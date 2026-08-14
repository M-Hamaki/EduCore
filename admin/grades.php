<?php
// Set page title
$page_title = "إدارة الصفوف الدراسية";
$custom_page_title = true;

// Include database and necessary classes
require_once '../config/database.php';
require_once '../classes/utilities.php';
require_once '../classes/ActivityLog.php';
require_once '../classes/UndoManager.php';
require_once '../classes/AcademicYear.php';
require_once '../src/Modules/Operations/Audit/AuditService.php';
require_once '../src/Modules/AcademicStructure/ExperimentalAcademicScopePolicy.php';
require_once '../includes/session_config.php';
require_once '../includes/csrf.php';
Utilities::validateSession('admin');
requireCsrfPost();

// Get messages from session
$success_message = $_SESSION['success_message'] ?? null;
$error_message = $_SESSION['error_message'] ?? null;
unset($_SESSION['success_message'], $_SESSION['error_message']);

// Initialize database connection
$database = new Database();
$db = $database->getConnection();
UndoManager::setDb($db);
$experimentalScopePolicy = new \EduCore\Modules\AcademicStructure\ExperimentalAcademicScopePolicy($db);
$experimentalScopePolicy->assertSchemaReady();
$auditService = new \EduCore\Modules\Operations\Audit\AuditService($db);
$currentAcademicYearId = AcademicYear::currentId($db);

// تعيين ترميز UTF-8
$db->exec("SET NAMES 'utf8mb4'");
$db->exec("SET CHARACTER SET utf8mb4");

// Helper: stage color mapping
function getStageColor($stageName)
{
    $colors = [
        'kindergarten' => ['bg' => 'bg-info', 'text' => 'text-info'],
        'primary' => ['bg' => 'bg-success', 'text' => 'text-success'],
        'preparatory' => ['bg' => 'bg-primary', 'text' => 'text-primary'],
        'secondary' => ['bg' => 'bg-danger', 'text' => 'text-danger'],
    ];

    $stageName = trim(strtolower((string) $stageName));

    if (strpos($stageName, 'رياض') !== false || strpos($stageName, 'روضة') !== false || strpos($stageName, 'kg') !== false) {
        return $colors['kindergarten'];
    }
    if (strpos($stageName, 'ابتدائ') !== false || strpos($stageName, 'primary') !== false) {
        return $colors['primary'];
    }
    if (strpos($stageName, 'اعداد') !== false || strpos($stageName, 'إعداد') !== false || strpos($stageName, 'preparatory') !== false) {
        return $colors['preparatory'];
    }
    if (strpos($stageName, 'ثانو') !== false || strpos($stageName, 'secondary') !== false) {
        return $colors['secondary'];
    }

    return ['bg' => 'bg-primary', 'text' => 'text-primary'];
}

