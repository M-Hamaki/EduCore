<?php

return static function (PDO $db): void {
    $exists = static function (string $column) use ($db): bool {
        $stmt = $db->prepare("SELECT 1 FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'users' AND COLUMN_NAME = ?");
        $stmt->execute([$column]);
        return (bool)$stmt->fetchColumn();
    };
    if (!$exists('password_hash')) {
        $db->exec("ALTER TABLE users ADD COLUMN password_hash VARCHAR(255) NULL AFTER password");
    }
    if (!$exists('password_key_version')) {
        $db->exec("ALTER TABLE users ADD COLUMN password_key_version SMALLINT UNSIGNED NOT NULL DEFAULT 1 AFTER password_hash");
    }
};
