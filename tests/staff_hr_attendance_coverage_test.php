<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/vendor/autoload.php';

use EduCore\Modules\Attendance\Domain\Calculation\AttendanceDayCalculator;
use EduCore\Modules\Attendance\Domain\Calculation\PunchWindowMatcher;
use EduCore\Modules\Attendance\Domain\Schedule\WorkSchedule;

date_default_timezone_set('Africa/Cairo');

$failures = 0;
$assert = static function (bool $condition, string $message) use (&$failures): void {
    if (!$condition) {
        fwrite(STDERR, "FAIL: {$message}\n");
        ++$failures;
    }
};
$zone = new DateTimeZone('Africa/Cairo');
$at = static fn (string $value): DateTimeImmutable => new DateTimeImmutable($value, $zone);
$event = static function (int $id, string $at, string $type): array {
    return [
        'id' => $id,
        'event_at_local' => $at,
        'event_type' => $type,
        'link_status' => 'matched',
        'review_status' => 'not_required',
        'entry_method_type' => 'biometric',
    ];
};
$coverage = static function (
    int $id,
    string $behavior,
    string $from,
    string $to,
    string $sourceType = 'permission'
) use ($at): array {
    return [
        'source_type' => $sourceType,
        'source_id' => $id,
        'coverage_behavior' => $behavior,
        'from_at' => $at($from),
        'to_at' => $at($to),
        'source_version_id' => 9,
    ];
};
$reasonCodes = static fn (array $result): array => array_values(array_unique(array_map(
    static fn (array $line): string => (string) ($line['reason_code'] ?? ''),
    (array) ($result['reason_lines'] ?? [])
)));
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
$monday = $at('2026-01-05 00:00:00');
$calculator = new AttendanceDayCalculator(new PunchWindowMatcher());

// 07:30 schedule + approved late-arrival until 09:30 + 09:00 punch: no violation.
$lateCovered = $calculator->calculate(701, $monday, $schedule, [
    $event(1, '2026-01-05 09:00:00', 'in'),
    $event(2, '2026-01-05 14:30:00', 'out'),
], [
    $coverage(11, 'late_arrival', '2026-01-05 07:30:00', '2026-01-05 09:30:00'),
]);
$assert(($lateCovered['status'] ?? null) === 'present', 'approved late coverage makes a paired 09:00 arrival present');
$assert(($lateCovered['raw_late_minutes'] ?? null) === 90, 'late coverage preserves raw 90-minute violation evidence');
$assert(($lateCovered['covered_late_minutes'] ?? null) === 90, 'late coverage covers only the matching 90 minutes');
$assert(($lateCovered['late_minutes'] ?? null) === 0 && ($lateCovered['missing_minutes'] ?? null) === 0, 'covered late minutes leave no late or missing minutes');
$assert(in_array('APPROVED_LATE_ARRIVAL_COVERAGE', $reasonCodes($lateCovered), true), 'late coverage has an explainable source line');

$latePartiallyCovered = $calculator->calculate(701, $monday, $schedule, [
    $event(3, '2026-01-05 09:00:00', 'in'),
    $event(4, '2026-01-05 14:30:00', 'out'),
], [
    $coverage(12, 'late_arrival', '2026-01-05 07:30:00', '2026-01-05 08:30:00'),
]);
$assert(($latePartiallyCovered['late_minutes'] ?? null) === 30, 'late arrival after the approved window keeps only uncovered minutes late');
$assert(($latePartiallyCovered['missing_minutes'] ?? null) === 30, 'only uncovered late minutes remain missing');

