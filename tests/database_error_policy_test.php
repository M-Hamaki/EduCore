<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/config/database.php';

$database = new Database();
$reflection = new ReflectionClass($database);

foreach ([
    'host' => '127.0.0.1;port=1',
    'db_name' => 'unreachable_test',
    'username' => 'invalid',
    'password' => 'secret-value',
] as $propertyName => $value) {
    $property = $reflection->getProperty($propertyName);
    $property->setAccessible(true);
    $property->setValue($database, $value);
}

$previousDisplayErrors = ini_get('display_errors');
ini_set('display_errors', '1');
ob_start();
$connection = $database->getConnection();
$output = ob_get_clean();
ini_set('display_errors', (string) $previousDisplayErrors);

$source = file_get_contents(dirname(__DIR__) . '/config/database.php');
$results = [
    'connection_failure_returns_null' => $connection === null,
    'connection_failure_has_no_response_body' => $output === '',
    'database_config_has_no_termination' => !preg_match('/\b(?:die|exit)\s*\(/', (string) $source),
    'database_uses_shared_safe_policy' => strpos((string) $source, "SafeErrorPolicy::report(\$e, 'database.connection')") !== false,
];

$failed = false;
foreach ($results as $name => $passed) {
    echo $name . ':' . ($passed ? 'PASS' : 'FAIL') . PHP_EOL;
    $failed = $failed || !$passed;
}

exit($failed ? 1 : 0);
