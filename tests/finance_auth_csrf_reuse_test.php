<?php

declare(strict_types=1);

/**
 * Auth/CSRF reuse verification test (T012).
 *
 * Verifies that every finance admin page:
 * - Calls Utilities::validateSession('admin')
 * - Does not call session_start() directly (uses session_config.php)
 * - Finance pages with POST handlers use requireCsrfPost() or hash_equals
 *
 * Run: php tests/finance_auth_csrf_reuse_test.php
 */

$root = dirname(__DIR__);
$financePages = glob($root . '/admin/finance_*.php');

$failures = 0;
$assert = static function (bool $cond, string $msg) use (&$failures): void {
    if (!$cond) { echo "FAIL: $msg\n"; ++$failures; }
};

foreach ($financePages as $pagePath) {
    $pageName = basename($pagePath);
    $source = (string) file_get_contents($pagePath);

    // Must call validateSession
    $assert(str_contains($source, 'validateSession'), "$pageName: calls validateSession");

    // Must NOT call session_start() directly
    $assert(!preg_match('/^\s*session_start\s*\(/m', $source), "$pageName: no direct session_start()");

    // Pages with POST handling must use CSRF
    $hasPost = str_contains($source, '$_POST') || str_contains($source, 'REQUEST_METHOD');
    if ($hasPost) {
        $hasCsrf = str_contains($source, 'csrf') || str_contains($source, 'requireCsrfPost') || str_contains($source, 'hash_equals');
        $assert($hasCsrf, "$pageName: POST handler has CSRF check");
    }

    // Must NOT use display_errors
    $assert(!str_contains($source, 'display_errors'), "$pageName: no display_errors");
}

if ($failures > 0) {
    echo "\n$failures FAILURES\n";
    exit(1);
}
echo "\nAll " . count($financePages) . " finance pages pass auth/CSRF reuse checks.\n";
exit(0);
