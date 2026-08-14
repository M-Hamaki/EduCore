<?php

declare(strict_types=1);

class AcademicYearWriteGuard
{
    protected PDO $db;

    public function __construct(PDO $db)
    {
        $this->db = $db;
    }

    public function assertWritable(int $academicYearId): array
    {
        if ($academicYearId <= 0) {
            throw new InvalidArgumentException('العام الدراسي غير صالح.');
        }

        $sql = 'SELECT id, name, is_active, locked, status FROM academic_years WHERE id = ? LIMIT 1';
        if ($this->db->inTransaction()) {
            $sql .= ' FOR UPDATE';
        }
        $stmt = $this->db->prepare($sql);
        $stmt->execute([$academicYearId]);
        $year = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$year || (string) ($year['status'] ?? '') !== 'active') {
            throw new RuntimeException('العام الدراسي غير موجود أو غير متاح.');
        }
        if ((int) ($year['locked'] ?? 0) === 1) {
            throw new RuntimeException('العام الدراسي مقفل تاريخيًا ولا يقبل تعديلات جديدة.');
        }

        return $year;
    }
}
