<?php

declare(strict_types=1);

namespace EduCore\Modules\Finance\Domain;

use InvalidArgumentException;
use RuntimeException;

final class FinanceAuthorization
{
    /** @var array<string, array{permission:string, maker_checker:bool}> */
    private const OPERATIONS = [
        'fee_plan_manage' => ['permission' => 'finance.fee_plans.manage', 'maker_checker' => false],
        'discount_approve' => ['permission' => 'finance.discounts.approve', 'maker_checker' => true],
        'receipt_post' => ['permission' => 'finance.receipts.post', 'maker_checker' => false],
        'receipt_reverse' => ['permission' => 'finance.receipts.reverse', 'maker_checker' => true],
        'refund_post' => ['permission' => 'finance.refunds.post', 'maker_checker' => true],
        'refund_reverse' => ['permission' => 'finance.refunds.reverse', 'maker_checker' => true],
        'debt_write_off' => ['permission' => 'finance.debts.write_off', 'maker_checker' => true],
        'debt_write_off_reverse' => ['permission' => 'finance.debts.write_off.reverse', 'maker_checker' => true],
        'staff_contract_approve' => ['permission' => 'finance.staff_contracts.approve', 'maker_checker' => true],
        'payroll_approve' => ['permission' => 'finance.payroll.approve', 'maker_checker' => true],
        'payroll_post' => ['permission' => 'finance.payroll.post', 'maker_checker' => true],
        'payroll_reverse' => ['permission' => 'finance.payroll.reverse', 'maker_checker' => true],
        'advance_write_off' => ['permission' => 'finance.advances.write_off', 'maker_checker' => true],
        'voucher_post' => ['permission' => 'finance.vouchers.post', 'maker_checker' => true],
        'manual_journal_post' => ['permission' => 'finance.journal.post', 'maker_checker' => true],
        'manual_journal_reverse' => ['permission' => 'finance.journal.reverse', 'maker_checker' => true],
        'period_close' => ['permission' => 'finance.periods.close', 'maker_checker' => true],
        'period_reopen' => ['permission' => 'finance.periods.reopen', 'maker_checker' => true],
        'import_post' => ['permission' => 'finance.imports.post', 'maker_checker' => true],
        'budget_approve' => ['permission' => 'finance.budgets.approve', 'maker_checker' => true],
    ];

    public static function permissionFor(string $operation): string
    {
        return self::definition($operation)['permission'];
    }

    public static function requiresMakerChecker(string $operation): bool
    {
        return self::definition($operation)['maker_checker'];
    }

    public static function assertMakerChecker(string $operation, int $makerId, int $checkerId): void
    {
        if (!self::requiresMakerChecker($operation)) {
            return;
        }
        if ($makerId <= 0 || $checkerId <= 0) {
            throw new InvalidArgumentException('Maker and checker identifiers must be positive.');
        }
        if ($makerId === $checkerId) {
            throw new RuntimeException('The maker cannot approve the same finance operation.');
        }
    }

    /** @return array{permission:string, maker_checker:bool} */
    private static function definition(string $operation): array
    {
        if (!isset(self::OPERATIONS[$operation])) {
            throw new InvalidArgumentException('Unknown finance operation: ' . $operation);
        }

        return self::OPERATIONS[$operation];
    }
}
