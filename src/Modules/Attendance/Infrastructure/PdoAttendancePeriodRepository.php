<?php

declare(strict_types=1);

namespace EduCore\Modules\Attendance\Infrastructure;

use DateTimeImmutable;
use DateTimeZone;
use EduCore\Modules\Attendance\Contracts\AttendancePeriodRepository;
use PDO;
use RuntimeException;

/**
 * PDO adapter for Attendance-owned period closure and reopen facts.
 */
final class PdoAttendancePeriodRepository implements AttendancePeriodRepository
{
    private DateTimeZone $utc;

    public function __construct(private PDO $db)
    {
        $this->utc = new DateTimeZone('UTC');
    }

    public function listPeriods(int $limit = 24): array
    {
        $limit = max(1, min(120, $limit));
        return $this->db->query(
            'SELECT * FROM staff_attendance_periods ORDER BY period_start DESC, id DESC LIMIT ' . $limit
        )->fetchAll(PDO::FETCH_ASSOC);
    }

    public function listChangeRequests(int $limit = 100): array
    {
        $limit = max(1, min(500, $limit));
        return $this->db->query(
            'SELECT change_request.*, period.period_key, period.state AS period_state,
                    period.lock_version AS period_lock_version
             FROM staff_attendance_period_change_requests change_request
             JOIN staff_attendance_periods period ON period.id = change_request.period_id
             ORDER BY change_request.requested_at DESC, change_request.id DESC LIMIT ' . $limit
        )->fetchAll(PDO::FETCH_ASSOC);
    }

