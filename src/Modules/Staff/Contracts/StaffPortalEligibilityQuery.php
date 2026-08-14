<?php

declare(strict_types=1);

namespace EduCore\Modules\Staff\Contracts;

use DateTimeImmutable;

/** Staff-owned portal eligibility lookup; active_role alone is never sufficient. */
interface StaffPortalEligibilityQuery
{
    /**
     * @return array{
     *     eligible:bool,
     *     staff_id:?int,
     *     active_assignment_id:?int,
     *     capabilities:list<string>
     * }
     */
    public function forUser(int $userId, DateTimeImmutable $atDate): array;
}
