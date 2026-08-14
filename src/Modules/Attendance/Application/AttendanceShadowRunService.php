<?php

declare(strict_types=1);

namespace EduCore\Modules\Attendance\Application;

use DateTimeImmutable;
use DateTimeInterface;
use DateTimeZone;
use DomainException;
use EduCore\Modules\Attendance\Contracts\AttendanceEventWindowQuery;
use EduCore\Modules\Attendance\Contracts\AttendanceShadowRunRepository;
use EduCore\Modules\Attendance\Contracts\AttendanceTransactionManager;
use EduCore\Modules\Attendance\Contracts\ApprovedCoverageQuery;
use EduCore\Modules\Attendance\Contracts\EffectiveScheduleQuery;
use EduCore\Modules\Attendance\Contracts\LegacyStaffAttendanceDayQuery;
use EduCore\Modules\Attendance\Domain\Calculation\AttendanceDayCalculator;
use EduCore\Modules\Attendance\Domain\Schedule\WorkSchedule;
use EduCore\Modules\Operations\Audit\AuditEventWriter;
use InvalidArgumentException;
use JsonException;

/**
 * Runs the new calculation beside, never instead of, the legacy daily
 * attendance reader. Shadow results are non-official and explain every delta.
 */
final class AttendanceShadowRunService
{
    public const ENGINE_VERSION = 'attendance-shadow-v2';

    private DateTimeZone $utc;

    public function __construct(
        private AttendanceTransactionManager $transactions,
        private AttendanceShadowRunRepository $repository,
        private EffectiveScheduleQuery $schedules,
        private AttendanceEventWindowQuery $events,
        private LegacyStaffAttendanceDayQuery $legacy,
        private AttendanceDayCalculator $calculator,
        private AuditEventWriter $audit,
        private ?ApprovedCoverageQuery $coverage = null
    ) {
        $this->utc = new DateTimeZone('UTC');
    }

