<?php

declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

$environment = strtolower(trim((string) (getenv('APP_ENV') ?: getenv('ENVIRONMENT') ?: '')));
$testDatabase = trim((string) (getenv('EDUCORE_TEST_DB_NAME') ?: ''));
$runtimeDatabase = trim((string) (getenv('DB_NAME') ?: ''));

if ($environment !== 'testing'
    || !preg_match('/^[A-Za-z0-9_]+_test$/', $testDatabase)
    || $runtimeDatabase !== $testDatabase
    || $testDatabase === 'educore'
) {
    fwrite(
        STDERR,
        "Refusing QA suite: APP_ENV=testing and matching DB_NAME/EDUCORE_TEST_DB_NAME ending in _test are required.\n"
    );
    exit(2);
}

$tests = glob(dirname(__DIR__) . '/tests/*_test.php') ?: [];
sort($tests, SORT_STRING);

$onlyDatabase = in_array('--only-db', $argv ?? [], true);
$onlyContracts = in_array('--only-contracts', $argv ?? [], true);
$offset = 0;
$limit = null;
foreach ($argv ?? [] as $argument) {
    if (preg_match('/^--offset=(\d+)$/', (string) $argument, $match)) {
        $offset = (int) $match[1];
    } elseif (preg_match('/^--limit=(\d+)$/', (string) $argument, $match)) {
        $limit = max(1, (int) $match[1]);
    }
}

$isDatabaseTest = static function (string $test): bool {
    $source = (string) file_get_contents($test);
    return strpos($source, 'bootstrap_test_database.php') !== false
        || strpos($source, 'safe_rollover_integration_fixture.php') !== false;
};
if ($onlyDatabase || $onlyContracts) {
    $tests = array_values(array_filter(
        $tests,
        static function (string $test) use ($isDatabaseTest, $onlyDatabase): bool {
            return $isDatabaseTest($test) === $onlyDatabase;
        }
    ));
}
if ($offset > 0 || $limit !== null) {
    $tests = array_slice($tests, $offset, $limit);
}

$run = static function (array $command, array $environment): int {
    $descriptors = [
        0 => ['file', 'php://stdin', 'r'],
        1 => ['file', 'php://stdout', 'w'],
        2 => ['file', 'php://stderr', 'w'],
    ];
    $process = proc_open($command, $descriptors, $pipes, dirname(__DIR__), $environment);
    if (!is_resource($process)) {
        return 127;
    }
    return proc_close($process);
};

$baseEnvironment = getenv();
if (!is_array($baseEnvironment)) {
    $baseEnvironment = [];
}
$baseEnvironment['APP_ENV'] = 'testing';
$baseEnvironment['EDUCORE_TEST_DB_NAME'] = $testDatabase;
$testEnvironment = $baseEnvironment;
$testEnvironment['DB_NAME'] = $testDatabase;
$cloneEnvironment = $baseEnvironment;
unset($cloneEnvironment['DB_NAME']);

$failures = [];
$startedAt = microtime(true);
foreach ($tests as $test) {
    $requiresIsolatedDatabase = $isDatabaseTest($test);
    if ($requiresIsolatedDatabase) {
        $cloneExit = $run(
            [PHP_BINARY, dirname(__DIR__) . '/tools/clone_schema_to_test_database.php'],
            $cloneEnvironment
        );
        if ($cloneExit !== 0) {
            $failures[] = basename($test) . ' (schema clone failed)';
            continue;
        }
    }
    $exitCode = $run([PHP_BINARY, $test], $testEnvironment);
    if ($exitCode !== 0) {
        $failures[] = basename($test);
    }
}

echo 'TEST_FILES=' . count($tests) . PHP_EOL;
echo 'TEST_FAILURES=' . count($failures) . PHP_EOL;
echo 'TEST_DURATION_SECONDS=' . number_format(microtime(true) - $startedAt, 2, '.', '') . PHP_EOL;
foreach ($failures as $failure) {
    echo 'FAILED_FILE=' . $failure . PHP_EOL;
}

exit($failures === [] ? 0 : 1);
