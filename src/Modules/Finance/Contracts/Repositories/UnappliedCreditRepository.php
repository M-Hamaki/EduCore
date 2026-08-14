<?php

declare(strict_types=1);

namespace EduCore\Modules\Finance\Contracts\Repositories;

/**
 * Repository contract for unapplied credits (over-payments / advances held on account).
 */
interface UnappliedCreditRepository
{
    /**
     * Create an unapplied credit on a student account.
     *
     * @param int $studentAccountId
     * @param int $receiptId
     * @param string $amount decimal
     * @param int $subledgerTxId
     * @param string $idempotencyKey
     * @return int the credit id
     */
    public function create(int $studentAccountId, int $receiptId, string $amount, int $subledgerTxId, string $idempotencyKey): int;

    /**
     * Find an unapplied credit by id.
     *
     * @return array|null
     */
    public function findById(int $id): ?array;

    /**
     * Remaining balance for a credit (gross - applications).
     *
     * @param int $creditId
     * @return string decimal amount
     */
    public function remaining(int $creditId): string;

    public function lockRemaining(int $creditId): string;

    public function findForReceipt(int $receiptId): array;

    public function createReversal(int $originalId, int $reversalReceiptId, int $subledgerTxId, string $requestId): int;
}
