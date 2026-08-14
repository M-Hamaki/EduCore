<?php

declare(strict_types=1);

namespace EduCore\Modules\Staff\Contracts;

use DateTimeImmutable;

/**
 * Authorization boundary for the employee-side permission lifecycle.
 *
 * The application service performs the self-owner check itself. This adapter
 * evaluates current account/service eligibility and the separately granted
 * retroactive and quota-override capabilities; no browser-supplied role is
 * accepted as evidence.
 */
interface PermissionRequestAuthorization
{
    public function assertCanAct(
        int $actorId,
        int $staffUserId,
        string $action,
        DateTimeImmutable $atInstant
    ): void;

    public function assertCanSubmitRetroactive(
        int $actorId,
        int $staffUserId,
        int $permissionTypeId,
        DateTimeImmutable $fromAt,
        DateTimeImmutable $atInstant
    ): void;

    public function canOverrideQuota(
        int $actorId,
        int $staffUserId,
        int $permissionTypeId,
        DateTimeImmutable $atInstant
    ): bool;
}
