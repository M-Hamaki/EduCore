<?php
require_once '../../includes/session_config.php';
require_once '../../config/database.php';
require_once '../../classes/utilities.php';
require_once '../../classes/AcademicYear.php';

Utilities::validateSession('student');

$database = new Database();
$db = $database->getConnection();
$studentId = (int) ($_SESSION['user_id'] ?? 0);
$currentAcademicYearId = AcademicYear::currentId($db);

function spr_escape($value): string
{
    return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
}

function spr_table_exists(PDO $db, string $table): bool
{
    $stmt = $db->prepare('SELECT 1 FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?');
    $stmt->execute([$table]);
    return (bool) $stmt->fetchColumn();
}

function spr_detail_status(array $detail): string
{
    $status = (string) ($detail['status'] ?? '');
    if ($status !== '') {
        return $status;
    }

    $valueLabel = (string) ($detail['value_label'] ?? '');
    if ($valueLabel === 'غ') {
        return 'absent';
    }
    if ($valueLabel === 'غياب بعذر') {
        return 'excused_absent';
    }

    return '';
}

$reportsReady = spr_table_exists($db, 'published_reports')
    && spr_table_exists($db, 'published_report_details')
    && spr_table_exists($db, 'report_windows');
$enrollmentsReady = spr_table_exists($db, 'student_enrollments');

$availableReports = [];
$selectedReport = null;
$snapshot = null;
$details = [];
$selectedReportId = (int) ($_GET['report_id'] ?? 0);

