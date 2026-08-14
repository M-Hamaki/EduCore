<?php
$page_title = "أرشيف إعداد الرصد";
$custom_page_title = true;

require_once '../config/database.php';
require_once '../classes/utilities.php';
require_once '../classes/AcademicYear.php';
require_once '../includes/session_config.php';
require_once '../includes/csrf.php';
Utilities::validateSession('admin');
requireCsrfPost();

$database = new Database();
$db = $database->getConnection();

$success_message = $_SESSION['success_message'] ?? null;
$error_message = $_SESSION['error_message'] ?? null;
unset($_SESSION['success_message'], $_SESSION['error_message']);

function assessment_setup_table_exists(PDO $db, string $table): bool
{
    $stmt = $db->prepare('SELECT 1 FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?');
    $stmt->execute([$table]);
    return (bool) $stmt->fetchColumn();
}

function assessment_setup_count_table(PDO $db, string $table): int
{
    if (!assessment_setup_table_exists($db, $table)) {
        return 0;
    }
    return (int) $db->query("SELECT COUNT(*) FROM `$table`")->fetchColumn();
}

function assessment_setup_legacy_action_target(string $action): string
{
    $targets = [
        'add_term' => 'assessment_calendar.php?tab=terms',
        'update_term' => 'assessment_calendar.php?tab=terms',
        'toggle_term' => 'assessment_calendar.php?tab=terms',
        'delete_term' => 'assessment_calendar.php?tab=terms',
        'add_month' => 'assessment_calendar.php?tab=months',
        'update_month' => 'assessment_calendar.php?tab=months',
        'toggle_month' => 'assessment_calendar.php?tab=months',
        'delete_month' => 'assessment_calendar.php?tab=months',
        'copy_month' => 'assessment_calendar.php?tab=months',
        'add_week' => 'assessment_calendar.php?tab=weeks',
        'update_week' => 'assessment_calendar.php?tab=weeks',
        'toggle_week' => 'assessment_calendar.php?tab=weeks',
        'delete_week' => 'assessment_calendar.php?tab=weeks',
        'assign_subject_grade' => 'assessment_subject_assignments.php',
        'update_subject_assignment' => 'assessment_subject_assignments.php',
        'toggle_subject_assignment' => 'assessment_subject_assignments.php',
        'delete_subject_assignment' => 'assessment_subject_assignments.php',
        'add_teacher_assignment' => 'assessment_teacher_assignments.php',
        'update_teacher_assignment' => 'assessment_teacher_assignments.php',
        'toggle_teacher_assignment' => 'assessment_teacher_assignments.php',
        'end_teacher_assignment' => 'assessment_teacher_assignments.php',
        'delete_teacher_assignment' => 'assessment_teacher_assignments.php',
        'add_assessment_permission' => 'assessment_permissions.php',
        'update_assessment_permission' => 'assessment_permissions.php',
        'toggle_assessment_permission' => 'assessment_permissions.php',
        'delete_assessment_permission' => 'assessment_permissions.php',
        'sync_student_locks' => 'assessment_student_locks.php',
        'add_manual_student_lock' => 'assessment_student_locks.php',
        'delete_manual_student_lock' => 'assessment_student_locks.php',
        'add_scheme' => 'assessment_schemes.php',
        'update_scheme' => 'assessment_schemes.php',
        'copy_scheme' => 'assessment_schemes.php',
        'apply_component_template' => 'assessment_schemes.php',
        'update_scheme_status' => 'assessment_schemes.php',
        'delete_scheme' => 'assessment_schemes.php',
        'add_component' => 'assessment_components.php',
        'update_component' => 'assessment_components.php',
        'toggle_component_status' => 'assessment_components.php',
        'delete_component' => 'assessment_components.php',
        'save_component_week_rule' => 'assessment_component_week_rules.php',
        'toggle_component_week_rule' => 'assessment_component_week_rules.php',
        'delete_component_week_rule' => 'assessment_component_week_rules.php',
        'add_window' => 'assessment_windows.php',
        'add_weekly_windows' => 'assessment_windows.php',
        'update_window_status' => 'assessment_windows.php',
        'update_window_settings' => 'assessment_windows.php',
        'delete_window' => 'assessment_windows.php',
        'add_report_window' => 'assessment_reports.php',
        'update_report_window' => 'assessment_reports.php',
        'publish_report_window' => 'assessment_reports.php',
        'hide_report_window' => 'assessment_reports.php',
        'delete_report_window' => 'assessment_reports.php',
        'add_report_item' => 'assessment_reports.php?tab=items',
        'update_report_item' => 'assessment_reports.php?tab=items',
        'toggle_report_item' => 'assessment_reports.php?tab=items',
        'remove_report_item' => 'assessment_reports.php?tab=items',
    ];

    return $targets[$action] ?? 'assessment_calendar.php';
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $legacyAction = (string) ($_POST['action'] ?? '');
    $_SESSION['error_message'] = 'تم أرشفة صفحة إعداد الرصد المجمعة. استخدم الصفحة الجديدة المناسبة من قائمة المواد والدرجات.';
    header('Location: ' . assessment_setup_legacy_action_target($legacyAction));
    exit();
}

