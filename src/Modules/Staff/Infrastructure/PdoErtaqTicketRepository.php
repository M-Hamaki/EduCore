<?php

declare(strict_types=1);

namespace EduCore\Modules\Staff\Infrastructure;

use EduCore\Modules\Staff\Contracts\ErtaqTicketRepository;
use JsonException;
use PDO;
use PDOException;
use RuntimeException;

/**
 * PDO persistence for the Staff-owned Ertaq ticket and assignment aggregate.
 *
 * It locks only users and Ertaq records. Route, team, party, discipline,
 * notification, file and Finance ownership remain outside this adapter.
 */
final class PdoErtaqTicketRepository implements ErtaqTicketRepository
{
    public function __construct(private PDO $db)
    {
    }

    public function transactional(callable $work): mixed
    {
        $ownsTransaction = !$this->db->inTransaction();
        $attempt = 0;
        do {
            if ($ownsTransaction) {
                $this->db->beginTransaction();
            }
            try {
                $result = $work();
                if ($ownsTransaction) {
                    $this->db->commit();
                }

                return $result;
            } catch (\Throwable $exception) {
                if ($ownsTransaction && $this->db->inTransaction()) {
                    $this->db->rollBack();
                }
                if (!$ownsTransaction || !$this->isRetryableConcurrencyFailure($exception) || ++$attempt >= 4) {
                    throw $exception;
                }
                usleep(5000 * $attempt);
            }
        } while (true);
    }

    public function lockUser(int $userId): bool
    {
        $statement = $this->db->prepare('SELECT id FROM users WHERE id = ? FOR UPDATE');
        $statement->execute([$userId]);

        return $statement->fetchColumn() !== false;
    }

    public function ticketByCreateIdempotencyForUpdate(string $idempotencyKey): ?array
    {
        return $this->ticketForUpdateSql(
            'SELECT * FROM staff_ertaq_tickets WHERE create_idempotency_key = ? FOR UPDATE',
            [$idempotencyKey]
        );
    }

    public function ticketForUpdate(int $ticketId): ?array
    {
        return $this->ticketForUpdateSql(
            'SELECT * FROM staff_ertaq_tickets WHERE id = ? FOR UPDATE',
            [$ticketId]
        );
    }

    public function insertTicket(array $ticket): int
    {
        $statement = $this->db->prepare(
            "INSERT INTO staff_ertaq_tickets (
                ticket_no, requester_user_id, type, classification,
                confidentiality_level, priority, risk_level, subject, status,
                sla_policy_id, sla_policy_snapshot, first_response_due_at, sla_due_at,
                create_idempotency_key, ticket_hash
            ) VALUES (
                :ticket_no, :requester_user_id, :type, :classification,
                :confidentiality_level, :priority, :risk_level, :subject, 'new',
                :sla_policy_id, :sla_policy_snapshot, :first_response_due_at, :sla_due_at,
                :create_idempotency_key, :ticket_hash
            )"
        );
        $statement->execute([
            'ticket_no' => (string) $ticket['ticket_no'],
            'requester_user_id' => (int) $ticket['requester_user_id'],
            'type' => (string) $ticket['type'],
            'classification' => (string) $ticket['classification'],
            'confidentiality_level' => (string) $ticket['confidentiality_level'],
            'priority' => (string) $ticket['priority'],
            'risk_level' => (string) $ticket['risk_level'],
            'subject' => (string) $ticket['subject'],
            'sla_policy_id' => $ticket['sla_policy_id'] ?? null,
            'sla_policy_snapshot' => $this->json($ticket['sla_policy_snapshot'] ?? null),
            'first_response_due_at' => $ticket['first_response_due_at'] ?? null,
            'sla_due_at' => $ticket['sla_due_at'] ?? null,
            'create_idempotency_key' => (string) $ticket['create_idempotency_key'],
            'ticket_hash' => (string) $ticket['ticket_hash'],
        ]);

