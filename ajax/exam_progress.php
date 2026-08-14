<?php
/**
 * معالج AJAX لحفظ تلقائي لتقدم الطالب في الامتحان
 * AJAX Handler for student exam progress auto-save
 */

ini_set('display_errors', 0);
error_reporting(E_ALL);
header('Content-Type: application/json; charset=utf-8');
require_once '../includes/session_config.php';
require_once '../includes/csrf.php';

// لا يحتاج تسجيل دخول — الامتحانات عامة
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo json_encode(['success' => false, 'message' => 'طريقة طلب غير صالحة']);
    exit;
}

try {
    require_once '../config/database.php';
    require_once '../classes/SchemaReadinessGuard.php';
    require_once '../src/Modules/Operations/Audit/AuditService.php';
    
    $input = json_decode(file_get_contents('php://input'), true);
    requireCsrfToken(is_array($input) ? ($input['csrf_token'] ?? '') : '');
    
    if (!$input) {
        echo json_encode(['success' => false, 'message' => 'بيانات غير صالحة']);
        exit;
    }
    
    $action = isset($input['action']) ? $input['action'] : '';
    
    switch ($action) {
        case 'save_progress':
            $examCode = isset($input['exam_code']) ? trim($input['exam_code']) : '';
            $studentName = isset($input['student_name']) ? trim($input['student_name']) : '';
            $sessionId = isset($input['session_id']) ? trim($input['session_id']) : '';
            $answers = isset($input['answers']) ? $input['answers'] : [];
            $timeRemaining = isset($input['time_remaining']) ? intval($input['time_remaining']) : 0;
            
            if (empty($examCode) || empty($studentName) || empty($sessionId)) {
                echo json_encode(['success' => false, 'message' => 'بيانات غير مكتملة']);
                exit;
            }
            
            $database = new Database();
            $db = $database->getConnection();
            (new SchemaReadinessGuard($db))->assertTable('ai_exam_progress');
            
            // التحقق من وجود الامتحان
            $examStmt = $db->prepare("SELECT id FROM ai_online_exams WHERE exam_code = ? AND is_active = 1");
            $examStmt->execute([$examCode]);
            if (!$examStmt->fetch()) {
                echo json_encode(['success' => false, 'message' => 'الامتحان غير موجود']);
                exit;
            }

            $db->beginTransaction();
            $beforeStmt = $db->prepare("SELECT * FROM ai_exam_progress WHERE exam_code = ? AND session_id = ? FOR UPDATE");
            $beforeStmt->execute([$examCode, $sessionId]);
            $before = $beforeStmt->fetch(PDO::FETCH_ASSOC) ?: null;
            $encodedAnswers = json_encode($answers, JSON_UNESCAPED_UNICODE);
            $saveStmt = $db->prepare("
                INSERT INTO ai_exam_progress (exam_code, session_id, student_name, student_class, model_letter, answers_data, time_remaining)
                VALUES (?, ?, ?, ?, ?, ?, ?)
                ON DUPLICATE KEY UPDATE 
                    answers_data = VALUES(answers_data),
                    time_remaining = VALUES(time_remaining),
                    updated_at = NOW()
            ");
            $saveStmt->execute([
                $examCode,
                $sessionId,
                $studentName,
                $input['student_class'] ?? '',
                $input['model_letter'] ?? 'A',
                $encodedAnswers,
                $timeRemaining
            ]);
            $afterStmt = $db->prepare("SELECT id, time_remaining FROM ai_exam_progress WHERE exam_code = ? AND session_id = ?");
            $afterStmt->execute([$examCode, $sessionId]);
            $after = $afterStmt->fetch(PDO::FETCH_ASSOC);
            if (!$after) {
                throw new RuntimeException('Saved exam progress could not be reloaded.');
            }
            (new \EduCore\Modules\Operations\Audit\AuditService($db))->recordEvent(
                $before ? 'update' : 'create',
                'exam_progress',
                (int)$after['id'],
                $studentName,
                [
                    'exam_code' => $examCode,
                    'session_fingerprint' => hash('sha256', $sessionId),
                    'answer_count' => is_array($answers) ? count($answers) : 0,
                    'answers_fingerprint' => hash('sha256', (string)$encodedAnswers),
                    'time_remaining_before' => $before ? (int)$before['time_remaining'] : null,
                    'time_remaining_after' => (int)$after['time_remaining'],
                    'undo_policy' => 'public_exam_progress_restore_not_enabled',
                ]
            );
            $db->commit();
            echo json_encode(['success' => true]);
            break;
            
        case 'load_progress':
            $examCode = isset($input['exam_code']) ? trim($input['exam_code']) : '';
            $sessionId = isset($input['session_id']) ? trim($input['session_id']) : '';
            
            if (empty($examCode) || empty($sessionId)) {
                echo json_encode(['success' => false, 'message' => 'بيانات غير مكتملة']);
                exit;
            }
            
            $database = new Database();
            $db = $database->getConnection();
            
            // التحقق من وجود الجدول
            try {
                $stmt = $db->prepare("SELECT answers_data, time_remaining, student_name, student_class, model_letter FROM ai_exam_progress WHERE exam_code = ? AND session_id = ?");
                $stmt->execute([$examCode, $sessionId]);
                $progress = $stmt->fetch(PDO::FETCH_ASSOC);
                
                if ($progress) {
                    echo json_encode([
                        'success' => true,
                        'has_progress' => true,
                        'answers' => json_decode($progress['answers_data'], true),
                        'time_remaining' => intval($progress['time_remaining']),
                        'student_name' => $progress['student_name'],
                        'student_class' => $progress['student_class'],
                        'model_letter' => $progress['model_letter']
                    ], JSON_UNESCAPED_UNICODE);
                } else {
                    echo json_encode(['success' => true, 'has_progress' => false]);
                }
            } catch (Exception $e) {
                echo json_encode(['success' => true, 'has_progress' => false]);
            }
            break;
            
        case 'clear_progress':
            $examCode = isset($input['exam_code']) ? trim($input['exam_code']) : '';
            $sessionId = isset($input['session_id']) ? trim($input['session_id']) : '';
            
            if (!empty($examCode) && !empty($sessionId)) {
                $database = new Database();
                $db = $database->getConnection();
                $db->beginTransaction();
                $beforeStmt = $db->prepare("SELECT * FROM ai_exam_progress WHERE exam_code = ? AND session_id = ? FOR UPDATE");
                $beforeStmt->execute([$examCode, $sessionId]);
                $before = $beforeStmt->fetch(PDO::FETCH_ASSOC) ?: null;
                $stmt = $db->prepare("DELETE FROM ai_exam_progress WHERE exam_code = ? AND session_id = ?");
                $stmt->execute([$examCode, $sessionId]);
                if ($before) {
                    $decodedAnswers = json_decode((string)$before['answers_data'], true);
                    (new \EduCore\Modules\Operations\Audit\AuditService($db))->recordEvent(
                        'delete', 'exam_progress', (int)$before['id'], (string)$before['student_name'],
                        [
                            'exam_code' => $examCode,
                            'session_fingerprint' => hash('sha256', $sessionId),
                            'answer_count' => is_array($decodedAnswers) ? count($decodedAnswers) : 0,
                            'answers_fingerprint' => hash('sha256', (string)$before['answers_data']),
                            'undo_policy' => 'public_exam_progress_restore_not_enabled',
                        ]
                    );
                }
                $db->commit();
            }
            
            echo json_encode(['success' => true]);
            break;
            
        default:
            echo json_encode(['success' => false, 'message' => 'إجراء غير معروف']);
    }

} catch (Throwable $e) {
    if (isset($db) && $db instanceof PDO && $db->inTransaction()) {
        $db->rollBack();
    }
    error_log("Exam Progress Error: " . $e->getMessage());
    echo json_encode(['success' => false, 'message' => 'حدث خطأ']);
}
