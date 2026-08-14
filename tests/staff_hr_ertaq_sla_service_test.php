<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/vendor/autoload.php';

use EduCore\Modules\Operations\Audit\AuditEventWriter;
use EduCore\Modules\Staff\Application\Ertaq\ErtaqSlaService;
use EduCore\Modules\Staff\Contracts\ErtaqSlaAuthorization;
use EduCore\Modules\Staff\Contracts\ErtaqSlaRepository;

final class ErtaqSlaMemoryRepository implements ErtaqSlaRepository
{
    /** @var array<int,array<string,mixed>> */
    public array $tickets = [
        1 => [
            'id' => 1,
            'ticket_no' => 'ERT-SLA-001',
            'ticket_hash' => '1c2720473d6f4fa231c2ca6fe129b77f2021a5805f3e1a3ca3c3ed66ecc3355f',
            'status' => 'new',
            'lock_version' => 1,
            'sla_policy_id' => 19,
            'sla_policy_snapshot' => [
                'version' => '2026.1',
                'first_response_minutes' => 60,
                'resolve_minutes' => 180,
            ],
            'first_response_due_at' => '2000-01-01 09:00:00.000000',
            'sla_due_at' => '2099-01-01 09:00:00.000000',
            'created_at' => '2026-08-01 08:00:00.000000',
            'subject' => 'محتوى سري لا يدخل مسار SLA',
        ],
    ];
    /** @var array<int,array<string,mixed>> */
    public array $events = [];
    private int $sequence = 0;

    public function transactional(callable $work): mixed
    {
        $tickets = $this->tickets;
        $events = $this->events;
        $sequence = $this->sequence;
        try {
            return $work();
        } catch (Throwable $exception) {
            $this->tickets = $tickets;
            $this->events = $events;
            $this->sequence = $sequence;
            throw $exception;
        }
    }

    public function ticketForUpdate(int $ticketId): ?array
    {
        return $this->tickets[$ticketId] ?? null;
    }

    public function slaEventByIdempotencyForUpdate(string $idempotencyKey): ?array
    {
        foreach ($this->events as $event) {
            if (($event['idempotency_key'] ?? null) === $idempotencyKey) {
                return $event;
            }
        }

        return null;
    }

    public function insertSlaEvent(array $event): int
    {
        $id = ++$this->sequence;
        $this->events[$id] = $event + [
            'id' => $id,
            'lock_version' => 1,
        ];

        return $id;
    }

    public function dueSlaEventsForUpdate(string $atInstant, int $limit): array
    {
        $events = array_values(array_filter($this->events, static function (array $event) use ($atInstant): bool {
            return ($event['status'] ?? null) === 'scheduled'
                && in_array($event['event_type'] ?? null, ['first_response_due', 'overdue'], true)
                && is_string($event['due_at'] ?? null)
                && $event['due_at'] <= $atInstant;
        }));
        usort($events, static fn (array $left, array $right): int => [
            $left['due_at'], $left['id'],
        ] <=> [
            $right['due_at'], $right['id'],
        ]);

        return array_slice($events, 0, $limit);
    }

    public function markSlaEventFired(
        int $eventId,
        int $expectedLockVersion,
        string $occurredAt,
        ?int $targetTeamId,
        ?int $targetUserId,
        array $escalationSnapshot
    ): bool {
        $event = $this->events[$eventId] ?? null;
        if ($event === null
            || ($event['status'] ?? null) !== 'scheduled'
            || (int) ($event['lock_version'] ?? 0) !== $expectedLockVersion) {
            return false;
        }
        $this->events[$eventId] = array_replace($event, [
            'status' => 'fired',
            'occurred_at' => $occurredAt,
            'target_team_id' => $targetTeamId,
            'target_user_id' => $targetUserId,
            'escalation_snapshot' => $escalationSnapshot,
            'lock_version' => $expectedLockVersion + 1,
        ]);

        return true;
    }

    public function cancelSlaEvent(
        int $eventId,
        int $expectedLockVersion,
        string $occurredAt
    ): bool {
        $event = $this->events[$eventId] ?? null;
        if ($event === null
            || ($event['status'] ?? null) !== 'scheduled'
            || (int) ($event['lock_version'] ?? 0) !== $expectedLockVersion) {
            return false;
        }
        $this->events[$eventId] = array_replace($event, [
            'status' => 'cancelled',
            'occurred_at' => $occurredAt,
            'lock_version' => $expectedLockVersion + 1,
        ]);

        return true;
    }

