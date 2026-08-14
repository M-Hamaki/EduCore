<?php

declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

$options = getopt('', ['database:']);
$databaseName = trim((string) ($options['database'] ?? getenv('EDUCORE_TEST_DB_NAME') ?: ''));
$marker = trim((string) (getenv('STAFF_HR_TEST_MARKER') ?: ''));
if ($marker !== 'integrated-staff-hr') {
    fwrite(STDERR, "FAIL: set STAFF_HR_TEST_MARKER=integrated-staff-hr explicitly.\n");
    exit(2);
}
if ($databaseName === ''
    || !preg_match('/^[A-Za-z0-9_]+_test$/', $databaseName)
    || strtolower($databaseName) === 'educore') {
    fwrite(STDERR, "FAIL: --database must identify a new isolated *_test database.\n");
    exit(2);
}

putenv('APP_ENV=test');
putenv('DB_NAME=' . $databaseName);
$_ENV['APP_ENV'] = $_SERVER['APP_ENV'] = 'test';
$_ENV['DB_NAME'] = $_SERVER['DB_NAME'] = $databaseName;

$root = dirname(__DIR__);
require_once $root . '/config/database.php';
require_once $root . '/src/Modules/Operations/Audit/AuditEventWriter.php';
require_once $root . '/src/Modules/Staff/Infrastructure/Migration/StaffHrMigrationCoordinator.php';

use EduCore\Modules\Operations\Audit\AuditEventWriter;
use EduCore\Modules\Staff\Infrastructure\Migration\StaffHrMigrationCoordinator;

final class StaffHrMigrationAuditSpy implements AuditEventWriter
{
    /** @var list<array<string,mixed>> */
    public array $events = [];

    public function recordEvent(
        string $action,
        ?string $entityType,
        mixed $recordId,
        ?string $name,
        array $details = [],
        array $context = []
    ): void {
        $this->events[] = compact('action', 'entityType', 'recordId', 'details', 'context');
    }
}

$failures = 0;
$assert = static function (bool $condition, string $message) use (&$failures): void {
    if (!$condition) {
        fwrite(STDERR, "FAIL: {$message}\n");
        ++$failures;
    }
};
$expectCode = static function (callable $operation, string $code, string $message) use ($assert): void {
    try {
        $operation();
        $assert(false, $message);
    } catch (Throwable $exception) {
        $assert(str_contains($exception->getMessage(), $code), $message . ' (' . $exception->getMessage() . ')');
    }
};
$quoteIdentifier = static function (string $identifier): string {
    if (!preg_match('/^[A-Za-z0-9_]+$/', $identifier)) {
        throw new InvalidArgumentException('Unsafe database identifier.');
    }
    return '`' . $identifier . '`';
};

$admin = null;
$db = null;
$created = false;
$dropped = false;

