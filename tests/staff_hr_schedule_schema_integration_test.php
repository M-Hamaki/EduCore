<?php

declare(strict_types=1);

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
    fwrite(STDERR, "FAIL: EDUCORE_TEST_DB_NAME/--database must identify an isolated *_test database.\n");
    exit(2);
}

putenv('APP_ENV=test');
putenv('EDUCORE_TEST_DB_NAME=' . $databaseName);
$_ENV['APP_ENV'] = 'test';
$_ENV['STAFF_HR_TEST_MARKER'] = $marker;
$_ENV['EDUCORE_TEST_DB_NAME'] = $databaseName;
$_SERVER['APP_ENV'] = 'test';
$_SERVER['STAFF_HR_TEST_MARKER'] = $marker;
$_SERVER['EDUCORE_TEST_DB_NAME'] = $databaseName;

require_once __DIR__ . '/bootstrap_staff_hr.php';
require_once dirname(__DIR__) . '/vendor/autoload.php';

try {
    $db = staffHrTestDatabase();
    $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
} catch (Throwable $e) {
    fwrite(STDERR, 'FAIL: isolated staff-HR database guard rejected the connection: ' . $e->getMessage() . "\n");
    exit(2);
}

$scheduleTables = [
    'staff_schedule_policies',
    'staff_schedule_policy_versions',
    'staff_schedule_days',
    'staff_schedule_segments',
    'staff_schedule_scopes',
    'staff_calendar_exceptions',
    'staff_schedule_change_requests',
    'staff_schedule_command_receipts',
    'staff_schedule_participant_locks',
];
$foundationTables = [
    'staff_approval_workflows',
    'staff_approval_workflow_versions',
    'staff_approval_stages',
    'staff_approval_instances',
    'staff_approval_steps',
    'staff_approval_assignees',
    'staff_approval_decisions',
    'staff_approval_escalation_events',
    'user_notification_inbox',
    'notification_outbox',
    'staff_external_effects',
    'staff_hr_cutover_windows',
    'staff_hr_migration_batches',
    'staff_hr_migration_exceptions',
];
$ownedTables = array_merge($scheduleTables, $foundationTables);
$scheduleTriggers = [
    'trg_staff_schedule_versions_immutable_update',
    'trg_staff_schedule_versions_immutable_delete',
    'trg_staff_schedule_days_immutable_insert',
    'trg_staff_schedule_days_immutable_update',
    'trg_staff_schedule_days_immutable_delete',
    'trg_staff_schedule_segments_immutable_insert',
    'trg_staff_schedule_segments_immutable_update',
    'trg_staff_schedule_segments_immutable_delete',
    'trg_staff_schedule_scopes_immutable_insert',
    'trg_staff_schedule_scopes_immutable_update',
    'trg_staff_schedule_scopes_immutable_delete',
    'trg_staff_calendar_exception_immutable_update',
    'trg_staff_calendar_exception_supersession_guard',
    'trg_staff_calendar_exception_immutable_delete',
];

$tableExists = static function (string $table) use ($db): bool {
    $statement = $db->prepare(
        'SELECT COUNT(*) FROM information_schema.TABLES
         WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?'
    );
    $statement->execute([$table]);

    return (int) $statement->fetchColumn() === 1;
};
$triggerExists = static function (string $trigger) use ($db): bool {
    $statement = $db->prepare(
        'SELECT COUNT(*) FROM information_schema.TRIGGERS
         WHERE TRIGGER_SCHEMA = DATABASE() AND TRIGGER_NAME = ?'
    );
    $statement->execute([$trigger]);

    return (int) $statement->fetchColumn() === 1;
};
$schemaObjects = static function (string $kind) use ($db): array {
    if ($kind === 'table') {
        $statement = $db->query(
            'SELECT TABLE_NAME FROM information_schema.TABLES
             WHERE TABLE_SCHEMA = DATABASE() ORDER BY TABLE_NAME'
        );
    } else {
        $statement = $db->query(
            'SELECT TRIGGER_NAME FROM information_schema.TRIGGERS
             WHERE TRIGGER_SCHEMA = DATABASE() ORDER BY TRIGGER_NAME'
        );
    }

    return array_map('strval', $statement->fetchAll(PDO::FETCH_COLUMN));
};

