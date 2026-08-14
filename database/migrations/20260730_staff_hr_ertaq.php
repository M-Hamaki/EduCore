<?php

declare(strict_types=1);

/**
 * Staff-owned Ertaq employee-relations evidence.
 *
 * Ertaq is a confidential ticket and conversation workflow, not a generic
 * message table. The requester, parties, assignments, SLA events, urgent
 * protection route, and withdrawal decision are retained as separate records
 * so a later correction never erases the original report or message.
 *
 * User, organization, discipline, initiative, notification, and file IDs are
 * scalar references. Their owning modules remain responsible for validating
 * and resolving those references; this migration deliberately adds no
 * cross-module foreign key or direct write path.
 *
 * Rollback is isolated-environment only: turn off Ertaq readers/writers,
 * archive the protected evidence, then drop dependents in reverse order.
 * Production complaint history is never a rollback target.
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

    $createTable('staff_ertaq_tickets', <<<'SQL'
CREATE TABLE staff_ertaq_tickets (
    id BIGINT NOT NULL AUTO_INCREMENT,
    ticket_no VARCHAR(80) NOT NULL,
    requester_user_id INT NOT NULL,
    type ENUM('complaint','suggestion','inquiry','other') NOT NULL,
    classification VARCHAR(100) NOT NULL DEFAULT 'general',
    confidentiality_level ENUM('normal','restricted','highly_restricted') NOT NULL DEFAULT 'restricted',
    priority ENUM('low','normal','high','urgent') NOT NULL DEFAULT 'normal',
    risk_level ENUM('none','low','high','immediate') NOT NULL DEFAULT 'none',
    subject VARCHAR(500) NOT NULL,
    status ENUM('new','triaged','assigned','in_progress','awaiting_requester','resolved','closed','reopened','withdrawal_requested','urgent_protected','cancelled') NOT NULL DEFAULT 'new',
    sla_policy_id BIGINT NULL,
    sla_policy_snapshot JSON NULL,
    first_response_due_at DATETIME(6) NULL,
    sla_due_at DATETIME(6) NULL,
    urgent_route_id BIGINT NULL,
    resolution_summary TEXT NULL,
    resolved_at DATETIME(6) NULL,
    resolved_by_user_id INT NULL,
    closure_reason TEXT NULL,
    closed_at DATETIME(6) NULL,
    closed_by_user_id INT NULL,
    reopened_at DATETIME(6) NULL,
    reopened_by_user_id INT NULL,
    reopen_reason TEXT NULL,
    withdrawal_requested_at DATETIME(6) NULL,
    withdrawal_requested_by_user_id INT NULL,
    satisfaction_rating TINYINT UNSIGNED NULL,
    satisfaction_comment TEXT NULL,
    legal_hold TINYINT(1) NOT NULL DEFAULT 0,
    create_idempotency_key CHAR(64) NOT NULL,
    ticket_hash CHAR(64) NOT NULL,
    lock_version INT NOT NULL DEFAULT 1,
    created_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
    updated_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6) ON UPDATE CURRENT_TIMESTAMP(6),
    PRIMARY KEY (id),
    UNIQUE KEY uk_staff_ertaq_ticket_no (ticket_no),
    UNIQUE KEY uk_staff_ertaq_ticket_create_key (create_idempotency_key),
    KEY idx_staff_ertaq_ticket_requester (requester_user_id, status, created_at),
    KEY idx_staff_ertaq_ticket_visibility (confidentiality_level, status, created_at),
    KEY idx_staff_ertaq_ticket_sla (status, sla_due_at, first_response_due_at),
    KEY idx_staff_ertaq_ticket_priority (priority, risk_level, status, created_at),
    CONSTRAINT chk_staff_ertaq_ticket_no CHECK (CHAR_LENGTH(TRIM(ticket_no)) > 0),
    CONSTRAINT chk_staff_ertaq_ticket_subject CHECK (CHAR_LENGTH(TRIM(subject)) > 0),
    CONSTRAINT chk_staff_ertaq_ticket_hash CHECK (CHAR_LENGTH(ticket_hash) = 64),
    CONSTRAINT chk_staff_ertaq_ticket_lock CHECK (lock_version > 0),
    CONSTRAINT chk_staff_ertaq_ticket_satisfaction CHECK (
        satisfaction_rating IS NULL OR satisfaction_rating BETWEEN 1 AND 5
    ),
    CONSTRAINT chk_staff_ertaq_ticket_resolved CHECK (
        status <> 'resolved' OR resolved_at IS NOT NULL
    ),
    CONSTRAINT chk_staff_ertaq_ticket_closed CHECK (
        status <> 'closed'
        OR (
            status = 'closed'
            AND closed_at IS NOT NULL
            AND CHAR_LENGTH(TRIM(COALESCE(closure_reason, ''))) > 0
        )
    ),
    CONSTRAINT chk_staff_ertaq_ticket_reopened CHECK (
        status <> 'reopened'
        OR (
            reopened_at IS NOT NULL
            AND CHAR_LENGTH(TRIM(COALESCE(reopen_reason, ''))) > 0
        )
    ),
    CONSTRAINT chk_staff_ertaq_ticket_withdrawal CHECK (
        status <> 'withdrawal_requested'
        OR withdrawal_requested_at IS NOT NULL
    ),
    CONSTRAINT chk_staff_ertaq_ticket_urgent CHECK (
        risk_level <> 'immediate' OR priority = 'urgent'
    )
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL);

    $createTable('staff_ertaq_messages', <<<'SQL'
CREATE TABLE staff_ertaq_messages (
    id BIGINT NOT NULL AUTO_INCREMENT,
    ticket_id BIGINT NOT NULL,
    sender_user_id INT NULL,
    message_type ENUM('requester_message','team_reply','internal_note','system_event','withdrawal_request','status_update') NOT NULL,
    visibility ENUM('requester','assigned_team','restricted','protection_team') NOT NULL DEFAULT 'assigned_team',
    body_cipher_or_text MEDIUMTEXT NOT NULL,
    body_hash CHAR(64) NOT NULL,
    reply_to_message_id BIGINT NULL,
    idempotency_key CHAR(64) NOT NULL,
    sent_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
    created_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
    PRIMARY KEY (id),
    UNIQUE KEY uk_staff_ertaq_message_idempotency (idempotency_key),
    KEY idx_staff_ertaq_message_ticket (ticket_id, sent_at),
    KEY idx_staff_ertaq_message_visibility (ticket_id, visibility, sent_at),
    KEY idx_staff_ertaq_message_reply (reply_to_message_id),
    CONSTRAINT fk_staff_ertaq_message_ticket FOREIGN KEY (ticket_id) REFERENCES staff_ertaq_tickets (id) ON DELETE RESTRICT,
    CONSTRAINT fk_staff_ertaq_message_reply FOREIGN KEY (reply_to_message_id) REFERENCES staff_ertaq_messages (id) ON DELETE RESTRICT,
    CONSTRAINT chk_staff_ertaq_message_body CHECK (CHAR_LENGTH(TRIM(body_cipher_or_text)) > 0),
    CONSTRAINT chk_staff_ertaq_message_hash CHECK (CHAR_LENGTH(body_hash) = 64),
    CONSTRAINT chk_staff_ertaq_message_sender CHECK (
        sender_user_id IS NOT NULL OR message_type = 'system_event'
    )
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL);

    $createTable('staff_ertaq_parties', <<<'SQL'
CREATE TABLE staff_ertaq_parties (
    id BIGINT NOT NULL AUTO_INCREMENT,
    ticket_id BIGINT NOT NULL,
    party_user_id INT NULL,
    external_party_label VARCHAR(255) NULL,
    party_role ENUM('requester','complainant','accused','affected','witness','representative','recipient','observer','other') NOT NULL,
    visibility_scope ENUM('requester','assigned_team','restricted','protection_team') NOT NULL DEFAULT 'assigned_team',
    conflict_status ENUM('unknown','none','declared','confirmed','excluded') NOT NULL DEFAULT 'unknown',
    conflict_declared_at DATETIME(6) NULL,
    withdrawn_at DATETIME(6) NULL,
    withdrawal_reason TEXT NULL,
    added_by_user_id INT NULL,
    idempotency_key CHAR(64) NOT NULL,
    party_hash CHAR(64) NOT NULL,
    created_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
    PRIMARY KEY (id),
    UNIQUE KEY uk_staff_ertaq_party_user_role (ticket_id, party_user_id, party_role),
    UNIQUE KEY uk_staff_ertaq_party_idempotency (idempotency_key),
    KEY idx_staff_ertaq_party_ticket_visibility (ticket_id, visibility_scope, party_role),
    KEY idx_staff_ertaq_party_user (party_user_id, conflict_status),
    CONSTRAINT fk_staff_ertaq_party_ticket FOREIGN KEY (ticket_id) REFERENCES staff_ertaq_tickets (id) ON DELETE RESTRICT,
    CONSTRAINT chk_staff_ertaq_party_identity CHECK (
        (party_user_id IS NOT NULL AND external_party_label IS NULL)
        OR (
            party_user_id IS NULL
            AND CHAR_LENGTH(TRIM(COALESCE(external_party_label, ''))) > 0
        )
    ),
    CONSTRAINT chk_staff_ertaq_party_hash CHECK (CHAR_LENGTH(party_hash) = 64)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL);

    $createTable('staff_ertaq_assignments', <<<'SQL'
CREATE TABLE staff_ertaq_assignments (
    id BIGINT NOT NULL AUTO_INCREMENT,
    ticket_id BIGINT NOT NULL,
    assigned_team_id BIGINT NULL,
    assigned_to_user_id INT NULL,
    assigned_by_user_id INT NULL,
    assignment_reason TEXT NULL,
    status ENUM('active','accepted','superseded','completed','cancelled') NOT NULL DEFAULT 'active',
    assigned_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
    accepted_at DATETIME(6) NULL,
    ended_at DATETIME(6) NULL,
    ended_by_user_id INT NULL,
    end_reason TEXT NULL,
    supersedes_assignment_id BIGINT NULL,
    idempotency_key CHAR(64) NOT NULL,
    assignment_hash CHAR(64) NOT NULL,
    lock_version INT NOT NULL DEFAULT 1,
    created_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
    updated_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6) ON UPDATE CURRENT_TIMESTAMP(6),
    PRIMARY KEY (id),
    UNIQUE KEY uk_staff_ertaq_assignment_idempotency (idempotency_key),
    KEY idx_staff_ertaq_assignment_ticket (ticket_id, status, assigned_at),
    KEY idx_staff_ertaq_assignment_assignee (assigned_to_user_id, status, assigned_at),
    KEY idx_staff_ertaq_assignment_team (assigned_team_id, status, assigned_at),
    CONSTRAINT fk_staff_ertaq_assignment_ticket FOREIGN KEY (ticket_id) REFERENCES staff_ertaq_tickets (id) ON DELETE RESTRICT,
    CONSTRAINT fk_staff_ertaq_assignment_supersedes FOREIGN KEY (supersedes_assignment_id) REFERENCES staff_ertaq_assignments (id) ON DELETE RESTRICT,
    CONSTRAINT chk_staff_ertaq_assignment_target CHECK (
        assigned_team_id IS NOT NULL OR assigned_to_user_id IS NOT NULL
    ),
    CONSTRAINT chk_staff_ertaq_assignment_hash CHECK (CHAR_LENGTH(assignment_hash) = 64),
    CONSTRAINT chk_staff_ertaq_assignment_lock CHECK (lock_version > 0),
    CONSTRAINT chk_staff_ertaq_assignment_end CHECK (
        (status IN ('active','accepted') AND ended_at IS NULL)
        OR (status IN ('superseded','completed','cancelled') AND ended_at IS NOT NULL)
    )
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL);

    $createTable('staff_ertaq_watchers', <<<'SQL'
CREATE TABLE staff_ertaq_watchers (
    id BIGINT NOT NULL AUTO_INCREMENT,
    ticket_id BIGINT NOT NULL,
    watcher_user_id INT NOT NULL,
    visibility_scope ENUM('assigned_team','restricted','protection_team') NOT NULL DEFAULT 'assigned_team',
    status ENUM('active','removed') NOT NULL DEFAULT 'active',
    added_by_user_id INT NULL,
    added_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
    removed_at DATETIME(6) NULL,
    removed_by_user_id INT NULL,
    removal_reason TEXT NULL,
    watcher_hash CHAR(64) NOT NULL,
    lock_version INT NOT NULL DEFAULT 1,
    PRIMARY KEY (id),
    UNIQUE KEY uk_staff_ertaq_watcher_ticket_user (ticket_id, watcher_user_id),
    KEY idx_staff_ertaq_watcher_user (watcher_user_id, status, visibility_scope),
    CONSTRAINT fk_staff_ertaq_watcher_ticket FOREIGN KEY (ticket_id) REFERENCES staff_ertaq_tickets (id) ON DELETE RESTRICT,
    CONSTRAINT chk_staff_ertaq_watcher_hash CHECK (CHAR_LENGTH(watcher_hash) = 64),
    CONSTRAINT chk_staff_ertaq_watcher_lock CHECK (lock_version > 0),
    CONSTRAINT chk_staff_ertaq_watcher_removed CHECK (
        (status = 'active' AND removed_at IS NULL)
        OR (status = 'removed' AND removed_at IS NOT NULL)
    )
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL);

    $createTable('staff_ertaq_ticket_links', <<<'SQL'
CREATE TABLE staff_ertaq_ticket_links (
    id BIGINT NOT NULL AUTO_INCREMENT,
    ticket_id BIGINT NOT NULL,
    related_ticket_id BIGINT NULL,
    target_resource_type VARCHAR(100) NULL,
    target_resource_id BIGINT NULL,
    link_type ENUM('collective','duplicate_of','related','discipline_case','improvement_initiative','external_reference') NOT NULL,
    visibility_scope ENUM('requester','assigned_team','restricted','protection_team') NOT NULL DEFAULT 'assigned_team',
    link_reason TEXT NULL,
    linked_by_user_id INT NULL,
    link_hash CHAR(64) NOT NULL,
    idempotency_key CHAR(64) NOT NULL,
    created_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
    PRIMARY KEY (id),
    UNIQUE KEY uk_staff_ertaq_link_idempotency (idempotency_key),
    KEY idx_staff_ertaq_link_ticket (ticket_id, link_type, created_at),
    KEY idx_staff_ertaq_link_related_ticket (related_ticket_id, link_type),
    KEY idx_staff_ertaq_link_resource (target_resource_type, target_resource_id),
    CONSTRAINT fk_staff_ertaq_link_ticket FOREIGN KEY (ticket_id) REFERENCES staff_ertaq_tickets (id) ON DELETE RESTRICT,
    CONSTRAINT fk_staff_ertaq_link_related_ticket FOREIGN KEY (related_ticket_id) REFERENCES staff_ertaq_tickets (id) ON DELETE RESTRICT,
    CONSTRAINT chk_staff_ertaq_link_target CHECK (
        (
            related_ticket_id IS NOT NULL
            AND target_resource_type IS NULL
            AND target_resource_id IS NULL
            AND related_ticket_id <> ticket_id
        )
        OR (
            related_ticket_id IS NULL
            AND target_resource_type IS NOT NULL
            AND target_resource_id IS NOT NULL
        )
    ),
    CONSTRAINT chk_staff_ertaq_link_hash CHECK (CHAR_LENGTH(link_hash) = 64)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL);

    $createTable('staff_ertaq_sla_events', <<<'SQL'
CREATE TABLE staff_ertaq_sla_events (
    id BIGINT NOT NULL AUTO_INCREMENT,
    ticket_id BIGINT NOT NULL,
    event_type ENUM('created','first_response_due','response_recorded','overdue','escalated','paused','resumed','resolved','closed','reopened') NOT NULL,
    status ENUM('scheduled','fired','acknowledged','cancelled') NOT NULL DEFAULT 'scheduled',
    due_at DATETIME(6) NULL,
    occurred_at DATETIME(6) NULL,
    escalation_level INT UNSIGNED NOT NULL DEFAULT 0,
    target_team_id BIGINT NULL,
    target_user_id INT NULL,
    escalation_snapshot JSON NULL,
    event_hash CHAR(64) NOT NULL,
    idempotency_key CHAR(64) NOT NULL,
    lock_version INT NOT NULL DEFAULT 1,
    created_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
    updated_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6) ON UPDATE CURRENT_TIMESTAMP(6),
    PRIMARY KEY (id),
    UNIQUE KEY uk_staff_ertaq_sla_idempotency (idempotency_key),
    KEY idx_staff_ertaq_sla_due (status, due_at, escalation_level),
    KEY idx_staff_ertaq_sla_ticket (ticket_id, event_type, created_at),
    CONSTRAINT fk_staff_ertaq_sla_ticket FOREIGN KEY (ticket_id) REFERENCES staff_ertaq_tickets (id) ON DELETE RESTRICT,
    CONSTRAINT chk_staff_ertaq_sla_hash CHECK (CHAR_LENGTH(event_hash) = 64),
    CONSTRAINT chk_staff_ertaq_sla_lock CHECK (lock_version > 0),
    CONSTRAINT chk_staff_ertaq_sla_due CHECK (
        status <> 'scheduled' OR due_at IS NOT NULL
    )
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL);

    $createTable('staff_ertaq_urgent_events', <<<'SQL'
CREATE TABLE staff_ertaq_urgent_events (
    id BIGINT NOT NULL AUTO_INCREMENT,
    ticket_id BIGINT NOT NULL,
    risk_type VARCHAR(100) NOT NULL,
    routed_team_id BIGINT NOT NULL,
    routed_by_user_id INT NULL,
    route_snapshot JSON NOT NULL,
    conflict_exclusion_snapshot JSON NOT NULL,
    status ENUM('routed','acknowledged','resolved','cancelled') NOT NULL DEFAULT 'routed',
    routed_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
    acknowledged_at DATETIME(6) NULL,
    acknowledged_by_user_id INT NULL,
    resolved_at DATETIME(6) NULL,
    resolved_by_user_id INT NULL,
    resolution_ref VARCHAR(255) NULL,
    idempotency_key CHAR(64) NOT NULL,
    urgent_hash CHAR(64) NOT NULL,
    lock_version INT NOT NULL DEFAULT 1,
    created_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
    updated_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6) ON UPDATE CURRENT_TIMESTAMP(6),
    PRIMARY KEY (id),
    UNIQUE KEY uk_staff_ertaq_urgent_ticket (ticket_id),
    UNIQUE KEY uk_staff_ertaq_urgent_idempotency (idempotency_key),
    KEY idx_staff_ertaq_urgent_team (routed_team_id, status, routed_at),
    CONSTRAINT fk_staff_ertaq_urgent_ticket FOREIGN KEY (ticket_id) REFERENCES staff_ertaq_tickets (id) ON DELETE RESTRICT,
    CONSTRAINT chk_staff_ertaq_urgent_type CHECK (CHAR_LENGTH(TRIM(risk_type)) > 0),
    CONSTRAINT chk_staff_ertaq_urgent_hash CHECK (CHAR_LENGTH(urgent_hash) = 64),
    CONSTRAINT chk_staff_ertaq_urgent_lock CHECK (lock_version > 0),
    CONSTRAINT chk_staff_ertaq_urgent_ack CHECK (
        (status = 'routed' AND acknowledged_at IS NULL)
        OR (status IN ('acknowledged','resolved','cancelled') AND acknowledged_at IS NOT NULL)
    )
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL);

    $createTable('staff_ertaq_withdrawal_events', <<<'SQL'
CREATE TABLE staff_ertaq_withdrawal_events (
    id BIGINT NOT NULL AUTO_INCREMENT,
    ticket_id BIGINT NOT NULL,
    event_type ENUM('requested','decided') NOT NULL,
    request_event_id BIGINT NULL,
    prior_ticket_status VARCHAR(40) NULL,
    requested_by_user_id INT NULL,
    requested_at DATETIME(6) NULL,
    withdrawal_reason TEXT NULL,
    decided_by_user_id INT NULL,
    decided_at DATETIME(6) NULL,
    outcome ENUM('withdrawn','continue_processing','rejected') NULL,
    decision_reason TEXT NULL,
    event_hash CHAR(64) NOT NULL,
    idempotency_key CHAR(64) NOT NULL,
    created_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
    PRIMARY KEY (id),
    UNIQUE KEY uk_staff_ertaq_withdrawal_idempotency (idempotency_key),
    UNIQUE KEY uk_staff_ertaq_withdrawal_request_outcome (request_event_id),
    KEY idx_staff_ertaq_withdrawal_ticket (ticket_id, event_type, created_at),
    CONSTRAINT fk_staff_ertaq_withdrawal_ticket FOREIGN KEY (ticket_id) REFERENCES staff_ertaq_tickets (id) ON DELETE RESTRICT,
    CONSTRAINT fk_staff_ertaq_withdrawal_request FOREIGN KEY (request_event_id) REFERENCES staff_ertaq_withdrawal_events (id) ON DELETE RESTRICT,
    CONSTRAINT chk_staff_ertaq_withdrawal_hash CHECK (CHAR_LENGTH(event_hash) = 64),
    CONSTRAINT chk_staff_ertaq_withdrawal_event CHECK (
        (
            event_type = 'requested'
            AND request_event_id IS NULL
            AND prior_ticket_status IS NOT NULL
            AND requested_by_user_id IS NOT NULL
            AND requested_at IS NOT NULL
            AND CHAR_LENGTH(TRIM(COALESCE(withdrawal_reason, ''))) > 0
            AND outcome IS NULL
            AND decided_at IS NULL
        )
        OR (
            event_type = 'decided'
            AND request_event_id IS NOT NULL
            AND prior_ticket_status IS NULL
            AND decided_by_user_id IS NOT NULL
            AND decided_at IS NOT NULL
            AND outcome IS NOT NULL
            AND CHAR_LENGTH(TRIM(COALESCE(decision_reason, ''))) > 0
        )
    )
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL);

    $createTrigger('trg_staff_ertaq_ticket_guard_update', <<<'SQL'
CREATE TRIGGER trg_staff_ertaq_ticket_guard_update
BEFORE UPDATE ON staff_ertaq_tickets
FOR EACH ROW
BEGIN
    IF NOT (NEW.ticket_no <=> OLD.ticket_no)
        OR NOT (NEW.requester_user_id <=> OLD.requester_user_id)
        OR NOT (NEW.create_idempotency_key <=> OLD.create_idempotency_key) THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Ertaq ticket identity is immutable';
    END IF;
    IF OLD.status <> 'new' AND (
        NOT (NEW.subject <=> OLD.subject)
        OR NOT (NEW.type <=> OLD.type)
        OR NOT (NEW.confidentiality_level <=> OLD.confidentiality_level)
    ) THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Triaged Ertaq ticket subject and confidentiality are immutable';
    END IF;
    IF OLD.status = 'closed' AND NEW.status NOT IN ('closed', 'reopened') THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Closed Ertaq ticket must reopen through an audited transition';
    END IF;
    IF OLD.status = 'cancelled' AND NEW.status <> 'cancelled' THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Cancelled Ertaq ticket cannot be reactivated';
    END IF;
END
SQL);

    $createTrigger('trg_staff_ertaq_ticket_no_delete', <<<'SQL'
CREATE TRIGGER trg_staff_ertaq_ticket_no_delete
BEFORE DELETE ON staff_ertaq_tickets
FOR EACH ROW
BEGIN
    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Ertaq tickets cannot be hard deleted';
END
SQL);

    $createTrigger('trg_staff_ertaq_message_no_update', <<<'SQL'
CREATE TRIGGER trg_staff_ertaq_message_no_update
BEFORE UPDATE ON staff_ertaq_messages
FOR EACH ROW
BEGIN
    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Sent Ertaq messages are immutable';
END
SQL);

    $createTrigger('trg_staff_ertaq_message_no_delete', <<<'SQL'
CREATE TRIGGER trg_staff_ertaq_message_no_delete
BEFORE DELETE ON staff_ertaq_messages
FOR EACH ROW
BEGIN
    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Sent Ertaq messages cannot be hard deleted';
END
SQL);

    $createTrigger('trg_staff_ertaq_party_no_delete', <<<'SQL'
CREATE TRIGGER trg_staff_ertaq_party_no_delete
BEFORE DELETE ON staff_ertaq_parties
FOR EACH ROW
BEGIN
    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Ertaq parties cannot be hard deleted';
END
SQL);

    $createTrigger('trg_staff_ertaq_assignment_guard_update', <<<'SQL'
CREATE TRIGGER trg_staff_ertaq_assignment_guard_update
BEFORE UPDATE ON staff_ertaq_assignments
FOR EACH ROW
BEGIN
    IF NOT (NEW.ticket_id <=> OLD.ticket_id)
        OR NOT (NEW.assigned_team_id <=> OLD.assigned_team_id)
        OR NOT (NEW.assigned_to_user_id <=> OLD.assigned_to_user_id)
        OR NOT (NEW.assigned_at <=> OLD.assigned_at)
        OR NOT (NEW.idempotency_key <=> OLD.idempotency_key) THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Ertaq assignment identity is immutable';
    END IF;
    IF OLD.status IN ('superseded', 'completed', 'cancelled') AND NEW.status <> OLD.status THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Final Ertaq assignment cannot be reopened';
    END IF;
END
SQL);

    $createTrigger('trg_staff_ertaq_assignment_no_delete', <<<'SQL'
CREATE TRIGGER trg_staff_ertaq_assignment_no_delete
BEFORE DELETE ON staff_ertaq_assignments
FOR EACH ROW
BEGIN
    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Ertaq assignments cannot be hard deleted';
END
SQL);

    $createTrigger('trg_staff_ertaq_watcher_guard_update', <<<'SQL'
CREATE TRIGGER trg_staff_ertaq_watcher_guard_update
BEFORE UPDATE ON staff_ertaq_watchers
FOR EACH ROW
BEGIN
    IF NOT (NEW.ticket_id <=> OLD.ticket_id)
        OR NOT (NEW.watcher_user_id <=> OLD.watcher_user_id)
        OR NOT (NEW.visibility_scope <=> OLD.visibility_scope) THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Ertaq watcher identity is immutable';
    END IF;
    IF OLD.status = 'removed' AND NEW.status <> 'removed' THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Removed Ertaq watcher cannot be reactivated';
    END IF;
END
SQL);

    $createTrigger('trg_staff_ertaq_watcher_no_delete', <<<'SQL'
CREATE TRIGGER trg_staff_ertaq_watcher_no_delete
BEFORE DELETE ON staff_ertaq_watchers
FOR EACH ROW
BEGIN
    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Ertaq watchers cannot be hard deleted';
END
SQL);

    $createTrigger('trg_staff_ertaq_link_no_update', <<<'SQL'
CREATE TRIGGER trg_staff_ertaq_link_no_update
BEFORE UPDATE ON staff_ertaq_ticket_links
FOR EACH ROW
BEGIN
    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Ertaq links are immutable';
END
SQL);

    $createTrigger('trg_staff_ertaq_link_no_delete', <<<'SQL'
CREATE TRIGGER trg_staff_ertaq_link_no_delete
BEFORE DELETE ON staff_ertaq_ticket_links
FOR EACH ROW
BEGIN
    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Ertaq links cannot be hard deleted';
END
SQL);

    $createTrigger('trg_staff_ertaq_sla_guard_update', <<<'SQL'
CREATE TRIGGER trg_staff_ertaq_sla_guard_update
BEFORE UPDATE ON staff_ertaq_sla_events
FOR EACH ROW
BEGIN
    IF NOT (NEW.ticket_id <=> OLD.ticket_id)
        OR NOT (NEW.event_type <=> OLD.event_type)
        OR NOT (NEW.due_at <=> OLD.due_at)
        OR NOT (NEW.idempotency_key <=> OLD.idempotency_key) THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Ertaq SLA event identity is immutable';
    END IF;
    IF OLD.status IN ('fired', 'acknowledged', 'cancelled') AND NEW.status <> OLD.status THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Final Ertaq SLA event cannot be reopened';
    END IF;
END
SQL);

    $createTrigger('trg_staff_ertaq_sla_no_delete', <<<'SQL'
CREATE TRIGGER trg_staff_ertaq_sla_no_delete
BEFORE DELETE ON staff_ertaq_sla_events
FOR EACH ROW
BEGIN
    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Ertaq SLA events cannot be hard deleted';
END
SQL);

    $createTrigger('trg_staff_ertaq_urgent_guard_update', <<<'SQL'
CREATE TRIGGER trg_staff_ertaq_urgent_guard_update
BEFORE UPDATE ON staff_ertaq_urgent_events
FOR EACH ROW
BEGIN
    IF NOT (NEW.ticket_id <=> OLD.ticket_id)
        OR NOT (NEW.risk_type <=> OLD.risk_type)
        OR NOT (NEW.routed_team_id <=> OLD.routed_team_id)
        OR NOT (NEW.route_snapshot <=> OLD.route_snapshot)
        OR NOT (NEW.conflict_exclusion_snapshot <=> OLD.conflict_exclusion_snapshot)
        OR NOT (NEW.idempotency_key <=> OLD.idempotency_key) THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Ertaq urgent route identity is immutable';
    END IF;
    IF OLD.status IN ('resolved', 'cancelled') AND NEW.status <> OLD.status THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Final Ertaq urgent route cannot be reopened';
    END IF;
END
SQL);

    $createTrigger('trg_staff_ertaq_urgent_no_delete', <<<'SQL'
CREATE TRIGGER trg_staff_ertaq_urgent_no_delete
BEFORE DELETE ON staff_ertaq_urgent_events
FOR EACH ROW
BEGIN
    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Ertaq urgent routes cannot be hard deleted';
END
SQL);

    $createTrigger('trg_staff_ertaq_withdrawal_no_update', <<<'SQL'
CREATE TRIGGER trg_staff_ertaq_withdrawal_no_update
BEFORE UPDATE ON staff_ertaq_withdrawal_events
FOR EACH ROW
BEGIN
    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Ertaq withdrawal evidence is immutable';
END
SQL);

    $createTrigger('trg_staff_ertaq_withdrawal_no_delete', <<<'SQL'
CREATE TRIGGER trg_staff_ertaq_withdrawal_no_delete
BEFORE DELETE ON staff_ertaq_withdrawal_events
FOR EACH ROW
BEGIN
    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Ertaq withdrawal evidence cannot be hard deleted';
END
SQL);
};
