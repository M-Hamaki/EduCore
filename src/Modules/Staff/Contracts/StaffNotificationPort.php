<?php

declare(strict_types=1);

namespace EduCore\Modules\Staff\Contracts;

/** Notification boundary used by Staff workflows without owning delivery infrastructure. */
interface StaffNotificationPort
{
    /**
     * The neutral text must not contain confidential request or case details.
     * Implementations create the authorized inbox item before enqueueing push.
     *
     * @param list<int> $recipientIds
     * @param array<string,mixed> $metadata
     * @return array{
     *     accepted:bool,
     *     status:string,
     *     receipt_id:?string,
     *     inbox_count:int,
     *     outbox_count:int
     * }
     */
    public function notifyRecipients(
        string $eventKey,
        array $recipientIds,
        string $secureRoute,
        string $neutralText,
        array $metadata,
        string $idempotencyKey
    ): array;
}
