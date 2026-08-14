<?php

declare(strict_types=1);

/**
 * Integrated staff-HR attendance evidence and calculated-day foundation.
 *
 * Ownership:
 * - Attendance entry methods and raw events belong to the Attendance ingestion
 *   boundary; they deliberately do not live in the schedule/calendar schema.
 * - Device time, receipt time, and normalized time are separate evidence.
 * - Raw evidence is append-only. Corrections create/link derived versions; they
 *   never rewrite a device punch.
 * - A calculated day is versioned and has at most one official version.
 *
 * Migration ordering:
 * This file sorts before the organization, schedule, and workflow migrations
 * dated the same day. Their cross-module identifiers are therefore indexed
 * scalar references validated through module contracts rather than fragile
 * cross-migration FKs.
 * Only tables owned by this migration reference one another.
 *
 * Rollback in an isolated environment (after disabling Attendance consumers):
 * drop migration-owned triggers, then drop tables in this order:
 * staff_attendance_adjustments, staff_attendance_reason_lines,
 * staff_attendance_segments, staff_attendance_day_versions,
 * staff_attendance_runs, staff_biometric_events,
 * staff_biometric_identity_mappings, staff_biometric_import_batches,
 * staff_attendance_entry_methods. Production evidence requires an archived
 * export and an approved cutover rollback; it must never be silently dropped.
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
    $triggerExists = static function (string $trigger) use ($db): bool {
        $statement = $db->prepare(
            'SELECT COUNT(*) FROM information_schema.TRIGGERS
             WHERE TRIGGER_SCHEMA = DATABASE() AND TRIGGER_NAME = ?'
        );
        $statement->execute([$trigger]);

        return (int) $statement->fetchColumn() > 0;
    };
    $createTable = static function (string $table, string $ddl) use ($db, $tableExists): void {
        if (!$tableExists($table)) {
            $db->exec($ddl);
        }
    };
    $createTrigger = static function (string $trigger, string $ddl) use ($db, $triggerExists): void {
        if (!$triggerExists($trigger)) {
            $db->exec($ddl);
        }
    };

    $createTable('staff_attendance_entry_methods', <<<'SQL'
CREATE TABLE `staff_attendance_entry_methods` (
    `id` INT NOT NULL AUTO_INCREMENT,
    `code` VARCHAR(80) NOT NULL,
    `name` VARCHAR(200) NOT NULL,
    `method_type` ENUM('biometric','manual_verified','device_fallback','access_log') NOT NULL,
    `requires_reason` TINYINT(1) NOT NULL DEFAULT 0,
    `requires_attachment` TINYINT(1) NOT NULL DEFAULT 0,
    `requires_review` TINYINT(1) NOT NULL DEFAULT 1,
    `allowed_scope` VARCHAR(50) NOT NULL,
    `status` ENUM('active','inactive','retired') NOT NULL DEFAULT 'active',
    `created_by` INT NULL,
    `created_at` DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
    `updated_at` DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6) ON UPDATE CURRENT_TIMESTAMP(6),
    PRIMARY KEY (`id`),
    UNIQUE KEY `uk_staff_attendance_entry_method_code` (`code`),
    KEY `idx_staff_attendance_entry_method_use` (`status`, `method_type`, `allowed_scope`),
    CONSTRAINT `chk_staff_attendance_entry_method_code` CHECK (CHAR_LENGTH(TRIM(`code`)) > 0),
    CONSTRAINT `chk_staff_attendance_entry_method_name` CHECK (CHAR_LENGTH(TRIM(`name`)) > 0),
    CONSTRAINT `chk_staff_attendance_entry_method_scope` CHECK (CHAR_LENGTH(TRIM(`allowed_scope`)) > 0),
    CONSTRAINT `chk_staff_attendance_entry_method_reason` CHECK (`requires_reason` IN (0, 1)),
    CONSTRAINT `chk_staff_attendance_entry_method_attachment` CHECK (`requires_attachment` IN (0, 1)),
    CONSTRAINT `chk_staff_attendance_entry_method_review` CHECK (`requires_review` IN (0, 1))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL);

    $createTable('staff_biometric_import_batches', <<<'SQL'
CREATE TABLE `staff_biometric_import_batches` (
    `id` BIGINT NOT NULL AUTO_INCREMENT,
    `source_type` ENUM('device_pull','file_import','api','manual') NOT NULL,
    `device_id` INT NULL,
    `file_fingerprint` CHAR(64) NULL,
    `idempotency_key` VARCHAR(190) NOT NULL,
    `request_hash` CHAR(64) NOT NULL,
    `started_at` DATETIME(6) NOT NULL,
    `finished_at` DATETIME(6) NULL,
    `status` ENUM('pending','processing','completed','partial','failed') NOT NULL DEFAULT 'pending',
    `row_counts` JSON NULL,
    `error_summary` TEXT NULL,
    `initiated_by` INT NULL,
    `created_at` DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
    PRIMARY KEY (`id`),
    UNIQUE KEY `uk_staff_biometric_batch_idempotency` (`idempotency_key`),
    UNIQUE KEY `uk_staff_biometric_batch_file_fingerprint` (`file_fingerprint`),
    KEY `idx_staff_biometric_batch_device_status` (`device_id`, `status`, `started_at`),
    CONSTRAINT `chk_staff_biometric_batch_file_hash` CHECK (`file_fingerprint` IS NULL OR CHAR_LENGTH(`file_fingerprint`) = 64),
    CONSTRAINT `chk_staff_biometric_batch_request_hash` CHECK (CHAR_LENGTH(`request_hash`) = 64),
    CONSTRAINT `chk_staff_biometric_batch_dates` CHECK (`finished_at` IS NULL OR `finished_at` >= `started_at`),
    CONSTRAINT `chk_staff_biometric_batch_finished` CHECK (
        (`status` IN ('pending','processing') AND `finished_at` IS NULL)
        OR (`status` IN ('completed','partial','failed') AND `finished_at` IS NOT NULL)
    )
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL);

    $createTable('staff_biometric_identity_mappings', <<<'SQL'
CREATE TABLE `staff_biometric_identity_mappings` (
    `id` BIGINT NOT NULL AUTO_INCREMENT,
    `device_id` INT NOT NULL,
    `biometric_identity` VARCHAR(100) NOT NULL,
    `staff_user_id` INT NOT NULL,
    `valid_from` DATETIME(6) NOT NULL,
    `valid_to` DATETIME(6) NULL,
    `source` VARCHAR(50) NOT NULL,
    `confirmed_by` INT NULL,
    `retired_reason` VARCHAR(1000) NULL,
    `created_at` DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
    `active_identity_key` VARCHAR(220) GENERATED ALWAYS AS (
        CASE
            WHEN `valid_to` IS NULL THEN CONCAT(CAST(`device_id` AS CHAR), ':', `biometric_identity`)
            ELSE NULL
        END
    ) STORED,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uk_staff_biometric_mapping_identity_start` (`device_id`, `biometric_identity`, `valid_from`),
    UNIQUE KEY `uk_staff_biometric_mapping_active_identity` (`active_identity_key`),
    KEY `idx_staff_biometric_mapping_resolution` (`device_id`, `biometric_identity`, `valid_from`, `valid_to`),
    KEY `idx_staff_biometric_mapping_staff_effective` (`staff_user_id`, `valid_from`, `valid_to`),
    CONSTRAINT `chk_staff_biometric_mapping_identity` CHECK (CHAR_LENGTH(TRIM(`biometric_identity`)) > 0),
    CONSTRAINT `chk_staff_biometric_mapping_dates` CHECK (`valid_to` IS NULL OR `valid_to` > `valid_from`),
    CONSTRAINT `chk_staff_biometric_mapping_retirement` CHECK (
        (`valid_to` IS NULL AND `retired_reason` IS NULL)
        OR (`valid_to` IS NOT NULL AND CHAR_LENGTH(TRIM(COALESCE(`retired_reason`, ''))) > 0)
    )
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL);

    $createTable('staff_biometric_events', <<<'SQL'
CREATE TABLE `staff_biometric_events` (
    `id` BIGINT NOT NULL AUTO_INCREMENT,
    `batch_id` BIGINT NULL,
    `entry_method_id` INT NOT NULL,
    `device_id` INT NULL,
    `external_event_key` VARCHAR(190) NULL,
    `idempotency_key` VARCHAR(190) NOT NULL,
    `biometric_identity` VARCHAR(100) NULL,
    `identity_mapping_id` BIGINT NULL,
    `staff_user_id` INT NULL,
    `device_event_at` DATETIME(6) NOT NULL,
    `received_at` DATETIME(6) NOT NULL,
    `device_timezone` VARCHAR(64) NOT NULL DEFAULT 'Africa/Cairo',
    `normalized_event_at_utc` DATETIME(6) NULL,
    `event_at_local` DATETIME(6) NULL,
    `clock_offset_seconds` INT NULL,
    `clock_status` ENUM('trusted','drifted','unknown','invalid') NOT NULL DEFAULT 'unknown',
    `event_type` ENUM('in','out','break_start','break_end','unknown') NOT NULL DEFAULT 'unknown',
    `raw_hash` CHAR(64) NOT NULL,
    `raw_payload_ref` VARCHAR(500) NULL,
    `link_status` ENUM('matched','unmatched','ambiguous','retired_mapping','manual_review') NOT NULL DEFAULT 'unmatched',
    `link_reason` VARCHAR(1000) NULL,
    `processing_order` INT UNSIGNED NOT NULL DEFAULT 0,
    `recorded_by` INT NULL,
    `reason_text` VARCHAR(1000) NULL,
    `attachment_ref` VARCHAR(500) NULL,
    `review_status` ENUM('pending','approved','rejected','not_required') NOT NULL DEFAULT 'pending',
    `reviewed_by` INT NULL,
    `reviewed_at` DATETIME(6) NULL,
    `created_at` DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
    PRIMARY KEY (`id`),
    UNIQUE KEY `uk_staff_biometric_event_idempotency` (`idempotency_key`),
    UNIQUE KEY `uk_staff_biometric_event_device_external` (`device_id`, `external_event_key`),
    UNIQUE KEY `uk_staff_biometric_event_device_raw_hash` (`device_id`, `raw_hash`),
    KEY `idx_staff_biometric_event_staff_window` (`staff_user_id`, `event_at_local`, `link_status`),
    KEY `idx_staff_biometric_event_identity_window` (`device_id`, `biometric_identity`, `device_event_at`),
    KEY `idx_staff_biometric_event_received` (`received_at`, `clock_status`),
    KEY `idx_staff_biometric_event_batch_order` (`batch_id`, `processing_order`, `id`),
    CONSTRAINT `fk_staff_biometric_event_batch` FOREIGN KEY (`batch_id`) REFERENCES `staff_biometric_import_batches` (`id`) ON DELETE RESTRICT,
    CONSTRAINT `fk_staff_biometric_event_method` FOREIGN KEY (`entry_method_id`) REFERENCES `staff_attendance_entry_methods` (`id`) ON DELETE RESTRICT,
    CONSTRAINT `fk_staff_biometric_event_mapping` FOREIGN KEY (`identity_mapping_id`) REFERENCES `staff_biometric_identity_mappings` (`id`) ON DELETE RESTRICT,
    CONSTRAINT `chk_staff_biometric_event_raw_hash` CHECK (CHAR_LENGTH(`raw_hash`) = 64),
    CONSTRAINT `chk_staff_biometric_event_timezone` CHECK (CHAR_LENGTH(TRIM(`device_timezone`)) > 0),
    CONSTRAINT `chk_staff_biometric_event_clock_normalization` CHECK (
        `clock_status` IN ('unknown','invalid')
        OR (`normalized_event_at_utc` IS NOT NULL AND `event_at_local` IS NOT NULL AND `clock_offset_seconds` IS NOT NULL)
    ),
    CONSTRAINT `chk_staff_biometric_event_device_identity` CHECK (`device_id` IS NOT NULL OR `biometric_identity` IS NULL),
    CONSTRAINT `chk_staff_biometric_event_link` CHECK (
        (`link_status` = 'matched' AND `staff_user_id` IS NOT NULL)
        OR (`link_status` <> 'matched' AND `staff_user_id` IS NULL)
    ),
    CONSTRAINT `chk_staff_biometric_event_unmatched_mapping` CHECK (`link_status` <> 'unmatched' OR `identity_mapping_id` IS NULL),
    CONSTRAINT `chk_staff_biometric_event_review` CHECK (
        (`review_status` IN ('approved','rejected') AND `reviewed_by` IS NOT NULL AND `reviewed_at` IS NOT NULL)
        OR (`review_status` IN ('pending','not_required') AND `reviewed_by` IS NULL AND `reviewed_at` IS NULL)
    ),
    CONSTRAINT `chk_staff_biometric_event_self_review` CHECK (`reviewed_by` IS NULL OR `recorded_by` IS NULL OR `reviewed_by` <> `recorded_by`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL);

    $createTable('staff_attendance_runs', <<<'SQL'
CREATE TABLE `staff_attendance_runs` (
    `id` BIGINT NOT NULL AUTO_INCREMENT,
    `engine_version` VARCHAR(80) NOT NULL,
    `mode` ENUM('shadow','official','recalculation') NOT NULL,
    `range_from` DATE NOT NULL,
    `range_to` DATE NOT NULL,
    `cutoff_at` DATETIME(6) NOT NULL,
    `initiated_by` INT NOT NULL,
    `status` ENUM('queued','running','completed','failed','blocked') NOT NULL DEFAULT 'queued',
    `source_fingerprint` CHAR(64) NOT NULL,
    `idempotency_key` VARCHAR(190) NOT NULL,
    `summary` JSON NULL,
    `supersedes_run_id` BIGINT NULL,
    `started_at` DATETIME(6) NULL,
    `finished_at` DATETIME(6) NULL,
    `created_at` DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
    PRIMARY KEY (`id`),
    UNIQUE KEY `uk_staff_attendance_run_idempotency` (`idempotency_key`),
    UNIQUE KEY `uk_staff_attendance_run_supersedes` (`supersedes_run_id`),
    KEY `idx_staff_attendance_run_range` (`range_from`, `range_to`, `mode`, `status`),
    CONSTRAINT `fk_staff_attendance_run_supersedes` FOREIGN KEY (`supersedes_run_id`) REFERENCES `staff_attendance_runs` (`id`) ON DELETE RESTRICT,
    CONSTRAINT `chk_staff_attendance_run_range` CHECK (`range_to` >= `range_from`),
    CONSTRAINT `chk_staff_attendance_run_source_hash` CHECK (CHAR_LENGTH(`source_fingerprint`) = 64),
    CONSTRAINT `chk_staff_attendance_run_finished` CHECK (
        (`status` = 'queued' AND `started_at` IS NULL AND `finished_at` IS NULL)
        OR (`status` = 'running' AND `started_at` IS NOT NULL AND `finished_at` IS NULL)
        OR (`status` IN ('completed','failed','blocked') AND `started_at` IS NOT NULL AND `finished_at` IS NOT NULL AND `finished_at` >= `started_at`)
    )
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL);

    $createTable('staff_attendance_day_versions', <<<'SQL'
CREATE TABLE `staff_attendance_day_versions` (
    `id` BIGINT NOT NULL AUTO_INCREMENT,
    `staff_user_id` INT NOT NULL,
    `work_date` DATE NOT NULL,
    `version_no` INT UNSIGNED NOT NULL,
    `run_id` BIGINT NOT NULL,
    `assignment_id` BIGINT NULL,
    `schedule_policy_version_id` BIGINT NULL,
    `calendar_exception_id` BIGINT NULL,
    `expected_start` DATETIME(6) NULL,
    `expected_end` DATETIME(6) NULL,
    `required_minutes` INT UNSIGNED NOT NULL DEFAULT 0,
    `first_in` DATETIME(6) NULL,
    `last_out` DATETIME(6) NULL,
    `worked_minutes` INT UNSIGNED NOT NULL DEFAULT 0,
    `covered_late_minutes` INT UNSIGNED NOT NULL DEFAULT 0,
    `covered_early_minutes` INT UNSIGNED NOT NULL DEFAULT 0,
    `mission_minutes` INT UNSIGNED NOT NULL DEFAULT 0,
    `leave_minutes` INT UNSIGNED NOT NULL DEFAULT 0,
    `late_minutes` INT UNSIGNED NOT NULL DEFAULT 0,
    `early_leave_minutes` INT UNSIGNED NOT NULL DEFAULT 0,
    `missing_minutes` INT UNSIGNED NOT NULL DEFAULT 0,
    `status` ENUM('present','absent','partial','non_working','exception','unresolved') NOT NULL,
    `calculation_mode` ENUM('shadow','official','recalculation') NOT NULL,
    `engine_version` VARCHAR(80) NOT NULL,
    `source_fingerprint` CHAR(64) NOT NULL,
    `is_official` TINYINT(1) NOT NULL DEFAULT 0,
    `officialized_by` INT NULL,
    `officialized_at` DATETIME(6) NULL,
    `supersedes_id` BIGINT NULL,
    `calculated_at` DATETIME(6) NOT NULL,
    `created_at` DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
    `official_work_date` DATE GENERATED ALWAYS AS (
        CASE WHEN `is_official` = 1 THEN `work_date` ELSE NULL END
    ) STORED,
    `official_supersedes_id` BIGINT GENERATED ALWAYS AS (
        CASE WHEN `officialized_at` IS NOT NULL THEN `supersedes_id` ELSE NULL END
    ) STORED,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uk_staff_attendance_day_version` (`staff_user_id`, `work_date`, `version_no`),
    UNIQUE KEY `uk_staff_attendance_day_idempotent` (`staff_user_id`, `work_date`, `source_fingerprint`, `engine_version`, `calculation_mode`),
    UNIQUE KEY `uk_staff_attendance_day_official` (`staff_user_id`, `official_work_date`),
    UNIQUE KEY `uk_staff_attendance_day_supersedes` (`official_supersedes_id`),
    KEY `idx_staff_attendance_day_supersedes` (`supersedes_id`),
    KEY `idx_staff_attendance_day_report` (`work_date`, `status`, `is_official`, `staff_user_id`),
    KEY `idx_staff_attendance_day_assignment_snapshot` (`assignment_id`, `work_date`),
    KEY `idx_staff_attendance_day_schedule_snapshot` (`schedule_policy_version_id`, `work_date`),
    KEY `idx_staff_attendance_day_calendar_snapshot` (`calendar_exception_id`, `work_date`),
    CONSTRAINT `fk_staff_attendance_day_run` FOREIGN KEY (`run_id`) REFERENCES `staff_attendance_runs` (`id`) ON DELETE RESTRICT,
    CONSTRAINT `fk_staff_attendance_day_supersedes` FOREIGN KEY (`supersedes_id`) REFERENCES `staff_attendance_day_versions` (`id`) ON DELETE RESTRICT,
    CONSTRAINT `chk_staff_attendance_day_version_no` CHECK (`version_no` > 0),
    CONSTRAINT `chk_staff_attendance_day_expected_window` CHECK (
        (`expected_start` IS NULL AND `expected_end` IS NULL)
        OR (`expected_start` IS NOT NULL AND `expected_end` IS NOT NULL AND `expected_end` > `expected_start`)
    ),
    CONSTRAINT `chk_staff_attendance_day_actual_window` CHECK (`last_out` IS NULL OR `first_in` IS NULL OR `last_out` >= `first_in`),
    CONSTRAINT `chk_staff_attendance_day_source_hash` CHECK (CHAR_LENGTH(`source_fingerprint`) = 64),
    CONSTRAINT `chk_staff_attendance_day_official` CHECK (
        `is_official` = 0
        OR (`is_official` = 1 AND `calculation_mode` IN ('official','recalculation') AND `status` NOT IN ('exception','unresolved') AND `officialized_by` IS NOT NULL AND `officialized_at` IS NOT NULL)
    )
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL);

    $createTable('staff_attendance_segments', <<<'SQL'
CREATE TABLE `staff_attendance_segments` (
    `id` BIGINT NOT NULL AUTO_INCREMENT,
    `day_version_id` BIGINT NOT NULL,
    `sequence_no` SMALLINT UNSIGNED NOT NULL,
    `segment_type` ENUM('work','paid_break','unpaid_break','mission','leave','overtime') NOT NULL,
    `expected_start` DATETIME(6) NULL,
    `expected_end` DATETIME(6) NULL,
    `actual_start` DATETIME(6) NULL,
    `actual_end` DATETIME(6) NULL,
    `required_minutes` INT UNSIGNED NOT NULL DEFAULT 0,
    `worked_minutes` INT UNSIGNED NOT NULL DEFAULT 0,
    `covered_minutes` INT UNSIGNED NOT NULL DEFAULT 0,
    `missing_minutes` INT UNSIGNED NOT NULL DEFAULT 0,
    `entry_event_id` BIGINT NULL,
    `exit_event_id` BIGINT NULL,
    `status` ENUM('matched','partial','missing','covered','not_required') NOT NULL,
    `created_at` DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
    PRIMARY KEY (`id`),
    UNIQUE KEY `uk_staff_attendance_segment_sequence` (`day_version_id`, `sequence_no`),
    KEY `idx_staff_attendance_segment_entry_event` (`entry_event_id`),
    KEY `idx_staff_attendance_segment_exit_event` (`exit_event_id`),
    CONSTRAINT `fk_staff_attendance_segment_day` FOREIGN KEY (`day_version_id`) REFERENCES `staff_attendance_day_versions` (`id`) ON DELETE RESTRICT,
    CONSTRAINT `fk_staff_attendance_segment_entry_event` FOREIGN KEY (`entry_event_id`) REFERENCES `staff_biometric_events` (`id`) ON DELETE RESTRICT,
    CONSTRAINT `fk_staff_attendance_segment_exit_event` FOREIGN KEY (`exit_event_id`) REFERENCES `staff_biometric_events` (`id`) ON DELETE RESTRICT,
    CONSTRAINT `chk_staff_attendance_segment_sequence` CHECK (`sequence_no` > 0),
    CONSTRAINT `chk_staff_attendance_segment_expected` CHECK (
        (`expected_start` IS NULL AND `expected_end` IS NULL)
        OR (`expected_start` IS NOT NULL AND `expected_end` IS NOT NULL AND `expected_end` > `expected_start`)
    ),
    CONSTRAINT `chk_staff_attendance_segment_actual` CHECK (
        (`actual_start` IS NULL AND `actual_end` IS NULL)
        OR (`actual_start` IS NOT NULL AND (`actual_end` IS NULL OR `actual_end` >= `actual_start`))
    ),
    CONSTRAINT `chk_staff_attendance_segment_events` CHECK (`entry_event_id` IS NULL OR `exit_event_id` IS NULL OR `entry_event_id` <> `exit_event_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL);

    $createTable('staff_attendance_reason_lines', <<<'SQL'
CREATE TABLE `staff_attendance_reason_lines` (
    `id` BIGINT NOT NULL AUTO_INCREMENT,
    `day_version_id` BIGINT NOT NULL,
    `line_no` SMALLINT UNSIGNED NOT NULL,
    `reason_code` VARCHAR(80) NOT NULL,
    `from_at` DATETIME(6) NULL,
    `to_at` DATETIME(6) NULL,
    `minutes` INT UNSIGNED NOT NULL DEFAULT 0,
    `source_type` VARCHAR(50) NOT NULL,
    `source_id` BIGINT NULL,
    `explanation` VARCHAR(1000) NOT NULL,
    `metadata` JSON NULL,
    `created_at` DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
    PRIMARY KEY (`id`),
    UNIQUE KEY `uk_staff_attendance_reason_sequence` (`day_version_id`, `line_no`),
    KEY `idx_staff_attendance_reason_code` (`reason_code`, `source_type`),
    KEY `idx_staff_attendance_reason_source` (`source_type`, `source_id`),
    CONSTRAINT `fk_staff_attendance_reason_day` FOREIGN KEY (`day_version_id`) REFERENCES `staff_attendance_day_versions` (`id`) ON DELETE RESTRICT,
    CONSTRAINT `chk_staff_attendance_reason_sequence` CHECK (`line_no` > 0),
    CONSTRAINT `chk_staff_attendance_reason_code` CHECK (CHAR_LENGTH(TRIM(`reason_code`)) > 0),
    CONSTRAINT `chk_staff_attendance_reason_window` CHECK (
        (`from_at` IS NULL AND `to_at` IS NULL)
        OR (`from_at` IS NOT NULL AND `to_at` IS NOT NULL AND `to_at` > `from_at`)
    ),
    CONSTRAINT `chk_staff_attendance_reason_explanation` CHECK (CHAR_LENGTH(TRIM(`explanation`)) > 0)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL);

    $createTable('staff_attendance_adjustments', <<<'SQL'
CREATE TABLE `staff_attendance_adjustments` (
    `id` BIGINT NOT NULL AUTO_INCREMENT,
    `staff_user_id` INT NOT NULL,
    `work_date` DATE NOT NULL,
    `requester_id` INT NOT NULL,
    `requester_kind` ENUM('self','manager','hr') NOT NULL,
    `reason` VARCHAR(2000) NOT NULL,
    `before_version_id` BIGINT NOT NULL,
    `proposed_values` JSON NOT NULL,
    `workflow_instance_id` BIGINT NULL,
    `status` ENUM('draft','pending','approved','rejected','cancelled') NOT NULL DEFAULT 'draft',
    `submitted_at` DATETIME(6) NULL,
    `approved_version_id` BIGINT NULL,
    `resolution_comment` VARCHAR(2000) NULL,
    `idempotency_key` VARCHAR(190) NOT NULL,
    `lock_version` INT UNSIGNED NOT NULL DEFAULT 1,
    `created_at` DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
    `updated_at` DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6) ON UPDATE CURRENT_TIMESTAMP(6),
    PRIMARY KEY (`id`),
    UNIQUE KEY `uk_staff_attendance_adjustment_idempotency` (`idempotency_key`),
    UNIQUE KEY `uk_staff_attendance_adjustment_approved_version` (`approved_version_id`),
    KEY `idx_staff_attendance_adjustment_staff_day` (`staff_user_id`, `work_date`, `status`),
    KEY `idx_staff_attendance_adjustment_workflow` (`workflow_instance_id`, `status`),
    CONSTRAINT `fk_staff_attendance_adjustment_before` FOREIGN KEY (`before_version_id`) REFERENCES `staff_attendance_day_versions` (`id`) ON DELETE RESTRICT,
    CONSTRAINT `fk_staff_attendance_adjustment_approved` FOREIGN KEY (`approved_version_id`) REFERENCES `staff_attendance_day_versions` (`id`) ON DELETE RESTRICT,
    CONSTRAINT `chk_staff_attendance_adjustment_reason` CHECK (CHAR_LENGTH(TRIM(`reason`)) > 0),
    CONSTRAINT `chk_staff_attendance_adjustment_version` CHECK (`lock_version` > 0),
    CONSTRAINT `chk_staff_attendance_adjustment_submission` CHECK (
        (`status` = 'draft' AND `submitted_at` IS NULL)
        OR (`status` IN ('pending','approved','rejected','cancelled') AND `submitted_at` IS NOT NULL)
    ),
    CONSTRAINT `chk_staff_attendance_adjustment_approval` CHECK (
        (`status` = 'approved' AND `approved_version_id` IS NOT NULL)
        OR (`status` <> 'approved' AND `approved_version_id` IS NULL)
    )
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL);

    $createTrigger('trg_staff_attendance_entry_method_no_delete', <<<'SQL'
CREATE TRIGGER `trg_staff_attendance_entry_method_no_delete`
BEFORE DELETE ON `staff_attendance_entry_methods`
FOR EACH ROW
BEGIN
    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Attendance entry methods must be retired, not deleted';
END
SQL);

    $createTrigger('trg_staff_attendance_entry_method_guard_update', <<<'SQL'
CREATE TRIGGER `trg_staff_attendance_entry_method_guard_update`
BEFORE UPDATE ON `staff_attendance_entry_methods`
FOR EACH ROW
BEGIN
    IF NOT (NEW.`id` <=> OLD.`id`)
       OR NOT (NEW.`created_by` <=> OLD.`created_by`)
       OR NOT (NEW.`created_at` <=> OLD.`created_at`) THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Attendance entry method identity and creation metadata are immutable';
    END IF;

    IF (
        NOT (NEW.`code` <=> OLD.`code`)
        OR NOT (NEW.`method_type` <=> OLD.`method_type`)
        OR NOT (NEW.`requires_reason` <=> OLD.`requires_reason`)
        OR NOT (NEW.`requires_attachment` <=> OLD.`requires_attachment`)
        OR NOT (NEW.`requires_review` <=> OLD.`requires_review`)
        OR NOT (NEW.`allowed_scope` <=> OLD.`allowed_scope`)
    ) AND (
        EXISTS (
            SELECT 1
            FROM `staff_biometric_events` event_old
            WHERE event_old.`entry_method_id` = OLD.`id`
        )
        OR EXISTS (
            SELECT 1
            FROM `staff_biometric_events` event_new
            WHERE event_new.`entry_method_id` = NEW.`id`
        )
    ) THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Used attendance entry method semantics are immutable; retire and create a new method';
    END IF;
END
SQL);

    $createTrigger('trg_staff_biometric_mapping_overlap_insert', <<<'SQL'
CREATE TRIGGER `trg_staff_biometric_mapping_overlap_insert`
BEFORE INSERT ON `staff_biometric_identity_mappings`
FOR EACH ROW
BEGIN
    IF EXISTS (
        SELECT 1
        FROM `staff_biometric_identity_mappings` existing
        WHERE existing.`device_id` = NEW.`device_id`
          AND existing.`biometric_identity` = NEW.`biometric_identity`
          AND (existing.`valid_to` IS NULL OR existing.`valid_to` > NEW.`valid_from`)
          AND (NEW.`valid_to` IS NULL OR existing.`valid_from` < NEW.`valid_to`)
    ) THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Biometric identity mapping period overlaps an existing mapping';
    END IF;
END
SQL);

    $createTrigger('trg_staff_biometric_mapping_guard_update', <<<'SQL'
CREATE TRIGGER `trg_staff_biometric_mapping_guard_update`
BEFORE UPDATE ON `staff_biometric_identity_mappings`
FOR EACH ROW
BEGIN
    IF NOT (NEW.`device_id` <=> OLD.`device_id`)
       OR NOT (NEW.`biometric_identity` <=> OLD.`biometric_identity`)
       OR NOT (NEW.`staff_user_id` <=> OLD.`staff_user_id`)
       OR NOT (NEW.`valid_from` <=> OLD.`valid_from`)
       OR NOT (NEW.`source` <=> OLD.`source`)
       OR NOT (NEW.`confirmed_by` <=> OLD.`confirmed_by`)
       OR NOT (NEW.`created_at` <=> OLD.`created_at`) THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Biometric identity history is immutable; retire and create a new mapping';
    END IF;

    IF OLD.`valid_to` IS NOT NULL
       AND (NOT (NEW.`valid_to` <=> OLD.`valid_to`) OR NOT (NEW.`retired_reason` <=> OLD.`retired_reason`)) THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'A retired biometric identity mapping cannot be reopened or rewritten';
    END IF;

    IF EXISTS (
        SELECT 1
        FROM `staff_biometric_identity_mappings` existing
        WHERE existing.`id` <> OLD.`id`
          AND existing.`device_id` = NEW.`device_id`
          AND existing.`biometric_identity` = NEW.`biometric_identity`
          AND (existing.`valid_to` IS NULL OR existing.`valid_to` > NEW.`valid_from`)
          AND (NEW.`valid_to` IS NULL OR existing.`valid_from` < NEW.`valid_to`)
    ) THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Biometric identity mapping period overlaps an existing mapping';
    END IF;
END
SQL);

    $createTrigger('trg_staff_biometric_mapping_no_delete', <<<'SQL'
CREATE TRIGGER `trg_staff_biometric_mapping_no_delete`
BEFORE DELETE ON `staff_biometric_identity_mappings`
FOR EACH ROW
BEGIN
    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Biometric identity mappings are historical evidence and cannot be deleted';
END
SQL);

    $createTrigger('trg_staff_biometric_event_method_insert', <<<'SQL'
CREATE TRIGGER `trg_staff_biometric_event_method_insert`
BEFORE INSERT ON `staff_biometric_events`
FOR EACH ROW
BEGIN
    DECLARE method_status VARCHAR(20) DEFAULT NULL;
    DECLARE method_type_value VARCHAR(30) DEFAULT NULL;
    DECLARE method_requires_reason TINYINT DEFAULT NULL;
    DECLARE method_requires_attachment TINYINT DEFAULT NULL;
    DECLARE method_requires_review TINYINT DEFAULT NULL;

    SELECT `status`, `method_type`, `requires_reason`, `requires_attachment`, `requires_review`
      INTO method_status, method_type_value, method_requires_reason, method_requires_attachment, method_requires_review
      FROM `staff_attendance_entry_methods`
     WHERE `id` = NEW.`entry_method_id`
     LIMIT 1;

    IF method_status IS NULL OR method_status <> 'active' THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Attendance entry method must exist and be active';
    END IF;
    IF method_requires_reason = 1 AND CHAR_LENGTH(TRIM(COALESCE(NEW.`reason_text`, ''))) = 0 THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Attendance entry method requires a reason';
    END IF;
    IF method_requires_attachment = 1 AND CHAR_LENGTH(TRIM(COALESCE(NEW.`attachment_ref`, ''))) = 0 THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Attendance entry method requires attachment evidence';
    END IF;
    IF method_requires_review = 1 AND NEW.`review_status` <> 'pending' THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Attendance entry method requires independent review';
    END IF;
    IF method_requires_review = 0 AND NEW.`review_status` NOT IN ('pending', 'not_required') THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'A new attendance event cannot arrive pre-approved';
    END IF;
    IF method_type_value = 'biometric'
       AND (NEW.`device_id` IS NULL OR CHAR_LENGTH(TRIM(COALESCE(NEW.`biometric_identity`, ''))) = 0) THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Biometric attendance requires device and biometric identity';
    END IF;
    IF NEW.`link_status` <> 'matched' AND NEW.`staff_user_id` IS NOT NULL THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Only a matched attendance event may carry a staff identity';
    END IF;
    IF NEW.`link_status` = 'unmatched' AND NEW.`identity_mapping_id` IS NOT NULL THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'An unmatched attendance event cannot carry an identity mapping';
    END IF;
    IF method_type_value <> 'biometric' AND NEW.`identity_mapping_id` IS NOT NULL THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Only biometric attendance may reference a biometric identity mapping';
    END IF;
    IF method_type_value = 'biometric' AND NEW.`link_status` = 'matched' THEN
        IF NEW.`identity_mapping_id` IS NULL OR NEW.`staff_user_id` IS NULL THEN
            SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Matched biometric attendance requires mapping and staff identity';
        END IF;
        IF NOT EXISTS (
            SELECT 1
            FROM `staff_biometric_identity_mappings` matched_mapping
            WHERE matched_mapping.`id` = NEW.`identity_mapping_id`
              AND matched_mapping.`device_id` = NEW.`device_id`
              AND matched_mapping.`biometric_identity` = NEW.`biometric_identity`
              AND matched_mapping.`staff_user_id` = NEW.`staff_user_id`
              AND matched_mapping.`valid_from` <= COALESCE(NEW.`event_at_local`, NEW.`device_event_at`)
              AND (
                  matched_mapping.`valid_to` IS NULL
                  OR COALESCE(NEW.`event_at_local`, NEW.`device_event_at`) < matched_mapping.`valid_to`
              )
        ) THEN
            SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Biometric mapping must match device, identity, staff, and event time';
        END IF;
    END IF;
END
SQL);

    $createTrigger('trg_staff_biometric_event_guard_update', <<<'SQL'
CREATE TRIGGER `trg_staff_biometric_event_guard_update`
BEFORE UPDATE ON `staff_biometric_events`
FOR EACH ROW
BEGIN
    IF NOT (NEW.`batch_id` <=> OLD.`batch_id`)
       OR NOT (NEW.`entry_method_id` <=> OLD.`entry_method_id`)
       OR NOT (NEW.`device_id` <=> OLD.`device_id`)
       OR NOT (NEW.`external_event_key` <=> OLD.`external_event_key`)
       OR NOT (NEW.`idempotency_key` <=> OLD.`idempotency_key`)
       OR NOT (NEW.`biometric_identity` <=> OLD.`biometric_identity`)
       OR NOT (NEW.`device_event_at` <=> OLD.`device_event_at`)
       OR NOT (NEW.`received_at` <=> OLD.`received_at`)
       OR NOT (NEW.`device_timezone` <=> OLD.`device_timezone`)
       OR NOT (NEW.`normalized_event_at_utc` <=> OLD.`normalized_event_at_utc`)
       OR NOT (NEW.`event_at_local` <=> OLD.`event_at_local`)
       OR NOT (NEW.`clock_offset_seconds` <=> OLD.`clock_offset_seconds`)
       OR NOT (NEW.`clock_status` <=> OLD.`clock_status`)
       OR NOT (NEW.`event_type` <=> OLD.`event_type`)
       OR NOT (NEW.`raw_hash` <=> OLD.`raw_hash`)
       OR NOT (NEW.`raw_payload_ref` <=> OLD.`raw_payload_ref`)
       OR NOT (NEW.`processing_order` <=> OLD.`processing_order`)
       OR NOT (NEW.`recorded_by` <=> OLD.`recorded_by`)
       OR NOT (NEW.`reason_text` <=> OLD.`reason_text`)
       OR NOT (NEW.`attachment_ref` <=> OLD.`attachment_ref`)
       OR NOT (NEW.`identity_mapping_id` <=> OLD.`identity_mapping_id`)
       OR NOT (NEW.`staff_user_id` <=> OLD.`staff_user_id`)
       OR NOT (NEW.`link_status` <=> OLD.`link_status`)
       OR NOT (NEW.`link_reason` <=> OLD.`link_reason`)
       OR NOT (NEW.`created_at` <=> OLD.`created_at`) THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Raw attendance evidence, attribution, and clock fields are immutable';
    END IF;

    IF OLD.`review_status` IN ('approved','rejected','not_required')
       AND (NOT (NEW.`review_status` <=> OLD.`review_status`)
            OR NOT (NEW.`reviewed_by` <=> OLD.`reviewed_by`)
            OR NOT (NEW.`reviewed_at` <=> OLD.`reviewed_at`)) THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Final attendance event review cannot be rewritten';
    END IF;
    IF OLD.`review_status` = 'pending' AND NEW.`review_status` = 'not_required' THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'A pending attendance review cannot be bypassed';
    END IF;
END
SQL);

    $createTrigger('trg_staff_biometric_event_no_delete', <<<'SQL'
CREATE TRIGGER `trg_staff_biometric_event_no_delete`
BEFORE DELETE ON `staff_biometric_events`
FOR EACH ROW
BEGIN
    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Raw attendance evidence cannot be deleted';
END
SQL);

    $createTrigger('trg_staff_attendance_run_guard_update', <<<'SQL'
CREATE TRIGGER `trg_staff_attendance_run_guard_update`
BEFORE UPDATE ON `staff_attendance_runs`
FOR EACH ROW
BEGIN
    IF NOT (NEW.`engine_version` <=> OLD.`engine_version`)
       OR NOT (NEW.`mode` <=> OLD.`mode`)
       OR NOT (NEW.`range_from` <=> OLD.`range_from`)
       OR NOT (NEW.`range_to` <=> OLD.`range_to`)
       OR NOT (NEW.`cutoff_at` <=> OLD.`cutoff_at`)
       OR NOT (NEW.`initiated_by` <=> OLD.`initiated_by`)
       OR NOT (NEW.`source_fingerprint` <=> OLD.`source_fingerprint`)
       OR NOT (NEW.`idempotency_key` <=> OLD.`idempotency_key`)
       OR NOT (NEW.`supersedes_run_id` <=> OLD.`supersedes_run_id`)
       OR NOT (NEW.`created_at` <=> OLD.`created_at`) THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Attendance run inputs are immutable';
    END IF;

    IF OLD.`status` IN ('completed','failed','blocked') THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Final attendance runs are immutable';
    END IF;
    IF (OLD.`status` = 'queued' AND NEW.`status` NOT IN ('queued','running'))
       OR (OLD.`status` = 'running' AND NEW.`status` NOT IN ('running','completed','failed','blocked')) THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Invalid attendance run state transition';
    END IF;
END
SQL);

    $createTrigger('trg_staff_attendance_run_no_delete', <<<'SQL'
CREATE TRIGGER `trg_staff_attendance_run_no_delete`
BEFORE DELETE ON `staff_attendance_runs`
FOR EACH ROW
BEGIN
    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Attendance calculation runs cannot be deleted';
END
SQL);

    $createTrigger('trg_staff_attendance_day_guard_insert', <<<'SQL'
CREATE TRIGGER `trg_staff_attendance_day_guard_insert`
BEFORE INSERT ON `staff_attendance_day_versions`
FOR EACH ROW
BEGIN
    IF NEW.`is_official` <> 0 THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Attendance day must be completed with children before official publication';
    END IF;
    IF NEW.`officialized_by` IS NOT NULL OR NEW.`officialized_at` IS NOT NULL THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'A new attendance version cannot contain publication metadata';
    END IF;
    IF NOT EXISTS (
        SELECT 1
        FROM `staff_attendance_runs` calculation_run
        WHERE calculation_run.`id` = NEW.`run_id`
          AND calculation_run.`mode` = NEW.`calculation_mode`
          AND calculation_run.`engine_version` = NEW.`engine_version`
          AND NEW.`work_date` BETWEEN calculation_run.`range_from` AND calculation_run.`range_to`
    ) THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Attendance day mode and engine must match its calculation run';
    END IF;
END
SQL);

    $createTrigger('trg_staff_attendance_day_guard_update', <<<'SQL'
CREATE TRIGGER `trg_staff_attendance_day_guard_update`
BEFORE UPDATE ON `staff_attendance_day_versions`
FOR EACH ROW
BEGIN
    IF NOT (NEW.`staff_user_id` <=> OLD.`staff_user_id`)
       OR NOT (NEW.`work_date` <=> OLD.`work_date`)
       OR NOT (NEW.`version_no` <=> OLD.`version_no`)
       OR NOT (NEW.`run_id` <=> OLD.`run_id`)
       OR NOT (NEW.`assignment_id` <=> OLD.`assignment_id`)
       OR NOT (NEW.`schedule_policy_version_id` <=> OLD.`schedule_policy_version_id`)
       OR NOT (NEW.`calendar_exception_id` <=> OLD.`calendar_exception_id`)
       OR NOT (NEW.`expected_start` <=> OLD.`expected_start`)
       OR NOT (NEW.`expected_end` <=> OLD.`expected_end`)
       OR NOT (NEW.`required_minutes` <=> OLD.`required_minutes`)
       OR NOT (NEW.`first_in` <=> OLD.`first_in`)
       OR NOT (NEW.`last_out` <=> OLD.`last_out`)
       OR NOT (NEW.`worked_minutes` <=> OLD.`worked_minutes`)
       OR NOT (NEW.`covered_late_minutes` <=> OLD.`covered_late_minutes`)
       OR NOT (NEW.`covered_early_minutes` <=> OLD.`covered_early_minutes`)
       OR NOT (NEW.`mission_minutes` <=> OLD.`mission_minutes`)
       OR NOT (NEW.`leave_minutes` <=> OLD.`leave_minutes`)
       OR NOT (NEW.`late_minutes` <=> OLD.`late_minutes`)
       OR NOT (NEW.`early_leave_minutes` <=> OLD.`early_leave_minutes`)
       OR NOT (NEW.`missing_minutes` <=> OLD.`missing_minutes`)
       OR NOT (NEW.`status` <=> OLD.`status`)
       OR NOT (NEW.`calculation_mode` <=> OLD.`calculation_mode`)
       OR NOT (NEW.`engine_version` <=> OLD.`engine_version`)
       OR NOT (NEW.`source_fingerprint` <=> OLD.`source_fingerprint`)
       OR NOT (NEW.`supersedes_id` <=> OLD.`supersedes_id`)
       OR NOT (NEW.`calculated_at` <=> OLD.`calculated_at`)
       OR NOT (NEW.`created_at` <=> OLD.`created_at`) THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Attendance day calculation contents are immutable';
    END IF;

    IF OLD.`is_official` = NEW.`is_official` THEN
        IF NOT (NEW.`officialized_by` <=> OLD.`officialized_by`)
           OR NOT (NEW.`officialized_at` <=> OLD.`officialized_at`) THEN
            SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Official attendance metadata changes only during publication';
        END IF;
    ELSEIF OLD.`is_official` = 0 AND NEW.`is_official` = 1 THEN
        IF OLD.`officialized_at` IS NOT NULL THEN
            SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'A former official attendance version cannot be republished; create a successor';
        END IF;
        IF NEW.`officialized_by` IS NULL OR NEW.`officialized_at` IS NULL THEN
            SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Publishing attendance requires actor and timestamp';
        END IF;
        IF NOT EXISTS (
            SELECT 1
            FROM `staff_attendance_runs` official_run
            WHERE official_run.`id` = NEW.`run_id`
              AND official_run.`status` = 'completed'
              AND official_run.`mode` = NEW.`calculation_mode`
              AND official_run.`mode` IN ('official','recalculation')
              AND official_run.`engine_version` = NEW.`engine_version`
              AND NEW.`work_date` BETWEEN official_run.`range_from` AND official_run.`range_to`
              AND NEW.`officialized_at` >= official_run.`finished_at`
        ) THEN
            SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Official attendance requires a completed matching official run';
        END IF;

        IF EXISTS (
            SELECT 1
            FROM `staff_attendance_day_versions` historical_day
            WHERE historical_day.`id` <> NEW.`id`
              AND historical_day.`staff_user_id` = NEW.`staff_user_id`
              AND historical_day.`work_date` = NEW.`work_date`
              AND historical_day.`officialized_at` IS NOT NULL
        ) THEN
            IF NEW.`supersedes_id` IS NULL OR NOT EXISTS (
                SELECT 1
                FROM `staff_attendance_day_versions` predecessor_day
                WHERE predecessor_day.`id` = NEW.`supersedes_id`
                  AND predecessor_day.`staff_user_id` = NEW.`staff_user_id`
                  AND predecessor_day.`work_date` = NEW.`work_date`
                  AND predecessor_day.`officialized_at` IS NOT NULL
                  AND NOT EXISTS (
                      SELECT 1
                      FROM `staff_attendance_day_versions` published_successor
                      WHERE published_successor.`id` <> NEW.`id`
                        AND published_successor.`supersedes_id` = predecessor_day.`id`
                        AND published_successor.`officialized_at` IS NOT NULL
                  )
            ) THEN
                SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Official attendance successor must extend the current published history chain';
            END IF;

            IF NOT EXISTS (
                SELECT 1
                FROM `staff_attendance_day_versions` predecessor_day
                INNER JOIN `staff_attendance_runs` successor_run
                    ON successor_run.`id` = NEW.`run_id`
                WHERE predecessor_day.`id` = NEW.`supersedes_id`
                  AND successor_run.`supersedes_run_id` = predecessor_day.`run_id`
            ) THEN
                SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Official attendance successor requires a run that supersedes the predecessor run';
            END IF;
        ELSEIF NEW.`supersedes_id` IS NOT NULL THEN
            SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Initial official attendance cannot supersede an unpublished version';
        END IF;
    ELSEIF OLD.`is_official` = 1 AND NEW.`is_official` = 0 THEN
        IF NOT (NEW.`officialized_by` <=> OLD.`officialized_by`)
           OR NOT (NEW.`officialized_at` <=> OLD.`officialized_at`) THEN
            SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Superseding attendance preserves original publication metadata';
        END IF;
    ELSE
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Invalid official attendance transition';
    END IF;
END
SQL);

    $createTrigger('trg_staff_attendance_day_no_delete', <<<'SQL'
CREATE TRIGGER `trg_staff_attendance_day_no_delete`
BEFORE DELETE ON `staff_attendance_day_versions`
FOR EACH ROW
BEGIN
    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Attendance day versions are append-only and cannot be deleted';
END
SQL);

    $createTrigger('trg_staff_attendance_segment_guard_insert', <<<'SQL'
CREATE TRIGGER `trg_staff_attendance_segment_guard_insert`
BEFORE INSERT ON `staff_attendance_segments`
FOR EACH ROW
BEGIN
    IF EXISTS (
        SELECT 1 FROM `staff_attendance_day_versions` day_version
        WHERE day_version.`id` = NEW.`day_version_id`
          AND (day_version.`is_official` = 1 OR day_version.`officialized_at` IS NOT NULL)
    ) THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Official attendance segments are immutable';
    END IF;
END
SQL);

    $createTrigger('trg_staff_attendance_segment_no_update', <<<'SQL'
CREATE TRIGGER `trg_staff_attendance_segment_no_update`
BEFORE UPDATE ON `staff_attendance_segments`
FOR EACH ROW
BEGIN
    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Attendance segments are append-only';
END
SQL);

    $createTrigger('trg_staff_attendance_segment_no_delete', <<<'SQL'
CREATE TRIGGER `trg_staff_attendance_segment_no_delete`
BEFORE DELETE ON `staff_attendance_segments`
FOR EACH ROW
BEGIN
    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Attendance segments are append-only';
END
SQL);

    $createTrigger('trg_staff_attendance_reason_guard_insert', <<<'SQL'
CREATE TRIGGER `trg_staff_attendance_reason_guard_insert`
BEFORE INSERT ON `staff_attendance_reason_lines`
FOR EACH ROW
BEGIN
    IF EXISTS (
        SELECT 1 FROM `staff_attendance_day_versions` day_version
        WHERE day_version.`id` = NEW.`day_version_id`
          AND (day_version.`is_official` = 1 OR day_version.`officialized_at` IS NOT NULL)
    ) THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Official attendance reason lines are immutable';
    END IF;
END
SQL);

    $createTrigger('trg_staff_attendance_reason_no_update', <<<'SQL'
CREATE TRIGGER `trg_staff_attendance_reason_no_update`
BEFORE UPDATE ON `staff_attendance_reason_lines`
FOR EACH ROW
BEGIN
    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Attendance reason lines are append-only';
END
SQL);

    $createTrigger('trg_staff_attendance_reason_no_delete', <<<'SQL'
CREATE TRIGGER `trg_staff_attendance_reason_no_delete`
BEFORE DELETE ON `staff_attendance_reason_lines`
FOR EACH ROW
BEGIN
    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Attendance reason lines are append-only';
END
SQL);

    $createTrigger('trg_staff_attendance_adjustment_guard_insert', <<<'SQL'
CREATE TRIGGER `trg_staff_attendance_adjustment_guard_insert`
BEFORE INSERT ON `staff_attendance_adjustments`
FOR EACH ROW
BEGIN
    IF NEW.`status` <> 'draft'
       OR NEW.`submitted_at` IS NOT NULL
       OR NEW.`approved_version_id` IS NOT NULL THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Attendance adjustment must start as an unsubmitted draft';
    END IF;
    IF NOT EXISTS (
        SELECT 1
        FROM `staff_attendance_day_versions` before_day
        WHERE before_day.`id` = NEW.`before_version_id`
          AND before_day.`staff_user_id` = NEW.`staff_user_id`
          AND before_day.`work_date` = NEW.`work_date`
          AND before_day.`is_official` = 1
          AND before_day.`officialized_at` IS NOT NULL
    ) THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Attendance adjustment source version must match staff and work date';
    END IF;
END
SQL);

    $createTrigger('trg_staff_attendance_adjustment_guard_update', <<<'SQL'
CREATE TRIGGER `trg_staff_attendance_adjustment_guard_update`
BEFORE UPDATE ON `staff_attendance_adjustments`
FOR EACH ROW
BEGIN
    IF OLD.`status` IN ('approved','rejected','cancelled') THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Final attendance adjustment decision is immutable';
    END IF;

    IF OLD.`status` <> 'draft' AND (
        NOT (NEW.`staff_user_id` <=> OLD.`staff_user_id`)
        OR NOT (NEW.`work_date` <=> OLD.`work_date`)
        OR NOT (NEW.`requester_id` <=> OLD.`requester_id`)
        OR NOT (NEW.`requester_kind` <=> OLD.`requester_kind`)
        OR NOT (NEW.`reason` <=> OLD.`reason`)
        OR NOT (NEW.`before_version_id` <=> OLD.`before_version_id`)
        OR NOT (NEW.`proposed_values` <=> OLD.`proposed_values`)
        OR NOT (NEW.`workflow_instance_id` <=> OLD.`workflow_instance_id`)
        OR NOT (NEW.`submitted_at` <=> OLD.`submitted_at`)
        OR NOT (NEW.`idempotency_key` <=> OLD.`idempotency_key`)
        OR NOT (NEW.`created_at` <=> OLD.`created_at`)
    ) THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Submitted attendance adjustment facts are immutable';
    END IF;

    IF (OLD.`status` = 'draft' AND NEW.`status` NOT IN ('draft','pending','cancelled'))
       OR (OLD.`status` = 'pending' AND NEW.`status` NOT IN ('pending','approved','rejected','cancelled')) THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Invalid attendance adjustment state transition';
    END IF;

    IF NOT EXISTS (
        SELECT 1
        FROM `staff_attendance_day_versions` before_day
        WHERE before_day.`id` = NEW.`before_version_id`
          AND before_day.`staff_user_id` = NEW.`staff_user_id`
          AND before_day.`work_date` = NEW.`work_date`
          AND before_day.`officialized_at` IS NOT NULL
    ) THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Attendance adjustment source version must match staff and work date';
    END IF;

    IF NEW.`status` = 'approved' AND NOT EXISTS (
        SELECT 1
        FROM `staff_attendance_day_versions` approved_day
        WHERE approved_day.`id` = NEW.`approved_version_id`
          AND approved_day.`staff_user_id` = NEW.`staff_user_id`
          AND approved_day.`work_date` = NEW.`work_date`
          AND approved_day.`supersedes_id` = NEW.`before_version_id`
          AND approved_day.`is_official` = 1
    ) THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Approved adjustment version must officially supersede its source day';
    END IF;
END
SQL);

    $createTrigger('trg_staff_attendance_adjustment_no_delete', <<<'SQL'
CREATE TRIGGER `trg_staff_attendance_adjustment_no_delete`
BEFORE DELETE ON `staff_attendance_adjustments`
FOR EACH ROW
BEGIN
    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Attendance adjustments are workflow evidence and cannot be deleted';
END
SQL);
};
