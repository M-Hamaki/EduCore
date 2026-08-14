<?php

declare(strict_types=1);

$applicationDir = dirname(__DIR__) . '/src/Modules/Finance/Application';
$files = glob($applicationDir . '/*.php') ?: [];
$violations = [];

foreach ($files as $file) {
    $source = file_get_contents($file);
    if ($source === false) {
        $violations[] = basename($file) . ': unreadable';
        continue;
    }

    $forbidden = [
        '/\\buse\\s+PDO\\s*;/',
        '/\\bPDO(?:Exception)?\\b/',
        '/->(?:prepare|query|exec|beginTransaction|commit|rollBack|lastInsertId)\\s*\\(/',
        '/\\b(?:SELECT|INSERT\\s+INTO|UPDATE\\s+[a-z_]|DELETE\\s+FROM)\\b/i',
    ];

    foreach ($forbidden as $pattern) {
        if (preg_match($pattern, $source) === 1) {
            $violations[] = basename($file) . ': ' . $pattern;
        }
    }
}

if ($violations !== []) {
    fwrite(STDERR, "Finance Application architecture violations:\n- " . implode("\n- ", $violations) . "\n");
    exit(1);
}

echo 'Finance Application architecture contract passed (' . count($files) . " services).\n";
