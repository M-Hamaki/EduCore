<?php

declare(strict_types=1);

$page_title = 'أرشيف الطلاب';
$custom_page_title = true;

require_once '../config/database.php';
require_once '../classes/utilities.php';
require_once '../includes/session_config.php';
require_once '../includes/csrf.php';
require_once '../classes/user.php';
require_once '../classes/ProfileAttachmentStorage.php';
require_once '../classes/StudentArchiveService.php';
require_once '../classes/StudentArchiveQuery.php';

Utilities::validateSession('admin');

$db = (new Database())->getConnection();
$archiveService = new StudentArchiveService($db, new ProfileAttachmentStorage());
$archiveQuery = new StudentArchiveQuery($db);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    requireCsrfPost();
    $action = (string) ($_POST['action'] ?? '');
    $studentId = (int) ($_POST['student_id'] ?? 0);

    try {
        if ($action === 'restore') {
            $archiveService->restore($studentId);
            $_SESSION['success_message'] = 'تم استرجاع الطالب من الأرشيف مع إعادة حالته السابقة.';
        } elseif ($action === 'permanent_delete') {
            $actor = new User($db);
            $actor->id = (int) ($_SESSION['user_id'] ?? 0);
            if (!$actor->verifyPassword((string) ($_POST['admin_password'] ?? ''))) {
                throw new RuntimeException('كلمة مرور المدير غير صحيحة.');
            }
            $result = $archiveService->permanentlyDelete(
                $studentId,
                (string) ($_POST['confirmation_code'] ?? '')
            );
            $_SESSION['success_message'] = 'تم حذف سجل الطالب نهائيًا.';
            if (!empty($result['failed_file_ids'])) {
                $_SESSION['error_message'] = 'تم حذف بيانات الطالب، لكن تعذر تنظيف بعض ملفات المرفقات. راجع سجل النظام.';
            }
        } else {
            throw new InvalidArgumentException('الإجراء المطلوب غير معروف.');
        }
    } catch (Throwable $e) {
        if ($e instanceof PDOException) {
            error_log('Student archive mutation failed: ' . $e->getMessage());
        }
        $_SESSION['error_message'] = $e instanceof PDOException
            ? 'تعذر تنفيذ العملية على الأرشيف. لم تُحفظ أي تغييرات جزئية.'
            : $e->getMessage();
    }

    header('Location: student_archive.php');
    exit;
}

$successMessage = $_SESSION['success_message'] ?? null;
$errorMessage = $_SESSION['error_message'] ?? null;
unset($_SESSION['success_message'], $_SESSION['error_message']);

try {
    $archiveData = $archiveQuery->summary($_GET);
} catch (Throwable $e) {
    $archiveData = ['search' => '', 'stats' => ['total' => 0, 'recent' => 0, 'previously_active' => 0]];
    if ($e instanceof PDOException) {
        error_log('Student archive query failed: ' . $e->getMessage());
    }
    $errorMessage = $errorMessage ?: ($e instanceof PDOException
        ? 'تعذر تحميل بيانات الأرشيف مؤقتاً.'
        : $e->getMessage());
}

$stats = $archiveData['stats'];
$search = $archiveData['search'];
$statusLabels = ['active' => 'نشط', 'inactive' => 'غير نشط', 'graduated' => 'خريج'];
$enrollmentLabels = ['enrolled' => 'مقيد', 'graduated' => 'خريج', 'transferred' => 'منقول', 'withdrawn' => 'منسحب'];

// جلب بيانات المراحل والصفوف والفصول للفلاتر (نفس نمط StudentListPageQuery)
$archiveClasses = $db->query(
    "SELECT c.id, c.name, c.grade_id, g.stage_id
     FROM classes c
     LEFT JOIN grades g ON c.grade_id = g.id
     ORDER BY c.name"
)->fetchAll(PDO::FETCH_ASSOC) ?: [];

