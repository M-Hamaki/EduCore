<?php

declare(strict_types=1);

/**
 * Finance staff compensation, payroll, advances (domain detail).
 *
 * Tables created:
 *   - staff_compensation_contracts
 *   - staff_compensation_contract_components  (PRIMARY source, not snapshot_json)
 *   - payroll_components
 *   - payroll_periods                         (unique by finance_period_id + start/end_date, NOT name)
 *   - payroll_runs                            (versions, settlements, reversal via reversal_of)
 *   - payroll_run_items
 *   - payroll_item_components
 *   - staff_advances                           (remaining_amount derived from subledger, not authoritative)
 *   - staff_advance_installments              (PRIMARY repayment schedule source)
 *   - staff_advance_movements                  (cash repayment/payroll deduction/write-off)
 *   - payroll_payments                         (reversal-only; posts STAFF_PAYROLL_PAYABLE)
 *
 * Preconditions: finance_subledger_transactions + finance_periods must exist.
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

    // 1. Compensation contracts
    $createTable('staff_compensation_contracts', <<<'SQL'
CREATE TABLE `staff_compensation_contracts` (
    `id` INT NOT NULL AUTO_INCREMENT,
    `staff_id` INT NOT NULL,
    `effective_from` DATE NOT NULL,
    `effective_to` DATE NULL,
    `version_number` INT NOT NULL DEFAULT 1,
    `status` ENUM('draft','active','superseded','expired') NOT NULL DEFAULT 'draft',
    `approved_by` INT NULL,
    `approved_at` DATETIME NULL,
    `provenance` ENUM('business_decision','legacy_migration','other') NOT NULL DEFAULT 'business_decision',
    `history_confidence` ENUM('confirmed','uncertain') NOT NULL DEFAULT 'confirmed',
    `created_by` INT NULL,
    `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uk_comp_contract_staff_eff_ver` (`staff_id`, `effective_from`, `version_number`),
    KEY `idx_comp_contract_staff` (`staff_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL);

    // 2. Contract components (PRIMARY source)
    $createTable('staff_compensation_contract_components', <<<'SQL'
CREATE TABLE `staff_compensation_contract_components` (
    `id` INT NOT NULL AUTO_INCREMENT,
    `contract_id` INT NOT NULL,
    `payroll_component_id` INT NOT NULL,
    `amount` DECIMAL(14,2) NOT NULL DEFAULT 0.00,
    `effective_from` DATE NULL,
    `effective_to` DATE NULL,
    `direction` ENUM('earning','deduction') NOT NULL DEFAULT 'earning',
    `status` ENUM('active','superseded') NOT NULL DEFAULT 'active',
    PRIMARY KEY (`id`),
    KEY `idx_ccc_contract` (`contract_id`),
    KEY `idx_ccc_component` (`payroll_component_id`),
    CONSTRAINT `fk_ccc_contract` FOREIGN KEY (`contract_id`) REFERENCES `staff_compensation_contracts` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL);

    // 3. Payroll components
    $createTable('payroll_components', <<<'SQL'
CREATE TABLE `payroll_components` (
    `id` INT NOT NULL AUTO_INCREMENT,
    `code` VARCHAR(50) NOT NULL,
    `name_ar` VARCHAR(200) NOT NULL,
    `direction` ENUM('earning','deduction') NOT NULL DEFAULT 'earning',
    `is_system` TINYINT(1) NOT NULL DEFAULT 0,
    `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uk_payroll_component_code` (`code`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL);

    // 4. Payroll periods (unique by finance_period_id + date range, NOT name)
    $createTable('payroll_periods', <<<'SQL'
CREATE TABLE `payroll_periods` (
    `id` INT NOT NULL AUTO_INCREMENT,
    `finance_period_id` INT NOT NULL,
    `start_date` DATE NOT NULL,
    `end_date` DATE NOT NULL,
    `pay_date` DATE NULL,
    `status` ENUM('open','closed') NOT NULL DEFAULT 'open',
    `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uk_payroll_period_range` (`finance_period_id`, `start_date`, `end_date`),
    CONSTRAINT `fk_payroll_period_finance` FOREIGN KEY (`finance_period_id`) REFERENCES `finance_periods` (`id`) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL);

    // 5. Payroll runs (versions, settlements, reversal via reversal_of)
    $createTable('payroll_runs', <<<'SQL'
CREATE TABLE `payroll_runs` (
    `id` INT NOT NULL AUTO_INCREMENT,
    `payroll_period_id` INT NOT NULL,
    `version_number` INT NOT NULL DEFAULT 1,
    `status` ENUM('draft','calculated','reviewed','approved','posted','paid','reversed') NOT NULL DEFAULT 'draft',
    `batch_id` CHAR(32) NULL,
    `created_by` INT NULL,
    `reviewed_by` INT NULL,
    `reviewed_at` DATETIME NULL,
    `posted_at` DATETIME NULL,
    `posted_by` INT NULL,
    `approved_by` INT NULL,
    `reversed_at` DATETIME NULL,
    `reversed_by` INT NULL,
    `reversal_of` INT NULL,
    `is_settlement` TINYINT(1) NOT NULL DEFAULT 0,
    `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uk_payroll_run_reversal` (`reversal_of`),
    UNIQUE KEY `uk_payroll_run_period_version` (`payroll_period_id`, `version_number`),
    KEY `idx_payroll_run_reversal` (`reversal_of`),
    CONSTRAINT `fk_payroll_run_period` FOREIGN KEY (`payroll_period_id`) REFERENCES `payroll_periods` (`id`) ON DELETE RESTRICT,
    CONSTRAINT `fk_payroll_run_reversal` FOREIGN KEY (`reversal_of`) REFERENCES `payroll_runs` (`id`) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL);

    // 6. Payroll run items (frozen copy; server-computed gross/net)
    $createTable('payroll_run_items', <<<'SQL'
CREATE TABLE `payroll_run_items` (
    `id` INT NOT NULL AUTO_INCREMENT,
    `payroll_run_id` INT NOT NULL,
    `staff_id` INT NOT NULL,
    `contract_snapshot_json` JSON NULL,
    `gross` DECIMAL(14,2) NOT NULL DEFAULT 0.00,
    `total_deductions` DECIMAL(14,2) NOT NULL DEFAULT 0.00,
    `net` DECIMAL(14,2) NOT NULL DEFAULT 0.00,
    `status` ENUM('draft','locked','paid','reversed') NOT NULL DEFAULT 'draft',
    `reversal_of` INT NULL,
    `payslip_ref_number` VARCHAR(100) NULL,
    `payment_status` ENUM('unpaid','paid') NOT NULL DEFAULT 'unpaid',
    `subledger_transaction_id` INT NULL,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uk_payroll_item_run_staff` (`payroll_run_id`, `staff_id`),
    KEY `idx_payroll_item_reversal` (`reversal_of`),
    CONSTRAINT `fk_payroll_item_run` FOREIGN KEY (`payroll_run_id`) REFERENCES `payroll_runs` (`id`) ON DELETE CASCADE,
    CONSTRAINT `fk_payroll_item_reversal` FOREIGN KEY (`reversal_of`) REFERENCES `payroll_run_items` (`id`) ON DELETE RESTRICT,
    CONSTRAINT `fk_payroll_item_subledger_tx` FOREIGN KEY (`subledger_transaction_id`) REFERENCES `finance_subledger_transactions` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL);

    // 7. Payroll item components (frozen per-staff component copy)
    $createTable('payroll_item_components', <<<'SQL'
CREATE TABLE `payroll_item_components` (
    `id` INT NOT NULL AUTO_INCREMENT,
    `payroll_run_item_id` INT NOT NULL,
    `payroll_component_id` INT NOT NULL,
    `amount` DECIMAL(14,2) NOT NULL DEFAULT 0.00,
    `direction` ENUM('earning','deduction') NOT NULL DEFAULT 'earning',
    PRIMARY KEY (`id`),
    KEY `idx_pic_item` (`payroll_run_item_id`),
    CONSTRAINT `fk_pic_item` FOREIGN KEY (`payroll_run_item_id`) REFERENCES `payroll_run_items` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL);

    // 8. Staff advances (remaining_amount derived from subledger)
    $createTable('staff_advances', <<<'SQL'
CREATE TABLE `staff_advances` (
    `id` INT NOT NULL AUTO_INCREMENT,
    `staff_id` INT NOT NULL,
    `amount` DECIMAL(14,2) NOT NULL DEFAULT 0.00,
    `issue_date` DATE NOT NULL,
    `reason` VARCHAR(500) NULL,
    `status` ENUM('active','repaid','written_off') NOT NULL DEFAULT 'active',
    `subledger_transaction_id` INT NULL,
    `request_id` CHAR(32) NOT NULL,
    `created_by` INT NULL,
    `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uk_staff_advance_request` (`request_id`),
    KEY `idx_advance_staff` (`staff_id`),
    CONSTRAINT `fk_advance_subledger_tx` FOREIGN KEY (`subledger_transaction_id`) REFERENCES `finance_subledger_transactions` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL);

    // 9. Staff advance installments (PRIMARY repayment schedule source)
    $createTable('staff_advance_installments', <<<'SQL'
CREATE TABLE `staff_advance_installments` (
    `id` INT NOT NULL AUTO_INCREMENT,
    `staff_advance_id` INT NOT NULL,
    `due_date` DATE NOT NULL,
    `amount` DECIMAL(14,2) NOT NULL DEFAULT 0.00,
    `status` ENUM('pending','paid','overdue') NOT NULL DEFAULT 'pending',
    PRIMARY KEY (`id`),
    KEY `idx_advance_installment_advance` (`staff_advance_id`),
    CONSTRAINT `fk_advance_installment_advance` FOREIGN KEY (`staff_advance_id`) REFERENCES `staff_advances` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL);

    // 10. Staff advance movements (repayment/deduction/write-off are distinct operations)
    $createTable('staff_advance_movements', <<<'SQL'
CREATE TABLE `staff_advance_movements` (
    `id` INT NOT NULL AUTO_INCREMENT,
    `staff_advance_id` INT NOT NULL,
    `movement_type` ENUM('cash_repayment','payroll_deduction','write_off') NOT NULL,
    `amount` DECIMAL(14,2) NOT NULL,
    `cashbox_id` INT NULL,
    `payroll_run_item_id` INT NULL,
    `reason` VARCHAR(500) NULL,
    `status` ENUM('pending','posted','reversed') NOT NULL DEFAULT 'pending',
    `approved_by` INT NULL,
    `approved_at` DATETIME NULL,
    `subledger_transaction_id` INT NULL,
    `reversal_of` INT NULL,
    `batch_id` CHAR(32) NULL,
    `request_id` CHAR(32) NOT NULL,
    `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `created_by` INT NULL,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uk_advance_movement_request` (`request_id`),
    KEY `idx_advance_movement_advance` (`staff_advance_id`),
    UNIQUE KEY `uk_advance_movement_reversal` (`reversal_of`),
    CONSTRAINT `fk_advance_movement_advance` FOREIGN KEY (`staff_advance_id`) REFERENCES `staff_advances` (`id`) ON DELETE RESTRICT,
    CONSTRAINT `fk_advance_movement_cashbox` FOREIGN KEY (`cashbox_id`) REFERENCES `finance_cashboxes` (`id`) ON DELETE RESTRICT,
    CONSTRAINT `fk_advance_movement_payroll_item` FOREIGN KEY (`payroll_run_item_id`) REFERENCES `payroll_run_items` (`id`) ON DELETE RESTRICT,
    CONSTRAINT `fk_advance_movement_subledger_tx` FOREIGN KEY (`subledger_transaction_id`) REFERENCES `finance_subledger_transactions` (`id`) ON DELETE RESTRICT,
    CONSTRAINT `fk_advance_movement_reversal` FOREIGN KEY (`reversal_of`) REFERENCES `staff_advance_movements` (`id`) ON DELETE RESTRICT,
    CONSTRAINT `chk_advance_movement_amount` CHECK (`amount` > 0),
    CONSTRAINT `chk_advance_movement_source` CHECK (
        (`movement_type` = 'cash_repayment' AND `cashbox_id` IS NOT NULL AND `payroll_run_item_id` IS NULL)
        OR (`movement_type` = 'payroll_deduction' AND `cashbox_id` IS NULL AND `payroll_run_item_id` IS NOT NULL)
        OR (`movement_type` = 'write_off' AND `cashbox_id` IS NULL AND `payroll_run_item_id` IS NULL AND `approved_by` IS NOT NULL)
    )
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL);

    // 11. Payroll payments (reversal-only; posts STAFF_PAYROLL_PAYABLE -amount)
    $createTable('payroll_payments', <<<'SQL'
CREATE TABLE `payroll_payments` (
    `id` INT NOT NULL AUTO_INCREMENT,
    `payroll_run_item_id` INT NOT NULL,
    `cashbox_id` INT NOT NULL,
    `amount` DECIMAL(14,2) NOT NULL DEFAULT 0.00,
    `payment_method` ENUM('cash','bank_transfer','check','card','other') NOT NULL DEFAULT 'cash',
    `status` ENUM('posted','reversed') NOT NULL DEFAULT 'posted',
    `posted_at` DATETIME NULL,
    `posted_by` INT NULL,
    `approved_by` INT NULL,
    `reversal_of` INT NULL,
    `subledger_transaction_id` INT NULL,
    `batch_id` CHAR(32) NULL,
    `request_id` CHAR(32) NULL,
    `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uk_payroll_payment_request` (`request_id`),
    KEY `idx_paypay_item` (`payroll_run_item_id`),
    UNIQUE KEY `uk_paypay_reversal` (`reversal_of`),
    CONSTRAINT `fk_paypay_item` FOREIGN KEY (`payroll_run_item_id`) REFERENCES `payroll_run_items` (`id`) ON DELETE RESTRICT,
    CONSTRAINT `fk_paypay_reversal` FOREIGN KEY (`reversal_of`) REFERENCES `payroll_payments` (`id`) ON DELETE RESTRICT,
    CONSTRAINT `fk_paypay_subledger_tx` FOREIGN KEY (`subledger_transaction_id`) REFERENCES `finance_subledger_transactions` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL);

    // Seed payroll components
    $seedComponents = [
        ['basic', 'الأساسي', 'earning', 1],
        ['allowance_transport', 'بدل انتقال', 'earning', 1],
        ['allowance_housing', 'بدل سكن', 'earning', 1],
        ['allowance_fixed', 'بدل ثابت', 'earning', 1],
        ['allowance_variable', 'بدل متغير', 'earning', 1],
        ['bonus', 'حافز', 'earning', 1],
        ['overtime', 'إضافي', 'earning', 1],
        ['insurance', 'تأمينات', 'deduction', 1],
        ['tax', 'ضرائب', 'deduction', 1],
        ['attendance_deduction', 'خصم غياب', 'deduction', 1],
        ['penalty', 'جزاءات', 'deduction', 1],
        ['advance', 'سلفة', 'deduction', 1],
        ['other_deduction', 'خصومات أخرى', 'deduction', 1],
    ];
    $checkComp = $db->prepare('SELECT COUNT(*) FROM payroll_components WHERE code = ?');
    $insertComp = $db->prepare('INSERT INTO payroll_components (code, name_ar, direction, is_system) VALUES (?, ?, ?, ?)');
    foreach ($seedComponents as [$code, $nameAr, $direction, $isSystem]) {
        $checkComp->execute([$code]);
        if ((int) $checkComp->fetchColumn() === 0) {
            $insertComp->execute([$code, $nameAr, $direction, $isSystem]);
        }
    }
};
