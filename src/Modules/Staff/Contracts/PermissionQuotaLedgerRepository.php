<?php

declare(strict_types=1);

namespace EduCore\Modules\Staff\Contracts;

/**
 * Transactional persistence boundary for Staff permission-quota movements.
 *
 * The repository must lock the idempotency row and the unique monthly account
 * before returning them to the application service.
 */
interface PermissionQuotaLedgerRepository
{
    public function transactional(callable $work): mixed;

    /** @return array<string,mixed>|null */
    public function movementByIdempotencyForUpdate(string $idempotencyKey): ?array;

    /** @return array<string,mixed> */
    public function ensureQuotaAccountForUpdate(
        int $staffUserId,
        int $permissionTypeId,
        string $periodKey
    ): array;

    /**
     * @param array<string,mixed> $account
     */
    public function updateQuotaAccount(array $account, int $expectedLockVersion): bool;

    /** @param array<string,mixed> $movement */
    public function insertMovement(array $movement): int;
}
