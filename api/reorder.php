<?php
/**
 * AJAX Reorder API
 * Handles moving items up/down in subjects, grades, and classes tables
 * 
 * POST Parameters:
 *   - type: 'subject' | 'grade' | 'class'
 *   - id: item ID
 *   - direction: 'up' | 'down'
 */

require_once '../config/database.php';
require_once '../includes/session_config.php';
require_once '../includes/csrf.php';
require_once '../classes/ActivityLog.php';

header('Content-Type: application/json; charset=utf-8');

// Authentication & authorization check
if (!isset($_SESSION['user_id']) || !isset($_SESSION['role']) || $_SESSION['role'] !== 'admin') {
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => 'غير مصرح']);
    exit;
}

// Only accept POST
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo json_encode(['success' => false, 'message' => 'طريقة طلب غير مسموح بها']);
    exit;
}

requireCsrfPost();

$type = $_POST['type'] ?? '';
$id = intval($_POST['id'] ?? 0);
$direction = $_POST['direction'] ?? '';

if (!in_array($type, ['subject', 'grade', 'class']) || $id <= 0 || !in_array($direction, ['up', 'down'])) {
    echo json_encode(['success' => false, 'message' => 'بيانات غير صالحة']);
    exit;
}

try {
    $database = new Database();
    $db = $database->getConnection();
    ActivityLog::setDb($db);
    $db->exec("SET NAMES 'utf8mb4'");

    // Configuration per type
    $config = [
        'subject' => [
            'table' => 'subjects',
            'order_col' => 'sort_order',
            'scope_col' => null, // no scope grouping
        ],
        'grade' => [
            'table' => 'grades',
            'order_col' => 'grade_order',
            'scope_col' => null,
        ],
        'class' => [
            'table' => 'classes',
            'order_col' => 'display_order',
            'scope_col' => null,
        ],
    ];

    $cfg = $config[$type];
    $table = $cfg['table'];
    $orderCol = $cfg['order_col'];

    // Get current item's order value
    $stmt = $db->prepare("SELECT id, {$orderCol} FROM {$table} WHERE id = ?");
    $stmt->execute([$id]);
    $current = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$current) {
        echo json_encode(['success' => false, 'message' => 'العنصر غير موجود']);
        exit;
    }

    $currentOrder = (int)$current[$orderCol];

    // Find the adjacent item to swap with
    if ($direction === 'up') {
        // Find the item with the closest lower order value
        $stmt = $db->prepare("SELECT id, {$orderCol} FROM {$table} WHERE {$orderCol} < ? ORDER BY {$orderCol} DESC LIMIT 1");
        $stmt->execute([$currentOrder]);
    } else {
        // Find the item with the closest higher order value
        $stmt = $db->prepare("SELECT id, {$orderCol} FROM {$table} WHERE {$orderCol} > ? ORDER BY {$orderCol} ASC LIMIT 1");
        $stmt->execute([$currentOrder]);
    }

    $adjacent = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$adjacent) {
        // Already at the top/bottom
        $msg = $direction === 'up' ? 'العنصر في أعلى القائمة بالفعل' : 'العنصر في أسفل القائمة بالفعل';
        echo json_encode(['success' => false, 'message' => $msg]);
        exit;
    }

    $adjacentOrder = (int)$adjacent[$orderCol];

    // Swap order values
    $db->beginTransaction();
    
    $stmt = $db->prepare("UPDATE {$table} SET {$orderCol} = ? WHERE id = ?");
    $stmt->execute([$adjacentOrder, $id]);
    
    $stmt = $db->prepare("UPDATE {$table} SET {$orderCol} = ? WHERE id = ?");
    $stmt->execute([$currentOrder, $adjacent['id']]);

    if (!ActivityLog::logUpdate($type, $id, 'تغيير ترتيب', [
        'direction' => $direction,
        'changes' => [
            $orderCol => ['from' => $currentOrder, 'to' => $adjacentOrder],
        ],
        'related_changes' => [
            'adjacent_id' => ['from' => (int) $adjacent['id'], 'to' => (int) $adjacent['id']],
            'adjacent_order' => ['from' => $adjacentOrder, 'to' => $currentOrder],
        ],
    ])) {
        throw new RuntimeException('Reorder audit failed.');
    }
    
    $db->commit();

    echo json_encode(['success' => true, 'message' => 'تم تغيير الترتيب بنجاح']);

} catch (Exception $e) {
    if (isset($db) && $db->inTransaction()) {
        $db->rollBack();
    }
    error_log('api/reorder.php error: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'تعذر تغيير الترتيب'], JSON_UNESCAPED_UNICODE);
}
