<?php
/**
 * معالج AJAX لإدارة مجلدات الدروس
 * AJAX Handler for lesson folder management
 */

ini_set('display_errors', 0);
error_reporting(E_ALL);
header('Content-Type: application/json; charset=utf-8');

require_once '../../includes/session_config.php';
require_once '../../includes/csrf.php';
require_once '../../config/database.php';
require_once '../../src/Modules/Operations/Audit/AuditService.php';

if (!isset($_SESSION['user_id']) || !in_array($_SESSION['role'], ['teacher', 'external_teacher'])) {
    echo json_encode(['success' => false, 'message' => 'غير مصرح لك بالوصول']);
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
    $action = isset($_POST['action']) ? trim($_POST['action']) : '';
    
    switch ($action) {
        case 'list':
            $stmt = $db->prepare("
                SELECT f.*, 
                    (SELECT COUNT(*) FROM ai_lessons WHERE folder_id = f.id AND teacher_id = ?) as lesson_count
                FROM ai_lesson_folders f 
                WHERE f.teacher_id = ? 
                ORDER BY f.sort_order, f.name
            ");
            $stmt->execute([$teacherId, $teacherId]);
            $folders = $stmt->fetchAll(PDO::FETCH_ASSOC);
            
            // عدد الدروس بدون مجلد
            $noFolderStmt = $db->prepare("SELECT COUNT(*) FROM ai_lessons WHERE teacher_id = ? AND (folder_id IS NULL OR folder_id = 0)");
            $noFolderStmt->execute([$teacherId]);
            $unfolderedCount = $noFolderStmt->fetchColumn();
            
            echo json_encode([
                'success' => true, 
                'folders' => $folders,
                'unfoldered_count' => intval($unfolderedCount)
            ], JSON_UNESCAPED_UNICODE);
            break;
            
        case 'create':
            $name = isset($_POST['name']) ? trim($_POST['name']) : '';
            $color = isset($_POST['color']) ? trim($_POST['color']) : '#3b82f6';
            $icon = isset($_POST['icon']) ? trim($_POST['icon']) : 'fa-folder';
            $parentId = isset($_POST['parent_id']) ? intval($_POST['parent_id']) : null;
            
            if (empty($name)) {
                echo json_encode(['success' => false, 'message' => 'اسم المجلد مطلوب']);
                exit;
            }
            
            // التحقق من عدد المجلدات (حد أقصى 50)
            $countStmt = $db->prepare("SELECT COUNT(*) FROM ai_lesson_folders WHERE teacher_id = ?");
            $countStmt->execute([$teacherId]);
            if ($countStmt->fetchColumn() >= 50) {
                echo json_encode(['success' => false, 'message' => 'تجاوزت الحد الأقصى لعدد المجلدات (50)']);
                exit;
            }
            
            $db->beginTransaction();
            $stmt = $db->prepare("INSERT INTO ai_lesson_folders (teacher_id, name, color, icon, parent_id) VALUES (?, ?, ?, ?, ?)");
            $stmt->execute([$teacherId, $name, $color, $icon, $parentId]);
            $folderId = (int)$db->lastInsertId();
            (new \EduCore\Modules\Operations\Audit\AuditService($db))->recordEvent(
                'create', 'lesson_folder', $folderId, $name,
                [
                    'color' => $color,
                    'icon' => $icon,
                    'parent_id' => $parentId,
                    'undo_policy' => 'folder_restore_not_enabled',
                ]
            );
            $db->commit();
            echo json_encode([
                'success' => true,
                'message' => 'تم إنشاء المجلد بنجاح',
                'folder_id' => $folderId
            ]);
            break;
            
        case 'rename':
            $folderId = isset($_POST['folder_id']) ? intval($_POST['folder_id']) : 0;
            $name = isset($_POST['name']) ? trim($_POST['name']) : '';
            $color = isset($_POST['color']) ? trim($_POST['color']) : null;
            
            if (!$folderId || empty($name)) {
                echo json_encode(['success' => false, 'message' => 'بيانات غير مكتملة']);
                exit;
            }
            
            $db->beginTransaction();
            $beforeStmt = $db->prepare('SELECT * FROM ai_lesson_folders WHERE id = ? AND teacher_id = ? FOR UPDATE');
            $beforeStmt->execute([$folderId, $teacherId]);
            $before = $beforeStmt->fetch(PDO::FETCH_ASSOC);
            if (!$before) {
                throw new RuntimeException('Folder not found.');
            }
            $setClauses = ['name = ?'];
            $params = [$name];
            
            if ($color !== null) {
                $setClauses[] = 'color = ?';
                $params[] = $color;
            }
            
            $params[] = $folderId;
            $params[] = $teacherId;
            
            $stmt = $db->prepare("UPDATE ai_lesson_folders SET " . implode(', ', $setClauses) . " WHERE id = ? AND teacher_id = ?");
            $stmt->execute($params);
            $after = $before;
            $after['name'] = $name;
            if ($color !== null) {
                $after['color'] = $color;
            }
            (new \EduCore\Modules\Operations\Audit\AuditService($db))->recordEvent(
                'update', 'lesson_folder', $folderId, $name,
                [
                    'changes' => \EduCore\Modules\Operations\Audit\EntityChangeTracker::diff($before, $after),
                    'undo_policy' => 'folder_restore_not_enabled',
                ]
            );
            $db->commit();
            echo json_encode(['success' => true, 'message' => 'تم تعديل المجلد']);
            break;
            
        case 'delete':
            $folderId = isset($_POST['folder_id']) ? intval($_POST['folder_id']) : 0;
            if (!$folderId) {
                echo json_encode(['success' => false, 'message' => 'معرف المجلد مطلوب']);
                exit;
            }
            
            $db->beginTransaction();
            $folderStmt = $db->prepare('SELECT * FROM ai_lesson_folders WHERE id = ? AND teacher_id = ? FOR UPDATE');
            $folderStmt->execute([$folderId, $teacherId]);
            $folder = $folderStmt->fetch(PDO::FETCH_ASSOC);
            if (!$folder) {
                throw new RuntimeException('Folder not found.');
            }
            // نقل الدروس إلى بدون مجلد
            $moveStmt = $db->prepare("UPDATE ai_lessons SET folder_id = NULL WHERE folder_id = ? AND teacher_id = ?");
            $moveStmt->execute([$folderId, $teacherId]);
            $movedLessons = $moveStmt->rowCount();
            $stmt = $db->prepare("DELETE FROM ai_lesson_folders WHERE id = ? AND teacher_id = ?");
            $stmt->execute([$folderId, $teacherId]);
            (new \EduCore\Modules\Operations\Audit\AuditService($db))->recordEvent(
                'delete', 'lesson_folder', $folderId, (string)$folder['name'],
                [
                    'before' => $folder,
                    'detached_lesson_count' => $movedLessons,
                    'undo_policy' => 'folder_composite_restore_not_enabled',
                ]
            );
            $db->commit();
            echo json_encode(['success' => true, 'message' => 'تم حذف المجلد (الدروس لم تُحذف)']);
            break;
            
        case 'move_lesson':
            $lessonId = isset($_POST['lesson_id']) ? intval($_POST['lesson_id']) : 0;
            $folderId = isset($_POST['folder_id']) ? ($_POST['folder_id'] === '' || $_POST['folder_id'] === '0' ? null : intval($_POST['folder_id'])) : null;
            
            if (!$lessonId) {
                echo json_encode(['success' => false, 'message' => 'معرف الدرس مطلوب']);
                exit;
            }
            
            $db->beginTransaction();
            // التحقق من ملكية المجلد
            if ($folderId) {
                $checkFolder = $db->prepare("SELECT id FROM ai_lesson_folders WHERE id = ? AND teacher_id = ?");
                $checkFolder->execute([$folderId, $teacherId]);
                if (!$checkFolder->fetch()) {
                    $db->rollBack();
                    echo json_encode(['success' => false, 'message' => 'المجلد غير موجود']);
                    exit;
                }
            }
            $lessonStmt = $db->prepare('SELECT id, title, folder_id FROM ai_lessons WHERE id = ? AND teacher_id = ? FOR UPDATE');
            $lessonStmt->execute([$lessonId, $teacherId]);
            $lesson = $lessonStmt->fetch(PDO::FETCH_ASSOC);
            if (!$lesson) {
                throw new RuntimeException('Lesson not found.');
            }
            $stmt = $db->prepare("UPDATE ai_lessons SET folder_id = ? WHERE id = ? AND teacher_id = ?");
            $stmt->execute([$folderId, $lessonId, $teacherId]);
            (new \EduCore\Modules\Operations\Audit\AuditService($db))->recordEvent(
                'move', 'ai_lesson', $lessonId, (string)$lesson['title'],
                [
                    'folder_id_before' => $lesson['folder_id'],
                    'folder_id_after' => $folderId,
                    'undo_policy' => 'lesson_folder_move_restore_not_enabled',
                ]
            );
            $db->commit();
            echo json_encode(['success' => true, 'message' => 'تم نقل الدرس']);
            break;
            
        default:
            echo json_encode(['success' => false, 'message' => 'إجراء غير معروف']);
    }

} catch (Throwable $e) {
    if (isset($db) && $db instanceof PDO && $db->inTransaction()) {
        $db->rollBack();
    }
    error_log("Folder Management Error: " . $e->getMessage());
    echo json_encode(['success' => false, 'message' => 'تعذر تنفيذ عملية المجلد']);
}
