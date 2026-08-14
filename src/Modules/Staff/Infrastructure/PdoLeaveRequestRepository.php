<?php

declare(strict_types=1);

namespace EduCore\Modules\Staff\Infrastructure;

use DateTimeImmutable;
use EduCore\Modules\Staff\Contracts\LeaveRequestOverlapQuery;
use EduCore\Modules\Staff\Contracts\LeaveRequestRepository;
use EduCore\Modules\Staff\Contracts\LeaveStaffingOverrideRequestGateway;
use PDO;
use RuntimeException;

/**
 * PDO adapter for Staff-owned leave requests and immutable allocations.
 *
 * The worker's users row is the serialization anchor. This adapter returns
 * only privacy-minimal overlap metadata and relies on database triggers to
 * reject a submitted request-day mutation even if a caller is defective.
 */
final class PdoLeaveRequestRepository implements LeaveRequestRepository, LeaveRequestOverlapQuery, LeaveStaffingOverrideRequestGateway
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

    public function lockStaffForRequest(int $staffUserId): bool
    {
        $statement = $this->db->prepare('SELECT id FROM users WHERE id = ? FOR UPDATE');
        $statement->execute([$staffUserId]);

        return $statement->fetchColumn() !== false;
    }

    public function requestByCreateIdempotencyForUpdate(string $idempotencyKey): ?array
    {
        return $this->requestByColumnForUpdate('create_idempotency_key', $idempotencyKey);
    }

    public function requestBySubmissionIdempotencyForUpdate(string $idempotencyKey): ?array
    {
        return $this->requestByColumnForUpdate('submission_idempotency_key', $idempotencyKey);
    }

    public function requestForUpdate(int $requestId): ?array
    {
        $statement = $this->db->prepare('SELECT * FROM staff_leave_requests WHERE id = ? FOR UPDATE');
        $statement->execute([$requestId]);
        $row = $statement->fetch(PDO::FETCH_ASSOC);

        return $row === false ? null : $row;
    }

    public function insertDraft(array $request): int
    {
        $columns = [
            'staff_user_id', 'leave_type_id', 'request_kind', 'parent_request_id', 'supersedes_id',
            'from_at', 'to_at', 'timezone', 'requested_units', 'requested_minutes',
            'reason', 'reason_code', 'supporting_document_ref', 'status',
            'create_idempotency_key', 'request_hash',
        ];
        $statement = $this->db->prepare(
            'INSERT INTO staff_leave_requests (' . implode(', ', array_map(
                static fn (string $column): string => '`' . $column . '`',
                $columns
            )) . ') VALUES (' . implode(', ', array_map(
                static fn (string $column): string => ':' . $column,
                $columns
            )) . ')'
        );
        $statement->execute([
            'staff_user_id' => (int) $request['staff_user_id'],
            'leave_type_id' => (int) $request['leave_type_id'],
            'request_kind' => (string) $request['request_kind'],
            'parent_request_id' => $request['parent_request_id'],
            'supersedes_id' => $request['supersedes_id'],
            'from_at' => (string) $request['from_at'],
            'to_at' => (string) $request['to_at'],
            'timezone' => (string) $request['timezone'],
            'requested_units' => (string) $request['requested_units'],
            'requested_minutes' => (int) $request['requested_minutes'],
            'reason' => $request['reason'],
            'reason_code' => $request['reason_code'],
            'supporting_document_ref' => $request['supporting_document_ref'],
            'status' => 'draft',
            'create_idempotency_key' => (string) $request['create_idempotency_key'],
            'request_hash' => (string) $request['request_hash'],
        ]);

        return (int) $this->db->lastInsertId();
    }

    public function updateDraft(int $requestId, int $expectedLockVersion, array $changes): bool
    {
        $statement = $this->db->prepare(
            "UPDATE staff_leave_requests
             SET from_at = :from_at,
                 to_at = :to_at,
                 timezone = :timezone,
                 requested_units = :requested_units,
                 requested_minutes = :requested_minutes,
                 reason = :reason,
                 reason_code = :reason_code,
                 supporting_document_ref = :supporting_document_ref,
                 staffing_override_granted = 0,
                 staffing_override_reason = NULL,
                 request_hash = :request_hash,
                 lock_version = lock_version + 1
             WHERE id = :id
               AND status = 'draft'
               AND lock_version = :lock_version"
        );
        $statement->execute([
            'from_at' => (string) $changes['from_at'],
            'to_at' => (string) $changes['to_at'],
            'timezone' => (string) $changes['timezone'],
            'requested_units' => (string) $changes['requested_units'],
            'requested_minutes' => (int) $changes['requested_minutes'],
            'reason' => $changes['reason'],
            'reason_code' => $changes['reason_code'],
            'supporting_document_ref' => $changes['supporting_document_ref'],
            'request_hash' => (string) $changes['request_hash'],
            'id' => $requestId,
            'lock_version' => $expectedLockVersion,
        ]);

        return $statement->rowCount() === 1;
    }

    public function applyStaffingOverrideDecision(
        int $requestId,
        int $expectedLockVersion,
        bool $granted,
        ?string $reason
    ): bool {
        $statement = $this->db->prepare(
            "UPDATE staff_leave_requests
             SET staffing_override_granted = :granted,
                 staffing_override_reason = :reason,
                 lock_version = lock_version + 1
             WHERE id = :id
               AND status = 'draft'
               AND lock_version = :lock_version"
        );
        $statement->execute([
            'granted' => $granted ? 1 : 0,
            'reason' => $granted ? $reason : null,
            'id' => $requestId,
            'lock_version' => $expectedLockVersion,
        ]);

        return $statement->rowCount() === 1;
    }

    public function replaceDraftDays(int $requestId, array $days): array
    {
        $delete = $this->db->prepare('DELETE FROM staff_leave_request_days WHERE request_id = ?');
        $delete->execute([$requestId]);
        $insert = $this->db->prepare(
            'INSERT INTO staff_leave_request_days
                (request_id, work_date, day_kind, from_at, to_at, requested_units, requested_minutes,
                 consumed_units, consumed_minutes, entitlement_period_key, calendar_exception_id, allocation_key)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
        );
        $stored = [];
        foreach ($days as $day) {
            $insert->execute([
                $requestId,
                (string) $day['work_date'],
                (string) $day['day_kind'],
                $day['from_at'],
                $day['to_at'],
                (string) $day['requested_units'],
                (int) $day['requested_minutes'],
                (string) $day['consumed_units'],
                (int) $day['consumed_minutes'],
                $day['entitlement_period_key'],
                $day['calendar_exception_id'],
                (string) $day['allocation_key'],
            ]);
            $stored[] = [
                'id' => (int) $this->db->lastInsertId(),
                'request_id' => $requestId,
                'work_date' => (string) $day['work_date'],
                'day_kind' => (string) $day['day_kind'],
                'from_at' => $day['from_at'],
                'to_at' => $day['to_at'],
                'requested_units' => (string) $day['requested_units'],
                'requested_minutes' => (int) $day['requested_minutes'],
                'consumed_units' => (string) $day['consumed_units'],
                'consumed_minutes' => (int) $day['consumed_minutes'],
                'entitlement_period_key' => $day['entitlement_period_key'],
                'calendar_exception_id' => $day['calendar_exception_id'],
                'allocation_key' => (string) $day['allocation_key'],
            ];
        }

        return $stored;
    }

    public function daysForRequestForUpdate(int $requestId): array
    {
        $statement = $this->db->prepare(
            'SELECT id, request_id, work_date, day_kind, from_at, to_at, requested_units, requested_minutes,
                    consumed_units, consumed_minutes, entitlement_period_key, calendar_exception_id, allocation_key
             FROM staff_leave_request_days
             WHERE request_id = ?
             ORDER BY work_date ASC, id ASC
             FOR UPDATE'
        );
        $statement->execute([$requestId]);

        return $statement->fetchAll(PDO::FETCH_ASSOC);
    }

    public function submitDraft(int $requestId, int $expectedLockVersion, array $submission): bool
    {
        $statement = $this->db->prepare(
            "UPDATE staff_leave_requests
             SET status = 'pending_approval',
                 requested_units = :requested_units,
                 requested_minutes = :requested_minutes,
                 timezone = :timezone,
                 request_hash = :request_hash,
                 policy_version_id = :policy_version_id,
                 policy_snapshot = :policy_snapshot,
                 workflow_version_id = :workflow_version_id,
                 assignment_id = :assignment_id,
                 submitted_by = :submitted_by,
                 submitted_at = :submitted_at,
                 submission_idempotency_key = :submission_idempotency_key,
                 lock_version = lock_version + 1
             WHERE id = :id
               AND status = 'draft'
               AND lock_version = :lock_version"
        );
        $statement->execute([
            'requested_units' => (string) $submission['requested_units'],
            'requested_minutes' => (int) $submission['requested_minutes'],
            'timezone' => (string) $submission['timezone'],
            'request_hash' => (string) $submission['request_hash'],
            'policy_version_id' => (int) $submission['policy_version_id'],
            'policy_snapshot' => (string) $submission['policy_snapshot'],
            'workflow_version_id' => (int) $submission['workflow_version_id'],
            'assignment_id' => (int) $submission['assignment_id'],
            'submitted_by' => (int) $submission['submitted_by'],
            'submitted_at' => (string) $submission['submitted_at'],
            'submission_idempotency_key' => (string) $submission['submission_idempotency_key'],
            'id' => $requestId,
            'lock_version' => $expectedLockVersion,
        ]);

        return $statement->rowCount() === 1;
    }

    public function attachWorkflowInstance(int $requestId, int $expectedLockVersion, int $workflowInstanceId): bool
    {
        $statement = $this->db->prepare(
            "UPDATE staff_leave_requests
             SET workflow_instance_id = :workflow_instance_id,
                 lock_version = lock_version + 1
             WHERE id = :id
               AND status = 'pending_approval'
               AND workflow_instance_id IS NULL
               AND lock_version = :lock_version"
        );
        $statement->execute([
            'workflow_instance_id' => $workflowInstanceId,
            'id' => $requestId,
            'lock_version' => $expectedLockVersion,
        ]);

        return $statement->rowCount() === 1;
    }

    public function withdrawDraft(int $requestId, int $expectedLockVersion): bool
    {
        $statement = $this->db->prepare(
            "UPDATE staff_leave_requests
             SET status = 'withdrawn', lock_version = lock_version + 1
             WHERE id = :id AND status = 'draft' AND lock_version = :lock_version"
        );
        $statement->execute(['id' => $requestId, 'lock_version' => $expectedLockVersion]);

        return $statement->rowCount() === 1;
    }

    public function finalizeWorkflowOutcome(
        int $requestId,
        int $expectedLockVersion,
        string $outcome,
        DateTimeImmutable $decidedAt
    ): bool {
        if (!in_array($outcome, ['approved', 'rejected'], true)) {
            throw new RuntimeException('LEAVE_APPROVAL_OUTCOME_INVALID');
        }
        $statement = $this->db->prepare(
            "UPDATE staff_leave_requests
             SET status = :status,
                 decided_at = :decided_at,
                 approved_at = CASE WHEN :approval_status = 'approved' THEN :approved_at ELSE approved_at END,
                 lock_version = lock_version + 1
             WHERE id = :id
               AND status = 'pending_approval'
               AND workflow_instance_id IS NOT NULL
               AND lock_version = :lock_version"
        );
        $statement->execute([
            'status' => $outcome,
            'decided_at' => $this->databaseInstant($decidedAt),
            'approval_status' => $outcome,
            'approved_at' => $this->databaseInstant($decidedAt),
            'id' => $requestId,
            'lock_version' => $expectedLockVersion,
        ]);

        return $statement->rowCount() === 1;
    }

    public function conflictsForStaffForUpdate(
        int $staffUserId,
        DateTimeImmutable $fromAt,
        DateTimeImmutable $toAt,
        ?int $excludingRequestId = null
    ): array {
        $params = [
            'staff_user_id' => $staffUserId,
            'from_at' => $this->databaseInstant($fromAt),
            'to_at' => $this->databaseInstant($toAt),
        ];
        $leaveSql = "SELECT 'leave_request' AS resource_type, id AS resource_id, from_at, to_at, status
                     FROM staff_leave_requests
                     WHERE staff_user_id = :staff_user_id
                       AND from_at < :to_at
                       AND to_at > :from_at
                       AND status IN ('pending_approval', 'approved', 'cancellation_requested')";
        if ($excludingRequestId !== null) {
            $leaveSql .= ' AND id <> :excluding_request_id';
            $params['excluding_request_id'] = $excludingRequestId;
        }
        $leaveStatement = $this->db->prepare($leaveSql . ' ORDER BY from_at ASC, id ASC FOR UPDATE');
        $leaveStatement->execute($params);
        $conflicts = $leaveStatement->fetchAll(PDO::FETCH_ASSOC);

        $permissionStatement = $this->db->prepare(
            "SELECT 'permission_request' AS resource_type, id AS resource_id, from_at, to_at, status
             FROM staff_permission_requests
             WHERE staff_user_id = :staff_user_id
               AND from_at < :to_at
               AND to_at > :from_at
               AND status IN ('pending_approval', 'approved', 'cancellation_requested')
             ORDER BY from_at ASC, id ASC
             FOR UPDATE"
        );
        $permissionStatement->execute([
            'staff_user_id' => $staffUserId,
            'from_at' => $this->databaseInstant($fromAt),
            'to_at' => $this->databaseInstant($toAt),
        ]);
        $conflicts = array_merge($conflicts, $permissionStatement->fetchAll(PDO::FETCH_ASSOC));
        usort($conflicts, static function (array $left, array $right): int {
            return [(string) $left['from_at'], (string) $left['resource_type'], (int) $left['resource_id']]
                <=> [(string) $right['from_at'], (string) $right['resource_type'], (int) $right['resource_id']];
        });

        return $conflicts;
    }

    private function requestByColumnForUpdate(string $column, string $value): ?array
    {
        if (!in_array($column, ['create_idempotency_key', 'submission_idempotency_key'], true)) {
            throw new RuntimeException('LEAVE_REQUEST_IDEMPOTENCY_COLUMN_INVALID');
        }
        $statement = $this->db->prepare(
            'SELECT * FROM staff_leave_requests WHERE `' . $column . '` = ? FOR UPDATE'
        );
        $statement->execute([$value]);
        $row = $statement->fetch(PDO::FETCH_ASSOC);

        return $row === false ? null : $row;
    }

    private function databaseInstant(DateTimeImmutable $value): string
    {
        return $value->format('Y-m-d H:i:s.u');
    }

    private function isRetryableConcurrencyFailure(\Throwable $exception): bool
    {
        if (!$exception instanceof \PDOException) {
            return false;
        }
        $sqlState = (string) ($exception->errorInfo[0] ?? $exception->getCode());
        $driverCode = (int) ($exception->errorInfo[1] ?? 0);

        return $sqlState === '40001' || in_array($driverCode, [1205, 1213], true);
    }
}
