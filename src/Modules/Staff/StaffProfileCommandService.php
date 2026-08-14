<?php

declare(strict_types=1);

namespace EduCore\Modules\Staff;

use ActivityLog;
use EduCore\Modules\Operations\Audit\AuditService;
use ClassRoom;
use FileUploadGuard;
use InvalidArgumentException;
use PDO;
use ProfileAttachmentStorage;
use ProfileInputValidator;
use RuntimeException;
use Throwable;
use StaffEmploymentLifecycleService;
use UndoManager;
use User;

final class StaffProfileCommandService
{
    private PDO $db;
    private User $users;
    private StaffProfileRequestMapper $mapper;
    private StaffEmploymentLifecycleService $employment;
    private StaffProfileRepository $profiles;
    private StaffBiometricIdentityService $biometricIdentities;
    private string $uploadDirectory;
    private $uploadMover;

    public function __construct(
        PDO $db,
        User $users,
        StaffProfileRequestMapper $mapper,
        StaffEmploymentLifecycleService $employment,
        StaffProfileRepository $profiles,
        string $uploadDirectory,
        ?callable $uploadMover = null,
        ?StaffBiometricIdentityService $biometricIdentities = null
    ) {
        $this->db = $db;
        $this->users = $users;
        $this->mapper = $mapper;
        $this->employment = $employment;
        $this->profiles = $profiles;
        $this->biometricIdentities = $biometricIdentities
            ?? new StaffBiometricIdentityService($db);
        $this->uploadDirectory = rtrim($uploadDirectory, '/\\');
        $this->uploadMover = $uploadMover ?? 'move_uploaded_file';
    }

    public function create(array $input, array $files, array $allowedDepartments, int $actorId): int
    {
        $imageChange = [];
        $undoBatchId = UndoManager::newBatchId();
        $this->db->beginTransaction();
        try {
            $mapped = $this->mapper->map($input, $allowedDepartments);
            $name = $mapped['name'];
            $insert = $this->db->prepare(
                "INSERT INTO users (name, username, password, role, class_id, status)
                 VALUES (?, NULL, NULL, NULL, NULL, 'active')"
            );
            if (!$insert->execute([$name])) {
                throw new RuntimeException('حدث خطأ أثناء إنشاء المستخدم للموظف.');
            }

            $userId = (int) $this->db->lastInsertId();
            $this->users->id = $userId;
            $this->users->name = $name;
            $profile = $mapped['profile'];
            $profile['employee_code'] = $this->users->generateEmployeeCode();
            $this->saveProfileAndEmployment(
                $userId,
                $profile,
                $mapped,
                $actorId,
                $undoBatchId
            );
            $imageChange = $this->saveSubmittedProfileImage($userId, $files['profile_image'] ?? null, false);
            $this->users->clearAssignedClassesCache($userId);

            (new AuditService($this->db))->recordInsert(
                'staff',
                'users',
                $userId,
                $name,
                UndoManager::fetchRecord('users', $userId) ?: ['name' => $name],
                'إضافة موظف: ' . $name,
                $undoBatchId,
                [
                    'summary' => 'تم إنشاء ملف موظف جديد',
                    'employee_code' => $profile['employee_code'] ?? null,
                ]
            );
            $this->db->commit();
            $this->finalizeImageChange($imageChange, true);

            return $userId;
        } catch (Throwable $exception) {
            if ($this->db->inTransaction()) {
                $this->db->rollBack();
            }
            $this->finalizeImageChange($imageChange, false);
            throw $exception;
        }
    }

