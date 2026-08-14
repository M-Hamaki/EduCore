<?php

declare(strict_types=1);

namespace EduCore\Modules\Finance\Application;

use EduCore\Modules\Finance\Contracts\FinanceTransactionManager;
use EduCore\Modules\Finance\Contracts\Repositories\FinanceApprovalRequestRepository;
use EduCore\Modules\Finance\Domain\FinanceAuthorization;
use EduCore\Modules\Finance\Domain\Money;
use EduCore\Modules\Operations\Audit\AuditEventWriter;
use InvalidArgumentException;
use RuntimeException;

final class FinanceApprovalWorkflowService
{
    private const OPERATIONS = ['receipt_reverse', 'refund_unapplied_credit', 'refund_allocation', 'refund_reverse', 'debt_write_off', 'advance_write_off', 'voucher_post', 'voucher_reverse', 'import_post', 'import_reverse', 'period_close', 'period_reopen', 'manual_journal_post', 'manual_journal_reverse', 'payroll_approve', 'payroll_finalize', 'payroll_item_post', 'payroll_payment_post', 'payroll_payment_reverse', 'payroll_run_reverse', 'discount_award_approve'];

    public function __construct(
        private FinanceApprovalRequestRepository $requests,
        private FinanceTransactionManager $transactions,
        private AuditEventWriter $audit,
        private ReceiptService $receipts,
        private UnappliedCreditService $credits,
        private StaffAdvanceService $advances,
        private VoucherService $vouchers,
        private ImportService $imports,
        private FinancePeriodService $periods,
        private ManualJournalService $manualJournals,
        private PayrollRunService $payroll,
        private DiscountService $discounts
    ) {}

    public function request(string $operationType, array $payload, int $requestedBy, ?string $requestKey = null): int
    {
        if (!in_array($operationType, self::OPERATIONS, true) || $requestedBy <= 0) { throw new InvalidArgumentException('Unsupported finance approval request.'); }
        $requestKey = $requestKey ?? bin2hex(random_bytes(16));
        if (!preg_match('/^[a-f0-9]{32}$/i', $requestKey)) { throw new InvalidArgumentException('Invalid approval request key.'); }
        $existing = $this->requests->findByRequestKey($requestKey);
        if ($existing !== null) { return (int) $existing['id']; }
        return $this->transactions->transactional(function () use ($operationType, $payload, $requestedBy, $requestKey): int {
            $id = $this->requests->create($operationType, $this->normalizePayload($operationType, $payload), $requestedBy, $requestKey);
            $this->audit->recordEvent('finance_approval_requested', 'finance_approval_request', $id, $operationType, ['requested_by' => $requestedBy]);
            return $id;
        });
    }

    /** @return array{result_ref_type:string,result_ref_id:int} */
    public function approve(int $requestId, int $checkerId): array
    {
        return $this->transactions->transactional(function () use ($requestId, $checkerId): array {
            $request = $this->requests->lockById($requestId);
            if ($request === null || (string) $request['status'] !== 'pending') { throw new RuntimeException('A pending finance approval request is required.'); }
            $operation = (string) $request['operation_type'];
            $makerId = (int) $request['requested_by'];
            FinanceAuthorization::assertMakerChecker($this->authorizationOperation($operation), $makerId, $checkerId);
            $payload = json_decode((string) $request['payload_json'], true, 512, JSON_THROW_ON_ERROR);
            [$refType, $refId] = $this->execute($operation, $payload, $makerId, $checkerId);
            $this->requests->markApproved($requestId, $checkerId, $refType, $refId);
            $this->audit->recordEvent('finance_approval_approved', 'finance_approval_request', $requestId, $operation, ['requested_by' => $makerId, 'approved_by' => $checkerId, 'result_ref_type' => $refType, 'result_ref_id' => $refId]);
            return ['result_ref_type' => $refType, 'result_ref_id' => $refId];
        });
    }

    public function reject(int $requestId, int $checkerId, string $reason): void
    {
        if (trim($reason) === '') { throw new InvalidArgumentException('A rejection reason is required.'); }
        $this->transactions->transactional(function () use ($requestId, $checkerId, $reason): void {
            $request = $this->requests->lockById($requestId);
            if ($request === null || (string) $request['status'] !== 'pending') { throw new RuntimeException('A pending finance approval request is required.'); }
            FinanceAuthorization::assertMakerChecker($this->authorizationOperation((string) $request['operation_type']), (int) $request['requested_by'], $checkerId);
            $this->requests->markRejected($requestId, $checkerId, $reason);
            $this->audit->recordEvent('finance_approval_rejected', 'finance_approval_request', $requestId, (string) $request['operation_type'], ['requested_by' => (int) $request['requested_by'], 'rejected_by' => $checkerId, 'reason' => trim($reason)]);
        });
    }

