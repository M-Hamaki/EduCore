<?php

declare(strict_types=1);

$source = (string) file_get_contents(dirname(__DIR__) . '/admin/staff_accounts.php');
$saveRoleStart = strpos($source, "if (\$action === 'save_role')");
$saveRoleEnd = strpos($source, '// جلب العامل المستهدف', $saveRoleStart ?: 0);
$workflow = $saveRoleStart !== false && $saveRoleEnd !== false
    ? substr($source, $saveRoleStart, $saveRoleEnd - $saveRoleStart)
    : '';

$begin = strpos($workflow, '$db->beginTransaction();');
$roleWrite = min(array_filter([
    strpos($workflow, 'UPDATE staff_roles'),
    strpos($workflow, 'INSERT INTO staff_roles'),
], static fn($position) => $position !== false));
$pageDelete = strpos($workflow, 'DELETE FROM staff_role_pages');
$auditDb = strpos($workflow, 'ActivityLog::setDb($db);');
$auditFailure = strpos($workflow, 'if (!$logged)');
$commit = strpos($workflow, '$db->commit();');
$rollback = strpos($workflow, '$db->rollBack();');

$results = [
    'save_role_workflow_found' => $workflow !== '',
    'transaction_begins_before_role_write' => $begin !== false && $begin < $roleWrite,
    'page_replacement_inside_transaction' => $pageDelete !== false && $begin < $pageDelete && $pageDelete < $commit,
    'activity_log_shares_connection' => $auditDb !== false && $auditDb < $begin,
    'audit_failure_prevents_commit' => $auditFailure !== false && $auditFailure < $commit,
    'failure_rolls_back' => $rollback !== false && $commit < $rollback,
];

$failed = false;
foreach ($results as $name => $passed) {
    echo $name . ':' . ($passed ? 'PASS' : 'FAIL') . PHP_EOL;
    $failed = $failed || !$passed;
}

exit($failed ? 1 : 0);
