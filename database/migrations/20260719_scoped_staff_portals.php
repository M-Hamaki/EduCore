<?php

declare(strict_types=1);

/**
 * Generic annual academic scope for staff portals. Specialist-specific tables
 * remain as rollback evidence, but runtime reads move to the generic tables.
 */
return static function (PDO $db): void {
    $tableExists = static function (string $table) use ($db): bool {
        $stmt = $db->prepare('SELECT 1 FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? LIMIT 1');
        $stmt->execute([$table]);
        return (bool)$stmt->fetchColumn();
    };

    if (!$tableExists('staff_grade_assignments')) {
        $db->exec("CREATE TABLE staff_grade_assignments (
            id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            staff_id INT NOT NULL,
            academic_year_id INT NOT NULL,
            grade_id INT NOT NULL,
            assigned_by INT NULL,
            created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            UNIQUE KEY uq_staff_grade_year (staff_id, academic_year_id, grade_id),
            KEY idx_staff_grade_year (academic_year_id, grade_id),
            CONSTRAINT fk_staff_grade_user FOREIGN KEY (staff_id) REFERENCES users(id) ON DELETE CASCADE,
            CONSTRAINT fk_staff_grade_year FOREIGN KEY (academic_year_id) REFERENCES academic_years(id) ON DELETE RESTRICT,
            CONSTRAINT fk_staff_grade_grade FOREIGN KEY (grade_id) REFERENCES grades(id) ON DELETE RESTRICT,
            CONSTRAINT fk_staff_grade_actor FOREIGN KEY (assigned_by) REFERENCES users(id) ON DELETE SET NULL
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    }

    if (!$tableExists('staff_class_assignments')) {
        $db->exec("CREATE TABLE staff_class_assignments (
            id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            staff_id INT NOT NULL,
            academic_year_id INT NOT NULL,
            class_id INT NOT NULL,
            assigned_by INT NULL,
            created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            UNIQUE KEY uq_staff_class_year (staff_id, academic_year_id, class_id),
            KEY idx_staff_class_year (academic_year_id, class_id),
            CONSTRAINT fk_staff_class_user FOREIGN KEY (staff_id) REFERENCES users(id) ON DELETE CASCADE,
            CONSTRAINT fk_staff_class_year FOREIGN KEY (academic_year_id) REFERENCES academic_years(id) ON DELETE RESTRICT,
            CONSTRAINT fk_staff_class_class FOREIGN KEY (class_id) REFERENCES classes(id) ON DELETE RESTRICT,
            CONSTRAINT fk_staff_class_actor FOREIGN KEY (assigned_by) REFERENCES users(id) ON DELETE SET NULL
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    }

    if ($tableExists('specialist_grade_assignments')) {
        $db->exec("INSERT IGNORE INTO staff_grade_assignments (staff_id, academic_year_id, grade_id, assigned_by, created_at)
            SELECT specialist_id, academic_year_id, grade_id, assigned_by, created_at
            FROM specialist_grade_assignments");
    }
    if ($tableExists('specialist_class_assignments')) {
        $db->exec("INSERT IGNORE INTO staff_class_assignments (staff_id, academic_year_id, class_id, assigned_by, created_at)
            SELECT specialist_id, academic_year_id, class_id, assigned_by, created_at
            FROM specialist_class_assignments");
    }

    $db->exec("CREATE OR REPLACE VIEW staff_active_classes AS
        SELECT sca.staff_id, sca.class_id
        FROM staff_class_assignments sca
        JOIN academic_years ay ON ay.id = sca.academic_year_id AND ay.is_active = 1 AND ay.status = 'active'
        UNION
        SELECT sga.staff_id, c.id AS class_id
        FROM staff_grade_assignments sga
        JOIN academic_years ay ON ay.id = sga.academic_year_id AND ay.is_active = 1 AND ay.status = 'active'
        JOIN classes c ON c.grade_id = sga.grade_id AND c.status = 'active'");

    // Compatibility for legacy read callers during the incremental migration.
    $db->exec("CREATE OR REPLACE VIEW specialist_active_classes AS
        SELECT sac.staff_id AS specialist_id, sac.class_id
        FROM staff_active_classes sac
        JOIN users u ON u.id = sac.staff_id AND u.role = 'specialist'");

    if ($tableExists('staff_roles')) {
        $roles = [
            'doctor' => 'طبيب',
            'librarian' => 'مسؤول مكتبة',
        ];
        $roleStmt = $db->prepare("INSERT INTO staff_roles (role_key, role_name, portal_type, status)
            VALUES (?, ?, 'admin_like', 'active')
            ON DUPLICATE KEY UPDATE role_name = VALUES(role_name), portal_type = 'admin_like', status = 'active'");
        foreach ($roles as $roleKey => $roleName) {
            $roleStmt->execute([$roleKey, $roleName]);
        }
    }
};
