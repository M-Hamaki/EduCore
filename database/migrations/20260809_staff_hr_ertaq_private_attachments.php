<?php

declare(strict_types=1);

/**
 * Private, append-only attachment metadata for the Ertaq workflow.
 *
 * File bytes live below storage/private/ertaq_attachments and are represented
 * here only by a normalized private reference. The table intentionally uses a
 * generic Staff resource identity while constraining this first migration to
 * Ertaq ticket/message resources; later Staff resources need their own
 * migration and validation rule rather than an unreviewed polymorphic write.
 *
 * Rollback is isolated-environment only: stop the Ertaq attachment readers
 * and writers, preserve protected evidence, then remove this table and its
 * triggers. Production history and stored private evidence are never a
 * rollback target.
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
    if (!$tableExists('staff_resource_attachments')) {
        $db->exec(<<<'SQL'
CREATE TABLE staff_resource_attachments (
    id BIGINT NOT NULL AUTO_INCREMENT,
    resource_type ENUM('ertaq_ticket','ertaq_message') NOT NULL,
    resource_id BIGINT NOT NULL,
    ticket_id BIGINT NOT NULL,
    message_id BIGINT NULL,
    visibility_scope ENUM('requester','assigned_team','restricted','protection_team') NOT NULL,
    confidentiality_level ENUM('normal','restricted','highly_restricted') NOT NULL,
    storage_ref VARCHAR(500) NOT NULL,
    original_name VARCHAR(255) NOT NULL,
    mime_type VARCHAR(100) NOT NULL,
    byte_size BIGINT UNSIGNED NOT NULL,
    content_sha256 CHAR(64) NOT NULL,
    uploaded_by_user_id INT NOT NULL,
    uploaded_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
    retention_until DATE NULL,
    legal_hold TINYINT(1) NOT NULL DEFAULT 0,
    status ENUM('active','revoked') NOT NULL DEFAULT 'active',
    revoked_at DATETIME(6) NULL,
    revoked_by_user_id INT NULL,
    revocation_reason TEXT NULL,
    attachment_hash CHAR(64) NOT NULL,
    idempotency_key CHAR(64) NOT NULL,
    lock_version INT NOT NULL DEFAULT 1,
    created_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
    updated_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6) ON UPDATE CURRENT_TIMESTAMP(6),
    PRIMARY KEY (id),
    UNIQUE KEY uk_staff_resource_attachment_idempotency (idempotency_key),
    KEY idx_staff_resource_attachment_ticket (ticket_id, visibility_scope, status, uploaded_at),
    KEY idx_staff_resource_attachment_resource (resource_type, resource_id, status),
    KEY idx_staff_resource_attachment_message (message_id),
    CONSTRAINT fk_staff_resource_attachment_ticket
        FOREIGN KEY (ticket_id) REFERENCES staff_ertaq_tickets (id) ON DELETE RESTRICT,
    CONSTRAINT fk_staff_resource_attachment_message
        FOREIGN KEY (message_id) REFERENCES staff_ertaq_messages (id) ON DELETE RESTRICT,
    CONSTRAINT chk_staff_resource_attachment_resource CHECK (
        (resource_type = 'ertaq_ticket' AND resource_id = ticket_id AND message_id IS NULL)
        OR (resource_type = 'ertaq_message' AND resource_id = message_id AND message_id IS NOT NULL)
    ),
    CONSTRAINT chk_staff_resource_attachment_hash CHECK (
        CHAR_LENGTH(content_sha256) = 64 AND CHAR_LENGTH(attachment_hash) = 64
    ),
    CONSTRAINT chk_staff_resource_attachment_size CHECK (byte_size > 0),
    CONSTRAINT chk_staff_resource_attachment_lock CHECK (lock_version > 0),
    CONSTRAINT chk_staff_resource_attachment_revocation CHECK (
        (status = 'active' AND revoked_at IS NULL AND revoked_by_user_id IS NULL AND revocation_reason IS NULL)
        OR (status = 'revoked' AND revoked_at IS NOT NULL AND revoked_by_user_id IS NOT NULL)
    )
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL);
    }

    if (!$triggerExists('trg_staff_resource_attachment_guard_update')) {
        $db->exec(<<<'SQL'
CREATE TRIGGER trg_staff_resource_attachment_guard_update
BEFORE UPDATE ON staff_resource_attachments
FOR EACH ROW
BEGIN
    IF NOT (NEW.resource_type <=> OLD.resource_type)
        OR NOT (NEW.resource_id <=> OLD.resource_id)
        OR NOT (NEW.ticket_id <=> OLD.ticket_id)
        OR NOT (NEW.message_id <=> OLD.message_id)
        OR NOT (NEW.visibility_scope <=> OLD.visibility_scope)
        OR NOT (NEW.confidentiality_level <=> OLD.confidentiality_level)
        OR NOT (NEW.storage_ref <=> OLD.storage_ref)
        OR NOT (NEW.original_name <=> OLD.original_name)
        OR NOT (NEW.mime_type <=> OLD.mime_type)
        OR NOT (NEW.byte_size <=> OLD.byte_size)
        OR NOT (NEW.content_sha256 <=> OLD.content_sha256)
        OR NOT (NEW.uploaded_by_user_id <=> OLD.uploaded_by_user_id)
        OR NOT (NEW.uploaded_at <=> OLD.uploaded_at)
        OR NOT (NEW.attachment_hash <=> OLD.attachment_hash)
        OR NOT (NEW.idempotency_key <=> OLD.idempotency_key) THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Ertaq attachment identity is immutable';
    END IF;
    IF OLD.status = 'revoked' AND NEW.status <> OLD.status THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Revoked Ertaq attachment cannot be restored';
    END IF;
END
SQL);
    }

    if (!$triggerExists('trg_staff_resource_attachment_no_delete')) {
        $db->exec(<<<'SQL'
CREATE TRIGGER trg_staff_resource_attachment_no_delete
BEFORE DELETE ON staff_resource_attachments
FOR EACH ROW
BEGIN
    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Ertaq attachments cannot be hard deleted';
END
SQL);
    }
};
