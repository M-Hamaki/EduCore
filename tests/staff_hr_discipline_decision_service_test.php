<?php

declare(strict_types=1);

$root = dirname(__DIR__);
require_once $root . '/vendor/autoload.php';
require_once $root . '/src/Modules/Staff/bootstrap.php';

use EduCore\Modules\Operations\Audit\AuditEventWriter;
use EduCore\Modules\Staff\Application\Approval\StaffApprovalOutcomeRouter;
use EduCore\Modules\Staff\Application\Discipline\DisciplineDecisionApprovalOutcomeHandler;
use EduCore\Modules\Staff\Application\Discipline\DisciplineDecisionService;
use EduCore\Modules\Staff\Contracts\ApprovalWorkflowOutcomeHandler;
use EduCore\Modules\Staff\Contracts\ApprovalWorkflowResolutionGateway;
use EduCore\Modules\Staff\Contracts\ApprovalWorkflowSubmissionGateway;
use EduCore\Modules\Staff\Contracts\DisciplineCaseAuthorization;
use EduCore\Modules\Staff\Contracts\DisciplineDecisionRepository;
use EduCore\Modules\Staff\Contracts\DisciplineFinanceEffectQueue;
use EduCore\Modules\Staff\Contracts\StaffNotificationPort;

final class DisciplineDecisionMemoryRepository implements DisciplineDecisionRepository
{
    /** @var array<int,array<string,mixed>> */
    public array $cases = [
        1 => [
            'id' => 1,
            'case_no' => 'DISC-CASE-001',
            'status' => 'under_investigation',
            'lock_version' => 4,
            'subject_staff_user_id' => 7,
            'opened_by_user_id' => 2,
            'incident_reported_by_user_id' => 1,
        ],
    ];
    /** @var array<int,array<string,mixed>> */
    public array $investigations = [
        1 => [
            'id' => 1,
            'case_id' => 1,
            'status' => 'completed',
            'investigator_user_id' => 3,
            'lock_version' => 2,
        ],
    ];
    /** @var array<int,array<string,mixed>> */
    public array $decisions = [];
    /** @var array<int,bool> */
    public array $users = [1 => true, 2 => true, 3 => true, 4 => true, 5 => true, 7 => true, 8 => true, 9 => true];
    private int $nextDecisionId = 1;

    public function transactional(callable $work): mixed
    {
        $cases = $this->cases;
        $investigations = $this->investigations;
        $decisions = $this->decisions;
        $nextDecisionId = $this->nextDecisionId;
        try {
            return $work();
        } catch (Throwable $exception) {
            $this->cases = $cases;
            $this->investigations = $investigations;
            $this->decisions = $decisions;
            $this->nextDecisionId = $nextDecisionId;
            throw $exception;
        }
    }

    public function lockUser(int $userId): bool
    {
        return isset($this->users[$userId]);
    }

    public function caseForUpdate(int $caseId): ?array
    {
        return $this->cases[$caseId] ?? null;
    }

    public function investigationForUpdate(int $investigationId): ?array
    {
        return $this->investigations[$investigationId] ?? null;
    }

    public function decisionByIdempotencyForUpdate(string $idempotencyKey): ?array
    {
        foreach ($this->decisions as $decision) {
            if (($decision['idempotency_key'] ?? null) === $idempotencyKey) {
                return $decision;
            }
        }

        return null;
    }

    public function decisionForUpdate(int $decisionId): ?array
    {
        return $this->decisions[$decisionId] ?? null;
    }

    public function nextDecisionSequenceForUpdate(int $caseId): int
    {
        $maximum = 0;
        foreach ($this->decisions as $decision) {
            if ((int) ($decision['case_id'] ?? 0) === $caseId) {
                $maximum = max($maximum, (int) ($decision['decision_sequence'] ?? 0));
            }
        }

        return $maximum + 1;
    }

    public function insertDecision(array $decision): int
    {
        $id = $this->nextDecisionId++;
        $this->decisions[$id] = array_replace($decision, [
            'id' => $id,
            'status' => 'proposed',
            'notification_status' => 'pending',
            'workflow_instance_id' => null,
            'lock_version' => 1,
        ]);

        return $id;
    }

