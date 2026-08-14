<?php
/**
 * Educational Materials Upload Center
 * EduCore School Management System
 */

$page_title = 'مركز رفع المواد التعليمية';
$custom_page_title = true;

// 1. Core Config & Authentication Validation BEFORE processing any requests
require_once '../config/database.php';
require_once '../classes/utilities.php';
require_once '../classes/ActivityLog.php';
require_once '../classes/UndoManager.php';
require_once '../classes/AcademicYear.php';
require_once '../classes/SchemaReadinessGuard.php';
require_once '../classes/FileUploadGuard.php';

require_once '../includes/session_config.php';
Utilities::validateSession('admin');

// 2. Database Connection & Schema Setup
$database = new Database();
$db = $database->getConnection();
$currentAcademicYearId = AcademicYear::currentId($db);

$db->exec("SET NAMES 'utf8mb4'");
$db->exec("SET CHARACTER SET utf8mb4");

// Tab Persistence Setup
$activeTab = $_GET['tab'] ?? ($_POST['active_tab'] ?? 'all');

(new SchemaReadinessGuard($db))->assertTable('materials');

// Ensure upload directory exists
$uploadDir = dirname(__DIR__) . '/uploads/materials/';
if (!is_dir($uploadDir) && !mkdir($uploadDir, 0755, true) && !is_dir($uploadDir)) {
    throw new RuntimeException('تعذر تجهيز مجلد المواد التعليمية.');
}
$materialMimeMap = ['pdf' => ['application/pdf']];
$supervisorPreviewBaseUrl = APP_URL . '/student/materials/supervisor_preview.php';

// 3. POST Handlers (PRG Pattern)
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $csrfToken = $_POST['csrf_token'] ?? '';
    if (empty($csrfToken) || !isset($_SESSION['csrf_token']) || !hash_equals($_SESSION['csrf_token'], $csrfToken)) {
        $_SESSION['error_message'] = "خطأ في التحقق من رموز الأمان (CSRF Token).";
        header("Location: materials_center.php?tab=" . urlencode($activeTab));
        exit();
    }
    $action = $_POST['action'] ?? '';

    if ($action === 'add') {
        $stage_id = intval($_POST['stage_id'] ?? 0);
        $grade_id = intval($_POST['grade_id'] ?? 0);
        $term = $_POST['term'] ?? '';
        $subject_name = trim($_POST['subject_name'] ?? '');
        $enabled = isset($_POST['enabled']) ? 1 : 0;
        $downloadable = isset($_POST['downloadable']) ? 1 : 0;

        if ($stage_id <= 0 || $grade_id <= 0 || !in_array($term, ['term1', 'term2']) || empty($subject_name)) {
            $_SESSION['error_message'] = "جميع الحقول المطلوبة يجب ملؤها بشكل صحيح.";
            header("Location: materials_center.php?tab=" . urlencode($activeTab));
            exit();
        }

        if (!isset($_FILES['material_file']) || $_FILES['material_file']['error'] !== UPLOAD_ERR_OK) {
            $_SESSION['error_message'] = "يرجى اختيار ملف PDF صالح للرفع.";
            header("Location: materials_center.php?tab=" . urlencode($activeTab));
            exit();
        }

        try {
            $validatedFile = FileUploadGuard::validate($_FILES['material_file'], $materialMimeMap, 50 * 1024 * 1024);
        } catch (InvalidArgumentException $e) {
            $_SESSION['error_message'] = $e->getMessage();
            header("Location: materials_center.php?tab=" . urlencode($activeTab));
            exit();
        }

        $original_name = $validatedFile['original_name'];
        $new_filename = FileUploadGuard::randomFileName('material', 'pdf');
        $target_path = $uploadDir . $new_filename;

        if (move_uploaded_file($validatedFile['tmp_name'], $target_path)) {
            try {
                $stmt = $db->prepare("INSERT INTO materials (stage_id, grade_id, term, subject_name, file_name, original_file_name, file_size, enabled, downloadable, uploaded_by, academic_year_id) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
                $stmt->execute([$stage_id, $grade_id, $term, $subject_name, $new_filename, $original_name, $validatedFile['size'], $enabled, $downloadable, $_SESSION['user_id'] ?? null, $currentAcademicYearId]);
            } catch (Throwable $e) {
                @unlink($target_path);
                error_log('Material create failed after file move: ' . $e->getMessage());
                throw $e;
            }
            $newId = $db->lastInsertId();

            ActivityLog::logCreate('material', $newId, $subject_name);
            $_SESSION['success_message'] = "تم رفع المادة التعليمية بنجاح.";
        } else {
            $_SESSION['error_message'] = "فشل نقل الملف المرفوع إلى مجلد التخزين.";
        }

        header("Location: materials_center.php?tab=" . urlencode($activeTab));
        exit();
    }

    if ($action === 'edit') {
        $id = intval($_POST['id'] ?? 0);
        $stage_id = intval($_POST['stage_id'] ?? 0);
        $grade_id = intval($_POST['grade_id'] ?? 0);
        $term = $_POST['term'] ?? '';
        $subject_name = trim($_POST['subject_name'] ?? '');
        $enabled = isset($_POST['enabled']) ? 1 : 0;
        $downloadable = isset($_POST['downloadable']) ? 1 : 0;

        if ($id <= 0 || $stage_id <= 0 || $grade_id <= 0 || !in_array($term, ['term1', 'term2']) || empty($subject_name)) {
            $_SESSION['error_message'] = "بيانات التعديل غير مكتملة أو غير صالحة.";
            header("Location: materials_center.php?tab=" . urlencode($activeTab));
            exit();
        }

        $stmt = $db->prepare("SELECT * FROM materials WHERE id = ?");
        $stmt->execute([$id]);
        $existing = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$existing) {
            $_SESSION['error_message'] = "المادة المطلوبة غير موجودة.";
            header("Location: materials_center.php?tab=" . urlencode($activeTab));
            exit();
        }

        $new_filename = $existing['file_name'];
        $original_name = $existing['original_file_name'];
        $file_size = $existing['file_size'];

        if (isset($_FILES['material_file']) && $_FILES['material_file']['error'] === UPLOAD_ERR_OK) {
            try {
                $validatedFile = FileUploadGuard::validate($_FILES['material_file'], $materialMimeMap, 50 * 1024 * 1024);
            } catch (InvalidArgumentException $e) {
                $_SESSION['error_message'] = $e->getMessage();
                header("Location: materials_center.php?tab=" . urlencode($activeTab));
                exit();
            }

            $replacement_filename = FileUploadGuard::randomFileName('material', 'pdf');
            if (move_uploaded_file($validatedFile['tmp_name'], $uploadDir . $replacement_filename)) {
                $new_filename = $replacement_filename;
                $original_name = $validatedFile['original_name'];
                $file_size = $validatedFile['size'];
            }
        }

        try {
            $stmt = $db->prepare("UPDATE materials SET stage_id = ?, grade_id = ?, term = ?, subject_name = ?, file_name = ?, original_file_name = ?, file_size = ?, enabled = ?, downloadable = ? WHERE id = ?");
            $stmt->execute([$stage_id, $grade_id, $term, $subject_name, $new_filename, $original_name, $file_size, $enabled, $downloadable, $id]);
        } catch (Throwable $e) {
            if ($new_filename !== $existing['file_name']) {
                @unlink($uploadDir . $new_filename);
            }
            throw $e;
        }
        if ($new_filename !== $existing['file_name'] && !empty($existing['file_name'])) {
            @unlink($uploadDir . basename((string)$existing['file_name']));
        }

        ActivityLog::logUpdate('material', $id, $subject_name);
        $_SESSION['success_message'] = "تم تحديث بيانات المادة التعليمية بنجاح.";
        header("Location: materials_center.php?tab=" . urlencode($activeTab));
        exit();
    }

    if ($action === 'delete') {
        $id = intval($_POST['id'] ?? 0);
        if ($id > 0) {
            $stmt = $db->prepare("SELECT file_name, subject_name FROM materials WHERE id = ?");
            $stmt->execute([$id]);
            $item = $stmt->fetch(PDO::FETCH_ASSOC);

            if ($item) {
                $delStmt = $db->prepare("DELETE FROM materials WHERE id = ?");
                $delStmt->execute([$id]);
                if (!empty($item['file_name'])) {
                    @unlink($uploadDir . basename((string)$item['file_name']));
                }

                ActivityLog::logDelete('material', $id, $item['subject_name']);
                $_SESSION['success_message'] = "تم حذف المادة التعليمية وجميع ملفاتها بنجاح.";
            }
        }
        header("Location: materials_center.php?tab=" . urlencode($activeTab));
        exit();
    }

    if ($action === 'quick_upload') {
        $id = intval($_POST['id'] ?? 0);
        if ($id <= 0) {
            $_SESSION['error_message'] = "معرف المادة غير صالح.";
            header("Location: materials_center.php?tab=" . urlencode($activeTab));
            exit();
        }

        $stmt = $db->prepare("SELECT * FROM materials WHERE id = ?");
        $stmt->execute([$id]);
        $existing = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$existing) {
            $_SESSION['error_message'] = "المادة المطلوبة غير موجودة.";
            header("Location: materials_center.php?tab=" . urlencode($activeTab));
            exit();
        }

        if (!isset($_FILES['material_file']) || $_FILES['material_file']['error'] !== UPLOAD_ERR_OK) {
            $_SESSION['error_message'] = "يرجى اختيار ملف PDF صالح للرفع.";
            header("Location: materials_center.php?tab=" . urlencode($activeTab));
            exit();
        }

        try {
            $validatedFile = FileUploadGuard::validate($_FILES['material_file'], $materialMimeMap, 50 * 1024 * 1024);
        } catch (InvalidArgumentException $e) {
            $_SESSION['error_message'] = $e->getMessage();
            header("Location: materials_center.php?tab=" . urlencode($activeTab));
            exit();
        }

        $original_name = $validatedFile['original_name'];
        $new_filename = FileUploadGuard::randomFileName('material', 'pdf');
        $target_path = $uploadDir . $new_filename;

        if (move_uploaded_file($validatedFile['tmp_name'], $target_path)) {
            try {
                $stmt = $db->prepare("UPDATE materials SET file_name = ?, original_file_name = ?, file_size = ? WHERE id = ?");
                $stmt->execute([$new_filename, $original_name, $validatedFile['size'], $id]);
            } catch (Throwable $e) {
                @unlink($target_path);
                throw $e;
            }
            if (!empty($existing['file_name'])) {
                @unlink($uploadDir . basename((string)$existing['file_name']));
            }

            ActivityLog::logUpdate('material_file_quick', $id, $existing['subject_name']);
            $_SESSION['success_message'] = "تم رفع ملف المادة التعليمية بنجاح.";
        } else {
            $_SESSION['error_message'] = "فشل نقل الملف المرفوع إلى مجلد التخزين.";
        }

        header("Location: materials_center.php?tab=" . urlencode($activeTab));
        exit();
    }

    if ($action === 'toggle') {
        $id = intval($_POST['id'] ?? 0);
        if ($id > 0) {
            $stmt = $db->prepare("SELECT enabled, subject_name FROM materials WHERE id = ?");
            $stmt->execute([$id]);
            $item = $stmt->fetch(PDO::FETCH_ASSOC);

            if ($item) {
                $newStatus = $item['enabled'] ? 0 : 1;
                $upStmt = $db->prepare("UPDATE materials SET enabled = ? WHERE id = ?");
                $upStmt->execute([$newStatus, $id]);

                $statusText = $newStatus ? 'إظهار' : 'إخفاء';
                ActivityLog::logStatusChange('material', $id, $item['subject_name'], ['status' => $statusText]);
                $_SESSION['success_message'] = "تم تغيير حالة عرض المادة بنجاح.";
            }
        }
    }

    if ($action === 'toggle_downloadable') {
        $id = intval($_POST['id'] ?? 0);
        if ($id > 0) {
            $stmt = $db->prepare("SELECT downloadable, subject_name FROM materials WHERE id = ?");
            $stmt->execute([$id]);
            $item = $stmt->fetch(PDO::FETCH_ASSOC);

            if ($item) {
                $newStatus = $item['downloadable'] ? 0 : 1;
                $upStmt = $db->prepare("UPDATE materials SET downloadable = ? WHERE id = ?");
                $upStmt->execute([$newStatus, $id]);

                $statusText = $newStatus ? 'متاح للتحميل' : 'قريباً';
                ActivityLog::logStatusChange('material_downloadable', $id, $item['subject_name'], ['downloadable' => $statusText]);
                $_SESSION['success_message'] = "تم تغيير حالة إتاحة تحميل المادة بنجاح.";
            }
        }
        header("Location: materials_center.php?tab=" . urlencode($activeTab));
        exit();
    }

    // ADVANCED BULK ACTIONS HANDLER
    if ($action === 'bulk_action') {
        $bulk_type = $_POST['bulk_type'] ?? '';
        $b_stage_id = intval($_POST['bulk_stage_id'] ?? 0);
        $b_grade_id = intval($_POST['bulk_grade_id'] ?? 0);
        $b_term = $_POST['bulk_term'] ?? '';
        $selected_ids = $_POST['selected_ids'] ?? [];

        if (!is_array($selected_ids)) {
            $selected_ids = [];
        }
        $selected_ids = array_map('intval', array_filter($selected_ids));

        // Build scope SQL
        $scopeClauses = [];
        $scopeParams = [];

        if (!empty($selected_ids)) {
            $inClause = implode(',', array_fill(0, count($selected_ids), '?'));
            $scopeClauses[] = "id IN ($inClause)";
            $scopeParams = array_merge($scopeParams, $selected_ids);
        } else {
            if ($b_stage_id > 0) {
                $scopeClauses[] = "stage_id = ?";
                $scopeParams[] = $b_stage_id;
            }
            if ($b_grade_id > 0) {
                $scopeClauses[] = "grade_id = ?";
                $scopeParams[] = $b_grade_id;
            }
            if (in_array($b_term, ['term1', 'term2'])) {
                $scopeClauses[] = "term = ?";
                $scopeParams[] = $b_term;
            }
        }

        if (empty($scopeClauses)) {
            $_SESSION['error_message'] = "يرجى تحديد النطاق أو اختيار المواد المراد تطبييق الإجراء الجماعي عليها.";
            header("Location: materials_center.php?tab=" . urlencode($activeTab));
            exit();
        }

        $whereScopeSql = "WHERE " . implode(" AND ", $scopeClauses);

        if ($bulk_type === 'set_coming_soon') {
            $stmt = $db->prepare("UPDATE materials SET downloadable = 0 {$whereScopeSql}");
            $stmt->execute($scopeParams);
            $affected = $stmt->rowCount();
            ActivityLog::logUpdate('materials_bulk', 0, 'تعيين حالة التحميل جماعياً إلى قريباً', ['affected' => $affected]);
            $_SESSION['success_message'] = "تم تغيير حالة التحميل إلى (قريباً) لـ {$affected} مادة بنجاح.";
        } elseif ($bulk_type === 'set_downloadable') {
            $stmt = $db->prepare("UPDATE materials SET downloadable = 1 {$whereScopeSql}");
            $stmt->execute($scopeParams);
            $affected = $stmt->rowCount();
            ActivityLog::logUpdate('materials_bulk', 0, 'تفعيل التحميل جماعياً', ['affected' => $affected]);
            $_SESSION['success_message'] = "تم إتاحة التحميل لـ {$affected} مادة بنجاح.";
        } elseif ($bulk_type === 'enable_all') {
            $stmt = $db->prepare("UPDATE materials SET enabled = 1 {$whereScopeSql}");
            $stmt->execute($scopeParams);
            $affected = $stmt->rowCount();
            ActivityLog::logStatusChange('materials_bulk', 0, 'إظهار المواد للطلاب جماعياً', ['affected' => $affected]);
            $_SESSION['success_message'] = "تم إظهار {$affected} مادة للطلاب بنجاح.";
        } elseif ($bulk_type === 'disable_all') {
            $stmt = $db->prepare("UPDATE materials SET enabled = 0 {$whereScopeSql}");
            $stmt->execute($scopeParams);
            $affected = $stmt->rowCount();
            ActivityLog::logStatusChange('materials_bulk', 0, 'إخفاء المواد جماعياً', ['affected' => $affected]);
            $_SESSION['success_message'] = "تم إخفاء {$affected} مادة عن الطلاب بنجاح.";
        } elseif ($bulk_type === 'clear_files_only') {
            $stmt = $db->prepare("SELECT file_name FROM materials {$whereScopeSql}");
            $stmt->execute($scopeParams);
            $files = $stmt->fetchAll(PDO::FETCH_COLUMN);
            $upStmt = $db->prepare("UPDATE materials SET file_name = '', original_file_name = '', file_size = 0, downloadable = 0 {$whereScopeSql}");
            $upStmt->execute($scopeParams);
            foreach ($files as $fName) {
                if (!empty($fName)) {
                    @unlink($uploadDir . basename((string)$fName));
                }
            }
            $affected = $upStmt->rowCount();
            ActivityLog::logUpdate('materials_bulk', 0, 'تفريغ ملفات المواد جماعياً', ['affected' => $affected]);
            $_SESSION['success_message'] = "تم تفريغ وسحب ملفات الـ PDF لـ {$affected} مادة وتعيينها كـ (قريباً) مع الإبقاء على المواد بنجاح.";
        } elseif ($bulk_type === 'move_to_term1') {
            $stmt = $db->prepare("UPDATE materials SET term = 'term1' {$whereScopeSql}");
            $stmt->execute($scopeParams);
            $affected = $stmt->rowCount();
            ActivityLog::logUpdate('materials_bulk', 0, 'نقل المواد جماعياً للترم الأول', ['affected' => $affected]);
            $_SESSION['success_message'] = "تم نقل {$affected} مادة إلى الفصل الدراسي الأول بنجاح.";
        } elseif ($bulk_type === 'move_to_term2') {
            $stmt = $db->prepare("UPDATE materials SET term = 'term2' {$whereScopeSql}");
            $stmt->execute($scopeParams);
            $affected = $stmt->rowCount();
            ActivityLog::logUpdate('materials_bulk', 0, 'نقل المواد جماعياً للترم الثاني', ['affected' => $affected]);
            $_SESSION['success_message'] = "تم نقل {$affected} مادة إلى الفصل الدراسي الثاني بنجاح.";
        } elseif ($bulk_type === 'delete_bulk') {
            $stmt = $db->prepare("SELECT file_name FROM materials {$whereScopeSql}");
            $stmt->execute($scopeParams);
            $filesToDelete = $stmt->fetchAll(PDO::FETCH_COLUMN);

            $delStmt = $db->prepare("DELETE FROM materials {$whereScopeSql}");
            $delStmt->execute($scopeParams);
            $affected = $delStmt->rowCount();
            foreach ($filesToDelete as $fName) {
                if (!empty($fName)) {
                    @unlink($uploadDir . basename((string)$fName));
                }
            }

            ActivityLog::logDelete('materials_bulk', 0, "حذف جماعي لـ {$affected} مادة");
            $_SESSION['success_message'] = "تم حذف {$affected} مادة بجميع ملفاتها وسجلاتها بنجاح.";
        }

        header("Location: materials_center.php?tab=" . urlencode($activeTab));
        exit();
    }
}

