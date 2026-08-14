<?php

declare(strict_types=1);

$root = dirname(__DIR__);
$workflow = (string) file_get_contents($root . '/src/Modules/Finance/Application/FinanceApprovalWorkflowService.php');
$expected = [
    'receipt_reverse', 'refund_unapplied_credit', 'refund_allocation', 'refund_reverse',
    'debt_write_off', 'advance_write_off', 'manual_journal_post', 'manual_journal_reverse',
    'import_post', 'import_reverse', 'payroll_approve', 'payroll_finalize', 'payroll_item_post',
    'payroll_payment_post', 'payroll_payment_reverse', 'payroll_run_reverse', 'period_reopen',
    'discount_award_approve', 'voucher_post', 'voucher_reverse',
];
$failures = 0;
foreach ($expected as $operation) {
    if (!str_contains($workflow, "'{$operation}'")) { fwrite(STDERR, "FAIL: approval workflow missing {$operation}.\n"); ++$failures; }
}
$pageOperations = [
    'admin/finance_receipts.php' => ['receipt_reverse'],
    'admin/finance_refunds.php' => ['refund_allocation', 'refund_unapplied_credit', 'refund_reverse'],
    'admin/finance_debts.php' => ['debt_write_off'],
    'admin/finance_staff_advances.php' => ['advance_write_off'],
    'admin/finance_journal.php' => ['manual_journal_post', 'manual_journal_reverse'],
    'admin/finance_import_export.php' => ['import_post', 'import_reverse'],
    'admin/finance_payroll_runs.php' => ['payroll_approve', 'payroll_finalize', 'payroll_run_reverse'],
    'admin/finance_payroll_items.php' => ['payroll_item_post', 'payroll_payment_post'],
    'admin/finance_payroll_payments.php' => ['payroll_payment_reverse'],
    'admin/finance_periods.php' => ['period_reopen'],
    'admin/finance_discount_awards.php' => ['discount_award_approve'],
    'admin/finance_vouchers.php' => ['voucher_post', 'voucher_reverse'],
];
foreach ($pageOperations as $path => $operations) {
    $source = (string) file_get_contents($root . '/' . $path);
    foreach ($operations as $operation) {
        if (!str_contains($source, "request('{$operation}'")) { fwrite(STDERR, "FAIL: {$path} does not request {$operation}.\n"); ++$failures; }
    }
}
$serviceTests = ['finance_receipt_reversal_contract_test.php', 'finance_refund_signed_contract_test.php', 'finance_student_debt_writeoff_contract_test.php', 'finance_manual_journal_integration_test.php', 'finance_import_staging_contract_test.php', 'finance_staff_financial_integration_test.php', 'finance_period_lifecycle_integration_test.php', 'finance_discount_service_integration_test.php', 'finance_voucher_gl_integration_test.php'];
foreach ($serviceTests as $test) { if (!is_file($root . '/tests/' . $test)) { fwrite(STDERR, "FAIL: missing focused sensitive-operation test {$test}.\n"); ++$failures; } }
if ($failures > 0) { exit(1); }
echo 'Finance sensitive approval coverage contract PASSED for ' . count($expected) . " operations.\n";
