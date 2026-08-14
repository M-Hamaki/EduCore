<?php

declare(strict_types=1);

namespace EduCore\Modules\Students;

use AcademicYear;
use AcademicYearWriteGuard;
use ActivityLog;
use DateTime;
use InvalidArgumentException;
use EduCore\Modules\Operations\Audit\AuditService;
use PDO;
use ProfileAttachmentStorage;
use ProfileInputValidator;
use RuntimeException;
use Throwable;
use UndoManager;
use User;

final class StudentProfileCommandService
{
    private PDO $db;
    private StudentProfileRepository $profiles;
    private StudentProfileRequestMapper $mapper;
    private StudentEnrollmentService $enrollments;
    private StudentGuardianService $guardians;
    private StudentProfileLifecycleService $lifecycle;

    public function __construct(
        PDO $db,
        StudentProfileRepository $profiles,
        StudentProfileRequestMapper $mapper,
        StudentEnrollmentService $enrollments,
        StudentGuardianService $guardians,
        StudentProfileLifecycleService $lifecycle
    ) {
        $this->db = $db;
        $this->profiles = $profiles;
        $this->mapper = $mapper;
        $this->enrollments = $enrollments;
        $this->guardians = $guardians;
        $this->lifecycle = $lifecycle;
    }

    public static function fromDatabase(PDO $db): self
    {
        $enrollments = new StudentEnrollmentService($db);
        return new self(
            $db,
            new StudentProfileRepository($db),
            new StudentProfileRequestMapper(),
            $enrollments,
            new StudentGuardianService($db),
            new StudentProfileLifecycleService($db, $enrollments)
        );
    }

    public function save(array &$post, string $scope, int $actorId): array
    {
        $isEdit = !empty($post['edit_user_id']);
        $studentId = $isEdit ? (int) $post['edit_user_id'] : null;
        $isTestAccount = false;
        if ($isEdit) {
            $this->profiles->assertManageableStudent((int) $studentId);
            $classification = $this->db->prepare(
                "SELECT COALESCE(is_test_account, 0) FROM users
                 WHERE id = ? AND role = 'student' AND deleted_at IS NULL"
            );
            $classification->execute([(int) $studentId]);
            $isTestAccount = (int) $classification->fetchColumn() === 1;
        }
        if (empty($post['grade_id']) && !$isTestAccount) {
            throw new InvalidArgumentException($isEdit
                ? 'اختيار الصف إلزامي عند تعديل الطالب.'
                : 'اختيار الصف إلزامي عند تسجيل الطالب.');
        }
        if (empty($post['class_id']) && !$isTestAccount) {
            throw new InvalidArgumentException($isEdit
                ? 'اختيار الفصل إلزامي عند تعديل الطالب.'
                : 'اختيار الفصل إلزامي عند تسجيل الطالب.');
        }

        $this->db->beginTransaction();
        try {
            $post = $this->mapper->normalizeAndValidate($post);
            $result = $isEdit
                ? $this->update((int) $studentId, $post, $scope, $actorId)
                : $this->create($post, $scope, $actorId);
            $this->db->commit();
            return $result;
        } catch (Throwable $e) {
            if ($this->db->inTransaction()) {
                $this->db->rollBack();
            }
            throw $e;
        }
    }

