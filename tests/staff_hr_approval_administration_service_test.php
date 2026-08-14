<?php

declare(strict_types=1);

/** Isolated write/audit proof for workflow and delegation administration. */

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

$root = dirname(__DIR__);
require_once $root . '/vendor/autoload.php';
require_once $root . '/src/Modules/Staff/bootstrap.php';

use EduCore\Modules\Operations\Audit\AuditEventWriter;
use EduCore\Modules\Staff\Application\Approval\ApprovalWorkflowAdministrationService;
use EduCore\Modules\Staff\Infrastructure\Approval\PdoApprovalWorkflowAdministrationRepository;

final class ApprovalAdministrationTestAudit implements AuditEventWriter
{
    /** @var list<array<string,mixed>> */
    public array $events = [];
    public bool $failNext = false;

    public function recordEvent(
        string $action,
        ?string $entityType,
        mixed $recordId,
        ?string $name,
        array $details = [],
        array $context = []
    ): void {
        if ($this->failNext) {
            $this->failNext = false;
            throw new RuntimeException('AUDIT_WRITE_FAILED');
        }
        $this->events[] = compact('action', 'entityType', 'recordId', 'name', 'details', 'context');
    }
}

$failures = 0;
$assert = static function (bool $condition, string $message) use (&$failures): void {
    if (!$condition) {
        fwrite(STDERR, "FAIL: {$message}\n");
        ++$failures;
    }
};
$assertError = static function (callable $callback, string $expectedCode, string $message) use ($assert): void {
    try {
        $callback();
        $assert(false, $message . ' (no error)');
    } catch (Throwable $exception) {
        $assert($exception->getMessage() === $expectedCode, $message . ' (' . $exception->getMessage() . ')');
    }
};

