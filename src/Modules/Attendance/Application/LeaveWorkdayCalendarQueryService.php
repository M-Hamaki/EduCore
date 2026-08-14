<?php

declare(strict_types=1);

namespace EduCore\Modules\Attendance\Application;

use DateTimeImmutable;
use DateTimeZone;
use DomainException;
use EduCore\Modules\Attendance\Contracts\EffectiveScheduleQuery;
use EduCore\Modules\Attendance\Contracts\LeaveWorkdayCalendarQuery;
use EduCore\Modules\Attendance\Domain\Schedule\WorkSchedule;

/**
 * Projects resolved Attendance schedules into the minimal leave-calendar
 * contract. Schedule offsets remain an Attendance concern: the current
 * WorkSchedule invariant permits an end offset of at most two days.
 */
final class LeaveWorkdayCalendarQueryService implements LeaveWorkdayCalendarQuery
{
    private const CROSS_MIDNIGHT_LOOKBACK_DAYS = 2;

    public function __construct(private EffectiveScheduleQuery $effectiveSchedules)
    {
    }

    public function daysIntersecting(
        int $staffId,
        DateTimeImmutable $fromAt,
        DateTimeImmutable $toAt,
        DateTimeZone $requestTimezone
    ): array {
        if ($staffId <= 0) {
            throw new DomainException('LEAVE_WORKDAY_CALENDAR_STAFF_INVALID');
        }
        if ($toAt <= $fromAt) {
            throw new DomainException('LEAVE_WORKDAY_CALENDAR_WINDOW_INVALID');
        }

        $localFrom = $fromAt->setTimezone($requestTimezone);
        $localTo = $toAt->setTimezone($requestTimezone);
        $firstRequestedDate = $localFrom->setTime(0, 0, 0, 0);
        $lastRequestedDate = $localTo
            ->modify('-1 microsecond')
            ->setTime(0, 0, 0, 0);
        $cursor = $firstRequestedDate->modify('-' . self::CROSS_MIDNIGHT_LOOKBACK_DAYS . ' days');
        $days = [];

        while ($cursor <= $lastRequestedDate) {
            $resolution = $this->effectiveSchedules->forStaffDate($staffId, $cursor);
            $withinRequestedDates = $cursor >= $firstRequestedDate && $cursor <= $lastRequestedDate;
            $entry = $this->normalizeResolution($resolution, $staffId, $cursor);

            if ($entry['status'] === 'unresolved') {
                // An unresolved prior day may own an overnight interval that
                // reaches the request window, so it must remain visible to
                // the Staff policy service rather than be silently ignored.
                $days[$entry['work_date']] = $entry;
                $cursor = $cursor->modify('+1 day');
                continue;
            }

            $hasOverlappingInterval = false;
            foreach ($entry['working_intervals'] as $interval) {
                $start = $this->parseInstant($interval['start_at']);
                $end = $this->parseInstant($interval['end_at']);
                if ($start < $toAt && $end > $fromAt) {
                    $hasOverlappingInterval = true;
                    break;
                }
            }

            if ($withinRequestedDates || $hasOverlappingInterval) {
                $days[$entry['work_date']] = $entry;
            }
            $cursor = $cursor->modify('+1 day');
        }

        ksort($days, SORT_STRING);

        return array_values($days);
    }

    /**
     * @param array<string,mixed> $resolution
     * @return array{
     *     status:'working'|'non_working'|'unresolved',
     *     reason_code:string,
     *     staff_id:int,
     *     work_date:string,
     *     required_minutes:int,
     *     working_intervals:list<array{start_at:string,end_at:string,minutes:int}>,
     *     schedule_policy_version_id:?int,
     *     calendar_exception_id:?int,
     *     conflicts:list<int>
     * }
     */
    private function normalizeResolution(array $resolution, int $staffId, DateTimeImmutable $workDate): array
    {
        $status = (string) ($resolution['status'] ?? 'unresolved');
        $reasonCode = trim((string) ($resolution['reason_code'] ?? 'LEAVE_WORKDAY_CALENDAR_UNRESOLVED'));
        $explanation = is_array($resolution['explanation'] ?? null) ? $resolution['explanation'] : [];
        $base = [
            'reason_code' => $reasonCode === '' ? 'LEAVE_WORKDAY_CALENDAR_UNRESOLVED' : $reasonCode,
            'staff_id' => $staffId,
            'work_date' => $workDate->format('Y-m-d'),
            'schedule_policy_version_id' => $this->nullablePositiveInt($explanation['version_id'] ?? null),
            'calendar_exception_id' => $this->nullablePositiveInt($explanation['calendar_exception_id'] ?? null),
            'conflicts' => $this->positiveIds((array) ($resolution['conflicts'] ?? [])),
        ];
        if ($status === 'unresolved') {
            return $base + [
                'status' => 'unresolved',
                'required_minutes' => 0,
                'working_intervals' => [],
            ];
        }
        if ($status !== 'working') {
            return $base + [
                'status' => 'non_working',
                'required_minutes' => 0,
                'working_intervals' => [],
            ];
        }

        $schedule = $resolution['selected']['schedule'] ?? null;
        if (!$schedule instanceof WorkSchedule) {
            throw new DomainException('LEAVE_WORKDAY_CALENDAR_SCHEDULE_INVALID');
        }

        $intervals = [];
        $requiredMinutes = 0;
        foreach ($schedule->segmentsForDate($workDate) as $segment) {
            if (($segment['counts_required_minutes'] ?? false) !== true) {
                continue;
            }
            $start = $segment['start'] ?? null;
            $end = $segment['end'] ?? null;
            $minutes = (int) ($segment['duration_minutes'] ?? 0);
            if (!$start instanceof DateTimeImmutable || !$end instanceof DateTimeImmutable || $end <= $start || $minutes <= 0) {
                throw new DomainException('LEAVE_WORKDAY_CALENDAR_SEGMENT_INVALID');
            }
            $actualMinutes = (int) floor(($end->getTimestamp() - $start->getTimestamp()) / 60);
            if ($actualMinutes !== $minutes) {
                throw new DomainException('LEAVE_WORKDAY_CALENDAR_SEGMENT_DURATION_INVALID');
            }
            $intervals[] = [
                'start_at' => $start->format('Y-m-d\TH:i:s.uP'),
                'end_at' => $end->format('Y-m-d\TH:i:s.uP'),
                'minutes' => $minutes,
            ];
            $requiredMinutes += $minutes;
        }
        if ($requiredMinutes <= 0 || $requiredMinutes !== $schedule->requiredMinutes($workDate)) {
            throw new DomainException('LEAVE_WORKDAY_CALENDAR_REQUIRED_MINUTES_INVALID');
        }

        return $base + [
            'status' => 'working',
            'required_minutes' => $requiredMinutes,
            'working_intervals' => $intervals,
        ];
    }

    private function parseInstant(string $value): DateTimeImmutable
    {
        try {
            return new DateTimeImmutable($value);
        } catch (\Throwable $exception) {
            throw new DomainException('LEAVE_WORKDAY_CALENDAR_INTERVAL_INVALID', 0, $exception);
        }
    }

    /** @return list<int> */
    private function positiveIds(array $values): array
    {
        $ids = array_values(array_unique(array_filter(
            array_map('intval', $values),
            static fn (int $id): bool => $id > 0
        )));
        sort($ids, SORT_NUMERIC);

        return $ids;
    }

    private function nullablePositiveInt(mixed $value): ?int
    {
        $id = filter_var($value, FILTER_VALIDATE_INT);

        return $id !== false && $id > 0 ? $id : null;
    }
}
