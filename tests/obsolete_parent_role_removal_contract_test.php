<?php

declare(strict_types=1);

$root = dirname(__DIR__);
$migration = (string) file_get_contents(
    $root . '/database/migrations/20260722_remove_reserved_parent_role.php'
);
$staffAccounts = (string) file_get_contents($root . '/admin/staff_accounts.php');
$utilities = (string) file_get_contents($root . '/classes/utilities.php');

$assignedGuard = strpos($migration, 'SELECT id FROM users WHERE role = ? LIMIT 1 FOR UPDATE');
$deletePages = strpos($migration, 'DELETE FROM staff_role_pages WHERE role_key = ?');
$deleteRole = strpos($migration, 'DELETE FROM staff_roles WHERE role_key = ?');

$checks = [
    'reserved_parent_role_is_not_presented_or_reserved' => strpos($staffAccounts, "'parent' => ['ولي أمر'") === false
        && strpos($staffAccounts, "'student', 'parent', 'external_teacher'") === false
        && strpos($utilities, "'student', 'parent', 'external_teacher'") === false,
    'migration_refuses_assigned_accounts' => $assignedGuard !== false
        && strpos($migration, 'while user accounts are assigned to it.') !== false,
    'migration_deletes_only_role_configuration' => $deletePages !== false
        && $deleteRole !== false
        && $deletePages < $deleteRole
        && strpos($migration, 'UPDATE users') === false
        && strpos($migration, 'DELETE FROM users') === false,
    'migration_is_transactional_and_repeatable' => strpos($migration, '$db->beginTransaction();') !== false
        && strpos($migration, '$db->commit();') !== false
        && strpos($migration, '$db->rollBack();') !== false,
    'role_table_hides_support_endpoints_and_locks_predefined_roles' => strpos($staffAccounts, 'AdminRolePageCatalog::isSupportingPage($page)') !== false
        && strpos($staffAccounts, 'array_keys(AdminRolePageCatalog::predefinedRoles())') !== false
        && strpos($staffAccounts, 'الأدوار الفعلية والصلاحيات') !== false,
];

$failed = false;
foreach ($checks as $name => $passed) {
    echo $name . ':' . ($passed ? 'PASS' : 'FAIL') . PHP_EOL;
    $failed = $failed || !$passed;
}

exit($failed ? 1 : 0);
