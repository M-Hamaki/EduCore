<?php
declare(strict_types=1);
/**
 * T063: payroll GL entry test + T076: account-mapping resolution test + T087: adapter compatibility test.
 * Combined to reduce file count.
 * Run: C:\xampp\php\php.exe tests/finance_payroll_gl_mapping_adapter_contract_test.php
 */
require_once __DIR__ . '/bootstrap_finance.php';
require_once __DIR__ . '/../classes/FinanceAuthorization.php';
require_once __DIR__ . '/../classes/FinanceLegacyAdapter.php';

use EduCore\Modules\Finance\Domain\Money;
use EduCore\Modules\Finance\Domain\Policy\AccountMappingPolicy;
use EduCore\Modules\Finance\Domain\Policy\PayrollCalculationPolicy;

$failures = 0;
$assert = static function (bool $cond, string $msg) use (&$failures): void {
    if (!$cond) { echo "FAIL: $msg\n"; ++$failures; }
};

// T063: PayrollCalculationPolicy + multi-component
$calc = new PayrollCalculationPolicy();
$components = [
    ['amount' => Money::fromDecimalString('5000.00'), 'direction' => 'earning'],
    ['amount' => Money::fromDecimalString('500.00'), 'direction' => 'deduction'],
];
$result = $calc->compute($components);
$assert($result['gross']->toDatabaseString() === '5000.00', 'payroll gross = 5000');
$assert($result['net']->toDatabaseString() === '4500.00', 'payroll net = 4500');
$assert($result['total_deductions']->toDatabaseString() === '500.00', 'payroll deductions = 500');

// T076: AccountMappingPolicy resolution
$policy = new AccountMappingPolicy();
$resolved = $policy->resolve([
    ['specificity_score' => 10, 'priority' => 5, 'version_number' => 1, 'debit_account_id' => 100, 'credit_account_id' => 200],
    ['specificity_score' => 20, 'priority' => 1, 'version_number' => 1, 'debit_account_id' => 300, 'credit_account_id' => 400],
]);
$assert($resolved['specificity_score'] === 20, 'mapping: higher specificity wins');

$threw = false;
try { $policy->resolve([]); } catch (\RuntimeException) { $threw = true; }
$assert($threw, 'mapping: zero matches throws');

// T087: Adapter compatibility
$assert(!FinanceLegacyAdapter::shouldHandle(), 'adapter: off mode → shouldHandle=false');
$assert(!FinanceLegacyAdapter::shouldShadow(), 'adapter: off mode → shouldShadow=false');
$assert(FinanceLegacyAdapter::balancesMatch('100.00', '100.00'), 'adapter: balances match');
$assert(!FinanceLegacyAdapter::balancesMatch('100.00', '100.01'), 'adapter: mismatch detected');

if ($failures > 0) { echo "\n$failures FAILURES\n"; exit(1); }
echo "\nAll payroll GL + account-mapping + adapter compatibility contract tests passed.\n";
exit(0);