// 4. Retrieve session messages
$success_message = $_SESSION['success_message'] ?? null;
$error_message = $_SESSION['error_message'] ?? null;
unset($_SESSION['success_message'], $_SESSION['error_message']);

// 5. Fetch Data for Page Display
$stages = $db->query("SELECT id, stage_name FROM stages WHERE status = 'active' ORDER BY stage_order, id")->fetchAll(PDO::FETCH_ASSOC) ?: [];
$grades = $db->query("SELECT id, grade_name, stage_id FROM grades WHERE status = 'active' ORDER BY stage_id, grade_order, id")->fetchAll(PDO::FETCH_ASSOC) ?: [];

// Map stage icons
function getStageIcon($stageName) {
    if (mb_strpos($stageName, 'رياض') !== false) return '🧸';
    if (mb_strpos($stageName, 'ابتدائ') !== false) return '🎒';
    if (mb_strpos($stageName, 'إعداد') !== false) return '📚';
    if (mb_strpos($stageName, 'ثانو') !== false) return '🎓';
    return '🏫';
}

// Filter inputs (Supporting Multi-Select Arrays)
$filter_stage_ids = isset($_GET['stage_ids']) && is_array($_GET['stage_ids'])
    ? array_values(array_filter(array_map('intval', $_GET['stage_ids'])))
    : (isset($_GET['stage_id']) && intval($_GET['stage_id']) > 0 ? [intval($_GET['stage_id'])] : []);

$filter_grade_ids = isset($_GET['grade_ids']) && is_array($_GET['grade_ids'])
    ? array_values(array_filter(array_map('intval', $_GET['grade_ids'])))
    : (isset($_GET['grade_id']) && intval($_GET['grade_id']) > 0 ? [intval($_GET['grade_id'])] : []);

$filter_terms = isset($_GET['terms']) && is_array($_GET['terms'])
    ? array_values(array_intersect($_GET['terms'], ['term1', 'term2']))
    : (isset($_GET['term']) && in_array($_GET['term'], ['term1', 'term2']) ? [$_GET['term']] : []);

$filter_statuses = isset($_GET['statuses']) && is_array($_GET['statuses'])
    ? array_values(array_intersect($_GET['statuses'], ['0', '1']))
    : (isset($_GET['status']) && in_array($_GET['status'], ['0', '1']) ? [$_GET['status']] : []);

// Legacy single variables for backward compatibility
$filter_stage = count($filter_stage_ids) === 1 ? $filter_stage_ids[0] : 0;
$filter_grade = count($filter_grade_ids) === 1 ? $filter_grade_ids[0] : 0;
$filter_term = count($filter_terms) === 1 ? $filter_terms[0] : '';
$filter_status = count($filter_statuses) === 1 ? $filter_statuses[0] : '';

// Determine tab filter
$tabStageId = 0;
if (strpos($activeTab, 'stage_') === 0) {
    $tabStageId = intval(str_replace('stage_', '', $activeTab));
}

// Build query (Fetch tab materials for instant real-time client-side DataTables filtering)
$whereClauses = [];
$queryParams = [];

if ($tabStageId > 0) {
    $whereClauses[] = "m.stage_id = ?";
    $queryParams[] = $tabStageId;
}

$whereSql = !empty($whereClauses) ? 'WHERE ' . implode(' AND ', $whereClauses) : '';

$materialsQuery = "SELECT m.*, s.stage_name, g.grade_name
                   FROM materials m
                   LEFT JOIN stages s ON m.stage_id = s.id
                   LEFT JOIN grades g ON m.grade_id = g.id
                   {$whereSql}
                   ORDER BY s.stage_order, g.grade_order, m.term, m.id DESC";
$stmt = $db->prepare($materialsQuery);
$stmt->execute($queryParams);
$materialsList = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

// Calculate Statistics with a SINGLE aggregated query
$statRow = $db->query("SELECT
    COUNT(*) as total,
    SUM(CASE WHEN enabled = 1 THEN 1 ELSE 0 END) as active_count,
    SUM(CASE WHEN enabled = 0 THEN 1 ELSE 0 END) as hidden_count,
    COUNT(DISTINCT stage_id) as stages_count,
    SUM(CASE WHEN downloadable = 1 THEN 1 ELSE 0 END) as downloadable_count,
    SUM(CASE WHEN downloadable = 0 THEN 1 ELSE 0 END) as coming_soon_count
FROM materials")->fetch(PDO::FETCH_ASSOC);

$totalMaterials = $statRow['total'] ?? 0;
$activeMaterials = $statRow['active_count'] ?? 0;
$hiddenMaterials = $statRow['hidden_count'] ?? 0;
$stagesCovered = $statRow['stages_count'] ?? 0;
$downloadableMaterials = $statRow['downloadable_count'] ?? 0;
$comingSoonMaterials = $statRow['coming_soon_count'] ?? 0;

// Pre-load stage & grade material counts maps (ZERO queries inside loops)
$stageCountsMap = [];
$scRows = $db->query("SELECT stage_id, COUNT(*) as cnt FROM materials GROUP BY stage_id")->fetchAll(PDO::FETCH_ASSOC);
foreach ($scRows as $sc) {
    $stageCountsMap[$sc['stage_id']] = $sc['cnt'];
}

$gradeCountsMap = [];
$gcRows = $db->query("SELECT grade_id, COUNT(*) as cnt FROM materials GROUP BY grade_id")->fetchAll(PDO::FETCH_ASSOC);
foreach ($gcRows as $gc) {
    $gradeCountsMap[$gc['grade_id']] = $gc['cnt'];
}

$showStageCol = ($tabStageId == 0);
$showGradeCol = ($filter_grade == 0);
$totalColsCount = 2 + ($showStageCol ? 1 : 0) + ($showGradeCol ? 1 : 0) + 6;
$actionColIndex = 7 + ($showStageCol ? 1 : 0) + ($showGradeCol ? 1 : 0);

// Render Admin Header
require_once '../includes/admin_header.php';
?>

<!-- Custom Styling -->
<style>
/* Table layout */
#materialsTable {
    width: 100% !important;
    margin-bottom: 0;
}
#materialsTable th,
#materialsTable td {
    padding: 0.45rem 0.35rem !important;
    font-size: 0.85rem;
    vertical-align: middle;
}
#materialsTable .badge {
    font-size: 0.75rem;
    padding: 0.35em 0.55em;
    font-weight: 600;
}
#materialsTable th:nth-child(1), #materialsTable td:nth-child(1) { width: 35px; text-align: center; }
#materialsTable th:nth-child(2), #materialsTable td:nth-child(2) { width: 45px; text-align: center; }
#materialsTable th:nth-child(7), #materialsTable td:nth-child(7) { max-width: 130px; }
#materialsTable th:nth-child(11), #materialsTable td:nth-child(11) { width: 130px; min-width: 130px; text-align: center; }
.table-responsive {
    overflow-x: auto;
    scrollbar-width: thin;
}

