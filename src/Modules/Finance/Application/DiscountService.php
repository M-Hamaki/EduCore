<?php

declare(strict_types=1);

namespace EduCore\Modules\Finance\Application;

use EduCore\Modules\Finance\Contracts\FinanceTransactionManager;
use EduCore\Modules\Finance\Contracts\Repositories\AdjustmentRepository;
use EduCore\Modules\Finance\Contracts\Repositories\ChargeRepository;
use EduCore\Modules\Finance\Contracts\Repositories\DiscountApplicationRepository;
use EduCore\Modules\Finance\Contracts\Repositories\DiscountAwardRepository;
use EduCore\Modules\Finance\Contracts\Repositories\DiscountRuleRepository;
use EduCore\Modules\Finance\Contracts\Repositories\StudentFinanceAccountRepository;
use EduCore\Modules\Finance\Domain\FinanceAuthorization;
use EduCore\Modules\Finance\Domain\Money;
use EduCore\Modules\Finance\Domain\SignedMoneyDelta;
use EduCore\Modules\Finance\Domain\Policy\DiscountCombinationPolicy;
use EduCore\Modules\Operations\Audit\AuditEventWriter;
use InvalidArgumentException;
use RuntimeException;

final class DiscountService
{
    public function __construct(
        private DiscountRuleRepository $rules,
        private DiscountAwardRepository $awards,
        private DiscountApplicationRepository $applications,
        private ChargeRepository $charges,
        private StudentFinanceAccountRepository $studentAccounts,
        private DiscountCombinationPolicy $combinationPolicy,
        private FinanceTransactionManager $transactions,
        private AuditEventWriter $audit,
        private ?AdjustmentRepository $adjustments = null,
        private ?SubledgerPostingService $posting = null,
        private ?JournalEntryService $journals = null
    ) {
    }

    public function createRuleVersion(
        string $code,
        int $academicYearId,
        string $scopeChargeTypeKey,
        string $nameAr,
        int $priority,
        bool $combinable,
        ?Money $capAmount,
        string $effectiveFrom,
        int $createdBy,
        ?string $effectiveTo = null,
        string $calculationType = 'manual_amount',
        ?string $calculationValue = null,
        ?array $parameters = null
    ): int
    {
        $code = strtolower(trim($code));
        $scopeChargeTypeKey = trim($scopeChargeTypeKey);
        $nameAr = trim($nameAr);
        if (!in_array($code, ['sibling', 'employee_child', 'scholarship', 'hardship', 'manual', 'exemption', 'promotional'], true)) {
            throw new InvalidArgumentException('Unsupported discount rule code.');
        }
        if ($academicYearId <= 0 || $scopeChargeTypeKey === '' || $nameAr === '') {
            throw new InvalidArgumentException('Academic year, scope, and name are required.');
        }
        if (!$this->isDate($effectiveFrom) || ($effectiveTo !== null && (!$this->isDate($effectiveTo) || $effectiveTo < $effectiveFrom))) {
            throw new InvalidArgumentException('Discount effective date range is invalid.');
        }
        if ($combinable && ($capAmount === null || $capAmount->isZero())) {
            throw new InvalidArgumentException('A combinable discount rule requires an explicit cap.');
        }
        if (!in_array($calculationType, ['manual_amount', 'fixed_amount', 'percentage', 'sibling_tiers'], true)) {
            throw new InvalidArgumentException('Unsupported discount calculation type.');
        }
        if (in_array($calculationType, ['fixed_amount', 'percentage'], true)) {
            if ($calculationValue === null || preg_match('/^\d+(?:\.\d{1,2})?$/', $calculationValue) !== 1) {
                throw new InvalidArgumentException('Calculated discount requires a non-negative decimal value.');
            }
            if ($calculationType === 'percentage' && Money::fromDecimalString($calculationValue)->toMinorUnits() > 10000) {
                throw new InvalidArgumentException('Discount percentage cannot exceed 100.');
            }
        }
        if ($calculationType === 'sibling_tiers') {
            $tiers = $parameters['tiers'] ?? null;
            if (!is_array($tiers) || $tiers === []) {
                throw new InvalidArgumentException('Sibling discount rules require percentage tiers.');
            }
            foreach ($tiers as $order => $percentage) {
                if ((int) $order <= 0 || preg_match('/^(?:100(?:\.0{1,2})?|\d{1,2}(?:\.\d{1,2})?)$/', (string) $percentage) !== 1) {
                    throw new InvalidArgumentException('Invalid sibling discount tier.');
                }
            }
        }
        return $this->transactions->transactional(function () use ($code, $academicYearId, $scopeChargeTypeKey, $nameAr, $priority, $combinable, $capAmount, $effectiveFrom, $effectiveTo, $createdBy, $calculationType, $calculationValue, $parameters): int {
            $id = $this->rules->createVersion([
                'code' => $code,
                'academic_year_id' => $academicYearId,
                'scope_charge_type_key' => $scopeChargeTypeKey,
                'name_ar' => $nameAr,
                'priority' => $priority,
                'combinable' => $combinable ? 1 : 0,
                'cap_amount' => $capAmount?->toDatabaseString(),
                'effective_from' => $effectiveFrom,
                'effective_to' => $effectiveTo,
                'calculation_type' => $calculationType,
                'calculation_value' => $calculationValue,
                'parameters_json' => $parameters === null ? null : json_encode($parameters, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR),
                'created_by' => $createdBy,
            ]);
            $this->audit->recordEvent('finance_discount_rule_create', 'finance_discount_rule', $id, $nameAr, ['code' => $code, 'academic_year_id' => $academicYearId]);
            return $id;
        });
    }

