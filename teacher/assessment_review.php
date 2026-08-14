<?php
$page_title = "مراجعة الدرجات";
$custom_page_title = true;

require_once '../config/database.php';
require_once '../classes/utilities.php';
require_once '../classes/AcademicYear.php';
require_once '../classes/AcademicYearWriteGuard.php';
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

function tar_table_exists(PDO $db, string $table): bool
{
    $stmt = $db->prepare('SELECT 1 FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?');
    $stmt->execute([$table]);
    return (bool) $stmt->fetchColumn();
}

function tar_permission_roles(): array
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

function tar_redirect(array $params = []): void
{
    header('Location: assessment_review.php' . ($params ? ('?' . http_build_query($params)) : ''));
    exit();
}

function tar_ready(PDO $db): bool
{
    foreach (['assessment_windows', 'assessment_schemes', 'assessment_components', 'student_marks', 'academic_terms'] as $table) {
        if (!tar_table_exists($db, $table)) {
            return false;
        }
    }
    return true;
}

function tar_can_review_assignment(PDO $db, int $teacherId, int $yearId, int $termId, int $subjectId, int $gradeId, ?int $classId, bool $allowSpecificClassForGradeWindow = false): bool
{
    if (!tar_table_exists($db, 'teacher_subject_assignments')) {
        return false;
    }

    $stmt = $db->prepare("SELECT 1 FROM teacher_subject_assignments
        WHERE teacher_id = ?
          AND academic_year_id = ?
          AND (term_id IS NULL OR term_id = ?)
          AND subject_id = ?
          AND is_active = 1
          AND can_review = 1
          AND (grade_id IS NULL OR grade_id = ?)
          AND (
                (? IS NULL AND (? = 1 OR class_id IS NULL))
                OR (? IS NOT NULL AND (class_id IS NULL OR class_id = ?))
              )
          AND (starts_at IS NULL OR starts_at <= CURDATE())
          AND (ends_at IS NULL OR ends_at >= CURDATE())
        LIMIT 1");
    $stmt->execute([
        $teacherId,
        $yearId,
        $termId,
        $subjectId,
        $gradeId,
        $classId,
        $allowSpecificClassForGradeWindow ? 1 : 0,
        $classId,
        $classId,
    ]);
    return (bool) $stmt->fetchColumn();
}

function tar_teacher_requires_assignment_scope(): bool
{
    if (class_exists('Utilities') && Utilities::isSupervisor()) {
        return false;
    }

    $effectiveRole = class_exists('Utilities') ? Utilities::getEffectiveRole() : ($_SESSION['role'] ?? '');
    return in_array($effectiveRole, ['', 'teacher'], true) || ($_SESSION['role'] ?? '') === 'teacher';
}

function tar_has_active_assignment_scope(PDO $db, int $teacherId, int $yearId, int $termId, int $subjectId, int $gradeId, ?int $classId, bool $allowSpecificClassForGradeWindow = false): bool
{
    if (!tar_table_exists($db, 'teacher_subject_assignments')) {
        return false;
    }

    $stmt = $db->prepare("SELECT 1 FROM teacher_subject_assignments
        WHERE teacher_id = ?
          AND academic_year_id = ?
          AND (term_id IS NULL OR term_id = ?)
          AND subject_id = ?
          AND is_active = 1
          AND (grade_id IS NULL OR grade_id = ?)
          AND (
                (? IS NULL AND (? = 1 OR class_id IS NULL))
                OR (? IS NOT NULL AND (class_id IS NULL OR class_id = ?))
              )
          AND (starts_at IS NULL OR starts_at <= CURDATE())
          AND (ends_at IS NULL OR ends_at >= CURDATE())
        LIMIT 1");
    $stmt->execute([
        $teacherId,
        $yearId,
        $termId,
        $subjectId,
        $gradeId,
        $classId,
        $allowSpecificClassForGradeWindow ? 1 : 0,
        $classId,
        $classId,
    ]);
    return (bool) $stmt->fetchColumn();
}

function tar_can_review_window(PDO $db, int $teacherId, array $window, ?int $classId = null): bool
{
    $effectiveClassId = $classId ?: (!empty($window['class_id']) ? (int) $window['class_id'] : null);
    if (tar_can_review_assignment(
        $db,
        $teacherId,
        (int) $window['academic_year_id'],
        (int) $window['term_id'],
        (int) $window['subject_id'],
        (int) $window['grade_id'],
        $effectiveClassId,
        $effectiveClassId === null
    )) {
        return true;
    }

    if (!tar_table_exists($db, 'assessment_permissions')) {
        return false;
    }

    $engine = new AssessmentEngine($db);
    $hasReviewPermission = false;
    $checks = [
        ['global', null],
        ['subject', (int) $window['subject_id']],
        ['grade', (int) $window['grade_id']],
        ['scheme', (int) $window['scheme_id']],
    ];
    if ($effectiveClassId !== null) {
        $checks[] = ['class', $effectiveClassId];
    }
    foreach ($checks as $check) {
        if ($engine->userHasAnyPermissionRole($teacherId, tar_permission_roles(), 'review_marks', $check[0], $check[1])) {
            $hasReviewPermission = true;
            break;
        }
    }
    if (!$hasReviewPermission) {
        return false;
    }

    if (!tar_teacher_requires_assignment_scope()) {
        return true;
    }

    return tar_has_active_assignment_scope(
        $db,
        $teacherId,
        (int) $window['academic_year_id'],
        (int) $window['term_id'],
        (int) $window['subject_id'],
        (int) $window['grade_id'],
        $effectiveClassId,
        $effectiveClassId === null
    );
}

function tar_fetch_current_year_classes(PDO $db, array $window): array
{
    if (!tar_table_exists($db, 'student_enrollments')) {
        $stmt = $db->prepare('SELECT id, name FROM classes WHERE grade_id = ? AND status = ? ORDER BY name');
        $stmt->execute([(int) $window['grade_id'], 'active']);
        return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    }

    $where = [
        'se.academic_year_id = ?',
        "se.enrollment_status = 'enrolled'",
        "u.role = 'student'",
        "u.status = 'active'",
        'u.deleted_at IS NULL',
        "c.status = 'active'",
    ];
    $params = [(int) $window['academic_year_id']];
    if (!empty($window['class_id'])) {
        $where[] = 'c.id = ?';
        $params[] = (int) $window['class_id'];
    } else {
        $where[] = 'COALESCE(se.grade_id, c.grade_id) = ?';
        $params[] = (int) $window['grade_id'];
    }

    $stmt = $db->prepare("SELECT DISTINCT c.id, c.name
        FROM student_enrollments se
        JOIN users u ON u.id = se.student_id
        JOIN classes c ON c.id = se.class_id
        WHERE " . implode(' AND ', $where) . "
        ORDER BY c.name");
    $stmt->execute($params);
    return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
}

function tar_fetch_review_windows(PDO $db, int $teacherId, int $academicYearId): array
{
    if (!tar_ready($db) || $academicYearId <= 0) {
        return [];
    }

    $stmt = $db->prepare("SELECT aw.*, sch.academic_year_id, sch.term_id, sch.subject_id, sch.grade_id,
            sch.name AS scheme_name, ac.name AS component_name, s.name AS subject_name,
            g.grade_name, t.name AS term_name, w.name AS week_name, c.name AS class_name,
            COUNT(CASE WHEN mark_student.id IS NOT NULL AND mark_enrollment.student_id IS NOT NULL THEN sm.id END) AS marks_count,
            SUM(CASE WHEN mark_student.id IS NOT NULL AND mark_enrollment.student_id IS NOT NULL AND sm.review_status = 'pending' THEN 1 ELSE 0 END) AS pending_count,
            SUM(CASE WHEN mark_student.id IS NOT NULL AND mark_enrollment.student_id IS NOT NULL AND sm.review_status = 'approved' THEN 1 ELSE 0 END) AS approved_count,
            SUM(CASE WHEN mark_student.id IS NOT NULL AND mark_enrollment.student_id IS NOT NULL AND sm.review_status = 'rejected' THEN 1 ELSE 0 END) AS rejected_count
        FROM assessment_windows aw
        JOIN assessment_schemes sch ON sch.id = aw.scheme_id
        JOIN assessment_components ac ON ac.id = aw.component_id
        JOIN subjects s ON s.id = sch.subject_id
        JOIN grades g ON g.id = sch.grade_id
        JOIN academic_terms t ON t.id = sch.term_id
        LEFT JOIN academic_weeks w ON w.id = aw.week_id
        LEFT JOIN classes c ON c.id = aw.class_id
        LEFT JOIN student_marks sm ON sm.scheme_id = aw.scheme_id
            AND sm.component_id = aw.component_id
            AND ((sm.week_id IS NULL AND aw.week_id IS NULL) OR sm.week_id = aw.week_id)
            AND sm.academic_year_id = sch.academic_year_id
            AND sm.term_id = sch.term_id
        LEFT JOIN student_enrollments mark_enrollment ON mark_enrollment.student_id = sm.student_id
            AND mark_enrollment.academic_year_id = sm.academic_year_id
            AND mark_enrollment.enrollment_status = 'enrolled'
            AND (aw.class_id IS NULL OR mark_enrollment.class_id = aw.class_id)
        LEFT JOIN users mark_student ON mark_student.id = sm.student_id
            AND mark_student.role = 'student'
            AND mark_student.status = 'active'
            AND mark_student.deleted_at IS NULL
        WHERE aw.requires_review = 1
          AND aw.status = 'closed'
          AND sch.academic_year_id = ?
        GROUP BY aw.id
        ORDER BY pending_count DESC, aw.id DESC");
    $stmt->execute([$academicYearId]);
    $windows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

    return array_values(array_filter($windows, static function (array $window) use ($db, $teacherId): bool {
        return tar_can_review_window($db, $teacherId, $window, null);
    }));
}

function tar_fetch_window_classes(PDO $db, array $window, int $teacherId): array
{
    $classes = tar_fetch_current_year_classes($db, $window);
    return array_values(array_filter($classes, static function (array $class) use ($db, $window, $teacherId): bool {
        return tar_can_review_window($db, $teacherId, $window, (int) $class['id']);
    }));
}

function tar_fetch_marks(PDO $db, array $window, int $classId): array
{
    $stmt = $db->prepare("SELECT sm.*, u.name AS student_name, u.username,
            recorder.name AS recorded_by_name, reviewer.name AS reviewed_by_name
        FROM student_marks sm
        JOIN users u ON u.id = sm.student_id
        JOIN student_enrollments se ON se.student_id = sm.student_id AND se.academic_year_id = sm.academic_year_id
        LEFT JOIN users recorder ON recorder.id = sm.recorded_by
        LEFT JOIN users reviewer ON reviewer.id = sm.reviewed_by
        WHERE sm.scheme_id = ?
          AND sm.component_id = ?
          AND ((sm.week_id IS NULL AND ? IS NULL) OR sm.week_id = ?)
          AND sm.academic_year_id = ?
          AND sm.term_id = ?
          AND se.class_id = ?
          AND se.enrollment_status = 'enrolled'
          AND u.role = 'student'
          AND u.status = 'active'
          AND u.deleted_at IS NULL
        ORDER BY FIELD(sm.review_status, 'pending', 'rejected', 'approved', 'not_required'), u.name");
    $weekId = $window['week_id'] !== null ? (int) $window['week_id'] : null;
    $stmt->execute([
        (int) $window['scheme_id'],
        (int) $window['component_id'],
        $weekId,
        $weekId,
        (int) $window['academic_year_id'],
        (int) $window['term_id'],
        $classId,
    ]);
    return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
}

function tar_mark_label(array $mark): string
{
    if (($mark['mark_status'] ?? '') === AssessmentEngine::STATUS_ABSENT) {
        return 'غ';
    }
    if (($mark['mark_status'] ?? '') === AssessmentEngine::STATUS_EXCUSED_ABSENT) {
        return 'غ.ع';
    }
    return $mark['value'] !== null ? AssessmentEngine::formatNumber((float) $mark['value']) : '';
}

$currentAcademicYear = AcademicYear::getCurrent($db);
$currentAcademicYearId = $currentAcademicYear ? (int) $currentAcademicYear['id'] : 0;
$foundationReady = tar_ready($db);
$reviewWindows = tar_fetch_review_windows($db, $teacherId, $currentAcademicYearId);
$reviewWindowsById = [];
foreach ($reviewWindows as $window) {
    $reviewWindowsById[(int) $window['id']] = $window;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && $foundationReady) {
    try {
        $action = (string) ($_POST['action'] ?? '');
        if ($action !== 'review_marks') {
            throw new InvalidArgumentException('الإجراء المطلوب غير معروف.');
        }

        $windowId = (int) ($_POST['window_id'] ?? 0);
        $classId = (int) ($_POST['class_id'] ?? 0);
        $reviewAction = (string) ($_POST['review_action'] ?? '');
        $reviewNote = trim((string) ($_POST['review_note'] ?? ''));
        $markIds = array_values(array_filter(array_map('intval', $_POST['mark_ids'] ?? [])));

        if (!isset($reviewWindowsById[$windowId]) || $classId <= 0) {
            throw new InvalidArgumentException('نافذة المراجعة أو الفصل غير متاحين لك.');
        }
        if (!in_array($reviewAction, ['approved', 'rejected'], true)) {
            throw new InvalidArgumentException('اختر اعتماد أو رفض الدرجات المحددة.');
        }
        if (empty($markIds)) {
            throw new InvalidArgumentException('حدد درجة واحدة على الأقل للمراجعة.');
        }

        $window = $reviewWindowsById[$windowId];
        if (!tar_can_review_window($db, $teacherId, $window, $classId)) {
            throw new RuntimeException('ليس لديك صلاحية مراجعة هذا الفصل.');
        }

        $placeholders = implode(',', array_fill(0, count($markIds), '?'));
        $weekId = $window['week_id'] !== null ? (int) $window['week_id'] : null;
        $params = array_merge($markIds, [
            (int) $window['scheme_id'],
            (int) $window['component_id'],
            $weekId,
            $weekId,
            (int) $window['academic_year_id'],
            (int) $window['term_id'],
            $classId,
        ]);
        $db->beginTransaction();
        $windowLockStmt = $db->prepare('SELECT status FROM assessment_windows WHERE id = ? FOR UPDATE');
        $windowLockStmt->execute([$windowId]);
        if ((string) $windowLockStmt->fetchColumn() !== 'closed') {
            throw new RuntimeException('لم تعد نافذة الرصد مغلقة للمراجعة. لم تتغير أي درجة.');
        }
        (new AcademicYearWriteGuard($db))->assertWritable((int) $window['academic_year_id']);

        $select = $db->prepare("SELECT sm.*
            FROM student_marks sm
            JOIN users u ON u.id = sm.student_id
            JOIN student_enrollments se ON se.student_id = sm.student_id AND se.academic_year_id = sm.academic_year_id
            WHERE sm.id IN ($placeholders)
              AND sm.scheme_id = ?
              AND sm.component_id = ?
              AND ((sm.week_id IS NULL AND ? IS NULL) OR sm.week_id = ?)
              AND sm.academic_year_id = ?
              AND sm.term_id = ?
              AND se.class_id = ?
              AND se.enrollment_status = 'enrolled'
              AND u.role = 'student'
              AND u.status = 'active'
              AND u.deleted_at IS NULL
            FOR UPDATE");
        $select->execute($params);
        $marks = $select->fetchAll(PDO::FETCH_ASSOC) ?: [];
        if (empty($marks)) {
            throw new InvalidArgumentException('لم يتم العثور على درجات مطابقة للمراجعة.');
        }

        $audit = $db->prepare("INSERT INTO student_mark_audit
            (mark_id, student_id, action, old_value, new_value, old_status, new_status, reason, changed_by)
            VALUES (?, ?, 'review', ?, ?, ?, ?, ?, ?)");
        $update = $db->prepare("UPDATE student_marks
            SET review_status = ?, reviewed_by = ?, reviewed_at = NOW(), review_note = ?,
                class_id_at_entry = ?
            WHERE id = ?");

        foreach ($marks as $mark) {
            $audit->execute([
                (int) $mark['id'],
                (int) $mark['student_id'],
                $mark['value'] !== null ? (string) $mark['value'] : null,
                $mark['value'] !== null ? (string) $mark['value'] : null,
                $mark['review_status'] ?? null,
                $reviewAction,
                $reviewNote !== '' ? $reviewNote : ($reviewAction === 'approved' ? 'اعتماد درجة' : 'رفض درجة'),
                $teacherId,
            ]);
            $update->execute([
                $reviewAction,
                $teacherId,
                $reviewNote !== '' ? $reviewNote : null,
                $classId,
                (int) $mark['id'],
            ]);
        }
        $db->commit();

        ActivityLog::logUpdate('student_mark', $windowId, (string) $window['window_name'], [
            'window' => $window['window_name'],
            'subject' => $window['subject_name'],
            'class_id' => $classId,
            'status' => $reviewAction,
            'count' => count($marks),
        ]);

        $_SESSION['success_message'] = $reviewAction === 'approved'
            ? 'تم اعتماد الدرجات المحددة.'
            : 'تم رفض الدرجات المحددة.';
        tar_redirect(['window_id' => $windowId, 'class_id' => $classId]);
    } catch (Throwable $e) {
        if ($db->inTransaction()) {
            $db->rollBack();
        }
        $_SESSION['error_message'] = $e->getMessage();
        tar_redirect([
            'window_id' => (int) ($_POST['window_id'] ?? 0),
            'class_id' => (int) ($_POST['class_id'] ?? 0),
        ]);
    }
}

$selectedWindowId = (int) ($_GET['window_id'] ?? 0);
$selectedWindow = $reviewWindowsById[$selectedWindowId] ?? ($reviewWindows[0] ?? null);
$availableClasses = $selectedWindow ? tar_fetch_window_classes($db, $selectedWindow, $teacherId) : [];
$selectedClassId = (int) ($_GET['class_id'] ?? 0);
if ($selectedClassId <= 0 && !empty($availableClasses)) {
    $selectedClassId = (int) $availableClasses[0]['id'];
}
$allowedClassIds = array_map('intval', array_column($availableClasses, 'id'));
if ($selectedClassId > 0 && !in_array($selectedClassId, $allowedClassIds, true)) {
    $selectedClassId = 0;
}

$marks = ($selectedWindow && $selectedClassId > 0) ? tar_fetch_marks($db, $selectedWindow, $selectedClassId) : [];
$stats = ['total' => count($marks), 'pending' => 0, 'approved' => 0, 'rejected' => 0];
foreach ($marks as $mark) {
    $status = (string) ($mark['review_status'] ?? 'not_required');
    if (isset($stats[$status])) {
        $stats[$status]++;
    }
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
.review-pending{background:#fff7ed!important}
.review-rejected{background:#fef2f2!important}
.review-approved{background:#f0fdf4!important}
</style>

<div class="d-flex justify-content-between flex-wrap flex-md-nowrap align-items-center pt-3 pb-2 mb-3 border-bottom">
    <h1 class="h2"><i class="fas fa-clipboard-check me-2 text-primary"></i>مراجعة الدرجات</h1>
    <div class="btn-toolbar mb-2 mb-md-0 gap-2">
        <a href="portal.php" class="btn btn-outline-secondary shadow-sm px-3 py-2">
            <i class="fas fa-arrow-right me-2"></i>العودة للبوابة
        </a>
        <a href="assessment_marks.php" class="btn btn-outline-primary shadow-sm px-3 py-2">
            <i class="fas fa-pen-to-square me-2"></i>رصد الدرجات
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
    <div class="col"><div class="stat-card" style="--card-gradient:linear-gradient(135deg,#3b82f6,#2563eb);"><div class="stat-card-icon"><i class="fas fa-list"></i></div><div class="stat-card-info"><div class="stat-card-number"><?php echo number_format($stats['total']); ?></div><div class="stat-card-label">إجمالي الدرجات</div></div></div></div>
    <div class="col"><div class="stat-card" style="--card-gradient:linear-gradient(135deg,#f59e0b,#d97706);"><div class="stat-card-icon"><i class="fas fa-clock"></i></div><div class="stat-card-info"><div class="stat-card-number"><?php echo number_format($stats['pending']); ?></div><div class="stat-card-label">بانتظار المراجعة</div></div></div></div>
    <div class="col"><div class="stat-card" style="--card-gradient:linear-gradient(135deg,#10b981,#059669);"><div class="stat-card-icon"><i class="fas fa-check"></i></div><div class="stat-card-info"><div class="stat-card-number"><?php echo number_format($stats['approved']); ?></div><div class="stat-card-label">معتمدة</div></div></div></div>
    <div class="col"><div class="stat-card" style="--card-gradient:linear-gradient(135deg,#ef4444,#dc2626);"><div class="stat-card-icon"><i class="fas fa-xmark"></i></div><div class="stat-card-info"><div class="stat-card-number"><?php echo number_format($stats['rejected']); ?></div><div class="stat-card-label">مرفوضة</div></div></div></div>
</div>

<?php if (!$foundationReady): ?>
    <div class="alert alert-warning"><i class="fas fa-info-circle me-2"></i>جداول محرك الدرجات غير مكتملة بعد.</div>
<?php else: ?>
<div class="card shadow">
    <div class="card-header bg-primary text-white">
        <div class="row align-items-center">
            <div class="col-md-3">
                <h5 class="mb-0"><i class="fas fa-table me-2"></i>درجات المراجعة <span class="badge bg-light text-dark ms-2"><?php echo count($marks); ?></span></h5>
            </div>
            <div class="col-md-9">
                <form method="GET" class="d-flex justify-content-end align-items-center gap-2 flex-wrap">
                    <select name="window_id" id="reviewWindowSelect" class="form-select form-select-sm" required style="width:auto; min-width:280px;">
                        <?php if (empty($reviewWindows)): ?>
                            <option value="">لا توجد نوافذ متاحة</option>
                        <?php else: ?>
                            <?php foreach ($reviewWindows as $window): ?>
                                <option value="<?php echo (int) $window['id']; ?>" <?php echo $selectedWindow && (int) $selectedWindow['id'] === (int) $window['id'] ? 'selected' : ''; ?>>
                                    <?php echo htmlspecialchars($window['subject_name'] . ' - ' . $window['grade_name'] . ' - ' . $window['component_name'] . ' - ' . $window['window_name'], ENT_QUOTES, 'UTF-8'); ?>
                                </option>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </select>
                    <select name="class_id" class="form-select form-select-sm" required style="width:auto; min-width:150px;">
                        <?php if (empty($availableClasses)): ?>
                            <option value="">لا توجد فصول</option>
                        <?php else: ?>
                            <?php foreach ($availableClasses as $class): ?>
                                <option value="<?php echo (int) $class['id']; ?>" <?php echo (int) $class['id'] === $selectedClassId ? 'selected' : ''; ?>>
                                    <?php echo htmlspecialchars($class['name'], ENT_QUOTES, 'UTF-8'); ?>
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
        <?php if (!$selectedWindow): ?>
            <div class="alert alert-info mb-0"><i class="fas fa-circle-info me-2"></i>لا توجد نوافذ درجات تتطلب مراجعة ضمن صلاحياتك.</div>
        <?php else: ?>
            <form method="post" id="reviewMarksForm">
                <?php echo csrfField(); ?>
                <input type="hidden" name="action" value="review_marks">
                <input type="hidden" name="window_id" value="<?php echo (int) $selectedWindow['id']; ?>">
                <input type="hidden" name="class_id" value="<?php echo (int) $selectedClassId; ?>">

                <div class="d-flex justify-content-between align-items-center gap-2 flex-wrap mb-3">
                    <input type="text" name="review_note" class="form-control form-control-sm" maxlength="500" placeholder="ملاحظة المراجعة" style="width:auto; min-width:260px;">
                    <div class="d-flex gap-2">
                        <button type="submit" name="review_action" value="approved" class="btn btn-success btn-sm">
                            <i class="fas fa-check me-1"></i>اعتماد المحدد
                        </button>
                        <button type="submit" name="review_action" value="rejected" class="btn btn-danger btn-sm">
                            <i class="fas fa-xmark me-1"></i>رفض المحدد
                        </button>
                    </div>
                </div>

                <div class="table-responsive">
                    <table class="table table-hover table-striped align-middle">
                        <thead>
                            <tr>
                                <th style="width:45px;"><input type="checkbox" class="form-check-input" id="selectAllMarks"></th>
                                <th>الطالب</th>
                                <th>الدرجة</th>
                                <th>حالة المراجعة</th>
                                <th>رصد بواسطة</th>
                                <th>راجع بواسطة</th>
                                <th>ملاحظة</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (empty($marks)): ?>
                                <tr><td colspan="7" class="text-center text-muted py-4">لا توجد درجات مرصودة لهذا الفصل في هذه النافذة.</td></tr>
                            <?php else: ?>
                                <?php foreach ($marks as $mark): ?>
                                    <?php
                                    $reviewStatus = (string) ($mark['review_status'] ?? 'not_required');
                                    $rowClass = $reviewStatus === 'pending' ? 'review-pending' : ($reviewStatus === 'rejected' ? 'review-rejected' : ($reviewStatus === 'approved' ? 'review-approved' : ''));
                                    $reviewLabels = [
                                        'pending' => ['بانتظار المراجعة', 'bg-warning text-dark'],
                                        'approved' => ['معتمدة', 'bg-success'],
                                        'rejected' => ['مرفوضة', 'bg-danger'],
                                        'not_required' => ['لا تتطلب مراجعة', 'bg-secondary'],
                                    ];
                                    $reviewLabel = $reviewLabels[$reviewStatus] ?? [$reviewStatus, 'bg-secondary'];
                                    ?>
                                    <tr class="<?php echo $rowClass; ?>">
                                        <td><input type="checkbox" class="form-check-input mark-check" name="mark_ids[]" value="<?php echo (int) $mark['id']; ?>"></td>
                                        <td>
                                            <strong><?php echo htmlspecialchars($mark['student_name'], ENT_QUOTES, 'UTF-8'); ?></strong>
                                            <div class="small text-muted"><?php echo htmlspecialchars($mark['username'] ?? '', ENT_QUOTES, 'UTF-8'); ?></div>
                                        </td>
                                        <td><strong><?php echo htmlspecialchars(tar_mark_label($mark), ENT_QUOTES, 'UTF-8'); ?></strong></td>
                                        <td><span class="badge <?php echo $reviewLabel[1]; ?>"><?php echo htmlspecialchars($reviewLabel[0], ENT_QUOTES, 'UTF-8'); ?></span></td>
                                        <td><?php echo htmlspecialchars($mark['recorded_by_name'] ?? '-', ENT_QUOTES, 'UTF-8'); ?></td>
                                        <td>
                                            <?php echo htmlspecialchars($mark['reviewed_by_name'] ?? '-', ENT_QUOTES, 'UTF-8'); ?>
                                            <?php if (!empty($mark['reviewed_at'])): ?>
                                                <div class="small text-muted" dir="ltr"><?php echo htmlspecialchars($mark['reviewed_at'], ENT_QUOTES, 'UTF-8'); ?></div>
                                            <?php endif; ?>
                                        </td>
                                        <td><?php echo htmlspecialchars($mark['review_note'] ?? ($mark['note'] ?? '-'), ENT_QUOTES, 'UTF-8'); ?></td>
                                    </tr>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </form>
        <?php endif; ?>
    </div>
</div>
<?php endif; ?>

<script>
document.addEventListener('DOMContentLoaded', function () {
    const windowSelect = document.getElementById('reviewWindowSelect');
    if (windowSelect) {
        windowSelect.addEventListener('change', function () {
            if (this.value) {
                window.location.href = 'assessment_review.php?window_id=' + encodeURIComponent(this.value);
            }
        });
    }

    const selectAll = document.getElementById('selectAllMarks');
    if (selectAll) {
        selectAll.addEventListener('change', function () {
            document.querySelectorAll('.mark-check').forEach(function (checkbox) {
                checkbox.checked = selectAll.checked;
            });
        });
    }
});
</script>

<?php include_once '../includes/teacher_footer.php'; ?>
