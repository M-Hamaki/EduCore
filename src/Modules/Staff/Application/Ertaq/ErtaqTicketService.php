<?php

declare(strict_types=1);

namespace EduCore\Modules\Staff\Application\Ertaq;

use DateTimeImmutable;
use DateTimeInterface;
use DateTimeZone;
use DomainException;
use EduCore\Modules\Operations\Audit\AuditEventWriter;
use EduCore\Modules\Staff\Contracts\ErtaqTicketAuthorization;
use EduCore\Modules\Staff\Contracts\ErtaqTicketPolicyResolver;
use EduCore\Modules\Staff\Contracts\ErtaqTicketRepository;
use EduCore\Modules\Staff\Contracts\ErtaqSlaScheduleQueue;
use InvalidArgumentException;
use JsonException;

/**
 * Owns the ticket, classification, assignment and ordinary lifecycle half of
 * Ertaq. It has no PDO, session, notification, discipline, Finance, upload,
 * or urgent-protection implementation dependency.
 *
 * Conversation/parties/links/withdrawal, urgent protection routing, SLA
 * dispatch, and attachments are intentionally separate owners so that a
 * normal ticket transition cannot reveal confidential content or trigger an
 * external effect.
 */
final class ErtaqTicketService
{
    /** @var list<string> */
    private const TYPES = ['complaint', 'suggestion', 'inquiry', 'other'];

    /** @var list<string> */
    private const CONFIDENTIALITY_LEVELS = ['normal', 'restricted', 'highly_restricted'];

    /** @var list<string> */
    private const PRIORITIES = ['low', 'normal', 'high', 'urgent'];

    /** @var list<string> */
    private const RISK_LEVELS = ['none', 'low', 'high', 'immediate'];

    /** @var list<string> */
    private const CLASSIFIABLE_STATES = ['new', 'triaged', 'reopened'];

    /** @var list<string> */
    private const ASSIGNABLE_STATES = ['triaged', 'assigned', 'in_progress', 'awaiting_requester', 'reopened'];

    /** @var array<string,list<string>> */
    private const TRANSITIONS = [
        'new' => ['triaged'],
        'assigned' => ['in_progress'],
        'in_progress' => ['awaiting_requester', 'resolved'],
        'awaiting_requester' => ['in_progress', 'resolved'],
        'resolved' => ['closed'],
        'closed' => ['reopened'],
    ];

    public function __construct(
        private ErtaqTicketRepository $repository,
        private ErtaqTicketAuthorization $authorization,
        private ErtaqTicketPolicyResolver $policyResolver,
        private AuditEventWriter $audit,
        private ErtaqSlaScheduleQueue $slaSchedule
    ) {
    }