// 14:30 schedule + approved early leave from 12:00 + 12:00 punch: no violation.
$earlyCovered = $calculator->calculate(701, $monday, $schedule, [
    $event(5, '2026-01-05 07:30:00', 'in'),
    $event(6, '2026-01-05 12:00:00', 'out'),
], [
    $coverage(13, 'early_leave', '2026-01-05 12:00:00', '2026-01-05 14:30:00'),
]);
$assert(($earlyCovered['status'] ?? null) === 'present', 'approved early coverage makes a paired 12:00 departure present');
$assert(($earlyCovered['covered_early_minutes'] ?? null) === 150, 'early-leave coverage covers 150 minutes');
$assert(($earlyCovered['early_leave_minutes'] ?? null) === 0 && ($earlyCovered['missing_minutes'] ?? null) === 0, 'covered early departure leaves no early or missing minutes');

// A mission fills only the unworked middle interval; it does not duplicate worked time.
$missionCovered = $calculator->calculate(701, $monday, $schedule, [
    $event(7, '2026-01-05 07:30:00', 'in'),
    $event(8, '2026-01-05 09:00:00', 'out'),
    $event(9, '2026-01-05 11:00:00', 'in'),
    $event(10, '2026-01-05 14:30:00', 'out'),
], [
    $coverage(14, 'mission', '2026-01-05 09:00:00', '2026-01-05 11:00:00', 'mission'),
]);
$assert(($missionCovered['status'] ?? null) === 'present', 'approved mission can cover an otherwise unmatched middle work interval');
$assert(($missionCovered['worked_minutes'] ?? null) === 300, 'mission never inflates actual worked minutes');
$assert(($missionCovered['mission_minutes'] ?? null) === 120, 'mission records only its uncovered scheduled minutes');
$assert(($missionCovered['missing_minutes'] ?? null) === 0, 'mission coverage removes only the matching missing interval');

// Overlapping permissions and mission are unioned, never counted as repeated grace.
$overlap = $calculator->calculate(701, $monday, $schedule, [
    $event(11, '2026-01-05 09:00:00', 'in'),
    $event(12, '2026-01-05 12:00:00', 'out'),
], [
    $coverage(15, 'late_arrival', '2026-01-05 07:30:00', '2026-01-05 09:30:00'),
    $coverage(16, 'mission', '2026-01-05 08:30:00', '2026-01-05 13:00:00', 'mission'),
    $coverage(17, 'early_leave', '2026-01-05 12:00:00', '2026-01-05 14:30:00'),
]);
$segment = (array) (($overlap['attendance_segments'] ?? [])[0] ?? []);
$assert(($overlap['status'] ?? null) === 'present', 'unioned approved intervals cover a paired partial day');
$assert(($overlap['late_minutes'] ?? null) === 0 && ($overlap['early_leave_minutes'] ?? null) === 0, 'overlap does not leave a duplicated late or early violation');
$assert(($overlap['missing_minutes'] ?? null) === 0, 'overlap union covers the schedule only once');
$assert(
    (int) ($segment['worked_minutes'] ?? 0) + (int) ($segment['covered_minutes'] ?? 0) <= (int) ($segment['scheduled_minutes'] ?? 0),
    'worked and covered time never exceed the scheduled segment under overlap'
);

// A permission may reduce violation minutes but can never invent an entry/exit pair.
$noShow = $calculator->calculate(701, $monday, $schedule, [], [
    $coverage(18, 'late_arrival', '2026-01-05 07:30:00', '2026-01-05 09:30:00'),
]);
$assert(($noShow['status'] ?? null) === 'absent', 'approved coverage without punches remains absent');
$assert(($noShow['worked_minutes'] ?? null) === 0, 'coverage without punches never creates worked minutes');
$assert(($noShow['missing_minutes'] ?? null) === 300, 'only the authorized no-show interval is covered');
$assert(in_array('COVERAGE_DOES_NOT_ESTABLISH_ATTENDANCE', $reasonCodes($noShow), true), 'no-show protection is explainable');

if ($failures > 0) {
    fwrite(STDERR, "{$failures} attendance coverage failure(s).\n");
    exit(1);
}

echo "Staff-HR attendance coverage tests passed.\n";
