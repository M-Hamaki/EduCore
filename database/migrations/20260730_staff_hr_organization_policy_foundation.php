<?php

declare(strict_types=1);

/**
 * Integrated staff-HR foundation: effective-dated organization and policies.
 *
 * The migration is additive and deliberately leaves every legacy staff field
 * and table untouched. Runtime services may therefore fall back to the legacy
 * model through the feature flag while the new read side is reconciled.
 *
 * Tables created:
 *   - staff_org_units
 *   - staff_job_titles
 *   - staff_assignments
 *   - staff_manager_assignments
 *   - staff_policy_groups
 *   - staff_policy_group_memberships
 *   - staff_delegations
 *   - staff_policy_definitions
 *   - staff_policy_versions
 *   - staff_policy_scopes
 *
 * Rollback strategy: switch readers to the legacy model. A schema rollback in
 * an isolated environment drops only these tables in reverse dependency order.
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

    $triggerExists = static function (string $trigger) use ($db): bool {
        $stmt = $db->prepare(
            'SELECT COUNT(*) FROM information_schema.TRIGGERS
             WHERE TRIGGER_SCHEMA = DATABASE() AND TRIGGER_NAME = ?'
        );
        $stmt->execute([$trigger]);

        return (int) $stmt->fetchColumn() > 0;
    };

    $createTable('staff_org_units', <<<'SQL'
CREATE TABLE `staff_org_units` (
    `id` INT NOT NULL AUTO_INCREMENT,
    `code` VARCHAR(80) NOT NULL,
    `name` VARCHAR(200) NOT NULL,
    `unit_type` VARCHAR(50) NOT NULL,
    `parent_id` INT NULL,
    `valid_from` DATE NOT NULL,
    `valid_to` DATE NULL,
    `status` ENUM('active','inactive','retired') NOT NULL DEFAULT 'active',
    `created_by` INT NULL,
    `created_at` DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
    `updated_at` DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6) ON UPDATE CURRENT_TIMESTAMP(6),
    PRIMARY KEY (`id`),
    UNIQUE KEY `uk_staff_org_unit_code_start` (`code`, `valid_from`),
    KEY `idx_staff_org_unit_effective` (`code`, `valid_from`, `valid_to`, `status`),
    KEY `idx_staff_org_unit_parent_effective` (`parent_id`, `valid_from`, `valid_to`),
    CONSTRAINT `fk_staff_org_unit_parent` FOREIGN KEY (`parent_id`) REFERENCES `staff_org_units` (`id`) ON DELETE RESTRICT,
    CONSTRAINT `chk_staff_org_unit_dates` CHECK (`valid_to` IS NULL OR `valid_to` >= `valid_from`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL);

    // MariaDB forbids AUTO_INCREMENT columns inside CHECK expressions. The
    // equivalent self-parent invariant is therefore enforced by a migration-
    // owned trigger; longer hierarchy cycles remain an application policy.
    if (!$triggerExists('trg_staff_org_units_no_self_parent')) {
        $db->exec(<<<'SQL'
CREATE TRIGGER `trg_staff_org_units_no_self_parent`
BEFORE UPDATE ON `staff_org_units`
FOR EACH ROW
BEGIN
    IF NEW.`parent_id` IS NOT NULL AND NEW.`parent_id` = NEW.`id` THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Staff organization unit cannot be its own parent';
    END IF;
END
SQL);
    }

    $createTable('staff_job_titles', <<<'SQL'
CREATE TABLE `staff_job_titles` (
    `id` INT NOT NULL AUTO_INCREMENT,
    `code` VARCHAR(80) NOT NULL,
    `name` VARCHAR(200) NOT NULL,
    `active_from` DATE NOT NULL,
    `active_to` DATE NULL,
    `status` ENUM('active','inactive','retired') NOT NULL DEFAULT 'active',
    `created_by` INT NULL,
    `created_at` DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
    `updated_at` DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6) ON UPDATE CURRENT_TIMESTAMP(6),
    PRIMARY KEY (`id`),
    UNIQUE KEY `uk_staff_job_title_code_start` (`code`, `active_from`),
    KEY `idx_staff_job_title_effective` (`code`, `active_from`, `active_to`, `status`),
    CONSTRAINT `chk_staff_job_title_dates` CHECK (`active_to` IS NULL OR `active_to` >= `active_from`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL);

    $createTable('staff_assignments', <<<'SQL'
CREATE TABLE `staff_assignments` (
    `id` BIGINT NOT NULL AUTO_INCREMENT,
    `staff_user_id` INT NOT NULL,
    `org_unit_id` INT NOT NULL,
    `job_title_id` INT NOT NULL,
    `assignment_kind` ENUM('primary','secondary','temporary') NOT NULL DEFAULT 'primary',
    `employment_status` VARCHAR(50) NOT NULL,
    `work_fraction` DECIMAL(5,4) NOT NULL DEFAULT 1.0000,
    `valid_from` DATE NOT NULL,
    `valid_to` DATE NULL,
    `source` VARCHAR(50) NOT NULL DEFAULT 'manual',
    `source_ref` VARCHAR(190) NULL,
    `version` INT UNSIGNED NOT NULL DEFAULT 1,
    `created_by` INT NULL,
    `created_at` DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
    `updated_at` DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6) ON UPDATE CURRENT_TIMESTAMP(6),
    PRIMARY KEY (`id`),
    UNIQUE KEY `uk_staff_assignment_version` (`staff_user_id`, `assignment_kind`, `valid_from`, `version`),
    KEY `idx_staff_assignment_effective` (`staff_user_id`, `valid_from`, `valid_to`, `assignment_kind`, `employment_status`),
    KEY `idx_staff_assignment_org_effective` (`org_unit_id`, `valid_from`, `valid_to`),
    KEY `idx_staff_assignment_title_effective` (`job_title_id`, `valid_from`, `valid_to`),
    CONSTRAINT `fk_staff_assignment_org` FOREIGN KEY (`org_unit_id`) REFERENCES `staff_org_units` (`id`) ON DELETE RESTRICT,
    CONSTRAINT `fk_staff_assignment_title` FOREIGN KEY (`job_title_id`) REFERENCES `staff_job_titles` (`id`) ON DELETE RESTRICT,
    CONSTRAINT `chk_staff_assignment_dates` CHECK (`valid_to` IS NULL OR `valid_to` >= `valid_from`),
    CONSTRAINT `chk_staff_assignment_fraction` CHECK (`work_fraction` > 0 AND `work_fraction` <= 1)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL);

    $createTable('staff_manager_assignments', <<<'SQL'
CREATE TABLE `staff_manager_assignments` (
    `id` BIGINT NOT NULL AUTO_INCREMENT,
    `subject_type` ENUM('staff','org_unit') NOT NULL,
    `subject_id` INT NOT NULL,
    `manager_user_id` INT NOT NULL,
    `manager_kind` ENUM('direct','administrative','hr') NOT NULL,
    `priority` SMALLINT NOT NULL DEFAULT 0,
    `valid_from` DATE NOT NULL,
    `valid_to` DATE NULL,
    `status` ENUM('active','suspended','retired') NOT NULL DEFAULT 'active',
    `source` VARCHAR(50) NOT NULL DEFAULT 'manual',
    `created_by` INT NULL,
    `created_at` DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
    `updated_at` DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6) ON UPDATE CURRENT_TIMESTAMP(6),
    PRIMARY KEY (`id`),
    UNIQUE KEY `uk_staff_manager_scope_start` (`subject_type`, `subject_id`, `manager_kind`, `priority`, `valid_from`),
    KEY `idx_staff_manager_subject_effective` (`subject_type`, `subject_id`, `manager_kind`, `valid_from`, `valid_to`, `status`, `priority`),
    KEY `idx_staff_manager_actor_effective` (`manager_user_id`, `valid_from`, `valid_to`, `status`),
    CONSTRAINT `chk_staff_manager_dates` CHECK (`valid_to` IS NULL OR `valid_to` >= `valid_from`),
    CONSTRAINT `chk_staff_manager_not_self` CHECK (`subject_type` <> 'staff' OR `subject_id` <> `manager_user_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL);

    $createTable('staff_policy_groups', <<<'SQL'
CREATE TABLE `staff_policy_groups` (
    `id` INT NOT NULL AUTO_INCREMENT,
    `code` VARCHAR(80) NOT NULL,
    `name` VARCHAR(200) NOT NULL,
    `purpose` VARCHAR(500) NULL,
    `valid_from` DATE NOT NULL,
    `valid_to` DATE NULL,
    `status` ENUM('active','inactive','retired') NOT NULL DEFAULT 'active',
    `created_by` INT NULL,
    `created_at` DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
    `updated_at` DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6) ON UPDATE CURRENT_TIMESTAMP(6),
    PRIMARY KEY (`id`),
    UNIQUE KEY `uk_staff_policy_group_code_start` (`code`, `valid_from`),
    KEY `idx_staff_policy_group_effective` (`code`, `valid_from`, `valid_to`, `status`),
    CONSTRAINT `chk_staff_policy_group_dates` CHECK (`valid_to` IS NULL OR `valid_to` >= `valid_from`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL);

    $createTable('staff_policy_group_memberships', <<<'SQL'
CREATE TABLE `staff_policy_group_memberships` (
    `id` BIGINT NOT NULL AUTO_INCREMENT,
    `group_id` INT NOT NULL,
    `staff_user_id` INT NOT NULL,
    `valid_from` DATE NOT NULL,
    `valid_to` DATE NULL,
    `status` ENUM('active','suspended','retired') NOT NULL DEFAULT 'active',
    `source` VARCHAR(50) NOT NULL DEFAULT 'manual',
    `created_by` INT NULL,
    `created_at` DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
    `updated_at` DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6) ON UPDATE CURRENT_TIMESTAMP(6),
    PRIMARY KEY (`id`),
    UNIQUE KEY `uk_staff_policy_group_member_start` (`group_id`, `staff_user_id`, `valid_from`),
    KEY `idx_staff_policy_group_member_effective` (`staff_user_id`, `valid_from`, `valid_to`, `status`, `group_id`),
    CONSTRAINT `fk_staff_policy_group_member_group` FOREIGN KEY (`group_id`) REFERENCES `staff_policy_groups` (`id`) ON DELETE RESTRICT,
    CONSTRAINT `chk_staff_policy_group_member_dates` CHECK (`valid_to` IS NULL OR `valid_to` >= `valid_from`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL);

    $createTable('staff_delegations', <<<'SQL'
CREATE TABLE `staff_delegations` (
    `id` BIGINT NOT NULL AUTO_INCREMENT,
    `delegator_user_id` INT NOT NULL,
    `delegate_user_id` INT NOT NULL,
    `scope_type` ENUM('global','org_unit','group','staff','request_type') NOT NULL,
    `scope_id` INT NOT NULL DEFAULT 0,
    `request_types` JSON NULL,
    `valid_from` DATETIME(6) NOT NULL,
    `valid_to` DATETIME(6) NOT NULL,
    `reason` VARCHAR(500) NOT NULL,
    `status` ENUM('draft','active','suspended','revoked','expired') NOT NULL DEFAULT 'draft',
    `created_by` INT NULL,
    `created_at` DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
    `updated_at` DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6) ON UPDATE CURRENT_TIMESTAMP(6),
    PRIMARY KEY (`id`),
    UNIQUE KEY `uk_staff_delegation_scope_start` (`delegator_user_id`, `delegate_user_id`, `scope_type`, `scope_id`, `valid_from`),
    KEY `idx_staff_delegation_delegate_effective` (`delegate_user_id`, `valid_from`, `valid_to`, `status`),
    KEY `idx_staff_delegation_delegator_effective` (`delegator_user_id`, `valid_from`, `valid_to`, `status`),
    CONSTRAINT `chk_staff_delegation_not_self` CHECK (`delegator_user_id` <> `delegate_user_id`),
    CONSTRAINT `chk_staff_delegation_dates` CHECK (`valid_to` > `valid_from`),
    CONSTRAINT `chk_staff_delegation_scope` CHECK ((`scope_type` = 'global' AND `scope_id` = 0) OR (`scope_type` <> 'global' AND `scope_id` > 0))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL);

    $createTable('staff_policy_definitions', <<<'SQL'
CREATE TABLE `staff_policy_definitions` (
    `id` INT NOT NULL AUTO_INCREMENT,
    `policy_type` VARCHAR(80) NOT NULL,
    `code` VARCHAR(80) NOT NULL,
    `name` VARCHAR(200) NOT NULL,
    `description` VARCHAR(1000) NULL,
    `status` ENUM('active','inactive','retired') NOT NULL DEFAULT 'active',
    `created_by` INT NULL,
    `created_at` DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
    `updated_at` DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6) ON UPDATE CURRENT_TIMESTAMP(6),
    PRIMARY KEY (`id`),
    UNIQUE KEY `uk_staff_policy_type_code` (`policy_type`, `code`),
    KEY `idx_staff_policy_definition_status` (`policy_type`, `status`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL);

    $createTable('staff_policy_versions', <<<'SQL'
CREATE TABLE `staff_policy_versions` (
    `id` BIGINT NOT NULL AUTO_INCREMENT,
    `policy_id` INT NOT NULL,
    `version_no` INT UNSIGNED NOT NULL,
    `state` ENUM('draft','published','retired') NOT NULL DEFAULT 'draft',
    `valid_from` DATETIME(6) NOT NULL,
    `valid_to` DATETIME(6) NULL,
    `timezone` VARCHAR(64) NOT NULL DEFAULT 'Africa/Cairo',
    `rules_json` JSON NOT NULL,
    `supersedes_id` BIGINT NULL,
    `published_by` INT NULL,
    `published_at` DATETIME(6) NULL,
    `created_by` INT NULL,
    `created_at` DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
    `updated_at` DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6) ON UPDATE CURRENT_TIMESTAMP(6),
    PRIMARY KEY (`id`),
    UNIQUE KEY `uk_staff_policy_version_no` (`policy_id`, `version_no`),
    UNIQUE KEY `uk_staff_policy_supersedes` (`supersedes_id`),
    KEY `idx_staff_policy_version_effective` (`policy_id`, `state`, `valid_from`, `valid_to`),
    CONSTRAINT `fk_staff_policy_version_policy` FOREIGN KEY (`policy_id`) REFERENCES `staff_policy_definitions` (`id`) ON DELETE RESTRICT,
    CONSTRAINT `fk_staff_policy_version_supersedes` FOREIGN KEY (`supersedes_id`) REFERENCES `staff_policy_versions` (`id`) ON DELETE RESTRICT,
    CONSTRAINT `chk_staff_policy_version_dates` CHECK (`valid_to` IS NULL OR `valid_to` > `valid_from`),
    CONSTRAINT `chk_staff_policy_publication` CHECK (`state` <> 'published' OR (`published_by` IS NOT NULL AND `published_at` IS NOT NULL))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL);

    $createTable('staff_policy_scopes', <<<'SQL'
CREATE TABLE `staff_policy_scopes` (
    `id` BIGINT NOT NULL AUTO_INCREMENT,
    `policy_version_id` BIGINT NOT NULL,
    `scope_type` ENUM('global','org_unit','job_title','group','staff') NOT NULL,
    `scope_id` INT NOT NULL DEFAULT 0,
    `priority` SMALLINT NOT NULL DEFAULT 0,
    `valid_from` DATETIME(6) NOT NULL,
    `valid_to` DATETIME(6) NULL,
    `status` ENUM('active','suspended','retired') NOT NULL DEFAULT 'active',
    `created_by` INT NULL,
    `created_at` DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
    PRIMARY KEY (`id`),
    UNIQUE KEY `uk_staff_policy_scope_start` (`policy_version_id`, `scope_type`, `scope_id`, `priority`, `valid_from`),
    KEY `idx_staff_policy_scope_resolution` (`scope_type`, `scope_id`, `valid_from`, `valid_to`, `status`, `priority`),
    CONSTRAINT `fk_staff_policy_scope_version` FOREIGN KEY (`policy_version_id`) REFERENCES `staff_policy_versions` (`id`) ON DELETE RESTRICT,
    CONSTRAINT `chk_staff_policy_scope_dates` CHECK (`valid_to` IS NULL OR `valid_to` > `valid_from`),
    CONSTRAINT `chk_staff_policy_scope_identity` CHECK ((`scope_type` = 'global' AND `scope_id` = 0) OR (`scope_type` <> 'global' AND `scope_id` > 0))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL);
};
