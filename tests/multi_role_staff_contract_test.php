<?php

declare(strict_types=1);

$root = dirname(__DIR__);
$migration = (string)file_get_contents($root . '/database/migrations/20260723_multi_role_staff_accounts.php');
$fixedRoleMigration = (string)file_get_contents($root . '/database/migrations/20260723_fixed_staff_roles.php');
$assignments = (string)file_get_contents($root . '/src/Modules/Staff/StaffRoleAssignmentService.php');
$activeRole = (string)file_get_contents($root . '/src/Modules/Staff/StaffActiveRoleService.php');
$scope = (string)file_get_contents($root . '/src/Modules/Staff/StaffAcademicScopeService.php');
$authorization = (string)file_get_contents($root . '/classes/AuthorizationFacade.php');
$utilities = (string)file_get_contents($root . '/classes/utilities.php');
$accounts = (string)file_get_contents($root . '/admin/staff_accounts.php');
$accountQuery = (string)file_get_contents($root . '/classes/AccountListDataTableQuery.php');
$login = (string)file_get_contents($root . '/login.php');
$sso = (string)file_get_contents($root . '/classes/MicrosoftSSO.php');
$teacherQuery = (string)file_get_contents($root . '/classes/AssessmentTeacherAssignmentListQuery.php');
$systemAdministratorPolicy = (string)file_get_contents($root . '/classes/SystemAdministratorRoleService.php');

$checks = [
    'migration_adds_normalized_role_membership' => strpos($migration, 'CREATE TABLE user_role_assignments') !== false
        && strpos($migration, 'UNIQUE KEY uq_user_role_assignment (user_id, role_key)') !== false
        && strpos($migration, 'is_primary') !== false,
    'legacy_role_is_backfilled_and_kept_as_primary_mirror' => strpos($migration, 'Backfill the legacy scalar role') !== false
        && strpos($fixedRoleMigration, "['super_admin', 'مدير النظام الأعلى'") !== false
        && strpos($fixedRoleMigration, 'INSERT IGNORE INTO user_role_assignments') !== false
        && strpos($assignments, 'UPDATE users SET role = ?') !== false,
    'employee_role_is_exclusive_and_uses_staff_hr_portal' => strpos($assignments, 'EMPLOYEE_ROLE') !== false
        && strpos($assignments, 'لا يمكن جمعه مع دور آخر') !== false
        && strpos($activeRole, 'دور الموظف لا يملك بوابة') === false
        && strpos($utilities, "case 'employee':") !== false
        && strpos($utilities, "return 'staff_hr_portal.php';") !== false
        && strpos($accounts, "'employee' => 'موظف'") !== false,
    'multi_role_login_requires_explicit_choice' => strpos($activeRole, "count(\$roleKeys) === 1") !== false
        && strpos($activeRole, "\$session['role_selection_required'] = true") !== false
        && strpos($login, 'select_role.php') !== false,
    'active_role_is_server_validated_and_never_unioned' => strpos($activeRole, 'userHasRole($userId, $roleKey)') !== false
        && strpos($activeRole, "\$session['active_role'] = \$roleKey") !== false
        && strpos($authorization, "'active_role'") !== false
        && strpos($utilities, 'refreshActiveRole') !== false,
    'super_admin_power_requires_that_exact_active_role' => strpos($systemAdministratorPolicy, "\$actorActiveRole !== 'super_admin'") !== false
        && strpos($accounts, "\$_SESSION['active_role'] ?? \$_SESSION['role']") !== false,
    'password_and_microsoft_login_share_role_initialization' => strpos($login, 'StaffActiveRoleService') !== false
        && strpos($sso, 'StaffActiveRoleService') !== false,
    'academic_scope_isolated_by_role_and_year' => strpos($migration, 'uq_staff_role_grade_year') !== false
        && strpos($migration, 'uq_staff_role_class_year') !== false
        && strpos($scope, 'staff_id = ? AND role_key = ? AND academic_year_id = ?') !== false,
    'staff_form_submits_multiple_roles_and_per_role_scopes' => strpos((string)file_get_contents($root . '/includes/staff_single_modals.php'), 'name="roles[]"') !== false
        && strpos((string)file_get_contents($root . '/includes/staff_single_modals.php'), 'name="primary_role"') !== false
        && strpos($accounts, "'scopes[' + roleKey + '][grade_ids][]'") !== false
        && strpos($accounts, "'scopes[' + roleKey + '][class_ids][]'") !== false,
    'staff_list_and_filters_use_role_memberships' => strpos($accountQuery, 'GROUP_CONCAT(role_key') !== false
        && strpos($accountQuery, 'user_role_assignments uraf') !== false,
    'teacher_assignment_page_uses_teacher_membership' => substr_count($teacherQuery, "role_key = 'teacher'") >= 3,
];

$failed = false;
foreach ($checks as $name => $passed) {
    echo $name . ':' . ($passed ? 'PASS' : 'FAIL') . PHP_EOL;
    $failed = $failed || !$passed;
}
exit($failed ? 1 : 0);
