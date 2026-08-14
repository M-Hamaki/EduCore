<?php

declare(strict_types=1);

namespace EduCore\Modules\Attendance\Contracts;

use DateTimeImmutable;

interface EffectiveScheduleQuery
{
    /** @return array<string,mixed> */
    public function forStaffDate(int $staffId, DateTimeImmutable $workDate): array;
}
