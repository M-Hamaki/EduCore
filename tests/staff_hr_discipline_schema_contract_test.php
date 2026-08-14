<?php

declare(strict_types=1);

$root = dirname(__DIR__);
$migrationPath = $root . '/database/migrations/20260730_staff_hr_discipline.php';
$hardeningMigrationPath = $root . '/database/migrations/20260809_staff_hr_discipline_appeal_reopen_hardening.php';
$registryPath = $root . '/src/Modules/Operations/Audit/AuditPolicyRegistry.php';
$migration = is_file($migrationPath) ? (string) file_get_contents($migrationPath) : '';
$hardening = is_file($hardeningMigrationPath) ? (string) file_get_contents($hardeningMigrationPath) : '';

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
    'staff_discipline_incidents',
    'staff_discipline_cases',
    'staff_discipline_case_parties',
    'staff_discipline_investigations',
    'staff_discipline_evidence',
    'staff_discipline_interim_measures',
    'staff_discipline_decisions',
    'staff_discipline_appeals',
    'staff_discipline_executions',
    'staff_discipline_finance_effects',
    'staff_discipline_reopen_events',
];

foreach ($tables as $table) {
    $assert(
        str_contains($migration, 'CREATE TABLE ' . $table),
        $table . ' is created by the additive discipline migration'
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

$assert(str_contains($migration, 'WHERE TABLE_SCHEMA = DATABASE()'), 'discipline migration remains idempotent through schema inspection only');
$assert(!str_contains($migration, 'ON DELETE CASCADE'), 'discipline evidence schema has no cascading deletion path');
$assert(
    substr_count($migration, 'ON DELETE RESTRICT') >= 20,
    'discipline relationships preserve historical evidence with restrictive foreign keys'
);

$assert(
    str_contains($migration, 'source_resource_type VARCHAR(100) NULL')
        && str_contains($migration, 'source_resource_id BIGINT NULL')
        && str_contains($migration, 'source_reference_snapshot JSON NULL'),
    'an incident references attendance, complaint, or document evidence without mutating its source'
);
$assert(
    str_contains($migration, 'case_no VARCHAR(80) NOT NULL')
        && str_contains($migration, 'confidentiality_level')
        && str_contains($migration, "status ENUM('reported','triage','under_investigation','pending_decision','decided','appeal_pending','upheld','amended','revoked','closed','reopened','cancelled')"),
    'case identity, confidentiality, and the complete discipline lifecycle are modeled explicitly'
);
$assert(
    str_contains($migration, 'party_role')
        && str_contains($migration, 'visibility_scope')
        && str_contains($migration, 'conflict_declared_at')
        && str_contains($migration, 'withdrawal_reason TEXT NULL')
        && str_contains($migration, 'party_hash CHAR(64) NOT NULL'),
    'case parties retain their role, limited visibility, and declared conflicts'
);
$assert(
    str_contains($migration, 'investigator_user_id INT NULL')
        && str_contains($migration, 'recommendation TEXT NULL')
        && str_contains($migration, 'findings TEXT NULL'),
    'investigations keep a distinct investigator, findings, and recommendation'
);
$assert(
    str_contains($migration, "storage_area ENUM('private') NOT NULL DEFAULT 'private'")
        && str_contains($migration, 'storage_ref VARCHAR(500) NULL')
        && str_contains($migration, 'chain_hash CHAR(64) NOT NULL')
        && str_contains($migration, 'prior_evidence_id BIGINT NULL'),
    'evidence stores private attachment metadata and a custody-chain reference without public file paths'
);
$assert(
    str_contains($migration, 'prepared_by_user_id INT NULL')
        && str_contains($migration, 'decided_by_user_id INT NULL')
        && str_contains($migration, "status ENUM('draft','proposed','approved','issued','amended','revoked','superseded','cancelled')"),
    'a decision records separate preparation and final decision evidence'
);
$assert(
    str_contains($migration, 'appellant_user_id INT NOT NULL')
        && str_contains($migration, 'reviewer_user_id INT NULL')
        && str_contains($migration, 'suspends_execution TINYINT(1) NOT NULL DEFAULT 0'),
    'appeals retain the appellant, an independent reviewer, and any execution suspension'
);
$assert(
    str_contains($migration, "status ENUM('planned','executed','suspended','reversed','cancelled')")
        && str_contains($migration, "effect_target ENUM('attendance','access','notification','finance','other') NOT NULL"),
    'execution is a separate evidence record rather than an implicit change to the decision'
);
$assert(
    str_contains($migration, 'effect_key CHAR(64) NOT NULL')
        && str_contains($migration, 'idempotency_key CHAR(64) NOT NULL')
        && str_contains($migration, "target_module ENUM('finance') NOT NULL DEFAULT 'finance'")
        && !str_contains($migration, 'salary_amount')
        && !str_contains($migration, 'payroll_amount'),
    'Finance integration is an idempotent fact queue and does not model payroll money in Staff'
);
$assert(
    str_contains($migration, 'new_evidence_id BIGINT NOT NULL')
        && str_contains($migration, 'authorized_by_user_id INT NULL')
        && str_contains($migration, 'prior_case_status'),
    'reopening requires new evidence and preserves the prior case state'
);
$assert(
    $hardening !== ''
        && str_contains($hardening, 'request_event_id BIGINT NULL')
        && str_contains($hardening, 'uk_staff_discipline_reopen_request_outcome')
        && str_contains($hardening, 'fk_staff_discipline_reopen_request')
        && str_contains($hardening, 'resolution_reason TEXT NULL'),
    'appeal/reopen hardening links an append-only resolution to its request and retains temporary-measure resolution evidence'
);
$assert(
    str_contains($hardening, "OLD.status = 'appeal_pending' AND NEW.status IN ('upheld', 'amended', 'revoked', 'decided', 'reopened')")
        && str_contains($hardening, "OLD.status IN ('upheld', 'amended', 'revoked', 'closed') AND NEW.status = 'reopened'")
        && str_contains($hardening, 'trg_staff_discipline_interim_guard_update'),
    'hardening permits only audited withdrawal/expiry and evidence-based reopening transitions while protecting final interim measures'
);

$appendOnlyTriggers = [
    'trg_staff_discipline_evidence_no_update',
    'trg_staff_discipline_evidence_no_delete',
    'trg_staff_discipline_reopen_no_update',
    'trg_staff_discipline_reopen_no_delete',
    'trg_staff_discipline_execution_no_delete',
];
foreach ($appendOnlyTriggers as $trigger) {
    $assert(str_contains($migration, $trigger), $trigger . ' protects official discipline evidence');
}

$assert(
    str_contains($migration, 'trg_staff_discipline_case_no_delete')
        && str_contains($migration, 'trg_staff_discipline_incident_no_delete')
        && str_contains($migration, 'trg_staff_discipline_decision_guard_update')
        && str_contains($migration, 'trg_staff_discipline_appeal_guard_insert'),
    'cases cannot be hard-deleted, issued decisions are immutable, and appeal review conflicts fail closed'
);
$assert(
    str_contains($migration, 'Decision preparer and decider must differ')
        && str_contains($migration, 'Appeal reviewer must differ from final decision maker'),
    'database guards make direct self-decision and same-person appeal review invalid'
);

$disciplineAudit = AuditPolicyRegistry::redact([
    'description' => 'تفاصيل حساسة للواقعة',
    'findings' => 'نتيجة التحقيق',
    'decision_reason' => 'سبب القرار',
    'appeal_reason' => 'سبب التظلم',
    'resolution_reason' => 'سبب إنهاء الإجراء المؤقت',
    'storage_ref' => 'storage/private/discipline/evidence.pdf',
    'case_no' => 'DISC-2026-0001',
], 'staff_discipline_cases');
$assert(
    ($disciplineAudit['description'] ?? null) === '[REDACTED]'
        && ($disciplineAudit['findings'] ?? null) === '[REDACTED]'
        && ($disciplineAudit['decision_reason'] ?? null) === '[REDACTED]'
        && ($disciplineAudit['appeal_reason'] ?? null) === '[REDACTED]'
        && ($disciplineAudit['resolution_reason'] ?? null) === '[REDACTED]'
        && ($disciplineAudit['storage_ref'] ?? null) === '[REDACTED]'
        && ($disciplineAudit['case_no'] ?? null) === 'DISC-2026-0001',
    'discipline audit redacts case-sensitive text and private evidence references while preserving a safe case identifier'
);

if ($failures > 0) {
    fwrite(STDERR, "{$failures} discipline schema contract test failure(s).\n");
    exit(1);
}

echo "Staff-HR discipline schema contracts passed.\n";
