<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/vendor/autoload.php';

use EduCore\Modules\Attendance\Application\EffectiveScheduleQueryService;
use EduCore\Modules\Attendance\Application\SchedulePolicyImpactQuery;
use EduCore\Modules\Attendance\Contracts\SchedulePolicyReadRepository;
use EduCore\Modules\Staff\Contracts\StaffPopulationAtDateQuery;

final class ScheduleImpactProbeRepository implements SchedulePolicyReadRepository
{
    /** @var array<int,array<string,mixed>> */
    public array $versions = [];
    /** @var array<int,list<array<string,mixed>>> */
    public array $publishedByStaff = [];
    /** @var array<int,list<array<string,mixed>>> */
    public array $exceptionsByStaff = [];
    /** @var array<int,list<array<string,mixed>>> */
    public array $changesByStaff = [];
    /** @var list<int> */
    public array $candidateCalls = [];

    public function listPolicies(array $filters = []): array { return []; }
    public function findPolicy(int $policyId): ?array { return null; }
    public function findVersion(int $versionId): ?array { return $this->versions[$versionId] ?? null; }
    public function candidateVersionsFor(int $staffId, array $assignmentSnapshot, DateTimeImmutable $at): array
    {
        $this->candidateCalls[] = $staffId;
        return $this->publishedByStaff[$staffId] ?? [];
    }
    public function calendarExceptionsFor(int $staffId, array $assignmentSnapshot, DateTimeImmutable $date): array
    {
        return $this->exceptionsByStaff[$staffId] ?? [];
    }
    public function approvedChangesFor(int $staffId, DateTimeImmutable $windowStart, DateTimeImmutable $windowEnd): array
    {
        return $this->changesByStaff[$staffId] ?? [];
    }
    public function listCalendarExceptions(array $filters = []): array { return []; }
}

final class ScheduleImpactProbePopulation implements StaffPopulationAtDateQuery
{
    /** @var array<string,array{staff:list<array<string,mixed>>,conflicts:list<array<string,mixed>>}> */
    public array $results = [];
    /** @var list<string> */
    public array $calls = [];

    public function forScope(string $scopeType, ?int $scopeId, DateTimeImmutable $atDate): array
    {
        $key = $scopeType . ':' . ($scopeId ?? 'global');
        $this->calls[] = $key;
        return $this->results[$key] ?? ['staff' => [], 'conflicts' => []];
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
        $assert(str_contains($exception->getMessage(), $expected), $message . ' (stable domain code)');
    }
};

$schedule = static function (string $start, string $end): array {
    $startMinutes = ((int) substr($start, 0, 2) * 60) + (int) substr($start, 3, 2);
    $endMinutes = ((int) substr($end, 0, 2) * 60) + (int) substr($end, 3, 2);
    return [
        'timezone' => 'Africa/Cairo',
        'days' => [[
            'weekday' => 1,
            'is_working_day' => true,
            'start_time' => $start . ':00',
            'end_time' => $end . ':00',
            'required_minutes' => $endMinutes - $startMinutes,
            'late_grace_minutes' => 15,
            'early_grace_minutes' => 10,
        ]],
    ];
};
$candidate = static function (
    int $versionId,
    string $scopeType,
    int $scopeId,
    int $priority,
    array $schedulePayload
): array {
    return [
        'policy_id' => $versionId,
        'policy_code' => 'P' . $versionId,
        'policy_name' => 'Policy ' . $versionId,
        'version_id' => $versionId,
        'version_no' => 1,
        'state' => 'published',
        'valid_from' => '2026-01-01 00:00:00',
        'valid_to' => null,
        'scope_id_record' => $versionId,
        'scope_type' => $scopeType,
        'scope_id' => $scopeId,
        'scope_priority' => $priority,
        'scope_valid_from' => '2026-01-01 00:00:00',
        'scope_valid_to' => null,
        'schedule' => $schedulePayload,
    ];
};
$staff = static function (
    int $staffId,
    int $assignmentId,
    int $orgUnitId,
    int $jobTitleId,
    array $groupIds = [],
    string $employmentStatus = 'active'
): array {
    return compact('staffId', 'assignmentId', 'orgUnitId', 'jobTitleId', 'groupIds', 'employmentStatus') + [
        'staff_id' => $staffId,
        'assignment_id' => $assignmentId,
        'org_unit_id' => $orgUnitId,
        'job_title_id' => $jobTitleId,
        'group_ids' => $groupIds,
        'employment_status' => $employmentStatus,
    ];
};

