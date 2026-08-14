<?php

declare(strict_types=1);

namespace EduCore\Modules\Attendance\Contracts;

/**
 * Attendance-owned read model for official daily versions and their reason
 * lines. Implementations must not join Staff profile, permission, or raw
 * biometric payload tables.
 */
interface AttendanceReportReadRepository
{
    /**
     * @param array{
     *     date_from:string,
     *     date_to:string,
     *     staff_user_ids:?list<int>,
     *     as_of:?string,
     *     scan_limit:int,
     *     cursor?:array{work_date:string,staff_user_id:int,day_version_id:int}|null
     * } $filters
     * @return list<array<string,mixed>>
     */
    public function officialDays(array $filters): array;

    /**
     * @param list<int> $dayVersionIds
     * @return list<array<string,mixed>>
     */
    public function reasonLinesForDayVersions(array $dayVersionIds): array;
}
