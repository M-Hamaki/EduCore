<?php

declare(strict_types=1);

namespace EduCore\Modules\Attendance\Infrastructure;

use DateTimeImmutable;
use DateTimeZone;
use EduCore\Modules\Attendance\Contracts\AttendanceAdjustmentRepository;
use JsonException;
use PDO;
use RuntimeException;

/** PDO adapter restricted to Attendance correction, run, day, and child tables. */
final class PdoAttendanceAdjustmentRepository implements AttendanceAdjustmentRepository
{
    private DateTimeZone $utc;

    public function __construct(private PDO $db)
    {
        $this->utc = new DateTimeZone('UTC');
    }

    public function adjustmentsForRequester(int $requesterId, int $limit): array
    {
        $limit = max(1, min(100, $limit));
        $statement = $this->db->prepare(
            "SELECT id, staff_user_id, work_date, requester_id, requester_kind, reason,
                    before_version_id, proposed_values, status, approved_version_id,
                    resolution_comment, lock_version, submitted_at, created_at
             FROM staff_attendance_adjustments
             WHERE requester_id = :requester_id
             ORDER BY created_at DESC, id DESC
             LIMIT {$limit}"
        );
        $statement->execute(['requester_id' => $requesterId]);
        return $statement->fetchAll(PDO::FETCH_ASSOC);
    }

    public function pendingAdjustments(int $limit): array
    {
        $limit = max(1, min(200, $limit));
        $statement = $this->db->query(
            "SELECT id, staff_user_id, work_date, requester_id, requester_kind, reason,
                    before_version_id, proposed_values, status, approved_version_id,
                    resolution_comment, lock_version, submitted_at, created_at
             FROM staff_attendance_adjustments
             WHERE status = 'pending'
             ORDER BY submitted_at ASC, id ASC
             LIMIT {$limit}"
        );
        return $statement->fetchAll(PDO::FETCH_ASSOC);
    }

    public function currentOfficialDayForUpdate(int $staffUserId, string $workDate): ?array
    {
        $statement = $this->db->prepare(
            'SELECT * FROM staff_attendance_day_versions
             WHERE staff_user_id = :staff_user_id
               AND work_date = :work_date
               AND is_official = 1
             FOR UPDATE'
        );
        $statement->execute(['staff_user_id' => $staffUserId, 'work_date' => $workDate]);
        $row = $statement->fetch(PDO::FETCH_ASSOC);
        return $row === false ? null : $row;
    }

    public function adjustmentByIdempotencyForUpdate(string $idempotencyKey): ?array
    {
        $statement = $this->db->prepare(
            'SELECT * FROM staff_attendance_adjustments WHERE idempotency_key = :idempotency_key FOR UPDATE'
        );
        $statement->execute(['idempotency_key' => $idempotencyKey]);
        $row = $statement->fetch(PDO::FETCH_ASSOC);
        return $row === false ? null : $row;
    }

    public function adjustmentForUpdate(int $adjustmentId): ?array
    {
        $statement = $this->db->prepare('SELECT * FROM staff_attendance_adjustments WHERE id = :id FOR UPDATE');
        $statement->execute(['id' => $adjustmentId]);
        $row = $statement->fetch(PDO::FETCH_ASSOC);
        return $row === false ? null : $row;
    }

    public function insertAdjustment(array $adjustment): int
    {
        $columns = [
            'staff_user_id', 'work_date', 'requester_id', 'requester_kind', 'reason',
            'before_version_id', 'proposed_values', 'workflow_instance_id', 'status',
            'submitted_at', 'approved_version_id', 'resolution_comment', 'idempotency_key',
            'lock_version',
        ];
        $statement = $this->db->prepare(
            'INSERT INTO staff_attendance_adjustments (' . implode(', ', $columns) . ') VALUES ('
            . implode(', ', array_map(static fn (string $column): string => ':' . $column, $columns)) . ')'
        );
        $params = [];
        foreach ($columns as $column) {
            $params[$column] = $adjustment[$column] ?? null;
        }
        $statement->execute($params);
        return (int) $this->db->lastInsertId();
    }

    public function submitAdjustment(
        int $adjustmentId,
        int $expectedLockVersion,
        ?int $workflowInstanceId,
        DateTimeImmutable $submittedAt
    ): bool {
        $statement = $this->db->prepare(
            "UPDATE staff_attendance_adjustments
             SET status = 'pending',
                 workflow_instance_id = :workflow_instance_id,
                 submitted_at = :submitted_at,
                 lock_version = lock_version + 1
             WHERE id = :id
               AND status = 'draft'
               AND lock_version = :lock_version"
        );
        $statement->execute([
            'workflow_instance_id' => $workflowInstanceId,
            'submitted_at' => $this->databaseInstant($submittedAt),
            'id' => $adjustmentId,
            'lock_version' => $expectedLockVersion,
        ]);
        return $statement->rowCount() === 1;
    }

    public function cancelAdjustment(
        int $adjustmentId,
        int $expectedLockVersion,
        string $resolutionComment,
        DateTimeImmutable $cancelledAt
    ): bool {
        $statement = $this->db->prepare(
            "UPDATE staff_attendance_adjustments
             SET status = 'cancelled',
                 submitted_at = COALESCE(submitted_at, :submitted_at),
                 resolution_comment = :resolution_comment,
                 lock_version = lock_version + 1
             WHERE id = :id
               AND status IN ('draft', 'pending')
               AND lock_version = :lock_version"
        );
        $statement->execute([
            'submitted_at' => $this->databaseInstant($cancelledAt),
            'resolution_comment' => $resolutionComment,
            'id' => $adjustmentId,
            'lock_version' => $expectedLockVersion,
        ]);
        return $statement->rowCount() === 1;
    }

