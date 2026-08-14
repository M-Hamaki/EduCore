<?php
/**
 * صفحة استقبال الرد من Microsoft (Callback)
 * Microsoft OAuth Callback Handler
 * 
 * هذه الصفحة تستقبل الكود من Microsoft بعد تسجيل الدخول
 * ثم تستبدله بـ Access Token وتسجّل دخول المستخدم
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
 * عرض رسالة خطأ
 */
function showError($title, $message, $details = null) {
    ?>
    <!DOCTYPE html>
    <html lang="ar" dir="rtl">
    <head>
        <meta charset="UTF-8">
        <meta name="viewport" content="width=device-width, initial-scale=1.0">
        <title>خطأ في تسجيل الدخول</title>
        <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.rtl.min.css">
        <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css">
        <style>
            body { background: linear-gradient(135deg, #667eea 0%, #764ba2 100%); min-height: 100vh; display: flex; align-items: center; justify-content: center; }
            .error-card { background: white; border-radius: 16px; padding: 40px; max-width: 500px; box-shadow: 0 10px 40px rgba(0,0,0,0.2); }
            .error-icon { font-size: 4rem; color: #dc3545; }
            .btn-back { background: linear-gradient(135deg, #667eea 0%, #764ba2 100%); border: none; padding: 12px 30px; }
        </style>
    </head>
    <body>
        <div class="error-card text-center">
            <i class="fas fa-exclamation-circle error-icon mb-4"></i>
            <h3 class="mb-3"><?php echo htmlspecialchars($title); ?></h3>
            <p class="text-muted mb-4"><?php echo htmlspecialchars($message); ?></p>
            <?php if ($details && defined('SSO_DEBUG_MODE') && SSO_DEBUG_MODE): ?>
                <div class="alert alert-secondary text-start small">
                    <strong>تفاصيل تقنية:</strong><br>
                    <code><?php echo htmlspecialchars($details); ?></code>
                </div>
            <?php endif; ?>
            <a href="../index.php?skip_intro=1" class="btn btn-back text-white">
                <i class="fas fa-arrow-right me-2"></i>
                العودة لتسجيل الدخول
            </a>
        </div>
    </body>
    </html>
    <?php
    exit;
}

/**
 * عرض رسالة نجاح وتوجيه
 */
function showSuccessAndRedirect($userName, $redirectUrl, $fromTeams = false) {
    // إذا كان قادم من Teams، نعرض رسالة خاصة
    $teamsDeepLink = 'https://teams.microsoft.com/l/entity/1e8980e7-c235-4bc8-81dd-e75faf01199a/portal-home';
    ?>
    <!DOCTYPE html>
    <html lang="ar" dir="rtl">
    <head>
        <meta charset="UTF-8">
        <meta name="viewport" content="width=device-width, initial-scale=1.0">
        <title>تم تسجيل الدخول بنجاح</title>
        <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.rtl.min.css">
        <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css">
        <style>
            body { background: linear-gradient(135deg, #667eea 0%, #764ba2 100%); min-height: 100vh; display: flex; align-items: center; justify-content: center; }
            .success-card { background: white; border-radius: 16px; padding: 40px; max-width: 500px; box-shadow: 0 10px 40px rgba(0,0,0,0.2); }
            .success-icon { font-size: 4rem; color: #28a745; }
            .spinner-border { width: 1.5rem; height: 1.5rem; }
            .btn-teams { background: #5558AF; color: white; border: none; padding: 12px 30px; border-radius: 8px; font-size: 1.1rem; }
            .btn-teams:hover { background: #4448A0; color: white; }
        </style>
        <?php if (!$fromTeams): ?>
        <meta http-equiv="refresh" content="2;url=<?php echo htmlspecialchars($redirectUrl); ?>">
        <?php endif; ?>
    </head>
    <body>
        <div class="success-card text-center">
            <i class="fas fa-check-circle success-icon mb-4"></i>
            <h3 class="mb-3">مرحباً <?php echo htmlspecialchars($userName); ?>!</h3>
            <p class="text-muted mb-4">تم تسجيل الدخول بنجاح عبر Microsoft</p>
            <?php if ($fromTeams): ?>
            <p class="text-success mb-4"><i class="fas fa-check me-2"></i>يمكنك الآن العودة لتطبيق Teams</p>
            <a href="<?php echo $teamsDeepLink; ?>" class="btn btn-teams">
                <i class="fab fa-microsoft me-2"></i>
                فتح التطبيق في Teams
            </a>
            <p class="text-muted mt-3 small">أو أغلق هذه النافذة وافتح التطبيق من Teams</p>
            <?php else: ?>
            <div class="d-flex align-items-center justify-content-center text-muted">
                <div class="spinner-border spinner-border-sm me-2" role="status"></div>
                <span>جاري التوجيه...</span>
            </div>
            <?php endif; ?>
        </div>
    </body>
    </html>
    <?php
    exit;
}

// ==================== بداية المعالجة ====================

// التحقق من وجود خطأ في الرد
if (isset($_GET['error'])) {
    $error = $_GET['error'];
    $errorDescription = $_GET['error_description'] ?? 'حدث خطأ غير معروف';
    
    // إذا كان الخطأ يتطلب تفاعل المستخدم وكنا نحاول تسجيل دخول صامت
    // نعيد المحاولة بالوضع التفاعلي
    $silentErrors = ['login_required', 'interaction_required', 'consent_required'];
    $wasSilentAttempt = isset($_SESSION['sso_silent_attempt']) && $_SESSION['sso_silent_attempt'] === true;
    
    if (in_array($error, $silentErrors) && $wasSilentAttempt) {
        // مسح علامة المحاولة الصامتة
        unset($_SESSION['sso_silent_attempt']);
        // إعادة التوجيه للوضع التفاعلي (سيعرض صفحة تسجيل الدخول)
        header('Location: microsoft_login.php?interactive=1');
        exit;
    }
    
    // معالجة أخطاء شائعة
    $errorMessages = [
        'access_denied' => 'تم رفض الوصول. ربما قمت بإلغاء عملية تسجيل الدخول.',
        'invalid_request' => 'طلب غير صالح. يرجى المحاولة مرة أخرى.',
        'unauthorized_client' => 'التطبيق غير مصرح له. يرجى التواصل مع الإدارة.',
        'invalid_grant' => 'انتهت صلاحية الرمز. يرجى المحاولة مرة أخرى.',
        'interaction_required' => 'مطلوب تفاعل المستخدم. يرجى المحاولة مرة أخرى.',
        'login_required' => 'يجب تسجيل الدخول أولاً.',
        'consent_required' => 'مطلوب موافقة المستخدم على الصلاحيات.'
    ];
    
    $friendlyMessage = $errorMessages[$error] ?? 'حدث خطأ أثناء تسجيل الدخول.';
    
    showError('خطأ في تسجيل الدخول', $friendlyMessage, $errorDescription);
}

// التحقق من وجود الكود
if (!isset($_GET['code'])) {
    showError('طلب غير صالح', 'لم يتم استلام رمز المصادقة من Microsoft.');
}

// التحقق من State (حماية CSRF)
if (!isset($_GET['state']) || !isset($_SESSION['oauth_state']) || $_GET['state'] !== $_SESSION['oauth_state']) {
    showError('خطأ أمني', 'فشل التحقق من صحة الطلب. يرجى المحاولة مرة أخرى.');
}

// مسح الـ state بعد استخدامه
unset($_SESSION['oauth_state']);

// الحصول على الكود
$code = $_GET['code'];

// إنشاء الاتصالات
$database = new Database();
$db = $database->getConnection();
$sso = new MicrosoftSSO($db);

// استبدال الكود بـ Tokens
$tokens = $sso->exchangeCodeForTokens($code);

if (!$tokens) {
    showError('فشل المصادقة', 'لم نتمكن من التحقق من حسابك. يرجى المحاولة مرة أخرى.');
}

// الحصول على معلومات المستخدم من Microsoft Graph
$microsoftUser = $sso->getUserInfo($tokens['access_token']);

if (!$microsoftUser) {
    showError('فشل الحصول على البيانات', 'لم نتمكن من الحصول على معلومات حسابك من Microsoft.');
}

// استخراج البيانات المهمة
$microsoftId = $microsoftUser['id'];
$email = $microsoftUser['mail'] ?? $microsoftUser['userPrincipalName'] ?? null;
$displayName = $microsoftUser['displayName'] ?? 'مستخدم';

// تسجيل للتشخيص
if (defined('SSO_DEBUG_MODE') && SSO_DEBUG_MODE) {
    error_log('[Microsoft SSO] User info: ' . json_encode([
        'id' => $microsoftId,
        'email' => $email,
        'name' => $displayName
    ]));
}

// البحث عن المستخدم في قاعدة البيانات
$user = null;

// الربط الصارم: Microsoft ID إن وجد، والبريد واسم المستخدم يجب أن يطابقا البريد الموثق.
$user = $sso->resolveMicrosoftLoginUser($microsoftId, $email);

// إذا لم يوجد المستخدم أو لم تتطابق الهوية
if (!$user) {
    // خيار: إنشاء حساب جديد تلقائياً (إذا كان مفعلاً)
    if (defined('SSO_ALLOW_AUTO_REGISTER') && SSO_ALLOW_AUTO_REGISTER) {
        // يمكن تفعيل هذا الخيار لإنشاء حسابات تلقائياً
        // لكن في هذه الحالة، نطلب ربط الحساب يدوياً
        showError(
            'الحساب غير موجود',
            'لم يتم العثور على حساب مرتبط بـ ' . htmlspecialchars($email) . '. يرجى التواصل مع الإدارة لربط حسابك.',
            'Microsoft ID: ' . $microsoftId
        );
    } else {
        showError(
            'الحساب غير موجود',
            'لم يتم العثور على حساب مرتبط ببريدك الإلكتروني. يرجى التواصل مع الإدارة.'
        );
    }
}

// Microsoft has verified the identity; it is now safe to reveal the account denial reason.
$accessDecision = $sso->loginAccessDecision($user);
if (!$accessDecision['allowed']) {
    showError('تعذر تسجيل الدخول', (string) $accessDecision['message']);
}

// التحقق من مرحلة الطالب (إذا كان الدور هو student)
if ($user['role'] === 'student') {
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
    
    // إذا لم يتم تعيين مرحلة للطالب
    if (!$studentStage) {
        showError(
            'لم يتم تعيين مرحلة',
            'لم يتم تعيين صف أو مرحلة دراسية لحسابك. يرجى التواصل مع الإدارة.'
        );
    }
    
    // حفظ مرحلة الطالب في الجلسة
    $_SESSION['student_stage'] = $studentStage;
}

// تسجيل الدخول وتهيئة الدور النشط
if (!$sso->loginUser($user, $microsoftUser)) {
    showError(
        'تعذر إكمال تسجيل الدخول',
        'حدث خطأ أثناء تهيئة جلسة الدخول. يرجى المحاولة مرة أخرى، وإذا استمرت المشكلة فتواصل مع الإدارة.'
    );
}

// تحديد صفحة التوجيه
$redirectUrl = !empty($_SESSION['role_selection_required'])
    ? '../select_role.php'
    : '../' . ltrim($sso->getDashboardUrl((string) ($_SESSION['active_role'] ?? $user['role'])), '/');

// إذا كان هناك صفحة إرجاع محددة
if (isset($_SESSION['sso_return_url'])) {
    $returnUrl = $_SESSION['sso_return_url'];
    unset($_SESSION['sso_return_url']);
    // Validate redirect is local (prevent open redirect)
    $parsed = parse_url($returnUrl);
    if (!isset($parsed['host']) || $parsed['host'] === ($_SERVER['HTTP_HOST'] ?? '')) {
        $redirectUrl = $returnUrl;
    }
}

// تسجيل الدخول في سجل النشاط
try {
    Utilities::logAction('microsoft_sso_login', 'User logged in via Microsoft SSO', $user['id']);
} catch (Exception $e) {
    // تجاهل الخطأ
}

// مسح علامة المحاولة الصامتة
$wasSilent = isset($_SESSION['sso_silent_attempt']) && $_SESSION['sso_silent_attempt'] === true;
$fromTeams = isset($_SESSION['from_teams']) && $_SESSION['from_teams'] === true;
unset($_SESSION['sso_silent_attempt']);
unset($_SESSION['from_teams']);

// إذا كان تسجيل دخول صامت ناجح، توجيه مباشر بدون صفحة وسيطة
if ($wasSilent && !$fromTeams) {
    header('Location: ' . $redirectUrl);
    exit;
}

// عرض رسالة النجاح والتوجيه
// داخل Teams أصبحت الصفحة نفسها هي تبويب التطبيق، لذلك يتم التوجيه للوحة مباشرة أيضاً.
showSuccessAndRedirect($user['name'] ?? $displayName, $redirectUrl, false);
