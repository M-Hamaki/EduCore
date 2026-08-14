<?php

declare(strict_types=1);

namespace EduCore\Modules\Finance\Application;

use EduCore\Modules\Finance\Contracts\FinanceTransactionManager;
use EduCore\Modules\Finance\Contracts\Repositories\AdjustmentRepository;
use EduCore\Modules\Finance\Contracts\Repositories\PaymentAllocationRepository;
use EduCore\Modules\Finance\Contracts\Repositories\ReceiptRepository;
use EduCore\Modules\Finance\Contracts\Repositories\RefundRepository;
use EduCore\Modules\Finance\Contracts\Repositories\StudentFinanceAccountRepository;
use EduCore\Modules\Finance\Contracts\Repositories\UnappliedCreditRepository;
use EduCore\Modules\Finance\Domain\FinanceAuthorization;
use EduCore\Modules\Finance\Domain\Money;
use EduCore\Modules\Finance\Domain\SignedMoneyDelta;
use RuntimeException;

final class UnappliedCreditService
{
    public function __construct(
        private UnappliedCreditRepository $credits,
        private PaymentAllocationRepository $allocations,
        private ReceiptRepository $receipts,
        private RefundRepository $refunds,
        private AdjustmentRepository $adjustments,
        private StudentFinanceAccountRepository $studentAccounts,
        private SubledgerPostingService $posting,
        private JournalEntryService $journals,
        private FinanceTransactionManager $transactions
    ) {
    }

    public function refundUnappliedCredit(int $creditId, int $studentId, int $academicYearId, Money $amount, int $postedBy, int $approvedBy, ?string $requestId = null): int
    {
        FinanceAuthorization::assertMakerChecker('refund_post', $postedBy, $approvedBy);
        $credit = $this->credits->findById($creditId);
        if ($credit === null || $amount->greaterThan(Money::fromDecimalString($this->credits->remaining($creditId)))) {
            throw new RuntimeException('Refund exceeds available unapplied credit.');
        }
        $receipt = $this->receipts->findById((int) $credit['receipt_id']);
        if ($receipt === null) {
            throw new RuntimeException('Source receipt was not found.');
        }
        return $this->postRefund('refund_unapplied_credit', (int) $receipt['id'], null, $creditId, $studentId, $academicYearId, $amount, (string) $receipt['payment_method'], $postedBy, $approvedBy, $requestId);
    }

    public function refundAllocation(int $allocationId, int $receiptId, int $studentId, int $academicYearId, Money $amount, int $postedBy, int $approvedBy, ?string $requestId = null): int
    {
        FinanceAuthorization::assertMakerChecker('refund_post', $postedBy, $approvedBy);
        $allocation = $this->allocations->findById($allocationId);
        $receipt = $this->receipts->findById($receiptId);
        if ($allocation === null || $receipt === null || (int) $allocation['receipt_id'] !== $receiptId) {
            throw new RuntimeException('Payment allocation and receipt do not match.');
        }
        $remaining = Money::fromDecimalString((string) $allocation['signed_amount'])->subtract(Money::fromDecimalString($this->refunds->sumForAllocation($allocationId)));
        if ($amount->greaterThan($remaining)) {
            throw new RuntimeException('Refund exceeds the refundable allocation amount.');
        }
        return $this->postRefund('refund_allocation', $receiptId, $allocationId, null, $studentId, $academicYearId, $amount, (string) $receipt['payment_method'], $postedBy, $approvedBy, $requestId);
    }

