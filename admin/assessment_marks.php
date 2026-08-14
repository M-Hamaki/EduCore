<?php

declare(strict_types=1);

$page_title = 'إدارة درجات الطلاب';
$custom_page_title = true;

require_once '../config/database.php';
require_once '../classes/utilities.php';
require_once '../classes/AcademicYear.php';
require_once '../classes/AcademicYearWriteGuard.php';
require_once '../classes/AssessmentEngine.php';
require_once '../classes/AssessmentMarkAdministrationQuery.php';
require_once '../classes/AssessmentMarkAdministrationService.php';
require_once '../includes/session_config.php';
require_once '../includes/csrf.php';

Utilities::validateSession('admin');
requireCsrfPost();

$db = (new Database())->getConnection();
$currentAcademicYear = AcademicYear::getCurrent($db) ?: AcademicYear::getActive($db);
$currentAcademicYearId = $currentAcademicYear ? (int) $currentAcademicYear['id'] : 0;
$currentAcademicYearName = (string) ($currentAcademicYear['name'] ?? '');
$activeRole = (string) ($_SESSION['active_role'] ?? $_SESSION['role'] ?? '');
$isSuperAdmin = $activeRole === 'super_admin';
$actorId = (int) ($_SESSION['user_id'] ?? 0);

$success_message = $_SESSION['success_message'] ?? null;
$error_message = $_SESSION['error_message'] ?? null;
unset($_SESSION['success_message'], $_SESSION['error_message']);

function assessment_marks_table_exists(PDO $db, string $table): bool
{
    $stmt = $db->prepare('SELECT 1 FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?');
    $stmt->execute([$table]);
    return (bool) $stmt->fetchColumn();
}

function assessment_marks_return_filters(array $source, bool $prefixed = false): array
{
    $keys = ['window_id', 'stage_id', 'grade_id', 'class_id', 'subject_id', 'scheme_id', 'component_id', 'week_id', 'mark_status', 'review_status'];
    $filters = [];
    foreach ($keys as $key) {
        $sourceKey = $prefixed ? ('return_' . $key) : $key;
        $value = trim((string) ($source[$sourceKey] ?? ''));
        if ($value !== '') {
            $filters[$key] = $value;
        }
    }
    return $filters;
}

function assessment_marks_redirect(array $filters = []): void
{
    header('Location: assessment_marks.php' . ($filters ? ('?' . http_build_query($filters)) : ''));
    exit();
}

$foundationReady = $currentAcademicYearId > 0
    && assessment_marks_table_exists($db, 'student_marks')
    && assessment_marks_table_exists($db, 'student_mark_audit')
    && assessment_marks_table_exists($db, 'assessment_schemes')
    && assessment_marks_table_exists($db, 'assessment_components')
    && assessment_marks_table_exists($db, 'assessment_windows');

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $returnFilters = assessment_marks_return_filters($_POST, true);
    try {
        if (!$foundationReady) {
            throw new RuntimeException('جداول إدارة درجات الطلاب غير مكتملة.');
        }
        (new AcademicYearWriteGuard($db))->assertWritable($currentAcademicYearId);
        $action = (string) ($_POST['action'] ?? '');
        $service = new AssessmentMarkAdministrationService($db);
        if ($action === 'update_mark') {
            $result = $service->updateMark(
                (int) ($_POST['mark_id'] ?? 0),
                $_POST,
                $currentAcademicYearId,
                $actorId,
                $activeRole
            );
            $_SESSION['success_message'] = 'تم تصحيح الدرجة وتسجيل سبب التعديل.';
            if ((int) ($result['published_count'] ?? 0) > 0) {
                $_SESSION['success_message'] .= ' توجد نسخ تقارير منشورة لم تتغير؛ أعد نشر التقرير إذا أردت إظهار القيمة الجديدة.';
            }
            assessment_marks_redirect($returnFilters);
        }
        if ($action === 'delete_marks') {
            $result = $service->deleteMarks(
                AssessmentMarkAdministrationService::normalizeIds($_POST['selected_ids'] ?? ''),
                $currentAcademicYearId,
                $actorId,
                $activeRole,
                (string) ($_POST['reason'] ?? '')
            );
            $_SESSION['success_message'] = 'تم حذف ' . (int) $result['affected'] . ' درجة كدفعة ذرية قابلة للتراجع.';
            if ((int) ($result['published_count'] ?? 0) > 0) {
                $_SESSION['success_message'] .= ' احتُفظ بنسخ التقارير المنشورة؛ ألغِ نشرها من صفحة التقارير إذا كانت تجريبية.';
            }
            assessment_marks_redirect($returnFilters);
        }
        throw new InvalidArgumentException('الإجراء المطلوب غير معروف.');
    } catch (Throwable $error) {
        if ($db->inTransaction()) {
            $db->rollBack();
        }
        $_SESSION['error_message'] = $error->getMessage();
        assessment_marks_redirect($returnFilters);
    }
}

