<?php

declare(strict_types=1);

namespace EduCore\Modules\Finance\Contracts\Repositories;

/**
 * Repository contract for GL journal entries and lines (1:1 with sub-ledger via source_idempotency_key).
 */
interface JournalEntryRepository
{
    public function findByIdempotencyKey(string $key): ?array;

    public function findBySubledgerTransactionId(int $subledgerTransactionId): ?array;

    /** @return list<array<string,mixed>> */
    public function linesForEntry(int $entryId): array;

    public function create(string $entryNumber, ?int $financePeriodId, string $entryDate, string $sourceType, ?int $sourceRefId, string $sourceIdempotencyKey, ?string $batchId, int $postedBy, ?int $subledgerTransactionId = null, ?int $reversalOf = null): int;

    public function addLine(int $entryId, int $accountId, ?int $costCenterId, string $debit, string $credit, ?string $description, ?string $subLedgerRefType, ?int $subLedgerRefId): void;

    public function post(int $entryId, int $postedBy): void;

    /**
     * Verify that SUM(debit) = SUM(credit) for an entry.
     */
    public function isBalanced(int $entryId): bool;
}
