<?php
/**
 * صفحة المنقولون إلى المدرسة — تعرض كل طالب تم إضافته/قَيده في المدرسة
 * ضمن العام الدراسي الحالي (تاريخ القيد ضمن بداية ونهاية العام الحالي).
 *
 * مصدر البيانات: student_enrollments للعام الحالي
 *   - se.academic_year_id = العام الحالي
 *   - se.enrollment_date بين start_date و end_date للعام الحالي (عند توفرهما)
 *   - enrollment_status = 'enrolled' (طلاب مقيّدون فعلاً وليسوا خريجين/منقولين)
 *   - academic_status = 'new' (مستجدون فعلاً، لا سجلات الترحيل السنوي للناجحين/الراسبين)
 */
$page_title = "المنقولون إلى المدرسة";
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
$currentYear = AcademicYear::getCurrent($db);

// بداية ونهاية العام الحالي (لتقييد تاريخ القيد)
$yearStart = $currentYear['start_date'] ?? null;
$yearEnd   = $currentYear['end_date']   ?? null;

// الفلاتر
$filterStages = isset($_GET['stage_ids']) && is_array($_GET['stage_ids']) ? array_map('intval', $_GET['stage_ids']) : [];
$filterGrades = isset($_GET['grade_ids']) && is_array($_GET['grade_ids']) ? array_map('intval', $_GET['grade_ids']) : [];
$filterClasses = isset($_GET['class_ids']) && is_array($_GET['class_ids']) ? array_map('intval', $_GET['class_ids']) : [];

$derivedListQuery = new DerivedStudentListDataTableQuery($db);
$newStudentsSummary = $derivedListQuery->newStudentsSummary(
    $currentAcademicYearId,
    $yearStart,
    $yearEnd,
    ['stage_ids' => $filterStages, 'grade_ids' => $filterGrades, 'class_ids' => $filterClasses]
);

// جلب الفلاتر (مراحل/صفوف/فصول) مع علاقات التسلسل الهرمي للفلترة المتسلسلة
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

// إحصائيات خفيفة من الخادم دون تحميل صفوف الجدول كلها.
$totalNew = $newStudentsSummary['total'];
$topStage = $newStudentsSummary['top_stage'];

// Include header
include_once '../includes/admin_header.php';
?>

<div class="admin-page-heading">
    <h1 class="h2"><i class="fas fa-user-plus me-2 text-primary"></i>المنقولون إلى المدرسة</h1>
</div>

<!-- بطاقات إحصائية -->
<div class="row row-cols-2 row-cols-md-4 g-3 mb-4">
    <div class="col">
        <div class="stat-card" style="--card-gradient: linear-gradient(135deg, #3b82f6, #2563eb);">
            <div class="stat-card-icon"><i class="fas fa-user-plus"></i></div>
            <div class="stat-card-info">
                <div class="stat-card-number counter" data-target="<?php echo (int)$totalNew; ?>">0</div>
                <div class="stat-card-label">إجمالي المنقولين للعام</div>
                <div class="stat-card-sub"><i class="fas fa-user-check"></i> طلاب مسجلون جدد</div>
            </div>
        </div>
    </div>
    <div class="col">
        <div class="stat-card" style="--card-gradient: linear-gradient(135deg, #10b981, #059669);">
            <div class="stat-card-icon"><i class="fas fa-layer-group"></i></div>
            <div class="stat-card-info">
                <div class="stat-card-number counter" data-target="<?php echo (int)$newStudentsSummary['stage_count']; ?>">0</div>
                <div class="stat-card-label">عدد المراحل المعنية</div>
                <div class="stat-card-sub"><i class="fas fa-school"></i> المراحل التعليمية</div>
            </div>
        </div>
    </div>
    <div class="col">
        <div class="stat-card" style="--card-gradient: linear-gradient(135deg, #8b5cf6, #7c3aed);">
            <div class="stat-card-icon"><i class="fas fa-trophy"></i></div>
            <div class="stat-card-info">
                <div class="stat-card-number admin-stat-text-number"><?php echo htmlspecialchars($topStage); ?></div>
                <div class="stat-card-label">أكثر مرحلة استقبالاً</div>
                <div class="stat-card-sub"><i class="fas fa-star text-warning"></i> المرحلة الأعلى تسجيلاً</div>
            </div>
        </div>
    </div>
    <div class="col">
        <div class="stat-card" style="--card-gradient: linear-gradient(135deg, #f59e0b, #d97706);">
            <div class="stat-card-icon"><i class="fas fa-calendar-day"></i></div>
            <div class="stat-card-info">
                <div class="stat-card-number admin-stat-date-number"><?php echo htmlspecialchars($yearStart ?: '—'); ?></div>
                <div class="stat-card-label">تاريخ بدء القيد للعام</div>
                <div class="stat-card-sub"><i class="fas fa-flag"></i> بداية فترة التسجيل</div>
            </div>
        </div>
    </div>