    /**
     * @param list<int> $staffUserIds Explicit bounded population selected by a
     *   caller that owns scope authorization.
     * @return array<string,mixed>
     */
    public function run(
        int $actorId,
        array $staffUserIds,
        DateTimeImmutable $from,
        DateTimeImmutable $to,
        string $idempotencyKey
    ): array {
        if ($actorId <= 0) {
            throw new InvalidArgumentException('ATTENDANCE_SHADOW_ACTOR_INVALID');
        }
        $staffUserIds = $this->normalizeStaffIds($staffUserIds);
        $from = $from->setTime(0, 0, 0, 0);
        $to = $to->setTime(0, 0, 0, 0);
        if ($to < $from) {
            throw new InvalidArgumentException('ATTENDANCE_SHADOW_RANGE_INVALID');
        }
        $dates = $this->dates($from, $to);
        $idempotencyKey = $this->requiredText(
            $idempotencyKey,
            190,
            'ATTENDANCE_SHADOW_IDEMPOTENCY_KEY_INVALID'
        );
        $inputFingerprint = $this->hash([
            'actor_id' => $actorId,
            'staff_user_ids' => $staffUserIds,
            'from' => $from->format('Y-m-d'),
            'to' => $to->format('Y-m-d'),
            'engine_version' => self::ENGINE_VERSION,
        ]);
        $now = new DateTimeImmutable('now', $this->utc);

        return $this->transactions->transactional(function () use (
            $actorId,
            $staffUserIds,
            $dates,
            $from,
            $to,
            $idempotencyKey,
            $inputFingerprint,
            $now
        ): array {
            $existing = $this->repository->runByIdempotencyForUpdate($idempotencyKey);
            if ($existing !== null) {
                if ((string) ($existing['source_fingerprint'] ?? '') !== $inputFingerprint
                    || (string) ($existing['engine_version'] ?? '') !== self::ENGINE_VERSION
                    || (string) ($existing['mode'] ?? '') !== 'shadow') {
                    throw new DomainException('ATTENDANCE_SHADOW_IDEMPOTENCY_CONFLICT');
                }
                return [
                    'run_id' => (int) ($existing['id'] ?? 0),
                    'summary' => $this->decodeSummary($existing['summary'] ?? null),
                    'replayed' => true,
                ];
            }

            $runId = $this->repository->insertShadowRun([
                'engine_version' => self::ENGINE_VERSION,
                'mode' => 'shadow',
                'range_from' => $from->format('Y-m-d'),
                'range_to' => $to->format('Y-m-d'),
                'cutoff_at' => $this->databaseInstant($now),
                'initiated_by' => $actorId,
                'status' => 'queued',
                'source_fingerprint' => $inputFingerprint,
                'idempotency_key' => $idempotencyKey,
                'supersedes_run_id' => null,
            ]);
            if (!$this->repository->startShadowRun($runId, $now)) {
                throw new DomainException('ATTENDANCE_SHADOW_RUN_STALE');
            }

            $summary = [
                'staff_count' => count($staffUserIds),
                'day_count' => 0,
                'stored_days' => 0,
                'reused_days' => 0,
                'non_working_days' => 0,
                'unresolved_days' => 0,
                'legacy_matches' => 0,
                'legacy_differences' => 0,
                'difference_codes' => [],
            ];
            foreach ($staffUserIds as $staffUserId) {
                foreach ($dates as $workDate) {
                    $result = $this->shadowDay($runId, $staffUserId, $workDate, $now);
                    ++$summary['day_count'];
                    ++$summary[$result['stored'] ? 'stored_days' : 'reused_days'];
                    if ($result['status'] === 'non_working') {
                        ++$summary['non_working_days'];
                    }
                    if ($result['status'] === 'unresolved') {
                        ++$summary['unresolved_days'];
                    }
                    if ($result['comparison']['match']) {
                        ++$summary['legacy_matches'];
                    } else {
                        ++$summary['legacy_differences'];
                        foreach ($result['comparison']['difference_codes'] as $code) {
                            $summary['difference_codes'][$code] = (int) ($summary['difference_codes'][$code] ?? 0) + 1;
                        }
                    }
                }
            }
            ksort($summary['difference_codes'], SORT_STRING);
            if (!$this->repository->completeShadowRun($runId, $now, $summary)) {
                throw new DomainException('ATTENDANCE_SHADOW_RUN_STALE');
            }
            $this->audit->recordEvent(
                'staff_attendance_shadow_run_completed',
                'staff_attendance_runs',
                $runId,
                null,
                [
                    'engine_version' => self::ENGINE_VERSION,
                    'range_from' => $from->format('Y-m-d'),
                    'range_to' => $to->format('Y-m-d'),
                    'summary' => $summary,
                    'source_fingerprint' => $inputFingerprint,
                ],
                ['user_id' => $actorId]
            );
            return ['run_id' => $runId, 'summary' => $summary, 'replayed' => false];
        });
    }

