<?php

declare(strict_types=1);

$root = dirname(__DIR__);
$tests = [
    'five_year_500_staff_cursor_benchmark' => [
        'file' => 'tests/staff_hr_attendance_reporting_performance_test.php',
        'environment' => ['STAFF_HR_RUN_FULL_REPORT_BENCHMARK' => '1'],
    ],
    'recalculation_idempotent_replay' => [
        'file' => 'tests/staff_hr_attendance_recalculation_test.php',
        'environment' => [],
    ],
    'recalculation_period_resume_and_atomicity' => [
        'file' => 'tests/staff_hr_attendance_recalculation_integration_test.php',
        'environment' => [],
    ],
];

$run = static function (string $file, array $environment) use ($root): array {
    $previous = [];
    foreach ($environment as $name => $value) {
        $previous[$name] = getenv($name);
        putenv($name . '=' . $value);
    }
    $started = hrtime(true);
    try {
        $process = proc_open(
            [PHP_BINARY, $root . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $file)],
            [1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
            $pipes,
            $root,
            null,
            ['bypass_shell' => true]
        );
        if (!is_resource($process)) {
            return ['exit_code' => 1, 'stdout' => '', 'stderr' => 'PERFORMANCE_SUITE_PROCESS_START_FAILED', 'seconds' => 0.0];
        }
        $stdout = stream_get_contents($pipes[1]);
        $stderr = stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        $exitCode = proc_close($process);
        return [
            'exit_code' => $exitCode,
            'stdout' => (string) $stdout,
            'stderr' => (string) $stderr,
            'seconds' => (hrtime(true) - $started) / 1_000_000_000,
        ];
    } finally {
        foreach ($previous as $name => $value) {
            putenv($value === false ? $name : $name . '=' . $value);
        }
    }
};

$failures = [];
$totalSeconds = 0.0;
foreach ($tests as $name => $definition) {
    $file = (string) $definition['file'];
    if (!is_file($root . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $file))) {
        $failures[$name] = 'PERFORMANCE_SUITE_TEST_MISSING:' . $file;
        continue;
    }
    $result = $run($file, (array) $definition['environment']);
    $totalSeconds += $result['seconds'];
    echo '[' . $name . '] ' . number_format($result['seconds'], 3) . 's' . PHP_EOL;
    if ($result['stdout'] !== '') {
        echo rtrim($result['stdout']) . PHP_EOL;
    }
    if ($result['exit_code'] !== 0) {
        $failures[$name] = trim($result['stderr']) ?: 'PERFORMANCE_SUITE_CHILD_FAILED';
    }
}

// This suite is a regression gate, not a production-capacity promise. The
// detailed projector test owns the memory and batch-query ceilings, while this
// wrapper proves the opt-in five-year path and restart/idempotency paths run in
// one repeatable command without connecting to a business database.
echo 'STAFF_HR_PERFORMANCE_TESTS=' . count($tests)
    . ' FAILED=' . count($failures)
    . ' TOTAL_SECONDS=' . number_format($totalSeconds, 3) . PHP_EOL;
if ($failures !== []) {
    foreach ($failures as $name => $message) {
        fwrite(STDERR, $name . ':' . $message . PHP_EOL);
    }
    exit(1);
}

echo "Staff-HR performance and recalculation-resume suite passed.\n";
