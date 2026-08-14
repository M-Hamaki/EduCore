<?php
// Secure session start
if (session_status() == PHP_SESSION_NONE) { session_start(); }

// Helper function for UTF-8 friendly JSON responses
function sendJsonResponse($data, $statusCode = 200) {
    header('Content-Type: application/json; charset=utf-8');
    http_response_code($statusCode);
    echo json_encode($data, JSON_UNESCAPED_UNICODE);
    exit;
}

// Basic auth gate
if (!isset($_SESSION['user_id'], $_SESSION['role'])) {
    sendJsonResponse(['success'=>false,'message'=>'غير مصرح'], 401);
}
// Ensure CSRF token present
if (empty($_SESSION['csrf_token'])) { $_SESSION['csrf_token']=bin2hex(random_bytes(32)); }

// Include database and necessary classes
// Get the correct path based on where this file is called from
$base_path = dirname(__DIR__) . '/';
require_once $base_path . 'config/database.php';
require_once $base_path . 'classes/user.php';
require_once $base_path . 'classes/classroom.php';
require_once $base_path . 'classes/evaluation.php';
require_once $base_path . 'classes/evaluation_type.php';
require_once $base_path . 'classes/utilities.php';
require_once $base_path . 'classes/AcademicYear.php';
require_once $base_path . 'classes/ScopedStaffPortalContext.php';
require_once $base_path . 'src/Modules/Search/Application/GlobalSearchAccessPolicy.php';

// Initialize database connection
$database = new Database();
$db = $database->getConnection();
$currentAcademicYearId = AcademicYear::currentId($db);
$staffPortalContext = new ScopedStaffPortalContext($db, $currentAcademicYearId);

// Check database connection
if (!$db) {
    sendJsonResponse(['success' => false, 'message' => 'فشل الاتصال بقاعدة البيانات']);
}

// Debug logs (disabled in production)
if (defined('DEVELOPMENT_MODE') && DEVELOPMENT_MODE) {
    error_log("AJAX Request - Method: " . ($_SERVER['REQUEST_METHOD'] ?? 'UNKNOWN'));
    error_log("AJAX Request - POST data: " . print_r($_POST, true));
    error_log("AJAX Request - GET data: " . print_r($_GET, true));
}

// CSRF check for state-changing POST requests
if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
    $csrf = $_POST['csrf_token'] ?? $_SERVER['HTTP_X_CSRF_TOKEN'] ?? '';
    if (empty($csrf) || !isset($_SESSION['csrf_token']) || !hash_equals($_SESSION['csrf_token'], $csrf)) {
        header('Content-Type: application/json');
        http_response_code(403);
        echo json_encode(['success'=>false,'message'=>'رمز الحماية غير صالح - يرجى تحديث الصفحة']);
        exit;
    }
}

// Pass an explicit, read-only request context to internal handler fragments.
$requestGet = $_GET;
$requestPost = $_POST;
$requestFiles = $_FILES;
$requestSession = $_SESSION;

// Determine if it's a GET or POST request
$request_method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
$action = '';

if ($request_method === 'POST' && isset($_POST['action'])) {
    $action = $_POST['action'];
    if (defined('DEVELOPMENT_MODE') && DEVELOPMENT_MODE) {
        error_log("AJAX Action from POST: " . $action);
    }
} elseif (isset($_GET['action'])) {
    $action = $_GET['action'];
    if (defined('DEVELOPMENT_MODE') && DEVELOPMENT_MODE) {
        error_log("AJAX Action from GET: " . $action);
    }
}

$postOnlyActions = ['link_kinship'];
if (in_array($action, $postOnlyActions, true) && $request_method !== 'POST') {
    sendJsonResponse(['success' => false, 'message' => 'طريقة الطلب غير صالحة'], 405);
}

