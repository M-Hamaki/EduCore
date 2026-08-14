<?php

declare(strict_types=1);

namespace EduCore\Modules\Staff\Application\Discipline;

use EduCore\Modules\Staff\Contracts\DisciplineCaseOperationsReadRepository;

/** Operational identifiers and optimistic locks, deliberately separate from the public safe case index. */
final class DisciplineCaseOperationsQuery
{
    public function __construct(private DisciplineCaseOperationsReadRepository $repository)
    {
    }

    /** @param list<int> $caseIds @return array<int,array<string,mixed>> */
    public function forCaseIds(array $caseIds): array
    {
        $caseIds = array_values(array_unique(array_filter(array_map('intval', $caseIds), static fn (int $id): bool => $id > 0)));
        return $caseIds === [] ? [] : $this->repository->forCaseIds($caseIds);
    }
}