    private function normalizePayload(string $operation, array $payload): array
    {
        $allowed = match ($operation) {
            'receipt_reverse' => ['receipt_id', 'student_id', 'entry_date'],
            'refund_unapplied_credit' => ['credit_id', 'student_id', 'academic_year_id', 'amount'],
            'refund_allocation' => ['allocation_id', 'receipt_id', 'student_id', 'academic_year_id', 'amount'],
            'refund_reverse' => ['refund_id', 'student_id', 'academic_year_id', 'entry_date'],
            'debt_write_off' => ['student_account_id', 'student_id', 'academic_year_id', 'amount', 'reason'],
            'advance_write_off' => ['advance_id', 'staff_id', 'amount', 'reason'],
            'voucher_post' => ['voucher_type', 'cashbox_id', 'source_cashbox_id', 'destination_cashbox_id', 'bank_account_id', 'amount', 'finance_period_id', 'entry_date', 'cost_center_id', 'description'],
            'voucher_reverse' => ['voucher_id', 'entry_date', 'reason'],
            'import_post' => ['batch_id'],
            'import_reverse' => ['batch_id'],
            'period_close' => ['period_id'],
            'period_reopen' => ['period_id', 'reason'],
            'manual_journal_post' => ['academic_year_id', 'finance_period_id', 'entry_date', 'lines', 'description', 'idempotency_key'],
            'manual_journal_reverse' => ['original_idempotency_key', 'entry_date', 'reason', 'idempotency_key'],
            'payroll_approve' => ['run_id'],
            'payroll_finalize' => ['run_id'],
            'payroll_item_post' => ['run_id', 'staff_id', 'calculation_date'],
            'payroll_payment_post' => ['item_id', 'staff_id', 'cashbox_id', 'amount', 'payment_method'],
            'payroll_payment_reverse' => ['payment_id', 'staff_id', 'reason'],
            'payroll_run_reverse' => ['run_id', 'reason'],
            'discount_award_approve' => ['award_id', 'charge_id', 'installment_id', 'amount'],
            default => throw new InvalidArgumentException('Unsupported approval operation.'),
        };
        return array_intersect_key($payload, array_flip($allowed));
    }

    private function authorizationOperation(string $operation): string
    {
        return match ($operation) {
            'refund_unapplied_credit', 'refund_allocation' => 'refund_post',
            'refund_reverse' => 'refund_reverse',
            'voucher_reverse' => 'voucher_post',
            'import_reverse' => 'import_post',
            'period_close' => 'period_close',
            'period_reopen' => 'period_reopen',
            'manual_journal_reverse' => 'manual_journal_post',
            'payroll_approve' => 'payroll_approve',
            'payroll_finalize', 'payroll_item_post', 'payroll_payment_post' => 'payroll_post',
            'payroll_payment_reverse', 'payroll_run_reverse' => 'payroll_reverse',
            'discount_award_approve' => 'discount_approve',
            default => $operation,
        };
    }

