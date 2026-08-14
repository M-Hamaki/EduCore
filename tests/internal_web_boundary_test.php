<?php

declare(strict_types=1);

$root = dirname(__DIR__);
$protectedDirectories = [
    'src',
    'classes',
    'config',
    'database',
    'tools',
    'tests',
    'scratch',
    'tmp',
    'storage',
];

$checks = [];
foreach ($protectedDirectories as $directory) {
    $path = $root . DIRECTORY_SEPARATOR . $directory . DIRECTORY_SEPARATOR . '.htaccess';
    $source = is_file($path) ? (string) file_get_contents($path) : '';
    $checks['deny_' . $directory] = $source !== ''
        && str_contains($source, 'Require all denied')
        && str_contains($source, 'Deny from all')
        && str_contains($source, 'Options -Indexes -ExecCGI');
}

$migrationRunner = (string) file_get_contents($root . '/tools/run_migrations.php');
$guardPosition = strpos($migrationRunner, "PHP_SAPI !== 'cli'");
$databasePosition = strpos($migrationRunner, "config/database.php");
$checks['migration_cli_guard_precedes_database'] = $guardPosition !== false
    && $databasePosition !== false
    && $guardPosition < $databasePosition;

$failed = [];
foreach ($checks as $name => $passed) {
    echo $name . ':' . ($passed ? 'PASS' : 'FAIL') . PHP_EOL;
    if (!$passed) {
        $failed[] = $name;
    }
}

exit($failed ? 1 : 0);
