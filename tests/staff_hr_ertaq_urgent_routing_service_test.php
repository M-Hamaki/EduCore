<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/vendor/autoload.php';

use EduCore\Modules\Operations\Audit\AuditEventWriter;
use EduCore\Modules\Staff\Application\Ertaq\ErtaqUrgentRoutingService;
use EduCore\Modules\Staff\Contracts\ErtaqUrgentRoutingAuthorization;
use EduCore\Modules\Staff\Contracts\ErtaqUrgentRoutingRepository;

final class ErtaqUrgentMemoryRepository implements ErtaqUrgentRoutingRepository
{
    /** @var array<int,array<string,mixed>> */
    public array $tickets = [
        1 => [
            'id' => 1,
            'ticket_no' => 'ERT-URGENT-001',
            'requester_user_id' => 7,
            'status' => 'in_progress',
            'lock_version' => 1,
        ],
    ];
    /** @var array<int,array<string,mixed>> */
    public array $urgentEvents = [];
    /** @var list<int> */
    public array $protectedIds = [9, 10];
    private int $urgentSequence = 0;

    public function transactional(callable $work): mixed
    {
        $tickets = $this->tickets;
        $events = $this->urgentEvents;
        $sequence = $this->urgentSequence;
        try {
            return $work();
        } catch (Throwable $exception) {
            $this->tickets = $tickets;
            $this->urgentEvents = $events;
            $this->urgentSequence = $sequence;
            throw $exception;
        }
    }

    public function ticketForUpdate(int $ticketId): ?array
    {
        return $this->tickets[$ticketId] ?? null;
    }

    public function protectedPartyUserIdsForTicketForUpdate(int $ticketId): array
    {
        return $this->protectedIds;
    }

    public function urgentByIdempotencyForUpdate(string $idempotencyKey): ?array
    {
        foreach ($this->urgentEvents as $event) {
            if (($event['idempotency_key'] ?? null) === $idempotencyKey) {
                return $event;
            }
        }

        return null;
    }

    public function urgentForTicketForUpdate(int $ticketId): ?array
    {
        foreach ($this->urgentEvents as $event) {
            if ((int) ($event['ticket_id'] ?? 0) === $ticketId) {
                return $event;
            }
        }

        return null;
    }

    public function urgentEventForUpdate(int $urgentEventId): ?array
    {
        return $this->urgentEvents[$urgentEventId] ?? null;
    }

    public function insertUrgentEvent(array $event): int
    {
        $id = ++$this->urgentSequence;
        $this->urgentEvents[$id] = $event + [
            'id' => $id,
            'status' => 'routed',
            'lock_version' => 1,
        ];

        return $id;
    }

    public function transitionTicketToUrgent(
        int $ticketId,
        int $expectedLockVersion,
        string $fromStatus
    ): bool {
        $ticket = $this->tickets[$ticketId] ?? null;
        if ($ticket === null
            || ($ticket['status'] ?? null) !== $fromStatus
            || (int) ($ticket['lock_version'] ?? 0) !== $expectedLockVersion) {
            return false;
        }
        $this->tickets[$ticketId] = array_replace($ticket, [
            'status' => 'urgent_protected',
            'lock_version' => $expectedLockVersion + 1,
        ]);

        return true;
    }

    public function acknowledgeUrgentEvent(
        int $urgentEventId,
        int $expectedLockVersion,
        int $actorId,
        string $acknowledgedAt
    ): bool {
        $event = $this->urgentEvents[$urgentEventId] ?? null;
        if ($event === null
            || ($event['status'] ?? null) !== 'routed'
            || (int) ($event['lock_version'] ?? 0) !== $expectedLockVersion) {
            return false;
        }
        $this->urgentEvents[$urgentEventId] = array_replace($event, [
            'status' => 'acknowledged',
            'acknowledged_at' => $acknowledgedAt,
            'acknowledged_by_user_id' => $actorId,
            'lock_version' => $expectedLockVersion + 1,
        ]);

        return true;
    }
}

final class ErtaqUrgentTestAuthorization implements ErtaqUrgentRoutingAuthorization
{
    /** @var list<string> */
    public array $actions = [];
    /** @var list<int> */
    public array $seenExcluded = [];

    public function __construct(
        private bool $allow = true,
        private bool $includeConflictedRecipient = false
    ) {
    }

    public function assertCanAct(
        int $actorId,
        string $action,
        ?array $ticket,
        DateTimeImmutable $atInstant
    ): void {
        $this->actions[] = $action;
        if (!$this->allow) {
            throw new DomainException('ERTAQ_URGENT_ACCESS_DENIED');
        }
    }

    public function resolveProtectionRoute(
        int $actorId,
        array $ticket,
        string $riskType,
        array $excludedUserIds,
        DateTimeImmutable $atInstant
    ): array {
        $this->seenExcluded = $excludedUserIds;

        return [
            'routed_team_id' => 77,
            'route_snapshot' => [
                'policy_key' => 'protection-v1',
                'risk_type' => $riskType,
            ],
            'eligible_user_ids' => $this->includeConflictedRecipient ? [8, 9] : [8],
        ];
    }

    public function assertCanAcknowledge(
        int $actorId,
        array $ticket,
        array $urgentEvent,
        DateTimeImmutable $atInstant
    ): void {
        if (!$this->allow || $actorId !== 8) {
            throw new DomainException('ERTAQ_URGENT_ACK_DENIED');
        }
    }
}

