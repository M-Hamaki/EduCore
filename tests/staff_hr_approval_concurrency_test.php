<?php

declare(strict_types=1);

/**
 * Isolated state-machine proof for Staff approval instances. No application
 * database is opened or mutated by this test.
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

$root = dirname(__DIR__);
require_once $root . '/vendor/autoload.php';
require_once $root . '/src/Modules/Staff/bootstrap.php';

use EduCore\Modules\Operations\Audit\AuditEventWriter;
use EduCore\Modules\Staff\Application\Approval\ApprovalWorkflowService;
use EduCore\Modules\Staff\Contracts\ApprovalWorkflowOutcomeHandler;
use EduCore\Modules\Staff\Infrastructure\Approval\PdoApprovalWorkflowRepository;

final class ApprovalWorkflowStateTestAudit implements AuditEventWriter
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

final class ApprovalWorkflowStateTestOutcomeHandler implements ApprovalWorkflowOutcomeHandler
{
    /** @var list<array<string,mixed>> */
    public array $outcomes = [];

    public function apply(array $instance, string $outcome, int $actorId, \DateTimeImmutable $occurredAt): void
    {
        $this->outcomes[] = [
            'instance_id' => (int) ($instance['id'] ?? 0),
            'resource_type' => (string) ($instance['resource_type'] ?? ''),
            'resource_id' => (int) ($instance['resource_id'] ?? 0),
            'outcome' => $outcome,
            'actor_id' => $actorId,
            'occurred_at' => $occurredAt->format('Y-m-d H:i:s.u'),
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

$newService = static function (PDO $db, ApprovalWorkflowStateTestOutcomeHandler $outcomes, ApprovalWorkflowStateTestAudit $audit): ApprovalWorkflowService {
    return new ApprovalWorkflowService(
        new PdoApprovalWorkflowRepository($db),
        $outcomes,
        $audit,
        new DateTimeZone('Africa/Cairo')
    );
};

$stage = static function (int $id, int $sequence, string $mode, array $users, array $overrides = []): array {
    $assignees = [];
    foreach ($users as $userId) {
        $assignees[] = [
            'user_id' => $userId,
            'relationship_kind' => 'named_user',
            'acting_for_user_id' => null,
            'delegation_id' => null,
            'assignment_snapshot' => ['source' => 'approval-state-test'],
        ];
    }
    $base = [
        'stage_id' => $id,
        'sequence_no' => $sequence,
        'name' => 'Stage ' . $sequence,
        'resolver_type' => 'named_users',
        'decision_mode' => $mode,
        'quorum_count' => $mode === 'quorum' ? 1 : null,
        'sla_minutes' => null,
        'on_timeout' => 'fail_closed',
        'self_approval_rule' => 'forbid',
        'same_actor_rule' => 'forbid',
        'tie_rule' => 'reject',
        'rejection_rule' => 'stop_workflow',
        'merged_actor_ids' => [],
        'assignees' => $assignees,
    ];

    return array_replace($base, $overrides);
};

$snapshot = static function (array $stages, int $staffUserId = 999): array {
    return [
        'schema_version' => 1,
        'resource_type' => 'permission_request',
        'workflow_id' => 90,
        'workflow_code' => 'PERMISSION_TEST',
        'workflow_name' => 'Permission test',
        'workflow_version_no' => 1,
        'valid_from' => '2026-01-01 00:00:00.000000',
        'valid_to' => null,
        'cancellation_rule' => 'workflow_required',
        'escalation_rule' => [],
        'effective_at' => '2026-10-01 08:00:00.000000',
        'resolved_at' => '2026-10-01 08:00:00.000000',
        'context' => ['staff_user_id' => $staffUserId],
        'stages' => $stages,
    ];
};

$submit = static function (ApprovalWorkflowService $service, array $workflowSnapshot, int $resourceId, string $key): array {
    return $service->submit([
        'actor_id' => 999,
        'resource_type' => 'permission_request',
        'resource_id' => $resourceId,
        'workflow_version_id' => 901,
        'snapshot' => $workflowSnapshot,
        'idempotency_key' => $key,
        'submitted_at' => '2026-10-01 08:00:00.000000',
    ]);
};

$stepBySequence = static function (PDO $db, int $instanceId, int $sequence): array {
    $statement = $db->prepare('SELECT * FROM staff_approval_steps WHERE instance_id = ? AND sequence_no = ?');
    $statement->execute([$instanceId, $sequence]);
    $row = $statement->fetch(PDO::FETCH_ASSOC);
    if (!is_array($row)) {
        throw new RuntimeException('APPROVAL_TEST_STEP_NOT_FOUND');
    }

    return $row;
};

try {
    // Sequential progression, idempotent replay, and exact idempotency payload matching.
    $phase = 'sequential';
    $db = $newDatabase();
    $outcomes = new ApprovalWorkflowStateTestOutcomeHandler();
    $audit = new ApprovalWorkflowStateTestAudit();
    $service = $newService($db, $outcomes, $audit);
    $initialSnapshot = $snapshot([
        $stage(101, 1, 'sequential', [10]),
        $stage(102, 2, 'sequential', [20]),
    ]);
    $submission = $submit($service, $initialSnapshot, 1010, 'approval-submit-sequential');
    $assert($submission['status'] === 'pending' && $submission['current_sequence'] === 1, 'submission starts the first stage only');
    $firstStep = $stepBySequence($db, $submission['instance_id'], 1);
    $firstDecision = [
        'actor_id' => 10,
        'step_id' => (int) $firstStep['id'],
        'expected_lock_version' => (int) $firstStep['lock_version'],
        'decision' => 'approve',
        'idempotency_key' => 'approval-decision-sequential-1',
        'decided_at' => '2026-10-01 08:10:00.000000',
    ];
    $firstResult = $service->decide($firstDecision);
    $assert($firstResult['instance_status'] === 'pending' && $firstResult['current_sequence'] === 2, 'first approval activates only the next stage');
    $replay = $service->decide($firstDecision);
    $assert($replay['replayed'] === true && $replay['decision_id'] === $firstResult['decision_id'], 'same decision idempotency key replays without a second decision');
    $changedDecision = $firstDecision;
    $changedDecision['comment'] = 'changed payload';
    $assertError(
        static fn (): array => $service->decide($changedDecision),
        'APPROVAL_DECISION_IDEMPOTENCY_CONFLICT',
        'decision idempotency rejects a changed payload'
    );
    $changedSnapshot = $initialSnapshot;
    $changedSnapshot['workflow_name'] = 'Changed workflow evidence';
    $assertError(
        static fn (): array => $submit($service, $changedSnapshot, 1010, 'approval-submit-sequential'),
        'APPROVAL_SUBMISSION_IDEMPOTENCY_CONFLICT',
        'submission idempotency rejects altered frozen evidence'
    );
    $secondStep = $stepBySequence($db, $submission['instance_id'], 2);
    $secondResult = $service->decide([
        'actor_id' => 20,
        'step_id' => (int) $secondStep['id'],
        'expected_lock_version' => (int) $secondStep['lock_version'],
        'decision' => 'approve',
        'idempotency_key' => 'approval-decision-sequential-2',
        'decided_at' => '2026-10-01 08:15:00.000000',
    ]);
    $assert($secondResult['instance_status'] === 'approved', 'last sequential approval completes the instance');
    $assert(count($outcomes->outcomes) === 1 && $outcomes->outcomes[0]['outcome'] === 'approved', 'approved outcome is dispatched exactly once');

    // Quorum decisions serialize through the step lock: a stale simultaneous decision cannot overtake the first one.
    $phase = 'quorum';
    $db = $newDatabase();
    $outcomes = new ApprovalWorkflowStateTestOutcomeHandler();
    $audit = new ApprovalWorkflowStateTestAudit();
    $service = $newService($db, $outcomes, $audit);
    $quorumSubmission = $submit($service, $snapshot([
        $stage(201, 1, 'quorum', [30, 31, 32], ['quorum_count' => 2]),
    ]), 2020, 'approval-submit-quorum');
    $quorumStep = $stepBySequence($db, $quorumSubmission['instance_id'], 1);
    $quorumFirst = $service->decide([
        'actor_id' => 30,
        'step_id' => (int) $quorumStep['id'],
        'expected_lock_version' => 1,
        'decision' => 'approve',
        'idempotency_key' => 'approval-decision-quorum-30',
        'decided_at' => '2026-10-01 08:10:00.000000',
    ]);
    $assert($quorumFirst['step_status'] === 'active', 'first quorum vote keeps the stage active');
    $assertError(
        static fn (): array => $service->decide([
            'actor_id' => 31,
            'step_id' => (int) $quorumStep['id'],
            'expected_lock_version' => 1,
            'decision' => 'approve',
            'idempotency_key' => 'approval-decision-quorum-stale',
            'decided_at' => '2026-10-01 08:10:01.000000',
        ]),
        'STALE_APPROVAL_STEP',
        'simultaneous stale decision cannot bypass optimistic step locking'
    );
    $quorumStep = $stepBySequence($db, $quorumSubmission['instance_id'], 1);
    $quorumSecond = $service->decide([
        'actor_id' => 31,
        'step_id' => (int) $quorumStep['id'],
        'expected_lock_version' => (int) $quorumStep['lock_version'],
        'decision' => 'approve',
        'idempotency_key' => 'approval-decision-quorum-31',
        'decided_at' => '2026-10-01 08:11:00.000000',
    ]);
    $assert($quorumSecond['instance_status'] === 'approved', 'the second required quorum vote completes the instance');

    // A non-stopping tied all-vote follows the configured tie rule deterministically.
    $phase = 'tie';
    $db = $newDatabase();
    $outcomes = new ApprovalWorkflowStateTestOutcomeHandler();
    $audit = new ApprovalWorkflowStateTestAudit();
    $service = $newService($db, $outcomes, $audit);
    $tieSubmission = $submit($service, $snapshot([
        $stage(301, 1, 'all', [40, 41], ['rejection_rule' => 'continue', 'tie_rule' => 'approve']),
    ]), 3030, 'approval-submit-tie');
    $tieStep = $stepBySequence($db, $tieSubmission['instance_id'], 1);
    $service->decide([
        'actor_id' => 40,
        'step_id' => (int) $tieStep['id'],
        'expected_lock_version' => 1,
        'decision' => 'approve',
        'idempotency_key' => 'approval-decision-tie-40',
        'decided_at' => '2026-10-01 08:10:00.000000',
    ]);
    $tieStep = $stepBySequence($db, $tieSubmission['instance_id'], 1);
    $tieResult = $service->decide([
        'actor_id' => 41,
        'step_id' => (int) $tieStep['id'],
        'expected_lock_version' => (int) $tieStep['lock_version'],
        'decision' => 'reject',
        'comment' => 'Noted',
        'idempotency_key' => 'approval-decision-tie-41',
        'decided_at' => '2026-10-01 08:11:00.000000',
    ]);
    $assert($tieResult['instance_status'] === 'approved', 'configured approve tie rule resolves the completed tie deterministically');

    // Stop-on-reject closes the instance and marks future waiting work skipped.
    $phase = 'rejection';
    $db = $newDatabase();
    $outcomes = new ApprovalWorkflowStateTestOutcomeHandler();
    $audit = new ApprovalWorkflowStateTestAudit();
    $service = $newService($db, $outcomes, $audit);
    $rejectionSubmission = $submit($service, $snapshot([
        $stage(401, 1, 'all', [50, 51]),
        $stage(402, 2, 'sequential', [52]),
    ]), 4040, 'approval-submit-rejection');
    $rejectionStep = $stepBySequence($db, $rejectionSubmission['instance_id'], 1);
    $rejectionResult = $service->decide([
        'actor_id' => 50,
        'step_id' => (int) $rejectionStep['id'],
        'expected_lock_version' => 1,
        'decision' => 'reject',
        'comment' => 'Insufficient evidence',
        'idempotency_key' => 'approval-decision-rejection',
        'decided_at' => '2026-10-01 08:10:00.000000',
    ]);
    $laterStep = $stepBySequence($db, $rejectionSubmission['instance_id'], 2);
    $assert($rejectionResult['instance_status'] === 'rejected' && $laterStep['status'] === 'skipped', 'rejection terminates the instance and skips later stages');
    $assertError(
        static fn (): array => $service->decide([
            'actor_id' => 51,
            'step_id' => (int) $rejectionStep['id'],
            'expected_lock_version' => 2,
            'decision' => 'reject',
            'idempotency_key' => 'approval-decision-missing-reason',
            'decided_at' => '2026-10-01 08:11:00.000000',
        ]),
        'APPROVAL_REJECTION_REASON_REQUIRED',
        'rejection always requires an explanatory reason'
    );

    // A merged same actor is not asked or counted again in the following stage.
    $phase = 'merged';
    $db = $newDatabase();
    $outcomes = new ApprovalWorkflowStateTestOutcomeHandler();
    $audit = new ApprovalWorkflowStateTestAudit();
    $service = $newService($db, $outcomes, $audit);
    $mergedSubmission = $submit($service, $snapshot([
        $stage(501, 1, 'sequential', [60]),
        $stage(502, 2, 'sequential', [], ['same_actor_rule' => 'merge', 'merged_actor_ids' => [60]]),
    ]), 5050, 'approval-submit-merged-actor');
    $mergedFirst = $stepBySequence($db, $mergedSubmission['instance_id'], 1);
    $mergedResult = $service->decide([
        'actor_id' => 60,
        'step_id' => (int) $mergedFirst['id'],
        'expected_lock_version' => 1,
        'decision' => 'approve',
        'idempotency_key' => 'approval-decision-merged-actor',
        'decided_at' => '2026-10-01 08:10:00.000000',
    ]);
    $mergedLater = $stepBySequence($db, $mergedSubmission['instance_id'], 2);
    $assert($mergedResult['instance_status'] === 'approved' && $mergedLater['status'] === 'skipped', 'merged actor stage is skipped instead of deciding twice');

    // An assigned approver can reassign once; a timed-out stage can escalate only after its frozen SLA.
    $phase = 'reassign';
    $db = $newDatabase();
    $outcomes = new ApprovalWorkflowStateTestOutcomeHandler();
    $audit = new ApprovalWorkflowStateTestAudit();
    $service = $newService($db, $outcomes, $audit);
    $reassignSubmission = $submit($service, $snapshot([
        $stage(601, 1, 'sequential', [70]),
    ]), 6060, 'approval-submit-reassign');
    $reassignStep = $stepBySequence($db, $reassignSubmission['instance_id'], 1);
    $reassigned = $service->reassign([
        'actor_id' => 70,
        'step_id' => (int) $reassignStep['id'],
        'expected_lock_version' => 1,
        'from_user_id' => 70,
        'to_user_id' => 71,
        'reason' => 'Delegated during absence',
        'occurred_at' => '2026-10-01 08:05:00.000000',
    ]);
    $assert($reassigned['lock_version'] === 2, 'reassignment increments the step lock once');
    $reassignStep = $stepBySequence($db, $reassignSubmission['instance_id'], 1);
    $reassignResult = $service->decide([
        'actor_id' => 71,
        'step_id' => (int) $reassignStep['id'],
        'expected_lock_version' => (int) $reassignStep['lock_version'],
        'decision' => 'approve',
        'idempotency_key' => 'approval-decision-reassigned',
        'decided_at' => '2026-10-01 08:06:00.000000',
    ]);
    $assert($reassignResult['instance_status'] === 'approved', 'reassigned approver can complete the active stage');

    $phase = 'escalation';
    $db = $newDatabase();
    $outcomes = new ApprovalWorkflowStateTestOutcomeHandler();
    $audit = new ApprovalWorkflowStateTestAudit();
    $service = $newService($db, $outcomes, $audit);
    $escalationSubmission = $submit($service, $snapshot([
        $stage(701, 1, 'sequential', [80], ['sla_minutes' => 30, 'on_timeout' => 'escalate']),
    ]), 7070, 'approval-submit-escalation');
    $escalationStep = $stepBySequence($db, $escalationSubmission['instance_id'], 1);
    $assertError(
        static fn (): array => $service->escalate([
            'actor_id' => 900,
            'step_id' => (int) $escalationStep['id'],
            'expected_lock_version' => 1,
            'to_user_id' => 81,
            'reason' => 'Escalate after SLA',
            'occurred_at' => '2026-10-01 08:29:00.000000',
        ]),
        'APPROVAL_ESCALATION_NOT_DUE',
        'escalation cannot occur before the frozen SLA expires'
    );
    $escalated = $service->escalate([
        'actor_id' => 900,
        'step_id' => (int) $escalationStep['id'],
        'expected_lock_version' => 1,
        'to_user_id' => 81,
        'reason' => 'Escalate after SLA',
        'occurred_at' => '2026-10-01 08:31:00.000000',
    ]);
    $assert($escalated['lock_version'] === 2, 'timed-out escalation atomically replaces current assignees');

    // The request owner cannot decide their own request unless the frozen stage explicitly permits it.
    $phase = 'self';
    $db = $newDatabase();
    $outcomes = new ApprovalWorkflowStateTestOutcomeHandler();
    $audit = new ApprovalWorkflowStateTestAudit();
    $service = $newService($db, $outcomes, $audit);
    $selfSubmission = $submit($service, $snapshot([
        $stage(801, 1, 'sequential', [999]),
    ], 999), 8080, 'approval-submit-self');
    $selfStep = $stepBySequence($db, $selfSubmission['instance_id'], 1);
    $assertError(
        static fn (): array => $service->decide([
            'actor_id' => 999,
            'step_id' => (int) $selfStep['id'],
            'expected_lock_version' => 1,
            'decision' => 'approve',
            'idempotency_key' => 'approval-decision-self',
            'decided_at' => '2026-10-01 08:10:00.000000',
        ]),
        'SELF_APPROVAL_FORBIDDEN',
        'request owner cannot self-approve without an explicit frozen exception'
    );

    // Audit failure rolls the entire approval insertion back, including the frozen workflow evidence.
    $phase = 'audit';
    $db = $newDatabase();
    $outcomes = new ApprovalWorkflowStateTestOutcomeHandler();
    $audit = new ApprovalWorkflowStateTestAudit();
    $audit->failNext = true;
    $service = $newService($db, $outcomes, $audit);
    $assertError(
        static fn (): array => $submit($service, $snapshot([$stage(901, 1, 'sequential', [90])]), 9090, 'approval-submit-audit-failure'),
        'AUDIT_WRITE_FAILED',
        'mandatory audit write failure aborts approval submission'
    );
    $assert((int) $db->query('SELECT COUNT(*) FROM staff_approval_instances')->fetchColumn() === 0, 'audit failure leaves no partial approval instance');
} catch (Throwable $exception) {
    fwrite(STDERR, 'FAIL: approval state-machine exercise failed in ' . ($phase ?? 'unknown') . ': ' . $exception->getMessage() . PHP_EOL);
    ++$failures;
}

if ($failures > 0) {
    fwrite(STDERR, "{$failures} approval state-machine failure(s).\n");
    exit(1);
}

echo "Staff-HR approval state-machine test passed.\n";
