<?php

declare(strict_types=1);

namespace EduCore\Modules\Finance\Application;

use EduCore\Modules\Finance\Contracts\FinanceImportOperation;
use EduCore\Modules\Finance\Domain\Money;
use Throwable;

final class VoucherImportOperation implements FinanceImportOperation
{
    public function __construct(private VoucherService $vouchers)
    {
    }

    public function operationType(): string
    {
        return 'vouchers';
    }

    public function validate(array $payload, array $context): array
    {
        $errors = [];
        $type = (string) ($payload['voucher_type'] ?? '');
        if (!in_array($type, ['expense', 'other_income', 'cash_transfer'], true)) {
            $errors[] = 'voucher_type is invalid';
        }
        try {
            if (Money::fromDecimalString((string) ($payload['amount'] ?? '0'))->isZero()) {
                $errors[] = 'amount must be positive';
            }
        } catch (Throwable) {
            $errors[] = 'amount is invalid';
        }
        if (preg_match('/^\d{4}-\d{2}-\d{2}$/', (string) ($payload['entry_date'] ?? '')) !== 1) {
            $errors[] = 'entry_date is invalid';
        }
        $cashboxId = $this->nullablePositiveInt($payload['cashbox_id'] ?? null);
        $sourceId = $this->nullablePositiveInt($payload['source_cashbox_id'] ?? null);
        $destinationId = $this->nullablePositiveInt($payload['destination_cashbox_id'] ?? null);
        if ($type === 'cash_transfer') {
            if ($cashboxId !== null || $sourceId === null || $destinationId === null || $sourceId === $destinationId) {
                $errors[] = 'cash transfer requires distinct source and destination cashboxes';
            }
        } elseif ($type !== '' && ($cashboxId === null || $sourceId !== null || $destinationId !== null)) {
            $errors[] = 'expense/income requires one cashbox';
        }
        return $errors;
    }

    public function post(array $payload, array $context): array
    {
        $voucherId = $this->vouchers->postVoucher(
            (string) $payload['voucher_type'],
            $this->nullablePositiveInt($payload['cashbox_id'] ?? null),
            $this->nullablePositiveInt($payload['source_cashbox_id'] ?? null),
            $this->nullablePositiveInt($payload['destination_cashbox_id'] ?? null),
            $this->nullablePositiveInt($payload['bank_account_id'] ?? null),
            Money::fromDecimalString((string) $payload['amount']),
            $this->nullablePositiveInt($payload['finance_period_id'] ?? null),
            (string) $payload['entry_date'],
            $this->nullablePositiveInt($payload['cost_center_id'] ?? null),
            isset($payload['description']) ? (string) $payload['description'] : null,
            (int) $context['posted_by'],
            (int) $context['approved_by'],
            (string) $context['request_id']
        );
        return ['entity_type' => 'finance_voucher', 'voucher_id' => $voucherId];
    }

    public function reverse(array $postingResult, array $context): void
    {
        $this->vouchers->reverseVoucher(
            (int) ($postingResult['voucher_id'] ?? 0),
            date('Y-m-d'),
            'Import reversal batch ' . (string) $context['batch_id'],
            (int) $context['posted_by'],
            (int) $context['approved_by'],
            (string) $context['request_id']
        );
    }

    private function nullablePositiveInt(mixed $value): ?int
    {
        if ($value === null || $value === '') {
            return null;
        }
        $integer = filter_var($value, FILTER_VALIDATE_INT);
        return $integer !== false && $integer > 0 ? $integer : null;
    }
}
