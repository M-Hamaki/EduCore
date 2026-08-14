<?php

declare(strict_types=1);

namespace EduCore\Modules\Staff\Application\Ertaq;

use DateInterval;
use DateTimeImmutable;
use DateTimeInterface;
use DateTimeZone;
use DomainException;
use EduCore\Modules\Operations\Audit\AuditEventWriter;
use EduCore\Modules\Staff\Contracts\ErtaqSlaAuthorization;
use EduCore\Modules\Staff\Contracts\ErtaqSlaRepository;
use EduCore\Modules\Staff\Contracts\ErtaqSlaScheduleQueue;
use InvalidArgumentException;
use JsonException;

/**
 * Owns the local, transactional SLA evidence queue for Ertaq tickets.
 *
 * This service records frozen deadline evidence and authorized escalation
 * targets only. It deliberately does not send push/browser notifications or
 * reveal ticket text; a later notification owner consumes the safe fired
 * event after its own visibility check.
 */
final class ErtaqSlaService implements ErtaqSlaScheduleQueue
{
    /** @var list<string> */
    private const DUE_EVENT_TYPES = ['first_response_due', 'overdue'];

    /** @var list<string> */
    private const ACTIVE_TICKET_STATUSES = [
        'new', 'triaged', 'assigned', 'in_progress', 'awaiting_requester', 'reopened',
    ];

    /** @var list<string> */
    private const INACTIVE_TICKET_STATUSES = [
        'resolved', 'closed', 'withdrawal_requested', 'urgent_protected', 'cancelled',
    ];

    public function __construct(
        private ErtaqSlaRepository $repository,
        private ErtaqSlaAuthorization $authorization,
        private AuditEventWriter $audit
    ) {
    }

    /**
     * Schedules immutable local records from the ticket's frozen SLA window.
     * The ticket owner calls this inside the ticket write transaction, so an
     * audit or queue failure rolls back the ticket together with its SLA rows.
     *
     * @param array<string,mixed> $ticket
     */
    public function scheduleTicketSla(
        array $ticket,
        int $actorId,
        DateTimeImmutable $atInstant
    ): void {
        $ticketId = $this->positiveId($ticket['id'] ?? null, 'ERTAQ_SLA_TICKET_ID_INVALID');
        $actorId = $this->positiveId($actorId, 'ERTAQ_SLA_ACTOR_INVALID');
        $status = $this->ticketStatus($ticket);
        $ticketHash = $this->requiredHash($ticket['ticket_hash'] ?? null, 'ERTAQ_SLA_TICKET_HASH_INVALID');

        $this->repository->transactional(function () use (
            $ticket,
            $ticketId,
            $actorId,
            $status,
            $ticketHash,
            $atInstant
        ): void {
            $effectiveTicket = $ticket;
            if ($status === 'reopened') {
                $window = $this->reopenedWindow($ticket);
                $lockVersion = $this->positiveId(
                    $ticket['lock_version'] ?? null,
                    'ERTAQ_SLA_TICKET_LOCK_INVALID'
                );
                if (!$this->repository->renewTicketSlaWindow(
                    $ticketId,
                    $lockVersion,
                    $window['first_response_due_at'],
                    $window['sla_due_at']
                )) {
                    throw new DomainException('ERTAQ_SLA_TICKET_STALE');
                }
                $effectiveTicket = array_replace($ticket, $window);
            } else {
                $window = $this->ticketWindow($ticket);
            }

            $windowHash = $this->slaWindowHash($effectiveTicket, $window);
            $events = [];
            if ($status === 'new') {
                $events[] = $this->lifecycleEventInput(
                    $effectiveTicket,
                    'created',
                    $this->requiredTicketInstant($effectiveTicket, 'created_at', 'ERTAQ_SLA_CREATED_AT_INVALID'),
                    $ticketHash
                );
            }
            if ($status === 'reopened') {
                $events[] = $this->lifecycleEventInput(
                    $effectiveTicket,
                    'reopened',
                    $this->requiredTicketInstant($effectiveTicket, 'reopened_at', 'ERTAQ_SLA_REOPENED_AT_INVALID'),
                    $ticketHash
                );
                $this->audit->recordEvent(
                    'staff_ertaq_sla_window_reopened',
                    'staff_ertaq_tickets',
                    $ticketId,
                    (string) ($effectiveTicket['ticket_no'] ?? ''),
                    [
                        'has_first_response_deadline' => $window['first_response_due_at'] !== null,
                        'has_resolution_deadline' => $window['sla_due_at'] !== null,
                        'sla_policy_id' => $this->nullablePositiveId(
                            $effectiveTicket['sla_policy_id'] ?? null,
                            'ERTAQ_SLA_POLICY_ID_INVALID'
                        ),
                    ],
                    ['user_id' => $actorId, 'occurred_at' => $this->instant($atInstant)]
                );
            }
            foreach (['first_response_due' => 'first_response_due_at', 'overdue' => 'sla_due_at'] as $eventType => $dueField) {
                $dueAt = $window[$dueField];
                if ($dueAt !== null) {
                    $events[] = $this->dueEventInput(
                        $effectiveTicket,
                        $eventType,
                        $dueAt,
                        $ticketHash,
                        $windowHash
                    );
                }
            }

            foreach ($events as $event) {
                $existing = $this->repository->slaEventByIdempotencyForUpdate((string) $event['idempotency_key']);
                if ($existing !== null) {
                    if (!hash_equals(
                        (string) ($existing['event_hash'] ?? ''),
                        (string) $event['event_hash']
                    )) {
                        throw new DomainException('ERTAQ_SLA_IDEMPOTENCY_CONFLICT');
                    }
                    continue;
                }
                $eventId = $this->repository->insertSlaEvent($event);
                if ($eventId <= 0) {
                    throw new DomainException('ERTAQ_SLA_EVENT_PERSIST_FAILED');
                }
                $this->recordScheduledAudit($eventId, $event, $actorId, $atInstant);
            }
        });
    }

