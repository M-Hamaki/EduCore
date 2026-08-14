<?php

declare(strict_types=1);

/**
 * Official attendance reporting surface.
 *
 * Legacy links remain available during rollout through the protected
 * compatibility surface. Once Staff-HR attendance is official, this entrypoint
 * reads only immutable official-day report DTOs through the Attendance module.
 */
$page_title = 'تقارير حضور العاملين';
$custom_page_title = true;

require_once '../config/database.php';
require_once '../classes/StaffAttendanceService.php';
require_once '../classes/utilities.php';
require_once '../includes/session_config.php';
Utilities::validateSession('admin');

require_once '../vendor/autoload.php';

$staffHrFlags = \EduCore\Modules\Staff\Infrastructure\StaffHrFeatureFlags::fromEnvironment();
if (!$staffHrFlags->usesNewResultsAsOfficial()) {
    // Existing URLs and exports keep their legacy behavior until the
    // migration-owned result has an explicit official cutover.
    require __DIR__ . '/includes/staff_attendance_reports_legacy_surface.php';
    exit;
}

$database = new Database();
$db = $database->getConnection();
$legacyAttendanceAccess = new StaffAttendanceService($db);
$accessPolicy = $legacyAttendanceAccess->getStaffReportAccessPolicy();
$canViewReports = (bool) ($accessPolicy['allow_view'] ?? false);
$canExportReports = (bool) ($accessPolicy['allow_export'] ?? false);

$escape = static fn (mixed $value): string => htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
$today = new DateTimeImmutable('today', new DateTimeZone('Africa/Cairo'));
$defaultFrom = $today->modify('first day of this month')->format('Y-m-d');
$defaultTo = $today->format('Y-m-d');

$readText = static function (string $key, string $fallback = ''): string {
    $value = $_GET[$key] ?? $fallback;
    return is_string($value) ? trim($value) : $fallback;
};

// Preserve useful legacy deep links by translating their date/range intent
// into the official query input. The old report type itself has no authority.
$legacyReportType = $readText('report_type');
$legacyDate = $readText('date');
$legacyMonth = $readText('month');
$legacyUserId = $readText('user_id');
$legacyFrom = $readText('date_from');
$legacyTo = $readText('date_to');
if ($legacyReportType === 'daily'
    && preg_match('/^\d{4}-\d{2}-\d{2}$/D', $legacyDate) === 1
    && $legacyFrom === '' && $legacyTo === '') {
    $legacyFrom = $legacyDate;
    $legacyTo = $legacyDate;
}
if ($legacyReportType === 'agenda'
    && preg_match('/^\d{4}-\d{2}$/D', $legacyMonth) === 1
    && $legacyFrom === '' && $legacyTo === '') {
    $legacyMonthStart = DateTimeImmutable::createFromFormat('!Y-m', $legacyMonth, new DateTimeZone('Africa/Cairo'));
    if ($legacyMonthStart !== false) {
        $legacyFrom = $legacyMonthStart->format('Y-m-d');
        $legacyTo = $legacyMonthStart->modify('last day of this month')->format('Y-m-d');
    }
}

$rawAsOf = $readText('as_of');
if (preg_match('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}$/D', $rawAsOf) === 1) {
    $rawAsOf = str_replace('T', ' ', $rawAsOf) . ':00';
}

$reportInput = [
    'date_from' => $legacyFrom !== '' ? $legacyFrom : $defaultFrom,
    'date_to' => $legacyTo !== '' ? $legacyTo : $defaultTo,
    'staff_user_id' => $readText('staff_user_id', $legacyUserId),
    'org_unit_id' => $readText('org_unit_id'),
    'job_title_id' => $readText('job_title_id'),
    'group_id' => $readText('group_id'),
    'status' => $readText('status', 'all'),
    'violation' => $readText('violation', $legacyReportType === 'lateness' ? 'late' : 'all'),
    'as_of' => $rawAsOf,
    'page' => $readText('page', '1'),
    'page_size' => $readText('page_size', '50'),
];

$exportAction = $readText('export');
if ($exportAction === 'pdf') {
    // Existing PDF links now open a printer-friendly official report; users
    // can still save it as PDF through the browser without a second renderer.
    $exportAction = 'print';
}
if (!in_array($exportAction, ['', 'csv', 'print'], true)) {
    $exportAction = '';
}

