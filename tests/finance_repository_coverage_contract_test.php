<?php

declare(strict_types=1);

require_once __DIR__ . '/bootstrap_finance.php';

$root = dirname(__DIR__);
$contractNamespace = 'EduCore\\Modules\\Finance\\Contracts\\Repositories\\';
$implementationNamespace = 'EduCore\\Modules\\Finance\\Infrastructure\\Pdo\\';
$contracts = [];
$implementations = [];
$failures = [];

foreach (glob($root . '/src/Modules/Finance/Contracts/Repositories/*.php') ?: [] as $file) {
    $interface = $contractNamespace . pathinfo($file, PATHINFO_FILENAME);
    if (!interface_exists($interface)) {
        $failures[] = 'Repository contract cannot be loaded: ' . $interface;
        continue;
    }
    $contracts[] = $interface;
}

foreach (glob($root . '/src/Modules/Finance/Infrastructure/Pdo/*.php') ?: [] as $file) {
    $class = $implementationNamespace . pathinfo($file, PATHINFO_FILENAME);
    if (class_exists($class)) {
        $implementations[] = $class;
    }
}

foreach ($contracts as $contract) {
    $matches = array_filter(
        $implementations,
        static fn (string $implementation): bool => is_subclass_of($implementation, $contract)
    );
    if ($matches === []) {
        $failures[] = 'No PDO implementation found for ' . $contract;
    }
}

if ($failures !== []) {
    fwrite(STDERR, "Finance repository coverage failures:\n- " . implode("\n- ", $failures) . "\n");
    exit(1);
}

echo sprintf(
    "Finance repository coverage passed (%d contracts, %d PDO classes).\n",
    count($contracts),
    count($implementations)
);
