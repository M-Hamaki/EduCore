<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/vendor/autoload.php';

use EduCore\Modules\Attendance\Application\EffectiveScheduleQueryService;
use EduCore\Modules\Attendance\Domain\Schedule\WorkSchedule;

$failures = 0;
$assert = static function (bool $condition, string $message) use (&$failures): void {
    if (!$condition) {
        fwrite(STDERR, "FAIL: {$message}\n");
        ++$failures;
    }
};
$assertThrows = static function (callable $callback, string $messagePart, string $message) use ($assert): void {
    try {
        $callback();
        $assert(false, $message);
    } catch (Throwable $exception) {
        $assert(str_contains($exception->getMessage(), $messagePart), $message . ' (domain code)');
    }
};

$splitNight = WorkSchedule::fromArray([
    'timezone' => 'Africa/Cairo',
    'season_start_mmdd' => '11-01',
    'season_end_mmdd' => '02-28',
    'days' => [[
        'weekday' => 1,
        'is_working_day' => true,
        'start_time' => '20:00:00',
        'end_time' => '04:00:00',
        'end_day_offset' => 1,
        'required_minutes' => 420,
        'late_grace_minutes' => 10,
        'early_grace_minutes' => 5,
        'entry_window_before_minutes' => 60,
        'entry_window_after_minutes' => 120,
        'exit_window_before_minutes' => 120,
        'exit_window_after_minutes' => 90,
        'segments' => [
            ['sequence_no' => 1, 'segment_type' => 'work', 'start_time' => '20:00:00', 'end_time' => '00:00:00', 'start_day_offset' => 0, 'end_day_offset' => 1, 'counts_required_minutes' => true],
            ['sequence_no' => 2, 'segment_type' => 'unpaid_break', 'start_time' => '00:00:00', 'end_time' => '01:00:00', 'start_day_offset' => 1, 'end_day_offset' => 1, 'counts_required_minutes' => false],
            ['sequence_no' => 3, 'segment_type' => 'work', 'start_time' => '01:00:00', 'end_time' => '04:00:00', 'start_day_offset' => 1, 'end_day_offset' => 1, 'counts_required_minutes' => true],
        ],
    ]],
]);

$winterMonday = new DateTimeImmutable('2026-01-05');
$summerMonday = new DateTimeImmutable('2026-06-01');
$assert($splitNight->isSeasonallyActive($winterMonday), 'cross-year winter season includes January');
$assert(!$splitNight->isSeasonallyActive($summerMonday), 'cross-year winter season excludes June');
$assert($splitNight->isWorkingDay($winterMonday), 'seasonal Monday is a working day');
$assert($splitNight->requiredMinutes($winterMonday) === 420, 'unpaid break is excluded from required minutes');
$segments = $splitNight->segmentsForDate($winterMonday);
$assert(count($segments) === 3, 'split shift keeps all work and break segments');
$assert($segments[0]['start']->format('Y-m-d H:i') === '2026-01-05 20:00', 'overnight first segment starts on work date');
$assert($segments[2]['end']->format('Y-m-d H:i') === '2026-01-06 04:00', 'overnight last segment ends on next date');
$window = $splitNight->workWindow($winterMonday);
$assert($window['start']->format('Y-m-d H:i') === '2026-01-05 20:00', 'work window starts at first work segment');
$assert($window['end']->format('Y-m-d H:i') === '2026-01-06 04:00', 'work window spans midnight once');
$assert($window['entry_capture_start']->format('H:i') === '19:00', 'entry capture window uses explicit before minutes');
$assert($window['exit_capture_end']->format('Y-m-d H:i') === '2026-01-06 05:30', 'exit capture window uses explicit after minutes');

