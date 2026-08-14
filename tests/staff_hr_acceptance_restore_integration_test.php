<?php

declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

$options = getopt('', ['database:']);
$databaseName = trim((string) ($options['database'] ?? getenv('EDUCORE_TEST_DB_NAME') ?: ''));
$restoreDatabaseName = $databaseName . '_restore_test';
$marker = trim((string) (getenv('STAFF_HR_TEST_MARKER') ?: ''));
if ($marker !== 'integrated-staff-hr'
    || !preg_match('/^[A-Za-z0-9_]+_test$/D', $databaseName)
    || strtolower($databaseName) === 'educore') {
    fwrite(STDERR, "FAIL: explicit marker and fresh *_test database are required.\n");
    exit(2);
}

putenv('APP_ENV=test');
putenv('DB_NAME=' . $databaseName);
$_ENV['APP_ENV'] = $_SERVER['APP_ENV'] = 'test';
$_ENV['DB_NAME'] = $_SERVER['DB_NAME'] = $databaseName;

$root = dirname(__DIR__);
require_once $root . '/config/database.php';

$failures = 0;
$assert = static function (bool $condition, string $message) use (&$failures): void {
    echo $message . ':' . ($condition ? 'PASS' : 'FAIL') . PHP_EOL;
    if (!$condition) {
        ++$failures;
    }
};
$quoteIdentifier = static function (string $identifier): string {
    if (!preg_match('/^[A-Za-z0-9_]+$/D', $identifier)) {
        throw new InvalidArgumentException('Unsafe database identifier.');
    }
    return '`' . $identifier . '`';
};
$runTool = static function (string $script, array $arguments, array $environment) use ($root): array {
    $command = escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg($root . '/' . $script);
    foreach ($arguments as $argument) {
        $command .= ' ' . escapeshellarg((string) $argument);
    }
    $pipes = [];
    $process = proc_open(
        $command,
        [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
        $pipes,
        $root,
        array_merge(getenv(), $environment)
    );
    if (!is_resource($process)) {
        throw new RuntimeException('Unable to start acceptance tool.');
    }
    fclose($pipes[0]);
    $stdout = stream_get_contents($pipes[1]);
    $stderr = stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);
    $code = proc_close($process);
    return ['code' => $code, 'stdout' => (string) $stdout, 'stderr' => (string) $stderr];
};
$removeOwnedRuntimeDirectory = static function (string $path) use ($root): void {
    $parent = str_replace('\\', '/', $root . '/storage/test-runtime/');
    $normalized = rtrim(str_replace('\\', '/', $path), '/') . '/';
    if (!str_starts_with($normalized, $parent) || !is_dir($path)) {
        return;
    }
    $iterator = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($path, FilesystemIterator::SKIP_DOTS),
        RecursiveIteratorIterator::CHILD_FIRST
    );
    foreach ($iterator as $entry) {
        if ($entry->isDir() && !$entry->isLink()) {
            rmdir($entry->getPathname());
        } else {
            unlink($entry->getPathname());
        }
    }
    rmdir($path);
};

$admin = null;
$db = null;
$created = false;
$dropped = false;
$restoreDropped = false;