</div>

<!-- جدول المنقولين إلى المدرسة مع فلاتر متسلسلة -->
<form method="GET" class="admin-filter-bar" id="filterForm">
    <!-- الفلاتر من جهة اليمين -->
    <div class="admin-filter-controls">
        <!-- Stages Dropdown -->
        <div class="dropdown d-inline-block">
            <button class="btn btn-light dropdown-toggle btn-sm filter-dropdown-btn" type="button" id="stageDropdown" data-bs-toggle="dropdown" data-bs-auto-close="outside" aria-expanded="false" style="background: white; border-color: #dee2e6; color: #495057; height: 31px; display: inline-flex; align-items: center; justify-content: space-between; min-width: 140px;">
                <span>المراحل: <span id="selectedStagesLabel" class="fw-bold">الكل</span></span>
            </button>
            <div class="dropdown-menu p-3" aria-labelledby="stageDropdown" style="max-height: 250px; overflow-y: auto; min-width: 200px; text-align: right; box-shadow: 0 10px 15px -3px rgba(0, 0, 0, 0.1);">
                <?php foreach ($stages as $st): ?>
                    <div class="form-check mb-1">
                        <input class="form-check-input stage-checkbox" type="checkbox" name="stage_ids[]" value="<?php echo $st['id']; ?>" id="stage_<?php echo $st['id']; ?>" <?php echo in_array($st['id'], $filterStages) ? 'checked' : ''; ?>>
                        <label class="form-check-label" for="stage_<?php echo $st['id']; ?>"><?php echo htmlspecialchars($st['stage_name']); ?></label>
                    </div>
                <?php endforeach; ?>
            </div>
        </div>

        <!-- Grades Dropdown -->
        <div class="dropdown d-inline-block">
            <button class="btn btn-light dropdown-toggle btn-sm filter-dropdown-btn" type="button" id="gradeDropdown" data-bs-toggle="dropdown" data-bs-auto-close="outside" aria-expanded="false" style="background: white; border-color: #dee2e6; color: #495057; height: 31px; display: inline-flex; align-items: center; justify-content: space-between; min-width: 140px;">
                <span>الصفوف: <span id="selectedGradesLabel" class="fw-bold">الكل</span></span>
            </button>
            <div class="dropdown-menu p-3" aria-labelledby="gradeDropdown" style="max-height: 250px; overflow-y: auto; min-width: 220px; text-align: right; box-shadow: 0 10px 15px -3px rgba(0, 0, 0, 0.1);">
                <?php foreach ($grades as $gr): ?>
                    <div class="form-check mb-1 grade-item" data-stage="<?php echo $gr['stage_id']; ?>">
                        <input class="form-check-input grade-checkbox" type="checkbox" name="grade_ids[]" value="<?php echo $gr['id']; ?>" id="grade_<?php echo $gr['id']; ?>" <?php echo in_array($gr['id'], $filterGrades) ? 'checked' : ''; ?>>
                        <label class="form-check-label" for="grade_<?php echo $gr['id']; ?>"><?php echo htmlspecialchars($gr['grade_name']); ?></label>
                    </div>
                <?php endforeach; ?>
            </div>
        </div>

        <!-- Classes Dropdown -->
        <div class="dropdown d-inline-block">
            <button class="btn btn-light dropdown-toggle btn-sm filter-dropdown-btn" type="button" id="classDropdown" data-bs-toggle="dropdown" data-bs-auto-close="outside" aria-expanded="false" style="background: white; border-color: #dee2e6; color: #495057; height: 31px; display: inline-flex; align-items: center; justify-content: space-between; min-width: 140px;">
                <span>الفصول: <span id="selectedClassesLabel" class="fw-bold">الكل</span></span>
            </button>
            <div class="dropdown-menu p-3" aria-labelledby="classDropdown" style="max-height: 250px; overflow-y: auto; min-width: 220px; text-align: right; box-shadow: 0 10px 15px -3px rgba(0, 0, 0, 0.1);">
                <?php foreach ($classes as $cl): ?>
                    <div class="form-check mb-1 class-item" data-grade="<?php echo $cl['grade_id']; ?>">
                        <input class="form-check-input class-checkbox" type="checkbox" name="class_ids[]" value="<?php echo $cl['id']; ?>" id="class_<?php echo $cl['id']; ?>" <?php echo in_array($cl['id'], $filterClasses) ? 'checked' : ''; ?>>
                        <label class="form-check-label" for="class_<?php echo $cl['id']; ?>"><?php echo htmlspecialchars($cl['name']); ?></label>
                    </div>
                <?php endforeach; ?>
            </div>
        </div>
    </div>
    
    <!-- الأزرار من جهة اليسار -->
    <div class="admin-filter-actions">
        <a href="new_students.php" class="btn btn-light btn-sm"><i class="fas fa-rotate-left me-1"></i>إعادة تعيين</a>
    </div>
