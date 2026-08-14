<?php

declare(strict_types=1);

namespace EduCore\Modules\Finance\Infrastructure\Pdo;

use EduCore\Modules\Finance\Contracts\Repositories\ReceiptRepository;
use EduCore\Modules\Finance\Contracts\Repositories\ReceiptNumberSequenceRepository;
use PDO;

final class PdoReceiptRepository implements ReceiptRepository, ReceiptNumberSequenceRepository
{
    public function __construct(private PDO $db)
    {
    }

    public function allocateSequenceNumber(int $cashboxId, int $academicYearId): int
    {
        // Upsert the sequence row, then atomically increment under FOR UPDATE.
        $this->db->prepare(
            'INSERT INTO finance_receipt_number_sequences (cashbox_id, academic_year_id, next_sequence)
             VALUES (?, ?, 1)
             ON DUPLICATE KEY UPDATE next_sequence = next_sequence'
        )->execute([$cashboxId, $academicYearId]);

        $stmt = $this->db->prepare(
            'SELECT next_sequence FROM finance_receipt_number_sequences
             WHERE cashbox_id = ? AND academic_year_id = ? FOR UPDATE'
        );
        $stmt->execute([$cashboxId, $academicYearId]);
        $seq = (int) $stmt->fetchColumn();

        $this->db->prepare(
            'UPDATE finance_receipt_number_sequences SET next_sequence = next_sequence + 1
             WHERE cashbox_id = ? AND academic_year_id = ?'
        )->execute([$cashboxId, $academicYearId]);

        return $seq;
    }

    public function create(array $fields): int
    {
        $this->db->prepare(
            'INSERT INTO finance_receipts
                (receipt_number, cashbox_id, academic_year_id, sequence_number, student_account_id,
                 payment_method, gross_amount, currency, idempotency_key, status, reversal_of,
                 approved_by, request_id, created_by, notes)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
        )->execute([
            $fields['receipt_number'],
            $fields['cashbox_id'],
            $fields['academic_year_id'],
            $fields['sequence_number'],
            $fields['student_account_id'],
            $fields['payment_method'],
            $fields['gross_amount'],
            'EGP',
            $fields['idempotency_key'],
            'draft',
            $fields['reversal_of'] ?? null,
            $fields['approved_by'] ?? null,
            $fields['request_id'] ?? $fields['idempotency_key'],
            $fields['created_by'] ?? null,
            $fields['notes'] ?? null,
        ]);
        return (int) $this->db->lastInsertId();
    }

    public function findById(int $id): ?array
    {
        $stmt = $this->db->prepare('SELECT * FROM finance_receipts WHERE id = ? LIMIT 1');
        $stmt->execute([$id]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row ?: null;
    }

    public function lockById(int $id): ?array
    {
        if (!$this->db->inTransaction()) {
            throw new \LogicException('Receipt locks require an active transaction.');
        }
        $stmt = $this->db->prepare('SELECT * FROM finance_receipts WHERE id = ? FOR UPDATE');
        $stmt->execute([$id]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row ?: null;
    }

    public function findByIdempotencyKey(string $key): ?array
    {
        $stmt = $this->db->prepare('SELECT * FROM finance_receipts WHERE idempotency_key = ? LIMIT 1');
        $stmt->execute([$key]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row ?: null;
    }

    public function findByReversalOf(int $receiptId): ?array
    {
        $stmt = $this->db->prepare('SELECT * FROM finance_receipts WHERE reversal_of = ? LIMIT 1');
        $stmt->execute([$receiptId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row ?: null;
    }

    public function hasDependentActivity(int $receiptId): bool
    {
        $stmt = $this->db->prepare(
            'SELECT EXISTS(
                SELECT 1 FROM finance_refunds r
                WHERE r.reversal_of IS NULL AND (
                    r.payment_allocation_id IN (SELECT a.id FROM finance_payment_allocations a WHERE a.receipt_id = ?)
                    OR r.unapplied_credit_id IN (SELECT c.id FROM finance_unapplied_credits c WHERE c.receipt_id = ?)
                )
                UNION ALL
                SELECT 1 FROM finance_unapplied_credit_applications app
                WHERE app.reversal_of IS NULL
                  AND app.unapplied_credit_id IN (SELECT c.id FROM finance_unapplied_credits c WHERE c.receipt_id = ?)
                LIMIT 1
             )'
        );
        $stmt->execute([$receiptId, $receiptId, $receiptId]);
        return (bool) $stmt->fetchColumn();
    }

    public function post(int $receiptId, int $subledgerTransactionId, int $postedBy): void
    {
        $this->db->prepare(
            'UPDATE finance_receipts SET status = ?, subledger_transaction_id = ?, posted_at = NOW(), posted_by = ? WHERE id = ? AND status = ?'
        )->execute(['posted', $subledgerTransactionId, $postedBy, $receiptId, 'draft']);
    }
}
