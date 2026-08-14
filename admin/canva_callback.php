<?php
/**
 * Canva OAuth Callback Handler
 * معالج استجابة تفويض Canva OAuth 2.0 PKCE
 */
require_once '../config/database.php';
require_once '../classes/utilities.php';
require_once '../includes/session_config.php';
require_once '../classes/CanvaIntegration.php';

Utilities::validateSession('admin');

$database = new Database();
$db       = $database->getConnection();
$canva    = new CanvaIntegration($db);

// --- معالجة الخطأ من Canva ---
if (isset($_GET['error'])) {
    $errMsg = htmlspecialchars($_GET['error_description'] ?? $_GET['error'], ENT_QUOTES, 'UTF-8');
    $_SESSION['canva_error'] = 'رفض Canva التفويض: ' . $errMsg;
    header('Location: canva_settings.php');
    exit();
}

// --- التحقق من المتطلبات ---
$code  = $_GET['code']  ?? '';
$state = $_GET['state'] ?? '';

if ($code === '' || $state === '') {
    $_SESSION['canva_error'] = 'استجابة غير مكتملة من Canva (code أو state مفقود)';
    header('Location: canva_settings.php');
    exit();
}

// --- استبدال code بـ tokens ---
if ($canva->handleCallback($code, $state)) {
    $_SESSION['canva_success'] = 'تم الربط مع Canva بنجاح! يمكنك الآن اختيار قوالب التصميم.';
    header('Location: canva_settings.php');
} else {
    $_SESSION['canva_error'] = 'فشل استبدال رمز التفويض. تأكد من صلاحية الـ scopes وأعد المحاولة.';
    header('Location: canva_settings.php');
}
exit();
