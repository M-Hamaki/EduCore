<?php

declare(strict_types=1);

namespace EduCore\Modules\Staff\Infrastructure;

use EduCore\Modules\Staff\Contracts\ErtaqInboxReadRepository;
use PDO;

/**
 * PDO implementation of the narrow Ertaq inbox contract.
 *
 * Every ticket/detail/message query carries its requester or current direct
 * assignee predicate. It deliberately does not join identity, party, watcher,
 * route, attachment, or external-module tables, because those records need a
 * different, explicitly granted read contract.
 */
final class PdoErtaqInboxReadRepository implements ErtaqInboxReadRepository
{
    /** @var list<string> */
    private const STATUSES = [
        'new', 'triaged', 'assigned', 'in_progress', 'awaiting_requester',
        'resolved', 'closed', 'reopened', 'withdrawal_requested',
        'urgent_protected', 'cancelled',
    ];

    /** @var list<string> */
    private const PRIORITIES = ['low', 'normal', 'high', 'urgent'];

    public function __construct(private PDO $db)
    {
    }

    public function requesterTickets(int $requesterUserId, array $filters): array
    {
        [$where, $params] = $this->ticketFilters($filters, 't', false);
        $params['requester_user_id'] = $requesterUserId;
        $params['limit'] = $this->limit($filters);

        return $this->all(
            'SELECT t.id, t.ticket_no, t.type, t.subject, t.confidentiality_level,
                    t.status, t.lock_version, t.created_at, t.updated_at
             FROM staff_ertaq_tickets t
             WHERE t.requester_user_id = :requester_user_id' . $where . '
             ORDER BY t.updated_at DESC, t.id DESC
             LIMIT :limit',
            $params
        );
    }

    public function requesterTicketCount(int $requesterUserId, array $filters): int
    {
        [$where, $params] = $this->ticketFilters($filters, 't', false);
        $params['requester_user_id'] = $requesterUserId;

        return $this->count(
            'SELECT COUNT(*)
             FROM staff_ertaq_tickets t
             WHERE t.requester_user_id = :requester_user_id' . $where,
            $params
        );
    }

    public function requesterTicket(int $requesterUserId, int $ticketId): ?array
    {
        return $this->one(
            'SELECT t.id, t.ticket_no, t.type, t.subject, t.confidentiality_level,
                    t.status, t.lock_version, t.created_at, t.updated_at
             FROM staff_ertaq_tickets t
             WHERE t.id = :ticket_id AND t.requester_user_id = :requester_user_id
             LIMIT 1',
            ['ticket_id' => $ticketId, 'requester_user_id' => $requesterUserId]
        );
    }

    public function requesterMessages(int $requesterUserId, int $ticketId): array
    {
        return $this->all(
            "SELECT m.id, m.message_type, m.body_cipher_or_text AS body, m.sent_at
             FROM staff_ertaq_messages m
             INNER JOIN staff_ertaq_tickets t ON t.id = m.ticket_id
             WHERE m.ticket_id = :ticket_id
               AND t.requester_user_id = :requester_user_id
               AND m.visibility = 'requester'
             ORDER BY m.sent_at ASC, m.id ASC",
            ['ticket_id' => $ticketId, 'requester_user_id' => $requesterUserId]
        );
    }

    public function assignedTickets(int $assigneeUserId, array $filters): array
    {
        [$where, $params] = $this->ticketFilters($filters, 't', true);
        $params['assignee_user_id'] = $assigneeUserId;
        $params['limit'] = $this->limit($filters);

        return $this->all(
            "SELECT t.id, t.ticket_no, t.type, t.subject, t.classification,
                    t.confidentiality_level, t.priority, t.status, t.lock_version,
                    t.first_response_due_at, t.sla_due_at, t.created_at, t.updated_at,
                    a.status AS assignment_status, a.assigned_at
             FROM staff_ertaq_tickets t
             INNER JOIN (
                    SELECT ticket_id, MAX(id) AS assignment_id
                    FROM staff_ertaq_assignments
                    WHERE assigned_to_user_id = :assignee_user_id
                      AND status IN ('active', 'accepted')
                    GROUP BY ticket_id
             ) current_assignment ON current_assignment.ticket_id = t.id
             INNER JOIN staff_ertaq_assignments a ON a.id = current_assignment.assignment_id
             WHERE 1 = 1" . $where . "
             ORDER BY
                CASE WHEN t.sla_due_at IS NOT NULL AND t.sla_due_at < NOW(6) THEN 0 ELSE 1 END,
                t.sla_due_at ASC,
                t.updated_at DESC,
                t.id DESC
             LIMIT :limit",
            $params
        );
    }

    public function assignedTicketCount(int $assigneeUserId, array $filters): int
    {
        [$where, $params] = $this->ticketFilters($filters, 't', true);
        $params['assignee_user_id'] = $assigneeUserId;

        return $this->count(
            "SELECT COUNT(DISTINCT t.id)
             FROM staff_ertaq_tickets t
             INNER JOIN staff_ertaq_assignments a
                ON a.ticket_id = t.id
               AND a.assigned_to_user_id = :assignee_user_id
               AND a.status IN ('active', 'accepted')
             WHERE 1 = 1" . $where,
            $params
        );
    }

