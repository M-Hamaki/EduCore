<?php

declare(strict_types=1);

/**
 * Source-level contract for the Staff-HR attendance evidence migration.
 *
 * This test intentionally opens no database connection. A later guarded
 * integration test owns MariaDB apply/re-run/rollback evidence in an isolated
 * *_test schema; this contract catches destructive or unsafe schema drift first.
 */

use EduCore\Modules\Operations\Audit\AuditPolicyRegistry;

$root = dirname(__DIR__);
$migrationPath = $root . '/database/migrations/20260730_staff_hr_attendance_engine.php';
$auditRegistryPath = $root . '/src/Modules/Operations/Audit/AuditPolicyRegistry.php';
$failures = 0;

$assert = static function (bool $condition, string $message) use (&$failures): void {
    if (!$condition) {
        fwrite(STDERR, "FAIL: {$message}\n");
        ++$failures;
    }
};
$assertContains = static function (string $needle, string $source, string $message) use ($assert): void {
    $assert(str_contains($source, $needle), $message);
};
$assertNotContains = static function (string $needle, string $source, string $message) use ($assert): void {
    $assert(!str_contains($source, $needle), $message);
};

$source = is_file($migrationPath) ? (string) file_get_contents($migrationPath) : '';
$assert($source !== '', 'attendance engine migration exists and is readable');

