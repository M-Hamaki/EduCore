<?php

declare(strict_types=1);

$root = dirname(__DIR__);
$guard = (string) file_get_contents($root . '/src/Modules/Students/StudentOperationalGuard.php');
$attendanceService = (string) file_get_contents($root . '/src/Modules/Students/StudentAttendanceService.php');
$files = [
    'finance' => (string) file_get_contents($root . '/admin/fee_payments.php'),
    'attendance' => (string) file_get_contents($root . '/admin/attendance.php'),
    'transport' => (string) file_get_contents($root . '/admin/student_buses.php'),
    'transport_lists' => (string) file_get_contents($root . '/admin/bus_lists.php'),
    'transport_reports' => (string) file_get_contents($root . '/admin/bus_report.php'),
    'transport_statistics' => (string) file_get_contents($root . '/admin/transport_statistics.php'),
    'buses' => (string) file_get_contents($root . '/admin/buses.php'),
    'clinic' => (string) file_get_contents($root . '/admin/student_clinic.php'),
    'library' => (string) file_get_contents($root . '/admin/library.php'),
    'accounts' => (string) file_get_contents($root . '/admin/student_accounts.php'),
    'notifications' => (string) file_get_contents($root . '/admin/notifications.php'),
    'derived_lists' => (string) file_get_contents($root . '/src/Modules/Students/DerivedStudentListDataTableQuery.php'),
    'grade_entry' => (string) file_get_contents($root . '/teacher/assessment_marks.php'),
    'grade_review' => (string) file_get_contents($root . '/teacher/assessment_review.php'),
    'password_login' => (string) file_get_contents($root . '/classes/user.php'),
    'microsoft_sso' => (string) file_get_contents($root . '/classes/MicrosoftSSO.php'),
];

$checks = [
    'guard_requires_active_non_archived_student' => strpos($guard, "status = 'active' AND deleted_at IS NULL") !== false
        && strpos($guard, 'طالب مؤرشف') !== false,
    'finance_uses_guard_and_filters_archive' => strpos($files['finance'], 'StudentOperationalGuard') !== false
        && strpos($files['finance'], 'assertWritable($student_id)') !== false
        && substr_count($files['finance'], 'u.deleted_at IS NULL') >= 4,
    'attendance_uses_batch_guard' => strpos($files['attendance'], 'new StudentAttendanceService($db)') !== false
        && strpos($attendanceService, 'assertWritableMany($submittedIds)') !== false
        && substr_count($files['attendance'], 'u.deleted_at IS NULL') >= 2,
    'transport_uses_single_and_batch_guard' => strpos($files['transport'], 'assertWritable($studentId)') !== false
        && strpos($files['transport'], 'assertWritableMany($studentIds)') !== false,
    'transport_surfaces_hide_archived_students' => substr_count($files['transport_lists'], 'u.deleted_at IS NULL') >= 2
        && substr_count($files['transport_reports'], 'deleted_at IS NULL') >= 4
        && substr_count($files['transport_statistics'], 'deleted_at IS NULL') >= 8
        && substr_count($files['buses'], 'deleted_at IS NULL') >= 4,
    'clinic_blocks_new_archived_activity' => substr_count($files['clinic'], 'assertWritable($studentId)') >= 3,
    'library_blocks_new_loans_and_fines' => substr_count($files['library'], 'assertWritable($studentId)') >= 2,
    'accounts_hide_archived_students' => substr_count($files['accounts'], 'deleted_at IS NULL') >= 2,
    'notifications_hide_archived_students' => substr_count($files['notifications'], 'u.deleted_at IS NULL') >= 2,
    'derived_student_lists_hide_archived_students' => substr_count($files['derived_lists'], 'u.deleted_at IS NULL') >= 2,
    'grade_workflows_hide_archived_students' => substr_count($files['grade_entry'], 'u.deleted_at IS NULL') >= 2
        && substr_count($files['grade_review'], 'deleted_at IS NULL') >= 4,
    'password_login_rejects_archived_users' => strpos($files['password_login'], "WHERE username = ? AND status = 'active' AND deleted_at IS NULL") !== false,
    'microsoft_sso_rejects_archived_users' => substr_count($files['microsoft_sso'], "status = 'active' AND deleted_at IS NULL") >= 2,
];

$failed = false;
foreach ($checks as $name => $passed) {
    echo $name . ':' . ($passed ? 'PASS' : 'FAIL') . PHP_EOL;
    $failed = $failed || !$passed;
}

exit($failed ? 1 : 0);
