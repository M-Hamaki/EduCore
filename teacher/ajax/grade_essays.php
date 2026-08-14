<?php
/**
 * معالج AJAX لتصحيح الأسئلة المقالية
 * AJAX Handler for grading essay questions
 */

ini_set('display_errors', 0);
error_reporting(E_ALL);
header('Content-Type: application/json; charset=utf-8');

require_once '../../includes/session_config.php';
require_once '../../config/database.php';
require_once '../../includes/http_helpers.php';
require_once '../../src/Modules/Operations/Audit/AuditService.php';

// التحقق من تسجيل الدخول (معلم أو أدمن)
if (!isset($_SESSION['user_id']) || !in_array($_SESSION['role'], ['teacher', 'external_teacher', 'admin', 'super_admin'])) {
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
    
    $userId = $_SESSION['user_id'];
    $input = json_decode(file_get_contents('php://input'), true);
    
    if (!$input) {
        $input = $_POST;
    }
    
    $action = isset($input['action']) ? $input['action'] : '';
    
    switch ($action) {
        case 'get_essays':
            // جلب النتائج التي تحتوي على مقالي لامتحان معين
            $examId = isset($input['exam_id']) ? intval($input['exam_id']) : 0;
            if (!$examId) {
                echo json_encode(['success' => false, 'message' => 'معرف الامتحان مطلوب']);
                exit;
            }
            
            // التحقق من ملكية الامتحان
            $examStmt = $db->prepare("
                SELECT e.id, e.teacher_id, e.title, e.questions_data
                FROM ai_online_exams e 
                WHERE e.id = ?
            ");
            $examStmt->execute([$examId]);
            $exam = $examStmt->fetch(PDO::FETCH_ASSOC);
            
            if (!$exam) {
                echo json_encode(['success' => false, 'message' => 'الامتحان غير موجود']);
                exit;
            }
            
            // التحقق من الصلاحية
            if (!in_array($_SESSION['role'], ['admin', 'super_admin']) && $exam['teacher_id'] != $userId) {
                echo json_encode(['success' => false, 'message' => 'غير مصرح لك']);
                exit;
            }
            
            // جلب النتائج مع إجابات مقالية
            $resultsStmt = $db->prepare("
                SELECT id, student_name, student_class, model_letter, score, total_questions,
                       correct_answers, percentage, passed, answers_data, essay_grades,
                       essay_graded, created_at
                FROM ai_exam_results 
                WHERE exam_id = ?
                ORDER BY created_at DESC
            ");
            $resultsStmt->execute([$examId]);
            $results = $resultsStmt->fetchAll(PDO::FETCH_ASSOC);
            
            // فلترة النتائج التي تحتوي على مقالي
            $essayResults = [];
            foreach ($results as $r) {
                $answers = json_decode($r['answers_data'], true);
                if (isset($answers['_has_essay']) && $answers['_has_essay']) {
                    $r['essay_answers'] = isset($answers['_essay_answers']) ? $answers['_essay_answers'] : [];
                    unset($r['answers_data']); // لا نرسل كل الإجابات
                    $essayResults[] = $r;
                }
            }
            
            // استخراج الأسئلة المقالية من بيانات الامتحان
            $questionsData = json_decode($exam['questions_data'], true);
            $essayQuestions = [];
            if ($questionsData) {
                foreach ($questionsData as $model => $questions) {
                    foreach ($questions as $idx => $q) {
                        if (isset($q['type']) && $q['type'] === 'graduated') {
                            $essayQuestions[$model][$idx] = [
                                'question' => $q['question'] ?? '',
                                'model_answer' => $q['model_answer'] ?? $q['answer'] ?? '',
                                'cognitive_level' => $q['cognitive_level'] ?? '',
                                'marks' => $q['marks'] ?? 5
                            ];
                        }
                    }
                    break; // نأخذ فقط النموذج الأول كمرجع
                }
            }
            
            echo json_encode([
                'success' => true,
                'exam_title' => $exam['title'],
                'results' => $essayResults,
                'essay_questions' => $essayQuestions,
                'total_with_essays' => count($essayResults),
                'graded_count' => count(array_filter($essayResults, function($r) { return $r['essay_graded']; }))
            ], JSON_UNESCAPED_UNICODE);
            break;
            
        case 'save_grade':
            // حفظ تصحيح مقالي لطالب
            $resultId = isset($input['result_id']) ? intval($input['result_id']) : 0;
            $essayGrades = isset($input['essay_grades']) ? $input['essay_grades'] : [];
            
            if (!$resultId || empty($essayGrades)) {
                echo json_encode(['success' => false, 'message' => 'بيانات غير مكتملة']);
                exit;
            }

            $db->beginTransaction();
            // التحقق من وجود النتيجة وملكية الامتحان
            $resultStmt = $db->prepare("
                SELECT r.*, e.teacher_id
                FROM ai_exam_results r
                JOIN ai_online_exams e ON r.exam_id = e.id
                WHERE r.id = ?
                FOR UPDATE
            ");
            $resultStmt->execute([$resultId]);
            $result = $resultStmt->fetch(PDO::FETCH_ASSOC);
            
            if (!$result) {
                $db->rollBack();
                echo json_encode(['success' => false, 'message' => 'النتيجة غير موجودة']);
                exit;
            }
            
            if (!in_array($_SESSION['role'], ['admin', 'super_admin']) && $result['teacher_id'] != $userId) {
                $db->rollBack();
                echo json_encode(['success' => false, 'message' => 'غير مصرح لك']);
                exit;
            }
            
            // حساب الدرجة النهائية
            $answersData = json_decode($result['answers_data'], true);
            $essayCount = isset($answersData['_essay_answers']) ? count($answersData['_essay_answers']) : 0;
            $mcTfCorrect = intval($result['correct_answers']);
            $totalQuestions = intval($result['total_questions']);
            
            // جمع درجات المقالي
            $totalEssayScore = 0;
            $totalEssayMax = 0;
            foreach ($essayGrades as $grade) {
                $totalEssayScore += floatval($grade['score'] ?? 0);
                $totalEssayMax += floatval($grade['max_score'] ?? 5);
            }
            
            // الدرجة النهائية: (صحيحة MC/TF + نسبة المقالي) / إجمالي الأسئلة
            $essayAsCorrect = $totalEssayMax > 0 ? ($totalEssayScore / $totalEssayMax) * $essayCount : 0;
            $newCorrectAnswers = $mcTfCorrect + $essayAsCorrect;
            $newPercentage = $totalQuestions > 0 ? round(($newCorrectAnswers / $totalQuestions) * 100, 2) : 0;
            
            // تحديث النتيجة
            $updateStmt = $db->prepare("
                UPDATE ai_exam_results 
                SET essay_grades = ?,
                    essay_graded = 1,
                    essay_graded_by = ?,
                    essay_graded_at = NOW(),
                    final_score = ?,
                    percentage = ?,
                    passed = ?
                WHERE id = ?
            ");
            
            $passingPercentage = 50; // افتراضي
            try {
                $examInfoStmt = $db->prepare("SELECT passing_percentage FROM ai_online_exams WHERE id = ?");
                $examInfoStmt->execute([$result['exam_id']]);
                $examInfo = $examInfoStmt->fetch(PDO::FETCH_ASSOC);
                if ($examInfo) $passingPercentage = intval($examInfo['passing_percentage']);
            } catch (Exception $e) {}
            
            $passed = $newPercentage >= $passingPercentage ? 1 : 0;
            
            $encodedGrades = json_encode($essayGrades, JSON_UNESCAPED_UNICODE);
            $updateStmt->execute([
                $encodedGrades,
                $userId,
                $newPercentage,
                $newPercentage,
                $passed,
                $resultId
            ]);
            (new \EduCore\Modules\Operations\Audit\AuditService($db))->recordEvent(
                'grade', 'online_exam_result', $resultId,
                (string)($result['student_name'] ?? ('نتيجة #' . $resultId)),
                [
                    'exam_id' => (int)$result['exam_id'],
                    'grader_id' => (int)$userId,
                    'essay_item_count' => count($essayGrades),
                    'grades_fingerprint' => hash('sha256', (string)$encodedGrades),
                    'percentage_before' => (float)$result['percentage'],
                    'percentage_after' => $newPercentage,
                    'passed_before' => (bool)$result['passed'],
                    'passed_after' => (bool)$passed,
                    'undo_policy' => 'exam_grading_review_required',
                ]
            );
            $db->commit();
            // إرسال إشعار (اختياري)
            try {
                $notifStmt = $db->prepare("
                    INSERT INTO ai_lesson_notifications (user_id, type, title, message, reference_id, reference_type)
                    VALUES (?, 'essay_graded', ?, ?, ?, 'result')
                ");
                // لا نرسل إشعار للطالب لأنه لا يملك حساب
            } catch (Exception $e) {}
            
            echo json_encode([
                'success' => true,
                'message' => 'تم حفظ التصحيح بنجاح',
                'new_percentage' => $newPercentage,
                'passed' => $passed
            ]);
            break;
            
        default:
            echo json_encode(['success' => false, 'message' => 'إجراء غير معروف']);
    }
    
} catch (Throwable $e) {
    if (isset($db) && $db instanceof PDO && $db->inTransaction()) {
        $db->rollBack();
    }
    error_log("Essay Grading Error: " . $e->getMessage());
    echo json_encode(['success' => false, 'message' => 'تعذر حفظ تصحيح الأسئلة المقالية']);
}
