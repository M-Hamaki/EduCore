<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/vendor/autoload.php';

use EduCore\Modules\Operations\Audit\AuditEventWriter;
use EduCore\Modules\Staff\Application\Ertaq\ErtaqConversationService;
use EduCore\Modules\Staff\Contracts\ErtaqConversationAuthorization;
use EduCore\Modules\Staff\Contracts\ErtaqConversationRepository;

final class ErtaqConversationMemoryRepository implements ErtaqConversationRepository
{
    /** @var array<int,array<string,mixed>> */
    public array $tickets;
    /** @var array<int,array<string,mixed>> */
    public array $messages = [];
    /** @var array<int,array<string,mixed>> */
    public array $parties = [];
    /** @var array<int,array<string,mixed>> */
    public array $links = [];
    /** @var array<int,array<string,mixed>> */
    public array $withdrawals = [];
    /** @var array<int,bool> */
    public array $users = [7 => true, 8 => true, 9 => true, 10 => true];
    private int $messageSequence = 0;
    private int $partySequence = 0;
    private int $linkSequence = 0;
    private int $withdrawalSequence = 0;

    public function __construct()
    {
        $this->tickets = [
            1 => [
                'id' => 1,
                'ticket_no' => 'ERT-TEST-001',
                'requester_user_id' => 7,
                'type' => 'complaint',
                'classification' => 'employee_relations',
                'confidentiality_level' => 'restricted',
                'priority' => 'normal',
                'risk_level' => 'none',
                'status' => 'in_progress',
                'lock_version' => 1,
            ],
            2 => [
                'id' => 2,
                'ticket_no' => 'ERT-TEST-002',
                'requester_user_id' => 9,
                'type' => 'complaint',
                'classification' => 'employee_relations',
                'confidentiality_level' => 'restricted',
                'priority' => 'normal',
                'risk_level' => 'none',
                'status' => 'triaged',
                'lock_version' => 1,
            ],
        ];
    }

    public function transactional(callable $work): mixed
    {
        $tickets = $this->tickets;
        $messages = $this->messages;
        $parties = $this->parties;
        $links = $this->links;
        $withdrawals = $this->withdrawals;
        $sequences = [
            $this->messageSequence,
            $this->partySequence,
            $this->linkSequence,
            $this->withdrawalSequence,
        ];
        try {
            return $work();
        } catch (Throwable $exception) {
            $this->tickets = $tickets;
            $this->messages = $messages;
            $this->parties = $parties;
            $this->links = $links;
            $this->withdrawals = $withdrawals;
            [
                $this->messageSequence,
                $this->partySequence,
                $this->linkSequence,
                $this->withdrawalSequence,
            ] = $sequences;
            throw $exception;
        }
    }

    public function lockUser(int $userId): bool
    {
        return $this->users[$userId] ?? false;
    }

    public function ticketForUpdate(int $ticketId): ?array
    {
        return $this->tickets[$ticketId] ?? null;
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
        $this->parties[$id] = $party + ['id' => $id];

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
            if (($event['request_event_id'] ?? null) === $requestEventId
                && ($event['event_type'] ?? null) === 'decided') {
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
}

final class ErtaqConversationTestAuthorization implements ErtaqConversationAuthorization
{
    /** @var list<string> */
    public array $actions = [];
    /** @var list<array<string,mixed>> */
    public array $visibilityCalls = [];

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
            throw new DomainException('ERTAQ_CONVERSATION_ACCESS_DENIED');
        }
    }

    public function resolveMessageVisibility(
        int $actorId,
        array $ticket,
        string $messageType,
        ?string $requestedVisibility,
        DateTimeImmutable $atInstant
    ): string {
        $this->visibilityCalls[] = ['kind' => 'message', 'requested' => $requestedVisibility];

        return $messageType === 'requester_message' ? 'requester' : 'assigned_team';
    }

    public function resolvePartyVisibility(
        int $actorId,
        array $ticket,
        array $party,
        ?string $requestedVisibility,
        DateTimeImmutable $atInstant
    ): string {
        $this->visibilityCalls[] = ['kind' => 'party', 'requested' => $requestedVisibility];

        return $party['party_role'] === 'accused' ? 'restricted' : 'assigned_team';
    }