/* Bulk Action Cards Layout */
.bulk-action-grid {
    display: grid;
    grid-template-columns: repeat(auto-fill, minmax(290px, 1fr));
    gap: 0.75rem;
}
.bulk-action-card {
    position: relative;
    border: 2px solid #e2e8f0;
    border-radius: 0.75rem;
    padding: 0.85rem 1rem;
    background: #ffffff;
    cursor: pointer;
    transition: all 0.2s ease;
    display: flex;
    align-items: flex-start;
    gap: 0.75rem;
}
.bulk-action-card:hover {
    border-color: #cbd5e1;
    transform: translateY(-2px);
    box-shadow: 0 4px 10px rgba(0,0,0,0.05);
}
.bulk-action-card input[type="radio"] {
    margin-top: 0.25rem;
    cursor: pointer;
    width: 1.15rem;
    height: 1.15rem;
    flex-shrink: 0;
}
.bulk-action-card.active-selected {
    border-color: #2563eb;
    background: #eff6ff;
    box-shadow: 0 4px 12px rgba(37, 99, 235, 0.12);
}
.bulk-action-icon {
    font-size: 1.25rem;
    width: 2.25rem;
    height: 2.25rem;
    display: flex;
    align-items: center;
    justify-content: center;
    border-radius: 0.5rem;
    flex-shrink: 0;
}
.bulk-action-content {
    flex: 1;
}
.bulk-action-title {
    font-weight: 700;
    font-size: 0.9rem;
    color: #1e293b;
    margin-bottom: 0.15rem;
}
.bulk-action-desc {
    font-size: 0.76rem;
    color: #64748b;
    line-height: 1.3;
/* Table Action Buttons */
</style>

<!-- Page Header -->
<div class="admin-page-heading">
    <h1 class="h2"><i class="fas fa-cloud-upload-alt text-primary me-2"></i>مركز رفع المواد التعليمية</h1>
    <div class="admin-top-actions no-print">
        <button class="btn btn-header-premium btn-success shadow-sm me-1" data-bs-toggle="modal" data-bs-target="#addMaterialModal">
            <i class="fas fa-plus-circle me-1"></i>رفع مادة جديدة
        </button>
        <button type="button" class="btn btn-header-premium btn-import-soft me-1" data-bs-toggle="modal" data-bs-target="#supervisorPreviewModal">
            <i class="fas fa-user-shield me-1"></i>رابط المعاينة للمشرفين
        </button>
        <button type="button" class="btn btn-header-premium btn-print-soft" data-bs-toggle="modal" data-bs-target="#bulkActionModal">
            <i class="fas fa-cogs me-1"></i>التحكم الجماعي المتقدم
        </button>
    </div>
</div>

<!-- Alert Messages -->
<?php if ($success_message): ?>
    <div class="alert alert-success alert-dismissible fade show" role="alert">
        <i class="fas fa-check-circle me-2"></i><?php echo htmlspecialchars($success_message); ?>
        <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
    </div>
<?php endif; ?>

<?php if ($error_message): ?>
    <div class="alert alert-danger alert-dismissible fade show" role="alert">
        <i class="fas fa-exclamation-circle me-2"></i><?php echo htmlspecialchars($error_message); ?>
        <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
    </div>
<?php endif; ?>

<!-- Stat Cards Row -->
<div class="dashboard-canvas sortable-dashboard">
    <div class="row row-cols-2 row-cols-md-3 row-cols-xl-5 g-3 mb-4">
        <div class="col">
            <div class="stat-card" style="--card-gradient: linear-gradient(135deg, #3b82f6, #2563eb);">
                <div class="stat-card-icon"><i class="fas fa-book-open"></i></div>
                <div class="stat-card-info">
                    <div class="stat-card-number counter" data-target="<?php echo (int) $totalMaterials; ?>">0</div>
                    <div class="stat-card-label">إجمالي المواد</div>
                    <div class="stat-card-sub"><i class="fas fa-file-pdf me-1"></i>ملفات مرفوعة</div>
                </div>
            </div>
        </div>
        <div class="col">
            <div class="stat-card" style="--card-gradient: linear-gradient(135deg, #10b981, #059669);">
                <div class="stat-card-icon"><i class="fas fa-eye"></i></div>
                <div class="stat-card-info">
                    <div class="stat-card-number counter" data-target="<?php echo (int) $activeMaterials; ?>">0</div>
                    <div class="stat-card-label">المواد الظاهرة</div>
                    <div class="stat-card-sub"><i class="fas fa-check-circle me-1"></i>متاحة للطلاب</div>
                </div>
            </div>
        </div>
        <div class="col">
            <div class="stat-card" style="--card-gradient: linear-gradient(135deg, #ef4444, #dc2626);">
                <div class="stat-card-icon"><i class="fas fa-eye-slash"></i></div>
                <div class="stat-card-info">
                    <div class="stat-card-number counter" data-target="<?php echo (int) $hiddenMaterials; ?>">0</div>
                    <div class="stat-card-label">المواد المخفية</div>
                    <div class="stat-card-sub"><i class="fas fa-ban me-1"></i>غير مسجلة بالعرض</div>
                </div>
            </div>
        </div>
        <div class="col">
            <div class="stat-card" style="--card-gradient: linear-gradient(135deg, #0ea5e9, #0284c7);">
                <div class="stat-card-icon"><i class="fas fa-file-download"></i></div>
                <div class="stat-card-info">
                    <div class="stat-card-number counter" data-target="<?php echo (int) $downloadableMaterials; ?>">0</div>
                    <div class="stat-card-label">متاحة للتحميل</div>
                    <div class="stat-card-sub"><i class="fas fa-download me-1"></i>جاهزة للتحميل</div>
                </div>
            </div>
        </div>
        <div class="col">
            <div class="stat-card" style="--card-gradient: linear-gradient(135deg, #f59e0b, #d97706);">
                <div class="stat-card-icon"><i class="fas fa-clock"></i></div>
                <div class="stat-card-info">
                    <div class="stat-card-number counter" data-target="<?php echo (int) $comingSoonMaterials; ?>">0</div>
                    <div class="stat-card-label">تظهر كـ قريباً</div>
                    <div class="stat-card-sub"><i class="fas fa-hourglass-half me-1"></i>قيد التجهيز</div>
                </div>
            </div>
        </div>
    </div>
</div>

<!-- Stage Navigation Tabs (Matching ui_preview.php Unified System) -->
<ul class="nav nav-tabs mb-3 border-bottom" id="stageTabs" role="tablist">
    <li class="nav-item" role="presentation">
        <a class="nav-link fw-semibold <?php echo $activeTab === 'all' ? 'active' : ''; ?>" href="materials_center.php?tab=all">
            جميع المراحل <span class="badge <?php echo $activeTab === 'all' ? 'bg-primary' : 'bg-secondary'; ?> ms-1"><?php echo number_format($totalMaterials); ?></span>
        </a>
    </li>
    <?php foreach ($stages as $s): ?>
        <?php
        $tabKey = 'stage_' . $s['id'];
        $sCount = $stageCountsMap[$s['id']] ?? 0;
        $isActive = ($activeTab === $tabKey);
        ?>
        <li class="nav-item" role="presentation">
            <a class="nav-link fw-semibold <?php echo $isActive ? 'active' : ''; ?>" href="materials_center.php?tab=<?php echo $tabKey; ?>">
                <?php echo htmlspecialchars($s['stage_name']); ?>
                <span class="badge <?php echo $isActive ? 'bg-primary' : 'bg-secondary'; ?> ms-1"><?php echo $sCount; ?></span>
            </a>
        </li>
    <?php endforeach; ?>
</ul>



<form method="GET" action="materials_center.php" class="admin-filter-bar mb-3" id="filterForm" novalidate>
    <input type="hidden" name="tab" class="active-tab-input" value="<?php echo htmlspecialchars($activeTab); ?>">
    <div class="admin-filter-controls">
        <!-- Stages Dropdown (Multi-Select) -->
        <?php if ($tabStageId == 0): ?>
            <div class="dropdown d-inline-block me-2">
                <button class="btn btn-light dropdown-toggle btn-sm filter-dropdown-btn" type="button" id="stageDropdown" data-bs-toggle="dropdown" data-bs-auto-close="outside" aria-expanded="false" style="background: white; border-color: #dee2e6; color: #495057; height: 31px; display: inline-flex; align-items: center; justify-content: space-between; min-width: 140px;">
                    <span>المراحل: <span id="selectedStagesLabel" class="fw-bold">الكل</span></span>
                </button>
                <div class="dropdown-menu p-3" aria-labelledby="stageDropdown" style="max-height: 250px; overflow-y: auto; min-width: 200px; text-align: right; box-shadow: 0 10px 15px -3px rgba(0, 0, 0, 0.1);">
                    <?php foreach ($stages as $s): ?>
                        <div class="form-check mb-1">
                            <input class="form-check-input stage-checkbox" type="checkbox" name="stage_ids[]" value="<?php echo $s['id']; ?>" id="stage_<?php echo $s['id']; ?>" <?php echo in_array((int)$s['id'], $filter_stage_ids) ? 'checked' : ''; ?>>
                            <label class="form-check-label" for="stage_<?php echo $s['id']; ?>"><?php echo htmlspecialchars($s['stage_name']); ?></label>
                        </div>
                    <?php endforeach; ?>
                </div>
            </div>
        <?php endif; ?>

        <!-- Grades Dropdown (Multi-Select) -->
        <div class="dropdown d-inline-block me-2">
            <button class="btn btn-light dropdown-toggle btn-sm filter-dropdown-btn" type="button" id="gradeDropdown" data-bs-toggle="dropdown" data-bs-auto-close="outside" aria-expanded="false" style="background: white; border-color: #dee2e6; color: #495057; height: 31px; display: inline-flex; align-items: center; justify-content: space-between; min-width: 140px;">
                <span>الصفوف: <span id="selectedGradesLabel" class="fw-bold">الكل</span></span>
            </button>
            <div class="dropdown-menu p-3" aria-labelledby="gradeDropdown" style="max-height: 250px; overflow-y: auto; min-width: 220px; text-align: right; box-shadow: 0 10px 15px -3px rgba(0, 0, 0, 0.1);">
                <?php foreach ($grades as $g): ?>
                    <?php if ($tabStageId == 0 || $g['stage_id'] == $tabStageId): ?>
                        <div class="form-check mb-1 grade-item" data-stage="<?php echo $g['stage_id']; ?>">
                            <input class="form-check-input grade-checkbox" type="checkbox" name="grade_ids[]" value="<?php echo $g['id']; ?>" id="grade_<?php echo $g['id']; ?>" <?php echo in_array((int)$g['id'], $filter_grade_ids) ? 'checked' : ''; ?>>
                            <label class="form-check-label" for="grade_<?php echo $g['id']; ?>"><?php echo htmlspecialchars($g['grade_name']); ?></label>
                        </div>
                    <?php endif; ?>
                <?php endforeach; ?>
            </div>
        </div>

        <!-- Term Dropdown (Multi-Select) -->
        <div class="dropdown d-inline-block me-2">
            <button class="btn btn-light dropdown-toggle btn-sm filter-dropdown-btn" type="button" id="termDropdown" data-bs-toggle="dropdown" data-bs-auto-close="outside" aria-expanded="false" style="background: white; border-color: #dee2e6; color: #495057; height: 31px; display: inline-flex; align-items: center; justify-content: space-between; min-width: 175px;">
                <span>الفصل الدراسي (الترم): <span id="selectedTermsLabel" class="fw-bold">الكل</span></span>
            </button>
            <div class="dropdown-menu p-3" aria-labelledby="termDropdown" style="max-height: 250px; overflow-y: auto; min-width: 200px; text-align: right; box-shadow: 0 10px 15px -3px rgba(0, 0, 0, 0.1);">
                <div class="form-check mb-1">
                    <input class="form-check-input term-checkbox" type="checkbox" name="terms[]" value="term1" id="term_term1" <?php echo in_array('term1', $filter_terms) ? 'checked' : ''; ?>>
                    <label class="form-check-label" for="term_term1">الترم الأول</label>
                </div>
                <div class="form-check mb-1">
                    <input class="form-check-input term-checkbox" type="checkbox" name="terms[]" value="term2" id="term_term2" <?php echo in_array('term2', $filter_terms) ? 'checked' : ''; ?>>
                    <label class="form-check-label" for="term_term2">الترم الثاني</label>
                </div>
            </div>
        </div>

        <!-- Status Dropdown (Multi-Select) -->
        <div class="dropdown d-inline-block me-2">
            <button class="btn btn-light dropdown-toggle btn-sm filter-dropdown-btn" type="button" id="statusDropdown" data-bs-toggle="dropdown" data-bs-auto-close="outside" aria-expanded="false" style="background: white; border-color: #dee2e6; color: #495057; height: 31px; display: inline-flex; align-items: center; justify-content: space-between; min-width: 130px;">
                <span>الحالة: <span id="selectedStatusesLabel" class="fw-bold">الكل</span></span>
            </button>
            <div class="dropdown-menu p-3" aria-labelledby="statusDropdown" style="max-height: 250px; overflow-y: auto; min-width: 180px; text-align: right; box-shadow: 0 10px 15px -3px rgba(0, 0, 0, 0.1);">
                <div class="form-check mb-1">
                    <input class="form-check-input status-checkbox" type="checkbox" name="statuses[]" value="1" id="status_1" <?php echo in_array('1', $filter_statuses) ? 'checked' : ''; ?>>
                    <label class="form-check-label" for="status_1">مفعّل (ظاهر)</label>
                </div>
                <div class="form-check mb-1">
                    <input class="form-check-input status-checkbox" type="checkbox" name="statuses[]" value="0" id="status_0" <?php echo in_array('0', $filter_statuses) ? 'checked' : ''; ?>>
                    <label class="form-check-label" for="status_0">مخفي</label>
                </div>
            </div>
        </div>
    </div>

    <!-- Filter Actions -->
    <div class="admin-filter-actions">
        <a href="materials_center.php?tab=<?php echo urlencode($activeTab); ?>" class="btn btn-light btn-sm" title="إعادة تعيين الفلاتر" style="height: 31px !important; display: inline-flex !important; align-items: center !important; justify-content: center !important; vertical-align: middle !important;">
            <i class="fas fa-undo me-1"></i>إعادة تعيين
        </a>
        <button type="button" class="btn btn-light btn-sm" data-bs-toggle="modal" data-bs-target="#tableSettingsModal" title="تخصيص أعمدة الجدول" style="height: 31px !important; display: inline-flex !important; align-items: center !important; justify-content: center !important; vertical-align: middle !important;">
            <i class="fas fa-cog me-1"></i>إعدادات الجدول
        </button>
    </div>
</form>

<div class="admin-list-surface">
    <form method="POST" action="materials_center.php" id="bulkForm">
        <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($_SESSION['csrf_token'] ?? ''); ?>">
        <input type="hidden" name="action" value="bulk_action">
        <input type="hidden" name="active_tab" class="active-tab-input" value="<?php echo htmlspecialchars($activeTab); ?>">
        <input type="hidden" name="bulk_type" id="formBulkType" value="">

        <div class="table-responsive admin-table-wrap">
            <table class="table table-hover table-striped datatable admin-data-table" id="materialsTable">
                <thead class="table-light">
                    <tr>
                        <th>#</th>
                        <th>المادة</th>
                        <?php if ($showStageCol): ?>
                            <th>المرحلة</th>
                        <?php endif; ?>
                        <?php if ($showGradeCol): ?>
                            <th>الصف</th>
                        <?php endif; ?>
                        <th>الفصل الدراسي</th>
                        <th>اسم الملف الأصلي</th>
                        <th>الحجم</th>
                        <th>العرض للطلاب</th>
                        <th>التحميل</th>
                        <th class="text-center">الإجراءات</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($materialsList)): ?>
                        <tr>
                            <td colspan="<?php echo $totalColsCount; ?>" class="text-center py-4 text-muted">
                                <i class="fas fa-folder-open fa-2x mb-2 d-block"></i>لا توجد مواد تعليمية تطابق خيارات البحث الحالية.
                            </td>
                        </tr>
                    <?php else: ?>
                        <?php foreach ($materialsList as $idx => $item): ?>
                            <tr data-stage="<?php echo $item['stage_id']; ?>" data-grade="<?php echo $item['grade_id']; ?>" data-term="<?php echo htmlspecialchars($item['term']); ?>" data-status="<?php echo $item['enabled']; ?>">
                                <td><?php echo $idx + 1; ?></td>
                                <td class="fw-bold text-primary">
                                    <i class="fas fa-book me-2"></i><?php echo htmlspecialchars($item['subject_name']); ?>
                                </td>
                                <?php if ($showStageCol): ?>
                                    <td><span class="badge bg-secondary"><?php echo htmlspecialchars($item['stage_name'] ?? 'غير محدد'); ?></span></td>
                                <?php endif; ?>
                                <?php if ($showGradeCol): ?>
                                    <td><span class="badge bg-info text-dark"><?php echo htmlspecialchars($item['grade_name'] ?? 'غير محدد'); ?></span></td>
                                <?php endif; ?>
                                <td>
                                    <?php if ($item['term'] === 'term1'): ?>
                                        <span class="badge bg-primary">الترم الأول</span>
                                    <?php else: ?>
                                        <span class="badge bg-purple" style="background-color: #6f42c1; color: white;">الترم الثاني</span>
                                    <?php endif; ?>
                                </td>
                                <td dir="ltr" class="text-end">
                                    <small class="text-truncate d-inline-block" style="max-width: 130px;" title="<?php echo htmlspecialchars($item['original_file_name']); ?>">
                                        <?php echo !empty($item['original_file_name']) ? htmlspecialchars($item['original_file_name']) : 'لم يرفع ملف بعد'; ?>
                                    </small>
                                </td>
                                <td>
                                    <small class="text-muted">
                                        <?php
                                        $bytes = $item['file_size'];
                                        if ($bytes >= 1048576) {
                                            echo number_format($bytes / 1048576, 2) . ' MB';
                                        } elseif ($bytes >= 1024) {
                                            echo number_format($bytes / 1024, 1) . ' KB';
                                        } else {
                                            echo $bytes . ' B';
                                        }
                                        ?>
                                    </small>
                                </td>
                                <td>
                                    <?php if ($item['enabled']): ?>
                                        <span class="badge bg-success"><i class="fas fa-check-circle me-1"></i>ظاهر</span>
                                    <?php else: ?>
                                        <span class="badge bg-warning text-dark"><i class="fas fa-eye-slash me-1"></i>مخفي</span>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <?php if ($item['downloadable']): ?>
                                        <span class="badge bg-info text-dark"><i class="fas fa-download me-1"></i>متاح</span>
                                    <?php else: ?>
                                        <span class="badge bg-secondary"><i class="fas fa-clock me-1"></i>قريباً</span>
                                    <?php endif; ?>
                                </td>
                                <td class="text-center actions-column admin-table-actions">
                                    <button type="button" class="btn btn-action-pills btn-edit me-1 edit-material-btn"
                                            data-bs-toggle="modal" data-bs-target="#addMaterialModal" title="تعديل المادة"
                                            data-id="<?php echo $item['id']; ?>"
                                            data-stage="<?php echo $item['stage_id']; ?>"
                                            data-grade="<?php echo $item['grade_id']; ?>"
                                            data-term="<?php echo $item['term']; ?>"
                                            data-subject="<?php echo htmlspecialchars($item['subject_name']); ?>"
                                            data-enabled="<?php echo $item['enabled']; ?>"
                                            data-downloadable="<?php echo $item['downloadable']; ?>">
                                        <i class="fas fa-edit"></i>
                                    </button>

                                    <?php if ($item['enabled']): ?>
                                        <button type="button" class="btn btn-action-pills btn-deactivate me-1 toggle-material-btn"
                                                data-bs-toggle="modal" data-bs-target="#toggleMaterialModal" title="إخفاء عن الطلاب"
                                                data-id="<?php echo $item['id']; ?>"
                                                data-name="<?php echo htmlspecialchars($item['subject_name']); ?>"
                                                data-status="1"
                                                data-stage-name="<?php echo htmlspecialchars($item['stage_name'] ?? ''); ?>"
                                                data-grade-name="<?php echo htmlspecialchars($item['grade_name'] ?? ''); ?>"
                                                data-term-name="<?php echo $item['term'] === 'term1' ? 'الترم الأول' : 'الترم الثاني'; ?>">
                                            <i class="fas fa-ban"></i>
                                        </button>
                                    <?php else: ?>
                                        <button type="button" class="btn btn-action-pills btn-activate me-1 toggle-material-btn"
                                                data-bs-toggle="modal" data-bs-target="#toggleMaterialModal" title="إظهار للطلاب"
                                                data-id="<?php echo $item['id']; ?>"
                                                data-name="<?php echo htmlspecialchars($item['subject_name']); ?>"
                                                data-status="0"
                                                data-stage-name="<?php echo htmlspecialchars($item['stage_name'] ?? ''); ?>"
                                                data-grade-name="<?php echo htmlspecialchars($item['grade_name'] ?? ''); ?>"
                                                data-term-name="<?php echo $item['term'] === 'term1' ? 'الترم الأول' : 'الترم الثاني'; ?>">
                                            <i class="fas fa-check"></i>
                                        </button>
                                    <?php endif; ?>

                                    <?php if ($item['downloadable']): ?>
                                        <button type="button" class="btn btn-action-pills btn-deactivate me-1 toggle-downloadable-btn"
                                                data-bs-toggle="modal" data-bs-target="#toggleDownloadableModal" title="تغيير حالة التحميل (تعيين كـ قريباً)"
                                                data-id="<?php echo $item['id']; ?>"
                                                data-name="<?php echo htmlspecialchars($item['subject_name']); ?>"
                                                data-downloadable="1"
                                                data-stage-name="<?php echo htmlspecialchars($item['stage_name'] ?? ''); ?>"
                                                data-grade-name="<?php echo htmlspecialchars($item['grade_name'] ?? ''); ?>"
                                                data-term-name="<?php echo $item['term'] === 'term1' ? 'الترم الأول' : 'الترم الثاني'; ?>">
                                            <i class="fas fa-clock"></i>
                                        </button>
                                    <?php else: ?>
                                        <button type="button" class="btn btn-action-pills btn-activate me-1 toggle-downloadable-btn"
                                                data-bs-toggle="modal" data-bs-target="#toggleDownloadableModal" title="تغيير حالة التحميل (إتاحة التحميل للطلاب)"
                                                data-id="<?php echo $item['id']; ?>"
                                                data-name="<?php echo htmlspecialchars($item['subject_name']); ?>"
                                                data-downloadable="0"
                                                data-stage-name="<?php echo htmlspecialchars($item['stage_name'] ?? ''); ?>"
                                                data-grade-name="<?php echo htmlspecialchars($item['grade_name'] ?? ''); ?>"
                                                data-term-name="<?php echo $item['term'] === 'term1' ? 'الترم الأول' : 'الترم الثاني'; ?>">
                                            <i class="fas fa-file-download"></i>
                                        </button>
                                    <?php endif; ?>

                                    <button type="button" class="btn btn-action-pills btn-edit me-1 quick-upload-trigger-btn"
                                            data-bs-toggle="tooltip" title="رفع سريع للملف مباشرة"
                                            data-id="<?php echo $item['id']; ?>"
                                            data-name="<?php echo htmlspecialchars($item['subject_name']); ?>">
                                        <i class="fas fa-upload"></i>
                                    </button>

                                    <?php if (!empty($item['file_name'])): ?>
                                        <a href="../uploads/materials/<?php echo htmlspecialchars($item['file_name']); ?>" download="<?php echo htmlspecialchars($item['original_file_name']); ?>" class="btn btn-action-pills btn-edit me-1" title="تحميل الملف" data-bs-toggle="tooltip">
                                            <i class="fas fa-download"></i>
                                        </a>
                                    <?php endif; ?>

                                    <button type="button" class="btn btn-action-pills btn-delete delete-material-btn"
                                            data-bs-toggle="modal" data-bs-target="#deleteMaterialModal" title="حذف المادة"
                                            data-id="<?php echo $item['id']; ?>"
                                            data-name="<?php echo htmlspecialchars($item['subject_name']); ?>">
                                        <i class="fas fa-trash"></i>
                                    </button>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </form>
