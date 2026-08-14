<?php

declare(strict_types=1);

$root = dirname(__DIR__);
$view = (string) file_get_contents($root . '/includes/public_login_portal.php');
$script = (string) file_get_contents($root . '/assets/js/public-portal.js');

assert(str_contains($view, 'lang="ar"') || str_contains((string) file_get_contents($root . '/index.php'), 'lang="ar"'));
assert(str_contains($view, 'aria-labelledby="portal-login-title"'));
assert(str_contains($view, '<label for="portal-username">'));
assert(str_contains($view, '<label for="portal-password">'));
assert(str_contains($view, 'aria-controls="portal-password"'));
assert(str_contains($view, 'aria-pressed="false"'));
assert(str_contains($script, "setAttribute('aria-pressed'"));
assert(!str_contains($view, 'tabindex="-1"'));

echo "PUBLIC_PORTAL_ACCESSIBILITY_CONTRACT_TEST_PASSED\n";
