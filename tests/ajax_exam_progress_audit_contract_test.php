<?php

declare(strict_types=1);

$source = (string)file_get_contents(dirname(__DIR__) . '/ajax/exam_progress.php');
$auditStart = strpos($source, '(new \\EduCore\\Modules\\Operations\\Audit\\AuditService');
$auditEnd = strpos($source, '$db->commit()', $auditStart ?: 0);
$saveAudit = ($auditStart !== false && $auditEnd !== false)
    ? substr($source, $auditStart, $auditEnd - $auditStart)
    : '';

$checks = [
    'save_and_clear_are_audited' => substr_count($source, 'recordEvent(') === 2
        && substr_count($source, "'public_exam_progress_restore_not_enabled'") === 2,
    'progress_writes_are_atomic' => substr_count($source, 'beginTransaction()') === 2
        && substr_count($source, '->commit()') === 2
        && strpos($source, '->rollBack()') !== false,
    'progress_rows_are_locked_before_mutation' => substr_count($source, 'FOR UPDATE') === 2,
    'answers_are_fingerprinted_not_copied_to_audit' => $saveAudit !== ''
        && strpos($saveAudit, "'answers_fingerprint'") !== false
        && strpos($saveAudit, "'answer_count'") !== false
        && strpos($saveAudit, "'answers_data' =>") === false
        && strpos($saveAudit, "'answers' =>") === false,
    'public_session_identifier_is_hashed' => substr_count($source, "'session_fingerprint' => hash('sha256', \$sessionId)") === 2,
];

$failed = [];
foreach ($checks as $name => $passed) {
    echo $name . ':' . ($passed ? 'PASS' : 'FAIL') . PHP_EOL;
    if (!$passed) {
        $failed[] = $name;
    }
}
exit($failed ? 1 : 0);
