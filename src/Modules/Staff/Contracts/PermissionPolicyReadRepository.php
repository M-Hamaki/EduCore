<?php

declare(strict_types=1);

namespace EduCore\Modules\Staff\Contracts;

use DateTimeImmutable;

/**
 * Staff-owned read boundary for effective-dated permission policies.
 *
 * The caller supplies the dated assignment snapshot so this contract never
 * infers an historical organization, title, or group from a current profile.
 */
interface PermissionPolicyReadRepository
{
    /** @return array<string,mixed>|null */
    public function findType(int $permissionTypeId): ?array;

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
        int $permissionTypeId,
        int $staffId,
        array $assignment,
        DateTimeImmutable $effectiveAt
    ): array;
}