$query = new AssessmentMarkAdministrationQuery($db);
$filterOptions = $foundationReady ? $query->filterOptions($currentAcademicYearId) : [
    'stages' => [], 'grades' => [], 'classes' => [], 'subjects' => [], 'schemes' => [], 'components' => [], 'weeks' => [], 'windows' => [],
];
$initialFilters = assessment_marks_return_filters($_GET);
$summary = $foundationReady ? $query->summary($currentAcademicYearId, $initialFilters) : ['total' => 0, 'present' => 0, 'absence' => 0, 'pending' => 0];
$selected = static fn(string $key, $value): string => (string) ($initialFilters[$key] ?? '') === (string) $value ? 'selected' : '';

require_once '../includes/admin_header.php';
?>

<div class="admin-page-heading">
    <h1 class="h2 d-flex align-items-center">
        <i class="fas fa-graduation-cap me-2 text-primary"></i>
        <span>إدارة درجات الطلاب</span>
        <i class="fas fa-info-circle ms-2 text-muted fs-6" 
           data-bs-toggle="tooltip" 
           data-bs-placement="top" 
           title="هذه الصفحة تدير الدرجات الأصلية. تعديلها أو حذفها لا يغيّر نسخ التقارير المنشورة تلقائيًا، وحذف نافذة الرصد لا يحذف هذه الدرجات." 
           style="cursor: pointer;"
           aria-label="معلومات عن إدارة درجات الطلاب"></i>
    </h1>
    <div class="admin-top-actions no-print">
        <a href="assessment_marks_sheet.php" class="btn btn-header-premium btn-import-soft">
            <i class="fas fa-table-cells me-1"></i>كشف رصد الدرجات
        </a>
    </div>
</div>

<?php if ($success_message): ?>
    <div class="alert alert-success alert-dismissible fade show" role="alert"><i class="fas fa-check-circle me-2"></i><?php echo htmlspecialchars((string) $success_message, ENT_QUOTES, 'UTF-8'); ?><button type="button" class="btn-close" data-bs-dismiss="alert"></button></div>
<?php endif; ?>
<?php if ($error_message): ?>
    <div class="alert alert-danger alert-dismissible fade show" role="alert"><i class="fas fa-exclamation-circle me-2"></i><?php echo htmlspecialchars((string) $error_message, ENT_QUOTES, 'UTF-8'); ?><button type="button" class="btn-close" data-bs-dismiss="alert"></button></div>
<?php endif; ?>

<?php if (!$foundationReady): ?>
    <div class="alert alert-warning"><i class="fas fa-triangle-exclamation me-2"></i>طبّق جداول نظام التقييم وحدد عامًا دراسيًا قبل استخدام إدارة الدرجات.</div>
