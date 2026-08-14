<?php
/**
 * معالج AJAX لتصدير بنك الأسئلة
 * AJAX Handler for question bank export (Word/PDF format)
 */

require_once '../../config/database.php';
require_once '../../classes/utilities.php';
require_once '../../includes/session_config.php';
require_once '../../includes/csrf.php';
Utilities::validateSession();

header('Content-Type: application/json; charset=utf-8');

$activeRole = (string) ($_SESSION['active_role'] ?? $_SESSION['role'] ?? '');
if (!in_array($activeRole, ['teacher', 'external_teacher'], true)) {
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => 'غير مصرح لك بتصدير بنك الأسئلة'], JSON_UNESCAPED_UNICODE);
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
    
    $action = isset($_POST['action']) ? $_POST['action'] : '';
    
    switch ($action) {
        case 'export_html':
            $lessonId = isset($_POST['lesson_id']) ? intval($_POST['lesson_id']) : 0;
            $format = isset($_POST['format']) ? $_POST['format'] : 'html';
            
            if (!$lessonId) {
                echo json_encode(['success' => false, 'message' => 'معرف الدرس مطلوب']);
                exit;
            }
            
            // التحقق من ملكية الدرس
            $stmt = $db->prepare("SELECT id, subject, question_bank FROM ai_lessons WHERE id = ? AND teacher_id = ?");
            $stmt->execute([$lessonId, $teacherId]);
            $lesson = $stmt->fetch(PDO::FETCH_ASSOC);
            
            if (!$lesson) {
                echo json_encode(['success' => false, 'message' => 'الدرس غير موجود']);
                exit;
            }
            
            $questions = json_decode($lesson['question_bank'], true);
            if (empty($questions)) {
                echo json_encode(['success' => false, 'message' => 'لا توجد أسئلة في البنك']);
                exit;
            }
            
            // بناء HTML قابل للتحميل
            $html = buildQuestionBankHTML($questions, $lesson['subject']);
            
            echo json_encode([
                'success' => true,
                'html' => $html,
                'filename' => 'بنك_الأسئلة_' . preg_replace('/[^أ-يa-zA-Z0-9]/', '_', $lesson['subject']) . '.html'
            ], JSON_UNESCAPED_UNICODE);
            break;
            
        case 'export_bulk':
            // تصدير أسئلة من عدة دروس
            $lessonIds = isset($_POST['lesson_ids']) ? json_decode($_POST['lesson_ids'], true) : [];
            
            if (empty($lessonIds) || !is_array($lessonIds)) {
                echo json_encode(['success' => false, 'message' => 'اختر درساً واحداً على الأقل']);
                exit;
            }
            
            // تنظيف المعرفات
            $cleanIds = array_map('intval', $lessonIds);
            $placeholders = implode(',', array_fill(0, count($cleanIds), '?'));
            
            $stmt = $db->prepare("SELECT id, subject, question_bank FROM ai_lessons WHERE id IN ($placeholders) AND teacher_id = ?");
            $params = array_merge($cleanIds, [$teacherId]);
            $stmt->execute($params);
            $lessons = $stmt->fetchAll(PDO::FETCH_ASSOC);
            
            $allQuestions = [];
            foreach ($lessons as $l) {
                $qs = json_decode($l['question_bank'], true);
                if (!empty($qs)) {
                    $allQuestions[$l['subject']] = $qs;
                }
            }
            
            if (empty($allQuestions)) {
                echo json_encode(['success' => false, 'message' => 'لا توجد أسئلة']);
                exit;
            }
            
            $html = buildBulkQuestionBankHTML($allQuestions);
            
            echo json_encode([
                'success' => true,
                'html' => $html,
                'filename' => 'بنك_الأسئلة_المجمع.html'
            ], JSON_UNESCAPED_UNICODE);
            break;
            
        default:
            echo json_encode(['success' => false, 'message' => 'إجراء غير معروف']);
    }

} catch (Exception $e) {
    error_log("QB Export Error: " . $e->getMessage());
    echo json_encode(['success' => false, 'message' => 'حدث خطأ في التصدير']);
}

