<?php
/**
 * Retired insecure Teams Context login endpoint.
 *
 * Teams context fields are client-controlled and cannot establish identity. Use
 * teams_token_handler.php, which verifies a Microsoft-issued token, instead.
 */

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');
http_response_code(410);

echo json_encode([
    'success' => false,
    'message' => 'تم إيقاف مسار تسجيل الدخول القديم. يرجى تسجيل الدخول باستخدام Microsoft Teams مرة أخرى.',
], JSON_UNESCAPED_UNICODE);
