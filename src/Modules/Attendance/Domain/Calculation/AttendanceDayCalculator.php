<?php

declare(strict_types=1);

namespace EduCore\Modules\Attendance\Domain\Calculation;

use DateTimeImmutable;
use DomainException;
use EduCore\Modules\Attendance\Domain\Schedule\WorkSchedule;

/**
 * Pure, explainable calculation of one attendance work date.
 *
 * It consumes only immutable evidence supplied by the caller. Coverage,
 * workflow decisions, and persistence are deliberately added by application
 * services rather than inferred here.
 */
final class AttendanceDayCalculator
{
    public const ENGINE_VERSION = 'attendance-day-v2';

    public function __construct(private PunchWindowMatcher $punchMatcher)
    {
    }

    /**
     * @param list<array<string,mixed>> $events
     * @param list<array<string,mixed>> $approvedCoverage
     * @return array<string,mixed>
     */
    public function calculate(
        int $staffUserId,
        DateTimeImmutable $workDate,
        WorkSchedule $schedule,
        array $events,
        array $approvedCoverage = []
    ): array {
        if ($staffUserId <= 0) {
            throw new DomainException('STAFF_ID_INVALID');
        }

        $match = $this->punchMatcher->match($schedule, $workDate, $events);
        $window = $match['window'];
        if (!is_array($window)) {
            return [
                'engine_version' => self::ENGINE_VERSION,
                'staff_user_id' => $staffUserId,
                'work_date' => $workDate->format('Y-m-d'),
                'status' => 'non_working',
                'expected_start' => null,
                'expected_end' => null,
                'required_minutes' => 0,
                'first_in' => null,
                'last_out' => null,
                'worked_minutes' => 0,
                'covered_late_minutes' => 0,
                'covered_early_minutes' => 0,
                'mission_minutes' => 0,
                'leave_minutes' => 0,
                'late_minutes' => 0,
                'early_leave_minutes' => 0,
                'missing_minutes' => 0,
                'attendance_segments' => [],
                'reason_lines' => [[
                    'reason_code' => 'NON_WORKING_DAY',
                    'from_at' => null,
                    'to_at' => null,
                    'minutes' => 0,
                    'source_type' => 'schedule',
                    'source_id' => null,
                    'explanation' => 'لا توجد فترة عمل مؤهلة لهذا التاريخ.',
                ]],
                'unusable_events' => [],
            ];
        }

        $coverage = $this->normalizeCoverage($approvedCoverage, $window['start'], $window['end']);
        $attendanceSegments = [];
        $workedMinutes = 0;
        $coveredMinutes = 0;
        $missionMinutes = 0;
        $leaveMinutes = 0;
        foreach ($schedule->segmentsForDate($workDate) as $segment) {
            if (($segment['counts_required_minutes'] ?? false) !== true) {
                continue;
            }
            $workedIntervals = [];
            $sourceEventIds = [];
            foreach ((array) ($match['intervals'] ?? []) as $interval) {
                if (!($interval['start'] ?? null) instanceof DateTimeImmutable
                    || !($interval['end'] ?? null) instanceof DateTimeImmutable) {
                    continue;
                }
                $start = $this->laterOf($segment['start'], $interval['start']);
                $end = $this->earlierOf($segment['end'], $interval['end']);
                if ($end <= $start) {
                    continue;
                }
                $workedIntervals[] = ['start' => $start, 'end' => $end];
                foreach (['entry_event_id', 'exit_event_id'] as $field) {
                    if (isset($interval[$field]) && (int) $interval[$field] > 0) {
                        $sourceEventIds[] = (int) $interval[$field];
                    }
                }
            }
            $scheduledMinutes = $this->minutesBetween($segment['start'], $segment['end']);
            $workedIntervals = $this->mergeIntervals($workedIntervals);
            $segmentMinutes = min($scheduledMinutes, $this->minutesOfIntervals($workedIntervals));
            $coverageIntervals = $this->coverageIntervalsForWindow(
                $coverage,
                $segment['start'],
                $segment['end']
            );
            $coveredIntervals = $this->subtractIntervals($coverageIntervals, $workedIntervals);
            $segmentCoveredMinutes = min(
                max(0, $scheduledMinutes - $segmentMinutes),
                $this->minutesOfIntervals($coveredIntervals)
            );
            $leaveIntervals = $this->subtractIntervals(
                $this->coverageIntervalsForWindow($coverage, $segment['start'], $segment['end'], ['leave']),
                $workedIntervals
            );
            $missionIntervals = $this->subtractIntervals(
                $this->coverageIntervalsForWindow($coverage, $segment['start'], $segment['end'], ['mission']),
                array_merge($workedIntervals, $leaveIntervals)
            );
            $segmentLeaveMinutes = min($segmentCoveredMinutes, $this->minutesOfIntervals($leaveIntervals));
            $segmentMissionMinutes = min(
                max(0, $segmentCoveredMinutes - $segmentLeaveMinutes),
                $this->minutesOfIntervals($missionIntervals)
            );
            $workedMinutes += $segmentMinutes;
            $coveredMinutes += $segmentCoveredMinutes;
            $missionMinutes += $segmentMissionMinutes;
            $leaveMinutes += $segmentLeaveMinutes;
            $attendanceSegments[] = [
                'sequence_no' => (int) ($segment['sequence_no'] ?? count($attendanceSegments) + 1),
                'segment_type' => (string) ($segment['segment_type'] ?? 'work'),
                'scheduled_start' => $segment['start'],
                'scheduled_end' => $segment['end'],
                'scheduled_minutes' => $scheduledMinutes,
                'worked_minutes' => $segmentMinutes,
                'covered_minutes' => $segmentCoveredMinutes,
                'missing_minutes' => max(0, $scheduledMinutes - $segmentMinutes - $segmentCoveredMinutes),
                'source_event_ids' => array_values(array_unique($sourceEventIds)),
            ];
        }

        $requiredMinutes = $schedule->requiredMinutes($workDate);
        $workedMinutes = min($requiredMinutes, $workedMinutes);
        $coveredMinutes = min(max(0, $requiredMinutes - $workedMinutes), $coveredMinutes);
        $leaveMinutes = min($coveredMinutes, $leaveMinutes);
        $missionMinutes = min(max(0, $coveredMinutes - $leaveMinutes), $missionMinutes);
        $firstIn = $match['first_in'] instanceof DateTimeImmutable ? $match['first_in'] : null;
        $lastOut = $match['last_out'] instanceof DateTimeImmutable ? $match['last_out'] : null;
        $lateThreshold = $this->shiftMinutes($window['start'], (int) $window['late_grace_minutes']);
        $earlyThreshold = $this->shiftMinutes($window['end'], -(int) $window['early_grace_minutes']);
        $rawLateMinutes = $firstIn === null || $firstIn <= $lateThreshold
            ? 0
            : $this->minutesBetween($lateThreshold, $this->earlierOf($firstIn, $window['end']));
        $rawEarlyLeaveMinutes = $lastOut === null || $lastOut >= $earlyThreshold
            ? 0
            : $this->minutesBetween($this->laterOf($lastOut, $window['start']), $earlyThreshold);
        $coveredLateMinutes = $rawLateMinutes === 0 || $firstIn === null
            ? 0
            : min(
                $rawLateMinutes,
                $this->minutesOfIntervals($this->coverageIntervalsForWindow(
                    $coverage,
                    $lateThreshold,
                    $this->earlierOf($firstIn, $window['end']),
                    ['late_arrival', 'mission', 'leave']
                ))
            );
        $coveredEarlyMinutes = $rawEarlyLeaveMinutes === 0 || $lastOut === null
            ? 0
            : min(
                $rawEarlyLeaveMinutes,
                $this->minutesOfIntervals($this->coverageIntervalsForWindow(
                    $coverage,
                    $this->laterOf($lastOut, $window['start']),
                    $earlyThreshold,
                    ['early_leave', 'mission', 'leave']
                ))
            );
        $lateMinutes = max(0, $rawLateMinutes - $coveredLateMinutes);
        $earlyLeaveMinutes = max(0, $rawEarlyLeaveMinutes - $coveredEarlyMinutes);
        $missingMinutes = max(0, $requiredMinutes - $workedMinutes - $coveredMinutes);

        $isIncomplete = !($match['has_complete_pair'] ?? false);
        $status = !$isIncomplete && $missingMinutes === 0 && $lateMinutes === 0 && $earlyLeaveMinutes === 0
            ? 'present'
            : ($firstIn === null && $lastOut === null ? 'absent' : ($isIncomplete ? 'incomplete' : 'partial'));
        $reasonLines = $this->reasonLines(
            $match,
            $attendanceSegments,
            $window,
            $firstIn,
            $lastOut,
            $lateThreshold,
            $earlyThreshold,
            $rawLateMinutes,
            $rawEarlyLeaveMinutes,
            $lateMinutes,
            $earlyLeaveMinutes,
            $missingMinutes,
            $coverage
        );

        return [
            'engine_version' => self::ENGINE_VERSION,
            'staff_user_id' => $staffUserId,
            'work_date' => $workDate->format('Y-m-d'),
            'status' => $status,
            'expected_start' => $window['start'],
            'expected_end' => $window['end'],
            'required_minutes' => $requiredMinutes,
            'first_in' => $firstIn,
            'last_out' => $lastOut,
            'worked_minutes' => $workedMinutes,
            'covered_late_minutes' => $coveredLateMinutes,
            'covered_early_minutes' => $coveredEarlyMinutes,
            'mission_minutes' => $missionMinutes,
            'leave_minutes' => $leaveMinutes,
            'raw_late_minutes' => $rawLateMinutes,
            'raw_early_leave_minutes' => $rawEarlyLeaveMinutes,
            'late_minutes' => $lateMinutes,
            'early_leave_minutes' => $earlyLeaveMinutes,
            'missing_minutes' => $missingMinutes,
            'attendance_segments' => $attendanceSegments,
            'reason_lines' => $reasonLines,
            'unusable_events' => $match['unusable_events'] ?? [],
            'punch_intervals' => $match['intervals'] ?? [],
            'approved_coverage' => $coverage,
        ];
    }

