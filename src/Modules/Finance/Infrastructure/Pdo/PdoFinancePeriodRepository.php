<?php

declare(strict_types=1);

namespace EduCore\Modules\Finance\Infrastructure\Pdo;

use EduCore\Modules\Finance\Contracts\Repositories\FinancePeriodRepository;
use PDO;

final class PdoFinancePeriodRepository implements FinancePeriodRepository
{
    public function __construct(private PDO $db) {}

    public function create(int $academicYearId, string $name, ?string $startDate, ?string $endDate): int
    {
        $stmt = $this->db->prepare('INSERT INTO finance_periods (academic_year_id, name, start_date, end_date, status) VALUES (?, ?, ?, ?, ?)');
        $stmt->execute([$academicYearId, $name, $startDate, $endDate, 'open']);
        return (int) $this->db->lastInsertId();
    }

    public function findById(int $id): ?array
    {
        $stmt = $this->db->prepare('SELECT * FROM finance_periods WHERE id = ? LIMIT 1');
        $stmt->execute([$id]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row ?: null;
    }

    public function lockById(int $id): ?array
    {
        $stmt = $this->db->prepare('SELECT * FROM finance_periods WHERE id = ? FOR UPDATE');
        $stmt->execute([$id]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row ?: null;
    }

    public function close(int $id, int $closedBy, int $approvedBy): void
    {
        $this->db->prepare('UPDATE finance_periods SET status = ?, closed_at = NOW(), closed_by = ?, closed_approved_by = ? WHERE id = ?')->execute(['closed', $closedBy, $approvedBy, $id]);
    }

    public function reopen(int $id, string $reason, int $reopenedBy, int $approvedBy): void
    {
        $this->db->prepare('UPDATE finance_periods SET status = ?, reopen_reason = ?, reopened_by = ?, reopen_approved_by = ?, reopened_at = NOW() WHERE id = ?')->execute(['reopened', $reason, $reopenedBy, $approvedBy, $id]);
    }
}
