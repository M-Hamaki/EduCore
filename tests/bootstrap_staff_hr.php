<?php

declare(strict_types=1);

require_once __DIR__ . '/bootstrap_test_database.php';

/**
 * Returns an isolated database for staff-HR write tests.
 *
 * The shared test bootstrap already rejects production environments and any
 * database name that does not end with `_test`. This wrapper adds a feature
 * marker so acceptance tooling cannot silently fall back to the application
 * database or to another test suite's unmarked connection.
 */
function staffHrTestDatabase(): PDO
{
    $marker = trim((string) (getenv('STAFF_HR_TEST_MARKER') ?: ''));
    if ($marker !== 'integrated-staff-hr') {
        throw new RuntimeException(
            'Refusing staff-HR database write: set STAFF_HR_TEST_MARKER=integrated-staff-hr.'
        );
    }

    return educoreTestDatabase();
}