    public function update(
        int $userId,
        array $input,
        array $files,
        array $allowedDepartments,
        int $actorId
    ): void {
        $this->profiles->assertManageableStaff($userId, 'تعديله');
        $imageChange = [];
        $this->db->beginTransaction();
        try {
            $oldActivity = $this->profiles->activitySnapshot($userId);
            $versionStatement = $this->db->prepare(
                'SELECT updated_at FROM staff_profiles WHERE user_id = ?'
            );
            $versionStatement->execute([$userId]);
            $currentVersion = (string) ($versionStatement->fetchColumn() ?: '');
            $submittedVersion = (string) ($input['record_version'] ?? '');
            if ($submittedVersion !== '' && $currentVersion !== $submittedVersion) {
                throw new RuntimeException(
                    'تم تعديل ملف الموظف بواسطة مستخدم آخر بعد فتح الصفحة. أعد تحميل الصفحة وراجع التغييرات قبل الحفظ.'
                );
            }

            $oldUser = UndoManager::fetchRecord('users', $userId);
            $oldProfileStatement = $this->db->prepare(
                'SELECT * FROM staff_profiles WHERE user_id = ?'
            );
            $oldProfileStatement->execute([$userId]);
            $oldProfile = $oldProfileStatement->fetch(PDO::FETCH_ASSOC) ?: null;
            $undoBatchId = UndoManager::newBatchId();
            $assignmentTables = [
                'user_class_access' => ['column' => 'user_id', 'before' => $this->fetchOwnedRows('user_class_access', 'user_id', $userId, true)],
                'teacher_subjects' => ['column' => 'teacher_id', 'before' => $this->fetchOwnedRows('teacher_subjects', 'teacher_id', $userId, true)],
            ];

            $mapped = $this->mapper->map($input, $allowedDepartments);
            $name = $mapped['name'];
            $this->users->id = $userId;
            $this->users->name = $name;
            $this->db->prepare('UPDATE users SET name = ? WHERE id = ?')
                ->execute([$name, $userId]);

            $profile = $mapped['profile'];
            $existingEmployeeCode = trim((string)($oldProfile['employee_code'] ?? ''));
            $profile['employee_code'] = preg_match('/^E\d{8}$/D', $existingEmployeeCode)
                ? $existingEmployeeCode
                : $this->users->generateEmployeeCode();
            $this->saveProfileAndEmployment(
                $userId,
                $profile,
                $mapped,
                $actorId,
                $undoBatchId
            );
            $imageChange = $this->saveSubmittedProfileImage($userId, $files['profile_image'] ?? null, true);
            $this->syncLegacyAssignmentsWhenSubmitted($userId, $input);

            $details = StaffProfilePayload::activityDetails(
                $oldActivity,
                $this->profiles->activitySnapshot($userId),
                false
            );
            $auditItems = [];
            if ($oldUser) {
                $auditItems[] = [
                    'table' => 'users',
                    'record_id' => $userId,
                    'before' => $oldUser,
                    'after' => UndoManager::fetchRecord('users', $userId) ?: [],
                    'description' => 'تعديل موظف: ' . $name,
                ];
            }
            if ($oldProfile) {
                $newProfileStatement = $this->db->prepare('SELECT * FROM staff_profiles WHERE id = ?');
                $newProfileStatement->execute([(int) $oldProfile['id']]);
                $auditItems[] = [
                    'table' => 'staff_profiles',
                    'record_id' => (int) $oldProfile['id'],
                    'before' => $oldProfile,
                    'after' => $newProfileStatement->fetch(PDO::FETCH_ASSOC) ?: [],
                    'description' => 'تعديل بيانات الموظف: ' . $name,
                ];
            }
            if ($auditItems) {
                (new AuditService($this->db))->recordCompositeUpdate(
                    'staff',
                    $userId,
                    $name,
                    $auditItems,
                    $details ?? ['summary' => 'تم حفظ ملف الموظف دون تغير ظاهر في الحقول المعروضة'],
                    $undoBatchId
                );
            }
            $this->auditAssignmentChanges($userId, $name, $assignmentTables, $undoBatchId);
            $this->db->commit();
            $this->finalizeImageChange($imageChange, true);
        } catch (Throwable $exception) {
            if ($this->db->inTransaction()) {
                $this->db->rollBack();
            }
            $this->finalizeImageChange($imageChange, false);
            throw $exception;
        }
    }

    private function saveProfileAndEmployment(
        int $userId,
        array $profile,
        array $mapped,
        int $actorId,
        string $undoBatchId
    ): void {
        if (array_key_exists('biometric_id', $profile)) {
            $profile['biometric_id'] = $this->biometricIdentities->assertAvailableWithinTransaction(
                $userId,
                $profile['biometric_id']
            );
        }
        if (!$this->users->saveStaffProfile($userId, $profile)) {
            throw new RuntimeException('فشل حفظ الملف الوظيفي للموظف.');
        }
        $this->employment->syncStatusHistory(
            $userId,
            $mapped['status_events'],
            $actorId,
            $undoBatchId
        );
        $this->employment->syncJobMovements(
            $userId,
            $mapped['job_movements'],
            $actorId,
            $undoBatchId
        );
    }

