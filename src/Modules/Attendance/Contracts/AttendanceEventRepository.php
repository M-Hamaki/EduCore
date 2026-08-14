<?php

declare(strict_types=1);

namespace EduCore\Modules\Attendance\Contracts;

use DateTimeImmutable;

/** Attendance-owned persistence boundary for append-only biometric evidence. */
interface AttendanceEventRepository
{
    /** @return array<string,mixed>|null */
    public function activeBiometricEntryMethod(int $entryMethodId): ?array;

    /** @return list<array<string,mixed>> */
    public function batchesForUpdate(string $idempotencyKey, ?string $fileFingerprint): array;

    /** @param array<string,mixed> $batch */
    public function insertBatch(array $batch): int;

    /** @param array<string,mixed> $result */
    public function finishBatch(
        int $batchId,
        string $status,
        DateTimeImmutable $finishedAt,
        array $result
    ): void;

    /**
     * Return every row that would collide with one of the immutable event keys.
     * More than one distinct row is a corruption/conflict and must fail closed.
     *
     * @return list<array<string,mixed>>
     */
    public function duplicateEventsForUpdate(
        int $deviceId,
        string $idempotencyKey,
        ?string $externalEventKey,
        string $rawHash
    ): array;

    /** @param array<string,mixed> $event */
    public function insertEvent(array $event): int;
}

