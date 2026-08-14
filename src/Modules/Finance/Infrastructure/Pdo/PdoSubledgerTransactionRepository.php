<?php

declare(strict_types=1);

namespace EduCore\Modules\Finance\Infrastructure\Pdo;

use EduCore\Modules\Finance\Contracts\Repositories\SubledgerTransactionRepository;
use EduCore\Modules\Finance\Domain\SignedMoneyDelta;
use PDO;

final class PdoSubledgerTransactionRepository implements SubledgerTransactionRepository
{
    public function __construct(private PDO $db)
    {
    }

    public function createTransaction(
        int $subledgerAccountId,
        string $sourceType,
        ?int $sourceRefId,
        string $sourceIdempotencyKey,
        ?string $batchId = null,
        ?string $requestId = null,
        int $postedBy = 0
    ): int {
        // Check for existing (idempotency).
        $existing = $this->findByIdempotencyKey($sourceIdempotencyKey);
        if ($existing) {
            return (int) $existing['id'];
        }

        // Insert; catch duplicate key race condition.
        try {
            $this->db->prepare(
                'INSERT INTO finance_subledger_transactions
                    (subledger_account_id, source_type, source_ref_id, source_idempotency_key, status, batch_id, request_id, posted_by)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?)'
            )->execute([
                $subledgerAccountId, $sourceType, $sourceRefId, $sourceIdempotencyKey,
                'draft', $batchId, $requestId, $postedBy
            ]);
            return (int) $this->db->lastInsertId();
        } catch (\PDOException $e) {
            // Race condition or duplicate key: another request inserted first. Fetch and return it.
            $errorInfo = $e->errorInfo ?? [];
            if ((int) ($errorInfo[1] ?? 0) === 1062) {
                $existing = $this->findByIdempotencyKey($sourceIdempotencyKey);
                if ($existing) {
                    return (int) $existing['id'];
                }
            }
            throw $e;
        }
    }

    public function findByIdempotencyKey(string $sourceIdempotencyKey): ?array
    {
        $stmt = $this->db->prepare(
            'SELECT id, subledger_account_id, source_type, source_ref_id, source_idempotency_key, status, reversal_of, batch_id
             FROM finance_subledger_transactions
             WHERE source_idempotency_key = ? LIMIT 1'
        );
        $stmt->execute([$sourceIdempotencyKey]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row ?: null;
    }

    public function addLine(
        int $transactionId,
        int $lineNumber,
        string $bucketCode,
        SignedMoneyDelta $amountDelta,
        ?string $description = null,
        ?int $installmentId = null,
        ?int $costCenterId = null
    ): void {
        $this->db->prepare(
            'INSERT INTO finance_subledger_lines
                (transaction_id, line_number, bucket_code, amount_delta, description, installment_id, cost_center_id)
             VALUES (?, ?, ?, ?, ?, ?, ?)'
        )->execute([
            $transactionId, $lineNumber, $bucketCode,
            $amountDelta->toDatabaseString(), $description, $installmentId, $costCenterId
        ]);
    }

    public function post(int $transactionId, int $postedBy): void
    {
        $this->db->prepare(
            'UPDATE finance_subledger_transactions SET status = ?, posted_at = NOW(), posted_by = ? WHERE id = ?'
        )->execute(['posted', $postedBy, $transactionId]);
    }

    public function createReversal(
        int $originalTransactionId,
        string $reversalIdempotencyKey,
        int $reversedBy
    ): int {
        // Fetch original to copy account linkage.
        $stmt = $this->db->prepare(
            'SELECT subledger_account_id, source_type, source_ref_id, status
             FROM finance_subledger_transactions WHERE id = ? FOR UPDATE'
        );
        $stmt->execute([$originalTransactionId]);
        $original = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$original) {
            throw new \RuntimeException('العملية الأصلية غير موجودة للعكس: ' . $originalTransactionId);
        }
        if ((string) $original['status'] !== 'posted') {
            throw new \RuntimeException('Only a posted sub-ledger transaction can be reversed.');
        }

        $existingReversal = $this->db->prepare(
            'SELECT id FROM finance_subledger_transactions WHERE reversal_of = ? LIMIT 1'
        );
        $existingReversal->execute([$originalTransactionId]);
        if ($existingReversal->fetchColumn() !== false) {
            throw new \RuntimeException('The sub-ledger transaction has already been reversed.');
        }

        $this->db->prepare(
            'INSERT INTO finance_subledger_transactions
                (subledger_account_id, source_type, source_ref_id, source_idempotency_key, status, reversal_of, posted_by)
             VALUES (?, ?, ?, ?, ?, ?, ?)'
        )->execute([
            (int) $original['subledger_account_id'],
            'reversal',
            null,
            $reversalIdempotencyKey,
            'draft',
            $originalTransactionId,
            $reversedBy,
        ]);

        return (int) $this->db->lastInsertId();
    }

    public function bucketBalance(int $subledgerAccountId, string $bucketCode): string
    {
        $stmt = $this->db->prepare(
            'SELECT COALESCE(SUM(sl.amount_delta), 0) AS balance
             FROM finance_subledger_lines sl
             JOIN finance_subledger_transactions st ON st.id = sl.transaction_id
             WHERE st.subledger_account_id = ?
               AND sl.bucket_code = ?
               AND st.status = ?'
        );
        $stmt->execute([$subledgerAccountId, $bucketCode, 'posted']);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return (string) ($row['balance'] ?? '0.00');
    }

    public function isReversed(int $transactionId): bool
    {
        $stmt = $this->db->prepare('SELECT EXISTS(SELECT 1 FROM finance_subledger_transactions WHERE reversal_of = ? AND status = ?)');
        $stmt->execute([$transactionId, 'posted']);
        return (bool) $stmt->fetchColumn();
    }
}
