<?php

declare(strict_types=1);

namespace EduCore\Modules\Students;

use AcademicYear;
use AcademicYearWriteGuard;
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

final class StudentProfileLifecycleService
{
    private PDO $db;
    private StudentEnrollmentService $enrollments;

    public function __construct(PDO $db, StudentEnrollmentService $enrollments)
    {
        $this->db = $db;
        $this->enrollments = $enrollments;
    }

    public function sync(
        int $studentId,
        ?int $gradeId,
        ?int $classId,
        string $enrollmentStatus,
        string $academicStatus,
        string $requestedAccountStatus,
        array $post,
        int $actorId,
        ?int $oldClassId = null,
        bool $syncMovedMarks = false,
        bool $parentAuditsUser = false
    ): array {
        $ownsTransaction = !$this->db->inTransaction();
        try {
            if ($ownsTransaction) $this->db->beginTransaction();
            $beforeStmt = $this->db->prepare("SELECT * FROM users WHERE id = ? AND role = 'student' FOR UPDATE");
            $beforeStmt->execute([$studentId]);
            $beforeUser = $beforeStmt->fetch(PDO::FETCH_ASSOC);
            if (!$beforeUser) throw new RuntimeException('Student account not found for lifecycle sync.');

            $accountStatus = $this->accountStatus($enrollmentStatus, $academicStatus, $requestedAccountStatus);
            $this->db->prepare('UPDATE users SET status = ? WHERE id = ?')->execute([$accountStatus, $studentId]);

        if ($enrollmentStatus === 'transferred') {
            $this->enrollments->saveExternalTransfer($studentId, $post, $actorId);
        }
        $academicYearId = AcademicYear::currentId($this->db);
        (new AcademicYearWriteGuard($this->db))->assertWritable($academicYearId);
        $this->enrollments->syncEnrollmentStatus(
            $studentId,
            $academicYearId,
            $gradeId,
            $classId,
            $enrollmentStatus,
            $academicStatus
        );

        $movedMarks = 0;
        if ($syncMovedMarks && $enrollmentStatus === 'enrolled' && $oldClassId !== $classId) {
            $movedMarks = $this->enrollments->syncAssessmentMarksClass(
                $studentId,
                $academicYearId,
                $oldClassId,
                $classId
            );
        }
        $this->enrollments->syncAssessmentLock(
            $studentId,
            $academicYearId,
            $enrollmentStatus,
            $academicStatus,
            $actorId
        );

            if (!$parentAuditsUser) {
                $afterStmt = $this->db->prepare('SELECT * FROM users WHERE id = ?');
                $afterStmt->execute([$studentId]);
                $afterUser = $afterStmt->fetch(PDO::FETCH_ASSOC);
                if (!$afterUser) throw new RuntimeException('Student account could not be reloaded after lifecycle sync.');
                (new AuditService($this->db))->recordUpdate(
                    'student', 'users', $studentId, (string) $afterUser['name'],
                    $beforeUser, $afterUser, 'مزامنة حالة دورة حياة الطالب'
                );
            }
            if ($ownsTransaction) $this->db->commit();
            return ['academic_year_id' => $academicYearId, 'moved_assessment_marks' => $movedMarks];
        } catch (Throwable $e) {
            if ($ownsTransaction && $this->db->inTransaction()) $this->db->rollBack();
            throw $e;
        }
    }

    public function accountStatus(string $enrollmentStatus, string $academicStatus, string $requestedStatus): string
    {
        if ($academicStatus === 'graduated') {
            return 'graduated';
        }
        if (in_array($enrollmentStatus, ['transferred', 'discontinued'], true)) {
            return 'inactive';
        }
        return $requestedStatus;
    }
}