    /**
     * Validates and normalizes the complete shared student form without writing.
     * The returned payload is safe to persist as a pending specialist proposal.
     *
     * @param array<string,mixed> $post
     * @return array<string,mixed>
     */
    public function prepareSpecialistProfileProposal(int $studentId, array $post): array
    {
        if ($studentId <= 0) {
            throw new InvalidArgumentException('لا يمكن للأخصائي إضافة طالب جديد.');
        }
        $this->profiles->assertManageableStudent($studentId);
        if (empty($post['grade_id'])) {
            throw new InvalidArgumentException('اختيار الصف إلزامي عند تعديل الطالب.');
        }
        if (empty($post['class_id'])) {
            throw new InvalidArgumentException('اختيار الفصل إلزامي عند تعديل الطالب.');
        }
        if (trim((string) ($post['record_version'] ?? '')) === '') {
            throw new RuntimeException('تعذر التحقق من نسخة ملف الطالب. أعد تحميل الصفحة ثم حاول مرة أخرى.');
        }

        $post['edit_user_id'] = $studentId;
        $post['student_scope'] = 'current';
        $post = StudentChangeFieldPolicy::omitUntouchedCompositeGroups($post);
        $post = $this->hydrateOmittedSpecialistCollections($studentId, $post);
        $post = $this->mapper->normalizeAndValidate($post);
        if (StudentProfilePayload::fullName($post) === '') {
            throw new InvalidArgumentException('اسم الطالب باللغة العربية إلزامي.');
        }

        return StudentChangeFieldPolicy::profileRequest($post);
    }

    /**
     * Applies the complete student profile form after admin approval. The caller
     * owns the transaction so request review and the underlying writes are atomic.
     *
     * @param array<string,mixed> $post
     * @return array{student_id:int,message:string,saved_base_page:string}
     */
    public function applyApprovedSpecialistProfile(int $studentId, array $post, int $actorId): array
    {
        if (!$this->db->inTransaction()) {
            throw new RuntimeException('Approved specialist profile changes require an active transaction.');
        }
        $post = $this->hydrateOmittedSpecialistCollections($studentId, $post);
        $post = $this->prepareSpecialistProfileProposal($studentId, $post);
        return $this->update($studentId, $post, 'current', $actorId);
    }

