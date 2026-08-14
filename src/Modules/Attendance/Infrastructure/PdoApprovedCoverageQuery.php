<?php

declare(strict_types=1);

namespace EduCore\Modules\Attendance\Infrastructure;

use DateTimeImmutable;
use EduCore\Modules\Attendance\Contracts\ApprovedCoverageQuery;
use EduCore\Modules\Staff\Contracts\StaffApprovedCoverageReadRepository;

/**
 * Attendance-side adapter over the documented Staff coverage projection.
 *
 * It deliberately has no SQL and therefore cannot couple Attendance's
 * calculation layer to Staff request, leave, or workflow tables.
 */
final class PdoApprovedCoverageQuery implements ApprovedCoverageQuery
{
    public function __construct(private StaffApprovedCoverageReadRepository $staffCoverage)
    {
    }

    public function forStaffWindow(
        int $staffUserId,
        DateTimeImmutable $windowStart,
        DateTimeImmutable $windowEnd
    ): array {
        return $this->staffCoverage->approvedCoverageForStaffWindow(
            $staffUserId,
            $windowStart,
            $windowEnd
        );
    }
}
