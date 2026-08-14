<?php

declare(strict_types=1);

define('ACCESS_ALLOWED', true);

require_once __DIR__ . '/config/env_loader.php';
ini_set('display_errors', '0');
ini_set('display_startup_errors', '0');
error_reporting(E_ALL);
ini_set('log_errors', '1');

require_once __DIR__ . '/includes/session_config.php';
require_once __DIR__ . '/includes/template_helper.php';
require_once __DIR__ . '/vendor/autoload.php';

use EduCore\Modules\PublicPortal\Application\GetPublicPortalView;
use EduCore\Modules\PublicPortal\Domain\IntroVisitPolicy;

header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Cache-Control: post-check=0, pre-check=0', false);
header('Pragma: no-cache');
header('Expires: Sat, 26 Jul 1997 05:00:00 GMT');

if (isset($_SESSION['user_id'])) {
    require_once __DIR__ . '/classes/utilities.php';
    if (!empty($_SESSION['role_selection_required'])) {
        header('Location: select_role.php');
    } else {
        header('Location: ' . Utilities::getDashboardUrl((string) ($_SESSION['active_role'] ?? $_SESSION['role'] ?? '')));
    }
    exit;
}

$loginError = trim((string) ($_SESSION['login_access_message'] ?? ''));
unset($_SESSION['login_access_message']);
$oldUsername = trim((string) ($_SESSION['login_username'] ?? ''));
unset($_SESSION['login_username']);
if ($loginError === '' && isset($_GET['error'])) {
    $errorLabels = [
        'session_expired' => 'انتهت جلستك. يرجى تسجيل الدخول من جديد.',
        'timeout' => 'انتهت مدة عدم النشاط. يرجى تسجيل الدخول من جديد.',
        'microsoft_unavailable' => 'تعذر بدء تسجيل الدخول بحساب Microsoft حالياً. يمكنك استخدام اسم المستخدم وكلمة المرور.',
    ];
    $errorRaw = trim((string) $_GET['error']);
    $loginError = $errorLabels[$errorRaw] ?? $errorRaw;
} elseif (isset($_GET['timeout'])) {
    $loginError = 'انتهت مدة عدم النشاط. يرجى تسجيل الدخول من جديد.';
}

$teamsContext = (string) ($_GET['from_teams'] ?? '') === '1';
$portalConfig = require __DIR__ . '/config/public_portal.php';
if (empty($portalConfig['unified_access_portal_enabled'])) {
    $legacyStage = trim((string) ($_GET['stage'] ?? 'kindergarten'));
    if (!in_array($legacyStage, ['kindergarten', 'preparatory', 'secondary'], true)) {
        $legacyStage = 'kindergarten';
    }
    header('Location: public_portal.php?stage=' . rawurlencode($legacyStage));
    exit;
}
$introPolicy = new IntroVisitPolicy((int) ($portalConfig['intro_interval_seconds'] ?? 1296000));
$shouldShowIntro = $introPolicy->shouldShow(
    isset($_COOKIE[IntroVisitPolicy::COOKIE_NAME]) ? (string) $_COOKIE[IntroVisitPolicy::COOKIE_NAME] : null,
    !empty($_SESSION['intro_shown']),
    $teamsContext,
    isset($_GET['skip_intro']),
    $loginError !== ''
);
if ($shouldShowIntro) {
    header('Location: intro_youtube.php?destination=portal');
    exit;
}
if (isset($_GET['skip_intro'])) {
    $_SESSION['intro_shown'] = true;
}

require_once __DIR__ . '/config/database.php';
require_once __DIR__ . '/includes/notifications_helper.php';
$database = new Database();
$db = $database->getConnection();
if (!$db) {
    http_response_code(503);
    exit('تعذر الاتصال بالخدمة حالياً. يرجى المحاولة لاحقاً.');
}
$db->exec("SET NAMES 'utf8mb4'");
$publicNotifications = getPublicNotifications($db);
$portalView = (new GetPublicPortalView())->execute();
?>
<!DOCTYPE html>
<html lang="ar" dir="rtl">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta http-equiv="Cache-Control" content="no-cache, no-store, must-revalidate">
    <meta http-equiv="Pragma" content="no-cache">
    <meta http-equiv="Expires" content="0">
    <title>بوابة التعلم الرقمي - DMLS</title>
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.rtl.min.css">
    <link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=Cairo:wght@300;400;600;700;800&display=swap">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.2/css/all.min.css">
    <link rel="stylesheet" href="assets/css/buttons.css?v=1.0">
    <link rel="stylesheet" href="assets/css/public-portal.css?v=1.0">
</head>
<body class="public-portal-page">
    <div id="particles-js" aria-hidden="true"></div>

    <main class="portal-main">
        <header class="portal-brand">
            <img src="<?= htmlspecialchars(asset_url(get_school_logo('')), ENT_QUOTES, 'UTF-8') ?>"
                alt="شعار مدارس دلتا الحديثة للغات" class="portal-school-logo">
            <h1>🎓 بوابة التعلم الرقمي</h1>
        </header>

        <?php if (!empty($publicNotifications)): ?>
            <div class="portal-notifications"><?= renderPublicNotificationAlerts($publicNotifications) ?></div>
        <?php endif; ?>

        <?php require __DIR__ . '/includes/public_login_portal.php'; ?>
    </main>

    <footer class="portal-footer">
        <p><strong>جميع الحقوق محفوظة © <?= date('Y') ?></strong></p>
        <p>Delta Modern Language Schools<br>Computer Department</p>
        <div class="portal-social-links" aria-label="روابط التواصل الاجتماعي">
            <a href="https://www.facebook.com/DELTA.MLS" target="_blank" rel="noopener noreferrer" class="facebook" aria-label="فيسبوك"><i class="fab fa-facebook-f"></i></a>
            <a href="https://wa.me/201289999818" target="_blank" rel="noopener noreferrer" class="whatsapp" aria-label="واتساب"><i class="fab fa-whatsapp"></i></a>
            <a href="https://www.instagram.com/delta.mls" target="_blank" rel="noopener noreferrer" class="instagram" aria-label="إنستجرام"><i class="fab fa-instagram"></i></a>
        </div>
    </footer>

    <button type="button" class="theme-toggle" aria-label="تبديل المظهر"><i class="fas fa-moon"></i></button>
    <script src="https://cdn.jsdelivr.net/particles.js/2.0.0/particles.min.js"></script>
    <script src="assets/js/public-portal.js?v=1.0"></script>
</body>
</html>
