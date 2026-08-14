<?php

declare(strict_types=1);

$root = dirname(__DIR__);
$service = is_file($root . '/classes/NewYearRolloverService.php')
    ? (string) file_get_contents($root . '/classes/NewYearRolloverService.php')
    : '';
$guard = (string) file_get_contents($root . '/classes/AcademicYearWriteGuard.php');
$migration = (string) file_get_contents($root . '/database/migrations/20260718_safe_year_rollover.php');
$setupPage = (string) file_get_contents($root . '/admin/academic_year_setup.php');
$guardedWriters = [
    'admin/assessment_calendar.php',
    'admin/assessment_component_week_rules.php',
    'admin/assessment_components.php',
    'admin/assessment_reports.php',
    'admin/assessment_schemes.php',
    'admin/assessment_student_locks.php',
    'admin/assessment_subject_assignments.php',
    'admin/assessment_teacher_assignments.php',
    'admin/assessment_windows.php',
    'admin/assessment_marks.php',
    'admin/ajax_assessment_mark_update.php',
    'admin/ajax_assessment_marks_bulk.php',
    'admin/attendance.php',
    'admin/fee_payments.php',
    'teacher/assessment_marks.php',
    'teacher/assessment_review.php',
    'teacher/attendance.php',
];
$allWritersGuarded = true;
foreach ($guardedWriters as $writer) {
    $contents = (string) file_get_contents($root . '/' . $writer);
    $allWritersGuarded = $allWritersGuarded
        && strpos($contents, 'AcademicYearWriteGuard') !== false
        && strpos($contents, 'assertWritable') !== false
        && strpos($contents, 'AcademicYearWriteGuard::assertWritable') === false;
}

$checks = [
    'manifest_tables_exist' => strpos($migration, 'CREATE TABLE academic_year_rollover_runs') !== false
        && strpos($migration, 'CREATE TABLE academic_year_rollover_items') !== false,
    'skipped_students_fail_closed' => strpos($service, 'students_skipped') !== false
        && strpos($service, 'لا يمكن تخطي أي طالب') !== false,
    'insert_ignore_is_forbidden' => strpos($service, 'INSERT IGNORE') === false,
    'historical_tables_are_verified_empty' => strpos($service, 'FORBIDDEN_TARGET_TABLES') !== false,
    'rollback_is_manifest_owned' => strpos($service, 'academic_year_rollover_items') !== false,
    'locked_year_guard_is_fail_closed' => strpos($guard, "\$year['locked'] ?? 0") !== false
        && strpos($guard, 'مقفل تاريخيًا') !== false,
    'admin_flow_is_fixed_and_password_confirmed' => strpos($setupPage, "yearSetupVerifyAdminPassword") !== false
        && strpos($setupPage, "hash_equals") !== false
        && strpos($setupPage, "create_recovery_backup") !== false
        && strpos($setupPage, "rollback_year_setup") !== false
        && strpos($setupPage, "activate_year_setup") !== false
        && strpos($setupPage, 'copy_') === false,
    'confirmed_annual_writers_use_lock_guard' => $allWritersGuarded,
];

foreach ($checks as $name => $passed) {
    echo $name . ':' . ($passed ? 'PASS' : 'FAIL') . PHP_EOL;
}
exit(in_array(false, $checks, true) ? 1 : 0);
