<?php

declare(strict_types=1);

namespace EduCore\Modules\Attendance\Contracts;

use DateTimeImmutable;

/**
 * Read-only evidence boundary for approved absence/permission coverage.
 *
 * Implementations return only immutable, already-approved intervals that
 * overlap the supplied scheduled work window.  The calculator receives the
 * evidence as data and never resolves approval state or Staff-owned policy.
 */
interface ApprovedCoverageQuery
{
    /**
     * @return list<array{
     *   source_type:'permission'|'leave'|'mission',
     *   source_id:int,
     *   coverage_behavior:'late_arrival'|'early_leave'|'mission'|'leave',
     *   from_at:DateTimeImmutable,
     *   to_at:DateTimeImmutable,
     *   source_version_id?:int|null
     * }>
     */
    public function forStaffWindow(
        int $staffUserId,
        DateTimeImmutable $windowStart,
        DateTimeImmutable $windowEnd
    ): array;
}
