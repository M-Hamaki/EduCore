<?php

declare(strict_types=1);

/**
 * Guarded real-PDO proof for the legacy permissions compatibility adapter.
 *
 * It creates and removes only an explicit fresh *_test database. The test is
 * intentionally never pointed at the application's educore database.
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

$options = getopt('', ['database:']);
$databaseName = trim((string) ($options['database'] ?? getenv('EDUCORE_TEST_DB_NAME') ?: ''));
if (trim((string) getenv('STAFF_HR_TEST_MARKER')) !== 'integrated-staff-hr') {
    fwrite(STDERR, "FAIL: set STAFF_HR_TEST_MARKER=integrated-staff-hr explicitly.\n");
    exit(2);
}
if ($databaseName === ''
    || !preg_match('/^[A-Za-z0-9_]+_test$/', $databaseName)
    || strtolower($databaseName) === 'educore') {
    fwrite(STDERR, "FAIL: --database must name a new dedicated *_test database.\n");
    exit(2);
}

putenv('APP_ENV=test');
putenv('DB_NAME=' . $databaseName);
putenv('EDUCORE_TEST_DB_NAME=' . $databaseName);
$_ENV['APP_ENV'] = 'test';
$_ENV['DB_NAME'] = $databaseName;
$_ENV['EDUCORE_TEST_DB_NAME'] = $databaseName;
$_SERVER['APP_ENV'] = 'test';
$_SERVER['DB_NAME'] = $databaseName;
$_SERVER['EDUCORE_TEST_DB_NAME'] = $databaseName;

require_once __DIR__ . '/bootstrap_staff_hr.php';
require_once dirname(__DIR__) . '/config/database.php';
require_once dirname(__DIR__) . '/vendor/autoload.php';

use EduCore\Modules\Staff\Application\Permission\LegacyPermissionCompatibilityService;
use EduCore\Modules\Staff\Contracts\LegacyPermissionAuditWriter;
use EduCore\Modules\Staff\Infrastructure\PdoLegacyPermissionRepository;

final class LegacyPermissionIntegrationAudit implements LegacyPermissionAuditWriter
{
    public ?string $failOn = null;
    /** @var list<string> */
    public array $events = [];

    public function permissionCreated(int $permissionId, array $after): void
    {
        $this->record('created');
    }

    public function permissionUpdated(int $permissionId, array $before, array $after): void
    {
        $this->record('updated');
    }

    public function permissionDeleted(int $permissionId, array $before): void
    {
        $this->record('deleted');
    }

    private function record(string $event): void
    {
        if ($this->failOn === $event) {
            throw new RuntimeException('LEGACY_PERMISSION_AUDIT_FAILED');
        }
        $this->events[] = $event;
    }
}

$failures = 0;
$assert = static function (bool $condition, string $message) use (&$failures): void {
    if (!$condition) {
        fwrite(STDERR, "FAIL: {$message}\n");
        ++$failures;
    }
};
$assertThrows = static function (callable $operation, string $message) use ($assert): void {
    try {
        $operation();
        $assert(false, $message);
    } catch (Throwable) {
        $assert(true, $message);
    }
};
$quoteIdentifier = static function (string $identifier): string {
    if (!preg_match('/^[A-Za-z0-9_]+$/', $identifier)) {
        throw new InvalidArgumentException('Unsafe database identifier.');
    }
    return chr(96) . $identifier . chr(96);
};