</div>

<!-- Modal: Supervisor Preview Link & Instructions -->
<div class="modal fade" id="supervisorPreviewModal" tabindex="-1" aria-labelledby="supervisorPreviewTitle" aria-hidden="true">
    <div class="modal-dialog modal-lg modal-dialog-centered">
        <div class="modal-content admin-modal admin-modal-premium admin-modal-view">
            <div class="modal-header">
                <h5 class="modal-title fw-bold text-primary" id="supervisorPreviewTitle">
                    <i class="fas fa-user-shield me-2"></i>رابط مركز المعاينة المباشر لمشرفي المواد والمعلمين
                </h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="إغلاق"></button>
            </div>
            <div class="modal-body p-4">
                <p class="text-muted small mb-4">
                    نسخة خاصة من مركز التحميلات تتيح للمشرفين الاطلاع على كافة المواد المرفوعة وتحميل ملفاتها فوراً لاختبارها وتدقيقها قبل إتاحتها للطلاب.
                </p>

                <!-- Filters & Options -->
                <div class="row g-3 mb-4">
                    <div class="col-md-6">
                        <label class="form-label fw-bold small text-dark"><i class="fas fa-filter text-primary me-1"></i>تحديد الصف والمرحلة:</label>
                        <select class="form-select" id="cardGradeSelect" onchange="updateSupervisorCardLink()">
                            <option value="all">🌐 جميع المراحل والصفوف الدراسية</option>
                            <?php foreach ($stages as $stg): ?>
                                <optgroup label="📍 <?php echo htmlspecialchars($stg['stage_name']); ?>">
                                    <option value="stage_<?php echo $stg['id']; ?>" class="fw-bold text-primary">🎯 جميع <?php echo htmlspecialchars($stg['stage_name']); ?></option>
                                    <?php foreach ($grades as $grd): ?>
                                        <?php if ($grd['stage_id'] == $stg['id']): ?>
                                            <option value="<?php echo htmlspecialchars($grd['grade_code'] ?? $grd['id']); ?>">
                                                <?php echo htmlspecialchars($grd['grade_name']); ?>
                                            </option>
                                        <?php endif; ?>
                                    <?php endforeach; ?>
                                </optgroup>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="col-md-6">
                        <label class="form-label fw-bold small text-dark"><i class="fas fa-calendar-alt text-primary me-1"></i>الفصل الدراسي:</label>
                        <select class="form-select" id="cardTermSelect" onchange="updateSupervisorCardLink()">
                            <option value="term1">الترم الأول</option>
                            <option value="term2">الترم الثاني</option>
                        </select>
                    </div>
                </div>

                <style>
                .soft-pill-switch .btn-check + .btn {
                    color: #475569;
                    background-color: #ffffff;
                    border: 1px solid #cbd5e1;
                    font-weight: 600;
                    border-radius: 50rem !important;
                    padding: 0.45rem 1.25rem;
                    transition: all 0.2s ease-in-out;
                }
                .soft-pill-switch .btn-check + .btn:hover {
                    background-color: #f1f5f9;
                    color: #1e293b;
                    border-color: #94a3b8;
                }
                .soft-pill-switch .btn-check:checked + .btn {
                    color: #ffffff !important;
                    background-color: #2563eb !important;
                    border-color: #2563eb !important;
                    box-shadow: 0 4px 12px rgba(37, 99, 235, 0.25) !important;
                }
                </style>

                <!-- Network Mode Selection -->
                <div class="d-flex align-items-center justify-content-between flex-wrap gap-2 mb-3 p-3 rounded-3 bg-light border">
                    <label class="form-label fw-bold small text-dark mb-0"><i class="fas fa-network-wired text-primary me-1"></i>نوع الرابط المطلوب:</label>
                    <div class="soft-pill-switch d-inline-flex align-items-center gap-2">
                        <input type="radio" class="btn-check" name="supervisor_link_mode" id="linkModeInternal" value="internal" autocomplete="off" checked>
                        <label class="btn btn-sm" for="linkModeInternal">
                            <i class="fas fa-building me-1.5"></i>الشبكة الداخلية
                        </label>

                        <input type="radio" class="btn-check" name="supervisor_link_mode" id="linkModeExternal" value="external" autocomplete="off">
                        <label class="btn btn-sm" for="linkModeExternal">
                            <i class="fas fa-globe me-1.5"></i>الرابط الخارجي
                        </label>
                    </div>
                </div>

                <!-- Generated Link Field -->
                <div class="mb-4">
                    <label class="form-label fw-bold small text-dark mb-2"><i class="fas fa-link text-primary me-1"></i>الرابط المولّد للمعاينة:</label>

                    <!-- Row 1: Full Link Input + Copy Button -->
                    <div class="bg-light rounded-3 border shadow-sm d-flex align-items-center gap-3 mb-3" style="min-height: 60px; padding: 0.85rem 1.25rem;">
                        <div class="d-flex align-items-center gap-2 flex-grow-1 min-w-0 px-1" dir="ltr">
                            <span class="text-primary fs-5"><i class="fas fa-link"></i></span>
                            <input type="text" class="form-control border-0 bg-transparent text-primary fw-bold dir-ltr p-0 shadow-none" id="supervisorLinkInput" readonly value="<?php echo htmlspecialchars($supervisorPreviewBaseUrl . '?grade=all&scope=all&term=term1', ENT_QUOTES, 'UTF-8'); ?>" style="font-size: 0.95rem;">
                        </div>
                        <button type="button" class="btn btn-sm btn-primary rounded-pill px-3 shadow-sm text-nowrap" onclick="copySupervisorLink(this)" data-bs-toggle="tooltip" title="نسخ الرابط المباشر" style="padding: 0.45rem 1.25rem; font-weight: 600;">
                            <i class="fas fa-copy me-1.5"></i>نسخ الرابط
                        </button>
                    </div>

                    <!-- Row 2: Dedicated Button Row for Opening Preview Portal -->
                    <div>
                        <a href="../student/materials/supervisor_preview.php?grade=KG+1&term=term1" id="supervisorOpenBtn" target="_blank" class="btn btn-outline-primary w-100 py-2 fw-bold shadow-sm" title="فتح بوابة المعاينة المباشرة في نافذة جديدة">
                            <i class="fas fa-external-link-alt me-2"></i>الانتقال إلى بوابة المعاينة المباشرة
                        </a>
                    </div>
                </div>

                <!-- Instructions Box -->
                <div class="p-4 rounded-3 bg-light border border-primary-subtle shadow-sm" style="padding: 1.25rem 1.5rem !important;">
                    <h6 class="fw-bold text-primary mb-3"><i class="fas fa-tasks me-2"></i>تعليمات وتشغيل دورة الاعتماد:</h6>
                    <ol class="mb-0 text-dark fw-medium" style="line-height: 1.8; font-size: 0.92rem; color: #1e293b !important; padding-right: 1.25rem !important;">
                        <li class="mb-1">اختر الصف والترم أعلاه لتوليد رابط المعاينة المباشر الخاص به.</li>
                        <li class="mb-1">شارك هذا الرابط مع مشرفي المواد والمعلمين لتطبيق الفحص.</li>
                        <li class="mb-1">يدخل المشرف ويقوم بتحميل الملفات وتجربتها للتأكد من سلامتها.</li>
                        <li class="mb-1">بعد مراجعة الملفات يقوم المشرف بترك تأكيد وتواصل هاتفي.</li>
                        <li class="mb-0">يقوم الأدمن بتغيير حالة التحميل للمادة من <strong class="text-primary">"قريباً"</strong> إلى <strong class="text-success">"متاح للتحميل"</strong> لتظهر للطلاب.</li>
                    </ol>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">
                    <i class="fas fa-times me-1"></i>إغلاق
                </button>
            </div>
        </div>
    </div>
