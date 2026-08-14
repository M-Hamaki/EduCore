<?php

declare(strict_types=1);

/**
 * Remove the obsolete test-only custom staff role.
 *
 * Rollback, if ever required, is a data restore of the former role and pages:
 * role_test / test / admin_like / active, with academic_year_setup.php and
 * activities_monitor.php. No user assignment is migrated by this cleanup.
 */
return static function (PDO $db): void {
    $tableExists = static function (string $table) use ($db): bool {
        $stmt = $db->prepare(
            'SELECT 1 FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? LIMIT 1'
        );
        $stmt->execute([$table]);
        return (bool) $stmt->fetchColumn();
    };

    foreach (['users', 'staff_roles', 'staff_role_pages'] as $table) {
        if (!$tableExists($table)) {
            throw new RuntimeException('Staff role schema is not ready.');
        }
    }

    $ownsTransaction = !$db->inTransaction();
    if ($ownsTransaction) {
        $db->beginTransaction();
    }

    try {
        $roleStmt = $db->prepare('SELECT id FROM staff_roles WHERE role_key = ? LIMIT 1 FOR UPDATE');
        $roleStmt->execute(['role_test']);
        if (!$roleStmt->fetchColumn()) {
            if ($ownsTransaction) {
                $db->commit();
            }
            return;
        }

        $assignedStmt = $db->prepare('SELECT id FROM users WHERE role = ? LIMIT 1 FOR UPDATE');
        $assignedStmt->execute(['role_test']);
        if ($assignedStmt->fetchColumn()) {
            throw new RuntimeException('Cannot remove role_test while staff accounts are assigned to it.');
        }

        $db->prepare('DELETE FROM staff_role_pages WHERE role_key = ?')->execute(['role_test']);
        $db->prepare('DELETE FROM staff_roles WHERE role_key = ?')->execute(['role_test']);

        if ($ownsTransaction) {
            $db->commit();
        }
    } catch (Throwable $e) {
        if ($ownsTransaction && $db->inTransaction()) {
            $db->rollBack();
        }
        throw $e;
    }
};
