<?php
/**
 * معالج AJAX لتوليد جميع نماذج الإجابة في ملف واحد
 * AJAX Handler for All Answer Keys in Single File
 */

header('Content-Type: application/json; charset=utf-8');

require_once '../../includes/session_config.php';
require_once '../../config/database.php';
require_once '../../config/ai_config.php';
require_once '../../classes/ExamGenerator.php';
require_once '../../includes/http_helpers.php';

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
    $database = new Database();
    $db = $database->getConnection();
    
    $teacherId = $_SESSION['user_id'];
    $input = json_decode(file_get_contents('php://input'), true);
    
    $lessonId = isset($input['lesson_id']) ? intval($input['lesson_id']) : 0;
    
    if ($lessonId <= 0) {
        echo json_encode(['success' => false, 'message' => 'معرف الدرس غير صالح']);
        exit;
    }
    
    // جلب بيانات الدرس
    $stmt = $db->prepare("SELECT * FROM ai_lessons WHERE id = ? AND teacher_id = ?");
    $stmt->execute([$lessonId, $teacherId]);
    $lesson = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$lesson) {
        echo json_encode(['success' => false, 'message' => 'الدرس غير موجود أو لا تملك صلاحية الوصول']);
        exit;
    }

    $preparedModels = !empty($lesson['exam_html'])
        ? ExamGenerator::extractPreparedModels((string) $lesson['exam_html'])
        : [];
    
    $questionBank = null;
    if (!empty($lesson['question_bank'])) {
        $questionBank = json_decode($lesson['question_bank'], true);
        if ($questionBank === null && json_last_error() !== JSON_ERROR_NONE) {
            error_log("generate_all_answer_keys: JSON decode failed for lesson {$lessonId}: " . json_last_error_msg());
        }
    }
    
    if ((!$questionBank || empty($questionBank)) && empty($preparedModels)) {
        echo json_encode(['success' => false, 'message' => 'لا توجد أسئلة في بنك أسئلة هذا الدرس. أعد توليد بنك الأسئلة من صفحة تحضير الدرس.']);
        exit;
    }
    
    $language = $lesson['language'] ?? 'ar';
    $examGenerator = new ExamGenerator($language);
    if ($preparedModels) {
        $examGenerator->setPreparedModels($preparedModels);
    } else {
        $examGenerator->setQuestions($questionBank);
    }
    
    $modelsCount = $preparedModels
        ? count($preparedModels)
        : (isset($lesson['exam_models_count']) ? intval($lesson['exam_models_count']) : 3);
    $examGenerator->setModelsCount($modelsCount);
    
    // الإعدادات المخزنة هي المرجع عند عدم إرسال قيم صريحة.
    $examGenerator->setMCCount($input['mc_count'] ?? ($lesson['exam_mc_count'] ?? 10));
    $examGenerator->setTFCount($input['tf_count'] ?? ($lesson['exam_tf_count'] ?? 10));
    $examGenerator->setEssayCount($input['essay_count'] ?? ($lesson['exam_essay_count'] ?? 0));
    if (isset($input['model_type'])) $examGenerator->setModelType($input['model_type']);
    
    $title = $lesson['title'] ?? ($language === 'ar' ? 'امتحان إلكتروني' : 'Electronic Exam');
    $answerKeyHtml = $examGenerator->generateAllAnswerKeysHTML($title);
    
    if (!$answerKeyHtml) {
        echo json_encode([
            'success' => false, 
            'message' => $examGenerator->getLastError() ?? 'فشل في توليد نماذج الإجابة'
        ]);
        exit;
    }
    
    echo json_encode([
        'success' => true,
        'answer_key_html' => $answerKeyHtml,
        'models_count' => $modelsCount
    ]);

} catch (Exception $e) {
    echo json_encode([
        'success' => false, 
        'message' => 'حدث خطأ غير متوقع'
    ]);
}
