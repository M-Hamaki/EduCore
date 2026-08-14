<?php

declare(strict_types=1);

namespace EduCore\Modules\Attendance\Application;

use DateTimeImmutable;
use DateTimeInterface;
use DateTimeZone;
use DomainException;
use EduCore\Modules\Attendance\Contracts\ApprovedCoverageQuery;
use EduCore\Modules\Attendance\Contracts\AttendanceEventWindowQuery;
use EduCore\Modules\Attendance\Contracts\AttendanceRecalculationRepository;
use EduCore\Modules\Attendance\Contracts\AttendanceTransactionManager;
use EduCore\Modules\Attendance\Contracts\EffectiveScheduleQuery;
use EduCore\Modules\Attendance\Domain\Calculation\AttendanceDayCalculator;
use EduCore\Modules\Attendance\Domain\Schedule\WorkSchedule;
use EduCore\Modules\Operations\Audit\AuditEventWriter;
use InvalidArgumentException;
use JsonException;

/**
 * Creates immutable official successors for explicitly affected attendance
 * days. It never mutates raw events or a prior calculated version.
 */
final class AttendanceRecalculationService
{
    public const ENGINE_VERSION = 'attendance-recalculation-v1';

    private DateTimeZone $utc;

    public function __construct(
        private AttendanceTransactionManager $transactions,
        private AttendanceRecalculationRepository $repository,
        private EffectiveScheduleQuery $schedules,
        private AttendanceEventWindowQuery $events,
        private ApprovedCoverageQuery $coverage,
        private AttendanceDayCalculator $calculator,
        private AuditEventWriter $audit
    ) {
        $this->utc = new DateTimeZone('UTC');
    }

    /** @return array<string,mixed> */
    public function recalculate(
        int $actorId,
        int $staffUserId,
        DateTimeImmutable $workDate,
        string $triggerCode,
        string $idempotencyKey
    ): array {
        return $this->calculate($actorId, $staffUserId, $workDate, $triggerCode, $idempotencyKey, false);
    }

    /** Publish the first official immutable version for a day that has no predecessor. */
    public function calculateInitial(
        int $actorId,
        int $staffUserId,
        DateTimeImmutable $workDate,
        string $triggerCode,
        string $idempotencyKey
    ): array {
        return $this->calculate($actorId, $staffUserId, $workDate, $triggerCode, $idempotencyKey, true);
    }

