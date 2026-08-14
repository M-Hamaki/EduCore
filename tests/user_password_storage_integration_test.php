<?php

require_once __DIR__ . '/bootstrap_test_database.php';
require_once dirname(__DIR__) . '/classes/user.php';

$db = educoreTestDatabase();
if (!$db) exit(1);
$db->beginTransaction();
try {
    $plaintext = 'Integration-' . bin2hex(random_bytes(8));
    $user = new User($db);
    $user->name = 'Password integration test';
    $user->username = 'password_test_' . bin2hex(random_bytes(6));
    $user->password = $plaintext;
    $user->role = 'student';
    $user->class_id = null;
    if (!$user->create()) throw new RuntimeException('Create failed');

    $stmt = $db->prepare('SELECT password, password_hash, password_key_version FROM users WHERE id = ?');
    $stmt->execute([$user->id]);
    $stored = $stmt->fetch(PDO::FETCH_ASSOC);
    $checks = [
        'legacy_ciphertext_created' => (string)$stored['password'] !== '' && !hash_equals($plaintext, (string)$stored['password']),
        'reveal_round_trip' => hash_equals($plaintext, decryptPasswordForUser((string)$stored['password'], (int)$user->id)),
        'hash_created_with_credentials' => !empty($stored['password_hash'])
            && password_verify($plaintext, (string)$stored['password_hash']),
        'per_user_gcm_cutover_deferred' => !str_starts_with((string)$stored['password'], 'gcm:2:'),
    ];
    foreach ($checks as $name => $passed) echo $name . ': ' . ($passed ? 'PASS' : 'FAIL') . PHP_EOL;
    $db->rollBack();
    exit(in_array(false, $checks, true) ? 1 : 0);
} catch (Throwable $e) {
    if ($db->inTransaction()) $db->rollBack();
    fwrite(STDERR, $e->getMessage() . PHP_EOL);
    exit(1);
}
