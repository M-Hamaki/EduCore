<?php

declare(strict_types=1);

require_once __DIR__ . '/bootstrap_test_database.php';
require_once dirname(__DIR__) . '/src/Modules/Operations/Audit/AuditService.php';

use EduCore\Modules\Operations\Audit\AuditService;

$db = educoreTestDatabase();
$actorId = 987654321;
$_SESSION['user_id'] = $actorId;
$_SESSION['name'] = 'Audit Integration Test';
$_SESSION['role'] = 'admin';

$prefix = 'audit_round_trip_' . bin2hex(random_bytes(5));
$checks = [];

$fetch = static function (PDO $db, int $id): array {
    $stmt = $db->prepare('SELECT * FROM settings WHERE id = ?');
    $stmt->execute([$id]);
    return $stmt->fetch(PDO::FETCH_ASSOC) ?: [];
};
$insert = static function (PDO $db, string $key, string $value): int {
    $stmt = $db->prepare('INSERT INTO settings (setting_key, setting_value, description) VALUES (?, ?, ?)');
    $stmt->execute([$key, $value, 'isolated audit integration test']);
    return (int) $db->lastInsertId();
};

try {
    $db->beginTransaction();
    $insertId = $insert($db, $prefix . '_insert', 'created');
    $insertSnapshot = $fetch($db, $insertId);
    $insertUndoId = (new AuditService($db))->recordInsert(
        'audit_test_setting', 'settings', $insertId, $prefix, $insertSnapshot, 'اختبار إنشاء'
    );
    $db->commit();
    $insertUndo = UndoManager::undo($actorId, $insertUndoId);
    $checks['insert_round_trip_removes_created_row'] = ($insertUndo['success'] ?? false) && !$fetch($db, $insertId);
    $insertRedo = UndoManager::redo($actorId, $insertUndoId);
    $checks['insert_redo_restores_created_row'] = ($insertRedo['success'] ?? false)
        && ($fetch($db, $insertId)['setting_value'] ?? null) === 'created';
    $secondInsertRedo = UndoManager::redo($actorId, $insertUndoId);
    $checks['repeated_redo_is_idempotently_rejected'] = !($secondInsertRedo['success'] ?? false)
        && ($fetch($db, $insertId)['setting_value'] ?? null) === 'created';

    $updateId = $insert($db, $prefix . '_update', 'before');
    $beforeUpdate = $fetch($db, $updateId);
    $db->beginTransaction();
    $db->prepare('UPDATE settings SET setting_value = ? WHERE id = ?')->execute(['after', $updateId]);
    $afterUpdate = $fetch($db, $updateId);
    $updateUndoId = (new AuditService($db))->recordUpdate(
        'audit_test_setting', 'settings', $updateId, $prefix, $beforeUpdate, $afterUpdate, 'اختبار تعديل'
    );
    $db->commit();
    $updateUndo = UndoManager::undo($actorId, $updateUndoId);
    $checks['update_round_trip_restores_previous_value'] = ($updateUndo['success'] ?? false)
        && ($fetch($db, $updateId)['setting_value'] ?? null) === 'before';
    $updateRedo = UndoManager::redo($actorId, $updateUndoId);
    $checks['update_redo_reapplies_new_value'] = ($updateRedo['success'] ?? false)
        && ($fetch($db, $updateId)['setting_value'] ?? null) === 'after';

    $scopeId = $insert($db, $prefix . '_scope', 'before-scope');
    $beforeScope = $fetch($db, $scopeId);
    $db->beginTransaction();
    $db->prepare('UPDATE settings SET setting_value = ? WHERE id = ?')->execute(['after-scope', $scopeId]);
    $scopeUndoId = (new AuditService($db))->recordUpdate(
        'audit_test_setting', 'settings', $scopeId, $prefix, $beforeScope, $fetch($db, $scopeId), 'اختبار نطاق المنفذ'
    );
    $db->commit();
    $crossActorUndo = UndoManager::undo($actorId + 1, $scopeUndoId);
    $checks['another_actor_cannot_execute_private_undo'] = !($crossActorUndo['success'] ?? false)
        && ($fetch($db, $scopeId)['setting_value'] ?? null) === 'after-scope';

    $deleteId = $insert($db, $prefix . '_delete', 'restorable');
    $beforeDelete = $fetch($db, $deleteId);
    $db->beginTransaction();
    $db->prepare('DELETE FROM settings WHERE id = ?')->execute([$deleteId]);
    $deleteUndoId = (new AuditService($db))->recordDelete(
        'audit_test_setting', 'settings', $deleteId, $prefix, $beforeDelete, 'اختبار حذف'
    );
    $db->commit();
    $deleteUndo = UndoManager::undo($actorId, $deleteUndoId);
    $checks['delete_round_trip_restores_deleted_row'] = ($deleteUndo['success'] ?? false)
        && ($fetch($db, $deleteId)['setting_value'] ?? null) === 'restorable';
    $deleteRedo = UndoManager::redo($actorId, $deleteUndoId);
    $checks['delete_redo_removes_restored_row_again'] = ($deleteRedo['success'] ?? false)
        && !$fetch($db, $deleteId);

    $redoConflictId = $insert($db, $prefix . '_redo_conflict', 'before-redo-conflict');
    $beforeRedoConflict = $fetch($db, $redoConflictId);
    $db->beginTransaction();
    $db->prepare('UPDATE settings SET setting_value = ? WHERE id = ?')->execute(['after-redo-conflict', $redoConflictId]);
    $redoConflictUndoId = (new AuditService($db))->recordUpdate(
        'audit_test_setting', 'settings', $redoConflictId, $prefix, $beforeRedoConflict, $fetch($db, $redoConflictId), 'اختبار تعارض إعادة العمل'
    );
    $db->commit();
    $redoConflictUndo = UndoManager::undo($actorId, $redoConflictUndoId);
    $db->prepare('UPDATE settings SET setting_value = ? WHERE id = ?')->execute(['external-after-undo', $redoConflictId]);
    $redoConflict = UndoManager::redo($actorId, $redoConflictUndoId);
    $checks['redo_detects_changes_made_after_undo'] = ($redoConflictUndo['success'] ?? false)
        && !($redoConflict['success'] ?? false)
        && ($redoConflict['conflict'] ?? false)
        && ($fetch($db, $redoConflictId)['setting_value'] ?? null) === 'external-after-undo';

    $batchOne = $insert($db, $prefix . '_batch_one', 'before-one');
    $batchTwo = $insert($db, $prefix . '_batch_two', 'before-two');
    $beforeOne = $fetch($db, $batchOne);
    $beforeTwo = $fetch($db, $batchTwo);
    $db->beginTransaction();
    $db->prepare('UPDATE settings SET setting_value = ? WHERE id = ?')->execute(['after-one', $batchOne]);
    $db->prepare('UPDATE settings SET setting_value = ? WHERE id = ?')->execute(['after-two', $batchTwo]);
    $batchUndoIds = (new AuditService($db))->recordCompositeUpdate(
        'audit_test_batch',
        $batchOne,
        $prefix,
        [
            ['table' => 'settings', 'record_id' => $batchOne, 'before' => $beforeOne, 'after' => $fetch($db, $batchOne), 'description' => 'دفعة 1'],
            ['table' => 'settings', 'record_id' => $batchTwo, 'before' => $beforeTwo, 'after' => $fetch($db, $batchTwo), 'description' => 'دفعة 2'],
        ],
        ['integration_test' => true]
    );
    $db->commit();
    $db->prepare('UPDATE settings SET setting_value = ? WHERE id = ?')->execute(['external-change', $batchTwo]);
    $batchUndo = UndoManager::undo($actorId, $batchUndoIds[0] ?? 0);
    $checks['batch_conflict_is_detected'] = !($batchUndo['success'] ?? false)
        && ($batchUndo['conflict'] ?? false);
    $checks['batch_conflict_rolls_back_every_member'] = ($fetch($db, $batchOne)['setting_value'] ?? null) === 'after-one'
        && ($fetch($db, $batchTwo)['setting_value'] ?? null) === 'external-change';

    $redoBatchOne = $insert($db, $prefix . '_redo_batch_one', 'before-redo-one');
    $redoBatchTwo = $insert($db, $prefix . '_redo_batch_two', 'before-redo-two');
    $beforeRedoOne = $fetch($db, $redoBatchOne);
    $beforeRedoTwo = $fetch($db, $redoBatchTwo);
    $db->beginTransaction();
    $db->prepare('UPDATE settings SET setting_value = ? WHERE id = ?')->execute(['after-redo-one', $redoBatchOne]);
    $db->prepare('UPDATE settings SET setting_value = ? WHERE id = ?')->execute(['after-redo-two', $redoBatchTwo]);
    $redoBatchIds = (new AuditService($db))->recordCompositeUpdate(
        'audit_test_redo_batch',
        $redoBatchOne,
        $prefix,
        [
            ['table' => 'settings', 'record_id' => $redoBatchOne, 'before' => $beforeRedoOne, 'after' => $fetch($db, $redoBatchOne), 'description' => 'إعادة دفعة 1'],
            ['table' => 'settings', 'record_id' => $redoBatchTwo, 'before' => $beforeRedoTwo, 'after' => $fetch($db, $redoBatchTwo), 'description' => 'إعادة دفعة 2'],
        ],
        ['integration_test' => true]
    );
    $db->commit();
    $redoBatchUndo = UndoManager::undo($actorId, $redoBatchIds[0] ?? 0);
    $db->prepare('UPDATE settings SET setting_value = ? WHERE id = ?')->execute(['external-redo-batch-change', $redoBatchTwo]);
    $redoBatchResult = UndoManager::redo($actorId, $redoBatchIds[0] ?? 0);
    $checks['redo_batch_conflict_is_detected'] = ($redoBatchUndo['success'] ?? false)
        && !($redoBatchResult['success'] ?? false)
        && ($redoBatchResult['conflict'] ?? false);
    $checks['redo_batch_conflict_rolls_back_every_member'] = ($fetch($db, $redoBatchOne)['setting_value'] ?? null) === 'before-redo-one'
        && ($fetch($db, $redoBatchTwo)['setting_value'] ?? null) === 'external-redo-batch-change';
} finally {
    if ($db->inTransaction()) {
        $db->rollBack();
    }
    $db->prepare('DELETE FROM settings WHERE setting_key LIKE ?')->execute([$prefix . '%']);
    $db->prepare('DELETE FROM activity_logs WHERE user_id = ?')->execute([$actorId]);
    $db->prepare('DELETE FROM undo_log WHERE user_id = ?')->execute([$actorId]);
}

$failed = [];
foreach ($checks as $name => $passed) {
    echo $name . ':' . ($passed ? 'PASS' : 'FAIL') . PHP_EOL;
    if (!$passed) $failed[] = $name;
}
exit($failed ? 1 : 0);
