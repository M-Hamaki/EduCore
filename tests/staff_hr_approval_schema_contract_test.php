<?php

declare(strict_types=1);

/**
 * Database-free contract for the foundational approval workflow and delegation
 * schema. Stateful resolution and decisions are covered by the later workflow
 * tests; this test protects the durable evidence those services rely on.
 */

use EduCore\Modules\Operations\Audit\AuditPolicyRegistry;

$root = dirname(__DIR__);
$workflowMigrationPath = $root . '/database/migrations/20260730_staff_hr_workflow_operations_foundation.php';
$organizationMigrationPath = $root . '/database/migrations/20260730_staff_hr_organization_policy_foundation.php';
$auditRegistryPath = $root . '/src/Modules/Operations/Audit/AuditPolicyRegistry.php';
$workflowSource = is_file($workflowMigrationPath) ? (string) file_get_contents($workflowMigrationPath) : '';
$organizationSource = is_file($organizationMigrationPath) ? (string) file_get_contents($organizationMigrationPath) : '';
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

$assert($workflowSource !== '', 'approval workflow migration exists and is readable');
if ($workflowSource !== '') {
    $migration = require $workflowMigrationPath;
    $assert(is_callable($migration), 'approval workflow migration returns a callable');

    foreach ([
        'staff_approval_workflows',
        'staff_approval_workflow_versions',
        'staff_approval_stages',
        'staff_approval_instances',
        'staff_approval_steps',
        'staff_approval_assignees',
        'staff_approval_decisions',
        'staff_approval_escalation_events',
    ] as $table) {
        $assertContains(
            'CREATE TABLE ' . $quote . $table . $quote,
            $workflowSource,
            "approval migration creates {$table}"
        );
    }

    foreach ([
        $quote . 'resource_type' . $quote . ' VARCHAR(80) NOT NULL',
        $quote . 'status' . $quote . " ENUM('active','inactive','retired')",
        $quote . 'state' . $quote . " ENUM('draft','published','retired')",
        $quote . 'valid_from' . $quote . ' DATETIME(6) NOT NULL',
        $quote . 'valid_to' . $quote . ' DATETIME(6) NULL',
        $quote . 'published_by' . $quote . ' INT NULL',
        $quote . 'published_at' . $quote . ' DATETIME(6) NULL',
    ] as $field) {
        $assertContains($field, $workflowSource, "workflow template/version preserves {$field}");
    }

    foreach ([
        $quote . 'resolver_type' . $quote . " ENUM('direct_manager','admin_manager','named_users','role_scope')",
        $quote . 'resolver_config' . $quote . ' JSON NULL',
        $quote . 'decision_mode' . $quote . " ENUM('sequential','any_one','all','quorum')",
        $quote . 'sla_minutes' . $quote . ' INT UNSIGNED NULL',
        $quote . 'on_timeout' . $quote . " ENUM('fail_closed','escalate','reassign','expire')",
        $quote . 'self_approval_rule' . $quote . " ENUM('forbid','require_alternate','allow_explicit')",
        $quote . 'same_actor_rule' . $quote . " ENUM('forbid','merge','require_alternate')",
        $quote . 'quorum_count' . $quote . ' INT UNSIGNED NULL',
        $quote . 'tie_rule' . $quote . " VARCHAR(50) NOT NULL DEFAULT 'reject'",
        $quote . 'rejection_rule' . $quote . " VARCHAR(50) NOT NULL DEFAULT 'stop_workflow'",
    ] as $field) {
        $assertContains($field, $workflowSource, "approval stage preserves {$field}");
    }

    foreach ([
        $quote . 'snapshot_json' . $quote . ' JSON NOT NULL',
        $quote . 'lock_version' . $quote . ' INT UNSIGNED NOT NULL DEFAULT 1',
        $quote . 'idempotency_key' . $quote . ' VARCHAR(190) NOT NULL',
        $quote . 'current_sequence' . $quote . ' INT UNSIGNED NOT NULL DEFAULT 1',
        $quote . 'due_at' . $quote . ' DATETIME(6) NULL',
        $quote . 'assignment_snapshot' . $quote . ' JSON NOT NULL',
        $quote . 'relationship_kind' . $quote . ' VARCHAR(50) NOT NULL',
        $quote . 'acting_for_user_id' . $quote . ' INT NULL',
        $quote . 'decision' . $quote . " ENUM('approve','reject','abstain')",
        $quote . 'is_effective' . $quote . ' TINYINT(1) NOT NULL DEFAULT 1',
    ] as $field) {
        $assertContains($field, $workflowSource, "approval runtime evidence preserves {$field}");
    }

    foreach ([
        'uk_staff_approval_workflow_code',
        'uk_staff_approval_workflow_version',
        'uk_staff_approval_stage_sequence',
        'uk_staff_approval_instance_idempotency',
        'uk_staff_approval_step_sequence',
        'uk_staff_approval_assignee_step_actor',
        'uk_staff_approval_decision_idempotency',
        'uk_staff_approval_decision_actor',
    ] as $index) {
        $assertContains($quote . $index . $quote, $workflowSource, "approval schema defines {$index}");
    }

    foreach ([
        'chk_staff_approval_version_dates',
        'chk_staff_approval_version_publish',
        'chk_staff_approval_stage_sequence',
        'chk_staff_approval_stage_quorum',
        'chk_staff_approval_instance_sequence',
        'chk_staff_approval_instance_completion',
        'chk_staff_approval_step_sequence',
        'chk_staff_approval_decision_proxy',
    ] as $constraint) {
        $assertContains($quote . $constraint . $quote, $workflowSource, "approval schema guards {$constraint}");
    }

    foreach ([
        'FOREIGN KEY (' . $quote . 'workflow_id' . $quote . ') REFERENCES ' . $quote . 'staff_approval_workflows' . $quote,
        'FOREIGN KEY (' . $quote . 'workflow_version_id' . $quote . ') REFERENCES ' . $quote . 'staff_approval_workflow_versions' . $quote,
        'FOREIGN KEY (' . $quote . 'instance_id' . $quote . ') REFERENCES ' . $quote . 'staff_approval_instances' . $quote,
        'FOREIGN KEY (' . $quote . 'step_id' . $quote . ') REFERENCES ' . $quote . 'staff_approval_steps' . $quote,
    ] as $foreignKey) {
        $assertContains($foreignKey, $workflowSource, "approval history has {$foreignKey}");
    }

    $assertContains('information_schema.TABLES', $workflowSource, 'approval migration checks table existence before DDL');
    $assertContains('isolated', $workflowSource, 'approval migration documents isolated rollback');
    $assertNotContains('ON DELETE CASCADE', $workflowSource, 'approval evidence cannot cascade-delete');
    $assert(
        preg_match('/\$db->exec\s*\(\s*[\'\"]\s*(?:DROP|TRUNCATE|ALTER)\b/i', $workflowSource) !== 1,
        'approval migration remains additive and non-destructive'
    );
}

