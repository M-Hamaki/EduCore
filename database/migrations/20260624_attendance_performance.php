<?php

require_once __DIR__ . '/../../config/database.php';
$db = (new Database())->getConnection();

$stmt = $db->prepare(
    "SELECT COUNT(*)
     FROM INFORMATION_SCHEMA.STATISTICS
     WHERE TABLE_SCHEMA = DATABASE()
       AND TABLE_NAME = 'attendance'
       AND INDEX_NAME = 'idx_attendance_date_status_class'"
);
$stmt->execute();
if (!$stmt->fetchColumn()) {
    $db->exec(
        'ALTER TABLE attendance
         ADD INDEX idx_attendance_date_status_class (attendance_date, status, class_id)'
    );
}

echo "Attendance reporting index is ready.\n";
