<?php

declare(strict_types=1);

/** Isolated proof for neutral approval notifications and transactional outbox intent. */

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

$root = dirname(__DIR__);
require_once $root . '/vendor/autoload.php';
require_once $root . '/src/Modules/Staff/bootstrap.php';

use EduCore\Modules\Operations\Audit\AuditEventWriter;
use EduCore\Modules\Staff\Application\Approval\ApprovalNotificationService;
use EduCore\Modules\Staff\Application\Approval\ApprovalWorkflowService;
use EduCore\Modules\Staff\Contracts\ApprovalWorkflowOutcomeHandler;
use EduCore\Modules\Staff\Contracts\StaffNotificationPort;
use EduCore\Modules\Staff\Infrastructure\Approval\PdoApprovalWorkflowRepository;

final class ApprovalNotificationTestPort implements StaffNotificationPort
{
    /** @var list<array<string,mixed>> */
    public array $calls = [];
    public bool $failNext = false;

    public function notifyRecipients(
        string $eventKey,
        array $recipientIds,
        string $secureRoute,
        string $neutralText,
        array $metadata,
        string $idempotencyKey
    ): array {
        if ($this->failNext) {
            $this->failNext = false;
            throw new RuntimeException('NOTIFICATION_OUTBOX_FAILED');
        }
        $this->calls[] = compact('eventKey', 'recipientIds', 'secureRoute', 'neutralText', 'metadata', 'idempotencyKey');

        return [
            'accepted' => true,
            'status' => 'queued',
            'receipt_id' => 'test-' . count($this->calls),
            'inbox_count' => count($recipientIds),
            'outbox_count' => count($recipientIds),
        ];
    }
}

final class ApprovalNotificationTestAudit implements AuditEventWriter
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

