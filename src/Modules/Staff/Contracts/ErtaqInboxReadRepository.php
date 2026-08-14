<?php

declare(strict_types=1);

namespace EduCore\Modules\Staff\Contracts;

/**
 * Read boundary for the two deliberately narrow Ertaq inboxes.
 *
 * The requester inbox sees only the requester's own ticket and messages that
 * were explicitly published to the requester. The administration inbox sees
 * only a current direct assignment and never infers team/protection access
 * from an administrative login alone.
 */
interface ErtaqInboxReadRepository
{
    /** @param array<string,mixed> $filters @return list<array<string,mixed>> */
    public function requesterTickets(int $requesterUserId, array $filters): array;

    /** @param array<string,mixed> $filters */
    public function requesterTicketCount(int $requesterUserId, array $filters): int;

    /** @return array<string,mixed>|null */
    public function requesterTicket(int $requesterUserId, int $ticketId): ?array;

    /** @return list<array<string,mixed>> */
    public function requesterMessages(int $requesterUserId, int $ticketId): array;

    /** @param array<string,mixed> $filters @return list<array<string,mixed>> */
    public function assignedTickets(int $assigneeUserId, array $filters): array;

    /** @param array<string,mixed> $filters */
    public function assignedTicketCount(int $assigneeUserId, array $filters): int;

    /** @return array{total:int,overdue:int,urgent:int} */
    public function assignedSummary(int $assigneeUserId): array;

    /** @return array<string,mixed>|null */
    public function assignedTicket(int $assigneeUserId, int $ticketId): ?array;

    /** @return list<array<string,mixed>> */
    public function assignedMessages(int $assigneeUserId, int $ticketId): array;

    /**
     * Existence is intentionally limited to the selected numeric identifier.
     * It lets an HTTP adapter return the documented 403 for an inaccessible
     * known URL, without reading a title, content, party, or assignment.
     */
    public function ticketExists(int $ticketId): bool;
}
