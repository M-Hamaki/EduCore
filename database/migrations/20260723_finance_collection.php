<?php

declare(strict_types=1);

/**
 * Finance collection: receipts, allocations, unapplied credits, adjustments, refunds.
 *
 * Tables created:
 *   - finance_receipts                   (reversal-only; per-cashbox/year numbering)
 *   - finance_payment_allocations       (targets finance_charge_installments; shared subledger_transaction_id)
 *   - finance_unapplied_credits         (independent overpayment movement)
 *   - finance_unapplied_credit_applications (partial application)
 *   - finance_adjustments
 *   - finance_refunds                   (refund_type distinguishes allocation vs unapplied-credit)
 *
 * Preconditions: finance_subledger_transactions + finance_charge_installments must exist.
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

    // 1. Receipts (reversal-only; numbering per cashbox/year)
    $createTable('finance_receipts', <<<'SQL'
CREATE TABLE `finance_receipts` (
    `id` INT NOT NULL AUTO_INCREMENT,
    `receipt_number` VARCHAR(100) NOT NULL,
    `cashbox_id` INT NOT NULL,
    `academic_year_id` INT NOT NULL,
    `sequence_number` INT NOT NULL,
    `student_account_id` INT NOT NULL,
    `payment_method` ENUM('cash','bank_transfer','check','card','other') NOT NULL DEFAULT 'cash',
    `gross_amount` DECIMAL(14,2) NOT NULL DEFAULT 0.00,
    `currency` VARCHAR(10) NOT NULL DEFAULT 'EGP',
    `idempotency_key` VARCHAR(100) NOT NULL,
    `status` ENUM('draft','posted','reversed') NOT NULL DEFAULT 'draft',
    `posted_at` DATETIME NULL,
    `posted_by` INT NULL,
    `reversed_at` DATETIME NULL,
    `reversed_by` INT NULL,
    `reversal_of` INT NULL,
    `approved_by` INT NULL,
    `notes` TEXT NULL,
    `subledger_transaction_id` INT NULL,
    `batch_id` CHAR(32) NULL,
    `request_id` CHAR(32) NULL,
    `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `created_by` INT NULL,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uk_receipt_number_seq` (`cashbox_id`, `academic_year_id`, `sequence_number`),
    UNIQUE KEY `uk_receipt_idempotency` (`idempotency_key`),
    KEY `idx_receipt_account` (`student_account_id`),
    UNIQUE KEY `uk_receipt_reversal` (`reversal_of`),
    KEY `idx_receipt_subledger_tx` (`subledger_transaction_id`),
    CONSTRAINT `fk_receipt_account` FOREIGN KEY (`student_account_id`) REFERENCES `finance_student_accounts` (`id`) ON DELETE RESTRICT,
    CONSTRAINT `fk_receipt_reversal` FOREIGN KEY (`reversal_of`) REFERENCES `finance_receipts` (`id`) ON DELETE RESTRICT,
    CONSTRAINT `fk_receipt_subledger_tx` FOREIGN KEY (`subledger_transaction_id`) REFERENCES `finance_subledger_transactions` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL);

    // 2. Payment allocations (targets finance_charge_installments; shared subledger_transaction_id with receipt)
    $createTable('finance_payment_allocations', <<<'SQL'
CREATE TABLE `finance_payment_allocations` (
    `id` INT NOT NULL AUTO_INCREMENT,
    `receipt_id` INT NOT NULL,
    `student_charge_installment_id` INT NOT NULL,
    `signed_amount` DECIMAL(14,2) NOT NULL,
    `status` ENUM('applied','reversed') NOT NULL DEFAULT 'applied',
    `reversal_of` INT NULL,
    `subledger_transaction_id` INT NULL,
    `batch_id` CHAR(32) NULL,
    `request_id` CHAR(32) NULL,
    `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    KEY `idx_alloc_receipt` (`receipt_id`),
    KEY `idx_alloc_installment` (`student_charge_installment_id`),
    KEY `idx_alloc_reversal` (`reversal_of`),
    UNIQUE KEY `uk_alloc_reversal` (`reversal_of`),
    CONSTRAINT `fk_alloc_receipt` FOREIGN KEY (`receipt_id`) REFERENCES `finance_receipts` (`id`) ON DELETE CASCADE,
    CONSTRAINT `fk_alloc_installment` FOREIGN KEY (`student_charge_installment_id`) REFERENCES `finance_charge_installments` (`id`) ON DELETE RESTRICT,
    CONSTRAINT `fk_alloc_reversal` FOREIGN KEY (`reversal_of`) REFERENCES `finance_payment_allocations` (`id`) ON DELETE RESTRICT,
    CONSTRAINT `fk_alloc_subledger_tx` FOREIGN KEY (`subledger_transaction_id`) REFERENCES `finance_subledger_transactions` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL);

    // 3. Unapplied credits (domain detail)
    $createTable('finance_unapplied_credits', <<<'SQL'
CREATE TABLE `finance_unapplied_credits` (
    `id` INT NOT NULL AUTO_INCREMENT,
    `student_account_id` INT NOT NULL,
    `receipt_id` INT NOT NULL,
    `signed_amount` DECIMAL(14,2) NOT NULL,
    `status` ENUM('open','applied','refunded','reversed') NOT NULL DEFAULT 'open',
    `applied_at` DATETIME NULL,
    `reversal_of` INT NULL,
    `subledger_transaction_id` INT NULL,
    `batch_id` CHAR(32) NULL,
    `request_id` CHAR(32) NULL,
    `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `created_by` INT NULL,
    PRIMARY KEY (`id`),
    KEY `idx_uc_account` (`student_account_id`),
    KEY `idx_uc_receipt` (`receipt_id`),
    UNIQUE KEY `uk_uc_reversal` (`reversal_of`),
    CONSTRAINT `fk_uc_account` FOREIGN KEY (`student_account_id`) REFERENCES `finance_student_accounts` (`id`) ON DELETE RESTRICT,
    CONSTRAINT `fk_uc_receipt` FOREIGN KEY (`receipt_id`) REFERENCES `finance_receipts` (`id`) ON DELETE RESTRICT,
    CONSTRAINT `fk_uc_reversal` FOREIGN KEY (`reversal_of`) REFERENCES `finance_unapplied_credits` (`id`) ON DELETE RESTRICT,
    CONSTRAINT `fk_uc_subledger_tx` FOREIGN KEY (`subledger_transaction_id`) REFERENCES `finance_subledger_transactions` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL);

    // 4. Unapplied credit applications (partial application; separate transaction)
    $createTable('finance_unapplied_credit_applications', <<<'SQL'
CREATE TABLE `finance_unapplied_credit_applications` (
    `id` INT NOT NULL AUTO_INCREMENT,
    `unapplied_credit_id` INT NOT NULL,
    `student_charge_installment_id` INT NOT NULL,
    `payment_allocation_id` INT NULL,
    `applied_amount` DECIMAL(14,2) NOT NULL DEFAULT 0.00,
    `status` ENUM('applied','reversed') NOT NULL DEFAULT 'applied',
    `reversal_of` INT NULL,
    `subledger_transaction_id` INT NULL,
    `batch_id` CHAR(32) NULL,
    `request_id` CHAR(32) NULL,
    `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `created_by` INT NULL,
    PRIMARY KEY (`id`),
    KEY `idx_uca_credit` (`unapplied_credit_id`),
    KEY `idx_uca_installment` (`student_charge_installment_id`),
    UNIQUE KEY `uk_uca_request` (`request_id`),
    UNIQUE KEY `uk_uca_reversal` (`reversal_of`),
    CONSTRAINT `fk_uca_credit` FOREIGN KEY (`unapplied_credit_id`) REFERENCES `finance_unapplied_credits` (`id`) ON DELETE CASCADE,
    CONSTRAINT `fk_uca_installment` FOREIGN KEY (`student_charge_installment_id`) REFERENCES `finance_charge_installments` (`id`) ON DELETE RESTRICT,
    CONSTRAINT `fk_uca_reversal` FOREIGN KEY (`reversal_of`) REFERENCES `finance_unapplied_credit_applications` (`id`) ON DELETE RESTRICT,
    CONSTRAINT `fk_uca_subledger_tx` FOREIGN KEY (`subledger_transaction_id`) REFERENCES `finance_subledger_transactions` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL);

    // 5. Adjustments (domain detail)
    $createTable('finance_adjustments', <<<'SQL'
CREATE TABLE `finance_adjustments` (
    `id` INT NOT NULL AUTO_INCREMENT,
    `student_account_id` INT NOT NULL,
    `adjustment_type` ENUM('credit','debit') NOT NULL DEFAULT 'credit',
    `signed_amount` DECIMAL(14,2) NOT NULL,
    `reason` VARCHAR(500) NULL,
    `source` ENUM('manual','student_debt_write_off','credit_note','debit_note','migration_reconciliation','prior_year') NOT NULL DEFAULT 'manual',
    `status` ENUM('pending','posted','reversed') NOT NULL DEFAULT 'pending',
    `posted_at` DATETIME NULL,
    `posted_by` INT NULL,
    `approved_by` INT NULL,
    `reversal_of` INT NULL,
    `subledger_transaction_id` INT NULL,
    `batch_id` CHAR(32) NULL,
    `request_id` CHAR(32) NULL,
    `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    KEY `idx_adj_account` (`student_account_id`),
    UNIQUE KEY `uk_adj_request` (`request_id`),
    UNIQUE KEY `uk_adj_reversal` (`reversal_of`),
    CONSTRAINT `fk_adj_account` FOREIGN KEY (`student_account_id`) REFERENCES `finance_student_accounts` (`id`) ON DELETE RESTRICT,
    CONSTRAINT `fk_adj_reversal` FOREIGN KEY (`reversal_of`) REFERENCES `finance_adjustments` (`id`) ON DELETE RESTRICT,
    CONSTRAINT `fk_adj_subledger_tx` FOREIGN KEY (`subledger_transaction_id`) REFERENCES `finance_subledger_transactions` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL);

    // 6. Refunds (refund_type distinguishes allocation vs unapplied-credit; separate transaction)
    $createTable('finance_refunds', <<<'SQL'
CREATE TABLE `finance_refunds` (
    `id` INT NOT NULL AUTO_INCREMENT,
    `receipt_id` INT NOT NULL,
    `refund_type` ENUM('refund_allocation','refund_unapplied_credit') NOT NULL,
    `payment_allocation_id` INT NULL,
    `unapplied_credit_id` INT NULL,
    `signed_amount` DECIMAL(14,2) NOT NULL,
    `payment_method` ENUM('cash','bank_transfer','check','card','other') NULL,
    `reason` VARCHAR(500) NULL,
    `status` ENUM('pending','posted','reversed') NOT NULL DEFAULT 'pending',
    `posted_at` DATETIME NULL,
    `posted_by` INT NULL,
    `approved_by` INT NULL,
    `reversal_of` INT NULL,
    `subledger_transaction_id` INT NULL,
    `batch_id` CHAR(32) NULL,
    `request_id` CHAR(32) NULL,
    `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    KEY `idx_refund_receipt` (`receipt_id`),
    KEY `idx_refund_type` (`refund_type`),
    UNIQUE KEY `uk_refund_request` (`request_id`),
    UNIQUE KEY `uk_refund_reversal` (`reversal_of`),
    CONSTRAINT `fk_refund_receipt` FOREIGN KEY (`receipt_id`) REFERENCES `finance_receipts` (`id`) ON DELETE RESTRICT,
    CONSTRAINT `fk_refund_alloc` FOREIGN KEY (`payment_allocation_id`) REFERENCES `finance_payment_allocations` (`id`) ON DELETE SET NULL,
    CONSTRAINT `fk_refund_uc` FOREIGN KEY (`unapplied_credit_id`) REFERENCES `finance_unapplied_credits` (`id`) ON DELETE SET NULL,
    CONSTRAINT `fk_refund_reversal` FOREIGN KEY (`reversal_of`) REFERENCES `finance_refunds` (`id`) ON DELETE RESTRICT,
    CONSTRAINT `fk_refund_subledger_tx` FOREIGN KEY (`subledger_transaction_id`) REFERENCES `finance_subledger_transactions` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL);
};
