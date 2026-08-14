<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/vendor/autoload.php';

use EduCore\Modules\Operations\Audit\AuditEventWriter;
use EduCore\Modules\Staff\Application\Ertaq\ErtaqTicketService;
use EduCore\Modules\Staff\Contracts\ErtaqTicketAuthorization;
use EduCore\Modules\Staff\Contracts\ErtaqTicketPolicyResolver;
use EduCore\Modules\Staff\Contracts\ErtaqTicketRepository;
use EduCore\Modules\Staff\Contracts\ErtaqSlaScheduleQueue;

final class ErtaqTicketMemoryRepository implements ErtaqTicketRepository
{
    /** @var array<int,array<string,mixed>> */
    public array $tickets = [];
    /** @var array<int,array<string,mixed>> */
    public array $assignments = [];
    /** @var array<int,bool> */
    public array $users = [7 => true, 8 => true, 9 => true, 10 => true];
    private int $ticketSequence = 0;
    private int $assignmentSequence = 0;

    public function transactional(callable $work): mixed
    {
        $tickets = $this->tickets;
        $assignments = $this->assignments;
        $ticketSequence = $this->ticketSequence;
        $assignmentSequence = $this->assignmentSequence;
        try {
            return $work();
        } catch (Throwable $exception) {
            $this->tickets = $tickets;
            $this->assignments = $assignments;
            $this->ticketSequence = $ticketSequence;
            $this->assignmentSequence = $assignmentSequence;
            throw $exception;
        }
    }

    public function lockUser(int $userId): bool
    {
        return $this->users[$userId] ?? false;
    }

    public function ticketByCreateIdempotencyForUpdate(string $idempotencyKey): ?array
    {
        foreach ($this->tickets as $ticket) {
            if (($ticket['create_idempotency_key'] ?? null) === $idempotencyKey) {
                return $ticket;
            }
        }

        return null;
    }

    public function ticketForUpdate(int $ticketId): ?array
    {
        return $this->tickets[$ticketId] ?? null;
    }

    public function insertTicket(array $ticket): int
    {
        $id = ++$this->ticketSequence;
        $this->tickets[$id] = $ticket + [
            'id' => $id,
            'status' => 'new',
            'lock_version' => 1,
        ];

        return $id;
    }

    public function transitionTicket(
        int $ticketId,
        int $expectedLockVersion,
        string $fromStatus,
        string $toStatus,
        array $changes
    ): bool {
        $ticket = $this->tickets[$ticketId] ?? null;
        if ($ticket === null
            || ($ticket['status'] ?? null) !== $fromStatus
            || (int) ($ticket['lock_version'] ?? 0) !== $expectedLockVersion) {
            return false;
        }
        $this->tickets[$ticketId] = array_replace($ticket, $changes, [
            'status' => $toStatus,
            'lock_version' => $expectedLockVersion + 1,
        ]);

        return true;
    }

    public function assignmentByIdempotencyForUpdate(string $idempotencyKey): ?array
    {
        foreach ($this->assignments as $assignment) {
            if (($assignment['idempotency_key'] ?? null) === $idempotencyKey) {
                return $assignment;
            }
        }

        return null;
    }

    public function activeAssignmentForTicketForUpdate(int $ticketId): ?array
    {
        $matches = array_filter(
            $this->assignments,
            static fn (array $assignment): bool => (int) ($assignment['ticket_id'] ?? 0) === $ticketId
                && in_array((string) ($assignment['status'] ?? ''), ['active', 'accepted'], true)
        );
        if ($matches === []) {
            return null;
        }
        krsort($matches);

        return reset($matches) ?: null;
    }

    public function insertAssignment(array $assignment): int
    {
        $id = ++$this->assignmentSequence;
        $this->assignments[$id] = $assignment + [
            'id' => $id,
            'status' => 'active',
            'lock_version' => 1,
        ];

        return $id;
    }

