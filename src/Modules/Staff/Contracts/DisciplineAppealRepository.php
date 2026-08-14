<?php

declare(strict_types=1);

namespace EduCore\Modules\Staff\Contracts;

/**
 * Persistence boundary for appeal, interim, and reopen records.
 *
 * It never executes an access effect, Finance fact, or external notification.
 * Those owners consume only a final, audited Staff record through their own
 * contracts after the relevant decision is durable.
 */
interface DisciplineAppealRepository
{
    public function transactional(callable $work): mixed;

    public function lockUser(int $userId): bool;

    /** @return array<string,mixed>|null */
    public function caseForUpdate(int $caseId): ?array;

    /** @return array<string,mixed>|null */
    public function decisionForUpdate(int $decisionId): ?array;

    /** @return array<string,mixed>|null */
    public function investigationForUpdate(int $investigationId): ?array;

    /** @return array<string,mixed>|null */
    public function evidenceForUpdate(int $evidenceId): ?array;

    /** @return array<string,mixed>|null */
    public function appealByIdempotencyForUpdate(string $idempotencyKey): ?array;

    /** @return array<string,mixed>|null */
    public function appealForUpdate(int $appealId): ?array;

    /** @return array<string,mixed>|null */
    public function activeAppealForDecisionAndAppellantForUpdate(int $decisionId, int $appellantUserId): ?array;

    /** @param array<string,mixed> $appeal */
    public function insertAppeal(array $appeal): int;

    public function assignAppealReviewer(
        int $appealId,
        int $expectedLockVersion,
        int $reviewerUserId
    ): bool;

    public function resolveAppeal(
        int $appealId,
        int $expectedLockVersion,
        string $outcome,
        string $outcomeReason,
        string $reviewedAt
    ): bool;

    public function withdrawAppeal(int $appealId, int $expectedLockVersion): bool;

    public function expireAppeal(int $appealId, int $expectedLockVersion, string $reviewedAt): bool;

    public function transitionCase(
        int $caseId,
        int $expectedLockVersion,
        string $fromStatus,
        string $toStatus
    ): bool;

    /** @return array<string,mixed>|null */
    public function interimByIdempotencyForUpdate(string $idempotencyKey): ?array;

    /** @return array<string,mixed>|null */
    public function interimForUpdate(int $measureId): ?array;

    /** @param array<string,mixed> $measure */
    public function insertInterim(array $measure): int;

    public function activateInterim(
        int $measureId,
        int $expectedLockVersion,
        int $authorizedByUserId,
        string $authorizedAt
    ): bool;

    public function resolveInterim(
        int $measureId,
        int $expectedLockVersion,
        string $outcome,
        ?int $reviewedByUserId,
        string $reviewedAt,
        ?string $resolutionReason
    ): bool;

    /** @return array<string,mixed>|null */
    public function reopenEventByIdempotencyForUpdate(string $idempotencyKey): ?array;

    /** @return array<string,mixed>|null */
    public function reopenEventForUpdate(int $reopenEventId): ?array;

    /** @return array<string,mixed>|null */
    public function reopenResolutionForRequestForUpdate(int $requestEventId): ?array;

    /** @param array<string,mixed> $event */
    public function insertReopenEvent(array $event): int;
}
