<?php

$root = dirname(__DIR__);
$page = (string) file_get_contents($root . '/admin/staff.php');
$service = (string) file_get_contents($root . '/src/Modules/Staff/StaffProfileCommandService.php');

$checks = [
    'page_delegates_create' => strpos($page, '$staffProfileCommandService->create(') !== false,
    'page_delegates_update' => strpos($page, '$staffProfileCommandService->update(') !== false,
    'page_owns_redirects' => strpos($page, 'header("Location: staff.php') !== false,
    'page_no_longer_owns_profile_transaction' => strpos(
        substr($page, 0, strpos($page, '// ===== رفع صورة الملف الشخصي للموظف =====')),
        'beginTransaction('
    ) === false,
    'service_owns_atomicity' => substr_count($service, 'beginTransaction()') === 2
        && substr_count($service, '->commit()') === 2
        && substr_count($service, '->rollBack()') === 2,
    'service_keeps_optimistic_lock' => strpos($service, "SELECT updated_at FROM staff_profiles") !== false
        && strpos($service, "record_version") !== false,
    'service_uses_target_boundary' => strpos(
        $service,
        "\$this->profiles->assertManageableStaff(\$userId, 'تعديله')"
    ) !== false,
    'service_keeps_audit_and_undo' => strpos($service, 'recordInsert(') !== false
        && strpos($service, 'recordCompositeUpdate(') !== false
        && strpos($service, 'auditAssignmentChanges(') !== false
        && strpos($service, "'teacher_subjects'") !== false,
    'service_owns_internal_employee_code' => strpos(
        $service,
        "\$profile['employee_code'] = \$this->users->generateEmployeeCode();"
    ) !== false
        && strpos($service, "preg_match('/^E\\d{8}\$/D'") !== false,
    'service_keeps_biometric_independent' => strpos(
        $service,
        'assertAvailableWithinTransaction('
    ) !== false
        && strpos($service, 'synchronizeWithinTransaction(') === false,
    'profile_image_cleanup_follows_transaction_outcome' => strpos($service, 'finalizeImageChange($imageChange, true)') !== false
        && strpos($service, 'finalizeImageChange($imageChange, false)') !== false
        && strpos($service, "\$committed ? (\$change['retired'] ?? null) : (\$change['created'] ?? null)") !== false,
    'service_does_not_read_superglobals' => strpos($service, '$_POST') === false
        && strpos($service, '$_FILES') === false
        && strpos($service, '$_SESSION') === false,
];

foreach ($checks as $name => $passed) {
    echo $name . ':' . ($passed ? 'PASS' : 'FAIL') . PHP_EOL;
}

exit(in_array(false, $checks, true) ? 1 : 0);
