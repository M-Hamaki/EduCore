<?php

declare(strict_types=1);

$root = dirname(__DIR__);
$service = (string) file_get_contents($root . '/classes/StaffAttendanceService.php');
$shiftPage = (string) file_get_contents($root . '/admin/staff_shifts.php');
$reportPage = (string) file_get_contents($root . '/admin/staff_attendance_reports.php');
$legacyReportSurface = (string) file_get_contents($root . '/admin/includes/staff_attendance_reports_legacy_surface.php');

$checks = [
    'legacy_default_shift_keys' => strpos($service, 'staff_shift_start') !== false
        && strpos($service, 'staff_shift_end') !== false
        && strpos($service, 'staff_shift_grace_minutes') !== false,
    'legacy_default_shift_values' => strpos($service, "'07:30'") !== false
        && strpos($service, "'14:30'") !== false
        && strpos($service, '$shiftGraceMinutes = 15;') !== false,
    'legacy_individual_override_table' => strpos($service, 'staff_shift_overrides') !== false
        && strpos($shiftPage, 'save_shift_override') !== false,
    'legacy_raw_import_entrypoints' => strpos($service, 'previewBiometricRows(') !== false
        && strpos($service, 'importBiometricRows(') !== false,
    'legacy_raw_logs_retained' => strpos($service, 'staff_biometric_logs') !== false,
    'legacy_late_calculation_exists' => strpos($service, 'calculateLateMinutes(') !== false,
    'legacy_early_leave_calculation_absent' => strpos($service, 'calculateEarlyLeaveMinutes(') === false,
    'legacy_report_types_retained' => strpos($reportPage, 'daily') !== false
        && strpos($reportPage, 'lateness') !== false
        && strpos($reportPage, "'agenda'") !== false
        && strpos($legacyReportSurface, 'monthly_agenda') !== false,
    'legacy_public_service_methods_retained' => strpos($service, 'public function getAttendanceByDate(') !== false
        && strpos($service, 'public function buildDailyReportRows(') !== false
        && strpos($service, 'public function buildMonthlyAgendaRows(') !== false,
];

$failed = [];
foreach ($checks as $name => $passed) {
    echo $name . ':' . ($passed ? 'PASS' : 'FAIL') . PHP_EOL;
    if (!$passed) {
        $failed[] = $name;
    }
}

exit($failed ? 1 : 0);
