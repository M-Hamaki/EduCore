<?php
$page_title = "ربط المواد بالصفوف";
$custom_page_title = true;

require_once '../config/database.php';
require_once '../classes/utilities.php';
require_once '../classes/ActivityLog.php';
require_once '../classes/AssessmentSubjectAssignmentGroupService.php';
require_once '../classes/AssessmentTeacherAssignmentActivationService.php';
require_once '../classes/AcademicYear.php';
require_once '../classes/AcademicYearWriteGuard.php';
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

function subject_assignments_table_exists(PDO $db, string $table): bool
{
    $stmt = $db->prepare('SELECT 1 FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?');
    $stmt->execute([$table]);
    return (bool) $stmt->fetchColumn();
}

function subject_assignments_column_exists(PDO $db, string $table, string $column): bool
{
    $stmt = $db->prepare('SELECT 1 FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?');
    $stmt->execute([$table, $column]);
    return (bool) $stmt->fetchColumn();
}

function subject_assignments_teacher_activation_ready(PDO $db): bool
{
    return subject_assignments_table_exists($db, 'teacher_subject_assignments')
        && subject_assignments_column_exists($db, 'teacher_subject_assignments', 'requested_active')
        && subject_assignments_column_exists($db, 'teacher_subject_assignments', 'pending_reason');
}

function subject_assignments_redirect(): void
{
    header('Location: assessment_subject_assignments.php');
    exit();
}

