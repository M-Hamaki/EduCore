<?php

declare(strict_types=1);

/**
 * Finance legacy compatibility metadata.
 *
 * The table preserves legacy public identifiers and presentation-only payloads
 * during cutover. It is never an accounting truth source: balances and posted
 * amounts continue to come exclusively from the unified sub-ledger.
 *
 * Preconditions: Finance foundation migrations have been applied.
 * Rollback: DROP TABLE finance_legacy_compatibility_mappings.
 */
return static function (PDO $db): void {
    $columnExists = static function (string $table, string $column) use ($db): bool {
        $stmt = $db->prepare(
            'SELECT COUNT(*) FROM information_schema.COLUMNS
             WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?'
        );
        $stmt->execute([$table, $column]);
        return (int) $stmt->fetchColumn() > 0;
    };
    if (!$columnExists('finance_discount_rules', 'calculation_type')) {
        $db->exec(
            "ALTER TABLE finance_discount_rules
             ADD COLUMN calculation_type ENUM('manual_amount','fixed_amount','percentage','sibling_tiers')
                NOT NULL DEFAULT 'manual_amount' AFTER scope_charge_type_key,
             ADD COLUMN calculation_value DECIMAL(14,2) NULL AFTER calculation_type,
             ADD COLUMN parameters_json JSON NULL AFTER calculation_value"
        );
    }
    if (!$columnExists('finance_discount_applications', 'ledger_effect_amount')) {
        $db->exec(
            "ALTER TABLE finance_discount_applications
             ADD COLUMN ledger_effect_amount DECIMAL(14,2) NOT NULL DEFAULT 0.00 AFTER applied_amount,
             ADD COLUMN adjustment_id INT NULL AFTER ledger_effect_amount,
             ADD COLUMN subledger_transaction_id INT NULL AFTER adjustment_id,
             ADD COLUMN request_id CHAR(32) NULL AFTER subledger_transaction_id,
             ADD UNIQUE KEY uk_disc_app_request (request_id)"
        );
    }
    $constraintExists = static function (string $table, string $constraint) use ($db): bool {
        $stmt = $db->prepare(
            'SELECT COUNT(*) FROM information_schema.TABLE_CONSTRAINTS
             WHERE CONSTRAINT_SCHEMA = DATABASE() AND TABLE_NAME = ? AND CONSTRAINT_NAME = ?'
        );
        $stmt->execute([$table, $constraint]);
        return (int) $stmt->fetchColumn() > 0;
    };
    if (!$constraintExists('finance_discount_applications', 'fk_disc_app_adjustment')) {
        $db->exec(
            'ALTER TABLE finance_discount_applications
             ADD CONSTRAINT fk_disc_app_adjustment
                FOREIGN KEY (adjustment_id) REFERENCES finance_adjustments (id) ON DELETE RESTRICT'
        );
    }
    if (!$constraintExists('finance_discount_applications', 'fk_disc_app_subledger_tx')) {
        $db->exec(
            'ALTER TABLE finance_discount_applications
             ADD CONSTRAINT fk_disc_app_subledger_tx
                FOREIGN KEY (subledger_transaction_id) REFERENCES finance_subledger_transactions (id) ON DELETE RESTRICT'
        );
    }
    $componentExists = $db->prepare('SELECT COUNT(*) FROM payroll_components WHERE code = ?');
    $insertComponent = $db->prepare(
        'INSERT INTO payroll_components (code, name_ar, direction, is_system) VALUES (?, ?, ?, 1)'
    );
    foreach ([
        ['allowance_transport', 'بدل انتقال', 'earning'],
        ['allowance_housing', 'بدل سكن', 'earning'],
    ] as [$code, $name, $direction]) {
        $componentExists->execute([$code]);
        if ((int) $componentExists->fetchColumn() === 0) {
            $insertComponent->execute([$code, $name, $direction]);
        }
    }
    if (!$columnExists('staff_compensation_contracts', 'version_number')) {
        $indexExists = $db->prepare(
            'SELECT COUNT(*) FROM information_schema.STATISTICS
             WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND INDEX_NAME = ?'
        );
        $indexExists->execute(['staff_compensation_contracts', 'uk_comp_contract_staff_eff']);
        if ((int) $indexExists->fetchColumn() > 0) {
            $db->exec(
                'ALTER TABLE staff_compensation_contracts
                 DROP INDEX uk_comp_contract_staff_eff'
            );
        }
        $db->exec(
            'ALTER TABLE staff_compensation_contracts
             ADD COLUMN version_number INT NOT NULL DEFAULT 1 AFTER effective_to,
             ADD UNIQUE KEY uk_comp_contract_staff_eff_ver
                (staff_id, effective_from, version_number)'
        );
    }

    $exists = $db->prepare(
        'SELECT COUNT(*) FROM information_schema.TABLES
         WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?'
    );
    $exists->execute(['finance_bus_fee_schedules']);
    if ((int) $exists->fetchColumn() === 0) {
        $db->exec(
            <<<'SQL'
CREATE TABLE `finance_bus_fee_schedules` (
    `id` INT NOT NULL AUTO_INCREMENT,
    `academic_year_id` INT NOT NULL,
    `transport_subscription_key` VARCHAR(160) NULL,
    `legacy_zone_key` VARCHAR(160) NULL,
    `zone_name` VARCHAR(200) NOT NULL,
    `version_number` INT NOT NULL DEFAULT 1,
    `amount` DECIMAL(14,2) NOT NULL,
    `installments_json` JSON NULL,
    `notes` TEXT NULL,
    `effective_from` DATE NOT NULL,
    `effective_to` DATE NULL,
    `status` ENUM('draft','active','superseded','archived') NOT NULL DEFAULT 'draft',
    `created_by` INT NULL,
    `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uk_fin_bus_schedule_legacy_version` (`academic_year_id`, `legacy_zone_key`, `version_number`),
    KEY `idx_fin_bus_schedule_subscription` (`academic_year_id`, `transport_subscription_key`, `status`),
    KEY `idx_fin_bus_schedule_legacy` (`academic_year_id`, `legacy_zone_key`, `status`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL
        );
    }

    $exists->execute(['finance_legacy_compatibility_mappings']);
    if ((int) $exists->fetchColumn() > 0) {
        return;
    }

    $db->exec(
        <<<'SQL'
CREATE TABLE `finance_legacy_compatibility_mappings` (
    `id` INT NOT NULL AUTO_INCREMENT,
    `source_type` VARCHAR(80) NOT NULL,
    `source_key` VARCHAR(160) NOT NULL,
    `version_number` INT NOT NULL DEFAULT 1,
    `target_type` VARCHAR(80) NOT NULL,
    `target_id` INT NOT NULL,
    `academic_year_id` INT NULL,
    `payload_json` JSON NULL,
    `status` ENUM('active','superseded','archived') NOT NULL DEFAULT 'active',
    `superseded_at` DATETIME NULL,
    `created_by` INT NULL,
    `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uk_fin_legacy_mapping_version` (`source_type`, `source_key`, `version_number`),
    KEY `idx_fin_legacy_mapping_active` (`source_type`, `source_key`, `status`),
    KEY `idx_fin_legacy_mapping_target` (`target_type`, `target_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL
    );
};