try {
    $refused = $runTool('tools/staff_hr_acceptance_seed.php', ['--database=educore', '--json'], [
        'APP_ENV' => 'test',
        'STAFF_HR_TEST_MARKER' => 'integrated-staff-hr',
        'STAFF_HR_ACCEPTANCE_PASSWORD' => 'Demo-only-passphrase-2026!',
    ]);
    $assert(
        $refused['code'] !== 0
        && str_contains($refused['stderr'], 'STAFF_HR_ACCEPTANCE_TARGET_REFUSED'),
        'seed_refuses_the_real_educore_name_before_connecting'
    );
    $badMarker = $runTool('tools/staff_hr_acceptance_seed.php', ['--database=' . $databaseName, '--json'], [
        'APP_ENV' => 'test',
        'STAFF_HR_TEST_MARKER' => 'wrong-marker',
        'STAFF_HR_ACCEPTANCE_PASSWORD' => 'Demo-only-passphrase-2026!',
    ]);
    $assert(
        $badMarker['code'] !== 0
        && str_contains($badMarker['stderr'], 'STAFF_HR_ACCEPTANCE_TARGET_REFUSED'),
        'seed_refuses_a_missing_or_wrong_feature_marker_before_connecting'
    );

    $admin = new PDO(
        'mysql:host=' . DB_HOST . ';charset=utf8mb4',
        DB_USERNAME,
        DB_PASSWORD,
        [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
    );
    $exists = $admin->prepare('SELECT COUNT(*) FROM information_schema.SCHEMATA WHERE SCHEMA_NAME = ?');
    $exists->execute([$databaseName]);
    if ((int) $exists->fetchColumn() !== 0) {
        fwrite(STDERR, "FAIL: {$databaseName} already exists; use a fresh database.\n");
        exit(2);
    }
    $admin->exec('CREATE DATABASE ' . $quoteIdentifier($databaseName)
        . ' CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci');
    $created = true;
    $db = new PDO(
        'mysql:host=' . DB_HOST . ';dbname=' . $databaseName . ';charset=utf8mb4',
        DB_USERNAME,
        DB_PASSWORD,
        [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
    );

    $db->exec(<<<'SQL'
CREATE TABLE users (
    id INT NOT NULL AUTO_INCREMENT,
    name VARCHAR(255) NOT NULL,
    employee_code VARCHAR(50) NULL,
    username VARCHAR(50) NULL,
    email VARCHAR(255) NULL,
    password VARCHAR(255) NULL,
    password_hash VARCHAR(255) NULL,
    password_key_version SMALLINT UNSIGNED NOT NULL DEFAULT 2,
    role VARCHAR(50) NULL,
    status ENUM('active','inactive','graduated') NOT NULL DEFAULT 'active',
    is_test_account TINYINT(1) NOT NULL DEFAULT 0,
    PRIMARY KEY (id),
    UNIQUE KEY uk_users_employee_code (employee_code),
    UNIQUE KEY uk_users_username (username),
    UNIQUE KEY uk_users_email (email)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL);
    $db->exec(<<<'SQL'
CREATE TABLE staff_profiles (
    id INT NOT NULL AUTO_INCREMENT,
    user_id INT NOT NULL,
    employee_code VARCHAR(20) NULL,
    biometric_id VARCHAR(50) NULL,
    full_name_ar VARCHAR(255) NULL,
    email_personal VARCHAR(255) NULL,
    hire_date DATE NULL,
    job_title VARCHAR(100) NULL,
    department VARCHAR(500) NULL,
    current_work_status VARCHAR(20) NULL,
    current_status_effective_date DATE NULL,
    first_hire_date DATE NULL,
    latest_hire_date DATE NULL,
    can_rehire TINYINT(1) NULL,
    notes TEXT NULL,
    PRIMARY KEY (id),
    UNIQUE KEY uk_staff_profile_user (user_id),
    UNIQUE KEY uk_staff_profile_employee (employee_code),
    UNIQUE KEY uk_staff_profile_biometric (biometric_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL);
    $db->exec(<<<'SQL'
CREATE TABLE staff_roles (
    id INT NOT NULL AUTO_INCREMENT,
    role_key VARCHAR(50) NOT NULL,
    role_name VARCHAR(100) NOT NULL,
    status VARCHAR(20) NOT NULL DEFAULT 'active',
    PRIMARY KEY (id),
    UNIQUE KEY uk_staff_role_key (role_key)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL);
    $db->exec(<<<'SQL'
CREATE TABLE user_role_assignments (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    user_id INT NOT NULL,
    role_key VARCHAR(50) NOT NULL,
    is_primary TINYINT(1) NOT NULL DEFAULT 0,
    status ENUM('active','inactive') NOT NULL DEFAULT 'active',
    assigned_by INT NULL,
    PRIMARY KEY (id),
    UNIQUE KEY uk_user_role (user_id, role_key)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL);
    $db->exec(<<<'SQL'
CREATE TABLE activity_logs (
    id BIGINT NOT NULL AUTO_INCREMENT,
    user_id INT NULL,
    user_name VARCHAR(255) NULL,
    user_role VARCHAR(50) NULL,
    action VARCHAR(100) NOT NULL,
    target_type VARCHAR(100) NULL,
    target_id BIGINT NULL,
    target_name VARCHAR(255) NULL,
    details LONGTEXT NULL,
    ip_address VARCHAR(64) NULL,
    academic_year_id INT NULL,
    request_id VARCHAR(64) NULL,
    batch_id CHAR(32) NULL,
    result VARCHAR(20) NULL,
    route VARCHAR(500) NULL,
    user_agent VARCHAR(500) NULL,
    undo_log_id BIGINT NULL,
    created_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
    PRIMARY KEY (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL);
    $db->exec(<<<'SQL'
CREATE TABLE academic_years (
    id INT NOT NULL AUTO_INCREMENT,
    name VARCHAR(100) NOT NULL,
    start_date DATE NOT NULL,
    end_date DATE NOT NULL,
    is_current TINYINT(1) NOT NULL DEFAULT 0,
    PRIMARY KEY (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL);
    $db->exec("INSERT INTO users (name, username, email, password_hash, role, status, is_test_account)
               VALUES ('Baseline Admin', 'baseline.admin', 'baseline.admin@example.test', 'baseline-hash', 'admin', 'active', 0)");
    $baselineActorId = (int) $db->lastInsertId();
    $roleInsert = $db->prepare('INSERT INTO staff_roles (role_key, role_name, status) VALUES (?, ?, ?)');
    foreach (['admin', 'super_admin', 'teacher', 'specialist', 'employee'] as $roleKey) {
        $roleInsert->execute([$roleKey, 'Baseline ' . $roleKey, 'active']);
    }

    foreach ([
        '20260718_safe_year_rollover.php',
        '20260730_staff_hr_organization_policy_foundation.php',
        '20260730_staff_hr_workflow_operations_foundation.php',
        '20260730_staff_hr_schedule_calendar.php',
        '20260730_staff_hr_permissions_quota.php',
        '20260730_staff_hr_leave_ledger.php',
        '20260730_staff_hr_attendance_engine.php',
        '20260730_staff_hr_discipline.php',
        '20260730_staff_hr_ertaq.php',
    ] as $migrationFile) {
        $migration = require $root . '/database/migrations/' . $migrationFile;
        $migration($db);
    }
    $db->exec("INSERT INTO staff_org_units
        (code, name, unit_type, valid_from, status, created_by)
        VALUES ('BASELINE-UNIT', 'Baseline Unit', 'baseline', '2026-01-01', 'active', {$baselineActorId})");
    $baselineUnitId = (int) $db->lastInsertId();

    $toolEnvironment = [
        'APP_ENV' => 'test',
        'STAFF_HR_TEST_MARKER' => 'integrated-staff-hr',
        'EDUCORE_TEST_DB_NAME' => $databaseName,
        'DB_NAME' => $databaseName,
        'STAFF_HR_ACCEPTANCE_PASSWORD' => 'Demo-only-passphrase-2026!',
    ];
    $seed = $runTool(
        'tools/staff_hr_acceptance_seed.php',
        ['--database=' . $databaseName, '--json'],
        $toolEnvironment
    );
    if ($seed['code'] !== 0) {
        throw new RuntimeException('SEED_TOOL_FAILURE: ' . trim($seed['stderr']));
    }
    $seedReceipt = json_decode(trim($seed['stdout']), true);
    $assert($seed['code'] === 0 && is_array($seedReceipt) && !$seedReceipt['replayed'], 'first_seed_writes_one_owned_dataset');
    $assert((int) $db->query("SELECT COUNT(*) FROM users WHERE is_test_account = 1 AND email LIKE 'demo.staffhr.%@example.test'")->fetchColumn() === 10, 'seed_creates_ten_synthetic_personas');
    $assert((int) $db->query("SELECT COUNT(*) FROM staff_profiles WHERE notes = 'STAFF_HR_ACCEPTANCE_DATASET'")->fetchColumn() === 10, 'seed_creates_ten_synthetic_staff_profiles');
    $assert((int) $db->query("SELECT COUNT(*) FROM staff_profiles WHERE employee_code = biometric_id")->fetchColumn() === 0, 'seed_keeps_employee_and_biometric_identifiers_separate');
    $assert((int) $db->query("SELECT COUNT(*) FROM users WHERE is_test_account = 1 AND (password_hash IS NULL OR password_hash NOT LIKE '$2%')")->fetchColumn() === 0, 'seed_stores_only_password_hashes_for_personas');
    $assert((int) $db->query("SELECT COUNT(*) FROM staff_biometric_events WHERE idempotency_key LIKE 'staff-hr-acceptance:biometric:%'")->fetchColumn() === 3, 'seed_creates_matched_and_unmatched_biometric_evidence');
    $assert((int) $db->query("SELECT COUNT(*) FROM staff_permission_requests WHERE create_idempotency_key LIKE 'staff-hr-acceptance:permission-create:%'")->fetchColumn() === 2, 'seed_creates_permission_requests_with_policy_snapshots');
    $assert((int) $db->query("SELECT COUNT(*) FROM staff_leave_requests WHERE create_idempotency_key LIKE 'staff-hr-acceptance:leave-create:%'")->fetchColumn() === 2, 'seed_creates_leave_requests_and_allocated_days');
    $assert((int) $db->query("SELECT COUNT(*) FROM staff_attendance_day_versions WHERE is_official = 1")->fetchColumn() === 1, 'seed_publishes_one_official_attendance_day');
    $assert((int) $db->query("SELECT COUNT(*) FROM staff_discipline_appeals WHERE status = 'submitted'")->fetchColumn() === 1, 'seed_creates_a_coherent_issued_decision_and_submitted_appeal');
    $assert((int) $db->query("SELECT COUNT(*) FROM staff_ertaq_tickets WHERE ticket_no LIKE 'DEMO-ERTAQ-%'")->fetchColumn() === 3, 'seed_creates_normal_restricted_and_urgent_ertaq_tickets');
    $assert((int) $db->query("SELECT COUNT(*) FROM staff_ertaq_urgent_events")->fetchColumn() === 1, 'seed_routes_the_urgent_ertaq_fixture_with_protection_evidence');
    $assert((int) $db->query("SELECT COUNT(*) FROM recovery_backups WHERE database_name = " . $db->quote($databaseName))->fetchColumn() === 1, 'seed_records_one_verified_baseline_package_owner');
    $ownedBeforeReplay = (int) $seedReceipt['owned_count'];

    $seedReplay = $runTool(
        'tools/staff_hr_acceptance_seed.php',
        ['--database=' . $databaseName, '--json'],
        $toolEnvironment
    );
    $seedReplayReceipt = json_decode(trim($seedReplay['stdout']), true);
    if ($seedReplay['code'] !== 0
        || !is_array($seedReplayReceipt)
        || $seedReplayReceipt['replayed'] !== true
        || (int) $seedReplayReceipt['owned_count'] !== $ownedBeforeReplay) {
        fwrite(STDERR, sprintf(
            "seed replay diagnostic: code=%d replayed=%s first_count=%d replay_count=%d error=%s\n",
            $seedReplay['code'],
            is_array($seedReplayReceipt) ? json_encode($seedReplayReceipt['replayed'] ?? null) : 'invalid-json',
            $ownedBeforeReplay,
            is_array($seedReplayReceipt) ? (int) ($seedReplayReceipt['owned_count'] ?? -1) : -1,
            trim($seedReplay['stderr'])
        ));
    }
    $assert(
        $seedReplay['code'] === 0
        && is_array($seedReplayReceipt)
        && $seedReplayReceipt['replayed'] === true
        && (int) $seedReplayReceipt['owned_count'] === $ownedBeforeReplay,
        'second_seed_is_idempotent_with_stable_owned_count'
    );
    $assert((int) $db->query("SELECT COUNT(*) FROM staff_hr_migration_batches WHERE migration_key = 'staff_hr_acceptance_v1'")->fetchColumn() === 1, 'seed_replay_does_not_duplicate_the_batch');

    $datasetSuperAdminId = (int) ($seedReceipt['persona_ids']['super_admin'] ?? 0);
    $wrongActorRestore = $runTool(
        'tools/staff_hr_acceptance_restore.php',
        [
            '--database=' . $databaseName,
            '--target-database=' . $restoreDatabaseName,
            '--actor-id=' . $datasetSuperAdminId,
            '--json',
        ],
        $toolEnvironment
    );
    $assert(
        $wrongActorRestore['code'] !== 0
        && str_contains($wrongActorRestore['stderr'], 'RESTORE_ACTOR_IS_DATASET_OWNED')
        && (int) $db->query("SELECT COUNT(*) FROM users WHERE is_test_account = 1")->fetchColumn() === 10,
        'restore_refuses_a_dataset_owned_actor_before_deleting'
    );

    $restore = $runTool(
        'tools/staff_hr_acceptance_restore.php',
        [
            '--database=' . $databaseName,
            '--target-database=' . $restoreDatabaseName,
            '--actor-id=' . $baselineActorId,
            '--json',
        ],
        $toolEnvironment
    );
    if ($restore['code'] !== 0) {
        fwrite(STDERR, 'RESTORE_TOOL_FAILURE: ' . trim($restore['stderr']) . PHP_EOL);
    }
    $restoreReceipt = json_decode(trim($restore['stdout']), true);
    $assert(
        $restore['code'] === 0
        && is_array($restoreReceipt)
        && (int) $restoreReceipt['deleted_count'] === 0
        && (string) $restoreReceipt['restored_database_name'] === $restoreDatabaseName,
        'restore_builds_a_fresh_retained_baseline_without_deleting_immutable_evidence'
    );
    $restoredDb = new PDO(
        'mysql:host=' . DB_HOST . ';dbname=' . $restoreDatabaseName . ';charset=utf8mb4',
        DB_USERNAME,
        DB_PASSWORD,
        [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
    );
    $assert((int) $restoredDb->query("SELECT COUNT(*) FROM users WHERE is_test_account = 1")->fetchColumn() === 0, 'restored_baseline_contains_no_demo_personas');
    $assert((int) $restoredDb->query("SELECT COUNT(*) FROM staff_profiles WHERE notes = 'STAFF_HR_ACCEPTANCE_DATASET'")->fetchColumn() === 0, 'restored_baseline_contains_no_demo_profiles');
    $assert((int) $restoredDb->query("SELECT COUNT(*) FROM users WHERE id = {$baselineActorId}")->fetchColumn() === 1, 'restored_baseline_preserves_the_baseline_admin');
    $assert((int) $restoredDb->query("SELECT COUNT(*) FROM staff_org_units WHERE id = {$baselineUnitId}")->fetchColumn() === 1, 'restored_baseline_preserves_an_unowned_organization_row');
    $assert((int) $restoredDb->query("SELECT COUNT(*) FROM staff_hr_migration_batches")->fetchColumn() === 0, 'restored_baseline_predates_the_acceptance_migration_batch');
    $assert((int) $db->query("SELECT COUNT(*) FROM users WHERE is_test_account = 1")->fetchColumn() === 10, 'source_acceptance_database_retains_immutable_demo_evidence');
    $assert((int) $db->query("SELECT COUNT(*) FROM activity_logs")->fetchColumn() >= 6, 'seed_and_baseline_restore_transitions_are_audited_on_the_source');

    $restoreReplay = $runTool(
        'tools/staff_hr_acceptance_restore.php',
        [
            '--database=' . $databaseName,
            '--target-database=' . $restoreDatabaseName,
            '--actor-id=' . $baselineActorId,
            '--json',
        ],
        $toolEnvironment
    );
    if ($restoreReplay['code'] !== 0) {
        fwrite(STDERR, 'RESTORE_REPLAY_FAILURE: ' . trim($restoreReplay['stderr']) . PHP_EOL);
    }
    $restoreReplayReceipt = json_decode(trim($restoreReplay['stdout']), true);
    $assert(
        $restoreReplay['code'] === 0
        && is_array($restoreReplayReceipt)
        && $restoreReplayReceipt['replayed'] === true,
        'restore_replay_is_idempotent_and_keeps_history'
    );
} catch (Throwable $exception) {
    fwrite(STDERR, 'FAIL: acceptance seed/restore exercise failed: ' . $exception->getMessage() . PHP_EOL);
    ++$failures;
} finally {
    if ($created && $admin instanceof PDO) {
        try {
            $db = null;
            $targetExists = $admin->prepare('SELECT COUNT(*) FROM information_schema.SCHEMATA WHERE SCHEMA_NAME = ?');
            $targetExists->execute([$restoreDatabaseName]);
            if ((int) $targetExists->fetchColumn() === 1) {
                $admin->exec('DROP DATABASE ' . $quoteIdentifier($restoreDatabaseName));
            }
            $restoreDropped = true;
            $admin->exec('DROP DATABASE ' . $quoteIdentifier($databaseName));
            $dropped = true;
        } catch (Throwable $exception) {
            fwrite(STDERR, 'FAIL: temporary acceptance database cleanup failed: ' . $exception->getMessage() . PHP_EOL);
            ++$failures;
        }
    }
    try {
        $removeOwnedRuntimeDirectory(
            $root . '/storage/test-runtime/staff-hr-acceptance-backups/' . $databaseName
        );
        $removeOwnedRuntimeDirectory(
            $root . '/storage/test-runtime/staff-hr-acceptance-data/' . $databaseName
        );
    } catch (Throwable $exception) {
        fwrite(STDERR, 'FAIL: temporary acceptance runtime cleanup failed: ' . $exception->getMessage() . PHP_EOL);
        ++$failures;
    }
}

if ($created && !$dropped) {
    fwrite(STDERR, "FAIL: temporary database {$databaseName} still exists and requires manual cleanup.\n");
    ++$failures;
}
if ($created && !$restoreDropped) {
    fwrite(STDERR, "FAIL: temporary restored database {$restoreDatabaseName} may still exist and requires manual cleanup.\n");
    ++$failures;
}
exit($failures > 0 ? 1 : 0);
