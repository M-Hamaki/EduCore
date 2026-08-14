<?php
/**
 * نقطة نهاية AJAX لنظام التراجع عن التغييرات (CTRL+Z)
 */
if (session_status() == PHP_SESSION_NONE) {
    session_start();
}

header('Content-Type: application/json; charset=utf-8');

// التحقق من تسجيل الدخول
if (!isset($_SESSION['user_id'])) {
    http_response_code(401);
    echo json_encode(['success' => false, 'message' => 'غير مصرح'], JSON_UNESCAPED_UNICODE);
    exit;
}

// التحقق من CSRF
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $csrf = $_POST['csrf_token'] ?? $_SERVER['HTTP_X_CSRF_TOKEN'] ?? '';
    if (empty($csrf) || !isset($_SESSION['csrf_token']) || !hash_equals($_SESSION['csrf_token'], $csrf)) {
        http_response_code(403);
        echo json_encode(['success' => false, 'message' => 'رمز الحماية غير صالح'], JSON_UNESCAPED_UNICODE);
        exit;
    }
}

try {
    require_once __DIR__ . '/../config/database.php';
    require_once __DIR__ . '/../classes/UndoManager.php';

    $database = new Database();
    $db = $database->getConnection();
    UndoManager::setDb($db);

    $action = $_GET['action'] ?? $_POST['action'] ?? '';
    $userId = (int)$_SESSION['user_id'];

    switch ($action) {
        case 'undo':
            if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
                http_response_code(405);
                echo json_encode(['success' => false, 'message' => 'طريقة غير مسموحة'], JSON_UNESCAPED_UNICODE);
                exit;
            }
            $undoId = filter_input(INPUT_POST, 'undo_id', FILTER_VALIDATE_INT);
            if (!$undoId) {
                http_response_code(422);
                echo json_encode(['success' => false, 'message' => 'معرّف عملية التراجع غير صالح'], JSON_UNESCAPED_UNICODE);
                exit;
            }
            $result = UndoManager::undo($userId, $undoId, false, UndoManager::quickUndoMinutes());
            echo json_encode($result, JSON_UNESCAPED_UNICODE);
            break;

        case 'check':
            // إشعار لمرة واحدة مرتبط بعملية أنشأها المستخدم في جلسته الحالية.
            $notice = $_SESSION['pending_undo_notice'] ?? null;
            unset($_SESSION['pending_undo_notice']);
            $noticeId = is_array($notice) && (int) ($notice['user_id'] ?? 0) === $userId
                ? (int) ($notice['id'] ?? 0)
                : 0;
            $entry = $noticeId > 0 ? UndoManager::getQuickUndoable($userId, $noticeId) : null;
            if ($entry) {
                $actionLabels = ['insert' => 'إضافة', 'update' => 'تعديل', 'delete' => 'حذف'];
                echo json_encode([
                    'success' => true,
                    'has_undo' => true,
                    'id' => (int)$entry['id'],
                    'description' => $entry['description'] ?: ($actionLabels[$entry['action_type']] ?? ''),
                    'action_type' => $entry['action_type'],
                    'expires_in' => (int) $entry['quick_undo_expires_in']
                ], JSON_UNESCAPED_UNICODE);
            } else {
                echo json_encode(['success' => true, 'has_undo' => false], JSON_UNESCAPED_UNICODE);
            }
            break;

        default:
            http_response_code(400);
            echo json_encode(['success' => false, 'message' => 'إجراء غير معروف'], JSON_UNESCAPED_UNICODE);
    }
} catch (Throwable $e) {
    error_log('api/undo.php error: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'message' => 'تعذر إتمام عملية التراجع. يرجى المحاولة مرة أخرى.',
    ], JSON_UNESCAPED_UNICODE);
}