    /**
     * Claims due records under a row lock and creates an immutable escalation
     * event for every fire. A terminal, protected, or superseded ticket only
     * cancels the stale queue record; it never generates a recipient route.
     *
     * @param array<string,mixed> $command
     * @return array{processed_count:int,fired_count:int,cancelled_count:int,events:list<array<string,mixed>>}
     */
    public function processDueEvents(array $command): array
    {
        $actorId = $this->positiveId($command['actor_id'] ?? null, 'ERTAQ_SLA_ACTOR_INVALID');
        $limit = $this->boundedLimit($command['limit'] ?? 50);
        $now = $this->now();
        $this->authorization->assertCanAct($actorId, 'process_sla_queue', null, $now);

        return $this->repository->transactional(function () use ($actorId, $limit, $now): array {
            $events = $this->repository->dueSlaEventsForUpdate($this->instant($now), $limit);
            $results = [];
            $firedCount = 0;
            $cancelledCount = 0;

            foreach ($events as $event) {
                $eventId = $this->positiveId($event['id'] ?? null, 'ERTAQ_SLA_EVENT_ID_INVALID');
                $ticketId = $this->positiveId($event['ticket_id'] ?? null, 'ERTAQ_SLA_TICKET_ID_INVALID');
                $eventType = $this->dueEventType($event['event_type'] ?? null);
                $lockVersion = $this->positiveId($event['lock_version'] ?? null, 'ERTAQ_SLA_EVENT_LOCK_INVALID');
                if ((string) ($event['status'] ?? '') !== 'scheduled') {
                    throw new DomainException('ERTAQ_SLA_EVENT_STATE_INVALID');
                }
                $ticket = $this->requiredTicket($ticketId);
                $status = $this->ticketStatus($ticket);
                $cancellationReason = $this->cancellationReason($ticket, $event);
                if ($cancellationReason !== null) {
                    if (!$this->repository->cancelSlaEvent($eventId, $lockVersion, $this->instant($now))) {
                        throw new DomainException('ERTAQ_SLA_EVENT_STALE');
                    }
                    ++$cancelledCount;
                    $this->audit->recordEvent(
                        'staff_ertaq_sla_event_cancelled',
                        'staff_ertaq_sla_events',
                        $eventId,
                        null,
                        [
                            'ticket_id' => $ticketId,
                            'event_type' => $eventType,
                            'ticket_status' => $status,
                            'reason' => $cancellationReason,
                        ],
                        ['user_id' => $actorId, 'occurred_at' => $this->instant($now)]
                    );
                    $results[] = $this->eventReceipt($event, 'cancelled', $cancellationReason);
                    continue;
                }

                $this->authorization->assertCanAct($actorId, 'escalate_sla_event', $ticket, $now);
                $route = $this->authorization->resolveEscalation($actorId, $ticket, $event, $now);
                $targetTeamId = $this->nullablePositiveId(
                    $route['target_team_id'] ?? null,
                    'ERTAQ_SLA_TARGET_TEAM_INVALID'
                );
                $targetUserId = $this->nullablePositiveId(
                    $route['target_user_id'] ?? null,
                    'ERTAQ_SLA_TARGET_USER_INVALID'
                );
                if ($targetTeamId === null && $targetUserId === null) {
                    throw new DomainException('ERTAQ_SLA_TARGET_REQUIRED');
                }
                $snapshot = $route['escalation_snapshot'] ?? null;
                if (!is_array($snapshot)) {
                    throw new InvalidArgumentException('ERTAQ_SLA_ESCALATION_SNAPSHOT_INVALID');
                }
                if (!$this->repository->markSlaEventFired(
                    $eventId,
                    $lockVersion,
                    $this->instant($now),
                    $targetTeamId,
                    $targetUserId,
                    $snapshot
                )) {
                    throw new DomainException('ERTAQ_SLA_EVENT_STALE');
                }

                $escalation = $this->escalationEventInput(
                    $event,
                    $ticket,
                    $targetTeamId,
                    $targetUserId,
                    $snapshot,
                    $now
                );
                $escalationId = $this->repository->insertSlaEvent($escalation);
                if ($escalationId <= 0) {
                    throw new DomainException('ERTAQ_SLA_ESCALATION_PERSIST_FAILED');
                }
                ++$firedCount;
                $this->audit->recordEvent(
                    'staff_ertaq_sla_event_fired',
                    'staff_ertaq_sla_events',
                    $eventId,
                    null,
                    [
                        'ticket_id' => $ticketId,
                        'event_type' => $eventType,
                        'escalation_level' => (int) ($event['escalation_level'] ?? 0),
                        'target_team_id' => $targetTeamId,
                        'target_user_id' => $targetUserId,
                    ],
                    ['user_id' => $actorId, 'occurred_at' => $this->instant($now)]
                );
                $this->audit->recordEvent(
                    'staff_ertaq_sla_event_escalated',
                    'staff_ertaq_sla_events',
                    $escalationId,
                    null,
                    [
                        'ticket_id' => $ticketId,
                        'source_event_id' => $eventId,
                        'source_event_type' => $eventType,
                        'escalation_level' => (int) $escalation['escalation_level'],
                        'target_team_id' => $targetTeamId,
                        'target_user_id' => $targetUserId,
                    ],
                    ['user_id' => $actorId, 'occurred_at' => $this->instant($now)]
                );
                $fired = array_replace($event, [
                    'status' => 'fired',
                    'target_team_id' => $targetTeamId,
                    'target_user_id' => $targetUserId,
                    'lock_version' => $lockVersion + 1,
                ]);
                $results[] = $this->eventReceipt($fired, 'fired', null) + [
                    'escalation_event_id' => $escalationId,
                ];
            }

            return [
                'processed_count' => count($results),
                'fired_count' => $firedCount,
                'cancelled_count' => $cancelledCount,
                'events' => $results,
            ];
        });
    }

