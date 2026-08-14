<?php

declare(strict_types=1);

namespace EduCore\Modules\Staff\Application\Ertaq;

use DateTimeImmutable;
use DateTimeInterface;
use DateTimeZone;
use DomainException;
use EduCore\Modules\Operations\Audit\AuditEventWriter;
use EduCore\Modules\Staff\Contracts\ErtaqConversationAuthorization;
use EduCore\Modules\Staff\Contracts\ErtaqConversationRepository;
use InvalidArgumentException;
use JsonException;

/**
 * Owns Ertaq conversation evidence, participants, links, and withdrawal
 * requests. It never converts a linked complaint to a discipline case, sends
 * a notification, or creates an urgent route; those are separately
 * authorized side effects.
 */
final class ErtaqConversationService
{
    /** @var list<string> */
    private const MESSAGE_TYPES = [
        'requester_message', 'team_reply', 'internal_note', 'system_event',
        'withdrawal_request', 'status_update',
    ];

    /** @var list<string> */
    private const VISIBILITY_SCOPES = [
        'requester', 'assigned_team', 'restricted', 'protection_team',
    ];

    /** @var list<string> */
    private const PARTY_ROLES = [
        'requester', 'complainant', 'accused', 'affected', 'witness',
        'representative', 'recipient', 'observer', 'other',
    ];

    /** @var list<string> */
    private const LINK_TYPES = [
        'collective', 'duplicate_of', 'related', 'discipline_case',
        'improvement_initiative', 'external_reference',
    ];

    /** @var list<string> */
    private const MESSAGEABLE_STATES = [
        'new', 'triaged', 'assigned', 'in_progress', 'awaiting_requester',
        'resolved', 'reopened', 'withdrawal_requested', 'urgent_protected',
    ];

    /** @var list<string> */
    private const PARTY_LINKABLE_STATES = [
        'new', 'triaged', 'assigned', 'in_progress', 'awaiting_requester',
        'resolved', 'reopened', 'withdrawal_requested', 'urgent_protected',
    ];

    /** @var list<string> */
    private const WITHDRAWAL_REQUESTABLE_STATES = [
        'triaged', 'assigned', 'in_progress', 'awaiting_requester', 'resolved',
        'urgent_protected',
    ];

    /** @var list<string> */
    private const WITHDRAWAL_OUTCOMES = [
        'withdrawn', 'continue_processing', 'rejected',
    ];

    public function __construct(
        private ErtaqConversationRepository $repository,
        private ErtaqConversationAuthorization $authorization,
        private AuditEventWriter $audit
    ) {
    }

