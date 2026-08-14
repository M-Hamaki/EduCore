<?php

declare(strict_types=1);

$root = dirname(__DIR__);
$service = (string) file_get_contents($root . '/classes/StaffEmploymentLifecycleService.php');
$command = (string) file_get_contents($root . '/src/Modules/Staff/StaffProfileCommandService.php');
$audit = (string) file_get_contents($root . '/src/Modules/Operations/Audit/AuditService.php');
$policy = (string) file_get_contents($root . '/src/Modules/Operations/Audit/AuditPolicyRegistry.php');

$checks = [
    'employment_tables_are_registered' => strpos($policy, "'staff_status_history'") !== false
        && strpos($policy, "'staff_job_movements'") !== false,
    'replacement_engine_supports_delete_and_insert_snapshots' => strpos($audit, 'public function recordReplacement(') !== false
        && strpos($audit, 'UndoManager::logDelete(') !== false
        && strpos($audit, 'UndoManager::logInsert(') !== false,
    'replacement_engine_emits_one_correlated_activity' => strpos($audit, "ActivityLog::log('update'") !== false
        && strpos($audit, "'batch_id' => \$batchId") !== false,
    'both_employment_replacements_are_locked_and_audited' => strpos($service, 'staff_status_history WHERE user_id = ? FOR UPDATE') !== false
        && strpos($service, 'staff_job_movements WHERE user_id = ? FOR UPDATE') !== false
        && substr_count($service, 'auditReplacement(') === 3,
    'standalone_and_parent_transactions_are_supported' => substr_count($service, '$ownsTransaction = !$this->db->inTransaction()') === 2
        && substr_count($service, 'rollBack()') === 2,
    'empty_job_movement_replacement_does_not_escape_transaction' => strpos($service, 'if (!$latest) return') === false,
    'profile_save_reuses_one_undo_batch' =>
        strpos($service, '?string $batchId = null') !== false
        && strpos($service, "['summary' => \$description],\n            \$batchId") !== false
        && substr_count($command, '$undoBatchId') >= 5,
];

$failed = [];
foreach ($checks as $name => $passed) {
    echo $name . ':' . ($passed ? 'PASS' : 'FAIL') . PHP_EOL;
    if (!$passed) $failed[] = $name;
}
exit($failed ? 1 : 0);
