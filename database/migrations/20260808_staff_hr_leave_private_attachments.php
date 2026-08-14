<?php

declare(strict_types=1);

/**
 * Private, metadata-only medical evidence for editable Staff leave drafts.
 *
 * Files stay below storage/private and this migration stores only normalized
 * private references plus display/integrity metadata. Rollback is
 * environment-scoped: disable the feature, preserve audited metadata, and
 * remove physical test files before dropping this table in a disposable DB.
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
    $createTable = static function (string $table, string $ddl) use ($db, $tableExists): void {
        if (!$tableExists($table)) {
            $db->exec($ddl);
        }
    };
    $createTrigger = static function (string $trigger, string $ddl) use ($db, $triggerExists): void {
        if (!$triggerExists($trigger)) {
            $db->exec($ddl);
        }
    };

    $createTable('staff_leave_request_attachments', <<<'SQL'
CREATE TABLE staff_leave_request_attachments (
    id BIGINT NOT NULL AUTO_INCREMENT,
    leave_request_id BIGINT NOT NULL,
    attachment_kind ENUM('medical') NOT NULL DEFAULT 'medical',
    storage_ref VARCHAR(500) NOT NULL,
    original_name VARCHAR(255) NOT NULL,
    detected_mime VARCHAR(100) NOT NULL,
    byte_size BIGINT UNSIGNED NOT NULL,
    sha256 CHAR(64) NOT NULL,
    status ENUM('active','superseded') NOT NULL DEFAULT 'active',
    current_marker TINYINT GENERATED ALWAYS AS (
        CASE WHEN status = 'active' THEN 1 ELSE NULL END
    ) STORED,
    supersedes_attachment_id BIGINT NULL,
    uploaded_by INT NULL,
    uploaded_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
    superseded_at DATETIME(6) NULL,
    PRIMARY KEY (id),
    UNIQUE KEY uk_staff_leave_attachment_storage_ref (storage_ref),
    UNIQUE KEY uk_staff_leave_attachment_current (
        leave_request_id, attachment_kind, current_marker
    ),
    KEY idx_staff_leave_attachment_request_status (leave_request_id, status),
    KEY idx_staff_leave_attachment_supersedes (supersedes_attachment_id),
    CONSTRAINT fk_staff_leave_attachment_request
        FOREIGN KEY (leave_request_id) REFERENCES staff_leave_requests (id) ON DELETE RESTRICT,
    CONSTRAINT fk_staff_leave_attachment_supersedes
        FOREIGN KEY (supersedes_attachment_id) REFERENCES staff_leave_request_attachments (id) ON DELETE RESTRICT,
    CONSTRAINT chk_staff_leave_attachment_size CHECK (byte_size > 0 AND byte_size <= 10485760),
    CONSTRAINT chk_staff_leave_attachment_hash CHECK (CHAR_LENGTH(sha256) = 64),
    CONSTRAINT chk_staff_leave_attachment_state CHECK (
        (status = 'active' AND superseded_at IS NULL)
        OR (status = 'superseded' AND superseded_at IS NOT NULL)
    )
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL);

    $createTrigger('trg_staff_leave_attachment_guard_insert', <<<'SQL'
CREATE TRIGGER trg_staff_leave_attachment_guard_insert
BEFORE INSERT ON staff_leave_request_attachments
FOR EACH ROW
BEGIN
    DECLARE request_state VARCHAR(50);
    DECLARE parent_request BIGINT;

    SELECT status INTO request_state
      FROM staff_leave_requests
     WHERE id = NEW.leave_request_id;
    IF request_state IS NULL OR request_state <> 'draft' THEN
        SIGNAL SQLSTATE '45000'
            SET MESSAGE_TEXT = 'Leave medical attachment requires an editable draft';
    END IF;
    IF NEW.status <> 'active' OR NEW.superseded_at IS NOT NULL THEN
        SIGNAL SQLSTATE '45000'
            SET MESSAGE_TEXT = 'New leave medical attachment must be active';
    END IF;
    IF NEW.supersedes_attachment_id IS NOT NULL THEN
        SELECT leave_request_id INTO parent_request
          FROM staff_leave_request_attachments
         WHERE id = NEW.supersedes_attachment_id;
        IF parent_request IS NULL OR parent_request <> NEW.leave_request_id THEN
            SIGNAL SQLSTATE '45000'
                SET MESSAGE_TEXT = 'Leave medical attachment successor must retain its request';
        END IF;
    END IF;
END
SQL);

    $createTrigger('trg_staff_leave_attachment_guard_update', <<<'SQL'
CREATE TRIGGER trg_staff_leave_attachment_guard_update
BEFORE UPDATE ON staff_leave_request_attachments
FOR EACH ROW
BEGIN
    IF NOT (NEW.leave_request_id <=> OLD.leave_request_id)
       OR NOT (NEW.attachment_kind <=> OLD.attachment_kind)
       OR NOT (NEW.storage_ref <=> OLD.storage_ref)
       OR NOT (NEW.original_name <=> OLD.original_name)
       OR NOT (NEW.detected_mime <=> OLD.detected_mime)
       OR NOT (NEW.byte_size <=> OLD.byte_size)
       OR NOT (NEW.sha256 <=> OLD.sha256)
       OR NOT (NEW.supersedes_attachment_id <=> OLD.supersedes_attachment_id)
       OR NOT (NEW.uploaded_by <=> OLD.uploaded_by)
       OR NOT (NEW.uploaded_at <=> OLD.uploaded_at)
       OR OLD.status <> 'active'
       OR NEW.status <> 'superseded'
       OR NEW.superseded_at IS NULL THEN
        SIGNAL SQLSTATE '45000'
            SET MESSAGE_TEXT = 'Leave medical attachment evidence is immutable';
    END IF;
END
SQL);

    $createTrigger('trg_staff_leave_attachment_no_delete', <<<'SQL'
CREATE TRIGGER trg_staff_leave_attachment_no_delete
BEFORE DELETE ON staff_leave_request_attachments
FOR EACH ROW
BEGIN
    SIGNAL SQLSTATE '45000'
        SET MESSAGE_TEXT = 'Leave medical attachment metadata is retained';
END
SQL);
};