    public function attachWorkflowInstance(int $decisionId, int $expectedLockVersion, int $workflowInstanceId): bool
    {
        $decision = $this->decisions[$decisionId] ?? null;
        if ($decision === null
            || ($decision['status'] ?? null) !== 'proposed'
            || $decision['workflow_instance_id'] !== null
            || (int) ($decision['lock_version'] ?? 0) !== $expectedLockVersion) {
            return false;
        }
        $decision['workflow_instance_id'] = $workflowInstanceId;
        $decision['lock_version'] = $expectedLockVersion + 1;
        $this->decisions[$decisionId] = $decision;

        return true;
    }

    public function issueDecision(
        int $decisionId,
        int $expectedLockVersion,
        int $decidedByUserId,
        string $decidedAt,
        string $issuedAt
    ): bool {
        $decision = $this->decisions[$decisionId] ?? null;
        if ($decision === null
            || ($decision['status'] ?? null) !== 'proposed'
            || $decision['workflow_instance_id'] === null
            || (int) ($decision['lock_version'] ?? 0) !== $expectedLockVersion) {
            return false;
        }
        $decision = array_replace($decision, [
            'status' => 'issued',
            'decided_by_user_id' => $decidedByUserId,
            'decided_at' => $decidedAt,
            'issued_at' => $issuedAt,
            'notification_status' => 'pending',
            'lock_version' => $expectedLockVersion + 1,
        ]);
        $this->decisions[$decisionId] = $decision;

        return true;
    }

    public function cancelProposedDecision(int $decisionId, int $expectedLockVersion): bool
    {
        $decision = $this->decisions[$decisionId] ?? null;
        if ($decision === null
            || ($decision['status'] ?? null) !== 'proposed'
            || $decision['workflow_instance_id'] === null
            || (int) ($decision['lock_version'] ?? 0) !== $expectedLockVersion) {
            return false;
        }
        $decision['status'] = 'cancelled';
        $decision['notification_status'] = 'not_required';
        $decision['lock_version'] = $expectedLockVersion + 1;
        $this->decisions[$decisionId] = $decision;

        return true;
    }

    public function markNotification(
        int $decisionId,
        int $expectedLockVersion,
        string $status,
        ?string $reference,
        ?string $notifiedAt
    ): bool {
        $decision = $this->decisions[$decisionId] ?? null;
        if ($decision === null
            || ($decision['status'] ?? null) !== 'issued'
            || (int) ($decision['lock_version'] ?? 0) !== $expectedLockVersion) {
            return false;
        }
        $decision['notification_status'] = $status;
        $decision['notification_reference'] = $reference;
        $decision['notified_at'] = $notifiedAt;
        $decision['lock_version'] = $expectedLockVersion + 1;
        $this->decisions[$decisionId] = $decision;

        return true;
    }

    public function recordReceipt(int $decisionId, int $expectedLockVersion, string $receiptAt): bool
    {
        $decision = $this->decisions[$decisionId] ?? null;
        if ($decision === null
            || ($decision['status'] ?? null) !== 'issued'
            || ($decision['notification_status'] ?? null) !== 'sent'
            || (int) ($decision['lock_version'] ?? 0) !== $expectedLockVersion) {
            return false;
        }
        $decision['notification_status'] = 'received';
        $decision['receipt_at'] = $receiptAt;
        $decision['lock_version'] = $expectedLockVersion + 1;
        $this->decisions[$decisionId] = $decision;

        return true;
    }

    public function transitionCase(
        int $caseId,
        int $expectedLockVersion,
        string $fromStatus,
        string $toStatus
    ): bool {
        $case = $this->cases[$caseId] ?? null;
        if ($case === null
            || ($case['status'] ?? null) !== $fromStatus
            || (int) ($case['lock_version'] ?? 0) !== $expectedLockVersion) {
            return false;
        }
        $case['status'] = $toStatus;
        $case['lock_version'] = $expectedLockVersion + 1;
        $this->cases[$caseId] = $case;

        return true;
    }
}

final class DisciplineDecisionTestAuthorization implements DisciplineCaseAuthorization
{
    /** @var list<string> */
    public array $actions = [];
    public ?int $deniedActor = null;

    public function assertCanAct(
        int $actorId,
        string $action,
        ?array $case,
        DateTimeImmutable $atInstant
    ): void {
        $this->actions[] = $action;
        if ($this->deniedActor === $actorId) {
            throw new DomainException('DISCIPLINE_DECISION_ACCESS_DENIED');
        }
    }
}

final class DisciplineDecisionWorkflowResolver implements ApprovalWorkflowResolutionGateway
{
    /** @var list<array<string,mixed>> */
    public array $calls = [];

