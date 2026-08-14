<?php

declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

$options = getopt('', ['database:', 'reset']);
$databaseName = trim((string) ($options['database'] ?? ''));
if ($databaseName === '' || !preg_match('/^[A-Za-z0-9_]+_test$/', $databaseName) || $databaseName === 'educore') {
    fwrite(STDERR, "Refusing migration: --database must name an isolated database ending in _test.\n");
    exit(2);
}

putenv('APP_ENV=test');
putenv('EDUCORE_TEST_DB_NAME=' . $databaseName);
putenv('DB_NAME=' . $databaseName);
$_ENV['DB_NAME'] = $databaseName;
$_SERVER['DB_NAME'] = $databaseName;

require_once dirname(__DIR__) . '/config/database.php';

if (DB_NAME !== $databaseName || DB_NAME === 'educore') {
    fwrite(STDERR, "Refusing migration: database isolation guard failed.\n");
    exit(2);
}

$db = (new Database())->getConnection();
if (!$db instanceof PDO) {
    fwrite(STDERR, "Unable to connect to the isolated test database.\n");
    exit(1);
}

$connectedDatabase = (string) $db->query('SELECT DATABASE()')->fetchColumn();
if ($connectedDatabase !== $databaseName) {
    fwrite(STDERR, "Refusing migration: connected database does not match --database.\n");
    exit(2);
}

if (array_key_exists('reset', $options)) {
    $quotedDatabase = '`' . str_replace('`', '``', $databaseName) . '`';
    $db->exec('DROP DATABASE ' . $quotedDatabase);
    $db->exec('CREATE DATABASE ' . $quotedDatabase . ' CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci');
    $db->exec('USE ' . $quotedDatabase);
    echo "reset {$databaseName}\n";
}

$migrationFiles = [
    '20260723_finance_core_and_subledger.php',
    '20260723_finance_fee_plans_and_student_charges.php',
    '20260723_finance_discounts.php',
    '20260723_finance_collection.php',
    '20260723_finance_staff_payroll.php',
    '20260723_finance_gl_vouchers_budget.php',
    '20260724_finance_views.php',
    '20260726_finance_approval_workflow.php',
    '20260726_finance_legacy_compatibility.php',
    '20260726_transport_bus_assignment_archive.php',
    '20260728_finance_default_configuration.php',
];

$db->exec(
    'CREATE TABLE IF NOT EXISTS schema_migrations (
        migration VARCHAR(190) PRIMARY KEY,
        applied_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4'
);
$applied = $db->query('SELECT migration FROM schema_migrations')->fetchAll(PDO::FETCH_COLUMN);

foreach ($migrationFiles as $fileName) {
    if (in_array($fileName, $applied, true)) {
        echo "skip {$fileName}\n";
        continue;
    }

    $path = dirname(__DIR__) . '/database/migrations/' . $fileName;
    $migration = require $path;
    if (!is_callable($migration)) {
        throw new RuntimeException('Finance migration must return a callable: ' . $fileName);
    }

    $migration($db);
    $stmt = $db->prepare('INSERT INTO schema_migrations (migration) VALUES (?)');
    $stmt->execute([$fileName]);
    echo "applied {$fileName}\n";
}

echo "Finance migrations completed on {$databaseName}.\n";
