<?php

declare(strict_types=1);

namespace EduCore\Modules\Finance\Contracts\Repositories;

use EduCore\Modules\Finance\Domain\SignedMoneyDelta;

/**
 * Repository contract for sub-ledger transactions and lines (the unified truth source).
 */
interface SubledgerTransactionRepository
{
    /**
     * Create a new sub-ledger transaction (header) with status 'draft'.
     *
     * @param int $subledgerAccountId
     * @param string $sourceType
     * @param int|null $sourceRefId
     * @param string $sourceIdempotencyKey  must be unique; a retry returns the original
     * @param string|null $batchId
     * @param string|null $requestId
     * @param int $postedBy
     * @return int the new transaction id
     */
    public function createTransaction(
        int $subledgerAccountId,
        string $sourceType,
        ?int $sourceRefId,
        string $sourceIdempotencyKey,
        ?string $batchId = null,
        ?string $requestId = null,
        int $postedBy = 0
    ): int;

    /**
     * Find an existing transaction by its idempotency key (for dedup on retry).
     *
     * @return array|null
     */
    public function findByIdempotencyKey(string $sourceIdempotencyKey): ?array;

    /**
     * Add a signed delta line to a transaction.
     *
     * @param int $transactionId
     * @param int $lineNumber
     * @param string $bucketCode
     * @param SignedMoneyDelta $amountDelta
     * @param string|null $description
     * @param int|null $installmentId
     * @param int|null $costCenterId
     */
    public function addLine(
        int $transactionId,
        int $lineNumber,
        string $bucketCode,
        SignedMoneyDelta $amountDelta,
        ?string $description = null,
        ?int $installmentId = null,
        ?int $costCenterId = null
    ): void;

    /**
     * Post a transaction (set status='posted', posted_at, posted_by).
     */
    public function post(int $transactionId, int $postedBy): void;

    /**
     * Create a reversal transaction linked to the original via reversal_of.
     * The reversal carries opposite-sign lines (created by the caller).
     *
     * @param int $originalTransactionId
     * @param string $reversalIdempotencyKey
     * @param int $reversedBy
     * @return int the new reversal transaction id
     */
    public function createReversal(
        int $originalTransactionId,
        string $reversalIdempotencyKey,
        int $reversedBy
    ): int;

    /**
     * Compute the balance for a bucket on a sub-ledger account.
     *
     * @param int $subledgerAccountId
     * @param string $bucketCode
     * @return string decimal string (e.g. "100.50")
     */
    public function bucketBalance(int $subledgerAccountId, string $bucketCode): string;

    public function isReversed(int $transactionId): bool;
}
