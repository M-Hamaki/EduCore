<?php

declare(strict_types=1);

/**
 * Integrated staff-HR permission policy, request, and quota-ledger schema.
 *
 * Ownership:
 * - Staff owns the permission types, effective policy versions/scopes, request
 *   snapshots, and the quota ledger.
 * - Attendance consumes only approved coverage through a later Staff-owned
 *   query contract; it does not gain a direct table dependency here.
 * - Workflow identifiers are validated by the Staff approval owner and remain
 *   scalar references because this migration sorts before the workflow
 *   migration on a fresh schema.
 *
 * The request is the historical source for what was asked and evaluated.
 * Quota-account counters are an optimization protected by a ledger; movement
 * rows are append-only and the application locks the account before changing
 * counters or appending a movement.
 *
 * Rollback in an isolated environment (after switching new readers off):
 * drop the triggers below, then drop staff_permission_quota_movements,
 * staff_permission_quota_accounts, staff_permission_request_periods,
 * staff_permission_requests, staff_permission_policy_scopes,
 * staff_permission_policy_versions, and staff_permission_types. Production
 * request and quota history must be archived and never silently dropped.
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

    $createTable('staff_permission_types', <<<'SQL'
CREATE TABLE `staff_permission_types` (
    `id` INT NOT NULL AUTO_INCREMENT,
    `code` VARCHAR(80) NOT NULL,
    `name` VARCHAR(200) NOT NULL,
    `coverage_behavior` ENUM('late_arrival','early_leave','mission','none') NOT NULL DEFAULT 'none',
    `requires_reason` TINYINT(1) NOT NULL DEFAULT 1,
    `requires_custom_label` TINYINT(1) NOT NULL DEFAULT 0,
    `requires_attachment` TINYINT(1) NOT NULL DEFAULT 0,
    `allow_retroactive` TINYINT(1) NOT NULL DEFAULT 0,
    `status` ENUM('active','inactive','retired') NOT NULL DEFAULT 'active',
    `created_by` INT NULL,
    `created_at` DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
    `updated_at` DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6) ON UPDATE CURRENT_TIMESTAMP(6),
    PRIMARY KEY (`id`),
    UNIQUE KEY `uk_staff_permission_type_code` (`code`),
    KEY `idx_staff_permission_type_status` (`status`, `coverage_behavior`),
    CONSTRAINT `chk_staff_permission_type_code` CHECK (CHAR_LENGTH(TRIM(`code`)) > 0),
    CONSTRAINT `chk_staff_permission_type_name` CHECK (CHAR_LENGTH(TRIM(`name`)) > 0),
    CONSTRAINT `chk_staff_permission_type_reason` CHECK (`requires_reason` IN (0, 1)),
    CONSTRAINT `chk_staff_permission_type_label` CHECK (`requires_custom_label` IN (0, 1)),
    CONSTRAINT `chk_staff_permission_type_attachment` CHECK (`requires_attachment` IN (0, 1)),
    CONSTRAINT `chk_staff_permission_type_retroactive` CHECK (`allow_retroactive` IN (0, 1))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL);

    $createTable('staff_permission_policy_versions', <<<'SQL'
CREATE TABLE `staff_permission_policy_versions` (
    `id` BIGINT NOT NULL AUTO_INCREMENT,
    `permission_type_id` INT NOT NULL,
    `version_no` INT UNSIGNED NOT NULL,
    `state` ENUM('draft','published','retired') NOT NULL DEFAULT 'draft',
    `valid_from` DATETIME(6) NOT NULL,
    `valid_to` DATETIME(6) NULL,
    `timezone` VARCHAR(64) NOT NULL DEFAULT 'Africa/Cairo',
    `max_requests_per_month` SMALLINT UNSIGNED NULL,
    `max_minutes_per_request` INT UNSIGNED NULL,
    `max_minutes_per_month` INT UNSIGNED NULL,
    `min_notice_minutes` INT UNSIGNED NOT NULL DEFAULT 0,
    `retroactive_limit_days` SMALLINT UNSIGNED NOT NULL DEFAULT 0,
    `reserve_on_submit` TINYINT(1) NOT NULL DEFAULT 1,
    `allow_overlap` TINYINT(1) NOT NULL DEFAULT 0,
    `allow_quota_override` TINYINT(1) NOT NULL DEFAULT 0,
    `quota_override_max_minutes` INT UNSIGNED NULL,
    `supersedes_id` BIGINT NULL,
    `published_by` INT NULL,
    `published_at` DATETIME(6) NULL,
    `created_by` INT NULL,
    `created_at` DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
    `updated_at` DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6) ON UPDATE CURRENT_TIMESTAMP(6),
    PRIMARY KEY (`id`),
    UNIQUE KEY `uk_staff_permission_policy_version` (`permission_type_id`, `version_no`),
    UNIQUE KEY `uk_staff_permission_policy_supersedes` (`supersedes_id`),
    KEY `idx_staff_permission_policy_effective` (`permission_type_id`, `state`, `valid_from`, `valid_to`),
    CONSTRAINT `fk_staff_permission_policy_type` FOREIGN KEY (`permission_type_id`) REFERENCES `staff_permission_types` (`id`) ON DELETE RESTRICT,
    CONSTRAINT `fk_staff_permission_policy_previous` FOREIGN KEY (`supersedes_id`) REFERENCES `staff_permission_policy_versions` (`id`) ON DELETE RESTRICT,
    CONSTRAINT `chk_staff_permission_policy_dates` CHECK (`valid_to` IS NULL OR `valid_to` > `valid_from`),
    CONSTRAINT `chk_staff_permission_policy_timezone` CHECK (CHAR_LENGTH(TRIM(`timezone`)) > 0),
    CONSTRAINT `chk_staff_permission_policy_reserve` CHECK (`reserve_on_submit` IN (0, 1)),
    CONSTRAINT `chk_staff_permission_policy_overlap` CHECK (`allow_overlap` IN (0, 1)),
    CONSTRAINT `chk_staff_permission_policy_override` CHECK (
        (`allow_quota_override` = 0 AND `quota_override_max_minutes` IS NULL)
        OR (`allow_quota_override` = 1 AND `quota_override_max_minutes` IS NOT NULL AND `quota_override_max_minutes` > 0)
    ),
    CONSTRAINT `chk_staff_permission_policy_month_limit` CHECK (
        `max_minutes_per_month` IS NULL
        OR `max_minutes_per_request` IS NULL
        OR `max_minutes_per_month` >= `max_minutes_per_request`
    ),
    CONSTRAINT `chk_staff_permission_policy_reserve_when_limited` CHECK (
        `reserve_on_submit` = 1
        OR (`max_requests_per_month` IS NULL AND `max_minutes_per_month` IS NULL)
    ),
    CONSTRAINT `chk_staff_permission_policy_publication` CHECK (
        `state` <> 'published' OR (`published_by` IS NOT NULL AND `published_at` IS NOT NULL)
    )
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL);

    $createTable('staff_permission_policy_scopes', <<<'SQL'
CREATE TABLE `staff_permission_policy_scopes` (
    `id` BIGINT NOT NULL AUTO_INCREMENT,
    `policy_version_id` BIGINT NOT NULL,
    `scope_type` ENUM('global','org_unit','job_title','group','staff') NOT NULL,
    `scope_id` INT NOT NULL DEFAULT 0,
    `priority` SMALLINT UNSIGNED NOT NULL DEFAULT 0,
    `valid_from` DATETIME(6) NOT NULL,
    `valid_to` DATETIME(6) NULL,
    `status` ENUM('active','suspended','retired') NOT NULL DEFAULT 'active',
    `created_by` INT NULL,
    `created_at` DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
    PRIMARY KEY (`id`),
    UNIQUE KEY `uk_staff_permission_policy_scope_start` (`policy_version_id`, `scope_type`, `scope_id`, `priority`, `valid_from`),
    KEY `idx_staff_permission_policy_scope_resolution` (`scope_type`, `scope_id`, `valid_from`, `valid_to`, `status`, `priority`),
    CONSTRAINT `fk_staff_permission_policy_scope_version` FOREIGN KEY (`policy_version_id`) REFERENCES `staff_permission_policy_versions` (`id`) ON DELETE RESTRICT,
    CONSTRAINT `chk_staff_permission_policy_scope_dates` CHECK (`valid_to` IS NULL OR `valid_to` > `valid_from`),
    CONSTRAINT `chk_staff_permission_policy_scope_identity` CHECK (
        (`scope_type` = 'global' AND `scope_id` = 0)
        OR (`scope_type` <> 'global' AND `scope_id` > 0)
    )
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL);

    $createTable('staff_permission_requests', <<<'SQL'
CREATE TABLE `staff_permission_requests` (
    `id` BIGINT NOT NULL AUTO_INCREMENT,
    `staff_user_id` INT NOT NULL,
    `permission_type_id` INT NOT NULL,
    `from_at` DATETIME(6) NOT NULL,
    `to_at` DATETIME(6) NOT NULL,
    `timezone` VARCHAR(64) NOT NULL DEFAULT 'Africa/Cairo',
    `requested_minutes` INT UNSIGNED NOT NULL,
    `custom_label` VARCHAR(200) NULL,
    `reason` TEXT NULL,
    `attachment_ref` VARCHAR(500) NULL,
    `status` ENUM(
        'draft','pending_approval','approved','rejected','withdrawn',
        'cancellation_requested','cancelled','expired',
        'cancelled_due_to_service_end','superseded'
    ) NOT NULL DEFAULT 'draft',
    `policy_version_id` BIGINT NULL,
    `policy_snapshot` JSON NULL,
    `workflow_version_id` BIGINT NULL,
    `workflow_instance_id` BIGINT NULL,
    `assignment_id` BIGINT NULL,
    `quota_exception` TINYINT(1) NOT NULL DEFAULT 0,
    `quota_exception_reason` VARCHAR(1000) NULL,
    `submitted_by` INT NULL,
    `submitted_at` DATETIME(6) NULL,
    `decided_at` DATETIME(6) NULL,
    `lock_version` INT UNSIGNED NOT NULL DEFAULT 1,
    `create_idempotency_key` VARCHAR(190) NOT NULL,
    `submission_idempotency_key` VARCHAR(190) NULL,
    `request_hash` CHAR(64) NOT NULL,
    `supersedes_id` BIGINT NULL,
    `created_at` DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
    `updated_at` DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6) ON UPDATE CURRENT_TIMESTAMP(6),
    PRIMARY KEY (`id`),
    UNIQUE KEY `uk_staff_permission_request_create_idempotency` (`create_idempotency_key`),
    UNIQUE KEY `uk_staff_permission_request_submission_idempotency` (`submission_idempotency_key`),
    KEY `idx_staff_permission_request_staff_window` (`staff_user_id`, `from_at`, `to_at`, `status`),
    KEY `idx_staff_permission_request_type_status` (`permission_type_id`, `status`, `from_at`),
    KEY `idx_staff_permission_request_workflow` (`workflow_instance_id`, `status`),
    KEY `idx_staff_permission_request_assignment` (`assignment_id`, `from_at`, `to_at`),
    KEY `idx_staff_permission_request_supersedes` (`supersedes_id`),
    CONSTRAINT `fk_staff_permission_request_type` FOREIGN KEY (`permission_type_id`) REFERENCES `staff_permission_types` (`id`) ON DELETE RESTRICT,
    CONSTRAINT `fk_staff_permission_request_policy` FOREIGN KEY (`policy_version_id`) REFERENCES `staff_permission_policy_versions` (`id`) ON DELETE RESTRICT,
    CONSTRAINT `fk_staff_permission_request_supersedes` FOREIGN KEY (`supersedes_id`) REFERENCES `staff_permission_requests` (`id`) ON DELETE RESTRICT,
    CONSTRAINT `chk_staff_permission_request_window` CHECK (`to_at` > `from_at`),
    CONSTRAINT `chk_staff_permission_request_minutes` CHECK (`requested_minutes` > 0),
    CONSTRAINT `chk_staff_permission_request_timezone` CHECK (CHAR_LENGTH(TRIM(`timezone`)) > 0),
    CONSTRAINT `chk_staff_permission_request_lock` CHECK (`lock_version` > 0),
    CONSTRAINT `chk_staff_permission_request_hash` CHECK (CHAR_LENGTH(`request_hash`) = 64),
    CONSTRAINT `chk_staff_permission_request_quota_exception` CHECK (
        (`quota_exception` = 0 AND `quota_exception_reason` IS NULL)
        OR (`quota_exception` = 1 AND CHAR_LENGTH(TRIM(COALESCE(`quota_exception_reason`, ''))) > 0)
    ),
    CONSTRAINT `chk_staff_permission_request_submission_snapshot` CHECK (
        (
            `status` IN ('draft', 'withdrawn')
            AND `submitted_at` IS NULL
            AND `policy_version_id` IS NULL
            AND `policy_snapshot` IS NULL
            AND `workflow_version_id` IS NULL
            AND `workflow_instance_id` IS NULL
            AND `assignment_id` IS NULL
            AND `submitted_by` IS NULL
            AND `submission_idempotency_key` IS NULL
        )
        OR (
            `submitted_at` IS NOT NULL
            AND `status` <> 'draft'
            AND `policy_version_id` IS NOT NULL
            AND `workflow_version_id` IS NOT NULL
            AND `assignment_id` IS NOT NULL
            AND `policy_snapshot` IS NOT NULL
            AND `submitted_by` IS NOT NULL
            AND `submission_idempotency_key` IS NOT NULL
        )
    ),
    CONSTRAINT `chk_staff_permission_request_decision_time` CHECK (
        `decided_at` IS NULL OR (`submitted_at` IS NOT NULL AND `decided_at` >= `submitted_at`)
    )
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL);

    $createTable('staff_permission_request_periods', <<<'SQL'
CREATE TABLE `staff_permission_request_periods` (
    `id` BIGINT NOT NULL AUTO_INCREMENT,
    `request_id` BIGINT NOT NULL,
    `period_key` CHAR(7) NOT NULL,
    `period_from_at` DATETIME(6) NOT NULL,
    `period_to_at` DATETIME(6) NOT NULL,
    `requested_count` SMALLINT UNSIGNED NOT NULL DEFAULT 1,
    `requested_minutes` INT UNSIGNED NOT NULL,
    `created_at` DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
    PRIMARY KEY (`id`),
    UNIQUE KEY `uk_staff_permission_request_period` (`request_id`, `period_key`),
    KEY `idx_staff_permission_request_period_quota` (`period_key`, `request_id`),
    CONSTRAINT `fk_staff_permission_request_period_request` FOREIGN KEY (`request_id`) REFERENCES `staff_permission_requests` (`id`) ON DELETE RESTRICT,
    CONSTRAINT `chk_staff_permission_request_period_key` CHECK (
        `period_key` REGEXP '^[0-9]{4}-(0[1-9]|1[0-2])$'
    ),
    CONSTRAINT `chk_staff_permission_request_period_window` CHECK (`period_to_at` > `period_from_at`),
    CONSTRAINT `chk_staff_permission_request_period_count` CHECK (`requested_count` = 1),
    CONSTRAINT `chk_staff_permission_request_period_minutes` CHECK (`requested_minutes` > 0)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL);

    $createTable('staff_permission_quota_accounts', <<<'SQL'
CREATE TABLE `staff_permission_quota_accounts` (
    `id` BIGINT NOT NULL AUTO_INCREMENT,
    `staff_user_id` INT NOT NULL,
    `permission_type_id` INT NOT NULL,
    `period_key` CHAR(7) NOT NULL,
    `status` ENUM('open','closed') NOT NULL DEFAULT 'open',
    `reserved_count` INT NOT NULL DEFAULT 0,
    `consumed_count` INT NOT NULL DEFAULT 0,
    `reserved_minutes` INT NOT NULL DEFAULT 0,
    `consumed_minutes` INT NOT NULL DEFAULT 0,
    `lock_version` INT NOT NULL DEFAULT 1,
    `created_at` DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
    `updated_at` DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6) ON UPDATE CURRENT_TIMESTAMP(6),
    PRIMARY KEY (`id`),
    UNIQUE KEY `uk_staff_permission_quota_account` (`staff_user_id`, `permission_type_id`, `period_key`),
    KEY `idx_staff_permission_quota_period` (`permission_type_id`, `period_key`, `status`),
    CONSTRAINT `fk_staff_permission_quota_type` FOREIGN KEY (`permission_type_id`) REFERENCES `staff_permission_types` (`id`) ON DELETE RESTRICT,
    CONSTRAINT `chk_staff_permission_quota_period_key` CHECK (
        `period_key` REGEXP '^[0-9]{4}-(0[1-9]|1[0-2])$'
    ),
    CONSTRAINT `chk_staff_permission_quota_counters` CHECK (
        `reserved_count` >= 0
        AND `consumed_count` >= 0
        AND `reserved_minutes` >= 0
        AND `consumed_minutes` >= 0
        AND `lock_version` > 0
    )
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL);

    $createTable('staff_permission_quota_movements', <<<'SQL'
CREATE TABLE `staff_permission_quota_movements` (
    `id` BIGINT NOT NULL AUTO_INCREMENT,
    `account_id` BIGINT NOT NULL,
    `request_id` BIGINT NOT NULL,
    `request_period_id` BIGINT NOT NULL,
    `movement_type` ENUM('reserve','consume','release','adjust','reverse') NOT NULL,
    `count_delta` INT NOT NULL,
    `minutes_delta` INT NOT NULL,
    `quota_exception` TINYINT(1) NOT NULL DEFAULT 0,
    `idempotency_key` VARCHAR(190) NOT NULL,
    `movement_hash` CHAR(64) NOT NULL,
    `reason_code` VARCHAR(100) NULL,
    `created_by` INT NULL,
    `created_at` DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
    PRIMARY KEY (`id`),
    UNIQUE KEY `uk_staff_permission_quota_movement_idempotency` (`idempotency_key`),
    UNIQUE KEY `uk_staff_permission_quota_movement_logical` (`account_id`, `request_id`, `request_period_id`, `movement_type`),
    KEY `idx_staff_permission_quota_movement_request` (`request_id`, `created_at`),
    KEY `idx_staff_permission_quota_movement_account` (`account_id`, `created_at`),
    CONSTRAINT `fk_staff_permission_quota_movement_account` FOREIGN KEY (`account_id`) REFERENCES `staff_permission_quota_accounts` (`id`) ON DELETE RESTRICT,
    CONSTRAINT `fk_staff_permission_quota_movement_request` FOREIGN KEY (`request_id`) REFERENCES `staff_permission_requests` (`id`) ON DELETE RESTRICT,
    CONSTRAINT `fk_staff_permission_quota_movement_period` FOREIGN KEY (`request_period_id`) REFERENCES `staff_permission_request_periods` (`id`) ON DELETE RESTRICT,
    CONSTRAINT `chk_staff_permission_quota_movement_hash` CHECK (CHAR_LENGTH(`movement_hash`) = 64),
    CONSTRAINT `chk_staff_permission_quota_movement_amount` CHECK (
        (
            `movement_type` = 'adjust'
            AND (`count_delta` <> 0 OR `minutes_delta` <> 0)
        )
        OR (
            `movement_type` <> 'adjust'
            AND `count_delta` >= 0
            AND `minutes_delta` >= 0
            AND (`count_delta` > 0 OR `minutes_delta` > 0)
        )
    ),
    CONSTRAINT `chk_staff_permission_quota_movement_exception` CHECK (`quota_exception` IN (0, 1))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL);

    $createTrigger('trg_staff_permission_type_guard_update', <<<'SQL'
CREATE TRIGGER `trg_staff_permission_type_guard_update`
BEFORE UPDATE ON `staff_permission_types`
FOR EACH ROW
BEGIN
    IF (
        NOT (NEW.`code` <=> OLD.`code`)
        OR NOT (NEW.`coverage_behavior` <=> OLD.`coverage_behavior`)
        OR NOT (NEW.`requires_reason` <=> OLD.`requires_reason`)
        OR NOT (NEW.`requires_custom_label` <=> OLD.`requires_custom_label`)
        OR NOT (NEW.`requires_attachment` <=> OLD.`requires_attachment`)
        OR NOT (NEW.`allow_retroactive` <=> OLD.`allow_retroactive`)
    ) AND (
        EXISTS (SELECT 1 FROM `staff_permission_policy_versions` policy_old WHERE policy_old.`permission_type_id` = OLD.`id`)
        OR EXISTS (SELECT 1 FROM `staff_permission_requests` request_old WHERE request_old.`permission_type_id` = OLD.`id`)
    ) THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Used permission type semantics are immutable; retire and create a new type';
    END IF;
END
SQL);
    $createTrigger('trg_staff_permission_type_no_delete', <<<'SQL'
CREATE TRIGGER `trg_staff_permission_type_no_delete`
BEFORE DELETE ON `staff_permission_types`
FOR EACH ROW
BEGIN
    IF EXISTS (SELECT 1 FROM `staff_permission_policy_versions` policy_old WHERE policy_old.`permission_type_id` = OLD.`id`)
       OR EXISTS (SELECT 1 FROM `staff_permission_requests` request_old WHERE request_old.`permission_type_id` = OLD.`id`)
       OR EXISTS (SELECT 1 FROM `staff_permission_quota_accounts` account_old WHERE account_old.`permission_type_id` = OLD.`id`) THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Used permission types cannot be deleted; retire them instead';
    END IF;
END
SQL);

    $createTrigger('trg_staff_permission_policy_version_immutable_update', <<<'SQL'
CREATE TRIGGER `trg_staff_permission_policy_version_immutable_update`
BEFORE UPDATE ON `staff_permission_policy_versions`
FOR EACH ROW
BEGIN
    IF OLD.`state` = 'published' AND NOT (
        NEW.`state` = 'retired'
        AND NEW.`permission_type_id` <=> OLD.`permission_type_id`
        AND NEW.`version_no` <=> OLD.`version_no`
        AND NEW.`valid_from` <=> OLD.`valid_from`
        AND NEW.`valid_to` <=> OLD.`valid_to`
        AND NEW.`timezone` <=> OLD.`timezone`
        AND NEW.`max_requests_per_month` <=> OLD.`max_requests_per_month`
        AND NEW.`max_minutes_per_request` <=> OLD.`max_minutes_per_request`
        AND NEW.`max_minutes_per_month` <=> OLD.`max_minutes_per_month`
        AND NEW.`min_notice_minutes` <=> OLD.`min_notice_minutes`
        AND NEW.`retroactive_limit_days` <=> OLD.`retroactive_limit_days`
        AND NEW.`reserve_on_submit` <=> OLD.`reserve_on_submit`
        AND NEW.`allow_overlap` <=> OLD.`allow_overlap`
        AND NEW.`allow_quota_override` <=> OLD.`allow_quota_override`
        AND NEW.`quota_override_max_minutes` <=> OLD.`quota_override_max_minutes`
        AND NEW.`supersedes_id` <=> OLD.`supersedes_id`
        AND NEW.`published_by` <=> OLD.`published_by`
        AND NEW.`published_at` <=> OLD.`published_at`
        AND NEW.`created_by` <=> OLD.`created_by`
        AND NEW.`created_at` <=> OLD.`created_at`
    ) THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Published permission policy versions are immutable except for retirement';
    END IF;
    IF OLD.`state` = 'retired' THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Retired permission policy versions are immutable';
    END IF;
END
SQL);
    $createTrigger('trg_staff_permission_policy_version_immutable_delete', <<<'SQL'
CREATE TRIGGER `trg_staff_permission_policy_version_immutable_delete`
BEFORE DELETE ON `staff_permission_policy_versions`
FOR EACH ROW
BEGIN
    IF OLD.`state` = 'published' THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Published permission policy versions cannot be deleted';
    END IF;
END
SQL);

    $immutableScopeTrigger = static function (
        string $trigger,
        string $timing
    ) use ($createTrigger): void {
        $versionSql = match ($timing) {
            'INSERT' => 'SELECT COUNT(*) FROM `staff_permission_policy_versions` WHERE `id` = NEW.`policy_version_id` AND `state` IN (\'published\', \'retired\')',
            'DELETE' => 'SELECT COUNT(*) FROM `staff_permission_policy_versions` WHERE `id` = OLD.`policy_version_id` AND `state` IN (\'published\', \'retired\')',
            default => '(SELECT COUNT(*) FROM `staff_permission_policy_versions` WHERE `id` = OLD.`policy_version_id` AND `state` IN (\'published\', \'retired\')) + (SELECT COUNT(*) FROM `staff_permission_policy_versions` WHERE `id` = NEW.`policy_version_id` AND `state` IN (\'published\', \'retired\'))',
        };
        $createTrigger($trigger, "CREATE TRIGGER `{$trigger}`\n"
            . "BEFORE {$timing} ON `staff_permission_policy_scopes`\n"
            . "FOR EACH ROW\nBEGIN\n"
            . "    IF ({$versionSql}) > 0 THEN\n"
            . "        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Published permission policy scopes are immutable';\n"
            . "    END IF;\nEND");
    };
    foreach ([
        ['trg_staff_permission_policy_scope_immutable_insert', 'INSERT'],
        ['trg_staff_permission_policy_scope_immutable_update', 'UPDATE'],
        ['trg_staff_permission_policy_scope_immutable_delete', 'DELETE'],
    ] as [$trigger, $timing]) {
        $immutableScopeTrigger($trigger, $timing);
    }

    $createTrigger('trg_staff_permission_request_guard_insert', <<<'SQL'
CREATE TRIGGER `trg_staff_permission_request_guard_insert`
BEFORE INSERT ON `staff_permission_requests`
FOR EACH ROW
BEGIN
    IF NEW.`status` <> 'draft' THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Permission requests must be created as drafts before submission';
    END IF;
END
SQL);
    $createTrigger('trg_staff_permission_request_guard_update', <<<'SQL'
CREATE TRIGGER `trg_staff_permission_request_guard_update`
BEFORE UPDATE ON `staff_permission_requests`
FOR EACH ROW
BEGIN
    DECLARE allocated_period_count INT DEFAULT 0;
    DECLARE allocated_minutes BIGINT DEFAULT 0;
    IF OLD.`status` <> 'draft' AND (
        NOT (NEW.`staff_user_id` <=> OLD.`staff_user_id`)
        OR NOT (NEW.`permission_type_id` <=> OLD.`permission_type_id`)
        OR NOT (NEW.`from_at` <=> OLD.`from_at`)
        OR NOT (NEW.`to_at` <=> OLD.`to_at`)
        OR NOT (NEW.`timezone` <=> OLD.`timezone`)
        OR NOT (NEW.`requested_minutes` <=> OLD.`requested_minutes`)
        OR NOT (NEW.`custom_label` <=> OLD.`custom_label`)
        OR NOT (NEW.`reason` <=> OLD.`reason`)
        OR NOT (NEW.`attachment_ref` <=> OLD.`attachment_ref`)
        OR NOT (NEW.`policy_version_id` <=> OLD.`policy_version_id`)
        OR NOT (NEW.`policy_snapshot` <=> OLD.`policy_snapshot`)
        OR NOT (NEW.`workflow_version_id` <=> OLD.`workflow_version_id`)
        OR NOT (NEW.`assignment_id` <=> OLD.`assignment_id`)
        OR NOT (NEW.`submitted_by` <=> OLD.`submitted_by`)
        OR NOT (NEW.`submitted_at` <=> OLD.`submitted_at`)
        OR NOT (NEW.`create_idempotency_key` <=> OLD.`create_idempotency_key`)
        OR NOT (NEW.`submission_idempotency_key` <=> OLD.`submission_idempotency_key`)
        OR NOT (NEW.`request_hash` <=> OLD.`request_hash`)
        OR NOT (NEW.`supersedes_id` <=> OLD.`supersedes_id`)
    ) THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Submitted permission request details are immutable; create a successor request';
    END IF;
    IF OLD.`status` <> 'draft'
       AND (
           NOT (NEW.`quota_exception` <=> OLD.`quota_exception`)
           OR NOT (NEW.`quota_exception_reason` <=> OLD.`quota_exception_reason`)
       )
       AND NOT (
           OLD.`status` = 'pending_approval'
           AND NEW.`status` = 'pending_approval'
           AND OLD.`quota_exception` = 0
           AND OLD.`quota_exception_reason` IS NULL
           AND NEW.`quota_exception` = 1
           AND CHAR_LENGTH(TRIM(COALESCE(NEW.`quota_exception_reason`, ''))) > 0
           AND NEW.`lock_version` = OLD.`lock_version` + 1
       ) THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Permission quota exception can only be recorded once during pending submission';
    END IF;
    IF OLD.`status` <> 'draft'
       AND OLD.`workflow_instance_id` IS NOT NULL
       AND NOT (NEW.`workflow_instance_id` <=> OLD.`workflow_instance_id`) THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Submitted permission workflow evidence cannot be replaced';
    END IF;
    IF OLD.`status` = 'draft' AND NEW.`status` NOT IN ('pending_approval', 'withdrawn') THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Permission drafts can only be submitted or withdrawn';
    END IF;
    IF OLD.`status` = 'draft' AND NEW.`status` = 'pending_approval' THEN
        SELECT COUNT(*), COALESCE(SUM(`requested_minutes`), 0)
          INTO allocated_period_count, allocated_minutes
          FROM `staff_permission_request_periods`
         WHERE `request_id` = OLD.`id`;
        IF allocated_period_count = 0 OR allocated_minutes <> NEW.`requested_minutes` THEN
            SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Submitted permission requests require complete monthly quota allocations';
        END IF;
    END IF;
    IF OLD.`status` IN ('approved','rejected','withdrawn','cancelled','expired','cancelled_due_to_service_end','superseded')
       AND NEW.`status` <> OLD.`status` THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Final permission requests cannot be reopened';
    END IF;
END
SQL);
    $createTrigger('trg_staff_permission_request_no_delete', <<<'SQL'
CREATE TRIGGER `trg_staff_permission_request_no_delete`
BEFORE DELETE ON `staff_permission_requests`
FOR EACH ROW
BEGIN
    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Permission requests are retained; use a workflow status instead of deletion';
END
SQL);

    $createTrigger('trg_staff_permission_request_period_guard_insert', <<<'SQL'
CREATE TRIGGER `trg_staff_permission_request_period_guard_insert`
BEFORE INSERT ON `staff_permission_request_periods`
FOR EACH ROW
BEGIN
    DECLARE parent_status VARCHAR(50);
    DECLARE parent_from DATETIME(6);
    DECLARE parent_to DATETIME(6);
    SELECT `status`, `from_at`, `to_at`
      INTO parent_status, parent_from, parent_to
      FROM `staff_permission_requests`
     WHERE `id` = NEW.`request_id`;
    IF parent_status IS NULL OR parent_status <> 'draft' THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Permission request periods can only be prepared while the request is a draft';
    END IF;
    IF NEW.`period_from_at` < parent_from OR NEW.`period_to_at` > parent_to THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Permission request period must remain inside the requested window';
    END IF;
    IF DATE_FORMAT(NEW.`period_from_at`, '%Y-%m') <> NEW.`period_key`
       OR DATE_FORMAT(DATE_SUB(NEW.`period_to_at`, INTERVAL 1 MICROSECOND), '%Y-%m') <> NEW.`period_key` THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Permission request period must remain inside its quota month';
    END IF;
END
SQL);
    $createTrigger('trg_staff_permission_request_period_guard_update', <<<'SQL'
CREATE TRIGGER `trg_staff_permission_request_period_guard_update`
BEFORE UPDATE ON `staff_permission_request_periods`
FOR EACH ROW
BEGIN
    DECLARE parent_status VARCHAR(50);
    DECLARE parent_from DATETIME(6);
    DECLARE parent_to DATETIME(6);
    IF NOT (NEW.`request_id` <=> OLD.`request_id`) THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Permission request periods cannot be moved to another request';
    END IF;
    SELECT `status`, `from_at`, `to_at`
      INTO parent_status, parent_from, parent_to
      FROM `staff_permission_requests`
     WHERE `id` = OLD.`request_id`;
    IF parent_status IS NULL OR parent_status <> 'draft' THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Submitted permission request periods are immutable';
    END IF;
    IF NEW.`period_from_at` < parent_from OR NEW.`period_to_at` > parent_to THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Permission request period must remain inside the requested window';
    END IF;
    IF DATE_FORMAT(NEW.`period_from_at`, '%Y-%m') <> NEW.`period_key`
       OR DATE_FORMAT(DATE_SUB(NEW.`period_to_at`, INTERVAL 1 MICROSECOND), '%Y-%m') <> NEW.`period_key` THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Permission request period must remain inside its quota month';
    END IF;
END
SQL);
    $createTrigger('trg_staff_permission_request_period_guard_delete', <<<'SQL'
CREATE TRIGGER `trg_staff_permission_request_period_guard_delete`
BEFORE DELETE ON `staff_permission_request_periods`
FOR EACH ROW
BEGIN
    DECLARE parent_status VARCHAR(50);
    SELECT `status` INTO parent_status FROM `staff_permission_requests` WHERE `id` = OLD.`request_id`;
    IF parent_status IS NULL OR parent_status <> 'draft' THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Submitted permission request periods cannot be deleted';
    END IF;
END
SQL);

    $createTrigger('trg_staff_permission_quota_account_guard_update', <<<'SQL'
CREATE TRIGGER `trg_staff_permission_quota_account_guard_update`
BEFORE UPDATE ON `staff_permission_quota_accounts`
FOR EACH ROW
BEGIN
    IF NOT (NEW.`staff_user_id` <=> OLD.`staff_user_id`)
       OR NOT (NEW.`permission_type_id` <=> OLD.`permission_type_id`)
       OR NOT (NEW.`period_key` <=> OLD.`period_key`) THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Quota account identity is immutable';
    END IF;
    IF OLD.`status` = 'closed' AND NEW.`status` <> OLD.`status` THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Closed quota accounts cannot be reopened';
    END IF;
END
SQL);

    $createTrigger('trg_staff_permission_quota_movement_guard_insert', <<<'SQL'
CREATE TRIGGER `trg_staff_permission_quota_movement_guard_insert`
BEFORE INSERT ON `staff_permission_quota_movements`
FOR EACH ROW
BEGIN
    DECLARE valid_link_count INT DEFAULT 0;
    DECLARE account_state VARCHAR(20);
    DECLARE request_state VARCHAR(50);
    DECLARE period_request_count SMALLINT DEFAULT 0;
    DECLARE period_requested_minutes INT DEFAULT 0;
    SELECT COUNT(*), MAX(account_row.`status`), MAX(request_row.`status`),
           MAX(period_row.`requested_count`), MAX(period_row.`requested_minutes`)
      INTO valid_link_count, account_state, request_state,
           period_request_count, period_requested_minutes
      FROM `staff_permission_quota_accounts` account_row
      JOIN `staff_permission_requests` request_row
        ON request_row.`id` = NEW.`request_id`
      JOIN `staff_permission_request_periods` period_row
        ON period_row.`id` = NEW.`request_period_id`
       AND period_row.`request_id` = request_row.`id`
     WHERE account_row.`id` = NEW.`account_id`
       AND account_row.`staff_user_id` = request_row.`staff_user_id`
       AND account_row.`permission_type_id` = request_row.`permission_type_id`
       AND account_row.`period_key` = period_row.`period_key`;
    IF valid_link_count <> 1 THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Quota movement must match the request, permission type, worker, and period account';
    END IF;
    IF account_state <> 'open' THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Closed quota accounts cannot receive movements';
    END IF;
    IF request_state IN ('draft', 'withdrawn') THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Quota movements can only follow a submitted permission request';
    END IF;
    IF NEW.`movement_type` <> 'adjust'
       AND (NEW.`count_delta` <> period_request_count OR NEW.`minutes_delta` <> period_requested_minutes) THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Quota movement must equal its monthly request allocation';
    END IF;
END
SQL);
    $createTrigger('trg_staff_permission_quota_movement_no_update', <<<'SQL'
CREATE TRIGGER `trg_staff_permission_quota_movement_no_update`
BEFORE UPDATE ON `staff_permission_quota_movements`
FOR EACH ROW
BEGIN
    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Permission quota movements are append-only';
END
SQL);
    $createTrigger('trg_staff_permission_quota_movement_no_delete', <<<'SQL'
CREATE TRIGGER `trg_staff_permission_quota_movement_no_delete`
BEFORE DELETE ON `staff_permission_quota_movements`
FOR EACH ROW
BEGIN
    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Permission quota movements are retained for reconciliation';
END
SQL);
};
