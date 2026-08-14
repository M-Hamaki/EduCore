<?php
/**
 * معالج AJAX لنشر الامتحان أونلاين
 * AJAX Handler for Publishing Exam Online
 */

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

// التحقق من نوع الطلب
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo json_encode(['success' => false, 'message' => 'طريقة طلب غير صالحة']);
    exit;
}
requireCsrfToken();

try {
    // قراءة البيانات
    $input = json_decode(file_get_contents('php://input'), true);
    
    $lessonId = isset($input['lesson_id']) ? intval($input['lesson_id']) : 0;
    $examDuration = isset($input['exam_duration']) ? intval($input['exam_duration']) : 20;
    $examModels = isset($input['exam_models']) ? intval($input['exam_models']) : 3;
    $examTheme = isset($input['exam_theme']) && in_array($input['exam_theme'], ['classic','ocean','nature','sunset','rose','dark','royal']) ? $input['exam_theme'] : 'classic';
    $mcCount = isset($input['mc_count']) ? intval($input['mc_count']) : null;
    $tfCount = isset($input['tf_count']) ? intval($input['tf_count']) : null;
    $essayCount = isset($input['essay_count']) ? intval($input['essay_count']) : null;
    
    if (!$lessonId) {
        echo json_encode(['success' => false, 'message' => 'معرف الدرس غير صالح']);
        exit;
    }
    
    // الاتصال بقاعدة البيانات
    $database = new Database();
    $db = $database->getConnection();
    
    $teacherId = $_SESSION['user_id'];
    
    // التحقق من ملكية الدرس
    $stmt = $db->prepare("SELECT id, title, question_bank, exam_mc_count, exam_tf_count, exam_essay_count FROM ai_lessons WHERE id = ? AND teacher_id = ?");
    $stmt->execute([$lessonId, $teacherId]);
    $lesson = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$lesson) {
        echo json_encode(['success' => false, 'message' => 'لم يتم العثور على الدرس']);
        exit;
    }
    
    // التحقق من وجود بنك أسئلة
    if (empty($lesson['question_bank']) || $lesson['question_bank'] === 'null') {
        echo json_encode(['success' => false, 'message' => 'لا يوجد بنك أسئلة للدرس. يرجى إعادة توليد الدرس مع محتوى كافٍ.']);
        exit;
    }
    
    $questionBank = json_decode($lesson['question_bank'], true);
    
    if (empty($questionBank)) {
        echo json_encode(['success' => false, 'message' => 'بنك الأسئلة فارغ. تأكد من أن محتوى الدرس يحتوي على معلومات كافية لتوليد أسئلة.']);
        exit;
    }
    
    // استخدام إعدادات الأسئلة المرسلة أو المحفوظة في الدرس
    if ($mcCount === null) {
        $mcCount = isset($lesson['exam_mc_count']) ? intval($lesson['exam_mc_count']) : null;
    }
    if ($tfCount === null) {
        $tfCount = isset($lesson['exam_tf_count']) ? intval($lesson['exam_tf_count']) : null;
    }
    if ($essayCount === null) {
        $essayCount = isset($lesson['exam_essay_count']) ? intval($lesson['exam_essay_count']) : 0;
    }
    
    $db->beginTransaction();
    // التحقق من وجود امتحان منشور مسبقاً
    $stmt = $db->prepare("SELECT id, exam_code FROM ai_online_exams WHERE lesson_id = ? AND teacher_id = ? AND is_active = 1 FOR UPDATE");
    $stmt->execute([$lessonId, $teacherId]);
    $existingExam = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if ($existingExam) {
        $db->rollBack();
        // إرجاع الامتحان الموجود
        echo json_encode([
            'success' => true,
            'exam_id' => $existingExam['id'],
            'exam_code' => $existingExam['exam_code'],
            'message' => 'تم العثور على امتحان منشور مسبقاً'
        ]);
        exit;
    }
    
    // إنشاء كود فريد للامتحان
    $examCode = generateExamCode();
    
    // تحضير أسئلة الامتحان مع تطبيق عدد الأسئلة المحدد
    $examQuestions = prepareExamQuestions($questionBank, $mcCount, $tfCount, $essayCount);
    
    if (count($examQuestions) < 3) {
        $db->rollBack();
        echo json_encode(['success' => false, 'message' => 'عدد الأسئلة غير كافٍ (المطلوب على الأقل 3 أسئلة). أعد توليد الدرس بمحتوى أكثر.']);
        exit;
    }
    
    // إنشاء النماذج
    $models = createExamModels($examQuestions, $examModels);
    
    // حفظ الامتحان في قاعدة البيانات
    $stmt = $db->prepare("
        INSERT INTO ai_online_exams 
        (lesson_id, teacher_id, exam_code, title, duration_minutes, models_count, questions_data, exam_theme)
        VALUES (?, ?, ?, ?, ?, ?, ?, ?)
    ");
    
    $examTitle = 'امتحان: ' . $lesson['title'];
    $questionsJson = json_encode($models, JSON_UNESCAPED_UNICODE);
    
    $stmt->execute([
        $lessonId,
        $teacherId,
        $examCode,
        $examTitle,
        $examDuration,
        $examModels,
        $questionsJson,
        $examTheme
    ]);
    
    $examId = $db->lastInsertId();
    
    // تحديث جدول الدروس بإعدادات الامتحان (بما في ذلك أعداد الأسئلة)
    $stmt = $db->prepare("UPDATE ai_lessons SET exam_duration = ?, exam_models_count = ?, exam_mc_count = ?, exam_tf_count = ?, exam_essay_count = ? WHERE id = ? AND teacher_id = ?");
    $stmt->execute([$examDuration, $examModels, $mcCount, $tfCount, $essayCount, $lessonId, $teacherId]);
    (new \EduCore\Modules\Operations\Audit\AuditService($db))->recordEvent(
        'publish', 'online_exam', (int)$examId, $examTitle,
        [
            'lesson_id' => $lessonId,
            'exam_code' => $examCode,
            'duration_minutes' => $examDuration,
            'models_count' => $examModels,
            'question_counts' => [
                'multiple_choice' => $mcCount,
                'true_false' => $tfCount,
                'essay' => $essayCount,
            ],
            'questions_fingerprint' => hash('sha256', (string)$questionsJson),
            'lesson_settings_before' => [
                'exam_mc_count' => $lesson['exam_mc_count'],
                'exam_tf_count' => $lesson['exam_tf_count'],
                'exam_essay_count' => $lesson['exam_essay_count'],
            ],
            'undo_policy' => 'published_exam_composite_restore_not_enabled',
        ]
    );
    $db->commit();
    echo json_encode([
        'success' => true,
        'exam_id' => $examId,
        'exam_code' => $examCode,
        'message' => 'تم نشر الامتحان بنجاح'
    ]);
    
} catch (Throwable $e) {
    if (isset($db) && $db instanceof PDO && $db->inTransaction()) {
        $db->rollBack();
    }
    error_log("Publish Exam Error: " . $e->getMessage());
    echo json_encode([
        'success' => false,
        'message' => 'حدث خطأ في نشر الامتحان'
    ]);
}

