<?php
$page_title = "نشر تقارير الدرجات";
$custom_page_title = true;

require_once '../config/database.php';
require_once '../classes/utilities.php';
require_once '../classes/AcademicYear.php';
require_once '../classes/AssessmentEngine.php';
require_once '../classes/ActivityLog.php';
require_once '../includes/session_config.php';
require_once '../includes/csrf.php';
Utilities::validateSession('teacher');
requireCsrfPost();

$database = new Database();
$db = $database->getConnection();

$teacherId = (int) ($_SESSION['user_id'] ?? 0);
$success_message = $_SESSION['success_message'] ?? null;
$error_message = $_SESSION['error_message'] ?? null;
unset($_SESSION['success_message'], $_SESSION['error_message']);

function tap_table_exists(PDO $db, string $table): bool
{
    $stmt = $db->prepare('SELECT 1 FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?');
    $stmt->execute([$table]);
    return (bool) $stmt->fetchColumn();
}

function tap_permission_roles(): array
{
    $roles = [
        $_SESSION['role'] ?? '',
        class_exists('Utilities') ? Utilities::getEffectiveRole() : '',
        'teacher',
    ];
    if (class_exists('Utilities') && Utilities::isSupervisor()) {
        $roles[] = 'supervisor';
        $roles[] = 'subject_supervisor';
        $roles[] = 'department_head';
    }

    return array_values(array_unique(array_filter($roles)));
}

function tap_redirect(array $params = []): void
{
    header('Location: assessment_reports.php' . ($params ? ('?' . http_build_query($params)) : ''));
    exit();
}

function tap_ready(PDO $db): bool
{
    foreach (['report_windows', 'report_window_items', 'published_reports', 'published_report_details', 'student_enrollments'] as $table) {
        if (!tap_table_exists($db, $table)) {
            return false;
        }
    }
    return true;
}

