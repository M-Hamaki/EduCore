<?php
// Set page title
$page_title = "إدارة الفصول";
$custom_page_title = true; // This page has its own custom title

// Include database and necessary classes
require_once '../config/database.php';
require_once '../classes/classroom.php';
require_once '../classes/utilities.php';
require_once '../classes/ActivityLog.php';
require_once '../classes/UndoManager.php';
require_once '../src/Modules/Operations/Audit/AuditService.php';
require_once '../src/Modules/AcademicStructure/ExperimentalAcademicScopePolicy.php';
require_once '../includes/session_config.php';
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

// لا تُصلح بيانات الترتيب ضمن طلب قراءة؛ القيم غير المهيأة تُرتّب بأمان في الاستعلام أدناه.

// Initialize class object
$class = new ClassRoom($db);
$auditService = new \EduCore\Modules\Operations\Audit\AuditService($db);

// العام الدراسي الحالي (مطلوب قبل معالجة POST لربط الفصول الجديدة)
require_once __DIR__ . '/../classes/AcademicYear.php';
$currentAcademicYearId = AcademicYear::currentId($db);
$hasStudentEnrollments = false;
try {
    $tableCheck = $db->prepare("SELECT 1 FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'student_enrollments' LIMIT 1");
    $tableCheck->execute();
    $hasStudentEnrollments = (bool) $tableCheck->fetchColumn();
} catch (Throwable $e) {
    $hasStudentEnrollments = false;
}

