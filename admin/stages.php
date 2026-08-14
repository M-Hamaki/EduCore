<?php
// Set page title
$page_title = "إدارة المراحل الدراسية";
$custom_page_title = true;

// Include database and necessary classes
require_once '../config/database.php';
require_once '../classes/utilities.php';
require_once '../classes/ActivityLog.php';
require_once '../classes/UndoManager.php';
require_once '../classes/AcademicYear.php';
require_once '../src/Modules/Operations/Audit/AuditService.php';
require_once '../src/Modules/AcademicStructure/ExperimentalAcademicScopePolicy.php';

// Auth validation before any processing
require_once '../includes/session_config.php';
require_once '../includes/csrf.php';
Utilities::validateSession('admin');
requireCsrfPost();

// Initialize database connection
$database = new Database();
$db = $database->getConnection();
UndoManager::setDb($db);
$experimentalScopePolicy = new \EduCore\Modules\AcademicStructure\ExperimentalAcademicScopePolicy($db);
$experimentalScopePolicy->assertSchemaReady();
$auditService = new \EduCore\Modules\Operations\Audit\AuditService($db);
$currentAcademicYearId = AcademicYear::currentId($db);

// Get messages from session
$success_message = $_SESSION['success_message'] ?? null;
$error_message = $_SESSION['error_message'] ?? null;
unset($_SESSION['success_message'], $_SESSION['error_message']);

// تعيين ترميز UTF-8
$db->exec("SET NAMES 'utf8mb4'");
$db->exec("SET CHARACTER SET utf8mb4");

// الخدمات المتاحة للطلاب
$available_services = [
    'rewards' => 'نظام المكافآت',
    'reports' => 'التقارير الشهرية',
    'materials' => 'المواد التعليمية',
    'ebooks' => 'الكتب الإلكترونية',
    'results' => 'النتائج',
    'timetable' => 'الجدول الدراسي',
    'activities' => 'الأنشطة التفاعلية'
];

// الخدمات المتاحة للمعلمين
$available_teacher_services = [
    'rewards' => 'نظام المكافآت',
    'lesson_prep' => 'تحضير الدروس بالذكاء الاصطناعي',
    'grade_system' => 'نظام رصد الدرجات',
    'attendance' => 'نظام الحضور والغياب',
    'timetable' => 'الجدول المدرسي',
    'training' => 'التطوير المهني والتدريبات',
    'activities' => 'الأنشطة التفاعلية',
    'ai_chat' => 'المساعد الذكي AI'
];

// Handle AJAX requests
if (isset($_GET['action']) && $_GET['action'] === 'get_grades') {
    header('Content-Type: application/json');
    $stage_id = intval($_GET['stage_id']);

    // الصفوف المرتبطة بهذه المرحلة
    $assigned_query = "SELECT id, grade_name FROM grades WHERE stage_id = ? ORDER BY grade_order";
    $assigned_stmt = $db->prepare($assigned_query);
    $assigned_stmt->execute([$stage_id]);
    $assigned = $assigned_stmt->fetchAll(PDO::FETCH_ASSOC);

    // الصفوف غير المرتبطة بأي مرحلة (فقط التي stage_id = NULL)
    $available_query = "SELECT id, grade_name FROM grades WHERE stage_id IS NULL ORDER BY grade_order";
    $available_stmt = $db->prepare($available_query);
    $available_stmt->execute();
    $available = $available_stmt->fetchAll(PDO::FETCH_ASSOC);

    echo json_encode(['assigned' => $assigned, 'available' => $available]);
    exit;
}


$normalizeSelections = static function ($raw, array $allowed, string $label): array {
    if ($raw === null) {
        return [];
    }
    if (!is_array($raw)) {
        throw new InvalidArgumentException('بيانات ' . $label . ' غير صالحة.');
    }
    $result = [];
    foreach ($raw as $value) {
        if (!is_string($value) || !array_key_exists($value, $allowed)) {
            throw new InvalidArgumentException('تتضمن ' . $label . ' قيمة غير مسموحة.');
        }
        $result[$value] = true;
    }
    return array_keys($result);
};

