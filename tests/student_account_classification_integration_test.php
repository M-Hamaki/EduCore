<?php

declare(strict_types=1);

require_once __DIR__ . '/bootstrap_test_database.php';
require_once dirname(__DIR__) . '/classes/StudentAccountClassificationService.php';

$db = educoreTestDatabase();
$student = $db->query("SELECT id, is_test_account FROM users WHERE role = 'student' AND deleted_at IS NULL ORDER BY id LIMIT 1")->fetch(PDO::FETCH_ASSOC);
$actorId = (int) $db->query("SELECT id FROM users WHERE role IN ('admin', 'super_admin') AND deleted_at IS NULL ORDER BY id LIMIT 1")->fetchColumn();
if (!$student || $actorId <= 0) {
    throw new RuntimeException('The isolated fixture needs one student and one admin account.');
}

$_SESSION['user_id'] = $actorId;
$studentId = (int) $student['id'];
$original = (int) $student['is_test_account'] === 1;
$db->beginTransaction();
try {
    $service = new StudentAccountClassificationService($db);
    $changed = $service->setTestAccount($studentId, !$original, $actorId);
    $afterChange = (int) $db->query("SELECT is_test_account FROM users WHERE id = {$studentId}")->fetchColumn() === 1;
    $restored = $service->setTestAccount($studentId, $original, $actorId);
    $afterRestore = (int) $db->query("SELECT is_test_account FROM users WHERE id = {$studentId}")->fetchColumn() === 1;

    $checks = [
        'classification_changed' => $changed['changed'] === true && $afterChange === !$original,
        'classification_restored' => $restored['changed'] === true && $afterRestore === $original,
        'audit_rows_written_in_same_transaction' => (int) $db->query("SELECT COUNT(*) FROM activity_logs WHERE target_type = 'student_account' AND target_id = {$studentId}")->fetchColumn() >= 2,
    ];
} finally {
    $db->rollBack();
}

$persisted = (int) $db->query("SELECT is_test_account FROM users WHERE id = {$studentId}")->fetchColumn() === 1;
$checks['outer_rollback_restores_original'] = $persisted === $original;

foreach ($checks as $name => $passed) {
    echo $name . ':' . ($passed ? 'PASS' : 'FAIL') . PHP_EOL;
}
exit(in_array(false, $checks, true) ? 1 : 0);