$requiredTables = [
    'academic_terms' => 'الترمات',
    'academic_months' => 'الشهور الدراسية',
    'academic_weeks' => 'الأسابيع الدراسية',
    'subject_grade_assignments' => 'ربط المواد بالصفوف',
    'teacher_subject_assignments' => 'تعيينات المعلمين التفصيلية',
    'assessment_schemes' => 'خطط الدرجات',
    'assessment_components' => 'بنود الدرجات',
    'assessment_component_week_rules' => 'قواعد أسابيع البنود',
    'assessment_windows' => 'نوافذ الرصد',
    'student_marks' => 'درجات الطلاب',
    'student_mark_audit' => 'سجل تعديل الدرجات',
    'assessment_student_locks' => 'أقفال درجات الطلاب',
    'report_windows' => 'نوافذ التقارير',
    'report_window_items' => 'بنود نوافذ التقارير',
    'published_reports' => 'التقارير المنشورة',
    'published_report_details' => 'تفاصيل التقارير المنشورة',
];

$tableStatus = [];
$readyTables = 0;
foreach ($requiredTables as $table => $label) {
    $exists = assessment_setup_table_exists($db, $table);
    if ($exists) {
        $readyTables++;
    }
    $tableStatus[] = [
        'table' => $table,
        'label' => $label,
        'exists' => $exists,
        'count' => $exists ? assessment_setup_count_table($db, $table) : 0,
    ];
}

$currentAcademicYear = AcademicYear::getCurrent($db) ?: AcademicYear::getActive($db);
$currentAcademicYearName = $currentAcademicYear['name'] ?? 'غير محدد';
$foundationReady = $readyTables === count($requiredTables);

