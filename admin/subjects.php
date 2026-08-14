<?php
// Set page title
$page_title = "إدارة المواد الدراسية";
$custom_page_title = true;

// Include database and necessary classes
require_once '../config/database.php';
require_once '../classes/utilities.php';
require_once '../classes/ActivityLog.php';
require_once '../classes/UndoManager.php';
require_once '../classes/AcademicYear.php';
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
$db->exec("SET NAMES 'utf8mb4'");
$currentAcademicYearId = AcademicYear::currentId($db);

function subjects_table_exists(PDO $db, string $table): bool
{
    $stmt = $db->prepare('SELECT 1 FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?');
    $stmt->execute([$table]);
    return (bool) $stmt->fetchColumn();
}

$subjectGradeAssignmentsReady = subjects_table_exists($db, 'subject_grade_assignments');
$assessmentSchemesReady = subjects_table_exists($db, 'assessment_schemes');
$teacherSubjectAssignmentsReady = subjects_table_exists($db, 'teacher_subject_assignments');

// Process form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (isset($_POST['add_subject'])) {
        try {
            $sort_order = !empty($_POST['sort_order']) ? (int) $_POST['sort_order'] : 0;
            $is_core = !empty($_POST['is_core']) ? 1 : 0;
            $query = "INSERT INTO subjects (name, code, sort_order, is_core) VALUES (:name, :code, :sort_order, :is_core)";
            $stmt = $db->prepare($query);
            $stmt->bindParam(':name', $_POST['name']);
            $stmt->bindParam(':code', $_POST['code']);
            $stmt->bindParam(':sort_order', $sort_order, PDO::PARAM_INT);
            $stmt->bindParam(':is_core', $is_core, PDO::PARAM_INT);

            if ($stmt->execute()) {
                $_SESSION['success_message'] = "تم إضافة المادة الدراسية بنجاح.";
                $newSubjectId = $db->lastInsertId();
                ActivityLog::logCreate('subject', $newSubjectId, $_POST['name']);
                UndoManager::logInsert('subjects', $newSubjectId, ['name' => $_POST['name'], 'sort_order' => $sort_order, 'is_core' => $is_core], 'إضافة مادة: ' . $_POST['name']);
            } else {
                $_SESSION['error_message'] = "حدث خطأ أثناء إضافة المادة.";
            }
            header("Location: subjects.php");
            exit();
        } catch (Exception $e) {
            $_SESSION['error_message'] = (strpos($e->getMessage(), 'Duplicate') !== false) ? "رمز المادة موجود مسبقاً." : "خطأ: " . $e->getMessage();
            header("Location: subjects.php?action=add");
            exit();
        }
    } elseif (isset($_POST['edit_subject'])) {
        try {
            // جلب البيانات القديمة للتراجع
            $oldSubjectData = UndoManager::fetchRecord('subjects', $_POST['id']);

            $sort_order = !empty($_POST['sort_order']) ? (int) $_POST['sort_order'] : 0;
            $is_core = !empty($_POST['is_core']) ? 1 : 0;
            $query = "UPDATE subjects SET name = :name, code = :code, sort_order = :sort_order, is_core = :is_core WHERE id = :id";
            $stmt = $db->prepare($query);
            $stmt->bindParam(':name', $_POST['name']);
            $stmt->bindParam(':code', $_POST['code']);
            $stmt->bindParam(':sort_order', $sort_order, PDO::PARAM_INT);
            $stmt->bindParam(':is_core', $is_core, PDO::PARAM_INT);
            $stmt->bindParam(':id', $_POST['id']);

            if ($stmt->execute()) {
                $_SESSION['success_message'] = "تم تحديث بيانات المادة بنجاح.";
                ActivityLog::logUpdate('subject', $_POST['id'], $_POST['name']);
                if ($oldSubjectData) {
                    UndoManager::logUpdate('subjects', $_POST['id'], $oldSubjectData, null, 'تعديل مادة: ' . $_POST['name']);
                }
            } else {
                $_SESSION['error_message'] = "حدث خطأ أثناء تحديث بيانات المادة.";
            }
            header("Location: subjects.php");
            exit();
        } catch (Exception $e) {
            $_SESSION['error_message'] = "خطأ: " . $e->getMessage();
            header("Location: subjects.php?action=edit&id=" . (isset($_POST['id']) ? $_POST['id'] : ''));
            exit();
        }
    } elseif (isset($_POST['action']) && $_POST['action'] == 'delete') {
        try {
            // Check if subject has dependent assignments before deleting.
            $checkQuery = "SELECT COUNT(*) FROM teacher_subjects WHERE subject_id = :id";
            $checkStmt = $db->prepare($checkQuery);
            $checkStmt->bindParam(':id', $_POST['id'], PDO::PARAM_INT);
            $checkStmt->execute();
            $assignmentCount = (int) $checkStmt->fetchColumn();
            $detailedTeacherAssignmentCount = 0;
            $gradeLinkCount = 0;
            $schemeCount = 0;
            if ($teacherSubjectAssignmentsReady) {
                $detailedTeacherStmt = $db->prepare("SELECT COUNT(*) FROM teacher_subject_assignments WHERE subject_id = :id");
                $detailedTeacherStmt->bindParam(':id', $_POST['id'], PDO::PARAM_INT);
                $detailedTeacherStmt->execute();
                $detailedTeacherAssignmentCount = (int) $detailedTeacherStmt->fetchColumn();
            }
            if ($subjectGradeAssignmentsReady) {
                $gradeLinkStmt = $db->prepare("SELECT COUNT(*) FROM subject_grade_assignments WHERE subject_id = :id");
                $gradeLinkStmt->bindParam(':id', $_POST['id'], PDO::PARAM_INT);
                $gradeLinkStmt->execute();
                $gradeLinkCount = (int) $gradeLinkStmt->fetchColumn();
            }
            if ($assessmentSchemesReady) {
                $schemeStmt = $db->prepare("SELECT COUNT(*) FROM assessment_schemes WHERE subject_id = :id");
                $schemeStmt->bindParam(':id', $_POST['id'], PDO::PARAM_INT);
                $schemeStmt->execute();
                $schemeCount = (int) $schemeStmt->fetchColumn();
            }

            if ($assignmentCount > 0 || $detailedTeacherAssignmentCount > 0 || $gradeLinkCount > 0 || $schemeCount > 0) {
                $_SESSION['error_message'] = "لا يمكن حذف المادة لوجود ارتباطات نشطة بها. قم بإزالة تعيينات المعلمين وروابط الصفوف وخطط الدرجات أولاً أو عطّل المادة بدلاً من حذفها.";
            } else {
                // جلب البيانات قبل الحذف للتراجع
                $oldSubjectData = UndoManager::fetchRecord('subjects', $_POST['id']);

                $query = "DELETE FROM subjects WHERE id = :id";
                $stmt = $db->prepare($query);
                $stmt->bindParam(':id', $_POST['id'], PDO::PARAM_INT);

                if ($stmt->execute()) {
                    $_SESSION['success_message'] = "تم حذف المادة الدراسية بنجاح.";
                    ActivityLog::logDelete('subject', $_POST['id'], $_POST['id']);
                    if ($oldSubjectData) {
                        UndoManager::logDelete('subjects', $_POST['id'], $oldSubjectData, 'حذف مادة: ' . ($oldSubjectData['name'] ?? ''));
                    }
                } else {
                    $_SESSION['error_message'] = "حدث خطأ أثناء حذف المادة.";
                }
            }
            header("Location: subjects.php");
            exit();
        } catch (Exception $e) {
            $_SESSION['error_message'] = "خطأ: " . $e->getMessage();
            header("Location: subjects.php");
            exit();
        }
    } elseif (isset($_POST['action']) && $_POST['action'] == 'toggle_status') {
        try {
            $new_active = ($_POST['new_status'] == '1') ? 1 : 0;
            $query = "UPDATE subjects SET is_active = :is_active WHERE id = :id";
            $stmt = $db->prepare($query);
            $stmt->bindParam(':is_active', $new_active, PDO::PARAM_INT);
            $stmt->bindParam(':id', $_POST['id']);

            if ($stmt->execute()) {
                $status_text = ($new_active == 1) ? 'تفعيل' : 'تعطيل';
                $_SESSION['success_message'] = "تم $status_text المادة بنجاح.";
                ActivityLog::logStatusChange('subject', $_POST['id'], $_POST['id'], ['status' => $new_active ? 'active' : 'inactive']);
            } else {
                $_SESSION['error_message'] = "حدث خطأ أثناء تغيير حالة المادة.";
            }
            header("Location: subjects.php");
            exit();
        } catch (Exception $e) {
            $_SESSION['error_message'] = "خطأ: " . $e->getMessage();
            header("Location: subjects.php");
            exit();
        }
    } elseif (isset($_POST['assign_teachers'])) {
        try {
            $subject_id = $_POST['subject_id'];

            // Remove all existing assignments for this subject
            $deleteQuery = "DELETE FROM teacher_subjects WHERE subject_id = :subject_id";
            $deleteStmt = $db->prepare($deleteQuery);
            $deleteStmt->bindParam(':subject_id', $subject_id);
            $deleteStmt->execute();

            // Add new assignments
            if (isset($_POST['teachers']) && !empty($_POST['teachers'])) {
                $insertQuery = "INSERT INTO teacher_subjects (teacher_id, subject_id) VALUES (:teacher_id, :subject_id)";
                $insertStmt = $db->prepare($insertQuery);

                foreach ($_POST['teachers'] as $teacher_id) {
                    $insertStmt->bindParam(':teacher_id', $teacher_id);
                    $insertStmt->bindParam(':subject_id', $subject_id);
                    $insertStmt->execute();
                }
            }

            $_SESSION['success_message'] = "تم تحديث تعيين المعلمين للمادة بنجاح.";
            header("Location: subjects.php");
            exit();
        } catch (Exception $e) {
            $_SESSION['error_message'] = "خطأ: " . $e->getMessage();
            header("Location: subjects.php");
            exit();
        }
    }
}

