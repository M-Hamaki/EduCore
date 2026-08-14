<?php

declare(strict_types=1);

$options = getopt('', ['database:']);
$database = (string) ($options['database'] ?? getenv('EDUCORE_TEST_DB_NAME') ?: '');
if (!preg_match('/^[A-Za-z0-9_]+_test$/', $database) || $database === 'educore') {
    fwrite(STDERR, "FAILED: EDUCORE_TEST_DB_NAME must name an isolated *_test database.\n");
    exit(1);
}

$command = escapeshellarg(PHP_BINARY)
    . ' ' . escapeshellarg(__DIR__ . '/finance_data_migration_integration_test.php')
    . ' --database=' . escapeshellarg($database);
$output = [];
$exitCode = 0;
exec($command . ' 2>&1', $output, $exitCode);
$text = implode("\n", $output);

if ($exitCode !== 0 || !str_contains($text, 'Finance data migration integration test PASSED')) {
    fwrite(STDERR, "FAILED: prior-year migration scenario did not pass.\n{$text}\n");
    exit(1);
}

$source = (string) file_get_contents(__DIR__ . '/../src/Modules/Finance/Application/LegacyFinanceMigrationService.php');
$integration = (string) file_get_contents(__DIR__ . '/finance_data_migration_integration_test.php');
$requirements = [
    "md5('legacy-prior-year-balance:'" => 'stable idempotency key',
    "'prior_year'" => 'separate prior-year source',
    "'Prior-year opening debt'" => 'opening-balance installment',
    'academic_year_id = {$yearId}' => 'original academic-year assertion',
    'legacy prior-year row remains unchanged' => 'append-only legacy preservation assertion',
];
foreach ($requirements as $token => $label) {
    $haystack = str_contains($label, 'assertion') ? $integration : $source;
    if (!str_contains($haystack, $token)) {
        fwrite(STDERR, "FAILED: missing {$label}.\n");
        exit(1);
    }
}

echo "Finance prior-year debt migration contract PASSED on {$database}.\n";
