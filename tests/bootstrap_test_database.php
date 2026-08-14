<?php

/**
 * Bootstrap for tests that may write or execute runtime schema setup.
 *
 * The database name must be supplied explicitly through EDUCORE_TEST_DB_NAME
 * and must end with `_test`. This prevents an accidental fallback to the
 * application database from turning a test into a production-data mutation.
 */
function educoreTestDatabase(): PDO
{
    $environment = strtolower(trim((string) (getenv('APP_ENV') ?: getenv('ENVIRONMENT') ?: '')));
    if (in_array($environment, ['production', 'prod'], true)) {
        throw new RuntimeException('Refusing database test in a production environment.');
    }

    $testDatabase = trim((string) (getenv('EDUCORE_TEST_DB_NAME') ?: ''));
    if ($testDatabase === '' || !preg_match('/^[A-Za-z0-9_]+_test$/', $testDatabase)) {
        throw new RuntimeException(
            'Refusing database-writing test: set EDUCORE_TEST_DB_NAME to an isolated database ending in _test.'
        );
    }

    putenv('DB_NAME=' . $testDatabase);
    $_ENV['DB_NAME'] = $testDatabase;
    $_SERVER['DB_NAME'] = $testDatabase;

    require_once __DIR__ . '/../config/database.php';
    if (DB_NAME !== $testDatabase || DB_NAME === 'educore') {
        throw new RuntimeException('Refusing database-writing test: database isolation guard failed.');
    }

    $db = (new Database())->getConnection();
    if (!$db instanceof PDO) {
        throw new RuntimeException('Unable to connect to the isolated test database.');
    }

    $connectedDatabase = (string) $db->query('SELECT DATABASE()')->fetchColumn();
    if ($connectedDatabase !== $testDatabase) {
        throw new RuntimeException('Refusing database-writing test: connected database does not match the requested test database.');
    }

    return $db;
}