    /** @param array<string,mixed> $ticket @return array{first_response_due_at:?string,sla_due_at:?string} */
    private function ticketWindow(array $ticket): array
    {
        $firstResponse = $this->nullableInstant(
            $ticket['first_response_due_at'] ?? null,
            'ERTAQ_SLA_FIRST_RESPONSE_DUE_INVALID'
        );
        $slaDue = $this->nullableInstant($ticket['sla_due_at'] ?? null, 'ERTAQ_SLA_DUE_INVALID');
        if ($firstResponse !== null && $slaDue !== null && $firstResponse > $slaDue) {
            throw new DomainException('ERTAQ_SLA_WINDOW_INVALID');
        }

        return [
            'first_response_due_at' => $firstResponse === null ? null : $this->instant($firstResponse),
            'sla_due_at' => $slaDue === null ? null : $this->instant($slaDue),
        ];
    }

    /** @param array<string,mixed> $ticket @return array{first_response_due_at:?string,sla_due_at:?string} */
    private function reopenedWindow(array $ticket): array
    {
        $snapshot = $ticket['sla_policy_snapshot'] ?? null;
        if ($snapshot === null) {
            return ['first_response_due_at' => null, 'sla_due_at' => null];
        }
        if (!is_array($snapshot)) {
            throw new InvalidArgumentException('ERTAQ_SLA_POLICY_SNAPSHOT_INVALID');
        }
        $reopenedAt = $this->requiredTicketInstant($ticket, 'reopened_at', 'ERTAQ_SLA_REOPENED_AT_INVALID');
        $firstResponseMinutes = $this->nullableMinutes(
            $snapshot['first_response_minutes'] ?? null,
            'ERTAQ_SLA_FIRST_RESPONSE_MINUTES_INVALID'
        );
        $resolveMinutes = $this->nullableMinutes(
            $snapshot['resolve_minutes'] ?? null,
            'ERTAQ_SLA_RESOLVE_MINUTES_INVALID'
        );
        if ($firstResponseMinutes !== null && $resolveMinutes !== null && $firstResponseMinutes > $resolveMinutes) {
            throw new DomainException('ERTAQ_SLA_WINDOW_INVALID');
        }

        return [
            'first_response_due_at' => $firstResponseMinutes === null
                ? null
                : $this->instant($reopenedAt->add(new DateInterval('PT' . $firstResponseMinutes . 'M'))),
            'sla_due_at' => $resolveMinutes === null
                ? null
                : $this->instant($reopenedAt->add(new DateInterval('PT' . $resolveMinutes . 'M'))),
        ];
    }

