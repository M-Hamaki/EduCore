<?php

declare(strict_types=1);

/**
 * Guarded MariaDB proof for PermissionRequestService with the real Staff PDO
 * request and quota repositories. It creates and removes only an explicit
 * fresh *_test database; it never targets the application database.
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

use EduCore\Modules\Operations\Audit\AuditEventWriter;
use EduCore\Modules\Staff\Application\Permission\PermissionPolicyResolver;
use EduCore\Modules\Staff\Application\Permission\PermissionQuotaLedger;
use EduCore\Modules\Staff\Application\Permission\PermissionRequestService;
use EduCore\Modules\Staff\Contracts\ApprovalWorkflowSubmissionGateway;
use EduCore\Modules\Staff\Contracts\PermissionRequestAuthorization;
use EduCore\Modules\Staff\Contracts\PermissionRequestClock;
use EduCore\Modules\Staff\Contracts\PermissionSubmissionWorkflowResolver;
use EduCore\Modules\Staff\Contracts\StaffAssignmentAtDateQuery;
use EduCore\Modules\Staff\Infrastructure\PdoPermissionPolicyReadRepository;
use EduCore\Modules\Staff\Infrastructure\PdoPermissionQuotaLedgerRepository;
use EduCore\Modules\Staff\Infrastructure\PdoPermissionRequestRepository;

final class PermissionRequestIntegrationAssignment implements StaffAssignmentAtDateQuery
{
    public function forStaff(int $staffId, DateTimeImmutable $atDate): ?array
    {
        return $staffId === 10 ? [
            'assignment_id' => 81,
            'org_unit_id' => 11,
            'job_title_id' => 12,
            'group_ids' => [13],
            'employment_status' => 'active',
        ] : null;
    }
}

final class PermissionRequestIntegrationAuthorization implements PermissionRequestAuthorization
{
    public function assertCanAct(int $actorId, int $staffUserId, string $action, DateTimeImmutable $atInstant): void
    {
        if ($actorId !== $staffUserId) {
            throw new DomainException('PERMISSION_REQUEST_OWNER_ONLY');
        }
    }

    public function assertCanSubmitRetroactive(
        int $actorId,
        int $staffUserId,
        int $permissionTypeId,
        DateTimeImmutable $fromAt,
        DateTimeImmutable $atInstant
    ): void {
        throw new DomainException('PERMISSION_REQUEST_RETROACTIVE_AUTHORIZATION_REQUIRED');
    }

    public function canOverrideQuota(
        int $actorId,
        int $staffUserId,
        int $permissionTypeId,
        DateTimeImmutable $atInstant
    ): bool {
        return $actorId === 10 && $staffUserId === 10 && $permissionTypeId === 1;
    }
}

final class PermissionRequestIntegrationWorkflow implements PermissionSubmissionWorkflowResolver
{
    public function resolveForSubmission(
        int $actorId,
        int $staffUserId,
        int $permissionTypeId,
        array $request,
        array $policy,
        array $assignment,
        DateTimeImmutable $submittedAt
    ): array {
        return [
            'workflow_version_id' => 701,
            'snapshot' => ['resource_type' => 'staff_permission_request', 'version_no' => 1],
        ];
    }
}

final class PermissionRequestIntegrationApprovalWorkflow implements ApprovalWorkflowSubmissionGateway
{
    private int $nextInstanceId = 8000;

    /** @var list<array<string,mixed>> */
    public array $submissions = [];

    public function submit(array $command): array
    {
        $instanceId = $this->nextInstanceId++;
        $this->submissions[] = $command + ['instance_id' => $instanceId];

        return ['instance_id' => $instanceId, 'replayed' => false];
    }
}

final class PermissionRequestIntegrationClock implements PermissionRequestClock
{
    public function now(): DateTimeImmutable
    {
        return new DateTimeImmutable('2026-10-01 08:00:00', new DateTimeZone('Africa/Cairo'));
    }
}

final class PermissionRequestIntegrationAudit implements AuditEventWriter
{
    public bool $fail = false;

    /** @var list<string> */
    public array $actions = [];

    public function recordEvent(
        string $action,
        ?string $entityType,
        mixed $recordId,
        ?string $name,
        array $details = [],
        array $context = []
    ): void {
        if ($this->fail) {
            throw new RuntimeException('PERMISSION_REQUEST_AUDIT_FAILED');
        }
        $this->actions[] = $action;
    }
}

