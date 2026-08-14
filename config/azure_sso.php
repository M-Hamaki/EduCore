<?php
/**
 * إعدادات Microsoft Azure AD / Entra ID للـ SSO
 * =========================================
 * 
 * هذا الملف يحتوي على إعدادات الاتصال بـ Microsoft Entra ID
 * لتفعيل تسجيل الدخول الموحد (Single Sign-On) مع Microsoft Teams
 * 
 * @author School Portal Team
 * @version 1.0
 */

// منع الوصول المباشر
if (!defined('ACCESS_ALLOWED')) {
    die('Direct access not permitted');
}

// تحميل متغيرات البيئة (قد تكون محملة مسبقاً من database.php)
require_once __DIR__ . '/env_loader.php';
require_once __DIR__ . '/../classes/MicrosoftSsoEnvironment.php';

$microsoftSsoEnvironment = new MicrosoftSsoEnvironment(
    $_SERVER,
    static fn(string $key, string $default = ''): string => (string) env($key, $default)
);
define('SSO_RUNTIME_ENV', $microsoftSsoEnvironment->name());

/**
 * إعدادات Azure AD App Registration
 * ================================
 * القيم تُقرأ من ملف .env
 */
define('AZURE_CLIENT_ID', $microsoftSsoEnvironment->credential('AZURE_CLIENT_ID', 'AZURE_LOCAL_CLIENT_ID'));
define('AZURE_TENANT_ID', $microsoftSsoEnvironment->credential('AZURE_TENANT_ID', 'AZURE_LOCAL_TENANT_ID'));

/**
 * Client Secret (سر العميل)
 * =========================
 */
define('AZURE_CLIENT_SECRET', $microsoftSsoEnvironment->credential('AZURE_CLIENT_SECRET', 'AZURE_LOCAL_CLIENT_SECRET'));

/**
 * عناوين URL للمصادقة
 * ==================
 */
define('AZURE_REDIRECT_URI', $microsoftSsoEnvironment->redirectUri(false));
define('AZURE_TEAMS_REDIRECT_URI', $microsoftSsoEnvironment->redirectUri(true));

/**
 * إعدادات Microsoft OAuth 2.0 Endpoints
 * =====================================
 * هذه القيم ثابتة ولا تحتاج تغيير
 */
define('AZURE_AUTHORITY', 'https://login.microsoftonline.com/' . AZURE_TENANT_ID);
define('AZURE_AUTHORIZE_ENDPOINT', AZURE_AUTHORITY . '/oauth2/v2.0/authorize');
define('AZURE_TOKEN_ENDPOINT', AZURE_AUTHORITY . '/oauth2/v2.0/token');
define('AZURE_LOGOUT_ENDPOINT', AZURE_AUTHORITY . '/oauth2/v2.0/logout');

/**
 * Microsoft Graph API Endpoint
 * ============================
 * للحصول على معلومات المستخدم
 */
define('GRAPH_API_ENDPOINT', 'https://graph.microsoft.com/v1.0');
define('GRAPH_USER_ENDPOINT', GRAPH_API_ENDPOINT . '/me');

/**
 * صلاحيات OAuth (Scopes)
 * =====================
 * openid: للحصول على ID Token
 * profile: للحصول على معلومات الملف الشخصي
 * email: للحصول على البريد الإلكتروني
 * User.Read: لقراءة معلومات المستخدم من Graph API
 */
define('AZURE_SCOPES', 'openid profile email User.Read');

/**
 * إعدادات JWT للتحقق من التوكن
 * ============================
 */
define('AZURE_JWKS_URI', AZURE_AUTHORITY . '/discovery/v2.0/keys');
define('AZURE_ISSUER', 'https://login.microsoftonline.com/' . AZURE_TENANT_ID . '/v2.0');

/**
 * إعدادات Teams SSO
 * =================
 */
define('TEAMS_APP_ID', AZURE_CLIENT_ID); // نفس Client ID
define('TEAMS_APP_ID_URI', $microsoftSsoEnvironment->teamsAppIdUri(AZURE_CLIENT_ID));
define('TEAMS_SCOPE', TEAMS_APP_ID_URI . '/access_as_user');

/**
 * إعدادات الأمان
 * =============
 */
define('SSO_SESSION_TIMEOUT', (int)env('SSO_SESSION_TIMEOUT', 86400)); // 24 ساعة بالثواني
define('SSO_ALLOW_AUTO_REGISTER', filter_var(env('SSO_ALLOW_AUTO_REGISTER', 'false'), FILTER_VALIDATE_BOOLEAN));

/**
 * وضع التطوير
 * ===========
 * غيّرها إلى false في الإنتاج
 */
define('SSO_DEBUG_MODE', filter_var(env('SSO_DEBUG_MODE', 'false'), FILTER_VALIDATE_BOOLEAN));
unset($microsoftSsoEnvironment);