        return (int) $this->db->lastInsertId();
    }

    public function transitionTicket(
        int $ticketId,
        int $expectedLockVersion,
        string $fromStatus,
        string $toStatus,
        array $changes
    ): bool {
        $allowedColumns = [
            'classification' => 'classification',
            'confidentiality_level' => 'confidentiality_level',
            'priority' => 'priority',
            'risk_level' => 'risk_level',
            'sla_policy_id' => 'sla_policy_id',
            'sla_policy_snapshot' => 'sla_policy_snapshot',
            'first_response_due_at' => 'first_response_due_at',
            'sla_due_at' => 'sla_due_at',
            'resolution_summary' => 'resolution_summary',
            'resolved_at' => 'resolved_at',
            'resolved_by_user_id' => 'resolved_by_user_id',
            'closure_reason' => 'closure_reason',
            'closed_at' => 'closed_at',
            'closed_by_user_id' => 'closed_by_user_id',
            'reopen_reason' => 'reopen_reason',
            'reopened_at' => 'reopened_at',
            'reopened_by_user_id' => 'reopened_by_user_id',
        ];
        $sets = ['status = :to_status', 'lock_version = lock_version + 1'];
        $params = [
            'id' => $ticketId,
            'lock_version' => $expectedLockVersion,
            'from_status' => $fromStatus,
            'to_status' => $toStatus,
        ];
        foreach ($allowedColumns as $input => $column) {
            if (!array_key_exists($input, $changes)) {
                continue;
            }
            $sets[] = $column . ' = :' . $input;
            $params[$input] = $input === 'sla_policy_snapshot'
                ? $this->json($changes[$input])
                : $changes[$input];
        }
        $statement = $this->db->prepare(
            'UPDATE staff_ertaq_tickets
             SET ' . implode(', ', $sets) . '
             WHERE id = :id
               AND status = :from_status
               AND lock_version = :lock_version'
        );
        $statement->execute($params);

        return $statement->rowCount() === 1;
    }

    public function assignmentByIdempotencyForUpdate(string $idempotencyKey): ?array
    {
        return $this->oneForUpdate(
            'SELECT * FROM staff_ertaq_assignments WHERE idempotency_key = ? FOR UPDATE',
            [$idempotencyKey]
        );
    }

    public function activeAssignmentForTicketForUpdate(int $ticketId): ?array
    {
        return $this->oneForUpdate(
            "SELECT *
             FROM staff_ertaq_assignments
             WHERE ticket_id = ?
               AND status IN ('active', 'accepted')
             ORDER BY id DESC
             LIMIT 1
             FOR UPDATE",
            [$ticketId]
        );
    }

    public function insertAssignment(array $assignment): int
    {
        $statement = $this->db->prepare(
            "INSERT INTO staff_ertaq_assignments (
                ticket_id, assigned_team_id, assigned_to_user_id, assigned_by_user_id,
                assignment_reason, status, assigned_at, supersedes_assignment_id,
                idempotency_key, assignment_hash
            ) VALUES (
                :ticket_id, :assigned_team_id, :assigned_to_user_id, :assigned_by_user_id,
                :assignment_reason, 'active', :assigned_at, :supersedes_assignment_id,
                :idempotency_key, :assignment_hash
            )"
        );
        $statement->execute([
            'ticket_id' => (int) $assignment['ticket_id'],
            'assigned_team_id' => $assignment['assigned_team_id'] ?? null,
            'assigned_to_user_id' => $assignment['assigned_to_user_id'] ?? null,
            'assigned_by_user_id' => (int) $assignment['assigned_by_user_id'],
            'assignment_reason' => $assignment['assignment_reason'] ?? null,
            'assigned_at' => (string) $assignment['assigned_at'],
            'supersedes_assignment_id' => $assignment['supersedes_assignment_id'] ?? null,
            'idempotency_key' => (string) $assignment['idempotency_key'],
            'assignment_hash' => (string) $assignment['assignment_hash'],
        ]);

        return (int) $this->db->lastInsertId();
    }

    public function supersedeAssignment(
        int $assignmentId,
        int $expectedLockVersion,
        int $actorId,
        string $endedAt,
        string $reason
    ): bool {
        $statement = $this->db->prepare(
            "UPDATE staff_ertaq_assignments
             SET status = 'superseded',
                 ended_at = :ended_at,
                 ended_by_user_id = :actor_id,
                 end_reason = :reason,
                 lock_version = lock_version + 1
             WHERE id = :id
               AND status IN ('active', 'accepted')
               AND lock_version = :lock_version"
        );
        $statement->execute([
            'id' => $assignmentId,
            'lock_version' => $expectedLockVersion,
            'actor_id' => $actorId,
            'ended_at' => $endedAt,
            'reason' => $reason,
        ]);

        return $statement->rowCount() === 1;
    }

    /** @param list<mixed> $params @return array<string,mixed>|null */
    private function ticketForUpdateSql(string $sql, array $params): ?array
    {
        $ticket = $this->oneForUpdate($sql, $params);
        if ($ticket === null) {
            return null;
        }
        $ticket['sla_policy_snapshot'] = $this->decodeJson(
            $ticket['sla_policy_snapshot'] ?? null,
            'ERTAQ_SLA_SNAPSHOT_CORRUPT'
        );

        return $ticket;
    }

    /** @param list<mixed> $params @return array<string,mixed>|null */
    private function oneForUpdate(string $sql, array $params): ?array
    {
        $statement = $this->db->prepare($sql);
        $statement->execute($params);
        $row = $statement->fetch(PDO::FETCH_ASSOC);

        return $row === false ? null : $row;
    }

    private function json(mixed $value): ?string
    {
        if ($value === null) {
            return null;
        }
        try {
            return json_encode($value, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        } catch (JsonException) {
            throw new RuntimeException('ERTAQ_JSON_SERIALIZATION_FAILED');
        }
    }

    private function decodeJson(mixed $value, string $error): mixed
    {
        if ($value === null || $value === '') {
            return null;
        }
        if (is_array($value)) {
            return $value;
        }
        if (!is_string($value)) {
            throw new RuntimeException($error);
        }
        try {
            return json_decode($value, true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            throw new RuntimeException($error);
        }
    }

    private function isRetryableConcurrencyFailure(\Throwable $exception): bool
    {
        if (!$exception instanceof PDOException) {
            return false;
        }
        $code = (string) $exception->getCode();
        if (in_array($code, ['40001', '1213'], true)) {
            return true;
        }
        $message = strtolower($exception->getMessage());

        return str_contains($message, 'deadlock') || str_contains($message, 'serialization failure');
    }
}
