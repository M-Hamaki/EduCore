<?php

declare(strict_types=1);

namespace EduCore\Modules\Staff\Infrastructure;

use EduCore\Modules\Staff\Contracts\ErtaqUrgentRoutingRepository;
use JsonException;
use PDO;
use PDOException;
use RuntimeException;

/**
 * PDO persistence for Staff-owned urgent Ertaq route evidence. It reads party
 * identifiers only to build a conflict exclusion set; it never exposes their
 * labels/content or writes an external protection route.
 */
final class PdoErtaqUrgentRoutingRepository implements ErtaqUrgentRoutingRepository
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
        return $this->oneForUpdate(
            'SELECT * FROM staff_ertaq_tickets WHERE id = ? FOR UPDATE',
            [$ticketId]
        );
    }

    public function protectedPartyUserIdsForTicketForUpdate(int $ticketId): array
    {
        $statement = $this->db->prepare(
            "SELECT protected.user_id FROM (
                SELECT t.requester_user_id AS user_id
                FROM staff_ertaq_tickets t WHERE t.id = ?
                UNION
                SELECT p.party_user_id AS user_id
                FROM staff_ertaq_parties p
                WHERE p.ticket_id = ? AND p.party_user_id IS NOT NULL
                  AND (p.party_role = 'accused' OR p.conflict_status IN ('declared', 'confirmed', 'excluded'))
                UNION
                SELECT ma.manager_user_id AS user_id
                FROM staff_ertaq_tickets t
                JOIN staff_assignments a ON a.staff_user_id = t.requester_user_id
                    AND a.assignment_kind = 'primary' AND a.employment_status = 'active'
                    AND a.valid_from <= CURRENT_DATE AND (a.valid_to IS NULL OR a.valid_to >= CURRENT_DATE)
                JOIN staff_manager_assignments ma ON ma.status = 'active'
                    AND ma.valid_from <= CURRENT_DATE AND (ma.valid_to IS NULL OR ma.valid_to >= CURRENT_DATE)
                    AND ((ma.subject_type = 'staff' AND ma.subject_id = t.requester_user_id)
                      OR (ma.subject_type = 'org_unit' AND ma.subject_id = a.org_unit_id))
                WHERE t.id = ?
             ) protected
             WHERE protected.user_id IS NOT NULL
             FOR UPDATE"
        );
        $statement->execute([$ticketId, $ticketId, $ticketId]);
        $ids = [];
        while (($value = $statement->fetchColumn()) !== false) {
            $ids[] = (int) $value;
        }

        return $ids;
    }

    public function urgentByIdempotencyForUpdate(string $idempotencyKey): ?array
    {
        return $this->eventForUpdate(
            'SELECT * FROM staff_ertaq_urgent_events WHERE idempotency_key = ? FOR UPDATE',
            [$idempotencyKey]
        );
    }

    public function urgentForTicketForUpdate(int $ticketId): ?array
    {
        return $this->eventForUpdate(
            'SELECT * FROM staff_ertaq_urgent_events WHERE ticket_id = ? FOR UPDATE',
            [$ticketId]
        );
    }

    public function urgentEventForUpdate(int $urgentEventId): ?array
    {
        return $this->eventForUpdate(
            'SELECT * FROM staff_ertaq_urgent_events WHERE id = ? FOR UPDATE',
            [$urgentEventId]
        );
    }

    public function insertUrgentEvent(array $event): int
    {
        $statement = $this->db->prepare(
            "INSERT INTO staff_ertaq_urgent_events (
                ticket_id, risk_type, routed_team_id, routed_by_user_id,
                route_snapshot, conflict_exclusion_snapshot, status, routed_at,
                idempotency_key, urgent_hash
            ) VALUES (
                :ticket_id, :risk_type, :routed_team_id, :routed_by_user_id,
                :route_snapshot, :conflict_exclusion_snapshot, 'routed', :routed_at,
                :idempotency_key, :urgent_hash
            )"
        );
        $statement->execute([
            'ticket_id' => (int) $event['ticket_id'],
            'risk_type' => (string) $event['risk_type'],
            'routed_team_id' => (int) $event['routed_team_id'],
            'routed_by_user_id' => $event['routed_by_user_id'] ?? null,
            'route_snapshot' => $this->json($event['route_snapshot'] ?? null),
            'conflict_exclusion_snapshot' => $this->json($event['conflict_exclusion_snapshot'] ?? null),
            'routed_at' => (string) $event['routed_at'],
            'idempotency_key' => (string) $event['idempotency_key'],
            'urgent_hash' => (string) $event['urgent_hash'],
        ]);

        return (int) $this->db->lastInsertId();
    }

    public function transitionTicketToUrgent(
        int $ticketId,
        int $expectedLockVersion,
        string $fromStatus
    ): bool {
        $statement = $this->db->prepare(
            "UPDATE staff_ertaq_tickets
             SET status = 'urgent_protected',
                 lock_version = lock_version + 1
             WHERE id = :id
               AND status = :from_status
               AND lock_version = :lock_version"
        );
        $statement->execute([
            'id' => $ticketId,
            'from_status' => $fromStatus,
            'lock_version' => $expectedLockVersion,
        ]);

        return $statement->rowCount() === 1;
    }

    public function acknowledgeUrgentEvent(
        int $urgentEventId,
        int $expectedLockVersion,
        int $actorId,
        string $acknowledgedAt
    ): bool {
        $statement = $this->db->prepare(
            "UPDATE staff_ertaq_urgent_events
             SET status = 'acknowledged',
                 acknowledged_at = :acknowledged_at,
                 acknowledged_by_user_id = :actor_id,
                 lock_version = lock_version + 1
             WHERE id = :id
               AND status = 'routed'
               AND lock_version = :lock_version"
        );
        $statement->execute([
            'id' => $urgentEventId,
            'lock_version' => $expectedLockVersion,
            'actor_id' => $actorId,
            'acknowledged_at' => $acknowledgedAt,
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
        if ($event === null) {
            return null;
        }
        $event['route_snapshot'] = $this->decodeJson(
            $event['route_snapshot'] ?? null,
            'ERTAQ_URGENT_ROUTE_SNAPSHOT_CORRUPT'
        );
        $event['conflict_exclusion_snapshot'] = $this->decodeJson(
            $event['conflict_exclusion_snapshot'] ?? null,
            'ERTAQ_URGENT_EXCLUSION_SNAPSHOT_CORRUPT'
        );

        return $event;
    }

    private function json(mixed $value): string
    {
        if (!is_array($value)) {
            throw new RuntimeException('ERTAQ_URGENT_JSON_INVALID');
        }
        try {
            return json_encode($value, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        } catch (JsonException) {
            throw new RuntimeException('ERTAQ_URGENT_JSON_INVALID');
        }
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
