<?php

declare(strict_types=1);

$source = (string) file_get_contents(dirname(__DIR__) . '/classes/ExamGenerator.php');
$start = strpos($source, 'public function saveQuiz(');
$method = $start === false ? '' : substr($source, $start);

$checks = [
    'quiz_save_owns_or_joins_transaction' => strpos($method, '$ownsTransaction = !$db->inTransaction()') !== false
        && strpos($method, 'beginTransaction()') !== false
        && strpos($method, '->commit()') !== false
        && strpos($method, '->rollBack()') !== false,
    'quiz_save_uses_central_audit' => strpos($method, "'ai_quiz_saved'") !== false
        && strpos($method, 'recordEvent(') !== false,
    'quiz_audit_uses_fingerprint_not_questions' => strpos($method, "'questions_sha256'") !== false
        && strpos($method, "'questions_bytes'") !== false
        && strpos($method, "'questions' => \$examQuestions") === false,
    'quiz_save_hides_internal_database_errors' => strpos($method, 'تعذر حفظ الامتحان بأمان') !== false
        && strpos($method, "'خطأ في حفظ الامتحان: ' . \$e->getMessage()") === false,
];

$failed = [];
foreach ($checks as $name => $passed) {
    echo $name . ':' . ($passed ? 'PASS' : 'FAIL') . PHP_EOL;
    if (!$passed) $failed[] = $name;
}
exit($failed ? 1 : 0);
