<?php

declare(strict_types=1);

$root = dirname(__DIR__);
$context = (string)file_get_contents($root . '/classes/ScopedStaffPortalContext.php');
$service = (string)file_get_contents($root . '/src/Modules/Staff/StaffAcademicScopeService.php');
$migration = (string)file_get_contents($root . '/database/migrations/20260719_scoped_staff_portals.php');
$utilities = (string)file_get_contents($root . '/classes/utilities.php');
$accounts = (string)file_get_contents($root . '/admin/staff_accounts.php');
$modals = (string)file_get_contents($root . '/includes/staff_single_modals.php');
$endpoint = (string)file_get_contents($root . '/admin/ajax_staff_scope.php');

$checks = [
    'roles_share_one_generic_scope_service' => strpos($service, "['specialist', 'doctor', 'librarian']") !== false
        && strpos($service, 'staff_grade_assignments') !== false
        && strpos($service, 'staff_class_assignments') !== false,
    'migration_backfills_legacy_specialist_scope' => strpos($migration, 'FROM specialist_grade_assignments') !== false
        && strpos($migration, 'FROM specialist_class_assignments') !== false,
    'migration_does_not_grant_unscoped_pages' => strpos($migration, 'staff_role_pages') === false,
    'context_fails_closed_for_missing_scope' => strpos($context, '$this->allowedClassIds = []') !== false
        && strpos($context, 'allowedClassIdsForStaff') !== false,
    'context_checks_database_role' => strpos($context, 'SELECT role FROM users') !== false,
    'context_supports_read_and_write_assertions' => strpos($context, 'assertClassAllowed') !== false
        && strpos($context, 'assertStudentAllowed') !== false
        && strpos($context, 'filterClassIds') !== false,
    'staff_form_embeds_scope_for_all_scoped_roles' => strpos($accounts, 'requiresAcademicScope((string)$validRoleKey)') !== false
        && strpos($modals, 'id="staffScopeSection"') !== false
        && strpos($modals, 'data-requires-scope=') !== false
        && strpos($accounts, 'ajax_staff_scope.php') !== false,
    'scope_endpoint_is_admin_csrf_read_only' => strpos($endpoint, "validateSession('admin')") !== false
        && strpos($endpoint, 'requireCsrfPost()') !== false
        && strpos($endpoint, 'replaceAssignments(') === false,
    'specialist_has_safe_transition_fallback' => strpos($utilities, "if (\$role === 'specialist' && !self::isCustomAdminRole('specialist'))") !== false
        && strpos($utilities, "return 'specialist/index.php'") !== false,
];

$failed = false;
foreach ($checks as $name => $passed) {
    echo $name . ':' . ($passed ? 'PASS' : 'FAIL') . PHP_EOL;
    $failed = $failed || !$passed;
}
exit($failed ? 1 : 0);
