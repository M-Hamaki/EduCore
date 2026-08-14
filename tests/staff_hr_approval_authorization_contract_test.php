<?php

declare(strict_types=1);

/**
 * Isolated proof that a frozen approval assignment is revalidated against
 * current account, employment, and delegation evidence before it writes.
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

$root = dirname(__DIR__);
require_once $root . '/vendor/autoload.php';
require_once $root . '/src/Modules/Staff/bootstrap.php';

use EduCore\Modules\Operations\Audit\AuditEventWriter;
use EduCore\Modules\Staff\Application\Approval\ApprovalNotificationService;
use EduCore\Modules\Staff\Application\Approval\ApprovalWorkflowAuthorization;
use EduCore\Modules\Staff\Application\Approval\ApprovalWorkflowService;
use EduCore\Modules\Staff\Contracts\ApprovalWorkflowOutcomeHandler;
use EduCore\Modules\Staff\Contracts\StaffNotificationPort;
use EduCore\Modules\Staff\Infrastructure\Approval\PdoApprovalActorEligibilityQuery;
use EduCore\Modules\Staff\Infrastructure\Approval\PdoApprovalDelegationRevalidationQuery;
use EduCore\Modules\Staff\Infrastructure\Approval\PdoApprovalWorkflowRepository;

final class ApprovalAuthorizationTestAudit implements AuditEventWriter
{
    public function recordEvent(
        string $action,
        ?string $entityType,
        mixed $recordId,
        ?string $name,
        array $details = [],
        array $context = []
    ): void {
    }
}

final class ApprovalAuthorizationTestOutcome implements ApprovalWorkflowOutcomeHandler
{
    public function apply(array $instance, string $outcome, int $actorId, DateTimeImmutable $occurredAt): void
    {
    }
}

final class ApprovalAuthorizationTestNotifications implements StaffNotificationPort
{
    /** @var list<array<string,mixed>> */
    public array $calls = [];

    public function notifyRecipients(
        string $eventKey,
        array $recipientIds,
        string $secureRoute,
        string $neutralText,
        array $metadata,
        string $idempotencyKey
    ): array {
        $this->calls[] = compact(
            'eventKey',
            'recipientIds',
            'secureRoute',
            'neutralText',
            'metadata',
            'idempotencyKey'
        );

        return [
            'accepted' => true,
            'status' => 'queued',
            'receipt_id' => $idempotencyKey,
            'inbox_count' => count($recipientIds),
            'outbox_count' => count($recipientIds),
        ];
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
    $db->exec('CREATE TABLE users (id INTEGER PRIMARY KEY, role TEXT NOT NULL, status TEXT NOT NULL)');
    $db->exec(
        'CREATE TABLE staff_assignments (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            staff_user_id INTEGER NOT NULL,
            assignment_kind TEXT NOT NULL,
            valid_from TEXT NOT NULL,
            valid_to TEXT NULL
        )'
    );
    $db->exec(
        'CREATE TABLE staff_delegations (
            id INTEGER PRIMARY KEY,
            delegator_user_id INTEGER NOT NULL,
            delegate_user_id INTEGER NOT NULL,
            status TEXT NOT NULL,
            valid_from TEXT NOT NULL,
            valid_to TEXT NOT NULL
        )'
    );
    $db->exec(
        'CREATE TABLE staff_approval_instances (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            resource_type TEXT NOT NULL,
            resource_id INTEGER NOT NULL,
            workflow_version_id INTEGER NOT NULL,
            status TEXT NOT NULL,
            current_sequence INTEGER NOT NULL,
            started_at TEXT NOT NULL,
            completed_at TEXT NULL,
            snapshot_json TEXT NOT NULL,
            lock_version INTEGER NOT NULL,
            idempotency_key TEXT NOT NULL UNIQUE
        )'
    );
    $db->exec(
        'CREATE TABLE staff_approval_steps (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            instance_id INTEGER NOT NULL,
            stage_id INTEGER NOT NULL,
            sequence_no INTEGER NOT NULL,
            status TEXT NOT NULL,
            due_at TEXT NULL,
            activated_at TEXT NULL,
            completed_at TEXT NULL,
            snapshot_json TEXT NOT NULL,
            lock_version INTEGER NOT NULL,
            UNIQUE(instance_id, sequence_no)
        )'
    );
    $db->exec(
        'CREATE TABLE staff_approval_assignees (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            step_id INTEGER NOT NULL,
            assignee_user_id INTEGER NOT NULL,
            relationship_kind TEXT NOT NULL,
            assignment_snapshot TEXT NOT NULL,
            status TEXT NOT NULL,
            UNIQUE(step_id, assignee_user_id)
        )'
    );
    $db->exec(
        'CREATE TABLE staff_approval_decisions (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            step_id INTEGER NOT NULL,
            actor_user_id INTEGER NOT NULL,
            acting_for_user_id INTEGER NULL,
            decision TEXT NOT NULL,
            comment TEXT NULL,
            decided_at TEXT NOT NULL,
            idempotency_key TEXT NOT NULL UNIQUE,
            is_effective INTEGER NOT NULL,
            UNIQUE(step_id, actor_user_id)
        )'
    );
    $db->exec(
        'CREATE TABLE staff_approval_escalation_events (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            step_id INTEGER NOT NULL,
            event_type TEXT NOT NULL,
            from_assignee INTEGER NULL,
            to_assignee INTEGER NULL,
            reason TEXT NOT NULL,
            created_by INTEGER NULL,
            created_at TEXT NOT NULL
        )'
    );

    return $db;
};

