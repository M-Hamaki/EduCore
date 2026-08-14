<?php

declare(strict_types=1);

$options = getopt('', ['database:']);
$database = (string) ($options['database'] ?? '');
if (!preg_match('/^[A-Za-z0-9_]+_test$/', $database)) {
    fwrite(STDERR, "--database must name an isolated *_test database.\n");
    exit(1);
}

$files = glob(__DIR__ . '/finance_*_test.php') ?: [];
sort($files);
$failed = [];

foreach ($files as $file) {
    $command = escapeshellarg(PHP_BINARY)
        . ' ' . escapeshellarg($file)
        . ' --database=' . escapeshellarg($database);
    passthru($command, $exitCode);
    if ($exitCode !== 0) {
        $failed[] = basename($file);
    }
}

echo 'FINANCE_TEST_FILES=' . count($files) . ' FAILED=' . count($failed) . PHP_EOL;
if ($failed !== []) {
    fwrite(STDERR, 'FAILED_FILES=' . implode(',', $failed) . PHP_EOL);
    exit(1);
}