$preexistingTables = array_values(array_filter($ownedTables, $tableExists));
$preexistingTriggers = array_values(array_filter($scheduleTriggers, $triggerExists));
if ($preexistingTables !== [] || $preexistingTriggers !== []) {
    fwrite(
        STDERR,
        'FAIL: use a clean dedicated *_test schema; owned objects already exist: '
        . implode(', ', array_merge($preexistingTables, $preexistingTriggers))
        . "\n"
    );
    exit(2);
}

$baselineTables = $schemaObjects('table');
$baselineTriggers = $schemaObjects('trigger');
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
$expectRejected = static function (
    callable $operation,
    string $message,
    ?string $expectedSqlState = null
) use ($assert): void {
    $rejected = false;
    $sqlState = '';
    try {
        $operation();
    } catch (Throwable $e) {
        $rejected = true;
        if ($e instanceof PDOException && isset($e->errorInfo[0])) {
            $sqlState = (string) $e->errorInfo[0];
        } else {
            $sqlState = (string) $e->getCode();
        }
    }

    $assert($rejected, $message);
    if ($rejected && $expectedSqlState !== null) {
        $assert($sqlState === $expectedSqlState, "{$message} (SQLSTATE {$expectedSqlState})");
    }
};

$operationsMigration = require dirname(__DIR__)
    . '/database/migrations/20260730_staff_hr_workflow_operations_foundation.php';
$scheduleMigration = require dirname(__DIR__)
    . '/database/migrations/20260730_staff_hr_schedule_calendar.php';
$assert(is_callable($operationsMigration), 'workflow operations prerequisite migration returns a callable');
$assert(is_callable($scheduleMigration), 'schedule/calendar migration returns a callable');

$scheduleRollbackOrder = [
    'staff_schedule_command_receipts',
    'staff_schedule_participant_locks',
    'staff_schedule_change_requests',
    'staff_calendar_exceptions',
    'staff_schedule_segments',
    'staff_schedule_scopes',
    'staff_schedule_days',
    'staff_schedule_policy_versions',
    'staff_schedule_policies',
];
$foundationRollbackOrder = [
    'staff_approval_decisions',
    'staff_approval_escalation_events',
    'staff_approval_assignees',
    'staff_approval_steps',
    'staff_approval_instances',
    'staff_approval_stages',
    'staff_approval_workflow_versions',
    'staff_approval_workflows',
    'notification_outbox',
    'user_notification_inbox',
    'staff_external_effects',
    'staff_hr_migration_exceptions',
    'staff_hr_migration_batches',
    'staff_hr_cutover_windows',
];

