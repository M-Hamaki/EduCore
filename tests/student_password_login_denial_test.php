<?php

declare(strict_types=1);

require_once __DIR__ . '/../classes/user.php';

$db = new PDO('sqlite::memory:');
$db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$db->exec('CREATE TABLE users (id INTEGER PRIMARY KEY, name TEXT, username TEXT, password TEXT, password_hash TEXT, role TEXT, is_supervisor INTEGER, class_id INTEGER, status TEXT, login_disabled_reason TEXT, deleted_at TEXT)');
$hash = password_hash('Correct-Pass-123', PASSWORD_DEFAULT);
$stmt = $db->prepare("INSERT INTO users (id, name, username, password, password_hash, role, is_supervisor, status, login_disabled_reason) VALUES (1, 'طالب', 'disabled.student', NULL, ?, 'student', 0, 'inactive', 'رسالة المدير فقط')");
$stmt->execute([$hash]);

$wrong = new User($db);
$wrong->username = 'disabled.student';
$wrong->password = 'wrong-password';
assert($wrong->login() === false);
assert($wrong->getLoginDenialMessage() === null);

$verified = new User($db);
$verified->username = 'disabled.student';
$verified->password = 'Correct-Pass-123';
assert($verified->login() === false);
assert($verified->getLoginDenialMessage() === 'رسالة المدير فقط');

echo "STUDENT_PASSWORD_LOGIN_DENIAL_TEST_PASSED\n";
