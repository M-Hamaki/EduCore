<?php

return static function (PDO $db): void {
    $columnExists = static function (string $table, string $column) use ($db): bool {
        $stmt = $db->prepare('SELECT 1 FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?');
        $stmt->execute([$table, $column]);
        return (bool)$stmt->fetchColumn();
    };
    $indexExists = static function (string $table, string $index) use ($db): bool {
        $stmt = $db->prepare('SELECT 1 FROM information_schema.STATISTICS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND INDEX_NAME = ?');
        $stmt->execute([$table, $index]);
        return (bool)$stmt->fetchColumn();
    };
    $fkExists = static function (string $table, string $constraint) use ($db): bool {
        $stmt = $db->prepare('SELECT 1 FROM information_schema.TABLE_CONSTRAINTS WHERE CONSTRAINT_SCHEMA = DATABASE() AND TABLE_NAME = ? AND CONSTRAINT_NAME = ?');
        $stmt->execute([$table, $constraint]);
        return (bool)$stmt->fetchColumn();
    };
    $addColumn = static function (string $table, string $column, string $definition) use ($db, $columnExists): void {
        if (!$columnExists($table, $column)) {
            $db->exec("ALTER TABLE `$table` ADD COLUMN `$column` $definition");
        }
    };
    $addIndex = static function (string $table, string $index, string $columns) use ($db, $indexExists): void {
        if (!$indexExists($table, $index)) {
            $db->exec("CREATE INDEX `$index` ON `$table` ($columns)");
        }
    };
    $addFk = static function (string $table, string $name, string $column, string $parent, string $onDelete) use ($db, $fkExists): void {
        if (!$fkExists($table, $name)) {
            $db->exec("ALTER TABLE `$table` ADD CONSTRAINT `$name` FOREIGN KEY (`$column`) REFERENCES `$parent` (`id`) ON DELETE $onDelete");
        }
    };

    $addIndex('evaluations', 'idx_eval_student_type_date', '`student_id`, `evaluation_type_id`, `date_created`');
    $addIndex('evaluations', 'idx_eval_class_created', '`class_id`, `date_created`');
    $addIndex('attendance', 'idx_attendance_student_class_date', '`student_id`, `class_id`, `attendance_date`');
    $addIndex('notifications', 'idx_notifications_active_created', '`is_active`, `created_at`');
    $addIndex('notification_targets', 'idx_notification_target_lookup', '`notification_id`, `target_type`, `target_id`');
    $addIndex('student_grades', 'idx_student_grades_student_column', '`student_id`, `grade_column_id`');
    $addIndex('activity_logs', 'idx_activity_action_created', '`action`, `created_at`');

    $addFk('attendance', 'fk_attendance_student', 'student_id', 'users', 'CASCADE');
    $addFk('attendance', 'fk_attendance_class', 'class_id', 'classes', 'CASCADE');
    $addFk('attendance', 'fk_attendance_recorder', 'recorded_by', 'users', 'RESTRICT');
    $addFk('student_grades', 'fk_student_grades_student', 'student_id', 'users', 'CASCADE');
    $addFk('student_grades', 'fk_student_grades_column', 'grade_column_id', 'grade_columns', 'CASCADE');
    $addFk('student_grades', 'fk_student_grades_updater', 'updated_by', 'users', 'SET NULL');
    $addFk('notifications', 'fk_notifications_creator', 'created_by', 'users', 'RESTRICT');

    foreach (['evaluations', 'student_grades', 'attendance', 'fee_payments'] as $table) {
        $addColumn($table, 'created_by', 'INT NULL');
        $addColumn($table, 'updated_by', 'INT NULL');
        $addColumn($table, 'deleted_at', 'DATETIME NULL');
        $addIndex($table, 'idx_' . $table . '_deleted_at', '`deleted_at`');
    }
    $addColumn('evaluations', 'updated_at', 'TIMESTAMP NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP');
    $addColumn('fee_payments', 'updated_at', 'TIMESTAMP NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP');
    $addColumn('users', 'password_hash', 'VARCHAR(255) NULL AFTER `password`');
    $addColumn('users', 'password_key_version', 'SMALLINT UNSIGNED NOT NULL DEFAULT 1 AFTER `password_hash`');

    $db->exec("CREATE TABLE IF NOT EXISTS audit_logs (
        id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        table_name VARCHAR(64) NOT NULL,
        record_id BIGINT UNSIGNED NOT NULL,
        action ENUM('create','update','delete','restore') NOT NULL,
        actor_id INT NULL,
        old_values LONGTEXT NULL,
        new_values LONGTEXT NULL,
        ip_address VARCHAR(45) NULL,
        created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
        INDEX idx_audit_record (table_name, record_id, created_at),
        INDEX idx_audit_actor (actor_id, created_at),
        CONSTRAINT fk_audit_actor FOREIGN KEY (actor_id) REFERENCES users(id) ON DELETE SET NULL
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
};
