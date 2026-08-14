<?php
require_once '../../includes/session_config.php';
require_once '../../classes/utilities.php';

header('Content-Type: application/json; charset=utf-8');

try {
    Utilities::validateSession('admin');
} catch (Throwable $e) {
    echo json_encode(['success' => false, 'message' => 'غير مصرح']);
    exit();
}

echo json_encode([
    'success' => false,
    'message' => 'تمت أرشفة تعديل درجات التقارير القديم. استخدم محرك الدرجات الجديد ونوافذ الرصد.',
    'redirect' => '../assessment_reports.php',
]);
exit();
