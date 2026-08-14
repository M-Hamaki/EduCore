<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/vendor/autoload.php';

use EduCore\Modules\Attendance\Application\EffectiveScheduleQueryService;
use EduCore\Modules\Attendance\Domain\Schedule\WorkSchedule;

date_default_timezone_set('Africa/Cairo');

$failures = 0;
$checks = 0;
$assert = static function (bool $condition, string $message) use (&$failures, &$checks): void {
    ++$checks;
    if (!$condition) {
        fwrite(STDERR, "FAIL: {$message}\n");
        ++$failures;
    }
};
$assertSame = static function (mixed $expected, mixed $actual, string $message) use ($assert): void {
    $assert(
        $expected === $actual,
        $message . ' (expected ' . var_export($expected, true) . ', got ' . var_export($actual, true) . ')'
    );
};
$scenarioResult = static function (string $scenario, int $before) use (&$failures): void {
    echo $scenario . ($failures === $before ? " PASS\n" : " FAIL\n");
};

$weeklySchedule = static function (
    string $start,
    string $end,
    int $requiredMinutes,
    array $dayOverrides = []
): array {
    $days = [];
    for ($weekday = 1; $weekday <= 7; ++$weekday) {
        $days[] = array_merge([
            'weekday' => $weekday,
            'is_working_day' => true,
            'start_time' => $start,
            'end_time' => $end,
            'end_day_offset' => 0,
            'required_minutes' => $requiredMinutes,
            'late_grace_minutes' => 0,
            'early_grace_minutes' => 0,
        ], $dayOverrides);
    }

    return ['timezone' => 'Africa/Cairo', 'days' => $days];
};

$candidate = static function (
    int $policyId,
    int $versionId,
    int $versionNo,
    string $scopeType,
    int $scopeId,
    int $priority,
    string $validFrom,
    ?string $validTo,
    array $schedule,
    array $extra = []
): array {
    return array_merge([
        'policy_id' => $policyId,
        'policy_code' => 'ACCEPTANCE-P' . $policyId,
        'policy_name' => 'Acceptance policy ' . $policyId,
        'version_id' => $versionId,
        'version_no' => $versionNo,
        'state' => 'published',
        'valid_from' => $validFrom,
        'valid_to' => $validTo,
        'scope_id_record' => $versionId,
        'scope_type' => $scopeType,
        'scope_id' => $scopeId,
        'scope_priority' => $priority,
        'scope_valid_from' => $validFrom,
        'scope_valid_to' => $validTo,
        'schedule' => $schedule,
    ], $extra);
};

$resolver = new EffectiveScheduleQueryService();
$staffId = 501;
$assignment = [
    'assignment_id' => 9001,
    'org_unit_id' => 200,
    'job_title_id' => 300,
    'group_ids' => [400, 401],
    'employment_status' => 'active',
];
$effectiveDay = new DateTimeImmutable('2026-01-05 00:00:00');

// Q01: global -> job title -> organization -> group -> staff, with an exact group tie failing closed.
$q01Failures = $failures;
$global = $candidate(1, 1001, 1, 'global', 0, 0, '2026-01-01 00:00:00', null, $weeklySchedule('07:30', '14:30', 420));
$jobTitle = $candidate(2, 1002, 1, 'job_title', 300, 0, '2026-01-01 00:00:00', null, $weeklySchedule('07:45', '14:45', 420));
$organization = $candidate(3, 1003, 1, 'org_unit', 200, 0, '2026-01-01 00:00:00', null, $weeklySchedule('08:00', '15:00', 420));
$group = $candidate(4, 1004, 1, 'group', 400, 10, '2026-01-01 00:00:00', null, $weeklySchedule('07:00', '14:00', 420));
$staff = $candidate(5, 1005, 1, 'staff', $staffId, 0, '2026-01-01 00:00:00', null, $weeklySchedule('09:00', '16:00', 420));

