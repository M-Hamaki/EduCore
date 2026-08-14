<?php
/**
 * تقارير حضور الموظفين
 * - تقرير يومي
 * - تقرير تأخيرات
 * - أجندة شهرية
 * - تصدير CSV (Excel)
 */
$page_title = "تقارير حضور الموظفين";
$custom_page_title = true;

require_once dirname(__DIR__, 2) . '/config/database.php';
require_once dirname(__DIR__, 2) . '/classes/StaffAttendanceService.php';
require_once dirname(__DIR__, 2) . '/classes/utilities.php';
require_once dirname(__DIR__, 2) . '/includes/session_config.php';
Utilities::validateSession('admin');

if (file_exists(dirname(__DIR__, 2) . '/vendor/autoload.php')) {
    require_once dirname(__DIR__, 2) . '/vendor/autoload.php';
}

$database = new Database();
$db = $database->getConnection();
$attendanceService = new StaffAttendanceService($db);

$reportType = $_GET['report_type'] ?? 'daily';
$reportDate = $_GET['date'] ?? date('Y-m-d');
$dateFrom = $_GET['date_from'] ?? date('Y-m-01');
$dateTo = $_GET['date_to'] ?? date('Y-m-t');
$month = $_GET['month'] ?? date('Y-m');
$userId = (int)($_GET['user_id'] ?? 0);
$exportType = $_GET['export'] ?? '';
$exportCsv = ($exportType === 'csv');
$exportPdf = ($exportType === 'pdf');

// تعقيم تواريخ مدخلة من $_GET لتفادي قيم غير صالحة (للعرض ولبناء أسماء الملفات)
$reportDate = preg_match('/^\d{4}-\d{2}-\d{2}$/', $reportDate) ? $reportDate : date('Y-m-d');
$dateFrom   = preg_match('/^\d{4}-\d{2}-\d{2}$/', $dateFrom)   ? $dateFrom   : date('Y-m-01');
$dateTo     = preg_match('/^\d{4}-\d{2}-\d{2}$/', $dateTo)     ? $dateTo     : date('Y-m-t');
$month      = preg_match('/^\d{4}-\d{2}$/', $month)            ? $month      : date('Y-m');

/**
 * تعقيم خلية CSV لتفادي هجمات Formula Injection في Excel.
 * القيم التي تبدأ بـ = + - @ أو TAB/CR تُسبق بعلامة اقتباس مفردة لتحييدها.
 */
$sanitizeCsvCell = static function ($cell) {
    $str = (string)$cell;
    if ($str === '') {
        return '';
    }
    $first = $str[0];
    if ($first === '=' || $first === '+' || $first === '-' || $first === '@' || $first === "\t" || $first === "\r") {
        return "'" . $str;
    }
    return $str;
};

/**
 * تعقيم جزء من اسم ملف لتفادي حقن الـ Content-Disposition header.
 * يُبقي الأرقام والحروف والشرطات والنقاط فقط.
 */
$sanitizeFileSegment = static function ($segment): string {
    $clean = preg_replace('/[^0-9A-Za-z_\-.]/', '_', (string)$segment);
    // منع تسلسلات مسار مثل ../
    $clean = str_replace(['..', '/'], ['_', ''], $clean);
    return $clean !== '' ? $clean : 'report';
};

$attendanceStatus = [
    'present' => 'حاضر',
    'absent' => 'غائب',
    'late' => 'متأخر',
    'excused' => 'بعذر'
];

$staffList = $attendanceService->getActiveStaffList();
$reportAccessPolicy = $attendanceService->getStaffReportAccessPolicy();
$canViewReports = (bool)$reportAccessPolicy['allow_view'];
$canExportReports = (bool)$reportAccessPolicy['allow_export'];

$sendCsv = static function (string $filename, array $headers, array $rows) use ($sanitizeCsvCell, $sanitizeFileSegment): void {
    // تعقيم اسم الملف لتفادي حقن الـ Content-Disposition header
    $safeFilename = $sanitizeFileSegment($filename);
    header('Content-Type: text/csv; charset=UTF-8');
    header('Content-Disposition: attachment; filename="' . $safeFilename . '"');
    echo "\xEF\xBB\xBF";
    $out = fopen('php://output', 'w');
    // تعقيم العناوين أيضاً (قد تحتوي على مدخلات مستخدم في صفحات أخرى)
    fputcsv($out, array_map($sanitizeCsvCell, $headers));
    foreach ($rows as $r) {
        fputcsv($out, array_map($sanitizeCsvCell, $r));
    }
    fclose($out);
    exit;
};