    /** @return array{stored:bool,status:string,comparison:array{match:bool,difference_codes:list<string>}} */
    private function shadowDay(
        int $runId,
        int $staffUserId,
        DateTimeImmutable $workDate,
        DateTimeImmutable $now
    ): array {
        $resolution = $this->schedules->forStaffDate($staffUserId, $workDate);
        $selected = is_array($resolution['selected'] ?? null) ? $resolution['selected'] : [];
        $schedule = $selected['schedule'] ?? null;
        $resolutionStatus = (string) ($resolution['status'] ?? '');
        $legacy = $this->legacy->forStaffDate($staffUserId, $workDate->format('Y-m-d'));
        $calculation = null;
        $events = [];
        $approvedCoverage = [];
        if ($resolutionStatus !== 'non_working' && $schedule instanceof WorkSchedule) {
            $window = $schedule->workWindow($workDate);
            if ($window !== null) {
                $events = $this->events->forStaffWindow(
                    $staffUserId,
                    $window['entry_capture_start'],
                    $window['exit_capture_end']
                );
                $approvedCoverage = $this->coverage === null
                    ? []
                    : $this->coverage->forStaffWindow($staffUserId, $window['start'], $window['end']);
                $calculation = $this->calculator->calculate(
                    $staffUserId,
                    $workDate,
                    $schedule,
                    $events,
                    $approvedCoverage
                );
            }
        }
        $day = $calculation === null
            ? $this->emptyDay($staffUserId, $workDate, $resolution, $resolutionStatus === 'non_working' ? 'non_working' : 'unresolved')
            : $this->dayFromCalculation($calculation, $resolution);
        $comparison = $this->compare($day, $legacy);
        $sourceFingerprint = $this->hash([
            'staff_user_id' => $staffUserId,
            'work_date' => $workDate->format('Y-m-d'),
            'resolution' => $this->resolutionFingerprint($resolution),
            'events' => $this->eventFingerprint($events),
            'approved_coverage' => $this->coverageFingerprint($approvedCoverage),
            'legacy' => $this->legacyFingerprint($legacy),
            'engine_version' => self::ENGINE_VERSION,
        ]);
        $existing = $this->repository->shadowDayBySourceForUpdate(
            $staffUserId,
            $workDate->format('Y-m-d'),
            $sourceFingerprint,
            self::ENGINE_VERSION
        );
        if ($existing !== null) {
            return ['stored' => false, 'status' => (string) ($day['status'] ?? 'unresolved'), 'comparison' => $comparison];
        }

        $dayVersionId = $this->repository->insertShadowDay([
            'staff_user_id' => $staffUserId,
            'work_date' => $workDate->format('Y-m-d'),
            'version_no' => $this->repository->nextDayVersionNoForUpdate($staffUserId, $workDate->format('Y-m-d')),
            'run_id' => $runId,
            'assignment_id' => $day['assignment_id'],
            'schedule_policy_version_id' => $day['schedule_policy_version_id'],
            'calendar_exception_id' => $day['calendar_exception_id'],
            'expected_start' => $this->databaseValue($day['expected_start']),
            'expected_end' => $this->databaseValue($day['expected_end']),
            'required_minutes' => $day['required_minutes'],
            'first_in' => $this->databaseValue($day['first_in']),
            'last_out' => $this->databaseValue($day['last_out']),
            'worked_minutes' => $day['worked_minutes'],
            'covered_late_minutes' => $day['covered_late_minutes'],
            'covered_early_minutes' => $day['covered_early_minutes'],
            'mission_minutes' => $day['mission_minutes'],
            'leave_minutes' => $day['leave_minutes'],
            'late_minutes' => $day['late_minutes'],
            'early_leave_minutes' => $day['early_leave_minutes'],
            'missing_minutes' => $day['missing_minutes'],
            'status' => $day['status'],
            'calculation_mode' => 'shadow',
            'engine_version' => self::ENGINE_VERSION,
            'source_fingerprint' => $sourceFingerprint,
            'is_official' => 0,
            'officialized_by' => null,
            'officialized_at' => null,
            'supersedes_id' => null,
            'calculated_at' => $this->databaseInstant($now),
        ]);
        foreach ($day['attendance_segments'] as $segment) {
            $this->repository->appendSegment($this->segment($dayVersionId, $segment));
        }
        $lineNo = 0;
        foreach ($day['reason_lines'] as $reason) {
            $this->repository->appendReasonLine($this->reasonLine($dayVersionId, ++$lineNo, $reason));
        }
        foreach ($comparison['difference_codes'] as $code) {
            $this->repository->appendReasonLine([
                'day_version_id' => $dayVersionId,
                'line_no' => ++$lineNo,
                'reason_code' => $code,
                'from_at' => null,
                'to_at' => null,
                'minutes' => 0,
                'source_type' => 'legacy_staff_attendance',
                'source_id' => isset($legacy['id']) ? (int) $legacy['id'] : null,
                'explanation' => 'فرق موثق بين نتيجة الاحتساب الجديدة وسجل الحضور القديم في وضع الظل.',
                'metadata' => $comparison['metadata'],
            ]);
        }
        return ['stored' => true, 'status' => (string) $day['status'], 'comparison' => $comparison];
    }

