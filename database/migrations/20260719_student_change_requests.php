<?php

declare(strict_types=1);

return static function (PDO $db): void {
    $stmt = $db->prepare("SELECT 1 FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'student_change_requests' LIMIT 1");
    $stmt->execute();
    if ($stmt->fetchColumn()) {
        return;
    }

    $db->exec("CREATE TABLE student_change_requests (
        id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        student_id INT NOT NULL,
        specialist_id INT NOT NULL,
        academic_year_id INT NOT NULL,
        before_payload LONGTEXT NOT NULL,
        proposed_payload LONGTEXT NOT NULL,
        status ENUM('pending','approved','rejected','conflict','cancelled') NOT NULL DEFAULT 'pending',
        reviewed_by INT NULL,
        reviewed_at DATETIME NULL,
        rejection_reason VARCHAR(500) NULL,
        created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
        updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        KEY idx_student_change_status (status, created_at),
        KEY idx_student_change_specialist (specialist_id, status),
        KEY idx_student_change_student (student_id, status),
        CONSTRAINT fk_student_change_student FOREIGN KEY (student_id) REFERENCES users(id) ON DELETE RESTRICT,
        CONSTRAINT fk_student_change_specialist FOREIGN KEY (specialist_id) REFERENCES users(id) ON DELETE RESTRICT,
        CONSTRAINT fk_student_change_year FOREIGN KEY (academic_year_id) REFERENCES academic_years(id) ON DELETE RESTRICT,
        CONSTRAINT fk_student_change_reviewer FOREIGN KEY (reviewed_by) REFERENCES users(id) ON DELETE SET NULL
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
};
