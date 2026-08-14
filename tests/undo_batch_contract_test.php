<?php

declare(strict_types=1);

$root = dirname(__DIR__);
$manager = (string) file_get_contents($root . '/classes/UndoManager.php');
$migration = (string) file_get_contents($root . '/database/migrations/20260715_undo_explicit_batches.php');
$classLists = (string) file_get_contents($root . '/admin/class_lists.php');
$studentService = (string) file_get_contents($root . '/src/Modules/Students/StudentProfileCommandService.php');
$staffService = (string) file_get_contents($root . '/src/Modules/Staff/StaffProfileCommandService.php');
$classTransferCallAt = strpos($classLists, '$studentCommandService->applyClassTransfer(');
$classTransferBatchAt = $classTransferCallAt === false ? false : strpos($classLists, '$undoBatchId', $classTransferCallAt);

$checks = [
    'batch_identifier_is_cryptographically_random' => strpos($manager, 'bin2hex(random_bytes(16))') !== false,
    'batch_identifier_is_validated' => strpos($manager, "preg_match('/^[a-f0-9]{32}$/'") !== false,
    'undo_rows_store_batch_identifier' => substr_count($manager, 'page_url, batch_id, request_id, can_undo') === 3,
    'undo_groups_only_by_explicit_batch' => strpos($manager, 'WHERE user_id = ? AND batch_id = ? ORDER BY id DESC') !== false
        && strpos($manager, 'member.batch_id = candidate.batch_id') !== false,
    'batch_members_are_all_preflighted' => strpos($manager, "(int) \$ent['can_undo'] !== 1") !== false
        && strpos($manager, 'تحتوي العملية على عنصر غير قابل للتراجع الآمن') !== false,
    'undo_records_are_locked_before_execution' => strpos($manager, 'ORDER BY id DESC FOR UPDATE') !== false
        && strpos($manager, 'fetchRecord($tableName, $recordId, true)') !== false,
    'undo_only_exposes_eligible_pending_entries' => strpos($manager, "can_undo = 1 AND is_undone = 0 AND undo_status = 'pending'") !== false,
    'completed_undo_records_actor_and_time' => strpos($manager, 'undone_by = ?, undone_at = NOW()') !== false,
    'redo_requires_completed_entries_and_restores_pending_state' => strpos($manager, "is_undone = 1 AND undo_status = 'completed'") !== false
        && strpos($manager, "is_undone = 0, undo_status = 'pending'") !== false,
    'redo_batches_use_original_order_and_lock_before_writes' => strpos($manager, "\$batchSql .= ' ORDER BY id ASC'") !== false
        && strpos($manager, 'ORDER BY id ASC FOR UPDATE') !== false,
    'redo_preflights_conflicts_and_audits_atomically' => strpos($manager, 'snapshotMatches($currentData, $oldData)') !== false
        && strpos($manager, "ActivityLog::log('redo'") !== false
        && strpos($manager, 'Redo audit event could not be stored.') !== false,
    'time_proximity_grouping_removed' => strpos($manager, 'WHERE user_id = ? AND batch_id = ? ORDER BY id DESC') !== false
        && strpos($manager, 'created_at >= DATE_SUB(NOW(), INTERVAL 5 SECOND)') === false,
    'migration_is_idempotent' => strpos($migration, "COLUMN_NAME = 'batch_id'") !== false
        && strpos($migration, 'ADD INDEX idx_undo_batch') !== false,
    'class_transfer_uses_one_explicit_batch' => strpos($classLists, '$undoBatchId = UndoManager::newBatchId();') !== false
        && $classTransferCallAt !== false
        && $classTransferBatchAt !== false,
    'student_composite_update_uses_batch' => strpos($studentService, '$undoBatchId = UndoManager::newBatchId();') !== false
        && strpos($studentService, 'recordCompositeUpdate(') !== false,
    'staff_composite_update_uses_batch' => strpos($staffService, '$undoBatchId = UndoManager::newBatchId();') !== false
        && strpos($staffService, 'recordCompositeUpdate(') !== false,
];

$failed = false;
foreach ($checks as $name => $passed) {
    echo $name . ':' . ($passed ? 'PASS' : 'FAIL') . PHP_EOL;
    $failed = $failed || !$passed;
}

exit($failed ? 1 : 0);
