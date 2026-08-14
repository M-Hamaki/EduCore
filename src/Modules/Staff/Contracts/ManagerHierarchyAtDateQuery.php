<?php

declare(strict_types=1);

namespace EduCore\Modules\Staff\Contracts;

use DateTimeImmutable;

/** Resolves a dated reporting relationship without silently choosing conflicts. */
interface ManagerHierarchyAtDateQuery
{
    /**
     * Absence and ambiguity are explicit outcomes. A non-empty conflicts list
     * means callers must stop rather than treating manager_id as authoritative.
     *
     * @return array{
     *     manager_id:?int,
     *     assignment_id:?int,
     *     delegation:?array{
     *         delegation_id:int,
     *         acting_for_user_id:int,
     *         delegate_user_id:int,
     *         valid_from:string,
     *         valid_to:?string
     *     },
     *     conflicts:list<array<string,mixed>>
     * }
     */
    public function resolve(int $staffId, string $managerKind, DateTimeImmutable $atDate): array;
}