    /** @return array<string,mixed> */
    private function calculate(
        int $actorId,
        int $staffUserId,
        DateTimeImmutable $workDate,
        string $triggerCode,
        string $idempotencyKey,
        bool $initial
    ): array {
        if ($actorId <= 0) {
            throw new InvalidArgumentException('ATTENDANCE_RECALCULATION_ACTOR_INVALID');
        }
        if ($staffUserId <= 0) {
            throw new InvalidArgumentException('ATTENDANCE_RECALCULATION_STAFF_ID_INVALID');
        }
        $workDate = $workDate->setTime(0, 0, 0, 0);
        $triggerCode = $this->triggerCode($triggerCode);
        $idempotencyKey = $this->requiredText(
            $idempotencyKey,
            190,
            'ATTENDANCE_RECALCULATION_IDEMPOTENCY_KEY_INVALID'
        );
        $commandFingerprint = $this->hash([
            'actor_id' => $actorId,
            'staff_user_id' => $staffUserId,
            'work_date' => $workDate->format('Y-m-d'),
            'trigger_code' => $triggerCode,
            'initial' => $initial,
            'engine_version' => self::ENGINE_VERSION,
        ]);
        $now = new DateTimeImmutable('now', $this->utc);

        return $this->transactions->transactional(function () use (
            $actorId,
            $staffUserId,
            $workDate,
            $triggerCode,
            $idempotencyKey,
            $commandFingerprint,
            $now,
            $initial
        ): array {
            $existing = $this->repository->runByIdempotencyForUpdate($idempotencyKey);
            if ($existing !== null) {
                if (
                    (string) ($existing['mode'] ?? '') !== 'recalculation'
                    || (string) ($existing['engine_version'] ?? '') !== self::ENGINE_VERSION
                    || !hash_equals((string) ($existing['source_fingerprint'] ?? ''), $commandFingerprint)
                ) {
                    throw new DomainException('ATTENDANCE_RECALCULATION_IDEMPOTENCY_CONFLICT');
                }

                return $this->replayedReceipt($existing);
            }

            $before = $this->repository->currentOfficialDayForUpdate(
                $staffUserId,
                $workDate->format('Y-m-d')
            );
            if ($before === null && !$initial) {
                throw new DomainException('ATTENDANCE_RECALCULATION_OFFICIAL_DAY_NOT_FOUND');
            }
            if ($before !== null && $initial) {
                throw new DomainException('ATTENDANCE_INITIAL_OFFICIAL_DAY_EXISTS');
            }
            $inputs = $this->calculationInputs($staffUserId, $workDate);
            $calculation = $inputs['calculation'];
            $day = $this->dayFromCalculation($calculation, $inputs['resolution']);
            $this->assertOfficializable($day);
            $candidateFingerprint = $this->hash([
                'staff_user_id' => $staffUserId,
                'work_date' => $workDate->format('Y-m-d'),
                'resolution' => $this->resolutionFingerprint($inputs['resolution']),
                'events' => $this->eventFingerprint($inputs['events']),
                'approved_coverage' => $this->coverageFingerprint($inputs['coverage']),
                'calculator_engine_version' => $calculation['engine_version'] ?? AttendanceDayCalculator::ENGINE_VERSION,
            ]);
            $changesOfficialDay = $initial || !hash_equals(
                (string) ($before['source_fingerprint'] ?? ''),
                $candidateFingerprint
            );

            $runId = $this->repository->insertRecalculationRun([
                'engine_version' => self::ENGINE_VERSION,
                'mode' => 'recalculation',
                'range_from' => $workDate->format('Y-m-d'),
                'range_to' => $workDate->format('Y-m-d'),
                'cutoff_at' => $this->databaseInstant($now),
                'initiated_by' => $actorId,
                'status' => 'queued',
                'source_fingerprint' => $commandFingerprint,
                'idempotency_key' => $idempotencyKey,
                'supersedes_run_id' => $changesOfficialDay && (int) ($before['run_id'] ?? 0) > 0
                    ? (int) $before['run_id']
                    : null,
            ]);
            if (!$this->repository->startRecalculationRun($runId, $now)) {
                throw new DomainException('ATTENDANCE_RECALCULATION_RUN_STALE');
            }

            if ($before !== null && hash_equals((string) ($before['source_fingerprint'] ?? ''), $candidateFingerprint)) {
                $summary = [
                    'result' => 'no_change',
                    'staff_user_id' => $staffUserId,
                    'work_date' => $workDate->format('Y-m-d'),
                    'trigger_code' => $triggerCode,
                    'before_day_version_id' => (int) ($before['id'] ?? 0),
                    'day_version_id' => (int) ($before['id'] ?? 0),
                    'candidate_fingerprint' => $candidateFingerprint,
                ];
                if (!$this->repository->completeRecalculationRun($runId, $now, $summary)) {
                    throw new DomainException('ATTENDANCE_RECALCULATION_RUN_STALE');
                }
                $this->audit->recordEvent(
                    'staff_attendance_recalculation_no_change',
                    'staff_attendance_runs',
                    $runId,
                    null,
                    $summary,
                    ['user_id' => $actorId, 'occurred_at' => $this->databaseInstant($now)]
                );

                return $this->receipt($runId, $summary, false);
            }

            $beforeId = (int) ($before['id'] ?? 0);
            if (!$initial && ($beforeId <= 0 || !$this->repository->retireOfficialDay($beforeId))) {
                throw new DomainException('ATTENDANCE_RECALCULATION_OFFICIAL_DAY_STALE');
            }
            $dayVersionId = $this->repository->insertRecalculatedDay([
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
                'calculation_mode' => 'recalculation',
                'engine_version' => self::ENGINE_VERSION,
                'source_fingerprint' => $candidateFingerprint,
                'is_official' => 0,
                'officialized_by' => null,
                'officialized_at' => null,
                'supersedes_id' => $beforeId > 0 ? $beforeId : null,
                'calculated_at' => $this->databaseInstant($now),
            ]);
            foreach ($day['attendance_segments'] as $segment) {
                $this->repository->appendSegment($this->segment($dayVersionId, $segment));
            }
            $lineNo = 0;
            foreach ($day['reason_lines'] as $reason) {
                $this->repository->appendReasonLine($this->reasonLine($dayVersionId, ++$lineNo, $reason));
            }
            $this->repository->appendReasonLine([
                'day_version_id' => $dayVersionId,
                'line_no' => ++$lineNo,
                'reason_code' => $initial ? 'INITIAL_OFFICIAL_CALCULATION' : 'RECALCULATED_FROM_OFFICIAL_VERSION',
                'from_at' => null,
                'to_at' => null,
                'minutes' => 0,
                'source_type' => $initial ? 'staff_attendance_run' : 'staff_attendance_day_version',
                'source_id' => $initial ? $runId : $beforeId,
                'explanation' => $initial
                    ? 'النسخة الرسمية الأولى لليوم أُنشئت من الجدول والبصمات والتغطيات المؤرخة مع حفظ الجولة المولدة لها.'
                    : 'نسخة حضور جديدة أنشئت بإعادة احتساب موثقة؛ النسخة الرسمية السابقة محفوظة للرجوع والمراجعة.',
                'metadata' => null,
            ]);
            $summary = [
                'result' => $initial ? 'calculated' : 'recalculated',
                'staff_user_id' => $staffUserId,
                'work_date' => $workDate->format('Y-m-d'),
                'trigger_code' => $triggerCode,
                'before_day_version_id' => $beforeId,
                'day_version_id' => $dayVersionId,
                'candidate_fingerprint' => $candidateFingerprint,
            ];
            if (!$this->repository->completeRecalculationRun($runId, $now, $summary)) {
                throw new DomainException('ATTENDANCE_RECALCULATION_RUN_STALE');
            }
            if (!$this->repository->publishRecalculatedDay($dayVersionId, $actorId, $now)) {
                throw new DomainException('ATTENDANCE_RECALCULATION_OFFICIAL_DAY_STALE');
            }
            $this->audit->recordEvent(
                $initial ? 'staff_attendance_day_initially_calculated' : 'staff_attendance_day_recalculated',
                'staff_attendance_day_versions',
                $dayVersionId,
                null,
                $summary,
                ['user_id' => $actorId, 'occurred_at' => $this->databaseInstant($now)]
            );

            return $this->receipt($runId, $summary, false);
        });
    }

