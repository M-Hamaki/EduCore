<?php

declare(strict_types=1);

namespace EduCore\Modules\Finance\Contracts\Repositories;

/**
 * Repository contract for budgets and versioned budget lines (planned vs actual).
 */
interface BudgetRepository
{
    public function findById(int $budgetId): ?array;
    public function lockById(int $budgetId): ?array;
    public function findVersionById(int $versionId): ?array;

    /**
     * Create a budget for an academic year (draft).
     *
     * @param int $academicYearId
     * @param string $name
     * @param int $createdBy
     * @return int the budget id
     */
    public function create(int $academicYearId, string $name, int $createdBy): int;

    /**
     * Create a new version of a budget.
     *
     * @param int $budgetId
     * @param int $createdBy
     * @return int the version id
     */
    public function createVersion(int $budgetId, int $createdBy): int;

    /**
     * Add a planned line to a budget version.
     *
     * @param int $versionId
     * @param int $accountId
     * @param int|null $costCenterId
     * @param int|null $periodId
     * @param string $plannedAmount decimal
     * @return int the line id
     */
    public function addLine(int $versionId, int $accountId, ?int $costCenterId, ?int $periodId, string $plannedAmount): int;

    /**
     * Actual posted amount for an account (optionally by cost center).
     *
     * @param int $accountId
     * @param int|null $costCenterId
     * @return string decimal amount
     */
    public function actualForLine(int $accountId, ?int $costCenterId, ?int $periodId): string;

    /**
     * Approve a budget.
     *
     * @param int $budgetId
     * @param int $approvedBy
     */
    public function approve(int $budgetId, int $approvedBy): void;

    public function lock(int $budgetId): void;
    public function review(int $budgetId, int $reviewedBy): void;
    public function revise(int $budgetId): void;
    public function activateLatestVersion(int $budgetId): int;
}
