<?php

declare(strict_types=1);

namespace EduCore\Modules\Finance\Application;

use EduCore\Modules\AcademicStructure\Contracts\AcademicYearQuery;
use EduCore\Modules\Finance\Contracts\Queries\LegacyFinanceReadQuery;
use EduCore\Modules\Finance\Contracts\Repositories\DiscountRuleRepository;
use EduCore\Modules\Finance\Contracts\Repositories\LegacyCompatibilityRepository;
use EduCore\Modules\Finance\Domain\Money;
use EduCore\Modules\Students\Contracts\StudentFinanceReadQuery;
use InvalidArgumentException;
use RuntimeException;

/**
 * Field-for-field compatibility boundary for fee_payments.php and its DataTable.
 *
 * Legacy URLs and JSON names remain stable, while every financial amount and
 * write comes from the unified Finance application services and sub-ledger.
 */
final class LegacyCollectionCompatibilityService
{
    public function __construct(
        private AcademicYearQuery $academicYears,
        private LegacyFinanceReadQuery $read,
        private StudentFinanceReadQuery $students,
        private StudentChargeService $charges,
        private PaymentAllocationService $allocations,
        private ReceiptService $receipts,
        private DiscountService $discounts,
        private DiscountRuleRepository $discountRules,
        private LegacyCompatibilityRepository $mappings,
        private FinanceApprovalWorkflowService $approvals,
        private LegacyFeeDefinitionService $feeDefinitions
    ) {
    }

    /** @return array<string,mixed> */
    public function studentFee(int $studentId, string $yearName): array
    {
        $yearId = $this->yearId($yearName);
        $student = $this->students->student($studentId, $yearId);
        if ($student === null) {
            throw new RuntimeException('الطالب غير موجود.');
        }
        $account = $this->read->studentAccount($studentId, $yearId);
        $tuitionTypeId = $this->requiredChargeType('tuition');
        $tuition = $this->read->activeStudentCharge($studentId, $yearId, $tuitionTypeId);
        $payments = $account === null ? [] : $this->read->studentReceipts((int) $account['id']);
        $discountRows = $account === null ? [] : $this->read->studentDiscounts((int) $account['id']);
        $totals = $account === null
            ? ['final_amount' => '0.00', 'total_paid' => '0.00']
            : $this->read->studentTotals((int) $account['id']);
        $installments = $tuition === null ? [] : array_map(static function (array $row): array {
            $row['amount'] = (string) $row['net_amount'];
            return $row;
        }, $this->read->chargeInstallments((int) $tuition['id']));

        $siblingDiscount = Money::zero();
        $otherDiscount = Money::zero();
        foreach ($discountRows as $discount) {
            $amount = Money::fromDecimalString((string) ($discount['applied_amount'] ?? '0'));
            if ((string) ($discount['code'] ?? '') === 'sibling') {
                $siblingDiscount = $siblingDiscount->add($amount);
            } else {
                $otherDiscount = $otherDiscount->add($amount);
            }
        }
        $priorBalances = $this->read->priorYearBalances($studentId, $yearId);
        $priorTotal = Money::zero();
        foreach ($priorBalances as &$prior) {
            if (empty($prior['year_name'])) {
                $year = $this->academicYears->yearOf((int) $prior['academic_year_id']);
                $prior['year_name'] = (string) ($year['name'] ?? $prior['academic_year_id']);
            }
            $priorTotal = $priorTotal->add(Money::fromDecimalString((string) $prior['balance']));
        }
        unset($prior);

        $fee = null;
        if ($account !== null && $tuition !== null) {
            $balance = Money::fromDecimalString((string) $account['net_account_position']);
            $fee = [
                'id' => (int) $tuition['id'],
                'student_id' => $studentId,
                'academic_year' => $yearName,
                'tuition_amount' => (string) $tuition['gross_amount'],
                'sibling_order' => 1,
                'sibling_discount' => $siblingDiscount->toDatabaseString(),
                'custom_discount' => '0.00',
                'custom_discount_reason' => null,
                'other_discount_total' => $otherDiscount->toDatabaseString(),
                'bus_fee_amount' => '0.00',
                'final_amount' => (string) $totals['final_amount'],
                'total_paid' => (string) $totals['total_paid'],
                'balance' => $balance->toDatabaseString(),
                'status' => $balance->isZero()
                    ? 'paid'
                    : (Money::fromDecimalString((string) $totals['total_paid'])->isZero() ? 'unpaid' : 'partial'),
            ];
        }

        return [
            'success' => true,
            'student' => $student,
            'fee' => $fee,
            'payments' => $payments,
            'siblings' => $this->students->siblings($studentId, $yearId),
            'installments' => $installments,
            'other_discounts' => array_values(array_filter(
                $discountRows,
                static fn (array $row): bool => (string) ($row['code'] ?? '') !== 'sibling'
            )),
            'prior_balances' => $priorBalances,
            'prior_total' => $priorTotal->toDatabaseString(),
        ];
    }

