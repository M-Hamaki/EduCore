<?php

declare(strict_types=1);

/**
 * Staff-HR assignment migration compatibility ledger.
 *
 * This migration intentionally creates no organization, title, assignment,
 * batch, or exception row.  It only provides an append-only record of a
 * later, reviewed backfill decision.  Keeping the legacy profile and job
 * movement tables unchanged lets readers remain on the legacy path until a
 * cutover reconciliation has been approved.
 *
 * The filename sorts before the organization and workflow foundations.  The
 * cross-migration identifiers are therefore validated scalar references rather
 * than reverse-order foreign keys:
 *   - assignment_id -> staff_assignments.id
 *   - migration_batch_id -> staff_hr_migration_batches.id
 *   - migration_exception_id -> staff_hr_migration_exceptions.id
 * A future coordinator must validate those targets in the same transaction
 * before inserting a link.  The self-reference is safe at this point and
 * preserves the append-only resolution chain (quarantine -> reviewed map).
 *
 * Rollback: leave the ledger and legacy source intact, disable new readers
 * through the rollout flag, and remove only this owned table/triggers in an
 * isolated rollback exercise.  Production rollback never deletes source data.
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

    if (!$tableExists('staff_assignment_legacy_links')) {
        $db->exec(<<<'SQL'
CREATE TABLE `staff_assignment_legacy_links` (
    `id` BIGINT NOT NULL AUTO_INCREMENT,
    `migration_batch_id` BIGINT NOT NULL COMMENT 'Validated scalar reference to staff_hr_migration_batches.id',
    `migration_exception_id` BIGINT NULL COMMENT 'Validated scalar reference to staff_hr_migration_exceptions.id for a quarantined source',
    `assignment_id` BIGINT NULL COMMENT 'Validated scalar reference to staff_assignments.id for a mapped source',
    `staff_user_id` INT NOT NULL,
    `legacy_source_type` VARCHAR(80) NOT NULL,
    `legacy_source_key` VARCHAR(190) NOT NULL,
    `source_payload_hash` CHAR(64) NOT NULL,
    `assignment_valid_from` DATE NOT NULL,
    `assignment_valid_to` DATE NULL,
    `resolution_status` ENUM('mapped','quarantined') NOT NULL,
    `resolution_reason_code` VARCHAR(80) NOT NULL,
    `supersedes_id` BIGINT NULL,
    `decision_idempotency_key` VARCHAR(190) NOT NULL,
    `created_by` INT NULL,
    `created_at` DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
    PRIMARY KEY (`id`),
    UNIQUE KEY `uk_staff_assignment_legacy_decision` (`decision_idempotency_key`),
    UNIQUE KEY `uk_staff_assignment_legacy_source_resolution` (
        `migration_batch_id`, `legacy_source_type`, `legacy_source_key`,
        `source_payload_hash`, `resolution_status`
    ),
    KEY `idx_staff_assignment_legacy_staff_effective` (
        `staff_user_id`, `assignment_valid_from`, `assignment_valid_to`, `resolution_status`
    ),
    KEY `idx_staff_assignment_legacy_source` (`legacy_source_type`, `legacy_source_key`, `created_at`),
    KEY `idx_staff_assignment_legacy_assignment` (`assignment_id`, `resolution_status`),
    KEY `idx_staff_assignment_legacy_exception` (`migration_exception_id`, `resolution_status`),
    KEY `idx_staff_assignment_legacy_supersedes` (`supersedes_id`),
    CONSTRAINT `fk_staff_assignment_legacy_supersedes`
        FOREIGN KEY (`supersedes_id`) REFERENCES `staff_assignment_legacy_links` (`id`) ON DELETE RESTRICT,
    CONSTRAINT `chk_staff_assignment_legacy_dates`
        CHECK (`assignment_valid_to` IS NULL OR `assignment_valid_to` >= `assignment_valid_from`),
    CONSTRAINT `chk_staff_assignment_legacy_resolution`
        CHECK (
            (`resolution_status` = 'mapped'
                AND `assignment_id` IS NOT NULL
                AND `migration_exception_id` IS NULL)
            OR
            (`resolution_status` = 'quarantined'
                AND `assignment_id` IS NULL
                AND `migration_exception_id` IS NOT NULL)
        )
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL);
    }

    if (!$triggerExists('trg_staff_assignment_legacy_link_no_update')) {
        $db->exec(<<<'SQL'
CREATE TRIGGER `trg_staff_assignment_legacy_link_no_update`
BEFORE UPDATE ON `staff_assignment_legacy_links`
FOR EACH ROW
BEGIN
    SIGNAL SQLSTATE '45000'
        SET MESSAGE_TEXT = 'Staff assignment legacy links are append-only; add a superseding resolution instead';
END
SQL);
    }

    if (!$triggerExists('trg_staff_assignment_legacy_link_no_delete')) {
        $db->exec(<<<'SQL'
CREATE TRIGGER `trg_staff_assignment_legacy_link_no_delete`
BEFORE DELETE ON `staff_assignment_legacy_links`
FOR EACH ROW
BEGIN
    SIGNAL SQLSTATE '45000'
        SET MESSAGE_TEXT = 'Staff assignment legacy links cannot be deleted';
END
SQL);
    }
};