    /**
     * Historical pending requests may predate the composite-group markers. In
     * that case an omitted form collection means "unchanged", not "delete".
     * Hydrate it from the locked current record before applying approval.
     *
     * @param array<string,mixed> $post
     * @return array<string,mixed>
     */
    private function hydrateOmittedSpecialistCollections(int $studentId, array $post): array
    {
        $profileStmt = $this->db->prepare('SELECT extra_phones, extra_data FROM student_profiles WHERE user_id = ? LIMIT 1');
        $profileStmt->execute([$studentId]);
        $profile = $profileStmt->fetch(PDO::FETCH_ASSOC) ?: [];

        $phoneKeys = ['student_mobile_numbers', 'student_mobile_notes', 'student_landline_numbers', 'student_landline_notes'];
        if (!array_key_exists('student_extra_phones_present', $post)
            && !array_intersect($phoneKeys, array_keys($post))) {
            foreach (StudentProfilePayload::decodePhonesForForm($profile['extra_phones'] ?? null) as $phone) {
                $isLandline = ($phone['type'] ?? '') === 'landline';
                $numberKey = $isLandline ? 'student_landline_numbers' : 'student_mobile_numbers';
                $noteKey = $isLandline ? 'student_landline_notes' : 'student_mobile_notes';
                $post[$numberKey][] = (string) ($phone['number'] ?? '');
                $post[$noteKey][] = (string) ($phone['note'] ?? '');
            }
        }

        $extraDataKeys = ['additional_data_labels', 'additional_data_values', 'educational_guardianship', 'educational_guardianship_other'];
        if (!array_key_exists('student_extra_data_present', $post)
            && !array_intersect($extraDataKeys, array_keys($post))) {
            $filteredExtraData = [];
            $post['educational_guardianship'] = StudentProfilePayload::extractEducationalGuardianship(
                $profile['extra_data'] ?? null,
                $filteredExtraData
            );
            foreach ($filteredExtraData as $item) {
                $post['additional_data_labels'][] = (string) ($item['label'] ?? '');
                $post['additional_data_values'][] = (string) ($item['value'] ?? '');
            }
        }

        if (!array_key_exists('student_guardians_present', $post)) {
            $guardianStmt = $this->db->prepare('SELECT * FROM student_guardians WHERE student_id = ? ORDER BY id');
            $guardianStmt->execute([$studentId]);
            $currentGuardians = [];
            foreach ($guardianStmt->fetchAll(PDO::FETCH_ASSOC) ?: [] as $guardian) {
                $guardianPhones = StudentProfilePayload::decodePhonesForForm($guardian['extra_phones'] ?? null);
                foreach ($guardianPhones as $phone) {
                    $isLandline = ($phone['type'] ?? '') === 'landline';
                    $numberKey = $isLandline ? 'extra_landline_numbers' : 'extra_mobile_numbers';
                    $noteKey = $isLandline ? 'extra_landline_notes' : 'extra_mobile_notes';
                    $guardian[$numberKey][] = (string) ($phone['number'] ?? '');
                    $guardian[$noteKey][] = (string) ($phone['note'] ?? '');
                }
                foreach (StudentProfilePayload::decodeExtraDataForForm($guardian['extra_data'] ?? null) as $item) {
                    $guardian['extra_data_labels'][] = (string) ($item['label'] ?? '');
                    $guardian['extra_data_values'][] = (string) ($item['value'] ?? '');
                }
                $currentGuardians[] = $guardian;
            }

            if (!array_key_exists('guardians', $post) || !is_array($post['guardians'])) {
                $post['guardians'] = $currentGuardians;
            } else {
                $guardianPhoneKeys = ['extra_mobile_numbers', 'extra_mobile_notes', 'extra_landline_numbers', 'extra_landline_notes'];
                $guardianDataKeys = ['extra_data_labels', 'extra_data_values'];
                foreach ($post['guardians'] as $index => &$proposedGuardian) {
                    if (!is_array($proposedGuardian)) continue;
                    $relationship = (string) ($proposedGuardian['relationship'] ?? '');
                    $currentGuardian = null;
                    foreach ($currentGuardians as $candidate) {
                        if ($relationship !== '' && (string) ($candidate['relationship'] ?? '') === $relationship) {
                            $currentGuardian = $candidate;
                            break;
                        }
                    }
                    $currentGuardian = $currentGuardian ?? ($currentGuardians[$index] ?? null);
                    if (!is_array($currentGuardian)) continue;
                    if (!array_intersect($guardianPhoneKeys, array_keys($proposedGuardian))) {
                        foreach ($guardianPhoneKeys as $key) {
                            if (array_key_exists($key, $currentGuardian)) $proposedGuardian[$key] = $currentGuardian[$key];
                        }
                    }
                    if (!array_intersect($guardianDataKeys, array_keys($proposedGuardian))) {
                        foreach ($guardianDataKeys as $key) {
                            if (array_key_exists($key, $currentGuardian)) $proposedGuardian[$key] = $currentGuardian[$key];
                        }
                    }
                }
                unset($proposedGuardian);
            }
        }

        $externalKeys = ['transfer_destination', 'external_transfer_date', 'external_transfer_reason', 'external_transfer_notes'];
        if (!array_key_exists('student_external_transfer_present', $post)
            && !array_intersect($externalKeys, array_keys($post))) {
            $externalStmt = $this->db->prepare('SELECT destination, transfer_date, reason, notes FROM student_external_transfers WHERE student_id = ? LIMIT 1');
            $externalStmt->execute([$studentId]);
            $external = $externalStmt->fetch(PDO::FETCH_ASSOC) ?: [];
            $post['transfer_destination'] = (string) ($external['destination'] ?? '');
            $post['external_transfer_date'] = (string) ($external['transfer_date'] ?? '');
            $post['external_transfer_reason'] = (string) ($external['reason'] ?? '');
            $post['external_transfer_notes'] = (string) ($external['notes'] ?? '');
        }

        return $post;
    }