    /** @return array<string,mixed> */
    public function recordPayment(array $input, int $actorId): array
    {
        $studentId = (int) ($input['student_id'] ?? 0);
        $yearName = trim((string) ($input['year'] ?? ''));
        $yearId = $this->yearId($yearName);
        $amount = Money::fromDecimalString($this->decimal($input['amount'] ?? null, 'قيمة الدفعة'));
        if ($studentId <= 0 || $amount->isZero()) {
            throw new InvalidArgumentException('بيانات الدفعة غير صحيحة.');
        }
        $paymentMethod = (string) ($input['payment_method'] ?? 'cash');
        if (!in_array($paymentMethod, ['cash', 'bank_transfer', 'check', 'card', 'other'], true)) {
            $paymentMethod = 'cash';
        }
        $entryDate = (string) ($input['payment_date'] ?? date('Y-m-d'));
        $account = $this->read->studentAccount($studentId, $yearId);
        if ($account === null) {
            $this->charges->createChargeFromActivePlan(
                $studentId,
                $yearId,
                $this->requiredChargeType('tuition'),
                $actorId,
                false,
                md5('legacy-auto-charge:' . $studentId . ':' . $yearId)
            );
            $account = $this->read->studentAccount($studentId, $yearId);
        }
        if ($account === null) {
            throw new RuntimeException('تعذر إنشاء الحساب المالي للطالب.');
        }
        $cashboxId = $this->read->soleActiveCashboxId();
        if ($cashboxId === null) {
            throw new RuntimeException('يجب تهيئة خزينة نشطة واحدة للتحصيل من الواجهة القديمة.');
        }
        $allocation = $this->allocations->autoAllocateAccountToOldest((int) $account['id'], $amount);
        $receiptReference = trim((string) ($input['receipt_number'] ?? ''));
        $idempotencyKey = md5(implode('|', [
            'legacy-receipt',
            $studentId,
            $yearId,
            $entryDate,
            $amount->toDatabaseString(),
            $paymentMethod,
            $receiptReference,
        ]));
        $this->receipts->postReceipt(
            (int) $account['id'],
            $studentId,
            $cashboxId,
            $yearId,
            $amount,
            $paymentMethod,
            $idempotencyKey,
            $allocation['allocations'],
            $allocation['overpayment'],
            $actorId,
            $entryDate,
            'auto_oldest',
            null,
            trim(implode(' | ', array_filter([
                $receiptReference === '' ? null : 'legacy_receipt: ' . $receiptReference,
                trim((string) ($input['notes'] ?? '')) ?: null,
            ])))
        );
        $fresh = $this->read->studentAccount($studentId, $yearId);
        $totals = $this->read->studentTotals((int) $account['id']);
        return [
            'success' => true,
            'message' => 'تم تسجيل الدفعة بنجاح (' . $amount->toDatabaseString() . ' جنيه)',
            'total_paid' => (string) $totals['total_paid'],
            'balance' => (string) ($fresh['net_account_position'] ?? '0.00'),
        ];
    }

    /** @return array<string,mixed> */
    public function requestReceiptReversal(int $paymentId, int $actorId): array
    {
        $receipt = $this->read->receipt($paymentId)
            ?? $this->read->receiptByLegacyPaymentId($paymentId);
        if ($receipt === null || (string) $receipt['status'] !== 'posted') {
            throw new RuntimeException('الدفعة غير موجودة أو سبق عكسها.');
        }
        $requestId = $this->approvals->request(
            'receipt_reverse',
            [
                'receipt_id' => (int) $receipt['id'],
                'student_id' => (int) $receipt['student_id'],
                'entry_date' => date('Y-m-d'),
            ],
            $actorId,
            md5('legacy-receipt-reversal:' . (int) $receipt['id'])
        );
        return [
            'success' => true,
            'message' => 'تم إرسال طلب عكس الدفعة للاعتماد بدل حذفها.',
            'approval_request_id' => $requestId,
        ];
    }