    /**
     * @param array<string,mixed> $match
     * @param list<array<string,mixed>> $attendanceSegments
     * @param array<string,mixed> $window
     * @return list<array<string,mixed>>
     */
    private function reasonLines(
        array $match,
        array $attendanceSegments,
        array $window,
        ?DateTimeImmutable $firstIn,
        ?DateTimeImmutable $lastOut,
        DateTimeImmutable $lateThreshold,
        DateTimeImmutable $earlyThreshold,
        int $rawLateMinutes,
        int $rawEarlyLeaveMinutes,
        int $lateMinutes,
        int $earlyLeaveMinutes,
        int $missingMinutes,
        array $coverage
    ): array {
        $lines = [];
        foreach ((array) ($match['unusable_events'] ?? []) as $event) {
            $lines[] = [
                'reason_code' => (string) ($event['reason_code'] ?? 'UNUSABLE_PUNCH'),
                'from_at' => $event['at'] ?? null,
                'to_at' => $event['at'] ?? null,
                'minutes' => 0,
                'source_type' => 'attendance_event',
                'source_id' => isset($event['event_id']) ? (int) $event['event_id'] : null,
                'explanation' => 'سجل حضور غير صالح للاحتساب الآلي ويحتاج إلى مراجعة.',
            ];
        }
        foreach ($attendanceSegments as $segment) {
            if ((int) ($segment['worked_minutes'] ?? 0) <= 0) {
                continue;
            }
            $lines[] = [
                'reason_code' => 'WORKED_SEGMENT',
                'from_at' => $segment['scheduled_start'],
                'to_at' => $segment['scheduled_end'],
                'minutes' => (int) $segment['worked_minutes'],
                'source_type' => 'punch_pair',
                'source_id' => null,
                'explanation' => 'فترة عمل مثبتة ببصمات دخول وخروج متطابقة.',
            ];
        }
        foreach ($coverage as $item) {
            $behavior = (string) $item['coverage_behavior'];
            $reasonCode = match ($behavior) {
                'late_arrival' => 'APPROVED_LATE_ARRIVAL_COVERAGE',
                'early_leave' => 'APPROVED_EARLY_LEAVE_COVERAGE',
                'mission' => 'APPROVED_MISSION_COVERAGE',
                'leave' => 'APPROVED_LEAVE_COVERAGE',
            };
            $sourceType = (string) $item['source_type'] === 'leave'
                ? 'staff_leave'
                : 'staff_permission_request';
            $lines[] = $this->line(
                $reasonCode,
                $item['from_at'],
                $item['to_at'],
                0,
                $sourceType,
                (int) $item['source_id'],
                'فترة تغطية معتمدة؛ يحتسب منها فقط التقاطع غير المثبت داخل دقائق العمل والمخالفة.'
            );
        }
        if ($firstIn === null) {
            $lines[] = $this->line(
                'MISSING_ENTRY_PUNCH',
                $window['start'],
                $window['end'],
                0,
                'attendance_event',
                null,
                'لا توجد بصمة دخول مؤهلة داخل نافذة الالتقاط.'
            );
        }
        if (($match['missing_exit'] ?? false) === true) {
            $lines[] = $this->line(
                'MISSING_EXIT_PUNCH',
                $firstIn ?? $window['start'],
                $window['end'],
                0,
                'attendance_event',
                null,
                'لا توجد بصمة خروج مؤهلة تكمل فترة الحضور.'
            );
        }
        if ($lateMinutes > 0 && $firstIn !== null) {
            $lines[] = $this->line(
                'LATE_ARRIVAL',
                $lateThreshold,
                $firstIn,
                $lateMinutes,
                'schedule',
                null,
                $rawLateMinutes > $lateMinutes
                    ? 'دقائق التأخر غير المغطاة بعد خصم التغطية المعتمدة دون إضافة سماح ثانٍ.'
                    : 'دقائق تأخر غير مغطاة وفق وقت الدوام والسماح المقرر.'
            );
        }
        if ($earlyLeaveMinutes > 0 && $lastOut !== null) {
            $lines[] = $this->line(
                'EARLY_LEAVE',
                $lastOut,
                $earlyThreshold,
                $earlyLeaveMinutes,
                'schedule',
                null,
                $rawEarlyLeaveMinutes > $earlyLeaveMinutes
                    ? 'دقائق الانصراف المبكر غير المغطاة بعد خصم التغطية المعتمدة دون إضافة سماح ثانٍ.'
                    : 'دقائق انصراف مبكر غير مغطاة وفق وقت الدوام والسماح المقرر.'
            );
        }
        if ($missingMinutes > 0) {
            $lines[] = $this->line(
                'MISSING_WORK_TIME',
                $window['start'],
                $window['end'],
                $missingMinutes,
                'schedule',
                null,
                'دقائق العمل المطلوبة المتبقية غير المثبتة وغير المغطاة بعد احتساب البصمات والتغطية المعتمدة.'
            );
        }
        if (!($match['has_complete_pair'] ?? false) && $coverage !== []) {
            $lines[] = $this->line(
                'COVERAGE_DOES_NOT_ESTABLISH_ATTENDANCE',
                $window['start'],
                $window['end'],
                0,
                'attendance_calculation',
                null,
                'التغطية المعتمدة لا تنشئ بصمة دخول أو خروج ولا تحول يومًا بلا دليل حضور إلى حاضر.'
            );
        }
        $alternativeEventIds = [];
        foreach ((array) ($match['matched_events'] ?? []) as $event) {
            if (($event['entry_method_type'] ?? 'biometric') !== 'biometric' && isset($event['id']) && $event['id'] !== null) {
                $alternativeEventIds[] = (int) $event['id'];
            }
        }
        if ($alternativeEventIds !== []) {
            $lines[] = $this->line(
                'ALTERNATIVE_ATTENDANCE',
                $firstIn,
                $lastOut,
                0,
                'attendance_event',
                min($alternativeEventIds),
                'استُخدمت وسيلة حضور بديلة معتمدة؛ لا تُعرض كبصمة جهاز.'
            );
        }

        return $lines;
    }

