<?php

declare(strict_types=1);

namespace EduCore\Modules\Staff\Contracts;

/**
 * Staff-owned persistence boundary for the Ertaq ticket and assignment
 * aggregate. Implementations keep each business write and its shared audit
 * event in one transaction and never write another module's resource.
 */
interface ErtaqTicketRepository
{
    public function transactional(callable $work): mixed;

    public function lockUser(int $userId): bool;

    /** @return array<string,mixed>|null */
    public function ticketByCreateIdempotencyForUpdate(string $idempotencyKey): ?array;

    /** @return array<string,mixed>|null */
    public function ticketForUpdate(int $ticketId): ?array;

    /** @param array<string,mixed> $ticket */
    public function insertTicket(array $ticket): int;

    /** @param array<string,mixed> $changes */
    public function transitionTicket(
        int $ticketId,
        int $expectedLockVersion,
        string $fromStatus,
        string $toStatus,
        array $changes
    ): bool;

    /** @return array<string,mixed>|null */
    public function assignmentByIdempotencyForUpdate(string $idempotencyKey): ?array;

    /** @return array<string,mixed>|null */
    public function activeAssignmentForTicketForUpdate(int $ticketId): ?array;

    /** @param array<string,mixed> $assignment */
    public function insertAssignment(array $assignment): int;

    public function supersedeAssignment(
        int $assignmentId,
        int $expectedLockVersion,
        int $actorId,
        string $endedAt,
        string $reason
    ): bool;
}
