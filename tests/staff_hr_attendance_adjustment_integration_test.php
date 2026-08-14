<?php

declare(strict_types=1);

/** Guarded integration proof for versioned, audited attendance corrections. */

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

use EduCore\Modules\Attendance\Application\AttendanceAdjustmentService;
use EduCore\Modules\Attendance\Contracts\AttendanceAdjustmentAuthorization;
use EduCore\Modules\Attendance\Infrastructure\PdoAttendanceAdjustmentRepository;
use EduCore\Modules\Attendance\Infrastructure\PdoAttendanceTransactionManager;
use EduCore\Modules\Operations\Audit\AuditEventWriter;

final class AttendanceAdjustmentIntegrationAuthorization implements AttendanceAdjustmentAuthorization
{
    public function assertCanAct(
        int $actorId,
        int $staffUserId,
        string $requesterKind,
        string $action,
        ?int $workflowInstanceId,
        \DateTimeImmutable $atInstant
    ): void {
        $allowed = $staffUserId === 1001
            && (($actorId === 1001 && $requesterKind === 'self' && in_array($action, ['request', 'submit', 'cancel'], true))
                || ($actorId === 9002 && $action === 'decide'));
        if (!$allowed) {
            throw new \DomainException('ATTENDANCE_ADJUSTMENT_NOT_AUTHORIZED');
        }
    }
}

