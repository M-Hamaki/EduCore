<?php
/**
 * إدارة الأعوام الدراسية
 */
require_once '../config/database.php';
require_once '../classes/utilities.php';
require_once '../includes/session_config.php';
require_once '../classes/AcademicYear.php';
require_once '../classes/ActivityLog.php';
require_once '../classes/user.php';

Utilities::validateSession('admin');

$database = new Database();
$db = $database->getConnection();

// --- استخراج رسائل الجلسة ---
$success_message = $_SESSION['settings_success'] ?? null;
$error_message = $_SESSION['settings_error'] ?? null;
unset($_SESSION['settings_success'], $_SESSION['settings_error']);

// ==========================================
// معالجة الإجراءات (POST)
// ==========================================
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // التحقق من CSRF token
    $csrfToken = (string) ($_POST['csrf_token'] ?? '');
    $sessionCsrfToken = (string) ($_SESSION['csrf_token'] ?? '');
    if (
        $csrfToken === ''
        || $sessionCsrfToken === ''
        || !hash_equals($sessionCsrfToken, $csrfToken)
    ) {
        $_SESSION['settings_error'] = "خطأ في التحقق من الأمان. يرجى إعادة المحاولة.";
        header("Location: academic_years.php");
        exit();
    }

    // ---- إضافة عام دراسي ----
    if (isset($_POST['add_academic_year'])) {
        try {
            $yearName = trim((string)($_POST['year_name'] ?? ''));
            $startDate = trim((string)($_POST['start_date'] ?? ''));
            $endDate = trim((string)($_POST['end_date'] ?? ''));
            $notes = trim((string)($_POST['year_notes'] ?? ''));
            $newYearId = AcademicYear::create($db, $yearName, $startDate ?: null, $endDate ?: null, $notes ?: null);
            $_SESSION['settings_success'] = "تم إضافة العام الدراسي بنجاح. العام الجديد فارغ الآن؛ استخدم صفحة تهيئة عام جديد لترحيل الفصول والطلاب عند الحاجة.";
            ActivityLog::logCreate('academic_year', $newYearId, $yearName, [
                'academic_year' => $yearName,
                'start_date' => $startDate,
                'end_date' => $endDate,
                'notes' => $notes
            ]);
            header("Location: academic_years.php");
            exit();
        } catch (Exception $e) {
            $_SESSION['settings_error'] = "خطأ في إضافة العام الدراسي: " . $e->getMessage();
            header("Location: academic_years.php");
            exit();
        }
    }

    // ---- تعديل عام دراسي ----
    elseif (isset($_POST['edit_academic_year'])) {
        try {
            $yearId = (int)($_POST['academic_year_id'] ?? 0);
            $newName = trim((string)($_POST['year_name'] ?? ''));
            $startDate = trim((string)($_POST['start_date'] ?? ''));
            $endDate = trim((string)($_POST['end_date'] ?? ''));
            $notes = trim((string)($_POST['year_notes'] ?? ''));

            // التحقق من إعادة كتابة الاسم للتأكيد
            $confirmName = trim((string)($_POST['confirm_name'] ?? ''));
            if (!hash_equals($newName, $confirmName)) {
                throw new InvalidArgumentException('يجب إعادة كتابة اسم العام الدراسي الجديد بدقة لتأكيد العملية.');
            }

            $old = AcademicYear::update($db, $yearId, $newName, $startDate ?: null, $endDate ?: null, $notes ?: null);
            $studentCount = AcademicYear::countEnrollments($db, $yearId);

            $_SESSION['settings_success'] = "تم تعديل بيانات العام الدراسي بنجاح.";
            ActivityLog::logUpdate('academic_year', $yearId, $old['name'] ?? $newName, [
                'old_academic_year' => $old['name'] ?? '',
                'new_academic_year' => $newName,
                'start_date' => $startDate,
                'end_date' => $endDate,
                'notes' => $notes,
                'student_count' => $studentCount
            ]);
            header("Location: academic_years.php");
            exit();
        } catch (Exception $e) {
            $_SESSION['settings_error'] = "خطأ في تعديل العام الدراسي: " . $e->getMessage();
            header("Location: academic_years.php");
            exit();
        }
    }

    // ---- تفعيل عام دراسي ----
    elseif (isset($_POST['activate_academic_year'])) {
        try {
            $yearId = (int)($_POST['academic_year_id'] ?? 0);
            AcademicYear::setActive($db, $yearId);
            $year = AcademicYear::getActive($db);
            $_SESSION['settings_success'] = "تم تعيين العام الدراسي النشط بنجاح.";
            ActivityLog::logUpdate('academic_year', $yearId, $year['name'] ?? '', [
                'academic_year' => $year['name'] ?? '',
                'is_active' => 1
            ]);
            header("Location: academic_years.php");
            exit();
        } catch (Exception $e) {
            $_SESSION['settings_error'] = "خطأ في تفعيل العام الدراسي: " . $e->getMessage();
            header("Location: academic_years.php");
            exit();
        }
    }

    // ---- قفل عام دراسي ----
    elseif (isset($_POST['lock_academic_year'])) {
        try {
            $yearId = (int)($_POST['academic_year_id'] ?? 0);
            AcademicYear::lock($db, $yearId);
            $year = AcademicYear::findById($db, $yearId);
            $_SESSION['settings_success'] = "تم قفل العام الدراسي '" . ($year['name'] ?? '') . "'. لا يمكن تعديل بياناته التاريخية.";
            ActivityLog::logUpdate('academic_year', $yearId, $year['name'] ?? '', [
                'academic_year' => $year['name'] ?? '',
                'notes' => 'تم قفل العام'
            ]);
            header("Location: academic_years.php");
            exit();
        } catch (Exception $e) {
            $_SESSION['settings_error'] = "خطأ في القفل: " . $e->getMessage();
            header("Location: academic_years.php");
            exit();
        }
    }

    // ---- فتح قفل عام دراسي ----
    elseif (isset($_POST['unlock_academic_year'])) {
        try {
            $yearId = (int)($_POST['academic_year_id'] ?? 0);
            AcademicYear::unlock($db, $yearId);
            $year = AcademicYear::findById($db, $yearId);
            $_SESSION['settings_success'] = "تم فتح قفل العام الدراسي '" . ($year['name'] ?? '') . "'.";
            ActivityLog::logUpdate('academic_year', $yearId, $year['name'] ?? '', [
                'academic_year' => $year['name'] ?? '',
                'notes' => 'تم فتح القفل'
            ]);
            header("Location: academic_years.php");
            exit();
        } catch (Exception $e) {
            $_SESSION['settings_error'] = "خطأ في فتح القفل: " . $e->getMessage();
            header("Location: academic_years.php");
            exit();
        }
    }

    // ---- حذف عام دراسي فارغ وغير نشط ----
    elseif (isset($_POST['delete_academic_year'])) {
        try {
            $yearId = (int)($_POST['academic_year_id'] ?? 0);
            $confirmName = trim((string)($_POST['confirm_name'] ?? ''));
            $year = AcademicYear::findById($db, $yearId);
            if (!$year) {
                throw new InvalidArgumentException('العام الدراسي المطلوب غير موجود.');
            }
            if (!hash_equals((string)$year['name'], $confirmName)) {
                throw new InvalidArgumentException('يجب كتابة اسم العام الدراسي بدقة لتأكيد الحذف.');
            }

            $deletedYear = AcademicYear::delete($db, $yearId);
            $_SESSION['settings_success'] = "تم حذف العام الدراسي '" . ($deletedYear['name'] ?? '') . "' بنجاح.";
            header("Location: academic_years.php");
            exit();
        } catch (Throwable $e) {
            if ($e instanceof InvalidArgumentException) {
                $_SESSION['settings_error'] = "تعذر حذف العام الدراسي: " . $e->getMessage();
            } else {
                $errorReference = SafeErrorPolicy::report($e, 'academic_year.delete');
                $_SESSION['settings_error'] = "تعذر حذف العام الدراسي بسبب خطأ غير متوقع. رقم المرجع: " . $errorReference;
            }
            header("Location: academic_years.php");
            exit();
        }
    }
}

