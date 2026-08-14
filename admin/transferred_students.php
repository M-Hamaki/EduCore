<?php
/**
 * الطلاب المنقولون من المدرسة في العام الدراسي الحالي.
 * هذه قائمة قراءة مستقلة؛ تعديل ملف الطالب يبقى عبر مالك ملف الطالب المشترك.
 */
$page_title = 'المنقولون من المدرسة';
$custom_page_title = true;

require_once '../config/database.php';
require_once '../classes/utilities.php';
require_once '../classes/AcademicYear.php';
require_once '../includes/session_config.php';
require_once '../includes/csrf.php';
require_once '../src/Modules/Students/bootstrap.php';

use EduCore\Modules\Students\DerivedStudentListDataTableQuery;

Utilities::validateSession('admin');

// Compatibility for forms opened before this list became independent.
// New list requests never load the legacy page; profile writes keep their existing owner.
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    requireCsrfPost();
    define('STUDENT_DATA_SCOPE', 'transferred');
    require __DIR__ . '/students.php';
    return;
}

$profileAction = (string) ($_GET['action'] ?? '');
if (in_array($profileAction, ['add', 'edit', 'view'], true)) {
    $profileParams = $_GET;
    $profileParams['student_scope'] = 'transferred';
    header('Location: students.php?' . http_build_query($profileParams));
    exit;
}

$db = (new Database())->getConnection();
$currentAcademicYearId = AcademicYear::currentId($db);
$currentYear = AcademicYear::getCurrent($db);

$filterStages = isset($_GET['stage_ids']) && is_array($_GET['stage_ids'])
    ? array_values(array_filter(array_map('intval', $_GET['stage_ids'])))
    : [];
$filterGrades = isset($_GET['grade_ids']) && is_array($_GET['grade_ids'])
    ? array_values(array_filter(array_map('intval', $_GET['grade_ids'])))
    : [];
$filterClasses = isset($_GET['class_ids']) && is_array($_GET['class_ids'])
    ? array_values(array_filter(array_map('intval', $_GET['class_ids'])))
    : [];
$filters = [
    'stage_ids' => $filterStages,
    'grade_ids' => $filterGrades,
    'class_ids' => $filterClasses,
    'destination' => '',
];

$derivedListQuery = new DerivedStudentListDataTableQuery($db);
$transferredSummary = $derivedListQuery->transferredStudentsSummary($currentAcademicYearId, $filters);

$stages = $db->query("SELECT id, stage_name FROM stages WHERE status = 'active' ORDER BY stage_order, id")->fetchAll(PDO::FETCH_ASSOC);
$grades = $db->query("SELECT id, grade_name, stage_id FROM grades WHERE status = 'active' ORDER BY grade_order, id")->fetchAll(PDO::FETCH_ASSOC);
$classesStmt = $db->prepare(
    "SELECT id, name, grade_id
     FROM classes
     WHERE status = 'active' AND academic_year_id = ?
     ORDER BY display_order, name"
);
$classesStmt->execute([$currentAcademicYearId]);
$classes = $classesStmt->fetchAll(PDO::FETCH_ASSOC);

include_once '../includes/admin_header.php';
?>

<div class="admin-page-heading">
    <h1 class="h2"><i class="fas fa-person-walking-arrow-right me-2 text-warning"></i>المنقولون من المدرسة</h1>
</div>

