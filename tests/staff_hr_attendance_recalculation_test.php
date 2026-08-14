<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/vendor/autoload.php';

use EduCore\Modules\Attendance\Application\AttendanceRecalculationService;
use EduCore\Modules\Attendance\Contracts\ApprovedCoverageQuery;
use EduCore\Modules\Attendance\Contracts\AttendanceEventWindowQuery;
use EduCore\Modules\Attendance\Contracts\AttendanceRecalculationRepository;
use EduCore\Modules\Attendance\Contracts\AttendanceTransactionManager;
use EduCore\Modules\Attendance\Contracts\EffectiveScheduleQuery;
use EduCore\Modules\Attendance\Domain\Calculation\AttendanceDayCalculator;
use EduCore\Modules\Attendance\Domain\Calculation\PunchWindowMatcher;
use EduCore\Modules\Attendance\Domain\Schedule\WorkSchedule;
use EduCore\Modules\Operations\Audit\AuditEventWriter;

final class AttendanceRecalculationTestTransaction implements AttendanceTransactionManager
{
    public function transactional(callable $operation): mixed
    {
        return $operation();
    }
}

final class AttendanceRecalculationTestRepository implements AttendanceRecalculationRepository
{
    /** @var array<int,array<string,mixed>> */
    public array $runs = [];

    /** @var array<int,array<string,mixed>> */
    public array $days = [];

    /** @var list<array<string,mixed>> */
    public array $segments = [];

    /** @var list<array<string,mixed>> */
    public array $reasons = [];

    private int $nextRunId = 10;
    private int $nextDayId = 2;

    public function __construct()
    {
        $this->days[1] = [
            'id' => 1,
            'staff_user_id' => 701,
            'work_date' => '2026-01-05',
            'version_no' => 1,
            'is_official' => 1,
            'source_fingerprint' => str_repeat('a', 64),
        ];
    }

    public function runByIdempotencyForUpdate(string $idempotencyKey): ?array
    {
        foreach ($this->runs as $run) {
            if (($run['idempotency_key'] ?? null) === $idempotencyKey) {
                return $run;
            }
        }
        return null;
    }

    public function insertRecalculationRun(array $run): int
    {
        $id = $this->nextRunId++;
        $this->runs[$id] = ['id' => $id] + $run;
        return $id;
    }

    public function startRecalculationRun(int $runId, DateTimeImmutable $startedAt): bool
    {
        if (($this->runs[$runId]['status'] ?? null) !== 'queued') {
            return false;
        }
        $this->runs[$runId]['status'] = 'running';
        return true;
    }

    public function completeRecalculationRun(int $runId, DateTimeImmutable $finishedAt, array $summary): bool
    {
        if (($this->runs[$runId]['status'] ?? null) !== 'running') {
            return false;
        }
        $this->runs[$runId]['status'] = 'completed';
        $this->runs[$runId]['summary'] = $summary;
        return true;
    }

    public function currentOfficialDayForUpdate(int $staffUserId, string $workDate): ?array
    {
        foreach ($this->days as $day) {
            if (
                (int) ($day['staff_user_id'] ?? 0) === $staffUserId
                && (string) ($day['work_date'] ?? '') === $workDate
                && (int) ($day['is_official'] ?? 0) === 1
            ) {
                return $day;
            }
        }
        return null;
    }

    public function nextDayVersionNoForUpdate(int $staffUserId, string $workDate): int
    {
        $latest = 0;
        foreach ($this->days as $day) {
            if ((int) ($day['staff_user_id'] ?? 0) === $staffUserId && (string) ($day['work_date'] ?? '') === $workDate) {
                $latest = max($latest, (int) ($day['version_no'] ?? 0));
            }
        }
        return $latest + 1;
    }

    public function retireOfficialDay(int $dayVersionId): bool
    {
        if (($this->days[$dayVersionId]['is_official'] ?? 0) !== 1) {
            return false;
        }
        $this->days[$dayVersionId]['is_official'] = 0;
        return true;
    }

    public function insertRecalculatedDay(array $day): int
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

    public function publishRecalculatedDay(int $dayVersionId, int $actorId, DateTimeImmutable $officializedAt): bool
    {
        if (($this->days[$dayVersionId]['is_official'] ?? 1) !== 0
            || $this->segments === []
            || $this->reasons === []) {
            return false;
        }
        $this->days[$dayVersionId]['is_official'] = 1;
        $this->days[$dayVersionId]['officialized_by'] = $actorId;
        $this->days[$dayVersionId]['officialized_at'] = $officializedAt->format(DATE_ATOM);
        return true;
    }
}

final class AttendanceRecalculationTestScheduleQuery implements EffectiveScheduleQuery
{
    public function __construct(private WorkSchedule $schedule)
    {
    }