$addUser = static function (PDO $db, int $id, string $status = 'active', string $role = 'teacher'): void {
    $statement = $db->prepare('INSERT INTO users (id, role, status) VALUES (?, ?, ?)');
    $statement->execute([$id, $role, $status]);
};
$addPrimaryAssignment = static function (PDO $db, int $userId, string $validTo = null): void {
    $statement = $db->prepare(
        'INSERT INTO staff_assignments (staff_user_id, assignment_kind, valid_from, valid_to) VALUES (?, ?, ?, ?)'
    );
    $statement->execute([$userId, 'primary', '2026-01-01', $validTo]);
};
$service = static function (PDO $db, ?StaffNotificationPort $notifications = null): ApprovalWorkflowService {
    $notificationService = $notifications === null ? null : new ApprovalNotificationService($notifications);
    $authorization = new ApprovalWorkflowAuthorization(
        new PdoApprovalActorEligibilityQuery($db),
        new PdoApprovalDelegationRevalidationQuery($db)
    );

    return new ApprovalWorkflowService(
        new PdoApprovalWorkflowRepository($db),
        new ApprovalAuthorizationTestOutcome(),
        new ApprovalAuthorizationTestAudit(),
        new DateTimeZone('UTC'),
        $notificationService,
        $authorization
    );
};
$stage = static function (
    int $userId,
    string $relationshipKind = 'direct_manager',
    array $assignmentSnapshot = [],
    ?int $actingForUserId = null,
    ?int $delegationId = null
): array {
    return [
        'stage_id' => 101,
        'sequence_no' => 1,
        'name' => 'Manager approval',
        'resolver_type' => 'direct_manager',
        'decision_mode' => 'sequential',
        'quorum_count' => null,
        'sla_minutes' => 30,
        'on_timeout' => 'escalate',
        'self_approval_rule' => 'forbid',
        'same_actor_rule' => 'forbid',
        'tie_rule' => 'reject',
        'rejection_rule' => 'stop_workflow',
        'merged_actor_ids' => [],
        'assignees' => [[
            'user_id' => $userId,
            'relationship_kind' => $relationshipKind,
            'acting_for_user_id' => $actingForUserId,
            'delegation_id' => $delegationId,
            'assignment_snapshot' => $assignmentSnapshot + ['manager_user_id' => $userId],
        ]],
    ];
};
$snapshot = static function (array $stage, string $sensitive = ''): array {
    return [
        'schema_version' => 1,
        'resource_type' => 'permission_request',
        'workflow_id' => 1,
        'workflow_code' => 'AUTHORIZATION_TEST',
        'workflow_name' => 'Authorization test',
        'workflow_version_no' => 1,
        'valid_from' => '2026-01-01 00:00:00.000000',
        'valid_to' => null,
        'cancellation_rule' => 'workflow_required',
        'escalation_rule' => [],
        'effective_at' => '2026-10-01 08:00:00.000000',
        'resolved_at' => '2026-10-01 08:00:00.000000',
        'context' => [
            'staff_user_id' => 999,
            'reason' => $sensitive,
            'attachment_name' => $sensitive === '' ? null : 'private-' . $sensitive . '.pdf',
        ],
        'stages' => [$stage],
    ];
};
$submit = static function (ApprovalWorkflowService $service, array $snapshot, int $resourceId, string $key): array {
    return $service->submit([
        'actor_id' => 999,
        'resource_type' => 'permission_request',
        'resource_id' => $resourceId,
        'workflow_version_id' => 1,
        'snapshot' => $snapshot,
        'idempotency_key' => $key,
        'submitted_at' => '2026-10-01 08:00:00.000000',
    ]);
};
$stepFor = static function (PDO $db, int $instanceId): array {
    $statement = $db->prepare('SELECT * FROM staff_approval_steps WHERE instance_id = ? LIMIT 1');
    $statement->execute([$instanceId]);
    $row = $statement->fetch(PDO::FETCH_ASSOC);
    if (!is_array($row)) {
        throw new RuntimeException('APPROVAL_TEST_STEP_NOT_FOUND');
    }

    return $row;
};
$decision = static function (ApprovalWorkflowService $service, array $step, int $actorId, string $key): array {
    return $service->decide([
        'actor_id' => $actorId,
        'step_id' => (int) $step['id'],
        'expected_lock_version' => (int) $step['lock_version'],
        'decision' => 'approve',
        'idempotency_key' => $key,
        'decided_at' => '2026-10-01 08:10:00.000000',
    ]);
};

