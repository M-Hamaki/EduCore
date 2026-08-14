<?php

require_once __DIR__ . '/../../config/database.php';
$db = (new Database())->getConnection();

$stmt = $db->prepare(
    "SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
     WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'student_profiles' AND COLUMN_NAME = 'previous_school'"
);
$stmt->execute();
if (!$stmt->fetchColumn()) {
    $db->exec("ALTER TABLE student_profiles ADD COLUMN previous_school VARCHAR(255) NULL DEFAULT NULL AFTER enrollment_status");
}

echo "Previous school column is ready.\n";
