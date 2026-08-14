<?php

declare(strict_types=1);

define('AZURE_CLIENT_ID', 'test-client');
define('AZURE_CLIENT_SECRET', 'test-secret');
define('AZURE_TENANT_ID', 'test-tenant');
define('AZURE_REDIRECT_URI', 'http://localhost/EduCore/auth/microsoft_callback.php');
define('AZURE_SCOPES', 'openid profile email');
define('SSO_DEBUG_MODE', false);

require_once __DIR__ . '/../classes/MicrosoftSSO.php';

$db = new PDO('sqlite::memory:');
$db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$db->exec('CREATE TABLE users (
    id INTEGER PRIMARY KEY,
    name TEXT NOT NULL,
    username TEXT NOT NULL,
    email TEXT NOT NULL,
    azure_id TEXT NULL,
    role TEXT NOT NULL,
    is_supervisor INTEGER NOT NULL DEFAULT 0,
    class_id INTEGER NULL,
    status TEXT NOT NULL,
    deleted_at TEXT NULL
)');

$insert = $db->prepare('INSERT INTO users
    (id, name, username, email, azure_id, role, status)
    VALUES (?, ?, ?, ?, ?, ?, ?)');
$insert->execute([1, 'Linked', 'student@school.test', 'student@school.test', 'oid-linked', 'student', 'active']);
$insert->execute([2, 'Changed target', 'changed@school.test', 'changed@school.test', null, 'student', 'active']);
$insert->execute([3, 'Unlinked', 'new@school.test', 'new@school.test', null, 'student', 'active']);
$insert->execute([4, 'Legacy username', 'legacy', 'legacy@school.test', null, 'student', 'active']);
$insert->execute([5, 'Wrong object', 'wrong@school.test', 'wrong@school.test', 'oid-other', 'student', 'active']);
$insert->execute([6, 'Duplicate one', 'duplicate@school.test', 'duplicate@school.test', null, 'student', 'active']);
$insert->execute([7, 'Duplicate two', 'duplicate@school.test', 'duplicate@school.test', null, 'student', 'active']);
$insert->execute([8, 'Object duplicate one', 'object-duplicate@school.test', 'object-duplicate@school.test', 'oid-duplicate', 'student', 'active']);
$insert->execute([9, 'Object duplicate two', 'object-duplicate@school.test', 'object-duplicate@school.test', 'oid-duplicate', 'student', 'active']);

$sso = new MicrosoftSSO($db);

assert(($sso->resolveMicrosoftLoginUser('oid-linked', 'STUDENT@school.test')['id'] ?? null) === 1);
assert($sso->resolveMicrosoftLoginUser('oid-linked', 'changed@school.test') === false);
assert(($sso->resolveMicrosoftLoginUser('oid-new', 'new@school.test')['id'] ?? null) === 3);
assert($sso->resolveMicrosoftLoginUser('oid-legacy', 'legacy@school.test') === false);
assert($sso->resolveMicrosoftLoginUser('oid-new', 'wrong@school.test') === false);
assert($sso->resolveMicrosoftLoginUser('oid-new', 'duplicate@school.test') === false);
assert($sso->resolveMicrosoftLoginUser('oid-duplicate', 'object-duplicate@school.test') === false);
assert($sso->resolveMicrosoftLoginUser('', 'new@school.test') === false);
assert($sso->resolveMicrosoftLoginUser('oid-new', '') === false);
assert(($sso->resolveLinkedMicrosoftLoginUser('oid-linked', 'student@school.test')['id'] ?? null) === 1);
assert($sso->resolveLinkedMicrosoftLoginUser('oid-linked', 'changed@school.test') === false);
assert($sso->resolveLinkedMicrosoftLoginUser('oid-new', 'new@school.test') === false);
assert($sso->resolveLinkedMicrosoftLoginUser('oid-duplicate', 'object-duplicate@school.test') === false);

echo "MICROSOFT_SSO_IDENTITY_MATCH_TEST_PASSED\n";