$archiveScopeGrades = $db->query(
    "SELECT g.id, g.grade_name, g.stage_id, s.stage_name
     FROM grades g
     JOIN stages s ON s.id = g.stage_id
     WHERE g.status = 'active' AND s.status = 'active'
     ORDER BY s.stage_order, g.grade_order, g.id"
)->fetchAll(PDO::FETCH_ASSOC) ?: [];

$archiveStages = [];
$archiveGrades = [];
foreach ($archiveScopeGrades as $grade) {
    $archiveStages[$grade['stage_id']] = $grade['stage_name'];
    $archiveGrades[] = ['id' => $grade['id'], 'grade_name' => $grade['grade_name'], 'stage_id' => $grade['stage_id']];
}

require_once '../includes/admin_header.php';
?>

<div class="students-page">
    <div class="admin-page-heading">
        <div>
            <h1 class="h2"><i class="fas fa-box-archive me-2 text-warning"></i>أرشيف الطلاب</h1>
            <p class="text-muted mb-0">الطلاب المؤرشفون محفوظون تاريخيًا ولا يظهرون في القوائم التشغيلية.</p>
        </div>
    </div>

    <?php if ($successMessage): ?>
        <div class="alert alert-success alert-dismissible fade show" role="alert">
            <i class="fas fa-check-circle me-2"></i><?php echo htmlspecialchars((string) $successMessage, ENT_QUOTES, 'UTF-8'); ?>
            <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="إغلاق"></button>
        </div>
    <?php endif; ?>
    <?php if ($errorMessage): ?>
        <div class="alert alert-danger alert-dismissible fade show" role="alert">
            <i class="fas fa-exclamation-circle me-2"></i><?php echo htmlspecialchars((string) $errorMessage, ENT_QUOTES, 'UTF-8'); ?>
            <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="إغلاق"></button>
        </div>
    <?php endif; ?>

    <div class="row row-cols-1 row-cols-sm-2 row-cols-xl-3 g-3 mb-4">
        <div class="col">
            <div class="stat-card" style="--card-gradient: linear-gradient(135deg,#3b82f6,#2563eb);">
                <div class="stat-card-icon"><i class="fas fa-boxes-stacked"></i></div>
                <div class="stat-card-info">
                    <div class="stat-card-number counter" data-target="<?php echo (int) $stats['total']; ?>">0</div>
                    <div class="stat-card-label">إجمالي المؤرشفين</div>
                    <div class="stat-card-sub"><i class="fas fa-database me-2"></i>البيانات محفوظة</div>
                </div>
            </div>
        </div>
        <div class="col">
            <div class="stat-card" style="--card-gradient: linear-gradient(135deg,#f59e0b,#d97706);">
                <div class="stat-card-icon"><i class="fas fa-clock-rotate-left"></i></div>
                <div class="stat-card-info">
                    <div class="stat-card-number counter" data-target="<?php echo (int) $stats['recent']; ?>">0</div>
                    <div class="stat-card-label">آخر 30 يومًا</div>
                    <div class="stat-card-sub"><i class="fas fa-clock me-2"></i>أرشفة حديثة</div>
                </div>
            </div>
        </div>
        <div class="col">
            <div class="stat-card" style="--card-gradient: linear-gradient(135deg,#10b981,#059669);">
                <div class="stat-card-icon"><i class="fas fa-rotate-left"></i></div>
                <div class="stat-card-info">
                    <div class="stat-card-number counter" data-target="<?php echo (int) $stats['previously_active']; ?>">0</div>
                    <div class="stat-card-label">كانت حساباتهم نشطة</div>
                    <div class="stat-card-sub"><i class="fas fa-user-check me-2"></i>تعود حالتها عند الاسترجاع</div>
                </div>
            </div>
        </div>
    </div>

    <div class="admin-filter-bar" id="archiveFilterBar">
        <div class="admin-filter-controls">
            <!-- فلتر المراحل -->
            <?php if (!empty($archiveStages)): ?>
            <div class="dropdown d-inline-block">
                <button class="btn btn-light dropdown-toggle btn-sm filter-dropdown-btn" type="button" id="archiveStageDropdown"
                    data-bs-toggle="dropdown" data-bs-auto-close="outside" aria-expanded="false"
                    style="background:white;border-color:#dee2e6;color:#495057;height:31px;display:inline-flex;align-items:center;min-width:140px;">
                    <span>المراحل: <span id="selStagesLabel" class="fw-bold">الكل</span></span>
                </button>
                <div class="dropdown-menu p-3" style="max-height:250px;overflow-y:auto;min-width:200px;text-align:right;box-shadow:0 10px 15px -3px rgba(0,0,0,.1);">
                    <?php foreach ($archiveStages as $sid => $sname): ?>
                    <div class="form-check mb-1">
                        <input class="form-check-input archive-stage-cb archive-filter-cb" type="checkbox"
                            name="filter_stage_ids[]" value="<?php echo (int)$sid; ?>" id="arc_stage_<?php echo (int)$sid; ?>">
                        <label class="form-check-label" for="arc_stage_<?php echo (int)$sid; ?>"><?php echo htmlspecialchars($sname, ENT_QUOTES, 'UTF-8'); ?></label>
                    </div>
                    <?php endforeach; ?>
                </div>
            </div>
            <?php endif; ?>

            <!-- فلتر الصفوف -->
            <?php if (!empty($archiveGrades)): ?>
            <div class="dropdown d-inline-block">
                <button class="btn btn-light dropdown-toggle btn-sm filter-dropdown-btn" type="button" id="archiveGradeDropdown"
                    data-bs-toggle="dropdown" data-bs-auto-close="outside" aria-expanded="false"
                    style="background:white;border-color:#dee2e6;color:#495057;height:31px;display:inline-flex;align-items:center;min-width:140px;">
                    <span>الصفوف: <span id="selGradesLabel" class="fw-bold">الكل</span></span>
                </button>
                <div class="dropdown-menu p-3" style="max-height:250px;overflow-y:auto;min-width:220px;text-align:right;box-shadow:0 10px 15px -3px rgba(0,0,0,.1);">
                    <?php foreach ($archiveGrades as $grade): ?>
                    <div class="form-check mb-1 arc-grade-item" data-stage="<?php echo (int)$grade['stage_id']; ?>">
                        <input class="form-check-input archive-grade-cb archive-filter-cb" type="checkbox"
                            name="filter_grade_ids[]" value="<?php echo (int)$grade['id']; ?>" id="arc_grade_<?php echo (int)$grade['id']; ?>">
                        <label class="form-check-label" for="arc_grade_<?php echo (int)$grade['id']; ?>"><?php echo htmlspecialchars($grade['grade_name'], ENT_QUOTES, 'UTF-8'); ?></label>
                    </div>
                    <?php endforeach; ?>
                </div>
            </div>
            <?php endif; ?>

            <!-- فلتر الفصول -->
            <?php if (!empty($archiveClasses)): ?>
            <div class="dropdown d-inline-block">
                <button class="btn btn-light dropdown-toggle btn-sm filter-dropdown-btn" type="button" id="archiveClassDropdown"
                    data-bs-toggle="dropdown" data-bs-auto-close="outside" aria-expanded="false"
                    style="background:white;border-color:#dee2e6;color:#495057;height:31px;display:inline-flex;align-items:center;min-width:140px;">
                    <span>الفصول: <span id="selClassesLabel" class="fw-bold">الكل</span></span>
                </button>
                <div class="dropdown-menu p-3" style="max-height:250px;overflow-y:auto;min-width:220px;text-align:right;box-shadow:0 10px 15px -3px rgba(0,0,0,.1);">
                    <?php foreach ($archiveClasses as $cls): ?>
                    <div class="form-check mb-1 arc-class-item" data-grade="<?php echo (int)$cls['grade_id']; ?>">
                        <input class="form-check-input archive-class-cb archive-filter-cb" type="checkbox"
                            name="filter_class_ids[]" value="<?php echo (int)$cls['id']; ?>" id="arc_class_<?php echo (int)$cls['id']; ?>">
                        <label class="form-check-label" for="arc_class_<?php echo (int)$cls['id']; ?>"><?php echo htmlspecialchars($cls['name'], ENT_QUOTES, 'UTF-8'); ?></label>
                    </div>
                    <?php endforeach; ?>
                </div>
            </div>
            <?php endif; ?>

            <!-- فلتر الحالة الأكاديمية -->
            <div class="dropdown d-inline-block">
                <button class="btn btn-light dropdown-toggle btn-sm filter-dropdown-btn" type="button" id="archiveEnrollmentDropdown"
                    data-bs-toggle="dropdown" data-bs-auto-close="outside" aria-expanded="false"
                    style="background:white;border-color:#dee2e6;color:#495057;height:31px;display:inline-flex;align-items:center;min-width:155px;">
                    <span>الحالة الأكاديمية: <span id="selEnrollmentLabel" class="fw-bold">الكل</span></span>
                </button>
                <div class="dropdown-menu p-3" style="max-height:250px;overflow-y:auto;min-width:180px;text-align:right;box-shadow:0 10px 15px -3px rgba(0,0,0,.1);">
                    <div class="form-check mb-1">
                        <input class="form-check-input archive-enrollment-cb archive-filter-cb" type="checkbox" value="enrolled" id="arc_enroll_enrolled">
                        <label class="form-check-label" for="arc_enroll_enrolled">مقيد</label>
                    </div>
                    <div class="form-check mb-1">
                        <input class="form-check-input archive-enrollment-cb archive-filter-cb" type="checkbox" value="graduated" id="arc_enroll_graduated">
                        <label class="form-check-label" for="arc_enroll_graduated">خريج</label>
                    </div>
                    <div class="form-check mb-1">
                        <input class="form-check-input archive-enrollment-cb archive-filter-cb" type="checkbox" value="transferred" id="arc_enroll_transferred">
                        <label class="form-check-label" for="arc_enroll_transferred">منقول</label>
                    </div>
                    <div class="form-check mb-1">
                        <input class="form-check-input archive-enrollment-cb archive-filter-cb" type="checkbox" value="withdrawn" id="arc_enroll_withdrawn">
                        <label class="form-check-label" for="arc_enroll_withdrawn">منسحب</label>
                    </div>
                </div>
            </div>

            <!-- فلتر فترة الأرشفة -->
            <div class="dropdown d-inline-block">
                <button class="btn btn-light dropdown-toggle btn-sm filter-dropdown-btn" type="button" id="archivePeriodDropdown"
                    data-bs-toggle="dropdown" data-bs-auto-close="outside" aria-expanded="false"
                    style="background:white;border-color:#dee2e6;color:#495057;height:31px;display:inline-flex;align-items:center;min-width:155px;">
                    <span>تاريخ الأرشفة: <span id="selPeriodLabel" class="fw-bold">الكل</span></span>
                </button>
                <div class="dropdown-menu p-3" style="max-height:250px;overflow-y:auto;min-width:180px;text-align:right;box-shadow:0 10px 15px -3px rgba(0,0,0,.1);">
                    <div class="form-check mb-1">
                        <input class="form-check-input archive-period-cb archive-filter-cb" type="checkbox" value="7days" id="arc_period_7days">
                        <label class="form-check-label" for="arc_period_7days">آخر 7 أيام</label>
                    </div>
                    <div class="form-check mb-1">
                        <input class="form-check-input archive-period-cb archive-filter-cb" type="checkbox" value="30days" id="arc_period_30days">
                        <label class="form-check-label" for="arc_period_30days">آخر 30 يوماً</label>
                    </div>
                    <div class="form-check mb-1">
                        <input class="form-check-input archive-period-cb archive-filter-cb" type="checkbox" value="90days" id="arc_period_90days">
                        <label class="form-check-label" for="arc_period_90days">آخر 90 يوماً</label>
                    </div>
                    <div class="form-check mb-1">
                        <input class="form-check-input archive-period-cb archive-filter-cb" type="checkbox" value="thisyear" id="arc_period_thisyear">
                        <label class="form-check-label" for="arc_period_thisyear">هذا العام</label>
                    </div>
                </div>
            </div>
        </div>
        <div class="admin-filter-actions">
            <button type="button" class="btn btn-light btn-sm" id="resetArchiveFilters">
                <i class="fas fa-rotate-left me-1"></i>إعادة تعيين
            </button>
        </div>
    </div>

    <div class="admin-list-surface">
        <div class="table-responsive admin-table-wrap">
            <table class="table table-hover table-striped admin-data-table align-middle" id="studentArchiveTable">
                <thead>
                    <tr>
                        <th>#</th>
                        <th>الكود</th>
                        <th>الطالب</th>
                        <th>آخر قيد</th>
                        <th>الحالة الأكاديمية</th>
                        <th>الأرشفة</th>
                        <th>السبب</th>
                        <th class="text-center">إجراءات</th>
                    </tr>
                </thead>
                <tbody><tr><td colspan="8" class="text-center text-muted py-5">جاري تحميل أرشيف الطلاب…</td></tr></tbody>
            </table>
        </div>
    </div>
