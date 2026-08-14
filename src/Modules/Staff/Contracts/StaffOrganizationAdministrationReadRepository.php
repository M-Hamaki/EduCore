<?php

declare(strict_types=1);

namespace EduCore\Modules\Staff\Contracts;

/** Read model for the Staff-owned organization administration surface. */
interface StaffOrganizationAdministrationReadRepository
{
    /**
     * Returns only presentation-safe identifiers, names, effective ranges, and
     * statuses required to compose administrative forms and lists.
     *
     * @return array{
     *     org_units:list<array<string,mixed>>,
     *     job_titles:list<array<string,mixed>>,
     *     policy_groups:list<array<string,mixed>>,
     *     group_memberships:list<array<string,mixed>>,
     *     manager_assignments:list<array<string,mixed>>,
     *     assignments:list<array<string,mixed>>,
     *     staff:list<array<string,mixed>>
     * }
     */
    public function dashboard(int $limit): array;
}