/**
 * إنشاء كود فريد للامتحان مع التحقق من عدم التكرار
 */
function generateExamCode() {
    global $db;
    $characters = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ0123456789';
    $maxLen = strlen($characters) - 1;
    $maxAttempts = 10;
    
    for ($attempt = 0; $attempt < $maxAttempts; $attempt++) {
        $code = '';
        for ($i = 0; $i < 8; $i++) {
            $code .= $characters[random_int(0, $maxLen)];
        }
        
        // التحقق من عدم وجود نفس الكود في قاعدة البيانات
        $stmt = $db->prepare("SELECT COUNT(*) FROM ai_online_exams WHERE exam_code = ?");
        $stmt->execute([$code]);
        if ($stmt->fetchColumn() == 0) {
            return $code;
        }
    }
    
    // في حالة فشل جميع المحاولات (نادر جداً)
    return strtoupper(substr(md5(uniqid('', true)), 0, 8));
}

/**
 * تحضير أسئلة الامتحان من بنك الأسئلة مع تطبيق العدد المحدد
 * @param array $questionBank بنك الأسئلة
 * @param int|null $mcLimit عدد أسئلة الاختيار من متعدد (null = الكل)
 * @param int|null $tfLimit عدد أسئلة صح/خطأ (null = الكل)
 * @param int|null $essayLimit عدد الأسئلة المقالية (null = 0)
 */
