<?php

declare(strict_types=1);

/**
 * Guarded MariaDB proof for the immutable FR-136 correction ledger.
 *
 * The test accepts only a fresh *_test database, creates it, applies and
 * reapplies the migration, proves append-only constraints, then drops it.
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
    fwrite(STDERR, "FAIL: --database must identify a new isolated *_test database.\n");
    exit(2);
}

putenv('APP_ENV=test');
putenv('DB_NAME=' . $databaseName);
$_ENV['APP_ENV'] = $_SERVER['APP_ENV'] = 'test';
$_ENV['DB_NAME'] = $_SERVER['DB_NAME'] = $databaseName;

require_once dirname(__DIR__) . '/config/database.php';

$failures = 0;
$assert = static function (bool $condition, string $message) use (&$failures): void {
    if (!$condition) {
        fwrite(STDERR, "FAIL: {$message}\n");
        ++$failures;
    }
};
$quoteIdentifier = static function (string $identifier): string {
    if (!preg_match('/^[A-Za-z0-9_]+$/', $identifier)) {
        throw new InvalidArgumentException('Unsafe database identifier.');
    }
    return '`' . $identifier . '`';
};
$expectImmutable = static function (callable $operation, string $message) use ($assert): void {
    try {
        $operation();
        $assert(false, $message);
    } catch (PDOException $exception) {
        $sqlState = (string) ($exception->errorInfo[0] ?? $exception->getCode());
        $assert($sqlState === '45000', $message . ' (unexpected SQLSTATE ' . $sqlState . ')');
    }
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
        fwrite(STDERR, "FAIL: {$databaseName} already exists; use a fresh dedicated *_test database.\n");
        exit(2);
    }

    $admin->exec('CREATE DATABASE ' . $quoteIdentifier($databaseName)
        . ' CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci');
    $databaseCreated = true;
    $db = new PDO(
        'mysql:host=' . DB_HOST . ';dbname=' . $databaseName . ';charset=utf8mb4',
        DB_USERNAME,
        DB_PASSWORD,
        [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
    );

    $migration = require dirname(__DIR__)
        . '/database/migrations/20260809_staff_hr_organization_corrections.php';
    $assert(is_callable($migration), 'correction migration returns a callable');
    $migration($db);
    $migration($db);

    $tables = $db->query(
        "SELECT TABLE_NAME FROM information_schema.TABLES
         WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME LIKE 'staff_organization_correction%'
         ORDER BY TABLE_NAME"
    )->fetchAll(PDO::FETCH_COLUMN);
    $triggers = $db->query(
        "SELECT TRIGGER_NAME FROM information_schema.TRIGGERS
         WHERE TRIGGER_SCHEMA = DATABASE() AND TRIGGER_NAME LIKE 'trg_staff_org_correction%'
         ORDER BY TRIGGER_NAME"
    )->fetchAll(PDO::FETCH_COLUMN);
    $assert(count($tables) === 3, 'migration creates exactly three correction tables');
    $assert(count($triggers) === 6, 'migration creates update/delete guards for all correction tables');

    $hash = hash('sha256', 'staff-organization-correction-integration');
    $snapshot = json_encode([
        'staff_user_ids' => [970001],
        'dates' => ['2026-08-01'],
        'request_refs' => [],
        'report_periods' => ['2026-08'],
    ], JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
    $insertCorrection = $db->prepare(
        'INSERT INTO staff_organization_corrections
         (correction_kind, scope_type, scope_id, effective_from, effective_to,
          proposed_reference_id, reason_text, reason_hash, impact_snapshot_json,
          impact_snapshot_hash, direction, requested_by, payload_hash, idempotency_key)
         VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
    );
    $insertCorrection->execute([
        'organization_unit', 'staff', 970001, '2026-08-01', '2026-08-01',
        1001, 'سبب اختبار معزول', $hash, $snapshot, $hash, 'apply', 990001, $hash, $hash,
    ]);
    $correctionId = (int) $db->lastInsertId();

    $decisionKey = hash('sha256', 'staff-organization-correction-decision');
    $insertDecision = $db->prepare(
        'INSERT INTO staff_organization_correction_decisions
         (correction_id, decision, decided_by, decision_hash, idempotency_key)
         VALUES (?, ?, ?, ?, ?)'
    );
    $insertDecision->execute([$correctionId, 'approved', 990002, $decisionKey, $decisionKey]);
    $decisionId = (int) $db->lastInsertId();

    $impactKey = hash('sha256', 'staff-organization-correction-impact');
    $insertImpact = $db->prepare(
        'INSERT INTO staff_organization_correction_impacts
         (correction_id, decision_id, direction, impact_type, resource_type,
          staff_user_id, work_date, source_snapshot_hash, impact_key)
         VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)'
    );
    $insertImpact->execute([
        $correctionId, $decisionId, 'apply', 'attendance_day', 'attendance_day',
        970001, '2026-08-01', $hash, $impactKey,
    ]);

    $expectImmutable(static function () use ($db, $correctionId): void {
        $statement = $db->prepare('UPDATE staff_organization_corrections SET reason_text = ? WHERE id = ?');
        $statement->execute(['changed', $correctionId]);
    }, 'correction previews cannot be updated');
    $expectImmutable(static function () use ($db, $decisionId): void {
        $statement = $db->prepare('DELETE FROM staff_organization_correction_decisions WHERE id = ?');
        $statement->execute([$decisionId]);
    }, 'correction decisions cannot be deleted');
    $expectImmutable(static function () use ($db, $impactKey): void {
        $statement = $db->prepare('UPDATE staff_organization_correction_impacts SET impact_key = ?');
        $statement->execute([$impactKey]);
    }, 'correction impact intents cannot be updated');
} catch (Throwable $exception) {
    fwrite(STDERR, 'FAIL: correction migration/invariant exercise failed: ' . $exception->getMessage() . "\n");
    ++$failures;
} finally {
    if ($databaseCreated && $admin instanceof PDO) {
        try {
            $db = null;
            $admin->exec('DROP DATABASE ' . $quoteIdentifier($databaseName));
            $databaseDropped = true;
        } catch (Throwable $exception) {
            fwrite(STDERR, "FAIL: temporary correction database cleanup failed: {$exception->getMessage()}\n");
            ++$failures;
        }
    }
}

if ($databaseCreated && !$databaseDropped) {
    fwrite(STDERR, "FAIL: temporary database {$databaseName} still exists and requires manual cleanup.\n");
    ++$failures;
}
if ($failures > 0) {
    fwrite(STDERR, "{$failures} correction integration failure(s).\n");
    exit(1);
}

echo "Staff-HR organization correction migration proof passed on {$databaseName}; temporary database removed.\n";
