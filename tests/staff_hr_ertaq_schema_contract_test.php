<?php

declare(strict_types=1);

$root = dirname(__DIR__);
$migrationPath = $root . '/database/migrations/20260730_staff_hr_ertaq.php';
$attachmentMigrationPath = $root . '/database/migrations/20260809_staff_hr_ertaq_private_attachments.php';
$registryPath = $root . '/src/Modules/Operations/Audit/AuditPolicyRegistry.php';
$migration = is_file($migrationPath) ? (string) file_get_contents($migrationPath) : '';
$attachmentMigration = is_file($attachmentMigrationPath) ? (string) file_get_contents($attachmentMigrationPath) : '';

require_once $registryPath;

use EduCore\Modules\Operations\Audit\AuditPolicyRegistry;

$failures = 0;
$assert = static function (bool $condition, string $message) use (&$failures): void {
    if (!$condition) {
        fwrite(STDERR, "FAIL: {$message}\n");
        ++$failures;
    }
};

$tables = [
    'staff_ertaq_tickets',
    'staff_ertaq_messages',
    'staff_ertaq_parties',
    'staff_ertaq_assignments',
    'staff_ertaq_watchers',
    'staff_ertaq_ticket_links',
    'staff_ertaq_sla_events',
    'staff_ertaq_urgent_events',
    'staff_ertaq_withdrawal_events',
];

foreach ($tables as $table) {
    $assert(
        str_contains($migration, 'CREATE TABLE ' . $table),
        $table . ' is created by the additive Ertaq migration'
    );
    $assert(
        AuditPolicyRegistry::isRegisteredTable($table),
        $table . ' is registered in the shared audit policy before use'
    );
    $assert(
        !AuditPolicyRegistry::allowsDirectUndo($table, 'update'),
        $table . ' fails closed for direct undo'
    );
}

$assert(
    str_contains($migration, 'WHERE TABLE_SCHEMA = DATABASE()')
        && str_contains($migration, 'WHERE TRIGGER_SCHEMA = DATABASE()'),
    'Ertaq migration is idempotent through schema inspection and never through request-time DDL'
);
$assert(!str_contains($migration, 'ON DELETE CASCADE'), 'Ertaq evidence schema has no cascading deletion path');
$assert(
    substr_count($migration, 'ON DELETE RESTRICT') >= 12,
    'Ertaq internal evidence relationships retain history with restrictive foreign keys'
);
$assert(
    !str_contains($migration, 'REFERENCES staff_discipline_')
        && !str_contains($migration, 'REFERENCES finance_'),
    'Ertaq keeps cross-module discipline and Finance references scalar rather than coupling table ownership'
);

$assert(
    str_contains($migration, "type ENUM('complaint','suggestion','inquiry','other')")
        && str_contains($migration, 'confidentiality_level ENUM(')
        && str_contains($migration, "status ENUM('new','triaged','assigned','in_progress','awaiting_requester','resolved','closed','reopened','withdrawal_requested','urgent_protected','cancelled')")
        && str_contains($migration, 'sla_policy_snapshot JSON NULL')
        && str_contains($migration, 'lock_version INT NOT NULL DEFAULT 1'),
    'ticket identity, confidential lifecycle, frozen SLA snapshot, and optimistic locking are explicit'
);
$assert(
    str_contains($migration, "risk_level ENUM('none','low','high','immediate')")
        && str_contains($migration, "priority ENUM('low','normal','high','urgent')")
        && str_contains($migration, 'chk_staff_ertaq_ticket_urgent'),
    'immediate risk must be modeled as an urgent ticket rather than an untyped flag'
);

$assert(
    str_contains($migration, 'body_cipher_or_text MEDIUMTEXT NOT NULL')
        && str_contains($migration, "visibility ENUM('requester','assigned_team','restricted','protection_team')")
        && str_contains($migration, 'trg_staff_ertaq_message_no_update')
        && str_contains($migration, 'trg_staff_ertaq_message_no_delete'),
    'sent message content has explicit visibility and cannot be edited or hard deleted'
);
$assert(
    str_contains($migration, "party_role ENUM('requester','complainant','accused','affected','witness','representative','recipient','observer','other')")
        && str_contains($migration, 'conflict_status ENUM(')
        && str_contains($migration, 'conflict_declared_at DATETIME(6) NULL')
        && str_contains($migration, 'UNIQUE KEY uk_staff_ertaq_party_idempotency (idempotency_key)')
        && str_contains($migration, 'trg_staff_ertaq_party_no_delete'),
    'ticket parties retain conflict evidence and cannot be silently removed'
);
$assert(
    str_contains($migration, 'assigned_team_id BIGINT NULL')
        && str_contains($migration, 'assigned_to_user_id INT NULL')
        && str_contains($migration, "status ENUM('active','accepted','superseded','completed','cancelled')")
        && str_contains($migration, 'trg_staff_ertaq_assignment_guard_update'),
    'assignment evidence supports a team or individual without replacing historical assignments'
);
$assert(
    str_contains($migration, "link_type ENUM('collective','duplicate_of','related','discipline_case','improvement_initiative','external_reference')")
        && str_contains($migration, 'target_resource_type VARCHAR(100) NULL')
        && str_contains($migration, 'target_resource_id BIGINT NULL')
        && str_contains($migration, 'trg_staff_ertaq_link_no_update'),
    'collective, duplicate, discipline, and initiative links preserve references without copying confidential content'
);

