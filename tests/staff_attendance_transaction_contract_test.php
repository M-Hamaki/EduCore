<?php

declare(strict_types=1);

$page = (string) file_get_contents(dirname(__DIR__) . '/admin/staff_attendance.php');
$deleteStart = strpos($page, "if (isset(\$_POST['delete_attendance']))");
$deleteEnd = strpos($page, '$redirectQuery = [', $deleteStart ?: 0);
$workflow = $deleteStart !== false && $deleteEnd !== false
    ? substr($page, $deleteStart, $deleteEnd - $deleteStart)
    : '';

$begin = strpos($workflow, '$db->beginTransaction();');
$delete = strpos($workflow, 'deleteAttendanceByIdWithAudit(');
$commit = strpos($workflow, '$db->commit();');
$rollback = strpos($workflow, '$db->rollBack();');

$results = [
    'delete_workflow_found' => $workflow !== '',
    'delete_and_audit_are_atomic' => $begin !== false && $begin < $delete && $delete < $commit,
    'delete_failure_rolls_back' => $rollback !== false && $commit < $rollback,
    'failure_message_preserves_no_partial_write' => strpos($workflow, 'لم يتم حفظ أي تغيير') !== false,
];

$failed = false;
foreach ($results as $name => $passed) {
    echo $name . ':' . ($passed ? 'PASS' : 'FAIL') . PHP_EOL;
    $failed = $failed || !$passed;
}

exit($failed ? 1 : 0);
