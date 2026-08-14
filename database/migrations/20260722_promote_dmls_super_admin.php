<?php

declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/classes/ActivityLog.php';

/**
 * Bootstrap the explicitly nominated first super administrator.
 *
 * The migration is installation-safe: it is a no-op where the exact username
 * does not exist, and it refuses ambiguous, inactive, or non-admin matches.
 */
return static function (PDO $db): void {
    $targetUsername = 'dmls@dmls.edu.eg';
    $ownsTransaction = !$db->inTransaction();
    if ($ownsTransaction) {
        $db->beginTransaction();
    }

    try {
        $stmt = $db->prepare(
            'SELECT id, name, username, role, status FROM users WHERE LOWER(username) = LOWER(?) FOR UPDATE'
        );
        $stmt->execute([$targetUsername]);
        $matches = $stmt->fetchAll(PDO::FETCH_ASSOC);
        if ($matches === []) {
            if ($ownsTransaction) {
                $db->commit();
            }
            return;
        }
        if (count($matches) !== 1) {
            throw new RuntimeException('The nominated super-admin username is not unique.');
        }

        $target = $matches[0];
        if ((string)$target['status'] !== 'active') {
            throw new RuntimeException('The nominated super-admin account must be active.');
        }
        if ((string)$target['role'] === 'super_admin') {
            if ($ownsTransaction) {
                $db->commit();
            }
            return;
        }
        if ((string)$target['role'] !== 'admin') {
            throw new RuntimeException('The nominated account must already be an administrator.');
        }

        $db->prepare('UPDATE users SET role = ? WHERE id = ?')->execute([
            'super_admin',
            (int)$target['id'],
        ]);

        ActivityLog::setDb($db);
        $logged = ActivityLog::logChange(
            'update',
            'staff_account',
            (int)$target['id'],
            (string)$target['name'],
            ['role' => 'admin'],
            ['role' => 'super_admin'],
            [
                'actor_name' => 'Migration 20260722_promote_dmls_super_admin',
                'actor_role' => 'system_migration',
            ]
        );
        if (!$logged) {
            throw new RuntimeException('Could not audit the initial super-admin promotion.');
        }

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
