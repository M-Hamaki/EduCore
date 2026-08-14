<?php

declare(strict_types=1);

namespace EduCore\Modules\Staff\Contracts;

/**
 * Transactional persistence boundary for locked leave-balance accounts.
 *
 * Implementations serialize an account with FOR UPDATE and still enforce the
 * optimistic lock version on the cache update. The movement ledger remains
 * append-only; a business correction is always a new reversal movement.
 */
interface LeaveBalanceLedgerRepository
{
    public function transactional(callable $work): mixed;

    /** @return array<string,mixed>|null */
    public function movementByIdempotencyForUpdate(string $idempotencyKey): ?array;

    /** @return array<string,mixed>|null */
    public function movementByIdForUpdate(int $movementId): ?array;

    /** @return array<string,mixed>|null */
    public function reversalForMovementForUpdate(int $movementId): ?array;

    /**
     * @param array{
     *     staff_user_id:int,
     *     leave_type_id:int,
     *     entitlement_period_key:string,
     *     period_from:string,
     *     period_to:string,
     *     negative_balance_limit_units:string
     * } $identity
     * @return array<string,mixed>|null
     */
    public function accountForUpdate(array $identity): ?array;

    /**
     * Create the account if absent, then lock and return it.
     *
     * @param array{
     *     staff_user_id:int,
     *     leave_type_id:int,
     *     entitlement_period_key:string,
     *     period_from:string,
     *     period_to:string,
     *     negative_balance_limit_units:string
     * } $identity
     * @return array<string,mixed>
     */
    public function ensureAccountForUpdate(array $identity): array;

    /** @return array<string,mixed>|null */
    public function accountByIdForUpdate(int $accountId): ?array;

    /** @param array<string,mixed> $account */
    public function updateAccount(array $account, int $expectedLockVersion): bool;

    /** @param array<string,mixed> $movement */
    public function insertMovement(array $movement): int;
}