    /**
     * A worker may only open a ticket for their own identified account. The
     * effective classification, confidentiality and SLA evidence come from
     * the policy boundary, not from the browser.
     *
     * @param array<string,mixed> $command
     * @return array<string,mixed>
     */
    public function createTicket(array $command): array
    {
        $actorId = $this->positiveId($command['actor_id'] ?? null, 'ERTAQ_ACTOR_INVALID');
        $requestedRequester = $this->nullablePositiveId($command['requester_user_id'] ?? null, 'ERTAQ_REQUESTER_INVALID');
        if ($requestedRequester !== null && $requestedRequester !== $actorId) {
            throw new DomainException('ERTAQ_REQUESTER_SELF_SERVICE_ONLY');
        }
        $idempotencyKey = $this->requiredText(
            $command['create_idempotency_key'] ?? null,
            64,
            'ERTAQ_TICKET_IDEMPOTENCY_INVALID'
        );
        $requested = $this->createRequestInput($command, $actorId, $idempotencyKey);
        $now = $this->now();
        $this->authorization->assertCanAct($actorId, 'create_ticket', null, $now);

        return $this->repository->transactional(function () use (
            $actorId,
            $idempotencyKey,
            $requested,
            $now
        ): array {
            if (!$this->repository->lockUser($actorId)) {
                throw new DomainException('ERTAQ_REQUESTER_NOT_FOUND');
            }
            $existing = $this->repository->ticketByCreateIdempotencyForUpdate($idempotencyKey);
            if ($existing !== null) {
                if (hash_equals((string) ($existing['ticket_hash'] ?? ''), $requested['ticket_hash'])) {
                    return $this->ticketReceipt($existing, true);
                }
                throw new DomainException('ERTAQ_TICKET_IDEMPOTENCY_CONFLICT');
            }

            $resolved = $this->policyResolver->resolveForCreate($actorId, $requested, $now);
            $input = $this->ticketInput($requested, $resolved, $now);
            $ticketId = $this->repository->insertTicket($input);
            if ($ticketId <= 0) {
                throw new DomainException('ERTAQ_TICKET_PERSIST_FAILED');
            }

            $stored = $input + [
                'id' => $ticketId,
                'status' => 'new',
                'lock_version' => 1,
            ];
            $this->slaSchedule->scheduleTicketSla($stored, $actorId, $now);
            $this->audit->recordEvent(
                'staff_ertaq_ticket_created',
                'staff_ertaq_tickets',
                $ticketId,
                $input['ticket_no'],
                [
                    'ticket_no' => $input['ticket_no'],
                    'requester_user_id' => $actorId,
                    'type' => $input['type'],
                    'classification' => $input['classification'],
                    'confidentiality_level' => $input['confidentiality_level'],
                    'priority' => $input['priority'],
                    'risk_level' => $input['risk_level'],
                    'ticket_hash' => $input['ticket_hash'],
                    'subject_provided' => true,
                ],
                ['user_id' => $actorId, 'occurred_at' => $this->instant($now)]
            );

            return $this->ticketReceipt($stored, false);
        });
    }

    /**
     * Reclassifies a not-yet-final ticket from a policy-derived result. A
     * stale browser retry is accepted only when the exact next lock version
     * already contains the same safe policy result.
     *
     * @param array<string,mixed> $command
     * @return array<string,mixed>
     */
    public function classifyTicket(array $command): array
    {
        $actorId = $this->positiveId($command['actor_id'] ?? null, 'ERTAQ_ACTOR_INVALID');
        $ticketId = $this->positiveId($command['ticket_id'] ?? null, 'ERTAQ_TICKET_ID_INVALID');
        $expectedLockVersion = $this->positiveId(
            $command['expected_lock_version'] ?? null,
            'ERTAQ_TICKET_LOCK_INVALID'
        );
        $requested = $this->classificationRequestInput($command);
        $now = $this->now();

        return $this->repository->transactional(function () use (
            $actorId,
            $ticketId,
            $expectedLockVersion,
            $requested,
            $now
        ): array {
            $ticket = $this->requiredTicket($ticketId);
            $this->authorization->assertCanAct($actorId, 'classify_ticket', $ticket, $now);
            $resolved = $this->policyResolver->resolveForClassification($actorId, $ticket, $requested, $now);
            $changes = $this->classificationChanges($resolved);
            $currentStatus = (string) ($ticket['status'] ?? '');

            if ((int) ($ticket['lock_version'] ?? 0) !== $expectedLockVersion) {
                if ((int) ($ticket['lock_version'] ?? 0) === $expectedLockVersion + 1
                    && $this->matchesChanges($ticket, $changes)) {
                    return $this->ticketReceipt($ticket, true);
                }
                throw new DomainException('ERTAQ_TICKET_STALE');
            }
            if (!in_array($currentStatus, self::CLASSIFIABLE_STATES, true)) {
                throw new DomainException('ERTAQ_TICKET_CLASSIFICATION_FORBIDDEN');
            }
            if (!$this->repository->transitionTicket(
                $ticketId,
                $expectedLockVersion,
                $currentStatus,
                $currentStatus,
                $changes
            )) {
                throw new DomainException('ERTAQ_TICKET_STALE');
            }

            $after = array_replace($ticket, $changes, [
                'lock_version' => $expectedLockVersion + 1,
            ]);
            $this->slaSchedule->scheduleTicketSla($after, $actorId, $now);
            $this->audit->recordEvent(
                'staff_ertaq_ticket_classified',
                'staff_ertaq_tickets',
                $ticketId,
                (string) ($ticket['ticket_no'] ?? ''),
                [
                    'previous_classification' => (string) ($ticket['classification'] ?? ''),
                    'classification' => (string) $changes['classification'],
                    'confidentiality_level' => (string) $changes['confidentiality_level'],
                    'priority' => (string) $changes['priority'],
                    'risk_level' => (string) $changes['risk_level'],
                    'sla_policy_id' => $changes['sla_policy_id'],
                ],
                ['user_id' => $actorId, 'occurred_at' => $this->instant($now)]
            );

            return $this->ticketReceipt($after, false);
        });
    }

