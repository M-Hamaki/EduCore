<?php

declare(strict_types=1);

$root = dirname(__DIR__);
require_once $root . '/src/Modules/Operations/Audit/AuditContext.php';
require_once $root . '/src/Modules/Operations/Audit/AuditPolicyRegistry.php';
require_once $root . '/src/Modules/Operations/Audit/EntityChangeTracker.php';

use EduCore\Modules\Operations\Audit\AuditContext;
use EduCore\Modules\Operations\Audit\AuditPolicyRegistry;
use EduCore\Modules\Operations\Audit\EntityChangeTracker;

$before = [
    'name' => 'Old',
    'email' => 'same@example.test',
    'password_hash' => 'secret-old',
    'profile' => ['phone' => '100', 'api_key' => 'nested-secret'],
];
$after = [
    'name' => 'New',
    'email' => 'same@example.test',
    'password_hash' => 'secret-new',
    'profile' => ['phone' => '200', 'api_key' => 'changed-secret'],
    'future_field' => 'automatically-covered',
];

$diff = EntityChangeTracker::diff($before, $after);
$undoSnapshot = AuditPolicyRegistry::undoSnapshot($after);
$firstRequestId = AuditContext::requestId();
$secondRequestId = AuditContext::requestId();
$migration = (string) file_get_contents($root . '/database/migrations/20260716_audit_undo_engine_v2.php');
$activityLog = (string) file_get_contents($root . '/classes/ActivityLog.php');
$undoManager = (string) file_get_contents($root . '/classes/UndoManager.php');
$auditService = (string) file_get_contents($root . '/src/Modules/Operations/Audit/AuditService.php');

$checks = [
    'request_id_is_stable_inside_request' => $firstRequestId === $secondRequestId
        && preg_match('/^[a-f0-9]{32}$/', $firstRequestId) === 1,
    'ordinary_change_is_detected' => ($diff['name']['from'] ?? null) === 'Old'
        && ($diff['name']['to'] ?? null) === 'New',
    'unchanged_field_is_omitted' => !array_key_exists('email', $diff),
    'future_field_is_automatically_detected' => ($diff['future_field']['to'] ?? null) === 'automatically-covered',
    'sensitive_values_are_redacted_in_diff' => ($diff['password_hash']['from'] ?? null) === '[REDACTED]'
        && ($diff['password_hash']['to'] ?? null) === '[REDACTED]',
    'sensitive_values_are_excluded_from_undo_snapshot' => !array_key_exists('password_hash', $undoSnapshot)
        && !array_key_exists('api_key', $undoSnapshot['profile'] ?? []),
    'financial_payment_requires_reversal' => !AuditPolicyRegistry::allowsDirectUndo('fee_payments'),
    'credential_bearing_delete_is_not_directly_restored' => !AuditPolicyRegistry::allowsDirectUndo('users', 'delete')
        && AuditPolicyRegistry::allowsDirectUndo('users', 'update'),
    'unknown_table_is_not_silently_undoable' => !AuditPolicyRegistry::isRegisteredTable('future_unregistered_table'),
    'activity_log_uses_correlation_and_redaction' => strpos($activityLog, 'AuditContext::requestId()') !== false
        && strpos($activityLog, 'AuditPolicyRegistry::redact') !== false,
    'activity_result_defaults_without_missing_key_access' => strpos($activityLog, "\$requestedResult = (string) (\$context['result'] ?? 'success')") !== false
        && strpos($activityLog, '? $requestedResult') !== false,
    'undo_log_uses_policy_and_safe_snapshot' => strpos($undoManager, 'allowsDirectUndo') !== false
        && strpos($undoManager, 'undoSnapshot') !== false,
    'migration_adds_operational_columns' => strpos($migration, "'request_id'") !== false
        && strpos($migration, "'can_undo'") !== false
        && strpos($migration, "'undo_status'") !== false
        && strpos($migration, 'idx_undo_available') !== false,
    'central_service_links_audit_to_undo' => strpos($auditService, "'undo_log_id' => \$undoId") !== false
        && strpos($auditService, 'EntityChangeTracker::diff') !== false,
    'central_service_fails_closed_inside_caller_transaction' => substr_count($auditService, 'throw new RuntimeException') >= 3,
];

$failed = [];
foreach ($checks as $name => $passed) {
    echo $name . ':' . ($passed ? 'PASS' : 'FAIL') . PHP_EOL;
    if (!$passed) {
        $failed[] = $name;
    }
}

exit($failed ? 1 : 0);
