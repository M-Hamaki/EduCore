<?php

declare(strict_types=1);

/**
 * Contract test for Money and SignedMoneyDelta value objects.
 *
 * Verifies: integer piaster minor units, half-up rounding at presentation,
 * no float drift, immutability, add/subtract/multiply, signed deltas.
 *
 * Run: php tests/finance_money_contract_test.php
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

// === Money: creation ===
$m0 = Money::zero();
$assert($m0->toMinorUnits() === 0, 'zero minor units');
$assert($m0->isZero(), 'zero isZero');
$assert($m0->toDatabaseString() === '0.00', 'zero db string');

$m1 = Money::fromMinorUnits(10050); // 100.50 EGP
$assert($m1->toMinorUnits() === 10050, '10050 minor units');
$assert($m1->toDatabaseString() === '100.50', '100.50 db string');
$assert($m1->toDisplayString() === '100.50 EGP', '100.50 display');
$assert($m1->getCurrency() === 'EGP', 'currency EGP');

$m2 = Money::fromDecimalString('250.75');
$assert($m2->toMinorUnits() === 25075, '250.75 -> 25075 minor');
$assert($m2->toDatabaseString() === '250.75', '250.75 db');

$m3 = Money::fromDecimalString('0.01'); // 1 piaster
$assert($m3->toMinorUnits() === 1, '0.01 -> 1 minor');

$m4 = Money::fromDecimalString('1000'); // no decimal
$assert($m4->toMinorUnits() === 100000, '1000 -> 100000 minor');
$assert($m4->toDatabaseString() === '1000.00', '1000.00 db');

// === Money: negative rejected ===
$threwNeg = false;
try {
    Money::fromMinorUnits(-1);
} catch (\InvalidArgumentException) {
    $threwNeg = true;
}
$assert($threwNeg, 'negative minor rejected');

$threwNegStr = false;
try {
    Money::fromDecimalString('-50.00');
} catch (\InvalidArgumentException) {
    $threwNegStr = true;
}
$assert($threwNegStr, 'negative decimal string rejected');

// === Money: arithmetic ===
$sum = $m1->add($m2); // 100.50 + 250.75 = 351.25
$assert($sum->toMinorUnits() === 35125, 'add: 10050+25075=35125');
$assert($sum->toDatabaseString() === '351.25', 'add db 351.25');

$diff = $m1->subtract($m3); // 100.50 - 0.01 = 100.49
$assert($diff->toMinorUnits() === 10049, 'subtract: 10050-1=10049');
$assert($diff->toDatabaseString() === '100.49', 'subtract db 100.49');

// Subtract resulting in negative throws
$threwSub = false;
try {
    $m3->subtract($m1); // 0.01 - 100.50 -> negative
} catch (\InvalidArgumentException) {
    $threwSub = true;
}
$assert($threwSub, 'subtract negative rejected');

// Multiply
$mul = Money::fromMinorUnits(1000)->multiply(3); // 10.00 * 3 = 30.00
$assert($mul->toMinorUnits() === 3000, 'multiply: 1000*3=3000');
$assert($mul->toDatabaseString() === '30.00', 'multiply db 30.00');

// === Money: comparisons ===
$assert($m1->equals(Money::fromMinorUnits(10050)), 'equals true');
$assert(!$m1->equals($m2), 'equals false');
$assert($m2->greaterThan($m1), '250.75 > 100.50');
$assert($m1->greaterThanOrEqual($m1), '>=');
$assert($m1->compareTo($m2) < 0, 'compareTo m1 < m2');
$assert($m2->compareTo($m1) > 0, 'compareTo m2 > m1');
$assert($m1->compareTo($m1) === 0, 'compareTo equal');

// === Money: immutability ===
$original = Money::fromMinorUnits(5000);
$added = $original->add(Money::fromMinorUnits(1000));
$assert($original->toMinorUnits() === 5000, 'immutability: original unchanged after add');
$assert($added->toMinorUnits() === 6000, 'immutability: add returns new');

// === No float drift test ===
// 0.10 + 0.20 should be exactly 0.30, not 0.30000000000000004
$a = Money::fromDecimalString('0.10');
$b = Money::fromDecimalString('0.20');
$c = $a->add($b);
$assert($c->toMinorUnits() === 30, 'no float drift: 0.10+0.20=0.30 (30 minor)');
$assert($c->toDatabaseString() === '0.30', 'no float drift db string');

// === SignedMoneyDelta ===
$d1 = SignedMoneyDelta::fromMinorUnits(500); // +5.00
$assert($d1->toMinorUnits() === 500, 'delta +500');
$assert($d1->isPositive(), 'delta positive');
$assert(!$d1->isNegative(), 'delta not negative');
$assert($d1->toDatabaseString() === '5.00', 'delta +5.00 db');

$d2 = SignedMoneyDelta::fromMinorUnits(-300); // -3.00
$assert($d2->toMinorUnits() === -300, 'delta -300');
$assert($d2->isNegative(), 'delta negative');
$assert(!$d2->isPositive(), 'delta not positive');
$assert($d2->toDatabaseString() === '-3.00', 'delta -3.00 db');

$d3 = SignedMoneyDelta::fromDecimalString('-100.50');
$assert($d3->toMinorUnits() === -10050, 'delta -100.50 -> -10050');

$d4 = SignedMoneyDelta::fromDecimalString('250.00');
$assert($d4->toMinorUnits() === 25000, 'delta +250.00 -> 25000');

// Delta arithmetic
$dSum = $d1->add($d2); // +500 + (-300) = +200
$assert($dSum->toMinorUnits() === 200, 'delta add: 500+(-300)=200');

$dNeg = $d1->negate(); // -500
$assert($dNeg->toMinorUnits() === -500, 'delta negate: 500 -> -500');

$dZero = SignedMoneyDelta::zero();
$assert($dZero->isZero(), 'delta zero');
$assert($dZero->toMinorUnits() === 0, 'delta zero minor');

// === SignedMoneyDelta: original + reversal = 0 ===
$original = SignedMoneyDelta::fromMinorUnits(1000);
$reversal = $original->negate();
$net = $original->add($reversal);
$assert($net->isZero(), 'original + reversal = 0');

// === Result ===
if ($failures > 0) {
    echo "\n$failures FAILURES\n";
    exit(1);
}
echo "\nAll Money + SignedMoneyDelta contract tests passed.\n";
exit(0);
