<?php

declare(strict_types=1);

/**
 * Annual specialist academic scope. Existing class assignments are copied to
 * the active academic year, while the legacy table remains untouched for rollback.
 */
return static function (PDO $db): void {
    $tableExists = static function (string $table) use ($db): bool {
        $stmt = $db->prepare('SELECT 1 FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? LIMIT 1');
        $stmt->execute([$table]);
        return (bool)$stmt->fetchColumn();
    };

    if (!$tableExists('specialist_grade_assignments')) {
        $db->exec("CREATE TABLE specialist_grade_assignments (
            id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            specialist_id INT NOT NULL,
            academic_year_id INT NOT NULL,
            grade_id INT NOT NULL,
            assigned_by INT NULL,
            created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            UNIQUE KEY uq_specialist_grade_year (specialist_id, academic_year_id, grade_id),
            KEY idx_specialist_grade_year (academic_year_id, grade_id),
            CONSTRAINT fk_specialist_grade_user FOREIGN KEY (specialist_id) REFERENCES users(id) ON DELETE CASCADE,
            CONSTRAINT fk_specialist_grade_year FOREIGN KEY (academic_year_id) REFERENCES academic_years(id) ON DELETE RESTRICT,
            CONSTRAINT fk_specialist_grade_grade FOREIGN KEY (grade_id) REFERENCES grades(id) ON DELETE RESTRICT,
            CONSTRAINT fk_specialist_grade_actor FOREIGN KEY (assigned_by) REFERENCES users(id) ON DELETE SET NULL
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    }

    if (!$tableExists('specialist_class_assignments')) {
        $db->exec("CREATE TABLE specialist_class_assignments (
            id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            specialist_id INT NOT NULL,
            academic_year_id INT NOT NULL,
            class_id INT NOT NULL,
            assigned_by INT NULL,
            created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            UNIQUE KEY uq_specialist_class_year (specialist_id, academic_year_id, class_id),
            KEY idx_specialist_class_year (academic_year_id, class_id),
            CONSTRAINT fk_specialist_class_user FOREIGN KEY (specialist_id) REFERENCES users(id) ON DELETE CASCADE,
            CONSTRAINT fk_specialist_class_year FOREIGN KEY (academic_year_id) REFERENCES academic_years(id) ON DELETE RESTRICT,
            CONSTRAINT fk_specialist_class_class FOREIGN KEY (class_id) REFERENCES classes(id) ON DELETE RESTRICT,
            CONSTRAINT fk_specialist_class_actor FOREIGN KEY (assigned_by) REFERENCES users(id) ON DELETE SET NULL
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    }

    if ($tableExists('specialist_classes')) {
        $db->exec("INSERT IGNORE INTO specialist_class_assignments
                (specialist_id, academic_year_id, class_id, assigned_by)
            SELECT sc.specialist_id, ay.id, sc.class_id, NULL
            FROM specialist_classes sc
            JOIN users u ON u.id = sc.specialist_id AND u.role = 'specialist'
            JOIN academic_years ay ON ay.is_active = 1 AND ay.status = 'active'");
    }

    $db->exec("CREATE OR REPLACE VIEW specialist_active_classes AS
        SELECT sca.specialist_id, sca.class_id
        FROM specialist_class_assignments sca
        JOIN academic_years ay ON ay.id = sca.academic_year_id AND ay.is_active = 1 AND ay.status = 'active'
        UNION
        SELECT sga.specialist_id, c.id AS class_id
        FROM specialist_grade_assignments sga
        JOIN academic_years ay ON ay.id = sga.academic_year_id AND ay.is_active = 1 AND ay.status = 'active'
        JOIN classes c ON c.grade_id = sga.grade_id AND c.status = 'active'");
};