$asOf = new DateTimeImmutable('2026-01-05 08:00:00', new DateTimeZone('Africa/Cairo'));
$currentSchedule = $schedule('07:30', '14:30');
$draftSchedule = $schedule('08:00', '15:00');
$staffOverrideSchedule = $schedule('09:00', '13:00');

$repository = new ScheduleImpactProbeRepository();
$repository->versions[201] = [
    'id' => 201,
    'version_id' => 201,
    'policy_id' => 20,
    'policy_code' => 'DRAFT-20',
    'policy_name' => 'January policy',
    'version_no' => 2,
    'state' => 'draft',
    'valid_from' => '2026-01-01 00:00:00',
    'valid_to' => null,
    'schedule' => $draftSchedule,
    'scopes' => [
        ['id' => 1, 'scope_type' => 'global', 'scope_id' => 0, 'priority' => 0, 'valid_from' => '2026-01-01 00:00:00'],
        ['id' => 2, 'scope_type' => 'org_unit', 'scope_id' => 20, 'priority' => 0, 'valid_from' => '2026-01-01 00:00:00'],
        ['id' => 3, 'scope_type' => 'group', 'scope_id' => 40, 'priority' => 0, 'valid_from' => '2026-01-01 00:00:00'],
        ['id' => 4, 'scope_type' => 'staff', 'scope_id' => 12, 'priority' => 0, 'valid_from' => '2026-01-01 00:00:00'],
        // Duplicate scope rows must not enumerate the Staff population twice.
        ['id' => 5, 'scope_type' => 'group', 'scope_id' => 40, 'priority' => -1, 'valid_from' => '2026-01-01 00:00:00'],
    ],
];
$globalCurrent = $candidate(101, 'global', 0, 0, $currentSchedule);
$globalCurrent['valid_from'] = '2025-01-01 00:00:00';
$globalCurrent['scope_valid_from'] = '2025-01-01 00:00:00';
$repository->publishedByStaff = [
    10 => [$globalCurrent],
    11 => [$globalCurrent],
    12 => [$globalCurrent, $candidate(112, 'staff', 12, 10, $staffOverrideSchedule)],
];

$population = new ScheduleImpactProbePopulation();
$population->results = [
    'global:global' => [
        'staff' => [
            $staff(10, 100, 20, 30, [40]),
            $staff(11, 110, 21, 31),
            $staff(12, 120, 30, 32),
            $staff(14, 140, 30, 34),
            $staff(16, 160, 40, 36, [], 'terminated'),
            $staff(17, 0, 40, 37),
        ],
        'conflicts' => [],
    ],
    'org_unit:20' => [
        'staff' => [
            $staff(10, 100, 20, 30, [40]),
            $staff(14, 141, 20, 34),
        ],
        'conflicts' => [[
            'staff_id' => 13,
            'assignment_ids' => [131, 130, 131],
            'reason' => 'overlapping_primary_assignments',
        ]],
    ],
    'group:40' => [
        'staff' => [$staff(10, 100, 20, 30, [40])],
        'conflicts' => [],
    ],
    'staff:12' => [
        'staff' => [$staff(12, 120, 30, 32)],
        'conflicts' => [],
    ],
];

$impact = new SchedulePolicyImpactQuery(
    $repository,
    new EffectiveScheduleQueryService(),
    $population
);
$preview = $impact->previewDraft(201, $asOf, 100);