    /**
     * @param array<string,mixed> $command
     * @return array<string,mixed>
     */
    public function postMessage(array $command): array
    {
        $actorId = $this->positiveId($command['actor_id'] ?? null, 'ERTAQ_ACTOR_INVALID');
        $ticketId = $this->positiveId($command['ticket_id'] ?? null, 'ERTAQ_TICKET_ID_INVALID');
        $idempotencyKey = $this->requiredText(
            $command['idempotency_key'] ?? null,
            64,
            'ERTAQ_MESSAGE_IDEMPOTENCY_INVALID'
        );
        $messageType = $this->enum(
            $command['message_type'] ?? null,
            self::MESSAGE_TYPES,
            'ERTAQ_MESSAGE_TYPE_INVALID'
        );
        $body = $this->requiredText($command['body'] ?? null, 50000, 'ERTAQ_MESSAGE_BODY_REQUIRED');
        $replyToMessageId = $this->nullablePositiveId(
            $command['reply_to_message_id'] ?? null,
            'ERTAQ_MESSAGE_REPLY_INVALID'
        );
        $requestedVisibility = $this->nullableVisibility($command['visibility'] ?? null);
        $bodyHash = hash('sha256', $body);
        $now = $this->now();

        return $this->repository->transactional(function () use (
            $actorId,
            $ticketId,
            $idempotencyKey,
            $messageType,
            $body,
            $replyToMessageId,
            $requestedVisibility,
            $bodyHash,
            $now
        ): array {
            $ticket = $this->requiredTicket($ticketId);
            $this->authorization->assertCanAct($actorId, 'post_message', $ticket, $now);
            if (!in_array((string) ($ticket['status'] ?? ''), self::MESSAGEABLE_STATES, true)) {
                throw new DomainException('ERTAQ_MESSAGE_TICKET_CLOSED');
            }
            $existing = $this->repository->messageByIdempotencyForUpdate($idempotencyKey);
            if ($existing !== null) {
                if ($this->messageMatches(
                    $existing,
                    $ticketId,
                    $actorId,
                    $messageType,
                    $bodyHash,
                    $replyToMessageId
                )) {
                    return $this->messageReceipt($existing, true);
                }
                throw new DomainException('ERTAQ_MESSAGE_IDEMPOTENCY_CONFLICT');
            }
            if ($replyToMessageId !== null) {
                $parent = $this->repository->messageForUpdate($replyToMessageId);
                if ($parent === null || (int) ($parent['ticket_id'] ?? 0) !== $ticketId) {
                    throw new DomainException('ERTAQ_MESSAGE_REPLY_NOT_FOUND');
                }
            }
            $visibility = $this->visibility(
                $this->authorization->resolveMessageVisibility(
                    $actorId,
                    $ticket,
                    $messageType,
                    $requestedVisibility,
                    $now
                ),
                'ERTAQ_MESSAGE_VISIBILITY_INVALID'
            );
            $input = [
                'ticket_id' => $ticketId,
                'sender_user_id' => $actorId,
                'message_type' => $messageType,
                'visibility' => $visibility,
                'body_cipher_or_text' => $body,
                'body_hash' => $bodyHash,
                'reply_to_message_id' => $replyToMessageId,
                'idempotency_key' => $idempotencyKey,
                'sent_at' => $this->instant($now),
            ];
            $messageId = $this->repository->insertMessage($input);
            if ($messageId <= 0) {
                throw new DomainException('ERTAQ_MESSAGE_PERSIST_FAILED');
            }
            $stored = $input + ['id' => $messageId];
            $this->audit->recordEvent(
                'staff_ertaq_message_posted',
                'staff_ertaq_messages',
                $messageId,
                null,
                [
                    'ticket_id' => $ticketId,
                    'message_type' => $messageType,
                    'visibility' => $visibility,
                    'reply_to_message_id' => $replyToMessageId,
                    'body_hash' => $bodyHash,
                    'body_provided' => true,
                ],
                ['user_id' => $actorId, 'occurred_at' => $this->instant($now)]
            );

            return $this->messageReceipt($stored, false);
        });
    }

