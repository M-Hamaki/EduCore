<?php

declare(strict_types=1);

$root = dirname(__DIR__);
$failures = [];

$expect = [
    'play_activity.php' => ['requireCsrfToken(', 'csrf_token:', 'activity_id'],
    'take_exam.php' => ['csrf_token:', "teacher/ajax/submit_exam.php"],
    'teacher/ajax/submit_exam.php' => ['requireCsrfToken('],
    'ajax/exam_progress.php' => ['requireCsrfToken('],
    'login.php' => ['requireCsrfPost();'],
    'student/goback.php' => ['requireCsrfPost();'],
    'specialist/students.php' => ['requireCsrfPost();', 'name="csrf_token"'],
    'supervisor/select_mode.php' => ['requireCsrfPost();', 'requireCsrfToken('],
];

foreach ($expect as $path => $needles) {
    $source = (string) file_get_contents($root . '/' . $path);
    foreach ($needles as $needle) {
        if (strpos($source, $needle) === false) {
            $failures[] = $path . ':' . $needle;
        }
    }
}

$retired = (string) file_get_contents($root . '/auth/teams_context_login.php');
if (strpos($retired, 'http_response_code(410)') === false || strpos($retired, 'loginUser(') !== false) {
    $failures[] = 'insecure_teams_context_not_retired';
}

$sso = (string) file_get_contents($root . '/classes/MicrosoftSSO.php');
foreach (["empty(\$payload['iss'])", "empty(\$payload['aud'])", "empty(\$payload['tid'])", "!isset(\$payload['exp'])"] as $needle) {
    if (strpos($sso, $needle) === false) {
        $failures[] = 'sso_claim:' . $needle;
    }
}

if ($failures) {
    fwrite(STDERR, 'FAIL: ' . implode(', ', $failures) . PHP_EOL);
    exit(1);
}

echo "PASS: anonymous write flows and Microsoft identity exchange have explicit integrity controls.\n";