$assertThrows(
    static fn (): WorkSchedule => WorkSchedule::fromArray([
        'timezone' => 'Africa/Cairo',
        'days' => [[
            'weekday' => 1,
            'is_working_day' => true,
            'start_time' => '07:30:00',
            'end_time' => '15:30:00',
            'required_minutes' => 420,
            'segments' => [
                ['sequence_no' => 1, 'segment_type' => 'work', 'start_time' => '07:30:00', 'end_time' => '12:00:00'],
                ['sequence_no' => 2, 'segment_type' => 'work', 'start_time' => '11:00:00', 'end_time' => '15:30:00'],
            ],
        ]],
    ]),
    'SCHEDULE_SEGMENT_OVERLAP',
    'overlapping split-shift segments are rejected'
);
$assertThrows(
    static fn (): WorkSchedule => WorkSchedule::fromArray([
        'timezone' => 'Africa/Cairo',
        'days' => [[
            'weekday' => 1,
            'is_working_day' => true,
            'start_time' => '07:30:00',
            'end_time' => '14:30:00',
            'required_minutes' => 360,
            'segments' => [[
                'sequence_no' => 1,
                'segment_type' => 'work',
                'start_time' => '08:30:00',
                'end_time' => '14:30:00',
            ]],
        ]],
    ]),
    'SCHEDULE_SEGMENT_BOUNDARY_MISMATCH',
    'day boundary cannot contradict its segment boundary'
);

$dayPayload = [
    'timezone' => 'Africa/Cairo',
    'days' => [[
        'weekday' => 1,
        'is_working_day' => true,
        'start_time' => '07:30:00',
        'end_time' => '14:30:00',
        'required_minutes' => 420,
    ]],
];
$candidate = static function (
    int $policyId,
    int $versionId,
    string $scopeType,
    int $scopeId,
    int $priority,
    string $validFrom,
    array $schedule = []
) use ($dayPayload): array {
    return [
        'policy_id' => $policyId,
        'policy_code' => 'P' . $policyId,
        'policy_name' => 'Policy ' . $policyId,
        'version_id' => $versionId,
        'version_no' => 1,
        'state' => 'published',
        'valid_from' => $validFrom,
        'valid_to' => null,
        'scope_id_record' => $versionId,
        'scope_type' => $scopeType,
        'scope_id' => $scopeId,
        'scope_priority' => $priority,
        'scope_valid_from' => $validFrom,
        'scope_valid_to' => null,
        'schedule' => $schedule + $dayPayload,
    ];
};

$assignment = ['assignment_id' => 99, 'org_unit_id' => 20, 'job_title_id' => 30, 'group_ids' => [40, 41], 'employment_status' => 'active'];
$query = new EffectiveScheduleQueryService();
$resolved = $query->resolveFromCandidates(
    10,
    $winterMonday,
    $assignment,
    [
        $candidate(1, 101, 'global', 0, 0, '2026-01-01 00:00:00'),
        $candidate(2, 102, 'job_title', 30, 0, '2026-01-01 00:00:00'),
        $candidate(3, 103, 'org_unit', 20, 0, '2026-01-01 00:00:00'),
        $candidate(4, 104, 'group', 40, 10, '2026-01-01 00:00:00'),
        $candidate(5, 105, 'staff', 10, 0, '2026-01-01 00:00:00'),
    ]
);
$assert($resolved['status'] === 'working', 'effective resolver returns working status');
$assert($resolved['selected']['version_id'] === 105, 'staff scope outranks group, org, title, and global');
$assert($resolved['explanation']['scope_type'] === 'staff', 'effective resolver explains selected scope');

$holiday = $query->resolveFromCandidates(
    10,
    $winterMonday,
    $assignment,
    [$candidate(5, 105, 'staff', 10, 0, '2026-01-01 00:00:00')],
    [[
        'id' => 77,
        'calendar_date' => '2026-01-05',
        'scope_type' => 'global',
        'scope_id' => 0,
        'priority' => 0,
        'exception_type' => 'holiday',
        'reason' => 'Official holiday',
        'status' => 'active',
    ]]
);
$assert($holiday['status'] === 'non_working', 'calendar holiday overrides a normal working day');
$assert($holiday['calendar_exception']['id'] === 77, 'calendar exception source is returned');

