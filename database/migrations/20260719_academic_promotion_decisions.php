<?php

declare(strict_types=1);

/**
 * Explicit single-school promotion rules, durable student decisions, and
 * source-linked annual enrollments. This migration is additive and idempotent.
 */
return static function (PDO $db): void {
    $tableExists = static function (string $table) use ($db): bool {
        $stmt = $db->prepare(
            'SELECT 1 FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? LIMIT 1'
        );
        $stmt->execute([$table]);
        return (bool) $stmt->fetchColumn();
    };
    $columnExists = static function (string $table, string $column) use ($db): bool {
        $stmt = $db->prepare(
            'SELECT 1 FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ? LIMIT 1'
        );
        $stmt->execute([$table, $column]);
        return (bool) $stmt->fetchColumn();
    };
    $indexExists = static function (string $table, string $index) use ($db): bool {
        $stmt = $db->prepare(
            'SELECT 1 FROM information_schema.STATISTICS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND INDEX_NAME = ? LIMIT 1'
        );
        $stmt->execute([$table, $index]);
        return (bool) $stmt->fetchColumn();
    };
    $constraintExists = static function (string $table, string $constraint) use ($db): bool {
        $stmt = $db->prepare(
            'SELECT 1 FROM information_schema.TABLE_CONSTRAINTS WHERE CONSTRAINT_SCHEMA = DATABASE() AND TABLE_NAME = ? AND CONSTRAINT_NAME = ? LIMIT 1'
        );
        $stmt->execute([$table, $constraint]);
        return (bool) $stmt->fetchColumn();
    };

    if (!$columnExists('grades', 'is_experimental')) {
        $db->exec("ALTER TABLE grades ADD COLUMN is_experimental TINYINT(1) NOT NULL DEFAULT 0 AFTER status");
    }
    if (!$indexExists('grades', 'idx_grades_experimental_status')) {
        $db->exec('ALTER TABLE grades ADD KEY idx_grades_experimental_status (is_experimental, status)');
    }

    if (!$columnExists('student_profiles', 'is_test_account')) {
        $db->exec("ALTER TABLE student_profiles ADD COLUMN is_test_account TINYINT(1) NOT NULL DEFAULT 0 AFTER enrollment_status");
    }
    if (!$indexExists('student_profiles', 'idx_student_profiles_test_account')) {
        $db->exec('ALTER TABLE student_profiles ADD KEY idx_student_profiles_test_account (is_test_account)');
    }

    if (!$columnExists('classes', 'capacity')) {
        $db->exec('ALTER TABLE classes ADD COLUMN capacity SMALLINT UNSIGNED NULL AFTER room_location');
    }

    if (!$columnExists('student_enrollments', 'source_enrollment_id')) {
        $db->exec('ALTER TABLE student_enrollments ADD COLUMN source_enrollment_id INT NULL AFTER academic_year_id');
    }
    if (!$columnExists('student_enrollments', 'promotion_decision_id')) {
        $db->exec('ALTER TABLE student_enrollments ADD COLUMN promotion_decision_id BIGINT UNSIGNED NULL AFTER source_enrollment_id');
    }
    if (!$columnExists('student_enrollments', 'is_repeater')) {
        $db->exec('ALTER TABLE student_enrollments ADD COLUMN is_repeater TINYINT(1) NOT NULL DEFAULT 0 AFTER class_id');
    }
    if (!$columnExists('student_enrollments', 'repeat_count')) {
        $db->exec('ALTER TABLE student_enrollments ADD COLUMN repeat_count SMALLINT UNSIGNED NOT NULL DEFAULT 0 AFTER is_repeater');
    }
    if (!$indexExists('student_enrollments', 'idx_enrollments_source')) {
        $db->exec('ALTER TABLE student_enrollments ADD KEY idx_enrollments_source (source_enrollment_id)');
    }
    if (!$indexExists('student_enrollments', 'uq_enrollments_promotion_decision')) {
        $db->exec('ALTER TABLE student_enrollments ADD UNIQUE KEY uq_enrollments_promotion_decision (promotion_decision_id)');
    }
    if (!$constraintExists('student_enrollments', 'fk_enrollment_source')) {
        $db->exec('ALTER TABLE student_enrollments ADD CONSTRAINT fk_enrollment_source FOREIGN KEY (source_enrollment_id) REFERENCES student_enrollments(id) ON DELETE RESTRICT');
    }

    if (!$tableExists('grade_promotion_rules')) {
        $db->exec("CREATE TABLE grade_promotion_rules (
            id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            source_year_id INT NOT NULL,
            target_year_id INT NOT NULL,
            source_grade_id INT NOT NULL,
            rule_type ENUM('promote','graduate') NOT NULL,
            target_grade_id INT NULL,
            status ENUM('active','inactive') NOT NULL DEFAULT 'active',
            created_by INT NULL,
            updated_by INT NULL,
            created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            UNIQUE KEY uq_grade_promotion_year_pair (source_year_id, target_year_id, source_grade_id),
            KEY idx_grade_promotion_target (target_year_id, target_grade_id),
            KEY idx_grade_promotion_status (source_year_id, target_year_id, status),
            CONSTRAINT fk_grade_promotion_source_year FOREIGN KEY (source_year_id) REFERENCES academic_years(id) ON DELETE RESTRICT,
            CONSTRAINT fk_grade_promotion_target_year FOREIGN KEY (target_year_id) REFERENCES academic_years(id) ON DELETE RESTRICT,
            CONSTRAINT fk_grade_promotion_source_grade FOREIGN KEY (source_grade_id) REFERENCES grades(id) ON DELETE RESTRICT,
            CONSTRAINT fk_grade_promotion_target_grade FOREIGN KEY (target_grade_id) REFERENCES grades(id) ON DELETE RESTRICT,
            CONSTRAINT fk_grade_promotion_creator FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE SET NULL,
            CONSTRAINT fk_grade_promotion_updater FOREIGN KEY (updated_by) REFERENCES users(id) ON DELETE SET NULL
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    }

    if (!$tableExists('student_promotion_decisions')) {
        $db->exec("CREATE TABLE student_promotion_decisions (
            id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            source_year_id INT NOT NULL,
            target_year_id INT NOT NULL,
            source_enrollment_id INT NOT NULL,
            student_id INT NOT NULL,
            decision ENUM('promoted','retained','pending','graduated','transferred_out','withdrawn','excluded_test') NOT NULL,
            status ENUM('draft','approved','applied','cancelled') NOT NULL DEFAULT 'draft',
            target_grade_id INT NULL,
            reason_code VARCHAR(100) NULL,
            note VARCHAR(500) NULL,
            decision_source ENUM('rule','manual','system') NOT NULL DEFAULT 'rule',
            source_snapshot_hash CHAR(64) NOT NULL,
            applied_run_id BIGINT UNSIGNED NULL,
            target_enrollment_id INT NULL,
            decided_by INT NULL,
            approved_by INT NULL,
            created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            applied_at DATETIME NULL,
            UNIQUE KEY uq_student_promotion_target (source_enrollment_id, target_year_id),
            UNIQUE KEY uq_student_promotion_enrollment (target_enrollment_id),
            KEY idx_student_promotion_pair_status (source_year_id, target_year_id, status, decision),
            KEY idx_student_promotion_student (student_id, target_year_id),
            KEY idx_student_promotion_run (applied_run_id),
            CONSTRAINT fk_student_promotion_source_year FOREIGN KEY (source_year_id) REFERENCES academic_years(id) ON DELETE RESTRICT,
            CONSTRAINT fk_student_promotion_target_year FOREIGN KEY (target_year_id) REFERENCES academic_years(id) ON DELETE RESTRICT,
            CONSTRAINT fk_student_promotion_source_enrollment FOREIGN KEY (source_enrollment_id) REFERENCES student_enrollments(id) ON DELETE RESTRICT,
            CONSTRAINT fk_student_promotion_student FOREIGN KEY (student_id) REFERENCES users(id) ON DELETE RESTRICT,
            CONSTRAINT fk_student_promotion_target_grade FOREIGN KEY (target_grade_id) REFERENCES grades(id) ON DELETE RESTRICT,
            CONSTRAINT fk_student_promotion_run FOREIGN KEY (applied_run_id) REFERENCES academic_year_rollover_runs(id) ON DELETE RESTRICT,
            CONSTRAINT fk_student_promotion_target_enrollment FOREIGN KEY (target_enrollment_id) REFERENCES student_enrollments(id) ON DELETE SET NULL,
            CONSTRAINT fk_student_promotion_decider FOREIGN KEY (decided_by) REFERENCES users(id) ON DELETE SET NULL,
            CONSTRAINT fk_student_promotion_approver FOREIGN KEY (approved_by) REFERENCES users(id) ON DELETE SET NULL
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    }

    if (!$constraintExists('student_enrollments', 'fk_enrollment_promotion_decision')) {
        $db->exec('ALTER TABLE student_enrollments ADD CONSTRAINT fk_enrollment_promotion_decision FOREIGN KEY (promotion_decision_id) REFERENCES student_promotion_decisions(id) ON DELETE RESTRICT');
    }

    if (!$columnExists('academic_year_rollover_runs', 'decision_fingerprint')) {
        $db->exec('ALTER TABLE academic_year_rollover_runs ADD COLUMN decision_fingerprint CHAR(64) NULL AFTER source_fingerprint');
    }
};