// Get subject by ID for editing
$action = $_GET['action'] ?? '';
$edit_subject = null;
if (isset($_GET['action']) && $_GET['action'] == 'edit' && isset($_GET['id'])) {
    $query = "SELECT * FROM subjects WHERE id = :id";
    $stmt = $db->prepare($query);
    $stmt->bindParam(':id', $_GET['id']);
    $stmt->execute();
    $edit_subject = $stmt->fetch(PDO::FETCH_ASSOC);
}

// Get all subjects count for header
$subjects_count = 0;
try {
    $count_stmt = $db->query("SELECT COUNT(*) FROM subjects");
    $subjects_count = (int) $count_stmt->fetchColumn();
} catch (Exception $e) {
    // fallback
}

// Include header
include_once '../includes/admin_header.php';
?>

<!-- Page Title and Buttons Toolbar -->
<div class="admin-page-heading">
    <h1 class="h2"><i class="fas fa-book me-2 text-primary"></i>إدارة المواد الدراسية <span
            class="badge bg-light text-dark border ms-2"><?php echo $subjects_count; ?></span></h1>
    <div class="admin-top-actions">
        <?php if ($action !== 'add' && $action !== 'edit'): ?>
            <button type="button" class="btn btn-header-premium btn-success shadow-sm" data-bs-toggle="modal" data-bs-target="#addSubjectModal">
                <i class="fas fa-plus-circle me-1"></i>إضافة مادة
            </button>
            <button type="button" class="btn btn-header-premium btn-import-soft" data-bs-toggle="modal"
                data-bs-target="#importSubjectsModal">
                <i class="fas fa-file-import me-1"></i>استيراد Excel
            </button>
            <a href="export_subjects.php" class="btn btn-header-premium btn-export-soft">
                <i class="fas fa-file-export me-1"></i>تصدير Excel
            </a>
        <?php else: ?>
            <a href="subjects.php" class="btn btn-outline-secondary shadow-sm px-3 py-2">
                <i class="fas fa-arrow-right me-1"></i>العودة للقائمة
            </a>
        <?php endif; ?>
    </div>
</div>

<!-- Alerts -->
<?php if (!empty($success_message)): ?>
    <div class="alert alert-success alert-dismissible fade show" role="alert">
        <i class="fas fa-check-circle me-2"></i><?php echo htmlspecialchars($success_message, ENT_QUOTES, 'UTF-8'); ?>
        <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
    </div>
<?php endif; ?>
<?php if (!empty($error_message)): ?>
    <div class="alert alert-danger alert-dismissible fade show" role="alert">
        <i class="fas fa-exclamation-circle me-2"></i><?php echo htmlspecialchars($error_message, ENT_QUOTES, 'UTF-8'); ?>
        <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
    </div>
<?php endif; ?>

