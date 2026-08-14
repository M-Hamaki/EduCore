<?php

declare(strict_types=1);

$root = dirname(__DIR__);
$busStaff = (string) file_get_contents($root . '/admin/bus_staff.php');
$buses = (string) file_get_contents($root . '/admin/buses.php');
$disciplinary = (string) file_get_contents($root . '/admin/disciplinary.php');
$staffShiftsController = (string) file_get_contents($root . '/admin/staff_shifts.php');
$staffShiftsService = (string) file_get_contents($root . '/src/Modules/Attendance/Application/LegacyStaffShiftCompatibilityService.php');
$staffShiftsAudit = (string) file_get_contents($root . '/src/Modules/Attendance/Infrastructure/OperationsAuditLegacyStaffShiftWriter.php');
$staffShiftsTransaction = (string) file_get_contents($root . '/src/Modules/Attendance/Infrastructure/PdoAttendanceTransactionManager.php');
$staffShifts = $staffShiftsController . PHP_EOL . $staffShiftsService . PHP_EOL . $staffShiftsAudit . PHP_EOL . $staffShiftsTransaction;
$gradesAjax = (string) file_get_contents($root . '/admin/grades_ajax.php');
$studentBuses = (string) file_get_contents($root . '/admin/student_buses.php');
$activitiesMonitor = (string) file_get_contents($root . '/admin/activities_monitor.php');
$aiSettings = (string) file_get_contents($root . '/admin/ai_settings.php');
$evaluationReports = (string) file_get_contents($root . '/admin/evaluation_reports.php');
$graduates = (string) file_get_contents($root . '/admin/graduates.php');
$biometricDevices = (string) file_get_contents($root . '/admin/biometric_devices.php');
$externalTeachers = (string) file_get_contents($root . '/admin/external_teachers.php');
$backupService = (string) file_get_contents($root . '/classes/EvaluationBackupService.php');
$adminProfile = (string) file_get_contents($root . '/admin/profile.php');
$passwordReveal = (string) file_get_contents($root . '/admin/ajax/get_password.php');
$passwordAuditStart = strpos($passwordReveal, '(new \\EduCore\\Modules\\Operations\\Audit\\AuditService');
$passwordAuditEnd = strpos($passwordReveal, '$db->commit()', $passwordAuditStart ?: 0);
$passwordAuditBlock = ($passwordAuditStart !== false && $passwordAuditEnd !== false)
    ? substr($passwordReveal, $passwordAuditStart, $passwordAuditEnd - $passwordAuditStart)
    : '';
$profileImport = (string) file_get_contents($root . '/admin/includes/profile_excel_import.php');
$staffPage = (string) file_get_contents($root . '/admin/staff.php');
$studentsPage = (string) file_get_contents($root . '/admin/students.php');
$biometricActions = (string) file_get_contents($root . '/admin/ajax/biometric_device_actions.php');
$biometricIdentityService = (string) file_get_contents($root . '/src/Modules/Staff/StaffBiometricIdentityService.php');
$attendanceService = (string) file_get_contents($root . '/classes/StaffAttendanceService.php');
$policy = (string) file_get_contents($root . '/src/Modules/Operations/Audit/AuditPolicyRegistry.php');