$scopeOptions = [
    'org_unit' => [],
    'job_title' => [],
    'group' => [],
    'staff' => [],
];
$report = null;
$reportError = null;
$scopeOptionsError = null;
$reportScope = null;
$reportExporter = null;

$reportErrorMessages = [
    'ATTENDANCE_REPORT_SCOPE_DENIED' => 'لا تملك صلاحية الاطلاع على بيانات هذا العامل.',
    'ATTENDANCE_REPORT_SCOPE_LEAK_BLOCKED' => 'تم إيقاف التقرير لحماية نطاق الصلاحيات. حاول مرة أخرى أو راجع مدير النظام.',
    'ATTENDANCE_REPORT_DIMENSION_UNRESOLVED' => 'لا يمكن إكمال التقرير لأن نطاق العامل التاريخي يحتاج إلى مراجعة.',
    'ATTENDANCE_REPORT_PROJECTION_REQUIRED' => 'النطاق واسع للتفاصيل المباشرة. حدّد فترة أو عاملًا واحدًا، أو استخدم التقرير المجمع عند تفعيله.',
    'ATTENDANCE_REPORT_EXPORT_ROW_INVALID' => 'تعذر تصدير صف من التقرير بشكل آمن.',
];

if (!$canViewReports) {
    $reportError = 'عرض تقارير حضور العاملين غير متاح حاليًا حسب إعدادات النظام.';
} else {
    try {
        $attendanceFactory = new \EduCore\Modules\Attendance\Infrastructure\AttendanceModuleFactory(
            $db,
            new \EduCore\Modules\Operations\Audit\AuditService($db)
        );
        // This is an Admin-only composition point. A future manager portal
        // must mint a bounded scope through its own authorization boundary.
        $reportScope = \EduCore\Modules\Attendance\Application\AttendanceReportScope::forAllStaff();
        $reportExporter = new \EduCore\Modules\Attendance\Presentation\AttendanceReportExporter();

        try {
            $scopeOptions = $attendanceFactory->scheduleScopeOptions()->options();
        } catch (Throwable $exception) {
            error_log('attendance report scope options unavailable: ' . $exception->getMessage());
            $scopeOptionsError = 'تعذر تحميل بعض قوائم الفلاتر؛ ما زال بإمكانك استخدام التاريخ وحالة الحضور.';
        }

        $report = $attendanceFactory->attendanceReportQuery()->query($reportInput, $reportScope);

        if ($exportAction !== '' && !$canExportReports) {
            $reportError = 'تصدير تقارير حضور العاملين غير متاح حاليًا حسب إعدادات النظام.';
        } elseif ($exportAction === 'csv') {
            $filters = (array) ($report['filters'] ?? []);
            $filename = 'staff-attendance-'
                . (string) ($filters['date_from'] ?? $defaultFrom)
                . '-to-'
                . (string) ($filters['date_to'] ?? $defaultTo)
                . '-page-'
                . (int) (($report['page']['number'] ?? 1))
                . '.csv';
            header('Content-Type: text/csv; charset=UTF-8');
            header('Content-Disposition: attachment; filename="' . $filename . '"');
            header('X-Content-Type-Options: nosniff');
            $reportExporter->streamCsv(
                (array) ($report['rows'] ?? []),
                $reportScope,
                static function (string $chunk): void {
                    echo $chunk;
                }
            );
            exit;
        } elseif ($exportAction === 'print') {
            $filters = (array) ($report['filters'] ?? []);
            $printTitle = 'تقرير الحضور والانصراف من '
                . (string) ($filters['date_from'] ?? $defaultFrom)
                . ' إلى '
                . (string) ($filters['date_to'] ?? $defaultTo);
            header('Content-Type: text/html; charset=UTF-8');
            header('X-Content-Type-Options: nosniff');
            echo '<!doctype html><html lang="ar" dir="rtl"><head><meta charset="utf-8"><title>'
                . $escape($printTitle)
                . '</title></head><body>';
            echo '<main><h1>' . $escape($printTitle) . '</h1><p>الصفحة '
                . (int) ($report['page']['number'] ?? 1)
                . ' من '
                . (int) ($report['page']['total_pages'] ?? 1)
                . '</p>';
            echo $reportExporter->renderPrintTable((array) ($report['rows'] ?? []), $reportScope);
            echo '<script>window.addEventListener("load", function () { window.print(); });</script></main></body></html>';
            exit;
        }
    } catch (Throwable $exception) {
        error_log('official attendance report unavailable: ' . $exception->getMessage());
        $reportError = $reportErrorMessages[$exception->getMessage()]
            ?? 'تعذر تحميل التقرير الرسمي الآن. تأكد من تنفيذ ترحيلات الحضور المطلوبة ثم حاول مرة أخرى.';
        $report = null;
    }
}

