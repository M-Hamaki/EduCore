<?php

namespace EduCore\Modules\Students;

use AcademicYear;
use ActivityLog;
use DateTime;
use InvalidArgumentException;
use PDO;
use ProfileAttachmentStorage;
use ProfileInputValidator;
use RuntimeException;
use Throwable;
use UndoManager;
use User;
use EduCore\Modules\Operations\Audit\AuditService;

final class StudentEnrollmentService
{
    private PDO $db;

    public function __construct(PDO $db)
    {
        $this->db = $db;
    }

    public function normalizeStatus(array $post, string $scope): string
    {
        $default = $scope === 'transferred' ? 'transferred' : 'enrolled';
        $status = trim((string) ($post['enrollment_status'] ?? $default));
        if ($status === 'graduated') {
            $status = 'enrolled';
        } elseif ($status === 'withdrawn') {
            $status = 'discontinued';
        }
        if (!in_array($status, ['enrolled', 'transferred', 'discontinued'], true)) {
            throw new InvalidArgumentException('حالة قيد الطالب غير صالحة.');
        }
        if ($status === 'transferred'
            && (trim((string) ($post['transfer_destination'] ?? '')) === '' || empty($post['external_transfer_date']))) {
            throw new InvalidArgumentException('يجب تسجيل الجهة المنقول إليها وتاريخ النقل.');
        }
        return $status;
    }

    public function normalizeAcademicStatus(array $post, string $scope, ?string $fallback = null): string
    {
        $default = $scope === 'graduates' ? 'graduated' : ($fallback ?: 'new');
        $status = trim((string) ($post['academic_status'] ?? $default));
        if (($post['enrollment_status'] ?? null) === 'graduated') {
            $status = 'graduated';
        }
        if (!in_array($status, ['new', 'promoted', 'retained', 'graduated'], true)) {
            throw new InvalidArgumentException('الحالة الدراسية للطالب غير صالحة.');
        }
        return $status;
    }

