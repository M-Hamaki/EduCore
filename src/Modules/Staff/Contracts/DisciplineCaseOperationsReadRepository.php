<?php

declare(strict_types=1);

namespace EduCore\Modules\Staff\Contracts;

interface DisciplineCaseOperationsReadRepository
{
    /** @param list<int> $caseIds @return array<int,array<string,mixed>> keyed by case id */
    public function forCaseIds(array $caseIds): array;
}
