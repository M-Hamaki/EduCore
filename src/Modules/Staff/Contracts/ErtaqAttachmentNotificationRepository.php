<?php

declare(strict_types=1);

namespace EduCore\Modules\Staff\Contracts;

/**
 * Staff-owned metadata persistence for Ertaq private attachments. The
 * notification outbox remains behind StaffNotificationPort and is not owned
 * by this repository.
 */
interface ErtaqAttachmentNotificationRepository
{
    public function transactional(callable $work): mixed;

    public function lockUser(int $userId): bool;

    /** @return array<string,mixed>|null */
    public function ticketForUpdate(int $ticketId): ?array;

    /** @return array<string,mixed>|null */
    public function messageForUpdate(int $messageId): ?array;

    /** @return array<string,mixed>|null */
    public function attachmentByIdempotencyForUpdate(string $idempotencyKey): ?array;

    /** @param array<string,mixed> $attachment */
    public function insertAttachment(array $attachment): int;
}
