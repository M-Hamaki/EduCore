<?php

declare(strict_types=1);

/**
 * Contract test for US2: discount policies — sibling ordering, employee-child eligibility, combination.
 *
 * Run: php tests/finance_discount_policies_contract_test.php
 */

require_once __DIR__ . '/../src/Modules/Finance/Domain/Money.php';
require_once __DIR__ . '/../src/Modules/Finance/Domain/Policy/SiblingDiscountPolicy.php';
require_once __DIR__ . '/../src/Modules/Finance/Domain/Policy/EmployeeChildEligibilityPolicy.php';
require_once __DIR__ . '/../src/Modules/Finance/Domain/Policy/DiscountCombinationPolicy.php';

use EduCore\Modules\Finance\Domain\Money;
use EduCore\Modules\Finance\Domain\Policy\SiblingDiscountPolicy;
use EduCore\Modules\Finance\Domain\Policy\EmployeeChildEligibilityPolicy;
use EduCore\Modules\Finance\Domain\Policy\DiscountCombinationPolicy;

$failures = 0;
$assert = static function (bool $condition, string $message) use (&$failures): void {
    if (!$condition) {
        echo "FAIL: $message\n";
        ++$failures;
    }
};

// ============================================================
// 1. SiblingDiscountPolicy: ordering by oldest enrollment date, ties by student_id
// ============================================================
$policy = new SiblingDiscountPolicy();

$siblings = [
    ['student_id' => 30, 'enrollment_date' => '2024-09-15'],
    ['student_id' => 10, 'enrollment_date' => '2023-09-01'],
    ['student_id' => 20, 'enrollment_date' => '2024-09-01'],
];
$ordered = $policy->orderSiblings($siblings);
$assert($ordered[0]['student_id'] === 10, 'sibling order: oldest first (student 10, 2023)');
$assert($ordered[0]['sibling_order'] === 1, 'sibling order: first gets order 1');
$assert($ordered[1]['student_id'] === 20, 'sibling order: second (student 20, 2024-09-01)');
$assert($ordered[1]['sibling_order'] === 2, 'sibling order: second gets order 2');
$assert($ordered[2]['student_id'] === 30, 'sibling order: third (student 30, 2024-09-15)');
$assert($ordered[2]['sibling_order'] === 3, 'sibling order: third gets order 3');

// Ties broken by student_id
$tied = [
    ['student_id' => 50, 'enrollment_date' => '2024-09-01'],
    ['student_id' => 40, 'enrollment_date' => '2024-09-01'],
];
$orderedTied = $policy->orderSiblings($tied);
$assert($orderedTied[0]['student_id'] === 40, 'tie broken by student_id ascending (40 before 50)');

// Stable across "regeneration" (same input → same output)
$reordered = $policy->orderSiblings($siblings);
$assert($reordered[0]['student_id'] === 10, 'stable: regeneration same order');

// Discount computation
$tuition = Money::fromDecimalString('1000.00');
$tiers = [1 => '10.00', 2 => '15.00', 3 => '20.00'];
$d1 = $policy->computeDiscount($tuition, 1, $tiers); // 10% of 1000 = 100
$assert($d1->toDatabaseString() === '100.00', 'discount tier 1 = 100.00');
$d2 = $policy->computeDiscount($tuition, 2, $tiers); // 15% = 150
$assert($d2->toDatabaseString() === '150.00', 'discount tier 2 = 150.00');
$d3 = $policy->computeDiscount($tuition, 3, $tiers); // 20% = 200
$assert($d3->toDatabaseString() === '200.00', 'discount tier 3 = 200.00');
$d0 = $policy->computeDiscount($tuition, 4, $tiers); // no tier
$assert($d0->isZero(), 'no tier 4 → zero discount');
$rounded = $policy->computeDiscount(Money::fromDecimalString('0.05'), 1, [1 => '10.00']);
$assert($rounded->toDatabaseString() === '0.01', 'percentage calculation rounds half-up without float');
$invalidPercentageRejected = false;
try {
    $policy->computeDiscount($tuition, 1, [1 => '100.01']);
} catch (InvalidArgumentException) {
    $invalidPercentageRejected = true;
}
$assert($invalidPercentageRejected, 'percentage above 100 is rejected');

