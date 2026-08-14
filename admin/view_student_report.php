<?php
$page_title = 'عرض تقرير الطالب';
$custom_page_title = true;

require_once '../config/database.php';
require_once '../classes/utilities.php';
require_once '../classes/AcademicYear.php';
require_once '../includes/session_config.php';

Utilities::validateSession('admin');

$database = new Database();
$db = $database->getConnection();

function avsr_escape($value): string
{
    return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
}

function avsr_table_exists(PDO $db, string $table): bool
{
    $stmt = $db->prepare('SELECT 1 FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? LIMIT 1');
    $stmt->execute([$table]);
    return (bool) $stmt->fetchColumn();
}

function avsr_detail_status(array $detail): string
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
    if ($valueLabel === '' || $valueLabel === '-') {
        return 'empty';
    }
    return is_numeric($valueLabel) ? 'present' : '';
}

$studentId = (int) ($_GET['student_id'] ?? 0);
$selectedReportId = (int) ($_GET['report_id'] ?? 0);
$currentAcademicYearId = AcademicYear::currentId($db);
$reportsReady = avsr_table_exists($db, 'published_reports')
    && avsr_table_exists($db, 'published_report_details')
    && avsr_table_exists($db, 'report_windows');

$student = null;
if ($studentId > 0) {
    $studentStmt = $db->prepare("SELECT u.id, u.name, u.username, u.status,
            COALESCE(cy.name, cu.name) AS class_name,
            COALESCE(gy.grade_name, gu.grade_name) AS grade_name
        FROM users u
        LEFT JOIN student_enrollments se ON se.student_id = u.id AND se.academic_year_id = ?
        LEFT JOIN classes cy ON cy.id = se.class_id
        LEFT JOIN grades gy ON gy.id = cy.grade_id
        LEFT JOIN classes cu ON cu.id = u.class_id
        LEFT JOIN grades gu ON gu.id = cu.grade_id
        WHERE u.id = ? AND u.role = 'student'
        LIMIT 1");
    $studentStmt->execute([$currentAcademicYearId, $studentId]);
    $student = $studentStmt->fetch(PDO::FETCH_ASSOC);
}

if (!$student) {
    $_SESSION['error_message'] = 'لم يتم العثور على الطالب المطلوب.';
    header('Location: assessment_reports.php');
    exit();
}

$availableReports = [];
$selectedReport = null;
$snapshot = null;
$details = [];

if ($reportsReady) {
    $reportsStmt = $db->prepare("SELECT pr.*, rw.name AS report_name, rw.report_type, rw.date_from, rw.date_to,
            rw.is_published, rw.hidden_at, ay.name AS academic_year_name, t.name AS term_name
        FROM published_reports pr
        JOIN report_windows rw ON rw.id = pr.report_window_id
        JOIN academic_years ay ON ay.id = pr.academic_year_id
        LEFT JOIN academic_terms t ON t.id = pr.term_id
        WHERE pr.student_id = ?
        ORDER BY pr.published_at DESC, pr.id DESC");
    $reportsStmt->execute([$studentId]);
    $availableReports = $reportsStmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

    if ($selectedReportId <= 0 && $availableReports) {
        $selectedReportId = (int) $availableReports[0]['id'];
    }

    foreach ($availableReports as $report) {
        if ((int) $report['id'] === $selectedReportId) {
            $selectedReport = $report;
            break;
        }
    }
    if (!$selectedReport && !empty($availableReports)) {
        $selectedReport = $availableReports[0];
        $selectedReportId = (int) $selectedReport['id'];
    }

    if ($selectedReport) {
        $snapshot = json_decode((string) $selectedReport['snapshot_json'], true);
        $snapshot = is_array($snapshot) ? $snapshot : [];

        $detailsStmt = $db->prepare("SELECT prd.*, s.name AS subject_name
            FROM published_report_details prd
            LEFT JOIN subjects s ON s.id = prd.subject_id
            WHERE prd.published_report_id = ?
            ORDER BY COALESCE(s.name, ''), prd.sort_order, prd.id");
        $detailsStmt->execute([(int) $selectedReport['id']]);
        $details = $detailsStmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    }
}

$snapshotStudent = is_array($snapshot['student'] ?? null) ? $snapshot['student'] : [];
$displayStudent = array_merge([
    'name' => $student['name'] ?? '',
    'username' => $student['username'] ?? '',
    'class_name' => $student['class_name'] ?? '',
    'grade_name' => $student['grade_name'] ?? '',
], $snapshotStudent);
$reportTitle = $snapshot['window']['name'] ?? ($selectedReport['report_name'] ?? 'تقرير الطالب');
$detailsVisible = (bool) ($snapshot['window']['include_details'] ?? true);
$absenceVisible = (bool) ($snapshot['window']['include_absence'] ?? true);
$notesVisible = (bool) ($snapshot['window']['include_teacher_notes'] ?? false);
$subjectsSummary = is_array($snapshot['subjects'] ?? null) ? $snapshot['subjects'] : [];
$annualSummary = is_array($snapshot['annual_summary'] ?? null) ? $snapshot['annual_summary'] : [];

$visibleDetails = [];
foreach ($details as $detail) {
    $status = avsr_detail_status($detail);
    if (!$absenceVisible && in_array($status, ['absent', 'excused_absent'], true)) {
        continue;
    }
    $visibleDetails[] = $detail;
}

include '../includes/admin_header.php';
?>

<div class="admin-page-heading no-print">
    <h1 class="h2"><i class="fas fa-file-lines me-2 text-primary"></i>عرض تقرير الطالب</h1>
    <div class="btn-toolbar admin-top-actions">
        <a href="assessment_reports.php" class="btn btn-light btn-sm">
            <i class="fas fa-arrow-right me-2"></i>نوافذ التقارير
        </a>
        <?php if ($selectedReport): ?>
            <button type="button" class="btn btn-header-premium btn-print-soft" onclick="window.print()">
                <i class="fas fa-print me-1"></i>طباعة
            </button>
        <?php endif; ?>
    </div>
</div>

<?php if (!$reportsReady): ?>
    <div class="alert alert-warning">
        <i class="fas fa-circle-exclamation me-2"></i>نظام التقارير الجديد لم يتم تفعيله بعد. نفذ migration محرك الدرجات أولا.
    </div>
<?php elseif (!$availableReports): ?>
    <div class="alert alert-info">
        <i class="fas fa-circle-info me-2"></i>لا توجد تقارير منشورة لهذا الطالب حتى الآن.
    </div>
<?php else: ?>
    <form method="get" class="admin-filter-bar no-print">
            <div class="admin-filter-controls">
                <input type="hidden" name="student_id" value="<?php echo (int) $studentId; ?>">
                <select name="report_id" class="form-select admin-select-wide" onchange="this.form.submit()">
                    <?php foreach ($availableReports as $report): ?>
                        <option value="<?php echo (int) $report['id']; ?>" <?php echo (int) $report['id'] === $selectedReportId ? 'selected' : ''; ?>>
                            <?php echo avsr_escape($report['report_name'] . ' - ' . ($report['term_name'] ?? 'كل الترمات')); ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="admin-filter-actions">
                <?php if ($selectedReport && !empty($selectedReport['hidden_at'])): ?>
                    <span class="badge bg-secondary">مؤرشف</span>
                <?php elseif ($selectedReport && empty($selectedReport['is_published'])): ?>
                    <span class="badge bg-warning text-dark">مخفي عن الطلاب</span>
                <?php elseif ($selectedReport): ?>
                    <span class="badge bg-success">ظاهر للطالب</span>
                <?php endif; ?>
            </div>
    </form>

    <?php if ($selectedReport): ?>
        <div class="admin-published-report">
            <div class="admin-report-header">
                <h2><?php echo avsr_escape($reportTitle); ?></h2>
                <div class="mt-2">
                    <?php echo avsr_escape($selectedReport['academic_year_name'] ?? ''); ?>
                    <?php if (!empty($selectedReport['term_name'])): ?>
                        - <?php echo avsr_escape($selectedReport['term_name']); ?>
                    <?php endif; ?>
                </div>
                <?php if (!empty($selectedReport['date_from']) || !empty($selectedReport['date_to'])): ?>
                    <div class="small mt-1" dir="ltr"><?php echo avsr_escape(($selectedReport['date_from'] ?? '-') . ' / ' . ($selectedReport['date_to'] ?? '-')); ?></div>
                <?php endif; ?>
            </div>
            <div class="p-3 p-md-4">
                <table class="table student-info-table admin-data-table mb-4">
                    <tr>
                        <th>اسم الطالب</th>
                        <td><?php echo avsr_escape($displayStudent['name'] ?? ''); ?></td>
                        <th>الفصل</th>
                        <td><?php echo avsr_escape($displayStudent['class_name'] ?? ''); ?></td>
                    </tr>
                    <tr>
                        <th>اسم المستخدم</th>
                        <td><?php echo avsr_escape($displayStudent['username'] ?? ''); ?></td>
                        <th>الصف</th>
                        <td><?php echo avsr_escape($displayStudent['grade_name'] ?? ''); ?></td>
                    </tr>
                </table>

                <div class="row g-3 mb-4">
                    <div class="col-md-4"><div class="summary-box"><div class="text-muted small">المجموع المرصود</div><div class="fs-4 fw-bold"><?php echo avsr_escape($snapshot['total_grade'] ?? $selectedReport['total_grade'] ?? '-'); ?></div></div></div>
                    <div class="col-md-4"><div class="summary-box"><div class="text-muted small">النهاية الكبرى المنشورة</div><div class="fs-4 fw-bold"><?php echo avsr_escape($snapshot['max_total'] ?? '-'); ?></div></div></div>
                    <div class="col-md-4"><div class="summary-box"><div class="text-muted small">النسبة</div><div class="fs-4 fw-bold"><?php echo isset($snapshot['percentage']) ? avsr_escape($snapshot['percentage']) . '%' : '-'; ?></div></div></div>
                </div>

                <?php if ($annualSummary): ?>
                    <div class="table-responsive admin-table-wrap mb-4">
                        <table class="table align-middle admin-data-table">
                            <thead><tr><th>المادة</th><th>الترمات الداخلة في نهاية العام</th><th>نهاية العام</th><th>النسبة</th></tr></thead>
                            <tbody>
                                <?php foreach ($annualSummary as $annualRow): ?>
                                    <?php
                                    $terms = is_array($annualRow['terms'] ?? null) ? $annualRow['terms'] : [];
                                    $weights = is_array($annualRow['weights'] ?? null) ? $annualRow['weights'] : [];
                                    $isAnnualComplete = !empty($annualRow['is_complete']);
                                    $missingTerms = is_array($annualRow['missing_terms'] ?? null) ? $annualRow['missing_terms'] : [];
                                    ?>
                                    <tr>
                                        <td class="subject-band"><?php echo avsr_escape($annualRow['subject_name'] ?? ''); ?></td>
                                        <td>
                                            <div class="d-flex flex-wrap gap-1">
                                                <?php foreach ($terms as $termKey => $term): ?>
                                                    <?php $weight = $weights[$termKey] ?? $weights[(string) $termKey] ?? 0; ?>
                                                    <span class="badge bg-light text-dark border">
                                                        <?php echo avsr_escape(($term['term_name'] ?? 'ترم') . ': ' . ($term['total'] ?? 0) . ' / ' . ($term['max_total'] ?? 0) . ' (' . $weight . '%)'); ?>
                                                    </span>
                                                <?php endforeach; ?>
                                            </div>
                                            <?php if (!$isAnnualComplete): ?>
                                                <div class="small text-warning mt-1">
                                                    <i class="fas fa-clock me-1"></i>غير مكتمل<?php echo $missingTerms ? ': ' . avsr_escape(implode('، ', $missingTerms)) : ''; ?>
                                                </div>
                                            <?php endif; ?>
                                        </td>
                                        <td class="fw-bold">
                                            <?php echo $isAnnualComplete ? avsr_escape(($annualRow['annual_value'] ?? 0) . ' / ' . ($annualRow['annual_max'] ?? 0)) : '— / ' . avsr_escape($annualRow['annual_max'] ?? 0); ?>
                                        </td>
                                        <td><?php echo $isAnnualComplete ? avsr_escape($annualRow['annual_percentage'] ?? 0) . '%' : '—'; ?></td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                <?php endif; ?>

                <?php if ($subjectsSummary): ?>
                    <div class="table-responsive admin-table-wrap mb-4">
                        <table class="table align-middle admin-data-table">
                            <thead><tr><th>المادة</th><th>المجموع</th><th>النهاية</th><th>النسبة</th></tr></thead>
                            <tbody>
                                <?php foreach ($subjectsSummary as $subjectSummary): ?>
                                    <?php
                                    $subjectTotal = isset($subjectSummary['total']) ? (float) $subjectSummary['total'] : null;
                                    $subjectMax = isset($subjectSummary['max_total']) ? (float) $subjectSummary['max_total'] : null;
                                    $subjectPercentage = ($subjectTotal !== null && $subjectMax !== null && $subjectMax > 0) ? round(($subjectTotal / $subjectMax) * 100, 2) : null;
                                    ?>
                                    <tr>
                                        <td class="subject-band"><?php echo avsr_escape($subjectSummary['subject_name'] ?? ''); ?></td>
                                        <td class="fw-bold"><?php echo $subjectTotal !== null ? avsr_escape($subjectTotal) : '-'; ?></td>
                                        <td><?php echo $subjectMax !== null ? avsr_escape($subjectMax) : '-'; ?></td>
                                        <td><?php echo $subjectPercentage !== null ? avsr_escape($subjectPercentage) . '%' : '-'; ?></td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                <?php endif; ?>

                <?php if (!$detailsVisible): ?>
                    <div class="alert alert-info mb-0"><i class="fas fa-circle-info me-2"></i>تفاصيل البنود غير مفعلة لهذا التقرير.</div>
                <?php else: ?>
                    <div class="table-responsive admin-table-wrap">
                        <table class="table align-middle admin-data-table">
                            <thead>
                                <tr>
                                    <th>المادة</th>
                                    <th>البند</th>
                                    <th>فصل الرصد</th>
                                    <th>الدرجة</th>
                                    <th>النهاية</th>
                                    <?php if ($notesVisible): ?><th>ملاحظة</th><?php endif; ?>
                                </tr>
                            </thead>
                            <tbody>
                                <?php if (!$visibleDetails): ?>
                                    <tr><td colspan="<?php echo $notesVisible ? 6 : 5; ?>" class="text-center text-muted">لا توجد تفاصيل منشورة.</td></tr>
                                <?php endif; ?>
                                <?php foreach ($visibleDetails as $detail): ?>
                                    <?php
                                    $status = avsr_detail_status($detail);
                                    $numericValue = $detail['numeric_value'];
                                    $maxGrade = $detail['max_grade'];
                                    $markClass = '';
                                    if ($status === 'absent' || $status === 'excused_absent') {
                                        $markClass = 'mark-absent';
                                    } elseif ($numericValue !== null && (float) $numericValue == 0.0) {
                                        $markClass = 'mark-zero';
                                    } elseif ($numericValue !== null && $maxGrade !== null && (float) $maxGrade > 0 && (float) $numericValue < ((float) $maxGrade / 2)) {
                                        $markClass = 'mark-low';
                                    }
                                    ?>
                                    <tr>
                                        <td class="subject-band"><?php echo avsr_escape($detail['subject_name'] ?? '-'); ?></td>
                                        <td><?php echo avsr_escape($detail['label'] ?? ''); ?></td>
                                        <td><?php echo avsr_escape($detail['class_name_at_entry'] ?? '-'); ?></td>
                                        <td class="<?php echo $markClass; ?>"><?php echo avsr_escape($detail['value_label'] ?? '-'); ?></td>
                                        <td><?php echo avsr_escape($detail['max_grade'] ?? '-'); ?></td>
                                        <?php if ($notesVisible): ?><td><?php echo avsr_escape($detail['note'] ?? ''); ?></td><?php endif; ?>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                <?php endif; ?>
            </div>
        </div>
    <?php endif; ?>
<?php endif; ?>

<?php require_once '../includes/admin_footer.php'; ?>
