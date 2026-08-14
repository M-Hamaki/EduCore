<?php
header('Content-Type: application/json');
require_once '../includes/session_config.php';
require_once '../config/database.php';
require_once '../classes/utilities.php';
require_once '../includes/http_helpers.php';
require_once '../src/Modules/Operations/Audit/AuditService.php';

// التحقق من صلاحية الأدمن
if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'admin') {
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => 'غير مصرح']);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    requireCsrfToken();
}

$database = new Database();
$db = $database->getConnection();

// Set UTF-8
$db->exec("SET NAMES 'utf8mb4'");
$db->exec("SET CHARACTER SET utf8mb4");

$action = $_GET['action'] ?? $_POST['action'] ?? '';

function updateClassGrade(PDO $db, int $classId, ?int $gradeId): array
{
    if ($classId <= 0) {
        return ['success' => false, 'message' => 'معرف الفصل غير صالح'];
    }
    try {
        $db->beginTransaction();
        $oldStmt = $db->prepare('SELECT * FROM classes WHERE id = ? FOR UPDATE');
        $oldStmt->execute([$classId]);
        $before = $oldStmt->fetch(PDO::FETCH_ASSOC);
        if (!$before) {
            throw new RuntimeException('Class not found.');
        }
        $update = $db->prepare('UPDATE classes SET grade_id = ? WHERE id = ?');
        $update->execute([$gradeId, $classId]);
        $newStmt = $db->prepare('SELECT * FROM classes WHERE id = ?');
        $newStmt->execute([$classId]);
        $after = $newStmt->fetch(PDO::FETCH_ASSOC) ?: [];
        (new \EduCore\Modules\Operations\Audit\AuditService($db))->recordUpdate(
            'class', 'classes', $classId, (string)($before['name'] ?? ('فصل #' . $classId)),
            $before, $after, $gradeId === null ? 'إلغاء ربط الفصل بالصف' : 'ربط الفصل بالصف'
        );
        $db->commit();
        return ['success' => true];
    } catch (Throwable $e) {
        if ($db->inTransaction()) {
            $db->rollBack();
        }
        error_log('grades_ajax class assignment error: ' . $e->getMessage());
        return ['success' => false, 'message' => 'تعذر تحديث ربط الفصل بالصف'];
    }
}

if ($action === 'get_classes') {
    $grade_id = intval($_GET['grade_id']);
    
    // Get assigned classes for this grade
    $query = "SELECT id, name FROM classes WHERE grade_id = ? ORDER BY name";
    $stmt = $db->prepare($query);
    $stmt->execute([$grade_id]);
    $assigned = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // Get available classes (not assigned to any grade)
    $query = "SELECT id, name FROM classes WHERE grade_id IS NULL ORDER BY name";
    $stmt = $db->prepare($query);
    $stmt->execute();
    $available = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    echo json_encode([
        'assigned' => $assigned,
        'available' => $available
    ]);
    exit;
}

if ($action === 'assign_class') {
    $data = json_decode(file_get_contents('php://input'), true);
    $class_id = intval($data['class_id']);
    $grade_id = intval($data['grade_id']);
    
    echo json_encode(updateClassGrade($db, $class_id, $grade_id), JSON_UNESCAPED_UNICODE);
    exit;
}

if ($action === 'remove_class') {
    $data = json_decode(file_get_contents('php://input'), true);
    $class_id = intval($data['class_id']);
    
    echo json_encode(updateClassGrade($db, $class_id, null), JSON_UNESCAPED_UNICODE);
    exit;
}

echo json_encode(['success' => false, 'message' => 'Invalid action']);
?>
