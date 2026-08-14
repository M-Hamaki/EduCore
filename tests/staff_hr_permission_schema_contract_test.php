<?php

declare(strict_types=1);

/**
 * Database-free contract for the additive Staff permission/quota migration.
 * Guarded MariaDB behavior is exercised separately in the integration test.
 */

use EduCore\Modules\Operations\Audit\AuditPolicyRegistry;

$root = dirname(__DIR__);
$migrationPath = $root . '/database/migrations/20260730_staff_hr_permissions_quota.php';
$auditRegistryPath = $root . '/src/Modules/Operations/Audit/AuditPolicyRegistry.php';
$source = is_file($migrationPath) ? (string) file_get_contents($migrationPath) : '';
$failures = 0;
$quote = chr(96);

$assert = static function (bool $condition, string $message) use (&$failures): void {
    if (!$condition) {
        fwrite(STDERR, "FAIL: {$message}\n");
        ++$failures;
    }
};
$assertContains = static function (string $needle, string $haystack, string $message) use ($assert): void {
    $assert(str_contains($haystack, $needle), $message);
};
$assertNotContains = static function (string $needle, string $haystack, string $message) use ($assert): void {
    $assert(!str_contains($haystack, $needle), $message);
};

$assert($source !== '', 'permission quota migration exists and is readable');
if ($source !== '') {
    $migration = require $migrationPath;
    $assert(is_callable($migration), 'permission quota migration returns a callable');

    $tables = [
        'staff_permission_types',
        'staff_permission_policy_versions',
        'staff_permission_policy_scopes',
        'staff_permission_requests',
        'staff_permission_request_periods',
        'staff_permission_quota_accounts',
        'staff_permission_quota_movements',
    ];
    foreach ($tables as $table) {
        $assertContains(
            'CREATE TABLE ' . $quote . $table . $quote,
            $source,
            "migration creates {$table}"
        );
    }

    foreach ([
        'uk_staff_permission_type_code',
        'uk_staff_permission_policy_version',
        'uk_staff_permission_policy_supersedes',
        'uk_staff_permission_policy_scope_start',
        'uk_staff_permission_request_create_idempotency',
        'uk_staff_permission_request_submission_idempotency',
        'uk_staff_permission_request_period',
        'uk_staff_permission_quota_account',
        'uk_staff_permission_quota_movement_idempotency',
        'uk_staff_permission_quota_movement_logical',
    ] as $index) {
        $assertContains($quote . $index . $quote, $source, "permission schema defines {$index}");
    }

    foreach ([
        $quote . 'coverage_behavior' . $quote . " ENUM('late_arrival','early_leave','mission','none')",
        $quote . 'requires_custom_label' . $quote . ' TINYINT(1) NOT NULL DEFAULT 0',
        $quote . 'allow_retroactive' . $quote . ' TINYINT(1) NOT NULL DEFAULT 0',
        $quote . 'max_requests_per_month' . $quote . ' SMALLINT UNSIGNED NULL',
        $quote . 'max_minutes_per_request' . $quote . ' INT UNSIGNED NULL',
        $quote . 'max_minutes_per_month' . $quote . ' INT UNSIGNED NULL',
        $quote . 'min_notice_minutes' . $quote . ' INT UNSIGNED NOT NULL DEFAULT 0',
        $quote . 'retroactive_limit_days' . $quote . ' SMALLINT UNSIGNED NOT NULL DEFAULT 0',
        $quote . 'reserve_on_submit' . $quote . ' TINYINT(1) NOT NULL DEFAULT 1',
        $quote . 'scope_type' . $quote . " ENUM('global','org_unit','job_title','group','staff')",
        $quote . 'workflow_version_id' . $quote . ' BIGINT NULL',
        $quote . 'assignment_id' . $quote . ' BIGINT NULL',
        $quote . 'policy_snapshot' . $quote . ' JSON NULL',
        $quote . 'request_hash' . $quote . ' CHAR(64) NOT NULL',
        $quote . 'period_key' . $quote . ' CHAR(7) NOT NULL',
        $quote . 'reserved_count' . $quote . ' INT NOT NULL DEFAULT 0',
        $quote . 'consumed_count' . $quote . ' INT NOT NULL DEFAULT 0',
        $quote . 'reserved_minutes' . $quote . ' INT NOT NULL DEFAULT 0',
        $quote . 'consumed_minutes' . $quote . ' INT NOT NULL DEFAULT 0',
        $quote . 'movement_type' . $quote . " ENUM('reserve','consume','release','adjust','reverse')",
        $quote . 'quota_exception' . $quote . ' TINYINT(1) NOT NULL DEFAULT 0',
    ] as $field) {
        $assertContains($field, $source, "permission schema preserves {$field}");
    }

    foreach ([
        'chk_staff_permission_policy_dates',
        'chk_staff_permission_policy_month_limit',
        'chk_staff_permission_policy_reserve_when_limited',
        'chk_staff_permission_policy_override',
        'chk_staff_permission_request_window',
        'chk_staff_permission_request_minutes',
        'chk_staff_permission_request_submission_snapshot',
        'chk_staff_permission_request_period_key',
        'chk_staff_permission_quota_period_key',
        'chk_staff_permission_quota_counters',
        'chk_staff_permission_quota_movement_amount',
        'chk_staff_permission_quota_movement_exception',
    ] as $constraint) {
        $assertContains($quote . $constraint . $quote, $source, "permission schema guards {$constraint}");
    }

    foreach ([
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
    ] as $trigger) {
        $assertContains($trigger, $source, "permission schema owns {$trigger}");
    }

    foreach ([
        'Published permission policy versions are immutable',
        'Published permission policy scopes are immutable',
        'Permission requests must be created as drafts before submission',
        'Submitted permission request details are immutable',
        'Permission quota exception can only be recorded once during pending submission',
        'Submitted permission requests require complete monthly quota allocations',
        'Permission requests are retained; use a workflow status instead of deletion',
        'Quota movement must match the request, permission type, worker, and period account',
        'Quota movements can only follow a submitted permission request',
        'Quota movement must equal its monthly request allocation',
        'Permission quota movements are append-only',
    ] as $message) {
        $assertContains($message, $source, "permission schema has a clear invariant message: {$message}");
    }

    foreach ([
        'REFERENCES ' . $quote . 'users' . $quote,
        'REFERENCES ' . $quote . 'staff_assignments' . $quote,
        'REFERENCES ' . $quote . 'staff_approval_workflow_versions' . $quote,
        'REFERENCES ' . $quote . 'staff_approval_instances' . $quote,
    ] as $laterForeignKey) {
        $assertNotContains(
            $laterForeignKey,
            $source,
            "permission migration keeps cross-module {$laterForeignKey} as a validated scalar snapshot"
        );
    }
    $assertContains('information_schema.TABLES', $source, 'migration checks table existence before DDL');
    $assertContains('information_schema.TRIGGERS', $source, 'migration checks trigger existence before DDL');
    $assertContains(
        "request_state IN ('draft', 'withdrawn')",
        $source,
        'quota movements reject every unsubmitted request state'
    );
    $assertContains('Rollback in an isolated environment', $source, 'migration documents isolated rollback');
    $assert(
        preg_match('/\$db->exec\s*\(\s*[\'"]\s*(?:DROP|TRUNCATE|ALTER)\b/i', $source) !== 1,
        'permission migration remains additive and non-destructive'
    );
    $assertNotContains('ON DELETE CASCADE', $source, 'permission history cannot cascade-delete');
}

