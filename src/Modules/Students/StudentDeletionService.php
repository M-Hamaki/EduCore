<?php

declare(strict_types=1);

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

final class StudentDeletionService
{
    private PDO $db;
    private StudentProfileRepository $profiles;

    public function __construct(PDO $db, StudentProfileRepository $profiles)
    {
        $this->db = $db;
        $this->profiles = $profiles;
    }

    public function delete(int $studentId): void
    {
        $targetStmt = $this->db->prepare("SELECT id FROM users WHERE id = ? AND role = 'student' LIMIT 1");
        $targetStmt->execute([$studentId]);
        if (!$targetStmt->fetchColumn()) {
            throw new InvalidArgumentException('ملف الطالب المطلوب غير موجود أو لا يمكن حذفه من هذه الصفحة.');
        }

        $oldStudentData = UndoManager::fetchRecord('users', $studentId);
        $user = new User($this->db);
        $user->id = $studentId;

        $this->db->beginTransaction();
        try {
            if (!$user->delete()) {
                throw new RuntimeException('حدث خطأ أثناء حذف الطالب.');
            }
            if (!ActivityLog::logDelete('student', $studentId, $oldStudentData['name'] ?? ('طالب #' . $studentId), [
                'summary' => 'تم حذف ملف الطالب',
                'class' => $this->profiles->className(!empty($oldStudentData['class_id']) ? (int) $oldStudentData['class_id'] : null),
            ])) {
                throw new RuntimeException('تعذر تسجيل حذف الطالب في سجل العمليات.');
            }
            if ($oldStudentData) {
                UndoManager::logDelete('users', $studentId, $oldStudentData, 'حذف طالب: ' . ($oldStudentData['name'] ?? ''));
            }
            $this->db->commit();
        } catch (Throwable $e) {
            if ($this->db->inTransaction()) {
                $this->db->rollBack();
            }
            throw $e;
        }
    }
}
