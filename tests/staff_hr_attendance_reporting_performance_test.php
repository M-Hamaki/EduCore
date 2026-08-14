<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/vendor/autoload.php';

use EduCore\Modules\Attendance\Application\AttendanceReportProjector;
use EduCore\Modules\Attendance\Contracts\AttendanceReportProjectionRepository;
use EduCore\Modules\Attendance\Contracts\AttendanceReportReadRepository;
use EduCore\Modules\Attendance\Contracts\AttendanceTransactionManager;
use EduCore\Modules\Operations\Audit\AuditEventWriter;
use EduCore\Modules\Staff\Contracts\StaffAttendanceReportDimensionQuery;

/**
 * Cursor-only fixture: it can represent five years of 500-worker daily
 * versions without first allocating the complete 900k+ result set.
 */
final class AttendanceReportPerformanceFixture implements AttendanceReportReadRepository
{
    public int $pageCalls = 0;
    public int $reasonCalls = 0;

    private DateTimeImmutable $origin;
    private DateTimeZone $timezone;

    public function __construct(private int $staffCount)
    {
        $this->timezone = new DateTimeZone('Africa/Cairo');
        $this->origin = new DateTimeImmutable('2022-01-01', $this->timezone);
    }

    public function officialDays(array $filters): array
    {
        ++$this->pageCalls;
        $from = new DateTimeImmutable((string) $filters['date_from'], $this->timezone);
        $to = new DateTimeImmutable((string) $filters['date_to'], $this->timezone);
        $limit = max(1, (int) $filters['scan_limit']);
        $cursor = is_array($filters['cursor'] ?? null) ? $filters['cursor'] : null;

        $date = $from;
        $firstStaffId = 1;
        if ($cursor !== null) {
            $cursorDate = new DateTimeImmutable((string) $cursor['work_date'], $this->timezone);
            if ($cursorDate > $date) {
                $date = $cursorDate;
                $firstStaffId = (int) $cursor['staff_user_id'] + 1;
            } elseif ($cursorDate == $date) {
                $firstStaffId = (int) $cursor['staff_user_id'] + 1;
            }
        }

        $rows = [];
        while ($date <= $to && count($rows) < $limit) {
            $dateText = $date->format('Y-m-d');
            for ($staffId = $firstStaffId; $staffId <= $this->staffCount && count($rows) < $limit; ++$staffId) {
                $dayVersionId = ((int) $this->origin->diff($date)->days * $this->staffCount) + $staffId;
                $rows[] = [
                    'day_version_id' => $dayVersionId,
                    'staff_user_id' => $staffId,
                    'work_date' => $dateText,
                    'assignment_id' => 10000 + $staffId,
                    'status' => $staffId % 37 === 0 ? 'partial' : 'present',
                    'source_fingerprint' => str_pad(dechex($dayVersionId), 64, '0', STR_PAD_LEFT),
                    'required_minutes' => 420,
                    'worked_minutes' => $staffId % 37 === 0 ? 400 : 420,
                    'covered_late_minutes' => 0,
                    'covered_early_minutes' => 0,
                    'mission_minutes' => 0,
                    'leave_minutes' => 0,
                    'late_minutes' => $staffId % 37 === 0 ? 20 : 0,
                    'early_leave_minutes' => 0,
                    'missing_minutes' => $staffId % 37 === 0 ? 20 : 0,
                ];
            }
            $date = $date->modify('+1 day');
            $firstStaffId = 1;
        }

        return $rows;
    }

    public function reasonLinesForDayVersions(array $dayVersionIds): array
    {
        ++$this->reasonCalls;
        return [];
    }

    public function expectedRows(string $from, string $to): int
    {
        $start = new DateTimeImmutable($from, $this->timezone);
        $end = new DateTimeImmutable($to, $this->timezone);
        return (((int) $start->diff($end)->days) + 1) * $this->staffCount;
    }
}

final class AttendanceReportPerformanceDimensions implements StaffAttendanceReportDimensionQuery
{
    public int $calls = 0;

