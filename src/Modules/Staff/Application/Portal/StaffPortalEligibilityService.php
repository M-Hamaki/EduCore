<?php

declare(strict_types=1);

namespace EduCore\Modules\Staff\Application\Portal;

use DateTimeImmutable;
use DomainException;
use EduCore\Modules\Staff\Contracts\StaffAssignmentAtDateQuery;
use EduCore\Modules\Staff\Contracts\StaffPortalEligibilityQuery;
use EduCore\Modules\Staff\Contracts\StaffPortalEligibilityReadRepository;
use InvalidArgumentException;

/**
 * Resolves worker self-service eligibility independently from the session's
 * selected role. Role pages can opt into these fixed capabilities, but none
 * may widen the Staff identity, assignment, or manager scope checks below.
 */
final class StaffPortalEligibilityService implements StaffPortalEligibilityQuery
{
    /** @var list<string> */
    private const SELF_SERVICE_CAPABILITIES = [
        'staff.portal.self_service',
    ];

    private const MANAGER_INBOX_CAPABILITY = 'staff.portal.manager_approval_inbox';

    /** @var list<string> */
    private const ELIGIBLE_EMPLOYMENT_STATUSES = ['active', 'rehired'];

    public function __construct(
        private StaffPortalEligibilityReadRepository $portalReadRepository,
        private StaffAssignmentAtDateQuery $datedAssignments
    ) {
    }

    public function forUser(int $userId, DateTimeImmutable $atDate): array
    {
        if ($userId <= 0 || !$this->portalReadRepository->hasActiveStaffProfile($userId)) {
            return $this->notEligible();
        }

        try {
            $assignment = $this->datedAssignments->forStaff($userId, $atDate);
        } catch (DomainException | InvalidArgumentException) {
            // Ambiguous or malformed employment evidence must not grant a
            // worker portal merely because a different role remains active.
            return $this->notEligible();
        }

        if ($assignment === null
            || !in_array(strtolower((string) $assignment['employment_status']), self::ELIGIBLE_EMPLOYMENT_STATUSES, true)
        ) {
            return $this->notEligible();
        }

        $capabilities = self::SELF_SERVICE_CAPABILITIES;
        if ($this->portalReadRepository->activeManagerScopeVersion($userId, $atDate) !== null) {
            $capabilities[] = self::MANAGER_INBOX_CAPABILITY;
        }

        return [
            'eligible' => true,
            'staff_id' => $userId,
            'active_assignment_id' => (int) $assignment['assignment_id'],
            'capabilities' => $capabilities,
        ];
    }

    /** @return array{eligible:false,staff_id:null,active_assignment_id:null,capabilities:list<string>} */
    private function notEligible(): array
    {
        return [
            'eligible' => false,
            'staff_id' => null,
            'active_assignment_id' => null,
            'capabilities' => [],
        ];
    }
}
