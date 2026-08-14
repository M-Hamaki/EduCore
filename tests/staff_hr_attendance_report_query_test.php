<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/vendor/autoload.php';

use EduCore\Modules\Attendance\Application\AttendanceReportQueryService;
use EduCore\Modules\Attendance\Application\AttendanceReportScope;
use EduCore\Modules\Attendance\Contracts\AttendanceReportReadRepository;
use EduCore\Modules\Staff\Contracts\StaffAttendanceReportDimensionQuery;

final class AttendanceReportProbeRepository implements AttendanceReportReadRepository
{
    /** @var list<array<string,mixed>> */
    public array $calls = [];
    /** @var list<array<string,mixed>> */
    public array $rows = [];
    /** @var list<array<string,mixed>> */
    public array $historicalRows = [];
    /** @var list<array<string,mixed>> */
    public array $reasonRows = [];

    public function officialDays(array $filters): array
    {
        $this->calls[] = $filters;
        $rows = $filters['as_of'] === null ? $this->rows : $this->historicalRows;
        $allowed = $filters['staff_user_ids'];
        return array_values(array_filter(
            $rows,
            static fn (array $row): bool => (string) $row['work_date'] >= (string) $filters['date_from']
                && (string) $row['work_date'] <= (string) $filters['date_to']
                && ($allowed === null || in_array((int) $row['staff_user_id'], $allowed, true))
        ));
    }

    public function reasonLinesForDayVersions(array $dayVersionIds): array
    {
        return array_values(array_filter(
            $this->reasonRows,
            static fn (array $reason): bool => in_array((int) $reason['day_version_id'], $dayVersionIds, true)
        ));
    }
}

final class AttendanceReportProbeDimensions implements StaffAttendanceReportDimensionQuery
{
    /** @var array<int,array<string,mixed>> */
    public array $dimensions = [];
    /** @var list<array<string,mixed>> */
    public array $conflicts = [];
    /** @var list<array<string,mixed>> */
    public array $calls = [];

