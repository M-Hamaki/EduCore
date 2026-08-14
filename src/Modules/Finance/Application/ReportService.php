<?php

declare(strict_types=1);

namespace EduCore\Modules\Finance\Application;

use EduCore\Modules\Finance\Contracts\Queries\FinanceReportQuery;

final class ReportService
{
    public function __construct(private FinanceReportQuery $reports)
    {
    }

    public function trialBalance(?int $financePeriodId = null): array { return $this->reports->trialBalance($financePeriodId); }
    public function profitAndLoss(?int $financePeriodId = null, ?int $costCenterId = null): array { return $this->reports->profitAndLoss($financePeriodId, $costCenterId); }
    public function cashFlow(?int $financePeriodId = null, ?int $costCenterId = null): array { return $this->reports->cashFlow($financePeriodId, $costCenterId); }
    public function collectionSummary(int $academicYearId): array { return $this->reports->collectionSummary($academicYearId); }
    public function debtAging(int $academicYearId): array { return $this->reports->debtAging($academicYearId); }
    public function payrollSummary(int $payrollRunId): array { return $this->reports->payrollSummary($payrollRunId); }
    public function budgetVsActual(int $budgetVersionId): array { return $this->reports->budgetVsActual($budgetVersionId); }
}
