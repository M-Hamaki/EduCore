<?php

declare(strict_types=1);

require_once __DIR__ . '/bootstrap_test_database.php';
require_once dirname(__DIR__) . '/classes/EvaluationBackupService.php';

$db = educoreTestDatabase();
$service = new EvaluationBackupService($db);
$fixtureId = 2147483000;
$db->exec('SET FOREIGN_KEY_CHECKS = 0');
$db->prepare(
    'INSERT INTO evaluations
     (id, student_id, teacher_id, evaluation_type_id, class_id, date_created, custom_points, reason)
     VALUES (?, ?, ?, ?, ?, NOW(), ?, ?)'
)->execute([$fixtureId, 900001, 900002, 900003, 900004, 7, 'backup-service-fixture']);
$before = (int)$db->query('SELECT COUNT(*) FROM evaluations')->fetchColumn();
$result = $service->resetAll(1, 'integration-test');
$backupKey = (string)$result['backup_key'];
$listed = array_column($service->all(), 'table_name');
$restore = $service->restore($backupKey, 1, 'integration-test');
$after = (int)$db->query('SELECT COUNT(*) FROM evaluations')->fetchColumn();
$restoredReasonStmt = $db->prepare('SELECT reason FROM evaluations WHERE id = ?');
$restoredReasonStmt->execute([$fixtureId]);
$restoredReason = $restoredReasonStmt->fetchColumn();
$db->prepare('DELETE FROM evaluations WHERE id = ?')->execute([$fixtureId]);
$service->delete($backupKey);
$service->delete((string)$restore['pre_restore_key']);
$db->exec('SET FOREIGN_KEY_CHECKS = 1');

$checks = [
    'snapshot_key_is_compatible' => (bool)preg_match(
        '/^evaluations_backup_\d{4}_\d{2}_\d{2}_\d{2}_\d{2}_\d{2}$/',
        $backupKey
    ),
    'snapshot_is_listed' => in_array($backupKey, $listed, true),
    'restore_preserves_record_count' => $before === $after
        && (int)$restore['before'] === 0
        && (int)$restore['after'] === $before,
    'restore_preserves_row_payload' => $restoredReason === 'backup-service-fixture',
    'test_snapshots_are_cleaned' => !in_array($backupKey, array_column($service->all(), 'table_name'), true),
];

$failed = [];
foreach ($checks as $name => $passed) {
    echo $name . ':' . ($passed ? 'PASS' : 'FAIL') . PHP_EOL;
    if (!$passed) {
        $failed[] = $name;
    }
}

exit($failed ? 1 : 0);