$formValues = $report !== null ? (array) ($report['filters'] ?? []) : $reportInput;
$formValues['as_of'] = $rawAsOf;
$formValues['page_size'] = (string) ($formValues['page_size'] ?? $reportInput['page_size']);
$formValues['status'] = (string) ($formValues['status'] ?? 'all');
$formValues['violation'] = (string) ($formValues['violation'] ?? 'all');

$toOptionMap = static function (array $items): array {
    $map = [];
    foreach ($items as $item) {
        if (!is_array($item)) {
            continue;
        }
        $id = (int) ($item['id'] ?? 0);
        if ($id > 0) {
            $map[$id] = (string) ($item['label'] ?? ('#' . $id));
        }
    }
    return $map;
};
$staffLabels = $toOptionMap((array) ($scopeOptions['staff'] ?? []));

$statusLabels = [
    'present' => 'حاضر',
    'absent' => 'غائب',
    'partial' => 'حضور جزئي',
    'non_working' => 'غير يوم عمل',
];
$statusClasses = [
    'present' => 'success',
    'absent' => 'danger',
    'partial' => 'warning',
    'non_working' => 'secondary',
];
$totals = $report !== null ? (array) ($report['totals'] ?? []) : [];
$page = $report !== null ? (array) ($report['page'] ?? []) : [];
$rows = $report !== null ? (array) ($report['rows'] ?? []) : [];

$asOfInput = $formValues['as_of'] ?? '';
if (is_string($asOfInput) && preg_match('/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/D', $asOfInput) === 1) {
    $asOfInput = str_replace(' ', 'T', substr($asOfInput, 0, 16));
}

$paginationBase = [
    'date_from' => (string) ($formValues['date_from'] ?? $defaultFrom),
    'date_to' => (string) ($formValues['date_to'] ?? $defaultTo),
    'staff_user_id' => (string) ($formValues['staff_user_id'] ?? ''),
    'org_unit_id' => (string) ($formValues['org_unit_id'] ?? ''),
    'job_title_id' => (string) ($formValues['job_title_id'] ?? ''),
    'group_id' => (string) ($formValues['group_id'] ?? ''),
    'status' => (string) ($formValues['status'] ?? 'all'),
    'violation' => (string) ($formValues['violation'] ?? 'all'),
    'as_of' => (string) $asOfInput,
    'page_size' => (string) ($formValues['page_size'] ?? '50'),
];
$pageUrl = static function (int $number) use ($paginationBase): string {
    $parameters = $paginationBase;
    $parameters['page'] = $number;
    return 'staff_attendance_reports.php?' . http_build_query($parameters);
};

$reasonSummary = static function (array $row): string {
    $reasons = $row['reasons'] ?? [];
    if (!is_array($reasons) || $reasons === []) {
        return '—';
    }
    $parts = [];
    foreach ($reasons as $reason) {
        if (!is_array($reason)) {
            continue;
        }
        $code = trim((string) ($reason['reason_code'] ?? ''));
        if ($code === '') {
            continue;
        }
        $parts[] = $code . ' (' . max(0, (int) ($reason['minutes'] ?? 0)) . ' د)';
    }
    return $parts === [] ? '—' : implode('، ', $parts);
};

require_once '../includes/admin_header.php';
?>

<div class="d-flex justify-content-between flex-wrap flex-md-nowrap align-items-center pt-3 pb-2 mb-3 border-bottom">
    <div>
        <h1 class="h2 mb-1"><i class="fas fa-chart-line me-2"></i>تقارير حضور العاملين</h1>
        <p class="text-muted mb-0">قراءة النسخ الرسمية فقط مع تفاصيل قابلة للتتبع حسب الفترة والعامل والقوة والمجموعة.</p>
    </div>
    <div class="btn-toolbar mb-2 mb-md-0 gap-2">
        <a href="staff_attendance.php" class="btn btn-outline-secondary">
            <i class="fas fa-arrow-right me-1"></i>العودة للحضور
        </a>
    </div>
</div>

