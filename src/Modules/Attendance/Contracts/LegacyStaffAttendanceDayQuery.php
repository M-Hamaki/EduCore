<?php

declare(strict_types=1);

namespace EduCore\Modules\Attendance\Contracts;

/**
 * Read-only compatibility boundary for the legacy staff_attendance row.
 * The adapter deliberately returns only report-comparison fields.
 */
interface LegacyStaffAttendanceDayQuery
{
    /** @return array<string,mixed>|null */
    public function forStaffDate(int $staffUserId, string $workDate): ?array;
}
