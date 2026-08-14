<?php

require_once __DIR__ . '/env_loader.php';

define('ENCRYPTION_METHOD', 'AES-256-GCM');
define('PASSWORD_KEY_VERSION', max(1, (int)env('PASSWORD_KEY_VERSION', 1)));

function passwordMasterKey(int $version): string
{
    $keys = json_decode((string)env('PASSWORD_ENCRYPTION_KEYS_JSON', ''), true);
    $hex = is_array($keys) ? ($keys[(string)$version] ?? '') : '';
    if ($hex === '' && $version === PASSWORD_KEY_VERSION) $hex = (string)env('PASSWORD_ENCRYPTION_KEY_HEX', '');
    $key = ctype_xdigit($hex) ? hex2bin($hex) : false;
    if ($key === false || strlen($key) < 32) throw new RuntimeException('Password encryption key is missing or invalid');
    return substr($key, 0, 32);
}

function passwordRecordKey(int $userId, int $version): string
{
    if ($userId <= 0) throw new InvalidArgumentException('A valid user ID is required');
    return hash_hkdf('sha256', passwordMasterKey($version), 32, 'educore-password:user:' . $userId);
}

function encryptPasswordForUser(string $plaintext, int $userId): string
{
    if ($plaintext === '') return '';
    $version = PASSWORD_KEY_VERSION;
    $nonce = random_bytes(12);
    $tag = '';
    $ciphertext = openssl_encrypt($plaintext, ENCRYPTION_METHOD, passwordRecordKey($userId, $version), OPENSSL_RAW_DATA, $nonce, $tag, 'user:' . $userId, 16);
    if ($ciphertext === false) throw new RuntimeException('Password encryption failed');
    return implode(':', ['gcm', '2', $version, base64_encode($nonce), base64_encode($tag), base64_encode($ciphertext)]);
}

function decryptPasswordForUser(string $stored, int $userId): string
{
    if ($stored === '') return '';
    if (str_starts_with($stored, 'gcm:2:')) {
        $parts = explode(':', $stored, 6);
        if (count($parts) !== 6) return '';
        $plaintext = openssl_decrypt(base64_decode($parts[5], true) ?: '', ENCRYPTION_METHOD, passwordRecordKey($userId, (int)$parts[2]), OPENSSL_RAW_DATA, base64_decode($parts[3], true) ?: '', base64_decode($parts[4], true) ?: '', 'user:' . $userId);
        return $plaintext === false ? '' : $plaintext;
    }
    // Accounts created during the transition used the generic GCM envelope.
    if (str_starts_with($stored, 'gcm:1:')) return decryptPassword($stored);
    return decryptLegacyPassword($stored);
}

function decryptLegacyPassword(string $stored): string
{
    if ($stored === '' || !empty(password_get_info($stored)['algo'])) return '';
    if (!str_contains($stored, '::')) return $stored;
    [$encodedIv, $ciphertext] = explode('::', $stored, 2);
    $iv = base64_decode($encodedIv, true);
    if ($iv === false) return '';
    try {
        $legacyHex = (string)env('ENCRYPTION_KEY_HEX', '');
        $legacyKey = ctype_xdigit($legacyHex) ? hex2bin($legacyHex) : false;
        if ($legacyKey === false || $legacyKey === '') return '';
        $plaintext = openssl_decrypt($ciphertext, 'AES-256-CBC', $legacyKey, 0, $iv);
    } catch (Throwable $e) {
        error_log('Legacy password decryption failed: ' . $e->getMessage());
        return '';
    }
    return $plaintext === false ? '' : $plaintext;
}

function verifyStoredPassword(string $plaintext, string $encrypted, ?string $hash = null, ?int $userId = null): bool
{
    // Once a hash exists it is authoritative; never fall back to stale reversible data.
    if ($hash !== null && trim($hash) !== '') return password_verify($plaintext, $hash);
    // Some existing accounts already store a one-way hash in the legacy column.
    if (isPasswordHash($encrypted)) return password_verify($plaintext, $encrypted);
    $storedPlaintext = $userId ? decryptPasswordForUser($encrypted, $userId) : decryptLegacyPassword($encrypted);
    return $storedPlaintext !== '' && hash_equals($storedPlaintext, $plaintext);
}

// Compatibility for non-user credentials. User passwords use the per-user functions above.
function encryptPassword(string $plaintext): string
{
    $nonce = random_bytes(12); $tag = '';
    $ciphertext = openssl_encrypt($plaintext, ENCRYPTION_METHOD, passwordMasterKey(PASSWORD_KEY_VERSION), OPENSSL_RAW_DATA, $nonce, $tag, 'educore-generic', 16);
    return $ciphertext === false ? '' : implode(':', ['gcm', '1', PASSWORD_KEY_VERSION, base64_encode($nonce), base64_encode($tag), base64_encode($ciphertext)]);
}

function decryptPassword(string $stored): string
{
    if (str_starts_with($stored, 'gcm:1:')) {
        $parts = explode(':', $stored, 6);
        if (count($parts) !== 6) return '';
        $plaintext = openssl_decrypt(base64_decode($parts[5], true) ?: '', ENCRYPTION_METHOD, passwordMasterKey((int)$parts[2]), OPENSSL_RAW_DATA, base64_decode($parts[3], true) ?: '', base64_decode($parts[4], true) ?: '', 'educore-generic');
        return $plaintext === false ? '' : $plaintext;
    }
    return decryptLegacyPassword($stored);
}

function isPasswordHash($stored): bool { return !empty(password_get_info((string)$stored)['algo']); }
function storedPasswordNeedsRehash($stored): bool { return !isPasswordHash($stored) || password_needs_rehash((string)$stored, PASSWORD_DEFAULT); }
function isPasswordEncrypted($stored): bool { return str_contains((string)$stored, '::') || str_starts_with((string)$stored, 'gcm:'); }
function passwordCipherNeedsRotation(string $stored): bool
{
    if (!str_starts_with($stored, 'gcm:2:')) return true;
    $parts = explode(':', $stored, 4);
    return count($parts) < 3 || (int)$parts[2] !== PASSWORD_KEY_VERSION;
}
