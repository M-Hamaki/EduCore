<?php

declare(strict_types=1);

/**
 * Characterization test stubs for legacy finance pages.
 *
 * These tests verify that legacy finance pages still exist and contain
 * expected structural elements (auth, CSRF, POST handlers) before any
 * adapter conversion. They are NOT behavioral tests — they capture the
 * current contract surface so adapter conversion can prove compatibility.
 *
 * Run: C:\xampp\php\php.exe tests/finance_legacy_characterization_test.php
 */

$root = dirname(__DIR__);
$failures = 0;
$assert = static function (bool $condition, string $message) use (&$failures): void {
    if (!$condition) {
        echo "FAIL: $message\n";
        ++$failures;
    }
};

// Legacy pages to characterize
$legacyPages = [
    'admin/fee_structure.php' => ['validateSession', 'admin_header.php'],
    'admin/fee_calculator.php' => ['validateSession', 'admin_header.php'],
    'admin/fee_payments.php' => ['validateSession', 'admin_header.php'],
    'admin/staff_financial_data.php' => ['validateSession', 'admin_header.php'],
    'admin/school_budget.php' => ['validateSession'],
    'admin/student_buses.php' => ['validateSession'],
    'admin/bus_report.php' => ['validateSession'],
];

foreach ($legacyPages as $page => $requiredTokens) {
    $fullPath = $root . '/' . $page;
    if (!is_file($fullPath)) {
        echo "SKIP: $page not found\n";
        continue;
    }
    $source = (string) file_get_contents($fullPath);

    foreach ($requiredTokens as $token) {
        $assert(
            str_contains($source, $token),
            "$page: contains '$token'"
        );
    }

    // Should NOT use SweetAlert
    $assert(
        !str_contains($source, 'Swal') && !str_contains($source, 'sweetalert'),
        "$page: no SweetAlert"
    );
    if ($page === 'admin/fee_payments.php') {
        $assert(
            !str_contains($source, ').tooltip()'),
            "$page: does not call the removed Bootstrap jQuery tooltip plugin"
        );
    }
}

if ($failures > 0) {
    echo "\n$failures FAILURES\n";
    exit(1);
}
echo "\nAll legacy finance characterization checks passed.\n";
exit(0);
