<?php

declare(strict_types=1);

$root = dirname(__DIR__);
$service = (string) file_get_contents($root . '/src/Modules/Students/StudentAttendanceService.php');
$admin = (string) file_get_contents($root . '/admin/attendance.php');
$teacher = (string) file_get_contents($root . '/teacher/attendance.php');
$bootstrap = (string) file_get_contents($root . '/src/Modules/Students/bootstrap.php');
$alias = (string) file_get_contents($root . '/classes/StudentAttendanceService.php');

$checks = [
    'shared_service_is_loadable' => strpos($bootstrap, "'StudentAttendanceService.php'") !== false
        && strpos($alias, 'StudentAttendanceService::class') !== false,
    'admin_and_teacher_delegate' => strpos($admin, 'new StudentAttendanceService($db)') !== false
        && strpos($teacher, 'new StudentAttendanceService($db)') !== false,
    'legacy_destructive_replace_removed' => strpos($admin, 'DELETE FROM attendance') === false
        && strpos($teacher, 'DELETE FROM attendance') === false
        && strpos($service, 'DELETE FROM attendance') === false,
    'date_is_strict_and_year_bounded' => strpos($service, "createFromFormat('!Y-m-d'") !== false
        && strpos($service, "new DateTimeImmutable('today')") !== false
        && strpos($service, "SELECT start_date, end_date FROM academic_years") !== false,
    'request_must_match_locked_roster' => strpos($service, 'ORDER BY u.id FOR UPDATE') !== false
        && strpos($service, '$rosterIds !== $submittedIds') !== false,
    'historical_other_class_rows_fail_closed' => strpos($service, "(int) \$row['class_id'] !== \$classId") !== false
        && strpos($service, 'لم يتم استبداله لحماية التاريخ') !== false,
    'duplicate_attendance_rows_fail_closed' => strpos($service, 'توجد سجلات حضور مكررة') !== false
        && strpos($service, 'نتج سجل حضور مكرر') !== false,
    'attendance_is_upserted_without_touching_omitted_history' => strpos($service, 'UPDATE attendance SET class_id = ?') !== false
        && strpos($service, 'INSERT INTO attendance') !== false
        && strpos($service, 'WHERE attendance_date = ? AND student_id IN') !== false,
    'audit_and_undo_are_inside_transaction' => strpos($service, 'recordCompositeUpdate(') !== false
        && strpos($service, 'recordInsert(') !== false
        && strpos($service, 'if ($ownsTransaction) $this->db->commit();') !== false
        && strpos($service, 'recordCompositeUpdate(') < strpos($service, 'if ($ownsTransaction) $this->db->commit();'),
    'database_errors_are_not_exposed' => strpos($admin, 'Admin student attendance save failed:') !== false
        && strpos($teacher, 'Teacher student attendance save failed:') !== false
        && substr_count($admin . $teacher, 'لم يتم اعتماد أي تغيير جزئي') >= 2,
    'read_surfaces_validate_and_scope_history' => strpos($admin, "createFromFormat('!Y-m-d'") !== false
        && substr_count($admin . $teacher, 'u.deleted_at IS NULL') >= 3
        && strpos($teacher, '$attendanceYearSql') !== false
        && strpos($teacher, 'AND academic_year_id = ?') !== false,
    'malformed_payloads_fail_without_notices' => strpos($admin, 'if (!is_array($data))') !== false
        && strpos($admin, "(int) (\$data['class_id'] ?? 0)") !== false
        && strpos($teacher, "(int) (\$_POST['class_id'] ?? 0)") !== false
        && substr_count($service, 'if (!is_scalar(') >= 2,
];

foreach ($checks as $name => $passed) {
    echo $name . ':' . ($passed ? 'PASS' : 'FAIL') . PHP_EOL;
}

exit(in_array(false, $checks, true) ? 1 : 0);
