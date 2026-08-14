<?php

declare(strict_types=1);

/**
 * Concurrent reserve/consume/release proof for the locked permission ledger.
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
$quoteIdentifier = static function (string $identifier): string {
    if (!preg_match('/^[A-Za-z0-9_]+$/', $identifier)) {
        throw new InvalidArgumentException('Unsafe database identifier.');
    }

    return chr(96) . $identifier . chr(96);
};

$permissionTables = [
    'staff_permission_quota_movements',
    'staff_permission_quota_accounts',
    'staff_permission_request_periods',
    'staff_permission_requests',
    'staff_permission_policy_scopes',
    'staff_permission_policy_versions',
    'staff_permission_types',
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

    $migration = require dirname(__DIR__)
        . '/database/migrations/20260730_staff_hr_permissions_quota.php';
    $migration($db);

    $db->exec(
        "INSERT INTO staff_permission_types
            (code, name, coverage_behavior, requires_reason, requires_custom_label,
             requires_attachment, allow_retroactive, status, created_by)
         VALUES ('LATE_ARRIVAL', 'Late arrival', 'late_arrival', 1, 0, 0, 1, 'active', 990001)"
    );
    $typeId = (int) $db->lastInsertId();
    $policyInsert = $db->prepare(
        "INSERT INTO staff_permission_policy_versions
            (permission_type_id, version_no, state, valid_from, timezone,
             max_requests_per_month, max_minutes_per_request, max_minutes_per_month,
             min_notice_minutes, retroactive_limit_days, reserve_on_submit,
             allow_overlap, allow_quota_override, quota_override_max_minutes, created_by)
         VALUES (?, 1, 'draft', '2026-01-01 00:00:00.000000', 'Africa/Cairo',
                 8, 120, 480, 0, 0, 1, 0, 0, NULL, 990001)"
    );
    $policyInsert->execute([$typeId]);
    $policyId = (int) $db->lastInsertId();
    $scopeInsert = $db->prepare(
        "INSERT INTO staff_permission_policy_scopes
            (policy_version_id, scope_type, scope_id, priority, valid_from, status, created_by)
         VALUES (?, 'global', 0, 0, '2026-01-01 00:00:00.000000', 'active', 990001)"
    );
    $scopeInsert->execute([$policyId]);
    $publish = $db->prepare(
        "UPDATE staff_permission_policy_versions
         SET state = 'published', published_by = 990001, published_at = '2026-01-01 00:00:00.000000'
         WHERE id = ?"
    );
    $publish->execute([$policyId]);

    $requestInsert = $db->prepare(
        "INSERT INTO staff_permission_requests
            (staff_user_id, permission_type_id, from_at, to_at, requested_minutes,
             create_idempotency_key, request_hash)
         VALUES (1001, ?, '2026-10-15 08:00:00.000000', '2026-10-15 09:00:00.000000',
                 60, ?, ?)"
    );
    $periodInsert = $db->prepare(
        "INSERT INTO staff_permission_request_periods
            (request_id, period_key, period_from_at, period_to_at, requested_minutes)
         VALUES (?, '2026-10', '2026-10-15 08:00:00.000000', '2026-10-15 09:00:00.000000', 60)"
    );
    $submitRequest = $db->prepare(
        "UPDATE staff_permission_requests
         SET status = 'pending_approval', policy_version_id = ?,
             policy_snapshot = ?, workflow_version_id = 8001, assignment_id = 7001,
             submitted_by = 1001, submitted_at = '2026-10-14 08:00:00.000000',
             submission_idempotency_key = ?, lock_version = 2
         WHERE id = ?"
    );
    $createPendingRequest = static function (int $sequence) use (
        $requestInsert,
        $periodInsert,
        $submitRequest,
        $typeId,
        $policyId,
        $db
    ): array {
        $createKey = 'quota-concurrency-create-' . $sequence;
        $requestInsert->execute([$typeId, $createKey, hash('sha256', $createKey)]);
        $requestId = (int) $db->lastInsertId();
        $periodInsert->execute([$requestId]);
        $periodId = (int) $db->lastInsertId();
        $submissionKey = 'quota-concurrency-submit-' . $sequence;
        $submitRequest->execute([
            $policyId,
            json_encode(['version_id' => $policyId], JSON_THROW_ON_ERROR),
            $submissionKey,
            $requestId,
        ]);

        return ['request_id' => $requestId, 'period_id' => $periodId, 'sequence' => $sequence];
    };

    $workerPath = __DIR__ . '/support/permission_quota_ledger_worker.php';
    $workerEnvironment = getenv();
    if (!is_array($workerEnvironment)) {
        $workerEnvironment = [];
    }
    $workerEnvironment['STAFF_HR_TEST_MARKER'] = 'integrated-staff-hr';
    $runWorkers = static function (array $commands) use (
        $workerPath,
        $databaseName,
        $typeId,
        $workerEnvironment
    ): array {
        $processes = [];
        foreach ($commands as $command) {
            $args = [
                PHP_BINARY,
                $workerPath,
                '--database=' . $databaseName,
                '--permission-type-id=' . $typeId,
                '--idempotency=' . $command['idempotency'],
                '--request-id=' . $command['request_id'],
                '--request-period-id=' . $command['period_id'],
                '--movement-type=' . $command['movement_type'],
                '--count=' . $command['count'],
                '--minutes=' . $command['minutes'],
                '--period-key=2026-10',
                '--max-requests=' . $command['max_requests'],
                '--max-minutes=' . $command['max_minutes'],
            ];
            if (($command['fail_audit'] ?? false) === true) {
                $args[] = '--fail-audit';
            }
            $pipes = [];
            $process = proc_open(
                $args,
                [
                    0 => ['file', 'NUL', 'r'],
                    1 => ['pipe', 'w'],
                    2 => ['pipe', 'w'],
                ],
                $pipes,
                dirname(__DIR__),
                $workerEnvironment
            );
            if (!is_resource($process)) {
                throw new RuntimeException('PERMISSION_QUOTA_WORKER_START_FAILED');
            }
            $processes[] = ['process' => $process, 'pipes' => $pipes, 'command' => $command];
        }

        $results = [];
        foreach ($processes as $item) {
            $stdout = stream_get_contents($item['pipes'][1]);
            $stderr = stream_get_contents($item['pipes'][2]);
            fclose($item['pipes'][1]);
            fclose($item['pipes'][2]);
            $exitCode = proc_close($item['process']);
            $decoded = json_decode(trim($stdout), true);
            $results[] = [
                'command' => $item['command'],
                'exit_code' => $exitCode,
                'output' => $decoded,
                'stderr' => $stderr,
            ];
        }

        return $results;
    };
    $successful = static function (array $results): array {
        return array_values(array_filter(
            $results,
            static fn (array $result): bool => $result['exit_code'] === 0 && ($result['output']['ok'] ?? false) === true
        ));
    };
    $account = static function () use ($db, $typeId): array {
        $statement = $db->prepare(
            "SELECT reserved_count, consumed_count, reserved_minutes, consumed_minutes
             FROM staff_permission_quota_accounts
             WHERE staff_user_id = 1001 AND permission_type_id = ? AND period_key = '2026-10'"
        );
        $statement->execute([$typeId]);
        $row = $statement->fetch(PDO::FETCH_ASSOC);

        return $row ?: [
            'reserved_count' => 0,
            'consumed_count' => 0,
            'reserved_minutes' => 0,
            'consumed_minutes' => 0,
        ];
    };

    $raceA = $createPendingRequest(1);
    $raceB = $createPendingRequest(2);
    $reserveRace = $runWorkers([
        [
            'idempotency' => 'quota-race-reserve-a', 'request_id' => $raceA['request_id'],
            'period_id' => $raceA['period_id'], 'movement_type' => 'reserve',
            'count' => 1, 'minutes' => 60, 'max_requests' => 1, 'max_minutes' => 60,
        ],
        [
            'idempotency' => 'quota-race-reserve-b', 'request_id' => $raceB['request_id'],
            'period_id' => $raceB['period_id'], 'movement_type' => 'reserve',
            'count' => 1, 'minutes' => 60, 'max_requests' => 1, 'max_minutes' => 60,
        ],
    ]);
    $reserveSuccesses = $successful($reserveRace);
    $reserveErrors = array_values(array_filter(
        $reserveRace,
        static fn (array $result): bool => ($result['output']['error'] ?? '') === 'PERMISSION_QUOTA_EXCEEDED'
    ));
    $assert(count($reserveSuccesses) === 1, 'two concurrent last-slot reservations allow exactly one success');
    $assert(count($reserveErrors) === 1, 'the losing concurrent reservation reports quota exceeded');
    $afterReserveRace = $account();
    $assert(
        (int) $afterReserveRace['reserved_count'] === 1 && (int) $afterReserveRace['reserved_minutes'] === 60,
        'reserve race leaves one held request and sixty held minutes'
    );

    if ($reserveSuccesses !== []) {
        $winner = $reserveSuccesses[0]['command'];
        $releaseWinner = $runWorkers([[
            'idempotency' => 'quota-race-release-winner',
            'request_id' => $winner['request_id'],
            'period_id' => $winner['period_id'],
            'movement_type' => 'release',
            'count' => 1,
            'minutes' => 60,
            'max_requests' => 1,
            'max_minutes' => 60,
        ]]);
        $assert(count($successful($releaseWinner)) === 1, 'winner reservation can be released for the next race');
    }

    $consumeA = $createPendingRequest(3);
    $consumeB = $createPendingRequest(4);
    $reserveForConsume = $runWorkers([
        [
            'idempotency' => 'quota-consume-reserve-a', 'request_id' => $consumeA['request_id'],
            'period_id' => $consumeA['period_id'], 'movement_type' => 'reserve',
            'count' => 1, 'minutes' => 60, 'max_requests' => 2, 'max_minutes' => 120,
        ],
        [
            'idempotency' => 'quota-consume-reserve-b', 'request_id' => $consumeB['request_id'],
            'period_id' => $consumeB['period_id'], 'movement_type' => 'reserve',
            'count' => 1, 'minutes' => 60, 'max_requests' => 2, 'max_minutes' => 120,
        ],
    ]);
    $assert(count($successful($reserveForConsume)) === 2, 'two distinct reservations both persist under a shared account lock');
    $consumeRace = $runWorkers([
        [
            'idempotency' => 'quota-consume-a', 'request_id' => $consumeA['request_id'],
            'period_id' => $consumeA['period_id'], 'movement_type' => 'consume',
            'count' => 1, 'minutes' => 60, 'max_requests' => 2, 'max_minutes' => 120,
        ],
        [
            'idempotency' => 'quota-consume-b', 'request_id' => $consumeB['request_id'],
            'period_id' => $consumeB['period_id'], 'movement_type' => 'consume',
            'count' => 1, 'minutes' => 60, 'max_requests' => 2, 'max_minutes' => 120,
        ],
    ]);
    $assert(count($successful($consumeRace)) === 2, 'concurrent consumptions do not lose either reservation');
    $afterConsume = $account();
    $assert(
        (int) $afterConsume['reserved_count'] === 0
        && (int) $afterConsume['consumed_count'] === 2
        && (int) $afterConsume['reserved_minutes'] === 0
        && (int) $afterConsume['consumed_minutes'] === 120,
        'concurrent consumes preserve both account transitions'
    );
    $reverseRace = $runWorkers([
        [
            'idempotency' => 'quota-reverse-a', 'request_id' => $consumeA['request_id'],
            'period_id' => $consumeA['period_id'], 'movement_type' => 'reverse',
            'count' => 1, 'minutes' => 60, 'max_requests' => 2, 'max_minutes' => 120,
        ],
        [
            'idempotency' => 'quota-reverse-b', 'request_id' => $consumeB['request_id'],
            'period_id' => $consumeB['period_id'], 'movement_type' => 'reverse',
            'count' => 1, 'minutes' => 60, 'max_requests' => 2, 'max_minutes' => 120,
        ],
    ]);
    $assert(count($successful($reverseRace)) === 2, 'reversal cleans up both consumed movements through new ledger rows');

    $releaseA = $createPendingRequest(5);
    $releaseB = $createPendingRequest(6);
    $reserveForRelease = $runWorkers([
        [
            'idempotency' => 'quota-release-reserve-a', 'request_id' => $releaseA['request_id'],
            'period_id' => $releaseA['period_id'], 'movement_type' => 'reserve',
            'count' => 1, 'minutes' => 60, 'max_requests' => 2, 'max_minutes' => 120,
        ],
        [
            'idempotency' => 'quota-release-reserve-b', 'request_id' => $releaseB['request_id'],
            'period_id' => $releaseB['period_id'], 'movement_type' => 'reserve',
            'count' => 1, 'minutes' => 60, 'max_requests' => 2, 'max_minutes' => 120,
        ],
    ]);
    $assert(count($successful($reserveForRelease)) === 2, 'release race starts with two held reservations');
    $releaseRace = $runWorkers([
        [
            'idempotency' => 'quota-release-a', 'request_id' => $releaseA['request_id'],
            'period_id' => $releaseA['period_id'], 'movement_type' => 'release',
            'count' => 1, 'minutes' => 60, 'max_requests' => 2, 'max_minutes' => 120,
        ],
        [
            'idempotency' => 'quota-release-b', 'request_id' => $releaseB['request_id'],
            'period_id' => $releaseB['period_id'], 'movement_type' => 'release',
            'count' => 1, 'minutes' => 60, 'max_requests' => 2, 'max_minutes' => 120,
        ],
    ]);
    $assert(count($successful($releaseRace)) === 2, 'concurrent releases return both held reservations exactly once');
    $afterRelease = $account();
    $assert(
        (int) $afterRelease['reserved_count'] === 0
        && (int) $afterRelease['consumed_count'] === 0
        && (int) $afterRelease['reserved_minutes'] === 0
        && (int) $afterRelease['consumed_minutes'] === 0,
        'all concurrent consume/release paths leave the cache reconciled to zero'
    );

    $auditFailureRequest = $createPendingRequest(7);
    $auditFailure = $runWorkers([[
        'idempotency' => 'quota-audit-failure',
        'request_id' => $auditFailureRequest['request_id'],
        'period_id' => $auditFailureRequest['period_id'],
        'movement_type' => 'reserve',
        'count' => 1,
        'minutes' => 60,
        'max_requests' => 2,
        'max_minutes' => 120,
        'fail_audit' => true,
    ]]);
    $assert(
        ($auditFailure[0]['output']['error'] ?? '') === 'AUDIT_WRITE_FAILED',
        'audit failure is returned by the real quota service'
    );
    $afterAuditFailure = $account();
    $assert(
        (int) $afterAuditFailure['reserved_count'] === 0
        && (int) $afterAuditFailure['reserved_minutes'] === 0,
        'audit failure rolls back real quota counters and movement insert'
    );
    $movementCount = (int) $db->query('SELECT COUNT(*) FROM staff_permission_quota_movements')->fetchColumn();
    $assert($movementCount === 12, 'only successful concurrent quota commands leave immutable movements');
} catch (Throwable $exception) {
    $recordFailure('permission quota concurrency exercise failed: ' . $exception->getMessage());
} finally {
    if ($db instanceof PDO) {
        foreach ($permissionTriggers as $trigger) {
            try {
                $db->exec('DROP TRIGGER IF EXISTS ' . $quoteIdentifier($trigger));
            } catch (Throwable $exception) {
                $recordFailure("rollback could not drop trigger {$trigger}: " . $exception->getMessage());
            }
        }
        foreach ($permissionTables as $table) {
            try {
                $db->exec('DROP TABLE IF EXISTS ' . $quoteIdentifier($table));
            } catch (Throwable $exception) {
                $recordFailure("rollback could not drop table {$table}: " . $exception->getMessage());
            }
        }
        try {
            $remainingTables = $db->query(
                'SELECT TABLE_NAME FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE()'
            )->fetchAll(PDO::FETCH_COLUMN);
            $remainingTriggers = $db->query(
                'SELECT TRIGGER_NAME FROM information_schema.TRIGGERS WHERE TRIGGER_SCHEMA = DATABASE()'
            )->fetchAll(PDO::FETCH_COLUMN);
            $assert($remainingTables === [], 'rollback removes all owned concurrency-test tables');
            $assert($remainingTriggers === [], 'rollback removes all owned concurrency-test triggers');
        } catch (Throwable $exception) {
            $recordFailure('quota concurrency rollback verification failed: ' . $exception->getMessage());
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
            $assert((int) $exists->fetchColumn() === 0, 'temporary quota concurrency database is removed');
        } catch (Throwable $exception) {
            $recordFailure("temporary quota concurrency cleanup failed: {$exception->getMessage()}");
        }
    }
}

if ($databaseCreated && !$databaseDropped) {
    fwrite(STDERR, "FAIL: temporary database {$databaseName} still exists and requires manual cleanup.\n");
    ++$failures;
}

if ($failures > 0) {
    fwrite(STDERR, "{$failures} permission quota concurrency failure(s).\n");
    exit(1);
}

echo "Staff-HR permission quota concurrency test passed on {$databaseName}; temporary database removed.\n";
