<?php

return static function (PDO $db): void {
    $columnExists = static function (string $table, string $column) use ($db): bool {
        $stmt = $db->prepare(
            'SELECT 1 FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?'
        );
        $stmt->execute([$table, $column]);
        return (bool) $stmt->fetchColumn();
    };

    $db->exec("CREATE TABLE IF NOT EXISTS biometric_devices (
        id INT AUTO_INCREMENT PRIMARY KEY, device_name VARCHAR(100) NOT NULL, serial_number VARCHAR(50) DEFAULT NULL,
        ip_address VARCHAR(45) NOT NULL, port INT DEFAULT 4370, comm_password INT NOT NULL DEFAULT 0,
        protocol ENUM('auto','TCP','UDP') DEFAULT 'auto', model VARCHAR(50) DEFAULT NULL,
        location_name VARCHAR(200) DEFAULT NULL, is_active TINYINT(1) DEFAULT 1, auto_sync TINYINT(1) DEFAULT 1,
        clear_after_sync TINYINT(1) DEFAULT 0, last_sync_at DATETIME DEFAULT NULL,
        last_sync_status ENUM('success','error','partial') DEFAULT NULL, last_sync_records INT DEFAULT 0,
        last_sync_message TEXT DEFAULT NULL, total_synced_records INT DEFAULT 0,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP, updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        UNIQUE KEY uniq_ip_port (ip_address, port)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    if (!$columnExists('biometric_devices', 'comm_password')) {
        $db->exec('ALTER TABLE biometric_devices ADD COLUMN comm_password INT NOT NULL DEFAULT 0 AFTER port');
    }
    if (!$columnExists('biometric_devices', 'protocol')) {
        $db->exec("ALTER TABLE biometric_devices ADD COLUMN protocol ENUM('auto','TCP','UDP') DEFAULT 'auto' AFTER comm_password");
    }

    $db->exec("CREATE TABLE IF NOT EXISTS biometric_sync_log (
        id BIGINT AUTO_INCREMENT PRIMARY KEY, device_id INT NOT NULL,
        sync_type ENUM('manual','auto','cron') DEFAULT 'manual', started_at DATETIME NOT NULL,
        completed_at DATETIME DEFAULT NULL, status ENUM('success','error','partial') DEFAULT 'success',
        total_records INT DEFAULT 0, new_records INT DEFAULT 0, duplicate_records INT DEFAULT 0,
        unmapped_records INT DEFAULT 0, synced_attendance INT DEFAULT 0, error_message TEXT DEFAULT NULL,
        synced_by INT DEFAULT NULL, created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        INDEX idx_device_id (device_id), INDEX idx_status (status), INDEX idx_created_at (created_at)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    $db->exec("CREATE TABLE IF NOT EXISTS materials (
        id INT AUTO_INCREMENT PRIMARY KEY, stage_id INT NOT NULL, grade_id INT NOT NULL,
        term ENUM('term1','term2') NOT NULL, subject_name VARCHAR(255) NOT NULL,
        file_name VARCHAR(500) NOT NULL, original_file_name VARCHAR(500) NOT NULL, file_size INT DEFAULT 0,
        enabled TINYINT(1) DEFAULT 1, downloadable TINYINT(1) DEFAULT 1, sort_order INT DEFAULT 0,
        uploaded_by INT NULL, academic_year_id INT NULL, created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        INDEX idx_stage_grade_term (stage_id, grade_id, term), INDEX idx_enabled (enabled),
        INDEX idx_academic_year (academic_year_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    if (!$columnExists('classes', 'timetable_image')) {
        $db->exec('ALTER TABLE classes ADD COLUMN timetable_image VARCHAR(255) NULL AFTER status');
    }

    $db->exec("CREATE TABLE IF NOT EXISTS ai_exam_progress (
        id INT AUTO_INCREMENT PRIMARY KEY, exam_code VARCHAR(20) NOT NULL, session_id VARCHAR(64) NOT NULL,
        student_name VARCHAR(100) NOT NULL, student_class VARCHAR(50) DEFAULT '', model_letter CHAR(1) DEFAULT 'A',
        answers_data LONGTEXT, time_remaining INT DEFAULT 0,
        updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        UNIQUE KEY unique_session (exam_code, session_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
};
