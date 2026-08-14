<?php
/**
 * API: إلغاء اشتراك Push Notification
 */
header('Content-Type: application/json; charset=utf-8');

require_once '../includes/session_config.php';
require_once '../config/database.php';
require_once '../includes/csrf.php';
require_once '../classes/ActivityLog.php';

if (empty($_SESSION['user_id'])) {
    http_response_code(401);
    echo json_encode(['success' => false, 'message' => 'غير مصرح']);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'message' => 'طريقة غير مسموحة'], JSON_UNESCAPED_UNICODE);
    exit;
}
requireCsrfPost();

$input = json_decode(file_get_contents('php://input'), true);

if (empty($input['endpoint'])) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'بيانات غير مكتملة']);
    exit;
}

try {
    $database = new Database();
    $db = $database->getConnection();
    ActivityLog::setDb($db);
    $db->beginTransaction();
    
    $stmt = $db->prepare("DELETE FROM push_subscriptions WHERE user_id = ? AND endpoint = ?");
    $stmt->execute([intval($_SESSION['user_id']), $input['endpoint']]);
    if (!ActivityLog::log('unlink', 'push_subscription', (int) $_SESSION['user_id'], 'إلغاء الإشعارات الفورية', [
        'user_id' => (int) $_SESSION['user_id'],
    ])) {
        throw new RuntimeException('Push unsubscription audit failed.');
    }
    $db->commit();
    
    echo json_encode(['success' => true, 'message' => 'تم إلغاء الإشعارات الفورية']);
} catch (Exception $e) {
    if (isset($db) && $db instanceof PDO && $db->inTransaction()) {
        $db->rollBack();
    }
    error_log('push_unsubscribe error: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'خطأ في إلغاء الاشتراك']);
}