    private function saveSubmittedProfileImage(int $userId, $file, bool $replace): array
    {
        if (!is_array($file)
            || (int)($file['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) {
            return [];
        }
        $validated = FileUploadGuard::validate(
            $file,
            [
                'jpg' => ['image/jpeg'],
                'jpeg' => ['image/jpeg'],
                'png' => ['image/png'],
                'webp' => ['image/webp'],
            ],
            2 * 1024 * 1024
        );
        if (!is_dir($this->uploadDirectory)) {
            throw new RuntimeException('مجلد حفظ صور العاملين غير متاح.');
        }
        $existingProfile = $replace ? ($this->users->getStaffProfile($userId) ?: []) : [];
        $retiredFile = !empty($existingProfile['profile_image']) ? (string) $existingProfile['profile_image'] : null;
        $fileName = FileUploadGuard::randomFileName(
            'staff_' . $userId,
            $validated['extension']
        );
        $destination = $this->uploadDirectory . DIRECTORY_SEPARATOR . $fileName;
        if (!(($this->uploadMover)($validated['tmp_name'], $destination))) {
            throw new RuntimeException('تعذر حفظ صورة العامل. يرجى إعادة المحاولة.');
        }
        if (!$this->users->saveStaffProfile($userId, ['profile_image' => $fileName])) {
            if (is_file($destination)) {
                unlink($destination);
            }
            throw new RuntimeException('تعذر ربط صورة العامل بملفه.');
        }
        return [
            'created' => $destination,
            'retired' => $retiredFile
                ? $this->uploadDirectory . DIRECTORY_SEPARATOR . $retiredFile
                : null,
        ];
    }

    private function finalizeImageChange(array $change, bool $committed): void
    {
        $path = $committed ? ($change['retired'] ?? null) : ($change['created'] ?? null);
        if (is_string($path) && $path !== '' && is_file($path)) unlink($path);
    }

    private function fetchOwnedRows(string $table, string $column, int $userId, bool $lock): array
    {
        $stmt = $this->db->prepare("SELECT * FROM `{$table}` WHERE `{$column}` = ? ORDER BY id" . ($lock ? ' FOR UPDATE' : ''));
        $stmt->execute([$userId]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    }

    private function auditAssignmentChanges(int $userId, string $name, array $tables, string $batchId): void
    {
        $deleted = $inserted = [];
        foreach ($tables as $table => $state) {
            $before = array_column($state['before'], null, 'id');
            $afterRows = $this->fetchOwnedRows($table, $state['column'], $userId, false);
            $after = array_column($afterRows, null, 'id');
            foreach (array_diff_key($before, $after) as $id => $row) $deleted[] = ['table' => $table, 'record_id' => $id, 'snapshot' => $row, 'description' => 'تحديث تعيينات موظف'];
            foreach (array_diff_key($after, $before) as $id => $row) $inserted[] = ['table' => $table, 'record_id' => $id, 'snapshot' => $row, 'description' => 'تحديث تعيينات موظف'];
        }
        if ($deleted || $inserted) {
            (new AuditService($this->db))->recordReplacement(
                'staff_assignments', $userId, $name, $deleted, $inserted,
                ['summary' => 'تحديث تعيينات الفصول والمواد للموظف'], $batchId
            );
        }
    }

    private function syncLegacyAssignmentsWhenSubmitted(int $userId, array $input): void
    {
        if (!array_key_exists('classes', $input) && !array_key_exists('subjects', $input)) {
            return;
        }
        $roleStatement = $this->db->prepare("SELECT EXISTS(
                SELECT 1 FROM user_role_assignments ura
                WHERE ura.user_id = ? AND ura.role_key = 'teacher' AND ura.status = 'active'
            )");
        $roleStatement->execute([$userId]);
        if ((int)$roleStatement->fetchColumn() === 1) {
            $this->users->removeAllClassAssignments($userId);
            foreach (($input['classes'] ?? []) as $classId) {
                $classroom = new ClassRoom($this->db);
                $classroom->id = (int) $classId;
                $classroom->assignStaff($userId);
            }
            $this->db->prepare('DELETE FROM teacher_subjects WHERE teacher_id = ?')
                ->execute([$userId]);
            if (!empty($input['subjects'])) {
                $insert = $this->db->prepare(
                    'INSERT INTO teacher_subjects (teacher_id, subject_id) VALUES (?, ?)'
                );
                foreach ($input['subjects'] as $subjectId) {
                    $insert->execute([$userId, (int) $subjectId]);
                }
            }
        }
        $this->users->clearAssignedClassesCache($userId);
    }
}
