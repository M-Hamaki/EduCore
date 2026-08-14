<?php

declare(strict_types=1);

namespace EduCore\Modules\Students\Contracts;

/**
 * Student-owned read boundary used by Finance compatibility adapters.
 */
interface StudentFinanceReadQuery
{
    /** @return array<string,mixed>|null */
    public function student(int $studentId, int $academicYearId): ?array;

    /** @return list<array<string,mixed>> */
    public function siblings(int $studentId, int $academicYearId): array;

    /** @return list<int> */
    public function studentIds(int $academicYearId, ?int $gradeId, ?int $classId, ?int $studentId): array;

    /**
     * @return array{recordsTotal:int,recordsFiltered:int,rows:list<array<string,mixed>>}
     */
    public function page(int $academicYearId, array $request): array;

    /** @return list<array<string,mixed>> */
    public function matching(int $academicYearId, array $request): array;
}
