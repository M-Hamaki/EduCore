<?php

declare(strict_types=1);

namespace EduCore\Modules\Staff\Infrastructure;

use DateTimeImmutable;
use EduCore\Modules\Staff\Contracts\PermissionRequestOverlapQuery;
use EduCore\Modules\Staff\Contracts\PermissionRequestRepository;
use PDO;
use RuntimeException;

/**
 * PDO adapter for Staff-owned permission requests and their monthly slices.
 *
 * A locked `users` row is the serialization anchor for concurrent requests by
 * one employee. The adapter intentionally projects only non-sensitive overlap
 * identifiers; it never exposes another request's reason or attachment.
 */
final class PdoPermissionRequestRepository implements PermissionRequestRepository, PermissionRequestOverlapQuery
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
        $statement = $this->db->prepare('SELECT * FROM staff_permission_requests WHERE id = ? FOR UPDATE');
        $statement->execute([$requestId]);
        $row = $statement->fetch(PDO::FETCH_ASSOC);

        return $row === false ? null : $row;
    }

    public function insertDraft(array $request): int
    {
        $columns = [
            'staff_user_id', 'permission_type_id', 'from_at', 'to_at', 'timezone', 'requested_minutes',
            'custom_label', 'reason', 'attachment_ref', 'status', 'quota_exception',
            'quota_exception_reason', 'create_idempotency_key', 'request_hash',
        ];
        $quoted = array_map(static fn (string $column): string => '`' . $column . '`', $columns);
        $statement = $this->db->prepare(
            'INSERT INTO staff_permission_requests (' . implode(', ', $quoted) . ') VALUES ('
            . implode(', ', array_map(static fn (string $column): string => ':' . $column, $columns)) . ')'
        );
        $params = [
            'staff_user_id' => (int) $request['staff_user_id'],
            'permission_type_id' => (int) $request['permission_type_id'],
            'from_at' => (string) $request['from_at'],
            'to_at' => (string) $request['to_at'],
            'timezone' => (string) $request['timezone'],
            'requested_minutes' => (int) $request['requested_minutes'],
            'custom_label' => $request['custom_label'] ?? null,
            'reason' => $request['reason'] ?? null,
            'attachment_ref' => $request['attachment_ref'] ?? null,
            'status' => 'draft',
            'quota_exception' => 0,
            'quota_exception_reason' => null,
            'create_idempotency_key' => (string) $request['create_idempotency_key'],
            'request_hash' => (string) $request['request_hash'],
        ];
        $statement->execute($params);

        return (int) $this->db->lastInsertId();
    }

    public function updateDraft(int $requestId, int $expectedLockVersion, array $changes): bool
    {
        $statement = $this->db->prepare(
            "UPDATE staff_permission_requests
             SET permission_type_id = :permission_type_id,
                 from_at = :from_at,
                 to_at = :to_at,
                 timezone = :timezone,
                 requested_minutes = :requested_minutes,
                 custom_label = :custom_label,
                 reason = :reason,
                 attachment_ref = :attachment_ref,
                 request_hash = :request_hash,
                 lock_version = lock_version + 1
             WHERE id = :id
               AND status = 'draft'
               AND lock_version = :lock_version"
        );
        $statement->execute([
            'permission_type_id' => (int) $changes['permission_type_id'],
            'from_at' => (string) $changes['from_at'],
            'to_at' => (string) $changes['to_at'],
            'timezone' => (string) $changes['timezone'],
            'requested_minutes' => (int) $changes['requested_minutes'],
            'custom_label' => $changes['custom_label'] ?? null,
            'reason' => $changes['reason'] ?? null,
            'attachment_ref' => $changes['attachment_ref'] ?? null,
            'request_hash' => (string) $changes['request_hash'],
            'id' => $requestId,
            'lock_version' => $expectedLockVersion,
        ]);

        return $statement->rowCount() === 1;
    }

    public function replaceDraftPeriods(int $requestId, array $periods): array
    {
        $delete = $this->db->prepare('DELETE FROM staff_permission_request_periods WHERE request_id = ?');
        $delete->execute([$requestId]);
        $insert = $this->db->prepare(
            'INSERT INTO staff_permission_request_periods
                (request_id, period_key, period_from_at, period_to_at, requested_count, requested_minutes)
             VALUES (?, ?, ?, ?, ?, ?)'
        );
        $stored = [];
        foreach ($periods as $period) {
            $insert->execute([
                $requestId,
                (string) ($period['period_key'] ?? ''),
                (string) ($period['period_from_at'] ?? ''),
                (string) ($period['period_to_at'] ?? ''),
                (int) ($period['requested_count'] ?? 0),
                (int) ($period['requested_minutes'] ?? 0),
            ]);
            $stored[] = [
                'id' => (int) $this->db->lastInsertId(),
                'request_id' => $requestId,
                'period_key' => (string) ($period['period_key'] ?? ''),
                'period_from_at' => (string) ($period['period_from_at'] ?? ''),
                'period_to_at' => (string) ($period['period_to_at'] ?? ''),
                'requested_count' => (int) ($period['requested_count'] ?? 0),
                'requested_minutes' => (int) ($period['requested_minutes'] ?? 0),
            ];
        }

        return $stored;
    }

    public function periodsForRequestForUpdate(int $requestId): array
    {
        $statement = $this->db->prepare(
            'SELECT id, request_id, period_key, period_from_at, period_to_at, requested_count, requested_minutes
             FROM staff_permission_request_periods
             WHERE request_id = ?
             ORDER BY period_from_at ASC, id ASC
             FOR UPDATE'
        );
        $statement->execute([$requestId]);

        return $statement->fetchAll(PDO::FETCH_ASSOC);
    }

    public function submitDraft(int $requestId, int $expectedLockVersion, array $submission): bool
    {
        $statement = $this->db->prepare(
            "UPDATE staff_permission_requests
             SET status = 'pending_approval',
                 policy_version_id = :policy_version_id,
                 policy_snapshot = :policy_snapshot,
                 workflow_version_id = :workflow_version_id,
                 assignment_id = :assignment_id,
                 quota_exception = 0,
                 quota_exception_reason = NULL,
                 submitted_by = :submitted_by,
                 submitted_at = :submitted_at,
                 submission_idempotency_key = :submission_idempotency_key,
                 lock_version = lock_version + 1
             WHERE id = :id
               AND status = 'draft'
               AND lock_version = :lock_version"
        );
        $statement->execute([
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
            "UPDATE staff_permission_requests
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

    public function finalizeWorkflowOutcome(
        int $requestId,
        int $expectedLockVersion,
        string $outcome,
        DateTimeImmutable $decidedAt
    ): bool {
        if (!in_array($outcome, ['approved', 'rejected'], true)) {
            throw new RuntimeException('PERMISSION_APPROVAL_OUTCOME_INVALID');
        }
        $statement = $this->db->prepare(
            "UPDATE staff_permission_requests
             SET status = :status,
                 decided_at = :decided_at,
                 lock_version = lock_version + 1
             WHERE id = :id
               AND status = 'pending_approval'
               AND workflow_instance_id IS NOT NULL
               AND lock_version = :lock_version"
        );
        $statement->execute([
            'status' => $outcome,
            'decided_at' => $this->databaseInstant($decidedAt),
            'id' => $requestId,
            'lock_version' => $expectedLockVersion,
        ]);

        return $statement->rowCount() === 1;
    }

    public function markQuotaException(int $requestId, int $expectedLockVersion, string $reason): bool
    {
        $statement = $this->db->prepare(
            "UPDATE staff_permission_requests
             SET quota_exception = 1,
                 quota_exception_reason = :reason,
                 lock_version = lock_version + 1
             WHERE id = :id
               AND status = 'pending_approval'
               AND quota_exception = 0
               AND quota_exception_reason IS NULL
               AND lock_version = :lock_version"
        );
        $statement->execute([
            'reason' => $reason,
            'id' => $requestId,
            'lock_version' => $expectedLockVersion,
        ]);

        return $statement->rowCount() === 1;
    }

    public function withdrawDraft(int $requestId, int $expectedLockVersion): bool
    {
        $statement = $this->db->prepare(
            "UPDATE staff_permission_requests
             SET status = 'withdrawn', lock_version = lock_version + 1
             WHERE id = :id AND status = 'draft' AND lock_version = :lock_version"
        );
        $statement->execute(['id' => $requestId, 'lock_version' => $expectedLockVersion]);

        return $statement->rowCount() === 1;
    }

    public function cancelPendingRequest(int $requestId, int $expectedLockVersion): bool
    {
        $statement = $this->db->prepare(
            "UPDATE staff_permission_requests
             SET status = 'cancelled', lock_version = lock_version + 1
             WHERE id = :id AND status = 'pending_approval' AND lock_version = :lock_version"
        );
        $statement->execute(['id' => $requestId, 'lock_version' => $expectedLockVersion]);

        return $statement->rowCount() === 1;
    }

    public function pendingRequestsForStaffForUpdate(int $staffUserId): array
    {
        $statement = $this->db->prepare(
            "SELECT * FROM staff_permission_requests
             WHERE staff_user_id = :staff_user_id
               AND status = 'pending_approval'
             ORDER BY id
             FOR UPDATE"
        );
        $statement->execute(['staff_user_id' => $staffUserId]);
        return $statement->fetchAll(PDO::FETCH_ASSOC);
    }

    public function cancelPendingRequestDueToServiceEnd(int $requestId, int $expectedLockVersion): bool
    {
        $statement = $this->db->prepare(
            "UPDATE staff_permission_requests
             SET status = 'cancelled_due_to_service_end',
                 decided_at = CURRENT_TIMESTAMP(6),
                 lock_version = lock_version + 1
             WHERE id = :id
               AND status = 'pending_approval'
               AND lock_version = :lock_version"
        );
        $statement->execute(['id' => $requestId, 'lock_version' => $expectedLockVersion]);
        return $statement->rowCount() === 1;
    }

    public function conflictsForStaffForUpdate(
        int $staffUserId,
        DateTimeImmutable $fromAt,
        DateTimeImmutable $toAt,
        ?int $excludingRequestId = null
    ): array {
        $sql = "SELECT 'permission_request' AS resource_type, id AS resource_id, from_at, to_at, status
                FROM staff_permission_requests
                WHERE staff_user_id = :staff_user_id
                  AND from_at < :to_at
                  AND to_at > :from_at
                  AND status IN ('pending_approval', 'approved', 'cancellation_requested')";
        $params = [
            'staff_user_id' => $staffUserId,
            'from_at' => $this->databaseInstant($fromAt),
            'to_at' => $this->databaseInstant($toAt),
        ];
        if ($excludingRequestId !== null) {
            $sql .= ' AND id <> :excluding_request_id';
            $params['excluding_request_id'] = $excludingRequestId;
        }
        $statement = $this->db->prepare($sql . ' ORDER BY from_at ASC, id ASC FOR UPDATE');
        $statement->execute($params);

        return $statement->fetchAll(PDO::FETCH_ASSOC);
    }

    private function requestByColumnForUpdate(string $column, string $value): ?array
    {
        if (!in_array($column, ['create_idempotency_key', 'submission_idempotency_key'], true)) {
            throw new RuntimeException('PERMISSION_REQUEST_IDEMPOTENCY_COLUMN_INVALID');
        }
        $statement = $this->db->prepare(
            'SELECT * FROM staff_permission_requests WHERE `' . $column . '` = ? FOR UPDATE'
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