</div>

<!-- Modal: Add / Edit Material -->
<div class="modal fade" id="addMaterialModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-lg">
        <div class="modal-content admin-modal admin-modal-premium admin-modal-create" id="materialModalContent">
            <form method="POST" action="materials_center.php" enctype="multipart/form-data" id="materialForm">
                <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($_SESSION['csrf_token'] ?? ''); ?>">
                <input type="hidden" name="active_tab" class="active-tab-input" value="<?php echo htmlspecialchars($activeTab); ?>">
                <input type="hidden" name="action" id="modalAction" value="add">
                <input type="hidden" name="id" id="modalMaterialId" value="">

                <div class="modal-header">
                    <h5 class="modal-title" id="modalTitle"><i class="fas fa-plus-circle me-2"></i>رفع مادة تعليمية جديدة</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body">
                    <div class="row g-3">
                        <div class="col-md-6">
                            <label for="modalStage" class="form-label fw-bold">المرحلة الدراسية <span class="text-danger">*</span></label>
                            <select class="form-select" id="modalStage" name="stage_id" required>
                                <option value="">اختر المرحلة...</option>
                                <?php foreach ($stages as $s): ?>
                                    <option value="<?php echo $s['id']; ?>"><?php echo htmlspecialchars($s['stage_name']); ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-md-6">
                            <label for="modalGrade" class="form-label fw-bold">الصف الدراسي <span class="text-danger">*</span></label>
                            <select class="form-select" id="modalGrade" name="grade_id" required>
                                <option value="">اختر الصف...</option>
                                <?php foreach ($grades as $g): ?>
                                    <option value="<?php echo $g['id']; ?>" data-stage="<?php echo $g['stage_id']; ?>"><?php echo htmlspecialchars($g['grade_name']); ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-md-6">
                            <label for="modalTerm" class="form-label fw-bold">الفصل الدراسي <span class="text-danger">*</span></label>
                            <select class="form-select" id="modalTerm" name="term" required>
                                <option value="term1">الترم الأول</option>
                                <option value="term2">الترم الثاني</option>
                            </select>
                        </div>
                        <div class="col-md-6">
                            <label for="modalSubject" class="form-label fw-bold">اسم المادة <span class="text-danger">*</span></label>
                            <input type="text" class="form-input form-control" id="modalSubject" name="subject_name" placeholder="مثال: اللغة العربية، Math..." required>
                        </div>
                        <div class="col-md-12">
                            <label for="modalFile" class="form-label fw-bold">ملف المادة (PDF) <span class="text-danger" id="fileRequiredStar">*</span></label>
                            <input type="file" class="form-control" id="modalFile" name="material_file" accept=".pdf">
                            <div class="form-text"><i class="fas fa-info-circle me-1"></i>يجب أن يكون الملف بصيغة PDF وبحجم لا يتجاوز 50 ميجابايت.</div>
                        </div>
                        <div class="col-md-6">
                            <div class="form-check form-switch mt-2">
                                <input class="form-check-input" type="checkbox" id="modalEnabled" name="enabled" value="1" checked>
                                <label class="form-check-label fw-bold" for="modalEnabled">إظهار المادة للطلاب</label>
                            </div>
                        </div>
                        <div class="col-md-6">
                            <div class="form-check form-switch mt-2">
                                <input class="form-check-input" type="checkbox" id="modalDownloadable" name="downloadable" value="1" checked>
                                <label class="form-check-label fw-bold" for="modalDownloadable">متاحة للتحميل (إذا أُلغيت ستظهر كـ "قريباً")</label>
                            </div>
                        </div>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">
                        <i class="fas fa-times me-1"></i>إلغاء
                    </button>
                    <button type="submit" class="btn btn-success" id="modalSubmitBtn">
                        <i class="fas fa-save me-1"></i>حفظ ورفع
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- Modal: Advanced Bulk Action (التحكم الجماعي المطور) -->
<div class="modal fade" id="bulkActionModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-xl">
        <div class="modal-content admin-modal admin-modal-premium admin-modal-warning">
            <form method="POST" action="materials_center.php" id="bulkActionModalForm">
                <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($_SESSION['csrf_token'] ?? ''); ?>">
                <input type="hidden" name="active_tab" class="active-tab-input" value="<?php echo htmlspecialchars($activeTab); ?>">
                <input type="hidden" name="action" value="bulk_action">

                <div class="modal-header">
                    <h5 class="modal-title fw-bold"><i class="fas fa-cogs me-2"></i>التحكم الإداري الجماعي والمتقدم في المواد</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body">
                    <div class="alert alert-info mb-3 py-2">
                        <i class="fas fa-info-circle me-2"></i>تتيح لك هذه الأداة تنفيذ عمليات إدارية جماعية وتطبييقها على نطاق محدد من المواد دفعة واحدة.
                    </div>

                    <!-- Scope Selection -->
                    <div class="card bg-light border-0 p-3 mb-4">
                        <h6 class="fw-bold text-primary mb-3"><i class="fas fa-filter me-2"></i>1. تحديد نطاق التطبيق الجماعي</h6>
                        <div class="row g-3">
                            <div class="col-md-4">
                                <label class="form-label fw-bold">المرحلة الدراسية</label>
                                <select class="form-select" name="bulk_stage_id" id="bulkStageSelect">
                                    <option value="">جميع المراحل (أو العناصر المحددة بالجدول)</option>
                                    <?php foreach ($stages as $s): ?>
                                        <option value="<?php echo $s['id']; ?>"><?php echo htmlspecialchars($s['stage_name']); ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <div class="col-md-4">
                                <label class="form-label fw-bold">الصف الدراسي</label>
                                <select class="form-select" name="bulk_grade_id" id="bulkGradeSelect">
                                    <option value="">جميع الصفوف</option>
                                    <?php foreach ($grades as $g): ?>
                                        <option value="<?php echo $g['id']; ?>" data-stage="<?php echo $g['stage_id']; ?>"><?php echo htmlspecialchars($g['grade_name']); ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <div class="col-md-4">
                                <label class="form-label fw-bold">الفصل الدراسي</label>
                                <select class="form-select" name="bulk_term">
                                    <option value="">جميع الفصول الدراسية (الترم)</option>
                                    <option value="term1">الترم الأول</option>
                                    <option value="term2">الترم الثاني</option>
                                </select>
                            </div>
                        </div>
                    </div>

                    <!-- Actions Cards Selection -->
                    <div class="mb-2">
                        <h6 class="fw-bold text-primary mb-3"><i class="fas fa-tasks me-2"></i>2. اختر الإجراء الجماعي المراد تنفيذه (8 إجراءات متاحة)</h6>

                        <!-- Category A -->
                        <div class="fw-bold text-dark mb-2 mt-3 fs-6"><i class="fas fa-toggle-on me-2 text-primary"></i>أ. حالة العرض والتحميل للطلاب:</div>
                        <div class="bulk-action-grid mb-3">
                            <label class="bulk-action-card active-selected">
                                <input type="radio" name="bulk_type" value="set_coming_soon" checked>
                                <div class="bulk-action-icon bg-warning text-dark"><i class="fas fa-clock"></i></div>
                                <div class="bulk-action-content">
                                    <div class="bulk-action-title">تعيين حالة التحميل إلى (قريباً) ⏳</div>
                                    <div class="bulk-action-desc">يعطل إمكانية تحميل الملفات وتظهر شارة "قريباً" للطلاب مع الإبقاء على مادة بالعرض.</div>
                                </div>
                            </label>

                            <label class="bulk-action-card">
                                <input type="radio" name="bulk_type" value="set_downloadable">
                                <div class="bulk-action-icon bg-info text-dark"><i class="fas fa-download"></i></div>
                                <div class="bulk-action-content">
                                    <div class="bulk-action-title">تفعيل وقابلية التحميل للجميع 📥</div>
                                    <div class="bulk-action-desc">يتيح للطلاب تحميل ملفات الـ PDF المرفوعة للمواد في النطاق المحدد.</div>
                                </div>
                            </label>

                            <label class="bulk-action-card">
                                <input type="radio" name="bulk_type" value="enable_all">
                                <div class="bulk-action-icon bg-success text-white"><i class="fas fa-eye"></i></div>
                                <div class="bulk-action-content">
                                    <div class="bulk-action-title">إظهار جميع مواد النطاق 👁️</div>
                                    <div class="bulk-action-desc">يجعل جميع المواد في النطاق محددة كـ "ظاهرة" في صفحة الطالب.</div>
                                </div>
                            </label>

                            <label class="bulk-action-card">
                                <input type="radio" name="bulk_type" value="disable_all">
                                <div class="bulk-action-icon bg-secondary text-white"><i class="fas fa-eye-slash"></i></div>
                                <div class="bulk-action-content">
                                    <div class="bulk-action-title">إخفاء جميع مواد النطاق 🙈</div>
                                    <div class="bulk-action-desc">يحول جميع مواد النطاق لـ "مخفية" فلن تظهر إطلاقاً في حسابات الطلاب.</div>
                                </div>
                            </label>
                        </div>

                        <!-- Category B -->
                        <div class="fw-bold text-dark mb-2 mt-3 fs-6"><i class="fas fa-folder-open me-2 text-primary"></i>ب. إدارة الملفات والفصول الدراسية:</div>
                        <div class="bulk-action-grid mb-3">
                            <label class="bulk-action-card">
                                <input type="radio" name="bulk_type" value="clear_files_only">
                                <div class="bulk-action-icon bg-orange text-white" style="background-color: #f97316;"><i class="fas fa-file-excel"></i></div>
                                <div class="bulk-action-content">
                                    <div class="bulk-action-title">تفريغ وسحب الملفات فقط 📄</div>
                                    <div class="bulk-action-desc">يحذف ملفات الـ PDF المرفقة فقط ويحظر التحميل مع الإبقاء على أسماء المواد جاهزة.</div>
                                </div>
                            </label>

                            <label class="bulk-action-card">
                                <input type="radio" name="bulk_type" value="move_to_term1">
                                <div class="bulk-action-icon bg-primary text-white"><i class="fas fa-exchange-alt"></i></div>
                                <div class="bulk-action-content">
                                    <div class="bulk-action-title">نقل النطاق للترم الأول 🔄</div>
                                    <div class="bulk-action-desc">يحول جميع المواد في النطاق المحدد لتصبح تابعة للفصل الدراسي الأول.</div>
                                </div>
                            </label>

                            <label class="bulk-action-card">
                                <input type="radio" name="bulk_type" value="move_to_term2">
                                <div class="bulk-action-icon bg-purple text-white" style="background-color: #6f42c1;"><i class="fas fa-exchange-alt"></i></div>
                                <div class="bulk-action-content">
                                    <div class="bulk-action-title">نقل النطاق للترم الثاني 🔄</div>
                                    <div class="bulk-action-desc">يحول جميع المواد في النطاق المحدد لتصبح تابعة للفصل الدراسي الثاني.</div>
                                </div>
                            </label>
                        </div>

                        <!-- Category C -->
                        <div class="fw-bold text-dark mb-2 mt-3 fs-6"><i class="fas fa-trash-alt me-2 text-danger"></i>ج. عمليات الحذف والتنظيف النهائي:</div>
                        <div class="bulk-action-grid">
                            <label class="bulk-action-card border-danger">
                                <input type="radio" name="bulk_type" value="delete_bulk">
                                <div class="bulk-action-icon bg-danger text-white"><i class="fas fa-trash-alt"></i></div>
                                <div class="bulk-action-content">
                                    <div class="bulk-action-title text-danger">حذف جماعي وشامل للنطاق 🗑️</div>
                                    <div class="bulk-action-desc text-danger">يحذف جميع المواد في النطاق بشكل نهائي من قاعدة البيانات مع حذف ملفاتها.</div>
                                </div>
                            </label>
                        </div>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">
                        <i class="fas fa-times me-1"></i>إلغاء
                    </button>
                    <button type="submit" class="btn btn-warning fw-bold px-4">
                        <i class="fas fa-check-circle me-1"></i>تأكيد وتنفيذ الإجراء الجماعي
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- Modal: Confirm Delete -->
<!-- Modal: Confirm Toggle Display Status -->
<div class="modal fade" id="toggleMaterialModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog">
        <div class="modal-content admin-modal admin-modal-premium admin-modal-warning" id="toggleMaterialModalContent">
            <form method="POST" action="materials_center.php">
                <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($_SESSION['csrf_token'] ?? ''); ?>">
                <input type="hidden" name="active_tab" class="active-tab-input" value="<?php echo htmlspecialchars($activeTab); ?>">
                <input type="hidden" name="action" value="toggle">
                <input type="hidden" name="id" id="toggleMaterialId">
                <div class="modal-header">
                    <h5 class="modal-title"><i class="fas fa-toggle-on me-2"></i>تغيير حالة إظهار/إخفاء المادة</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body text-center">
                    <div class="mb-3" id="toggleModalIcon"></div>
                    <p id="toggleModalText" class="mb-3 text-dark fw-medium fs-6"></p>

                    <!-- Target Material Details Card -->
                    <div class="rounded-3 bg-light border shadow-sm mx-auto" style="max-width: 92%; padding: 1.25rem 1.5rem !important;">
                        <div class="text-muted small fw-semibold mb-2"><i class="fas fa-file-alt text-primary" style="margin-left: 6px !important;"></i>تفاصيل المادة المستهدفة:</div>
                        <h5 class="fw-bold text-dark mb-3" id="toggleMaterialName" style="font-size: 1.2rem;"></h5>
                        <div id="toggleMaterialMeta"></div>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal"><i class="fas fa-times me-1"></i>إلغاء</button>
                    <button type="submit" class="btn btn-warning" id="toggleModalSubmitBtn"><i class="fas fa-ban me-1"></i>تأكيد</button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- Modal: Confirm Toggle Downloadable Status -->
