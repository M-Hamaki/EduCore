<?php

declare(strict_types=1);

namespace EduCore\Modules\Staff\Application\Portal;

use DateTimeImmutable;
use DomainException;
use EduCore\Modules\Staff\Contracts\PermissionRequestAuthorization;
use EduCore\Modules\Staff\Contracts\StaffPortalEligibilityQuery;

/** Least-privilege authorization for worker-owned permission commands. */
final class StaffSelfServiceAuthorization implements PermissionRequestAuthorization
{
    public function __construct(private StaffPortalEligibilityQuery $eligibility)
    {
    }

    public function assertCanAct(
        int $actorId,
        int $staffUserId,
        string $action,
        DateTimeImmutable $atInstant
    ): void {
        if ($actorId <= 0 || $staffUserId <= 0 || $actorId !== $staffUserId) {
            throw new DomainException('PERMISSION_REQUEST_OWNER_ONLY');
        }

        $result = $this->eligibility->forUser($actorId, $atInstant);
        if (($result['eligible'] ?? false) !== true
            || (int) ($result['staff_id'] ?? 0) !== $actorId
            || !in_array('staff.portal.self_service', (array) ($result['capabilities'] ?? []), true)) {
            throw new DomainException('PERMISSION_REQUEST_FORBIDDEN');
        }
    }

    public function assertCanSubmitRetroactive(
        int $actorId,
        int $staffUserId,
        int $permissionTypeId,
        DateTimeImmutable $fromAt,
        DateTimeImmutable $atInstant
    ): void {
        $this->assertCanAct($actorId, $staffUserId, 'submit_retroactive', $atInstant);
        throw new DomainException('PERMISSION_REQUEST_RETROACTIVE_NOT_ALLOWED');
    }

    public function canOverrideQuota(
        int $actorId,
        int $staffUserId,
        int $permissionTypeId,
        DateTimeImmutable $atInstant
    ): bool {
        return false;
    }
}
