<?php

declare(strict_types=1);

/**
 * Guarded MariaDB proof for the Staff permission policy and quota ledger.
 *
 * It applies only the owned migration to an explicitly named new *_test
 * database, exercises the invariants that preserve request/quota history,
 * removes only the owned objects, and drops that temporary database.
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
$_ENV['EDUCORE_TEST_DB_NAME'] = $databaseName;
$_ENV['STAFF_HR_TEST_MARKER'] = $marker;
$_SERVER['APP_ENV'] = 'test';
$_SERVER['DB_NAME'] = $databaseName;
$_SERVER['EDUCORE_TEST_DB_NAME'] = $databaseName;
$_SERVER['STAFF_HR_TEST_MARKER'] = $marker;

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

$permissionTables = [
    'staff_permission_types',
    'staff_permission_policy_versions',
    'staff_permission_policy_scopes',
    'staff_permission_requests',
    'staff_permission_request_periods',
    'staff_permission_quota_accounts',
    'staff_permission_quota_movements',
];
$permissionTriggers = [
    'trg_staff_permission_type_guard_update',
    'trg_staff_permission_type_no_delete',
    'trg_staff_permission_policy_version_immutable_update',
    'trg_staff_permission_policy_version_immutable_delete',
    'trg_staff_permission_policy_scope_immutable_insert',
    'trg_staff_permission_policy_scope_immutable_update',
    'trg_staff_permission_policy_scope_immutable_delete',
    'trg_staff_permission_request_guard_insert',
    'trg_staff_permission_request_guard_update',
    'trg_staff_permission_request_no_delete',
    'trg_staff_permission_request_period_guard_insert',
    'trg_staff_permission_request_period_guard_update',
    'trg_staff_permission_request_period_guard_delete',
    'trg_staff_permission_quota_account_guard_update',
    'trg_staff_permission_quota_movement_guard_insert',
    'trg_staff_permission_quota_movement_no_update',
    'trg_staff_permission_quota_movement_no_delete',
];
$rollbackTables = [
    'staff_permission_quota_movements',
    'staff_permission_quota_accounts',
    'staff_permission_request_periods',
    'staff_permission_requests',
    'staff_permission_policy_scopes',
    'staff_permission_policy_versions',
    'staff_permission_types',
];

$quoteIdentifier = static function (string $identifier): string {
    if (!preg_match('/^[A-Za-z0-9_]+$/', $identifier)) {
        throw new InvalidArgumentException('Unsafe database identifier.');
    }

    return chr(96) . $identifier . chr(96);
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
                $message . ' (expected SQLSTATE ' . implode(' or ', $expectedSqlStates)
                . '; actual ' . $sqlState . ')'
            );
        }
    };

    $assert($schemaObjects('table') === [], 'new permission test database starts with no tables');
    $assert($schemaObjects('trigger') === [], 'new permission test database starts with no triggers');
    $assert(count($permissionTables) === 7, 'permission migration owns seven tables');
    $assert(count($permissionTriggers) === 17, 'permission migration owns seventeen triggers');

    $migration = require dirname(__DIR__)
        . '/database/migrations/20260730_staff_hr_permissions_quota.php';
    $assert(is_callable($migration), 'permission migration returns a callable');
    $migration($db);
    $migration($db);

    $expectedTables = $permissionTables;
    $expectedTriggers = $permissionTriggers;
    sort($expectedTables);
    sort($expectedTriggers);
    $actualTables = $schemaObjects('table');
    $actualTriggers = $schemaObjects('trigger');
    sort($actualTables);
    sort($actualTriggers);
    $assert(
        $actualTables === $expectedTables,
        'two applies create exactly the seven permission tables: actual=' . implode(',', $actualTables)
    );
    $assert(
        $actualTriggers === $expectedTriggers,
        'two applies create exactly the seventeen permission triggers: actual=' . implode(',', $actualTriggers)
    );

    $typeInsert = $db->prepare(
        "INSERT INTO staff_permission_types
            (code, name, coverage_behavior, requires_reason, requires_custom_label,
             requires_attachment, allow_retroactive, status, created_by)
         VALUES ('LATE_ARRIVAL', 'Late-arrival permission', 'late_arrival', 1, 0, 0, 1, 'active', 990001)"
    );
    $typeInsert->execute();
    $typeId = (int) $db->lastInsertId();

    $expectRejected(static function () use ($db, $typeId): void {
        $statement = $db->prepare(
            "INSERT INTO staff_permission_policy_versions
                (permission_type_id, version_no, state, valid_from, max_requests_per_month,
                 reserve_on_submit, created_by)
             VALUES (?, 99, 'draft', '2027-01-01 00:00:00.000000', 1, 0, 990001)"
        );
        $statement->execute([$typeId]);
    }, 'a configured monthly quota cannot bypass reservation on submission', ['23000']);

    $policyInsert = $db->prepare(
        "INSERT INTO staff_permission_policy_versions
            (permission_type_id, version_no, state, valid_from, timezone,
             max_requests_per_month, max_minutes_per_request, max_minutes_per_month,
             min_notice_minutes, retroactive_limit_days, reserve_on_submit,
             allow_overlap, allow_quota_override, quota_override_max_minutes, created_by)
         VALUES (?, ?, 'draft', ?, 'Africa/Cairo', 3, 120, 240, 30, 2, 1, 0, 0, NULL, 990001)"
    );
    $policyInsert->execute([$typeId, 1, '2026-10-01 00:00:00.000000']);
    $firstPolicyId = (int) $db->lastInsertId();
    $scopeInsert = $db->prepare(
        "INSERT INTO staff_permission_policy_scopes
            (policy_version_id, scope_type, scope_id, priority, valid_from, status, created_by)
         VALUES (?, 'global', 0, 0, ?, 'active', 990001)"
    );
    $scopeInsert->execute([$firstPolicyId, '2026-10-01 00:00:00.000000']);
    $firstScopeId = (int) $db->lastInsertId();

    $publishPolicy = $db->prepare(
        "UPDATE staff_permission_policy_versions
         SET state = 'published', published_by = 990002, published_at = ?
         WHERE id = ?"
    );
    $publishPolicy->execute(['2026-09-25 09:00:00.000000', $firstPolicyId]);
    $assert($publishPolicy->rowCount() === 1, 'a complete draft policy can be published');

    $expectRejected(static function () use ($db, $firstPolicyId): void {
        $statement = $db->prepare(
            'UPDATE staff_permission_policy_versions SET max_minutes_per_request = 60 WHERE id = ?'
        );
        $statement->execute([$firstPolicyId]);
    }, 'published policy rules cannot be edited');
    $retirePolicy = $db->prepare(
        "UPDATE staff_permission_policy_versions SET state = 'retired' WHERE id = ?"
    );
    $retirePolicy->execute([$firstPolicyId]);
    $assert($retirePolicy->rowCount() === 1, 'a published policy can be retired without rewriting its rules');
    $expectRejected(static function () use ($db, $firstScopeId): void {
        $statement = $db->prepare(
            'DELETE FROM staff_permission_policy_scopes WHERE id = ?'
        );
        $statement->execute([$firstScopeId]);
    }, 'policy scopes remain historical after policy retirement');

    $policyInsert->execute([$typeId, 2, '2026-11-01 00:00:00.000000']);
    $secondPolicyId = (int) $db->lastInsertId();
    $scopeInsert->execute([$secondPolicyId, '2026-11-01 00:00:00.000000']);
    $publishPolicy->execute(['2026-10-25 09:00:00.000000', $secondPolicyId]);
    $assert($publishPolicy->rowCount() === 1, 'a successor policy can become effective');

    $renameType = $db->prepare('UPDATE staff_permission_types SET name = ? WHERE id = ?');
    $renameType->execute(['Late-arrival permission (display)', $typeId]);
    $assert($renameType->rowCount() === 1, 'permission type display name remains editable');
    $expectRejected(static function () use ($db, $typeId): void {
        $statement = $db->prepare(
            "UPDATE staff_permission_types SET coverage_behavior = 'mission' WHERE id = ?"
        );
        $statement->execute([$typeId]);
    }, 'used permission type semantics are immutable');

    $directSubmittedInsert = $db->prepare(
        "INSERT INTO staff_permission_requests
            (staff_user_id, permission_type_id, from_at, to_at, requested_minutes, status,
             policy_version_id, policy_snapshot, workflow_version_id, assignment_id,
             submitted_by, submitted_at, create_idempotency_key, submission_idempotency_key, request_hash)
         VALUES (4101, ?, '2026-10-31 23:00:00.000000', '2026-11-01 01:00:00.000000', 120,
                 'pending_approval', ?, ?, 8001, 7001, 4101, '2026-10-30 10:00:00.000000',
                 'permission-direct-submit', 'permission-direct-submit-once', ?)"
    );
    $expectRejected(static function () use ($directSubmittedInsert, $typeId, $secondPolicyId): void {
        $directSubmittedInsert->execute([
            $typeId,
            $secondPolicyId,
            json_encode(['version' => 2], JSON_THROW_ON_ERROR),
            str_repeat('d', 64),
        ]);
    }, 'permission requests cannot bypass the draft state');

    $requestInsert = $db->prepare(
        "INSERT INTO staff_permission_requests
            (staff_user_id, permission_type_id, from_at, to_at, requested_minutes,
             create_idempotency_key, request_hash)
         VALUES (4101, ?, '2026-10-31 23:00:00.000000', '2026-11-01 01:00:00.000000',
                 120, 'permission-request-main', ?)"
    );
    $requestInsert->execute([$typeId, str_repeat('a', 64)]);
    $requestId = (int) $db->lastInsertId();

    $submitRequest = $db->prepare(
        "UPDATE staff_permission_requests
         SET status = 'pending_approval', policy_version_id = ?, policy_snapshot = ?,
             workflow_version_id = 8001, assignment_id = 7001, submitted_by = 4101,
             submitted_at = '2026-10-30 10:00:00.000000',
             submission_idempotency_key = 'permission-main-submit', lock_version = 2
         WHERE id = ?"
    );
    $expectRejected(static function () use ($submitRequest, $secondPolicyId, $requestId): void {
        $submitRequest->execute([
            $secondPolicyId,
            json_encode(['version' => 2, 'monthly_minutes' => 240], JSON_THROW_ON_ERROR),
            $requestId,
        ]);
    }, 'submitting without monthly allocations is rejected');

    $periodInsert = $db->prepare(
        "INSERT INTO staff_permission_request_periods
            (request_id, period_key, period_from_at, period_to_at, requested_minutes)
         VALUES (?, ?, ?, ?, ?)"
    );
    $expectRejected(static function () use ($periodInsert, $requestId): void {
        $periodInsert->execute([
            $requestId, '2026-10', '2026-10-31 23:00:00.000000',
            '2026-11-01 00:30:00.000000', 90,
        ]);
    }, 'a quota allocation cannot cross a monthly boundary');
    $periodInsert->execute([
        $requestId, '2026-10', '2026-10-31 23:00:00.000000',
        '2026-11-01 00:00:00.000000', 60,
    ]);
    $octoberPeriodId = (int) $db->lastInsertId();
    $periodInsert->execute([
        $requestId, '2026-11', '2026-11-01 00:00:00.000000',
        '2026-11-01 01:00:00.000000', 60,
    ]);
    $novemberPeriodId = (int) $db->lastInsertId();

    $submitRequest->execute([
        $secondPolicyId,
        json_encode(['version' => 2, 'monthly_minutes' => 240], JSON_THROW_ON_ERROR),
        $requestId,
    ]);
    $assert($submitRequest->rowCount() === 1, 'complete cross-month allocations permit a pending request');

    $expectRejected(static function () use ($db, $octoberPeriodId): void {
        $statement = $db->prepare(
            'UPDATE staff_permission_request_periods SET requested_minutes = 59 WHERE id = ?'
        );
        $statement->execute([$octoberPeriodId]);
    }, 'submitted request allocations are immutable');
    $expectRejected(static function () use ($db, $novemberPeriodId): void {
        $statement = $db->prepare('DELETE FROM staff_permission_request_periods WHERE id = ?');
        $statement->execute([$novemberPeriodId]);
    }, 'submitted request allocations cannot be deleted');
    $expectRejected(static function () use ($db, $requestId): void {
        $statement = $db->prepare(
            "UPDATE staff_permission_requests SET reason = 'rewritten' WHERE id = ?"
        );
        $statement->execute([$requestId]);
    }, 'submitted request details are immutable');

    $recordQuotaException = $db->prepare(
        "UPDATE staff_permission_requests
         SET quota_exception = 1,
             quota_exception_reason = 'authorized emergency allowance',
             lock_version = 3
         WHERE id = ?"
    );
    $recordQuotaException->execute([$requestId]);
    $assert($recordQuotaException->rowCount() === 1, 'actual quota exception may be recorded once after a pending reservation');
    $expectRejected(static function () use ($db, $requestId): void {
        $statement = $db->prepare(
            "UPDATE staff_permission_requests
             SET quota_exception_reason = 'rewritten exception reason', lock_version = 4
             WHERE id = ?"
        );
        $statement->execute([$requestId]);
    }, 'quota exception evidence cannot be rewritten after it is recorded');

    $attachWorkflowEvidence = $db->prepare(
        'UPDATE staff_permission_requests SET workflow_instance_id = 9001, lock_version = 4 WHERE id = ?'
    );
    $attachWorkflowEvidence->execute([$requestId]);
    $assert($attachWorkflowEvidence->rowCount() === 1, 'workflow evidence may be attached exactly once after submission');
    $expectRejected(static function () use ($db, $requestId): void {
        $statement = $db->prepare(
            'UPDATE staff_permission_requests SET workflow_instance_id = 9002 WHERE id = ?'
        );
        $statement->execute([$requestId]);
    }, 'workflow evidence cannot be replaced after it is attached');

    $approveRequest = $db->prepare(
        "UPDATE staff_permission_requests
         SET status = 'approved', decided_at = '2026-10-30 11:00:00.000000', lock_version = 5
         WHERE id = ?"
    );
    $approveRequest->execute([$requestId]);
    $assert($approveRequest->rowCount() === 1, 'a pending permission request can receive its decision');
    $expectRejected(static function () use ($db, $requestId): void {
        $statement = $db->prepare(
            "UPDATE staff_permission_requests SET status = 'pending_approval' WHERE id = ?"
        );
        $statement->execute([$requestId]);
    }, 'final permission request cannot be reopened');

    $draftWithdrawal = $db->prepare(
        "INSERT INTO staff_permission_requests
            (staff_user_id, permission_type_id, from_at, to_at, requested_minutes,
             create_idempotency_key, request_hash)
         VALUES (4101, ?, '2026-10-20 08:00:00.000000', '2026-10-20 09:00:00.000000',
                 60, 'permission-request-withdrawn-draft', ?)"
    );
    $draftWithdrawal->execute([$typeId, str_repeat('b', 64)]);
    $withdrawnDraftId = (int) $db->lastInsertId();
    $periodInsert->execute([
        $withdrawnDraftId, '2026-10', '2026-10-20 08:00:00.000000',
        '2026-10-20 09:00:00.000000', 60,
    ]);
    $withdrawnDraftPeriodId = (int) $db->lastInsertId();
    $withdrawDraft = $db->prepare(
        "UPDATE staff_permission_requests SET status = 'withdrawn', lock_version = 2 WHERE id = ?"
    );
    $withdrawDraft->execute([$withdrawnDraftId]);
    $assert($withdrawDraft->rowCount() === 1, 'an unsubmitted draft can be retained as withdrawn without a fabricated snapshot');

    $accountInsert = $db->prepare(
        "INSERT INTO staff_permission_quota_accounts
            (staff_user_id, permission_type_id, period_key)
         VALUES (4101, ?, ?)"
    );
    $expectRejected(static function () use ($accountInsert, $typeId): void {
        $accountInsert->execute([$typeId, '2026-13']);
    }, 'invalid quota account period key is rejected', []);
    $accountInsert->execute([$typeId, '2026-10']);
    $octoberAccountId = (int) $db->lastInsertId();
    $accountInsert->execute([$typeId, '2026-11']);
    $novemberAccountId = (int) $db->lastInsertId();
    $expectRejected(static function () use ($db, $octoberAccountId): void {
        $statement = $db->prepare(
            "UPDATE staff_permission_quota_accounts SET period_key = '2026-12' WHERE id = ?"
        );
        $statement->execute([$octoberAccountId]);
    }, 'quota account identity is immutable');
    $expectRejected(static function () use ($db, $octoberAccountId): void {
        $statement = $db->prepare(
            'UPDATE staff_permission_quota_accounts SET reserved_minutes = -1 WHERE id = ?'
        );
        $statement->execute([$octoberAccountId]);
    }, 'quota counter cache cannot become negative', []);

    $draftRequestInsert = $db->prepare(
        "INSERT INTO staff_permission_requests
            (staff_user_id, permission_type_id, from_at, to_at, requested_minutes,
             create_idempotency_key, request_hash)
         VALUES (4101, ?, '2026-10-20 08:00:00.000000', '2026-10-20 09:00:00.000000',
                 60, 'permission-request-ledger-draft', ?)"
    );
    $draftRequestInsert->execute([$typeId, str_repeat('c', 64)]);
    $draftRequestId = (int) $db->lastInsertId();
    $periodInsert->execute([
        $draftRequestId, '2026-10', '2026-10-20 08:00:00.000000',
        '2026-10-20 09:00:00.000000', 60,
    ]);
    $draftPeriodId = (int) $db->lastInsertId();

    $movementInsert = $db->prepare(
        "INSERT INTO staff_permission_quota_movements
            (account_id, request_id, request_period_id, movement_type, count_delta,
             minutes_delta, idempotency_key, movement_hash, created_by)
         VALUES (?, ?, ?, ?, ?, ?, ?, ?, 990010)"
    );
    $expectRejected(static function () use (
        $movementInsert,
        $octoberAccountId,
        $draftRequestId,
        $draftPeriodId
    ): void {
        $movementInsert->execute([
            $octoberAccountId, $draftRequestId, $draftPeriodId, 'reserve', 1, 60,
            'permission-draft-reserve', str_repeat('e', 64),
        ]);
    }, 'draft requests cannot change quota ledgers');
    $expectRejected(static function () use (
        $movementInsert,
        $octoberAccountId,
        $withdrawnDraftId,
        $withdrawnDraftPeriodId
    ): void {
        $movementInsert->execute([
            $octoberAccountId, $withdrawnDraftId, $withdrawnDraftPeriodId, 'reserve', 1, 60,
            'permission-withdrawn-reserve', str_repeat('d', 64),
        ]);
    }, 'withdrawn requests cannot change quota ledgers');
    $expectRejected(static function () use (
        $movementInsert,
        $novemberAccountId,
        $requestId,
        $octoberPeriodId
    ): void {
        $movementInsert->execute([
            $novemberAccountId, $requestId, $octoberPeriodId, 'reserve', 1, 60,
            'permission-wrong-account', str_repeat('f', 64),
        ]);
    }, 'quota movement account must match the allocated month');

    $movementInsert->execute([
        $octoberAccountId, $requestId, $octoberPeriodId, 'reserve', 1, 60,
        'permission-october-reserve', str_repeat('1', 64),
    ]);
    $movementId = (int) $db->lastInsertId();
    $expectRejected(static function () use (
        $movementInsert,
        $octoberAccountId,
        $requestId,
        $octoberPeriodId
    ): void {
        $movementInsert->execute([
            $octoberAccountId, $requestId, $octoberPeriodId, 'reserve', 1, 60,
            'permission-october-reserve', str_repeat('2', 64),
        ]);
    }, 'quota movement idempotency prevents duplicate reservation', ['23000']);
    $expectRejected(static function () use ($db, $movementId): void {
        $statement = $db->prepare(
            'UPDATE staff_permission_quota_movements SET minutes_delta = 61 WHERE id = ?'
        );
        $statement->execute([$movementId]);
    }, 'quota movements are append-only');
    $expectRejected(static function () use ($db, $movementId): void {
        $statement = $db->prepare('DELETE FROM staff_permission_quota_movements WHERE id = ?');
        $statement->execute([$movementId]);
    }, 'quota movements cannot be deleted');

    $closeAccount = $db->prepare(
        "UPDATE staff_permission_quota_accounts SET status = 'closed', lock_version = 2 WHERE id = ?"
    );
    $closeAccount->execute([$octoberAccountId]);
    $assert($closeAccount->rowCount() === 1, 'an account can be closed by its ledger owner');
    $expectRejected(static function () use (
        $movementInsert,
        $octoberAccountId,
        $requestId,
        $octoberPeriodId
    ): void {
        $movementInsert->execute([
            $octoberAccountId, $requestId, $octoberPeriodId, 'consume', 1, 60,
            'permission-closed-consume', str_repeat('3', 64),
        ]);
    }, 'closed quota accounts cannot accept new movements');
    $expectRejected(static function () use ($db, $octoberAccountId): void {
        $statement = $db->prepare(
            "UPDATE staff_permission_quota_accounts SET status = 'open' WHERE id = ?"
        );
        $statement->execute([$octoberAccountId]);
    }, 'closed quota accounts cannot be reopened');

    $expectRejected(static function () use ($db, $requestId): void {
        $statement = $db->prepare('DELETE FROM staff_permission_requests WHERE id = ?');
        $statement->execute([$requestId]);
    }, 'permission request history cannot be deleted');
    $expectRejected(static function () use ($db, $typeId): void {
        $statement = $db->prepare('DELETE FROM staff_permission_types WHERE id = ?');
        $statement->execute([$typeId]);
    }, 'used permission type cannot be deleted');
} catch (Throwable $exception) {
    $recordFailure('permission migration/invariant exercise failed: ' . $exception->getMessage());
} finally {
    if ($db instanceof PDO) {
        foreach ($permissionTriggers as $trigger) {
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
            $assert($remainingTables === [], 'rollback removes exactly all owned permission tables');
            $assert($remainingTriggers === [], 'rollback removes exactly all owned permission triggers');
        } catch (Throwable $exception) {
            $recordFailure('permission rollback object verification failed: ' . $exception->getMessage());
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
            $assert((int) $exists->fetchColumn() === 0, 'empty temporary permission test database is deleted');
        } catch (Throwable $exception) {
            $recordFailure("temporary permission database cleanup failed: {$exception->getMessage()}");
        }
    }
}

if ($databaseCreated && !$databaseDropped) {
    fwrite(STDERR, "FAIL: temporary database {$databaseName} still exists and requires manual cleanup.\n");
    ++$failures;
}

if ($failures > 0) {
    fwrite(STDERR, "{$failures} staff-HR permission schema integration failure(s).\n");
    exit(1);
}

echo "Staff-HR permission migration apply/idempotency/invariants/rollback passed on {$databaseName}; temporary database removed.\n";
