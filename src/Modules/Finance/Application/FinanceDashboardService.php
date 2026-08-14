<?php

declare(strict_types=1);

namespace EduCore\Modules\Finance\Application;

use EduCore\Modules\AcademicStructure\Contracts\AcademicYearQuery;
use EduCore\Modules\Finance\Contracts\Queries\FinanceAdminQuery;
use EduCore\Modules\Finance\Contracts\Queries\FinanceReportQuery;

final class FinanceDashboardService
{
    public function __construct(private AcademicYearQuery $academicYears, private FinanceReportQuery $reports, private FinanceAdminQuery $admin) {}

    /** @return array{academic_year_id:int,total_charges:string,total_collected:string,total_outstanding:string,receipt_count:int} */
    public function summary(): array
    {
        $yearId = $this->academicYears->currentId();
        if ($yearId <= 0) { return ['academic_year_id' => 0, 'total_charges' => '0.00', 'total_collected' => '0.00', 'total_outstanding' => '0.00', 'receipt_count' => 0]; }
        $summary = $this->reports->collectionSummary($yearId);
        $receipts = $this->admin->rows('receipts', [], 500);
        $receiptCount = count(array_filter($receipts, static fn (array $row): bool => (int) ($row['academic_year_id'] ?? 0) === $yearId && ($row['status'] ?? '') === 'posted' && empty($row['reversal_of'])));
        return ['academic_year_id' => $yearId, 'total_charges' => (string) ($summary['total_charges'] ?? '0.00'), 'total_collected' => (string) ($summary['total_collected'] ?? '0.00'), 'total_outstanding' => (string) ($summary['total_outstanding'] ?? '0.00'), 'receipt_count' => $receiptCount];
    }
}