$checks = [
    'bus_staff_uses_central_audit_service' => substr_count($busStaff, 'recordInsert(') === 1
        && substr_count($busStaff, 'recordUpdate(') === 1
        && substr_count($busStaff, 'recordDelete(') === 1,
    'bus_staff_audit_is_inside_transactions' => substr_count($busStaff, 'beginTransaction()') === 2
        && substr_count($busStaff, '->commit()') === 2,
    'bus_staff_errors_are_not_disclosed' => strpos($busStaff, "'حدث خطأ أثناء الحفظ: ' . \$e->getMessage()") === false
        && strpos($busStaff, "'حدث خطأ أثناء الحذف: ' . \$e->getMessage()") === false,
    'bus_composite_writes_are_audited_without_partial_undo' => substr_count($buses, "'composite_restore_not_enabled'") === 2
        && strpos($buses, 'recordEvent(') !== false
        && strpos($buses, "UndoManager::logDelete('buses'") === false,
    'bus_status_change_uses_safe_direct_undo' => strpos($buses, "recordUpdate(") !== false
        && strpos($buses, "'bus', 'buses'") !== false,
    'bus_failures_are_not_disclosed' => strpos($buses, "'حدث خطأ أثناء الحفظ: ' . \$e->getMessage()") === false
        && strpos($buses, "'حدث خطأ أثناء الحذف: ' . \$e->getMessage()") === false
        && strpos($buses, "'حدث خطأ أثناء تغيير الحالة: ' . \$e->getMessage()") === false,
    'disciplinary_all_mutations_are_audited' => substr_count($disciplinary, 'recordInsert(') === 1
        && substr_count($disciplinary, 'recordUpdate(') === 1
        && substr_count($disciplinary, 'recordDelete(') === 1,
    'disciplinary_mutations_are_atomic' => substr_count($disciplinary, 'beginTransaction()') === 2
        && substr_count($disciplinary, '->commit()') === 2
        && substr_count($disciplinary, '->rollBack()') === 2,
    'disciplinary_table_has_explicit_policy' => strpos($policy, "'staff_disciplinary'") !== false,
    'default_shift_settings_are_audited_without_undo' => strpos($staffShifts, "recordEvent(") !== false
        && strpos($staffShifts, "'staff_shift_settings'") !== false,
    'staff_shift_overrides_cover_all_mutations' => strpos($staffShifts, 'recordInsert(') !== false
        && strpos($staffShifts, 'recordUpdate(') !== false
        && strpos($staffShifts, 'recordDelete(') !== false,
    'staff_shift_writes_are_atomic' => substr_count($staffShiftsService, '->transactional(') === 3
        && strpos($staffShiftsTransaction, 'beginTransaction()') !== false
        && strpos($staffShiftsTransaction, '->commit()') !== false
        && strpos($staffShiftsTransaction, '->rollBack()') !== false,
    'staff_shift_override_has_explicit_policy' => strpos($policy, "'staff_shift_overrides'") !== false,
    'class_grade_assignment_uses_atomic_direct_undo' => strpos($gradesAjax, 'function updateClassGrade(') !== false
        && strpos($gradesAjax, 'SELECT * FROM classes WHERE id = ? FOR UPDATE') !== false
        && strpos($gradesAjax, 'recordUpdate(') !== false
        && strpos($gradesAjax, 'beginTransaction()') !== false
        && strpos($gradesAjax, '->rollBack()') !== false,
    'class_grade_assignment_errors_are_not_disclosed' => strpos($gradesAjax, "'message' => \$e->getMessage()") === false,
    'student_bus_single_assignment_is_atomically_audited' => strpos($studentBuses, 'SELECT * FROM student_bus_assignments WHERE student_id = ?') !== false
        && strpos($studentBuses, "'student_bus_assignment'") !== false
        && strpos($studentBuses, 'EntityChangeTracker::diff(') !== false,
    'student_bus_bulk_assignment_has_change_evidence' => strpos($studentBuses, "'student_bus_assignment_bulk'") !== false
        && strpos($studentBuses, "'changes' => \$auditChanges") !== false
        && strpos($studentBuses, "'count' => \$count") !== false,
    'student_bus_assignments_block_unsafe_annual_undo' => substr_count($studentBuses, "'annual_assignment_restore_not_enabled'") === 2,
    'student_bus_writes_are_atomic' => substr_count($studentBuses, 'beginTransaction()') >= 2
        && substr_count($studentBuses, '->commit()') >= 2
        && substr_count($studentBuses, '->rollBack()') >= 2,
    'student_bus_errors_are_not_disclosed' => strpos($studentBuses, "'تعذر حفظ تعيين الحافلة: ' . \$e->getMessage()") === false,
    'activity_status_change_has_direct_undo' => strpos($activitiesMonitor, "'activity', 'activities'") !== false
        && strpos($activitiesMonitor, 'recordUpdate(') !== false
        && strpos($activitiesMonitor, 'FOR UPDATE') !== false,
    'activity_composite_delete_is_atomically_audited' => strpos($activitiesMonitor, "'composite_restore_not_enabled'") !== false
        && strpos($activitiesMonitor, "'deleted_result_count'") !== false
        && strpos($activitiesMonitor, 'recordEvent(') !== false,
    'activities_have_explicit_policy' => strpos($policy, "'activities'") !== false,
    'ai_settings_batch_is_atomically_audited' => strpos($aiSettings, "'settings_batch_restore_not_enabled'") !== false
        && strpos($aiSettings, 'EntityChangeTracker::diff(') !== false
        && strpos($aiSettings, 'beginTransaction()') !== false
        && strpos($aiSettings, '->rollBack()') !== false,
    'ai_settings_errors_are_not_disclosed' => strpos($aiSettings, "'تعذر حفظ إعدادات الذكاء الاصطناعي: ' . \$e->getMessage()") === false,
    'evaluation_bulk_delete_is_audited_as_one_batch' => strpos($evaluationReports, 'AuditContext::requestId()') !== false
        && strpos($evaluationReports, 'recordDelete(') !== false
        && strpos($evaluationReports, 'FOR UPDATE') !== false,
    'evaluation_bulk_delete_is_atomic' => strpos($evaluationReports, 'beginTransaction()') !== false
        && strpos($evaluationReports, '->commit()') !== false
        && strpos($evaluationReports, '->rollBack()') !== false,
    'evaluation_bulk_delete_errors_are_not_disclosed' => strpos($evaluationReports, '"خطأ: " . $e->getMessage()') === false,
    'graduation_cancellation_is_atomically_audited' => strpos($graduates, "'student_graduation'") !== false
        && strpos($graduates, "'business_reversal_not_direct_undo'") !== false
        && substr_count($graduates, 'FOR UPDATE') >= 2
        && strpos($graduates, 'recordEvent(') !== false,
    'graduation_errors_are_not_disclosed' => strpos($graduates, "'خطأ: ' . \$e->getMessage()") === false,
    'biometric_device_credentials_are_audit_only' => substr_count($biometricDevices, 'credential_bearing_') === 2
        && strpos($biometricDevices, 'EntityChangeTracker::diff(') !== false
        && strpos($biometricDevices, 'recordEvent(') !== false,
    'biometric_device_delete_captures_related_count' => strpos($biometricDevices, "'deleted_sync_log_count'") !== false
        && strpos($biometricDevices, 'SELECT COUNT(*) FROM biometric_sync_log') !== false,
    'biometric_identity_has_direct_undo' => strpos($biometricDevices, "'table' => 'staff_profiles'") !== false
        && strpos($biometricDevices, "'staff_biometric_identity'") !== false
        && strpos($biometricDevices, 'recordCompositeUpdate(') !== false,
    'biometric_mutations_are_atomic' => substr_count($biometricDevices, 'beginTransaction()') >= 3
        && substr_count($biometricDevices, '->commit()') >= 3
        && substr_count($biometricDevices, '->rollBack()') >= 3,
    'external_teacher_status_and_delete_use_engine' => strpos($externalTeachers, 'recordUpdate(') !== false
        && strpos($externalTeachers, 'recordDelete(') !== false
        && substr_count($externalTeachers, 'SELECT * FROM external_teachers WHERE id = ? FOR UPDATE') >= 3,
    'external_teacher_settings_are_atomic_audit_only' => strpos($externalTeachers, "'external_teacher_settings'") !== false
        && strpos($externalTeachers, "'settings_batch_restore_not_enabled'") !== false,
    'external_teacher_bulk_approval_is_one_undo_batch' => strpos($externalTeachers, 'recordCompositeUpdate(') !== false
        && strpos($externalTeachers, "'external_teacher_bulk_status'") !== false,
    'external_teacher_password_change_blocks_partial_undo' => strpos($externalTeachers, "'password_changed' => true") !== false
        && strpos($externalTeachers, "'credential_change_not_direct_undo'") !== false,
    'external_teacher_delete_has_credential_policy' => strpos($policy, "'external_teachers'") !== false
        && strpos($policy, "'users', 'school_emails', 'external_teachers'") !== false,
    'evaluation_backup_lifecycle_is_audited_by_owner' => substr_count($backupService, 'recordEvent(') === 3
        && strpos($backupService, "'restore_from_evaluation_backup'") !== false
        && strpos($backupService, "'restore_from_pre_restore_backup'") !== false
        && strpos($backupService, "'deleted_backup_not_restorable'") !== false,
    'evaluation_backup_delete_is_atomic' => substr_count($backupService, 'beginTransaction()') === 3
        && substr_count($backupService, '->commit()') === 3
        && substr_count($backupService, '->rollBack()') === 3,
    'admin_profile_update_is_atomic' => strpos($adminProfile, 'SELECT * FROM users WHERE id = ? FOR UPDATE') !== false
        && strpos($adminProfile, 'beginTransaction()') !== false
        && strpos($adminProfile, '->rollBack()') !== false,
    'admin_profile_password_change_blocks_partial_undo' => strpos($adminProfile, 'recordUpdate(') !== false
        && strpos($adminProfile, "'password_changed' => true") !== false
        && strpos($adminProfile, "'credential_change_not_direct_undo'") !== false,
    'password_reveal_uses_central_security_audit' => strpos($passwordReveal, "'security_view'") !== false
        && strpos($passwordReveal, "'password_revealed'") !== false
        && strpos($passwordReveal, "'encryption_key_rotated'") !== false
        && strpos($passwordReveal, 'recordEvent(') !== false,
    'password_reveal_does_not_put_plaintext_in_audit_details' => $passwordAuditBlock !== ''
        && strpos($passwordAuditBlock, '$plaintext') === false
        && strpos($passwordReveal, 'INSERT INTO audit_logs') === false,
    'password_rotation_and_security_audit_are_atomic' => strpos($passwordReveal, 'beginTransaction()') !== false
        && strpos($passwordReveal, '->commit()') !== false
        && strpos($passwordReveal, '->rollBack()') !== false,
    'profile_imports_audit_inside_owner_transaction' => substr_count($profileImport, 'recordEvent(') === 2
        && strpos($profileImport, "'student_profile_batch'") !== false
        && strpos($profileImport, "'staff_profile_batch'") !== false
        && substr_count($profileImport, "'bulk_profile_import_restore_not_enabled'") === 2,
    'profile_import_audit_keeps_compact_identity_evidence' => substr_count($profileImport, "'created_user_ids' => \$createdUserIds") === 2
        && substr_count($profileImport, "'source_keys' => array_keys(") === 2,
    'profile_import_pages_do_not_duplicate_owner_audit' => strpos($staffPage, "ActivityLog::logImport('staff'") === false
        && strpos($studentsPage, "ActivityLog::logImport('student'") === false,
    'biometric_import_owner_is_atomic_and_audited' => strpos($attendanceService, "'staff_biometric_batch'") !== false
        && strpos($attendanceService, "'biometric_import_reconciliation_required'") !== false
        && strpos($attendanceService, '$ownsTransaction') !== false,
    'biometric_sync_records_every_outcome' => substr_count($biometricActions, 'recordBiometricSyncOutcome(') >= 5
        && strpos($biometricActions, "'external_device_sync_not_undoable'") !== false,
    'biometric_bulk_mapping_is_one_undo_batch' => strpos($biometricActions, "'biometric_employee_mapping'") !== false
        && strpos($biometricActions, 'recordCompositeUpdate(') !== false
        && strpos($biometricIdentityService, 'FOR UPDATE') !== false
        && strpos($biometricActions, '->rollBack()') !== false,
];

$failed = [];
foreach ($checks as $name => $passed) {
    echo $name . ':' . ($passed ? 'PASS' : 'FAIL') . PHP_EOL;
    if (!$passed) {
        $failed[] = $name;
    }
}

exit($failed ? 1 : 0);