    public function resolveForResource(
        string $resourceType,
        int $staffUserId,
        array $context,
        DateTimeImmutable $effectiveAt,
        DateTimeImmutable $resolvedAt
    ): array {
        $this->calls[] = compact('resourceType', 'staffUserId', 'context', 'effectiveAt', 'resolvedAt');

        return [
            'workflow_version_id' => 41,
            'snapshot' => [
                'schema_version' => 1,
                'resource_type' => $resourceType,
                'context' => [
                    'staff_user_id' => $staffUserId,
                    'case_id' => $context['case_id'] ?? null,
                ],
                'stages' => [],
            ],
        ];
    }
}

final class DisciplineDecisionWorkflowSubmission implements ApprovalWorkflowSubmissionGateway
{
    /** @var list<array<string,mixed>> */
    public array $commands = [];
    private int $nextInstanceId = 100;

    public function submit(array $command): array
    {
        $this->commands[] = $command;

        return ['instance_id' => ++$this->nextInstanceId, 'status' => 'pending'];
    }
}

final class DisciplineDecisionTestNotification implements StaffNotificationPort
{
    public bool $fail = false;
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
        if ($this->fail) {
            throw new DomainException('SIMULATED_NOTIFICATION_FAILURE');
        }

        return [
            'accepted' => true,
            'status' => 'queued',
            'receipt_id' => 'notification-receipt-' . count($this->calls),
            'inbox_count' => count($recipientIds),
            'outbox_count' => count($recipientIds),
        ];
    }
}

final class DisciplineDecisionTestFinanceQueue implements DisciplineFinanceEffectQueue
{
    /** @var list<array{decision_id:int,actor_id:int}> */
    public array $issuedCalls = [];

    public function queueForIssuedDecision(
        int $decisionId,
        int $actorId,
        ?DateTimeImmutable $occurredAt = null
    ): array {
        $this->issuedCalls[] = ['decision_id' => $decisionId, 'actor_id' => $actorId];

        return [
            'status' => 'queued',
            'decision_id' => $decisionId,
            'effect_id' => 77,
            'replayed' => false,
        ];
    }

    public function queueReversalForResolvedAppeal(
        int $appealId,
        int $actorId,
        ?DateTimeImmutable $occurredAt = null
    ): array {
        return [
            'status' => 'not_applicable',
            'appeal_id' => $appealId,
            'effect_id' => null,
            'reversed_effect_id' => null,
            'replayed' => false,
        ];
    }
}

final class DisciplineDecisionTestAudit implements AuditEventWriter
{
    public bool $fail = false;
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
        if ($this->fail) {
            throw new DomainException('DISCIPLINE_DECISION_AUDIT_FAILURE');
        }
        $this->events[] = compact('action', 'entityType', 'recordId', 'name', 'details', 'context');
    }
}

final class DisciplineDecisionNoopOutcomeHandler implements ApprovalWorkflowOutcomeHandler
{
    public function apply(array $instance, string $outcome, int $actorId, DateTimeImmutable $occurredAt): void
    {
    }
}

$assertions = 0;
$assert = static function (bool $condition, string $message) use (&$assertions): void {
    ++$assertions;
    if (!$condition) {
        throw new RuntimeException($message);
    }
};
$assertThrows = static function (callable $work, string $expectedMessage, string $message) use (&$assertions): void {
    ++$assertions;
    try {
        $work();
    } catch (Throwable $exception) {
        if ($exception->getMessage() === $expectedMessage) {
            return;
        }
        throw new RuntimeException($message . ': expected ' . $expectedMessage . ', got ' . $exception->getMessage());
    }
    throw new RuntimeException($message . ': no exception');
};

