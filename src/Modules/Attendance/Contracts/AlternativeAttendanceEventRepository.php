<?php

declare(strict_types=1);

namespace EduCore\Modules\Attendance\Contracts;

use DateTimeImmutable;

/** Narrow persistence contract for append-only alternative attendance events. */
interface AlternativeAttendanceEventRepository
{
    /** @param array<string,mixed> $method */
    public function insertAlternativeEntryMethod(array $method): int;

    /** @return list<array<string,mixed>> */
    public function activeAlternativeEntryMethods(): array;

    /** @return list<array<string,mixed>> */
    public function pendingAlternativeEvents(int $limit = 100): array;

    public function retireAlternativeEntryMethod(int $methodId): bool;

    /** @return array<string,mixed>|null */
    public function activeAlternativeEntryMethodForUpdate(int $entryMethodId): ?array;

    /**
     * Includes the method scope/type required to re-check authorization on an
     * idempotent replay. A row belonging to a biometric method is returned as
     * a collision, never exposed as its identity or raw payload.
     *
     * @return array<string,mixed>|null
     */
    public function alternativeEventByIdempotencyForUpdate(string $idempotencyKey): ?array;

    /** @param array<string,mixed> $event */
    public function insertAlternativeEvent(array $event): int;

    /** @return array<string,mixed>|null */
    public function pendingAlternativeEventForReview(int $eventId): ?array;

    public function finalizeAlternativeReview(
        int $eventId,
        string $decision,
        int $reviewerId,
        DateTimeImmutable $reviewedAt
    ): bool;
}
