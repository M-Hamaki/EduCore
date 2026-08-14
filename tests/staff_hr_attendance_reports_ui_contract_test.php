<?php

declare(strict_types=1);

$pagePath = dirname(__DIR__) . '/admin/staff_attendance_reports.php';
$legacyPath = dirname(__DIR__) . '/admin/includes/staff_attendance_reports_legacy_surface.php';
$bootstrapPath = dirname(__DIR__) . '/src/Modules/Attendance/bootstrap.php';
$page = (string) file_get_contents($pagePath);
$legacy = (string) file_get_contents($legacyPath);
$bootstrap = (string) file_get_contents($bootstrapPath);

$failures = 0;
$assert = static function (bool $condition, string $message) use (&$failures): void {
    if (!$condition) {
        fwrite(STDERR, "FAIL: {$message}\n");
        ++$failures;
    }
};
$position = static function (string $needle, string $source): int {
    $value = strpos($source, $needle);
    return $value === false ? PHP_INT_MAX : $value;
};

$assert($position("Utilities::validateSession('admin')", $page) < $position('$database = new Database()', $page), 'admin authentication happens before database access');
$assert($position("Utilities::validateSession('admin')", $page) < $position('$_GET', $page), 'admin authentication happens before report filter input is read');
$assert(str_contains($page, 'usesNewResultsAsOfficial()'), 'official report surface is explicitly rollout-gated');
$assert(str_contains($page, "staff_attendance_reports_legacy_surface.php"), 'legacy URL behavior remains behind a protected compatibility surface before official cutover');
$assert(str_contains($legacy, "Utilities::validateSession('admin')"), 'compatibility surface remains independently protected');
$assert(
    str_contains($legacy, "dirname(__DIR__, 2) . '/includes/session_config.php'")
        && str_contains($legacy, "dirname(__DIR__, 2) . '/includes/admin_header.php'")
        && str_contains($legacy, "dirname(__DIR__, 2) . '/includes/widgets/hr_stat_cards.php'")
        && str_contains($legacy, "dirname(__DIR__, 2) . '/includes/admin_footer.php'"),
    'nested compatibility surface resolves shared includes from the project root'
);

$assert(str_contains($page, 'AttendanceModuleFactory'), 'page composes the report through the Attendance module factory');
$assert(str_contains($page, 'attendanceReportQuery()->query($reportInput, $reportScope)'), 'page delegates all official detail reads to AttendanceReportQueryService');
$assert(str_contains($page, 'AttendanceReportScope::forAllStaff()'), 'Admin composition root supplies the all-staff scope instead of taking scope from request input');
$assert(str_contains($page, 'AttendanceReportExporter'), 'CSV and print output use the shared safe exporter');
$assert(str_contains($page, 'streamCsv(') && str_contains($page, 'renderPrintTable('), 'both CSV and print export paths are serviced through the exporter');
$assert(!str_contains($page, 'buildDailyReportRows('), 'new official page does not fall back to daily legacy calculations');
$assert(!str_contains($page, 'buildLatenessRows('), 'new official page does not fall back to lateness legacy calculations');
$assert(!str_contains($page, 'buildMonthlyAgendaRows('), 'new official page does not fall back to agenda legacy calculations');
$assert(!str_contains($page, 'SELECT '), 'page contains no direct SQL');
$assert(!str_contains($page, 'Dompdf'), 'new official print path does not introduce a second PDF renderer');

$assert(str_contains($page, "getStaffReportAccessPolicy()"), 'existing view/export policy remains enforced');
$assert(str_contains($page, '!$canExportReports'), 'download controls and handlers reject disabled export policy');
$assert(str_contains($page, 'X-Content-Type-Options: nosniff'), 'file/print response sets an explicit content-type hardening header');
$assert(str_contains($page, "header('Content-Disposition: attachment; filename=\""), 'CSV response provides a deterministic attachment filename');
$assert(!str_contains($page, '$exception->getMessage()); ?>'), 'page does not expose raw exception messages in HTML');

$assert(str_contains($page, "name=\"date_from\"") && str_contains($page, "name=\"date_to\""), 'date range filters are present');
$assert(str_contains($page, "name=\"staff_user_id\""), 'per-worker filter is present');
$assert(str_contains($page, "name=\"org_unit_id\"") && str_contains($page, "name=\"job_title_id\"") && str_contains($page, "name=\"group_id\""), 'organization scope filters are present');
$assert(str_contains($page, "name=\"status\"") && str_contains($page, "name=\"violation\""), 'status and violation filters are present');
$assert(str_contains($page, "name=\"as_of\""), 'historical official-version filter is present');
$assert(str_contains($page, 'admin-filter-bar') && str_contains($page, 'admin-list-surface') && str_contains($page, 'admin-data-table'), 'page follows the centralized free-list surface structure');
$assert(str_contains($page, 'data-schedule-policy-version-id=') && str_contains($page, 'نسخة سياسة الدوام'), 'historical rows expose their frozen schedule-policy version');
$assert(!str_contains($page, '<style>'), 'page introduces no local style block');

$assert(str_contains($page, '$legacyReportType === \'daily\'') && str_contains($page, '$legacyReportType === \'agenda\''), 'legacy daily and agenda deep links are translated to official date ranges');
$assert(str_contains($page, '$legacyReportType === \'lateness\' ? \'late\' : \'all\''), 'legacy lateness deep links retain their violation intent');
$assert(str_contains($page, '$exportAction === \'pdf\'') && str_contains($page, '$exportAction = \'print\''), 'legacy PDF export links remain usable as a printer-friendly report');
$assert(str_contains($page, 'تصدير الصفحة CSV') && str_contains($page, 'طباعة الصفحة'), 'UI clearly limits direct exports to the shown page');
$assert(str_contains($page, "(array) (\$report['rows'] ?? [])"), 'export paths consume the same paged report DTO that is rendered');

$assert(str_contains($bootstrap, "'Presentation/AttendanceReportExporter.php'"), 'attendance compatibility bootstrap registers the exporter');

if ($failures > 0) {
    fwrite(STDERR, "{$failures} attendance report UI contract test failure(s).\n");
    exit(1);
}

echo "Attendance report UI contracts passed.\n";
