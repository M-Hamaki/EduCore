<?php

declare(strict_types=1);

namespace EduCore\Modules\Finance\Application;

use EduCore\Modules\Finance\Contracts\FinanceTransactionManager;
use EduCore\Modules\Finance\Contracts\Repositories\CashboxRepository;
use EduCore\Modules\Finance\Contracts\Repositories\ChargeRepository;
use EduCore\Modules\Finance\Contracts\Repositories\PaymentAllocationRepository;
use EduCore\Modules\Finance\Contracts\Repositories\ReceiptRepository;
use EduCore\Modules\Finance\Contracts\Repositories\UnappliedCreditRepository;
use EduCore\Modules\Finance\Domain\Money;
use EduCore\Modules\Finance\Domain\SignedMoneyDelta;
use EduCore\Modules\Finance\Domain\FinanceAuthorization;
use InvalidArgumentException;
use RuntimeException;

final class ReceiptService
{
    public function __construct(
        private ReceiptRepository $receipts,
        private PaymentAllocationRepository $allocations,
        private UnappliedCreditRepository $credits,
        private ChargeRepository $charges,
        private CashboxRepository $cashboxes,
        private SubledgerPostingService $posting,
        private JournalEntryService $journals,
        private FinanceTransactionManager $transactions
    ) {
    }

