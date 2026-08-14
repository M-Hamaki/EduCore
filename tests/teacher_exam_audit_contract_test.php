<?php

declare(strict_types=1);

$source = (string)file_get_contents(dirname(__DIR__) . '/teacher/ajax/submit_exam.php');
$publish = (string)file_get_contents(dirname(__DIR__) . '/teacher/ajax/publish_exam.php');
$gradeAjax = (string)file_get_contents(dirname(__DIR__) . '/teacher/ajax/grade_essays.php');
$gradePage = (string)file_get_contents(dirname(__DIR__) . '/teacher/essay_grading.php');
$auditStart = strpos($source, '(new \\EduCore\\Modules\\Operations\\Audit\\AuditService');
$auditEnd = strpos($source, '$db->commit()', $auditStart ?: 0);
$auditBlock = ($auditStart !== false && $auditEnd !== false)
    ? substr($source, $auditStart, $auditEnd - $auditStart)
    : '';

$checks = [
    'exam_submission_is_atomic' => strpos($source, 'beginTransaction()') !== false
        && strpos($source, '->commit()') !== false
        && substr_count($source, '->rollBack()') >= 2,
    'duplicate_check_is_locked' => strpos($source, 'FOR UPDATE') !== false,
    'submission_uses_central_audit' => strpos($auditBlock, "'online_exam_result'") !== false
        && strpos($auditBlock, "'submitted_exam_result_not_direct_undo'") !== false,
    'answers_are_fingerprinted_not_logged' => strpos($auditBlock, "'answers_fingerprint'") !== false
        && strpos($auditBlock, "'answer_count'") !== false
        && strpos($auditBlock, "'answers_data'") === false
        && strpos($auditBlock, "'answers' =>") === false
        && strpos($auditBlock, "'encoded_answers'") === false,
    'exam_publication_is_atomic_composite_audit' => strpos($publish, "'published_exam_composite_restore_not_enabled'") !== false
        && strpos($publish, 'beginTransaction()') !== false
        && strpos($publish, '->commit()') !== false
        && substr_count($publish, '->rollBack()') >= 3,
    'published_questions_are_fingerprinted' => strpos($publish, "'questions_fingerprint'") !== false
        && strpos($publish, "'questions_data' =>") === false,
    'essay_ajax_grading_is_locked_and_atomic' => strpos($gradeAjax, 'FOR UPDATE') !== false
        && strpos($gradeAjax, 'beginTransaction()') !== false
        && strpos($gradeAjax, '->commit()') !== false
        && substr_count($gradeAjax, '->rollBack()') >= 3,
    'essay_ajax_grades_are_fingerprinted' => strpos($gradeAjax, "'grades_fingerprint'") !== false
        && strpos($gradeAjax, "'essay_grades' =>") === false
        && strpos($gradeAjax, "'exam_grading_review_required'") !== false,
    'legacy_essay_page_has_same_audit_policy' => strpos($gradePage, 'FOR UPDATE') !== false
        && strpos($gradePage, "'grades_fingerprint'") !== false
        && strpos($gradePage, "'exam_grading_review_required'") !== false
        && strpos($gradePage, '->rollBack()') !== false,
];

$failed = [];
foreach ($checks as $name => $passed) {
    echo $name . ':' . ($passed ? 'PASS' : 'FAIL') . PHP_EOL;
    if (!$passed) $failed[] = $name;
}
exit($failed ? 1 : 0);
