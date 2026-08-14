<?php

declare(strict_types=1);

/**
 * Finance fee plans, student accounts, contracts, charges, installments (domain detail).
 *
 * Tables created:
 *   - finance_fee_plans
 *   - finance_fee_plan_versions
 *   - finance_fee_plan_installments
 *   - finance_student_accounts
 *   - finance_student_contracts
 *   - finance_student_charges
 *   - finance_charge_installments
 *
 * Preconditions: finance_charge_types + finance_subledger_accounts must exist (20260723_finance_core_and_subledger.php).
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

    // 1. Fee plans
    $createTable('finance_fee_plans', <<<'SQL'
CREATE TABLE `finance_fee_plans` (
    `id` INT NOT NULL AUTO_INCREMENT,
    `charge_type_id` INT NOT NULL,
    `academic_year_id` INT NOT NULL,
    `stage_id` INT NULL,
    `grade_id` INT NULL,
    `name` VARCHAR(200) NOT NULL,
    `status` ENUM('draft','active','archived') NOT NULL DEFAULT 'draft',
    `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `created_by` INT NULL,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uk_fee_plan_type_year_grade` (`charge_type_id`, `academic_year_id`, `grade_id`),
    KEY `idx_fee_plan_year` (`academic_year_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL);

    // 2. Fee plan versions (immutable after use)
    $createTable('finance_fee_plan_versions', <<<'SQL'
CREATE TABLE `finance_fee_plan_versions` (
    `id` INT NOT NULL AUTO_INCREMENT,
    `fee_plan_id` INT NOT NULL,
    `version_number` INT NOT NULL,
    `snapshot_json` JSON NULL,
    `effective_from` DATE NULL,
    `effective_to` DATE NULL,
    `status` ENUM('draft','active','superseded') NOT NULL DEFAULT 'draft',
    `superseded_at` DATETIME NULL,
    `created_by` INT NULL,
    `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uk_fee_plan_version` (`fee_plan_id`, `version_number`),
    CONSTRAINT `fk_fee_plan_version_plan` FOREIGN KEY (`fee_plan_id`) REFERENCES `finance_fee_plans` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL);

    // 3. Fee plan installments
    $createTable('finance_fee_plan_installments', <<<'SQL'
CREATE TABLE `finance_fee_plan_installments` (
    `id` INT NOT NULL AUTO_INCREMENT,
    `fee_plan_version_id` INT NOT NULL,
    `installment_name` VARCHAR(100) NOT NULL,
    `gross_amount` DECIMAL(14,2) NOT NULL DEFAULT 0.00,
    `due_date` DATE NULL,
    `display_order` INT NOT NULL DEFAULT 1,
    PRIMARY KEY (`id`),
    KEY `idx_fee_plan_installment_version` (`fee_plan_version_id`),
    CONSTRAINT `fk_fee_plan_installment_version` FOREIGN KEY (`fee_plan_version_id`) REFERENCES `finance_fee_plan_versions` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL);

    // 4. Student finance accounts (NO balance column; linked to subledger)
    $createTable('finance_student_accounts', <<<'SQL'
CREATE TABLE `finance_student_accounts` (
    `id` INT NOT NULL AUTO_INCREMENT,
    `student_id` INT NOT NULL,
    `academic_year_id` INT NOT NULL,
    `currency` VARCHAR(10) NOT NULL DEFAULT 'EGP',
    `status` ENUM('active','closed') NOT NULL DEFAULT 'active',
    `subledger_account_id` INT NULL,
    `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uk_student_account_year` (`student_id`, `academic_year_id`),
    KEY `idx_student_account_subledger` (`subledger_account_id`),
    CONSTRAINT `fk_student_account_subledger` FOREIGN KEY (`subledger_account_id`) REFERENCES `finance_subledger_accounts` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL);

    // 5. Student contracts (snapshot of plan version at assignment)
    $createTable('finance_student_contracts', <<<'SQL'
CREATE TABLE `finance_student_contracts` (
    `id` INT NOT NULL AUTO_INCREMENT,
    `student_account_id` INT NOT NULL,
    `fee_plan_version_id` INT NOT NULL,
    `snapshot_json` JSON NULL,
    `signed_at` DATETIME NULL,
    `status` ENUM('draft','active','closed') NOT NULL DEFAULT 'draft',
    `created_by` INT NULL,
    `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uk_student_contract_version` (`student_account_id`, `fee_plan_version_id`),
    CONSTRAINT `fk_student_contract_account` FOREIGN KEY (`student_account_id`) REFERENCES `finance_student_accounts` (`id`) ON DELETE CASCADE,
    CONSTRAINT `fk_student_contract_version` FOREIGN KEY (`fee_plan_version_id`) REFERENCES `finance_fee_plan_versions` (`id`) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL);

    // 6. Student charges (domain detail; subledger is the truth source)
    $createTable('finance_student_charges', <<<'SQL'
CREATE TABLE `finance_student_charges` (
    `id` INT NOT NULL AUTO_INCREMENT,
    `student_account_id` INT NOT NULL,
    `student_contract_id` INT NULL,
    `charge_type_id` INT NOT NULL,
    `direction` ENUM('debit','credit') NOT NULL DEFAULT 'debit',
    `gross_amount` DECIMAL(14,2) NOT NULL DEFAULT 0.00,
    `discount_amount` DECIMAL(14,2) NOT NULL DEFAULT 0.00,
    `adjustment_amount` DECIMAL(14,2) NOT NULL DEFAULT 0.00,
    `net_due` DECIMAL(14,2) NOT NULL DEFAULT 0.00,
    `due_date` DATE NULL,
    `source` ENUM('plan','manual','import','opening_balance','prior_year') NOT NULL DEFAULT 'plan',
    `academic_year_id` INT NOT NULL,
    `status` ENUM('pending','posted') NOT NULL DEFAULT 'pending',
    `posted_at` DATETIME NULL,
    `posted_by` INT NULL,
    `reversal_of` INT NULL,
    `subledger_transaction_id` INT NULL,
    `batch_id` CHAR(32) NULL,
    `request_id` CHAR(32) NULL,
    `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `created_by` INT NULL,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uk_charge_request_account` (`request_id`, `student_account_id`),
    KEY `idx_charge_account` (`student_account_id`),
    KEY `idx_charge_contract` (`student_contract_id`),
    KEY `idx_charge_type` (`charge_type_id`),
    KEY `idx_charge_reversal` (`reversal_of`),
    CONSTRAINT `fk_charge_account` FOREIGN KEY (`student_account_id`) REFERENCES `finance_student_accounts` (`id`) ON DELETE CASCADE,
    CONSTRAINT `fk_charge_contract` FOREIGN KEY (`student_contract_id`) REFERENCES `finance_student_contracts` (`id`) ON DELETE SET NULL,
    CONSTRAINT `fk_charge_reversal` FOREIGN KEY (`reversal_of`) REFERENCES `finance_student_charges` (`id`) ON DELETE RESTRICT,
    CONSTRAINT `fk_charge_subledger_tx` FOREIGN KEY (`subledger_transaction_id`) REFERENCES `finance_subledger_transactions` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL);

    // 7. Charge installments (the due-unit for allocation)
    $createTable('finance_charge_installments', <<<'SQL'
CREATE TABLE `finance_charge_installments` (
    `id` INT NOT NULL AUTO_INCREMENT,
    `student_charge_id` INT NOT NULL,
    `installment_name` VARCHAR(100) NOT NULL,
    `net_amount` DECIMAL(14,2) NOT NULL DEFAULT 0.00,
    `due_date` DATE NULL,
    `display_order` INT NOT NULL DEFAULT 1,
    `status` ENUM('pending','partially_paid','paid') NOT NULL DEFAULT 'pending',
    PRIMARY KEY (`id`),
    KEY `idx_installment_charge` (`student_charge_id`),
    CONSTRAINT `fk_installment_charge` FOREIGN KEY (`student_charge_id`) REFERENCES `finance_student_charges` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL);
};
