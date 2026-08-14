<?php

declare(strict_types=1);

$root = dirname(__DIR__);
$source = (string) file_get_contents($root . '/classes/StaffLeaveService.php');
$policy = (string) file_get_contents($root . '/src/Modules/Operations/Audit/AuditPolicyRegistry.php');

$checks = [
    'leave_and_setting_tables_are_registered' => strpos($policy, "'staff_leaves'") !== false
        && strpos($policy, "'settings'") !== false,
    'leave_crud_and_status_are_audited' => strpos($source, "recordInsert('staff_leave'") !== false
        && substr_count($source, 'recordUpdate(') >= 4
        && strpos($source, 'تغيير حالة إجازة موظف') !== false
        && strpos($source, 'recordDelete(') !== false,
    'leave_rows_are_locked' => strpos($source, 'SELECT * FROM staff_leaves WHERE id = ? FOR UPDATE') !== false,
    'leave_settings_use_one_audited_owner' => substr_count($source, 'saveAuditedSetting(') === 3
        && strpos($source, "recordInsert('setting'") !== false
        && strpos($source, "recordUpdate('setting'") !== false,
    'individual_and_bulk_balances_are_atomic' => strpos($source, 'SELECT * FROM staff_profiles WHERE user_id = ? FOR UPDATE') !== false
        && substr_count($source, 'updateAnnualLeaveBalance(') >= 2
        && substr_count($source, 'rollBack()') >= 6,
];

$failed = [];
foreach ($checks as $name => $passed) {
    echo $name . ':' . ($passed ? 'PASS' : 'FAIL') . PHP_EOL;
    if (!$passed) $failed[] = $name;
}
exit($failed ? 1 : 0);
