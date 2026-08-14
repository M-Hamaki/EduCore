<?php

declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

$options = getopt('', ['database:']);
$databaseName = trim((string) ($options['database'] ?? getenv('EDUCORE_TEST_DB_NAME') ?: ''));
if ($databaseName === '' || !preg_match('/^[A-Za-z0-9_]+_test$/', $databaseName) || $databaseName === 'educore') {
    fwrite(STDERR, "FAIL: --database must identify a clean isolated *_test database.\n");
    exit(2);
}

putenv('APP_ENV=test');
putenv('STAFF_HR_TEST_MARKER=integrated-staff-hr');
putenv('EDUCORE_TEST_DB_NAME=' . $databaseName);
$_ENV['APP_ENV'] = 'test';
$_ENV['STAFF_HR_TEST_MARKER'] = 'integrated-staff-hr';
$_ENV['EDUCORE_TEST_DB_NAME'] = $databaseName;
$_SERVER['APP_ENV'] = 'test';
$_SERVER['STAFF_HR_TEST_MARKER'] = 'integrated-staff-hr';
$_SERVER['EDUCORE_TEST_DB_NAME'] = $databaseName;

require_once __DIR__ . '/bootstrap_staff_hr.php';

try {
    $db = staffHrTestDatabase();
} catch (Throwable $e) {
    fwrite(STDERR, 'FAIL: isolated staff-HR database guard rejected the connection: ' . $e->getMessage() . "\n");
    exit(2);
}

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
$allTables = array_merge($organizationTables, $operationsTables);

$tableExists = static function (string $table) use ($db): bool {
    $stmt = $db->prepare(
        'SELECT COUNT(*) FROM information_schema.TABLES
         WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?'
    );
    $stmt->execute([$table]);

    return (int) $stmt->fetchColumn() === 1;
};
$columnExists = static function (string $table, string $column) use ($db): bool {
    $stmt = $db->prepare(
        'SELECT COUNT(*) FROM information_schema.COLUMNS
         WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?'
    );
    $stmt->execute([$table, $column]);

    return (int) $stmt->fetchColumn() === 1;
};
$indexColumns = static function (string $table, string $index) use ($db): string {
    $stmt = $db->prepare(
        'SELECT GROUP_CONCAT(COLUMN_NAME ORDER BY SEQ_IN_INDEX SEPARATOR ",")
         FROM information_schema.STATISTICS
         WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND INDEX_NAME = ?'
    );
    $stmt->execute([$table, $index]);

    return (string) $stmt->fetchColumn();
};

$preexisting = array_values(array_filter($allTables, $tableExists));
if ($preexisting !== []) {
    fwrite(
        STDERR,
        'FAIL: use a clean dedicated *_test schema; foundational tables already exist: '
        . implode(', ', $preexisting)
        . "\n"
    );
    exit(2);
}

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

$organizationMigration = require dirname(__DIR__)
    . '/database/migrations/20260730_staff_hr_organization_policy_foundation.php';
$operationsMigration = require dirname(__DIR__)
    . '/database/migrations/20260730_staff_hr_workflow_operations_foundation.php';
$assert(is_callable($organizationMigration), 'organization migration returns a callable');
$assert(is_callable($operationsMigration), 'operations migration returns a callable');

$rollbackOrder = [
    'staff_approval_decisions',
    'staff_approval_escalation_events',
    'staff_approval_assignees',
    'staff_approval_steps',
    'staff_approval_instances',
    'staff_approval_stages',
    'staff_approval_workflow_versions',
    'staff_approval_workflows',
    'notification_outbox',
    'user_notification_inbox',
    'staff_external_effects',
    'staff_hr_migration_exceptions',
    'staff_hr_migration_batches',
    'staff_hr_cutover_windows',
    'staff_policy_scopes',
    'staff_policy_versions',
    'staff_policy_definitions',
    'staff_delegations',
    'staff_policy_group_memberships',
    'staff_policy_groups',
    'staff_manager_assignments',
    'staff_assignments',
    'staff_job_titles',
    'staff_org_units',
];

