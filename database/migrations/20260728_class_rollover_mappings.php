<?php

declare(strict_types=1);

/**
 * Persists the reviewed class plan for a year rollover.
 *
 * A source class may have two independent plans:
 * - cohort: create the next-grade class that receives promoted students.
 * - entry_template: keep an empty class template for an intake grade that has
 *   no incoming promotion rule.
 */
return static function (PDO $db): void {
    $tableExists = static function (string $table) use ($db): bool {
        $stmt = $db->prepare(
            'SELECT 1 FROM information_schema.TABLES
             WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? LIMIT 1'
        );
        $stmt->execute([$table]);
        return (bool) $stmt->fetchColumn();
    };

    if ($tableExists('class_rollover_mappings')) {
        return;
    }

    $db->exec("CREATE TABLE class_rollover_mappings (
        id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        source_year_id INT NOT NULL,
        target_year_id INT NOT NULL,
        source_class_id INT NOT NULL,
        mapping_type ENUM('cohort','entry_template') NOT NULL,
        source_grade_id INT NOT NULL,
        target_grade_id INT NOT NULL,
        target_name VARCHAR(100) NOT NULL,
        target_room_location VARCHAR(100) NULL,
        target_capacity SMALLINT UNSIGNED NULL,
        target_display_order INT NOT NULL DEFAULT 0,
        auto_place_students TINYINT(1) NOT NULL DEFAULT 0,
        is_enabled TINYINT(1) NOT NULL DEFAULT 1,
        status ENUM('active','inactive') NOT NULL DEFAULT 'active',
        created_by INT NULL,
        updated_by INT NULL,
        created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
        updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        UNIQUE KEY uq_class_rollover_source_type
            (source_year_id, target_year_id, source_class_id, mapping_type),
        KEY idx_class_rollover_target
            (target_year_id, target_grade_id, status, is_enabled),
        KEY idx_class_rollover_source
            (source_year_id, source_class_id, status),
        CONSTRAINT fk_class_rollover_source_year
            FOREIGN KEY (source_year_id) REFERENCES academic_years(id) ON DELETE RESTRICT,
        CONSTRAINT fk_class_rollover_target_year
            FOREIGN KEY (target_year_id) REFERENCES academic_years(id) ON DELETE RESTRICT,
        CONSTRAINT fk_class_rollover_source_class
            FOREIGN KEY (source_class_id) REFERENCES classes(id) ON DELETE RESTRICT,
        CONSTRAINT fk_class_rollover_source_grade
            FOREIGN KEY (source_grade_id) REFERENCES grades(id) ON DELETE RESTRICT,
        CONSTRAINT fk_class_rollover_target_grade
            FOREIGN KEY (target_grade_id) REFERENCES grades(id) ON DELETE RESTRICT,
        CONSTRAINT fk_class_rollover_creator
            FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE SET NULL,
        CONSTRAINT fk_class_rollover_updater
            FOREIGN KEY (updated_by) REFERENCES users(id) ON DELETE SET NULL
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
};