    /**
     * Creates a new assignment record and closes the previous active
     * assignment as a successor event. The actor's live scope and the target
     * team/user are checked by the authorization boundary.
     *
     * @param array<string,mixed> $command
     * @return array<string,mixed>
     */
    public function assignTicket(array $command): array
    {
        $actorId = $this->positiveId($command['actor_id'] ?? null, 'ERTAQ_ACTOR_INVALID');
        $ticketId = $this->positiveId($command['ticket_id'] ?? null, 'ERTAQ_TICKET_ID_INVALID');
        $expectedLockVersion = $this->positiveId(
            $command['expected_lock_version'] ?? null,
            'ERTAQ_TICKET_LOCK_INVALID'
        );
        $idempotencyKey = $this->requiredText(
            $command['idempotency_key'] ?? null,
            64,
            'ERTAQ_ASSIGNMENT_IDEMPOTENCY_INVALID'
        );
        $assignedTeamId = $this->nullablePositiveId($command['assigned_team_id'] ?? null, 'ERTAQ_ASSIGNMENT_TEAM_INVALID');
        $assignedToUserId = $this->nullablePositiveId($command['assigned_to_user_id'] ?? null, 'ERTAQ_ASSIGNMENT_USER_INVALID');
        if ($assignedTeamId === null && $assignedToUserId === null) {
            throw new InvalidArgumentException('ERTAQ_ASSIGNMENT_TARGET_REQUIRED');
        }
        $reason = $this->nullableText(
            $command['assignment_reason'] ?? null,
            4000,
            'ERTAQ_ASSIGNMENT_REASON_INVALID'
        );
        $assignmentHash = $this->hash([
            'ticket_id' => $ticketId,
            'assigned_team_id' => $assignedTeamId,
            'assigned_to_user_id' => $assignedToUserId,
            'assigned_by_user_id' => $actorId,
            'assignment_reason' => $reason,
        ]);
        $now = $this->now();

        return $this->repository->transactional(function () use (
            $actorId,
            $ticketId,
            $expectedLockVersion,
            $idempotencyKey,
            $assignedTeamId,
            $assignedToUserId,
            $reason,
            $assignmentHash,
            $now
        ): array {
            $ticket = $this->requiredTicket($ticketId);
            $this->authorization->assertCanAssign(
                $actorId,
                $ticket,
                $assignedTeamId,
                $assignedToUserId,
                $now
            );
            $existing = $this->repository->assignmentByIdempotencyForUpdate($idempotencyKey);
            if ($existing !== null) {
                if (hash_equals((string) ($existing['assignment_hash'] ?? ''), $assignmentHash)) {
                    return [
                        'ticket' => $this->ticketReceipt($ticket, true),
                        'assignment' => $this->assignmentReceipt($existing, true),
                    ];
                }
                throw new DomainException('ERTAQ_ASSIGNMENT_IDEMPOTENCY_CONFLICT');
            }
            if ((int) ($ticket['lock_version'] ?? 0) !== $expectedLockVersion) {
                throw new DomainException('ERTAQ_TICKET_STALE');
            }
            $fromStatus = (string) ($ticket['status'] ?? '');
            if (!in_array($fromStatus, self::ASSIGNABLE_STATES, true)) {
                throw new DomainException('ERTAQ_TICKET_ASSIGNMENT_FORBIDDEN');
            }
            if ($assignedToUserId !== null && !$this->repository->lockUser($assignedToUserId)) {
                throw new DomainException('ERTAQ_ASSIGNMENT_USER_NOT_FOUND');
            }

            $previous = $this->repository->activeAssignmentForTicketForUpdate($ticketId);
            if ($previous !== null
                && (int) ($previous['assigned_team_id'] ?? 0) === (int) ($assignedTeamId ?? 0)
                && (int) ($previous['assigned_to_user_id'] ?? 0) === (int) ($assignedToUserId ?? 0)) {
                throw new DomainException('ERTAQ_TICKET_ALREADY_ASSIGNED');
            }
            $input = [
                'ticket_id' => $ticketId,
                'assigned_team_id' => $assignedTeamId,
                'assigned_to_user_id' => $assignedToUserId,
                'assigned_by_user_id' => $actorId,
                'assignment_reason' => $reason,
                'assigned_at' => $this->instant($now),
                'supersedes_assignment_id' => $previous === null
                    ? null
                    : $this->positiveId($previous['id'] ?? null, 'ERTAQ_ASSIGNMENT_ID_INVALID'),
                'idempotency_key' => $idempotencyKey,
                'assignment_hash' => $assignmentHash,
            ];
            $assignmentId = $this->repository->insertAssignment($input);
            if ($assignmentId <= 0) {
                throw new DomainException('ERTAQ_ASSIGNMENT_PERSIST_FAILED');
            }
            if ($previous !== null) {
                $previousId = $this->positiveId($previous['id'] ?? null, 'ERTAQ_ASSIGNMENT_ID_INVALID');
                $previousLock = $this->positiveId(
                    $previous['lock_version'] ?? null,
                    'ERTAQ_ASSIGNMENT_LOCK_INVALID'
                );
                if (!$this->repository->supersedeAssignment(
                    $previousId,
                    $previousLock,
                    $actorId,
                    $this->instant($now),
                    $reason ?? 'Superseded by a new authorized Ertaq assignment.'
                )) {
                    throw new DomainException('ERTAQ_ASSIGNMENT_STALE');
                }
            }
            if (!$this->repository->transitionTicket(
                $ticketId,
                $expectedLockVersion,
                $fromStatus,
                'assigned',
                []
            )) {
                throw new DomainException('ERTAQ_TICKET_STALE');
            }

            $afterTicket = array_replace($ticket, [
                'status' => 'assigned',
                'lock_version' => $expectedLockVersion + 1,
            ]);
            $stored = $input + [
                'id' => $assignmentId,
                'status' => 'active',
                'lock_version' => 1,
            ];
            if ($previous !== null) {
                $this->audit->recordEvent(
                    'staff_ertaq_assignment_superseded',
                    'staff_ertaq_assignments',
                    (int) $previous['id'],
                    null,
                    [
                        'ticket_id' => $ticketId,
                        'successor_assignment_id' => $assignmentId,
                        'reason_provided' => $reason !== null,
                    ],
                    ['user_id' => $actorId, 'occurred_at' => $this->instant($now)]
                );
            }
            $this->audit->recordEvent(
                'staff_ertaq_ticket_assigned',
                'staff_ertaq_assignments',
                $assignmentId,
                null,
                [
                    'ticket_id' => $ticketId,
                    'assigned_team_id' => $assignedTeamId,
                    'assigned_to_user_id' => $assignedToUserId,
                    'supersedes_assignment_id' => $input['supersedes_assignment_id'],
                    'assignment_hash' => $assignmentHash,
                    'reason_provided' => $reason !== null,
                ],
                ['user_id' => $actorId, 'occurred_at' => $this->instant($now)]
            );
            $this->audit->recordEvent(
                'staff_ertaq_ticket_assignment_state',
                'staff_ertaq_tickets',
                $ticketId,
                (string) ($ticket['ticket_no'] ?? ''),
                ['previous_status' => $fromStatus, 'status' => 'assigned', 'assignment_id' => $assignmentId],
                ['user_id' => $actorId, 'occurred_at' => $this->instant($now)]
            );

            return [
                'ticket' => $this->ticketReceipt($afterTicket, false),
                'assignment' => $this->assignmentReceipt($stored, false),
            ];
        });
    }

