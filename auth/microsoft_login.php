<?php
/**
 * صفحة بدء تسجيل الدخول عبر Microsoft
 * Microsoft Login Initiation Page
 * 
 * هذه الصفحة تبدأ عملية المصادقة مع Microsoft Entra ID
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

// التحقق من اكتمال إعدادات التطبيق المختارة للبيئة الحالية.
$requiredAzureValues = [AZURE_CLIENT_ID, AZURE_TENANT_ID, AZURE_CLIENT_SECRET];
$hasInvalidAzureCredential = false;
foreach ($requiredAzureValues as $requiredAzureValue) {
    $value = trim((string) $requiredAzureValue);
    if ($value === '' || preg_match('/(?:YOUR_|your_|change[_-]?me)/i', $value)) {
        $hasInvalidAzureCredential = true;
        break;
    }
}
unset($requiredAzureValues, $requiredAzureValue);

if ($hasInvalidAzureCredential) {
    die('
    <html dir="rtl">
    <head>
        <meta charset="UTF-8">
        <title>خطأ في الإعداد</title>
        <style>
            body { font-family: Arial, sans-serif; padding: 50px; background: #f5f5f5; }
            .error-box { background: #fff; border-right: 4px solid #dc3545; padding: 30px; border-radius: 8px; max-width: 600px; margin: auto; }
            h2 { color: #dc3545; margin-top: 0; }
            code { background: #f8f9fa; padding: 2px 8px; border-radius: 4px; }
        </style>
    </head>
    <body>
        <div class="error-box">
            <h2>⚠️ لم يتم إعداد Client Secret</h2>
            <p>يجب عليك إنشاء <strong>Client Secret</strong> من Azure Portal ثم إضافته في ملف:</p>
            <p><code>.env</code></p>
            <br>
            <p><strong>الخطوات:</strong></p>
            <ol>
                <li>اذهب إلى <a href="https://portal.azure.com" target="_blank">Azure Portal</a></li>
                <li>Microsoft Entra ID → App registrations</li>
                <li>اختر "School Website SSO"</li>
                <li>Certificates & secrets → New client secret</li>
                <li>انسخ القيمة والصقها في الملف</li>
            </ol>
        </div>
    </body>
    </html>
    ');
}

// التحقق من إعداد Redirect URI
$redirectUri = trim((string) AZURE_REDIRECT_URI);
if ($redirectUri === '' || preg_match('/(?:YOUR_|your_|YOUR_DOMAIN\.COM)/i', $redirectUri)) {
    die('
    <html dir="rtl">
    <head>
        <meta charset="UTF-8">
        <title>خطأ في الإعداد</title>
        <style>
            body { font-family: Arial, sans-serif; padding: 50px; background: #f5f5f5; }
            .error-box { background: #fff; border-right: 4px solid #ffc107; padding: 30px; border-radius: 8px; max-width: 600px; margin: auto; }
            h2 { color: #ffc107; margin-top: 0; }
            code { background: #f8f9fa; padding: 2px 8px; border-radius: 4px; }
        </style>
    </head>
    <body>
        <div class="error-box">
            <h2>⚠️ لم يتم تحديد رابط الموقع</h2>
            <p>يجب تعديل <code>AZURE_REDIRECT_URI</code> أو <code>AZURE_LOCAL_REDIRECT_URI</code> في ملف:</p>
            <p><code>.env</code></p>
            <br>
            <p>مثال:</p>
            <code>https://school.example.com/auth/microsoft_callback.php</code>
        </div>
    </body>
    </html>
    ');
}
unset($redirectUri, $hasInvalidAzureCredential);

// إنشاء كائن SSO
$database = new Database();
$db = $database->getConnection();
$sso = new MicrosoftSSO($db);

// إذا كان المستخدم مسجل دخول بالفعل، توجيهه للوحة التحكم
if (isset($_SESSION['user_id'])) {
    $role = $_SESSION['active_role'] ?? $_SESSION['role'] ?? 'student';
    header('Location: ' . $sso->getDashboardUrl($role));
    exit;
}

// حفظ صفحة الإرجاع إذا وُجدت (فقط المسارات المحلية)
if (isset($_GET['return_url'])) {
    $returnUrl = filter_var($_GET['return_url'], FILTER_SANITIZE_URL);
    $parsed = parse_url($returnUrl);
    // Only allow local paths or same-host URLs
    if (!isset($parsed['host']) || $parsed['host'] === ($_SERVER['HTTP_HOST'] ?? '')) {
        $_SESSION['sso_return_url'] = $returnUrl;
    }
}

// حفظ أن المستخدم قادم من Teams
if (isset($_GET['from_teams'])) {
    $_SESSION['from_teams'] = true;
}

// التحقق هل الجهاز موبايل
function isMobile() {
    $userAgent = $_SERVER['HTTP_USER_AGENT'] ?? '';
    return preg_match('/Mobile|Android|iPhone|iPad|iPod|webOS|BlackBerry|IEMobile|Opera Mini/i', $userAgent);
}

// التحقق هل نحاول تسجيل دخول صامت أم عادي
// على الموبايل: نستخدم الوضع التفاعلي مباشرة (لأن الكوكيز محظورة)
// على الكمبيوتر: نحاول الصامت أولاً
$trySilent = !isset($_GET['interactive']) && !isMobile();

// الحصول على login_hint إذا متوفر (لتجاوز طلب الإيميل)
$loginHint = $_GET['login_hint'] ?? null;

// توليد رابط المصادقة والتوجيه إليه
if ($loginHint) {
    // إذا وجد login_hint، نستخدمه لتسريع تسجيل الدخول
    $authUrl = $sso->getTeamsAuthorizationUrl(null, $loginHint);
} else {
    $authUrl = $sso->getAuthorizationUrl(null, $trySilent);
}

// تسجيل للتشخيص
if (defined('SSO_DEBUG_MODE') && SSO_DEBUG_MODE) {
    error_log('[Microsoft SSO] Authorization redirect prepared (silent: ' . ($trySilent ? 'yes' : 'no') . ', hint: ' . ($loginHint ? 'present' : 'absent') . ')');
}

// التوجيه إلى صفحة تسجيل الدخول في Microsoft
header('Location: ' . $authUrl);
exit;
