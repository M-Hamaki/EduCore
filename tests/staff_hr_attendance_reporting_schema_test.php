<?php

declare(strict_types=1);

/**
 * Database-free contract for rebuildable Attendance reporting projections.
 * The test intentionally does not apply a migration to educore.
 */

use EduCore\Modules\Operations\Audit\AuditPolicyRegistry;

$root = dirname(__DIR__);
$migrationPath = $root . '/database/migrations/20260730_staff_hr_attendance_reporting.php';
$registryPath = $root . '/src/Modules/Operations/Audit/AuditPolicyRegistry.php';
$source = is_file($migrationPath) ? (string) file_get_contents($migrationPath) : '';
$failures = 0;

$assert = static function (bool $condition, string $message) use (&$failures): void {
    if (!$condition) {
        fwrite(STDERR, "FAIL: {$message}\n");
        ++$failures;
    }
};
$contains = static function (string $needle, string $haystack, string $message) use ($assert): void {
    $assert(str_contains($haystack, $needle), $message);
};

$assert($source !== '', 'attendance reporting migration exists and is readable');
if ($source !== '') {
    $migration = require $migrationPath;
    $assert(is_callable($migration), 'attendance reporting migration returns a callable');
    foreach ([
        'staff_attendance_report_projection_runs',
        'staff_attendance_report_aggregates',
    ] as $table) {
        $contains("CREATE TABLE `{$table}`", $source, "report migration creates {$table}");
    }
    foreach ([
        'uk_staff_attendance_report_projection_idempotency',
        'uk_staff_attendance_report_aggregate_run_key',
        'uk_staff_attendance_report_aggregate_current',
        'idx_staff_attendance_report_aggregate_range',
        'idx_staff_attendance_report_aggregate_dimensions',
        'idx_staff_attendance_report_aggregate_assignment',
    ] as $index) {
        $contains("`{$index}`", $source, "report schema defines {$index}");
    }
    foreach ([
        "`granularity` ENUM('monthly','annual','range')",
        '`aggregate_key` CHAR(64) NOT NULL',
        '`source_fingerprint` CHAR(64) NOT NULL',
        '`is_current` TINYINT(1) NOT NULL DEFAULT 1',
        '`current_aggregate_key` CHAR(64) GENERATED ALWAYS AS',
        '`supersedes_id` BIGINT NULL',
        '`eligible_workdays` INT UNSIGNED NOT NULL DEFAULT 0',
        '`approved_permission_days` INT UNSIGNED NOT NULL DEFAULT 0',
        '`mission_days` INT UNSIGNED NOT NULL DEFAULT 0',
        '`leave_days` INT UNSIGNED NOT NULL DEFAULT 0',
        '`reason_summary` JSON NULL',
        'fk_staff_attendance_report_aggregate_run',
        'fk_staff_attendance_report_aggregate_supersedes',
        'chk_staff_attendance_report_aggregate_days',
        'chk_staff_attendance_report_aggregate_minutes',
    ] as $contract) {
        $contains($contract, $source, "report schema preserves {$contract}");
    }
    $contains('information_schema.TABLES', $source, 'report migration checks table existence before DDL');
    $contains('Rollback in an isolated environment', $source, 'report migration documents isolated rollback only');
    $assert(!str_contains($source, 'ON DELETE CASCADE'), 'report projection history cannot cascade-delete');
    $assert(
        preg_match('/\$db->exec\s*\(\s*[\'\"]\s*(?:DROP|TRUNCATE|ALTER)\b/i', $source) !== 1,
        'report migration is additive and non-destructive'
    );
}

$assert(is_file($registryPath), 'audit policy registry exists');
if (is_file($registryPath)) {
    require_once $registryPath;
    foreach (['staff_attendance_report_projection_runs', 'staff_attendance_report_aggregates'] as $table) {
        $assert(AuditPolicyRegistry::isRegisteredTable($table), "{$table} is registered before reporting writes");
        $assert(!AuditPolicyRegistry::allowsDirectUndo($table), "{$table} uses projection-safe rollback instead of direct undo");
    }
}

if ($failures > 0) {
    fwrite(STDERR, "{$failures} attendance reporting schema contract failure(s).\n");
    exit(1);
}

echo "Staff-HR attendance reporting schema contracts passed.\n";
