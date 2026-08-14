<?php

declare(strict_types=1);

namespace EduCore\Modules\Staff\Contracts;

use DateTimeImmutable;

/**
 * Live authorization boundary for confidential Ertaq ticket commands.
 *
 * Browser role, manager scope, team membership, and any conflict exclusion are
 * never command fields. Implementations resolve them at the instant of the
 * write and fail closed when a ticket is not visible or an assignee is outside
 * the actor's permitted scope.
 */
interface ErtaqTicketAuthorization
{
    /** @param array<string,mixed>|null $ticket */
    public function assertCanAct(
        int $actorId,
        string $action,
        ?array $ticket,
        DateTimeImmutable $atInstant
    ): void;

    /** @param array<string,mixed> $ticket */
    public function assertCanAssign(
        int $actorId,
        array $ticket,
        ?int $assignedTeamId,
        ?int $assignedToUserId,
        DateTimeImmutable $atInstant
    ): void;
}
