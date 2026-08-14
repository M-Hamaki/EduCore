<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/vendor/autoload.php';

use EduCore\Modules\Attendance\Application\AttendanceReportProjector;
use EduCore\Modules\Attendance\Contracts\AttendanceReportProjectionRepository;
use EduCore\Modules\Attendance\Contracts\AttendanceReportReadRepository;
use EduCore\Modules\Attendance\Contracts\AttendanceTransactionManager;
use EduCore\Modules\Operations\Audit\AuditEventWriter;
use EduCore\Modules\Staff\Contracts\StaffAttendanceReportDimensionQuery;

final class AttendanceReportProjectorProbeTransaction implements AttendanceTransactionManager
{
    public int $calls = 0;

    public function __construct(
        public AttendanceReportProjectorProbeProjectionRepository $repository,
        public AttendanceReportProjectorProbeAudit $audit
    ) {
    }

    public function transactional(callable $operation): mixed
    {
        ++$this->calls;
        $snapshot = [
            'runs' => $this->repository->runs,
            'aggregates' => $this->repository->aggregates,
            'nextRunId' => $this->repository->nextRunId,
            'nextAggregateId' => $this->repository->nextAggregateId,
            'events' => $this->audit->events,
        ];
        try {
            return $operation();
        } catch (Throwable $exception) {
            $this->repository->runs = $snapshot['runs'];
            $this->repository->aggregates = $snapshot['aggregates'];
            $this->repository->nextRunId = $snapshot['nextRunId'];
            $this->repository->nextAggregateId = $snapshot['nextAggregateId'];
            $this->audit->events = $snapshot['events'];
            throw $exception;
        }
    }
}

final class AttendanceReportProjectorProbeReader implements AttendanceReportReadRepository
{
    /** @var list<array<string,mixed>> */
    public array $rows = [];
    /** @var list<array<string,mixed>> */
    public array $reasonRows = [];
    /** @var list<array<string,mixed>> */
    public array $calls = [];

    public function officialDays(array $filters): array
    {
        $this->calls[] = $filters;
        $rows = array_values(array_filter($this->rows, static function (array $row) use ($filters): bool {
            if ($row['work_date'] < $filters['date_from'] || $row['work_date'] > $filters['date_to']) {
                return false;
            }
            $cursor = $filters['cursor'] ?? null;
            if (!is_array($cursor)) {
                return true;
            }
            $left = [$row['work_date'], (int) $row['staff_user_id'], (int) $row['day_version_id']];
            $right = [(string) $cursor['work_date'], (int) $cursor['staff_user_id'], (int) $cursor['day_version_id']];
            return $left > $right;
        }));

        return array_slice($rows, 0, (int) $filters['scan_limit']);
    }

    public function reasonLinesForDayVersions(array $dayVersionIds): array
    {
        return array_values(array_filter(
            $this->reasonRows,
            static fn (array $row): bool => in_array((int) $row['day_version_id'], $dayVersionIds, true)
        ));
    }
}

final class AttendanceReportProjectorProbeDimensions implements StaffAttendanceReportDimensionQuery
{
    /** @var array<int,array<string,mixed>> */
    public array $dimensions = [];
    /** @var list<array<string,mixed>> */
    public array $conflicts = [];

    public function forAttendanceDays(array $dayReferences): array
    {
        $dimensions = [];
        foreach ($dayReferences as $reference) {
            $id = (int) $reference['day_version_id'];
            if (isset($this->dimensions[$id])) {
                $dimensions[$id] = $this->dimensions[$id];
            }
        }

        return ['dimensions' => $dimensions, 'conflicts' => $this->conflicts];
    }
}

final class AttendanceReportProjectorProbeProjectionRepository implements AttendanceReportProjectionRepository
{
    /** @var array<int,array<string,mixed>> */
    public array $runs = [];
    /** @var array<int,array<string,mixed>> */
    public array $aggregates = [];
    public int $nextRunId = 1;
    public int $nextAggregateId = 1;
    public bool $failInsert = false;

    public function projectionRunByIdempotencyForUpdate(string $idempotencyKey): ?array
    {
        foreach ($this->runs as $run) {
            if (($run['idempotency_key'] ?? null) === $idempotencyKey) {
                return $run;
            }
        }
        return null;
    }

    public function insertProjectionRun(array $run): int
    {
        $id = $this->nextRunId++;
        $run['id'] = $id;
        $this->runs[$id] = $run;
        return $id;
    }

    public function startProjectionRun(int $runId, DateTimeImmutable $startedAt): bool
    {
        if (!isset($this->runs[$runId]) || $this->runs[$runId]['status'] !== 'queued') {
            return false;
        }
        $this->runs[$runId]['status'] = 'running';
        $this->runs[$runId]['started_at'] = $startedAt->format('Y-m-d H:i:s.u');
        return true;
    }

