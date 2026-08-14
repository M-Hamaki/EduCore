<?php
/**
 * معالج AJAX لتوليد صورة تعليمية بالذكاء الاصطناعي
 * AJAX Handler for AI Educational Image Generation
 */

ini_set('display_errors', 0);
error_reporting(E_ALL);

header('Content-Type: application/json; charset=utf-8');

set_error_handler(function($errno, $errstr, $errfile, $errline) {
    error_log("PHP Error [$errno]: $errstr in $errfile on line $errline");
    return true;
});

require_once '../../includes/session_config.php';
require_once '../../includes/csrf.php';
require_once '../../config/database.php';
require_once '../../config/ai_config.php';
require_once '../../classes/AIProvider.php';
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
    
    // التحقق من حدود الاستخدام اليومي للصور
    $imageLimit = defined('GEMINI_IMAGE_DAILY_LIMIT') ? GEMINI_IMAGE_DAILY_LIMIT : 20;
    try {
        $stmt = $db->prepare("
            SELECT COUNT(*) as count 
            FROM ai_api_logs 
            WHERE teacher_id = ? 
            AND DATE(created_at) = CURDATE()
            AND request_type = 'image_generation'
            AND status = 'success'
        ");
        $stmt->execute([$teacherId]);
        $result = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if ($result && $result['count'] >= $imageLimit) {
            echo json_encode([
                'success' => false, 
                'message' => "لقد تجاوزت الحد اليومي لتوليد الصور ({$imageLimit} صورة). يرجى المحاولة غداً."
            ]);
            exit;
        }
    } catch (PDOException $e) {
        error_log("Image limit check error: " . $e->getMessage());
    }
    
    // استلام البيانات
    $prompt = isset($_POST['prompt']) ? trim($_POST['prompt']) : '';
    $lessonId = isset($_POST['lesson_id']) ? intval($_POST['lesson_id']) : 0;
    $imageType = isset($_POST['image_type']) ? trim($_POST['image_type']) : 'educational'; // educational, flash_card, sequential
    
    if (empty($prompt)) {
        echo json_encode(['success' => false, 'message' => 'يرجى تقديم وصف للصورة المطلوبة']);
        exit;
    }
    
    if (mb_strlen($prompt) > 2000) {
        echo json_encode(['success' => false, 'message' => 'وصف الصورة طويل جداً (الحد الأقصى 2000 حرف)']);
        exit;
    }
    
    // التحقق من وجود الدرس (اختياري)
    if ($lessonId > 0) {
        $stmt = $db->prepare("SELECT id FROM ai_lessons WHERE id = ? AND teacher_id = ?");
        $stmt->execute([$lessonId, $teacherId]);
        if (!$stmt->fetch()) {
            echo json_encode(['success' => false, 'message' => 'لم يتم العثور على الدرس']);
            exit;
        }
    }
    
    // إنشاء مولد الصور
    $gemini = new AIProvider($db);
    
    // توليد الصورة
    $result = $gemini->generateImage($prompt);
    
    if (!$result) {
        try {
            $db->beginTransaction();
            if (!logApiUsage($db, $teacherId, $lessonId ?: null, 'image_generation', 'error', $gemini->getLastTokensUsed(), $gemini->getLastResponseTime(), $gemini->getLastError())) {
                throw new RuntimeException('AI usage failure could not be recorded.');
            }
            (new \EduCore\Modules\Operations\Audit\AuditService($db))->recordEvent(
                'ai_image_generation_failed',
                'ai_lesson',
                $lessonId ?: null,
                'توليد صورة تعليمية',
                [
                    'image_type' => $imageType,
                    'prompt_length' => mb_strlen($prompt),
                    'prompt_sha256' => hash('sha256', $prompt),
                    'response_time_ms' => $gemini->getLastResponseTime(),
                ],
                ['outcome' => 'failure']
            );
            $db->commit();
        } catch (Throwable $auditError) {
            if ($db->inTransaction()) $db->rollBack();
            error_log('Image generation failure audit error: ' . $auditError->getMessage());
        }
        
        echo json_encode([
            'success' => false, 
            'message' => 'فشل في توليد الصورة: ' . $gemini->getLastError()
        ]);
        exit;
    }
    
    $generatedImageId = null;
    try {
        $db->beginTransaction();
        if (!logApiUsage($db, $teacherId, $lessonId ?: null, 'image_generation', 'success', $gemini->getLastTokensUsed(), $gemini->getLastResponseTime())) {
            throw new RuntimeException('AI usage could not be recorded.');
        }

        // تحقق من وجود جدول الصور المولدة
        $tableCheck = $db->query("SHOW TABLES LIKE 'ai_generated_images'");
        if ($tableCheck->rowCount() > 0) {
            $stmt = $db->prepare("
                INSERT INTO ai_generated_images 
                (teacher_id, lesson_id, prompt, filename, file_path, mime_type, file_size, image_type)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?)
            ");
            $stmt->execute([
                $teacherId,
                $lessonId ?: null,
                $prompt,
                $result['filename'],
                $result['path'],
                $result['mime_type'],
                $result['size'],
                $imageType
            ]);
            $generatedImageId = (int) $db->lastInsertId();
        }

        (new \EduCore\Modules\Operations\Audit\AuditService($db))->recordEvent(
            'ai_image_generated',
            'ai_generated_image',
            $generatedImageId,
            (string) $result['filename'],
            [
                'lesson_id' => $lessonId ?: null,
                'image_type' => $imageType,
                'mime_type' => $result['mime_type'],
                'file_size' => (int) $result['size'],
                'prompt_length' => mb_strlen($prompt),
                'prompt_sha256' => hash('sha256', $prompt),
                'filename_sha256' => hash('sha256', (string) $result['filename']),
                'response_time_ms' => $gemini->getLastResponseTime(),
                'metadata_stored' => $generatedImageId !== null,
            ]
        );
        $db->commit();
    } catch (Throwable $e) {
        if ($db->inTransaction()) $db->rollBack();
        error_log("Save generated image audit error: " . $e->getMessage());
        echo json_encode(['success' => false, 'message' => 'تم توليد الصورة لكن تعذر تسجيلها بأمان. يرجى إعادة المحاولة.']);
        exit;
    }
    
    echo json_encode([
        'success' => true,
        'image_url' => $result['url'],
        'filename' => $result['filename'],
        'size' => $result['size'],
        'text_response' => $result['text_response'] ?? '',
        'response_time' => $gemini->getLastResponseTime()
    ], JSON_UNESCAPED_UNICODE);
    
} catch (Exception $e) {
    error_log("Generate Image Error: " . $e->getMessage());
    echo json_encode([
        'success' => false, 
        'message' => 'حدث خطأ غير متوقع: ' . $e->getMessage()
    ]);
}
