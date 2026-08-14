<?php
/**
 * AJAX endpoint to dismiss/mark notifications as read
 * Used by student and teacher pages
 */
header('Content-Type: application/json; charset=utf-8');

require_once '../config/database.php';
require_once '../includes/session_config.php';
require_once '../includes/csrf.php';
require_once '../classes/ActivityLog.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST' || !isset($_POST['action']) || $_POST['action'] !== 'dismiss_notification') {
    echo json_encode(['success' => false, 'error' => 'Invalid request']);
    exit;
}

if (!isset($_SESSION['user_id'])) {
    echo json_encode(['success' => false, 'error' => 'Not authenticated']);
    exit;
}

requireCsrfPost();

$notification_id = intval($_POST['notification_id'] ?? 0);
if ($notification_id <= 0) {
    echo json_encode(['success' => false, 'error' => 'Invalid notification ID']);
    exit;
}

try {
    $database = new Database();
    $db = $database->getConnection();
    ActivityLog::setDb($db);
    $db->beginTransaction();
    
    $stmt = $db->prepare("INSERT IGNORE INTO notification_reads (notification_id, user_id) VALUES (?, ?)");
    $stmt->execute([$notification_id, $_SESSION['user_id']]);
    if (!ActivityLog::log('read', 'notification', $notification_id, 'قراءة إشعار', [
        'user_id' => (int) $_SESSION['user_id'],
    ])) {
        throw new RuntimeException('Notification read audit failed.');
    }
    $db->commit();
    
    echo json_encode(['success' => true]);
} catch (Exception $e) {
    if (isset($db) && $db instanceof PDO && $db->inTransaction()) {
        $db->rollBack();
    }
    error_log('dismiss_notification error: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => 'تعذر تحديث حالة الإشعار'], JSON_UNESCAPED_UNICODE);
}