<div class="row row-cols-2 row-cols-md-4 g-3 mb-4">
    <div class="col">
        <div class="stat-card" style="--card-gradient: linear-gradient(135deg, #f97316, #ea580c);">
            <div class="stat-card-icon"><i class="fas fa-person-walking-arrow-right"></i></div>
            <div class="stat-card-info">
                <div class="stat-card-number counter" data-target="<?php echo (int) $transferredSummary['total']; ?>">0</div>
                <div class="stat-card-label">إجمالي المنقولين</div>
                <div class="stat-card-sub"><i class="fas fa-calendar-check"></i> في العام الدراسي الحالي</div>
            </div>
        </div>
    </div>
    <div class="col">
        <div class="stat-card" style="--card-gradient: linear-gradient(135deg, #8b5cf6, #7c3aed);">
            <div class="stat-card-icon"><i class="fas fa-school-flag"></i></div>
            <div class="stat-card-info">
                <div class="stat-card-number counter" data-target="<?php echo (int) $transferredSummary['destination_count']; ?>">0</div>
                <div class="stat-card-label">جهات النقل</div>
                <div class="stat-card-sub"><i class="fas fa-location-dot"></i> الجهات المسجلة</div>
            </div>
        </div>
    </div>
    <div class="col">
        <div class="stat-card" style="--card-gradient: linear-gradient(135deg, #0ea5e9, #0284c7);">
            <div class="stat-card-icon"><i class="fas fa-calendar-day"></i></div>
            <div class="stat-card-info">
                <div class="stat-card-number admin-stat-date-number"><?php echo htmlspecialchars($transferredSummary['latest_transfer_date'] ?? '—', ENT_QUOTES, 'UTF-8'); ?></div>
                <div class="stat-card-label">أحدث عملية نقل</div>
                <div class="stat-card-sub"><i class="fas fa-clock"></i> آخر تاريخ مسجل</div>
            </div>
        </div>
    </div>
    <div class="col">
        <div class="stat-card" style="--card-gradient: linear-gradient(135deg, #10b981, #059669);">
            <div class="stat-card-icon"><i class="fas fa-calendar-alt"></i></div>
            <div class="stat-card-info">
                <div class="stat-card-number admin-stat-date-number"><?php echo htmlspecialchars((string) ($currentYear['name'] ?? '—'), ENT_QUOTES, 'UTF-8'); ?></div>
                <div class="stat-card-label">العام الدراسي</div>
                <div class="stat-card-sub"><i class="fas fa-book-open"></i> نطاق القائمة الحالي</div>
            </div>
        </div>
    </div>
</div>

<form method="GET" class="admin-filter-bar" id="transferredFilterForm">
    <div class="admin-filter-controls">
        <!-- Stages Dropdown -->
        <div class="dropdown d-inline-block me-2">
            <button class="btn btn-light dropdown-toggle btn-sm filter-dropdown-btn" type="button" id="stageDropdown" data-bs-toggle="dropdown" data-bs-auto-close="outside" aria-expanded="false" style="background: white; border-color: #dee2e6; color: #495057; height: 31px; display: inline-flex; align-items: center; justify-content: space-between; min-width: 140px;">
                <span>المراحل: <span id="selectedStagesLabel" class="fw-bold">الكل</span></span>
            </button>
            <div class="dropdown-menu p-3" aria-labelledby="stageDropdown" style="max-height: 250px; overflow-y: auto; min-width: 200px; text-align: right; box-shadow: 0 10px 15px -3px rgba(0, 0, 0, 0.1);">
                <?php foreach ($stages as $stage): ?>
                    <div class="form-check mb-1">
                        <input class="form-check-input stage-checkbox" type="checkbox" name="stage_ids[]" value="<?php echo (int) $stage['id']; ?>" id="stage_<?php echo (int) $stage['id']; ?>" <?php echo in_array((int)$stage['id'], $filterStages, true) ? 'checked' : ''; ?>>
                        <label class="form-check-label" for="stage_<?php echo (int) $stage['id']; ?>"><?php echo htmlspecialchars($stage['stage_name'], ENT_QUOTES, 'UTF-8'); ?></label>
                    </div>
                <?php endforeach; ?>
            </div>
        </div>

        <!-- Grades Dropdown -->
        <div class="dropdown d-inline-block me-2">
            <button class="btn btn-light dropdown-toggle btn-sm filter-dropdown-btn" type="button" id="gradeDropdown" data-bs-toggle="dropdown" data-bs-auto-close="outside" aria-expanded="false" style="background: white; border-color: #dee2e6; color: #495057; height: 31px; display: inline-flex; align-items: center; justify-content: space-between; min-width: 140px;">
                <span>الصفوف: <span id="selectedGradesLabel" class="fw-bold">الكل</span></span>
            </button>
            <div class="dropdown-menu p-3" aria-labelledby="gradeDropdown" style="max-height: 250px; overflow-y: auto; min-width: 220px; text-align: right; box-shadow: 0 10px 15px -3px rgba(0, 0, 0, 0.1);">
                <?php foreach ($grades as $grade): ?>
                    <div class="form-check mb-1 grade-item" data-stage="<?php echo (int) $grade['stage_id']; ?>">
                        <input class="form-check-input grade-checkbox" type="checkbox" name="grade_ids[]" value="<?php echo (int) $grade['id']; ?>" id="grade_<?php echo (int) $grade['id']; ?>" <?php echo in_array((int)$grade['id'], $filterGrades, true) ? 'checked' : ''; ?>>
                        <label class="form-check-label" for="grade_<?php echo (int) $grade['id']; ?>"><?php echo htmlspecialchars($grade['grade_name'], ENT_QUOTES, 'UTF-8'); ?></label>
                    </div>
                <?php endforeach; ?>
            </div>
        </div>

        <!-- Classes Dropdown -->
        <div class="dropdown d-inline-block me-2">
            <button class="btn btn-light dropdown-toggle btn-sm filter-dropdown-btn" type="button" id="classDropdown" data-bs-toggle="dropdown" data-bs-auto-close="outside" aria-expanded="false" style="background: white; border-color: #dee2e6; color: #495057; height: 31px; display: inline-flex; align-items: center; justify-content: space-between; min-width: 140px;">
                <span>الفصول: <span id="selectedClassesLabel" class="fw-bold">الكل</span></span>
            </button>
            <div class="dropdown-menu p-3" aria-labelledby="classDropdown" style="max-height: 250px; overflow-y: auto; min-width: 220px; text-align: right; box-shadow: 0 10px 15px -3px rgba(0, 0, 0, 0.1);">
                <?php foreach ($classes as $class): ?>
                    <div class="form-check mb-1 class-item" data-grade="<?php echo (int) $class['grade_id']; ?>">
                        <input class="form-check-input class-checkbox" type="checkbox" name="class_ids[]" value="<?php echo (int) $class['id']; ?>" id="class_<?php echo (int) $class['id']; ?>" <?php echo in_array((int)$class['id'], $filterClasses, true) ? 'checked' : ''; ?>>
                        <label class="form-check-label" for="class_<?php echo (int) $class['id']; ?>"><?php echo htmlspecialchars($class['name'], ENT_QUOTES, 'UTF-8'); ?></label>
                    </div>
                <?php endforeach; ?>
            </div>
        </div>
    </div>
    <div class="admin-filter-actions">
        <a href="transferred_students.php" class="btn btn-light btn-sm"><i class="fas fa-rotate-left me-1"></i>إعادة تعيين</a>
    </div>
