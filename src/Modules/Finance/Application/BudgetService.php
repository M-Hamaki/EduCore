<?php

declare(strict_types=1);

namespace EduCore\Modules\Finance\Application;

use EduCore\Modules\Finance\Contracts\FinanceTransactionManager;
use EduCore\Modules\Finance\Contracts\Repositories\BudgetRepository;
use EduCore\Modules\Finance\Domain\FinanceAuthorization;
use EduCore\Modules\Finance\Domain\Money;
use EduCore\Modules\Operations\Audit\AuditEventWriter;
use RuntimeException;

final class BudgetService
{
    public function __construct(
        private BudgetRepository $budgets,
        private FinanceTransactionManager $transactions,
        private AuditEventWriter $audit
    ) {
    }

    public function createBudget(int $academicYearId, string $name, int $createdBy): int
    {
        if ($academicYearId <= 0 || $createdBy <= 0 || trim($name) === '') {
            throw new RuntimeException('Invalid budget context.');
        }
        return $this->transactions->transactional(function () use ($academicYearId, $name, $createdBy): int {
            $id = $this->budgets->create($academicYearId, trim($name), $createdBy);
            $this->audit->recordEvent('finance_budget_create', 'finance_budget', $id, trim($name), ['academic_year_id' => $academicYearId]);
            return $id;
        });
    }

    public function createVersion(int $budgetId, int $createdBy): int
    {
        return $this->transactions->transactional(function () use ($budgetId, $createdBy): int {
            $id = $this->budgets->createVersion($budgetId, $createdBy);
            $this->audit->recordEvent('finance_budget_version_create', 'finance_budget_version', $id, null, ['budget_id' => $budgetId]);
            return $id;
        });
    }

    public function addLine(int $versionId, int $accountId, ?int $costCenterId, ?int $periodId, Money $plannedAmount): int
    {
        return $this->transactions->transactional(function () use ($versionId, $accountId, $costCenterId, $periodId, $plannedAmount): int {
            $id = $this->budgets->addLine($versionId, $accountId, $costCenterId, $periodId, $plannedAmount->toDatabaseString());
            $this->audit->recordEvent('finance_budget_line_create', 'finance_budget_line', $id, null, ['version_id' => $versionId, 'planned_amount' => $plannedAmount->toDatabaseString()]);
            return $id;
        });
    }

    public function actualForLine(int $accountId, ?int $costCenterId, ?int $periodId): string
    {
        return $this->budgets->actualForLine($accountId, $costCenterId, $periodId);
    }

    public function approveBudget(int $budgetId, int $approvedBy): void
    {
        $this->transactions->transactional(function () use ($budgetId, $approvedBy): void {
            $budget = $this->budgets->lockById($budgetId);
            if ($budget === null || (string) $budget['status'] !== 'reviewed') {
                throw new RuntimeException('Budget not found.');
            }
            FinanceAuthorization::assertMakerChecker('budget_approve', (int) $budget['created_by'], $approvedBy);
            $this->budgets->approve($budgetId, $approvedBy);
            $activeVersionId = $this->budgets->activateLatestVersion($budgetId);
            $this->audit->recordEvent('finance_budget_approve', 'finance_budget', $budgetId, (string) $budget['name'], ['approved_by' => $approvedBy, 'active_version_id' => $activeVersionId]);
        });
    }

    public function reviewBudget(int $budgetId, int $reviewedBy): void
    {
        $this->transactions->transactional(function () use ($budgetId, $reviewedBy): void {
            $budget = $this->budgets->lockById($budgetId);
            if ($budget === null || !in_array((string) $budget['status'], ['draft', 'revised'], true)) {
                throw new RuntimeException('Only a draft or revised budget can be reviewed.');
            }
            $this->budgets->review($budgetId, $reviewedBy);
            $this->audit->recordEvent('finance_budget_review', 'finance_budget', $budgetId, (string) $budget['name'], ['reviewed_by' => $reviewedBy]);
        });
    }

    public function lockBudget(int $budgetId, int $lockedBy): void
    {
        $this->transactions->transactional(function () use ($budgetId, $lockedBy): void {
            $this->budgets->lock($budgetId);
            $this->audit->recordEvent('finance_budget_lock', 'finance_budget', $budgetId, null, ['locked_by' => $lockedBy]);
        });
    }

    public function reviseBudget(int $budgetId, int $createdBy): int
    {
        return $this->transactions->transactional(function () use ($budgetId, $createdBy): int {
            $budget = $this->budgets->lockById($budgetId);
            if ($budget === null || (string) $budget['status'] !== 'locked') {
                throw new RuntimeException('Only a locked budget can be revised.');
            }
            $this->budgets->revise($budgetId);
            $versionId = $this->budgets->createVersion($budgetId, $createdBy);
            $this->audit->recordEvent('finance_budget_revise', 'finance_budget', $budgetId, (string) $budget['name'], ['version_id' => $versionId, 'created_by' => $createdBy]);
            return $versionId;
        });
    }
}
