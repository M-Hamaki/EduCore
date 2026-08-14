<?php

declare(strict_types=1);

namespace EduCore\Modules\Finance\Infrastructure\Pdo;

use EduCore\Modules\Finance\Contracts\Repositories\StaffAdvanceRepository;
use PDO;

final class PdoStaffAdvanceRepository implements StaffAdvanceRepository
{
    public function __construct(private PDO $db) {}
    public function create(int $staffId, string $amount, string $issueDate, string $reason, int $createdBy, string $requestId): int
    {
        $this->db->prepare('INSERT INTO staff_advances (staff_id, amount, issue_date, reason, status, created_by, request_id) VALUES (?, ?, ?, ?, ?, ?, ?)')->execute([$staffId, $amount, $issueDate, $reason, 'active', $createdBy, $requestId]);
        return (int) $this->db->lastInsertId();
    }
    public function findById(int $id): ?array
    {
        $stmt = $this->db->prepare('SELECT * FROM staff_advances WHERE id = ? LIMIT 1 FOR UPDATE');
        $stmt->execute([$id]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row ?: null;
    }
    public function findByRequestId(string $requestId): ?array
    {
        $stmt = $this->db->prepare('SELECT * FROM staff_advances WHERE request_id = ? LIMIT 1');
        $stmt->execute([$requestId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row ?: null;
    }
    public function linkPosting(int $advanceId, int $subledgerTransactionId): void
    {
        $this->db->prepare('UPDATE staff_advances SET subledger_transaction_id = ? WHERE id = ? AND subledger_transaction_id IS NULL')->execute([$subledgerTransactionId, $advanceId]);
    }
    public function remaining(int $advanceId): string
    {
        $stmt = $this->db->prepare("SELECT sa.amount - COALESCE(SUM(CASE WHEN sam.status = 'posted' AND sam.reversal_of IS NULL THEN sam.amount WHEN sam.status = 'posted' AND sam.reversal_of IS NOT NULL THEN -sam.amount ELSE 0 END), 0) FROM staff_advances sa LEFT JOIN staff_advance_movements sam ON sam.staff_advance_id = sa.id WHERE sa.id = ? GROUP BY sa.id, sa.amount");
        $stmt->execute([$advanceId]);
        return (string) ($stmt->fetchColumn() ?: '0.00');
    }
    public function updateStatus(int $advanceId, string $status): void
    {
        $this->db->prepare('UPDATE staff_advances SET status = ? WHERE id = ?')->execute([$status, $advanceId]);
    }
    public function addInstallment(int $advanceId, string $dueDate, string $amount): int
    {
        $this->db->prepare('INSERT INTO staff_advance_installments (staff_advance_id, due_date, amount, status) VALUES (?, ?, ?, ?)')->execute([$advanceId, $dueDate, $amount, 'pending']);
        return (int) $this->db->lastInsertId();
    }
}
