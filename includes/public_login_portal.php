<?php

declare(strict_types=1);

if (!defined('ACCESS_ALLOWED')) {
    http_response_code(403);
    exit('Direct access is not allowed.');
}

$portalView = is_array($portalView ?? null) ? $portalView : [];
$materialsUrl = isset($portalView['materials_url']) && is_string($portalView['materials_url'])
    ? $portalView['materials_url']
    : null;
$teamsContext = (bool) ($teamsContext ?? false);
$appendTeamsContext = static function (?string $url) use ($teamsContext): ?string {
    if ($url === null || !$teamsContext) {
        return $url;
    }
    return $url . (str_contains($url, '?') ? '&' : '?') . 'from_teams=1';
};
$materialsUrl = $appendTeamsContext($materialsUrl);
$microsoftLoginUrl = 'auth/microsoft_login.php' . ($teamsContext ? '?from_teams=1' : '');
?>
<section class="portal-login-shell" aria-labelledby="portal-login-title">
    <div class="portal-login-card">
        <div class="portal-login-card__heading">
            <h2 id="portal-login-title">تسجيل الدخول</h2>
            <p>استخدم حساب المدرسة أو حساب Microsoft المرتبط به.</p>
        </div>

        <?php if (($loginError ?? '') !== ''): ?>
            <div class="alert alert-danger portal-alert" role="alert">
                <i class="fas fa-circle-exclamation" aria-hidden="true"></i>
                <span><?= htmlspecialchars((string) $loginError, ENT_QUOTES, 'UTF-8') ?></span>
            </div>
        <?php endif; ?>

        <a class="portal-microsoft-button" href="<?= htmlspecialchars($microsoftLoginUrl, ENT_QUOTES, 'UTF-8') ?>">
            <span class="microsoft-mark" aria-hidden="true"><i></i><i></i><i></i><i></i></span>
            <span>تسجيل الدخول بحساب Microsoft</span>
        </a>

        <div class="portal-divider"><span>أو</span></div>

        <form method="post" action="login.php" class="portal-login-form" autocomplete="on">
            <input type="hidden" name="csrf_token" value="<?= htmlspecialchars((string) ($_SESSION['csrf_token'] ?? ''), ENT_QUOTES, 'UTF-8') ?>">
            <?php if ($teamsContext): ?>
                <input type="hidden" name="from_teams" value="1">
            <?php endif; ?>

            <div class="portal-field">
                <label for="portal-username">اسم المستخدم</label>
                <div class="portal-input-wrap">
                    <i class="fas fa-user" aria-hidden="true"></i>
                    <input id="portal-username" name="username" type="text" required autocomplete="username"
                        inputmode="email" placeholder="اسم المستخدم أو البريد المدرسي"
                        value="<?= htmlspecialchars((string) ($oldUsername ?? ''), ENT_QUOTES, 'UTF-8') ?>">
                </div>
            </div>

            <div class="portal-field">
                <label for="portal-password">كلمة المرور</label>
                <div class="portal-input-wrap portal-password-wrap">
                    <i class="fas fa-lock" aria-hidden="true"></i>
                    <input id="portal-password" name="password" type="password" required autocomplete="current-password"
                        placeholder="كلمة المرور">
                    <button type="button" class="portal-password-toggle" aria-label="إظهار كلمة المرور"
                        aria-controls="portal-password" aria-pressed="false">
                        <i class="fas fa-eye" aria-hidden="true"></i>
                    </button>
                </div>
            </div>

            <button type="submit" class="btn btn-primary portal-submit-button">
                <i class="fas fa-right-to-bracket" aria-hidden="true"></i>
                <span>تسجيل الدخول</span>
            </button>
        </form>

        <div class="portal-access-links">
            <?php if ($materialsUrl !== null): ?>
                <a href="<?= htmlspecialchars($materialsUrl, ENT_QUOTES, 'UTF-8') ?>" class="portal-materials-link">
                    <i class="fas fa-download" aria-hidden="true"></i>
                    الذهاب لتحميل الشيتات والملفات مباشرة بدون تسجيل دخول
                </a>
            <?php endif; ?>
        </div>

        <div class="portal-support-card">
            <p class="portal-support-card__text">
                للاستفسارات يمكنكم التواصل مع الدعم الفني عبر رسائل الواتساب على الرقم التالي
            </p>
            <a href="https://wa.me/201289999818" target="_blank" rel="noopener noreferrer" class="portal-support-card__btn">
                <i class="fab fa-whatsapp" aria-hidden="true"></i>
                <span dir="ltr">01289999818</span>
            </a>
        </div>
    </div>
</section>
