<?php
/**
 * معالج AJAX لتحديث قسم من الدرس (تعديل مباشر)
 * AJAX Handler for inline editing lesson sections
 */

ini_set('display_errors', 0);
error_reporting(E_ALL);
header('Content-Type: application/json; charset=utf-8');

require_once '../../includes/session_config.php';
require_once '../../includes/csrf.php';
require_once '../../config/database.php';
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

requireCsrfPost();

try {
    $database = new Database();
    $db = $database->getConnection();
    
    $teacherId = $_SESSION['user_id'];
    $lessonId = isset($_POST['lesson_id']) ? intval($_POST['lesson_id']) : 0;
    $sectionType = isset($_POST['section_type']) ? trim($_POST['section_type']) : '';
    // نقبل كلاً من section_data (الاسم الذي يرسله saveInlineEdit في lesson_display.js)
    // و data (للتوافق مع أي مستدعٍ قديم) لتفادي رسالة "بيانات غير مكتملة".
    $updatedData = isset($_POST['section_data']) ? $_POST['section_data'] : (isset($_POST['data']) ? $_POST['data'] : null);
    
    if (!$lessonId || !$sectionType || $updatedData === null) {
        echo json_encode(['success' => false, 'message' => 'بيانات غير مكتملة']);
        exit;
    }
    
    // الأعمدة المسموح بتعديلها
    $columnMap = [
        'lesson_plan' => 'generated_prep',
        'question_bank' => 'question_bank',
        'visual_materials' => 'visual_materials',
        'class_activities' => 'class_activities',
        'educational_stories' => 'educational_stories',
        'mind_maps' => 'mind_maps',
        'lesson_summary' => 'lesson_summary',
        'custom_content' => 'custom_content'
    ];
    
    if (!isset($columnMap[$sectionType])) {
        echo json_encode(['success' => false, 'message' => 'نوع قسم غير صالح']);
        exit;
    }
    
    $dbColumn = $columnMap[$sectionType];
    
    $db->beginTransaction();
    // التحقق من ملكية الدرس
    $checkStmt = $db->prepare("SELECT id, title, {$dbColumn} AS section_data FROM ai_lessons WHERE id = ? AND teacher_id = ? FOR UPDATE");
    $checkStmt->execute([$lessonId, $teacherId]);
    $lesson = $checkStmt->fetch(PDO::FETCH_ASSOC);
    if (!$lesson) {
        $db->rollBack();
        echo json_encode(['success' => false, 'message' => 'لم يتم العثور على الدرس']);
        exit;
    }
    
    // فك تشفير البيانات
    $decodedData = json_decode($updatedData, true);
    if ($decodedData === null && json_last_error() !== JSON_ERROR_NONE) {
        $db->rollBack();
        echo json_encode(['success' => false, 'message' => 'بيانات JSON غير صالحة']);
        exit;
    }
    
    // حفظ البيانات المعدلة
    $jsonFlags = JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE;
    $encodedData = json_encode($decodedData, $jsonFlags);
    
    $updateStmt = $db->prepare("UPDATE ai_lessons SET {$dbColumn} = ?, updated_at = NOW() WHERE id = ? AND teacher_id = ?");
    $updateStmt->execute([$encodedData, $lessonId, $teacherId]);
    (new \EduCore\Modules\Operations\Audit\AuditService($db))->recordEvent(
        'update', 'ai_lesson_section', $lessonId, (string)$lesson['title'],
        [
            'section_type' => $sectionType,
            'before_fingerprint' => hash('sha256', (string)($lesson['section_data'] ?? '')),
            'after_fingerprint' => hash('sha256', (string)$encodedData),
            'before_bytes' => strlen((string)($lesson['section_data'] ?? '')),
            'after_bytes' => strlen((string)$encodedData),
            'undo_policy' => 'lesson_content_restore_not_enabled',
        ]
    );
    $db->commit();
    echo json_encode(['success' => true, 'message' => 'تم حفظ التعديلات بنجاح']);
    
} catch (Throwable $e) {
    if (isset($db) && $db instanceof PDO && $db->inTransaction()) {
        $db->rollBack();
    }
    error_log("Update Section Error: " . $e->getMessage());
    echo json_encode(['success' => false, 'message' => 'تعذر حفظ تعديلات القسم']);
}
