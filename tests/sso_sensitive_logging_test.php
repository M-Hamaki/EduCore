<?php

declare(strict_types=1);

$root = dirname(__DIR__);
$teamsApp = (string) file_get_contents($root . '/teams/app.html');
$handler = (string) file_get_contents($root . '/auth/teams_token_handler.php');
$microsoftLogin = (string) file_get_contents($root . '/auth/microsoft_login.php');

assert(!str_contains($teamsApp, 'localStorage'));
assert(!str_contains($teamsApp, 'sessionStorage'));
assert(!str_contains($teamsApp, '?token='));
assert(!str_contains($handler, "\$_GET['token']"));
assert(!str_contains($handler, "Decoded:"));
assert(!str_contains($handler, "\$exception->getMessage()"));
assert(!str_contains($microsoftLogin, "error_log('[Microsoft SSO] Redirecting to:"));
assert(!str_contains($microsoftLogin, "\$loginHint ??"));

echo "SSO_SENSITIVE_LOGGING_TEST_PASSED\n";