try {
    // Only a current direct manager may decide; a normal valid approval still succeeds.
    $db = $newDatabase();
    $addUser($db, 10);
    $addPrimaryAssignment($db, 10);
    $workflow = $service($db);
    $submission = $submit($workflow, $snapshot($stage(10)), 101, 'authorization-allowed');
    $result = $decision($workflow, $stepFor($db, $submission['instance_id']), 10, 'authorization-allowed-decision');
    $assert($result['instance_status'] === 'approved', 'active manager with current service can decide');

    // An inactive account is rejected even though its identifier remains frozen in the stage.
    $db = $newDatabase();
    $addUser($db, 11, 'inactive');
    $addPrimaryAssignment($db, 11);
    $workflow = $service($db);
    $submission = $submit($workflow, $snapshot($stage(11)), 102, 'authorization-session');
    $step = $stepFor($db, $submission['instance_id']);
    $assertError(
        static fn (): array => $decision($workflow, $step, 11, 'authorization-session-decision'),
        'APPROVAL_SESSION_REVALIDATION_FAILED',
        'inactive assigned account cannot use a historical assignment'
    );

    // Ending the manager's primary service prevents a later decision without rewriting the past snapshot.
    $db = $newDatabase();
    $addUser($db, 12);
    $addPrimaryAssignment($db, 12, '2026-09-30');
    $workflow = $service($db);
    $submission = $submit($workflow, $snapshot($stage(12)), 103, 'authorization-service-ended');
    $step = $stepFor($db, $submission['instance_id']);
    $assertError(
        static fn (): array => $decision($workflow, $step, 12, 'authorization-service-ended-decision'),
        'APPROVAL_ACTOR_SERVICE_ENDED',
        'ended service prevents manager approval at the new date'
    );

    // A delegated manager needs the same exact delegation to remain live at decision time.
    $db = $newDatabase();
    $addUser($db, 13);
    $addPrimaryAssignment($db, 13);
    $addUser($db, 14);
    $db->exec(
        "INSERT INTO staff_delegations (id, delegator_user_id, delegate_user_id, status, valid_from, valid_to)
         VALUES (99, 14, 13, 'active', '2026-10-01 07:00:00.000000', '2026-10-01 08:05:00.000000')"
    );
    $workflow = $service($db);
    $submission = $submit(
        $workflow,
        $snapshot($stage(
            13,
            'delegated_direct_manager',
            ['acting_for_user_id' => 14, 'delegation_id' => 99],
            14,
            99
        )),
        104,
        'authorization-delegation-ended'
    );
    $step = $stepFor($db, $submission['instance_id']);
    $assertError(
        static fn (): array => $decision($workflow, $step, 13, 'authorization-delegation-ended-decision'),
        'APPROVAL_DELEGATION_EXPIRED',
        'expired delegation cannot authorize a delegated decision'
    );

    // A guessed step id cannot become an IDOR path for another active manager.
    $db = $newDatabase();
    $addUser($db, 10);
    $addPrimaryAssignment($db, 10);
    $addUser($db, 15);
    $addPrimaryAssignment($db, 15);
    $workflow = $service($db);
    $submission = $submit($workflow, $snapshot($stage(10)), 105, 'authorization-idor');
    $step = $stepFor($db, $submission['instance_id']);
    $assertError(
        static fn (): array => $decision($workflow, $step, 15, 'authorization-idor-decision'),
        'NOT_ASSIGNED_APPROVER',
        'active non-assignee cannot decide a guessed approval step'
    );

    // Submission notification is neutral and omits frozen private payloads from both text and metadata.
    $db = $newDatabase();
    $notifications = new ApprovalAuthorizationTestNotifications();
    $workflow = $service($db, $notifications);
    $secret = 'PRIVATE-REQUEST-REASON-ONLY';
    $submit(
        $workflow,
        $snapshot($stage(10, 'direct_manager', ['private_attachment' => $secret]), $secret),
        106,
        'authorization-notification-neutral'
    );
    $call = $notifications->calls[0] ?? [];
    $serializedCall = json_encode($call, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE);
    $metadata = is_array($call['metadata'] ?? null) ? $call['metadata'] : [];
    $assert(($call['neutralText'] ?? null) === 'لديك اعتماد جديد يحتاج إلى قرار.', 'approval notification uses neutral text');
    $assert(!str_contains($serializedCall, $secret), 'notification does not leak reason or attachment evidence');
    $assert(
        array_intersect(array_keys($metadata), ['reason', 'attachment_name', 'assignment_snapshot', 'snapshot']) === [],
        'notification metadata contains no private snapshot fields'
    );
} catch (Throwable $exception) {
    fwrite(STDERR, 'FAIL: unexpected exception: ' . $exception->getMessage() . PHP_EOL);
    ++$failures;
}

if ($failures > 0) {
    exit(1);
}

echo "Staff HR approval authorization contract: PASS\n";