final class AttendanceAdjustmentIntegrationAudit implements AuditEventWriter
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
            throw new \RuntimeException('ATTENDANCE_ADJUSTMENT_AUDIT_FAILURE');
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
    $failed = false;
    try {
        $operation();
    } catch (Throwable) {
        $failed = true;
    }
    $assert($failed, $message);
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
            (code, name, method_type, requires_review, allowed_scope, created_by)
         VALUES ('RAW_ALT_TEST', 'Raw alternative fixture', 'access_log', 0, 'hr', 1)"
    );
    $rawMethodId = (int) $db->lastInsertId();
    $rawInsert = $db->prepare(
        "INSERT INTO staff_biometric_events
            (entry_method_id, idempotency_key, staff_user_id, device_event_at, received_at,
             device_timezone, normalized_event_at_utc, event_at_local, clock_offset_seconds,
             clock_status, event_type, raw_hash, link_status, recorded_by, reason_text, review_status)
         VALUES (?, 'raw-fixture-1', 1001, '2026-01-05 07:25:00.000000', '2026-01-05 07:26:00.000000',
                 'Africa/Cairo', '2026-01-05 05:25:00.000000', '2026-01-05 07:25:00.000000', 0,
                 'trusted', 'in', ?, 'matched', 1001, 'fixture reason', 'not_required')"
    );
    $rawInsert->execute([$rawMethodId, str_repeat('1', 64)]);
    $rawEventId = (int) $db->lastInsertId();

    $runInsert = $db->prepare(
        "INSERT INTO staff_attendance_runs
            (engine_version, mode, range_from, range_to, cutoff_at, initiated_by, status,
             source_fingerprint, idempotency_key, supersedes_run_id)
         VALUES ('attendance-v1', 'official', '2026-01-05', '2026-01-05',
                 '2026-01-06 00:00:00.000000', 1, 'queued', ?, 'base-run', NULL)"
    );
    $runInsert->execute([str_repeat('a', 64)]);
    $baseRunId = (int) $db->lastInsertId();
    $db->prepare("UPDATE staff_attendance_runs SET status = 'running', started_at = '2026-01-06 01:00:00.000000' WHERE id = ?")
        ->execute([$baseRunId]);
    $db->prepare("UPDATE staff_attendance_runs SET status = 'completed', finished_at = '2026-01-06 01:10:00.000000' WHERE id = ?")
        ->execute([$baseRunId]);
    $dayInsert = $db->prepare(
        "INSERT INTO staff_attendance_day_versions
            (staff_user_id, work_date, version_no, run_id, expected_start, expected_end,
             required_minutes, first_in, last_out, worked_minutes, covered_late_minutes,
             covered_early_minutes, mission_minutes, leave_minutes, late_minutes,
             early_leave_minutes, missing_minutes, status, calculation_mode, engine_version,
             source_fingerprint, supersedes_id, calculated_at)
         VALUES (1001, '2026-01-05', 1, ?, '2026-01-05 07:30:00.000000',
                 '2026-01-05 14:30:00.000000', 420, '2026-01-05 07:45:00.000000',
                 '2026-01-05 14:30:00.000000', 405, 0, 0, 0, 0, 15, 0, 15,
                 'partial', 'official', 'attendance-v1', ?, NULL, '2026-01-06 01:11:00.000000')"
    );
    $dayInsert->execute([$baseRunId, str_repeat('b', 64)]);
    $baseDayId = (int) $db->lastInsertId();
    $db->prepare(
        "INSERT INTO staff_attendance_segments
            (day_version_id, sequence_no, segment_type, expected_start, expected_end, actual_start,
             actual_end, required_minutes, worked_minutes, missing_minutes, status)
         VALUES (?, 1, 'work', '2026-01-05 07:30:00.000000', '2026-01-05 14:30:00.000000',
                 '2026-01-05 07:45:00.000000', '2026-01-05 14:30:00.000000', 420, 405, 15, 'partial')"
    )->execute([$baseDayId]);
    $db->prepare(
        "INSERT INTO staff_attendance_reason_lines
            (day_version_id, line_no, reason_code, minutes, source_type, explanation)
         VALUES (?, 1, 'LATE_ARRIVAL', 15, 'schedule', 'Original calculated late arrival')"
    )->execute([$baseDayId]);
    $db->prepare(
        "UPDATE staff_attendance_day_versions
         SET is_official = 1, officialized_by = 1, officialized_at = '2026-01-06 01:12:00.000000'
         WHERE id = ?"
    )->execute([$baseDayId]);

    $audit = new AttendanceAdjustmentIntegrationAudit();
    $service = new AttendanceAdjustmentService(
        new PdoAttendanceTransactionManager($db),
        new PdoAttendanceAdjustmentRepository($db),
        new AttendanceAdjustmentIntegrationAuthorization(),
        $audit
    );
    $proposal = [
        'first_in' => '2026-01-05 07:30:00',
        'worked_minutes' => 420,
        'late_minutes' => 0,
        'missing_minutes' => 0,
        'status' => 'present',
    ];
    $draft = $service->createDraft(1001, 1001, 'self', '2026-01-05', 'تصحيح وقت الحضور', $proposal, 'adjustment-1');
    $draftId = (int) ($draft['adjustment_id'] ?? 0);
    $assert($draftId > 0 && ($draft['before_version_id'] ?? 0) === $baseDayId && ($draft['status'] ?? null) === 'draft', 'self correction starts from the current official day as a draft');
    $replayedDraft = $service->createDraft(1001, 1001, 'self', '2026-01-05', 'تصحيح وقت الحضور', $proposal, 'adjustment-1');
    $assert(($replayedDraft['adjustment_id'] ?? 0) === $draftId && ($replayedDraft['replayed'] ?? false) === true, 'same correction request replays idempotently');
    $staleDraft = $service->createDraft(1001, 1001, 'self', '2026-01-05', 'طلب متزامن', ['late_minutes' => 0], 'adjustment-stale');
    $submitted = $service->submit(1001, $draftId, 1, 7001);
    $assert(($submitted['status'] ?? null) === 'pending' && ($submitted['lock_version'] ?? 0) === 2, 'draft submission freezes facts and advances optimistic lock version');
    $expectException(static fn () => $service->decide(1001, $draftId, 2, 'approved'), 'requester cannot approve their own attendance correction');
    $approved = $service->decide(9002, $draftId, 2, 'approved', 'تمت المراجعة المستقلة');
    $approvedDayId = (int) ($approved['approved_version_id'] ?? 0);
    $assert(($approved['status'] ?? null) === 'approved' && $approvedDayId > $baseDayId, 'independent approval produces a new official day version');

    $dayRead = $db->prepare(
        'SELECT id, version_no, run_id, first_in, worked_minutes, late_minutes, missing_minutes, status, is_official, supersedes_id
         FROM staff_attendance_day_versions WHERE staff_user_id = 1001 AND work_date = ? ORDER BY version_no'
    );
    $dayRead->execute(['2026-01-05']);
    $versions = $dayRead->fetchAll(PDO::FETCH_ASSOC);
    $assert(count($versions) === 2 && (int) $versions[0]['is_official'] === 0 && (int) $versions[1]['is_official'] === 1, 'approval demotes only the predecessor and leaves exactly one official successor');
    $assert(
        ($versions[0]['first_in'] ?? null) === '2026-01-05 07:45:00.000000'
        && ($versions[0]['status'] ?? null) === 'partial'
        && (int) ($versions[1]['supersedes_id'] ?? 0) === $baseDayId
        && ($versions[1]['first_in'] ?? null) === '2026-01-05 07:30:00.000000'
        && (int) ($versions[1]['worked_minutes'] ?? 0) === 420
        && ($versions[1]['status'] ?? null) === 'present',
        'predecessor remains immutable while successor contains only approved correction values'
    );
    $segmentCount = $db->prepare('SELECT COUNT(*) FROM staff_attendance_segments WHERE day_version_id = ?');
    $segmentCount->execute([$approvedDayId]);
    $reasonRead = $db->prepare('SELECT reason_code, metadata FROM staff_attendance_reason_lines WHERE day_version_id = ? ORDER BY line_no');
    $reasonRead->execute([$approvedDayId]);
    $reasonLines = $reasonRead->fetchAll(PDO::FETCH_ASSOC);
    $reasonCodes = array_column($reasonLines, 'reason_code');
    $adjustmentReason = array_values(array_filter(
        $reasonLines,
        static fn (array $line): bool => ($line['reason_code'] ?? null) === 'ATTENDANCE_ADJUSTMENT_APPROVED'
    ))[0] ?? [];
    $adjustmentMetadata = json_decode((string) ($adjustmentReason['metadata'] ?? ''), true);
    $assert(
        (int) $segmentCount->fetchColumn() === 1
        && in_array('ATTENDANCE_ADJUSTMENT_APPROVED', $reasonCodes, true)
        && ($adjustmentMetadata['proposed_values']['worked_minutes'] ?? null) === 420,
        'approved successor preserves child evidence and records the approved correction values in its explanation line'
    );
    $runRead = $db->prepare('SELECT mode, supersedes_run_id, status FROM staff_attendance_runs WHERE id = ?');
    $runRead->execute([(int) $versions[1]['run_id']]);
    $adjustmentRun = $runRead->fetch(PDO::FETCH_ASSOC) ?: [];
    $assert(($adjustmentRun['mode'] ?? null) === 'recalculation' && (int) ($adjustmentRun['supersedes_run_id'] ?? 0) === $baseRunId && ($adjustmentRun['status'] ?? null) === 'completed', 'successor is backed by a completed recalculation run linked to the original run');
    $rawRead = $db->prepare('SELECT raw_hash, reason_text FROM staff_biometric_events WHERE id = ?');
    $rawRead->execute([$rawEventId]);
    $rawAfter = $rawRead->fetch(PDO::FETCH_ASSOC) ?: [];
    $assert(($rawAfter['raw_hash'] ?? null) === str_repeat('1', 64) && ($rawAfter['reason_text'] ?? null) === 'fixture reason', 'correction never mutates prior raw attendance evidence');

    $staleId = (int) ($staleDraft['adjustment_id'] ?? 0);
    $staleSubmitted = $service->submit(1001, $staleId, 1, 7002);
    $expectException(static fn () => $service->decide(9002, $staleId, (int) $staleSubmitted['lock_version'], 'approved'), 'approval fails closed when another official version superseded the correction source');
    $currentOfficial = (int) $db->query("SELECT id FROM staff_attendance_day_versions WHERE staff_user_id = 1001 AND work_date = '2026-01-05' AND is_official = 1")->fetchColumn();
    $assert($currentOfficial === $approvedDayId, 'stale correction cannot replace the current official result');

    $cancelledDraft = $service->createDraft(1001, 1001, 'self', '2026-01-05', 'طلب تم سحبه', ['late_minutes' => 0], 'adjustment-cancelled');
    $cancelled = $service->cancel(1001, (int) $cancelledDraft['adjustment_id'], 1, 'لم يعد التصحيح مطلوباً');
    $cancelledState = $db->prepare('SELECT status, submitted_at FROM staff_attendance_adjustments WHERE id = ?');
    $cancelledState->execute([(int) $cancelledDraft['adjustment_id']]);
    $cancelledRow = $cancelledState->fetch(PDO::FETCH_ASSOC) ?: [];
    $assert(
        ($cancelled['status'] ?? null) === 'cancelled'
        && ($cancelledRow['status'] ?? null) === 'cancelled'
        && !empty($cancelledRow['submitted_at']),
        'requester can cancel a draft without deleting workflow evidence'
    );

    $auditDraft = $service->createDraft(1001, 1001, 'self', '2026-01-05', 'اختبار فشل التدقيق', ['early_leave_minutes' => 0], 'adjustment-audit-failure');
    $auditDraftId = (int) $auditDraft['adjustment_id'];
    $auditSubmitted = $service->submit(1001, $auditDraftId, 1, 7003);
    $audit->failNext = true;
    $expectException(static fn () => $service->decide(9002, $auditDraftId, (int) $auditSubmitted['lock_version'], 'approved'), 'audit failure aborts correction publication atomically');
    $auditState = $db->prepare('SELECT status FROM staff_attendance_adjustments WHERE id = ?');
    $auditState->execute([$auditDraftId]);
    $assert(($auditState->fetchColumn() ?? null) === 'pending', 'audit failure leaves the correction pending rather than partially approved');
    $assert((int) $db->query("SELECT id FROM staff_attendance_day_versions WHERE staff_user_id = 1001 AND work_date = '2026-01-05' AND is_official = 1")->fetchColumn() === $approvedDayId, 'audit failure rolls back successor publication and predecessor demotion');

    $rejectedDraft = $service->createDraft(1001, 1001, 'self', '2026-01-05', 'طلب مرفوض', ['late_minutes' => 5], 'adjustment-rejected');
    $rejectedSubmitted = $service->submit(1001, (int) $rejectedDraft['adjustment_id'], 1, 7004);
    $rejected = $service->decide(9002, (int) $rejectedDraft['adjustment_id'], (int) $rejectedSubmitted['lock_version'], 'rejected', 'لا يكفي الدليل');
    $assert(($rejected['status'] ?? null) === 'rejected' && ($rejected['approved_version_id'] ?? null) === null, 'independent rejection finalizes workflow without another day version');
    $expectException(static fn () => $db->prepare("UPDATE staff_attendance_adjustments SET resolution_comment = 'rewritten' WHERE id = ?")->execute([$draftId]), 'final correction decision is immutable at the schema boundary');
    $auditSerialized = json_encode($audit->events, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    $assert(!str_contains((string) $auditSerialized, 'تصحيح وقت الحضور') && !str_contains((string) $auditSerialized, '07:30:00'), 'correction audit records hashes and field names rather than sensitive request details');
} catch (Throwable $exception) {
    $recordFailure('attendance adjustment integration exercise failed: ' . $exception->getMessage());
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
            $assert((int) $exists->fetchColumn() === 0, 'temporary correction database is deleted');
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
    fwrite(STDERR, "{$failures} attendance adjustment integration failure(s).\n");
    exit(1);
}

echo "Staff-HR attendance adjustment integration passed on {$databaseName}; temporary database removed.\n";