$newDatabase = static function (): PDO {
    $db = new PDO('sqlite::memory:');
    $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $db->exec('CREATE TABLE users (id INTEGER PRIMARY KEY, name TEXT NOT NULL, username TEXT NOT NULL, role TEXT NOT NULL, status TEXT NOT NULL)');
    $db->exec('CREATE TABLE user_role_assignments (id INTEGER PRIMARY KEY AUTOINCREMENT, user_id INTEGER NOT NULL, role_key TEXT NOT NULL, status TEXT NOT NULL)');
    $db->exec(
        'CREATE TABLE staff_approval_workflows (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            code TEXT NOT NULL UNIQUE,
            name TEXT NOT NULL,
            resource_type TEXT NOT NULL,
            status TEXT NOT NULL,
            created_by INTEGER NULL,
            created_at TEXT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at TEXT NULL DEFAULT CURRENT_TIMESTAMP
        )'
    );
    $db->exec(
        'CREATE TABLE staff_approval_workflow_versions (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            workflow_id INTEGER NOT NULL,
            version_no INTEGER NOT NULL,
            state TEXT NOT NULL,
            valid_from TEXT NOT NULL,
            valid_to TEXT NULL,
            cancellation_rule TEXT NOT NULL,
            escalation_rule TEXT NULL,
            supersedes_id INTEGER NULL,
            published_by INTEGER NULL,
            published_at TEXT NULL,
            created_by INTEGER NULL,
            created_at TEXT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at TEXT NULL DEFAULT CURRENT_TIMESTAMP,
            UNIQUE(workflow_id, version_no)
        )'
    );
    $db->exec(
        'CREATE TABLE staff_approval_stages (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            workflow_version_id INTEGER NOT NULL,
            sequence_no INTEGER NOT NULL,
            name TEXT NOT NULL,
            resolver_type TEXT NOT NULL,
            resolver_config TEXT NULL,
            decision_mode TEXT NOT NULL,
            sla_minutes INTEGER NULL,
            on_timeout TEXT NOT NULL,
            self_approval_rule TEXT NOT NULL,
            same_actor_rule TEXT NOT NULL,
            quorum_count INTEGER NULL,
            tie_rule TEXT NOT NULL,
            rejection_rule TEXT NOT NULL,
            created_at TEXT NULL DEFAULT CURRENT_TIMESTAMP,
            UNIQUE(workflow_version_id, sequence_no)
        )'
    );
    $db->exec(
        'CREATE TABLE staff_delegations (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            delegator_user_id INTEGER NOT NULL,
            delegate_user_id INTEGER NOT NULL,
            scope_type TEXT NOT NULL,
            scope_id INTEGER NOT NULL,
            request_types TEXT NULL,
            valid_from TEXT NOT NULL,
            valid_to TEXT NOT NULL,
            reason TEXT NOT NULL,
            status TEXT NOT NULL,
            created_by INTEGER NULL,
            created_at TEXT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at TEXT NULL DEFAULT CURRENT_TIMESTAMP
        )'
    );

    return $db;
};
$addUser = static function (PDO $db, int $id, string $role = 'admin', string $status = 'active'): void {
    $statement = $db->prepare('INSERT INTO users (id, name, username, role, status) VALUES (?, ?, ?, ?, ?)');
    $statement->execute([$id, 'User ' . $id, 'user' . $id, $role, $status]);
};
$workflowInput = static function (?int $workflowId = null, string $start = '2026-10-01T08:00', bool $publish = true): array {
    return [
        'workflow_id' => $workflowId,
        'code' => 'PERMISSION_MAIN',
        'name' => 'مسار أذونات العاملين',
        'resource_type' => 'permission_request',
        'workflow_status' => 'active',
        'valid_from' => $start,
        'valid_to' => '',
        'cancellation_rule' => 'workflow_required',
        'publish_now' => $publish ? '1' : '0',
        'stage_name' => ['المدير المباشر'],
        'stage_resolver_type' => ['direct_manager'],
        'stage_decision_mode' => ['sequential'],
        'stage_sla_minutes' => ['60'],
        'stage_on_timeout' => ['escalate'],
        'stage_self_approval_rule' => ['forbid'],
        'stage_same_actor_rule' => ['forbid'],
        'stage_quorum_count' => [''],
        'stage_tie_rule' => ['reject'],
        'stage_rejection_rule' => ['stop_workflow'],
        'stage_user_ids' => [[]],
        'stage_role_keys' => [[]],
    ];
};

