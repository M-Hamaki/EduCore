<?php

declare(strict_types=1);

/**
 * Integrated staff-HR foundation: approvals, notifications, external effects,
 * and resumable migration/cutover operations.
 *
 * The migration owns only additive infrastructure tables. Business writes,
 * audit records, inbox rows, and outbox rows are committed together by the
 * application services that use them; this migration performs no data seed.
 *
 * Rollback strategy: feature flags stop new consumers first. An isolated
 * schema rollback drops these tables in reverse dependency order only.
 */
return static function (PDO $db): void {
    $tableExists = static function (string $table) use ($db): bool {
        $stmt = $db->prepare(
            'SELECT COUNT(*) FROM information_schema.TABLES
             WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?'
        );
        $stmt->execute([$table]);

        return (int) $stmt->fetchColumn() > 0;
    };

    $createTable = static function (string $table, string $ddl) use ($db, $tableExists): void {
        if (!$tableExists($table)) {
            $db->exec($ddl);
        }
    };

    $createTable('staff_approval_workflows', <<<'SQL'
CREATE TABLE `staff_approval_workflows` (
    `id` INT NOT NULL AUTO_INCREMENT,
    `code` VARCHAR(80) NOT NULL,
    `name` VARCHAR(200) NOT NULL,
    `resource_type` VARCHAR(80) NOT NULL,
    `status` ENUM('active','inactive','retired') NOT NULL DEFAULT 'active',
    `created_by` INT NULL,
    `created_at` DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
    `updated_at` DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6) ON UPDATE CURRENT_TIMESTAMP(6),
    PRIMARY KEY (`id`),
    UNIQUE KEY `uk_staff_approval_workflow_code` (`code`),
    KEY `idx_staff_approval_workflow_resource` (`resource_type`, `status`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL);

    $createTable('staff_approval_workflow_versions', <<<'SQL'
CREATE TABLE `staff_approval_workflow_versions` (
    `id` BIGINT NOT NULL AUTO_INCREMENT,
    `workflow_id` INT NOT NULL,
    `version_no` INT UNSIGNED NOT NULL,
    `state` ENUM('draft','published','retired') NOT NULL DEFAULT 'draft',
    `valid_from` DATETIME(6) NOT NULL,
    `valid_to` DATETIME(6) NULL,
    `cancellation_rule` VARCHAR(100) NOT NULL DEFAULT 'request_cancellation',
    `escalation_rule` JSON NULL,
    `supersedes_id` BIGINT NULL,
    `published_by` INT NULL,
    `published_at` DATETIME(6) NULL,
    `created_by` INT NULL,
    `created_at` DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
    `updated_at` DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6) ON UPDATE CURRENT_TIMESTAMP(6),
    PRIMARY KEY (`id`),
    UNIQUE KEY `uk_staff_approval_workflow_version` (`workflow_id`, `version_no`),
    UNIQUE KEY `uk_staff_approval_workflow_supersedes` (`supersedes_id`),
    KEY `idx_staff_approval_workflow_effective` (`workflow_id`, `state`, `valid_from`, `valid_to`),
    CONSTRAINT `fk_staff_approval_version_workflow` FOREIGN KEY (`workflow_id`) REFERENCES `staff_approval_workflows` (`id`) ON DELETE RESTRICT,
    CONSTRAINT `fk_staff_approval_version_previous` FOREIGN KEY (`supersedes_id`) REFERENCES `staff_approval_workflow_versions` (`id`) ON DELETE RESTRICT,
    CONSTRAINT `chk_staff_approval_version_dates` CHECK (`valid_to` IS NULL OR `valid_to` > `valid_from`),
    CONSTRAINT `chk_staff_approval_version_publish` CHECK (`state` <> 'published' OR (`published_by` IS NOT NULL AND `published_at` IS NOT NULL))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL);

    $createTable('staff_approval_stages', <<<'SQL'
CREATE TABLE `staff_approval_stages` (
    `id` BIGINT NOT NULL AUTO_INCREMENT,
    `workflow_version_id` BIGINT NOT NULL,
    `sequence_no` INT UNSIGNED NOT NULL,
    `name` VARCHAR(200) NOT NULL,
    `resolver_type` ENUM('direct_manager','admin_manager','named_users','role_scope') NOT NULL,
    `resolver_config` JSON NULL,
    `decision_mode` ENUM('sequential','any_one','all','quorum') NOT NULL DEFAULT 'sequential',
    `sla_minutes` INT UNSIGNED NULL,
    `on_timeout` ENUM('fail_closed','escalate','reassign','expire') NOT NULL DEFAULT 'fail_closed',
    `self_approval_rule` ENUM('forbid','require_alternate','allow_explicit') NOT NULL DEFAULT 'forbid',
    `same_actor_rule` ENUM('forbid','merge','require_alternate') NOT NULL DEFAULT 'forbid',
    `quorum_count` INT UNSIGNED NULL,
    `tie_rule` VARCHAR(50) NOT NULL DEFAULT 'reject',
    `rejection_rule` VARCHAR(50) NOT NULL DEFAULT 'stop_workflow',
    `created_at` DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
    PRIMARY KEY (`id`),
    UNIQUE KEY `uk_staff_approval_stage_sequence` (`workflow_version_id`, `sequence_no`),
    KEY `idx_staff_approval_stage_resolver` (`resolver_type`, `decision_mode`),
    CONSTRAINT `fk_staff_approval_stage_version` FOREIGN KEY (`workflow_version_id`) REFERENCES `staff_approval_workflow_versions` (`id`) ON DELETE RESTRICT,
    CONSTRAINT `chk_staff_approval_stage_sequence` CHECK (`sequence_no` > 0),
    CONSTRAINT `chk_staff_approval_stage_quorum` CHECK (`decision_mode` <> 'quorum' OR (`quorum_count` IS NOT NULL AND `quorum_count` > 0))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL);

    $createTable('staff_approval_instances', <<<'SQL'
CREATE TABLE `staff_approval_instances` (
    `id` BIGINT NOT NULL AUTO_INCREMENT,
    `resource_type` VARCHAR(80) NOT NULL,
    `resource_id` BIGINT NOT NULL,
    `workflow_version_id` BIGINT NOT NULL,
    `status` ENUM('pending','approved','rejected','cancelled','expired') NOT NULL DEFAULT 'pending',
    `current_sequence` INT UNSIGNED NOT NULL DEFAULT 1,
    `started_at` DATETIME(6) NOT NULL,
    `completed_at` DATETIME(6) NULL,
    `snapshot_json` JSON NOT NULL,
    `lock_version` INT UNSIGNED NOT NULL DEFAULT 1,
    `idempotency_key` VARCHAR(190) NOT NULL,
    `created_at` DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
    `updated_at` DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6) ON UPDATE CURRENT_TIMESTAMP(6),
    PRIMARY KEY (`id`),
    UNIQUE KEY `uk_staff_approval_instance_idempotency` (`idempotency_key`),
    KEY `idx_staff_approval_instance_resource` (`resource_type`, `resource_id`, `status`),
    KEY `idx_staff_approval_instance_workflow` (`workflow_version_id`, `status`),
    CONSTRAINT `fk_staff_approval_instance_version` FOREIGN KEY (`workflow_version_id`) REFERENCES `staff_approval_workflow_versions` (`id`) ON DELETE RESTRICT,
    CONSTRAINT `chk_staff_approval_instance_sequence` CHECK (`current_sequence` > 0),
    CONSTRAINT `chk_staff_approval_instance_completion` CHECK ((`status` = 'pending' AND `completed_at` IS NULL) OR (`status` <> 'pending' AND `completed_at` IS NOT NULL))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL);

    $createTable('staff_approval_steps', <<<'SQL'
CREATE TABLE `staff_approval_steps` (
    `id` BIGINT NOT NULL AUTO_INCREMENT,
    `instance_id` BIGINT NOT NULL,
    `stage_id` BIGINT NOT NULL,
    `sequence_no` INT UNSIGNED NOT NULL,
    `status` ENUM('waiting','active','approved','rejected','skipped','expired') NOT NULL DEFAULT 'waiting',
    `due_at` DATETIME(6) NULL,
    `activated_at` DATETIME(6) NULL,
    `completed_at` DATETIME(6) NULL,
    `snapshot_json` JSON NOT NULL,
    `lock_version` INT UNSIGNED NOT NULL DEFAULT 1,
    `created_at` DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
    `updated_at` DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6) ON UPDATE CURRENT_TIMESTAMP(6),
    PRIMARY KEY (`id`),
    UNIQUE KEY `uk_staff_approval_step_sequence` (`instance_id`, `sequence_no`),
    KEY `idx_staff_approval_step_status_due` (`status`, `due_at`),
    KEY `idx_staff_approval_step_stage` (`stage_id`),
    CONSTRAINT `fk_staff_approval_step_instance` FOREIGN KEY (`instance_id`) REFERENCES `staff_approval_instances` (`id`) ON DELETE RESTRICT,
    CONSTRAINT `fk_staff_approval_step_stage` FOREIGN KEY (`stage_id`) REFERENCES `staff_approval_stages` (`id`) ON DELETE RESTRICT,
    CONSTRAINT `chk_staff_approval_step_sequence` CHECK (`sequence_no` > 0)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL);

    $createTable('staff_approval_assignees', <<<'SQL'
CREATE TABLE `staff_approval_assignees` (
    `id` BIGINT NOT NULL AUTO_INCREMENT,
    `step_id` BIGINT NOT NULL,
    `assignee_user_id` INT NOT NULL,
    `relationship_kind` VARCHAR(50) NOT NULL,
    `assignment_snapshot` JSON NOT NULL,
    `status` ENUM('eligible','reassigned','removed','decided') NOT NULL DEFAULT 'eligible',
    `created_at` DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
    `updated_at` DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6) ON UPDATE CURRENT_TIMESTAMP(6),
    PRIMARY KEY (`id`),
    UNIQUE KEY `uk_staff_approval_assignee_step_actor` (`step_id`, `assignee_user_id`),
    KEY `idx_staff_approval_assignee_actor` (`assignee_user_id`, `status`, `step_id`),
    CONSTRAINT `fk_staff_approval_assignee_step` FOREIGN KEY (`step_id`) REFERENCES `staff_approval_steps` (`id`) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL);

    $createTable('staff_approval_decisions', <<<'SQL'
CREATE TABLE `staff_approval_decisions` (
    `id` BIGINT NOT NULL AUTO_INCREMENT,
    `step_id` BIGINT NOT NULL,
    `actor_user_id` INT NOT NULL,
    `acting_for_user_id` INT NULL,
    `decision` ENUM('approve','reject','abstain') NOT NULL,
    `comment` TEXT NULL,
    `decided_at` DATETIME(6) NOT NULL,
    `idempotency_key` VARCHAR(190) NOT NULL,
    `is_effective` TINYINT(1) NOT NULL DEFAULT 1,
    `created_at` DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
    PRIMARY KEY (`id`),
    UNIQUE KEY `uk_staff_approval_decision_idempotency` (`idempotency_key`),
    UNIQUE KEY `uk_staff_approval_decision_actor` (`step_id`, `actor_user_id`),
    KEY `idx_staff_approval_decision_step` (`step_id`, `decided_at`),
    CONSTRAINT `fk_staff_approval_decision_step` FOREIGN KEY (`step_id`) REFERENCES `staff_approval_steps` (`id`) ON DELETE RESTRICT,
    CONSTRAINT `chk_staff_approval_decision_proxy` CHECK (`acting_for_user_id` IS NULL OR `acting_for_user_id` <> `actor_user_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL);

    $createTable('staff_approval_escalation_events', <<<'SQL'
CREATE TABLE `staff_approval_escalation_events` (
    `id` BIGINT NOT NULL AUTO_INCREMENT,
    `step_id` BIGINT NOT NULL,
    `event_type` VARCHAR(80) NOT NULL,
    `from_assignee` INT NULL,
    `to_assignee` INT NULL,
    `reason` VARCHAR(1000) NOT NULL,
    `created_by` INT NULL,
    `created_at` DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
    PRIMARY KEY (`id`),
    KEY `idx_staff_approval_escalation_step` (`step_id`, `created_at`),
    KEY `idx_staff_approval_escalation_target` (`to_assignee`, `created_at`),
    CONSTRAINT `fk_staff_approval_escalation_step` FOREIGN KEY (`step_id`) REFERENCES `staff_approval_steps` (`id`) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL);

    $createTable('user_notification_inbox', <<<'SQL'
CREATE TABLE `user_notification_inbox` (
    `id` BIGINT NOT NULL AUTO_INCREMENT,
    `recipient_user_id` INT NOT NULL,
    `event_key` VARCHAR(190) NOT NULL,
    `idempotency_key` VARCHAR(190) NOT NULL,
    `neutral_text` VARCHAR(500) NOT NULL,
    `secure_route` VARCHAR(500) NOT NULL,
    `metadata_json` JSON NULL,
    `created_at` DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
    `read_at` DATETIME(6) NULL,
    `archived_at` DATETIME(6) NULL,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uk_user_notification_event_recipient` (`event_key`, `recipient_user_id`),
    UNIQUE KEY `uk_user_notification_idempotency` (`idempotency_key`, `recipient_user_id`),
    KEY `idx_user_notification_recipient_state` (`recipient_user_id`, `read_at`, `archived_at`, `created_at`),
    CONSTRAINT `chk_user_notification_archive` CHECK (`archived_at` IS NULL OR `archived_at` >= `created_at`),
    CONSTRAINT `chk_user_notification_read` CHECK (`read_at` IS NULL OR `read_at` >= `created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL);

    $createTable('notification_outbox', <<<'SQL'
CREATE TABLE `notification_outbox` (
    `id` BIGINT NOT NULL AUTO_INCREMENT,
    `inbox_id` BIGINT NOT NULL,
    `event_key` VARCHAR(190) NOT NULL,
    `recipient_user_id` INT NOT NULL,
    `idempotency_key` VARCHAR(190) NOT NULL,
    `payload` JSON NOT NULL,
    `attempts` INT UNSIGNED NOT NULL DEFAULT 0,
    `next_attempt_at` DATETIME(6) NULL,
    `status` ENUM('pending','processing','delivered','retry','failed','cancelled') NOT NULL DEFAULT 'pending',
    `last_error` VARCHAR(500) NULL,
    `delivered_at` DATETIME(6) NULL,
    `created_at` DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
    `updated_at` DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6) ON UPDATE CURRENT_TIMESTAMP(6),
    PRIMARY KEY (`id`),
    UNIQUE KEY `uk_notification_outbox_event_recipient` (`event_key`, `recipient_user_id`),
    UNIQUE KEY `uk_notification_outbox_idempotency` (`idempotency_key`, `recipient_user_id`),
    KEY `idx_notification_outbox_dispatch` (`status`, `next_attempt_at`, `attempts`),
    KEY `idx_notification_outbox_recipient` (`recipient_user_id`, `status`, `created_at`),
    CONSTRAINT `fk_notification_outbox_inbox` FOREIGN KEY (`inbox_id`) REFERENCES `user_notification_inbox` (`id`) ON DELETE RESTRICT,
    CONSTRAINT `chk_notification_outbox_delivery` CHECK (`status` <> 'delivered' OR `delivered_at` IS NOT NULL)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL);

    $createTable('staff_external_effects', <<<'SQL'
CREATE TABLE `staff_external_effects` (
    `id` BIGINT NOT NULL AUTO_INCREMENT,
    `effect_key` VARCHAR(190) NOT NULL,
    `idempotency_key` VARCHAR(190) NOT NULL,
    `resource_type` VARCHAR(80) NOT NULL,
    `resource_id` BIGINT NOT NULL,
    `target_module` VARCHAR(80) NOT NULL,
    `fact_type` VARCHAR(100) NOT NULL,
    `units` DECIMAL(18,6) NOT NULL,
    `effective_period` VARCHAR(20) NOT NULL,
    `payload` JSON NULL,
    `status` ENUM('pending','processing','accepted','retry','failed','reversed','cancelled') NOT NULL DEFAULT 'pending',
    `result_ref` VARCHAR(255) NULL,
    `last_error` VARCHAR(500) NULL,
    `attempts` INT UNSIGNED NOT NULL DEFAULT 0,
    `next_attempt_at` DATETIME(6) NULL,
    `completed_at` DATETIME(6) NULL,
    `created_at` DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
    `updated_at` DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6) ON UPDATE CURRENT_TIMESTAMP(6),
    PRIMARY KEY (`id`),
    UNIQUE KEY `uk_staff_external_effect_key` (`effect_key`),
    UNIQUE KEY `uk_staff_external_effect_idempotency` (`idempotency_key`),
    KEY `idx_staff_external_effect_dispatch` (`status`, `next_attempt_at`, `attempts`),
    KEY `idx_staff_external_effect_resource` (`resource_type`, `resource_id`, `target_module`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL);

    $createTable('staff_hr_cutover_windows', <<<'SQL'
CREATE TABLE `staff_hr_cutover_windows` (
    `id` BIGINT NOT NULL AUTO_INCREMENT,
    `opened_at` DATETIME(6) NOT NULL,
    `write_mode` ENUM('capture','freeze','legacy_only','new_only') NOT NULL,
    `source_watermark` VARCHAR(255) NULL,
    `target_watermark` VARCHAR(255) NULL,
    `closed_at` DATETIME(6) NULL,
    `approved_by` INT NOT NULL,
    `rollback_deadline` DATETIME(6) NULL,
    `reconciliation_status` ENUM('pending','matched','exceptions','failed','rolled_back') NOT NULL DEFAULT 'pending',
    `status` ENUM('open','closed','rolled_back') NOT NULL DEFAULT 'open',
    `idempotency_key` VARCHAR(190) NOT NULL,
    `created_at` DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
    `updated_at` DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6) ON UPDATE CURRENT_TIMESTAMP(6),
    PRIMARY KEY (`id`),
    UNIQUE KEY `uk_staff_hr_cutover_idempotency` (`idempotency_key`),
    KEY `idx_staff_hr_cutover_status` (`status`, `opened_at`, `closed_at`),
    CONSTRAINT `chk_staff_hr_cutover_close` CHECK ((`status` = 'open' AND `closed_at` IS NULL) OR (`status` <> 'open' AND `closed_at` IS NOT NULL)),
    CONSTRAINT `chk_staff_hr_cutover_deadline` CHECK (`rollback_deadline` IS NULL OR `rollback_deadline` >= `opened_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL);

    $createTable('staff_hr_migration_batches', <<<'SQL'
CREATE TABLE `staff_hr_migration_batches` (
    `id` BIGINT NOT NULL AUTO_INCREMENT,
    `migration_key` VARCHAR(190) NOT NULL,
    `source_watermark` VARCHAR(255) NULL,
    `target_watermark` VARCHAR(255) NULL,
    `started_at` DATETIME(6) NOT NULL,
    `checkpoint_at` DATETIME(6) NULL,
    `completed_at` DATETIME(6) NULL,
    `status` ENUM('queued','running','completed','completed_with_exceptions','failed','rolled_back') NOT NULL DEFAULT 'queued',
    `read_count` BIGINT UNSIGNED NOT NULL DEFAULT 0,
    `write_count` BIGINT UNSIGNED NOT NULL DEFAULT 0,
    `skip_count` BIGINT UNSIGNED NOT NULL DEFAULT 0,
    `error_count` BIGINT UNSIGNED NOT NULL DEFAULT 0,
    `checksum` CHAR(64) NULL,
    `resume_token` VARCHAR(500) NULL,
    `idempotency_key` VARCHAR(190) NOT NULL,
    `cutover_window_id` BIGINT NULL,
    `manifest_json` JSON NULL,
    `created_by` INT NULL,
    `created_at` DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
    `updated_at` DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6) ON UPDATE CURRENT_TIMESTAMP(6),
    PRIMARY KEY (`id`),
    UNIQUE KEY `uk_staff_hr_migration_idempotency` (`idempotency_key`),
    KEY `idx_staff_hr_migration_key_status` (`migration_key`, `status`, `started_at`),
    KEY `idx_staff_hr_migration_cutover` (`cutover_window_id`, `status`),
    CONSTRAINT `fk_staff_hr_migration_cutover` FOREIGN KEY (`cutover_window_id`) REFERENCES `staff_hr_cutover_windows` (`id`) ON DELETE RESTRICT,
    CONSTRAINT `chk_staff_hr_migration_completion` CHECK (`completed_at` IS NULL OR `completed_at` >= `started_at`),
    CONSTRAINT `chk_staff_hr_migration_checkpoint` CHECK (`checkpoint_at` IS NULL OR `checkpoint_at` >= `started_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL);

    $createTable('staff_hr_migration_exceptions', <<<'SQL'
CREATE TABLE `staff_hr_migration_exceptions` (
    `id` BIGINT NOT NULL AUTO_INCREMENT,
    `batch_id` BIGINT NOT NULL,
    `source_type` VARCHAR(80) NOT NULL,
    `source_key` VARCHAR(190) NOT NULL,
    `reason_code` VARCHAR(80) NOT NULL,
    `payload_hash` CHAR(64) NOT NULL,
    `resolution_status` ENUM('open','resolved','ignored') NOT NULL DEFAULT 'open',
    `resolution_comment` VARCHAR(1000) NULL,
    `resolved_by` INT NULL,
    `resolved_at` DATETIME(6) NULL,
    `created_at` DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
    PRIMARY KEY (`id`),
    UNIQUE KEY `uk_staff_hr_migration_exception` (`batch_id`, `source_type`, `source_key`, `reason_code`),
    KEY `idx_staff_hr_migration_resolution` (`resolution_status`, `reason_code`, `created_at`),
    CONSTRAINT `fk_staff_hr_migration_exception_batch` FOREIGN KEY (`batch_id`) REFERENCES `staff_hr_migration_batches` (`id`) ON DELETE RESTRICT,
    CONSTRAINT `chk_staff_hr_migration_resolution` CHECK ((`resolution_status` = 'open' AND `resolved_at` IS NULL) OR (`resolution_status` <> 'open' AND `resolved_at` IS NOT NULL))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL);
};