<div class="modal fade" id="toggleDownloadableModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog">
        <div class="modal-content admin-modal admin-modal-premium admin-modal-warning" id="toggleDownloadableModalContent">
            <form method="POST" action="materials_center.php">
                <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($_SESSION['csrf_token'] ?? ''); ?>">
                <input type="hidden" name="active_tab" class="active-tab-input" value="<?php echo htmlspecialchars($activeTab); ?>">
                <input type="hidden" name="action" value="toggle_downloadable">
                <input type="hidden" name="id" id="toggleDownloadableMaterialId">
                <div class="modal-header">
                    <h5 class="modal-title"><i class="fas fa-file-download me-2"></i>تغيير حالة إتاحة التحميل</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body text-center">
                    <div class="mb-3" id="toggleDownloadableModalIcon"></div>
                    <p id="toggleDownloadableModalText" class="mb-3 text-dark fw-medium fs-6"></p>

                    <!-- Target Material Details Card -->
                    <div class="rounded-3 bg-light border shadow-sm mx-auto" style="max-width: 92%; padding: 1.25rem 1.5rem !important;">
                        <div class="text-muted small fw-semibold mb-2"><i class="fas fa-file-alt text-primary" style="margin-left: 6px !important;"></i>تفاصيل المادة المستهدفة:</div>
                        <h5 class="fw-bold text-dark mb-3" id="toggleDownloadableMaterialName" style="font-size: 1.2rem;"></h5>
                        <div id="toggleDownloadableMaterialMeta"></div>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal"><i class="fas fa-times me-1"></i>إلغاء</button>
                    <button type="submit" class="btn btn-primary" id="toggleDownloadableModalSubmitBtn"><i class="fas fa-check me-1"></i>تأكيد الإجراء</button>
                </div>
            </form>
        </div>
    </div>
</div>

<div class="modal fade" id="deleteMaterialModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog">
        <div class="modal-content admin-modal admin-modal-premium admin-modal-delete">
            <form method="POST" action="materials_center.php">
                <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($_SESSION['csrf_token'] ?? ''); ?>">
                <input type="hidden" name="active_tab" class="active-tab-input" value="<?php echo htmlspecialchars($activeTab); ?>">
                <input type="hidden" name="action" value="delete">
                <input type="hidden" name="id" id="deleteMaterialId">

                <div class="modal-header">
                    <h5 class="modal-title"><i class="fas fa-trash me-2"></i>تأكيد حذف المادة التعليمية</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body text-center">
                    <div class="mb-3 text-danger">
                        <i class="fas fa-exclamation-triangle" style="font-size: 3.5rem;"></i>
                    </div>
                    <p class="fs-5 mb-2">هل أنت تأكد من رغبتك في حذف المادة الدراسية؟</p>
                    <p class="fw-bold text-primary fs-6" id="deleteMaterialName"></p>
                    <div class="alert alert-warning text-start mb-0">
                        <i class="fas fa-info-circle me-2"></i>سيتم حذف سجل المادة بشكل نهائي من قاعدة البيانات وحذف ملف الـ PDF المرفق من السيرفر.
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">
                        <i class="fas fa-times me-1"></i>إلغاء
                    </button>
                    <button type="submit" class="btn btn-danger">
                        <i class="fas fa-trash me-1"></i>تأكيد الحذف
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- Modal: Column Visibility Settings -->
<div class="modal fade" id="tableSettingsModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog">
        <div class="modal-content admin-modal admin-modal-premium admin-modal-view">
            <div class="modal-header">
                <h5 class="modal-title"><i class="fas fa-cog me-2"></i>إعدادات أعمدة الجدول</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body">
                <?php if ($showStageCol): ?>
                    <div class="form-check mb-2"><input class="form-check-input" type="checkbox" id="col_stage" checked><label class="form-check-label" for="col_stage">المرحلة</label></div>
                <?php endif; ?>
                <?php if ($showGradeCol): ?>
                    <div class="form-check mb-2"><input class="form-check-input" type="checkbox" id="col_grade" checked><label class="form-check-label" for="col_grade">الصف</label></div>
                <?php endif; ?>
                <div class="form-check mb-2"><input class="form-check-input" type="checkbox" id="col_term" checked><label class="form-check-label" for="col_term">الفصل الدراسي</label></div>
                <div class="form-check mb-2"><input class="form-check-input" type="checkbox" id="col_file" checked><label class="form-check-label" for="col_file">اسم الملف الاصلي</label></div>
                <div class="form-check mb-2"><input class="form-check-input" type="checkbox" id="col_size" checked><label class="form-check-label" for="col_size">الحجم</label></div>
                <div class="form-check mb-2"><input class="form-check-input" type="checkbox" id="col_status" checked><label class="form-check-label" for="col_status">العرض للطلاب</label></div>
                <div class="form-check mb-2"><input class="form-check-input" type="checkbox" id="col_downloadable" checked><label class="form-check-label" for="col_downloadable">التحميل</label></div>
                <div class="form-check mb-2"><input class="form-check-input" type="checkbox" id="col_actions" checked><label class="form-check-label" for="col_actions">الإجراءات</label></div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">
                    <i class="fas fa-check me-1"></i>إغلاق
                </button>
            </div>
        </div>
    </div>