    public function ensurePeriodForUpdate(string $periodKey, string $periodStart, string $periodEnd): array
    {
        $insert = $this->db->prepare(
            'INSERT INTO staff_attendance_periods (period_key, period_start, period_end, state, lock_version)
             VALUES (:period_key, :period_start, :period_end, \'open\', 1)
             ON DUPLICATE KEY UPDATE id = LAST_INSERT_ID(id)'
        );
        $insert->execute([
            'period_key' => $periodKey,
            'period_start' => $periodStart,
            'period_end' => $periodEnd,
        ]);

        $statement = $this->db->prepare(
            'SELECT * FROM staff_attendance_periods
             WHERE period_key = :period_key
             FOR UPDATE'
        );
        $statement->execute(['period_key' => $periodKey]);
        $row = $statement->fetch(PDO::FETCH_ASSOC);
        if ($row === false) {
            throw new RuntimeException('ATTENDANCE_PERIOD_PERSISTENCE_FAILED');
        }

        return $row;
    }

    public function periodByIdForUpdate(int $periodId): ?array
    {
        $statement = $this->db->prepare(
            'SELECT * FROM staff_attendance_periods WHERE id = :id FOR UPDATE'
        );
        $statement->execute(['id' => $periodId]);
        $row = $statement->fetch(PDO::FETCH_ASSOC);

        return $row === false ? null : $row;
    }

    public function changeByIdempotencyForUpdate(string $idempotencyKey): ?array
    {
        $statement = $this->db->prepare(
            'SELECT * FROM staff_attendance_period_change_requests
             WHERE idempotency_key = :idempotency_key
             FOR UPDATE'
        );
        $statement->execute(['idempotency_key' => $idempotencyKey]);
        $row = $statement->fetch(PDO::FETCH_ASSOC);

        return $row === false ? null : $row;
    }

    public function changeByFingerprintForUpdate(int $periodId, string $changeFingerprint): ?array
    {
        $statement = $this->db->prepare(
            'SELECT * FROM staff_attendance_period_change_requests
             WHERE period_id = :period_id
               AND change_fingerprint = :change_fingerprint
             FOR UPDATE'
        );
        $statement->execute([
            'period_id' => $periodId,
            'change_fingerprint' => $changeFingerprint,
        ]);
        $row = $statement->fetch(PDO::FETCH_ASSOC);

        return $row === false ? null : $row;
    }

    public function insertChangeRequest(array $change): int
    {
        $columns = [
            'period_id', 'request_type', 'source_type', 'source_id', 'staff_user_id', 'work_date',
            'source_fingerprint', 'reason_code', 'status', 'idempotency_key', 'request_hash',
            'change_fingerprint', 'requested_by', 'requested_at', 'lock_version',
        ];
        $statement = $this->db->prepare(
            'INSERT INTO staff_attendance_period_change_requests (' . implode(', ', $columns) . ') VALUES ('
            . implode(', ', array_map(static fn (string $column): string => ':' . $column, $columns)) . ')'
        );
        $parameters = [];
        foreach ($columns as $column) {
            $parameters[$column] = $change[$column] ?? null;
        }
        $statement->execute($parameters);

        return (int) $this->db->lastInsertId();
    }

    public function changeRequestForUpdate(int $changeRequestId): ?array
    {
        $statement = $this->db->prepare(
            'SELECT * FROM staff_attendance_period_change_requests WHERE id = :id FOR UPDATE'
        );
        $statement->execute(['id' => $changeRequestId]);
        $row = $statement->fetch(PDO::FETCH_ASSOC);

        return $row === false ? null : $row;
    }

    public function hasUnappliedChangeRequestsForPeriodForUpdate(int $periodId): bool
    {
        $statement = $this->db->prepare(
            "SELECT id
             FROM staff_attendance_period_change_requests
             WHERE period_id = :period_id
               AND status IN ('pending', 'ready', 'approved')
             ORDER BY id ASC
             LIMIT 1
             FOR UPDATE"
        );
        $statement->execute(['period_id' => $periodId]);

        return $statement->fetchColumn() !== false;
    }

    public function closePeriod(
        int $periodId,
        int $expectedLockVersion,
        int $actorId,
        DateTimeImmutable $closedAt,
        ?int $lastClosedRunId,
        string $reasonHash
    ): bool {
        $statement = $this->db->prepare(
            "UPDATE staff_attendance_periods
             SET state = 'closed',
                 last_closed_run_id = :last_closed_run_id,
                 closed_by = :closed_by,
                 closed_at = :closed_at,
                 close_reason_hash = :close_reason_hash,
                 lock_version = lock_version + 1
             WHERE id = :id
               AND state = 'open'
               AND lock_version = :lock_version"
        );
        $statement->execute([
            'last_closed_run_id' => $lastClosedRunId,
            'closed_by' => $actorId,
            'closed_at' => $this->databaseInstant($closedAt),
            'close_reason_hash' => $reasonHash,
            'id' => $periodId,
            'lock_version' => $expectedLockVersion,
        ]);

        return $statement->rowCount() === 1;
    }

    public function reopenPeriod(
        int $periodId,
        int $expectedLockVersion,
        int $actorId,
        DateTimeImmutable $reopenedAt
    ): bool {
        $statement = $this->db->prepare(
            "UPDATE staff_attendance_periods
             SET state = 'open',
                 reopened_by = :reopened_by,
                 reopened_at = :reopened_at,
                 lock_version = lock_version + 1
             WHERE id = :id
               AND state = 'closed'
               AND lock_version = :lock_version"
        );
        $statement->execute([
            'reopened_by' => $actorId,
            'reopened_at' => $this->databaseInstant($reopenedAt),
            'id' => $periodId,
            'lock_version' => $expectedLockVersion,
        ]);

        return $statement->rowCount() === 1;
    }

    public function decideChangeRequest(
        int $changeRequestId,
        int $expectedLockVersion,
        array $decision
    ): bool {
        $statement = $this->db->prepare(
            "UPDATE staff_attendance_period_change_requests
             SET status = :status,
                 reviewed_by = :reviewed_by,
                 reviewed_at = :reviewed_at,
                 review_comment_hash = :review_comment_hash,
                 decision_idempotency_key = :decision_idempotency_key,
                 decision_hash = :decision_hash,
                 lock_version = lock_version + 1
             WHERE id = :id
               AND status = 'pending'
               AND lock_version = :lock_version"
        );
        $statement->execute([
            'status' => $decision['status'],
            'reviewed_by' => $decision['reviewed_by'],
            'reviewed_at' => $this->databaseInstant($decision['reviewed_at']),
            'review_comment_hash' => $decision['review_comment_hash'],
            'decision_idempotency_key' => $decision['decision_idempotency_key'],
            'decision_hash' => $decision['decision_hash'],
            'id' => $changeRequestId,
            'lock_version' => $expectedLockVersion,
        ]);

        return $statement->rowCount() === 1;
    }

    public function applyChangeRequest(
        int $changeRequestId,
        int $expectedLockVersion,
        int $runId,
        DateTimeImmutable $appliedAt
    ): bool {
        $statement = $this->db->prepare(
            "UPDATE staff_attendance_period_change_requests
             SET status = 'applied',
                 applied_run_id = :applied_run_id,
                 applied_at = :applied_at,
                 lock_version = lock_version + 1
             WHERE id = :id
               AND status IN ('ready', 'approved')
               AND lock_version = :lock_version"
        );
        $statement->execute([
            'applied_run_id' => $runId,
            'applied_at' => $this->databaseInstant($appliedAt),
            'id' => $changeRequestId,
            'lock_version' => $expectedLockVersion,
        ]);

        return $statement->rowCount() === 1;
    }

    private function databaseInstant(DateTimeImmutable $instant): string
    {
        return $instant->setTimezone($this->utc)->format('Y-m-d H:i:s.u');
    }
}
