<?php

declare(strict_types=1);

namespace EduCore\Modules\Finance\Application;

use EduCore\Modules\Finance\Contracts\Queries\LegacyFinanceReadQuery;
use EduCore\Modules\Finance\Contracts\Repositories\LegacyCompatibilityRepository;
use EduCore\Modules\Finance\Domain\Money;
use EduCore\Modules\Staff\Contracts\StaffFinanceReadQuery;
use InvalidArgumentException;
use RuntimeException;

/** Compatibility boundary for the retained staff_financial_data.php contract. */
final class LegacyStaffFinanceCompatibilityService
{
    public function __construct(
        private StaffFinanceReadQuery $staff,
        private LegacyFinanceReadQuery $finance,
        private StaffCompensationService $contracts,
        private LegacyCompatibilityRepository $mappings
    ) {
    }

    /** @return array<string,mixed> */
    public function staffFinancial(int $staffId): array
    {
        $staff = $this->staff->staff($staffId);
        if ($staff === null) {
            throw new RuntimeException('العامل غير موجود.');
        }
        $mapping = $this->mappings->findActive('staff_financial_data', 'staff:' . $staffId);
        $payload = $this->payload($mapping);
        $contract = $mapping === null
            ? $this->finance->activeStaffContract($staffId)
            : $this->finance->staffContract((int) $mapping['target_id']);
        $values = [
            'basic_salary' => '',
            'allowance_transport' => '',
            'allowance_housing' => '',
            'other_allowances_data' => $payload['other_allowances_data'] ?? '[]',
            'deduction_insurance' => '',
            'deduction_tax' => '',
            'other_deductions_data' => $payload['other_deductions_data'] ?? '[]',
            'net_salary' => '',
            'advances_data' => $payload['advances_data'] ?? '[]',
            'financial_notes' => $payload['financial_notes'] ?? '',
        ];
        if ($contract !== null) {
            $earning = Money::zero();
            $deduction = Money::zero();
            foreach ($this->finance->staffContractComponents((int) $contract['id']) as $component) {
                $amount = Money::fromDecimalString((string) $component['amount']);
                $code = (string) $component['code'];
                if ($code === 'basic') {
                    $values['basic_salary'] = $amount->toDatabaseString();
                } elseif ($code === 'allowance_transport') {
                    $values['allowance_transport'] = $amount->toDatabaseString();
                } elseif ($code === 'allowance_housing') {
                    $values['allowance_housing'] = $amount->toDatabaseString();
                } elseif ($code === 'insurance') {
                    $values['deduction_insurance'] = $amount->toDatabaseString();
                } elseif ($code === 'tax') {
                    $values['deduction_tax'] = $amount->toDatabaseString();
                }
                if ((string) $component['direction'] === 'earning') {
                    $earning = $earning->add($amount);
                } else {
                    $deduction = $deduction->add($amount);
                }
            }
            $values['net_salary'] = Money::fromMinorUnits(max(
                0,
                $earning->toMinorUnits() - $deduction->toMinorUnits()
            ))->toDatabaseString();
        }
        return ['success' => true, 'data' => $staff + $values];
    }