    /** @param array<string,mixed> $calculation @param array<string,mixed> $resolution @return array<string,mixed> */
    private function dayFromCalculation(array $calculation, array $resolution): array
    {
        $status = (string) ($calculation['status'] ?? 'exception');
        if ($status === 'incomplete') {
            $status = 'exception';
        }
        return [
            'assignment_id' => isset($resolution['assignment']['assignment_id']) ? (int) $resolution['assignment']['assignment_id'] : null,
            'schedule_policy_version_id' => isset($resolution['selected']['version_id'])
                ? (int) $resolution['selected']['version_id']
                : null,
            'calendar_exception_id' => isset($resolution['calendar_exception']['id']) ? (int) $resolution['calendar_exception']['id'] : null,
            'expected_start' => $calculation['expected_start'] ?? null,
            'expected_end' => $calculation['expected_end'] ?? null,
            'required_minutes' => (int) ($calculation['required_minutes'] ?? 0),
            'first_in' => $calculation['first_in'] ?? null,
            'last_out' => $calculation['last_out'] ?? null,
            'worked_minutes' => (int) ($calculation['worked_minutes'] ?? 0),
            'covered_late_minutes' => (int) ($calculation['covered_late_minutes'] ?? 0),
            'covered_early_minutes' => (int) ($calculation['covered_early_minutes'] ?? 0),
            'mission_minutes' => (int) ($calculation['mission_minutes'] ?? 0),
            'leave_minutes' => (int) ($calculation['leave_minutes'] ?? 0),
            'late_minutes' => (int) ($calculation['late_minutes'] ?? 0),
            'early_leave_minutes' => (int) ($calculation['early_leave_minutes'] ?? 0),
            'missing_minutes' => (int) ($calculation['missing_minutes'] ?? 0),
            'status' => in_array($status, ['present', 'absent', 'partial', 'non_working'], true) ? $status : 'exception',
            'attendance_segments' => (array) ($calculation['attendance_segments'] ?? []),
            'reason_lines' => (array) ($calculation['reason_lines'] ?? []),
        ];
    }

    /** @param array<string,mixed> $resolution @return array<string,mixed> */
    private function emptyDay(
        int $staffUserId,
        DateTimeImmutable $workDate,
        array $resolution,
        string $status
    ): array {
        $reasonCode = (string) ($resolution['reason_code'] ?? ($status === 'non_working' ? 'NON_WORKING_DAY' : 'SCHEDULE_NOT_FOUND'));
        return [
            'assignment_id' => isset($resolution['assignment']['assignment_id']) ? (int) $resolution['assignment']['assignment_id'] : null,
            'schedule_policy_version_id' => isset($resolution['selected']['version_id'])
                ? (int) $resolution['selected']['version_id']
                : null,
            'calendar_exception_id' => isset($resolution['calendar_exception']['id']) ? (int) $resolution['calendar_exception']['id'] : null,
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
            'status' => $status,
            'attendance_segments' => [],
            'reason_lines' => [[
                'reason_code' => $reasonCode,
                'from_at' => null,
                'to_at' => null,
                'minutes' => 0,
                'source_type' => 'schedule',
                'source_id' => null,
                'explanation' => $status === 'non_working'
                    ? 'اليوم غير مؤهل للعمل وفق التقويم أو حالة العامل.'
                    : 'تعذر حل سياسة دوام قابلة للاحتساب في وضع الظل.',
            ]],
        ];
    }

