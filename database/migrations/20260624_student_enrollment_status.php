<?php

require_once __DIR__ . '/../../config/database.php';
$db = (new Database())->getConnection();

$columns = $db->query("SHOW COLUMNS FROM student_profiles")->fetchAll(PDO::FETCH_COLUMN);
if (!in_array('enrollment_status', $columns, true)) {
    $db->exec("ALTER TABLE student_profiles ADD COLUMN enrollment_status ENUM('enrolled','graduated','transferred') NOT NULL DEFAULT 'enrolled' AFTER enrollment_date");
}

$db->exec("UPDATE student_profiles sp JOIN users u ON u.id = sp.user_id
           SET sp.enrollment_status = 'graduated'
           WHERE u.role = 'student' AND u.status = 'graduated'");

$db->exec("CREATE TABLE IF NOT EXISTS student_external_transfers (
    id INT AUTO_INCREMENT PRIMARY KEY,
    student_id INT NOT NULL,
    destination VARCHAR(255) NOT NULL,
    transfer_date DATE NOT NULL,
    reason TEXT NULL,
    notes TEXT NULL,
    transferred_by INT NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uq_student_external_transfer (student_id),
    KEY idx_external_transfer_date (transfer_date),
    CONSTRAINT fk_external_transfer_student FOREIGN KEY (student_id) REFERENCES users(id) ON DELETE CASCADE,
    CONSTRAINT fk_external_transfer_user FOREIGN KEY (transferred_by) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

echo "Student enrollment status is ready.\n";
