<?php

declare(strict_types=1);

namespace EduCore\Modules\Staff\Application\Ertaq;

use EduCore\Modules\Staff\Contracts\ErtaqInboxReadRepository;
use InvalidArgumentException;

/**
 * Presentation-safe read use case for the worker and directly assigned inbox.
 *
 * It returns explicitly projected view data only. It has no write, audit,
 * attachment, notification, party, protection-route, or cross-module query.
 */
final class ErtaqInboxQuery
{
    /** @var list<string> */
    private const STATUSES = [
        'new', 'triaged', 'assigned', 'in_progress', 'awaiting_requester',
        'resolved', 'closed', 'reopened', 'withdrawal_requested',
        'urgent_protected', 'cancelled',
    ];

    /** @var list<string> */
    private const PRIORITIES = ['low', 'normal', 'high', 'urgent'];

    public function __construct(private ErtaqInboxReadRepository $repository)
    {
    }

    /**
     * @param array<string,mixed> $filters
     * @return array{items:list<array<string,mixed>>,total:int,selected_ticket:array<string,mixed>|null,messages:list<array<string,mixed>>,access:string}
     */
    public function forRequester(int $requesterUserId, array $filters = [], ?int $ticketId = null): array
    {
        $requesterUserId = $this->positiveId($requesterUserId, 'ERTAQ_INBOX_ACTOR_INVALID');
        $normalized = $this->filters($filters, false);
        $items = array_map([$this, 'workerTicket'], $this->repository->requesterTickets($requesterUserId, $normalized));

        return $this->conversationResult(
            $ticketId,
            $items,
            $this->repository->requesterTicketCount($requesterUserId, $normalized),
            fn (int $selectedTicketId): ?array => $this->repository->requesterTicket($requesterUserId, $selectedTicketId),
            fn (int $selectedTicketId): array => $this->repository->requesterMessages($requesterUserId, $selectedTicketId),
            false
        );
    }

    /**
     * @param array<string,mixed> $filters
     * @return array{items:list<array<string,mixed>>,total:int,summary:array{total:int,overdue:int,urgent:int},selected_ticket:array<string,mixed>|null,messages:list<array<string,mixed>>,access:string}
     */
    public function forAssignee(int $assigneeUserId, array $filters = [], ?int $ticketId = null): array
    {
        $assigneeUserId = $this->positiveId($assigneeUserId, 'ERTAQ_INBOX_ACTOR_INVALID');
        $normalized = $this->filters($filters, true);
        $items = array_map([$this, 'assignedTicketRow'], $this->repository->assignedTickets($assigneeUserId, $normalized));
        $conversation = $this->conversationResult(
            $ticketId,
            $items,
            $this->repository->assignedTicketCount($assigneeUserId, $normalized),
            fn (int $selectedTicketId): ?array => $this->repository->assignedTicket($assigneeUserId, $selectedTicketId),
            fn (int $selectedTicketId): array => $this->repository->assignedMessages($assigneeUserId, $selectedTicketId),
            true
        );
        $summary = $this->repository->assignedSummary($assigneeUserId);

        return $conversation + [
            'summary' => [
                'total' => max(0, (int) ($summary['total'] ?? 0)),
                'overdue' => max(0, (int) ($summary['overdue'] ?? 0)),
                'urgent' => max(0, (int) ($summary['urgent'] ?? 0)),
            ],
        ];
    }

    /**
     * @param list<array<string,mixed>> $items
     * @param callable(int):array<string,mixed>|null $ticketLoader
     * @param callable(int):list<array<string,mixed>> $messageLoader
     * @return array{items:list<array<string,mixed>>,total:int,selected_ticket:array<string,mixed>|null,messages:list<array<string,mixed>>,access:string}
     */
    private function conversationResult(
        ?int $ticketId,
        array $items,
        int $total,
        callable $ticketLoader,
        callable $messageLoader,
        bool $assignedView
    ): array {
        $result = [
            'items' => $items,
            'total' => max(0, $total),
            'selected_ticket' => null,
            'messages' => [],
            'access' => 'none',
        ];
        if ($ticketId === null) {
            return $result;
        }

        $ticketId = $this->positiveId($ticketId, 'ERTAQ_TICKET_ID_INVALID');
        $rawTicket = $ticketLoader($ticketId);
        if ($rawTicket === null) {
            $result['access'] = $this->repository->ticketExists($ticketId) ? 'forbidden' : 'not_found';
            return $result;
        }

        $result['selected_ticket'] = $assignedView
            ? $this->assignedTicketRow($rawTicket)
            : $this->workerTicket($rawTicket);
        $result['messages'] = array_map([$this, 'message'], $messageLoader($ticketId));
        $result['access'] = 'granted';

        return $result;
    }

