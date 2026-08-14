<?php

declare(strict_types=1);

$root = dirname(__DIR__);
$migrationPath = $root . '/database/migrations/20260730_staff_hr_schedule_calendar.php';
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

$source = is_file($migrationPath) ? (string) file_get_contents($migrationPath) : '';
$assert($source !== '', 'schedule migration exists and is readable');
if ($source !== '') {
    $migration = require $migrationPath;
    $assert(is_callable($migration), 'schedule migration returns a callable');

    foreach ([
        'staff_schedule_policies',
        'staff_schedule_policy_versions',
        'staff_schedule_days',
        'staff_schedule_segments',
        'staff_schedule_scopes',
        'staff_calendar_exceptions',
        'staff_schedule_change_requests',
        'staff_schedule_command_receipts',
        'staff_schedule_participant_locks',
    ] as $table) {
        $assertContains("CREATE TABLE `{$table}`", $source, "migration creates {$table}");
    }

    foreach ([
        'uk_staff_schedule_policy_code',
        'uk_staff_schedule_version_no',
        'uk_staff_schedule_day',
        'uk_staff_schedule_segment_sequence',
        'idx_staff_schedule_scope_resolution',
        'idx_staff_calendar_exception_resolution',
        'idx_staff_schedule_change_overlap',
        'uk_staff_schedule_change_idempotency',
        'uk_staff_schedule_command_idempotency',
        'idx_staff_schedule_participant_lock_updated',
    ] as $index) {
        $assertContains("`{$index}`", $source, "migration defines {$index}");
    }

    foreach ([
        'trg_staff_schedule_versions_immutable_update',
        'trg_staff_schedule_versions_immutable_delete',
        'trg_staff_schedule_days_immutable_insert',
        'trg_staff_schedule_days_immutable_update',
        'trg_staff_schedule_days_immutable_delete',
        'trg_staff_schedule_segments_immutable_insert',
        'trg_staff_schedule_segments_immutable_update',
        'trg_staff_schedule_segments_immutable_delete',
        'trg_staff_schedule_scopes_immutable_insert',
        'trg_staff_schedule_scopes_immutable_update',
        'trg_staff_schedule_scopes_immutable_delete',
        'trg_staff_calendar_exception_immutable_update',
        'trg_staff_calendar_exception_supersession_guard',
        'trg_staff_calendar_exception_immutable_delete',
    ] as $trigger) {
        $assertContains($trigger, $source, "published schedule immutability owns {$trigger}");
    }

    foreach ([
        "`segment_type` ENUM('work','paid_break','unpaid_break','on_call','overtime')",
        "`change_type` ENUM('temporary_shift','shift_swap','overtime','alternative_attendance')",
        '`end_day_offset` TINYINT UNSIGNED NOT NULL DEFAULT 0',
        '`entry_window_before_minutes` SMALLINT UNSIGNED NOT NULL DEFAULT 0',
        '`entry_window_after_minutes` SMALLINT UNSIGNED NOT NULL DEFAULT 0',
        '`exit_window_before_minutes` SMALLINT UNSIGNED NOT NULL DEFAULT 0',
        '`exit_window_after_minutes` SMALLINT UNSIGNED NOT NULL DEFAULT 0',
        '`season_start_mmdd` CHAR(5) NULL',
        '`season_end_mmdd` CHAR(5) NULL',
        '`approved_schedule_snapshot` JSON NULL',
        '`create_payload_hash` CHAR(64) NOT NULL',
        '`last_command_payload_hash` CHAR(64) NULL',
        '`publication_payload_hash` CHAR(64) NULL',
        '`payload_hash` CHAR(64) NOT NULL',
        '`lock_version` INT UNSIGNED NOT NULL DEFAULT 1',
        '`chk_staff_schedule_version_create_hash`',
        '`chk_staff_schedule_version_last_hash`',
        '`chk_staff_schedule_version_publication_hash`',
        '`chk_staff_calendar_exception_hash`',
        '`chk_staff_schedule_change_hash`',
        '`chk_staff_schedule_change_last_hash`',
        '`chk_staff_schedule_change_counterpart_acceptance_pair`',
        '`chk_staff_schedule_change_pending_swap`',
        '`chk_staff_schedule_change_acceptance_swap_only`',
    ] as $contract) {
        $assertContains($contract, $source, "schema preserves {$contract}");
    }

    $assertContains('information_schema.TABLES', $source, 'migration checks table existence before DDL');
    $assertContains('information_schema.TRIGGERS', $source, 'migration checks trigger existence before DDL');
    $assertContains(
        '`idx_staff_schedule_change_workflow` (`workflow_instance_id`, `status`)',
        $source,
        'schedule changes retain an indexed workflow contract reference'
    );
    $assert(
        !str_contains($source, 'FOREIGN KEY (`workflow_instance_id`) REFERENCES `staff_approval_instances`'),
        'clean migration order does not require the later workflow owner through a physical FK'
    );
    $assertContains(
        "str_replace('{ROW}', 'OLD', \$versionExpression)",
        $source,
        'child UPDATE immutability checks the original published parent'
    );
    $assertContains(
        "str_replace('{ROW}', 'NEW', \$versionExpression)",
        $source,
        'child UPDATE immutability also checks the destination parent'
    );
    $assert(
        preg_match('/\$db->exec\s*\(\s*[\'\"]\s*(?:DROP|TRUNCATE|ALTER)\b/i', $source) !== 1,
        'schedule migration remains additive'
    );
}

if ($failures > 0) {
    fwrite(STDERR, "{$failures} staff-HR schedule schema contract failure(s).\n");
    exit(1);
}

echo "Staff-HR schedule schema contracts passed.\n";
