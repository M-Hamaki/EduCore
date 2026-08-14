<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/vendor/autoload.php';

use EduCore\Modules\Attendance\Application\AttendanceShadowRunService;
use EduCore\Modules\Attendance\Contracts\AttendanceEventWindowQuery;
use EduCore\Modules\Attendance\Contracts\ApprovedCoverageQuery;
use EduCore\Modules\Attendance\Contracts\AttendanceShadowRunRepository;
use EduCore\Modules\Attendance\Contracts\AttendanceTransactionManager;
use EduCore\Modules\Attendance\Contracts\EffectiveScheduleQuery;
use EduCore\Modules\Attendance\Contracts\LegacyStaffAttendanceDayQuery;
use EduCore\Modules\Attendance\Domain\Calculation\AttendanceDayCalculator;
use EduCore\Modules\Attendance\Domain\Calculation\PunchWindowMatcher;
use EduCore\Modules\Attendance\Domain\Schedule\WorkSchedule;
use EduCore\Modules\Operations\Audit\AuditEventWriter;

final class AttendanceShadowTestTransaction implements AttendanceTransactionManager
{
    public function transactional(callable $operation): mixed
    {
        return $operation();
    }
}

final class AttendanceShadowTestRepository implements AttendanceShadowRunRepository
{
    /** @var array<int,array<string,mixed>> */
    public array $runs = [];

    /** @var array<int,array<string,mixed>> */
    public array $days = [];

    /** @var list<array<string,mixed>> */
    public array $segments = [];

    /** @var list<array<string,mixed>> */
    public array $reasons = [];

    private int $nextRunId = 1;
    private int $nextDayId = 1;

    public function runByIdempotencyForUpdate(string $idempotencyKey): ?array
    {
        foreach ($this->runs as $run) {
            if (($run['idempotency_key'] ?? null) === $idempotencyKey) {
                return $run;
            }
        }

        return null;
    }

    public function insertShadowRun(array $run): int
    {
        $id = $this->nextRunId++;
        $this->runs[$id] = ['id' => $id] + $run;
        return $id;
    }

    public function startShadowRun(int $runId, DateTimeImmutable $startedAt): bool
    {
        if (!isset($this->runs[$runId]) || ($this->runs[$runId]['status'] ?? null) !== 'queued') {
            return false;
        }
        $this->runs[$runId]['status'] = 'running';
        $this->runs[$runId]['started_at'] = $startedAt->format('Y-m-d H:i:s.u');
        return true;
    }

    public function completeShadowRun(int $runId, DateTimeImmutable $finishedAt, array $summary): bool
    {
        if (!isset($this->runs[$runId]) || ($this->runs[$runId]['status'] ?? null) !== 'running') {
            return false;
        }
        $this->runs[$runId]['status'] = 'completed';
        $this->runs[$runId]['finished_at'] = $finishedAt->format('Y-m-d H:i:s.u');
        $this->runs[$runId]['summary'] = $summary;
        return true;
    }

    public function shadowDayBySourceForUpdate(
        int $staffUserId,
        string $workDate,
        string $sourceFingerprint,
        string $engineVersion
    ): ?array {
        foreach ($this->days as $day) {
            if ((int) ($day['staff_user_id'] ?? 0) === $staffUserId
                && ($day['work_date'] ?? null) === $workDate
                && ($day['source_fingerprint'] ?? null) === $sourceFingerprint
                && ($day['engine_version'] ?? null) === $engineVersion
                && ($day['calculation_mode'] ?? null) === 'shadow') {
                return $day;
            }
        }

        return null;
    }

    public function nextDayVersionNoForUpdate(int $staffUserId, string $workDate): int
    {
        $latest = 0;
        foreach ($this->days as $day) {
            if ((int) ($day['staff_user_id'] ?? 0) === $staffUserId && ($day['work_date'] ?? null) === $workDate) {
                $latest = max($latest, (int) ($day['version_no'] ?? 0));
            }
        }

        return $latest + 1;
    }

    public function insertShadowDay(array $day): int
    {
        $id = $this->nextDayId++;
        $this->days[$id] = ['id' => $id] + $day;
        return $id;
    }

    public function appendSegment(array $segment): void
    {
        $this->segments[] = $segment;
    }

    public function appendReasonLine(array $reasonLine): void
    {
        $this->reasons[] = $reasonLine;
    }
}

final class AttendanceShadowTestScheduleQuery implements EffectiveScheduleQuery
{
    public bool $nonWorking = false;
    public bool $unresolved = false;

