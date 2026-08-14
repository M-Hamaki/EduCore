<?php

declare(strict_types=1);

define('EDUCORE_SUPPRESS_NO_CACHE_HEADERS', true);

require_once __DIR__ . '/config/database.php';
require_once __DIR__ . '/vendor/autoload.php';
require_once __DIR__ . '/classes/RateLimiter.php';
require_once __DIR__ . '/src/Modules/LearningContent/LessonShareService.php';
require_once __DIR__ . '/src/Modules/LearningContent/LessonExportService.php';

use EduCore\Modules\LearningContent\LessonExportService;
use EduCore\Modules\LearningContent\LessonShareService;

header('X-Content-Type-Options: nosniff');
header('Cross-Origin-Resource-Policy: same-origin');
header('Referrer-Policy: no-referrer');
header('Cache-Control: private, no-store, must-revalidate, max-age=0');
header('Pragma: no-cache');

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    http_response_code(405);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['success' => false, 'message' => 'طريقة الطلب غير صالحة'], JSON_UNESCAPED_UNICODE);
    exit;
}

$token = isset($_POST['token']) ? strtolower(trim((string) $_POST['token'])) : '';
$format = isset($_POST['format']) ? trim((string) $_POST['format']) : '';
$fragment = isset($_POST['content_html']) ? (string) $_POST['content_html'] : '';

if (!LessonShareService::isValidToken($token)
    || !in_array($format, ['html', 'word', 'pdf'], true)) {
    http_response_code(404);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['success' => false, 'message' => 'رابط التصدير غير صالح'], JSON_UNESCAPED_UNICODE);
    exit;
}

$clientIp = trim((string) ($_SERVER['REMOTE_ADDR'] ?? 'unknown'));
$rateKey = 'shared_lesson_export:' . hash('sha256', $token . '|' . $clientIp);
if (!RateLimiter::hit($rateKey, 12, 60)) {
    http_response_code(429);
    header('Retry-After: 60');
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(
        ['success' => false, 'message' => 'تم تجاوز عدد محاولات التصدير. حاول بعد دقيقة.'],
        JSON_UNESCAPED_UNICODE
    );
    exit;
}

try {
    $db = (new Database())->getConnection();
    if (!$db instanceof PDO) {
        throw new RuntimeException('Database connection is unavailable.');
    }

    $lesson = (new LessonShareService($db))->findPublicLesson($token);
    if (!$lesson) {
        http_response_code(404);
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode(['success' => false, 'message' => 'رابط الدرس لم يعد متاحًا'], JSON_UNESCAPED_UNICODE);
        exit;
    }

    $lessonId = (int) $lesson['id'];
    $artifact = (new LessonExportService())->createExportArtifact(
        $format,
        (string) $lesson['title'],
        $lessonId,
        $fragment
    );

    while (ob_get_level() > 0) {
        ob_end_clean();
    }

    $payload = $artifact['payload'];
    $extension = $artifact['extension'];
    header('Content-Type: ' . $artifact['content_type']);
    header(
        "Content-Disposition: attachment; filename=\"lesson.{$extension}\"; filename*=UTF-8''"
        . rawurlencode($artifact['download_name'])
    );
    header('Content-Length: ' . strlen($payload));
    echo $payload;
} catch (Throwable $e) {
    error_log('Shared lesson export failed: ' . $e->getMessage());
    http_response_code(422);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(
        ['success' => false, 'message' => 'تعذر إنشاء ملف التصدير من هذا الدرس.'],
        JSON_UNESCAPED_UNICODE
    );
}
