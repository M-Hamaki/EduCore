<?php

declare(strict_types=1);

$root = dirname(__DIR__);
require_once $root . '/classes/AdminRolePageCatalog.php';

$roles = AdminRolePageCatalog::predefinedRoles();
$migration = (string) file_get_contents($root . '/database/migrations/20260722_department_admin_roles.php');
$studentNumbersMigration = (string) file_get_contents($root . '/database/migrations/20260807_rename_school_budget_report_permission.php');
$studentOperationsMigration = (string) file_get_contents($root . '/database/migrations/20260808_add_student_operations_page_permission.php');
$utilities = (string) file_get_contents($root . '/classes/utilities.php');
$header = (string) file_get_contents($root . '/includes/admin_header.php');
$accounts = (string) file_get_contents($root . '/admin/staff_accounts.php');
$passwordEndpoint = (string) file_get_contents($root . '/admin/ajax/get_password.php');
$staffScopeEndpoint = (string) file_get_contents($root . '/admin/ajax_staff_scope.php');
$studentCompletenessEndpoint = (string) file_get_contents($root . '/admin/ajax_student_completeness.php');
$ajaxHandlers = (string) file_get_contents($root . '/includes/ajax_handlers.php');
$scopeService = (string) file_get_contents($root . '/classes/StaffAcademicScopeService.php');

$expectedPages = [
    AdminRolePageCatalog::STUDENT_AFFAIRS_MANAGER => [
        'students.php', 'student_operations.php', 'pending_operations.php', 'new_students.php', 'transferred_students.php',
        'graduate_students.php', 'student_archive.php', 'student_data_completeness.php',
        'class_lists.php', 'siblings.php', 'attendance.php', 'statements.php', 'student_file.php',
        'student_numbers_reports.php', 'student_id_cards.php', 'export_students.php', 'student_statistics.php',
        'calculation_tools.php',
    ],
    AdminRolePageCatalog::TRANSPORT_MANAGER => [
        'locations.php', 'bus_staff.php', 'buses.php', 'student_buses.php', 'bus_lists.php',
        'bus_report.php', 'transport_statistics.php',
    ],
    AdminRolePageCatalog::ROLES_PERMISSIONS_MANAGER => [
        'school_settings.php', 'student_accounts.php', 'staff_accounts.php',
    ],
];

$migrationPages = static function (string $roleKey) use ($migration): array {
    $pattern = "/'" . preg_quote($roleKey, '/')
        . "'\\s*=>\\s*\\[.*?'pages'\\s*=>\\s*\\[(.*?)\\]\\s*,\\s*\\]/s";
    if (!preg_match($pattern, $migration, $match)) {
        return [];
    }
    preg_match_all("/'([a-z0-9_]+\\.php)'/", (string)$match[1], $pageMatches);
    return $pageMatches[1] ?? [];
};

$studentAffairsMigrationPages = $migrationPages(AdminRolePageCatalog::STUDENT_AFFAIRS_MANAGER);
if (strpos($studentNumbersMigration, "SET page_name = 'student_numbers_reports.php'") !== false) {
    $legacyReportIndex = array_search('school_budget.php', $studentAffairsMigrationPages, true);
    if ($legacyReportIndex !== false) {
        $studentAffairsMigrationPages[$legacyReportIndex] = 'student_numbers_reports.php';
    }
}
if (strpos($studentOperationsMigration, "['student_affairs_manager', 'student_operations.php']") !== false) {
    array_splice($studentAffairsMigrationPages, 1, 0, ['student_operations.php']);
}

$allVisiblePagesProtected = true;
$unprotectedVisiblePages = [];
foreach ($expectedPages as $pages) {
    foreach ($pages as $page) {
        $source = (string) file_get_contents($root . '/admin/' . $page);
        $authAt = strpos($source, "Utilities::validateSession('admin')");
        $requestPositions = array_filter([
            strpos($source, "\$_SERVER['REQUEST_METHOD']"),
            strpos($source, '$_POST'),
        ], static fn($position) => $position !== false);
        $requestAt = $requestPositions === [] ? false : min($requestPositions);
        if ($authAt === false || ($requestAt !== false && $authAt > $requestAt)) {
            $allVisiblePagesProtected = false;
            $unprotectedVisiblePages[] = $page;
        }
    }
}

