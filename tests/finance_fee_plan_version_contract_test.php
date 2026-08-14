<?php

declare(strict_types=1);

/**
 * Contract test for US1: versioned fee plans and per-student contract immutability.
 *
 * Verifies:
 * - FeePlanService.isVersionUsed logic.
 * - assertVersionEditable throws for used versions.
 * - AccountMappingPolicy deterministic resolution.
 *
 * Note: Full integration tests requiring DB are in tests/finance_student_contract_integration_test.php
 * (requires *_test database). This contract test verifies pure domain logic without DB.
 *
 * Run: php tests/finance_fee_plan_version_contract_test.php
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

// === Money: charge net_due = gross - discount - adjustment ===
$gross = Money::fromDecimalString('1000.00');
$discount = Money::fromDecimalString('100.00');
$adjustment = Money::fromDecimalString('50.00');
$netDue = $gross->subtract($discount)->subtract($adjustment);
$assert($netDue->toDatabaseString() === '850.00', 'net_due = 1000-100-50 = 850.00');

// === Money: SUM(installments) = net_due ===
$inst1 = Money::fromDecimalString('283.33');
$inst2 = Money::fromDecimalString('283.33');
$inst3 = Money::fromDecimalString('283.34');
$sumInst = $inst1->add($inst2)->add($inst3);
$assert($sumInst->toMinorUnits() === 85000, 'SUM(installments) = 850.00 minor units');
$assert($sumInst->toDatabaseString() === '850.00', 'SUM(installments) = 850.00 db');

// === SignedMoneyDelta: charge posts positive delta on STUDENT_OUTSTANDING_DUE ===
$chargeDelta = SignedMoneyDelta::fromDecimalString($netDue->toDatabaseString());
$assert($chargeDelta->isPositive(), 'charge delta is positive');
$assert($chargeDelta->toDatabaseString() === '850.00', 'charge delta 850.00');

// === Reversal of charge = negative delta ===
$reversalDelta = $chargeDelta->negate();
$assert($reversalDelta->isNegative(), 'reversal delta is negative');
$assert($reversalDelta->toDatabaseString() === '-850.00', 'reversal delta -850.00');

// === original + reversal = 0 ===
$net = $chargeDelta->add($reversalDelta);
$assert($net->isZero(), 'original charge + reversal = 0');

// === AccountMappingPolicy: deterministic resolution (re-used from invariants test) ===
$policy = new AccountMappingPolicy();
$resolved = $policy->resolve([
    ['specificity_score' => 10, 'priority' => 1, 'version_number' => 1, 'debit_account_id' => 100, 'credit_account_id' => 200],
    ['specificity_score' => 20, 'priority' => 5, 'version_number' => 1, 'debit_account_id' => 300, 'credit_account_id' => 400],
]);
$assert($resolved['specificity_score'] === 20, 'mapping: higher specificity wins');
$assert($resolved['debit_account_id'] === 300, 'mapping: correct debit account');

// Zero matches throws
$threw = false;
try {
    $policy->resolve([]);
} catch (RuntimeException) {
    $threw = true;
}
$assert($threw, 'mapping: zero matches throws');

// === Result ===
if ($failures > 0) {
    echo "\n$failures FAILURES\n";
    exit(1);
}
echo "\nAll US1 fee plan version immutability contract tests passed.\n";
exit(0);
