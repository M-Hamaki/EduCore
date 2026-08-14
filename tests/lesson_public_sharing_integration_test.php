<?php

declare(strict_types=1);

require_once __DIR__ . '/bootstrap_test_database.php';
require_once dirname(__DIR__) . '/src/Modules/LearningContent/LessonShareService.php';

use EduCore\Modules\LearningContent\LessonShareService;

$databaseArgument = '';
foreach (array_slice($argv ?? [], 1) as $argument) {
    if (str_starts_with((string) $argument, '--database=')) {
        $databaseArgument = trim(substr((string) $argument, 11));
    }
}
if ($databaseArgument !== '') {
    putenv('EDUCORE_TEST_DB_NAME=' . $databaseArgument);
}

$db = educoreTestDatabase();
$auditMigration = require dirname(__DIR__) . '/database/migrations/20260716_audit_undo_engine_v2.php';
$auditMigration($db);
$migration = require dirname(__DIR__) . '/database/migrations/20260726_ai_lesson_public_sharing.php';
$migration($db);

if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
}
$_SESSION['user_id'] = 987654321;
$_SESSION['role'] = 'teacher';

$checks = [];
$db->beginTransaction();
try {
    $insert = $db->prepare(
        "INSERT INTO ai_lessons
            (teacher_id, title, original_content, status)
         VALUES (?, ?, ?, 'completed')"
    );
    $insert->execute([
        987654321,
        'درس مشاركة اختباري',
        'محتوى اصطناعي لاختبار الرابط العام',
    ]);
    $lessonId = (int) $db->lastInsertId();

    $service = new LessonShareService($db);
    $enabled = $service->enable($lessonId, 987654321);
    $shareUrl = (string) ($enabled['share_url'] ?? '');
    parse_str((string) parse_url($shareUrl, PHP_URL_QUERY), $query);
    $token = (string) ($query['token'] ?? '');

    $publicLesson = $service->findPublicLesson($token);
    $ownerState = $service->getOwnerState($lessonId, 987654321);
    $revoked = $service->revoke($lessonId, 987654321);

    $checks = [
        'completed_lesson_creates_public_link' =>
            ($enabled['enabled'] ?? false) === true
            && LessonShareService::isValidToken($token)
            && str_contains($shareUrl, '/shared_lesson.php?token='),
        'owner_state_returns_the_same_active_link' =>
            ($ownerState['enabled'] ?? false) === true
            && ($ownerState['share_url'] ?? null) === $shareUrl,
        'public_lookup_returns_the_shared_lesson' =>
            (int) ($publicLesson['id'] ?? 0) === $lessonId
            && ($publicLesson['status'] ?? '') === 'completed',
        'revocation_invalidates_the_public_link' =>
            ($revoked['enabled'] ?? true) === false
            && $service->findPublicLesson($token) === null,
    ];
} finally {
    if ($db->inTransaction()) {
        $db->rollBack();
    }
}

foreach ($checks as $name => $passed) {
    echo $name . ':' . ($passed ? 'PASS' : 'FAIL') . PHP_EOL;
}

exit(in_array(false, $checks, true) ? 1 : 0);
