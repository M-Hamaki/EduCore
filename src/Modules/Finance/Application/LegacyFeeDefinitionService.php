<?php

declare(strict_types=1);

namespace EduCore\Modules\Finance\Application;

use EduCore\Modules\AcademicStructure\Contracts\AcademicYearQuery;
use EduCore\Modules\Finance\Contracts\FinanceTransactionManager;
use EduCore\Modules\Finance\Contracts\Queries\LegacyFinanceReadQuery;
use EduCore\Modules\Finance\Contracts\Repositories\BusFeeScheduleRepository;
use EduCore\Modules\Finance\Contracts\Repositories\DiscountRuleRepository;
use EduCore\Modules\Finance\Contracts\Repositories\LegacyCompatibilityRepository;
use EduCore\Modules\Finance\Domain\Money;
use EduCore\Modules\Finance\Domain\Policy\SiblingDiscountPolicy;
use EduCore\Modules\Operations\Audit\AuditEventWriter;
use InvalidArgumentException;
use RuntimeException;

/**
 * Field-for-field application adapter for fee_structure.php and fee_calculator.php.
 */
final class LegacyFeeDefinitionService
{
    public function __construct(
        private AcademicYearQuery $academicYears,
        private LegacyFinanceReadQuery $read,
        private FeePlanService $feePlans,
        private DiscountService $discounts,
        private DiscountRuleRepository $discountRules,
        private BusFeeScheduleService $busSchedules,
        private BusFeeScheduleRepository $busScheduleRepository,
        private LegacyCompatibilityRepository $mappings,
        private FinanceTransactionManager $transactions,
        private AuditEventWriter $audit,
        private SiblingDiscountPolicy $siblingPolicy
    ) {
    }

    /** @return array{plan_id:int,version_id:int,total:string} */
    public function saveFeeStructure(array $input, int $actorId): array
    {
        $gradeId = (int) ($input['grade_id'] ?? 0);
        $yearName = trim((string) ($input['academic_year'] ?? $input['year'] ?? ''));
        $yearId = $this->yearId($yearName);
        $names = (array) ($input['installment_name'] ?? []);
        $amounts = (array) ($input['installment_amount'] ?? []);
        $dates = (array) ($input['installment_due_date'] ?? []);
        if ($gradeId <= 0 || $names === [] || count($names) !== count($amounts)) {
            throw new InvalidArgumentException('بيانات هيكل الرسوم غير مكتملة.');
        }
        $installments = [];
        $total = Money::zero();
        foreach ($names as $index => $name) {
            $name = trim((string) $name);
            $amountText = $this->decimal($amounts[$index] ?? null, 'قيمة القسط');
            $amount = Money::fromDecimalString($amountText);
            if ($name === '' || $amount->isZero()) {
                throw new InvalidArgumentException('يجب إدخال اسم وقيمة موجبة لكل قسط.');
            }
            $dueDate = trim((string) ($dates[$index] ?? ''));
            if ($dueDate !== '' && preg_match('/^\d{4}-\d{2}-\d{2}$/', $dueDate) !== 1) {
                throw new InvalidArgumentException('تاريخ استحقاق القسط غير صحيح.');
            }
            $installments[] = [
                'name' => $name,
                'gross_amount' => $amount,
                'due_date' => $dueDate === '' ? null : $dueDate,
                'display_order' => $index + 1,
            ];
            $total = $total->add($amount);
        }
        $chargeTypeId = $this->requiredChargeType('tuition');
        $sourceKey = 'grade:' . $gradeId . ':year:' . $yearId;
        $effectiveFrom = $this->effectiveFrom($installments);

        return $this->transactions->transactional(function () use ($chargeTypeId, $yearId, $yearName, $gradeId, $sourceKey, $effectiveFrom, $installments, $total, $input, $actorId): array {
            $planId = $this->feePlans->findOrCreatePlan(
                $chargeTypeId,
                $yearId,
                $gradeId,
                'المصروفات الدراسية - الصف ' . $gradeId,
                $actorId
            );
            $versionId = $this->feePlans->createVersion($planId, $effectiveFrom, $installments, $actorId);
            $this->feePlans->activateVersion($versionId, $actorId);
            $this->mappings->storeVersion('fee_structure', $sourceKey, 'finance_fee_plan', $planId, $yearId, [
                'grade_id' => $gradeId,
                'academic_year' => $yearName,
                'notes' => trim((string) ($input['notes'] ?? '')),
                'total_amount' => $total->toDatabaseString(),
                'version_id' => $versionId,
            ], $actorId);
            $this->audit->recordEvent('finance_legacy_fee_structure_translate', 'finance_fee_plan', $planId, null, [
                'source_key' => $sourceKey,
                'version_id' => $versionId,
                'actor_id' => $actorId,
            ]);
            return ['plan_id' => $planId, 'version_id' => $versionId, 'total' => $total->toDatabaseString()];
        });
    }