try {
    $admin = new PDO(
        'mysql:host=' . DB_HOST . ';charset=utf8mb4',
        DB_USERNAME,
        DB_PASSWORD,
        [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
    );
    $exists = $admin->prepare('SELECT COUNT(*) FROM information_schema.SCHEMATA WHERE SCHEMA_NAME = ?');
    $exists->execute([$databaseName]);
    if ((int) $exists->fetchColumn() !== 0) {
        fwrite(STDERR, "FAIL: {$databaseName} already exists; use a fresh dedicated *_test database.\n");
        exit(2);
    }
    $admin->exec('CREATE DATABASE ' . $quoteIdentifier($databaseName)
        . ' CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci');
    $created = true;
    $db = new PDO(
        'mysql:host=' . DB_HOST . ';dbname=' . $databaseName . ';charset=utf8mb4',
        DB_USERNAME,
        DB_PASSWORD,
        [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
    );

    $migration = require $root . '/database/migrations/20260730_staff_hr_workflow_operations_foundation.php';
    $migration($db);
    $migration($db);
    $db->exec(<<<'SQL'
CREATE TABLE migration_owned_rows (
    id BIGINT NOT NULL AUTO_INCREMENT,
    source_key VARCHAR(100) NOT NULL,
    owner_kind ENUM('batch','concurrent') NOT NULL,
    PRIMARY KEY (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL);

    $audit = new StaffHrMigrationAuditSpy();
    $coordinator = new StaffHrMigrationCoordinator($db, $audit);
    $deadline = new DateTimeImmutable('+1 day', new DateTimeZone('UTC'));

    $window = $coordinator->openWindow('capture', 'legacy:0', 990001, 'cutover-capture-1', $deadline);
    $windowReplay = $coordinator->openWindow('capture', 'legacy:0', 990001, 'cutover-capture-1', $deadline);
    $assert(
        $window['window_id'] === $windowReplay['window_id'] && $windowReplay['replayed'] === true,
        'cutover opening is idempotent'
    );
    $expectCode(
        static fn () => $coordinator->openWindow('freeze', 'legacy:0', 990001, 'cutover-capture-1', $deadline),
        'STAFF_HR_CUTOVER_IDEMPOTENCY_CONFLICT',
        'an altered cutover replay fails closed'
    );

    $db->exec("INSERT INTO migration_owned_rows (source_key, owner_kind) VALUES ('legacy:1', 'batch')");
    $ownedId = (int) $db->lastInsertId();
    $db->exec("INSERT INTO migration_owned_rows (source_key, owner_kind) VALUES ('legacy:concurrent', 'concurrent')");
    $concurrentId = (int) $db->lastInsertId();
    $manifest = [['resource_type' => 'migration_owned_rows', 'resource_id' => $ownedId]];
    $batch = $coordinator->beginBatch(
        $window['window_id'],
        'legacy_staff_profile_backfill',
        'legacy:0',
        990001,
        'migration-batch-1',
        $manifest
    );
    $batchReplay = $coordinator->beginBatch(
        $window['window_id'],
        'legacy_staff_profile_backfill',
        'legacy:0',
        990001,
        'migration-batch-1',
        $manifest
    );
    $assert($batchReplay['batch_id'] === $batch['batch_id'] && $batchReplay['replayed'], 'batch start reruns safely');

    $checksum = hash('sha256', 'legacy-staff-profile-batch-1');
    $checkpoint = $coordinator->checkpoint(
        $window['window_id'],
        $batch['batch_id'],
        'staff_profiles:id:100',
        ['read' => 2, 'write' => 1, 'skip' => 1, 'error' => 0],
        $checksum,
        990001
    );
    $checkpointReplay = $coordinator->checkpoint(
        $window['window_id'],
        $batch['batch_id'],
        'staff_profiles:id:100',
        ['read' => 2, 'write' => 1, 'skip' => 1, 'error' => 0],
        $checksum,
        990001
    );
    $assert($checkpoint['resume_token'] === 'staff_profiles:id:100' && $checkpointReplay['replayed'], 'checkpoint rerun does not duplicate progress');
    $expectCode(
        static fn () => $coordinator->checkpoint(
            $window['window_id'],
            $batch['batch_id'],
            'staff_profiles:id:50',
            ['read' => 1, 'write' => 1, 'skip' => 0, 'error' => 0],
            hash('sha256', 'regression'),
            990001
        ),
        'STAFF_HR_MIGRATION_CHECKPOINT_REGRESSION',
        'a resumed batch cannot move counts backwards'
    );

    $captured = $coordinator->recordConcurrentLegacyWrite(
        $window['window_id'], 'legacy:concurrent', hash('sha256', 'legacy concurrent payload'), 990002
    );
    $assert($captured['captured'] === true, 'capture mode records the new source watermark');

    $quarantine = $coordinator->quarantine(
        $batch['batch_id'], 'staff_profiles', 'staff_profiles:ambiguous:7',
        'AMBIGUOUS_ORG_UNIT', hash('sha256', 'ambiguous source payload'), 990001
    );
    $quarantineReplay = $coordinator->quarantine(
        $batch['batch_id'], 'staff_profiles', 'staff_profiles:ambiguous:7',
        'AMBIGUOUS_ORG_UNIT', hash('sha256', 'ambiguous source payload'), 990001
    );
    $assert($quarantine['exception_id'] === $quarantineReplay['exception_id'] && $quarantineReplay['replayed'], 'ambiguous source quarantine is idempotent');
    $completed = $coordinator->completeBatch($batch['batch_id'], 'new:1', 990001);
    $assert($completed['status'] === 'completed_with_exceptions', 'an open quarantine is never reported as a matched batch');
    $expectCode(
        static fn () => $coordinator->closeWindow($window['window_id'], [
            'read' => 2, 'write' => 1, 'skip' => 1, 'error' => 0, 'checksum' => $checksum,
        ], 990001),
        'STAFF_HR_CUTOVER_RECONCILIATION_INCOMPLETE',
        'new_only is blocked while a batch has an open exception'
    );

    $rollback = $coordinator->rollbackWindow(
        $window['window_id'],
        'فشلت المصالحة وبقي صف ملتبس',
        990001,
        static function (array $owned, PDO $connection): array {
            $ids = array_values(array_map('intval', array_column($owned, 'resource_id')));
            if ($ids !== []) {
                $placeholders = implode(',', array_fill(0, count($ids), '?'));
                $statement = $connection->prepare(
                    "DELETE FROM migration_owned_rows WHERE owner_kind = 'batch' AND id IN ({$placeholders})"
                );
                $statement->execute($ids);
            }
            return ['reversed' => count($ids), 'checksum' => hash('sha256', json_encode($ids))];
        }
    );
    $assert(
        $rollback['status'] === 'rolled_back'
        && $rollback['write_mode'] === 'legacy_only'
        && (int) $db->query("SELECT COUNT(*) FROM migration_owned_rows WHERE id = {$ownedId}")->fetchColumn() === 0
        && (int) $db->query("SELECT COUNT(*) FROM migration_owned_rows WHERE id = {$concurrentId}")->fetchColumn() === 1,
        'rollback removes only manifest-owned rows and preserves concurrent legacy data'
    );
    $assert(
        (int) $db->query('SELECT COUNT(*) FROM staff_hr_migration_batches')->fetchColumn() === 1
        && (int) $db->query('SELECT COUNT(*) FROM staff_hr_migration_exceptions')->fetchColumn() === 1,
        'rollback retains batch and quarantine history for investigation'
    );

    $freezeWindow = $coordinator->openWindow('freeze', 'legacy:200', 990001, 'cutover-freeze-1', $deadline);
    $expectCode(
        static fn () => $coordinator->recordConcurrentLegacyWrite(
            $freezeWindow['window_id'], 'legacy:201', hash('sha256', 'blocked write'), 990002
        ),
        'STAFF_HR_CUTOVER_LEGACY_WRITE_BLOCKED',
        'freeze mode rejects a concurrent legacy write'
    );

    $matchedWindow = $coordinator->openWindow('capture', 'legacy:300', 990001, 'cutover-matched-1', $deadline);
    $matchedBatch = $coordinator->beginBatch(
        $matchedWindow['window_id'], 'matched_staff_batch', 'legacy:300', 990001, 'migration-batch-matched', []
    );
    $matchedChecksum = hash('sha256', 'matched-staff-batch');
    $coordinator->checkpoint(
        $matchedWindow['window_id'], $matchedBatch['batch_id'], 'staff_profiles:id:300',
        ['read' => 3, 'write' => 3, 'skip' => 0, 'error' => 0], $matchedChecksum, 990001
    );
    $coordinator->completeBatch($matchedBatch['batch_id'], 'new:300', 990001);
    $closed = $coordinator->closeWindow($matchedWindow['window_id'], [
        'read' => 3, 'write' => 3, 'skip' => 0, 'error' => 0, 'checksum' => $matchedChecksum,
    ], 990001);
    $assert(
        $closed['status'] === 'closed'
        && $closed['write_mode'] === 'new_only'
        && $closed['reconciliation_status'] === 'matched',
        'new_only is published only after exact count and checksum reconciliation'
    );
    $assert(count($audit->events) >= 10, 'all coordinator transitions create shared audit evidence');
} catch (Throwable $exception) {
    fwrite(STDERR, 'FAIL: migration coordinator exercise failed: ' . $exception->getMessage() . "\n");
    ++$failures;
} finally {
    if ($created && $admin instanceof PDO) {
        try {
            $db = null;
            $admin->exec('DROP DATABASE ' . $quoteIdentifier($databaseName));
            $dropped = true;
        } catch (Throwable $exception) {
            fwrite(STDERR, "FAIL: temporary coordinator database cleanup failed: {$exception->getMessage()}\n");
            ++$failures;
        }
    }
}

if ($created && !$dropped) {
    fwrite(STDERR, "FAIL: temporary database {$databaseName} still exists and requires manual cleanup.\n");
    ++$failures;
}
if ($failures > 0) {
    fwrite(STDERR, "{$failures} migration coordinator failure(s).\n");
    exit(1);
}

echo "Staff-HR migration coordinator proof passed on {$databaseName}; temporary database removed.\n";
