<?php

declare(strict_types=1);

namespace EduCore\Modules\Attendance\Infrastructure;

use DateTimeImmutable;
use DateTimeZone;
use EduCore\Modules\Attendance\Contracts\BiometricIdentityMappingRepository;
use PDO;

/** PDO adapter limited to Attendance-owned biometric mapping history. */
final class PdoBiometricIdentityMappingRepository implements BiometricIdentityMappingRepository
{
    private DateTimeZone $utc;

    public function __construct(private PDO $db)
    {
        $this->utc = new DateTimeZone('UTC');
    }

    public function mappingsAt(
        int $deviceId,
        string $biometricIdentity,
        DateTimeImmutable $at
    ): array {
        $statement = $this->db->prepare(
            'SELECT *
             FROM staff_biometric_identity_mappings
             WHERE device_id = :device_id
               AND biometric_identity = :biometric_identity
               AND valid_from <= :at_from
               AND (valid_to IS NULL OR :at_to < valid_to)
             ORDER BY valid_from DESC, id DESC
             FOR UPDATE'
        );
        $instant = $this->databaseInstant($at);
        $statement->execute([
            'device_id' => $deviceId,
            'biometric_identity' => $biometricIdentity,
            'at_from' => $instant,
            'at_to' => $instant,
        ]);
        return $this->normalizeRows($statement->fetchAll(PDO::FETCH_ASSOC));
    }

    public function mappingsForUpdate(int $deviceId, string $biometricIdentity): array
    {
        $statement = $this->db->prepare(
            'SELECT *
             FROM staff_biometric_identity_mappings
             WHERE device_id = ? AND biometric_identity = ?
             ORDER BY valid_from, id
             FOR UPDATE'
        );
        $statement->execute([$deviceId, $biometricIdentity]);
        return $this->normalizeRows($statement->fetchAll(PDO::FETCH_ASSOC));
    }

    public function insertMapping(array $mapping): int
    {
        $statement = $this->db->prepare(
            'INSERT INTO staff_biometric_identity_mappings
                (device_id, biometric_identity, staff_user_id, valid_from,
                 valid_to, source, confirmed_by, retired_reason)
             VALUES
                (:device_id, :biometric_identity, :staff_user_id, :valid_from,
                 :valid_to, :source, :confirmed_by, :retired_reason)'
        );
        $statement->execute([
            'device_id' => $mapping['device_id'],
            'biometric_identity' => $mapping['biometric_identity'],
            'staff_user_id' => $mapping['staff_user_id'],
            'valid_from' => $mapping['valid_from'],
            'valid_to' => $mapping['valid_to'],
            'source' => $mapping['source'],
            'confirmed_by' => $mapping['confirmed_by'],
            'retired_reason' => $mapping['retired_reason'],
        ]);
        return (int) $this->db->lastInsertId();
    }

    public function retireMapping(
        int $mappingId,
        DateTimeImmutable $validTo,
        string $reason
    ): bool {
        $statement = $this->db->prepare(
            'UPDATE staff_biometric_identity_mappings
             SET valid_to = :valid_to, retired_reason = :reason
             WHERE id = :id AND valid_to IS NULL AND valid_from < :valid_to_guard'
        );
        $instant = $this->databaseInstant($validTo);
        $statement->execute([
            'valid_to' => $instant,
            'reason' => $reason,
            'id' => $mappingId,
            'valid_to_guard' => $instant,
        ]);
        return $statement->rowCount() === 1;
    }

    /** @param array<int,array<string,mixed>> $rows @return list<array<string,mixed>> */
    private function normalizeRows(array $rows): array
    {
        foreach ($rows as &$row) {
            $row['id'] = (int) $row['id'];
            $row['device_id'] = (int) $row['device_id'];
            $row['staff_user_id'] = (int) $row['staff_user_id'];
            $row['confirmed_by'] = isset($row['confirmed_by']) ? (int) $row['confirmed_by'] : null;
        }
        unset($row);
        return array_values($rows);
    }

    private function databaseInstant(DateTimeImmutable $instant): string
    {
        return $instant->setTimezone($this->utc)->format('Y-m-d H:i:s.u');
    }
}