$assert($organizationSource !== '', 'organization/delegation migration exists and is readable');
if ($organizationSource !== '') {
    $migration = require $organizationMigrationPath;
    $assert(is_callable($migration), 'organization/delegation migration returns a callable');
    $assertContains('CREATE TABLE ' . $quote . 'staff_delegations' . $quote, $organizationSource, 'delegation table exists');
    foreach ([
        $quote . 'delegator_user_id' . $quote . ' INT NOT NULL',
        $quote . 'delegate_user_id' . $quote . ' INT NOT NULL',
        $quote . 'scope_type' . $quote . " ENUM('global','org_unit','group','staff','request_type')",
        $quote . 'scope_id' . $quote . ' INT NOT NULL DEFAULT 0',
        $quote . 'request_types' . $quote . ' JSON NULL',
        $quote . 'valid_from' . $quote . ' DATETIME(6) NOT NULL',
        $quote . 'valid_to' . $quote . ' DATETIME(6) NOT NULL',
        $quote . 'status' . $quote . " ENUM('draft','active','suspended','revoked','expired')",
    ] as $field) {
        $assertContains($field, $organizationSource, "delegation schema preserves {$field}");
    }
    foreach ([
        'uk_staff_delegation_scope_start',
        'idx_staff_delegation_delegate_effective',
        'idx_staff_delegation_delegator_effective',
        'chk_staff_delegation_not_self',
        'chk_staff_delegation_dates',
        'chk_staff_delegation_scope',
    ] as $invariant) {
        $assertContains($quote . $invariant . $quote, $organizationSource, "delegation schema guards {$invariant}");
    }
}

$auditSource = is_file($auditRegistryPath) ? (string) file_get_contents($auditRegistryPath) : '';
$assert($auditSource !== '', 'audit policy registry exists and is readable');
if ($auditSource !== '') {
    require_once $auditRegistryPath;
    foreach ([
        'staff_delegations',
        'staff_approval_workflows',
        'staff_approval_workflow_versions',
        'staff_approval_stages',
        'staff_approval_instances',
        'staff_approval_steps',
        'staff_approval_assignees',
        'staff_approval_decisions',
        'staff_approval_escalation_events',
    ] as $table) {
        $assert(AuditPolicyRegistry::isRegisteredTable($table), "{$table} is registered fail-closed for audit policy");
        $assert(!AuditPolicyRegistry::allowsDirectUndo($table), "{$table} requires workflow-safe correction, not direct undo");
    }
}

if ($failures > 0) {
    fwrite(STDERR, "{$failures} staff-HR approval schema contract failure(s).\n");
    exit(1);
}

echo "Staff-HR approval schema contracts passed.\n";
