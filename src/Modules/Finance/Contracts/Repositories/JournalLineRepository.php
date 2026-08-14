<?php

declare(strict_types=1);

namespace EduCore\Modules\Finance\Contracts\Repositories;

/**
 * Repository contract for GL journal lines (1:1 with sub-ledger via source_idempotency_key).
 */
interface JournalLineRepository
{
    /**
     * Return all lines for a journal entry.
     *
     * @param int $entryId
     * @return array
     */
    public function findByEntry(int $entryId): array;

    /**
     * Sum the debit column for a journal entry.
     *
     * @param int $entryId
     * @return string decimal sum
     */
    public function sumDebit(int $entryId): string;

    /**
     * Sum the credit column for a journal entry.
     *
     * @param int $entryId
     * @return string decimal sum
     */
    public function sumCredit(int $entryId): string;
}