    public function resolveLinkVisibility(
        int $actorId,
        array $ticket,
        array $link,
        ?string $requestedVisibility,
        DateTimeImmutable $atInstant
    ): string {
        $this->visibilityCalls[] = ['kind' => 'link', 'requested' => $requestedVisibility];

        return $link['link_type'] === 'discipline_case' ? 'restricted' : 'assigned_team';
    }
}

final class ErtaqConversationTestAudit implements AuditEventWriter
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
            throw new RuntimeException('ERTAQ_CONVERSATION_AUDIT_FAILED');
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
    $repository = new ErtaqConversationMemoryRepository();
    $authorization = new ErtaqConversationTestAuthorization();
    $audit = new ErtaqConversationTestAudit();

    return [$repository, $authorization, $audit, new ErtaqConversationService(
        $repository,
        $authorization,
        $audit
    )];
};

[$repository, $authorization, $audit, $service] = $newFixture();
$messageCommand = [
    'actor_id' => 7,
    'ticket_id' => 1,
    'message_type' => 'requester_message',
    'body' => 'هذه رسالة تجريبية سرية من مقدم الشكوى.',
    'visibility' => 'protection_team',
    'idempotency_key' => 'ertaq-message-1',
];
$message = $service->postMessage($messageCommand);
$assert(
    $message['message_id'] === 1
        && $message['visibility'] === 'requester'
        && !array_key_exists('body', $message),
    'message visibility is authorization-derived and receipt never exposes the body'
);
$assert(
    count($audit->events) === 1
        && !array_key_exists('body', $audit->events[0]['details'])
        && $authorization->visibilityCalls[0]['requested'] === 'protection_team',
    'audit preserves safe message evidence while rejecting browser-owned visibility'
);
$messageReplay = $service->postMessage($messageCommand);
$assert(
    $messageReplay['replayed'] === true
        && count($repository->messages) === 1,
    'message retry returns one immutable original message'
);
$assertThrows(
    static fn (): array => $service->postMessage(array_replace($messageCommand, ['body' => 'نص مختلف'])),
    'ERTAQ_MESSAGE_IDEMPOTENCY_CONFLICT',
    'a changed message under the same idempotency key fails closed'
);
$reply = $service->postMessage([
    'actor_id' => 8,
    'ticket_id' => 1,
    'message_type' => 'team_reply',
    'body' => 'تمت إحالة الرسالة إلى المعالج المختص.',
    'reply_to_message_id' => 1,
    'visibility' => 'requester',
    'idempotency_key' => 'ertaq-message-2',
]);
$assert(
    $reply['reply_to_message_id'] === 1
        && $reply['visibility'] === 'assigned_team',
    'reply is linked only within the ticket and its visibility remains server-derived'
);
$assertThrows(
    static fn (): array => $service->postMessage([
        'actor_id' => 8,
        'ticket_id' => 1,
        'message_type' => 'team_reply',
        'body' => 'رد بمراجع غير صحيح.',
        'reply_to_message_id' => 99,
        'idempotency_key' => 'ertaq-message-bad-reply',
    ]),
    'ERTAQ_MESSAGE_REPLY_NOT_FOUND',
    'a message cannot reply across a missing or another ticket record'
);