$assert($preview['summary']['population'] === 7, 'multi-scope population is de-duplicated by staff id');
$assert($preview['summary']['affected'] === 2, 'only workers whose effective result changes are affected');
$assert($preview['summary']['unchanged'] === 2, 'stronger staff override and inactive assignment remain unchanged');
$assert($preview['summary']['conflict_count'] === 3, 'ambiguous, inconsistent, and invalid assignments fail closed');
$assert($preview['summary']['truncated'] === false, 'complete preview is not marked truncated');
$assert(array_column($preview['affected_staff'], 'staff_id') === [10, 11], 'affected output is stable and sorted');
$assert($preview['affected_staff'][0]['proposed']['version_id'] === 201, 'proposed source identifies the draft version');
$assert($preview['affected_staff'][0]['proposed']['scope_type'] === 'group', 'resolver applies strongest matching draft scope');
$assert($preview['affected_staff'][0]['current']['version_id'] === 101, 'current source remains independently visible');
$assert($preview['affected_staff'][0]['explanation']['impact_reason_code'] === 'EFFECTIVE_SCHEDULE_WOULD_CHANGE', 'impact reason is explainable');
$assert($preview['affected_staff'][0]['proposed']['schedule']['days'][0]['start_time'] === '08:00:00', 'output contains serializable proposed schedule details');
$assert($population->calls === ['global:global', 'org_unit:20', 'group:40', 'staff:12'], 'duplicate scope population queries are suppressed');
$assert($repository->candidateCalls === [10, 11, 12], 'overlapping scopes never resolve one staff member more than once');
$assert($preview['conflicts'][0]['staff_id'] === 13 && $preview['conflicts'][0]['assignment_ids'] === [130, 131], 'population conflicts are normalized and sorted');
$assert($preview['conflicts'][1]['staff_id'] === 14 && $preview['conflicts'][1]['assignment_ids'] === [140, 141], 'inconsistent cross-scope assignment snapshots fail closed');
$assert($preview['conflicts'][2]['staff_id'] === 17 && $preview['conflicts'][2]['reason_code'] === 'STAFF_ASSIGNMENT_SNAPSHOT_INVALID', 'invalid assignment snapshot fails closed before resolution');

$limited = $impact->previewDraft(201, $asOf, 1);
$assert($limited['summary']['affected'] === 2 && count($limited['affected_staff']) === 1, 'limit caps rows without corrupting aggregate counts');
$assert($limited['summary']['conflict_count'] === 3 && count($limited['conflicts']) === 1, 'conflict aggregate remains complete when rows are capped');
$assert($limited['summary']['truncated'] === true, 'row cap is reported explicitly');

$missingCurrentRepository = new ScheduleImpactProbeRepository();
$missingCurrentRepository->versions[202] = array_replace($repository->versions[201], [
    'id' => 202,
    'version_id' => 202,
    'policy_id' => 21,
    'scopes' => [['scope_type' => 'global', 'scope_id' => 0, 'priority' => 0, 'valid_from' => '2026-01-01 00:00:00']],
]);
$missingCurrentPopulation = new ScheduleImpactProbePopulation();
$missingCurrentPopulation->results['global:global'] = [
    'staff' => [$staff(20, 200, 50, 60)],
    'conflicts' => [],
];
$missingCurrent = (new SchedulePolicyImpactQuery(
    $missingCurrentRepository,
    new EffectiveScheduleQueryService(),
    $missingCurrentPopulation
))->previewDraft(202, $asOf);
$assert($missingCurrent['summary']['affected'] === 1, 'a first schedule can affect staff with no current policy');
$assert($missingCurrent['affected_staff'][0]['current']['reason_code'] === 'SCHEDULE_NOT_FOUND', 'missing current schedule is explained rather than treated as a conflict');