    /**
     * Transitions only the ordinary non-urgent state machine. Urgent
     * protection and withdrawal each have separate owners and cannot be
     * reached by a generic browser status value.
     *
     * @param array<string,mixed> $command
     * @return array<string,mixed>
     */
    public function transitionTicket(array $command): array
    {
        $actorId = $this->positiveId($command['actor_id'] ?? null, 'ERTAQ_ACTOR_INVALID');
        $ticketId = $this->positiveId($command['ticket_id'] ?? null, 'ERTAQ_TICKET_ID_INVALID');
        $expectedLockVersion = $this->positiveId(
            $command['expected_lock_version'] ?? null,
            'ERTAQ_TICKET_LOCK_INVALID'
        );
        $toStatus = $this->requiredText($command['to_status'] ?? null, 40, 'ERTAQ_TICKET_STATUS_INVALID');
        $now = $this->now();

        return $this->repository->transactional(function () use (
            $command,
            $actorId,
            $ticketId,
            $expectedLockVersion,
            $toStatus,
            $now
        ): array {
            $ticket = $this->requiredTicket($ticketId);
            $this->authorization->assertCanAct($actorId, 'transition_ticket_' . $toStatus, $ticket, $now);
            $fromStatus = (string) ($ticket['status'] ?? '');
            $changes = $this->transitionChanges($toStatus, $command, $actorId, $now);
            if ((int) ($ticket['lock_version'] ?? 0) !== $expectedLockVersion) {
                if ((int) ($ticket['lock_version'] ?? 0) === $expectedLockVersion + 1
                    && $fromStatus === $toStatus
                    && $this->matchesChanges($ticket, $changes)) {
                    return $this->ticketReceipt($ticket, true);
                }
                throw new DomainException('ERTAQ_TICKET_STALE');
            }
            if (!in_array($toStatus, self::TRANSITIONS[$fromStatus] ?? [], true)) {
                throw new DomainException('ERTAQ_TICKET_TRANSITION_FORBIDDEN');
            }
            if (!$this->repository->transitionTicket(
                $ticketId,
                $expectedLockVersion,
                $fromStatus,
                $toStatus,
                $changes
            )) {
                throw new DomainException('ERTAQ_TICKET_STALE');
            }
            $after = array_replace($ticket, $changes, [
                'status' => $toStatus,
                'lock_version' => $expectedLockVersion + 1,
            ]);
            if ($toStatus === 'reopened') {
                $this->slaSchedule->scheduleTicketSla($after, $actorId, $now);
            }
            $this->audit->recordEvent(
                'staff_ertaq_ticket_state_changed',
                'staff_ertaq_tickets',
                $ticketId,
                (string) ($ticket['ticket_no'] ?? ''),
                [
                    'previous_status' => $fromStatus,
                    'status' => $toStatus,
                    'resolution_provided' => array_key_exists('resolution_summary', $changes),
                    'closure_reason_provided' => array_key_exists('closure_reason', $changes),
                    'reopen_reason_provided' => array_key_exists('reopen_reason', $changes),
                ],
                ['user_id' => $actorId, 'occurred_at' => $this->instant($now)]
            );

            return $this->ticketReceipt($after, false);
        });
    }

