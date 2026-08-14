<?php

declare(strict_types=1);

namespace EduCore\Modules\Attendance\Infrastructure;

use DateTimeImmutable;
use DateTimeZone;
use EduCore\Modules\Attendance\Contracts\AlternativeAttendanceEventRepository;
use PDO;

/** PDO adapter limited to non-biometric events and entry methods owned by Attendance. */
final class PdoAlternativeAttendanceEventRepository implements AlternativeAttendanceEventRepository
{
    private DateTimeZone $utc;

    public function __construct(private PDO $db)
    {
        $this->utc = new DateTimeZone('UTC');
    }

    public function insertAlternativeEntryMethod(array $method): int
    {
        $statement = $this->db->prepare(
            'INSERT INTO staff_attendance_entry_methods
             (code, name, method_type, requires_reason, requires_attachment, requires_review, allowed_scope, status, created_by)
             VALUES (:code, :name, :method_type, 1, :requires_attachment, 1, :allowed_scope, \'active\', :created_by)'
        );
        $statement->execute($method);
        return (int) $this->db->lastInsertId();
    }

    public function activeAlternativeEntryMethods(): array
    {
        return $this->db->query(
            "SELECT id, code, name, method_type, requires_attachment, requires_review, allowed_scope
             FROM staff_attendance_entry_methods
             WHERE status = 'active' AND method_type <> 'biometric' ORDER BY name, id"
        )->fetchAll(PDO::FETCH_ASSOC);
    }

    public function pendingAlternativeEvents(int $limit = 100): array
    {
        $limit = max(1, min(500, $limit));
        return $this->db->query(
            "SELECT event_row.id, event_row.staff_user_id, event_row.event_type, event_row.event_at_local,
                    event_row.recorded_by, event_row.review_status, method_row.name AS method_name,
                    method_row.allowed_scope
             FROM staff_biometric_events event_row
             JOIN staff_attendance_entry_methods method_row ON method_row.id = event_row.entry_method_id
             WHERE event_row.review_status = 'pending' AND method_row.method_type <> 'biometric'
             ORDER BY event_row.id DESC LIMIT " . $limit
        )->fetchAll(PDO::FETCH_ASSOC);
    }

    public function retireAlternativeEntryMethod(int $methodId): bool
    {
        $statement = $this->db->prepare(
            "UPDATE staff_attendance_entry_methods SET status = 'retired' WHERE id = :id AND status = 'active' AND method_type <> 'biometric'"
        );
        $statement->execute(['id' => $methodId]);
        return $statement->rowCount() === 1;
    }

    public function activeAlternativeEntryMethodForUpdate(int $entryMethodId): ?array
    {
        $statement = $this->db->prepare(
            "SELECT id, code, name, method_type, requires_reason, requires_attachment,
                    requires_review, allowed_scope, status
             FROM staff_attendance_entry_methods
             WHERE id = :id
               AND status = 'active'
               AND method_type <> 'biometric'
             FOR UPDATE"
        );
        $statement->execute(['id' => $entryMethodId]);
        $row = $statement->fetch(PDO::FETCH_ASSOC);
        return $row === false ? null : $row;
    }

    public function alternativeEventByIdempotencyForUpdate(string $idempotencyKey): ?array
    {
        $statement = $this->db->prepare(
            "SELECT event_row.id, event_row.entry_method_id, event_row.staff_user_id,
                    event_row.event_type, event_row.event_at_local, event_row.device_event_at,
                    event_row.review_status, event_row.raw_hash, event_row.recorded_by,
                    method_row.allowed_scope, method_row.method_type
             FROM staff_biometric_events event_row
             INNER JOIN staff_attendance_entry_methods method_row ON method_row.id = event_row.entry_method_id
             WHERE event_row.idempotency_key = :idempotency_key
             FOR UPDATE"
        );
        $statement->execute(['idempotency_key' => $idempotencyKey]);
        $row = $statement->fetch(PDO::FETCH_ASSOC);
        return $row === false ? null : $row;
    }

    public function insertAlternativeEvent(array $event): int
    {
        $columns = [
            'batch_id', 'entry_method_id', 'device_id', 'external_event_key',
            'idempotency_key', 'biometric_identity', 'identity_mapping_id',
            'staff_user_id', 'device_event_at', 'received_at', 'device_timezone',
            'normalized_event_at_utc', 'event_at_local', 'clock_offset_seconds',
            'clock_status', 'event_type', 'raw_hash', 'raw_payload_ref',
            'link_status', 'link_reason', 'processing_order', 'recorded_by',
            'reason_text', 'attachment_ref', 'review_status', 'reviewed_by',
            'reviewed_at',
        ];
        $quoted = array_map(static fn (string $column): string => '`' . $column . '`', $columns);
        $placeholders = array_map(static fn (string $column): string => ':' . $column, $columns);
        $statement = $this->db->prepare(
            'INSERT INTO staff_biometric_events (' . implode(', ', $quoted) . ') VALUES ('
            . implode(', ', $placeholders) . ')'
        );
        $params = [];
        foreach ($columns as $column) {
            $params[$column] = $event[$column] ?? null;
        }
        $statement->execute($params);
        return (int) $this->db->lastInsertId();
    }

    public function pendingAlternativeEventForReview(int $eventId): ?array
    {
        $statement = $this->db->prepare(
            "SELECT event_row.id, event_row.entry_method_id, event_row.staff_user_id,
                    event_row.review_status, event_row.recorded_by, method_row.allowed_scope
             FROM staff_biometric_events event_row
             INNER JOIN staff_attendance_entry_methods method_row ON method_row.id = event_row.entry_method_id
             WHERE event_row.id = :id
               AND event_row.review_status = 'pending'
               AND method_row.method_type <> 'biometric'
             FOR UPDATE"
        );
        $statement->execute(['id' => $eventId]);
        $row = $statement->fetch(PDO::FETCH_ASSOC);
        return $row === false ? null : $row;
    }

    public function finalizeAlternativeReview(
        int $eventId,
        string $decision,
        int $reviewerId,
        DateTimeImmutable $reviewedAt
    ): bool {
        $statement = $this->db->prepare(
            "UPDATE staff_biometric_events
             SET review_status = :review_status,
                 reviewed_by = :reviewed_by,
                 reviewed_at = :reviewed_at
             WHERE id = :id
               AND review_status = 'pending'"
        );
        $statement->execute([
            'review_status' => $decision,
            'reviewed_by' => $reviewerId,
            'reviewed_at' => $reviewedAt->setTimezone($this->utc)->format('Y-m-d H:i:s.u'),
            'id' => $eventId,
        ]);
        return $statement->rowCount() === 1;
    }
}
