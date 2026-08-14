<?php

declare(strict_types=1);

require_once __DIR__ . '/bootstrap_test_database.php';
require_once dirname(__DIR__) . '/src/Modules/Operations/Audit/AuditService.php';

use EduCore\Modules\Operations\Audit\AuditService;

$db = educoreTestDatabase();
$_SESSION['user_id'] = 987654323;
$_SESSION['name'] = 'Audit Failure Test';
$_SESSION['role'] = 'admin';

$key = 'audit_fail_closed_' . bin2hex(random_bytes(6));
$renamed = false;
$thrown = false;

try {
    $db->exec('DROP TABLE IF EXISTS activity_logs_audit_failure_test');
    $db->exec('RENAME TABLE activity_logs TO activity_logs_audit_failure_test');
    $renamed = true;

    try {
        $db->beginTransaction();
        $stmt = $db->prepare('INSERT INTO settings (setting_key, setting_value, description) VALUES (?, ?, ?)');
        $stmt->execute([$key, 'must-roll-back', 'audit failure integration test']);
        $id = (int) $db->lastInsertId();
        (new AuditService($db))->recordInsert(
            'audit_failure_setting',
            'settings',
            $id,
            $key,
            ['id' => $id, 'setting_key' => $key, 'setting_value' => 'must-roll-back'],
            'اختبار فشل مغلق'
        );
        $db->commit();
    } catch (Throwable $error) {
        $thrown = true;
        if ($db->inTransaction()) {
            $db->rollBack();
        }
    }
} finally {
    if ($db->inTransaction()) {
        $db->rollBack();
    }
    if ($renamed) {
        $db->exec('RENAME TABLE activity_logs_audit_failure_test TO activity_logs');
    }
}

$settingStmt = $db->prepare('SELECT COUNT(*) FROM settings WHERE setting_key = ?');
$settingStmt->execute([$key]);
$undoStmt = $db->prepare('SELECT COUNT(*) FROM undo_log WHERE user_id = ? AND description = ?');
$undoStmt->execute([987654323, 'اختبار فشل مغلق']);

$checks = [
    'audit_sink_failure_throws' => $thrown,
    'business_write_is_rolled_back' => (int) $settingStmt->fetchColumn() === 0,
    'partial_undo_entry_is_rolled_back' => (int) $undoStmt->fetchColumn() === 0,
    'audit_table_is_restored' => (bool) $db->query("SHOW TABLES LIKE 'activity_logs'")->fetchColumn(),
];

foreach ($checks as $name => $passed) {
    echo $name . ':' . ($passed ? 'PASS' : 'FAIL') . PHP_EOL;
}
exit(in_array(false, $checks, true) ? 1 : 0);
