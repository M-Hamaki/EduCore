<?php

declare(strict_types=1);

/**
 * Staff-owned discipline case evidence.
 *
 * A discipline record is never a mutable "penalty row": the incident,
 * investigation, evidence, decision, appeal, execution, reopening, and
 * Finance intent are separate records. User, workflow, attendance,
 * notification, and Finance identifiers deliberately remain scalar references
 * because those modules own their lifecycle and deploy independently.
 *
 * Rollback is isolated-environment only: switch the feature readers/writers
 * off, archive the legal/audit evidence, drop triggers, then drop dependent
 * tables in reverse order. Production case history is not a rollback target.
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

    $createTable('staff_discipline_incidents', <<<'SQL'
CREATE TABLE staff_discipline_incidents (
    id BIGINT NOT NULL AUTO_INCREMENT,
    incident_no VARCHAR(80) NOT NULL,
    subject_staff_user_id INT NULL,
    reported_by_user_id INT NULL,
    occurred_at DATETIME(6) NULL,
    reported_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
    source_resource_type VARCHAR(100) NULL,
    source_resource_id BIGINT NULL,
    source_reference_snapshot JSON NULL,
    classification VARCHAR(100) NOT NULL DEFAULT 'general',
    confidentiality_level ENUM('normal','restricted','highly_restricted') NOT NULL DEFAULT 'restricted',
    description TEXT NULL,
    status ENUM('draft','reported','triage','cancelled') NOT NULL DEFAULT 'draft',
    cancellation_reason TEXT NULL,
    cancelled_by_user_id INT NULL,
    cancelled_at DATETIME(6) NULL,
    create_idempotency_key CHAR(64) NOT NULL,
    incident_hash CHAR(64) NOT NULL,
    lock_version INT NOT NULL DEFAULT 1,
    created_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
    updated_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6) ON UPDATE CURRENT_TIMESTAMP(6),
    PRIMARY KEY (id),
    UNIQUE KEY uk_staff_discipline_incident_no (incident_no),
    UNIQUE KEY uk_staff_discipline_incident_create_key (create_idempotency_key),
    KEY idx_staff_discipline_incident_subject (subject_staff_user_id, status, occurred_at),
    KEY idx_staff_discipline_incident_source (source_resource_type, source_resource_id),
    KEY idx_staff_discipline_incident_confidentiality (confidentiality_level, status, reported_at),
    CONSTRAINT chk_staff_discipline_incident_no CHECK (CHAR_LENGTH(TRIM(incident_no)) > 0),
    CONSTRAINT chk_staff_discipline_incident_hash CHECK (CHAR_LENGTH(incident_hash) = 64),
    CONSTRAINT chk_staff_discipline_incident_lock CHECK (lock_version > 0),
    CONSTRAINT chk_staff_discipline_incident_cancelled CHECK (
        (status <> 'cancelled' AND cancellation_reason IS NULL AND cancelled_at IS NULL)
        OR (
            status = 'cancelled'
            AND CHAR_LENGTH(TRIM(COALESCE(cancellation_reason, ''))) > 0
            AND cancelled_at IS NOT NULL
        )
    )
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL);

    $createTable('staff_discipline_cases', <<<'SQL'
CREATE TABLE staff_discipline_cases (
    id BIGINT NOT NULL AUTO_INCREMENT,
    case_no VARCHAR(80) NOT NULL,
    incident_id BIGINT NOT NULL,
    subject_staff_user_id INT NOT NULL,
    classification VARCHAR(100) NOT NULL DEFAULT 'general',
    confidentiality_level ENUM('normal','restricted','highly_restricted') NOT NULL DEFAULT 'restricted',
    status ENUM('reported','triage','under_investigation','pending_decision','decided','appeal_pending','upheld','amended','revoked','closed','reopened','cancelled') NOT NULL DEFAULT 'reported',
    opened_by_user_id INT NULL,
    opened_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
    closed_by_user_id INT NULL,
    closed_at DATETIME(6) NULL,
    cancellation_reason TEXT NULL,
    cancelled_by_user_id INT NULL,
    cancelled_at DATETIME(6) NULL,
    legal_hold TINYINT(1) NOT NULL DEFAULT 0,
    create_idempotency_key CHAR(64) NOT NULL,
    case_hash CHAR(64) NOT NULL,
    lock_version INT NOT NULL DEFAULT 1,
    created_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
    updated_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6) ON UPDATE CURRENT_TIMESTAMP(6),
    PRIMARY KEY (id),
    UNIQUE KEY uk_staff_discipline_case_no (case_no),
    UNIQUE KEY uk_staff_discipline_case_create_key (create_idempotency_key),
    UNIQUE KEY uk_staff_discipline_case_incident (incident_id),
    KEY idx_staff_discipline_case_subject (subject_staff_user_id, status, opened_at),
    KEY idx_staff_discipline_case_visibility (confidentiality_level, status, opened_at),
    CONSTRAINT fk_staff_discipline_case_incident FOREIGN KEY (incident_id) REFERENCES staff_discipline_incidents (id) ON DELETE RESTRICT,
    CONSTRAINT chk_staff_discipline_case_no CHECK (CHAR_LENGTH(TRIM(case_no)) > 0),
    CONSTRAINT chk_staff_discipline_case_hash CHECK (CHAR_LENGTH(case_hash) = 64),
    CONSTRAINT chk_staff_discipline_case_lock CHECK (lock_version > 0),
    CONSTRAINT chk_staff_discipline_case_closed CHECK (
        status <> 'closed' OR closed_at IS NOT NULL
    ),
    CONSTRAINT chk_staff_discipline_case_cancelled CHECK (
        (status <> 'cancelled' AND cancellation_reason IS NULL AND cancelled_at IS NULL)
        OR (
            status = 'cancelled'
            AND CHAR_LENGTH(TRIM(COALESCE(cancellation_reason, ''))) > 0
            AND cancelled_at IS NOT NULL
        )
    )
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL);

    $createTable('staff_discipline_case_parties', <<<'SQL'
CREATE TABLE staff_discipline_case_parties (
    id BIGINT NOT NULL AUTO_INCREMENT,
    case_id BIGINT NOT NULL,
    party_user_id INT NULL,
    external_party_label VARCHAR(255) NULL,
    party_role ENUM('subject','reporter','complainant','respondent','witness','representative','observer','other') NOT NULL,
    visibility_scope ENUM('case_team','decision_team','restricted','subject_only') NOT NULL DEFAULT 'case_team',
    conflict_declared_at DATETIME(6) NULL,
    conflict_declaration TEXT NULL,
    status ENUM('active','withdrawn','excluded') NOT NULL DEFAULT 'active',
    added_by_user_id INT NULL,
    withdrawn_by_user_id INT NULL,
    withdrawn_at DATETIME(6) NULL,
    withdrawal_reason TEXT NULL,
    idempotency_key CHAR(64) NOT NULL,
    party_hash CHAR(64) NOT NULL,
    lock_version INT NOT NULL DEFAULT 1,
    created_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
    updated_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6) ON UPDATE CURRENT_TIMESTAMP(6),
    PRIMARY KEY (id),
    UNIQUE KEY uk_staff_discipline_party_idempotency (idempotency_key),
    UNIQUE KEY uk_staff_discipline_party_unique (case_id, party_user_id, party_role),
    KEY idx_staff_discipline_party_case (case_id, status, party_role),
    CONSTRAINT fk_staff_discipline_party_case FOREIGN KEY (case_id) REFERENCES staff_discipline_cases (id) ON DELETE RESTRICT,
    CONSTRAINT chk_staff_discipline_party_identity CHECK (
        (party_user_id IS NOT NULL AND external_party_label IS NULL)
        OR (party_user_id IS NULL AND CHAR_LENGTH(TRIM(COALESCE(external_party_label, ''))) > 0)
    ),
    CONSTRAINT chk_staff_discipline_party_conflict CHECK (
        (conflict_declared_at IS NULL AND conflict_declaration IS NULL)
        OR (conflict_declared_at IS NOT NULL AND CHAR_LENGTH(TRIM(COALESCE(conflict_declaration, ''))) > 0)
    ),
    CONSTRAINT chk_staff_discipline_party_withdrawn CHECK (
        (status = 'active' AND withdrawn_at IS NULL AND withdrawal_reason IS NULL)
        OR (
            status <> 'active'
            AND withdrawn_at IS NOT NULL
            AND CHAR_LENGTH(TRIM(COALESCE(withdrawal_reason, ''))) > 0
        )
    ),
    CONSTRAINT chk_staff_discipline_party_hash CHECK (CHAR_LENGTH(party_hash) = 64),
    CONSTRAINT chk_staff_discipline_party_lock CHECK (lock_version > 0)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL);

    $createTable('staff_discipline_investigations', <<<'SQL'
CREATE TABLE staff_discipline_investigations (
    id BIGINT NOT NULL AUTO_INCREMENT,
    case_id BIGINT NOT NULL,
    investigator_user_id INT NULL,
    assigned_by_user_id INT NULL,
    assigned_at DATETIME(6) NULL,
    started_at DATETIME(6) NULL,
    completed_at DATETIME(6) NULL,
    status ENUM('draft','assigned','in_progress','completed','cancelled','superseded') NOT NULL DEFAULT 'draft',
    allegation TEXT NULL,
    investigation_notes TEXT NULL,
    findings TEXT NULL,
    recommendation TEXT NULL,
    confidentiality_level ENUM('normal','restricted','highly_restricted') NOT NULL DEFAULT 'restricted',
    idempotency_key CHAR(64) NOT NULL,
    investigation_hash CHAR(64) NOT NULL,
    lock_version INT NOT NULL DEFAULT 1,
    created_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
    updated_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6) ON UPDATE CURRENT_TIMESTAMP(6),
    PRIMARY KEY (id),
    UNIQUE KEY uk_staff_discipline_investigation_idempotency (idempotency_key),
    KEY idx_staff_discipline_investigation_case (case_id, status, assigned_at),
    KEY idx_staff_discipline_investigator (investigator_user_id, status, assigned_at),
    CONSTRAINT fk_staff_discipline_investigation_case FOREIGN KEY (case_id) REFERENCES staff_discipline_cases (id) ON DELETE RESTRICT,
    CONSTRAINT chk_staff_discipline_investigation_hash CHECK (CHAR_LENGTH(investigation_hash) = 64),
    CONSTRAINT chk_staff_discipline_investigation_lock CHECK (lock_version > 0),
    CONSTRAINT chk_staff_discipline_investigation_assignment CHECK (
        (status = 'draft' AND investigator_user_id IS NULL AND assigned_at IS NULL)
        OR (status <> 'draft' AND investigator_user_id IS NOT NULL AND assigned_at IS NOT NULL)
    ),
    CONSTRAINT chk_staff_discipline_investigation_completed CHECK (
        (status <> 'completed' AND completed_at IS NULL)
        OR (status = 'completed' AND completed_at IS NOT NULL)
    )
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL);

    $createTable('staff_discipline_evidence', <<<'SQL'
CREATE TABLE staff_discipline_evidence (
    id BIGINT NOT NULL AUTO_INCREMENT,
    case_id BIGINT NOT NULL,
    investigation_id BIGINT NULL,
    prior_evidence_id BIGINT NULL,
    evidence_kind ENUM('statement','attendance_reference','complaint_reference','document_reference','private_attachment','physical_item','other') NOT NULL,
    source_resource_type VARCHAR(100) NULL,
    source_resource_id BIGINT NULL,
    storage_area ENUM('private') NOT NULL DEFAULT 'private',
    storage_ref VARCHAR(500) NULL,
    original_name VARCHAR(255) NULL,
    mime_type VARCHAR(150) NULL,
    byte_size BIGINT NULL,
    content_sha256 CHAR(64) NULL,
    chain_hash CHAR(64) NOT NULL,
    evidence_summary TEXT NULL,
    collected_by_user_id INT NULL,
    collected_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
    retention_until DATE NULL,
    legal_hold TINYINT(1) NOT NULL DEFAULT 0,
    status ENUM('collected','verified','excluded','superseded') NOT NULL DEFAULT 'collected',
    idempotency_key CHAR(64) NOT NULL,
    created_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
    PRIMARY KEY (id),
    UNIQUE KEY uk_staff_discipline_evidence_idempotency (idempotency_key),
    UNIQUE KEY uk_staff_discipline_evidence_chain_hash (chain_hash),
    KEY idx_staff_discipline_evidence_case (case_id, collected_at),
    KEY idx_staff_discipline_evidence_investigation (investigation_id, collected_at),
    KEY idx_staff_discipline_evidence_source (source_resource_type, source_resource_id),
    CONSTRAINT fk_staff_discipline_evidence_case FOREIGN KEY (case_id) REFERENCES staff_discipline_cases (id) ON DELETE RESTRICT,
    CONSTRAINT fk_staff_discipline_evidence_investigation FOREIGN KEY (investigation_id) REFERENCES staff_discipline_investigations (id) ON DELETE RESTRICT,
    CONSTRAINT fk_staff_discipline_evidence_prior FOREIGN KEY (prior_evidence_id) REFERENCES staff_discipline_evidence (id) ON DELETE RESTRICT,
    CONSTRAINT chk_staff_discipline_evidence_chain CHECK (CHAR_LENGTH(chain_hash) = 64),
    CONSTRAINT chk_staff_discipline_evidence_storage CHECK (
        (storage_ref IS NULL AND original_name IS NULL AND mime_type IS NULL AND byte_size IS NULL AND content_sha256 IS NULL)
        OR (
            CHAR_LENGTH(TRIM(COALESCE(storage_ref, ''))) > 0
            AND CHAR_LENGTH(TRIM(COALESCE(original_name, ''))) > 0
            AND CHAR_LENGTH(TRIM(COALESCE(mime_type, ''))) > 0
            AND byte_size >= 0
            AND CHAR_LENGTH(COALESCE(content_sha256, '')) = 64
        )
    )
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL);

    $createTable('staff_discipline_interim_measures', <<<'SQL'
CREATE TABLE staff_discipline_interim_measures (
    id BIGINT NOT NULL AUTO_INCREMENT,
    case_id BIGINT NOT NULL,
    basis_evidence_id BIGINT NULL,
    measure_type VARCHAR(100) NOT NULL,
    status ENUM('draft','active','expired','revoked','completed','cancelled') NOT NULL DEFAULT 'draft',
    reason TEXT NULL,
    access_effect JSON NULL,
    requested_by_user_id INT NULL,
    authorized_by_user_id INT NULL,
    authorized_at DATETIME(6) NULL,
    starts_at DATETIME(6) NULL,
    ends_at DATETIME(6) NULL,
    review_due_at DATETIME(6) NULL,
    reviewed_by_user_id INT NULL,
    reviewed_at DATETIME(6) NULL,
    idempotency_key CHAR(64) NOT NULL,
    measure_hash CHAR(64) NOT NULL,
    lock_version INT NOT NULL DEFAULT 1,
    created_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
    updated_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6) ON UPDATE CURRENT_TIMESTAMP(6),
    PRIMARY KEY (id),
    UNIQUE KEY uk_staff_discipline_interim_idempotency (idempotency_key),
    KEY idx_staff_discipline_interim_case (case_id, status, starts_at, ends_at),
    KEY idx_staff_discipline_interim_review (status, review_due_at),
    CONSTRAINT fk_staff_discipline_interim_case FOREIGN KEY (case_id) REFERENCES staff_discipline_cases (id) ON DELETE RESTRICT,
    CONSTRAINT fk_staff_discipline_interim_evidence FOREIGN KEY (basis_evidence_id) REFERENCES staff_discipline_evidence (id) ON DELETE RESTRICT,
    CONSTRAINT chk_staff_discipline_interim_type CHECK (CHAR_LENGTH(TRIM(measure_type)) > 0),
    CONSTRAINT chk_staff_discipline_interim_hash CHECK (CHAR_LENGTH(measure_hash) = 64),
    CONSTRAINT chk_staff_discipline_interim_lock CHECK (lock_version > 0),
    CONSTRAINT chk_staff_discipline_interim_window CHECK (
        (starts_at IS NULL AND ends_at IS NULL)
        OR (starts_at IS NOT NULL AND ends_at IS NOT NULL AND ends_at > starts_at)
    ),
    CONSTRAINT chk_staff_discipline_interim_authorization CHECK (
        (status = 'draft' AND authorized_at IS NULL)
        OR (status <> 'draft' AND authorized_by_user_id IS NOT NULL AND authorized_at IS NOT NULL)
    )
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL);

    $createTable('staff_discipline_decisions', <<<'SQL'
CREATE TABLE staff_discipline_decisions (
    id BIGINT NOT NULL AUTO_INCREMENT,
    case_id BIGINT NOT NULL,
    investigation_id BIGINT NULL,
    supersedes_decision_id BIGINT NULL,
    decision_no VARCHAR(80) NOT NULL,
    decision_sequence INT NOT NULL,
    sanction_code VARCHAR(100) NULL,
    status ENUM('draft','proposed','approved','issued','amended','revoked','superseded','cancelled') NOT NULL DEFAULT 'draft',
    prepared_by_user_id INT NULL,
    decided_by_user_id INT NULL,
    decided_at DATETIME(6) NULL,
    issued_at DATETIME(6) NULL,
    effective_from DATETIME(6) NULL,
    effective_to DATETIME(6) NULL,
    decision_reason TEXT NULL,
    policy_snapshot JSON NULL,
    workflow_instance_id BIGINT NULL,
    notification_status ENUM('not_required','pending','sent','received','delivery_failed') NOT NULL DEFAULT 'pending',
    notification_reference VARCHAR(191) NULL,
    notified_at DATETIME(6) NULL,
    receipt_at DATETIME(6) NULL,
    financial_effect_requested TINYINT(1) NOT NULL DEFAULT 0,
    decision_hash CHAR(64) NOT NULL,
    idempotency_key CHAR(64) NOT NULL,
    lock_version INT NOT NULL DEFAULT 1,
    created_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
    updated_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6) ON UPDATE CURRENT_TIMESTAMP(6),
    PRIMARY KEY (id),
    UNIQUE KEY uk_staff_discipline_decision_no (decision_no),
    UNIQUE KEY uk_staff_discipline_decision_case_sequence (case_id, decision_sequence),
    UNIQUE KEY uk_staff_discipline_decision_idempotency (idempotency_key),
    UNIQUE KEY uk_staff_discipline_decision_supersedes (supersedes_decision_id),
    KEY idx_staff_discipline_decision_case (case_id, status, issued_at),
    KEY idx_staff_discipline_decision_workflow (workflow_instance_id, status),
    CONSTRAINT fk_staff_discipline_decision_case FOREIGN KEY (case_id) REFERENCES staff_discipline_cases (id) ON DELETE RESTRICT,
    CONSTRAINT fk_staff_discipline_decision_investigation FOREIGN KEY (investigation_id) REFERENCES staff_discipline_investigations (id) ON DELETE RESTRICT,
    CONSTRAINT fk_staff_discipline_decision_previous FOREIGN KEY (supersedes_decision_id) REFERENCES staff_discipline_decisions (id) ON DELETE RESTRICT,
    CONSTRAINT chk_staff_discipline_decision_no CHECK (CHAR_LENGTH(TRIM(decision_no)) > 0),
    CONSTRAINT chk_staff_discipline_decision_sequence CHECK (decision_sequence > 0),
    CONSTRAINT chk_staff_discipline_decision_hash CHECK (CHAR_LENGTH(decision_hash) = 64),
    CONSTRAINT chk_staff_discipline_decision_lock CHECK (lock_version > 0),
    CONSTRAINT chk_staff_discipline_decision_window CHECK (effective_to IS NULL OR effective_from IS NULL OR effective_to > effective_from),
    CONSTRAINT chk_staff_discipline_decision_issued CHECK (
        status NOT IN ('issued','amended','revoked','superseded')
        OR (decided_by_user_id IS NOT NULL AND decided_at IS NOT NULL AND issued_at IS NOT NULL)
    )
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL);

    $createTable('staff_discipline_appeals', <<<'SQL'
CREATE TABLE staff_discipline_appeals (
    id BIGINT NOT NULL AUTO_INCREMENT,
    case_id BIGINT NOT NULL,
    decision_id BIGINT NOT NULL,
    appellant_user_id INT NOT NULL,
    reviewer_user_id INT NULL,
    status ENUM('draft','submitted','under_review','upheld','amended','revoked','withdrawn','expired','closed') NOT NULL DEFAULT 'draft',
    submitted_at DATETIME(6) NULL,
    due_at DATETIME(6) NULL,
    reviewed_at DATETIME(6) NULL,
    appeal_reason TEXT NULL,
    outcome_reason TEXT NULL,
    suspends_execution TINYINT(1) NOT NULL DEFAULT 0,
    suspension_reason TEXT NULL,
    idempotency_key CHAR(64) NOT NULL,
    appeal_hash CHAR(64) NOT NULL,
    lock_version INT NOT NULL DEFAULT 1,
    created_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
    updated_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6) ON UPDATE CURRENT_TIMESTAMP(6),
    PRIMARY KEY (id),
    UNIQUE KEY uk_staff_discipline_appeal_idempotency (idempotency_key),
    UNIQUE KEY uk_staff_discipline_appeal_active (decision_id, appellant_user_id, status),
    KEY idx_staff_discipline_appeal_case (case_id, status, due_at),
    KEY idx_staff_discipline_appeal_reviewer (reviewer_user_id, status, due_at),
    CONSTRAINT fk_staff_discipline_appeal_case FOREIGN KEY (case_id) REFERENCES staff_discipline_cases (id) ON DELETE RESTRICT,
    CONSTRAINT fk_staff_discipline_appeal_decision FOREIGN KEY (decision_id) REFERENCES staff_discipline_decisions (id) ON DELETE RESTRICT,
    CONSTRAINT chk_staff_discipline_appeal_hash CHECK (CHAR_LENGTH(appeal_hash) = 64),
    CONSTRAINT chk_staff_discipline_appeal_lock CHECK (lock_version > 0),
    CONSTRAINT chk_staff_discipline_appeal_submission CHECK (
        (status = 'draft' AND submitted_at IS NULL)
        OR (status <> 'draft' AND submitted_at IS NOT NULL)
    ),
    CONSTRAINT chk_staff_discipline_appeal_suspension CHECK (
        (suspends_execution = 0 AND suspension_reason IS NULL)
        OR (suspends_execution = 1 AND CHAR_LENGTH(TRIM(COALESCE(suspension_reason, ''))) > 0)
    )
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL);

    $createTable('staff_discipline_executions', <<<'SQL'
CREATE TABLE staff_discipline_executions (
    id BIGINT NOT NULL AUTO_INCREMENT,
    case_id BIGINT NOT NULL,
    decision_id BIGINT NOT NULL,
    appeal_id BIGINT NULL,
    reverses_execution_id BIGINT NULL,
    execution_no VARCHAR(80) NOT NULL,
    effect_target ENUM('attendance','access','notification','finance','other') NOT NULL,
    status ENUM('planned','executed','suspended','reversed','cancelled') NOT NULL DEFAULT 'planned',
    execution_payload JSON NULL,
    executed_by_user_id INT NULL,
    executed_at DATETIME(6) NULL,
    suspended_by_user_id INT NULL,
    suspended_at DATETIME(6) NULL,
    suspension_reason TEXT NULL,
    idempotency_key CHAR(64) NOT NULL,
    execution_hash CHAR(64) NOT NULL,
    lock_version INT NOT NULL DEFAULT 1,
    created_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
    updated_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6) ON UPDATE CURRENT_TIMESTAMP(6),
    PRIMARY KEY (id),
    UNIQUE KEY uk_staff_discipline_execution_no (execution_no),
    UNIQUE KEY uk_staff_discipline_execution_idempotency (idempotency_key),
    UNIQUE KEY uk_staff_discipline_execution_reverse (reverses_execution_id),
    KEY idx_staff_discipline_execution_case (case_id, status, executed_at),
    KEY idx_staff_discipline_execution_decision (decision_id, status, executed_at),
    CONSTRAINT fk_staff_discipline_execution_case FOREIGN KEY (case_id) REFERENCES staff_discipline_cases (id) ON DELETE RESTRICT,
    CONSTRAINT fk_staff_discipline_execution_decision FOREIGN KEY (decision_id) REFERENCES staff_discipline_decisions (id) ON DELETE RESTRICT,
    CONSTRAINT fk_staff_discipline_execution_appeal FOREIGN KEY (appeal_id) REFERENCES staff_discipline_appeals (id) ON DELETE RESTRICT,
    CONSTRAINT fk_staff_discipline_execution_reverse FOREIGN KEY (reverses_execution_id) REFERENCES staff_discipline_executions (id) ON DELETE RESTRICT,
    CONSTRAINT chk_staff_discipline_execution_no CHECK (CHAR_LENGTH(TRIM(execution_no)) > 0),
    CONSTRAINT chk_staff_discipline_execution_hash CHECK (CHAR_LENGTH(execution_hash) = 64),
    CONSTRAINT chk_staff_discipline_execution_lock CHECK (lock_version > 0),
    CONSTRAINT chk_staff_discipline_execution_done CHECK (
        status <> 'executed' OR (executed_by_user_id IS NOT NULL AND executed_at IS NOT NULL)
    ),
    CONSTRAINT chk_staff_discipline_execution_suspended CHECK (
        (status <> 'suspended' AND suspended_at IS NULL)
        OR (
            status = 'suspended'
            AND suspended_by_user_id IS NOT NULL
            AND suspended_at IS NOT NULL
            AND CHAR_LENGTH(TRIM(COALESCE(suspension_reason, ''))) > 0
        )
    )
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL);

    $createTable('staff_discipline_finance_effects', <<<'SQL'
CREATE TABLE staff_discipline_finance_effects (
    id BIGINT NOT NULL AUTO_INCREMENT,
    case_id BIGINT NOT NULL,
    decision_id BIGINT NOT NULL,
    execution_id BIGINT NULL,
    reverses_effect_id BIGINT NULL,
    target_module ENUM('finance') NOT NULL DEFAULT 'finance',
    fact_type VARCHAR(100) NOT NULL,
    effect_code VARCHAR(100) NOT NULL,
    effect_key CHAR(64) NOT NULL,
    idempotency_key CHAR(64) NOT NULL,
    direction ENUM('apply','reverse') NOT NULL,
    effective_from DATE NOT NULL,
    effective_to DATE NULL,
    units DECIMAL(12,3) NOT NULL DEFAULT 0,
    payload_json JSON NULL,
    status ENUM('pending','processing','accepted','retry','rejected','cancelled') NOT NULL DEFAULT 'pending',
    attempt_count INT NOT NULL DEFAULT 0,
    next_attempt_at DATETIME(6) NULL,
    lease_token CHAR(64) NULL,
    lease_expires_at DATETIME(6) NULL,
    accepted_reference VARCHAR(191) NULL,
    accepted_at DATETIME(6) NULL,
    last_error_code VARCHAR(100) NULL,
    created_by_user_id INT NULL,
    created_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
    updated_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6) ON UPDATE CURRENT_TIMESTAMP(6),
    PRIMARY KEY (id),
    UNIQUE KEY uk_staff_discipline_finance_effect_key (effect_key),
    UNIQUE KEY uk_staff_discipline_finance_idempotency (idempotency_key),
    UNIQUE KEY uk_staff_discipline_finance_reverse (reverses_effect_id),
    KEY idx_staff_discipline_finance_due (status, next_attempt_at, lease_expires_at),
    KEY idx_staff_discipline_finance_decision (decision_id, status, effective_from),
    CONSTRAINT fk_staff_discipline_finance_case FOREIGN KEY (case_id) REFERENCES staff_discipline_cases (id) ON DELETE RESTRICT,
    CONSTRAINT fk_staff_discipline_finance_decision FOREIGN KEY (decision_id) REFERENCES staff_discipline_decisions (id) ON DELETE RESTRICT,
    CONSTRAINT fk_staff_discipline_finance_execution FOREIGN KEY (execution_id) REFERENCES staff_discipline_executions (id) ON DELETE RESTRICT,
    CONSTRAINT fk_staff_discipline_finance_reverse FOREIGN KEY (reverses_effect_id) REFERENCES staff_discipline_finance_effects (id) ON DELETE RESTRICT,
    CONSTRAINT chk_staff_discipline_finance_type CHECK (
        CHAR_LENGTH(TRIM(fact_type)) > 0 AND CHAR_LENGTH(TRIM(effect_code)) > 0
    ),
    CONSTRAINT chk_staff_discipline_finance_keys CHECK (
        CHAR_LENGTH(effect_key) = 64 AND CHAR_LENGTH(idempotency_key) = 64
    ),
    CONSTRAINT chk_staff_discipline_finance_window CHECK (effective_to IS NULL OR effective_to >= effective_from),
    CONSTRAINT chk_staff_discipline_finance_attempts CHECK (attempt_count >= 0),
    CONSTRAINT chk_staff_discipline_finance_reverse CHECK (
        (direction = 'apply' AND reverses_effect_id IS NULL)
        OR (direction = 'reverse' AND reverses_effect_id IS NOT NULL)
    )
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL);

    $createTable('staff_discipline_reopen_events', <<<'SQL'
CREATE TABLE staff_discipline_reopen_events (
    id BIGINT NOT NULL AUTO_INCREMENT,
    case_id BIGINT NOT NULL,
    prior_decision_id BIGINT NULL,
    new_evidence_id BIGINT NOT NULL,
    prior_case_status ENUM('decided','appeal_pending','upheld','amended','revoked','closed') NOT NULL,
    status ENUM('requested','authorized','rejected','completed') NOT NULL DEFAULT 'requested',
    requested_by_user_id INT NULL,
    requested_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
    authorized_by_user_id INT NULL,
    authorized_at DATETIME(6) NULL,
    reopen_reason TEXT NULL,
    idempotency_key CHAR(64) NOT NULL,
    reopen_hash CHAR(64) NOT NULL,
    created_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
    PRIMARY KEY (id),
    UNIQUE KEY uk_staff_discipline_reopen_idempotency (idempotency_key),
    KEY idx_staff_discipline_reopen_case (case_id, status, requested_at),
    CONSTRAINT fk_staff_discipline_reopen_case FOREIGN KEY (case_id) REFERENCES staff_discipline_cases (id) ON DELETE RESTRICT,
    CONSTRAINT fk_staff_discipline_reopen_decision FOREIGN KEY (prior_decision_id) REFERENCES staff_discipline_decisions (id) ON DELETE RESTRICT,
    CONSTRAINT fk_staff_discipline_reopen_evidence FOREIGN KEY (new_evidence_id) REFERENCES staff_discipline_evidence (id) ON DELETE RESTRICT,
    CONSTRAINT chk_staff_discipline_reopen_hash CHECK (CHAR_LENGTH(reopen_hash) = 64),
    CONSTRAINT chk_staff_discipline_reopen_reason CHECK (CHAR_LENGTH(TRIM(COALESCE(reopen_reason, ''))) > 0),
    CONSTRAINT chk_staff_discipline_reopen_authorized CHECK (
        (status IN ('requested','rejected') AND authorized_at IS NULL)
        OR (status IN ('authorized','completed') AND authorized_by_user_id IS NOT NULL AND authorized_at IS NOT NULL)
    )
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL);

    $createTrigger('trg_staff_discipline_incident_no_delete', <<<'SQL'
CREATE TRIGGER trg_staff_discipline_incident_no_delete
BEFORE DELETE ON staff_discipline_incidents
FOR EACH ROW
BEGIN
    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Discipline incidents are cancelled, never deleted';
END
SQL);

    $createTrigger('trg_staff_discipline_case_guard_update', <<<'SQL'
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
            OR (OLD.status = 'appeal_pending' AND NEW.status IN ('upheld', 'amended', 'revoked'))
            OR (OLD.status IN ('upheld', 'amended', 'revoked') AND NEW.status = 'closed')
            OR (OLD.status = 'closed' AND NEW.status = 'reopened')
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

    $createTrigger('trg_staff_discipline_case_no_delete', <<<'SQL'
CREATE TRIGGER trg_staff_discipline_case_no_delete
BEFORE DELETE ON staff_discipline_cases
FOR EACH ROW
BEGIN
    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Discipline cases are closed or cancelled, never deleted';
END
SQL);

    $createTrigger('trg_staff_discipline_party_no_delete', <<<'SQL'
CREATE TRIGGER trg_staff_discipline_party_no_delete
BEFORE DELETE ON staff_discipline_case_parties
FOR EACH ROW
BEGIN
    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Discipline case parties are withdrawn, never deleted';
END
SQL);

    $createTrigger('trg_staff_discipline_investigation_no_delete', <<<'SQL'
CREATE TRIGGER trg_staff_discipline_investigation_no_delete
BEFORE DELETE ON staff_discipline_investigations
FOR EACH ROW
BEGIN
    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Discipline investigations are superseded or cancelled, never deleted';
END
SQL);

    $createTrigger('trg_staff_discipline_evidence_guard_insert', <<<'SQL'
CREATE TRIGGER trg_staff_discipline_evidence_guard_insert
BEFORE INSERT ON staff_discipline_evidence
FOR EACH ROW
BEGIN
    DECLARE investigation_case BIGINT;
    DECLARE previous_case BIGINT;

    IF NEW.investigation_id IS NOT NULL THEN
        SELECT case_id INTO investigation_case
          FROM staff_discipline_investigations
         WHERE id = NEW.investigation_id;
        IF investigation_case IS NULL OR investigation_case <> NEW.case_id THEN
            SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Evidence investigation must belong to its case';
        END IF;
    END IF;

    IF NEW.prior_evidence_id IS NOT NULL THEN
        SELECT case_id INTO previous_case
          FROM staff_discipline_evidence
         WHERE id = NEW.prior_evidence_id;
        IF previous_case IS NULL OR previous_case <> NEW.case_id THEN
            SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Evidence custody predecessor must belong to its case';
        END IF;
    END IF;
END
SQL);

    $createTrigger('trg_staff_discipline_evidence_no_update', <<<'SQL'
CREATE TRIGGER trg_staff_discipline_evidence_no_update
BEFORE UPDATE ON staff_discipline_evidence
FOR EACH ROW
BEGIN
    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Discipline evidence is append-only; add a successor record';
END
SQL);

    $createTrigger('trg_staff_discipline_evidence_no_delete', <<<'SQL'
CREATE TRIGGER trg_staff_discipline_evidence_no_delete
BEFORE DELETE ON staff_discipline_evidence
FOR EACH ROW
BEGIN
    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Discipline evidence is never deleted';
END
SQL);

    $createTrigger('trg_staff_discipline_interim_no_delete', <<<'SQL'
CREATE TRIGGER trg_staff_discipline_interim_no_delete
BEFORE DELETE ON staff_discipline_interim_measures
FOR EACH ROW
BEGIN
    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Interim measures are revoked or completed, never deleted';
END
SQL);

    $createTrigger('trg_staff_discipline_decision_guard_insert', <<<'SQL'
CREATE TRIGGER trg_staff_discipline_decision_guard_insert
BEFORE INSERT ON staff_discipline_decisions
FOR EACH ROW
BEGIN
    IF NEW.prepared_by_user_id IS NOT NULL
       AND NEW.decided_by_user_id IS NOT NULL
       AND NEW.prepared_by_user_id = NEW.decided_by_user_id THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Decision preparer and decider must differ';
    END IF;

    IF NEW.status IN ('issued', 'amended', 'revoked', 'superseded')
       AND (NEW.decided_by_user_id IS NULL OR NEW.decided_at IS NULL OR NEW.issued_at IS NULL) THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Final discipline decision requires a recorded decider and issuance time';
    END IF;
END
SQL);

    $createTrigger('trg_staff_discipline_decision_guard_update', <<<'SQL'
CREATE TRIGGER trg_staff_discipline_decision_guard_update
BEFORE UPDATE ON staff_discipline_decisions
FOR EACH ROW
BEGIN
    IF NEW.prepared_by_user_id IS NOT NULL
       AND NEW.decided_by_user_id IS NOT NULL
       AND NEW.prepared_by_user_id = NEW.decided_by_user_id THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Decision preparer and decider must differ';
    END IF;

    IF NEW.status IN ('issued', 'amended', 'revoked', 'superseded')
       AND (NEW.decided_by_user_id IS NULL OR NEW.decided_at IS NULL OR NEW.issued_at IS NULL) THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Final discipline decision requires a recorded decider and issuance time';
    END IF;

    IF OLD.status IN ('issued', 'amended', 'revoked', 'superseded')
       AND (
           NOT (NEW.status <=> OLD.status)
           OR NOT (NEW.case_id <=> OLD.case_id)
           OR NOT (NEW.investigation_id <=> OLD.investigation_id)
           OR NOT (NEW.supersedes_decision_id <=> OLD.supersedes_decision_id)
           OR NOT (NEW.decision_no <=> OLD.decision_no)
           OR NOT (NEW.decision_sequence <=> OLD.decision_sequence)
           OR NOT (NEW.sanction_code <=> OLD.sanction_code)
           OR NOT (NEW.prepared_by_user_id <=> OLD.prepared_by_user_id)
           OR NOT (NEW.decided_by_user_id <=> OLD.decided_by_user_id)
           OR NOT (NEW.decided_at <=> OLD.decided_at)
           OR NOT (NEW.issued_at <=> OLD.issued_at)
           OR NOT (NEW.effective_from <=> OLD.effective_from)
           OR NOT (NEW.effective_to <=> OLD.effective_to)
           OR NOT (NEW.decision_reason <=> OLD.decision_reason)
           OR NOT (NEW.policy_snapshot <=> OLD.policy_snapshot)
           OR NOT (NEW.workflow_instance_id <=> OLD.workflow_instance_id)
           OR NOT (NEW.financial_effect_requested <=> OLD.financial_effect_requested)
           OR NOT (NEW.decision_hash <=> OLD.decision_hash)
           OR NOT (NEW.idempotency_key <=> OLD.idempotency_key)
       ) THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Issued discipline decision semantics are immutable; create a successor decision';
    END IF;
END
SQL);

    $createTrigger('trg_staff_discipline_decision_no_delete', <<<'SQL'
CREATE TRIGGER trg_staff_discipline_decision_no_delete
BEFORE DELETE ON staff_discipline_decisions
FOR EACH ROW
BEGIN
    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Discipline decisions are superseded or revoked, never deleted';
END
SQL);

    $createTrigger('trg_staff_discipline_appeal_guard_insert', <<<'SQL'
CREATE TRIGGER trg_staff_discipline_appeal_guard_insert
BEFORE INSERT ON staff_discipline_appeals
FOR EACH ROW
BEGIN
    DECLARE decision_case BIGINT;
    DECLARE decision_maker INT;

    SELECT case_id, decided_by_user_id
      INTO decision_case, decision_maker
      FROM staff_discipline_decisions
     WHERE id = NEW.decision_id;

    IF decision_case IS NULL OR decision_case <> NEW.case_id THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Appeal decision must belong to its case';
    END IF;

    IF NEW.reviewer_user_id IS NOT NULL
       AND NEW.reviewer_user_id = NEW.appellant_user_id THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Appeal reviewer must differ from appellant';
    END IF;

    IF NEW.reviewer_user_id IS NOT NULL
       AND decision_maker IS NOT NULL
       AND NEW.reviewer_user_id = decision_maker THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Appeal reviewer must differ from final decision maker';
    END IF;
END
SQL);

    $createTrigger('trg_staff_discipline_appeal_guard_update', <<<'SQL'
CREATE TRIGGER trg_staff_discipline_appeal_guard_update
BEFORE UPDATE ON staff_discipline_appeals
FOR EACH ROW
BEGIN
    DECLARE decision_maker INT;

    SELECT decided_by_user_id INTO decision_maker
      FROM staff_discipline_decisions
     WHERE id = NEW.decision_id;

    IF NEW.reviewer_user_id IS NOT NULL
       AND NEW.reviewer_user_id = NEW.appellant_user_id THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Appeal reviewer must differ from appellant';
    END IF;

    IF NEW.reviewer_user_id IS NOT NULL
       AND decision_maker IS NOT NULL
       AND NEW.reviewer_user_id = decision_maker THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Appeal reviewer must differ from final decision maker';
    END IF;

    IF OLD.status IN ('upheld', 'amended', 'revoked', 'withdrawn', 'expired', 'closed') THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Final appeal outcome is immutable; record a follow-up case event';
    END IF;
END
SQL);

    $createTrigger('trg_staff_discipline_appeal_no_delete', <<<'SQL'
CREATE TRIGGER trg_staff_discipline_appeal_no_delete
BEFORE DELETE ON staff_discipline_appeals
FOR EACH ROW
BEGIN
    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Discipline appeals are never deleted';
END
SQL);

    $createTrigger('trg_staff_discipline_execution_guard_update', <<<'SQL'
CREATE TRIGGER trg_staff_discipline_execution_guard_update
BEFORE UPDATE ON staff_discipline_executions
FOR EACH ROW
BEGIN
    IF OLD.status IN ('executed', 'reversed', 'cancelled') THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Final execution evidence is immutable; create a reversal execution';
    END IF;
END
SQL);

    $createTrigger('trg_staff_discipline_execution_no_delete', <<<'SQL'
CREATE TRIGGER trg_staff_discipline_execution_no_delete
BEFORE DELETE ON staff_discipline_executions
FOR EACH ROW
BEGIN
    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Discipline execution evidence is never deleted';
END
SQL);

    $createTrigger('trg_staff_discipline_finance_effect_no_delete', <<<'SQL'
CREATE TRIGGER trg_staff_discipline_finance_effect_no_delete
BEFORE DELETE ON staff_discipline_finance_effects
FOR EACH ROW
BEGIN
    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Discipline Finance facts are cancelled or reversed, never deleted';
END
SQL);

    $createTrigger('trg_staff_discipline_reopen_guard_insert', <<<'SQL'
CREATE TRIGGER trg_staff_discipline_reopen_guard_insert
BEFORE INSERT ON staff_discipline_reopen_events
FOR EACH ROW
BEGIN
    DECLARE evidence_case BIGINT;
    DECLARE current_case_status VARCHAR(50);

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
END
SQL);

    $createTrigger('trg_staff_discipline_reopen_no_update', <<<'SQL'
CREATE TRIGGER trg_staff_discipline_reopen_no_update
BEFORE UPDATE ON staff_discipline_reopen_events
FOR EACH ROW
BEGIN
    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Discipline reopen authorization is append-only; add a new event';
END
SQL);

    $createTrigger('trg_staff_discipline_reopen_no_delete', <<<'SQL'
CREATE TRIGGER trg_staff_discipline_reopen_no_delete
BEFORE DELETE ON staff_discipline_reopen_events
FOR EACH ROW
BEGIN
    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Discipline reopen authorization is never deleted';
END
SQL);
};
