<?php

declare(strict_types=1);

namespace EduCore\Modules\Staff\Contracts;

use DateTimeImmutable;

/**
 * Live authority boundary for the urgent Ertaq protection route. A browser
 * cannot choose the protection team, recipients, or whether a conflicted
 * manager remains eligible.
 */
interface ErtaqUrgentRoutingAuthorization
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
     * @param list<int> $excludedUserIds
     * @return array<string,mixed>
     */
    public function resolveProtectionRoute(
        int $actorId,
        array $ticket,
        string $riskType,
        array $excludedUserIds,
        DateTimeImmutable $atInstant
    ): array;

    /** @param array<string,mixed> $ticket @param array<string,mixed> $urgentEvent */
    public function assertCanAcknowledge(
        int $actorId,
        array $ticket,
        array $urgentEvent,
        DateTimeImmutable $atInstant
    ): void;
}