function buildQuestionBankHTML($questions, $subject) {
    $html = '<!DOCTYPE html><html dir="rtl" lang="ar"><head><meta charset="UTF-8">';
    $html .= '<title>بنك الأسئلة - ' . htmlspecialchars($subject) . '</title>';
    $html .= '<style>
        @import url("https://fonts.googleapis.com/css2?family=Cairo:wght@400;600;700&display=swap");
        body { font-family: "Cairo", sans-serif; direction: rtl; padding: 30px; background: #fff; color: #333; }
        h1 { text-align: center; color: #1a56db; border-bottom: 3px solid #1a56db; padding-bottom: 10px; }
        h2 { color: #7c3aed; margin-top: 25px; }
        .question { background: #f8fafc; border-right: 4px solid #3b82f6; padding: 15px; margin: 10px 0; border-radius: 8px; }
        .question-text { font-weight: 600; font-size: 16px; margin-bottom: 8px; }
        .choices { list-style: none; padding: 0; }
        .choices li { padding: 5px 15px; margin: 3px 0; background: #fff; border-radius: 5px; }
        .choices li.correct { background: #dcfce7; border-right: 3px solid #22c55e; font-weight: 600; }
        .answer-key { color: #059669; font-weight: 600; margin-top: 5px; font-size: 14px; }
        .essay-note { color: #b45309; font-style: italic; }
        @media print { body { padding: 10px; } .question { break-inside: avoid; } }
    </style></head><body>';
    $html .= '<h1>بنك الأسئلة — ' . htmlspecialchars($subject) . '</h1>';
    
    $typeNames = [
        'mcq' => 'اختيار من متعدد',
        'true_false' => 'صح وخطأ', 
        'short_answer' => 'إجابة قصيرة',
        'essay' => 'مقالي',
        'fill_blank' => 'أكمل',
        'matching' => 'توصيل'
    ];
    
    $grouped = [];
    foreach ($questions as $q) {
        $type = $q['type'] ?? 'other';
        $grouped[$type][] = $q;
    }
    
    foreach ($grouped as $type => $qs) {
        $typeName = $typeNames[$type] ?? $type;
        $html .= '<h2>' . htmlspecialchars($typeName) . ' (' . count($qs) . ' سؤال)</h2>';
        
        foreach ($qs as $i => $q) {
            $html .= '<div class="question">';
            $html .= '<div class="question-text">' . ($i + 1) . '. ' . htmlspecialchars($q['question'] ?? $q['text'] ?? '') . '</div>';
            
            if ($type === 'mcq' && !empty($q['choices'])) {
                $html .= '<ul class="choices">';
                foreach ($q['choices'] as $ci => $choice) {
                    $isCorrect = false;
                    if (isset($q['correct_answer'])) {
                        $isCorrect = ($choice === $q['correct_answer']) || ($ci === $q['correct_answer']);
                    }
                    $html .= '<li' . ($isCorrect ? ' class="correct"' : '') . '>';
                    $html .= chr(65 + $ci) . ') ' . htmlspecialchars($choice);
                    if ($isCorrect) $html .= ' ✓';
                    $html .= '</li>';
                }
                $html .= '</ul>';
            } elseif ($type === 'true_false') {
                $answer = $q['correct_answer'] ?? $q['answer'] ?? '';
                $html .= '<div class="answer-key">الإجابة: ' . htmlspecialchars($answer) . '</div>';
            } elseif ($type === 'essay') {
                $model = $q['model_answer'] ?? $q['answer'] ?? '';
                if ($model) {
                    $html .= '<div class="answer-key">الإجابة النموذجية: ' . htmlspecialchars($model) . '</div>';
                }
            } else {
                $answer = $q['correct_answer'] ?? $q['answer'] ?? $q['model_answer'] ?? '';
                if ($answer) {
                    $html .= '<div class="answer-key">الإجابة: ' . htmlspecialchars($answer) . '</div>';
                }
            }
            
            $html .= '</div>';
        }
    }
    
    $html .= '<script>window.onload=function(){window.print();}</script>';
    $html .= '</body></html>';
    return $html;
}

function buildBulkQuestionBankHTML($allQuestions) {
    $html = '<!DOCTYPE html><html dir="rtl" lang="ar"><head><meta charset="UTF-8">';
    $html .= '<title>بنك الأسئلة المجمع</title>';
    $html .= '<style>
        @import url("https://fonts.googleapis.com/css2?family=Cairo:wght@400;600;700&display=swap");
        body { font-family: "Cairo", sans-serif; direction: rtl; padding: 30px; background: #fff; color: #333; }
        h1 { text-align: center; color: #1a56db; }
        h2 { color: #7c3aed; border-bottom: 2px solid #7c3aed; padding-bottom: 5px; }
        h3 { color: #0891b2; }
        .question { background: #f8fafc; border-right: 4px solid #3b82f6; padding: 15px; margin: 10px 0; border-radius: 8px; }
        .question-text { font-weight: 600; margin-bottom: 8px; }
        .choices { list-style: none; padding: 0; }
        .choices li { padding: 5px 15px; margin: 3px 0; background: #fff; border-radius: 5px; }
        .choices li.correct { background: #dcfce7; border-right: 3px solid #22c55e; }
        .answer-key { color: #059669; font-weight: 600; margin-top: 5px; }
        @media print { .question { break-inside: avoid; } }
    </style></head><body>';
    $html .= '<h1>بنك الأسئلة المجمع</h1>';
    
    foreach ($allQuestions as $subject => $questions) {
        $html .= '<h2>' . htmlspecialchars($subject) . '</h2>';
        foreach ($questions as $i => $q) {
            $html .= '<div class="question">';
            $html .= '<div class="question-text">' . ($i + 1) . '. ' . htmlspecialchars($q['question'] ?? $q['text'] ?? '') . '</div>';
            if (!empty($q['choices'])) {
                $html .= '<ul class="choices">';
                foreach ($q['choices'] as $ci => $choice) {
                    $isCorrect = isset($q['correct_answer']) && ($choice === $q['correct_answer'] || $ci === $q['correct_answer']);
                    $html .= '<li' . ($isCorrect ? ' class="correct"' : '') . '>' . chr(65 + $ci) . ') ' . htmlspecialchars($choice) . ($isCorrect ? ' ✓' : '') . '</li>';
                }
                $html .= '</ul>';
            }
            $answer = $q['correct_answer'] ?? $q['answer'] ?? $q['model_answer'] ?? '';
            if ($answer && empty($q['choices'])) {
                $html .= '<div class="answer-key">الإجابة: ' . htmlspecialchars($answer) . '</div>';
            }
            $html .= '</div>';
        }
    }
    
    $html .= '<script>window.onload=function(){window.print();}</script>';
    $html .= '</body></html>';
    return $html;
}