    /** @return array<string,mixed> */
    public function generateFees(array $input, int $actorId): array
    {
        $yearId = $this->yearId(trim((string) ($input['year'] ?? '')));
        $studentIds = $this->students->studentIds(
            $yearId,
            $this->nullableInt($input['grade_id'] ?? null),
            $this->nullableInt($input['class_id'] ?? null),
            $this->nullableInt($input['student_id'] ?? null)
        );
        $chargeTypeId = $this->requiredChargeType('tuition');
        $generated = 0;
        $skipped = 0;
        foreach ($studentIds as $studentId) {
            if ($this->read->activeStudentCharge($studentId, $yearId, $chargeTypeId) !== null) {
                ++$skipped;
                continue;
            }
            $this->charges->createChargeFromActivePlan(
                $studentId,
                $yearId,
                $chargeTypeId,
                $actorId,
                false,
                md5('legacy-generate-fee:' . $yearId . ':' . $studentId)
            );
            ++$generated;
        }
        return [
            'success' => true,
            'message' => "تم توليد المستحقات: {$generated} طالب جديد، {$skipped} طالب موجود مسبقاً",
            'generated' => $generated,
            'skipped' => $skipped,
        ];
    }

    public function requestDiscount(int $studentId, int $legacyDiscountId, string $yearName, int $actorId): int
    {
        $yearId = $this->yearId($yearName);
        $assignmentKey = $studentId . ':' . $legacyDiscountId . ':' . $yearId;
        if ($this->mappings->findActive('student_other_discount', $assignmentKey) !== null) {
            throw new RuntimeException('هذا الخصم معيّن بالفعل لهذا الطالب.');
        }
        $mapping = $this->mappings->findActive('other_discount', 'legacy-id:' . $legacyDiscountId);
        if ($mapping === null) {
            $legacy = $this->read->legacyOtherDiscount($legacyDiscountId);
            if ($legacy === null || (string) ($legacy['status'] ?? 'active') !== 'active') {
                throw new RuntimeException('الخصم غير موجود أو معطّل.');
            }
            $this->feeDefinitions->saveOtherDiscount([
                'od_id' => $legacyDiscountId,
                'academic_year' => $legacy['academic_year'] ?? $yearName,
                'od_name' => $legacy['name'] ?? '',
                'discount_type' => $legacy['discount_type'] ?? 'amount',
                'discount_value' => $legacy['discount_value'] ?? '0',
            ], $actorId);
            $mapping = $this->mappings->findActive('other_discount', 'legacy-id:' . $legacyDiscountId);
        }
        if ($mapping === null) {
            throw new RuntimeException('تعذر ربط الخصم بالنظام المالي الجديد.');
        }
        $rule = $this->discountRules->findRuleById((int) $mapping['target_id']);
        if ($rule === null || (string) $rule['status'] !== 'active') {
            throw new RuntimeException('الخصم غير موجود أو معطّل.');
        }
        $chargeTypeId = $this->requiredChargeType('tuition');
        $charge = $this->read->activeStudentCharge($studentId, $yearId, $chargeTypeId);
        if ($charge === null) {
            $this->charges->createChargeFromActivePlan(
                $studentId,
                $yearId,
                $chargeTypeId,
                $actorId,
                false,
                md5('legacy-discount-charge:' . $studentId . ':' . $yearId)
            );
            $charge = $this->read->activeStudentCharge($studentId, $yearId, $chargeTypeId);
        }
        $account = $this->read->studentAccount($studentId, $yearId);
        if ($charge === null || $account === null) {
            throw new RuntimeException('تعذر العثور على مستحقات الطالب.');
        }
        $amount = $this->ruleAmount($rule, Money::fromDecimalString((string) $charge['gross_amount']));
        if ($amount->isZero()) {
            throw new RuntimeException('قيمة الخصم تساوي صفرًا.');
        }
        $awardId = $this->discounts->createAward(
            (int) $account['id'],
            (int) $rule['id'],
            $amount,
            'طلب خصم من واجهة المصروفات القديمة',
            $actorId,
            null
        );
        $this->mappings->storeVersion(
            'student_other_discount',
            $assignmentKey,
            'finance_discount_award',
            $awardId,
            $yearId,
            ['legacy_discount_id' => $legacyDiscountId, 'amount' => $amount->toDatabaseString()],
            $actorId
        );
        return $this->approvals->request(
            'discount_award_approve',
            [
                'award_id' => $awardId,
                'charge_id' => (int) $charge['id'],
                'installment_id' => null,
                'amount' => $amount->toDatabaseString(),
            ],
            $actorId,
            md5('legacy-discount-approval:' . $assignmentKey)
        );
    }