$partialDay = $query->resolveFromCandidates(
    10,
    $winterMonday,
    $assignment,
    [$candidate(5, 105, 'staff', 10, 0, '2026-01-01 00:00:00')],
    [
        [
            'id' => 78,
            'calendar_date' => '2026-01-05',
            'scope_type' => 'global',
            'scope_id' => 0,
            'priority' => 0,
            'exception_type' => 'holiday',
            'reason' => 'Global holiday',
            'status' => 'active',
        ],
        [
            'id' => 79,
            'calendar_date' => '2026-01-05',
            'scope_type' => 'staff',
            'scope_id' => 10,
            'priority' => 0,
            'exception_type' => 'partial_day',
            'override_json' => [
                'is_working_day' => true,
                'start_time' => '10:00:00',
                'end_time' => '12:00:00',
                'required_minutes' => 120,
                'segments' => [[
                    'sequence_no' => 1,
                    'segment_type' => 'work',
                    'start_time' => '10:00:00',
                    'end_time' => '12:00:00',
                ]],
            ],
            'reason' => 'Staff partial day',
            'status' => 'active',
        ],
    ]
);
$assert($partialDay['status'] === 'working', 'staff partial day outranks a global holiday');
$assert($partialDay['calendar_exception']['id'] === 79, 'calendar precedence is staff over global');
$assert(
    $partialDay['selected']['schedule']->workWindow($winterMonday)['start']->format('H:i') === '10:00',
    'partial-day override replaces the effective day window'
);

$makeupBase = $candidate(6, 106, 'global', 0, 0, '2026-01-01 00:00:00', [
    'timezone' => 'Africa/Cairo',
    'days' => [['weekday' => 1, 'is_working_day' => false]],
]);
$makeup = $query->resolveFromCandidates(
    10,
    $winterMonday,
    $assignment,
    [$makeupBase],
    [[
        'id' => 80,
        'calendar_date' => '2026-01-05',
        'scope_type' => 'global',
        'scope_id' => 0,
        'priority' => 0,
        'exception_type' => 'makeup_day',
        'override_json' => [
            'is_working_day' => true,
            'start_time' => '08:00:00',
            'end_time' => '12:00:00',
            'required_minutes' => 240,
        ],
        'reason' => 'Makeup Monday',
        'status' => 'active',
    ]]
);
$assert($makeup['status'] === 'working', 'makeup-day override turns a non-working weekday into work');

$calendarTie = $query->resolveFromCandidates(
    10,
    $winterMonday,
    $assignment,
    [$candidate(5, 105, 'staff', 10, 0, '2026-01-01 00:00:00')],
    [
        ['id' => 81, 'calendar_date' => '2026-01-05', 'scope_type' => 'group', 'scope_id' => 40, 'priority' => 5, 'exception_type' => 'holiday', 'reason' => 'A', 'status' => 'active', 'created_at' => '2026-01-01 00:00:00'],
        ['id' => 82, 'calendar_date' => '2026-01-05', 'scope_type' => 'group', 'scope_id' => 41, 'priority' => 5, 'exception_type' => 'closure', 'reason' => 'B', 'status' => 'active', 'created_at' => '2026-01-01 00:00:00'],
    ]
);
$assert($calendarTie['status'] === 'unresolved', 'equal calendar rank fails closed');
$assert($calendarTie['reason_code'] === 'CALENDAR_EXCEPTION_CONFLICT', 'calendar tie has a domain reason');
$assert($calendarTie['conflicts'] === [81, 82], 'calendar tie reports stable exception ids');

$supersededCalendar = $query->resolveFromCandidates(
    10,
    $winterMonday,
    $assignment,
    [$candidate(5, 105, 'staff', 10, 0, '2026-01-01 00:00:00')],
    [
        ['id' => 83, 'calendar_date' => '2026-01-05', 'scope_type' => 'global', 'scope_id' => 0, 'priority' => 0, 'exception_type' => 'holiday', 'reason' => 'Old', 'status' => 'active', 'created_at' => '2025-12-01 00:00:00'],
        ['id' => 84, 'supersedes_id' => 83, 'calendar_date' => '2026-01-05', 'scope_type' => 'global', 'scope_id' => 0, 'priority' => 0, 'exception_type' => 'override', 'override_json' => ['is_working_day' => true, 'start_time' => '09:00:00', 'end_time' => '13:00:00', 'required_minutes' => 240], 'reason' => 'New', 'status' => 'active', 'created_at' => '2025-12-02 00:00:00'],
    ]
);
$assert($supersededCalendar['calendar_exception']['id'] === 84, 'active superseding exception hides only its predecessor');
$assert($supersededCalendar['status'] === 'working', 'superseding override is applied without a permanent tie');

