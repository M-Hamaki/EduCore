<?php

declare(strict_types=1);

$root = dirname(__DIR__);
require_once $root . '/classes/AdminRolePageCatalog.php';

$dashboard = (string)file_get_contents($root . '/admin/role_dashboard.php');
$utilities = (string)file_get_contents($root . '/classes/utilities.php');
$header = (string)file_get_contents($root . '/includes/admin_header.php');
$styles = (string)file_get_contents($root . '/assets/css/admin-unified.css');

$welcomeFamilies = [
    AdminRolePageCatalog::STUDENT_AFFAIRS_MANAGER,
    AdminRolePageCatalog::TRANSPORT_MANAGER,
    AdminRolePageCatalog::LIBRARIAN,
    AdminRolePageCatalog::DOCTOR,
    AdminRolePageCatalog::ROLES_PERMISSIONS_MANAGER,
];

$allFamiliesUseWelcomeLanding = true;
foreach ($welcomeFamilies as $family) {
    $allowed = array_merge(
        AdminRolePageCatalog::customizablePages($family),
        AdminRolePageCatalog::mandatoryPages($family)
    );
    $allFamiliesUseWelcomeLanding = $allFamiliesUseWelcomeLanding
        && AdminRolePageCatalog::landingPage($family, $allowed) === 'role_dashboard.php';
}

$checks = [
    'five_roles_use_shared_welcome_landing' => $allFamiliesUseWelcomeLanding,
    'welcome_dashboard_authenticates_before_database_work' => strpos($dashboard, "Utilities::validateSession('admin');") !== false
        && strpos($dashboard, "Utilities::validateSession('admin');") < strpos($dashboard, '$db = (new Database())->getConnection();'),
    'dashboard_uses_active_role_family_and_page_grants' => strpos($dashboard, "\$_SESSION['active_role']") !== false
        && strpos($dashboard, '$roleResolver->family($activeRole)') !== false
        && strpos($dashboard, "\$definition['pages'] = AdminRolePageCatalog::customizablePages(\$roleFamily);") !== false
        && strpos($dashboard, 'in_array($page, $allowedPages, true)') !== false,
    'student_operations_is_available_from_welcome_dashboard' => strpos(
        $dashboard,
        "'student_operations.php' =>"
    ) !== false,
    'doctor_and_library_show_current_year_scope' => strpos($dashboard, '$roleResolver->requiresAcademicScope($activeRole)') !== false
        && strpos($dashboard, 'new StaffAcademicScopeService($db)') !== false
        && strpos($dashboard, '$academicYearId') !== false,
    'dashboard_uses_shared_admin_shell_and_central_styles' => strpos($dashboard, 'admin_header.php') !== false
        && strpos($dashboard, 'admin_footer.php') !== false
        && strpos($dashboard, '<style>') === false
        && strpos($styles, '.role-welcome-hero') !== false
        && strpos($styles, '.role-services-grid') !== false,
    'sidebar_home_uses_resolved_dashboard_url' => strpos($header, '$__adminDashboardUrl = Utilities::getDashboardUrl') !== false
        && strpos($header, '$__adminHomeHref') !== false,
    'mandatory_dashboard_is_runtime_granted_and_hidden_from_editor' => strpos($utilities, 'AdminRolePageCatalog::mandatoryPages($roleFamily)') !== false
        && AdminRolePageCatalog::isSupportingPage('role_dashboard.php'),
];

$failed = false;
foreach ($checks as $name => $passed) {
    echo $name . ':' . ($passed ? 'PASS' : 'FAIL') . PHP_EOL;
    $failed = $failed || !$passed;
}

exit($failed ? 1 : 0);