    /** @return array<string,mixed> */
    public function dataTable(array $request, string $yearName): array
    {
        $yearId = $this->yearId($yearName);
        $totalRequest = $request;
        $totalRequest['search'] = ['value' => ''];
        $total = count($this->students->matching($yearId, $totalRequest));
        $identityRows = $this->students->matching($yearId, $request);
        $statusFilter = $this->statuses($request['fee_status'] ?? null);
        $rows = [];
        foreach ($identityRows as $identity) {
            $account = $this->read->studentAccount((int) $identity['id'], $yearId);
            $totals = $account === null
                ? ['final_amount' => '0.00', 'total_paid' => '0.00']
                : $this->read->studentTotals((int) $account['id']);
            $balance = (string) ($account['net_account_position'] ?? '0.00');
            $status = $account === null
                ? 'unpaid'
                : (Money::fromDecimalString($balance)->toMinorUnits() <= 0
                    ? 'paid'
                    : (Money::fromDecimalString((string) $totals['total_paid'])->isZero() ? 'unpaid' : 'partial'));
            if ($statusFilter !== [] && !in_array($status, $statusFilter, true)) {
                continue;
            }
            $rows[] = $identity + [
                'has_fee' => $account !== null,
                'final_amount' => (string) $totals['final_amount'],
                'total_paid' => (string) $totals['total_paid'],
                'balance' => $balance,
                'fee_status' => $status,
            ];
        }
        $filtered = count($rows);
        $column = (int) ($request['order'][0]['column'] ?? 2);
        $direction = strtolower((string) ($request['order'][0]['dir'] ?? 'asc')) === 'desc' ? -1 : 1;
        $keys = [1 => 'student_code', 2 => 'name', 3 => 'class_name', 4 => 'final_amount', 5 => 'total_paid', 6 => 'balance'];
        $key = $keys[$column] ?? 'name';
        usort($rows, static function (array $left, array $right) use ($key, $direction): int {
            $numeric = in_array($key, ['final_amount', 'total_paid', 'balance'], true);
            $comparison = $numeric
                ? Money::fromDecimalString((string) $left[$key])->toMinorUnits() <=> Money::fromDecimalString((string) $right[$key])->toMinorUnits()
                : strnatcasecmp((string) ($left[$key] ?? ''), (string) ($right[$key] ?? ''));
            return $direction * $comparison;
        });
        $start = max(0, (int) ($request['start'] ?? 0));
        $wanted = (int) ($request['length'] ?? 50);
        $length = $wanted === -1 ? $filtered : min(500, max(10, $wanted));
        $page = array_slice($rows, $start, $length);
        return [
            'draw' => max(0, (int) ($request['draw'] ?? 0)),
            'recordsTotal' => $total,
            'recordsFiltered' => $filtered,
            'data' => array_map(
                fn (array $row, int $index): array => $this->presentStudentRow($row, $start + $index + 1),
                $page,
                array_keys($page)
            ),
        ];
    }

    /** @return list<string> */
    private function statuses(mixed $value): array
    {
        $values = is_array($value) ? $value : explode(',', trim((string) $value));
        return array_values(array_intersect(array_map('trim', $values), ['paid', 'partial', 'unpaid']));
    }

