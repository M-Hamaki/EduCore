<?php
$page_title = "تعيينات المعلمين";
$custom_page_title = true;

require_once '../config/database.php';
require_once '../classes/utilities.php';
require_once '../classes/ActivityLog.php';
require_once '../classes/AcademicYear.php';
require_once '../classes/AcademicYearWriteGuard.php';
require_once '../classes/AssessmentTeacherAssignmentListQuery.php';
require_once '../classes/AssessmentTeacherAssignmentActivationService.php';
require_once '../classes/StaffEmploymentLifecycleService.php';
require_once '../includes/session_config.php';
require_once '../includes/csrf.php';
Utilities::validateSession('admin');
requireCsrfPost();

$database = new Database();
$db = $database->getConnection();
ActivityLog::setDb($db);

$success_message = $_SESSION['success_message'] ?? null;
$error_message = $_SESSION['error_message'] ?? null;
unset($_SESSION['success_message'], $_SESSION['error_message']);

function teacher_assignments_table_exists(PDO $db, string $table): bool
{
    $stmt = $db->prepare('SELECT 1 FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?');
    $stmt->execute([$table]);
    return (bool) $stmt->fetchColumn();
}

function teacher_assignments_column_exists(PDO $db, string $table, string $column): bool
{
    $stmt = $db->prepare('SELECT 1 FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?');
    $stmt->execute([$table, $column]);
    return (bool) $stmt->fetchColumn();
}

function teacher_assignments_redirect(): void
{
    header('Location: assessment_teacher_assignments.php');
    exit();
}

function teacher_assignments_int_list($value): array
{
    if (!is_array($value)) {
        return [];
    }
    $ids = [];
    foreach ($value as $item) {
        $id = (int) $item;
        if ($id > 0) {
            $ids[$id] = $id;
        }
    }
    return array_values($ids);
}

/**
 * يبقى السجل التفصيلي محفوظًا، بينما يقدّم سجل النشاطات مقارنة قصيرة قابلة للمراجعة.
 * لا تمرر صفوف قاعدة البيانات كاملة إلى عرض الفروقات حتى لا تتحول إلى JSON داخل الجدول.
 */
function teacher_assignments_audit_summary(array $assignments, int $academicYearId, int $staffId): array
{
    $subjects = [];
    $wholeGrades = [];
    $classes = [];
    $activeCount = 0;
    $pendingCount = 0;
    $recordEnabledCount = 0;
    $reviewEnabledCount = 0;
    $requestedActiveCount = 0;

    foreach ($assignments as $assignment) {
        $subjectId = (int) ($assignment['subject_id'] ?? 0);
        $gradeId = (int) ($assignment['grade_id'] ?? 0);
        $classId = isset($assignment['class_id']) && $assignment['class_id'] !== null
            ? (int) $assignment['class_id']
            : 0;

        if ($subjectId > 0) {
            $subjects[$subjectId] = $subjectId;
        }
        if ($classId > 0) {
            $classes[$classId] = $classId;
        } elseif ($gradeId > 0) {
            $wholeGrades[$gradeId] = $gradeId;
        }

        $isRequestedActive = !empty($assignment['requested_active']);
        $isActive = !empty($assignment['is_active']);
        $recordEnabledCount += !empty($assignment['can_record']) ? 1 : 0;
        $reviewEnabledCount += !empty($assignment['can_review']) ? 1 : 0;
        $requestedActiveCount += $isRequestedActive ? 1 : 0;
        $activeCount += $isActive ? 1 : 0;
        $pendingCount += $isRequestedActive && !$isActive ? 1 : 0;
    }

    return [
        'academic_year' => $academicYearId,
        'teacher_id' => $staffId,
        'subjects' => array_values($subjects),
        'whole_grades' => array_values($wholeGrades),
        'classes' => array_values($classes),
        'assignment_count' => count($assignments),
        'record_enabled_count' => $recordEnabledCount,
        'review_enabled_count' => $reviewEnabledCount,
        'requested_active_count' => $requestedActiveCount,
        'active_count' => $activeCount,
        'pending_count' => $pendingCount,
    ];
}