    public function activateRule(int $ruleId, int $activatedBy): void
    {
        $this->transactions->transactional(function () use ($ruleId, $activatedBy): void {
            $this->rules->activateRule($ruleId, $activatedBy);
            $this->audit->recordEvent('finance_discount_rule_activate', 'finance_discount_rule', $ruleId, null, ['activated_by' => $activatedBy]);
        });
    }

    public function archiveRule(int $ruleId, int $archivedBy): void
    {
        $this->transactions->transactional(function () use ($ruleId, $archivedBy): void {
            $rule = $this->rules->findRuleById($ruleId);
            if ($rule === null) {
                throw new RuntimeException('Discount rule does not exist.');
            }
            $this->rules->archiveRule($ruleId);
            $this->audit->recordEvent('finance_discount_rule_archive', 'finance_discount_rule', $ruleId, (string) $rule['name_ar'], ['archived_by' => $archivedBy]);
        });
    }

    public function createAward(int $studentAccountId, int $discountRuleId, Money $awardedAmount, string $reason, int $requestedBy, ?int $approvedBy): int
    {
        if ($awardedAmount->isZero() || trim($reason) === '') {
            throw new InvalidArgumentException('A positive award amount and reason are required.');
        }
        $rule = $this->rules->findRuleById($discountRuleId);
        $studentAccount = $this->studentAccounts->findById($studentAccountId);
        if ($rule === null || (string) $rule['status'] !== 'active' || $studentAccount === null) {
            throw new RuntimeException('An active discount rule and student account are required.');
        }
        if ((int) $rule['academic_year_id'] !== (int) $studentAccount['academic_year_id']) {
            throw new RuntimeException('Discount rule and student account must belong to the same academic year.');
        }
        $sensitive = in_array((string) $rule['code'], ['manual', 'hardship', 'scholarship', 'exemption'], true);
        if ($sensitive && $approvedBy !== null) {
            FinanceAuthorization::assertMakerChecker('discount_approve', $requestedBy, $approvedBy);
        }
        return $this->transactions->transactional(function () use ($studentAccountId, $discountRuleId, $awardedAmount, $reason, $requestedBy, $approvedBy): int {
            $id = $this->awards->createAward($studentAccountId, $discountRuleId, $awardedAmount->toDatabaseString(), trim($reason), $requestedBy, $approvedBy);
            $this->audit->recordEvent('finance_discount_award_create', 'finance_discount_award', $id, null, ['student_account_id' => $studentAccountId, 'amount' => $awardedAmount->toDatabaseString(), 'requested_by' => $requestedBy, 'approved_by' => $approvedBy]);
            return $id;
        });
    }

    public function approveAward(int $awardId, int $approvedBy): void
    {
        $this->transactions->transactional(function () use ($awardId, $approvedBy): void {
            $award = $this->awards->lockById($awardId);
            if ($award === null || (string) $award['status'] !== 'pending') {
                throw new RuntimeException('A pending discount award is required.');
            }
            FinanceAuthorization::assertMakerChecker('discount_approve', (int) $award['requested_by'], $approvedBy);
            $this->awards->approve($awardId, $approvedBy);
            $this->audit->recordEvent('finance_discount_award_approve', 'finance_discount_award', $awardId, null, ['approved_by' => $approvedBy]);
        });
    }

