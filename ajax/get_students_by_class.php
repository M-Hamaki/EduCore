<?php
// Get students by class AJAX handler
// Include database and necessary classes
require_once '../config/database.php';
require_once '../classes/user.php';
require_once '../classes/utilities.php';
require_once '../includes/session_config.php';
require_once '../includes/http_helpers.php';

// Start session if not already started
requireJsonUser(['admin', 'teacher']);

// Initialize database connection
$database = new Database();
$db = $database->getConnection();

// Initialize user object
$user = new User($db);

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['class_id'])) {
    requireCsrfToken();
    $class_id = intval($_POST['class_id']);
    if ($class_id <= 0) {
        jsonError('معرف الفصل غير صالح', 422);
    }
    
    // Non-admin callers are teachers and remain constrained to their assigned classes.
    if ($_SESSION['role'] !== 'admin') {
        $specialist_id = $_SESSION['user_id'];
        $assigned_classes = $user->getAssignedClasses($specialist_id);
        $allowed_class_ids = array_map('intval', array_column($assigned_classes, 'id'));
        
        if (!in_array($class_id, $allowed_class_ids, true)) {
            jsonError('غير مسموح لك بالوصول لهذا الفصل', 403);
        }
    }
    
    try {
        $students = $user->readStudentsByClass($class_id);
        jsonSuccess(['students' => $students]);
    } catch (Throwable $e) {
        handleJsonException($e, 'حدث خطأ في تحميل الطلاب');
    }
}
jsonError('طريقة أو بيانات الطلب غير صالحة', 405);
