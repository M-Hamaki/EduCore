<?php

declare(strict_types=1);

$root = dirname(__DIR__);
$routes = [
    'index' => 'specialist_dashboard.php',
    'students' => 'students.php',
    'class_lists' => 'class_lists.php',
    'attendance' => 'attendance.php',
    'student_file' => 'student_file.php',
    'student_id_cards' => 'student_id_cards.php',
    'export_students' => 'export_students.php',
    'student_statistics' => 'student_statistics.php',
    'calculation_tools' => 'calculation_tools.php',
    'student_evaluations' => 'student_evaluations.php',
    'teacher_evaluations' => 'teacher_evaluations.php',
    'evaluation_analytics' => 'evaluation_analytics.php',
    'evaluation_reports' => 'evaluation_reports.php',
    'student_clinic' => 'student_clinic.php',
];

$redirects = [];
foreach ($routes as $route => $target) {
    $redirects[$route] = (string)file_get_contents($root . '/specialist/' . $route . '.php');
}

$adminPages = [];
foreach (array_unique(array_values($routes)) as $page) {
    $adminPages[$page] = (string)file_get_contents($root . '/admin/' . $page);
}

$header = (string)file_get_contents($root . '/includes/admin_header.php');
$academicYear = (string)file_get_contents($root . '/classes/AcademicYear.php');
$ajax = (string)file_get_contents($root . '/includes/ajax_handlers.php');
$evaluationAjax = (string)file_get_contents($root . '/src/Modules/BehaviorEvaluation/Ajax/evaluations.php');
$migration = (string)file_get_contents($root . '/database/migrations/20260719_specialist_admin_portal_activation.php');
$authorization = (string)file_get_contents($root . '/classes/AuthorizationFacade.php');

$redirectsValid = true;
foreach ($routes as $route => $target) {
    $source = $redirects[$route];
    $redirectsValid = $redirectsValid
        && strpos($source, "validateSession('specialist')") !== false
        && strpos($source, "../admin/{$target}") !== false;
}

$scopedPages = [
    'specialist_dashboard.php',
    'students.php', 'class_lists.php', 'attendance.php', 'student_file.php',
    'student_id_cards.php', 'export_students.php', 'student_statistics.php',
    'calculation_tools.php', 'student_evaluations.php', 'teacher_evaluations.php',
    'evaluation_analytics.php', 'evaluation_reports.php', 'student_clinic.php',
];
$allPagesUseAdminHeader = true;
foreach ($scopedPages as $page) {
    $source = $adminPages[$page];
    $allPagesUseAdminHeader = $allPagesUseAdminHeader
        && strpos($source, 'admin_header.php') !== false;
}

$checks = [
    'legacy_routes_redirect_to_exact_admin_pages' => $redirectsValid,
    'all_specialist_surfaces_use_admin_header' => $allPagesUseAdminHeader,
    'specialist_is_active_year_only' => strpos($academicYear, "if (\$role === 'specialist')") !== false
        && strpos($academicYear, 'Utilities::getAdministrativeRoleFamily($role)') !== false
        && strpos($header, '$__academicYearSwitchAllowed') !== false,
    'student_edits_are_pending' => strpos($adminPages['students.php'], 'submitProfile') !== false
        && strpos($adminPages['students.php'], '$studentProfilePendingMode') !== false,
    'shared_ajax_enforces_specialist_scope' => strpos($ajax, "if (\$role === 'specialist')") !== false
        && strpos($ajax, 'assertStudentAllowed') !== false
        && strpos($ajax, 'assertClassAllowed') !== false,
    'specialist_can_read_scoped_teacher_evaluations' => strpos(
        $ajax,
        "'get_teacher_evaluations_for_admin' => ['admin','specialist']"
    ) !== false
        && strpos($evaluationAjax, "if (\$role !== 'admin' && \$role !== 'specialist')") !== false
        && strpos($evaluationAjax, "if (\$role === 'specialist')") !== false
        && strpos($evaluationAjax, '$staffPortalContext->allowedClassIds()') !== false
        && strpos($evaluationAjax, 'e.class_id IN ({$teacherClassMarks})') !== false
        && strpos($evaluationAjax, '(e.academic_year_id = ? OR e.academic_year_id IS NULL)') !== false,
    'activation_grants_only_reviewed_pages' => strpos($migration, "portal_type = 'admin_like'") !== false
        && strpos($migration, "'pending_operations.php'") === false
        && strpos($migration, "'ajax_students_datatable.php'") !== false
        && strpos($migration, "'ajax_clinic_datatable.php'") !== false,
    'supervisor_does_not_inherit_specialist_role' => strpos($authorization, "return 'supervisor';") !== false,
];

$failed = false;
foreach ($checks as $name => $passed) {
    echo $name . ':' . ($passed ? 'PASS' : 'FAIL') . PHP_EOL;
    $failed = $failed || !$passed;
}
exit($failed ? 1 : 0);