</div>

<div class="modal fade" id="restoreStudentModal" tabindex="-1" aria-labelledby="restoreStudentModalLabel" aria-hidden="true">
    <div class="modal-dialog">
        <div class="modal-content admin-modal admin-modal-premium admin-modal-warning">
            <form method="post" data-no-form-safety="true">
                <div class="modal-header">
                    <h5 class="modal-title" id="restoreStudentModalLabel"><i class="fas fa-trash-restore me-2"></i>استرجاع طالب</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="إغلاق"></button>
                </div>
                <div class="modal-body">
                    <div class="text-center mb-3"><i class="fas fa-trash-restore text-success admin-modal-icon-lg"></i></div>
                    <p class="text-center">استرجاع الطالب <span class="fw-bold text-primary" id="restoreStudentName"></span>؟</p>
                    <div class="alert alert-info"><i class="fas fa-info-circle me-2"></i>ستعود حالة الحساب السابقة، ولن يُنشأ قيد تلقائي في عام دراسي جديد.</div>
                </div>
                <div class="modal-footer">
                    <?php echo csrfField(); ?>
                    <input type="hidden" name="action" value="restore">
                    <input type="hidden" name="student_id" id="restoreStudentId">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal"><i class="fas fa-times me-1"></i>إلغاء</button>
                    <button type="submit" class="btn btn-success"><i class="fas fa-trash-restore me-1"></i>استرجاع</button>
                </div>
            </form>
        </div>
    </div>
