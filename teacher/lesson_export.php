<?php

declare(strict_types=1);

define('EDUCORE_SUPPRESS_NO_CACHE_HEADERS', true);

require_once '../includes/session_config.php';
require_once '../includes/csrf.php';
require_once '../config/database.php';
require_once '../vendor/autoload.php';
require_once '../src/Modules/LearningContent/LessonExportService.php';

use EduCore\Modules\LearningContent\LessonExportService;

if (!isset($_SESSION['user_id'])
    || !in_array($_SESSION['role'] ?? '', ['teacher', 'external_teacher', 'admin', 'super_admin'], true)) {
    http_response_code(401);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['success' => false, 'message' => 'انتهت الجلسة أو لا تملك صلاحية التصدير'], JSON_UNESCAPED_UNICODE);
    exit;
}

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    http_response_code(405);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['success' => false, 'message' => 'طريقة الطلب غير صالحة'], JSON_UNESCAPED_UNICODE);
    exit;
}

requireCsrfPost();

$lessonId = isset($_POST['lesson_id']) ? (int) $_POST['lesson_id'] : 0;
$format = isset($_POST['format']) ? trim((string) $_POST['format']) : '';
$fragment = isset($_POST['content_html']) ? (string) $_POST['content_html'] : '';

if ($lessonId <= 0 || !in_array($format, ['html', 'word', 'pdf'], true)) {
    http_response_code(422);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['success' => false, 'message' => 'طلب التصدير غير صالح'], JSON_UNESCAPED_UNICODE);
    exit;
}

try {
    $db = (new Database())->getConnection();
    if (!$db instanceof PDO) {
        throw new RuntimeException('Database connection is unavailable.');
    }

    if (in_array($_SESSION['role'], ['admin', 'super_admin'], true)) {
        $stmt = $db->prepare("SELECT id, title FROM ai_lessons WHERE id = ? AND status = 'completed'");
        $stmt->execute([$lessonId]);
    } else {
        $stmt = $db->prepare(
            "SELECT id, title FROM ai_lessons
             WHERE id = ? AND teacher_id = ? AND status = 'completed'"
        );
        $stmt->execute([$lessonId, (int) $_SESSION['user_id']]);
    }
    $lesson = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$lesson) {
        throw new RuntimeException('Lesson is unavailable for export.');
    }

    $service = new LessonExportService();
    $artifact = $service->createExportArtifact(
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
    header('X-Content-Type-Options: nosniff');
    header('Cache-Control: private, no-store, max-age=0');
    header(
        "Content-Disposition: attachment; filename=\"lesson.{$extension}\"; filename*=UTF-8''"
        . rawurlencode($artifact['download_name'])
    );
    header('Content-Length: ' . strlen($payload));
    echo $payload;
} catch (Throwable $e) {
    error_log('Lesson export failed: ' . $e->getMessage());
    http_response_code(422);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(
        ['success' => false, 'message' => 'تعذر إنشاء ملف التصدير من العناصر المحددة.'],
        JSON_UNESCAPED_UNICODE
    );
}