$entryPages = [
    ['title' => 'التقويم', 'text' => 'الترمات والشهور والأسابيع الدراسية.', 'icon' => 'fa-calendar-alt', 'href' => 'assessment_calendar.php', 'color' => 'primary'],
    ['title' => 'ربط المواد', 'text' => 'ربط المادة بأكثر من مرحلة وصف وفصل.', 'icon' => 'fa-link', 'href' => 'assessment_subject_assignments.php', 'color' => 'success'],
    ['title' => 'تعيينات المعلمين', 'text' => 'تحديد مواد وصفوف وفصول كل معلم.', 'icon' => 'fa-chalkboard-user', 'href' => 'assessment_teacher_assignments.php', 'color' => 'info'],
    ['title' => 'خطط الدرجات', 'text' => 'مجاميع المواد ونسخ القوالب بين الصفوف.', 'icon' => 'fa-diagram-project', 'href' => 'assessment_schemes.php', 'color' => 'warning'],
    ['title' => 'بنود الدرجات', 'text' => 'الشهرية والأسبوعية والنهائية لكل خطة.', 'icon' => 'fa-list-check', 'href' => 'assessment_components.php', 'color' => 'secondary'],
    ['title' => 'قواعد الأسابيع', 'text' => 'ربط البنود الأسبوعية بأسابيع محددة.', 'icon' => 'fa-calendar-week', 'href' => 'assessment_component_week_rules.php', 'color' => 'primary'],
    ['title' => 'نوافذ الرصد', 'text' => 'إتاحة أعمدة الرصد للمعلمين حسب الوقت.', 'icon' => 'fa-lock-open', 'href' => 'assessment_windows.php', 'color' => 'success'],
    ['title' => 'درجات الطلاب', 'text' => 'عرض وتصحيح الدرجات الأصلية عبر المراحل والمواد.', 'icon' => 'fa-graduation-cap', 'href' => 'assessment_marks.php', 'color' => 'primary'],
    ['title' => 'شيت الدرجات', 'text' => 'عرض الصفوف والمواد والأسابيع في شيت موحد يشبه جداول البيانات.', 'icon' => 'fa-table-cells-large', 'href' => 'assessment_marks_sheet.php', 'color' => 'success'],
    ['title' => 'تقارير الدرجات', 'text' => 'نوافذ النشر وما يظهر للطلاب.', 'icon' => 'fa-file-alt', 'href' => 'assessment_reports.php', 'color' => 'danger'],
    ['title' => 'صلاحيات الرصد', 'text' => 'صلاحيات المراجعة والحذف والنشر.', 'icon' => 'fa-user-shield', 'href' => 'assessment_permissions.php', 'color' => 'dark'],
    ['title' => 'أقفال الطلاب', 'text' => 'قفل درجات المنقولين والخريجين يدويا أو آليا.', 'icon' => 'fa-user-lock', 'href' => 'assessment_student_locks.php', 'color' => 'secondary'],
];

require_once '../includes/admin_header.php';
?>

<div class="admin-page-heading">
    <h1 class="h2"><i class="fas fa-box-archive me-2 text-primary"></i>أرشيف إعداد الرصد</h1>
    <div class="admin-top-actions">
        <a href="assessment_calendar.php" class="btn btn-light">
            <i class="fas fa-calendar-alt me-2"></i>فتح التقويم
        </a>
    </div>
</div>

<?php if ($success_message): ?>
    <div class="alert alert-success alert-dismissible fade show" role="alert">
        <i class="fas fa-check-circle me-2"></i><?php echo htmlspecialchars($success_message, ENT_QUOTES, 'UTF-8'); ?>
        <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
    </div>
<?php endif; ?>
<?php if ($error_message): ?>
    <div class="alert alert-danger alert-dismissible fade show" role="alert">
        <i class="fas fa-exclamation-triangle me-2"></i><?php echo htmlspecialchars($error_message, ENT_QUOTES, 'UTF-8'); ?>
        <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
    </div>
<?php endif; ?>

<div class="alert alert-warning">
    <i class="fas fa-info-circle me-2"></i>
    تم أرشفة الصفحة المجمعة القديمة حتى لا تتداخل مع الصفحات الجديدة المقسمة تحت قائمة المواد والدرجات.
</div>

<div class="row row-cols-2 row-cols-md-4 g-3 mb-4">
    <div class="col">
        <div class="stat-card" style="--card-gradient: linear-gradient(135deg, #3b82f6, #2563eb);">
            <div class="stat-card-icon"><i class="fas fa-database"></i></div>
            <div class="stat-card-info">
                <div class="stat-card-number"><?php echo (int) $readyTables; ?>/<?php echo count($requiredTables); ?></div>
                <div class="stat-card-label">جداول جاهزة</div>
                <div class="stat-card-sub"><i class="fas fa-check-circle me-1"></i><?php echo $foundationReady ? 'المحرك مكتمل' : 'توجد جداول ناقصة'; ?></div>
            </div>
        </div>
    </div>
    <div class="col">
        <div class="stat-card" style="--card-gradient: linear-gradient(135deg, #10b981, #059669);">
            <div class="stat-card-icon"><i class="fas fa-calendar-check"></i></div>
            <div class="stat-card-info">
                <div class="stat-card-number"><?php echo htmlspecialchars($currentAcademicYearName, ENT_QUOTES, 'UTF-8'); ?></div>
                <div class="stat-card-label">العام الدراسي</div>
                <div class="stat-card-sub"><i class="fas fa-school me-1"></i>السياق الحالي</div>
            </div>
        </div>
    </div>
    <div class="col">
        <div class="stat-card" style="--card-gradient: linear-gradient(135deg, #8b5cf6, #7c3aed);">
            <div class="stat-card-icon"><i class="fas fa-layer-group"></i></div>
            <div class="stat-card-info">
                <div class="stat-card-number"><?php echo count($entryPages); ?></div>
                <div class="stat-card-label">صفحات مستقلة</div>
                <div class="stat-card-sub"><i class="fas fa-sitemap me-1"></i>بديل الصفحة القديمة</div>
            </div>
        </div>
    </div>
    <div class="col">
        <div class="stat-card" style="--card-gradient: linear-gradient(135deg, #f59e0b, #d97706);">
            <div class="stat-card-icon"><i class="fas fa-route"></i></div>
            <div class="stat-card-info">
                <div class="stat-card-number">مؤرشف</div>
                <div class="stat-card-label">مسار الكتابة القديم</div>
                <div class="stat-card-sub"><i class="fas fa-shield-alt me-1"></i>POST يعاد توجيهه</div>
            </div>
        </div>
    </div>
