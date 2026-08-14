<?php
/**
 * API: حفظ اشتراك Push Notification
 */
header('Content-Type: application/json; charset=utf-8');

require_once '../includes/session_config.php';
require_once '../config/database.php';
require_once '../includes/csrf.php';
require_once '../classes/ActivityLog.php';

// التحقق من تسجيل الدخول
if (empty($_SESSION['user_id'])) {
    http_response_code(401);
    echo json_encode(['success' => false, 'message' => 'غير مصرح']);
    exit;
}

// إرجاع عدد الاشتراكات (GET ?count=1)
if (isset($_GET['count'])) {
    try {
        $database = new Database();
        $db = $database->getConnection();
        $count = (int) $db->query("SELECT COUNT(*) FROM push_subscriptions")->fetchColumn();
        echo json_encode(['count' => $count]);
    } catch (Exception $e) {
        echo json_encode(['count' => 0]);
    }
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'message' => 'طريقة غير مسموحة'], JSON_UNESCAPED_UNICODE);
    exit;
}
requireCsrfPost();

// قراءة البيانات
$input = json_decode(file_get_contents('php://input'), true);

if (empty($input['endpoint']) || empty($input['keys']['p256dh']) || empty($input['keys']['auth'])) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'بيانات الاشتراك غير مكتملة']);
    exit;
}

$user_id = intval($_SESSION['user_id']);
$endpoint = $input['endpoint'];
$p256dh = $input['keys']['p256dh'];
$auth = $input['keys']['auth'];
$userAgent = $_SERVER['HTTP_USER_AGENT'] ?? '';

try {
    $database = new Database();
    $db = $database->getConnection();
    ActivityLog::setDb($db);
    $db->beginTransaction();
    
    // حذف اشتراك قديم بنفس الـ endpoint
    $stmt = $db->prepare("DELETE FROM push_subscriptions WHERE endpoint = ?");
    $stmt->execute([$endpoint]);
    
    // إضافة الاشتراك الجديد
    $stmt = $db->prepare("INSERT INTO push_subscriptions (user_id, endpoint, p256dh_key, auth_key, user_agent) VALUES (?, ?, ?, ?, ?)");
    $stmt->execute([$user_id, $endpoint, $p256dh, $auth, $userAgent]);
    if (!ActivityLog::log('link', 'push_subscription', $user_id, 'تفعيل الإشعارات الفورية', [
        'user_id' => $user_id,
    ])) {
        throw new RuntimeException('Push subscription audit failed.');
    }
    $db->commit();
    
    echo json_encode(['success' => true, 'message' => 'تم تفعيل الإشعارات الفورية']);
} catch (Exception $e) {
    if (isset($db) && $db instanceof PDO && $db->inTransaction()) {
        $db->rollBack();
    }
    error_log('push_subscribe error: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'خطأ في حفظ الاشتراك']);
}
