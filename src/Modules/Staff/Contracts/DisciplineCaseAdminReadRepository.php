<?php

declare(strict_types=1);

namespace EduCore\Modules\Staff\Contracts;

/**
 * Read-only, presentation-safe discipline case index.
 *
 * It deliberately excludes reasons, evidence, decision text, and private
 * attachment references. Detail disclosure remains a separately authorized
 * case workflow, not a list-page side effect.
 */
interface DisciplineCaseAdminReadRepository
{
    /**
     * @param array{status?:string,confidentiality_level?:string,date_from?:string,date_to?:string} $filters
     * @return list<array<string,mixed>>
     */
    public function listSummaries(array $filters, int $limit, int $offset): array;

    /**
     * @param array{status?:string,confidentiality_level?:string,date_from?:string,date_to?:string} $filters
     */
    public function countSummaries(array $filters): int;

    /** @return array<string,mixed>|null */
    public function summaryById(int $caseId): ?array;
}
