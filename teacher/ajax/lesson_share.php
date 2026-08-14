<?php

declare(strict_types=1);

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

require_once '../../includes/session_config.php';
require_once '../../includes/csrf.php';
require_once '../../config/database.php';
require_once '../../src/Modules/LearningContent/LessonShareService.php';

use EduCore\Modules\LearningContent\LessonShareService;

if (!isset($_SESSION['user_id']) || !in_array($_SESSION['role'] ?? '', ['teacher', 'external_teacher'], true)) {
    http_response_code(401);
    echo json_encode(['success' => false, 'message' => 'انتهت الجلسة أو لا تملك صلاحية الوصول'], JSON_UNESCAPED_UNICODE);
    exit;
}

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'message' => 'طريقة الطلب غير صالحة'], JSON_UNESCAPED_UNICODE);
    exit;
}

requireCsrfPost();

$lessonId = isset($_POST['lesson_id']) ? (int) $_POST['lesson_id'] : 0;
$action = isset($_POST['action']) ? trim((string) $_POST['action']) : 'status';

if ($lessonId <= 0 || !in_array($action, ['status', 'enable', 'revoke'], true)) {
    http_response_code(422);
    echo json_encode(['success' => false, 'message' => 'بيانات المشاركة غير صالحة'], JSON_UNESCAPED_UNICODE);
    exit;
}

try {
    $db = (new Database())->getConnection();
    if (!$db instanceof PDO) {
        throw new RuntimeException('Database connection is unavailable.');
    }

    $service = new LessonShareService($db);
    $teacherId = (int) $_SESSION['user_id'];

    if ($action === 'enable') {
        $state = $service->enable($lessonId, $teacherId);
        $message = 'تم إنشاء رابط المشاركة. أي شخص يملك الرابط يستطيع مشاهدة الدرس.';
    } elseif ($action === 'revoke') {
        $state = $service->revoke($lessonId, $teacherId);
        $message = 'تم إلغاء رابط المشاركة ولم يعد صالحًا.';
    } else {
        $state = $service->getOwnerState($lessonId, $teacherId);
        $message = '';
    }

    echo json_encode(
        ['success' => true, 'message' => $message] + $state,
        JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
    );
} catch (Throwable $e) {
    error_log('Lesson share endpoint failed: ' . $e->getMessage());
    http_response_code(422);
    echo json_encode(
        ['success' => false, 'message' => 'تعذر تحديث رابط المشاركة. تأكد من اكتمال الدرس ثم حاول مرة أخرى.'],
        JSON_UNESCAPED_UNICODE
    );
}