    /** @param array<string,mixed> $day @param array<string,mixed>|null $legacy @return array{match:bool,difference_codes:list<string>,metadata:array<string,mixed>} */
    private function compare(array $day, ?array $legacy): array
    {
        $newStatus = (string) $day['status'];
        $metadata = [
            'new_status' => $newStatus,
            'legacy_status' => $legacy['status'] ?? null,
            'new_first_in' => $this->timeValue($day['first_in']),
            'legacy_check_in' => $this->timeValue($legacy['check_in'] ?? null),
            'new_last_out' => $this->timeValue($day['last_out']),
            'legacy_check_out' => $this->timeValue($legacy['check_out'] ?? null),
            'new_late_minutes' => (int) $day['late_minutes'],
            'legacy_late_minutes' => $legacy === null ? null : (int) ($legacy['late_minutes'] ?? 0),
            'legacy_row_count' => $legacy === null ? 0 : (int) ($legacy['legacy_row_count'] ?? 1),
        ];
        if ($legacy === null) {
            return [
                'match' => false,
                'difference_codes' => ['LEGACY_RECORD_MISSING'],
                'metadata' => $metadata,
            ];
        }
        if ((int) ($legacy['legacy_row_count'] ?? 1) !== 1) {
            return [
                'match' => false,
                'difference_codes' => ['LEGACY_RECORD_AMBIGUOUS'],
                'metadata' => $metadata,
            ];
        }
        $codes = [];
        if ($this->legacyStatus((string) ($legacy['status'] ?? '')) !== $newStatus) {
            $codes[] = 'LEGACY_STATUS_DIFFERENCE';
        }
        if ($metadata['legacy_check_in'] !== $metadata['new_first_in']) {
            $codes[] = 'LEGACY_CHECK_IN_DIFFERENCE';
        }
        if ($metadata['legacy_check_out'] !== $metadata['new_last_out']) {
            $codes[] = 'LEGACY_CHECK_OUT_DIFFERENCE';
        }
        if ((int) $metadata['legacy_late_minutes'] !== (int) $metadata['new_late_minutes']) {
            $codes[] = 'LEGACY_LATE_MINUTES_DIFFERENCE';
        }
        return ['match' => $codes === [], 'difference_codes' => $codes, 'metadata' => $metadata];
    }

    /** @param array<string,mixed> $segment @return array<string,mixed> */
    private function segment(int $dayVersionId, array $segment): array
    {
        $requiredMinutes = max(0, (int) ($segment['scheduled_minutes'] ?? 0));
        $workedMinutes = min($requiredMinutes, max(0, (int) ($segment['worked_minutes'] ?? 0)));
        $coveredMinutes = min(
            max(0, $requiredMinutes - $workedMinutes),
            max(0, (int) ($segment['covered_minutes'] ?? 0))
        );
        $missingMinutes = min(
            max(0, $requiredMinutes - $workedMinutes - $coveredMinutes),
            max(0, (int) ($segment['missing_minutes'] ?? ($requiredMinutes - $workedMinutes - $coveredMinutes)))
        );
        $eventIds = array_values(array_filter(
            array_map('intval', (array) ($segment['source_event_ids'] ?? [])),
            static fn (int $eventId): bool => $eventId > 0
        ));

        return [
            'day_version_id' => $dayVersionId,
            'sequence_no' => max(1, (int) ($segment['sequence_no'] ?? 1)),
            'segment_type' => in_array((string) ($segment['segment_type'] ?? 'work'), ['work', 'paid_break', 'unpaid_break', 'mission', 'leave', 'overtime'], true)
                ? (string) $segment['segment_type']
                : 'work',
            'expected_start' => $this->databaseValue($segment['scheduled_start'] ?? null),
            'expected_end' => $this->databaseValue($segment['scheduled_end'] ?? null),
            'actual_start' => null,
            'actual_end' => null,
            'required_minutes' => $requiredMinutes,
            'worked_minutes' => $workedMinutes,
            'covered_minutes' => $coveredMinutes,
            'missing_minutes' => $missingMinutes,
            'entry_event_id' => $eventIds === [] ? null : min($eventIds),
            'exit_event_id' => count($eventIds) < 2 ? null : max($eventIds),
            'status' => $workedMinutes === 0 && $coveredMinutes === 0
                ? 'missing'
                : ($workedMinutes + $coveredMinutes >= $requiredMinutes ? 'covered' : 'partial'),
        ];
    }

