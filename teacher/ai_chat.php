<?php
/**
 * مساعد AI للمعلم — شات بوت تعليمي
 * AI Teaching Assistant — Chatbot
 * يعمل بـ Ollama المحلي (مجاني بلا حدود)
 */
ini_set('display_errors', 0);
error_reporting(0);

$page_title = "المساعد الذكي";
$custom_page_title = true;

require_once '../config/database.php';
require_once '../classes/utilities.php';
require_once '../includes/session_config.php';
Utilities::validateSession('teacher');

$database = new Database();
$db = $database->getConnection();

$teacherId = $_SESSION['user_id'];
$teacherName = $_SESSION['name'] ?? 'معلم';

// فحص حالة Ollama
require_once '../classes/OllamaAI.php';
$ollama = new OllamaAI($db);
$ollamaAvailable = $ollama->isAvailable();

require_once '../includes/teacher_header.php';
?>

<style>
.chat-container { display: flex; height: calc(100vh - 140px); gap: 0; background: #f8fafc; border-radius: 16px; overflow: hidden; box-shadow: 0 4px 20px rgba(0,0,0,.08); }
.chat-sidebar { width: 280px; background: linear-gradient(180deg, #1e293b 0%, #0f172a 100%); color: #fff; display: flex; flex-direction: column; flex-shrink: 0; }
.chat-sidebar-header { padding: 20px 16px; border-bottom: 1px solid rgba(255,255,255,.1); }
.chat-sidebar-header h5 { margin: 0; font-size: .95rem; }
.btn-new-chat { width: 100%; background: linear-gradient(135deg, #3b82f6, #2563eb); border: none; color: #fff; padding: 10px; border-radius: 10px; font-weight: 600; transition: all .3s; }
.btn-new-chat:hover { background: linear-gradient(135deg, #2563eb, #1d4ed8); transform: translateY(-1px); color: #fff; }
.conv-list { flex: 1; overflow-y: auto; padding: 8px; }
.conv-list::-webkit-scrollbar { width: 4px; }
.conv-list::-webkit-scrollbar-thumb { background: rgba(255,255,255,.2); border-radius: 4px; }
.conv-item { padding: 10px 12px; border-radius: 8px; cursor: pointer; margin-bottom: 4px; transition: all .2s; display: flex; align-items: center; gap: 8px; }
.conv-item:hover { background: rgba(255,255,255,.1); }
.conv-item.active { background: rgba(59,130,246,.3); }
.conv-item .conv-title { flex: 1; font-size: .85rem; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; color: #cbd5e1; }
.conv-item.active .conv-title { color: #fff; }
.conv-item .conv-delete { opacity: 0; color: #ef4444; cursor: pointer; font-size: .8rem; padding: 4px; }
.conv-item:hover .conv-delete { opacity: 1; }
.conv-item .conv-icon { color: #64748b; font-size: .8rem; }
.conv-item.active .conv-icon { color: #60a5fa; }
.chat-main { flex: 1; display: flex; flex-direction: column; background: #fff; }
.chat-messages { flex: 1; overflow-y: auto; padding: 24px; }
.chat-messages::-webkit-scrollbar { width: 6px; }
.chat-messages::-webkit-scrollbar-thumb { background: #cbd5e1; border-radius: 6px; }
.msg-row { display: flex; gap: 12px; margin-bottom: 20px; max-width: 85%; }
.msg-row.user { flex-direction: row-reverse; margin-right: 0; margin-left: auto; }
.msg-row.assistant { margin-left: 0; margin-right: auto; }
.msg-avatar { width: 36px; height: 36px; border-radius: 10px; display: flex; align-items: center; justify-content: center; font-size: 1rem; flex-shrink: 0; }
.msg-row.user .msg-avatar { background: linear-gradient(135deg, #3b82f6, #2563eb); color: #fff; }
.msg-row.assistant .msg-avatar { background: linear-gradient(135deg, #8b5cf6, #7c3aed); color: #fff; }
.msg-bubble { padding: 12px 16px; border-radius: 14px; font-size: .9rem; line-height: 1.7; white-space: pre-wrap; word-wrap: break-word; }
.msg-row.user .msg-bubble { background: linear-gradient(135deg, #3b82f6, #2563eb); color: #fff; border-bottom-left-radius: 14px; border-bottom-right-radius: 4px; }
.msg-row.assistant .msg-bubble { background: #f1f5f9; color: #1e293b; border-bottom-right-radius: 14px; border-bottom-left-radius: 4px; }
.msg-bubble strong { color: inherit; }
.msg-time { font-size: .7rem; color: #94a3b8; margin-top: 4px; text-align: left; }
.msg-row.user .msg-time { text-align: right; }
.chat-input-area { padding: 16px 24px; border-top: 1px solid #e2e8f0; background: #fff; }
.chat-input-wrap { display: flex; gap: 10px; align-items: flex-end; }
.chat-input-wrap textarea { flex: 1; border: 2px solid #e2e8f0; border-radius: 12px; padding: 12px 16px; font-size: .9rem; resize: none; max-height: 120px; transition: border-color .3s; font-family: inherit; }
.chat-input-wrap textarea:focus { outline: none; border-color: #3b82f6; box-shadow: 0 0 0 3px rgba(59,130,246,.1); }
.btn-send { width: 48px; height: 48px; border-radius: 12px; border: none; background: linear-gradient(135deg, #3b82f6, #2563eb); color: #fff; display: flex; align-items: center; justify-content: center; font-size: 1.1rem; cursor: pointer; transition: all .3s; flex-shrink: 0; }
.btn-send:hover { background: linear-gradient(135deg, #2563eb, #1d4ed8); transform: translateY(-1px); }
.btn-send:disabled { background: #94a3b8; cursor: not-allowed; transform: none; }
.typing-indicator { display: none; padding: 8px 16px; }
.typing-indicator .dots { display: inline-flex; gap: 4px; }
.typing-indicator .dots span { width: 8px; height: 8px; background: #94a3b8; border-radius: 50%; animation: typing 1.2s infinite; }
.typing-indicator .dots span:nth-child(2) { animation-delay: .2s; }
.typing-indicator .dots span:nth-child(3) { animation-delay: .4s; }
@keyframes typing { 0%,60%,100% { transform: translateY(0); } 30% { transform: translateY(-6px); } }
.welcome-screen { display: flex; flex-direction: column; align-items: center; justify-content: center; height: 100%; color: #64748b; text-align: center; padding: 40px; }
.welcome-screen i { font-size: 4rem; color: #8b5cf6; margin-bottom: 20px; opacity: .6; }
.welcome-screen h3 { color: #1e293b; margin-bottom: 12px; }
.welcome-screen p { max-width: 400px; line-height: 1.8; }
.suggestion-chips { display: flex; flex-wrap: wrap; gap: 8px; margin-top: 20px; justify-content: center; }
.suggestion-chip { background: #f1f5f9; border: 1px solid #e2e8f0; padding: 8px 16px; border-radius: 20px; font-size: .82rem; cursor: pointer; transition: all .2s; color: #475569; }
.suggestion-chip:hover { background: #e0e7ff; border-color: #818cf8; color: #4338ca; }
.offline-banner { background: #fef2f2; border: 1px solid #fecaca; border-radius: 10px; padding: 12px 16px; margin: 16px 24px 0; display: flex; align-items: center; gap: 10px; color: #991b1b; font-size: .85rem; }
.offline-banner i { font-size: 1.2rem; }
@media (max-width: 768px) {
    .chat-sidebar { width: 60px; }
    .chat-sidebar-header h5, .conv-item .conv-title, .conv-item .conv-delete, .btn-new-chat span { display: none; }
    .btn-new-chat { padding: 10px; font-size: 1.1rem; }
    .conv-item { justify-content: center; padding: 10px; }
    .msg-row { max-width: 95%; }
}
</style>

<!-- Page Header -->
<div class="d-flex justify-content-between flex-wrap flex-md-nowrap align-items-center pt-3 pb-2 mb-3 border-bottom">
    <h1 class="h2"><i class="fas fa-robot me-2 text-primary"></i>المساعد الذكي</h1>
    <div class="btn-toolbar mb-2 mb-md-0 gap-2">
        <a href="portal.php" class="btn btn-outline-secondary shadow-sm px-3 py-2">
            <i class="fas fa-arrow-right me-2"></i>العودة للبوابة
        </a>
    </div>
</div>

<?php if (!$ollamaAvailable): ?>
<div class="offline-banner">
    <i class="fas fa-exclamation-triangle"></i>
    <div><strong>خدمة المساعد الذكي غير متاحة حالياً.</strong> تأكد من تشغيل Ollama على الخادم.</div>
</div>
<?php endif; ?>

<!-- Chat Interface -->
<div class="chat-container">
    <!-- Sidebar -->
    <div class="chat-sidebar">
        <div class="chat-sidebar-header">
            <button class="btn-new-chat" onclick="startNewChat()">
                <i class="fas fa-plus me-2"></i><span>محادثة جديدة</span>
            </button>
        </div>
        <div class="conv-list" id="convList">
            <!-- يتم تحميلها بالـ AJAX -->
        </div>
    </div>
    
    <!-- Main Chat -->
    <div class="chat-main">
        <div class="chat-messages" id="chatMessages">
            <!-- شاشة الترحيب -->
            <div class="welcome-screen" id="welcomeScreen">
                <i class="fas fa-robot"></i>
                <h3>مرحباً <?= htmlspecialchars($teacherName) ?>!</h3>
                <p>أنا مساعدك التعليمي الذكي. يمكنني مساعدتك في التخطيط للدروس، استراتيجيات التدريس، إدارة الصف، والمزيد.</p>
                <div class="suggestion-chips">
                    <span class="suggestion-chip" onclick="sendSuggestion(this)">اقترح لي أنشطة تفاعلية لدرس الكسور</span>
                    <span class="suggestion-chip" onclick="sendSuggestion(this)">كيف أتعامل مع طالب ضعيف الانتباه؟</span>
                    <span class="suggestion-chip" onclick="sendSuggestion(this)">أعطني أفكار لتقييم تكويني</span>
                    <span class="suggestion-chip" onclick="sendSuggestion(this)">استراتيجيات التعلم النشط</span>
                </div>
            </div>
        </div>
        
        <!-- Typing Indicator -->
        <div class="typing-indicator" id="typingIndicator">
            <div class="msg-row assistant">
                <div class="msg-avatar"><i class="fas fa-robot"></i></div>
                <div class="msg-bubble" style="background: #f1f5f9; padding: 12px 20px;">
                    <div class="dots"><span></span><span></span><span></span></div>
                </div>
            </div>
        </div>
        
        <!-- Input -->
        <div class="chat-input-area">
            <div class="chat-input-wrap">
                <textarea id="chatInput" rows="1" placeholder="اكتب رسالتك هنا..." <?= !$ollamaAvailable ? 'disabled' : '' ?>
                    onkeydown="if(event.key==='Enter' && !event.shiftKey){event.preventDefault();sendMessage();}"></textarea>
                <button class="btn-send" id="btnSend" onclick="sendMessage()" <?= !$ollamaAvailable ? 'disabled' : '' ?>>
                    <i class="fas fa-paper-plane"></i>
                </button>
            </div>
            <div class="d-flex justify-content-between align-items-center mt-2">
                <small class="text-muted"><i class="fas fa-microchip me-1"></i>يعمل بـ Ollama محلياً — مجاني وبدون حدود</small>
                <small class="text-muted" id="responseTime"></small>
            </div>
        </div>
    </div>
</div>

<!-- Delete Confirmation Modal -->
<div class="modal fade" id="deleteConvModal" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header bg-danger text-white">
                <h5 class="modal-title"><i class="fas fa-trash me-2"></i>حذف المحادثة</h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body text-center">
                <i class="fas fa-exclamation-triangle text-danger" style="font-size: 3rem;"></i>
                <p class="mt-3">هل تريد حذف هذه المحادثة نهائياً؟</p>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-danger" data-bs-dismiss="modal"><i class="fas fa-times me-1"></i>إلغاء</button>
                <button type="button" class="btn btn-danger" id="confirmDeleteBtn" onclick="confirmDeleteConversation()">
                    <i class="fas fa-trash me-1"></i>حذف
                </button>
            </div>
        </div>
    </div>
</div>

<script>
var currentConversationId = 0;
var deleteConversationId = 0;
var isProcessing = false;

// تحميل المحادثات عند فتح الصفحة
$(document).ready(function() {
    loadConversations();
    autoResizeTextarea();
});

function autoResizeTextarea() {
    var textarea = document.getElementById('chatInput');
    textarea.addEventListener('input', function() {
        this.style.height = 'auto';
        this.style.height = Math.min(this.scrollHeight, 120) + 'px';
    });
}

// إرسال رسالة
function sendMessage() {
    if (isProcessing) return;
    
    var input = document.getElementById('chatInput');
    var message = input.value.trim();
    if (!message) return;
    
    isProcessing = true;
    document.getElementById('btnSend').disabled = true;
    
    // إخفاء شاشة الترحيب
    var welcome = document.getElementById('welcomeScreen');
    if (welcome) welcome.style.display = 'none';
    
    // عرض رسالة المعلم
    appendMessage('user', message);
    input.value = '';
    input.style.height = 'auto';
    
    // عرض مؤشر الكتابة
    document.getElementById('typingIndicator').style.display = 'block';
    scrollToBottom();
    
    // إرسال للخادم
    $.ajax({
        url: 'ajax/ai_chat.php',
        method: 'POST',
        data: {
            action: 'send_message',
            message: message,
            conversation_id: currentConversationId,
            csrf_token: typeof csrfToken !== 'undefined' ? csrfToken : ''
        },
        timeout: 180000, // 3 دقائق
        success: function(res) {
            document.getElementById('typingIndicator').style.display = 'none';
            
            if (res.success) {
                appendMessage('assistant', res.response);
                currentConversationId = res.conversation_id;
                
                if (res.time_ms) {
                    var seconds = (res.time_ms / 1000).toFixed(1);
                    document.getElementById('responseTime').innerHTML = '<i class="fas fa-clock me-1"></i>' + seconds + ' ثانية';
                }
                
                loadConversations();
            } else {
                appendMessage('assistant', '❌ ' + (res.message || 'حدث خطأ'));
            }
        },
        error: function(xhr) {
            document.getElementById('typingIndicator').style.display = 'none';
            appendMessage('assistant', '❌ خطأ في الاتصال. تأكد من أن الخادم يعمل.');
        },
        complete: function() {
            isProcessing = false;
            document.getElementById('btnSend').disabled = false;
            document.getElementById('chatInput').focus();
        }
    });
}

// إضافة رسالة للشاشة
function appendMessage(role, content) {
    var icon = role === 'user' ? 'fa-user' : 'fa-robot';
    var time = new Date().toLocaleTimeString('ar-EG', { hour: '2-digit', minute: '2-digit' });
    
    // تحويل Markdown بسيط
    var html = escapeHtml(content);
    html = html.replace(/\*\*(.*?)\*\*/g, '<strong>$1</strong>');
    html = html.replace(/\*(.*?)\*/g, '<em>$1</em>');
    html = html.replace(/`(.*?)`/g, '<code>$1</code>');
    
    var msgHtml = '<div class="msg-row ' + role + '">' +
        '<div class="msg-avatar"><i class="fas ' + icon + '"></i></div>' +
        '<div><div class="msg-bubble">' + html + '</div>' +
        '<div class="msg-time">' + time + '</div></div></div>';
    
    var container = document.getElementById('chatMessages');
    container.insertAdjacentHTML('beforeend', msgHtml);
    scrollToBottom();
}

function escapeHtml(text) {
    var div = document.createElement('div');
    div.textContent = text;
    return div.innerHTML;
}

function scrollToBottom() {
    var el = document.getElementById('chatMessages');
    el.scrollTop = el.scrollHeight;
}

// اقتراحات
function sendSuggestion(chip) {
    document.getElementById('chatInput').value = chip.textContent;
    sendMessage();
}

// محادثة جديدة
function startNewChat() {
    currentConversationId = 0;
    document.getElementById('chatMessages').innerHTML = '';
    document.getElementById('responseTime').innerHTML = '';
    
    // إعادة إظهار شاشة الترحيب
    var welcome = '<div class="welcome-screen" id="welcomeScreen">' +
        '<i class="fas fa-robot"></i>' +
        '<h3>مرحباً <?= htmlspecialchars($teacherName, ENT_QUOTES) ?>!</h3>' +
        '<p>أنا مساعدك التعليمي الذكي. يمكنني مساعدتك في التخطيط للدروس، استراتيجيات التدريس، إدارة الصف، والمزيد.</p>' +
        '<div class="suggestion-chips">' +
        '<span class="suggestion-chip" onclick="sendSuggestion(this)">اقترح لي أنشطة تفاعلية لدرس الكسور</span>' +
        '<span class="suggestion-chip" onclick="sendSuggestion(this)">كيف أتعامل مع طالب ضعيف الانتباه؟</span>' +
        '<span class="suggestion-chip" onclick="sendSuggestion(this)">أعطني أفكار لتقييم تكويني</span>' +
        '<span class="suggestion-chip" onclick="sendSuggestion(this)">استراتيجيات التعلم النشط</span>' +
        '</div></div>';
    document.getElementById('chatMessages').innerHTML = welcome;
    
    // إزالة التحديد من المحادثات
    document.querySelectorAll('.conv-item').forEach(function(el) { el.classList.remove('active'); });
    document.getElementById('chatInput').focus();
}

// تحميل المحادثات
function loadConversations() {
    $.get('ajax/ai_chat.php', { action: 'get_conversations' }, function(res) {
        if (!res.success) return;
        
        var html = '';
        res.conversations.forEach(function(conv) {
            var isActive = conv.id == currentConversationId ? ' active' : '';
            html += '<div class="conv-item' + isActive + '" onclick="loadConversation(' + conv.id + ')" data-id="' + conv.id + '">' +
                '<i class="fas fa-comment conv-icon"></i>' +
                '<span class="conv-title">' + escapeHtml(conv.title) + '</span>' +
                '<i class="fas fa-trash conv-delete" onclick="event.stopPropagation();showDeleteModal(' + conv.id + ')"></i>' +
                '</div>';
        });
        
        document.getElementById('convList').innerHTML = html || '<div class="text-center text-muted p-3" style="font-size:.8rem;">لا توجد محادثات</div>';
    });
}

// تحميل محادثة
function loadConversation(convId) {
    currentConversationId = convId;
    
    document.querySelectorAll('.conv-item').forEach(function(el) {
        el.classList.toggle('active', el.dataset.id == convId);
    });
    
    $.get('ajax/ai_chat.php', { action: 'get_messages', conversation_id: convId }, function(res) {
        if (!res.success) return;
        
        var container = document.getElementById('chatMessages');
        container.innerHTML = '';
        
        res.messages.forEach(function(msg) {
            appendMessage(msg.role, msg.content);
        });
    });
}

// حذف محادثة
function showDeleteModal(convId) {
    deleteConversationId = convId;
    new bootstrap.Modal(document.getElementById('deleteConvModal')).show();
}

function confirmDeleteConversation() {
    $.post('ajax/ai_chat.php', {
        action: 'delete_conversation',
        conversation_id: deleteConversationId,
        csrf_token: typeof csrfToken !== 'undefined' ? csrfToken : ''
    }, function(res) {
        bootstrap.Modal.getInstance(document.getElementById('deleteConvModal')).hide();
        if (res.success) {
            if (currentConversationId === deleteConversationId) {
                startNewChat();
            }
            loadConversations();
        }
    });
}
</script>

<?php require_once '../includes/teacher_footer.php'; ?>
