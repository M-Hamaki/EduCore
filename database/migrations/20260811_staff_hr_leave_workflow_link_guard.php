<?php

declare(strict_types=1);

/**
 * Allows the approval engine to attach its generated workflow instance exactly
 * once after a leave request becomes pending, while retaining immutable
 * submission evidence and rejecting replacement of an existing link.
 */
return static function (PDO $db): void {
    $statement = $db->prepare(
        'SELECT COUNT(*) FROM information_schema.TABLES
         WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?'
    );
    $statement->execute(['staff_leave_requests']);
    if ((int) $statement->fetchColumn() === 0) {
        throw new RuntimeException('Leave workflow-link hardening requires the leave ledger foundation migration.');
    }

    $db->exec('DROP TRIGGER IF EXISTS trg_staff_leave_request_guard_update');
    $db->exec(<<<'SQL'
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
        OR (
            NOT (NEW.workflow_instance_id <=> OLD.workflow_instance_id)
            AND NOT (
                OLD.status = 'pending_approval'
                AND NEW.status = 'pending_approval'
                AND OLD.workflow_instance_id IS NULL
                AND NEW.workflow_instance_id IS NOT NULL
            )
        )
        OR NOT (NEW.assignment_id <=> OLD.assignment_id)
        OR NOT (NEW.request_hash <=> OLD.request_hash)
    ) THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Submitted leave request evidence is immutable; create a successor';
    END IF;
END
SQL);
};
