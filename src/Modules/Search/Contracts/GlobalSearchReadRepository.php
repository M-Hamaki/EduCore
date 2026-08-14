<?php

declare(strict_types=1);

namespace EduCore\Modules\Search\Contracts;

/**
 * Read-only search contract spanning the approved global-search projection.
 *
 * @phpstan-type SearchToken array{original:string,normalized:string}
 */
interface GlobalSearchReadRepository
{
    /**
     * @param array<int,array{original:string,normalized:string}> $tokens
     * @param array<int,int>|null $allowedClassIds Null means unrestricted.
     * @return array<int,array<string,mixed>>
     */
    public function searchStudents(
        array $tokens,
        int $academicYearId,
        ?array $allowedClassIds,
        int $limit
    ): array;

    /**
     * @param array<int,array{original:string,normalized:string}> $tokens
     * @return array<int,array<string,mixed>>
     */
    public function searchStaff(array $tokens, int $limit): array;

    /**
     * @param array<int,array{original:string,normalized:string}> $tokens
     * @param array<int,int>|null $allowedClassIds Null means unrestricted.
     * @return array<int,array<string,mixed>>
     */
    public function searchClasses(
        array $tokens,
        int $academicYearId,
        ?array $allowedClassIds,
        int $limit
    ): array;

    /**
     * @param array<int,array{original:string,normalized:string}> $tokens
     * @return array<int,array<string,mixed>>
     */
    public function searchSubjects(array $tokens, int $limit): array;

    /**
     * @param array<int,array{original:string,normalized:string}> $tokens
     * @return array<int,array<string,mixed>>
     */
    public function searchBuses(array $tokens, int $limit): array;
}
