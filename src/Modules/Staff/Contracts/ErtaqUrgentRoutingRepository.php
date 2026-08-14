<?php

declare(strict_types=1);

namespace EduCore\Modules\Staff\Contracts;

/**
 * Staff-owned persistence boundary for urgent Ertaq route evidence. The
 * adapter does not send notifications or change an external protection,
 * disciplinary, access, or Finance system.
 */
interface ErtaqUrgentRoutingRepository
{
    public function transactional(callable $work): mixed;

    /** @return array<string,mixed>|null */
    public function ticketForUpdate(int $ticketId): ?array;

    /** @return list<int> */
    public function protectedPartyUserIdsForTicketForUpdate(int $ticketId): array;

    /** @return array<string,mixed>|null */
    public function urgentByIdempotencyForUpdate(string $idempotencyKey): ?array;

    /** @return array<string,mixed>|null */
    public function urgentForTicketForUpdate(int $ticketId): ?array;

    /** @return array<string,mixed>|null */
    public function urgentEventForUpdate(int $urgentEventId): ?array;

    /** @param array<string,mixed> $event */
    public function insertUrgentEvent(array $event): int;

    public function transitionTicketToUrgent(
        int $ticketId,
        int $expectedLockVersion,
        string $fromStatus
    ): bool;

    public function acknowledgeUrgentEvent(
        int $urgentEventId,
        int $expectedLockVersion,
        int $actorId,
        string $acknowledgedAt
    ): bool;
}