    /** @param array<string,mixed> $reason @return array<string,mixed> */
    private function reasonLine(int $dayVersionId, int $lineNo, array $reason): array
    {
        return [
            'day_version_id' => $dayVersionId,
            'line_no' => $lineNo,
            'reason_code' => (string) ($reason['reason_code'] ?? 'SHADOW_CALCULATION'),
            'from_at' => $this->databaseValue($reason['from_at'] ?? null),
            'to_at' => $this->databaseValue($reason['to_at'] ?? null),
            'minutes' => max(0, (int) ($reason['minutes'] ?? 0)),
            'source_type' => (string) ($reason['source_type'] ?? 'engine'),
            'source_id' => isset($reason['source_id']) && (int) $reason['source_id'] > 0
                ? (int) $reason['source_id']
                : null,
            'explanation' => $this->requiredText(
                (string) ($reason['explanation'] ?? 'نتيجة احتساب وضع الظل.'),
                1000,
                'ATTENDANCE_SHADOW_REASON_INVALID'
            ),
            'metadata' => null,
        ];
    }

    /** @param array<string,mixed> $resolution @return array<string,mixed> */
    private function resolutionFingerprint(array $resolution): array
    {
        $selected = is_array($resolution['selected'] ?? null) ? $resolution['selected'] : [];
        $schedule = $selected['schedule'] ?? null;
        if ($schedule instanceof WorkSchedule) {
            $schedule = $schedule->toArray();
        }
        return [
            'status' => $resolution['status'] ?? null,
            'reason_code' => $resolution['reason_code'] ?? null,
            'assignment_id' => $resolution['assignment']['assignment_id'] ?? null,
            'selected_version_id' => $selected['version_id'] ?? null,
            'selected_schedule' => $schedule ?? $selected['schedule_payload'] ?? null,
            'schedule_change_request_id' => $selected['schedule_change_request_id'] ?? null,
            'schedule_change_type' => $selected['schedule_change_type'] ?? null,
            'calendar_exception_id' => $resolution['calendar_exception']['id'] ?? null,
            'calendar_exception_type' => $resolution['calendar_exception']['exception_type'] ?? null,
        ];
    }

    /** @param list<array<string,mixed>> $events @return list<array<string,mixed>> */
    private function eventFingerprint(array $events): array
    {
        return array_map(static fn (array $event): array => [
            'id' => $event['id'] ?? null,
            'event_at_local' => $event['event_at_local'] ?? null,
            'event_type' => $event['event_type'] ?? null,
            'link_status' => $event['link_status'] ?? null,
            'review_status' => $event['review_status'] ?? null,
            'entry_method_type' => $event['entry_method_type'] ?? null,
        ], $events);
    }

    /** @param list<array<string,mixed>> $coverage @return list<array<string,mixed>> */
    private function coverageFingerprint(array $coverage): array
    {
        return array_map(static function (array $item): array {
            $from = $item['from_at'] ?? null;
            $to = $item['to_at'] ?? null;
            return [
                'source_type' => $item['source_type'] ?? null,
                'source_id' => $item['source_id'] ?? null,
                'coverage_behavior' => $item['coverage_behavior'] ?? null,
                'source_version_id' => $item['source_version_id'] ?? null,
                'from_at' => $from instanceof DateTimeInterface ? $from->format('Y-m-d H:i:s.uP') : null,
                'to_at' => $to instanceof DateTimeInterface ? $to->format('Y-m-d H:i:s.uP') : null,
            ];
        }, $coverage);
    }

    /** @param array<string,mixed>|null $legacy @return array<string,mixed>|null */
    private function legacyFingerprint(?array $legacy): ?array
    {
        if ($legacy === null) {
            return null;
        }
        return [
            'id' => $legacy['id'] ?? null,
            'status' => $legacy['status'] ?? null,
            'check_in' => $legacy['check_in'] ?? null,
            'check_out' => $legacy['check_out'] ?? null,
            'late_minutes' => $legacy['late_minutes'] ?? null,
            'legacy_row_count' => $legacy['legacy_row_count'] ?? 1,
        ];
    }

