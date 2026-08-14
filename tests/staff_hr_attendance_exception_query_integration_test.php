<?php

declare(strict_types=1);

/**
 * Guarded MariaDB proof for the read-only attendance exception projection.
 * It creates and removes only an explicitly named *_test database.
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

$options = getopt('', ['database:']);
$databaseName = trim((string) ($options['database'] ?? getenv('EDUCORE_TEST_DB_NAME') ?: ''));
$marker = trim((string) (getenv('STAFF_HR_TEST_MARKER') ?: ''));
if ($marker !== 'integrated-staff-hr') {
    fwrite(STDERR, "FAIL: set STAFF_HR_TEST_MARKER=integrated-staff-hr explicitly.\n");
    exit(2);
}
if ($databaseName === ''
    || preg_match('/^[A-Za-z0-9_]+_test$/', $databaseName) !== 1
    || strtolower($databaseName) === 'educore') {
    fwrite(STDERR, "FAIL: EDUCORE_TEST_DB_NAME/--database must identify a new isolated *_test database.\n");
    exit(2);
}

putenv('APP_ENV=test');
putenv('DB_NAME=' . $databaseName);
putenv('EDUCORE_TEST_DB_NAME=' . $databaseName);
$_ENV['APP_ENV'] = 'test';
$_ENV['DB_NAME'] = $databaseName;
$_ENV['STAFF_HR_TEST_MARKER'] = $marker;
$_ENV['EDUCORE_TEST_DB_NAME'] = $databaseName;
$_SERVER['APP_ENV'] = 'test';
$_SERVER['DB_NAME'] = $databaseName;
$_SERVER['STAFF_HR_TEST_MARKER'] = $marker;
$_SERVER['EDUCORE_TEST_DB_NAME'] = $databaseName;

require_once __DIR__ . '/bootstrap_staff_hr.php';
require_once dirname(__DIR__) . '/config/database.php';
require_once dirname(__DIR__) . '/src/Modules/Attendance/bootstrap.php';

use EduCore\Modules\Attendance\Application\AttendanceExceptionQueryService;
use EduCore\Modules\Attendance\Infrastructure\PdoAttendanceExceptionQuery;

$failures = 0;
$assert = static function (bool $condition, string $message) use (&$failures): void {
    if (!$condition) {
        fwrite(STDERR, "FAIL: {$message}\n");
        ++$failures;
    }
};
$quoteIdentifier = static function (string $identifier): string {
    if (preg_match('/^[A-Za-z0-9_]+$/', $identifier) !== 1) {
        throw new InvalidArgumentException('Unsafe database identifier.');
    }

    return '`' . $identifier . '`';
};

$admin = null;
$db = null;
$databaseCreated = false;
$databaseDropped = false;

try {
    $admin = new PDO(
        'mysql:host=' . DB_HOST . ';charset=utf8mb4',
        DB_USERNAME,
        DB_PASSWORD,
        [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
    );
    $exists = $admin->prepare('SELECT COUNT(*) FROM information_schema.SCHEMATA WHERE SCHEMA_NAME = ?');
    $exists->execute([$databaseName]);
    if ((int) $exists->fetchColumn() !== 0) {
        fwrite(STDERR, "FAIL: {$databaseName} already exists; supply a new dedicated *_test database name.\n");
        exit(2);
    }
    $admin->exec(
        'CREATE DATABASE ' . $quoteIdentifier($databaseName)
        . ' CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci'
    );
    $databaseCreated = true;
    $db = staffHrTestDatabase();
    $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $migration = require dirname(__DIR__) . '/database/migrations/20260730_staff_hr_attendance_engine.php';
    $migration($db);

    $db->exec(
        "INSERT INTO staff_attendance_entry_methods
            (code, name, method_type, requires_review, allowed_scope, created_by)
         VALUES ('EXCEPTION_TEST_ACCESS', 'Exception test access', 'access_log', 0, 'hr', 9000)"
    );
    $methodId = (int) $db->lastInsertId();
    $event = $db->prepare(
        "INSERT INTO staff_biometric_events
            (entry_method_id, device_id, idempotency_key, device_event_at, received_at,
             device_timezone, normalized_event_at_utc, event_at_local, clock_offset_seconds,
             clock_status, event_type, raw_hash, raw_payload_ref, link_status, review_status)
         VALUES (?, 1, ?, ?, ?, 'Africa/Cairo', ?, ?, 0, 'trusted', 'in', ?, ?, 'unmatched', 'not_required')"
    );
    $event->execute([
        $methodId,
        'exception-query-unmatched-event',
        '2026-08-02 07:31:00.000000',
        '2026-08-02 07:32:00.000000',
        '2026-08-02 05:31:00.000000',
        '2026-08-02 07:31:00.000000',
        str_repeat('1', 64),
        'private/raw/do-not-project.json',
    ]);

    $run = $db->prepare(
        "INSERT INTO staff_attendance_runs
            (engine_version, mode, range_from, range_to, cutoff_at, initiated_by, status,
             source_fingerprint, idempotency_key, started_at, finished_at)
         VALUES ('exception-query-test', 'shadow', '2026-08-01', '2026-08-03',
                 '2026-08-03 23:59:59.000000', 9000, 'completed', ?, ?,
                 '2026-08-03 23:59:58.000000', '2026-08-03 23:59:59.000000')"
    );
    $run->execute([str_repeat('2', 64), 'exception-query-run']);
    $runId = (int) $db->lastInsertId();
    $day = $db->prepare(
        "INSERT INTO staff_attendance_day_versions
            (staff_user_id, work_date, version_no, run_id, required_minutes, worked_minutes,
             status, calculation_mode, engine_version, source_fingerprint, is_official, calculated_at)
         VALUES (44, '2026-08-02', 1, ?, 0, 0, 'exception', 'shadow',
                 'exception-query-test', ?, 0, '2026-08-03 23:59:59.000000')"
    );
    $day->execute([$runId, str_repeat('3', 64)]);
    $dayId = (int) $db->lastInsertId();
    $reason = $db->prepare(
        "INSERT INTO staff_attendance_reason_lines
            (day_version_id, line_no, reason_code, minutes, source_type, source_id, explanation)
         VALUES (?, 1, 'LEGACY_RECORD_AMBIGUOUS', 0, 'legacy_staff_attendance', 77,
                 'Legacy duplicate rows were detected for this test day.')"
    );
    $reason->execute([$dayId]);

    $service = new AttendanceExceptionQueryService(new PdoAttendanceExceptionQuery($db));
    $review = $service->review([
        'date_from' => '2026-08-01',
        'date_to' => '2026-08-03',
        'category' => 'all',
    ]);
    $assert(($review['summary']['raw_events'] ?? null) === 1, 'MariaDB projection counts unmatched raw events');
    $assert(($review['summary']['unresolved_days'] ?? null) === 1, 'MariaDB projection counts exception day versions');
    $assert(($review['summary']['comparison_differences'] ?? null) === 1, 'MariaDB projection counts classified legacy differences');
    $assert(count($review['items'] ?? []) === 3, 'MariaDB projection returns all three review categories');

    $projection = json_encode($review['items'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    $assert(!str_contains((string) $projection, 'do-not-project.json'), 'MariaDB projection never emits raw payload references');
    $assert(!str_contains((string) $projection, 'private/raw'), 'MariaDB projection keeps private evidence locations outside the read model');
    $comparison = array_values(array_filter(
        $review['items'] ?? [],
        static fn (array $item): bool => ($item['category'] ?? null) === 'comparison'
    ));
    $assert(($comparison[0]['issue_label'] ?? null) === 'توجد سجلات حضور سابقة مكررة', 'legacy ambiguity remains an explicit review item');

    $staffReview = $service->review([
        'date_from' => '2026-08-01',
        'date_to' => '2026-08-03',
        'staff_user_id' => '44',
        'category' => 'all',
    ]);
    $assert(($staffReview['summary']['raw_events'] ?? null) === 0, 'staff filter excludes unmatched evidence that has no staff assignment');
    $assert(count($staffReview['items'] ?? []) === 2, 'staff filter retains the matching day and comparison evidence');
} catch (Throwable $exception) {
    fwrite(STDERR, 'FAIL: unexpected integration exception: ' . $exception->getMessage() . "\n");
    ++$failures;
} finally {
    if ($databaseCreated && $admin instanceof PDO) {
        try {
            $admin->exec('DROP DATABASE ' . $quoteIdentifier($databaseName));
            $databaseDropped = true;
        } catch (Throwable $cleanupException) {
            fwrite(STDERR, 'FAIL: unable to remove isolated test database: ' . $cleanupException->getMessage() . "\n");
            ++$failures;
        }
    }
}

if ($databaseCreated) {
    $assert($databaseDropped, 'isolated database is removed after the integration proof');
}

if ($failures > 0) {
    fwrite(STDERR, "{$failures} attendance exception query integration failure(s).\n");
    exit(1);
}

echo "Attendance exception query integration tests passed.\n";
