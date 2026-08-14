<?php

declare(strict_types=1);

$root = dirname(__DIR__);
$source = (string) file_get_contents($root . '/classes/StaffPermissionService.php');
$policy = (string) file_get_contents($root . '/src/Modules/Operations/Audit/AuditPolicyRegistry.php');

$checks = [
    'permission_table_is_registered_for_undo' => strpos($policy, "'staff_permissions'") !== false,
    'permission_mutations_are_transactional' => substr_count($source, 'beginTransaction()') >= 3
        && substr_count($source, 'rollBack()') >= 3,
    'permission_rows_are_locked' => strpos($source, 'SELECT * FROM staff_permissions WHERE id = ? FOR UPDATE') !== false,
    'permission_create_update_delete_are_audited' => strpos($source, 'recordInsert(') !== false
        && substr_count($source, 'recordUpdate(') >= 2
        && strpos($source, 'recordDelete(') !== false,
    'audit_is_written_before_commit' => strrpos($source, 'recordUpdate(') < strrpos($source, 'commit()'),
];

$failed = [];
foreach ($checks as $name => $passed) {
    echo $name . ':' . ($passed ? 'PASS' : 'FAIL') . PHP_EOL;
    if (!$passed) $failed[] = $name;
}
exit($failed ? 1 : 0);
