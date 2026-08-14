<?php

$root = dirname(__DIR__);
$page = (string) file_get_contents($root . '/admin/staff.php');
$service = (string) file_get_contents($root . '/src/Modules/Staff/StaffDeletionService.php');

$checks = [
    'page_delegates_delete' => strpos($page, '$staffDeletionService->delete(') !== false,
    'page_keeps_evaluation_message' => strpos(
        $page,
        'لا يمكن الحذف لوجود تقييمات مرتبطة'
    ) !== false,
    'service_uses_staff_boundary' => strpos(
        $service,
        "\$this->profiles->assertManageableStaff(\$userId, 'حذفه')"
    ) !== false,
    'service_keeps_evaluation_blocker' => strpos(
        $service,
        'SELECT COUNT(*) FROM evaluations WHERE teacher_id = ?'
    ) !== false,
    'service_keeps_role_cleanup' => strpos(
        $service,
        'removeAllSpecialistClassAssignments'
    ) !== false && strpos($service, 'removeAllClassAssignments') !== false
        && strpos($service, 'DELETE FROM teacher_subjects') !== false,
    'service_owns_atomicity' => strpos($service, 'beginTransaction()') !== false
        && strpos($service, '->commit()') !== false
        && strpos($service, '->rollBack()') !== false,
    'service_keeps_audit_and_undo' => strpos($service, 'ActivityLog::logDelete(') !== false
        && strpos($service, 'UndoManager::logDelete(') !== false,
    'service_does_not_read_superglobals' => strpos($service, '$_POST') === false
        && strpos($service, '$_SESSION') === false,
];

foreach ($checks as $name => $passed) {
    echo $name . ':' . ($passed ? 'PASS' : 'FAIL') . PHP_EOL;
}

exit(in_array(false, $checks, true) ? 1 : 0);
