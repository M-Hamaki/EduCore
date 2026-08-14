<?php

declare(strict_types=1);

namespace EduCore\Modules\Finance\Contracts\Repositories;

/**
 * Repository contract for payment allocations (receipt -> installment, double-entry linked).
 */
interface PaymentAllocationRepository
{
    /**
     * Create an allocation linking a receipt to an installment.
     *
     * @param int $receiptId
     * @param int $installmentId
     * @param string $allocatedAmount decimal
     * @param int $subledgerTxId
     * @param string $idempotencyKey
     * @return int the allocation id
     */
    public function create(int $receiptId, int $installmentId, string $allocatedAmount, int $subledgerTxId, string $idempotencyKey): int;

    /**
     * Find an allocation by id.
     *
     * @return array|null
     */
    public function findById(int $id): ?array;

    public function lockById(int $id): ?array;

    /**
     * Sum allocations for a receipt.
     *
     * @param int $receiptId
     * @return string decimal sum
     */
    public function sumForReceipt(int $receiptId): string;

    public function findForReceipt(int $receiptId): array;

    public function createReversal(int $originalId, int $reversalReceiptId, int $subledgerTxId, string $requestId): int;
}
