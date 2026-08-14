<?php

declare(strict_types=1);

$root = dirname(__DIR__);
$app = (string) file_get_contents($root . '/teams/app.html');
$handler = (string) file_get_contents($root . '/auth/teams_token_handler.php');
$sso = (string) file_get_contents($root . '/classes/MicrosoftSSO.php');

assert(str_contains($app, 'startAutomaticLogin();'));
assert(str_contains($app, 'microsoftTeams.authentication.getAuthToken()'));
assert(str_contains($app, "method: 'POST'"));
assert(str_contains($app, "credentials: 'include'"));
assert(str_contains($app, 'withTimeout'));
assert(str_contains($app, 'AbortController'));
assert(str_contains($app, 'automaticLoginTimeoutMs'));
assert(!str_contains($app, 'request_sso'));
assert(!str_contains($app, "postMessage("));

assert(str_contains($handler, "REQUEST_METHOD'] !== 'POST'"));
assert(str_contains($handler, 'resolveLinkedMicrosoftLoginUser'));
assert(str_contains($handler, "loginUser(\$user, \$microsoftUser, false)"));
assert(!str_contains($handler, "\$_GET['token']"));
assert(!str_contains($handler, "\$_GET['stage']"));

assert(str_contains($sso, 'resolveLinkedMicrosoftLoginUser'));
assert(str_contains($sso, 'microsoftEmailMatchesAccount'));

echo "TEAMS_AUTO_SSO_CONTRACT_TEST_PASSED\n";
