<?php

declare(strict_types=1);

require_once __DIR__ . '/bootstrap_staff_hr.php';

function staffHrGuardRejects(?string $databaseName, ?string $marker, ?string $environment = null): bool
{
    $databaseName === null
        ? putenv('EDUCORE_TEST_DB_NAME')
        : putenv('EDUCORE_TEST_DB_NAME=' . $databaseName);
    $marker === null
        ? putenv('STAFF_HR_TEST_MARKER')
        : putenv('STAFF_HR_TEST_MARKER=' . $marker);
    $environment === null
        ? putenv('APP_ENV')
        : putenv('APP_ENV=' . $environment);

    try {
        staffHrTestDatabase();
    } catch (RuntimeException $error) {
        return true;
    } catch (Throwable $error) {
        // A connection failure after both guards pass is not a guard failure.
        return false;
    }

    return false;
}

$checks = [
    'missing_marker_rejected' => staffHrGuardRejects('educore_test', null),
    'wrong_marker_rejected' => staffHrGuardRejects('educore_test', 'another-suite'),
    'application_database_rejected' => staffHrGuardRejects('educore', 'integrated-staff-hr'),
    'lookalike_database_rejected' => staffHrGuardRejects('educore_testing', 'integrated-staff-hr'),
    'production_environment_rejected' => staffHrGuardRejects(
        'educore_test',
        'integrated-staff-hr',
        'production'
    ),
];

$failed = [];
foreach ($checks as $name => $passed) {
    echo $name . ':' . ($passed ? 'PASS' : 'FAIL') . PHP_EOL;
    if (!$passed) {
        $failed[] = $name;
    }
}

putenv('EDUCORE_TEST_DB_NAME');
putenv('STAFF_HR_TEST_MARKER');
putenv('APP_ENV');
exit($failed ? 1 : 0);