    /**
     * @return array{calculation:array<string,mixed>,events:list<array<string,mixed>>,coverage:list<array<string,mixed>>,resolution:array<string,mixed>}
     */
    private function calculationInputs(int $staffUserId, DateTimeImmutable $workDate): array
    {
        $resolution = $this->schedules->forStaffDate($staffUserId, $workDate);
        $status = (string) ($resolution['status'] ?? '');
        if ($status === 'non_working') {
            return [
                'calculation' => $this->nonWorkingCalculation($staffUserId, $workDate),
                'events' => [],
                'coverage' => [],
                'resolution' => $resolution,
            ];
        }
        $selected = is_array($resolution['selected'] ?? null) ? $resolution['selected'] : [];
        $schedule = $selected['schedule'] ?? null;
        if ($status !== 'working' || !$schedule instanceof WorkSchedule) {
            throw new DomainException('ATTENDANCE_RECALCULATION_SCHEDULE_UNRESOLVED');
        }
        $window = $schedule->workWindow($workDate);
        if ($window === null) {
            return [
                'calculation' => $this->nonWorkingCalculation($staffUserId, $workDate),
                'events' => [],
                'coverage' => [],
                'resolution' => $resolution,
            ];
        }
        $events = $this->events->forStaffWindow(
            $staffUserId,
            $window['entry_capture_start'],
            $window['exit_capture_end']
        );
        $coverage = $this->coverage->forStaffWindow($staffUserId, $window['start'], $window['end']);

        return [
            'calculation' => $this->calculator->calculate($staffUserId, $workDate, $schedule, $events, $coverage),
            'events' => $events,
            'coverage' => $coverage,
            'resolution' => $resolution,
        ];
    }