    public function postReceipt(int $studentAccountId, int $studentId, int $cashboxId, int $academicYearId, Money $receiptAmount, string $paymentMethod, string $idempotencyKey, array $allocations, ?Money $overpayment, int $postedBy, ?string $entryDate = null, string $allocationMode = 'auto_oldest', ?string $allocationReason = null, ?string $notes = null): int
    {
        if (!preg_match('/^[a-f0-9]{32}$/i', $idempotencyKey) || !in_array($paymentMethod, ['cash', 'bank_transfer', 'check', 'card', 'other'], true) || !in_array($allocationMode, ['auto_oldest', 'manual'], true)) {
            throw new InvalidArgumentException('Invalid receipt idempotency key or payment method.');
        }
        $allocationReason = trim((string) $allocationReason);
        $notes = trim((string) $notes);
        if ($allocationMode === 'manual' && $allocationReason === '') {
            throw new InvalidArgumentException('Manual allocation requires a recorded reason.');
        }
        $existing = $this->receipts->findByIdempotencyKey($idempotencyKey);
        if ($existing !== null) {
            return (int) $existing['id'];
        }
        foreach ($allocations as $allocation) {
            if (!isset($allocation['installment_id'], $allocation['amount']) || !$allocation['amount'] instanceof Money) {
                throw new InvalidArgumentException('Every receipt allocation requires an installment and Money amount.');
            }
        }
        $overpayment = $overpayment ?? Money::zero();
        $entryDate = $entryDate ?? date('Y-m-d');

        return $this->transactions->transactional(function () use ($studentAccountId, $studentId, $cashboxId, $academicYearId, $receiptAmount, $paymentMethod, $idempotencyKey, $allocations, $overpayment, $postedBy, $entryDate, $allocationMode, $allocationReason, $notes): int {
            $cashbox = $this->cashboxes->lockById($cashboxId);
            if ($cashbox === null || !(bool) $cashbox['is_active']) {
                throw new RuntimeException('Active receipt cashbox not found.');
            }
            $existing = $this->receipts->findByIdempotencyKey($idempotencyKey);
            if ($existing !== null) {
                return (int) $existing['id'];
            }
            $allocatedTotal = Money::zero();
            $seenInstallments = [];
            foreach ($allocations as $allocation) {
                $installmentId = (int) $allocation['installment_id'];
                if (isset($seenInstallments[$installmentId])) {
                    throw new RuntimeException('A receipt cannot allocate the same installment more than once.');
                }
                $seenInstallments[$installmentId] = true;
                $remaining = Money::fromDecimalString(
                    $this->charges->lockInstallmentRemainingDue($installmentId)
                );
                if ($allocation['amount']->greaterThan($remaining)) {
                    throw new RuntimeException('Receipt allocation exceeds installment remaining due.');
                }
                $allocatedTotal = $allocatedTotal->add($allocation['amount']);
            }
            if (!$allocatedTotal->add($overpayment)->equals($receiptAmount)) {
                throw new RuntimeException('Receipt amount must equal allocations plus unapplied credit.');
            }
            $sequence = $this->receipts->allocateSequenceNumber($cashboxId, $academicYearId);
            $prefix = trim((string) ($cashbox['receipt_prefix'] ?? 'R')) ?: 'R';
            $receiptId = $this->receipts->create([
                'receipt_number' => $prefix . '-' . $academicYearId . '-' . str_pad((string) $sequence, 6, '0', STR_PAD_LEFT),
                'cashbox_id' => $cashboxId,
                'academic_year_id' => $academicYearId,
                'sequence_number' => $sequence,
                'student_account_id' => $studentAccountId,
                'payment_method' => $paymentMethod,
                'gross_amount' => $receiptAmount->toDatabaseString(),
                'idempotency_key' => $idempotencyKey,
                'notes' => trim(implode(' | ', array_filter([
                    $allocationMode === 'manual' ? 'manual_allocation: ' . $allocationReason : null,
                    $notes === '' ? null : $notes,
                ]))) ?: null,
                'created_by' => $postedBy,
            ]);

            $subledgerLines = [];
            foreach ($allocations as $allocation) {
                $subledgerLines[] = ['bucket' => 'STUDENT_OUTSTANDING_DUE', 'delta' => SignedMoneyDelta::fromMinorUnits(-$allocation['amount']->toMinorUnits()), 'installment_id' => (int) $allocation['installment_id'], 'description' => 'Receipt allocation'];
            }
            if (!$overpayment->isZero()) {
                $subledgerLines[] = ['bucket' => 'STUDENT_UNAPPLIED_CREDIT', 'delta' => SignedMoneyDelta::fromMinorUnits($overpayment->toMinorUnits()), 'description' => 'Receipt overpayment'];
            }

            $receiptMapping = $this->journals->resolveAccounts('receipt', ['payment_method' => $paymentMethod, 'cashbox_id' => $cashboxId]);
            $zero = Money::zero();
            $journalLines = [['account_id' => $receiptMapping['debit_account_id'], 'debit' => $receiptAmount, 'credit' => $zero, 'sub_ledger_ref_type' => 'student', 'sub_ledger_ref_id' => $studentId]];
            if (!$allocatedTotal->isZero()) {
                $journalLines[] = ['account_id' => $receiptMapping['credit_account_id'], 'debit' => $zero, 'credit' => $allocatedTotal, 'sub_ledger_ref_type' => 'student', 'sub_ledger_ref_id' => $studentId];
            }
            if (!$overpayment->isZero()) {
                $creditMapping = $this->journals->resolveAccounts('unapplied_credit', ['payment_method' => $paymentMethod, 'cashbox_id' => $cashboxId]);
                $journalLines[] = ['account_id' => $creditMapping['credit_account_id'], 'debit' => $zero, 'credit' => $overpayment, 'sub_ledger_ref_type' => 'student', 'sub_ledger_ref_id' => $studentId];
            }

            $subledgerTransactionId = $this->posting->postPartyOperation('student', $studentId, (string) $academicYearId, 'receipt', $receiptId, $idempotencyKey, $subledgerLines, 'receipt', $entryDate, $journalLines, $postedBy, null, $idempotencyKey);
            foreach ($allocations as $allocation) {
                $this->allocations->create($receiptId, (int) $allocation['installment_id'], $allocation['amount']->toDatabaseString(), $subledgerTransactionId, $idempotencyKey);
            }
            if (!$overpayment->isZero()) {
                $this->credits->create($studentAccountId, $receiptId, $overpayment->toDatabaseString(), $subledgerTransactionId, $idempotencyKey);
            }
            $this->receipts->post($receiptId, $subledgerTransactionId, $postedBy);
            return $receiptId;
        });
    }

