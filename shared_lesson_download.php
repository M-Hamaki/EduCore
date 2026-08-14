<?php

declare(strict_types=1);

define('EDUCORE_SUPPRESS_NO_CACHE_HEADERS', true);

require_once __DIR__ . '/config/database.php';
require_once __DIR__ . '/src/Modules/LearningContent/LessonShareService.php';

use EduCore\Modules\LearningContent\LessonShareService;

header('X-Content-Type-Options: nosniff');
header('Referrer-Policy: no-referrer');
header('Cache-Control: private, no-store, must-revalidate, max-age=0');
header('Pragma: no-cache');

$token = isset($_GET['token']) ? strtolower(trim((string) $_GET['token'])) : '';
$type = isset($_GET['type']) ? trim((string) $_GET['type']) : '';

if (!LessonShareService::isValidToken($token) || !in_array($type, ['exam', 'powerpoint'], true)) {
    http_response_code(404);
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
        exit;
    }

    $title = trim((string) ($lesson['title'] ?? 'lesson'));
    $filenameBase = preg_replace('~[\\\\/:*?"<>|\x00-\x1F\x7F]+~u', ' ', $title);
    $filenameBase = trim((string) preg_replace('~\s+~u', ' ', (string) $filenameBase));
    if ($filenameBase === '') {
        $filenameBase = 'lesson_' . (int) $lesson['id'];
    }
    $filenameBase = mb_substr($filenameBase, 0, 80, 'UTF-8');

    while (ob_get_level() > 0) {
        ob_end_clean();
    }

    if ($type === 'exam') {
        $content = (string) ($lesson['exam_html'] ?? '');
        if ($content === '') {
            http_response_code(404);
            exit;
        }

        header('Content-Type: text/html; charset=utf-8');
        header("Content-Disposition: attachment; filename=\"lesson_exam.html\"; filename*=UTF-8''" . rawurlencode($filenameBase . '_exam.html'));
        header('Content-Length: ' . strlen($content));
        echo $content;
        exit;
    }

    $relativePath = str_replace('\\', '/', ltrim((string) ($lesson['powerpoint_path'] ?? ''), '/\\'));
    $root = realpath(__DIR__);
    $absolutePath = $relativePath !== '' ? realpath(__DIR__ . DIRECTORY_SEPARATOR . $relativePath) : false;
    $insideRoot = $root !== false
        && $absolutePath !== false
        && str_starts_with($absolutePath, $root . DIRECTORY_SEPARATOR);
    if (!$insideRoot || !is_file($absolutePath)) {
        http_response_code(404);
        exit;
    }

    header('Content-Type: application/vnd.openxmlformats-officedocument.presentationml.presentation');
    header("Content-Disposition: attachment; filename=\"lesson.pptx\"; filename*=UTF-8''" . rawurlencode($filenameBase . '.pptx'));
    header('Content-Length: ' . (string) filesize($absolutePath));
    readfile($absolutePath);
} catch (Throwable $e) {
    error_log('Shared lesson download failed: ' . $e->getMessage());
    http_response_code(404);
}
