<?php

declare(strict_types=1);

namespace EduCore\Modules\Attendance\Contracts;

use DateTimeImmutable;

/**
 * Attendance-owned persistence boundary for period closure and its immutable
 * late-change facts. All methods ending in ForUpdate must run inside the
 * caller's Attendance transaction.
 */
interface AttendancePeriodRepository
{
    /** @return list<array<string,mixed>> */
    public function listPeriods(int $limit = 24): array;

    /** @return list<array<string,mixed>> */
    public function listChangeRequests(int $limit = 100): array;

    /** @return array<string,mixed> */
    public function ensurePeriodForUpdate(string $periodKey, string $periodStart, string $periodEnd): array;

    /** @return array<string,mixed>|null */
    public function periodByIdForUpdate(int $periodId): ?array;

    /** @return array<string,mixed>|null */
    public function changeByIdempotencyForUpdate(string $idempotencyKey): ?array;

    /** @return array<string,mixed>|null */
    public function changeByFingerprintForUpdate(int $periodId, string $changeFingerprint): ?array;

    /** @param array<string,mixed> $change */
    public function insertChangeRequest(array $change): int;

    /** @return array<string,mixed>|null */
    public function changeRequestForUpdate(int $changeRequestId): ?array;

    public function hasUnappliedChangeRequestsForPeriodForUpdate(int $periodId): bool;

    public function closePeriod(
        int $periodId,
        int $expectedLockVersion,
        int $actorId,
        DateTimeImmutable $closedAt,
        ?int $lastClosedRunId,
        string $reasonHash
    ): bool;

    public function reopenPeriod(
        int $periodId,
        int $expectedLockVersion,
        int $actorId,
        DateTimeImmutable $reopenedAt
    ): bool;

    /** @param array<string,mixed> $decision */
    public function decideChangeRequest(
        int $changeRequestId,
        int $expectedLockVersion,
        array $decision
    ): bool;

    public function applyChangeRequest(
        int $changeRequestId,
        int $expectedLockVersion,
        int $runId,
        DateTimeImmutable $appliedAt
    ): bool;
}
