<?php

require_once __DIR__ . '/../../config/database.php';
$db = (new Database())->getConnection();

$columns = $db->query("SHOW COLUMNS FROM stages")->fetchAll(PDO::FETCH_COLUMN);

if (!in_array('is_graduate_stage', $columns, true)) {
    $db->exec("ALTER TABLE stages ADD COLUMN is_graduate_stage TINYINT(1) NOT NULL DEFAULT 0 AFTER portal_visible");
}

if (!in_array('portal_description', $columns, true)) {
    $db->exec("ALTER TABLE stages ADD COLUMN portal_description VARCHAR(255) NULL AFTER is_graduate_stage");
}

$db->exec("UPDATE stages SET is_graduate_stage = 1 WHERE LOWER(stage_code) = 'graduates'");

echo "Stage graduate settings are ready.\n";