if ($reportsReady) {
    $reportJoins = '';
    $reportWhere = [
        'pr.student_id = ?',
        'rw.is_published = 1',
        'rw.hidden_at IS NULL',
        'rw.academic_year_id = pr.academic_year_id',
    ];
    $reportParams = [$studentId];

    if ($currentAcademicYearId > 0) {
        $reportWhere[] = 'pr.academic_year_id = ?';
        $reportParams[] = $currentAcademicYearId;
    }

    if ($enrollmentsReady) {
        $reportJoins .= " JOIN student_enrollments se
              ON se.student_id = pr.student_id
             AND se.academic_year_id = pr.academic_year_id
             AND se.enrollment_status = 'enrolled'";
    }

    $stmt = $db->prepare("SELECT pr.*, rw.name AS report_name, rw.report_type, rw.date_from, rw.date_to,
            rw.is_published, ay.name AS academic_year_name, t.name AS term_name
        FROM published_reports pr
        JOIN report_windows rw ON rw.id = pr.report_window_id
        JOIN academic_years ay ON ay.id = pr.academic_year_id
        LEFT JOIN academic_terms t ON t.id = pr.term_id
        {$reportJoins}
        WHERE " . implode("\n          AND ", $reportWhere) . "
        ORDER BY pr.published_at DESC, pr.id DESC");
    $stmt->execute($reportParams);
    $availableReports = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

    if ($selectedReportId <= 0 && !empty($availableReports)) {
        $selectedReportId = (int) $availableReports[0]['id'];
    }

    if ($selectedReportId > 0) {
        foreach ($availableReports as $report) {
            if ((int) $report['id'] === $selectedReportId) {
                $selectedReport = $report;
                break;
            }
        }
    }
    if (!$selectedReport && !empty($availableReports)) {
        $selectedReport = $availableReports[0];
        $selectedReportId = (int) $selectedReport['id'];
    }

    if ($selectedReport) {
        $snapshot = json_decode((string) $selectedReport['snapshot_json'], true);
        if (!is_array($snapshot)) {
            $snapshot = null;
        }
        $detailsStmt = $db->prepare("SELECT prd.*, s.name AS subject_name
            FROM published_report_details prd
            LEFT JOIN subjects s ON s.id = prd.subject_id
            WHERE prd.published_report_id = ?
            ORDER BY COALESCE(s.name, ''), prd.sort_order, prd.id");
        $detailsStmt->execute([(int) $selectedReport['id']]);
        $details = $detailsStmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    }
}

$student = $snapshot['student'] ?? [
    'name' => $_SESSION['name'] ?? '',
    'username' => '',
    'class_name' => '',
    'grade_name' => '',
];
$reportTitle = $snapshot['window']['name'] ?? ($selectedReport['report_name'] ?? 'تقارير الدرجات');
$annualSummary = is_array($snapshot['annual_summary'] ?? null) ? $snapshot['annual_summary'] : [];
$subjectsSummary = is_array($snapshot['subjects'] ?? null) ? $snapshot['subjects'] : [];
$detailsVisible = (bool) ($snapshot['window']['include_details'] ?? !empty($details));
$absenceVisible = (bool) ($snapshot['window']['include_absence'] ?? true);
$notesVisible = (bool) ($snapshot['window']['include_teacher_notes'] ?? false);
$visibleDetails = [];
if ($detailsVisible) {
    foreach ($details as $detail) {
        $status = spr_detail_status($detail);
        if (!$absenceVisible && in_array($status, ['absent', 'excused_absent'], true)) {
            continue;
        }
        $visibleDetails[] = $detail;
    }
}
?>
<!DOCTYPE html>
<html lang="ar" dir="rtl">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo spr_escape($reportTitle); ?> - التقارير</title>
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.rtl.min.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css">
    <link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=Tajawal:wght@400;500;700;800&display=swap">
    <link rel="stylesheet" href="style.css?v=published-1">
    <style>
        body { font-family: 'Tajawal', sans-serif; background: #eef2ff; color: #1f2937; }
        .report-shell { max-width: 1180px; margin: 0 auto; padding: 1rem; text-align: initial; }
        .published-report-card { background: #fff; border-radius: 12px; box-shadow: 0 12px 30px rgba(15, 23, 42, 0.12); overflow: hidden; }
        .report-header { background: linear-gradient(135deg, #3b82f6, #2563eb); color: #fff; padding: 1.2rem; }
        .report-title { font-weight: 800; margin: 0; color: #fff; }
        .grades-container.published-grades { margin: 0 auto; max-width: 1180px; text-align: center; }
        .published-grades .table-container { overflow-x: auto; margin-bottom: 1.25rem; }
        .published-grades .grades-table { width: 100%; }
        .student-info-table th { background: #2563eb !important; color: #fff !important; white-space: nowrap; }
        .subject-band { background: #eff6ff; color: #1d4ed8; font-weight: 800; }
        .low-mark { background: #fef3c7 !important; }
        .absent-mark { background: #e0f2fe !important; }
        .zero-mark { background: #fee2e2 !important; }
        .summary-box { border: 1px solid #dbeafe; border-radius: 12px; padding: 0.9rem; background: #f8fafc; }
        @media (max-width: 576px) {
            .report-shell { padding-inline: .65rem; }
            .report-selector-form label,
            .report-selector-form select {
                width: 100% !important;
                min-width: 0 !important;
            }
        }
        @media print {
            body { background: #fff; }
            .no-print, .student-info-bar { display: none !important; }
            .report-shell { max-width: none; padding: 0; }
            .published-report-card { box-shadow: none; border-radius: 0; }
        }
    </style>
</head>
<body>
<?php include '../../includes/student_header.php'; ?>

<div class="report-shell">
    <div class="d-flex justify-content-between align-items-center flex-wrap gap-2 mb-3 no-print">
        <a href="../portal.php" class="btn btn-outline-secondary back-to-portal-link">
            <i class="fas fa-arrow-right me-1"></i>العودة للبوابة
        </a>
        <?php if ($selectedReport): ?>
            <button type="button" class="btn btn-primary" onclick="window.print()">
                <i class="fas fa-print me-1"></i>طباعة
            </button>
        <?php endif; ?>
    </div>

    <?php if (!$reportsReady): ?>
        <div class="alert alert-warning">
            <i class="fas fa-circle-exclamation me-2"></i>نظام التقارير الجديد لم يتم تفعيله بعد.
        </div>
    <?php elseif (empty($availableReports)): ?>
        <div class="published-report-card">
            <div class="report-header text-center">
                <h1 class="report-title">التقارير غير متاحة حاليا</h1>
                <p class="mb-0 mt-2">لم يتم نشر تقارير جديدة لحسابك حتى الآن.</p>
            </div>
            <div class="p-4 text-center">
                <a href="../portal.php" class="btn btn-primary back-to-portal-link">
                    <i class="fas fa-home me-1"></i>العودة للبوابة
                </a>
            </div>
        </div>
    <?php else: ?>
        <div class="card mb-3 no-print">
            <div class="card-body">
                <form method="get" class="d-flex align-items-center gap-2 flex-wrap report-selector-form">
                    <label class="fw-bold">اختر التقرير</label>
                    <select name="report_id" class="form-select" style="width:auto; min-width:260px;" onchange="this.form.submit()">
                        <?php foreach ($availableReports as $report): ?>
                            <option value="<?php echo (int) $report['id']; ?>" <?php echo (int) $report['id'] === $selectedReportId ? 'selected' : ''; ?>>
                                <?php echo spr_escape($report['report_name'] . ' - ' . ($report['term_name'] ?? 'كل الترمات')); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </form>
            </div>
        </div>

        <div class="published-report-card grades-container published-grades">
            <div class="print-header">
                <div class="print-logo"><img src="../../assets/img/logo.png" alt="Logo"></div>
                <h3 class="print-school-name">EduCore</h3>
                <h4 class="print-report-title"><?php echo spr_escape($reportTitle); ?></h4>
            </div>
            <div class="report-header text-center">
                <h1 class="report-title"><?php echo spr_escape($reportTitle); ?></h1>
                <div class="mt-2">
                    <?php echo spr_escape($selectedReport['academic_year_name'] ?? ''); ?>
                    <?php if (!empty($selectedReport['term_name'])): ?>
                        - <?php echo spr_escape($selectedReport['term_name']); ?>
                    <?php endif; ?>
                </div>
                <?php if (!empty($selectedReport['date_from']) || !empty($selectedReport['date_to'])): ?>
                    <div class="mt-1 small">
                        الفترة:
                        <span dir="ltr"><?php echo spr_escape(($selectedReport['date_from'] ?? '-') . ' / ' . ($selectedReport['date_to'] ?? '-')); ?></span>
                    </div>
                <?php endif; ?>
            </div>
            <div class="p-3 p-md-4">
                <table class="table table-bordered student-info-table mb-4">
                    <tr>
                        <th>اسم الطالب</th>
                        <td><?php echo spr_escape($student['name'] ?? ''); ?></td>
                        <th>الفصل</th>
                        <td><?php echo spr_escape($student['class_name'] ?? ''); ?></td>
                    </tr>
                    <tr>
                        <th>اسم المستخدم</th>
                        <td><?php echo spr_escape($student['username'] ?? ''); ?></td>
                        <th>الصف</th>
                        <td><?php echo spr_escape($student['grade_name'] ?? ''); ?></td>
                    </tr>
                </table>

                <div class="row g-3 mb-4">
                    <div class="col-md-4">
                        <div class="summary-box">
                            <div class="text-muted small">المجموع المرصود</div>
                            <div class="fs-4 fw-bold"><?php echo spr_escape($snapshot['total_grade'] ?? $selectedReport['total_grade'] ?? '-'); ?></div>
                        </div>
                    </div>
                    <div class="col-md-4">
                        <div class="summary-box">
                            <div class="text-muted small">النهاية الكبرى للبنود المنشورة</div>
                            <div class="fs-4 fw-bold"><?php echo spr_escape($snapshot['max_total'] ?? '-'); ?></div>
                        </div>
                    </div>
                    <div class="col-md-4">
                        <div class="summary-box">
                            <div class="text-muted small">النسبة</div>
                            <div class="fs-4 fw-bold"><?php echo isset($snapshot['percentage']) ? spr_escape($snapshot['percentage']) . '%' : '-'; ?></div>
                        </div>
                    </div>
                </div>

                <?php if (!empty($annualSummary)): ?>
                    <div class="table-responsive mb-4">
                        <table class="table table-bordered align-middle grades-table">
                            <thead>
                                <tr>
                                    <th>المادة</th>
                                    <th>الترم الأول</th>
                                    <th>وزن الترم الأول</th>
                                    <th>الترم الثاني</th>
                                    <th>وزن الترم الثاني</th>
                                    <th>نتيجة نهاية العام</th>
                                    <th>النسبة</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($annualSummary as $annualRow): ?>
                                    <?php
                                    $terms = is_array($annualRow['terms'] ?? null) ? $annualRow['terms'] : [];
                                    $firstTerm = $terms[1] ?? null;
                                    $secondTerm = $terms[2] ?? null;
                                    ?>
                                    <tr>
                                        <td class="subject-band"><?php echo spr_escape($annualRow['subject_name'] ?? ''); ?></td>
                                        <td>
                                            <?php if ($firstTerm): ?>
                                                <?php echo spr_escape(($firstTerm['total'] ?? 0) . ' / ' . ($firstTerm['max_total'] ?? 0)); ?>
                                            <?php else: ?>
                                                -
                                            <?php endif; ?>
                                        </td>
                                        <td><?php echo spr_escape($annualRow['first_term_weight'] ?? 0); ?>%</td>
                                        <td>
                                            <?php if ($secondTerm): ?>
                                                <?php echo spr_escape(($secondTerm['total'] ?? 0) . ' / ' . ($secondTerm['max_total'] ?? 0)); ?>
                                            <?php else: ?>
                                                -
                                            <?php endif; ?>
                                        </td>
                                        <td><?php echo spr_escape($annualRow['second_term_weight'] ?? 0); ?>%</td>
                                        <td class="fw-bold">
                                            <?php echo spr_escape(($annualRow['annual_value'] ?? 0) . ' / ' . ($annualRow['annual_max'] ?? 0)); ?>
                                        </td>
                                        <td><?php echo spr_escape($annualRow['annual_percentage'] ?? 0); ?>%</td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                <?php endif; ?>

                <?php if (!empty($subjectsSummary)): ?>
                    <div class="table-responsive mb-4">
                        <table class="table table-bordered align-middle grades-table">
                            <thead>
                                <tr>
                                    <th>المادة</th>
                                    <th>المجموع</th>
                                    <th>النهاية</th>
                                    <th>النسبة</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($subjectsSummary as $subjectSummary): ?>
                                    <?php
                                    $subjectTotal = isset($subjectSummary['total']) ? (float) $subjectSummary['total'] : null;
                                    $subjectMax = isset($subjectSummary['max_total']) ? (float) $subjectSummary['max_total'] : null;
                                    $subjectPercentage = ($subjectTotal !== null && $subjectMax !== null && $subjectMax > 0)
                                        ? round(($subjectTotal / $subjectMax) * 100, 2)
                                        : null;
                                    ?>
                                    <tr>
                                        <td class="subject-band"><?php echo spr_escape($subjectSummary['subject_name'] ?? ''); ?></td>
                                        <td class="fw-bold"><?php echo $subjectTotal !== null ? spr_escape($subjectTotal) : '-'; ?></td>
                                        <td><?php echo $subjectMax !== null ? spr_escape($subjectMax) : '-'; ?></td>
                                        <td><?php echo $subjectPercentage !== null ? spr_escape($subjectPercentage) . '%' : '-'; ?></td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                <?php endif; ?>

                <?php if (!$detailsVisible): ?>
                    <div class="alert alert-info mb-0">
                        <i class="fas fa-circle-info me-2"></i>تفاصيل البنود غير مفعلة لهذا التقرير.
                    </div>
                <?php else: ?>
                    <div class="table-responsive table-container">
                        <table class="table table-bordered align-middle grades-table">
                            <thead>
                                <tr>
                                    <th>المادة</th>
                                    <th>البند</th>
                                    <th>فصل الرصد</th>
                                    <th>الدرجة</th>
                                    <th>النهاية</th>
                                    <?php if ($notesVisible): ?>
                                        <th>ملاحظة</th>
                                    <?php endif; ?>
                                </tr>
                            </thead>
                            <tbody>
                                <?php if (empty($visibleDetails)): ?>
                                    <tr>
                                        <td colspan="<?php echo $notesVisible ? 6 : 5; ?>" class="text-center text-muted py-4">
                                            لا توجد تفاصيل منشورة في هذا التقرير.
                                        </td>
                                    </tr>
                                <?php else: ?>
                                    <?php foreach ($visibleDetails as $detail): ?>
                                        <?php
                                        $valueLabel = (string) ($detail['value_label'] ?? '');
                                        $status = spr_detail_status($detail);
                                        $cellClass = '';
                                        if (in_array($status, ['absent', 'excused_absent'], true)) {
                                            $cellClass = 'absent-mark';
                                        } elseif ($detail['numeric_value'] !== null && (float) $detail['numeric_value'] == 0.0) {
                                            $cellClass = 'zero-mark';
                                        } elseif ($detail['numeric_value'] !== null && $detail['max_grade'] !== null && (float) $detail['numeric_value'] < ((float) $detail['max_grade'] / 2)) {
                                            $cellClass = 'low-mark';
                                        }
                                        ?>
                                        <tr>
                                            <td class="subject-band"><?php echo spr_escape($detail['subject_name'] ?? ''); ?></td>
                                            <td><?php echo spr_escape($detail['label']); ?></td>
                                            <td><?php echo spr_escape($detail['class_name_at_entry'] ?? '-'); ?></td>
                                            <td class="<?php echo $cellClass; ?>"><?php echo spr_escape($valueLabel); ?></td>
                                            <td><?php echo spr_escape($detail['max_grade'] ?? ''); ?></td>
                                            <?php if ($notesVisible): ?>
                                                <td><?php echo spr_escape($detail['note'] ?? ''); ?></td>
                                            <?php endif; ?>
                                        </tr>
                                    <?php endforeach; ?>
                                <?php endif; ?>
                            </tbody>
                        </table>
                    </div>
                <?php endif; ?>
            </div>
        </div>
    <?php endif; ?>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
<script>
document.addEventListener('DOMContentLoaded', function () {
    const referrer = document.referrer;
    if (referrer && referrer !== window.location.href && !referrer.includes('published_reports.php') && !referrer.includes('login.php')) {
        document.querySelectorAll('.back-to-portal-link').forEach(function(link) {
            link.href = referrer;
        });
    }
});
</script>
</body>
</html>