// ==========================================
// جلب البيانات
// ==========================================
$academicYears = [];
$academicYearStudentCounts = [];
$academicYearDeletionAssessments = [];
try {
    $academicYears = AcademicYear::getAll($db);
    $academicYearIds = array_map(static fn(array $year): int => (int) $year['id'], $academicYears);
    $academicYearStudentCounts = AcademicYear::countEnrollmentsByYear($db, $academicYearIds);
    $academicYearDeletionAssessments = AcademicYear::getDeletionAssessments($db, $academicYears);
} catch (Exception $e) {
    $academicYears = [];
}

$yearSearch = trim((string)($_GET['year_search'] ?? ''));
$yearStatus = (string)($_GET['year_status'] ?? '');
if (!in_array($yearStatus, ['', 'active', 'available', 'locked'], true)) {
    $yearStatus = '';
}

$filteredAcademicYears = array_values(array_filter(
    $academicYears,
    static function (array $year) use ($yearSearch, $yearStatus): bool {
        if (
            $yearSearch !== ''
            && mb_stripos((string)($year['name'] ?? ''), $yearSearch) === false
            && mb_stripos((string)($year['notes'] ?? ''), $yearSearch) === false
        ) {
            return false;
        }
        if ($yearStatus === 'active') {
            return (int)($year['is_active'] ?? 0) === 1;
        }
        if ($yearStatus === 'locked') {
            return (int)($year['locked'] ?? 0) === 1;
        }
        if ($yearStatus === 'available') {
            return (int)($year['is_active'] ?? 0) !== 1 && (int)($year['locked'] ?? 0) !== 1;
        }
        return true;
    }
));