    public function renewTicketSlaWindow(
        int $ticketId,
        int $expectedLockVersion,
        ?string $firstResponseDueAt,
        ?string $slaDueAt
    ): bool {
        $ticket = $this->tickets[$ticketId] ?? null;
        if ($ticket === null
            || ($ticket['status'] ?? null) !== 'reopened'
            || (int) ($ticket['lock_version'] ?? 0) !== $expectedLockVersion) {
            return false;
        }
        $this->tickets[$ticketId] = array_replace($ticket, [
            'first_response_due_at' => $firstResponseDueAt,
            'sla_due_at' => $slaDueAt,
        ]);

        return true;
    }
}

final class ErtaqSlaTestAuthorization implements ErtaqSlaAuthorization
{
    /** @var list<string> */
    public array $actions = [];
    public bool $allowQueue = true;
    public bool $allowEscalation = true;
    public bool $includeTarget = true;

    public function assertCanAct(
        int $actorId,
        string $action,
        ?array $ticket,
        DateTimeImmutable $atInstant
    ): void {
        $this->actions[] = $action;
        if (($action === 'process_sla_queue' && !$this->allowQueue)
            || ($action === 'escalate_sla_event' && !$this->allowEscalation)) {
            throw new DomainException('ERTAQ_SLA_ACCESS_DENIED');
        }
    }

    public function resolveEscalation(
        int $actorId,
        array $ticket,
        array $slaEvent,
        DateTimeImmutable $atInstant
    ): array {
        if (!$this->includeTarget) {
            return ['escalation_snapshot' => ['policy_key' => 'sla-v1']];
        }

        return [
            'target_team_id' => 77,
            'target_user_id' => 8,
            'escalation_snapshot' => [
                'policy_key' => 'sla-v1',
                'route_kind' => 'administrative',
            ],
        ];
    }
}

final class ErtaqSlaTestAudit implements AuditEventWriter
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
            throw new RuntimeException('ERTAQ_SLA_AUDIT_FAILED');
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
$newFixture = static function (): array {
    $repository = new ErtaqSlaMemoryRepository();
    $authorization = new ErtaqSlaTestAuthorization();
    $audit = new ErtaqSlaTestAudit();

    return [$repository, $authorization, $audit, new ErtaqSlaService($repository, $authorization, $audit)];
};
$schedule = static function (ErtaqSlaService $service, ErtaqSlaMemoryRepository $repository): void {
    $service->scheduleTicketSla(
        $repository->tickets[1],
        7,
        new DateTimeImmutable('2026-08-01 08:00:00 UTC')
    );
};

[$repository, $authorization, $audit, $service] = $newFixture();
$schedule($service, $repository);
$assert(
    count($repository->events) === 3
        && $repository->events[1]['event_type'] === 'created'
        && $repository->events[1]['status'] === 'fired'
        && $repository->events[2]['event_type'] === 'first_response_due'
        && $repository->events[2]['status'] === 'scheduled'
        && $repository->events[3]['event_type'] === 'overdue',
    'frozen policy deadlines create one lifecycle record and two local due records'
);
$assert(
    !str_contains(json_encode($repository->events, JSON_THROW_ON_ERROR), 'محتوى سري')
        && !array_key_exists('subject', $repository->events[2]['escalation_snapshot']),
    'the SLA queue keeps deadline evidence without ticket content'
);
$schedule($service, $repository);
$assert(
    count($repository->events) === 3 && count($audit->events) === 3,
    'exact scheduling retry is idempotent and has no duplicate audit event'
);

$processed = $service->processDueEvents(['actor_id' => 8, 'limit' => 10]);
$assert(
    $processed['processed_count'] === 1
        && $processed['fired_count'] === 1
        && $processed['cancelled_count'] === 0
        && $processed['events'][0]['event_id'] === 2
        && $processed['events'][0]['escalation_event_id'] === 4
        && $repository->events[2]['status'] === 'fired'
        && $repository->events[4]['event_type'] === 'escalated'
        && $repository->events[4]['target_team_id'] === 77,
    'due event is claimed once, routed by live authorization, and retains an escalation evidence event'
);
$assert(
    in_array('process_sla_queue', $authorization->actions, true)
        && in_array('escalate_sla_event', $authorization->actions, true)
        && !str_contains(json_encode($audit->events, JSON_THROW_ON_ERROR), 'escalation_snapshot'),
    'queue and per-ticket escalation authorization run before safe audit details are stored'
);
$reprocess = $service->processDueEvents(['actor_id' => 8, 'limit' => 10]);
$assert(
    $reprocess['processed_count'] === 0 && count($repository->events) === 4,
    'a fired event is not escalated twice by a retrying worker'
);

