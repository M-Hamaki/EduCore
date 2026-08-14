<?php

declare(strict_types=1);

namespace EduCore\Modules\Finance\Contracts\Queries;

interface FinanceReportQuery
{
    public function trialBalance(?int $financePeriodId): array;
    public function profitAndLoss(?int $financePeriodId, ?int $costCenterId): array;
    public function cashFlow(?int $financePeriodId, ?int $costCenterId): array;
    public function collectionSummary(int $academicYearId): array;
    public function debtAging(int $academicYearId): array;
    public function payrollSummary(int $payrollRunId): array;
    public function budgetVsActual(int $budgetVersionId): array;
}
