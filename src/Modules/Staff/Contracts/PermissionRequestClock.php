<?php

declare(strict_types=1);

namespace EduCore\Modules\Staff\Contracts;

use DateTimeImmutable;

/** Clock boundary keeps notice and retroactive rules deterministic in tests. */
interface PermissionRequestClock
{
    public function now(): DateTimeImmutable;
}