    public function completeProjectionRun(int $runId, DateTimeImmutable $finishedAt, array $summary): bool
    {
        if (!isset($this->runs[$runId]) || $this->runs[$runId]['status'] !== 'running') {
            return false;
        }
        $this->runs[$runId]['status'] = 'completed';
        $this->runs[$runId]['finished_at'] = $finishedAt->format('Y-m-d H:i:s.u');
        $this->runs[$runId]['summary'] = $summary;
        return true;
    }

    public function currentAggregateForUpdate(string $aggregateKey): ?array
    {
        foreach ($this->aggregates as $aggregate) {
            if (($aggregate['aggregate_key'] ?? null) === $aggregateKey
                && (int) ($aggregate['is_current'] ?? 0) === 1) {
                return $aggregate;
            }
        }
        return null;
    }

    public function retireCurrentAggregate(int $aggregateId): bool
    {
        if (!isset($this->aggregates[$aggregateId]) || (int) $this->aggregates[$aggregateId]['is_current'] !== 1) {
            return false;
        }
        $this->aggregates[$aggregateId]['is_current'] = 0;
        return true;
    }

    public function insertAggregate(array $aggregate): int
    {
        if ($this->failInsert) {
            throw new RuntimeException('forced aggregate persistence failure');
        }
        $id = $this->nextAggregateId++;
        $aggregate['id'] = $id;
        $this->aggregates[$id] = $aggregate;
        return $id;
    }
}

final class AttendanceReportProjectorProbeAudit implements AuditEventWriter
{
    /** @var list<array<string,mixed>> */
    public array $events = [];
    public bool $fail = false;

    public function recordEvent(
        string $action,
        ?string $entityType,
        mixed $recordId,
        ?string $name,
        array $details = [],
        array $context = []
    ): void {
        if ($this->fail) {
            throw new RuntimeException('forced audit failure');
        }
        $this->events[] = compact('action', 'entityType', 'recordId', 'name', 'details', 'context');
    }
}

$failures = 0;
$assert = static function (bool $condition, string $message) use (&$failures): void {
    if (!$condition) {
        fwrite(STDERR, "FAIL: {$message}\n");
        ++$failures;
    }
};
$assertThrows = static function (callable $operation, string $code, string $message) use ($assert): void {
    try {
        $operation();
        $assert(false, $message);
    } catch (Throwable $exception) {
        $assert(str_contains($exception->getMessage(), $code), $message . ' (stable error code)');
    }
};
$day = static function (
    int $id,
    int $staffId,
    string $date,
    int $assignmentId,
    string $status,
    string $fingerprint,
    int $required = 420,
    int $worked = 0,
    int $coveredLate = 0,
    int $coveredEarly = 0,
    int $mission = 0,
    int $leave = 0,
    int $late = 0,
    int $early = 0,
    int $missing = 0
): array {
    return [
        'day_version_id' => $id,
        'staff_user_id' => $staffId,
        'work_date' => $date,
        'assignment_id' => $assignmentId,
        'status' => $status,
        'source_fingerprint' => str_repeat($fingerprint, 64),
        'required_minutes' => $required,
        'worked_minutes' => $worked,
        'covered_late_minutes' => $coveredLate,
        'covered_early_minutes' => $coveredEarly,
        'mission_minutes' => $mission,
        'leave_minutes' => $leave,
        'late_minutes' => $late,
        'early_leave_minutes' => $early,
        'missing_minutes' => $missing,
    ];
};
$dimension = static function (int $dayVersionId, int $staffId, int $assignmentId, int $unitId, int $titleId, array $groups): array {
    return [
        'day_version_id' => $dayVersionId,
        'staff_user_id' => $staffId,
        'assignment_id' => $assignmentId,
        'org_unit_id' => $unitId,
        'job_title_id' => $titleId,
        'group_ids' => $groups,
    ];
};

