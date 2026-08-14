<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/vendor/autoload.php';

use EduCore\Modules\Operations\Audit\AuditEventWriter;
use EduCore\Modules\Staff\Application\Ertaq\ErtaqConversationService;
use EduCore\Modules\Staff\Application\Ertaq\ErtaqSlaService;
use EduCore\Modules\Staff\Application\Ertaq\ErtaqTicketService;
use EduCore\Modules\Staff\Application\Ertaq\ErtaqUrgentRoutingService;
use EduCore\Modules\Staff\Contracts\ErtaqConversationAuthorization;
use EduCore\Modules\Staff\Contracts\ErtaqConversationRepository;
use EduCore\Modules\Staff\Contracts\ErtaqSlaAuthorization;
use EduCore\Modules\Staff\Contracts\ErtaqSlaRepository;
use EduCore\Modules\Staff\Contracts\ErtaqTicketAuthorization;
use EduCore\Modules\Staff\Contracts\ErtaqTicketPolicyResolver;
use EduCore\Modules\Staff\Contracts\ErtaqTicketRepository;
use EduCore\Modules\Staff\Contracts\ErtaqUrgentRoutingAuthorization;
use EduCore\Modules\Staff\Contracts\ErtaqUrgentRoutingRepository;

/**
 * An isolated in-memory aggregate that lets the four Ertaq owners share the
 * same transaction and ticket state without opening a school database.
 */
