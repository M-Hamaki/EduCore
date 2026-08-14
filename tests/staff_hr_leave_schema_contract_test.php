<?php

declare(strict_types=1);

$root = dirname(__DIR__);
$migrationPath = $root . '/database/migrations/20260730_staff_hr_leave_ledger.php';
$overrideMigrationPath = $root . '/database/migrations/20260808_staff_hr_leave_staffing_overrides.php';
$workflowLinkMigrationPath = $root . '/database/migrations/20260811_staff_hr_leave_workflow_link_guard.php';
$registryPath = $root . '/src/Modules/Operations/Audit/AuditPolicyRegistry.php';
$migration = (string) file_get_contents($migrationPath);
$overrideMigration = (string) file_get_contents($overrideMigrationPath);
$workflowLinkMigration = (string) file_get_contents($workflowLinkMigrationPath);

require_once $registryPath;

use EduCore\Modules\Operations\Audit\AuditPolicyRegistry;

$failures = 0;
$assert = static function (bool $condition, string $message) use (&$failures): void {
    if (!$condition) {
        fwrite(STDERR, "FAIL: {$message}\n");
        ++$failures;
    }
};

$tables = [
    'staff_leave_types',
    'staff_leave_policy_versions',
    'staff_leave_policy_scopes',
    'staff_leave_policy_blackouts',
    'staff_leave_requests',
    'staff_leave_request_days',
    'staff_leave_balance_accounts',
    'staff_leave_balance_movements',
    'staff_return_to_work_events',
];
foreach ($tables as $table) {
    $assert(str_contains($migration, 'CREATE TABLE ' . $table), $table . ' is created by the additive leave migration');
    $assert(AuditPolicyRegistry::isRegisteredTable($table), $table . ' is registered in the shared audit policy before use');
    $assert(!AuditPolicyRegistry::allowsDirectUndo($table, 'update'), $table . ' fails closed for direct undo');
}
$assert(
    str_contains($overrideMigration, 'CREATE TABLE staff_leave_staffing_overrides'),
    'staffing override decisions have a dedicated additive evidence table'
);
$assert(
    AuditPolicyRegistry::isRegisteredTable('staff_leave_staffing_overrides')
        && !AuditPolicyRegistry::allowsDirectUndo('staff_leave_staffing_overrides', 'update'),
    'staffing override evidence is registered and fails closed for direct undo'
);
$assert(
    str_contains($overrideMigration, 'uk_staff_leave_staffing_override_request_hash')
        && str_contains($overrideMigration, 'uk_staff_leave_staffing_override_idempotency'),
    'one immutable decision is bound to one request hash with replay-safe idempotency'
);
$assert(
    str_contains($overrideMigration, 'trg_staff_leave_staffing_override_guard_insert')
        && str_contains($overrideMigration, 'trg_staff_leave_staffing_override_no_update')
        && str_contains($overrideMigration, 'trg_staff_leave_staffing_override_no_delete'),
    'override evidence is draft-bound and database-protected as append-only'
);
$assert(
    str_contains($workflowLinkMigration, 'OLD.workflow_instance_id IS NULL')
        && str_contains($workflowLinkMigration, 'NEW.workflow_instance_id IS NOT NULL')
        && str_contains($workflowLinkMigration, "OLD.status = 'pending_approval'")
        && str_contains($workflowLinkMigration, "NEW.status = 'pending_approval'"),
    'approval workflow instance may be attached once without weakening submitted leave evidence'
);
$assert(
    str_contains($workflowLinkMigration, 'Submitted leave request evidence is immutable; create a successor')
        && str_contains($workflowLinkMigration, 'DROP TRIGGER IF EXISTS trg_staff_leave_request_guard_update'),
    'the corrective migration replaces the guard while retaining immutable successor semantics'
);

$assert(!str_contains($migration, 'ON DELETE CASCADE'), 'leave evidence schema has no cascading deletion path');
$assert(substr_count($migration, 'ON DELETE RESTRICT') >= 10, 'leave relationships protect historical evidence with restrictive foreign keys');
$assert(str_contains($migration, "unit ENUM('day','hour')"), 'leave types preserve day/hour unit semantics');
$assert(str_contains($migration, 'entitlement_period_type') && str_contains($migration, 'entitlement_period_anchor_mmdd') && str_contains($migration, 'chk_staff_leave_policy_period_anchor') && str_contains($migration, 'carry_limit_units')
    && str_contains($migration, 'allow_retroactive') && str_contains($migration, 'retroactive_limit_days'), 'policy versions retain entitlement, carry, expiry, and bounded retroactive policy snapshots');
