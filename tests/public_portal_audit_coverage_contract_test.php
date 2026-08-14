<?php

declare(strict_types=1);

$root = dirname(__DIR__);
$classifications = require $root . '/tools/audit_write_coverage_classifications.php';
$settingsAdapter = (string) file_get_contents($root . '/admin/public_portal_settings.php');

assert(!isset($classifications['admin/public_portal_settings.php']));
assert(!str_contains($settingsAdapter, "REQUEST_METHOD'] === 'POST'"));
assert(str_contains($settingsAdapter, 'materials_center.php'));

echo "PUBLIC_PORTAL_AUDIT_COVERAGE_CONTRACT_TEST_PASSED\n";
