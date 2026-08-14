<?php

declare(strict_types=1);

namespace EduCore\Modules\Attendance\Contracts;

use DateTimeImmutable;

/**
 * Read-only, effective-dated identity boundary used by raw-event ingestion.
 * Repository adapters must keep the returned mapping snapshot stable for the
 * caller's transaction so reassignment cannot race a raw-event insert.
 */
interface BiometricIdentityResolver
{
    /**
     * @return list<array{
     *     id:int,
     *     device_id:int,
     *     biometric_identity:string,
     *     staff_user_id:int,
     *     valid_from:string,
     *     valid_to:?string,
     *     source:string,
     *     confirmed_by:?int,
     *     retired_reason:?string
     * }>
     */
    public function mappingsAt(
        int $deviceId,
        string $biometricIdentity,
        DateTimeImmutable $at
    ): array;
}
