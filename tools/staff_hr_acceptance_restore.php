<?php

declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

$options = getopt('', ['database:', 'target-database:', 'actor-id:', 'json']);
$databaseName = trim((string) ($options['database'] ?? getenv('EDUCORE_TEST_DB_NAME') ?: ''));
$targetDatabaseName = trim((string) ($options['target-database'] ?? getenv('STAFF_HR_ACCEPTANCE_RESTORE_DB') ?: ''));
$actorId = (int) ($options['actor-id'] ?? getenv('STAFF_HR_ACCEPTANCE_ACTOR_ID') ?: 0);
$environment = trim((string) (getenv('APP_ENV') ?: ''));
$marker = trim((string) (getenv('STAFF_HR_TEST_MARKER') ?: ''));
if ($databaseName !== '') {
    putenv('DB_NAME=' . $databaseName);
    $_ENV['DB_NAME'] = $_SERVER['DB_NAME'] = $databaseName;
}

$root = dirname(__DIR__);
require_once $root . '/tests/fixtures/staff_hr_acceptance_dataset.php';
require_once $root . '/config/database.php';
require_once $root . '/src/Modules/Operations/Audit/AuditService.php';
require_once $root . '/classes/RecoveryBackupService.php';
require_once $root . '/src/Modules/Staff/bootstrap.php';
require_once __DIR__ . '/includes/StaffHrAcceptanceDatasetStore.php';

try {
    StaffHrAcceptanceDatasetStore::assertEnvironment($databaseName, $environment, $marker);
    StaffHrAcceptanceDatasetStore::assertEnvironment($targetDatabaseName, $environment, $marker);
    if ($targetDatabaseName === $databaseName) {
        throw new RuntimeException('STAFF_HR_ACCEPTANCE_RESTORE_TARGET_MUST_BE_FRESH');
    }
    $db = (new Database())->getConnection();
    $connected = (string) $db->query('SELECT DATABASE()')->fetchColumn();
    StaffHrAcceptanceDatasetStore::assertEnvironment($connected, $environment, $marker);
    if ($connected !== $databaseName) {
        throw new RuntimeException('STAFF_HR_ACCEPTANCE_CONNECTED_DATABASE_MISMATCH');
    }
    $runtimeRoot = $root . '/storage/test-runtime';
    $recovery = new RecoveryBackupService(
        $db,
        $root,
        ['acceptance_data' => $runtimeRoot . '/staff-hr-acceptance-data/' . $databaseName],
        $runtimeRoot . '/staff-hr-acceptance-backups/' . $databaseName
    );
    $receipt = (new StaffHrAcceptanceDatasetStore($db))->restore(
        StaffHrAcceptanceDataset::build(),
        $actorId,
        static fn (string $backupKey): array => $recovery->restorePackageToIsolatedDatabase(
            $backupKey,
            $targetDatabaseName,
            $actorId
        )
    );
    $output = json_encode($receipt, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    echo isset($options['json']) ? $output . PHP_EOL : "Staff-HR acceptance baseline restored: {$output}\n";
} catch (Throwable $exception) {
    fwrite(STDERR, 'FAIL: ' . $exception->getMessage() . PHP_EOL);
    exit(1);
}
