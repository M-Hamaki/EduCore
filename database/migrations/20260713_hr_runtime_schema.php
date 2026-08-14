<?php

return static function (PDO $db): void {
    $db->exec("CREATE TABLE IF NOT EXISTS staff_shift_overrides (
        id INT AUTO_INCREMENT PRIMARY KEY, user_id INT NOT NULL, shift_start TIME NOT NULL,
        shift_end TIME NOT NULL, grace_minutes INT NOT NULL DEFAULT 15, is_active TINYINT(1) NOT NULL DEFAULT 1,
        notes VARCHAR(255) DEFAULT NULL, created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        UNIQUE KEY uq_user (user_id), INDEX idx_active (is_active),
        CONSTRAINT fk_shift_override_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    $db->exec("CREATE TABLE IF NOT EXISTS staff_attendance_audit (
        id INT AUTO_INCREMENT PRIMARY KEY, attendance_id INT NULL, user_id INT NOT NULL,
        attendance_date DATE NOT NULL, action_type ENUM('insert','update','delete','biometric_import') NOT NULL,
        before_data JSON NULL, after_data JSON NULL, changed_by INT NULL, source VARCHAR(30) DEFAULT 'manual',
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP, INDEX idx_user_date (user_id, attendance_date),
        INDEX idx_action_type (action_type), INDEX idx_created_at (created_at)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    $db->exec("CREATE TABLE IF NOT EXISTS staff_biometric_logs (
        id BIGINT AUTO_INCREMENT PRIMARY KEY, user_id INT NOT NULL, log_datetime DATETIME NOT NULL,
        log_type ENUM('in','out','unknown') DEFAULT 'unknown', device_id VARCHAR(100) DEFAULT NULL,
        raw_payload TEXT DEFAULT NULL, imported_by INT NULL, created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        UNIQUE KEY uniq_biometric_log (user_id, log_datetime, log_type, device_id),
        INDEX idx_user_date (user_id, log_datetime), INDEX idx_created_at (created_at)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    $columnExists = static function (string $table, string $column) use ($db): bool {
        $stmt = $db->prepare('SELECT 1 FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?');
        $stmt->execute([$table, $column]);
        return (bool) $stmt->fetchColumn();
    };
    if (!$columnExists('users', 'employee_code')) {
        $db->exec('ALTER TABLE users ADD COLUMN employee_code VARCHAR(50) DEFAULT NULL AFTER name');
    }
    $index = $db->prepare("SELECT 1 FROM information_schema.STATISTICS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'users' AND INDEX_NAME = 'uq_employee_code'");
    $index->execute();
    if (!$index->fetchColumn()) {
        $db->exec('ALTER TABLE users ADD UNIQUE KEY uq_employee_code (employee_code)');
    }
    if (!$columnExists('staff_profiles', 'annual_leave_balance')) {
        $db->exec("ALTER TABLE staff_profiles ADD annual_leave_balance DECIMAL(6,2) DEFAULT 30 COMMENT 'الرصيد السنوي للإجازات بالأيام'");
    }
    if (!$columnExists('staff_profiles', 'leave_balance_notes')) {
        $db->exec("ALTER TABLE staff_profiles ADD leave_balance_notes VARCHAR(255) DEFAULT NULL COMMENT 'ملاحظات رصيد الإجازات'");
    }
};
