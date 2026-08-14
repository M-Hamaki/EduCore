<?php

declare(strict_types=1);

namespace EduCore\Modules\Staff\Contracts;

/** Extra effective-dated mutations reserved for the lifecycle orchestrator. */
interface StaffEmploymentLifecycleRepository extends StaffOrganizationRepository
{
    /** @return array<string,mixed>|null */
    public function currentPrimaryAssignmentForUpdate(int $staffUserId, string $effectiveDate): ?array;

    public function closeAssignment(int $assignmentId, string $validTo): bool;
}
