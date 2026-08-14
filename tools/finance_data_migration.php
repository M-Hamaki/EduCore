<?php

declare(strict_types=1);

/**
 * Guarded legacy-finance migration into the unified domain/sub-ledger/GL model.
 *
 * Example:
 * php tools/finance_data_migration.php
 *   --database=educore_finance_test --charge-type-id=1 --cashbox-id=1 --actor-id=1
 */

if (PHP_SAPI !== 'cli') {
    fwrite(STDERR, "CLI only.\n");
    exit(1);
}

$options = getopt('', ['database:', 'charge-type-id:', 'cashbox-id:', 'actor-id:', 'dry-run', 'json']);
$database = (string) ($options['database'] ?? getenv('EDUCORE_TEST_DB_NAME') ?: '');
if (!preg_match('/^[A-Za-z0-9_]+_test$/', $database) || $database === 'educore') {
    fwrite(STDERR, "FAILED: --database must name an isolated *_test database; educore is always refused.\n");
    exit(1);
}

$chargeTypeId = filter_var($options['charge-type-id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
$cashboxId = filter_var($options['cashbox-id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
$actorId = filter_var($options['actor-id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
if ($chargeTypeId === false || $cashboxId === false || $actorId === false) {
    fwrite(STDERR, "FAILED: positive --charge-type-id, --cashbox-id, and --actor-id are required.\n");
    exit(1);
}

require_once __DIR__ . '/../config/env_loader.php';
$projectRoot = dirname(__DIR__);
spl_autoload_register(static function (string $class) use ($projectRoot): void {
    if (!str_starts_with($class, 'EduCore\\')) {
        return;
    }
    $file = $projectRoot . '/src/' . str_replace('\\', '/', substr($class, strlen('EduCore\\'))) . '.php';
    if (is_file($file)) {
        require_once $file;
    }
});

use EduCore\Modules\Finance\Application\LegacyFinanceReconciliationException;
use EduCore\Modules\Finance\Infrastructure\FinanceServiceFactory;
use EduCore\Modules\Finance\Infrastructure\Pdo\PdoLegacyFinanceSource;
use EduCore\Modules\Operations\Audit\AuditService;

$db = new PDO(
    'mysql:host=localhost;dbname=' . $database . ';charset=utf8mb4',
    (string) env('DB_USER', 'root'),
    (string) env('DB_PASS', ''),
    [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
);

$dryRun = array_key_exists('dry-run', $options);
try {
    if ($dryRun) {
        $db->beginTransaction();
    }
    $factory = new FinanceServiceFactory($db, new AuditService($db));
    $report = $factory->legacyMigrationService(new PdoLegacyFinanceSource($db))->migrate(
        (int) $chargeTypeId,
        (int) $cashboxId,
        (int) $actorId
    );
    if ($dryRun && $db->inTransaction()) {
        $db->rollBack();
        $report['dry_run'] = true;
    } else {
        $report['dry_run'] = false;
    }

    if (array_key_exists('json', $options)) {
        echo json_encode($report, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . PHP_EOL;
    } else {
        echo "Finance migration PASSED on {$database}.\n";
        foreach ($report as $key => $value) {
            if (!is_array($value)) {
                echo sprintf("  %-20s %s\n", $key . ':', (string) $value);
            }
        }
    }
    exit(0);
} catch (LegacyFinanceReconciliationException $exception) {
    if ($db->inTransaction()) {
        $db->rollBack();
    }
    fwrite(STDERR, json_encode($exception->report(), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . PHP_EOL);
    exit(2);
} catch (Throwable $exception) {
    if ($db->inTransaction()) {
        $db->rollBack();
    }
    fwrite(STDERR, 'FAILED: ' . $exception->getMessage() . PHP_EOL);
    exit(1);
}
