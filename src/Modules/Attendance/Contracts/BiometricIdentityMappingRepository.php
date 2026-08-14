<?php

declare(strict_types=1);

namespace EduCore\Modules\Attendance\Contracts;

use DateTimeImmutable;

/** Attendance-owned persistence boundary for biometric identity history. */
interface BiometricIdentityMappingRepository extends BiometricIdentityResolver
{
    /** @return list<array<string,mixed>> */
    public function mappingsForUpdate(int $deviceId, string $biometricIdentity): array;

    /** @param array<string,mixed> $mapping */
    public function insertMapping(array $mapping): int;

    public function retireMapping(
        int $mappingId,
        DateTimeImmutable $validTo,
        string $reason
    ): bool;
}

