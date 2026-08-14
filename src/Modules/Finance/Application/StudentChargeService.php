<?php

declare(strict_types=1);

namespace EduCore\Modules\Finance\Application;

use EduCore\Modules\Finance\Contracts\FinanceTransactionManager;
use EduCore\Modules\Finance\Contracts\Repositories\FeePlanRepository;
use EduCore\Modules\Finance\Contracts\Repositories\ChargeRepository;
use EduCore\Modules\Finance\Contracts\Repositories\StudentContractRepository;
use EduCore\Modules\Finance\Contracts\Repositories\StudentFinanceAccountRepository;
use EduCore\Modules\Finance\Contracts\Repositories\SubledgerAccountRepository;
use EduCore\Modules\Finance\Domain\Money;
use EduCore\Modules\Finance\Domain\SignedMoneyDelta;
use EduCore\Modules\Operations\Audit\AuditEventWriter;
use EduCore\Modules\Students\Contracts\StudentEnrollmentQuery;
use EduCore\Modules\Transport\Contracts\BusSubscriptionQuery;
use InvalidArgumentException;
use RuntimeException;

final class StudentChargeService
{
    public function __construct(
        private ChargeRepository $charges,
        private SubledgerPostingService $posting,
        private JournalEntryService $journals,
        private FinanceTransactionManager $transactions,
        private StudentFinanceAccountRepository $studentAccounts,
        private StudentContractRepository $studentContracts,
        private SubledgerAccountRepository $subledgerAccounts,
        private FeePlanRepository $feePlans,
        private StudentEnrollmentQuery $enrollments,
        private BusSubscriptionQuery $busSubscriptions,
        private AuditEventWriter $audit
    ) {
    }

    public function createCharge(int $studentAccountId, int $studentId, int $academicYearId, int $chargeTypeId, Money $grossAmount, Money $discountAmount, Money $adjustmentAmount, array $installments, int $postedBy, ?string $requestId = null, ?int $studentContractId = null, string $source = 'plan', ?string $entryDate = null): int
    {
        $netDue = $grossAmount->subtract($discountAmount)->subtract($adjustmentAmount);
        $installmentTotal = Money::zero();
        foreach ($installments as $installment) {
            if (!isset($installment['net_amount']) || !$installment['net_amount'] instanceof Money) {
                throw new InvalidArgumentException('Each charge installment requires a Money net amount.');
            }
            $installmentTotal = $installmentTotal->add($installment['net_amount']);
        }
        if (!$installmentTotal->equals($netDue)) {
            throw new RuntimeException('Charge installment total must equal the charge net due.');
        }
        $requestId = $requestId ?? bin2hex(random_bytes(16));
        if (!preg_match('/^[a-f0-9]{32}$/i', $requestId)) {
            throw new InvalidArgumentException('Charge request id must be a 32-character hexadecimal key.');
        }
        $entryDate = $entryDate ?? date('Y-m-d');
        $parsedEntryDate = \DateTimeImmutable::createFromFormat('!Y-m-d', $entryDate);
        if ($parsedEntryDate === false || $parsedEntryDate->format('Y-m-d') !== $entryDate) {
            throw new InvalidArgumentException('Charge entry date must use Y-m-d format.');
        }

        $existing = $this->charges->findByRequestId($studentAccountId, $requestId);
        if ($existing !== null) {
            if ((string) $existing['status'] !== 'posted') {
                throw new RuntimeException('An incomplete charge already uses this request id.');
            }

            return (int) $existing['id'];
        }

        return $this->transactions->transactional(function () use ($studentAccountId, $studentId, $academicYearId, $chargeTypeId, $grossAmount, $discountAmount, $adjustmentAmount, $netDue, $installments, $postedBy, $requestId, $studentContractId, $source, $entryDate): int {
            $chargeId = $this->charges->createCharge([
                'student_account_id' => $studentAccountId,
                'student_contract_id' => $studentContractId,
                'charge_type_id' => $chargeTypeId,
                'direction' => 'debit',
                'gross_amount' => $grossAmount->toDatabaseString(),
                'discount_amount' => $discountAmount->toDatabaseString(),
                'adjustment_amount' => $adjustmentAmount->toDatabaseString(),
                'net_due' => $netDue->toDatabaseString(),
                'source' => $source,
                'academic_year_id' => $academicYearId,
                'request_id' => $requestId,
            ]);
            foreach (array_values($installments) as $index => $installment) {
                $this->charges->addInstallment($chargeId, trim((string) ($installment['name'] ?? '')), $installment['net_amount']->toDatabaseString(), $installment['due_date'] ?? null, (int) ($installment['display_order'] ?? $index + 1));
            }

            $mapping = $this->journals->resolveAccounts('student_charge', ['charge_type_id' => $chargeTypeId]);
            $zero = Money::zero();
            $subledgerTransactionId = $this->posting->postPartyOperation(
                'student', $studentId, (string) $academicYearId, 'charge', $chargeId, $requestId,
                [['bucket' => 'STUDENT_OUTSTANDING_DUE', 'delta' => SignedMoneyDelta::fromMinorUnits($netDue->toMinorUnits()), 'description' => 'Student charge']],
                'student_charge', $entryDate,
                [
                    ['account_id' => $mapping['debit_account_id'], 'debit' => $netDue, 'credit' => $zero, 'sub_ledger_ref_type' => 'student', 'sub_ledger_ref_id' => $studentId],
                    ['account_id' => $mapping['credit_account_id'], 'debit' => $zero, 'credit' => $netDue, 'sub_ledger_ref_type' => 'student', 'sub_ledger_ref_id' => $studentId],
                ],
                $postedBy,
                null,
                $requestId
            );
            $this->charges->post($chargeId, $subledgerTransactionId, $postedBy);
            return $chargeId;
        });
    }

