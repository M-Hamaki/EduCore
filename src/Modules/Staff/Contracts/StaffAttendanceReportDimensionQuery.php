<?php

declare(strict_types=1);

namespace EduCore\Modules\Staff\Contracts;

/**
 * Staff-owned historical organization projection for Attendance reports.
 *
 * Callers provide the immutable Attendance day reference. The implementation
 * returns the assignment and group membership effective on that work date; it
 * must never substitute today's staff profile or group membership.
 */
interface StaffAttendanceReportDimensionQuery
{
    /**
     * @param list<array{
     *     day_version_id:int,
     *     staff_user_id:int,
     *     work_date:string,
     *     assignment_id:?int
     * }> $dayReferences
     * @return array{
     *     dimensions:array<int,array{
     *         day_version_id:int,
     *         staff_user_id:int,
     *         assignment_id:int,
     *         org_unit_id:?int,
     *         job_title_id:?int,
     *         group_ids:list<int>
     *     }>,
     *     conflicts:list<array{day_version_id:int,reason_code:string}>
     * }
     */
    public function forAttendanceDays(array $dayReferences): array;
}
