<?php

declare(strict_types=1);

/** Preserve the behavioural family when a configurable administrative role is cloned. */
return static function (PDO $db): void {
    $tableStmt = $db->prepare(
        "SELECT 1 FROM information_schema.TABLES
         WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'staff_roles' LIMIT 1"
    );
    $tableStmt->execute();
    if (!$tableStmt->fetchColumn()) {
        throw new RuntimeException('Staff role schema is not ready.');
    }

    $columnStmt = $db->prepare(
        "SELECT 1 FROM information_schema.COLUMNS
         WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'staff_roles' AND COLUMN_NAME = 'base_role_key' LIMIT 1"
    );
    $columnStmt->execute();
    if (!$columnStmt->fetchColumn()) {
        $db->exec("ALTER TABLE staff_roles
            ADD COLUMN base_role_key VARCHAR(50) NULL AFTER role_name,
            ADD INDEX idx_staff_roles_base_role (base_role_key)");
    }
};
