<?php

declare(strict_types=1);

namespace EduCore\Modules\Attendance\Infrastructure;

use DateTimeImmutable;
use DateTimeZone;
use EduCore\Modules\Attendance\Contracts\AttendanceEventWindowQuery;
use EduCore\Modules\Attendance\Contracts\AttendanceRecalculationRepository;
use EduCore\Modules\Attendance\Contracts\AttendanceShadowRunRepository;
use JsonException;
use PDO;
use RuntimeException;

/**
 * Attendance-owned persistence adapter for non-official shadow calculations.
 * Raw evidence is deliberately projected to the minimum fields needed by the
 * calculator; payload, biometric identity, and attachment references never
 * leave this adapter.
 */
final class PdoAttendanceShadowRunRepository implements
    AttendanceShadowRunRepository,
    AttendanceRecalculationRepository,
    AttendanceEventWindowQuery
{
    private DateTimeZone $utc;

    public function __construct(private PDO $db)
    {
        $this->utc = new DateTimeZone('UTC');
    }

    public function runByIdempotencyForUpdate(string $idempotencyKey): ?array
    {
        $statement = $this->db->prepare(
            'SELECT * FROM staff_attendance_runs WHERE idempotency_key = :idempotency_key FOR UPDATE'
        );
        $statement->execute(['idempotency_key' => $idempotencyKey]);
        $row = $statement->fetch(PDO::FETCH_ASSOC);
        return $row === false ? null : $row;
    }

    public function insertShadowRun(array $run): int
    {
        $columns = [
            'engine_version', 'mode', 'range_from', 'range_to', 'cutoff_at', 'initiated_by',
            'status', 'source_fingerprint', 'idempotency_key', 'supersedes_run_id',
        ];
        $statement = $this->db->prepare(
            'INSERT INTO staff_attendance_runs (' . implode(', ', $columns) . ') VALUES ('
            . implode(', ', array_map(static fn (string $column): string => ':' . $column, $columns)) . ')'
        );
        $params = [];
        foreach ($columns as $column) {
            $params[$column] = $run[$column] ?? null;
        }
        $statement->execute($params);
        return (int) $this->db->lastInsertId();
    }

    public function startShadowRun(int $runId, DateTimeImmutable $startedAt): bool
    {
        $statement = $this->db->prepare(
            "UPDATE staff_attendance_runs
             SET status = 'running', started_at = :started_at
             WHERE id = :id AND mode = 'shadow' AND status = 'queued'"
        );
        $statement->execute([
            'started_at' => $this->databaseInstant($startedAt),
            'id' => $runId,
        ]);
        return $statement->rowCount() === 1;
    }

    public function completeShadowRun(int $runId, DateTimeImmutable $finishedAt, array $summary): bool
    {
        try {
            $summaryJson = json_encode(
                $summary,
                JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
            );
        } catch (JsonException $exception) {
            throw new RuntimeException('ATTENDANCE_SHADOW_RUN_SUMMARY_INVALID', 0, $exception);
        }
        $statement = $this->db->prepare(
            "UPDATE staff_attendance_runs
             SET status = 'completed', finished_at = :finished_at, summary = :summary
             WHERE id = :id AND mode = 'shadow' AND status = 'running'"
        );
        $statement->execute([
            'finished_at' => $this->databaseInstant($finishedAt),
            'summary' => $summaryJson,
            'id' => $runId,
        ]);
        return $statement->rowCount() === 1;
    }

    public function shadowDayBySourceForUpdate(
        int $staffUserId,
        string $workDate,
        string $sourceFingerprint,
        string $engineVersion
    ): ?array {
        $statement = $this->db->prepare(
            "SELECT *
             FROM staff_attendance_day_versions
             WHERE staff_user_id = :staff_user_id
               AND work_date = :work_date
               AND source_fingerprint = :source_fingerprint
               AND engine_version = :engine_version
               AND calculation_mode = 'shadow'
             FOR UPDATE"
        );
        $statement->execute([
            'staff_user_id' => $staffUserId,
            'work_date' => $workDate,
            'source_fingerprint' => $sourceFingerprint,
            'engine_version' => $engineVersion,
        ]);
        $row = $statement->fetch(PDO::FETCH_ASSOC);
        return $row === false ? null : $row;
    }

    public function nextDayVersionNoForUpdate(int $staffUserId, string $workDate): int
    {
        $statement = $this->db->prepare(
            'SELECT version_no
             FROM staff_attendance_day_versions
             WHERE staff_user_id = :staff_user_id AND work_date = :work_date
             ORDER BY version_no DESC
             LIMIT 1
             FOR UPDATE'
        );
        $statement->execute(['staff_user_id' => $staffUserId, 'work_date' => $workDate]);
        $last = $statement->fetchColumn();
        return $last === false ? 1 : ((int) $last + 1);
    }

    public function insertShadowDay(array $day): int
    {
        $columns = [
            'staff_user_id', 'work_date', 'version_no', 'run_id', 'assignment_id',
            'schedule_policy_version_id', 'calendar_exception_id', 'expected_start', 'expected_end',
            'required_minutes', 'first_in', 'last_out', 'worked_minutes', 'covered_late_minutes',
            'covered_early_minutes', 'mission_minutes', 'leave_minutes', 'late_minutes',
            'early_leave_minutes', 'missing_minutes', 'status', 'calculation_mode', 'engine_version',
            'source_fingerprint', 'is_official', 'officialized_by', 'officialized_at', 'supersedes_id',
            'calculated_at',
        ];
        $quoted = array_map(static fn (string $column): string => '`' . $column . '`', $columns);
        $statement = $this->db->prepare(
            'INSERT INTO staff_attendance_day_versions (' . implode(', ', $quoted) . ') VALUES ('
            . implode(', ', array_map(static fn (string $column): string => ':' . $column, $columns)) . ')'
        );
        $params = [];
        foreach ($columns as $column) {
            $params[$column] = $day[$column] ?? null;
        }
        $statement->execute($params);
        return (int) $this->db->lastInsertId();
    }

    public function appendSegment(array $segment): void
    {
        $columns = [
            'day_version_id', 'sequence_no', 'segment_type', 'expected_start', 'expected_end',
            'actual_start', 'actual_end', 'required_minutes', 'worked_minutes', 'covered_minutes',
            'missing_minutes', 'entry_event_id', 'exit_event_id', 'status',
        ];
        $statement = $this->db->prepare(
            'INSERT INTO staff_attendance_segments (' . implode(', ', $columns) . ') VALUES ('
            . implode(', ', array_map(static fn (string $column): string => ':' . $column, $columns)) . ')'
        );
        $params = [];
        foreach ($columns as $column) {
            $params[$column] = $segment[$column] ?? null;
        }
        $statement->execute($params);
    }

    public function appendReasonLine(array $reasonLine): void
    {
        $metadata = $reasonLine['metadata'] ?? null;
        if (is_array($metadata)) {
            try {
                $metadata = json_encode(
                    $metadata,
                    JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
                );
            } catch (JsonException $exception) {
                throw new RuntimeException('ATTENDANCE_SHADOW_REASON_METADATA_INVALID', 0, $exception);
            }
        }
        $statement = $this->db->prepare(
            'INSERT INTO staff_attendance_reason_lines
                (day_version_id, line_no, reason_code, from_at, to_at, minutes, source_type,
                 source_id, explanation, metadata)
             VALUES
                (:day_version_id, :line_no, :reason_code, :from_at, :to_at, :minutes, :source_type,
                 :source_id, :explanation, :metadata)'
        );
        $statement->execute([
            'day_version_id' => $reasonLine['day_version_id'],
            'line_no' => $reasonLine['line_no'],
            'reason_code' => $reasonLine['reason_code'],
            'from_at' => $reasonLine['from_at'] ?? null,
            'to_at' => $reasonLine['to_at'] ?? null,
            'minutes' => $reasonLine['minutes'],
            'source_type' => $reasonLine['source_type'],
            'source_id' => $reasonLine['source_id'] ?? null,
            'explanation' => $reasonLine['explanation'],
            'metadata' => $metadata,
        ]);
    }

    public function insertRecalculationRun(array $run): int
    {
        return $this->insertShadowRun($run);
    }

    public function startRecalculationRun(int $runId, DateTimeImmutable $startedAt): bool
    {
        $statement = $this->db->prepare(
            "UPDATE staff_attendance_runs
             SET status = 'running', started_at = :started_at
             WHERE id = :id AND mode = 'recalculation' AND status = 'queued'"
        );
        $statement->execute([
            'started_at' => $this->databaseInstant($startedAt),
            'id' => $runId,
        ]);
        return $statement->rowCount() === 1;
    }

    public function completeRecalculationRun(int $runId, DateTimeImmutable $finishedAt, array $summary): bool
    {
        try {
            $summaryJson = json_encode(
                $summary,
                JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
            );
        } catch (JsonException $exception) {
            throw new RuntimeException('ATTENDANCE_RECALCULATION_RUN_SUMMARY_INVALID', 0, $exception);
        }
        $statement = $this->db->prepare(
            "UPDATE staff_attendance_runs
             SET status = 'completed', finished_at = :finished_at, summary = :summary
             WHERE id = :id AND mode = 'recalculation' AND status = 'running'"
        );
        $statement->execute([
            'finished_at' => $this->databaseInstant($finishedAt),
            'summary' => $summaryJson,
            'id' => $runId,
        ]);
        return $statement->rowCount() === 1;
    }

    public function currentOfficialDayForUpdate(int $staffUserId, string $workDate): ?array
    {
        $statement = $this->db->prepare(
            'SELECT *
             FROM staff_attendance_day_versions
             WHERE staff_user_id = :staff_user_id
               AND work_date = :work_date
               AND is_official = 1
             LIMIT 1
             FOR UPDATE'
        );
        $statement->execute([
            'staff_user_id' => $staffUserId,
            'work_date' => $workDate,
        ]);
        $row = $statement->fetch(PDO::FETCH_ASSOC);
        return $row === false ? null : $row;
    }

    public function retireOfficialDay(int $dayVersionId): bool
    {
        $statement = $this->db->prepare(
            'UPDATE staff_attendance_day_versions
             SET is_official = 0
             WHERE id = :id AND is_official = 1'
        );
        $statement->execute(['id' => $dayVersionId]);
        return $statement->rowCount() === 1;
    }

    public function insertRecalculatedDay(array $day): int
    {
        return $this->insertShadowDay($day);
    }

    public function publishRecalculatedDay(
        int $dayVersionId,
        int $actorId,
        DateTimeImmutable $officializedAt
    ): bool {
        $statement = $this->db->prepare(
            'UPDATE staff_attendance_day_versions
             SET is_official = 1,
                 officialized_by = :officialized_by,
                 officialized_at = :officialized_at
             WHERE id = :id AND is_official = 0'
        );
        $statement->execute([
            'officialized_by' => $actorId,
            'officialized_at' => $this->databaseInstant($officializedAt),
            'id' => $dayVersionId,
        ]);
        return $statement->rowCount() === 1;
    }

    public function forStaffWindow(
        int $staffUserId,
        DateTimeImmutable $windowStart,
        DateTimeImmutable $windowEnd
    ): array {
        $statement = $this->db->prepare(
            "SELECT attendance_event.id,
                    attendance_event.event_at_local,
                    attendance_event.event_type,
                    attendance_event.link_status,
                    attendance_event.review_status,
                    entry_method.method_type AS entry_method_type
             FROM staff_biometric_events AS attendance_event
             INNER JOIN staff_attendance_entry_methods AS entry_method
                ON entry_method.id = attendance_event.entry_method_id
             WHERE attendance_event.staff_user_id = :staff_user_id
               AND attendance_event.link_status = 'matched'
               AND attendance_event.event_at_local >= :window_start
               AND attendance_event.event_at_local <= :window_end
             ORDER BY attendance_event.event_at_local ASC, attendance_event.id ASC"
        );
        $statement->execute([
            'staff_user_id' => $staffUserId,
            'window_start' => $this->localInstant($windowStart),
            'window_end' => $this->localInstant($windowEnd),
        ]);
        return array_values($statement->fetchAll(PDO::FETCH_ASSOC));
    }

    private function databaseInstant(DateTimeImmutable $instant): string
    {
        return $instant->setTimezone($this->utc)->format('Y-m-d H:i:s.u');
    }

    private function localInstant(DateTimeImmutable $instant): string
    {
        return $instant->format('Y-m-d H:i:s.u');
    }
}