$admin = null;
$db = null;
$databaseCreated = false;
$databaseDropped = false;

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
        fwrite(STDERR, "FAIL: {$databaseName} already exists; supply a fresh dedicated *_test database.\n");
        exit(2);
    }

    $admin->exec(
        'CREATE DATABASE ' . $quoteIdentifier($databaseName)
        . ' CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci'
    );
    $databaseCreated = true;
    $db = staffHrTestDatabase();
    $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $db->exec(
        "CREATE TABLE users (
            id INT NOT NULL PRIMARY KEY,
            name VARCHAR(120) NOT NULL,
            status VARCHAR(20) NOT NULL,
            role VARCHAR(30) NOT NULL
        ) ENGINE=InnoDB"
    );
    $db->exec('CREATE TABLE staff_profiles (user_id INT NOT NULL PRIMARY KEY) ENGINE=InnoDB');
    $db->exec(
        "CREATE TABLE staff_permissions (
            id INT NOT NULL AUTO_INCREMENT PRIMARY KEY,
            user_id INT NOT NULL,
            permission_type VARCHAR(30) NOT NULL,
            permission_date DATE NOT NULL,
            time_from TIME NULL,
            time_to TIME NULL,
            reason TEXT NOT NULL,
            status VARCHAR(30) NOT NULL,
            approved_by INT NULL,
            notes TEXT NOT NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
        ) ENGINE=InnoDB"
    );
    $db->exec("INSERT INTO users (id, name, status, role) VALUES (41, 'عامل تجريبي', 'active', 'teacher')");

    $audit = new LegacyPermissionIntegrationAudit();
    $service = new LegacyPermissionCompatibilityService(new PdoLegacyPermissionRepository($db), $audit);
    $permissionId = $service->savePermission([
        'user_id' => 41,
        'permission_type' => 'late_arrival',
        'permission_date' => '2026-08-07',
        'time_from' => '08:00',
        'time_to' => '09:00',
        'status' => 'approved',
        'reason' => 'اختبار قاعدة معزولة',
        'notes' => 'بيانات تجريبية',
    ], 900);
    $stored = $db->query('SELECT status, approved_by, reason FROM staff_permissions WHERE id = ' . $permissionId)
        ->fetch(PDO::FETCH_ASSOC) ?: [];
    $assert($permissionId === 1 && ($stored['status'] ?? '') === 'approved'
        && (int) ($stored['approved_by'] ?? 0) === 900
        && $audit->events === ['created'], 'real PDO create persists and audits the legacy permission atomically');

    $audit->failOn = 'updated';
    $assertThrows(static fn (): int => $service->savePermission([
        'user_id' => 41,
        'permission_type' => 'late_arrival',
        'permission_date' => '2026-08-07',
        'time_from' => '08:00',
        'time_to' => '09:00',
        'status' => 'pending',
        'reason' => 'لا يجب حفظه',
        'notes' => 'بيانات تجريبية',
    ], 900, $permissionId), 'mandatory audit failure rejects the real legacy update');
    $audit->failOn = null;
    $afterFailure = $db->query('SELECT status, reason FROM staff_permissions WHERE id = ' . $permissionId)
        ->fetch(PDO::FETCH_ASSOC) ?: [];
    $assert(($afterFailure['status'] ?? '') === 'approved'
        && ($afterFailure['reason'] ?? '') === 'اختبار قاعدة معزولة', 'audit failure rolls the real legacy row back');

    $assert($service->deletePermission($permissionId)
        && (int) $db->query('SELECT COUNT(*) FROM staff_permissions')->fetchColumn() === 0
        && $audit->events === ['created', 'deleted'], 'real PDO delete is audited and commits only after audit success');
} catch (Throwable $exception) {
    fwrite(STDERR, 'UNEXPECTED: ' . $exception->getMessage() . "\n");
    ++$failures;
} finally {
    if ($databaseCreated && $admin instanceof PDO) {
        try {
            $admin->exec('DROP DATABASE IF EXISTS ' . $quoteIdentifier($databaseName));
            $databaseDropped = true;
        } catch (Throwable $exception) {
            fwrite(STDERR, 'CLEANUP FAILURE: ' . $exception->getMessage() . "\n");
            ++$failures;
        }
    }
}

if (!$databaseDropped) {
    fwrite(STDERR, "FAIL: temporary legacy permission test database was not removed.\n");
    ++$failures;
}
if ($failures > 0) {
    fwrite(STDERR, "{$failures} legacy permission compatibility integration failure(s).\n");
    exit(1);
}

echo "Staff-HR legacy permission compatibility integration test passed on {$databaseName}; temporary database removed.\n";