$normalizeGradePayload = static function (array $payload) use ($db): array {
    $name = trim((string) ($payload['grade_name'] ?? ''));
    $code = trim((string) ($payload['grade_code'] ?? ''));
    $order = filter_var($payload['grade_order'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
    $description = trim((string) ($payload['description'] ?? ''));
    $stageRaw = trim((string) ($payload['stage_id'] ?? ''));
    $stageId = null;
    if ($stageRaw !== '') {
        $stageId = filter_var($stageRaw, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
        if ($stageId === false) {
            throw new InvalidArgumentException('المرحلة المحددة غير صالحة.');
        }
        $stageStmt = $db->prepare('SELECT 1 FROM stages WHERE id = ?');
        $stageStmt->execute([(int) $stageId]);
        if (!$stageStmt->fetchColumn()) {
            throw new InvalidArgumentException('المرحلة المحددة غير موجودة.');
        }
    }
    if ($name === '' || mb_strlen($name, 'UTF-8') > 100) {
        throw new InvalidArgumentException('اسم الصف مطلوب ويجب ألا يتجاوز 100 حرف.');
    }
    if ($code === '' || mb_strlen($code, 'UTF-8') > 20) {
        throw new InvalidArgumentException('كود الصف مطلوب ويجب ألا يتجاوز 20 حرفاً.');
    }
    if ($order === false) {
        throw new InvalidArgumentException('ترتيب الصف يجب أن يكون رقماً موجباً.');
    }
    if (mb_strlen($description, 'UTF-8') > 2000) {
        throw new InvalidArgumentException('وصف الصف طويل جداً.');
    }
    return [
        'grade_name' => $name,
        'grade_code' => $code,
        'grade_order' => (int) $order,
        'stage_id' => $stageId !== null ? (int) $stageId : null,
        'description' => $description,
        'is_experimental' => !empty($payload['is_experimental']) ? 1 : 0,
    ];
};

// Process form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $postAction = is_string($_POST['action'] ?? null) ? $_POST['action'] : '';
    try {
        if (isset($_POST['add_grade'])) {
            $grade = $normalizeGradePayload($_POST);
            $db->beginTransaction();
            $stmt = $db->prepare('INSERT INTO grades (grade_name, grade_code, grade_order, stage_id, description, is_experimental) VALUES (?, ?, ?, ?, ?, ?)');
            $stmt->execute(array_values($grade));
            $gradeId = (int) $db->lastInsertId();
            $afterStmt = $db->prepare('SELECT * FROM grades WHERE id = ?');
            $afterStmt->execute([$gradeId]);
            $after = $afterStmt->fetch(PDO::FETCH_ASSOC) ?: [];
            $auditService->recordInsert('grade', 'grades', $gradeId, $grade['grade_name'], $after, 'إضافة صف');
            $db->commit();
            $_SESSION['success_message'] = 'تم إضافة الصف بنجاح.';
        } elseif (isset($_POST['edit_grade']) || $postAction === 'edit_grade') {
            $gradeId = filter_var($_POST['id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
            if ($gradeId === false) {
                throw new InvalidArgumentException('معرّف الصف غير صالح.');
            }
            $grade = $normalizeGradePayload($_POST);
            $db->beginTransaction();
            $beforeStmt = $db->prepare('SELECT * FROM grades WHERE id = ? FOR UPDATE');
            $beforeStmt->execute([(int) $gradeId]);
            $before = $beforeStmt->fetch(PDO::FETCH_ASSOC);
            if (!$before) {
                throw new InvalidArgumentException('الصف الدراسي غير موجود.');
            }
            $experimentalScopePolicy->assertGradeTransition((int) $gradeId, $grade['stage_id'], $grade['is_experimental'] === 1);
            $stmt = $db->prepare('UPDATE grades SET grade_name = ?, grade_code = ?, grade_order = ?, stage_id = ?, description = ?, is_experimental = ? WHERE id = ?');
            $stmt->execute([...array_values($grade), (int) $gradeId]);
            $afterStmt = $db->prepare('SELECT * FROM grades WHERE id = ?');
            $afterStmt->execute([(int) $gradeId]);
            $after = $afterStmt->fetch(PDO::FETCH_ASSOC) ?: [];
            if ($before != $after) {
                $auditService->recordUpdate('grade', 'grades', (int) $gradeId, $grade['grade_name'], $before, $after, 'تعديل صف');
            }
            $db->commit();
            $_SESSION['success_message'] = 'تم تحديث الصف بنجاح.';
        } elseif ($postAction === 'delete_grade') {
            $gradeId = filter_var($_POST['id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
            if ($gradeId === false) {
                throw new InvalidArgumentException('معرّف الصف غير صالح.');
            }
            $db->beginTransaction();
            $beforeStmt = $db->prepare('SELECT * FROM grades WHERE id = ? FOR UPDATE');
            $beforeStmt->execute([(int) $gradeId]);
            $before = $beforeStmt->fetch(PDO::FETCH_ASSOC);
            if (!$before) {
                throw new InvalidArgumentException('الصف الدراسي غير موجود أو حُذف مسبقاً.');
            }
            $classesStmt = $db->prepare('SELECT COUNT(*) FROM classes WHERE grade_id = ?');
            $classesStmt->execute([(int) $gradeId]);
            $classesCount = (int) $classesStmt->fetchColumn();
            if ($classesCount > 0) {
                throw new InvalidArgumentException('لا يمكن حذف الصف لأنه مرتبط بـ ' . $classesCount . ' فصل. يمكنك تعطيله بدلاً من ذلك.');
            }
            $deleteStmt = $db->prepare('DELETE FROM grades WHERE id = ?');
            $deleteStmt->execute([(int) $gradeId]);
            if ($deleteStmt->rowCount() !== 1) {
                throw new RuntimeException('Grade deletion affected no rows.');
            }
            $auditService->recordDelete('grade', 'grades', (int) $gradeId, (string) ($before['grade_name'] ?? ''), $before, 'حذف صف');
            $db->commit();
            $_SESSION['success_message'] = 'تم حذف الصف بنجاح.';
        } elseif ($postAction === 'toggle_grade_status') {
            $gradeId = filter_var($_POST['id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
            $newStatus = is_string($_POST['new_status'] ?? null) ? $_POST['new_status'] : '';
            if ($gradeId === false || !in_array($newStatus, ['active', 'inactive'], true)) {
                throw new InvalidArgumentException('بيانات حالة الصف غير صالحة.');
            }
            $db->beginTransaction();
            $beforeStmt = $db->prepare('SELECT * FROM grades WHERE id = ? FOR UPDATE');
            $beforeStmt->execute([(int) $gradeId]);
            $before = $beforeStmt->fetch(PDO::FETCH_ASSOC);
            if (!$before) {
                throw new InvalidArgumentException('الصف الدراسي غير موجود.');
            }
            if (($before['status'] ?? '') !== $newStatus) {
                $db->prepare('UPDATE grades SET status = ? WHERE id = ?')->execute([$newStatus, (int) $gradeId]);
                $afterStmt = $db->prepare('SELECT * FROM grades WHERE id = ?');
                $afterStmt->execute([(int) $gradeId]);
                $after = $afterStmt->fetch(PDO::FETCH_ASSOC) ?: [];
                $auditService->recordUpdate('grade', 'grades', (int) $gradeId, (string) ($before['grade_name'] ?? ''), $before, $after, 'تغيير حالة صف');
            }
            $db->commit();
            $_SESSION['success_message'] = $newStatus === 'active' ? 'تم تفعيل الصف بنجاح.' : 'تم تعطيل الصف بنجاح.';
        }
    } catch (InvalidArgumentException $e) {
        if ($db->inTransaction()) { $db->rollBack(); }
        $_SESSION['error_message'] = $e->getMessage();
    } catch (Throwable $e) {
        if ($db->inTransaction()) { $db->rollBack(); }
        error_log('Grades write failed: ' . $e->getMessage());
        $_SESSION['error_message'] = $e instanceof PDOException && $e->getCode() === '23000'
            ? 'تعذر تنفيذ العملية بسبب ارتباط الصف ببيانات أخرى أو تكرار الكود.'
            : 'تعذر تنفيذ العملية على الصف. يرجى إعادة المحاولة.';
    }
    header('Location: grades.php');
    exit();
}

// Determine current action (add/edit)
$action = isset($_GET['action']) ? $_GET['action'] : '';
$edit_grade = null;

if ($action === 'edit' && isset($_GET['id'])) {
    $grade_id = intval($_GET['id']);
    $query = "SELECT * FROM grades WHERE id = ?";
    $stmt = $db->prepare($query);
    $stmt->execute([$grade_id]);
    $edit_grade = $stmt->fetch(PDO::FETCH_ASSOC);
}

// Get all stages for dropdown
$stages_query = "SELECT id, stage_name, is_experimental FROM stages ORDER BY stage_order";
$stages_stmt = $db->prepare($stages_query);
$stages_stmt->execute();
$all_stages = $stages_stmt->fetchAll(PDO::FETCH_ASSOC);

// Get all grades with stage info and counts
$studentsCountSql = $currentAcademicYearId > 0
    ? "(SELECT COUNT(DISTINCT se.student_id)
        FROM student_enrollments se
        JOIN users u2 ON u2.id = se.student_id AND u2.role = 'student' AND u2.status = 'active' AND u2.deleted_at IS NULL
        WHERE se.grade_id = g.id
          AND se.academic_year_id = " . (int) $currentAcademicYearId . "
          AND se.enrollment_status = 'enrolled')"
    : "(SELECT COUNT(DISTINCT u2.id)
        FROM classes c2
        JOIN users u2 ON u2.class_id = c2.id AND u2.role = 'student' AND u2.status = 'active' AND u2.deleted_at IS NULL
        WHERE c2.grade_id = g.id)";
$grades_query = "SELECT 
    g.*,
    s.stage_name,
    COALESCE(s.is_experimental, 0) AS stage_is_experimental,
    (SELECT COUNT(*) FROM classes WHERE grade_id = g.id" . ($currentAcademicYearId > 0 ? " AND (academic_year_id = " . (int) $currentAcademicYearId . " OR academic_year_id IS NULL)" : "") . ") as classes_count,
    {$studentsCountSql} as students_count
FROM grades g
LEFT JOIN stages s ON g.stage_id = s.id
ORDER BY g.grade_order";

$stmt = $db->prepare($grades_query);
$stmt->execute();
$grades = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Include header
include_once '../includes/admin_header.php';
?>

<!-- Page Title and Buttons Toolbar -->
<div class="admin-page-heading">
    <h1 class="h2"><i class="fas fa-layer-group me-2 text-primary"></i>إدارة الصفوف الدراسية <span
            class="badge bg-light text-dark border ms-2"><?php echo count($grades); ?></span></h1>
    <div class="admin-top-actions">
        <button class="btn btn-header-premium btn-success shadow-sm" data-bs-toggle="modal"
            data-bs-target="#addGradeModal">
            <i class="fas fa-plus-circle me-1"></i>إضافة صف
        </button>
        <button class="btn btn-header-premium btn-import-soft" data-bs-toggle="modal"
            data-bs-target="#importGradesModal">
            <i class="fas fa-file-import me-1"></i>استيراد Excel
        </button>
        <a href="export_grades.php" class="btn btn-header-premium btn-export-soft">
            <i class="fas fa-file-export me-1"></i>تصدير Excel
        </a>
    </div>
</div>
<!-- Filter/Actions Bar -->
<div class="admin-filter-bar">
    <div class="admin-filter-controls">
                    <!-- Stage Filter -->
                    <select class="form-select form-select-sm" id="stageFilter"
                        style="width: auto; min-width: 140px;">
                        <option value="">جميع المراحل</option>
                        <?php foreach ($all_stages as $stage): ?>
                            <option value="<?php echo htmlspecialchars($stage['stage_name']); ?>">
                                <?php echo htmlspecialchars($stage['stage_name']); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="admin-filter-actions">
                    <!-- Reset Filters Button -->
                    <a href="grades.php" class="btn btn-light btn-sm" title="إعادة تعيين الفلاتر">
                        <i class="fas fa-undo me-1"></i>إعادة تعيين
                    </a>

                    <!-- Table Settings Button -->
                    <button type="button" class="btn btn-light btn-sm" data-bs-toggle="modal"
                        data-bs-target="#tableSettingsModal" title="تخصيص أعمدة الجدول">
                        <i class="fas fa-cog me-1"></i>إعدادات الجدول
                    </button>
                </div>
            </div>

            <!-- List Surface -->
            <div class="admin-list-surface">
                <?php if (!empty($success_message)): ?>
                    <div class="alert alert-success alert-dismissible fade show" role="alert">
                        <i class="fas fa-check-circle me-2"></i><?php echo htmlspecialchars((string) $success_message, ENT_QUOTES, 'UTF-8'); ?>
                        <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
                    </div>
                <?php endif; ?>

                <?php if (!empty($error_message)): ?>
                    <div class="alert alert-danger alert-dismissible fade show" role="alert">
                        <i class="fas fa-exclamation-circle me-2"></i><?php echo htmlspecialchars((string) $error_message, ENT_QUOTES, 'UTF-8'); ?>
                        <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                    </div>
                <?php endif; ?>

                <div class="admin-table-wrap">
                    <table id="gradesTable" class="table table-hover table-striped align-middle datatable admin-data-table">
                        <thead class="table-light">
                            <tr>
                                <th style="width: 5%;">#</th>
                                <th class="col-name" style="width: 20%;">اسم الصف</th>
                                <th class="col-stage" style="width: 15%;">المرحلة</th>
                                <th class="col-code" style="width: 10%;">الكود</th>
                                <th class="col-order" style="width: 8%;">الترتيب</th>
                                <th class="col-classes" style="width: 10%;">عدد الفصول</th>
                                <th class="col-count" style="width: 10%;">عدد الطلاب</th>
                                <th class="col-status" style="width: 10%;">الحالة</th>
                                <th style="width: 10%;">الإجراءات</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php
                            $counter = 1;
                            foreach ($grades as $row):
                                ?>
                                <tr>
                                    <td><?php echo $counter++; ?></td>
                                    <td class="col-name">
                                        <strong><?php echo htmlspecialchars($row['grade_name']); ?></strong>
                                        <?php if (!empty($row['is_experimental'])): ?>
                                            <span class="badge bg-warning text-dark ms-1"><i class="fas fa-flask me-1"></i>صف تجريبي</span>
                                        <?php elseif (!empty($row['stage_is_experimental'])): ?>
                                            <span class="badge bg-warning text-dark ms-1"
                                                data-bs-toggle="tooltip" title="موروث من المرحلة التجريبية">
                                                <i class="fas fa-flask me-1"></i>تجريبي موروث
                                            </span>
                                        <?php endif; ?>
                                        <?php if (!empty($row['description'])): ?>
                                            <br><small
                                                class="text-muted"><?php echo htmlspecialchars($row['description']); ?></small>
                                        <?php endif; ?>
                                    </td>
                                    <td class="col-stage">
                                        <?php if (!empty($row['stage_name'])): ?>
                                            <?php $stageColor = getStageColor($row['stage_name']); ?>
                                            <span
                                                class="px-2 py-1 <?php echo $stageColor['bg']; ?> bg-opacity-10 <?php echo $stageColor['text']; ?> rounded"><?php echo htmlspecialchars($row['stage_name']); ?></span>
                                        <?php else: ?>
                                            <span class="text-muted">غير محدد</span>
                                        <?php endif; ?>
                                    </td>
                                    <td class="col-code"><span
                                            class="px-2 py-1 bg-secondary bg-opacity-10 text-dark rounded fw-bold"><?php echo htmlspecialchars($row['grade_code']); ?></span>
                                    </td>
                                    <td class="col-order"><?php echo $row['grade_order']; ?></td>
                                    <td class="col-classes"><span
                                            class="fw-bold text-primary"><?php echo $row['classes_count']; ?></span></td>
                                    <td class="col-count"><span
                                            class="fw-bold text-success"><?php echo $row['students_count']; ?></span></td>
                                    <td class="col-status">
                                        <?php if ($row['status'] == 'active'): ?>
                                            <span class="badge bg-success">
                                                <i class="fas fa-check-circle me-1"></i>نشط
                                            </span>
                                        <?php else: ?>
                                            <span class="badge bg-secondary">
                                                <i class="fas fa-ban me-1"></i>معطل
                                            </span>
                                        <?php endif; ?>
                                    </td>
                                    <td class="align-middle text-nowrap actions-column admin-table-actions">
                                        <button type="button" class="btn btn-action-pills btn-light me-1 reorder-btn"
                                            data-id="<?php echo $row['id']; ?>" data-direction="up" data-bs-toggle="tooltip"
                                            title="نقل لأعلى">
                                            <i class="fas fa-arrow-up"></i>
                                        </button>
                                        <button type="button" class="btn btn-action-pills btn-light me-1 reorder-btn"
                                            data-id="<?php echo $row['id']; ?>" data-direction="down"
                                            data-bs-toggle="tooltip" title="نقل لأسفل">
                                            <i class="fas fa-arrow-down"></i>
                                        </button>
                                        <button type="button" class="btn btn-action-pills btn-edit me-1 edit-grade"
                                            data-id="<?php echo $row['id']; ?>"
                                            data-name="<?php echo htmlspecialchars($row['grade_name']); ?>"
                                            data-code="<?php echo htmlspecialchars($row['grade_code']); ?>"
                                            data-order="<?php echo $row['grade_order']; ?>"
                                            data-stage="<?php echo $row['stage_id'] ?? ''; ?>"
                                            data-description="<?php echo htmlspecialchars($row['description'] ?? ''); ?>"
                                            data-experimental="<?php echo !empty($row['is_experimental']) ? '1' : '0'; ?>"
                                            data-stage-experimental="<?php echo !empty($row['stage_is_experimental']) ? '1' : '0'; ?>"
                                            data-bs-toggle="tooltip" title="تعديل">
                                            <i class="fas fa-edit"></i>
                                        </button>

                                        <a href="classes.php?grade_id=<?php echo $row['id']; ?>"
                                            class="btn btn-action-pills btn-services me-1" data-bs-toggle="tooltip"
                                            title="إدارة الفصول">
                                            <i class="fas fa-door-open"></i>
                                        </a>

                                        <?php if ($row['status'] == 'active'): ?>
                                            <button type="button"
                                                class="btn btn-action-pills btn-deactivate me-1 toggle-grade-status"
                                                data-id="<?php echo $row['id']; ?>"
                                                data-name="<?php echo htmlspecialchars($row['grade_name']); ?>"
                                                data-status="inactive" data-action="تعطيل" data-bs-toggle="modal"
                                                data-bs-target="#toggleGradeStatusModal" title="تعطيل">
                                                <i class="fas fa-ban"></i>
                                            </button>
                                        <?php else: ?>
                                            <button type="button"
                                                class="btn btn-action-pills btn-activate me-1 toggle-grade-status"
                                                data-id="<?php echo $row['id']; ?>"
                                                data-name="<?php echo htmlspecialchars($row['grade_name']); ?>"
                                                data-status="active" data-action="تفعيل" data-bs-toggle="modal"
                                                data-bs-target="#toggleGradeStatusModal" title="تفعيل">
                                                <i class="fas fa-check"></i>
                                            </button>
                                        <?php endif; ?>

                                        <button type="button" class="btn btn-action-pills btn-delete delete-grade"
                                            data-id="<?php echo $row['id']; ?>"
                                            data-name="<?php echo htmlspecialchars($row['grade_name']); ?>"
                                            data-bs-toggle="modal" data-bs-target="#deleteGradeModal" title="حذف">
                                            <i class="fas fa-trash"></i>
                                        </button>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div> <!-- admin-table-wrap -->
            </div> <!-- admin-list-surface -->

<!-- Add Grade Modal -->
<div class="modal fade" id="addGradeModal" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content admin-modal admin-modal-premium admin-modal-create">
            <div class="modal-header">
                <h5 class="modal-title">
                    <i class="fas fa-plus-circle me-2"></i>إضافة صف جديد
                </h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
<form method="POST" action="">
    <?php echo csrfField(); ?>
                <div class="modal-body">
                    <div class="mb-3">
                        <label class="form-label">اسم الصف <span class="text-danger">*</span></label>
                        <input type="text" class="form-control" name="grade_name" required>
                    </div>
                    <div class="mb-3">
                        <label class="form-label">كود الصف <span class="text-danger">*</span></label>
                        <input type="text" class="form-control" name="grade_code" required>
                    </div>
                    <div class="mb-3">
                        <label class="form-label">المرحلة</label>
                        <select class="form-select" name="stage_id">
                            <option value="">اختر المرحلة</option>
                            <?php foreach ($all_stages as $stage): ?>
                                <option value="<?php echo $stage['id']; ?>">
                                    <?php echo htmlspecialchars($stage['stage_name']); ?><?php echo !empty($stage['is_experimental']) ? ' — تجريبية' : ''; ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="mb-3">
                        <label class="form-label">الترتيب <span class="text-danger">*</span></label>
                        <input type="number" class="form-control" name="grade_order" required>
                    </div>
                    <div class="mb-3">
                        <label class="form-label">الوصف</label>
                        <textarea class="form-control" name="description" rows="3"></textarea>
                    </div>
                    <div class="form-check form-switch mb-3">
                        <input class="form-check-input" type="checkbox" role="switch" id="add_is_experimental"
                            name="is_experimental" value="1">
                        <label class="form-check-label fw-bold" for="add_is_experimental">صف تجريبي</label>
                        <div class="form-text">إذا كانت المرحلة تجريبية فسيرث الصف التصنيف حتى لو لم تحدد هذا المفتاح.</div>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">إلغاء</button>
                    <button type="submit" name="add_grade" class="btn btn-success">
                        <i class="fas fa-save me-2"></i>حفظ
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- Edit Grade Modal -->
<div class="modal fade" id="editGradeModal" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content admin-modal admin-modal-premium admin-modal-edit">
            <div class="modal-header">
                <h5 class="modal-title">
                    <i class="fas fa-edit me-2"></i>تعديل الصف
                </h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
<form method="POST" action="">
    <?php echo csrfField(); ?>
                <input type="hidden" name="id" id="edit_grade_id">
                <input type="hidden" name="action" value="edit_grade">
                <div class="modal-body">
                    <div class="mb-3">
                        <label class="form-label">اسم الصف <span class="text-danger">*</span></label>
                        <input type="text" class="form-control" name="grade_name" id="edit_grade_name" required>
                    </div>
                    <div class="mb-3">
                        <label class="form-label">كود الصف <span class="text-danger">*</span></label>
                        <input type="text" class="form-control" name="grade_code" id="edit_grade_code" required>
                    </div>
                    <div class="mb-3">
                        <label class="form-label">المرحلة</label>
                        <select class="form-select" name="stage_id" id="edit_stage_id">
                            <option value="">اختر المرحلة</option>
                            <?php foreach ($all_stages as $stage): ?>
                                <option value="<?php echo $stage['id']; ?>">
                                    <?php echo htmlspecialchars($stage['stage_name']); ?><?php echo !empty($stage['is_experimental']) ? ' — تجريبية' : ''; ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="mb-3">
                        <label class="form-label">الترتيب <span class="text-danger">*</span></label>
                        <input type="number" class="form-control" name="grade_order" id="edit_grade_order" required>
                    </div>
                    <div class="mb-3">
                        <label class="form-label">الوصف</label>
                        <textarea class="form-control" name="description" id="edit_description" rows="3"></textarea>
                    </div>
                    <div class="form-check form-switch mb-3">
                        <input class="form-check-input" type="checkbox" role="switch" id="edit_is_experimental"
                            name="is_experimental" value="1">
                        <label class="form-check-label fw-bold" for="edit_is_experimental">صف تجريبي</label>
                        <div class="form-text">التصنيف الموروث من المرحلة يظل فعالًا، وتُمنع التحويلات التي تؤثر في بيانات طلاب بأثر رجعي.</div>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">إلغاء</button>
                    <button type="submit" name="edit_grade" class="btn btn-primary">
                        <i class="fas fa-save me-2"></i>حفظ التعديلات
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- Delete Grade Modal -->
<div class="modal fade" id="deleteGradeModal" tabindex="-1" aria-labelledby="deleteGradeModalLabel" aria-hidden="true">
    <div class="modal-dialog">
        <div class="modal-content admin-modal admin-modal-premium admin-modal-delete">
            <form method="post" action="grades.php">
                <?php echo csrfField(); ?>
                <input type="hidden" id="delete_grade_id" name="id">
                <input type="hidden" name="action" value="delete_grade">
                <div class="modal-header">
                    <h5 class="modal-title" id="deleteGradeModalLabel">
                        <i class="fas fa-trash-alt me-2"></i>حذف صف
                    </h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body">
                    <div class="text-center mb-3">
                        <i class="fas fa-exclamation-triangle text-warning" style="font-size: 3rem;"></i>
                    </div>
                    <p class="text-center">هل أنت متأكد من حذف الصف <span class="fw-bold text-primary"
                            id="delete_grade_name"></span>؟</p>
                    <div class="alert alert-warning">
                        <i class="fas fa-info-circle me-2"></i>
                        سيتم حذف الصف وجميع بياناته المرتبطة.
                    </div>
                    <p class="text-danger text-center mb-0">
                        <i class="fas fa-exclamation-circle me-1"></i>
                        هذا الإجراء لا يمكن التراجع عنه.
                    </p>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">
                        <i class="fas fa-times me-1"></i>إلغاء
                    </button>
                    <button type="submit" class="btn btn-danger">
                        <i class="fas fa-trash me-1"></i>حذف
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- Toggle Grade Status Modal -->
<div class="modal fade" id="toggleGradeStatusModal" tabindex="-1" aria-labelledby="toggleGradeStatusModalLabel"
    aria-hidden="true">
    <div class="modal-dialog">
        <div class="modal-content admin-modal admin-modal-premium admin-modal-warning" id="toggleGradeStatusModalContent">
            <div class="modal-header">
                <h5 class="modal-title" id="toggleGradeStatusModalLabel"><i class="fas fa-power-off"></i>تغيير حالة الصف</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body">
                <div class="text-center mb-3">
                    <i id="toggle-grade-status-icon" class="fas fa-check-circle text-primary"
                        style="font-size: 3rem;"></i>
                </div>
                <p class="text-center">هل أنت متأكد من <span id="grade_status_action"></span> الصف <span
                        class="fw-bold text-primary" id="toggle_grade_name"></span>؟</p>
                <div class="alert alert-info">
                    <i class="fas fa-info-circle me-2"></i>
                    <span id="grade_status_description"></span>
                </div>
            </div>
            <div class="modal-footer">
                <form method="post" action="grades.php" class="admin-modal-actions">
                    <?php echo csrfField(); ?>
                    <input type="hidden" id="toggle_grade_id" name="id">
                    <input type="hidden" id="toggle_new_status" name="new_status">
                    <input type="hidden" name="action" value="toggle_grade_status">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">إلغاء</button>
                    <button type="submit" id="toggle_grade_confirm_btn" class="btn btn-warning">تأكيد</button>
                </form>
            </div>
        </div>
    </div>
</div>

<!-- Import Grades Modal -->
<div class="modal fade" id="importGradesModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog">
        <div class="modal-content admin-modal admin-modal-premium admin-modal-create">
            <div class="modal-header">
                <h5 class="modal-title"><i class="fas fa-file-import me-2"></i>استيراد صفوف من Excel</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"
                    aria-label="Close"></button>
            </div>
            <div class="modal-body">

                <form id="importGradesForm" method="post" enctype="multipart/form-data" action="import_grades.php">
                    <input type="hidden" name="csrf_token"
                        value="<?php echo htmlspecialchars($_SESSION['csrf_token']); ?>">
                    <div class="mb-3">
                        <label for="gradesFile" class="form-label">اختر ملف Excel</label>
                        <input type="file" class="form-control" id="gradesFile" name="file" accept=".xlsx,.xls,.csv"
                            required>
                    </div>
                </form>
                <div class="alert alert-info mb-0 mt-3">
                    <div class="d-flex justify-content-between align-items-center mb-3 flex-wrap gap-2">
                        <h6 class="alert-heading fw-bold mb-0"><i class="fas fa-info-circle me-1"></i>تعليمات ملف الاستيراد:</h6>
                        <a href="download_template.php?type=grades" class="btn btn-sm btn-primary">
                            <i class="fas fa-download me-1"></i>تحميل نموذج فارغ
                        </a>
                    </div>
                    <p class="small text-danger mb-2 fw-bold border-bottom pb-2">
                        <i class="fas fa-exclamation-triangle me-1"></i>
                        تنبيه هام: يجب رفع الملف مع إبقاء السطر الأول (عناوين الأعمدة) كما هو دون تعديل أو حذف، وتعبئة البيانات بدءاً من السطر الثاني.
                    </p>
                    <p class="small mb-1">يجب أن يحتوي ملف الـ Excel على الأعمدة التالية بالترتيب أو المسمى:</p>
                    <ul class="small mb-0 ps-3">
                        <li><strong>اسم الصف</strong> (حقل مطلوب)</li>
                        <li>الكود</li>
                        <li>المرحلة</li>
                        <li>الترتيب (رقمي)</li>
                        <li>الوصف</li>
                    </ul>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">إلغاء</button>
                <button type="submit" class="btn btn-success" form="importGradesForm">
                    <i class="fas fa-upload me-1"></i>استيراد
                </button>
            </div>
        </div>
    </div>
</div>

<!-- Table Settings Modal -->
<div class="modal fade" id="tableSettingsModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog">
        <div class="modal-content admin-modal admin-modal-premium admin-modal-view">
            <div class="modal-header">
                <h5 class="modal-title"><i class="fas fa-cog me-2"></i>إعدادات أعمدة الجدول</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"
                    aria-label="Close"></button>
            </div>
            <div class="modal-body">
                <p>اختر الأعمدة التي تريد عرضها في الجدول:</p>
                <div class="form-check">
                    <input class="form-check-input col-toggle-checkbox" type="checkbox" id="chk_name"
                        data-column="col-name" checked>
                    <label class="form-check-label" for="chk_name">اسم الصف</label>
                </div>
                <div class="form-check">
                    <input class="form-check-input col-toggle-checkbox" type="checkbox" id="chk_stage"
                        data-column="col-stage" checked>
                    <label class="form-check-label" for="chk_stage">المرحلة</label>
                </div>
                <div class="form-check">
                    <input class="form-check-input col-toggle-checkbox" type="checkbox" id="chk_code"
                        data-column="col-code" checked>
                    <label class="form-check-label" for="chk_code">الكود</label>
                </div>
                <div class="form-check">
                    <input class="form-check-input col-toggle-checkbox" type="checkbox" id="chk_order"
                        data-column="col-order" checked>
                    <label class="form-check-label" for="chk_order">الترتيب</label>
                </div>
                <div class="form-check">
                    <input class="form-check-input col-toggle-checkbox" type="checkbox" id="chk_classes"
                        data-column="col-classes" checked>
                    <label class="form-check-label" for="chk_classes">عدد الفصول</label>
                </div>
                <div class="form-check">
                    <input class="form-check-input col-toggle-checkbox" type="checkbox" id="chk_count"
                        data-column="col-count" checked>
                    <label class="form-check-label" for="chk_count">عدد الطلاب</label>
                </div>
                <div class="form-check">
                    <input class="form-check-input col-toggle-checkbox" type="checkbox" id="chk_status"
                        data-column="col-status" checked>
                    <label class="form-check-label" for="chk_status">الحالة</label>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal"><i
                        class="fas fa-check me-1"></i>إغلاق</button>
            </div>
        </div>
    </div>
</div>

<script src="../assets/js/admin_table_actions.js"></script>
<script>
    (function () {
        "use strict";

        function initGradesPage() {
            if (typeof bootstrap === 'undefined') {
                console.error('Bootstrap is not loaded yet for grades page');
                return;
            }

            // ========== REORDER GRADES ==========
            document.querySelectorAll('.reorder-btn').forEach(function (btn) {
                btn.addEventListener('click', function () {
                    var id = this.getAttribute('data-id');
                    var direction = this.getAttribute('data-direction');
                    this.disabled = true;

                    var formData = new FormData();
                    formData.append('type', 'grade');
                    formData.append('id', id);
                    formData.append('direction', direction);
                    formData.append('csrf_token', '<?php echo htmlspecialchars($_SESSION['csrf_token'], ENT_QUOTES, 'UTF-8'); ?>');

                    fetch('../api/reorder.php', {
                        method: 'POST',
                        body: formData
                    })
                        .then(function (response) { return response.json(); })
                        .then(function (data) {
                            if (data.success) {
                                location.reload();
                            } else {
                                btn.disabled = false;
                            }
                        })
                        .catch(function (error) {
                            btn.disabled = false;
                            console.error('Reorder error:', error);
                        });
                });
            });

            var editButtons = document.querySelectorAll('.edit-grade');
            var deleteButtons = document.querySelectorAll('.delete-grade');
            var toggleButtons = document.querySelectorAll('.toggle-grade-status');

            // Handle edit button click
            editButtons.forEach(function (btn) {
                btn.addEventListener('click', function () {
                    var gradeId = this.getAttribute('data-id');
                    var gradeName = this.getAttribute('data-name');
                    var gradeCode = this.getAttribute('data-code');
                    var gradeOrder = this.getAttribute('data-order');
                    var stageId = this.getAttribute('data-stage');
                    var description = this.getAttribute('data-description') || '';
                    var isExperimental = this.getAttribute('data-experimental') === '1';

                    document.getElementById('edit_grade_id').value = gradeId;
                    document.getElementById('edit_grade_name').value = gradeName;
                    document.getElementById('edit_grade_code').value = gradeCode;
                    document.getElementById('edit_grade_order').value = gradeOrder;
                    document.getElementById('edit_stage_id').value = stageId;
                    document.getElementById('edit_description').value = description;
                    document.getElementById('edit_is_experimental').checked = isExperimental;

                    var modal = new bootstrap.Modal(document.getElementById('editGradeModal'));
                    modal.show();
                });
            });

            // Handle delete button click
            deleteButtons.forEach(function (btn) {
                btn.addEventListener('click', function () {
                    var gradeId = this.getAttribute('data-id');
                    var gradeName = this.getAttribute('data-name');

                    document.getElementById('delete_grade_id').value = gradeId;
                    document.getElementById('delete_grade_name').textContent = gradeName;
                });
            });

            // Handle toggle status button click
            toggleButtons.forEach(function (btn) {
                btn.addEventListener('click', function () {
                    var gradeId = this.getAttribute('data-id');
                    var gradeName = this.getAttribute('data-name');
                    var newStatus = this.getAttribute('data-status');
                    var actionText = this.getAttribute('data-action');

                    // Update modal content
                    document.getElementById('toggle_grade_id').value = gradeId;
                    document.getElementById('toggle_new_status').value = newStatus;
                    document.getElementById('toggle_grade_name').textContent = gradeName;
                    document.getElementById('grade_status_action').textContent = actionText;

                    // Update modal description and button based on action
                    var descriptionElement = document.getElementById('grade_status_description');
                    var confirmButton = document.getElementById('toggle_grade_confirm_btn');
                    var modalContent = document.getElementById('toggleGradeStatusModalContent');
                    var iconElement = document.getElementById('toggle-grade-status-icon');
                    var modalTitle = document.getElementById('toggleGradeStatusModalLabel');

                    if (newStatus === 'inactive') {
                        descriptionElement.textContent = 'سيتم تعطيل الصف ولن يظهر في القوائم، ولكن ستبقى جميع الفصول والطلاب محفوظة.';
                        if (confirmButton) {
                            confirmButton.className = 'btn btn-warning';
                            confirmButton.textContent = 'تعطيل';
                        }
                        if (modalTitle) modalTitle.textContent = 'تعطيل الصف';
                        if (modalContent) {
                            modalContent.classList.remove('admin-modal-create');
                            modalContent.classList.add('admin-modal-warning');
                        }
                        if (iconElement) iconElement.className = 'fas fa-ban text-warning admin-modal-icon-lg';
                    } else {
                        descriptionElement.textContent = 'سيتم تفعيل الصف وسيظهر في القوائم ويمكن استخدامه مرة أخرى.';
                        if (confirmButton) {
                            confirmButton.className = 'btn btn-success';
                            confirmButton.textContent = 'تفعيل';
                        }
                        if (modalTitle) modalTitle.textContent = 'تفعيل الصف';
                        if (modalContent) {
                            modalContent.classList.remove('admin-modal-warning');
                            modalContent.classList.add('admin-modal-create');
                        }
                        if (iconElement) iconElement.className = 'fas fa-check-circle text-success admin-modal-icon-lg';
                    }
                });
            });

            // Stage filter functionality
            var stageFilter = document.getElementById('stageFilter');

            if (typeof $ !== 'undefined' && $.fn.dataTable) {
                $.fn.dataTable.ext.search.push(
                    function (settings, data, dataIndex) {
                        if (settings.nTable.id !== 'gradesTable') {
                            return true;
                        }

                        var stageVal = stageFilter ? stageFilter.value.trim() : '';
                        var stageText = (data[2] || '').trim(); // Index 2 is Stage

                        if (stageVal && stageText !== stageVal) {
                            return false;
                        }
                        return true;
                    }
                );
            }

            function filterGradesTable() {
                if (typeof $ !== 'undefined' && $.fn.dataTable && $.fn.dataTable.isDataTable('#gradesTable')) {
                    $('#gradesTable').DataTable().draw();
                } else {
                    var stageValue = stageFilter ? stageFilter.value.toLowerCase().trim() : '';
                    var table = document.getElementById('gradesTable');
                    if (!table) return;

                    var rows = table.getElementsByTagName('tbody')[0].getElementsByTagName('tr');
                    var visibleCount = 0;

                    for (var i = 0; i < rows.length; i++) {
                        var row = rows[i];
                        var stageTd = row.cells[2]; // المرحلة في العمود الثالث

                        if (!stageTd) continue;

                        var stageText = stageTd.textContent.toLowerCase().trim();
                        var showRow = true;

                        // Filter by stage
                        if (stageValue && stageText !== stageValue && !stageText.includes(stageValue)) {
                            showRow = false;
                        }

                        row.style.display = showRow ? '' : 'none';
                        if (showRow) visibleCount++;
                    }

                    console.log('Filtered grades - Visible rows:', visibleCount);
                }
            }

            if (stageFilter) {
                stageFilter.addEventListener('change', function () {
                    console.log('Stage filter changed:', this.value);
                    filterGradesTable();
                });
            }

            // Initialize tooltips
            var tooltipTriggerList = [].slice.call(document.querySelectorAll('[data-bs-toggle="tooltip"]'));
            tooltipTriggerList.map(function (tooltipTriggerEl) {
                return new bootstrap.Tooltip(tooltipTriggerEl);
            });
        }

        document.addEventListener('DOMContentLoaded', initGradesPage);
    })();

    document.addEventListener('DOMContentLoaded', function () {
        if (typeof initializeTableColumnSettings === 'function') {
            initializeTableColumnSettings('gradesTable', {
                chk_name: 1,
                chk_stage: 2,
                chk_code: 3,
                chk_order: 4,
                chk_classes: 5,
                chk_count: 6,
                chk_status: 7
            }, 'grades_table_columns');
        }
    });

    // وظيفة تصدير جدول الصفوف لملف CSV
    function exportGradesTableToCSV() {
        exportTableToCsv('gradesTable', 'grades_list_' + new Date().toISOString().slice(0, 10) + '.csv');
    }

    function exportGradesToPDF() {
        exportTableToPdf('gradesTable', 'إدارة الصفوف الدراسية');
    }
</script>

<?php
// Include footer
include_once '../includes/admin_footer.php';
?>
