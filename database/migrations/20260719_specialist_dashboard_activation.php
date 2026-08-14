<?php

declare(strict_types=1);

/** Add the mandatory specialist welcome dashboard to existing installations. */
return static function (PDO $db): void {
    foreach (['staff_roles', 'staff_role_pages'] as $table) {
        $stmt = $db->prepare(
            'SELECT 1 FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? LIMIT 1'
        );
        $stmt->execute([$table]);
        if (!$stmt->fetchColumn()) {
            throw new RuntimeException('Staff role schema is not ready.');
        }
    }

    $roleStmt = $db->prepare("SELECT 1 FROM staff_roles
        WHERE role_key = 'specialist' AND portal_type = 'admin_like' AND status = 'active' LIMIT 1");
    $roleStmt->execute();
    if (!$roleStmt->fetchColumn()) {
        throw new RuntimeException('The active specialist role must exist before dashboard activation.');
    }

    $stmt = $db->prepare('INSERT IGNORE INTO staff_role_pages (role_key, page_name) VALUES (?, ?)');
    $stmt->execute(['specialist', 'specialist_dashboard.php']);
};
