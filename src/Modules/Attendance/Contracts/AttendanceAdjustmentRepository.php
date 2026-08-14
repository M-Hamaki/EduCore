<?php

declare(strict_types=1);

namespace EduCore\Modules\Attendance\Contracts;

use DateTimeImmutable;

/** Attendance-owned persistence contract for versioned day corrections. */
interface AttendanceAdjustmentRepository
{
    /** @return list<array<string,mixed>> */
    public function adjustmentsForRequester(int $requesterId, int $limit): array;

    /** @return list<array<string,mixed>> */
    public function pendingAdjustments(int $limit): array;

    /** @return array<string,mixed>|null */
    public function currentOfficialDayForUpdate(int $staffUserId, string $workDate): ?array;

    /** @return array<string,mixed>|null */
    public function adjustmentByIdempotencyForUpdate(string $idempotencyKey): ?array;

    /** @return array<string,mixed>|null */
    public function adjustmentForUpdate(int $adjustmentId): ?array;

    /** @param array<string,mixed> $adjustment */
    public function insertAdjustment(array $adjustment): int;

    public function submitAdjustment(
        int $adjustmentId,
        int $expectedLockVersion,
        ?int $workflowInstanceId,
        DateTimeImmutable $submittedAt
    ): bool;

    public function cancelAdjustment(
        int $adjustmentId,
        int $expectedLockVersion,
        string $resolutionComment,
        DateTimeImmutable $cancelledAt
    ): bool;

    public function finalizeAdjustment(
        int $adjustmentId,
        int $expectedLockVersion,
        string $status,
        ?int $approvedVersionId,
        ?string $resolutionComment
    ): bool;

    /** @param array<string,mixed> $run */
    public function insertRecalculationRun(array $run): int;

    public function startRun(int $runId, DateTimeImmutable $startedAt): bool;

    /** @param array<string,mixed> $summary */
    public function completeRun(int $runId, DateTimeImmutable $finishedAt, array $summary): bool;

    public function nextDayVersionNoForUpdate(int $staffUserId, string $workDate): int;

    /** @param array<string,mixed> $day */
    public function insertDayVersion(array $day): int;

    public function copySegments(int $sourceDayVersionId, int $targetDayVersionId): void;

    public function copyReasonLines(int $sourceDayVersionId, int $targetDayVersionId): int;

    /** @param array<string,mixed> $reasonLine */
    public function appendReasonLine(array $reasonLine): void;

    public function demoteOfficialDay(int $dayVersionId): bool;

    public function publishDayVersion(
        int $dayVersionId,
        int $actorId,
        DateTimeImmutable $officializedAt
    ): bool;
}
