<?php
/**
 * معالج AJAX لاستنساخ الدروس
 * AJAX Handler for cloning lessons
 */

ini_set('display_errors', 0);
error_reporting(E_ALL);
header('Content-Type: application/json; charset=utf-8');

require_once '../../includes/session_config.php';
require_once '../../includes/csrf.php';
require_once '../../config/database.php';
require_once '../../src/Modules/Operations/Audit/AuditService.php';

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
    
    if (!$lessonId) {
        echo json_encode(['success' => false, 'message' => 'معرف الدرس مطلوب']);
        exit;
    }
    
    $db->beginTransaction();
    // جلب الدرس الأصلي
    $stmt = $db->prepare("SELECT * FROM ai_lessons WHERE id = ? AND teacher_id = ? FOR UPDATE");
    $stmt->execute([$lessonId, $teacherId]);
    $lesson = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$lesson) {
        $db->rollBack();
        echo json_encode(['success' => false, 'message' => 'لم يتم العثور على الدرس']);
        exit;
    }
    
    // إنشاء نسخة جديدة
    $newTitle = $lesson['title'] . ' (نسخة)';
    
    // بناء الاستعلام ديناميكياً بناءً على الأعمدة الموجودة
    $columns = ['teacher_id', 'title', 'language', 'original_content', 'duration_minutes', 
                'generated_prep', 'question_bank', 'visual_materials', 'exam_html',
                'exam_duration', 'exam_models_count', 'status', 'subject'];
    
    $values = [$teacherId, $newTitle, $lesson['language'], $lesson['original_content'], 
               $lesson['duration_minutes'], $lesson['generated_prep'], $lesson['question_bank'],
               $lesson['visual_materials'], $lesson['exam_html'], $lesson['exam_duration'],
               $lesson['exam_models_count'], $lesson['status'], $lesson['subject'] ?? null];
    
    // إضافة الأعمدة الاختيارية إذا كانت موجودة
    $optionalColumns = ['class_activities', 'educational_stories', 'mind_maps', 'lesson_summary', 'custom_content',
                        'exam_mc_count', 'exam_tf_count', 'exam_essay_count', 'folder_id', 'grade_level'];
    
    foreach ($optionalColumns as $col) {
        if (array_key_exists($col, $lesson)) {
            $columns[] = $col;
            $values[] = $col === 'folder_id' ? $lesson[$col] : $lesson[$col];
        }
    }
    
    $placeholders = implode(', ', array_fill(0, count($columns), '?'));
    $columnList = implode(', ', $columns);
    
    $insertStmt = $db->prepare("INSERT INTO ai_lessons ({$columnList}) VALUES ({$placeholders})");
    $insertStmt->execute($values);
    
    $newLessonId = $db->lastInsertId();
    $contentFingerprints = [];
    foreach (['original_content', 'generated_prep', 'question_bank', 'visual_materials', 'exam_html'] as $field) {
        if (!empty($lesson[$field])) {
            $contentFingerprints[$field] = hash('sha256', (string)$lesson[$field]);
        }
    }
    (new \EduCore\Modules\Operations\Audit\AuditService($db))->recordEvent(
        'clone', 'ai_lesson', (int)$newLessonId, $newTitle,
        [
            'source_lesson_id' => $lessonId,
            'new_lesson_id' => (int)$newLessonId,
            'folder_id' => $lesson['folder_id'] ?? null,
            'content_fingerprints' => $contentFingerprints,
            'undo_policy' => 'cloned_lesson_delete_requires_review',
        ]
    );
    $db->commit();
    echo json_encode([
        'success' => true,
        'message' => 'تم نسخ الدرس بنجاح',
        'new_lesson_id' => intval($newLessonId),
        'new_title' => $newTitle
    ], JSON_UNESCAPED_UNICODE);
    
} catch (Throwable $e) {
    if (isset($db) && $db instanceof PDO && $db->inTransaction()) {
        $db->rollBack();
    }
    error_log("Clone Lesson Error: " . $e->getMessage());
    echo json_encode(['success' => false, 'message' => 'تعذر نسخ الدرس']);
}
