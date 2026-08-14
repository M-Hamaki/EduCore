<?php

declare(strict_types=1);

namespace EduCore\Modules\Attendance\Contracts;

use DateTimeImmutable;

/** Read-only, redacted event window used by the deterministic shadow engine. */
interface AttendanceEventWindowQuery
{
    /** @return list<array<string,mixed>> */
    public function forStaffWindow(
        int $staffUserId,
        DateTimeImmutable $windowStart,
        DateTimeImmutable $windowEnd
    ): array;
}
