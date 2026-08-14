<?php
/**
 * إعدادات الجلسة الموحدة لجميع صفحات النظام
 * يتضمن إلغاء انتهاء الجلسة ومسح الكاش الشامل
 */

// تحميل إعدادات مسح الكاش الشاملة
require_once __DIR__ . '/no_cache.php';
require_once __DIR__ . '/../config/env_loader.php';

// بدء الجلسة بإعدادات محسنة (بدون انتهاء)
if (session_status() === PHP_SESSION_NONE) {
    // تحديد ما إذا كان الاتصال آمن (HTTPS)
    $isSecure = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') || 
                (isset($_SERVER['SERVER_PORT']) && $_SERVER['SERVER_PORT'] == 443);
    
    $sameSite = env('SESSION_SAMESITE', 'Lax');
    if ($sameSite === 'None' && !$isSecure) {
        $sameSite = 'Lax';
    }
    
    $cookieLifetime = max(0, (int)env('SESSION_COOKIE_LIFETIME', 0));
    $sessionLifetime = max(1800, (int)env('SESSION_MAX_LIFETIME', 86400));
    $regenerateInterval = max(300, (int)env('SESSION_REGENERATE_INTERVAL', 1800));
    
    // إعدادات الجلسة
    if (PHP_VERSION_ID >= 70300) {
        session_set_cookie_params([
            'lifetime' => $cookieLifetime,
            'path' => '/',
            'domain' => '',
            'secure' => $isSecure,
            'httponly' => true,
            'samesite' => $sameSite,
        ]);
    } else {
        // للإصدارات الأقدم من PHP
        @ini_set('session.cookie_httponly', '1');
        @ini_set('session.cookie_secure', $isSecure ? '1' : '0');
        @ini_set('session.cookie_samesite', $sameSite);
        session_set_cookie_params($cookieLifetime, '/');
    }
    
    @ini_set('session.gc_maxlifetime', (string)$sessionLifetime);
    @ini_set('session.cache_expire', (string)ceil($sessionLifetime / 60));
    
    // بدء الجلسة
    session_start();

    $idleTimeout = max(900, (int)env('SESSION_IDLE_TIMEOUT', 3600));
    $adminIdleTimeout = max(600, (int)env('ADMIN_SESSION_IDLE_TIMEOUT', 1800));
    $effectiveTimeout = (($_SESSION['role'] ?? '') === 'admin') ? $adminIdleTimeout : $idleTimeout;
    if (!empty($_SESSION['user_id']) && isset($_SESSION['last_activity']) && time() - $_SESSION['last_activity'] > $effectiveTimeout) {
        $_SESSION = [];
        if (ini_get('session.use_cookies')) {
            $params = session_get_cookie_params();
            setcookie(session_name(), '', time() - 42000, $params['path'], $params['domain'], $params['secure'], $params['httponly']);
        }
        session_destroy();
        session_start();
    }
    $_SESSION['last_activity'] = time();
    
    // تجديد معرف الجلسة دورياً لزيادة الأمان
    if (!isset($_SESSION['last_regeneration'])) {
        $_SESSION['last_regeneration'] = time();
    } elseif (time() - $_SESSION['last_regeneration'] > $regenerateInterval) {
        session_regenerate_id(true);
        $_SESSION['last_regeneration'] = time();
    }
}

// إنشاء رمز CSRF إذا لم يكن موجوداً
if (!isset($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}
require_once __DIR__ . '/csrf.php';
?>