    /** @return array{0:string,1:int} */
    private function execute(string $operation, array $p, int $maker, int $checker): array
    {
        return match ($operation) {
            'receipt_reverse' => ['finance_receipt', $this->receipts->reverseReceipt((int) ($p['receipt_id'] ?? 0), (int) ($p['student_id'] ?? 0), $maker, $checker, null, ($p['entry_date'] ?? '') ?: null)],
            'refund_unapplied_credit' => ['finance_refund', $this->credits->refundUnappliedCredit((int) ($p['credit_id'] ?? 0), (int) ($p['student_id'] ?? 0), (int) ($p['academic_year_id'] ?? 0), Money::fromDecimalString((string) ($p['amount'] ?? '0')), $maker, $checker)],
            'refund_allocation' => ['finance_refund', $this->credits->refundAllocation((int) ($p['allocation_id'] ?? 0), (int) ($p['receipt_id'] ?? 0), (int) ($p['student_id'] ?? 0), (int) ($p['academic_year_id'] ?? 0), Money::fromDecimalString((string) ($p['amount'] ?? '0')), $maker, $checker)],
            'refund_reverse' => ['finance_refund', $this->credits->reverseRefund((int) ($p['refund_id'] ?? 0), (int) ($p['student_id'] ?? 0), (int) ($p['academic_year_id'] ?? 0), $maker, $checker, null, ($p['entry_date'] ?? '') ?: null)],
            'debt_write_off' => ['finance_adjustment', $this->credits->writeOffDebt((int) ($p['student_account_id'] ?? 0), (int) ($p['student_id'] ?? 0), (int) ($p['academic_year_id'] ?? 0), Money::fromDecimalString((string) ($p['amount'] ?? '0')), (string) ($p['reason'] ?? ''), $maker, $checker)],
            'advance_write_off' => ['staff_advance_movement', $this->advances->writeOffAdvance((int) ($p['advance_id'] ?? 0), (int) ($p['staff_id'] ?? 0), Money::fromDecimalString((string) ($p['amount'] ?? '0')), $maker, $checker, (string) ($p['reason'] ?? ''))],
            'voucher_post' => ['finance_voucher', $this->vouchers->postVoucher((string) ($p['voucher_type'] ?? ''), $this->nullableInt($p['cashbox_id'] ?? null), $this->nullableInt($p['source_cashbox_id'] ?? null), $this->nullableInt($p['destination_cashbox_id'] ?? null), $this->nullableInt($p['bank_account_id'] ?? null), Money::fromDecimalString((string) ($p['amount'] ?? '0')), $this->nullableInt($p['finance_period_id'] ?? null), (string) ($p['entry_date'] ?? ''), $this->nullableInt($p['cost_center_id'] ?? null), ($p['description'] ?? '') ?: null, $maker, $checker)],
            'voucher_reverse' => ['finance_voucher', $this->vouchers->reverseVoucher((int) ($p['voucher_id'] ?? 0), (string) ($p['entry_date'] ?? ''), (string) ($p['reason'] ?? ''), $maker, $checker)],
            'import_post' => ['finance_import_batch', $this->postImport((int) ($p['batch_id'] ?? 0), $maker, $checker)],
            'import_reverse' => ['finance_import_batch', $this->imports->reverseBatch((int) ($p['batch_id'] ?? 0), $maker, $checker)],
            'period_close' => ['finance_period', $this->closePeriod((int) ($p['period_id'] ?? 0), $maker, $checker)],
            'period_reopen' => ['finance_period', $this->reopenPeriod((int) ($p['period_id'] ?? 0), $maker, $checker, (string) ($p['reason'] ?? ''))],
            'manual_journal_post' => ['accounting_journal_entry', $this->manualJournals->post((int) ($p['academic_year_id'] ?? 0), (int) ($p['finance_period_id'] ?? 0), (string) ($p['entry_date'] ?? ''), (array) ($p['lines'] ?? []), (string) ($p['description'] ?? ''), $maker, $checker, (string) ($p['idempotency_key'] ?? ''))],
            'manual_journal_reverse' => ['accounting_journal_entry', $this->manualJournals->reverse((string) ($p['original_idempotency_key'] ?? ''), (string) ($p['entry_date'] ?? ''), (string) ($p['reason'] ?? ''), $maker, $checker, (string) ($p['idempotency_key'] ?? ''))],
            'payroll_approve' => ['payroll_run', $this->approvePayroll((int) ($p['run_id'] ?? 0), $checker)],
            'payroll_finalize' => ['payroll_run', $this->finalizePayroll((int) ($p['run_id'] ?? 0), $maker, $checker)],
            'payroll_item_post' => ['payroll_run_item', $this->payroll->postPayrollItem((int) ($p['run_id'] ?? 0), (int) ($p['staff_id'] ?? 0), (string) ($p['calculation_date'] ?? ''), $maker, $checker)],
            'payroll_payment_post' => ['payroll_payment', $this->payroll->postPayment((int) ($p['item_id'] ?? 0), (int) ($p['staff_id'] ?? 0), (int) ($p['cashbox_id'] ?? 0), Money::fromDecimalString((string) ($p['amount'] ?? '0')), (string) ($p['payment_method'] ?? 'cash'), $maker, $checker)],
            'payroll_payment_reverse' => ['payroll_payment', $this->payroll->reversePayment((int) ($p['payment_id'] ?? 0), (int) ($p['staff_id'] ?? 0), $maker, $checker, (string) ($p['reason'] ?? ''))],
            'payroll_run_reverse' => ['payroll_run', $this->payroll->reverseRun((int) ($p['run_id'] ?? 0), $maker, $checker, (string) ($p['reason'] ?? ''))],
            'discount_award_approve' => ['finance_discount_award', $this->approveDiscountAward(
                (int) ($p['award_id'] ?? 0),
                $checker,
                (int) ($p['charge_id'] ?? 0),
                $this->nullableInt($p['installment_id'] ?? null),
                (string) ($p['amount'] ?? '')
            )],
            default => throw new InvalidArgumentException('Unsupported approval operation.'),
        };
    }

    private function nullableInt(mixed $value): ?int { $number = (int) $value; return $number > 0 ? $number : null; }

    private function postImport(int $batchId, int $maker, int $checker): int
    {
        $this->imports->postBatch($batchId, $maker, $checker);
        return $batchId;
    }

    private function closePeriod(int $periodId, int $maker, int $checker): int
    {
        $this->periods->closePeriod($periodId, $maker, $checker);
        return $periodId;
    }

    private function reopenPeriod(int $periodId, int $maker, int $checker, string $reason): int
    {
        $this->periods->reopenPeriod($periodId, $maker, $checker, $reason);
        return $periodId;
    }

    private function approvePayroll(int $runId, int $checker): int
    {
        $this->payroll->approveRun($runId, $checker);
        return $runId;
    }

    private function finalizePayroll(int $runId, int $maker, int $checker): int
    {
        $this->payroll->finalizeRun($runId, $maker, $checker);
        return $runId;
    }

    private function approveDiscountAward(int $awardId, int $checker, int $chargeId = 0, ?int $installmentId = null, string $amount = ''): int
    {
        $this->discounts->approveAward($awardId, $checker);
        if ($chargeId > 0 && $amount !== '') {
            $this->discounts->applyDiscount($awardId, $chargeId, $installmentId, Money::fromDecimalString($amount));
        }
        return $awardId;
    }
}
