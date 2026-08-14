<?php

declare(strict_types=1);

/**
 * Guarded MariaDB proof for non-official, redacted Attendance shadow runs.
 * The test creates and removes only an explicitly named *_test database.
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
require_once dirname(__DIR__) . '/src/Modules/Staff/bootstrap.php';
require_once dirname(__DIR__) . '/src/Modules/Attendance/bootstrap.php';

use EduCore\Modules\Attendance\Application\AttendanceShadowRunService;
use EduCore\Modules\Attendance\Contracts\EffectiveScheduleQuery;
use EduCore\Modules\Attendance\Domain\Calculation\AttendanceDayCalculator;
use EduCore\Modules\Attendance\Domain\Calculation\PunchWindowMatcher;
use EduCore\Modules\Attendance\Domain\Schedule\WorkSchedule;
use EduCore\Modules\Attendance\Infrastructure\PdoAttendanceShadowRunRepository;
use EduCore\Modules\Attendance\Infrastructure\PdoAttendanceTransactionManager;
use EduCore\Modules\Attendance\Infrastructure\PdoLegacyStaffAttendanceDayQuery;
use EduCore\Modules\Operations\Audit\AuditEventWriter;

final class AttendanceShadowIntegrationScheduleQuery implements EffectiveScheduleQuery
{
    public function __construct(private WorkSchedule $schedule)
    {
    }

    public function forStaffDate(int $staffId, \DateTimeImmutable $workDate): array
    {
        return [
            'status' => 'working',
            'reason_code' => 'EFFECTIVE_SCHEDULE_RESOLVED',
            'assignment' => ['assignment_id' => 711],
            'selected' => [
                'version_id' => 812,
                'schedule' => $this->schedule,
                'schedule_payload' => $this->schedule->toArray(),
            ],
            'calendar_exception' => null,
        ];
    }
}

final class AttendanceShadowIntegrationAudit implements AuditEventWriter
{
    public bool $failNext = false;

    /** @var list<array<string,mixed>> */
    public array $events = [];

    public function recordEvent(
        string $action,
        ?string $entityType,
        mixed $recordId,
        ?string $name,
        array $details = [],
        array $context = []
    ): void {
        if ($this->failNext) {
            $this->failNext = false;
            throw new RuntimeException('ATTENDANCE_SHADOW_AUDIT_FAILURE');
        }
        $this->events[] = compact('action', 'entityType', 'recordId', 'details', 'context');
    }
}

