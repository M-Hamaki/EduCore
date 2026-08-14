<?php

declare(strict_types=1);

/**
 * Hardens the unclosed discipline lifecycle without rewriting legal history.
 *
 * This additive follow-up links an append-only reopen authorization to its
 * request, preserves a resolution reason for temporary measures, and expands
 * only the legal transitions necessary for withdrawn/expired appeals and
 * evidence-based reopening. It is intentionally never run by web requests.
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
    $columnExists = static function (string $table, string $column) use ($db): bool {
        $statement = $db->prepare(
            'SELECT COUNT(*) FROM information_schema.COLUMNS
             WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?'
        );
        $statement->execute([$table, $column]);

        return (int) $statement->fetchColumn() > 0;
    };
    $indexExists = static function (string $table, string $index) use ($db): bool {
        $statement = $db->prepare(
            'SELECT COUNT(*) FROM information_schema.STATISTICS
             WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND INDEX_NAME = ?'
        );
        $statement->execute([$table, $index]);

        return (int) $statement->fetchColumn() > 0;
    };
    $foreignKeyExists = static function (string $table, string $constraint) use ($db): bool {
        $statement = $db->prepare(
            'SELECT COUNT(*) FROM information_schema.TABLE_CONSTRAINTS
             WHERE TABLE_SCHEMA = DATABASE()
               AND TABLE_NAME = ?
               AND CONSTRAINT_NAME = ?
               AND CONSTRAINT_TYPE = ?'
        );
        $statement->execute([$table, $constraint, 'FOREIGN KEY']);

        return (int) $statement->fetchColumn() > 0;
    };

    foreach ([
        'staff_discipline_cases',
        'staff_discipline_evidence',
        'staff_discipline_interim_measures',
        'staff_discipline_reopen_events',
    ] as $table) {
        if (!$tableExists($table)) {
            throw new RuntimeException('Discipline appeal/reopen hardening requires the discipline foundation migration.');
        }
    }

    if (!$columnExists('staff_discipline_reopen_events', 'request_event_id')) {
        $db->exec(
            'ALTER TABLE staff_discipline_reopen_events
             ADD COLUMN request_event_id BIGINT NULL AFTER id'
        );
    }
    if (!$indexExists('staff_discipline_reopen_events', 'uk_staff_discipline_reopen_request_outcome')) {
        $db->exec(
            'ALTER TABLE staff_discipline_reopen_events
             ADD UNIQUE KEY uk_staff_discipline_reopen_request_outcome (request_event_id)'
        );
    }
    if (!$foreignKeyExists('staff_discipline_reopen_events', 'fk_staff_discipline_reopen_request')) {
        $db->exec(
            'ALTER TABLE staff_discipline_reopen_events
             ADD CONSTRAINT fk_staff_discipline_reopen_request
             FOREIGN KEY (request_event_id) REFERENCES staff_discipline_reopen_events (id)
             ON DELETE RESTRICT'
        );
    }
    if (!$columnExists('staff_discipline_interim_measures', 'resolution_reason')) {
        $db->exec(
            'ALTER TABLE staff_discipline_interim_measures
             ADD COLUMN resolution_reason TEXT NULL AFTER reason'
        );
    }

    $db->exec('DROP TRIGGER IF EXISTS trg_staff_discipline_case_guard_update');
    $db->exec(<<<'SQL'
CREATE TRIGGER trg_staff_discipline_case_guard_update
BEFORE UPDATE ON staff_discipline_cases
FOR EACH ROW
BEGIN
    IF NEW.status <> OLD.status THEN
        IF NOT (
            (OLD.status = 'reported' AND NEW.status IN ('triage', 'cancelled'))
            OR (OLD.status = 'triage' AND NEW.status IN ('under_investigation', 'cancelled'))
            OR (OLD.status = 'under_investigation' AND NEW.status IN ('pending_decision', 'cancelled'))
            OR (OLD.status = 'pending_decision' AND NEW.status IN ('decided', 'cancelled'))
            OR (OLD.status = 'decided' AND NEW.status IN ('appeal_pending', 'closed', 'reopened'))
            OR (OLD.status = 'appeal_pending' AND NEW.status IN ('upheld', 'amended', 'revoked', 'decided', 'reopened'))
            OR (OLD.status IN ('upheld', 'amended', 'revoked', 'closed') AND NEW.status = 'reopened')
            OR (OLD.status = 'reopened' AND NEW.status = 'under_investigation')
        ) THEN
            SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Unsupported discipline case state transition';
        END IF;
    END IF;

    IF OLD.status <> 'reported'
       AND (
           NOT (NEW.case_no <=> OLD.case_no)
           OR NOT (NEW.incident_id <=> OLD.incident_id)
           OR NOT (NEW.subject_staff_user_id <=> OLD.subject_staff_user_id)
       ) THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Opened discipline case identity is immutable';
    END IF;
END
SQL);

    $db->exec('DROP TRIGGER IF EXISTS trg_staff_discipline_reopen_guard_insert');
    $db->exec(<<<'SQL'
CREATE TRIGGER trg_staff_discipline_reopen_guard_insert
BEFORE INSERT ON staff_discipline_reopen_events
FOR EACH ROW
BEGIN
    DECLARE evidence_case BIGINT;
    DECLARE current_case_status VARCHAR(50);
    DECLARE request_case BIGINT;
    DECLARE request_status VARCHAR(50);

    SELECT case_id INTO evidence_case
      FROM staff_discipline_evidence
     WHERE id = NEW.new_evidence_id;
    IF evidence_case IS NULL OR evidence_case <> NEW.case_id THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Reopen evidence must belong to its case';
    END IF;

    SELECT status INTO current_case_status
      FROM staff_discipline_cases
     WHERE id = NEW.case_id;
    IF current_case_status IS NULL OR current_case_status <> NEW.prior_case_status THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Reopen event must preserve the current prior case state';
    END IF;

    IF NEW.status = 'requested' THEN
        IF NEW.request_event_id IS NOT NULL THEN
            SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'A reopen request cannot parent itself';
        END IF;
    ELSE
        IF NEW.request_event_id IS NULL THEN
            SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'A reopen resolution must reference its request';
        END IF;
        SELECT case_id, status INTO request_case, request_status
          FROM staff_discipline_reopen_events
         WHERE id = NEW.request_event_id;
        IF request_case IS NULL OR request_case <> NEW.case_id OR request_status <> 'requested' THEN
            SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Reopen resolution must reference a request from the same case';
        END IF;
    END IF;
END
SQL);

    $db->exec('DROP TRIGGER IF EXISTS trg_staff_discipline_interim_guard_update');
    $db->exec(<<<'SQL'
CREATE TRIGGER trg_staff_discipline_interim_guard_update
BEFORE UPDATE ON staff_discipline_interim_measures
FOR EACH ROW
BEGIN
    IF OLD.status IN ('expired', 'revoked', 'completed', 'cancelled') THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Final interim measure evidence is immutable';
    END IF;

    IF NEW.status <> OLD.status AND NOT (
        (OLD.status = 'draft' AND NEW.status IN ('active', 'cancelled'))
        OR (OLD.status = 'active' AND NEW.status IN ('expired', 'revoked', 'completed'))
    ) THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Unsupported interim measure state transition';
    END IF;

    IF NEW.status = 'active'
       AND (NEW.authorized_by_user_id IS NULL OR NEW.authorized_at IS NULL) THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Active interim measure requires authorization evidence';
    END IF;

    IF NEW.status IN ('revoked', 'completed')
       AND (NEW.reviewed_at IS NULL OR NEW.reviewed_by_user_id IS NULL) THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Resolved interim measure requires reviewer evidence';
    END IF;

    IF NEW.status = 'revoked'
       AND CHAR_LENGTH(TRIM(COALESCE(NEW.resolution_reason, ''))) = 0 THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Revoked interim measure requires a resolution reason';
    END IF;
END
SQL);
};
