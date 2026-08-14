<?php

declare(strict_types=1);

/**
 * Guarded MariaDB proof for the Staff-owned permission-policy read adapter.
 */

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
    fwrite(STDERR, "FAIL: EDUCORE_TEST_DB_NAME/--database must identify a new isolated *_test database.\n");
    exit(2);
}

putenv('APP_ENV=test');
putenv('DB_NAME=' . $databaseName);
putenv('EDUCORE_TEST_DB_NAME=' . $databaseName);
$_ENV['APP_ENV'] = 'test';
$_ENV['DB_NAME'] = $databaseName;
$_ENV['EDUCORE_TEST_DB_NAME'] = $databaseName;
$_ENV['STAFF_HR_TEST_MARKER'] = $marker;
$_SERVER['APP_ENV'] = 'test';
$_SERVER['DB_NAME'] = $databaseName;
$_SERVER['EDUCORE_TEST_DB_NAME'] = $databaseName;
$_SERVER['STAFF_HR_TEST_MARKER'] = $marker;

require_once __DIR__ . '/bootstrap_staff_hr.php';
require_once dirname(__DIR__) . '/vendor/autoload.php';
require_once dirname(__DIR__) . '/config/database.php';

use EduCore\Modules\Staff\Application\Permission\PermissionPolicyResolver;
use EduCore\Modules\Staff\Contracts\StaffAssignmentAtDateQuery;
use EduCore\Modules\Staff\Infrastructure\PdoPermissionPolicyReadRepository;

$failures = 0;
$assert = static function (bool $condition, string $message) use (&$failures): void {
    if (!$condition) {
        fwrite(STDERR, "FAIL: {$message}\n");
        ++$failures;
    }
};
$recordFailure = static function (string $message) use (&$failures): void {
    fwrite(STDERR, "FAIL: {$message}\n");
    ++$failures;
};
$quoteIdentifier = static function (string $identifier): string {
    if (!preg_match('/^[A-Za-z0-9_]+$/', $identifier)) {
        throw new InvalidArgumentException('Unsafe database identifier.');
    }

    return chr(96) . $identifier . chr(96);
};