$academicYearCount = count($academicYears);
$activeAcademicYearCount = count(array_filter(
    $academicYears,
    static fn(array $year): bool => (int)($year['is_active'] ?? 0) === 1
));
$lockedAcademicYearCount = count(array_filter(
    $academicYears,
    static fn(array $year): bool => (int)($year['locked'] ?? 0) === 1
));
$deletableAcademicYearCount = count(array_filter(
    $academicYearDeletionAssessments,
    static fn(array $assessment): bool => !empty($assessment['can_delete'])
));

$page_title = 'الأعوام الدراسية';
$custom_page_title = true;
require_once '../includes/admin_header.php';
?>

<div class="academic-years-page">
    <div class="admin-page-heading">
        <div>
            <h1 class="h2"><i class="fas fa-calendar-alt me-2 text-primary"></i>الأعوام الدراسية</h1>
            <p class="text-muted mb-0">إدارة الأعوام وتفعيل العام الحالي وحماية السنوات التي تحتوي على سجل دراسي.</p>
        </div>
        <div class="admin-top-actions no-print">
            <button type="button"
                    class="btn btn-header-premium btn-success shadow-sm"
                    data-bs-toggle="modal"
                    data-bs-target="#addAcademicYearModal">
                <i class="fas fa-plus-circle"></i>إضافة عام دراسي
            </button>
            <a href="academic_year_setup.php" class="btn btn-header-premium btn-print-soft">
                <i class="fas fa-calendar-plus me-1"></i>تهيئة عام جديد
            </a>
        </div>
    </div>

    <?php if ($success_message): ?>
        <div class="alert alert-success alert-dismissible fade show" role="alert">
            <i class="fas fa-check-circle me-2"></i><?php echo htmlspecialchars($success_message, ENT_QUOTES, 'UTF-8'); ?>
            <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="إغلاق"></button>
        </div>
    <?php endif; ?>
    <?php if ($error_message): ?>
        <div class="alert alert-danger alert-dismissible fade show" role="alert">
            <i class="fas fa-exclamation-triangle me-2"></i><?php echo htmlspecialchars($error_message, ENT_QUOTES, 'UTF-8'); ?>
            <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="إغلاق"></button>
        </div>
    <?php endif; ?>

    <div class="row row-cols-1 row-cols-sm-2 row-cols-xl-4 g-3 mb-4" aria-label="إحصائيات الأعوام الدراسية">
        <div class="col">
            <div class="stat-card" style="--card-gradient: linear-gradient(135deg,#3b82f6,#2563eb);">
                <div class="stat-card-icon"><i class="fas fa-calendar-days"></i></div>
                <div class="stat-card-info">
                    <div class="stat-card-number counter" data-target="<?php echo $academicYearCount; ?>">0</div>
                    <div class="stat-card-label">إجمالي الأعوام</div>
                    <div class="stat-card-sub"><i class="fas fa-layer-group"></i> جميع الأعوام المسجلة</div>
                </div>
            </div>
        </div>
        <div class="col">
            <div class="stat-card" style="--card-gradient: linear-gradient(135deg,#10b981,#059669);">
                <div class="stat-card-icon"><i class="fas fa-circle-check"></i></div>
                <div class="stat-card-info">
                    <div class="stat-card-number counter" data-target="<?php echo $activeAcademicYearCount; ?>">0</div>
                    <div class="stat-card-label">العام النشط</div>
                    <div class="stat-card-sub"><i class="fas fa-circle-check"></i> عام واحد معتمد للتشغيل</div>
                </div>
            </div>
        </div>
        <div class="col">
            <div class="stat-card" style="--card-gradient: linear-gradient(135deg,#f59e0b,#d97706);">
                <div class="stat-card-icon"><i class="fas fa-lock"></i></div>
                <div class="stat-card-info">
                    <div class="stat-card-number counter" data-target="<?php echo $lockedAcademicYearCount; ?>">0</div>
                    <div class="stat-card-label">أعوام مقفلة</div>
                    <div class="stat-card-sub"><i class="fas fa-box-archive"></i> محفوظة كسجل تاريخي</div>
                </div>
            </div>
        </div>
        <div class="col">
            <div class="stat-card" style="--card-gradient: linear-gradient(135deg,#8b5cf6,#7c3aed);">
                <div class="stat-card-icon"><i class="fas fa-trash-can"></i></div>
                <div class="stat-card-info">
                    <div class="stat-card-number counter" data-target="<?php echo $deletableAcademicYearCount; ?>">0</div>
                    <div class="stat-card-label">قابلة للحذف</div>
                    <div class="stat-card-sub"><i class="fas fa-shield-halved"></i> فارغة وغير نشطة</div>
                </div>
            </div>
        </div>
    </div>

    <form method="GET" class="admin-filter-bar" aria-label="فلترة الأعوام الدراسية">
        <div class="admin-filter-controls">
            <input type="search"
                   class="form-control form-control-sm"
                   name="year_search"
                   value="<?php echo htmlspecialchars($yearSearch, ENT_QUOTES, 'UTF-8'); ?>"
                   placeholder="بحث بالعام أو الملاحظات"
                   aria-label="بحث في الأعوام الدراسية">
            <select class="form-select form-select-sm admin-inline-select-sm"
                    name="year_status"
                    aria-label="فلترة حالة العام">
                <option value="" <?php echo $yearStatus === '' ? 'selected' : ''; ?>>كل الحالات</option>
                <option value="active" <?php echo $yearStatus === 'active' ? 'selected' : ''; ?>>نشط</option>
                <option value="available" <?php echo $yearStatus === 'available' ? 'selected' : ''; ?>>متاح</option>
                <option value="locked" <?php echo $yearStatus === 'locked' ? 'selected' : ''; ?>>مقفل</option>
            </select>
        </div>
        <div class="admin-filter-actions">
            <a href="academic_years.php" class="btn btn-light btn-sm">
                <i class="fas fa-rotate-left me-1"></i>إعادة تعيين
            </a>
            <button type="submit" class="btn btn-light btn-sm">
                <i class="fas fa-search me-1"></i>بحث
            </button>
        </div>
    </form>

    <div class="admin-list-surface">
        <div class="table-responsive admin-table-wrap">
            <table class="table table-hover table-striped admin-data-table" id="academicYearsTable">
                <thead>
                    <tr>
                        <th>#</th>
                        <th>العام</th>
                        <th>الفترة</th>
                        <th class="text-center">قيود الطلاب</th>
                        <th>الحالة</th>
                        <th>ملاحظات</th>
                        <th class="text-center">إجراءات</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($filteredAcademicYears as $year):
                        $yearId = (int)$year['id'];
                        $yearStudentCount = $academicYearStudentCounts[$yearId] ?? 0;
                        $yearNotes = trim((string)($year['notes'] ?? ''));
                        $deleteAssessment = $academicYearDeletionAssessments[$yearId] ?? [
                            'can_delete' => false,
                            'reason' => 'تعذر التحقق من إمكانية حذف العام.',
                        ];
                    ?>
                        <tr>
                            <td><?php echo $yearId; ?></td>
                            <td class="fw-bold text-primary" dir="ltr"><?php echo htmlspecialchars($year['name'], ENT_QUOTES, 'UTF-8'); ?></td>
                            <td dir="ltr"><?php echo htmlspecialchars(($year['start_date'] ?: '-') . ' / ' . ($year['end_date'] ?: '-'), ENT_QUOTES, 'UTF-8'); ?></td>
                            <td class="text-center">
                                <span class="badge <?php echo $yearStudentCount > 0 ? 'bg-info' : 'bg-light text-dark'; ?>"><?php echo number_format($yearStudentCount); ?></span>
                            </td>
                            <td>
                                <?php if ((int)$year['is_active'] === 1): ?>
                                    <span class="badge bg-success"><i class="fas fa-circle-check me-1"></i>نشط</span>
                                <?php elseif ((int)($year['locked'] ?? 0) === 1): ?>
                                    <span class="badge bg-dark"><i class="fas fa-lock me-1"></i>مقفل</span>
                                <?php else: ?>
                                    <span class="badge bg-secondary">متاح</span>
                                <?php endif; ?>
                            </td>
                            <td>
                                <?php if ($yearNotes !== ''): ?>
                                    <span data-bs-toggle="tooltip" title="<?php echo htmlspecialchars($yearNotes, ENT_QUOTES, 'UTF-8'); ?>">
                                        <i class="fas fa-sticky-note text-warning me-1"></i>
                                        <small class="text-muted"><?php echo htmlspecialchars(mb_substr($yearNotes, 0, 35) . (mb_strlen($yearNotes) > 35 ? '…' : ''), ENT_QUOTES, 'UTF-8'); ?></small>
                                    </span>
                                <?php else: ?>
                                    <span class="text-muted">—</span>
                                <?php endif; ?>
                            </td>
                            <td class="text-center actions-column admin-table-actions">
                                <button type="button"
                                        class="btn btn-action-pills btn-edit me-1"
                                        data-bs-toggle="modal"
                                        data-bs-target="#editAcademicYearModal"
                                        data-year-id="<?php echo $yearId; ?>"
                                        data-year-name="<?php echo htmlspecialchars($year['name'], ENT_QUOTES, 'UTF-8'); ?>"
                                        data-year-start="<?php echo htmlspecialchars($year['start_date'] ?? '', ENT_QUOTES, 'UTF-8'); ?>"
                                        data-year-end="<?php echo htmlspecialchars($year['end_date'] ?? '', ENT_QUOTES, 'UTF-8'); ?>"
                                        data-year-notes="<?php echo htmlspecialchars($yearNotes, ENT_QUOTES, 'UTF-8'); ?>"
                                        data-year-students="<?php echo (int)$yearStudentCount; ?>"
                                        title="تعديل بيانات العام"
                                        aria-label="تعديل بيانات العام">
                                    <i class="fas fa-edit"></i>
                                </button>

                                <?php if ((int)$year['is_active'] !== 1): ?>
                                    <form method="POST" class="d-inline">
                                        <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($_SESSION['csrf_token'], ENT_QUOTES, 'UTF-8'); ?>">
                                        <input type="hidden" name="academic_year_id" value="<?php echo $yearId; ?>">
                                        <button type="submit"
                                                name="activate_academic_year"
                                                class="btn btn-action-pills btn-activate me-1"
                                                data-bs-toggle="tooltip"
                                                title="تعيين كعام نشط"
                                                aria-label="تعيين كعام نشط">
                                            <i class="fas fa-check"></i>
                                        </button>
                                    </form>
                                <?php else: ?>
                                    <button type="button"
                                            class="btn btn-action-pills btn-activate me-1"
                                            disabled
                                            title="العام النشط"
                                            aria-label="العام النشط">
                                        <i class="fas fa-check"></i>
                                    </button>
                                <?php endif; ?>

                                <?php if ((int)($year['locked'] ?? 0) === 1): ?>
                                    <form method="POST" class="d-inline">
                                        <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($_SESSION['csrf_token'], ENT_QUOTES, 'UTF-8'); ?>">
                                        <input type="hidden" name="academic_year_id" value="<?php echo $yearId; ?>">
                                        <button type="submit"
                                                name="unlock_academic_year"
                                                class="btn btn-action-pills btn-activate me-1"
                                                data-bs-toggle="tooltip"
                                                title="فتح قفل العام"
                                                aria-label="فتح قفل العام">
                                            <i class="fas fa-lock-open"></i>
                                        </button>
                                    </form>
                                <?php elseif ((int)$year['is_active'] !== 1): ?>
                                    <form method="POST" class="d-inline">
                                        <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($_SESSION['csrf_token'], ENT_QUOTES, 'UTF-8'); ?>">
                                        <input type="hidden" name="academic_year_id" value="<?php echo $yearId; ?>">
                                        <button type="submit"
                                                name="lock_academic_year"
                                                class="btn btn-action-pills btn-deactivate me-1"
                                                data-bs-toggle="tooltip"
                                                title="قفل العام"
                                                aria-label="قفل العام">
                                            <i class="fas fa-lock"></i>
                                        </button>
                                    </form>
                                <?php endif; ?>

                                <?php if (!empty($deleteAssessment['can_delete'])): ?>
                                    <button type="button"
                                            class="btn btn-action-pills btn-delete"
                                            data-bs-toggle="modal"
                                            data-bs-target="#deleteAcademicYearModal"
                                            data-year-id="<?php echo $yearId; ?>"
                                            data-year-name="<?php echo htmlspecialchars($year['name'], ENT_QUOTES, 'UTF-8'); ?>"
                                            title="حذف العام"
                                            aria-label="حذف العام">
                                        <i class="fas fa-trash"></i>
                                    </button>
                                <?php else: ?>
                                    <span class="d-inline-block"
                                          data-bs-toggle="tooltip"
                                          title="<?php echo htmlspecialchars((string)$deleteAssessment['reason'], ENT_QUOTES, 'UTF-8'); ?>">
                                        <button type="button"
                                                class="btn btn-action-pills btn-delete"
                                                disabled
                                                aria-label="حذف العام غير متاح">
                                            <i class="fas fa-trash"></i>
                                        </button>
                                    </span>
                                <?php endif; ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                    <?php if (empty($filteredAcademicYears)): ?>
                        <tr>
                            <td colspan="7" class="text-center text-muted py-4">
                                <i class="fas fa-calendar-xmark me-2"></i>لا توجد أعوام مطابقة لمعايير البحث.
                            </td>
                        </tr>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>

    <div class="modal fade" id="addAcademicYearModal" tabindex="-1" aria-labelledby="addAcademicYearTitle" aria-hidden="true">
        <div class="modal-dialog modal-lg">
            <div class="modal-content admin-modal admin-modal-premium admin-modal-create">
                <form method="POST">
                    <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($_SESSION['csrf_token'], ENT_QUOTES, 'UTF-8'); ?>">
                    <div class="modal-header">
                        <h5 class="modal-title" id="addAcademicYearTitle"><i class="fas fa-calendar-plus me-2"></i>إضافة عام دراسي</h5>
                        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="إغلاق"></button>
                    </div>
                    <div class="modal-body">
                        <div class="mb-3">
                            <label class="form-label fw-bold">اسم العام الدراسي <span class="text-danger">*</span></label>
                            <input type="text" class="form-control" name="year_name" placeholder="2026-2027" dir="ltr" required maxlength="20">
                            <div class="form-text">يُضاف العام فارغاً، ثم تُستخدم صفحة تهيئة عام جديد عند الحاجة.</div>
                        </div>
                        <div class="row g-3">
                            <div class="col-md-6">
                                <label class="form-label fw-bold">تاريخ البداية</label>
                                <input type="text" class="form-control flatpickr-date" name="start_date" placeholder="اختر التاريخ...">
                            </div>
                            <div class="col-md-6">
                                <label class="form-label fw-bold">تاريخ النهاية</label>
                                <input type="text" class="form-control flatpickr-date" name="end_date" placeholder="اختر التاريخ...">
                            </div>
                        </div>
                        <div class="mt-3">
                            <label class="form-label fw-bold">ملاحظات <small class="text-muted fw-normal">(اختياري)</small></label>
                            <textarea class="form-control" name="year_notes" rows="3" maxlength="500" placeholder="ملاحظة توضيحية عن العام الدراسي"></textarea>
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">
                            <i class="fas fa-times me-1"></i>إلغاء
                        </button>
                        <button type="submit" name="add_academic_year" class="btn btn-success">
                            <i class="fas fa-save me-1"></i>إضافة العام
                        </button>
                    </div>
                </form>
            </div>
        </div>
    </div>