[$supersededRepository, , , $supersededService] = $newFixture();
$schedule($supersededService, $supersededRepository);
$supersededRepository->tickets[1]['first_response_due_at'] = '2098-01-01 09:00:00.000000';
$supersededRepository->tickets[1]['sla_due_at'] = '2099-01-01 09:00:00.000000';
$schedule($supersededService, $supersededRepository);
$superseded = $supersededService->processDueEvents(['actor_id' => 8, 'limit' => 10]);
$assert(
    $superseded['fired_count'] === 0
        && $superseded['cancelled_count'] === 1
        && $supersededRepository->events[2]['status'] === 'cancelled'
        && $superseded['events'][0]['cancellation_reason'] === 'sla_window_superseded',
    'a changed frozen SLA window cancels its old due event before it can route a recipient'
);

[$terminalRepository, , , $terminalService] = $newFixture();
$schedule($terminalService, $terminalRepository);
$terminalRepository->tickets[1]['status'] = 'closed';
$terminal = $terminalService->processDueEvents(['actor_id' => 8, 'limit' => 10]);
$assert(
    $terminal['fired_count'] === 0
        && $terminal['cancelled_count'] === 1
        && $terminalRepository->events[2]['status'] === 'cancelled'
        && $terminal['events'][0]['cancellation_reason'] === 'ticket_not_active',
    'closed or protected lifecycle states cannot emit a late normal-SLA route'
);

[$reopenRepository, , $reopenAudit, $reopenService] = $newFixture();
$reopenRepository->tickets[1] = array_replace($reopenRepository->tickets[1], [
    'status' => 'reopened',
    'lock_version' => 4,
    'reopened_at' => '2026-08-08 10:00:00.000000',
]);
$schedule($reopenService, $reopenRepository);
$assert(
    $reopenRepository->tickets[1]['first_response_due_at'] === '2026-08-08 11:00:00.000000'
        && $reopenRepository->tickets[1]['sla_due_at'] === '2026-08-08 13:00:00.000000'
        && $reopenRepository->events[1]['event_type'] === 'reopened'
        && $reopenRepository->events[2]['event_type'] === 'first_response_due'
        && count($reopenAudit->events) === 4,
    'reopening renews the SLA window from the immutable policy snapshot and records a lifecycle event'
);

[$deniedRepository, $deniedAuthorization, , $deniedService] = $newFixture();
$schedule($deniedService, $deniedRepository);
$deniedAuthorization->allowQueue = false;
$assertThrows(
    static fn (): array => $deniedService->processDueEvents(['actor_id' => 8, 'limit' => 10]),
    'ERTAQ_SLA_ACCESS_DENIED',
    'a worker without current queue authority cannot claim a confidential ticket event'
);
$assert($deniedRepository->events[2]['status'] === 'scheduled', 'denied worker leaves the SLA event untouched');

[$targetRepository, $targetAuthorization, , $targetService] = $newFixture();
$schedule($targetService, $targetRepository);
$targetAuthorization->includeTarget = false;
$assertThrows(
    static fn (): array => $targetService->processDueEvents(['actor_id' => 8, 'limit' => 10]),
    'ERTAQ_SLA_TARGET_REQUIRED',
    'escalation fails closed when policy supplies no eligible team or user'
);
$assert(
    $targetRepository->events[2]['status'] === 'scheduled' && count($targetRepository->events) === 3,
    'invalid route target rolls back the claimed source event and its escalation'
);

[$rollbackRepository, , $rollbackAudit, $rollbackService] = $newFixture();
$schedule($rollbackService, $rollbackRepository);
$rollbackAudit->fail = true;
$assertThrows(
    static fn (): array => $rollbackService->processDueEvents(['actor_id' => 8, 'limit' => 10]),
    'ERTAQ_SLA_AUDIT_FAILED',
    'mandatory audit persistence failure aborts the SLA escalation batch'
);
$assert(
    $rollbackRepository->events[2]['status'] === 'scheduled' && count($rollbackRepository->events) === 3,
    'audit failure rolls back the fired state and avoids a partial escalation evidence row'
);

$source = (string) file_get_contents(
    dirname(__DIR__) . '/src/Modules/Staff/Application/Ertaq/ErtaqSlaService.php'
);
$assert(
    !str_contains($source, 'StaffNotificationPort')
        && !str_contains($source, 'body_cipher_or_text')
        && !str_contains($source, 'DisciplineCaseService'),
    'SLA owner has no push, message-content, or disciplinary side effect dependency'
);

if ($failures > 0) {
    fwrite(STDERR, "{$failures} Ertaq SLA service test failure(s).\n");
    exit(1);
}

echo 'staff_hr_ertaq_sla_service_test: PASS (' . $assertions . ' assertions)' . PHP_EOL;