    /** @param array<string,mixed> $ticket @param array{first_response_due_at:?string,sla_due_at:?string} $window */
    private function slaWindowHash(array $ticket, array $window): string
    {
        $snapshot = $ticket['sla_policy_snapshot'] ?? null;
        if ($snapshot !== null && !is_array($snapshot)) {
            throw new InvalidArgumentException('ERTAQ_SLA_POLICY_SNAPSHOT_INVALID');
        }

        return $this->hash([
            'sla_policy_id' => $this->nullablePositiveId(
                $ticket['sla_policy_id'] ?? null,
                'ERTAQ_SLA_POLICY_ID_INVALID'
            ),
            'sla_policy_snapshot' => $snapshot,
            'first_response_due_at' => $window['first_response_due_at'],
            'sla_due_at' => $window['sla_due_at'],
        ], 'ERTAQ_SLA_SERIALIZATION_INVALID');
    }

    /** @param array<string,mixed> $ticket @return array<string,mixed> */
    private function lifecycleEventInput(
        array $ticket,
        string $eventType,
        DateTimeImmutable $occurredAt,
        string $ticketHash
    ): array {
        $ticketId = $this->positiveId($ticket['id'] ?? null, 'ERTAQ_SLA_TICKET_ID_INVALID');
        $lifecycleKey = $eventType === 'reopened'
            ? $this->instant($occurredAt)
            : $ticketHash;
        $snapshot = [
            'schema_version' => 1,
            'source_ticket_hash' => $ticketHash,
            'lifecycle' => $eventType,
        ];

        return $this->eventInput([
            'ticket_id' => $ticketId,
            'event_type' => $eventType,
            'status' => 'fired',
            'due_at' => null,
            'occurred_at' => $this->instant($occurredAt),
            'escalation_level' => 0,
            'target_team_id' => null,
            'target_user_id' => null,
            'escalation_snapshot' => $snapshot,
            'idempotency_seed' => 'lifecycle:' . $eventType . ':' . $ticketId . ':' . $lifecycleKey,
        ]);
    }