<!-- مودال تعديل العام الدراسي (مع تأكيد) -->
<div class="modal fade" id="editAcademicYearModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-lg">
        <div class="modal-content admin-modal admin-modal-premium admin-modal-edit">
            <form id="editAcademicYearForm" method="POST">
                <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($_SESSION['csrf_token'], ENT_QUOTES, 'UTF-8'); ?>">
                <input type="hidden" name="academic_year_id" id="editYearId">

                <div class="modal-header">
                    <h5 class="modal-title"><i class="fas fa-edit me-2"></i>تعديل العام الدراسي</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="إغلاق"></button>
                </div>
                <div class="modal-body">
                    <div class="alert alert-warning border-0 d-flex align-items-start">
                        <i class="fas fa-exclamation-triangle fa-2x me-3 mt-1"></i>
                        <div>
                            <strong>تنبيه: أنت على وشك تعديل عام دراسي.</strong><br>
                            تغيير اسم العام الدراسي قد يؤثر على السجلات المرتبطة به.
                            تأكد من مراجعة التفاصيل قبل الحفظ — سيتم تسجيل التغيير في سجل العمليات.
                        </div>
                    </div>
                    <div class="row g-3 mb-3">
                        <div class="col-md-6">
                            <label class="form-label fw-bold text-muted">العام الحالي</label>
                            <div class="form-control bg-light" dir="ltr" id="editCurrentNameDisplay">—</div>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label fw-bold text-muted">عدد الطلاب المرتبطين</label>
                            <div class="form-control bg-light fw-bold" id="editStudentCountDisplay">0</div>
                        </div>
                    </div>
                    <div class="mb-3">
                        <label class="form-label fw-bold">اسم العام الدراسي الجديد</label>
                        <input type="text" class="form-control" name="year_name" id="editYearName" dir="ltr" required maxlength="20">
                    </div>
                    <div class="row g-3">
                        <div class="col-md-6">
                            <label class="form-label fw-bold">تاريخ البداية</label>
                            <input type="text" class="form-control flatpickr-date" name="start_date" id="editStartDate" placeholder="اختر التاريخ...">
                        </div>
                        <div class="col-md-6">
                            <label class="form-label fw-bold">تاريخ النهاية</label>
                            <input type="text" class="form-control flatpickr-date" name="end_date" id="editEndDate" placeholder="اختر التاريخ...">
                        </div>
                    </div>
                    <div class="mt-3">
                        <label class="form-label fw-bold">ملاحظات <small class="text-muted fw-normal">(اختياري)</small></label>
                        <textarea class="form-control" name="year_notes" id="editYearNotes" rows="2" maxlength="500"></textarea>
                    </div>
                    <div class="mt-3 p-3 border rounded bg-light">
                        <label class="form-label fw-bold">
                            <i class="fas fa-shield-alt text-danger me-1"></i>
                            للتأكيد، أعد كتابة الاسم الجديد <span class="text-danger">*</span>
                        </label>
                        <input type="text" class="form-control" name="confirm_name" id="editConfirmName" dir="ltr" required maxlength="20">
                        <small class="text-muted">يجب أن يتطابق تماماً مع الاسم الجديد أعلاه.</small>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal"><i class="fas fa-times me-1"></i>إلغاء</button>
                    <button type="submit" name="edit_academic_year" class="btn btn-primary" id="editSubmitBtn"><i class="fas fa-save me-1"></i>حفظ التعديلات</button>
                </div>
            </form>
        </div>
    </div>
