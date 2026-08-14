<?php

declare(strict_types=1);

namespace EduCore\Modules\Staff\Application\Permission;

use DateTimeImmutable;
use EduCore\Modules\Staff\Contracts\PermissionRequestClock;

final class SystemPermissionRequestClock implements PermissionRequestClock
{
    public function now(): DateTimeImmutable
    {
        return new DateTimeImmutable('now');
    }
}