    /** @param array<string,mixed> $ticket @return array<string,mixed> */
    private function dueEventInput(
        array $ticket,
        string $eventType,
        string $dueAt,
        string $ticketHash,
        string $windowHash
    ): array {
        $ticketId = $this->positiveId($ticket['id'] ?? null, 'ERTAQ_SLA_TICKET_ID_INVALID');
        $snapshot = [
            'schema_version' => 1,
            'source_sla_window_hash' => $windowHash,
            'source_ticket_hash' => $ticketHash,
        ];

        return $this->eventInput([
            'ticket_id' => $ticketId,
            'event_type' => $eventType,
            'status' => 'scheduled',
            'due_at' => $dueAt,
            'occurred_at' => null,
            'escalation_level' => 0,
            'target_team_id' => null,
            'target_user_id' => null,
            'escalation_snapshot' => $snapshot,
            'idempotency_seed' => 'due:' . $eventType . ':' . $ticketId . ':' . $windowHash . ':' . $dueAt,
        ]);
    }

    /** @param array<string,mixed> $sourceEvent @param array<string,mixed> $ticket @param array<string,mixed> $snapshot @return array<string,mixed> */
    private function escalationEventInput(
        array $sourceEvent,
        array $ticket,
        ?int $targetTeamId,
        ?int $targetUserId,
        array $snapshot,
        DateTimeImmutable $now
    ): array {
        $sourceEventId = $this->positiveId($sourceEvent['id'] ?? null, 'ERTAQ_SLA_EVENT_ID_INVALID');
        $ticketId = $this->positiveId($ticket['id'] ?? null, 'ERTAQ_SLA_TICKET_ID_INVALID');
        $sourceHash = $this->requiredHash($sourceEvent['event_hash'] ?? null, 'ERTAQ_SLA_EVENT_HASH_INVALID');
        $eventLevel = (int) ($sourceEvent['escalation_level'] ?? 0);
        if ($eventLevel < 0) {
            throw new DomainException('ERTAQ_SLA_ESCALATION_LEVEL_INVALID');
        }
        $escalationLevel = $eventLevel + 1;
        $safeSnapshot = [
            'schema_version' => 1,
            'source_event_id' => $sourceEventId,
            'source_event_hash' => $sourceHash,
            'route' => $snapshot,
        ];

        return $this->eventInput([
            'ticket_id' => $ticketId,
            'event_type' => 'escalated',
            'status' => 'fired',
            'due_at' => null,
            'occurred_at' => $this->instant($now),
            'escalation_level' => $escalationLevel,
            'target_team_id' => $targetTeamId,
            'target_user_id' => $targetUserId,
            'escalation_snapshot' => $safeSnapshot,
            'idempotency_seed' => 'escalation:' . $sourceEventId . ':' . $sourceHash,
        ]);
    }

    /** @param array<string,mixed> $values @return array<string,mixed> */
    private function eventInput(array $values): array
    {
        $idempotencySeed = $this->requiredText(
            $values['idempotency_seed'] ?? null,
            512,
            'ERTAQ_SLA_IDEMPOTENCY_INVALID'
        );
        unset($values['idempotency_seed']);
        $eventHash = $this->hash($values, 'ERTAQ_SLA_SERIALIZATION_INVALID');
        $values['event_hash'] = $eventHash;
        $values['idempotency_key'] = hash('sha256', 'ertaq-sla-v1:' . $idempotencySeed);

        return $values;
    }