// ============================================================
// 2. EmployeeChildEligibilityPolicy
// ============================================================
$elig = new EmployeeChildEligibilityPolicy();

$activeRel = ['staff_id' => 5, 'is_active' => true];
$activeContract = ['staff_id' => 5, 'is_active' => true, 'current_work_status' => 'active'];
$assert($elig->isEligible($activeRel, $activeContract), 'eligible: active relationship + active contract');

$inactiveRel = ['staff_id' => 5, 'is_active' => false];
$assert(!$elig->isEligible($inactiveRel, $activeContract), 'ineligible: inactive relationship');

$inactiveContract = ['staff_id' => 5, 'is_active' => false, 'current_work_status' => 'inactive'];
$assert(!$elig->isEligible($activeRel, $inactiveContract), 'ineligible: inactive contract');

$mismatchStaff = ['staff_id' => 6, 'is_active' => true];
$assert(!$elig->isEligible($mismatchStaff, $activeContract), 'ineligible: staff_id mismatch');

$empty = $elig->isEligible([], []);
$assert(!$empty, 'ineligible: empty data');

// ============================================================
// 3. DiscountCombinationPolicy: default no-combine, explicit-combine with cap
// ============================================================
$comb = new DiscountCombinationPolicy();

// Single discount → applied
$single = $comb->resolve([['amount' => Money::fromMinorUnits(5000), 'combinable' => false]]);
$assert($single['applied']->toMinorUnits() === 5000, 'single discount applied');
$assert(!$single['combined'], 'single not combined');

// Two non-combinable → highest-benefit only
$noCombine = $comb->resolve([
    ['amount' => Money::fromMinorUnits(3000), 'combinable' => false, 'priority' => 1],
    ['amount' => Money::fromMinorUnits(5000), 'combinable' => false, 'priority' => 2],
]);
$assert($noCombine['applied']->toMinorUnits() === 5000, 'no-combine: highest-benefit (5000)');
$assert(!$noCombine['combined'], 'no-combine: not combined');

// Two combinable with cap → sum capped
$withCap = $comb->resolve([
    ['amount' => Money::fromMinorUnits(3000), 'combinable' => true, 'cap_amount' => Money::fromMinorUnits(7000)],
    ['amount' => Money::fromMinorUnits(5000), 'combinable' => true, 'cap_amount' => Money::fromMinorUnits(7000)],
]);
$assert($withCap['applied']->toMinorUnits() === 7000, 'combine with cap: 3000+5000=8000 capped to 7000');
$assert($withCap['combined'], 'combine: combined=true');

// Two combinable without cap → rejected (an explicit cap is mandatory)
$missingCapRejected = false;
try {
    $comb->resolve([
        ['amount' => Money::fromMinorUnits(3000), 'combinable' => true, 'cap_amount' => null],
        ['amount' => Money::fromMinorUnits(5000), 'combinable' => true, 'cap_amount' => null],
    ]);
} catch (InvalidArgumentException) {
    $missingCapRejected = true;
}
$assert($missingCapRejected, 'combine without an explicit cap is rejected');

// One combinable + one not → no-combine (highest only)
$mixed = $comb->resolve([
    ['amount' => Money::fromMinorUnits(3000), 'combinable' => true, 'cap_amount' => null],
    ['amount' => Money::fromMinorUnits(5000), 'combinable' => false, 'cap_amount' => null],
]);
$assert(!$mixed['combined'], 'mixed: not combined (one not combinable)');
$assert($mixed['applied']->toMinorUnits() === 5000, 'mixed: highest-benefit 5000');

// Empty → zero
$empty2 = $comb->resolve([]);
$assert($empty2['applied']->isZero(), 'empty: zero discount');

// ============================================================
// Result
// ============================================================
if ($failures > 0) {
    echo "\n$failures FAILURES\n";
    exit(1);
}
echo "\nAll US2 discount policies contract tests passed.\n";
exit(0);
