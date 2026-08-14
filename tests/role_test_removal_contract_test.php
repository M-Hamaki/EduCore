<?php

declare(strict_types=1);

$root = dirname(__DIR__);
$migration = (string) file_get_contents(
    $root . '/database/migrations/20260720_remove_role_test.php'
);

$assignedGuard = strpos($migration, "SELECT id FROM users WHERE role = ? LIMIT 1 FOR UPDATE");
$deletePages = strpos($migration, "DELETE FROM staff_role_pages WHERE role_key = ?");
$deleteRole = strpos($migration, "DELETE FROM staff_roles WHERE role_key = ?");

$checks = [
    'targets_only_obsolete_role_test' => substr_count($migration, "'role_test'") >= 4
        && strpos($migration, "'test'") === false,
    'refuses_removal_when_accounts_are_assigned' => $assignedGuard !== false
        && strpos($migration, 'Cannot remove role_test while staff accounts are assigned to it.') !== false,
    'deletes_permissions_before_role' => $deletePages !== false
        && $deleteRole !== false
        && $deletePages < $deleteRole,
    'cleanup_is_transactional_and_idempotent' => strpos($migration, '$db->beginTransaction();') !== false
        && strpos($migration, '$db->commit();') !== false
        && strpos($migration, '$db->rollBack();') !== false
        && strpos($migration, 'if (!$roleStmt->fetchColumn())') !== false,
    'does_not_reassign_or_delete_users' => strpos($migration, 'UPDATE users') === false
        && strpos($migration, 'DELETE FROM users') === false,
];

$failed = false;
foreach ($checks as $name => $passed) {
    echo $name . ':' . ($passed ? 'PASS' : 'FAIL') . PHP_EOL;
    $failed = $failed || !$passed;
}

exit($failed ? 1 : 0);
