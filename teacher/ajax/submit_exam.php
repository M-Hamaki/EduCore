<?php
/**
 * معالج AJAX لحفظ نتائج الامتحان
 * AJAX Handler for Saving Exam Results
 * مع حساب الدرجة من جانب الخادم للأمان
 */

header('Content-Type: application/json; charset=utf-8');

require_once '../../config/database.php';
require_once '../../includes/session_config.php';
require_once '../../includes/csrf.php';
require_once '../../src/Modules/Operations/Audit/AuditService.php';

// التحقق من نوع الطلب
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo json_encode(['success' => false, 'message' => 'طريقة طلب غير صالحة']);
    exit;
}

try {
    // قراءة البيانات
    $input = json_decode(file_get_contents('php://input'), true);
    requireCsrfToken(is_array($input) ? ($input['csrf_token'] ?? '') : '');
    
    $examId = isset($input['exam_id']) ? intval($input['exam_id']) : 0;
    $studentName = isset($input['student_name']) ? trim($input['student_name']) : '';
    $studentClass = isset($input['student_class']) ? trim($input['student_class']) : '';
    $modelLetter = isset($input['model_letter']) ? trim($input['model_letter']) : 'A';
    $timeSpent = isset($input['time_spent']) ? intval($input['time_spent']) : 0;
    $answers = isset($input['answers']) ? $input['answers'] : [];
    $essayAnswers = isset($input['essay_answers']) ? $input['essay_answers'] : [];
    $essayCount = isset($input['essay_count']) ? intval($input['essay_count']) : 0;
    $cheatingAttempts = isset($input['cheating_attempts']) ? intval($input['cheating_attempts']) : 0;
    
    // التحقق من البيانات الأساسية
    if (!$examId || !$studentName || !$studentClass) {
        echo json_encode(['success' => false, 'message' => 'بيانات ناقصة']);
        exit;
    }
    
    // تنظيف model letter
    $modelLetter = strtoupper(substr(preg_replace('/[^A-Da-d]/', '', $modelLetter), 0, 1));
    if (empty($modelLetter)) $modelLetter = 'A';
    
    // الاتصال بقاعدة البيانات
    $database = new Database();
    $db = $database->getConnection();
    
    // جلب بيانات الامتحان للتحقق وحساب الدرجة من الخادم
    $stmt = $db->prepare("SELECT id, questions_data, passing_percentage, duration_minutes FROM ai_online_exams WHERE id = ? AND is_active = 1");
    $stmt->execute([$examId]);
    $exam = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$exam) {
        echo json_encode(['success' => false, 'message' => 'الامتحان غير موجود أو غير نشط']);
        exit;
    }
    
    // منع التقديم المكرر من نفس الطالب ونفس النموذج خلال 5 دقائق
    $ipAddress = $_SERVER['REMOTE_ADDR'] ?? '';
    $userAgent = $_SERVER['HTTP_USER_AGENT'] ?? '';

    $db->beginTransaction();
    $dupCheck = $db->prepare("
        SELECT id FROM ai_exam_results 
        WHERE exam_id = ? AND student_name = ? AND student_class = ? AND model_letter = ?
        AND submitted_at > DATE_SUB(NOW(), INTERVAL 5 MINUTE)
        FOR UPDATE
    ");
    $dupCheck->execute([$examId, $studentName, $studentClass, $modelLetter]);
    
    if ($dupCheck->fetch()) {
        $db->rollBack();
        echo json_encode(['success' => true, 'message' => 'تم حفظ النتيجة مسبقاً', 'duplicate' => true]);
        exit;
    }
    
    // === حساب الدرجة من جانب الخادم (الأسئلة المقالية تُصحح يدوياً) ===
    $questionsData = json_decode($exam['questions_data'], true);
    $modelQuestions = $questionsData[$modelLetter] ?? $questionsData['A'] ?? [];
    
    // فصل الأسئلة حسب النوع
    $autoGradedQuestions = array_filter($modelQuestions, function($q) {
        return ($q['type'] ?? '') !== 'essay';
    });
    $totalAutoGraded = count($autoGradedQuestions);
    $totalQuestions = $totalAutoGraded; // النتيجة الآلية تحسب فقط على الأسئلة الموضوعية
    $correctAnswers = 0;
    
    if ($totalAutoGraded > 0 && !empty($answers)) {
        foreach ($modelQuestions as $qIndex => $question) {
            if (($question['type'] ?? '') === 'essay') continue; // تخطي المقالية
            if (isset($answers[$qIndex]) || isset($answers[(string)$qIndex])) {
                $studentAnswer = $answers[$qIndex] ?? $answers[(string)$qIndex];
                if (isset($question['correct']) && $studentAnswer === $question['correct']) {
                    $correctAnswers++;
                }
            }
        }
    }
    
    $score = $correctAnswers;
    $percentage = $totalQuestions > 0 ? round(($correctAnswers / $totalQuestions) * 100) : 0;
    $passed = $percentage >= intval($exam['passing_percentage']) ? 1 : 0;
    
    // دمج الإجابات: الموضوعية + المقالية
    $allAnswers = $answers;
    if (!empty($essayAnswers)) {
        $allAnswers['_essay_answers'] = $essayAnswers;
        $allAnswers['_essay_count'] = $essayCount;
        $allAnswers['_has_essay'] = true;
    }
    
    // حفظ النتيجة
    $stmt = $db->prepare("
        INSERT INTO ai_exam_results 
        (exam_id, student_name, student_class, model_letter, score, total_questions, 
         correct_answers, percentage, passed, time_spent_seconds, answers_data, 
         ip_address, user_agent, cheating_attempts, started_at, submitted_at)
        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, DATE_SUB(NOW(), INTERVAL ? SECOND), NOW())
    ");
    
    $encodedAnswers = json_encode($allAnswers, JSON_UNESCAPED_UNICODE);
    $stmt->execute([
        $examId,
        $studentName,
        $studentClass,
        $modelLetter,
        $score,
        $totalQuestions,
        $correctAnswers,
        $percentage,
        $passed,
        $timeSpent,
        $encodedAnswers,
        $ipAddress,
        $userAgent,
        $cheatingAttempts,
        $timeSpent
    ]);
    
    $resultId = $db->lastInsertId();
    (new \EduCore\Modules\Operations\Audit\AuditService($db))->recordEvent(
        'submit', 'online_exam_result', (int)$resultId, $studentName,
        [
            'exam_id' => $examId,
            'student_class' => $studentClass,
            'model_letter' => $modelLetter,
            'score' => $score,
            'total_questions' => $totalQuestions,
            'percentage' => $percentage,
            'passed' => (bool)$passed,
            'answer_count' => count($allAnswers),
            'answers_fingerprint' => hash('sha256', (string)$encodedAnswers),
            'essay_count' => $essayCount,
            'cheating_attempts' => $cheatingAttempts,
            'undo_policy' => 'submitted_exam_result_not_direct_undo',
        ]
    );
    $db->commit();
    echo json_encode([
        'success' => true,
        'result_id' => $resultId,
        'message' => 'تم حفظ النتيجة بنجاح',
        'server_score' => $score,
        'server_total' => $totalQuestions,
        'server_percentage' => $percentage,
        'server_passed' => $passed,
        'has_essay' => $essayCount > 0,
        'essay_count' => $essayCount
    ]);
    
} catch (Throwable $e) {
    if (isset($db) && $db instanceof PDO && $db->inTransaction()) {
        $db->rollBack();
    }
    error_log("Submit Exam Error: " . $e->getMessage());
    echo json_encode([
        'success' => false,
        'message' => 'حدث خطأ في حفظ النتيجة'
    ]);
}