$requesterParty = $service->addParty([
    'actor_id' => 8,
    'ticket_id' => 1,
    'party_user_id' => 7,
    'party_role' => 'requester',
    'visibility_scope' => 'protection_team',
    'idempotency_key' => 'ertaq-party-requester',
]);
$accusedParty = $service->addParty([
    'actor_id' => 8,
    'ticket_id' => 1,
    'party_user_id' => 9,
    'party_role' => 'accused',
    'visibility_scope' => 'assigned_team',
    'idempotency_key' => 'ertaq-party-accused',
]);
$assert(
    $requesterParty['party_role'] === 'requester'
        && $requesterParty['visibility_scope'] === 'assigned_team'
        && $accusedParty['visibility_scope'] === 'restricted'
        && !array_key_exists('external_party_label', $requesterParty),
    'party identity and accused visibility are constrained by server authorization'
);
$partyReplay = $service->addParty([
    'actor_id' => 8,
    'ticket_id' => 1,
    'party_user_id' => 9,
    'party_role' => 'accused',
    'visibility_scope' => 'assigned_team',
    'idempotency_key' => 'ertaq-party-accused',
]);
$assert(
    $partyReplay['replayed'] === true
        && count($repository->parties) === 2,
    'party idempotency preserves a single conflict evidence record'
);
$assertThrows(
    static fn (): array => $service->addParty([
        'actor_id' => 8,
        'ticket_id' => 1,
        'party_user_id' => 9,
        'party_role' => 'requester',
        'idempotency_key' => 'ertaq-party-wrong-requester',
    ]),
    'ERTAQ_PARTY_REQUESTER_MISMATCH',
    'the requester party cannot be changed to a different worker'
);

$collective = $service->linkTicket([
    'actor_id' => 8,
    'ticket_id' => 1,
    'related_ticket_id' => 2,
    'link_type' => 'collective',
    'visibility_scope' => 'protection_team',
    'link_reason' => 'بلاغات متشابهة من أكثر من طرف.',
    'idempotency_key' => 'ertaq-link-collective',
]);
$discipline = $service->linkTicket([
    'actor_id' => 8,
    'ticket_id' => 1,
    'target_resource_type' => 'discipline_case',
    'target_resource_id' => 41,
    'link_type' => 'discipline_case',
    'visibility_scope' => 'assigned_team',
    'idempotency_key' => 'ertaq-link-discipline',
]);
$assert(
    $collective['related_ticket_id'] === 2
        && $collective['visibility_scope'] === 'assigned_team'
        && $discipline['target_resource_type'] === 'discipline_case'
        && $discipline['visibility_scope'] === 'restricted',
    'collective and discipline links retain scalar references without copying ticket content'
);
$linkReplay = $service->linkTicket([
    'actor_id' => 8,
    'ticket_id' => 1,
    'target_resource_type' => 'discipline_case',
    'target_resource_id' => 41,
    'link_type' => 'discipline_case',
    'visibility_scope' => 'assigned_team',
    'idempotency_key' => 'ertaq-link-discipline',
]);
$assert(
    $linkReplay['replayed'] === true
        && count($repository->links) === 2,
    'link idempotency preserves one confidential scalar relation'
);
$assertThrows(
    static fn (): array => $service->linkTicket([
        'actor_id' => 8,
        'ticket_id' => 1,
        'related_ticket_id' => 1,
        'link_type' => 'related',
        'idempotency_key' => 'ertaq-link-self',
    ]),
    'ERTAQ_LINK_SELF_FORBIDDEN',
    'a ticket cannot link to itself'
);

