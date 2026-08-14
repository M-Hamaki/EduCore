<?php
/**
 * صفحة SSO الخاصة بـ Microsoft Teams
 * Teams SSO Handler
 * 
 * هذه الصفحة تعالج تسجيل الدخول التلقائي من داخل Microsoft Teams
 * عندما يفتح الطالب تطبيق المدرسة من داخل Teams
 * 
 * @author School Portal Team
 * @version 1.0
 */

define('ACCESS_ALLOWED', true);

ini_set('display_errors', '0');
ini_set('display_startup_errors', '0');
error_reporting(E_ALL);

// تحميل الإعدادات
require_once dirname(__DIR__) . '/includes/session_config.php';
require_once dirname(__DIR__) . '/config/database.php';
require_once dirname(__DIR__) . '/config/azure_sso.php';
require_once dirname(__DIR__) . '/classes/MicrosoftSSO.php';
require_once dirname(__DIR__) . '/classes/utilities.php';

/**
 * إرجاع JSON response
 */
function jsonResponse($success, $message, $data = []) {
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode([
        'success' => $success,
        'message' => $message,
        'data' => $data
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

/**
 * عرض صفحة HTML للـ Teams
 */
function showTeamsPage($content, $script = '') {
    ?>
    <!DOCTYPE html>
    <html lang="ar" dir="rtl">
    <head>
        <meta charset="UTF-8">
        <meta name="viewport" content="width=device-width, initial-scale=1.0">
        <title>بوابة المدرسة - Microsoft Teams</title>
        <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.rtl.min.css">
        <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css">
        <script src="https://res.cdn.office.net/teams-js/2.19.0/js/MicrosoftTeams.min.js"></script>
        <style>
            body { 
                background: linear-gradient(135deg, #667eea 0%, #764ba2 100%); 
                min-height: 100vh; 
                display: flex; 
                align-items: center; 
                justify-content: center;
                margin: 0;
            }
            .teams-card { 
                background: white; 
                border-radius: 16px; 
                padding: 40px; 
                max-width: 450px; 
                width: 90%;
                box-shadow: 0 10px 40px rgba(0,0,0,0.2); 
                text-align: center;
            }
            .loading-spinner {
                width: 50px;
                height: 50px;
                border: 4px solid #f3f3f3;
                border-top: 4px solid #667eea;
                border-radius: 50%;
                animation: spin 1s linear infinite;
                margin: 20px auto;
            }
            @keyframes spin {
                0% { transform: rotate(0deg); }
                100% { transform: rotate(360deg); }
            }
            .teams-icon {
                width: 80px;
                height: 80px;
                margin-bottom: 20px;
            }
            .success-icon { color: #28a745; font-size: 4rem; }
            .error-icon { color: #dc3545; font-size: 4rem; }
        </style>
    </head>
    <body>
        <div class="teams-card">
            <?php echo $content; ?>
        </div>
        <?php if ($script): ?>
        <script>
            <?php echo $script; ?>
        </script>
        <?php endif; ?>
    </body>
    </html>
    <?php
    exit;
}

// ==================== معالجة الطلب ====================

$database = new Database();
$db = $database->getConnection();
$sso = new MicrosoftSSO($db);

// إذا كان المستخدم مسجل دخول بالفعل
if (isset($_SESSION['user_id'])) {
    $role = $_SESSION['role'] ?? 'student';
    $redirectUrl = '../' . ltrim($sso->getDashboardUrl($role), '/');
    
    $content = '
        <i class="fas fa-check-circle success-icon mb-4"></i>
        <h4 class="mb-3">مرحباً ' . htmlspecialchars($_SESSION['name'] ?? 'بك') . '!</h4>
        <p class="text-muted">أنت مسجل دخول بالفعل</p>
        <div class="loading-spinner"></div>
        <p class="small text-muted">جاري التوجيه...</p>
    ';
    
    $script = 'setTimeout(function() { window.location.href = "' . $redirectUrl . '"; }, 1500);';
    
    showTeamsPage($content, $script);
}

// التحقق من وجود كود من OAuth
if (isset($_GET['code'])) {
    // معالجة الكود (نفس callback العادي لكن للـ Teams)
    
    // التحقق من State
    if (!isset($_GET['state']) || !isset($_SESSION['oauth_state']) || $_GET['state'] !== $_SESSION['oauth_state']) {
        $content = '
            <i class="fas fa-exclamation-circle error-icon mb-4"></i>
            <h4 class="mb-3">خطأ أمني</h4>
            <p class="text-muted">فشل التحقق من صحة الطلب</p>
            <button onclick="retryAuth()" class="btn btn-primary mt-3">
                <i class="fas fa-redo me-2"></i>
                إعادة المحاولة
            </button>
        ';
        
        $script = 'function retryAuth() { window.location.href = "teams_sso.php"; }';
        
        showTeamsPage($content, $script);
    }
    
    unset($_SESSION['oauth_state']);
    
    $code = $_GET['code'];
    $tokens = $sso->exchangeCodeForTokensTeams($code);
    
    if (!$tokens) {
        $content = '
            <i class="fas fa-exclamation-circle error-icon mb-4"></i>
            <h4 class="mb-3">فشل المصادقة</h4>
            <p class="text-muted">لم نتمكن من التحقق من حسابك</p>
            <button onclick="retryAuth()" class="btn btn-primary mt-3">
                <i class="fas fa-redo me-2"></i>
                إعادة المحاولة
            </button>
        ';
        
        $script = 'function retryAuth() { window.location.href = "teams_sso.php"; }';
        
        showTeamsPage($content, $script);
    }
    
    $microsoftUser = $sso->getUserInfo($tokens['access_token']);
    
    if (!$microsoftUser) {
        $content = '
            <i class="fas fa-exclamation-circle error-icon mb-4"></i>
            <h4 class="mb-3">خطأ في البيانات</h4>
            <p class="text-muted">لم نتمكن من الحصول على معلومات حسابك</p>
        ';
        
        showTeamsPage($content);
    }
    
    $microsoftId = $microsoftUser['id'];
    $email = $microsoftUser['mail'] ?? $microsoftUser['userPrincipalName'] ?? null;
    $displayName = $microsoftUser['displayName'] ?? 'مستخدم';
    
    // البحث عن المستخدم
    $user = $sso->resolveMicrosoftLoginUser($microsoftId, $email);

    if (!$user) {
        $content = '
            <i class="fas fa-user-times error-icon mb-4"></i>
            <h4 class="mb-3">الحساب غير موجود</h4>
            <p class="text-muted">لم يتم العثور على حساب مرتبط بـ</p>
            <p><strong>' . htmlspecialchars($email) . '</strong></p>
            <p class="small text-muted mt-3">يرجى التواصل مع الإدارة لربط حسابك</p>
        ';
        
        showTeamsPage($content);
    }

    $accessDecision = $sso->loginAccessDecision($user);
    if (!$accessDecision['allowed']) {
        $content = '
            <i class="fas fa-user-lock error-icon mb-4"></i>
            <h4 class="mb-3">تعذر تسجيل الدخول</h4>
            <p class="text-muted">' . htmlspecialchars((string) $accessDecision['message'], ENT_QUOTES, 'UTF-8') . '</p>
        ';
        showTeamsPage($content);
    }
    
    // التحقق من مرحلة الطالب (إذا كان الدور هو student)
    if ($user['role'] === 'student') {
        // الحصول على المرحلة المحددة من الجلسة
        $loginStage = $_SESSION['stage_selected'] ?? null;
        
        // الحصول على مرحلة الطالب من قاعدة البيانات
        $stageQuery = "SELECT s.stage_code, s.stage_name
                       FROM users u
                       LEFT JOIN classes c ON u.class_id = c.id
                       LEFT JOIN grades g ON c.grade_id = g.id
                       LEFT JOIN stages s ON g.stage_id = s.id
                       WHERE u.id = ?";
        $stageStmt = $db->prepare($stageQuery);
        $stageStmt->execute([$user['id']]);
        $stageResult = $stageStmt->fetch(PDO::FETCH_ASSOC);
        
        $studentStage = $stageResult && !empty($stageResult['stage_code']) ? $stageResult['stage_code'] : null;
        
        // التحقق من تطابق المرحلة (فقط إذا كانت المرحلة محددة)
        if ($loginStage && $studentStage && $studentStage !== $loginStage) {
            $stageNameMap = [
                'kindergarten' => 'رياض الأطفال',
                'primary' => 'المرحلة الابتدائية',
                'preparatory' => 'المرحلة الإعدادية',
                'secondary' => 'المرحلة الثانوية'
            ];
            $correctStageName = isset($stageNameMap[$studentStage]) ? $stageNameMap[$studentStage] : 'المرحلة المناسبة';
            
            $content = '
                <i class="fas fa-exclamation-triangle error-icon mb-4" style="color: #ffc107;"></i>
                <h4 class="mb-3">المرحلة غير صحيحة</h4>
                <p class="text-muted">هذا الحساب مخصص لـ <strong>' . $correctStageName . '</strong></p>
                <p class="small text-muted">يرجى تسجيل الدخول من الصفحة المناسبة</p>
            ';
            
            showTeamsPage($content);
        }
        
        // إذا لم يتم تعيين مرحلة للطالب
        if (!$studentStage) {
            $content = '
                <i class="fas fa-exclamation-circle error-icon mb-4"></i>
                <h4 class="mb-3">لم يتم تعيين مرحلة</h4>
                <p class="text-muted">لم يتم تعيين صف أو مرحلة دراسية لحسابك</p>
                <p class="small text-muted">يرجى التواصل مع الإدارة</p>
            ';
            
            showTeamsPage($content);
        }
        
        // حفظ مرحلة الطالب في الجلسة
        $_SESSION['student_stage'] = $studentStage;
    }
    
    // تسجيل الدخول
    if (!$sso->loginUser($user, $microsoftUser)) {
        $content = '
            <i class="fas fa-exclamation-circle error-icon mb-4"></i>
            <h4 class="mb-3">تعذر إكمال تسجيل الدخول</h4>
            <p class="text-muted">حدث خطأ أثناء تهيئة جلسة الدخول. يرجى المحاولة مرة أخرى، وإذا استمرت المشكلة فتواصل مع الإدارة.</p>
        ';
        showTeamsPage($content);
    }
    
    $redirectUrl = '../' . ltrim($sso->getDashboardUrl($user['role']), '/');
    
    $content = '
        <i class="fas fa-check-circle success-icon mb-4"></i>
        <h4 class="mb-3">مرحباً ' . htmlspecialchars($user['name'] ?? $displayName) . '!</h4>
        <p class="text-muted">تم تسجيل الدخول بنجاح عبر Microsoft Teams</p>
        <div class="loading-spinner"></div>
        <p class="small text-muted">جاري التوجيه...</p>
    ';
    
    $script = 'setTimeout(function() { window.location.href = "' . $redirectUrl . '"; }, 1500);';
    
    showTeamsPage($content, $script);
}

// التحقق من وجود خطأ
if (isset($_GET['error'])) {
    $error = $_GET['error'];
    
    // إذا كان الخطأ يتطلب تسجيل دخول تفاعلي
    if ($error === 'login_required' || $error === 'interaction_required' || $error === 'consent_required') {
        // التوجيه لصفحة تسجيل الدخول العادية
        $authUrl = $sso->getAuthorizationUrl();
        header('Location: ' . str_replace(AZURE_REDIRECT_URI, AZURE_TEAMS_REDIRECT_URI, $authUrl));
        exit;
    }
    
    $content = '
        <i class="fas fa-exclamation-circle error-icon mb-4"></i>
        <h4 class="mb-3">حدث خطأ</h4>
        <p class="text-muted">' . htmlspecialchars($_GET['error_description'] ?? 'فشل تسجيل الدخول') . '</p>
        <button onclick="retryAuth()" class="btn btn-primary mt-3">
            <i class="fas fa-redo me-2"></i>
            إعادة المحاولة
        </button>
    ';
    
    $script = 'function retryAuth() { window.location.href = "teams_sso.php"; }';
    
    showTeamsPage($content, $script);
}

// ==================== بدء عملية SSO ====================

// عرض صفحة التحميل وبدء عملية المصادقة
$teamsAuthUrl = $sso->getTeamsAuthorizationUrl();

$content = '
    <img src="https://img.icons8.com/fluency/96/microsoft-teams-2019.png" class="teams-icon" alt="Teams">
    <h4 class="mb-3">بوابة المدرسة</h4>
    <p class="text-muted mb-4">جاري تسجيل الدخول عبر Microsoft Teams...</p>
    <div class="loading-spinner"></div>
    <p class="small text-muted">يرجى الانتظار</p>
';

$script = '
    // Initialize Teams SDK
    microsoftTeams.app.initialize().then(function() {
        console.log("Teams SDK initialized");
        
        // Try silent SSO first
        microsoftTeams.authentication.getAuthToken({
            successCallback: function(token) {
                console.log("Got Teams token");
                // Redirect with token
                window.location.href = "teams_token_handler.php?token=" + encodeURIComponent(token);
            },
            failureCallback: function(error) {
                console.log("Silent SSO failed, trying popup:", error);
                // Fall back to OAuth flow
                window.location.href = "' . $teamsAuthUrl . '";
            }
        });
    }).catch(function(error) {
        console.log("Not in Teams context, using OAuth:", error);
        // Not in Teams, use regular OAuth
        window.location.href = "' . $teamsAuthUrl . '";
    });
';

showTeamsPage($content, $script);
