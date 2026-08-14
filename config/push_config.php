<?php
/**
 * إعدادات الإشعارات الفورية (Push Notifications)
 * تم التوليد تلقائياً - لا تقم بتغيير المفاتيح بعد بدء الاستخدام
 */

require_once __DIR__ . '/env_loader.php';

define('VAPID_SUBJECT', env('VAPID_SUBJECT', 'mailto:admin@' . parse_url(SITE_URL, PHP_URL_HOST)));
define('VAPID_PUBLIC_KEY', env('VAPID_PUBLIC_KEY', ''));
define('VAPID_PRIVATE_KEY', env('VAPID_PRIVATE_KEY', ''));
