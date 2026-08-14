<?php

declare(strict_types=1);

final class FinancePeriodGuard extends AcademicYearWriteGuard
{
    public function assertWritable(int $academicYearId, int $financePeriodId = 0): array
    {
        $year = parent::assertWritable($academicYearId);
        $period = null;
        if ($financePeriodId > 0) {
            $sql = 'SELECT id, academic_year_id, name, status, closed_at, closed_by FROM finance_periods WHERE id = ? LIMIT 1';
            if ($this->db->inTransaction()) {
                $sql .= ' FOR UPDATE';
            }
            $stmt = $this->db->prepare($sql);
            $stmt->execute([$financePeriodId]);
            $period = $stmt->fetch(PDO::FETCH_ASSOC);
            if (!$period || (int) $period['academic_year_id'] !== $academicYearId) {
                throw new RuntimeException('Finance period does not belong to the selected academic year.');
            }
            if ((string) $period['status'] === 'closed') {
                throw new RuntimeException('The finance period is closed.');
            }
        }
        return ['year' => $year, 'period' => $period];
    }
}
