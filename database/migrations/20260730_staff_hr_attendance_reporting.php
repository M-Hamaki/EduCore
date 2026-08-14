<?php

declare(strict_types=1);

/**
 * Attendance reporting projection foundation.
 *
 * Official day versions remain the source of truth. These tables store only
 * rebuildable report projections and their immutable run evidence; a reopened
 * period retires a current aggregate and appends a successor instead of
 * altering history in place.
 *
 * Rollback in an isolated environment, after disabling the reporting reader:
 * drop staff_attendance_report_aggregates first, then
 * staff_attendance_report_projection_runs. Production reports and their run
 * evidence must be archived, never silently removed.
 */
return static function (PDO $db): void {
    $tableExists = static function (string $table) use ($db): bool {
        $statement = $db->prepare(
            'SELECT COUNT(*) FROM information_schema.TABLES
             WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?'
        );
        $statement->execute([$table]);

        return (int) $statement->fetchColumn() > 0;
    };

    $createTable = static function (string $table, string $ddl) use ($db, $tableExists): void {
        if (!$tableExists($table)) {
            $db->exec($ddl);
        }
    };

    $createTable('staff_attendance_report_projection_runs', <<<'SQL'
CREATE TABLE `staff_attendance_report_projection_runs` (
    `id` BIGINT NOT NULL AUTO_INCREMENT,
    `projection_version` VARCHAR(80) NOT NULL,
    `range_from` DATE NOT NULL,
    `range_to` DATE NOT NULL,
    `initiated_by` INT NULL,
    `status` ENUM('queued','running','completed','failed','blocked') NOT NULL DEFAULT 'queued',
    `source_fingerprint` CHAR(64) NOT NULL,
    `idempotency_key` VARCHAR(190) NOT NULL,
    `summary` JSON NULL,
    `started_at` DATETIME(6) NULL,
    `finished_at` DATETIME(6) NULL,
    `created_at` DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
    PRIMARY KEY (`id`),
    UNIQUE KEY `uk_staff_attendance_report_projection_idempotency` (`idempotency_key`),
    KEY `idx_staff_attendance_report_projection_range` (`range_from`, `range_to`, `status`),
    CONSTRAINT `chk_staff_attendance_report_projection_range` CHECK (`range_to` >= `range_from`),
    CONSTRAINT `chk_staff_attendance_report_projection_source_hash` CHECK (CHAR_LENGTH(`source_fingerprint`) = 64),
    CONSTRAINT `chk_staff_attendance_report_projection_finished` CHECK (
        (`status` = 'queued' AND `started_at` IS NULL AND `finished_at` IS NULL)
        OR (`status` = 'running' AND `started_at` IS NOT NULL AND `finished_at` IS NULL)
        OR (`status` IN ('completed','failed','blocked') AND `started_at` IS NOT NULL AND `finished_at` IS NOT NULL AND `finished_at` >= `started_at`)
    )
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL);

    $createTable('staff_attendance_report_aggregates', <<<'SQL'
CREATE TABLE `staff_attendance_report_aggregates` (
    `id` BIGINT NOT NULL AUTO_INCREMENT,
    `projection_run_id` BIGINT NOT NULL,
    `aggregate_key` CHAR(64) NOT NULL,
    `staff_user_id` INT NOT NULL,
    `granularity` ENUM('monthly','annual','range') NOT NULL,
    `range_from` DATE NOT NULL,
    `range_to` DATE NOT NULL,
    `assignment_id` BIGINT NULL,
    `org_unit_id` BIGINT NULL,
    `job_title_id` BIGINT NULL,
    `group_ids` JSON NULL,
    `eligible_workdays` INT UNSIGNED NOT NULL DEFAULT 0,
    `present_days` INT UNSIGNED NOT NULL DEFAULT 0,
    `absent_days` INT UNSIGNED NOT NULL DEFAULT 0,
    `partial_days` INT UNSIGNED NOT NULL DEFAULT 0,
    `non_working_days` INT UNSIGNED NOT NULL DEFAULT 0,
    `exception_days` INT UNSIGNED NOT NULL DEFAULT 0,
    `approved_permission_days` INT UNSIGNED NOT NULL DEFAULT 0,
    `mission_days` INT UNSIGNED NOT NULL DEFAULT 0,
    `leave_days` INT UNSIGNED NOT NULL DEFAULT 0,
    `required_minutes` INT UNSIGNED NOT NULL DEFAULT 0,
    `worked_minutes` INT UNSIGNED NOT NULL DEFAULT 0,
    `covered_minutes` INT UNSIGNED NOT NULL DEFAULT 0,
    `late_minutes` INT UNSIGNED NOT NULL DEFAULT 0,
    `early_leave_minutes` INT UNSIGNED NOT NULL DEFAULT 0,
    `missing_minutes` INT UNSIGNED NOT NULL DEFAULT 0,
    `reason_summary` JSON NULL,
    `source_fingerprint` CHAR(64) NOT NULL,
    `is_current` TINYINT(1) NOT NULL DEFAULT 1,
    `supersedes_id` BIGINT NULL,
    `created_at` DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
    `current_aggregate_key` CHAR(64) GENERATED ALWAYS AS (
        CASE WHEN `is_current` = 1 THEN `aggregate_key` ELSE NULL END
    ) STORED,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uk_staff_attendance_report_aggregate_run_key` (`projection_run_id`, `aggregate_key`),
    UNIQUE KEY `uk_staff_attendance_report_aggregate_current` (`current_aggregate_key`),
    KEY `idx_staff_attendance_report_aggregate_range` (`range_from`, `range_to`, `granularity`, `staff_user_id`),
    KEY `idx_staff_attendance_report_aggregate_dimensions` (`org_unit_id`, `job_title_id`, `range_from`, `range_to`),
    KEY `idx_staff_attendance_report_aggregate_assignment` (`assignment_id`, `range_from`, `range_to`),
    KEY `idx_staff_attendance_report_aggregate_supersedes` (`supersedes_id`),
    CONSTRAINT `fk_staff_attendance_report_aggregate_run`
        FOREIGN KEY (`projection_run_id`) REFERENCES `staff_attendance_report_projection_runs` (`id`) ON DELETE RESTRICT,
    CONSTRAINT `fk_staff_attendance_report_aggregate_supersedes`
        FOREIGN KEY (`supersedes_id`) REFERENCES `staff_attendance_report_aggregates` (`id`) ON DELETE RESTRICT,
    CONSTRAINT `chk_staff_attendance_report_aggregate_range` CHECK (`range_to` >= `range_from`),
    CONSTRAINT `chk_staff_attendance_report_aggregate_key` CHECK (CHAR_LENGTH(`aggregate_key`) = 64),
    CONSTRAINT `chk_staff_attendance_report_aggregate_source_hash` CHECK (CHAR_LENGTH(`source_fingerprint`) = 64),
    CONSTRAINT `chk_staff_attendance_report_aggregate_current` CHECK (`is_current` IN (0, 1)),
    CONSTRAINT `chk_staff_attendance_report_aggregate_days` CHECK (
        `present_days` + `absent_days` + `partial_days` + `exception_days` <= `eligible_workdays`
    ),
    CONSTRAINT `chk_staff_attendance_report_aggregate_minutes` CHECK (
        `worked_minutes` <= `required_minutes`
        AND `covered_minutes` <= `required_minutes`
        AND `late_minutes` <= `required_minutes`
        AND `early_leave_minutes` <= `required_minutes`
        AND `missing_minutes` <= `required_minutes`
    )
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL);
};
