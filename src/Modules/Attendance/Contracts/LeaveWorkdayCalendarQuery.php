<?php

declare(strict_types=1);

namespace EduCore\Modules\Attendance\Contracts;

use DateTimeImmutable;
use DateTimeZone;

/**
 * Narrow Attendance-owned calendar read model for leave calculation.
 *
 * Staff receives only resolved workday intervals and calendar provenance. It
 * never reads schedule, exception, or biometric tables directly and never
 * receives the attendance policy payload.
 */
interface LeaveWorkdayCalendarQuery
{
    /**
     * Return every requested local calendar day plus any prior workday whose
     * cross-midnight required interval overlaps the half-open request window.
     *
     * Results are sorted by work_date and contain no duplicate work_date.
     *
     * @return list<array{
     *     status:'working'|'non_working'|'unresolved',
     *     reason_code:string,
     *     staff_id:int,
     *     work_date:string,
     *     required_minutes:int,
     *     working_intervals:list<array{start_at:string,end_at:string,minutes:int}>,
     *     schedule_policy_version_id:?int,
     *     calendar_exception_id:?int,
     *     conflicts:list<int>
     * }>
     */
    public function daysIntersecting(
        int $staffId,
        DateTimeImmutable $fromAt,
        DateTimeImmutable $toAt,
        DateTimeZone $requestTimezone
    ): array;
}