    /** @return array{plan_id:int,version_id:int,total:string} */
    public function copyFeeStructure(int $fromGradeId, int $toGradeId, string $yearName, int $actorId): array
    {
        if ($fromGradeId <= 0 || $toGradeId <= 0 || $fromGradeId === $toGradeId) {
            throw new InvalidArgumentException('يجب اختيار صف مصدر وصف هدف مختلفين.');
        }
        $yearId = $this->yearId($yearName);
        $chargeTypeId = $this->requiredChargeType('tuition');
        $sourcePlan = $this->read->feePlan($chargeTypeId, $yearId, $fromGradeId);
        if ($sourcePlan === null) {
            throw new RuntimeException('لا توجد مصاريف محددة للصف المصدر.');
        }
        $version = $this->read->activeFeePlanVersion((int) $sourcePlan['id']);
        if ($version === null) {
            throw new RuntimeException('خطة الصف المصدر ليس لها إصدار نشط.');
        }
        $rows = $this->read->feePlanInstallments((int) $version['id']);
        $post = [
            'grade_id' => $toGradeId,
            'academic_year' => $yearName,
            'notes' => 'نسخة من الصف ' . $fromGradeId,
            'installment_name' => array_column($rows, 'installment_name'),
            'installment_amount' => array_column($rows, 'gross_amount'),
            'installment_due_date' => array_column($rows, 'due_date'),
        ];
        return $this->saveFeeStructure($post, $actorId);
    }

    public function archiveFeeStructure(int $legacyId, int $actorId): void
    {
        $plan = $this->resolvePlanByLegacyId($legacyId);
        if ($plan === null) {
            throw new RuntimeException('هيكل الرسوم غير موجود.');
        }
        $this->feePlans->archivePlan((int) $plan['id'], $actorId);
        $mapping = $this->mappings->findActiveTarget('finance_fee_plan', (int) $plan['id']);
        if ($mapping !== null) {
            $this->mappings->archive((string) $mapping['source_type'], (string) $mapping['source_key']);
        }
    }

    /** @return array{success:bool,fee:array<string,mixed>|null,installments:list<array<string,mixed>>} */
    public function feeStructure(int $legacyId): array
    {
        $plan = $this->resolvePlanByLegacyId($legacyId);
        if ($plan === null) {
            return ['success' => false, 'fee' => null, 'installments' => []];
        }
        $version = $this->read->activeFeePlanVersion((int) $plan['id']);
        $rows = $version === null ? [] : $this->read->feePlanInstallments((int) $version['id']);
        $mapping = $this->mappings->findActiveTarget('finance_fee_plan', (int) $plan['id']);
        $payload = $this->payload($mapping);
        $year = $this->academicYears->yearOf((int) $plan['academic_year_id']);
        $installments = array_map(static fn (array $row): array => [
            'id' => (int) $row['id'],
            'fee_structure_id' => $legacyId,
            'installment_name' => (string) $row['installment_name'],
            'amount' => (string) $row['gross_amount'],
            'due_date' => $row['due_date'],
            'display_order' => (int) $row['display_order'],
        ], $rows);
        $total = Money::zero();
        foreach ($rows as $row) {
            $total = $total->add(Money::fromDecimalString((string) $row['gross_amount']));
        }
        return [
            'success' => true,
            'fee' => [
                'id' => $legacyId,
                'grade_id' => (int) $plan['grade_id'],
                'academic_year' => (string) ($payload['academic_year'] ?? $year['name'] ?? ''),
                'total_amount' => $total->toDatabaseString(),
                'notes' => (string) ($payload['notes'] ?? ''),
                'status' => (string) $plan['status'],
            ],
            'installments' => $installments,
        ];
    }

