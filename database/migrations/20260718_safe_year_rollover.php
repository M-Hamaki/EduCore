<?php

declare(strict_types=1);

/**
 * Recovery receipts and manifest-owned academic-year rollover runs.
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

    if (!$tableExists('recovery_backups')) {
        $db->exec("CREATE TABLE recovery_backups (
            id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            backup_key CHAR(32) NOT NULL,
            status ENUM('creating','created','verifying','verified','failed') NOT NULL DEFAULT 'creating',
            package_path VARCHAR(500) NOT NULL,
            package_sha256 CHAR(64) NULL,
            manifest_sha256 CHAR(64) NULL,
            database_fingerprint CHAR(64) NULL,
            source_fingerprint CHAR(64) NULL,
            files_fingerprint CHAR(64) NULL,
            database_name VARCHAR(128) NOT NULL,
            test_database_name VARCHAR(128) NULL,
            verification_summary LONGTEXT NULL,
            failure_code VARCHAR(100) NULL,
            created_by INT NULL,
            created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            verified_at DATETIME NULL,
            expires_at DATETIME NULL,
            UNIQUE KEY uq_recovery_backup_key (backup_key),
            KEY idx_recovery_status_verified (status, verified_at),
            CONSTRAINT fk_recovery_creator FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE SET NULL
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    }
    if (!$columnExists('recovery_backups', 'source_fingerprint')) {
        $db->exec('ALTER TABLE recovery_backups ADD COLUMN source_fingerprint CHAR(64) NULL AFTER database_fingerprint');
    }

    if (!$tableExists('academic_year_rollover_runs')) {
        $db->exec("CREATE TABLE academic_year_rollover_runs (
            id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            run_key CHAR(32) NOT NULL,
            source_year_id INT NOT NULL,
            target_year_id INT NOT NULL,
            recovery_backup_id BIGINT UNSIGNED NULL,
            status ENUM('previewed','executing','completed','verified','rolled_back','activated','failed') NOT NULL DEFAULT 'previewed',
            source_fingerprint CHAR(64) NULL,
            preflight_summary LONGTEXT NULL,
            execution_summary LONGTEXT NULL,
            verification_summary LONGTEXT NULL,
            audit_batch_id VARCHAR(64) NULL,
            created_by INT NULL,
            created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            executed_at DATETIME NULL,
            verified_at DATETIME NULL,
            rolled_back_at DATETIME NULL,
            activated_at DATETIME NULL,
            UNIQUE KEY uq_rollover_run_key (run_key),
            KEY idx_rollover_target_status (target_year_id, status),
            KEY idx_rollover_source (source_year_id),
            CONSTRAINT fk_rollover_source_year FOREIGN KEY (source_year_id) REFERENCES academic_years(id) ON DELETE RESTRICT,
            CONSTRAINT fk_rollover_target_year FOREIGN KEY (target_year_id) REFERENCES academic_years(id) ON DELETE RESTRICT,
            CONSTRAINT fk_rollover_recovery FOREIGN KEY (recovery_backup_id) REFERENCES recovery_backups(id) ON DELETE RESTRICT,
            CONSTRAINT fk_rollover_creator FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE SET NULL
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    }

    if (!$tableExists('academic_year_rollover_items')) {
        $db->exec("CREATE TABLE academic_year_rollover_items (
            id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            run_id BIGINT UNSIGNED NOT NULL,
            entity_table VARCHAR(100) NOT NULL,
            source_record_id VARCHAR(100) NULL,
            target_record_id VARCHAR(100) NOT NULL,
            dependency_order SMALLINT UNSIGNED NOT NULL,
            action ENUM('insert','update') NOT NULL DEFAULT 'insert',
            created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            UNIQUE KEY uq_rollover_owned_target (run_id, entity_table, target_record_id),
            KEY idx_rollover_item_dependency (run_id, dependency_order, id),
            CONSTRAINT fk_rollover_item_run FOREIGN KEY (run_id) REFERENCES academic_year_rollover_runs(id) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    }
};