$reader = new AttendanceReportProjectorProbeReader();
$reader->rows = [
    $day(1001, 11, '2026-08-01', 201, 'present', 'a', 420, 420),
    $day(1002, 11, '2026-08-02', 201, 'partial', 'b', 420, 370, 30, 0, 0, 0, 20, 0, 20),
    $day(1003, 12, '2026-08-05', 202, 'absent', 'c', 420, 0, 0, 0, 0, 420),
    $day(1004, 12, '2026-09-01', 203, 'present', 'd', 420, 420),
];
$reader->reasonRows = [
    ['day_version_id' => 1002, 'reason_code' => 'APPROVED_LATE_COVERAGE', 'minutes' => 30],
    ['day_version_id' => 1002, 'reason_code' => 'UNEXCUSED_LATE', 'minutes' => 20],
    ['day_version_id' => 1003, 'reason_code' => 'APPROVED_LEAVE', 'minutes' => 420],
];
$dimensions = new AttendanceReportProjectorProbeDimensions();
$dimensions->dimensions = [
    1001 => $dimension(1001, 11, 201, 1, 10, [5]),
    1002 => $dimension(1002, 11, 201, 1, 10, [5]),
    1003 => $dimension(1003, 12, 202, 2, 20, [9]),
    1004 => $dimension(1004, 12, 203, 2, 20, [9]),
];
$projectionRepository = new AttendanceReportProjectorProbeProjectionRepository();
$audit = new AttendanceReportProjectorProbeAudit();
$transactions = new AttendanceReportProjectorProbeTransaction($projectionRepository, $audit);
$projector = new AttendanceReportProjector(
    $transactions,
    $reader,
    $dimensions,
    $projectionRepository,
    $audit,
    new DateTimeZone('Africa/Cairo'),
    static fn (): DateTimeImmutable => new DateTimeImmutable('2026-09-30 12:00:00', new DateTimeZone('Africa/Cairo'))
);

$monthly = $projector->project(7, [
    'granularity' => 'monthly',
    'range_from' => '2026-08-01',
    'range_to' => '2026-09-30',
    'idempotency_key' => 'report-monthly-001',
    'batch_size' => 2,
]);
$assert(($monthly['aggregate_count'] ?? null) === 3, 'monthly projector splits by calendar month and frozen assignment dimension');
$assert(($monthly['created_count'] ?? null) === 3 && ($monthly['superseded_count'] ?? null) === 0, 'first projection appends current aggregates');
$assert(count($reader->calls) === 3, 'projector reads the stable official source in bounded cursor batches');
$assert(($reader->calls[0]['as_of'] ?? null) === '2026-09-30 09:00:00.000000', 'source is pinned to one UTC as-of instant before writes');
$assert(count($projectionRepository->runs) === 1 && count($audit->events) === 1, 'projection run and mandatory audit event are persisted together');

$augustStaff11 = null;
foreach ($projectionRepository->aggregates as $aggregate) {
    if (($aggregate['staff_user_id'] ?? null) === 11 && ($aggregate['range_from'] ?? null) === '2026-08-01') {
        $augustStaff11 = $aggregate;
        break;
    }
}
$assert($augustStaff11 !== null, 'monthly aggregate exists for the first employee and month');
$assert(($augustStaff11['eligible_workdays'] ?? null) === 2, 'aggregate denominator counts eligible scheduled workdays');
$assert(($augustStaff11['present_days'] ?? null) === 1 && ($augustStaff11['partial_days'] ?? null) === 1, 'aggregate status totals equal its official day details');
$assert(($augustStaff11['approved_permission_days'] ?? null) === 1, 'permission coverage stays separate in projection statistics');
$assert(($augustStaff11['reason_summary']['UNEXCUSED_LATE']['minutes'] ?? null) === 20, 'aggregate has a compact, explainable reason summary');

$replayed = $projector->project(7, [
    'granularity' => 'monthly',
    'range_from' => '2026-08-01',
    'range_to' => '2026-09-30',
    'idempotency_key' => 'report-monthly-001',
    'batch_size' => 2,
]);
$assert(($replayed['replayed'] ?? null) === true && count($projectionRepository->runs) === 1, 'same idempotency key replays without new aggregates or audit');

$assertThrows(
    static fn () => $projector->project(7, [
        'granularity' => 'range',
        'range_from' => '2026-08-01',
        'range_to' => '2026-09-30',
        'idempotency_key' => 'report-monthly-001',
    ]),
    'ATTENDANCE_REPORT_PROJECTION_IDEMPOTENCY_CONFLICT',
    'same idempotency key cannot silently change the projection request'
);