    public function saveSiblingDiscounts(string $yearName, array $orders, array $percentages, int $actorId): int
    {
        if (count($orders) !== count($percentages) || $orders === []) {
            throw new InvalidArgumentException('بيانات خصومات الإخوة غير مكتملة.');
        }
        $tiers = [];
        foreach ($orders as $index => $order) {
            $order = (int) $order;
            $percentage = trim((string) ($percentages[$index] ?? ''));
            if ($order <= 0 || preg_match('/^(?:100(?:\.0{1,2})?|\d{1,2}(?:\.\d{1,2})?)$/', $percentage) !== 1) {
                throw new InvalidArgumentException('نسبة خصم الإخوة غير صحيحة.');
            }
            $tiers[$order] = $percentage;
        }
        ksort($tiers);
        $yearId = $this->yearId($yearName);
        $previous = $this->mappings->findActive('sibling_discounts', 'year:' . $yearId);
        if ($previous !== null) {
            $previousRule = $this->discountRules->findRuleById((int) $previous['target_id']);
            if ($previousRule !== null && (string) $previousRule['status'] !== 'archived') {
                $this->discounts->archiveRule((int) $previous['target_id'], $actorId);
            }
        }
        $ruleId = $this->discounts->createRuleVersion(
            'sibling',
            $yearId,
            'tuition',
            'خصومات الإخوة',
            100,
            false,
            null,
            date('Y-m-d'),
            $actorId,
            null,
            'sibling_tiers',
            null,
            ['tiers' => $tiers]
        );
        $this->discounts->activateRule($ruleId, $actorId);
        $this->mappings->storeVersion('sibling_discounts', 'year:' . $yearId, 'finance_discount_rule', $ruleId, $yearId, ['tiers' => $tiers], $actorId);
        return $ruleId;
    }

    public function saveBusZone(array $input, int $actorId): int
    {
        $legacyId = (int) ($input['zone_id'] ?? 0);
        $yearName = trim((string) ($input['academic_year'] ?? ''));
        $yearId = $this->yearId($yearName);
        $zoneName = trim((string) ($input['zone_name'] ?? ''));
        $amount = Money::fromDecimalString($this->decimal($input['fee_amount'] ?? null, 'قيمة الاشتراك'));
        $sourceKey = $legacyId > 0 ? 'legacy-id:' . $legacyId : 'generated:' . bin2hex(random_bytes(8));
        $scheduleId = $this->busSchedules->createAndActivate(
            $yearId,
            null,
            $sourceKey,
            $zoneName,
            $amount,
            [],
            ($input['zone_notes'] ?? '') === '' ? null : (string) $input['zone_notes'],
            date('Y-m-d'),
            $actorId
        );
        $this->mappings->storeVersion('bus_fee_zone', $sourceKey, 'finance_bus_fee_schedule', $scheduleId, $yearId, [
            'legacy_id' => $legacyId,
            'zone_name' => $zoneName,
            'academic_year' => $yearName,
            'fee_amount' => $amount->toDatabaseString(),
            'notes' => trim((string) ($input['zone_notes'] ?? '')),
        ], $actorId);
        return $scheduleId;
    }

    public function archiveBusZone(int $legacyId, string $yearName, int $actorId): void
    {
        $yearId = $this->yearId($yearName);
        $key = 'legacy-id:' . $legacyId;
        $this->busSchedules->archive($yearId, $key, $actorId);
        $mapping = $this->mappings->findActive('bus_fee_zone', $key);
        if ($mapping !== null) {
            $this->mappings->archive('bus_fee_zone', $key);
        }
    }

    public function saveOtherDiscount(array $input, int $actorId): int
    {
        $legacyId = (int) ($input['od_id'] ?? 0);
        $yearName = trim((string) ($input['academic_year'] ?? ''));
        if ($yearName === '' && $legacyId > 0) {
            $legacy = $this->read->legacyOtherDiscount($legacyId);
            $yearName = trim((string) ($legacy['academic_year'] ?? ''));
        }
        $yearId = $this->yearId($yearName);
        $name = trim((string) ($input['od_name'] ?? ''));
        $type = (string) ($input['discount_type'] ?? 'amount');
        $value = $this->decimal($input['discount_value'] ?? null, 'قيمة الخصم');
        if ($name === '' || !in_array($type, ['amount', 'percentage'], true)) {
            throw new InvalidArgumentException('بيانات الخصم غير صحيحة.');
        }
        $calculationType = $type === 'percentage' ? 'percentage' : 'fixed_amount';
        $sourceKey = $legacyId > 0 ? 'legacy-id:' . $legacyId : 'generated:' . bin2hex(random_bytes(8));
        $ruleId = $this->discounts->createRuleVersion(
            'manual',
            $yearId,
            'tuition:other:' . $sourceKey,
            $name,
            50,
            false,
            null,
            date('Y-m-d'),
            $actorId,
            null,
            $calculationType,
            $value
        );
        $this->discounts->activateRule($ruleId, $actorId);
        $this->mappings->storeVersion('other_discount', $sourceKey, 'finance_discount_rule', $ruleId, $yearId, [
            'legacy_id' => $legacyId,
            'name' => $name,
            'discount_type' => $type,
            'discount_value' => $value,
            'academic_year' => $yearName,
        ], $actorId);
        return $ruleId;
    }