    public function forAttendanceDays(array $dayReferences): array
    {
        ++$this->calls;
        $dimensions = [];
        foreach ($dayReferences as $reference) {
            $staffId = (int) $reference['staff_user_id'];
            $dayVersionId = (int) $reference['day_version_id'];
            $dimensions[$dayVersionId] = [
                'day_version_id' => $dayVersionId,
                'staff_user_id' => $staffId,
                'assignment_id' => 10000 + $staffId,
                'org_unit_id' => (($staffId - 1) % 5) + 1,
                'job_title_id' => (($staffId - 1) % 10) + 1,
                'group_ids' => [(($staffId - 1) % 20) + 1],
            ];
        }
        return ['dimensions' => $dimensions, 'conflicts' => []];
    }
}

final class AttendanceReportPerformanceTransaction implements AttendanceTransactionManager
{
    public function transactional(callable $operation): mixed
    {
        return $operation();
    }
}

final class AttendanceReportPerformanceProjectionRepository implements AttendanceReportProjectionRepository
{
    public int $runs = 0;
    public int $aggregates = 0;
    public int $retired = 0;
    /** @var list<array<string,mixed>> */
    public array $insertedAggregates = [];

    public function projectionRunByIdempotencyForUpdate(string $idempotencyKey): ?array
    {
        return null;
    }

    public function insertProjectionRun(array $run): int
    {
        return ++$this->runs;
    }

    public function startProjectionRun(int $runId, DateTimeImmutable $startedAt): bool
    {
        return true;
    }

    public function completeProjectionRun(int $runId, DateTimeImmutable $finishedAt, array $summary): bool
    {
        return true;
    }

    public function currentAggregateForUpdate(string $aggregateKey): ?array
    {
        return null;
    }

    public function retireCurrentAggregate(int $aggregateId): bool
    {
        ++$this->retired;
        return true;
    }

    public function insertAggregate(array $aggregate): int
    {
        ++$this->aggregates;
        if (count($this->insertedAggregates) < 3) {
            $this->insertedAggregates[] = $aggregate;
        }
        return $this->aggregates;
    }
}

final class AttendanceReportPerformanceAudit implements AuditEventWriter
{
    public int $events = 0;

    public function recordEvent(
        string $action,
        ?string $entityType,
        mixed $recordId,
        ?string $name,
        array $details = [],
        array $context = []
    ): void {
        ++$this->events;
    }
}

$failures = 0;
$assert = static function (bool $condition, string $message) use (&$failures): void {
    if (!$condition) {
        fwrite(STDERR, "FAIL: {$message}\n");
        ++$failures;
    }
};

$staffCount = 500;
$oneYearFrom = '2024-01-01';
$oneYearTo = '2024-12-31';
$batchSize = 1000;
$fixture = new AttendanceReportPerformanceFixture($staffCount);
$dimensions = new AttendanceReportPerformanceDimensions();
$projections = new AttendanceReportPerformanceProjectionRepository();
$audit = new AttendanceReportPerformanceAudit();
$projector = new AttendanceReportProjector(
    new AttendanceReportPerformanceTransaction(),
    $fixture,
    $dimensions,
    $projections,
    $audit,
    new DateTimeZone('Africa/Cairo'),
    static fn (): DateTimeImmutable => new DateTimeImmutable('2026-08-08 10:00:00', new DateTimeZone('UTC'))
);

$expectedOneYearRows = $fixture->expectedRows($oneYearFrom, $oneYearTo);
$expectedFiveYearRows = $fixture->expectedRows('2022-01-01', '2026-12-31');
$assert($expectedOneYearRows === 183000, 'fixture represents 500 staff across a leap acceptance year');
$assert($expectedFiveYearRows === 913000, 'fixture represents the five-year 500-staff capacity target without pre-materializing its rows');

