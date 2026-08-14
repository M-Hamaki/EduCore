<?php

declare(strict_types=1);

namespace EduCore\Modules\Staff\Contracts;

use DateTimeImmutable;

/**
 * Staff-owned projection of approved time coverage for Attendance.
 *
 * It intentionally excludes reasons, attachments, policy snapshots, and
 * workflow evidence.  Attendance receives only the minimum immutable
 * interval evidence required to calculate a day.
 */
interface StaffApprovedCoverageReadRepository
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
    public function approvedCoverageForStaffWindow(
        int $staffUserId,
        DateTimeImmutable $windowStart,
        DateTimeImmutable $windowEnd
    ): array;
}