    private function update(int $studentId, array $post, string $scope, int $actorId): array
    {
        $versionStmt = $this->db->prepare('SELECT updated_at FROM student_profiles WHERE user_id = ?');
        $versionStmt->execute([$studentId]);
        $currentVersion = (string) ($versionStmt->fetchColumn() ?: '');
        $submittedVersion = (string) ($post['record_version'] ?? '');
        if ($submittedVersion !== '' && $currentVersion !== $submittedVersion) {
            throw new RuntimeException('تم تعديل ملف الطالب بواسطة مستخدم آخر بعد فتح الصفحة. أعد تحميل الصفحة وراجع التغييرات قبل الحفظ.');
        }

        $oldUserUndoData = UndoManager::fetchRecord('users', $studentId);
        $oldProfileStmt = $this->db->prepare('SELECT * FROM student_profiles WHERE user_id = ?');
        $oldProfileStmt->execute([$studentId]);
        $oldProfileUndoData = $oldProfileStmt->fetch(PDO::FETCH_ASSOC) ?: null;
        $oldActivityData = $this->profiles->activitySnapshot($studentId);

        $user = new User($this->db);
        $user->id = $studentId;
        $user->name = StudentProfilePayload::fullName($post);
        if ($user->name === '') {
            throw new InvalidArgumentException('اسم الطالب باللغة العربية إلزامي.');
        }
        $enrollmentStatus = $this->enrollments->normalizeStatus($post, $scope);
        $currentYearId = AcademicYear::currentId($this->db);
        $currentEnrollment = StudentEnrollment::getStudentEnrollment($this->db, $studentId, $currentYearId);
        $academicStatus = $this->enrollments->normalizeAcademicStatus(
            $post,
            $scope,
            (string) ($currentEnrollment['academic_status'] ?? 'new')
        );

        $oldClassStmt = $this->db->prepare('SELECT class_id FROM users WHERE id = ?');
        $oldClassStmt->execute([$studentId]);
        $oldClassId = $oldClassStmt->fetchColumn();
        $newClassId = !empty($post['class_id']) ? (int) $post['class_id'] : null;
        $user->class_id = $newClassId;
        if (!$user->updateStudentIdentity()) {
            throw new RuntimeException('فشل تحديث بيانات الطالب.');
        }
        if ($oldClassId != $newClassId) {
            $user->logStudentTransfer($studentId, $oldClassId, $newClassId, $post['transfer_reason'] ?? 'نقل فصل', $actorId);
        }

        $profileData = $this->mapper->profileData($post, true);
        if (empty(trim($profileData['student_code'] ?? ''))) {
            $profileData['student_code'] = $user->generateStudentCode();
        }
        $profileData['enrollment_status'] = $academicStatus === 'graduated'
            ? 'graduated'
            : $enrollmentStatus;
        if (!$user->saveStudentProfile($studentId, $profileData)) {
            throw new RuntimeException('فشل حفظ ملف الطالب.');
        }

        $lifecycle = $this->lifecycle->sync(
            $studentId,
            !empty($post['grade_id'])
                ? (int) $post['grade_id']
                : (!empty($post['graduate_grade_id']) ? (int) $post['graduate_grade_id'] : null),
            $user->class_id ? (int) $user->class_id : null,
            $enrollmentStatus,
            $academicStatus,
            (string) ($post['status'] ?? 'active'),
            $post,
            $actorId,
            $oldClassId !== false && $oldClassId !== null ? (int) $oldClassId : null,
            true,
            true
        );
        $missingGuardianNames = $this->guardians->save($user, $studentId, $post['guardians'] ?? [], true);

        $message = 'تم تحديث بيانات الطالب بنجاح.' . $this->guardianWarning($missingGuardianNames);
        $movedMarks = (int) $lifecycle['moved_assessment_marks'];
        if ($movedMarks > 0) {
            $message .= " وتم نقل {$movedMarks} درجة إلى الفصل الجديد.";
            if (!ActivityLog::logUpdate('student_mark', $studentId, $user->name, [
                'student' => $user->name,
                'old_class_id' => $oldClassId !== false && $oldClassId !== null ? (int) $oldClassId : null,
                'new_class_id' => $newClassId,
                'academic_year' => $lifecycle['academic_year_id'],
                'count' => $movedMarks,
            ])) {
                throw new RuntimeException('تعذر تسجيل نقل درجات الطالب في سجل العمليات.');
            }
        }

        $details = StudentProfilePayload::activityDetails($oldActivityData, $this->profiles->activitySnapshot($studentId));
        $undoBatchId = UndoManager::newBatchId();
        $auditItems = [];
        if ($oldUserUndoData) {
            $auditItems[] = [
                'table' => 'users',
                'record_id' => $studentId,
                'before' => $oldUserUndoData,
                'after' => UndoManager::fetchRecord('users', $studentId) ?: [],
                'description' => 'تعديل ملف الطالب: ' . $user->name,
            ];
        }
        if ($oldProfileUndoData) {
            $newProfileStmt = $this->db->prepare('SELECT * FROM student_profiles WHERE id = ?');
            $newProfileStmt->execute([(int) $oldProfileUndoData['id']]);
            $auditItems[] = [
                'table' => 'student_profiles',
                'record_id' => (int) $oldProfileUndoData['id'],
                'before' => $oldProfileUndoData,
                'after' => $newProfileStmt->fetch(PDO::FETCH_ASSOC) ?: [],
                'description' => 'تعديل بيانات الطالب: ' . $user->name,
            ];
        }
        if ($auditItems) {
            (new AuditService($this->db))->recordCompositeUpdate(
                'student',
                $studentId,
                $user->name,
                $auditItems,
                $details ?? ['summary' => 'تم حفظ ملف الطالب دون تغير ظاهر في الحقول المعروضة'],
                $undoBatchId
            );
        }

        return [
            'student_id' => $studentId,
            'message' => $message,
            'saved_base_page' => $this->savedBasePage($enrollmentStatus, $academicStatus),
        ];
    }

