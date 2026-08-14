<?php

declare(strict_types=1);

namespace EduCore\Modules\Finance\Application;

use EduCore\Modules\AcademicStructure\Contracts\AcademicYearQuery;
use EduCore\Modules\Finance\Contracts\FinanceTransactionManager;
use EduCore\Modules\Finance\Contracts\LegacyFinanceSource;
use EduCore\Modules\Finance\Contracts\Repositories\ChargeRepository;
use EduCore\Modules\Finance\Contracts\Repositories\ReceiptRepository;
use EduCore\Modules\Finance\Contracts\Repositories\StudentFinanceAccountRepository;
use EduCore\Modules\Finance\Contracts\Repositories\SubledgerAccountRepository;
use EduCore\Modules\Finance\Domain\Money;
use EduCore\Modules\Finance\Domain\SignedMoneyDelta;
use RuntimeException;

final class LegacyFinanceMigrationService
{
    public function __construct(
        private LegacyFinanceSource $legacy,
        private AcademicYearQuery $academicYears,
        private SubledgerAccountRepository $subledgerAccounts,
        private StudentFinanceAccountRepository $studentAccounts,
        private ChargeRepository $chargeRepository,
        private ReceiptRepository $receiptRepository,
        private StudentChargeService $charges,
        private PaymentAllocationService $allocation,
        private ReceiptService $receipts,
        private ReconciliationService $reconciliation,
        private FinanceTransactionManager $transactions
    ) {
    }

