<?php

declare(strict_types=1);

namespace EduCore\Modules\Finance\Application;

use EduCore\Modules\Finance\Contracts\FinanceTransactionManager;
use EduCore\Modules\Finance\Contracts\Repositories\ChargeRepository;
use EduCore\Modules\Finance\Contracts\Repositories\UnappliedCreditApplicationRepository;
use EduCore\Modules\Finance\Contracts\Repositories\UnappliedCreditRepository;
use EduCore\Modules\Finance\Domain\Money;
use EduCore\Modules\Finance\Domain\SignedMoneyDelta;
use InvalidArgumentException;
use RuntimeException;

final class PaymentAllocationService
{
    public function __construct(
        private ChargeRepository $charges,
        private UnappliedCreditRepository $credits,
        private UnappliedCreditApplicationRepository $creditApplications,
        private SubledgerPostingService $posting,
        private JournalEntryService $journals,
        private FinanceTransactionManager $transactions
    ) {
    }

    public function autoAllocateToOldest(int $studentId, int $academicYearId, int $chargeId, Money $amount, string $idempotencyKey, int $postedBy): array
    {
        $remainingMinor = $amount->toMinorUnits();
        $allocations = [];
        foreach ($this->charges->installmentsForCharge($chargeId) as $installment) {
            if ($remainingMinor === 0) {
                break;
            }
            $dueMinor = Money::fromDecimalString($this->charges->installmentRemainingDue((int) $installment['id']))->toMinorUnits();
            if ($dueMinor <= 0) {
                continue;
            }
            $allocatedMinor = min($remainingMinor, $dueMinor);
            $allocations[] = ['installment_id' => (int) $installment['id'], 'amount' => Money::fromMinorUnits($allocatedMinor)];
            $remainingMinor -= $allocatedMinor;
        }
        return ['allocations' => $allocations, 'overpayment' => Money::fromMinorUnits($remainingMinor)];
    }

    public function autoAllocateAccountToOldest(int $studentAccountId, Money $amount): array
    {
        if ($studentAccountId <= 0 || $amount->isZero()) {
            throw new InvalidArgumentException('A student account and positive payment amount are required.');
        }
        $remainingMinor = $amount->toMinorUnits();
        $allocations = [];
        foreach ($this->charges->installmentsForAccount($studentAccountId) as $installment) {
            if ($remainingMinor === 0) {
                break;
            }
            $dueMinor = Money::fromDecimalString(
                $this->charges->installmentRemainingDue((int) $installment['id'])
            )->toMinorUnits();
            if ($dueMinor <= 0) {
                continue;
            }
            $allocatedMinor = min($remainingMinor, $dueMinor);
            $allocations[] = [
                'installment_id' => (int) $installment['id'],
                'amount' => Money::fromMinorUnits($allocatedMinor),
            ];
            $remainingMinor -= $allocatedMinor;
        }
        return [
            'allocations' => $allocations,
            'overpayment' => Money::fromMinorUnits($remainingMinor),
        ];
    }

    public function applyUnappliedCredit(int $creditId, int $installmentId, Money $amount, int $studentId, int $academicYearId, int $postedBy, ?string $requestId = null): int
    {
        $requestId = $requestId ?? bin2hex(random_bytes(16));
        if (!preg_match('/^[a-f0-9]{32}$/i', $requestId) || $amount->isZero()) {
            throw new InvalidArgumentException('Invalid unapplied-credit application request.');
        }
        return $this->transactions->transactional(function () use ($creditId, $installmentId, $amount, $studentId, $academicYearId, $postedBy, $requestId): int {
            $existing = $this->creditApplications->findByRequestId($requestId);
            if ($existing !== null) {
                return (int) $existing['id'];
            }
            if ($amount->greaterThan(Money::fromDecimalString($this->credits->lockRemaining($creditId)))) {
                throw new RuntimeException('Applied amount exceeds locked remaining unapplied credit.');
            }
            if ($amount->greaterThan(Money::fromDecimalString($this->charges->lockInstallmentRemainingDue($installmentId)))) {
                throw new RuntimeException('Applied amount exceeds locked installment remaining due.');
            }
            $mapping = $this->journals->resolveAccounts('unapplied_credit_application');
            $zero = Money::zero();
            $subledgerTransactionId = $this->posting->postPartyOperation(
                'student', $studentId, (string) $academicYearId, 'unapplied_credit_application', $creditId, $requestId,
                [
                    ['bucket' => 'STUDENT_OUTSTANDING_DUE', 'delta' => SignedMoneyDelta::fromMinorUnits(-$amount->toMinorUnits()), 'installment_id' => $installmentId],
                    ['bucket' => 'STUDENT_UNAPPLIED_CREDIT', 'delta' => SignedMoneyDelta::fromMinorUnits(-$amount->toMinorUnits())],
                ],
                'unapplied_credit', date('Y-m-d'),
                [
                    ['account_id' => $mapping['debit_account_id'], 'debit' => $amount, 'credit' => $zero, 'sub_ledger_ref_type' => 'student', 'sub_ledger_ref_id' => $studentId],
                    ['account_id' => $mapping['credit_account_id'], 'debit' => $zero, 'credit' => $amount, 'sub_ledger_ref_type' => 'student', 'sub_ledger_ref_id' => $studentId],
                ],
                $postedBy, null, $requestId
            );
            return $this->creditApplications->createApplication($creditId, $installmentId, null, $amount->toDatabaseString(), $subledgerTransactionId, $requestId);
        });
    }
}
