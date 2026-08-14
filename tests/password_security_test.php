<?php

require_once dirname(__DIR__) . '/config/encryption.php';

$userId = 42;
$plaintext = 'Test-' . bin2hex(random_bytes(12));
$encrypted = encryptPasswordForUser($plaintext, $userId);
$hash = password_hash($plaintext, PASSWORD_DEFAULT);
$genericEncrypted = encryptPassword($plaintext);

$checks = [
    'uses_gcm' => str_starts_with($encrypted, 'gcm:2:'),
    'round_trip' => hash_equals($plaintext, decryptPasswordForUser($encrypted, $userId)),
    'wrong_user_rejected' => decryptPasswordForUser($encrypted, $userId + 1) === '',
    'hash_login' => verifyStoredPassword($plaintext, $encrypted, $hash, $userId),
    'wrong_password_rejected' => !verifyStoredPassword($plaintext . 'x', $encrypted, $hash, $userId),
    'generic_round_trip' => hash_equals($plaintext, decryptPassword($genericEncrypted)),
    'current_key_version' => !passwordCipherNeedsRotation($encrypted),
];

foreach ($checks as $name => $passed) {
    echo $name . ': ' . ($passed ? 'PASS' : 'FAIL') . PHP_EOL;
}
exit(in_array(false, $checks, true) ? 1 : 0);