/** @return array{0:DisciplineDecisionMemoryRepository,1:DisciplineDecisionTestAuthorization,2:DisciplineDecisionWorkflowResolver,3:DisciplineDecisionWorkflowSubmission,4:DisciplineDecisionTestNotification,5:DisciplineDecisionTestAudit,6:DisciplineDecisionService,7:DisciplineDecisionApprovalOutcomeHandler} */
$newFixture = static function (?DisciplineFinanceEffectQueue $financeEffects = null): array {
    $repository = new DisciplineDecisionMemoryRepository();
    $authorization = new DisciplineDecisionTestAuthorization();
    $resolver = new DisciplineDecisionWorkflowResolver();
    $submission = new DisciplineDecisionWorkflowSubmission();
    $notifications = new DisciplineDecisionTestNotification();
    $audit = new DisciplineDecisionTestAudit();
    $service = new DisciplineDecisionService($repository, $authorization, $resolver, $submission, $audit);
    $outcomes = new DisciplineDecisionApprovalOutcomeHandler(
        $repository,
        $notifications,
        $audit,
        null,
        'admin/disciplinary.php',
        $financeEffects
    );

    return [$repository, $authorization, $resolver, $submission, $notifications, $audit, $service, $outcomes];
};
$proposalCommand = static function (string $idempotencyKey = 'discipline-decision-1'): array {
    return [
        'actor_id' => 4,
        'case_id' => 1,
        'investigation_id' => 1,
        'expected_case_lock_version' => 4,
        'sanction_code' => 'written_warning',
        'decision_reason' => 'سبب القرار الإداري المسجل في الملف المحمي.',
        'policy_snapshot' => ['version' => 1, 'rule' => 'documented-disciplinary-policy'],
        'effective_from' => '2026-08-09 08:00:00',
        'financial_effect_requested' => false,
        'idempotency_key' => $idempotencyKey,
    ];
};
$instanceFor = static function (array $proposal, DisciplineDecisionWorkflowSubmission $submission): array {
    return [
        'id' => $proposal['workflow_instance_id'],
        'resource_type' => 'discipline_case',
        'resource_id' => $proposal['case_id'],
        'snapshot' => $submission->commands[0]['snapshot'],
    ];
};

[$repository, $authorization, $resolver, $submission, $notifications, $audit, $service, $outcomes] = $newFixture();
$assertThrows(
    static fn (): array => $service->proposeDecision(array_replace($proposalCommand(), ['actor_id' => 3])),
    'DISCIPLINE_DECISION_PREPARER_CONFLICT',
    'the investigator cannot prepare the final decision'
);
$assert($repository->decisions === [] && $repository->cases[1]['status'] === 'under_investigation', 'preparer conflict rolls back the proposal');
$assertThrows(
    static fn (): array => $service->proposeDecision(array_replace($proposalCommand(), ['actor_id' => 2])),
    'DISCIPLINE_DECISION_PREPARER_CONFLICT',
    'the case opener cannot prepare the decision'
);

$proposal = $service->proposeDecision($proposalCommand());
$assert(
    $proposal['decision_id'] === 1
        && $proposal['status'] === 'proposed'
        && $proposal['workflow_instance_id'] === 101
        && $repository->cases[1]['status'] === 'pending_decision'
        && $repository->decisions[1]['lock_version'] === 2,
    'proposal freezes an approval workflow and advances the case exactly once'
);
$assert(
    $service->proposeDecision($proposalCommand())['replayed'] === true
        && count($repository->decisions) === 1
        && count($submission->commands) === 1,
    'proposal idempotency does not duplicate the decision or approval instance'
);
$assert(
    $submission->commands[0]['resource_type'] === 'discipline_case'
        && $submission->commands[0]['resource_id'] === 1
        && $submission->commands[0]['snapshot']['context']['discipline_decision_id'] === 1,
    'workflow snapshot links to the immutable decision without copying confidential reason text'
);

$instance = $instanceFor($proposal, $submission);
$assertThrows(
    static fn (): null => $outcomes->apply($instance, 'approved', 4, new DateTimeImmutable('2026-08-09 09:00:00')),
    'DISCIPLINE_DECISION_FINALIZER_CONFLICT',
    'the decision preparer cannot approve the same decision'
);
$assert($repository->decisions[1]['status'] === 'proposed', 'failed finalizer conflict leaves the proposed decision unchanged');
$router = new StaffApprovalOutcomeRouter(
    new DisciplineDecisionNoopOutcomeHandler(),
    new DisciplineDecisionNoopOutcomeHandler(),
    $outcomes
);
$router->apply($instance, 'approved', 5, new DateTimeImmutable('2026-08-09 09:00:00'));
$assert(
    $repository->decisions[1]['status'] === 'issued'
        && $repository->decisions[1]['decided_by_user_id'] === 5
        && $repository->decisions[1]['notification_status'] === 'sent'
        && $repository->cases[1]['status'] === 'decided'
        && $repository->decisions[1]['lock_version'] === 4,
    'an independent workflow approver issues the decision and leaves Finance untouched'
);
$notificationCall = $notifications->calls[0];
$assert(
    $notificationCall['recipientIds'] === [7]
        && $notificationCall['neutralText'] === 'لديك قرار إداري جديد متاح للمراجعة.'
        && !array_key_exists('decision_reason', $notificationCall['metadata'])
        && !array_key_exists('sanction_code', $notificationCall['metadata']),
    'subject notification is neutral and contains no confidential discipline contents'
);

