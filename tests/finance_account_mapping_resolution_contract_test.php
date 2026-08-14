<?php

declare(strict_types=1);

require_once __DIR__ . '/bootstrap_finance.php';

use EduCore\Modules\Finance\Domain\Policy\AccountMappingPolicy;

$failures = 0;
$assert = static function (bool $condition, string $message) use (&$failures): void {
    if (!$condition) { fwrite(STDERR, "FAIL: {$message}\n"); ++$failures; }
};
$policy = new AccountMappingPolicy();

$specific = $policy->resolve([
    ['specificity_score' => 0, 'priority' => 999, 'version_number' => 9, 'debit_account_id' => 1, 'credit_account_id' => 2],
    ['specificity_score' => 2, 'priority' => 1, 'version_number' => 1, 'debit_account_id' => 3, 'credit_account_id' => 4],
]);
$assert((int) $specific['debit_account_id'] === 3, 'specific override beats a higher-priority general mapping');

$priority = $policy->resolve([
    ['specificity_score' => 2, 'priority' => 1, 'version_number' => 9, 'debit_account_id' => 1, 'credit_account_id' => 2],
    ['specificity_score' => 2, 'priority' => 5, 'version_number' => 1, 'debit_account_id' => 3, 'credit_account_id' => 4],
]);
$assert((int) $priority['debit_account_id'] === 3, 'priority breaks ties after specificity');

$version = $policy->resolve([
    ['specificity_score' => 2, 'priority' => 5, 'version_number' => 1, 'debit_account_id' => 1, 'credit_account_id' => 2],
    ['specificity_score' => 2, 'priority' => 5, 'version_number' => 2, 'debit_account_id' => 3, 'credit_account_id' => 4],
]);
$assert((int) $version['debit_account_id'] === 3, 'newest mapping version breaks the final deterministic tie');

$missingRejected = false;
try { $policy->resolve([]); } catch (RuntimeException) { $missingRejected = true; }
$assert($missingRejected, 'zero mapping matches are refused');
$ambiguousRejected = false;
try {
    $policy->resolve([
        ['specificity_score' => 2, 'priority' => 5, 'version_number' => 2, 'debit_account_id' => 1, 'credit_account_id' => 2],
        ['specificity_score' => 2, 'priority' => 5, 'version_number' => 2, 'debit_account_id' => 3, 'credit_account_id' => 4],
    ]);
} catch (RuntimeException) { $ambiguousRejected = true; }
$assert($ambiguousRejected, 'same specificity, priority, and active version is refused as ambiguous');

if ($failures > 0) { fwrite(STDERR, "{$failures} failure(s).\n"); exit(1); }
echo "Finance deterministic account-mapping contract PASSED.\n";
