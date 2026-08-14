<?php
/**
 * endpoint خفيف لإرجاع رمز CSRF الحالي من الجلسة.
 *
 * يُستخدم من قبل ai_lesson_csrf.js لتحديث الـ token المحفوظ في الـ <meta>
 * بعد فشل طلب POST بسبب HTTP 419 (انتهت صلاحية رمز الأمان الناتج عن
 * session_regenerate_id كل 30 دقيقة في session_config.php).
 *
 * أمان: يتطلب جلسة معلم/مشرف صالحة فقط. لا يتطلب CSRF ذاته (لأن الهدف منه
 * توفير token صالح لطلبات CSRF اللاحقة). الـ token نفسه ليس سرّاً مستقلاً
 * (يُولَّد لكل جلسة ويُمرَّر للعميل في كل صفحة عبر <meta>).
 */

require_once __DIR__ . '/../../includes/session_config.php';

// التحقق من وجود جلسة صالحة (معلم/معلم خارجي/مشرف).
if (!isset($_SESSION['user_id']) || !in_array($_SESSION['role'] ?? '', ['teacher', 'external_teacher', 'admin', 'super_admin'], true)) {
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['success' => false, 'message' => 'غير مصرّح'], JSON_UNESCAPED_UNICODE);
    exit;
}

// ضمان وجود token في الجلسة (session_config.php يُنشئه إن لم يوجد).
if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

header('Content-Type: application/json; charset=utf-8');
echo json_encode([
    'success' => true,
    'csrf_token' => $_SESSION['csrf_token'],
], JSON_UNESCAPED_UNICODE);
