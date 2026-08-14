<?php

declare(strict_types=1);

$root = dirname(__DIR__);
require_once $root . '/src/Modules/Operations/Audit/AuditService.php';

use EduCore\Modules\Operations\Audit\AuditPolicyRegistry;
use EduCore\Modules\Operations\Audit\AuditService;

$officialTables = [
    'staff_assignments',
    'staff_assignment_legacy_links',
    'staff_manager_assignments',
    'staff_policy_versions',
    'staff_schedule_policy_versions',
    'staff_attendance_day_versions',
    'staff_permission_requests',
    'staff_approval_instances',
    'staff_credential_records',
    'staff_leave_requests',
    'staff_leave_staffing_overrides',
    'staff_discipline_cases',
    'staff_ertaq_tickets',
    'staff_external_effects',
];

$missingTables = array_values(array_filter(
    $officialTables,
    static fn(string $table): bool => !AuditPolicyRegistry::isRegisteredTable($table)
));
$directlyUndoable = array_values(array_filter(
    $officialTables,
    static fn(string $table): bool => AuditPolicyRegistry::allowsDirectUndo($table, 'update')
));

$ticketAudit = AuditPolicyRegistry::redact([
    'status' => 'assigned',
    'subject' => 'شكوى حساسة',
    'body_cipher_or_text' => 'تفاصيل سرية',
], 'staff_ertaq_tickets');
$leaveAudit = AuditPolicyRegistry::redact([
    'duration_days' => 2,
    'reason' => 'سبب إجازة خاص',
    'diagnosis' => 'بيان صحي',
    'medical_notes' => 'ملاحظات الطبيب',
    'supporting_document_ref' => 'storage/private/leave.pdf',
    'policy_snapshot' => ['قرار' => 'خاص'],
], 'staff_leave_requests');
$disciplineSnapshot = AuditPolicyRegistry::undoSnapshot([
    'status' => 'investigating',
    'allegation' => 'تفاصيل الواقعة',
    'investigation_notes' => 'أقوال التحقيق',
], 'staff_discipline_cases');
$legacyLinkAudit = AuditPolicyRegistry::redact([
    'resolution_status' => 'quarantined',
    'legacy_source_key' => 'staff_profiles:9001',
    'source_payload_hash' => str_repeat('a', 64),
    'decision_idempotency_key' => 'assignment-backfill:9001',
], 'staff_assignment_legacy_links');
$credentialAudit = AuditPolicyRegistry::redact([
    'staff_user_id' => 101,
    'credential_kind' => 'document',
    'credential_key' => 'teaching-license',
    'title' => 'رخصة مهنية',
    'issuer' => 'جهة الإصدار',
    'attachment_id' => 77,
    'payload_hash' => str_repeat('b', 64),
    'idempotency_key' => str_repeat('c', 64),
    'expires_on' => '2026-08-31',
], 'staff_credential_record');

$_SESSION = [
    'user_id' => 9001,
    'name' => 'Staff HR Contract Test',
    'role' => 'admin',
];
$_SERVER['REQUEST_URI'] = '/tests/staff_hr_audit_policy_contract_test.php';
$_SERVER['REMOTE_ADDR'] = '127.0.0.1';
$_SERVER['HTTP_USER_AGENT'] = 'staff-hr-contract-test';

$db = new PDO('sqlite::memory:');
$db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$db->exec(
    'CREATE TABLE activity_logs (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        user_id INTEGER,
        user_name TEXT,
        user_role TEXT,
        action TEXT NOT NULL,
        target_type TEXT,
        target_id INTEGER,
        target_name TEXT,
        details TEXT,
        ip_address TEXT,
        academic_year_id INTEGER,
        request_id TEXT,
        batch_id TEXT,
        result TEXT,
        route TEXT,
        user_agent TEXT,
        undo_log_id INTEGER,
        created_at TEXT DEFAULT CURRENT_TIMESTAMP
    )'
);
$audit = new AuditService($db);

$db->beginTransaction();
$audit->recordEvent(
    'create',
    'staff_ertaq_tickets',
    77,
    'تذكرة اختبار',
    ['subject' => 'موضوع لا يجوز تسريبه', 'status' => 'submitted']
);
$db->rollBack();
$rollbackCount = (int)$db->query('SELECT COUNT(*) FROM activity_logs')->fetchColumn();

