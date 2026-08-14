<?php

declare(strict_types=1);

namespace EduCore\Modules\Staff\Contracts;

use DateTimeImmutable;

/** Supplies the server-side instant used for leave submission evidence. */
interface LeaveRequestClock
{
    public function now(): DateTimeImmutable;
}
