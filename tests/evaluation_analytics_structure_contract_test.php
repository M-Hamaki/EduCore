<?php

declare(strict_types=1);

$root = dirname(__DIR__);
$legacyPage = (string) file_get_contents($root . '/specialist/evaluation_analytics.php');
$adminPage = (string) file_get_contents($root . '/admin/evaluation_analytics.php');
$legacyHeader = (string) file_get_contents($root . '/includes/specialist_header.php');
$evaluationBootstrap = (string) file_get_contents($root . '/src/Modules/BehaviorEvaluation/bootstrap.php');

$checks = [
    'legacy_route_is_authenticated_redirect' => strpos($legacyPage, "Utilities::validateSession('specialist')") !== false
        && strpos($legacyPage, '../admin/evaluation_analytics.php') !== false,
    'admin_page_owns_shared_analytics' => strpos($adminPage, "Utilities::validateSession('admin')") !== false
        && strpos($adminPage, 'ScopedStaffPortalContext') !== false
        && strpos($adminPage, 'evaluation_analytics_scope') !== false,
    'analytics_is_scoped_to_current_year_and_classes' => strpos($adminPage, 'currentAcademicYearId') !== false
        && strpos($adminPage, 'allowedClassIds') !== false
        && strpos($adminPage, 'student_enrollments') !== false,
    'legacy_header_cannot_revive_separate_shell' => strpos($legacyHeader, "require_once __DIR__ . '/admin_header.php'") !== false
        && strpos($legacyHeader, '<html') === false
        && strpos($legacyHeader, 'admin-sidebar') === false,
    'retired_specialist_read_service_is_not_bootstrapped' => strpos($evaluationBootstrap, 'SpecialistEvaluationReadService.php') === false,
    'admin_page_uses_centralized_css' => strpos($adminPage, '<style') === false,
];

foreach ($checks as $name => $passed) {
    echo $name . ':' . ($passed ? 'PASS' : 'FAIL') . PHP_EOL;
}

exit(in_array(false, $checks, true) ? 1 : 0);
