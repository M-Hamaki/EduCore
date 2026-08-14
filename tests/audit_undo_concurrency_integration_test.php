<?php

declare(strict_types=1);

require_once __DIR__ . '/bootstrap_test_database.php';
require_once dirname(__DIR__) . '/src/Modules/Operations/Audit/AuditService.php';

use EduCore\Modules\Operations\Audit\AuditService;

$writer = educoreTestDatabase();
$locker = educoreTestDatabase();
$undoConnection = educoreTestDatabase();
$actorId = 987654324;
$_SESSION['user_id'] = $actorId;
$_SESSION['name'] = 'Audit Concurrency Test';
$_SESSION['role'] = 'admin';
$key = 'audit_concurrency_' . bin2hex(random_bytes(6));
$settingId = 0;

try {
    $writer->prepare('INSERT INTO settings (setting_key, setting_value, description) VALUES (?, ?, ?)')
        ->execute([$key, 'before', 'isolated concurrency test']);
    $settingId = (int) $writer->lastInsertId();
    $fetch = $writer->prepare('SELECT * FROM settings WHERE id = ?');
    $fetch->execute([$settingId]);
    $before = $fetch->fetch(PDO::FETCH_ASSOC);

    $writer->beginTransaction();
    $writer->prepare('UPDATE settings SET setting_value = ? WHERE id = ?')->execute(['after', $settingId]);
    $fetch->execute([$settingId]);
    $after = $fetch->fetch(PDO::FETCH_ASSOC);
    $undoId = (new AuditService($writer))->recordUpdate(
        'audit_concurrency_setting', 'settings', $settingId, $key, $before, $after, 'اختبار تزامن التراجع'
    );
    $writer->commit();

    $locker->beginTransaction();
    $lock = $locker->prepare('SELECT id FROM undo_log WHERE id = ? FOR UPDATE');
    $lock->execute([$undoId]);
    $undoConnection->exec('SET SESSION innodb_lock_wait_timeout = 1');
    UndoManager::setDb($undoConnection);
    $blockedResult = UndoManager::undo($actorId, $undoId);

    $valueStmt = $writer->prepare('SELECT setting_value FROM settings WHERE id = ?');
    $valueStmt->execute([$settingId]);
    $checks = [
        'concurrent_undo_wait_timeout_is_safely_rejected' => !($blockedResult['success'] ?? false),
        'blocked_concurrent_undo_does_not_change_business_row' => $valueStmt->fetchColumn() === 'after',
    ];

    $locker->rollBack();
    UndoManager::setDb($undoConnection);
    $retryResult = UndoManager::undo($actorId, $undoId);
    $valueStmt->execute([$settingId]);
    $checks['undo_succeeds_after_lock_release'] = ($retryResult['success'] ?? false)
        && $valueStmt->fetchColumn() === 'before';
} finally {
    if ($locker->inTransaction()) $locker->rollBack();
    if ($writer->inTransaction()) $writer->rollBack();
    $writer->prepare('DELETE FROM settings WHERE setting_key = ?')->execute([$key]);
    $writer->prepare('DELETE FROM activity_logs WHERE user_id = ?')->execute([$actorId]);
    $writer->prepare('DELETE FROM undo_log WHERE user_id = ?')->execute([$actorId]);
}

foreach ($checks ?? [] as $name => $passed) {
    echo $name . ':' . ($passed ? 'PASS' : 'FAIL') . PHP_EOL;
}
exit(!isset($checks) || in_array(false, $checks, true) ? 1 : 0);