    /** @return array<string,mixed> */
    private function line(
        string $reasonCode,
        ?DateTimeImmutable $from,
        ?DateTimeImmutable $to,
        int $minutes,
        string $sourceType,
        ?int $sourceId,
        string $explanation
    ): array {
        return [
            'reason_code' => $reasonCode,
            'from_at' => $from,
            'to_at' => $to,
            'minutes' => $minutes,
            'source_type' => $sourceType,
            'source_id' => $sourceId,
            'explanation' => $explanation,
        ];
    }

    /**
     * Normalize source-owned approval evidence before it reaches calculation.
     *
     * @param list<array<string,mixed>> $coverage
     * @return list<array{source_type:string,source_id:int,coverage_behavior:string,from_at:DateTimeImmutable,to_at:DateTimeImmutable,source_version_id:int|null}>
     */
    private function normalizeCoverage(
        array $coverage,
        DateTimeImmutable $windowStart,
        DateTimeImmutable $windowEnd
    ): array {
        $normalized = [];
        foreach ($coverage as $item) {
            if (!is_array($item)) {
                throw new DomainException('APPROVED_COVERAGE_EVIDENCE_INVALID');
            }
            $sourceType = (string) ($item['source_type'] ?? '');
            $behavior = (string) ($item['coverage_behavior'] ?? '');
            $sourceId = filter_var($item['source_id'] ?? null, FILTER_VALIDATE_INT);
            $from = $item['from_at'] ?? null;
            $to = $item['to_at'] ?? null;
            if (!in_array($sourceType, ['permission', 'leave', 'mission'], true)
                || !in_array($behavior, ['late_arrival', 'early_leave', 'mission', 'leave'], true)
                || $sourceId === false || $sourceId <= 0
                || !($from instanceof DateTimeImmutable)
                || !($to instanceof DateTimeImmutable)
                || $to <= $from) {
                throw new DomainException('APPROVED_COVERAGE_EVIDENCE_INVALID');
            }
            $clippedFrom = $this->laterOf($from, $windowStart);
            $clippedTo = $this->earlierOf($to, $windowEnd);
            if ($clippedTo <= $clippedFrom) {
                continue;
            }
            $sourceVersion = $item['source_version_id'] ?? null;
            if ($sourceVersion !== null
                && (!is_int($sourceVersion) && !(is_string($sourceVersion) && preg_match('/^\d+$/D', $sourceVersion) === 1))) {
                throw new DomainException('APPROVED_COVERAGE_EVIDENCE_INVALID');
            }
            $normalized[] = [
                'source_type' => $sourceType,
                'source_id' => $sourceId,
                'coverage_behavior' => $behavior,
                'from_at' => $clippedFrom,
                'to_at' => $clippedTo,
                'source_version_id' => $sourceVersion === null ? null : (int) $sourceVersion,
            ];
        }
        usort($normalized, static function (array $left, array $right): int {
            $byStart = $left['from_at'] <=> $right['from_at'];
            if ($byStart !== 0) {
                return $byStart;
            }
            $byEnd = $left['to_at'] <=> $right['to_at'];
            if ($byEnd !== 0) {
                return $byEnd;
            }
            return [$left['source_type'], $left['source_id']] <=> [$right['source_type'], $right['source_id']];
        });

        return $normalized;
    }

