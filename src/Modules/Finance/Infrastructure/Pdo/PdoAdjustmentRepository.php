<?php

declare(strict_types=1);

namespace EduCore\Modules\Finance\Infrastructure\Pdo;

use EduCore\Modules\Finance\Contracts\Repositories\AdjustmentRepository;
use PDO;

final class PdoAdjustmentRepository implements AdjustmentRepository
{
    public function __construct(private PDO $db) {}
    public function create(array $fields): int
    {
        $this->db->prepare('INSERT INTO finance_adjustments (student_account_id, adjustment_type, signed_amount, reason, source, status, posted_at, posted_by, approved_by, reversal_of, subledger_transaction_id, request_id) VALUES (?, ?, ?, ?, ?, ?, NOW(), ?, ?, ?, ?, ?)')->execute([$fields['student_account_id'], $fields['adjustment_type'], $fields['signed_amount'], $fields['reason'], $fields['source'], 'posted', $fields['posted_by'], $fields['approved_by'], $fields['reversal_of'] ?? null, $fields['subledger_transaction_id'], $fields['request_id']]);
        return (int) $this->db->lastInsertId();
    }
    public function lockById(int $id): ?array
    {
        if (!$this->db->inTransaction()) {
            throw new \LogicException('Adjustment locks require an active transaction.');
        }
        $stmt = $this->db->prepare('SELECT * FROM finance_adjustments WHERE id = ? FOR UPDATE');
        $stmt->execute([$id]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row ?: null;
    }
    public function findByRequestId(string $requestId): ?array
    {
        $stmt = $this->db->prepare('SELECT * FROM finance_adjustments WHERE request_id = ? LIMIT 1');
        $stmt->execute([$requestId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row ?: null;
    }
    public function findByReversalOf(int $adjustmentId): ?array
    {
        $stmt = $this->db->prepare('SELECT * FROM finance_adjustments WHERE reversal_of = ? LIMIT 1');
        $stmt->execute([$adjustmentId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row ?: null;
    }
}