$precedenceCases = [
    ['global', [$global], 1001, '07:30'],
    ['job_title', [$global, $jobTitle], 1002, '07:45'],
    ['org_unit', [$global, $jobTitle, $organization], 1003, '08:00'],
    ['group', [$global, $jobTitle, $organization, $group], 1004, '07:00'],
    ['staff', [$global, $jobTitle, $organization, $group, $staff], 1005, '09:00'],
];
foreach ($precedenceCases as [$expectedScope, $candidates, $expectedVersion, $expectedStart]) {
    $resolution = $resolver->resolveFromCandidates($staffId, $effectiveDay, $assignment, $candidates);
    $assertSame('working', $resolution['status'], "Q01 {$expectedScope} resolution is a working day");
    $assertSame($expectedVersion, $resolution['selected']['version_id'], "Q01 {$expectedScope} selects the expected version");
    $assertSame($expectedScope, $resolution['explanation']['scope_type'], "Q01 {$expectedScope} records the selected source");
    $assertSame($expectedVersion, $resolution['explanation']['version_id'], "Q01 {$expectedScope} records the selected version");
    $assert(
        $resolution['selected']['schedule'] instanceof WorkSchedule,
        "Q01 {$expectedScope} returns the real WorkSchedule domain object"
    );
    $assertSame(
        $expectedStart,
        $resolution['selected']['schedule']->workWindow($effectiveDay)['start']->format('H:i'),
        "Q01 {$expectedScope} applies the expected work window"
    );
}

$equalGroupA = $candidate(14, 1014, 1, 'group', 400, 50, '2026-01-01 00:00:00', null, $weeklySchedule('06:30', '13:30', 420));
$equalGroupB = $candidate(15, 1015, 1, 'group', 401, 50, '2026-01-01 00:00:00', null, $weeklySchedule('08:30', '15:30', 420));
$groupConflict = $resolver->resolveFromCandidates(
    $staffId,
    $effectiveDay,
    $assignment,
    [$global, $equalGroupA, $equalGroupB]
);
$assertSame('unresolved', $groupConflict['status'], 'Q01 equal active groups fail closed');
$assertSame('SCHEDULE_CONFLICT', $groupConflict['reason_code'], 'Q01 equal active groups return a stable publication-blocking reason');
$assertSame([1014, 1015], $groupConflict['conflicts'], 'Q01 equal active groups identify both conflicting versions deterministically');
$assertSame(null, $groupConflict['selected'], 'Q01 conflict never falls back silently to the global policy');
$scenarioResult('Q01', $q01Failures);

// Q02: a published successor takes over at its half-open start boundary without rewriting open-ended v1.
$q02Failures = $failures;
$v1 = $candidate(
    20,
    2001,
    1,
    'global',
    0,
    0,
    '2026-01-01 00:00:00',
    null,
    $weeklySchedule('07:30', '14:30', 420)
);
$v2 = $candidate(
    20,
    2002,
    2,
    'global',
    0,
    0,
    '2026-02-01 00:00:00',
    null,
    $weeklySchedule('08:30', '15:30', 420),
    ['supersedes_id' => 2001]
);
$oldDay = new DateTimeImmutable('2026-01-31 00:00:00');
$lastV1Instant = new DateTimeImmutable('2026-01-31 23:59:59.999999');
$v2Boundary = new DateTimeImmutable('2026-02-01 00:00:00');
$beforeV2Publication = $resolver->resolveFromCandidates($staffId, $oldDay, $assignment, [$v1]);
$afterV2PublicationOldDay = $resolver->resolveFromCandidates($staffId, $oldDay, $assignment, [$v1, $v2]);
$lastV1Resolution = $resolver->resolveFromCandidates($staffId, $lastV1Instant, $assignment, [$v1, $v2]);
$v2Resolution = $resolver->resolveFromCandidates($staffId, $v2Boundary, $assignment, [$v1, $v2]);

$assertSame(2001, $beforeV2Publication['selected']['version_id'], 'Q02 January initially resolves with v1');
$assertSame(2001, $afterV2PublicationOldDay['selected']['version_id'], 'Q02 publishing v2 does not change a January result');
$assertSame(
    $beforeV2Publication['selected']['schedule']->toArray(),
    $afterV2PublicationOldDay['selected']['schedule']->toArray(),
    'Q02 historical schedule payload remains identical after v2 exists'
);
$assertSame(2001, $lastV1Resolution['selected']['version_id'], 'Q02 v1 remains active through the instant before its exclusive end');
$assertSame(2002, $v2Resolution['selected']['version_id'], 'Q02 v2 becomes active exactly at the half-open boundary');
$assertSame(null, $afterV2PublicationOldDay['selected']['valid_to'], 'Q02 v1 remains an immutable open-ended historical row');
$assertSame(2001, $v2Resolution['selected']['supersedes_id'], 'Q02 v2 preserves its predecessor lineage');
$assertSame('08:30', $v2Resolution['selected']['schedule']->workWindow($v2Boundary)['start']->format('H:i'), 'Q02 v2 applies only from its boundary onward');
$scenarioResult('Q02', $q02Failures);

