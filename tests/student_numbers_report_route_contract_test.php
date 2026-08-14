<?php

declare(strict_types=1);

$root = dirname(__DIR__);

require_once $root . '/classes/AdminRolePageCatalog.php';
require_once $root . '/classes/FinanceLegacyAdapter.php';

$studentAffairsPages = AdminRolePageCatalog::customizablePages(
    AdminRolePageCatalog::STUDENT_AFFAIRS_MANAGER
);
$legacyExpansion = AdminRolePageCatalog::expandWithDependencies(['school_budget.php']);
$canonicalExpansion = AdminRolePageCatalog::expandWithDependencies(['student_numbers_reports.php']);
$legacyFinanceContract = FinanceLegacyAdapter::contract('school_budget.php');
$canonicalFinanceContract = FinanceLegacyAdapter::contract('student_numbers_reports.php');

$header = (string) file_get_contents($root . '/includes/admin_header.php');
$legacyEntrypoint = (string) file_get_contents($root . '/admin/school_budget.php');
$migration = (string) file_get_contents(
    $root . '/database/migrations/20260807_rename_school_budget_report_permission.php'
);

$checks = [
    'student affairs catalog uses canonical report page' => in_array('student_numbers_reports.php', $studentAffairsPages, true)
        && !in_array('school_budget.php', $studentAffairsPages, true),
    'legacy stored grant keeps both protected urls reachable' => in_array('school_budget.php', $legacyExpansion, true)
        && in_array('student_numbers_reports.php', $legacyExpansion, true),
    'canonical stored grant keeps compatibility redirect reachable' => in_array('school_budget.php', $canonicalExpansion, true)
        && in_array('student_numbers_reports.php', $canonicalExpansion, true),
    'legacy url is hidden from independent role editing' => AdminRolePageCatalog::isSupportingPage('school_budget.php')
        && !AdminRolePageCatalog::isSupportingPage('student_numbers_reports.php'),
    'finance compatibility alias retains the old contract' => $legacyFinanceContract === $canonicalFinanceContract
        && ($canonicalFinanceContract['target'] ?? '') === 'finance_budgets.php',
    'sidebar and legacy entrypoint route to canonical url' => strpos($header, "href=\"student_numbers_reports.php\"") !== false
        && strpos($legacyEntrypoint, "Location: student_numbers_reports.php") !== false,
    'migration canonicalizes persisted grants without duplicate failures' => strpos($migration, "DELETE legacy_permission") !== false
        && strpos($migration, "SET page_name = 'student_numbers_reports.php'") !== false
        && strpos($migration, "WHERE page_name = 'school_budget.php'") !== false
        && strpos($migration, '$db->beginTransaction();') !== false
        && strpos($migration, '$db->rollBack();') !== false,
];

$failed = array_keys(array_filter($checks, static fn (bool $passed): bool => !$passed));
if ($failed !== []) {
    fwrite(STDERR, "STUDENT_NUMBERS_REPORT_ROUTE_CONTRACT_FAILED\n" . implode("\n", $failed) . "\n");
    exit(1);
}

echo "STUDENT_NUMBERS_REPORT_ROUTE_CONTRACT_PASSED\n";