$permissionTables = [
    'staff_permission_quota_movements',
    'staff_permission_quota_accounts',
    'staff_permission_request_periods',
    'staff_permission_requests',
    'staff_permission_policy_scopes',
    'staff_permission_policy_versions',
    'staff_permission_types',
];
$permissionTriggers = [
    'trg_staff_permission_type_guard_update',
    'trg_staff_permission_type_no_delete',
    'trg_staff_permission_policy_version_immutable_update',
    'trg_staff_permission_policy_version_immutable_delete',
    'trg_staff_permission_policy_scope_immutable_insert',
    'trg_staff_permission_policy_scope_immutable_update',
    'trg_staff_permission_policy_scope_immutable_delete',
    'trg_staff_permission_request_guard_insert',
    'trg_staff_permission_request_guard_update',
    'trg_staff_permission_request_no_delete',
    'trg_staff_permission_request_period_guard_insert',
    'trg_staff_permission_request_period_guard_update',
    'trg_staff_permission_request_period_guard_delete',
    'trg_staff_permission_quota_account_guard_update',
    'trg_staff_permission_quota_movement_guard_insert',
    'trg_staff_permission_quota_movement_no_update',
    'trg_staff_permission_quota_movement_no_delete',
];

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
    $exists = $admin->prepare(
        'SELECT COUNT(*) FROM information_schema.SCHEMATA WHERE SCHEMA_NAME = ?'
    );
    $exists->execute([$databaseName]);
    if ((int) $exists->fetchColumn() !== 0) {
        fwrite(STDERR, "FAIL: {$databaseName} already exists; supply a new dedicated *_test database name.\n");
        exit(2);
    }

    $admin->exec(
        'CREATE DATABASE ' . $quoteIdentifier($databaseName)
        . ' CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci'
    );
    $databaseCreated = true;
    $db = staffHrTestDatabase();
    $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

    $migration = require dirname(__DIR__)
        . '/database/migrations/20260730_staff_hr_permissions_quota.php';
    $migration($db);

    $typeInsert = $db->prepare(
        "INSERT INTO staff_permission_types
            (code, name, coverage_behavior, requires_reason, requires_custom_label,
             requires_attachment, allow_retroactive, status, created_by)
         VALUES ('LATE_ARRIVAL', 'Late arrival', 'late_arrival', 1, 0, 0, 1, 'active', 990001)"
    );
    $typeInsert->execute();
    $typeId = (int) $db->lastInsertId();

    $versionInsert = $db->prepare(
        "INSERT INTO staff_permission_policy_versions
            (permission_type_id, version_no, state, valid_from, valid_to, timezone,
             max_requests_per_month, max_minutes_per_request, max_minutes_per_month,
             min_notice_minutes, retroactive_limit_days, reserve_on_submit,
             allow_overlap, allow_quota_override, quota_override_max_minutes, created_by)
         VALUES (?, ?, 'draft', ?, ?, 'Africa/Cairo', 3, 120, 240, 30, 2, 1, 0, 0, NULL, 990001)"
    );
    $scopeInsert = $db->prepare(
        "INSERT INTO staff_permission_policy_scopes
            (policy_version_id, scope_type, scope_id, priority, valid_from, valid_to, status, created_by)
         VALUES (?, ?, ?, ?, ?, ?, 'active', 990001)"
    );
    $publish = $db->prepare(
        "UPDATE staff_permission_policy_versions
         SET state = 'published', published_by = 990002, published_at = ?
         WHERE id = ?"
    );
    $createPublishedPolicy = static function (
        int $versionNo,
        string $scopeType,
        int $scopeId,
        int $priority,
        string $validFrom,
        ?string $validTo = null
    ) use ($versionInsert, $scopeInsert, $publish, $typeId, $db): int {
        $versionInsert->execute([$typeId, $versionNo, $validFrom, $validTo]);
        $versionId = (int) $db->lastInsertId();
        $scopeInsert->execute([$versionId, $scopeType, $scopeId, $priority, $validFrom, $validTo]);
        $publish->execute(['2026-09-01 08:00:00.000000', $versionId]);

        return $versionId;
    };

    $globalVersionId = $createPublishedPolicy(1, 'global', 0, 0, '2026-01-01 00:00:00.000000');
    $jobTitleVersionId = $createPublishedPolicy(2, 'job_title', 30, 0, '2026-01-01 00:00:00.000000');
    $orgUnitVersionId = $createPublishedPolicy(3, 'org_unit', 20, 0, '2026-01-01 00:00:00.000000');
    $groupVersionId = $createPublishedPolicy(4, 'group', 40, 5, '2026-01-01 00:00:00.000000');
    $staffVersionId = $createPublishedPolicy(5, 'staff', 10, 0, '2026-01-01 00:00:00.000000');
    $unmatchedGroupVersionId = $createPublishedPolicy(6, 'group', 99, 50, '2026-01-01 00:00:00.000000');
    $expiredVersionId = $createPublishedPolicy(
        7,
        'global',
        0,
        99,
        '2026-01-01 00:00:00.000000',
        '2026-10-01 00:00:00.000000'
    );

    $assignment = [
        'assignment_id' => 7001,
        'org_unit_id' => 20,
        'job_title_id' => 30,
        'group_ids' => [40, 41],
        'employment_status' => 'active',
    ];
    $effectiveAt = new DateTimeImmutable('2026-10-15 08:00:00.000000');
    $adapter = new PdoPermissionPolicyReadRepository($db);

    $type = $adapter->findType($typeId);
    $assert(is_array($type) && (string) $type['code'] === 'LATE_ARRIVAL', 'adapter reads the owned permission type');

    $candidates = $adapter->candidateVersionsFor($typeId, 10, $assignment, $effectiveAt);
    $candidateIds = array_map(static fn (array $row): int => (int) $row['version_id'], $candidates);
    $assert(
        $candidateIds === [
            $globalVersionId,
            $jobTitleVersionId,
            $orgUnitVersionId,
            $groupVersionId,
            $staffVersionId,
        ],
        'adapter returns exactly the matching effective global/title/unit/group/staff policies'
    );
    $assert(!in_array($unmatchedGroupVersionId, $candidateIds, true), 'adapter excludes groups the worker did not hold at the effective date');
    $assert(!in_array($expiredVersionId, $candidateIds, true), 'adapter respects the exclusive policy valid_to boundary');

    $assignmentQuery = new class($assignment) implements StaffAssignmentAtDateQuery {
        /** @param array<string,mixed> $assignment */
        public function __construct(private array $assignment)
        {
        }

        public function forStaff(int $staffId, DateTimeImmutable $atDate): ?array
        {
            return $this->assignment;
        }
    };
    $resolver = new PermissionPolicyResolver($adapter, $assignmentQuery);
    $resolved = $resolver->resolve(10, $typeId, $effectiveAt);
    $assert($resolved['status'] === 'resolved', 'resolver works over the real PDO adapter');
    $assert($resolved['policy']['version_id'] === $staffVersionId, 'real adapter preserves staff-scope precedence');
    $assert(
        $resolved['explanation']['scope_type'] === 'staff'
        && $resolved['explanation']['scope_id'] === 10,
        'real adapter returns a safe explanation of the effective scope'
    );
} catch (Throwable $exception) {
    $recordFailure('permission policy repository exercise failed: ' . $exception->getMessage());
} finally {
    if ($db instanceof PDO) {
        foreach ($permissionTriggers as $trigger) {
            try {
                $db->exec('DROP TRIGGER IF EXISTS ' . $quoteIdentifier($trigger));
            } catch (Throwable $exception) {
                $recordFailure("rollback could not drop trigger {$trigger}: " . $exception->getMessage());
            }
        }
        foreach ($permissionTables as $table) {
            try {
                $db->exec('DROP TABLE IF EXISTS ' . $quoteIdentifier($table));
            } catch (Throwable $exception) {
                $recordFailure("rollback could not drop table {$table}: " . $exception->getMessage());
            }
        }
        try {
            $remainingTables = $db->query(
                'SELECT TABLE_NAME FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE()'
            )->fetchAll(PDO::FETCH_COLUMN);
            $remainingTriggers = $db->query(
                'SELECT TRIGGER_NAME FROM information_schema.TRIGGERS WHERE TRIGGER_SCHEMA = DATABASE()'
            )->fetchAll(PDO::FETCH_COLUMN);
            $assert($remainingTables === [], 'rollback removes all owned policy repository tables');
            $assert($remainingTriggers === [], 'rollback removes all owned policy repository triggers');
        } catch (Throwable $exception) {
            $recordFailure('policy repository rollback verification failed: ' . $exception->getMessage());
        }
    }
    if ($databaseCreated && $admin instanceof PDO) {
        try {
            $db = null;
            $admin->exec('DROP DATABASE ' . $quoteIdentifier($databaseName));
            $databaseDropped = true;
            $exists = $admin->prepare(
                'SELECT COUNT(*) FROM information_schema.SCHEMATA WHERE SCHEMA_NAME = ?'
            );
            $exists->execute([$databaseName]);
            $assert((int) $exists->fetchColumn() === 0, 'temporary policy repository database is removed');
        } catch (Throwable $exception) {
            $recordFailure("temporary policy repository cleanup failed: {$exception->getMessage()}");
        }
    }
}

if ($databaseCreated && !$databaseDropped) {
    fwrite(STDERR, "FAIL: temporary database {$databaseName} still exists and requires manual cleanup.\n");
    ++$failures;
}

if ($failures > 0) {
    fwrite(STDERR, "{$failures} permission policy repository integration failure(s).\n");
    exit(1);
}

echo "Staff-HR permission policy repository integration passed on {$databaseName}; temporary database removed.\n";