    public function createChargeFromActivePlan(int $studentId, int $academicYearId, int $chargeTypeId, int $postedBy, bool $requiresBusSubscription = false, ?string $requestId = null): int
    {
        $enrollment = $this->enrollments->enrollmentOf($studentId, $academicYearId);
        if ($enrollment === null || !in_array((string) $enrollment['enrollment_status'], ['active', 'enrolled'], true)) {
            throw new RuntimeException('Active student enrollment is required for plan charge generation.');
        }
        if ($requiresBusSubscription && $this->busSubscriptions->subscriptionOf($studentId, $academicYearId) === null) {
            throw new RuntimeException('An active bus subscription is required for a bus charge.');
        }
        $plan = $this->feePlans->findActivePlan($chargeTypeId, $academicYearId, (int) $enrollment['grade_id']);
        if ($plan === null) {
            throw new RuntimeException('No active fee plan matches the student enrollment.');
        }
        $version = $this->feePlans->findActiveVersion((int) $plan['id']);
        if ($version === null) {
            throw new RuntimeException('The matching fee plan has no active version.');
        }
        $planInstallments = $this->feePlans->installmentsForVersion((int) $version['id']);
        if ($planInstallments === []) {
            throw new RuntimeException('The active fee plan version has no installments.');
        }

        return $this->transactions->transactional(function () use ($studentId, $academicYearId, $chargeTypeId, $postedBy, $requestId, $version, $planInstallments): int {
            $subledger = $this->subledgerAccounts->findOrCreate('student', $studentId, (string) $academicYearId);
            $studentAccountId = $this->studentAccounts->findOrCreate($studentId, $academicYearId, (int) $subledger['id']);
            $snapshot = json_encode(['fee_plan_version' => $version, 'installments' => $planInstallments], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
            $contract = $this->studentContracts->findByAccountAndVersion($studentAccountId, (int) $version['id']);
            if ($contract === null) {
                $contractId = $this->studentContracts->create($studentAccountId, (int) $version['id'], $snapshot, $postedBy);
                $this->audit->recordEvent('finance_student_contract_create', 'finance_student_contract', $contractId, null, ['student_account_id' => $studentAccountId, 'fee_plan_version_id' => (int) $version['id']]);
            } else {
                $contractId = (int) $contract['id'];
            }
            $gross = Money::zero();
            $installments = [];
            foreach ($planInstallments as $installment) {
                $amount = Money::fromDecimalString((string) $installment['gross_amount']);
                $gross = $gross->add($amount);
                $installments[] = ['name' => (string) $installment['installment_name'], 'net_amount' => $amount, 'due_date' => $installment['due_date'], 'display_order' => (int) $installment['display_order']];
            }
            return $this->createCharge($studentAccountId, $studentId, $academicYearId, $chargeTypeId, $gross, Money::zero(), Money::zero(), $installments, $postedBy, $requestId, $contractId, 'plan');
        });
    }
}