    public function supersedeAssignment(
        int $assignmentId,
        int $expectedLockVersion,
        int $actorId,
        string $endedAt,
        string $reason
    ): bool {
        $assignment = $this->assignments[$assignmentId] ?? null;
        if ($assignment === null
            || !in_array((string) ($assignment['status'] ?? ''), ['active', 'accepted'], true)
            || (int) ($assignment['lock_version'] ?? 0) !== $expectedLockVersion) {
            return false;
        }
        $this->assignments[$assignmentId] = array_replace($assignment, [
            'status' => 'superseded',
            'ended_at' => $endedAt,
            'ended_by_user_id' => $actorId,
            'end_reason' => $reason,
            'lock_version' => $expectedLockVersion + 1,
        ]);

        return true;
    }
}

final class ErtaqTicketTestAuthorization implements ErtaqTicketAuthorization
{
    /** @var list<string> */
    public array $actions = [];
    /** @var list<array<string,int|null>> */
    public array $assignments = [];

    public function __construct(private bool $allow = true)
    {
    }

    public function assertCanAct(
        int $actorId,
        string $action,
        ?array $ticket,
        DateTimeImmutable $atInstant
    ): void {
        $this->actions[] = $action;
        if (!$this->allow) {
            throw new DomainException('ERTAQ_ACCESS_DENIED');
        }
    }

    public function assertCanAssign(
        int $actorId,
        array $ticket,
        ?int $assignedTeamId,
        ?int $assignedToUserId,
        DateTimeImmutable $atInstant
    ): void {
        $this->assignments[] = [
            'actor_id' => $actorId,
            'assigned_team_id' => $assignedTeamId,
            'assigned_to_user_id' => $assignedToUserId,
        ];
        if (!$this->allow) {
            throw new DomainException('ERTAQ_ASSIGNMENT_DENIED');
        }
    }
}

final class ErtaqTicketTestPolicyResolver implements ErtaqTicketPolicyResolver
{
    /** @var list<string> */
    public array $calls = [];

    public function resolveForCreate(
        int $requesterUserId,
        array $requested,
        DateTimeImmutable $atInstant
    ): array {
        $this->calls[] = 'create';

        return $this->resolved([
            'classification' => $requested['requested_classification'],
            'confidentiality_level' => $requested['requested_confidentiality_level'],
            'priority' => $requested['requested_priority'],
            'risk_level' => $requested['requested_risk_level'],
        ]);
    }

    public function resolveForClassification(
        int $actorId,
        array $ticket,
        array $requested,
        DateTimeImmutable $atInstant
    ): array {
        $this->calls[] = 'classification';

        return $this->resolved($requested);
    }

    /** @param array<string,mixed> $values @return array<string,mixed> */
    private function resolved(array $values): array
    {
        return $values + [
            'sla_policy_id' => 19,
            'sla_policy_snapshot' => [
                'version' => '2026.1',
                'first_response_minutes' => 60,
                'resolve_minutes' => 1440,
            ],
            'first_response_due_at' => '2030-01-01 09:00:00.000000',
            'sla_due_at' => '2030-01-02 09:00:00.000000',
        ];
    }
}

final class ErtaqTicketTestAudit implements AuditEventWriter
{
    /** @var list<array<string,mixed>> */
    public array $events = [];
    public bool $fail = false;

    public function recordEvent(
        string $action,
        ?string $entityType,
        mixed $recordId,
        ?string $name,
        array $details = [],
        array $context = []
    ): void {
        if ($this->fail) {
            throw new RuntimeException('ERTAQ_AUDIT_WRITE_FAILED');
        }
        $this->events[] = compact('action', 'entityType', 'recordId', 'name', 'details', 'context');
    }
}

final class ErtaqTicketTestSlaScheduleQueue implements ErtaqSlaScheduleQueue
{
    /** @var list<array<string,mixed>> */
    public array $scheduledTickets = [];
    public bool $fail = false;

    public function scheduleTicketSla(
        array $ticket,
        int $actorId,
        DateTimeImmutable $atInstant
    ): void {
        if ($this->fail) {
            throw new RuntimeException('ERTAQ_SLA_SCHEDULE_FAILED');
        }
        $this->scheduledTickets[] = [
            'ticket_id' => $ticket['id'],
            'actor_id' => $actorId,
            'sla_policy_id' => $ticket['sla_policy_id'],
        ];
    }
}

