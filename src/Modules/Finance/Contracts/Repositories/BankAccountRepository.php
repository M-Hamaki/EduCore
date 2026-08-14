<?php

declare(strict_types=1);

namespace EduCore\Modules\Finance\Contracts\Repositories;

/**
 * Repository contract for bank accounts linked to cashboxes.
 */
interface BankAccountRepository
{
    /**
     * Find the bank account linked to a cashbox.
     *
     * @param int $cashboxId
     * @return array|null
     */
    public function findByCashbox(int $cashboxId): ?array;

    /**
     * Find a bank account by id.
     *
     * @return array|null
     */
    public function findById(int $id): ?array;
}