// Handle different AJAX actions based on the action parameter
if (!empty($action)) {
    if (defined('DEVELOPMENT_MODE') && DEVELOPMENT_MODE) {
        error_log("Processing action: " . $action);
    }
    
    // Role permissions map - use effective role for supervisor mode support
    // Normalize customizable administrative roles to their capability family.
    // The concrete active role remains available through assignedRole() for
    // role-specific annual academic scope checks.
    $role = $staffPortalContext->role();
    $assignedRole = $staffPortalContext->assignedRole();
    $permissions = [
    'get_students_by_class' => ['admin','teacher','specialist'],
    'get_teachers_by_class' => ['admin','specialist'],
    'get_all_students' => ['admin','teacher','specialist'],
    'get_all_teachers' => ['admin','specialist'],
    'get_specialist_students' => [], // Legacy specialist endpoint disabled after scoped portal cutover.
    'get_evaluation_types' => ['admin','teacher','specialist'],
    'get_classrooms' => ['admin','teacher'],
    'get_student' => ['admin','teacher'],
    'get_teacher' => ['admin'],
    'get_student_class' => ['admin','teacher','student'],
    'add_evaluation' => ['admin','teacher','specialist'],
    'get_student_evaluations' => ['admin','teacher','student','specialist'],
    'delete_evaluation' => ['admin','teacher','specialist'],
    'delete_all_evaluations' => ['admin'],
    'delete_all_student_evaluations' => ['admin','specialist'],
    'export_student_evaluations' => ['admin','teacher','specialist'],
    'get_teacher_evaluations_for_admin' => ['admin','specialist'],
    'update_teacher_evaluation' => ['admin','specialist'],
    'adjust_total_points' => ['admin'],
    'delete_evaluation_from_report' => ['admin','specialist'],
    'bulk_delete_evaluations_specialist' => [], // Legacy specialist write endpoint intentionally disabled.
    'bulk_delete_evaluations_admin' => ['admin'], // Bulk delete for admin
    'get_user_services' => ['admin'],
    'save_user_services' => ['admin'],
    'reset_user_services' => ['admin'],
    'admin_reports_datatable' => ['admin','specialist'],
    'specialist_reports_datatable' => [], // Replaced by SpecialistEvaluationReadService.
    'teacher_evaluations_datatable' => ['teacher','admin'],
    'delete_teacher_evaluation' => ['teacher'],
    'find_siblings' => ['admin'],
    'search_students_for_sibling' => ['admin'],
    'find_kinship' => ['admin'],
    'link_kinship' => ['admin']
    ];
    $delegatedAdminActionPages = [
        'admin_reports_datatable' => 'evaluation_reports.php',
        'get_user_services' => 'student_accounts.php',
        'save_user_services' => 'student_accounts.php',
        'reset_user_services' => 'student_accounts.php',
        'find_siblings' => 'students.php',
        'search_students_for_sibling' => 'students.php',
        'find_kinship' => 'students.php',
        'link_kinship' => 'students.php',
    ];
    $hasDelegatedPageGrant = isset($delegatedAdminActionPages[$action])
        && Utilities::roleCanAccessAdminPage($role, $delegatedAdminActionPages[$action]);
    $globalSearchCapabilities = null;
    if ($action === 'global_deep_search') {
        $globalSearchAllowedPages = Utilities::getAllowedAdminPagesForRole($assignedRole);
        $globalSearchPolicy = new \EduCore\Modules\Search\Application\GlobalSearchAccessPolicy();
        if (!$globalSearchPolicy->canUse($assignedRole, $globalSearchAllowedPages)) {
            sendJsonResponse(['success' => false, 'message' => 'صلاحيات غير كافية'], 403);
        }
        $globalSearchCapabilities = $globalSearchPolicy->capabilities(
            $assignedRole,
            $globalSearchAllowedPages
        );
    }
    if (isset($permissions[$action])
        && !in_array($role, $permissions[$action], true)
        && !$hasDelegatedPageGrant) {
        header('Content-Type: application/json');
        http_response_code(403);
        echo json_encode(['success'=>false,'message'=>'صلاحيات غير كافية']);
        exit;
    }

    $currentUserId = (int)$_SESSION['user_id'];

    // Specialist requests reuse admin endpoints with server-side annual scope enforcement.
    if ($role === 'specialist') {
        try {
            $requestedStudentId = (int)($requestPost['student_id'] ?? $requestGet['student_id'] ?? 0);
            if ($requestedStudentId > 0) {
                $staffPortalContext->assertStudentAllowed($requestedStudentId);
            }

            $requestedClassId = (int)($requestPost['class_id'] ?? $requestGet['class_id'] ?? 0);
            if ($requestedClassId > 0) {
                $staffPortalContext->assertClassAllowed($requestedClassId);
            }

            $evaluationId = (int)($requestPost['evaluation_id'] ?? $requestGet['evaluation_id'] ?? 0);
            if ($evaluationId > 0) {
                $evaluationScopeStmt = $db->prepare('SELECT student_id, academic_year_id FROM evaluations WHERE id = ? LIMIT 1');
                $evaluationScopeStmt->execute([$evaluationId]);
                $evaluationScopeRow = $evaluationScopeStmt->fetch(PDO::FETCH_ASSOC);
                if (!$evaluationScopeRow) {
                    throw new RuntimeException('التقييم المطلوب غير موجود.');
                }
                $staffPortalContext->assertStudentAllowed((int)$evaluationScopeRow['student_id']);
                $evaluationYearId = (int)($evaluationScopeRow['academic_year_id'] ?? 0);
                if ($evaluationYearId > 0 && $evaluationYearId !== $currentAcademicYearId) {
                    throw new RuntimeException('التقييم المطلوب لا ينتمي إلى العام الدراسي الحالي.');
                }
            }

            if (in_array($action, ['get_teacher_evaluations_for_admin', 'admin_reports_datatable'], true)
                && (int)($requestPost['teacher_id'] ?? $requestGet['teacher_id'] ?? 0) > 0) {
                $teacherId = (int)($requestPost['teacher_id'] ?? $requestGet['teacher_id'] ?? 0);
                $scopeClassIds = $staffPortalContext->allowedClassIds() ?? [];
                if ($teacherId <= 0 || $scopeClassIds === []) {
                    throw new RuntimeException('المعلم المطلوب غير مرتبط بفصولك المسندة.');
                }
                $teacherScopeMarks = implode(',', array_fill(0, count($scopeClassIds), '?'));
                $teacherScopeStmt = $db->prepare("SELECT 1 FROM user_class_access
                    WHERE user_id = ? AND class_id IN ({$teacherScopeMarks}) LIMIT 1");
                $teacherScopeStmt->execute(array_merge([$teacherId], $scopeClassIds));
                if (!$teacherScopeStmt->fetchColumn()) {
                    throw new RuntimeException('المعلم المطلوب غير مرتبط بفصولك المسندة.');
                }
            }
        } catch (RuntimeException $e) {
            sendJsonResponse(['success' => false, 'message' => $e->getMessage()], 403);
        }
    }

    error_log("About to process action: '" . $action . "' (length: " . strlen($action) . ")");
    
    $handlerGroups = [
        'reports' => [
            'admin_reports_datatable',
            'specialist_reports_datatable',
            'teacher_evaluations_datatable',
            'delete_teacher_evaluation',
        ],
        'lookups' => [
            'get_students_by_class',
            'get_teachers_by_class',
            'get_all_students',
            'get_all_teachers',
            'get_specialist_students',
            'get_evaluation_types',
            'get_classrooms',
            'get_student',
            'get_teacher',
            'get_student_class',
            'find_siblings',
            'search_students_for_sibling',
            'find_kinship',
            'link_kinship',
            'global_deep_search',
        ],
        'evaluations' => [
            'add_evaluation',
            'get_student_evaluations',
            'delete_evaluation',
            'get_teacher_evaluations_for_admin',
            'update_teacher_evaluation',
            'delete_all_evaluations',
            'delete_evaluation_from_report',
            'bulk_delete_evaluations_specialist',
            'bulk_delete_evaluations_admin',
            'adjust_total_points',
            'delete_all_student_evaluations',
            'export_student_evaluations',
        ],
        'user_services' => [
            'get_user_services',
            'save_user_services',
            'reset_user_services',
        ],
    ];
    $handlerFile = null;
    foreach ($handlerGroups as $group => $actions) {
        if (in_array($action, $actions, true)) {
            $handlerFile = $base_path . 'classes/Ajax/Handlers/' . $group . '.php';
            break;
        }
    }
    if ($handlerFile !== null) {
        require $handlerFile;
        return;
    }

    if (defined('DEVELOPMENT_MODE') && DEVELOPMENT_MODE) {
        error_log("Invalid action received: " . $action);
    }
    header('Content-Type: application/json');
    echo json_encode(['success' => false, 'message' => 'Invalid action: ' . $action]);
} else {
    if (defined('DEVELOPMENT_MODE') && DEVELOPMENT_MODE) {
        error_log("No action specified in request");
    }
    // No action specified
    header('Content-Type: application/json');
    echo json_encode(['success' => false, 'message' => 'No action specified']);
}
?>
