<?php

declare(strict_types=1);

$root = dirname(__DIR__);
$index = (string) file_get_contents($root . '/index.php');
$login = (string) file_get_contents($root . '/login.php');
$legacyPortal = (string) file_get_contents($root . '/public_portal.php');

assert(str_contains($index, "\$_GET['stage'] ?? 'kindergarten'"));
assert(str_contains($index, "\$_GET['from_teams'] ?? ''"));
assert(str_contains($login, "REQUEST_METHOD'] !== 'POST'"));
assert(!str_contains($login, 'stage_selected'));
assert(str_contains($legacyPortal, "header('Location: index.php?'"));
assert(str_contains($legacyPortal, "unified_access_portal_enabled"));

echo "LEGACY_PORTAL_ROUTES_TEST_PASSED\n";
