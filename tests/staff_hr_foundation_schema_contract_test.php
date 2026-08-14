<?php

declare(strict_types=1);

/**
 * Source-level contract for the two integrated staff-HR foundation migrations.
 *
 * The isolated database integration test proves that MariaDB accepts the DDL;
 * this guard keeps table names, effective-date indexes, idempotency keys, and
 * append-only migration behavior from drifting silently.
 */
$root = dirname(__DIR__);
$migrationSource = static function (string $fileName) use ($root): string {
    $path = $root . '/database/migrations/' . $fileName;
    $source = file_get_contents($path);
    if ($source === false) {
        throw new RuntimeException('Unable to read migration: ' . $path);
    }

    $migration = require $path;
    if (!is_callable($migration)) {
        throw new RuntimeException('Staff-HR migration must return a callable: ' . $fileName);
    }

    return $source;
};

$organization = $migrationSource('20260730_staff_hr_organization_policy_foundation.php');
$operations = $migrationSource('20260730_staff_hr_workflow_operations_foundation.php');

$failures = 0;
$assertContains = static function (string $needle, string $haystack, string $message) use (&$failures): void {
    if (!str_contains($haystack, $needle)) {
        fwrite(STDERR, "FAIL: {$message}\n");
        ++$failures;
    }
};
$assertMatches = static function (string $pattern, string $haystack, string $message) use (&$failures): void {
    if (preg_match($pattern, $haystack) !== 1) {
        fwrite(STDERR, "FAIL: {$message}\n");
        ++$failures;
    }
};
$assertNotMatches = static function (string $pattern, string $haystack, string $message) use (&$failures): void {
    if (preg_match($pattern, $haystack) === 1) {
        fwrite(STDERR, "FAIL: {$message}\n");
        ++$failures;
    }
};

$organizationTables = [
    'staff_org_units',
    'staff_job_titles',
    'staff_assignments',
    'staff_manager_assignments',
    'staff_policy_groups',
    'staff_policy_group_memberships',
    'staff_delegations',
    'staff_policy_definitions',
    'staff_policy_versions',
    'staff_policy_scopes',
];
$operationsTables = [
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

foreach ($organizationTables as $table) {
    $assertContains("CREATE TABLE `{$table}`", $organization, "organization migration creates {$table}");
}
foreach ($operationsTables as $table) {
    $assertContains("CREATE TABLE `{$table}`", $operations, "operations migration creates {$table}");
}

foreach ([$organization, $operations] as $source) {
    $assertContains('information_schema.TABLES', $source, 'migration checks table existence before DDL');
    $assertNotMatches(
        '/\$db->exec\s*\(\s*[\'\"]\s*(?:DROP|TRUNCATE|ALTER)\b/i',
        $source,
        'foundation migration stays additive'
    );
}

foreach ([
    'idx_staff_org_unit_effective',
    'idx_staff_assignment_effective',
    'idx_staff_assignment_org_effective',
    'idx_staff_assignment_title_effective',
    'idx_staff_manager_subject_effective',
    'idx_staff_manager_actor_effective',
    'idx_staff_policy_group_member_effective',
    'idx_staff_delegation_delegate_effective',
    'uk_staff_policy_type_code',
    'uk_staff_policy_version_no',
    'uk_staff_policy_scope_start',
    'idx_staff_policy_scope_resolution',
] as $index) {
    $assertContains("`{$index}`", $organization, "organization migration defines {$index}");
}
$assertContains('chk_staff_manager_not_self', $organization, 'manager self-assignment is rejected');
$assertContains('chk_staff_delegation_not_self', $organization, 'self-delegation is rejected');
$assertContains('chk_staff_policy_scope_identity', $organization, 'global and targeted scopes have explicit identities');
$assertContains("`state` ENUM('draft','published','retired')", $organization, 'policy versions have immutable publication states');

foreach ([
    'uk_staff_approval_workflow_version',
    'uk_staff_approval_stage_sequence',
    'uk_staff_approval_instance_idempotency',
    'uk_staff_approval_step_sequence',
    'uk_staff_approval_decision_idempotency',
    'uk_staff_approval_decision_actor',
    'uk_user_notification_event_recipient',
    'uk_user_notification_idempotency',
    'idx_user_notification_recipient_state',
    'uk_notification_outbox_event_recipient',
    'uk_notification_outbox_idempotency',
    'idx_notification_outbox_dispatch',
    'uk_staff_external_effect_key',
    'uk_staff_external_effect_idempotency',
    'uk_staff_hr_cutover_idempotency',
    'uk_staff_hr_migration_idempotency',
    'uk_staff_hr_migration_exception',
] as $index) {
    $assertContains("`{$index}`", $operations, "operations migration defines {$index}");
}

$assertContains('`neutral_text` VARCHAR(500) NOT NULL', $operations, 'notification inbox stores neutral text');
$assertContains('`secure_route` VARCHAR(500) NOT NULL', $operations, 'notification inbox stores a secure route');
$assertContains('`payload` JSON NOT NULL', $operations, 'notification outbox stores a retry payload');
$assertContains('`attempts` INT UNSIGNED NOT NULL DEFAULT 0', $operations, 'retryable operations track attempts');
$assertContains('`resume_token` VARCHAR(500) NULL', $operations, 'migration batches can resume after interruption');
$assertContains('`checksum` CHAR(64) NULL', $operations, 'migration reconciliation stores a checksum');
$assertContains('ON DELETE RESTRICT', $operations, 'workflow and operations history cannot cascade-delete');

$allSource = $organization . "\n" . $operations;
$assertMatches('/CHECK \(`valid_to` IS NULL OR `valid_to` [>=]+ `valid_from`\)/', $allSource, 'effective ranges reject inverted dates');
$assertNotMatches('/REFERENCES\s+`users`/i', $allSource, 'foundation does not couple to a legacy user-table key shape');

if ($failures > 0) {
    fwrite(STDERR, "{$failures} staff-HR foundation schema contract failure(s).\n");
    exit(1);
}

echo "Staff-HR foundation schema contracts passed.\n";
