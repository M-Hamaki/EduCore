<?php

declare(strict_types=1);

$root = dirname(__DIR__);
$clone = (string)file_get_contents($root . '/teacher/ajax/clone_lesson.php');
$folders = (string)file_get_contents($root . '/teacher/ajax/manage_folders.php');
$notifications = (string)file_get_contents($root . '/teacher/ajax/lesson_notifications.php');
$mindMap = (string)file_get_contents($root . '/teacher/ajax/save_mindmap.php');
$sectionUpdate = (string)file_get_contents($root . '/teacher/ajax/update_section.php');

$checks = [
    'lesson_clone_is_atomic_and_locked' => strpos($clone, 'beginTransaction()') !== false
        && strpos($clone, 'FOR UPDATE') !== false
        && strpos($clone, '->commit()') !== false
        && substr_count($clone, '->rollBack()') >= 2,
    'lesson_clone_logs_fingerprints_not_content' => strpos($clone, "'content_fingerprints'") !== false
        && strpos($clone, "'cloned_lesson_delete_requires_review'") !== false
        && strpos($clone, "'original_content' =>") === false
        && strpos($clone, "'question_bank' =>") === false,
    'folder_mutations_all_use_central_audit' => substr_count($folders, 'recordEvent(') === 4
        && substr_count($folders, 'beginTransaction()') === 4
        && substr_count($folders, '->commit()') === 4,
    'folder_delete_records_composite_impact' => strpos($folders, "'detached_lesson_count'") !== false
        && strpos($folders, "'folder_composite_restore_not_enabled'") !== false,
    'lesson_move_records_before_and_after_folder' => strpos($folders, "'folder_id_before'") !== false
        && strpos($folders, "'folder_id_after'") !== false
        && substr_count($folders, 'FOR UPDATE') >= 3,
    'folder_errors_are_not_disclosed' => strpos($folders, "'حدث خطأ: ' . \$e->getMessage()") === false
        && strpos($clone, "'حدث خطأ: ' . \$e->getMessage()") === false,
    'notification_mutations_are_locked_and_audited' => substr_count($notifications, 'recordEvent(') === 3
        && substr_count($notifications, 'beginTransaction()') === 3
        && substr_count($notifications, '->commit()') === 3
        && substr_count($notifications, 'FOR UPDATE') === 3,
    'bulk_notification_read_uses_compact_evidence' => strpos($notifications, "'ids_fingerprint'") !== false
        && strpos($notifications, "'count' => count(\$ids)") !== false,
    'notification_failure_rolls_back' => strpos($notifications, '->rollBack()') !== false
        && strpos($notifications, "'notification_read_state_not_undoable'") !== false
        && strpos($notifications, "'generated_notification_not_undoable'") !== false,
    'mind_map_update_is_locked_and_atomic' => strpos($mindMap, 'FOR UPDATE') !== false
        && strpos($mindMap, 'beginTransaction()') !== false
        && strpos($mindMap, '->commit()') !== false
        && substr_count($mindMap, '->rollBack()') >= 3,
    'mind_map_audit_uses_fingerprints_only' => strpos($mindMap, "'before_fingerprint'") !== false
        && strpos($mindMap, "'after_fingerprint'") !== false
        && strpos($mindMap, "'mind_maps' =>") === false,
    'section_update_is_locked_and_atomic' => strpos($sectionUpdate, 'FOR UPDATE') !== false
        && strpos($sectionUpdate, 'beginTransaction()') !== false
        && strpos($sectionUpdate, '->commit()') !== false
        && substr_count($sectionUpdate, '->rollBack()') >= 3,
    'section_audit_uses_fingerprints_not_payload' => strpos($sectionUpdate, "'before_fingerprint'") !== false
        && strpos($sectionUpdate, "'after_fingerprint'") !== false
        && strpos($sectionUpdate, "'section_data' =>") === false
        && strpos($sectionUpdate, "'lesson_content_restore_not_enabled'") !== false,
];

$failed = [];
foreach ($checks as $name => $passed) {
    echo $name . ':' . ($passed ? 'PASS' : 'FAIL') . PHP_EOL;
    if (!$passed) $failed[] = $name;
}
exit($failed ? 1 : 0);