    /** @param array<string,mixed> $command @return array<string,mixed> */
    private function createRequestInput(array $command, int $actorId, string $idempotencyKey): array
    {
        $type = $this->enum($command['type'] ?? null, self::TYPES, 'ERTAQ_TICKET_TYPE_INVALID');
        $classification = $this->requiredText(
            $command['classification'] ?? 'general',
            100,
            'ERTAQ_TICKET_CLASSIFICATION_INVALID'
        );
        $confidentiality = $this->enum(
            $command['confidentiality_level'] ?? 'restricted',
            self::CONFIDENTIALITY_LEVELS,
            'ERTAQ_TICKET_CONFIDENTIALITY_INVALID'
        );
        $priority = $this->enum(
            $command['priority'] ?? 'normal',
            self::PRIORITIES,
            'ERTAQ_TICKET_PRIORITY_INVALID'
        );
        $riskLevel = $this->enum(
            $command['risk_level'] ?? 'none',
            self::RISK_LEVELS,
            'ERTAQ_TICKET_RISK_INVALID'
        );
        $subject = $this->requiredText($command['subject'] ?? null, 500, 'ERTAQ_TICKET_SUBJECT_REQUIRED');
        $ticketNo = $this->nullableText($command['ticket_no'] ?? null, 80, 'ERTAQ_TICKET_NO_INVALID')
            ?? $this->number('ERT', $idempotencyKey);
        $ticketHash = $this->hash([
            'ticket_no' => $ticketNo,
            'requester_user_id' => $actorId,
            'type' => $type,
            'classification' => $classification,
            'confidentiality_level' => $confidentiality,
            'priority' => $priority,
            'risk_level' => $riskLevel,
            'subject' => $subject,
        ]);

        return [
            'ticket_no' => $ticketNo,
            'requester_user_id' => $actorId,
            'type' => $type,
            'requested_classification' => $classification,
            'requested_confidentiality_level' => $confidentiality,
            'requested_priority' => $priority,
            'requested_risk_level' => $riskLevel,
            'subject' => $subject,
            'create_idempotency_key' => $idempotencyKey,
            'ticket_hash' => $ticketHash,
        ];
    }

