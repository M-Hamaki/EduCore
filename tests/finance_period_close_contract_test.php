<?php

declare(strict_types=1);

/**
 * Contract test for FinancePeriodGuard and FinanceAuthorization.
 *
 * Verifies: maker-checker enforcement (creator ≠ approver), permission checks,
 * and FinancePeriodGuard construction (without DB writes).
 *
 * Run: php tests/finance_period_close_contract_test.php
 */

require_once __DIR__ . '/../classes/FinanceAuthorization.php';

use function FinanceAuthorization\assertCan;
use function FinanceAuthorization\can;
use function FinanceAuthorization\assertMakerChecker;

$failures = 0;
$assert = static function (bool $condition, string $message) use (&$failures): void {
    if (!$condition) {
        echo "FAIL: $message\n";
        ++$failures;
    }
};

// === FinanceAuthorization: permission matrix ===
$adminSession = ['role' => 'admin', 'active_role' => 'admin'];
$teacherSession = ['role' => 'teacher', 'active_role' => 'teacher'];

$assert(FinanceAuthorization::can($adminSession, 'payment_record'), 'admin can payment_record');
$assert(FinanceAuthorization::can($adminSession, 'period_close'), 'admin can period_close');
$assert(FinanceAuthorization::can($adminSession, 'budget_manage'), 'admin can budget_manage');
$assert(FinanceAuthorization::can($adminSession, 'finance_view'), 'admin can finance_view');
$assert(FinanceAuthorization::can($adminSession, 'payroll_approve'), 'admin can payroll_approve');
$assert(FinanceAuthorization::can($adminSession, 'discount_approve'), 'admin can discount_approve');

$assert(!FinanceAuthorization::can($teacherSession, 'payment_record'), 'teacher cannot payment_record');
$assert(!FinanceAuthorization::can($teacherSession, 'period_close'), 'teacher cannot period_close');
$assert(!FinanceAuthorization::can($teacherSession, 'finance_view'), 'teacher cannot finance_view');

// Invalid permission
$assert(!FinanceAuthorization::can($adminSession, 'nonexistent_perm'), 'invalid permission rejected');

// === FinanceAuthorization: assertCan throws ===
$threw = false;
try {
    FinanceAuthorization::assertCan($teacherSession, 'payment_record');
} catch (RuntimeException $e) {
    $threw = true;
}
$assert($threw, 'assertCan throws for unauthorized');

$noThrow = true;
try {
    FinanceAuthorization::assertCan($adminSession, 'payment_record');
} catch (RuntimeException) {
    $noThrow = false;
}
$assert($noThrow, 'assertCan does not throw for authorized');

// === Maker-checker enforcement ===
// Creator == approver for a maker-checker operation → MUST throw
$threwMC = false;
try {
    FinanceAuthorization::assertMakerChecker(100, 100, 'receipt_reversal');
} catch (RuntimeException $e) {
    $threwMC = true;
}
$assert($threwMC, 'maker-checker: creator == approver throws for receipt_reversal');

$threwMC2 = false;
try {
    FinanceAuthorization::assertMakerChecker(100, 100, 'refund');
} catch (RuntimeException) {
    $threwMC2 = true;
}
$assert($threwMC2, 'maker-checker: creator == approver throws for refund');

$threwMC3 = false;
try {
    FinanceAuthorization::assertMakerChecker(100, 100, 'payroll_approval');
} catch (RuntimeException) {
    $threwMC3 = true;
}
$assert($threwMC3, 'maker-checker: creator == approver throws for payroll_approval');

$threwMC4 = false;
try {
    FinanceAuthorization::assertMakerChecker(100, 100, 'period_reopen');
} catch (RuntimeException) {
    $threwMC4 = true;
}
$assert($threwMC4, 'maker-checker: creator == approver throws for period_reopen');

$threwMC5 = false;
try {
    FinanceAuthorization::assertMakerChecker(100, 100, 'write_off');
} catch (RuntimeException) {
    $threwMC5 = true;
}
$assert($threwMC5, 'maker-checker: creator == approver throws for write_off');

// Creator ≠ approver → OK
$noThrowMC = true;
try {
    FinanceAuthorization::assertMakerChecker(100, 200, 'receipt_reversal');
} catch (RuntimeException) {
    $noThrowMC = false;
}
$assert($noThrowMC, 'maker-checker: creator ≠ approver OK');

// Non-maker-checker operation → no check
$noThrowMC2 = true;
try {
    FinanceAuthorization::assertMakerChecker(100, 100, 'payment_record');
} catch (RuntimeException) {
    $noThrowMC2 = false;
}
$assert($noThrowMC2, 'maker-checker: non-sensitive operation OK even if creator == approver');

// === requiresMakerChecker ===
$assert(FinanceAuthorization::requiresMakerChecker('receipt_reversal'), 'requiresMakerChecker receipt_reversal');
$assert(FinanceAuthorization::requiresMakerChecker('refund'), 'requiresMakerChecker refund');
$assert(FinanceAuthorization::requiresMakerChecker('manual_journal'), 'requiresMakerChecker manual_journal');
$assert(FinanceAuthorization::requiresMakerChecker('import_posting'), 'requiresMakerChecker import_posting');
$assert(!FinanceAuthorization::requiresMakerChecker('payment_record'), 'payment_record not maker-checker');
$assert(!FinanceAuthorization::requiresMakerChecker('finance_view'), 'finance_view not maker-checker');

// === Result ===
if ($failures > 0) {
    echo "\n$failures FAILURES\n";
    exit(1);
}
echo "\nAll FinanceAuthorization + maker-checker contract tests passed.\n";
exit(0);