    public function applyDiscount(
        int $awardId,
        int $chargeId,
        ?int $installmentId,
        Money $appliedAmount,
        ?string $requestId = null,
        ?string $entryDate = null
    ): int
    {
        if ($appliedAmount->isZero()) {
            throw new InvalidArgumentException('Applied discount amount must be positive.');
        }
        $requestId = $requestId ?? bin2hex(random_bytes(16));
        if (preg_match('/^[a-f0-9]{32}$/i', $requestId) !== 1) {
            $requestId = md5($requestId);
        }
        $entryDate = $entryDate ?? date('Y-m-d');
        if (!$this->isDate($entryDate)) {
            throw new InvalidArgumentException('Discount entry date is invalid.');
        }
        $requestMatch = $this->applications->findByRequestId($requestId);
        if ($requestMatch !== null) {
            return (int) $requestMatch['id'];
        }

        return $this->transactions->transactional(function () use ($awardId, $chargeId, $installmentId, $appliedAmount, $requestId, $entryDate): int {
            $award = $this->awards->lockById($awardId);
            $charge = $this->charges->lockById($chargeId);
            $requestMatch = $this->applications->findByRequestId($requestId);
            if ($requestMatch !== null) {
                return (int) $requestMatch['id'];
            }
            if ($award === null || (string) $award['status'] !== 'approved' || $charge === null || (string) $charge['status'] !== 'posted') {
                throw new RuntimeException('Approved discount award and posted charge are required.');
            }
            if ((int) $award['student_account_id'] !== (int) $charge['student_account_id']) {
                throw new RuntimeException('Discount award and charge must belong to the same student account.');
            }
            if ($installmentId !== null && $this->charges->findInstallmentForCharge($installmentId, $chargeId) === null) {
                throw new RuntimeException('Discount installment does not belong to the charge.');
            }
            $awardTotal = Money::fromDecimalString($this->applications->sumForAward($awardId))->add($appliedAmount);
            if ($awardTotal->greaterThan(Money::fromDecimalString((string) $award['awarded_amount']))) {
                throw new RuntimeException('Discount application exceeds the approved award.');
            }

            $alreadyApplied = Money::fromDecimalString($this->applications->sumForCharge($chargeId));
            $prepostedDiscountMinor = max(
                0,
                Money::fromDecimalString((string) $charge['discount_amount'])->toMinorUnits()
                    - $alreadyApplied->toMinorUnits()
            );
            $metadataMinor = min($prepostedDiscountMinor, $appliedAmount->toMinorUnits());
            $ledgerMinor = $appliedAmount->toMinorUnits() - $metadataMinor;
            $studentAccount = $this->studentAccounts->lockById((int) $award['student_account_id']);
            if ($studentAccount === null || (int) $studentAccount['academic_year_id'] !== (int) $charge['academic_year_id']) {
                throw new RuntimeException('Discount account and charge year do not match.');
            }

            $allocations = [];
            $subledgerTransactionId = null;
            $adjustmentId = null;
            if ($ledgerMinor > 0) {
                if ($this->adjustments === null || $this->posting === null || $this->journals === null) {
                    throw new RuntimeException('Posted discount accounting dependencies are not configured.');
                }
                if ($studentAccount['subledger_account_id'] === null) {
                    throw new RuntimeException('Student finance account is not connected to the unified sub-ledger.');
                }
                $outstanding = Money::fromDecimalString(
                    $this->posting->bucketBalance((int) $studentAccount['subledger_account_id'], 'STUDENT_OUTSTANDING_DUE')
                );
                if ($ledgerMinor > $outstanding->toMinorUnits()) {
                    throw new RuntimeException('Discount exceeds the locked outstanding student balance.');
                }

                $remainingMinor = $ledgerMinor;
                $installments = $installmentId === null
                    ? $this->charges->installmentsForCharge($chargeId)
                    : [['id' => $installmentId]];
                foreach ($installments as $installment) {
                    $targetInstallmentId = (int) $installment['id'];
                    $dueMinor = max(
                        0,
                        Money::fromDecimalString(
                            $this->charges->lockInstallmentRemainingDue($targetInstallmentId)
                        )->toMinorUnits()
                    );
                    $allocatedMinor = min($dueMinor, $remainingMinor);
                    if ($allocatedMinor > 0) {
                        $allocations[] = [
                            'installment_id' => $targetInstallmentId,
                            'minor' => $allocatedMinor,
                        ];
                        $remainingMinor -= $allocatedMinor;
                    }
                    if ($remainingMinor === 0) {
                        break;
                    }
                }
                if ($remainingMinor !== 0) {
                    throw new RuntimeException('Discount exceeds the remaining due for the selected charge.');
                }

                $mapping = $this->journals->resolveAccounts('student_discount');
                $ledgerAmount = Money::fromMinorUnits($ledgerMinor);
                $zero = Money::zero();
                $subledgerTransactionId = $this->posting->postPartyOperation(
                    'student',
                    (int) $studentAccount['student_id'],
                    (string) $studentAccount['academic_year_id'],
                    'discount_application',
                    $awardId,
                    $requestId,
                    array_map(static fn (array $allocation): array => [
                        'bucket' => 'STUDENT_OUTSTANDING_DUE',
                        'delta' => SignedMoneyDelta::fromMinorUnits(-$allocation['minor']),
                        'installment_id' => $allocation['installment_id'],
                        'description' => 'Approved student discount',
                    ], $allocations),
                    'student_discount',
                    $entryDate,
                    [
                        [
                            'account_id' => $mapping['debit_account_id'],
                            'debit' => $ledgerAmount,
                            'credit' => $zero,
                            'sub_ledger_ref_type' => 'student',
                            'sub_ledger_ref_id' => (int) $studentAccount['student_id'],
                        ],
                        [
                            'account_id' => $mapping['credit_account_id'],
                            'debit' => $zero,
                            'credit' => $ledgerAmount,
                            'sub_ledger_ref_type' => 'student',
                            'sub_ledger_ref_id' => (int) $studentAccount['student_id'],
                        ],
                    ],
                    (int) $award['requested_by'],
                    null,
                    $requestId
                );
                $adjustmentId = $this->adjustments->create([
                    'student_account_id' => (int) $studentAccount['id'],
                    'adjustment_type' => 'credit',
                    'signed_amount' => SignedMoneyDelta::fromMinorUnits(-$ledgerMinor)->toDatabaseString(),
                    'reason' => trim((string) ($award['reason'] ?? 'Approved student discount')),
                    'source' => 'credit_note',
                    'posted_by' => (int) $award['requested_by'],
                    'approved_by' => (int) $award['approved_by'],
                    'subledger_transaction_id' => $subledgerTransactionId,
                    'request_id' => $requestId,
                ]);
            }

            $applicationIds = [];
            if ($metadataMinor > 0 || $allocations === []) {
                $applicationIds[] = $this->applications->createApplication(
                    $awardId,
                    $chargeId,
                    $installmentId,
                    Money::fromMinorUnits($metadataMinor > 0 ? $metadataMinor : $appliedAmount->toMinorUnits())->toDatabaseString(),
                    '0.00',
                    null,
                    null,
                    $requestId
                );
            }
            foreach ($allocations as $allocation) {
                $applicationIds[] = $this->applications->createApplication(
                    $awardId,
                    $chargeId,
                    $allocation['installment_id'],
                    Money::fromMinorUnits($allocation['minor'])->toDatabaseString(),
                    Money::fromMinorUnits($allocation['minor'])->toDatabaseString(),
                    $adjustmentId,
                    $subledgerTransactionId,
                    $applicationIds === [] ? $requestId : null
                );
            }
            $firstId = (int) $applicationIds[0];
            $this->audit->recordEvent('finance_discount_apply', 'finance_discount_application', $firstId, null, [
                'application_ids' => $applicationIds,
                'award_id' => $awardId,
                'charge_id' => $chargeId,
                'amount' => $appliedAmount->toDatabaseString(),
                'ledger_effect_amount' => Money::fromMinorUnits($ledgerMinor)->toDatabaseString(),
                'adjustment_id' => $adjustmentId,
            ]);
            return $firstId;
        });
    }

    public function resolveCombination(array $candidates): array
    {
        return $this->combinationPolicy->resolve($candidates);
    }

    private function isDate(string $date): bool
    {
        $parsed = \DateTimeImmutable::createFromFormat('!Y-m-d', $date);
        return $parsed !== false && $parsed->format('Y-m-d') === $date;
    }
}