    /**
     * @param array<string,mixed> $command
     * @return array<string,mixed>
     */
    public function addParty(array $command): array
    {
        $actorId = $this->positiveId($command['actor_id'] ?? null, 'ERTAQ_ACTOR_INVALID');
        $ticketId = $this->positiveId($command['ticket_id'] ?? null, 'ERTAQ_TICKET_ID_INVALID');
        $idempotencyKey = $this->requiredText(
            $command['idempotency_key'] ?? null,
            64,
            'ERTAQ_PARTY_IDEMPOTENCY_INVALID'
        );
        $party = $this->partyInput($command, $actorId, $ticketId, $idempotencyKey);
        $requestedVisibility = $this->nullableVisibility($command['visibility_scope'] ?? null);
        $now = $this->now();

        return $this->repository->transactional(function () use (
            $actorId,
            $ticketId,
            $idempotencyKey,
            $party,
            $requestedVisibility,
            $now
        ): array {
            $ticket = $this->requiredTicket($ticketId);
            $this->authorization->assertCanAct($actorId, 'add_party', $ticket, $now);
            if (!in_array((string) ($ticket['status'] ?? ''), self::PARTY_LINKABLE_STATES, true)) {
                throw new DomainException('ERTAQ_PARTY_CHANGE_FORBIDDEN');
            }
            if ($party['party_user_id'] !== null && !$this->repository->lockUser($party['party_user_id'])) {
                throw new DomainException('ERTAQ_PARTY_USER_NOT_FOUND');
            }
            if ($party['party_role'] === 'requester'
                && $party['party_user_id'] !== (int) ($ticket['requester_user_id'] ?? 0)) {
                throw new DomainException('ERTAQ_PARTY_REQUESTER_MISMATCH');
            }
            $existing = $this->repository->partyByIdempotencyForUpdate($idempotencyKey);
            if ($existing !== null) {
                if ($this->partyMatches($existing, $party)) {
                    return $this->partyReceipt($existing, true);
                }
                throw new DomainException('ERTAQ_PARTY_IDEMPOTENCY_CONFLICT');
            }
            $visibility = $this->visibility(
                $this->authorization->resolvePartyVisibility(
                    $actorId,
                    $ticket,
                    $party,
                    $requestedVisibility,
                    $now
                ),
                'ERTAQ_PARTY_VISIBILITY_INVALID'
            );
            $party['visibility_scope'] = $visibility;
            $party['party_hash'] = $this->hash([
                'ticket_id' => $ticketId,
                'party_user_id' => $party['party_user_id'],
                'external_party_label' => $party['external_party_label'],
                'party_role' => $party['party_role'],
                'visibility_scope' => $visibility,
                'added_by_user_id' => $actorId,
            ]);
            $partyId = $this->repository->insertParty($party);
            if ($partyId <= 0) {
                throw new DomainException('ERTAQ_PARTY_PERSIST_FAILED');
            }
            $stored = $party + ['id' => $partyId];
            $this->audit->recordEvent(
                'staff_ertaq_party_added',
                'staff_ertaq_parties',
                $partyId,
                null,
                [
                    'ticket_id' => $ticketId,
                    'party_role' => $party['party_role'],
                    'visibility_scope' => $visibility,
                    'is_internal_party' => $party['party_user_id'] !== null,
                    'party_hash' => $party['party_hash'],
                ],
                ['user_id' => $actorId, 'occurred_at' => $this->instant($now)]
            );

            return $this->partyReceipt($stored, false);
        });
    }

    /**
     * Stores a relationship only. A discipline-case or initiative conversion
     * remains an explicit future contract; this service never copies text from
     * the ticket to a linked target.
     *
     * @param array<string,mixed> $command
     * @return array<string,mixed>
     */
    public function linkTicket(array $command): array
    {
        $actorId = $this->positiveId($command['actor_id'] ?? null, 'ERTAQ_ACTOR_INVALID');
        $ticketId = $this->positiveId($command['ticket_id'] ?? null, 'ERTAQ_TICKET_ID_INVALID');
        $idempotencyKey = $this->requiredText(
            $command['idempotency_key'] ?? null,
            64,
            'ERTAQ_LINK_IDEMPOTENCY_INVALID'
        );
        $link = $this->linkInput($command, $actorId, $ticketId, $idempotencyKey);
        $requestedVisibility = $this->nullableVisibility($command['visibility_scope'] ?? null);
        $now = $this->now();

        return $this->repository->transactional(function () use (
            $actorId,
            $ticketId,
            $idempotencyKey,
            $link,
            $requestedVisibility,
            $now
        ): array {
            $ticket = $this->requiredTicket($ticketId);
            $this->authorization->assertCanAct($actorId, 'link_ticket', $ticket, $now);
            if (!in_array((string) ($ticket['status'] ?? ''), self::PARTY_LINKABLE_STATES, true)) {
                throw new DomainException('ERTAQ_LINK_CHANGE_FORBIDDEN');
            }
            $existing = $this->repository->linkByIdempotencyForUpdate($idempotencyKey);
            if ($existing !== null) {
                if ($this->linkMatches($existing, $link)) {
                    return $this->linkReceipt($existing, true);
                }
                throw new DomainException('ERTAQ_LINK_IDEMPOTENCY_CONFLICT');
            }
            $visibility = $this->visibility(
                $this->authorization->resolveLinkVisibility(
                    $actorId,
                    $ticket,
                    $link,
                    $requestedVisibility,
                    $now
                ),
                'ERTAQ_LINK_VISIBILITY_INVALID'
            );
            if ($link['related_ticket_id'] !== null
                && $this->repository->ticketForUpdate($link['related_ticket_id']) === null) {
                throw new DomainException('ERTAQ_LINK_RELATED_TICKET_NOT_FOUND');
            }
            $link['visibility_scope'] = $visibility;
            $link['link_hash'] = $this->hash([
                'ticket_id' => $ticketId,
                'related_ticket_id' => $link['related_ticket_id'],
                'target_resource_type' => $link['target_resource_type'],
                'target_resource_id' => $link['target_resource_id'],
                'link_type' => $link['link_type'],
                'visibility_scope' => $visibility,
                'link_reason' => $link['link_reason'],
                'linked_by_user_id' => $actorId,
            ]);
            $linkId = $this->repository->insertLink($link);
            if ($linkId <= 0) {
                throw new DomainException('ERTAQ_LINK_PERSIST_FAILED');
            }
            $stored = $link + ['id' => $linkId];
            $this->audit->recordEvent(
                'staff_ertaq_ticket_linked',
                'staff_ertaq_ticket_links',
                $linkId,
                null,
                [
                    'ticket_id' => $ticketId,
                    'related_ticket_id' => $link['related_ticket_id'],
                    'target_resource_type' => $link['target_resource_type'],
                    'target_resource_id' => $link['target_resource_id'],
                    'link_type' => $link['link_type'],
                    'visibility_scope' => $visibility,
                    'reason_provided' => $link['link_reason'] !== null,
                    'link_hash' => $link['link_hash'],
                ],
                ['user_id' => $actorId, 'occurred_at' => $this->instant($now)]
            );

            return $this->linkReceipt($stored, false);
        });
    }

