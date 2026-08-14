<?php

declare(strict_types=1);

namespace EduCore\Modules\Finance\Application;

use EduCore\Modules\Finance\Contracts\FinanceTransactionManager;
use EduCore\Modules\Finance\Contracts\Repositories\StaffCompensationContractRepository;
use EduCore\Modules\Finance\Domain\FinanceAuthorization;
use EduCore\Modules\Finance\Domain\Money;
use EduCore\Modules\Operations\Audit\AuditEventWriter;
use InvalidArgumentException;

final class StaffCompensationService
{
    public function __construct(
        private StaffCompensationContractRepository $contracts,
        private FinanceTransactionManager $transactions,
        private AuditEventWriter $audit
    ) {
    }

    public function createDraft(int $staffId, string $effectiveFrom, string $provenance, string $historyConfidence, array $components, int $createdBy): int
    {
        if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $effectiveFrom) !== 1
            || !in_array($provenance, ['business_decision', 'legacy_migration', 'other'], true)
            || !in_array($historyConfidence, ['confirmed', 'uncertain'], true)
            || $components === []) {
            throw new InvalidArgumentException('Invalid staff compensation contract context.');
        }

        return $this->transactions->transactional(function () use ($staffId, $effectiveFrom, $provenance, $historyConfidence, $components, $createdBy): int {
            $contractId = $this->contracts->createContract($staffId, $effectiveFrom, $provenance, $historyConfidence, $createdBy);
            foreach ($components as $component) {
                if (!isset($component['component_id'], $component['amount'], $component['direction'])
                    || !$component['amount'] instanceof Money
                    || !in_array($component['direction'], ['earning', 'deduction'], true)) {
                    throw new InvalidArgumentException('Invalid staff compensation component.');
                }
                $this->contracts->addComponent(
                    $contractId,
                    (int) $component['component_id'],
                    $component['amount']->toDatabaseString(),
                    (string) $component['direction'],
                    $effectiveFrom
                );
            }
            $this->audit->recordEvent('finance_staff_contract_create', 'staff_compensation_contract', $contractId, null, ['staff_id' => $staffId, 'effective_from' => $effectiveFrom, 'component_count' => count($components)]);
            return $contractId;
        });
    }

    public function activate(int $contractId, int $approvedBy): void
    {
        $this->transactions->transactional(function () use ($contractId, $approvedBy): void {
            $contract = $this->contracts->findContractById($contractId);
            if ($contract === null) {
                throw new InvalidArgumentException('Staff compensation contract was not found.');
            }
            FinanceAuthorization::assertMakerChecker('staff_contract_approve', (int) $contract['created_by'], $approvedBy);
            $this->contracts->activateContract($contractId, $approvedBy);
            $this->audit->recordEvent('finance_staff_contract_activate', 'staff_compensation_contract', $contractId, null, ['approved_by' => $approvedBy]);
        });
    }
}
