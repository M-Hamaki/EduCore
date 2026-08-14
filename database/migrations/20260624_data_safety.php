<?php

require_once __DIR__ . '/../../config/database.php';
$db = (new Database())->getConnection();

$column = $db->prepare(
    "SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
     WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'users' AND COLUMN_NAME = 'deleted_at'"
);
$column->execute();
if (!$column->fetchColumn()) {
    $db->exec('ALTER TABLE users ADD COLUMN deleted_at DATETIME NULL, ADD INDEX idx_users_deleted_at (deleted_at)');
}

require_once __DIR__ . '/../../classes/UndoManager.php';
UndoManager::setDb($db);
UndoManager::getLastUndoable(0);

echo "Data safety schema is ready.\n";