$assert(str_contains($migration, 'minimum_available_staff') && str_contains($migration, 'max_absence_percentage'), 'scoped leave policy supports minimum staffing and absence-ratio controls');
$assert(str_contains($migration, 'staff_leave_policy_blackouts') && str_contains($migration, 'requires_override'), 'dated blackout windows and authorized override evidence are modeled');
$assert(str_contains($migration, "request_kind ENUM('leave','extension','early_return','cancellation')"), 'leave request lifecycle supports extensions, early return, and cancellation as explicit records');
$assert(str_contains($migration, 'policy_snapshot JSON') && str_contains($migration, 'workflow_instance_id'), 'submitted leave request freezes policy and workflow evidence');
$assert(str_contains($migration, 'requested_units DECIMAL(12,3)') && str_contains($migration, 'consumed_units DECIMAL(12,3)'), 'fractional leave units are represented exactly rather than as floats');
$assert(str_contains($migration, 'allocation_key CHAR(64) NOT NULL') && str_contains($migration, 'uk_staff_leave_request_day'), 'request-day allocation has a stable per-request uniqueness key');
$assert(str_contains($migration, 'DATE_SUB(DATE(request_from), INTERVAL 2 DAY)') && str_contains($migration, 'overnight leave allocation'), 'request-day guard preserves the source workday for cross-midnight shifts');
$assert(str_contains($migration, 'negative_balance_limit_units') && str_contains($migration, 'available_units >= (0 - negative_balance_limit_units)'), 'balance accounts reject negative availability except an explicit bounded policy allowance');
$assert(str_contains($migration, "movement_type ENUM('grant','accrue','reserve','consume','release','carry','expire','adjust','reverse')"), 'append-only movement ledger includes accrual, carry, expiry, and reversal operations');
$assert(str_contains($migration, 'uk_staff_leave_movement_idempotency') && str_contains($migration, 'uk_staff_leave_movement_logical'), 'movement replay and logical duplication are independently constrained');
$assert(str_contains($migration, 'trg_staff_leave_movement_no_update') && str_contains($migration, 'trg_staff_leave_movement_no_delete'), 'leave movements are database-protected as append-only');
$assert(str_contains($migration, 'trg_staff_leave_request_day_guard_insert') && str_contains($migration, 'Submitted leave request days are immutable'), 'request-day records cannot be changed after submission');
$assert(str_contains($migration, 'trg_staff_leave_policy_guard_update') && str_contains($migration, 'Published leave policy semantics are immutable'), 'published leave policies require successor versions for semantic changes');
$assert(str_contains($migration, 'trg_staff_leave_scope_guard_update') && str_contains($migration, 'trg_staff_leave_blackout_guard_update'), 'published scopes and blackout definitions are immutable');
$assert(str_contains($migration, 'staff_return_to_work_events') && str_contains($migration, 'uk_staff_return_event_idempotency'), 'return-to-work events are idempotent immutable evidence');
$assert(str_contains($migration, 'trg_staff_return_no_update') && str_contains($migration, 'trg_staff_return_no_delete'), 'return-to-work corrections require a successor event rather than mutation');
$assert(str_contains($migration, "WHERE TABLE_SCHEMA = DATABASE()"), 'migration remains idempotent through schema inspection only');

$leaveAudit = AuditPolicyRegistry::redact([
    'reason' => 'سبب خاص',
    'supporting_document_ref' => 'storage/private/medical.pdf',
    'medical_document_ref' => 'storage/private/return.pdf',
    'return_notes' => 'ملاحظة صحية',
    'policy_snapshot' => ['private' => 'data'],
    'requested_units' => 2.5,
], 'staff_leave_requests');
$assert(($leaveAudit['reason'] ?? null) === '[REDACTED]'
    && ($leaveAudit['supporting_document_ref'] ?? null) === '[REDACTED]'
    && ($leaveAudit['medical_document_ref'] ?? null) === '[REDACTED]'
    && ($leaveAudit['return_notes'] ?? null) === '[REDACTED]'
    && ($leaveAudit['policy_snapshot'] ?? null) === '[REDACTED]'
    && ($leaveAudit['requested_units'] ?? null) === 2.5, 'leave audit redacts sensitive reason/document/snapshot data while preserving aggregate units');
$overrideAudit = AuditPolicyRegistry::redact([
    'decision_reason' => 'سبب إداري خاص',
    'assessment_snapshot' => ['internal' => 'capacity'],
    'decision_outcome' => 'approved',
], 'staff_leave_staffing_overrides');
$assert(
    ($overrideAudit['decision_reason'] ?? null) === '[REDACTED]'
        && ($overrideAudit['assessment_snapshot'] ?? null) === '[REDACTED]'
        && ($overrideAudit['decision_outcome'] ?? null) === 'approved',
    'staffing override audit redacts the reason and assessment while preserving the outcome'
);

if ($failures > 0) {
    fwrite(STDERR, "{$failures} leave schema contract test failure(s).\n");
    exit(1);
}

echo "Staff-HR leave schema contracts passed.\n";
