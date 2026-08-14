<?php

declare(strict_types=1);

$root = dirname(__DIR__);
$permission = (string) file_get_contents($root . '/classes/StaffPermissionService.php');
$leave = (string) file_get_contents($root . '/classes/StaffLeaveService.php');
$discipline = (string) file_get_contents($root . '/admin/disciplinary.php');
$hrCenter = (string) file_get_contents($root . '/admin/hr_center.php');

$checks = [
    'legacy_permission_types' => strpos($permission, "['early_leave', 'late_arrival', 'errand']") !== false,
    'legacy_permission_statuses' => strpos($permission, "['pending', 'approved', 'rejected']") !== false,
    'legacy_permission_single_status_owner' => strpos($permission, 'changePermissionStatus(') !== false
        && strpos($permission, 'approved_by') !== false,
    'legacy_leave_types' => strpos($leave, "['regular', 'sick', 'casual', 'exceptional', 'other']") !== false,
    'legacy_leave_statuses' => strpos($leave, "['pending', 'approved', 'rejected']") !== false,
    'legacy_leave_balance_columns' => strpos($leave, 'annual_leave_balance') !== false
        && strpos($leave, 'leave_balance_notes') !== false,
    'legacy_hr_center_direct_actions' => strpos($hrCenter, 'changePermissionStatus(') !== false
        && strpos($hrCenter, 'changeLeaveStatus(') !== false,
    'legacy_discipline_direct_write_adapter_blocks_hard_delete' => strpos($discipline, 'INSERT INTO staff_disciplinary') !== false
        && strpos($discipline, 'UPDATE staff_disciplinary') !== false
        && strpos($discipline, "isset(\$_POST['delete_action'])") !== false
        && strpos($discipline, 'DELETE FROM staff_disciplinary') === false
        && strpos($discipline, 'لا يمكن حذف سجل تأديبي') !== false,
    'legacy_writes_are_transactional_and_audited' => strpos($permission, 'beginTransaction()') !== false
        && strpos($leave, 'beginTransaction()') !== false
        && strpos($discipline, 'AuditService') !== false,
];

$failed = [];
foreach ($checks as $name => $passed) {
    echo $name . ':' . ($passed ? 'PASS' : 'FAIL') . PHP_EOL;
    if (!$passed) {
        $failed[] = $name;
    }
}

exit($failed ? 1 : 0);
