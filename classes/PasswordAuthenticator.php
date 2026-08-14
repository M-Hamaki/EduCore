<?php

declare(strict_types=1);

final class PasswordAuthenticator
{
    public function __construct(private bool $legacyLoginEnabled = true)
    {
    }

    public function verify(string $plaintext, string $encryptedPassword, ?string $passwordHash, int $userId): array
    {
        $passwordHash = trim((string) $passwordHash);
        if ($passwordHash !== '') {
            $verified = password_verify($plaintext, $passwordHash);
            return [
                'verified' => $verified,
                'used_legacy' => false,
                'replacement_hash' => $verified && password_needs_rehash($passwordHash, PASSWORD_DEFAULT)
                    ? password_hash($plaintext, PASSWORD_DEFAULT)
                    : null,
            ];
        }

        if (!$this->legacyLoginEnabled) {
            return ['verified' => false, 'used_legacy' => false, 'replacement_hash' => null];
        }

        $verified = verifyStoredPassword($plaintext, $encryptedPassword, null, $userId);
        return [
            'verified' => $verified,
            'used_legacy' => $verified,
            'replacement_hash' => $verified ? password_hash($plaintext, PASSWORD_DEFAULT) : null,
        ];
    }
}