</div>

<div class="modal fade" id="deleteAcademicYearModal" tabindex="-1" aria-labelledby="deleteAcademicYearTitle" aria-hidden="true">
    <div class="modal-dialog">
        <div class="modal-content admin-modal admin-modal-premium admin-modal-delete">
            <form id="deleteAcademicYearForm" method="POST">
                <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($_SESSION['csrf_token'], ENT_QUOTES, 'UTF-8'); ?>">
                <input type="hidden" name="academic_year_id" id="deleteYearId">
                <div class="modal-header">
                    <h5 class="modal-title" id="deleteAcademicYearTitle"><i class="fas fa-trash me-2"></i>حذف عام دراسي</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="إغلاق"></button>
                </div>
                <div class="modal-body">
                    <div class="text-center mb-3">
                        <i class="fas fa-calendar-xmark text-danger admin-modal-icon-lg"></i>
                    </div>
                    <p class="text-center">
                        هل تريد حذف العام <span class="fw-bold text-primary" id="deleteYearNameDisplay" dir="ltr"></span>؟
                    </p>
                    <div class="alert alert-warning">
                        <i class="fas fa-shield-halved me-2"></i>
                        يسمح النظام بالحذف فقط إذا كان العام غير نشط وغير مقفل ولا يحتوي على أي بيانات مرتبطة.
                    </div>
                    <div class="mb-0">
                        <label class="form-label fw-bold" for="deleteYearConfirmName">
                            للتأكيد، اكتب اسم العام كما هو <span class="text-danger">*</span>
                        </label>
                        <input type="text"
                               class="form-control"
                               name="confirm_name"
                               id="deleteYearConfirmName"
                               dir="ltr"
                               required
                               maxlength="20"
                               autocomplete="off">
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">
                        <i class="fas fa-times me-1"></i>إلغاء
                    </button>
                    <button type="submit" name="delete_academic_year" class="btn btn-danger" id="deleteYearSubmit" disabled>
                        <i class="fas fa-trash me-1"></i>حذف العام
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>
</div>

