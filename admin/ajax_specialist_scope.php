<?php

declare(strict_types=1);

http_response_code(410);
header('Content-Type: application/json; charset=utf-8');
echo json_encode([
    'success' => false,
    'message' => 'تم إيقاف مسار نطاق الأخصائي القديم. استخدم نموذج حسابات العاملين الموحد.',
], JSON_UNESCAPED_UNICODE);
