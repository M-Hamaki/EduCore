<?php

declare(strict_types=1);

namespace EduCore\Modules\Finance\Infrastructure\Pdo;

use EduCore\Modules\Finance\Contracts\Repositories\UnappliedCreditApplicationRepository;
use EduCore\Modules\Finance\Contracts\Repositories\UnappliedCreditRepository;
use PDO;

/**
 * PDO implementation for unapplied credits and their applications.
 */
final class PdoUnappliedCreditRepository implements UnappliedCreditRepository, UnappliedCreditApplicationRepository
{
    public function __construct(private PDO $db)
    {
    }

    public function create(int $studentAccountId, int $receiptId, string $amount, int $subledgerTxId, string $idempotencyKey): int
    {
        $this->db->prepare(
            'INSERT INTO finance_unapplied_credits
                (student_account_id, receipt_id, signed_amount, status, subledger_transaction_id, request_id)
             VALUES (?, ?, ?, ?, ?, ?)'
        )->execute([$studentAccountId, $receiptId, $amount, 'open', $subledgerTxId, $idempotencyKey]);
        return (int) $this->db->lastInsertId();
    }

    public function findById(int $id): ?array
    {
        $stmt = $this->db->prepare('SELECT * FROM finance_unapplied_credits WHERE id = ?');
        $stmt->execute([$id]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row ?: null;
    }

    public function remaining(int $creditId): string
    {
        $stmt = $this->db->prepare(
            'SELECT uc.signed_amount + COALESCE(
                (SELECT SUM(reverse_uc.signed_amount) FROM finance_unapplied_credits reverse_uc
                 WHERE reverse_uc.reversal_of = uc.id), 0
             ) - COALESCE(
                (SELECT SUM(uca.applied_amount) FROM finance_unapplied_credit_applications uca
                 WHERE uca.unapplied_credit_id = uc.id AND uca.status = ?), 0
             ) + COALESCE(
                (SELECT SUM(r.signed_amount) FROM finance_refunds r
                 WHERE r.unapplied_credit_id = uc.id AND r.status = ?), 0
             ) AS remaining
             FROM finance_unapplied_credits uc WHERE uc.id = ?'
        );
        $stmt->execute(['applied', 'posted', $creditId]);
        return (string) $stmt->fetchColumn();
    }

    public function lockRemaining(int $creditId): string
    {
        if (!$this->db->inTransaction()) {
            throw new \LogicException('Unapplied-credit locks require an active transaction.');
        }
        $stmt = $this->db->prepare('SELECT id FROM finance_unapplied_credits WHERE id = ? FOR UPDATE');
        $stmt->execute([$creditId]);
        if ($stmt->fetchColumn() === false) {
            throw new \RuntimeException('Unapplied credit was not found.');
        }
        return $this->remaining($creditId);
    }

    public function findForReceipt(int $receiptId): array
    {
        $stmt = $this->db->prepare('SELECT * FROM finance_unapplied_credits WHERE receipt_id = ? AND reversal_of IS NULL ORDER BY id');
        $stmt->execute([$receiptId]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    public function createReversal(int $originalId, int $reversalReceiptId, int $subledgerTxId, string $requestId): int
    {
        $original = $this->findById($originalId);
        if ($original === null || (string) $original['status'] !== 'open') {
            throw new \RuntimeException('Open unapplied credit not found for reversal.');
        }
        $this->db->prepare(
            'INSERT INTO finance_unapplied_credits
                (student_account_id, receipt_id, signed_amount, status, reversal_of, subledger_transaction_id, request_id)
             VALUES (?, ?, ?, ?, ?, ?, ?)'
        )->execute([
            (int) $original['student_account_id'],
            $reversalReceiptId,
            '-' . ltrim((string) $original['signed_amount'], '+-'),
            'reversed',
            $originalId,
            $subledgerTxId,
            $requestId,
        ]);
        return (int) $this->db->lastInsertId();
    }

    public function createApplication(int $creditId, int $installmentId, ?int $allocationId, string $appliedAmount, int $subledgerTxId, string $idempotencyKey): int
    {
        $this->db->prepare(
            'INSERT INTO finance_unapplied_credit_applications
                (unapplied_credit_id, student_charge_installment_id, payment_allocation_id,
                 applied_amount, status, subledger_transaction_id, request_id)
             VALUES (?, ?, ?, ?, ?, ?, ?)'
        )->execute([$creditId, $installmentId, $allocationId, $appliedAmount, 'applied', $subledgerTxId, $idempotencyKey]);
        return (int) $this->db->lastInsertId();
    }

    public function sumForCredit(int $creditId): string
    {
        $stmt = $this->db->prepare('SELECT COALESCE(SUM(applied_amount), 0) FROM finance_unapplied_credit_applications WHERE unapplied_credit_id = ? AND status = ?');
        $stmt->execute([$creditId, 'applied']);
        return (string) $stmt->fetchColumn();
    }

    public function findByRequestId(string $requestId): ?array
    {
        $stmt = $this->db->prepare('SELECT * FROM finance_unapplied_credit_applications WHERE request_id = ? LIMIT 1');
        $stmt->execute([$requestId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row ?: null;
    }
}
