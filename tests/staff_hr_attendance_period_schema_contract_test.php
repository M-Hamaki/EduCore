<?php

declare(strict_types=1);

/**
 * Database-free contract for Attendance period close/reopen control.
 * Real migration execution remains restricted to an explicitly marked _test
 * database; this guard protects the durable idempotency and no-silent-write
 * invariants without opening the user's educore database.
 */

use EduCore\Modules\Operations\Audit\AuditPolicyRegistry;

$root = dirname(__DIR__);
$migrationPath = $root . '/database/migrations/20260808_staff_hr_attendance_period_control.php';
$registryPath = $root . '/src/Modules/Operations/Audit/AuditPolicyRegistry.php';
$source = is_file($migrationPath) ? (string) file_get_contents($migrationPath) : '';
$failures = 0;

$assert = static function (bool $condition, string $message) use (&$failures): void {
    if (!$condition) {
        fwrite(STDERR, "FAIL: {$message}\n");
        ++$failures;
    }
};
$assertContains = static function (string $needle, string $haystack, string $message) use ($assert): void {
    $assert(str_contains($haystack, $needle), $message);
};

$assert($source !== '', 'attendance period migration exists and is readable');
if ($source !== '') {
    $migration = require $migrationPath;
    $assert(is_callable($migration), 'attendance period migration returns a callable');

    foreach ([
        'staff_attendance_periods',
        'staff_attendance_period_change_requests',
    ] as $table) {
        $assertContains("CREATE TABLE `{$table}`", $source, "period migration creates {$table}");
    }

    foreach ([
        'uk_staff_attendance_period_key',
        'uk_staff_attendance_period_change_idempotency',
        'uk_staff_attendance_period_change_decision_idempotency',
        'uk_staff_attendance_period_change_fact',
        'idx_staff_attendance_period_change_review',
        'idx_staff_attendance_period_change_staff_date',
    ] as $index) {
        $assertContains("`{$index}`", $source, "period migration defines {$index}");
    }

    foreach ([
        "`state` ENUM('open','closed')",
        "`status` ENUM('pending','ready','approved','rejected','applied','cancelled')",
        '`source_fingerprint` CHAR(64) NOT NULL',
        '`request_hash` CHAR(64) NOT NULL',
        '`change_fingerprint` CHAR(64) NOT NULL',
        '`decision_idempotency_key` VARCHAR(190) NULL',
        '`decision_hash` CHAR(64) NULL',
        '`applied_run_id` BIGINT NULL',
        '`lock_version` INT UNSIGNED NOT NULL DEFAULT 1',
        'fk_staff_attendance_period_last_run',
        'fk_staff_attendance_period_change_period',
        'fk_staff_attendance_period_change_run',
        'chk_staff_attendance_period_month_bounds',
        'chk_staff_attendance_period_closed_metadata',
        'chk_staff_attendance_period_change_applied',
    ] as $contract) {
        $assertContains($contract, $source, "period schema preserves {$contract}");
    }

    $assertContains('information_schema.TABLES', $source, 'migration checks table existence before DDL');
    $assertContains('Rollback in an isolated environment', $source, 'migration documents an isolated rollback only');
    $assert(!str_contains($source, 'ON DELETE CASCADE'), 'period history cannot cascade-delete');
    $assert(
        preg_match('/\$db->exec\s*\(\s*[\'\"]\s*(?:DROP|TRUNCATE|ALTER)\b/i', $source) !== 1,
        'period migration remains additive and non-destructive'
    );
}

$assert(is_file($registryPath), 'audit policy registry exists');
if (is_file($registryPath)) {
    require_once $registryPath;
    foreach (['staff_attendance_periods', 'staff_attendance_period_change_requests'] as $table) {
        $assert(AuditPolicyRegistry::isRegisteredTable($table), "{$table} is registered before writes can audit it");
        $assert(!AuditPolicyRegistry::allowsDirectUndo($table), "{$table} cannot use unsafe direct undo");
        $assert(
            AuditPolicyRegistry::directUndoBlockReason($table) === 'workflow_owned_rollback',
            "{$table} has an explicit workflow-owned rollback policy"
        );
    }
    $redacted = AuditPolicyRegistry::redact([
        'source_fingerprint' => str_repeat('a', 64),
        'decision_hash' => str_repeat('b', 64),
    ], 'staff_attendance_period_change_requests');
    $assert(($redacted['source_fingerprint'] ?? null) === '[REDACTED]', 'source fingerprint is redacted from period audit details');
    $assert(($redacted['decision_hash'] ?? null) === '[REDACTED]', 'decision fingerprint is redacted from period audit details');
}

if ($failures > 0) {
    fwrite(STDERR, "{$failures} attendance period schema contract failure(s).\n");
    exit(1);
}

echo "Staff-HR attendance period schema contracts passed.\n";
