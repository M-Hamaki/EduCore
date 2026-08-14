<?php

declare(strict_types=1);

$root = dirname(__DIR__);
$dashboard = (string) file_get_contents($root . '/admin/specialist_dashboard.php');
$dashboardQuery = (string) file_get_contents($root . '/src/Modules/Staff/SpecialistDashboardQuery.php');
$utilities = (string) file_get_contents($root . '/classes/utilities.php');
$header = (string) file_get_contents($root . '/includes/admin_header.php');
$legacyIndex = (string) file_get_contents($root . '/specialist/index.php');
$migration = (string) file_get_contents($root . '/database/migrations/20260719_specialist_admin_portal_activation.php');
$dashboardMigration = (string) file_get_contents($root . '/database/migrations/20260719_specialist_dashboard_activation.php');

$services = [
    'students.php', 'class_lists.php', 'attendance.php', 'student_file.php',
    'student_id_cards.php', 'export_students.php', 'student_statistics.php',
    'calculation_tools.php', 'student_evaluations.php', 'teacher_evaluations.php',
    'evaluation_analytics.php', 'evaluation_reports.php', 'student_clinic.php',
];
$allServicesPresent = true;
foreach ($services as $service) {
    $allServicesPresent = $allServicesPresent && strpos($dashboard, "['{$service}'") !== false;
}

$checks = [
    'dashboard_uses_exact_admin_shell' => strpos($dashboard, "Utilities::validateSession('admin')") !== false
        && strpos($dashboard, "admin_header.php") !== false
        && strpos($dashboard, "admin_footer.php") !== false,
    'welcome_uses_specialist_name' => strpos($dashboard, 'مرحبًا بك أ.') !== false
        && strpos($dashboard, "\$_SESSION['name']") !== false,
    'dashboard_is_active_year_scoped' => strpos($dashboard, 'AcademicYear::getCurrent') !== false
        && strpos($dashboard, 'SpecialistDashboardQuery') !== false
        && strpos($dashboardQuery, 'StaffAcademicScopeService') !== false
        && strpos($dashboardQuery, 'student_enrollments') !== false,
    'dashboard_entrypoint_has_no_direct_sql' => strpos($dashboard, 'SELECT ') === false
        && strpos($dashboard, 'prepare(') === false,
    'all_agreed_services_are_listed' => $allServicesPresent,
    'specialist_lands_on_dashboard' => strpos($utilities, "'specialist' => 'specialist_dashboard.php'") !== false
        && strpos($legacyIndex, '../admin/specialist_dashboard.php') !== false,
    'dashboard_is_mandatory_and_migrated' => strpos($utilities, "\$pages[] = 'specialist_dashboard.php'") !== false
        && strpos($migration, "'specialist_dashboard.php'") !== false
        && strpos($dashboardMigration, "['specialist', 'specialist_dashboard.php']") !== false,
    'sidebar_dashboard_uses_role_landing_page' => strpos($header, 'href="<?php echo htmlspecialchars($__adminHomeHref') !== false,
];

$failed = false;
foreach ($checks as $name => $passed) {
    echo $name . ':' . ($passed ? 'PASS' : 'FAIL') . PHP_EOL;
    $failed = $failed || !$passed;
}
exit($failed ? 1 : 0);