$normalizeStagePayload = static function (array $payload) use ($available_services, $available_teacher_services, $normalizeSelections): array {
    $name = trim((string) ($payload['stage_name'] ?? ''));
    $nameEn = trim((string) ($payload['stage_name_en'] ?? ''));
    $code = trim((string) ($payload['stage_code'] ?? ''));
    $order = filter_var($payload['stage_order'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
    $portalDescription = trim((string) ($payload['portal_description'] ?? ''));
    if ($name === '' || mb_strlen($name, 'UTF-8') > 100 || mb_strlen($nameEn, 'UTF-8') > 100) {
        throw new InvalidArgumentException('اسم المرحلة مطلوب ويجب ألا يتجاوز 100 حرف.');
    }
    if ($code === '' || mb_strlen($code, 'UTF-8') > 50) {
        throw new InvalidArgumentException('كود المرحلة مطلوب ويجب ألا يتجاوز 50 حرفاً.');
    }
    if ($order === false) {
        throw new InvalidArgumentException('ترتيب المرحلة يجب أن يكون رقماً موجباً.');
    }
    if (mb_strlen($portalDescription, 'UTF-8') > 255) {
        throw new InvalidArgumentException('وصف البوابة يجب ألا يتجاوز 255 حرفاً.');
    }
    $services = $normalizeSelections($payload['services'] ?? null, $available_services, 'خدمات الطلاب');
    $teacherServices = $normalizeSelections($payload['teacher_services'] ?? null, $available_teacher_services, 'خدمات المعلمين');
    $newBadges = array_values(array_intersect($normalizeSelections($payload['new_badges'] ?? null, $available_services, 'شارات الطلاب'), $services));
    $teacherNewBadges = array_values(array_intersect($normalizeSelections($payload['teacher_new_badges'] ?? null, $available_teacher_services, 'شارات المعلمين'), $teacherServices));
    return [
        'stage_name' => $name,
        'stage_name_en' => $nameEn,
        'stage_code' => $code,
        'stage_order' => (int) $order,
        'services' => json_encode($services, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR),
        'teacher_services' => json_encode($teacherServices, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR),
        'new_badges' => json_encode($newBadges, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR),
        'teacher_new_badges' => json_encode($teacherNewBadges, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR),
        'portal_visible' => !empty($payload['portal_visible']) ? 1 : 0,
        'portal_description' => $portalDescription !== '' ? $portalDescription : null,
        'is_experimental' => !empty($payload['is_experimental']) ? 1 : 0,
    ];
};

// Process form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = is_string($_POST['action'] ?? null) ? $_POST['action'] : '';
    if (in_array($action, ['link_grade', 'unlink_grade'], true)) {
        header('Content-Type: application/json; charset=utf-8');
        try {
            $gradeId = filter_var($_POST['grade_id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
            $stageId = $action === 'link_grade'
                ? filter_var($_POST['stage_id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]])
                : null;
            if ($gradeId === false || ($action === 'link_grade' && $stageId === false)) {
                throw new InvalidArgumentException('معرّفات الربط غير صالحة.');
            }
            $db->beginTransaction();
            $beforeStmt = $db->prepare('SELECT * FROM grades WHERE id = ? FOR UPDATE');
            $beforeStmt->execute([(int) $gradeId]);
            $before = $beforeStmt->fetch(PDO::FETCH_ASSOC);
            if (!$before) {
                throw new InvalidArgumentException('الصف الدراسي غير موجود.');
            }
            $experimentalScopePolicy->assertGradeTransition((int) $gradeId, $stageId !== null ? (int) $stageId : null, (int) ($before['is_experimental'] ?? 0) === 1);
            $targetStageId = $stageId !== null ? (int) $stageId : null;
            if (($before['stage_id'] !== null ? (int) $before['stage_id'] : null) !== $targetStageId) {
                $db->prepare('UPDATE grades SET stage_id = ? WHERE id = ?')->execute([$targetStageId, (int) $gradeId]);
                $afterStmt = $db->prepare('SELECT * FROM grades WHERE id = ?');
                $afterStmt->execute([(int) $gradeId]);
                $after = $afterStmt->fetch(PDO::FETCH_ASSOC) ?: [];
                $auditService->recordUpdate('grade', 'grades', (int) $gradeId, (string) ($before['grade_name'] ?? ''), $before, $after, $action === 'link_grade' ? 'ربط صف بمرحلة' : 'إلغاء ربط صف بمرحلة');
            }
            $db->commit();
            echo json_encode(['success' => true], JSON_UNESCAPED_UNICODE);
        } catch (InvalidArgumentException $e) {
            if ($db->inTransaction()) { $db->rollBack(); }
            http_response_code(422);
            echo json_encode(['success' => false, 'message' => $e->getMessage()], JSON_UNESCAPED_UNICODE);
        } catch (Throwable $e) {
            if ($db->inTransaction()) { $db->rollBack(); }
            error_log('Stage grade link operation failed: ' . $e->getMessage());
            http_response_code(500);
            echo json_encode(['success' => false, 'message' => 'تعذر تنفيذ عملية ربط الصف حالياً.'], JSON_UNESCAPED_UNICODE);
        }
        exit;
    }

    try {
        if (isset($_POST['add_stage'])) {
            $stage = $normalizeStagePayload($_POST);
            $db->beginTransaction();
            $stmt = $db->prepare('INSERT INTO stages (stage_name, stage_name_en, stage_code, stage_order, services, teacher_services, new_badges, teacher_new_badges, portal_visible, portal_description, is_experimental) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)');
            $stmt->execute(array_values($stage));
            $stageId = (int) $db->lastInsertId();
            $afterStmt = $db->prepare('SELECT * FROM stages WHERE id = ?');
            $afterStmt->execute([$stageId]);
            $after = $afterStmt->fetch(PDO::FETCH_ASSOC) ?: [];
            $auditService->recordInsert('stage', 'stages', $stageId, $stage['stage_name'], $after, 'إضافة مرحلة');
            $db->commit();
            $_SESSION['success_message'] = 'تم إضافة المرحلة بنجاح.';
        } elseif (isset($_POST['edit_stage'])) {
            $stageId = filter_var($_POST['id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
            if ($stageId === false) {
                throw new InvalidArgumentException('معرّف المرحلة غير صالح.');
            }
            $stage = $normalizeStagePayload($_POST);
            $db->beginTransaction();
            $beforeStmt = $db->prepare('SELECT * FROM stages WHERE id = ? FOR UPDATE');
            $beforeStmt->execute([(int) $stageId]);
            $before = $beforeStmt->fetch(PDO::FETCH_ASSOC);
            if (!$before) {
                throw new InvalidArgumentException('المرحلة غير موجودة.');
            }
            $experimentalScopePolicy->assertStageTransition((int) $stageId, $stage['is_experimental'] === 1);
            $stmt = $db->prepare('UPDATE stages SET stage_name = ?, stage_name_en = ?, stage_code = ?, stage_order = ?, services = ?, teacher_services = ?, new_badges = ?, teacher_new_badges = ?, portal_visible = ?, portal_description = ?, is_experimental = ? WHERE id = ?');
            $stmt->execute([...array_values($stage), (int) $stageId]);
            $afterStmt = $db->prepare('SELECT * FROM stages WHERE id = ?');
            $afterStmt->execute([(int) $stageId]);
            $after = $afterStmt->fetch(PDO::FETCH_ASSOC) ?: [];
            if ($before != $after) {
                $auditService->recordUpdate('stage', 'stages', (int) $stageId, $stage['stage_name'], $before, $after, 'تعديل مرحلة');
            }
            $db->commit();
            $_SESSION['success_message'] = 'تم تحديث المرحلة بنجاح.';
        } elseif ($action === 'delete_stage') {
            $stageId = filter_var($_POST['id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
            if ($stageId === false) {
                throw new InvalidArgumentException('معرّف المرحلة غير صالح.');
            }
            $db->beginTransaction();
            $beforeStmt = $db->prepare('SELECT * FROM stages WHERE id = ? FOR UPDATE');
            $beforeStmt->execute([(int) $stageId]);
            $before = $beforeStmt->fetch(PDO::FETCH_ASSOC);
            if (!$before) {
                throw new InvalidArgumentException('المرحلة غير موجودة أو حُذفت مسبقاً.');
            }
            $gradesStmt = $db->prepare('SELECT COUNT(*) FROM grades WHERE stage_id = ?');
            $gradesStmt->execute([(int) $stageId]);
            $gradesCount = (int) $gradesStmt->fetchColumn();
            if ($gradesCount > 0) {
                throw new InvalidArgumentException('لا يمكن حذف المرحلة لأنها مرتبطة بـ ' . $gradesCount . ' صف دراسي. يمكنك تعطيلها بدلاً من ذلك.');
            }
            $deleteStmt = $db->prepare('DELETE FROM stages WHERE id = ?');
            $deleteStmt->execute([(int) $stageId]);
            if ($deleteStmt->rowCount() !== 1) {
                throw new RuntimeException('Stage deletion affected no rows.');
            }
            $auditService->recordDelete('stage', 'stages', (int) $stageId, (string) ($before['stage_name'] ?? ''), $before, 'حذف مرحلة');
            $db->commit();
            $_SESSION['success_message'] = 'تم حذف المرحلة بنجاح.';
        } elseif ($action === 'toggle_stage_status') {
            $stageId = filter_var($_POST['id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
            $newStatus = is_string($_POST['new_status'] ?? null) ? $_POST['new_status'] : '';
            if ($stageId === false || !in_array($newStatus, ['active', 'inactive'], true)) {
                throw new InvalidArgumentException('بيانات حالة المرحلة غير صالحة.');
            }
            $db->beginTransaction();
            $beforeStmt = $db->prepare('SELECT * FROM stages WHERE id = ? FOR UPDATE');
            $beforeStmt->execute([(int) $stageId]);
            $before = $beforeStmt->fetch(PDO::FETCH_ASSOC);
            if (!$before) {
                throw new InvalidArgumentException('المرحلة غير موجودة.');
            }
            if (($before['status'] ?? '') !== $newStatus) {
                $db->prepare('UPDATE stages SET status = ? WHERE id = ?')->execute([$newStatus, (int) $stageId]);
                $afterStmt = $db->prepare('SELECT * FROM stages WHERE id = ?');
                $afterStmt->execute([(int) $stageId]);
                $after = $afterStmt->fetch(PDO::FETCH_ASSOC) ?: [];
                $auditService->recordUpdate('stage', 'stages', (int) $stageId, (string) ($before['stage_name'] ?? ''), $before, $after, 'تغيير حالة مرحلة');
            }
            $db->commit();
            $_SESSION['success_message'] = 'تم تحديث حالة المرحلة بنجاح.';
        }
    } catch (InvalidArgumentException $e) {
        if ($db->inTransaction()) { $db->rollBack(); }
        $_SESSION['error_message'] = $e->getMessage();
    } catch (Throwable $e) {
        if ($db->inTransaction()) { $db->rollBack(); }
        error_log('Stages write failed: ' . $e->getMessage());
        $_SESSION['error_message'] = $e instanceof PDOException && $e->getCode() === '23000'
            ? 'تعذر تنفيذ العملية بسبب ارتباط المرحلة ببيانات أخرى أو تكرار الكود.'
            : 'تعذر تنفيذ العملية على المرحلة. يرجى إعادة المحاولة.';
    }
    header('Location: stages.php');
    exit();
}

// Fetch all stages with grade count, classes count and students count
$query = "SELECT s.*, 
          COUNT(DISTINCT g.id) as grades_count,
          COUNT(DISTINCT c.id) as classes_count,
          COUNT(DISTINCT u.id) as students_count
          FROM stages s 
          LEFT JOIN grades g ON s.id = g.stage_id 
          LEFT JOIN classes c ON g.id = c.grade_id" . ($currentAcademicYearId > 0 ? " AND (c.academic_year_id = " . (int) $currentAcademicYearId . " OR c.academic_year_id IS NULL)" : "") . "
          " . ($currentAcademicYearId > 0
    ? "LEFT JOIN student_enrollments se ON se.class_id = c.id AND se.academic_year_id = " . (int) $currentAcademicYearId . " AND se.enrollment_status = 'enrolled'
                 LEFT JOIN users u ON u.id = se.student_id AND u.role = 'student' AND u.status = 'active' AND u.deleted_at IS NULL"
    : "LEFT JOIN users u ON c.id = u.class_id AND u.role = 'student' AND u.status = 'active' AND u.deleted_at IS NULL") . "
          GROUP BY s.id 
          ORDER BY s.stage_order ASC";
$stmt = $db->prepare($query);
$stmt->execute();
$stages = $stmt->fetchAll(PDO::FETCH_ASSOC);
$stages_count = count($stages);

// Include header
require_once '../includes/admin_header.php';
?>

<div class="admin-page-heading">
    <h1 class="h2"><i class="fas fa-layer-group me-2 text-primary"></i>إدارة المراحل الدراسية <span
            class="badge bg-light text-dark border ms-2"><?php echo $stages_count; ?></span></h1>
    <div class="admin-top-actions">
        <button class="btn btn-header-premium btn-success shadow-sm" data-bs-toggle="modal"
            data-bs-target="#addStageModal">
            <i class="fas fa-plus-circle me-1"></i>إضافة مرحلة
        </button>
        <button class="btn btn-header-premium btn-import-soft" data-bs-toggle="modal"
            data-bs-target="#importStagesModal">
            <i class="fas fa-file-import me-1"></i>استيراد Excel
        </button>
        <a href="export_stages.php" class="btn btn-header-premium btn-export-soft">
            <i class="fas fa-file-export me-1"></i>تصدير Excel
        </a>
    </div>
</div>

<div class="container-fluid">
    <div class="row">
        <div class="col-12">
            <!-- Filter/Actions Bar -->
            <div class="admin-filter-bar">
                <div class="admin-filter-controls">
                    <!-- No select filters, so we keep it empty or place nothing -->
                </div>
                <div class="admin-filter-actions">
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
                        <i class="fas fa-check-circle me-2"></i><?php echo htmlspecialchars($success_message); ?>
                        <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                    </div>
                <?php endif; ?>

                <?php if (!empty($error_message)): ?>
                    <div class="alert alert-danger alert-dismissible fade show" role="alert">
                        <i class="fas fa-exclamation-circle me-2"></i><?php echo htmlspecialchars($error_message); ?>
                        <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                    </div>
                <?php endif; ?>

                <div class="admin-table-wrap">
<table class="table table-striped table-hover datatable admin-data-table" id="stagesTable">
                        <thead class="table-light">
                            <tr>
                                <th class="col-order">الترتيب</th>
                                <th class="col-name">اسم المرحلة</th>
                                <th class="col-name-en">الاسم بالإنجليزية</th>
                                <th class="col-student-services">خدمات الطلاب</th>
                                <th class="col-teacher-services">خدمات المعلمين</th>
                                <th class="col-grades">عدد الصفوف</th>
                                <th class="col-classes">عدد الفصول</th>
                                <th class="col-students">عدد الطلاب</th>
                                <th class="col-status">الحالة</th>
                                <th>الإجراءات</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($stages as $stage): ?>
                                <?php
                                // فك تشفير JSON الخدمات
                                $stage_services = json_decode($stage['services'], true);
                                if (!is_array($stage_services)) {
                                    $stage_services = [];
                                }
                                $stage_teacher_services = json_decode($stage['teacher_services'], true);
                                if (!is_array($stage_teacher_services)) {
                                    $stage_teacher_services = [];
                                }
                                ?>
                                <tr>
                                    <td class="col-order align-middle text-center"><span
                                            class="badge bg-light text-dark border fw-bold px-2 py-1"><?php echo htmlspecialchars($stage['stage_order']); ?></span>
                                    </td>
                                    <td class="col-name align-middle">
                                        <div class="d-flex align-items-center">
                                            <div class="avatar-sm bg-primary bg-opacity-10 text-primary rounded-circle me-2 d-flex align-items-center justify-content-center fw-bold"
                                                style="width:32px; height:32px; flex-shrink:0;">
                                                <i class="fas fa-layer-group fs-7"></i>
                                            </div>
                                            <span class="fw-bold text-dark"><?php echo htmlspecialchars($stage['stage_name']); ?></span>
                                            <?php if (!empty($stage['is_experimental'])): ?>
                                                <span class="badge bg-warning text-dark ms-2">
                                                    <i class="fas fa-flask me-1"></i>تجريبية
                                                </span>
                                            <?php endif; ?>
                                        </div>
                                    </td>
                                    <td class="col-name-en align-middle text-secondary fw-semibold fs-7">
                                        <?php echo htmlspecialchars($stage['stage_name_en']); ?></td>
                                    <td class="col-student-services align-middle text-center">
                                        <?php
                                        $student_service_names = [];
                                        foreach ($stage_services as $service) {
                                            if (isset($available_services[$service])) {
                                                $student_service_names[] = $available_services[$service];
                                            }
                                        }
                                        if (empty($student_service_names)):
                                            ?>
                                            <span class="text-muted fs-7 fst-italic">لا توجد خدمات</span>
                                        <?php else: ?>
                                            <div class="dropdown">
                                                <button
                                                    class="btn btn-sm btn-outline-danger dropdown-toggle rounded-pill py-1 px-3 fs-7"
                                                    type="button" aria-expanded="false">
                                                    <i
                                                        class="fas fa-layer-group me-1"></i><?php echo count($student_service_names); ?>
                                                    خدمات
                                                </button>
                                                <ul class="dropdown-menu dropdown-menu-end shadow-sm border-0 fs-7">
                                                    <?php foreach ($student_service_names as $name): ?>
                                                        <li><span class="dropdown-item"><i
                                                                    class="fas fa-check-circle text-danger me-2"></i><?php echo htmlspecialchars($name); ?></span>
                                                        </li>
                                                    <?php endforeach; ?>
                                                </ul>
                                            </div>
                                        <?php endif; ?>
                                    </td>
                                    <td class="col-teacher-services align-middle text-center">
                                        <?php
                                        $teacher_service_names = [];
                                        foreach ($stage_teacher_services as $service) {
                                            if (isset($available_teacher_services[$service])) {
                                                $teacher_service_names[] = $available_teacher_services[$service];
                                            }
                                        }
                                        if (empty($teacher_service_names)):
                                            ?>
                                            <span class="text-muted fs-7 fst-italic">لا توجد خدمات</span>
                                        <?php else: ?>
                                            <div class="dropdown">
                                                <button
                                                    class="btn btn-sm btn-outline-primary dropdown-toggle rounded-pill py-1 px-3 fs-7"
                                                    type="button" aria-expanded="false">
                                                    <i
                                                        class="fas fa-layer-group me-1"></i><?php echo count($teacher_service_names); ?>
                                                    خدمات
                                                </button>
                                                <ul class="dropdown-menu dropdown-menu-end shadow-sm border-0 fs-7">
                                                    <?php foreach ($teacher_service_names as $name): ?>
                                                        <li><span class="dropdown-item"><i
                                                                    class="fas fa-check-circle text-primary me-2"></i><?php echo htmlspecialchars($name); ?></span>
                                                        </li>
                                                    <?php endforeach; ?>
                                                </ul>
                                            </div>
                                        <?php endif; ?>
                                    </td>
                                    <td class="col-grades align-middle text-center"><span
                                            class="fw-bold text-dark"><?php echo $stage['grades_count']; ?></span></td>
                                    <td class="col-classes align-middle text-center"><span
                                            class="fw-bold text-primary"><?php echo $stage['classes_count']; ?></span></td>
                                    <td class="col-students align-middle text-center"><span
                                            class="fw-bold text-success"><?php echo $stage['students_count']; ?></span></td>
                                    <td class="col-status align-middle text-center">
                                        <?php if ($stage['status'] == 'active'): ?>
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
                                        <button type="button" class="btn btn-action-pills btn-edit me-1"
                                            onclick="editStage(<?php echo htmlspecialchars(json_encode($stage, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_UNESCAPED_UNICODE), ENT_QUOTES, 'UTF-8'); ?>)"
                                            data-bs-toggle="tooltip" title="تعديل">
                                            <i class="fas fa-edit"></i>
                                        </button>

                                        <button type="button" class="btn btn-action-pills btn-grades me-1"
                                            onclick="manageGrades(<?php echo (int) $stage['id']; ?>, <?php echo htmlspecialchars(json_encode((string) $stage['stage_name'], JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_UNESCAPED_UNICODE), ENT_QUOTES, 'UTF-8'); ?>)"
                                            data-bs-toggle="tooltip" title="إدارة الصفوف">
                                            <i class="fas fa-graduation-cap"></i>
                                        </button>

                                        <?php if ($stage['status'] == 'active'): ?>
                                            <button type="button"
                                                class="btn btn-action-pills btn-deactivate me-1 toggle-stage-status"
                                                data-id="<?php echo $stage['id']; ?>"
                                                data-name="<?php echo htmlspecialchars($stage['stage_name'], ENT_QUOTES, 'UTF-8'); ?>"
                                                data-status="inactive" data-action="تعطيل" data-bs-toggle="tooltip" title="تعطيل">
                                                <i class="fas fa-ban"></i>
                                            </button>
                                        <?php else: ?>
                                            <button type="button"
                                                class="btn btn-action-pills btn-activate me-1 toggle-stage-status"
                                                data-id="<?php echo $stage['id']; ?>"
                                                data-name="<?php echo htmlspecialchars($stage['stage_name'], ENT_QUOTES, 'UTF-8'); ?>"
                                                data-status="active" data-action="تفعيل" data-bs-toggle="tooltip" title="تفعيل">
                                                <i class="fas fa-check"></i>
                                            </button>
                                        <?php endif; ?>

                                        <button type="button" class="btn btn-action-pills btn-delete delete-stage"
                                            data-id="<?php echo $stage['id']; ?>"
                                            data-name="<?php echo htmlspecialchars($stage['stage_name'], ENT_QUOTES, 'UTF-8'); ?>"
                                            data-bs-toggle="tooltip" title="حذف">
                                            <i class="fas fa-trash"></i>
                                        </button>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div> <!-- admin-table-wrap -->
            </div> <!-- admin-list-surface -->
        </div>
    </div>
</div>

<!-- Add Stage Modal -->
<div class="modal fade" id="addStageModal" tabindex="-1">
    <div class="modal-dialog modal-lg">
        <div class="modal-content admin-modal admin-modal-premium admin-modal-create">
            <div class="modal-header">
                <h5 class="modal-title"><i class="fas fa-plus me-2"></i>إضافة مرحلة دراسية جديدة</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
<form method="POST" data-confirm-submit="false">
    <?php echo csrfField(); ?>
                <div class="modal-body">
                    <!-- التبويبات لتنظيم النموذج وتقليص حجمه -->
                    <ul class="nav nav-tabs admin-tabs mb-3" id="addStageTabs" role="tablist">
                        <li class="nav-item" role="presentation">
                            <button class="nav-link active" id="add-basic-tab" data-bs-toggle="tab"
                                data-bs-target="#add-basic-panel" type="button" role="tab"
                                aria-controls="add-basic-panel" aria-selected="true">
                                <i class="fas fa-info-circle me-1"></i>البيانات الأساسية
                            </button>
                        </li>
                        <li class="nav-item" role="presentation">
                            <button class="nav-link" id="add-services-tab" data-bs-toggle="tab"
                                data-bs-target="#add-services-panel" type="button" role="tab"
                                aria-controls="add-services-panel" aria-selected="false">
                                <i class="fas fa-concierge-bell me-1"></i>الخدمات المتاحة
                            </button>
                        </li>
                    </ul>

                    <div class="tab-content" id="addStageTabsContent">
                        <!-- تبويب البيانات الأساسية -->
                        <div class="tab-pane fade show active" id="add-basic-panel" role="tabpanel"
                            aria-labelledby="add-basic-tab">
                            <!-- قسم البيانات الأساسية -->
                            <div class="card border-0 bg-light p-3 rounded-3 mb-3">
                                <h6 class="fw-bold text-primary mb-3 border-bottom pb-2"><i
                                        class="fas fa-info-circle me-2"></i>البيانات الأساسية للمرحلة</h6>
                                <div class="row g-3 mb-2">
                                    <div class="col-md-6">
                                        <label class="form-label fw-bold"><i
                                                class="fas fa-layer-group text-secondary me-1"></i>اسم المرحلة (عربي) <span
                                                class="text-danger">*</span></label>
                                        <input type="text" class="form-control bg-white" name="stage_name" required
                                            placeholder="مثال: المرحلة الابتدائية">
                                    </div>
                                    <div class="col-md-6">
                                        <label class="form-label fw-bold"><i class="fas fa-globe text-secondary me-1"></i>اسم
                                            المرحلة (English) <span class="text-danger">*</span></label>
                                        <input type="text" class="form-control bg-white" name="stage_name_en" required
                                            placeholder="Example: Primary">
                                    </div>
                                </div>
                                <div class="row g-3">
                                    <div class="col-md-6">
                                        <label class="form-label fw-bold"><i class="fas fa-code text-secondary me-1"></i>كود
                                            المرحلة <span class="text-danger">*</span></label>
                                        <input type="text" class="form-control bg-white" name="stage_code" required
                                            placeholder="مثال: primary">
                                        <small class="text-muted fs-8">حروف إنجليزية صغيرة فقط بدون مسافات</small>
                                    </div>
                                    <div class="col-md-6">
                                        <label class="form-label fw-bold"><i
                                                class="fas fa-sort-numeric-down text-secondary me-1"></i>ترتيب المرحلة <span
                                                class="text-danger">*</span></label>
                                        <input type="number" class="form-control bg-white" name="stage_order" required value="1"
                                            min="1">
                                    </div>
                                </div>
                                <div class="alert alert-warning mt-3 mb-0">
                                    <div class="form-check form-switch mb-1">
                                        <input class="form-check-input" type="checkbox" role="switch"
                                            name="is_experimental" value="1" id="add_stage_is_experimental">
                                        <label class="form-check-label fw-bold" for="add_stage_is_experimental">
                                            <i class="fas fa-flask me-1"></i>مرحلة تجريبية
                                        </label>
                                    </div>
                                    <small>كل الصفوف والفصول التابعة لهذه المرحلة ستُعامل كتجريبية تلقائيًا.</small>
                                </div>
                            </div>

                            <!-- قسم إعدادات البوابة الرئيسية -->
                            <div class="card border-0 bg-light p-3 rounded-3 mb-0">
                                <div class="d-flex align-items-center justify-content-between mb-2">
                                    <label class="form-check-label fw-bold text-dark mb-0" for="add_portal_visible">
                                        <i class="fas fa-window-restore text-primary me-2"></i>عرض بطاقة هذه المرحلة في البوابة
                                        الرئيسية
                                    </label>
                                    <div class="form-check form-switch mb-0 ps-0">
                                        <input class="form-check-input" type="checkbox" role="switch" name="portal_visible"
                                            id="add_portal_visible" value="1" checked
                                            style="width: 2.5em; height: 1.25em; cursor: pointer;">
                                    </div>
                                </div>
                                <div>
                                    <label class="form-label text-secondary fs-7 fw-bold" for="add_portal_description"><i
                                            class="fas fa-align-left me-1"></i>وصف بطاقة المرحلة في البوابة الرئيسية</label>
                                    <textarea class="form-control bg-white" name="portal_description"
                                        id="add_portal_description" rows="2" maxlength="255"
                                        placeholder="وصف مختصر يظهر أسفل اسم المرحلة في البوابة الإلكترونية"></textarea>
                                </div>
                            </div>
                        </div>

                        <!-- تبويب الخدمات المتاحة -->
                        <div class="tab-pane fade" id="add-services-panel" role="tabpanel"
                            aria-labelledby="add-services-tab">
                            <div class="mb-3">
                                <label class="form-label fw-bold"><i class="fas fa-user-graduate me-1 text-danger"></i>الخدمات
                                    المتاحة للطلاب</label>
                                <div class="card border-danger-subtle shadow-sm">
                                    <div class="card-body p-3">
                                        <div class="row g-2">
                                            <?php foreach ($available_services as $service_key => $service_name): ?>
                                                <div class="col-md-6">
                                                    <div
                                                        class="p-2 border rounded bg-white d-flex align-items-center justify-content-between h-100 shadow-sm-hover">
                                                        <div class="form-check mb-0 flex-grow-1">
                                                            <input class="form-check-input" type="checkbox" name="services[]"
                                                                value="<?php echo $service_key; ?>"
                                                                id="add_service_<?php echo $service_key; ?>">
                                                            <label class="form-check-label fw-bold text-dark fs-7"
                                                                for="add_service_<?php echo $service_key; ?>">
                                                                <?php echo $service_name; ?>
                                                            </label>
                                                        </div>
                                                        <div class="form-check form-switch mb-0 ps-0">
                                                            <input class="form-check-input" type="checkbox" role="switch"
                                                                name="new_badges[]" value="<?php echo $service_key; ?>"
                                                                id="add_badge_<?php echo $service_key; ?>">
                                                            <label class="form-check-label text-danger fw-bold fs-8 me-1"
                                                                for="add_badge_<?php echo $service_key; ?>">
                                                                جديد
                                                            </label>
                                                        </div>
                                                    </div>
                                                </div>
                                            <?php endforeach; ?>
                                        </div>
                                    </div>
                                </div>
                            </div>

                            <div class="mb-0">
                                <label class="form-label fw-bold"><i
                                        class="fas fa-chalkboard-teacher me-1 text-primary"></i>الخدمات المتاحة للمعلمين</label>
                                <div class="card border-primary-subtle shadow-sm">
                                    <div class="card-body p-3">
                                        <div class="row g-2">
                                            <?php foreach ($available_teacher_services as $service_key => $service_name): ?>
                                                <div class="col-md-6">
                                                    <div
                                                        class="p-2 border rounded bg-white d-flex align-items-center justify-content-between h-100 shadow-sm-hover">
                                                        <div class="form-check mb-0 flex-grow-1">
                                                            <input class="form-check-input" type="checkbox"
                                                                name="teacher_services[]" value="<?php echo $service_key; ?>"
                                                                id="add_teacher_service_<?php echo $service_key; ?>" checked>
                                                            <label class="form-check-label fw-bold text-dark fs-7"
                                                                for="add_teacher_service_<?php echo $service_key; ?>">
                                                                <?php echo $service_name; ?>
                                                            </label>
                                                        </div>
                                                        <div class="form-check form-switch mb-0 ps-0">
                                                            <input class="form-check-input" type="checkbox" role="switch"
                                                                name="teacher_new_badges[]" value="<?php echo $service_key; ?>"
                                                                id="add_teacher_badge_<?php echo $service_key; ?>">
                                                            <label class="form-check-label text-danger fw-bold fs-8 me-1"
                                                                for="add_teacher_badge_<?php echo $service_key; ?>">
                                                                جديد
                                                            </label>
                                                        </div>
                                                    </div>
                                                </div>
                                            <?php endforeach; ?>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">
                        <i class="fas fa-times me-1"></i>إلغاء
                    </button>
                    <button type="submit" name="add_stage" class="btn btn-success">
                        <i class="fas fa-save me-1"></i>حفظ
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- Manage Grades Modal -->
<div class="modal fade" id="manageGradesModal" tabindex="-1">
    <div class="modal-dialog modal-lg">
        <div class="modal-content admin-modal admin-modal-premium admin-modal-view">
            <div class="modal-header">
                <h5 class="modal-title">
                    <i class="fas fa-graduation-cap me-2"></i>إدارة صفوف <span id="modal_stage_name"></span>
                </h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <input type="hidden" id="current_stage_id">
                <div id="manage_grades_error" class="alert alert-danger d-none" role="alert"></div>

                <!-- الصفوف المرتبطة بالمرحلة -->
                <div class="mb-4">
                    <h6 class="border-bottom pb-2 mb-3">
                        <i class="fas fa-check-circle text-success me-2"></i>الصفوف المرتبطة بهذه المرحلة
                    </h6>
                    <div id="assigned_grades"></div>
                </div>

                <!-- الصفوف غير المرتبطة -->
                <div>
                    <h6 class="border-bottom pb-2 mb-3">
                        <i class="fas fa-plus-circle text-primary me-2"></i>الصفوف المتاحة للإضافة
                    </h6>
                    <div id="available_grades"></div>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">إغلاق</button>
            </div>
        </div>
    </div>
</div>

<!-- Edit Stage Modal -->
<div class="modal fade" id="editStageModal" tabindex="-1">
    <div class="modal-dialog modal-lg">
        <div class="modal-content admin-modal admin-modal-premium admin-modal-edit">
            <div class="modal-header">
                <h5 class="modal-title"><i class="fas fa-edit me-2"></i>تعديل مرحلة دراسية</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
<form method="POST" id="editStageForm" data-confirm-submit="false">
    <?php echo csrfField(); ?>
                <input type="hidden" name="id" id="edit_id">
                <div class="modal-body">
                    <!-- التبويبات لتنظيم النموذج وتقليص حجمه -->
                    <ul class="nav nav-tabs admin-tabs mb-3" id="editStageTabs" role="tablist">
                        <li class="nav-item" role="presentation">
                            <button class="nav-link active" id="edit-basic-tab" data-bs-toggle="tab"
                                data-bs-target="#edit-basic-panel" type="button" role="tab"
                                aria-controls="edit-basic-panel" aria-selected="true">
                                <i class="fas fa-info-circle me-1"></i>البيانات الأساسية
                            </button>
                        </li>
                        <li class="nav-item" role="presentation">
                            <button class="nav-link" id="edit-services-tab" data-bs-toggle="tab"
                                data-bs-target="#edit-services-panel" type="button" role="tab"
                                aria-controls="edit-services-panel" aria-selected="false">
                                <i class="fas fa-concierge-bell me-1"></i>الخدمات المتاحة
                            </button>
                        </li>
                    </ul>

                    <div class="tab-content" id="editStageTabsContent">
                        <!-- تبويب البيانات الأساسية -->
                        <div class="tab-pane fade show active" id="edit-basic-panel" role="tabpanel"
                            aria-labelledby="edit-basic-tab">
                            <!-- قسم البيانات الأساسية -->
                            <div class="card border-0 bg-light p-3 rounded-3 mb-3">
                                <h6 class="fw-bold text-primary mb-3 border-bottom pb-2"><i
                                        class="fas fa-info-circle me-2"></i>البيانات الأساسية للمرحلة</h6>
                                <div class="row g-3 mb-2">
                                    <div class="col-md-6">
                                        <label class="form-label fw-bold"><i
                                                class="fas fa-layer-group text-secondary me-1"></i>اسم المرحلة (عربي) <span
                                                class="text-danger">*</span></label>
                                        <input type="text" class="form-control bg-white" name="stage_name" id="edit_stage_name"
                                            required>
                                    </div>
                                    <div class="col-md-6">
                                        <label class="form-label fw-bold"><i class="fas fa-globe text-secondary me-1"></i>اسم
                                            المرحلة (English) <span class="text-danger">*</span></label>
                                        <input type="text" class="form-control bg-white" name="stage_name_en"
                                            id="edit_stage_name_en" required>
                                    </div>
                                </div>
                                <div class="row g-3">
                                    <div class="col-md-6">
                                        <label class="form-label fw-bold"><i class="fas fa-code text-secondary me-1"></i>كود
                                            المرحلة <span class="text-danger">*</span></label>
                                        <input type="text" class="form-control bg-white" name="stage_code" id="edit_stage_code"
                                            required>
                                    </div>
                                    <div class="col-md-6">
                                        <label class="form-label fw-bold"><i
                                                class="fas fa-sort-numeric-down text-secondary me-1"></i>ترتيب المرحلة <span
                                                class="text-danger">*</span></label>
                                        <input type="number" class="form-control bg-white" name="stage_order"
                                            id="edit_stage_order" required min="1">
                                    </div>
                                </div>
                                <div class="alert alert-warning mt-3 mb-0">
                                    <div class="form-check form-switch mb-1">
                                        <input class="form-check-input" type="checkbox" role="switch"
                                            name="is_experimental" value="1" id="edit_stage_is_experimental">
                                        <label class="form-check-label fw-bold" for="edit_stage_is_experimental">
                                            <i class="fas fa-flask me-1"></i>مرحلة تجريبية
                                        </label>
                                    </div>
                                    <small>تغيير التصنيف يُمنع إذا كان سيحوّل بيانات طلاب رسمية أو تجريبية بأثر رجعي.</small>
                                </div>
                            </div>

                            <!-- قسم إعدادات البوابة الرئيسية -->
                            <div class="card border-0 bg-light p-3 rounded-3 mb-0">
                                <div class="d-flex align-items-center justify-content-between mb-2">
                                    <label class="form-check-label fw-bold text-dark mb-0" for="edit_portal_visible">
                                        <i class="fas fa-window-restore text-primary me-2"></i>عرض بطاقة هذه المرحلة في البوابة
                                        الرئيسية
                                    </label>
                                    <div class="form-check form-switch mb-0 ps-0">
                                        <input class="form-check-input" type="checkbox" role="switch" name="portal_visible"
                                            id="edit_portal_visible" value="1"
                                            style="width: 2.5em; height: 1.25em; cursor: pointer;">
                                    </div>
                                </div>
                                <div>
                                    <label class="form-label text-secondary fs-7 fw-bold" for="edit_portal_description"><i
                                            class="fas fa-align-left me-1"></i>وصف بطاقة المرحلة في البوابة الرئيسية</label>
                                    <textarea class="form-control bg-white" name="portal_description"
                                        id="edit_portal_description" rows="2" maxlength="255"></textarea>
                                </div>
                            </div>
                        </div>

                        <!-- تبويب الخدمات المتاحة -->
                        <div class="tab-pane fade" id="edit-services-panel" role="tabpanel"
                            aria-labelledby="edit-services-tab">
                            <div class="mb-3">
                                <label class="form-label fw-bold"><i class="fas fa-user-graduate me-1 text-danger"></i>الخدمات
                                    المتاحة للطلاب</label>
                                <div class="card border-danger-subtle shadow-sm">
                                    <div class="card-body p-3">
                                        <div class="row g-2">
                                            <?php foreach ($available_services as $service_key => $service_name): ?>
                                                <div class="col-md-6">
                                                    <div
                                                        class="p-2 border rounded bg-white d-flex align-items-center justify-content-between h-100">
                                                        <div class="form-check mb-0 flex-grow-1">
                                                            <input class="form-check-input edit-service-checkbox" type="checkbox"
                                                                name="services[]" value="<?php echo $service_key; ?>"
                                                                id="edit_service_<?php echo $service_key; ?>">
                                                            <label class="form-check-label fw-bold text-dark fs-7"
                                                                for="edit_service_<?php echo $service_key; ?>">
                                                                <?php echo $service_name; ?>
                                                            </label>
                                                        </div>
                                                        <div class="form-check form-switch mb-0 ps-0">
                                                            <input class="form-check-input edit-badge-checkbox" type="checkbox"
                                                                role="switch" name="new_badges[]"
                                                                value="<?php echo $service_key; ?>"
                                                                id="edit_badge_<?php echo $service_key; ?>">
                                                            <label class="form-check-label text-danger fw-bold fs-8 me-1"
                                                                for="edit_badge_<?php echo $service_key; ?>">
                                                                جديد
                                                            </label>
                                                        </div>
                                                    </div>
                                                </div>
                                            <?php endforeach; ?>
                                        </div>
                                    </div>
                                </div>
                            </div>

                            <div class="mb-0">
                                <label class="form-label fw-bold"><i
                                        class="fas fa-chalkboard-teacher me-1 text-primary"></i>الخدمات المتاحة للمعلمين</label>
                                <div class="card border-primary-subtle shadow-sm">
                                    <div class="card-body p-3">
                                        <div class="row g-2">
                                            <?php foreach ($available_teacher_services as $service_key => $service_name): ?>
                                                <div class="col-md-6">
                                                    <div
                                                        class="p-2 border rounded bg-white d-flex align-items-center justify-content-between h-100">
                                                        <div class="form-check mb-0 flex-grow-1">
                                                            <input class="form-check-input edit-teacher-service-checkbox"
                                                                type="checkbox" name="teacher_services[]"
                                                                value="<?php echo $service_key; ?>"
                                                                id="edit_teacher_service_<?php echo $service_key; ?>">
                                                            <label class="form-check-label fw-bold text-dark fs-7"
                                                                for="edit_teacher_service_<?php echo $service_key; ?>">
                                                                <?php echo $service_name; ?>
                                                            </label>
                                                        </div>
                                                        <div class="form-check form-switch mb-0 ps-0">
                                                            <input class="form-check-input edit-teacher-badge-checkbox"
                                                                type="checkbox" role="switch" name="teacher_new_badges[]"
                                                                value="<?php echo $service_key; ?>"
                                                                id="edit_teacher_badge_<?php echo $service_key; ?>">
                                                            <label class="form-check-label text-danger fw-bold fs-8 me-1"
                                                                for="edit_teacher_badge_<?php echo $service_key; ?>">
                                                                جديد
                                                            </label>
                                                        </div>
                                                    </div>
                                                </div>
                                            <?php endforeach; ?>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">
                        <i class="fas fa-times me-1"></i>إلغاء
                    </button>
                    <button type="submit" name="edit_stage" class="btn btn-success">
                        <i class="fas fa-save me-1"></i>حفظ
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- Delete Stage Modal -->
<div class="modal fade" id="deleteStageModal" tabindex="-1" aria-labelledby="deleteStageModalLabel" aria-hidden="true">
    <div class="modal-dialog">
        <div class="modal-content admin-modal admin-modal-premium admin-modal-delete">
            <form method="post" action="stages.php">
                <?php echo csrfField(); ?>
                <input type="hidden" name="id" id="delete_stage_id">
                <input type="hidden" name="action" value="delete_stage">

                <div class="modal-header">
                    <h5 class="modal-title" id="deleteStageModalLabel">
                        <i class="fas fa-trash-alt me-2"></i>حذف مرحلة دراسية
                    </h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body">
                    <div class="text-center mb-3">
                        <i class="fas fa-exclamation-triangle text-warning" style="font-size: 3rem;"></i>
                    </div>
                    <p class="text-center">هل أنت متأكد من حذف المرحلة <span class="fw-bold text-primary"
                            id="delete_stage_name"></span>؟</p>
                    <div class="alert alert-warning">
                        <i class="fas fa-info-circle me-2"></i>
                        سيتم حذف المرحلة وجميع بياناتها المرتبطة.
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

<!-- Toggle Stage Status Modal -->
<div class="modal fade" id="toggleStageStatusModal" tabindex="-1" aria-labelledby="toggleStageStatusModalLabel"
    aria-hidden="true">
    <div class="modal-dialog">
        <div class="modal-content admin-modal admin-modal-premium admin-modal-warning" id="toggleStageStatusModalContent">
            <div class="modal-header">
                <h5 class="modal-title" id="toggleStageStatusModalLabel"><i class="fas fa-power-off"></i>تغيير حالة المرحلة</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body">
                <div class="text-center mb-3">
                    <i id="toggle_status_icon" class="fas fa-school text-primary" style="font-size: 3rem;"></i>
                </div>
                <p class="text-center">
                    هل أنت متأكد من <span id="status_action_text" class="fw-bold"></span> المرحلة
                    <span class="fw-bold text-primary" id="toggle_stage_name"></span>؟
                </p>
                <div class="alert alert-info">
                    <i class="fas fa-info-circle me-2"></i>
                    <span id="stage_status_description"></span>
                </div>
            </div>
            <div class="modal-footer">
                <form method="post" action="stages.php" class="admin-modal-actions">
                    <?php echo csrfField(); ?>
                    <input type="hidden" name="id" id="toggle_stage_id">
                    <input type="hidden" name="new_status" id="toggle_new_status">
                    <input type="hidden" name="action" value="toggle_stage_status">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">إلغاء</button>
                    <button type="submit" class="btn" id="toggle_status_btn">تأكيد</button>
                </form>
            </div>
        </div>
    </div>
</div>

<div class="modal fade" id="importStagesModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog">
        <div class="modal-content admin-modal admin-modal-premium admin-modal-create">
            <div class="modal-header">
                <h5 class="modal-title"><i class="fas fa-file-import me-2"></i>استيراد مراحل دراسية</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"
                    aria-label="Close"></button>
            </div>
            <div class="modal-body">

                <form id="importStagesForm" method="post" enctype="multipart/form-data" action="import_stages.php">
                    <input type="hidden" name="csrf_token"
                        value="<?php echo htmlspecialchars($_SESSION['csrf_token']); ?>">
                    <div class="mb-3">
                        <label for="stagesFile" class="form-label">اختر الملف</label>
                        <input type="file" class="form-control" id="stagesFile" name="file" accept=".xlsx,.xls,.csv"
                            required>
                    </div>
                </form>
                <div class="alert alert-info mb-0 mt-3">
                    <div class="d-flex justify-content-between align-items-center mb-3 flex-wrap gap-2">
                        <h6 class="alert-heading fw-bold mb-0"><i class="fas fa-info-circle me-1"></i>تعليمات ملف الاستيراد:</h6>
                        <a href="download_template.php?type=stages" class="btn btn-sm btn-primary">
                            <i class="fas fa-download me-1"></i>تحميل نموذج فارغ
                        </a>
                    </div>
                    <p class="small text-danger mb-2 fw-bold border-bottom pb-2">
                        <i class="fas fa-exclamation-triangle me-1"></i>
                        تنبيه هام: يجب رفع الملف مع إبقاء السطر الأول (عناوين الأعمدة) كما هو دون تعديل أو حذف، وتعبئة البيانات بدءاً من السطر الثاني.
                    </p>
                    <p class="small mb-1">يجب أن يحتوي ملف الـ Excel على الأعمدة التالية بالترتيب أو المسمى:</p>
                    <ul class="small mb-0 ps-3">
                        <li><strong>اسم المرحلة</strong> (حقل مطلوب)</li>
                        <li>الاسم بالإنجليزية</li>
                        <li>الكود</li>
                        <li>الترتيب (رقمي)</li>
                        <li>الحالة (نشط/معطل)</li>
                        <li>خدمات الطلاب</li>
                        <li>خدمات المعلمين</li>
                    </ul>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">إلغاء</button>
                <button type="submit" class="btn btn-success" form="importStagesForm"><i
                        class="fas fa-upload me-1"></i>استيراد</button>
            </div>
        </div>
    </div>
</div>

<div class="modal fade" id="tableSettingsModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog">
        <div class="modal-content admin-modal admin-modal-premium admin-modal-view">
            <div class="modal-header">
                <h5 class="modal-title"><i class="fas fa-cog me-2"></i>إعدادات أعمدة الجدول</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"
                    aria-label="Close"></button>
            </div>
            <div class="modal-body">
                <div class="form-check">
                    <input class="form-check-input col-toggle-checkbox" type="checkbox" id="chk_order"
                        data-column="col-order" checked>
                    <label class="form-check-label" for="chk_order">الترتيب</label>
                </div>
                <div class="form-check">
                    <input class="form-check-input col-toggle-checkbox" type="checkbox" id="chk_name"
                        data-column="col-name" checked>
                    <label class="form-check-label" for="chk_name">اسم المرحلة</label>
                </div>
                <div class="form-check">
                    <input class="form-check-input col-toggle-checkbox" type="checkbox" id="chk_name_en"
                        data-column="col-name-en" checked>
                    <label class="form-check-label" for="chk_name_en">الاسم بالإنجليزية</label>
                </div>
                <div class="form-check">
                    <input class="form-check-input col-toggle-checkbox" type="checkbox" id="chk_student_services"
                        data-column="col-student-services" checked>
                    <label class="form-check-label" for="chk_student_services">خدمات الطلاب</label>
                </div>
                <div class="form-check">
                    <input class="form-check-input col-toggle-checkbox" type="checkbox" id="chk_teacher_services"
                        data-column="col-teacher-services" checked>
                    <label class="form-check-label" for="chk_teacher_services">خدمات المعلمين</label>
                </div>
                <div class="form-check">
                    <input class="form-check-input col-toggle-checkbox" type="checkbox" id="chk_grades"
                        data-column="col-grades" checked>
                    <label class="form-check-label" for="chk_grades">عدد الصفوف</label>
                </div>
                <div class="form-check">
                    <input class="form-check-input col-toggle-checkbox" type="checkbox" id="chk_classes"
                        data-column="col-classes" checked>
                    <label class="form-check-label" for="chk_classes">عدد الفصول</label>
                </div>
                <div class="form-check">
                    <input class="form-check-input col-toggle-checkbox" type="checkbox" id="chk_students"
                        data-column="col-students" checked>
                    <label class="form-check-label" for="chk_students">عدد الطلاب</label>
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
    function editStage(stage) {
        // ملء البيانات في نموذج التعديل
        document.getElementById('edit_id').value = stage.id;
        document.getElementById('edit_stage_name').value = stage.stage_name;
        document.getElementById('edit_stage_name_en').value = stage.stage_name_en;
        document.getElementById('edit_stage_code').value = stage.stage_code;
        document.getElementById('edit_stage_order').value = stage.stage_order;
        document.getElementById('edit_stage_is_experimental').checked = Number(stage.is_experimental) === 1;
        document.getElementById('edit_portal_visible').checked = Number(stage.portal_visible) === 1;
        document.getElementById('edit_portal_description').value = stage.portal_description || '';

        // إلغاء تحديد جميع الخدمات والشارات أولاً
        document.querySelectorAll('.edit-service-checkbox').forEach(checkbox => {
            checkbox.checked = false;
        });
        document.querySelectorAll('.edit-teacher-service-checkbox').forEach(checkbox => {
            checkbox.checked = false;
        });
        document.querySelectorAll('.edit-badge-checkbox').forEach(checkbox => {
            checkbox.checked = false;
        });
        document.querySelectorAll('.edit-teacher-badge-checkbox').forEach(checkbox => {
            checkbox.checked = false;
        });

        // تحديد الخدمات المفعلة للطلاب
        if (stage.services) {
            try {
                const services = JSON.parse(stage.services);
                services.forEach(service => {
                    const checkbox = document.getElementById('edit_service_' + service);
                    if (checkbox) {
                        checkbox.checked = true;
                    }
                });
            } catch (e) {
                console.error('Error parsing services:', e);
            }
        }

        // تحديد الخدمات المفعلة للمعلمين
        if (stage.teacher_services) {
            try {
                const teacherServices = JSON.parse(stage.teacher_services);
                teacherServices.forEach(service => {
                    const checkbox = document.getElementById('edit_teacher_service_' + service);
                    if (checkbox) {
                        checkbox.checked = true;
                    }
                });
            } catch (e) {
                console.error('Error parsing teacher_services:', e);
            }
        }

        // تحديد شارات "جديد" للطلاب
        if (stage.new_badges) {
            try {
                const badges = JSON.parse(stage.new_badges);
                badges.forEach(badge => {
                    const checkbox = document.getElementById('edit_badge_' + badge);
                    if (checkbox) {
                        checkbox.checked = true;
                    }
                });
            } catch (e) {
                console.error('Error parsing new_badges:', e);
            }
        }

        // تحديد شارات "جديد" للمعلمين
        if (stage.teacher_new_badges) {
            try {
                const teacherBadges = JSON.parse(stage.teacher_new_badges);
                teacherBadges.forEach(badge => {
                    const checkbox = document.getElementById('edit_teacher_badge_' + badge);
                    if (checkbox) {
                        checkbox.checked = true;
                    }
                });
            } catch (e) {
                console.error('Error parsing teacher_new_badges:', e);
            }
        }

        // فتح نافذة التعديل
        const editModal = new bootstrap.Modal(document.getElementById('editStageModal'));
        editModal.show();
    }

    function escapeStageGradeHtml(value) {
        return String(value == null ? '' : value)
            .replace(/&/g, '&amp;')
            .replace(/</g, '&lt;')
            .replace(/>/g, '&gt;')
            .replace(/"/g, '&quot;')
            .replace(/'/g, '&#039;');
    }

    function showManageGradesError(message) {
        var errorBox = document.getElementById('manage_grades_error');
        if (!errorBox) {
            console.error(message);
            return;
        }
        errorBox.textContent = message;
        errorBox.classList.remove('d-none');
    }

    function clearManageGradesError() {
        var errorBox = document.getElementById('manage_grades_error');
        if (!errorBox) {
            return;
        }
        errorBox.textContent = '';
        errorBox.classList.add('d-none');
    }

    function stageGradesCsrfToken() {
        var metaToken = document.querySelector('meta[name="csrf-token"]');
        if (metaToken && metaToken.content) {
            return metaToken.content;
        }
        return <?php echo json_encode(csrfToken(), JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT); ?>;
    }

    async function postStageGradeMutation(formData) {
        var response = await fetch('stages.php', {
            method: 'POST',
            credentials: 'same-origin',
            body: formData
        });
        var payload = null;
        try {
            payload = await response.json();
        } catch (error) {
            // A CSRF rejection from a legacy page can be plain text rather than JSON.
        }
        if (!response.ok || !payload || payload.success !== true) {
            throw new Error((payload && payload.message) || 'تعذر تنفيذ العملية. أعد تحميل الصفحة ثم حاول مرة أخرى.');
        }
        return payload;
    }

    // دالة لإدارة الصفوف المرتبطة بالمرحلة
    function manageGrades(stageId, stageName) {
        stageId = Number.parseInt(stageId, 10);
        if (!Number.isInteger(stageId) || stageId <= 0) {
            showManageGradesError('معرف المرحلة غير صالح.');
            return;
        }
        document.getElementById('modal_stage_name').textContent = stageName;
        document.getElementById('current_stage_id').value = stageId;
        clearManageGradesError();

        // جلب الصفوف المرتبطة وغير المرتبطة
        fetch('stages.php?action=get_grades&stage_id=' + encodeURIComponent(stageId), {
            credentials: 'same-origin'
        })
            .then(function (response) {
                if (!response.ok) {
                    throw new Error('تعذر تحميل الصفوف المرتبطة بهذه المرحلة.');
                }
                return response.json();
            })
            .then(function (data) {
                if (!data || !Array.isArray(data.assigned) || !Array.isArray(data.available)) {
                    throw new Error('استجابة الصفوف غير صالحة.');
                }

                // عرض الصفوف المرتبطة
                var assignedHtml = '';
                if (data.assigned.length > 0) {
                    data.assigned.forEach(function (grade) {
                        var gradeId = Number.parseInt(grade.id, 10);
                        if (!Number.isInteger(gradeId) || gradeId <= 0) {
                            return;
                        }
                        assignedHtml += `
                        <div class="alert alert-success d-flex justify-content-between align-items-center">
                            <span><i class="fas fa-graduation-cap me-2"></i>${escapeStageGradeHtml(grade.grade_name)}</span>
                            <button class="btn btn-sm btn-outline-danger" type="button" onclick="unlinkGrade(${gradeId}, ${stageId})">
                                <i class="fas fa-unlink"></i> إلغاء الربط
                            </button>
                        </div>
                    `;
                    });
                } else {
                    assignedHtml = '<div class="alert alert-info">لا توجد صفوف مرتبطة بهذه المرحلة.</div>';
                }
                document.getElementById('assigned_grades').innerHTML = assignedHtml;

                // عرض الصفوف المتاحة
                var availableHtml = '';
                if (data.available.length > 0) {
                    data.available.forEach(function (grade) {
                        var gradeId = Number.parseInt(grade.id, 10);
                        if (!Number.isInteger(gradeId) || gradeId <= 0) {
                            return;
                        }
                        availableHtml += `
                        <div class="alert alert-secondary d-flex justify-content-between align-items-center">
                            <span><i class="fas fa-graduation-cap me-2"></i>${escapeStageGradeHtml(grade.grade_name)}</span>
                            <button class="btn btn-sm btn-outline-primary" type="button" onclick="linkGrade(${gradeId}, ${stageId})">
                                <i class="fas fa-link"></i> ربط
                            </button>
                        </div>
                    `;
                    });
                } else {
                    availableHtml = '<div class="alert alert-info">جميع الصفوف مرتبطة بالفعل.</div>';
                }
                document.getElementById('available_grades').innerHTML = availableHtml;
            })
            .catch(function (error) {
                showManageGradesError(error.message || 'تعذر تحميل الصفوف المرتبطة بهذه المرحلة.');
            });

        // فتح نافذة إدارة الصفوف
        const modalElement = document.getElementById('manageGradesModal');
        const modal = new bootstrap.Modal(modalElement);

        // إزالة الـ backdrop عند إغلاق الـ modal
        modalElement.addEventListener('hidden.bs.modal', function () {
            // إزالة أي backdrop متبقي
            const backdrops = document.querySelectorAll('.modal-backdrop');
            backdrops.forEach(backdrop => backdrop.remove());
            // التأكد من إزالة الـ class من body
            document.body.classList.remove('modal-open');
            document.body.style.overflow = '';
            document.body.style.paddingRight = '';
        }, { once: true });

        modal.show();
    }

    // ربط صف بمرحلة
    async function linkGrade(gradeId, stageId) {
        const formData = new FormData();
        formData.append('action', 'link_grade');
        formData.append('grade_id', gradeId);
        formData.append('stage_id', stageId);
        formData.append('csrf_token', stageGradesCsrfToken());

        try {
            await postStageGradeMutation(formData);
            manageGrades(stageId, document.getElementById('modal_stage_name').textContent);
        } catch (error) {
            showManageGradesError(error.message || 'تعذر ربط الصف بالمرحلة.');
        }
    }

    // إلغاء ربط صف من مرحلة
    async function unlinkGrade(gradeId, stageId) {
        const approved = await window.adminConfirm('هل أنت متأكد من إلغاء ربط هذا الصف من المرحلة؟');
        if (!approved) return;

        const formData = new FormData();
        formData.append('action', 'unlink_grade');
        formData.append('grade_id', gradeId);
        formData.append('csrf_token', stageGradesCsrfToken());

        try {
            await postStageGradeMutation(formData);
            manageGrades(stageId, document.getElementById('modal_stage_name').textContent);
        } catch (error) {
            showManageGradesError(error.message || 'تعذر إلغاء ربط الصف من المرحلة.');
        }
    }

    if (typeof initializeTableColumnSettings === 'function') {
        initializeTableColumnSettings('stagesTable', {
            chk_order: 0,
            chk_name: 1,
            chk_name_en: 2,
            chk_student_services: 3,
            chk_teacher_services: 4,
            chk_grades: 5,
            chk_classes: 6,
            chk_students: 7,
            chk_status: 8
        }, 'stages_table_columns');
    }

    // Handle button events using event delegation (works with DataTables)
    document.addEventListener('DOMContentLoaded', function () {
        // Handle delete stage button (Event Delegation)
        document.body.addEventListener('click', function (e) {
            if (e.target.closest('.delete-stage')) {
                const btn = e.target.closest('.delete-stage');
                const stageId = btn.getAttribute('data-id');
                const stageName = btn.getAttribute('data-name');

                console.log('Delete button clicked. Stage ID:', stageId, 'Name:', stageName);

                document.getElementById('delete_stage_id').value = stageId;
                document.getElementById('delete_stage_name').textContent = stageName;
                const deleteModalElement = document.getElementById('deleteStageModal');
                if (deleteModalElement) {
                    bootstrap.Modal.getOrCreateInstance(deleteModalElement).show();
                }
            }
        });

        // Handle toggle stage status button (Event Delegation)
        document.body.addEventListener('click', function (e) {
            if (e.target.closest('.toggle-stage-status')) {
                const btn = e.target.closest('.toggle-stage-status');
                const stageId = btn.getAttribute('data-id');
                const stageName = btn.getAttribute('data-name');
                const newStatus = btn.getAttribute('data-status');
                const actionText = btn.getAttribute('data-action');

                // Update modal content
                document.getElementById('toggle_stage_id').value = stageId;
                document.getElementById('toggle_stage_name').textContent = stageName;
                document.getElementById('toggle_new_status').value = newStatus;
                document.getElementById('status_action_text').textContent = actionText;

                // Update modal description and button based on action
                const descriptionElement = document.getElementById('stage_status_description');
                const confirmButton = document.getElementById('toggle_status_btn');
                const modalContent = document.getElementById('toggleStageStatusModalContent');
                const iconElement = document.getElementById('toggle_status_icon');

                if (newStatus === 'inactive') {
                    descriptionElement.textContent = 'سيتم تعطيل المرحلة ولن تظهر في القوائم، ولكن ستبقى جميع الصفوف والفصول محفوظة.';
                    confirmButton.className = 'btn btn-warning';
                    confirmButton.textContent = 'تعطيل';
                    document.getElementById('toggleStageStatusModalLabel').textContent = 'تعطيل المرحلة';
                    modalContent.classList.remove('admin-modal-create');
                    modalContent.classList.add('admin-modal-warning');
                    iconElement.className = 'fas fa-ban text-warning admin-modal-icon-lg';
                } else {
                    descriptionElement.textContent = 'سيتم تفعيل المرحلة وستظهر في القوائم ويمكن استخدامها مرة أخرى.';
                    confirmButton.className = 'btn btn-success';
                    confirmButton.textContent = 'تفعيل';
                    document.getElementById('toggleStageStatusModalLabel').textContent = 'تفعيل المرحلة';
                    modalContent.classList.remove('admin-modal-warning');
                    modalContent.classList.add('admin-modal-create');
                    iconElement.className = 'fas fa-check-circle text-success admin-modal-icon-lg';
                }

                const statusModalElement = document.getElementById('toggleStageStatusModal');
                if (statusModalElement) {
                    bootstrap.Modal.getOrCreateInstance(statusModalElement).show();
                }
            }
        });
    });

    // وظيفة تصدير جدول المراحل لملف CSV
    function exportStagesTableToCSV() {
        exportTableToCsv('stagesTable', 'stages_list_' + new Date().toISOString().slice(0, 10) + '.csv');
    }

    // (تمت إزالة applyTableSettings — التطبيق فوري عبر initializeTableColumnSettings)

    function exportStagesToPDF() {
        exportTableToPdf('stagesTable', 'إدارة المراحل الدراسية');
    }
</script>

<?php require_once '../includes/admin_footer.php'; ?>
