<?php

declare(strict_types=1);

/**
 * Versioned qualifications, training, and document-expiry evidence.
 *
 * This additive migration is not executed by application requests. Credential
 * rows are immutable evidence: correction/revocation is represented by a later
 * row linked through supersedes_id rather than altering or deleting history.
 * The attachment identifier is deliberately scalar until a reviewed private
 * attachment owner expands its resource policy.
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

    if (!$tableExists('staff_credential_records')) {
        $db->exec(<<<'SQL'
CREATE TABLE staff_credential_records (
    id BIGINT NOT NULL AUTO_INCREMENT,
    staff_user_id INT NOT NULL,
    credential_kind ENUM('qualification','training','document') NOT NULL,
    credential_key VARCHAR(100) NOT NULL,
    title VARCHAR(255) NOT NULL,
    issuer VARCHAR(255) NULL,
    effective_on DATE NOT NULL,
    issued_on DATE NULL,
    expires_on DATE NULL,
    attachment_id BIGINT NULL,
    verification_status ENUM('unverified','verified','rejected') NOT NULL DEFAULT 'unverified',
    lifecycle_status ENUM('active','revoked','superseded') NOT NULL DEFAULT 'active',
    supersedes_id BIGINT NULL,
    version INT UNSIGNED NOT NULL,
    source VARCHAR(50) NOT NULL DEFAULT 'manual',
    payload_hash CHAR(64) NOT NULL,
    idempotency_key CHAR(64) NOT NULL,
    created_by_user_id INT NOT NULL,
    created_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
    PRIMARY KEY (id),
    UNIQUE KEY uk_staff_credential_idempotency (idempotency_key),
    UNIQUE KEY uk_staff_credential_version (staff_user_id, credential_kind, credential_key, version),
    KEY idx_staff_credential_expiry (lifecycle_status, verification_status, expires_on, staff_user_id),
    KEY idx_staff_credential_staff (staff_user_id, credential_kind, effective_on),
    KEY idx_staff_credential_supersedes (supersedes_id),
    CONSTRAINT fk_staff_credential_supersedes
        FOREIGN KEY (supersedes_id) REFERENCES staff_credential_records (id) ON DELETE RESTRICT,
    CONSTRAINT chk_staff_credential_dates CHECK (
        (issued_on IS NULL OR issued_on >= effective_on)
        AND (expires_on IS NULL OR expires_on >= effective_on)
    ),
    CONSTRAINT chk_staff_credential_attachment CHECK (attachment_id IS NULL OR attachment_id > 0),
    CONSTRAINT chk_staff_credential_version CHECK (version > 0),
    -- supersedes_id points from a later immutable version to the record it
    -- replaces. The earlier record is never mutated just to mark it obsolete.
    CONSTRAINT chk_staff_credential_successor CHECK (
        supersedes_id IS NULL OR supersedes_id > 0
    ),
    CONSTRAINT chk_staff_credential_hashes CHECK (
        CHAR_LENGTH(payload_hash) = 64 AND CHAR_LENGTH(idempotency_key) = 64
    )
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL);
    }

    if (!$triggerExists('trg_staff_credential_records_no_update')) {
        $db->exec(<<<'SQL'
CREATE TRIGGER trg_staff_credential_records_no_update
BEFORE UPDATE ON staff_credential_records
FOR EACH ROW
BEGIN
    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Staff credential records are immutable; create a successor record';
END
SQL);
    }
    if (!$triggerExists('trg_staff_credential_records_no_delete')) {
        $db->exec(<<<'SQL'
CREATE TRIGGER trg_staff_credential_records_no_delete
BEFORE DELETE ON staff_credential_records
FOR EACH ROW
BEGIN
    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Staff credential records cannot be hard deleted';
END
SQL);
    }
};
