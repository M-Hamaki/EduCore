<?php

declare(strict_types=1);

/**
 * Attendance period close/reopen control.
 *
 * Period control is deliberately separate from calculated-day versions. A
 * late biometric event or a newly approved/reversed coverage source must
 * become a durable, idempotent change fact when its month is closed; it must
 * never rewrite an official attendance day or affect payroll silently.
 *
 * Ownership: Attendance owns both tables. Staff, Finance, and other modules
 * publish only a narrow affected-day request through the Attendance contract.
 *
 * Rollback in an isolated environment, after stopping new period consumers:
 * drop staff_attendance_period_change_requests first, then
 * staff_attendance_periods. Production closure and review history is audit
 * evidence and must be archived rather than silently discarded.
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

    $createTable('staff_attendance_periods', <<<'SQL'
CREATE TABLE `staff_attendance_periods` (
    `id` BIGINT NOT NULL AUTO_INCREMENT,
    `period_key` CHAR(7) NOT NULL,
    `period_start` DATE NOT NULL,
    `period_end` DATE NOT NULL,
    `state` ENUM('open','closed') NOT NULL DEFAULT 'open',
    `last_closed_run_id` BIGINT NULL,
    `closed_by` INT NULL,
    `closed_at` DATETIME(6) NULL,
    `reopened_by` INT NULL,
    `reopened_at` DATETIME(6) NULL,
    `close_reason_hash` CHAR(64) NULL,
    `lock_version` INT UNSIGNED NOT NULL DEFAULT 1,
    `created_at` DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
    `updated_at` DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6) ON UPDATE CURRENT_TIMESTAMP(6),
    PRIMARY KEY (`id`),
    UNIQUE KEY `uk_staff_attendance_period_key` (`period_key`),
    KEY `idx_staff_attendance_period_state` (`state`, `period_start`),
    KEY `idx_staff_attendance_period_last_run` (`last_closed_run_id`),
    CONSTRAINT `fk_staff_attendance_period_last_run`
        FOREIGN KEY (`last_closed_run_id`) REFERENCES `staff_attendance_runs` (`id`) ON DELETE RESTRICT,
    CONSTRAINT `chk_staff_attendance_period_key`
        CHECK (`period_key` REGEXP '^[0-9]{4}-[0-9]{2}$'),
    CONSTRAINT `chk_staff_attendance_period_month_bounds`
        CHECK (
            DAY(`period_start`) = 1
            AND `period_end` = LAST_DAY(`period_start`)
            AND YEAR(`period_start`) = CAST(LEFT(`period_key`, 4) AS UNSIGNED)
            AND MONTH(`period_start`) = CAST(RIGHT(`period_key`, 2) AS UNSIGNED)
        ),
    CONSTRAINT `chk_staff_attendance_period_lock_version` CHECK (`lock_version` > 0),
    CONSTRAINT `chk_staff_attendance_period_close_hash`
        CHECK (`close_reason_hash` IS NULL OR CHAR_LENGTH(`close_reason_hash`) = 64),
    CONSTRAINT `chk_staff_attendance_period_closed_metadata`
        CHECK (`state` <> 'closed' OR (`closed_by` IS NOT NULL AND `closed_at` IS NOT NULL))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL);

    $createTable('staff_attendance_period_change_requests', <<<'SQL'
CREATE TABLE `staff_attendance_period_change_requests` (
    `id` BIGINT NOT NULL AUTO_INCREMENT,
    `period_id` BIGINT NOT NULL,
    `request_type` ENUM(
        'late_event','coverage_approved','coverage_reversed','leave_approved',
        'leave_reversed','schedule_correction','calendar_correction','manual_recalculation'
    ) NOT NULL,
    `source_type` VARCHAR(80) NOT NULL,
    `source_id` BIGINT NULL,
    `staff_user_id` INT NULL,
    `work_date` DATE NULL,
    `source_fingerprint` CHAR(64) NOT NULL,
    `reason_code` VARCHAR(80) NOT NULL,
    `status` ENUM('pending','ready','approved','rejected','applied','cancelled') NOT NULL DEFAULT 'pending',
    `idempotency_key` VARCHAR(190) NOT NULL,
    `request_hash` CHAR(64) NOT NULL,
    `change_fingerprint` CHAR(64) NOT NULL,
    `decision_idempotency_key` VARCHAR(190) NULL,
    `decision_hash` CHAR(64) NULL,
    `requested_by` INT NOT NULL,
    `requested_at` DATETIME(6) NOT NULL,
    `reviewed_by` INT NULL,
    `reviewed_at` DATETIME(6) NULL,
    `review_comment_hash` CHAR(64) NULL,
    `applied_run_id` BIGINT NULL,
    `applied_at` DATETIME(6) NULL,
    `lock_version` INT UNSIGNED NOT NULL DEFAULT 1,
    `created_at` DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
    `updated_at` DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6) ON UPDATE CURRENT_TIMESTAMP(6),
    PRIMARY KEY (`id`),
    UNIQUE KEY `uk_staff_attendance_period_change_idempotency` (`idempotency_key`),
    UNIQUE KEY `uk_staff_attendance_period_change_decision_idempotency` (`decision_idempotency_key`),
    UNIQUE KEY `uk_staff_attendance_period_change_fact` (`period_id`, `change_fingerprint`),
    KEY `idx_staff_attendance_period_change_review` (`period_id`, `status`, `requested_at`),
    KEY `idx_staff_attendance_period_change_staff_date` (`staff_user_id`, `work_date`, `status`),
    KEY `idx_staff_attendance_period_change_source` (`source_type`, `source_id`),
    KEY `idx_staff_attendance_period_change_run` (`applied_run_id`),
    CONSTRAINT `fk_staff_attendance_period_change_period`
        FOREIGN KEY (`period_id`) REFERENCES `staff_attendance_periods` (`id`) ON DELETE RESTRICT,
    CONSTRAINT `fk_staff_attendance_period_change_run`
        FOREIGN KEY (`applied_run_id`) REFERENCES `staff_attendance_runs` (`id`) ON DELETE RESTRICT,
    CONSTRAINT `chk_staff_attendance_period_change_source_type`
        CHECK (CHAR_LENGTH(TRIM(`source_type`)) > 0),
    CONSTRAINT `chk_staff_attendance_period_change_source_hash`
        CHECK (CHAR_LENGTH(`source_fingerprint`) = 64),
    CONSTRAINT `chk_staff_attendance_period_change_reason`
        CHECK (CHAR_LENGTH(TRIM(`reason_code`)) > 0),
    CONSTRAINT `chk_staff_attendance_period_change_request_hash`
        CHECK (CHAR_LENGTH(`request_hash`) = 64),
    CONSTRAINT `chk_staff_attendance_period_change_fingerprint`
        CHECK (CHAR_LENGTH(`change_fingerprint`) = 64),
    CONSTRAINT `chk_staff_attendance_period_change_decision_hash`
        CHECK (`decision_hash` IS NULL OR CHAR_LENGTH(`decision_hash`) = 64),
    CONSTRAINT `chk_staff_attendance_period_change_review_hash`
        CHECK (`review_comment_hash` IS NULL OR CHAR_LENGTH(`review_comment_hash`) = 64),
    CONSTRAINT `chk_staff_attendance_period_change_lock_version` CHECK (`lock_version` > 0),
    CONSTRAINT `chk_staff_attendance_period_change_applied`
        CHECK (`status` <> 'applied' OR (`applied_run_id` IS NOT NULL AND `applied_at` IS NOT NULL))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL);
};