</form>

<div class="admin-list-surface">
    <div class="table-responsive admin-table-wrap">
        <table class="table table-hover table-striped align-middle admin-data-table" id="newStudentsTable">
                <thead>
                    <tr>
                        <th>#</th>
                        <th>الكود</th>
                        <th>اسم الطالب</th>
                        <th>المرحلة</th>
                        <th>الصف</th>
                        <th>الفصل</th>
                        <th>تاريخ القيد</th>
                        <th>العام</th>
                        <th class="text-center">إجراءات</th>
                    </tr>
                </thead>
                <tbody><tr><td colspan="9" class="text-center text-muted py-5">جاري تحميل الطلاب…</td></tr></tbody>
        </table>
    </div>
</div>
<script src="../assets/js/admin-server-side-table.js"></script>
<script>
document.addEventListener('DOMContentLoaded', function () {
    var form = document.getElementById('filterForm');
    if (!form) return;
    var table = window.AdminServerSideTable && window.AdminServerSideTable.init({
        selector: '#newStudentsTable', url: 'ajax_derived_students_datatable.php', order: [[6, 'desc']],
        language: { processing: '<i class="fas fa-spinner fa-spin me-2"></i>جاري تحميل الطلاب…', emptyTable: 'لا يوجد طلاب منقولون مطابقون.' },
        requestData: function () {
            return {
                list: 'new',
                stage_ids: Array.from(document.querySelectorAll('.stage-checkbox:checked')).map(function (item) { return item.value; }),
                grade_ids: Array.from(document.querySelectorAll('.grade-checkbox:checked')).map(function (item) { return item.value; }),
                class_ids: Array.from(document.querySelectorAll('.class-checkbox:checked')).map(function (item) { return item.value; })
            };
        }
    });

    // ===== Multiple Selection Filter Cascading =====
    function updateDropdownLabels() {
        // 1. Stages
        var checkedStages = document.querySelectorAll('.stage-checkbox:checked');
        var stageLabel = document.getElementById('selectedStagesLabel');
        var stageBtn = document.getElementById('stageDropdown');
        if (stageLabel) {
            if (checkedStages.length === 0) {
                stageLabel.textContent = 'الكل';
            } else if (checkedStages.length === document.querySelectorAll('.stage-checkbox').length) {
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
            if (checkedGrades.length === 0) {
                gradeLabel.textContent = 'الكل';
            } else if (checkedGrades.length === visibleGradesCount) {
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
            if (checkedClasses.length === 0) {
                classLabel.textContent = 'الكل';
            } else if (checkedClasses.length === visibleClassesCount) {
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
        // Get checked stage IDs
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

            // Check if this class's grade belongs to any visible grades/stages
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

    // Auto-submit form when any dropdown collapses
    document.querySelectorAll('.dropdown').forEach(function(dropdown) {
        dropdown.addEventListener('hide.bs.dropdown', function () {
            // تبقى الفلاتر مرتبطة بإعادة تحميل الصفحة حتى تتحدث بطاقات الملخص
            // من المصدر نفسه، بينما يبقى ترقيم الجدول وبحثه من الخادم.
            form.submit();
        });
    });

    // Initial trigger
    applyCascadingFilters();
});
</script>

<?php require_once '../includes/admin_footer.php'; ?>
