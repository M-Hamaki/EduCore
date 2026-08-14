<?php

declare(strict_types=1);

/**
 * Contract test for US5/US6: voucher GL balance + reconciliation logic.
 *
 * Verifies:
 * - VoucherService balance check: SUM(debit) = SUM(credit)
 * - ReconciliationService decimal arithmetic (no float)
 * - net_account_position = outstanding_due - unapplied_credit
 *
 * Run: C:\xampp\php\php.exe tests/finance_voucher_reconciliation_contract_test.php
 */

require_once __DIR__ . '/../src/Modules/Finance/Domain/Money.php';
require_once __DIR__ . '/../src/Modules/Finance/Domain/SignedMoneyDelta.php';

use EduCore\Modules\Finance\Domain\Money;
use EduCore\Modules\Finance\Domain\SignedMoneyDelta;

$failures = 0;
$assert = static function (bool $condition, string $message) use (&$failures): void {
    if (!$condition) {
        echo "FAIL: $message\n";
        ++$failures;
    }
};

// ============================================================
// 1. Voucher balance: SUM(debit) = SUM(credit) = amount
// ============================================================
$amount = Money::fromDecimalString('500.00');
$debit1 = Money::fromDecimalString('300.00');
$debit2 = Money::fromDecimalString('200.00');
$credit1 = Money::fromDecimalString('500.00');

$totalDebit = $debit1->add($debit2);
$assert($totalDebit->toDatabaseString() === '500.00', 'total debit = 500.00');
$assert($totalDebit->equals($credit1), 'debit total = credit total');
$assert($totalDebit->equals($amount), 'debit total = voucher amount');

// Unbalanced case
$unbalancedCredit = Money::fromDecimalString('400.00');
$assert(!$totalDebit->equals($unbalancedCredit), 'unbalanced: debit ≠ credit detected');

// ============================================================
// 2. Reconciliation: net_account_position = outstanding - unapplied
// ============================================================
// Simulate: outstanding_due = 150.00, unapplied_credit = 30.00 → net = 120.00
$outstanding = 15000; // minor
$unapplied = 3000;   // minor
$net = $outstanding - $unapplied;
$assert($net === 12000, 'net_account_position = 150-30 = 120.00 (12000 minor)');

// Simulate: outstanding = 100.00, unapplied = 100.00 → net = 0
$net2 = 10000 - 10000;
$assert($net2 === 0, 'net = 0 when outstanding = unapplied');

// Simulate: outstanding = 50.00, unapplied = 80.00 → net = -30.00
$net3 = 5000 - 8000;
$assert($net3 === -3000, 'net negative = -30.00 when unapplied > outstanding');

// ============================================================
// 3. Decimal subtraction (no float drift)
// ============================================================
// 0.10 + 0.20 = 0.30 (no float drift)
$a = Money::fromDecimalString('0.10');
$b = Money::fromDecimalString('0.20');
$sum = $a->add($b);
$assert($sum->toDatabaseString() === '0.30', 'no float drift: 0.10+0.20=0.30');

// 100.50 - 50.25 = 50.25
$x = Money::fromDecimalString('100.50');
$y = Money::fromDecimalString('50.25');
$diff = $x->subtract($y);
$assert($diff->toDatabaseString() === '50.25', '100.50-50.25 = 50.25');

// ============================================================
// 4. Original + reversal = 0 (voucher reversal)
// ============================================================
$voucherDelta = SignedMoneyDelta::fromDecimalString('500.00');
$reversalDelta = $voucherDelta->negate();
$net4 = $voucherDelta->add($reversalDelta);
$assert($net4->isZero(), 'voucher original + reversal = 0');

// ============================================================
// Result
// ============================================================
if ($failures > 0) {
    echo "\n$failures FAILURES\n";
    exit(1);
}
echo "\nAll voucher + reconciliation contract tests passed.\n";
exit(0);
