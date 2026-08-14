<?php

declare(strict_types=1);

namespace EduCore\Modules\Attendance\Infrastructure;

use DateTimeImmutable;
use DateTimeZone;
use EduCore\Modules\Attendance\Contracts\AttendanceEventRepository;
use EduCore\Modules\Attendance\Contracts\AttendanceEntryMethodQuery;
use JsonException;
use PDO;

/** PDO adapter limited to Attendance-owned batch and raw-event tables. */
final class PdoAttendanceEventRepository implements AttendanceEventRepository, AttendanceEntryMethodQuery
{
    private DateTimeZone $utc;

    public function __construct(private PDO $db)
    {
        $this->utc = new DateTimeZone('UTC');
    }

    public function activeBiometricEntryMethod(int $entryMethodId): ?array
    {
        $statement = $this->db->prepare(
            "SELECT *
             FROM staff_attendance_entry_methods
             WHERE id = ? AND status = 'active' AND method_type = 'biometric'
             FOR UPDATE"
        );
        $statement->execute([$entryMethodId]);
        $row = $statement->fetch(PDO::FETCH_ASSOC);
        return $row === false ? null : $row;
    }

    public function activeBiometricMethods(): array
    {
        $statement = $this->db->query(
            "SELECT id, code, name, method_type
             FROM staff_attendance_entry_methods
             WHERE status = 'active' AND method_type = 'biometric'
               AND requires_reason = 0 AND requires_attachment = 0
             ORDER BY name, id"
        );
        return array_values($statement->fetchAll(PDO::FETCH_ASSOC));
    }

    public function batchesForUpdate(string $idempotencyKey, ?string $fileFingerprint): array
    {
        $sql = 'SELECT * FROM staff_biometric_import_batches WHERE idempotency_key = :idempotency_key';
        $params = ['idempotency_key' => $idempotencyKey];
        if ($fileFingerprint !== null) {
            $sql .= ' OR file_fingerprint = :file_fingerprint';
            $params['file_fingerprint'] = $fileFingerprint;
        }
        $sql .= ' ORDER BY id FOR UPDATE';
        $statement = $this->db->prepare($sql);
        $statement->execute($params);
        return array_values($statement->fetchAll(PDO::FETCH_ASSOC));
    }

    public function insertBatch(array $batch): int
    {
        $statement = $this->db->prepare(
            'INSERT INTO staff_biometric_import_batches
                (source_type, device_id, file_fingerprint, idempotency_key,
                 request_hash, started_at, status, initiated_by)
             VALUES
                (:source_type, :device_id, :file_fingerprint, :idempotency_key,
                 :request_hash, :started_at, :status, :initiated_by)'
        );
        $statement->execute([
            'source_type' => $batch['source_type'],
            'device_id' => $batch['device_id'],
            'file_fingerprint' => $batch['file_fingerprint'],
            'idempotency_key' => $batch['idempotency_key'],
            'request_hash' => $batch['request_hash'],
            'started_at' => $batch['started_at'],
            'status' => $batch['status'],
            'initiated_by' => $batch['initiated_by'],
        ]);
        return (int) $this->db->lastInsertId();
    }

    public function finishBatch(
        int $batchId,
        string $status,
        DateTimeImmutable $finishedAt,
        array $result
    ): void {
        try {
            $json = json_encode(
                $result,
                JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
            );
        } catch (JsonException $exception) {
            throw new \RuntimeException('BIOMETRIC_BATCH_RESULT_INVALID', 0, $exception);
        }
        $statement = $this->db->prepare(
            "UPDATE staff_biometric_import_batches
             SET status = :status, finished_at = :finished_at,
                 row_counts = :row_counts, error_summary = NULL
             WHERE id = :id AND status = 'processing' AND finished_at IS NULL"
        );
        $statement->execute([
            'status' => $status,
            'finished_at' => $this->databaseInstant($finishedAt),
            'row_counts' => $json,
            'id' => $batchId,
        ]);
        if ($statement->rowCount() !== 1) {
            throw new \RuntimeException('BIOMETRIC_BATCH_STATE_STALE');
        }
    }

    public function duplicateEventsForUpdate(
        int $deviceId,
        string $idempotencyKey,
        ?string $externalEventKey,
        string $rawHash
    ): array {
        $predicates = [
            'idempotency_key = :idempotency_key',
            '(device_id = :raw_device_id AND raw_hash = :raw_hash)',
        ];
        $params = [
            'idempotency_key' => $idempotencyKey,
            'raw_device_id' => $deviceId,
            'raw_hash' => $rawHash,
        ];
        if ($externalEventKey !== null) {
            $predicates[] = '(device_id = :external_device_id AND external_event_key = :external_event_key)';
            $params['external_device_id'] = $deviceId;
            $params['external_event_key'] = $externalEventKey;
        }
        $statement = $this->db->prepare(
            'SELECT * FROM staff_biometric_events WHERE '
            . implode(' OR ', $predicates)
            . ' ORDER BY id FOR UPDATE'
        );
        $statement->execute($params);
        return array_values($statement->fetchAll(PDO::FETCH_ASSOC));
    }

    public function insertEvent(array $event): int
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

    private function databaseInstant(DateTimeImmutable $instant): string
    {
        return $instant->setTimezone($this->utc)->format('Y-m-d H:i:s.u');
    }
}