$auditSource = is_file($auditRegistryPath) ? (string) file_get_contents($auditRegistryPath) : '';
$assert($auditSource !== '', 'audit policy registry exists and is readable');
if ($auditSource !== '') {
    require_once $auditRegistryPath;
    foreach ([
        'staff_permission_types',
        'staff_permission_policy_versions',
        'staff_permission_policy_scopes',
        'staff_permission_requests',
        'staff_permission_request_periods',
        'staff_permission_quota_accounts',
        'staff_permission_quota_movements',
    ] as $table) {
        $assert(AuditPolicyRegistry::isRegisteredTable($table), "{$table} is registered fail-closed for audit policy");
        $assert(!AuditPolicyRegistry::allowsDirectUndo($table), "{$table} requires workflow/ledger rollback, not direct undo");
    }
    $redacted = AuditPolicyRegistry::redact([
        'reason' => 'private permission reason',
        'custom_label' => 'other private label',
        'attachment_ref' => 'storage/private/staff/permission.pdf',
        'policy_snapshot' => ['quota' => 120],
        'quota_exception_reason' => 'restricted exception',
        'status' => 'pending_approval',
    ], 'staff_permission_requests');
    foreach (['reason', 'custom_label', 'attachment_ref', 'policy_snapshot', 'quota_exception_reason'] as $sensitiveField) {
        $assert(($redacted[$sensitiveField] ?? null) === '[REDACTED]', "permission audit redacts {$sensitiveField}");
    }
    $assert(($redacted['status'] ?? null) === 'pending_approval', 'permission audit retains safe request status');
}

if ($failures > 0) {
    fwrite(STDERR, "{$failures} staff-HR permission schema contract failure(s).\n");
    exit(1);
}

echo "Staff-HR permission schema contracts passed.\n";
