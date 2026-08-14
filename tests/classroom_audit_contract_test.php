<?php

declare(strict_types=1);

$root = dirname(__DIR__);
$source = (string) file_get_contents($root . '/classes/classroom.php');
$policy = (string) file_get_contents($root . '/src/Modules/Operations/Audit/AuditPolicyRegistry.php');

$checks = [
    'class_and_access_tables_are_registered' => strpos($policy, "'classes'") !== false
        && strpos($policy, "'user_class_access'") !== false,
    'class_crud_is_atomic_audited' => strpos($source, "recordInsert(") !== false
        && strpos($source, "recordUpdate(") !== false
        && strpos($source, "'summary' => 'حذف فصل وإسنادات العاملين'") !== false,
    'delete_locks_class_and_assignments' => strpos($source, "fetchRowForUpdate('classes'") !== false
        && strpos($source, 'user_class_access WHERE class_id = ? ORDER BY id FOR UPDATE') !== false,
    'staff_assignment_create_delete_are_audited' => strpos($source, "'class_staff_assignment'") !== false
        && strpos($source, 'إزالة موظف من فصل') !== false,
    'point_reset_snapshots_each_evaluation_in_one_event' => strpos($source, 'evaluations WHERE class_id = ? ORDER BY id FOR UPDATE') !== false
        && strpos($source, "'evaluation_count' => count(\$beforeRows)") !== false,
    'all_mutations_support_parent_transactions' => substr_count($source, '$ownsTransaction = !$this->conn->inTransaction()') >= 6,
];

$failed = [];
foreach ($checks as $name => $passed) {
    echo $name . ':' . ($passed ? 'PASS' : 'FAIL') . PHP_EOL;
    if (!$passed) $failed[] = $name;
}
exit($failed ? 1 : 0);
