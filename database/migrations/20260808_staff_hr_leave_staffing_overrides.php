<?php

declare(strict_types=1);

/**
 * Records manager-only staffing exceptions separately from editable leave
 * drafts. A request modification changes its request hash, so its historical
 * decision remains available for audit but cannot authorize the new draft.
 *
 * Rollback is isolated-environment only: first switch new readers/writers
 * off, retain/archive decision evidence, drop the three triggers, then drop
 * this table. Do not erase operational authorization history in production.
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
    $createTrigger = static function (string $trigger, string $ddl) use ($db, $triggerExists): void {
        if (!$triggerExists($trigger)) {
            $db->exec($ddl);
        }
    };

    if (!$tableExists('staff_leave_requests')) {
        return;
    }

    if (!$tableExists('staff_leave_staffing_overrides')) {
        $db->exec(<<<'SQL'
CREATE TABLE staff_leave_staffing_overrides (
    id BIGINT NOT NULL AUTO_INCREMENT,
    leave_request_id BIGINT NOT NULL,
    request_hash CHAR(64) NOT NULL,
    decision_outcome ENUM('approved','rejected') NOT NULL,
    required_role_keys JSON NOT NULL,
    requirement_fingerprint CHAR(64) NOT NULL,
    assessment_snapshot JSON NOT NULL,
    decision_reason VARCHAR(1000) NOT NULL,
    reason_hash CHAR(64) NOT NULL,
    decision_idempotency_key VARCHAR(190) NOT NULL,
    decision_hash CHAR(64) NOT NULL,
    decided_by INT NOT NULL,
    decided_at DATETIME(6) NOT NULL,
    created_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
    PRIMARY KEY (id),
    UNIQUE KEY uk_staff_leave_staffing_override_request_hash (leave_request_id, request_hash),
    UNIQUE KEY uk_staff_leave_staffing_override_idempotency (decision_idempotency_key),
    KEY idx_staff_leave_staffing_override_request (leave_request_id, decided_at),
    KEY idx_staff_leave_staffing_override_actor (decided_by, decided_at),
    CONSTRAINT fk_staff_leave_staffing_override_request
        FOREIGN KEY (leave_request_id) REFERENCES staff_leave_requests (id) ON DELETE RESTRICT,
    CONSTRAINT chk_staff_leave_staffing_override_hashes CHECK (
        CHAR_LENGTH(request_hash) = 64
        AND CHAR_LENGTH(requirement_fingerprint) = 64
        AND CHAR_LENGTH(reason_hash) = 64
        AND CHAR_LENGTH(decision_hash) = 64
    ),
    CONSTRAINT chk_staff_leave_staffing_override_reason
        CHECK (CHAR_LENGTH(TRIM(decision_reason)) > 0),
    CONSTRAINT chk_staff_leave_staffing_override_roles
        CHECK (JSON_TYPE(required_role_keys) = 'ARRAY' AND JSON_LENGTH(required_role_keys) > 0)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL);
    }

    $createTrigger('trg_staff_leave_staffing_override_guard_insert', <<<'SQL'
CREATE TRIGGER trg_staff_leave_staffing_override_guard_insert
BEFORE INSERT ON staff_leave_staffing_overrides
FOR EACH ROW
BEGIN
    DECLARE request_state VARCHAR(50);
    DECLARE current_request_hash CHAR(64);
    SELECT status, request_hash
      INTO request_state, current_request_hash
      FROM staff_leave_requests
     WHERE id = NEW.leave_request_id;
    IF request_state IS NULL OR request_state <> 'draft' THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Staffing override requires a draft leave request';
    END IF;
    IF NEW.request_hash <> current_request_hash THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Staffing override must match the current leave request hash';
    END IF;
END
SQL);
    $createTrigger('trg_staff_leave_staffing_override_no_update', <<<'SQL'
CREATE TRIGGER trg_staff_leave_staffing_override_no_update
BEFORE UPDATE ON staff_leave_staffing_overrides
FOR EACH ROW
BEGIN
    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Staffing override decisions are immutable';
END
SQL);
    $createTrigger('trg_staff_leave_staffing_override_no_delete', <<<'SQL'
CREATE TRIGGER trg_staff_leave_staffing_override_no_delete
BEFORE DELETE ON staff_leave_staffing_overrides
FOR EACH ROW
BEGIN
    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Staffing override decisions are retained for audit';
END
SQL);
};