$failures = 0;
$assert = static function (bool $condition, string $message) use (&$failures): void {
    if (!$condition) {
        fwrite(STDERR, "FAIL: {$message}\n");
        ++$failures;
    }
};
$recordFailure = static function (string $message) use (&$failures): void {
    fwrite(STDERR, "FAIL: {$message}\n");
    ++$failures;
};
$expectException = static function (callable $operation, string $message) use ($assert): void {
    $thrown = false;
    try {
        $operation();
    } catch (Throwable) {
        $thrown = true;
    }
    $assert($thrown, $message);
};
$quoteIdentifier = static function (string $identifier): string {
    if (preg_match('/^[A-Za-z0-9_]+$/', $identifier) !== 1) {
        throw new InvalidArgumentException('Unsafe database identifier.');
    }
    return '`' . $identifier . '`';
};
$schedule = static function (): WorkSchedule {
    return WorkSchedule::fromArray([
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
    $migration($db);
    $db->exec(
        "CREATE TABLE staff_attendance (
            id INT NOT NULL AUTO_INCREMENT,
            user_id INT NOT NULL,
            attendance_date DATE NOT NULL,
            status VARCHAR(30) NOT NULL,
            check_in DATETIME(6) NULL,
            check_out DATETIME(6) NULL,
            late_minutes INT NOT NULL DEFAULT 0,
            PRIMARY KEY (id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
    );
    $db->exec(
        "INSERT INTO staff_attendance_entry_methods
            (code, name, method_type, requires_review, allowed_scope, created_by)
         VALUES ('SHADOW_ACCESS_LOG', 'Shadow test access log', 'access_log', 0, 'hr', 9000)"
    );
    $methodId = (int) $db->lastInsertId();
    $eventInsert = $db->prepare(
        "INSERT INTO staff_biometric_events
            (entry_method_id, idempotency_key, staff_user_id, device_event_at, received_at,
             device_timezone, normalized_event_at_utc, event_at_local, clock_offset_seconds,
             clock_status, event_type, raw_hash, raw_payload_ref, link_status, recorded_by, review_status)
         VALUES (?, ?, 1001, ?, ?, 'Africa/Cairo', ?, ?, 0, 'trusted', ?, ?, ?, 'matched', 9000, 'not_required')"
    );
    $eventInsert->execute([
        $methodId,
        'shadow-event-in',
        '2026-01-05 07:25:00.000000',
        '2026-01-05 07:26:00.000000',
        '2026-01-05 05:25:00.000000',
        '2026-01-05 07:25:00.000000',
        'in',
        str_repeat('1', 64),
        'private/raw/sensitive-shadow-in.json',
    ]);
    $eventInsert->execute([
        $methodId,
        'shadow-event-out',
        '2026-01-05 14:30:00.000000',
        '2026-01-05 14:31:00.000000',
        '2026-01-05 12:30:00.000000',
        '2026-01-05 14:30:00.000000',
        'out',
        str_repeat('2', 64),
        'private/raw/sensitive-shadow-out.json',
    ]);
    $db->prepare(
        "INSERT INTO staff_attendance (user_id, attendance_date, status, check_in, check_out, late_minutes)
         VALUES (1001, '2026-01-05', 'present', '2026-01-05 07:25:00.000000', '2026-01-05 14:30:00.000000', 0)"
    )->execute();

    $repository = new PdoAttendanceShadowRunRepository($db);
    $legacy = new PdoLegacyStaffAttendanceDayQuery($db);
    $audit = new AttendanceShadowIntegrationAudit();
    $service = new AttendanceShadowRunService(
        new PdoAttendanceTransactionManager($db),
        $repository,
        new AttendanceShadowIntegrationScheduleQuery($schedule()),
        $repository,
        $legacy,
        new AttendanceDayCalculator(new PunchWindowMatcher()),
        $audit
    );
    $monday = new DateTimeImmutable('2026-01-05 00:00:00', new DateTimeZone('Africa/Cairo'));
    $first = $service->run(9001, [1001], $monday, $monday, 'shadow-integration-one');
    $runId = (int) ($first['run_id'] ?? 0);
    $assert($runId > 0 && ($first['summary']['legacy_matches'] ?? null) === 1, 'shadow service persists a matching legacy comparison on MariaDB');
    $runRead = $db->prepare('SELECT mode, status, summary FROM staff_attendance_runs WHERE id = ?');
    $runRead->execute([$runId]);
    $storedRun = $runRead->fetch(PDO::FETCH_ASSOC) ?: [];
    $assert(($storedRun['mode'] ?? null) === 'shadow' && ($storedRun['status'] ?? null) === 'completed', 'database stores a completed shadow-only run');
    $dayRead = $db->prepare(
        'SELECT id, schedule_policy_version_id, calculation_mode, is_official, status
         FROM staff_attendance_day_versions WHERE run_id = ?'
    );
    $dayRead->execute([$runId]);
    $day = $dayRead->fetch(PDO::FETCH_ASSOC) ?: [];
    $dayId = (int) ($day['id'] ?? 0);
    $assert(
        $dayId > 0
        && (int) ($day['schedule_policy_version_id'] ?? 0) === 812
        && ($day['calculation_mode'] ?? null) === 'shadow'
        && (int) ($day['is_official'] ?? 1) === 0
        && ($day['status'] ?? null) === 'present',
        'shadow day snapshots policy version but remains non-official'
    );
    $segmentCount = $db->prepare('SELECT COUNT(*) FROM staff_attendance_segments WHERE day_version_id = ?');
    $segmentCount->execute([$dayId]);
    $reasonRead = $db->prepare('SELECT reason_code FROM staff_attendance_reason_lines WHERE day_version_id = ? ORDER BY line_no');
    $reasonRead->execute([$dayId]);
    $reasonCodes = $reasonRead->fetchAll(PDO::FETCH_COLUMN);
    $assert(
        (int) $segmentCount->fetchColumn() === 1 && in_array('WORKED_SEGMENT', $reasonCodes, true),
        'shadow day persists calculation child evidence before completing the run'
    );
    $window = $repository->forStaffWindow(
        1001,
        new DateTimeImmutable('2026-01-05 06:30:00', new DateTimeZone('Africa/Cairo')),
        new DateTimeImmutable('2026-01-05 15:30:00', new DateTimeZone('Africa/Cairo'))
    );
    $windowPayload = json_encode($window, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    $assert(
        count($window) === 2
        && !str_contains((string) $windowPayload, 'sensitive-shadow')
        && !array_key_exists('raw_payload_ref', $window[0]),
        'event-window adapter exposes only redacted calculation fields'
    );
    $auditPayload = json_encode($audit->events, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    $assert(!str_contains((string) $auditPayload, 'sensitive-shadow'), 'audit event never carries raw attendance payload references');

    $replay = $service->run(9001, [1001], $monday, $monday, 'shadow-integration-one');
    $assert(($replay['replayed'] ?? null) === true, 'same shadow-run request replays from its immutable receipt');
    $assert((int) $db->query('SELECT COUNT(*) FROM staff_attendance_runs')->fetchColumn() === 1, 'idempotent replay creates no second database run');

    $db->prepare(
        "INSERT INTO staff_attendance (user_id, attendance_date, status, check_in, check_out, late_minutes)
         VALUES (1001, '2026-01-05', 'late', '2026-01-05 07:40:00.000000', '2026-01-05 14:30:00.000000', 10)"
    )->execute();
    $ambiguousLegacy = $legacy->forStaffDate(1001, '2026-01-05') ?? [];
    $assert(
        ($ambiguousLegacy['status'] ?? null) === 'legacy_ambiguous'
        && (int) ($ambiguousLegacy['legacy_row_count'] ?? 0) === 2,
        'legacy adapter refuses to silently choose a duplicate legacy attendance row'
    );

    $runCountBeforeAuditFailure = (int) $db->query('SELECT COUNT(*) FROM staff_attendance_runs')->fetchColumn();
    $audit->failNext = true;
    $expectException(
        static fn () => $service->run(9001, [1002], $monday, $monday, 'shadow-integration-audit-failure'),
        'audit failure aborts the shadow transaction'
    );
    $assert(
        (int) $db->query('SELECT COUNT(*) FROM staff_attendance_runs')->fetchColumn() === $runCountBeforeAuditFailure,
        'failed shadow audit leaves no partially completed run'
    );
} catch (Throwable $exception) {
    $recordFailure('attendance shadow integration exercise failed: ' . $exception->getMessage());
} finally {
    if ($databaseCreated && $admin instanceof PDO) {
        try {
            $db = null;
            $admin->exec('DROP DATABASE ' . $quoteIdentifier($databaseName));
            $databaseDropped = true;
            $exists = $admin->prepare('SELECT COUNT(*) FROM information_schema.SCHEMATA WHERE SCHEMA_NAME = ?');
            $exists->execute([$databaseName]);
            $assert((int) $exists->fetchColumn() === 0, 'temporary shadow-run database is deleted');
        } catch (Throwable $exception) {
            $recordFailure('temporary database cleanup failed: ' . $exception->getMessage());
        }
    }
}

if ($databaseCreated && !$databaseDropped) {
    fwrite(STDERR, "FAIL: temporary database {$databaseName} still exists and requires manual cleanup.\n");
    ++$failures;
}
if ($failures > 0) {
    fwrite(STDERR, "{$failures} attendance shadow run integration failure(s).\n");
    exit(1);
}

echo "Staff-HR attendance shadow run integration passed on {$databaseName}; temporary database removed.\n";
