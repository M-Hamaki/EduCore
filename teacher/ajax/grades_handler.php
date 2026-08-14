<?php
require_once '../../includes/session_config.php';
require_once '../../classes/utilities.php';

header('Content-Type: application/json; charset=utf-8');

try {
    Utilities::validateSession('teacher');
} catch (Throwable $e) {
    echo json_encode(['success' => false, 'message' => 'غير مصرح لك بالوصول']);
    exit();
}

echo json_encode([
    'success' => false,
    'message' => 'تمت أرشفة نظام رصد الدرجات القديم. استخدم صفحة رصد الدرجات الجديدة.',
    'redirect' => '../assessment_marks.php',
]);
exit();