    /** @param array<string,mixed> $filters @return array{status:string,priority:string,query:string,limit:int} */
    private function filters(array $filters, bool $allowsPriority): array
    {
        $status = trim((string) ($filters['status'] ?? ''));
        if ($status !== '' && !in_array($status, self::STATUSES, true)) {
            throw new InvalidArgumentException('ERTAQ_INBOX_STATUS_INVALID');
        }
        $priority = trim((string) ($filters['priority'] ?? ''));
        if ($priority !== '' && (!$allowsPriority || !in_array($priority, self::PRIORITIES, true))) {
            throw new InvalidArgumentException('ERTAQ_INBOX_PRIORITY_INVALID');
        }
        $query = trim((string) ($filters['query'] ?? ''));
        if (strlen($query) > 160 || preg_match('/[\x00-\x1F\x7F]/', $query) === 1) {
            throw new InvalidArgumentException('ERTAQ_INBOX_QUERY_INVALID');
        }
        $limit = (int) ($filters['limit'] ?? 50);

        return [
            'status' => $status,
            'priority' => $priority,
            'query' => $query,
            'limit' => min(100, max(1, $limit)),
        ];
    }

    /** @param array<string,mixed> $row @return array<string,mixed> */
    private function workerTicket(array $row): array
    {
        return [
            'id' => $this->positiveId($row['id'] ?? null, 'ERTAQ_INBOX_ROW_INVALID'),
            'ticket_no' => $this->requiredText($row['ticket_no'] ?? null, 80),
            'type' => $this->enum($row['type'] ?? null, ['complaint', 'suggestion', 'inquiry', 'other']),
            'subject' => $this->requiredText($row['subject'] ?? null, 500),
            'confidentiality_level' => $this->enum($row['confidentiality_level'] ?? null, ['normal', 'restricted', 'highly_restricted']),
            'status' => $this->enum($row['status'] ?? null, self::STATUSES),
            'lock_version' => $this->positiveId($row['lock_version'] ?? 1, 'ERTAQ_INBOX_ROW_INVALID'),
            'created_at' => $this->dateText($row['created_at'] ?? null),
            'updated_at' => $this->dateText($row['updated_at'] ?? null),
        ];
    }

    /** @param array<string,mixed> $row @return array<string,mixed> */
    private function assignedTicketRow(array $row): array
    {
        return $this->workerTicket($row) + [
            'classification' => $this->requiredText($row['classification'] ?? null, 100),
            'priority' => $this->enum($row['priority'] ?? null, self::PRIORITIES),
            'first_response_due_at' => $this->nullableDateText($row['first_response_due_at'] ?? null),
            'sla_due_at' => $this->nullableDateText($row['sla_due_at'] ?? null),
            'assignment_status' => $this->enum($row['assignment_status'] ?? null, ['active', 'accepted']),
            'assigned_at' => $this->dateText($row['assigned_at'] ?? null),
        ];
    }

    /** @param array<string,mixed> $row @return array<string,mixed> */
    private function message(array $row): array
    {
        return [
            'id' => $this->positiveId($row['id'] ?? null, 'ERTAQ_INBOX_MESSAGE_INVALID'),
            'message_type' => $this->enum($row['message_type'] ?? null, [
                'requester_message', 'team_reply', 'internal_note', 'system_event',
                'withdrawal_request', 'status_update',
            ]),
            'body' => $this->requiredText($row['body'] ?? null, 50000),
            'sent_at' => $this->dateText($row['sent_at'] ?? null),
        ];
    }

    private function positiveId(mixed $value, string $error): int
    {
        if (!is_int($value) && !is_string($value)) {
            throw new InvalidArgumentException($error);
        }
        $id = (int) $value;
        if ($id <= 0 || (string) $id !== trim((string) $value)) {
            throw new InvalidArgumentException($error);
        }

        return $id;
    }

    /** @param list<string> $allowed */
    private function enum(mixed $value, array $allowed): string
    {
        $text = trim((string) $value);
        if (!in_array($text, $allowed, true)) {
            throw new InvalidArgumentException('ERTAQ_INBOX_ROW_INVALID');
        }

        return $text;
    }

    private function requiredText(mixed $value, int $max): string
    {
        $text = trim((string) $value);
        if ($text === '' || strlen($text) > $max) {
            throw new InvalidArgumentException('ERTAQ_INBOX_ROW_INVALID');
        }

        return $text;
    }

    private function dateText(mixed $value): string
    {
        $text = trim((string) $value);
        if (preg_match('/^\d{4}-\d{2}-\d{2}[ T]\d{2}:\d{2}/', $text) !== 1) {
            throw new InvalidArgumentException('ERTAQ_INBOX_ROW_INVALID');
        }

        return str_replace('T', ' ', substr($text, 0, 16));
    }

    private function nullableDateText(mixed $value): ?string
    {
        $text = trim((string) $value);
        return $text === '' ? null : $this->dateText($text);
    }
}
