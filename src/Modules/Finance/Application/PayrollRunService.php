<?php

declare(strict_types=1);

namespace EduCore\Modules\Finance\Application;

use EduCore\Modules\Finance\Contracts\FinanceTransactionManager;
use EduCore\Modules\Finance\Contracts\Repositories\CashboxRepository;
use EduCore\Modules\Finance\Contracts\Repositories\PayrollRunRepository;
use EduCore\Modules\Finance\Contracts\Repositories\StaffCompensationContractRepository;
use EduCore\Modules\Finance\Domain\FinanceAuthorization;
use EduCore\Modules\Finance\Domain\Money;
use EduCore\Modules\Finance\Domain\Policy\PayrollCalculationPolicy;
use EduCore\Modules\Finance\Domain\SignedMoneyDelta;
use EduCore\Modules\Operations\Audit\AuditEventWriter;
use InvalidArgumentException;
use RuntimeException;

final class PayrollRunService
{
    public function __construct(
        private PayrollRunRepository $payroll,
        private SubledgerPostingService $posting,
        private JournalEntryService $journals,
        private PayrollCalculationPolicy $calculation,
        private FinanceTransactionManager $transactions,
        private CashboxRepository $cashboxes,
        private AuditEventWriter $audit,
        private StaffCompensationContractRepository $compensation
    ) {
    }

    public function createRun(int $payrollPeriodId, int $createdBy, bool $isSettlement = false, ?string $batchId = null): int
    {
        $batchId = $batchId ?? bin2hex(random_bytes(16));
        return $this->transactions->transactional(function () use ($payrollPeriodId, $createdBy, $isSettlement, $batchId): int {
            $runId = $this->payroll->create($payrollPeriodId, $this->payroll->nextVersion($payrollPeriodId), $createdBy, $isSettlement, $batchId);
            $this->audit->recordEvent('finance_payroll_run_create', 'payroll_run', $runId, null, ['payroll_period_id' => $payrollPeriodId, 'is_settlement' => $isSettlement], ['batch_id' => $batchId]);
            return $runId;
        });
    }

    public function markCalculated(int $runId, int $actorId): void
    {
        $this->transition($runId, 'draft', 'calculated', $actorId);
    }

    public function reviewRun(int $runId, int $reviewedBy): void
    {
        $this->transition($runId, 'calculated', 'reviewed', $reviewedBy);
    }

    public function approveRun(int $runId, int $approvedBy): void
    {
        $run = $this->payroll->findById($runId);
        if ($run === null) {
            throw new RuntimeException('Payroll run was not found.');
        }
        FinanceAuthorization::assertMakerChecker('payroll_approve', (int) $run['created_by'], $approvedBy);
        $this->transition($runId, 'reviewed', 'approved', $approvedBy);
    }

    public function finalizeRun(int $runId, int $postedBy, int $approvedBy): void
    {
        FinanceAuthorization::assertMakerChecker('payroll_post', $postedBy, $approvedBy);
        $this->transactions->transactional(function () use ($runId, $postedBy, $approvedBy): void {
            $this->payroll->post($runId, $postedBy);
            $this->audit->recordEvent('finance_payroll_run_post', 'payroll_run', $runId, null, ['posted_by' => $postedBy, 'approved_by' => $approvedBy]);
        });
    }

    private function transition(int $runId, string $fromStatus, string $toStatus, int $actorId): void
    {
        $this->transactions->transactional(function () use ($runId, $fromStatus, $toStatus, $actorId): void {
            $this->payroll->setStatus($runId, $fromStatus, $toStatus, $actorId);
            $this->audit->recordEvent('finance_payroll_run_' . $toStatus, 'payroll_run', $runId, null, ['from_status' => $fromStatus, 'to_status' => $toStatus, 'actor_id' => $actorId]);
        });
    }

