<?php

declare(strict_types=1);

namespace EduCore\Modules\Finance\Contracts\Repositories;

/**
 * Repository contract for control accounts (sub-ledger <-> GL reconciliation).
 */
interface ControlAccountRepository
{
    /**
     * Find the GL control account for a sub-ledger type.
     *
     * @param string $subLedgerType
     * @return array|null
     */
    public function findControlAccount(string $subLedgerType): ?array;

    /**
     * Return the GL balance for an account as a decimal string.
     *
     * @param int $accountId
     * @return string decimal balance
     */
    public function glBalance(int $accountId): string;

    public function isControlAccount(int $accountId): bool;
}