$tie = $query->resolveFromCandidates(
    10,
    $winterMonday,
    $assignment,
    [
        $candidate(7, 107, 'group', 40, 50, '2026-01-01 00:00:00'),
        $candidate(8, 108, 'group', 41, 50, '2026-01-01 00:00:00'),
    ]
);
$assert($tie['status'] === 'unresolved', 'equal-precedence policy tie fails closed');
$assert($tie['conflicts'] === [107, 108], 'policy tie reports stable conflicting version ids');

$invalidHigherPolicy = $candidate(10, 110, 'staff', 10, 0, '2026-01-01 00:00:00', [
    'timezone' => 'Africa/Cairo',
    'days' => [[
        'weekday' => 1,
        'is_working_day' => true,
        'start_time' => '14:00:00',
        'end_time' => '08:00:00',
        'end_day_offset' => 0,
    ]],
]);
$invalidSchedule = $query->resolveFromCandidates(
    10,
    $winterMonday,
    $assignment,
    [
        $candidate(1, 101, 'global', 0, 0, '2026-01-01 00:00:00'),
        $invalidHigherPolicy,
    ]
);
$assert($invalidSchedule['status'] === 'unresolved', 'invalid higher schedule fails closed instead of falling back');
$assert($invalidSchedule['reason_code'] === 'SCHEDULE_PAYLOAD_INVALID', 'invalid schedule has a stable domain reason');
$assert($invalidSchedule['conflicts'] === [110], 'invalid schedule reports its version id');

$invalidDateCandidate = $candidate(11, 111, 'staff', 10, 0, 'not-a-date');
$invalidDate = $query->resolveFromCandidates(10, $winterMonday, $assignment, [$invalidDateCandidate]);
$assert($invalidDate['reason_code'] === 'SCHEDULE_PAYLOAD_INVALID', 'invalid effective date returns unresolved instead of a raw exception');

$impact = $query->resolveFromCandidates(
    10,
    $winterMonday,
    $assignment,
    [$candidate(1, 101, 'global', 0, 0, '2026-01-01 00:00:00')],
    [],
    [],
    $candidate(9, 109, 'staff', 10, 0, '2026-01-01 00:00:00')
);
$assert($impact['current']['version_id'] === 101, 'impact DTO keeps current selected schedule');
$assert($impact['proposed']['version_id'] === 109, 'impact DTO resolves the proposed draft independently');
$assert($impact['selected']['version_id'] === 109 && $impact['changed'] === true, 'impact DTO identifies changed effective policy');

$conflictedImpact = $query->resolveFromCandidates(
    10,
    $winterMonday,
    $assignment,
    [
        $candidate(7, 107, 'group', 40, 50, '2026-01-01 00:00:00'),
        $candidate(8, 108, 'group', 41, 50, '2026-01-01 00:00:00'),
    ],
    [],
    [],
    $candidate(9, 109, 'staff', 10, 0, '2026-01-01 00:00:00')
);
$assert($conflictedImpact['reason_code'] === 'SCHEDULE_CONFLICT', 'proposed higher policy does not hide a current conflict');
$assert($conflictedImpact['conflicts'] === [107, 108], 'impact preserves current conflict evidence');

$invalidChange = $query->resolveFromCandidates(
    10,
    $winterMonday,
    $assignment,
    [$candidate(1, 101, 'global', 0, 0, '2026-01-01 00:00:00')],
    [],
    [[
        'id' => 901,
        'status' => 'approved',
        'change_type' => 'temporary_shift',
        'approved_schedule_snapshot' => ['schedule' => ['timezone' => 'Africa/Cairo', 'days' => [['weekday' => 1, 'is_working_day' => true, 'start_time' => 'bad', 'end_time' => '12:00:00']]]],
    ]]
);
$assert($invalidChange['reason_code'] === 'SCHEDULE_CHANGE_PAYLOAD_INVALID', 'invalid approved change snapshot fails closed');
$assert($invalidChange['conflicts'] === [901], 'invalid change reports its request id');

if ($failures > 0) {
    fwrite(STDERR, "{$failures} staff-HR schedule resolution failure(s).\n");
    exit(1);
}

echo "Staff-HR schedule domain and resolution tests passed.\n";