</form>

<div class="admin-list-surface">
    <div class="table-responsive admin-table-wrap">
        <table class="table table-hover table-striped align-middle admin-data-table" id="transferredStudentsTable">
            <thead>
                <tr>
                    <th>#</th>
                    <th>الكود</th>
                    <th>اسم الطالب</th>
                    <th>المرحلة</th>
                    <th>الصف</th>
                    <th>الفصل السابق</th>
                    <th>الجهة المنقول إليها</th>
                    <th>تاريخ النقل</th>
                    <th>العام</th>
                    <th class="text-center">إجراءات</th>
                </tr>
            </thead>
            <tbody><tr><td colspan="10" class="text-center text-muted py-5">جاري تحميل الطلاب المنقولين…</td></tr></tbody>
        </table>
    </div>
</div>

<script src="../assets/js/admin-server-side-table.js"></script>
<script>
// ===== Multiple Selection Filter Cascading =====
function updateDropdownLabels() {
    // 1. Stages
    var checkedStages = document.querySelectorAll('.stage-checkbox:checked');
    var stageLabel = document.getElementById('selectedStagesLabel');
    var stageBtn = document.getElementById('stageDropdown');
    if (stageLabel) {
        if (checkedStages.length === 0 || checkedStages.length === document.querySelectorAll('.stage-checkbox').length) {
            stageLabel.textContent = 'الكل';
        } else if (checkedStages.length <= 2) {
            var names = [];
            checkedStages.forEach(function(cb) {
                names.push(cb.nextElementSibling.textContent.trim());
            });
            stageLabel.textContent = names.join('، ');
        } else {
            stageLabel.textContent = checkedStages.length + ' محددة';
        }
    }
    if (stageBtn) {
        stageBtn.classList.toggle('active-filter', checkedStages.length > 0);
    }

    // 2. Grades
    var checkedGrades = document.querySelectorAll('.grade-checkbox:checked');
    var gradeLabel = document.getElementById('selectedGradesLabel');
    var gradeBtn = document.getElementById('gradeDropdown');
    if (gradeLabel) {
        var visibleGradesCount = document.querySelectorAll('.grade-item:not([style*="display: none"])').length || document.querySelectorAll('.grade-checkbox').length;
        if (checkedGrades.length === 0 || checkedGrades.length === visibleGradesCount) {
            gradeLabel.textContent = 'الكل';
        } else if (checkedGrades.length <= 2) {
            var names = [];
            checkedGrades.forEach(function(cb) {
                names.push(cb.nextElementSibling.textContent.trim());
            });
            gradeLabel.textContent = names.join('، ');
        } else {
            gradeLabel.textContent = checkedGrades.length + ' محددة';
        }
    }
    if (gradeBtn) {
        gradeBtn.classList.toggle('active-filter', checkedGrades.length > 0);
    }

    // 3. Classes
    var checkedClasses = document.querySelectorAll('.class-checkbox:checked');
    var classLabel = document.getElementById('selectedClassesLabel');
    var classBtn = document.getElementById('classDropdown');
    if (classLabel) {
        var visibleClassesCount = document.querySelectorAll('.class-item:not([style*="display: none"])').length || document.querySelectorAll('.class-checkbox').length;
        if (checkedClasses.length === 0 || checkedClasses.length === visibleClassesCount) {
            classLabel.textContent = 'الكل';
        } else if (checkedClasses.length <= 2) {
            var names = [];
            checkedClasses.forEach(function(cb) {
                names.push(cb.nextElementSibling.textContent.trim());
            });
            classLabel.textContent = names.join('، ');
        } else {
            classLabel.textContent = checkedClasses.length + ' محددة';
        }
    }
    if (classBtn) {
        classBtn.classList.toggle('active-filter', checkedClasses.length > 0);
    }
}

