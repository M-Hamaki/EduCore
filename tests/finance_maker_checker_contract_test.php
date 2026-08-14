<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/src/Modules/Finance/Domain/FinanceAuthorization.php';

use EduCore\Modules\Finance\Domain\FinanceAuthorization;

$failures = 0;
$assert = static function (bool $condition, string $message) use (&$failures): void {
    if (!$condition) {
        fwrite(STDERR, "FAIL: {$message}\n");
        ++$failures;
    }
};

foreach ([
    'discount_approve',
    'receipt_reverse',
    'refund_reverse',
    'debt_write_off',
    'debt_write_off_reverse',
    'payroll_post',
    'payroll_reverse',
    'advance_write_off',
    'voucher_post',
    'period_close',
    'period_reopen',
    'import_post',
    'budget_approve',
] as $operation) {
    $assert(FinanceAuthorization::requiresMakerChecker($operation), "{$operation} requires maker-checker");

    $rejected = false;
    try {
        FinanceAuthorization::assertMakerChecker($operation, 7, 7);
    } catch (RuntimeException) {
        $rejected = true;
    }
    $assert($rejected, "{$operation} rejects self-approval");

    FinanceAuthorization::assertMakerChecker($operation, 7, 8);
}

$assert(!FinanceAuthorization::requiresMakerChecker('receipt_post'), 'ordinary receipt posting does not self-require approval');
$assert(FinanceAuthorization::permissionFor('period_reopen') === 'finance.periods.reopen', 'permission matrix is stable');

$unknownRejected = false;
try {
    FinanceAuthorization::permissionFor('not-a-finance-operation');
} catch (InvalidArgumentException) {
    $unknownRejected = true;
}
$assert($unknownRejected, 'unknown operations fail closed');

if ($failures > 0) {
    fwrite(STDERR, "{$failures} maker-checker contract failure(s).\n");
    exit(1);
}

echo "Finance maker-checker contracts passed.\n";
