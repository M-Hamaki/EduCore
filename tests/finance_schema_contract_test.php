<?php

declare(strict_types=1);

/**
 * Source-level guard for the Finance foundation migrations.
 *
 * Database integration tests still apply the migrations to an isolated *_test
 * schema; this test catches contract drift before a database connection is used.
 */

$root = dirname(__DIR__);
$migration = static function (string $name) use ($root): string {
    $path = $root . '/database/migrations/' . $name;
    $contents = file_get_contents($path);
    if ($contents === false) {
        throw new RuntimeException('Unable to read migration: ' . $path);
    }

    return $contents;
};

$core = $migration('20260723_finance_core_and_subledger.php');
$fees = $migration('20260723_finance_fee_plans_and_student_charges.php');
$discounts = $migration('20260723_finance_discounts.php');
$collection = $migration('20260723_finance_collection.php');
$payroll = $migration('20260723_finance_staff_payroll.php');
$gl = $migration('20260723_finance_gl_vouchers_budget.php');

$failures = 0;
$assertContains = static function (string $needle, string $haystack, string $message) use (&$failures): void {
    if (!str_contains($haystack, $needle)) {
        fwrite(STDERR, "FAIL: {$message}\n");
        ++$failures;
    }
};
$assertNotContains = static function (string $needle, string $haystack, string $message) use (&$failures): void {
    if (str_contains($haystack, $needle)) {
        fwrite(STDERR, "FAIL: {$message}\n");
        ++$failures;
    }
};

foreach ([
    'finance_charge_types',
    'finance_periods',
    'finance_cashboxes',
    'finance_bank_accounts',
    'finance_cashbox_settlements',
    'finance_receipt_number_sequences',
    'finance_import_batches',
    'finance_import_rows',
] as $table) {
    $assertContains("CREATE TABLE `{$table}`", $core, "core migration creates {$table}");
}

$assertContains(
    'UNIQUE KEY `uk_subledger_party_scope_currency` (`party_type`, `party_id`, `scope_key`, `currency`)',
    $core,
    'sub-ledger account uniqueness includes currency'
);
$assertContains("`status` ENUM('draft','posted')", $core, 'sub-ledger transaction status is draft|posted only');
$assertContains('ON DELETE RESTRICT', $core, 'append-only sub-ledger foreign keys restrict deletion');
$assertNotContains('student_ledger', $core, 'no parallel student-specific ledger schema');

foreach ([
    'finance_fee_plans',
    'finance_fee_plan_versions',
    'finance_fee_plan_installments',
    'finance_student_accounts',
    'finance_student_contracts',
    'finance_student_charges',
    'finance_charge_installments',
] as $table) {
    $assertContains("CREATE TABLE `{$table}`", $fees, "fee migration creates {$table}");
}

$assertContains('uk_discount_rule_scope_version', $discounts, 'discount policy versions are scope-unique');

foreach (['finance_payment_allocations', 'finance_unapplied_credits', 'finance_adjustments', 'finance_refunds'] as $table) {
    $assertContains("CREATE TABLE `{$table}`", $collection, "collection migration creates {$table}");
}
if (substr_count($collection, '`signed_amount` DECIMAL(14,2) NOT NULL') < 4) {
    fwrite(STDERR, "FAIL: collection signed movements use signed_amount consistently\n");
    ++$failures;
}
$assertContains("'student_debt_write_off'", $collection, 'student debt write-off has a distinct adjustment source');

$assertContains('CREATE TABLE `staff_advance_movements`', $payroll, 'staff advance movements table exists');
$assertContains("ENUM('cash_repayment','payroll_deduction','write_off')", $payroll, 'advance movement types are distinct');
$assertContains('chk_advance_movement_source', $payroll, 'advance movement source constraints are enforced');

$assertContains('`subledger_transaction_id` INT NULL', $gl, 'journal header links to a party transaction');
$assertContains('UNIQUE KEY `uk_journal_subledger_tx` (`subledger_transaction_id`)', $gl, 'journal/sub-ledger linkage is one-to-one');
$assertContains("`status` ENUM('draft','posted') NOT NULL DEFAULT 'draft'", $gl, 'journal status is draft|posted only');
$assertContains('`source_cashbox_id` INT NULL', $gl, 'cash transfers have a source cashbox');
$assertContains('`destination_cashbox_id` INT NULL', $gl, 'cash transfers have a destination cashbox');
$assertContains('chk_voucher_endpoints', $gl, 'voucher endpoints are constrained');
$assertNotContains('`actual_amount`', $gl, 'budget actuals are not stored independently');

if ($failures > 0) {
    fwrite(STDERR, "{$failures} finance schema contract failure(s).\n");
    exit(1);
}

echo "Finance schema contracts passed.\n";
