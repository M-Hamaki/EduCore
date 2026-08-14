<?php

declare(strict_types=1);

/** Activate the already scope-hardened clinic page and its read endpoint. */
return static function (PDO $db): void {
    $tableExists = static function (string $table) use ($db): bool {
        $stmt = $db->prepare('SELECT 1 FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? LIMIT 1');
        $stmt->execute([$table]);
        return (bool)$stmt->fetchColumn();
    };
    if (!$tableExists('staff_roles') || !$tableExists('staff_role_pages')) {
        throw new RuntimeException('Staff role schema is not ready.');
    }

    $roleStmt = $db->prepare("INSERT INTO staff_roles (role_key, role_name, portal_type, status)
        VALUES ('doctor', 'طبيب', 'admin_like', 'active')
        ON DUPLICATE KEY UPDATE role_name = VALUES(role_name), portal_type = 'admin_like', status = 'active'");
    $roleStmt->execute();

    $pageStmt = $db->prepare('INSERT IGNORE INTO staff_role_pages (role_key, page_name) VALUES (?, ?)');
    foreach (['student_clinic.php', 'ajax_clinic_datatable.php'] as $page) {
        $pageStmt->execute(['doctor', $page]);
    }
};
