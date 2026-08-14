<?php
declare(strict_types=1);

require_once '../includes/session_config.php';
require_once '../classes/utilities.php';

Utilities::validateSession('specialist');
http_response_code(410);
header('Content-Type: text/html; charset=utf-8');
echo '<div class="alert alert-info">تم إيقاف نافذة التقييم القديمة. استخدم صفحة تقارير التقييم الجديدة المقيدة بنطاقك.</div>';
