<?php

declare(strict_types=1);

namespace EduCore\Modules\Attendance\Infrastructure;

use DateTimeImmutable;
use DateTimeZone;
use EduCore\Modules\Attendance\Contracts\AttendanceReportProjectionRepository;
use JsonException;
use PDO;
use RuntimeException;

/** PDO persistence adapter for immutable Attendance report projections. */
final class PdoAttendanceReportProjectionRepository implements AttendanceReportProjectionRepository
{
    private DateTimeZone $utc;

    public function __construct(private PDO $db)
    {
        $this->utc = new DateTimeZone('UTC');
    }

    public function projectionRunByIdempotencyForUpdate(string $idempotencyKey): ?array
    {
        $statement = $this->db->prepare(
            'SELECT * FROM staff_attendance_report_projection_runs
             WHERE idempotency_key = :idempotency_key
             FOR UPDATE'
        );
        $statement->execute(['idempotency_key' => $idempotencyKey]);
        $row = $statement->fetch(PDO::FETCH_ASSOC);

        return $row === false ? null : $row;
    }

    public function insertProjectionRun(array $run): int
    {
        $columns = [
            'projection_version',
            'range_from',
            'range_to',
            'initiated_by',
            'status',
            'source_fingerprint',
            'idempotency_key',
            'summary',
            'started_at',
            'finished_at',
        ];
        $statement = $this->db->prepare(
            'INSERT INTO staff_attendance_report_projection_runs (' . implode(', ', $columns) . ') VALUES ('
            . implode(', ', array_map(static fn (string $column): string => ':' . $column, $columns)) . ')'
        );
        $parameters = [];
        foreach ($columns as $column) {
            $parameters[$column] = $run[$column] ?? null;
        }
        $statement->execute($parameters);

        return (int) $this->db->lastInsertId();
    }

    public function startProjectionRun(int $runId, DateTimeImmutable $startedAt): bool
    {
        $statement = $this->db->prepare(
            "UPDATE staff_attendance_report_projection_runs
             SET status = 'running', started_at = :started_at
             WHERE id = :id AND status = 'queued'"
        );
        $statement->execute([
            'id' => $runId,
            'started_at' => $this->databaseInstant($startedAt),
        ]);

        return $statement->rowCount() === 1;
    }

    public function completeProjectionRun(int $runId, DateTimeImmutable $finishedAt, array $summary): bool
    {
        $statement = $this->db->prepare(
            "UPDATE staff_attendance_report_projection_runs
             SET status = 'completed',
                 summary = :summary,
                 finished_at = :finished_at
             WHERE id = :id AND status = 'running'"
        );
        $statement->execute([
            'id' => $runId,
            'summary' => $this->json($summary, 'ATTENDANCE_REPORT_PROJECTION_SUMMARY_INVALID'),
            'finished_at' => $this->databaseInstant($finishedAt),
        ]);

        return $statement->rowCount() === 1;
    }

    public function currentAggregateForUpdate(string $aggregateKey): ?array
    {
        $statement = $this->db->prepare(
            'SELECT * FROM staff_attendance_report_aggregates
             WHERE current_aggregate_key = :aggregate_key
             FOR UPDATE'
        );
        $statement->execute(['aggregate_key' => $aggregateKey]);
        $row = $statement->fetch(PDO::FETCH_ASSOC);

        return $row === false ? null : $row;
    }

    public function retireCurrentAggregate(int $aggregateId): bool
    {
        $statement = $this->db->prepare(
            'UPDATE staff_attendance_report_aggregates
             SET is_current = 0
             WHERE id = :id AND is_current = 1'
        );
        $statement->execute(['id' => $aggregateId]);

        return $statement->rowCount() === 1;
    }

    public function insertAggregate(array $aggregate): int
    {
        $columns = [
            'projection_run_id',
            'aggregate_key',
            'staff_user_id',
            'granularity',
            'range_from',
            'range_to',
            'assignment_id',
            'org_unit_id',
            'job_title_id',
            'group_ids',
            'eligible_workdays',
            'present_days',
            'absent_days',
            'partial_days',
            'non_working_days',
            'exception_days',
            'approved_permission_days',
            'mission_days',
            'leave_days',
            'required_minutes',
            'worked_minutes',
            'covered_minutes',
            'late_minutes',
            'early_leave_minutes',
            'missing_minutes',
            'reason_summary',
            'source_fingerprint',
            'is_current',
            'supersedes_id',
        ];
        $quotedColumns = array_map(static fn (string $column): string => '`' . $column . '`', $columns);
        $statement = $this->db->prepare(
            'INSERT INTO staff_attendance_report_aggregates (' . implode(', ', $quotedColumns) . ') VALUES ('
            . implode(', ', array_map(static fn (string $column): string => ':' . $column, $columns)) . ')'
        );
        $parameters = [];
        foreach ($columns as $column) {
            $parameters[$column] = $aggregate[$column] ?? null;
        }
        $parameters['group_ids'] = $this->json($parameters['group_ids'] ?? [], 'ATTENDANCE_REPORT_GROUPS_INVALID');
        $parameters['reason_summary'] = $this->json(
            $parameters['reason_summary'] ?? [],
            'ATTENDANCE_REPORT_REASON_SUMMARY_INVALID'
        );
        $statement->execute($parameters);

        return (int) $this->db->lastInsertId();
    }

    private function databaseInstant(DateTimeImmutable $instant): string
    {
        return $instant->setTimezone($this->utc)->format('Y-m-d H:i:s.u');
    }

    private function json(mixed $value, string $error): string
    {
        try {
            return json_encode($value, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        } catch (JsonException $exception) {
            throw new RuntimeException($error, 0, $exception);
        }
    }
}