<?php else: ?>

    <div class="row row-cols-2 row-cols-md-4 g-3 mb-4">
        <div class="col"><div class="stat-card" style="--card-gradient: linear-gradient(135deg, #3b82f6, #2563eb);"><div class="stat-card-icon"><i class="fas fa-list-ol"></i></div><div class="stat-card-info"><div class="stat-card-number counter" data-datatable-summary-key="total" data-target="<?php echo (int) $summary['total']; ?>">0</div><div class="stat-card-label">إجمالي الدرجات</div><div class="stat-card-sub"><?php echo htmlspecialchars($currentAcademicYearName ?: 'العام المختار', ENT_QUOTES, 'UTF-8'); ?></div></div></div></div>
        <div class="col"><div class="stat-card" style="--card-gradient: linear-gradient(135deg, #10b981, #059669);"><div class="stat-card-icon"><i class="fas fa-check"></i></div><div class="stat-card-info"><div class="stat-card-number counter" data-datatable-summary-key="present" data-target="<?php echo (int) $summary['present']; ?>">0</div><div class="stat-card-label">درجات رقمية</div><div class="stat-card-sub">قيم مرصودة</div></div></div></div>
        <div class="col"><div class="stat-card" style="--card-gradient: linear-gradient(135deg, #f97316, #ea580c);"><div class="stat-card-icon"><i class="fas fa-user-clock"></i></div><div class="stat-card-info"><div class="stat-card-number counter" data-datatable-summary-key="absence" data-target="<?php echo (int) $summary['absence']; ?>">0</div><div class="stat-card-label">حالات غياب</div><div class="stat-card-sub">بعذر وبدون عذر</div></div></div></div>
        <div class="col"><div class="stat-card" style="--card-gradient: linear-gradient(135deg, #f59e0b, #d97706);"><div class="stat-card-icon"><i class="fas fa-hourglass-half"></i></div><div class="stat-card-info"><div class="stat-card-number counter" data-datatable-summary-key="pending" data-target="<?php echo (int) $summary['pending']; ?>">0</div><div class="stat-card-label">بانتظار المراجعة</div><div class="stat-card-sub">تحتاج اعتمادًا</div></div></div></div>
    </div>

    <form method="get" id="marksFilterForm" class="admin-filter-bar">
        <div class="admin-filter-controls">
            <select name="window_id" id="filterWindow" class="form-select form-select-sm"><option value="">كل نوافذ الرصد</option><?php foreach ($filterOptions['windows'] as $option): ?><option value="<?php echo (int) $option['id']; ?>" <?php echo $selected('window_id', $option['id']); ?>><?php echo htmlspecialchars($option['name'] . ' — ' . $option['subject_name'] . ' — ' . $option['grade_name'], ENT_QUOTES, 'UTF-8'); ?></option><?php endforeach; ?></select>
            <select name="stage_id" id="filterStage" class="form-select form-select-sm"><option value="">كل المراحل</option><?php foreach ($filterOptions['stages'] as $option): ?><option value="<?php echo (int) $option['id']; ?>" <?php echo $selected('stage_id', $option['id']); ?>><?php echo htmlspecialchars($option['name'], ENT_QUOTES, 'UTF-8'); ?></option><?php endforeach; ?></select>
            <select name="grade_id" id="filterGrade" class="form-select form-select-sm"><option value="">كل الصفوف</option><?php foreach ($filterOptions['grades'] as $option): ?><option value="<?php echo (int) $option['id']; ?>" data-stage="<?php echo (int) $option['stage_id']; ?>" <?php echo $selected('grade_id', $option['id']); ?>><?php echo htmlspecialchars($option['name'], ENT_QUOTES, 'UTF-8'); ?></option><?php endforeach; ?></select>
            <select name="class_id" id="filterClass" class="form-select form-select-sm"><option value="">كل الفصول</option><?php foreach ($filterOptions['classes'] as $option): ?><option value="<?php echo (int) $option['id']; ?>" data-grade="<?php echo (int) $option['grade_id']; ?>" <?php echo $selected('class_id', $option['id']); ?>><?php echo htmlspecialchars($option['name'], ENT_QUOTES, 'UTF-8'); ?></option><?php endforeach; ?></select>
            <select name="subject_id" id="filterSubject" class="form-select form-select-sm"><option value="">كل المواد</option><?php foreach ($filterOptions['subjects'] as $option): ?><option value="<?php echo (int) $option['id']; ?>" <?php echo $selected('subject_id', $option['id']); ?>><?php echo htmlspecialchars($option['name'], ENT_QUOTES, 'UTF-8'); ?></option><?php endforeach; ?></select>
            <select name="scheme_id" id="filterScheme" class="form-select form-select-sm"><option value="">كل الخطط</option><?php foreach ($filterOptions['schemes'] as $option): ?><option value="<?php echo (int) $option['id']; ?>" data-subject="<?php echo (int) $option['subject_id']; ?>" <?php echo $selected('scheme_id', $option['id']); ?>><?php echo htmlspecialchars($option['name'], ENT_QUOTES, 'UTF-8'); ?></option><?php endforeach; ?></select>
            <select name="component_id" id="filterComponent" class="form-select form-select-sm"><option value="">كل البنود</option><?php foreach ($filterOptions['components'] as $option): ?><option value="<?php echo (int) $option['id']; ?>" data-scheme="<?php echo (int) $option['scheme_id']; ?>" <?php echo $selected('component_id', $option['id']); ?>><?php echo htmlspecialchars($option['name'], ENT_QUOTES, 'UTF-8'); ?></option><?php endforeach; ?></select>
            <select name="week_id" id="filterWeek" class="form-select form-select-sm"><option value="">كل الأسابيع</option><?php foreach ($filterOptions['weeks'] as $option): ?><option value="<?php echo (int) $option['id']; ?>" <?php echo $selected('week_id', $option['id']); ?>><?php echo htmlspecialchars($option['name'], ENT_QUOTES, 'UTF-8'); ?></option><?php endforeach; ?></select>
            <select name="mark_status" id="filterMarkStatus" class="form-select form-select-sm"><option value="">كل حالات الرصد</option><option value="present" <?php echo $selected('mark_status', 'present'); ?>>درجة رقمية</option><option value="absent" <?php echo $selected('mark_status', 'absent'); ?>>غائب</option><option value="excused_absent" <?php echo $selected('mark_status', 'excused_absent'); ?>>غياب بعذر</option><option value="exempt" <?php echo $selected('mark_status', 'exempt'); ?>>معفى</option><option value="empty" <?php echo $selected('mark_status', 'empty'); ?>>فارغة</option></select>
            <select name="review_status" id="filterReviewStatus" class="form-select form-select-sm"><option value="">كل حالات المراجعة</option><option value="pending" <?php echo $selected('review_status', 'pending'); ?>>بانتظار المراجعة</option><option value="approved" <?php echo $selected('review_status', 'approved'); ?>>معتمدة</option><option value="rejected" <?php echo $selected('review_status', 'rejected'); ?>>مرفوضة</option><option value="not_required" <?php echo $selected('review_status', 'not_required'); ?>>لا تتطلب مراجعة</option></select>
        </div>
        <div class="admin-filter-actions">
            <button type="button" id="resetMarksFilters" class="btn btn-light btn-sm"><i class="fas fa-rotate-left me-1"></i>إعادة تعيين</button>
            <button type="submit" class="btn btn-light btn-sm"><i class="fas fa-search me-1"></i>تطبيق</button>
        </div>
    </form>

    <?php if ($isSuperAdmin): ?>
        <div class="admin-bulk-action-bar d-none" id="marksBulkActionBar">
            <div class="admin-bulk-info">
                <span>الدرجات المحددة:</span>
                <span class="admin-bulk-badge" id="selectedMarksCount">0</span>
            </div>
            <div class="admin-bulk-actions">
                <button type="button" id="deleteSelectedMarks" class="btn btn-danger btn-sm" disabled><i class="fas fa-trash me-1"></i>حذف الدرجات المحددة</button>
            </div>
        </div>
    <?php endif; ?>

    <div class="admin-list-surface">
        <div class="admin-table-wrap">
            <table id="assessmentMarksTable" class="table table-hover table-striped admin-data-table align-middle">
                <thead><tr><th class="text-center no-sort" data-orderable="false" style="width:42px;"><?php if ($isSuperAdmin): ?><input type="checkbox" id="selectCurrentMarksPage" class="form-check-input" title="تحديد درجات الصفحة الحالية" aria-label="تحديد درجات الصفحة الحالية"><?php else: ?>—<?php endif; ?></th><th>الطالب</th><th>المرحلة/الصف/الفصل</th><th>المادة</th><th>الخطة والبند</th><th>الدرجة والحالة</th><th>الملاحظة</th><th>آخر تسجيل</th><th>نسخ منشورة</th><th class="admin-col-170px">إجراءات</th></tr></thead>
                <tbody><tr><td colspan="10" class="text-center text-muted py-4">جاري تحميل الدرجات…</td></tr></tbody>
            </table>
        </div>
    </div>

    <div class="modal fade" id="editMarkModal" tabindex="-1" aria-hidden="true">
        <div class="modal-dialog"><div class="modal-content admin-modal admin-modal-premium admin-modal-edit"><form method="post" action="assessment_marks.php">
            <?php echo csrfField(); ?><input type="hidden" name="action" value="update_mark"><input type="hidden" name="mark_id" id="editMarkId">
            <?php foreach (['window_id', 'stage_id', 'grade_id', 'class_id', 'subject_id', 'scheme_id', 'component_id', 'week_id', 'mark_status', 'review_status'] as $key): ?><input type="hidden" name="return_<?php echo $key; ?>" data-return-filter="<?php echo $key; ?>" value="<?php echo htmlspecialchars((string) ($initialFilters[$key] ?? ''), ENT_QUOTES, 'UTF-8'); ?>"><?php endforeach; ?>
            <div class="modal-header"><h5 class="modal-title"><i class="fas fa-edit me-2"></i>تصحيح درجة طالب</h5><button type="button" class="btn-close" data-bs-dismiss="modal"></button></div>
            <div class="modal-body">
                <div class="text-center mb-3"><i class="fas fa-pen-to-square text-primary admin-modal-icon-lg"></i></div>
                <p class="text-center mb-1">الطالب: <strong id="editMarkStudent" class="text-primary"></strong></p><p id="editMarkScope" class="small text-muted text-center"></p>
                <div id="editMarkLockedNotice" class="alert alert-danger d-none"><i class="fas fa-lock me-2"></i>هذه درجة مقفلة؛ تعديلها استثناء متاح لمدير النظام الأعلى فقط.</div>
                <div id="editMarkPublishedNotice" class="alert alert-warning d-none"><i class="fas fa-file-lines me-2"></i>توجد <strong id="editMarkPublishedCount">0</strong> نسخة منشورة ولن تتغير تلقائيًا.</div>
                <div class="row g-3">
                    <div class="col-md-6"><label class="form-label">حالة الرصد</label><select name="mark_status" id="editMarkStatus" class="form-select" required><option value="present">درجة رقمية</option><option value="absent">غائب</option><option value="excused_absent">غياب بعذر</option><option value="exempt">معفى</option><option value="empty">فارغة</option></select></div>
                    <div class="col-md-6"><label class="form-label">الدرجة <span id="editMarkMax" class="text-muted"></span></label><input type="number" name="value" id="editMarkValue" class="form-control" min="0" step="0.01"></div>
                    <div class="col-12"><label class="form-label">ملاحظة الدرجة</label><textarea name="note" id="editMarkNote" class="form-control" rows="2" maxlength="500"></textarea></div>
                    <div class="col-12"><label class="form-label">سبب التصحيح</label><textarea name="reason" class="form-control" rows="2" minlength="5" maxlength="500" required placeholder="مثال: تصحيح إدخال خاطئ بعد مراجعة ورقة الطالب"></textarea><div class="form-text">سيظهر السبب في سجل التدقيق، وستعود الدرجة للمراجعة إذا كان البند يتطلبها.</div></div>
                </div>
            </div>
            <div class="modal-footer"><button type="button" class="btn btn-secondary" data-bs-dismiss="modal"><i class="fas fa-times me-1"></i>إلغاء</button><button type="submit" class="btn btn-primary"><i class="fas fa-save me-1"></i>حفظ التصحيح</button></div>
        </form></div></div>
    </div>

    <?php if ($isSuperAdmin): ?>
        <div class="modal fade" id="deleteMarksModal" tabindex="-1" aria-hidden="true">
            <div class="modal-dialog"><div class="modal-content admin-modal admin-modal-premium admin-modal-delete"><form method="post" action="assessment_marks.php" id="deleteMarksForm">
                <?php echo csrfField(); ?><input type="hidden" name="action" value="delete_marks"><input type="hidden" name="selected_ids" id="deleteMarkIds">
                <?php foreach (['window_id', 'stage_id', 'grade_id', 'class_id', 'subject_id', 'scheme_id', 'component_id', 'week_id', 'mark_status', 'review_status'] as $key): ?><input type="hidden" name="return_<?php echo $key; ?>" data-return-filter="<?php echo $key; ?>" value="<?php echo htmlspecialchars((string) ($initialFilters[$key] ?? ''), ENT_QUOTES, 'UTF-8'); ?>"><?php endforeach; ?>
                <div class="modal-header"><h5 class="modal-title"><i class="fas fa-trash me-2"></i>حذف درجات الطلاب</h5><button type="button" class="btn-close" data-bs-dismiss="modal"></button></div>
                <div class="modal-body"><div class="text-center mb-3"><i class="fas fa-triangle-exclamation text-danger admin-modal-icon-lg"></i></div><p class="text-center">سيتم حذف <strong id="deleteMarksCount" class="text-danger">0</strong> درجة أصلية.</p><div class="alert alert-danger"><i class="fas fa-shield-halved me-2"></i>هذا الإجراء متاح لمدير النظام الأعلى فقط، وينفذ كدفعة ذرية قابلة للتراجع.</div><div class="alert alert-warning"><i class="fas fa-file-lines me-2"></i>نسخ التقارير المنشورة تبقى كما هي ويجب إلغاء نشرها منفصلًا إذا كانت تجريبية.</div><label class="form-label">سبب الحذف</label><textarea name="reason" class="form-control" rows="3" minlength="5" maxlength="500" required placeholder="اشرح لماذا يجب حذف هذه الدرجات الأصلية"></textarea></div>
                <div class="modal-footer"><button type="button" class="btn btn-secondary" data-bs-dismiss="modal"><i class="fas fa-times me-1"></i>إلغاء</button><button type="submit" class="btn btn-danger"><i class="fas fa-trash me-1"></i>حذف الدرجات</button></div>
            </form></div></div>
        </div>
    <?php endif; ?>

    <script src="../assets/js/admin-server-side-table.js"></script>
    <script>
    document.addEventListener('DOMContentLoaded', function () {
        const filterForm = document.getElementById('marksFilterForm');
        const filterIds = ['filterWindow', 'filterStage', 'filterGrade', 'filterClass', 'filterSubject', 'filterScheme', 'filterComponent', 'filterWeek', 'filterMarkStatus', 'filterReviewStatus'];
        const requestData = function () {
            return {
                academic_year_id: <?php echo (int) $currentAcademicYearId; ?>,
                window_id: document.getElementById('filterWindow').value,
                stage_id: document.getElementById('filterStage').value,
                grade_id: document.getElementById('filterGrade').value,
                class_id: document.getElementById('filterClass').value,
                subject_id: document.getElementById('filterSubject').value,
                scheme_id: document.getElementById('filterScheme').value,
                component_id: document.getElementById('filterComponent').value,
                week_id: document.getElementById('filterWeek').value,
                mark_status: document.getElementById('filterMarkStatus').value,
                review_status: document.getElementById('filterReviewStatus').value
            };
        };
        function syncReturnFilters() { const values = requestData(); document.querySelectorAll('[data-return-filter]').forEach(function (input) { input.value = values[input.dataset.returnFilter] || ''; }); }
        const table = window.AdminServerSideTable && window.AdminServerSideTable.init({
            selector: '#assessmentMarksTable', url: 'ajax_assessment_marks_datatable.php', order: [[1, 'asc']], requestData: requestData,
            language: { processing: '<i class="fas fa-spinner fa-spin me-2"></i>جاري تحميل الدرجات…', emptyTable: 'لا توجد درجات مطابقة.' }
        });
        filterForm.addEventListener('submit', function (event) { event.preventDefault(); if (table) table.ajax.reload(); });
        document.getElementById('resetMarksFilters').addEventListener('click', function () { filterIds.forEach(function (id) { document.getElementById(id).value = ''; }); if (table) table.search('').ajax.reload(); window.history.replaceState({}, '', 'assessment_marks.php'); });

        const statusInput = document.getElementById('editMarkStatus');
        const valueInput = document.getElementById('editMarkValue');
        function syncValueInput() { const numeric = statusInput.value === 'present'; valueInput.disabled = !numeric; valueInput.required = numeric; if (!numeric) valueInput.value = ''; }
        statusInput.addEventListener('change', syncValueInput);
        document.getElementById('assessmentMarksTable').addEventListener('click', function (event) {
            const editButton = event.target.closest('.edit-mark-btn');
            if (editButton) {
                syncReturnFilters();
                document.getElementById('editMarkId').value = editButton.dataset.markId || '';
                document.getElementById('editMarkStudent').textContent = editButton.dataset.studentName || '';
                document.getElementById('editMarkScope').textContent = editButton.dataset.scopeLabel || '';
                statusInput.value = editButton.dataset.markStatus || 'present'; valueInput.value = editButton.dataset.markValue || '';
                valueInput.max = editButton.dataset.maxGrade || ''; document.getElementById('editMarkMax').textContent = '(الحد الأقصى ' + (editButton.dataset.maxGrade || '-') + ')';
                document.getElementById('editMarkNote').value = editButton.dataset.markNote || '';
                document.getElementById('editMarkLockedNotice').classList.toggle('d-none', editButton.dataset.locked !== '1');
                const publishedCount = Number(editButton.dataset.publishedCount || 0); document.getElementById('editMarkPublishedCount').textContent = String(publishedCount); document.getElementById('editMarkPublishedNotice').classList.toggle('d-none', publishedCount <= 0);
                syncValueInput(); bootstrap.Modal.getOrCreateInstance(document.getElementById('editMarkModal')).show(); return;
            }
            const deleteButton = event.target.closest('.delete-single-mark-btn');
            if (deleteButton) { openDeleteModal([deleteButton.dataset.markId]); }
        });

        <?php if ($isSuperAdmin): ?>
        const selected = new Set(); const selectPage = document.getElementById('selectCurrentMarksPage'); const deleteSelected = document.getElementById('deleteSelectedMarks');
        function updateSelection() { document.getElementById('selectedMarksCount').textContent = String(selected.size); document.getElementById('marksBulkActionBar').classList.toggle('d-none', selected.size === 0); deleteSelected.disabled = selected.size === 0; const boxes = Array.from(document.querySelectorAll('.assessment-mark-select')); selectPage.checked = boxes.length > 0 && boxes.every(function (box) { return box.checked; }); selectPage.indeterminate = boxes.some(function (box) { return box.checked; }) && !selectPage.checked; }
        function clearSelection() { selected.clear(); document.querySelectorAll('.assessment-mark-select').forEach(function (box) { box.checked = false; }); updateSelection(); }
        document.getElementById('assessmentMarksTable').addEventListener('change', function (event) { if (!event.target.matches('.assessment-mark-select')) return; const id = event.target.value; if (event.target.checked) selected.add(id); else selected.delete(id); updateSelection(); });
        selectPage.addEventListener('change', function () { document.querySelectorAll('.assessment-mark-select').forEach(function (box) { box.checked = selectPage.checked; if (selectPage.checked) selected.add(box.value); else selected.delete(box.value); }); updateSelection(); });
        if (table) table.on('draw', clearSelection);
        function openDeleteModal(ids) { const normalized = Array.from(new Set(ids.filter(Boolean))); if (!normalized.length) return; syncReturnFilters(); document.getElementById('deleteMarkIds').value = normalized.join(','); document.getElementById('deleteMarksCount').textContent = String(normalized.length); bootstrap.Modal.getOrCreateInstance(document.getElementById('deleteMarksModal')).show(); }
        deleteSelected.addEventListener('click', function () { openDeleteModal(Array.from(selected)); });
        <?php endif; ?>
    });
    </script>
<?php endif; ?>

<?php require_once '../includes/admin_footer.php'; ?>
