<?php

declare(strict_types=1);

namespace EduCore\Modules\Staff\Application\Ertaq;

use DateTimeImmutable;
use DateTimeInterface;
use DateTimeZone;
use DomainException;
use EduCore\Modules\Operations\Audit\AuditEventWriter;
use EduCore\Modules\Staff\Contracts\ErtaqUrgentRoutingAuthorization;
use EduCore\Modules\Staff\Contracts\ErtaqUrgentRoutingRepository;
use InvalidArgumentException;
use JsonException;

/**
 * Owns the urgent-protection route for Ertaq only.
 *
 * It records a protected routing intent and acknowledgement, but deliberately
 * does not send a push, open a discipline case, apply access control, or
 * create an external/financial effect. Those effects require their own
 * authorized owners after the confidential route is established.
 */
final class ErtaqUrgentRoutingService
{
    /** @var list<string> */
    private const ROUTEABLE_STATES = [
        'new', 'triaged', 'assigned', 'in_progress', 'awaiting_requester',
        'resolved', 'reopened',
    ];

    public function __construct(
        private ErtaqUrgentRoutingRepository $repository,
        private ErtaqUrgentRoutingAuthorization $authorization,
        private AuditEventWriter $audit
    ) {
    }

    /**
     * @param array<string,mixed> $command
     * @return array<string,mixed>
     */
    public function routeUrgentTicket(array $command): array
    {
        $actorId = $this->positiveId($command['actor_id'] ?? null, 'ERTAQ_ACTOR_INVALID');
        $ticketId = $this->positiveId($command['ticket_id'] ?? null, 'ERTAQ_TICKET_ID_INVALID');
        $expectedLockVersion = $this->positiveId(
            $command['expected_lock_version'] ?? null,
            'ERTAQ_TICKET_LOCK_INVALID'
        );
        $riskType = $this->requiredText($command['risk_type'] ?? null, 100, 'ERTAQ_URGENT_RISK_TYPE_REQUIRED');
        $idempotencyKey = $this->requiredText(
            $command['idempotency_key'] ?? null,
            64,
            'ERTAQ_URGENT_IDEMPOTENCY_INVALID'
        );
        $now = $this->now();

        return $this->repository->transactional(function () use (
            $actorId,
            $ticketId,
            $expectedLockVersion,
            $riskType,
            $idempotencyKey,
            $now
        ): array {
            $ticket = $this->requiredTicket($ticketId);
            $this->authorization->assertCanAct($actorId, 'route_urgent_ticket', $ticket, $now);
            $existing = $this->repository->urgentByIdempotencyForUpdate($idempotencyKey);
            if ($existing !== null) {
                if ($this->routeRequestMatches($existing, $ticketId, $actorId, $riskType)) {
                    return $this->urgentReceipt($existing, true);
                }
                throw new DomainException('ERTAQ_URGENT_IDEMPOTENCY_CONFLICT');
            }
            if ((int) ($ticket['lock_version'] ?? 0) !== $expectedLockVersion) {
                throw new DomainException('ERTAQ_TICKET_STALE');
            }
            $fromStatus = (string) ($ticket['status'] ?? '');
            if (!in_array($fromStatus, self::ROUTEABLE_STATES, true)) {
                throw new DomainException('ERTAQ_URGENT_ROUTE_FORBIDDEN');
            }
            if ($this->repository->urgentForTicketForUpdate($ticketId) !== null) {
                throw new DomainException('ERTAQ_URGENT_ALREADY_ROUTED');
            }

            $excludedUserIds = $this->uniquePositiveIds(
                $this->repository->protectedPartyUserIdsForTicketForUpdate($ticketId),
                'ERTAQ_URGENT_EXCLUSION_INVALID'
            );
            $route = $this->authorization->resolveProtectionRoute(
                $actorId,
                $ticket,
                $riskType,
                $excludedUserIds,
                $now
            );
            $routedTeamId = $this->positiveId(
                $route['routed_team_id'] ?? null,
                'ERTAQ_URGENT_TEAM_INVALID'
            );
            $routeSnapshot = $route['route_snapshot'] ?? null;
            if (!is_array($routeSnapshot)) {
                throw new InvalidArgumentException('ERTAQ_URGENT_ROUTE_SNAPSHOT_INVALID');
            }
            $recipientUserIds = $this->uniquePositiveIds(
                $route['eligible_user_ids'] ?? [],
                'ERTAQ_URGENT_RECIPIENT_INVALID'
            );
            if (array_intersect($recipientUserIds, $excludedUserIds) !== []) {
                throw new DomainException('ERTAQ_URGENT_CONFLICT_RECIPIENT');
            }
            $exclusionSnapshot = [
                'excluded_user_ids' => $excludedUserIds,
                'excluded_count' => count($excludedUserIds),
            ];
            $urgentHash = $this->hash([
                'ticket_id' => $ticketId,
                'risk_type' => $riskType,
                'routed_team_id' => $routedTeamId,
                'route_snapshot' => $routeSnapshot,
                'conflict_exclusion_snapshot' => $exclusionSnapshot,
                'routed_by_user_id' => $actorId,
            ]);
            $input = [
                'ticket_id' => $ticketId,
                'risk_type' => $riskType,
                'routed_team_id' => $routedTeamId,
                'routed_by_user_id' => $actorId,
                'route_snapshot' => $routeSnapshot,
                'conflict_exclusion_snapshot' => $exclusionSnapshot,
                'idempotency_key' => $idempotencyKey,
                'urgent_hash' => $urgentHash,
                'routed_at' => $this->instant($now),
            ];
            $urgentEventId = $this->repository->insertUrgentEvent($input);
            if ($urgentEventId <= 0) {
                throw new DomainException('ERTAQ_URGENT_PERSIST_FAILED');
            }
            if (!$this->repository->transitionTicketToUrgent(
                $ticketId,
                $expectedLockVersion,
                $fromStatus
            )) {
                throw new DomainException('ERTAQ_TICKET_STALE');
            }
            $stored = $input + [
                'id' => $urgentEventId,
                'status' => 'routed',
                'lock_version' => 1,
            ];
            $this->audit->recordEvent(
                'staff_ertaq_urgent_route_created',
                'staff_ertaq_urgent_events',
                $urgentEventId,
                null,
                [
                    'ticket_id' => $ticketId,
                    'risk_type' => $riskType,
                    'routed_team_id' => $routedTeamId,
                    'excluded_user_count' => count($excludedUserIds),
                    'recipient_count' => count($recipientUserIds),
                    'urgent_hash' => $urgentHash,
                ],
                ['user_id' => $actorId, 'occurred_at' => $this->instant($now)]
            );
            $this->audit->recordEvent(
                'staff_ertaq_ticket_urgent_protected',
                'staff_ertaq_tickets',
                $ticketId,
                (string) ($ticket['ticket_no'] ?? ''),
                [
                    'previous_status' => $fromStatus,
                    'status' => 'urgent_protected',
                    'urgent_event_id' => $urgentEventId,
                    'routed_team_id' => $routedTeamId,
                ],
                ['user_id' => $actorId, 'occurred_at' => $this->instant($now)]
            );

            return $this->urgentReceipt($stored, false);
        });
    }