    public function setOtherDiscountStatus(int $legacyId, string $status, int $actorId): void
    {
        $mapping = $this->mappings->findActive('other_discount', 'legacy-id:' . $legacyId);
        if ($mapping === null) {
            throw new RuntimeException('الخصم غير موجود في نظام المالية الجديد.');
        }
        $ruleId = (int) $mapping['target_id'];
        if ($status === 'active') {
            $rule = $this->discountRules->findRuleById($ruleId);
            if ($rule === null) {
                throw new RuntimeException('الخصم غير موجود في نظام المالية الجديد.');
            }
            if ((string) $rule['status'] === 'active') {
                return;
            }
            $payload = $this->payload($mapping);
            $this->saveOtherDiscount([
                'od_id' => $legacyId,
                'academic_year' => $payload['academic_year'] ?? '',
                'od_name' => $payload['name'] ?? $rule['name_ar'],
                'discount_type' => $payload['discount_type'] ?? ((string) $rule['calculation_type'] === 'percentage' ? 'percentage' : 'amount'),
                'discount_value' => $payload['discount_value'] ?? $rule['calculation_value'],
            ], $actorId);
            return;
        }
        $this->discounts->archiveRule($ruleId, $actorId);
    }

    public function archiveOtherDiscount(int $legacyId, int $actorId): void
    {
        $mapping = $this->mappings->findActive('other_discount', 'legacy-id:' . $legacyId);
        if ($mapping === null) {
            throw new RuntimeException('الخصم غير موجود في نظام المالية الجديد.');
        }
        $this->discounts->archiveRule((int) $mapping['target_id'], $actorId);
        $this->mappings->archive('other_discount', 'legacy-id:' . $legacyId);
    }

    /** @return array<string,mixed> */
    public function calculate(int $gradeId, int $siblingOrder, int $busZoneId, string $yearName): array
    {
        $yearId = $this->yearId($yearName);
        $tuition = $this->tuition($gradeId, $yearId);
        $rule = $this->discountRules->findActiveRule('sibling', $yearId, 'tuition', date('Y-m-d'));
        $tiers = [];
        if ($rule !== null && (string) ($rule['calculation_type'] ?? '') === 'sibling_tiers') {
            $parameters = json_decode((string) ($rule['parameters_json'] ?? '{}'), true);
            $tiers = is_array($parameters['tiers'] ?? null) ? $parameters['tiers'] : [];
        }
        $discount = $this->siblingPolicy->computeDiscount($tuition, max(1, $siblingOrder), $tiers);
        $discountPct = (string) ($tiers[max(1, $siblingOrder)] ?? '0');
        $busFee = $this->busFee($busZoneId, $yearId);
        $after = $tuition->subtract($discount);
        return [
            'success' => true,
            'tuition' => $tuition->toDatabaseString(),
            'discount_pct' => $discountPct,
            'sibling_discount' => $discount->toDatabaseString(),
            'tuition_after_discount' => $after->toDatabaseString(),
            'bus_fee' => $busFee->toDatabaseString(),
            'total' => $after->add($busFee)->toDatabaseString(),
        ];
    }

