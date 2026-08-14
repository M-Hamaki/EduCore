<?php

require_once __DIR__ . '/../../config/database.php';
$db = (new Database())->getConnection();

$stmt = $db->prepare(
    "SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
     WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'stages' AND COLUMN_NAME = 'portal_visible'"
);
$stmt->execute();
if (!$stmt->fetchColumn()) {
    $db->exec("ALTER TABLE stages ADD COLUMN portal_visible TINYINT(1) NOT NULL DEFAULT 1 AFTER status");
}

echo "Stage portal visibility is ready.\n";