<?php
$action = isset($_GET['action']) ? $_GET['action'] : '';
    // Get all subjects with teacher count and current-year assessment links
    $assignmentSelect = $subjectGradeAssignmentsReady && $currentAcademicYearId > 0
        ? ", (SELECT COUNT(*) FROM subject_grade_assignments sga WHERE sga.subject_id = s.id AND sga.academic_year_id = :current_year_id) as year_link_count,
         (SELECT COUNT(DISTINCT COALESCE(sga.stage_id, g.stage_id)) FROM subject_grade_assignments sga LEFT JOIN grades g ON g.id = sga.grade_id WHERE sga.subject_id = s.id AND sga.academic_year_id = :current_year_id_stages AND sga.is_active = 1 AND COALESCE(sga.stage_id, g.stage_id) IS NOT NULL) as linked_stage_count,
         (SELECT COUNT(DISTINCT sga.grade_id) FROM subject_grade_assignments sga WHERE sga.subject_id = s.id AND sga.academic_year_id = :current_year_id_grades AND sga.is_active = 1) as linked_grade_count,
         (SELECT COUNT(DISTINCT sga.class_id) FROM subject_grade_assignments sga WHERE sga.subject_id = s.id AND sga.academic_year_id = :current_year_id_classes AND sga.is_active = 1 AND sga.class_id IS NOT NULL) as linked_class_count"
        : ", 0 as year_link_count, 0 as linked_stage_count, 0 as linked_grade_count, 0 as linked_class_count";
    $schemeSelect = $assessmentSchemesReady && $currentAcademicYearId > 0
        ? ", (SELECT COUNT(*) FROM assessment_schemes sch WHERE sch.subject_id = s.id AND sch.academic_year_id = :current_year_id_schemes) as scheme_count,
         (SELECT COUNT(*) FROM assessment_schemes sch WHERE sch.subject_id = s.id AND sch.academic_year_id = :current_year_id_active_schemes AND sch.status = 'active') as active_scheme_count"
        : ", 0 as scheme_count, 0 as active_scheme_count";
    $teacherAssignmentSelect = $teacherSubjectAssignmentsReady && $currentAcademicYearId > 0
        ? ", (SELECT COUNT(*) FROM teacher_subject_assignments tsa WHERE tsa.subject_id = s.id AND tsa.academic_year_id = :current_year_id_teacher_assignments AND tsa.is_active = 1) as detailed_teacher_assignment_count"
        : ", 0 as detailed_teacher_assignment_count";
    $subjectsQuery = "SELECT s.*, 
                  (SELECT COUNT(*) FROM teacher_subjects ts WHERE ts.subject_id = s.id) as teacher_count,
                  (SELECT GROUP_CONCAT(u.name ORDER BY u.name SEPARATOR '، ') FROM teacher_subjects ts2 JOIN users u ON ts2.teacher_id = u.id WHERE ts2.subject_id = s.id) as teacher_names,
                  (SELECT GROUP_CONCAT(ts3.teacher_id SEPARATOR ',') FROM teacher_subjects ts3 WHERE ts3.subject_id = s.id) as teacher_ids
                  {$assignmentSelect}
                  {$schemeSelect}
                  {$teacherAssignmentSelect}
                  FROM subjects s ORDER BY s.sort_order ASC, s.name ASC";
    $subjectsStmt = $db->prepare($subjectsQuery);
    if ($subjectGradeAssignmentsReady && $currentAcademicYearId > 0) {
        $subjectsStmt->bindValue(':current_year_id', $currentAcademicYearId, PDO::PARAM_INT);
        $subjectsStmt->bindValue(':current_year_id_stages', $currentAcademicYearId, PDO::PARAM_INT);
        $subjectsStmt->bindValue(':current_year_id_grades', $currentAcademicYearId, PDO::PARAM_INT);
        $subjectsStmt->bindValue(':current_year_id_classes', $currentAcademicYearId, PDO::PARAM_INT);
    }
    if ($assessmentSchemesReady && $currentAcademicYearId > 0) {
        $subjectsStmt->bindValue(':current_year_id_schemes', $currentAcademicYearId, PDO::PARAM_INT);
        $subjectsStmt->bindValue(':current_year_id_active_schemes', $currentAcademicYearId, PDO::PARAM_INT);
    }
    if ($teacherSubjectAssignmentsReady && $currentAcademicYearId > 0) {
        $subjectsStmt->bindValue(':current_year_id_teacher_assignments', $currentAcademicYearId, PDO::PARAM_INT);
    }
    $subjectsStmt->execute();
    $subjects = $subjectsStmt->fetchAll(PDO::FETCH_ASSOC);
    $linkedSubjectsCount = 0;
    foreach ($subjects as $subjectRow) {
        if ((int) ($subjectRow['year_link_count'] ?? 0) > 0) {
            $linkedSubjectsCount++;
        }
    }
    ?>


