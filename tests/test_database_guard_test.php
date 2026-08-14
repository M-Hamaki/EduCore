<?php

declare(strict_types=1);

require_once __DIR__ . '/bootstrap_test_database.php';

function guardRejects(?string $databaseName, ?string $environment = null): bool
{
    $databaseName === null
        ? putenv('EDUCORE_TEST_DB_NAME')
        : putenv('EDUCORE_TEST_DB_NAME=' . $databaseName);
    $environment === null
        ? putenv('APP_ENV')
        : putenv('APP_ENV=' . $environment);

    try {
        educoreTestDatabase();
    } catch (RuntimeException $error) {
        return true;
    }

    return false;
}

$results = [
    'missing_name_rejected' => guardRejects(null),
    'application_database_rejected' => guardRejects('educore'),
    'lookalike_name_rejected' => guardRejects('educore_testing'),
    'production_environment_rejected' => guardRejects('educore_test', 'production'),
];

$failed = false;
foreach ($results as $name => $passed) {
    echo $name . ':' . ($passed ? 'PASS' : 'FAIL') . PHP_EOL;
    $failed = $failed || !$passed;
}

putenv('EDUCORE_TEST_DB_NAME');
putenv('APP_ENV');
exit($failed ? 1 : 0);
