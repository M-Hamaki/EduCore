<?php

/**
 * Migration: grouped assessment schemes, explicit scheme scopes and annual policies.
 *
 * This migration deliberately leaves legacy assessment_schemes rows ungrouped.  Their
 * existing term and annual-weight fields remain the compatibility source until an
 * administrator explicitly creates a new grouped policy; no historical scope or
 * annual-policy relationship is inferred from ambiguous data.
 */

return static function (PDO $db): void {
    $tableExists = static function (string $table) use ($db): bool {
        $stmt = $db->prepare('SELECT 1 FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?');
        $stmt->execute([$table]);
        return (bool) $stmt->fetchColumn();
    };

    $columnExists = static function (string $table, string $column) use ($db): bool {
        $stmt = $db->prepare('SELECT 1 FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?');
        $stmt->execute([$table, $column]);
        return (bool) $stmt->fetchColumn();
    };

    $indexExists = static function (string $table, string $index) use ($db): bool {
        $stmt = $db->prepare('SELECT 1 FROM information_schema.STATISTICS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND INDEX_NAME = ? LIMIT 1');
        $stmt->execute([$table, $index]);
        return (bool) $stmt->fetchColumn();
    };

    $constraintExists = static function (string $table, string $constraint) use ($db): bool {
        $stmt = $db->prepare('SELECT 1 FROM information_schema.TABLE_CONSTRAINTS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND CONSTRAINT_NAME = ? LIMIT 1');
        $stmt->execute([$table, $constraint]);
        return (bool) $stmt->fetchColumn();
    };

    if (!$tableExists('assessment_scheme_families')) {
        $db->exec("CREATE TABLE assessment_scheme_families (
            id INT AUTO_INCREMENT PRIMARY KEY,
            academic_year_id INT NOT NULL,
            subject_id INT NOT NULL,
            name VARCHAR(190) NOT NULL,
            request_key CHAR(64) NOT NULL,
            batch_id CHAR(32) NOT NULL,
            created_by INT NULL,
            archived_at DATETIME NULL,
            created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            UNIQUE KEY uq_scheme_family_request (academic_year_id, request_key),
            KEY idx_scheme_family_subject (academic_year_id, subject_id),
            KEY idx_scheme_family_batch (batch_id),
            CONSTRAINT fk_scheme_family_year FOREIGN KEY (academic_year_id) REFERENCES academic_years(id) ON DELETE CASCADE,
            CONSTRAINT fk_scheme_family_subject FOREIGN KEY (subject_id) REFERENCES subjects(id) ON DELETE CASCADE,
            CONSTRAINT fk_scheme_family_creator FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE SET NULL
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    }

    if (!$tableExists('assessment_scheme_scopes')) {
        $db->exec("CREATE TABLE assessment_scheme_scopes (
            id INT AUTO_INCREMENT PRIMARY KEY,
            scheme_id INT NOT NULL,
            grade_id INT NOT NULL,
            class_id INT NULL,
            scope_identity INT NOT NULL,
            scope_kind VARCHAR(12) NOT NULL,
            created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            UNIQUE KEY uq_scheme_scope (scheme_id, grade_id, scope_identity),
            KEY idx_scheme_scope_grade (grade_id, class_id),
            CONSTRAINT fk_scheme_scope_scheme FOREIGN KEY (scheme_id) REFERENCES assessment_schemes(id) ON DELETE CASCADE,
            CONSTRAINT fk_scheme_scope_grade FOREIGN KEY (grade_id) REFERENCES grades(id) ON DELETE CASCADE,
            CONSTRAINT fk_scheme_scope_class FOREIGN KEY (class_id) REFERENCES classes(id) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    }

    if (!$tableExists('assessment_annual_policies')) {
        $db->exec("CREATE TABLE assessment_annual_policies (
            id INT AUTO_INCREMENT PRIMARY KEY,
            family_id INT NOT NULL,
            is_enabled TINYINT(1) NOT NULL DEFAULT 0,
            created_by INT NULL,
            created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            UNIQUE KEY uq_annual_policy_family (family_id),
            CONSTRAINT fk_annual_policy_family FOREIGN KEY (family_id) REFERENCES assessment_scheme_families(id) ON DELETE CASCADE,
            CONSTRAINT fk_annual_policy_creator FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE SET NULL
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    }

    if (!$tableExists('assessment_annual_policy_terms')) {
        $db->exec("CREATE TABLE assessment_annual_policy_terms (
            id INT AUTO_INCREMENT PRIMARY KEY,
            policy_id INT NOT NULL,
            term_id INT NOT NULL,
            weight DECIMAL(6,3) NOT NULL,
            created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            UNIQUE KEY uq_annual_policy_term (policy_id, term_id),
            KEY idx_annual_policy_term (term_id),
            CONSTRAINT fk_annual_policy_term_policy FOREIGN KEY (policy_id) REFERENCES assessment_annual_policies(id) ON DELETE CASCADE,
            CONSTRAINT fk_annual_policy_term_term FOREIGN KEY (term_id) REFERENCES academic_terms(id) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    }

    if (!$tableExists('assessment_scheme_migration_reviews')) {
        $db->exec("CREATE TABLE assessment_scheme_migration_reviews (
            id INT AUTO_INCREMENT PRIMARY KEY,
            scheme_id INT NOT NULL,
            review_type VARCHAR(80) NOT NULL,
            details VARCHAR(1000) NOT NULL,
            resolved_at DATETIME NULL,
            created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            UNIQUE KEY uq_scheme_migration_review (scheme_id, review_type),
            KEY idx_scheme_migration_review_open (resolved_at),
            CONSTRAINT fk_scheme_migration_review_scheme FOREIGN KEY (scheme_id) REFERENCES assessment_schemes(id) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    }

    if ($tableExists('assessment_schemes')) {
        if (!$columnExists('assessment_schemes', 'family_id')) {
            $db->exec('ALTER TABLE assessment_schemes ADD COLUMN family_id INT NULL AFTER id');
        }
        if (!$columnExists('assessment_schemes', 'readiness_status')) {
            $db->exec("ALTER TABLE assessment_schemes ADD COLUMN readiness_status VARCHAR(40) NOT NULL DEFAULT 'legacy' AFTER status");
        }
        if (!$columnExists('assessment_schemes', 'readiness_reason')) {
            $db->exec('ALTER TABLE assessment_schemes ADD COLUMN readiness_reason VARCHAR(255) NULL AFTER readiness_status');
        }
        if (!$columnExists('assessment_schemes', 'batch_id')) {
            $db->exec('ALTER TABLE assessment_schemes ADD COLUMN batch_id CHAR(32) NULL AFTER copied_from_scheme_id');
        }
        if (!$indexExists('assessment_schemes', 'idx_scheme_family_readiness')) {
            $db->exec('ALTER TABLE assessment_schemes ADD KEY idx_scheme_family_readiness (family_id, readiness_status)');
        }
        if (!$indexExists('assessment_schemes', 'idx_scheme_batch')) {
            $db->exec('ALTER TABLE assessment_schemes ADD KEY idx_scheme_batch (batch_id)');
        }
        if (!$constraintExists('assessment_schemes', 'fk_scheme_family')) {
            $db->exec('ALTER TABLE assessment_schemes ADD CONSTRAINT fk_scheme_family FOREIGN KEY (family_id) REFERENCES assessment_scheme_families(id) ON DELETE SET NULL');
        }

        // Preserve ambiguous legacy policies and flag only objectively invalid weights.
        if ($columnExists('assessment_schemes', 'annual_result_enabled')
            && $columnExists('assessment_schemes', 'first_term_weight')
            && $columnExists('assessment_schemes', 'second_term_weight')) {
            $review = $db->prepare("INSERT IGNORE INTO assessment_scheme_migration_reviews
                (scheme_id, review_type, details)
                SELECT id, 'legacy_annual_weight_review',
                       CONCAT('Legacy annual weights require review: ', first_term_weight, ' + ', second_term_weight)
                FROM assessment_schemes
                WHERE annual_result_enabled = 1
                  AND (first_term_weight < 0 OR second_term_weight < 0
                       OR ABS((first_term_weight + second_term_weight) - 100) > 0.001)");
            $review->execute();
        }
    }
};
