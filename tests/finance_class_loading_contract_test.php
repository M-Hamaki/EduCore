<?php

declare(strict_types=1);

require_once __DIR__ . '/bootstrap_finance.php';

$roots = [
    dirname(__DIR__) . '/src/Modules/Finance/Application' => 'EduCore\\Modules\\Finance\\Application\\',
    dirname(__DIR__) . '/src/Modules/Finance/Infrastructure/Pdo' => 'EduCore\\Modules\\Finance\\Infrastructure\\Pdo\\',
];

$failures = [];
foreach ($roots as $directory => $namespace) {
    foreach (glob($directory . '/*.php') ?: [] as $file) {
        $class = $namespace . pathinfo($file, PATHINFO_FILENAME);
        try {
            if (!class_exists($class)) {
                $failures[] = $class;
            }
        } catch (Throwable $exception) {
            $failures[] = $class . ': ' . $exception->getMessage();
        }
    }
}

if ($failures !== []) {
    fwrite(STDERR, "Finance class-loading failures:\n- " . implode("\n- ", $failures) . "\n");
    exit(1);
}

echo "Finance Application/PDO class-loading contract passed.\n";