function tap_fetch_report_items(PDO $db, int $reportWindowId): array
{
    $stmt = $db->prepare("SELECT rwi.*, sch.subject_id AS scheme_subject_id, sch.grade_id AS scheme_grade_id
        FROM report_window_items rwi
        LEFT JOIN assessment_schemes sch ON sch.id = rwi.scheme_id
        WHERE rwi.report_window_id = ? AND rwi.include_item = 1");
    $stmt->execute([$reportWindowId]);
    return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
}

function tap_class_grade(PDO $db, int $classId): ?int
{
    $stmt = $db->prepare('SELECT grade_id FROM classes WHERE id = ? LIMIT 1');
    $stmt->execute([$classId]);
    $gradeId = $stmt->fetchColumn();
    return $gradeId !== false ? (int) $gradeId : null;
}

function tap_teacher_requires_assignment_scope(): bool
{
    if (class_exists('Utilities') && Utilities::isSupervisor()) {
        return false;
    }

    $effectiveRole = class_exists('Utilities') ? Utilities::getEffectiveRole() : ($_SESSION['role'] ?? '');
    return in_array($effectiveRole, ['', 'teacher'], true) || ($_SESSION['role'] ?? '') === 'teacher';
}

function tap_teacher_has_active_report_assignment(PDO $db, int $teacherId, array $reportWindow, int $classId): bool
{
    if (!tap_table_exists($db, 'teacher_subject_assignments')) {
        return false;
    }

    $classGradeId = tap_class_grade($db, $classId);
    if ($classGradeId === null) {
        return false;
    }

    $items = tap_fetch_report_items($db, (int) $reportWindow['id']);
    if (empty($items)) {
        return false;
    }

    $stmt = $db->prepare("SELECT 1
        FROM teacher_subject_assignments
        WHERE teacher_id = ?
          AND academic_year_id = ?
          AND (term_id IS NULL OR term_id = ?)
          AND subject_id = ?
          AND is_active = 1
          AND (grade_id IS NULL OR grade_id = ?)
          AND (class_id IS NULL OR class_id = ?)
          AND (starts_at IS NULL OR starts_at <= CURDATE())
          AND (ends_at IS NULL OR ends_at >= CURDATE())
        LIMIT 1");

    foreach ($items as $item) {
        $subjectId = !empty($item['subject_id'])
            ? (int) $item['subject_id']
            : (!empty($item['scheme_subject_id']) ? (int) $item['scheme_subject_id'] : 0);
        $itemGradeId = !empty($item['scheme_grade_id']) ? (int) $item['scheme_grade_id'] : $classGradeId;
        if ($subjectId <= 0 || $itemGradeId !== $classGradeId) {
            return false;
        }

        $stmt->execute([
            $teacherId,
            (int) $reportWindow['academic_year_id'],
            !empty($reportWindow['term_id']) ? (int) $reportWindow['term_id'] : null,
            $subjectId,
            $classGradeId,
            $classId,
        ]);
        if (!$stmt->fetchColumn()) {
            return false;
        }
    }

    return true;
}

function tap_fetch_current_classes(PDO $db, int $academicYearId): array
{
    if ($academicYearId > 0 && tap_table_exists($db, 'student_enrollments')) {
        $stmt = $db->prepare("SELECT DISTINCT c.id, c.name, g.grade_name
            FROM student_enrollments se
            JOIN users u ON u.id = se.student_id
            JOIN classes c ON c.id = se.class_id
            LEFT JOIN grades g ON g.id = c.grade_id
            WHERE se.academic_year_id = ?
              AND se.enrollment_status = 'enrolled'
              AND u.role = 'student'
              AND u.status = 'active'
              AND u.deleted_at IS NULL
              AND c.status = 'active'
            ORDER BY g.grade_order, c.name");
        $stmt->execute([$academicYearId]);
        $classes = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
        if (!empty($classes)) {
            return $classes;
        }
    }

    $stmt = $db->query("SELECT c.id, c.name, g.grade_name
        FROM classes c
        LEFT JOIN grades g ON g.id = c.grade_id
        WHERE c.status = 'active'
        ORDER BY g.grade_order, c.name");
    return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
}

function tap_user_can_publish(PDO $db, int $teacherId, array $reportWindow, ?int $classId): bool
{
    if (!tap_table_exists($db, 'assessment_permissions')) {
        return false;
    }

    $engine = new AssessmentEngine($db);
    $permissionRoles = tap_permission_roles();
    $hasPublishPermission = false;
    if ($engine->userHasAnyPermissionRole($teacherId, $permissionRoles, 'publish_report', 'global', null)) {
        $hasPublishPermission = true;
    }

    if (!$hasPublishPermission && $classId === null) {
        return false;
    }

    if (!$hasPublishPermission && $engine->userHasAnyPermissionRole($teacherId, $permissionRoles, 'publish_report', 'class', $classId)) {
        $hasPublishPermission = true;
    }

    $gradeId = $classId !== null ? tap_class_grade($db, $classId) : null;
    if (!$hasPublishPermission && $gradeId !== null && $engine->userHasAnyPermissionRole($teacherId, $permissionRoles, 'publish_report', 'grade', $gradeId)) {
        $hasPublishPermission = true;
    }

    if (!$hasPublishPermission) {
        $items = tap_fetch_report_items($db, (int) $reportWindow['id']);
        if (empty($items)) {
            return false;
        }

        $subjectIds = [];
        $schemeIds = [];
        foreach ($items as $item) {
            $subjectId = !empty($item['subject_id']) ? (int) $item['subject_id'] : (!empty($item['scheme_subject_id']) ? (int) $item['scheme_subject_id'] : null);
            if ($subjectId === null) {
                return false;
            }
            $subjectIds[$subjectId] = true;
            if (!empty($item['scheme_id'])) {
                $schemeIds[(int) $item['scheme_id']] = true;
            }
        }

        if (count($subjectIds) === 1) {
            $subjectId = (int) array_key_first($subjectIds);
            if ($engine->userHasAnyPermissionRole($teacherId, $permissionRoles, 'publish_report', 'subject', $subjectId)) {
                $hasPublishPermission = true;
            }
        }

        if (!$hasPublishPermission && count($schemeIds) === 1) {
            $schemeId = (int) array_key_first($schemeIds);
            if ($engine->userHasAnyPermissionRole($teacherId, $permissionRoles, 'publish_report', 'scheme', $schemeId)) {
                $hasPublishPermission = true;
            }
        }
    }

    if (!$hasPublishPermission) {
        return false;
    }

    if (!tap_teacher_requires_assignment_scope()) {
        return true;
    }

    if ($classId === null) {
        return false;
    }

    return tap_teacher_has_active_report_assignment($db, $teacherId, $reportWindow, $classId);
}

function tap_fetch_report_windows(PDO $db, int $academicYearId, int $teacherId): array
{
    if (!tap_ready($db) || $academicYearId <= 0) {
        return [];
    }

    $stmt = $db->prepare("SELECT rw.*, ay.name AS academic_year_name, t.name AS term_name,
            0 AS published_count
        FROM report_windows rw
        JOIN academic_years ay ON ay.id = rw.academic_year_id
        LEFT JOIN academic_terms t ON t.id = rw.term_id
        WHERE rw.academic_year_id = ?
        GROUP BY rw.id
        ORDER BY rw.is_published ASC, rw.id DESC");
    $stmt->execute([$academicYearId]);
    $windows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

    $filtered = array_values(array_filter($windows, static function (array $window) use ($db, $academicYearId, $teacherId): bool {
        if (tap_user_can_publish($db, $teacherId, $window, null)) {
            return true;
        }

        foreach (tap_fetch_current_classes($db, $academicYearId) as $class) {
            $classId = (int) $class['id'];
            if (tap_user_can_publish($db, $teacherId, $window, (int) $classId)) {
                return true;
            }
        }
        return false;
    }));

    foreach ($filtered as &$window) {
        $window['published_count'] = tap_count_published_for_window_scope($db, $teacherId, $academicYearId, $window);
    }
    unset($window);

    return $filtered;
}

function tap_fetch_classes_for_report(PDO $db, int $teacherId, int $academicYearId, array $reportWindow): array
{
    $classes = [];
    $allowAll = tap_table_exists($db, 'assessment_permissions')
        && (new AssessmentEngine($db))->userHasAnyPermissionRole($teacherId, tap_permission_roles(), 'publish_report', 'global', null);
    if ($allowAll) {
        $classes[] = ['id' => 0, 'name' => 'كل الفصول'];
    }

    foreach (tap_fetch_current_classes($db, $academicYearId) as $class) {
        if (tap_user_can_publish($db, $teacherId, $reportWindow, (int) $class['id'])) {
            $classes[] = [
                'id' => (int) $class['id'],
                'name' => trim(($class['grade_name'] ?? '') . ' - ' . $class['name'], ' -'),
            ];
        }
    }

    return $classes;
}

function tap_count_published_for_window_scope(PDO $db, int $teacherId, int $academicYearId, array $reportWindow): int
{
    $classes = tap_fetch_classes_for_report($db, $teacherId, $academicYearId, $reportWindow);
    if (empty($classes)) {
        return 0;
    }

    foreach ($classes as $class) {
        if ((int) $class['id'] === 0) {
            $stmt = $db->prepare('SELECT COUNT(*) FROM published_reports WHERE report_window_id = ?');
            $stmt->execute([(int) $reportWindow['id']]);
            return (int) $stmt->fetchColumn();
        }
    }

    $classIds = array_values(array_unique(array_map('intval', array_column($classes, 'id'))));
    if (empty($classIds)) {
        return 0;
    }

    $placeholders = implode(',', array_fill(0, count($classIds), '?'));
    $params = array_merge([(int) $reportWindow['id'], $academicYearId], $classIds);
    $stmt = $db->prepare("SELECT COUNT(DISTINCT pr.id)
        FROM published_reports pr
        JOIN student_enrollments se
          ON se.student_id = pr.student_id
         AND se.academic_year_id = pr.academic_year_id
        JOIN users u ON u.id = pr.student_id
        WHERE pr.report_window_id = ?
          AND pr.academic_year_id = ?
          AND se.enrollment_status = 'enrolled'
          AND se.class_id IN ($placeholders)
          AND u.role = 'student'
          AND u.status = 'active'
          AND u.deleted_at IS NULL");
    $stmt->execute($params);
    return (int) $stmt->fetchColumn();
}

$currentAcademicYear = AcademicYear::getCurrent($db);
$currentAcademicYearId = $currentAcademicYear ? (int) $currentAcademicYear['id'] : 0;
$foundationReady = tap_ready($db);
$reportWindows = tap_fetch_report_windows($db, $currentAcademicYearId, $teacherId);
$reportWindowsById = [];
foreach ($reportWindows as $window) {
    $reportWindowsById[(int) $window['id']] = $window;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && $foundationReady) {
    try {
        $action = (string) ($_POST['action'] ?? '');
        if ($action !== 'publish_report_window') {
            throw new InvalidArgumentException('الإجراء المطلوب غير معروف.');
        }

        $reportWindowId = (int) ($_POST['report_window_id'] ?? 0);
        $classId = (int) ($_POST['class_id'] ?? 0);
        $classScope = $classId > 0 ? $classId : null;
        if (!isset($reportWindowsById[$reportWindowId])) {
            throw new InvalidArgumentException('نافذة التقرير غير متاحة لك.');
        }

        $reportWindow = $reportWindowsById[$reportWindowId];
        if ($classScope !== null) {
            $availableClassIds = array_map(
                'intval',
                array_column(tap_fetch_classes_for_report($db, $teacherId, $currentAcademicYearId, $reportWindow), 'id')
            );
            if (!in_array($classScope, $availableClassIds, true)) {
                throw new InvalidArgumentException('الفصل المحدد غير متاح لك لهذا التقرير.');
            }
        }
        if (!tap_user_can_publish($db, $teacherId, $reportWindow, $classScope)) {
            throw new RuntimeException('ليس لديك صلاحية نشر هذا التقرير لهذا النطاق.');
        }

        $result = (new AssessmentEngine($db))->publishReportWindow($reportWindowId, $classScope, $teacherId);
        $publishedCount = (int) ($result['published'] ?? 0);
        $skippedCount = (int) ($result['skipped'] ?? 0);
        $pendingReviewCount = (int) ($result['pending_review'] ?? 0);

        ActivityLog::logUpdate('report_window', $reportWindowId, (string) $reportWindow['name'], [
            'report_type' => $reportWindow['report_type'],
            'class_id' => $classScope,
            'count' => $publishedCount,
            'skipped' => $skippedCount,
            'pending_review' => $pendingReviewCount,
        ]);

        $_SESSION['success_message'] = "تم نشر {$publishedCount} تقريرا للطلاب.";
        if ($skippedCount > 0) {
            $_SESSION['success_message'] .= " وتم تخطي {$skippedCount} تقريرا مجمدا.";
        }
        if ($pendingReviewCount > 0) {
            $_SESSION['success_message'] .= " توجد {$pendingReviewCount} درجة بانتظار المراجعة لم تدخل في التقارير.";
        }
        tap_redirect(['report_window_id' => $reportWindowId, 'class_id' => $classId]);
    } catch (Throwable $e) {
        $_SESSION['error_message'] = $e->getMessage();
        tap_redirect([
            'report_window_id' => (int) ($_POST['report_window_id'] ?? 0),
            'class_id' => (int) ($_POST['class_id'] ?? 0),
        ]);
    }
}

$selectedReportWindowId = (int) ($_GET['report_window_id'] ?? 0);
$selectedReportWindow = $reportWindowsById[$selectedReportWindowId] ?? ($reportWindows[0] ?? null);
$availableClasses = $selectedReportWindow ? tap_fetch_classes_for_report($db, $teacherId, $currentAcademicYearId, $selectedReportWindow) : [];
$selectedClassId = (int) ($_GET['class_id'] ?? 0);
if (empty($availableClasses)) {
    $selectedClassId = 0;
} elseif (!in_array($selectedClassId, array_map('intval', array_column($availableClasses, 'id')), true)) {
    $selectedClassId = (int) $availableClasses[0]['id'];
}
$selectedReadiness = null;
if ($selectedReportWindow && !empty($availableClasses)) {
    $readinessClassScope = $selectedClassId > 0 ? $selectedClassId : null;
    $selectedReadiness = (new AssessmentEngine($db))->getReportWindowPublishReadiness((int) $selectedReportWindow['id'], $readinessClassScope);
}

$stats = [
    'windows' => count($reportWindows),
    'published_windows' => 0,
    'published_reports' => 0,
    'available_classes' => count($availableClasses),
];
foreach ($reportWindows as $window) {
    if (!empty($window['is_published'])) {
        $stats['published_windows']++;
    }
    $stats['published_reports'] += (int) ($window['published_count'] ?? 0);
}

include_once '../includes/teacher_header.php';
?>

<style>
.stat-card{position:relative;display:flex;align-items:center;gap:1rem;min-height:112px;padding:1.25rem;border-radius:8px;color:#fff;overflow:hidden;background:var(--card-gradient);box-shadow:0 10px 22px rgba(15,23,42,.12)}
.stat-card-icon{width:52px;height:52px;border-radius:8px;background:rgba(255,255,255,.18);display:flex;align-items:center;justify-content:center;font-size:1.6rem}
.stat-card-info{position:relative;z-index:1}
.stat-card-number{font-size:1.9rem;font-weight:800;line-height:1}
.stat-card-label{font-weight:700;margin-top:.35rem}
.stat-card-sub{font-size:.82rem;opacity:.88;margin-top:.25rem}
</style>

<div class="d-flex justify-content-between flex-wrap flex-md-nowrap align-items-center pt-3 pb-2 mb-3 border-bottom">
    <h1 class="h2"><i class="fas fa-file-export me-2 text-primary"></i>نشر تقارير الدرجات</h1>
    <div class="btn-toolbar mb-2 mb-md-0 gap-2">
        <a href="portal.php" class="btn btn-outline-secondary shadow-sm px-3 py-2">
            <i class="fas fa-arrow-right me-2"></i>العودة للبوابة
        </a>
        <a href="assessment_review.php" class="btn btn-outline-primary shadow-sm px-3 py-2">
            <i class="fas fa-clipboard-check me-2"></i>مراجعة الدرجات
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

<div class="row row-cols-2 row-cols-md-4 g-3 mb-4">
    <div class="col"><div class="stat-card" style="--card-gradient:linear-gradient(135deg,#3b82f6,#2563eb);"><div class="stat-card-icon"><i class="fas fa-window-restore"></i></div><div class="stat-card-info"><div class="stat-card-number"><?php echo number_format($stats['windows']); ?></div><div class="stat-card-label">نوافذ متاحة</div></div></div></div>
    <div class="col"><div class="stat-card" style="--card-gradient:linear-gradient(135deg,#10b981,#059669);"><div class="stat-card-icon"><i class="fas fa-eye"></i></div><div class="stat-card-info"><div class="stat-card-number"><?php echo number_format($stats['published_windows']); ?></div><div class="stat-card-label">نوافذ منشورة</div></div></div></div>
    <div class="col"><div class="stat-card" style="--card-gradient:linear-gradient(135deg,#8b5cf6,#7c3aed);"><div class="stat-card-icon"><i class="fas fa-file-lines"></i></div><div class="stat-card-info"><div class="stat-card-number"><?php echo number_format($stats['published_reports']); ?></div><div class="stat-card-label">تقارير منشورة</div></div></div></div>
    <div class="col"><div class="stat-card" style="--card-gradient:linear-gradient(135deg,#0ea5e9,#0284c7);"><div class="stat-card-icon"><i class="fas fa-door-open"></i></div><div class="stat-card-info"><div class="stat-card-number"><?php echo number_format($stats['available_classes']); ?></div><div class="stat-card-label">نطاقات متاحة</div></div></div></div>
</div>

<?php if (!$foundationReady): ?>
    <div class="alert alert-warning"><i class="fas fa-info-circle me-2"></i>جداول التقارير المنشورة غير مكتملة بعد.</div>
<?php else: ?>
<div class="card shadow">
    <div class="card-header bg-primary text-white">
        <div class="row align-items-center">
            <div class="col-md-3">
                <h5 class="mb-0"><i class="fas fa-upload me-2"></i>نشر تقرير</h5>
            </div>
            <div class="col-md-9">
                <form method="GET" class="d-flex justify-content-end align-items-center gap-2 flex-wrap">
                    <select name="report_window_id" id="reportWindowSelect" class="form-select form-select-sm" required style="width:auto; min-width:260px;">
                        <?php if (empty($reportWindows)): ?>
                            <option value="">لا توجد نوافذ متاحة</option>
                        <?php else: ?>
                            <?php foreach ($reportWindows as $window): ?>
                                <option value="<?php echo (int) $window['id']; ?>" <?php echo $selectedReportWindow && (int) $selectedReportWindow['id'] === (int) $window['id'] ? 'selected' : ''; ?>>
                                    <?php echo htmlspecialchars($window['name'] . ' - ' . ($window['term_name'] ?? 'كل العام'), ENT_QUOTES, 'UTF-8'); ?>
                                </option>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </select>
                    <button type="submit" class="btn btn-light btn-sm"><i class="fas fa-search me-1"></i>عرض</button>
                </form>
            </div>
        </div>
    </div>
    <div class="card-body">
        <?php if (!$selectedReportWindow): ?>
            <div class="alert alert-info mb-0"><i class="fas fa-circle-info me-2"></i>لا توجد نوافذ تقارير ضمن صلاحيات النشر لديك.</div>
        <?php elseif (empty($availableClasses)): ?>
            <div class="alert alert-warning mb-0"><i class="fas fa-info-circle me-2"></i>لا يوجد فصل أو نطاق نشر متاح لهذه النافذة ضمن صلاحياتك.</div>
        <?php else: ?>
            <?php if ($selectedReadiness): ?>
                <div class="row row-cols-2 row-cols-md-5 g-2 mb-3">
                    <div class="col"><div class="border rounded p-2 bg-light"><div class="small text-muted">طلاب النطاق</div><div class="fw-bold fs-5"><?php echo number_format((int) $selectedReadiness['target_students']); ?></div></div></div>
                    <div class="col"><div class="border rounded p-2 bg-light"><div class="small text-muted">قابل للنشر</div><div class="fw-bold fs-5 text-success"><?php echo number_format((int) $selectedReadiness['publishable']); ?></div></div></div>
                    <div class="col"><div class="border rounded p-2 bg-light"><div class="small text-muted">بدون درجات</div><div class="fw-bold fs-5 text-secondary"><?php echo number_format((int) $selectedReadiness['empty']); ?></div></div></div>
                    <div class="col"><div class="border rounded p-2 bg-light"><div class="small text-muted">مجمّد سابقا</div><div class="fw-bold fs-5 text-info"><?php echo number_format((int) $selectedReadiness['frozen_existing']); ?></div></div></div>
                    <div class="col"><div class="border rounded p-2 bg-light"><div class="small text-muted">بانتظار المراجعة</div><div class="fw-bold fs-5 text-warning"><?php echo number_format((int) $selectedReadiness['pending_review']); ?></div></div></div>
                </div>
                <?php if ((int) $selectedReadiness['pending_review'] > 0): ?>
                    <div class="alert alert-warning">
                        <i class="fas fa-triangle-exclamation me-2"></i>
                        توجد درجات بانتظار المراجعة ضمن هذا النطاق، ولن تدخل في التقرير المنشور حتى يتم اعتمادها.
                    </div>
                <?php endif; ?>
            <?php endif; ?>
            <form method="post" action="assessment_reports.php" class="row g-3 align-items-end">
                <?php echo csrfField(); ?>
                <input type="hidden" name="action" value="publish_report_window">
                <input type="hidden" name="report_window_id" value="<?php echo (int) $selectedReportWindow['id']; ?>">
                <div class="col-md-5">
                    <label class="form-label">نافذة التقرير</label>
                    <div class="form-control bg-light">
                        <?php echo htmlspecialchars($selectedReportWindow['name'], ENT_QUOTES, 'UTF-8'); ?>
                        <span class="badge bg-secondary ms-2"><?php echo htmlspecialchars($selectedReportWindow['report_type'], ENT_QUOTES, 'UTF-8'); ?></span>
                    </div>
                </div>
                <div class="col-md-4">
                    <label class="form-label">نطاق النشر</label>
                    <select name="class_id" class="form-select" required>
                        <?php foreach ($availableClasses as $class): ?>
                            <option value="<?php echo (int) $class['id']; ?>" <?php echo (int) $class['id'] === $selectedClassId ? 'selected' : ''; ?>>
                                <?php echo htmlspecialchars($class['name'], ENT_QUOTES, 'UTF-8'); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-md-3">
                    <button type="submit" class="btn btn-success w-100">
                        <i class="fas fa-upload me-1"></i>نشر التقارير
                    </button>
                </div>
            </form>

            <hr>
            <div class="table-responsive">
                <table class="table table-hover table-striped align-middle">
                    <thead>
                        <tr>
                            <th>النافذة</th>
                            <th>الترم</th>
                            <th>الحالة</th>
                            <th>عدد المنشور</th>
                            <th>آخر نشر</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($reportWindows as $window): ?>
                            <tr>
                                <td><?php echo htmlspecialchars($window['name'], ENT_QUOTES, 'UTF-8'); ?></td>
                                <td><?php echo htmlspecialchars($window['term_name'] ?? '-', ENT_QUOTES, 'UTF-8'); ?></td>
                                <td>
                                    <?php echo !empty($window['is_published']) ? '<span class="badge bg-success">منشور</span>' : '<span class="badge bg-warning text-dark">غير منشور</span>'; ?>
                                    <?php if (!empty($window['freeze_on_publish'])): ?>
                                        <span class="badge bg-info text-dark">تجميد</span>
                                    <?php endif; ?>
                                </td>
                                <td><span class="badge bg-primary"><?php echo number_format((int) ($window['published_count'] ?? 0)); ?></span></td>
                                <td><span dir="ltr"><?php echo htmlspecialchars($window['published_at'] ?? '-', ENT_QUOTES, 'UTF-8'); ?></span></td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php endif; ?>
    </div>
</div>
<?php endif; ?>

<script>
document.addEventListener('DOMContentLoaded', function () {
    const reportWindowSelect = document.getElementById('reportWindowSelect');
    if (reportWindowSelect) {
        reportWindowSelect.addEventListener('change', function () {
            if (this.value) {
                window.location.href = 'assessment_reports.php?report_window_id=' + encodeURIComponent(this.value);
            }
        });
    }
});
</script>

<?php include_once '../includes/teacher_footer.php'; ?>