    public function forAttendanceDays(array $dayReferences): array
    {
        $this->calls[] = $dayReferences;
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

$failures = 0;
$assert = static function (bool $condition, string $message) use (&$failures): void {
    if (!$condition) {
        fwrite(STDERR, "FAIL: {$message}\n");
        ++$failures;
    }
};
$assertThrows = static function (callable $operation, string $expected, string $message) use ($assert): void {
    try {
        $operation();
        $assert(false, $message);
    } catch (Throwable $exception) {
        $assert(str_contains($exception->getMessage(), $expected), $message . ' (stable error code)');
    }
};
$day = static function (
    int $id,
    int $staffId,
    string $date,
    int $assignmentId,
    string $status,
    int $required,
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
        'schedule_policy_version_id' => 77,
        'calendar_exception_id' => null,
        'expected_start' => $date . ' 07:30:00.000000',
        'expected_end' => $date . ' 14:30:00.000000',
        'first_in' => $date . ' 07:28:00.000000',
        'last_out' => $date . ' 14:32:00.000000',
        'required_minutes' => $required,
        'worked_minutes' => $worked,
        'covered_late_minutes' => $coveredLate,
        'covered_early_minutes' => $coveredEarly,
        'mission_minutes' => $mission,
        'leave_minutes' => $leave,
        'late_minutes' => $late,
        'early_leave_minutes' => $early,
        'missing_minutes' => $missing,
        'status' => $status,
        'run_id' => 91,
        'calculation_mode' => 'official',
        'engine_version' => 'attendance-v2',
        'source_fingerprint' => str_repeat('a', 64),
        'officialized_at' => $date . ' 15:00:00.000000',
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

$repository = new AttendanceReportProbeRepository();
$repository->rows = [
    $day(1001, 11, '2026-08-01', 201, 'present', 420, 420),
    $day(1002, 11, '2026-08-02', 201, 'absent', 420, 0, 0, 0, 0, 0, 0, 0, 420),
    $day(1003, 12, '2026-08-02', 202, 'partial', 420, 370, 30, 0, 0, 0, 20, 0, 20),
    $day(1004, 12, '2026-08-03', 202, 'absent', 420, 0, 0, 0, 0, 420, 0, 0, 0),
];
$repository->historicalRows = [
    $day(901, 11, '2026-08-01', 201, 'present', 420, 420),
    $day(902, 11, '2026-08-02', 201, 'partial', 420, 390, 0, 0, 0, 0, 15, 0, 15),
];
$repository->reasonRows = [
    [
        'day_version_id' => 1003,
        'line_no' => 1,
        'reason_code' => 'APPROVED_LATE_COVERAGE',
        'from_at' => '2026-08-02 07:30:00.000000',
        'to_at' => '2026-08-02 08:00:00.000000',
        'minutes' => 30,
        'source_type' => 'permission_request',
        'source_id' => 700,
        'explanation' => 'تغطية إذن معتمدة.',
        'raw_payload_ref' => 'private/raw/never-expose.json',
    ],
    [
        'day_version_id' => 1002,
        'line_no' => 1,
        'reason_code' => 'MISSING_PUNCH',
        'from_at' => null,
        'to_at' => null,
        'minutes' => 420,
        'source_type' => 'attendance_calculation',
        'source_id' => null,
        'explanation' => 'لا توجد بصمة مكتملة.',
    ],
];

$dimensions = new AttendanceReportProbeDimensions();
$dimensions->dimensions = [
    1001 => $dimension(1001, 11, 201, 1, 10, [5]),
    1002 => $dimension(1002, 11, 201, 1, 10, [5]),
    1003 => $dimension(1003, 12, 202, 2, 20, [9]),
    1004 => $dimension(1004, 12, 202, 2, 20, [9]),
    901 => $dimension(901, 11, 201, 1, 10, [5]),
    902 => $dimension(902, 11, 201, 1, 10, [5]),
];

$service = new AttendanceReportQueryService(
    $repository,
    $dimensions,
    new DateTimeZone('Africa/Cairo')
);
$scope = AttendanceReportScope::forStaffIds([12, 11]);
$report = $service->query([
    'date_from' => '2026-08-01',
    'date_to' => '2026-08-03',
    'page_size' => 2,
], $scope);

$assert(($repository->calls[0]['staff_user_ids'] ?? null) === [11, 12], 'repository receives the authorized, normalized staff scope only');
$assert(($repository->calls[0]['scan_limit'] ?? 0) === 10001, 'raw detail scan is bounded before a projection is required');
$assert(($report['page']['total_rows'] ?? null) === 4 && count($report['rows'] ?? []) === 2, 'page details are bounded while totals use the same filtered details');
$assert(($report['totals']['official_days'] ?? null) === 4, 'official total equals the complete detail row set');
$assert(($report['totals']['eligible_workdays'] ?? null) === 3, 'full approved leave is excluded from the absence denominator');
$assert(($report['totals']['absent_days'] ?? null) === 1, 'uncovered absence is counted once');
$assert(($report['totals']['partial_days'] ?? null) === 1, 'partial official day remains distinct from absence');
$assert(($report['totals']['approved_permission_days'] ?? null) === 1, 'approved coverage is visible as a distinct report measure');
$assert(($report['totals']['leave_days'] ?? null) === 1, 'leave remains separate from unjustified absence');
$assert(($report['totals']['absence_percentage'] ?? null) === 33.33, 'absence percentage uses eligible workdays as its denominator');
$assert(($report['rows'][0]['dimensions']['group_ids'] ?? null) === [5], 'dated Staff dimensions accompany every official day');
$assert(($report['rows'][1]['official_version']['calculation_mode'] ?? null) === 'official', 'drill-down identifies the official calculation version');

$pageTwo = $service->query([
    'date_from' => '2026-08-01',
    'date_to' => '2026-08-03',
    'page' => 2,
    'page_size' => 2,
], $scope);
$assert(($pageTwo['rows'][0]['day_version_id'] ?? null) === 1003, 'second page keeps deterministic official-day ordering');
$presented = json_encode($pageTwo, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
$assert(!str_contains((string) $presented, 'never-expose.json'), 'drill-down reason DTO excludes raw evidence locations');
$assert(($pageTwo['rows'][0]['reasons'][0]['reason_code'] ?? null) === 'APPROVED_LATE_COVERAGE', 'drill-down exposes the explainable reason code');

$groupReport = $service->query([
    'date_from' => '2026-08-01',
    'date_to' => '2026-08-03',
    'group_id' => 9,
    'page_size' => 50,
], $scope);
$assert(($groupReport['page']['total_rows'] ?? null) === 2, 'group filter uses historical group membership rather than the current profile');
$assert(($groupReport['totals']['eligible_workdays'] ?? null) === 1, 'dimension-filter totals still use the correct denominator');

$unitReport = $service->query([
    'date_from' => '2026-08-01',
    'date_to' => '2026-08-03',
    'org_unit_id' => 1,
], $scope);
$assert(($unitReport['page']['total_rows'] ?? null) === 2, 'force/unit report uses the dated organizational unit rather than a current profile value');

$titleReport = $service->query([
    'date_from' => '2026-08-01',
    'date_to' => '2026-08-03',
    'job_title_id' => 20,
], $scope);
$assert(($titleReport['page']['total_rows'] ?? null) === 2, 'job-title report uses the dated title snapshot');

$individualReport = $service->query([
    'date_from' => '2026-08-01',
    'date_to' => '2026-08-03',
    'staff_user_id' => 11,
], $scope);
$assert(($individualReport['page']['total_rows'] ?? null) === 2, 'individual period report is narrowed by the authorized staff identifier');

$absenceReport = $service->query([
    'date_from' => '2026-08-01',
    'date_to' => '2026-08-03',
    'violation' => 'absence',
], $scope);
$assert(($absenceReport['page']['total_rows'] ?? null) === 1 && ($absenceReport['rows'][0]['day_version_id'] ?? null) === 1002, 'absence filter excludes an approved full-leave day');

$historic = $service->query([
    'date_from' => '2026-08-01',
    'date_to' => '2026-08-03',
    'as_of' => '2026-08-03 12:00:00',
], $scope);
$assert(($repository->calls[array_key_last($repository->calls)]['as_of'] ?? null) === '2026-08-03 09:00:00.000000', 'as-of history is normalized to the database UTC instant');
$assert(($historic['page']['total_rows'] ?? null) === 2 && ($historic['rows'][1]['day_version_id'] ?? null) === 902, 'as-of query can expose a former official version without mutating history');
$assert($historic['warnings'] !== [], 'as-of report clearly warns that a historical official version is shown');

$currentRowsBeforeCorrection = $repository->rows;
$repository->rows[1]['status'] = 'present';
$repository->rows[1]['worked_minutes'] = 420;
$repository->rows[1]['missing_minutes'] = 0;
$reopenedCurrent = $service->query([
    'date_from' => '2026-08-01',
    'date_to' => '2026-08-03',
], $scope);
$lockedSnapshotAgain = $service->query([
    'date_from' => '2026-08-01',
    'date_to' => '2026-08-03',
    'as_of' => '2026-08-03 12:00:00',
], $scope);
$assert(($reopenedCurrent['totals']['absent_days'] ?? null) === 0, 'a successor official result becomes visible only in the current reopened view');
$assert(($lockedSnapshotAgain['totals']['absent_days'] ?? null) === ($historic['totals']['absent_days'] ?? null)
    && ($lockedSnapshotAgain['rows'][1]['day_version_id'] ?? null) === 902, 'locked historical report remains stable after a later official successor exists');
$repository->rows = $currentRowsBeforeCorrection;

$callsBeforeDenied = count($repository->calls);
$assertThrows(
    static fn () => $service->query(['staff_user_id' => 13], $scope),
    'ATTENDANCE_REPORT_SCOPE_DENIED',
    'requested employee outside the supplied scope is rejected before reading report rows'
);
$assert(count($repository->calls) === $callsBeforeDenied, 'out-of-scope request does not query report data');

$dimensions->conflicts = [['day_version_id' => 1001, 'reason_code' => 'ATTENDANCE_REPORT_ASSIGNMENT_NOT_EFFECTIVE']];
$assertThrows(
    static fn () => $service->query(['date_from' => '2026-08-01', 'date_to' => '2026-08-03'], $scope),
    'ATTENDANCE_REPORT_DIMENSION_UNRESOLVED',
    'missing or ambiguous historical dimension fails closed instead of using current data'
);
$dimensions->conflicts = [];

$assertThrows(
    static fn () => $service->query(['date_from' => '2026-08-04', 'date_to' => '2026-08-01'], $scope),
    'تاريخ نهاية التقرير',
    'invalid date range is rejected'
);
$assertThrows(
    static fn () => $service->query(['violation' => 'unsafe'], $scope),
    'نوع المخالفة',
    'unknown violation filter cannot reach the repository'
);

$applicationSource = (string) file_get_contents(
    dirname(__DIR__) . '/src/Modules/Attendance/Application/AttendanceReportQueryService.php'
);
$attendanceRepositorySource = (string) file_get_contents(
    dirname(__DIR__) . '/src/Modules/Attendance/Infrastructure/PdoAttendanceReportReadRepository.php'
);
$staffDimensionSource = (string) file_get_contents(
    dirname(__DIR__) . '/src/Modules/Staff/Infrastructure/PdoStaffAttendanceReportDimensionQuery.php'
);
$assert(!str_contains($applicationSource, 'use PDO;'), 'report application service has no PDO dependency');
$assert(!str_contains($applicationSource, 'staff_assignments'), 'Attendance application service does not reach Staff assignment tables');
$assert(!str_contains($attendanceRepositorySource, 'staff_assignments'), 'Attendance repository does not join Staff-owned tables');
$assert(str_contains($staffDimensionSource, 'FROM staff_assignments'), 'historical Staff dimension adapter owns its assignment read');
$assert(str_contains($staffDimensionSource, 'staff_policy_group_memberships'), 'historical Staff dimension adapter resolves group membership at the work date');

if ($failures > 0) {
    fwrite(STDERR, "{$failures} attendance report query test failure(s).\n");
    exit(1);
}

echo "Attendance report query tests passed.\n";
