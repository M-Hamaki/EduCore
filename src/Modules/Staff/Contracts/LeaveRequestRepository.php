<?php

declare(strict_types=1);

namespace EduCore\Modules\Staff\Contracts;

use DateTimeImmutable;

/**
 * Transactional Staff-owned persistence boundary for leave requests.
 *
 * Draft allocations are replaceable only while the request is a draft. The
 * implementation must share its PDO transaction with the leave ledger and
 * approval-instance gateway when a request is submitted.
 */
interface LeaveRequestRepository
{
    public function transactional(callable $work): mixed;

    /** Serializes competing leave submissions for one worker. */
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
     * Replaces draft-only calculated allocations and returns generated rows.
     *
     * @param list<array<string,mixed>> $days
     * @return list<array<string,mixed>>
     */
    public function replaceDraftDays(int $requestId, array $days): array;

    /** @return list<array<string,mixed>> */
    public function daysForRequestForUpdate(int $requestId): array;

    /** @param array<string,mixed> $submission */
    public function submitDraft(int $requestId, int $expectedLockVersion, array $submission): bool;

    /** Links the durable approval instance exactly once after submission. */
    public function attachWorkflowInstance(int $requestId, int $expectedLockVersion, int $workflowInstanceId): bool;

    public function withdrawDraft(int $requestId, int $expectedLockVersion): bool;

    /**
     * Applies a final outcome from the approval owner to a pending request.
     *
     * @param 'approved'|'rejected' $outcome
     */
    public function finalizeWorkflowOutcome(
        int $requestId,
        int $expectedLockVersion,
        string $outcome,
        DateTimeImmutable $decidedAt
    ): bool;
}