function applyCascadingFilters() {
    var checkedStages = Array.from(document.querySelectorAll('.stage-checkbox:checked')).map(function(cb) {
        return cb.value;
    });

    // Update grades visibility
    var gradeItems = document.querySelectorAll('.grade-item');
    gradeItems.forEach(function(item) {
        var stageId = item.getAttribute('data-stage');
        if (checkedStages.length === 0 || checkedStages.includes(stageId)) {
            item.style.display = '';
        } else {
            item.style.display = 'none';
            var cb = item.querySelector('.grade-checkbox');
            if (cb && cb.checked) {
                cb.checked = false;
            }
        }
    });

    // Get checked grade IDs
    var checkedGrades = Array.from(document.querySelectorAll('.grade-checkbox:checked')).map(function(cb) {
        return cb.value;
    });

    // Update classes visibility
    var classItems = document.querySelectorAll('.class-item');
    classItems.forEach(function(item) {
        var gradeId = item.getAttribute('data-grade');
        var cb = item.querySelector('.class-checkbox');

        var gradeItem = document.querySelector('.grade-checkbox[value="' + gradeId + '"]');
        var isGradeVisible = gradeItem && gradeItem.closest('.grade-item').style.display !== 'none';

        if (isGradeVisible && (checkedGrades.length === 0 || checkedGrades.includes(gradeId))) {
            item.style.display = '';
        } else {
            item.style.display = 'none';
            if (cb && cb.checked) {
                cb.checked = false;
            }
        }
    });

    updateDropdownLabels();
}

document.addEventListener('DOMContentLoaded', function () {
    // Initial triggers
    applyCascadingFilters();

    // Bind change listeners to checkboxes
    document.querySelectorAll('.stage-checkbox').forEach(function(cb) {
        cb.addEventListener('change', applyCascadingFilters);
    });
    document.querySelectorAll('.grade-checkbox').forEach(function(cb) {
        cb.addEventListener('change', applyCascadingFilters);
    });
    document.querySelectorAll('.class-checkbox').forEach(function(cb) {
        cb.addEventListener('change', updateDropdownLabels);
    });

    // Auto-submit form when any filter dropdown collapses
    document.querySelectorAll('#transferredFilterForm .dropdown').forEach(function(dropdown) {
        dropdown.addEventListener('hide.bs.dropdown', function () {
            const filterForm = document.getElementById('transferredFilterForm');
            if (filterForm) {
                filterForm.submit();
            }
        });
    });

    if (!window.AdminServerSideTable) return;
    window.AdminServerSideTable.init({
        selector: '#transferredStudentsTable',
        url: 'ajax_derived_students_datatable.php',
        order: [[7, 'desc']],
        language: {
            processing: '<i class="fas fa-spinner fa-spin me-2"></i>جاري تحميل الطلاب المنقولين…',
            emptyTable: 'لا يوجد طلاب منقولون مطابقون.'
        },
        requestData: function () {
            return {
                list: 'transferred',
                stage_ids: Array.from(document.querySelectorAll('.stage-checkbox:checked')).map(function (cb) { return cb.value; }),
                grade_ids: Array.from(document.querySelectorAll('.grade-checkbox:checked')).map(function (cb) { return cb.value; }),
                class_ids: Array.from(document.querySelectorAll('.class-checkbox:checked')).map(function (cb) { return cb.value; })
            };
        }
    });
});
</script>

<?php require_once '../includes/admin_footer.php'; ?>
