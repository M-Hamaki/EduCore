<?php

declare(strict_types=1);

namespace EduCore\Modules\Staff\Contracts;

/**
 * Staff-owned persistence boundary for scheduled and fired Ertaq SLA events.
 * Implementations do not notify recipients or expose ticket message text.
 */
interface ErtaqSlaRepository
{
    public function transactional(callable $work): mixed;

    /** @return array<string,mixed>|null */
    public function ticketForUpdate(int $ticketId): ?array;

    /** @return array<string,mixed>|null */
    public function slaEventByIdempotencyForUpdate(string $idempotencyKey): ?array;

    /** @param array<string,mixed> $event */
    public function insertSlaEvent(array $event): int;

    /** @return list<array<string,mixed>> */
    public function dueSlaEventsForUpdate(string $atInstant, int $limit): array;

    public function markSlaEventFired(
        int $eventId,
        int $expectedLockVersion,
        string $occurredAt,
        ?int $targetTeamId,
        ?int $targetUserId,
        array $escalationSnapshot
    ): bool;

    public function cancelSlaEvent(
        int $eventId,
        int $expectedLockVersion,
        string $occurredAt
    ): bool;

    /**
     * Replaces only the frozen deadline window during the already-authorized
     * reopening transition. The ticket state and optimistic lock remain owned
     * by ErtaqTicketService.
     */
    public function renewTicketSlaWindow(
        int $ticketId,
        int $expectedLockVersion,
        ?string $firstResponseDueAt,
        ?string $slaDueAt
    ): bool;
}