$conflictRepository = new ScheduleImpactProbeRepository();
$conflictRepository->versions[203] = array_replace($repository->versions[201], [
    'id' => 203,
    'version_id' => 203,
    'policy_id' => 22,
    'scopes' => [['scope_type' => 'global', 'scope_id' => 0, 'priority' => 0, 'valid_from' => '2026-01-01 00:00:00']],
]);
$conflictRepository->publishedByStaff[21] = [
    $candidate(301, 'group', 70, 5, $currentSchedule),
    $candidate(302, 'group', 71, 5, $currentSchedule),
];
$conflictPopulation = new ScheduleImpactProbePopulation();
$conflictPopulation->results['global:global'] = [
    'staff' => [$staff(21, 210, 50, 60, [70, 71])],
    'conflicts' => [],
];
$currentConflict = (new SchedulePolicyImpactQuery(
    $conflictRepository,
    new EffectiveScheduleQueryService(),
    $conflictPopulation
))->previewDraft(203, $asOf);
$assert($currentConflict['summary']['affected'] === 0 && $currentConflict['summary']['conflict_count'] === 1, 'current policy tie is never hidden by a draft');
$assert($currentConflict['conflicts'][0]['phase'] === 'current', 'conflict identifies the failing resolution phase');
$assert($currentConflict['conflicts'][0]['conflict_ids'] === [301, 302], 'current policy tie evidence is preserved');

$roundingRepository = new ScheduleImpactProbeRepository();
$roundingRepository->versions[205] = array_replace($repository->versions[201], [
    'id' => 205,
    'version_id' => 205,
    'policy_id' => 25,
    'rounding_rule' => 'floor_5',
    'schedule' => $currentSchedule,
    'scopes' => [['scope_type' => 'global', 'scope_id' => 0, 'priority' => 0, 'valid_from' => '2026-01-01 00:00:00']],
]);
$roundingRepository->publishedByStaff[22] = [$globalCurrent];
$roundingPopulation = new ScheduleImpactProbePopulation();
$roundingPopulation->results['global:global'] = [
    'staff' => [$staff(22, 220, 50, 60)],
    'conflicts' => [],
];
$roundingOnly = (new SchedulePolicyImpactQuery(
    $roundingRepository,
    new EffectiveScheduleQueryService(),
    $roundingPopulation
))->previewDraft(205, $asOf);
$assert($roundingOnly['summary']['affected'] === 1, 'rounding-only policy change is included in impact');
$assert($roundingOnly['affected_staff'][0]['current']['schedule'] === $roundingOnly['affected_staff'][0]['proposed']['schedule'], 'rounding-only fixture keeps work windows identical');
$assert($roundingOnly['affected_staff'][0]['current']['rounding_rule'] === 'none', 'current rounding rule is explicit');
$assert($roundingOnly['affected_staff'][0]['proposed']['rounding_rule'] === 'floor_5', 'proposed rounding rule is explicit');
$assert($roundingOnly['affected_staff'][0]['explanation']['rounding_rule'] === 'floor_5', 'impact explanation carries the proposed rounding rule');

$repository->versions[204] = array_replace(
    $repository->versions[201],
    ['id' => 204, 'version_id' => 204, 'state' => 'published']
);
$assertThrows(
    static fn (): array => $impact->previewDraft(204, $asOf),
    'SCHEDULE_PREVIEW_VERSION_NOT_DRAFT',
    'preview refuses mutable semantics for a published version'
);
$assertThrows(
    static fn (): array => $impact->previewDraft(999, $asOf),
    'SCHEDULE_PREVIEW_VERSION_NOT_FOUND',
    'unknown draft fails explicitly'
);
$assertThrows(
    static fn (): array => $impact->previewDraft(201, $asOf, 0),
    'SCHEDULE_PREVIEW_LIMIT_INVALID',
    'invalid row limit is rejected'
);

$impactSource = (string) file_get_contents(
    dirname(__DIR__) . '/src/Modules/Attendance/Application/SchedulePolicyImpactQuery.php'
);
$assert(!str_contains($impactSource, 'use PDO;'), 'application impact query has no PDO dependency');
$assert(!str_contains($impactSource, '\\Infrastructure\\'), 'application impact query has no infrastructure dependency');
$assert(
    preg_match('/->\s*(?:prepare|query|exec|beginTransaction|commit|rollBack)\s*\(/', $impactSource) !== 1,
    'impact preview is structurally read-only and owns no database transaction'
);

if ($failures > 0) {
    fwrite(STDERR, "{$failures} staff-HR schedule impact failure(s).\n");
    exit(1);
}

echo "Staff-HR schedule impact tests passed.\n";