final class ErtaqUrgentTestAudit implements AuditEventWriter
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
            throw new RuntimeException('ERTAQ_URGENT_AUDIT_FAILED');
        }
        $this->events[] = compact('action', 'entityType', 'recordId', 'name', 'details', 'context');
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
$newFixture = static function (bool $allow = true, bool $includeConflict = false): array {
    $repository = new ErtaqUrgentMemoryRepository();
    $authorization = new ErtaqUrgentTestAuthorization($allow, $includeConflict);
    $audit = new ErtaqUrgentTestAudit();

    return [$repository, $authorization, $audit, new ErtaqUrgentRoutingService(
        $repository,
        $authorization,
        $audit
    )];
};
$routeCommand = static function (string $idempotencyKey = 'ertaq-urgent-route-1'): array {
    return [
        'actor_id' => 8,
        'ticket_id' => 1,
        'expected_lock_version' => 1,
        'risk_type' => 'immediate_safeguarding_risk',
        'idempotency_key' => $idempotencyKey,
    ];
};

[$repository, $authorization, $audit, $service] = $newFixture();
$routed = $service->routeUrgentTicket($routeCommand());
$assert(
    $routed['urgent_event_id'] === 1
        && $routed['status'] === 'routed'
        && $routed['routed_team_id'] === 77
        && $repository->tickets[1]['status'] === 'urgent_protected',
    'urgent route moves only the ticket into protected state and records a protection team'
);
$assert(
    $authorization->seenExcluded === [9, 10]
        && $repository->urgentEvents[1]['conflict_exclusion_snapshot']['excluded_user_ids'] === [9, 10]
        && !array_key_exists('route_snapshot', $routed),
    'accused/conflicted identities are excluded and their route snapshot is not exposed in the receipt'
);
$assert(
    count($audit->events) === 2
        && !array_key_exists('route_snapshot', $audit->events[0]['details'])
        && !array_key_exists('excluded_user_ids', $audit->events[0]['details']),
    'urgent audit stores safe counts rather than protection-route or identity details'
);
$routeReplay = $service->routeUrgentTicket($routeCommand());
$assert(
    $routeReplay['replayed'] === true
        && count($repository->urgentEvents) === 1,
    'urgent route idempotency prevents a second protected route'
);
$acknowledged = $service->acknowledgeUrgentRoute([
    'actor_id' => 8,
    'urgent_event_id' => 1,
    'expected_lock_version' => 1,
]);
$assert(
    $acknowledged['status'] === 'acknowledged'
        && $acknowledged['acknowledged'] === true
        && $repository->urgentEvents[1]['acknowledged_by_user_id'] === 8,
    'only protection-route acknowledgement changes the urgent event'
);
$ackReplay = $service->acknowledgeUrgentRoute([
    'actor_id' => 8,
    'urgent_event_id' => 1,
    'expected_lock_version' => 1,
]);
$assert($ackReplay['replayed'] === true, 'exact acknowledgement retry does not create another event');
$assertThrows(
    static fn (): array => $service->acknowledgeUrgentRoute([
        'actor_id' => 9,
        'urgent_event_id' => 1,
        'expected_lock_version' => 2,
    ]),
    'ERTAQ_URGENT_ACK_DENIED',
    'an accused/conflicted user cannot acknowledge the protection route'
);

[$conflictRepository, , , $conflictService] = $newFixture(true, true);
$assertThrows(
    static fn (): array => $conflictService->routeUrgentTicket($routeCommand('ertaq-urgent-conflict')),
    'ERTAQ_URGENT_CONFLICT_RECIPIENT',
    'route fails closed when resolver includes an excluded manager or party'
);
$assert(
    $conflictRepository->urgentEvents === []
        && $conflictRepository->tickets[1]['status'] === 'in_progress',
    'conflict recipient failure leaves no urgent event or partial ticket transition'
);

[$deniedRepository, , , $deniedService] = $newFixture(false);
$assertThrows(
    static fn (): array => $deniedService->routeUrgentTicket($routeCommand('ertaq-urgent-denied')),
    'ERTAQ_URGENT_ACCESS_DENIED',
    'urgent route authorization is checked before persistence'
);
$assert($deniedRepository->urgentEvents === [], 'denied urgent route leaves no route evidence');

[$rollbackRepository, , $rollbackAudit, $rollbackService] = $newFixture();
$rollbackAudit->fail = true;
$assertThrows(
    static fn (): array => $rollbackService->routeUrgentTicket($routeCommand('ertaq-urgent-audit-fail')),
    'ERTAQ_URGENT_AUDIT_FAILED',
    'mandatory audit failure aborts urgent routing'
);
$assert(
    $rollbackRepository->urgentEvents === []
        && $rollbackRepository->tickets[1]['status'] === 'in_progress',
    'audit failure rolls back both urgent event and ticket state'
);

$source = (string) file_get_contents(
    dirname(__DIR__) . '/src/Modules/Staff/Application/Ertaq/ErtaqUrgentRoutingService.php'
);
$assert(
    !str_contains($source, 'StaffNotificationPort')
        && !str_contains($source, 'PayrollImpactGateway')
        && !str_contains($source, 'DisciplineCaseService'),
    'urgent routing service owns no push, Finance, or disciplinary side effect'
);

if ($failures > 0) {
    fwrite(STDERR, "{$failures} Ertaq urgent routing test failure(s).\n");
    exit(1);
}

echo 'staff_hr_ertaq_urgent_routing_service_test: PASS (' . $assertions . ' assertions)' . PHP_EOL;