$sendPdf = static function (string $filename, string $title, array $headers, array $rows) use ($sanitizeFileSegment): bool {
    if (!class_exists('Dompdf\\Dompdf') || !class_exists('Dompdf\\Options')) {
        return false;
    }

    // تعقيم اسم الملف لتفادي حقن الـ Content-Disposition header
    $safeFilename = $sanitizeFileSegment($filename);

    $html = '<!doctype html><html lang="ar" dir="rtl"><head><meta charset="utf-8">';
    $html .= '<style>
        body { font-family: DejaVu Sans, sans-serif; direction: rtl; }
        h2 { margin: 0 0 14px 0; }
        table { width: 100%; border-collapse: collapse; font-size: 12px; }
        th, td { border: 1px solid #888; padding: 6px; text-align: right; vertical-align: top; }
        th { background: #f0f0f0; }
        .meta { margin-bottom: 10px; color: #444; font-size: 12px; }
    </style></head><body>';
    $html .= '<h2>' . htmlspecialchars($title, ENT_QUOTES, 'UTF-8') . '</h2>';
    $html .= '<div class="meta">تاريخ التصدير: ' . date('Y-m-d H:i') . '</div>';
    $html .= '<table><thead><tr>';
    foreach ($headers as $h) {
        $html .= '<th>' . htmlspecialchars($h, ENT_QUOTES, 'UTF-8') . '</th>';
    }
    $html .= '</tr></thead><tbody>';
    foreach ($rows as $r) {
        $html .= '<tr>';
        foreach ($r as $cell) {
            $html .= '<td>' . htmlspecialchars((string)$cell, ENT_QUOTES, 'UTF-8') . '</td>';
        }
        $html .= '</tr>';
    }
    $html .= '</tbody></table></body></html>';

    $options = new Dompdf\Options();
    $options->set('isRemoteEnabled', false);
    $options->set('isHtml5ParserEnabled', true);
    $dompdf = new Dompdf\Dompdf($options);
    $dompdf->loadHtml($html, 'UTF-8');
    $dompdf->setPaper('A4', 'landscape');
    $dompdf->render();

    header('Content-Type: application/pdf');
    header('Content-Disposition: attachment; filename="' . $safeFilename . '"');
    echo $dompdf->output();
    exit;
};

$pdfExportError = null;
$reportAccessError = null;

$dailyRows = [];
$latenessRows = [];
$agendaRows = [];

if (!$canViewReports) {
    $reportAccessError = 'عرض تقارير حضور الموظفين غير متاح حالياً حسب إعدادات النظام.';
}

if (($exportCsv || $exportPdf) && !$canExportReports) {
    $reportAccessError = 'تصدير تقارير حضور الموظفين غير متاح حالياً حسب إعدادات النظام.';
    $exportCsv = false;
    $exportPdf = false;
}

if ($canViewReports && $reportType === 'daily') {
    $dailyRows = $attendanceService->buildDailyReportRows($reportDate, $staffList, $attendanceStatus);

    if ($exportCsv) {
        $csvRows = array_map(static function ($r) {
            return [$r['name'], $r['status'], $r['check_in'], $r['check_out'], $r['late_minutes'], $r['note']];
        }, $dailyRows);
        $sendCsv('staff_daily_attendance_' . $reportDate . '.csv', ['الموظف','الحالة','الحضور','الانصراف','دقائق التأخير','ملاحظات'], $csvRows);
    }
    if ($exportPdf) {
        $pdfRows = array_map(static function ($r) {
            return [$r['name'], $r['status'], $r['check_in'], $r['check_out'], $r['late_minutes'], $r['note']];
        }, $dailyRows);
        $ok = $sendPdf('staff_daily_attendance_' . $reportDate . '.pdf', 'تقرير الحضور اليومي - ' . $reportDate, ['الموظف','الحالة','الحضور','الانصراف','دقائق التأخير','ملاحظات'], $pdfRows);
        if (!$ok) {
            $pdfExportError = 'تصدير PDF غير متاح حالياً (مكتبة dompdf غير محملة).';
        }
    }
}

if ($canViewReports && $reportType === 'lateness') {
        $latenessRows = $attendanceService->buildLatenessRows($dateFrom, $dateTo);

    if ($exportCsv) {
        $csvRows = array_map(static function ($r) {
            return [
                $r['attendance_date'],
                $r['name'],
                $r['check_in'] ? substr($r['check_in'], 0, 5) : '-',
                (string)$r['late_minutes'],
                $r['notes'] ?: '-'
            ];
        }, $latenessRows);
        $sendCsv('staff_lateness_' . $dateFrom . '_to_' . $dateTo . '.csv', ['التاريخ','الموظف','وقت الحضور','دقائق التأخير','ملاحظات'], $csvRows);
    }
    if ($exportPdf) {
        $pdfRows = array_map(static function ($r) {
            return [
                $r['attendance_date'],
                $r['name'],
                $r['check_in'] ? substr($r['check_in'], 0, 5) : '-',
                (string)$r['late_minutes'],
                $r['notes'] ?: '-'
            ];
        }, $latenessRows);
        $ok = $sendPdf('staff_lateness_' . $dateFrom . '_to_' . $dateTo . '.pdf', 'تقرير التأخيرات من ' . $dateFrom . ' إلى ' . $dateTo, ['التاريخ','الموظف','وقت الحضور','دقائق التأخير','ملاحظات'], $pdfRows);
        if (!$ok) {
            $pdfExportError = 'تصدير PDF غير متاح حالياً (مكتبة dompdf غير محملة).';
        }
    }
}

if ($canViewReports && $reportType === 'agenda') {
    if (!preg_match('/^\d{4}-\d{2}$/', $month)) {
        $month = date('Y-m');
    }

    if ($userId <= 0 && !empty($staffList)) {
        $userId = (int)$staffList[0]['id'];
    }

    $agendaRows = $attendanceService->buildMonthlyAgendaRows($month, $userId);

    if ($exportCsv) {
        $csvRows = array_map(static function ($r) {
            return [$r['date'], $r['day'], $r['label']];
        }, $agendaRows);
        $sendCsv('staff_monthly_agenda_' . $month . '.csv', ['التاريخ','اليوم','الحالة'], $csvRows);
    }
    if ($exportPdf) {
        $pdfRows = array_map(static function ($r) {
            return [$r['date'], $r['day'], $r['label']];
        }, $agendaRows);
        $ok = $sendPdf('staff_monthly_agenda_' . $month . '.pdf', 'الأجندة الشهرية - ' . $month, ['التاريخ','اليوم','الحالة'], $pdfRows);
        if (!$ok) {
            $pdfExportError = 'تصدير PDF غير متاح حالياً (مكتبة dompdf غير محملة).';
        }
    }
}

$reportSummaryCards = [];
if ($canViewReports && $reportType === 'daily') {
    $dailyCounts = ['present' => 0, 'absent' => 0, 'late' => 0, 'excused' => 0];
    foreach ($dailyRows as $row) {
        $statusKey = array_search($row['status'], $attendanceStatus, true);
        if ($statusKey !== false) {
            $dailyCounts[$statusKey]++;
        }
    }

    $reportSummaryCards = [
        ['value' => count($dailyRows), 'label' => 'إجمالي الموظفين', 'icon' => 'fa-users', 'gradient' => '#3b82f6, #2563eb'],
        ['value' => $dailyCounts['present'], 'label' => 'حاضر', 'icon' => 'fa-user-check', 'gradient' => '#10b981, #059669'],
        ['value' => $dailyCounts['late'], 'label' => 'متأخر', 'icon' => 'fa-clock', 'gradient' => '#f59e0b, #d97706'],
        ['value' => $dailyCounts['absent'], 'label' => 'غائب', 'icon' => 'fa-user-times', 'gradient' => '#ef4444, #dc2626'],
        ['value' => $dailyCounts['excused'], 'label' => 'بعذر', 'icon' => 'fa-user-shield', 'gradient' => '#0ea5e9, #0284c7'],
    ];
} elseif ($canViewReports && $reportType === 'lateness') {
    $totalLateMinutes = 0;
    foreach ($latenessRows as $row) {
        $totalLateMinutes += (int)$row['late_minutes'];
    }

    $reportSummaryCards = [
        ['value' => count($latenessRows), 'label' => 'حالات التأخير', 'icon' => 'fa-clock', 'gradient' => '#f59e0b, #d97706'],
        ['value' => $totalLateMinutes, 'label' => 'إجمالي دقائق التأخير', 'icon' => 'fa-hourglass-half', 'gradient' => '#ef4444, #dc2626'],
        ['value' => count(array_unique(array_map(static function ($row) { return $row['name']; }, $latenessRows))), 'label' => 'موظفون متأخرون', 'icon' => 'fa-users', 'gradient' => '#8b5cf6, #7c3aed'],
    ];
} elseif ($canViewReports && $reportType === 'agenda') {
    $agendaCounts = [];
    foreach ($agendaRows as $row) {
        $label = $row['label'] ?? 'غير محدد';
        if (!isset($agendaCounts[$label])) {
            $agendaCounts[$label] = 0;
        }
        $agendaCounts[$label]++;
    }

    $agendaGradients = [
        'حاضر' => '#10b981, #059669',
        'غائب' => '#ef4444, #dc2626',
        'متأخر' => '#f59e0b, #d97706',
        'بعذر' => '#0ea5e9, #0284c7',
        'إجازة' => '#8b5cf6, #7c3aed'
    ];
    $agendaIcons = [
        'حاضر' => 'fa-user-check',
        'غائب' => 'fa-user-times',
        'متأخر' => 'fa-clock',
        'بعذر' => 'fa-user-shield',
        'إجازة' => 'fa-calendar-check'
    ];

    $reportSummaryCards[] = ['value' => count($agendaRows), 'label' => 'أيام الشهر', 'icon' => 'fa-calendar-alt', 'gradient' => '#3b82f6, #2563eb'];
    foreach ($agendaCounts as $label => $count) {
        $reportSummaryCards[] = [
            'value' => $count,
            'label' => $label,
            'icon' => $agendaIcons[$label] ?? 'fa-circle',
            'gradient' => $agendaGradients[$label] ?? '#64748b, #475569'
        ];
    }
}

require_once dirname(__DIR__, 2) . '/includes/admin_header.php';
require_once dirname(__DIR__, 2) . '/includes/widgets/hr_stat_cards.php';
?>

<div class="d-flex justify-content-between flex-wrap flex-md-nowrap align-items-center pt-3 pb-2 mb-3 border-bottom">
    <h1 class="h2"><i class="fas fa-chart-line me-2"></i>تقارير حضور الموظفين</h1>
    <div class="btn-toolbar mb-2 mb-md-0 gap-2">
        <a href="staff_attendance.php" class="btn btn-sm btn-outline-secondary"><i class="fas fa-arrow-right me-1"></i>العودة للحضور</a>
    </div>
</div>

<?php if ($pdfExportError): ?>
<div class="alert alert-warning" role="alert">
    <i class="fas fa-exclamation-triangle me-2"></i><?php echo htmlspecialchars($pdfExportError, ENT_QUOTES, 'UTF-8'); ?>
</div>
<?php endif; ?>

<?php if ($reportAccessError): ?>
<div class="alert alert-danger" role="alert">
    <i class="fas fa-ban me-2"></i><?php echo htmlspecialchars($reportAccessError, ENT_QUOTES, 'UTF-8'); ?>
</div>
<?php endif; ?>

<?php if (!empty($reportSummaryCards) && $canViewReports): ?>
<?php renderHrStatCards($reportSummaryCards, 'row-cols-2 row-cols-md-3 row-cols-xl-5'); ?>
<?php endif; ?>

<div class="card shadow mb-4">
    <div class="card-header bg-primary text-white">
        <h5 class="mb-0"><i class="fas fa-filter me-2"></i>اختيار نوع التقرير</h5>
    </div>
    <div class="card-body">
        <form method="GET" class="row g-3 align-items-end">
            <div class="col-md-3">
                <label class="form-label">نوع التقرير</label>
                <select name="report_type" class="form-select" onchange="this.form.submit()">
                    <option value="daily" <?php echo $reportType === 'daily' ? 'selected' : ''; ?>>تقرير الحضور اليومي</option>
                    <option value="lateness" <?php echo $reportType === 'lateness' ? 'selected' : ''; ?>>تقرير التأخيرات</option>
                    <option value="agenda" <?php echo $reportType === 'agenda' ? 'selected' : ''; ?>>الأجندة الشهرية</option>
                </select>
            </div>

            <?php if ($reportType === 'daily'): ?>
            <div class="col-md-3">
                <label class="form-label">التاريخ</label>
                <input type="text" name="date" class="form-control flatpickr-date" value="<?php echo htmlspecialchars($reportDate, ENT_QUOTES, 'UTF-8'); ?>">
            </div>
            <?php elseif ($reportType === 'lateness'): ?>
            <div class="col-md-3">
                <label class="form-label">من تاريخ</label>
                <input type="text" name="date_from" class="form-control flatpickr-date" value="<?php echo htmlspecialchars($dateFrom, ENT_QUOTES, 'UTF-8'); ?>">
            </div>
            <div class="col-md-3">
                <label class="form-label">إلى تاريخ</label>
                <input type="text" name="date_to" class="form-control flatpickr-date" value="<?php echo htmlspecialchars($dateTo, ENT_QUOTES, 'UTF-8'); ?>">
            </div>
            <?php else: ?>
            <div class="col-md-3">
                <label class="form-label">الشهر</label>
                <input type="month" name="month" class="form-control" value="<?php echo htmlspecialchars($month, ENT_QUOTES, 'UTF-8'); ?>">
            </div>
            <div class="col-md-4">
                <label class="form-label">الموظف</label>
                <select name="user_id" class="form-select">
                    <?php foreach ($staffList as $s): ?>
                        <option value="<?php echo (int)$s['id']; ?>" <?php echo (int)$s['id'] === $userId ? 'selected' : ''; ?>><?php echo htmlspecialchars($s['name'], ENT_QUOTES, 'UTF-8'); ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <?php endif; ?>

            <div class="col-md-2">
                <button type="submit" class="btn btn-primary w-100"><i class="fas fa-search me-1"></i>عرض</button>
            </div>
            <div class="col-md-1">
                <button type="submit" name="export" value="csv" class="btn btn-success w-100" <?php echo !$canExportReports ? 'disabled' : ''; ?>><i class="fas fa-file-excel me-1"></i>Excel (CSV)</button>
            </div>
            <div class="col-md-1">
                <button type="submit" name="export" value="pdf" class="btn btn-danger w-100" <?php echo !$canExportReports ? 'disabled' : ''; ?>><i class="fas fa-file-pdf me-1"></i>PDF</button>
            </div>
        </form>
    </div>
</div>

<?php if (!$canViewReports): ?>
<div class="card shadow">
    <div class="card-header bg-primary text-white">
        <h5 class="mb-0"><i class="fas fa-lock me-2"></i>غير متاح</h5>
    </div>
    <div class="card-body">
        <div class="alert alert-warning mb-0"><i class="fas fa-info-circle me-2"></i>لا يمكن عرض بيانات التقارير حالياً. راجع إعدادات النظام.</div>
    </div>
</div>
<?php elseif ($reportType === 'daily'): ?>
<div class="card shadow">
    <div class="card-header bg-primary text-white">
        <h5 class="mb-0"><i class="fas fa-calendar-day me-2"></i>تقرير الحضور اليومي - <?php echo htmlspecialchars($reportDate, ENT_QUOTES, 'UTF-8'); ?></h5>
    </div>
    <div class="card-body">
        <div class="table-responsive">
            <table class="table table-hover table-striped">
                <thead>
                    <tr>
                        <th>#</th>
                        <th>الموظف</th>
                        <th>الحالة</th>
                        <th>الحضور</th>
                        <th>الانصراف</th>
                        <th>التأخير</th>
                        <th>ملاحظات</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($dailyRows as $i => $r): ?>
                        <tr>
                            <td><?php echo $i + 1; ?></td>
                            <td><?php echo htmlspecialchars($r['name'], ENT_QUOTES, 'UTF-8'); ?></td>
                            <td><?php echo htmlspecialchars($r['status'], ENT_QUOTES, 'UTF-8'); ?></td>
                            <td><?php echo htmlspecialchars($r['check_in'], ENT_QUOTES, 'UTF-8'); ?></td>
                            <td><?php echo htmlspecialchars($r['check_out'], ENT_QUOTES, 'UTF-8'); ?></td>
                            <td><?php echo htmlspecialchars($r['late_minutes'], ENT_QUOTES, 'UTF-8'); ?></td>
                            <td><?php echo htmlspecialchars($r['note'], ENT_QUOTES, 'UTF-8'); ?></td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>
<?php elseif ($reportType === 'lateness'): ?>
<div class="card shadow">
    <div class="card-header bg-primary text-white">
        <h5 class="mb-0"><i class="fas fa-clock me-2"></i>تقرير التأخيرات من <?php echo htmlspecialchars($dateFrom, ENT_QUOTES, 'UTF-8'); ?> إلى <?php echo htmlspecialchars($dateTo, ENT_QUOTES, 'UTF-8'); ?></h5>
    </div>
    <div class="card-body">
        <div class="table-responsive">
            <table class="table table-hover table-striped">
                <thead>
                    <tr>
                        <th>#</th>
                        <th>التاريخ</th>
                        <th>الموظف</th>
                        <th>وقت الحضور</th>
                        <th>دقائق التأخير</th>
                        <th>ملاحظات</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($latenessRows as $i => $r): ?>
                        <tr>
                            <td><?php echo $i + 1; ?></td>
                            <td><?php echo htmlspecialchars($r['attendance_date'], ENT_QUOTES, 'UTF-8'); ?></td>
                            <td><?php echo htmlspecialchars($r['name'], ENT_QUOTES, 'UTF-8'); ?></td>
                            <td><?php echo $r['check_in'] ? htmlspecialchars(substr($r['check_in'], 0, 5), ENT_QUOTES, 'UTF-8') : '-'; ?></td>
                            <td><?php echo (int)$r['late_minutes']; ?></td>
                            <td><?php echo htmlspecialchars($r['notes'] ?: '-', ENT_QUOTES, 'UTF-8'); ?></td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <?php if (empty($latenessRows)): ?>
            <div class="alert alert-success mb-0"><i class="fas fa-check-circle me-2"></i>لا توجد حالات تأخير في الفترة المختارة.</div>
        <?php endif; ?>
    </div>
</div>
<?php else: ?>
<div class="card shadow">
    <div class="card-header bg-primary text-white">
        <h5 class="mb-0"><i class="fas fa-calendar-alt me-2"></i>الأجندة الشهرية - <?php echo htmlspecialchars($month, ENT_QUOTES, 'UTF-8'); ?></h5>
    </div>
    <div class="card-body">
        <div class="d-flex flex-wrap gap-2 mb-3">
            <span class="badge bg-success">حاضر</span>
            <span class="badge bg-danger">غائب</span>
            <span class="badge bg-warning text-dark">إجازة/متأخر</span>
            <span class="badge bg-info">بعذر</span>
        </div>
        <div class="table-responsive">
            <table class="table table-bordered align-middle text-center">
                <thead>
                    <tr>
                        <th>التاريخ</th>
                        <th>اليوم</th>
                        <th>الحالة</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($agendaRows as $r): ?>
                        <tr>
                            <td><?php echo htmlspecialchars($r['date'], ENT_QUOTES, 'UTF-8'); ?></td>
                            <td><?php echo htmlspecialchars($r['day'], ENT_QUOTES, 'UTF-8'); ?></td>
                            <td><span class="badge bg-<?php echo htmlspecialchars($r['color'], ENT_QUOTES, 'UTF-8'); ?><?php echo $r['color'] === 'warning' ? ' text-dark' : ''; ?>"><?php echo htmlspecialchars($r['label'], ENT_QUOTES, 'UTF-8'); ?></span></td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>
<?php endif; ?>

<?php require_once dirname(__DIR__, 2) . '/includes/admin_footer.php'; ?>