</div>

<div class="modal fade" id="permanentDeleteStudentModal" tabindex="-1" aria-labelledby="permanentDeleteStudentModalLabel" aria-hidden="true">
    <div class="modal-dialog">
        <div class="modal-content admin-modal admin-modal-premium admin-modal-delete">
            <form method="post" data-no-form-safety="true" autocomplete="off">
                <div class="modal-header">
                    <h5 class="modal-title" id="permanentDeleteStudentModalLabel"><i class="fas fa-trash me-2"></i>حذف نهائي</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="إغلاق"></button>
                </div>
                <div class="modal-body">
                    <div class="text-center mb-3"><i class="fas fa-triangle-exclamation text-danger admin-modal-icon-lg"></i></div>
                    <p class="text-center">سيُحذف الطالب <span class="fw-bold text-danger" id="permanentDeleteStudentName"></span> نهائيًا.</p>
                    <div class="alert alert-danger"><i class="fas fa-shield-halved me-2"></i>العملية غير قابلة للتراجع، وستُرفض تلقائيًا إذا وجدت درجات أو حضور أو معاملات مالية أو سجلات رسمية.</div>
                    <div class="mb-3">
                        <label for="permanentDeleteCode" class="form-label">اكتب كود الطالب <code id="permanentDeleteExpectedCode"></code></label>
                        <input type="text" class="form-control" name="confirmation_code" id="permanentDeleteCode" required autocomplete="off">
                    </div>
                    <div>
                        <label for="permanentDeletePassword" class="form-label">كلمة مرور المدير</label>
                        <input type="password" class="form-control" name="admin_password" id="permanentDeletePassword" required autocomplete="current-password">
                    </div>
                </div>
                <div class="modal-footer">
                    <?php echo csrfField(); ?>
                    <input type="hidden" name="action" value="permanent_delete">
                    <input type="hidden" name="student_id" id="permanentDeleteStudentId">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal"><i class="fas fa-times me-1"></i>إلغاء</button>
                    <button type="submit" class="btn btn-danger"><i class="fas fa-trash me-1"></i>حذف نهائي</button>
                </div>
            </form>
        </div>
    </div>
