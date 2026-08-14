<?php

declare(strict_types=1);

$root = dirname(__DIR__);
$adapter = (string) file_get_contents($root . '/classes/NewYearWizard.php');
$service = (string) file_get_contents($root . '/classes/NewYearRolloverService.php');
$policy = (string) file_get_contents($root . '/src/Modules/Operations/Audit/AuditPolicyRegistry.php');

$checks = [
    'rollover_workflow_tables_are_registered' => strpos($policy, "'academic_year_rollover_runs'") !== false
        && strpos($policy, "'academic_year_rollover_items'") !== false
        && strpos($policy, "'recovery_backups'") !== false,
    'legacy_entrypoint_delegates_to_safe_service' => strpos($adapter, 'new NewYearRolloverService($db)') !== false
        && strpos($adapter, "options['backup_key']") !== false,
    'source_and_target_years_are_locked_in_order' => strpos($service, 'id IN (?, ?) ORDER BY id FOR UPDATE') !== false,
    'execution_is_atomic_and_one_audit_batch' => strpos($service, '$batchId = UndoManager::newBatchId()') !== false
        && strpos($service, '$this->db->beginTransaction()') !== false
        && strpos($service, '$this->db->commit()') !== false
        && strpos($service, 'recordEvent(') !== false,
    'generic_undo_cannot_bypass_manifest' => strpos($service, 'recordReplacement(') === false
        && strpos($service, "'direct_undo_available' => false") !== false
        && strpos($service, "'rollback_owner' => 'academic_year_rollover_manifest'") !== false,
    'rollback_is_manifest_owned_and_dependency_ordered' => strpos($service, 'academic_year_rollover_items') !== false
        && strpos($service, 'ORDER BY dependency_order DESC, id DESC FOR UPDATE') !== false,
    'verify_and_activate_are_separate_atomic_steps' => strpos($service, 'public function verifyRun') !== false
        && strpos($service, 'public function activate') !== false
        && strpos($service, "status = 'activated'") !== false,
];

$failed = [];
foreach ($checks as $name => $passed) {
    echo $name . ':' . ($passed ? 'PASS' : 'FAIL') . PHP_EOL;
    if (!$passed) $failed[] = $name;
}
exit($failed ? 1 : 0);
