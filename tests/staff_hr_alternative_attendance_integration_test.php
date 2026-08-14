<?php

declare(strict_types=1);

/**
 * Guarded MariaDB proof for append-only alternative attendance evidence.
 * It only creates and removes a newly named, explicitly marked *_test schema.
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

use EduCore\Modules\Attendance\Application\AlternativeAttendanceRecorder;
use EduCore\Modules\Attendance\Contracts\AlternativeAttendanceAuthorization;
use EduCore\Modules\Attendance\Infrastructure\PdoAlternativeAttendanceEventRepository;
use EduCore\Modules\Attendance\Infrastructure\PdoAttendanceTransactionManager;
use EduCore\Modules\Operations\Audit\AuditEventWriter;
use EduCore\Modules\Staff\Contracts\StaffAssignmentAtDateQuery;

final class AlternativeAttendanceIntegrationAssignments implements StaffAssignmentAtDateQuery
{
    public function forStaff(int $staffUserId, \DateTimeImmutable $atDate): ?array
    {
        return $staffUserId === 1001 ? [
            'assignment_id' => 1,
            'org_unit_id' => 1,
            'job_title_id' => 1,
            'group_ids' => [],
            'employment_status' => 'active',
        ] : null;
    }
}

final class AlternativeAttendanceIntegrationAuthorization implements AlternativeAttendanceAuthorization
{
    public function assertCanRecord(
        int $actorId,
        int $staffUserId,
        string $allowedScope,
        \DateTimeImmutable $atInstant
    ): void {
        if ($actorId !== 9001 || $staffUserId !== 1001 || $allowedScope !== 'hr') {
            throw new \DomainException('ALTERNATIVE_ATTENDANCE_RECORD_NOT_AUTHORIZED');
        }
    }

    public function assertCanReview(
        int $actorId,
        int $staffUserId,
        string $allowedScope,
        \DateTimeImmutable $atInstant
    ): void {
        if ($actorId !== 9002 || $staffUserId !== 1001 || $allowedScope !== 'hr') {
            throw new \DomainException('ALTERNATIVE_ATTENDANCE_REVIEW_NOT_AUTHORIZED');
        }
    }
}

final class AlternativeAttendanceIntegrationAudit implements AuditEventWriter
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
            throw new \RuntimeException('ALTERNATIVE_AUDIT_FAILURE');
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
$triggers = [
    'trg_staff_attendance_entry_method_no_delete',
    'trg_staff_attendance_entry_method_guard_update',
    'trg_staff_biometric_mapping_overlap_insert',
    'trg_staff_biometric_mapping_guard_update',
    'trg_staff_biometric_mapping_no_delete',
    'trg_staff_biometric_event_method_insert',
    'trg_staff_biometric_event_guard_update',
    'trg_staff_biometric_event_no_delete',
    'trg_staff_attendance_run_guard_update',
    'trg_staff_attendance_run_no_delete',
    'trg_staff_attendance_day_guard_insert',
    'trg_staff_attendance_day_guard_update',
    'trg_staff_attendance_day_no_delete',
    'trg_staff_attendance_segment_guard_insert',
    'trg_staff_attendance_segment_no_update',
    'trg_staff_attendance_segment_no_delete',
    'trg_staff_attendance_reason_guard_insert',
    'trg_staff_attendance_reason_no_update',
    'trg_staff_attendance_reason_no_delete',
    'trg_staff_attendance_adjustment_guard_insert',
    'trg_staff_attendance_adjustment_guard_update',
    'trg_staff_attendance_adjustment_no_delete',
];
$tables = [
    'staff_attendance_adjustments',
    'staff_attendance_reason_lines',
    'staff_attendance_segments',
    'staff_attendance_day_versions',
    'staff_attendance_runs',
    'staff_biometric_events',
    'staff_biometric_identity_mappings',
    'staff_biometric_import_batches',
    'staff_attendance_entry_methods',
];

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
        "INSERT INTO staff_attendance_entry_methods
            (code, name, method_type, requires_reason, requires_attachment, requires_review, allowed_scope, created_by)
         VALUES ('ALT_TEST', 'Alternative test', 'manual_verified', 1, 1, 1, 'hr', 9000)"
    );
    $methodId = (int) $db->lastInsertId();

    $audit = new AlternativeAttendanceIntegrationAudit();
    $service = new AlternativeAttendanceRecorder(
        new PdoAttendanceTransactionManager($db),
        new PdoAlternativeAttendanceEventRepository($db),
        new AlternativeAttendanceIntegrationAssignments(),
        new AlternativeAttendanceIntegrationAuthorization(),
        $audit
    );
    $occurredAt = new \DateTimeImmutable('2026-01-05 07:30:00', new \DateTimeZone('Africa/Cairo'));
    $recorded = $service->record(
        9001,
        1001,
        $methodId,
        $occurredAt,
        'تعذر استخدام البصمة',
        [
            'event_type' => 'in',
            'attachment_ref' => 'staff-attendance/alternative/proof-1.pdf',
            'evidence_ref' => 'staff-attendance/alternative/form-1',
        ],
        'alt-integration-1'
    );
    $eventId = (int) ($recorded['event_id'] ?? 0);
    $assert($eventId > 0 && ($recorded['review_status'] ?? null) === 'pending', 'manual alternative evidence is stored as pending review');
    $eventRead = $db->prepare(
        'SELECT device_id, biometric_identity, identity_mapping_id, staff_user_id, link_status, review_status, raw_hash
         FROM staff_biometric_events WHERE id = ?'
    );
    $eventRead->execute([$eventId]);
    $stored = $eventRead->fetch(PDO::FETCH_ASSOC) ?: [];
    $assert(
        array_key_exists('device_id', $stored) && $stored['device_id'] === null
        && array_key_exists('biometric_identity', $stored) && $stored['biometric_identity'] === null
        && array_key_exists('identity_mapping_id', $stored) && $stored['identity_mapping_id'] === null
        && (int) ($stored['staff_user_id'] ?? 0) === 1001
        && ($stored['link_status'] ?? null) === 'matched',
        'alternative event has no biometric/device identity and keeps explicit staff attribution'
    );

    $replayed = $service->record(
        9001,
        1001,
        $methodId,
        $occurredAt,
        'تعذر استخدام البصمة',
        [
            'event_type' => 'in',
            'attachment_ref' => 'staff-attendance/alternative/proof-1.pdf',
            'evidence_ref' => 'staff-attendance/alternative/form-1',
        ],
        'alt-integration-1'
    );
    $assert(($replayed['event_id'] ?? 0) === $eventId && ($replayed['replayed'] ?? false) === true, 'same alternative request replays without another raw event');

    $expectRejected = static function (callable $operation, string $message) use ($assert): void {
        $rejected = false;
        try {
            $operation();
        } catch (Throwable) {
            $rejected = true;
        }
        $assert($rejected, $message);
    };
    $expectRejected(static function () use ($db, $eventId): void {
        $statement = $db->prepare(
            "UPDATE staff_biometric_events
             SET review_status = 'approved', reviewed_by = 9001, reviewed_at = '2026-01-05 08:00:00.000000'
             WHERE id = ?"
        );
        $statement->execute([$eventId]);
    }, 'database rejects a recorder approving their own alternative evidence');
    $reviewed = $service->review(9002, $eventId, 'approved');
    $assert(($reviewed['review_status'] ?? null) === 'approved', 'independent reviewer finalizes the pending alternative event');
    $expectRejected(static function () use ($db, $eventId): void {
        $statement = $db->prepare("UPDATE staff_biometric_events SET reason_text = 'rewritten' WHERE id = ?");
        $statement->execute([$eventId]);
    }, 'raw alternative evidence reason remains immutable after recording');
    $expectRejected(static function () use ($db, $eventId): void {
        $statement = $db->prepare("UPDATE staff_biometric_events SET review_status = 'rejected' WHERE id = ?");
        $statement->execute([$eventId]);
    }, 'final alternative review cannot be rewritten');

    $audit->failNext = true;
    $expectRejected(static function () use ($service, $methodId, $occurredAt): void {
        $service->record(
            9001,
            1001,
            $methodId,
            $occurredAt,
            'سبب آخر',
            [
                'event_type' => 'out',
                'attachment_ref' => 'staff-attendance/alternative/proof-2.pdf',
            ],
            'alt-integration-audit-failure'
        );
    }, 'audit failure aborts the alternative attendance write transaction');
    $eventCount = (int) $db->query('SELECT COUNT(*) FROM staff_biometric_events')->fetchColumn();
    $assert($eventCount === 1, 'failed audited alternative event is rolled back with no orphan raw evidence');
    $auditPayload = json_encode($audit->events, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    $assert(!str_contains((string) $auditPayload, 'تعذر استخدام البصمة'), 'audit payload does not expose alternative attendance reason text');
} catch (Throwable $exception) {
    $recordFailure('alternative attendance integration exercise failed: ' . $exception->getMessage());
} finally {
    if ($db instanceof PDO) {
        foreach ($triggers as $trigger) {
            try {
                $db->exec('DROP TRIGGER IF EXISTS ' . $quoteIdentifier($trigger));
            } catch (Throwable $exception) {
                $recordFailure("cleanup could not drop trigger {$trigger}: " . $exception->getMessage());
            }
        }
        foreach ($tables as $table) {
            try {
                $db->exec('DROP TABLE IF EXISTS ' . $quoteIdentifier($table));
            } catch (Throwable $exception) {
                $recordFailure("cleanup could not drop table {$table}: " . $exception->getMessage());
            }
        }
    }
    if ($databaseCreated && $admin instanceof PDO) {
        try {
            $db = null;
            $admin->exec('DROP DATABASE ' . $quoteIdentifier($databaseName));
            $databaseDropped = true;
            $exists = $admin->prepare('SELECT COUNT(*) FROM information_schema.SCHEMATA WHERE SCHEMA_NAME = ?');
            $exists->execute([$databaseName]);
            $assert((int) $exists->fetchColumn() === 0, 'temporary alternative-attendance database is deleted');
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
    fwrite(STDERR, "{$failures} alternative attendance integration failure(s).\n");
    exit(1);
}

echo "Staff-HR alternative attendance integration passed on {$databaseName}; temporary database removed.\n";