</div>

<script src="../assets/js/admin-server-side-table.js"></script>
<script>
document.addEventListener('DOMContentLoaded', function () {
    var dtInstance = null;

    // جمع قيم الـ checkboxes كمصفوفة
    function getCheckedValues(selector) {
        return Array.from(document.querySelectorAll(selector + ':checked')).map(function(el){ return el.value; });
    }

    function getFilterValues() {
        var result = {};
        var stageIds      = getCheckedValues('.archive-stage-cb');
        var gradeIds      = getCheckedValues('.archive-grade-cb');
        var classIds      = getCheckedValues('.archive-class-cb');
        var enrollments   = getCheckedValues('.archive-enrollment-cb');
        var periods       = getCheckedValues('.archive-period-cb');
        if (stageIds.length)    result['filter_stage_ids[]']      = stageIds;
        if (gradeIds.length)    result['filter_grade_ids[]']      = gradeIds;
        if (classIds.length)    result['filter_class_ids[]']      = classIds;
        if (enrollments.length) result['filter_enrollment_status[]'] = enrollments;
        if (periods.length)     result['filter_archive_period[]'] = periods;
        return result;
    }

    function reloadTable() {
        if (dtInstance) dtInstance.ajax.reload(null, false);
    }

    // تحديث نص العنوان لكل Dropdown
    function updateDropdownLabel(labelId, cbSelector, allLabel) {
        var checked = document.querySelectorAll(cbSelector + ':checked');
        var label = document.getElementById(labelId);
        if (!label) return;
        label.textContent = checked.length ? checked.length + ' محدد' : allLabel;

        var btn = label.closest('.filter-dropdown-btn');
        if (btn) {
            btn.classList.toggle('active-filter', checked.length > 0);
        }
    }

    // فلترة عناصر الصفوف بناءً على المراحل المحددة
    function cascadeGradesByStages() {
        var selectedStages = getCheckedValues('.archive-stage-cb');
        document.querySelectorAll('.arc-grade-item').forEach(function(item) {
            var stageId = item.getAttribute('data-stage');
            var visible = !selectedStages.length || selectedStages.includes(stageId);
            item.style.display = visible ? '' : 'none';
            if (!visible) item.querySelector('input').checked = false;
        });
        updateDropdownLabel('selGradesLabel', '.archive-grade-cb', 'الكل');
    }

    // فلترة عناصر الفصول بناءً على الصفوف المحددة
    function cascadeClassesByGrades() {
        var selectedGrades = getCheckedValues('.archive-grade-cb');
        document.querySelectorAll('.arc-class-item').forEach(function(item) {
            var gradeId = item.getAttribute('data-grade');
            var visible = !selectedGrades.length || selectedGrades.includes(gradeId);
            item.style.display = visible ? '' : 'none';
            if (!visible) item.querySelector('input').checked = false;
        });
        updateDropdownLabel('selClassesLabel', '.archive-class-cb', 'الكل');
    }

    // أحداث الـ checkboxes — مراحل
    document.querySelectorAll('.archive-stage-cb').forEach(function(cb) {
        cb.addEventListener('change', function() {
            updateDropdownLabel('selStagesLabel', '.archive-stage-cb', 'الكل');
            cascadeGradesByStages();
            cascadeClassesByGrades();
            reloadTable();
        });
    });

    // أحداث الـ checkboxes — صفوف
    document.querySelectorAll('.archive-grade-cb').forEach(function(cb) {
        cb.addEventListener('change', function() {
            updateDropdownLabel('selGradesLabel', '.archive-grade-cb', 'الكل');
            cascadeClassesByGrades();
            reloadTable();
        });
    });

    // أحداث الـ checkboxes — فصول
    document.querySelectorAll('.archive-class-cb').forEach(function(cb) {
        cb.addEventListener('change', function() {
            updateDropdownLabel('selClassesLabel', '.archive-class-cb', 'الكل');
            reloadTable();
        });
    });

    // أحداث الـ checkboxes — الحالة الأكاديمية
    document.querySelectorAll('.archive-enrollment-cb').forEach(function(cb) {
        cb.addEventListener('change', function() {
            updateDropdownLabel('selEnrollmentLabel', '.archive-enrollment-cb', 'الكل');
            reloadTable();
        });
    });

    // أحداث الـ checkboxes — فترة الأرشفة
    document.querySelectorAll('.archive-period-cb').forEach(function(cb) {
        cb.addEventListener('change', function() {
            updateDropdownLabel('selPeriodLabel', '.archive-period-cb', 'الكل');
            reloadTable();
        });
    });

    if (window.AdminServerSideTable) {
        dtInstance = window.AdminServerSideTable.init({
            selector: '#studentArchiveTable',
            url: 'ajax_student_archive_datatable.php',
            order: [[5, 'desc']],
            language: {
                processing: '<i class="fas fa-spinner fa-spin me-2"></i>جاري تحميل أرشيف الطلاب…',
                emptyTable: 'لا يوجد طلاب مؤرشفون مطابقون للبحث.'
            },
            requestData: getFilterValues,
            decorateRow: function (row) {
                row.lastElementChild.classList.add('actions-column', 'admin-table-actions');
            },
            onDraw: function(dt) {
                var searchInput = document.querySelector('#studentArchiveTable_filter input');
                if (searchInput && !searchInput.placeholder) {
                    searchInput.placeholder = 'الاسم أو الكود';
                }
            }
        });
    }

    // إعادة تعيين جميع الفلاتر
    document.getElementById('resetArchiveFilters').addEventListener('click', function() {
        document.querySelectorAll('.archive-filter-cb').forEach(function(cb) { cb.checked = false; });
        ['selStagesLabel','selGradesLabel','selClassesLabel','selEnrollmentLabel','selPeriodLabel'].forEach(function(id) {
            var el = document.getElementById(id); if (el) el.textContent = 'الكل';
        });
        document.querySelectorAll('.filter-dropdown-btn').forEach(function(btn) {
            btn.classList.remove('active-filter');
        });
        document.querySelectorAll('.arc-grade-item, .arc-class-item').forEach(function(i){ i.style.display=''; });
        if (dtInstance) {
            dtInstance.search('').draw();
        }
    });

    document.querySelectorAll('[data-bs-toggle="tooltip"]').forEach(function (element) {
        new bootstrap.Tooltip(element);
    });

    document.addEventListener('click', function (event) {
        const restoreButton = event.target.closest('.restore-student');
        if (restoreButton) {
            document.getElementById('restoreStudentId').value = restoreButton.dataset.id;
            document.getElementById('restoreStudentName').textContent = restoreButton.dataset.name;
            new bootstrap.Modal(document.getElementById('restoreStudentModal')).show();
            return;
        }

        const deleteButton = event.target.closest('.permanent-delete-student');
        if (deleteButton && !deleteButton.disabled) {
            document.getElementById('permanentDeleteStudentId').value = deleteButton.dataset.id;
            document.getElementById('permanentDeleteStudentName').textContent = deleteButton.dataset.name;
            document.getElementById('permanentDeleteExpectedCode').textContent = deleteButton.dataset.code;
            document.getElementById('permanentDeleteCode').value = '';
            document.getElementById('permanentDeletePassword').value = '';
            new bootstrap.Modal(document.getElementById('permanentDeleteStudentModal')).show();
        }
    });
});
</script>

<?php require_once '../includes/admin_footer.php'; ?>
