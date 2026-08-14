<?php

declare(strict_types=1);

/**
 * Allow one internal account to own multiple roles while keeping academic
 * scopes isolated by role and academic year.
 */
return static function (PDO $db): void {
    $tableExists = static function (string $table) use ($db): bool {
        $stmt = $db->prepare(
            'SELECT 1 FROM information_schema.TABLES
             WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? LIMIT 1'
        );
        $stmt->execute([$table]);
        return (bool)$stmt->fetchColumn();
    };

    $columnExists = static function (string $table, string $column) use ($db): bool {
        $stmt = $db->prepare(
            'SELECT 1 FROM information_schema.COLUMNS
             WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ? LIMIT 1'
        );
        $stmt->execute([$table, $column]);
        return (bool)$stmt->fetchColumn();
    };

    $indexExists = static function (string $table, string $index) use ($db): bool {
        $stmt = $db->prepare(
            'SELECT 1 FROM information_schema.STATISTICS
             WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND INDEX_NAME = ? LIMIT 1'
        );
        $stmt->execute([$table, $index]);
        return (bool)$stmt->fetchColumn();
    };

    if (!$tableExists('staff_roles')) {
        throw new RuntimeException('Staff role schema is not ready.');
    }

    if (!$tableExists('user_role_assignments')) {
        $db->exec("CREATE TABLE user_role_assignments (
            id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            user_id INT NOT NULL,
            role_key VARCHAR(50) NOT NULL,
            is_primary TINYINT(1) NOT NULL DEFAULT 0,
            status ENUM('active', 'inactive') NOT NULL DEFAULT 'active',
            assigned_by INT NULL,
            created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            UNIQUE KEY uq_user_role_assignment (user_id, role_key),
            KEY idx_user_role_active (user_id, status),
            KEY idx_role_assignment_active (role_key, status),
            CONSTRAINT fk_user_role_assignment_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
            CONSTRAINT fk_user_role_assignment_role FOREIGN KEY (role_key) REFERENCES staff_roles(role_key) ON DELETE RESTRICT ON UPDATE CASCADE,
            CONSTRAINT fk_user_role_assignment_actor FOREIGN KEY (assigned_by) REFERENCES users(id) ON DELETE SET NULL
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    }

    // Backfill the legacy scalar role as the primary role without changing it.
    $db->exec("INSERT IGNORE INTO user_role_assignments (user_id, role_key, is_primary, status)
        SELECT u.id, u.role, 1, 'active'
        FROM users u
        JOIN staff_roles sr ON sr.role_key = u.role
        WHERE u.role IS NOT NULL AND u.role <> ''");

    foreach (['staff_grade_assignments', 'staff_class_assignments'] as $table) {
        if (!$tableExists($table)) {
            throw new RuntimeException('Staff academic scope schema is not ready.');
        }
        if (!$columnExists($table, 'role_key')) {
            $db->exec("ALTER TABLE {$table} ADD COLUMN role_key VARCHAR(50) NULL AFTER staff_id");
        }

        // Existing rows predate role-aware scopes. Preserve them under the
        // current legacy role, with specialist as a non-destructive fallback.
        $db->exec("UPDATE {$table} a
            LEFT JOIN users u ON u.id = a.staff_id
            LEFT JOIN staff_roles sr ON sr.role_key = u.role
            SET a.role_key = COALESCE(sr.role_key, 'specialist')
            WHERE a.role_key IS NULL OR a.role_key = ''");

        $nullStmt = $db->query("SELECT COUNT(*) FROM {$table} WHERE role_key IS NULL OR role_key = ''");
        if ((int)$nullStmt->fetchColumn() > 0) {
            throw new RuntimeException("Unable to assign role ownership to existing {$table} rows.");
        }

        $db->exec("ALTER TABLE {$table} MODIFY role_key VARCHAR(50) NOT NULL");
    }

    if (!$indexExists('staff_grade_assignments', 'uq_staff_role_grade_year')) {
        $db->exec('ALTER TABLE staff_grade_assignments
            ADD UNIQUE KEY uq_staff_role_grade_year (staff_id, role_key, academic_year_id, grade_id),
            ADD KEY idx_staff_role_grade_year (staff_id, role_key, academic_year_id),
            ADD CONSTRAINT fk_staff_grade_role FOREIGN KEY (role_key) REFERENCES staff_roles(role_key) ON DELETE RESTRICT ON UPDATE CASCADE');
    }
    // Add the replacement first: MySQL may be using the legacy unique index
    // to support the staff_id foreign key and refuses to drop it otherwise.
    if ($indexExists('staff_grade_assignments', 'uq_staff_grade_year')) {
        $db->exec('ALTER TABLE staff_grade_assignments DROP INDEX uq_staff_grade_year');
    }

    if (!$indexExists('staff_class_assignments', 'uq_staff_role_class_year')) {
        $db->exec('ALTER TABLE staff_class_assignments
            ADD UNIQUE KEY uq_staff_role_class_year (staff_id, role_key, academic_year_id, class_id),
            ADD KEY idx_staff_role_class_year (staff_id, role_key, academic_year_id),
            ADD CONSTRAINT fk_staff_class_role FOREIGN KEY (role_key) REFERENCES staff_roles(role_key) ON DELETE RESTRICT ON UPDATE CASCADE');
    }
    if ($indexExists('staff_class_assignments', 'uq_staff_class_year')) {
        $db->exec('ALTER TABLE staff_class_assignments DROP INDEX uq_staff_class_year');
    }

    $db->exec("CREATE OR REPLACE VIEW staff_active_role_classes AS
        SELECT sca.staff_id, sca.role_key, sca.class_id
        FROM staff_class_assignments sca
        JOIN academic_years ay ON ay.id = sca.academic_year_id AND ay.is_active = 1 AND ay.status = 'active'
        UNION
        SELECT sga.staff_id, sga.role_key, c.id AS class_id
        FROM staff_grade_assignments sga
        JOIN academic_years ay ON ay.id = sga.academic_year_id AND ay.is_active = 1 AND ay.status = 'active'
        JOIN classes c ON c.grade_id = sga.grade_id AND c.status = 'active'");

    // Keep the legacy union view available while every caller is migrated.
    $db->exec("CREATE OR REPLACE VIEW staff_active_classes AS
        SELECT DISTINCT staff_id, class_id FROM staff_active_role_classes");

    $db->exec("CREATE OR REPLACE VIEW specialist_active_classes AS
        SELECT sarc.staff_id AS specialist_id, sarc.class_id
        FROM staff_active_role_classes sarc
        LEFT JOIN staff_roles sr ON sr.role_key = sarc.role_key
        WHERE sarc.role_key = 'specialist' OR sr.base_role_key = 'specialist'");
};