</div>

<div class="row g-3 mb-4">
    <?php foreach ($entryPages as $page): ?>
        <div class="col-md-6 col-xl-4">
            <a href="<?php echo htmlspecialchars($page['href'], ENT_QUOTES, 'UTF-8'); ?>" class="text-decoration-none text-dark h-100 d-block">
                <div class="assessment-entry-card h-100 p-3 bg-white">
                    <div class="d-flex align-items-start gap-3">
                        <span class="btn btn-<?php echo htmlspecialchars($page['color'], ENT_QUOTES, 'UTF-8'); ?> btn-icon flex-shrink-0">
                            <i class="fas <?php echo htmlspecialchars($page['icon'], ENT_QUOTES, 'UTF-8'); ?>"></i>
                        </span>
                        <div>
                            <h5 class="mb-1"><?php echo htmlspecialchars($page['title'], ENT_QUOTES, 'UTF-8'); ?></h5>
                            <p class="text-muted mb-0 small"><?php echo htmlspecialchars($page['text'], ENT_QUOTES, 'UTF-8'); ?></p>
                        </div>
                    </div>
                </div>
            </a>
        </div>
    <?php endforeach; ?>
</div>

<div class="admin-list-surface mb-4">
        <div class="table-responsive admin-table-wrap">
            <table class="table table-hover table-striped align-middle admin-data-table">
                <thead>
                    <tr>
                        <th>الجدول</th>
                        <th>الوصف</th>
                        <th>الحالة</th>
                        <th>عدد السجلات</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($tableStatus as $row): ?>
                        <tr>
                            <td><code><?php echo htmlspecialchars($row['table'], ENT_QUOTES, 'UTF-8'); ?></code></td>
                            <td><?php echo htmlspecialchars($row['label'], ENT_QUOTES, 'UTF-8'); ?></td>
                            <td>
                                <?php if ($row['exists']): ?>
                                    <span class="badge bg-success"><i class="fas fa-check me-1"></i>موجود</span>
                                <?php else: ?>
                                    <span class="badge bg-danger"><i class="fas fa-times me-1"></i>ناقص</span>
                                <?php endif; ?>
                            </td>
                            <td><?php echo number_format((int) $row['count']); ?></td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
</div>

<script>
document.addEventListener('DOMContentLoaded', function () {
    var legacyHashRoutes = {
        '#calendar': 'assessment_calendar.php',
        '#assignments': 'assessment_subject_assignments.php',
        '#teacher-assignments': 'assessment_teacher_assignments.php',
        '#schemes': 'assessment_schemes.php',
        '#components': 'assessment_components.php',
        '#component-week-rules': 'assessment_component_week_rules.php',
        '#windows': 'assessment_windows.php',
        '#reports': 'assessment_reports.php',
        '#permissions': 'assessment_permissions.php',
        '#student-locks': 'assessment_student_locks.php'
    };
    if (legacyHashRoutes[window.location.hash]) {
        window.location.replace(legacyHashRoutes[window.location.hash]);
    }
});
</script>

<?php require_once '../includes/admin_footer.php'; ?>

