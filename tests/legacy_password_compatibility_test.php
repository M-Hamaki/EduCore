<?php

require_once __DIR__ . '/bootstrap_test_database.php';
require_once dirname(__DIR__) . '/config/encryption.php';

$db = educoreTestDatabase();
$stmt = $db->query("SELECT id, password FROM users WHERE password NOT LIKE 'gcm:2:%' LIMIT 1");
$row = $stmt->fetch(PDO::FETCH_ASSOC);
if (!$row) {
    echo "legacy_sample: SKIP (no legacy rows)\n";
    exit(0);
}
$plaintext = decryptPasswordForUser((string)$row['password'], (int)$row['id']);
echo 'legacy_sample: ' . ($plaintext !== '' ? 'PASS' : 'FAIL') . PHP_EOL;
exit($plaintext !== '' ? 0 : 1);
