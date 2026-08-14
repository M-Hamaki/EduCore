<?php

declare(strict_types=1);

$root = dirname(__DIR__);
$headerPath = $root . '/includes/admin_header.php';
$source = (string) file_get_contents($headerPath);
$failures = 0;

$assert = static function (bool $condition, string $message) use (&$failures): void {
    if (!$condition) {
        echo "FAIL: {$message}\n";
        ++$failures;
    }
};

$menuStart = strpos($source, 'id="financeMenu"');
$menuEnd = $menuStart === false ? false : strpos($source, '</ul>', $menuStart);
$financeMenu = ($menuStart !== false && $menuEnd !== false)
    ? substr($source, $menuStart, $menuEnd - $menuStart)
    : '';

$assert($financeMenu !== '', 'finance sidebar menu exists');

$groupLabels = [
    'نظرة عامة والتحصيل',
    'الرسوم والخصومات',
    'العاملون والرواتب',
    'المحاسبة والرقابة',
    'الصفحات الحالية',
];

foreach ($groupLabels as $label) {
    $assert(str_contains($financeMenu, $label), "finance menu includes group: {$label}");
}

$linkedPages = [
    'finance_dashboard.php',
    'finance_student_accounts.php',
    'finance_receipts.php',
    'finance_refunds.php',
    'finance_debts.php',
    'finance_buses.php',
    'finance_fee_plans.php',
    'finance_discounts.php',
    'finance_discount_awards.php',
    'finance_staff_contracts.php',
    'finance_payroll_runs.php',
    'finance_payroll_items.php',
    'finance_payroll_payments.php',
    'finance_staff_advances.php',
    'finance_staff_ledger.php',
    'finance_vouchers.php',
    'finance_cashboxes.php',
    'finance_budgets.php',
    'finance_journal.php',
    'finance_reports.php',
    'finance_approvals.php',
    'finance_periods.php',
    'finance_import_export.php',
    'finance_archive.php',
    'finance_audit_log.php',
    'fee_structure.php',
    'fee_calculator.php',
    'fee_payments.php',
    'staff_financial_data.php',
];

foreach ($linkedPages as $page) {
    $assert(
        str_contains($financeMenu, 'href="' . $page . '"'),
        "finance menu links to {$page}"
    );
    $assert(
        is_file($root . '/admin/' . $page),
        "finance menu target exists: {$page}"
    );
}

if ($failures > 0) {
    echo "{$failures} finance sidebar navigation failure(s).\n";
    exit(1);
}

echo 'Finance sidebar navigation exposes '
    . count($linkedPages)
    . " operational pages in five clear groups.\n";