    /**
     * Applies an approved, field-limited specialist proposal. The caller owns
     * the surrounding transaction so request status and student data stay atomic.
     *
     * @param array<string,mixed> $changes
     */
    public function applyApprovedSpecialistChanges(int $studentId, array $changes, int $actorId): void
    {
        if (!$this->db->inTransaction()) {
            throw new RuntimeException('Approved specialist changes require an active transaction.');
        }
        $this->profiles->assertManageableStudent($studentId);
        $changes = StudentChangeFieldPolicy::filter($changes);
        if ($changes === []) {
            throw new InvalidArgumentException('طلب التعديل لا يحتوي على حقول مسموحة.');
        }

        $profileStmt = $this->db->prepare('SELECT * FROM student_profiles WHERE user_id = ? LIMIT 1 FOR UPDATE');
        $profileStmt->execute([$studentId]);
        $beforeProfile = $profileStmt->fetch(PDO::FETCH_ASSOC);
        if (!$beforeProfile) {
            throw new RuntimeException('تعذر العثور على ملف الطالب.');
        }
        $beforeUser = UndoManager::fetchRecord('users', $studentId) ?: [];
        $merged = array_replace(StudentChangeFieldPolicy::snapshot($beforeProfile), $changes);
        StudentChangeFieldPolicy::validate($merged);

        $sets = [];
        $params = [];
        foreach ($changes as $field => $value) {
            $sets[] = "{$field} = ?";
            $params[] = $value === '' ? null : $value;
        }
        $params[] = $studentId;
        $this->db->prepare('UPDATE student_profiles SET ' . implode(', ', $sets) . ' WHERE user_id = ?')->execute($params);

        $nameFields = ['first_name_ar', 'second_name_ar', 'third_name_ar', 'fourth_name_ar', 'family_name_ar'];
        if (array_intersect(array_keys($changes), $nameFields)) {
            $name = StudentProfilePayload::fullName($merged);
            $this->db->prepare("UPDATE users SET name = ? WHERE id = ? AND role = 'student'")->execute([$name, $studentId]);
        }

        $afterProfileStmt = $this->db->prepare('SELECT * FROM student_profiles WHERE user_id = ? LIMIT 1');
        $afterProfileStmt->execute([$studentId]);
        $afterProfile = $afterProfileStmt->fetch(PDO::FETCH_ASSOC) ?: [];
        $afterUser = UndoManager::fetchRecord('users', $studentId) ?: [];
        $items = [[
            'table' => 'student_profiles',
            'record_id' => (int)$beforeProfile['id'],
            'before' => $beforeProfile,
            'after' => $afterProfile,
            'description' => 'تطبيق تعديل طالب وافقت عليه الإدارة',
        ]];
        if ($beforeUser != $afterUser) {
            $items[] = [
                'table' => 'users',
                'record_id' => $studentId,
                'before' => $beforeUser,
                'after' => $afterUser,
                'description' => 'تحديث اسم الطالب من طلب أخصائي معتمد',
            ];
        }
        (new AuditService($this->db))->recordCompositeUpdate(
            'student',
            $studentId,
            (string)($afterUser['name'] ?? $beforeUser['name'] ?? ''),
            $items,
            ['summary' => 'تطبيق طلب تعديل مقدم من أخصائي', 'fields' => array_keys($changes), 'approved_by' => $actorId]
        );
    }

