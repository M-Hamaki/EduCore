<?php

declare(strict_types=1);

namespace EduCore\Modules\Staff\Contracts;

/**
 * Staff-owned persistence boundary for Ertaq conversations, parties, links,
 * and append-only withdrawal evidence. Implementations keep business writes
 * and the caller's shared audit event in one transaction.
 */
interface ErtaqConversationRepository
{
    public function transactional(callable $work): mixed;

    public function lockUser(int $userId): bool;

    /** @return array<string,mixed>|null */
    public function ticketForUpdate(int $ticketId): ?array;

    /** @param array<string,mixed> $changes */
    public function transitionTicket(
        int $ticketId,
        int $expectedLockVersion,
        string $fromStatus,
        string $toStatus,
        array $changes
    ): bool;

    /** @return array<string,mixed>|null */
    public function messageByIdempotencyForUpdate(string $idempotencyKey): ?array;

    /** @return array<string,mixed>|null */
    public function messageForUpdate(int $messageId): ?array;

    /** @param array<string,mixed> $message */
    public function insertMessage(array $message): int;

    /** @return array<string,mixed>|null */
    public function partyByIdempotencyForUpdate(string $idempotencyKey): ?array;

    /** @param array<string,mixed> $party */
    public function insertParty(array $party): int;

    /** @return array<string,mixed>|null */
    public function linkByIdempotencyForUpdate(string $idempotencyKey): ?array;

    /** @param array<string,mixed> $link */
    public function insertLink(array $link): int;

    /** @return array<string,mixed>|null */
    public function withdrawalByIdempotencyForUpdate(string $idempotencyKey): ?array;

    /** @return array<string,mixed>|null */
    public function withdrawalEventForUpdate(int $withdrawalEventId): ?array;

    /** @return array<string,mixed>|null */
    public function withdrawalDecisionForRequestForUpdate(int $requestEventId): ?array;

    /** @param array<string,mixed> $event */
    public function insertWithdrawalEvent(array $event): int;
}