$memoryBefore = memory_get_usage(true);
$startedAt = hrtime(true);
$receipt = $projector->project(1, [
    'granularity' => 'annual',
    'range_from' => $oneYearFrom,
    'range_to' => $oneYearTo,
    'idempotency_key' => 'attendance-report-performance-2024',
    'projection_version' => 'attendance-report-v1',
    'batch_size' => $batchSize,
]);
$elapsedSeconds = (hrtime(true) - $startedAt) / 1_000_000_000;
$memoryDelta = memory_get_usage(true) - $memoryBefore;
$expectedBatches = (int) ceil($expectedOneYearRows / $batchSize);

$assert(($receipt['scanned_days'] ?? null) === $expectedOneYearRows, 'annual benchmark scans every generated official day exactly once');
$assert(($receipt['aggregate_count'] ?? null) === $staffCount, 'annual benchmark creates one frozen aggregate per worker');
$assert($projections->aggregates === $staffCount && $audit->events === 1, 'benchmark persists bounded aggregate/audit output instead of one write per day');
$assert($dimensions->calls === $expectedBatches && $fixture->reasonCalls === $expectedBatches, 'historical dimensions and reason lines are batched instead of queried per worker/day');
$assert($fixture->pageCalls <= $expectedBatches + 1, 'official-day repository uses a keyset cursor with at most one terminal empty read');
$assert($memoryDelta < 96 * 1024 * 1024, 'one-year 500-staff benchmark keeps incremental projection memory bounded');
$assert($elapsedSeconds < 15.0, 'benchmark remains below the conservative PHP-only regression ceiling');
foreach ($projections->insertedAggregates as $aggregate) {
    $assert(!array_key_exists('source_hash_context', $aggregate), 'hash contexts never reach persistence');
    $assert(!array_key_exists('source_days', $aggregate), 'all daily source rows are not retained in persisted aggregate payloads');
}

// A full five-year run is deliberately opt-in: it is a local capacity drill,
// not a database acceptance substitute. It reuses the same cursor fixture and
// makes accidental reintroduction of an all-rows fixture immediately visible.
if (getenv('STAFF_HR_RUN_FULL_REPORT_BENCHMARK') === '1') {
    $fullFixture = new AttendanceReportPerformanceFixture($staffCount);
    $fullDimensions = new AttendanceReportPerformanceDimensions();
    $fullProjections = new AttendanceReportPerformanceProjectionRepository();
    $fullProjector = new AttendanceReportProjector(
        new AttendanceReportPerformanceTransaction(),
        $fullFixture,
        $fullDimensions,
        $fullProjections,
        new AttendanceReportPerformanceAudit(),
        new DateTimeZone('Africa/Cairo'),
        static fn (): DateTimeImmutable => new DateTimeImmutable('2026-08-08 10:00:00', new DateTimeZone('UTC'))
    );
    $fullReceipt = $fullProjector->project(1, [
        'granularity' => 'range',
        'range_from' => '2022-01-01',
        'range_to' => '2026-12-31',
        'idempotency_key' => 'attendance-report-performance-five-years',
        'projection_version' => 'attendance-report-v1',
        'batch_size' => $batchSize,
    ]);
    $assert(($fullReceipt['scanned_days'] ?? null) === $expectedFiveYearRows, 'opt-in five-year drill scans the complete design-capacity fixture');
    $assert(($fullReceipt['aggregate_count'] ?? null) === $staffCount, 'opt-in five-year drill remains bounded by worker aggregates');
}

$projectorSource = (string) file_get_contents(
    dirname(__DIR__) . '/src/Modules/Attendance/Application/AttendanceReportProjector.php'
);
$assert(!str_contains($projectorSource, '$seenDayVersions'), 'projector does not retain an all-day version set across cursor batches');
$assert(!str_contains($projectorSource, "'source_days' => []"), 'projector does not retain full source-day arrays in aggregates');
$assert(str_contains($projectorSource, 'source_hash_context'), 'projector uses an incremental source fingerprint context');

if ($failures > 0) {
    fwrite(STDERR, "{$failures} attendance report performance test failure(s).\n");
    exit(1);
}

echo 'Attendance reporting performance benchmark passed in '
    . number_format($elapsedSeconds, 3)
    . 's with '
    . number_format($memoryDelta / 1024 / 1024, 2)
    . " MiB incremental memory.\n";