$assert(
    str_contains($migration, "event_type ENUM('created','first_response_due','response_recorded','overdue','escalated','paused','resumed','resolved','closed','reopened')")
        && str_contains($migration, 'escalation_snapshot JSON NULL')
        && str_contains($migration, 'trg_staff_ertaq_sla_no_delete'),
    'SLA due and escalation evidence are stored as restricted historical events'
);
$assert(
    str_contains($migration, 'route_snapshot JSON NOT NULL')
        && str_contains($migration, 'conflict_exclusion_snapshot JSON NOT NULL')
        && str_contains($migration, 'UNIQUE KEY uk_staff_ertaq_urgent_ticket (ticket_id)')
        && str_contains($migration, 'trg_staff_ertaq_urgent_guard_update')
        && str_contains($migration, 'trg_staff_ertaq_urgent_no_delete'),
    'urgent protection routing retains a one-per-ticket exclusion snapshot and cannot be deleted'
);
$assert(
    str_contains($migration, "event_type ENUM('requested','decided')")
        && str_contains($migration, 'request_event_id BIGINT NULL')
        && str_contains($migration, 'prior_ticket_status VARCHAR(40) NULL')
        && str_contains($migration, "outcome ENUM('withdrawn','continue_processing','rejected') NULL")
        && str_contains($migration, 'trg_staff_ertaq_withdrawal_no_update')
        && str_contains($migration, 'trg_staff_ertaq_withdrawal_no_delete'),
    'withdrawal after an investigation is an append-only request and decision, never a ticket deletion'
);
$assert(
    str_contains($attachmentMigration, 'CREATE TABLE staff_resource_attachments')
        && str_contains($attachmentMigration, "resource_type ENUM('ertaq_ticket','ertaq_message')")
        && str_contains($attachmentMigration, 'storage_ref VARCHAR(500) NOT NULL')
        && str_contains($attachmentMigration, 'content_sha256 CHAR(64) NOT NULL')
        && str_contains($attachmentMigration, 'UNIQUE KEY uk_staff_resource_attachment_idempotency (idempotency_key)')
        && AuditPolicyRegistry::isRegisteredTable('staff_resource_attachments')
        && !AuditPolicyRegistry::allowsDirectUndo('staff_resource_attachments', 'update'),
    'private Ertaq attachment metadata is audited, idempotent, and contains only a normalized private reference'
);
$assert(
    str_contains($attachmentMigration, 'ON DELETE RESTRICT')
        && str_contains($attachmentMigration, 'trg_staff_resource_attachment_guard_update')
        && str_contains($attachmentMigration, 'trg_staff_resource_attachment_no_delete')
        && !str_contains($attachmentMigration, 'ON DELETE CASCADE'),
    'Ertaq attachment evidence cannot cascade or hard-delete and its identity/storage metadata are immutable'
);

$protectedTriggerNames = [
    'trg_staff_ertaq_ticket_no_delete',
    'trg_staff_ertaq_message_no_update',
    'trg_staff_ertaq_message_no_delete',
    'trg_staff_ertaq_link_no_update',
    'trg_staff_ertaq_link_no_delete',
    'trg_staff_ertaq_urgent_no_delete',
    'trg_staff_ertaq_withdrawal_no_update',
    'trg_staff_ertaq_withdrawal_no_delete',
];
foreach ($protectedTriggerNames as $trigger) {
    $assert(str_contains($migration, $trigger), $trigger . ' protects Ertaq historical evidence');
}

$redacted = AuditPolicyRegistry::redact([
    'subject' => 'شكوى سرية',
    'body_cipher_or_text' => 'نص الرسالة السرية',
    'withdrawal_reason' => 'سبب السحب',
    'route_snapshot' => ['team' => 5],
    'conflict_exclusion_snapshot' => ['excluded' => [7]],
    'ticket_no' => 'ERT-2026-0001',
], 'staff_ertaq_tickets');
$assert(
    ($redacted['subject'] ?? null) === '[REDACTED]'
        && ($redacted['body_cipher_or_text'] ?? null) === '[REDACTED]'
        && ($redacted['withdrawal_reason'] ?? null) === '[REDACTED]'
        && ($redacted['route_snapshot'] ?? null) === '[REDACTED]'
        && ($redacted['conflict_exclusion_snapshot'] ?? null) === '[REDACTED]'
        && ($redacted['ticket_no'] ?? null) === 'ERT-2026-0001',
    'Ertaq audit redacts secret message and route details while retaining a safe ticket identifier'
);
$attachmentRedacted = AuditPolicyRegistry::redact([
    'storage_ref' => 'private:ertaq_attachments/hidden.pdf',
    'original_name' => 'hidden.pdf',
    'mime_type' => 'application/pdf',
], 'staff_resource_attachments');
$assert(
    ($attachmentRedacted['storage_ref'] ?? null) === '[REDACTED]'
        && ($attachmentRedacted['original_name'] ?? null) === '[REDACTED]'
        && ($attachmentRedacted['mime_type'] ?? null) === 'application/pdf',
    'private Ertaq attachment path and display name are redacted while safe MIME aggregation remains available'
);

if ($failures > 0) {
    fwrite(STDERR, "{$failures} Ertaq schema contract test failure(s).\n");
    exit(1);
}

echo "Staff-HR Ertaq schema contracts passed.\n";
