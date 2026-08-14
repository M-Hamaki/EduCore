<?php

declare(strict_types=1);

namespace EduCore\Modules\Staff\Application\Permission;

use DateTimeImmutable;
use DomainException;
use EduCore\Modules\Staff\Contracts\PermissionRequestAuthorization;
use EduCore\Modules\Staff\Contracts\StaffOrganizationRepository;

/** HR-only authorization used by the employment lifecycle orchestrator. */
final class StaffLifecyclePermissionAuthorization implements PermissionRequestAuthorization
{
    public function __construct(private StaffOrganizationRepository $organization)
    {
    }

    public function assertCanAct(int $actorId, int $staffUserId, string $action, DateTimeImmutable $atInstant): void
    {
        if ($action !== 'service_end' || $actorId <= 0 || $staffUserId <= 0
            || !$this->organization->actorCanManageOrganization($actorId)) {
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