<div class="alert alert-info alert-dismissible fade show" role="alert">
    <i class="fas fa-shield-alt me-2"></i>
    يعرض هذا السطح النتيجة الرسمية فقط. تصدير CSV والطباعة يخرجان الصفحة الظاهرة حاليًا وبنفس نطاق الصلاحية.
    <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="إغلاق"></button>
</div>

<?php if ($scopeOptionsError !== null): ?>
<div class="alert alert-warning alert-dismissible fade show" role="alert">
    <i class="fas fa-exclamation-triangle me-2"></i><?php echo $escape($scopeOptionsError); ?>
    <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="إغلاق"></button>
</div>
<?php endif; ?>

<?php if ($reportError !== null): ?>
<div class="alert alert-danger alert-dismissible fade show" role="alert">
    <i class="fas fa-exclamation-circle me-2"></i><?php echo $escape($reportError); ?>
    <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="إغلاق"></button>
</div>
<?php endif; ?>

<?php if ($report !== null): ?>
<div class="row row-cols-2 row-cols-md-4 g-3 mb-4">
    <div class="col">
        <div class="stat-card" style="--card-gradient: linear-gradient(135deg, #3b82f6, #2563eb);">
            <div class="stat-card-icon"><i class="fas fa-calendar-check"></i></div>
            <div class="stat-card-info">
                <div class="stat-card-number counter" data-target="<?php echo (int) ($totals['official_days'] ?? 0); ?>">0</div>
                <div class="stat-card-label">أيام رسمية</div>
                <div class="stat-card-sub"><i class="fas fa-filter"></i>ضمن النطاق</div>
            </div>
        </div>
    </div>
    <div class="col">
        <div class="stat-card" style="--card-gradient: linear-gradient(135deg, #10b981, #059669);">
            <div class="stat-card-icon"><i class="fas fa-user-check"></i></div>
            <div class="stat-card-info">
                <div class="stat-card-number counter" data-target="<?php echo (int) ($totals['present_days'] ?? 0); ?>">0</div>
                <div class="stat-card-label">حضور مكتمل</div>
                <div class="stat-card-sub"><i class="fas fa-clock"></i><?php echo (int) ($totals['worked_minutes'] ?? 0); ?> دقيقة عمل</div>
            </div>
        </div>
    </div>
    <div class="col">
        <div class="stat-card" style="--card-gradient: linear-gradient(135deg, #ef4444, #dc2626);">
            <div class="stat-card-icon"><i class="fas fa-user-times"></i></div>
            <div class="stat-card-info">
                <div class="stat-card-number counter" data-target="<?php echo (int) ($totals['absent_days'] ?? 0); ?>">0</div>
                <div class="stat-card-label">غياب غير مغطى</div>
                <div class="stat-card-sub"><i class="fas fa-calendar-day"></i><?php echo (int) ($totals['eligible_workdays'] ?? 0); ?> يوم مؤهل</div>
            </div>
        </div>
    </div>
    <div class="col">
        <div class="stat-card" style="--card-gradient: linear-gradient(135deg, #f59e0b, #d97706);">
            <div class="stat-card-icon"><i class="fas fa-percent"></i></div>
            <div class="stat-card-info">
                <div class="stat-card-number"><span class="counter" data-target="<?php echo (float) ($totals['absence_percentage'] ?? 0); ?>">0</span>%</div>
                <div class="stat-card-label">نسبة الغياب</div>
                <div class="stat-card-sub"><i class="fas fa-hourglass-half"></i><?php echo (int) ($totals['missing_minutes'] ?? 0); ?> دقيقة ناقصة</div>
            </div>
        </div>
    </div>
</div>
<?php endif; ?>