    /** @return array<string,mixed> */
    public function calculateFamily(array $siblings, string $yearName): array
    {
        if ($siblings === []) {
            throw new InvalidArgumentException('لم يتم إدخال بيانات الإخوة.');
        }
        $results = [];
        $familyTotal = Money::zero();
        $familyDiscount = Money::zero();
        foreach (array_values($siblings) as $index => $sibling) {
            $result = $this->calculate(
                (int) ($sibling['grade_id'] ?? 0),
                $index + 1,
                (int) ($sibling['bus_zone_id'] ?? 0),
                $yearName
            );
            $discount = Money::fromDecimalString((string) $result['sibling_discount']);
            $subtotal = Money::fromDecimalString((string) $result['total']);
            $results[] = [
                'order' => $index + 1,
                'grade_id' => (int) ($sibling['grade_id'] ?? 0),
                'tuition' => $result['tuition'],
                'discount_pct' => $result['discount_pct'],
                'discount_amount' => $result['sibling_discount'],
                'after_discount' => $result['tuition_after_discount'],
                'bus_fee' => $result['bus_fee'],
                'subtotal' => $result['total'],
            ];
            $familyTotal = $familyTotal->add($subtotal);
            $familyDiscount = $familyDiscount->add($discount);
        }
        return [
            'success' => true,
            'results' => $results,
            'family_total' => $familyTotal->toDatabaseString(),
            'family_discount' => $familyDiscount->toDatabaseString(),
        ];
    }

    private function tuition(int $gradeId, int $yearId): Money
    {
        $chargeTypeId = $this->requiredChargeType('tuition');
        $plan = $this->read->feePlan($chargeTypeId, $yearId, $gradeId);
        if ($plan === null) {
            return Money::zero();
        }
        $version = $this->read->activeFeePlanVersion((int) $plan['id']);
        if ($version === null) {
            return Money::zero();
        }
        $total = Money::zero();
        foreach ($this->read->feePlanInstallments((int) $version['id']) as $row) {
            $total = $total->add(Money::fromDecimalString((string) $row['gross_amount']));
        }
        return $total;
    }

    private function busFee(int $legacyZoneId, int $yearId): Money
    {
        if ($legacyZoneId <= 0) {
            return Money::zero();
        }
        $row = $this->busScheduleRepository->findActiveByLegacyKey($yearId, 'legacy-id:' . $legacyZoneId);
        if ($row !== null) {
            return Money::fromDecimalString((string) $row['amount']);
        }
        $mapping = $this->mappings->findActive('bus_fee_zone', 'legacy-id:' . $legacyZoneId);
        $payload = $this->payload($mapping);
        return isset($payload['fee_amount'])
            ? Money::fromDecimalString((string) $payload['fee_amount'])
            : Money::zero();
    }

    /** @return array<string,mixed>|null */
    private function resolvePlanByLegacyId(int $legacyId): ?array
    {
        $mapping = $this->mappings->findActive('fee_structure', 'legacy-id:' . $legacyId);
        if ($mapping !== null) {
            return $this->read->feePlanById((int) $mapping['target_id']);
        }
        $coordinates = $this->read->legacyFeeStructureCoordinates($legacyId);
        if ($coordinates === null) {
            return null;
        }
        $yearId = $this->yearId($coordinates['academic_year']);
        return $this->read->feePlan($this->requiredChargeType('tuition'), $yearId, $coordinates['grade_id']);
    }

    private function yearId(string $yearName): int
    {
        $yearName = trim($yearName);
        $id = $yearName === ''
            ? $this->academicYears->currentId()
            : $this->academicYears->idByName($yearName);
        if ($id === null) {
            throw new RuntimeException('العام الدراسي غير موجود في النظام الجديد.');
        }
        return $id;
    }

    private function requiredChargeType(string $code): int
    {
        $id = $this->read->chargeTypeId($code);
        if ($id === null) {
            throw new RuntimeException('نوع الرسوم المطلوب غير مهيأ.');
        }
        return $id;
    }

    /** @param list<array<string,mixed>> $installments */
    private function effectiveFrom(array $installments): string
    {
        $dates = array_values(array_filter(array_map(
            static fn (array $row): ?string => $row['due_date'] ?? null,
            $installments
        )));
        return $dates === [] ? date('Y-m-d') : min($dates);
    }

    private function decimal(mixed $value, string $label): string
    {
        $text = trim((string) $value);
        if (preg_match('/^\d+(?:\.\d{1,2})?$/', $text) !== 1) {
            throw new InvalidArgumentException($label . ' غير صحيحة.');
        }
        return Money::fromDecimalString($text)->toDatabaseString();
    }

    /** @return array<string,mixed> */
    private function payload(?array $mapping): array
    {
        if ($mapping === null || empty($mapping['payload_json'])) {
            return [];
        }
        $decoded = json_decode((string) $mapping['payload_json'], true);
        return is_array($decoded) ? $decoded : [];
    }
}
