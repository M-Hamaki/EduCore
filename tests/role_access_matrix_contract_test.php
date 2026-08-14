<?php

declare(strict_types=1);

$root = dirname(__DIR__);
require_once $root . '/classes/AdminRolePageCatalog.php';

$header = (string) file_get_contents($root . '/includes/admin_header.php');
$dashboard = (string) file_get_contents($root . '/admin/role_dashboard.php');
$utilities = (string) file_get_contents($root . '/classes/utilities.php');

$visiblePages = [];
foreach (AdminRolePageCatalog::predefinedRoles() as $definition) {
    foreach ((array) ($definition['pages'] ?? []) as $page) {
        $visiblePages[(string) $page] = true;
    }
}
$visiblePages = array_keys($visiblePages);
sort($visiblePages, SORT_STRING);

$missingFiles = [];
$unprotectedPages = [];
$missingSidebarLinks = [];
foreach ($visiblePages as $page) {
    $path = $root . '/admin/' . $page;
    if (!is_file($path)) {
        $missingFiles[] = $page;
        continue;
    }

    $source = (string) file_get_contents($path);
    $authAt = strpos($source, "Utilities::validateSession('admin');");
    $databasePositions = array_filter([
        strpos($source, 'new Database('),
        strpos($source, '->getConnection('),
    ], static fn($position): bool => $position !== false);
    $databaseAt = $databasePositions === [] ? false : min($databasePositions);
    if ($authAt === false || ($databaseAt !== false && $authAt > $databaseAt)) {
        $unprotectedPages[] = $page;
    }

    if (strpos($header, 'href="' . $page . '"') === false
        && strpos($header, "href='" . $page . "'") === false) {
        $missingSidebarLinks[] = $page;
    }
}

$serviceCatalogAt = strpos($dashboard, '$serviceCatalog = [');
$serviceCatalogEnd = $serviceCatalogAt === false ? false : strpos($dashboard, "\n];", $serviceCatalogAt);
$serviceCatalog = $serviceCatalogAt === false || $serviceCatalogEnd === false
    ? ''
    : substr($dashboard, $serviceCatalogAt, $serviceCatalogEnd - $serviceCatalogAt);
$missingWelcomeServices = [];
foreach ([
    AdminRolePageCatalog::STUDENT_AFFAIRS_MANAGER,
    AdminRolePageCatalog::TRANSPORT_MANAGER,
    AdminRolePageCatalog::LIBRARIAN,
    AdminRolePageCatalog::DOCTOR,
    AdminRolePageCatalog::ROLES_PERMISSIONS_MANAGER,
] as $roleFamily) {
    foreach (AdminRolePageCatalog::customizablePages($roleFamily) as $page) {
        if (strpos($serviceCatalog, "'{$page}' =>") === false) {
            $missingWelcomeServices[] = $roleFamily . ':' . $page;
        }
    }
}

$dashboardRoutes = [
    'admin' => 'admin/index.php',
    'super_admin' => 'admin/index.php',
    'teacher' => 'teacher/portal.php',
    'supervisor' => 'supervisor/index.php',
    'student' => 'student/portal.php',
    'external_teacher' => 'external/index.php',
    'employee' => 'staff_hr_portal.php',
];
$missingDashboardRoutes = [];
foreach ($dashboardRoutes as $role => $route) {
    if (strpos($utilities, "case '{$role}':") === false
        || strpos($utilities, "return '{$route}';") === false
        || !is_file($root . '/' . $route)) {
        $missingDashboardRoutes[] = $role . ':' . $route;
    }
}

$checks = [
    'all_predefined_role_pages_exist' => $missingFiles === [],
    'all_predefined_role_pages_authenticate_before_database_access' => $unprotectedPages === [],
    'all_predefined_role_pages_have_sidebar_navigation' => $missingSidebarLinks === [],
    'welcome_dashboards_follow_the_central_page_catalog' => strpos(
        $dashboard,
        "\$definition['pages'] = AdminRolePageCatalog::customizablePages(\$roleFamily);"
    ) !== false,
    'all_welcome_role_pages_have_service_cards' => $missingWelcomeServices === [],
    'all_fixed_portal_roles_have_existing_dashboard_routes' => $missingDashboardRoutes === [],
    'custom_admin_clones_resolve_through_their_role_family' => strpos(
        $utilities,
        'AdminRolePageCatalog::landingPage($roleFamily, $allowedPages)'
    ) !== false,
];

$details = [
    'all_predefined_role_pages_exist' => $missingFiles,
    'all_predefined_role_pages_authenticate_before_database_access' => $unprotectedPages,
    'all_predefined_role_pages_have_sidebar_navigation' => $missingSidebarLinks,
    'all_welcome_role_pages_have_service_cards' => $missingWelcomeServices,
    'all_fixed_portal_roles_have_existing_dashboard_routes' => $missingDashboardRoutes,
];

$failed = false;
foreach ($checks as $name => $passed) {
    echo $name . ':' . ($passed ? 'PASS' : 'FAIL');
    if (!$passed && isset($details[$name])) {
        echo ':' . implode(',', $details[$name]);
    }
    echo PHP_EOL;
    $failed = $failed || !$passed;
}

exit($failed ? 1 : 0);