<form method="get" class="admin-filter-bar">
    <div class="admin-filter-controls">
        <div>
            <label class="form-label" for="report-date-from">من</label>
            <input id="report-date-from" type="date" name="date_from" class="form-control form-control-sm" value="<?php echo $escape($formValues['date_from'] ?? $defaultFrom); ?>">
        </div>
        <div>
            <label class="form-label" for="report-date-to">إلى</label>
            <input id="report-date-to" type="date" name="date_to" class="form-control form-control-sm" value="<?php echo $escape($formValues['date_to'] ?? $defaultTo); ?>">
        </div>
        <div>
            <label class="form-label" for="report-staff">العامل</label>
            <select id="report-staff" name="staff_user_id" class="form-select form-select-sm">
                <option value="">كل العاملين</option>
                <?php foreach ((array) ($scopeOptions['staff'] ?? []) as $option): ?>
                    <?php $optionId = (int) ($option['id'] ?? 0); ?>
                    <?php if ($optionId > 0): ?>
                    <option value="<?php echo $optionId; ?>" <?php echo (string) $optionId === (string) ($formValues['staff_user_id'] ?? '') ? 'selected' : ''; ?>>
                        <?php echo $escape($option['label'] ?? ('#' . $optionId)); ?>
                    </option>
                    <?php endif; ?>
                <?php endforeach; ?>
            </select>
        </div>
        <div>
            <label class="form-label" for="report-org-unit">القوة / الوحدة</label>
            <select id="report-org-unit" name="org_unit_id" class="form-select form-select-sm">
                <option value="">كل القوى</option>
                <?php foreach ((array) ($scopeOptions['org_unit'] ?? []) as $option): ?>
                    <?php $optionId = (int) ($option['id'] ?? 0); ?>
                    <?php if ($optionId > 0): ?>
                    <option value="<?php echo $optionId; ?>" <?php echo (string) $optionId === (string) ($formValues['org_unit_id'] ?? '') ? 'selected' : ''; ?>>
                        <?php echo $escape($option['label'] ?? ('#' . $optionId)); ?>
                    </option>
                    <?php endif; ?>
                <?php endforeach; ?>
            </select>
        </div>
        <div>
            <label class="form-label" for="report-job-title">المسمى الوظيفي</label>
            <select id="report-job-title" name="job_title_id" class="form-select form-select-sm">
                <option value="">كل المسميات</option>
                <?php foreach ((array) ($scopeOptions['job_title'] ?? []) as $option): ?>
                    <?php $optionId = (int) ($option['id'] ?? 0); ?>
                    <?php if ($optionId > 0): ?>
                    <option value="<?php echo $optionId; ?>" <?php echo (string) $optionId === (string) ($formValues['job_title_id'] ?? '') ? 'selected' : ''; ?>>
                        <?php echo $escape($option['label'] ?? ('#' . $optionId)); ?>
                    </option>
                    <?php endif; ?>
                <?php endforeach; ?>
            </select>
        </div>
        <div>
            <label class="form-label" for="report-group">المجموعة</label>
            <select id="report-group" name="group_id" class="form-select form-select-sm">
                <option value="">كل المجموعات</option>
                <?php foreach ((array) ($scopeOptions['group'] ?? []) as $option): ?>
                    <?php $optionId = (int) ($option['id'] ?? 0); ?>
                    <?php if ($optionId > 0): ?>
                    <option value="<?php echo $optionId; ?>" <?php echo (string) $optionId === (string) ($formValues['group_id'] ?? '') ? 'selected' : ''; ?>>
                        <?php echo $escape($option['label'] ?? ('#' . $optionId)); ?>
                    </option>
                    <?php endif; ?>
                <?php endforeach; ?>
            </select>
        </div>
        <div>
            <label class="form-label" for="report-status">الحالة</label>
            <select id="report-status" name="status" class="form-select form-select-sm">
                <option value="all" <?php echo ($formValues['status'] ?? 'all') === 'all' ? 'selected' : ''; ?>>كل الحالات</option>
                <option value="present" <?php echo ($formValues['status'] ?? '') === 'present' ? 'selected' : ''; ?>>حاضر</option>
                <option value="absent" <?php echo ($formValues['status'] ?? '') === 'absent' ? 'selected' : ''; ?>>غائب</option>
                <option value="partial" <?php echo ($formValues['status'] ?? '') === 'partial' ? 'selected' : ''; ?>>حضور جزئي</option>
                <option value="non_working" <?php echo ($formValues['status'] ?? '') === 'non_working' ? 'selected' : ''; ?>>غير يوم عمل</option>
                <option value="exception" <?php echo ($formValues['status'] ?? '') === 'exception' ? 'selected' : ''; ?>>يحتاج مراجعة</option>
            </select>
        </div>
        <div>
            <label class="form-label" for="report-violation">التركيز</label>
            <select id="report-violation" name="violation" class="form-select form-select-sm">
                <option value="all" <?php echo ($formValues['violation'] ?? 'all') === 'all' ? 'selected' : ''; ?>>كل النتائج</option>
                <option value="absence" <?php echo ($formValues['violation'] ?? '') === 'absence' ? 'selected' : ''; ?>>الغياب</option>
                <option value="late" <?php echo ($formValues['violation'] ?? '') === 'late' ? 'selected' : ''; ?>>التأخير</option>
                <option value="early_leave" <?php echo ($formValues['violation'] ?? '') === 'early_leave' ? 'selected' : ''; ?>>انصراف مبكر</option>
                <option value="missing" <?php echo ($formValues['violation'] ?? '') === 'missing' ? 'selected' : ''; ?>>دقائق ناقصة</option>
                <option value="permission" <?php echo ($formValues['violation'] ?? '') === 'permission' ? 'selected' : ''; ?>>إذن معتمد</option>
                <option value="mission" <?php echo ($formValues['violation'] ?? '') === 'mission' ? 'selected' : ''; ?>>مأمورية</option>
                <option value="leave" <?php echo ($formValues['violation'] ?? '') === 'leave' ? 'selected' : ''; ?>>إجازة</option>
            </select>
        </div>
        <div>
            <label class="form-label" for="report-as-of">نسخة سابقة (اختياري)</label>
            <input id="report-as-of" type="datetime-local" name="as_of" class="form-control form-control-sm" value="<?php echo $escape($asOfInput); ?>">
        </div>
        <div>
            <label class="form-label" for="report-page-size">صفوف الصفحة</label>
            <select id="report-page-size" name="page_size" class="form-select form-select-sm">
                <?php foreach ([25, 50, 100, 250] as $size): ?>
                <option value="<?php echo $size; ?>" <?php echo (string) $size === (string) ($formValues['page_size'] ?? '50') ? 'selected' : ''; ?>><?php echo $size; ?></option>
                <?php endforeach; ?>
            </select>
        </div>
    </div>
    <div class="admin-filter-actions">
        <button type="submit" class="btn btn-light btn-sm">
            <i class="fas fa-search me-1"></i>عرض التقرير
        </button>
        <a href="staff_attendance_reports.php" class="btn btn-light btn-sm">
            <i class="fas fa-rotate-left me-1"></i>إعادة تعيين
        </a>
        <button type="submit" name="export" value="csv" class="btn btn-outline-success btn-sm" <?php echo !$canExportReports || $report === null ? 'disabled' : ''; ?>>
            <i class="fas fa-file-csv me-1"></i>تصدير الصفحة CSV
        </button>
        <button type="submit" name="export" value="print" class="btn btn-outline-primary btn-sm" <?php echo !$canExportReports || $report === null ? 'disabled' : ''; ?>>
            <i class="fas fa-print me-1"></i>طباعة الصفحة
        </button>
    </div>