function prepareExamQuestions($questionBank, $mcLimit = null, $tfLimit = null, $essayLimit = null) {
    $examQuestions = [];
    
    // أسئلة الاختيار من متعدد
    if (isset($questionBank['multiple_choice']) && !empty($questionBank['multiple_choice'])) {
        $mcQuestions = $questionBank['multiple_choice'];
        if ($mcLimit !== null) {
            $mcQuestions = array_slice($mcQuestions, 0, max(0, $mcLimit));
        }
        foreach ($mcQuestions as $q) {
            if (!isset($q['question']) || !isset($q['options']) || !isset($q['correct_answer'])) continue;
            $examQuestions[] = [
                'type' => 'multiple_choice',
                'question' => $q['question'],
                'options' => $q['options'],
                'correct' => $q['correct_answer']
            ];
        }
    }
    
    // أسئلة صح/خطأ
    if (isset($questionBank['true_false']) && !empty($questionBank['true_false'])) {
        $tfQuestions = $questionBank['true_false'];
        if ($tfLimit !== null) {
            $tfQuestions = array_slice($tfQuestions, 0, max(0, $tfLimit));
        }
        foreach ($tfQuestions as $q) {
            if (!isset($q['statement'])) continue;
            $examQuestions[] = [
                'type' => 'true_false',
                'question' => $q['statement'],
                'correct' => isset($q['correct_answer']) ? ($q['correct_answer'] ? 1 : 0) : 0
            ];
        }
    }
    
    // الأسئلة المقالية
    if ($essayLimit !== null && $essayLimit > 0 && isset($questionBank['graduated']) && !empty($questionBank['graduated'])) {
        $essayQuestions = array_slice($questionBank['graduated'], 0, $essayLimit);
        foreach ($essayQuestions as $q) {
            if (!isset($q['question'])) continue;
            $examQuestions[] = [
                'type' => 'essay',
                'question' => $q['question'],
                'model_answer' => $q['model_answer'] ?? '',
                'difficulty' => $q['difficulty'] ?? 'medium'
            ];
        }
    }
    
    return $examQuestions;
}

/**
 * إنشاء نماذج متعددة للامتحان
 */
function createExamModels($questions, $modelsCount) {
    $models = [];
    $letters = ['A', 'B', 'C', 'D'];
    
    for ($i = 0; $i < min($modelsCount, 4); $i++) {
        $letter = $letters[$i];
        $shuffled = $questions;
        
        // خلط الأسئلة بطريقة مختلفة لكل نموذج
        switch ($letter) {
            case 'A':
                // النموذج الأصلي
                break;
            case 'B':
                // عكس الترتيب
                $shuffled = array_reverse($shuffled);
                break;
            case 'C':
                // خلط عشوائي 1
                shuffle($shuffled);
                break;
            case 'D':
                // خلط عشوائي 2
                shuffle($shuffled);
                $shuffled = array_reverse($shuffled); // ثم عكس
                shuffle($shuffled);
                break;
        }
        
        // خلط خيارات أسئلة الاختيار من متعدد
        foreach ($shuffled as &$q) {
            if ($q['type'] === 'multiple_choice') {
                $correctAnswer = $q['options'][$q['correct']];
                shuffle($q['options']);
                $q['correct'] = array_search($correctAnswer, $q['options']);
            }
        }
        
        $models[$letter] = $shuffled;
    }
    
    return $models;
}