    /** @param array<string,mixed> $command @return array<string,mixed> */
    private function classificationRequestInput(array $command): array
    {
        return [
            'classification' => $this->requiredText(
                $command['classification'] ?? null,
                100,
                'ERTAQ_TICKET_CLASSIFICATION_INVALID'
            ),
            'confidentiality_level' => $this->enum(
                $command['confidentiality_level'] ?? null,
                self::CONFIDENTIALITY_LEVELS,
                'ERTAQ_TICKET_CONFIDENTIALITY_INVALID'
            ),
            'priority' => $this->enum(
                $command['priority'] ?? null,
                self::PRIORITIES,
                'ERTAQ_TICKET_PRIORITY_INVALID'
            ),
            'risk_level' => $this->enum(
                $command['risk_level'] ?? null,
                self::RISK_LEVELS,
                'ERTAQ_TICKET_RISK_INVALID'
            ),
        ];
    }

    /**
     * @param array<string,mixed> $requested
     * @param array<string,mixed> $resolved
     * @return array<string,mixed>
     */
    private function ticketInput(array $requested, array $resolved, DateTimeImmutable $now): array
    {
        return [
            'ticket_no' => $requested['ticket_no'],
            'requester_user_id' => $requested['requester_user_id'],
            'type' => $requested['type'],
            'subject' => $requested['subject'],
            'create_idempotency_key' => $requested['create_idempotency_key'],
            'ticket_hash' => $requested['ticket_hash'],
        ] + $this->classificationChanges($resolved) + [
            'created_at' => $this->instant($now),
        ];
    }