</form>

<?php if ($report !== null): ?>
    <?php foreach ((array) ($report['warnings'] ?? []) as $warning): ?>
    <div class="alert alert-warning" role="alert">
        <i class="fas fa-history me-2"></i><?php echo $escape($warning); ?>
    </div>
    <?php endforeach; ?>

    <div class="admin-list-surface">
        <div class="d-flex justify-content-between align-items-center flex-wrap gap-2 px-3 py-2 border-bottom">
            <div>
                <strong><i class="fas fa-list-check me-1"></i>تفاصيل الأيام الرسمية</strong>
                <span class="text-muted ms-2">إجمالي <?php echo (int) ($page['total_rows'] ?? 0); ?> صف</span>
            </div>
            <span class="badge bg-primary">الصفحة <?php echo (int) ($page['number'] ?? 1); ?> / <?php echo (int) ($page['total_pages'] ?? 1); ?></span>
        </div>
        <div class="admin-table-wrap">
            <table class="table table-hover table-striped admin-data-table mb-0">
                <thead>
                    <tr>
                        <th>التاريخ</th>
                        <th>العامل</th>
                        <th>الحالة</th>
                        <th>الدوام والبصمات</th>
                        <th>الدقائق</th>
                        <th>التغطية والإجازة</th>
                        <th>النطاق وقت اليوم</th>
                        <th>الأسباب</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($rows as $row): ?>
                        <?php
                        if (!is_array($row)) {
                            continue;
                        }
                        $staffId = (int) ($row['staff_user_id'] ?? 0);
                        $status = (string) ($row['status'] ?? '');
                        $metrics = is_array($row['metrics'] ?? null) ? $row['metrics'] : [];
                        $dimensions = is_array($row['dimensions'] ?? null) ? $row['dimensions'] : [];
                        $groupIds = array_map('intval', (array) ($dimensions['group_ids'] ?? []));
                        sort($groupIds, SORT_NUMERIC);
                        ?>
                    <tr>
                        <td class="text-nowrap"><?php echo $escape($row['work_date'] ?? '—'); ?></td>
                        <td>
                            <div class="fw-semibold"><?php echo $escape($staffLabels[$staffId] ?? ('عامل #' . $staffId)); ?></div>
                            <small class="text-muted">المعرف: <?php echo $staffId; ?></small>
                        </td>
                        <td>
                            <span class="badge bg-<?php echo $escape($statusClasses[$status] ?? 'secondary'); ?>">
                                <?php echo $escape($statusLabels[$status] ?? $status ?: 'غير محدد'); ?>
                            </span>
                        </td>
                        <td class="small">
                            <div>المتوقع: <?php echo $escape($row['expected_start'] ?? '—'); ?> — <?php echo $escape($row['expected_end'] ?? '—'); ?></div>
                            <div>الفعلـي: <?php echo $escape($row['first_in'] ?? '—'); ?> — <?php echo $escape($row['last_out'] ?? '—'); ?></div>
                            <div class="text-muted" data-schedule-policy-version-id="<?php echo (int) ($row['schedule_policy_version_id'] ?? 0); ?>">نسخة سياسة الدوام: <?php echo (int) ($row['schedule_policy_version_id'] ?? 0); ?></div>
                        </td>
                        <td class="small">
                            <div>عمل: <?php echo (int) ($metrics['worked_minutes'] ?? 0); ?> / <?php echo (int) ($metrics['required_minutes'] ?? 0); ?></div>
                            <div class="text-warning-emphasis">تأخير: <?php echo (int) ($metrics['late_minutes'] ?? 0); ?> · مبكر: <?php echo (int) ($metrics['early_leave_minutes'] ?? 0); ?></div>
                            <div class="text-danger">ناقص: <?php echo (int) ($metrics['missing_minutes'] ?? 0); ?></div>
                        </td>
                        <td class="small">
                            <div>إذن: <?php echo (int) ($metrics['covered_late_minutes'] ?? 0) + (int) ($metrics['covered_early_minutes'] ?? 0); ?> دقيقة</div>
                            <div>مأمورية: <?php echo (int) ($metrics['mission_minutes'] ?? 0); ?> · إجازة: <?php echo (int) ($metrics['leave_minutes'] ?? 0); ?></div>
                        </td>
                        <td class="small">
                            <div>تعيين #<?php echo (int) ($dimensions['assignment_id'] ?? 0); ?></div>
                            <div>وحدة #<?php echo (int) ($dimensions['org_unit_id'] ?? 0); ?> · مسمى #<?php echo (int) ($dimensions['job_title_id'] ?? 0); ?></div>
                            <div>مجموعات: <?php echo $escape($groupIds === [] ? '—' : implode('، ', $groupIds)); ?></div>
                        </td>
                        <td class="small"><?php echo $escape($reasonSummary($row)); ?></td>
                    </tr>
                    <?php endforeach; ?>
                    <?php if ($rows === []): ?>
                    <tr>
                        <td colspan="8" class="text-center text-muted py-4">
                            <i class="fas fa-circle-info me-1"></i>لا توجد أيام رسمية مطابقة للفلاتر المختارة.
                        </td>
                    </tr>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
        <?php if ((int) ($page['total_pages'] ?? 1) > 1): ?>
        <nav class="p-3" aria-label="صفحات تقرير الحضور">
            <ul class="pagination mb-0 justify-content-center">
                <?php $currentPage = (int) ($page['number'] ?? 1); ?>
                <li class="page-item <?php echo $currentPage <= 1 ? 'disabled' : ''; ?>">
                    <a class="page-link" href="<?php echo $escape($pageUrl(max(1, $currentPage - 1))); ?>">السابق</a>
                </li>
                <li class="page-item active"><span class="page-link"><?php echo $currentPage; ?></span></li>
                <li class="page-item <?php echo $currentPage >= (int) ($page['total_pages'] ?? 1) ? 'disabled' : ''; ?>">
                    <a class="page-link" href="<?php echo $escape($pageUrl(min((int) ($page['total_pages'] ?? 1), $currentPage + 1))); ?>">التالي</a>
                </li>
            </ul>
        </nav>
        <?php endif; ?>
    </div>
<?php endif; ?>

<?php require_once '../includes/admin_footer.php'; ?>