$financeQueue = new DisciplineDecisionTestFinanceQueue();
[$financeRepository, , , $financeSubmission, , , $financeService, $financeOutcomes] = $newFixture($financeQueue);
$financeProposal = $financeService->proposeDecision(array_replace(
    $proposalCommand('discipline-decision-finance-intent'),
    ['financial_effect_requested' => true]
));
$financeOutcomes->apply(
    $instanceFor($financeProposal, $financeSubmission),
    'approved',
    5,
    new DateTimeImmutable('2026-08-09 09:00:00')
);
$assert(
    $financeRepository->decisions[1]['status'] === 'issued'
        && $financeQueue->issuedCalls === [['decision_id' => 1, 'actor_id' => 5]],
    'final approval persists only the local discipline Finance intent through its queue boundary'
);
$assertThrows(
    static fn (): array => $service->acknowledgeReceipt([
        'actor_id' => 5,
        'decision_id' => 1,
        'expected_lock_version' => 4,
    ]),
    'DISCIPLINE_DECISION_RECEIPT_SUBJECT_ONLY',
    'a manager or approver cannot acknowledge the worker receipt'
);
$receipt = $service->acknowledgeReceipt([
    'actor_id' => 7,
    'decision_id' => 1,
    'expected_lock_version' => 4,
]);
$assert(
    $receipt['notification_status'] === 'received'
        && $receipt['lock_version'] === 5
        && $repository->decisions[1]['receipt_at'] !== null,
    'only the subject records a durable receipt'
);
$assert(
    $service->acknowledgeReceipt([
        'actor_id' => 7,
        'decision_id' => 1,
        'expected_lock_version' => 4,
    ])['replayed'] === true,
    'receipt replay is safe after a stale browser retry'
);

[$failureRepository, , , $failureSubmission, $failureNotifications, , $failureService, $failureOutcomes] = $newFixture();
$failureProposal = $failureService->proposeDecision($proposalCommand('discipline-decision-notification-failure'));
$failureNotifications->fail = true;
$failureOutcomes->apply(
    $instanceFor($failureProposal, $failureSubmission),
    'approved',
    5,
    new DateTimeImmutable('2026-08-09 09:00:00')
);
$assert(
    $failureRepository->decisions[1]['status'] === 'issued'
        && $failureRepository->decisions[1]['notification_status'] === 'delivery_failed'
        && $failureRepository->cases[1]['status'] === 'decided',
    'notification enqueue failure is audited as delivery failure without silently undoing the issued decision'
);

[$rejectedRepository, , , $rejectedSubmission, , , $rejectedService, $rejectedOutcomes] = $newFixture();
$rejectedProposal = $rejectedService->proposeDecision($proposalCommand('discipline-decision-rejected'));
$rejectedOutcomes->apply(
    $instanceFor($rejectedProposal, $rejectedSubmission),
    'rejected',
    5,
    new DateTimeImmutable('2026-08-09 09:00:00')
);
$assert(
    $rejectedRepository->decisions[1]['status'] === 'cancelled'
        && $rejectedRepository->decisions[1]['notification_status'] === 'not_required'
        && $rejectedRepository->cases[1]['status'] === 'pending_decision',
    'a rejected proposal stays traceable while the case remains available for a corrected successor decision'
);

[$auditRepository, , , $auditSubmission, , $auditWriter, $auditService, $auditOutcomes] = $newFixture();
$auditProposal = $auditService->proposeDecision($proposalCommand('discipline-decision-audit-rollback'));
$auditWriter->fail = true;
$assertThrows(
    static fn (): null => $auditOutcomes->apply(
        $instanceFor($auditProposal, $auditSubmission),
        'approved',
        5,
        new DateTimeImmutable('2026-08-09 09:00:00')
    ),
    'DISCIPLINE_DECISION_AUDIT_FAILURE',
    'mandatory audit failure aborts the final approval transaction'
);
$assert(
    $auditRepository->decisions[1]['status'] === 'proposed'
        && $auditRepository->cases[1]['status'] === 'pending_decision',
    'audit failure rolls the decision issue and case transition back together'
);
$assert(
    in_array('propose_decision', $authorization->actions, true)
        && in_array('acknowledge_decision_receipt', $authorization->actions, true),
    'proposal and receipt writes both cross the sensitive authorization boundary'
);

echo 'staff_hr_discipline_decision_service_test: PASS (' . $assertions . ' assertions)' . PHP_EOL;
