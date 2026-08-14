<?php

declare(strict_types=1);

namespace EduCore\Modules\Finance\Application;

use EduCore\Modules\AcademicStructure\Contracts\AcademicYearQuery;
use EduCore\Modules\Finance\Contracts\FinanceTransactionManager;
use EduCore\Modules\Finance\Contracts\Repositories\FinancePeriodRepository;
use EduCore\Modules\Finance\Domain\FinanceAuthorization;
use EduCore\Modules\Finance\Domain\FinancePeriod;
use EduCore\Modules\Operations\Audit\AuditEventWriter;
use InvalidArgumentException;
use RuntimeException;

final class FinancePeriodService
{
    public function __construct(private FinancePeriodRepository $periods, private AcademicYearQuery $academicYears, private FinanceTransactionManager $transactions, private AuditEventWriter $audit) {}

    public function createPeriod(int $academicYearId, string $name, ?string $startDate, ?string $endDate, int $createdBy): int
    {
        $name = trim($name);
        if ($academicYearId <= 0 || $name === '' || $createdBy <= 0) { throw new InvalidArgumentException('Academic year, period name, and creator are required.'); }
        if (($startDate !== null && !$this->isDate($startDate)) || ($endDate !== null && !$this->isDate($endDate)) || ($startDate !== null && $endDate !== null && $endDate < $startDate)) { throw new InvalidArgumentException('Finance period date range is invalid.'); }
        $this->assertAcademicYearWritable($academicYearId);
        return $this->transactions->transactional(function () use ($academicYearId, $name, $startDate, $endDate, $createdBy): int {
            $id = $this->periods->create($academicYearId, $name, $startDate, $endDate);
            $this->audit->recordEvent('finance_period_create', 'finance_period', $id, $name, ['academic_year_id' => $academicYearId, 'created_by' => $createdBy]);
            return $id;
        });
    }

    public function closePeriod(int $periodId, int $closedBy, int $approvedBy): void
    {
        FinanceAuthorization::assertMakerChecker('period_close', $closedBy, $approvedBy);
        $this->transactions->transactional(function () use ($periodId, $closedBy, $approvedBy): void {
            $period = $this->lockedPeriod($periodId);
            $this->assertAcademicYearWritable((int) $period['academic_year_id']);
            if ((string) $period['status'] === FinancePeriod::CLOSED) { throw new RuntimeException('Finance period is already closed.'); }
            $this->periods->close($periodId, $closedBy, $approvedBy);
            $this->audit->recordEvent('finance_period_close', 'finance_period', $periodId, (string) $period['name'], ['maker_id' => $closedBy, 'checker_id' => $approvedBy]);
        });
    }

    public function reopenPeriod(int $periodId, int $reopenedBy, int $approvedBy, string $reason): void
    {
        $reason = trim($reason);
        if ($reason === '') { throw new InvalidArgumentException('A reopen reason is required.'); }
        FinanceAuthorization::assertMakerChecker('period_reopen', $reopenedBy, $approvedBy);
        $this->transactions->transactional(function () use ($periodId, $reopenedBy, $approvedBy, $reason): void {
            $period = $this->lockedPeriod($periodId);
            $this->assertAcademicYearWritable((int) $period['academic_year_id']);
            if ((string) $period['status'] !== FinancePeriod::CLOSED) { throw new RuntimeException('Only a closed finance period can be reopened.'); }
            $this->periods->reopen($periodId, $reason, $reopenedBy, $approvedBy);
            $this->audit->recordEvent('finance_period_reopen', 'finance_period', $periodId, (string) $period['name'], ['maker_id' => $reopenedBy, 'checker_id' => $approvedBy, 'reason' => $reason]);
        });
    }

    public function assertWritable(int $academicYearId, int $periodId): array
    {
        $this->assertAcademicYearWritable($academicYearId);
        $period = $this->periods->findById($periodId);
        if ($period === null || (int) $period['academic_year_id'] !== $academicYearId) { throw new RuntimeException('Finance period does not belong to the selected academic year.'); }
        (new FinancePeriod((int) $period['id'], $academicYearId, (string) $period['status']))->assertWritable();
        return $period;
    }

    private function lockedPeriod(int $periodId): array
    {
        if ($periodId <= 0) { throw new InvalidArgumentException('Invalid finance period identifier.'); }
        $period = $this->periods->lockById($periodId);
        if ($period === null) { throw new RuntimeException('Finance period not found.'); }
        return $period;
    }

    private function assertAcademicYearWritable(int $academicYearId): void
    {
        $year = $this->academicYears->yearOf($academicYearId);
        if ($year === null || (string) ($year['status'] ?? '') !== 'active' || (int) ($year['locked'] ?? 0) === 1) { throw new RuntimeException('Academic year is unavailable or historically locked.'); }
    }

    private function isDate(string $value): bool
    {
        $date = \DateTimeImmutable::createFromFormat('!Y-m-d', $value);
        return $date !== false && $date->format('Y-m-d') === $value;
    }
}
