<?php

declare(strict_types=1);

namespace EduCore\Modules\Staff\Infrastructure;

use EduCore\Modules\Staff\Contracts\ErtaqSlaRepository;
use JsonException;
use PDO;
use PDOException;
use RuntimeException;

/**
 * PDO storage for local Ertaq SLA evidence. It uses row locks for the worker
 * claim and never reads a ticket message, party label, or attachment path.
 */
final class PdoErtaqSlaRepository implements ErtaqSlaRepository
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

    public function ticketForUpdate(int $ticketId): ?array
    {
        $ticket = $this->oneForUpdate(
            'SELECT * FROM staff_ertaq_tickets WHERE id = ? FOR UPDATE',
            [$ticketId]
        );
        if ($ticket === null) {
            return null;
        }
        $ticket['sla_policy_snapshot'] = $this->decodeNullableJson(
            $ticket['sla_policy_snapshot'] ?? null,
            'ERTAQ_SLA_POLICY_SNAPSHOT_CORRUPT'
        );

        return $ticket;
    }

    public function slaEventByIdempotencyForUpdate(string $idempotencyKey): ?array
    {
        return $this->eventForUpdate(
            'SELECT * FROM staff_ertaq_sla_events WHERE idempotency_key = ? FOR UPDATE',
            [$idempotencyKey]
        );
    }

    public function insertSlaEvent(array $event): int
    {
        $statement = $this->db->prepare(
            'INSERT INTO staff_ertaq_sla_events (
                ticket_id, event_type, status, due_at, occurred_at,
                escalation_level, target_team_id, target_user_id,
                escalation_snapshot, event_hash, idempotency_key
            ) VALUES (
                :ticket_id, :event_type, :status, :due_at, :occurred_at,
                :escalation_level, :target_team_id, :target_user_id,
                :escalation_snapshot, :event_hash, :idempotency_key
            )'
        );
        $statement->execute([
            'ticket_id' => (int) $event['ticket_id'],
            'event_type' => (string) $event['event_type'],
            'status' => (string) $event['status'],
            'due_at' => $event['due_at'] ?? null,
            'occurred_at' => $event['occurred_at'] ?? null,
            'escalation_level' => (int) $event['escalation_level'],
            'target_team_id' => $event['target_team_id'] ?? null,
            'target_user_id' => $event['target_user_id'] ?? null,
            'escalation_snapshot' => $this->json($event['escalation_snapshot'] ?? null),
            'event_hash' => (string) $event['event_hash'],
            'idempotency_key' => (string) $event['idempotency_key'],
        ]);

        return (int) $this->db->lastInsertId();
    }

    public function dueSlaEventsForUpdate(string $atInstant, int $limit): array
    {
        if ($limit < 1 || $limit > 100) {
            throw new RuntimeException('ERTAQ_SLA_LIMIT_INVALID');
        }
        $statement = $this->db->prepare(
            "SELECT *
             FROM staff_ertaq_sla_events
             WHERE status = 'scheduled'
               AND event_type IN ('first_response_due', 'overdue')
               AND due_at IS NOT NULL
               AND due_at <= :at_instant
             ORDER BY due_at ASC, id ASC
             LIMIT {$limit}
             FOR UPDATE"
        );
        $statement->execute(['at_instant' => $atInstant]);
        $events = [];
        while (($event = $statement->fetch(PDO::FETCH_ASSOC)) !== false) {
            $events[] = $this->decodedEvent($event);
        }

        return $events;
    }

    public function markSlaEventFired(
        int $eventId,
        int $expectedLockVersion,
        string $occurredAt,
        ?int $targetTeamId,
        ?int $targetUserId,
        array $escalationSnapshot
    ): bool {
        $statement = $this->db->prepare(
            "UPDATE staff_ertaq_sla_events
             SET status = 'fired',
                 occurred_at = :occurred_at,
                 target_team_id = :target_team_id,
                 target_user_id = :target_user_id,
                 escalation_snapshot = :escalation_snapshot,
                 lock_version = lock_version + 1
             WHERE id = :id
               AND status = 'scheduled'
               AND lock_version = :lock_version"
        );
        $statement->execute([
            'id' => $eventId,
            'lock_version' => $expectedLockVersion,
            'occurred_at' => $occurredAt,
            'target_team_id' => $targetTeamId,
            'target_user_id' => $targetUserId,
            'escalation_snapshot' => $this->json($escalationSnapshot),
        ]);

        return $statement->rowCount() === 1;
    }

    public function cancelSlaEvent(
        int $eventId,
        int $expectedLockVersion,
        string $occurredAt
    ): bool {
        $statement = $this->db->prepare(
            "UPDATE staff_ertaq_sla_events
             SET status = 'cancelled',
                 occurred_at = :occurred_at,
                 lock_version = lock_version + 1
             WHERE id = :id
               AND status = 'scheduled'
               AND lock_version = :lock_version"
        );
        $statement->execute([
            'id' => $eventId,
            'lock_version' => $expectedLockVersion,
            'occurred_at' => $occurredAt,
        ]);

        return $statement->rowCount() === 1;
    }

    public function renewTicketSlaWindow(
        int $ticketId,
        int $expectedLockVersion,
        ?string $firstResponseDueAt,
        ?string $slaDueAt
    ): bool {
        $statement = $this->db->prepare(
            "UPDATE staff_ertaq_tickets
             SET first_response_due_at = :first_response_due_at,
                 sla_due_at = :sla_due_at
             WHERE id = :id
               AND status = 'reopened'
               AND lock_version = :lock_version"
        );
        $statement->execute([
            'id' => $ticketId,
            'lock_version' => $expectedLockVersion,
            'first_response_due_at' => $firstResponseDueAt,
            'sla_due_at' => $slaDueAt,
        ]);

        return $statement->rowCount() === 1;
    }

    /** @param list<mixed> $params @return array<string,mixed>|null */
    private function oneForUpdate(string $sql, array $params): ?array
    {
        $statement = $this->db->prepare($sql);
        $statement->execute($params);
        $row = $statement->fetch(PDO::FETCH_ASSOC);

        return $row === false ? null : $row;
    }

    /** @param list<mixed> $params @return array<string,mixed>|null */
    private function eventForUpdate(string $sql, array $params): ?array
    {
        $event = $this->oneForUpdate($sql, $params);

        return $event === null ? null : $this->decodedEvent($event);
    }

    /** @param array<string,mixed> $event @return array<string,mixed> */
    private function decodedEvent(array $event): array
    {
        $event['escalation_snapshot'] = $this->decodeJson(
            $event['escalation_snapshot'] ?? null,
            'ERTAQ_SLA_EVENT_SNAPSHOT_CORRUPT'
        );

        return $event;
    }

    private function json(mixed $value): string
    {
        if (!is_array($value)) {
            throw new RuntimeException('ERTAQ_SLA_JSON_INVALID');
        }
        try {
            return json_encode($value, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        } catch (JsonException) {
            throw new RuntimeException('ERTAQ_SLA_JSON_INVALID');
        }
    }

    private function decodeNullableJson(mixed $value, string $error): ?array
    {
        if ($value === null || $value === '') {
            return null;
        }

        return $this->decodeJson($value, $error);
    }

    private function decodeJson(mixed $value, string $error): array
    {
        if (!is_string($value) || $value === '') {
            throw new RuntimeException($error);
        }
        try {
            $decoded = json_decode($value, true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            throw new RuntimeException($error);
        }
        if (!is_array($decoded)) {
            throw new RuntimeException($error);
        }

        return $decoded;
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
