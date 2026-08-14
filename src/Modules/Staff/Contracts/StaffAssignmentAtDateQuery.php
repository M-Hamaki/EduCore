<?php

declare(strict_types=1);

namespace EduCore\Modules\Staff\Contracts;

use DateTimeImmutable;

/**
 * Staff-owned effective-dated assignment boundary.
 *
 * Consumers must query the assignment that is effective on the supplied day;
 * they must not infer historical organization data from the current profile.
 */
interface StaffAssignmentAtDateQuery
{
    /**
     * @return array{
     *     assignment_id:int,
     *     org_unit_id:?int,
     *     job_title_id:?int,
     *     group_ids:list<int>,
     *     employment_status:string
     * }|null
     */
    public function forStaff(int $staffId, DateTimeImmutable $atDate): ?array;
}