final class ApprovalNotificationTestOutcome implements ApprovalWorkflowOutcomeHandler
{
    public function apply(array $instance, string $outcome, int $actorId, \DateTimeImmutable $occurredAt): void
    {
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
    $db->exec('CREATE TABLE staff_approval_instances (
        id INTEGER PRIMARY KEY AUTOINCREMENT, resource_type TEXT, resource_id INTEGER, workflow_version_id INTEGER,
        status TEXT, current_sequence INTEGER, started_at TEXT, completed_at TEXT NULL, snapshot_json TEXT,
        lock_version INTEGER, idempotency_key TEXT UNIQUE
    )');
    $db->exec('CREATE TABLE staff_approval_steps (
        id INTEGER PRIMARY KEY AUTOINCREMENT, instance_id INTEGER, stage_id INTEGER, sequence_no INTEGER, status TEXT,
        due_at TEXT NULL, activated_at TEXT NULL, completed_at TEXT NULL, snapshot_json TEXT, lock_version INTEGER
    )');
    $db->exec('CREATE TABLE staff_approval_assignees (
        id INTEGER PRIMARY KEY AUTOINCREMENT, step_id INTEGER, assignee_user_id INTEGER, relationship_kind TEXT,
        assignment_snapshot TEXT, status TEXT
    )');
    $db->exec('CREATE TABLE staff_approval_decisions (
        id INTEGER PRIMARY KEY AUTOINCREMENT, step_id INTEGER, actor_user_id INTEGER, acting_for_user_id INTEGER NULL,
        decision TEXT, comment TEXT NULL, decided_at TEXT, idempotency_key TEXT UNIQUE, is_effective INTEGER
    )');
    $db->exec('CREATE TABLE staff_approval_escalation_events (
        id INTEGER PRIMARY KEY AUTOINCREMENT, step_id INTEGER, event_type TEXT, from_assignee INTEGER NULL,
        to_assignee INTEGER NULL, reason TEXT, created_by INTEGER NULL, created_at TEXT
    )');

    return $db;
};

$stage = static function (int $id, int $sequence, int $userId): array {
    return [
        'stage_id' => $id,
        'sequence_no' => $sequence,
        'name' => 'Stage ' . $sequence,
        'resolver_type' => 'named_users',
        'decision_mode' => 'sequential',
        'quorum_count' => null,
        'sla_minutes' => null,
        'on_timeout' => 'fail_closed',
        'self_approval_rule' => 'forbid',
        'same_actor_rule' => 'forbid',
        'tie_rule' => 'reject',
        'rejection_rule' => 'stop_workflow',
        'merged_actor_ids' => [],
        'assignees' => [[
            'user_id' => $userId,
            'relationship_kind' => 'named_user',
            'acting_for_user_id' => null,
            'delegation_id' => null,
            'assignment_snapshot' => ['source' => 'notification-test'],
        ]],
    ];
};

$snapshot = static function (array $stages): array {
    return [
        'context' => ['staff_user_id' => 900],
        'stages' => $stages,
    ];
};

try {
    $port = new ApprovalNotificationTestPort();
    $notifications = new ApprovalNotificationService($port);
    $receipt = $notifications->notifyAssignees(
        ['id' => 10, 'resource_type' => 'permission_request', 'resource_id' => 20, 'workflow_version_id' => 30],
        40,
        [['user_id' => 8], ['assignee_user_id' => 7], ['user_id' => 8]]
    );
    $assert($receipt !== null && $receipt['status'] === 'queued' && count($port->calls) === 1, 'neutral approval assignment is queued through the notification port');
    $call = $port->calls[0];
    $assert($call['recipientIds'] === [7, 8] && $call['eventKey'] === 'staff-approval:10:step:40:assigned:40', 'recipients are unique, ordered, and tied to a stable stage event');
    $assert($call['secureRoute'] === 'admin/hr_center.php?tab=approvals' && $call['neutralText'] === 'لديك اعتماد جديد يحتاج إلى قرار.', 'notification uses a neutral manager route and neutral text');
    $encoded = json_encode($call['metadata'], JSON_THROW_ON_ERROR);
    $assert(!str_contains($encoded, 'reason') && !str_contains($encoded, 'attachment') && !str_contains($encoded, 'snapshot'), 'notification metadata contains no confidential request content');
    $assert($notifications->notifyAssignees(
        ['id' => 10, 'resource_type' => 'permission_request', 'resource_id' => 20, 'workflow_version_id' => 30],
        40,
        []
    ) === null, 'an empty merged/removed assignee set produces no orphan outbox event');
    $assertError(
        static fn (): ApprovalNotificationService => new ApprovalNotificationService($port, 'https://outside.example/'),
        'APPROVAL_NOTIFICATION_ROUTE_INVALID',
        'notification route cannot leave the application boundary'
    );

    $db = $newDatabase();
    $workflowPort = new ApprovalNotificationTestPort();
    $workflow = new ApprovalWorkflowService(
        new PdoApprovalWorkflowRepository($db),
        new ApprovalNotificationTestOutcome(),
        new ApprovalNotificationTestAudit(),
        new DateTimeZone('Africa/Cairo'),
        new ApprovalNotificationService($workflowPort)
    );
    $submitted = $workflow->submit([
        'actor_id' => 900,
        'resource_type' => 'permission_request',
        'resource_id' => 50,
        'workflow_version_id' => 60,
        'snapshot' => $snapshot([$stage(1, 1, 101), $stage(2, 2, 102)]),
        'idempotency_key' => 'notification-workflow-submit',
        'submitted_at' => '2026-10-01 08:00:00.000000',
    ]);
    $assert(count($workflowPort->calls) === 1 && $workflowPort->calls[0]['recipientIds'] === [101], 'submission queues the first active stage assignee');
    $firstStep = $db->prepare('SELECT id, lock_version FROM staff_approval_steps WHERE instance_id = ? AND sequence_no = 1');
    $firstStep->execute([$submitted['instance_id']]);
    $first = $firstStep->fetch(PDO::FETCH_ASSOC);
    $workflow->decide([
        'actor_id' => 101,
        'step_id' => (int) $first['id'],
        'expected_lock_version' => (int) $first['lock_version'],
        'decision' => 'approve',
        'idempotency_key' => 'notification-workflow-decision',
        'decided_at' => '2026-10-01 08:10:00.000000',
    ]);
    $assert(count($workflowPort->calls) === 2 && $workflowPort->calls[1]['recipientIds'] === [102], 'activation queues only the next stage assignee');

    $rollbackDb = $newDatabase();
    $failingPort = new ApprovalNotificationTestPort();
    $failingPort->failNext = true;
    $failingWorkflow = new ApprovalWorkflowService(
        new PdoApprovalWorkflowRepository($rollbackDb),
        new ApprovalNotificationTestOutcome(),
        new ApprovalNotificationTestAudit(),
        new DateTimeZone('Africa/Cairo'),
        new ApprovalNotificationService($failingPort)
    );
    $assertError(
        static fn (): array => $failingWorkflow->submit([
            'actor_id' => 900,
            'resource_type' => 'permission_request',
            'resource_id' => 70,
            'workflow_version_id' => 80,
            'snapshot' => $snapshot([$stage(3, 1, 103)]),
            'idempotency_key' => 'notification-workflow-failure',
            'submitted_at' => '2026-10-01 08:00:00.000000',
        ]),
        'NOTIFICATION_OUTBOX_FAILED',
        'outbox enqueue failure fails the workflow submission closed'
    );
    $assert((int) $rollbackDb->query('SELECT COUNT(*) FROM staff_approval_instances')->fetchColumn() === 0, 'outbox failure leaves no partial approval state');
} catch (Throwable $exception) {
    fwrite(STDERR, 'FAIL: approval notification exercise failed: ' . $exception->getMessage() . PHP_EOL);
    ++$failures;
}

if ($failures > 0) {
    fwrite(STDERR, "{$failures} approval notification failure(s).\n");
    exit(1);
}

echo "Staff-HR approval notification service test passed.\n";