$checks = [
    'student_affairs_pages_match_sidebar_scope' => ($roles[AdminRolePageCatalog::STUDENT_AFFAIRS_MANAGER]['pages'] ?? null)
        === $expectedPages[AdminRolePageCatalog::STUDENT_AFFAIRS_MANAGER],
    'transport_pages_match_sidebar_scope' => ($roles[AdminRolePageCatalog::TRANSPORT_MANAGER]['pages'] ?? null)
        === $expectedPages[AdminRolePageCatalog::TRANSPORT_MANAGER],
    'account_permissions_pages_match_sidebar_scope' => ($roles[AdminRolePageCatalog::ROLES_PERMISSIONS_MANAGER]['pages'] ?? null)
        === $expectedPages[AdminRolePageCatalog::ROLES_PERMISSIONS_MANAGER],
    'roles_have_safe_landing_pages' => AdminRolePageCatalog::landingPage(
        AdminRolePageCatalog::STUDENT_AFFAIRS_MANAGER,
        array_merge(
            $expectedPages[AdminRolePageCatalog::STUDENT_AFFAIRS_MANAGER],
            AdminRolePageCatalog::mandatoryPages(AdminRolePageCatalog::STUDENT_AFFAIRS_MANAGER)
        )
    ) === 'role_dashboard.php'
        && AdminRolePageCatalog::landingPage(
            AdminRolePageCatalog::TRANSPORT_MANAGER,
            array_merge(
                $expectedPages[AdminRolePageCatalog::TRANSPORT_MANAGER],
                AdminRolePageCatalog::mandatoryPages(AdminRolePageCatalog::TRANSPORT_MANAGER)
            )
        ) === 'role_dashboard.php'
        && AdminRolePageCatalog::landingPage(
            AdminRolePageCatalog::ROLES_PERMISSIONS_MANAGER,
            array_merge(
                $expectedPages[AdminRolePageCatalog::ROLES_PERMISSIONS_MANAGER],
                AdminRolePageCatalog::mandatoryPages(AdminRolePageCatalog::ROLES_PERMISSIONS_MANAGER)
            )
        ) === 'role_dashboard.php',
    'department_roles_are_unscoped_like_admin' => strpos($scopeService, "'student_affairs_manager'") === false
        && strpos($scopeService, "'transport_manager'") === false
        && strpos($scopeService, "'roles_permissions_manager'") === false,
    'support_endpoints_are_derived_not_standalone' => in_array(
        'ajax_students_datatable.php',
        AdminRolePageCatalog::expandWithDependencies(['students.php']),
        true
    )
        && in_array(
            'get_password.php',
            AdminRolePageCatalog::expandWithDependencies(['staff_accounts.php']),
            true
        )
        && AdminRolePageCatalog::isSupportingPage('ajax_staff_accounts_datatable.php'),
    'student_completeness_endpoint_uses_page_authorization' => in_array(
        'ajax_student_completeness.php',
        AdminRolePageCatalog::expandWithDependencies(['student_data_completeness.php']),
        true
    )
        && strpos($studentCompletenessEndpoint, "Utilities::validateSession('admin');") !== false
        && strpos($studentCompletenessEndpoint, "in_array(\$role, ['admin', 'super_admin'])") === false,
    'migration_registers_all_three_roles' => strpos($migration, "'student_affairs_manager'") !== false
        && strpos($migration, "'transport_manager'") !== false
        && strpos($migration, "'roles_permissions_manager'") !== false
        && strpos($migration, "portal_type = 'admin_like'") !== false,
    'migration_page_grants_match_catalog' => $studentAffairsMigrationPages
        === $expectedPages[AdminRolePageCatalog::STUDENT_AFFAIRS_MANAGER]
        && $migrationPages(AdminRolePageCatalog::TRANSPORT_MANAGER)
        === $expectedPages[AdminRolePageCatalog::TRANSPORT_MANAGER]
        && $migrationPages(AdminRolePageCatalog::ROLES_PERMISSIONS_MANAGER)
        === $expectedPages[AdminRolePageCatalog::ROLES_PERMISSIONS_MANAGER],
    'utilities_expand_dependencies_and_choose_landing' => strpos($utilities, 'AdminRolePageCatalog::expandWithDependencies') !== false
        && strpos($utilities, 'AdminRolePageCatalog::landingPage') !== false,
    'role_editor_lists_fixed_roles_and_hides_support_endpoints' => strpos($accounts, '$fixedRoleDefinitions') !== false
        && strpos($accounts, 'AdminRolePageCatalog::isSupportingPage($name)') !== false
        && strpos($accounts, 'دور نظامي ثابت') !== false,
    'pending_badge_supports_authorized_student_affairs_role' => strpos(
        $header,
        "Utilities::roleCanAccessAdminPage(\$__sessionRole, 'pending_operations.php')"
    ) !== false,
    'password_reveal_accepts_only_page_authorized_admin_like_roles' => strpos(
        $passwordEndpoint,
        "Utilities::roleCanAccessAdminPage((string)(\$_SESSION['role'] ?? ''), 'get_password.php')"
    ) !== false,
    'custom_staff_role_can_be_converted_to_scoped_role' => strpos(
        $staffScopeEndpoint,
        "u.role NOT IN ('student','external_teacher')"
    ) !== false
        && strpos($staffScopeEndpoint, "u.role IN ('teacher','specialist','doctor','librarian')") === false,
    'shared_student_and_account_ajax_actions_delegate_by_page' => strpos($ajaxHandlers, "'get_user_services' => 'student_accounts.php'") !== false
        && strpos($ajaxHandlers, "'find_siblings' => 'students.php'") !== false
        && strpos($ajaxHandlers, '$hasDelegatedPageGrant') !== false,
    'all_visible_role_pages_authenticate_before_post_handling' => $allVisiblePagesProtected,
];

$failed = false;
foreach ($checks as $name => $passed) {
    echo ($passed ? '[PASS] ' : '[FAIL] ') . $name . PHP_EOL;
    if ($name === 'all_visible_role_pages_authenticate_before_post_handling' && !$passed) {
        echo '       ' . implode(', ', array_values(array_unique($unprotectedVisiblePages))) . PHP_EOL;
    }
    $failed = $failed || !$passed;
}

exit($failed ? 1 : 0);
