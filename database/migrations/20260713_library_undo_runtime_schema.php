<?php

return static function (PDO $db): void {
    $columns = static function (string $table) use ($db): array {
        $stmt = $db->prepare(
            'SELECT COLUMN_NAME FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?'
        );
        $stmt->execute([$table]);
        return $stmt->fetchAll(PDO::FETCH_COLUMN);
    };

    $fineColumns = $columns('library_fines');
    if (!in_array('loan_id', $fineColumns, true)) {
        $db->exec('ALTER TABLE library_fines ADD loan_id INT NULL AFTER id');
    }
    if (!in_array('student_id', $fineColumns, true)) {
        if (in_array('user_id', $fineColumns, true)) {
            $db->exec('ALTER TABLE library_fines CHANGE user_id student_id INT NOT NULL');
        } else {
            $db->exec('ALTER TABLE library_fines ADD student_id INT NOT NULL AFTER loan_id');
        }
    }
    $fineDefinitions = [
        'amount' => 'DECIMAL(10,2) NOT NULL DEFAULT 0 AFTER student_id',
        'reason' => 'VARCHAR(255) NULL AFTER amount',
        'paid' => 'TINYINT(1) NOT NULL DEFAULT 0 AFTER reason',
        'paid_at' => 'DATE NULL AFTER paid',
        'notes' => 'TEXT NULL AFTER paid_at',
    ];
    foreach ($fineDefinitions as $name => $definition) {
        if (!in_array($name, $fineColumns, true)) {
            $db->exec("ALTER TABLE library_fines ADD `$name` $definition");
        }
    }

    $bookColumns = $columns('library_books');
    $bookDefinitions = [
        'author' => 'VARCHAR(255) NULL AFTER title',
        'category' => 'VARCHAR(150) NULL AFTER author',
        'isbn' => 'VARCHAR(80) NULL AFTER category',
        'copies_total' => 'INT NOT NULL DEFAULT 1 AFTER isbn',
        'copies_available' => 'INT NOT NULL DEFAULT 1 AFTER copies_total',
        'location' => 'VARCHAR(150) NULL AFTER copies_available',
        'status' => "ENUM('active','inactive') NOT NULL DEFAULT 'active' AFTER location",
        'notes' => 'TEXT NULL AFTER status',
    ];
    foreach ($bookDefinitions as $name => $definition) {
        if (!in_array($name, $bookColumns, true)) {
            $db->exec("ALTER TABLE library_books ADD `$name` $definition");
        }
    }

    $db->exec("CREATE TABLE IF NOT EXISTS undo_log (
        id INT AUTO_INCREMENT PRIMARY KEY, user_id INT NOT NULL,
        action_type ENUM('insert','update','delete') NOT NULL, table_name VARCHAR(100) NOT NULL,
        record_id VARCHAR(255) NOT NULL, old_data JSON DEFAULT NULL, new_data JSON DEFAULT NULL,
        description VARCHAR(500) DEFAULT NULL, page_url VARCHAR(255) DEFAULT NULL,
        is_undone TINYINT(1) DEFAULT 0, created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        INDEX idx_user_undo (user_id, is_undone, created_at), INDEX idx_created (created_at)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    $db->exec("CREATE TABLE IF NOT EXISTS recycle_bin (
        id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY, undo_log_id INT NULL, deleted_by INT NOT NULL,
        table_name VARCHAR(100) NOT NULL, record_id VARCHAR(255) NOT NULL, record_data JSON NOT NULL,
        description VARCHAR(500) DEFAULT NULL, restored_at DATETIME DEFAULT NULL, expires_at DATETIME NOT NULL,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP, UNIQUE KEY uq_recycle_undo (undo_log_id),
        INDEX idx_recycle_active (restored_at, expires_at), INDEX idx_recycle_record (table_name, record_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
};
