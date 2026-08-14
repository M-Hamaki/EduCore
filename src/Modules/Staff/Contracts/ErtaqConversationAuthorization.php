<?php

declare(strict_types=1);

namespace EduCore\Modules\Staff\Contracts;

use DateTimeImmutable;

/**
 * Live authorization and visibility boundary for confidential Ertaq
 * conversations. Callers never select an effective visibility scope, party
 * access, or link audience through a browser field.
 */
interface ErtaqConversationAuthorization
{
    /** @param array<string,mixed>|null $ticket */
    public function assertCanAct(
        int $actorId,
        string $action,
        ?array $ticket,
        DateTimeImmutable $atInstant
    ): void;

    /** @param array<string,mixed> $ticket */
    public function resolveMessageVisibility(
        int $actorId,
        array $ticket,
        string $messageType,
        ?string $requestedVisibility,
        DateTimeImmutable $atInstant
    ): string;

    /** @param array<string,mixed> $ticket @param array<string,mixed> $party */
    public function resolvePartyVisibility(
        int $actorId,
        array $ticket,
        array $party,
        ?string $requestedVisibility,
        DateTimeImmutable $atInstant
    ): string;

    /** @param array<string,mixed> $ticket @param array<string,mixed> $link */
    public function resolveLinkVisibility(
        int $actorId,
        array $ticket,
        array $link,
        ?string $requestedVisibility,
        DateTimeImmutable $atInstant
    ): string;
}