final class ErtaqWorkflowStore implements
    ErtaqTicketRepository,
    ErtaqConversationRepository,
    ErtaqUrgentRoutingRepository,
    ErtaqSlaRepository
{
    /** @var array<int,array<string,mixed>> */
    public array $tickets = [];
    /** @var array<int,array<string,mixed>> */
    public array $assignments = [];
    /** @var array<int,array<string,mixed>> */
    public array $messages = [];
    /** @var array<int,array<string,mixed>> */
    public array $parties = [];
    /** @var array<int,array<string,mixed>> */
    public array $links = [];
    /** @var array<int,array<string,mixed>> */
    public array $withdrawals = [];
    /** @var array<int,array<string,mixed>> */
    public array $urgentEvents = [];
    /** @var array<int,array<string,mixed>> */
    public array $slaEvents = [];
    private int $ticketSequence = 0;
    private int $assignmentSequence = 0;
    private int $messageSequence = 0;
    private int $partySequence = 0;
    private int $linkSequence = 0;
    private int $withdrawalSequence = 0;
    private int $urgentSequence = 0;
    private int $slaSequence = 0;

    public function transactional(callable $work): mixed
    {
        $snapshot = [
            $this->tickets,
            $this->assignments,
            $this->messages,
            $this->parties,
            $this->links,
            $this->withdrawals,
            $this->urgentEvents,
            $this->slaEvents,
            $this->ticketSequence,
            $this->assignmentSequence,
            $this->messageSequence,
            $this->partySequence,
            $this->linkSequence,
            $this->withdrawalSequence,
            $this->urgentSequence,
            $this->slaSequence,
        ];
        try {
            return $work();
        } catch (Throwable $exception) {
            [
                $this->tickets,
                $this->assignments,
                $this->messages,
                $this->parties,
                $this->links,
                $this->withdrawals,
                $this->urgentEvents,
                $this->slaEvents,
                $this->ticketSequence,
                $this->assignmentSequence,
                $this->messageSequence,
                $this->partySequence,
                $this->linkSequence,
                $this->withdrawalSequence,
                $this->urgentSequence,
                $this->slaSequence,
            ] = $snapshot;
            throw $exception;
        }
    }

    public function lockUser(int $userId): bool
    {
        return in_array($userId, [7, 8, 9, 10], true);
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
        foreach ($this->assignments as $assignment) {
            if ((int) ($assignment['ticket_id'] ?? 0) === $ticketId
                && ($assignment['status'] ?? null) === 'active') {
                return $assignment;
            }
        }

        return null;
    }

    public function insertAssignment(array $assignment): int
    {
        $id = ++$this->assignmentSequence;
        $this->assignments[$id] = $assignment + ['id' => $id, 'status' => 'active', 'lock_version' => 1];

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
            || ($assignment['status'] ?? null) !== 'active'
            || (int) ($assignment['lock_version'] ?? 0) !== $expectedLockVersion) {
            return false;
        }
        $this->assignments[$assignmentId] = array_replace($assignment, [
            'status' => 'superseded',
            'ended_by_user_id' => $actorId,
            'ended_at' => $endedAt,
            'end_reason' => $reason,
            'lock_version' => $expectedLockVersion + 1,
        ]);

        return true;
    }

    public function messageByIdempotencyForUpdate(string $idempotencyKey): ?array
    {
        foreach ($this->messages as $message) {
            if (($message['idempotency_key'] ?? null) === $idempotencyKey) {
                return $message;
            }
        }

        return null;
    }

    public function messageForUpdate(int $messageId): ?array
    {
        return $this->messages[$messageId] ?? null;
    }

    public function insertMessage(array $message): int
    {
        $id = ++$this->messageSequence;
        $this->messages[$id] = $message + ['id' => $id];

        return $id;
    }

    public function partyByIdempotencyForUpdate(string $idempotencyKey): ?array
    {
        foreach ($this->parties as $party) {
            if (($party['idempotency_key'] ?? null) === $idempotencyKey) {
                return $party;
            }
        }

        return null;
    }

    public function insertParty(array $party): int
    {
        $id = ++$this->partySequence;
        $this->parties[$id] = $party + [
            'id' => $id,
            'conflict_status' => 'unknown',
        ];

        return $id;
    }

    public function linkByIdempotencyForUpdate(string $idempotencyKey): ?array
    {
        foreach ($this->links as $link) {
            if (($link['idempotency_key'] ?? null) === $idempotencyKey) {
                return $link;
            }
        }

        return null;
    }

    public function insertLink(array $link): int
    {
        $id = ++$this->linkSequence;
        $this->links[$id] = $link + ['id' => $id];

        return $id;
    }

    public function withdrawalByIdempotencyForUpdate(string $idempotencyKey): ?array
    {
        foreach ($this->withdrawals as $event) {
            if (($event['idempotency_key'] ?? null) === $idempotencyKey) {
                return $event;
            }
        }

        return null;
    }

    public function withdrawalEventForUpdate(int $withdrawalEventId): ?array
    {
        return $this->withdrawals[$withdrawalEventId] ?? null;
    }

    public function withdrawalDecisionForRequestForUpdate(int $requestEventId): ?array
    {
        foreach ($this->withdrawals as $event) {
            if (($event['event_type'] ?? null) === 'decided'
                && (int) ($event['request_event_id'] ?? 0) === $requestEventId) {
                return $event;
            }
        }

        return null;
    }

    public function insertWithdrawalEvent(array $event): int
    {
        $id = ++$this->withdrawalSequence;
        $this->withdrawals[$id] = $event + ['id' => $id];

        return $id;
    }

    public function protectedPartyUserIdsForTicketForUpdate(int $ticketId): array
    {
        $ids = [];
        foreach ($this->parties as $party) {
            if ((int) ($party['ticket_id'] ?? 0) !== $ticketId
                || ($party['party_user_id'] ?? null) === null) {
                continue;
            }
            if (($party['party_role'] ?? null) === 'accused'
                || in_array($party['conflict_status'] ?? null, ['declared', 'confirmed', 'excluded'], true)) {
                $ids[] = (int) $party['party_user_id'];
            }
        }

        return array_values(array_unique($ids));
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
        $this->urgentEvents[$id] = $event + ['id' => $id, 'status' => 'routed', 'lock_version' => 1];

        return $id;
    }

    public function transitionTicketToUrgent(
        int $ticketId,
        int $expectedLockVersion,
        string $fromStatus
    ): bool {
        return $this->transitionTicket($ticketId, $expectedLockVersion, $fromStatus, 'urgent_protected', []);
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
            'acknowledged_by_user_id' => $actorId,
            'acknowledged_at' => $acknowledgedAt,
            'lock_version' => $expectedLockVersion + 1,
        ]);

        return true;
    }

    public function slaEventByIdempotencyForUpdate(string $idempotencyKey): ?array
    {
        foreach ($this->slaEvents as $event) {
            if (($event['idempotency_key'] ?? null) === $idempotencyKey) {
                return $event;
            }
        }

        return null;
    }

    public function insertSlaEvent(array $event): int
    {
        $id = ++$this->slaSequence;
        $this->slaEvents[$id] = $event + ['id' => $id, 'lock_version' => 1];

        return $id;
    }

    public function dueSlaEventsForUpdate(string $atInstant, int $limit): array
    {
        $events = array_values(array_filter($this->slaEvents, static function (array $event) use ($atInstant): bool {
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
        $event = $this->slaEvents[$eventId] ?? null;
        if ($event === null
            || ($event['status'] ?? null) !== 'scheduled'
            || (int) ($event['lock_version'] ?? 0) !== $expectedLockVersion) {
            return false;
        }
        $this->slaEvents[$eventId] = array_replace($event, [
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
        $event = $this->slaEvents[$eventId] ?? null;
        if ($event === null
            || ($event['status'] ?? null) !== 'scheduled'
            || (int) ($event['lock_version'] ?? 0) !== $expectedLockVersion) {
            return false;
        }
        $this->slaEvents[$eventId] = array_replace($event, [
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

final class ErtaqWorkflowTicketAuthorization implements ErtaqTicketAuthorization
{
    public function assertCanAct(
        int $actorId,
        string $action,
        ?array $ticket,
        DateTimeImmutable $atInstant
    ): void {
    }

    public function assertCanAssign(
        int $actorId,
        array $ticket,
        ?int $assignedTeamId,
        ?int $assignedToUserId,
        DateTimeImmutable $atInstant
    ): void {
    }
}

final class ErtaqWorkflowPolicyResolver implements ErtaqTicketPolicyResolver
{
    public function resolveForCreate(
        int $requesterUserId,
        array $requested,
        DateTimeImmutable $atInstant
    ): array {
        return $this->resolved($requested);
    }

    public function resolveForClassification(
        int $actorId,
        array $ticket,
        array $requested,
        DateTimeImmutable $atInstant
    ): array {
        return $this->resolved($requested);
    }

    /** @param array<string,mixed> $requested @return array<string,mixed> */
    private function resolved(array $requested): array
    {
        $isOverdueScenario = ($requested['requested_classification'] ?? null) === 'sla_overdue';

        return [
            'classification' => (string) $requested['requested_classification'],
            'confidentiality_level' => (string) $requested['requested_confidentiality_level'],
            'priority' => (string) $requested['requested_priority'],
            'risk_level' => (string) $requested['requested_risk_level'],
            'sla_policy_id' => 19,
            'sla_policy_snapshot' => [
                'version' => '2026.1',
                'first_response_minutes' => 60,
                'resolve_minutes' => 180,
            ],
            'first_response_due_at' => $isOverdueScenario
                ? '2000-01-01 09:00:00.000000'
                : '2099-01-01 09:00:00.000000',
            'sla_due_at' => '2099-01-02 09:00:00.000000',
        ];
    }
}

final class ErtaqWorkflowConversationAuthorization implements ErtaqConversationAuthorization
{
    public function assertCanAct(
        int $actorId,
        string $action,
        ?array $ticket,
        DateTimeImmutable $atInstant
    ): void {
    }

    public function resolveMessageVisibility(
        int $actorId,
        array $ticket,
        string $messageType,
        ?string $requestedVisibility,
        DateTimeImmutable $atInstant
    ): string {
        return 'restricted';
    }

    public function resolvePartyVisibility(
        int $actorId,
        array $ticket,
        array $party,
        ?string $requestedVisibility,
        DateTimeImmutable $atInstant
    ): string {
        return ($party['party_role'] ?? null) === 'accused' ? 'protection_team' : 'restricted';
    }

    public function resolveLinkVisibility(
        int $actorId,
        array $ticket,
        array $link,
        ?string $requestedVisibility,
        DateTimeImmutable $atInstant
    ): string {
        return 'restricted';
    }
}

final class ErtaqWorkflowUrgentAuthorization implements ErtaqUrgentRoutingAuthorization
{
    /** @var list<int> */
    public array $excluded = [];

    public function assertCanAct(
        int $actorId,
        string $action,
        ?array $ticket,
        DateTimeImmutable $atInstant
    ): void {
    }

    public function resolveProtectionRoute(
        int $actorId,
        array $ticket,
        string $riskType,
        array $excludedUserIds,
        DateTimeImmutable $atInstant
    ): array {
        $this->excluded = $excludedUserIds;

        return [
            'routed_team_id' => 77,
            'eligible_user_ids' => [8],
            'route_snapshot' => ['policy_key' => 'protection-v1'],
        ];
    }

    public function assertCanAcknowledge(
        int $actorId,
        array $ticket,
        array $urgentEvent,
        DateTimeImmutable $atInstant
    ): void {
        if ($actorId !== 8) {
            throw new DomainException('ERTAQ_URGENT_ACK_DENIED');
        }
    }
}

final class ErtaqWorkflowSlaAuthorization implements ErtaqSlaAuthorization
{
    public function assertCanAct(
        int $actorId,
        string $action,
        ?array $ticket,
        DateTimeImmutable $atInstant
    ): void {
    }

    public function resolveEscalation(
        int $actorId,
        array $ticket,
        array $slaEvent,
        DateTimeImmutable $atInstant
    ): array {
        return [
            'target_team_id' => 88,
            'target_user_id' => 8,
            'escalation_snapshot' => ['policy_key' => 'sla-v1'],
        ];
    }
}

final class ErtaqWorkflowAudit implements AuditEventWriter
{
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
$newFixture = static function (): array {
    $store = new ErtaqWorkflowStore();
    $audit = new ErtaqWorkflowAudit();
    $ticketAuthorization = new ErtaqWorkflowTicketAuthorization();
    $conversationAuthorization = new ErtaqWorkflowConversationAuthorization();
    $urgentAuthorization = new ErtaqWorkflowUrgentAuthorization();
    $slaAuthorization = new ErtaqWorkflowSlaAuthorization();
    $sla = new ErtaqSlaService($store, $slaAuthorization, $audit);

    return [
        $store,
        $audit,
        $urgentAuthorization,
        $sla,
        new ErtaqTicketService($store, $ticketAuthorization, new ErtaqWorkflowPolicyResolver(), $audit, $sla),
        new ErtaqConversationService($store, $conversationAuthorization, $audit),
        new ErtaqUrgentRoutingService($store, $urgentAuthorization, $audit),
    ];
};
$ticketCommand = static function (
    string $key,
    string $classification,
    string $priority = 'normal',
    string $riskLevel = 'none'
): array {
    return [
        'actor_id' => 7,
        'requester_user_id' => 7,
        'type' => 'complaint',
        'classification' => $classification,
        'confidentiality_level' => 'restricted',
        'priority' => $priority,
        'risk_level' => $riskLevel,
        'subject' => 'نص سري لا يجب أن ينتقل خارج سجل التذكرة.',
        'create_idempotency_key' => $key,
    ];
};

[$store, $audit, $urgentAuthorization, $sla, $tickets, $conversation, $urgent] = $newFixture();

$urgentTicket = $tickets->createTicket($ticketCommand('workflow-urgent-1', 'safeguarding', 'urgent', 'immediate'));
$accusedParty = $conversation->addParty([
    'actor_id' => 8,
    'ticket_id' => $urgentTicket['ticket_id'],
    'party_user_id' => 9,
    'party_role' => 'accused',
    'idempotency_key' => 'workflow-accused-1',
]);
$urgentRoute = $urgent->routeUrgentTicket([
    'actor_id' => 8,
    'ticket_id' => $urgentTicket['ticket_id'],
    'expected_lock_version' => 1,
    'risk_type' => 'immediate_safeguarding_risk',
    'idempotency_key' => 'workflow-urgent-route-1',
]);
$assert(
    $accusedParty['visibility_scope'] === 'protection_team'
        && $urgentAuthorization->excluded === [9]
        && $urgentRoute['routed_team_id'] === 77
        && $store->tickets[1]['status'] === 'urgent_protected'
        && !str_contains(json_encode($urgentRoute, JSON_THROW_ON_ERROR), '9'),
    'an accused manager is excluded from the urgent route and receives no identity-bearing route receipt'
);

$collectiveTicket = $tickets->createTicket($ticketCommand('workflow-collective-2', 'employee_relations'));
$collectiveParty = $conversation->addParty([
    'actor_id' => 8,
    'ticket_id' => $collectiveTicket['ticket_id'],
    'party_user_id' => 10,
    'party_role' => 'complainant',
    'idempotency_key' => 'workflow-collective-party-2',
]);
$collectiveLink = $conversation->linkTicket([
    'actor_id' => 8,
    'ticket_id' => $collectiveTicket['ticket_id'],
    'related_ticket_id' => $urgentTicket['ticket_id'],
    'link_type' => 'collective',
    'link_reason' => 'موضوع مشترك مع سجل مستقل.',
    'idempotency_key' => 'workflow-collective-link-2',
]);
$assert(
    $collectiveParty['party_role'] === 'complainant'
        && $collectiveLink['link_type'] === 'collective'
        && $collectiveLink['related_ticket_id'] === $urgentTicket['ticket_id']
        && $store->links[1]['target_resource_type'] === null,
    'collective handling keeps a separate party and scalar ticket link without copying confidential text'
);

$tickets->transitionTicket([
    'actor_id' => 8,
    'ticket_id' => $collectiveTicket['ticket_id'],
    'expected_lock_version' => 1,
    'to_status' => 'triaged',
]);
$tickets->assignTicket([
    'actor_id' => 8,
    'ticket_id' => $collectiveTicket['ticket_id'],
    'expected_lock_version' => 2,
    'assigned_team_id' => 11,
    'assignment_reason' => 'فريق مستقل لمعالجة الطلب.',
    'idempotency_key' => 'workflow-assignment-2',
]);
$tickets->transitionTicket([
    'actor_id' => 8,
    'ticket_id' => $collectiveTicket['ticket_id'],
    'expected_lock_version' => 3,
    'to_status' => 'in_progress',
]);
$withdrawal = $conversation->requestWithdrawal([
    'actor_id' => 7,
    'ticket_id' => $collectiveTicket['ticket_id'],
    'expected_lock_version' => 4,
    'withdrawal_reason' => 'يرغب صاحب الطلب في الاستمرار فقط بعد مراجعة مستقلة.',
    'idempotency_key' => 'workflow-withdraw-request-2',
]);
$withdrawalDecision = $conversation->decideWithdrawal([
    'actor_id' => 8,
    'request_event_id' => $withdrawal['withdrawal_event_id'],
    'expected_ticket_lock_version' => 5,
    'outcome' => 'continue_processing',
    'decision_reason' => 'تستلزم المصلحة معالجة أصلية دون حذف السجل.',
    'idempotency_key' => 'workflow-withdraw-decision-2',
]);
$assert(
    $withdrawal['event_type'] === 'requested'
        && $withdrawalDecision['outcome'] === 'continue_processing'
        && $store->tickets[2]['status'] === 'in_progress'
        && $store->withdrawals[1]['prior_ticket_status'] === 'in_progress'
        && count($store->withdrawals) === 2,
    'a requester withdrawal remains append-only and an independent decision restores the exact prior state'
);

$tickets->transitionTicket([
    'actor_id' => 8,
    'ticket_id' => $collectiveTicket['ticket_id'],
    'expected_lock_version' => 6,
    'to_status' => 'resolved',
    'resolution_summary' => 'اكتملت المراجعة الأولية.',
]);
$tickets->transitionTicket([
    'actor_id' => 8,
    'ticket_id' => $collectiveTicket['ticket_id'],
    'expected_lock_version' => 7,
    'to_status' => 'closed',
    'closure_reason' => 'أغلق بعد توثيق النتيجة.',
]);
$oldFirstDue = $store->tickets[2]['first_response_due_at'];
$reopened = $tickets->transitionTicket([
    'actor_id' => 8,
    'ticket_id' => $collectiveTicket['ticket_id'],
    'expected_lock_version' => 8,
    'to_status' => 'reopened',
    'reopen_reason' => 'ظهر دليل لاحق يستحق الاستكمال.',
]);
$reopenedSlaEvents = array_values(array_filter($store->slaEvents, static fn (array $event): bool =>
    (int) $event['ticket_id'] === $collectiveTicket['ticket_id'] && ($event['event_type'] ?? null) === 'reopened'
));
$assert(
    $reopened['status'] === 'reopened'
        && $store->tickets[2]['first_response_due_at'] !== $oldFirstDue
        && count($reopenedSlaEvents) === 1
        && $reopenedSlaEvents[0]['status'] === 'fired'
        && $store->tickets[2]['lock_version'] === 9,
    'a close/reopen cycle retains history and renews a new SLA window from its frozen policy snapshot'
);

$slaTicket = $tickets->createTicket($ticketCommand('workflow-sla-3', 'sla_overdue'));
$slaResult = $sla->processDueEvents(['actor_id' => 8, 'limit' => 10]);
$firedSla = array_values(array_filter($slaResult['events'], static fn (array $event): bool =>
    (int) $event['ticket_id'] === $slaTicket['ticket_id'] && ($event['status'] ?? null) === 'fired'
));
$assert(
    $slaResult['fired_count'] === 1
        && count($firedSla) === 1
        && $firedSla[0]['target_team_id'] === 88
        && count(array_filter($store->slaEvents, static fn (array $event): bool =>
            (int) $event['ticket_id'] === $slaTicket['ticket_id'] && ($event['event_type'] ?? null) === 'escalated'
        )) === 1,
    'a due complaint creates a single authorized SLA escalation evidence event without opening a notification route'
);
$assert(
    !str_contains(json_encode($audit->events, JSON_THROW_ON_ERROR), 'نص سري')
        && !str_contains(json_encode($store->slaEvents, JSON_THROW_ON_ERROR), 'نص سري')
        && !str_contains(json_encode($store->urgentEvents, JSON_THROW_ON_ERROR), 'نص سري'),
    'combined workflow preserves confidentiality across urgent, collective, withdrawal, reopen, and SLA evidence'
);

$ordinarySuggestion = $tickets->createTicket(array_replace(
    $ticketCommand('workflow-suggestion-4', 'improvement'),
    [
        'type' => 'suggestion',
        'subject' => 'مقترح تحسين عادي يبقى منفصلًا عن بلاغ الحماية.',
    ]
));
$assert(
    $ordinarySuggestion['status'] === 'new'
        && ($store->tickets[$ordinarySuggestion['ticket_id']]['type'] ?? null) === 'suggestion'
        && !array_key_exists('subject', $ordinarySuggestion),
    'an ordinary suggestion remains a separate self-service ticket with no subject in its receipt'
);

if ($failures > 0) {
    fwrite(STDERR, "{$failures} Ertaq workflow test failure(s).\n");
    exit(1);
}

echo 'staff_hr_ertaq_workflow_test: PASS (' . $assertions . ' assertions)' . PHP_EOL;