    /**
     * @param list<array<string,mixed>> $coverage
     * @param list<string>|null $behaviors
     * @return list<array{start:DateTimeImmutable,end:DateTimeImmutable}>
     */
    private function coverageIntervalsForWindow(
        array $coverage,
        DateTimeImmutable $windowStart,
        DateTimeImmutable $windowEnd,
        ?array $behaviors = null
    ): array {
        $intervals = [];
        foreach ($coverage as $item) {
            if ($behaviors !== null && !in_array((string) $item['coverage_behavior'], $behaviors, true)) {
                continue;
            }
            $start = $this->laterOf($windowStart, $item['from_at']);
            $end = $this->earlierOf($windowEnd, $item['to_at']);
            if ($end > $start) {
                $intervals[] = ['start' => $start, 'end' => $end];
            }
        }

        return $this->mergeIntervals($intervals);
    }

    /**
     * @param list<array<string,mixed>> $intervals
     * @return list<array{start:DateTimeImmutable,end:DateTimeImmutable}>
     */
    private function mergeIntervals(array $intervals): array
    {
        $normalized = [];
        foreach ($intervals as $interval) {
            $start = $interval['start'] ?? null;
            $end = $interval['end'] ?? null;
            if (!($start instanceof DateTimeImmutable) || !($end instanceof DateTimeImmutable) || $end <= $start) {
                throw new DomainException('ATTENDANCE_INTERVAL_EVIDENCE_INVALID');
            }
            $normalized[] = ['start' => $start, 'end' => $end];
        }
        usort($normalized, static fn (array $left, array $right): int => $left['start'] <=> $right['start']);
        $merged = [];
        foreach ($normalized as $interval) {
            $lastIndex = count($merged) - 1;
            if ($lastIndex >= 0 && $interval['start'] <= $merged[$lastIndex]['end']) {
                if ($interval['end'] > $merged[$lastIndex]['end']) {
                    $merged[$lastIndex]['end'] = $interval['end'];
                }
                continue;
            }
            $merged[] = $interval;
        }

        return $merged;
    }

