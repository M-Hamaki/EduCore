<?php

declare(strict_types=1);

/**
 * Immutable FR-136 organization/calendar correction previews, decisions, and
 * exact downstream impact intents. This additive migration is never run from
 * a web request. Reversal is a later correction row, never a mutation/delete.
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

    if (!$tableExists('staff_organization_corrections')) {
        $db->exec(<<<'SQL'
CREATE TABLE staff_organization_corrections (
    id BIGINT NOT NULL AUTO_INCREMENT,
    correction_kind ENUM('organization_unit','job_title','manager','calendar') NOT NULL,
    scope_type ENUM('staff','org_unit','policy_group','global') NOT NULL,
    scope_id BIGINT NULL,
    effective_from DATE NOT NULL,
    effective_to DATE NOT NULL,
    proposed_reference_id BIGINT NOT NULL,
    reason_text TEXT NOT NULL,
    reason_hash CHAR(64) NOT NULL,
    impact_snapshot_json JSON NOT NULL,
    impact_snapshot_hash CHAR(64) NOT NULL,
    reverses_correction_id BIGINT NULL,
    direction ENUM('apply','reverse') NOT NULL DEFAULT 'apply',
    requested_by INT NOT NULL,
    payload_hash CHAR(64) NOT NULL,
    idempotency_key CHAR(64) NOT NULL,
    lock_version INT UNSIGNED NOT NULL DEFAULT 1,
    created_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
    PRIMARY KEY (id),
    UNIQUE KEY uk_staff_org_correction_idempotency (idempotency_key),
    KEY idx_staff_org_correction_scope (scope_type, scope_id, effective_from, effective_to),
    KEY idx_staff_org_correction_reversal (reverses_correction_id),
    CONSTRAINT fk_staff_org_correction_reversal
        FOREIGN KEY (reverses_correction_id) REFERENCES staff_organization_corrections (id) ON DELETE RESTRICT,
    CONSTRAINT chk_staff_org_correction_dates CHECK (effective_to >= effective_from),
    CONSTRAINT chk_staff_org_correction_scope CHECK (
        (scope_type = 'global' AND scope_id IS NULL)
        OR (scope_type <> 'global' AND scope_id > 0)
    ),
    CONSTRAINT chk_staff_org_correction_reference CHECK (proposed_reference_id > 0),
    CONSTRAINT chk_staff_org_correction_direction CHECK (
        (direction = 'apply' AND reverses_correction_id IS NULL)
        OR (direction = 'reverse' AND reverses_correction_id IS NOT NULL)
    ),
    CONSTRAINT chk_staff_org_correction_hashes CHECK (
        CHAR_LENGTH(reason_hash) = 64
        AND CHAR_LENGTH(impact_snapshot_hash) = 64
        AND CHAR_LENGTH(payload_hash) = 64
        AND CHAR_LENGTH(idempotency_key) = 64
    ),
    CONSTRAINT chk_staff_org_correction_lock CHECK (lock_version = 1)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL);
    }

    if (!$tableExists('staff_organization_correction_decisions')) {
        $db->exec(<<<'SQL'
CREATE TABLE staff_organization_correction_decisions (
    id BIGINT NOT NULL AUTO_INCREMENT,
    correction_id BIGINT NOT NULL,
    decision ENUM('approved','rejected') NOT NULL,
    comment_hash CHAR(64) NULL,
    decided_by INT NOT NULL,
    decision_hash CHAR(64) NOT NULL,
    idempotency_key CHAR(64) NOT NULL,
    created_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
    PRIMARY KEY (id),
    UNIQUE KEY uk_staff_org_correction_final_decision (correction_id),
    UNIQUE KEY uk_staff_org_correction_decision_key (idempotency_key),
    CONSTRAINT fk_staff_org_correction_decision
        FOREIGN KEY (correction_id) REFERENCES staff_organization_corrections (id) ON DELETE RESTRICT,
    CONSTRAINT chk_staff_org_correction_decision_hashes CHECK (
        (comment_hash IS NULL OR CHAR_LENGTH(comment_hash) = 64)
        AND CHAR_LENGTH(decision_hash) = 64
        AND CHAR_LENGTH(idempotency_key) = 64
    )
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL);
    }

    if (!$tableExists('staff_organization_correction_impacts')) {
        $db->exec(<<<'SQL'
CREATE TABLE staff_organization_correction_impacts (
    id BIGINT NOT NULL AUTO_INCREMENT,
    correction_id BIGINT NOT NULL,
    decision_id BIGINT NOT NULL,
    direction ENUM('apply','reverse') NOT NULL,
    impact_type ENUM('attendance_day','request_route','report_period') NOT NULL,
    resource_type VARCHAR(64) NOT NULL,
    resource_id BIGINT NULL,
    staff_user_id INT NULL,
    work_date DATE NULL,
    report_period CHAR(7) NULL,
    source_snapshot_hash CHAR(64) NOT NULL,
    impact_key CHAR(64) NOT NULL,
    created_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
    PRIMARY KEY (id),
    UNIQUE KEY uk_staff_org_correction_impact_key (impact_key),
    KEY idx_staff_org_correction_impact_staff (staff_user_id, work_date, impact_type),
    KEY idx_staff_org_correction_impact_request (resource_type, resource_id, impact_type),
    KEY idx_staff_org_correction_impact_period (report_period, staff_user_id, impact_type),
    CONSTRAINT fk_staff_org_correction_impact_correction
        FOREIGN KEY (correction_id) REFERENCES staff_organization_corrections (id) ON DELETE RESTRICT,
    CONSTRAINT fk_staff_org_correction_impact_decision
        FOREIGN KEY (decision_id) REFERENCES staff_organization_correction_decisions (id) ON DELETE RESTRICT,
    CONSTRAINT chk_staff_org_correction_impact_shape CHECK (
        (impact_type = 'attendance_day' AND staff_user_id IS NOT NULL AND work_date IS NOT NULL AND resource_id IS NULL AND report_period IS NULL)
        OR (impact_type = 'request_route' AND resource_id IS NOT NULL AND work_date IS NULL AND report_period IS NULL)
        OR (impact_type = 'report_period' AND staff_user_id IS NOT NULL AND report_period IS NOT NULL AND resource_id IS NULL AND work_date IS NULL)
    ),
    CONSTRAINT chk_staff_org_correction_impact_hashes CHECK (
        CHAR_LENGTH(source_snapshot_hash) = 64 AND CHAR_LENGTH(impact_key) = 64
    )
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL);
    }

    $immutableTables = [
        'staff_organization_corrections',
        'staff_organization_correction_decisions',
        'staff_organization_correction_impacts',
    ];
    foreach ($immutableTables as $table) {
        $base = str_replace('staff_organization_', 'staff_org_', $table);
        $updateTrigger = 'trg_' . $base . '_no_update';
        $deleteTrigger = 'trg_' . $base . '_no_delete';
        if (!$triggerExists($updateTrigger)) {
            $db->exec(
                "CREATE TRIGGER `{$updateTrigger}` BEFORE UPDATE ON `{$table}`
                 FOR EACH ROW SIGNAL SQLSTATE '45000'
                 SET MESSAGE_TEXT = 'Staff organization correction history is immutable'"
            );
        }
        if (!$triggerExists($deleteTrigger)) {
            $db->exec(
                "CREATE TRIGGER `{$deleteTrigger}` BEFORE DELETE ON `{$table}`
                 FOR EACH ROW SIGNAL SQLSTATE '45000'
                 SET MESSAGE_TEXT = 'Staff organization correction history cannot be deleted'"
            );
        }
    }
};
