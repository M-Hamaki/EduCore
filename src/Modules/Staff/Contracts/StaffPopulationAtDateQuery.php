<?php

declare(strict_types=1);

namespace EduCore\Modules\Staff\Contracts;

use DateTimeImmutable;

/** Staff-owned enumeration boundary used by impact previews and reports. */
interface StaffPopulationAtDateQuery
{
    /**
     * @return array{
     *     staff:list<array{
     *         staff_id:int,
     *         assignment_id:int,
     *         org_unit_id:int,
     *         job_title_id:int,
     *         group_ids:list<int>,
     *         employment_status:string
     *     }>,
     *     conflicts:list<array{
     *         staff_id:int,
     *         assignment_ids:list<int>,
     *         reason:string
     *     }>
     * }
     */
    public function forScope(
        string $scopeType,
        ?int $scopeId,
        DateTimeImmutable $atDate
    ): array;
}