$failures = 0;
$assertions = 0;
$assert = static function (bool $condition, string $message) use (&$failures, &$assertions): void {
    ++$assertions;
    if (!$condition) {
        fwrite(STDERR, "FAIL: {$message}\n");
        ++$failures;
    }
};
$assertThrows = static function (callable $work, string $expectedMessage, string $message) use (&$failures, &$assertions): void {
    ++$assertions;
    try {
        $work();
        fwrite(STDERR, "FAIL: {$message} (no exception)\n");
        ++$failures;
    } catch (Throwable $exception) {
        if ($exception->getMessage() !== $expectedMessage) {
            fwrite(STDERR, "FAIL: {$message} (got {$exception->getMessage()})\n");
            ++$failures;
        }
    }
};

$newFixture = static function (): array {
    $repository = new ErtaqTicketMemoryRepository();
    $authorization = new ErtaqTicketTestAuthorization();
    $policy = new ErtaqTicketTestPolicyResolver();
    $audit = new ErtaqTicketTestAudit();
    $slaQueue = new ErtaqTicketTestSlaScheduleQueue();

    return [$repository, $authorization, $policy, $audit, $slaQueue, new ErtaqTicketService(
        $repository,
        $authorization,
        $policy,
        $audit,
        $slaQueue
    )];
};
$ticketCommand = static function (string $idempotencyKey = 'ertaq-ticket-1'): array {
    return [
        'actor_id' => 7,
        'requester_user_id' => 7,
        'type' => 'complaint',
        'classification' => 'employee_relations',
        'confidentiality_level' => 'restricted',
        'priority' => 'normal',
        'risk_level' => 'none',
        'subject' => 'موضوع شكوى تجريبي سري',
        'create_idempotency_key' => $idempotencyKey,
    ];
};

[$repository, $authorization, $policy, $audit, $slaQueue, $service] = $newFixture();
$assertThrows(
    static fn (): array => $service->createTicket(array_replace($ticketCommand(), ['requester_user_id' => 9])),
    'ERTAQ_REQUESTER_SELF_SERVICE_ONLY',
    'a worker cannot create an Ertaq ticket under another worker identity'
);
$assert($repository->tickets === [], 'self-service identity rejection leaves no ticket');

$created = $service->createTicket($ticketCommand());
$assert(
    $created['ticket_id'] === 1
        && $created['status'] === 'new'
        && $created['requester_user_id'] === 7
        && !array_key_exists('subject', $created),
    'ticket creation is self-scoped and receipt omits confidential subject text'
);
$assert(
    $repository->tickets[1]['sla_policy_id'] === 19
        && is_array($repository->tickets[1]['sla_policy_snapshot'])
        && $policy->calls === ['create']
        && $slaQueue->scheduledTickets === [['ticket_id' => 1, 'actor_id' => 7, 'sla_policy_id' => 19]],
    'effective SLA evidence is frozen by the policy resolver rather than trusted from the browser'
);
$assert(
    count($audit->events) === 1
        && !array_key_exists('subject', $audit->events[0]['details']),
    'ticket creation records an audit event without leaking subject content'
);
$replayedCreate = $service->createTicket($ticketCommand());
$assert(
    $replayedCreate['replayed'] === true
        && count($repository->tickets) === 1
        && count($audit->events) === 1,
    'exact ticket retry returns the original receipt without duplicate audit evidence'
);
$assertThrows(
    static fn (): array => $service->createTicket(array_replace($ticketCommand(), ['subject' => 'موضوع مختلف'])),
    'ERTAQ_TICKET_IDEMPOTENCY_CONFLICT',
    'same ticket key with different confidential input fails closed'
);