    public function reverseReceipt(int $receiptId, int $studentId, int $reversedBy, int $approvedBy, ?string $requestId = null, ?string $entryDate = null): int
    {
        FinanceAuthorization::assertMakerChecker('receipt_reverse', $reversedBy, $approvedBy);
        $requestId = $requestId ?? bin2hex(random_bytes(16));
        $entryDate = $entryDate ?? date('Y-m-d');
        if (!preg_match('/^[a-f0-9]{32}$/i', $requestId)) {
            throw new InvalidArgumentException('Invalid receipt reversal request id.');
        }
        $requestMatch = $this->receipts->findByIdempotencyKey($requestId);
        if ($requestMatch !== null) {
            if ((int) ($requestMatch['reversal_of'] ?? 0) !== $receiptId) {
                throw new RuntimeException('Receipt reversal request id belongs to another operation.');
            }
            return (int) $requestMatch['id'];
        }

        return $this->transactions->transactional(function () use ($receiptId, $studentId, $reversedBy, $approvedBy, $requestId, $entryDate): int {
            $original = $this->receipts->lockById($receiptId);
            if ($original === null || (string) $original['status'] !== 'posted' || $original['reversal_of'] !== null) {
                throw new RuntimeException('Only an original posted receipt can be reversed.');
            }
            $existing = $this->receipts->findByReversalOf($receiptId);
            if ($existing !== null) {
                return (int) $existing['id'];
            }
            if ($this->receipts->hasDependentActivity($receiptId)) {
                throw new RuntimeException('Reverse dependent refunds or credit applications before reversing this receipt.');
            }
            $originalTransactionId = (int) ($original['subledger_transaction_id'] ?? 0);
            if ($originalTransactionId <= 0) {
                throw new RuntimeException('Receipt is missing its posted sub-ledger transaction.');
            }
            $cashboxId = (int) $original['cashbox_id'];
            $academicYearId = (int) $original['academic_year_id'];
            $cashbox = $this->cashboxes->findById($cashboxId);
            if ($cashbox === null) {
                throw new RuntimeException('Receipt cashbox not found.');
            }
            $sequence = $this->receipts->allocateSequenceNumber($cashboxId, $academicYearId);
            $prefix = trim((string) ($cashbox['receipt_prefix'] ?? 'R')) ?: 'R';
            $reversalReceiptId = $this->receipts->create([
                'receipt_number' => $prefix . '-' . $academicYearId . '-' . str_pad((string) $sequence, 6, '0', STR_PAD_LEFT),
                'cashbox_id' => $cashboxId,
                'academic_year_id' => $academicYearId,
                'sequence_number' => $sequence,
                'student_account_id' => (int) $original['student_account_id'],
                'payment_method' => (string) $original['payment_method'],
                'gross_amount' => (string) $original['gross_amount'],
                'idempotency_key' => $requestId,
                'request_id' => $requestId,
                'reversal_of' => $receiptId,
                'approved_by' => $approvedBy,
                'created_by' => $reversedBy,
            ]);
            $reversalTransactionId = $this->posting->postReversal(
                $originalTransactionId,
                $requestId,
                $entryDate,
                $this->journals->reversalLinesForPartyOperation($originalTransactionId),
                $reversedBy,
                null,
                $requestId
            );
            foreach ($this->allocations->findForReceipt($receiptId) as $allocation) {
                $this->allocations->createReversal((int) $allocation['id'], $reversalReceiptId, $reversalTransactionId, $requestId);
            }
            foreach ($this->credits->findForReceipt($receiptId) as $credit) {
                $this->credits->createReversal((int) $credit['id'], $reversalReceiptId, $reversalTransactionId, $requestId);
            }
            $this->receipts->post($reversalReceiptId, $reversalTransactionId, $reversedBy);
            return $reversalReceiptId;
        });
    }
}
