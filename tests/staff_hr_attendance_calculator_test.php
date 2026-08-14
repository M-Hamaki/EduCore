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
$format = static function (mixed $value): ?string {
    return $value instanceof DateTimeImmutable ? $value->format('Y-m-d H:i:s') : null;
};
$reasonCodes = static function (array $result): array {
    return array_values(array_unique(array_map(
        static fn (array $line): string => (string) ($line['reason_code'] ?? ''),
        (array) ($result['reason_lines'] ?? [])
    )));
};
$event = static function (int $id, string $at, string $type, array $extra = []): array {
    return array_merge([
        'id' => $id,
        'event_at_local' => $at,
        'event_type' => $type,
        'link_status' => 'matched',
        'review_status' => 'not_required',
        'entry_method_type' => 'biometric',
    ], $extra);
};
$singleSchedule = static function (
    string $start = '07:30',
    string $end = '14:30',
    int $endOffset = 0,
    int $requiredMinutes = 420
): WorkSchedule {
    return WorkSchedule::fromArray([
        'timezone' => 'Africa/Cairo',
        'days' => [[
            'weekday' => 1,
            'is_working_day' => true,
            'start_time' => $start,
            'end_time' => $end,
            'end_day_offset' => $endOffset,
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

$matcher = new PunchWindowMatcher();
$calculator = new AttendanceDayCalculator($matcher);
$monday = new DateTimeImmutable('2026-01-05 00:00:00', new DateTimeZone('Africa/Cairo'));

$normal = $calculator->calculate(501, $monday, $singleSchedule(), [
    $event(1, '2026-01-05 07:25:00', 'in'),
    $event(2, '2026-01-05 14:30:00', 'out'),
]);
$assert(($normal['status'] ?? null) === 'present', 'normal paired punches produce a present day');
$assert(($normal['worked_minutes'] ?? null) === 420, 'normal paired punches count only the required work window');
$assert(($normal['late_minutes'] ?? null) === 0 && ($normal['early_leave_minutes'] ?? null) === 0, 'normal paired punches have no late or early violation');
$assert(($normal['missing_minutes'] ?? null) === 0, 'normal paired punches have no missing minutes');
$assert($format($normal['first_in'] ?? null) === '2026-01-05 07:25:00', 'first matched punch is retained as the first-in evidence');
$assert($format($normal['last_out'] ?? null) === '2026-01-05 14:30:00', 'last matched punch is retained as the last-out evidence');

$late = $calculator->calculate(501, $monday, $singleSchedule(), [
    $event(3, '2026-01-05 09:00:00', 'in'),
    $event(4, '2026-01-05 14:30:00', 'out'),
]);
$assert(($late['status'] ?? null) === 'partial', 'a late but paired arrival remains a partial attendance result');
$assert(($late['late_minutes'] ?? null) === 90, 'late arrival counts the uncovered minutes after the schedule start');
$assert(($late['worked_minutes'] ?? null) === 330 && ($late['missing_minutes'] ?? null) === 90, 'late arrival keeps worked and missing minutes distinct');
$assert(in_array('LATE_ARRIVAL', $reasonCodes($late), true), 'late arrival has an explainable reason line');

$early = $calculator->calculate(501, $monday, $singleSchedule(), [
    $event(16, '2026-01-05 07:30:00', 'in'),
    $event(17, '2026-01-05 12:00:00', 'out'),
]);
$assert(($early['status'] ?? null) === 'partial', 'an early but paired departure remains a partial attendance result');
$assert(($early['early_leave_minutes'] ?? null) === 150, 'early departure counts the uncovered minutes before the expected end');
$assert(in_array('EARLY_LEAVE', $reasonCodes($early), true), 'early departure has an explainable reason line');

$missingPunch = $calculator->calculate(501, $monday, $singleSchedule(), [
    $event(5, '2026-01-05 07:25:00', 'in'),
]);
$assert(($missingPunch['status'] ?? null) === 'incomplete', 'one punch never establishes a completed attendance interval');
$assert(($missingPunch['worked_minutes'] ?? null) === 0 && ($missingPunch['missing_minutes'] ?? null) === 420, 'one punch does not create inferred worked minutes');
$assert(in_array('MISSING_EXIT_PUNCH', $reasonCodes($missingPunch), true), 'missing exit punch is explicit in the reason lines');

$overnightSchedule = $singleSchedule('20:00', '04:00', 1, 480);
$overnight = $calculator->calculate(501, $monday, $overnightSchedule, [
    $event(6, '2026-01-05 19:55:00', 'in'),
    $event(7, '2026-01-06 04:03:00', 'out'),
]);
$assert(($overnight['status'] ?? null) === 'present', 'overnight punches remain one work-date attendance result');
$assert(($overnight['worked_minutes'] ?? null) === 480, 'overnight work is capped to the scheduled work interval');
$assert($format($overnight['expected_end'] ?? null) === '2026-01-06 04:00:00', 'overnight expected end remains on the following calendar date');

$splitSchedule = WorkSchedule::fromArray([
    'timezone' => 'Africa/Cairo',
    'days' => [[
        'weekday' => 1,
        'is_working_day' => true,
        'start_time' => '07:30',
        'end_time' => '15:30',
        'end_day_offset' => 0,
        'required_minutes' => 420,
        'late_grace_minutes' => 0,
        'early_grace_minutes' => 0,
        'entry_window_before_minutes' => 60,
        'entry_window_after_minutes' => 240,
        'exit_window_before_minutes' => 240,
        'exit_window_after_minutes' => 60,
        'segments' => [
            ['sequence_no' => 1, 'segment_type' => 'work', 'start_time' => '07:30', 'end_time' => '11:00', 'counts_required_minutes' => true],
            ['sequence_no' => 2, 'segment_type' => 'unpaid_break', 'start_time' => '11:00', 'end_time' => '12:00', 'counts_required_minutes' => false],
            ['sequence_no' => 3, 'segment_type' => 'work', 'start_time' => '12:00', 'end_time' => '15:30', 'counts_required_minutes' => true],
        ],
    ]],
]);
$split = $calculator->calculate(501, $monday, $splitSchedule, [
    $event(8, '2026-01-05 07:25:00', 'in', ['device_id' => 1]),
    $event(9, '2026-01-05 11:00:00', 'out', ['device_id' => 1]),
    $event(10, '2026-01-05 12:00:00', 'in', ['device_id' => 2]),
    $event(11, '2026-01-05 15:30:00', 'out', ['device_id' => 2]),
]);
$assert(($split['status'] ?? null) === 'present', 'split-shift punches from multiple devices resolve as one present day');
$assert(($split['worked_minutes'] ?? null) === 420, 'unpaid break time is never counted as worked time');
$assert(count((array) ($split['attendance_segments'] ?? [])) === 2, 'split shift produces one calculated segment per work interval');

$alternative = $calculator->calculate(501, $monday, $singleSchedule(), [
    $event(12, '2026-01-05 07:30:00', 'in', ['entry_method_type' => 'manual_verified', 'review_status' => 'approved']),
    $event(13, '2026-01-05 14:30:00', 'out', ['entry_method_type' => 'manual_verified', 'review_status' => 'approved']),
]);
$assert(($alternative['status'] ?? null) === 'present', 'approved alternative attendance entries can produce a calculated day');
$assert(in_array('ALTERNATIVE_ATTENDANCE', $reasonCodes($alternative), true), 'alternative attendance remains distinguishable from biometric evidence');

$unknown = $calculator->calculate(501, $monday, $singleSchedule(), [
    $event(14, '2026-01-05 07:30:00', 'unknown'),
    $event(15, '2026-01-05 14:30:00', 'unknown'),
]);
$assert(($unknown['status'] ?? null) === 'absent', 'unknown punches never become an inferred in/out pair');
$assert(in_array('UNUSABLE_PUNCH', $reasonCodes($unknown), true), 'unknown punches are surfaced as review evidence');

if ($failures > 0) {
    fwrite(STDERR, "{$failures} attendance calculator failure(s).\n");
    exit(1);
}

echo "Staff-HR attendance calculator tests passed.\n";
