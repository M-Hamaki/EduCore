<?php

declare(strict_types=1);

namespace EduCore\Modules\Finance\Contracts\Repositories;

/**
 * Repository contract for receipts (reversal-only; per-cashbox/year numbering).
 */
interface ReceiptRepository
{
    /**
     * Allocate a receipt number using the per-cashbox/year sequence (atomic FOR UPDATE).
     *
     * @return int the sequence number
     */
    public function allocateSequenceNumber(int $cashboxId, int $academicYearId): int;

    /**
     * Create a receipt (draft).
     */
    public function create(array $fields): int;

    public function findById(int $id): ?array;

    public function lockById(int $id): ?array;

    public function findByIdempotencyKey(string $key): ?array;

    public function findByReversalOf(int $receiptId): ?array;

    public function hasDependentActivity(int $receiptId): bool;

    public function post(int $receiptId, int $subledgerTransactionId, int $postedBy): void;
}