<script>
(function () {
    var editModal = document.getElementById('editAcademicYearModal');
    if (editModal) {
        var editId = editModal.querySelector('#editYearId');
        var editName = editModal.querySelector('#editYearName');
        var editStart = editModal.querySelector('#editStartDate');
        var editEnd = editModal.querySelector('#editEndDate');
        var editNotes = editModal.querySelector('#editYearNotes');
        var editConfirm = editModal.querySelector('#editConfirmName');
        var editSubmit = editModal.querySelector('#editSubmitBtn');
        var editCurrentName = editModal.querySelector('#editCurrentNameDisplay');
        var editStudentCount = editModal.querySelector('#editStudentCountDisplay');

        function validateEditConfirm() {
            var matches = (editName.value || '') === (editConfirm.value || '');
            editSubmit.disabled = !matches;
            editConfirm.classList.toggle('is-valid', matches);
            editConfirm.classList.toggle('is-invalid', !matches);
        }

        editModal.addEventListener('show.bs.modal', function (event) {
            var button = event.relatedTarget;
            if (!button) return;
            editId.value = button.getAttribute('data-year-id') || '';
            editName.value = button.getAttribute('data-year-name') || '';
            editStart.value = button.getAttribute('data-year-start') || '';
            editEnd.value = button.getAttribute('data-year-end') || '';
            editNotes.value = button.getAttribute('data-year-notes') || '';
            editConfirm.value = '';
            editCurrentName.textContent = button.getAttribute('data-year-name') || '—';
            editStudentCount.textContent = button.getAttribute('data-year-students') || '0';
            validateEditConfirm();
        });

        editName.addEventListener('input', validateEditConfirm);
        editConfirm.addEventListener('input', validateEditConfirm);
    }

    var deleteModal = document.getElementById('deleteAcademicYearModal');
    if (deleteModal) {
        var deleteId = deleteModal.querySelector('#deleteYearId');
        var deleteNameDisplay = deleteModal.querySelector('#deleteYearNameDisplay');
        var deleteConfirm = deleteModal.querySelector('#deleteYearConfirmName');
        var deleteSubmit = deleteModal.querySelector('#deleteYearSubmit');
        var expectedDeleteName = '';

        function validateDeleteConfirm() {
            var matches = expectedDeleteName !== '' && (deleteConfirm.value || '') === expectedDeleteName;
            deleteSubmit.disabled = !matches;
            deleteConfirm.classList.toggle('is-valid', matches);
            deleteConfirm.classList.toggle('is-invalid', deleteConfirm.value !== '' && !matches);
        }

        deleteModal.addEventListener('show.bs.modal', function (event) {
            var button = event.relatedTarget;
            if (!button) return;
            deleteId.value = button.getAttribute('data-year-id') || '';
            expectedDeleteName = button.getAttribute('data-year-name') || '';
            deleteNameDisplay.textContent = expectedDeleteName;
            deleteConfirm.value = '';
            validateDeleteConfirm();
        });

        deleteConfirm.addEventListener('input', validateDeleteConfirm);
    }
})();
</script>

<?php require_once '../includes/admin_footer.php'; ?>
