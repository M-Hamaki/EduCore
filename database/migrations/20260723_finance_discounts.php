<?php

declare(strict_types=1);

/**
 * Finance discounts: versioned, scope-aware rules + awards + applications.
 *
 * Tables created:
 *   - finance_discount_rules   (versioned: code + year + scope_charge_type_key + version_number; MariaDB-safe activation)
 *   - finance_discount_awards
 *   - finance_discount_applications
 *
 * Preconditions: finance_subledger_accounts must exist.
 * Rollback: DROP TABLE in reverse dependency order.
 */
return static function (PDO $db): void {
    $tableExists = static function (string $table) use ($db): bool {
        $stmt = $db->prepare(
            'SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?'
        );
        $stmt->execute([$table]);
        return (int) $stmt->fetchColumn() > 0;
    };

    $createTable = static function (string $table, string $ddl) use ($db, $tableExists): void {
        if (!$tableExists($table)) {
            $db->exec($ddl);
        }
    };

    // 1. Discount rules (versioned; scope-aware; MariaDB-safe activation via FOR UPDATE, no partial index)
    $createTable('finance_discount_rules', <<<'SQL'
CREATE TABLE `finance_discount_rules` (
    `id` INT NOT NULL AUTO_INCREMENT,
    `code` VARCHAR(50) NOT NULL,
    `name_ar` VARCHAR(200) NOT NULL,
    `priority` INT NOT NULL DEFAULT 0,
    `combinable` TINYINT(1) NOT NULL DEFAULT 0,
    `cap_amount` DECIMAL(14,2) NULL,
    `effective_from` DATE NULL,
    `effective_to` DATE NULL,
    `status` ENUM('draft','active','superseded','archived') NOT NULL DEFAULT 'draft',
    `academic_year_id` INT NOT NULL,
    `scope_charge_type_key` VARCHAR(50) NOT NULL DEFAULT 'ALL',
    `calculation_type` ENUM('manual_amount','fixed_amount','percentage','sibling_tiers') NOT NULL DEFAULT 'manual_amount',
    `calculation_value` DECIMAL(14,2) NULL,
    `parameters_json` JSON NULL,
    `version_number` INT NOT NULL DEFAULT 1,
    `activated_by` INT NULL,
    `activated_at` DATETIME NULL,
    `superseded_at` DATETIME NULL,
    `created_by` INT NULL,
    `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uk_discount_rule_scope_version` (`code`, `academic_year_id`, `scope_charge_type_key`, `version_number`),
    KEY `idx_discount_rule_active` (`code`, `academic_year_id`, `scope_charge_type_key`, `status`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL);

    // 2. Discount awards
    $createTable('finance_discount_awards', <<<'SQL'
CREATE TABLE `finance_discount_awards` (
    `id` INT NOT NULL AUTO_INCREMENT,
    `student_account_id` INT NOT NULL,
    `discount_rule_id` INT NOT NULL,
    `awarded_amount` DECIMAL(14,2) NOT NULL DEFAULT 0.00,
    `reason` VARCHAR(500) NULL,
    `requested_by` INT NULL,
    `approved_by` INT NULL,
    `approved_at` DATETIME NULL,
    `status` ENUM('pending','approved','rejected','revoked') NOT NULL DEFAULT 'pending',
    `document_ref` VARCHAR(200) NULL,
    `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    KEY `idx_award_account` (`student_account_id`),
    KEY `idx_award_rule` (`discount_rule_id`),
    KEY `idx_award_status` (`status`),
    CONSTRAINT `fk_award_account` FOREIGN KEY (`student_account_id`) REFERENCES `finance_student_accounts` (`id`) ON DELETE CASCADE,
    CONSTRAINT `fk_award_rule` FOREIGN KEY (`discount_rule_id`) REFERENCES `finance_discount_rules` (`id`) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL);

    // 3. Discount applications (links discount to charge/installment)
    $createTable('finance_discount_applications', <<<'SQL'
CREATE TABLE `finance_discount_applications` (
    `id` INT NOT NULL AUTO_INCREMENT,
    `discount_award_id` INT NOT NULL,
    `student_charge_id` INT NOT NULL,
    `student_charge_installment_id` INT NULL,
    `applied_amount` DECIMAL(14,2) NOT NULL DEFAULT 0.00,
    `ledger_effect_amount` DECIMAL(14,2) NOT NULL DEFAULT 0.00,
    `adjustment_id` INT NULL,
    `subledger_transaction_id` INT NULL,
    `request_id` CHAR(32) NULL,
    `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uk_disc_app_request` (`request_id`),
    KEY `idx_disc_app_award` (`discount_award_id`),
    KEY `idx_disc_app_charge` (`student_charge_id`),
    CONSTRAINT `fk_disc_app_award` FOREIGN KEY (`discount_award_id`) REFERENCES `finance_discount_awards` (`id`) ON DELETE CASCADE,
    CONSTRAINT `fk_disc_app_charge` FOREIGN KEY (`student_charge_id`) REFERENCES `finance_student_charges` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL);
};
