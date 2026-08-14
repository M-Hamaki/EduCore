<?php

declare(strict_types=1);

namespace EduCore\Modules\Staff\Contracts;

use DateTimeImmutable;

/**
 * Live access, visibility, and recipient boundary for Ertaq attachments.
 * Browser fields never select the effective attachment audience or notification
 * recipients.
 */
interface ErtaqAttachmentNotificationAuthorization
{
    /** @param array<string,mixed>|null $ticket */
    public function assertCanAct(
        int $actorId,
        string $action,
        ?array $ticket,
        DateTimeImmutable $atInstant
    ): void;

    /** @param array<string,mixed> $ticket @param array<string,mixed>|null $message */
    public function resolveAttachmentVisibility(
        int $actorId,
        array $ticket,
        ?array $message,
        DateTimeImmutable $atInstant
    ): string;

    /**
     * @param array<string,mixed> $ticket
     * @param array<string,mixed> $attachmentContext safe IDs/scope only; no filename, path, or content
     * @return array{recipient_user_ids:list<int>,secure_route:string}
     */
    public function resolveNeutralNotification(
        int $actorId,
        array $ticket,
        array $attachmentContext,
        DateTimeImmutable $atInstant
    ): array;
}
