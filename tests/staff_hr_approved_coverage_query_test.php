<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/vendor/autoload.php';

use EduCore\Modules\Attendance\Infrastructure\PdoApprovedCoverageQuery;
use EduCore\Modules\Staff\Infrastructure\PdoStaffApprovedCoverageReadRepository;

$failures = 0;
$assert = static function (bool $condition, string $message) use (&$failures): void {
    if (!$condition) {
        fwrite(STDERR, "FAIL: {$message}\n");
        ++$failures;
    }
};
$pdo = new PDO('sqlite::memory:');
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$pdo->exec('CREATE TABLE staff_permission_types (id INTEGER PRIMARY KEY, coverage_behavior TEXT NOT NULL)');
$pdo->exec('CREATE TABLE staff_permission_requests (
    id INTEGER PRIMARY KEY, staff_user_id INTEGER NOT NULL, permission_type_id INTEGER NOT NULL,
    from_at TEXT NOT NULL, to_at TEXT NOT NULL, timezone TEXT NOT NULL,
    policy_version_id INTEGER NULL, status TEXT NOT NULL
)');
$pdo->exec('CREATE TABLE staff_leaves (
    id INTEGER PRIMARY KEY, user_id INTEGER NOT NULL, start_date TEXT NOT NULL,
    end_date TEXT NOT NULL, status TEXT NOT NULL
)');
$pdo->exec('CREATE TABLE staff_leave_requests (
    id INTEGER PRIMARY KEY, staff_user_id INTEGER NOT NULL, parent_request_id INTEGER NULL,
    request_kind TEXT NOT NULL, from_at TEXT NOT NULL, to_at TEXT NOT NULL,
    timezone TEXT NOT NULL, policy_version_id INTEGER NULL, status TEXT NOT NULL
)');
$pdo->exec("INSERT INTO staff_permission_types (id, coverage_behavior) VALUES (1, 'late_arrival'), (2, 'mission'), (3, 'early_leave')");
$pdo->exec("INSERT INTO staff_permission_requests (id, staff_user_id, permission_type_id, from_at, to_at, timezone, policy_version_id, status) VALUES
    (11, 701, 1, '2026-01-05 07:30:00.000000', '2026-01-05 09:30:00.000000', 'Africa/Cairo', 91, 'approved'),
    (12, 701, 2, '2026-01-05 10:00:00.000000', '2026-01-05 11:00:00.000000', 'Africa/Cairo', 92, 'approved'),
    (13, 701, 3, '2026-01-05 12:00:00.000000', '2026-01-05 14:30:00.000000', 'Africa/Cairo', 93, 'rejected'),
    (14, 702, 1, '2026-01-05 07:30:00.000000', '2026-01-05 09:30:00.000000', 'Africa/Cairo', 94, 'approved')");
$pdo->exec("INSERT INTO staff_leaves (id, user_id, start_date, end_date, status) VALUES
    (21, 701, '2026-01-05', '2026-01-05', 'approved'),
    (22, 701, '2026-01-06', '2026-01-06', 'approved'),
    (23, 701, '2026-01-05', '2026-01-05', 'pending')");
$pdo->exec("INSERT INTO staff_leave_requests (id, staff_user_id, parent_request_id, request_kind, from_at, to_at, timezone, policy_version_id, status) VALUES
    (30, 701, NULL, 'leave', '2026-01-05 07:30:00.000000', '2026-01-05 14:30:00.000000', 'Africa/Cairo', 101, 'approved'),
    (31, 701, 30, 'early_return', '2026-01-05 12:00:00.000000', '2026-01-05 14:30:00.000000', 'Africa/Cairo', 101, 'approved'),
    (32, 701, NULL, 'leave', '2026-01-05 07:30:00.000000', '2026-01-05 14:30:00.000000', 'Africa/Cairo', 102, 'pending')");

$zone = new DateTimeZone('Africa/Cairo');
$query = new PdoApprovedCoverageQuery(new PdoStaffApprovedCoverageReadRepository($pdo));
$coverage = $query->forStaffWindow(
    701,
    new DateTimeImmutable('2026-01-05 07:30:00', $zone),
    new DateTimeImmutable('2026-01-05 14:30:00', $zone)
);
$bySource = [];
foreach ($coverage as $item) {
    $bySource[(string) ($item['source_type'] ?? '') . ':' . (int) ($item['source_id'] ?? 0)] = $item;
}

$assert(count($coverage) === 4, 'only overlapping approved permission, immutable leave, mission, and legacy leave coverage is projected');
$assert(($bySource['permission:11']['coverage_behavior'] ?? null) === 'late_arrival', 'approved permission retains only minimal coverage evidence');
$assert(($bySource['mission:12']['coverage_behavior'] ?? null) === 'mission', 'mission permission is exposed as mission coverage');
$assert(($bySource['leave:21']['coverage_behavior'] ?? null) === 'leave', 'approved legacy leave is projected as full-day leave coverage');
$assert(($bySource['leave:21']['to_at'] ?? null) instanceof DateTimeImmutable && $bySource['leave:21']['to_at']->format('Y-m-d H:i:s') === '2026-01-06 00:00:00', 'legacy leave end date is inclusive and converted to a half-open interval');
$assert(($bySource['leave:30']['from_at'] ?? null) instanceof DateTimeImmutable && $bySource['leave:30']['from_at']->format('H:i:s') === '07:30:00', 'approved immutable leave is projected through the Staff-owned coverage contract');
$assert(($bySource['leave:30']['to_at'] ?? null) instanceof DateTimeImmutable && $bySource['leave:30']['to_at']->format('H:i:s') === '12:00:00', 'approved early return subtracts only its final successor interval from immutable leave coverage');
$assert(!array_key_exists('reason', $bySource['permission:11'] ?? []) && !array_key_exists('policy_snapshot', $bySource['permission:11'] ?? []), 'coverage projection redacts request details and frozen snapshots');

if ($failures > 0) {
    fwrite(STDERR, "{$failures} approved coverage query failure(s).\n");
    exit(1);
}

echo "Staff-HR approved coverage query tests passed.\n";