    private function postRefund(string $type, int $receiptId, ?int $allocationId, ?int $creditId, int $studentId, int $academicYearId, Money $amount, string $paymentMethod, int $postedBy, int $approvedBy, ?string $requestId): int
    {
        $requestId = $requestId ?? bin2hex(random_bytes(16));
        $existing = $this->refunds->findByRequestId($requestId);
        if ($existing !== null) {
            return (int) $existing['id'];
        }
        return $this->transactions->transactional(function () use ($type, $receiptId, $allocationId, $creditId, $studentId, $academicYearId, $amount, $paymentMethod, $postedBy, $approvedBy, $requestId): int {
            $existing = $this->refunds->findByRequestId($requestId);
            if ($existing !== null) {
                return (int) $existing['id'];
            }
            if ($allocationId !== null) {
                $allocation = $this->allocations->lockById($allocationId);
                if ($allocation === null || (int) $allocation['receipt_id'] !== $receiptId) {
                    throw new RuntimeException('Locked payment allocation and receipt do not match.');
                }
                $remaining = Money::fromDecimalString((string) $allocation['signed_amount'])->subtract(Money::fromDecimalString($this->refunds->sumForAllocation($allocationId)));
                if ($amount->greaterThan($remaining)) {
                    throw new RuntimeException('Refund exceeds the locked refundable allocation amount.');
                }
            } elseif ($creditId !== null && $amount->greaterThan(Money::fromDecimalString($this->credits->lockRemaining($creditId)))) {
                throw new RuntimeException('Refund exceeds the locked available unapplied credit.');
            }
            $mapping = $this->journals->resolveAccounts($type, ['payment_method' => $paymentMethod]);
            $zero = Money::zero();
            $bucket = $type === 'refund_allocation' ? 'STUDENT_OUTSTANDING_DUE' : 'STUDENT_UNAPPLIED_CREDIT';
            $delta = $type === 'refund_allocation' ? $amount->toMinorUnits() : -$amount->toMinorUnits();
            $subledgerTransactionId = $this->posting->postPartyOperation('student', $studentId, (string) $academicYearId, $type, $allocationId ?? $creditId, $requestId, [['bucket' => $bucket, 'delta' => SignedMoneyDelta::fromMinorUnits($delta), 'description' => $type]], 'refund', date('Y-m-d'), [
                ['account_id' => $mapping['debit_account_id'], 'debit' => $amount, 'credit' => $zero, 'sub_ledger_ref_type' => 'student', 'sub_ledger_ref_id' => $studentId],
                ['account_id' => $mapping['credit_account_id'], 'debit' => $zero, 'credit' => $amount, 'sub_ledger_ref_type' => 'student', 'sub_ledger_ref_id' => $studentId],
            ], $postedBy, null, $requestId);
            return $this->refunds->create(['receipt_id' => $receiptId, 'refund_type' => $type, 'payment_allocation_id' => $allocationId, 'unapplied_credit_id' => $creditId, 'signed_amount' => SignedMoneyDelta::fromMinorUnits(-$amount->toMinorUnits())->toDatabaseString(), 'payment_method' => $paymentMethod, 'posted_by' => $postedBy, 'approved_by' => $approvedBy, 'subledger_transaction_id' => $subledgerTransactionId, 'request_id' => $requestId]);
        });
    }

    public function writeOffDebt(int $studentAccountId, int $studentId, int $academicYearId, Money $amount, string $reason, int $postedBy, int $approvedBy, ?string $requestId = null): int
    {
        FinanceAuthorization::assertMakerChecker('debt_write_off', $postedBy, $approvedBy);
        if ($amount->isZero()) {
            throw new RuntimeException('Write-off amount must be positive.');
        }
        $requestId = $requestId ?? bin2hex(random_bytes(16));
        $existing = $this->adjustments->findByRequestId($requestId);
        if ($existing !== null) {
            return (int) $existing['id'];
        }
        return $this->transactions->transactional(function () use ($studentAccountId, $studentId, $academicYearId, $amount, $reason, $postedBy, $approvedBy, $requestId): int {
            $account = $this->studentAccounts->lockById($studentAccountId);
            $existing = $this->adjustments->findByRequestId($requestId);
            if ($existing !== null) {
                return (int) $existing['id'];
            }
            if ($account === null || $amount->greaterThan(Money::fromDecimalString($this->posting->bucketBalance((int) $account['subledger_account_id'], 'STUDENT_OUTSTANDING_DUE')))) {
                throw new RuntimeException('Write-off exceeds the locked outstanding student debt.');
            }
            $mapping = $this->journals->resolveAccounts('student_debt_write_off');
            $zero = Money::zero();
            $subledgerTransactionId = $this->posting->postPartyOperation('student', $studentId, (string) $academicYearId, 'adjustment', null, $requestId, [['bucket' => 'STUDENT_OUTSTANDING_DUE', 'delta' => SignedMoneyDelta::fromMinorUnits(-$amount->toMinorUnits()), 'description' => trim($reason)]], 'adjustment', date('Y-m-d'), [
                ['account_id' => $mapping['debit_account_id'], 'debit' => $amount, 'credit' => $zero, 'sub_ledger_ref_type' => 'student', 'sub_ledger_ref_id' => $studentId],
                ['account_id' => $mapping['credit_account_id'], 'debit' => $zero, 'credit' => $amount, 'sub_ledger_ref_type' => 'student', 'sub_ledger_ref_id' => $studentId],
            ], $postedBy, null, $requestId);
            return $this->adjustments->create(['student_account_id' => $studentAccountId, 'adjustment_type' => 'credit', 'signed_amount' => SignedMoneyDelta::fromMinorUnits(-$amount->toMinorUnits())->toDatabaseString(), 'reason' => trim($reason), 'source' => 'student_debt_write_off', 'posted_by' => $postedBy, 'approved_by' => $approvedBy, 'subledger_transaction_id' => $subledgerTransactionId, 'request_id' => $requestId]);
        });
    }