$reader->rows[1]['source_fingerprint'] = str_repeat('e', 64);
$reader->rows[1]['late_minutes'] = 25;
$changed = $projector->project(7, [
    'granularity' => 'monthly',
    'range_from' => '2026-08-01',
    'range_to' => '2026-09-30',
    'idempotency_key' => 'report-monthly-002',
    'batch_size' => 2,
]);
$assert(($changed['created_count'] ?? null) === 1 && ($changed['superseded_count'] ?? null) === 1, 'changed official source appends only the affected aggregate successor');
$former = array_values(array_filter(
    $projectionRepository->aggregates,
    static fn (array $aggregate): bool => ($aggregate['staff_user_id'] ?? null) === 11
        && ($aggregate['range_from'] ?? null) === '2026-08-01'
        && (int) ($aggregate['is_current'] ?? 0) === 0
));
$current = array_values(array_filter(
    $projectionRepository->aggregates,
    static fn (array $aggregate): bool => ($aggregate['staff_user_id'] ?? null) === 11
        && ($aggregate['range_from'] ?? null) === '2026-08-01'
        && (int) ($aggregate['is_current'] ?? 0) === 1
));
$assert(count($former) === 1 && count($current) === 1 && ($current[0]['supersedes_id'] ?? null) === ($former[0]['id'] ?? null), 'rebuild retains and links the former aggregate instead of overwriting it');

$annual = $projector->project(7, [
    'granularity' => 'annual',
    'range_from' => '2026-01-01',
    'range_to' => '2026-12-31',
    'idempotency_key' => 'report-annual-001',
]);
$assert(($annual['aggregate_count'] ?? null) === 3, 'annual projection supports historical assignment splits inside one year');

$ranged = $projector->project(7, [
    'granularity' => 'range',
    'range_from' => '2026-08-01',
    'range_to' => '2026-08-31',
    'idempotency_key' => 'report-range-001',
]);
$assert(($ranged['aggregate_count'] ?? null) === 2, 'custom range projection is bounded to the requested interval');

$runsBeforeConflict = count($projectionRepository->runs);
$dimensions->conflicts = [['day_version_id' => 1001, 'reason_code' => 'ATTENDANCE_REPORT_ASSIGNMENT_NOT_EFFECTIVE']];
$assertThrows(
    static fn () => $projector->project(7, [
        'granularity' => 'range',
        'range_from' => '2026-08-01',
        'range_to' => '2026-08-31',
        'idempotency_key' => 'report-conflict-001',
    ]),
    'ATTENDANCE_REPORT_DIMENSION_UNRESOLVED',
    'historical dimension conflict prevents a partial projection'
);
$assert(count($projectionRepository->runs) === $runsBeforeConflict, 'dimension conflict stops before any projection write');
$dimensions->conflicts = [];

$runsBeforeAuditFailure = count($projectionRepository->runs);
$aggregatesBeforeAuditFailure = count($projectionRepository->aggregates);
$audit->fail = true;
$assertThrows(
    static fn () => $projector->project(7, [
        'granularity' => 'range',
        'range_from' => '2026-08-01',
        'range_to' => '2026-08-31',
        'idempotency_key' => 'report-audit-failure-001',
    ]),
    'forced audit failure',
    'mandatory audit failure aborts report projection'
);
$audit->fail = false;
$assert(count($projectionRepository->runs) === $runsBeforeAuditFailure
    && count($projectionRepository->aggregates) === $aggregatesBeforeAuditFailure, 'transaction rollback removes projection writes after audit failure');

$assertThrows(
    static fn () => $projector->project(7, [
        'granularity' => 'monthly',
        'range_from' => '2026-08-02',
        'range_to' => '2026-08-31',
        'idempotency_key' => 'report-invalid-month-001',
    ]),
    'ATTENDANCE_REPORT_MONTH_RANGE_INVALID',
    'partial month cannot be mislabeled as a complete monthly aggregate'
);
$assertThrows(
    static fn () => $projector->project(7, [
        'granularity' => 'annual',
        'range_from' => '2026-01-02',
        'range_to' => '2026-12-31',
        'idempotency_key' => 'report-invalid-year-001',
    ]),
    'ATTENDANCE_REPORT_YEAR_RANGE_INVALID',
    'partial year cannot be mislabeled as a complete annual aggregate'
);

$projectorSource = (string) file_get_contents(
    dirname(__DIR__) . '/src/Modules/Attendance/Application/AttendanceReportProjector.php'
);
$projectionRepositorySource = (string) file_get_contents(
    dirname(__DIR__) . '/src/Modules/Attendance/Infrastructure/PdoAttendanceReportProjectionRepository.php'
);
$assert(!str_contains($projectorSource, 'use PDO;'), 'projection application service has no PDO dependency');
$assert(!str_contains($projectorSource, 'staff_assignments'), 'projection application service does not read Staff tables directly');
$assert(str_contains($projectorSource, 'AuditEventWriter'), 'projection owner requires the shared audit contract');
$assert(str_contains($projectionRepositorySource, 'staff_attendance_report_aggregates'), 'PDO adapter owns only Attendance projection persistence');

if ($failures > 0) {
    fwrite(STDERR, "{$failures} attendance report projector test failure(s).\n");
    exit(1);
}

echo "Attendance report projector tests passed.\n";
