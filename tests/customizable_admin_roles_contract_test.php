<?php

declare(strict_types=1);

$root = dirname(__DIR__);
require_once $root . '/classes/AdminRolePageCatalog.php';

$accounts = (string)file_get_contents($root . '/admin/staff_accounts.php');
$resolver = (string)file_get_contents($root . '/src/Modules/Staff/StaffRoleCapabilityResolver.php');
$scopeService = (string)file_get_contents($root . '/src/Modules/Staff/StaffAcademicScopeService.php');
$context = (string)file_get_contents($root . '/classes/ScopedStaffPortalContext.php');
$utilities = (string)file_get_contents($root . '/classes/utilities.php');
$academicYear = (string)file_get_contents($root . '/classes/AcademicYear.php');
$migration = (string)file_get_contents($root . '/database/migrations/20260722_staff_role_inheritance.php');

$expectedCustomizable = [
    'specialist',
    'doctor',
    'librarian',
    'student_affairs_manager',
    'transport_manager',
    'roles_permissions_manager',
];
$actualCustomizable = AdminRolePageCatalog::customizableRoleKeys();
sort($expectedCustomizable);
sort($actualCustomizable);

$fixedRoles = ['admin', 'super_admin', 'teacher', 'supervisor', 'external_teacher', 'student', 'employee'];
$fixedRolesRemainFixed = true;
foreach ($fixedRoles as $role) {
    $fixedRolesRemainFixed = $fixedRolesRemainFixed && !AdminRolePageCatalog::isCustomizableRole($role);
}

$results = [
    'exact_six_roles_are_customizable' => $actualCustomizable === $expectedCustomizable,
    'core_roles_remain_fixed' => $fixedRolesRemainFixed,
    'page_updates_require_super_admin' => strpos($accounts, 'assertActorCanManage(') !== false
        && strpos($accounts, "\$_SESSION['active_role'] ?? \$_SESSION['role']") !== false
        && strpos($accounts, 'إنشاء الأدوار وتعديل صلاحياتها متاح لمدير النظام الأعلى فقط.') !== false,
    'built_in_role_edits_are_pages_only' => strpos($accounts, 'data-pages-only=') !== false
        && strpos($accounts, "AdminRolePageCatalog::isCustomizableRole(\$currentRoleKey)") !== false
        && strpos($accounts, "\$roleName = (string)\$existingRole['role_name'];") !== false,
    'submitted_pages_are_limited_to_role_catalog' => strpos($accounts, 'AdminRolePageCatalog::customizablePages($roleFamily)') !== false,
    'landing_and_workflow_pages_are_mandatory' => AdminRolePageCatalog::mandatoryPages('specialist') === ['specialist_dashboard.php', 'specialist_requests.php']
        && AdminRolePageCatalog::mandatoryPages('doctor') === ['role_dashboard.php']
        && AdminRolePageCatalog::mandatoryPages('librarian') === ['role_dashboard.php']
        && AdminRolePageCatalog::mandatoryPages('student_affairs_manager') === ['role_dashboard.php']
        && AdminRolePageCatalog::mandatoryPages('transport_manager') === ['role_dashboard.php']
        && AdminRolePageCatalog::mandatoryPages('roles_permissions_manager') === ['role_dashboard.php']
        && strpos($accounts, 'AdminRolePageCatalog::mandatoryPages($roleFamily)') !== false
        && strpos($accounts, 'data-mandatory-pages=') !== false,
    'six_roles_offer_edit_and_clone_actions' => strpos($accounts, 'onclick="editRoleFromButton(this)"') !== false
        && strpos($accounts, 'onclick="cloneRoleFromButton(this)"') !== false
        && strpos($accounts, 'clone_source_role_key') !== false,
    'clones_persist_their_behavioural_family' => strpos($accounts, 'base_role_key, portal_type') !== false
        && strpos($accounts, "\$roleFamily = \$cloneSourceRoleKey;") !== false
        && strpos($resolver, 'SELECT base_role_key') !== false,
    'scoped_clones_keep_academic_scope' => strpos($accounts, 'requiresAcademicScope($selectedRole)') !== false
        && strpos($context, 'StaffRoleCapabilityResolver') !== false
        && strpos($context, "family(\$this->assignedRole)") !== false
        && strpos($scopeService, 'requiresAcademicScope($roleKey)') !== false,
    'specialist_clones_keep_active_year_and_specialist_behaviour' => strpos($utilities, 'isSpecialistFamily($role)') !== false
        && strpos($academicYear, 'Utilities::getAdministrativeRoleFamily($role)') !== false,
    'schema_change_is_migration_owned_and_idempotent' => strpos($migration, 'information_schema.COLUMNS') !== false
        && strpos($migration, "COLUMN_NAME = 'base_role_key'") !== false
        && strpos($migration, 'ALTER TABLE staff_roles') !== false,
];

$failed = false;
foreach ($results as $name => $passed) {
    echo $name . ':' . ($passed ? 'PASS' : 'FAIL') . PHP_EOL;
    $failed = $failed || !$passed;
}

exit($failed ? 1 : 0);