$db->beginTransaction();
$audit->recordEvent(
    'create',
    'staff_ertaq_tickets',
    78,
    'تذكرة اختبار',
    ['subject' => 'موضوع لا يجوز تسريبه', 'status' => 'submitted']
);
$db->commit();
$storedDetails = json_decode(
    (string)$db->query('SELECT details FROM activity_logs WHERE target_id = 78')->fetchColumn(),
    true
);

$db->exec('DROP TABLE activity_logs');
$failedClosed = false;
try {
    $audit->recordEvent('update', 'staff_ertaq_tickets', 78, 'تذكرة اختبار', []);
} catch (RuntimeException $exception) {
    $failedClosed = true;
}

$checks = [
    'all_staff_hr_resources_are_registered' => $missingTables === [],
    'official_staff_hr_resources_have_no_direct_undo' => $directlyUndoable === [],
    'unknown_resources_fail_closed' => !AuditPolicyRegistry::isRegisteredTable('staff_future_unregistered_resource')
        && AuditPolicyRegistry::directUndoBlockReason('staff_future_unregistered_resource') === 'unregistered_entity',
    'ertaq_sensitive_fields_are_entity_redacted' => ($ticketAudit['subject'] ?? null) === '[REDACTED]'
        && ($ticketAudit['body_cipher_or_text'] ?? null) === '[REDACTED]'
        && ($ticketAudit['status'] ?? null) === 'assigned',
    'medical_fields_are_redacted_without_hiding_totals' => ($leaveAudit['reason'] ?? null) === '[REDACTED]'
        && ($leaveAudit['diagnosis'] ?? null) === '[REDACTED]'
        && ($leaveAudit['medical_notes'] ?? null) === '[REDACTED]'
        && ($leaveAudit['supporting_document_ref'] ?? null) === '[REDACTED]'
        && ($leaveAudit['policy_snapshot'] ?? null) === '[REDACTED]'
        && ($leaveAudit['duration_days'] ?? null) === 2,
    'sensitive_undo_fields_are_excluded' => !array_key_exists('allegation', $disciplineSnapshot)
        && !array_key_exists('investigation_notes', $disciplineSnapshot)
        && ($disciplineSnapshot['status'] ?? null) === 'investigating',
    'assignment_backfill_ledger_hides_legacy_source_fingerprints' => ($legacyLinkAudit['resolution_status'] ?? null) === 'quarantined'
        && ($legacyLinkAudit['legacy_source_key'] ?? null) === '[REDACTED]'
        && ($legacyLinkAudit['source_payload_hash'] ?? null) === '[REDACTED]'
        && ($legacyLinkAudit['decision_idempotency_key'] ?? null) === '[REDACTED]',
    'credential_evidence_identifiers_are_redacted_without_hiding_expiry_status' => ($credentialAudit['credential_key'] ?? null) === '[REDACTED]'
        && ($credentialAudit['title'] ?? null) === '[REDACTED]'
        && ($credentialAudit['issuer'] ?? null) === '[REDACTED]'
        && ($credentialAudit['attachment_id'] ?? null) === '[REDACTED]'
        && ($credentialAudit['payload_hash'] ?? null) === '[REDACTED]'
        && ($credentialAudit['idempotency_key'] ?? null) === '[REDACTED]'
        && ($credentialAudit['expires_on'] ?? null) === '2026-08-31',
    'audit_participates_in_caller_transaction' => $rollbackCount === 0,
    'persisted_event_uses_entity_redaction' => ($storedDetails['subject'] ?? null) === '[REDACTED]'
        && ($storedDetails['status'] ?? null) === 'submitted',
    'mandatory_audit_failure_fails_closed' => $failedClosed,
];

$failed = [];
foreach ($checks as $name => $passed) {
    echo $name . ':' . ($passed ? 'PASS' : 'FAIL') . PHP_EOL;
    if (!$passed) {
        $failed[] = $name;
    }
}

exit($failed ? 1 : 0);
