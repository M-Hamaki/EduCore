<?php
/**
 * معالج AJAX — مساعد AI للمعلم (شات بوت)
 * AJAX Handler — AI Teaching Assistant Chatbot
 * يستخدم Ollama محلياً (مجاني بلا حدود)
 */
ini_set('display_errors', 0);
error_reporting(E_ALL);

header('Content-Type: application/json; charset=utf-8');

require_once '../../includes/session_config.php';
require_once '../../config/database.php';
require_once '../../config/ai_config.php';
require_once '../../classes/AIProvider.php';
require_once '../../includes/http_helpers.php';
require_once '../../src/Modules/Operations/Audit/AuditService.php';

// التحقق من تسجيل الدخول
if (!isset($_SESSION['user_id']) || !in_array($_SESSION['role'], ['teacher', 'external_teacher'])) {
    echo json_encode(['success' => false, 'message' => 'غير مصرح لك بالوصول']);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    requireCsrfToken();
}

$teacherId = $_SESSION['user_id'];
$teacherName = $_SESSION['name'] ?? 'معلم';

$database = new Database();
$db = $database->getConnection();

$action = $_POST['action'] ?? $_GET['action'] ?? '';

// ==========================================
// إرسال رسالة للمساعد
// ==========================================
if ($action === 'send_message') {
    $message = trim($_POST['message'] ?? '');
    $conversationId = intval($_POST['conversation_id'] ?? 0);
    
    if (empty($message)) {
        echo json_encode(['success' => false, 'message' => 'يرجى كتابة رسالة']);
        exit;
    }
    
    if (mb_strlen($message) > 5000) {
        echo json_encode(['success' => false, 'message' => 'الرسالة طويلة جداً (الحد الأقصى 5000 حرف)']);
        exit;
    }
    
    $createdConversation = false;
    try {
        $db->beginTransaction();
        if ($conversationId <= 0) {
            $title = mb_substr($message, 0, 80);
            $stmt = $db->prepare("INSERT INTO ai_chat_conversations (teacher_id, title) VALUES (?, ?)");
            $stmt->execute([$teacherId, $title]);
            $conversationId = (int) $db->lastInsertId();
            $createdConversation = true;
        } else {
            $stmt = $db->prepare("SELECT id FROM ai_chat_conversations WHERE id = ? AND teacher_id = ? FOR UPDATE");
            $stmt->execute([$conversationId, $teacherId]);
            if (!$stmt->fetch()) {
                throw new RuntimeException('conversation_not_found');
            }
        }

        $stmt = $db->prepare("INSERT INTO ai_chat_messages (conversation_id, role, content) VALUES (?, 'user', ?)");
        $stmt->execute([$conversationId, $message]);
        (new \EduCore\Modules\Operations\Audit\AuditService($db))->recordEvent(
            'ai_chat_message_saved',
            'ai_chat_conversation',
            $conversationId,
            'محادثة المساعد الذكي',
            [
                'role' => 'user',
                'conversation_created' => $createdConversation,
                'content_length' => mb_strlen($message),
                'content_sha256' => hash('sha256', $message),
            ]
        );
        $db->commit();
    } catch (Throwable $e) {
        if ($db->inTransaction()) $db->rollBack();
        error_log('AI chat user message persistence error: ' . $e->getMessage());
        $messageText = $e->getMessage() === 'conversation_not_found'
            ? 'المحادثة غير موجودة'
            : 'تعذر حفظ الرسالة بأمان';
        echo json_encode(['success' => false, 'message' => $messageText]);
        exit;
    }
    
    // جلب آخر 10 رسائل للسياق
    $stmt = $db->prepare("
        SELECT role, content FROM ai_chat_messages 
        WHERE conversation_id = ? 
        ORDER BY id DESC LIMIT 10
    ");
    $stmt->execute([$conversationId]);
    $history = array_reverse($stmt->fetchAll(PDO::FETCH_ASSOC));
    
    // بناء رسائل المحادثة
    $messages = [];
    $messages[] = [
        'role' => 'system',
        'content' => 'أنت مساعد تعليمي ذكي يساعد المعلمين في المدارس العربية. تجيب باللغة العربية بشكل واضح ومختصر. تساعد في: التخطيط للدروس، استراتيجيات التدريس، إدارة الصف، التقييم، التعامل مع الطلاب، الأنشطة التعليمية. اسم المعلم: ' . $teacherName
    ];
    
    foreach ($history as $msg) {
        $messages[] = [
            'role' => $msg['role'] === 'user' ? 'user' : 'assistant',
            'content' => $msg['content']
        ];
    }
    
    // إرسال للـ AI (Ollama — مجاني)
    $ollama = new OllamaAI($db);
    
    if (!$ollama->isAvailable()) {
        echo json_encode([
            'success' => false, 
            'message' => 'خدمة المساعد الذكي غير متاحة حالياً. تأكد من تشغيل Ollama على الخادم.'
        ]);
        exit;
    }
    
    $startTime = microtime(true);
    $response = $ollama->chat($messages, [
        'temperature' => 0.7,
        'maxTokens' => 2048
    ]);
    $timeMs = round((microtime(true) - $startTime) * 1000);
    
    if ($response === null) {
        echo json_encode(['success' => false, 'message' => 'فشل في الحصول على رد: ' . $ollama->getLastError()]);
        exit;
    }
    
    try {
        $db->beginTransaction();
        $stmt = $db->prepare("SELECT id FROM ai_chat_conversations WHERE id = ? AND teacher_id = ? FOR UPDATE");
        $stmt->execute([$conversationId, $teacherId]);
        if (!$stmt->fetch()) throw new RuntimeException('Conversation disappeared before response persistence.');

        $stmt = $db->prepare("INSERT INTO ai_chat_messages (conversation_id, role, content) VALUES (?, 'assistant', ?)");
        $stmt->execute([$conversationId, $response]);
        $stmt = $db->prepare("UPDATE ai_chat_conversations SET updated_at = NOW() WHERE id = ?");
        $stmt->execute([$conversationId]);
        $ollama->logUsage($teacherId, null, 'chat', 'success');
        (new \EduCore\Modules\Operations\Audit\AuditService($db))->recordEvent(
            'ai_chat_response_saved',
            'ai_chat_conversation',
            $conversationId,
            'محادثة المساعد الذكي',
            [
                'role' => 'assistant',
                'content_length' => mb_strlen($response),
                'content_sha256' => hash('sha256', $response),
                'response_time_ms' => $timeMs,
            ]
        );
        $db->commit();
    } catch (Throwable $e) {
        if ($db->inTransaction()) $db->rollBack();
        error_log('AI chat response persistence error: ' . $e->getMessage());
        echo json_encode(['success' => false, 'message' => 'تم توليد الرد لكن تعذر حفظه بأمان. يرجى إعادة المحاولة.']);
        exit;
    }
    
    echo json_encode([
        'success' => true,
        'response' => $response,
        'conversation_id' => $conversationId,
        'time_ms' => $timeMs
    ]);
    exit;
}

// ==========================================
// جلب المحادثات السابقة
// ==========================================
if ($action === 'get_conversations') {
    $stmt = $db->prepare("
        SELECT c.id, c.title, c.created_at, c.updated_at,
               (SELECT COUNT(*) FROM ai_chat_messages WHERE conversation_id = c.id) as message_count
        FROM ai_chat_conversations c
        WHERE c.teacher_id = ?
        ORDER BY c.updated_at DESC
        LIMIT 50
    ");
    $stmt->execute([$teacherId]);
    $conversations = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    echo json_encode(['success' => true, 'conversations' => $conversations]);
    exit;
}

// ==========================================
// جلب رسائل محادثة
// ==========================================
if ($action === 'get_messages') {
    $conversationId = intval($_GET['conversation_id'] ?? 0);
    
    $stmt = $db->prepare("SELECT id FROM ai_chat_conversations WHERE id = ? AND teacher_id = ?");
    $stmt->execute([$conversationId, $teacherId]);
    if (!$stmt->fetch()) {
        echo json_encode(['success' => false, 'message' => 'المحادثة غير موجودة']);
        exit;
    }
    
    $stmt = $db->prepare("
        SELECT role, content, created_at 
        FROM ai_chat_messages 
        WHERE conversation_id = ? 
        ORDER BY id ASC
    ");
    $stmt->execute([$conversationId]);
    $messages = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    echo json_encode(['success' => true, 'messages' => $messages]);
    exit;
}

// ==========================================
// حذف محادثة
// ==========================================
if ($action === 'delete_conversation') {
    $conversationId = intval($_POST['conversation_id'] ?? 0);
    
    try {
        $db->beginTransaction();
        $stmt = $db->prepare("SELECT id FROM ai_chat_conversations WHERE id = ? AND teacher_id = ? FOR UPDATE");
        $stmt->execute([$conversationId, $teacherId]);
        if (!$stmt->fetch()) throw new RuntimeException('conversation_not_found');

        $countStmt = $db->prepare("SELECT COUNT(*) FROM ai_chat_messages WHERE conversation_id = ?");
        $countStmt->execute([$conversationId]);
        $messageCount = (int) $countStmt->fetchColumn();
        $db->prepare("DELETE FROM ai_chat_messages WHERE conversation_id = ?")->execute([$conversationId]);
        $db->prepare("DELETE FROM ai_chat_conversations WHERE id = ? AND teacher_id = ?")->execute([$conversationId, $teacherId]);
        (new \EduCore\Modules\Operations\Audit\AuditService($db))->recordEvent(
            'delete',
            'ai_chat_conversation',
            $conversationId,
            'محادثة المساعد الذكي',
            ['message_count' => $messageCount, 'direct_undo' => false, 'reason' => 'sensitive_content_deleted']
        );
        $db->commit();
    } catch (Throwable $e) {
        if ($db->inTransaction()) $db->rollBack();
        error_log('AI chat deletion error: ' . $e->getMessage());
        echo json_encode([
            'success' => false,
            'message' => $e->getMessage() === 'conversation_not_found' ? 'المحادثة غير موجودة' : 'تعذر حذف المحادثة بأمان',
        ]);
        exit;
    }
    
    echo json_encode(['success' => true, 'message' => 'تم حذف المحادثة']);
    exit;
}

echo json_encode(['success' => false, 'message' => 'إجراء غير معروف']);
