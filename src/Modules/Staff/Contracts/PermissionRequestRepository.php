<?php

declare(strict_types=1);

namespace EduCore\Modules\Staff\Contracts;

use DateTimeImmutable;

/**
 * Transactional Staff-owned persistence boundary for permission requests.
 *
 * The implementation must use the same PDO transaction as the quota ledger
 * when the two are composed together, so request status, monthly allocation,
 * reservation, and audit either all commit or all roll back.
 */
interface PermissionRequestRepository
{
    public function transactional(callable $work): mixed;

    /** Serializes competing permission submissions for one staff member. */
    public function lockStaffForRequest(int $staffUserId): bool;

    /** @return array<string,mixed>|null */
    public function requestByCreateIdempotencyForUpdate(string $idempotencyKey): ?array;

    /** @return array<string,mixed>|null */
    public function requestBySubmissionIdempotencyForUpdate(string $idempotencyKey): ?array;

    /** @return array<string,mixed>|null */
    public function requestForUpdate(int $requestId): ?array;

    /** @param array<string,mixed> $request */
    public function insertDraft(array $request): int;

    /** @param array<string,mixed> $changes */
    public function updateDraft(int $requestId, int $expectedLockVersion, array $changes): bool;

    /**
     * Replaces the draft-only monthly allocation and returns persisted rows
     * with their generated IDs.
     *
     * @param list<array<string,mixed>> $periods
     * @return list<array<string,mixed>>
     */
    public function replaceDraftPeriods(int $requestId, array $periods): array;

    /** @return list<array<string,mixed>> */
    public function periodsForRequestForUpdate(int $requestId): array;

    /** @param array<string,mixed> $submission */
    public function submitDraft(int $requestId, int $expectedLockVersion, array $submission): bool;

    /**
     * Attaches the durable approval instance created during the same submission
     * transaction. The immutable link may only be written once.
     */
    public function attachWorkflowInstance(int $requestId, int $expectedLockVersion, int $workflowInstanceId): bool;

    /**
     * Applies the terminal state owned by the approval workflow.
     *
     * @param 'approved'|'rejected' $outcome
     */
    public function finalizeWorkflowOutcome(
        int $requestId,
        int $expectedLockVersion,
        string $outcome,
        DateTimeImmutable $decidedAt
    ): bool;

    /** Marks the one-way actual quota exception after reservation succeeds. */
    public function markQuotaException(int $requestId, int $expectedLockVersion, string $reason): bool;

    public function withdrawDraft(int $requestId, int $expectedLockVersion): bool;

    public function cancelPendingRequest(int $requestId, int $expectedLockVersion): bool;

    /** @return list<array<string,mixed>> */
    public function pendingRequestsForStaffForUpdate(int $staffUserId): array;

    public function cancelPendingRequestDueToServiceEnd(int $requestId, int $expectedLockVersion): bool;
}
