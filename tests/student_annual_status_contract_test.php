<?php

declare(strict_types=1);

$root = dirname(__DIR__);
$migration = (string) file_get_contents($root . '/database/migrations/20260726_student_annual_statuses.php');
$enrollment = (string) file_get_contents($root . '/src/Modules/Students/StudentEnrollment.php');
$service = (string) file_get_contents($root . '/src/Modules/Students/StudentEnrollmentService.php');
$command = (string) file_get_contents($root . '/src/Modules/Students/StudentProfileCommandService.php');
$repository = (string) file_get_contents($root . '/src/Modules/Students/StudentProfileRepository.php');
$payload = (string) file_get_contents($root . '/src/Modules/Students/StudentProfilePayload.php');
$query = (string) file_get_contents($root . '/src/Modules/Students/StudentProfilePageQuery.php');
$listQuery = (string) file_get_contents($root . '/src/Modules/Students/StudentListPageQuery.php');
$history = (string) file_get_contents($root . '/classes/UserProfileStore.php');
$rollover = (string) file_get_contents($root . '/classes/NewYearRolloverService.php');
$setup = (string) file_get_contents($root . '/admin/academic_year_setup.php');
$form = (string) file_get_contents($root . '/src/Modules/Students/Presentation/profile_form.php');
$view = (string) file_get_contents($root . '/src/Modules/Students/Presentation/profile_view.php');
$discontinuedPage = (string) file_get_contents($root . '/admin/discontinued_students.php');
$studentDataTableEndpoint = (string) file_get_contents($root . '/admin/ajax_students_datatable.php');

$checks = [
    'schema_separates_registration_and_academic_status' =>
        strpos($migration, "ENUM('enrolled','transferred','discontinued','graduated','withdrawn')") !== false
        && strpos($migration, "ENUM('new','promoted','retained','graduated')") !== false
        && strpos($migration, 'idx_enroll_year_statuses') !== false,
    'annual_enrollment_write_persists_both_statuses' =>
        strpos($enrollment, 'academic_status') !== false
        && strpos($enrollment, 'VALUES (?, ?, ?, ?, ?, ?, ?, CURDATE())') !== false
        && strpos($service, 'normalizeAcademicStatus') !== false
        && strpos($service, "['new', 'promoted', 'retained', 'graduated']") !== false,
    'profile_command_uses_dual_status_lifecycle' =>
        substr_count($command, 'normalizeAcademicStatus(') === 2
        && substr_count($command, '$academicStatus,') >= 2
        && strpos($command, "academicStatus === 'graduated'") !== false
        && strpos($command, "empty(\$post['class_id'])") !== false
        && strpos($repository, 'END AS academic_status') !== false
        && strpos($payload, "'academic_status'") !== false,
    'profile_exposes_annual_status_and_path' =>
        strpos($form, 'الحالة والمسار الدراسي') !== false
        && strpos($form, 'foreach ($stages as $stageId => $stageName)') !== false
        && strpos($form, 'name="enrollment_status"') !== false
        && strpos($form, 'value="discontinued"') !== false
        && strpos($form, 'name="academic_status"') !== false
        && strpos($form, 'value="promoted"') !== false
        && strpos($form, 'value="retained"') !== false
        && strpos($form, 'value="graduated"') !== false
        && strpos($view, '$viewCurrentEnrollment') !== false
        && strpos($listQuery, 'c.academic_year_id = ?') !== false
        && strpos($service, 'الفصل المختار غير متاح في العام الدراسي الحالي') !== false,
    'academic_path_is_year_based' =>
        strpos($query, "'academic_history' =>") !== false
        && strpos($query, "'current_enrollment' =>") !== false
        && strpos($history, 'FROM student_enrollments se') !== false
        && strpos($history, "'academic_status' => \$promotionType") !== false
        && strpos($history, "'stage_name' =>") !== false,
    'rollover_approves_and_applies_both_statuses' =>
        strpos($rollover, 'decision_enrollment_status') !== false
        && strpos($rollover, 'decision_academic_status') !== false
        && strpos($rollover, 'applyTerminalAnnualStatuses') !== false
        && strpos($rollover, 'enrollment_status, academic_status') !== false
        && strpos($setup, 'data-decision-name="student_decisions[<?php echo $studentId; ?>][enrollment_status]"') !== false
        && strpos($setup, 'data-decision-name="student_decisions[<?php echo $studentId; ?>][academic_status]"') !== false
        && strpos($setup, 'syncStudentDecisionField') !== false,
    'discontinued_students_have_compatible_list_entrypoint' =>
        strpos($discontinuedPage, "define('STUDENT_DATA_SCOPE', 'discontinued')") !== false
        && strpos($discontinuedPage, "require __DIR__ . '/students.php'") !== false
        && strpos($studentDataTableEndpoint, "'discontinued' => 'discontinued_students.php'") !== false,
];

foreach ($checks as $name => $passed) {
    echo $name . ':' . ($passed ? 'PASS' : 'FAIL') . PHP_EOL;
}

exit(in_array(false, $checks, true) ? 1 : 0);
