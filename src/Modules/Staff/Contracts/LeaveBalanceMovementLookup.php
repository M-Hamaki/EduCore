<?php

declare(strict_types=1);

namespace EduCore\Modules\Staff\Contracts;

/**
 * Read/lock boundary for the immutable ledger evidence needed when a later
 * approved leave successor restores a portion of an earlier consumption.
 */
interface LeaveBalanceMovementLookup
{
    /** @return array<string,mixed>|null */
    public function movementByIdempotencyForUpdate(string $idempotencyKey): ?array;

    /** @return array<string,mixed>|null */
    public function accountByIdForUpdate(int $accountId): ?array;

    /** @return list<array<string,mixed>> */
    public function restorationMovementsForSourceForUpdate(int $sourceMovementId): array;
}
