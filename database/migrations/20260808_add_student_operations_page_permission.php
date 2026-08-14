<?php

declare(strict_types=1);

/**
 * Grants the dedicated student operation log to the student-affairs role.
 *
 * The specialist role intentionally remains excluded because the page can
 * undo operations created by other actors.  The migration is additive and
 * idempotent; administrators and super administrators require no stored grant.
 */
return static function (PDO $db): void {
    $tableExists = $db->prepare(
        'SELECT 1 FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? LIMIT 1'
    );
    foreach (['staff_roles', 'staff_role_pages'] as $table) {
        $tableExists->execute([$table]);
        if (!(bool) $tableExists->fetchColumn()) {
            return;
        }
    }

    $roleExists = $db->prepare(
        "SELECT 1 FROM staff_roles WHERE role_key = 'student_affairs_manager' AND portal_type = 'admin_like' AND status = 'active' LIMIT 1"
    );
    $roleExists->execute();
    if (!(bool) $roleExists->fetchColumn()) {
        return;
    }

    $grant = $db->prepare('INSERT IGNORE INTO staff_role_pages (role_key, page_name) VALUES (?, ?)');
    $grant->execute(['student_affairs_manager', 'student_operations.php']);
};