    /** @param array<string,mixed> $event */
    private function recordScheduledAudit(
        int $eventId,
        array $event,
        int $actorId,
        DateTimeImmutable $atInstant
    ): void {
        $isScheduled = (string) ($event['status'] ?? '') === 'scheduled';
        $this->audit->recordEvent(
            $isScheduled ? 'staff_ertaq_sla_due_scheduled' : 'staff_ertaq_sla_lifecycle_recorded',
            'staff_ertaq_sla_events',
            $eventId,
            null,
            [
                'ticket_id' => $this->positiveId($event['ticket_id'] ?? null, 'ERTAQ_SLA_TICKET_ID_INVALID'),
                'event_type' => (string) ($event['event_type'] ?? ''),
                'status' => (string) ($event['status'] ?? ''),
                'has_due_at' => ($event['due_at'] ?? null) !== null,
                'escalation_level' => (int) ($event['escalation_level'] ?? 0),
            ],
            ['user_id' => $actorId, 'occurred_at' => $this->instant($atInstant)]
        );
    }

    /** @param array<string,mixed> $ticket @param array<string,mixed> $event */
    private function cancellationReason(array $ticket, array $event): ?string
    {
        $status = $this->ticketStatus($ticket);
        if (in_array($status, self::INACTIVE_TICKET_STATUSES, true)) {
            return 'ticket_not_active';
        }
        if (!in_array($status, self::ACTIVE_TICKET_STATUSES, true)) {
            throw new DomainException('ERTAQ_SLA_TICKET_STATUS_INVALID');
        }
        $snapshot = $event['escalation_snapshot'] ?? null;
        if (!is_array($snapshot)) {
            throw new DomainException('ERTAQ_SLA_EVENT_SNAPSHOT_INVALID');
        }
        $sourceWindowHash = $this->requiredHash(
            $snapshot['source_sla_window_hash'] ?? null,
            'ERTAQ_SLA_EVENT_SNAPSHOT_INVALID'
        );
        $currentWindowHash = $this->slaWindowHash($ticket, $this->ticketWindow($ticket));

        return hash_equals($sourceWindowHash, $currentWindowHash) ? null : 'sla_window_superseded';
    }

    /** @param array<string,mixed> $event @return array<string,mixed> */
    private function eventReceipt(array $event, string $status, ?string $cancellationReason): array
    {
        $receipt = [
            'event_id' => $this->positiveId($event['id'] ?? null, 'ERTAQ_SLA_EVENT_ID_INVALID'),
            'ticket_id' => $this->positiveId($event['ticket_id'] ?? null, 'ERTAQ_SLA_TICKET_ID_INVALID'),
            'event_type' => $this->dueEventType($event['event_type'] ?? null),
            'status' => $status,
            'escalation_level' => (int) ($event['escalation_level'] ?? 0),
        ];
        if ($status === 'fired') {
            $receipt['target_team_id'] = $this->nullablePositiveId(
                $event['target_team_id'] ?? null,
                'ERTAQ_SLA_TARGET_TEAM_INVALID'
            );
            $receipt['target_user_id'] = $this->nullablePositiveId(
                $event['target_user_id'] ?? null,
                'ERTAQ_SLA_TARGET_USER_INVALID'
            );
        }
        if ($cancellationReason !== null) {
            $receipt['cancellation_reason'] = $cancellationReason;
        }

        return $receipt;
    }

    /** @return array<string,mixed> */
    private function requiredTicket(int $ticketId): array
    {
        $ticket = $this->repository->ticketForUpdate($ticketId);
        if ($ticket === null) {
            throw new DomainException('ERTAQ_TICKET_NOT_FOUND');
        }

        return $ticket;
    }

    /** @param array<string,mixed> $ticket */
    private function ticketStatus(array $ticket): string
    {
        $status = $this->requiredText($ticket['status'] ?? null, 40, 'ERTAQ_SLA_TICKET_STATUS_INVALID');
        if (!in_array($status, self::ACTIVE_TICKET_STATUSES, true)
            && !in_array($status, self::INACTIVE_TICKET_STATUSES, true)) {
            throw new DomainException('ERTAQ_SLA_TICKET_STATUS_INVALID');
        }

        return $status;
    }

