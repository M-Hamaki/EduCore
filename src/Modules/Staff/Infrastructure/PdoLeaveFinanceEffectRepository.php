<?php

declare(strict_types=1);

namespace EduCore\Modules\Staff\Infrastructure;

use EduCore\Modules\Staff\Contracts\LeaveFinanceEffectRepository;
use PDO;
use Throwable;

/**
 * PDO adapter for the Staff-owned Finance fact outbox.
 *
 * Finance tables are intentionally absent here. The adapter persists only
 * staff_external_effects and loads the approved leave snapshot/day facts
 * needed to build an immutable request for the Finance contract.
 */
final class PdoLeaveFinanceEffectRepository implements LeaveFinanceEffectRepository
{
    public function __construct(private PDO $db)
    {
    }

    public function transactional(callable $operation): mixed
    {
        $ownsTransaction = !$this->db->inTransaction();
        if ($ownsTransaction) {
            $this->db->beginTransaction();
        }

        try {
            $result = $operation();
            if ($ownsTransaction) {
                $this->db->commit();
            }

            return $result;
        } catch (Throwable $exception) {
            if ($ownsTransaction && $this->db->inTransaction()) {
                $this->db->rollBack();
            }
            throw $exception;
        }
    }

    public function requestForUpdate(int $requestId): ?array
    {
        $statement = $this->db->prepare(
            'SELECT id, staff_user_id, leave_type_id, request_kind, status,
                    from_at, to_at, timezone, requested_units, requested_minutes,
                    policy_version_id, policy_snapshot, request_hash, approved_at, decided_at
             FROM staff_leave_requests
             WHERE id = ?
             FOR UPDATE'
        );
        $statement->execute([$requestId]);
        $row = $statement->fetch(PDO::FETCH_ASSOC);

        return $row === false ? null : $row;
    }

    public function requestDaysForUpdate(int $requestId): array
    {
        $statement = $this->db->prepare(
            'SELECT id, request_id, work_date, day_kind, requested_units, requested_minutes,
                    consumed_units, consumed_minutes, entitlement_period_key, allocation_key
             FROM staff_leave_request_days
             WHERE request_id = ?
             ORDER BY work_date ASC, id ASC
             FOR UPDATE'
        );
        $statement->execute([$requestId]);

        return $statement->fetchAll(PDO::FETCH_ASSOC);
    }

    public function effectByIdentityForUpdate(string $effectKey, string $idempotencyKey): ?array
    {
        $statement = $this->db->prepare(
            'SELECT id, effect_key, idempotency_key, resource_type, resource_id,
                    target_module, fact_type, units, effective_period, payload, status,
                    result_ref, last_error, attempts, next_attempt_at, completed_at
             FROM staff_external_effects
             WHERE effect_key = ? OR idempotency_key = ?
             FOR UPDATE'
        );
        $statement->execute([$effectKey, $idempotencyKey]);
        $rows = $statement->fetchAll(PDO::FETCH_ASSOC);
        if (count($rows) > 1) {
            throw new \RuntimeException('LEAVE_FINANCE_EFFECT_IDENTITY_CONFLICT');
        }

        return $rows[0] ?? null;
    }

    public function effectForUpdate(int $effectId): ?array
    {
        $statement = $this->db->prepare(
            'SELECT id, effect_key, idempotency_key, resource_type, resource_id,
                    target_module, fact_type, units, effective_period, payload, status,
                    result_ref, last_error, attempts, next_attempt_at, completed_at
             FROM staff_external_effects
             WHERE id = ?
             FOR UPDATE'
        );
        $statement->execute([$effectId]);
        $row = $statement->fetch(PDO::FETCH_ASSOC);

        return $row === false ? null : $row;
    }

    public function dueEffectIdsForDispatch(int $limit, string $dueAt): array
    {
        $limit = max(1, min(200, $limit));
        $statement = $this->db->prepare(
            "SELECT id
             FROM staff_external_effects
             WHERE resource_type = 'leave_request'
               AND target_module = 'finance'
               AND (
                    (status IN ('pending', 'retry') AND (next_attempt_at IS NULL OR next_attempt_at <= ?))
                    OR (status = 'processing' AND next_attempt_at IS NOT NULL AND next_attempt_at <= ?)
               )
             ORDER BY
                 CASE WHEN next_attempt_at IS NULL THEN 0 ELSE 1 END ASC,
                 next_attempt_at ASC,
                 id ASC
             LIMIT {$limit}"
        );
        $statement->execute([$dueAt, $dueAt]);

        return array_map('intval', $statement->fetchAll(PDO::FETCH_COLUMN));
    }

    public function insertEffect(array $effect): int
    {
        $statement = $this->db->prepare(
            'INSERT INTO staff_external_effects
                (effect_key, idempotency_key, resource_type, resource_id, target_module,
                 fact_type, units, effective_period, payload, status, attempts, next_attempt_at)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 0, NULL)'
        );
        $statement->execute([
            (string) $effect['effect_key'],
            (string) $effect['idempotency_key'],
            (string) $effect['resource_type'],
            (int) $effect['resource_id'],
            (string) $effect['target_module'],
            (string) $effect['fact_type'],
            (string) $effect['units'],
            (string) $effect['effective_period'],
            (string) $effect['payload'],
            (string) $effect['status'],
        ]);

        return (int) $this->db->lastInsertId();
    }

    public function claimEffect(int $effectId, string $claimedAt, string $leaseUntil): bool
    {
        $statement = $this->db->prepare(
            "UPDATE staff_external_effects
             SET status = 'processing',
                 attempts = attempts + 1,
                 next_attempt_at = ?,
                 last_error = NULL
             WHERE id = ?
               AND (
                    (status IN ('pending', 'retry') AND (next_attempt_at IS NULL OR next_attempt_at <= ?))
                    OR (status = 'processing' AND next_attempt_at IS NOT NULL AND next_attempt_at <= ?)
               )"
        );
        $statement->execute([$leaseUntil, $effectId, $claimedAt, $claimedAt]);

        return $statement->rowCount() === 1;
    }

    public function markEffectAccepted(int $effectId, ?string $financeReference, string $completedAt): bool
    {
        $statement = $this->db->prepare(
            "UPDATE staff_external_effects
             SET status = 'accepted',
                 result_ref = ?,
                 last_error = NULL,
                 next_attempt_at = NULL,
                 completed_at = ?
             WHERE id = ? AND status = 'processing'"
        );
        $statement->execute([$financeReference, $completedAt, $effectId]);

        return $statement->rowCount() === 1;
    }

    public function markEffectForRetry(int $effectId, string $reasonCode, string $nextAttemptAt): bool
    {
        $statement = $this->db->prepare(
            "UPDATE staff_external_effects
             SET status = 'retry',
                 last_error = ?,
                 next_attempt_at = ?
             WHERE id = ? AND status = 'processing'"
        );
        $statement->execute([$reasonCode, $nextAttemptAt, $effectId]);

        return $statement->rowCount() === 1;
    }
}
