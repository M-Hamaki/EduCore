<?php
/**
 * صفحة الخريجين — تعرض كل خريجي الأعوام السابقة
 * مصدر البيانات: student_enrollments (academic_status='graduated' + graduation_year)
 *
 * مزايا:
 *   - عمود "سنة التخرج" لكل خريج
 *   - فلتر حسب عام التخرج
 *   - لا تعتمد على "مرحلة الخريجين" الوهمية (تم إيقافها)
 */
$page_title = "الخريجين";
$custom_page_title = true;

require_once '../config/database.php';
require_once '../classes/utilities.php';
require_once '../classes/AcademicYear.php';
require_once '../classes/ActivityLog.php';
require_once '../includes/session_config.php';
require_once '../src/Modules/Students/bootstrap.php';
use EduCore\Modules\Students\DerivedStudentListDataTableQuery;
Utilities::validateSession('admin');

$database = new Database();
$db = $database->getConnection();
$currentAcademicYearId = AcademicYear::currentId($db);

// فلتر عام التخرج
$gradYearFilter = isset($_GET['grad_year']) ? trim($_GET['grad_year']) : '';

$derivedListQuery = new DerivedStudentListDataTableQuery($db);
$graduatesSummary = $derivedListQuery->graduatesSummary($gradYearFilter);

// كل سنوات التخرج المتاحة (للفلتر)
$yearsStmt = $db->query("SELECT DISTINCT se.graduation_year
    FROM student_enrollments se
    JOIN users u ON u.id = se.student_id
    WHERE (se.academic_status = 'graduated' OR se.enrollment_status = 'graduated')
      AND u.role = 'student' AND u.deleted_at IS NULL
      AND se.graduation_year IS NOT NULL AND se.graduation_year <> ''
    ORDER BY se.graduation_year DESC");
$gradYears = $yearsStmt->fetchAll(PDO::FETCH_COLUMN);

// Include header
include_once '../includes/admin_header.php';
?>

<div class="admin-page-heading">
    <h1 class="h2"><i class="fas fa-user-graduate me-2 text-success"></i>الخريجون</h1>
</div>

<!-- بطاقات إحصائية -->
<div class="row row-cols-2 row-cols-md-4 g-3 mb-4">
    <div class="col">
        <div class="stat-card" style="--card-gradient: linear-gradient(135deg, #10b981, #059669);">
            <div class="stat-card-icon"><i class="fas fa-user-graduate"></i></div>
            <div class="stat-card-info">
                <div class="stat-card-number counter" data-target="<?php echo (int)$graduatesSummary['total']; ?>">0</div>
                <div class="stat-card-label"><?php echo $gradYearFilter !== '' ? 'خريج عام ' . htmlspecialchars($gradYearFilter) : 'إجمالي الخريجين'; ?></div>
                <div class="stat-card-sub"><i class="fas fa-check-circle"></i> عدد الطلاب المتخرجين</div>
            </div>
        </div>
    </div>
    <div class="col">
        <div class="stat-card" style="--card-gradient: linear-gradient(135deg, #8b5cf6, #7c3aed);">
            <div class="stat-card-icon"><i class="fas fa-calendar-alt"></i></div>
            <div class="stat-card-info">
                <div class="stat-card-number counter" data-target="<?php echo (int)$graduatesSummary['years_count']; ?>">0</div>
                <div class="stat-card-label">دفعات تخرّج</div>
                <div class="stat-card-sub"><i class="fas fa-history"></i> الأعوام الدراسية السابقة</div>
            </div>
        </div>
    </div>
</div>

<!-- جدول الخريجين مع فلتر العام -->
<form method="GET" class="admin-filter-bar">
    <!-- الفلاتر من جهة اليمين -->
    <div class="admin-filter-controls">
        <select name="grad_year" class="form-select form-select-sm admin-inline-select-sm admin-min-160" onchange="this.form.submit()">
            <option value="">كل أعوام التخرج</option>
            <?php foreach ($gradYears as $yr): ?>
                <option value="<?php echo htmlspecialchars($yr); ?>" <?php echo ($gradYearFilter === $yr) ? 'selected' : ''; ?>><?php echo htmlspecialchars($yr); ?></option>
            <?php endforeach; ?>
        </select>
    </div>
    
    <!-- الأزرار من جهة اليسار -->
    <div class="admin-filter-actions">
        <a href="graduate_students.php" class="btn btn-light btn-sm"><i class="fas fa-rotate-left me-1"></i>إعادة تعيين</a>
    </div>
</form>

<div class="admin-list-surface">
    <div class="table-responsive admin-table-wrap">
        <table class="table table-hover table-striped align-middle admin-data-table" id="graduatesTable">
                <thead>
                    <tr>
                        <th>#</th>
                        <th>الكود</th>
                        <th>اسم الطالب</th>
                        <th>المرحلة</th>
                        <th>الصف عند التخرج</th>
                        <th>سنة التخرج</th>
                        <th class="text-center">إجراءات</th>
                    </tr>
                </thead>
                <tbody><tr><td colspan="7" class="text-center text-muted py-5">جاري تحميل الخريجين…</td></tr></tbody>
        </table>
    </div>
</div>

<script src="../assets/js/admin-server-side-table.js"></script>
<script>
document.addEventListener('DOMContentLoaded', function () {
    if (!window.AdminServerSideTable) return;
    window.AdminServerSideTable.init({
        selector: '#graduatesTable', url: 'ajax_derived_students_datatable.php', order: [[5, 'desc']],
        language: { processing: '<i class="fas fa-spinner fa-spin me-2"></i>جاري تحميل الخريجين…', emptyTable: 'لا يوجد خريجون مطابقون.' },
        requestData: function () { return { list: 'graduate', grad_year: document.querySelector('[name="grad_year"]').value }; }
    });
});
</script>

<?php require_once '../includes/admin_footer.php'; ?>
