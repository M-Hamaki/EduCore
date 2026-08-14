<?php
/**
 * إعدادات مسح الكاش لـ HTML headers
 * يتم تضمينه في جميع الملفات لضمان عدم تخزين أي بيانات مؤقتة
 */

// إعدادات منع التخزين المؤقت الشاملة
function addNoCacheHeaders() {
    // إعدادات HTTP للمتصفحات الحديثة
    header('Cache-Control: no-cache, no-store, must-revalidate, max-age=0');
    header('Pragma: no-cache');
    header('Expires: Thu, 01 Jan 1970 00:00:00 GMT');
    
    // إعدادات إضافية لمنع التخزين المؤقت
    header('Last-Modified: ' . gmdate('D, d M Y H:i:s') . ' GMT');
    header('ETag: "' . md5(microtime()) . '"');
    
    // منع التخزين في الـ proxy servers
    header('Vary: *');
}

// إضافة meta tags لمنع التخزين في HTML
function addNoCacheMetaTags() {
    echo '<meta http-equiv="Cache-Control" content="no-cache, no-store, must-revalidate">' . "\n";
    echo '<meta http-equiv="Pragma" content="no-cache">' . "\n";
    echo '<meta http-equiv="Expires" content="0">' . "\n";
    echo '<meta name="robots" content="noindex, nofollow, noarchive, nosnippet, noimageindex">' . "\n";
}

// تطبيق إعدادات منع التخزين المؤقت
// استثناء: صفحات تنزيل الملفات الثنائية (مثل lesson_download.php) تعطّل هذه الـ headers
// لأن Vary: * و Cache-Control: no-store قد تُفسد التحميل في بعض المتصفحات
// ("Couldn't Download - Network issue").
if (!defined('EDUCORE_SUPPRESS_NO_CACHE_HEADERS')) {
    addNoCacheHeaders();
}
?>