    /** @return array{charges:int,receipts:int,prior_year_debts:int,idempotent:int,reconciled:int,mismatches:list<array<string,mixed>>} */
    public function migrate(int $chargeTypeId, int $cashboxId, int $actorId): array
    {
        if ($chargeTypeId <= 0 || $cashboxId <= 0 || $actorId <= 0) {
            throw new \InvalidArgumentException('Charge type, cashbox, and migration actor are required.');
        }

        $report = ['charges' => 0, 'receipts' => 0, 'prior_year_debts' => 0, 'idempotent' => 0, 'reconciled' => 0, 'mismatches' => []];
        return $this->transactions->transactional(function () use ($chargeTypeId, $cashboxId, $actorId, &$report): array {
            foreach ($this->legacy->studentFees() as $legacyFee) {
                $yearId = $this->academicYears->idByName(trim((string) $legacyFee['academic_year']));
                if ($yearId === null) {
                    throw new RuntimeException('Unknown academic year on legacy student fee ' . (int) $legacyFee['id']);
                }
                $studentId = (int) $legacyFee['student_id'];
                $subledger = $this->subledgerAccounts->findOrCreate('student', $studentId, (string) $yearId);
                $studentAccountId = $this->studentAccounts->findOrCreate($studentId, $yearId, (int) $subledger['id']);
                $gross = Money::fromDecimalString((string) $legacyFee['final_amount']);
                $chargeRequest = md5('legacy-student-fee:' . (int) $legacyFee['id']);
                $existingCharge = $this->chargeRepository->findByRequestId($studentAccountId, $chargeRequest);
                if ($gross->isZero()) {
                    if ($existingCharge !== null) {
                        throw new RuntimeException('A zero legacy fee unexpectedly has a migrated charge.');
                    }
                    $chargeId = null;
                } else {
                    $entryDate = $this->datePart((string) ($legacyFee['created_at'] ?? ''));
                    $chargeId = $this->charges->createCharge(
                        $studentAccountId,
                        $studentId,
                        $yearId,
                        $chargeTypeId,
                        $gross,
                        Money::zero(),
                        Money::zero(),
                        [['name' => 'Legacy annual balance', 'net_amount' => $gross, 'due_date' => null, 'display_order' => 1]],
                        $actorId,
                        $chargeRequest,
                        null,
                        'import',
                        $entryDate
                    );
                    $existingCharge === null ? ++$report['charges'] : ++$report['idempotent'];
                }

                foreach ($this->legacy->paymentsForStudentFee((int) $legacyFee['id']) as $legacyPayment) {
                    $amount = Money::fromDecimalString((string) $legacyPayment['amount']);
                    if ($amount->isZero()) {
                        throw new RuntimeException('Zero legacy payment ' . (int) $legacyPayment['id'] . ' cannot be migrated.');
                    }
                    $receiptRequest = md5('legacy-fee-payment:' . (int) $legacyPayment['id']);
                    if ($this->receiptRepository->findByIdempotencyKey($receiptRequest) !== null) {
                        ++$report['idempotent'];
                        continue;
                    }
                    $allocation = $chargeId === null
                        ? ['allocations' => [], 'overpayment' => $amount]
                        : $this->allocation->autoAllocateToOldest($studentId, $yearId, $chargeId, $amount, $receiptRequest, $actorId);
                    $this->receipts->postReceipt(
                        $studentAccountId,
                        $studentId,
                        $cashboxId,
                        $yearId,
                        $amount,
                        $this->paymentMethod((string) $legacyPayment['payment_method']),
                        $receiptRequest,
                        $allocation['allocations'],
                        $allocation['overpayment'],
                        $actorId,
                        $this->datePart((string) $legacyPayment['payment_date'])
                    );
                    ++$report['receipts'];
                }

                $actual = $this->reconciliation->studentBalances((int) $subledger['id']);
                $expectedMinor = SignedMoneyDelta::fromDecimalString((string) $legacyFee['balance'])->toMinorUnits();
                $actualMinor = SignedMoneyDelta::fromDecimalString($actual['net_account_position'])->toMinorUnits();
                if ($actualMinor === $expectedMinor) {
                    ++$report['reconciled'];
                } else {
                    $report['mismatches'][] = [
                        'legacy_student_fee_id' => (int) $legacyFee['id'],
                        'student_id' => $studentId,
                        'academic_year_id' => $yearId,
                        'legacy_balance' => SignedMoneyDelta::fromMinorUnits($expectedMinor)->toDatabaseString(),
                        'finance_balance' => SignedMoneyDelta::fromMinorUnits($actualMinor)->toDatabaseString(),
                    ];
                }
            }

            foreach ($this->legacy->priorYearBalances() as $history) {
                $amount = Money::fromDecimalString((string) $history['balance']);
                if ($amount->isZero()) {
                    continue;
                }
                $studentId = (int) $history['student_id'];
                $yearId = (int) $history['academic_year_id'];
                $subledger = $this->subledgerAccounts->findOrCreate('student', $studentId, (string) $yearId);
                $studentAccountId = $this->studentAccounts->findOrCreate($studentId, $yearId, (int) $subledger['id']);
                $requestId = md5('legacy-prior-year-balance:' . (int) $history['id']);
                $existing = $this->chargeRepository->findByRequestId($studentAccountId, $requestId);
                $this->charges->createCharge(
                    $studentAccountId,
                    $studentId,
                    $yearId,
                    $chargeTypeId,
                    $amount,
                    Money::zero(),
                    Money::zero(),
                    [['name' => 'Prior-year opening debt', 'net_amount' => $amount, 'due_date' => null, 'display_order' => 1]],
                    $actorId,
                    $requestId,
                    null,
                    'prior_year',
                    $this->datePart((string) ($history['created_at'] ?? ''))
                );
                $existing === null ? ++$report['prior_year_debts'] : ++$report['idempotent'];
            }

            if ($report['mismatches'] !== []) {
                throw new LegacyFinanceReconciliationException($report);
            }
            return $report;
        });
    }

    private function paymentMethod(string $legacy): string
    {
        return in_array($legacy, ['cash', 'bank_transfer', 'check', 'card', 'other'], true) ? $legacy : 'other';
    }

    private function datePart(string $value): string
    {
        $date = substr(trim($value), 0, 10);
        $parsed = \DateTimeImmutable::createFromFormat('!Y-m-d', $date);
        if ($parsed === false || $parsed->format('Y-m-d') !== $date) {
            throw new RuntimeException('Legacy financial row is missing a valid historical date.');
        }
        return $date;
    }
}

final class LegacyFinanceReconciliationException extends RuntimeException
{
    public function __construct(private array $report)
    {
        parent::__construct('Legacy finance reconciliation failed; the migration transaction was rolled back.');
    }

    public function report(): array
    {
        return $this->report;
    }
}
