<?php
/**
 * معالج AJAX لتوليد نموذج امتحان واحد
 * AJAX Handler for Single Exam Model Generation
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
    $examDuration = isset($input['exam_duration']) ? intval($input['exam_duration']) : 20;
    $mcCount = isset($input['mc_count']) ? intval($input['mc_count']) : null;
    $tfCount = isset($input['tf_count']) ? intval($input['tf_count']) : null;
    $essayCount = isset($input['essay_count']) ? intval($input['essay_count']) : null;
    $modelType = isset($input['model_type']) ? $input['model_type'] : 'shuffle';
    $antiCheat = isset($input['anti_cheat']) ? (bool)$input['anti_cheat'] : true;
    $studentInfo = isset($input['student_info']) ? (bool)$input['student_info'] : true;
    
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

    // استخدم النموذج المحفوظ نفسه حتى لا يعيد زر التصدير خلط الأسئلة أو الإجابات.
    if (!empty($lesson['exam_html'])) {
        $storedModelHtml = ExamGenerator::filterExamHtmlToModel(
            (string) $lesson['exam_html'],
            $modelLetter
        );
        if ($storedModelHtml !== null) {
            echo json_encode([
                'success' => true,
                'exam_html' => $storedModelHtml,
                'model' => $modelLetter,
                'question_count' => count(
                    ExamGenerator::extractPreparedModels((string) $lesson['exam_html'])[$modelLetter]
                ),
                'from_saved_exam' => true,
            ], JSON_UNESCAPED_UNICODE);
            exit;
        }
    }
    
    // جلب بنك الأسئلة من الدرس
    $questionBank = null;
    if (!empty($lesson['question_bank'])) {
        $questionBank = json_decode($lesson['question_bank'], true);
        if ($questionBank === null && json_last_error() !== JSON_ERROR_NONE) {
            error_log("generate_single_model: JSON decode failed for lesson {$lessonId}: " . json_last_error_msg());
        }
    }
    
    if (!$questionBank || empty($questionBank)) {
        echo json_encode(['success' => false, 'message' => 'لا توجد أسئلة في بنك أسئلة هذا الدرس. أعد توليد بنك الأسئلة من صفحة تحضير الدرس.']);
        exit;
    }
    
    // إنشاء مولد الامتحان
    $examGenerator = new ExamGenerator($lesson['language'] ?? 'ar');
    $examGenerator->setQuestions($questionBank);
    $examGenerator->setDuration($examDuration);
    $examGenerator->setModelsCount(1); // نموذج واحد فقط
    $examGenerator->setMCCount($mcCount ?? ($lesson['exam_mc_count'] ?? 10));
    $examGenerator->setTFCount($tfCount ?? ($lesson['exam_tf_count'] ?? 10));
    $examGenerator->setEssayCount($essayCount ?? ($lesson['exam_essay_count'] ?? 0));
    $examGenerator->setModelType($modelType);
    $examGenerator->setAntiCheatEnabled($antiCheat);
    $examGenerator->setStudentInfoEnabled($studentInfo);
    
    // تعيين ثيم الامتحان
    $examTheme = isset($input['exam_theme']) ? $input['exam_theme'] : 'classic';
    $examGenerator->setTheme($examTheme);
    
    // توليد نموذج واحد
    $examHtml = $examGenerator->generateSingleModelHTML(
        $modelLetter, 
        $lesson['title'] ?? 'امتحان إلكتروني'
    );
    
    if (!$examHtml) {
        echo json_encode([
            'success' => false, 
            'message' => $examGenerator->getLastError() ?? 'فشل في توليد النموذج'
        ]);
        exit;
    }
    
    echo json_encode([
        'success' => true,
        'exam_html' => $examHtml,
        'model' => $modelLetter,
        'question_count' => $examGenerator->getActualQuestionCount()
    ]);

} catch (Exception $e) {
    echo json_encode([
        'success' => false, 
        'message' => 'حدث خطأ غير متوقع'
    ]);
}
