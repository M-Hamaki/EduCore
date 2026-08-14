<?php

declare(strict_types=1);

namespace EduCore\Modules\Staff\Contracts;

use DateTimeImmutable;

/**
 * Staff-owned read boundary for effective-dated leave policy rows.
 *
 * The repository returns policy-version/scope joins only. The application
 * service supplies the dated assignment snapshot and resolves precedence, so
 * a current organizational position can never rewrite historical eligibility.
 */
interface LeavePolicyReadRepository
{
    /** @return array<string,mixed>|null */
    public function findType(int $leaveTypeId): ?array;

    /**
     * @param array{
     *     assignment_id:int,
     *     org_unit_id:?int,
     *     job_title_id:?int,
     *     group_ids:list<int>,
     *     employment_status:string
     * } $assignment
     * @return list<array<string,mixed>>
     */
    public function candidateVersionsFor(
        int $leaveTypeId,
        int $staffId,
        array $assignment,
        DateTimeImmutable $effectiveAt
    ): array;
}