    /** @return array<string,mixed> */
    private function nonWorkingCalculation(int $staffUserId, DateTimeImmutable $workDate): array
    {
        return [
            'engine_version' => AttendanceDayCalculator::ENGINE_VERSION,
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
                'explanation' => 'اليوم غير مؤهل للعمل وفق التقويم أو الدوام الفعال.',
            ]],
        ];
    }

    /** @param array<string,mixed> $calculation @param array<string,mixed> $resolution @return array<string,mixed> */
    private function dayFromCalculation(array $calculation, array $resolution): array
    {
        return [
            'assignment_id' => isset($resolution['assignment']['assignment_id'])
                ? (int) $resolution['assignment']['assignment_id']
                : null,
            'schedule_policy_version_id' => isset($resolution['selected']['version_id'])
                ? (int) $resolution['selected']['version_id']
                : null,
            'calendar_exception_id' => isset($resolution['calendar_exception']['id'])
                ? (int) $resolution['calendar_exception']['id']
                : null,
            'expected_start' => $calculation['expected_start'] ?? null,
            'expected_end' => $calculation['expected_end'] ?? null,
            'required_minutes' => max(0, (int) ($calculation['required_minutes'] ?? 0)),
            'first_in' => $calculation['first_in'] ?? null,
            'last_out' => $calculation['last_out'] ?? null,
            'worked_minutes' => max(0, (int) ($calculation['worked_minutes'] ?? 0)),
            'covered_late_minutes' => max(0, (int) ($calculation['covered_late_minutes'] ?? 0)),
            'covered_early_minutes' => max(0, (int) ($calculation['covered_early_minutes'] ?? 0)),
            'mission_minutes' => max(0, (int) ($calculation['mission_minutes'] ?? 0)),
            'leave_minutes' => max(0, (int) ($calculation['leave_minutes'] ?? 0)),
            'late_minutes' => max(0, (int) ($calculation['late_minutes'] ?? 0)),
            'early_leave_minutes' => max(0, (int) ($calculation['early_leave_minutes'] ?? 0)),
            'missing_minutes' => max(0, (int) ($calculation['missing_minutes'] ?? 0)),
            'status' => (string) ($calculation['status'] ?? 'unresolved'),
            'attendance_segments' => (array) ($calculation['attendance_segments'] ?? []),
            'reason_lines' => (array) ($calculation['reason_lines'] ?? []),
        ];
    }

    /** @param array<string,mixed> $day */
    private function assertOfficializable(array $day): void
    {
        if (!in_array((string) ($day['status'] ?? ''), ['present', 'absent', 'partial', 'non_working'], true)) {
            throw new DomainException('ATTENDANCE_RECALCULATION_INCOMPLETE_EVIDENCE');
        }
        $required = (int) ($day['required_minutes'] ?? 0);
        foreach ([
            'worked_minutes',
            'covered_late_minutes',
            'covered_early_minutes',
            'mission_minutes',
            'leave_minutes',
            'late_minutes',
            'early_leave_minutes',
            'missing_minutes',
        ] as $field) {
            if ((int) ($day[$field] ?? 0) < 0 || (int) ($day[$field] ?? 0) > $required) {
                throw new DomainException('ATTENDANCE_RECALCULATION_RESULT_INVALID');
            }
        }
    }

    /** @param array<string,mixed> $segment @return array<string,mixed> */
    private function segment(int $dayVersionId, array $segment): array
    {
        $required = max(0, (int) ($segment['scheduled_minutes'] ?? 0));
        $worked = min($required, max(0, (int) ($segment['worked_minutes'] ?? 0)));
        $covered = min(max(0, $required - $worked), max(0, (int) ($segment['covered_minutes'] ?? 0)));
        $missing = min(
            max(0, $required - $worked - $covered),
            max(0, (int) ($segment['missing_minutes'] ?? ($required - $worked - $covered)))
        );
        $eventIds = array_values(array_filter(
            array_map('intval', (array) ($segment['source_event_ids'] ?? [])),
            static fn (int $value): bool => $value > 0
        ));

        return [
            'day_version_id' => $dayVersionId,
            'sequence_no' => max(1, (int) ($segment['sequence_no'] ?? 1)),
            'segment_type' => in_array(
                (string) ($segment['segment_type'] ?? 'work'),
                ['work', 'paid_break', 'unpaid_break', 'mission', 'leave', 'overtime'],
                true
            ) ? (string) $segment['segment_type'] : 'work',
            'expected_start' => $this->databaseValue($segment['scheduled_start'] ?? null),
            'expected_end' => $this->databaseValue($segment['scheduled_end'] ?? null),
            'actual_start' => null,
            'actual_end' => null,
            'required_minutes' => $required,
            'worked_minutes' => $worked,
            'covered_minutes' => $covered,
            'missing_minutes' => $missing,
            'entry_event_id' => $eventIds === [] ? null : min($eventIds),
            'exit_event_id' => count($eventIds) < 2 ? null : max($eventIds),
            'status' => $worked === 0 && $covered === 0
                ? 'missing'
                : ($worked + $covered >= $required ? 'covered' : 'partial'),
        ];
    }

    /** @param array<string,mixed> $reason @return array<string,mixed> */
    private function reasonLine(int $dayVersionId, int $lineNo, array $reason): array
    {
        return [
            'day_version_id' => $dayVersionId,
            'line_no' => $lineNo,
            'reason_code' => $this->requiredText(
                (string) ($reason['reason_code'] ?? 'ATTENDANCE_RECALCULATION'),
                80,
                'ATTENDANCE_RECALCULATION_REASON_INVALID'
            ),
            'from_at' => $this->databaseValue($reason['from_at'] ?? null),
            'to_at' => $this->databaseValue($reason['to_at'] ?? null),
            'minutes' => max(0, (int) ($reason['minutes'] ?? 0)),
            'source_type' => $this->requiredText(
                (string) ($reason['source_type'] ?? 'attendance_calculation'),
                50,
                'ATTENDANCE_RECALCULATION_REASON_INVALID'
            ),
            'source_id' => isset($reason['source_id']) && (int) $reason['source_id'] > 0
                ? (int) $reason['source_id']
                : null,
            'explanation' => $this->requiredText(
                (string) ($reason['explanation'] ?? 'نتيجة إعادة احتساب حضور موثقة.'),
                1000,
                'ATTENDANCE_RECALCULATION_REASON_INVALID'
            ),
            'metadata' => null,
        ];
    }

    /** @param array<string,mixed> $run @return array<string,mixed> */
    private function replayedReceipt(array $run): array
    {
        return $this->receipt(
            (int) ($run['id'] ?? 0),
            $this->decodeSummary($run['summary'] ?? null),
            true
        );
    }

    /** @param array<string,mixed> $summary @return array<string,mixed> */
    private function receipt(int $runId, array $summary, bool $replayed): array
    {
        return [
            'run_id' => $runId,
            'staff_user_id' => (int) ($summary['staff_user_id'] ?? 0),
            'work_date' => (string) ($summary['work_date'] ?? ''),
            'trigger_code' => (string) ($summary['trigger_code'] ?? ''),
            'before_day_version_id' => (int) ($summary['before_day_version_id'] ?? 0),
            'day_version_id' => (int) ($summary['day_version_id'] ?? 0),
            'recalculated' => (string) ($summary['result'] ?? '') === 'recalculated',
            'calculated' => (string) ($summary['result'] ?? '') === 'calculated',
            'no_change' => (string) ($summary['result'] ?? '') === 'no_change',
            'replayed' => $replayed,
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
        throw new DomainException('ATTENDANCE_RECALCULATION_RECEIPT_CORRUPT');
    }

    private function triggerCode(string $value): string
    {
        $value = trim($value);
        if (preg_match('/^[A-Z][A-Z0-9_]{2,79}$/D', $value) !== 1) {
            throw new InvalidArgumentException('ATTENDANCE_RECALCULATION_TRIGGER_INVALID');
        }
        return $value;
    }

    private function requiredText(string $value, int $maximumLength, string $error): string
    {
        $value = trim($value);
        if ($value === '' || mb_strlen($value, 'UTF-8') > $maximumLength) {
            throw new InvalidArgumentException($error);
        }
        return $value;
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

    private function databaseInstant(DateTimeImmutable $value): string
    {
        return $value->setTimezone($this->utc)->format('Y-m-d H:i:s.u');
    }

    /** @param array<string,mixed> $payload */
    private function hash(array $payload): string
    {
        try {
            return hash('sha256', json_encode(
                $this->canonicalize($payload),
                JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
            ));
        } catch (JsonException $exception) {
            throw new InvalidArgumentException('ATTENDANCE_RECALCULATION_FINGERPRINT_INVALID', 0, $exception);
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
}
