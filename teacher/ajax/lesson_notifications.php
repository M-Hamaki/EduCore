<?php
/**
 * معالج AJAX لإشعارات أداة الدروس
 * AJAX Handler for lesson tool notifications
 */

ini_set('display_errors', 0);
error_reporting(E_ALL);
header('Content-Type: application/json; charset=utf-8');

require_once '../../includes/session_config.php';
require_once '../../config/database.php';
require_once '../../includes/http_helpers.php';
require_once '../../src/Modules/Operations/Audit/AuditService.php';

if (!isset($_SESSION['user_id'])) {
    echo json_encode(['success' => false, 'message' => 'غير مصرح لك بالوصول']);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    requireCsrfToken();
}

try {
    $database = new Database();
    $db = $database->getConnection();
    
    $userId = $_SESSION['user_id'];
    $action = isset($_REQUEST['action']) ? trim($_REQUEST['action']) : 'list';
    
    switch ($action) {
        case 'list':
            $limit = isset($_GET['limit']) ? intval($_GET['limit']) : 20;
            $stmt = $db->prepare("
                SELECT * FROM ai_lesson_notifications 
                WHERE user_id = ? 
                ORDER BY created_at DESC 
                LIMIT ?
            ");
            $stmt->bindValue(1, $userId, PDO::PARAM_INT);
            $stmt->bindValue(2, $limit, PDO::PARAM_INT);
            $stmt->execute();
            $notifications = $stmt->fetchAll(PDO::FETCH_ASSOC);
            
            $unreadStmt = $db->prepare("SELECT COUNT(*) FROM ai_lesson_notifications WHERE user_id = ? AND is_read = 0");
            $unreadStmt->execute([$userId]);
            $unreadCount = $unreadStmt->fetchColumn();
            
            echo json_encode([
                'success' => true,
                'notifications' => $notifications,
                'unread_count' => intval($unreadCount)
            ], JSON_UNESCAPED_UNICODE);
            break;
            
        case 'mark_read':
            if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
                echo json_encode(['success' => false, 'message' => 'طريقة طلب غير صالحة']);
                exit;
            }
            $notifId = isset($_POST['notification_id']) ? intval($_POST['notification_id']) : 0;
            if ($notifId) {
                $db->beginTransaction();
                $beforeStmt = $db->prepare("SELECT id, type, title, is_read FROM ai_lesson_notifications WHERE id = ? AND user_id = ? FOR UPDATE");
                $beforeStmt->execute([$notifId, $userId]);
                $before = $beforeStmt->fetch(PDO::FETCH_ASSOC);
                $stmt = $db->prepare("UPDATE ai_lesson_notifications SET is_read = 1 WHERE id = ? AND user_id = ?");
                $stmt->execute([$notifId, $userId]);
                if ($before && !(bool)$before['is_read']) {
                    (new \EduCore\Modules\Operations\Audit\AuditService($db))->recordEvent(
                        'read', 'lesson_notification', $notifId, (string)$before['title'],
                        [
                            'notification_type' => $before['type'],
                            'undo_policy' => 'notification_read_state_not_undoable',
                        ]
                    );
                }
                $db->commit();
            }
            echo json_encode(['success' => true]);
            break;
            
        case 'mark_all_read':
            if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
                echo json_encode(['success' => false, 'message' => 'طريقة طلب غير صالحة']);
                exit;
            }
            $db->beginTransaction();
            $idsStmt = $db->prepare("SELECT id FROM ai_lesson_notifications WHERE user_id = ? AND is_read = 0 FOR UPDATE");
            $idsStmt->execute([$userId]);
            $ids = array_map('intval', $idsStmt->fetchAll(PDO::FETCH_COLUMN));
            $stmt = $db->prepare("UPDATE ai_lesson_notifications SET is_read = 1 WHERE user_id = ? AND is_read = 0");
            $stmt->execute([$userId]);
            if ($ids) {
                (new \EduCore\Modules\Operations\Audit\AuditService($db))->recordEvent(
                    'read_all', 'lesson_notification_batch', null, 'تعليم إشعارات الدروس كمقروءة',
                    [
                        'count' => count($ids),
                        'ids_fingerprint' => hash('sha256', json_encode($ids)),
                        'undo_policy' => 'notification_read_state_not_undoable',
                    ]
                );
            }
            $db->commit();
            echo json_encode(['success' => true, 'message' => 'تم تعليم جميع الإشعارات كمقروءة']);
            break;
            
        case 'check_new_results':
            // التحقق من وجود نتائج جديدة لامتحانات المعلم
            if (!in_array($_SESSION['role'], ['teacher', 'external_teacher'])) {
                echo json_encode(['success' => true, 'new_results' => 0]);
                exit;
            }
            
            $lastCheck = isset($_GET['since']) ? $_GET['since'] : date('Y-m-d H:i:s', strtotime('-1 hour'));
            
            $stmt = $db->prepare("
                SELECT COUNT(*) as cnt
                FROM ai_exam_results r
                JOIN ai_online_exams e ON r.exam_id = e.id
                WHERE e.teacher_id = ? AND r.created_at > ?
            ");
            $stmt->execute([$userId, $lastCheck]);
            $count = $stmt->fetch(PDO::FETCH_ASSOC);
            
            if ($count && $count['cnt'] > 0) {
                $db->beginTransaction();
                // إنشاء إشعار تلقائي
                $existingStmt = $db->prepare("
                    SELECT id FROM ai_lesson_notifications 
                    WHERE user_id = ? AND type = 'exam_result' AND is_read = 0 
                    AND created_at > DATE_SUB(NOW(), INTERVAL 1 HOUR)
                    FOR UPDATE
                ");
                $existingStmt->execute([$userId]);
                
                if (!$existingStmt->fetch()) {
                    $notifStmt = $db->prepare("
                        INSERT INTO ai_lesson_notifications (user_id, type, title, message)
                        VALUES (?, 'exam_result', ?, ?)
                    ");
                    $notifStmt->execute([
                        $userId,
                        'نتائج امتحان جديدة',
                        'وصلت ' . $count['cnt'] . ' إجابة جديدة على امتحاناتك'
                    ]);
                    $notificationId = (int)$db->lastInsertId();
                    (new \EduCore\Modules\Operations\Audit\AuditService($db))->recordEvent(
                        'create', 'lesson_notification', $notificationId, 'نتائج امتحان جديدة',
                        [
                            'notification_type' => 'exam_result',
                            'new_result_count' => (int)$count['cnt'],
                            'undo_policy' => 'generated_notification_not_undoable',
                        ]
                    );
                }
                $db->commit();
            }
            
            echo json_encode(['success' => true, 'new_results' => intval($count['cnt'] ?? 0)]);
            break;
            
        default:
            echo json_encode(['success' => false, 'message' => 'إجراء غير معروف']);
    }

} catch (Throwable $e) {
    if (isset($db) && $db instanceof PDO && $db->inTransaction()) {
        $db->rollBack();
    }
    error_log("Notification Error: " . $e->getMessage());
    echo json_encode(['success' => false, 'message' => 'حدث خطأ']);
}