    /**
     * @param array<string,mixed> $resolved
     * @return array<string,mixed>
     */
    private function classificationChanges(array $resolved): array
    {
        $classification = $this->requiredText(
            $resolved['classification'] ?? null,
            100,
            'ERTAQ_POLICY_CLASSIFICATION_INVALID'
        );
        $confidentiality = $this->enum(
            $resolved['confidentiality_level'] ?? null,
            self::CONFIDENTIALITY_LEVELS,
            'ERTAQ_POLICY_CONFIDENTIALITY_INVALID'
        );
        $priority = $this->enum(
            $resolved['priority'] ?? null,
            self::PRIORITIES,
            'ERTAQ_POLICY_PRIORITY_INVALID'
        );
        $riskLevel = $this->enum(
            $resolved['risk_level'] ?? null,
            self::RISK_LEVELS,
            'ERTAQ_POLICY_RISK_INVALID'
        );
        if ($riskLevel === 'immediate' && $priority !== 'urgent') {
            throw new DomainException('ERTAQ_POLICY_URGENT_PRIORITY_REQUIRED');
        }
        $slaPolicyId = $this->nullablePositiveId(
            $resolved['sla_policy_id'] ?? null,
            'ERTAQ_POLICY_SLA_ID_INVALID'
        );
        $snapshot = $resolved['sla_policy_snapshot'] ?? null;
        if ($snapshot !== null && !is_array($snapshot)) {
            throw new InvalidArgumentException('ERTAQ_POLICY_SLA_SNAPSHOT_INVALID');
        }
        if ($slaPolicyId !== null && $snapshot === null) {
            throw new DomainException('ERTAQ_POLICY_SLA_SNAPSHOT_REQUIRED');
        }
        $firstResponseDueAt = $this->nullableInstant(
            $resolved['first_response_due_at'] ?? null,
            'ERTAQ_POLICY_FIRST_RESPONSE_DUE_INVALID'
        );
        $slaDueAt = $this->nullableInstant(
            $resolved['sla_due_at'] ?? null,
            'ERTAQ_POLICY_DUE_INVALID'
        );
        if ($firstResponseDueAt !== null && $slaDueAt !== null && $firstResponseDueAt > $slaDueAt) {
            throw new DomainException('ERTAQ_POLICY_SLA_WINDOW_INVALID');
        }

        return [
            'classification' => $classification,
            'confidentiality_level' => $confidentiality,
            'priority' => $priority,
            'risk_level' => $riskLevel,
            'sla_policy_id' => $slaPolicyId,
            'sla_policy_snapshot' => $snapshot,
            'first_response_due_at' => $firstResponseDueAt,
            'sla_due_at' => $slaDueAt,
        ];
    }

