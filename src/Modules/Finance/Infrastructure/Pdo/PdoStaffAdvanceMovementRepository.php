<?php

declare(strict_types=1);

namespace EduCore\Modules\Finance\Infrastructure\Pdo;

use EduCore\Modules\Finance\Contracts\Repositories\StaffAdvanceMovementRepository;
use PDO;

final class PdoStaffAdvanceMovementRepository implements StaffAdvanceMovementRepository
{
    public function __construct(private PDO $db) {}
    public function create(array $fields): int
    {
        $this->db->prepare('INSERT INTO staff_advance_movements (staff_advance_id, movement_type, amount, cashbox_id, payroll_run_item_id, reason, status, approved_by, approved_at, subledger_transaction_id, reversal_of, batch_id, request_id, created_by) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)')->execute([$fields['advance_id'], $fields['movement_type'], $fields['amount'], $fields['cashbox_id'] ?? null, $fields['payroll_run_item_id'] ?? null, $fields['reason'] ?? null, $fields['status'] ?? 'posted', $fields['approved_by'] ?? null, isset($fields['approved_by']) ? date('Y-m-d H:i:s') : null, $fields['subledger_transaction_id'] ?? null, $fields['reversal_of'] ?? null, $fields['batch_id'] ?? null, $fields['request_id'], $fields['created_by']]);
        return (int) $this->db->lastInsertId();
    }
    public function lockById(int $movementId): ?array
    {
        if (!$this->db->inTransaction()) {
            throw new \RuntimeException('Advance movement locking requires an active transaction.');
        }
        $stmt = $this->db->prepare('SELECT * FROM staff_advance_movements WHERE id = ? FOR UPDATE');
        $stmt->execute([$movementId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row ?: null;
    }
    public function findByReversalOf(int $movementId): ?array
    {
        $stmt = $this->db->prepare('SELECT * FROM staff_advance_movements WHERE reversal_of = ? LIMIT 1');
        $stmt->execute([$movementId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row ?: null;
    }
    public function findByAdvance(int $advanceId): array
    {
        $stmt = $this->db->prepare('SELECT * FROM staff_advance_movements WHERE staff_advance_id = ? ORDER BY id');
        $stmt->execute([$advanceId]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }
    public function findByRequestId(string $requestId): ?array
    {
        $stmt = $this->db->prepare('SELECT * FROM staff_advance_movements WHERE request_id = ? LIMIT 1');
        $stmt->execute([$requestId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row ?: null;
    }
    public function linkPosting(int $movementId, int $subledgerTransactionId): void
    {
        $stmt = $this->db->prepare('UPDATE staff_advance_movements SET subledger_transaction_id = ? WHERE id = ? AND subledger_transaction_id IS NULL');
        $stmt->execute([$subledgerTransactionId, $movementId]);
        if ($stmt->rowCount() !== 1) {
            throw new \RuntimeException('Advance movement posting link was rejected.');
        }
    }
}
