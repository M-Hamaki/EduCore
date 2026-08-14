<?php

declare(strict_types=1);

namespace EduCore\Modules\Finance\Application;

use EduCore\Modules\Finance\Contracts\FinanceTransactionManager;
use EduCore\Modules\Finance\Contracts\Repositories\FeePlanRepository;
use EduCore\Modules\Finance\Domain\Money;
use EduCore\Modules\Operations\Audit\AuditEventWriter;
use InvalidArgumentException;
use RuntimeException;

final class FeePlanService
{
    public function __construct(
        private FeePlanRepository $feePlans,
        private FinanceTransactionManager $transactions,
        private AuditEventWriter $audit
    ) {
    }

    public function createPlan(int $chargeTypeId, int $academicYearId, ?int $gradeId, string $name, int $createdBy): int
    {
        if ($chargeTypeId <= 0 || $academicYearId <= 0 || $createdBy <= 0 || trim($name) === '') {
            throw new InvalidArgumentException('Invalid fee plan context.');
        }
        return $this->transactions->transactional(function () use ($chargeTypeId, $academicYearId, $gradeId, $name, $createdBy): int {
            $id = $this->feePlans->createPlan($chargeTypeId, $academicYearId, $gradeId, trim($name), $createdBy);
            $this->audit->recordEvent('finance_fee_plan_create', 'finance_fee_plan', $id, trim($name), ['academic_year_id' => $academicYearId, 'charge_type_id' => $chargeTypeId]);
            return $id;
        });
    }

    public function findOrCreatePlan(int $chargeTypeId, int $academicYearId, ?int $gradeId, string $name, int $createdBy): int
    {
        $existing = $this->feePlans->findPlan($chargeTypeId, $academicYearId, $gradeId);
        return $existing === null
            ? $this->createPlan($chargeTypeId, $academicYearId, $gradeId, $name, $createdBy)
            : (int) $existing['id'];
    }

    public function createVersion(int $feePlanId, string $effectiveFrom, array $installments, int $createdBy): int
    {
        if ($installments === [] || preg_match('/^\d{4}-\d{2}-\d{2}$/', $effectiveFrom) !== 1) {
            throw new InvalidArgumentException('Fee-plan version requires a date and installments.');
        }
        return $this->transactions->transactional(function () use ($feePlanId, $effectiveFrom, $installments, $createdBy): int {
            $versionId = $this->feePlans->createVersion($feePlanId, $this->feePlans->nextVersionNumber($feePlanId), null, $effectiveFrom);
            $total = Money::zero();
            foreach (array_values($installments) as $index => $installment) {
                if (!isset($installment['gross_amount']) || !$installment['gross_amount'] instanceof Money || trim((string) ($installment['name'] ?? '')) === '') {
                    throw new InvalidArgumentException('Every fee installment requires a name and Money amount.');
                }
                $amount = $installment['gross_amount'];
                $total = $total->add($amount);
                $this->feePlans->addInstallment($versionId, trim((string) $installment['name']), $amount->toDatabaseString(), $installment['due_date'] ?? null, (int) ($installment['display_order'] ?? $index + 1));
            }
            $this->audit->recordEvent('finance_fee_plan_version_create', 'finance_fee_plan_version', $versionId, null, ['fee_plan_id' => $feePlanId, 'total' => $total->toDatabaseString(), 'created_by' => $createdBy]);
            return $versionId;
        });
    }

    public function activateVersion(int $versionId, int $activatedBy): void
    {
        $this->transactions->transactional(function () use ($versionId, $activatedBy): void {
            $this->feePlans->activateVersion($versionId);
            $this->audit->recordEvent('finance_fee_plan_version_activate', 'finance_fee_plan_version', $versionId, null, ['activated_by' => $activatedBy]);
        });
    }

    public function isVersionUsed(int $versionId): bool { return $this->feePlans->isVersionUsed($versionId); }

    public function assertVersionEditable(int $versionId): void
    {
        if ($this->isVersionUsed($versionId)) {
            throw new RuntimeException('A used fee-plan version is immutable; create a new version.');
        }
    }

    public function archivePlan(int $feePlanId, int $archivedBy): void
    {
        $this->transactions->transactional(function () use ($feePlanId, $archivedBy): void {
            $plan = $this->feePlans->findPlanById($feePlanId);
            if ($plan === null) {
                throw new RuntimeException('Fee plan does not exist.');
            }
            if ($this->feePlans->isPlanUsed($feePlanId)) {
                throw new RuntimeException('A used fee plan cannot be deleted; create a replacement version or archive it after its contracts end.');
            }
            $this->feePlans->archivePlan($feePlanId);
            $this->audit->recordEvent('finance_fee_plan_archive', 'finance_fee_plan', $feePlanId, (string) $plan['name'], ['archived_by' => $archivedBy]);
        });
    }
}
