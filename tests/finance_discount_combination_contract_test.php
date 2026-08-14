<?php

declare(strict_types=1);

require_once __DIR__ . '/bootstrap_finance.php';

use EduCore\Modules\Finance\Domain\Money;
use EduCore\Modules\Finance\Domain\Policy\DiscountCombinationPolicy;

$policy = new DiscountCombinationPolicy();
$highestOnly = $policy->resolve([
    ['amount' => Money::fromDecimalString('30.00'), 'combinable' => false, 'priority' => 1],
    ['amount' => Money::fromDecimalString('50.00'), 'combinable' => false, 'priority' => 2],
]);
$combined = $policy->resolve([
    ['amount' => Money::fromDecimalString('30.00'), 'combinable' => true, 'cap_amount' => Money::fromDecimalString('70.00')],
    ['amount' => Money::fromDecimalString('50.00'), 'combinable' => true, 'cap_amount' => Money::fromDecimalString('70.00')],
]);

$missingCapRejected = false;
try {
    $policy->resolve([
        ['amount' => Money::fromDecimalString('30.00'), 'combinable' => true],
        ['amount' => Money::fromDecimalString('50.00'), 'combinable' => true],
    ]);
} catch (InvalidArgumentException) {
    $missingCapRejected = true;
}

if ($highestOnly['combined'] || !$highestOnly['applied']->equals(Money::fromDecimalString('50.00'))
    || !$combined['combined'] || !$combined['applied']->equals(Money::fromDecimalString('70.00'))
    || !$missingCapRejected) {
    fwrite(STDERR, "FAILED: default no-combine or explicit-combine-with-cap contract.\n");
    exit(1);
}

echo "Discount combination contract test PASSED.\n";