// Q03 schedule-layer proof: one overnight window/capture range, then a holiday makes the day non-working.
$q03Failures = $failures;
$overnightPayload = [
    'timezone' => 'Africa/Cairo',
    'days' => [[
        'weekday' => 1,
        'is_working_day' => true,
        'start_time' => '20:00:00',
        'end_time' => '04:00:00',
        'end_day_offset' => 1,
        'required_minutes' => 480,
        'entry_window_before_minutes' => 30,
        'entry_window_after_minutes' => 90,
        'exit_window_before_minutes' => 120,
        'exit_window_after_minutes' => 45,
    ]],
];
$overnight = $candidate(30, 3001, 1, 'global', 0, 0, '2026-01-01 00:00:00', null, $overnightPayload);
$overnightResolution = $resolver->resolveFromCandidates($staffId, $effectiveDay, $assignment, [$overnight]);
$nightWindow = $overnightResolution['selected']['schedule']->workWindow($effectiveDay);
$entryPunch = new DateTimeImmutable('2026-01-05 19:55:00');
$exitPunch = new DateTimeImmutable('2026-01-06 04:03:00');

$assertSame('working', $overnightResolution['status'], 'Q03 overnight work date resolves as working');
$assertSame('2026-01-05 20:00', $nightWindow['start']->format('Y-m-d H:i'), 'Q03 overnight window starts on the work date');
$assertSame('2026-01-06 04:00', $nightWindow['end']->format('Y-m-d H:i'), 'Q03 overnight window ends on the following date');
$assertSame('2026-01-05 19:30', $nightWindow['entry_capture_start']->format('Y-m-d H:i'), 'Q03 entry capture begins before the overnight shift');
$assertSame('2026-01-05 21:30', $nightWindow['entry_capture_end']->format('Y-m-d H:i'), 'Q03 entry capture closes on the work date');
$assertSame('2026-01-06 02:00', $nightWindow['exit_capture_start']->format('Y-m-d H:i'), 'Q03 exit capture begins on the following date');
$assertSame('2026-01-06 04:45', $nightWindow['exit_capture_end']->format('Y-m-d H:i'), 'Q03 exit capture closes on the following date');
$assert(
    $entryPunch >= $nightWindow['entry_capture_start'] && $entryPunch <= $nightWindow['entry_capture_end'],
    'Q03 19:55 punch belongs to the overnight entry capture window'
);
$assert(
    $exitPunch >= $nightWindow['exit_capture_start'] && $exitPunch <= $nightWindow['exit_capture_end'],
    'Q03 04:03 punch belongs to the same overnight work-date capture window'
);

$holidayResolution = $resolver->resolveFromCandidates(
    $staffId,
    $effectiveDay,
    $assignment,
    [$overnight],
    [[
        'id' => 3901,
        'calendar_date' => '2026-01-05',
        'scope_type' => 'global',
        'scope_id' => 0,
        'priority' => 0,
        'exception_type' => 'holiday',
        'reason' => 'Q03 acceptance holiday',
        'status' => 'active',
        'created_at' => '2026-01-01 00:00:00',
    ]]
);
$assertSame('non_working', $holidayResolution['status'], 'Q03 holiday changes the schedule layer to non-working');
$assertSame('CALENDAR_HOLIDAY', $holidayResolution['reason_code'], 'Q03 holiday result remains explicitly explainable');
$assertSame(3901, $holidayResolution['calendar_exception']['id'], 'Q03 holiday evidence retains its calendar exception');
$assertSame(3001, $holidayResolution['explanation']['version_id'], 'Q03 holiday result retains the underlying schedule version provenance');
$scenarioResult('Q03', $q03Failures);

if ($failures > 0) {
    fwrite(STDERR, "{$failures} failure(s) across {$checks} schedule acceptance checks.\n");
    exit(1);
}

echo "Staff-HR schedule acceptance Q01-Q03 passed ({$checks} checks).\n";
