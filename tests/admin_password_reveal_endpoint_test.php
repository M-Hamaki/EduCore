<?php

require_once __DIR__ . '/bootstrap_test_database.php';

$db = educoreTestDatabase();
$adminId = (int)$db->query("SELECT id FROM users WHERE role = 'admin' AND status = 'active' ORDER BY id LIMIT 1")->fetchColumn();
$studentId = (int)$db->query("SELECT id FROM users WHERE role = 'student' AND password <> '' ORDER BY id LIMIT 1")->fetchColumn();
if ($adminId <= 0 || $studentId <= 0) {
    fwrite(STDERR, "admin_reveal_without_password: SKIP (fixtures unavailable)\n");
    exit(0);
}

chdir(__DIR__ . '/../admin/ajax');

$_SERVER['REQUEST_METHOD'] = 'POST';
$_SERVER['REMOTE_ADDR'] = '127.0.0.1';
$_POST = [
    'user_id' => $studentId,
    'account_type' => 'user',
    'csrf_token' => 'endpoint-test-token',
];

session_start();
$_SESSION['user_id'] = $adminId;
$_SESSION['role'] = 'admin';
$_SESSION['csrf_token'] = 'endpoint-test-token';

ob_start(function (string $output): string {
    $result = json_decode($output, true);
    $passed = is_array($result)
        && ($result['success'] ?? false) === true
        && is_string($result['password'] ?? null)
        && $result['password'] !== '';

    return 'admin_reveal_without_password: ' . ($passed ? 'PASS' : 'FAIL') . PHP_EOL;
});

require __DIR__ . '/../admin/ajax/get_password.php';
