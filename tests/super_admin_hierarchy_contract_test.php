<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/classes/AuthorizationFacade.php';

$root = dirname(__DIR__);
$staffAccounts = (string) file_get_contents($root . '/admin/staff_accounts.php');
$staffEndpoint = (string) file_get_contents($root . '/admin/ajax_staff_accounts_datatable.php');
$staffModals = (string) file_get_contents($root . '/includes/staff_single_modals.php');
$service = (string) file_get_contents($root . '/classes/SystemAdministratorRoleService.php');
$resetPoints = (string) file_get_contents($root . '/admin/reset_points.php');
$migration = (string) file_get_contents($root . '/database/migrations/20260722_promote_dmls_super_admin.php');

$checks = [
    'admin_does_not_satisfy_super_admin_requirement' => !AuthorizationFacade::allowsRequiredRole(
        ['role' => 'admin'],
        'super_admin'
    ),
    'super_admin_inherits_admin_access' => AuthorizationFacade::allowsRequiredRole(
        ['role' => 'super_admin'],
        'admin'
    ),
    'critical_points_reset_requires_super_admin_server_side' => strpos(
        $resetPoints,
        "Utilities::validateSession('super_admin');"
    ) !== false
        && strpos($resetPoints, 'SystemAdministratorRoleService') !== false
        && strpos($resetPoints, 'assertActorCanManage(') !== false,
    'system_roles_are_exposed_only_to_super_admin' => substr_count(
        $staffAccounts,
        '$isSuperAdmin'
    ) >= 5
        && strpos($staffEndpoint, "=== 'super_admin'") !== false
        && strpos($staffAccounts, "\$portalRoleLabels['super_admin']") !== false,
    'role_definition_changes_require_super_admin' => strpos(
        $staffAccounts,
        'إنشاء الأدوار وتعديل صلاحياتها متاح لمدير النظام الأعلى فقط.'
    ) !== false
        && strpos($staffAccounts, 'assertActorCanManage(') !== false
        && strpos($staffAccounts, "\$_SESSION['active_role'] ?? \$_SESSION['role']") !== false,
    'system_role_transitions_use_shared_policy' => strpos(
        $staffAccounts,
        'assertRoleSetChangeAllowed('
    ) !== false
        && strpos($staffAccounts, 'assertStatusChangeAllowed(') !== false
        && strpos($staffAccounts, 'SELECT id, name, role, status FROM users WHERE id = ? LIMIT 1 FOR UPDATE') !== false,
    'policy_requires_active_super_admin_and_protects_self_status' => strpos(
        $service,
        "\$actorActiveRole !== 'super_admin'"
    ) !== false
        && strpos($service, "ura.role_key = 'super_admin'") !== false
        && strpos($service, 'assertDifferentAccount($actorId, $targetId)') !== false,
    'self_super_admin_can_edit_only_secondary_roles' => strpos(
        $service,
        "!in_array('super_admin', \$newRoles, true)"
    ) !== false
        && strpos($service, 'لا يمكنك إزالة دور مدير النظام الأعلى من حسابك الحالي.') !== false
        && strpos($staffAccounts, '$primaryRole = \'super_admin\';') !== false
        && strpos($staffModals, 'id="selfSuperAdminRoleNotice"') !== false
        && strpos($staffAccounts, "dataset.protectSelfSuperAdmin") !== false
        && strpos($staffEndpoint, "=== 'super_admin'") !== false,
    'policy_protects_last_active_super_admin' => strpos(
        $service,
        "ura.role_key = 'super_admin' AND ura.status = 'active'"
    ) !== false
        && strpos($service, 'لا يمكن تخفيض أو تعطيل آخر مدير نظام أعلى نشط.') !== false,
    'bootstrap_uses_configurable_account' => strpos(
        $migration,
        "\$targetUsername = trim((string) env('INITIAL_SUPER_ADMIN_USERNAME', ''));"
    ) !== false
        && strpos($migration, "if (\$targetUsername === '')") !== false
        && strpos($migration, 'dmls@dmls.edu.eg') === false
        && strpos($migration, 'LOWER(username) = LOWER(?)') !== false
        && strpos($migration, 'count($matches) !== 1') !== false,
    'bootstrap_is_guarded_transactional_and_audited' => strpos($migration, "role'] !== 'admin'") !== false
        && strpos($migration, "status'] !== 'active'") !== false
        && strpos($migration, 'ActivityLog::logChange(') !== false
        && strpos($migration, '$db->rollBack();') !== false
        && strpos($migration, 'DELETE FROM users') === false,
];

$failed = false;
foreach ($checks as $name => $passed) {
    echo $name . ':' . ($passed ? 'PASS' : 'FAIL') . PHP_EOL;
    $failed = $failed || !$passed;
}

exit($failed ? 1 : 0);
