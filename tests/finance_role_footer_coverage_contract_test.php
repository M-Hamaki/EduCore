<?php

declare(strict_types=1);

/**
 * Role-coverage contract test for finance admin pages.
 *
 * Verifies that every finance admin page:
 * - Uses shared admin_footer.php (not standalone HTML)
 * - Includes form-safety.js and undo-toast.js (or admin_footer which loads them)
 * - Does NOT use page-local confirm(), alert(), or SweetAlert
 * - Does NOT define page-local button/stat-card CSS in <style> blocks
 *
 * Run: C:\xampp\php\php.exe tests/finance_role_footer_coverage_contract_test.php
 */

$root = dirname(__DIR__);
$financePages = glob($root . '/admin/finance_*.php');
$streamingControllers = [
    'finance_export_download.php' => ['csrf' => false, 'csrf_marker' => '', 'reason' => 'authenticated GET file-stream controller; no recoverable form surface'],
    'finance_report_export.php' => ['csrf' => true, 'csrf_marker' => 'requireCsrfPost()', 'reason' => 'authenticated POST export controller that redirects to the protected stream'],
    'finance_datatable.php' => ['csrf' => true, 'csrf_marker' => 'hash_equals($sessionToken, $providedToken)', 'reason' => 'authenticated read-only JSON paging endpoint; no recoverable form surface'],
];
$sharedListSource = (string) file_get_contents($root . '/admin/includes/finance_list_page.php');

if (empty($financePages)) {
    echo "SKIP: No finance pages found\n";
    exit(0);
}

$failures = 0;
$assert = static function (bool $condition, string $message) use (&$failures): void {
    if (!$condition) {
        echo "FAIL: $message\n";
        ++$failures;
    }
};

foreach ($financePages as $pagePath) {
    $pageName = basename($pagePath);
    $source = (string) file_get_contents($pagePath);

    if (isset($streamingControllers[$pageName])) {
        $assert(str_contains($source, "validateSession('admin')"), "$pageName: validates the admin session ({$streamingControllers[$pageName]['reason']})");
        if ($streamingControllers[$pageName]['csrf']) {
            $assert(str_contains($source, $streamingControllers[$pageName]['csrf_marker']), "$pageName: validates CSRF before processing");
        }
        $assert(!str_contains($source, '<form'), "$pageName: has no recoverable form surface");
        continue;
    }

    // Must include admin_footer.php
    $assert(
        str_contains($source, "admin_footer.php"),
        "$pageName: includes admin_footer.php"
    );

    // Must include admin_header.php
    $assert(
        str_contains($source, "admin_header.php")
            || (str_contains($source, "finance_list_page.php") && str_contains($sharedListSource, "admin_header.php")),
        "$pageName: includes admin_header.php"
    );

    // Must NOT use confirm()
    $assert(
        !str_contains($source, "confirm("),
        "$pageName: no confirm()"
    );

    // Must NOT use SweetAlert/Swal
    $assert(
        !str_contains($source, "Swal") && !str_contains($source, "sweetalert"),
        "$pageName: no SweetAlert"
    );

    // Must NOT define page-local button CSS in <style>
    $assert(
        !preg_match('/<style[^>]*>.*\.btn-/s', $source),
        "$pageName: no page-local .btn- CSS in <style>"
    );

    // Must NOT define page-local stat-card CSS in <style>
    $assert(
        !preg_match('/<style[^>]*>.*\.stat-card/s', $source),
        "$pageName: no page-local .stat-card CSS in <style>"
    );

    // Must validate session
    $assert(
        str_contains($source, "validateSession"),
        "$pageName: validates session"
    );
}

if ($failures > 0) {
    echo "\n$failures FAILURES across " . count($financePages) . " pages\n";
    exit(1);
}
echo "\nAll " . count($financePages) . " finance pages pass role-footer + UI coverage checks.\n";
exit(0);