    public function assignedSummary(int $assigneeUserId): array
    {
        $row = $this->one(
            "SELECT COUNT(DISTINCT t.id) AS total,
                    COUNT(DISTINCT CASE
                        WHEN t.sla_due_at IS NOT NULL
                         AND t.sla_due_at < NOW(6)
                         AND t.status NOT IN ('closed', 'cancelled', 'urgent_protected')
                        THEN t.id END) AS overdue,
                    COUNT(DISTINCT CASE
                        WHEN t.priority = 'urgent' THEN t.id END) AS urgent
             FROM staff_ertaq_tickets t
             INNER JOIN staff_ertaq_assignments a
                ON a.ticket_id = t.id
               AND a.assigned_to_user_id = :assignee_user_id
               AND a.status IN ('active', 'accepted')",
            ['assignee_user_id' => $assigneeUserId]
        ) ?? [];

        return [
            'total' => max(0, (int) ($row['total'] ?? 0)),
            'overdue' => max(0, (int) ($row['overdue'] ?? 0)),
            'urgent' => max(0, (int) ($row['urgent'] ?? 0)),
        ];
    }

    public function assignedTicket(int $assigneeUserId, int $ticketId): ?array
    {
        return $this->one(
            "SELECT t.id, t.ticket_no, t.type, t.subject, t.classification,
                    t.confidentiality_level, t.priority, t.status, t.lock_version,
                    t.first_response_due_at, t.sla_due_at, t.created_at, t.updated_at,
                    a.status AS assignment_status, a.assigned_at
             FROM staff_ertaq_tickets t
             INNER JOIN (
                    SELECT ticket_id, MAX(id) AS assignment_id
                    FROM staff_ertaq_assignments
                    WHERE assigned_to_user_id = :assignee_user_id
                      AND status IN ('active', 'accepted')
                    GROUP BY ticket_id
             ) current_assignment ON current_assignment.ticket_id = t.id
             INNER JOIN staff_ertaq_assignments a ON a.id = current_assignment.assignment_id
             WHERE t.id = :ticket_id
             LIMIT 1",
            ['ticket_id' => $ticketId, 'assignee_user_id' => $assigneeUserId]
        );
    }

    public function assignedMessages(int $assigneeUserId, int $ticketId): array
    {
        return $this->all(
            "SELECT m.id, m.message_type, m.body_cipher_or_text AS body, m.sent_at
             FROM staff_ertaq_messages m
             WHERE m.ticket_id = :ticket_id
               AND m.visibility IN ('requester', 'assigned_team')
               AND EXISTS (
                    SELECT 1
                    FROM staff_ertaq_assignments a
                    WHERE a.ticket_id = m.ticket_id
                      AND a.assigned_to_user_id = :assignee_user_id
                      AND a.status IN ('active', 'accepted')
               )
             ORDER BY m.sent_at ASC, m.id ASC",
            ['ticket_id' => $ticketId, 'assignee_user_id' => $assigneeUserId]
        );
    }

    public function ticketExists(int $ticketId): bool
    {
        return $this->one(
            'SELECT id FROM staff_ertaq_tickets WHERE id = :ticket_id LIMIT 1',
            ['ticket_id' => $ticketId]
        ) !== null;
    }

    /** @param array<string,mixed> $filters @return array{0:string,1:array<string,mixed>} */
    private function ticketFilters(array $filters, string $alias, bool $allowsPriority): array
    {
        $where = '';
        $params = [];
        $status = trim((string) ($filters['status'] ?? ''));
        if ($status !== '' && in_array($status, self::STATUSES, true)) {
            $where .= ' AND ' . $alias . '.status = :filter_status';
            $params['filter_status'] = $status;
        }
        $priority = trim((string) ($filters['priority'] ?? ''));
        if ($allowsPriority && $priority !== '' && in_array($priority, self::PRIORITIES, true)) {
            $where .= ' AND ' . $alias . '.priority = :filter_priority';
            $params['filter_priority'] = $priority;
        }
        $query = trim((string) ($filters['query'] ?? ''));
        if ($query !== '') {
            $escaped = str_replace(['!', '%', '_'], ['!!', '!%', '!_'], $query);
            $where .= ' AND (' . $alias . ".ticket_no LIKE :filter_ticket_query ESCAPE '!'"
                . ' OR ' . $alias . ".subject LIKE :filter_subject_query ESCAPE '!')";
            $params['filter_ticket_query'] = '%' . $escaped . '%';
            $params['filter_subject_query'] = '%' . $escaped . '%';
        }

        return [$where, $params];
    }

    /** @param array<string,mixed> $filters */
    private function limit(array $filters): int
    {
        return min(100, max(1, (int) ($filters['limit'] ?? 50)));
    }

    /** @param array<string,mixed> $params @return list<array<string,mixed>> */
    private function all(string $sql, array $params): array
    {
        $statement = $this->db->prepare($sql);
        $this->bind($statement, $params);
        $statement->execute();
        $rows = $statement->fetchAll(PDO::FETCH_ASSOC);

        return is_array($rows) ? $rows : [];
    }

    /** @param array<string,mixed> $params @return array<string,mixed>|null */
    private function one(string $sql, array $params): ?array
    {
        $statement = $this->db->prepare($sql);
        $this->bind($statement, $params);
        $statement->execute();
        $row = $statement->fetch(PDO::FETCH_ASSOC);

        return is_array($row) ? $row : null;
    }

    /** @param array<string,mixed> $params */
    private function count(string $sql, array $params): int
    {
        $statement = $this->db->prepare($sql);
        $this->bind($statement, $params);
        $statement->execute();

        return max(0, (int) $statement->fetchColumn());
    }

    /** @param array<string,mixed> $params */
    private function bind(\PDOStatement $statement, array $params): void
    {
        foreach ($params as $name => $value) {
            $statement->bindValue(':' . $name, $value, is_int($value) ? PDO::PARAM_INT : PDO::PARAM_STR);
        }
    }
}
