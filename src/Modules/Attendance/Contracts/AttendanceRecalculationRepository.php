<?php

declare(strict_types=1);

namespace EduCore\Modules\Attendance\Contracts;

use DateTimeImmutable;

/** Attendance-owned persistence boundary for immutable recalculation runs. */
interface AttendanceRecalculationRepository
{
    /** @return array<string,mixed>|null */
    public function runByIdempotencyForUpdate(string $idempotencyKey): ?array;

    /** @param array<string,mixed> $run */
    public function insertRecalculationRun(array $run): int;

    public function startRecalculationRun(int $runId, DateTimeImmutable $startedAt): bool;

    /** @param array<string,mixed> $summary */
    public function completeRecalculationRun(int $runId, DateTimeImmutable $finishedAt, array $summary): bool;

    /** @return array<string,mixed>|null */
    public function currentOfficialDayForUpdate(int $staffUserId, string $workDate): ?array;

    public function nextDayVersionNoForUpdate(int $staffUserId, string $workDate): int;

    /**
     * Retires only the currently official version. The historical version and
     * its evidence remain immutable and are linked by the successor.
     */
    public function retireOfficialDay(int $dayVersionId): bool;

    /** @param array<string,mixed> $day */
    public function insertRecalculatedDay(array $day): int;

    /** @param array<string,mixed> $segment */
    public function appendSegment(array $segment): void;

    /** @param array<string,mixed> $reasonLine */
    public function appendReasonLine(array $reasonLine): void;

    /** Publishes only after all immutable child evidence has been appended. */
    public function publishRecalculatedDay(
        int $dayVersionId,
        int $actorId,
        DateTimeImmutable $officializedAt
    ): bool;
}
