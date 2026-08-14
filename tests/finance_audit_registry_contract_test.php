<?php

declare(strict_types=1);

require_once __DIR__ . '/bootstrap_finance.php';

use EduCore\Modules\Operations\Audit\AuditPolicyRegistry;

$migrationFiles = glob(dirname(__DIR__) . '/database/migrations/2026072*_finance_*.php') ?: [];
$tables = [];
foreach ($migrationFiles as $file) {
    $source = file_get_contents($file) ?: '';
    if (preg_match_all('/CREATE TABLE\s+`([^`]+)`/i', $source, $matches)) {
        $tables = array_merge($tables, $matches[1]);
    }
}
$tables = array_values(array_unique($tables));
sort($tables);

$missing = array_values(array_filter($tables, static fn(string $table): bool => !AuditPolicyRegistry::isRegisteredTable($table)));
if ($missing !== []) {
    fwrite(STDERR, "Finance tables missing audit policy:\n- " . implode("\n- ", $missing) . "\n");
    exit(1);
}

echo 'Finance audit registry covers ' . count($tables) . " migration tables.\n";