    public function save(array $input, int $actorId): int
    {
        $staffId = (int) ($input['staff_id'] ?? 0);
        if ($this->staff->staff($staffId) === null) {
            throw new RuntimeException('العامل غير موجود.');
        }
        $otherAllowances = $this->moneyList($input['other_allowances_data'] ?? '[]', 'البدلات الأخرى');
        $otherDeductions = $this->moneyList($input['other_deductions_data'] ?? '[]', 'الاستقطاعات الأخرى');
        $advances = $this->jsonList($input['advances_data'] ?? '[]', 'السلف');
        if ($advances !== []) {
            throw new RuntimeException('يجب تسجيل السلف وسدادها من صفحة سلف العاملين الجديدة لضمان القيود المحاسبية وسجل العكس.');
        }
        $components = [];
        foreach ([
            ['basic', $input['basic_salary'] ?? '', 'earning'],
            ['allowance_transport', $input['allowance_transport'] ?? '', 'earning'],
            ['allowance_housing', $input['allowance_housing'] ?? '', 'earning'],
            ['allowance_variable', $this->sumList($otherAllowances), 'earning'],
            ['insurance', $input['deduction_insurance'] ?? '', 'deduction'],
            ['tax', $input['deduction_tax'] ?? '', 'deduction'],
            ['other_deduction', $this->sumList($otherDeductions), 'deduction'],
        ] as [$code, $rawAmount, $direction]) {
            $amount = $this->optionalMoney($rawAmount);
            if ($amount === null || $amount->isZero()) {
                continue;
            }
            $componentId = $this->finance->payrollComponentId($code);
            if ($componentId === null) {
                throw new RuntimeException('مكون الراتب غير مهيأ: ' . $code);
            }
            $components[] = [
                'component_id' => $componentId,
                'amount' => $amount,
                'direction' => $direction,
            ];
        }
        if ($components === []) {
            throw new InvalidArgumentException('يجب إدخال مكون مالي واحد على الأقل.');
        }
        $contractId = $this->contracts->createDraft(
            $staffId,
            date('Y-m-d'),
            'business_decision',
            'confirmed',
            $components,
            $actorId
        );
        $this->mappings->storeVersion(
            'staff_financial_data',
            'staff:' . $staffId,
            'staff_compensation_contract',
            $contractId,
            null,
            [
                'other_allowances_data' => json_encode($otherAllowances, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR),
                'other_deductions_data' => json_encode($otherDeductions, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR),
                'advances_data' => '[]',
                'financial_notes' => trim((string) ($input['financial_notes'] ?? '')),
                'legacy_net_salary' => trim((string) ($input['net_salary'] ?? '')),
            ],
            $actorId
        );
        return $contractId;
    }

    /** @return list<array{name:string,amount:string}> */
    private function moneyList(mixed $json, string $label): array
    {
        $rows = $this->jsonList($json, $label);
        $normalized = [];
        foreach ($rows as $row) {
            if (!is_array($row)) {
                throw new InvalidArgumentException($label . ' غير صحيحة.');
            }
            $name = trim((string) ($row['name'] ?? ''));
            $amount = $this->optionalMoney($row['amount'] ?? null);
            if ($name === '' || $amount === null || $amount->isZero()) {
                continue;
            }
            $normalized[] = ['name' => $name, 'amount' => $amount->toDatabaseString()];
        }
        return $normalized;
    }

    /** @return list<mixed> */
    private function jsonList(mixed $json, string $label): array
    {
        if (is_array($json)) {
            return array_values($json);
        }
        try {
            $decoded = json_decode((string) $json, true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            throw new InvalidArgumentException($label . ' غير صحيحة.');
        }
        if (!is_array($decoded)) {
            throw new InvalidArgumentException($label . ' غير صحيحة.');
        }
        return array_values($decoded);
    }

    private function sumList(array $rows): string
    {
        $sum = Money::zero();
        foreach ($rows as $row) {
            $sum = $sum->add(Money::fromDecimalString((string) $row['amount']));
        }
        return $sum->toDatabaseString();
    }

    private function optionalMoney(mixed $value): ?Money
    {
        $text = trim((string) $value);
        if ($text === '') {
            return null;
        }
        if (preg_match('/^\d+(?:\.\d{1,2})?$/', $text) !== 1) {
            throw new InvalidArgumentException('قيمة مالية غير صحيحة.');
        }
        return Money::fromDecimalString($text);
    }

    /** @return array<string,mixed> */
    private function payload(?array $mapping): array
    {
        if ($mapping === null || empty($mapping['payload_json'])) {
            return [];
        }
        $payload = json_decode((string) $mapping['payload_json'], true);
        return is_array($payload) ? $payload : [];
    }
}