    /** @param array<string,mixed> $command @return array<string,mixed> */
    private function transitionChanges(
        string $toStatus,
        array $command,
        int $actorId,
        DateTimeImmutable $now
    ): array {
        return match ($toStatus) {
            'resolved' => [
                'resolution_summary' => $this->requiredText(
                    $command['resolution_summary'] ?? null,
                    10000,
                    'ERTAQ_TICKET_RESOLUTION_REQUIRED'
                ),
                'resolved_at' => $this->instant($now),
                'resolved_by_user_id' => $actorId,
            ],
            'closed' => [
                'closure_reason' => $this->requiredText(
                    $command['closure_reason'] ?? null,
                    4000,
                    'ERTAQ_TICKET_CLOSURE_REASON_REQUIRED'
                ),
                'closed_at' => $this->instant($now),
                'closed_by_user_id' => $actorId,
            ],
            'reopened' => [
                'reopen_reason' => $this->requiredText(
                    $command['reopen_reason'] ?? null,
                    4000,
                    'ERTAQ_TICKET_REOPEN_REASON_REQUIRED'
                ),
                'reopened_at' => $this->instant($now),
                'reopened_by_user_id' => $actorId,
            ],
            default => [],
        };
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

    /** @param array<string,mixed> $ticket @return array<string,mixed> */
    private function ticketReceipt(array $ticket, bool $replayed): array
    {
        return [
            'ticket_id' => $this->positiveId($ticket['id'] ?? null, 'ERTAQ_TICKET_PERSIST_FAILED'),
            'ticket_no' => (string) ($ticket['ticket_no'] ?? ''),
            'requester_user_id' => (int) ($ticket['requester_user_id'] ?? 0),
            'type' => (string) ($ticket['type'] ?? ''),
            'classification' => (string) ($ticket['classification'] ?? ''),
            'confidentiality_level' => (string) ($ticket['confidentiality_level'] ?? ''),
            'priority' => (string) ($ticket['priority'] ?? ''),
            'risk_level' => (string) ($ticket['risk_level'] ?? ''),
            'status' => (string) ($ticket['status'] ?? ''),
            'lock_version' => (int) ($ticket['lock_version'] ?? 0),
            'replayed' => $replayed,
        ];
    }

    /** @param array<string,mixed> $assignment @return array<string,mixed> */
    private function assignmentReceipt(array $assignment, bool $replayed): array
    {
        return [
            'assignment_id' => $this->positiveId($assignment['id'] ?? null, 'ERTAQ_ASSIGNMENT_PERSIST_FAILED'),
            'ticket_id' => (int) ($assignment['ticket_id'] ?? 0),
            'assigned_team_id' => $this->nullablePositiveId(
                $assignment['assigned_team_id'] ?? null,
                'ERTAQ_ASSIGNMENT_TEAM_INVALID'
            ),
            'assigned_to_user_id' => $this->nullablePositiveId(
                $assignment['assigned_to_user_id'] ?? null,
                'ERTAQ_ASSIGNMENT_USER_INVALID'
            ),
            'status' => (string) ($assignment['status'] ?? ''),
            'lock_version' => (int) ($assignment['lock_version'] ?? 0),
            'replayed' => $replayed,
        ];
    }

    /** @param array<string,mixed> $current @param array<string,mixed> $changes */
    private function matchesChanges(array $current, array $changes): bool
    {
        foreach ($changes as $key => $value) {
            if (($current[$key] ?? null) !== $value) {
                return false;
            }
        }

        return true;
    }

    private function positiveId(mixed $value, string $error): int
    {
        $id = filter_var($value, FILTER_VALIDATE_INT);
        if ($id === false || $id <= 0) {
            throw new InvalidArgumentException($error);
        }

        return (int) $id;
    }

    private function nullablePositiveId(mixed $value, string $error): ?int
    {
        if ($value === null || $value === '') {
            return null;
        }

        return $this->positiveId($value, $error);
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

    private function nullableText(mixed $value, int $maxBytes, string $error): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }

        return $this->requiredText($value, $maxBytes, $error);
    }

    /** @param list<string> $allowed */
    private function enum(mixed $value, array $allowed, string $error): string
    {
        if (!is_string($value) || !in_array($value, $allowed, true)) {
            throw new InvalidArgumentException($error);
        }

        return $value;
    }

    private function nullableInstant(mixed $value, string $error): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }
        if (!is_string($value)) {
            throw new InvalidArgumentException($error);
        }
        try {
            return $this->instant(new DateTimeImmutable($value));
        } catch (\Throwable) {
            throw new InvalidArgumentException($error);
        }
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

    private function number(string $prefix, string $idempotencyKey): string
    {
        return $prefix . '-' . strtoupper(substr(hash('sha256', $idempotencyKey), 0, 16));
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
            throw new InvalidArgumentException('ERTAQ_COMMAND_SERIALIZATION_INVALID');
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