    public function __construct(public WorkSchedule $schedule)
    {
    }

    public function forStaffDate(int $staffId, DateTimeImmutable $workDate): array
    {
        if ($this->unresolved) {
            return [
                'status' => 'unresolved',
                'reason_code' => 'SCHEDULE_CONFLICT',
                'assignment' => ['assignment_id' => 711],
                'selected' => null,
                'calendar_exception' => null,
            ];
        }
        if ($this->nonWorking) {
            return [
                'status' => 'non_working',
                'reason_code' => 'CALENDAR_HOLIDAY',
                'assignment' => ['assignment_id' => 711],
                'selected' => [
                    'version_id' => 812,
                    'schedule' => $this->schedule,
                    'schedule_payload' => $this->schedule->toArray(),
                ],
                'calendar_exception' => ['id' => 913],
            ];
        }

        return [
            'status' => 'working',
            'reason_code' => 'EFFECTIVE_SCHEDULE_RESOLVED',
            'assignment' => ['assignment_id' => 711],
            'selected' => [
                'version_id' => 812,
                'schedule' => $this->schedule,
                'schedule_payload' => $this->schedule->toArray(),
            ],
            'calendar_exception' => null,
        ];
    }
}

final class AttendanceShadowTestEvents implements AttendanceEventWindowQuery
{
    /** @var array<int,list<array<string,mixed>>> */
    public array $eventsByStaff = [];

    public function forStaffWindow(
        int $staffUserId,
        DateTimeImmutable $windowStart,
        DateTimeImmutable $windowEnd
    ): array {
        return $this->eventsByStaff[$staffUserId] ?? [];
    }
}

final class AttendanceShadowTestCoverage implements ApprovedCoverageQuery
{
    /** @var array<int,list<array<string,mixed>>> */
    public array $coverageByStaff = [];

    public function forStaffWindow(
        int $staffUserId,
        DateTimeImmutable $windowStart,
        DateTimeImmutable $windowEnd
    ): array {
        return $this->coverageByStaff[$staffUserId] ?? [];
    }
}

final class AttendanceShadowTestLegacyDays implements LegacyStaffAttendanceDayQuery
{
    /** @var array<string,array<string,mixed>> */
    public array $rows = [];

    public function forStaffDate(int $staffUserId, string $workDate): ?array
    {
        return $this->rows[$staffUserId . ':' . $workDate] ?? null;
    }
}

final class AttendanceShadowTestAudit implements AuditEventWriter
{
    /** @var list<array<string,mixed>> */
    public array $events = [];

    public function recordEvent(
        string $action,
        ?string $entityType,
        mixed $recordId,
        ?string $name,
        array $details = [],
        array $context = []
    ): void {
        $this->events[] = compact('action', 'entityType', 'recordId', 'details', 'context');
    }
}

$failures = 0;
$assert = static function (bool $condition, string $message) use (&$failures): void {
    if (!$condition) {
        fwrite(STDERR, "FAIL: {$message}\n");
        ++$failures;
    }
};
$throws = static function (callable $operation, string $expected, string $message) use (&$failures): void {
    try {
        $operation();
        fwrite(STDERR, "FAIL: {$message} (no exception)\n");
        ++$failures;
    } catch (Throwable $exception) {
        if ($exception->getMessage() !== $expected) {
            fwrite(STDERR, "FAIL: {$message} (got {$exception->getMessage()})\n");
            ++$failures;
        }
    }
};
$countCode = static function (array $lines, string $code): int {
    return count(array_filter($lines, static fn (array $line): bool => ($line['reason_code'] ?? null) === $code));
};
$schedule = static function (string $end = '14:30', int $requiredMinutes = 420): WorkSchedule {
    return WorkSchedule::fromArray([
        'timezone' => 'Africa/Cairo',
        'days' => [[
            'weekday' => 1,
            'is_working_day' => true,
            'start_time' => '07:30',
            'end_time' => $end,
            'end_day_offset' => 0,
            'required_minutes' => $requiredMinutes,
            'late_grace_minutes' => 0,
            'early_grace_minutes' => 0,
            'entry_window_before_minutes' => 60,
            'entry_window_after_minutes' => 240,
            'exit_window_before_minutes' => 240,
            'exit_window_after_minutes' => 60,
        ]],
    ]);
};