if ($source !== '') {
    $migration = require $migrationPath;
    $assert(is_callable($migration), 'attendance engine migration returns a callable');

    $tables = [
        'staff_attendance_entry_methods',
        'staff_biometric_import_batches',
        'staff_biometric_identity_mappings',
        'staff_biometric_events',
        'staff_attendance_runs',
        'staff_attendance_day_versions',
        'staff_attendance_segments',
        'staff_attendance_reason_lines',
        'staff_attendance_adjustments',
    ];
    foreach ($tables as $table) {
        $assertContains("CREATE TABLE `{$table}`", $source, "migration creates {$table}");
    }

    foreach ([
        'uk_staff_attendance_entry_method_code',
        'uk_staff_biometric_batch_idempotency',
        'uk_staff_biometric_batch_file_fingerprint',
        'uk_staff_biometric_mapping_identity_start',
        'uk_staff_biometric_mapping_active_identity',
        'uk_staff_biometric_event_idempotency',
        'uk_staff_biometric_event_device_external',
        'uk_staff_biometric_event_device_raw_hash',
        'uk_staff_attendance_run_idempotency',
        'uk_staff_attendance_day_version',
        'uk_staff_attendance_day_idempotent',
        'uk_staff_attendance_day_official',
        'uk_staff_attendance_day_supersedes',
        'uk_staff_attendance_segment_sequence',
        'uk_staff_attendance_reason_sequence',
        'uk_staff_attendance_adjustment_idempotency',
    ] as $index) {
        $assertContains("`{$index}`", $source, "migration defines {$index}");
    }

    foreach ([
        "`method_type` ENUM('biometric','manual_verified','device_fallback','access_log')",
        '`requires_reason` TINYINT(1) NOT NULL DEFAULT 0',
        '`requires_attachment` TINYINT(1) NOT NULL DEFAULT 0',
        '`requires_review` TINYINT(1) NOT NULL DEFAULT 1',
        '`allowed_scope` VARCHAR(50) NOT NULL',
        "`status` ENUM('active','inactive','retired')",
        '`entry_method_id` INT NOT NULL',
        'fk_staff_biometric_event_method',
        'Attendance entry method must exist and be active',
        'Attendance entry method requires a reason',
        'Attendance entry method requires attachment evidence',
        'Attendance entry method requires independent review',
        'chk_staff_biometric_event_self_review',
        'trg_staff_attendance_entry_method_guard_update',
        'Used attendance entry method semantics are immutable; retire and create a new method',
        'event_old.`entry_method_id` = OLD.`id`',
        'event_new.`entry_method_id` = NEW.`id`',
    ] as $entryMethodContract) {
        $assertContains($entryMethodContract, $source, "entry-method safety preserves {$entryMethodContract}");
    }
    $entryMethodGuardStart = strpos($source, 'CREATE TRIGGER `trg_staff_attendance_entry_method_guard_update`');
    $entryMethodGuardEnd = $entryMethodGuardStart === false
        ? false
        : strpos($source, "\nSQL);", $entryMethodGuardStart);
    $entryMethodGuard = ($entryMethodGuardStart === false || $entryMethodGuardEnd === false)
        ? ''
        : substr($source, $entryMethodGuardStart, $entryMethodGuardEnd - $entryMethodGuardStart);
    $assert($entryMethodGuard !== '', 'entry-method semantic update guard has an inspectable body');
    foreach (['code', 'method_type', 'requires_reason', 'requires_attachment', 'requires_review', 'allowed_scope'] as $semanticField) {
        $assertContains(
            "NOT (NEW.`{$semanticField}` <=> OLD.`{$semanticField}`)",
            $entryMethodGuard,
            "used entry method freezes semantic field {$semanticField}"
        );
    }
    $assertContains(
        'event_old.`entry_method_id` = OLD.`id`',
        $entryMethodGuard,
        'entry-method update guard checks events attached to the original method identity'
    );
    $assertContains(
        'event_new.`entry_method_id` = NEW.`id`',
        $entryMethodGuard,
        'entry-method update guard checks events attached to the destination method identity'
    );
    $assertNotContains('grants_attendance', $source, 'entry methods never grant attendance automatically');

    foreach ([
        '`device_event_at` DATETIME(6) NOT NULL',
        '`received_at` DATETIME(6) NOT NULL',
        '`device_timezone` VARCHAR(64) NOT NULL',
        '`normalized_event_at_utc` DATETIME(6) NULL',
        '`event_at_local` DATETIME(6) NULL',
        '`clock_offset_seconds` INT NULL',
        "`clock_status` ENUM('trusted','drifted','unknown','invalid')",
        'chk_staff_biometric_event_clock_normalization',
        '`raw_hash` CHAR(64) NOT NULL',
        '`raw_payload_ref` VARCHAR(500) NULL',
        'Raw attendance evidence, attribution, and clock fields are immutable',
    ] as $clockContract) {
        $assertContains($clockContract, $source, "clock/raw evidence preserves {$clockContract}");
    }
    $assert(
        preg_match('/`raw_payload`\s+(?:JSON|TEXT|LONGTEXT|VARCHAR)/i', $source) !== 1,
        'raw sensitive payload is referenced, not copied into an auditable row'
    );
    $assertNotContains('employee_code', $source, 'biometric identity remains independent from employee code');

    $eventMethodGuardStart = strpos($source, 'CREATE TRIGGER `trg_staff_biometric_event_method_insert`');
    $eventMethodGuardEnd = $eventMethodGuardStart === false
        ? false
        : strpos($source, "\nSQL);", $eventMethodGuardStart);
    $eventMethodGuard = ($eventMethodGuardStart === false || $eventMethodGuardEnd === false)
        ? ''
        : substr($source, $eventMethodGuardStart, $eventMethodGuardEnd - $eventMethodGuardStart);
    $assert($eventMethodGuard !== '', 'biometric event insertion guard has an inspectable body');
    foreach ([
        'matched_mapping.`id` = NEW.`identity_mapping_id`',
        'matched_mapping.`device_id` = NEW.`device_id`',
        'matched_mapping.`biometric_identity` = NEW.`biometric_identity`',
        'matched_mapping.`staff_user_id` = NEW.`staff_user_id`',
        'matched_mapping.`valid_from` <= COALESCE(NEW.`event_at_local`, NEW.`device_event_at`)',
        'COALESCE(NEW.`event_at_local`, NEW.`device_event_at`) < matched_mapping.`valid_to`',
        "NEW.`link_status` <> 'matched' AND NEW.`staff_user_id` IS NOT NULL",
        "NEW.`link_status` = 'unmatched' AND NEW.`identity_mapping_id` IS NOT NULL",
        'Biometric mapping must match device, identity, staff, and event time',
    ] as $eventMappingSafety) {
        $assertContains($eventMappingSafety, $eventMethodGuard, "event mapping safety preserves {$eventMappingSafety}");
    }

    $eventUpdateGuardStart = strpos($source, 'CREATE TRIGGER `trg_staff_biometric_event_guard_update`');
    $eventUpdateGuardEnd = $eventUpdateGuardStart === false
        ? false
        : strpos($source, "\nSQL);", $eventUpdateGuardStart);
    $eventUpdateGuard = ($eventUpdateGuardStart === false || $eventUpdateGuardEnd === false)
        ? ''
        : substr($source, $eventUpdateGuardStart, $eventUpdateGuardEnd - $eventUpdateGuardStart);
    $assert($eventUpdateGuard !== '', 'biometric event update guard has an inspectable body');
    foreach (['identity_mapping_id', 'staff_user_id', 'link_status', 'link_reason'] as $attributionField) {
        $assertContains(
            "NOT (NEW.`{$attributionField}` <=> OLD.`{$attributionField}`)",
            $eventUpdateGuard,
            "raw event freezes attribution field {$attributionField}"
        );
    }
    $assertContains(
        "OLD.`review_status` = 'pending' AND NEW.`review_status` = 'not_required'",
        $eventUpdateGuard,
        'pending review can only finish by approval or rejection, not bypass'
    );

    foreach ([
        '`active_identity_key` VARCHAR(220) GENERATED ALWAYS AS',
        'trg_staff_biometric_mapping_overlap_insert',
        'trg_staff_biometric_mapping_guard_update',
        'trg_staff_biometric_mapping_no_delete',
        'Biometric identity mapping period overlaps an existing mapping',
        'retire and create a new mapping',
    ] as $mappingContract) {
        $assertContains($mappingContract, $source, "dated identity mapping preserves {$mappingContract}");
    }

    foreach ([
        '`version_no` INT UNSIGNED NOT NULL',
        '`source_fingerprint` CHAR(64) NOT NULL',
        '`is_official` TINYINT(1) NOT NULL DEFAULT 0',
        '`officialized_by` INT NULL',
        '`officialized_at` DATETIME(6) NULL',
        '`supersedes_id` BIGINT NULL',
        '`official_work_date` DATE GENERATED ALWAYS AS',
        "CASE WHEN `is_official` = 1 THEN `work_date` ELSE NULL END",
        '`official_supersedes_id` BIGINT GENERATED ALWAYS AS',
        'CASE WHEN `officialized_at` IS NOT NULL THEN `supersedes_id` ELSE NULL END',
        'UNIQUE KEY `uk_staff_attendance_day_supersedes` (`official_supersedes_id`)',
        'chk_staff_attendance_day_official',
        'trg_staff_attendance_day_guard_insert',
        'trg_staff_attendance_day_guard_update',
        'trg_staff_attendance_day_no_delete',
        'Attendance day must be completed with children before official publication',
        'Attendance day mode and engine must match its calculation run',
        "official_run.`status` = 'completed'",
        'official_run.`mode` = NEW.`calculation_mode`',
        "official_run.`mode` IN ('official','recalculation')",
        'NEW.`work_date` BETWEEN official_run.`range_from` AND official_run.`range_to`',
        'NEW.`officialized_at` >= official_run.`finished_at`',
        'A former official attendance version cannot be republished; create a successor',
        'Official attendance successor must extend the current published history chain',
        'Official attendance successor requires a run that supersedes the predecessor run',
        'predecessor_day.`staff_user_id` = NEW.`staff_user_id`',
        'predecessor_day.`work_date` = NEW.`work_date`',
        'Official attendance requires a completed matching official run',
        'Superseding attendance preserves original publication metadata',
    ] as $officialContract) {
        $assertContains($officialContract, $source, "official day version preserves {$officialContract}");
    }
    $dayInsertGuardStart = strpos($source, 'CREATE TRIGGER `trg_staff_attendance_day_guard_insert`');
    $dayInsertGuardEnd = $dayInsertGuardStart === false
        ? false
        : strpos($source, "\nSQL);", $dayInsertGuardStart);
    $dayInsertGuard = ($dayInsertGuardStart === false || $dayInsertGuardEnd === false)
        ? ''
        : substr($source, $dayInsertGuardStart, $dayInsertGuardEnd - $dayInsertGuardStart);
    foreach ([
        'NEW.`is_official` <> 0',
        'calculation_run.`mode` = NEW.`calculation_mode`',
        'calculation_run.`engine_version` = NEW.`engine_version`',
        'NEW.`work_date` BETWEEN calculation_run.`range_from` AND calculation_run.`range_to`',
    ] as $dayInsertSafety) {
        $assertContains($dayInsertSafety, $dayInsertGuard, "day insert safety preserves {$dayInsertSafety}");
    }

    $dayUpdateGuardStart = strpos($source, 'CREATE TRIGGER `trg_staff_attendance_day_guard_update`');
    $dayUpdateGuardEnd = $dayUpdateGuardStart === false
        ? false
        : strpos($source, "\nSQL);", $dayUpdateGuardStart);
    $dayUpdateGuard = ($dayUpdateGuardStart === false || $dayUpdateGuardEnd === false)
        ? ''
        : substr($source, $dayUpdateGuardStart, $dayUpdateGuardEnd - $dayUpdateGuardStart);
    foreach ([
        "official_run.`status` = 'completed'",
        'official_run.`mode` = NEW.`calculation_mode`',
        "official_run.`mode` IN ('official','recalculation')",
        'NEW.`officialized_at` >= official_run.`finished_at`',
    ] as $dayPublicationSafety) {
        $assertContains($dayPublicationSafety, $dayUpdateGuard, "official publication safety preserves {$dayPublicationSafety}");
    }

    foreach ([
        'trg_staff_attendance_entry_method_no_delete',
        'trg_staff_attendance_entry_method_guard_update',
        'trg_staff_biometric_event_method_insert',
        'trg_staff_biometric_event_guard_update',
        'trg_staff_biometric_event_no_delete',
        'trg_staff_attendance_run_guard_update',
        'trg_staff_attendance_run_no_delete',
        'trg_staff_attendance_segment_guard_insert',
        'trg_staff_attendance_segment_no_update',
        'trg_staff_attendance_segment_no_delete',
        'trg_staff_attendance_reason_guard_insert',
        'trg_staff_attendance_reason_no_update',
        'trg_staff_attendance_reason_no_delete',
        'trg_staff_attendance_adjustment_guard_insert',
        'trg_staff_attendance_adjustment_guard_update',
        'trg_staff_attendance_adjustment_no_delete',
    ] as $trigger) {
        $assertContains($trigger, $source, "append-only attendance schema owns {$trigger}");
    }

    foreach (['trg_staff_attendance_segment_guard_insert', 'trg_staff_attendance_reason_guard_insert'] as $childInsertTrigger) {
        $childGuardStart = strpos($source, "CREATE TRIGGER `{$childInsertTrigger}`");
        $childGuardEnd = $childGuardStart === false ? false : strpos($source, "\nSQL);", $childGuardStart);
        $childGuard = ($childGuardStart === false || $childGuardEnd === false)
            ? ''
            : substr($source, $childGuardStart, $childGuardEnd - $childGuardStart);
        $assertContains(
            'day_version.`officialized_at` IS NOT NULL',
            $childGuard,
            "{$childInsertTrigger} freezes children even after the former official version is demoted"
        );
    }

    $adjustmentGuardStart = strpos($source, 'CREATE TRIGGER `trg_staff_attendance_adjustment_guard_update`');
    $adjustmentGuardEnd = $adjustmentGuardStart === false
        ? false
        : strpos($source, "\nSQL);", $adjustmentGuardStart);
    $adjustmentGuard = ($adjustmentGuardStart === false || $adjustmentGuardEnd === false)
        ? ''
        : substr($source, $adjustmentGuardStart, $adjustmentGuardEnd - $adjustmentGuardStart);
    $assert($adjustmentGuard !== '', 'attendance adjustment update guard has an inspectable body');
    foreach (['staff_user_id', 'work_date', 'before_version_id', 'proposed_values', 'workflow_instance_id', 'submitted_at'] as $submittedField) {
        $assertContains(
            "NOT (NEW.`{$submittedField}` <=> OLD.`{$submittedField}`)",
            $adjustmentGuard,
            "submitted adjustment freezes {$submittedField}"
        );
    }
    foreach ([
        "OLD.`status` IN ('approved','rejected','cancelled')",
        'approved_day.`staff_user_id` = NEW.`staff_user_id`',
        'approved_day.`work_date` = NEW.`work_date`',
        'approved_day.`supersedes_id` = NEW.`before_version_id`',
        'approved_day.`is_official` = 1',
        'before_day.`officialized_at` IS NOT NULL',
        'Approved adjustment version must officially supersede its source day',
    ] as $adjustmentSafety) {
        $assertContains($adjustmentSafety, $adjustmentGuard, "adjustment decision safety preserves {$adjustmentSafety}");
    }
    $adjustmentInsertGuardStart = strpos($source, 'CREATE TRIGGER `trg_staff_attendance_adjustment_guard_insert`');
    $adjustmentInsertGuardEnd = $adjustmentInsertGuardStart === false
        ? false
        : strpos($source, "\nSQL);", $adjustmentInsertGuardStart);
    $adjustmentInsertGuard = ($adjustmentInsertGuardStart === false || $adjustmentInsertGuardEnd === false)
        ? ''
        : substr($source, $adjustmentInsertGuardStart, $adjustmentInsertGuardEnd - $adjustmentInsertGuardStart);
    $assertContains(
        'before_day.`is_official` = 1',
        $adjustmentInsertGuard,
        'new adjustment can only branch from the current official attendance day'
    );

    foreach ([
        '`assignment_id` BIGINT NULL',
        '`schedule_policy_version_id` BIGINT NULL',
        '`calendar_exception_id` BIGINT NULL',
        'idx_staff_attendance_day_assignment_snapshot',
        'idx_staff_attendance_day_schedule_snapshot',
        'idx_staff_attendance_day_calendar_snapshot',
    ] as $snapshotContract) {
        $assertContains($snapshotContract, $source, "day result snapshots preserve {$snapshotContract}");
    }
    foreach (['REFERENCES `staff_assignments`', 'REFERENCES `staff_schedule_policy_versions`', 'REFERENCES `staff_calendar_exceptions`', 'REFERENCES `staff_approval_instances`'] as $laterForeignKey) {
        $assertNotContains($laterForeignKey, $source, "same-date migration ordering avoids premature {$laterForeignKey}");
    }

    $assertContains('information_schema.TABLES', $source, 'migration checks table existence before DDL');
    $assertContains('information_schema.TRIGGERS', $source, 'migration checks trigger existence before DDL');
    $assertContains('Rollback in an isolated environment', $source, 'migration records an explicit rollback strategy');
    $assert(
        preg_match('/\$db->exec\s*\(\s*[\'\"]\s*(?:DROP|TRUNCATE|ALTER)\b/i', $source) !== 1,
        'attendance migration remains additive and non-destructive'
    );
    $assertNotContains('ON DELETE CASCADE', $source, 'attendance evidence cannot cascade-delete');
}