// Process form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $assignClassPayload = static function (ClassRoom $target, array $payload) use ($experimentalScopePolicy): void {
        $name = trim((string) ($payload['name'] ?? ''));
        $gradeId = filter_var($payload['grade_id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
        $roomLocation = trim((string) ($payload['room_location'] ?? ''));
        $capacityRaw = trim((string) ($payload['capacity'] ?? ''));
        $displayOrderRaw = trim((string) ($payload['display_order'] ?? '0'));
        if ($name === '' || mb_strlen($name, 'UTF-8') > 100) {
            throw new InvalidArgumentException('اسم الفصل مطلوب ويجب ألا يتجاوز 100 حرف.');
        }
        if ($gradeId === false) {
            throw new InvalidArgumentException('يجب اختيار صف دراسي صالح.');
        }
        if (mb_strlen($roomLocation, 'UTF-8') > 255) {
            throw new InvalidArgumentException('مقر الفصل يجب ألا يتجاوز 255 حرفاً.');
        }
        $capacity = null;
        if ($capacityRaw !== '') {
            $capacity = filter_var($capacityRaw, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1, 'max_range' => 65535]]);
            if ($capacity === false) {
                throw new InvalidArgumentException('سعة الفصل يجب أن تكون رقماً بين 1 و65535.');
            }
        }
        $displayOrder = filter_var($displayOrderRaw, FILTER_VALIDATE_INT, ['options' => ['min_range' => 0]]);
        if ($displayOrder === false) {
            throw new InvalidArgumentException('ترتيب العرض غير صالح.');
        }
        $experimentalScopePolicy->gradeEffectiveExperimental((int) $gradeId);
        $target->name = $name;
        $target->grade_id = (int) $gradeId;
        $target->room_location = $roomLocation !== '' ? $roomLocation : null;
        $target->capacity = $capacity;
        $target->display_order = (int) $displayOrder;
        $target->is_experimental = !empty($payload['is_experimental']) ? 1 : 0;
    };

    try {
        if (isset($_POST['add_class'])) {
            $assignClassPayload($class, $_POST);
            $class->academic_year_id = $currentAcademicYearId > 0 ? $currentAcademicYearId : null;
            if (!$class->create()) {
                if ($class->error_message) {
                    throw new InvalidArgumentException($class->error_message);
                }
                throw new RuntimeException('Class creation failed.');
            }
            $_SESSION['success_message'] = 'تم إضافة الفصل بنجاح.';
        } elseif (isset($_POST['edit_class'])) {
            $classId = filter_var($_POST['id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
            if ($classId === false) {
                throw new InvalidArgumentException('الفصل المحدد غير صالح.');
            }
            $class->id = (int) $classId;
            $assignClassPayload($class, $_POST);
            $experimentalScopePolicy->assertClassTransition((int) $class->id, (int) $class->grade_id, (int) $class->is_experimental === 1);
            if (!$class->update()) {
                if ($class->error_message) {
                    throw new InvalidArgumentException($class->error_message);
                }
                throw new RuntimeException('Class update failed.');
            }
            $_SESSION['success_message'] = 'تم تحديث الفصل بنجاح.';
        } elseif (($_POST['action'] ?? '') === 'delete_class') {
            $classId = filter_var($_POST['id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
            if ($classId === false) {
                throw new InvalidArgumentException('الفصل المحدد غير صالح.');
            }
            if ($hasStudentEnrollments) {
                $studentsStmt = $db->prepare("SELECT
                    (SELECT COUNT(*) FROM student_enrollments WHERE class_id = ?)
                    + (SELECT COUNT(*) FROM users WHERE class_id = ? AND role = 'student')");
                $studentsStmt->execute([(int) $classId, (int) $classId]);
            } else {
                $studentsStmt = $db->prepare("SELECT COUNT(*) FROM users WHERE class_id = ? AND role = 'student'");
                $studentsStmt->execute([(int) $classId]);
            }
            if ((int) $studentsStmt->fetchColumn() > 0) {
                $db->beginTransaction();
                $beforeStmt = $db->prepare('SELECT * FROM classes WHERE id = ? FOR UPDATE');
                $beforeStmt->execute([(int) $classId]);
                $before = $beforeStmt->fetch(PDO::FETCH_ASSOC);
                if (!$before) {
                    throw new InvalidArgumentException('الفصل المحدد غير موجود.');
                }
                if (($before['status'] ?? '') !== 'inactive') {
                    $db->prepare("UPDATE classes SET status = 'inactive' WHERE id = ?")->execute([(int) $classId]);
                    $afterStmt = $db->prepare('SELECT * FROM classes WHERE id = ?');
                    $afterStmt->execute([(int) $classId]);
                    $after = $afterStmt->fetch(PDO::FETCH_ASSOC) ?: [];
                    $auditService->recordUpdate('class', 'classes', (int) $classId, (string) ($before['name'] ?? ''), $before, $after, 'تعطيل فصل مرتبط بطلاب');
                }
                $db->commit();
                $_SESSION['success_message'] = 'تم تعطيل الفصل لأنه مرتبط بسجلات طلاب، ولم يُحذف.';
            } else {
                $class->id = (int) $classId;
                if (!$class->delete()) {
                    throw new RuntimeException('Class deletion failed.');
                }
                $_SESSION['success_message'] = 'تم حذف الفصل بنجاح.';
            }
        } elseif (($_POST['action'] ?? '') === 'toggle_class_status') {
            $classId = filter_var($_POST['id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
            $newStatus = is_string($_POST['new_status'] ?? null) ? $_POST['new_status'] : '';
            if ($classId === false || !in_array($newStatus, ['active', 'inactive'], true)) {
                throw new InvalidArgumentException('بيانات حالة الفصل غير صالحة.');
            }
            $db->beginTransaction();
            $beforeStmt = $db->prepare('SELECT * FROM classes WHERE id = ? FOR UPDATE');
            $beforeStmt->execute([(int) $classId]);
            $before = $beforeStmt->fetch(PDO::FETCH_ASSOC);
            if (!$before) {
                throw new InvalidArgumentException('الفصل المحدد غير موجود.');
            }
            if (($before['status'] ?? '') !== $newStatus) {
                $db->prepare('UPDATE classes SET status = ? WHERE id = ?')->execute([$newStatus, (int) $classId]);
                $afterStmt = $db->prepare('SELECT * FROM classes WHERE id = ?');
                $afterStmt->execute([(int) $classId]);
                $after = $afterStmt->fetch(PDO::FETCH_ASSOC) ?: [];
                $auditService->recordUpdate('class', 'classes', (int) $classId, (string) ($before['name'] ?? ''), $before, $after, 'تغيير حالة فصل');
            }
            $db->commit();
            $_SESSION['success_message'] = $newStatus === 'active' ? 'تم تفعيل الفصل بنجاح.' : 'تم تعطيل الفصل بنجاح.';
        }
    } catch (InvalidArgumentException $e) {
        if ($db->inTransaction()) {
            $db->rollBack();
        }
        $_SESSION['error_message'] = $e->getMessage();
    } catch (Throwable $e) {
        if ($db->inTransaction()) {
            $db->rollBack();
        }
        error_log('Classes write failed: ' . $e->getMessage());
        $_SESSION['error_message'] = 'تعذر تنفيذ العملية على الفصل. يرجى إعادة المحاولة.';
    }
    header('Location: classes.php');
    exit();
}

// GET-based delete removed for security - use the POST modal instead

// Get class by ID for editing
if (isset($_GET['action']) && $_GET['action'] == 'edit' && isset($_GET['id'])) {
    $class->id = $_GET['id'];
    $class->readOne();
}

// Check if grade_id is passed
$selected_grade_id = isset($_GET['grade_id']) ? intval($_GET['grade_id']) : null;

// Get all grades for dropdown with stage info
$grades_query = "SELECT g.id, g.grade_name, s.stage_name,
                        COALESCE(g.is_experimental, 0) AS grade_is_experimental,
                        COALESCE(s.is_experimental, 0) AS stage_is_experimental
                 FROM grades g 
                 LEFT JOIN stages s ON g.stage_id = s.id 
                 ORDER BY g.grade_order";
$grades_stmt = $db->prepare($grades_query);
$grades_stmt->execute();
$all_grades = $grades_stmt->fetchAll(PDO::FETCH_ASSOC);

// دالة لتحديد لون المرحلة
function getStageColor($stageName)
{
    $colors = [
        'kindergarten' => ['bg' => 'bg-info', 'text' => 'text-info'],
        'primary' => ['bg' => 'bg-success', 'text' => 'text-success'],
        'preparatory' => ['bg' => 'bg-primary', 'text' => 'text-primary'],
        'secondary' => ['bg' => 'bg-danger', 'text' => 'text-danger']
    ];

    $stageName = trim(strtolower($stageName));

    // البحث في الاسم عن كلمات مفتاحية
    if (strpos($stageName, 'رياض') !== false || strpos($stageName, 'روضة') !== false || strpos($stageName, 'kg') !== false) {
        return $colors['kindergarten'];
    } elseif (strpos($stageName, 'ابتدائ') !== false || strpos($stageName, 'primary') !== false) {
        return $colors['primary'];
    } elseif (strpos($stageName, 'اعداد') !== false || strpos($stageName, 'إعداد') !== false || strpos($stageName, 'preparatory') !== false) {
        return $colors['preparatory'];
    } elseif (strpos($stageName, 'ثانو') !== false || strpos($stageName, 'secondary') !== false) {
        return $colors['secondary'];
    }

    return ['bg' => 'bg-primary', 'text' => 'text-primary']; // اللون الافتراضي
}

// Get all stages for filter (BEFORE header)
$stages_query = "SELECT DISTINCT s.id, s.stage_name FROM stages s ORDER BY s.stage_order";
$stages_stmt = $db->prepare($stages_query);
$stages_stmt->execute();
$all_stages = $stages_stmt->fetchAll(PDO::FETCH_ASSOC);

// Get all classes with student counts and grade names (مرتبطة بالعام الحالي عند توفر جدول التسجيلات)
if ($hasStudentEnrollments && $currentAcademicYearId > 0) {
    $classes_query = "SELECT c.id, c.name, c.grade_id, c.status, c.is_experimental, c.room_location, c.capacity, c.display_order,
                      g.grade_name, COALESCE(g.is_experimental, 0) AS grade_is_experimental,
                      s.stage_name, COALESCE(s.is_experimental, 0) AS stage_is_experimental,
                      COUNT(DISTINCT u.id) as student_count
                      FROM classes c
                      LEFT JOIN grades g ON c.grade_id = g.id
                      LEFT JOIN stages s ON g.stage_id = s.id
                      LEFT JOIN student_enrollments se
                          ON se.class_id = c.id
                          AND se.academic_year_id = :academic_year_id
                          AND se.enrollment_status = 'enrolled'
                      LEFT JOIN users u ON u.id = se.student_id AND u.role = 'student' AND u.status = 'active' AND u.deleted_at IS NULL";
} else {
    $classes_query = "SELECT c.id, c.name, c.grade_id, c.status, c.is_experimental, c.room_location, c.capacity, c.display_order,
                      g.grade_name, COALESCE(g.is_experimental, 0) AS grade_is_experimental,
                      s.stage_name, COALESCE(s.is_experimental, 0) AS stage_is_experimental,
                      COUNT(DISTINCT u.id) as student_count
                      FROM classes c
                      LEFT JOIN grades g ON c.grade_id = g.id
                      LEFT JOIN stages s ON g.stage_id = s.id
                      LEFT JOIN users u ON u.class_id = c.id AND u.role = 'student' AND u.status = 'active' AND u.deleted_at IS NULL";
}

$whereClauses = [];
if ($currentAcademicYearId > 0) {
    $whereClauses[] = "(c.academic_year_id = :academic_year_id_filter OR c.academic_year_id IS NULL)";
}
if ($selected_grade_id) {
    $whereClauses[] = "c.grade_id = :grade_id";
}
if ($whereClauses) {
    $classes_query .= " WHERE " . implode(' AND ', $whereClauses);
}

    $classes_query .= " GROUP BY c.id
                    ORDER BY CASE WHEN c.display_order IS NULL OR c.display_order = 0 THEN 1 ELSE 0 END,
                             c.display_order, s.stage_order, g.grade_order, c.name";

$classes_stmt = $db->prepare($classes_query);
$hasAcademicYearJoin = $hasStudentEnrollments && $currentAcademicYearId > 0;
if ($hasAcademicYearJoin) {
    $classes_stmt->bindValue(':academic_year_id', (int) $currentAcademicYearId, PDO::PARAM_INT);
}
if ($currentAcademicYearId > 0) {
    $classes_stmt->bindValue(':academic_year_id_filter', (int) $currentAcademicYearId, PDO::PARAM_INT);
}

if ($selected_grade_id) {
    $classes_stmt->bindParam(':grade_id', $selected_grade_id, PDO::PARAM_INT);
}

$classes_stmt->execute();
$classes = $classes_stmt->fetchAll(PDO::FETCH_ASSOC);

// Include header
include_once '../includes/admin_header.php';

?>

<!-- Page Title and Buttons Toolbar -->
<div class="admin-page-heading">
    <h1 class="h2"><i class="fas fa-school me-2 text-primary"></i>إدارة الفصول الدراسية <span
            class="badge bg-light text-dark border ms-2"><?php echo count($classes); ?></span></h1>
    <div class="admin-top-actions">
        <button class="btn btn-header-premium btn-success shadow-sm" data-bs-toggle="modal"
            data-bs-target="#addClassModal">
            <i class="fas fa-plus-circle me-1"></i>إضافة فصل
        </button>
        <button class="btn btn-header-premium btn-import-soft" data-bs-toggle="modal"
            data-bs-target="#importClassesModal">
            <i class="fas fa-file-import me-1"></i>استيراد Excel
        </button>
        <a href="export_classes.php" class="btn btn-header-premium btn-export-soft">
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
                    <select class="form-select form-select-sm" id="stageFilter" style="width: auto; min-width: 140px;">
                        <option value="">جميع المراحل</option>
                        <?php foreach ($all_stages as $stage): ?>
                            <option value="<?php echo htmlspecialchars($stage['stage_name']); ?>">
                                <?php echo htmlspecialchars($stage['stage_name']); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>

                    <select class="form-select form-select-sm" id="gradeFilter" style="width: auto; min-width: 140px;">
                        <option value="">جميع الصفوف</option>
                        <?php foreach ($all_grades as $grade): ?>
                            <option value="<?php echo htmlspecialchars($grade['grade_name']); ?>"
                                data-stage="<?php echo htmlspecialchars($grade['stage_name'] ?? ''); ?>" <?php echo ($selected_grade_id && $selected_grade_id == $grade['id']) ? 'selected' : ''; ?>>
                                <?php echo htmlspecialchars($grade['grade_name']); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="admin-filter-actions">
                    <!-- Reset Filters Button -->
                    <a href="classes.php" class="btn btn-light btn-sm" title="إعادة تعيين الفلاتر">
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
                <?php if ($selected_grade_id):
                    // Get grade name
                    $grade_query = "SELECT grade_name FROM grades WHERE id = ?";
                    $grade_stmt = $db->prepare($grade_query);
                    $grade_stmt->execute([$selected_grade_id]);
                    $grade_info = $grade_stmt->fetch(PDO::FETCH_ASSOC);
                    ?>
                    <div class="alert alert-info alert-dismissible fade show" role="alert">
                        <i class="fas fa-filter me-2"></i>
                        يتم عرض الفصول الخاصة بالصف:
                        <strong><?php echo htmlspecialchars($grade_info['grade_name']); ?></strong>
                        <a href="classes.php" class="btn btn-sm btn-outline-primary ms-3">
                            <i class="fas fa-times me-1"></i>إلغاء الفلترة
                        </a>
                        <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
                    </div>
                <?php endif; ?>

                <!-- Alerts -->
                <?php if (!empty($success_message)): ?>
                    <div class="alert alert-success alert-dismissible fade show" role="alert">
                        <i class="fas fa-check-circle me-2"></i><?php echo htmlspecialchars((string) $success_message, ENT_QUOTES, 'UTF-8'); ?>
                        <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
                    </div>
                <?php endif; ?>
                <?php if (!empty($error_message)): ?>
                    <div class="alert alert-danger alert-dismissible fade show" role="alert">
                        <?php echo htmlspecialchars((string) $error_message, ENT_QUOTES, 'UTF-8'); ?>
                        <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
                    </div>
                <?php endif; ?>

                <?php if (!empty($classes)): ?>
                    <div class="admin-table-wrap">
                        <table class="table table-hover table-striped admin-data-table datatable" id="classesTable">
                            <thead>
                                <tr>
                                    <th width="70">الرقم</th>
                                    <th class="col-name">اسم الفصل</th>
                                    <th class="col-room">مقر الفصل</th>
                                    <th class="col-capacity" width="100">السعة</th>
                                    <th class="col-order" width="80">الترتيب</th>
                                    <th class="col-stage">المرحلة الدراسية</th>
                                    <th class="col-grade">الصف الدراسي</th>
                                    <th class="col-count" width="120">عدد الطلاب</th>
                                    <th class="col-status" width="100">الحالة</th>
                                    <th width="180">الإجراءات</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php
                                $counter = 1;
                                foreach ($classes as $row):
                                    ?>
                                    <tr>
                                        <td><?php echo $counter++; ?></td>
                                        <td class="col-name">
                                            <strong><?php echo htmlspecialchars($row['name']); ?></strong>
                                            <?php if (!empty($row['is_experimental'])): ?>
                                                <span class="badge bg-warning text-dark ms-1">
                                                    <i class="fas fa-flask me-1"></i>فصل تجريبي
                                                </span>
                                            <?php elseif (!empty($row['grade_is_experimental']) || !empty($row['stage_is_experimental'])): ?>
                                                <span class="badge bg-warning text-dark ms-1" data-bs-toggle="tooltip"
                                                    title="موروث من <?php echo !empty($row['grade_is_experimental']) ? 'الصف' : 'المرحلة'; ?> التجريبية">
                                                    <i class="fas fa-flask me-1"></i>تجريبي موروث
                                                </span>
                                            <?php endif; ?>
                                        </td>
                                        <td class="col-room">
                                            <?php if (!empty($row['room_location'])): ?>
                                                <span class="badge bg-info bg-opacity-10 text-info"><i
                                                        class="fas fa-map-marker-alt me-1"></i><?php echo htmlspecialchars($row['room_location']); ?></span>
                                            <?php else: ?>
                                                <span class="text-muted">-</span>
                                            <?php endif; ?>
                                        </td>
                                        <td class="col-capacity">
                                            <?php echo $row['capacity'] !== null
                                                ? '<span class="badge bg-light text-dark border">' . (int) $row['capacity'] . '</span>'
                                                : '<span class="text-muted">غير محددة</span>'; ?>
                                        </td>
                                        <td class="col-order">
                                            <span
                                                class="badge bg-light text-dark border"><?php echo (int) ($row['display_order'] ?? 0); ?></span>
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
                                        <td class="col-grade">
                                            <?php if (!empty($row['grade_name'])): ?>
                                                <span
                                                    class="px-2 py-1 bg-secondary bg-opacity-10 text-dark rounded"><?php echo htmlspecialchars($row['grade_name']); ?></span>
                                            <?php else: ?>
                                                <span class="text-muted">غير محدد</span>
                                            <?php endif; ?>
                                        </td>
                                        <td class="col-count"><span
                                                class="fw-bold text-success"><?php echo $row['student_count']; ?></span></td>
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
                                            <button type="button" class="btn btn-action-pills btn-edit me-1 edit-class"
                                                data-id="<?php echo $row['id']; ?>"
                                                data-name="<?php echo htmlspecialchars($row['name']); ?>"
                                                data-grade="<?php echo $row['grade_id']; ?>"
                                                data-room="<?php echo htmlspecialchars($row['room_location'] ?? ''); ?>"
                                                data-capacity="<?php echo $row['capacity'] !== null ? (int) $row['capacity'] : ''; ?>"
                                                data-order="<?php echo (int) ($row['display_order'] ?? 0); ?>"
                                                data-experimental="<?php echo !empty($row['is_experimental']) ? '1' : '0'; ?>"
                                                data-bs-toggle="tooltip" title="تعديل">
                                                <i class="fas fa-edit"></i>
                                            </button>

                                            <?php if ($row['status'] == 'active'): ?>
                                                <button type="button"
                                                    class="btn btn-action-pills btn-deactivate me-1 toggle-class-status"
                                                    data-id="<?php echo $row['id']; ?>"
                                                    data-name="<?php echo htmlspecialchars($row['name']); ?>" data-status="inactive"
                                                    data-bs-toggle="tooltip" title="تعطيل">
                                                    <i class="fas fa-ban"></i>
                                                </button>
                                            <?php else: ?>
                                                <button type="button"
                                                    class="btn btn-action-pills btn-activate me-1 toggle-class-status"
                                                    data-id="<?php echo $row['id']; ?>"
                                                    data-name="<?php echo htmlspecialchars($row['name']); ?>" data-status="active"
                                                    data-bs-toggle="tooltip" title="تفعيل">
                                                    <i class="fas fa-check"></i>
                                                </button>
                                            <?php endif; ?>

                                            <button type="button" class="btn btn-action-pills btn-delete delete-class"
                                                data-id="<?php echo $row['id']; ?>"
                                                data-name="<?php echo htmlspecialchars($row['name']); ?>"
                                                data-students="<?php echo $row['student_count']; ?>" data-bs-toggle="modal"
                                                data-bs-target="#deleteClassModal" title="حذف">
                                                <i class="fas fa-trash"></i>
                                            </button>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div> <!-- admin-table-wrap -->
                <?php else: ?>
                    <div class="alert alert-info">
                        لا توجد فصول حتى الآن. يمكنك إضافة فصل جديد من خلال النقر على زر "إضافة فصل جديد".
                    </div>
                <?php endif; ?>
            </div> <!-- admin-list-surface -->
        </div> <!-- container-fluid row col-12 wrappers closed in admin_footer.php -->

        <!-- Delete Class Modal -->
        <div class="modal fade" id="deleteClassModal" tabindex="-1" aria-labelledby="deleteClassModalLabel"
            aria-hidden="true">
            <div class="modal-dialog">
                <div class="modal-content admin-modal admin-modal-premium admin-modal-delete">
                    <form method="post" action="classes.php">
                        <?php echo csrfField(); ?>
                        <input type="hidden" name="id" id="delete_class_id">
                        <input type="hidden" name="action" value="delete_class">
                        <div class="modal-header">
                            <h5 class="modal-title" id="deleteClassModalLabel">
                                <i class="fas fa-trash-alt me-2"></i>حذف فصل
                            </h5>
                            <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                        </div>
                        <div class="modal-body">
                            <div class="text-center mb-3">
                                <i class="fas fa-exclamation-triangle text-warning" style="font-size: 3rem;"></i>
                            </div>
                            <p class="text-center">هل أنت متأكد من حذف الفصل <span class="fw-bold text-primary"
                                    id="delete_class_name"></span>؟</p>
                            <div class="alert alert-danger" id="students_warning_class" style="display:none;">
                                <i class="fas fa-exclamation-triangle me-2"></i>
                                هذا الفصل يحتوي على <span id="students_count_class"></span> طالب/طلاب. سيتم
                                <strong>تعطيله</strong> بدلاً من الحذف.
                            </div>
                            <p class="text-danger text-center mb-0" id="no_return_warning_class">
                                <i class="fas fa-exclamation-circle me-1"></i>
                                هذا الإجراء لا يمكن التراجع عنه.
                            </p>
                        </div>
                        <div class="modal-footer">
                            <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">
                                <i class="fas fa-times me-1"></i>إلغاء
                            </button>
                            <button type="submit" class="btn btn-danger" id="confirm_delete_class_btn">
                                <i class="fas fa-trash me-1"></i>حذف
                            </button>
                        </div>
                    </form>
                </div>
            </div>
        </div>

        <!-- Toggle Class Status Modal -->
        <div class="modal fade" id="toggleClassStatusModal" tabindex="-1" aria-labelledby="toggleClassStatusModalLabel"
            aria-hidden="true">
            <div class="modal-dialog">
                <div class="modal-content admin-modal admin-modal-premium admin-modal-warning" id="toggleClassStatusModalContent">
                    <div class="modal-header">
                        <h5 class="modal-title" id="toggleClassStatusModalLabel"><i class="fas fa-power-off"></i>تغيير حالة الفصل</h5>
                        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                    </div>
                    <form method="POST" action="classes.php" class="admin-modal-actions">
                        <?php echo csrfField(); ?>
                        <div class="modal-body">
                            <input type="hidden" name="action" value="toggle_class_status">
                            <input type="hidden" name="id" id="toggle_class_id">
                            <input type="hidden" name="new_status" id="toggle_new_status">

                            <div class="text-center mb-3">
                                <i id="toggle-class-status-icon" class="fas fa-door-open text-primary"
                                    style="font-size: 3rem;"></i>
                            </div>
                            <p class="text-center">
                                هل أنت متأكد من <span id="toggle_action_text"></span> الفصل <span
                                    class="fw-bold text-primary" id="toggle_class_name"></span>؟
                            </p>
                            <div class="alert alert-info">
                                <i class="fas fa-info-circle me-2"></i>
                                <span id="class_status_description"></span>
                            </div>
                        </div>
                        <div class="modal-footer">
                            <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">إلغاء</button>
                            <button type="submit" class="btn" id="toggle_class_confirm_btn">تأكيد</button>
                        </div>
                    </form>
                </div>
            </div>
        </div>

        <script>
            document.addEventListener('DOMContentLoaded', function () {
                // ========== REORDER CLASSES ==========
                document.querySelectorAll('.reorder-btn').forEach(function (btn) {
                    btn.addEventListener('click', function () {
                        var id = this.getAttribute('data-id');
                        var direction = this.getAttribute('data-direction');
                        this.disabled = true;

                        var formData = new FormData();
                        formData.append('type', 'class');
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

                // Handle edit class button
                document.querySelectorAll('.edit-class').forEach(btn => {
                    btn.addEventListener('click', function () {
                        var classId = this.getAttribute('data-id');
                        var className = this.getAttribute('data-name');
                        var gradeId = this.getAttribute('data-grade');
                        var roomLocation = this.getAttribute('data-room');
                        var capacity = this.getAttribute('data-capacity');
                        var displayOrder = this.getAttribute('data-order');
                        var isExperimental = this.getAttribute('data-experimental') === '1';

                        document.getElementById('edit_id').value = classId;
                        document.getElementById('edit_name').value = className;
                        document.getElementById('edit_grade_id').value = gradeId;
                        document.getElementById('edit_room_location').value = roomLocation || '';
                        document.getElementById('edit_capacity').value = capacity || '';
                        document.getElementById('edit_display_order').value = displayOrder || '0';
                        document.getElementById('edit_class_is_experimental').checked = isExperimental;

                        var modal = new bootstrap.Modal(document.getElementById('editClassModal'));
                        modal.show();
                    });
                });

                document.querySelectorAll('.delete-class').forEach(btn => {
                    btn.addEventListener('click', function () {
                        var classId = this.getAttribute('data-id');
                        var className = this.getAttribute('data-name');
                        var studentsCount = parseInt(this.getAttribute('data-students'));

                        document.getElementById('delete_class_id').value = classId;
                        document.getElementById('delete_class_name').textContent = className;

                        // إخفاء جميع التحذيرات أولاً
                        document.getElementById('students_warning_class').style.display = 'none';
                        document.getElementById('no_return_warning_class').style.display = 'block';

                        if (studentsCount > 0) {
                            // إذا كان هناك طلاب، سيتم التعطيل بدلاً من الحذف
                            document.getElementById('students_count_class').textContent = studentsCount;
                            document.getElementById('students_warning_class').style.display = 'block';
                            document.getElementById('confirm_delete_class_btn').disabled = false;
                            document.getElementById('confirm_delete_class_btn').className = 'btn btn-warning';
                            document.getElementById('confirm_delete_class_btn').innerHTML = '<i class="fas fa-ban me-1"></i> تعطيل الفصل';
                            document.getElementById('no_return_warning_class').style.display = 'none';
                        } else {
                            // لا يوجد طلاب، يمكن الحذف
                            document.getElementById('confirm_delete_class_btn').disabled = false;
                            document.getElementById('confirm_delete_class_btn').className = 'btn btn-danger';
                            document.getElementById('confirm_delete_class_btn').innerHTML = '<i class="fas fa-trash me-1"></i> حذف';
                        }
                    });
                });

                // Handle toggle class status
                document.querySelectorAll('.toggle-class-status').forEach(btn => {
                    btn.addEventListener('click', function () {
                        var classId = this.getAttribute('data-id');
                        var className = this.getAttribute('data-name');
                        var newStatus = this.getAttribute('data-status');
                        var actionText = newStatus === 'active' ? 'تفعيل' : 'تعطيل';

                        // Update modal content
                        document.getElementById('toggle_class_id').value = classId;
                        document.getElementById('toggle_new_status').value = newStatus;
                        document.getElementById('toggle_class_name').textContent = className;
                        document.getElementById('toggle_action_text').textContent = actionText;

                        // Update modal description and button based on action
                        const descriptionElement = document.getElementById('class_status_description');
                        const confirmButton = document.getElementById('toggle_class_confirm_btn');
                        const modalContent = document.getElementById('toggleClassStatusModalContent');
                        const iconElement = document.getElementById('toggle-class-status-icon');

                        if (newStatus === 'inactive') {
                            descriptionElement.textContent = 'سيتم تعطيل الفصل ولن يظهر في القوائم، ولكن ستبقى جميع بيانات الطلاب محفوظة.';
                            confirmButton.className = 'btn btn-warning';
                            confirmButton.textContent = 'تعطيل';
                            document.getElementById('toggleClassStatusModalLabel').textContent = 'تعطيل الفصل';
                            modalContent.classList.remove('admin-modal-create');
                            modalContent.classList.add('admin-modal-warning');
                            iconElement.className = 'fas fa-door-closed text-warning admin-modal-icon-lg';
                        } else {
                            descriptionElement.textContent = 'سيتم تفعيل الفصل وسيظهر في القوائم ويمكن استخدامه مرة أخرى.';
                            confirmButton.className = 'btn btn-success';
                            confirmButton.textContent = 'تفعيل';
                            document.getElementById('toggleClassStatusModalLabel').textContent = 'تفعيل الفصل';
                            modalContent.classList.remove('admin-modal-warning');
                            modalContent.classList.add('admin-modal-create');
                            iconElement.className = 'fas fa-door-open text-success admin-modal-icon-lg';
                        }

                        var modal = new bootstrap.Modal(document.getElementById('toggleClassStatusModal'));
                        modal.show();
                    });
                });

                // Filter functionality for classes table
                var stageFilter = document.getElementById('stageFilter');
                var gradeFilter = document.getElementById('gradeFilter');

                // تخزين جميع الخيارات الأصلية للصفوف
                var allGradeOptions = [];
                if (gradeFilter) {
                    var options = gradeFilter.querySelectorAll('option');
                    options.forEach(function (option) {
                        if (option.value !== '') { // استثناء خيار "جميع الصفوف"
                            allGradeOptions.push({
                                value: option.value,
                                text: option.text,
                                stage: option.getAttribute('data-stage'),
                                selected: option.selected
                            });
                        }
                    });
                }

                // تصفية الصفوف بناءً على المرحلة المختارة
                function filterGradesByStage(selectedStage) {
                    if (!gradeFilter) return;

                    console.log('Filtering grades by stage:', selectedStage);
                    console.log('Total grade options:', allGradeOptions.length);

                    var currentValue = gradeFilter.value;

                    // حذف جميع الخيارات ماعدا "جميع الصفوف"
                    while (gradeFilter.options.length > 1) {
                        gradeFilter.remove(1);
                    }

                    // إضافة الصفوف المناسبة فقط
                    var hasMatchingGrades = false;
                    var addedCount = 0;
                    allGradeOptions.forEach(function (gradeData) {
                        var gradeStage = (gradeData.stage || '').trim();
                        var selectedStageTrimmed = (selectedStage || '').trim();

                        console.log('Checking grade:', gradeData.text, 'with stage:', gradeStage, 'against:', selectedStageTrimmed);

                        // إذا لم تكن هناك مرحلة محددة، أو إذا كانت المرحلة تتطابق
                        if (!selectedStageTrimmed || gradeStage === selectedStageTrimmed) {
                            var option = new Option(gradeData.text, gradeData.value);
                            option.setAttribute('data-stage', gradeStage);
                            if (gradeData.value === currentValue) {
                                option.selected = true;
                            }
                            gradeFilter.add(option);
                            hasMatchingGrades = true;
                            addedCount++;
                        }
                    });

                    console.log('Added grades:', addedCount);

                    // إذا لم يكن الصف الحالي متوافقاً مع المرحلة، إعادة تعيين الفلتر
                    if (currentValue && !hasMatchingGrades) {
                        gradeFilter.value = '';
                    }
                }

                // Register custom search filter for DataTables
                if (typeof $ !== 'undefined' && $.fn.dataTable) {
                    $.fn.dataTable.ext.search.push(
                        function (settings, data, dataIndex) {
                            if (settings.nTable.id !== 'classesTable') {
                                return true;
                            }

                            var stageVal = stageFilter ? stageFilter.value.trim() : '';
                            var gradeVal = gradeFilter ? gradeFilter.value.trim() : '';

                            var stageText = (data[5] || '').trim(); // Index 5 is Stage
                            var gradeText = (data[6] || '').trim(); // Index 6 is Grade

                            if (stageVal && stageText !== stageVal) {
                                return false;
                            }
                            if (gradeVal && gradeText !== gradeVal) {
                                return false;
                            }
                            return true;
                        }
                    );
                }

                function filterTable() {
                    if (typeof $ !== 'undefined' && $.fn.dataTable && $.fn.dataTable.isDataTable('#classesTable')) {
                        $('#classesTable').DataTable().draw();
                    } else {
                        // Fallback for manual DOM filtering if DataTables is not initialized
                        var stageValue = stageFilter ? stageFilter.value.toLowerCase().trim() : '';
                        var gradeValue = gradeFilter ? gradeFilter.value.toLowerCase().trim() : '';

                        var table = document.getElementById('classesTable') || document.querySelector('table');
                        if (!table) return;

                        var tbody = table.querySelector('tbody');
                        if (!tbody) return;

                        var rows = tbody.getElementsByTagName('tr');
                        for (var i = 0; i < rows.length; i++) {
                            var cells = rows[i].getElementsByTagName('td');
                            if (cells.length >= 7) {
                                var stageCell = cells[5]; // Stage is index 5
                                var gradeCell = cells[6]; // Grade is index 6

                                var stageText = (stageCell.textContent || stageCell.innerText).toLowerCase().trim();
                                var gradeText = (gradeCell.textContent || gradeCell.innerText).toLowerCase().trim();

                                var stageMatch = stageValue === '' || stageText === stageValue || stageText.indexOf(stageValue) > -1;
                                var gradeMatch = gradeValue === '' || gradeText === gradeValue || gradeText.indexOf(gradeValue) > -1;

                                if (stageMatch && gradeMatch) {
                                    rows[i].style.display = '';
                                } else {
                                    rows[i].style.display = 'none';
                                }
                            }
                        }
                    }
                }

                if (stageFilter) {
                    stageFilter.addEventListener('change', function () {
                        console.log('Stage filter changed:', this.value);
                        // تصفية قائمة الصفوف أولاً
                        filterGradesByStage(this.value);
                        // ثم تصفية الجدول
                        filterTable();
                    });
                }

                if (gradeFilter) {
                    gradeFilter.addEventListener('change', function () {
                        console.log('Grade filter changed:', this.value);
                        filterTable();
                    });

                    // Apply filter on page load if a grade is selected
                    if (gradeFilter.value) {
                        setTimeout(function () {
                            filterTable();
                        }, 100);
                    }
                }
            });
        </script>

        <!-- Add Class Modal -->
        <div class="modal fade" id="addClassModal" tabindex="-1">
            <div class="modal-dialog">
                <div class="modal-content admin-modal admin-modal-premium admin-modal-create">
                    <div class="modal-header">
                        <h5 class="modal-title"><i class="fas fa-plus me-2"></i>إضافة فصل جديد</h5>
                        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                    </div>
                    <form method="POST">
                        <?php echo csrfField(); ?>
                        <div class="modal-body">
                            <div class="mb-3">
                                <label for="grade_id" class="form-label">الصف الدراسي <span
                                        class="text-danger">*</span></label>
                                <select class="form-select" id="grade_id" name="grade_id" required>
                                    <option value="">-- اختر الصف الدراسي --</option>
                                    <?php foreach ($all_grades as $grade): ?>
                                        <option value="<?php echo $grade['id']; ?>">
                                            <?php echo htmlspecialchars($grade['grade_name']); ?><?php echo !empty($grade['grade_is_experimental']) || !empty($grade['stage_is_experimental']) ? ' — تجريبي' : ''; ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                                <small class="text-muted">اختر الصف الدراسي الذي ينتمي إليه هذا الفصل</small>
                            </div>

                            <div class="mb-3">
                                <label for="name" class="form-label">اسم الفصل <span
                                        class="text-danger">*</span></label>
                                <input type="text" class="form-control" id="name" name="name" required
                                    placeholder="مثال: 1/أ، 1/ب">
                                <small class="text-muted">أدخل اسم الفصل</small>
                            </div>
                            <div class="mb-3">
                                <label for="room_location" class="form-label">مقر الفصل</label>
                                <input type="text" class="form-control" id="room_location" name="room_location"
                                    placeholder="مثال: غرفة 101، المبنى الرئيسي">
                                <small class="text-muted">اسم أو رقم الغرفة التي يوجد بها الفصل</small>
                            </div>
                            <div class="mb-3">
                                <label for="capacity" class="form-label">السعة القصوى <small class="text-muted">(اختياري)</small></label>
                                <input type="number" class="form-control" id="capacity" name="capacity" min="1" max="65535"
                                    placeholder="مثال: 40">
                                <small class="text-muted">تُستخدم لاحقًا عند تسكين الطلاب ولا تمنع الترحيل.</small>
                            </div>
                            <div class="mb-3">
                                <label for="display_order" class="form-label">ترتيب الفصل</label>
                                <input type="number" class="form-control" id="display_order" name="display_order"
                                    placeholder="اتركه فارغاً للترتيب التلقائي" min="1">
                                <small class="text-muted">ترتيب ظهور الفصل في القوائم والجداول</small>
                            </div>
                            <div class="alert alert-warning mb-0">
                                <div class="form-check form-switch mb-1">
                                    <input class="form-check-input" type="checkbox" role="switch"
                                        id="add_class_is_experimental" name="is_experimental" value="1">
                                    <label class="form-check-label fw-bold" for="add_class_is_experimental">
                                        <i class="fas fa-flask me-1"></i>فصل تجريبي
                                    </label>
                                </div>
                                <small>إذا كان الصف أو المرحلة تجريبيًا فسيرث الفصل التصنيف تلقائيًا.</small>
                            </div>
                        </div>
                        <div class="modal-footer">
                            <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">
                                <i class="fas fa-times me-1"></i>إلغاء
                            </button>
                            <button type="submit" name="add_class" class="btn btn-success">
                                <i class="fas fa-save me-1"></i>حفظ الفصل
                            </button>
                        </div>
                    </form>
                </div>
            </div>
        </div>

        <!-- Edit Class Modal -->
        <div class="modal fade" id="editClassModal" tabindex="-1">
            <div class="modal-dialog">
                <div class="modal-content admin-modal admin-modal-premium admin-modal-edit">
                    <div class="modal-header">
                        <h5 class="modal-title"><i class="fas fa-edit me-2"></i>تعديل الفصل</h5>
                        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                    </div>
                    <form method="POST" id="editClassForm">
                        <?php echo csrfField(); ?>
                        <input type="hidden" name="id" id="edit_id">
                        <div class="modal-body">
                            <div class="mb-3">
                                <label for="edit_grade_id" class="form-label">الصف الدراسي <span
                                        class="text-danger">*</span></label>
                                <select class="form-select" id="edit_grade_id" name="grade_id" required>
                                    <option value="">-- اختر الصف الدراسي --</option>
                                    <?php foreach ($all_grades as $grade): ?>
                                        <option value="<?php echo $grade['id']; ?>">
                                            <?php echo htmlspecialchars($grade['grade_name']); ?><?php echo !empty($grade['grade_is_experimental']) || !empty($grade['stage_is_experimental']) ? ' — تجريبي' : ''; ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>

                            <div class="mb-3">
                                <label for="edit_name" class="form-label">اسم الفصل <span
                                        class="text-danger">*</span></label>
                                <input type="text" class="form-control" id="edit_name" name="name" required>
                            </div>
                            <div class="mb-3">
                                <label for="edit_room_location" class="form-label">مقر الفصل</label>
                                <input type="text" class="form-control" id="edit_room_location" name="room_location"
                                    placeholder="مثال: غرفة 101، المبنى الرئيسي">
                            </div>
                            <div class="mb-3">
                                <label for="edit_capacity" class="form-label">السعة القصوى <small class="text-muted">(اختياري)</small></label>
                                <input type="number" class="form-control" id="edit_capacity" name="capacity" min="1" max="65535">
                                <small class="text-muted">تُستخدم لاحقًا عند تسكين الطلاب ولا تمنع الترحيل.</small>
                            </div>
                            <div class="mb-3">
                                <label for="edit_display_order" class="form-label">ترتيب الفصل</label>
                                <input type="number" class="form-control" id="edit_display_order" name="display_order"
                                    min="1">
                            </div>
                            <div class="alert alert-warning mb-0">
                                <div class="form-check form-switch mb-1">
                                    <input class="form-check-input" type="checkbox" role="switch"
                                        id="edit_class_is_experimental" name="is_experimental" value="1">
                                    <label class="form-check-label fw-bold" for="edit_class_is_experimental">
                                        <i class="fas fa-flask me-1"></i>فصل تجريبي
                                    </label>
                                </div>
                                <small>التصنيف الموروث من الصف أو المرحلة يظل فعالًا، وتُمنع التحويلات المؤثرة في بيانات الطلاب.</small>
                            </div>
                        </div>
                        <div class="modal-footer">
                            <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">
                                <i class="fas fa-times me-1"></i>إلغاء
                            </button>
                            <button type="submit" name="edit_class" class="btn btn-primary">
                                <i class="fas fa-save me-1"></i>حفظ التعديلات
                            </button>
                        </div>
                    </form>
                </div>
            </div>
        </div>

        <!-- Import Classes Modal -->
        <div class="modal fade" id="importClassesModal" tabindex="-1" aria-hidden="true">
            <div class="modal-dialog">
                <div class="modal-content admin-modal admin-modal-premium admin-modal-create">
                    <div class="modal-header">
                        <h5 class="modal-title"><i class="fas fa-file-import me-2"></i>استيراد فصول من Excel</h5>
                        <button type="button" class="btn-close" data-bs-dismiss="modal"
                            aria-label="Close"></button>
                    </div>
                    <div class="modal-body">

                        <form id="importClassesForm" method="post" enctype="multipart/form-data"
                            action="import_classes.php">
                            <input type="hidden" name="csrf_token"
                                value="<?php echo htmlspecialchars($_SESSION['csrf_token']); ?>">
                            <div class="mb-3">
                                <label for="classesFile" class="form-label">اختر ملف Excel</label>
                                <input type="file" class="form-control" id="classesFile" name="file"
                                    accept=".xlsx,.xls,.csv" required>
                            </div>
                        </form>
                        <div class="alert alert-info mb-0 mt-3">
                            <div class="d-flex justify-content-between align-items-center mb-3 flex-wrap gap-2">
                                <h6 class="alert-heading fw-bold mb-0"><i class="fas fa-info-circle me-1"></i>تعليمات ملف الاستيراد:</h6>
                                <a href="download_template.php?type=classes" class="btn btn-sm btn-primary">
                                    <i class="fas fa-download me-1"></i>تحميل نموذج فارغ
                                </a>
                            </div>
                            <p class="small text-danger mb-2 fw-bold border-bottom pb-2">
                                <i class="fas fa-exclamation-triangle me-1"></i>
                                تنبيه هام: يجب رفع الملف مع إبقاء السطر الأول (عناوين الأعمدة) كما هو دون تعديل أو حذف، وتعبئة البيانات بدءاً من السطر الثاني.
                            </p>
                            <p class="small mb-1">يجب أن يحتوي ملف الـ Excel على الأعمدة التالية بالترتيب أو المسمى:</p>
                            <ul class="small mb-0 ps-3">
                                <li><strong>اسم الفصل</strong> (حقل مطلوب)</li>
                                <li>الصف الدراسي</li>
                                <li>مقر الفصل</li>
                                <li>الترتيب (رقمي)</li>
                                <li>الحالة (نشط/معطل)</li>
                            </ul>
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">إلغاء</button>
                        <button type="submit" class="btn btn-success" form="importClassesForm">
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
                            <label class="form-check-label" for="chk_name">اسم الفصل</label>
                        </div>
                        <div class="form-check">
                            <input class="form-check-input col-toggle-checkbox" type="checkbox" id="chk_room"
                                data-column="col-room" checked>
                            <label class="form-check-label" for="chk_room">مقر الفصل</label>
                        </div>
                        <div class="form-check">
                            <input class="form-check-input col-toggle-checkbox" type="checkbox" id="chk_capacity"
                                data-column="col-capacity" checked>
                            <label class="form-check-label" for="chk_capacity">السعة</label>
                        </div>
                        <div class="form-check">
                            <input class="form-check-input col-toggle-checkbox" type="checkbox" id="chk_order"
                                data-column="col-order" checked>
                            <label class="form-check-label" for="chk_order">الترتيب</label>
                        </div>
                        <div class="form-check">
                            <input class="form-check-input col-toggle-checkbox" type="checkbox" id="chk_grade"
                                data-column="col-grade" checked>
                            <label class="form-check-label" for="chk_grade">الصف</label>
                        </div>
                        <div class="form-check">
                            <input class="form-check-input col-toggle-checkbox" type="checkbox" id="chk_stage"
                                data-column="col-stage" checked>
                            <label class="form-check-label" for="chk_stage">المرحلة</label>
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
            if (typeof initializeTableColumnSettings === 'function') {
                initializeTableColumnSettings('classesTable', {
                    chk_name: 1,
                    chk_room: 2,
                    chk_capacity: 3,
                    chk_order: 4,
                    chk_stage: 5,
                    chk_grade: 6,
                    chk_count: 7,
                    chk_status: 8
                }, 'classes_table_columns');
            }

            function exportClassesToPDF() {
                exportTableToPdf('classesTable', 'إدارة الفصول الدراسية');
            }

            function exportClassesTableToCSV() {
                exportTableToCsv('classesTable', 'classes_list_' + new Date().toISOString().slice(0, 10) + '.csv');
            }
        </script>

        <?php
        // Include footer
        include_once '../includes/admin_footer.php';
        ?>
