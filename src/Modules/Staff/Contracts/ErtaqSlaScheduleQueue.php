<?php

declare(strict_types=1);

namespace EduCore\Modules\Staff\Contracts;

use DateTimeImmutable;

/**
 * Transactional local queue for the frozen SLA evidence attached to a new
 * Ertaq ticket. It schedules internal SLA records only; it never sends a
 * browser or push notification directly.
 */
interface ErtaqSlaScheduleQueue
{
    /** @param array<string,mixed> $ticket */
    public function scheduleTicketSla(
        array $ticket,
        int $actorId,
        DateTimeImmutable $atInstant
    ): void;
}
