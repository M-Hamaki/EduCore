<?php

declare(strict_types=1);

/**
 * Effective-dated staff schedules, calendar exceptions, and temporary changes.
 *
 * This migration is additive. Published schedule rows and their children are
 * protected by triggers because a historical attendance result must continue
 * to point at the exact schedule definition that produced it.
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

    $createTable('staff_schedule_policies', <<<'SQL'
CREATE TABLE `staff_schedule_policies` (
    `id` INT NOT NULL AUTO_INCREMENT,
    `code` VARCHAR(80) NOT NULL,
    `name` VARCHAR(200) NOT NULL,
    `description` VARCHAR(1000) NULL,
    `status` ENUM('active','inactive','retired') NOT NULL DEFAULT 'active',
    `created_by` INT NOT NULL,
    `created_at` DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
    `updated_at` DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6) ON UPDATE CURRENT_TIMESTAMP(6),
    PRIMARY KEY (`id`),
    UNIQUE KEY `uk_staff_schedule_policy_code` (`code`),
    KEY `idx_staff_schedule_policy_status` (`status`, `name`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL);

    $createTable('staff_schedule_policy_versions', <<<'SQL'
CREATE TABLE `staff_schedule_policy_versions` (
    `id` BIGINT NOT NULL AUTO_INCREMENT,
    `policy_id` INT NOT NULL,
    `version_no` INT UNSIGNED NOT NULL,
    `state` ENUM('draft','published','retired') NOT NULL DEFAULT 'draft',
    `valid_from` DATETIME(6) NOT NULL,
    `valid_to` DATETIME(6) NULL,
    `timezone` VARCHAR(64) NOT NULL DEFAULT 'Africa/Cairo',
    `rounding_rule` VARCHAR(80) NULL,
    `season_start_mmdd` CHAR(5) NULL,
    `season_end_mmdd` CHAR(5) NULL,
    `supersedes_id` BIGINT NULL,
    `lock_version` INT UNSIGNED NOT NULL DEFAULT 1,
    `create_idempotency_key` VARCHAR(190) NOT NULL,
    `create_payload_hash` CHAR(64) NOT NULL,
    `last_command_key` VARCHAR(190) NULL,
    `last_command_payload_hash` CHAR(64) NULL,
    `publication_key` VARCHAR(190) NULL,
    `publication_payload_hash` CHAR(64) NULL,
    `published_by` INT NULL,
    `published_at` DATETIME(6) NULL,
    `created_by` INT NOT NULL,
    `created_at` DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
    PRIMARY KEY (`id`),
    UNIQUE KEY `uk_staff_schedule_version_no` (`policy_id`, `version_no`),
    UNIQUE KEY `uk_staff_schedule_version_create_key` (`create_idempotency_key`),
    UNIQUE KEY `uk_staff_schedule_version_publication_key` (`publication_key`),
    UNIQUE KEY `uk_staff_schedule_version_supersedes` (`supersedes_id`),
    KEY `idx_staff_schedule_version_effective` (`policy_id`, `state`, `valid_from`, `valid_to`),
    CONSTRAINT `fk_staff_schedule_version_policy` FOREIGN KEY (`policy_id`) REFERENCES `staff_schedule_policies` (`id`) ON DELETE RESTRICT,
    CONSTRAINT `fk_staff_schedule_version_supersedes` FOREIGN KEY (`supersedes_id`) REFERENCES `staff_schedule_policy_versions` (`id`) ON DELETE RESTRICT,
    CONSTRAINT `chk_staff_schedule_version_dates` CHECK (`valid_to` IS NULL OR `valid_to` > `valid_from`),
    CONSTRAINT `chk_staff_schedule_version_season` CHECK ((`season_start_mmdd` IS NULL AND `season_end_mmdd` IS NULL) OR (`season_start_mmdd` IS NOT NULL AND `season_end_mmdd` IS NOT NULL)),
    CONSTRAINT `chk_staff_schedule_version_publication` CHECK (`state` <> 'published' OR (`published_by` IS NOT NULL AND `published_at` IS NOT NULL AND `publication_key` IS NOT NULL)),
    CONSTRAINT `chk_staff_schedule_version_create_hash` CHECK (CHAR_LENGTH(`create_payload_hash`) = 64),
    CONSTRAINT `chk_staff_schedule_version_last_hash` CHECK (`last_command_payload_hash` IS NULL OR CHAR_LENGTH(`last_command_payload_hash`) = 64),
    CONSTRAINT `chk_staff_schedule_version_publication_hash` CHECK (`publication_payload_hash` IS NULL OR CHAR_LENGTH(`publication_payload_hash`) = 64)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL);

    $createTable('staff_schedule_days', <<<'SQL'
CREATE TABLE `staff_schedule_days` (
    `id` BIGINT NOT NULL AUTO_INCREMENT,
    `policy_version_id` BIGINT NOT NULL,
    `weekday` TINYINT UNSIGNED NOT NULL,
    `is_working_day` TINYINT(1) NOT NULL DEFAULT 1,
    `start_time` TIME NULL,
    `end_time` TIME NULL,
    `end_day_offset` TINYINT UNSIGNED NOT NULL DEFAULT 0,
    `required_minutes` SMALLINT UNSIGNED NOT NULL DEFAULT 0,
    `late_grace_minutes` SMALLINT UNSIGNED NOT NULL DEFAULT 0,
    `early_grace_minutes` SMALLINT UNSIGNED NOT NULL DEFAULT 0,
    `entry_window_before_minutes` SMALLINT UNSIGNED NOT NULL DEFAULT 0,
    `entry_window_after_minutes` SMALLINT UNSIGNED NOT NULL DEFAULT 0,
    `exit_window_before_minutes` SMALLINT UNSIGNED NOT NULL DEFAULT 0,
    `exit_window_after_minutes` SMALLINT UNSIGNED NOT NULL DEFAULT 0,
    `created_at` DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
    PRIMARY KEY (`id`),
    UNIQUE KEY `uk_staff_schedule_day` (`policy_version_id`, `weekday`),
    KEY `idx_staff_schedule_day_version` (`policy_version_id`, `weekday`, `is_working_day`),
    CONSTRAINT `fk_staff_schedule_day_version` FOREIGN KEY (`policy_version_id`) REFERENCES `staff_schedule_policy_versions` (`id`) ON DELETE RESTRICT,
    CONSTRAINT `chk_staff_schedule_day_weekday` CHECK (`weekday` BETWEEN 1 AND 7),
    CONSTRAINT `chk_staff_schedule_day_offset` CHECK (`end_day_offset` BETWEEN 0 AND 2),
    CONSTRAINT `chk_staff_schedule_day_times` CHECK (`is_working_day` = 0 OR (`start_time` IS NOT NULL AND `end_time` IS NOT NULL)),
    CONSTRAINT `chk_staff_schedule_day_same_day` CHECK (`is_working_day` = 0 OR `end_day_offset` > 0 OR `end_time` > `start_time`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL);

    $createTable('staff_schedule_segments', <<<'SQL'
CREATE TABLE `staff_schedule_segments` (
    `id` BIGINT NOT NULL AUTO_INCREMENT,
    `schedule_day_id` BIGINT NOT NULL,
    `sequence_no` SMALLINT UNSIGNED NOT NULL,
    `segment_type` ENUM('work','paid_break','unpaid_break','on_call','overtime') NOT NULL,
    `start_time` TIME NOT NULL,
    `end_time` TIME NOT NULL,
    `start_day_offset` TINYINT UNSIGNED NOT NULL DEFAULT 0,
    `end_day_offset` TINYINT UNSIGNED NOT NULL DEFAULT 0,
    `counts_required_minutes` TINYINT(1) NOT NULL DEFAULT 0,
    `created_at` DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
    PRIMARY KEY (`id`),
    UNIQUE KEY `uk_staff_schedule_segment_sequence` (`schedule_day_id`, `sequence_no`),
    KEY `idx_staff_schedule_segment_window` (`schedule_day_id`, `start_day_offset`, `start_time`, `end_day_offset`, `end_time`),
    CONSTRAINT `fk_staff_schedule_segment_day` FOREIGN KEY (`schedule_day_id`) REFERENCES `staff_schedule_days` (`id`) ON DELETE RESTRICT,
    CONSTRAINT `chk_staff_schedule_segment_sequence` CHECK (`sequence_no` > 0),
    CONSTRAINT `chk_staff_schedule_segment_offsets` CHECK (`start_day_offset` BETWEEN 0 AND 2 AND `end_day_offset` BETWEEN 0 AND 2 AND `end_day_offset` >= `start_day_offset`),
    CONSTRAINT `chk_staff_schedule_segment_window` CHECK (`end_day_offset` > `start_day_offset` OR `end_time` > `start_time`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL);

    $createTable('staff_schedule_scopes', <<<'SQL'
CREATE TABLE `staff_schedule_scopes` (
    `id` BIGINT NOT NULL AUTO_INCREMENT,
    `policy_version_id` BIGINT NOT NULL,
    `scope_type` ENUM('global','org_unit','job_title','group','staff') NOT NULL,
    `scope_id` INT NOT NULL DEFAULT 0,
    `priority` SMALLINT UNSIGNED NOT NULL DEFAULT 0,
    `valid_from` DATETIME(6) NOT NULL,
    `valid_to` DATETIME(6) NULL,
    `status` ENUM('active','suspended','retired') NOT NULL DEFAULT 'active',
    `created_by` INT NOT NULL,
    `created_at` DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
    PRIMARY KEY (`id`),
    UNIQUE KEY `uk_staff_schedule_scope_start` (`policy_version_id`, `scope_type`, `scope_id`, `priority`, `valid_from`),
    KEY `idx_staff_schedule_scope_resolution` (`scope_type`, `scope_id`, `valid_from`, `valid_to`, `status`, `priority`),
    CONSTRAINT `fk_staff_schedule_scope_version` FOREIGN KEY (`policy_version_id`) REFERENCES `staff_schedule_policy_versions` (`id`) ON DELETE RESTRICT,
    CONSTRAINT `chk_staff_schedule_scope_dates` CHECK (`valid_to` IS NULL OR `valid_to` > `valid_from`),
    CONSTRAINT `chk_staff_schedule_scope_identity` CHECK ((`scope_type` = 'global' AND `scope_id` = 0) OR (`scope_type` <> 'global' AND `scope_id` > 0))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL);

    $createTable('staff_calendar_exceptions', <<<'SQL'
CREATE TABLE `staff_calendar_exceptions` (
    `id` BIGINT NOT NULL AUTO_INCREMENT,
    `calendar_date` DATE NOT NULL,
    `scope_type` ENUM('global','org_unit','job_title','group','staff') NOT NULL,
    `scope_id` INT NOT NULL DEFAULT 0,
    `priority` SMALLINT UNSIGNED NOT NULL DEFAULT 0,
    `exception_type` ENUM('holiday','closure','partial_day','makeup_day','override') NOT NULL,
    `schedule_policy_version_id` BIGINT NULL,
    `override_json` JSON NULL,
    `reason` VARCHAR(1000) NOT NULL,
    `status` ENUM('draft','active','retired') NOT NULL DEFAULT 'draft',
    `supersedes_id` BIGINT NULL,
    `lock_version` INT UNSIGNED NOT NULL DEFAULT 1,
    `idempotency_key` VARCHAR(190) NOT NULL,
    `payload_hash` CHAR(64) NOT NULL,
    `created_by` INT NOT NULL,
    `created_at` DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
    `updated_at` DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6) ON UPDATE CURRENT_TIMESTAMP(6),
    PRIMARY KEY (`id`),
    UNIQUE KEY `uk_staff_calendar_exception_idempotency` (`idempotency_key`),
    UNIQUE KEY `uk_staff_calendar_exception_supersedes` (`supersedes_id`),
    KEY `idx_staff_calendar_exception_resolution` (`calendar_date`, `scope_type`, `scope_id`, `status`, `priority`),
    CONSTRAINT `fk_staff_calendar_exception_schedule` FOREIGN KEY (`schedule_policy_version_id`) REFERENCES `staff_schedule_policy_versions` (`id`) ON DELETE RESTRICT,
    CONSTRAINT `fk_staff_calendar_exception_supersedes` FOREIGN KEY (`supersedes_id`) REFERENCES `staff_calendar_exceptions` (`id`) ON DELETE RESTRICT,
    CONSTRAINT `chk_staff_calendar_exception_scope` CHECK ((`scope_type` = 'global' AND `scope_id` = 0) OR (`scope_type` <> 'global' AND `scope_id` > 0)),
    CONSTRAINT `chk_staff_calendar_exception_override` CHECK (`exception_type` IN ('holiday','closure') OR `schedule_policy_version_id` IS NOT NULL OR `override_json` IS NOT NULL),
    CONSTRAINT `chk_staff_calendar_exception_hash` CHECK (CHAR_LENGTH(`payload_hash`) = 64)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL);

    // workflow_instance_id is an indexed scalar contract reference. The
    // workflow-foundation migration sorts later on a clean installation, so a
    // physical FK here would make the documented migration runner fail before
    // that owner exists. Application services validate the reference through
    // the Staff approval-workflow contract before persistence.
    $createTable('staff_schedule_change_requests', <<<'SQL'
CREATE TABLE `staff_schedule_change_requests` (
    `id` BIGINT NOT NULL AUTO_INCREMENT,
    `staff_user_id` INT NOT NULL,
    `change_type` ENUM('temporary_shift','shift_swap','overtime','alternative_attendance') NOT NULL,
    `from_at` DATETIME(6) NOT NULL,
    `to_at` DATETIME(6) NOT NULL,
    `counterpart_staff_id` INT NULL,
    `counterpart_accepted_by` INT NULL,
    `counterpart_accepted_at` DATETIME(6) NULL,
    `requested_schedule_version_id` BIGINT NULL,
    `reason` VARCHAR(1000) NOT NULL,
    `workflow_instance_id` BIGINT NULL,
    `status` ENUM('draft','pending_counterpart','submitted','approved','rejected','cancelled','withdrawn') NOT NULL DEFAULT 'draft',
    `approved_schedule_snapshot` JSON NULL,
    `approved_by` INT NULL,
    `approved_at` DATETIME(6) NULL,
    `lock_version` INT UNSIGNED NOT NULL DEFAULT 1,
    `idempotency_key` VARCHAR(190) NOT NULL,
    `payload_hash` CHAR(64) NOT NULL,
    `last_command_key` VARCHAR(190) NULL,
    `last_command_payload_hash` CHAR(64) NULL,
    `created_by` INT NOT NULL,
    `created_at` DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
    `updated_at` DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6) ON UPDATE CURRENT_TIMESTAMP(6),
    PRIMARY KEY (`id`),
    UNIQUE KEY `uk_staff_schedule_change_idempotency` (`idempotency_key`),
    KEY `idx_staff_schedule_change_overlap` (`staff_user_id`, `from_at`, `to_at`, `status`),
    KEY `idx_staff_schedule_change_counterpart_overlap` (`counterpart_staff_id`, `from_at`, `to_at`, `status`),
    KEY `idx_staff_schedule_change_workflow` (`workflow_instance_id`, `status`),
    CONSTRAINT `fk_staff_schedule_change_version` FOREIGN KEY (`requested_schedule_version_id`) REFERENCES `staff_schedule_policy_versions` (`id`) ON DELETE RESTRICT,
    CONSTRAINT `chk_staff_schedule_change_window` CHECK (`to_at` > `from_at`),
    CONSTRAINT `chk_staff_schedule_change_swap` CHECK ((`change_type` = 'shift_swap' AND `counterpart_staff_id` IS NOT NULL AND `counterpart_staff_id` <> `staff_user_id`) OR (`change_type` <> 'shift_swap' AND `counterpart_staff_id` IS NULL)),
    CONSTRAINT `chk_staff_schedule_change_schedule` CHECK (`change_type` IN ('overtime','alternative_attendance') OR `requested_schedule_version_id` IS NOT NULL),
    CONSTRAINT `chk_staff_schedule_change_approval` CHECK (`status` <> 'approved' OR (`approved_by` IS NOT NULL AND `approved_at` IS NOT NULL AND `approved_schedule_snapshot` IS NOT NULL)),
    CONSTRAINT `chk_staff_schedule_change_counterpart_acceptance_pair` CHECK ((`counterpart_accepted_by` IS NULL AND `counterpart_accepted_at` IS NULL) OR (`counterpart_accepted_by` IS NOT NULL AND `counterpart_accepted_at` IS NOT NULL)),
    CONSTRAINT `chk_staff_schedule_change_pending_swap` CHECK (`status` <> 'pending_counterpart' OR `change_type` = 'shift_swap'),
    CONSTRAINT `chk_staff_schedule_change_acceptance_swap_only` CHECK (`change_type` = 'shift_swap' OR (`counterpart_accepted_by` IS NULL AND `counterpart_accepted_at` IS NULL)),
    CONSTRAINT `chk_staff_schedule_change_hash` CHECK (CHAR_LENGTH(`payload_hash`) = 64),
    CONSTRAINT `chk_staff_schedule_change_last_hash` CHECK (`last_command_payload_hash` IS NULL OR CHAR_LENGTH(`last_command_payload_hash`) = 64)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL);

    $createTable('staff_schedule_command_receipts', <<<'SQL'
CREATE TABLE `staff_schedule_command_receipts` (
    `id` BIGINT NOT NULL AUTO_INCREMENT,
    `command_type` VARCHAR(80) NOT NULL,
    `resource_type` VARCHAR(80) NOT NULL,
    `resource_id` BIGINT NOT NULL,
    `idempotency_key` VARCHAR(190) NOT NULL,
    `payload_hash` CHAR(64) NOT NULL,
    `result_json` JSON NOT NULL,
    `actor_user_id` INT NOT NULL,
    `created_at` DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
    PRIMARY KEY (`id`),
    UNIQUE KEY `uk_staff_schedule_command_idempotency` (`idempotency_key`),
    KEY `idx_staff_schedule_command_resource` (`resource_type`, `resource_id`, `created_at`),
    CONSTRAINT `chk_staff_schedule_command_hash` CHECK (CHAR_LENGTH(`payload_hash`) = 64)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL);

    $createTable('staff_schedule_participant_locks', <<<'SQL'
CREATE TABLE `staff_schedule_participant_locks` (
    `staff_user_id` INT NOT NULL,
    `created_at` DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
    `updated_at` DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6) ON UPDATE CURRENT_TIMESTAMP(6),
    PRIMARY KEY (`staff_user_id`),
    KEY `idx_staff_schedule_participant_lock_updated` (`updated_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL);

    $createTrigger('trg_staff_schedule_versions_immutable_update', <<<'SQL'
CREATE TRIGGER `trg_staff_schedule_versions_immutable_update`
BEFORE UPDATE ON `staff_schedule_policy_versions`
FOR EACH ROW
BEGIN
    IF OLD.`state` = 'published' THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Published schedule versions are immutable';
    END IF;
END
SQL);
    $createTrigger('trg_staff_schedule_versions_immutable_delete', <<<'SQL'
CREATE TRIGGER `trg_staff_schedule_versions_immutable_delete`
BEFORE DELETE ON `staff_schedule_policy_versions`
FOR EACH ROW
BEGIN
    IF OLD.`state` = 'published' THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Published schedule versions cannot be deleted';
    END IF;
END
SQL);

    $immutableChildTrigger = static function (
        string $trigger,
        string $timing,
        string $table,
        string $versionExpression
    ) use ($createTrigger): void {
        if ($timing === 'UPDATE') {
            $versionSql = '(' . str_replace('{ROW}', 'OLD', $versionExpression) . ') + ('
                . str_replace('{ROW}', 'NEW', $versionExpression) . ')';
        } else {
            $reference = $timing === 'DELETE' ? 'OLD' : 'NEW';
            $versionSql = str_replace('{ROW}', $reference, $versionExpression);
        }
        $createTrigger($trigger, "CREATE TRIGGER `{$trigger}`\n"
            . "BEFORE {$timing} ON `{$table}`\n"
            . "FOR EACH ROW\nBEGIN\n"
            . "    IF ({$versionSql}) > 0 THEN\n"
            . "        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Published schedule children are immutable';\n"
            . "    END IF;\nEND");
    };

    $versionChildCheck = "SELECT COUNT(*) FROM `staff_schedule_policy_versions` WHERE `id` = {ROW}.`policy_version_id` AND `state` = 'published'";
    $segmentChildCheck = "SELECT COUNT(*) FROM `staff_schedule_days` d JOIN `staff_schedule_policy_versions` v ON v.`id` = d.`policy_version_id` WHERE d.`id` = {ROW}.`schedule_day_id` AND v.`state` = 'published'";
    foreach ([
        ['trg_staff_schedule_days_immutable_insert', 'INSERT', 'staff_schedule_days', $versionChildCheck],
        ['trg_staff_schedule_days_immutable_update', 'UPDATE', 'staff_schedule_days', $versionChildCheck],
        ['trg_staff_schedule_days_immutable_delete', 'DELETE', 'staff_schedule_days', $versionChildCheck],
        ['trg_staff_schedule_scopes_immutable_insert', 'INSERT', 'staff_schedule_scopes', $versionChildCheck],
        ['trg_staff_schedule_scopes_immutable_update', 'UPDATE', 'staff_schedule_scopes', $versionChildCheck],
        ['trg_staff_schedule_scopes_immutable_delete', 'DELETE', 'staff_schedule_scopes', $versionChildCheck],
        ['trg_staff_schedule_segments_immutable_insert', 'INSERT', 'staff_schedule_segments', $segmentChildCheck],
        ['trg_staff_schedule_segments_immutable_update', 'UPDATE', 'staff_schedule_segments', $segmentChildCheck],
        ['trg_staff_schedule_segments_immutable_delete', 'DELETE', 'staff_schedule_segments', $segmentChildCheck],
    ] as [$trigger, $timing, $table, $check]) {
        $immutableChildTrigger($trigger, $timing, $table, $check);
    }

    $createTrigger('trg_staff_calendar_exception_immutable_update', <<<'SQL'
CREATE TRIGGER `trg_staff_calendar_exception_immutable_update`
BEFORE UPDATE ON `staff_calendar_exceptions`
FOR EACH ROW
BEGIN
    IF OLD.`status` IN ('active','retired') THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Active calendar exceptions are immutable; create a superseding row';
    END IF;

    IF NEW.`supersedes_id` IS NOT NULL AND (
        SELECT COUNT(*) FROM `staff_calendar_exceptions` predecessor
        WHERE predecessor.`id` = NEW.`supersedes_id`
          AND predecessor.`status` IN ('active','retired')
          AND predecessor.`calendar_date` = NEW.`calendar_date`
          AND predecessor.`scope_type` = NEW.`scope_type`
          AND predecessor.`scope_id` = NEW.`scope_id`
    ) <> 1 THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Calendar successor must preserve predecessor date and scope';
    END IF;

    IF NEW.`status` IN ('active','retired')
       AND EXISTS (
           SELECT 1
           FROM `staff_calendar_exceptions` current_exception
           WHERE current_exception.`calendar_date` = NEW.`calendar_date`
             AND current_exception.`scope_type` = NEW.`scope_type`
             AND current_exception.`scope_id` = NEW.`scope_id`
             AND current_exception.`status` IN ('active','retired')
             AND NOT EXISTS (
                 SELECT 1
                 FROM `staff_calendar_exceptions` successor
                 WHERE successor.`supersedes_id` = current_exception.`id`
                   AND successor.`status` IN ('active','retired')
             )
             AND (NEW.`supersedes_id` IS NULL OR current_exception.`id` <> NEW.`supersedes_id`)
       ) THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Calendar exception must supersede the current exception for its date and scope';
    END IF;
END
SQL);
    $createTrigger('trg_staff_calendar_exception_supersession_guard', <<<'SQL'
CREATE TRIGGER `trg_staff_calendar_exception_supersession_guard`
BEFORE INSERT ON `staff_calendar_exceptions`
FOR EACH ROW
BEGIN
    IF NEW.`status` IN ('active','retired')
       AND NEW.`supersedes_id` IS NULL
       AND EXISTS (
           SELECT 1
           FROM `staff_calendar_exceptions` current_exception
           WHERE current_exception.`calendar_date` = NEW.`calendar_date`
             AND current_exception.`scope_type` = NEW.`scope_type`
             AND current_exception.`scope_id` = NEW.`scope_id`
             AND current_exception.`status` IN ('active','retired')
             AND NOT EXISTS (
                 SELECT 1
                 FROM `staff_calendar_exceptions` successor
                 WHERE successor.`supersedes_id` = current_exception.`id`
                   AND successor.`status` IN ('active','retired')
             )
       ) THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Calendar exception must supersede the current exception for its date and scope';
    END IF;

    IF NEW.`supersedes_id` IS NOT NULL AND (
        SELECT COUNT(*) FROM `staff_calendar_exceptions` predecessor
        WHERE predecessor.`id` = NEW.`supersedes_id`
          AND predecessor.`status` IN ('active','retired')
          AND predecessor.`calendar_date` = NEW.`calendar_date`
          AND predecessor.`scope_type` = NEW.`scope_type`
          AND predecessor.`scope_id` = NEW.`scope_id`
    ) <> 1 THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Calendar successor must preserve predecessor date and scope';
    END IF;
END
SQL);
    $createTrigger('trg_staff_calendar_exception_immutable_delete', <<<'SQL'
CREATE TRIGGER `trg_staff_calendar_exception_immutable_delete`
BEFORE DELETE ON `staff_calendar_exceptions`
FOR EACH ROW
BEGIN
    IF OLD.`status` IN ('active','retired') THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Active calendar exceptions cannot be deleted';
    END IF;
END
SQL);
};
