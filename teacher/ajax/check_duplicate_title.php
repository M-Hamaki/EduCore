<?php
/**
 * التحقق من وجود درس بنفس العنوان - فحص سريع قبل التوليد
 * Check for duplicate lesson title before generation
 */
header('Content-Type: application/json; charset=utf-8');

require_once '../../includes/session_config.php';
require_once '../../includes/csrf.php';
require_once '../../config/database.php';

// التحقق من تسجيل الدخول
if (!isset($_SESSION['user_id']) || !in_array($_SESSION['role'], ['teacher', 'external_teacher'])) {
    echo json_encode(['success' => false, 'message' => 'غير مصرح']);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo json_encode(['success' => false, 'message' => 'طريقة طلب غير صالحة']);
    exit;
}

requireCsrfPost();

$title = isset($_POST['title']) ? trim($_POST['title']) : '';
if (!$title) {
    echo json_encode(['exists' => false]);
    exit;
}

try {
    $database = new Database();
    $db = $database->getConnection();
    $teacherId = $_SESSION['user_id'];

    // إغلاق الجلسة لمنع تعليق الطلبات (Session Locking)
    session_write_close();

    $stmt = $db->prepare("
        SELECT id, title, created_at 
        FROM ai_lessons 
        WHERE teacher_id = ? AND title = ?
        ORDER BY created_at DESC 
        LIMIT 1
    ");
    $stmt->execute([$teacherId, $title]);
    $existing = $stmt->fetch(PDO::FETCH_ASSOC);

    if ($existing) {
        $createdDate = date('Y/m/d - h:i A', strtotime($existing['created_at']));
        echo json_encode([
            'exists' => true,
            'existing_id' => $existing['id'],
            'existing_date' => $createdDate
        ], JSON_UNESCAPED_UNICODE);
    }
    else {
        echo json_encode(['exists' => false]);
    }
}
catch (\Throwable $e) {
    // التقاط Throwable (لا Exception فقط) ليشمل Error/TypeError.
    // إرجاع success:false بدل exists:false لكي يُظهر العميل فشل التحقق بدل افتراض "لا تكرار".
    error_log("check_duplicate_title error: " . $e->getMessage());
    echo json_encode(['success' => false, 'message' => 'حدث خطأ أثناء التحقق من تكرار العنوان.']);
}