try {
    $operationsMigration($db);
    $scheduleMigration($db);

    foreach ($scheduleTables as $table) {
        $assert($tableExists($table), "first apply creates {$table}");
    }
    foreach ($scheduleTriggers as $trigger) {
        $assert($triggerExists($trigger), "first apply creates {$trigger}");
    }

    $scheduleMigration($db);
    $assert(
        count(array_filter($scheduleTables, $tableExists)) === count($scheduleTables),
        'second apply preserves exactly the schedule tables'
    );
    $assert(
        count(array_filter($scheduleTriggers, $triggerExists)) === count($scheduleTriggers),
        'second apply preserves exactly the immutability triggers'
    );

    $db->exec(
        "INSERT INTO staff_schedule_policies (code, name, created_by)
         VALUES ('TEST_SCHEDULE_IMMUTABLE', 'Schedule immutability fixture', 990001)"
    );
    $policyId = (int) $db->lastInsertId();

    $versionInsert = $db->prepare(
        "INSERT INTO staff_schedule_policy_versions
            (policy_id, version_no, state, valid_from, create_idempotency_key, create_payload_hash, created_by)
         VALUES (?, ?, 'draft', '2026-09-01 00:00:00', ?, ?, 990001)"
    );
    $versionInsert->execute([$policyId, 1, 'schedule-integration-version-1', str_repeat('a', 64)]);
    $publishedVersionId = (int) $db->lastInsertId();
    $versionInsert->execute([$policyId, 2, 'schedule-integration-version-2', str_repeat('b', 64)]);
    $draftVersionId = (int) $db->lastInsertId();

    $expectRejected(
        static function () use ($db, $draftVersionId): void {
            $statement = $db->prepare(
                "UPDATE staff_schedule_policy_versions SET state = 'published' WHERE id = ?"
            );
            $statement->execute([$draftVersionId]);
        },
        'publication without actor, timestamp, and idempotency key is rejected'
    );

    $dayInsert = $db->prepare(
        "INSERT INTO staff_schedule_days
            (policy_version_id, weekday, is_working_day, start_time, end_time, required_minutes)
         VALUES (?, 1, 1, '07:30:00', '14:30:00', 420)"
    );
    $dayInsert->execute([$publishedVersionId]);
    $publishedDayId = (int) $db->lastInsertId();
    $dayInsert->execute([$draftVersionId]);
    $draftDayId = (int) $db->lastInsertId();

    $segmentInsert = $db->prepare(
        "INSERT INTO staff_schedule_segments
            (schedule_day_id, sequence_no, segment_type, start_time, end_time, counts_required_minutes)
         VALUES (?, 1, 'work', '07:30:00', '14:30:00', 1)"
    );
    $segmentInsert->execute([$publishedDayId]);
    $publishedSegmentId = (int) $db->lastInsertId();
    $segmentInsert->execute([$draftDayId]);
    $draftSegmentId = (int) $db->lastInsertId();

    $scopeInsert = $db->prepare(
        "INSERT INTO staff_schedule_scopes
            (policy_version_id, scope_type, scope_id, priority, valid_from, created_by)
         VALUES (?, 'global', 0, ?, '2026-09-01 00:00:00', 990001)"
    );
    $scopeInsert->execute([$publishedVersionId, 100]);
    $publishedScopeId = (int) $db->lastInsertId();
    $scopeInsert->execute([$draftVersionId, 200]);
    $draftScopeId = (int) $db->lastInsertId();

    $publish = $db->prepare(
        "UPDATE staff_schedule_policy_versions
         SET state = 'published', published_by = 990002,
             published_at = '2026-08-02 12:00:00.000000',
             publication_key = 'schedule-integration-publish-1',
             publication_payload_hash = 'cccccccccccccccccccccccccccccccccccccccccccccccccccccccccccccccc'
         WHERE id = ?"
    );
    $publish->execute([$publishedVersionId]);
    $assert($publish->rowCount() === 1, 'a complete draft can transition to published exactly once');

    $expectRejected(
        static function () use ($db, $publishedVersionId): void {
            $statement = $db->prepare(
                "UPDATE staff_schedule_policy_versions SET timezone = 'UTC' WHERE id = ?"
            );
            $statement->execute([$publishedVersionId]);
        },
        'published version update is rejected by the immutable trigger',
        '45000'
    );
    $expectRejected(
        static function () use ($db, $publishedVersionId): void {
            $statement = $db->prepare('DELETE FROM staff_schedule_policy_versions WHERE id = ?');
            $statement->execute([$publishedVersionId]);
        },
        'published version delete is rejected by the immutable trigger',
        '45000'
    );

    $expectRejected(
        static function () use ($db, $publishedVersionId): void {
            $statement = $db->prepare(
                "INSERT INTO staff_schedule_days
                    (policy_version_id, weekday, is_working_day, start_time, end_time, required_minutes)
                 VALUES (?, 2, 1, '07:30:00', '14:30:00', 420)"
            );
            $statement->execute([$publishedVersionId]);
        },
        'a day cannot be inserted below a published version',
        '45000'
    );
    $expectRejected(
        static function () use ($db, $publishedDayId): void {
            $statement = $db->prepare('UPDATE staff_schedule_days SET required_minutes = 390 WHERE id = ?');
            $statement->execute([$publishedDayId]);
        },
        'published day update is rejected',
        '45000'
    );
    $expectRejected(
        static function () use ($db, $publishedDayId): void {
            $statement = $db->prepare('DELETE FROM staff_schedule_days WHERE id = ?');
            $statement->execute([$publishedDayId]);
        },
        'published day delete is rejected',
        '45000'
    );
    $expectRejected(
        static function () use ($db, $publishedDayId, $draftVersionId): void {
            $statement = $db->prepare('UPDATE staff_schedule_days SET policy_version_id = ? WHERE id = ?');
            $statement->execute([$draftVersionId, $publishedDayId]);
        },
        'day OLD published to NEW draft parent move cannot bypass immutability',
        '45000'
    );
    $expectRejected(
        static function () use ($db, $draftDayId, $publishedVersionId): void {
            $statement = $db->prepare('UPDATE staff_schedule_days SET policy_version_id = ? WHERE id = ?');
            $statement->execute([$publishedVersionId, $draftDayId]);
        },
        'day OLD draft to NEW published parent move cannot bypass immutability',
        '45000'
    );

    $expectRejected(
        static function () use ($db, $publishedDayId): void {
            $statement = $db->prepare(
                "INSERT INTO staff_schedule_segments
                    (schedule_day_id, sequence_no, segment_type, start_time, end_time)
                 VALUES (?, 2, 'paid_break', '10:00:00', '10:15:00')"
            );
            $statement->execute([$publishedDayId]);
        },
        'a segment cannot be inserted below a published version',
        '45000'
    );
    $expectRejected(
        static function () use ($db, $publishedSegmentId): void {
            $statement = $db->prepare("UPDATE staff_schedule_segments SET end_time = '14:00:00' WHERE id = ?");
            $statement->execute([$publishedSegmentId]);
        },
        'published segment update is rejected',
        '45000'
    );
    $expectRejected(
        static function () use ($db, $publishedSegmentId): void {
            $statement = $db->prepare('DELETE FROM staff_schedule_segments WHERE id = ?');
            $statement->execute([$publishedSegmentId]);
        },
        'published segment delete is rejected',
        '45000'
    );
    $expectRejected(
        static function () use ($db, $publishedSegmentId, $draftDayId): void {
            $statement = $db->prepare('UPDATE staff_schedule_segments SET schedule_day_id = ? WHERE id = ?');
            $statement->execute([$draftDayId, $publishedSegmentId]);
        },
        'segment OLD published to NEW draft day move cannot bypass immutability',
        '45000'
    );
    $expectRejected(
        static function () use ($db, $draftSegmentId, $publishedDayId): void {
            $statement = $db->prepare('UPDATE staff_schedule_segments SET schedule_day_id = ? WHERE id = ?');
            $statement->execute([$publishedDayId, $draftSegmentId]);
        },
        'segment OLD draft to NEW published day move cannot bypass immutability',
        '45000'
    );

    $expectRejected(
        static function () use ($db, $publishedVersionId): void {
            $statement = $db->prepare(
                "INSERT INTO staff_schedule_scopes
                    (policy_version_id, scope_type, scope_id, priority, valid_from, created_by)
                 VALUES (?, 'staff', 990010, 500, '2026-09-01 00:00:00', 990001)"
            );
            $statement->execute([$publishedVersionId]);
        },
        'a scope cannot be inserted below a published version',
        '45000'
    );
    $expectRejected(
        static function () use ($db, $publishedScopeId): void {
            $statement = $db->prepare('UPDATE staff_schedule_scopes SET priority = 101 WHERE id = ?');
            $statement->execute([$publishedScopeId]);
        },
        'published scope update is rejected',
        '45000'
    );
    $expectRejected(
        static function () use ($db, $publishedScopeId): void {
            $statement = $db->prepare('DELETE FROM staff_schedule_scopes WHERE id = ?');
            $statement->execute([$publishedScopeId]);
        },
        'published scope delete is rejected',
        '45000'
    );
    $expectRejected(
        static function () use ($db, $publishedScopeId, $draftVersionId): void {
            $statement = $db->prepare('UPDATE staff_schedule_scopes SET policy_version_id = ? WHERE id = ?');
            $statement->execute([$draftVersionId, $publishedScopeId]);
        },
        'scope OLD published to NEW draft parent move cannot bypass immutability',
        '45000'
    );
    $expectRejected(
        static function () use ($db, $draftScopeId, $publishedVersionId): void {
            $statement = $db->prepare('UPDATE staff_schedule_scopes SET policy_version_id = ? WHERE id = ?');
            $statement->execute([$publishedVersionId, $draftScopeId]);
        },
        'scope OLD draft to NEW published parent move cannot bypass immutability',
        '45000'
    );

    $draftDayUpdate = $db->prepare('UPDATE staff_schedule_days SET required_minutes = 400 WHERE id = ?');
    $draftDayUpdate->execute([$draftDayId]);
    $draftSegmentUpdate = $db->prepare(
        "UPDATE staff_schedule_segments SET end_time = '14:10:00' WHERE id = ?"
    );
    $draftSegmentUpdate->execute([$draftSegmentId]);
    $draftScopeUpdate = $db->prepare('UPDATE staff_schedule_scopes SET priority = 201 WHERE id = ?');
    $draftScopeUpdate->execute([$draftScopeId]);
    $assert(
        $draftDayUpdate->rowCount() === 1
        && $draftSegmentUpdate->rowCount() === 1
        && $draftScopeUpdate->rowCount() === 1,
        'draft day, segment, and scope remain editable'
    );

    // Historical Q02 regression: an open v1 stays immutable while its direct v2
    // successor becomes effective at the following half-open month boundary.
    $db->exec(
        "INSERT INTO staff_schedule_policies (code, name, created_by)
         VALUES ('TEST_SCHEDULE_HISTORY', 'Schedule history fixture', 990001)"
    );
    $historyPolicyId = (int) $db->lastInsertId();
    $historyVersionInsert = $db->prepare(
        "INSERT INTO staff_schedule_policy_versions
            (policy_id, version_no, state, valid_from, supersedes_id,
             create_idempotency_key, create_payload_hash, created_by)
         VALUES (?, ?, 'draft', ?, ?, ?, ?, 990001)"
    );
    $historyVersionInsert->execute([
        $historyPolicyId, 1, '2026-09-01 00:00:00.000000', null,
        'schedule-history-version-1', str_repeat('1', 64),
    ]);
    $historyV1 = (int) $db->lastInsertId();
    $dayInsert->execute([$historyV1]);
    $historyV1Day = (int) $db->lastInsertId();
    $segmentInsert->execute([$historyV1Day]);
    $historyScopeInsert = $db->prepare(
        "INSERT INTO staff_schedule_scopes
            (policy_version_id, scope_type, scope_id, priority, valid_from, created_by)
         VALUES (?, 'global', 0, 10, ?, 990001)"
    );
    $historyScopeInsert->execute([$historyV1, '2026-09-01 00:00:00.000000']);
    $historyPublish = $db->prepare(
        "UPDATE staff_schedule_policy_versions
         SET state = 'published', published_by = 990002, published_at = ?,
             publication_key = ?, publication_payload_hash = ?
         WHERE id = ?"
    );
    $historyPublish->execute([
        '2026-08-31 12:00:00.000000', 'schedule-history-publish-1', str_repeat('2', 64), $historyV1,
    ]);
    $historyVersionInsert->execute([
        $historyPolicyId, 2, '2026-10-01 00:00:00.000000', $historyV1,
        'schedule-history-version-2', str_repeat('3', 64),
    ]);
    $historyV2 = (int) $db->lastInsertId();
    $dayInsert->execute([$historyV2]);
    $historyV2Day = (int) $db->lastInsertId();
    $segmentInsert->execute([$historyV2Day]);
    $historyScopeInsert->execute([$historyV2, '2026-10-01 00:00:00.000000']);
    $historyPublish->execute([
        '2026-09-30 12:00:00.000000', 'schedule-history-publish-2', str_repeat('4', 64), $historyV2,
    ]);

    $historyRepository = new \EduCore\Modules\Attendance\Infrastructure\PdoSchedulePolicyRepository(
        $db,
        new \EduCore\Modules\Staff\Infrastructure\PdoStaffGroupOverlapQuery($db)
    );
    $assignmentSnapshot = ['org_unit_id' => null, 'job_title_id' => null, 'group_ids' => []];
    $septemberCandidates = $historyRepository->candidateVersionsFor(
        990100,
        $assignmentSnapshot,
        new DateTimeImmutable('2026-09-15 08:00:00', new DateTimeZone('Africa/Cairo'))
    );
    $octoberCandidates = $historyRepository->candidateVersionsFor(
        990100,
        $assignmentSnapshot,
        new DateTimeImmutable('2026-10-15 08:00:00', new DateTimeZone('Africa/Cairo'))
    );
    $septemberCandidateIds = array_map(static fn (array $candidate): int => (int) $candidate['version_id'], $septemberCandidates);
    $octoberCandidateIds = array_map(static fn (array $candidate): int => (int) $candidate['version_id'], $octoberCandidates);
    $assert(in_array($historyV1, $septemberCandidateIds, true) && !in_array($historyV2, $septemberCandidateIds, true), 'open v1 is a candidate before its successor boundary');
    $assert(in_array($historyV2, $octoberCandidateIds, true) && !in_array($historyV1, $octoberCandidateIds, true), 'v2 replaces v1 after its half-open successor boundary');
    $historyV1State = $db->prepare('SELECT state, valid_to FROM staff_schedule_policy_versions WHERE id = ?');
    $historyV1State->execute([$historyV1]);
    $historyV1Row = $historyV1State->fetch(PDO::FETCH_ASSOC);
    $assert(($historyV1Row['state'] ?? '') === 'published' && $historyV1Row['valid_to'] === null, 'publishing v2 never rewrites open-ended immutable v1 history');

    // Participant locks serialize the overlap decision even when no change row exists yet.
    $db->beginTransaction();
    $historyRepository->lockChangeParticipants([990101]);
    $secondDb = staffHrTestDatabase();
    $secondDb->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $secondDb->exec('SET SESSION innodb_lock_wait_timeout = 1');
    $secondRepository = new \EduCore\Modules\Attendance\Infrastructure\PdoSchedulePolicyRepository(
        $secondDb,
        new \EduCore\Modules\Staff\Infrastructure\PdoStaffGroupOverlapQuery($secondDb)
    );
    $secondDb->beginTransaction();
    $participantLockRejected = false;
    try {
        $secondRepository->lockChangeParticipants([990101]);
    } catch (PDOException $exception) {
        $participantLockRejected = str_contains(strtolower($exception->getMessage()), 'lock wait timeout')
            || (int) ($exception->errorInfo[1] ?? 0) === 1205;
    } finally {
        if ($secondDb->inTransaction()) {
            $secondDb->rollBack();
        }
        if ($db->inTransaction()) {
            $db->rollBack();
        }
    }
    $assert($participantLockRejected, 'a second connection cannot pass the same participant overlap gate concurrently');

    $invalidChangeInsert = $db->prepare(
        "INSERT INTO staff_schedule_change_requests
            (staff_user_id, change_type, from_at, to_at, counterpart_staff_id,
             counterpart_accepted_by, counterpart_accepted_at, requested_schedule_version_id,
             reason, status, idempotency_key, payload_hash, created_by)
         VALUES (?, ?, '2026-11-01 08:00:00', '2026-11-01 09:00:00', ?, ?, ?, ?, ?, ?, ?, ?, 990001)"
    );
    $expectRejected(static function () use ($invalidChangeInsert): void {
        $invalidChangeInsert->execute([
            990100, 'overtime', null, null, null, null, 'Invalid pending', 'pending_counterpart',
            'invalid-change-pending', str_repeat('5', 64),
        ]);
    }, 'pending_counterpart is restricted to shift swaps');
    $expectRejected(static function () use ($invalidChangeInsert): void {
        $invalidChangeInsert->execute([
            990100, 'overtime', null, 990200, '2026-10-01 08:00:00', null,
            'Invalid acceptance', 'submitted', 'invalid-change-acceptance', str_repeat('6', 64),
        ]);
    }, 'counterpart acceptance metadata is forbidden for non-swap changes');
    $expectRejected(static function () use ($invalidChangeInsert, $historyV1): void {
        $invalidChangeInsert->execute([
            990100, 'shift_swap', 990200, 990200, null, $historyV1,
            'Half acceptance', 'submitted', 'invalid-change-half-acceptance', str_repeat('7', 64),
        ]);
    }, 'counterpart acceptance actor and instant must be stored as one pair');

    $calendarInsert = $db->prepare(
        "INSERT INTO staff_calendar_exceptions
            (calendar_date, scope_type, scope_id, exception_type, reason, status, idempotency_key, payload_hash, created_by)
         VALUES ('2026-10-06', 'global', 0, 'holiday', 'Draft holiday', 'draft',
                 'schedule-integration-calendar-1',
                 'dddddddddddddddddddddddddddddddddddddddddddddddddddddddddddddddd', 990001)"
    );
    $calendarInsert->execute();
    $calendarId = (int) $db->lastInsertId();
    $calendarActivate = $db->prepare(
        "UPDATE staff_calendar_exceptions SET reason = 'Approved holiday', status = 'active' WHERE id = ?"
    );
    $calendarActivate->execute([$calendarId]);
    $assert($calendarActivate->rowCount() === 1, 'draft calendar exception can become active');
    $expectRejected(
        static function () use ($db): void {
            $statement = $db->prepare(
                "INSERT INTO staff_calendar_exceptions
                    (calendar_date, scope_type, scope_id, exception_type, reason, status, idempotency_key, payload_hash, created_by)
                 VALUES ('2026-10-06', 'global', 0, 'closure', 'Conflicting active closure', 'active', ?, ?, 990001)"
            );
            $statement->execute([
                'schedule-integration-calendar-conflicting-root', str_repeat('a', 64),
            ]);
        },
        'a second active calendar exception for the same date and scope must supersede the current row',
        '45000'
    );
    $draftConflict = $db->prepare(
        "INSERT INTO staff_calendar_exceptions
            (calendar_date, scope_type, scope_id, exception_type, reason, status, idempotency_key, payload_hash, created_by)
         VALUES ('2026-10-06', 'global', 0, 'closure', 'Unlinked replacement draft', 'draft', ?, ?, 990001)"
    );
    $draftConflict->execute([
        'schedule-integration-calendar-conflicting-draft', str_repeat('b', 64),
    ]);
    $draftConflictId = (int) $db->lastInsertId();
    $expectRejected(
        static function () use ($db, $draftConflictId): void {
            $statement = $db->prepare("UPDATE staff_calendar_exceptions SET status = 'active' WHERE id = ?");
            $statement->execute([$draftConflictId]);
        },
        'a draft cannot become active beside an existing calendar exception without an explicit predecessor',
        '45000'
    );
    $expectRejected(
        static function () use ($db, $calendarId): void {
            $statement = $db->prepare(
                "UPDATE staff_calendar_exceptions SET reason = 'Changed holiday' WHERE id = ?"
            );
            $statement->execute([$calendarId]);
        },
        'active calendar exception update is rejected',
        '45000'
    );
    $calendarRetire = $db->prepare(
        "INSERT INTO staff_calendar_exceptions
            (calendar_date, scope_type, scope_id, exception_type, reason, status,
             supersedes_id, idempotency_key, payload_hash, created_by)
         VALUES ('2026-10-06', 'global', 0, 'holiday', 'Retired holiday', 'retired', ?, ?, ?, 990001)"
    );
    $expectRejected(static function () use ($db, $calendarId): void {
        $statement = $db->prepare(
            "INSERT INTO staff_calendar_exceptions
                (calendar_date, scope_type, scope_id, exception_type, reason, status,
                 supersedes_id, idempotency_key, payload_hash, created_by)
             VALUES ('2026-10-07', 'global', 0, 'holiday', 'Wrong successor', 'active', ?, ?, ?, 990001)"
        );
        $statement->execute([
            $calendarId, 'schedule-integration-calendar-wrong-scope', str_repeat('9', 64),
        ]);
    }, 'calendar successor cannot move its predecessor to another date/scope', '45000');
    $calendarRetire->execute([
        $calendarId, 'schedule-integration-calendar-retire-1', str_repeat('8', 64),
    ]);
    $effectiveCalendar = $historyRepository->calendarExceptionsFor(
        990100,
        $assignmentSnapshot,
        new DateTimeImmutable('2026-10-06')
    );
    $assert($effectiveCalendar === [], 'retired immutable successor hides its active calendar predecessor');
    $expectRejected(
        static function () use ($db, $calendarId): void {
            $statement = $db->prepare('DELETE FROM staff_calendar_exceptions WHERE id = ?');
            $statement->execute([$calendarId]);
        },
        'active calendar exception delete is rejected',
        '45000'
    );
} catch (Throwable $e) {
    $recordFailure('schedule migration/invariant exercise failed: ' . $e->getMessage());
} finally {
    foreach ($scheduleTriggers as $trigger) {
        try {
            $db->exec('DROP TRIGGER IF EXISTS `' . $trigger . '`');
        } catch (Throwable $e) {
            $recordFailure("rollback could not drop owned trigger {$trigger}: " . $e->getMessage());
        }
    }
    foreach (array_merge($scheduleRollbackOrder, $foundationRollbackOrder) as $table) {
        try {
            $db->exec('DROP TABLE IF EXISTS `' . $table . '`');
        } catch (Throwable $e) {
            $recordFailure("rollback could not drop owned table {$table}: " . $e->getMessage());
        }
    }
}

foreach ($scheduleTriggers as $trigger) {
    $assert(!$triggerExists($trigger), "rollback removes owned trigger {$trigger}");
}
foreach ($ownedTables as $table) {
    $assert(!$tableExists($table), "rollback removes owned table {$table}");
}
$assert($schemaObjects('table') === $baselineTables, 'rollback preserves every pre-existing table');
$assert($schemaObjects('trigger') === $baselineTriggers, 'rollback preserves every pre-existing trigger');

if ($failures > 0) {
    fwrite(STDERR, "{$failures} staff-HR schedule schema integration failure(s).\n");
    exit(1);
}

echo "Staff-HR schedule migration apply/idempotency/immutability/rollback passed on {$databaseName}.\n";
