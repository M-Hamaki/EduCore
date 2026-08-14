<?php

declare(strict_types=1);

namespace EduCore\Modules\Staff\Application\Portal;

use DateTimeImmutable;
use DomainException;
use EduCore\Modules\Staff\Contracts\LeaveRequestAuthorization;
use EduCore\Modules\Staff\Contracts\StaffPortalEligibilityQuery;

/** Owner-only authorization for leave commands from the shared worker portal. */
final class StaffLeaveSelfServiceAuthorization implements LeaveRequestAuthorization
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
            throw new DomainException('LEAVE_REQUEST_OWNER_ONLY');
        }
        $result = $this->eligibility->forUser($actorId, $atInstant);
        if (($result['eligible'] ?? false) !== true
            || (int) ($result['staff_id'] ?? 0) !== $actorId
            || !in_array('staff.portal.self_service', (array) ($result['capabilities'] ?? []), true)) {
            throw new DomainException('LEAVE_REQUEST_FORBIDDEN');
        }
    }
}
