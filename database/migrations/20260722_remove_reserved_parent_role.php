<?php

declare(strict_types=1);

/**
 * Remove the unused reserved parent role from the user-role model.
 *
 * There is no parent portal or parent authentication flow in the current
 * system. The migration refuses to continue if historical user accounts are
 * assigned to the key so no account is silently deleted or reclassified.
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
        $assignedStmt = $db->prepare('SELECT id FROM users WHERE role = ? LIMIT 1 FOR UPDATE');
        $assignedStmt->execute(['parent']);
        if ($assignedStmt->fetchColumn()) {
            throw new RuntimeException('Cannot remove the reserved parent role while user accounts are assigned to it.');
        }

        $db->prepare('DELETE FROM staff_role_pages WHERE role_key = ?')->execute(['parent']);
        $db->prepare('DELETE FROM staff_roles WHERE role_key = ?')->execute(['parent']);

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