</div>

<script src="../assets/js/admin_table_actions.js"></script>
<script>
var copySupervisorLinkTimer = null;

function resetCopySupervisorBtn() {
    if (copySupervisorLinkTimer) {
        clearTimeout(copySupervisorLinkTimer);
        copySupervisorLinkTimer = null;
    }
    var copyBtn = document.querySelector('button[onclick*="copySupervisorLink"]');
    if (copyBtn) {
        copyBtn.innerHTML = '<i class="fas fa-copy me-1.5"></i>نسخ الرابط';
        copyBtn.className = 'btn btn-sm btn-primary rounded-pill px-3 shadow-sm text-nowrap';
    }
}

function updateSupervisorCardLink() {
    var gradeSelect = document.getElementById("cardGradeSelect");
    var termSelect = document.getElementById("cardTermSelect");
    var grade = gradeSelect ? gradeSelect.value : 'all';
    var term = termSelect ? termSelect.value : 'term1';
    var externalBaseUrl = <?php echo json_encode($supervisorPreviewBaseUrl, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE); ?>;
    var internalBaseUrl = new URL('../student/materials/supervisor_preview.php', window.location.href).href;
    var selectedMode = document.querySelector('input[name="supervisor_link_mode"]:checked');
    var baseUrl = selectedMode && selectedMode.value === 'external' ? externalBaseUrl : internalBaseUrl;
    var absoluteUrl = baseUrl + '?grade=' + encodeURIComponent(grade) + '&scope=' + encodeURIComponent(grade) + '&term=' + encodeURIComponent(term);

    var input = document.getElementById("supervisorLinkInput");
    var prevUrl = input ? input.value : '';
    if (input) input.value = absoluteUrl;

    var openBtn = document.getElementById("supervisorOpenBtn");
    if (openBtn) openBtn.href = absoluteUrl;

    if (prevUrl && prevUrl !== absoluteUrl) {
        resetCopySupervisorBtn();
    }
}

function copySupervisorLink(btn) {
    var input = document.getElementById("supervisorLinkInput");
    var fullUrl = input ? input.value : '';
    var targetBtn = btn || (window.event ? window.event.currentTarget : null) || document.querySelector('button[onclick*="copySupervisorLink"]');

    var showSuccessState = function() {
        if (targetBtn) {
            targetBtn.innerHTML = '<i class="fas fa-check me-1.5"></i>تم النسخ!';
            targetBtn.className = 'btn btn-sm btn-success rounded-pill px-3 shadow-sm text-nowrap';

            if (copySupervisorLinkTimer) clearTimeout(copySupervisorLinkTimer);
            copySupervisorLinkTimer = setTimeout(function() {
                resetCopySupervisorBtn();
            }, 2000);
        }
    };

    if (navigator.clipboard && navigator.clipboard.writeText) {
        navigator.clipboard.writeText(fullUrl).then(showSuccessState).catch(function() {
            if (input) {
                input.select();
                document.execCommand('copy');
                showSuccessState();
            }
        });
    } else if (input) {
        input.select();
        document.execCommand('copy');
        showSuccessState();
    }
}