    public function reverseRefund(int $refundId, int $studentId, int $academicYearId, int $reversedBy, int $approvedBy, ?string $requestId = null, ?string $entryDate = null): int
    {
        FinanceAuthorization::assertMakerChecker('refund_reverse', $reversedBy, $approvedBy);
        $requestId = $requestId ?? bin2hex(random_bytes(16));
        $entryDate = $entryDate ?? date('Y-m-d');
        $requestMatch = $this->refunds->findByRequestId($requestId);
        if ($requestMatch !== null) {
            if ((int) ($requestMatch['reversal_of'] ?? 0) !== $refundId) {
                throw new RuntimeException('Refund reversal request id belongs to another operation.');
            }
            return (int) $requestMatch['id'];
        }
        return $this->transactions->transactional(function () use ($refundId, $studentId, $academicYearId, $reversedBy, $approvedBy, $requestId, $entryDate): int {
            $original = $this->refunds->lockById($refundId);
            if ($original === null || (string) $original['status'] !== 'posted' || $original['reversal_of'] !== null) {
                throw new RuntimeException('Only an original posted refund can be reversed.');
            }
            $existing = $this->refunds->findByReversalOf($refundId);
            if ($existing !== null) {
                return (int) $existing['id'];
            }
            $originalTransactionId = (int) ($original['subledger_transaction_id'] ?? 0);
            $reversalTransactionId = $this->posting->postReversal($originalTransactionId, $requestId, $entryDate, $this->journals->reversalLinesForPartyOperation($originalTransactionId), $reversedBy, null, $requestId);
            return $this->refunds->create([
                'receipt_id' => (int) $original['receipt_id'],
                'refund_type' => (string) $original['refund_type'],
                'payment_allocation_id' => $original['payment_allocation_id'] === null ? null : (int) $original['payment_allocation_id'],
                'unapplied_credit_id' => $original['unapplied_credit_id'] === null ? null : (int) $original['unapplied_credit_id'],
                'signed_amount' => SignedMoneyDelta::fromDecimalString((string) $original['signed_amount'])->negate()->toDatabaseString(),
                'payment_method' => (string) $original['payment_method'],
                'reason' => 'Reversal: ' . trim((string) ($original['reason'] ?? '')),
                'posted_by' => $reversedBy,
                'approved_by' => $approvedBy,
                'reversal_of' => $refundId,
                'subledger_transaction_id' => $reversalTransactionId,
                'request_id' => $requestId,
            ]);
        });
    }

    public function reverseWriteOff(int $adjustmentId, int $studentId, int $academicYearId, int $reversedBy, int $approvedBy, ?string $requestId = null, ?string $entryDate = null): int
    {
        FinanceAuthorization::assertMakerChecker('debt_write_off_reverse', $reversedBy, $approvedBy);
        $requestId = $requestId ?? bin2hex(random_bytes(16));
        $entryDate = $entryDate ?? date('Y-m-d');
        $requestMatch = $this->adjustments->findByRequestId($requestId);
        if ($requestMatch !== null) {
            if ((int) ($requestMatch['reversal_of'] ?? 0) !== $adjustmentId) {
                throw new RuntimeException('Adjustment reversal request id belongs to another operation.');
            }
            return (int) $requestMatch['id'];
        }
        return $this->transactions->transactional(function () use ($adjustmentId, $studentId, $academicYearId, $reversedBy, $approvedBy, $requestId, $entryDate): int {
            $original = $this->adjustments->lockById($adjustmentId);
            if ($original === null || (string) $original['status'] !== 'posted' || (string) $original['source'] !== 'student_debt_write_off' || $original['reversal_of'] !== null) {
                throw new RuntimeException('Only an original posted debt write-off can be reversed.');
            }
            $existing = $this->adjustments->findByReversalOf($adjustmentId);
            if ($existing !== null) {
                return (int) $existing['id'];
            }
            $originalTransactionId = (int) ($original['subledger_transaction_id'] ?? 0);
            $reversalTransactionId = $this->posting->postReversal($originalTransactionId, $requestId, $entryDate, $this->journals->reversalLinesForPartyOperation($originalTransactionId), $reversedBy, null, $requestId);
            return $this->adjustments->create([
                'student_account_id' => (int) $original['student_account_id'],
                'adjustment_type' => 'debit',
                'signed_amount' => SignedMoneyDelta::fromDecimalString((string) $original['signed_amount'])->negate()->toDatabaseString(),
                'reason' => 'Reversal: ' . trim((string) ($original['reason'] ?? '')),
                'source' => 'student_debt_write_off',
                'posted_by' => $reversedBy,
                'approved_by' => $approvedBy,
                'reversal_of' => $adjustmentId,
                'subledger_transaction_id' => $reversalTransactionId,
                'request_id' => $requestId,
            ]);
        });
    }
}