$withdrawal = $service->requestWithdrawal([
    'actor_id' => 7,
    'ticket_id' => 1,
    'expected_lock_version' => 1,
    'withdrawal_reason' => 'أطلب سحب البلاغ مع حفظ الرسالة الأصلية.',
    'idempotency_key' => 'ertaq-withdrawal-request',
]);
$assert(
    $withdrawal['withdrawal_event_id'] === 1
        && $repository->tickets[1]['status'] === 'withdrawal_requested'
        && $repository->withdrawals[1]['prior_ticket_status'] === 'in_progress'
        && count($repository->messages) === 2,
    'withdrawal request preserves messages and records the previous operational state'
);
$withdrawalReplay = $service->requestWithdrawal([
    'actor_id' => 7,
    'ticket_id' => 1,
    'expected_lock_version' => 1,
    'withdrawal_reason' => 'أطلب سحب البلاغ مع حفظ الرسالة الأصلية.',
    'idempotency_key' => 'ertaq-withdrawal-request',
]);
$assert(
    $withdrawalReplay['replayed'] === true
        && count($repository->withdrawals) === 1,
    'withdrawal request is idempotent without a second ticket transition'
);
$assertThrows(
    static fn (): array => $service->decideWithdrawal([
        'actor_id' => 7,
        'request_event_id' => 1,
        'expected_ticket_lock_version' => 2,
        'outcome' => 'continue_processing',
        'decision_reason' => 'لا يجوز أن يقرر مقدم الطلب السحب بنفسه.',
        'idempotency_key' => 'ertaq-withdrawal-self-decision',
    ]),
    'ERTAQ_WITHDRAWAL_SELF_DECISION_FORBIDDEN',
    'requester cannot decide their own withdrawal'
);
$continued = $service->decideWithdrawal([
    'actor_id' => 8,
    'request_event_id' => 1,
    'expected_ticket_lock_version' => 2,
    'outcome' => 'continue_processing',
    'decision_reason' => 'يتطلب الالتزام الوقائي استمرار المعالجة.',
    'idempotency_key' => 'ertaq-withdrawal-continue',
]);
$assert(
    $continued['outcome'] === 'continue_processing'
        && $repository->tickets[1]['status'] === 'in_progress'
        && $repository->tickets[1]['lock_version'] === 3
        && count($repository->withdrawals) === 2,
    'authorized continuation restores the exact prior state without deleting evidence'
);
$assertThrows(
    static fn (): array => $service->decideWithdrawal([
        'actor_id' => 8,
        'request_event_id' => 1,
        'expected_ticket_lock_version' => 3,
        'outcome' => 'continue_processing',
        'decision_reason' => 'قرار ثان غير مسموح.',
        'idempotency_key' => 'ertaq-withdrawal-second-decision',
    ]),
    'ERTAQ_WITHDRAWAL_ALREADY_DECIDED',
    'one withdrawal request receives one append-only decision only'
);

[$closeRepository, , , $closeService] = $newFixture();
$closeRequest = $closeService->requestWithdrawal([
    'actor_id' => 7,
    'ticket_id' => 1,
    'expected_lock_version' => 1,
    'withdrawal_reason' => 'سحب بعد بدء المتابعة.',
    'idempotency_key' => 'ertaq-withdrawal-close-request',
]);
$closed = $closeService->decideWithdrawal([
    'actor_id' => 8,
    'request_event_id' => $closeRequest['withdrawal_event_id'],
    'expected_ticket_lock_version' => 2,
    'outcome' => 'withdrawn',
    'decision_reason' => 'لا يوجد التزام وقائي يمنع الإغلاق.',
    'idempotency_key' => 'ertaq-withdrawal-close-decision',
]);
$assert(
    $closed['outcome'] === 'withdrawn'
        && $closeRepository->tickets[1]['status'] === 'closed'
        && $closeRepository->tickets[1]['closure_reason'] !== null
        && $closeRepository->messages === [],
    'authorized withdrawal closes only the ticket lifecycle and retains any existing evidence'
);

[$deniedRepository, , , $deniedService] = $newFixture();
$deniedService = new ErtaqConversationService(
    $deniedRepository,
    new ErtaqConversationTestAuthorization(false),
    new ErtaqConversationTestAudit()
);
$assertThrows(
    static fn (): array => $deniedService->postMessage($messageCommand),
    'ERTAQ_CONVERSATION_ACCESS_DENIED',
    'authorization failure occurs before a confidential message is persisted'
);
$assert($deniedRepository->messages === [], 'denied conversation write leaves no partial message');

[$rollbackRepository, , $rollbackAudit, $rollbackService] = $newFixture();
$rollbackAudit->fail = true;
$assertThrows(
    static fn (): array => $rollbackService->postMessage($messageCommand),
    'ERTAQ_CONVERSATION_AUDIT_FAILED',
    'mandatory audit failure aborts message persistence'
);
$assert($rollbackRepository->messages === [], 'audit failure rolls back the immutable message write');

if ($failures > 0) {
    fwrite(STDERR, "{$failures} Ertaq conversation service test failure(s).\n");
    exit(1);
}

echo 'staff_hr_ertaq_conversation_service_test: PASS (' . $assertions . ' assertions)' . PHP_EOL;