$repository = new AttendanceShadowTestRepository();
$schedules = new AttendanceShadowTestScheduleQuery($schedule());
$events = new AttendanceShadowTestEvents();
$events->eventsByStaff[501] = [
    [
        'id' => 1,
        'event_at_local' => '2026-01-05 07:25:00',
        'event_type' => 'in',
        'link_status' => 'matched',
        'review_status' => 'not_required',
        'entry_method_type' => 'biometric',
        'raw_payload_ref' => 'private/raw/sensitive-payload.json',
    ],
    [
        'id' => 2,
        'event_at_local' => '2026-01-05 07:26:00',
        'event_type' => 'in',
        'link_status' => 'matched',
        'review_status' => 'not_required',
        'entry_method_type' => 'biometric',
    ],
    [
        'id' => 3,
        'event_at_local' => '2026-01-05 14:30:00',
        'event_type' => 'out',
        'link_status' => 'matched',
        'review_status' => 'not_required',
        'entry_method_type' => 'biometric',
    ],
];
$legacy = new AttendanceShadowTestLegacyDays();
$legacy->rows['501:2026-01-05'] = [
    'id' => 101,
    'status' => 'present',
    'check_in' => '2026-01-05 07:25:00.000000',
    'check_out' => '2026-01-05 14:30:00.000000',
    'late_minutes' => 0,
];
$audit = new AttendanceShadowTestAudit();
$coverage = new AttendanceShadowTestCoverage();
$service = new AttendanceShadowRunService(
    new AttendanceShadowTestTransaction(),
    $repository,
    $schedules,
    $events,
    $legacy,
    new AttendanceDayCalculator(new PunchWindowMatcher()),
    $audit,
    $coverage
);
$monday = new DateTimeImmutable('2026-01-05 00:00:00', new DateTimeZone('Africa/Cairo'));