    public function postPayrollItem(int $payrollRunId, int $staffId, string $calculationDate, int $postedBy, int $approvedBy, ?string $requestId = null): int
    {
        FinanceAuthorization::assertMakerChecker('payroll_post', $postedBy, $approvedBy);
        if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $calculationDate) !== 1) {
            throw new InvalidArgumentException('Payroll calculation date is required.');
        }
        $requestId = $requestId ?? bin2hex(random_bytes(16));
        return $this->transactions->transactional(function () use ($payrollRunId, $staffId, $calculationDate, $postedBy, $requestId): int {
            $run = $this->payroll->findById($payrollRunId);
            if ($run === null || !in_array((string) $run['status'], ['approved', 'posted'], true)) {
                throw new RuntimeException('Payroll run must be approved before staff items are posted.');
            }
            $existing = $this->payroll->findItemByRunAndStaff($payrollRunId, $staffId);
            if ($existing !== null) {
                return (int) $existing['id'];
            }
            $contract = $this->compensation->findEffectiveContract($staffId, $calculationDate);
            if ($contract === null) {
                throw new RuntimeException('No active compensation contract applies on the payroll date.');
            }
            $contractComponents = $this->compensation->componentsForContractAtDate((int) $contract['id'], $calculationDate);
            if ($contractComponents === []) {
                throw new RuntimeException('The active compensation contract has no effective components.');
            }
            $components = array_map(static fn (array $component): array => [
                'component_id' => (int) $component['payroll_component_id'],
                'amount' => Money::fromDecimalString((string) $component['amount']),
                'direction' => (string) $component['direction'],
            ], $contractComponents);
            $computed = $this->calculation->compute($components);
            if ($computed['net']->isZero()) {
                throw new RuntimeException('A zero-net payroll item cannot be posted.');
            }
            $snapshot = json_encode(['contract' => $contract, 'components' => $contractComponents, 'calculation_date' => $calculationDate], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
            $itemId = $this->payroll->createItem($payrollRunId, $staffId, $snapshot, $computed['gross']->toDatabaseString(), $computed['total_deductions']->toDatabaseString(), $computed['net']->toDatabaseString(), null);
            $zero = Money::zero();
            $journalLines = [];
            foreach ($components as $component) {
                if (!isset($component['amount'], $component['component_id']) || !$component['amount'] instanceof Money || !in_array($component['direction'], ['earning', 'deduction'], true)) {
                    throw new InvalidArgumentException('Invalid payroll component.');
                }
                $this->payroll->addItemComponent($itemId, (int) $component['component_id'], $component['amount']->toDatabaseString(), (string) $component['direction']);
                $mapping = $this->journals->resolveAccounts('payroll_component', ['payroll_component_id' => (int) $component['component_id']]);
                $journalLines[] = $component['direction'] === 'earning'
                    ? ['account_id' => $mapping['debit_account_id'], 'debit' => $component['amount'], 'credit' => $zero, 'sub_ledger_ref_type' => 'staff', 'sub_ledger_ref_id' => $staffId]
                    : ['account_id' => $mapping['credit_account_id'], 'debit' => $zero, 'credit' => $component['amount'], 'sub_ledger_ref_type' => 'staff', 'sub_ledger_ref_id' => $staffId];
            }
            $payableMapping = $this->journals->resolveAccounts('payroll_run_item_posting');
            $journalLines[] = ['account_id' => $payableMapping['credit_account_id'], 'debit' => $zero, 'credit' => $computed['net'], 'sub_ledger_ref_type' => 'staff', 'sub_ledger_ref_id' => $staffId];
            $batchId = $run['batch_id'] === null ? null : (string) $run['batch_id'];
            $subledgerTransactionId = $this->posting->postPartyOperation('staff', $staffId, 'STAFF_GLOBAL', 'payroll_run_item_posting', $itemId, $requestId, [['bucket' => 'STAFF_PAYROLL_PAYABLE', 'delta' => SignedMoneyDelta::fromMinorUnits($computed['net']->toMinorUnits()), 'description' => 'Payroll posting']], 'payroll_run_item_posting', date('Y-m-d'), $journalLines, $postedBy, $batchId, $requestId);
            $this->payroll->linkItemPosting($itemId, $subledgerTransactionId);
            return $itemId;
        });
    }

    public function postPayment(int $payrollRunItemId, int $staffId, int $cashboxId, Money $amount, string $paymentMethod, int $postedBy, int $approvedBy, ?string $requestId = null): int
    {
        FinanceAuthorization::assertMakerChecker('payroll_post', $postedBy, $approvedBy);
        if (!in_array($paymentMethod, ['cash', 'bank_transfer', 'check', 'card', 'other'], true)) {
            throw new InvalidArgumentException('Unsupported payroll payment method.');
        }
        $requestId = $requestId ?? bin2hex(random_bytes(16));
        $existing = $this->payroll->findPaymentByRequestId($requestId);
        if ($existing !== null) {
            return (int) $existing['id'];
        }
        return $this->transactions->transactional(function () use ($payrollRunItemId, $staffId, $cashboxId, $amount, $paymentMethod, $postedBy, $approvedBy, $requestId): int {
            $item = $this->payroll->lockItem($payrollRunItemId);
            if ($item === null || (int) $item['staff_id'] !== $staffId) {
                throw new RuntimeException('Payroll item does not belong to the staff member.');
            }
            $cashbox = $this->cashboxes->findById($cashboxId);
            if ($cashbox === null || !(bool) $cashbox['is_active']) {
                throw new RuntimeException('Active payroll cashbox was not found.');
            }
            $remaining = Money::fromDecimalString((string) $item['net'])->subtract(Money::fromDecimalString($this->payroll->paidAmountForItem($payrollRunItemId)));
            if ($amount->isZero() || $amount->greaterThan($remaining)) {
                throw new RuntimeException('Payroll payment exceeds unpaid net salary.');
            }
            $paymentId = $this->payroll->createPayment($payrollRunItemId, $cashboxId, $amount->toDatabaseString(), $paymentMethod, $postedBy, $approvedBy, $requestId);
            $mapping = $this->journals->resolveAccounts('payroll_payment', ['payment_method' => $paymentMethod, 'cashbox_id' => $cashboxId]);
            $zero = Money::zero();
            $subledgerTransactionId = $this->posting->postPartyOperation('staff', $staffId, 'STAFF_GLOBAL', 'payroll_payment', $paymentId, $requestId, [['bucket' => 'STAFF_PAYROLL_PAYABLE', 'delta' => SignedMoneyDelta::fromMinorUnits(-$amount->toMinorUnits()), 'description' => 'Payroll payment']], 'payroll_payment', date('Y-m-d'), [
                ['account_id' => $mapping['debit_account_id'], 'debit' => $amount, 'credit' => $zero, 'sub_ledger_ref_type' => 'staff', 'sub_ledger_ref_id' => $staffId],
                ['account_id' => $mapping['credit_account_id'], 'debit' => $zero, 'credit' => $amount, 'sub_ledger_ref_type' => 'staff', 'sub_ledger_ref_id' => $staffId],
            ], $postedBy, null, $requestId);
            $this->payroll->linkPaymentPosting($paymentId, $subledgerTransactionId);
            if ($amount->equals($remaining)) {
                $this->payroll->markItemPaid($payrollRunItemId);
            }
            return $paymentId;
        });
    }

    public function reversePayment(int $paymentId, int $staffId, int $requestedBy, int $approvedBy, string $reason, ?string $requestId = null): int
    {
        FinanceAuthorization::assertMakerChecker('payroll_approve', $requestedBy, $approvedBy);
        $requestId = $requestId ?? bin2hex(random_bytes(16));
        if (!preg_match('/^[a-f0-9]{32}$/i', $requestId) || trim($reason) === '') {
            throw new InvalidArgumentException('Payroll payment reversal requires a request id and reason.');
        }
        $existing = $this->payroll->findPaymentByRequestId($requestId);
        if ($existing !== null) {
            return (int) $existing['id'];
        }

        return $this->transactions->transactional(function () use ($paymentId, $staffId, $requestedBy, $approvedBy, $reason, $requestId): int {
            $original = $this->payroll->lockPayment($paymentId);
            if ($original === null || (string) $original['status'] !== 'posted' || $original['reversal_of'] !== null || $original['subledger_transaction_id'] === null) {
                throw new RuntimeException('Only an original posted payroll payment can be reversed.');
            }
            $alreadyReversed = $this->payroll->findPaymentByReversalOf($paymentId);
            if ($alreadyReversed !== null) {
                return (int) $alreadyReversed['id'];
            }
            $item = $this->payroll->lockItem((int) $original['payroll_run_item_id']);
            if ($item === null || (int) $item['staff_id'] !== $staffId) {
                throw new RuntimeException('Payroll payment does not belong to the staff member.');
            }
            $reversalId = $this->payroll->createPaymentReversal($original, $requestedBy, $approvedBy, $requestId);
            $originalSubledgerId = (int) $original['subledger_transaction_id'];
            $reversalSubledgerId = $this->posting->postReversal(
                $originalSubledgerId,
                $requestId,
                date('Y-m-d'),
                $this->journals->reversalLinesForPartyOperation($originalSubledgerId),
                $requestedBy,
                $original['batch_id'] ?? null,
                $requestId
            );
            $this->payroll->linkPaymentPosting($reversalId, $reversalSubledgerId);
            $this->payroll->refreshItemPaymentStatus((int) $item['id']);
            $this->audit->recordEvent('finance_payroll_payment_reverse', 'payroll_payment', $reversalId, null, ['reversal_of' => $paymentId, 'reason' => trim($reason)], ['request_id' => $requestId]);
            return $reversalId;
        });
    }

    public function payslip(int $payrollRunItemId): array
    {
        $payslip = $this->payroll->payslip($payrollRunItemId);
        if ($payslip === null) {
            throw new RuntimeException('Payslip was not found.');
        }
        return $payslip;
    }

    public function reverseRun(int $runId, int $requestedBy, int $approvedBy, string $reason, ?string $requestId = null): int
    {
        FinanceAuthorization::assertMakerChecker('payroll_approve', $requestedBy, $approvedBy);
        $requestId = $requestId ?? bin2hex(random_bytes(16));
        if (!preg_match('/^[a-f0-9]{32}$/i', $requestId) || trim($reason) === '') {
            throw new InvalidArgumentException('Payroll reversal requires a request id and reason.');
        }

        return $this->transactions->transactional(function () use ($runId, $requestedBy, $approvedBy, $reason, $requestId): int {
            $existing = $this->payroll->findRunByReversalOf($runId);
            if ($existing !== null) {
                return (int) $existing['id'];
            }
            $original = $this->payroll->lockRun($runId);
            if ($original === null || (string) $original['status'] !== 'posted') {
                throw new RuntimeException('Only a posted unpaid payroll run can be reversed.');
            }
            if ($this->payroll->hasPostedPaymentsForRun($runId)) {
                throw new RuntimeException('Reverse posted payroll payments before reversing the payroll run.');
            }

            $batchId = md5('payroll-run-reversal:' . $requestId);
            $reversalRunId = $this->payroll->createReversalRun(
                $original,
                $this->payroll->nextVersion((int) $original['payroll_period_id']),
                $approvedBy,
                $batchId
            );
            foreach ($this->payroll->itemsForRun($runId) as $originalItem) {
                $originalTransactionId = (int) ($originalItem['subledger_transaction_id'] ?? 0);
                if ($originalTransactionId <= 0) {
                    throw new RuntimeException('Payroll run item is missing its posted sub-ledger transaction.');
                }
                $snapshot = json_encode([
                    'reversal_of_item_id' => (int) $originalItem['id'],
                    'reason' => trim($reason),
                    'original_contract_snapshot' => json_decode((string) $originalItem['contract_snapshot_json'], true),
                ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
                $reversalItemId = $this->payroll->createReversalItem($reversalRunId, $originalItem, $snapshot);
                foreach ($this->payroll->componentsForItem((int) $originalItem['id']) as $component) {
                    $negative = SignedMoneyDelta::fromDecimalString((string) $component['amount'])->negate()->toDatabaseString();
                    $this->payroll->addItemComponent($reversalItemId, (int) $component['payroll_component_id'], $negative, (string) $component['direction']);
                }
                $itemRequestId = md5($requestId . ':item:' . (int) $originalItem['id']);
                $reversalTransactionId = $this->posting->postReversal(
                    $originalTransactionId,
                    $itemRequestId,
                    date('Y-m-d'),
                    $this->journals->reversalLinesForPartyOperation($originalTransactionId),
                    $approvedBy,
                    $batchId,
                    $itemRequestId
                );
                $this->payroll->linkItemPosting($reversalItemId, $reversalTransactionId);
                $this->payroll->markItemReversed((int) $originalItem['id']);
            }
            $this->payroll->markReversed($runId, $approvedBy);
            $this->audit->recordEvent('finance_payroll_run_reverse', 'payroll_run', $reversalRunId, null, [
                'reversal_of' => $runId,
                'reason' => trim($reason),
                'requested_by' => $requestedBy,
                'approved_by' => $approvedBy,
            ], ['batch_id' => $batchId, 'request_id' => $requestId]);
            return $reversalRunId;
        });
    }

    public function postRetroactiveSettlementItem(int $settlementRunId, int $originalItemId, string $calculationDate, int $postedBy, int $approvedBy, ?string $requestId = null): int
    {
        FinanceAuthorization::assertMakerChecker('payroll_post', $postedBy, $approvedBy);
        $requestId = $requestId ?? bin2hex(random_bytes(16));
        $date = \DateTimeImmutable::createFromFormat('!Y-m-d', $calculationDate);
        if (!preg_match('/^[a-f0-9]{32}$/i', $requestId) || $date === false || $date->format('Y-m-d') !== $calculationDate) {
            throw new InvalidArgumentException('Settlement requires a request id and calculation date.');
        }

        return $this->transactions->transactional(function () use ($settlementRunId, $originalItemId, $calculationDate, $postedBy, $requestId): int {
            $run = $this->payroll->lockRun($settlementRunId);
            if ($run === null || !(bool) $run['is_settlement'] || !in_array((string) $run['status'], ['approved', 'posted'], true)) {
                throw new RuntimeException('An approved settlement run in an open period is required.');
            }
            if ((string) $run['finance_period_status'] !== 'open') {
                throw new RuntimeException('Retroactive payroll settlement must post into an open finance period.');
            }
            $originalItem = $this->payroll->findItem($originalItemId);
            if ($originalItem === null || $originalItem['reversal_of'] !== null) {
                throw new RuntimeException('An original frozen payslip is required for settlement.');
            }
            $staffId = (int) $originalItem['staff_id'];
            $existing = $this->payroll->findItemByRunAndStaff($settlementRunId, $staffId);
            if ($existing !== null) {
                return (int) $existing['id'];
            }
            $contract = $this->compensation->findEffectiveContract($staffId, $calculationDate);
            if ($contract === null) {
                throw new RuntimeException('No effective compensation contract exists for settlement recalculation.');
            }
            $currentRows = $this->compensation->componentsForContractAtDate((int) $contract['id'], $calculationDate);
            if ($currentRows === []) {
                throw new RuntimeException('Settlement compensation contract has no effective components.');
            }
            $currentComponents = array_map(static fn (array $component): array => [
                'component_id' => (int) $component['payroll_component_id'],
                'amount' => Money::fromDecimalString((string) $component['amount']),
                'direction' => (string) $component['direction'],
            ], $currentRows);
            $current = $this->calculation->compute($currentComponents);
            $grossDelta = SignedMoneyDelta::fromMinorUnits($current['gross']->toMinorUnits() - Money::fromDecimalString((string) $originalItem['gross'])->toMinorUnits());
            $deductionDelta = SignedMoneyDelta::fromMinorUnits($current['total_deductions']->toMinorUnits() - Money::fromDecimalString((string) $originalItem['total_deductions'])->toMinorUnits());
            $netDelta = SignedMoneyDelta::fromMinorUnits($current['net']->toMinorUnits() - Money::fromDecimalString((string) $originalItem['net'])->toMinorUnits());
            if ($grossDelta->isZero() && $deductionDelta->isZero() && $netDelta->isZero()) {
                throw new RuntimeException('The recalculation produced no retroactive difference.');
            }

            $snapshot = json_encode([
                'settlement_of_item_id' => $originalItemId,
                'calculation_date' => $calculationDate,
                'original_contract_snapshot' => json_decode((string) $originalItem['contract_snapshot_json'], true),
                'replacement_contract' => $contract,
                'replacement_components' => $currentRows,
            ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
            $settlementItemId = $this->payroll->createItem(
                $settlementRunId,
                $staffId,
                $snapshot,
                $grossDelta->toDatabaseString(),
                $deductionDelta->toDatabaseString(),
                $netDelta->toDatabaseString(),
                null
            );

            $oldByComponent = [];
            foreach ($this->payroll->componentsForItem($originalItemId) as $component) {
                $oldByComponent[(int) $component['payroll_component_id']] = [
                    'minor' => Money::fromDecimalString((string) $component['amount'])->toMinorUnits(),
                    'direction' => (string) $component['direction'],
                ];
            }
            $currentByComponent = [];
            foreach ($currentComponents as $component) {
                $currentByComponent[(int) $component['component_id']] = [
                    'minor' => $component['amount']->toMinorUnits(),
                    'direction' => (string) $component['direction'],
                ];
            }
            $journalLines = [];
            foreach (array_unique(array_merge(array_keys($oldByComponent), array_keys($currentByComponent))) as $componentId) {
                $old = $oldByComponent[$componentId] ?? ['minor' => 0, 'direction' => $currentByComponent[$componentId]['direction']];
                $new = $currentByComponent[$componentId] ?? ['minor' => 0, 'direction' => $oldByComponent[$componentId]['direction']];
                if ($old['direction'] !== $new['direction']) {
                    throw new RuntimeException('A payroll component direction cannot change during settlement.');
                }
                $delta = SignedMoneyDelta::fromMinorUnits($new['minor'] - $old['minor']);
                if ($delta->isZero()) {
                    continue;
                }
                $this->payroll->addItemComponent($settlementItemId, (int) $componentId, $delta->toDatabaseString(), (string) $new['direction']);
                $mapping = $this->journals->resolveAccounts('payroll_component', ['payroll_component_id' => (int) $componentId]);
                $amount = Money::fromMinorUnits(abs($delta->toMinorUnits()));
                $zero = Money::zero();
                $accountId = $new['direction'] === 'earning' ? $mapping['debit_account_id'] : $mapping['credit_account_id'];
                $isDebit = ($new['direction'] === 'earning' && $delta->isPositive()) || ($new['direction'] === 'deduction' && $delta->isNegative());
                $journalLines[] = ['account_id' => $accountId, 'debit' => $isDebit ? $amount : $zero, 'credit' => $isDebit ? $zero : $amount, 'sub_ledger_ref_type' => 'staff', 'sub_ledger_ref_id' => $staffId];
            }
            $payable = $this->journals->resolveAccounts('payroll_run_item_posting');
            $netAmount = Money::fromMinorUnits(abs($netDelta->toMinorUnits()));
            $zero = Money::zero();
            $journalLines[] = ['account_id' => $payable['credit_account_id'], 'debit' => $netDelta->isNegative() ? $netAmount : $zero, 'credit' => $netDelta->isPositive() ? $netAmount : $zero, 'sub_ledger_ref_type' => 'staff', 'sub_ledger_ref_id' => $staffId];
            $batchId = $run['batch_id'] === null ? null : (string) $run['batch_id'];
            $transactionId = $this->posting->postPartyOperation(
                'staff', $staffId, 'STAFF_GLOBAL', 'payroll_settlement_item_posting', $settlementItemId, $requestId,
                [['bucket' => 'STAFF_PAYROLL_PAYABLE', 'delta' => $netDelta, 'description' => 'Retroactive payroll settlement']],
                'payroll_settlement_item_posting', date('Y-m-d'), $journalLines, $postedBy, $batchId, $requestId
            );
            $this->payroll->linkItemPosting($settlementItemId, $transactionId);
            return $settlementItemId;
        });
    }
}