    public function finalizeAdjustment(
        int $adjustmentId,
        int $expectedLockVersion,
        string $status,
        ?int $approvedVersionId,
        ?string $resolutionComment
    ): bool {
        $statement = $this->db->prepare(
            "UPDATE staff_attendance_adjustments
             SET status = :status,
                 approved_version_id = :approved_version_id,
                 resolution_comment = :resolution_comment,
                 lock_version = lock_version + 1
             WHERE id = :id
               AND status = 'pending'
               AND lock_version = :lock_version"
        );
        $statement->execute([
            'status' => $status,
            'approved_version_id' => $approvedVersionId,
            'resolution_comment' => $resolutionComment,
            'id' => $adjustmentId,
            'lock_version' => $expectedLockVersion,
        ]);
        return $statement->rowCount() === 1;
    }

    public function insertRecalculationRun(array $run): int
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

    public function startRun(int $runId, DateTimeImmutable $startedAt): bool
    {
        $statement = $this->db->prepare(
            "UPDATE staff_attendance_runs
             SET status = 'running', started_at = :started_at
             WHERE id = :id AND status = 'queued'"
        );
        $statement->execute([
            'started_at' => $this->databaseInstant($startedAt),
            'id' => $runId,
        ]);
        return $statement->rowCount() === 1;
    }

    public function completeRun(int $runId, DateTimeImmutable $finishedAt, array $summary): bool
    {
        try {
            $summaryJson = json_encode(
                $summary,
                JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
            );
        } catch (JsonException $exception) {
            throw new RuntimeException('ATTENDANCE_ADJUSTMENT_RUN_SUMMARY_INVALID', 0, $exception);
        }
        $statement = $this->db->prepare(
            "UPDATE staff_attendance_runs
             SET status = 'completed', finished_at = :finished_at, summary = :summary
             WHERE id = :id AND status = 'running'"
        );
        $statement->execute([
            'finished_at' => $this->databaseInstant($finishedAt),
            'summary' => $summaryJson,
            'id' => $runId,
        ]);
        return $statement->rowCount() === 1;
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

    public function insertDayVersion(array $day): int
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

    public function copySegments(int $sourceDayVersionId, int $targetDayVersionId): void
    {
        $statement = $this->db->prepare(
            'INSERT INTO staff_attendance_segments
                (day_version_id, sequence_no, segment_type, expected_start, expected_end,
                 actual_start, actual_end, required_minutes, worked_minutes, covered_minutes,
                 missing_minutes, entry_event_id, exit_event_id, status)
             SELECT :target_day_version_id, sequence_no, segment_type, expected_start, expected_end,
                    actual_start, actual_end, required_minutes, worked_minutes, covered_minutes,
                    missing_minutes, entry_event_id, exit_event_id, status
             FROM staff_attendance_segments
             WHERE day_version_id = :source_day_version_id
             ORDER BY sequence_no'
        );
        $statement->execute([
            'target_day_version_id' => $targetDayVersionId,
            'source_day_version_id' => $sourceDayVersionId,
        ]);
    }

    public function copyReasonLines(int $sourceDayVersionId, int $targetDayVersionId): int
    {
        $maxStatement = $this->db->prepare(
            'SELECT COALESCE(MAX(line_no), 0) FROM staff_attendance_reason_lines WHERE day_version_id = ?'
        );
        $maxStatement->execute([$sourceDayVersionId]);
        $lastLine = (int) $maxStatement->fetchColumn();
        $statement = $this->db->prepare(
            'INSERT INTO staff_attendance_reason_lines
                (day_version_id, line_no, reason_code, from_at, to_at, minutes, source_type,
                 source_id, explanation, metadata)
             SELECT :target_day_version_id, line_no, reason_code, from_at, to_at, minutes, source_type,
                    source_id, explanation, metadata
             FROM staff_attendance_reason_lines
             WHERE day_version_id = :source_day_version_id
             ORDER BY line_no'
        );
        $statement->execute([
            'target_day_version_id' => $targetDayVersionId,
            'source_day_version_id' => $sourceDayVersionId,
        ]);
        return $lastLine;
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
                throw new RuntimeException('ATTENDANCE_ADJUSTMENT_REASON_METADATA_INVALID', 0, $exception);
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

    public function demoteOfficialDay(int $dayVersionId): bool
    {
        $statement = $this->db->prepare(
            'UPDATE staff_attendance_day_versions SET is_official = 0 WHERE id = :id AND is_official = 1'
        );
        $statement->execute(['id' => $dayVersionId]);
        return $statement->rowCount() === 1;
    }

    public function publishDayVersion(
        int $dayVersionId,
        int $actorId,
        DateTimeImmutable $officializedAt
    ): bool {
        $statement = $this->db->prepare(
            'UPDATE staff_attendance_day_versions
             SET is_official = 1,
                 officialized_by = :officialized_by,
                 officialized_at = :officialized_at
             WHERE id = :id
               AND is_official = 0
               AND officialized_at IS NULL'
        );
        $statement->execute([
            'officialized_by' => $actorId,
            'officialized_at' => $this->databaseInstant($officializedAt),
            'id' => $dayVersionId,
        ]);
        return $statement->rowCount() === 1;
    }

    private function databaseInstant(DateTimeImmutable $instant): string
    {
        return $instant->setTimezone($this->utc)->format('Y-m-d H:i:s.u');
    }
}
