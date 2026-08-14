<?php

declare(strict_types=1);

/**
 * Finance GL (v1), vouchers, budget model.
 *
 * Tables created:
 *   - accounting_accounts
 *   - accounting_cost_centers
 *   - accounting_journal_entries        (reversal-only; 1:1 with subledger via source_idempotency_key)
 *   - accounting_journal_lines
 *   - accounting_account_mapping_headers (versioned)
 *   - accounting_account_mapping_lines   (deterministic resolution: specificity→priority→version)
 *   - accounting_control_accounts
 *   - finance_vouchers                   (expense/other_income/cash_transfer; AP out of scope v1)
 *   - finance_voucher_lines
 *   - finance_budgets
 *   - finance_budget_versions
 *   - finance_budget_lines               (NO actual_amount column; from GL view/cache)
 *
 * Preconditions: finance_periods + finance_subledger_transactions must exist.
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

    // === GL ===

    // 1. Chart of accounts
    $createTable('accounting_accounts', <<<'SQL'
CREATE TABLE `accounting_accounts` (
    `id` INT NOT NULL AUTO_INCREMENT,
    `code` VARCHAR(50) NOT NULL,
    `name_ar` VARCHAR(200) NOT NULL,
    `type` ENUM('asset','liability','equity','revenue','expense') NOT NULL,
    `parent_id` INT NULL,
    `is_active` TINYINT(1) NOT NULL DEFAULT 1,
    `is_control_account` TINYINT(1) NOT NULL DEFAULT 0,
    `created_by` INT NULL,
    `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uk_account_code` (`code`),
    KEY `idx_account_parent` (`parent_id`),
    KEY `idx_account_type` (`type`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL);

    // 2. Cost centers
    $createTable('accounting_cost_centers', <<<'SQL'
CREATE TABLE `accounting_cost_centers` (
    `id` INT NOT NULL AUTO_INCREMENT,
    `code` VARCHAR(50) NOT NULL,
    `name_ar` VARCHAR(200) NOT NULL,
    `scope` ENUM('stage','grade','bus','activity','department') NOT NULL,
    `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uk_cost_center_code` (`code`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL);

    // 3. Journal entries (reversal-only; 1:1 with subledger via source_idempotency_key)
    $createTable('accounting_journal_entries', <<<'SQL'
CREATE TABLE `accounting_journal_entries` (
    `id` INT NOT NULL AUTO_INCREMENT,
    `entry_number` VARCHAR(100) NOT NULL,
    `finance_period_id` INT NULL,
    `entry_date` DATE NOT NULL,
    `source_type` VARCHAR(50) NOT NULL,
    `source_ref_id` INT NULL,
    `source_idempotency_key` CHAR(32) NOT NULL,
    `subledger_transaction_id` INT NULL,
    `status` ENUM('draft','posted') NOT NULL DEFAULT 'draft',
    `batch_id` CHAR(32) NULL,
    `posted_at` DATETIME NULL,
    `posted_by` INT NULL,
    `approved_by` INT NULL,
    `reversal_of` INT NULL,
    `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uk_journal_idempotency` (`source_idempotency_key`),
    UNIQUE KEY `uk_journal_subledger_tx` (`subledger_transaction_id`),
    UNIQUE KEY `uk_journal_reversal` (`reversal_of`),
    KEY `idx_journal_period` (`finance_period_id`),
    KEY `idx_journal_source` (`source_type`, `source_ref_id`),
    KEY `idx_journal_reversal` (`reversal_of`),
    CONSTRAINT `fk_journal_period` FOREIGN KEY (`finance_period_id`) REFERENCES `finance_periods` (`id`) ON DELETE SET NULL,
    CONSTRAINT `fk_journal_subledger_tx` FOREIGN KEY (`subledger_transaction_id`) REFERENCES `finance_subledger_transactions` (`id`) ON DELETE RESTRICT,
    CONSTRAINT `fk_journal_reversal` FOREIGN KEY (`reversal_of`) REFERENCES `accounting_journal_entries` (`id`) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL);

    // 4. Journal lines
    $createTable('accounting_journal_lines', <<<'SQL'
CREATE TABLE `accounting_journal_lines` (
    `id` INT NOT NULL AUTO_INCREMENT,
    `journal_entry_id` INT NOT NULL,
    `account_id` INT NOT NULL,
    `cost_center_id` INT NULL,
    `debit` DECIMAL(14,2) NOT NULL DEFAULT 0.00,
    `credit` DECIMAL(14,2) NOT NULL DEFAULT 0.00,
    `description` VARCHAR(500) NULL,
    `sub_ledger_ref_type` ENUM('student','staff','cashbox','voucher') NULL,
    `sub_ledger_ref_id` INT NULL,
    PRIMARY KEY (`id`),
    KEY `idx_jline_entry` (`journal_entry_id`),
    KEY `idx_jline_account` (`account_id`),
    CONSTRAINT `fk_jline_entry` FOREIGN KEY (`journal_entry_id`) REFERENCES `accounting_journal_entries` (`id`) ON DELETE CASCADE,
    CONSTRAINT `fk_jline_account` FOREIGN KEY (`account_id`) REFERENCES `accounting_accounts` (`id`) ON DELETE RESTRICT,
    CONSTRAINT `fk_jline_cost_center` FOREIGN KEY (`cost_center_id`) REFERENCES `accounting_cost_centers` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL);

    // 5. Account mapping headers (versioned)
    $createTable('accounting_account_mapping_headers', <<<'SQL'
CREATE TABLE `accounting_account_mapping_headers` (
    `id` INT NOT NULL AUTO_INCREMENT,
    `version_number` INT NOT NULL,
    `effective_from` DATE NULL,
    `effective_to` DATE NULL,
    `status` ENUM('draft','active','superseded') NOT NULL DEFAULT 'draft',
    `superseded_at` DATETIME NULL,
    `created_by` INT NULL,
    `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uk_mapping_header_version` (`version_number`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL);

    // 6. Account mapping lines (deterministic resolution)
    $createTable('accounting_account_mapping_lines', <<<'SQL'
CREATE TABLE `accounting_account_mapping_lines` (
    `id` INT NOT NULL AUTO_INCREMENT,
    `mapping_header_id` INT NOT NULL,
    `operation_type` VARCHAR(50) NOT NULL,
    `selector_charge_type_id` INT NULL,
    `selector_payroll_component_id` INT NULL,
    `selector_payment_method` VARCHAR(20) NULL,
    `selector_cashbox_id` INT NULL,
    `selector_voucher_type` VARCHAR(20) NULL,
    `debit_account_id` INT NOT NULL,
    `credit_account_id` INT NOT NULL,
    `cost_center_scope` ENUM('stage','grade','bus','activity','department','none') NOT NULL DEFAULT 'none',
    `specificity_score` INT NOT NULL DEFAULT 0,
    `priority` INT NOT NULL DEFAULT 0,
    PRIMARY KEY (`id`),
    KEY `idx_mline_header` (`mapping_header_id`),
    KEY `idx_mline_op` (`operation_type`),
    CONSTRAINT `fk_mline_header` FOREIGN KEY (`mapping_header_id`) REFERENCES `accounting_account_mapping_headers` (`id`) ON DELETE CASCADE,
    CONSTRAINT `fk_mline_debit` FOREIGN KEY (`debit_account_id`) REFERENCES `accounting_accounts` (`id`) ON DELETE RESTRICT,
    CONSTRAINT `fk_mline_credit` FOREIGN KEY (`credit_account_id`) REFERENCES `accounting_accounts` (`id`) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL);

    // 7. Control accounts
    $createTable('accounting_control_accounts', <<<'SQL'
CREATE TABLE `accounting_control_accounts` (
    `id` INT NOT NULL AUTO_INCREMENT,
    `account_id` INT NOT NULL,
    `sub_ledger_type` ENUM('student','staff','cashbox','voucher') NOT NULL,
    `normal_balance` ENUM('debit','credit') NOT NULL DEFAULT 'debit',
    `reconciliation_tolerance` DECIMAL(14,2) NOT NULL DEFAULT 0.00,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uk_control_account` (`account_id`),
    CONSTRAINT `fk_control_account` FOREIGN KEY (`account_id`) REFERENCES `accounting_accounts` (`id`) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL);

    // === Vouchers ===

    // 8. Vouchers (expense/other_income/cash_transfer; AP out of scope v1)
    $createTable('finance_vouchers', <<<'SQL'
CREATE TABLE `finance_vouchers` (
    `id` INT NOT NULL AUTO_INCREMENT,
    `voucher_number` VARCHAR(100) NOT NULL,
    `voucher_type` ENUM('expense','other_income','cash_transfer') NOT NULL,
    `cashbox_id` INT NULL,
    `source_cashbox_id` INT NULL,
    `destination_cashbox_id` INT NULL,
    `bank_account_id` INT NULL,
    `amount` DECIMAL(14,2) NOT NULL DEFAULT 0.00,
    `finance_period_id` INT NULL,
    `entry_date` DATE NOT NULL,
    `cost_center_id` INT NULL,
    `status` ENUM('draft','posted','reversed') NOT NULL DEFAULT 'draft',
    `posted_at` DATETIME NULL,
    `posted_by` INT NULL,
    `approved_by` INT NULL,
    `reversal_of` INT NULL,
    `batch_id` CHAR(32) NULL,
    `request_id` CHAR(32) NULL,
    `notes` TEXT NULL,
    `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `created_by` INT NULL,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uk_voucher_request` (`request_id`),
    UNIQUE KEY `uk_voucher_number` (`voucher_number`),
    KEY `idx_voucher_type` (`voucher_type`),
    UNIQUE KEY `uk_voucher_reversal` (`reversal_of`),
    CONSTRAINT `fk_voucher_cashbox` FOREIGN KEY (`cashbox_id`) REFERENCES `finance_cashboxes` (`id`) ON DELETE RESTRICT,
    CONSTRAINT `fk_voucher_source_cashbox` FOREIGN KEY (`source_cashbox_id`) REFERENCES `finance_cashboxes` (`id`) ON DELETE RESTRICT,
    CONSTRAINT `fk_voucher_destination_cashbox` FOREIGN KEY (`destination_cashbox_id`) REFERENCES `finance_cashboxes` (`id`) ON DELETE RESTRICT,
    CONSTRAINT `fk_voucher_reversal` FOREIGN KEY (`reversal_of`) REFERENCES `finance_vouchers` (`id`) ON DELETE RESTRICT,
    CONSTRAINT `chk_voucher_amount_positive` CHECK (`amount` > 0),
    CONSTRAINT `chk_voucher_endpoints` CHECK (
        (`voucher_type` IN ('expense','other_income') AND `cashbox_id` IS NOT NULL AND `source_cashbox_id` IS NULL AND `destination_cashbox_id` IS NULL)
        OR (`voucher_type` = 'cash_transfer' AND `cashbox_id` IS NULL AND `source_cashbox_id` IS NOT NULL AND `destination_cashbox_id` IS NOT NULL AND `source_cashbox_id` <> `destination_cashbox_id`)
    )
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL);

    // 9. Voucher lines
    $createTable('finance_voucher_lines', <<<'SQL'
CREATE TABLE `finance_voucher_lines` (
    `id` INT NOT NULL AUTO_INCREMENT,
    `voucher_id` INT NOT NULL,
    `account_id` INT NOT NULL,
    `cost_center_id` INT NULL,
    `debit` DECIMAL(14,2) NOT NULL DEFAULT 0.00,
    `credit` DECIMAL(14,2) NOT NULL DEFAULT 0.00,
    `description` VARCHAR(500) NULL,
    PRIMARY KEY (`id`),
    KEY `idx_vline_voucher` (`voucher_id`),
    CONSTRAINT `fk_vline_voucher` FOREIGN KEY (`voucher_id`) REFERENCES `finance_vouchers` (`id`) ON DELETE CASCADE,
    CONSTRAINT `fk_vline_account` FOREIGN KEY (`account_id`) REFERENCES `accounting_accounts` (`id`) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL);

    // === Budget ===

    // 10. Budgets
    $createTable('finance_budgets', <<<'SQL'
CREATE TABLE `finance_budgets` (
    `id` INT NOT NULL AUTO_INCREMENT,
    `academic_year_id` INT NOT NULL,
    `name` VARCHAR(200) NOT NULL,
    `status` ENUM('draft','reviewed','approved','locked','revised') NOT NULL DEFAULT 'draft',
    `approved_by` INT NULL,
    `approved_at` DATETIME NULL,
    `created_by` INT NULL,
    `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uk_budget_year_name` (`academic_year_id`, `name`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL);

    // 11. Budget versions
    $createTable('finance_budget_versions', <<<'SQL'
CREATE TABLE `finance_budget_versions` (
    `id` INT NOT NULL AUTO_INCREMENT,
    `budget_id` INT NOT NULL,
    `version_number` INT NOT NULL,
    `status` ENUM('draft','active','superseded') NOT NULL DEFAULT 'draft',
    `superseded_at` DATETIME NULL,
    `created_by` INT NULL,
    `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uk_budget_version` (`budget_id`, `version_number`),
    CONSTRAINT `fk_budget_version_budget` FOREIGN KEY (`budget_id`) REFERENCES `finance_budgets` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL);

    // 12. Budget lines (NO actual_amount column; from GL view/cache)
    $createTable('finance_budget_lines', <<<'SQL'
CREATE TABLE `finance_budget_lines` (
    `id` INT NOT NULL AUTO_INCREMENT,
    `budget_version_id` INT NOT NULL,
    `account_id` INT NOT NULL,
    `cost_center_id` INT NULL,
    `period_id` INT NULL,
    `planned_amount` DECIMAL(14,2) NOT NULL DEFAULT 0.00,
    PRIMARY KEY (`id`),
    KEY `idx_bline_version` (`budget_version_id`),
    CONSTRAINT `fk_bline_version` FOREIGN KEY (`budget_version_id`) REFERENCES `finance_budget_versions` (`id`) ON DELETE CASCADE,
    CONSTRAINT `fk_bline_account` FOREIGN KEY (`account_id`) REFERENCES `accounting_accounts` (`id`) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL);
};
