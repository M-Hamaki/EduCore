<?php

declare(strict_types=1);

require_once __DIR__ . '/bootstrap_test_database.php';
require_once dirname(__DIR__) . '/classes/SchemaReadinessGuard.php';

$db = educoreTestDatabase();
$guard = new SchemaReadinessGuard($db);
$guard->assertColumns('activity_logs', [
    'request_id', 'batch_id', 'result', 'route', 'user_agent', 'undo_log_id',
]);
$guard->assertColumns('undo_log', [
    'request_id', 'can_undo', 'undone_by', 'undone_at', 'undo_status', 'failure_reason',
]);

$requiredIndexes = [
    'activity_logs' => ['idx_activity_request', 'idx_activity_batch', 'idx_activity_target_created'],
    'undo_log' => ['idx_undo_available', 'idx_undo_request'],
];
foreach ($requiredIndexes as $table => $indexes) {
    $stmt = $db->prepare(
        'SELECT INDEX_NAME FROM information_schema.STATISTICS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?'
    );
    $stmt->execute([$table]);
    $actual = $stmt->fetchAll(PDO::FETCH_COLUMN);
    foreach ($indexes as $index) {
        if (!in_array($index, $actual, true)) {
            throw new RuntimeException("Missing required index {$table}.{$index}");
        }
    }
}

echo "audit_engine_v2_schema_ready:PASS\n";
