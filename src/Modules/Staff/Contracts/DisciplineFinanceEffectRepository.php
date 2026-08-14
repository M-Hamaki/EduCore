<?php

declare(strict_types=1);

namespace EduCore\Modules\Staff\Contracts;

/**
 * Staff-owned persistence contract for discipline-to-Finance fact intents.
 *
 * The adapter owns only `staff_discipline_*` rows. Finance receives data solely
 * through PayrollImpactGateway after a durable Staff claim; no Finance table is
 * visible through this contract.
 */
interface DisciplineFinanceEffectRepository
{
    public function transactional(callable $work): mixed;

    /** @return array<string,mixed>|null */
    public function decisionForUpdate(int $decisionId): ?array;

    /** @return array<string,mixed>|null */
    public function caseForUpdate(int $caseId): ?array;

    /** @return array<string,mixed>|null */
    public function appealForUpdate(int $appealId): ?array;

    /** @return array<string,mixed>|null */
    public function effectByIdentityForUpdate(string $effectKey, string $idempotencyKey): ?array;

    /** @return array<string,mixed>|null */
    public function effectForUpdate(int $effectId): ?array;

    /** @return list<array<string,mixed>> */
    public function applyEffectsForDecisionForUpdate(int $decisionId): array;

    /** @return array<string,mixed>|null */
    public function reversalForEffectForUpdate(int $effectId): ?array;

    /**
     * @return list<int>
     */
    public function dueEffectIdsForDispatch(int $limit, string $dueAt): array;

    /** @param array<string,mixed> $effect */
    public function insertEffect(array $effect): int;

    public function claimEffect(
        int $effectId,
        string $leaseToken,
        string $claimedAt,
        string $leaseExpiresAt
    ): bool;

    public function markEffectAccepted(
        int $effectId,
        string $leaseToken,
        ?string $financeReference,
        string $acceptedAt
    ): bool;

    public function markEffectForRetry(
        int $effectId,
        string $leaseToken,
        string $reasonCode,
        string $nextAttemptAt
    ): bool;

    public function markEffectRejected(
        int $effectId,
        string $leaseToken,
        string $reasonCode
    ): bool;

    public function cancelQueuedApplyEffect(int $effectId, string $reasonCode): bool;
}
