<?php

declare(strict_types=1);

/**
 * Invariants contract test for the Finance module.
 *
 * Verifies:
 * - AccountMappingPolicy deterministic resolution (specificity→priority→version).
 * - Refusal on zero matches and ambiguous matches.
 * - Money + SignedMoneyDelta: original + reversal = 0.
 * - Bucket balance equations are consistent.
 *
 * Run: php tests/finance_invariants_contract_test.php
 */

require_once __DIR__ . '/../src/Modules/Finance/Domain/Money.php';
require_once __DIR__ . '/../src/Modules/Finance/Domain/SignedMoneyDelta.php';
require_once __DIR__ . '/../src/Modules/Finance/Domain/Policy/AccountMappingPolicy.php';

use EduCore\Modules\Finance\Domain\Money;
use EduCore\Modules\Finance\Domain\SignedMoneyDelta;
use EduCore\Modules\Finance\Domain\Policy\AccountMappingPolicy;

$failures = 0;
$assert = static function (bool $condition, string $message) use (&$failures): void {
    if (!$condition) {
        echo "FAIL: $message\n";
        ++$failures;
    }
};

// ============================================================
// 1. AccountMappingPolicy: deterministic resolution
// ============================================================
$policy = new AccountMappingPolicy();

// Zero matches → throws
$threwZero = false;
try {
    $policy->resolve([]);
} catch (RuntimeException $e) {
    $threwZero = true;
}
$assert($threwZero, 'account-mapping: zero matches throws');

// Single match → resolves
$single = $policy->resolve([
    ['specificity_score' => 10, 'priority' => 5, 'version_number' => 1, 'debit_account_id' => 1, 'credit_account_id' => 2],
]);
$assert($single['debit_account_id'] === 1, 'account-mapping: single match resolves');

// Specificity wins over priority
$resolved = $policy->resolve([
    ['specificity_score' => 10, 'priority' => 1, 'version_number' => 1, 'debit_account_id' => 100, 'credit_account_id' => 200],
    ['specificity_score' => 20, 'priority' => 5, 'version_number' => 1, 'debit_account_id' => 300, 'credit_account_id' => 400],
]);
$assert($resolved['specificity_score'] === 20, 'account-mapping: higher specificity wins');
$assert($resolved['debit_account_id'] === 300, 'account-mapping: specificity winner debit_account_id');

// Priority breaks specificity tie
$resolved2 = $policy->resolve([
    ['specificity_score' => 10, 'priority' => 1, 'version_number' => 1, 'debit_account_id' => 100, 'credit_account_id' => 200],
    ['specificity_score' => 10, 'priority' => 5, 'version_number' => 1, 'debit_account_id' => 300, 'credit_account_id' => 400],
]);
$assert($resolved2['priority'] === 5, 'account-mapping: priority breaks specificity tie');
$assert($resolved2['debit_account_id'] === 300, 'account-mapping: priority winner');

// Version breaks specificity+priority tie
$resolved3 = $policy->resolve([
    ['specificity_score' => 10, 'priority' => 5, 'version_number' => 1, 'debit_account_id' => 100, 'credit_account_id' => 200],
    ['specificity_score' => 10, 'priority' => 5, 'version_number' => 3, 'debit_account_id' => 300, 'credit_account_id' => 400],
]);
$assert($resolved3['version_number'] === 3, 'account-mapping: version breaks tie');
$assert($resolved3['debit_account_id'] === 300, 'account-mapping: version winner');

// Ambiguous (same specificity+priority+version) → throws
$threwAmbiguous = false;
try {
    $policy->resolve([
        ['specificity_score' => 10, 'priority' => 5, 'version_number' => 1, 'debit_account_id' => 100, 'credit_account_id' => 200],
        ['specificity_score' => 10, 'priority' => 5, 'version_number' => 1, 'debit_account_id' => 300, 'credit_account_id' => 400],
    ]);
} catch (RuntimeException $e) {
    $threwAmbiguous = true;
}
$assert($threwAmbiguous, 'account-mapping: ambiguous (same spec+prio+version) throws');

