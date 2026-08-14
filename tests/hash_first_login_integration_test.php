<?php

declare(strict_types=1);

require_once __DIR__ . '/bootstrap_test_database.php';
require_once dirname(__DIR__) . '/config/encryption.php';
require_once dirname(__DIR__) . '/classes/user.php';

$db = educoreTestDatabase();
$db->beginTransaction();
try {
    $plaintext = 'Legacy-' . bin2hex(random_bytes(6));
    $username = 'hash_first_' . bin2hex(random_bytes(6));
    $insert = $db->prepare(
        "INSERT INTO users (name, username, password, password_hash, role, status) VALUES (?, ?, '', NULL, 'teacher', 'active')"
    );
    $insert->execute(['Hash-first integration', $username]);
    $userId = (int) $db->lastInsertId();
    $db->prepare('UPDATE users SET password = ? WHERE id = ?')
        ->execute([encryptPasswordForUser($plaintext, $userId), $userId]);

    $user = new User($db);
    $user->username = $username;
    $user->password = $plaintext;
    $legacyLoginAccepted = $user->login();

    $storedHash = (string) $db->query('SELECT password_hash FROM users WHERE id = ' . $userId)->fetchColumn();
    $db->prepare('UPDATE users SET password = ? WHERE id = ?')
        ->execute([encryptPasswordForUser('stale-password', $userId), $userId]);
    $stale = new User($db);
    $stale->username = $username;
    $stale->password = 'stale-password';

    $results = [
        'legacy_login_accepted_during_window' => $legacyLoginAccepted,
        'successful_legacy_login_creates_hash' => $storedHash !== '' && password_verify($plaintext, $storedHash),
        'hash_becomes_authoritative' => !$stale->login(),
    ];
    foreach ($results as $name => $passed) {
        echo $name . ':' . ($passed ? 'PASS' : 'FAIL') . PHP_EOL;
    }
    $db->rollBack();
    exit(in_array(false, $results, true) ? 1 : 0);
} catch (Throwable $error) {
    if ($db->inTransaction()) {
        $db->rollBack();
    }
    fwrite(STDERR, $error->getMessage() . PHP_EOL);
    exit(1);
}