document.addEventListener('DOMContentLoaded', function () {
    var savedLinkMode = localStorage.getItem('educore_materials_link_mode');
    var savedLinkModeInput = document.querySelector('input[name="supervisor_link_mode"][value="' + savedLinkMode + '"]');
    if (savedLinkModeInput) {
        savedLinkModeInput.checked = true;
    }
    document.querySelectorAll('input[name="supervisor_link_mode"]').forEach(function (input) {
        input.addEventListener('change', function () {
            localStorage.setItem('educore_materials_link_mode', this.value);
            updateSupervisorCardLink();
        });
    });
    updateSupervisorCardLink();
    // 1. Tooltips
    var tooltipTriggerList = [].slice.call(document.querySelectorAll('[data-bs-toggle="tooltip"]'));
    tooltipTriggerList.map(function (tooltipTriggerEl) {
        return new bootstrap.Tooltip(tooltipTriggerEl);
    });

    // 2. DataTables & Real-Time Filter Setup
    var materialsDataTable = null;
    if (typeof $.fn.DataTable !== 'undefined' && $('#materialsTable tbody tr').length > 0 && !$('#materialsTable tbody tr td').hasClass('text-muted')) {
        var actionColIndex = <?php echo $actionColIndex; ?>;

        // Custom DataTables filter for instant real-time multi-select filtering
        $.fn.dataTable.ext.search.push(function(settings, data, dataIndex) {
            if (!settings.nTable || settings.nTable.id !== 'materialsTable') return true;

            var rowNode = settings.aoData[dataIndex].nTr;
            if (!rowNode) return true;

            var rowStage = rowNode.getAttribute('data-stage');
            var rowGrade = rowNode.getAttribute('data-grade');
            var rowTerm = rowNode.getAttribute('data-term');
            var rowStatus = rowNode.getAttribute('data-status');

            var checkedStages = Array.from(document.querySelectorAll('.stage-checkbox:checked')).map(function(cb) { return cb.value; });
            var checkedGrades = Array.from(document.querySelectorAll('.grade-checkbox:checked')).map(function(cb) { return cb.value; });
            var checkedTerms = Array.from(document.querySelectorAll('.term-checkbox:checked')).map(function(cb) { return cb.value; });
            var checkedStatuses = Array.from(document.querySelectorAll('.status-checkbox:checked')).map(function(cb) { return cb.value; });

            if (checkedStages.length > 0 && checkedStages.indexOf(rowStage) === -1) return false;
            if (checkedGrades.length > 0 && checkedGrades.indexOf(rowGrade) === -1) return false;
            if (checkedTerms.length > 0 && checkedTerms.indexOf(rowTerm) === -1) return false;
            if (checkedStatuses.length > 0 && checkedStatuses.indexOf(rowStatus) === -1) return false;

            return true;
        });

        materialsDataTable = $('#materialsTable').DataTable({
            language: {
                url: '//cdn.datatables.net/plug-ins/1.13.7/i18n/ar.json'
            },
            pageLength: 50,
            order: [],
            columnDefs: [{ orderable: false, targets: [actionColIndex] }]
        });
    }

    // ===== Multi-Select Filter Cascade & Auto-Update =====
    function updateFilterDropdownLabels() {
        // 1. Stage Dropdown Label
        var checkedStages = document.querySelectorAll('.stage-checkbox:checked');
        var stageLabel = document.getElementById('selectedStagesLabel');
        var stageBtn = document.getElementById('stageDropdown');
        if (stageLabel) {
            var totalStages = document.querySelectorAll('.stage-checkbox').length;
            if (checkedStages.length === 0 || checkedStages.length === totalStages) {
                stageLabel.textContent = 'الكل';
            } else if (checkedStages.length <= 2) {
                var names = [];
                checkedStages.forEach(function(cb) {
                    names.push(cb.nextElementSibling.textContent.trim());
                });
                stageLabel.textContent = names.join('، ');
            } else {
                stageLabel.textContent = checkedStages.length + ' محددة';
            }
        }
        if (stageBtn) {
            stageBtn.classList.toggle('active-filter', checkedStages.length > 0);
        }

        // 2. Grade Dropdown Label & Cascade
        var checkedStageVals = Array.from(checkedStages).map(function(cb) { return cb.value; });
        document.querySelectorAll('.grade-item').forEach(function(item) {
            var itemStage = item.getAttribute('data-stage');
            if (checkedStageVals.length === 0 || checkedStageVals.indexOf(itemStage) !== -1) {
                item.style.display = 'block';
            } else {
                item.style.display = 'none';
                var cb = item.querySelector('.grade-checkbox');
                if (cb) cb.checked = false;
            }
        });

        var checkedGrades = document.querySelectorAll('.grade-checkbox:checked');
        var gradeLabel = document.getElementById('selectedGradesLabel');
        var gradeBtn = document.getElementById('gradeDropdown');
        if (gradeLabel) {
            var visibleGradesCount = document.querySelectorAll('.grade-item:not([style*="display: none"])').length || document.querySelectorAll('.grade-checkbox').length;
            if (checkedGrades.length === 0 || checkedGrades.length === visibleGradesCount) {
                gradeLabel.textContent = 'الكل';
            } else if (checkedGrades.length <= 2) {
                var names = [];
                checkedGrades.forEach(function(cb) {
                    names.push(cb.nextElementSibling.textContent.trim());
                });
                gradeLabel.textContent = names.join('، ');
            } else {
                gradeLabel.textContent = checkedGrades.length + ' محددة';
            }
        }
        if (gradeBtn) {
            gradeBtn.classList.toggle('active-filter', checkedGrades.length > 0);
        }

        // 3. Term Dropdown Label
        var checkedTerms = document.querySelectorAll('.term-checkbox:checked');
        var termLabel = document.getElementById('selectedTermsLabel');
        var termBtn = document.getElementById('termDropdown');
        if (termLabel) {
            var totalTerms = document.querySelectorAll('.term-checkbox').length;
            if (checkedTerms.length === 0 || checkedTerms.length === totalTerms) {
                termLabel.textContent = 'الكل';
            } else if (checkedTerms.length === 1) {
                termLabel.textContent = checkedTerms[0].nextElementSibling.textContent.trim();
            } else {
                termLabel.textContent = checkedTerms.length + ' محددة';
            }
        }
        if (termBtn) {
            termBtn.classList.toggle('active-filter', checkedTerms.length > 0);
        }

        // 4. Status Dropdown Label
        var checkedStatuses = document.querySelectorAll('.status-checkbox:checked');
        var statusLabel = document.getElementById('selectedStatusesLabel');
        var statusBtn = document.getElementById('statusDropdown');
        if (statusLabel) {
            var totalStatuses = document.querySelectorAll('.status-checkbox').length;
            if (checkedStatuses.length === 0 || checkedStatuses.length === totalStatuses) {
                statusLabel.textContent = 'الكل';
            } else if (checkedStatuses.length === 1) {
                statusLabel.textContent = checkedStatuses[0].nextElementSibling.textContent.trim();
            } else {
                statusLabel.textContent = checkedStatuses.length + ' محددة';
            }
        }
        if (statusBtn) {
            statusBtn.classList.toggle('active-filter', checkedStatuses.length > 0);
        }
    }

    // Initialize labels & initial DataTables draw
    updateFilterDropdownLabels();
    if (materialsDataTable) {
        materialsDataTable.draw();
    }

    // Real-time instant DataTable filter on checkbox change (NO page reload -> Dropdown stays OPEN!)
    document.querySelectorAll('.stage-checkbox, .grade-checkbox, .term-checkbox, .status-checkbox').forEach(function(cb) {
        cb.addEventListener('change', function() {
            updateFilterDropdownLabels();
            if (materialsDataTable) {
                materialsDataTable.draw();
            }
            var form = document.getElementById('filterForm');
            if (form && window.history && window.history.replaceState) {
                var formData = new FormData(form);
                var params = new URLSearchParams(formData);
                var newUrl = window.location.pathname + '?' + params.toString();
                window.history.replaceState(null, '', newUrl);
            }
        });
    });

    // Bulk action cards interactivity
    var actionCards = document.querySelectorAll('.bulk-action-card');
    actionCards.forEach(function (card) {
        card.addEventListener('click', function () {
            actionCards.forEach(function (c) { c.classList.remove('active-selected'); });
            card.classList.add('active-selected');
            var radio = card.querySelector('input[type="radio"]');
            if (radio) radio.checked = true;
        });
    });

    // 3. Cascading Filters (Inline Header Search Form)
    var filterStage = document.getElementById('filterStage');
    var filterGrade = document.getElementById('filterGrade');
    if (filterStage && filterGrade) {
        filterStage.addEventListener('change', function () {
            var stageId = this.value;
            filterGrade.value = '';
            filterGrade.querySelectorAll('option[data-stage]').forEach(function (opt) {
                opt.style.display = (!stageId || opt.getAttribute('data-stage') === stageId) ? '' : 'none';
            });
        });
    }

    // 4. Cascading Filters (Bulk Modal Form)
    var bulkStage = document.getElementById('bulkStageSelect');
    var bulkGrade = document.getElementById('bulkGradeSelect');
    if (bulkStage && bulkGrade) {
        bulkStage.addEventListener('change', function () {
            var stageId = this.value;
            bulkGrade.value = '';
            bulkGrade.querySelectorAll('option[data-stage]').forEach(function (opt) {
                opt.style.display = (!stageId || opt.getAttribute('data-stage') === stageId) ? '' : 'none';
            });
        });
    }

    // 5. Cascading Filters (Add/Edit Modal Form)
    var modalStage = document.getElementById('modalStage');
    var modalGrade = document.getElementById('modalGrade');
    if (modalStage && modalGrade) {
        modalStage.addEventListener('change', function () {
            var stageId = this.value;
            modalGrade.querySelectorAll('option[data-stage]').forEach(function (opt) {
                var matches = (!stageId || opt.getAttribute('data-stage') === stageId);
                opt.style.display = matches ? '' : 'none';
            });
            var selectedOpt = modalGrade.options[modalGrade.selectedIndex];
            if (selectedOpt && selectedOpt.style.display === 'none') {
                modalGrade.value = '';
            }
        });
    }

    // 6. Add Material Modal Reset
    var addModalEl = document.getElementById('addMaterialModal');
    if (addModalEl) {
        addModalEl.addEventListener('show.bs.modal', function (e) {
            if (e.relatedTarget && e.relatedTarget.getAttribute('data-bs-target') === '#addMaterialModal') {
                document.getElementById('modalAction').value = 'add';
                document.getElementById('modalMaterialId').value = '';
                document.getElementById('modalTitle').innerHTML = '<i class="fas fa-plus-circle me-2"></i>رفع مادة تعليمية جديدة';
                document.getElementById('materialModalContent').classList.remove('admin-modal-edit');
                document.getElementById('materialModalContent').classList.add('admin-modal-create');
                document.getElementById('modalSubmitBtn').className = 'btn btn-success';
                document.getElementById('modalSubmitBtn').innerHTML = '<i class="fas fa-save me-1"></i>حفظ ورفع';
                document.getElementById('fileRequiredStar').style.display = 'inline';
                document.getElementById('modalFile').required = true;
                document.getElementById('materialForm').reset();
            }
        });
    }

    // 7. Event Delegation for Action Buttons
    document.body.addEventListener('click', function (e) {
        var editBtn = e.target.closest('.edit-material-btn');
        if (editBtn) {
            document.getElementById('modalAction').value = 'edit';
            document.getElementById('modalMaterialId').value = editBtn.getAttribute('data-id');
            document.getElementById('modalTitle').innerHTML = '<i class="fas fa-edit me-2"></i>تعديل المادة التعليمية';
            document.getElementById('materialModalContent').classList.remove('admin-modal-create');
            document.getElementById('materialModalContent').classList.add('admin-modal-edit');
            document.getElementById('modalSubmitBtn').className = 'btn btn-primary';
            document.getElementById('modalSubmitBtn').innerHTML = '<i class="fas fa-save me-1"></i>حفظ التعديلات';
            document.getElementById('fileRequiredStar').style.display = 'none';
            document.getElementById('modalFile').required = false;

            var stageId = editBtn.getAttribute('data-stage');
            document.getElementById('modalStage').value = stageId;

            if (modalStage) {
                modalStage.dispatchEvent(new Event('change'));
            }
            document.getElementById('modalGrade').value = editBtn.getAttribute('data-grade');
            document.getElementById('modalTerm').value = editBtn.getAttribute('data-term');
            document.getElementById('modalSubject').value = editBtn.getAttribute('data-subject');
            document.getElementById('modalEnabled').checked = (editBtn.getAttribute('data-enabled') === '1');
            document.getElementById('modalDownloadable').checked = (editBtn.getAttribute('data-downloadable') === '1');
        }

        var deleteBtn = e.target.closest('.delete-material-btn');
        if (deleteBtn) {
            document.getElementById('deleteMaterialId').value = deleteBtn.getAttribute('data-id');
            document.getElementById('deleteMaterialName').textContent = deleteBtn.getAttribute('data-name');
        }

        var toggleBtn = e.target.closest('.toggle-material-btn');
        if (toggleBtn) {
            var id = toggleBtn.getAttribute('data-id');
            var name = toggleBtn.getAttribute('data-name');
            var status = toggleBtn.getAttribute('data-status');
            var stageName = toggleBtn.getAttribute('data-stage-name') || '';
            var gradeName = toggleBtn.getAttribute('data-grade-name') || '';
            var termName = toggleBtn.getAttribute('data-term-name') || '';

            document.getElementById('toggleMaterialId').value = id;
            document.getElementById('toggleMaterialName').textContent = name;

            var metaHtml = '<div class="d-flex align-items-center justify-content-center gap-2 flex-wrap mt-2.5">' +
                (stageName ? '<span class="badge bg-white text-dark border fw-normal fs-6 shadow-2xs" style="padding: 0.5rem 0.9rem !important;"><i class="fas fa-school text-muted" style="margin-left: 8px !important;"></i>' + stageName + '</span>' : '') +
                (gradeName ? '<span class="badge bg-white text-dark border fw-normal fs-6 shadow-2xs" style="padding: 0.5rem 0.9rem !important;"><i class="fas fa-graduation-cap text-muted" style="margin-left: 8px !important;"></i>' + gradeName + '</span>' : '') +
                (termName ? '<span class="badge bg-white text-dark border fw-normal fs-6 shadow-2xs" style="padding: 0.5rem 0.9rem !important;"><i class="fas fa-calendar-alt text-muted" style="margin-left: 8px !important;"></i>' + termName + '</span>' : '') +
                '</div>';
            document.getElementById('toggleMaterialMeta').innerHTML = metaHtml;

            var modalContent = document.getElementById('toggleMaterialModalContent');
            var icon = document.getElementById('toggleModalIcon');
            var text = document.getElementById('toggleModalText');
            var submitBtn = document.getElementById('toggleModalSubmitBtn');

            if (status === '1') {
                modalContent.classList.remove('admin-modal-create');
                modalContent.classList.add('admin-modal-warning');
                icon.innerHTML = '<i class="fas fa-eye-slash text-warning" style="font-size: 3.5rem;"></i>';
                text.textContent = 'هل أنت تأكد من رغبتك في إخفاء هذه المادة عن الطلاب؟';
                submitBtn.className = 'btn btn-warning';
                submitBtn.innerHTML = '<i class="fas fa-ban me-1"></i>إخفاء المادة';
            } else {
                modalContent.classList.remove('admin-modal-warning');
                modalContent.classList.add('admin-modal-create');
                icon.innerHTML = '<i class="fas fa-check-circle text-success" style="font-size: 3.5rem;"></i>';
                text.textContent = 'هل أنت تأكد من رغبتك في تفعيل وإظهار هذه المادة للطلاب؟';
                submitBtn.className = 'btn btn-success';
                submitBtn.innerHTML = '<i class="fas fa-check me-1"></i>تفعيل وإظهار';
            }
        }

        var toggleDlBtn = e.target.closest('.toggle-downloadable-btn');
        if (toggleDlBtn) {
            var id = toggleDlBtn.getAttribute('data-id');
            var name = toggleDlBtn.getAttribute('data-name');
            var downloadable = toggleDlBtn.getAttribute('data-downloadable');
            var stageName = toggleDlBtn.getAttribute('data-stage-name') || '';
            var gradeName = toggleDlBtn.getAttribute('data-grade-name') || '';
            var termName = toggleDlBtn.getAttribute('data-term-name') || '';

            document.getElementById('toggleDownloadableMaterialId').value = id;
            document.getElementById('toggleDownloadableMaterialName').textContent = name;

            var metaHtmlDl = '<div class="d-flex align-items-center justify-content-center gap-2 flex-wrap mt-2.5">' +
                (stageName ? '<span class="badge bg-white text-dark border fw-normal fs-6 shadow-2xs" style="padding: 0.5rem 0.9rem !important;"><i class="fas fa-school text-muted" style="margin-left: 8px !important;"></i>' + stageName + '</span>' : '') +
                (gradeName ? '<span class="badge bg-white text-dark border fw-normal fs-6 shadow-2xs" style="padding: 0.5rem 0.9rem !important;"><i class="fas fa-graduation-cap text-muted" style="margin-left: 8px !important;"></i>' + gradeName + '</span>' : '') +
                (termName ? '<span class="badge bg-white text-dark border fw-normal fs-6 shadow-2xs" style="padding: 0.5rem 0.9rem !important;"><i class="fas fa-calendar-alt text-muted" style="margin-left: 8px !important;"></i>' + termName + '</span>' : '') +
                '</div>';
            document.getElementById('toggleDownloadableMaterialMeta').innerHTML = metaHtmlDl;

            var modalContent = document.getElementById('toggleDownloadableModalContent');
            var icon = document.getElementById('toggleDownloadableModalIcon');
            var text = document.getElementById('toggleDownloadableModalText');
            var submitBtn = document.getElementById('toggleDownloadableModalSubmitBtn');

            if (downloadable === '1') {
                modalContent.classList.remove('admin-modal-create');
                modalContent.classList.add('admin-modal-warning');
                icon.innerHTML = '<i class="fas fa-clock text-warning" style="font-size: 3.5rem;"></i>';
                text.textContent = 'هل أنت تأكد من تغيير حالة المادة إلى (قريباً) وتجميد التحميل للطلاب؟';
                submitBtn.className = 'btn btn-warning';
                submitBtn.innerHTML = '<i class="fas fa-clock me-1"></i>تعيين كـ قريباً';
            } else {
                modalContent.classList.remove('admin-modal-warning');
                modalContent.classList.add('admin-modal-create');
                icon.innerHTML = '<i class="fas fa-file-download text-success" style="font-size: 3.5rem;"></i>';
                text.textContent = 'هل أنت تأكد من إتاحة تحميل ملف هذه المادة للطلاب؟';
                submitBtn.className = 'btn btn-success';
                submitBtn.innerHTML = '<i class="fas fa-download me-1"></i>إتاحة التحميل للطلاب';
            }
        }

        var quickUploadBtn = e.target.closest('.quick-upload-trigger-btn');
        if (quickUploadBtn) {
            var id = quickUploadBtn.getAttribute('data-id');
            document.getElementById('quickUploadId').value = id;
            document.getElementById('quickUploadFile').click();
        }
    });

    var quickUploadFile = document.getElementById('quickUploadFile');
    if (quickUploadFile) {
        quickUploadFile.addEventListener('change', function () {
            if (this.files && this.files[0]) {
                var file = this.files[0];
                var name = file.name;
                var size = file.size;
                var ext = name.split('.').pop().toLowerCase();

                if (ext !== 'pdf') {
                    alert("صيغة الملف غير مدعومة. يسمح فقط بملفات PDF.");
                    this.value = '';
                    return;
                }

                if (size > 50 * 1024 * 1024) {
                    alert("حجم الملف يتجاوز الحد الأقصى المسموح به (50 ميجابايت).");
                    this.value = '';
                    return;
                }

                // Submit form
                document.getElementById('quickUploadForm').submit();
            }
        });
    }

    // 8. Table Column Settings Initialization
    if (typeof initializeTableColumnSettings === 'function') {
        var colMap = { col_num: 0, col_subject: 1 };
        var cIdx = 2;
        <?php if ($showStageCol): ?>colMap.col_stage = cIdx++;<?php endif; ?>
        <?php if ($showGradeCol): ?>colMap.col_grade = cIdx++;<?php endif; ?>
        colMap.col_term = cIdx++;
        colMap.col_file = cIdx++;
        colMap.col_size = cIdx++;
        colMap.col_status = cIdx++;
        colMap.col_downloadable = cIdx++;
        colMap.col_actions = cIdx++;

        initializeTableColumnSettings('materialsTable', colMap, 'materials_center_columns_<?php echo $tabStageId . '_' . $filter_grade; ?>');
    }
});
</script>

<!-- Hidden form for quick file upload -->
<form id="quickUploadForm" method="POST" action="materials_center.php" enctype="multipart/form-data" style="display:none;">
    <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($_SESSION['csrf_token'] ?? ''); ?>">
    <input type="hidden" name="active_tab" class="active-tab-input" value="<?php echo htmlspecialchars($activeTab); ?>">
    <input type="hidden" name="action" value="quick_upload">
    <input type="hidden" name="id" id="quickUploadId" value="">
    <input type="file" name="material_file" id="quickUploadFile" accept=".pdf">
</form>

<?php require_once '../includes/admin_footer.php'; ?>
