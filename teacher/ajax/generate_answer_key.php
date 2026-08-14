<?php
/**
 * معالج AJAX لتوليد نموذج الإجابة
 * AJAX Handler for Answer Key Generation
 */

header('Content-Type: application/json; charset=utf-8');

// تحميل الملفات المطلوبة
require_once '../../includes/session_config.php';
require_once '../../config/database.php';
require_once '../../config/ai_config.php';
require_once '../../classes/ExamGenerator.php';
require_once '../../includes/http_helpers.php';

// التحقق من تسجيل الدخول
if (!isset($_SESSION['user_id']) || !in_array($_SESSION['role'], ['teacher', 'external_teacher'])) {
    echo json_encode(['success' => false, 'message' => 'غير مصرح لك بالوصول']);
    exit;
}

// التحقق من نوع الطلب
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo json_encode(['success' => false, 'message' => 'طريقة طلب غير صالحة']);
    exit;
}
requireCsrfToken();

try {
    // الاتصال بقاعدة البيانات
    $database = new Database();
    $db = $database->getConnection();
    
    $teacherId = $_SESSION['user_id'];
    
    // الحصول على البيانات
    $input = json_decode(file_get_contents('php://input'), true);
    
    $lessonId = isset($input['lesson_id']) ? intval($input['lesson_id']) : 0;
    $modelLetter = isset($input['model']) ? strtoupper(trim($input['model'])) : 'A';
    
    // التحقق من البيانات
    if ($lessonId <= 0) {
        echo json_encode(['success' => false, 'message' => 'معرف الدرس غير صالح']);
        exit;
    }
    
    if (!in_array($modelLetter, ['A', 'B', 'C', 'D'])) {
        echo json_encode(['success' => false, 'message' => 'نموذج غير صالح']);
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
    
    // جلب بنك الأسئلة من الدرس
    $questionBank = null;
    if (!empty($lesson['question_bank'])) {
        $questionBank = json_decode($lesson['question_bank'], true);
        if ($questionBank === null && json_last_error() !== JSON_ERROR_NONE) {
            error_log("generate_answer_key: JSON decode failed for lesson {$lessonId}: " . json_last_error_msg());
        }
    }
    
    if ((!$questionBank || empty($questionBank)) && empty($preparedModels[$modelLetter])) {
        echo json_encode(['success' => false, 'message' => 'لا توجد أسئلة في بنك أسئلة هذا الدرس. أعد توليد بنك الأسئلة من صفحة تحضير الدرس.']);
        exit;
    }
    
    // إنشاء مولد الامتحان
    $language = $lesson['language'] ?? 'ar';
    $examGenerator = new ExamGenerator($language);
    if (!empty($preparedModels[$modelLetter])) {
        $examGenerator->setPreparedModels([$modelLetter => $preparedModels[$modelLetter]]);
    } else {
        $examGenerator->setQuestions($questionBank);
    }
    
    // الإعدادات المخزنة هي المرجع عند عدم إرسال قيم صريحة.
    $examGenerator->setMCCount($input['mc_count'] ?? ($lesson['exam_mc_count'] ?? 10));
    $examGenerator->setTFCount($input['tf_count'] ?? ($lesson['exam_tf_count'] ?? 10));
    $examGenerator->setEssayCount($input['essay_count'] ?? ($lesson['exam_essay_count'] ?? 0));
    if (isset($input['model_type'])) $examGenerator->setModelType($input['model_type']);
    
    // توليد نموذج الإجابة
    $title = $lesson['title'] ?? ($language === 'ar' ? 'امتحان إلكتروني' : 'Electronic Exam');
    $answerKeyHtml = $examGenerator->generateAnswerKeyHTML($modelLetter, $title);
    
    if (!$answerKeyHtml) {
        echo json_encode([
            'success' => false, 
            'message' => $examGenerator->getLastError() ?? 'فشل في توليد نموذج الإجابة'
        ]);
        exit;
    }
    
    echo json_encode([
        'success' => true,
        'answer_key_html' => $answerKeyHtml,
        'model' => $modelLetter
    ]);

} catch (Exception $e) {
    echo json_encode([
        'success' => false, 
        'message' => 'حدث خطأ غير متوقع'
    ]);
}
