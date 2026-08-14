<?php

declare(strict_types=1);

namespace EduCore\Modules\Finance\Contracts\Repositories;

/**
 * Repository contract for the student finance account (wrapper over the sub-ledger).
 */
interface StudentFinanceAccountRepository
{
    /**
     * Find or create a student finance account for the academic year.
     *
     * @param int $studentId
     * @param int $academicYearId
     * @param int $subledgerAccountId
     * @return int the account id
     */
    public function findOrCreate(int $studentId, int $academicYearId, int $subledgerAccountId): int;

    /**
     * Find an account by id.
     *
     * @return array|null
     */
    public function findById(int $id): ?array;

    public function lockById(int $id): ?array;
}