// ============================================================
// 2. Original + reversal = 0 (signed deltas)
// ============================================================
$original = SignedMoneyDelta::fromMinorUnits(5000);  // +50.00
$reversal = $original->negate();                       // -50.00
$net = $original->add($reversal);                      // 0
$assert($net->isZero(), 'original + reversal = 0');

// With non-zero original
$orig2 = SignedMoneyDelta::fromMinorUnits(10050);  // +100.50
$rev2 = $orig2->negate();                            // -100.50
$net2 = $orig2->add($rev2);
$assert($net2->isZero(), 'original(100.50) + reversal(-100.50) = 0');

// ============================================================
// 3. Bucket balance simulation (outstanding_due equation)
// ============================================================
// Simulate: charge +100, allocation -60, refund_allocation +10
// outstanding_due = 100 - 60 + 10 = 50
$chargeDelta = SignedMoneyDelta::fromMinorUnits(10000);   // +100.00
$allocationDelta = SignedMoneyDelta::fromMinorUnits(-6000); // -60.00
$refundDelta = SignedMoneyDelta::fromMinorUnits(1000);    // +10.00

$outstandingDue = $chargeDelta->add($allocationDelta)->add($refundDelta);
$assert($outstandingDue->toMinorUnits() === 5000, 'outstanding_due = 100-60+10 = 50.00');
$assert($outstandingDue->toDatabaseString() === '50.00', 'outstanding_due db string 50.00');

// Simulate: unapplied credit +30, application -20, refund_unapplied -5
// unapplied_credit = 30 - 20 - 5 = 5
$ucCreate = SignedMoneyDelta::fromMinorUnits(3000);   // +30.00
$ucApply = SignedMoneyDelta::fromMinorUnits(-2000);     // -20.00
$ucRefund = SignedMoneyDelta::fromMinorUnits(-500);    // -5.00

$unappliedCredit = $ucCreate->add($ucApply)->add($ucRefund);
$assert($unappliedCredit->toMinorUnits() === 500, 'unapplied_credit = 30-20-5 = 5.00');
$assert($unappliedCredit->toDatabaseString() === '5.00', 'unapplied_credit db 5.00');

// net_account_position = outstanding_due - unapplied_credit = 50 - 5 = 45
$assert($outstandingDue->toMinorUnits() - $unappliedCredit->toMinorUnits() === 4500, 'net_account_position = 50-5 = 45.00');

// ============================================================
// 4. Receipt amount = allocations + unapplied credit
// ============================================================
// receipt = 100.00; allocations = 30+30+30=90; unapplied = 10
$receiptAmount = Money::fromMinorUnits(10000);  // 100.00
$alloc1 = Money::fromMinorUnits(3000);
$alloc2 = Money::fromMinorUnits(3000);
$alloc3 = Money::fromMinorUnits(3000);
$unapplied = Money::fromMinorUnits(1000);

$totalAllocations = $alloc1->add($alloc2)->add($alloc3);  // 90.00
$receiptComponents = $totalAllocations->add($unapplied);   // 100.00
$assert($receiptAmount->equals($receiptComponents), 'receipt.amount = SUM(allocations) + SUM(unapplied_credit)');

// ============================================================
// 5. SUM(installment.net_amount) = charge.net_due
// ============================================================
$chargeNetDue = Money::fromMinorUnits(9000);  // 90.00
$inst1 = Money::fromMinorUnits(3000);
$inst2 = Money::fromMinorUnits(3000);
$inst3 = Money::fromMinorUnits(3000);
$sumInstallments = $inst1->add($inst2)->add($inst3);
$assert($chargeNetDue->equals($sumInstallments), 'SUM(installment.net_amount) = charge.net_due');

// ============================================================
// 6. allocation <= installment.remaining_due
// ============================================================
$installmentNet = Money::fromMinorUnits(3000);  // 30.00
$allocation = Money::fromMinorUnits(2500);       // 25.00
$assert(!$allocation->greaterThan($installmentNet), 'allocation <= installment.net_amount');

// ============================================================
// Result
// ============================================================
if ($failures > 0) {
    echo "\n$failures FAILURES\n";
    exit(1);
}
echo "\nAll Finance invariants contract tests passed.\n";
exit(0);
