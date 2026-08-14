<?php

declare(strict_types=1);

namespace EduCore\Modules\Staff\Contracts;

use DateTimeImmutable;

/**
 * Staff-owned locked read boundary for leave operational-capacity rules.
 *
 * The implementation must lock the effective population in a stable order
 * before reading existing absences, so two different workers cannot both
 * submit the last available operational slot concurrently.
 */
interface LeaveStaffingReadRepository
{
    /**
     * @param array<string,mixed> $assignment
     * @return list<array{
     *     id:int,
     *     scope_type:string,
     *     scope_id:int,
     *     from_at:string,
     *     to_at:string,
     *     label:string,
     *     requires_override:bool,
     *     override_role_key:?string
     * }>
     */
    public function blackoutsFor(
        int $policyVersionId,
        array $assignment,
        DateTimeImmutable $fromAt,
        DateTimeImmutable $toAt
    ): array;

    /**
     * Locks the effective active population and any submitted leave rows for
     * a work date, returning IDs only to the policy owner.
     *
     * @return array{
     *     staff_ids:list<int>,
     *     absent_staff_ids:list<int>,
     *     conflicting_staff_ids:list<int>
     * }
     */
    public function availabilityForScopeForUpdate(
        string $scopeType,
        int $scopeId,
        DateTimeImmutable $workDate,
        ?int $excludingRequestId = null
    ): array;
}