    /**
     * @param list<array<string,mixed>> $source
     * @param list<array<string,mixed>> $occupied
     * @return list<array{start:DateTimeImmutable,end:DateTimeImmutable}>
     */
    private function subtractIntervals(array $source, array $occupied): array
    {
        $source = $this->mergeIntervals($source);
        $occupied = $this->mergeIntervals($occupied);
        $remaining = [];
        foreach ($source as $interval) {
            $cursor = $interval['start'];
            foreach ($occupied as $block) {
                if ($block['end'] <= $cursor) {
                    continue;
                }
                if ($block['start'] >= $interval['end']) {
                    break;
                }
                if ($block['start'] > $cursor) {
                    $remaining[] = [
                        'start' => $cursor,
                        'end' => $this->earlierOf($block['start'], $interval['end']),
                    ];
                }
                if ($block['end'] >= $interval['end']) {
                    $cursor = $interval['end'];
                    break;
                }
                if ($block['end'] > $cursor) {
                    $cursor = $block['end'];
                }
            }
            if ($cursor < $interval['end']) {
                $remaining[] = ['start' => $cursor, 'end' => $interval['end']];
            }
        }

        return $this->mergeIntervals($remaining);
    }

    /** @param list<array<string,mixed>> $intervals */
    private function minutesOfIntervals(array $intervals): int
    {
        $minutes = 0;
        foreach ($this->mergeIntervals($intervals) as $interval) {
            $minutes += $this->minutesBetween($interval['start'], $interval['end']);
        }

        return $minutes;
    }

    private function laterOf(DateTimeImmutable $left, DateTimeImmutable $right): DateTimeImmutable
    {
        return $left >= $right ? $left : $right;
    }

    private function earlierOf(DateTimeImmutable $left, DateTimeImmutable $right): DateTimeImmutable
    {
        return $left <= $right ? $left : $right;
    }

    private function minutesBetween(DateTimeImmutable $from, DateTimeImmutable $to): int
    {
        return max(0, (int) floor(($to->getTimestamp() - $from->getTimestamp()) / 60));
    }

    private function shiftMinutes(DateTimeImmutable $value, int $minutes): DateTimeImmutable
    {
        return $minutes === 0
            ? $value
            : $value->modify(($minutes < 0 ? '-' : '+') . abs($minutes) . ' minutes');
    }

}