$auditSource = is_file($auditRegistryPath) ? (string) file_get_contents($auditRegistryPath) : '';
$assert($auditSource !== '', 'audit policy registry exists and is readable');
if ($auditSource !== '') {
    require_once $auditRegistryPath;

    foreach ([
        'staff_attendance_entry_methods',
        'staff_biometric_import_batches',
        'staff_biometric_identity_mappings',
        'staff_biometric_events',
        'staff_attendance_runs',
        'staff_attendance_day_versions',
        'staff_attendance_segments',
        'staff_attendance_reason_lines',
        'staff_attendance_adjustments',
    ] as $auditTable) {
        $assert(AuditPolicyRegistry::isRegisteredTable($auditTable), "audit registry fails closed for {$auditTable}");
        $assert(!AuditPolicyRegistry::allowsDirectUndo($auditTable), "{$auditTable} requires versioning/workflow rollback, not direct undo");
    }

    $eventAudit = AuditPolicyRegistry::redact([
        'biometric_identity' => 'device-person-id',
        'raw_payload_ref' => 'private/evidence/ref',
        'reason_text' => 'sensitive reason',
        'raw_hash' => str_repeat('a', 64),
    ], 'staff_biometric_events');
    $assert(($eventAudit['biometric_identity'] ?? null) === '[REDACTED]', 'audit redacts biometric identity');
    $assert(($eventAudit['raw_payload_ref'] ?? null) === '[REDACTED]', 'audit redacts raw payload reference');
    $assert(($eventAudit['reason_text'] ?? null) === '[REDACTED]', 'audit redacts alternative attendance reason');
    $assert(($eventAudit['raw_hash'] ?? null) === str_repeat('a', 64), 'audit retains non-sensitive evidence hash');

    $adjustmentAudit = AuditPolicyRegistry::redact([
        'reason' => 'private correction reason',
        'proposed_values' => ['first_in' => '09:00'],
        'status' => 'pending',
    ], 'staff_attendance_adjustments');
    $assert(($adjustmentAudit['reason'] ?? null) === '[REDACTED]', 'audit redacts attendance adjustment reason');
    $assert(($adjustmentAudit['proposed_values'] ?? null) === '[REDACTED]', 'audit redacts proposed correction values');
    $assert(($adjustmentAudit['status'] ?? null) === 'pending', 'audit retains safe adjustment state');
}

if ($failures > 0) {
    fwrite(STDERR, "{$failures} staff-HR attendance schema contract failure(s).\n");
    exit(1);
}

echo "Staff-HR attendance schema contracts passed.\n";
