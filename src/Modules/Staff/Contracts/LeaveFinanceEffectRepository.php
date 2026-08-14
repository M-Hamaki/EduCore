<?php

declare(strict_types=1);

namespace EduCore\Modules\Staff\Contracts;

/**
 * Staff-owned persistence boundary for Finance fact intents.
 *
 * It stores no salary amount and never touches Finance-owned tables. The
 * effect record is an idempotent outbox fact that Finance may interpret and
 * post according to its own payroll and closed-period policy.
 */
interface LeaveFinanceEffectRepository
{
    public function transactional(callable $operation): mixed;

    /** @return array<string,mixed>|null */
    public function requestForUpdate(int $requestId): ?array;

    /** @return list<array<string,mixed>> */
    public function requestDaysForUpdate(int $requestId): array;

    /** @return array<string,mixed>|null */
    public function effectByIdentityForUpdate(string $effectKey, string $idempotencyKey): ?array;

    /** @return array<string,mixed>|null */
    public function effectForUpdate(int $effectId): ?array;

    /**
     * Returns only due Staff leave facts for the Finance dispatcher. Claiming
     * remains a separate conditional operation so competing workers cannot
     * both submit one effect.
     *
     * @return list<int>
     */
    public function dueEffectIdsForDispatch(int $limit, string $dueAt): array;

    /** @param array<string,mixed> $effect */
    public function insertEffect(array $effect): int;

    public function claimEffect(int $effectId, string $claimedAt, string $leaseUntil): bool;

    public function markEffectAccepted(int $effectId, ?string $financeReference, string $completedAt): bool;

    public function markEffectForRetry(int $effectId, string $reasonCode, string $nextAttemptAt): bool;
}
