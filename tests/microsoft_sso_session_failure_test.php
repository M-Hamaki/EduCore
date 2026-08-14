<?php

declare(strict_types=1);

define('AZURE_CLIENT_ID', 'test-client');
define('AZURE_CLIENT_SECRET', 'test-secret');
define('AZURE_TENANT_ID', 'test-tenant');
define('AZURE_REDIRECT_URI', 'http://localhost/EduCore/auth/microsoft_callback.php');
define('AZURE_SCOPES', 'openid profile email');
define('SSO_DEBUG_MODE', false);

require_once __DIR__ . '/../classes/MicrosoftSSO.php';

if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
}

$db = new PDO('sqlite::memory:');
$db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$db->exec('CREATE TABLE users (id INTEGER PRIMARY KEY, role TEXT, status TEXT, login_disabled_reason TEXT, deleted_at TEXT)');
$db->exec("INSERT INTO users (id, role, status) VALUES (7, 'student', 'active')");

$sso = new MicrosoftSSO($db);
$result = $sso->loginUser([
    'id' => 7,
    'name' => 'طالب اختبار',
    'role' => 'student',
    'status' => 'active',
    'class_id' => null,
]);

assert($result === false);
assert(!isset($_SESSION['user_id']));
assert(!isset($_SESSION['microsoft_login']));

echo "MICROSOFT_SSO_SESSION_FAILURE_TEST_PASSED\n";
