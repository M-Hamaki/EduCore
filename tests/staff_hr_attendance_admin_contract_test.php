<?php

declare(strict_types=1);

$root = dirname(__DIR__);
$importPage = (string) file_get_contents($root . '/admin/staff_biometric_import.php');
$attendancePage = (string) file_get_contents($root . '/admin/staff_attendance.php');
$preparationService = (string) file_get_contents(
    $root . '/src/Modules/Attendance/Application/BiometricCsvImportPreparationService.php'
);
$header = (string) file_get_contents($root . '/includes/admin_header.php');

$failures = [];
$check = static function (string $name, bool $passed) use (&$failures): void {
    echo $name . ':' . ($passed ? 'PASS' : 'FAIL') . PHP_EOL;
    if (!$passed) {
        $failures[] = $name;
    }
};
$firstRequestRead = static function (string $source): int {
    $positions = [
        strpos($source, '$_POST'),
        strpos($source, '$_GET'),
        strpos($source, '$_FILES'),
        strpos($source, '$_SERVER[\'REQUEST_METHOD\']'),
    ];
    $positions = array_values(array_filter($positions, static fn ($position): bool => $position !== false));

    return $positions === [] ? PHP_INT_MAX : min($positions);
};

$importAuth = strpos($importPage, "Utilities::validateSession('admin');");
$attendanceAuth = strpos($attendancePage, "Utilities::validateSession('admin');");

$check('import.exists', $importPage !== '');
$check(
    'import.auth_before_request_processing',
    $importAuth !== false && $importAuth < $firstRequestRead($importPage)
);
$check(
    'import.csrf_for_all_write_actions',
    substr_count($importPage, 'requireCsrfPost();') >= 1
        && substr_count($importPage, 'csrfField()') >= 3
);
$check(
    'import.legacy_actions_preserved',
    str_contains($importPage, "'preview_biometric'")
        && str_contains($importPage, "'confirm_biometric'")
        && str_contains($importPage, "'cancel_biometric_preview'")
);
$check(
    'import.rollout_is_explicit',
    str_contains($importPage, 'StaffHrFeatureFlags::fromEnvironment()')
        && str_contains($importPage, '$useAttendanceEventPipeline')
        && str_contains($importPage, 'if (!$useAttendanceEventPipeline)')
);
$check(
    'import.csv_uses_shared_upload_guard',
    str_contains($importPage, 'FileUploadGuard::validate(')
        && str_contains($importPage, 'BIOMETRIC_MAX_BYTES')
);
$check(
    'import.new_pipeline_requires_capture_context',
    str_contains($importPage, "'entry_method_id'")
        && str_contains($importPage, "'device_timezone'")
        && str_contains($importPage, "'file_fingerprint'")
        && str_contains($importPage, "'clock_drift_threshold_seconds'")
);
$check(
    'import.new_pipeline_prepares_and_ingests_raw_events',
    str_contains($importPage, 'BiometricCsvImportPreparationService')
        && str_contains($importPage, 'attendanceEventIngestor()->ingest(')
        && str_contains($importPage, "'engine' => 'attendance_event'")
);
$check(
    'import.new_pipeline_precedes_legacy_synchronizer',
    strpos($importPage, 'if ($usesNewPreview)') !== false
        && strpos($importPage, 'if ($usesNewPreview)') < strpos($importPage, 'importBiometricRows(')
);
$check(
    'import.preview_masks_biometric_identity',
    str_contains($importPage, "['identity_hint']")
        && str_contains($importPage, 'identity_hint')
        && !str_contains($importPage, "echo htmlspecialchars((string)(\$pr['biometric_identity']")
);
$check(
    'import.new_pipeline_errors_are_presented_safely',
    str_contains($importPage, 'BiometricImportErrorPresenter::present(')
        && !str_contains($importPage, 'htmlspecialchars($exception->getMessage()')
);

$eventArrayStart = strpos($preparationService, '$events[] = [');
$eventArrayEnd = $eventArrayStart === false ? false : strpos($preparationService, '];', $eventArrayStart);
$eventPayload = $eventArrayStart === false || $eventArrayEnd === false
    ? ''
    : substr($preparationService, $eventArrayStart, $eventArrayEnd - $eventArrayStart);
$check(
    'preparation.never_forwards_legacy_staff_identity',
    $eventPayload !== ''
        && !str_contains($eventPayload, "'user_id'")
        && !str_contains($eventPayload, "'employee_code'")
        && str_contains($eventPayload, "'biometric_identity'")
);

$check('attendance.exists', $attendancePage !== '');
$check(
    'attendance.auth_before_request_processing',
    $attendanceAuth !== false && $attendanceAuth < $firstRequestRead($attendancePage)
);
$check(
    'attendance.legacy_actions_preserved',
    str_contains($attendancePage, "'save_bulk_attendance'")
        && str_contains($attendancePage, "'delete_attendance'")
        && str_contains($attendancePage, 'saveManualAttendanceWithAudit(')
);
$check(
    'attendance.reads_exception_summary_through_contract',
    str_contains($attendancePage, 'attendanceExceptionQuery()->review(')
        && str_contains($attendancePage, 'AttendanceModuleFactory')
        && str_contains($attendancePage, '$attendanceExceptionSnapshotError')
);
$officialBlock = strpos($attendancePage, '$isOfficialAttendanceMode &&');
$legacyWriter = strpos($attendancePage, 'saveManualAttendanceWithAudit(');
$check(
    'attendance.official_mode_blocks_legacy_writes_first',
    $officialBlock !== false
        && $legacyWriter !== false
        && $officialBlock < $legacyWriter
        && str_contains($attendancePage, 'usesNewResultsAsOfficial()')
);
$check(
    'attendance.official_mode_is_read_only_in_ui',
    str_contains($attendancePage, 'disabled aria-disabled="true"')
        && str_contains($attendancePage, 'السجل القديم للقراءة فقط')
        && str_contains($attendancePage, 'مسار التصحيح والمراجعة')
);
$check(
    'attendance.transition_errors_do_not_disclose_database_details',
    !str_contains($attendancePage, 'htmlspecialchars($exception->getMessage()')
        && str_contains($attendancePage, 'تحقق من تطبيق ترحيلات الحضور')
);
$check(
    'attendance.exception_navigation_is_discoverable',
    str_contains($attendancePage, 'hr_attendance_exceptions.php')
        && str_contains($header, "'hr_attendance_exceptions.php'")
        && str_contains($header, 'مركز الاستثناءات')
);

if ($failures !== []) {
    fwrite(STDERR, count($failures) . " staff-HR attendance admin contract failure(s).\n");
    exit(1);
}

echo "Staff-HR attendance admin contracts passed.\n";
