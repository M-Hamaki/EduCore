<?php

declare(strict_types=1);

namespace EduCore\Modules\Attendance\Contracts;

use DateTimeImmutable;

/** Attendance-owned persistence boundary for non-official shadow comparisons. */
interface AttendanceShadowRunRepository
{
    /** @return array<string,mixed>|null */
    public function runByIdempotencyForUpdate(string $idempotencyKey): ?array;

    /** @param array<string,mixed> $run */
    public function insertShadowRun(array $run): int;

    public function startShadowRun(int $runId, DateTimeImmutable $startedAt): bool;

    /** @param array<string,mixed> $summary */
    public function completeShadowRun(int $runId, DateTimeImmutable $finishedAt, array $summary): bool;

    /** @return array<string,mixed>|null */
    public function shadowDayBySourceForUpdate(
        int $staffUserId,
        string $workDate,
        string $sourceFingerprint,
        string $engineVersion
    ): ?array;

    public function nextDayVersionNoForUpdate(int $staffUserId, string $workDate): int;

    /** @param array<string,mixed> $day */
    public function insertShadowDay(array $day): int;

    /** @param array<string,mixed> $segment */
    public function appendSegment(array $segment): void;

    /** @param array<string,mixed> $reasonLine */
    public function appendReasonLine(array $reasonLine): void;
}
