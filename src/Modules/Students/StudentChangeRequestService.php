<?php

declare(strict_types=1);

namespace EduCore\Modules\Students;

use EduCore\Modules\Operations\Audit\AuditService;
use EduCore\Modules\Staff\SpecialistAcademicScopeService;
use InvalidArgumentException;
use PDO;
use RuntimeException;
use Throwable;

require_once dirname(__DIR__) . '/Operations/Audit/AuditService.php';
require_once dirname(__DIR__) . '/Staff/SpecialistAcademicScopeService.php';

final class StudentChangeRequestService
{
    public function __construct(
        private PDO $db,
        private SpecialistAcademicScopeService $scope,
        private StudentProfileCommandService $commands
    ) {
    }

    public static function create(PDO $db): self
    {
        return new self(
            $db,
            new SpecialistAcademicScopeService($db),
            StudentProfileCommandService::fromDatabase($db)
        );
    }

    public static function pendingCount(PDO $db, ?int $specialistId = null, ?int $academicYearId = null): int
    {
        if ($specialistId === null) {
            return (int) $db->query("SELECT COUNT(*) FROM student_change_requests WHERE status = 'pending'")
                ->fetchColumn();
        }

        if ($specialistId <= 0 || $academicYearId === null || $academicYearId <= 0) {
            return 0;
        }

        $stmt = $db->prepare("SELECT COUNT(*) FROM student_change_requests
            WHERE specialist_id = ? AND academic_year_id = ? AND status = 'pending'");
        $stmt->execute([$specialistId, $academicYearId]);
        return (int) $stmt->fetchColumn();
    }

    /** @param array<string,mixed> $input */
    public function submitProfile(int $specialistId, int $academicYearId, int $studentId, array $input): int
    {
        $this->scope->assertStudentAllowed($specialistId, $academicYearId, $studentId);
        $profile = $this->profileSnapshot($studentId);
        $request = $this->commands->prepareSpecialistProfileProposal($studentId, $input);
        $newClassId = (int) ($request['class_id'] ?? 0);
        if ($newClassId > 0) {
            $this->scope->assertClassAllowed($specialistId, $academicYearId, $newClassId);
        }
        $changes = $this->profileDisplayChanges($studentId, $academicYearId, $profile, $request);
        if ($changes === []) {
            throw new InvalidArgumentException('لم يتم إدخال أي تغيير جديد.');
        }

        $before = [
            '__format' => 'full_profile_v1',
            'record_version' => (string) ($profile['updated_at'] ?? ''),
            'display' => array_map(static fn(array $change): mixed => $change['before'], $changes),
        ];
        $proposed = [
            '__format' => 'full_profile_v1',
            'request' => $request,
            'display' => array_map(static fn(array $change): mixed => $change['after'], $changes),
        ];

        return $this->insertPendingRequest(
            $specialistId,
            $academicYearId,
            $studentId,
            $before,
            $proposed,
            array_keys($changes)
        );
    }

    public function submitClassTransfer(
        int $specialistId,
        int $academicYearId,
        int $studentId,
        int $newClassId,
        string $reason = ''
    ): int {
        if ($academicYearId <= 0 || $studentId <= 0 || $newClassId <= 0) {
            throw new InvalidArgumentException('بيانات طلب نقل الطالب غير مكتملة.');
        }
        $this->scope->assertStudentAllowed($specialistId, $academicYearId, $studentId);
        $this->scope->assertClassAllowed($specialistId, $academicYearId, $newClassId);

        $ownsTransaction = !$this->db->inTransaction();
        if ($ownsTransaction) {
            $this->db->beginTransaction();
        }
        try {
            $currentStmt = $this->db->prepare("SELECT se.class_id, se.grade_id, c.name AS class_name
                FROM student_enrollments se
                JOIN users u ON u.id = se.student_id AND u.role = 'student' AND u.deleted_at IS NULL
                LEFT JOIN classes c ON c.id = se.class_id
                WHERE se.student_id = ? AND se.academic_year_id = ? AND se.enrollment_status = 'enrolled'
                LIMIT 1 FOR UPDATE");
            $currentStmt->execute([$studentId, $academicYearId]);
            $current = $currentStmt->fetch(PDO::FETCH_ASSOC);
            if (!$current || empty($current['class_id'])) {
                throw new RuntimeException('لا يوجد قيد حالي للطالب في هذا العام الدراسي.');
            }

            $oldClassId = (int) $current['class_id'];
            if ($oldClassId === $newClassId) {
                throw new InvalidArgumentException('الطالب مسجل بالفعل في الفصل المحدد.');
            }

            $targetStmt = $this->db->prepare("SELECT c.id, c.name, c.grade_id
                FROM classes c
                WHERE c.id = ? AND c.status = 'active'
                  AND (c.academic_year_id = ? OR c.academic_year_id IS NULL)
                LIMIT 1");
            $targetStmt->execute([$newClassId, $academicYearId]);
            $target = $targetStmt->fetch(PDO::FETCH_ASSOC);
            if (!$target) {
                throw new RuntimeException('الفصل الجديد غير متاح في العام الدراسي الحالي.');
            }
            if ((int) ($current['grade_id'] ?? 0) > 0
                && (int) ($target['grade_id'] ?? 0) !== (int) $current['grade_id']) {
                throw new InvalidArgumentException('يجب أن يكون الفصل الجديد تابعًا للصف الدراسي نفسه.');
            }

            $before = [
                '__format' => 'class_transfer_v1',
                'old_class_id' => $oldClassId,
                'display' => ['class_id' => (string) ($current['class_name'] ?? '')],
            ];
            $proposed = [
                '__format' => 'class_transfer_v1',
                'old_class_id' => $oldClassId,
                'new_class_id' => $newClassId,
                'new_grade_id' => (int) ($target['grade_id'] ?? 0),
                'transfer_reason' => mb_substr(trim($reason), 0, 500),
                'display' => ['class_id' => (string) $target['name']],
            ];
            $requestId = $this->insertPendingRequest(
                $specialistId,
                $academicYearId,
                $studentId,
                $before,
                $proposed,
                ['class_id']
            );
            if ($ownsTransaction) {
                $this->db->commit();
            }
            return $requestId;
        } catch (Throwable $e) {
            if ($ownsTransaction && $this->db->inTransaction()) {
                $this->db->rollBack();
            }
            throw $e;
        }
    }

    /**
     * Compatibility adapter for the retired limited specialist form. New shared
     * admin-page callers must use submitProfile().
     *
     * @param array<string,mixed> $input
     */
    public function submit(int $specialistId, int $academicYearId, int $studentId, array $input): int
    {
        $this->scope->assertStudentAllowed($specialistId, $academicYearId, $studentId);
        $profile = $this->profileSnapshot($studentId);
        $before = StudentChangeFieldPolicy::snapshot($profile);
        $requested = StudentChangeFieldPolicy::filter($input);
        $proposed = [];
        foreach ($requested as $field => $value) {
            if ($value !== ($before[$field] ?? '')) $proposed[$field] = $value;
        }
        if ($proposed === []) throw new InvalidArgumentException('لم يتم إدخال أي تغيير جديد.');
        StudentChangeFieldPolicy::validate(array_replace($before, $proposed));

        return $this->insertPendingRequest(
            $specialistId,
            $academicYearId,
            $studentId,
            $before,
            $proposed,
            array_keys($proposed)
        );
    }

    /** @return array{status:string,message:string} */
    public function approve(int $requestId, int $adminId): array
    {
        $this->db->beginTransaction();
        try {
            $request = $this->lockedPendingRequest($requestId);
            $beforePayload = $this->decodePayloadRaw((string)$request['before_payload']);
            $proposedPayload = $this->decodePayloadRaw((string)$request['proposed_payload']);
            $requestFormat = (string) ($proposedPayload['__format'] ?? '');
            $successMessage = 'تمت الموافقة وتطبيق التعديل على ملف الطالب.';
            if ($requestFormat === 'class_transfer_v1') {
                $studentId = (int) $request['student_id'];
                $academicYearId = (int) $request['academic_year_id'];
                $oldClassId = (int) ($proposedPayload['old_class_id'] ?? 0);
                $newClassId = (int) ($proposedPayload['new_class_id'] ?? 0);
                $currentStmt = $this->db->prepare("SELECT class_id FROM student_enrollments
                    WHERE student_id = ? AND academic_year_id = ? AND enrollment_status = 'enrolled'
                    LIMIT 1 FOR UPDATE");
                $currentStmt->execute([$studentId, $academicYearId]);
                $currentClassId = (int) ($currentStmt->fetchColumn() ?: 0);
                if ($oldClassId <= 0 || $newClassId <= 0 || $currentClassId !== $oldClassId) {
                    $this->finishReview($requestId, 'conflict', $adminId, 'تغير فصل الطالب بعد إنشاء الطلب.');
                    (new AuditService($this->db))->recordEvent('update', 'student_change_request', $requestId, 'تعارض طلب نقل طالب', ['status' => 'conflict', 'field' => 'class_id']);
                    $this->db->commit();
                    return ['status' => 'conflict', 'message' => 'تعذر التطبيق لأن فصل الطالب تغير بعد إنشاء الطلب.'];
                }
                $this->scope->assertStudentAllowed(
                    (int) $request['specialist_id'],
                    $academicYearId,
                    $studentId
                );
                $this->scope->assertClassAllowed(
                    (int) $request['specialist_id'],
                    $academicYearId,
                    $newClassId
                );
                $this->commands->applyApprovedSpecialistClassTransfer(
                    $studentId,
                    $academicYearId,
                    $oldClassId,
                    $newClassId,
                    (string) ($proposedPayload['transfer_reason'] ?? ''),
                    $adminId
                );
                $auditFields = ['class_id'];
                $successMessage = 'تمت الموافقة ونقل الطالب إلى الفصل الجديد.';
            } elseif ($requestFormat === 'full_profile_v1') {
                $profile = $this->profileSnapshot((int) $request['student_id']);
                if ((string) ($profile['updated_at'] ?? '') !== (string) ($beforePayload['record_version'] ?? '')) {
                    $this->finishReview($requestId, 'conflict', $adminId, 'تغيرت بيانات الطالب بعد إنشاء الطلب.');
                    (new AuditService($this->db))->recordEvent('update', 'student_change_request', $requestId, 'تعارض طلب تعديل طالب', ['status' => 'conflict', 'field' => 'record_version']);
                    $this->db->commit();
                    return ['status' => 'conflict', 'message' => 'تعذر التطبيق لأن بيانات الطالب تغيرت بعد إنشاء الطلب.'];
                }

                $profileRequest = StudentChangeFieldPolicy::profileRequest(
                    is_array($proposedPayload['request'] ?? null) ? $proposedPayload['request'] : []
                );
                $profileRequest = StudentChangeFieldPolicy::omitUntouchedCompositeGroups($profileRequest);
                $this->scope->assertStudentAllowed(
                    (int) $request['specialist_id'],
                    (int) $request['academic_year_id'],
                    (int) $request['student_id']
                );
                $newClassId = (int) ($profileRequest['class_id'] ?? 0);
                if ($newClassId > 0) {
                    $this->scope->assertClassAllowed(
                        (int) $request['specialist_id'],
                        (int) $request['academic_year_id'],
                        $newClassId
                    );
                }
                $this->commands->applyApprovedSpecialistProfile(
                    (int) $request['student_id'],
                    $profileRequest,
                    $adminId
                );
                $auditDisplay = StudentChangeFieldPolicy::filterUntouchedCompositeDisplay(
                    is_array($proposedPayload['display'] ?? null) ? $proposedPayload['display'] : [],
                    is_array($proposedPayload['request'] ?? null) ? $proposedPayload['request'] : []
                );
                $auditFields = array_keys($auditDisplay);
            } else {
                $before = StudentChangeFieldPolicy::filter($beforePayload);
                $proposed = StudentChangeFieldPolicy::filter($proposedPayload);
            $current = StudentChangeFieldPolicy::snapshot($this->profileSnapshot((int)$request['student_id']));
            foreach ($proposed as $field => $_value) {
                if (($current[$field] ?? '') !== ($before[$field] ?? '')) {
                    $this->finishReview($requestId, 'conflict', $adminId, 'تغيرت بيانات الطالب بعد إنشاء الطلب.');
                    (new AuditService($this->db))->recordEvent('update', 'student_change_request', $requestId, 'تعارض طلب تعديل طالب', ['status' => 'conflict', 'field' => $field]);
                    $this->db->commit();
                    return ['status' => 'conflict', 'message' => 'تعذر التطبيق لأن بيانات الطالب تغيرت بعد إنشاء الطلب.'];
                }
            }

            $this->commands->applyApprovedSpecialistChanges((int)$request['student_id'], $proposed, $adminId);
                $auditFields = array_keys($proposed);
            }
            $this->finishReview($requestId, 'approved', $adminId, null);
            (new AuditService($this->db))->recordEvent('update', 'student_change_request', $requestId, 'الموافقة على تعديل طالب', ['status' => 'approved', 'fields' => $auditFields]);
            $this->db->commit();
            return ['status' => 'approved', 'message' => $successMessage];
        } catch (Throwable $e) {
            if ($this->db->inTransaction()) $this->db->rollBack();
            throw $e;
        }
    }

    public function reject(int $requestId, int $adminId, string $reason): void
    {
        $reason = trim($reason);
        if ($reason === '') throw new InvalidArgumentException('اكتب سبب رفض الطلب.');
        $this->db->beginTransaction();
        try {
            $this->lockedPendingRequest($requestId);
            $this->finishReview($requestId, 'rejected', $adminId, mb_substr($reason, 0, 500));
            (new AuditService($this->db))->recordEvent('update', 'student_change_request', $requestId, 'رفض تعديل طالب', ['status' => 'rejected', 'reason' => mb_substr($reason, 0, 500)]);
            $this->db->commit();
        } catch (Throwable $e) {
            if ($this->db->inTransaction()) $this->db->rollBack();
            throw $e;
        }
    }

    /** @return array<int,array<string,mixed>> */
    public function listForAdmin(string $status = 'pending'): array
    {
        $allowed = ['pending', 'approved', 'rejected', 'conflict', 'cancelled', 'all'];
        if (!in_array($status, $allowed, true)) $status = 'pending';
        $where = $status === 'all' ? '' : 'WHERE scr.status = ?';
        $stmt = $this->db->prepare("SELECT scr.*, s.name AS student_name, sp.student_code,
                specialist.name AS specialist_name, reviewer.name AS reviewer_name,
                c.name AS class_name, g.grade_name,
                COALESCE(se.class_id, s.class_id) AS current_class_id,
                COALESCE(NULLIF(se.grade_id, 0), c.grade_id) AS current_grade_id
            FROM student_change_requests scr
            JOIN users s ON s.id = scr.student_id
            JOIN users specialist ON specialist.id = scr.specialist_id
            LEFT JOIN users reviewer ON reviewer.id = scr.reviewed_by
            LEFT JOIN student_profiles sp ON sp.user_id = s.id
            LEFT JOIN student_enrollments se ON se.student_id = s.id
                AND se.academic_year_id = scr.academic_year_id
                AND se.enrollment_status = 'enrolled'
            LEFT JOIN classes c ON c.id = COALESCE(se.class_id, s.class_id)
            LEFT JOIN grades g ON g.id = COALESCE(se.grade_id, c.grade_id)
            {$where} ORDER BY FIELD(scr.status, 'pending','conflict','rejected','approved','cancelled'), scr.created_at DESC");
        $stmt->execute($status === 'all' ? [] : [$status]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    }

    /** @return array<int,array<string,mixed>> */
    public function listForSpecialist(
        int $specialistId,
        int $academicYearId,
        string $status = 'all'
    ): array {
        if ($specialistId <= 0 || $academicYearId <= 0) {
            return [];
        }

        $allowed = ['pending', 'approved', 'rejected', 'conflict', 'cancelled', 'all'];
        if (!in_array($status, $allowed, true)) {
            $status = 'all';
        }
        $statusSql = $status === 'all' ? '' : ' AND scr.status = ?';
        $params = [$specialistId, $academicYearId];
        if ($status !== 'all') {
            $params[] = $status;
        }

        $stmt = $this->db->prepare("SELECT scr.*, s.name AS student_name, sp.student_code,
                reviewer.name AS reviewer_name, c.name AS class_name, g.grade_name,
                COALESCE(se.class_id, s.class_id) AS current_class_id,
                COALESCE(NULLIF(se.grade_id, 0), c.grade_id) AS current_grade_id
            FROM student_change_requests scr
            JOIN users s ON s.id = scr.student_id AND s.role = 'student'
            LEFT JOIN users reviewer ON reviewer.id = scr.reviewed_by
            LEFT JOIN student_profiles sp ON sp.user_id = s.id
            LEFT JOIN student_enrollments se ON se.student_id = s.id
                AND se.academic_year_id = scr.academic_year_id
                AND se.enrollment_status = 'enrolled'
            LEFT JOIN classes c ON c.id = COALESCE(se.class_id, s.class_id)
            LEFT JOIN grades g ON g.id = COALESCE(se.grade_id, c.grade_id)
            WHERE scr.specialist_id = ? AND scr.academic_year_id = ?{$statusSql}
            ORDER BY FIELD(scr.status, 'pending','conflict','rejected','approved','cancelled'),
                scr.created_at DESC, scr.id DESC");
        $stmt->execute($params);
        return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    }

    /** @return array<string,mixed> */
    private function profileSnapshot(int $studentId): array
    {
        $stmt = $this->db->prepare("SELECT sp.* FROM users u JOIN student_profiles sp ON sp.user_id = u.id WHERE u.id = ? AND u.role = 'student' AND u.deleted_at IS NULL LIMIT 1");
        $stmt->execute([$studentId]);
        $profile = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$profile) throw new RuntimeException('تعذر العثور على ملف الطالب.');
        return $profile;
    }

    /** @return array<string,mixed> */
    private function lockedPendingRequest(int $requestId): array
    {
        $stmt = $this->db->prepare("SELECT * FROM student_change_requests WHERE id = ? LIMIT 1 FOR UPDATE");
        $stmt->execute([$requestId]);
        $request = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$request || (string)$request['status'] !== 'pending') throw new RuntimeException('الطلب غير موجود أو تمت مراجعته سابقاً.');
        return $request;
    }

    private function finishReview(int $requestId, string $status, int $adminId, ?string $reason): void
    {
        $stmt = $this->db->prepare('UPDATE student_change_requests SET status = ?, reviewed_by = ?, reviewed_at = NOW(), rejection_reason = ? WHERE id = ?');
        $stmt->execute([$status, $adminId, $reason, $requestId]);
    }

    /**
     * @param array<string,mixed> $before
     * @param array<string,mixed> $proposed
     * @param array<int,string> $fields
     */
    private function insertPendingRequest(
        int $specialistId,
        int $academicYearId,
        int $studentId,
        array $before,
        array $proposed,
        array $fields
    ): int {
        $ownsTransaction = !$this->db->inTransaction();
        if ($ownsTransaction) $this->db->beginTransaction();
        try {
            $studentLock = $this->db->prepare("SELECT id FROM users WHERE id = ? AND role = 'student' LIMIT 1 FOR UPDATE");
            $studentLock->execute([$studentId]);
            if (!$studentLock->fetchColumn()) throw new RuntimeException('تعذر العثور على الطالب.');
            $duplicate = $this->db->prepare("SELECT 1 FROM student_change_requests WHERE student_id = ? AND specialist_id = ? AND status = 'pending' LIMIT 1");
            $duplicate->execute([$studentId, $specialistId]);
            if ($duplicate->fetchColumn()) throw new RuntimeException('يوجد طلب تعديل معلق لهذا الطالب بالفعل.');

            $stmt = $this->db->prepare("INSERT INTO student_change_requests
                (student_id, specialist_id, academic_year_id, before_payload, proposed_payload, status)
                VALUES (?, ?, ?, ?, ?, 'pending')");
            $stmt->execute([
                $studentId,
                $specialistId,
                $academicYearId,
                json_encode($before, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR),
                json_encode($proposed, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR),
            ]);
            $requestId = (int) $this->db->lastInsertId();
            (new AuditService($this->db))->recordEvent('create', 'student_change_request', $requestId, 'طلب تعديل طالب', [
                'student_id' => $studentId,
                'specialist_id' => $specialistId,
                'academic_year_id' => $academicYearId,
                'fields' => $fields,
            ]);
            if ($ownsTransaction) $this->db->commit();
            return $requestId;
        } catch (Throwable $e) {
            if ($ownsTransaction && $this->db->inTransaction()) $this->db->rollBack();
            throw $e;
        }
    }

    /** @return array<string,mixed> */
    private function decodePayloadRaw(string $payload): array
    {
        $decoded = json_decode($payload, true, 512, JSON_THROW_ON_ERROR);
        return is_array($decoded) ? $decoded : [];
    }

    /**
     * @param array<string,mixed> $profile
     * @param array<string,mixed> $request
     * @return array<string,array{before:mixed,after:mixed}>
     */
    private function profileDisplayChanges(
        int $studentId,
        int $academicYearId,
        array $profile,
        array $request
    ): array {
        $current = $profile;
        $classStmt = $this->db->prepare("SELECT COALESCE(se.class_id, u.class_id) AS class_id,
                COALESCE(NULLIF(se.grade_id, 0), c.grade_id) AS grade_id,
                se.enrollment_status, u.status, u.name
            FROM users u
            LEFT JOIN student_enrollments se ON se.student_id = u.id
                AND se.academic_year_id = ? AND se.enrollment_status = 'enrolled'
            LEFT JOIN classes c ON c.id = COALESCE(se.class_id, u.class_id)
            WHERE u.id = ? LIMIT 1");
        $classStmt->execute([$academicYearId, $studentId]);
        $identity = $classStmt->fetch(PDO::FETCH_ASSOC) ?: [];
        $current['class_id'] = $identity['class_id'] ?? '';
        $current['grade_id'] = !empty($identity['grade_id'])
            ? $identity['grade_id']
            : ($profile['grade_id'] ?? '');
        $current['enrollment_status'] = !empty($identity['enrollment_status'])
            ? $identity['enrollment_status']
            : ($profile['enrollment_status'] ?? '');
        $current['status'] = $identity['status'] ?? '';
        if (trim((string) ($current['first_name_ar'] ?? '')) === ''
            && trim((string) ($identity['name'] ?? '')) !== '') {
            $current = array_merge($current, User::splitDisplayName((string) $identity['name']));
        }

        $labels = StudentChangeFieldPolicy::labels();
        $changes = [];
        foreach ($labels as $field => $_label) {
            if (in_array($field, ['extra_phones', 'extra_data', 'guardians', 'external_transfer'], true)
                || !array_key_exists($field, $request)) {
                continue;
            }
            $beforeValue = $this->normalizedComparable($current[$field] ?? '');
            $afterValue = $this->normalizedComparable($request[$field]);
            if ($beforeValue !== $afterValue) {
                $changes[$field] = ['before' => $current[$field] ?? '', 'after' => $request[$field]];
            }
        }

        $phoneFields = ['student_mobile_numbers', 'student_mobile_notes', 'student_landline_numbers', 'student_landline_notes'];
        if (!empty($request['student_extra_phones_present']) || array_intersect($phoneFields, array_keys($request))) {
            $afterPhones = StudentProfilePayload::studentExtraPhones($request);
            $this->appendJsonChange($changes, 'extra_phones', $profile['extra_phones'] ?? null, $afterPhones);
        }

        $extraDataFields = ['additional_data_labels', 'additional_data_values', 'educational_guardianship', 'educational_guardianship_other'];
        if (!empty($request['student_extra_data_present']) || array_intersect($extraDataFields, array_keys($request))) {
            $afterExtraData = StudentProfilePayload::studentExtraData($request);
            $guardianship = (string) ($request['educational_guardianship'] ?? '');
            if (in_array($guardianship, ['other', 'أخرى'], true)) {
                $guardianship = (string) ($request['educational_guardianship_other'] ?? '');
            }
            $afterExtraData = StudentProfilePayload::mergeEducationalGuardianship($afterExtraData, $guardianship);
            $this->appendJsonChange($changes, 'extra_data', $profile['extra_data'] ?? null, $afterExtraData);
        }

        if (!empty($request['student_guardians_present']) || array_key_exists('guardians', $request)) {
            $guardianStmt = $this->db->prepare('SELECT * FROM student_guardians WHERE student_id = ? ORDER BY id');
            $guardianStmt->execute([$studentId]);
            $beforeGuardians = $this->canonicalGuardians($guardianStmt->fetchAll(PDO::FETCH_ASSOC) ?: []);
            $afterGuardians = $this->canonicalGuardians(is_array($request['guardians']) ? $request['guardians'] : []);
            if ($this->normalizedComparable($beforeGuardians) !== $this->normalizedComparable($afterGuardians)) {
                $changes['guardians'] = ['before' => $beforeGuardians, 'after' => $afterGuardians];
            }
        }

        $externalKeys = ['transfer_destination', 'external_transfer_date', 'external_transfer_reason', 'external_transfer_notes'];
        if (!empty($request['student_external_transfer_present']) || array_intersect($externalKeys, array_keys($request))) {
            $externalStmt = $this->db->prepare('SELECT destination AS transfer_destination, transfer_date AS external_transfer_date, reason AS external_transfer_reason, notes AS external_transfer_notes FROM student_external_transfers WHERE student_id = ? LIMIT 1');
            $externalStmt->execute([$studentId]);
            $beforeExternal = $externalStmt->fetch(PDO::FETCH_ASSOC) ?: [];
            $afterExternal = [];
            foreach ($externalKeys as $key) $afterExternal[$key] = $request[$key] ?? '';
            if ($this->normalizedComparable($beforeExternal) !== $this->normalizedComparable($afterExternal)) {
                $changes['external_transfer'] = ['before' => $beforeExternal, 'after' => $afterExternal];
            }
        }

        return $changes;
    }

    /** @param array<string,array{before:mixed,after:mixed}> $changes */
    private function appendJsonChange(array &$changes, string $field, mixed $before, mixed $after): void
    {
        if ($field === 'extra_phones') {
            $beforeValue = $this->sortComparableRows(StudentProfilePayload::decodePhonesForForm((string) ($before ?? '')));
            $afterValue = $this->sortComparableRows(StudentProfilePayload::decodePhonesForForm((string) ($after ?? '')));
        } elseif ($field === 'extra_data') {
            $beforeValue = $this->sortComparableRows(StudentProfilePayload::decodeExtraDataForForm((string) ($before ?? '')));
            $afterValue = $this->sortComparableRows(StudentProfilePayload::decodeExtraDataForForm((string) ($after ?? '')));
        } else {
            $beforeValue = json_decode((string) ($before ?? ''), true) ?: [];
            $afterValue = json_decode((string) ($after ?? ''), true) ?: [];
        }
        if ($this->normalizedComparable($beforeValue) !== $this->normalizedComparable($afterValue)) {
            $changes[$field] = ['before' => $beforeValue, 'after' => $afterValue];
        }
    }

    /** @param array<int,array<string,mixed>> $rows @return array<int,array<string,mixed>> */
    private function canonicalGuardians(array $rows): array
    {
        $fields = ['guardian_name', 'relationship', 'relationship_other', 'birth_date', 'birth_place',
            'religion', 'religion_other', 'nationality', 'nationality_other', 'national_id',
            'passport_number', 'phone_primary', 'phone_landline', 'email', 'address',
            'qualification', 'job_title', 'employer', 'work_phone'];
        $result = [];
        foreach ($rows as $row) {
            $item = [];
            foreach ($fields as $field) $item[$field] = trim((string) ($row[$field] ?? ''));
            $item['extra_phones'] = isset($row['extra_phones']) && !is_array($row['extra_phones'])
                ? $this->sortComparableRows(StudentProfilePayload::decodePhonesForForm((string) $row['extra_phones']))
                : $this->sortComparableRows(StudentProfilePayload::decodePhonesForForm(StudentProfilePayload::guardianExtraPhones($row)));
            $item['extra_data'] = isset($row['extra_data']) && !is_array($row['extra_data'])
                ? $this->sortComparableRows(StudentProfilePayload::decodeExtraDataForForm((string) $row['extra_data']))
                : $this->sortComparableRows(StudentProfilePayload::decodeExtraDataForForm(StudentProfilePayload::guardianExtraData($row)));
            $result[] = $item;
        }
        return $result;
    }

    private function normalizedComparable(mixed $value): string
    {
        if (is_array($value)) {
            $normalized = [];
            foreach ($value as $key => $item) {
                $itemValue = $this->normalizedComparableValue($item);
                if ($itemValue === '' || $itemValue === []) continue;
                $normalized[$key] = $itemValue;
            }
            if (!$this->isListArray($normalized)) ksort($normalized);
            return json_encode($normalized, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?: '';
        }
        return trim((string) $value);
    }

    private function normalizedComparableValue(mixed $value): mixed
    {
        if (!is_array($value)) return trim((string) $value);
        $normalized = [];
        foreach ($value as $key => $item) {
            $itemValue = $this->normalizedComparableValue($item);
            if ($itemValue === '' || $itemValue === []) continue;
            $normalized[$key] = $itemValue;
        }
        if (!$this->isListArray($normalized)) ksort($normalized);
        return $normalized;
    }

    private function isListArray(array $value): bool
    {
        $index = 0;
        foreach ($value as $key => $_item) {
            if ($key !== $index++) return false;
        }
        return true;
    }

    /** @param array<int,mixed> $rows @return array<int,mixed> */
    private function sortComparableRows(array $rows): array
    {
        usort($rows, function (mixed $left, mixed $right): int {
            $leftValue = json_encode($this->normalizedComparableValue($left), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?: '';
            $rightValue = json_encode($this->normalizedComparableValue($right), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?: '';
            return strcmp($leftValue, $rightValue);
        });
        return $rows;
    }
}
