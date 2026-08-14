<?php

declare(strict_types=1);

/**
 * Staff-owned leave policies, requests, immutable balance movements, and
 * return-to-work evidence. Workflow, account, attachment, attendance, and
 * Finance IDs remain scalar references because their owners deploy
 * independently; later services communicate through documented contracts.
 *
 * Rollback is isolated-environment only: switch readers/writers off, archive
 * the request and ledger history, drop triggers, then drop dependent tables
 * in reverse order. Production leave movements are reconciliation evidence.
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

    $createTable('staff_leave_types', <<<'SQL'
CREATE TABLE staff_leave_types (
    id INT NOT NULL AUTO_INCREMENT,
    code VARCHAR(80) NOT NULL,
    name VARCHAR(200) NOT NULL,
    unit ENUM('day','hour') NOT NULL DEFAULT 'day',
    requires_reason TINYINT(1) NOT NULL DEFAULT 1,
    requires_attachment TINYINT(1) NOT NULL DEFAULT 0,
    requires_medical_document TINYINT(1) NOT NULL DEFAULT 0,
    allow_partial_unit TINYINT(1) NOT NULL DEFAULT 0,
    payroll_effect_code VARCHAR(80) NULL,
    status ENUM('active','inactive','retired') NOT NULL DEFAULT 'active',
    created_by INT NULL,
    created_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
    updated_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6) ON UPDATE CURRENT_TIMESTAMP(6),
    PRIMARY KEY (id),
    UNIQUE KEY uk_staff_leave_type_code (code),
    KEY idx_staff_leave_type_status (status, unit),
    CONSTRAINT chk_staff_leave_type_code CHECK (CHAR_LENGTH(TRIM(code)) > 0),
    CONSTRAINT chk_staff_leave_type_name CHECK (CHAR_LENGTH(TRIM(name)) > 0),
    CONSTRAINT chk_staff_leave_type_flags CHECK (
        requires_reason IN (0, 1)
        AND requires_attachment IN (0, 1)
        AND requires_medical_document IN (0, 1)
        AND allow_partial_unit IN (0, 1)
    )
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL);

    $createTable('staff_leave_policy_versions', <<<'SQL'
CREATE TABLE staff_leave_policy_versions (
    id BIGINT NOT NULL AUTO_INCREMENT,
    leave_type_id INT NOT NULL,
    version_no INT UNSIGNED NOT NULL,
    state ENUM('draft','published','retired') NOT NULL DEFAULT 'draft',
    valid_from DATETIME(6) NOT NULL,
    valid_to DATETIME(6) NULL,
    timezone VARCHAR(64) NOT NULL DEFAULT 'Africa/Cairo',
    entitlement_period_type ENUM('calendar_year','academic_year','service_anniversary','custom') NOT NULL DEFAULT 'calendar_year',
    entitlement_period_anchor_mmdd CHAR(5) NULL,
    entitlement_units DECIMAL(12,3) NOT NULL DEFAULT 0.000,
    accrual_mode ENUM('grant','monthly','manual') NOT NULL DEFAULT 'grant',
    accrual_units DECIMAL(12,3) NOT NULL DEFAULT 0.000,
    carry_limit_units DECIMAL(12,3) NULL,
    carry_expiry_months SMALLINT UNSIGNED NULL,
    max_consecutive_units DECIMAL(12,3) NULL,
    min_notice_minutes INT UNSIGNED NOT NULL DEFAULT 0,
    min_service_months SMALLINT UNSIGNED NOT NULL DEFAULT 0,
    allow_retroactive TINYINT(1) NOT NULL DEFAULT 0,
    retroactive_limit_days SMALLINT UNSIGNED NOT NULL DEFAULT 0,
    minimum_increment_minutes INT UNSIGNED NOT NULL DEFAULT 60,
    allow_partial_unit TINYINT(1) NOT NULL DEFAULT 0,
    allow_overlap TINYINT(1) NOT NULL DEFAULT 0,
    allow_negative_balance TINYINT(1) NOT NULL DEFAULT 0,
    negative_balance_limit_units DECIMAL(12,3) NOT NULL DEFAULT 0.000,
    requires_return_to_work TINYINT(1) NOT NULL DEFAULT 0,
    requires_attachment TINYINT(1) NOT NULL DEFAULT 0,
    requires_medical_document TINYINT(1) NOT NULL DEFAULT 0,
    payroll_effect_code VARCHAR(80) NULL,
    supersedes_id BIGINT NULL,
    published_by INT NULL,
    published_at DATETIME(6) NULL,
    created_by INT NULL,
    created_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
    updated_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6) ON UPDATE CURRENT_TIMESTAMP(6),
    PRIMARY KEY (id),
    UNIQUE KEY uk_staff_leave_policy_version (leave_type_id, version_no),
    UNIQUE KEY uk_staff_leave_policy_supersedes (supersedes_id),
    KEY idx_staff_leave_policy_effective (leave_type_id, state, valid_from, valid_to),
    CONSTRAINT fk_staff_leave_policy_type FOREIGN KEY (leave_type_id) REFERENCES staff_leave_types (id) ON DELETE RESTRICT,
    CONSTRAINT fk_staff_leave_policy_previous FOREIGN KEY (supersedes_id) REFERENCES staff_leave_policy_versions (id) ON DELETE RESTRICT,
    CONSTRAINT chk_staff_leave_policy_dates CHECK (valid_to IS NULL OR valid_to > valid_from),
    CONSTRAINT chk_staff_leave_policy_timezone CHECK (CHAR_LENGTH(TRIM(timezone)) > 0),
    CONSTRAINT chk_staff_leave_policy_period_anchor CHECK (
        (entitlement_period_type IN ('calendar_year', 'service_anniversary') AND entitlement_period_anchor_mmdd IS NULL)
        OR (
            entitlement_period_type IN ('academic_year', 'custom')
            AND entitlement_period_anchor_mmdd REGEXP '^(0[1-9]|1[0-2])-(0[1-9]|[12][0-9]|3[01])$'
        )
    ),
    CONSTRAINT chk_staff_leave_policy_entitlement CHECK (entitlement_units >= 0 AND accrual_units >= 0),
    CONSTRAINT chk_staff_leave_policy_carry CHECK (
        (carry_limit_units IS NULL OR carry_limit_units >= 0)
        AND (carry_expiry_months IS NULL OR carry_expiry_months > 0)
    ),
    CONSTRAINT chk_staff_leave_policy_max_consecutive CHECK (max_consecutive_units IS NULL OR max_consecutive_units > 0),
    CONSTRAINT chk_staff_leave_policy_increment CHECK (minimum_increment_minutes > 0),
    CONSTRAINT chk_staff_leave_policy_flags CHECK (
        allow_partial_unit IN (0, 1)
        AND allow_overlap IN (0, 1)
        AND allow_negative_balance IN (0, 1)
        AND requires_return_to_work IN (0, 1)
        AND requires_attachment IN (0, 1)
        AND requires_medical_document IN (0, 1)
        AND allow_retroactive IN (0, 1)
    ),
    CONSTRAINT chk_staff_leave_policy_retroactive CHECK (
        (allow_retroactive = 0 AND retroactive_limit_days = 0)
        OR (allow_retroactive = 1 AND retroactive_limit_days > 0)
    ),
    CONSTRAINT chk_staff_leave_policy_negative CHECK (
        (allow_negative_balance = 0 AND negative_balance_limit_units = 0)
        OR (allow_negative_balance = 1 AND negative_balance_limit_units > 0)
    ),
    CONSTRAINT chk_staff_leave_policy_published CHECK (
        state <> 'published' OR (published_by IS NOT NULL AND published_at IS NOT NULL)
    )
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL);

    $createTable('staff_leave_policy_scopes', <<<'SQL'
CREATE TABLE staff_leave_policy_scopes (
    id BIGINT NOT NULL AUTO_INCREMENT,
    policy_version_id BIGINT NOT NULL,
    scope_type ENUM('global','org_unit','job_title','group','staff') NOT NULL,
    scope_id INT NOT NULL DEFAULT 0,
    priority SMALLINT UNSIGNED NOT NULL DEFAULT 0,
    valid_from DATETIME(6) NOT NULL,
    valid_to DATETIME(6) NULL,
    minimum_available_staff SMALLINT UNSIGNED NULL,
    max_absence_percentage DECIMAL(5,2) NULL,
    requires_staffing_override TINYINT(1) NOT NULL DEFAULT 0,
    override_role_key VARCHAR(80) NULL,
    status ENUM('active','suspended','retired') NOT NULL DEFAULT 'active',
    created_by INT NULL,
    created_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
    PRIMARY KEY (id),
    UNIQUE KEY uk_staff_leave_policy_scope_start (policy_version_id, scope_type, scope_id, priority, valid_from),
    KEY idx_staff_leave_policy_scope_resolution (scope_type, scope_id, valid_from, valid_to, status, priority),
    CONSTRAINT fk_staff_leave_policy_scope_version FOREIGN KEY (policy_version_id) REFERENCES staff_leave_policy_versions (id) ON DELETE RESTRICT,
    CONSTRAINT chk_staff_leave_policy_scope_dates CHECK (valid_to IS NULL OR valid_to > valid_from),
    CONSTRAINT chk_staff_leave_policy_scope_identity CHECK (
        (scope_type = 'global' AND scope_id = 0)
        OR (scope_type <> 'global' AND scope_id > 0)
    ),
    CONSTRAINT chk_staff_leave_policy_scope_absence CHECK (
        max_absence_percentage IS NULL OR (max_absence_percentage > 0 AND max_absence_percentage <= 100)
    ),
    CONSTRAINT chk_staff_leave_policy_scope_override CHECK (
        (requires_staffing_override = 0 AND override_role_key IS NULL)
        OR (requires_staffing_override = 1 AND CHAR_LENGTH(TRIM(COALESCE(override_role_key, ''))) > 0)
    )
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL);

    $createTable('staff_leave_policy_blackouts', <<<'SQL'
CREATE TABLE staff_leave_policy_blackouts (
    id BIGINT NOT NULL AUTO_INCREMENT,
    policy_version_id BIGINT NOT NULL,
    scope_type ENUM('global','org_unit','job_title','group','staff') NOT NULL DEFAULT 'global',
    scope_id INT NOT NULL DEFAULT 0,
    from_at DATETIME(6) NOT NULL,
    to_at DATETIME(6) NOT NULL,
    label VARCHAR(200) NOT NULL,
    requires_override TINYINT(1) NOT NULL DEFAULT 1,
    override_role_key VARCHAR(80) NULL,
    status ENUM('active','retired') NOT NULL DEFAULT 'active',
    created_by INT NULL,
    created_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
    PRIMARY KEY (id),
    UNIQUE KEY uk_staff_leave_blackout_start (policy_version_id, scope_type, scope_id, from_at),
    KEY idx_staff_leave_blackout_resolution (scope_type, scope_id, from_at, to_at, status),
    CONSTRAINT fk_staff_leave_blackout_policy FOREIGN KEY (policy_version_id) REFERENCES staff_leave_policy_versions (id) ON DELETE RESTRICT,
    CONSTRAINT chk_staff_leave_blackout_window CHECK (to_at > from_at),
    CONSTRAINT chk_staff_leave_blackout_scope CHECK (
        (scope_type = 'global' AND scope_id = 0)
        OR (scope_type <> 'global' AND scope_id > 0)
    ),
    CONSTRAINT chk_staff_leave_blackout_label CHECK (CHAR_LENGTH(TRIM(label)) > 0),
    CONSTRAINT chk_staff_leave_blackout_override CHECK (
        (requires_override = 0 AND override_role_key IS NULL)
        OR (requires_override = 1 AND CHAR_LENGTH(TRIM(COALESCE(override_role_key, ''))) > 0)
    )
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL);

    $createTable('staff_leave_requests', <<<'SQL'
CREATE TABLE staff_leave_requests (
    id BIGINT NOT NULL AUTO_INCREMENT,
    staff_user_id INT NOT NULL,
    leave_type_id INT NOT NULL,
    request_kind ENUM('leave','extension','early_return','cancellation') NOT NULL DEFAULT 'leave',
    parent_request_id BIGINT NULL,
    supersedes_id BIGINT NULL,
    from_at DATETIME(6) NOT NULL,
    to_at DATETIME(6) NOT NULL,
    timezone VARCHAR(64) NOT NULL DEFAULT 'Africa/Cairo',
    requested_units DECIMAL(12,3) NOT NULL,
    requested_minutes INT UNSIGNED NOT NULL DEFAULT 0,
    reason TEXT NULL,
    reason_code VARCHAR(100) NULL,
    supporting_document_ref VARCHAR(500) NULL,
    status ENUM(
        'draft','pending_approval','approved','rejected','withdrawn',
        'cancellation_requested','cancelled','return_recorded',
        'cancelled_due_to_service_end','superseded'
    ) NOT NULL DEFAULT 'draft',
    policy_version_id BIGINT NULL,
    policy_snapshot JSON NULL,
    workflow_version_id BIGINT NULL,
    workflow_instance_id BIGINT NULL,
    assignment_id BIGINT NULL,
    staffing_override_granted TINYINT(1) NOT NULL DEFAULT 0,
    staffing_override_reason VARCHAR(1000) NULL,
    submitted_by INT NULL,
    submitted_at DATETIME(6) NULL,
    approved_at DATETIME(6) NULL,
    decided_at DATETIME(6) NULL,
    lock_version INT UNSIGNED NOT NULL DEFAULT 1,
    create_idempotency_key VARCHAR(190) NOT NULL,
    submission_idempotency_key VARCHAR(190) NULL,
    request_hash CHAR(64) NOT NULL,
    created_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
    updated_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6) ON UPDATE CURRENT_TIMESTAMP(6),
    PRIMARY KEY (id),
    UNIQUE KEY uk_staff_leave_request_create_key (create_idempotency_key),
    UNIQUE KEY uk_staff_leave_request_submit_key (submission_idempotency_key),
    KEY idx_staff_leave_request_staff_window (staff_user_id, from_at, to_at, status),
    KEY idx_staff_leave_request_type_status (leave_type_id, status, from_at),
    KEY idx_staff_leave_request_workflow (workflow_instance_id, status),
    KEY idx_staff_leave_request_parent (parent_request_id, request_kind, status),
    KEY idx_staff_leave_request_assignment (assignment_id, from_at, to_at),
    CONSTRAINT fk_staff_leave_request_type FOREIGN KEY (leave_type_id) REFERENCES staff_leave_types (id) ON DELETE RESTRICT,
    CONSTRAINT fk_staff_leave_request_policy FOREIGN KEY (policy_version_id) REFERENCES staff_leave_policy_versions (id) ON DELETE RESTRICT,
    CONSTRAINT fk_staff_leave_request_parent FOREIGN KEY (parent_request_id) REFERENCES staff_leave_requests (id) ON DELETE RESTRICT,
    CONSTRAINT fk_staff_leave_request_supersedes FOREIGN KEY (supersedes_id) REFERENCES staff_leave_requests (id) ON DELETE RESTRICT,
    CONSTRAINT chk_staff_leave_request_window CHECK (to_at > from_at),
    CONSTRAINT chk_staff_leave_request_units CHECK (requested_units > 0 AND requested_minutes >= 0),
    CONSTRAINT chk_staff_leave_request_timezone CHECK (CHAR_LENGTH(TRIM(timezone)) > 0),
    CONSTRAINT chk_staff_leave_request_lock CHECK (lock_version > 0),
    CONSTRAINT chk_staff_leave_request_hash CHECK (CHAR_LENGTH(request_hash) = 64),
    CONSTRAINT chk_staff_leave_request_parent_kind CHECK (
        (request_kind = 'leave' AND parent_request_id IS NULL)
        OR (request_kind <> 'leave' AND parent_request_id IS NOT NULL)
    ),
    CONSTRAINT chk_staff_leave_request_staffing_override CHECK (
        (staffing_override_granted = 0 AND staffing_override_reason IS NULL)
        OR (staffing_override_granted = 1 AND CHAR_LENGTH(TRIM(COALESCE(staffing_override_reason, ''))) > 0)
    ),
    CONSTRAINT chk_staff_leave_request_submission CHECK (
        (
            status IN ('draft', 'withdrawn')
            AND submitted_at IS NULL
            AND policy_version_id IS NULL
            AND policy_snapshot IS NULL
            AND workflow_version_id IS NULL
            AND workflow_instance_id IS NULL
            AND assignment_id IS NULL
            AND submitted_by IS NULL
            AND submission_idempotency_key IS NULL
        )
        OR (
            status NOT IN ('draft', 'withdrawn')
            AND submitted_at IS NOT NULL
            AND policy_version_id IS NOT NULL
            AND policy_snapshot IS NOT NULL
            AND workflow_version_id IS NOT NULL
            AND assignment_id IS NOT NULL
            AND submitted_by IS NOT NULL
            AND submission_idempotency_key IS NOT NULL
        )
    ),
    CONSTRAINT chk_staff_leave_request_decision_time CHECK (
        decided_at IS NULL OR (submitted_at IS NOT NULL AND decided_at >= submitted_at)
    )
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL);

    $createTable('staff_leave_request_days', <<<'SQL'
CREATE TABLE staff_leave_request_days (
    id BIGINT NOT NULL AUTO_INCREMENT,
    request_id BIGINT NOT NULL,
    work_date DATE NOT NULL,
    day_kind ENUM('workday','non_working','partial') NOT NULL,
    from_at DATETIME(6) NULL,
    to_at DATETIME(6) NULL,
    requested_units DECIMAL(12,3) NOT NULL DEFAULT 0.000,
    requested_minutes INT UNSIGNED NOT NULL DEFAULT 0,
    consumed_units DECIMAL(12,3) NOT NULL DEFAULT 0.000,
    consumed_minutes INT UNSIGNED NOT NULL DEFAULT 0,
    entitlement_period_key VARCHAR(80) NULL,
    calendar_exception_id BIGINT NULL,
    allocation_key CHAR(64) NOT NULL,
    created_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
    PRIMARY KEY (id),
    UNIQUE KEY uk_staff_leave_request_day (request_id, allocation_key),
    KEY idx_staff_leave_request_day_date (work_date, request_id),
    KEY idx_staff_leave_request_day_period (entitlement_period_key, request_id),
    CONSTRAINT fk_staff_leave_request_day_request FOREIGN KEY (request_id) REFERENCES staff_leave_requests (id) ON DELETE RESTRICT,
    CONSTRAINT chk_staff_leave_request_day_window CHECK (
        (from_at IS NULL AND to_at IS NULL) OR (from_at IS NOT NULL AND to_at IS NOT NULL AND to_at > from_at)
    ),
    CONSTRAINT chk_staff_leave_request_day_units CHECK (
        requested_units >= 0 AND consumed_units >= 0 AND requested_minutes >= 0 AND consumed_minutes >= 0
    ),
    CONSTRAINT chk_staff_leave_request_day_kind CHECK (
        (day_kind = 'non_working' AND consumed_units = 0 AND consumed_minutes = 0)
        OR day_kind IN ('workday', 'partial')
    ),
    CONSTRAINT chk_staff_leave_request_day_key CHECK (CHAR_LENGTH(allocation_key) = 64)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL);

    $createTable('staff_leave_balance_accounts', <<<'SQL'
CREATE TABLE staff_leave_balance_accounts (
    id BIGINT NOT NULL AUTO_INCREMENT,
    staff_user_id INT NOT NULL,
    leave_type_id INT NOT NULL,
    entitlement_period_key VARCHAR(80) NOT NULL,
    period_from DATE NOT NULL,
    period_to DATE NOT NULL,
    status ENUM('open','closed') NOT NULL DEFAULT 'open',
    available_units DECIMAL(12,3) NOT NULL DEFAULT 0.000,
    reserved_units DECIMAL(12,3) NOT NULL DEFAULT 0.000,
    consumed_units DECIMAL(12,3) NOT NULL DEFAULT 0.000,
    granted_units DECIMAL(12,3) NOT NULL DEFAULT 0.000,
    expired_units DECIMAL(12,3) NOT NULL DEFAULT 0.000,
    negative_balance_limit_units DECIMAL(12,3) NOT NULL DEFAULT 0.000,
    lock_version INT UNSIGNED NOT NULL DEFAULT 1,
    created_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
    updated_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6) ON UPDATE CURRENT_TIMESTAMP(6),
    PRIMARY KEY (id),
    UNIQUE KEY uk_staff_leave_balance_account (staff_user_id, leave_type_id, entitlement_period_key),
    KEY idx_staff_leave_balance_period (leave_type_id, entitlement_period_key, status),
    CONSTRAINT fk_staff_leave_balance_type FOREIGN KEY (leave_type_id) REFERENCES staff_leave_types (id) ON DELETE RESTRICT,
    CONSTRAINT chk_staff_leave_balance_period CHECK (period_to >= period_from AND CHAR_LENGTH(TRIM(entitlement_period_key)) > 0),
    CONSTRAINT chk_staff_leave_balance_counters CHECK (
        reserved_units >= 0
        AND consumed_units >= 0
        AND granted_units >= 0
        AND expired_units >= 0
        AND negative_balance_limit_units >= 0
        AND available_units >= (0 - negative_balance_limit_units)
        AND lock_version > 0
    )
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL);

    $createTable('staff_leave_balance_movements', <<<'SQL'
CREATE TABLE staff_leave_balance_movements (
    id BIGINT NOT NULL AUTO_INCREMENT,
    account_id BIGINT NOT NULL,
    leave_request_id BIGINT NULL,
    request_day_id BIGINT NULL,
    movement_type ENUM('grant','accrue','reserve','consume','release','carry','expire','adjust','reverse') NOT NULL,
    units_delta DECIMAL(12,3) NOT NULL,
    available_delta DECIMAL(12,3) NOT NULL DEFAULT 0.000,
    reserved_delta DECIMAL(12,3) NOT NULL DEFAULT 0.000,
    consumed_delta DECIMAL(12,3) NOT NULL DEFAULT 0.000,
    source_type VARCHAR(80) NOT NULL,
    source_id BIGINT NULL,
    logical_key CHAR(64) NOT NULL,
    reverses_movement_id BIGINT NULL,
    idempotency_key VARCHAR(190) NOT NULL,
    movement_hash CHAR(64) NOT NULL,
    reason_code VARCHAR(100) NULL,
    created_by INT NULL,
    created_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
    PRIMARY KEY (id),
    UNIQUE KEY uk_staff_leave_movement_idempotency (idempotency_key),
    UNIQUE KEY uk_staff_leave_movement_logical (account_id, logical_key),
    UNIQUE KEY uk_staff_leave_movement_reverse (reverses_movement_id),
    KEY idx_staff_leave_movement_request (leave_request_id, created_at),
    KEY idx_staff_leave_movement_account (account_id, created_at),
    KEY idx_staff_leave_movement_source (source_type, source_id),
    CONSTRAINT fk_staff_leave_movement_account FOREIGN KEY (account_id) REFERENCES staff_leave_balance_accounts (id) ON DELETE RESTRICT,
    CONSTRAINT fk_staff_leave_movement_request FOREIGN KEY (leave_request_id) REFERENCES staff_leave_requests (id) ON DELETE RESTRICT,
    CONSTRAINT fk_staff_leave_movement_day FOREIGN KEY (request_day_id) REFERENCES staff_leave_request_days (id) ON DELETE RESTRICT,
    CONSTRAINT fk_staff_leave_movement_reverse FOREIGN KEY (reverses_movement_id) REFERENCES staff_leave_balance_movements (id) ON DELETE RESTRICT,
    CONSTRAINT chk_staff_leave_movement_hashes CHECK (CHAR_LENGTH(logical_key) = 64 AND CHAR_LENGTH(movement_hash) = 64),
    CONSTRAINT chk_staff_leave_movement_nonzero CHECK (
        units_delta <> 0 OR available_delta <> 0 OR reserved_delta <> 0 OR consumed_delta <> 0
    ),
    CONSTRAINT chk_staff_leave_movement_reverse_link CHECK (
        (movement_type = 'reverse' AND reverses_movement_id IS NOT NULL)
        OR (movement_type <> 'reverse' AND reverses_movement_id IS NULL)
    )
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL);

    $createTable('staff_return_to_work_events', <<<'SQL'
CREATE TABLE staff_return_to_work_events (
    id BIGINT NOT NULL AUTO_INCREMENT,
    leave_request_id BIGINT NOT NULL,
    event_sequence SMALLINT UNSIGNED NOT NULL DEFAULT 1,
    actual_return_at DATETIME(6) NOT NULL,
    return_outcome ENUM('normal','early','late','unfit','pending_review') NOT NULL DEFAULT 'normal',
    fitness_status ENUM('not_required','fit','restricted','unfit','pending') NOT NULL DEFAULT 'not_required',
    medical_document_ref VARCHAR(500) NULL,
    return_notes TEXT NULL,
    supersedes_id BIGINT NULL,
    recorded_by INT NOT NULL,
    idempotency_key VARCHAR(190) NOT NULL,
    return_hash CHAR(64) NOT NULL,
    created_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
    PRIMARY KEY (id),
    UNIQUE KEY uk_staff_return_event_sequence (leave_request_id, event_sequence),
    UNIQUE KEY uk_staff_return_event_idempotency (idempotency_key),
    UNIQUE KEY uk_staff_return_event_supersedes (supersedes_id),
    KEY idx_staff_return_event_time (actual_return_at, return_outcome),
    CONSTRAINT fk_staff_return_event_request FOREIGN KEY (leave_request_id) REFERENCES staff_leave_requests (id) ON DELETE RESTRICT,
    CONSTRAINT fk_staff_return_event_previous FOREIGN KEY (supersedes_id) REFERENCES staff_return_to_work_events (id) ON DELETE RESTRICT,
    CONSTRAINT chk_staff_return_event_sequence CHECK (event_sequence > 0),
    CONSTRAINT chk_staff_return_event_hash CHECK (CHAR_LENGTH(return_hash) = 64),
    CONSTRAINT chk_staff_return_event_medical CHECK (
        fitness_status <> 'unfit' OR medical_document_ref IS NOT NULL
    )
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL);

    $createTrigger('trg_staff_leave_type_guard_update', <<<'SQL'
CREATE TRIGGER trg_staff_leave_type_guard_update
BEFORE UPDATE ON staff_leave_types
FOR EACH ROW
BEGIN
    IF (
        NOT (NEW.code <=> OLD.code)
        OR NOT (NEW.unit <=> OLD.unit)
        OR NOT (NEW.requires_reason <=> OLD.requires_reason)
        OR NOT (NEW.requires_attachment <=> OLD.requires_attachment)
        OR NOT (NEW.requires_medical_document <=> OLD.requires_medical_document)
        OR NOT (NEW.allow_partial_unit <=> OLD.allow_partial_unit)
        OR NOT (NEW.payroll_effect_code <=> OLD.payroll_effect_code)
    ) AND (
        EXISTS (SELECT 1 FROM staff_leave_policy_versions p WHERE p.leave_type_id = OLD.id)
        OR EXISTS (SELECT 1 FROM staff_leave_requests r WHERE r.leave_type_id = OLD.id)
        OR EXISTS (SELECT 1 FROM staff_leave_balance_accounts a WHERE a.leave_type_id = OLD.id)
    ) THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Used leave type semantics are immutable; retire and create a new type';
    END IF;
END
SQL);

    $createTrigger('trg_staff_leave_type_no_delete', <<<'SQL'
CREATE TRIGGER trg_staff_leave_type_no_delete
BEFORE DELETE ON staff_leave_types
FOR EACH ROW
BEGIN
    IF EXISTS (SELECT 1 FROM staff_leave_policy_versions p WHERE p.leave_type_id = OLD.id)
       OR EXISTS (SELECT 1 FROM staff_leave_requests r WHERE r.leave_type_id = OLD.id)
       OR EXISTS (SELECT 1 FROM staff_leave_balance_accounts a WHERE a.leave_type_id = OLD.id) THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Used leave type cannot be deleted';
    END IF;
END
SQL);

    $createTrigger('trg_staff_leave_policy_guard_update', <<<'SQL'
CREATE TRIGGER trg_staff_leave_policy_guard_update
BEFORE UPDATE ON staff_leave_policy_versions
FOR EACH ROW
BEGIN
    IF OLD.state = 'published' AND (
        NOT (NEW.leave_type_id <=> OLD.leave_type_id)
        OR NOT (NEW.version_no <=> OLD.version_no)
        OR NOT (NEW.valid_from <=> OLD.valid_from)
        OR NOT (NEW.valid_to <=> OLD.valid_to)
        OR NOT (NEW.timezone <=> OLD.timezone)
        OR NOT (NEW.entitlement_period_type <=> OLD.entitlement_period_type)
        OR NOT (NEW.entitlement_period_anchor_mmdd <=> OLD.entitlement_period_anchor_mmdd)
        OR NOT (NEW.entitlement_units <=> OLD.entitlement_units)
        OR NOT (NEW.accrual_mode <=> OLD.accrual_mode)
        OR NOT (NEW.accrual_units <=> OLD.accrual_units)
        OR NOT (NEW.carry_limit_units <=> OLD.carry_limit_units)
        OR NOT (NEW.carry_expiry_months <=> OLD.carry_expiry_months)
        OR NOT (NEW.max_consecutive_units <=> OLD.max_consecutive_units)
        OR NOT (NEW.min_notice_minutes <=> OLD.min_notice_minutes)
        OR NOT (NEW.allow_retroactive <=> OLD.allow_retroactive)
        OR NOT (NEW.retroactive_limit_days <=> OLD.retroactive_limit_days)
        OR NOT (NEW.minimum_increment_minutes <=> OLD.minimum_increment_minutes)
        OR NOT (NEW.allow_partial_unit <=> OLD.allow_partial_unit)
        OR NOT (NEW.allow_overlap <=> OLD.allow_overlap)
        OR NOT (NEW.allow_negative_balance <=> OLD.allow_negative_balance)
        OR NOT (NEW.negative_balance_limit_units <=> OLD.negative_balance_limit_units)
        OR NOT (NEW.requires_return_to_work <=> OLD.requires_return_to_work)
        OR NOT (NEW.requires_attachment <=> OLD.requires_attachment)
        OR NOT (NEW.requires_medical_document <=> OLD.requires_medical_document)
        OR NOT (NEW.payroll_effect_code <=> OLD.payroll_effect_code)
    ) THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Published leave policy semantics are immutable; create a successor version';
    END IF;
END
SQL);

    $createTrigger('trg_staff_leave_policy_no_delete', <<<'SQL'
CREATE TRIGGER trg_staff_leave_policy_no_delete
BEFORE DELETE ON staff_leave_policy_versions
FOR EACH ROW
BEGIN
    IF OLD.state = 'published'
       OR EXISTS (SELECT 1 FROM staff_leave_policy_scopes s WHERE s.policy_version_id = OLD.id)
       OR EXISTS (SELECT 1 FROM staff_leave_requests r WHERE r.policy_version_id = OLD.id) THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Leave policy history cannot be deleted';
    END IF;
END
SQL);

    $createTrigger('trg_staff_leave_scope_guard_insert', <<<'SQL'
CREATE TRIGGER trg_staff_leave_scope_guard_insert
BEFORE INSERT ON staff_leave_policy_scopes
FOR EACH ROW
BEGIN
    DECLARE policy_state VARCHAR(20);
    SELECT state INTO policy_state FROM staff_leave_policy_versions WHERE id = NEW.policy_version_id;
    IF policy_state IS NULL OR policy_state <> 'draft' THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Leave policy scopes can only change while draft';
    END IF;
END
SQL);

    $createTrigger('trg_staff_leave_scope_guard_update', <<<'SQL'
CREATE TRIGGER trg_staff_leave_scope_guard_update
BEFORE UPDATE ON staff_leave_policy_scopes
FOR EACH ROW
BEGIN
    DECLARE policy_state VARCHAR(20);
    IF NOT (NEW.policy_version_id <=> OLD.policy_version_id) THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Leave policy scope cannot move to another policy';
    END IF;
    SELECT state INTO policy_state FROM staff_leave_policy_versions WHERE id = OLD.policy_version_id;
    IF policy_state IS NULL OR policy_state <> 'draft' THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Published leave policy scopes are immutable';
    END IF;
END
SQL);

    $createTrigger('trg_staff_leave_scope_guard_delete', <<<'SQL'
CREATE TRIGGER trg_staff_leave_scope_guard_delete
BEFORE DELETE ON staff_leave_policy_scopes
FOR EACH ROW
BEGIN
    DECLARE policy_state VARCHAR(20);
    SELECT state INTO policy_state FROM staff_leave_policy_versions WHERE id = OLD.policy_version_id;
    IF policy_state IS NULL OR policy_state <> 'draft' THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Published leave policy scopes cannot be deleted';
    END IF;
END
SQL);

    $createTrigger('trg_staff_leave_blackout_guard_insert', <<<'SQL'
CREATE TRIGGER trg_staff_leave_blackout_guard_insert
BEFORE INSERT ON staff_leave_policy_blackouts
FOR EACH ROW
BEGIN
    DECLARE policy_state VARCHAR(20);
    SELECT state INTO policy_state FROM staff_leave_policy_versions WHERE id = NEW.policy_version_id;
    IF policy_state IS NULL OR policy_state <> 'draft' THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Leave blackouts can only change while draft';
    END IF;
END
SQL);

    $createTrigger('trg_staff_leave_blackout_guard_update', <<<'SQL'
CREATE TRIGGER trg_staff_leave_blackout_guard_update
BEFORE UPDATE ON staff_leave_policy_blackouts
FOR EACH ROW
BEGIN
    DECLARE policy_state VARCHAR(20);
    IF NOT (NEW.policy_version_id <=> OLD.policy_version_id) THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Leave blackout cannot move to another policy';
    END IF;
    SELECT state INTO policy_state FROM staff_leave_policy_versions WHERE id = OLD.policy_version_id;
    IF policy_state IS NULL OR policy_state <> 'draft' THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Published leave blackouts are immutable';
    END IF;
END
SQL);

    $createTrigger('trg_staff_leave_blackout_guard_delete', <<<'SQL'
CREATE TRIGGER trg_staff_leave_blackout_guard_delete
BEFORE DELETE ON staff_leave_policy_blackouts
FOR EACH ROW
BEGIN
    DECLARE policy_state VARCHAR(20);
    SELECT state INTO policy_state FROM staff_leave_policy_versions WHERE id = OLD.policy_version_id;
    IF policy_state IS NULL OR policy_state <> 'draft' THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Published leave blackouts cannot be deleted';
    END IF;
END
SQL);

    $createTrigger('trg_staff_leave_request_guard_update', <<<'SQL'
CREATE TRIGGER trg_staff_leave_request_guard_update
BEFORE UPDATE ON staff_leave_requests
FOR EACH ROW
BEGIN
    IF OLD.status NOT IN ('draft', 'withdrawn') AND (
        NOT (NEW.staff_user_id <=> OLD.staff_user_id)
        OR NOT (NEW.leave_type_id <=> OLD.leave_type_id)
        OR NOT (NEW.request_kind <=> OLD.request_kind)
        OR NOT (NEW.parent_request_id <=> OLD.parent_request_id)
        OR NOT (NEW.from_at <=> OLD.from_at)
        OR NOT (NEW.to_at <=> OLD.to_at)
        OR NOT (NEW.timezone <=> OLD.timezone)
        OR NOT (NEW.requested_units <=> OLD.requested_units)
        OR NOT (NEW.requested_minutes <=> OLD.requested_minutes)
        OR NOT (NEW.reason <=> OLD.reason)
        OR NOT (NEW.reason_code <=> OLD.reason_code)
        OR NOT (NEW.supporting_document_ref <=> OLD.supporting_document_ref)
        OR NOT (NEW.policy_version_id <=> OLD.policy_version_id)
        OR NOT (NEW.policy_snapshot <=> OLD.policy_snapshot)
        OR NOT (NEW.workflow_version_id <=> OLD.workflow_version_id)
        OR NOT (NEW.workflow_instance_id <=> OLD.workflow_instance_id)
        OR NOT (NEW.assignment_id <=> OLD.assignment_id)
        OR NOT (NEW.request_hash <=> OLD.request_hash)
    ) THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Submitted leave request evidence is immutable; create a successor';
    END IF;
END
SQL);

    $createTrigger('trg_staff_leave_request_day_guard_insert', <<<'SQL'
CREATE TRIGGER trg_staff_leave_request_day_guard_insert
BEFORE INSERT ON staff_leave_request_days
FOR EACH ROW
BEGIN
    DECLARE request_state VARCHAR(50);
    DECLARE request_from DATETIME(6);
    DECLARE request_to DATETIME(6);
    SELECT status, from_at, to_at
      INTO request_state, request_from, request_to
      FROM staff_leave_requests WHERE id = NEW.request_id;
    IF request_state IS NULL OR request_state <> 'draft' THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Leave request days can only change while request is draft';
    END IF;
    -- WorkSchedule permits an originating workday to end up to two calendar
    -- days later, so an overnight leave allocation may legitimately belong to
    -- a work_date immediately preceding the civil request date.
    IF (NEW.from_at IS NOT NULL AND NEW.from_at < request_from)
       OR (NEW.to_at IS NOT NULL AND NEW.to_at > request_to)
       OR NEW.work_date < DATE_SUB(DATE(request_from), INTERVAL 2 DAY)
       OR NEW.work_date > DATE(DATE_SUB(request_to, INTERVAL 1 MICROSECOND)) THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Leave request day must remain inside requested window';
    END IF;
END
SQL);

    $createTrigger('trg_staff_leave_request_day_guard_update', <<<'SQL'
CREATE TRIGGER trg_staff_leave_request_day_guard_update
BEFORE UPDATE ON staff_leave_request_days
FOR EACH ROW
BEGIN
    DECLARE request_state VARCHAR(50);
    IF NOT (NEW.request_id <=> OLD.request_id) THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Leave request day cannot move to another request';
    END IF;
    SELECT status INTO request_state FROM staff_leave_requests WHERE id = OLD.request_id;
    IF request_state IS NULL OR request_state <> 'draft' THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Submitted leave request days are immutable';
    END IF;
END
SQL);

    $createTrigger('trg_staff_leave_request_day_guard_delete', <<<'SQL'
CREATE TRIGGER trg_staff_leave_request_day_guard_delete
BEFORE DELETE ON staff_leave_request_days
FOR EACH ROW
BEGIN
    DECLARE request_state VARCHAR(50);
    SELECT status INTO request_state FROM staff_leave_requests WHERE id = OLD.request_id;
    IF request_state IS NULL OR request_state <> 'draft' THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Submitted leave request days cannot be deleted';
    END IF;
END
SQL);

    $createTrigger('trg_staff_leave_account_guard_update', <<<'SQL'
CREATE TRIGGER trg_staff_leave_account_guard_update
BEFORE UPDATE ON staff_leave_balance_accounts
FOR EACH ROW
BEGIN
    IF NOT (NEW.staff_user_id <=> OLD.staff_user_id)
       OR NOT (NEW.leave_type_id <=> OLD.leave_type_id)
       OR NOT (NEW.entitlement_period_key <=> OLD.entitlement_period_key)
       OR NOT (NEW.period_from <=> OLD.period_from)
       OR NOT (NEW.period_to <=> OLD.period_to) THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Leave balance account identity is immutable';
    END IF;
    IF OLD.status = 'closed' AND NEW.status <> OLD.status THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Closed leave balance account cannot reopen';
    END IF;
END
SQL);

    $createTrigger('trg_staff_leave_movement_guard_insert', <<<'SQL'
CREATE TRIGGER trg_staff_leave_movement_guard_insert
BEFORE INSERT ON staff_leave_balance_movements
FOR EACH ROW
BEGIN
    DECLARE account_state VARCHAR(20);
    DECLARE account_staff INT;
    DECLARE account_type INT;
    DECLARE request_staff INT;
    DECLARE request_type INT;
    DECLARE request_state VARCHAR(50);
    DECLARE day_request BIGINT;
    SELECT status, staff_user_id, leave_type_id
      INTO account_state, account_staff, account_type
      FROM staff_leave_balance_accounts WHERE id = NEW.account_id;
    IF account_state IS NULL OR account_state <> 'open' THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Closed or missing leave balance account cannot receive movements';
    END IF;
    IF NEW.leave_request_id IS NOT NULL THEN
        SELECT staff_user_id, leave_type_id, status
          INTO request_staff, request_type, request_state
          FROM staff_leave_requests WHERE id = NEW.leave_request_id;
        IF request_staff IS NULL OR request_staff <> account_staff OR request_type <> account_type THEN
            SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Leave movement request must match account worker and leave type';
        END IF;
        IF request_state IN ('draft', 'withdrawn') THEN
            SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Leave movements require a submitted request';
        END IF;
    END IF;
    IF NEW.request_day_id IS NOT NULL THEN
        SELECT request_id INTO day_request FROM staff_leave_request_days WHERE id = NEW.request_day_id;
        IF day_request IS NULL OR NEW.leave_request_id IS NULL OR day_request <> NEW.leave_request_id THEN
            SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Leave movement day must belong to its request';
        END IF;
    END IF;
    IF NEW.movement_type IN ('reserve', 'consume', 'release') AND NEW.leave_request_id IS NULL THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Reserve, consume, and release require a leave request';
    END IF;
END
SQL);

    $createTrigger('trg_staff_leave_movement_no_update', <<<'SQL'
CREATE TRIGGER trg_staff_leave_movement_no_update
BEFORE UPDATE ON staff_leave_balance_movements
FOR EACH ROW
BEGIN
    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Leave balance movements are append-only';
END
SQL);

    $createTrigger('trg_staff_leave_movement_no_delete', <<<'SQL'
CREATE TRIGGER trg_staff_leave_movement_no_delete
BEFORE DELETE ON staff_leave_balance_movements
FOR EACH ROW
BEGIN
    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Leave balance movements are retained for reconciliation';
END
SQL);

    $createTrigger('trg_staff_return_guard_insert', <<<'SQL'
CREATE TRIGGER trg_staff_return_guard_insert
BEFORE INSERT ON staff_return_to_work_events
FOR EACH ROW
BEGIN
    DECLARE request_state VARCHAR(50);
    SELECT status INTO request_state FROM staff_leave_requests WHERE id = NEW.leave_request_id;
    IF request_state IS NULL OR request_state NOT IN ('approved', 'return_recorded', 'cancellation_requested') THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Return-to-work event requires an approved leave request';
    END IF;
END
SQL);

    $createTrigger('trg_staff_return_no_update', <<<'SQL'
CREATE TRIGGER trg_staff_return_no_update
BEFORE UPDATE ON staff_return_to_work_events
FOR EACH ROW
BEGIN
    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Return-to-work events are immutable; record a successor';
END
SQL);

    $createTrigger('trg_staff_return_no_delete', <<<'SQL'
CREATE TRIGGER trg_staff_return_no_delete
BEFORE DELETE ON staff_return_to_work_events
FOR EACH ROW
BEGIN
    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Return-to-work events are retained for reconciliation';
END
SQL);
};