$assignmentsReady = subject_assignments_table_exists($db, 'subject_grade_assignments');
$calendarReady = subject_assignments_table_exists($db, 'academic_years') && subject_assignments_table_exists($db, 'academic_terms');
$currentAcademicYear = AcademicYear::getCurrent($db) ?: AcademicYear::getActive($db);
$currentAcademicYearId = $currentAcademicYear ? (int) $currentAcademicYear['id'] : 0;
$currentAcademicYearName = $currentAcademicYear['name'] ?? '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        (new AcademicYearWriteGuard($db))->assertWritable($currentAcademicYearId);
        if (!$assignmentsReady) {
            throw new RuntimeException('جدول ربط المواد بالصفوف غير موجود بعد.');
        }

        $action = (string) ($_POST['action'] ?? '');

        if ($action === 'assign_subject_grade') {
            $groupService = new AssessmentSubjectAssignmentGroupService($db);
            $addResult = $groupService->add($_POST, $currentAcademicYearId, (int) ($_SESSION['user_id'] ?? 0));
            $_SESSION['success_message'] = 'تم حفظ ربط المادة: إضافة ' . (int) $addResult['created']
                . '، تحديث ' . (int) $addResult['updated']
                . ((int) ($addResult['teacher_activation']['activated'] ?? 0) > 0
                    ? '، وتفعيل ' . (int) $addResult['teacher_activation']['activated'] . ' تعيين معلم تلقائياً.'
                    : '.');
            subject_assignments_redirect();
        }

        if ($action === 'sync_subject_assignment_group') {
            $syncService = new AssessmentSubjectAssignmentGroupService($db);
            $syncResult = $syncService->sync($_POST, $currentAcademicYearId, (int) ($_SESSION['user_id'] ?? 0));
            $_SESSION['success_message'] = 'تم حفظ مجموعة الروابط: إضافة ' . (int) $syncResult['created']
                . '، تحديث ' . (int) $syncResult['updated']
                . '، إزالة ' . (int) $syncResult['deleted']
                . ((int) ($syncResult['teacher_activation']['activated'] ?? 0) > 0
                    ? '، وتفعيل ' . (int) $syncResult['teacher_activation']['activated'] . ' تعيين معلم تلقائياً.'
                    : '.');
            subject_assignments_redirect();
        }

        if ($action === 'set_subject_assignment_group_status') {
            $groupService = new AssessmentSubjectAssignmentGroupService($db);
            $updatedCount = $groupService->setStatus($_POST, $currentAcademicYearId);
            $_SESSION['success_message'] = $updatedCount > 0
                ? 'تم تحديث حالة مجموعة روابط المادة بنجاح.'
                : 'مجموعة روابط المادة بالحالة المطلوبة بالفعل.';
            subject_assignments_redirect();
        }

        if ($action === 'delete_subject_assignment_group') {
            $groupService = new AssessmentSubjectAssignmentGroupService($db);
            $deletedCount = $groupService->deleteGroup(
                $_POST,
                $currentAcademicYearId,
                (int) ($_SESSION['user_id'] ?? 0)
            );
            $_SESSION['success_message'] = 'تم حذف مجموعة روابط المادة بالكامل (' . $deletedCount . ' رابط).';
            subject_assignments_redirect();
        }

        if ($action === 'update_subject_assignment') {
            $assignmentId = (int) ($_POST['assignment_id'] ?? 0);
            $academicYearId = $currentAcademicYearId > 0 ? $currentAcademicYearId : (int) ($_POST['academic_year_id'] ?? 0);
            $termId = !empty($_POST['term_id']) ? (int) $_POST['term_id'] : null;
            $subjectId = (int) ($_POST['subject_id'] ?? 0);
            $stageId = !empty($_POST['stage_id']) ? (int) $_POST['stage_id'] : null;
            $gradeId = (int) ($_POST['grade_id'] ?? 0);
            $classId = !empty($_POST['class_id']) ? (int) $_POST['class_id'] : null;
            $notes = trim((string) ($_POST['notes'] ?? ''));
            $isActive = isset($_POST['is_active']) ? 1 : 0;

            if ($assignmentId <= 0 || $academicYearId <= 0 || $subjectId <= 0 || $gradeId <= 0) {
                throw new InvalidArgumentException('بيانات ربط المادة غير مكتملة.');
            }

            $oldStmt = $db->prepare('SELECT * FROM subject_grade_assignments WHERE id = ? LIMIT 1');
            $oldStmt->execute([$assignmentId]);
            $oldAssignment = $oldStmt->fetch(PDO::FETCH_ASSOC);
            if (!$oldAssignment) {
                throw new InvalidArgumentException('ربط المادة المحدد غير موجود.');
            }
            if ($currentAcademicYearId > 0 && (int) $oldAssignment['academic_year_id'] !== $currentAcademicYearId) {
                throw new InvalidArgumentException('لا يمكن تعديل ربط مادة خارج العام الدراسي المختار.');
            }

            if ($termId !== null) {
                $termStmt = $db->prepare('SELECT academic_year_id FROM academic_terms WHERE id = ? LIMIT 1');
                $termStmt->execute([$termId]);
                if ((int) $termStmt->fetchColumn() !== $academicYearId) {
                    throw new InvalidArgumentException('الترم المختار لا يتبع العام الدراسي المحدد.');
                }
            }

            $gradeStmt = $db->prepare("SELECT stage_id FROM grades WHERE id = ? AND status = 'active' LIMIT 1");
            $gradeStmt->execute([$gradeId]);
            $gradeStageId = (int) $gradeStmt->fetchColumn();
            if ($gradeStageId <= 0) {
                throw new InvalidArgumentException('الصف المختار غير صحيح أو غير نشط.');
            }
            if ($stageId !== null && $stageId !== $gradeStageId) {
                throw new InvalidArgumentException('الصف المختار لا يتبع المرحلة المحددة.');
            }

            if ($classId !== null) {
                $classStmt = $db->prepare("SELECT grade_id FROM classes WHERE id = ? AND status = 'active' LIMIT 1");
                $classStmt->execute([$classId]);
                if ((int) $classStmt->fetchColumn() !== $gradeId) {
                    throw new InvalidArgumentException('الفصل المختار لا يتبع الصف المحدد.');
                }
            }

            $duplicateStmt = $db->prepare("SELECT id
                FROM subject_grade_assignments
                WHERE academic_year_id = ?
                  AND term_id <=> ?
                  AND subject_id = ?
                  AND grade_id = ?
                  AND id <> ?
                  AND (class_id IS NULL OR ? IS NULL OR class_id = ?)
                LIMIT 1");
            $duplicateStmt->execute([$academicYearId, $termId, $subjectId, $gradeId, $assignmentId, $classId, $classId]);
            if ($duplicateStmt->fetchColumn()) {
                throw new InvalidArgumentException('يوجد ربط سابق لنفس المادة والصف يغطي هذا النطاق بالفعل.');
            }

            $db->beginTransaction();
            $stmt = $db->prepare("UPDATE subject_grade_assignments
                SET academic_year_id = ?, term_id = ?, subject_id = ?, stage_id = ?, grade_id = ?, class_id = ?, is_active = ?, notes = ?
                WHERE id = ?");
            $stmt->execute([
                $academicYearId,
                $termId,
                $subjectId,
                $gradeStageId,
                $gradeId,
                $classId,
                $isActive,
                $notes !== '' ? $notes : null,
                $assignmentId,
            ]);
            if (subject_assignments_teacher_activation_ready($db)) {
                $activationService = new AssessmentTeacherAssignmentActivationService($db);
                $activationService->synchronize(
                    $academicYearId,
                    $subjectId,
                    ['source' => 'single_subject_assignment_update']
                );
                if ((int) $oldAssignment['subject_id'] !== $subjectId) {
                    $activationService->synchronize(
                        $academicYearId,
                        (int) $oldAssignment['subject_id'],
                        ['source' => 'single_subject_assignment_update']
                    );
                }
            }
            if (!ActivityLog::logUpdate('subject_grade_assignment', $assignmentId, 'تعديل ربط مادة بصف', [
                'old_subject' => $oldAssignment['subject_id'],
                'new_subject' => $subjectId,
                'old_grade' => $oldAssignment['grade_id'],
                'new_grade' => $gradeId,
                'old_class_id' => $oldAssignment['class_id'],
                'new_class_id' => $classId,
                'is_active' => $isActive,
            ])) {
                throw new RuntimeException('تعذر حفظ سجل تدقيق تعديل ربط المادة.');
            }
            $db->commit();
            $_SESSION['success_message'] = 'تم تعديل ربط المادة بنجاح.';
            subject_assignments_redirect();
        }

        if ($action === 'toggle_subject_assignment') {
            $assignmentId = (int) ($_POST['assignment_id'] ?? 0);
            if ($assignmentId <= 0) {
                throw new InvalidArgumentException('ربط المادة غير محدد.');
            }

            $assignmentStmt = $db->prepare('SELECT academic_year_id, subject_id FROM subject_grade_assignments WHERE id = ? LIMIT 1');
            $assignmentStmt->execute([$assignmentId]);
            $assignment = $assignmentStmt->fetch(PDO::FETCH_ASSOC);
            if (!$assignment) {
                throw new InvalidArgumentException('ربط المادة المحدد غير موجود.');
            }
            if ($currentAcademicYearId > 0 && (int) $assignment['academic_year_id'] !== $currentAcademicYearId) {
                throw new InvalidArgumentException('لا يمكن تغيير حالة ربط مادة خارج العام الدراسي المختار.');
            }

            $db->beginTransaction();
            $stmt = $db->prepare('UPDATE subject_grade_assignments SET is_active = IF(is_active = 1, 0, 1) WHERE id = ?');
            $stmt->execute([$assignmentId]);
            if (subject_assignments_teacher_activation_ready($db)) {
                (new AssessmentTeacherAssignmentActivationService($db))->synchronize(
                    (int) $assignment['academic_year_id'],
                    (int) $assignment['subject_id'],
                    ['source' => 'single_subject_assignment_toggle']
                );
            }
            if (!ActivityLog::logUpdate('subject_grade_assignment', $assignmentId, 'تغيير حالة ربط مادة بصف', [
                'academic_year_id' => (int) $assignment['academic_year_id'],
            ])) {
                throw new RuntimeException('تعذر حفظ سجل تدقيق تغيير حالة ربط المادة.');
            }
            $db->commit();
            $_SESSION['success_message'] = 'تم تغيير حالة ربط المادة.';
            subject_assignments_redirect();
        }

        if ($action === 'delete_subject_assignment') {
            $assignmentId = (int) ($_POST['assignment_id'] ?? 0);
            $assignmentStmt = $db->prepare('SELECT * FROM subject_grade_assignments WHERE id = ? LIMIT 1');
            $assignmentStmt->execute([$assignmentId]);
            $assignment = $assignmentStmt->fetch(PDO::FETCH_ASSOC);
            if (!$assignment) {
                throw new InvalidArgumentException('ربط المادة المحدد غير موجود.');
            }
            if ($currentAcademicYearId > 0 && (int) $assignment['academic_year_id'] !== $currentAcademicYearId) {
                throw new InvalidArgumentException('لا يمكن حذف ربط مادة خارج العام الدراسي المختار.');
            }

            $dependencyCount = 0;
            if (subject_assignments_table_exists($db, 'assessment_schemes')) {
                $stmt = $db->prepare('SELECT COUNT(*) FROM assessment_schemes WHERE subject_assignment_id = ?');
                $stmt->execute([$assignmentId]);
                $dependencyCount += (int) $stmt->fetchColumn();
            }
            if (subject_assignments_table_exists($db, 'teacher_subject_assignments')) {
                $stmt = $db->prepare("SELECT COUNT(*)
                    FROM teacher_subject_assignments
                    WHERE academic_year_id = ?
                      AND subject_id = ?
                      AND (term_id <=> ? OR term_id IS NULL OR ? IS NULL)
                      AND (grade_id <=> ? OR grade_id IS NULL)
                      AND (class_id <=> ? OR class_id IS NULL)
                      AND is_active = 1");
                $stmt->execute([
                    (int) $assignment['academic_year_id'],
                    (int) $assignment['subject_id'],
                    $assignment['term_id'],
                    $assignment['term_id'],
                    $assignment['grade_id'],
                    $assignment['class_id'],
                ]);
                $dependencyCount += (int) $stmt->fetchColumn();
            }
            if ($dependencyCount > 0) {
                throw new RuntimeException('لا يمكن حذف هذا الربط لوجود خطط درجات أو تعيينات معلمين مرتبطة به. عطّله بدلا من الحذف.');
            }

            $db->prepare('DELETE FROM subject_grade_assignments WHERE id = ?')->execute([$assignmentId]);
            ActivityLog::logDelete('subject_grade_assignment', $assignmentId, 'حذف ربط مادة بصف', [
                'subject' => $assignment['subject_id'],
                'grade' => $assignment['grade_id'],
                'class_id' => $assignment['class_id'],
            ]);
            $_SESSION['success_message'] = 'تم حذف ربط المادة بنجاح.';
            subject_assignments_redirect();
        }
    } catch (Throwable $e) {
        if ($db->inTransaction()) {
            $db->rollBack();
        }
        $_SESSION['error_message'] = $e->getMessage();
        subject_assignments_redirect();
    }
}

$academicYears = [];
$terms = [];
$subjects = [];
$stages = [];
$grades = [];
$classes = [];
$subjectAssignments = [];
$assignmentDetailsBySubject = [];
$totalAssignmentsCount = 0;
$activeAssignmentsCount = 0;
$inactiveAssignmentsCount = 0;
$linkedSubjectsCount = 0;
$linkedGradesCount = 0;

if ($calendarReady) {
    $academicYears = $db->query("SELECT id, name, is_active FROM academic_years WHERE status = 'active' ORDER BY is_active DESC, id DESC")->fetchAll(PDO::FETCH_ASSOC) ?: [];
    if ($currentAcademicYearId > 0) {
        $termsStmt = $db->prepare('SELECT t.*, ay.name AS academic_year_name
            FROM academic_terms t
            JOIN academic_years ay ON ay.id = t.academic_year_id
            WHERE t.academic_year_id = ?
            ORDER BY t.term_order ASC');
        $termsStmt->execute([$currentAcademicYearId]);
        $terms = $termsStmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    } else {
        $terms = $db->query("SELECT t.*, ay.name AS academic_year_name
            FROM academic_terms t
            JOIN academic_years ay ON ay.id = t.academic_year_id
            ORDER BY ay.is_active DESC, ay.id DESC, t.term_order ASC")->fetchAll(PDO::FETCH_ASSOC) ?: [];
    }
}

$subjectOrderExpr = subject_assignments_column_exists($db, 'subjects', 'sort_order')
    ? 'sort_order'
    : (subject_assignments_column_exists($db, 'subjects', 'default_order') ? 'default_order' : 'id');
$subjects = $db->query("SELECT id, name FROM subjects WHERE COALESCE(is_active, 1) = 1 ORDER BY {$subjectOrderExpr}, name")->fetchAll(PDO::FETCH_ASSOC) ?: [];
$stages = $db->query("SELECT id, stage_name FROM stages WHERE status = 'active' ORDER BY stage_order, id")->fetchAll(PDO::FETCH_ASSOC) ?: [];
$grades = $db->query("SELECT id, grade_name, stage_id FROM grades WHERE status = 'active' ORDER BY stage_id, grade_order, id")->fetchAll(PDO::FETCH_ASSOC) ?: [];
$classes = $db->query("SELECT id, name, grade_id FROM classes WHERE status = 'active' ORDER BY grade_id, display_order, name")->fetchAll(PDO::FETCH_ASSOC) ?: [];
$gradesByStage = [];
$classesByGrade = [];
$availableGradeCount = 0;
foreach ($grades as $grade) {
    $gradesByStage[(int) $grade['stage_id']][] = $grade;
}
foreach ($classes as $class) {
    $classesByGrade[(int) $class['grade_id']][] = $class;
}
foreach ($stages as $stage) {
    $availableGradeCount += count($gradesByStage[(int) $stage['id']] ?? []);
}

if ($assignmentsReady) {
    $assignmentSql = "SELECT
            MIN(sga.id) AS id,
            sga.academic_year_id,
            MIN(sga.term_id) AS term_id,
            sga.subject_id,
            CASE WHEN COUNT(DISTINCT COALESCE(sga.stage_id, g.stage_id)) = 1 THEN MIN(COALESCE(sga.stage_id, g.stage_id)) ELSE NULL END AS stage_id,
            CASE WHEN COUNT(DISTINCT sga.grade_id) = 1 THEN MIN(sga.grade_id) ELSE NULL END AS grade_id,
            CASE WHEN COUNT(*) = 1 THEN MIN(sga.class_id) ELSE NULL END AS class_id,
            CASE WHEN COUNT(*) = 1 THEN MAX(sga.notes) ELSE NULL END AS notes,
            CASE WHEN SUM(CASE WHEN sga.is_active = 1 THEN 1 ELSE 0 END) > 0 THEN 1 ELSE 0 END AS is_active,
            COUNT(*) AS assignment_count,
            COUNT(DISTINCT sga.class_id) AS class_count,
            COUNT(DISTINCT sga.grade_id) AS grade_count,
            COUNT(DISTINCT COALESCE(sga.stage_id, g.stage_id)) AS stage_count,
            COUNT(DISTINCT COALESCE(sga.term_id, 0)) AS term_count,
            SUM(CASE WHEN sga.class_id IS NULL THEN 1 ELSE 0 END) AS all_classes_count,
            SUM(CASE WHEN sga.is_active = 1 THEN 1 ELSE 0 END) AS active_count,
            SUM(CASE WHEN sga.is_active = 1 THEN 0 ELSE 1 END) AS inactive_count,
            GROUP_CONCAT(sga.id ORDER BY sga.id SEPARATOR ',') AS assignment_ids,
            GROUP_CONCAT(DISTINCT sga.grade_id ORDER BY sga.grade_id SEPARATOR ',') AS grade_ids,
            MAX(ay.is_active) AS year_is_active,
            MIN(t.term_order) AS term_order,
            MIN(g.grade_order) AS grade_order,
            ay.name AS academic_year_name,
            s.name AS subject_name,
            COALESCE(MIN(t.name), 'كل الترمات') AS term_name,
            GROUP_CONCAT(DISTINCT st.stage_name ORDER BY st.stage_order SEPARATOR '، ') AS stage_name,
            GROUP_CONCAT(DISTINCT g.grade_name ORDER BY st.stage_order, g.grade_order SEPARATOR '، ') AS grade_name,
            CASE
                WHEN SUM(CASE WHEN sga.class_id IS NULL THEN 1 ELSE 0 END) > 0 THEN 'كل الفصول'
                ELSE GROUP_CONCAT(DISTINCT c.name ORDER BY c.name SEPARATOR '، ')
            END AS class_name
        FROM subject_grade_assignments sga
        JOIN academic_years ay ON ay.id = sga.academic_year_id
        LEFT JOIN academic_terms t ON t.id = sga.term_id
        JOIN subjects s ON s.id = sga.subject_id
        JOIN grades g ON g.id = sga.grade_id
        LEFT JOIN stages st ON st.id = COALESCE(sga.stage_id, g.stage_id)
        LEFT JOIN classes c ON c.id = sga.class_id";
    $assignmentParams = [];
    if ($currentAcademicYearId > 0) {
        $assignmentSql .= ' WHERE sga.academic_year_id = ?';
        $assignmentParams[] = $currentAcademicYearId;
    }
        $assignmentSql .= " GROUP BY
            sga.academic_year_id,
            sga.subject_id,
            COALESCE(sga.term_id, 0),
            ay.name,
            s.name
        ORDER BY year_is_active DESC, sga.academic_year_id DESC, s.name, term_order, grade_order";
    $assignmentStmt = $db->prepare($assignmentSql);
    $assignmentStmt->execute($assignmentParams);
    $subjectAssignments = $assignmentStmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

    $detailSql = "SELECT sga.*, ay.name AS academic_year_name, t.name AS term_name,
            s.name AS subject_name, st.stage_name, g.grade_name, c.name AS class_name
        FROM subject_grade_assignments sga
        JOIN academic_years ay ON ay.id = sga.academic_year_id
        LEFT JOIN academic_terms t ON t.id = sga.term_id
        JOIN subjects s ON s.id = sga.subject_id
        JOIN grades g ON g.id = sga.grade_id
        LEFT JOIN stages st ON st.id = COALESCE(sga.stage_id, g.stage_id)
        LEFT JOIN classes c ON c.id = sga.class_id";
    if ($currentAcademicYearId > 0) {
        $detailSql .= ' WHERE sga.academic_year_id = ?';
    }
    $detailSql .= ' ORDER BY ay.is_active DESC, ay.id DESC, s.name, g.grade_order, c.name';
    $detailStmt = $db->prepare($detailSql);
    $detailStmt->execute($assignmentParams);
    foreach ($detailStmt->fetchAll(PDO::FETCH_ASSOC) ?: [] as $detail) {
        $groupKey = (int) $detail['academic_year_id']
            . '_' . (int) $detail['subject_id']
            . '_' . (int) ($detail['term_id'] ?? 0);
        $assignmentDetailsBySubject[$groupKey][] = [
            'id' => (int) $detail['id'],
            'yearId' => (int) $detail['academic_year_id'],
            'yearName' => (string) ($detail['academic_year_name'] ?? ''),
            'termId' => (int) ($detail['term_id'] ?? 0),
            'subjectId' => (int) $detail['subject_id'],
            'stageId' => (int) ($detail['stage_id'] ?? 0),
            'gradeId' => (int) $detail['grade_id'],
            'classId' => (int) ($detail['class_id'] ?? 0),
            'active' => !empty($detail['is_active']) ? 1 : 0,
            'notes' => (string) ($detail['notes'] ?? ''),
            'termName' => (string) ($detail['term_name'] ?? 'كل الترمات'),
            'subjectName' => (string) ($detail['subject_name'] ?? ''),
            'stageName' => (string) ($detail['stage_name'] ?? '-'),
            'gradeName' => (string) ($detail['grade_name'] ?? ''),
            'className' => (string) ($detail['class_name'] ?? 'كل الفصول'),
        ];
    }

    $linkedGradeIds = [];
    foreach ($subjectAssignments as $assignment) {
        $totalAssignmentsCount += (int) ($assignment['assignment_count'] ?? 0);
        $activeAssignmentsCount += (int) ($assignment['active_count'] ?? 0);
        $inactiveAssignmentsCount += (int) ($assignment['inactive_count'] ?? 0);
        foreach (explode(',', (string) ($assignment['grade_ids'] ?? '')) as $gradeId) {
            $gradeId = (int) $gradeId;
            if ($gradeId > 0) {
                $linkedGradeIds[$gradeId] = true;
            }
        }
    }
    $linkedSubjectsCount = count(array_unique(array_filter(array_column($subjectAssignments, 'subject_id'))));
    $linkedGradesCount = count($linkedGradeIds);
}

require_once '../includes/admin_header.php';
?>

<div class="admin-page-heading">
    <h1 class="h2"><i class="fas fa-link me-2 text-primary"></i>ربط المواد بالصفوف</h1>
    <div class="admin-top-actions no-print">
        <?php if ($assignmentsReady): ?>
            <button type="button" class="btn btn-header-premium btn-success shadow-sm" data-bs-toggle="modal" data-bs-target="#addAssignmentModal">
                <i class="fas fa-plus-circle me-2"></i>ربط مادة
            </button>
        <?php endif; ?>
    </div>
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



<?php if (!$assignmentsReady): ?>
    <div class="alert alert-warning"><i class="fas fa-clock me-2"></i>جدول ربط المواد بالصفوف غير مطبق بعد.</div>
<?php else: ?>
<div class="row row-cols-2 row-cols-md-5 g-3 mb-4">
    <div class="col">
        <div class="stat-card" style="--card-gradient: linear-gradient(135deg, #3b82f6, #2563eb);">
            <div class="stat-card-icon"><i class="fas fa-link"></i></div>
            <div class="stat-card-info"><div class="stat-card-number counter" data-target="<?php echo (int)$totalAssignmentsCount; ?>">0</div><div class="stat-card-label">إجمالي الربط</div></div>
        </div>
    </div>
    <div class="col">
        <div class="stat-card" style="--card-gradient: linear-gradient(135deg, #10b981, #059669);">
            <div class="stat-card-icon"><i class="fas fa-check"></i></div>
            <div class="stat-card-info"><div class="stat-card-number counter" data-target="<?php echo (int)$activeAssignmentsCount; ?>">0</div><div class="stat-card-label">نشط</div></div>
        </div>
    </div>
    <div class="col">
        <div class="stat-card" style="--card-gradient: linear-gradient(135deg, #f59e0b, #d97706);">
            <div class="stat-card-icon"><i class="fas fa-ban"></i></div>
            <div class="stat-card-info"><div class="stat-card-number counter" data-target="<?php echo (int)$inactiveAssignmentsCount; ?>">0</div><div class="stat-card-label">معطل</div></div>
        </div>
    </div>
    <div class="col">
        <div class="stat-card" style="--card-gradient: linear-gradient(135deg, #8b5cf6, #7c3aed);">
            <div class="stat-card-icon"><i class="fas fa-book"></i></div>
            <div class="stat-card-info"><div class="stat-card-number counter" data-target="<?php echo (int)$linkedSubjectsCount; ?>">0</div><div class="stat-card-label">مواد مرتبطة</div></div>
        </div>
    </div>
    <div class="col">
        <div class="stat-card" style="--card-gradient: linear-gradient(135deg, #0ea5e9, #0284c7);">
            <div class="stat-card-icon"><i class="fas fa-graduation-cap"></i></div>
            <div class="stat-card-info"><div class="stat-card-number counter" data-target="<?php echo (int)$linkedGradesCount; ?>">0</div><div class="stat-card-label">صفوف مرتبطة</div></div>
        </div>
    </div>
</div>

<div class="admin-list-surface">
    <div class="table-responsive admin-table-wrap">
        <table class="table table-hover table-striped align-middle datatable admin-data-table">
                <thead>
                    <tr>
                        <th class="admin-col-5 text-center">#</th>
                        <th>المادة</th>
                        <th>الترم</th>
                        <th class="text-center">المرحلة</th>
                        <th class="text-center">الصف</th>
                        <th class="text-center">الفصل</th>
                        <th class="text-center">الحالة</th>
                        <th class="text-center">إجراءات</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($subjectAssignments)): ?>
                        <tr><td colspan="8" class="text-center text-muted py-4">لم يتم ربط أي مادة بصف بعد.</td></tr>
                    <?php else: ?>
                        <?php foreach ($subjectAssignments as $index => $assignment): ?>
                            <?php
                                $assignmentCount = (int) ($assignment['assignment_count'] ?? 1);
                                $activeCount = (int) ($assignment['active_count'] ?? 0);
                                $inactiveCount = (int) ($assignment['inactive_count'] ?? 0);
                                $targetGroupStatus = $activeCount > 0 ? 'inactive' : 'active';
                                $assignmentGroupKey = (int) $assignment['academic_year_id']
                                    . '_' . (int) $assignment['subject_id']
                                    . '_' . (int) ($assignment['term_id'] ?? 0);
                                $assignmentDetails = $assignmentDetailsBySubject[$assignmentGroupKey] ?? [];
                                $assignmentDetailsJson = htmlspecialchars(json_encode($assignmentDetails, JSON_UNESCAPED_UNICODE), ENT_QUOTES, 'UTF-8');
                                $assignmentGroupName = (string) $assignment['subject_name'] . ' - ' . (string) ($assignment['term_name'] ?? 'كل الترمات');

                                // --- Stage Data ---
                                $rowStageNames = [];
                                foreach ($assignmentDetails as $d) {
                                    $st = trim((string)($d['stageName'] ?? ''));
                                    if ($st !== '' && $st !== '-') {
                                        $rowStageNames[$st] = true;
                                    }
                                }
                                $rowStageNames = array_keys($rowStageNames);
                                if (empty($rowStageNames) && !empty($assignment['stage_name'])) {
                                    $rowStageNames = array_values(array_filter(array_map('trim', explode('، ', (string)$assignment['stage_name']))));
                                }
                                $rowStageCount = count($rowStageNames);
                                $rowStageLabel = $rowStageCount === 1 ? 'مرحلة' : 'مراحل';

                                // --- Grade Data ---
                                $rowGradesByStage = [];
                                $rowAllGrades = [];
                                foreach ($assignmentDetails as $d) {
                                    $st = trim((string)($d['stageName'] ?? '')) ?: 'المرحلة';
                                    $gr = trim((string)($d['gradeName'] ?? ''));
                                    if ($gr !== '') {
                                        $rowGradesByStage[$st][$gr] = true;
                                        $rowAllGrades[$gr] = true;
                                    }
                                }
                                $rowGradeCount = count($rowAllGrades);
                                if ($rowGradeCount === 0 && !empty($assignment['grade_name'])) {
                                    $rowAllGrades = array_fill_keys(array_values(array_filter(array_map('trim', explode('، ', (string)$assignment['grade_name'])))), true);
                                    $rowGradeCount = count($rowAllGrades);
                                }
                                $rowGradeLabel = $rowGradeCount === 1 ? 'صف' : 'صفوف';
                                $gradeGroupCount = count($rowGradesByStage);
                                $gradeMenuModifier = $gradeGroupCount <= 1
                                    ? ($rowGradeCount <= 4 ? '--single' : '--compact')
                                    : ($gradeGroupCount <= 2 ? '--compact' : ($gradeGroupCount <= 4 ? '--medium' : '--wide'));

                                // --- Class Data ---
                                $rowClassesByGrade = [];
                                $rowTotalClassCount = 0;
                                $rowWholeGradeCount = 0;
                                foreach ($assignmentDetails as $d) {
                                    $gr = trim((string)($d['gradeName'] ?? '')) ?: 'صف غير محدد';
                                    $cl = trim((string)($d['className'] ?? ''));
                                    if (empty($d['classId']) || $cl === '' || $cl === 'كل الفصول') {
                                        $rowWholeGradeCount++;
                                        $rowClassesByGrade[$gr]['كل الفصول'] = true;
                                    } else {
                                        $rowTotalClassCount++;
                                        $rowClassesByGrade[$gr][$cl] = true;
                                    }
                                }
                                $isAllClassesOnly = ($rowTotalClassCount === 0);
                                $classGroupCount = count($rowClassesByGrade);
                                $classMenuModifier = $classGroupCount <= 1
                                    ? '--single'
                                    : ($classGroupCount <= 2 ? '--compact' : ($classGroupCount <= 4 ? '--medium' : '--wide'));
                            ?>
                            <tr>
                                <td class="text-center text-muted"><?php echo (int) $index + 1; ?></td>
                                <td><strong><?php echo htmlspecialchars($assignment['subject_name'], ENT_QUOTES, 'UTF-8'); ?></strong></td>
                                <td>
                                    <?php if (empty($assignment['term_id'])): ?>
                                        <span class="badge bg-primary-subtle text-primary border border-primary">كل الترمات</span>
                                    <?php else: ?>
                                        <span class="badge bg-light text-dark border"><?php echo htmlspecialchars($assignment['term_name'], ENT_QUOTES, 'UTF-8'); ?></span>
                                    <?php endif; ?>
                                </td>
                                <td class="text-center">
                                    <?php if ($rowStageCount > 0): ?>
                                        <div class="dropdown subject-assignment-stage-dropdown">
                                            <button class="btn btn-sm btn-outline-danger dropdown-toggle rounded-pill py-1 px-3 fs-7" type="button" aria-expanded="false">
                                                <i class="fas fa-layer-group me-1"></i><bdi><?php echo $rowStageCount; ?></bdi> <?php echo $rowStageLabel; ?>
                                            </button>
                                            <ul class="dropdown-menu dropdown-menu-end shadow-sm border-0 fs-7" aria-label="المراحل المسندة">
                                                <?php foreach ($rowStageNames as $stName): ?>
                                                    <li><span class="dropdown-item"><i class="fas fa-check-circle text-danger me-2" aria-hidden="true"></i><?php echo htmlspecialchars($stName, ENT_QUOTES, 'UTF-8'); ?></span></li>
                                                <?php endforeach; ?>
                                            </ul>
                                        </div>
                                    <?php else: ?>
                                        <span class="text-muted">-</span>
                                    <?php endif; ?>
                                </td>
                                <td class="text-center">
                                    <?php if ($rowGradeCount > 0): ?>
                                        <div class="dropdown subject-assignment-grade-dropdown subject-assignment-grade-dropdown<?php echo $gradeMenuModifier; ?>">
                                            <button class="btn btn-sm btn-outline-primary dropdown-toggle rounded-pill py-1 px-3 fs-7" type="button" aria-expanded="false">
                                                <i class="fas fa-graduation-cap me-1"></i><bdi><?php echo $rowGradeCount; ?></bdi> <?php echo $rowGradeLabel; ?>
                                            </button>
                                            <ul class="dropdown-menu dropdown-menu-end shadow-sm border-0 fs-7" aria-label="الصفوف المسندة">
                                                <?php if (count($rowGradesByStage) > 1): ?>
                                                    <?php foreach ($rowGradesByStage as $stageTitle => $gradesMap): ?>
                                                        <li><span class="dropdown-item teacher-assignment-class-group-item">
                                                            <span class="teacher-assignment-class-group-copy">
                                                                <strong class="teacher-assignment-class-group-title">
                                                                    <i class="fas fa-check-circle text-primary me-2" aria-hidden="true"></i>
                                                                    <?php echo htmlspecialchars($stageTitle, ENT_QUOTES, 'UTF-8'); ?>
                                                                </strong>
                                                                <span class="teacher-assignment-class-group-items"><?php echo htmlspecialchars(implode('، ', array_keys($gradesMap)), ENT_QUOTES, 'UTF-8'); ?></span>
                                                            </span>
                                                        </span></li>
                                                    <?php endforeach; ?>
                                                <?php else: ?>
                                                    <?php foreach (array_keys($rowAllGrades) as $grName): ?>
                                                        <li><span class="dropdown-item"><i class="fas fa-check-circle text-primary me-2" aria-hidden="true"></i><?php echo htmlspecialchars($grName, ENT_QUOTES, 'UTF-8'); ?></span></li>
                                                    <?php endforeach; ?>
                                                <?php endif; ?>
                                            </ul>
                                        </div>
                                    <?php else: ?>
                                        <span class="text-muted">-</span>
                                    <?php endif; ?>
                                </td>
                                <td class="text-center">
                                    <?php if (!empty($rowClassesByGrade)): ?>
                                        <div class="dropdown subject-assignment-class-dropdown subject-assignment-class-dropdown<?php echo $classMenuModifier; ?>">
                                            <button class="btn btn-sm btn-outline-info dropdown-toggle rounded-pill py-1 px-3 fs-7" type="button" aria-expanded="false">
                                                <i class="fas fa-chalkboard me-1"></i>
                                                <?php if ($isAllClassesOnly): ?>
                                                    كل الفصول
                                                <?php elseif ($rowWholeGradeCount > 0): ?>
                                                    <bdi><?php echo $rowTotalClassCount; ?></bdi> فصول · <bdi><?php echo $rowWholeGradeCount; ?></bdi> صفوف كاملة
                                                <?php else: ?>
                                                    <bdi><?php echo $rowTotalClassCount; ?></bdi> فصول
                                                <?php endif; ?>
                                            </button>
                                            <ul class="dropdown-menu dropdown-menu-end shadow-sm border-0 fs-7" aria-label="الفصول المسندة">
                                                <?php foreach ($rowClassesByGrade as $gradeTitle => $classMap): ?>
                                                    <li><span class="dropdown-item teacher-assignment-class-group-item">
                                                        <span class="teacher-assignment-class-group-copy">
                                                            <strong class="teacher-assignment-class-group-title">
                                                                <i class="fas fa-check-circle text-info me-2" aria-hidden="true"></i>
                                                                <?php echo htmlspecialchars($gradeTitle, ENT_QUOTES, 'UTF-8'); ?>
                                                            </strong>
                                                            <span class="teacher-assignment-class-group-items"><?php echo htmlspecialchars(implode('، ', array_keys($classMap)), ENT_QUOTES, 'UTF-8'); ?></span>
                                                        </span>
                                                    </span></li>
                                                <?php endforeach; ?>
                                            </ul>
                                        </div>
                                    <?php else: ?>
                                        <span class="text-muted">-</span>
                                    <?php endif; ?>
                                </td>
                                <td class="text-center">
                                    <?php if ($activeCount > 0 && $inactiveCount > 0): ?>
                                        <span class="badge bg-warning text-dark" title="نشط: <?php echo (int) $activeCount; ?>، معطل: <?php echo (int) $inactiveCount; ?>">مختلط</span>
                                    <?php elseif ($activeCount > 0): ?>
                                        <span class="badge bg-success">نشط</span>
                                    <?php else: ?>
                                        <span class="badge bg-secondary">معطل</span>
                                    <?php endif; ?>
                                </td>
                                <td class="actions-column admin-table-actions text-center">
                                    <button type="button" class="btn btn-sm btn-action-pills btn-edit edit-assignment-group-btn me-1"
                                            data-bs-toggle="tooltip" title="تعديل المجموعة"
                                            data-subject-name="<?php echo htmlspecialchars((string) $assignment['subject_name'], ENT_QUOTES, 'UTF-8'); ?>"
                                            data-details="<?php echo $assignmentDetailsJson; ?>">
                                        <i class="fas fa-edit"></i>
                                    </button>
                                    <button type="button" class="btn btn-sm me-1 btn-action-pills <?php echo $targetGroupStatus === 'inactive' ? 'btn-deactivate' : 'btn-activate'; ?> toggle-assignment-group-btn"
                                            data-bs-toggle="tooltip"
                                            title="<?php echo $targetGroupStatus === 'inactive' ? 'تعطيل المجموعة' : 'تفعيل المجموعة'; ?>"
                                            data-year-id="<?php echo (int) $assignment['academic_year_id']; ?>"
                                            data-subject-id="<?php echo (int) $assignment['subject_id']; ?>"
                                            data-term-id="<?php echo (int) ($assignment['term_id'] ?? 0); ?>"
                                            data-group-name="<?php echo htmlspecialchars($assignmentGroupName, ENT_QUOTES, 'UTF-8'); ?>"
                                            data-target-status="<?php echo $targetGroupStatus; ?>"
                                            data-assignment-count="<?php echo $assignmentCount; ?>">
                                         <i class="fas <?php echo $targetGroupStatus === 'inactive' ? 'fa-ban' : 'fa-check'; ?>"></i>
                                    </button>
                                    <button type="button" class="btn btn-sm btn-action-pills btn-delete delete-assignment-group-btn"
                                            data-bs-toggle="tooltip" title="حذف المجموعة"
                                            data-year-id="<?php echo (int) $assignment['academic_year_id']; ?>"
                                            data-subject-id="<?php echo (int) $assignment['subject_id']; ?>"
                                            data-term-id="<?php echo (int) ($assignment['term_id'] ?? 0); ?>"
                                            data-group-name="<?php echo htmlspecialchars($assignmentGroupName, ENT_QUOTES, 'UTF-8'); ?>"
                                            data-assignment-count="<?php echo $assignmentCount; ?>"><i class="fas fa-trash"></i></button>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
        </table>
    </div>
</div>

<div class="modal fade" id="addAssignmentModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-xl modal-dialog-scrollable assessment-subject-assignment-modal-dialog">
        <form method="post" action="assessment_subject_assignments.php" id="addSubjectAssignmentForm" class="modal-content admin-modal admin-modal-premium admin-modal-create assessment-subject-assignment-modal">
                <?php echo csrfField(); ?>
                <input type="hidden" name="action" value="assign_subject_grade">
                <div class="modal-header">
                    <h5 class="modal-title"><i class="fas fa-link me-2"></i>ربط مادة بالصفوف والفصول</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="إغلاق"></button>
                </div>
                <div class="modal-body assessment-subject-assignment-modal-body">
                    <section class="border rounded-3 bg-light assignment-context-panel" aria-labelledby="assignmentPeriodTitle">
                        <div class="d-flex align-items-center justify-content-between gap-2 mb-2 flex-wrap">
                            <h6 class="fw-bold text-dark mb-0" id="assignmentPeriodTitle">
                                <i class="fas fa-sliders text-primary me-2"></i>نطاق الربط
                            </h6>
                            <div class="assignment-selection-summary assignment-context-selection-summary" id="assignmentSelectionSummary" aria-live="polite">
                                <span class="text-muted small">اختر مادة وصفاً واحداً على الأقل</span>
                            </div>
                        </div>
                        <div class="row g-2 align-items-end">
                            <?php if ($currentAcademicYearId > 0): ?>
                                <input type="hidden" name="academic_year_id" value="<?php echo (int) $currentAcademicYearId; ?>">
                            <?php else: ?>
                                <div class="col-md-4">
                                    <label class="form-label small fw-bold mb-1" for="assignmentAcademicYear">العام الدراسي</label>
                                    <select name="academic_year_id" id="assignmentAcademicYear" class="form-select" required>
                                        <option value="">اختر العام</option>
                                        <?php foreach ($academicYears as $year): ?>
                                            <option value="<?php echo (int) $year['id']; ?>"><?php echo htmlspecialchars($year['name'], ENT_QUOTES, 'UTF-8'); ?></option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>
                            <?php endif; ?>
                            <div class="col-md-6">
                                <label class="form-label small fw-bold mb-1" for="assignmentTerm">نطاق الترم</label>
                                <select name="term_id" id="assignmentTerm" class="form-select">
                                    <option value="">كل الترمات</option>
                                    <?php foreach ($terms as $term): ?>
                                        <option value="<?php echo (int) $term['id']; ?>" data-year-id="<?php echo (int) $term['academic_year_id']; ?>"><?php echo htmlspecialchars($term['name'], ENT_QUOTES, 'UTF-8'); ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <div class="col-md-6">
                                <label class="form-label small fw-bold mb-1" for="assignmentSubject">المادة الدراسية</label>
                                <select name="subject_id" id="assignmentSubject" class="form-select" required>
                                    <option value="" <?php echo empty($subjects) ? 'disabled' : ''; ?>><?php echo empty($subjects) ? 'لا توجد مواد نشطة متاحة للربط' : 'اختر المادة'; ?></option>
                                    <?php foreach ($subjects as $subject): ?>
                                        <option value="<?php echo (int) $subject['id']; ?>"><?php echo htmlspecialchars($subject['name'], ENT_QUOTES, 'UTF-8'); ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <div class="col-12">
                                <div class="text-muted small d-flex align-items-center gap-1">
                                    <i class="fas fa-circle-info text-primary me-1"></i>
                                    <span>اختيار «كل الترمات» يجعل الرابط متاحًا طوال العام الدراسي، ثم حدد الصفوف أو الفصول المطلوبة.</span>
                                </div>
                                <div class="alert alert-danger py-2 mt-2 mb-0 d-none" id="assignmentSubjectFeedback" role="alert">
                                    <i class="fas fa-exclamation-circle me-1"></i>اختر مادة دراسية.
                                </div>
                            </div>
                        </div>
                    </section>

                    <section class="border rounded-3 p-2 bg-white shadow-sm assignment-scope-panel assignment-scope-panel-expanded" aria-labelledby="assignmentScopeTitle">
                                <div class="d-flex align-items-center justify-content-between gap-2 p-2 mb-2 bg-light rounded border-start border-3 border-primary">
                                    <span class="fw-bold text-dark" id="assignmentScopeTitle"><i class="fas fa-school text-primary me-2"></i>الصفوف والفصول</span>
                                    <button type="button" class="btn btn-sm btn-outline-primary py-0 px-2 small" id="selectAllAssignmentGrades" <?php echo $availableGradeCount === 0 ? 'disabled' : ''; ?>>
                                        <i class="fas fa-check-double me-1"></i>تحديد كل الصفوف
                                    </button>
                                </div>
                                <div class="px-2 py-2 assignment-scope-list" id="assignmentAcademicScope">
                                    <?php if ($availableGradeCount === 0): ?>
                                        <div class="alert alert-warning mb-0"><i class="fas fa-triangle-exclamation me-1"></i>لا توجد صفوف نشطة متاحة للربط.</div>
                                    <?php endif; ?>
                                    <?php foreach ($stages as $stage): ?>
                                        <?php
                                        $stageId = (int) $stage['id'];
                                        $stageGrades = $gradesByStage[$stageId] ?? [];
                                        if (empty($stageGrades)) {
                                            continue;
                                        }
                                        ?>
                                        <div class="stage-group mb-3 assignment-stage-group" data-stage-id="<?php echo $stageId; ?>">
                                            <div class="d-flex align-items-center justify-content-between gap-2 p-2 px-3 mb-2 rounded border bg-light shadow-sm">
                                                <div class="d-flex align-items-center">
                                                    <i class="fas fa-graduation-cap text-primary me-2"></i>
                                                    <span class="fw-bold text-dark me-2"><?php echo htmlspecialchars($stage['stage_name'], ENT_QUOTES, 'UTF-8'); ?></span>
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
                                                                    <input class="form-check-input assignment-grade-checkbox mt-0" type="checkbox" name="all_grade_ids[]" value="<?php echo $gradeId; ?>" id="assignment_grade_<?php echo $gradeId; ?>" data-stage-id="<?php echo $stageId; ?>" autocomplete="off">
                                                                    <label class="fw-bold text-dark cursor-pointer mb-0" for="assignment_grade_<?php echo $gradeId; ?>"><?php echo htmlspecialchars($grade['grade_name'], ENT_QUOTES, 'UTF-8'); ?></label>
                                                                    <span class="badge bg-light text-dark border assignment-grade-scope-badge">غير محدد</span>
                                                                </div>
                                                            </div>

                                                            <div class="px-2 py-1 assignment-class-options" data-grade-id="<?php echo $gradeId; ?>">
                                                                <?php if (empty($gradeClasses)): ?>
                                                                    <div class="text-muted small">لا توجد فصول نشطة؛ سيطبق الربط على الصف بالكامل.</div>
                                                                <?php else: ?>
                                                                     <div class="row row-cols-2 g-2">
                                                                        <?php foreach ($gradeClasses as $class): ?>
                                                                            <?php $classId = (int) $class['id']; ?>
                                                                            <div class="col">
                                                                                <div class="form-check mb-1">
                                                                                    <input class="form-check-input assignment-class-checkbox" type="checkbox" name="class_ids[]" value="<?php echo $classId; ?>" id="assignment_class_<?php echo $classId; ?>" data-grade-id="<?php echo $gradeId; ?>">
                                                                                    <label class="form-check-label small fw-semibold cursor-pointer" for="assignment_class_<?php echo $classId; ?>">
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
                                <div class="alert alert-danger py-2 mt-2 mb-0 d-none" id="assignmentScopeFeedback" role="alert">
                                    <i class="fas fa-exclamation-circle me-1"></i>اختر صفاً كاملاً أو فصلاً واحداً على الأقل.
                                </div>
                    </section>

                    <div class="card bg-light border border-secondary border-opacity-10 shadow-none rounded-3 assignment-options-panel">
                                <div class="card-body py-2 px-3">
                                    <div class="row g-2 align-items-stretch assignment-options-grid">
                                        <div class="col-lg-8 assignment-option-field">
                                            <label class="form-label fw-bold" for="assignmentNotes"><i class="fas fa-note-sticky text-primary me-1"></i>ملاحظات اختيارية</label>
                                            <input type="text" name="notes" id="assignmentNotes" class="form-control" maxlength="500" placeholder="ملاحظة تظهر مع الربط عند الحاجة">
                                        </div>
                                        <div class="col-lg-4 d-flex">
                                        </div>
                                    </div>
                                </div>
                            </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal"><i class="fas fa-times me-1"></i>إلغاء</button>
                    <button type="submit" class="btn btn-success"><i class="fas fa-link me-1"></i>حفظ الربط</button>
                </div>
        </form>
    </div>
</div>

<div class="modal fade" id="editAssignmentModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-xl modal-dialog-scrollable assessment-subject-assignment-modal-dialog">
        <form method="post" action="assessment_subject_assignments.php" id="editSubjectAssignmentForm" class="modal-content admin-modal admin-modal-premium admin-modal-edit assessment-subject-assignment-modal">
                <?php echo csrfField(); ?>
                <input type="hidden" name="action" value="sync_subject_assignment_group">
                <input type="hidden" name="original_academic_year_id" id="editOriginalAssignmentYear">
                <input type="hidden" name="original_subject_id" id="editOriginalAssignmentSubject">
                <input type="hidden" name="original_term_id" id="editOriginalAssignmentTerm">
                <input type="hidden" name="academic_year_id" id="editAssignmentYear">
                <input type="hidden" name="notes_mode" id="editAssignmentNotesMode" value="replace">
                <div class="modal-header">
                    <h5 class="modal-title"><i class="fas fa-edit me-2"></i>تعديل مجموعة روابط المادة</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="إغلاق"></button>
                </div>
                <div class="modal-body assessment-subject-assignment-modal-body">
                    <section class="border rounded-3 bg-light assignment-context-panel" aria-labelledby="editAssignmentPeriodTitle">
                        <div class="d-flex align-items-center justify-content-between gap-2 mb-2 flex-wrap">
                            <h6 class="fw-bold text-dark mb-0" id="editAssignmentPeriodTitle">
                                <i class="fas fa-sliders text-primary me-2"></i>نطاق الربط
                            </h6>
                            <div class="assignment-selection-summary assignment-context-selection-summary" id="editAssignmentSelectionSummary" aria-live="polite">—</div>
                        </div>
                        <div class="row g-2 align-items-end">
                            <div class="col-md-6">
                                <label class="form-label small fw-bold mb-1" for="editAssignmentTerm">نطاق الترم</label>
                                <select name="term_id" id="editAssignmentTerm" class="form-select">
                                    <option value="">كل الترمات</option>
                                    <?php foreach ($terms as $term): ?>
                                        <option value="<?php echo (int) $term['id']; ?>" data-year-id="<?php echo (int) $term['academic_year_id']; ?>"><?php echo htmlspecialchars($term['name'], ENT_QUOTES, 'UTF-8'); ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <div class="col-md-6">
                                <label class="form-label small fw-bold mb-1" for="editAssignmentSubject">المادة الدراسية</label>
                                <select name="subject_id" id="editAssignmentSubject" class="form-select" required>
                                    <option value="" <?php echo empty($subjects) ? 'disabled' : ''; ?>><?php echo empty($subjects) ? 'لا توجد مواد نشطة متاحة للربط' : 'اختر المادة'; ?></option>
                                    <?php foreach ($subjects as $subject): ?>
                                        <option value="<?php echo (int) $subject['id']; ?>"><?php echo htmlspecialchars($subject['name'], ENT_QUOTES, 'UTF-8'); ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                        </div>
                    </section>

                    <section class="border rounded-3 p-2 bg-white shadow-sm assignment-scope-panel assignment-scope-panel-expanded" aria-labelledby="editAssignmentScopeTitle">
                                <div class="d-flex align-items-center justify-content-between gap-2 p-2 mb-2 bg-light rounded border-start border-3 border-primary">
                                    <span class="fw-bold text-dark" id="editAssignmentScopeTitle"><i class="fas fa-school text-primary me-2"></i>الصفوف والفصول</span>
                                    <button type="button" class="btn btn-sm btn-outline-primary py-0 px-2 small" id="selectAllEditAssignmentGrades">
                                        <i class="fas fa-check-double me-1"></i>تحديد كل الصفوف بالكامل
                                    </button>
                                </div>
                                <div class="px-2 py-2 assignment-scope-list" id="editAssignmentAcademicScope">
                                    <?php foreach ($stages as $stage): ?>
                                        <?php
                                        $stageId = (int) $stage['id'];
                                        $stageGrades = $gradesByStage[$stageId] ?? [];
                                        if (empty($stageGrades)) {
                                            continue;
                                        }
                                        ?>
                                        <div class="stage-group mb-3 edit-assignment-stage-group" data-stage-id="<?php echo $stageId; ?>">
                                            <div class="d-flex align-items-center justify-content-between gap-2 p-2 px-3 mb-2 rounded border bg-light shadow-sm">
                                                <div class="d-flex align-items-center gap-2">
                                                    <i class="fas fa-graduation-cap text-primary"></i>
                                                    <span class="fw-bold text-dark"><?php echo htmlspecialchars($stage['stage_name'], ENT_QUOTES, 'UTF-8'); ?></span>
                                                    <span class="badge bg-secondary"><?php echo count($stageGrades); ?> صفوف</span>
                                                </div>
                                                <button type="button" class="btn btn-sm btn-outline-primary py-0 px-2 small select-edit-assignment-stage-btn">
                                                    <i class="fas fa-check-double me-1"></i>تحديد المرحلة بالكامل
                                                </button>
                                            </div>
                                            <div class="row g-2">
                                                <?php foreach ($stageGrades as $grade): ?>
                                                    <?php
                                                    $gradeId = (int) $grade['id'];
                                                    $gradeClasses = $classesByGrade[$gradeId] ?? [];
                                                    ?>
                                                    <div class="col-md-6 col-xl-4">
                                                        <div class="border rounded-3 p-2 bg-white shadow-sm h-100 edit-assignment-grade-card" data-grade-id="<?php echo $gradeId; ?>">
                                                            <div class="d-flex align-items-center justify-content-between gap-2 p-2 mb-2 bg-light rounded border-start border-3 border-primary">
                                                                <div class="d-flex align-items-center gap-2">
                                                                    <input class="form-check-input edit-assignment-grade-checkbox mt-0" type="checkbox" name="all_grade_ids[]" value="<?php echo $gradeId; ?>" id="edit_assignment_grade_<?php echo $gradeId; ?>" data-stage-id="<?php echo $stageId; ?>" autocomplete="off">
                                                                    <label class="fw-bold text-dark cursor-pointer mb-0" for="edit_assignment_grade_<?php echo $gradeId; ?>"><?php echo htmlspecialchars($grade['grade_name'], ENT_QUOTES, 'UTF-8'); ?></label>
                                                                    <span class="badge bg-light text-dark border edit-assignment-grade-badge">غير محدد</span>
                                                                </div>
                                                            </div>

                                                            <div class="px-2 py-1">
                                                                <?php if (empty($gradeClasses)): ?>
                                                                    <div class="text-muted small">لا توجد فصول نشطة؛ سيطبق الربط على الصف بالكامل.</div>
                                                                <?php else: ?>
                                                                     <div class="row row-cols-2 g-2">
                                                                        <?php foreach ($gradeClasses as $class): ?>
                                                                            <?php $classId = (int) $class['id']; ?>
                                                                            <div class="col">
                                                                                <div class="form-check mb-1">
                                                                                    <input class="form-check-input edit-assignment-class-checkbox" type="checkbox" name="class_ids[]" value="<?php echo $classId; ?>" id="edit_assignment_class_<?php echo $classId; ?>" data-grade-id="<?php echo $gradeId; ?>">
                                                                                    <label class="form-check-label small fw-semibold cursor-pointer" for="edit_assignment_class_<?php echo $classId; ?>">
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
                    </section>

                    <div class="card bg-light border border-secondary border-opacity-10 shadow-none rounded-3 assignment-options-panel">
                                <div class="card-body py-2 px-3">
                                    <div class="row g-2 align-items-stretch assignment-options-grid">
                                        <div class="col-lg-8 assignment-option-field">
                                            <label class="form-label fw-bold" for="editAssignmentNotes"><i class="fas fa-note-sticky text-primary me-1"></i>ملاحظات اختيارية</label>
                                            <input type="text" name="notes" id="editAssignmentNotes" class="form-control" maxlength="500" placeholder="ملاحظة تظهر مع الربط عند الحاجة">
                                        </div>
                                        <div class="col-md-6 col-lg-4 assignment-option-field">
                                            <label class="form-label fw-bold" for="editAssignmentStatusMode"><i class="fas fa-toggle-on text-primary me-1"></i>حالة المجموعة</label>
                                            <select name="status_mode" id="editAssignmentStatusMode" class="form-select">
                                                <option value="active">تفعيل كل الروابط المحددة</option>
                                                <option value="inactive">تعطيل كل الروابط المحددة</option>
                                                <option value="preserve">الحفاظ على الحالات الحالية</option>
                                            </select>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        <div>
                            <div class="alert alert-danger py-2 mb-0 d-none" id="editAssignmentFeedback" role="alert">
                                <i class="fas fa-exclamation-circle me-1"></i>اختر مادة وصفاً كاملاً أو فصلاً واحداً على الأقل قبل الحفظ.
                            </div>
                        </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal"><i class="fas fa-times me-1"></i>إلغاء</button>
                    <button type="submit" class="btn btn-primary"><i class="fas fa-save me-1"></i>حفظ التعديلات</button>
                </div>
        </form>
    </div>
</div>

<div class="modal fade" id="toggleAssignmentModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog">
        <div class="modal-content admin-modal admin-modal-premium admin-modal-warning" id="toggleAssignmentModalContent">
            <form method="post" action="assessment_subject_assignments.php">
                <?php echo csrfField(); ?>
                <input type="hidden" name="action" value="set_subject_assignment_group_status">
                <input type="hidden" name="academic_year_id" id="toggleAssignmentGroupYear">
                <input type="hidden" name="subject_id" id="toggleAssignmentGroupSubject">
                <input type="hidden" name="term_id" id="toggleAssignmentGroupTerm">
                <input type="hidden" name="target_status" id="toggleAssignmentGroupTargetStatus">
                <div class="modal-header">
                    <h5 class="modal-title"><i class="fas fa-toggle-on me-2" id="toggleAssignmentHeaderIcon"></i>تغيير حالة مجموعة الروابط</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <div class="text-center mb-3">
                        <i class="fas fa-toggle-on text-warning admin-modal-icon-lg" id="toggleAssignmentBodyIcon"></i>
                    </div>
                    <p class="text-center">هل تريد <span class="fw-bold" id="toggleAssignmentAction"></span> كل روابط <span class="fw-bold text-primary" id="toggleAssignmentName"></span>؟</p>
                    <div class="alert alert-info mb-0">
                        <i class="fas fa-info-circle me-2"></i>
                        ستُطبق الحالة على المجموعة كاملة في عملية ذرية، ولا تُحذف أي درجات أو إعدادات سابقة.
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal"><i class="fas fa-times me-1"></i>إلغاء</button>
                    <button type="submit" class="btn btn-warning" id="toggleAssignmentSubmit"><i class="fas fa-ban me-1"></i><span id="toggleAssignmentSubmitText">تعطيل</span></button>
                </div>
            </form>
        </div>
    </div>
</div>

<div class="modal fade" id="deleteAssignmentModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog">
        <div class="modal-content admin-modal admin-modal-premium admin-modal-delete">
            <form method="post" action="assessment_subject_assignments.php">
                <?php echo csrfField(); ?>
                <input type="hidden" name="action" value="delete_subject_assignment_group">
                <input type="hidden" name="academic_year_id" id="deleteAssignmentGroupYear">
                <input type="hidden" name="subject_id" id="deleteAssignmentGroupSubject">
                <input type="hidden" name="term_id" id="deleteAssignmentGroupTerm">
                <div class="modal-header">
                    <h5 class="modal-title"><i class="fas fa-trash me-2"></i>حذف مجموعة روابط المادة</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body text-center">
                    <i class="fas fa-triangle-exclamation text-danger mb-3 admin-modal-icon-lg"></i>
                    <p>هل تريد حذف كل روابط <span class="fw-bold text-primary" id="deleteAssignmentName"></span>؟</p>
                    <div class="alert alert-warning text-start">
                        ستُحذف المجموعة كاملة في عملية ذرية. سيمنع النظام الحذف إذا كان أي رابط مستخدماً في خطة درجات أو تعيين معلم.
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal"><i class="fas fa-times me-1"></i>إلغاء</button>
                    <button type="submit" class="btn btn-danger"><i class="fas fa-trash me-1"></i>حذف</button>
                </div>
            </form>
        </div>
    </div>
</div>

<script>
document.addEventListener('DOMContentLoaded', function () {
    function showModal(id) {
        const modalEl = document.getElementById(id);
        if (modalEl && window.bootstrap) {
            new bootstrap.Modal(modalEl).show();
        }
    }

    const addAssignmentForm = document.getElementById('addSubjectAssignmentForm');
    const addAssignmentModal = document.getElementById('addAssignmentModal');
    const assignmentYearSelect = document.getElementById('assignmentAcademicYear');
    const assignmentTermSelect = document.getElementById('assignmentTerm');
    const assignmentSubjectInput = document.getElementById('assignmentSubject');
    const assignmentSubjectFeedback = document.getElementById('assignmentSubjectFeedback');
    const assignmentScopeFeedback = document.getElementById('assignmentScopeFeedback');
    const assignmentSelectionSummary = document.getElementById('assignmentSelectionSummary');
    const assignmentGradeInputs = Array.from(document.querySelectorAll('.assignment-grade-checkbox'));
    const assignmentClassInputs = Array.from(document.querySelectorAll('.assignment-class-checkbox'));

    function assignmentInputLabel(input) {
        if (input && input.tagName === 'SELECT') {
            const option = input.options[input.selectedIndex];
            return option && option.value ? option.textContent.trim() : '';
        }
        const label = input && input.id ? document.querySelector('label[for="' + input.id + '"]') : null;
        return label ? label.textContent.trim() : '';
    }

    function selectedAssignmentGrades() {
        return assignmentGradeInputs.filter(function (input) {
            return input.checked;
        });
    }

    function syncAssignmentSubjectChoices() {
        if (assignmentSubjectFeedback && assignmentSubjectInput && assignmentSubjectInput.value) {
            assignmentSubjectFeedback.classList.add('d-none');
        }
    }

    function syncAssignmentTermOptions() {
        if (!assignmentYearSelect || !assignmentTermSelect) return;
        const yearId = assignmentYearSelect.value;
        assignmentTermSelect.querySelectorAll('option[data-year-id]').forEach(function (option) {
            option.hidden = Boolean(yearId) && option.dataset.yearId !== yearId;
        });
        const selectedOption = assignmentTermSelect.options[assignmentTermSelect.selectedIndex];
        if (selectedOption && selectedOption.hidden) {
            assignmentTermSelect.value = '';
        }
    }

    function syncAssignmentScope() {
        document.querySelectorAll('.assignment-grade-card').forEach(function (card) {
            const gradeInput = card.querySelector('.assignment-grade-checkbox');
            const gradeSelected = Boolean(gradeInput && gradeInput.checked);
            const classInputs = Array.from(card.querySelectorAll('.assignment-class-checkbox'));
            classInputs.forEach(function (input) {
                input.disabled = gradeSelected;
                if (gradeSelected) input.checked = false;
            });

            const selectedClasses = classInputs.filter(function (input) { return input.checked; });
            const hasScope = gradeSelected || selectedClasses.length > 0;
            card.classList.toggle('border-primary', hasScope);
            card.classList.toggle('shadow', hasScope);

            const badge = card.querySelector('.assignment-grade-scope-badge');
            if (badge) {
                badge.className = hasScope
                    ? 'badge bg-primary-subtle text-primary border border-primary assignment-grade-scope-badge'
                    : 'badge bg-light text-dark border assignment-grade-scope-badge';
                badge.textContent = gradeSelected
                    ? 'الصف بالكامل'
                    : (selectedClasses.length > 0 ? selectedClasses.length + ' فصول' : 'غير محدد');
            }
        });

        document.querySelectorAll('.assignment-stage-group').forEach(function (stageGroup) {
            const stageGrades = Array.from(stageGroup.querySelectorAll('.assignment-grade-checkbox'));
            const allSelected = stageGrades.length > 0 && stageGrades.every(function (input) { return input.checked; });
            const stageButton = stageGroup.querySelector('.select-assignment-stage-btn');
            if (!stageButton) return;
            stageButton.classList.toggle('btn-primary', allSelected);
            stageButton.classList.toggle('btn-outline-primary', !allSelected);
            stageButton.innerHTML = allSelected
                ? '<i class="fas fa-times-circle me-1"></i>إلغاء تحديد المرحلة'
                : '<i class="fas fa-check-double me-1"></i>تحديد المرحلة';
        });

        const allGradesSelected = assignmentGradeInputs.length > 0 && assignmentGradeInputs.every(function (input) { return input.checked; });
        const allGradesButton = document.getElementById('selectAllAssignmentGrades');
        if (allGradesButton) {
            allGradesButton.classList.toggle('btn-primary', allGradesSelected);
            allGradesButton.classList.toggle('btn-outline-primary', !allGradesSelected);
            allGradesButton.innerHTML = allGradesSelected
                ? '<i class="fas fa-times-circle me-1"></i>إلغاء تحديد الكل'
                : '<i class="fas fa-check-double me-1"></i>تحديد كل الصفوف';
        }

        const selectedWholeGrades = selectedAssignmentGrades();
        const selectedClasses = assignmentClassInputs.filter(function (input) {
            return input.checked && !input.disabled;
        });
        const scopeCount = selectedWholeGrades.length + selectedClasses.length;
        if (assignmentScopeFeedback && scopeCount > 0) {
            assignmentScopeFeedback.classList.add('d-none');
        }

        if (assignmentSelectionSummary) {
            const selectedSubject = assignmentSubjectInput && assignmentSubjectInput.value
                ? assignmentSubjectInput
                : null;
            if (!selectedSubject || scopeCount === 0) {
                assignmentSelectionSummary.innerHTML = '<span class="text-muted small">اختر مادة وصفاً واحداً على الأقل</span>';
            } else {
                const subjectName = assignmentInputLabel(selectedSubject);
                assignmentSelectionSummary.textContent = subjectName
                    + ' · ' + selectedWholeGrades.length + ' صف كامل'
                    + ' · ' + selectedClasses.length + ' فصل';
            }
        }
    }

    if (assignmentSubjectInput) {
        assignmentSubjectInput.addEventListener('change', function () {
            syncAssignmentSubjectChoices();
            syncAssignmentScope();
        });
        assignmentSubjectInput.addEventListener('invalid', function () {
            if (assignmentSubjectFeedback) {
                assignmentSubjectFeedback.classList.remove('d-none');
            }
        });
    }

    assignmentGradeInputs.forEach(function (input) {
        input.addEventListener('change', function () {
            syncAssignmentScope();
        });
    });

    assignmentClassInputs.forEach(function (input) {
        input.addEventListener('change', function () {
            syncAssignmentScope();
        });
    });

    document.querySelectorAll('.select-assignment-stage-btn').forEach(function (button) {
        button.addEventListener('click', function () {
            const stageGroup = this.closest('.assignment-stage-group');
            if (!stageGroup) return;
            const stageGrades = Array.from(stageGroup.querySelectorAll('.assignment-grade-checkbox'));
            const allSelected = stageGrades.length > 0 && stageGrades.every(function (input) { return input.checked; });
            stageGrades.forEach(function (input) {
                input.checked = !allSelected;
            });
            syncAssignmentScope();
        });
    });

    const selectAllAssignmentGrades = document.getElementById('selectAllAssignmentGrades');
    if (selectAllAssignmentGrades) {
        selectAllAssignmentGrades.addEventListener('click', function () {
            const allSelected = assignmentGradeInputs.length > 0 && assignmentGradeInputs.every(function (input) { return input.checked; });
            assignmentGradeInputs.forEach(function (input) {
                input.checked = !allSelected;
            });
            syncAssignmentScope();
        });
    }

    if (addAssignmentForm) {
        addAssignmentForm.addEventListener('submit', function (event) {
            const hasSubject = Boolean(assignmentSubjectInput && assignmentSubjectInput.value);
            const hasScope = selectedAssignmentGrades().length > 0 || assignmentClassInputs.some(function (input) {
                return input.checked && !input.disabled;
            });
            if (hasSubject && hasScope) {
                if (assignmentSubjectFeedback) assignmentSubjectFeedback.classList.add('d-none');
                if (assignmentScopeFeedback) assignmentScopeFeedback.classList.add('d-none');
                return;
            }
            event.preventDefault();
            if (!hasSubject && assignmentSubjectFeedback) {
                assignmentSubjectFeedback.classList.remove('d-none');
            }
            if (!hasScope && assignmentScopeFeedback) {
                assignmentScopeFeedback.classList.remove('d-none');
                assignmentScopeFeedback.scrollIntoView({ behavior: 'smooth', block: 'nearest' });
            }
        });
    }

    if (assignmentYearSelect) {
        assignmentYearSelect.addEventListener('change', syncAssignmentTermOptions);
    }

    if (addAssignmentModal && addAssignmentForm) {
        addAssignmentModal.addEventListener('hidden.bs.modal', function () {
            addAssignmentForm.reset();
            if (assignmentSubjectFeedback) assignmentSubjectFeedback.classList.add('d-none');
            if (assignmentScopeFeedback) assignmentScopeFeedback.classList.add('d-none');
            syncAssignmentSubjectChoices();
            syncAssignmentScope();
            syncAssignmentTermOptions();
        });
    }

    syncAssignmentSubjectChoices();
    syncAssignmentScope();
    syncAssignmentTermOptions();

    const editAssignmentForm = document.getElementById('editSubjectAssignmentForm');
    const editTermSelect = document.getElementById('editAssignmentTerm');
    const editSubjectInput = document.getElementById('editAssignmentSubject');
    const editOriginalYearInput = document.getElementById('editOriginalAssignmentYear');
    const editOriginalSubjectInput = document.getElementById('editOriginalAssignmentSubject');
    const editOriginalTermInput = document.getElementById('editOriginalAssignmentTerm');
    const editSelectionSummary = document.getElementById('editAssignmentSelectionSummary');
    const editFeedback = document.getElementById('editAssignmentFeedback');
    const editStatusMode = document.getElementById('editAssignmentStatusMode');
    const editNotesInput = document.getElementById('editAssignmentNotes');
    const editNotesMode = document.getElementById('editAssignmentNotesMode');
    const editAssignmentModal = document.getElementById('editAssignmentModal');
    const editAssignmentModalBody = editAssignmentModal
        ? editAssignmentModal.querySelector('.modal-body')
        : null;
    const editAssignmentAcademicScope = document.getElementById('editAssignmentAcademicScope');
    const editGradeChoices = Array.from(document.querySelectorAll('.edit-assignment-grade-checkbox'));
    const editClassChoices = Array.from(document.querySelectorAll('.edit-assignment-class-checkbox'));

    function syncEditAssignmentTermOptions(yearId, selectedTermId) {
        if (!editTermSelect) return;
        const requestedTermId = selectedTermId && selectedTermId !== '0' ? selectedTermId : '';
        editTermSelect.querySelectorAll('option[data-year-id]').forEach(function (option) {
            option.hidden = Boolean(yearId) && option.dataset.yearId !== yearId;
        });
        editTermSelect.value = requestedTermId;
        const selectedOption = editTermSelect.options[editTermSelect.selectedIndex];
        if (selectedOption && selectedOption.hidden) {
            editTermSelect.value = '';
        }
    }

    function syncEditAssignmentScope() {
        const selectedSubject = editSubjectInput && editSubjectInput.value ? editSubjectInput : null;

        document.querySelectorAll('.edit-assignment-grade-card').forEach(function (card) {
            const gradeId = card.dataset.gradeId || '';
            const gradeInput = card.querySelector('.edit-assignment-grade-checkbox');
            const gradeSelected = Boolean(gradeInput && gradeInput.checked);
            const classInputs = Array.from(card.querySelectorAll('.edit-assignment-class-checkbox'));
            classInputs.forEach(function (input) {
                input.disabled = gradeSelected;
                if (gradeSelected) input.checked = false;
            });
            const selectedClasses = classInputs.filter(function (input) { return input.checked; });
            const hasScope = gradeSelected || selectedClasses.length > 0;
            card.classList.toggle('border-primary', hasScope);
            card.classList.toggle('shadow', hasScope);

            const badge = card.querySelector('.edit-assignment-grade-badge');
            if (badge) {
                badge.className = hasScope
                    ? 'badge bg-primary-subtle text-primary border border-primary edit-assignment-grade-badge'
                    : 'badge bg-light text-dark border edit-assignment-grade-badge';
                badge.textContent = gradeSelected
                    ? 'الصف بالكامل'
                    : (selectedClasses.length > 0 ? selectedClasses.length + ' فصول' : 'غير محدد');
            }

        });

        document.querySelectorAll('.edit-assignment-stage-group').forEach(function (stageGroup) {
            const stageGrades = Array.from(stageGroup.querySelectorAll('.edit-assignment-grade-checkbox'));
            const allSelected = stageGrades.length > 0 && stageGrades.every(function (input) { return input.checked; });
            const stageButton = stageGroup.querySelector('.select-edit-assignment-stage-btn');
            if (!stageButton) return;
            stageButton.classList.toggle('btn-primary', allSelected);
            stageButton.classList.toggle('btn-outline-primary', !allSelected);
            stageButton.innerHTML = allSelected
                ? '<i class="fas fa-times-circle me-1"></i>إلغاء تحديد المرحلة'
                : '<i class="fas fa-check-double me-1"></i>تحديد المرحلة بالكامل';
        });

        const allGradesSelected = editGradeChoices.length > 0 && editGradeChoices.every(function (input) {
            return input.checked;
        });
        const selectAllButton = document.getElementById('selectAllEditAssignmentGrades');
        if (selectAllButton) {
            selectAllButton.classList.toggle('btn-primary', allGradesSelected);
            selectAllButton.classList.toggle('btn-outline-primary', !allGradesSelected);
            selectAllButton.innerHTML = allGradesSelected
                ? '<i class="fas fa-times-circle me-1"></i>إلغاء تحديد الكل'
                : '<i class="fas fa-check-double me-1"></i>تحديد كل الصفوف بالكامل';
        }

        const selectedWholeGrades = editGradeChoices.filter(function (input) { return input.checked; });
        const selectedClasses = editClassChoices.filter(function (input) { return input.checked && !input.disabled; });
        const scopeCount = selectedWholeGrades.length + selectedClasses.length;
        if (editSelectionSummary) {
            if (!selectedSubject || scopeCount === 0) {
                editSelectionSummary.textContent = 'اختر مادة ونطاقاً';
            } else {
                editSelectionSummary.textContent = assignmentInputLabel(selectedSubject)
                    + ' · ' + selectedWholeGrades.length + ' صف كامل'
                    + ' · ' + selectedClasses.length + ' فصل';
            }
        }

        if (editFeedback && selectedSubject && scopeCount > 0) {
            editFeedback.classList.add('d-none');
        }
    }

    if (editSubjectInput) {
        editSubjectInput.addEventListener('change', function () {
            syncEditAssignmentScope();
        });
    }

    editGradeChoices.forEach(function (input) {
        input.addEventListener('change', function () {
            if (this.checked) {
                const gradeId = this.value;
                editClassChoices.forEach(function (classInput) {
                    if (classInput.dataset.gradeId === gradeId) classInput.checked = false;
                });
            }
            syncEditAssignmentScope();
        });
    });

    editClassChoices.forEach(function (input) {
        input.addEventListener('change', function () {
            if (this.checked) {
                const gradeInput = editGradeChoices.find(function (candidate) {
                    return candidate.value === input.dataset.gradeId;
                });
                if (gradeInput) gradeInput.checked = false;
            }
            syncEditAssignmentScope();
        });
    });

    document.querySelectorAll('.select-edit-assignment-stage-btn').forEach(function (button) {
        button.addEventListener('click', function () {
            const stageGroup = this.closest('.edit-assignment-stage-group');
            if (!stageGroup) return;
            const stageGrades = Array.from(stageGroup.querySelectorAll('.edit-assignment-grade-checkbox'));
            const allSelected = stageGrades.length > 0 && stageGrades.every(function (input) {
                return input.checked;
            });
            stageGrades.forEach(function (input) {
                input.checked = !allSelected;
                if (!allSelected) {
                    editClassChoices.forEach(function (classInput) {
                        if (classInput.dataset.gradeId === input.value) classInput.checked = false;
                    });
                }
            });
            syncEditAssignmentScope();
        });
    });

    const selectAllEditAssignmentGrades = document.getElementById('selectAllEditAssignmentGrades');
    if (selectAllEditAssignmentGrades) {
        selectAllEditAssignmentGrades.addEventListener('click', function () {
            const allSelected = editGradeChoices.length > 0 && editGradeChoices.every(function (input) {
                return input.checked;
            });
            editGradeChoices.forEach(function (input) {
                input.checked = !allSelected;
            });
            if (!allSelected) {
                editClassChoices.forEach(function (input) { input.checked = false; });
            }
            syncEditAssignmentScope();
        });
    }

    if (editNotesInput && editNotesMode) {
        editNotesInput.addEventListener('input', function () {
            editNotesMode.value = 'replace';
        });
    }

    if (editAssignmentForm) {
        editAssignmentForm.addEventListener('submit', function (event) {
            const selectedSubject = Boolean(editSubjectInput && editSubjectInput.value);
            const selectedScope = editGradeChoices.some(function (input) { return input.checked; })
                || editClassChoices.some(function (input) { return input.checked && !input.disabled; });
            if (selectedSubject && selectedScope) return;
            event.preventDefault();
            if (editFeedback) {
                editFeedback.classList.remove('d-none');
                editFeedback.scrollIntoView({ behavior: 'smooth', block: 'nearest' });
            }
        });
    }

    function scrollAssignmentListToItem(container, item) {
        if (!container) return;
        container.scrollTop = 0;
        if (!item) return;

        const containerRect = container.getBoundingClientRect();
        const itemRect = item.getBoundingClientRect();
        container.scrollTop = Math.max(0, itemRect.top - containerRect.top - 8);
    }

    function positionEditAssignmentModal() {
        if (editAssignmentModalBody) {
            editAssignmentModalBody.scrollTop = 0;
        }

        const selectedScopeInput = editGradeChoices.find(function (input) {
            return input.checked;
        }) || editClassChoices.find(function (input) {
            return input.checked;
        });
        scrollAssignmentListToItem(
            editAssignmentAcademicScope,
            selectedScopeInput ? selectedScopeInput.closest('.edit-assignment-stage-group') : null
        );
    }

    function showEditAssignmentGroupModal() {
        if (editAssignmentModal) {
            editAssignmentModal.addEventListener('shown.bs.modal', function () {
                window.requestAnimationFrame(positionEditAssignmentModal);
            }, { once: true });
        }
        showModal('editAssignmentModal');
    }

    function openEditAssignmentGroup(details) {
        if (!Array.isArray(details) || details.length === 0) return;
        const firstDetail = details[0];
        const assignmentYearId = String(firstDetail.yearId || '');
        document.getElementById('editAssignmentYear').value = assignmentYearId;
        if (editOriginalYearInput) editOriginalYearInput.value = assignmentYearId;
        if (editOriginalSubjectInput) editOriginalSubjectInput.value = String(firstDetail.subjectId || '');
        if (editOriginalTermInput) editOriginalTermInput.value = firstDetail.termId && String(firstDetail.termId) !== '0'
            ? String(firstDetail.termId)
            : '';
        syncEditAssignmentTermOptions(assignmentYearId, String(firstDetail.termId || ''));
        if (editSubjectInput) {
            editSubjectInput.value = String(firstDetail.subjectId || '');
        }
        editGradeChoices.forEach(function (input) {
            input.checked = details.some(function (detail) {
                return String(detail.gradeId || '') === input.value && (!detail.classId || String(detail.classId) === '0');
            });
        });
        editClassChoices.forEach(function (input) {
            input.checked = details.some(function (detail) {
                return detail.classId
                    && String(detail.classId) !== '0'
                    && String(detail.classId) === input.value;
            });
        });

        const activeStates = Array.from(new Set(details.map(function (detail) {
            return detail.active ? 'active' : 'inactive';
        })));
        if (editStatusMode) {
            editStatusMode.value = activeStates.length === 1 ? activeStates[0] : 'preserve';
        }

        const notesValues = Array.from(new Set(details.map(function (detail) {
            return detail.notes || '';
        })));
        if (editNotesInput && editNotesMode) {
            const hasMixedNotes = notesValues.length > 1;
            editNotesInput.value = hasMixedNotes ? '' : notesValues[0];
            editNotesMode.value = hasMixedNotes ? 'preserve' : 'replace';
        }

        if (editFeedback) editFeedback.classList.add('d-none');
        syncEditAssignmentScope();
        showEditAssignmentGroupModal();
    }

    function openToggleAssignmentGroup(button) {
        const activatesGroup = button.dataset.targetStatus === 'active';
        const submitButton = document.getElementById('toggleAssignmentSubmit');
        const submitText = document.getElementById('toggleAssignmentSubmitText');
        const actionText = document.getElementById('toggleAssignmentAction');

        document.getElementById('toggleAssignmentGroupYear').value = button.dataset.yearId || '';
        document.getElementById('toggleAssignmentGroupSubject').value = button.dataset.subjectId || '';
        document.getElementById('toggleAssignmentGroupTerm').value = button.dataset.termId === '0' ? '' : (button.dataset.termId || '');
        document.getElementById('toggleAssignmentGroupTargetStatus').value = button.dataset.targetStatus || '';
        document.getElementById('toggleAssignmentName').textContent = button.dataset.groupName || '';
        actionText.textContent = activatesGroup ? 'تفعيل' : 'تعطيل';
        actionText.className = activatesGroup ? 'fw-bold text-success' : 'fw-bold text-warning';
        submitText.textContent = activatesGroup ? 'تفعيل المجموعة' : 'تعطيل المجموعة';
        submitButton.className = activatesGroup ? 'btn btn-success' : 'btn btn-warning';
        submitButton.querySelector('i').className = activatesGroup ? 'fas fa-check me-1' : 'fas fa-ban me-1';

        const modalContent = document.getElementById('toggleAssignmentModalContent');
        if (modalContent) {
            modalContent.classList.toggle('admin-modal-warning', !activatesGroup);
            modalContent.classList.toggle('admin-modal-create', activatesGroup);
        }
        const bodyIcon = document.getElementById('toggleAssignmentBodyIcon');
        const headerIcon = document.getElementById('toggleAssignmentHeaderIcon');
        if (bodyIcon) {
            bodyIcon.className = activatesGroup ? 'fas fa-check-circle text-success admin-modal-icon-lg' : 'fas fa-ban text-warning admin-modal-icon-lg';
        }
        if (headerIcon) {
            headerIcon.className = activatesGroup ? 'fas fa-check-circle me-2' : 'fas fa-ban me-2';
        }
        showModal('toggleAssignmentModal');
    }

    function openDeleteAssignmentGroup(button) {
        document.getElementById('deleteAssignmentGroupYear').value = button.dataset.yearId || '';
        document.getElementById('deleteAssignmentGroupSubject').value = button.dataset.subjectId || '';
        document.getElementById('deleteAssignmentGroupTerm').value = button.dataset.termId === '0' ? '' : (button.dataset.termId || '');
        document.getElementById('deleteAssignmentName').textContent = button.dataset.groupName || '';
        showModal('deleteAssignmentModal');
    }

    function assignmentDetailsFromButton(button) {
        try {
            const details = JSON.parse(button.dataset.details || '[]');
            return Array.isArray(details) ? details : [];
        } catch (error) {
            return [];
        }
    }

    document.querySelectorAll('.edit-assignment-group-btn').forEach(function (button) {
        button.addEventListener('click', function () {
            openEditAssignmentGroup(assignmentDetailsFromButton(this));
        });
    });

    document.querySelectorAll('.toggle-assignment-group-btn').forEach(function (button) {
        button.addEventListener('click', function () {
            openToggleAssignmentGroup(this);
        });
    });

    document.querySelectorAll('.delete-assignment-group-btn').forEach(function (button) {
        button.addEventListener('click', function () {
            openDeleteAssignmentGroup(this);
        });
    });

});
</script>
<?php endif; ?>

<?php require_once '../includes/admin_footer.php'; ?>