$first = $service->run(900, [501], $monday, $monday, 'shadow-run-one');
$assert(($first['replayed'] ?? null) === false, 'first shadow run is not a replay');
$assert(($first['summary']['legacy_matches'] ?? null) === 1, 'matching legacy result is classified as a match');
$assert(($first['summary']['legacy_differences'] ?? null) === 0, 'matching legacy result has no difference count');
$assert(count($repository->runs) === 1 && ($repository->runs[1]['mode'] ?? null) === 'shadow', 'shadow run is persisted in shadow mode only');
$assert(($repository->runs[1]['status'] ?? null) === 'completed', 'shadow run becomes completed after all comparison rows are stored');
$assert(count($repository->days) === 1, 'first comparison stores a derived day result');
$dayOne = $repository->days[1] ?? [];
$assert(($dayOne['calculation_mode'] ?? null) === 'shadow' && (int) ($dayOne['is_official'] ?? 1) === 0, 'shadow result is never an official day result');
$assert((int) ($dayOne['schedule_policy_version_id'] ?? 0) === 812, 'shadow result snapshots the effective schedule version identifier');
$assert(count($repository->segments) === 1, 'shadow result persists its calculated attendance segment for explainability');
$assert($countCode($repository->reasons, 'DUPLICATE_ENTRY_PUNCH') === 1, 'unusable punch reason is persisted exactly once');
$assert($countCode($repository->reasons, 'LEGACY_STATUS_DIFFERENCE') === 0, 'matching day stores no legacy-difference reason');
$auditText = json_encode($audit->events, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
$assert(!str_contains((string) $auditText, 'sensitive-payload.json'), 'shadow audit stores no raw payload reference');
$assert(($audit->events[0]['action'] ?? null) === 'staff_attendance_shadow_run_completed', 'completed shadow run is written to shared audit');

$replay = $service->run(900, [501], $monday, $monday, 'shadow-run-one');
$assert(($replay['replayed'] ?? null) === true, 'same shadow request is idempotently replayed');
$assert(count($repository->runs) === 1 && count($repository->days) === 1, 'idempotent replay creates no extra run or day');
$throws(
    static fn () => $service->run(900, [501, 502], $monday, $monday, 'shadow-run-one'),
    'ATTENDANCE_SHADOW_IDEMPOTENCY_CONFLICT',
    'same idempotency key with a different bounded population fails closed'
);

$schedules->schedule = $schedule('14:00', 390);
$rescheduled = $service->run(900, [501], $monday, $monday, 'shadow-run-rescheduled');
$assert(($rescheduled['summary']['stored_days'] ?? null) === 1, 'changed effective schedule produces a fresh shadow result');
$assert(count($repository->days) === 2, 'schedule payload changes cannot silently reuse an older shadow day');
$assert((int) ($repository->days[2]['version_no'] ?? 0) === 2, 'same work date receives a new immutable version when source inputs change');

$events->eventsByStaff[506] = [
    [
        'id' => 61,
        'event_at_local' => '2026-01-05 09:00:00',
        'event_type' => 'in',
        'link_status' => 'matched',
        'review_status' => 'not_required',
        'entry_method_type' => 'biometric',
    ],
    [
        'id' => 62,
        'event_at_local' => '2026-01-05 14:30:00',
        'event_type' => 'out',
        'link_status' => 'matched',
        'review_status' => 'not_required',
        'entry_method_type' => 'biometric',
    ],
];
$coverage->coverageByStaff[506] = [[
    'source_type' => 'permission',
    'source_id' => 501,
    'coverage_behavior' => 'late_arrival',
    'from_at' => new DateTimeImmutable('2026-01-05 07:30:00', new DateTimeZone('Africa/Cairo')),
    'to_at' => new DateTimeImmutable('2026-01-05 09:30:00', new DateTimeZone('Africa/Cairo')),
    'source_version_id' => 44,
]];
$legacy->rows['506:2026-01-05'] = [
    'id' => 106,
    'status' => 'present',
    'check_in' => '2026-01-05 09:00:00.000000',
    'check_out' => '2026-01-05 14:30:00.000000',
    'late_minutes' => 0,
];
$coverageRun = $service->run(900, [506], $monday, $monday, 'shadow-run-approved-coverage');
$coverageDay = end($repository->days);
$assert(($coverageRun['summary']['legacy_matches'] ?? null) === 1, 'approved coverage is included before the shadow comparison is classified');
$assert((int) ($coverageDay['covered_late_minutes'] ?? 0) === 90, 'shadow day persists covered late minutes from the approved coverage query');
$assert((int) ($coverageDay['late_minutes'] ?? 0) === 0 && (int) ($coverageDay['missing_minutes'] ?? 0) === 0, 'shadow day stores only uncovered violation minutes');
$assert($countCode($repository->reasons, 'APPROVED_LATE_ARRIVAL_COVERAGE') === 1, 'shadow day persists an explainable approved coverage source line');

$schedules->schedule = $schedule();
$difference = $service->run(900, [502], $monday, $monday, 'shadow-run-missing-legacy');
$assert(($difference['summary']['legacy_differences'] ?? null) === 1, 'missing legacy row becomes a classified difference');
$assert($countCode($repository->reasons, 'LEGACY_RECORD_MISSING') === 1, 'missing legacy row has an explainable reason line');
$missingLegacyReason = end($repository->reasons);
$assert(($missingLegacyReason['source_type'] ?? null) === 'legacy_staff_attendance', 'legacy difference line identifies its compatibility source without exposing raw evidence');

$legacy->rows['505:2026-01-05'] = [
    'id' => 105,
    'status' => 'legacy_ambiguous',
    'check_in' => null,
    'check_out' => null,
    'late_minutes' => 0,
    'legacy_row_count' => 2,
];
$ambiguousLegacy = $service->run(900, [505], $monday, $monday, 'shadow-run-ambiguous-legacy');
$assert(($ambiguousLegacy['summary']['legacy_differences'] ?? null) === 1, 'multiple legacy rows become a classified comparison issue');
$assert($countCode($repository->reasons, 'LEGACY_RECORD_AMBIGUOUS') === 1, 'shadow comparison does not silently pick one duplicate legacy row');

$schedules->nonWorking = true;
$nonWorking = $service->run(900, [503], $monday, $monday, 'shadow-run-non-working');
$assert(($nonWorking['summary']['non_working_days'] ?? null) === 1, 'non-working date is stored as a non-working comparison result');
$nonWorkingDay = end($repository->days);
$assert(($nonWorkingDay['status'] ?? null) === 'non_working', 'non-working day never becomes absence during shadow comparison');
$schedules->nonWorking = false;
$schedules->unresolved = true;
$unresolved = $service->run(900, [504], $monday, $monday, 'shadow-run-unresolved');
$assert(($unresolved['summary']['unresolved_days'] ?? null) === 1, 'unresolved policy becomes a visible exception rather than a guessed schedule');
$unresolvedDay = end($repository->days);
$assert(($unresolvedDay['status'] ?? null) === 'unresolved', 'unresolved schedule result stays non-official and explicit');
$assert($countCode($repository->reasons, 'SCHEDULE_CONFLICT') === 1, 'unresolved schedule reason remains explainable in the child evidence');

if ($failures > 0) {
    fwrite(STDERR, "{$failures} attendance shadow run failure(s).\n");
    exit(1);
}

echo "Staff-HR attendance shadow run tests passed.\n";
