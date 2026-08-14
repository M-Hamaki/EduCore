<?php

declare(strict_types=1);

namespace EduCore\Modules\Finance\Infrastructure\Pdo;

use EduCore\Modules\Finance\Contracts\Repositories\PaymentAllocationRepository;
use PDO;

final class PdoPaymentAllocationRepository implements PaymentAllocationRepository
{
    public function __construct(private PDO $db) {}
    public function create(int $receiptId, int $installmentId, string $allocatedAmount, int $subledgerTxId, string $idempotencyKey): int
    {
        $this->db->prepare('INSERT INTO finance_payment_allocations (receipt_id, student_charge_installment_id, signed_amount, status, subledger_transaction_id, request_id) VALUES (?, ?, ?, ?, ?, ?)')->execute([$receiptId, $installmentId, $allocatedAmount, 'applied', $subledgerTxId, $idempotencyKey]);
        return (int) $this->db->lastInsertId();
    }
    public function findById(int $id): ?array
    {
        $stmt = $this->db->prepare('SELECT * FROM finance_payment_allocations WHERE id = ? LIMIT 1');
        $stmt->execute([$id]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row ?: null;
    }
    public function lockById(int $id): ?array
    {
        if (!$this->db->inTransaction()) {
            throw new \LogicException('Payment-allocation locks require an active transaction.');
        }
        $stmt = $this->db->prepare('SELECT * FROM finance_payment_allocations WHERE id = ? FOR UPDATE');
        $stmt->execute([$id]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row ?: null;
    }
    public function sumForReceipt(int $receiptId): string
    {
        $stmt = $this->db->prepare('SELECT COALESCE(SUM(signed_amount), 0) FROM finance_payment_allocations WHERE receipt_id = ?');
        $stmt->execute([$receiptId]);
        return (string) $stmt->fetchColumn();
    }
    public function findForReceipt(int $receiptId): array
    {
        $stmt = $this->db->prepare('SELECT * FROM finance_payment_allocations WHERE receipt_id = ? AND reversal_of IS NULL ORDER BY id');
        $stmt->execute([$receiptId]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }
    public function createReversal(int $originalId, int $reversalReceiptId, int $subledgerTxId, string $requestId): int
    {
        $original = $this->findById($originalId);
        if ($original === null || (string) $original['status'] !== 'applied') {
            throw new \RuntimeException('Posted payment allocation not found.');
        }
        $this->db->prepare(
            'INSERT INTO finance_payment_allocations
                (receipt_id, student_charge_installment_id, signed_amount, status, reversal_of, subledger_transaction_id, request_id)
             VALUES (?, ?, ?, ?, ?, ?, ?)'
        )->execute([
            $reversalReceiptId,
            (int) $original['student_charge_installment_id'],
            '-' . ltrim((string) $original['signed_amount'], '+-'),
            'applied',
            $originalId,
            $subledgerTxId,
            $requestId,
        ]);

        return (int) $this->db->lastInsertId();
    }
}
