<?php

require_once '../../includes/session_config.php';
require_once '../../includes/http_helpers.php';
require_once '../../classes/utilities.php';

header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache');

requireJsonUser();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    jsonError('طريقة غير مسموحة', 405);
}

requireCsrfToken();

/*
 * Password disclosure has been permanently disabled.
 *
 * Administrators must reset credentials instead of retrieving
 * an existing user's plaintext password.
 *
 * Legacy encrypted credentials may still exist temporarily for
 * backward-compatible login migration, but they must never be
 * exposed through an HTTP endpoint.
 */
jsonError(
    'تم إلغاء ميزة عرض كلمة المرور لأسباب أمنية. استخدم إعادة تعيين كلمة المرور بدلاً من ذلك.',
    410
);