    /**
     * @param array<string,mixed> $command
     * @return array<string,mixed>
     */
    public function acknowledgeUrgentRoute(array $command): array
    {
        $actorId = $this->positiveId($command['actor_id'] ?? null, 'ERTAQ_ACTOR_INVALID');
        $urgentEventId = $this->positiveId(
            $command['urgent_event_id'] ?? null,
            'ERTAQ_URGENT_EVENT_ID_INVALID'
        );
        $expectedLockVersion = $this->positiveId(
            $command['expected_lock_version'] ?? null,
            'ERTAQ_URGENT_LOCK_INVALID'
        );
        $now = $this->now();

        return $this->repository->transactional(function () use (
            $actorId,
            $urgentEventId,
            $expectedLockVersion,
            $now
        ): array {
            $event = $this->repository->urgentEventForUpdate($urgentEventId);
            if ($event === null) {
                throw new DomainException('ERTAQ_URGENT_EVENT_NOT_FOUND');
            }
            $ticket = $this->requiredTicket(
                $this->positiveId($event['ticket_id'] ?? null, 'ERTAQ_TICKET_ID_INVALID')
            );
            $this->authorization->assertCanAcknowledge($actorId, $ticket, $event, $now);
            if ((string) ($event['status'] ?? '') === 'acknowledged'
                && (int) ($event['lock_version'] ?? 0) === $expectedLockVersion + 1
                && (int) ($event['acknowledged_by_user_id'] ?? 0) === $actorId) {
                return $this->urgentReceipt($event, true);
            }
            if ((string) ($event['status'] ?? '') !== 'routed'
                || (int) ($event['lock_version'] ?? 0) !== $expectedLockVersion) {
                throw new DomainException('ERTAQ_URGENT_EVENT_STALE');
            }
            if (!$this->repository->acknowledgeUrgentEvent(
                $urgentEventId,
                $expectedLockVersion,
                $actorId,
                $this->instant($now)
            )) {
                throw new DomainException('ERTAQ_URGENT_EVENT_STALE');
            }
            $after = array_replace($event, [
                'status' => 'acknowledged',
                'acknowledged_by_user_id' => $actorId,
                'acknowledged_at' => $this->instant($now),
                'lock_version' => $expectedLockVersion + 1,
            ]);
            $this->audit->recordEvent(
                'staff_ertaq_urgent_route_acknowledged',
                'staff_ertaq_urgent_events',
                $urgentEventId,
                null,
                [
                    'ticket_id' => (int) $event['ticket_id'],
                    'routed_team_id' => (int) $event['routed_team_id'],
                    'risk_type' => (string) $event['risk_type'],
                ],
                ['user_id' => $actorId, 'occurred_at' => $this->instant($now)]
            );

            return $this->urgentReceipt($after, false);
        });
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

    /** @param array<string,mixed> $event */
    private function routeRequestMatches(array $event, int $ticketId, int $actorId, string $riskType): bool
    {
        return (int) ($event['ticket_id'] ?? 0) === $ticketId
            && (int) ($event['routed_by_user_id'] ?? 0) === $actorId
            && (string) ($event['risk_type'] ?? '') === $riskType;
    }

    /** @param array<string,mixed> $event @return array<string,mixed> */
    private function urgentReceipt(array $event, bool $replayed): array
    {
        return [
            'urgent_event_id' => $this->positiveId($event['id'] ?? null, 'ERTAQ_URGENT_PERSIST_FAILED'),
            'ticket_id' => (int) ($event['ticket_id'] ?? 0),
            'risk_type' => (string) ($event['risk_type'] ?? ''),
            'routed_team_id' => (int) ($event['routed_team_id'] ?? 0),
            'status' => (string) ($event['status'] ?? ''),
            'acknowledged' => ($event['acknowledged_at'] ?? null) !== null,
            'lock_version' => (int) ($event['lock_version'] ?? 0),
            'replayed' => $replayed,
        ];
    }

    /** @return list<int> */
    private function uniquePositiveIds(mixed $values, string $error): array
    {
        if (!is_array($values)) {
            throw new InvalidArgumentException($error);
        }
        $ids = [];
        foreach ($values as $value) {
            $ids[] = $this->positiveId($value, $error);
        }

        return array_values(array_unique($ids));
    }

    private function positiveId(mixed $value, string $error): int
    {
        $id = filter_var($value, FILTER_VALIDATE_INT);
        if ($id === false || $id <= 0) {
            throw new InvalidArgumentException($error);
        }

        return (int) $id;
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
    private function hash(array $value): string
    {
        try {
            return hash('sha256', json_encode(
                $this->canonicalize($value),
                JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
            ));
        } catch (JsonException) {
            throw new InvalidArgumentException('ERTAQ_URGENT_SERIALIZATION_INVALID');
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
