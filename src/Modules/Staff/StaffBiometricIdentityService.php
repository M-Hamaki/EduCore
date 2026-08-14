<?php

declare(strict_types=1);

namespace EduCore\Modules\Staff;

use InvalidArgumentException;
use LogicException;
use PDO;
use RuntimeException;

/**
 * Owns the staff biometric identifier.
 *
 * staff_profiles.biometric_id is independent from both the internal employee
 * code (staff_profiles.employee_code) and the legacy attendance identifier
 * (users.employee_code).
 */
final class StaffBiometricIdentityService
{
    private PDO $db;

    public function __construct(PDO $db)
    {
        $this->db = $db;
    }

    public static function normalize($value): ?string
    {
        $identifier = trim((string)($value ?? ''));
        if ($identifier === '') {
            return null;
        }
        if (mb_strlen($identifier, 'UTF-8') > 50) {
            throw new InvalidArgumentException('رقم البصمة يجب ألا يتجاوز 50 حرفًا.');
        }
        return $identifier;
    }

    public function assertAvailableWithinTransaction(int $userId, $value): ?string
    {
        if (!$this->db->inTransaction()) {
            throw new LogicException('Biometric identity validation requires an active transaction.');
        }

        $identifier = self::normalize($value);
        if ($identifier === null) {
            return null;
        }

        $duplicateStatement = $this->db->prepare(
            "SELECT user_id
             FROM staff_profiles
             WHERE user_id <> ?
               AND NULLIF(TRIM(biometric_id), '') = ?
             LIMIT 1"
        );
        $duplicateStatement->execute([$userId, $identifier]);
        if ($duplicateStatement->fetchColumn()) {
            throw new InvalidArgumentException('رقم البصمة مستخدم بالفعل لعامل آخر.');
        }

        return $identifier;
    }

    /**
     * Update only the independent biometric identifier.
     *
     * The caller owns the transaction and the shared audit record.
     *
     * @return array{
     *   identifier:?string,
     *   user_name:string,
     *   before_profile:array<string,mixed>,
     *   after_profile:array<string,mixed>
     * }
     */
    public function synchronizeWithinTransaction(int $userId, $value): array
    {
        if (!$this->db->inTransaction()) {
            throw new LogicException('Biometric identity synchronization requires an active transaction.');
        }

        $identifier = $this->assertAvailableWithinTransaction($userId, $value);
        $profileStatement = $this->db->prepare(
            'SELECT * FROM staff_profiles WHERE user_id = ? FOR UPDATE'
        );
        $profileStatement->execute([$userId]);
        $beforeProfile = $profileStatement->fetch(PDO::FETCH_ASSOC);

        $userStatement = $this->db->prepare('SELECT name FROM users WHERE id = ? FOR UPDATE');
        $userStatement->execute([$userId]);
        $userName = $userStatement->fetchColumn();

        if (!$beforeProfile || $userName === false) {
            throw new RuntimeException('ملف العامل المطلوب غير موجود.');
        }

        $this->db->prepare('UPDATE staff_profiles SET biometric_id = ? WHERE user_id = ?')
            ->execute([$identifier, $userId]);

        $afterProfileStatement = $this->db->prepare(
            'SELECT * FROM staff_profiles WHERE user_id = ?'
        );
        $afterProfileStatement->execute([$userId]);

        return [
            'identifier' => $identifier,
            'user_name' => (string)$userName,
            'before_profile' => $beforeProfile,
            'after_profile' => $afterProfileStatement->fetch(PDO::FETCH_ASSOC) ?: [],
        ];
    }
}
