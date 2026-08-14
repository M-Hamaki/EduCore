<?php

declare(strict_types=1);

$root = dirname(__DIR__);
$dashboard = (string)file_get_contents($root . '/admin/index.php');
$utilities = (string)file_get_contents($root . '/classes/utilities.php');

$checks = [
    'scoped_roles_are_intercepted_before_dashboard_queries' => strpos($dashboard, '$scopedLandingPages = [') !== false
        && strpos($dashboard, '$scopedLandingPages = [') < strpos($dashboard, '// Get counts for dashboard'),
    'doctor_and_librarian_have_safe_landings' => strpos($dashboard, "'doctor' => 'student_clinic.php'") !== false
        && strpos($dashboard, "'librarian' => 'library.php'") !== false,
    'specialist_has_welcome_dashboard_landing' => strpos($dashboard, "'specialist' => 'specialist_dashboard.php'") !== false,
    'inactive_portal_shows_no_admin_statistics' => strpos($dashboard, 'لم تُفعّل صفحات هذا الدور بعد') !== false
        && strpos($dashboard, "require_once '../includes/admin_footer.php';") !== false
        && strpos($dashboard, "exit;\n}\nrequire_once __DIR__ . '/../classes/AcademicYear.php';") !== false,
    'login_and_forbidden_page_use_role_landing' => strpos($utilities, "'doctor' => 'student_clinic.php'") !== false
        && strpos($utilities, "'librarian' => 'library.php'") !== false
        && strpos($utilities, '$target = self::getDashboardUrl') !== false,
];

$failed = false;
foreach ($checks as $name => $passed) {
    echo $name . ':' . ($passed ? 'PASS' : 'FAIL') . PHP_EOL;
    $failed = $failed || !$passed;
}
exit($failed ? 1 : 0);