    public function forStaffDate(int $staffId, DateTimeImmutable $workDate): array
    {
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

final class AttendanceRecalculationTestEvents implements AttendanceEventWindowQuery
{
    /** @var list<array<string,mixed>> */
    public array $items = [];

    public function forStaffWindow(
        int $staffUserId,
        DateTimeImmutable $windowStart,
        DateTimeImmutable $windowEnd
    ): array {
        return $this->items;
    }
}

final class AttendanceRecalculationTestCoverage implements ApprovedCoverageQuery
{
    /** @var list<array<string,mixed>> */
    public array $items = [];

    public function forStaffWindow(
        int $staffUserId,
        DateTimeImmutable $windowStart,
        DateTimeImmutable $windowEnd
    ): array {
        return $this->items;
    }
}

final class AttendanceRecalculationTestAudit implements AuditEventWriter
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
$zone = new DateTimeZone('Africa/Cairo');
$monday = new DateTimeImmutable('2026-01-05 00:00:00', $zone);
$schedule = WorkSchedule::fromArray([
    'timezone' => 'Africa/Cairo',
    'days' => [[
        'weekday' => 1,
        'is_working_day' => true,
        'start_time' => '07:30',
        'end_time' => '14:30',
        'end_day_offset' => 0,
        'required_minutes' => 420,
        'late_grace_minutes' => 0,
        'early_grace_minutes' => 0,
        'entry_window_before_minutes' => 60,
        'entry_window_after_minutes' => 240,
        'exit_window_before_minutes' => 240,
        'exit_window_after_minutes' => 60,
    ]],
]);
$repository = new AttendanceRecalculationTestRepository();
$events = new AttendanceRecalculationTestEvents();
$events->items = [
    [
        'id' => 101,
        'event_at_local' => '2026-01-05 09:00:00',
        'event_type' => 'in',
        'link_status' => 'matched',
        'review_status' => 'not_required',
        'entry_method_type' => 'biometric',
    ],
    [
        'id' => 102,
        'event_at_local' => '2026-01-05 14:30:00',
        'event_type' => 'out',
        'link_status' => 'matched',
        'review_status' => 'not_required',
        'entry_method_type' => 'biometric',
    ],
];
$coverage = new AttendanceRecalculationTestCoverage();
$coverage->items = [[
    'source_type' => 'permission',
    'source_id' => 301,
    'coverage_behavior' => 'late_arrival',
    'from_at' => new DateTimeImmutable('2026-01-05 07:30:00', $zone),
    'to_at' => new DateTimeImmutable('2026-01-05 09:30:00', $zone),
    'source_version_id' => 44,
]];
$audit = new AttendanceRecalculationTestAudit();
$service = new AttendanceRecalculationService(
    new AttendanceRecalculationTestTransaction(),
    $repository,
    new AttendanceRecalculationTestScheduleQuery($schedule),
    $events,
    $coverage,
    new AttendanceDayCalculator(new PunchWindowMatcher()),
    $audit
);

$first = $service->recalculate(900, 701, $monday, 'PERMISSION_APPROVED', 'recalc-coverage-1');
$assert(($first['recalculated'] ?? null) === true && ($first['replayed'] ?? null) === false, 'affected day recalculation creates a new result exactly once');
$assert((int) ($first['before_day_version_id'] ?? 0) === 1 && (int) ($first['day_version_id'] ?? 0) === 2, 'recalculation links the official successor to the prior version');
$assert((int) ($repository->days[1]['is_official'] ?? 1) === 0 && (int) ($repository->days[2]['is_official'] ?? 0) === 1, 'only the successor remains current official');
$assert((int) ($repository->days[2]['covered_late_minutes'] ?? 0) === 90, 'recalculation persists approved late coverage');
$assert((int) ($repository->days[2]['late_minutes'] ?? 0) === 0 && (int) ($repository->days[2]['missing_minutes'] ?? 0) === 0, 'recalculation persists only uncovered violations');
$assert((int) ($repository->days[2]['supersedes_id'] ?? 0) === 1, 'new official result preserves the immutable predecessor link');
$assert(count($repository->segments) === 1, 'recalculation persists explainable calculated segments');
$assert(
    count(array_filter($repository->reasons, static fn (array $line): bool => ($line['reason_code'] ?? null) === 'RECALCULATED_FROM_OFFICIAL_VERSION')) === 1,
    'recalculation records an explicit immutable succession reason'
);
$assert(($audit->events[0]['action'] ?? null) === 'staff_attendance_day_recalculated', 'recalculation is written through shared audit');

$replay = $service->recalculate(900, 701, $monday, 'PERMISSION_APPROVED', 'recalc-coverage-1');
$assert(($replay['replayed'] ?? null) === true && count($repository->days) === 2, 'same idempotency key replays without another successor');
$throws(
    static fn () => $service->recalculate(900, 701, $monday, 'PERMISSION_REVERSED', 'recalc-coverage-1'),
    'ATTENDANCE_RECALCULATION_IDEMPOTENCY_CONFLICT',
    'same idempotency key with a changed trigger fails closed'
);

$noChange = $service->recalculate(900, 701, $monday, 'PERMISSION_APPROVED', 'recalc-coverage-no-change');
$assert(($noChange['no_change'] ?? null) === true && count($repository->days) === 2, 'unchanged evidence creates an audited no-change run without duplicate day versions');
$assert(
    ($repository->runs[(int) ($noChange['run_id'] ?? 0)]['supersedes_run_id'] ?? null) === null,
    'an unchanged run does not consume the unique official-run succession link'
);

$events->items = [[
    'id' => 103,
    'event_at_local' => '2026-01-05 07:30:00',
    'event_type' => 'in',
    'link_status' => 'matched',
    'review_status' => 'not_required',
    'entry_method_type' => 'biometric',
]];
$throws(
    static fn () => $service->recalculate(900, 701, $monday, 'LATE_EVENT', 'recalc-incomplete'),
    'ATTENDANCE_RECALCULATION_INCOMPLETE_EVIDENCE',
    'incomplete punch evidence cannot silently replace an official result'
);
$assert(count($repository->days) === 2, 'incomplete evidence leaves the current official version untouched');

$events->items = [
    [
        'id' => 201,
        'event_at_local' => '2026-01-05 07:30:00',
        'event_type' => 'in',
        'link_status' => 'matched',
        'review_status' => 'not_required',
        'entry_method_type' => 'biometric',
    ],
    [
        'id' => 202,
        'event_at_local' => '2026-01-05 14:30:00',
        'event_type' => 'out',
        'link_status' => 'matched',
        'review_status' => 'not_required',
        'entry_method_type' => 'biometric',
    ],
];
$initialRepository = new AttendanceRecalculationTestRepository();
$initialRepository->days = [];
$initialAudit = new AttendanceRecalculationTestAudit();
$initialService = new AttendanceRecalculationService(
    new AttendanceRecalculationTestTransaction(),
    $initialRepository,
    new AttendanceRecalculationTestScheduleQuery($schedule),
    $events,
    $coverage,
    new AttendanceDayCalculator(new PunchWindowMatcher()),
    $initialAudit
);
$initial = $initialService->calculateInitial(900, 701, $monday, 'INITIAL_OFFICIAL_CALCULATION', 'initial-official-1');
$assert(($initial['calculated'] ?? null) === true && (int) ($initial['before_day_version_id'] ?? -1) === 0, 'initial calculation publishes a first official day without inventing a predecessor');
$assert(count($initialRepository->days) === 1 && (int) ($initialRepository->days[2]['is_official'] ?? 0) === 1 && ($initialRepository->days[2]['supersedes_id'] ?? null) === null, 'initial official day is version one and has no predecessor link');
$assert(count(array_filter($initialRepository->reasons, static fn (array $line): bool => ($line['reason_code'] ?? null) === 'INITIAL_OFFICIAL_CALCULATION')) === 1, 'initial calculation records its explicit immutable reason');
$assert(($initialAudit->events[0]['action'] ?? null) === 'staff_attendance_day_initially_calculated', 'initial official calculation is written through shared audit');
$throws(
    static fn () => $initialService->calculateInitial(900, 701, $monday, 'INITIAL_OFFICIAL_CALCULATION', 'initial-official-2'),
    'ATTENDANCE_INITIAL_OFFICIAL_DAY_EXISTS',
    'initial calculation refuses to replace an existing official day'
);

$missingOfficial = new AttendanceRecalculationService(
    new AttendanceRecalculationTestTransaction(),
    new class implements AttendanceRecalculationRepository {
        public function runByIdempotencyForUpdate(string $idempotencyKey): ?array { return null; }
        public function insertRecalculationRun(array $run): int { return 1; }
        public function startRecalculationRun(int $runId, DateTimeImmutable $startedAt): bool { return true; }
        public function completeRecalculationRun(int $runId, DateTimeImmutable $finishedAt, array $summary): bool { return true; }
        public function currentOfficialDayForUpdate(int $staffUserId, string $workDate): ?array { return null; }
        public function nextDayVersionNoForUpdate(int $staffUserId, string $workDate): int { return 1; }
        public function retireOfficialDay(int $dayVersionId): bool { return false; }
        public function insertRecalculatedDay(array $day): int { return 1; }
        public function appendSegment(array $segment): void {}
        public function appendReasonLine(array $reasonLine): void {}
        public function publishRecalculatedDay(int $dayVersionId, int $actorId, DateTimeImmutable $officializedAt): bool { return true; }
    },
    new AttendanceRecalculationTestScheduleQuery($schedule),
    $events,
    $coverage,
    new AttendanceDayCalculator(new PunchWindowMatcher()),
    new AttendanceRecalculationTestAudit()
);
$throws(
    static fn () => $missingOfficial->recalculate(900, 701, $monday, 'PERMISSION_APPROVED', 'recalc-no-official'),
    'ATTENDANCE_RECALCULATION_OFFICIAL_DAY_NOT_FOUND',
    'recalculation refuses to invent an official predecessor'
);

if ($failures > 0) {
    fwrite(STDERR, "{$failures} attendance recalculation failure(s).\n");
    exit(1);
}

echo "Staff-HR attendance recalculation tests passed.\n";