    /** @return list<string> */
    private function presentStudentRow(array $row, int $number): array
    {
        $escape = static fn (mixed $value): string => htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
        $hasFee = (bool) $row['has_fee'];
        $balance = Money::fromDecimalString((string) $row['balance']);
        $paid = Money::fromDecimalString((string) $row['total_paid']);
        $badge = !$hasFee
            ? 'bg-secondary">غير محدد'
            : ((string) $row['fee_status'] === 'paid'
                ? 'bg-success">مسدد'
                : ((string) $row['fee_status'] === 'partial' ? 'bg-warning text-dark">جزئي' : 'bg-danger">لم يسدد'));
        $nameJson = htmlspecialchars(
            (string) json_encode((string) $row['name'], JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT),
            ENT_QUOTES,
            'UTF-8'
        );
        $id = (int) $row['id'];
        $actions = '<button class="btn btn-action-pills btn-edit me-1" data-bs-toggle="tooltip" title="عرض التفاصيل" onclick="viewStudentFee(' . $id . ')"><i class="fas fa-eye"></i></button>'
            . '<button class="btn btn-action-pills btn-activate me-1" data-bs-toggle="tooltip" title="تسجيل دفعة" onclick="openPaymentModal(' . $id . ', ' . $nameJson . ')"><i class="fas fa-plus-circle"></i></button>'
            . '<button class="btn btn-action-pills btn-edit me-1" data-bs-toggle="tooltip" title="تعيين خصم" onclick="openDiscountModal(' . $id . ', ' . $nameJson . ')"><i class="fas fa-tags"></i></button>';
        if ($hasFee && !$paid->isZero()) {
            $actions .= '<button class="btn btn-action-pills btn-deactivate" data-bs-toggle="tooltip" title="طباعة إيصال" onclick="printReceipt(' . $id . ')"><i class="fas fa-print"></i></button>';
        }
        return [
            (string) $number,
            '<small class="text-muted">' . $escape($row['student_code'] ?? '-') . '</small>',
            '<strong>' . $escape($row['name']) . '</strong>',
            $escape($row['class_name'] ?? '-'),
            $hasFee ? $this->formatMoney(Money::fromDecimalString((string) $row['final_amount'])) : '<span class="text-muted">-</span>',
            '<span class="text-success">' . ($hasFee ? $this->formatMoney($paid) : '-') . '</span>',
            '<span class="' . ($hasFee && $balance->toMinorUnits() > 0 ? 'text-danger fw-bold' : '') . '">' . ($hasFee ? $this->formatMoney($balance) : '-') . '</span>',
            '<span class="badge ' . $badge . '</span>',
            '<span class="admin-table-actions">' . $actions . '</span>',
        ];
    }

    private function ruleAmount(array $rule, Money $base): Money
    {
        $value = Money::fromDecimalString((string) ($rule['calculation_value'] ?? '0'));
        if ((string) ($rule['calculation_type'] ?? '') !== 'percentage') {
            return $value;
        }
        return Money::fromMinorUnits(intdiv(
            ($base->toMinorUnits() * $value->toMinorUnits()) + 5000,
            10000
        ));
    }

    private function requiredChargeType(string $code): int
    {
        $id = $this->read->chargeTypeId($code);
        if ($id === null) {
            throw new RuntimeException('نوع الرسوم المطلوب غير مهيأ.');
        }
        return $id;
    }

    private function yearId(string $yearName): int
    {
        $yearName = trim($yearName);
        $id = $yearName === ''
            ? $this->academicYears->currentId()
            : $this->academicYears->idByName($yearName);
        if ($id === null) {
            throw new RuntimeException('العام الدراسي غير موجود.');
        }
        return $id;
    }

    private function nullableInt(mixed $value): ?int
    {
        $id = (int) $value;
        return $id > 0 ? $id : null;
    }

    private function decimal(mixed $value, string $label): string
    {
        $text = trim((string) $value);
        if (preg_match('/^\d+(?:\.\d{1,2})?$/', $text) !== 1) {
            throw new InvalidArgumentException($label . ' غير صحيحة.');
        }
        return Money::fromDecimalString($text)->toDatabaseString();
    }

    private function formatMoney(Money $money): string
    {
        $minor = $money->toMinorUnits();
        $sign = $minor < 0 ? '-' : '';
        $absolute = abs($minor);
        return $sign
            . number_format(intdiv($absolute, 100), 0, '.', ',')
            . '.'
            . str_pad((string) ($absolute % 100), 2, '0', STR_PAD_LEFT);
    }
}
