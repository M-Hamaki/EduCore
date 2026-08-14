<?php

declare(strict_types=1);

namespace EduCore\Modules\Attendance\Contracts;

use DateTimeImmutable;

/**
 * Attendance-owned write boundary for rebuildable report projections.
 *
 * Every ForUpdate method is called inside AttendanceTransactionManager by the
 * projector. Aggregates are never updated in place: a changed source retires
 * the current projection and inserts its successor.
 */
interface AttendanceReportProjectionRepository
{
    /** @return array<string,mixed>|null */
    public function projectionRunByIdempotencyForUpdate(string $idempotencyKey): ?array;

    /** @param array<string,mixed> $run */
    public function insertProjectionRun(array $run): int;

    public function startProjectionRun(int $runId, DateTimeImmutable $startedAt): bool;

    /** @param array<string,mixed> $summary */
    public function completeProjectionRun(int $runId, DateTimeImmutable $finishedAt, array $summary): bool;

    /** @return array<string,mixed>|null */
    public function currentAggregateForUpdate(string $aggregateKey): ?array;

    public function retireCurrentAggregate(int $aggregateId): bool;

    /** @param array<string,mixed> $aggregate */
    public function insertAggregate(array $aggregate): int;
}
