<?php

declare(strict_types=1);

namespace EduCore\Modules\Staff\Application\Leave;

use DateTimeImmutable;
use DateTimeZone;
use EduCore\Modules\Staff\Contracts\LeaveRequestClock;

/** Default server clock for leave lifecycle evidence. */
final class SystemLeaveRequestClock implements LeaveRequestClock
{
    public function now(): DateTimeImmutable
    {
        return new DateTimeImmutable('now', new DateTimeZone('UTC'));
    }
}
