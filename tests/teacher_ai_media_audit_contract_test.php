<?php

declare(strict_types=1);

$root = dirname(__DIR__);
$chat = (string) file_get_contents($root . '/teacher/ajax/ai_chat.php');
$image = (string) file_get_contents($root . '/teacher/ajax/generate_image.php');
$generator = (string) file_get_contents($root . '/classes/LessonGenerator.php');
$regenerate = (string) file_get_contents($root . '/teacher/ajax/regenerate_section.php');
$lifecycle = (string) file_get_contents($root . '/src/Modules/LearningContent/AiLessonLifecycleService.php');
$generateLesson = (string) file_get_contents($root . '/teacher/ajax/generate_lesson.php');
$generatePowerPoint = (string) file_get_contents($root . '/teacher/ajax/generate_powerpoint_only.php');

$checks = [
    'chat_mutations_use_central_audit' => substr_count($chat, 'recordEvent(') === 3
        && substr_count($chat, 'beginTransaction()') === 3
        && substr_count($chat, '->commit()') === 3,
    'chat_ownership_and_deletion_are_locked' => substr_count($chat, 'FOR UPDATE') >= 3
        && strpos($chat, "'message_count' => \$messageCount") !== false,
    'chat_audit_uses_hashes_not_message_content' => substr_count($chat, "'content_sha256'") === 2
        && substr_count($chat, "'content_length'") === 2
        && strpos($chat, "'content' => \$message") === false
        && strpos($chat, "'content' => \$response") === false,
    'chat_failures_rollback_and_hide_internal_errors' => substr_count($chat, '->rollBack()') === 3
        && strpos($chat, "'message' => 'حدث خطأ: ' . \$e->getMessage()") === false,
    'image_success_metadata_and_audit_are_atomic' => strpos($image, "'ai_image_generated'") !== false
        && substr_count($image, 'beginTransaction()') >= 2
        && substr_count($image, '->commit()') >= 2,
    'image_audit_excludes_prompt_and_binary_payload' => strpos($image, "'prompt_sha256'") !== false
        && strpos($image, "'prompt_length'") !== false
        && strpos($image, "'prompt' => \$prompt") === false
        && strpos($image, "'image_data'") === false,
    'image_failure_is_audited_without_provider_error' => strpos($image, "'ai_image_generation_failed'") !== false
        && strpos($image, "['outcome' => 'failure']") !== false
        && strpos($image, "'provider_error' =>") === false,
    'image_database_failure_is_not_silently_accepted' => strpos($image, 'تم توليد الصورة لكن تعذر تسجيلها بأمان') !== false
        && substr_count($image, '->rollBack()') >= 2,
    'lesson_generator_owns_create_and_result_audit' => substr_count($generator, 'recordEvent(') >= 3
        && strpos($generator, "'content_sha256'") !== false
        && strpos($generator, "'results_sha256'") !== false,
    'lesson_generator_writes_are_atomic' => strpos($generator, '$ownsTransaction = !$this->db->inTransaction()') !== false
        && substr_count($generator, 'beginTransaction()') >= 3
        && substr_count($generator, '->rollBack()') >= 3,
    'section_regeneration_is_locked_atomic_and_audited' => substr_count($regenerate, 'FOR UPDATE') >= 2
        && substr_count($regenerate, 'beginTransaction()') >= 2
        && substr_count($regenerate, 'recordEvent(') === 2
        && substr_count($regenerate, '->commit()') >= 2,
    'section_regeneration_detects_concurrent_changes' => strpos($regenerate, 'Lesson section changed during generation.') !== false
        && strpos($regenerate, 'Lesson exam changed during generation.') !== false,
    'section_audit_uses_fingerprints_not_generated_payload' => strpos($regenerate, "'before_sha256'") !== false
        && strpos($regenerate, "'after_sha256'") !== false
        && strpos($regenerate, "'result' => \$result") === false,
    'ai_lesson_lifecycle_owner_locks_and_audits' => strpos($lifecycle, 'FOR UPDATE') !== false
        && strpos($lifecycle, 'recordEvent(') !== false
        && strpos($lifecycle, 'beginTransaction()') !== false
        && strpos($lifecycle, '->rollBack()') !== false,
    'ai_lesson_lifecycle_audit_uses_compact_evidence' => strpos($lifecycle, "'changed_fields'") !== false
        && strpos($lifecycle, "'before_sha256'") !== false
        && strpos($lifecycle, "'after_sha256'") !== false
        && strpos($lifecycle, "'generation_error' =>") === false,
    'lesson_generation_delegates_all_lifecycle_writes' => strpos($generateLesson, 'UPDATE ai_lessons SET') === false
        && substr_count($generateLesson, 'AiLessonLifecycleService') >= 6,
    'powerpoint_generation_delegates_all_lifecycle_writes' => strpos($generatePowerPoint, 'UPDATE ai_lessons SET') === false
        && substr_count($generatePowerPoint, 'AiLessonLifecycleService') >= 4,
];

$failed = [];
foreach ($checks as $name => $passed) {
    echo $name . ':' . ($passed ? 'PASS' : 'FAIL') . PHP_EOL;
    if (!$passed) $failed[] = $name;
}

exit($failed ? 1 : 0);