    private function legacyStatus(string $legacyStatus): string
    {
        return match ($legacyStatus) {
            'present' => 'present',
            'absent' => 'absent',
            'late', 'excused' => 'partial',
            default => 'exception',
        };
    }

    private function timeValue(mixed $value): ?string
    {
        if ($value instanceof DateTimeInterface) {
            return $value->format('H:i');
        }
        $text = trim((string) $value);
        if ($text === '') {
            return null;
        }
        if (preg_match('/(?:^|\s)([0-2]\d:[0-5]\d)(?::[0-5]\d(?:\.\d{1,6})?)?$/', $text, $matches) === 1) {
            return $matches[1];
        }
        return null;
    }

    /** @param list<int> $staffUserIds @return list<int> */
    private function normalizeStaffIds(array $staffUserIds): array
    {
        $ids = [];
        foreach ($staffUserIds as $staffUserId) {
            if (!is_int($staffUserId) && !(is_string($staffUserId) && preg_match('/^\d+$/D', $staffUserId) === 1)) {
                throw new InvalidArgumentException('ATTENDANCE_SHADOW_STAFF_ID_INVALID');
            }
            $staffUserId = (int) $staffUserId;
            if ($staffUserId <= 0) {
                throw new InvalidArgumentException('ATTENDANCE_SHADOW_STAFF_ID_INVALID');
            }
            $ids[$staffUserId] = $staffUserId;
        }
        if ($ids === [] || count($ids) > 1000) {
            throw new InvalidArgumentException('ATTENDANCE_SHADOW_STAFF_POPULATION_INVALID');
        }
        ksort($ids, SORT_NUMERIC);
        return array_values($ids);
    }

    /** @return list<DateTimeImmutable> */
    private function dates(DateTimeImmutable $from, DateTimeImmutable $to): array
    {
        $dates = [];
        for ($cursor = $from; $cursor <= $to; $cursor = $cursor->modify('+1 day')) {
            $dates[] = $cursor;
            if (count($dates) > 366) {
                throw new InvalidArgumentException('ATTENDANCE_SHADOW_RANGE_TOO_LARGE');
            }
        }
        return $dates;
    }

    private function databaseValue(mixed $value): ?string
    {
        if ($value === null) {
            return null;
        }
        if ($value instanceof DateTimeInterface) {
            return DateTimeImmutable::createFromInterface($value)->format('Y-m-d H:i:s.u');
        }
        $value = trim((string) $value);
        return $value === '' ? null : $value;
    }

    /** @return array<string,mixed> */
    private function decodeSummary(mixed $summary): array
    {
        if (is_array($summary)) {
            return $summary;
        }
        if (is_string($summary)) {
            try {
                $decoded = json_decode($summary, true, 512, JSON_THROW_ON_ERROR);
                if (is_array($decoded)) {
                    return $decoded;
                }
            } catch (JsonException) {
            }
        }
        throw new DomainException('ATTENDANCE_SHADOW_RECEIPT_CORRUPT');
    }

    private function hash(array $payload): string
    {
        try {
            return hash('sha256', json_encode(
                $this->canonicalize($payload),
                JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
            ));
        } catch (JsonException $exception) {
            throw new InvalidArgumentException('ATTENDANCE_SHADOW_FINGERPRINT_INVALID', 0, $exception);
        }
    }

    private function canonicalize(mixed $value): mixed
    {
        if (!is_array($value)) {
            return $value;
        }
        if ($value !== [] && array_keys($value) !== range(0, count($value) - 1)) {
            ksort($value, SORT_STRING);
        }
        foreach ($value as $key => $item) {
            $value[$key] = $this->canonicalize($item);
        }
        return $value;
    }

    private function requiredText(string $value, int $maximum, string $error): string
    {
        $value = trim($value);
        if ($value === '' || mb_strlen($value, 'UTF-8') > $maximum) {
            throw new InvalidArgumentException($error);
        }
        return $value;
    }

    private function databaseInstant(DateTimeImmutable $instant): string
    {
        return $instant->setTimezone($this->utc)->format('Y-m-d H:i:s.u');
    }
}