$ownsFoundationSchema = true;
try {
    $organizationMigration($db);
    $operationsMigration($db);

    foreach ($allTables as $table) {
        $assert($tableExists($table), "apply creates {$table}");
    }

    $tableCountAfterFirstApply = count(array_filter($allTables, $tableExists));
    $organizationMigration($db);
    $operationsMigration($db);
    $tableCountAfterSecondApply = count(array_filter($allTables, $tableExists));
    $assert(
        $tableCountAfterFirstApply === count($allTables)
        && $tableCountAfterSecondApply === $tableCountAfterFirstApply,
        'reapplying both migrations is idempotent'
    );

    $assert(
        $indexColumns('staff_assignments', 'idx_staff_assignment_effective')
            === 'staff_user_id,valid_from,valid_to,assignment_kind,employment_status',
        'assignment effective-date index has the expected order'
    );
    $assert(
        $indexColumns('staff_policy_scopes', 'idx_staff_policy_scope_resolution')
            === 'scope_type,scope_id,valid_from,valid_to,status,priority',
        'policy scope resolution index has the expected order'
    );
    $assert(
        $indexColumns('notification_outbox', 'idx_notification_outbox_dispatch')
            === 'status,next_attempt_at,attempts',
        'notification dispatcher can claim due work efficiently'
    );
    $assert(
        $indexColumns('staff_hr_migration_batches', 'uk_staff_hr_migration_idempotency')
            === 'idempotency_key',
        'migration batches enforce idempotency'
    );

    foreach ([
        ['staff_policy_versions', 'rules_json'],
        ['staff_approval_instances', 'snapshot_json'],
        ['user_notification_inbox', 'neutral_text'],
        ['notification_outbox', 'payload'],
        ['staff_external_effects', 'effect_key'],
        ['staff_hr_migration_batches', 'resume_token'],
        ['staff_hr_migration_exceptions', 'payload_hash'],
    ] as [$table, $column]) {
        $assert($columnExists($table, $column), "{$table}.{$column} exists");
    }

    $db->exec(
        "INSERT INTO staff_org_units (code, name, unit_type, valid_from)
         VALUES ('SCHOOL', 'Test School', 'school', '2026-01-01')"
    );
    $orgUnitId = (int) $db->lastInsertId();
    $selfParentRejected = false;
    try {
        $selfParent = $db->prepare(
            'UPDATE staff_org_units SET parent_id = ? WHERE id = ?'
        );
        $selfParent->execute([$orgUnitId, $orgUnitId]);
    } catch (Throwable $e) {
        $selfParentRejected = true;
    }
    $assert($selfParentRejected, 'organization unit cannot be its own parent');
    $db->exec(
        "INSERT INTO staff_job_titles (code, name, active_from)
         VALUES ('TEST_TITLE', 'Test Title', '2026-01-01')"
    );
    $jobTitleId = (int) $db->lastInsertId();
    $assignment = $db->prepare(
        "INSERT INTO staff_assignments
            (staff_user_id, org_unit_id, job_title_id, employment_status, valid_from)
         VALUES (?, ?, ?, 'active', '2026-01-01')"
    );
    $assignment->execute([900001, $orgUnitId, $jobTitleId]);

    $db->exec(
        "INSERT INTO staff_policy_definitions (policy_type, code, name)
         VALUES ('schedule', 'TEST_SCHEDULE', 'Test schedule')"
    );
    $policyId = (int) $db->lastInsertId();
    $policyVersion = $db->prepare(
        "INSERT INTO staff_policy_versions
            (policy_id, version_no, state, valid_from, rules_json)
         VALUES (?, 1, 'draft', '2026-01-01 00:00:00', '{}')"
    );
    $policyVersion->execute([$policyId]);
    $policyVersionId = (int) $db->lastInsertId();
    $policyScope = $db->prepare(
        "INSERT INTO staff_policy_scopes
            (policy_version_id, scope_type, scope_id, priority, valid_from)
         VALUES (?, 'global', 0, 0, '2026-01-01 00:00:00')"
    );
    $policyScope->execute([$policyVersionId]);

    $db->exec(
        "INSERT INTO staff_approval_workflows (code, name, resource_type)
         VALUES ('TEST_PERMISSION', 'Test permission workflow', 'permission_request')"
    );
    $workflowId = (int) $db->lastInsertId();
    $workflowVersion = $db->prepare(
        "INSERT INTO staff_approval_workflow_versions
            (workflow_id, version_no, state, valid_from)
         VALUES (?, 1, 'draft', '2026-01-01 00:00:00')"
    );
    $workflowVersion->execute([$workflowId]);
    $workflowVersionId = (int) $db->lastInsertId();
    $stage = $db->prepare(
        "INSERT INTO staff_approval_stages
            (workflow_version_id, sequence_no, name, resolver_type)
         VALUES (?, 1, 'Direct manager', 'direct_manager')"
    );
    $stage->execute([$workflowVersionId]);
    $stageId = (int) $db->lastInsertId();
    $instance = $db->prepare(
        "INSERT INTO staff_approval_instances
            (resource_type, resource_id, workflow_version_id, started_at, snapshot_json, idempotency_key)
         VALUES ('permission_request', 1001, ?, '2026-01-01 08:00:00', '{}', 'test-instance-1')"
    );
    $instance->execute([$workflowVersionId]);
    $instanceId = (int) $db->lastInsertId();
    $step = $db->prepare(
        "INSERT INTO staff_approval_steps
            (instance_id, stage_id, sequence_no, status, snapshot_json)
         VALUES (?, ?, 1, 'active', '{}')"
    );
    $step->execute([$instanceId, $stageId]);

    $inbox = $db->prepare(
        "INSERT INTO user_notification_inbox
            (recipient_user_id, event_key, idempotency_key, neutral_text, secure_route, metadata_json)
         VALUES (900002, 'staff.request.submitted:1001', 'test-notify-1', 'لديك معاملة جديدة.', '/staff/request.php?id=1001', '{}')"
    );
    $inbox->execute();
    $inboxId = (int) $db->lastInsertId();
    $outbox = $db->prepare(
        "INSERT INTO notification_outbox
            (inbox_id, event_key, recipient_user_id, idempotency_key, payload)
         VALUES (?, 'staff.request.submitted:1001', 900002, 'test-notify-1', '{}')"
    );
    $outbox->execute([$inboxId]);

    $duplicateNotificationRejected = false;
    try {
        $inbox->execute();
        $duplicateNotificationRejected = $inbox->rowCount() === 0;
    } catch (Throwable $e) {
        $duplicateNotificationRejected = true;
    }
    $assert($duplicateNotificationRejected, 'notification occurrence is idempotent per recipient');

    $db->exec(
        "INSERT INTO staff_external_effects
            (effect_key, idempotency_key, resource_type, resource_id, target_module, fact_type, units, effective_period)
         VALUES ('effect:test:1', 'effect-idempotency:test:1', 'permission_request', 1001, 'Finance', 'uncovered_late_minutes', 15, '2026-01')"
    );
    $db->exec(
        "INSERT INTO staff_hr_cutover_windows
            (opened_at, write_mode, approved_by, idempotency_key)
         VALUES ('2026-01-01 00:00:00', 'capture', 900003, 'test-cutover-1')"
    );
    $cutoverId = (int) $db->lastInsertId();
    $migrationBatch = $db->prepare(
        "INSERT INTO staff_hr_migration_batches
            (migration_key, started_at, status, idempotency_key, cutover_window_id)
         VALUES ('test-foundation', '2026-01-01 00:01:00', 'running', 'test-migration-1', ?)"
    );
    $migrationBatch->execute([$cutoverId]);
    $batchId = (int) $db->lastInsertId();
    $migrationException = $db->prepare(
        "INSERT INTO staff_hr_migration_exceptions
            (batch_id, source_type, source_key, reason_code, payload_hash)
         VALUES (?, 'staff_profiles', 'legacy:1', 'AMBIGUOUS_UNIT', ?)"
    );
    $migrationException->execute([$batchId, hash('sha256', 'test-payload')]);
} catch (Throwable $e) {
    $recordFailure('migration apply/invariant exercise failed: ' . $e->getMessage());
} finally {
    if ($ownsFoundationSchema) {
        foreach ($rollbackOrder as $table) {
            try {
                $db->exec('DROP TABLE IF EXISTS `' . $table . '`');
            } catch (Throwable $e) {
                $recordFailure("rollback could not drop {$table}: " . $e->getMessage());
            }
        }
    }
}

foreach ($allTables as $table) {
    $assert(!$tableExists($table), "rollback removes only owned foundation table {$table}");
}

if ($failures > 0) {
    fwrite(STDERR, "{$failures} staff-HR foundation schema integration failure(s).\n");
    exit(1);
}

echo "Staff-HR foundation migration apply/idempotency/rollback passed on {$databaseName}.\n";
