<?php

declare(strict_types=1);

namespace EduCore\Modules\Students;

use EduCore\Modules\Students\Contracts\StudentWriteEligibility;
use InvalidArgumentException;
use PDO;
use RuntimeException;

final class StudentOperationalGuard implements StudentWriteEligibility
{
    private PDO $db;

    public function __construct(PDO $db)
    {
        $this->db = $db;
    }

    public function assertWritable(int $studentId): array
    {
        if ($studentId <= 0) {
            throw new InvalidArgumentException('معرف الطالب غير صالح.');
        }

        $sql = "SELECT id, name, status, deleted_at
             FROM users
             WHERE id = ? AND role = 'student' LIMIT 1";
        if ($this->db->inTransaction()) {
            $sql .= ' FOR UPDATE';
        }
        $stmt = $this->db->prepare($sql);
        $stmt->execute([$studentId]);
        $student = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$student) {
            throw new InvalidArgumentException('الطالب المطلوب غير موجود.');
        }
        if (!empty($student['deleted_at'])) {
            throw new RuntimeException('لا يمكن تنفيذ معاملة جديدة لطالب مؤرشف. استرجع الطالب أولاً.');
        }
        if (($student['status'] ?? '') !== 'active') {
            throw new RuntimeException('لا يمكن تنفيذ معاملة جديدة لحساب طالب غير نشط.');
        }

        return $student;
    }

    public function assertWritableMany(array $studentIds): void
    {
        $studentIds = array_values(array_unique(array_filter(array_map('intval', $studentIds))));
        if (!$studentIds) {
            return;
        }

        $placeholders = implode(',', array_fill(0, count($studentIds), '?'));
        $sql = "SELECT id FROM users
             WHERE id IN ($placeholders) AND role = 'student'
               AND status = 'active' AND deleted_at IS NULL";
        if ($this->db->inTransaction()) {
            $sql .= ' FOR UPDATE';
        }
        $stmt = $this->db->prepare($sql);
        $stmt->execute($studentIds);
        if (count($stmt->fetchAll(PDO::FETCH_COLUMN)) !== count($studentIds)) {
            throw new RuntimeException('تتضمن العملية طالبًا مؤرشفًا أو غير نشط؛ لم يتم حفظ أي بيانات.');
        }
    }
}
