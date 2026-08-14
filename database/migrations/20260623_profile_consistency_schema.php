<?php

return static function (PDO $db): void {
    $stmt = $db->query("SELECT CHARACTER_MAXIMUM_LENGTH
                        FROM information_schema.COLUMNS
                        WHERE TABLE_SCHEMA = DATABASE()
                          AND TABLE_NAME = 'users'
                          AND COLUMN_NAME = 'name'");
    $length = (int)$stmt->fetchColumn();
    if ($length > 0 && $length < 255) {
        $db->exec("ALTER TABLE users MODIFY name VARCHAR(255) NOT NULL");
    }
};