function teacher_assignments_has_active_subject_scope(PDO $db, int $academicYearId, int $subjectId, int $gradeId, ?int $classId): bool
{
    if (!teacher_assignments_table_exists($db, 'subject_grade_assignments')) {
        return false;
    }

    $scopeSql = $classId === null
        ? 'AND class_id IS NULL'
        : 'AND (class_id = ? OR class_id IS NULL)';
    $stmt = $db->prepare("SELECT 1
        FROM subject_grade_assignments
        WHERE academic_year_id = ?
          AND subject_id = ?
          AND is_active = 1
          AND (grade_id = ? OR grade_id IS NULL)
          {$scopeSql}
        LIMIT 1");
    $params = [$academicYearId, $subjectId, $gradeId];
    if ($classId !== null) {
        $params[] = $classId;
    }
    $stmt->execute($params);
    return $stmt->fetchColumn() !== false;
}

$teacherAssignmentsReady = teacher_assignments_table_exists($db, 'teacher_subject_assignments');
$teacherAssignmentActivationReady = $teacherAssignmentsReady
    && teacher_assignments_column_exists($db, 'teacher_subject_assignments', 'requested_active')
    && teacher_assignments_column_exists($db, 'teacher_subject_assignments', 'pending_reason');
$calendarReady = teacher_assignments_table_exists($db, 'academic_years');
$currentAcademicYear = AcademicYear::getCurrent($db) ?: AcademicYear::getActive($db);
$currentAcademicYearId = $currentAcademicYear ? (int) $currentAcademicYear['id'] : 0;
$currentAcademicYearName = $currentAcademicYear['name'] ?? '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        (new AcademicYearWriteGuard($db))->assertWritable($currentAcademicYearId);
        if (!$teacherAssignmentsReady) {
            throw new RuntimeException('جدول تعيينات المعلمين غير مطبق بعد.');
        }
        if (!$teacherAssignmentActivationReady) {
            throw new RuntimeException('يلزم تطبيق ترقية حالة تعيينات المعلمين قبل الحفظ.');
        }

        $action = (string) ($_POST['action'] ?? '');
        if ($action !== 'save_staff_assignments') {
            throw new InvalidArgumentException('عملية غير معروفة.');
        }

        $academicYearId = $currentAcademicYearId > 0 ? $currentAcademicYearId : (int) ($_POST['academic_year_id'] ?? 0);
        $staffId = (int) ($_POST['staff_id'] ?? 0);
        $subjectIds = teacher_assignments_int_list($_POST['subjects'] ?? []);
        $classIds = teacher_assignments_int_list($_POST['classes'] ?? []);
        $wholeGradeIds = teacher_assignments_int_list($_POST['all_grade_ids'] ?? []);
        $canRecord = isset($_POST['can_record']) ? 1 : 0;
        $canReview = isset($_POST['can_review']) ? 1 : 0;
        $isActive = isset($_POST['is_active']) ? 1 : 0;

        if ($academicYearId <= 0) {
            throw new InvalidArgumentException('لا يوجد عام دراسي محدد.');
        }
        if ($staffId <= 0) {
            throw new InvalidArgumentException('اختر العامل.');
        }
        if (empty($subjectIds)) {
            throw new InvalidArgumentException('اختر مادة واحدة على الأقل.');
        }
        if (empty($classIds) && empty($wholeGradeIds)) {
            throw new InvalidArgumentException('اختر صفاً كاملاً أو فصلاً واحداً على الأقل.');
        }

        $staffStmt = $db->prepare("SELECT u.id, u.name, COALESCE(NULLIF(sp.full_name_ar, ''), u.name) AS display_name
            FROM users u
            LEFT JOIN staff_profiles sp ON sp.user_id = u.id
            WHERE u.id = ? AND EXISTS (
                SELECT 1 FROM user_role_assignments ura
                WHERE ura.user_id = u.id AND ura.role_key = 'teacher' AND ura.status = 'active'
            )
            LIMIT 1");
        $staffStmt->execute([$staffId]);
        $staff = $staffStmt->fetch(PDO::FETCH_ASSOC);
        if (!$staff) {
            throw new InvalidArgumentException('المعلم المحدد غير موجود أو لا يحمل دور معلم.');
        }

        $subjectPlaceholders = implode(',', array_fill(0, count($subjectIds), '?'));
        $subjectStmt = $db->prepare("SELECT id FROM subjects WHERE id IN ($subjectPlaceholders) AND COALESCE(is_active, 1) = 1");
        $subjectStmt->execute($subjectIds);
        $validSubjectIds = array_map('intval', $subjectStmt->fetchAll(PDO::FETCH_COLUMN));
        if (count($validSubjectIds) !== count($subjectIds)) {
            throw new InvalidArgumentException('توجد مادة غير صحيحة أو غير نشطة.');
        }

        $wholeGradeScopeIds = [];
        if (!empty($wholeGradeIds)) {
            $gradePlaceholders = implode(',', array_fill(0, count($wholeGradeIds), '?'));
            $gradeStmt = $db->prepare("SELECT id FROM grades WHERE id IN ($gradePlaceholders) AND status = 'active'");
            $gradeStmt->execute($wholeGradeIds);
            $validGradeIds = array_map('intval', $gradeStmt->fetchAll(PDO::FETCH_COLUMN));
            if (count($validGradeIds) !== count($wholeGradeIds)) {
                throw new InvalidArgumentException('يوجد صف غير صحيح أو غير نشط.');
            }
            $wholeGradeScopeIds = $validGradeIds;
        }

        if (empty($classIds) && empty($wholeGradeScopeIds)) {
            throw new InvalidArgumentException('لا توجد فصول نشطة ضمن الصفوف المحددة.');
        }

        $classRows = [];
        if (!empty($classIds)) {
            $classPlaceholders = implode(',', array_fill(0, count($classIds), '?'));
            $classStmt = $db->prepare("SELECT c.id, c.grade_id
                FROM classes c
                WHERE c.id IN ($classPlaceholders) AND c.status = 'active'");
            $classStmt->execute($classIds);
            $classRows = $classStmt->fetchAll(PDO::FETCH_ASSOC);
            if (count($classRows) !== count($classIds)) {
                throw new InvalidArgumentException('يوجد فصل غير صحيح أو غير نشط.');
            }
        }
        $classGradeMap = [];
        foreach ($classRows as $classRow) {
            $classGradeMap[(int) $classRow['id']] = (int) $classRow['grade_id'];
        }

        foreach ($classGradeMap as $classGradeId) {
            if (in_array($classGradeId, $wholeGradeScopeIds, true)) {
                throw new InvalidArgumentException('لا يمكن اختيار الصف بالكامل وفصول محددة منه في الوقت نفسه.');
            }
        }

        $assignmentScopes = [];
        foreach ($wholeGradeScopeIds as $gradeId) {
            $assignmentScopes[] = ['grade_id' => $gradeId, 'class_id' => null];
        }
        foreach ($classIds as $classId) {
            $assignmentScopes[] = ['grade_id' => $classGradeMap[$classId], 'class_id' => $classId];
        }

        $db->beginTransaction();
        $oldStmt = $db->prepare('SELECT * FROM teacher_subject_assignments WHERE academic_year_id = ? AND teacher_id = ?');
        $oldStmt->execute([$academicYearId, $staffId]);
        $oldAssignments = $oldStmt->fetchAll(PDO::FETCH_ASSOC);

        $db->prepare('DELETE FROM teacher_subject_assignments WHERE academic_year_id = ? AND teacher_id = ?')->execute([$academicYearId, $staffId]);

        $insertStmt = $db->prepare("INSERT INTO teacher_subject_assignments
            (academic_year_id, term_id, teacher_id, subject_id, grade_id, class_id, starts_at, ends_at,
             can_record, can_review, requested_active, is_active, pending_reason)
            VALUES (?, NULL, ?, ?, ?, ?, NULL, NULL, ?, ?, ?, ?, ?)");

        $created = 0;
        $pending = 0;
        $active = 0;
        foreach ($validSubjectIds as $subjectId) {
            foreach ($assignmentScopes as $scope) {
                $gradeId = (int) ($scope['grade_id'] ?? 0);
                $classId = $scope['class_id'] !== null ? (int) $scope['class_id'] : null;
                if ($gradeId <= 0) {
                    throw new InvalidArgumentException('نطاق التعيين المختار لا يتبع صفاً صحيحاً.');
                }
                $hasActiveSubjectLink = teacher_assignments_has_active_subject_scope(
                    $db,
                    $academicYearId,
                    $subjectId,
                    $gradeId,
                    $classId
                );
                $effectiveActive = $isActive === 1 && $hasActiveSubjectLink ? 1 : 0;
                $pendingReason = $isActive === 1 && !$hasActiveSubjectLink ? 'missing_subject_link' : null;
                $insertStmt->execute([
                    $academicYearId,
                    $staffId,
                    $subjectId,
                    $gradeId,
                    $classId,
                    $canRecord,
                    $canReview,
                    $isActive,
                    $effectiveActive,
                    $pendingReason,
                ]);
                if ($effectiveActive === 1) {
                    $active++;
                } elseif ($isActive === 1) {
                    $pending++;
                }
                $created++;
            }
        }

        $auditBefore = teacher_assignments_audit_summary($oldAssignments, $academicYearId, $staffId);
        $auditAfter = [
            'academic_year' => $academicYearId,
            'teacher_id' => $staffId,
            'subjects' => $validSubjectIds,
            'whole_grades' => $wholeGradeScopeIds,
            'classes' => $classIds,
            'assignment_count' => $created,
            'record_enabled_count' => $canRecord ? $created : 0,
            'review_enabled_count' => $canReview ? $created : 0,
            'requested_active_count' => $isActive ? $created : 0,
            'active_count' => $active,
            'pending_count' => $pending,
        ];
        if (!ActivityLog::logChange(
            'update',
            'teacher_subject_assignment',
            $staffId,
            'تعيينات المعلمين',
            $auditBefore,
            $auditAfter,
            ['source' => 'assessment_teacher_assignments'],
            [
                'summary' => 'تحديث توزيع معلم للعام الدراسي',
                'audit_snapshot' => ['previous_assignments' => $oldAssignments],
            ]
        )) {
            throw new RuntimeException('تعذر تسجيل تحديث تعيينات المعلم في سجل التدقيق.');
        }
        $db->commit();

        $_SESSION['success_message'] = 'تم حفظ تعيينات ' . $staff['display_name'] . ' للعام الدراسي الحالي.'
            . ($pending > 0 ? ' يوجد ' . $pending . ' تعيين بانتظار ربط المادة بالنطاق المحدد.' : '');
    } catch (Throwable $e) {
        if ($db->inTransaction()) {
            $db->rollBack();
        }
        $_SESSION['error_message'] = $e->getMessage();
    }

    teacher_assignments_redirect();
}

$academicYears = [];
$stages = [];
$grades = [];
$classes = [];
$subjects = [];
$staffRows = [];
$assignmentRows = [];
$assignmentByStaff = [];
$jobTitles = [];
$activeAssignmentCount = 0;
$recordCount = 0;
$reviewCount = 0;

if ($calendarReady) {
    $academicYears = $db->query("SELECT id, name, is_active FROM academic_years WHERE status = 'active' ORDER BY is_active DESC, id DESC")->fetchAll(PDO::FETCH_ASSOC) ?: [];
}

$stages = $db->query("SELECT id, stage_name FROM stages WHERE status = 'active' ORDER BY stage_order, id")->fetchAll(PDO::FETCH_ASSOC) ?: [];
$grades = $db->query("SELECT id, grade_name, stage_id FROM grades WHERE status = 'active' ORDER BY stage_id, grade_order, id")->fetchAll(PDO::FETCH_ASSOC) ?: [];
$classes = $db->query("SELECT c.id, c.name, c.grade_id, g.stage_id
    FROM classes c
    LEFT JOIN grades g ON g.id = c.grade_id
    WHERE c.status = 'active'
    ORDER BY g.stage_id, c.grade_id, c.display_order, c.name")->fetchAll(PDO::FETCH_ASSOC) ?: [];

$gradesByStage = [];
foreach ($grades as $g) {
    $gradesByStage[(int)$g['stage_id']][] = $g;
}
$classesByGrade = [];
foreach ($classes as $c) {
    $classesByGrade[(int)$c['grade_id']][] = $c;
}

$subjectOrderExpr = teacher_assignments_column_exists($db, 'subjects', 'sort_order')
    ? 'sort_order'
    : (teacher_assignments_column_exists($db, 'subjects', 'default_order') ? 'default_order' : 'id');
$subjects = $db->query("SELECT id, name FROM subjects WHERE COALESCE(is_active, 1) = 1 ORDER BY {$subjectOrderExpr}, name")->fetchAll(PDO::FETCH_ASSOC) ?: [];

$jobTitleRows = $db->query("SELECT DISTINCT job_title FROM staff_profiles WHERE job_title IS NOT NULL AND job_title <> '' ORDER BY job_title")->fetchAll(PDO::FETCH_COLUMN) ?: [];
foreach (StaffEmploymentLifecycleService::canonicalJobTitleOptionsFromValues($jobTitleRows) as $title) {
    $jobTitles[$title] = $title;
}
$teacherAssignmentList = new AssessmentTeacherAssignmentListQuery($db);
$assignmentSummary = $teacherAssignmentList->summary($currentAcademicYearId);

require_once '../includes/admin_header.php';
?>

<div class="teacher-assignments-page">

<div class="admin-page-heading">
    <h1 class="h2"><i class="fas fa-chalkboard-user me-2 text-primary"></i>تعيينات المعلمين</h1>
</div>

<?php if (!empty($success_message)): ?>
    <div class="alert alert-success alert-dismissible fade show" role="alert">
        <i class="fas fa-check-circle me-2"></i><?php echo htmlspecialchars($success_message, ENT_QUOTES, 'UTF-8'); ?>
        <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
    </div>
<?php endif; ?>
<?php if (!empty($error_message)): ?>
    <div class="alert alert-danger alert-dismissible fade show" role="alert">
        <i class="fas fa-exclamation-circle me-2"></i><?php echo htmlspecialchars($error_message, ENT_QUOTES, 'UTF-8'); ?>
        <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
    </div>
<?php endif; ?>

<?php if (!$teacherAssignmentsReady): ?>
    <div class="alert alert-warning"><i class="fas fa-clock me-2"></i>جدول تعيينات المعلمين غير مطبق بعد.</div>
<?php else: ?>
<div class="row row-cols-2 row-cols-md-4 g-3 mb-4">
    <div class="col"><div class="stat-card" style="--card-gradient: linear-gradient(135deg, #3b82f6, #2563eb);"><div class="stat-card-icon"><i class="fas fa-users"></i></div><div class="stat-card-info"><div class="stat-card-number counter" data-target="<?php echo (int)$assignmentSummary['staff_count']; ?>">0</div><div class="stat-card-label">العاملون</div></div></div></div>
    <div class="col"><div class="stat-card" style="--card-gradient: linear-gradient(135deg, #10b981, #059669);"><div class="stat-card-icon"><i class="fas fa-chalkboard-teacher"></i></div><div class="stat-card-info"><div class="stat-card-number counter" data-target="<?php echo (int)$assignmentSummary['assigned_staff_count']; ?>">0</div><div class="stat-card-label">لهم تعيينات</div></div></div></div>
    <div class="col"><div class="stat-card" style="--card-gradient: linear-gradient(135deg, #f59e0b, #d97706);"><div class="stat-card-icon"><i class="fas fa-pen"></i></div><div class="stat-card-info"><div class="stat-card-number counter" data-target="<?php echo (int)$assignmentSummary['record_count']; ?>">0</div><div class="stat-card-label">صلاحيات رصد</div></div></div></div>
    <div class="col"><div class="stat-card" style="--card-gradient: linear-gradient(135deg, #0ea5e9, #0284c7);"><div class="stat-card-icon"><i class="fas fa-user-check"></i></div><div class="stat-card-info"><div class="stat-card-number counter" data-target="<?php echo (int)$assignmentSummary['review_count']; ?>">0</div><div class="stat-card-label">صلاحيات مراجعة</div></div></div></div>
</div>

<div class="admin-list-surface">
        <div class="admin-filter-bar">
            <div class="admin-filter-controls">
                    <select class="form-select form-select-sm admin-inline-select-sm" id="filterJobTitle">
                        <option value="">كل المسميات</option>
                        <?php foreach ($jobTitles as $title): ?>
                            <option value="<?php echo htmlspecialchars($title, ENT_QUOTES, 'UTF-8'); ?>"><?php echo htmlspecialchars($title, ENT_QUOTES, 'UTF-8'); ?></option>
                        <?php endforeach; ?>
                    </select>
                    <select class="form-select form-select-sm admin-inline-select-sm" id="filterStage">
                        <option value="">كل المراحل</option>
                        <?php foreach ($stages as $stage): ?>
                            <option value="<?php echo (int)$stage['id']; ?>"><?php echo htmlspecialchars($stage['stage_name'], ENT_QUOTES, 'UTF-8'); ?></option>
                        <?php endforeach; ?>
                    </select>
                    <select class="form-select form-select-sm admin-inline-select-sm" id="filterGrade">
                        <option value="">كل الصفوف</option>
                        <?php foreach ($grades as $grade): ?>
                            <option value="<?php echo (int)$grade['id']; ?>" data-stage="<?php echo (int)$grade['stage_id']; ?>"><?php echo htmlspecialchars($grade['grade_name'], ENT_QUOTES, 'UTF-8'); ?></option>
                        <?php endforeach; ?>
                    </select>
                    <select class="form-select form-select-sm admin-inline-select-sm" id="filterClass">
                        <option value="">كل الفصول</option>
                        <?php foreach ($classes as $class): ?>
                            <option value="<?php echo (int)$class['id']; ?>" data-grade="<?php echo (int)$class['grade_id']; ?>" data-stage="<?php echo (int)$class['stage_id']; ?>"><?php echo htmlspecialchars($class['name'], ENT_QUOTES, 'UTF-8'); ?></option>
                        <?php endforeach; ?>
                    </select>
            </div>
            <div class="admin-filter-actions">
                <button type="button" class="btn btn-light btn-sm" id="resetFilters"><i class="fas fa-rotate-left me-1"></i>إعادة تعيين</button>
                <button type="button" class="btn btn-light btn-sm" data-bs-toggle="modal" data-bs-target="#tableSettingsModal"><i class="fas fa-cog me-1"></i>إعدادات الجدول</button>
            </div>
        </div>
        <div class="table-responsive admin-table-wrap">
            <table class="table table-hover table-striped align-middle admin-data-table" id="teacherAssignmentsTable">
                <thead>
                    <tr>
                        <th>م</th>
                        <th>الاسم</th>
                        <th>المسمى الوظيفي</th>
                        <th>الدور</th>
                        <th>الحالة الوظيفية</th>
                        <th>المادة</th>
                        <th>التعيينات</th>
                        <th>صلاحيات الحساب</th>
                        <th>الإجراءات</th>
                    </tr>
                </thead>
                <tbody><tr><td colspan="9" class="text-center text-muted py-5">جاري تحميل التعيينات…</td></tr></tbody>
            </table>
        </div>
    </div>

<div class="modal fade" id="assignStaffModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-xl modal-dialog-scrollable assessment-subject-assignment-modal-dialog">
        <form method="post" action="assessment_teacher_assignments.php" class="modal-content admin-modal admin-modal-premium admin-modal-edit assessment-subject-assignment-modal teacher-assignment-modal" data-no-form-safety="true">
                <?php echo csrfField(); ?>
                <input type="hidden" name="action" value="save_staff_assignments">
                <input type="hidden" name="staff_id" id="assignStaffId">
                <?php if ($currentAcademicYearId > 0): ?>
                    <input type="hidden" name="academic_year_id" value="<?php echo (int)$currentAcademicYearId; ?>">
                <?php endif; ?>
                <div class="modal-header">
                    <h5 class="modal-title d-flex align-items-center flex-wrap gap-1">
                        <i class="fas fa-chalkboard-user me-1 text-primary"></i>تخصيص تعيينات المعلم:
                        <strong id="assignStaffNameHeader" class="text-primary me-1">—</strong>
                    </h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="إغلاق"></button>
                </div>
                <div class="modal-body assessment-subject-assignment-modal-body">
                    <div class="alert alert-info py-2 mb-3 d-flex align-items-center">
                        <i class="fas fa-calendar-alt me-2"></i>
                        نطاق التعيين للعام الدراسي: <strong><?php echo htmlspecialchars($currentAcademicYearName ?: 'العام الحالي', ENT_QUOTES, 'UTF-8'); ?></strong>
                    </div>

                    <div class="row g-3 teacher-assignment-scope-columns">
                        <!-- Subjects Section -->
                        <div class="col-lg-3">
                            <div class="border rounded-3 p-2 bg-white shadow-sm">
                                <div class="d-flex align-items-center justify-content-between p-2 mb-2 bg-light rounded border-start border-3 border-primary">
                                    <span class="fw-bold text-dark"><i class="fas fa-book text-primary me-2"></i>المواد الدراسية</span>
                                    <button type="button" class="btn btn-sm btn-outline-primary py-0 px-2 small" id="selectAllSubjects">
                                        <i class="fas fa-check-double me-1"></i>تحديد الكل
                                    </button>
                                </div>
                                <div class="px-2 py-2 teacher-assignment-fixed-list">
                                    <div class="row row-cols-1 g-2">
                                        <?php foreach ($subjects as $subject): ?>
                                            <div class="col">
                                                <div class="p-2 px-3 rounded border bg-light-subtle d-flex align-items-center">
                                                    <input class="form-check-input assign-subject-checkbox me-2 mt-0" type="checkbox" name="subjects[]" value="<?php echo (int)$subject['id']; ?>" id="assign_subject_<?php echo (int)$subject['id']; ?>" autocomplete="off">
                                                    <label class="form-check-label fw-semibold text-dark cursor-pointer flex-grow-1 mb-0" for="assign_subject_<?php echo (int)$subject['id']; ?>">
                                                        <i class="fas fa-book-open text-primary me-2"></i><?php echo htmlspecialchars($subject['name'], ENT_QUOTES, 'UTF-8'); ?>
                                                    </label>
                                                </div>
                                            </div>
                                        <?php endforeach; ?>
                                    </div>
                                </div>
                            </div>
                        </div>

                        <!-- Unified academic scope selector -->
                        <div class="col-lg-9">
                            <section class="border rounded-3 p-2 bg-white shadow-sm assignment-scope-panel assignment-scope-panel-expanded" aria-labelledby="assignScopeTitle">
                                <div class="d-flex align-items-center justify-content-between gap-2 p-2 mb-2 bg-light rounded border-start border-3 border-primary">
                                    <span class="fw-bold text-dark" id="assignScopeTitle"><i class="fas fa-school text-primary me-2"></i>الصفوف والفصول</span>
                                    <button type="button" class="btn btn-sm btn-outline-primary py-0 px-2 small" id="selectAllAssignmentGrades" <?php echo empty($grades) ? 'disabled' : ''; ?>>
                                        <i class="fas fa-check-double me-1"></i>تحديد كل الصفوف
                                    </button>
                                </div>
                                <div class="px-2 py-2 assignment-scope-list" id="assignClassesBox">
                                    <?php foreach ($stages as $stage): ?>
                                        <?php
                                        $stageId = (int) $stage['id'];
                                        $stageGrades = $gradesByStage[$stageId] ?? [];
                                        if (empty($stageGrades)) continue;
                                        ?>
                                        <div class="stage-group assignment-stage-group mb-3" data-stage-id="<?php echo $stageId; ?>">
                                            <div class="d-flex align-items-center justify-content-between gap-2 p-2 px-3 mb-2 rounded border bg-light shadow-sm">
                                                <div class="d-flex align-items-center gap-2">
                                                    <i class="fas fa-graduation-cap text-primary"></i>
                                                    <span class="fw-bold text-dark"><?php echo htmlspecialchars($stage['stage_name'], ENT_QUOTES, 'UTF-8'); ?></span>
                                                    <span class="badge bg-secondary"><?php echo count($stageGrades); ?> صفوف</span>
                                                </div>
                                                <button type="button" class="btn btn-sm btn-outline-primary py-0 px-2 small select-assignment-stage-btn">
                                                    <i class="fas fa-check-double me-1"></i>تحديد المرحلة
                                                </button>
                                            </div>

                                            <div class="row g-2">
                                                <?php foreach ($stageGrades as $grade): ?>
                                                    <?php
                                                    $gradeId = (int) $grade['id'];
                                                    $gradeClasses = $classesByGrade[$gradeId] ?? [];
                                                    ?>
                                                    <div class="col-md-6 col-xl-4">
                                                        <div class="border rounded-3 p-2 bg-white shadow-sm h-100 assignment-grade-card" data-grade-id="<?php echo $gradeId; ?>">
                                                            <div class="d-flex align-items-center justify-content-between gap-2 p-2 mb-2 bg-light rounded border-start border-3 border-primary">
                                                                <div class="d-flex align-items-center gap-2">
                                                                    <input class="form-check-input assignment-grade-checkbox mt-0" type="checkbox" name="all_grade_ids[]" value="<?php echo $gradeId; ?>" id="assign_grade_<?php echo $gradeId; ?>" data-stage-id="<?php echo $stageId; ?>" <?php echo empty($gradeClasses) ? 'disabled' : ''; ?> autocomplete="off">
                                                                    <label class="fw-bold text-dark cursor-pointer mb-0" for="assign_grade_<?php echo $gradeId; ?>"><?php echo htmlspecialchars($grade['grade_name'], ENT_QUOTES, 'UTF-8'); ?></label>
                                                                    <span class="badge bg-light text-dark border assignment-grade-scope-badge">غير محدد</span>
                                                                </div>
                                                            </div>

                                                            <div class="px-2 py-1 assignment-class-options" data-grade-id="<?php echo $gradeId; ?>">
                                                                <?php if (empty($gradeClasses)): ?>
                                                                    <div class="text-muted small">لا توجد فصول نشطة؛ لا يمكن إسناد هذا الصف حتى يُنشأ فصل نشط.</div>
                                                                <?php else: ?>
                                                                    <div class="row row-cols-2 g-2">
                                                                        <?php foreach ($gradeClasses as $class): ?>
                                                                            <?php $classId = (int) $class['id']; ?>
                                                                            <div class="col">
                                                                                <div class="form-check mb-1">
                                                                                    <input class="form-check-input assignment-class-checkbox" type="checkbox" name="classes[]" value="<?php echo $classId; ?>" id="assign_class_<?php echo $classId; ?>" data-grade-id="<?php echo $gradeId; ?>" autocomplete="off">
                                                                                    <label class="form-check-label small fw-semibold cursor-pointer" for="assign_class_<?php echo $classId; ?>">
                                                                                        <?php echo htmlspecialchars($class['name'], ENT_QUOTES, 'UTF-8'); ?>
                                                                                    </label>
                                                                                </div>
                                                                            </div>
                                                                        <?php endforeach; ?>
                                                                    </div>
                                                                <?php endif; ?>
                                                            </div>
                                                        </div>
                                                    </div>
                                                <?php endforeach; ?>
                                            </div>
                                        </div>
                                    <?php endforeach; ?>
                                </div>
                                <div class="alert alert-danger py-2 mt-2 mb-0 d-none" id="assignScopeFeedback" role="alert">
                                    <i class="fas fa-exclamation-circle me-1"></i>اختر صفاً كاملاً أو فصلاً واحداً على الأقل.
                                </div>
                            </section>
                        </div>

                    </div>
                    <!-- Permissions stay visible below the scrollable academic selector. -->
                    <div class="teacher-assignment-permissions mt-3 pt-3">
                        <div class="card bg-light border border-secondary border-opacity-10 shadow-none rounded-3">
                            <div class="card-body py-3 px-4">
                                <h6 class="fw-bold mb-3 text-secondary">
                                    <i class="fas fa-user-shield me-2 text-primary"></i>صلاحيات وخيارات التعيين
                                </h6>
                                <div class="d-flex flex-wrap gap-4 align-items-center">
                                    <div class="form-check form-switch">
                                        <input class="form-check-input" type="checkbox" name="is_active" id="assignIsActive" checked>
                                        <label class="form-check-label fw-bold text-dark cursor-pointer" for="assignIsActive">التعيين نشط</label>
                                    </div>
                                    <div class="form-check form-switch">
                                        <input class="form-check-input" type="checkbox" name="can_record" id="assignCanRecord" checked>
                                        <label class="form-check-label fw-bold text-dark cursor-pointer" for="assignCanRecord">صلاحية الرصد</label>
                                    </div>
                                    <div class="form-check form-switch">
                                        <input class="form-check-input" type="checkbox" name="can_review" id="assignCanReview">
                                        <label class="form-check-label fw-bold text-dark cursor-pointer" for="assignCanReview">صلاحية المراجعة</label>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal"><i class="fas fa-times me-1"></i>إلغاء</button>
                    <button type="submit" class="btn btn-primary"><i class="fas fa-save me-1"></i>حفظ التعيينات</button>
                </div>
        </form>
    </div>
</div>

<div class="modal fade" id="tableSettingsModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog">
        <div class="modal-content admin-modal admin-modal-premium admin-modal-view">
            <div class="modal-header">
                <h5 class="modal-title"><i class="fas fa-cog me-2"></i>إعدادات أعمدة الجدول</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <p class="text-muted small">اختر الأعمدة التي تريد عرضها في الجدول:</p>
                <div class="row g-2">
                    <?php
                    $columnSettings = [
                        'col_serial' => 'م',
                        'col_name' => 'الاسم',
                        'col_job' => 'المسمى الوظيفي',
                        'col_roles' => 'الدور',
                        'col_status' => 'الحالة الوظيفية',
                        'col_subjects' => 'المادة',
                        'col_classes' => 'التعيينات',
                        'col_permissions' => 'صلاحيات الحساب',
                        'col_actions' => 'الإجراءات',
                    ];
                    foreach ($columnSettings as $id => $label):
                    ?>
                        <div class="col-6">
                            <div class="form-check">
                                <input class="form-check-input" type="checkbox" id="<?php echo $id; ?>" checked>
                                <label class="form-check-label" for="<?php echo $id; ?>"><?php echo $label; ?></label>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal"><i class="fas fa-check me-1"></i>إغلاق</button>
            </div>
        </div>
    </div>
</div>

<script src="../assets/js/admin-server-side-table.js"></script>
<script src="../assets/js/admin_table_actions.js"></script>
<script>
document.addEventListener('DOMContentLoaded', function () {
    var filterJobTitle = document.getElementById('filterJobTitle');
    var filterStage = document.getElementById('filterStage');
    var filterGrade = document.getElementById('filterGrade');
    var filterClass = document.getElementById('filterClass');
    var table = window.AdminServerSideTable && window.AdminServerSideTable.init({
        selector: '#teacherAssignmentsTable',
        url: 'ajax_assessment_teacher_assignments_datatable.php',
        order: [[1, 'asc']],
        language: { processing: '<i class="fas fa-spinner fa-spin me-2"></i>جاري تحميل التعيينات…', emptyTable: 'لا توجد تعيينات مطابقة.' },
        requestData: function () {
            return {
                academic_year_id: <?php echo (int)$currentAcademicYearId; ?>,
                job_title: filterJobTitle.value,
                stage_id: filterStage.value,
                grade_id: filterGrade.value,
                class_id: filterClass.value
            };
        }
    });

    function updateFilterOptions() {
        var stageId = filterStage.value;
        filterGrade.value = stageId ? filterGrade.value : filterGrade.value;
        filterGrade.querySelectorAll('option[data-stage]').forEach(function(opt) {
            opt.style.display = (!stageId || opt.getAttribute('data-stage') === stageId) ? '' : 'none';
        });

        var gradeId = filterGrade.value;
        filterClass.querySelectorAll('option[data-grade]').forEach(function(opt) {
            var stageOk = !stageId || opt.getAttribute('data-stage') === stageId;
            var gradeOk = !gradeId || opt.getAttribute('data-grade') === gradeId;
            opt.style.display = (stageOk && gradeOk) ? '' : 'none';
        });
    }

    function applyFilters() {
        updateFilterOptions();
        if (table) {
            table.ajax.reload();
        }
    }

    filterStage.addEventListener('change', function() {
        filterGrade.value = '';
        filterClass.value = '';
        applyFilters();
    });
    filterGrade.addEventListener('change', function() {
        filterClass.value = '';
        applyFilters();
    });
    [filterJobTitle, filterClass].forEach(function(el) {
        el.addEventListener('change', applyFilters);
    });
    document.getElementById('resetFilters').addEventListener('click', function() {
        filterJobTitle.value = '';
        filterStage.value = '';
        filterGrade.value = '';
        filterClass.value = '';
        applyFilters();
    });
    updateFilterOptions();

    // Select All Subjects
    var selectAllSubjectsBtn = document.getElementById('selectAllSubjects');
    if (selectAllSubjectsBtn) {
        selectAllSubjectsBtn.addEventListener('click', function() {
            var subjectCbs = document.querySelectorAll('.assign-subject-checkbox');
            var allChecked = Array.from(subjectCbs).every(function(cb) { return cb.checked; });
            subjectCbs.forEach(function(cb) {
                cb.checked = !allChecked;
            });
        });
    }

    var assignStaffForm = document.querySelector('#assignStaffModal form');
    var assignmentGradeInputs = Array.from(document.querySelectorAll('.assignment-grade-checkbox'));
    var assignmentClassInputs = Array.from(document.querySelectorAll('.assignment-class-checkbox'));
    var assignScopeFeedback = document.getElementById('assignScopeFeedback');

    function syncTeacherAssignmentScope() {
        document.querySelectorAll('.assignment-grade-card').forEach(function(card) {
            var gradeInput = card.querySelector('.assignment-grade-checkbox');
            var gradeSelected = Boolean(gradeInput && gradeInput.checked);
            var classInputs = Array.from(card.querySelectorAll('.assignment-class-checkbox'));
            classInputs.forEach(function(input) {
                input.disabled = gradeSelected;
                if (gradeSelected) input.checked = false;
            });

            var selectedClasses = classInputs.filter(function(input) { return input.checked; });
            var hasScope = gradeSelected || selectedClasses.length > 0;
            card.classList.toggle('border-primary', hasScope);
            card.classList.toggle('shadow', hasScope);

            var badge = card.querySelector('.assignment-grade-scope-badge');
            if (badge) {
                badge.className = hasScope
                    ? 'badge bg-primary-subtle text-primary border border-primary assignment-grade-scope-badge'
                    : 'badge bg-light text-dark border assignment-grade-scope-badge';
                badge.textContent = gradeSelected
                    ? 'الصف بالكامل'
                    : (selectedClasses.length > 0 ? selectedClasses.length + ' فصول' : 'غير محدد');
            }
        });

        document.querySelectorAll('.assignment-stage-group').forEach(function(stageGroup) {
            var stageGrades = Array.from(stageGroup.querySelectorAll('.assignment-grade-checkbox:not(:disabled)'));
            var allSelected = stageGrades.length > 0 && stageGrades.every(function(input) { return input.checked; });
            var stageButton = stageGroup.querySelector('.select-assignment-stage-btn');
            if (!stageButton) return;
            stageButton.classList.toggle('btn-primary', allSelected);
            stageButton.classList.toggle('btn-outline-primary', !allSelected);
            stageButton.innerHTML = allSelected
                ? '<i class="fas fa-times-circle me-1"></i>إلغاء تحديد المرحلة'
                : '<i class="fas fa-check-double me-1"></i>تحديد المرحلة';
        });

        var allGradesSelected = assignmentGradeInputs.filter(function(input) { return !input.disabled; }).length > 0
            && assignmentGradeInputs.filter(function(input) { return !input.disabled; }).every(function(input) { return input.checked; });
        var allGradesButton = document.getElementById('selectAllAssignmentGrades');
        if (allGradesButton) {
            allGradesButton.classList.toggle('btn-primary', allGradesSelected);
            allGradesButton.classList.toggle('btn-outline-primary', !allGradesSelected);
            allGradesButton.innerHTML = allGradesSelected
                ? '<i class="fas fa-times-circle me-1"></i>إلغاء تحديد الكل'
                : '<i class="fas fa-check-double me-1"></i>تحديد كل الصفوف';
        }

        var selectedWholeGrades = assignmentGradeInputs.filter(function(input) { return input.checked; });
        var selectedClasses = assignmentClassInputs.filter(function(input) { return input.checked && !input.disabled; });
        if (assignScopeFeedback && (selectedWholeGrades.length > 0 || selectedClasses.length > 0)) {
            assignScopeFeedback.classList.add('d-none');
        }
    }

    assignmentGradeInputs.forEach(function(input) {
        input.addEventListener('change', function() {
            if (this.checked) {
                assignmentClassInputs.forEach(function(classInput) {
                    if (classInput.dataset.gradeId === input.value) classInput.checked = false;
                });
            }
            syncTeacherAssignmentScope();
        });
    });

    assignmentClassInputs.forEach(function(input) {
        input.addEventListener('change', function() {
            if (this.checked) {
                var gradeInput = assignmentGradeInputs.find(function(candidate) {
                    return candidate.value === input.dataset.gradeId;
                });
                if (gradeInput) gradeInput.checked = false;
            }
            syncTeacherAssignmentScope();
        });
    });

    document.querySelectorAll('.select-assignment-stage-btn').forEach(function(button) {
        button.addEventListener('click', function() {
            var stageGroup = this.closest('.assignment-stage-group');
            if (!stageGroup) return;
            var stageGrades = Array.from(stageGroup.querySelectorAll('.assignment-grade-checkbox:not(:disabled)'));
            var allSelected = stageGrades.length > 0 && stageGrades.every(function(input) { return input.checked; });
            stageGrades.forEach(function(input) { input.checked = !allSelected; });
            syncTeacherAssignmentScope();
        });
    });

    var selectAllAssignmentGrades = document.getElementById('selectAllAssignmentGrades');
    if (selectAllAssignmentGrades) {
        selectAllAssignmentGrades.addEventListener('click', function() {
            var selectableGrades = assignmentGradeInputs.filter(function(input) { return !input.disabled; });
            var allSelected = selectableGrades.length > 0 && selectableGrades.every(function(input) { return input.checked; });
            selectableGrades.forEach(function(input) { input.checked = !allSelected; });
            syncTeacherAssignmentScope();
        });
    }

    if (assignStaffForm) {
        assignStaffForm.addEventListener('submit', function(event) {
            var hasScope = assignmentGradeInputs.some(function(input) { return input.checked; })
                || assignmentClassInputs.some(function(input) { return input.checked && !input.disabled; });
            if (hasScope) {
                if (assignScopeFeedback) assignScopeFeedback.classList.add('d-none');
                return;
            }
            event.preventDefault();
            if (assignScopeFeedback) {
                assignScopeFeedback.classList.remove('d-none');
                assignScopeFeedback.scrollIntoView({ behavior: 'smooth', block: 'nearest' });
            }
        });
    }

    document.getElementById('teacherAssignmentsTable').addEventListener('click', function(event) {
        var button = event.target.closest('.assign-staff-btn');
        if (!button || !this.contains(button)) return;
            var subjectIds = (button.dataset.subjectIds || '').split(',').filter(Boolean);
            var classIds = (button.dataset.classIds || '').split(',').filter(Boolean);
            var wholeGradeIds = (button.dataset.wholeGradeIds || '').split(',').filter(Boolean);
            document.getElementById('assignStaffId').value = button.dataset.staffId || '';
            
            // Set dynamic staff name in the modal title header
            var staffNameHeader = document.getElementById('assignStaffNameHeader');
            if (staffNameHeader) {
                staffNameHeader.textContent = button.dataset.staffName || '';
            }
            
            document.querySelectorAll('.assign-subject-checkbox').forEach(function(input) {
                input.checked = subjectIds.indexOf(input.value) !== -1;
            });
            assignmentGradeInputs.forEach(function(input) {
                input.checked = wholeGradeIds.indexOf(input.value) !== -1;
            });
            assignmentClassInputs.forEach(function(input) {
                input.checked = classIds.indexOf(input.value) !== -1;
                input.disabled = false;
            });
            document.getElementById('assignIsActive').checked = (button.dataset.isActive || '1') === '1';
            document.getElementById('assignCanRecord').checked = (button.dataset.canRecord || '0') === '1';
            document.getElementById('assignCanReview').checked = (button.dataset.canReview || '0') === '1';
            
            if (assignScopeFeedback) assignScopeFeedback.classList.add('d-none');
            syncTeacherAssignmentScope();
            
            new bootstrap.Modal(document.getElementById('assignStaffModal')).show();
    });

    syncTeacherAssignmentScope();

    syncTeacherAssignmentScope();

    if (typeof initializeTableColumnSettings === 'function') {
        initializeTableColumnSettings('teacherAssignmentsTable', {
            col_serial: 0,
            col_name: 1,
            col_job: 2,
            col_roles: 3,
            col_status: 4,
            col_subjects: 5,
            col_classes: 6,
            col_permissions: 7,
            col_actions: 8
        }, 'assessment_teacher_assignments_columns');
    }

    if (window.bootstrap && bootstrap.Tooltip) {
        document.querySelectorAll('[data-bs-toggle="tooltip"]').forEach(function(el) {
            new bootstrap.Tooltip(el);
        });
    }
});
</script>
<?php endif; ?>

</div>

<?php require_once '../includes/admin_footer.php'; ?>
