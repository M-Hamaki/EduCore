<?php

declare(strict_types=1);

require_once __DIR__ . '/../../config/database.php';

$db = (new Database())->getConnection();
if (!$db instanceof PDO) {
    throw new RuntimeException('Database connection is not available.');
}

$columnExists = static function (string $column) use ($db): bool {
    $stmt = $db->prepare(
        "SELECT 1 FROM information_schema.COLUMNS
         WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'users' AND COLUMN_NAME = ? LIMIT 1"
    );
    $stmt->execute([$column]);
    return (bool) $stmt->fetchColumn();
};

$indexExists = static function (string $index) use ($db): bool {
    $stmt = $db->prepare(
        "SELECT 1 FROM information_schema.STATISTICS
         WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'users' AND INDEX_NAME = ? LIMIT 1"
    );
    $stmt->execute([$index]);
    return (bool) $stmt->fetchColumn();
};

if (!$columnExists('deleted_at')) {
    $db->exec('ALTER TABLE users ADD COLUMN deleted_at DATETIME NULL');
}
if (!$columnExists('archived_by')) {
    $db->exec('ALTER TABLE users ADD COLUMN archived_by INT NULL AFTER deleted_at');
}
if (!$columnExists('archive_reason')) {
    $db->exec('ALTER TABLE users ADD COLUMN archive_reason VARCHAR(500) NULL AFTER archived_by');
}
if (!$columnExists('status_before_archive')) {
    $db->exec('ALTER TABLE users ADD COLUMN status_before_archive VARCHAR(20) NULL AFTER archive_reason');
}
if (!$indexExists('idx_users_role_deleted_at')) {
    $db->exec('CREATE INDEX idx_users_role_deleted_at ON users (role, deleted_at)');
}

echo "Student archiving schema is ready.\n";