<!-- Filter/Actions Bar -->
<div class="admin-filter-bar">
                    <div class="admin-filter-controls">
                        <!-- Status Filter -->
                        <select class="form-select form-select-sm" id="subjectStatusFilter"
                            style="width: auto; min-width: 140px;">
                            <option value="all">جميع الحالات</option>
                            <option value="active">المواد النشطة</option>
                            <option value="inactive">المواد المعطلة</option>
                        </select>
                    </div>
                    <div class="admin-filter-actions">
                        <!-- Reset Filters Button -->
                        <a href="subjects.php" class="btn btn-light btn-sm" title="إعادة تعيين الفلاتر">
                            <i class="fas fa-rotate-left me-1"></i>إعادة تعيين
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
                    <?php
                    if (count($subjects) > 0):
                        ?>
                        <div class="admin-table-wrap">
                            <table class="table table-hover table-striped admin-data-table datatable" id="subjectsTable">
                                <thead>
                                    <tr>
                                        <th>#</th>
                                        <th class="col-name">اسم المادة</th>
                                        <th class="col-code">الرمز</th>
                                        <th class="col-order">الترتيب</th>
                                        <th class="col-type">النوع</th>
                                        <th class="col-teachers">عدد المعلمين</th>
                                        <th class="col-teacher-names d-none">أسماء المعلمين</th>
                                        <th class="col-year-links">ربط العام الحالي</th>
                                        <th class="col-schemes">خطط الدرجات</th>
                                        <th class="col-status">الحالة</th>
                                        <th>الإجراءات</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php
                                    $counter = 1;
                                    foreach ($subjects as $subject):
                                        $teacherNames = !empty($subject['teacher_names']) ? explode('، ', $subject['teacher_names']) : [];

                                        $isActive = (int) $subject['is_active'];
                                        ?>
                                        <tr>
                                            <td><?php echo $counter++; ?></td>
                                            <td class="col-name">
                                                <strong><?php echo htmlspecialchars($subject['name']); ?></strong>
                                            </td>
                                            <td class="col-code">
                                                <?php if (!empty($subject['code'])): ?>
                                                    <span
                                                        class="badge bg-secondary"><?php echo htmlspecialchars($subject['code']); ?></span>
                                                <?php else: ?>
                                                    <span class="text-muted">-</span>
                                                <?php endif; ?>
                                            </td>
                                            <td class="col-order">
                                                <span class="badge bg-light text-dark border"><?php echo (int) ($subject['sort_order'] ?? 0); ?></span>
                                            </td>
                                            <td class="col-type">
                                                <?php if (!empty($subject['is_core'])): ?>
                                                    <span class="badge bg-warning text-dark"><i
                                                            class="fas fa-star me-1"></i>أساسية</span>
                                                <?php else: ?>
                                                    <span class="badge bg-secondary">اختيارية</span>
                                                <?php endif; ?>
                                            </td>
                                            <td class="col-teachers">
                                                <?php if ($subject['teacher_count'] > 0): ?>
                                                    <span class="badge bg-primary" data-bs-toggle="tooltip"
                                                        title="<?php echo htmlspecialchars(implode('، ', $teacherNames)); ?>">
                                                        <i
                                                            class="fas fa-chalkboard-teacher me-1"></i><?php echo $subject['teacher_count']; ?>
                                                    </span>
                                                <?php else: ?>
                                                    <span class="badge bg-secondary">0</span>
                                                <?php endif; ?>
                                                <?php if ((int) ($subject['detailed_teacher_assignment_count'] ?? 0) > 0): ?>
                                                    <a href="assessment_teacher_assignments.php"
                                                        class="badge bg-success text-decoration-none mt-1 d-inline-block"
                                                        data-bs-toggle="tooltip" title="تعيينات رصد تفصيلية حسب الصف/الفصل">
                                                        <i
                                                            class="fas fa-clipboard-check me-1"></i><?php echo (int) $subject['detailed_teacher_assignment_count']; ?>
                                                        رصد
                                                    </a>
                                                <?php endif; ?>
                                            </td>
                                            <td class="col-teacher-names d-none">
                                                <?php if (!empty($subject['teacher_names'])): ?>
                                                    <small
                                                        class="text-muted"><?php echo htmlspecialchars($subject['teacher_names']); ?></small>
                                                <?php else: ?>
                                                    <span class="text-muted">-</span>
                                                <?php endif; ?>
                                            </td>
                                            <td class="col-year-links">
                                                <?php if ((int) ($subject['year_link_count'] ?? 0) > 0): ?>
                                                    <span class="badge bg-success" data-bs-toggle="tooltip"
                                                        title="<?php echo (int) ($subject['linked_class_count'] ?? 0); ?> فصل محدد">
                                                        <i
                                                            class="fas fa-link me-1"></i><?php echo (int) ($subject['linked_stage_count'] ?? 0); ?>
                                                        مرحلة / <?php echo (int) ($subject['linked_grade_count'] ?? 0); ?> صف
                                                    </span>
                                                <?php else: ?>
                                                    <a href="assessment_subject_assignments.php"
                                                        class="badge bg-warning text-dark text-decoration-none" data-bs-toggle="tooltip"
                                                        title="اربط المادة بمرحلة/صف قبل إنشاء خطط الدرجات">
                                                        غير مرتبطة
                                                    </a>
                                                <?php endif; ?>
                                            </td>
                                            <td class="col-schemes">
                                                <?php if ((int) ($subject['scheme_count'] ?? 0) > 0): ?>
                                                    <a href="assessment_schemes.php" class="badge bg-primary text-decoration-none"
                                                        data-bs-toggle="tooltip"
                                                        title="<?php echo (int) ($subject['active_scheme_count'] ?? 0); ?> خطة نشطة">
                                                        <i
                                                            class="fas fa-sliders-h me-1"></i><?php echo (int) ($subject['scheme_count'] ?? 0); ?>
                                                        خطة
                                                    </a>
                                                <?php elseif ((int) ($subject['year_link_count'] ?? 0) > 0): ?>
                                                    <a href="assessment_schemes.php"
                                                        class="badge bg-warning text-dark text-decoration-none" data-bs-toggle="tooltip"
                                                        title="أنشئ خطة درجات مختلفة لكل صف حسب الحاجة">
                                                        تحتاج خطة درجات
                                                    </a>
                                                <?php else: ?>
                                                    <span class="badge bg-secondary">-</span>
                                                <?php endif; ?>
                                            </td>
                                            <td class="col-status">
                                                <?php if ($isActive): ?>
                                                    <span class="badge bg-success">نشطة</span>
                                                <?php else: ?>
                                                    <span class="badge bg-danger">معطلة</span>
                                                <?php endif; ?>
                                            </td>
                                            <td class="align-middle text-nowrap actions-column admin-table-actions">
                                                <button type="button" class="btn btn-action-pills btn-light me-1 reorder-btn"
                                                    data-id="<?php echo $subject['id']; ?>" data-direction="up"
                                                    data-bs-toggle="tooltip" title="نقل لأعلى">
                                                    <i class="fas fa-arrow-up"></i>
                                                </button>
                                                <button type="button" class="btn btn-action-pills btn-light me-1 reorder-btn"
                                                    data-id="<?php echo $subject['id']; ?>" data-direction="down"
                                                    data-bs-toggle="tooltip" title="نقل لأسفل">
                                                    <i class="fas fa-arrow-down"></i>
                                                </button>
                                                <button type="button" 
                                                    class="btn btn-action-pills btn-edit me-1 edit-subject-btn" 
                                                    data-id="<?php echo $subject['id']; ?>"
                                                    data-name="<?php echo htmlspecialchars($subject['name']); ?>"
                                                    data-code="<?php echo htmlspecialchars($subject['code'] ?? ''); ?>"
                                                    data-sort="<?php echo (int)($subject['sort_order'] ?? 0); ?>"
                                                    data-core="<?php echo !empty($subject['is_core']) ? '1' : '0'; ?>"
                                                    data-bs-toggle="tooltip"
                                                    title="تعديل">
                                                    <i class="fas fa-edit"></i>
                                                </button>
                                                <button type="button" 
                                                    class="btn btn-action-pills btn-activate me-1 assign-teachers-btn"
                                                    data-id="<?php echo $subject['id']; ?>"
                                                    data-name="<?php echo htmlspecialchars($subject['name']); ?>"
                                                    data-teacher-ids="<?php echo htmlspecialchars($subject['teacher_ids'] ?? ''); ?>"
                                                    data-bs-toggle="tooltip"
                                                    title="تعيين معلمين">
                                                    <i class="fas fa-user-plus"></i>
                                                </button>

                                                <?php if ($isActive): ?>
                                                    <button type="button" class="btn btn-action-pills btn-deactivate me-1 toggle-status"
                                                        data-id="<?php echo $subject['id']; ?>"
                                                        data-name="<?php echo htmlspecialchars($subject['name']); ?>"
                                                        data-new-status="0" data-action="تعطيل" data-bs-toggle="tooltip"
                                                        title="تعطيل المادة">
                                                        <i class="fas fa-ban"></i>
                                                    </button>
                                                <?php else: ?>
                                                    <button type="button" class="btn btn-action-pills btn-activate me-1 toggle-status"
                                                        data-id="<?php echo $subject['id']; ?>"
                                                        data-name="<?php echo htmlspecialchars($subject['name']); ?>"
                                                        data-new-status="1" data-action="تفعيل" data-bs-toggle="tooltip"
                                                        title="تفعيل المادة">
                                                        <i class="fas fa-check"></i>
                                                    </button>
                                                <?php endif; ?>

                                                <button type="button" class="btn btn-action-pills btn-delete delete-subject"
                                                    data-id="<?php echo $subject['id']; ?>"
                                                    data-name="<?php echo htmlspecialchars($subject['name']); ?>"
                                                    data-teachers="<?php echo $subject['teacher_count']; ?>"
                                                    data-links="<?php echo (int) ($subject['year_link_count'] ?? 0); ?>"
                                                    data-schemes="<?php echo (int) ($subject['scheme_count'] ?? 0); ?>"
                                                    data-bs-toggle="tooltip" title="حذف المادة">
                                                    <i class="fas fa-trash"></i>
                                                </button>
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div> <!-- admin-table-wrap -->
                    <?php else: ?>
                        <div class="text-center py-5">
                            <i class="fas fa-book-open text-muted" style="font-size: 4rem;"></i>
                            <h4 class="mt-3 text-muted">لا توجد مواد دراسية حتى الآن</h4>
                            <p class="text-muted">ابدأ بإضافة المواد الدراسية للمدرسة</p>
                            <a href="subjects.php?action=add" class="btn btn-primary">
                                <i class="fas fa-plus-circle me-1"></i> إضافة مادة جديدة
                            </a>
                        </div>
                    <?php endif; ?>
                </div> <!-- admin-list-surface -->

            <!-- Delete Subject Modal -->
            <div class="modal fade" id="deleteSubjectModal" tabindex="-1" aria-hidden="true">
                <div class="modal-dialog">
                    <div class="modal-content admin-modal admin-modal-premium admin-modal-delete">
                        <form method="post" action="subjects.php">
                            <?php echo csrfField(); ?>
                            <input type="hidden" id="delete_subject_id" name="id">
                            <input type="hidden" name="action" value="delete">
                            <div class="modal-header">
                                <h5 class="modal-title"><i class="fas fa-trash-alt me-2"></i>حذف مادة</h5>
                                <button type="button" class="btn-close" data-bs-dismiss="modal"
                                    aria-label="Close"></button>
                            </div>
                            <div class="modal-body">
                                <div class="text-center mb-3">
                                    <i class="fas fa-exclamation-triangle text-warning" style="font-size: 3rem;"></i>
                                </div>
                                <p class="text-center">هل أنت متأكد من حذف المادة <span class="fw-bold text-primary"
                                        id="delete_subject_name"></span>؟</p>
                                <div class="alert alert-warning" id="delete_warning">
                                    <i class="fas fa-info-circle me-2"></i>
                                    <span id="delete_warning_text">سيتم حذف المادة نهائياً.</span>
                                </div>
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

            <!-- Import Subjects Modal -->
            <div class="modal fade" id="importSubjectsModal" tabindex="-1" aria-hidden="true">
                <div class="modal-dialog">
                    <div class="modal-content admin-modal admin-modal-premium admin-modal-create">
                        <div class="modal-header">
                            <h5 class="modal-title"><i class="fas fa-file-import me-2"></i>استيراد مواد من Excel</h5>
                            <button type="button" class="btn-close" data-bs-dismiss="modal"
                                aria-label="Close"></button>
                        </div>
                        <div class="modal-body">

                            <form id="importSubjectsForm" method="post" enctype="multipart/form-data"
                                action="import_subjects.php">
                                <?php echo csrfField(); ?>
                                <div class="mb-3">
                                    <label for="subjectsFile" class="form-label">اختر ملف Excel</label>
                                    <input type="file" class="form-control" id="subjectsFile" name="file"
                                        accept=".xlsx,.xls,.csv" required>
                                </div>
                            </form>
                            <div class="alert alert-info mb-0 mt-3">
                                <div class="d-flex justify-content-between align-items-center mb-3 flex-wrap gap-2">
                                    <h6 class="alert-heading fw-bold mb-0"><i class="fas fa-info-circle me-1"></i>تعليمات ملف الاستيراد:</h6>
                                    <a href="download_template.php?type=subjects" class="btn btn-sm btn-primary">
                                        <i class="fas fa-download me-1"></i>تحميل نموذج فارغ
                                    </a>
                                </div>
                                <p class="small text-danger mb-2 fw-bold border-bottom pb-2">
                                    <i class="fas fa-exclamation-triangle me-1"></i>
                                    تنبيه هام: يجب رفع الملف مع إبقاء السطر الأول (عناوين الأعمدة) كما هو دون تعديل أو حذف، وتعبئة البيانات بدءاً من السطر الثاني.
                                </p>
                                <p class="small mb-1">يجب أن يحتوي ملف الـ Excel على الأعمدة التالية بالترتيب أو المسمى:</p>
                                <ul class="small mb-0 ps-3">
                                    <li><strong>اسم المادة</strong> (حقل مطلوب)</li>
                                    <li><strong>الكود</strong> (حقل مطلوب فريد)</li>
                                    <li>الترتيب (رقمي)</li>
                                    <li>مادة أساسية (نعم/لا)</li>
                                </ul>
                            </div>
                        </div>
                        <div class="modal-footer">
                            <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">إلغاء</button>
                            <button type="submit" class="btn btn-success" form="importSubjectsForm">
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
                                <label class="form-check-label" for="chk_name">اسم المادة</label>
                            </div>
                            <div class="form-check">
                                <input class="form-check-input col-toggle-checkbox" type="checkbox" id="chk_code"
                                    data-column="col-code" checked>
                                <label class="form-check-label" for="chk_code">الرمز</label>
                            </div>
                            <div class="form-check">
                                <input class="form-check-input col-toggle-checkbox" type="checkbox" id="chk_order"
                                    data-column="col-order" checked>
                                <label class="form-check-label" for="chk_order">الترتيب</label>
                            </div>
                            <div class="form-check">
                                <input class="form-check-input col-toggle-checkbox" type="checkbox" id="chk_type"
                                    data-column="col-type" checked>
                                <label class="form-check-label" for="chk_type">نوع المادة</label>
                            </div>
                            <div class="form-check">
                                <input class="form-check-input col-toggle-checkbox" type="checkbox" id="chk_teachers"
                                    data-column="col-teachers" checked>
                                <label class="form-check-label" for="chk_teachers">عدد المعلمين</label>
                            </div>
                            <div class="form-check">
                                <input class="form-check-input col-toggle-checkbox" type="checkbox" id="chk_teacher_names"
                                    data-column="col-teacher-names">
                                <label class="form-check-label" for="chk_teacher_names">أسماء المعلمين</label>
                            </div>
                            <div class="form-check">
                                <input class="form-check-input col-toggle-checkbox" type="checkbox" id="chk_year_links"
                                    data-column="col-year-links" checked>
                                <label class="form-check-label" for="chk_year_links">ربط العام الحالي</label>
                            </div>
                            <div class="form-check">
                                <input class="form-check-input col-toggle-checkbox" type="checkbox" id="chk_schemes"
                                    data-column="col-schemes" checked>
                                <label class="form-check-label" for="chk_schemes">خطط الدرجات</label>
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
            <div class="modal fade" id="toggleStatusModal" tabindex="-1" aria-hidden="true">
                <div class="modal-dialog">
                    <div class="modal-content admin-modal admin-modal-premium admin-modal-warning" id="toggleStatusModalContent">
                        <div class="modal-header" id="toggleStatusHeader">
                            <h5 class="modal-title" id="toggleStatusTitle"><i class="fas fa-power-off"></i>تغيير حالة المادة</h5>
                            <button type="button" class="btn-close" data-bs-dismiss="modal"
                                aria-label="Close"></button>
                        </div>
                        <div class="modal-body">
                            <div class="text-center mb-3">
                                <i id="toggle-status-icon" class="fas fa-book text-primary" style="font-size: 3rem;"></i>
                            </div>
                            <p class="text-center">هل أنت متأكد من <span id="status_action_text"></span> المادة <span
                                    class="fw-bold text-primary" id="status_subject_name"></span>؟</p>
                        </div>
                        <div class="modal-footer">
                            <form method="post" action="subjects.php" class="admin-modal-actions">
                                <?php echo csrfField(); ?>
                                <input type="hidden" id="status_subject_id" name="id">
                                <input type="hidden" id="status_value" name="new_status">
                                <input type="hidden" name="action" value="toggle_status">
                                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">
                                    <i class="fas fa-times me-1"></i>إلغاء
                                </button>
                                <button type="submit" class="btn" id="status_confirm_btn">
                                    <i class="fas fa-check me-1"></i>تأكيد
                                </button>
                            </form>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Assign Teachers Modal -->
            <div class="modal fade" id="assignTeachersModal" tabindex="-1" aria-hidden="true">
                <div class="modal-dialog">
                    <div class="modal-content admin-modal admin-modal-premium admin-modal-create">
                        <div class="modal-header">
                            <h5 class="modal-title"><i class="fas fa-user-plus me-2"></i>تعيين معلمين للمادة: <span id="assign_subject_title_name" class="fw-bold text-primary"></span></h5>
                            <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                        </div>
                        <form method="POST" action="subjects.php">
                            <?php echo csrfField(); ?>
                            <input type="hidden" id="assign_subject_id" name="subject_id">
                            <input type="hidden" name="assign_teachers" value="1">
                            
                            <div class="modal-body">
                                <div class="mb-3">
                                    <label class="form-label fw-bold">
                                        <i class="fas fa-chalkboard-teacher me-2 text-primary"></i>اختر المعلمين
                                    </label>
                                    <div class="mb-2">
                                        <div class="form-check form-check-inline">
                                            <input class="form-check-input" type="checkbox" id="modalSelectAllTeachers">
                                            <label class="form-check-label fw-bold" for="modalSelectAllTeachers">تحديد/إلغاء الكل</label>
                                        </div>
                                        <input type="text" class="form-control form-control-sm mt-2" id="modalTeacherSearch"
                                            placeholder="🔍 بحث عن معلم...">
                                    </div>
                                    <div class="border rounded p-3" style="max-height: 300px; overflow-y: auto;" id="modalTeachersList">
                                        <?php
                                        $teachersQuery = "SELECT u.id, u.name, u.username FROM users u WHERE u.status = 'active'
                                            AND EXISTS (SELECT 1 FROM user_role_assignments ura WHERE ura.user_id = u.id AND ura.role_key = 'teacher' AND ura.status = 'active') ORDER BY u.name";
                                        $teachersStmt = $db->prepare($teachersQuery);
                                        $teachersStmt->execute();
                                        $allTeachers = $teachersStmt->fetchAll(PDO::FETCH_ASSOC);

                                        if (count($allTeachers) > 0):
                                            foreach ($allTeachers as $teacher):
                                                ?>
                                                <div class="form-check mb-2 modal-teacher-item">
                                                    <input class="form-check-input modal-teacher-checkbox" type="checkbox" name="teachers[]"
                                                        value="<?php echo $teacher['id']; ?>" id="modal_teacher_<?php echo $teacher['id']; ?>">
                                                    <label class="form-check-label" for="modal_teacher_<?php echo $teacher['id']; ?>">
                                                        <i class="fas fa-user text-primary me-1"></i>
                                                        <?php echo htmlspecialchars($teacher['name']); ?>
                                                        <small class="text-muted">(<?php echo htmlspecialchars($teacher['username']); ?>)</small>
                                                    </label>
                                                </div>
                                            <?php
                                            endforeach;
                                        else:
                                            ?>
                                            <div class="alert alert-info mb-0">
                                                <i class="fas fa-info-circle me-2"></i>لا يوجد معلمين نشطين حالياً.
                                            </div>
                                        <?php endif; ?>
                                    </div>
                                    <small class="text-muted mt-1 d-block">
                                        <i class="fas fa-info-circle me-1"></i>
                                        عدد المعلمين المحددين: <span id="modalSelectedCount">0</span>
                                    </small>
                                </div>
                            </div>
                            <div class="modal-footer">
                                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">
                                    <i class="fas fa-times me-1"></i>إلغاء
                                </button>
                                <button type="submit" class="btn btn-success">
                                    <i class="fas fa-save me-1"></i>حفظ التعيينات
                                </button>
                            </div>
                        </form>
                    </div>
                </div>
            </div>

            <!-- Add Subject Modal -->
            <div class="modal fade" id="addSubjectModal" tabindex="-1" aria-hidden="true">
                <div class="modal-dialog">
                    <div class="modal-content admin-modal admin-modal-premium admin-modal-create">
                        <div class="modal-header">
                            <h5 class="modal-title"><i class="fas fa-plus-circle me-2"></i>إضافة مادة جديدة</h5>
                            <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                        </div>
                        <form method="POST" action="subjects.php">
                            <?php echo csrfField(); ?>
                            <input type="hidden" name="add_subject" value="1">
                            <div class="modal-body">
                                <div class="mb-3">
                                    <label for="add_name" class="form-label">اسم المادة <span class="text-danger">*</span></label>
                                    <input type="text" class="form-control" id="add_name" name="name" placeholder="مثال: اللغة العربية" required>
                                </div>
                                <div class="mb-3">
                                    <label for="add_code" class="form-label">رمز المادة</label>
                                    <input type="text" class="form-control" id="add_code" name="code" placeholder="مثال: arabic">
                                    <small class="text-muted">رمز مختصر للمادة (اختياري)</small>
                                </div>
                                <div class="mb-3">
                                    <label for="add_sort_order" class="form-label">رقم الترتيب</label>
                                    <input type="number" class="form-control" id="add_sort_order" name="sort_order" placeholder="0" min="0" step="1">
                                    <small class="text-muted">رقم لتحديد ترتيب المواد (الأقل يأتي أولاً)</small>
                                </div>
                                <div class="mb-3">
                                    <div class="form-check mt-3">
                                        <input type="checkbox" class="form-check-input" id="add_is_core" name="is_core" value="1">
                                        <label class="form-check-label" for="add_is_core">
                                            <i class="fas fa-star me-1 text-warning"></i>مادة أساسية
                                        </label>
                                        <small class="text-muted d-block mt-1">تحديد ما إذا كانت المادة أساسية أم اختيارية</small>
                                    </div>
                                </div>
                            </div>
                            <div class="modal-footer">
                                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal"><i class="fas fa-times me-1"></i>إلغاء</button>
                                <button type="submit" name="add_subject" class="btn btn-success"><i class="fas fa-save me-1"></i>حفظ</button>
                            </div>
                        </form>
                    </div>
                </div>
            </div>

            <!-- Edit Subject Modal -->
            <div class="modal fade" id="editSubjectModal" tabindex="-1" aria-hidden="true">
                <div class="modal-dialog">
                    <div class="modal-content admin-modal admin-modal-premium admin-modal-edit">
                        <div class="modal-header">
                            <h5 class="modal-title"><i class="fas fa-edit me-2"></i>تعديل بيانات المادة</h5>
                            <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                        </div>
                        <form method="POST" action="subjects.php">
                            <?php echo csrfField(); ?>
                            <input type="hidden" name="edit_subject" value="1">
                            <input type="hidden" id="edit_id" name="id">
                            <div class="modal-body">
                                <div class="mb-3">
                                    <label for="edit_name" class="form-label">اسم المادة <span class="text-danger">*</span></label>
                                    <input type="text" class="form-control" id="edit_name" name="name" required>
                                </div>
                                <div class="mb-3">
                                    <label for="edit_code" class="form-label">رمز المادة</label>
                                    <input type="text" class="form-control" id="edit_code" name="code">
                                    <small class="text-muted">رمز مختصر للمادة (اختياري)</small>
                                </div>
                                <div class="mb-3">
                                    <label for="edit_sort_order" class="form-label">رقم الترتيب</label>
                                    <input type="number" class="form-control" id="edit_sort_order" name="sort_order" min="0" step="1">
                                    <small class="text-muted">رقم لتحديد ترتيب المواد (الأقل يأتي أولاً)</small>
                                </div>
                                <div class="mb-3">
                                    <div class="form-check mt-3">
                                        <input type="checkbox" class="form-check-input" id="edit_is_core" name="is_core" value="1">
                                        <label class="form-check-label" for="edit_is_core">
                                            <i class="fas fa-star me-1 text-warning"></i>مادة أساسية
                                        </label>
                                        <small class="text-muted d-block mt-1">تحديد ما إذا كانت المادة أساسية أم اختيارية</small>
                                    </div>
                                </div>
                            </div>
                            <div class="modal-footer">
                                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal"><i class="fas fa-times me-1"></i>إلغاء</button>
                                <button type="submit" name="edit_subject" class="btn btn-primary"><i class="fas fa-save me-1"></i>حفظ</button>
                            </div>
                        </form>
                    </div>
                </div>
            </div>



        <script src="../assets/js/admin_table_actions.js"></script>
        <script>
            // ========== EDIT SUBJECT MODAL POPULATION ==========
            document.addEventListener('DOMContentLoaded', function () {
                document.querySelectorAll('.edit-subject-btn').forEach(function (btn) {
                    btn.addEventListener('click', function () {
                        const id = this.getAttribute('data-id');
                        const name = this.getAttribute('data-name');
                        const code = this.getAttribute('data-code');
                        const sort = this.getAttribute('data-sort');
                        const core = this.getAttribute('data-core');

                        document.getElementById('edit_id').value = id;
                        document.getElementById('edit_name').value = name;
                        document.getElementById('edit_code').value = code;
                        document.getElementById('edit_sort_order').value = sort;
                        document.getElementById('edit_is_core').checked = (core === '1');

                        const modal = new bootstrap.Modal(document.getElementById('editSubjectModal'));
                        modal.show();
                    });
                });
            });

            // ===== إعدادات أعمدة الجدول (تطبيق مباشر عبر class) =====
            function applyColumnVisibility(colClass, isVisible) {
                document.querySelectorAll('.' + colClass).forEach(function (el) {
                    if (isVisible) { el.classList.remove('d-none'); }
                    else { el.classList.add('d-none'); }
                });
            }
            document.addEventListener('DOMContentLoaded', function () {
                var checkboxes = document.querySelectorAll('#tableSettingsModal .col-toggle-checkbox');
                var storageKey = 'subjects_table_columns';
                var prefs = {};
                try { prefs = JSON.parse(localStorage.getItem(storageKey) || '{}'); } catch (e) { prefs = {}; }

                checkboxes.forEach(function (cb) {
                    var colClass = cb.getAttribute('data-column');
                    if (!colClass) return;
                    var isVisible = prefs.hasOwnProperty(colClass) ? prefs[colClass] : cb.checked;
                    cb.checked = isVisible;
                    applyColumnVisibility(colClass, isVisible);
                    cb.addEventListener('change', function () {
                        applyColumnVisibility(colClass, this.checked);
                        prefs[colClass] = this.checked;
                        localStorage.setItem(storageKey, JSON.stringify(prefs));
                    });
                });
            });

            document.addEventListener('DOMContentLoaded', function () {
                // ========== REORDER SUBJECTS ==========
                document.querySelectorAll('.reorder-btn').forEach(function (button) {
                    button.addEventListener('click', function () {
                        const id = this.getAttribute('data-id');
                        const direction = this.getAttribute('data-direction');
                        const btn = this;
                        btn.disabled = true;

                        const formData = new FormData();
                        formData.append('type', 'subject');
                        formData.append('id', id);
                        formData.append('direction', direction);
                        formData.append('csrf_token', '<?php echo htmlspecialchars($_SESSION['csrf_token'], ENT_QUOTES, 'UTF-8'); ?>');

                        fetch('../api/reorder.php', {
                            method: 'POST',
                            body: formData
                        })
                            .then(response => response.json())
                            .then(data => {
                                if (data.success) {
                                    location.reload();
                                } else {
                                    btn.disabled = false;
                                    const originalTitle = btn.getAttribute('data-bs-original-title') || '';
                                    btn.setAttribute('data-bs-original-title', data.message);
                                    const tooltip = bootstrap.Tooltip.getInstance(btn);
                                    if (tooltip) { tooltip.show(); setTimeout(() => { tooltip.hide(); btn.setAttribute('data-bs-original-title', originalTitle); }, 1500); }
                                }
                            })
                            .catch(error => {
                                btn.disabled = false;
                                console.error('Reorder error:', error);
                            });
                    });
                });

                // ========== DELETE SUBJECT ==========
                document.querySelectorAll('.delete-subject').forEach(function (button) {
                    button.addEventListener('click', function () {
                        const id = this.getAttribute('data-id');
                        const name = this.getAttribute('data-name');
                        const teachers = parseInt(this.getAttribute('data-teachers') || '0', 10);
                        const links = parseInt(this.getAttribute('data-links') || '0', 10);
                        const schemes = parseInt(this.getAttribute('data-schemes') || '0', 10);

                        document.getElementById('delete_subject_id').value = id;
                        document.getElementById('delete_subject_name').textContent = name;

                        if (teachers > 0 || links > 0 || schemes > 0) {
                            const reasons = [];
                            if (teachers > 0) reasons.push(teachers + ' معلم');
                            if (links > 0) reasons.push(links + ' ربط صف/مرحلة');
                            if (schemes > 0) reasons.push(schemes + ' خطة درجات');
                            document.getElementById('delete_warning_text').textContent =
                                'لا يمكن حذف هذه المادة لوجود ارتباطات: ' + reasons.join('، ') + '. قم بإزالتها أولاً أو عطّل المادة بدلاً من حذفها.';
                        } else {
                            document.getElementById('delete_warning_text').textContent = 'سيتم حذف المادة نهائياً. هذا الإجراء لا يمكن التراجع عنه.';
                        }

                        const modal = new bootstrap.Modal(document.getElementById('deleteSubjectModal'));
                        modal.show();
                    });
                });

                // ========== TOGGLE STATUS ==========
                document.querySelectorAll('.toggle-status').forEach(function (button) {
                    button.addEventListener('click', function () {
                        const id = this.getAttribute('data-id');
                        const name = this.getAttribute('data-name');
                        const newStatus = this.getAttribute('data-new-status');
                        const actionText = this.getAttribute('data-action');

                        document.getElementById('status_subject_id').value = id;
                        document.getElementById('status_value').value = newStatus;
                        document.getElementById('status_subject_name').textContent = name;
                        document.getElementById('status_action_text').textContent = actionText;

                        const modalContent = document.getElementById('toggleStatusModalContent');
                        const icon = document.getElementById('toggle-status-icon');
                        const confirmBtn = document.getElementById('status_confirm_btn');

                        if (newStatus === '0') {
                            modalContent.classList.remove('admin-modal-create');
                            modalContent.classList.add('admin-modal-warning');
                            icon.className = 'fas fa-ban text-warning admin-modal-icon-lg';
                            confirmBtn.className = 'btn btn-warning';
                            confirmBtn.innerHTML = '<i class="fas fa-ban me-1"></i>تعطيل';
                            document.getElementById('toggleStatusTitle').textContent = 'تعطيل المادة';
                        } else {
                            modalContent.classList.remove('admin-modal-warning');
                            modalContent.classList.add('admin-modal-create');
                            icon.className = 'fas fa-check-circle text-success admin-modal-icon-lg';
                            confirmBtn.className = 'btn btn-success';
                            confirmBtn.innerHTML = '<i class="fas fa-check me-1"></i>تفعيل';
                            document.getElementById('toggleStatusTitle').textContent = 'تفعيل المادة';
                        }

                        const modal = new bootstrap.Modal(document.getElementById('toggleStatusModal'));
                        modal.show();
                    });
                });

                // ========== SELECT ALL TEACHERS ==========
                // ========== ASSIGN TEACHERS MODAL POPULATION ==========
                document.querySelectorAll('.assign-teachers-btn').forEach(function (btn) {
                    btn.addEventListener('click', function () {
                        const id = this.getAttribute('data-id');
                        const name = this.getAttribute('data-name');
                        const teacherIdsStr = this.getAttribute('data-teacher-ids') || '';
                        const assignedIds = teacherIdsStr ? teacherIdsStr.split(',') : [];

                        document.getElementById('assign_subject_id').value = id;
                        document.getElementById('assign_subject_title_name').textContent = name;

                        // Reset and select checkboxes
                        document.querySelectorAll('.modal-teacher-checkbox').forEach(function (cb) {
                            cb.checked = assignedIds.includes(cb.value);
                        });

                        updateModalSelectedCount();

                        const modal = new bootstrap.Modal(document.getElementById('assignTeachersModal'));
                        modal.show();
                    });
                });

                // Select all teachers inside modal
                const modalSelectAll = document.getElementById('modalSelectAllTeachers');
                if (modalSelectAll) {
                    modalSelectAll.addEventListener('change', function () {
                        document.querySelectorAll('.modal-teacher-checkbox').forEach(function (cb) {
                            if (cb.closest('.modal-teacher-item').style.display !== 'none') {
                                cb.checked = modalSelectAll.checked;
                            }
                        });
                        updateModalSelectedCount();
                    });
                }

                // Teacher search inside modal
                const modalSearchInput = document.getElementById('modalTeacherSearch');
                if (modalSearchInput) {
                    modalSearchInput.addEventListener('input', function () {
                        const filter = this.value.toLowerCase();
                        document.querySelectorAll('.modal-teacher-item').forEach(function (item) {
                            const text = item.textContent.toLowerCase();
                            item.style.display = text.includes(filter) ? '' : 'none';
                        });
                    });
                }

                // Update modal selected count
                function updateModalSelectedCount() {
                    const countEl = document.getElementById('modalSelectedCount');
                    if (countEl) {
                        countEl.textContent = document.querySelectorAll('.modal-teacher-checkbox:checked').length;
                    }
                }

                document.querySelectorAll('.modal-teacher-checkbox').forEach(function (cb) {
                    cb.addEventListener('change', updateModalSelectedCount);
                });

                // ========== STATUS FILTER ==========
                if (typeof $ !== 'undefined' && $.fn.DataTable) {
                    const bindStatusFilter = () => {
                        if (!$.fn.dataTable.isDataTable('#subjectsTable')) {
                            setTimeout(bindStatusFilter, 100);
                            return;
                        }

                        const filter = document.getElementById('subjectStatusFilter');
                        if (!filter || filter.dataset.bound === 'true') return;

                        const table = $('#subjectsTable').DataTable();
                        const statusColumnIndex = 9;

                        filter.addEventListener('change', function () {
                            if (this.value === 'active') {
                                table.column(statusColumnIndex).search('نشطة', true, false).draw();
                            } else if (this.value === 'inactive') {
                                table.column(statusColumnIndex).search('معطلة', true, false).draw();
                            } else {
                                table.column(statusColumnIndex).search('', true, false).draw();
                            }
                        });

                        filter.dataset.bound = 'true';
                    };

                    bindStatusFilter();
                }
            });

            // وظيفة تصدير جدول المواد لملف CSV
            function exportSubjectsTableToCSV() {
                exportTableToCsv('subjectsTable', 'subjects_list_' + new Date().toISOString().slice(0, 10) + '.csv');
            }

            function exportSubjectsToPDF() {
                exportTableToPdf('subjectsTable', 'إدارة المواد الدراسية');
            }
        </script>

        <?php
        include_once '../includes/admin_footer.php';
        ?>
