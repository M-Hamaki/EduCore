<?php

declare(strict_types=1);

require_once __DIR__ . '/../classes/MicrosoftSsoEnvironment.php';

$values = [
    'AZURE_CLIENT_ID' => 'production-client',
    'AZURE_LOCAL_CLIENT_ID' => 'local-client',
    'AZURE_REDIRECT_URI' => 'https://portal.dmls.edu.eg/auth/microsoft_callback.php',
    'AZURE_TEAMS_REDIRECT_URI' => 'https://portal.dmls.edu.eg/auth/teams_sso.php',
    'TEAMS_APP_ID_URI' => 'api://portal.dmls.edu.eg/production-client',
    'AZURE_LOCAL_TEAMS_APP_ID_URI' => 'api://localhost/local-client',
];
$reader = static fn(string $key, string $default = ''): string => $values[$key] ?? $default;

$local = new MicrosoftSsoEnvironment([
    'HTTP_HOST' => 'localhost',
    'SCRIPT_NAME' => '/EduCore/auth/microsoft_login.php',
], $reader);
assert($local->isLocal());
assert($local->name() === 'local');
assert($local->credential('AZURE_CLIENT_ID', 'AZURE_LOCAL_CLIENT_ID') === 'local-client');
assert($local->redirectUri(false) === 'http://localhost/EduCore/auth/microsoft_callback.php');
assert($local->redirectUri(true) === 'http://localhost/EduCore/auth/teams_sso.php');
assert($local->teamsAppIdUri('local-client') === 'api://localhost/local-client');

$localWithPort = new MicrosoftSsoEnvironment([
    'HTTP_HOST' => '127.0.0.1:8080',
    'SCRIPT_NAME' => '/School/auth/microsoft_login.php',
], $reader);
assert($localWithPort->isLocal());
assert($localWithPort->redirectUri(false) === 'http://127.0.0.1:8080/School/auth/microsoft_callback.php');

$production = new MicrosoftSsoEnvironment([
    'HTTP_HOST' => 'portal.dmls.edu.eg',
    'SCRIPT_NAME' => '/auth/microsoft_login.php',
    'HTTPS' => 'on',
], $reader);
assert(!$production->isLocal());
assert($production->name() === 'production');
assert($production->credential('AZURE_CLIENT_ID', 'AZURE_LOCAL_CLIENT_ID') === 'production-client');
assert($production->redirectUri(false) === 'https://portal.dmls.edu.eg/auth/microsoft_callback.php');
assert($production->redirectUri(true) === 'https://portal.dmls.edu.eg/auth/teams_sso.php');
assert($production->teamsAppIdUri('production-client') === 'api://portal.dmls.edu.eg/production-client');

$forcedProductionValues = $values;
$forcedProductionValues['MICROSOFT_SSO_ENV'] = 'production';
$forcedProduction = new MicrosoftSsoEnvironment([
    'HTTP_HOST' => 'localhost',
    'SCRIPT_NAME' => '/EduCore/auth/microsoft_login.php',
], static fn(string $key, string $default = ''): string => $forcedProductionValues[$key] ?? $default);
assert(!$forcedProduction->isLocal());
assert($forcedProduction->redirectUri(false) === 'https://portal.dmls.edu.eg/auth/microsoft_callback.php');

$forcedLocalValues = $values;
$forcedLocalValues['MICROSOFT_SSO_ENV'] = 'local';
$forcedLocalValues['AZURE_LOCAL_REDIRECT_URI'] = 'https://educore-dev.example/auth/microsoft_callback.php';
$forcedLocalValues['AZURE_LOCAL_TEAMS_REDIRECT_URI'] = 'https://educore-dev.example/auth/teams_sso.php';
$forcedLocal = new MicrosoftSsoEnvironment([
    'HTTP_HOST' => 'educore-dev.example',
    'SCRIPT_NAME' => '/auth/microsoft_login.php',
    'HTTPS' => 'on',
], static fn(string $key, string $default = ''): string => $forcedLocalValues[$key] ?? $default);
assert($forcedLocal->isLocal());
assert($forcedLocal->redirectUri(false) === 'https://educore-dev.example/auth/microsoft_callback.php');
assert($forcedLocal->redirectUri(true) === 'https://educore-dev.example/auth/teams_sso.php');

$unsafeForcedLocalValues = $values;
$unsafeForcedLocalValues['MICROSOFT_SSO_ENV'] = 'local';
$unsafeForcedLocal = new MicrosoftSsoEnvironment([
    'HTTP_HOST' => 'unconfigured-tunnel.example',
    'SCRIPT_NAME' => '/auth/microsoft_login.php',
], static fn(string $key, string $default = ''): string => $unsafeForcedLocalValues[$key] ?? $default);
assert($unsafeForcedLocal->redirectUri(false) === '');

$untrustedHost = new MicrosoftSsoEnvironment([
    'HTTP_HOST' => 'attacker.example',
    'SCRIPT_NAME' => '/EduCore/auth/microsoft_login.php',
], $reader);
assert(!$untrustedHost->isLocal());
assert($untrustedHost->redirectUri(false) === 'https://portal.dmls.edu.eg/auth/microsoft_callback.php');

$ssoSource = file_get_contents(__DIR__ . '/../classes/MicrosoftSSO.php');
$teamsHandlerSource = file_get_contents(__DIR__ . '/../auth/teams_token_handler.php');
assert(is_string($ssoSource) && str_contains($ssoSource, "defined('TEAMS_APP_ID_URI') ? TEAMS_APP_ID_URI : null"));
assert(is_string($teamsHandlerSource) && str_contains($teamsHandlerSource, '$applicationPath'));
assert(!str_contains((string) $teamsHandlerSource, "'https://portal.dmls.edu.eg' . \$basePath"));

echo "MICROSOFT_SSO_ENVIRONMENT_TEST_PASSED\n";