$classification = $service->classifyTicket([
    'actor_id' => 8,
    'ticket_id' => 1,
    'expected_lock_version' => 1,
    'classification' => 'sensitive_relations',
    'confidentiality_level' => 'highly_restricted',
    'priority' => 'high',
    'risk_level' => 'high',
]);
$assert(
    $classification['classification'] === 'sensitive_relations'
        && $classification['confidentiality_level'] === 'highly_restricted'
        && $classification['lock_version'] === 2,
    'classification changes through the policy boundary and optimistic lock'
);
$classificationReplay = $service->classifyTicket([
    'actor_id' => 8,
    'ticket_id' => 1,
    'expected_lock_version' => 1,
    'classification' => 'sensitive_relations',
    'confidentiality_level' => 'highly_restricted',
    'priority' => 'high',
    'risk_level' => 'high',
]);
$assert(
    $classificationReplay['replayed'] === true
        && $repository->tickets[1]['lock_version'] === 2,
    'one-version stale classification retry is safely recognized as the same result'
);

$triaged = $service->transitionTicket([
    'actor_id' => 8,
    'ticket_id' => 1,
    'expected_lock_version' => 2,
    'to_status' => 'triaged',
]);
$assert($triaged['status'] === 'triaged' && $triaged['lock_version'] === 3, 'new ticket enters the explicit triage state');
$triageReplay = $service->transitionTicket([
    'actor_id' => 8,
    'ticket_id' => 1,
    'expected_lock_version' => 2,
    'to_status' => 'triaged',
]);
$assert($triageReplay['replayed'] === true, 'state retry returns replay evidence only for the exact next version');

$firstAssignment = $service->assignTicket([
    'actor_id' => 8,
    'ticket_id' => 1,
    'expected_lock_version' => 3,
    'assigned_to_user_id' => 9,
    'assignment_reason' => 'توزيع الاختصاص على معالج مستقل.',
    'idempotency_key' => 'ertaq-assignment-1',
]);
$assert(
    $firstAssignment['ticket']['status'] === 'assigned'
        && $firstAssignment['assignment']['assignment_id'] === 1
        && $firstAssignment['assignment']['assigned_to_user_id'] === 9,
    'assignment creates one ownership record and advances ticket state atomically'
);
$assignmentReplay = $service->assignTicket([
    'actor_id' => 8,
    'ticket_id' => 1,
    'expected_lock_version' => 3,
    'assigned_to_user_id' => 9,
    'assignment_reason' => 'توزيع الاختصاص على معالج مستقل.',
    'idempotency_key' => 'ertaq-assignment-1',
]);
$assert(
    $assignmentReplay['assignment']['replayed'] === true
        && count($repository->assignments) === 1,
    'assignment idempotency prevents a duplicate assignee record'
);
$secondAssignment = $service->assignTicket([
    'actor_id' => 8,
    'ticket_id' => 1,
    'expected_lock_version' => 4,
    'assigned_team_id' => 11,
    'assignment_reason' => 'إعادة توجيه للاختصاص الجماعي.',
    'idempotency_key' => 'ertaq-assignment-2',
]);
$assert(
    $secondAssignment['assignment']['assignment_id'] === 2
        && $repository->assignments[1]['status'] === 'superseded'
        && $repository->assignments[2]['supersedes_assignment_id'] === 1
        && $repository->tickets[1]['lock_version'] === 5,
    'reassignment appends a successor and retains the former assignment'
);