try {
    $db = $newDatabase();
    $addUser($db, 1);
    $addUser($db, 2, 'teacher');
    $addUser($db, 3, 'teacher');
    $audit = new ApprovalAdministrationTestAudit();
    $service = new ApprovalWorkflowAdministrationService(
        new PdoApprovalWorkflowAdministrationRepository($db),
        $audit,
        new DateTimeZone('UTC')
    );

    $first = $service->createWorkflowVersion($workflowInput(), 1);
    $assert($first['published'] === true, 'new workflow can be drafted and published atomically');
    $firstVersion = $db->query('SELECT * FROM staff_approval_workflow_versions WHERE id = ' . (int) $first['version_id'])->fetch(PDO::FETCH_ASSOC);
    $assert(($firstVersion['state'] ?? null) === 'published' && ($firstVersion['valid_to'] ?? null) === null, 'first version is published with open validity');
    $assert((int) $db->query('SELECT COUNT(*) FROM staff_approval_stages')->fetchColumn() === 1, 'published workflow preserves its stage definition');

    $second = $service->createWorkflowVersion($workflowInput($first['workflow_id'], '2026-11-01T08:00'), 1);
    $previous = $db->query('SELECT valid_to FROM staff_approval_workflow_versions WHERE id = ' . (int) $first['version_id'])->fetchColumn();
    $assert($second['published'] === true && $previous === '2026-11-01 08:00:00.000000', 'new open version closes only the previous open version at its start');

    $conflicting = $workflowInput($first['workflow_id'], '2026-12-01T08:00');
    $conflicting['valid_to'] = '2026-12-20T08:00';
    $versionCountBeforeConflict = (int) $db->query('SELECT COUNT(*) FROM staff_approval_workflow_versions')->fetchColumn();
    $assertError(
        static fn (): array => $service->createWorkflowVersion($conflicting, 1),
        'APPROVAL_WORKFLOW_PUBLISH_CONFLICT',
        'bounded version cannot silently overlap an open published version'
    );
    $assert((int) $db->query('SELECT COUNT(*) FROM staff_approval_workflow_versions')->fetchColumn() === $versionCountBeforeConflict, 'publication conflict rolls back the staged version and stages');

    $delegationId = $service->createDelegation([
        'delegator_user_id' => 1,
        'delegate_user_id' => 2,
        'scope_type' => 'global',
        'scope_id' => '',
        'request_types' => ['permission_request'],
        'valid_from' => '2026-10-01T08:00',
        'valid_to' => '2026-10-10T08:00',
        'reason' => 'تغطية إدارية مؤقتة',
        'status' => 'active',
    ], 1);
    $assert($delegationId > 0, 'active delegation is stored with audit evidence');
    $assertError(
        static fn (): int => $service->createDelegation([
            'delegator_user_id' => 1,
            'delegate_user_id' => 3,
            'scope_type' => 'global',
            'scope_id' => '',
            'request_types' => ['permission_request'],
            'valid_from' => '2026-10-05T08:00',
            'valid_to' => '2026-10-12T08:00',
            'reason' => 'تفويض متعارض',
            'status' => 'active',
        ], 1),
        'APPROVAL_DELEGATION_SCOPE_CONFLICT',
        'overlapping active delegation in the exact same scope fails closed'
    );
    $service->endDelegation($delegationId, 'revoked', 1);
    $assertError(
        static function () use ($service, $delegationId): void {
            $service->endDelegation($delegationId, 'suspended', 1);
        },
        'APPROVAL_DELEGATION_TERMINAL',
        'revoked delegation cannot be changed back through the surface'
    );
    $draftDelegationId = $service->createDelegation([
        'delegator_user_id' => 1,
        'delegate_user_id' => 3,
        'scope_type' => 'global',
        'scope_id' => '',
        'request_types' => ['permission_request'],
        'valid_from' => '2026-10-01T08:00',
        'valid_to' => '2026-10-10T08:00',
        'reason' => 'تفويض للمراجعة ثم التفعيل',
        'status' => 'draft',
    ], 1);
    $service->activateDelegation($draftDelegationId, 1);
    $activatedStatus = $db->query('SELECT status FROM staff_delegations WHERE id = ' . $draftDelegationId)->fetchColumn();
    $assert($activatedStatus === 'active', 'draft delegation is revalidated before activation');
    $auditDetails = json_encode($audit->events, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE);
    $assert(!str_contains($auditDetails, 'تغطية إدارية مؤقتة'), 'delegation reason is hashed instead of copied into audit details');

    $rollbackDb = $newDatabase();
    $addUser($rollbackDb, 1);
    $rollbackAudit = new ApprovalAdministrationTestAudit();
    $rollbackAudit->failNext = true;
    $rollbackService = new ApprovalWorkflowAdministrationService(
        new PdoApprovalWorkflowAdministrationRepository($rollbackDb),
        $rollbackAudit,
        new DateTimeZone('UTC')
    );
    $assertError(
        static fn (): array => $rollbackService->createWorkflowVersion($workflowInput(), 1),
        'AUDIT_WRITE_FAILED',
        'mandatory workflow audit failure rolls back the business write'
    );
    $assert((int) $rollbackDb->query('SELECT COUNT(*) FROM staff_approval_workflows')->fetchColumn() === 0, 'audit rollback leaves no workflow behind');
} catch (Throwable $exception) {
    fwrite(STDERR, 'FAIL: unexpected exception: ' . $exception->getMessage() . PHP_EOL);
    ++$failures;
}

if ($failures > 0) {
    exit(1);
}

echo "Staff HR approval administration service: PASS\n";
