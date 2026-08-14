<?php

declare(strict_types=1);

namespace EduCore\Modules\Staff\Contracts;

use DateTimeImmutable;

/**
 * Minimal Staff-owned read evidence for the role-independent worker portal.
 *
 * This contract deliberately does not expose a session role. A valid Staff
 * profile and current assignment establish self-service eligibility, while a
 * separate effective-dated manager relationship establishes the inbox affordance.
 */
interface StaffPortalEligibilityReadRepository
{
    /** Confirms a non-disabled login account has a Staff profile. */
    public function hasActiveStaffProfile(int $userId): bool;

    /**
     * Returns an effective direct/administrative manager relationship version
     * only when it resolves to at least one other active Staff member.
     */
    public function activeManagerScopeVersion(int $managerUserId, DateTimeImmutable $atDate): ?int;
}
