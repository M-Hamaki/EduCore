<?php

declare(strict_types=1);

require_once __DIR__ . '/bootstrap_test_database.php';

$databaseArgument = '';
foreach (array_slice($argv ?? [], 1) as $argument) {
    if (str_starts_with((string) $argument, '--database=')) {
        $databaseArgument = trim(substr((string) $argument, 11));
    }
}
if ($databaseArgument !== '') {
    putenv('EDUCORE_TEST_DB_NAME=' . $databaseArgument);
}

$databaseName = trim((string) getenv('EDUCORE_TEST_DB_NAME'));
if ($databaseName === '') {
    throw new RuntimeException('Pass an isolated test database with --database=...');
}

$root = dirname(__DIR__);
$db = educoreTestDatabase();
$auditMigration = require $root . '/database/migrations/20260716_audit_undo_engine_v2.php';
$auditMigration($db);
$sharingMigration = require $root . '/database/migrations/20260726_ai_lesson_public_sharing.php';
$sharingMigration($db);

$token = bin2hex(random_bytes(32));
$lessonId = 0;
$clientIp = '127.0.0.251';
$rateKey = 'shared_lesson_export:' . hash('sha256', $token . '|' . $clientIp);
$rateFile = $root . '/storage/framework/rate_limits/' . sha1($rateKey) . '.json';
$checks = [];

$runEndpoint = static function (string $format) use (
    $root,
    $databaseName,
    $token,
    $clientIp
): array {
    $post = base64_encode((string) json_encode([
        'token' => $token,
        'format' => $format,
        'content_html' =>
            '<section class="lesson-export-section" data-export-key="lesson_plan">'
            . '<h1>تحضير الدرس</h1><p>شرح عربي واضح للاختبار.</p></section>'
            . '<section class="lesson-export-section" data-export-key="lesson_plan">'
            . '<h1>نسخة مكررة</h1><p>يجب حذفها.</p></section>'
            . '<section class="lesson-export-section" data-export-key="question_bank">'
            . '<h1>بنك الأسئلة</h1><p>ما الفكرة الرئيسية؟</p></section>',
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));

    $code =
        "putenv('DB_NAME=" . addslashes($databaseName) . "');"
        . "\$_ENV['DB_NAME']='" . addslashes($databaseName) . "';"
        . "\$_SERVER['REQUEST_METHOD']='POST';"
        . "\$_SERVER['REMOTE_ADDR']='" . addslashes($clientIp) . "';"
        . "\$_POST=json_decode(base64_decode('" . $post . "'),true);"
        . "require '" . addslashes($root . '/shared_lesson_export.php') . "';";

    $pipes = [];
    $process = proc_open(
        [PHP_BINARY, '-r', $code],
        [
            0 => ['pipe', 'r'],
            1 => ['pipe', 'w'],
            2 => ['pipe', 'w'],
        ],
        $pipes,
        $root
    );
    if (!is_resource($process)) {
        throw new RuntimeException('Could not start shared export endpoint process.');
    }

    fclose($pipes[0]);
    $stdout = stream_get_contents($pipes[1]);
    $stderr = stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);
    $exitCode = proc_close($process);

    return [
        'exit_code' => $exitCode,
        'stdout' => is_string($stdout) ? $stdout : '',
        'stderr' => is_string($stderr) ? $stderr : '',
    ];
};

try {
    $insert = $db->prepare(
        "INSERT INTO ai_lessons
            (teacher_id, title, original_content, status,
             public_share_token, public_share_enabled_at, public_share_revoked_at)
         VALUES (?, ?, ?, 'completed', ?, NOW(), NULL)"
    );
    $insert->execute([
        987654322,
        'درس تصدير عام اختباري',
        'بيانات اصطناعية داخل قاعدة الاختبار فقط',
        $token,
    ]);
    $lessonId = (int) $db->lastInsertId();

    $html = $runEndpoint('html');
    $word = $runEndpoint('word');
    $pdf = $runEndpoint('pdf');

    $checks = [
        'public_html_export_succeeds_with_arabic_and_no_duplicate_section' =>
            $html['exit_code'] === 0
            && $html['stderr'] === ''
            && str_contains($html['stdout'], 'شرح عربي واضح للاختبار')
            && substr_count($html['stdout'], 'data-export-key="lesson_plan"') === 1
            && !str_contains($html['stdout'], 'يجب حذفها'),
        'public_word_export_uses_the_same_sanitized_document' =>
            $word['exit_code'] === 0
            && $word['stderr'] === ''
            && str_contains($word['stdout'], '<!DOCTYPE html>')
            && str_contains($word['stdout'], 'بنك الأسئلة')
            && substr_count($word['stdout'], 'data-export-key="lesson_plan"') === 1,
        'public_pdf_export_is_a_real_pdf' =>
            $pdf['exit_code'] === 0
            && $pdf['stderr'] === ''
            && str_starts_with($pdf['stdout'], '%PDF-')
            && strlen($pdf['stdout']) > 1000,
    ];
} finally {
    if ($lessonId > 0) {
        $delete = $db->prepare('DELETE FROM ai_lessons WHERE id = ?');
        $delete->execute([$lessonId]);
    }
    if (is_file($rateFile)) {
        unlink($rateFile);
    }
}

foreach ($checks as $name => $passed) {
    echo $name . ':' . ($passed ? 'PASS' : 'FAIL') . PHP_EOL;
}

exit(in_array(false, $checks, true) ? 1 : 0);