    /**
     * Applies a class transfer proposed by a specialist after admin approval.
     * The caller owns the transaction so the request review and all student
     * enrollment writes remain atomic.
     *
     * @return array{moved_marks:int}
     */
    public function applyApprovedSpecialistClassTransfer(
        int $studentId,
        int $academicYearId,
        int $oldClassId,
        int $newClassId,
        string $reason,
        int $actorId
    ): array {
        return $this->applyClassTransfer(
            $studentId,
            $academicYearId,
            $oldClassId,
            $newClassId,
            $reason,
            $actorId,
            null,
            'specialist_request'
        );
    }

    /**
     * Applies a class transfer while the caller-owned transaction keeps every
     * student, enrollment, mark, transfer and audit row in one atomic unit.
     *
     * @return array{moved_marks:int}
     */
    public function applyClassTransfer(
        int $studentId,
        int $academicYearId,
        int $oldClassId,
        int $newClassId,
        string $reason,
        int $actorId,
        ?string $batchId = null,
        string $source = 'admin_class_lists'
    ): array {
        if (!$this->db->inTransaction()) {
            throw new RuntimeException('Student class transfers require an active transaction.');
        }
        if ($studentId <= 0 || $academicYearId <= 0 || $oldClassId <= 0 || $newClassId <= 0 || $actorId <= 0) {
            throw new InvalidArgumentException('بيانات نقل الطالب غير مكتملة.');
        }
        (new AcademicYearWriteGuard($this->db))->assertWritable($academicYearId);
        $this->profiles->assertManageableStudent($studentId);
        $batchId = $batchId ?: UndoManager::newBatchId();

        $enrollmentStmt = $this->db->prepare("SELECT * FROM student_enrollments
            WHERE student_id = ? AND academic_year_id = ? AND enrollment_status = 'enrolled'
            LIMIT 1 FOR UPDATE");
        $enrollmentStmt->execute([$studentId, $academicYearId]);
        $beforeEnrollment = $enrollmentStmt->fetch(PDO::FETCH_ASSOC);
        if (!$beforeEnrollment || (int) ($beforeEnrollment['class_id'] ?? 0) !== $oldClassId) {
            throw new RuntimeException('تغير فصل الطالب قبل تطبيق الطلب.');
        }

        $targetStmt = $this->db->prepare("SELECT c.id, c.name, c.grade_id, g.stage_id
            FROM classes c
            LEFT JOIN grades g ON g.id = c.grade_id
            WHERE c.id = ? AND c.status = 'active'
              AND (c.academic_year_id = ? OR c.academic_year_id IS NULL)
            LIMIT 1");
        $targetStmt->execute([$newClassId, $academicYearId]);
        $target = $targetStmt->fetch(PDO::FETCH_ASSOC);
        if (!$target) {
            throw new RuntimeException('الفصل الجديد غير متاح في العام الدراسي الحالي.');
        }
        if ((int) ($beforeEnrollment['grade_id'] ?? 0) > 0
            && (int) ($target['grade_id'] ?? 0) !== (int) $beforeEnrollment['grade_id']) {
            throw new RuntimeException('الفصل الجديد لا يتبع الصف الدراسي الحالي للطالب.');
        }

        $beforeUser = UndoManager::fetchRecord('users', $studentId) ?: [];
        $profileStmt = $this->db->prepare('SELECT * FROM student_profiles WHERE user_id = ? LIMIT 1 FOR UPDATE');
        $profileStmt->execute([$studentId]);
        $beforeProfile = $profileStmt->fetch(PDO::FETCH_ASSOC);
        if (!$beforeProfile) {
            throw new RuntimeException('تعذر العثور على ملف الطالب.');
        }

        $this->db->prepare("UPDATE users SET class_id = ? WHERE id = ? AND role = 'student'")
            ->execute([$newClassId, $studentId]);
        $this->db->prepare('UPDATE student_profiles SET grade_id = ? WHERE user_id = ?')
            ->execute([(int) ($target['grade_id'] ?? 0), $studentId]);
        StudentEnrollment::upsert(
            $this->db,
            $studentId,
            $academicYearId,
            !empty($target['stage_id']) ? (int) $target['stage_id'] : null,
            !empty($target['grade_id']) ? (int) $target['grade_id'] : null,
            $newClassId,
            'enrolled',
            (string) ($beforeEnrollment['academic_status'] ?? 'new'),
            $batchId
        );
        $movedMarks = $this->enrollments->syncAssessmentMarksClass(
            $studentId,
            $academicYearId,
            $oldClassId,
            $newClassId,
            $batchId
        );

        $transferReason = mb_substr(trim($reason), 0, 500);
        if ($transferReason === '') {
            $transferReason = $source === 'specialist_request'
                ? 'نقل فصل بطلب أخصائي وافقت عليه الإدارة'
                : 'نقل فصل من قوائم الفصول';
        }
        $transferStmt = $this->db->prepare("INSERT INTO student_transfers
            (student_id, from_class_id, to_class_id, transfer_date, reason, transferred_by)
            VALUES (?, ?, ?, CURDATE(), ?, ?)");
        $transferStmt->execute([$studentId, $oldClassId, $newClassId, $transferReason, $actorId]);
        $transferId = (int) $this->db->lastInsertId();
        $transferReload = $this->db->prepare('SELECT * FROM student_transfers WHERE id = ?');
        $transferReload->execute([$transferId]);
        $transferRow = $transferReload->fetch(PDO::FETCH_ASSOC) ?: [];

        $afterUser = UndoManager::fetchRecord('users', $studentId) ?: [];
        $afterProfileStmt = $this->db->prepare('SELECT * FROM student_profiles WHERE user_id = ? LIMIT 1');
        $afterProfileStmt->execute([$studentId]);
        $afterProfile = $afterProfileStmt->fetch(PDO::FETCH_ASSOC) ?: [];
        $audit = new AuditService($this->db);
        $audit->recordInsert(
            'student_transfer',
            'student_transfers',
            $transferId,
            'نقل الطالب ' . (string) ($afterUser['name'] ?? ('#' . $studentId)),
            $transferRow,
            $source === 'specialist_request' ? 'تطبيق نقل فصل وافقت عليه الإدارة' : 'نقل طالب من قوائم الفصول',
            $batchId
        );
        $audit->recordCompositeUpdate(
            'student',
            $studentId,
            (string) ($afterUser['name'] ?? $beforeUser['name'] ?? ''),
            [
                [
                    'table' => 'users',
                    'record_id' => $studentId,
                    'before' => $beforeUser,
                    'after' => $afterUser,
                    'description' => $source === 'specialist_request' ? 'تحديث فصل الطالب بعد موافقة الإدارة' : 'تحديث فصل الطالب من قوائم الفصول',
                ],
                [
                    'table' => 'student_profiles',
                    'record_id' => (int) $beforeProfile['id'],
                    'before' => $beforeProfile,
                    'after' => $afterProfile,
                    'description' => $source === 'specialist_request' ? 'تحديث صف الطالب بعد موافقة الإدارة' : 'تحديث صف الطالب من قوائم الفصول',
                ],
            ],
            [
                'summary' => $source === 'specialist_request' ? 'تطبيق طلب نقل فصل مقدم من أخصائي' : 'نقل الطالب من قوائم الفصول',
                'academic_year_id' => $academicYearId,
                'class_id_before' => $oldClassId,
                'class_id_after' => $newClassId,
                'approved_by' => $actorId,
                'moved_marks' => $movedMarks,
            ],
            $batchId
        );

        return ['moved_marks' => $movedMarks];
    }

    private function create(array $post, string $scope, int $actorId): array
    {
        $user = new User($this->db);
        $user->name = StudentProfilePayload::fullName($post);
        if ($user->name === '') {
            throw new InvalidArgumentException('اسم الطالب باللغة العربية إلزامي.');
        }
        $user->role = 'student';
        $user->class_id = !empty($post['class_id']) ? (int) $post['class_id'] : null;
        if (!$user->create()) {
            throw new RuntimeException('فشل إنشاء المستخدم للطالب.');
        }

        $studentId = (int) $user->id;
        if ($scope === 'graduates') {
            $this->db->prepare("UPDATE users SET status = 'graduated' WHERE id = ?")->execute([$studentId]);
        }
        $studentCode = !empty($post['student_code']) ? trim((string) $post['student_code']) : $user->generateStudentCode();
        $profileData = $this->mapper->profileData($post);
        $profileData['student_code'] = $studentCode;
        $enrollmentStatus = $this->enrollments->normalizeStatus($post, $scope);
        $academicStatus = $this->enrollments->normalizeAcademicStatus($post, $scope);
        $profileData['enrollment_status'] = $academicStatus === 'graduated'
            ? 'graduated'
            : $enrollmentStatus;
        if (!$user->saveStudentProfile($studentId, $profileData)) {
            throw new RuntimeException('فشل حفظ ملف الطالب.');
        }

        $this->lifecycle->sync(
            $studentId,
            !empty($post['grade_id'])
                ? (int) $post['grade_id']
                : (!empty($post['graduate_grade_id']) ? (int) $post['graduate_grade_id'] : null),
            $user->class_id ? (int) $user->class_id : null,
            $enrollmentStatus,
            $academicStatus,
            (string) ($post['status'] ?? 'active'),
            $post,
            $actorId,
            null,
            false,
            true
        );
        $missingGuardianNames = $this->guardians->save($user, $studentId, $post['guardians'] ?? [], false);
        if (!ActivityLog::logCreate('student', $studentId, $user->name, [
            'summary' => 'تم إنشاء ملف طالب جديد',
            'student_code' => $studentCode,
            'class' => $this->profiles->className($user->class_id ? (int) $user->class_id : null),
            'guardian_count' => count($post['guardians'] ?? []),
        ])) {
            throw new RuntimeException('تعذر تسجيل إضافة الطالب في سجل العمليات.');
        }

        return [
            'student_id' => $studentId,
            'message' => 'تم إضافة الطالب بنجاح. كود الطالب: ' . htmlspecialchars($studentCode) . '.'
                . $this->guardianWarning($missingGuardianNames),
            'saved_base_page' => $this->savedBasePage($enrollmentStatus, $academicStatus),
        ];
    }

    private function guardianWarning(array $missingNames): string
    {
        if (!$missingNames) {
            return '';
        }
        return '<br><span class="text-warning"><i class="fas fa-exclamation-triangle me-1"></i>تنبيه: لم تتم كتابة اسم كل من: '
            . htmlspecialchars(implode('، ', array_unique($missingNames))) . '. يُنصح بإكمال الأسماء لاحقاً.</span>';
    }

    private function savedBasePage(string $enrollmentStatus, string $academicStatus): string
    {
        return $academicStatus === 'graduated'
            ? 'graduate_students.php'
            : ($enrollmentStatus === 'transferred'
                ? 'transferred_students.php'
                : ($enrollmentStatus === 'discontinued' ? 'discontinued_students.php' : 'students.php'));
    }
}
