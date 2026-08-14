<?php
/**
 * حفظ تعديلات الخرائط الذهنية
 * Save mind map edits to database via AJAX
 */

ini_set('display_errors', 0);
error_reporting(E_ALL);
header('Content-Type: application/json; charset=utf-8');

require_once '../../includes/session_config.php';
require_once '../../config/database.php';
require_once '../../includes/http_helpers.php';
require_once '../../src/Modules/Operations/Audit/AuditService.php';

// التحقق من تسجيل الدخول
if (!isset($_SESSION['user_id']) || !in_array($_SESSION['role'], ['teacher', 'external_teacher'])) {
    echo json_encode(['success' => false, 'message' => 'غير مصرح لك بالوصول']);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo json_encode(['success' => false, 'message' => 'طريقة طلب غير صالحة']);
    exit;
}
requireCsrfToken();

try {
    // Read JSON body
    $input = json_decode(file_get_contents('php://input'), true);
    
    if (!$input) {
        echo json_encode(['success' => false, 'message' => 'بيانات غير صالحة']);
        exit;
    }

    $lessonId = isset($input['lesson_id']) ? intval($input['lesson_id']) : 0;
    $mindMaps = isset($input['mind_maps']) ? $input['mind_maps'] : null;

    if (!$lessonId || !$mindMaps) {
        echo json_encode(['success' => false, 'message' => 'معرف الدرس أو بيانات الخرائط مفقودة']);
        exit;
    }

    $database = new Database();
    $db = $database->getConnection();
    $teacherId = $_SESSION['user_id'];

    $db->beginTransaction();
    // التحقق من ملكية الدرس
    $stmt = $db->prepare("SELECT id, title, mind_maps FROM ai_lessons WHERE id = ? AND teacher_id = ? FOR UPDATE");
    $stmt->execute([$lessonId, $teacherId]);
    $lesson = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$lesson) {
        $db->rollBack();
        echo json_encode(['success' => false, 'message' => 'الدرس غير موجود أو لا تملك صلاحية التعديل']);
        exit;
    }

    // حفظ البيانات المحدثة
    $mindMapsJson = json_encode($mindMaps, JSON_UNESCAPED_UNICODE);
    
    if ($mindMapsJson === false) {
        $db->rollBack();
        echo json_encode(['success' => false, 'message' => 'خطأ في تحويل البيانات إلى JSON']);
        exit;
    }

    $stmt = $db->prepare("UPDATE ai_lessons SET mind_maps = ? WHERE id = ? AND teacher_id = ?");
    $result = $stmt->execute([$mindMapsJson, $lessonId, $teacherId]);

    if ($result) {
        (new \EduCore\Modules\Operations\Audit\AuditService($db))->recordEvent(
            'update', 'ai_lesson_section', $lessonId, (string)$lesson['title'],
            [
                'section_type' => 'mind_maps',
                'before_fingerprint' => hash('sha256', (string)($lesson['mind_maps'] ?? '')),
                'after_fingerprint' => hash('sha256', $mindMapsJson),
                'before_bytes' => strlen((string)($lesson['mind_maps'] ?? '')),
                'after_bytes' => strlen($mindMapsJson),
                'undo_policy' => 'lesson_content_restore_not_enabled',
            ]
        );
        $db->commit();
        echo json_encode(['success' => true, 'message' => 'تم حفظ التعديلات بنجاح']);
    } else {
        throw new RuntimeException('Mind map update failed.');
    }

} catch (Throwable $e) {
    if (isset($db) && $db instanceof PDO && $db->inTransaction()) {
        $db->rollBack();
    }
    error_log("Save mindmap error: " . $e->getMessage());
    echo json_encode(['success' => false, 'message' => 'حدث خطأ في الخادم']);
}