$failures = 0;
$assert = static function (bool $condition, string $message) use (&$failures): void {
    if (!$condition) {
        fwrite(STDERR, "FAIL: {$message}\n");
        ++$failures;
    }
};
$assertThrows = static function (callable $callback, string $code, string $message) use ($assert): void {
    try {
        $callback();
        $assert(false, $message);
    } catch (Throwable $exception) {
        $assert(str_contains($exception->getMessage(), $code), $message . ' (' . $exception->getMessage() . ')');
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

    $migration = require dirname(__DIR__) . '/database/migrations/20260730_staff_hr_permissions_quota.php';
    $migration($db);
    $db->exec('CREATE TABLE users (id INT NOT NULL PRIMARY KEY) ENGINE=InnoDB');
    $db->exec('INSERT INTO users (id) VALUES (10)');

    $type = $db->prepare(
        "INSERT INTO staff_permission_types
            (code, name, coverage_behavior, requires_reason, allow_retroactive, status)
         VALUES ('LATE_ARRIVAL', 'Late arrival', 'late_arrival', 1, 0, 'active')"
    );
    $type->execute();
    $typeId = (int) $db->lastInsertId();
    $policy = $db->prepare(
        "INSERT INTO staff_permission_policy_versions
            (permission_type_id, version_no, state, valid_from, timezone,
             max_requests_per_month, max_minutes_per_request, max_minutes_per_month,
             min_notice_minutes, retroactive_limit_days, reserve_on_submit,
             allow_overlap, allow_quota_override, quota_override_max_minutes,
             created_by)
         VALUES (?, 1, 'draft', '2026-01-01 00:00:00.000000', 'Africa/Cairo',
                 5, 180, 180, 30, 0, 1, 0, 1, 60, 900)"
    );
    $policy->execute([$typeId]);
    $policyId = (int) $db->lastInsertId();
    $scope = $db->prepare(
        "INSERT INTO staff_permission_policy_scopes
            (policy_version_id, scope_type, scope_id, priority, valid_from, status)
         VALUES (?, 'global', 0, 0, '2026-01-01 00:00:00.000000', 'active')"
    );
    $scope->execute([$policyId]);
    $publishPolicy = $db->prepare(
        "UPDATE staff_permission_policy_versions
         SET state = 'published', published_by = 900, published_at = '2026-01-01 00:00:00.000000'
         WHERE id = ?"
    );
    $publishPolicy->execute([$policyId]);

    $requestRepository = new PdoPermissionRequestRepository($db);
    $audit = new PermissionRequestIntegrationAudit();
    $approvalWorkflow = new PermissionRequestIntegrationApprovalWorkflow();
    $service = new PermissionRequestService(
        $requestRepository,
        $requestRepository,
        new PermissionPolicyResolver(
            new PdoPermissionPolicyReadRepository($db),
            new PermissionRequestIntegrationAssignment()
        ),
        new PermissionQuotaLedger(new PdoPermissionQuotaLedgerRepository($db), $audit),
        new PermissionRequestIntegrationAuthorization(),
        new PermissionRequestIntegrationWorkflow(),
        $audit,
        new PermissionRequestIntegrationClock(),
        $approvalWorkflow
    );

    $crossMonth = $service->createDraft([
        'actor_id' => 10,
        'staff_user_id' => 10,
        'permission_type_id' => $typeId,
        'from_at' => '2026-10-31T23:00',
        'to_at' => '2026-11-01T01:00',
        'timezone' => 'Africa/Cairo',
        'reason' => 'Documented mission',
        'create_idempotency_key' => 'integration-cross-create',
    ]);
    $crossSubmitted = $service->submit([
        'actor_id' => 10,
        'request_id' => $crossMonth['request_id'],
        'expected_lock_version' => $crossMonth['lock_version'],
        'submission_idempotency_key' => 'integration-cross-submit',
    ]);
    $assert($crossSubmitted['status'] === 'pending_approval', 'real PDO service submits a cross-month permission');
    $assert(
        (int) ($crossSubmitted['workflow_instance_id'] ?? 0) > 0
        && count($approvalWorkflow->submissions) === 1,
        'real PDO submission attaches the workflow-instance evidence before committing'
    );
    $periodCount = $db->prepare('SELECT COUNT(*) FROM staff_permission_request_periods WHERE request_id = ?');
    $periodCount->execute([$crossMonth['request_id']]);
    $assert((int) $periodCount->fetchColumn() === 2, 'real PDO service stores two exact monthly request periods');
    $movementCount = $db->prepare('SELECT COUNT(*) FROM staff_permission_quota_movements WHERE request_id = ?');
    $movementCount->execute([$crossMonth['request_id']]);
    $assert((int) $movementCount->fetchColumn() === 2, 'real PDO service reserves an immutable movement per month');

    $override = $service->createDraft([
        'actor_id' => 10,
        'staff_user_id' => 10,
        'permission_type_id' => $typeId,
        'from_at' => '2026-10-20T10:00',
        'to_at' => '2026-10-20T13:00',
        'timezone' => 'Africa/Cairo',
        'reason' => 'Emergency coverage',
        'create_idempotency_key' => 'integration-override-create',
    ]);
    $overrideSubmitted = $service->submit([
        'actor_id' => 10,
        'request_id' => $override['request_id'],
        'expected_lock_version' => $override['lock_version'],
        'submission_idempotency_key' => 'integration-override-submit',
        'quota_exception_reason' => 'authorized emergency excess',
    ]);
    $assert(
        $overrideSubmitted['quota_exception'] && $overrideSubmitted['lock_version'] === 4,
        'real quota overflow and workflow attachment each advance the lock after reservation succeeds'
    );
    $exceptionState = $db->prepare(
        'SELECT quota_exception, quota_exception_reason FROM staff_permission_requests WHERE id = ?'
    );
    $exceptionState->execute([$override['request_id']]);
    $exceptionRow = $exceptionState->fetch(PDO::FETCH_ASSOC) ?: [];
    $assert(
        (int) ($exceptionRow['quota_exception'] ?? 0) === 1
        && ($exceptionRow['quota_exception_reason'] ?? '') === 'authorized emergency excess',
        'real request row retains the precise quota exception evidence'
    );

    $cancelled = $service->cancel([
        'actor_id' => 10,
        'request_id' => $crossMonth['request_id'],
        'expected_lock_version' => $crossSubmitted['lock_version'],
        'reason' => 'No longer needed',
    ]);
    $assert($cancelled['status'] === 'cancelled', 'real PDO cancellation transitions only the pending request');
    $movementCount->execute([$crossMonth['request_id']]);
    $assert((int) $movementCount->fetchColumn() === 4, 'real PDO cancellation appends release movements rather than editing reservations');
    $released = $db->query(
        "SELECT reserved_count, reserved_minutes
         FROM staff_permission_quota_accounts
         WHERE staff_user_id = 10 AND permission_type_id = {$typeId} AND period_key = '2026-11'"
    )->fetch(PDO::FETCH_ASSOC) ?: [];
    $assert(
        (int) ($released['reserved_count'] ?? -1) === 0
        && (int) ($released['reserved_minutes'] ?? -1) === 0,
        'cancellation leaves its released November account counters balanced without touching another October request'
    );

    $rollbackDraft = $service->createDraft([
        'actor_id' => 10,
        'staff_user_id' => 10,
        'permission_type_id' => $typeId,
        'from_at' => '2026-11-25T10:00',
        'to_at' => '2026-11-25T11:00',
        'timezone' => 'Africa/Cairo',
        'reason' => 'Audit rollback proof',
        'create_idempotency_key' => 'integration-audit-rollback-create',
    ]);
    $audit->fail = true;
    $assertThrows(
        static fn (): array => $service->submit([
            'actor_id' => 10,
            'request_id' => $rollbackDraft['request_id'],
            'expected_lock_version' => $rollbackDraft['lock_version'],
            'submission_idempotency_key' => 'integration-audit-rollback-submit',
        ]),
        'PERMISSION_REQUEST_AUDIT_FAILED',
        'real mandatory audit failure aborts the full permission submission'
    );
    $audit->fail = false;
    $rollbackState = $db->prepare(
        'SELECT status, policy_version_id FROM staff_permission_requests WHERE id = ?'
    );
    $rollbackState->execute([$rollbackDraft['request_id']]);
    $rollbackRow = $rollbackState->fetch(PDO::FETCH_ASSOC) ?: [];
    $assert(
        ($rollbackRow['status'] ?? '') === 'draft' && ($rollbackRow['policy_version_id'] ?? null) === null,
        'audit rollback restores the real request to its draft-without-snapshot state'
    );
    $periodCount->execute([$rollbackDraft['request_id']]);
    $movementCount->execute([$rollbackDraft['request_id']]);
    $assert(
        (int) $periodCount->fetchColumn() === 0 && (int) $movementCount->fetchColumn() === 0,
        'audit rollback leaves no real monthly allocation or quota movement behind'
    );

    $assert(
        in_array('staff_permission_request_submitted', $audit->actions, true)
        && in_array('staff_permission_request_cancelled', $audit->actions, true),
        'the real service records audited request lifecycle events'
    );
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
    fwrite(STDERR, "FAIL: temporary request-service database was not removed.\n");
    ++$failures;
}
if ($failures > 0) {
    fwrite(STDERR, "{$failures} permission request integration failure(s).\n");
    exit(1);
}

echo "Staff-HR permission request integration test passed on {$databaseName}; temporary database removed.\n";