$inProgress = $service->transitionTicket([
    'actor_id' => 9,
    'ticket_id' => 1,
    'expected_lock_version' => 5,
    'to_status' => 'in_progress',
]);
$awaiting = $service->transitionTicket([
    'actor_id' => 9,
    'ticket_id' => 1,
    'expected_lock_version' => 6,
    'to_status' => 'awaiting_requester',
]);
$resumed = $service->transitionTicket([
    'actor_id' => 9,
    'ticket_id' => 1,
    'expected_lock_version' => 7,
    'to_status' => 'in_progress',
]);
$resolved = $service->transitionTicket([
    'actor_id' => 9,
    'ticket_id' => 1,
    'expected_lock_version' => 8,
    'to_status' => 'resolved',
    'resolution_summary' => 'تمت المعالجة الداخلية وفق المسار المعتمد.',
]);
$assert(
    $inProgress['status'] === 'in_progress'
        && $awaiting['status'] === 'awaiting_requester'
        && $resumed['status'] === 'in_progress'
        && $resolved['status'] === 'resolved'
        && $repository->tickets[1]['resolution_summary'] !== null,
    'ordinary lifecycle allows only documented work, requester-wait, and resolved states'
);
$assertThrows(
    static fn (): array => $service->transitionTicket([
        'actor_id' => 9,
        'ticket_id' => 1,
        'expected_lock_version' => 9,
        'to_status' => 'urgent_protected',
    ]),
    'ERTAQ_TICKET_TRANSITION_FORBIDDEN',
    'generic ticket transition cannot enter the dedicated urgent-protection route'
);
$closed = $service->transitionTicket([
    'actor_id' => 8,
    'ticket_id' => 1,
    'expected_lock_version' => 9,
    'to_status' => 'closed',
    'closure_reason' => 'اكتملت المعالجة وتم توثيق الإغلاق.',
]);
$reopened = $service->transitionTicket([
    'actor_id' => 8,
    'ticket_id' => 1,
    'expected_lock_version' => 10,
    'to_status' => 'reopened',
    'reopen_reason' => 'وردت معلومة لاحقة تستلزم متابعة جديدة.',
]);
$assert(
    $closed['status'] === 'closed'
        && $reopened['status'] === 'reopened'
        && $repository->tickets[1]['closure_reason'] !== null
        && $repository->tickets[1]['reopen_reason'] !== null,
    'close and reopen require preserved reason evidence rather than a hard deletion'
);
$assert(
    in_array('create_ticket', $authorization->actions, true)
        && in_array('classify_ticket', $authorization->actions, true)
        && $authorization->assignments[0]['assigned_to_user_id'] === 9,
    'live authorization receives ticket actions and assignment targets before persistence'
);

[$deniedRepository, , , , , $deniedService] = $newFixture();
$deniedAuthorization = new ErtaqTicketTestAuthorization(false);
$deniedService = new ErtaqTicketService(
    $deniedRepository,
    $deniedAuthorization,
    new ErtaqTicketTestPolicyResolver(),
    new ErtaqTicketTestAudit(),
    new ErtaqTicketTestSlaScheduleQueue()
);
$assertThrows(
    static fn (): array => $deniedService->createTicket($ticketCommand('ertaq-ticket-denied')),
    'ERTAQ_ACCESS_DENIED',
    'authorization failure occurs before a confidential ticket is persisted'
);
$assert($deniedRepository->tickets === [], 'denied request leaves no partial ticket');

[$rollbackRepository, , , $rollbackAudit, , $rollbackService] = $newFixture();
$rollbackAudit->fail = true;
$assertThrows(
    static fn (): array => $rollbackService->createTicket($ticketCommand('ertaq-ticket-audit-fail')),
    'ERTAQ_AUDIT_WRITE_FAILED',
    'mandatory audit failure aborts ticket creation'
);
$assert($rollbackRepository->tickets === [], 'audit failure rolls back the ticket write');

[$slaRollbackRepository, , , , $slaRollbackQueue, $slaRollbackService] = $newFixture();
$slaRollbackQueue->fail = true;
$assertThrows(
    static fn (): array => $slaRollbackService->createTicket($ticketCommand('ertaq-ticket-sla-fail')),
    'ERTAQ_SLA_SCHEDULE_FAILED',
    'mandatory local SLA scheduling failure aborts ticket creation'
);
$assert(
    $slaRollbackRepository->tickets === [],
    'SLA schedule failure cannot leave a ticket without its frozen deadline evidence'
);

if ($failures > 0) {
    fwrite(STDERR, "{$failures} Ertaq ticket service test failure(s).\n");
    exit(1);
}

echo 'staff_hr_ertaq_ticket_service_test: PASS (' . $assertions . ' assertions)' . PHP_EOL;