    private function dueEventType(mixed $value): string
    {
        $eventType = $this->requiredText($value, 40, 'ERTAQ_SLA_EVENT_TYPE_INVALID');
        if (!in_array($eventType, self::DUE_EVENT_TYPES, true)) {
            throw new DomainException('ERTAQ_SLA_EVENT_TYPE_INVALID');
        }

        return $eventType;
    }

    /** @param array<string,mixed> $ticket */
    private function requiredTicketInstant(array $ticket, string $field, string $error): DateTimeImmutable
    {
        $instant = $this->nullableInstant($ticket[$field] ?? null, $error);
        if ($instant === null) {
            throw new DomainException($error);
        }

        return $instant;
    }

    private function nullableInstant(mixed $value, string $error): ?DateTimeImmutable
    {
        if ($value === null || $value === '') {
            return null;
        }
        if (!is_string($value)) {
            throw new InvalidArgumentException($error);
        }
        try {
            return (new DateTimeImmutable($value, new DateTimeZone('UTC')))
                ->setTimezone(new DateTimeZone('UTC'));
        } catch (\Exception) {
            throw new InvalidArgumentException($error);
        }
    }

    private function nullableMinutes(mixed $value, string $error): ?int
    {
        if ($value === null) {
            return null;
        }
        $minutes = filter_var($value, FILTER_VALIDATE_INT);
        if ($minutes === false || $minutes <= 0) {
            throw new InvalidArgumentException($error);
        }

        return (int) $minutes;
    }

    private function nullablePositiveId(mixed $value, string $error): ?int
    {
        if ($value === null || $value === '') {
            return null;
        }

        return $this->positiveId($value, $error);
    }

    private function positiveId(mixed $value, string $error): int
    {
        $id = filter_var($value, FILTER_VALIDATE_INT);
        if ($id === false || $id <= 0) {
            throw new InvalidArgumentException($error);
        }

        return (int) $id;
    }

    private function boundedLimit(mixed $value): int
    {
        $limit = filter_var($value, FILTER_VALIDATE_INT);
        if ($limit === false || $limit < 1 || $limit > 100) {
            throw new InvalidArgumentException('ERTAQ_SLA_LIMIT_INVALID');
        }

        return (int) $limit;
    }

    private function requiredText(mixed $value, int $maxBytes, string $error): string
    {
        if (!is_string($value)) {
            throw new InvalidArgumentException($error);
        }
        $text = trim($value);
        if ($text === '' || strlen($text) > $maxBytes) {
            throw new InvalidArgumentException($error);
        }

        return $text;
    }

    private function requiredHash(mixed $value, string $error): string
    {
        if (!is_string($value) || !preg_match('/^[a-f0-9]{64}$/', $value)) {
            throw new InvalidArgumentException($error);
        }

        return $value;
    }

    private function now(): DateTimeImmutable
    {
        return new DateTimeImmutable('now', new DateTimeZone('UTC'));
    }

    private function instant(DateTimeInterface $instant): string
    {
        return DateTimeImmutable::createFromInterface($instant)
            ->setTimezone(new DateTimeZone('UTC'))
            ->format('Y-m-d H:i:s.u');
    }

    /** @param array<string,mixed> $value */
    private function hash(array $value, string $error): string
    {
        try {
            return hash('sha256', json_encode(
                $this->canonicalize($value),
                JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
            ));
        } catch (JsonException) {
            throw new InvalidArgumentException($error);
        }
    }

    private function canonicalize(mixed $value): mixed
    {
        if (!is_array($value)) {
            return $value;
        }
        if (array_is_list($value)) {
            return array_map(fn (mixed $item): mixed => $this->canonicalize($item), $value);
        }
        ksort($value);
        foreach ($value as $key => $item) {
            $value[$key] = $this->canonicalize($item);
        }

        return $value;
    }
}
