<?php

declare(strict_types=1);

/**
 * Guarded MariaDB integration proof for the Staff-HR attendance schema.
 *
 * The test creates the explicitly named *_test database itself, refuses to
 * reuse any existing schema, applies the additive migration twice, exercises
 * the database invariants, rolls back only migration-owned objects, proves the
 * schema is empty again, and finally drops the temporary database.
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
    || !preg_match('/^[A-Za-z0-9_]+_test$/', $databaseName)
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

$admin = null;
$db = null;
$databaseCreated = false;
$databaseDropped = false;

$attendanceTables = [
    'staff_attendance_entry_methods',
    'staff_biometric_import_batches',
    'staff_biometric_identity_mappings',
    'staff_biometric_events',
    'staff_attendance_runs',
    'staff_attendance_day_versions',
    'staff_attendance_segments',
    'staff_attendance_reason_lines',
    'staff_attendance_adjustments',
];
$attendanceTriggers = [
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
$rollbackTables = [
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

$quoteIdentifier = static function (string $identifier): string {
    if (!preg_match('/^[A-Za-z0-9_]+$/', $identifier)) {
        throw new InvalidArgumentException('Unsafe database identifier.');
    }

    return '`' . $identifier . '`';
};

try {
    $admin = new PDO(
        'mysql:host=' . DB_HOST . ';charset=utf8mb4',
        DB_USERNAME,
        DB_PASSWORD,
        [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
    );
    $exists = $admin->prepare(
        'SELECT COUNT(*) FROM information_schema.SCHEMATA WHERE SCHEMA_NAME = ?'
    );
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

    $schemaObjects = static function (string $kind) use ($db): array {
        $sql = $kind === 'table'
            ? 'SELECT TABLE_NAME FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() ORDER BY TABLE_NAME'
            : 'SELECT TRIGGER_NAME FROM information_schema.TRIGGERS WHERE TRIGGER_SCHEMA = DATABASE() ORDER BY TRIGGER_NAME';

        return array_map('strval', $db->query($sql)->fetchAll(PDO::FETCH_COLUMN));
    };
    $expectRejected = static function (
        callable $operation,
        string $message,
        array $expectedSqlStates = ['45000']
    ) use ($assert): void {
        $rejected = false;
        $sqlState = '';
        try {
            $operation();
        } catch (Throwable $exception) {
            $rejected = true;
            $sqlState = $exception instanceof PDOException && isset($exception->errorInfo[0])
                ? (string) $exception->errorInfo[0]
                : (string) $exception->getCode();
        }

        $assert($rejected, $message);
        if ($rejected && $expectedSqlStates !== []) {
            $assert(
                in_array($sqlState, $expectedSqlStates, true),
                $message . ' (SQLSTATE ' . implode(' or ', $expectedSqlStates) . ')'
            );
        }
    };
    $expectRejectedAndRollback = static function (
        callable $operation,
        string $message,
        array $expectedSqlStates = ['45000']
    ) use ($db, $assert): void {
        $rejected = false;
        $sqlState = '';
        $db->beginTransaction();
        try {
            $operation();
        } catch (Throwable $exception) {
            $rejected = true;
            $sqlState = $exception instanceof PDOException && isset($exception->errorInfo[0])
                ? (string) $exception->errorInfo[0]
                : (string) $exception->getCode();
        } finally {
            if ($db->inTransaction()) {
                $db->rollBack();
            }
        }

        $assert($rejected, $message);
        if ($rejected && $expectedSqlStates !== []) {
            $assert(
                in_array($sqlState, $expectedSqlStates, true),
                $message . ' (SQLSTATE ' . implode(' or ', $expectedSqlStates) . ')'
            );
        }
    };

    $assert($schemaObjects('table') === [], 'new test database starts with no tables');
    $assert($schemaObjects('trigger') === [], 'new test database starts with no triggers');
    $assert(count($attendanceTables) === 9, 'owned attendance table manifest contains 9 tables');
    $assert(count($attendanceTriggers) === 22, 'owned attendance trigger manifest contains 22 triggers');

    $migration = require dirname(__DIR__)
        . '/database/migrations/20260730_staff_hr_attendance_engine.php';
    $assert(is_callable($migration), 'attendance migration returns a callable');
    $migration($db);
    $migration($db);

    $expectedTables = $attendanceTables;
    $expectedTriggers = $attendanceTriggers;
    sort($expectedTables);
    sort($expectedTriggers);
    $assert($schemaObjects('table') === $expectedTables, 'two applies create exactly the 9 owned tables');
    $assert($schemaObjects('trigger') === $expectedTriggers, 'two applies create exactly the 22 owned triggers');

    // Entry-method semantics become historical as soon as one event uses them.
    $db->exec(
        "INSERT INTO staff_attendance_entry_methods
            (code, name, method_type, requires_review, allowed_scope, created_by)
         VALUES ('TEST_BIOMETRIC', 'Test biometric', 'biometric', 1, 'all_staff', 990001)"
    );
    $methodId = (int) $db->lastInsertId();

    $mappingInsert = $db->prepare(
        "INSERT INTO staff_biometric_identity_mappings
            (device_id, biometric_identity, staff_user_id, valid_from, valid_to, source,
             confirmed_by, retired_reason)
         VALUES (?, ?, ?, ?, ?, 'integration_test', 990001, ?)"
    );
    $mappingInsert->execute([
        7001, 'BIO-42', 1001, '2026-09-01 00:00:00.000000',
        '2026-10-01 00:00:00.000000', 'Scheduled reassignment',
    ]);
    $mappingOneId = (int) $db->lastInsertId();

    $expectRejected(static function () use ($mappingInsert): void {
        $mappingInsert->execute([
            7001, 'BIO-42', 1999, '2026-09-30 23:59:59.000000',
            '2026-10-15 00:00:00.000000', 'Overlapping fixture',
        ]);
    }, 'overlapping biometric identity periods are rejected');

    $mappingInsert->execute([
        7001, 'BIO-42', 1002, '2026-10-01 00:00:00.000000', null, null,
    ]);
    $mappingTwoId = (int) $db->lastInsertId();
    $assert($mappingTwoId > $mappingOneId, 'identity reuse is accepted at the exact half-open boundary');

    $mappingInsert->execute([
        7002, 'CLOCK-7', 1003, '2026-10-01 10:00:00.000000', null, null,
    ]);
    $clockMappingId = (int) $db->lastInsertId();

    $eventInsert = $db->prepare(
        "INSERT INTO staff_biometric_events
            (entry_method_id, device_id, external_event_key, idempotency_key,
             biometric_identity, identity_mapping_id, staff_user_id,
             device_event_at, received_at, device_timezone,
             normalized_event_at_utc, event_at_local, clock_offset_seconds,
             clock_status, event_type, raw_hash, link_status, review_status, recorded_by)
         VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, 'Africa/Cairo', ?, ?, ?, ?, ?, ?, 'matched', 'pending', ?)"
    );
    $eventInsert->execute([
        $methodId, 7001, 'event-1', 'attendance-event-1', 'BIO-42', $mappingOneId, 1001,
        '2026-09-15 07:30:00.000000', '2026-09-15 07:31:00.000000',
        '2026-09-15 04:30:00.000000', '2026-09-15 07:30:00.000000', 0,
        'trusted', 'in', str_repeat('1', 64), 990010,
    ]);
    $eventOneId = (int) $db->lastInsertId();

    $expectRejected(static function () use ($db, $methodId): void {
        $statement = $db->prepare(
            'UPDATE staff_attendance_entry_methods SET requires_reason = 1 WHERE id = ?'
        );
        $statement->execute([$methodId]);
    }, 'used attendance entry-method semantics are immutable');
    $methodRename = $db->prepare('UPDATE staff_attendance_entry_methods SET name = ? WHERE id = ?');
    $methodRename->execute(['Renamed test biometric', $methodId]);
    $assert($methodRename->rowCount() === 1, 'non-semantic entry-method display name remains editable');

    $expectRejected(static function () use ($eventInsert, $methodId, $mappingOneId): void {
        $eventInsert->execute([
            $methodId, 7001, 'event-wrong-staff', 'attendance-event-wrong-staff',
            'BIO-42', $mappingOneId, 1002,
            '2026-09-15 08:00:00.000000', '2026-09-15 08:01:00.000000',
            '2026-09-15 05:00:00.000000', '2026-09-15 08:00:00.000000', 0,
            'trusted', 'in', str_repeat('2', 64), 990010,
        ]);
    }, 'matched biometric attribution must agree with mapping staff identity');

    $expectRejected(static function () use ($eventInsert, $methodId, $mappingOneId): void {
        $eventInsert->execute([
            $methodId, 7001, 'event-expired-map', 'attendance-event-expired-map',
            'BIO-42', $mappingOneId, 1001,
            '2026-09-30 23:58:00.000000', '2026-10-01 00:01:00.000000',
            '2026-09-30 21:00:00.000000', '2026-10-01 00:00:00.000000', 120,
            'drifted', 'in', str_repeat('3', 64), 990010,
        ]);
    }, 'the old mapping is excluded at its corrected half-open end boundary');

    $eventInsert->execute([
        $methodId, 7001, 'event-boundary-map', 'attendance-event-boundary-map',
        'BIO-42', $mappingTwoId, 1002,
        '2026-09-30 23:58:00.000000', '2026-10-01 00:01:00.000000',
        '2026-09-30 21:00:00.000000', '2026-10-01 00:00:00.000000', 120,
        'drifted', 'in', str_repeat('4', 64), 990010,
    ]);
    $assert((int) $db->lastInsertId() > $eventOneId, 'the replacement mapping owns the corrected boundary event');

    $eventInsert->execute([
        $methodId, 7002, 'event-corrected-clock', 'attendance-event-corrected-clock',
        'CLOCK-7', $clockMappingId, 1003,
        '2026-10-01 09:58:00.000000', '2026-10-01 10:02:00.000000',
        '2026-10-01 07:01:00.000000', '2026-10-01 10:01:00.000000', 180,
        'drifted', 'in', str_repeat('5', 64), 990010,
    ]);
    $assert((int) $db->lastInsertId() > 0, 'corrected local event time selects the effective mapping despite device drift');

    $expectRejected(static function () use ($db, $eventOneId): void {
        $statement = $db->prepare(
            "UPDATE staff_biometric_events SET event_at_local = '2026-09-15 07:31:00' WHERE id = ?"
        );
        $statement->execute([$eventOneId]);
    }, 'raw event clock normalization is immutable');
    $expectRejected(static function () use ($db, $eventOneId): void {
        $statement = $db->prepare('UPDATE staff_biometric_events SET staff_user_id = 1002 WHERE id = ?');
        $statement->execute([$eventOneId]);
    }, 'raw event attribution is immutable');
    $reviewEvent = $db->prepare(
        "UPDATE staff_biometric_events
         SET review_status = 'approved', reviewed_by = 990011,
             reviewed_at = '2026-09-15 09:00:00.000000'
         WHERE id = ?"
    );
    $reviewEvent->execute([$eventOneId]);
    $assert($reviewEvent->rowCount() === 1, 'pending event review can be finalized independently');
    $expectRejected(static function () use ($db, $eventOneId): void {
        $statement = $db->prepare(
            "UPDATE staff_biometric_events SET review_status = 'rejected' WHERE id = ?"
        );
        $statement->execute([$eventOneId]);
    }, 'final event review cannot be rewritten');

    // Calculation runs gate day publication; day contents are completed first.
    $runInsert = $db->prepare(
        "INSERT INTO staff_attendance_runs
            (engine_version, mode, range_from, range_to, cutoff_at, initiated_by,
             status, source_fingerprint, idempotency_key, supersedes_run_id)
         VALUES (?, ?, '2026-10-01', '2026-10-01', '2026-10-02 00:00:00.000000',
                 990020, 'queued', ?, ?, ?)"
    );
    $runInsert->execute(['attendance-v1', 'official', str_repeat('a', 64), 'attendance-run-1', null]);
    $runOneId = (int) $db->lastInsertId();

    $dayInsert = $db->prepare(
        "INSERT INTO staff_attendance_day_versions
            (staff_user_id, work_date, version_no, run_id, expected_start, expected_end,
             required_minutes, first_in, last_out, worked_minutes, status,
             calculation_mode, engine_version, source_fingerprint, supersedes_id, calculated_at)
         VALUES (?, '2026-10-01', ?, ?, '2026-10-01 07:30:00.000000',
                 '2026-10-01 14:30:00.000000', 420, '2026-10-01 07:28:00.000000',
                 '2026-10-01 14:31:00.000000', 420, 'present', ?, ?, ?, ?,
                 '2026-10-02 00:10:00.000000')"
    );
    $dayInsert->execute([
        3001, 1, $runOneId, 'official', 'attendance-v1', str_repeat('b', 64), null,
    ]);
    $dayOneId = (int) $db->lastInsertId();

    $expectRejected(static function () use ($dayInsert, $runOneId): void {
        $dayInsert->execute([
            3002, 1, $runOneId, 'shadow', 'attendance-v1', str_repeat('c', 64), null,
        ]);
    }, 'day calculation mode must match its run');

    $segmentInsert = $db->prepare(
        "INSERT INTO staff_attendance_segments
            (day_version_id, sequence_no, segment_type, expected_start, expected_end,
             actual_start, actual_end, required_minutes, worked_minutes, status)
         VALUES (?, ?, 'work', '2026-10-01 07:30:00.000000',
                 '2026-10-01 14:30:00.000000', '2026-10-01 07:28:00.000000',
                 '2026-10-01 14:31:00.000000', 420, 420, 'matched')"
    );
    $reasonInsert = $db->prepare(
        "INSERT INTO staff_attendance_reason_lines
            (day_version_id, line_no, reason_code, minutes, source_type, explanation)
         VALUES (?, ?, 'ON_TIME', 0, 'engine', 'Attendance matched expected schedule')"
    );
    $segmentInsert->execute([$dayOneId, 1]);
    $reasonInsert->execute([$dayOneId, 1]);

    $publishDay = $db->prepare(
        "UPDATE staff_attendance_day_versions
         SET is_official = 1, officialized_by = 990021, officialized_at = ?
         WHERE id = ?"
    );
    $expectRejected(static function () use ($publishDay, $dayOneId): void {
        $publishDay->execute(['2026-10-02 02:00:00.000000', $dayOneId]);
    }, 'queued calculation run cannot publish an official day');

    $startRun = $db->prepare(
        "UPDATE staff_attendance_runs
         SET status = 'running', started_at = '2026-10-02 01:00:00.000000'
         WHERE id = ?"
    );
    $finishRun = $db->prepare(
        "UPDATE staff_attendance_runs
         SET status = 'completed', finished_at = '2026-10-02 01:30:00.000000'
         WHERE id = ?"
    );
    $startRun->execute([$runOneId]);
    $finishRun->execute([$runOneId]);
    $expectRejected(static function () use ($publishDay, $dayOneId): void {
        $publishDay->execute(['2026-10-02 01:29:59.000000', $dayOneId]);
    }, 'publication timestamp cannot predate run completion');
    $publishDay->execute(['2026-10-02 01:31:00.000000', $dayOneId]);
    $assert($publishDay->rowCount() === 1, 'completed matching official run can publish its day');

    $dayInsert->execute([
        3999, 1, $runOneId, 'official', 'attendance-v1', str_repeat('c', 64), null,
    ]);
    $otherStaffDayId = (int) $db->lastInsertId();
    $segmentInsert->execute([$otherStaffDayId, 1]);
    $reasonInsert->execute([$otherStaffDayId, 1]);
    $publishDay->execute(['2026-10-02 01:31:00.000000', $otherStaffDayId]);
    $assert($publishDay->rowCount() === 1, 'another staff member can have an independent official day');

    $runInsert->execute(['attendance-v2', 'recalculation', str_repeat('d', 64), 'attendance-run-2', $runOneId]);
    $runTwoId = (int) $db->lastInsertId();
    $startRun->execute([$runTwoId]);
    $finishRun->execute([$runTwoId]);

    $runInsert->execute(['attendance-v2-bad', 'recalculation', str_repeat('e', 64), 'attendance-run-no-chain', null]);
    $runWithoutChainId = (int) $db->lastInsertId();
    $startRun->execute([$runWithoutChainId]);
    $finishRun->execute([$runWithoutChainId]);

    $demoteDay = $db->prepare('UPDATE staff_attendance_day_versions SET is_official = 0 WHERE id = ?');
    $demoteDay->execute([$dayOneId]);
    $assert($demoteDay->rowCount() === 1, 'former official day is demoted without rewriting publication evidence');

    $expectRejectedAndRollback(static function () use ($publishDay, $dayOneId): void {
        $publishDay->execute(['2026-10-02 01:32:00.000000', $dayOneId]);
    }, 'a demoted former official version cannot be republished');

    $expectRejectedAndRollback(static function () use ($db, $dayInsert, $publishDay, $runTwoId): void {
        $dayInsert->execute([
            3001, 3, $runTwoId, 'recalculation', 'attendance-v2', str_repeat('6', 64), null,
        ]);
        $forkId = (int) $db->lastInsertId();
        $publishDay->execute(['2026-10-02 01:33:00.000000', $forkId]);
    }, 'a later official day cannot fork without superseding the former official version');

    $expectRejectedAndRollback(static function () use (
        $dayInsert,
        $db,
        $publishDay,
        $runWithoutChainId,
        $dayOneId
    ): void {
        $dayInsert->execute([
            3001, 3, $runWithoutChainId, 'recalculation', 'attendance-v2-bad',
            str_repeat('7', 64), $dayOneId,
        ]);
        $candidateId = (int) $db->lastInsertId();
        $publishDay->execute(['2026-10-02 01:33:00.000000', $candidateId]);
    }, 'a replacement day must come from a run that supersedes the former official run');

    $expectRejectedAndRollback(static function () use (
        $dayInsert,
        $db,
        $publishDay,
        $runTwoId,
        $otherStaffDayId
    ): void {
        $dayInsert->execute([
            3001, 97, $runTwoId, 'recalculation', 'attendance-v2',
            str_repeat('a', 64), $otherStaffDayId,
        ]);
        $wrongPredecessorId = (int) $db->lastInsertId();
        $publishDay->execute(['2026-10-02 01:33:00.000000', $wrongPredecessorId]);
    }, 'official successor must point to a former official day for the same staff member and date');

    $dayInsert->execute([
        3001, 98, $runWithoutChainId, 'recalculation', 'attendance-v2-bad',
        str_repeat('0', 64), $dayOneId,
    ]);
    $abandonedCandidateId = (int) $db->lastInsertId();
    $dayInsert->execute([
        3001, 2, $runTwoId, 'recalculation', 'attendance-v2', str_repeat('f', 64), $dayOneId,
    ]);
    $dayTwoId = (int) $db->lastInsertId();
    $assert(
        $dayTwoId > $abandonedCandidateId,
        'an unofficiated recalculation candidate cannot reserve the historical successor slot forever'
    );
    $segmentInsert->execute([$dayTwoId, 1]);
    $reasonInsert->execute([$dayTwoId, 1]);
    $publishDay->execute(['2026-10-02 01:34:00.000000', $dayTwoId]);
    $assert($publishDay->rowCount() === 1, 'valid recalculation publishes a direct official successor');

    $runInsert->execute(['attendance-v3', 'recalculation', str_repeat('8', 64), 'attendance-run-3', $runTwoId]);
    $runThreeId = (int) $db->lastInsertId();
    $startRun->execute([$runThreeId]);
    $finishRun->execute([$runThreeId]);
    $expectRejectedAndRollback(static function () use (
        $db,
        $dayInsert,
        $segmentInsert,
        $reasonInsert,
        $publishDay,
        $runThreeId,
        $dayTwoId
    ): void {
        $dayInsert->execute([
            3001, 3, $runThreeId, 'recalculation', 'attendance-v3', str_repeat('9', 64), $dayTwoId,
        ]);
        $dayThreeId = (int) $db->lastInsertId();
        $segmentInsert->execute([$dayThreeId, 1]);
        $reasonInsert->execute([$dayThreeId, 1]);
        $publishDay->execute(['2026-10-02 01:35:00.000000', $dayThreeId]);
    }, 'only one official version may exist for a staff member and work date', ['23000']);

    $officialCount = $db->prepare(
        'SELECT COUNT(*) FROM staff_attendance_day_versions WHERE staff_user_id = ? AND work_date = ? AND is_official = 1'
    );
    $officialCount->execute([3001, '2026-10-01']);
    $assert((int) $officialCount->fetchColumn() === 1, 'exactly one official day remains after supersession');

    $expectRejected(static function () use ($segmentInsert, $dayOneId): void {
        $segmentInsert->execute([$dayOneId, 2]);
    }, 'segments stay frozen after a former official day is demoted');
    $expectRejected(static function () use ($reasonInsert, $dayOneId): void {
        $reasonInsert->execute([$dayOneId, 2]);
    }, 'reason lines stay frozen after a former official day is demoted');

    // Corrections can only start from the current official day and final decisions freeze.
    $adjustmentInsert = $db->prepare(
        "INSERT INTO staff_attendance_adjustments
            (staff_user_id, work_date, requester_id, requester_kind, reason,
             before_version_id, proposed_values, status, idempotency_key)
         VALUES (3001, '2026-10-01', 3001, 'self', ?, ?, ?, 'draft', ?)"
    );
    $expectRejected(static function () use ($adjustmentInsert, $dayOneId): void {
        $adjustmentInsert->execute([
            'Stale correction branch', $dayOneId,
            json_encode(['first_in' => '08:00'], JSON_THROW_ON_ERROR),
            'attendance-adjustment-stale',
        ]);
    }, 'an adjustment cannot branch from a superseded former official day');

    $adjustmentInsert->execute([
        'Correct an approved day', $dayTwoId,
        json_encode(['first_in' => '07:29'], JSON_THROW_ON_ERROR),
        'attendance-adjustment-current',
    ]);
    $adjustmentId = (int) $db->lastInsertId();
    $submitAdjustment = $db->prepare(
        "UPDATE staff_attendance_adjustments
         SET status = 'pending', submitted_at = '2026-10-03 08:00:00.000000', lock_version = 2
         WHERE id = ?"
    );
    $submitAdjustment->execute([$adjustmentId]);
    $rejectAdjustment = $db->prepare(
        "UPDATE staff_attendance_adjustments
         SET status = 'rejected', resolution_comment = 'Insufficient evidence', lock_version = 3
         WHERE id = ?"
    );
    $rejectAdjustment->execute([$adjustmentId]);
    $assert($rejectAdjustment->rowCount() === 1, 'pending adjustment can reach a final decision');
    $expectRejected(static function () use ($db, $adjustmentId): void {
        $statement = $db->prepare(
            "UPDATE staff_attendance_adjustments SET resolution_comment = 'Rewritten' WHERE id = ?"
        );
        $statement->execute([$adjustmentId]);
    }, 'final adjustment decision is immutable');
} catch (Throwable $exception) {
    $recordFailure('attendance migration/invariant exercise failed: ' . $exception->getMessage());
} finally {
    if ($db instanceof PDO) {
        foreach ($attendanceTriggers as $trigger) {
            try {
                $db->exec('DROP TRIGGER IF EXISTS ' . $quoteIdentifier($trigger));
            } catch (Throwable $exception) {
                $recordFailure("rollback could not drop owned trigger {$trigger}: " . $exception->getMessage());
            }
        }
        foreach ($rollbackTables as $table) {
            try {
                $db->exec('DROP TABLE IF EXISTS ' . $quoteIdentifier($table));
            } catch (Throwable $exception) {
                $recordFailure("rollback could not drop owned table {$table}: " . $exception->getMessage());
            }
        }

        try {
            $remainingTables = array_map(
                'strval',
                $db->query(
                    'SELECT TABLE_NAME FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() ORDER BY TABLE_NAME'
                )->fetchAll(PDO::FETCH_COLUMN)
            );
            $remainingTriggers = array_map(
                'strval',
                $db->query(
                    'SELECT TRIGGER_NAME FROM information_schema.TRIGGERS WHERE TRIGGER_SCHEMA = DATABASE() ORDER BY TRIGGER_NAME'
                )->fetchAll(PDO::FETCH_COLUMN)
            );
            $assert($remainingTables === [], 'rollback removes exactly all migration-owned tables');
            $assert($remainingTriggers === [], 'rollback removes exactly all migration-owned triggers');
        } catch (Throwable $exception) {
            $recordFailure('rollback object verification failed: ' . $exception->getMessage());
        }
    }

    if ($databaseCreated && $admin instanceof PDO) {
        try {
            $db = null;
            $admin->exec('DROP DATABASE ' . $quoteIdentifier($databaseName));
            $databaseDropped = true;
            $exists = $admin->prepare(
                'SELECT COUNT(*) FROM information_schema.SCHEMATA WHERE SCHEMA_NAME = ?'
            );
            $exists->execute([$databaseName]);
            $assert((int) $exists->fetchColumn() === 0, 'empty temporary test database is deleted');
        } catch (Throwable $exception) {
            $recordFailure("temporary database cleanup failed: {$exception->getMessage()}");
        }
    }
}

if ($databaseCreated && !$databaseDropped) {
    fwrite(STDERR, "FAIL: temporary database {$databaseName} still exists and requires manual cleanup.\n");
    ++$failures;
}

if ($failures > 0) {
    fwrite(STDERR, "{$failures} staff-HR attendance schema integration failure(s).\n");
    exit(1);
}

echo "Staff-HR attendance migration apply/idempotency/invariants/rollback passed on {$databaseName}; temporary database removed.\n";
