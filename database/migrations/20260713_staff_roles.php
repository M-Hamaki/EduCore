<?php

return static function (PDO $db): void {
    $db->exec("CREATE TABLE IF NOT EXISTS staff_roles (
        id INT AUTO_INCREMENT PRIMARY KEY,
        role_key VARCHAR(50) NOT NULL UNIQUE,
        role_name VARCHAR(100) NOT NULL,
        portal_type VARCHAR(30) NOT NULL DEFAULT 'admin_like',
        status VARCHAR(20) NOT NULL DEFAULT 'active',
        created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
        updated_at TIMESTAMP NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='أدوار بوابة العاملين المخصصة'");

    $db->exec("CREATE TABLE IF NOT EXISTS staff_role_pages (
        id INT AUTO_INCREMENT PRIMARY KEY,
        role_key VARCHAR(50) NOT NULL,
        page_name VARCHAR(150) NOT NULL,
        created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
        UNIQUE KEY uniq_staff_role_page (role_key, page_name),
        INDEX idx_staff_role_pages_role (role_key)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='صفحات الأدوار الإدارية المخصصة'");

    $stmt = $db->query("SHOW COLUMNS FROM users LIKE 'role'");
    $column = $stmt ? $stmt->fetch(PDO::FETCH_ASSOC) : false;
    if ($column && stripos((string) ($column['Type'] ?? ''), 'enum') !== false) {
        $db->exec('ALTER TABLE users MODIFY role VARCHAR(50) NULL');
    }
};