    public function saveExternalTransfer(int $studentId, array $post, int $actorId): void
    {
        $ownsTransaction = !$this->db->inTransaction();
        try {
            if ($ownsTransaction) $this->db->beginTransaction();
            $beforeStmt = $this->db->prepare('SELECT * FROM student_external_transfers WHERE student_id = ? FOR UPDATE');
            $beforeStmt->execute([$studentId]);
            $before = $beforeStmt->fetch(PDO::FETCH_ASSOC) ?: null;
            $stmt = $this->db->prepare("INSERT INTO student_external_transfers
            (student_id, destination, transfer_date, reason, notes, transferred_by)
            VALUES (?, ?, ?, ?, ?, ?)
            ON DUPLICATE KEY UPDATE destination = VALUES(destination), transfer_date = VALUES(transfer_date),
                reason = VALUES(reason), notes = VALUES(notes), transferred_by = VALUES(transferred_by)");
            $stmt->execute([
                $studentId, trim((string) $post['transfer_destination']), $post['external_transfer_date'],
                trim((string) ($post['external_transfer_reason'] ?? '')),
                trim((string) ($post['external_transfer_notes'] ?? '')), $actorId,
            ]);
            $afterStmt = $this->db->prepare('SELECT * FROM student_external_transfers WHERE student_id = ?');
            $afterStmt->execute([$studentId]);
            $after = $afterStmt->fetch(PDO::FETCH_ASSOC);
            if (!$after) throw new RuntimeException('External transfer could not be reloaded.');
            $audit = new AuditService($this->db);
            if ($before) {
                $audit->recordUpdate('student_external_transfer', 'student_external_transfers', (int) $after['id'], 'نقل طالب #' . $studentId, $before, $after, 'تحديث النقل الخارجي للطالب');
            } else {
                $audit->recordInsert('student_external_transfer', 'student_external_transfers', (int) $after['id'], 'نقل طالب #' . $studentId, $after, 'تسجيل نقل خارجي للطالب');
            }
            if ($ownsTransaction) $this->db->commit();
        } catch (Throwable $e) {
            if ($ownsTransaction && $this->db->inTransaction()) $this->db->rollBack();
            throw $e;
        }
    }

    public function syncAssessmentLock(
        int $studentId,
        int $academicYearId,
        string $enrollmentStatus,
        string $academicStatus,
        int $actorId
    ): void
    {
        if ($studentId <= 0 || $academicYearId <= 0 || !$this->tableExists('assessment_student_locks')) {
            return;
        }

        $ownsTransaction = !$this->db->inTransaction();
        try {
            if ($ownsTransaction) $this->db->beginTransaction();
            $beforeStmt = $this->db->prepare('SELECT * FROM assessment_student_locks WHERE student_id = ? AND academic_year_id = ? FOR UPDATE');
            $beforeStmt->execute([$studentId, $academicYearId]);
            $before = $beforeStmt->fetch(PDO::FETCH_ASSOC) ?: null;

        $lockReason = $academicStatus === 'graduated'
            ? 'graduated'
            : (in_array($enrollmentStatus, ['transferred', 'discontinued'], true) ? $enrollmentStatus : null);
        if ($lockReason !== null) {
            $notes = $lockReason === 'graduated'
                ? 'قفل تلقائي بسبب تخرج الطالب'
                : ($lockReason === 'transferred'
                    ? 'قفل تلقائي بسبب نقل الطالب من المدرسة'
                    : 'قفل تلقائي بسبب انقطاع الطالب');
            $stmt = $this->db->prepare("INSERT INTO assessment_student_locks
                (student_id, academic_year_id, lock_reason, locked_by, notes)
                VALUES (?, ?, ?, ?, ?)
                ON DUPLICATE KEY UPDATE
                    lock_reason = VALUES(lock_reason),
                    locked_by = VALUES(locked_by),
                    notes = VALUES(notes)");
            $stmt->execute([$studentId, $academicYearId, $lockReason, $actorId ?: null, $notes]);
            $afterStmt = $this->db->prepare('SELECT * FROM assessment_student_locks WHERE student_id = ? AND academic_year_id = ?');
            $afterStmt->execute([$studentId, $academicYearId]);
            $after = $afterStmt->fetch(PDO::FETCH_ASSOC);
            if (!$after) throw new RuntimeException('Assessment lock could not be reloaded.');
            $audit = new AuditService($this->db);
            if ($before) {
                $audit->recordUpdate('assessment_student_lock', 'assessment_student_locks', (int) $after['id'], 'قفل تقييم طالب #' . $studentId, $before, $after, 'تحديث قفل تقييم الطالب');
            } else {
                $audit->recordInsert('assessment_student_lock', 'assessment_student_locks', (int) $after['id'], 'قفل تقييم طالب #' . $studentId, $after, 'إضافة قفل تقييم الطالب');
            }
        } elseif ($enrollmentStatus === 'enrolled' && $academicStatus !== 'graduated' && $before) {
            $stmt = $this->db->prepare('DELETE FROM assessment_student_locks WHERE student_id = ? AND academic_year_id = ?');
            $stmt->execute([$studentId, $academicYearId]);
            (new AuditService($this->db))->recordDelete('assessment_student_lock', 'assessment_student_locks', (int) $before['id'], 'قفل تقييم طالب #' . $studentId, $before, 'إزالة قفل تقييم الطالب');
        }
            if ($ownsTransaction) $this->db->commit();
        } catch (Throwable $e) {
            if ($ownsTransaction && $this->db->inTransaction()) $this->db->rollBack();
            throw $e;
        }
    }

    public function syncEnrollmentStatus(
        int $studentId,
        int $academicYearId,
        ?int $gradeId,
        ?int $classId,
        string $enrollmentStatus,
        string $academicStatus
    ): void
    {
        if ($studentId <= 0 || $academicYearId <= 0) {
            return;
        }

        $stageId = null;
        if ($classId) {
            $stmt = $this->db->prepare(
                'SELECT c.grade_id, c.academic_year_id, c.status, g.stage_id
                 FROM classes c
                 LEFT JOIN grades g ON g.id = c.grade_id
                 WHERE c.id = ?
                 LIMIT 1'
            );
            $stmt->execute([$classId]);
            $classInfo = $stmt->fetch(PDO::FETCH_ASSOC) ?: [];
            if (!$classInfo
                || (int)($classInfo['academic_year_id'] ?? 0) !== $academicYearId
                || (string)($classInfo['status'] ?? '') !== 'active') {
                throw new InvalidArgumentException('الفصل المختار غير متاح في العام الدراسي الحالي.');
            }
            $classGradeId = !empty($classInfo['grade_id']) ? (int) $classInfo['grade_id'] : null;
            if ($gradeId && $classGradeId && $gradeId !== $classGradeId) {
                throw new InvalidArgumentException('الفصل المختار لا يتبع الصف الدراسي المحدد.');
            }
            $gradeId = $classGradeId ?: $gradeId;
            $stageId = !empty($classInfo['stage_id']) ? (int) $classInfo['stage_id'] : null;
        } elseif ($gradeId) {
            $stmt = $this->db->prepare('SELECT stage_id FROM grades WHERE id = ? LIMIT 1');
            $stmt->execute([$gradeId]);
            $stageValue = $stmt->fetchColumn();
            if ($stageValue === false) {
                throw new InvalidArgumentException('الصف الدراسي المحدد غير صالح.');
            }
            $stageId = $stageValue !== null ? (int) $stageValue : null;
        } else {
            $existing = StudentEnrollment::getStudentEnrollment($this->db, $studentId, $academicYearId);
            if ($existing) {
                $stageId = !empty($existing['stage_id']) ? (int) $existing['stage_id'] : null;
                $gradeId = !empty($existing['grade_id']) ? (int) $existing['grade_id'] : null;
                $classId = !empty($existing['class_id']) ? (int) $existing['class_id'] : null;
            }
        }

        StudentEnrollment::upsert(
            $this->db,
            $studentId,
            $academicYearId,
            $stageId,
            $gradeId,
            $classId,
            $enrollmentStatus,
            $academicStatus
        );
    }

    public function syncAssessmentMarksClass(
        int $studentId,
        int $academicYearId,
        ?int $oldClassId,
        ?int $newClassId,
        ?string $batchId = null
    ): int
    {
        if ($studentId <= 0 || $academicYearId <= 0 || !$newClassId || $oldClassId === $newClassId
            || !$this->tableExists('student_marks')) {
            return 0;
        }

        $ownsTransaction = !$this->db->inTransaction();
        try {
            if ($ownsTransaction) $this->db->beginTransaction();
            $beforeStmt = $this->db->prepare("SELECT * FROM student_marks
                WHERE student_id = ? AND academic_year_id = ?
                  AND (class_id_at_entry IS NULL OR class_id_at_entry = ?) FOR UPDATE");
            $beforeStmt->execute([$studentId, $academicYearId, $oldClassId]);
            $beforeRows = $beforeStmt->fetchAll(PDO::FETCH_ASSOC);
            $stmt = $this->db->prepare("UPDATE student_marks
            SET class_id_at_entry = ?
            WHERE student_id = ?
              AND academic_year_id = ?
              AND (class_id_at_entry IS NULL OR class_id_at_entry = ?)");
        $stmt->execute([$newClassId, $studentId, $academicYearId, $oldClassId]);
            if ($stmt->rowCount() !== count($beforeRows)) throw new RuntimeException('Assessment mark move count mismatch.');
            if ($beforeRows) {
                $ids = array_map(static fn(array $row): int => (int) $row['id'], $beforeRows);
                $placeholders = implode(',', array_fill(0, count($ids), '?'));
                $afterStmt = $this->db->prepare("SELECT * FROM student_marks WHERE id IN ({$placeholders}) ORDER BY id");
                $afterStmt->execute($ids);
                $afterById = [];
                foreach ($afterStmt->fetchAll(PDO::FETCH_ASSOC) as $row) $afterById[(int) $row['id']] = $row;
                $items = [];
                foreach ($beforeRows as $before) {
                    $id = (int) $before['id'];
                    $items[] = [
                        'table' => 'student_marks', 'record_id' => $id,
                        'before' => $before, 'after' => $afterById[$id] ?? [],
                        'description' => 'نقل درجة الطالب إلى فصل جديد',
                    ];
                }
                (new AuditService($this->db))->recordCompositeUpdate(
                    'student_mark_class_move', $studentId, 'درجات طالب #' . $studentId,
                    $items,
                    ['academic_year_id' => $academicYearId, 'class_id_before' => $oldClassId, 'class_id_after' => $newClassId, 'count' => count($items)],
                    $batchId ?: bin2hex(random_bytes(16))
                );
            }
            if ($ownsTransaction) $this->db->commit();
            return count($beforeRows);
        } catch (Throwable $e) {
            if ($ownsTransaction && $this->db->inTransaction()) $this->db->rollBack();
            throw $e;
        }
    }

    private function tableExists(string $table): bool
    {
        $stmt = $this->db->prepare('SELECT 1 FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?');
        $stmt->execute([$table]);
        return (bool) $stmt->fetchColumn();
    }
}
