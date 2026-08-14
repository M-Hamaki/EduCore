<?php

declare(strict_types=1);

namespace EduCore\Modules\Staff\Contracts;

use DateTimeImmutable;

/**
 * Live authorization and destination resolver for Ertaq SLA work. It keeps
 * team/user scope and escalation policy outside an unattended worker command.
 */
interface ErtaqSlaAuthorization
{
    /** @param array<string,mixed>|null $ticket */
    public function assertCanAct(
        int $actorId,
        string $action,
        ?array $ticket,
        DateTimeImmutable $atInstant
    ): void;

    /**
     * @param array<string,mixed> $ticket
     * @param array<string,mixed> $slaEvent
     * @return array<string,mixed>
     */
    public function resolveEscalation(
        int $actorId,
        array $ticket,
        array $slaEvent,
        DateTimeImmutable $atInstant
    ): array;
}
