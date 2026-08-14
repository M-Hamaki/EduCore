<?php

declare(strict_types=1);

namespace EduCore\Modules\Staff\Infrastructure;

use DateTimeImmutable;
use DomainException;
use EduCore\Modules\Staff\Contracts\StaffAssignmentAtDateQuery;
use EduCore\Modules\Staff\Contracts\StaffPopulationAtDateQuery;
use PDO;

/** PDO adapter for one effective primary assignment; ambiguity fails closed. */
final class PdoStaffAssignmentAtDateQuery implements StaffAssignmentAtDateQuery
{
    private StaffPopulationAtDateQuery $population;

    public function __construct(PDO $db, ?StaffPopulationAtDateQuery $population = null)
    {
        $this->population = $population ?? new PdoStaffPopulationAtDateQuery($db);
    }

    public function forStaff(int $staffId, DateTimeImmutable $atDate): ?array
    {
        $result = $this->population->forScope('staff', $staffId, $atDate);
        if ($result['conflicts'] !== []) {
            $ids = $result['conflicts'][0]['assignment_ids'] ?? [];
            throw new DomainException(
                'AMBIGUOUS_STAFF_ASSIGNMENT: ' . implode(', ', $ids)
            );
        }
        if ($result['staff'] === []) {
            return null;
        }

        $assignment = $result['staff'][0];

        return [
            'assignment_id' => $assignment['assignment_id'],
            'org_unit_id' => $assignment['org_unit_id'],
            'job_title_id' => $assignment['job_title_id'],
            'group_ids' => $assignment['group_ids'],
            'employment_status' => $assignment['employment_status'],
        ];
    }
}
