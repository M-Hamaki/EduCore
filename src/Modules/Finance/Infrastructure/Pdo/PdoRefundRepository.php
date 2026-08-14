<?php

declare(strict_types=1);

namespace EduCore\Modules\Finance\Infrastructure\Pdo;

use EduCore\Modules\Finance\Contracts\Repositories\RefundRepository;
use PDO;

final class PdoRefundRepository implements RefundRepository
{
    public function __construct(private PDO $db) {}
    public function create(array $fields): int
    {
        $this->db->prepare('INSERT INTO finance_refunds (receipt_id, refund_type, payment_allocation_id, unapplied_credit_id, signed_amount, payment_method, reason, status, posted_at, posted_by, approved_by, reversal_of, subledger_transaction_id, request_id) VALUES (?, ?, ?, ?, ?, ?, ?, ?, NOW(), ?, ?, ?, ?, ?)')->execute([$fields['receipt_id'], $fields['refund_type'], $fields['payment_allocation_id'] ?? null, $fields['unapplied_credit_id'] ?? null, $fields['signed_amount'], $fields['payment_method'], $fields['reason'] ?? null, 'posted', $fields['posted_by'], $fields['approved_by'], $fields['reversal_of'] ?? null, $fields['subledger_transaction_id'], $fields['request_id']]);
        return (int) $this->db->lastInsertId();
    }
    public function lockById(int $id): ?array
    {
        if (!$this->db->inTransaction()) {
            throw new \LogicException('Refund locks require an active transaction.');
        }
        $stmt = $this->db->prepare('SELECT * FROM finance_refunds WHERE id = ? FOR UPDATE');
        $stmt->execute([$id]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row ?: null;
    }
    public function findByRequestId(string $requestId): ?array
    {
        $stmt = $this->db->prepare('SELECT * FROM finance_refunds WHERE request_id = ? LIMIT 1');
        $stmt->execute([$requestId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row ?: null;
    }
    public function findByReversalOf(int $refundId): ?array
    {
        $stmt = $this->db->prepare('SELECT * FROM finance_refunds WHERE reversal_of = ? LIMIT 1');
        $stmt->execute([$refundId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row ?: null;
    }
    public function sumForAllocation(int $allocationId): string
    {
        $stmt = $this->db->prepare('SELECT COALESCE(SUM(-signed_amount), 0) FROM finance_refunds WHERE payment_allocation_id = ? AND status = ?');
        $stmt->execute([$allocationId, 'posted']);
        return (string) $stmt->fetchColumn();
    }
}
