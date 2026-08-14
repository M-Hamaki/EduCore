<?php

declare(strict_types=1);

namespace EduCore\Modules\Staff\Infrastructure;

use EduCore\Modules\Staff\Contracts\DisciplineFinanceEffectRepository;
use PDO;
use RuntimeException;
use Throwable;

/**
 * PDO adapter for the Staff-owned discipline Finance fact outbox.
 *
 * This class intentionally has no Finance table query. It locks immutable
 * discipline facts and exposes conditional state transitions for a separate
 * PayrollImpactGateway dispatcher.
 */
final class PdoDisciplineFinanceEffectRepository implements DisciplineFinanceEffectRepository
{
    public function __construct(private PDO $db)
    {
    }

    public function transactional(callable $work): mixed
    {
        $ownsTransaction = !$this->db->inTransaction();
        if ($ownsTransaction) {
            $this->db->beginTransaction();
        }

        try {
            $result = $work();
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

    public function decisionForUpdate(int $decisionId): ?array
    {
        return $this->one(
            'SELECT id, case_id, status, sanction_code, policy_snapshot,
                    financial_effect_requested, decision_hash, idempotency_key,
                    issued_at, lock_version
             FROM staff_discipline_decisions
             WHERE id = ?
             FOR UPDATE',
            [$decisionId]
        );
    }

    public function caseForUpdate(int $caseId): ?array
    {
        return $this->one(
            'SELECT id, case_no, subject_staff_user_id, status, lock_version
             FROM staff_discipline_cases
             WHERE id = ?
             FOR UPDATE',
            [$caseId]
        );
    }

    public function appealForUpdate(int $appealId): ?array
    {
        return $this->one(
            'SELECT id, case_id, decision_id, appellant_user_id, status,
                    appeal_hash, lock_version
             FROM staff_discipline_appeals
             WHERE id = ?
             FOR UPDATE',
            [$appealId]
        );
    }

    public function effectByIdentityForUpdate(string $effectKey, string $idempotencyKey): ?array
    {
        $statement = $this->db->prepare(
            'SELECT *
             FROM staff_discipline_finance_effects
             WHERE effect_key = ? OR idempotency_key = ?
             FOR UPDATE'
        );
        $statement->execute([$effectKey, $idempotencyKey]);
        $rows = $statement->fetchAll(PDO::FETCH_ASSOC);
        if (count($rows) > 1) {
            throw new RuntimeException('DISCIPLINE_FINANCE_EFFECT_IDENTITY_CONFLICT');
        }

        return $rows[0] ?? null;
    }

    public function effectForUpdate(int $effectId): ?array
    {
        return $this->one(
            'SELECT * FROM staff_discipline_finance_effects WHERE id = ? FOR UPDATE',
            [$effectId]
        );
    }

    public function applyEffectsForDecisionForUpdate(int $decisionId): array
    {
        $statement = $this->db->prepare(
            "SELECT *
             FROM staff_discipline_finance_effects
             WHERE decision_id = ? AND direction = 'apply'
             ORDER BY id ASC
             FOR UPDATE"
        );
        $statement->execute([$decisionId]);

        return $statement->fetchAll(PDO::FETCH_ASSOC);
    }

    public function reversalForEffectForUpdate(int $effectId): ?array
    {
        return $this->one(
            'SELECT *
             FROM staff_discipline_finance_effects
             WHERE reverses_effect_id = ?
             FOR UPDATE',
            [$effectId]
        );
    }

    public function dueEffectIdsForDispatch(int $limit, string $dueAt): array
    {
        $limit = max(1, min(200, $limit));
        $statement = $this->db->prepare(
            "SELECT id
             FROM staff_discipline_finance_effects
             WHERE target_module = 'finance'
               AND (
                    (status IN ('pending', 'retry')
                        AND (next_attempt_at IS NULL OR next_attempt_at <= ?))
                    OR (status = 'processing'
                        AND lease_expires_at IS NOT NULL
                        AND lease_expires_at <= ?)
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
            'INSERT INTO staff_discipline_finance_effects
                (case_id, decision_id, execution_id, reverses_effect_id,
                 target_module, fact_type, effect_code, effect_key,
                 idempotency_key, direction, effective_from, effective_to,
                 units, payload_json, status, attempt_count, next_attempt_at,
                 lease_token, lease_expires_at, accepted_reference, accepted_at,
                 last_error_code, created_by_user_id)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 0, NULL,
                     NULL, NULL, NULL, NULL, NULL, ?)'
        );
        $statement->execute([
            (int) $effect['case_id'],
            (int) $effect['decision_id'],
            $effect['execution_id'] === null ? null : (int) $effect['execution_id'],
            $effect['reverses_effect_id'] === null ? null : (int) $effect['reverses_effect_id'],
            (string) $effect['target_module'],
            (string) $effect['fact_type'],
            (string) $effect['effect_code'],
            (string) $effect['effect_key'],
            (string) $effect['idempotency_key'],
            (string) $effect['direction'],
            (string) $effect['effective_from'],
            $effect['effective_to'] === null ? null : (string) $effect['effective_to'],
            (string) $effect['units'],
            $effect['payload_json'] === null ? null : (string) $effect['payload_json'],
            (string) $effect['status'],
            $effect['created_by_user_id'] === null ? null : (int) $effect['created_by_user_id'],
        ]);

        return (int) $this->db->lastInsertId();
    }

    public function claimEffect(
        int $effectId,
        string $leaseToken,
        string $claimedAt,
        string $leaseExpiresAt
    ): bool {
        $statement = $this->db->prepare(
            "UPDATE staff_discipline_finance_effects
             SET status = 'processing',
                 attempt_count = attempt_count + 1,
                 next_attempt_at = NULL,
                 lease_token = ?,
                 lease_expires_at = ?,
                 last_error_code = NULL
             WHERE id = ?
               AND (
                    (status IN ('pending', 'retry')
                        AND (next_attempt_at IS NULL OR next_attempt_at <= ?))
                    OR (status = 'processing'
                        AND lease_expires_at IS NOT NULL
                        AND lease_expires_at <= ?)
               )"
        );
        $statement->execute([$leaseToken, $leaseExpiresAt, $effectId, $claimedAt, $claimedAt]);

        return $statement->rowCount() === 1;
    }

    public function markEffectAccepted(
        int $effectId,
        string $leaseToken,
        ?string $financeReference,
        string $acceptedAt
    ): bool {
        $statement = $this->db->prepare(
            "UPDATE staff_discipline_finance_effects
             SET status = 'accepted',
                 next_attempt_at = NULL,
                 lease_token = NULL,
                 lease_expires_at = NULL,
                 accepted_reference = ?,
                 accepted_at = ?,
                 last_error_code = NULL
             WHERE id = ? AND status = 'processing' AND lease_token = ?"
        );
        $statement->execute([$financeReference, $acceptedAt, $effectId, $leaseToken]);

        return $statement->rowCount() === 1;
    }

    public function markEffectForRetry(
        int $effectId,
        string $leaseToken,
        string $reasonCode,
        string $nextAttemptAt
    ): bool {
        $statement = $this->db->prepare(
            "UPDATE staff_discipline_finance_effects
             SET status = 'retry',
                 next_attempt_at = ?,
                 lease_token = NULL,
                 lease_expires_at = NULL,
                 last_error_code = ?
             WHERE id = ? AND status = 'processing' AND lease_token = ?"
        );
        $statement->execute([$nextAttemptAt, $reasonCode, $effectId, $leaseToken]);

        return $statement->rowCount() === 1;
    }

    public function markEffectRejected(
        int $effectId,
        string $leaseToken,
        string $reasonCode
    ): bool {
        $statement = $this->db->prepare(
            "UPDATE staff_discipline_finance_effects
             SET status = 'rejected',
                 next_attempt_at = NULL,
                 lease_token = NULL,
                 lease_expires_at = NULL,
                 last_error_code = ?
             WHERE id = ? AND status = 'processing' AND lease_token = ?"
        );
        $statement->execute([$reasonCode, $effectId, $leaseToken]);

        return $statement->rowCount() === 1;
    }

    public function cancelQueuedApplyEffect(int $effectId, string $reasonCode): bool
    {
        $statement = $this->db->prepare(
            "UPDATE staff_discipline_finance_effects
             SET status = 'cancelled',
                 next_attempt_at = NULL,
                 lease_token = NULL,
                 lease_expires_at = NULL,
                 last_error_code = ?
             WHERE id = ?
               AND direction = 'apply'
               AND status IN ('pending', 'retry')"
        );
        $statement->execute([$reasonCode, $effectId]);

        return $statement->rowCount() === 1;
    }

    /** @param list<mixed> $parameters @return array<string,mixed>|null */
    private function one(string $sql, array $parameters): ?array
    {
        $statement = $this->db->prepare($sql);
        $statement->execute($parameters);
        $row = $statement->fetch(PDO::FETCH_ASSOC);

        return $row === false ? null : $row;
    }
}
