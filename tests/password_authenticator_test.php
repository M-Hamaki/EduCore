<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/config/encryption.php';
require_once dirname(__DIR__) . '/classes/PasswordAuthenticator.php';

$userId = 42;
$plaintext = 'Hash-first-' . bin2hex(random_bytes(4));
$encrypted = encryptPasswordForUser($plaintext, $userId);
$hash = password_hash($plaintext, PASSWORD_DEFAULT);
$authenticator = new PasswordAuthenticator(true);

$hashResult = $authenticator->verify($plaintext, $encrypted, $hash, $userId);
$staleLegacyResult = $authenticator->verify('stale-password', encryptPasswordForUser('stale-password', $userId), $hash, $userId);
$legacyResult = $authenticator->verify($plaintext, $encrypted, null, $userId);
$disabledResult = (new PasswordAuthenticator(false))->verify($plaintext, $encrypted, null, $userId);

$results = [
    'hash_verified_first' => $hashResult['verified'] && !$hashResult['used_legacy'],
    'wrong_hash_never_falls_back' => !$staleLegacyResult['verified'] && !$staleLegacyResult['used_legacy'],
    'legacy_success_requests_hash_upgrade' => $legacyResult['verified']
        && $legacyResult['used_legacy']
        && password_verify($plaintext, (string) $legacyResult['replacement_hash']),
    'legacy_cutover_flag_disables_fallback' => !$disabledResult['verified'],
];

$failed = false;
foreach ($results as $name => $passed) {
    echo $name . ':' . ($passed ? 'PASS' : 'FAIL') . PHP_EOL;
    $failed = $failed || !$passed;
}

exit($failed ? 1 : 0);