    /**
     * A withdrawal request never erases the ticket, its parties, or messages.
     * It records the previous operational state so an authorized decision can
     * resume exactly that state rather than guessing.
     *
     * @param array<string,mixed> $command
     * @return array<string,mixed>
     */
    public function requestWithdrawal(array $command): array
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
            'ERTAQ_WITHDRAWAL_IDEMPOTENCY_INVALID'
        );
        $reason = $this->requiredText(
            $command['withdrawal_reason'] ?? null,
            4000,
            'ERTAQ_WITHDRAWAL_REASON_REQUIRED'
        );
        $eventHash = $this->hash([
            'ticket_id' => $ticketId,
            'requested_by_user_id' => $actorId,
            'withdrawal_reason' => $reason,
        ]);
        $now = $this->now();

        return $this->repository->transactional(function () use (
            $actorId,
            $ticketId,
            $expectedLockVersion,
            $idempotencyKey,
            $reason,
            $eventHash,
            $now
        ): array {
            $ticket = $this->requiredTicket($ticketId);
            $this->authorization->assertCanAct($actorId, 'request_withdrawal', $ticket, $now);
            if ((int) ($ticket['requester_user_id'] ?? 0) !== $actorId) {
                throw new DomainException('ERTAQ_WITHDRAWAL_REQUESTER_ONLY');
            }
            $existing = $this->repository->withdrawalByIdempotencyForUpdate($idempotencyKey);
            if ($existing !== null) {
                if (hash_equals((string) ($existing['event_hash'] ?? ''), $eventHash)) {
                    return $this->withdrawalReceipt($existing, true);
                }
                throw new DomainException('ERTAQ_WITHDRAWAL_IDEMPOTENCY_CONFLICT');
            }
            $priorStatus = (string) ($ticket['status'] ?? '');
            if ((int) ($ticket['lock_version'] ?? 0) !== $expectedLockVersion) {
                throw new DomainException('ERTAQ_TICKET_STALE');
            }
            if (!in_array($priorStatus, self::WITHDRAWAL_REQUESTABLE_STATES, true)) {
                throw new DomainException('ERTAQ_WITHDRAWAL_REQUEST_FORBIDDEN');
            }
            $input = [
                'ticket_id' => $ticketId,
                'event_type' => 'requested',
                'request_event_id' => null,
                'prior_ticket_status' => $priorStatus,
                'requested_by_user_id' => $actorId,
                'requested_at' => $this->instant($now),
                'withdrawal_reason' => $reason,
                'decided_by_user_id' => null,
                'decided_at' => null,
                'outcome' => null,
                'decision_reason' => null,
                'event_hash' => $eventHash,
                'idempotency_key' => $idempotencyKey,
            ];
            $eventId = $this->repository->insertWithdrawalEvent($input);
            if ($eventId <= 0) {
                throw new DomainException('ERTAQ_WITHDRAWAL_PERSIST_FAILED');
            }
            $changes = [
                'withdrawal_requested_at' => $this->instant($now),
                'withdrawal_requested_by_user_id' => $actorId,
            ];
            if (!$this->repository->transitionTicket(
                $ticketId,
                $expectedLockVersion,
                $priorStatus,
                'withdrawal_requested',
                $changes
            )) {
                throw new DomainException('ERTAQ_TICKET_STALE');
            }
            $stored = $input + ['id' => $eventId];
            $this->audit->recordEvent(
                'staff_ertaq_withdrawal_requested',
                'staff_ertaq_withdrawal_events',
                $eventId,
                null,
                [
                    'ticket_id' => $ticketId,
                    'prior_ticket_status' => $priorStatus,
                    'reason_provided' => true,
                    'event_hash' => $eventHash,
                ],
                ['user_id' => $actorId, 'occurred_at' => $this->instant($now)]
            );
            $this->audit->recordEvent(
                'staff_ertaq_ticket_withdrawal_pending',
                'staff_ertaq_tickets',
                $ticketId,
                (string) ($ticket['ticket_no'] ?? ''),
                ['previous_status' => $priorStatus, 'status' => 'withdrawal_requested', 'withdrawal_event_id' => $eventId],
                ['user_id' => $actorId, 'occurred_at' => $this->instant($now)]
            );

            return $this->withdrawalReceipt($stored, false);
        });
    }

    /**
     * @param array<string,mixed> $command
     * @return array<string,mixed>
     */
    public function decideWithdrawal(array $command): array
    {
        $actorId = $this->positiveId($command['actor_id'] ?? null, 'ERTAQ_ACTOR_INVALID');
        $requestEventId = $this->positiveId(
            $command['request_event_id'] ?? null,
            'ERTAQ_WITHDRAWAL_REQUEST_ID_INVALID'
        );
        $expectedLockVersion = $this->positiveId(
            $command['expected_ticket_lock_version'] ?? null,
            'ERTAQ_TICKET_LOCK_INVALID'
        );
        $outcome = $this->enum(
            $command['outcome'] ?? null,
            self::WITHDRAWAL_OUTCOMES,
            'ERTAQ_WITHDRAWAL_OUTCOME_INVALID'
        );
        $reason = $this->requiredText(
            $command['decision_reason'] ?? null,
            4000,
            'ERTAQ_WITHDRAWAL_DECISION_REASON_REQUIRED'
        );
        $idempotencyKey = $this->requiredText(
            $command['idempotency_key'] ?? null,
            64,
            'ERTAQ_WITHDRAWAL_DECISION_IDEMPOTENCY_INVALID'
        );
        $eventHash = $this->hash([
            'request_event_id' => $requestEventId,
            'decided_by_user_id' => $actorId,
            'outcome' => $outcome,
            'decision_reason' => $reason,
        ]);
        $now = $this->now();

        return $this->repository->transactional(function () use (
            $actorId,
            $requestEventId,
            $expectedLockVersion,
            $outcome,
            $reason,
            $idempotencyKey,
            $eventHash,
            $now
        ): array {
            $request = $this->repository->withdrawalEventForUpdate($requestEventId);
            if ($request === null || (string) ($request['event_type'] ?? '') !== 'requested') {
                throw new DomainException('ERTAQ_WITHDRAWAL_REQUEST_NOT_FOUND');
            }
            $ticketId = $this->positiveId($request['ticket_id'] ?? null, 'ERTAQ_TICKET_ID_INVALID');
            $ticket = $this->requiredTicket($ticketId);
            $this->authorization->assertCanAct($actorId, 'decide_withdrawal', $ticket, $now);
            if ((int) ($request['requested_by_user_id'] ?? 0) === $actorId) {
                throw new DomainException('ERTAQ_WITHDRAWAL_SELF_DECISION_FORBIDDEN');
            }
            $existing = $this->repository->withdrawalByIdempotencyForUpdate($idempotencyKey);
            if ($existing !== null) {
                if (hash_equals((string) ($existing['event_hash'] ?? ''), $eventHash)) {
                    return $this->withdrawalReceipt($existing, true);
                }
                throw new DomainException('ERTAQ_WITHDRAWAL_DECISION_IDEMPOTENCY_CONFLICT');
            }
            if ($this->repository->withdrawalDecisionForRequestForUpdate($requestEventId) !== null) {
                throw new DomainException('ERTAQ_WITHDRAWAL_ALREADY_DECIDED');
            }
            if ((string) ($ticket['status'] ?? '') !== 'withdrawal_requested'
                || (int) ($ticket['lock_version'] ?? 0) !== $expectedLockVersion) {
                throw new DomainException('ERTAQ_TICKET_STALE');
            }
            $priorStatus = (string) ($request['prior_ticket_status'] ?? '');
            if (!in_array($priorStatus, self::WITHDRAWAL_REQUESTABLE_STATES, true)) {
                throw new DomainException('ERTAQ_WITHDRAWAL_PRIOR_STATE_INVALID');
            }
            $toStatus = $outcome === 'withdrawn' ? 'closed' : $priorStatus;
            $changes = $outcome === 'withdrawn'
                ? [
                    'closure_reason' => $reason,
                    'closed_at' => $this->instant($now),
                    'closed_by_user_id' => $actorId,
                ]
                : [];
            $input = [
                'ticket_id' => $ticketId,
                'event_type' => 'decided',
                'request_event_id' => $requestEventId,
                'prior_ticket_status' => null,
                'requested_by_user_id' => null,
                'requested_at' => null,
                'withdrawal_reason' => null,
                'decided_by_user_id' => $actorId,
                'decided_at' => $this->instant($now),
                'outcome' => $outcome,
                'decision_reason' => $reason,
                'event_hash' => $eventHash,
                'idempotency_key' => $idempotencyKey,
            ];
            $eventId = $this->repository->insertWithdrawalEvent($input);
            if ($eventId <= 0) {
                throw new DomainException('ERTAQ_WITHDRAWAL_DECISION_PERSIST_FAILED');
            }
            if (!$this->repository->transitionTicket(
                $ticketId,
                $expectedLockVersion,
                'withdrawal_requested',
                $toStatus,
                $changes
            )) {
                throw new DomainException('ERTAQ_TICKET_STALE');
            }
            $stored = $input + ['id' => $eventId];
            $this->audit->recordEvent(
                'staff_ertaq_withdrawal_decided',
                'staff_ertaq_withdrawal_events',
                $eventId,
                null,
                [
                    'ticket_id' => $ticketId,
                    'request_event_id' => $requestEventId,
                    'outcome' => $outcome,
                    'reason_provided' => true,
                    'event_hash' => $eventHash,
                ],
                ['user_id' => $actorId, 'occurred_at' => $this->instant($now)]
            );
            $this->audit->recordEvent(
                'staff_ertaq_ticket_withdrawal_resolved',
                'staff_ertaq_tickets',
                $ticketId,
                (string) ($ticket['ticket_no'] ?? ''),
                [
                    'previous_status' => 'withdrawal_requested',
                    'status' => $toStatus,
                    'withdrawal_request_event_id' => $requestEventId,
                    'withdrawal_decision_event_id' => $eventId,
                    'outcome' => $outcome,
                ],
                ['user_id' => $actorId, 'occurred_at' => $this->instant($now)]
            );

            return $this->withdrawalReceipt($stored, false);
        });
    }

    /** @param array<string,mixed> $command @return array<string,mixed> */
    private function partyInput(array $command, int $actorId, int $ticketId, string $idempotencyKey): array
    {
        $partyUserId = $this->nullablePositiveId($command['party_user_id'] ?? null, 'ERTAQ_PARTY_USER_INVALID');
        $externalLabel = $this->nullableText(
            $command['external_party_label'] ?? null,
            255,
            'ERTAQ_PARTY_EXTERNAL_LABEL_INVALID'
        );
        if (($partyUserId === null) === ($externalLabel === null)) {
            throw new InvalidArgumentException('ERTAQ_PARTY_IDENTITY_REQUIRED');
        }

        return [
            'ticket_id' => $ticketId,
            'party_user_id' => $partyUserId,
            'external_party_label' => $externalLabel,
            'party_role' => $this->enum(
                $command['party_role'] ?? null,
                self::PARTY_ROLES,
                'ERTAQ_PARTY_ROLE_INVALID'
            ),
            'added_by_user_id' => $actorId,
            'idempotency_key' => $idempotencyKey,
            'party_hash' => '',
        ];
    }

    /** @param array<string,mixed> $command @return array<string,mixed> */
    private function linkInput(array $command, int $actorId, int $ticketId, string $idempotencyKey): array
    {
        $relatedTicketId = $this->nullablePositiveId(
            $command['related_ticket_id'] ?? null,
            'ERTAQ_LINK_RELATED_TICKET_INVALID'
        );
        $targetResourceType = $this->nullableText(
            $command['target_resource_type'] ?? null,
            100,
            'ERTAQ_LINK_RESOURCE_TYPE_INVALID'
        );
        $targetResourceId = $this->nullablePositiveId(
            $command['target_resource_id'] ?? null,
            'ERTAQ_LINK_RESOURCE_ID_INVALID'
        );
        if ($relatedTicketId !== null) {
            if ($relatedTicketId === $ticketId) {
                throw new DomainException('ERTAQ_LINK_SELF_FORBIDDEN');
            }
            if ($targetResourceType !== null || $targetResourceId !== null) {
                throw new InvalidArgumentException('ERTAQ_LINK_TARGET_AMBIGUOUS');
            }
        } elseif ($targetResourceType === null || $targetResourceId === null) {
            throw new InvalidArgumentException('ERTAQ_LINK_TARGET_REQUIRED');
        }

        return [
            'ticket_id' => $ticketId,
            'related_ticket_id' => $relatedTicketId,
            'target_resource_type' => $targetResourceType,
            'target_resource_id' => $targetResourceId,
            'link_type' => $this->enum(
                $command['link_type'] ?? null,
                self::LINK_TYPES,
                'ERTAQ_LINK_TYPE_INVALID'
            ),
            'link_reason' => $this->nullableText(
                $command['link_reason'] ?? null,
                4000,
                'ERTAQ_LINK_REASON_INVALID'
            ),
            'linked_by_user_id' => $actorId,
            'idempotency_key' => $idempotencyKey,
            'link_hash' => '',
        ];
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

    /** @param array<string,mixed> $message */
    private function messageMatches(
        array $message,
        int $ticketId,
        int $actorId,
        string $messageType,
        string $bodyHash,
        ?int $replyToMessageId
    ): bool {
        return (int) ($message['ticket_id'] ?? 0) === $ticketId
            && (int) ($message['sender_user_id'] ?? 0) === $actorId
            && (string) ($message['message_type'] ?? '') === $messageType
            && hash_equals((string) ($message['body_hash'] ?? ''), $bodyHash)
            && $this->nullablePositiveId(
                $message['reply_to_message_id'] ?? null,
                'ERTAQ_MESSAGE_REPLY_INVALID'
            ) === $replyToMessageId;
    }

    /** @param array<string,mixed> $stored @param array<string,mixed> $party */
    private function partyMatches(array $stored, array $party): bool
    {
        return (int) ($stored['ticket_id'] ?? 0) === (int) $party['ticket_id']
            && $this->nullablePositiveId($stored['party_user_id'] ?? null, 'ERTAQ_PARTY_USER_INVALID')
                === $party['party_user_id']
            && (string) ($stored['external_party_label'] ?? '') === (string) ($party['external_party_label'] ?? '')
            && (string) ($stored['party_role'] ?? '') === $party['party_role']
            && (int) ($stored['added_by_user_id'] ?? 0) === (int) $party['added_by_user_id'];
    }

    /** @param array<string,mixed> $stored @param array<string,mixed> $link */
    private function linkMatches(array $stored, array $link): bool
    {
        return (int) ($stored['ticket_id'] ?? 0) === (int) $link['ticket_id']
            && $this->nullablePositiveId($stored['related_ticket_id'] ?? null, 'ERTAQ_LINK_RELATED_TICKET_INVALID')
                === $link['related_ticket_id']
            && (string) ($stored['target_resource_type'] ?? '') === (string) ($link['target_resource_type'] ?? '')
            && $this->nullablePositiveId($stored['target_resource_id'] ?? null, 'ERTAQ_LINK_RESOURCE_ID_INVALID')
                === $link['target_resource_id']
            && (string) ($stored['link_type'] ?? '') === $link['link_type']
            && (string) ($stored['link_reason'] ?? '') === (string) ($link['link_reason'] ?? '')
            && (int) ($stored['linked_by_user_id'] ?? 0) === (int) $link['linked_by_user_id'];
    }

    /** @param array<string,mixed> $message @return array<string,mixed> */
    private function messageReceipt(array $message, bool $replayed): array
    {
        return [
            'message_id' => $this->positiveId($message['id'] ?? null, 'ERTAQ_MESSAGE_PERSIST_FAILED'),
            'ticket_id' => (int) ($message['ticket_id'] ?? 0),
            'message_type' => (string) ($message['message_type'] ?? ''),
            'visibility' => (string) ($message['visibility'] ?? ''),
            'reply_to_message_id' => $this->nullablePositiveId(
                $message['reply_to_message_id'] ?? null,
                'ERTAQ_MESSAGE_REPLY_INVALID'
            ),
            'replayed' => $replayed,
        ];
    }

    /** @param array<string,mixed> $party @return array<string,mixed> */
    private function partyReceipt(array $party, bool $replayed): array
    {
        return [
            'party_id' => $this->positiveId($party['id'] ?? null, 'ERTAQ_PARTY_PERSIST_FAILED'),
            'ticket_id' => (int) ($party['ticket_id'] ?? 0),
            'party_role' => (string) ($party['party_role'] ?? ''),
            'visibility_scope' => (string) ($party['visibility_scope'] ?? ''),
            'is_internal_party' => ($party['party_user_id'] ?? null) !== null,
            'replayed' => $replayed,
        ];
    }

    /** @param array<string,mixed> $link @return array<string,mixed> */
    private function linkReceipt(array $link, bool $replayed): array
    {
        return [
            'link_id' => $this->positiveId($link['id'] ?? null, 'ERTAQ_LINK_PERSIST_FAILED'),
            'ticket_id' => (int) ($link['ticket_id'] ?? 0),
            'related_ticket_id' => $this->nullablePositiveId(
                $link['related_ticket_id'] ?? null,
                'ERTAQ_LINK_RELATED_TICKET_INVALID'
            ),
            'target_resource_type' => $link['target_resource_type'] ?? null,
            'target_resource_id' => $this->nullablePositiveId(
                $link['target_resource_id'] ?? null,
                'ERTAQ_LINK_RESOURCE_ID_INVALID'
            ),
            'link_type' => (string) ($link['link_type'] ?? ''),
            'visibility_scope' => (string) ($link['visibility_scope'] ?? ''),
            'replayed' => $replayed,
        ];
    }

    /** @param array<string,mixed> $event @return array<string,mixed> */
    private function withdrawalReceipt(array $event, bool $replayed): array
    {
        return [
            'withdrawal_event_id' => $this->positiveId($event['id'] ?? null, 'ERTAQ_WITHDRAWAL_PERSIST_FAILED'),
            'ticket_id' => (int) ($event['ticket_id'] ?? 0),
            'event_type' => (string) ($event['event_type'] ?? ''),
            'request_event_id' => $this->nullablePositiveId(
                $event['request_event_id'] ?? null,
                'ERTAQ_WITHDRAWAL_REQUEST_ID_INVALID'
            ),
            'outcome' => $event['outcome'] ?? null,
            'replayed' => $replayed,
        ];
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

    private function nullableVisibility(mixed $value): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }

        return $this->visibility($value, 'ERTAQ_VISIBILITY_REQUEST_INVALID');
    }

    private function visibility(mixed $value, string $error): string
    {
        return $this->enum($value, self::VISIBILITY_SCOPES, $error);
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
