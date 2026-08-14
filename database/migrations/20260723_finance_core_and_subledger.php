<?php

declare(strict_types=1);

/**
 * Finance foundation: unified sub-ledger truth source (students + staff) + core shared tables.
 *
 * Tables created:
 *   - finance_charge_types
 *   - finance_periods
 *   - finance_cashboxes
 *   - finance_bank_accounts
 *   - finance_cashbox_settlements
 *   - finance_receipt_number_sequences
 *   - finance_import_batches
 *   - finance_import_rows
 *   - finance_subledger_accounts        (unified truth source header)
 *   - finance_subledger_transactions     (one per source operation; append-only)
 *   - finance_subledger_lines           (signed deltas per bucket; FK RESTRICT)
 *
 * Preconditions: academic_years table must exist (provided by 20260625_academic_years.php).
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

    // 1. Charge types
    $createTable('finance_charge_types', <<<'SQL'
CREATE TABLE `finance_charge_types` (
    `id` INT NOT NULL AUTO_INCREMENT,
    `code` VARCHAR(50) NOT NULL,
    `name_ar` VARCHAR(200) NOT NULL,
    `is_system` TINYINT(1) NOT NULL DEFAULT 0,
    `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uk_charge_type_code` (`code`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL);

    // 2. Finance periods (extends academic_years)
    $createTable('finance_periods', <<<'SQL'
CREATE TABLE `finance_periods` (
    `id` INT NOT NULL AUTO_INCREMENT,
    `academic_year_id` INT NOT NULL,
    `name` VARCHAR(100) NOT NULL,
    `start_date` DATE NULL,
    `end_date` DATE NULL,
    `status` ENUM('open','closed','reopened') NOT NULL DEFAULT 'open',
    `closed_at` DATETIME NULL,
    `closed_by` INT NULL,
    `closed_approved_by` INT NULL,
    `reopen_reason` VARCHAR(500) NULL,
    `reopened_by` INT NULL,
    `reopen_approved_by` INT NULL,
    `reopened_at` DATETIME NULL,
    `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uk_finance_period_year_name` (`academic_year_id`, `name`),
    KEY `idx_finance_period_year` (`academic_year_id`),
    KEY `idx_finance_period_status` (`status`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL);

    // 3. Cashboxes
    $createTable('finance_cashboxes', <<<'SQL'
CREATE TABLE `finance_cashboxes` (
    `id` INT NOT NULL AUTO_INCREMENT,
    `code` VARCHAR(50) NOT NULL,
    `name` VARCHAR(200) NOT NULL,
    `type` ENUM('cash','bank') NOT NULL DEFAULT 'cash',
    `is_active` TINYINT(1) NOT NULL DEFAULT 1,
    `accountability_role` VARCHAR(100) NULL,
    `receipt_prefix` VARCHAR(20) NULL,
    `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uk_cashbox_code` (`code`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL);

    // 4. Bank accounts
    $createTable('finance_bank_accounts', <<<'SQL'
CREATE TABLE `finance_bank_accounts` (
    `id` INT NOT NULL AUTO_INCREMENT,
    `cashbox_id` INT NOT NULL,
    `bank_name` VARCHAR(200) NOT NULL,
    `iban_masked` VARCHAR(50) NULL,
    `account_last4` VARCHAR(4) NULL,
    `currency` VARCHAR(10) NOT NULL DEFAULT 'EGP',
    `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uk_bank_account_cashbox` (`cashbox_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL);

    // 5. Cashbox settlements
    $createTable('finance_cashbox_settlements', <<<'SQL'
CREATE TABLE `finance_cashbox_settlements` (
    `id` INT NOT NULL AUTO_INCREMENT,
    `cashbox_id` INT NOT NULL,
    `period_id` INT NULL,
    `settlement_date` DATE NOT NULL,
    `opening_float` DECIMAL(14,2) NOT NULL DEFAULT 0.00,
    `expected_total` DECIMAL(14,2) NOT NULL DEFAULT 0.00,
    `counted_total` DECIMAL(14,2) NOT NULL DEFAULT 0.00,
    `difference` DECIMAL(14,2) NOT NULL DEFAULT 0.00,
    `status` ENUM('open','settled','adjusted') NOT NULL DEFAULT 'open',
    `settled_by` INT NULL,
    `settled_at` DATETIME NULL,
    `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    KEY `idx_settlement_cashbox` (`cashbox_id`),
    KEY `idx_settlement_date` (`settlement_date`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL);

    // 6. Receipt number sequences
    $createTable('finance_receipt_number_sequences', <<<'SQL'
CREATE TABLE `finance_receipt_number_sequences` (
    `id` INT NOT NULL AUTO_INCREMENT,
    `cashbox_id` INT NOT NULL,
    `academic_year_id` INT NOT NULL,
    `next_sequence` INT NOT NULL DEFAULT 1,
    `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uk_receipt_seq_cashbox_year` (`cashbox_id`, `academic_year_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL);

    // 7. Import batches
    $createTable('finance_import_batches', <<<'SQL'
CREATE TABLE `finance_import_batches` (
    `id` INT NOT NULL AUTO_INCREMENT,
    `batch_id` CHAR(32) NOT NULL,
    `operation_type` VARCHAR(50) NOT NULL DEFAULT 'staging_only',
    `schema_version` VARCHAR(20) NOT NULL,
    `academic_year_id` INT NULL,
    `source_file_ref` VARCHAR(500) NULL,
    `status` ENUM('staged','posted','reversed','abandoned') NOT NULL DEFAULT 'staged',
    `posted_at` DATETIME NULL,
    `posted_by` INT NULL,
    `approved_by` INT NULL,
    `reversal_of` INT NULL,
    `reversed_at` DATETIME NULL,
    `reversed_by` INT NULL,
    `row_count` INT NOT NULL DEFAULT 0,
    `error_count` INT NOT NULL DEFAULT 0,
    `created_by` INT NULL,
    `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uk_import_batch_id` (`batch_id`),
    UNIQUE KEY `uk_import_batch_reversal` (`reversal_of`),
    CONSTRAINT `fk_import_batch_reversal` FOREIGN KEY (`reversal_of`) REFERENCES `finance_import_batches` (`id`) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL);

    // 8. Import rows
    $createTable('finance_import_rows', <<<'SQL'
CREATE TABLE `finance_import_rows` (
    `id` INT NOT NULL AUTO_INCREMENT,
    `import_batch_id` INT NOT NULL,
    `row_number` INT NOT NULL,
    `payload_json` JSON NULL,
    `validation_status` ENUM('valid','invalid') NOT NULL DEFAULT 'valid',
    `error_messages_json` JSON NULL,
    `posting_result_json` JSON NULL,
    `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    KEY `idx_import_row_batch` (`import_batch_id`),
    CONSTRAINT `fk_import_row_batch` FOREIGN KEY (`import_batch_id`) REFERENCES `finance_import_batches` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL);

    // ============================================================
    // 9-11. UNIFIED SUB-LEDGER TRUTH SOURCE (students + staff)
    // ============================================================

    // 9. Sub-ledger accounts (one per party × scope; NO balance column)
    $createTable('finance_subledger_accounts', <<<'SQL'
CREATE TABLE `finance_subledger_accounts` (
    `id` INT NOT NULL AUTO_INCREMENT,
    `party_type` ENUM('student','staff') NOT NULL,
    `party_id` INT NOT NULL,
    `scope_key` VARCHAR(50) NOT NULL,
    `currency` VARCHAR(10) NOT NULL DEFAULT 'EGP',
    `status` ENUM('active','closed') NOT NULL DEFAULT 'active',
    `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `created_by` INT NULL,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uk_subledger_party_scope_currency` (`party_type`, `party_id`, `scope_key`, `currency`),
    KEY `idx_subledger_party` (`party_type`, `party_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL);

    // 10. Sub-ledger transactions (one header per source operation; append-only; status draft|posted ONLY)
    $createTable('finance_subledger_transactions', <<<'SQL'
CREATE TABLE `finance_subledger_transactions` (
    `id` INT NOT NULL AUTO_INCREMENT,
    `subledger_account_id` INT NOT NULL,
    `source_type` VARCHAR(50) NOT NULL,
    `source_ref_id` INT NULL,
    `source_idempotency_key` CHAR(32) NOT NULL,
    `status` ENUM('draft','posted') NOT NULL DEFAULT 'draft',
    `reversal_of` INT NULL,
    `batch_id` CHAR(32) NULL,
    `request_id` CHAR(32) NULL,
    `posted_at` DATETIME NULL,
    `posted_by` INT NULL,
    `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `created_by` INT NULL,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uk_subledger_tx_idempotency` (`source_idempotency_key`),
    UNIQUE KEY `uk_subledger_tx_reversal` (`reversal_of`),
    KEY `idx_subledger_tx_account` (`subledger_account_id`),
    KEY `idx_subledger_tx_source` (`source_type`, `source_ref_id`),
    KEY `idx_subledger_tx_reversal` (`reversal_of`),
    KEY `idx_subledger_tx_batch` (`batch_id`),
    CONSTRAINT `fk_subledger_tx_account` FOREIGN KEY (`subledger_account_id`) REFERENCES `finance_subledger_accounts` (`id`) ON DELETE RESTRICT,
    CONSTRAINT `fk_subledger_tx_reversal` FOREIGN KEY (`reversal_of`) REFERENCES `finance_subledger_transactions` (`id`) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL);

    // 11. Sub-ledger lines (append-only; signed deltas per bucket; FK RESTRICT — NOT CASCADE)
    $createTable('finance_subledger_lines', <<<'SQL'
CREATE TABLE `finance_subledger_lines` (
    `id` INT NOT NULL AUTO_INCREMENT,
    `transaction_id` INT NOT NULL,
    `line_number` INT NOT NULL,
    `bucket_code` VARCHAR(50) NOT NULL,
    `amount_delta` DECIMAL(14,2) NOT NULL,
    `description` VARCHAR(500) NULL,
    `installment_id` INT NULL,
    `cost_center_id` INT NULL,
    `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uk_subledger_line_tx_num` (`transaction_id`, `line_number`),
    KEY `idx_subledger_line_bucket` (`bucket_code`),
    CONSTRAINT `fk_subledger_line_tx` FOREIGN KEY (`transaction_id`) REFERENCES `finance_subledger_transactions` (`id`) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL);

    // Seed charge types
    $seedTypes = [
        ['tuition', 'مصروفات دراسية', 1],
        ['bus', 'حافلة', 1],
        ['books', 'كتب', 1],
        ['activities', 'أنشطة', 1],
        ['events', 'حفلات ورحلات', 1],
        ['uniform', 'زي أو أدوات', 1],
        ['other_services', 'خدمات أخرى', 1],
        ['opening_balance', 'رصيد افتتاحي', 1],
    ];
    $checkType = $db->prepare('SELECT COUNT(*) FROM finance_charge_types WHERE code = ?');
    $insertType = $db->prepare('INSERT INTO finance_charge_types (code, name_ar, is_system) VALUES (?, ?, ?)');
    foreach ($seedTypes as [$code, $nameAr, $isSystem]) {
        $checkType->execute([$code]);
        if ((int) $checkType->fetchColumn() === 0) {
            $insertType->execute([$code, $nameAr, $isSystem]);
        }
    }
};
