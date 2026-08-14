<?php

declare(strict_types=1);

return static function (PDO $db): void {
    $columnExists = static function (string $column) use ($db): bool {
        $stmt = $db->prepare(
            "SELECT COUNT(*) FROM information_schema.COLUMNS
             WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'users' AND COLUMN_NAME = ?"
        );
        $stmt->execute([$column]);
        return (int) $stmt->fetchColumn() > 0;
    };

    if (!$columnExists('login_disabled_reason')) {
        $db->exec("ALTER TABLE users ADD login_disabled_reason VARCHAR(500) NULL AFTER status");
    }
    if (!$columnExists('login_disabled_at')) {
        $db->exec("ALTER TABLE users ADD login_disabled_at DATETIME NULL AFTER login_disabled_reason");
    }
    if (!$columnExists('login_disabled_by')) {
        $db->exec("ALTER TABLE users ADD login_disabled_by INT NULL AFTER login_disabled_at");
    }

    $indexStmt = $db->prepare(
        "SELECT COUNT(*) FROM information_schema.STATISTICS
         WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'users' AND INDEX_NAME = 'idx_users_login_disabled_by'"
    );
    $indexStmt->execute();
    if ((int) $indexStmt->fetchColumn() === 0) {
        $db->exec('ALTER TABLE users ADD INDEX idx_users_login_disabled_by (login_disabled_by)');
    }
};
