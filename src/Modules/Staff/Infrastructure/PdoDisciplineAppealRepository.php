<?php

declare(strict_types=1);

namespace EduCore\Modules\Staff\Infrastructure;

use EduCore\Modules\Staff\Contracts\DisciplineAppealRepository;
use PDO;
use PDOException;

/**
 * PDO adapter for the append-only discipline appeal, temporary-measure, and
 * evidence-based reopening records owned by Staff.
 */
final class PdoDisciplineAppealRepository implements DisciplineAppealRepository
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

    public function lockUser(int $userId): bool
    {
        $statement = $this->db->prepare('SELECT id FROM users WHERE id = ? FOR UPDATE');
        $statement->execute([$userId]);

        return $statement->fetchColumn() !== false;
    }

    public function caseForUpdate(int $caseId): ?array
    {
        return $this->oneForUpdate(
            'SELECT c.*, i.reported_by_user_id AS incident_reported_by_user_id
             FROM staff_discipline_cases c
             INNER JOIN staff_discipline_incidents i ON i.id = c.incident_id
             WHERE c.id = ?
             FOR UPDATE',
            [$caseId]
        );
    }

    public function decisionForUpdate(int $decisionId): ?array
    {
        return $this->oneForUpdate(
            'SELECT * FROM staff_discipline_decisions WHERE id = ? FOR UPDATE',
            [$decisionId]
        );
    }

    public function investigationForUpdate(int $investigationId): ?array
    {
        return $this->oneForUpdate(
            'SELECT * FROM staff_discipline_investigations WHERE id = ? FOR UPDATE',
            [$investigationId]
        );
    }

    public function evidenceForUpdate(int $evidenceId): ?array
    {
        return $this->oneForUpdate(
            'SELECT * FROM staff_discipline_evidence WHERE id = ? FOR UPDATE',
            [$evidenceId]
        );
    }

    public function appealByIdempotencyForUpdate(string $idempotencyKey): ?array
    {
        return $this->oneForUpdate(
            'SELECT * FROM staff_discipline_appeals WHERE idempotency_key = ? FOR UPDATE',
            [$idempotencyKey]
        );
    }

    public function appealForUpdate(int $appealId): ?array
    {
        return $this->oneForUpdate(
            'SELECT * FROM staff_discipline_appeals WHERE id = ? FOR UPDATE',
            [$appealId]
        );
    }

    public function activeAppealForDecisionAndAppellantForUpdate(int $decisionId, int $appellantUserId): ?array
    {
        return $this->oneForUpdate(
            "SELECT * FROM staff_discipline_appeals
             WHERE decision_id = ?
               AND appellant_user_id = ?
               AND status IN ('submitted', 'under_review')
             ORDER BY id DESC
             LIMIT 1
             FOR UPDATE",
            [$decisionId, $appellantUserId]
        );
    }

    public function insertAppeal(array $appeal): int
    {
        $statement = $this->db->prepare(
            "INSERT INTO staff_discipline_appeals (
                case_id, decision_id, appellant_user_id, status, submitted_at,
                due_at, appeal_reason, suspends_execution, suspension_reason,
                idempotency_key, appeal_hash
            ) VALUES (
                :case_id, :decision_id, :appellant_user_id, 'submitted', :submitted_at,
                :due_at, :appeal_reason, :suspends_execution, :suspension_reason,
                :idempotency_key, :appeal_hash
            )"
        );
        $statement->execute([
            'case_id' => (int) $appeal['case_id'],
            'decision_id' => (int) $appeal['decision_id'],
            'appellant_user_id' => (int) $appeal['appellant_user_id'],
            'submitted_at' => (string) $appeal['submitted_at'],
            'due_at' => (string) $appeal['due_at'],
            'appeal_reason' => (string) $appeal['appeal_reason'],
            'suspends_execution' => (int) $appeal['suspends_execution'],
            'suspension_reason' => $appeal['suspension_reason'] ?? null,
            'idempotency_key' => (string) $appeal['idempotency_key'],
            'appeal_hash' => (string) $appeal['appeal_hash'],
        ]);

        return (int) $this->db->lastInsertId();
    }

    public function assignAppealReviewer(int $appealId, int $expectedLockVersion, int $reviewerUserId): bool
    {
        $statement = $this->db->prepare(
            "UPDATE staff_discipline_appeals
             SET reviewer_user_id = :reviewer_user_id,
                 status = 'under_review',
                 lock_version = lock_version + 1
             WHERE id = :id
               AND status = 'submitted'
               AND reviewer_user_id IS NULL
               AND lock_version = :lock_version"
        );
        $statement->execute([
            'id' => $appealId,
            'lock_version' => $expectedLockVersion,
            'reviewer_user_id' => $reviewerUserId,
        ]);

        return $statement->rowCount() === 1;
    }

    public function resolveAppeal(
        int $appealId,
        int $expectedLockVersion,
        string $outcome,
        string $outcomeReason,
        string $reviewedAt
    ): bool {
        if (!in_array($outcome, ['upheld', 'amended', 'revoked'], true)) {
            throw new \InvalidArgumentException('Discipline appeal outcome is invalid.');
        }
        $statement = $this->db->prepare(
            'UPDATE staff_discipline_appeals
             SET status = :status,
                 outcome_reason = :outcome_reason,
                 reviewed_at = :reviewed_at,
                 lock_version = lock_version + 1
             WHERE id = :id
               AND status = :prior_status
               AND reviewer_user_id IS NOT NULL
               AND lock_version = :lock_version'
        );
        $statement->execute([
            'id' => $appealId,
            'lock_version' => $expectedLockVersion,
            'status' => $outcome,
            'outcome_reason' => $outcomeReason,
            'reviewed_at' => $reviewedAt,
            'prior_status' => 'under_review',
        ]);

        return $statement->rowCount() === 1;
    }

    public function withdrawAppeal(int $appealId, int $expectedLockVersion): bool
    {
        $statement = $this->db->prepare(
            "UPDATE staff_discipline_appeals
             SET status = 'withdrawn',
                 lock_version = lock_version + 1
             WHERE id = :id
               AND status IN ('submitted', 'under_review')
               AND lock_version = :lock_version"
        );
        $statement->execute(['id' => $appealId, 'lock_version' => $expectedLockVersion]);

        return $statement->rowCount() === 1;
    }

    public function expireAppeal(int $appealId, int $expectedLockVersion, string $reviewedAt): bool
    {
        $statement = $this->db->prepare(
            "UPDATE staff_discipline_appeals
             SET status = 'expired',
                 reviewed_at = :reviewed_at,
                 lock_version = lock_version + 1
             WHERE id = :id
               AND status IN ('submitted', 'under_review')
               AND lock_version = :lock_version"
        );
        $statement->execute([
            'id' => $appealId,
            'lock_version' => $expectedLockVersion,
            'reviewed_at' => $reviewedAt,
        ]);

        return $statement->rowCount() === 1;
    }

    public function transitionCase(
        int $caseId,
        int $expectedLockVersion,
        string $fromStatus,
        string $toStatus
    ): bool {
        $statement = $this->db->prepare(
            'UPDATE staff_discipline_cases
             SET status = :to_status,
                 lock_version = lock_version + 1
             WHERE id = :id
               AND status = :from_status
               AND lock_version = :lock_version'
        );
        $statement->execute([
            'id' => $caseId,
            'lock_version' => $expectedLockVersion,
            'from_status' => $fromStatus,
            'to_status' => $toStatus,
        ]);

        return $statement->rowCount() === 1;
    }

    public function interimByIdempotencyForUpdate(string $idempotencyKey): ?array
    {
        return $this->oneForUpdate(
            'SELECT * FROM staff_discipline_interim_measures WHERE idempotency_key = ? FOR UPDATE',
            [$idempotencyKey]
        );
    }

    public function interimForUpdate(int $measureId): ?array
    {
        return $this->oneForUpdate(
            'SELECT * FROM staff_discipline_interim_measures WHERE id = ? FOR UPDATE',
            [$measureId]
        );
    }

    public function insertInterim(array $measure): int
    {
        $statement = $this->db->prepare(
            "INSERT INTO staff_discipline_interim_measures (
                case_id, basis_evidence_id, measure_type, status, reason,
                access_effect, requested_by_user_id, starts_at, ends_at,
                review_due_at, idempotency_key, measure_hash
            ) VALUES (
                :case_id, :basis_evidence_id, :measure_type, 'draft', :reason,
                :access_effect, :requested_by_user_id, :starts_at, :ends_at,
                :review_due_at, :idempotency_key, :measure_hash
            )"
        );
        $statement->execute([
            'case_id' => (int) $measure['case_id'],
            'basis_evidence_id' => $measure['basis_evidence_id'] ?? null,
            'measure_type' => (string) $measure['measure_type'],
            'reason' => (string) $measure['reason'],
            'access_effect' => $measure['access_effect'] ?? null,
            'requested_by_user_id' => (int) $measure['requested_by_user_id'],
            'starts_at' => (string) $measure['starts_at'],
            'ends_at' => (string) $measure['ends_at'],
            'review_due_at' => $measure['review_due_at'] ?? null,
            'idempotency_key' => (string) $measure['idempotency_key'],
            'measure_hash' => (string) $measure['measure_hash'],
        ]);

        return (int) $this->db->lastInsertId();
    }

    public function activateInterim(
        int $measureId,
        int $expectedLockVersion,
        int $authorizedByUserId,
        string $authorizedAt
    ): bool {
        $statement = $this->db->prepare(
            "UPDATE staff_discipline_interim_measures
             SET status = 'active',
                 authorized_by_user_id = :authorized_by_user_id,
                 authorized_at = :authorized_at,
                 lock_version = lock_version + 1
             WHERE id = :id
               AND status = 'draft'
               AND lock_version = :lock_version"
        );
        $statement->execute([
            'id' => $measureId,
            'lock_version' => $expectedLockVersion,
            'authorized_by_user_id' => $authorizedByUserId,
            'authorized_at' => $authorizedAt,
        ]);

        return $statement->rowCount() === 1;
    }

    public function resolveInterim(
        int $measureId,
        int $expectedLockVersion,
        string $outcome,
        ?int $reviewedByUserId,
        string $reviewedAt,
        ?string $resolutionReason
    ): bool {
        if (!in_array($outcome, ['expired', 'revoked', 'completed'], true)) {
            throw new \InvalidArgumentException('Discipline interim outcome is invalid.');
        }
        $statement = $this->db->prepare(
            'UPDATE staff_discipline_interim_measures
             SET status = :status,
                 reviewed_by_user_id = :reviewed_by_user_id,
                 reviewed_at = :reviewed_at,
                 resolution_reason = :resolution_reason,
                 lock_version = lock_version + 1
             WHERE id = :id
               AND status = :prior_status
               AND lock_version = :lock_version'
        );
        $statement->execute([
            'id' => $measureId,
            'lock_version' => $expectedLockVersion,
            'status' => $outcome,
            'reviewed_by_user_id' => $reviewedByUserId,
            'reviewed_at' => $reviewedAt,
            'resolution_reason' => $resolutionReason,
            'prior_status' => 'active',
        ]);

        return $statement->rowCount() === 1;
    }

    public function reopenEventByIdempotencyForUpdate(string $idempotencyKey): ?array
    {
        return $this->oneForUpdate(
            'SELECT * FROM staff_discipline_reopen_events WHERE idempotency_key = ? FOR UPDATE',
            [$idempotencyKey]
        );
    }

    public function reopenEventForUpdate(int $reopenEventId): ?array
    {
        return $this->oneForUpdate(
            'SELECT * FROM staff_discipline_reopen_events WHERE id = ? FOR UPDATE',
            [$reopenEventId]
        );
    }

    public function reopenResolutionForRequestForUpdate(int $requestEventId): ?array
    {
        return $this->oneForUpdate(
            'SELECT * FROM staff_discipline_reopen_events WHERE request_event_id = ? FOR UPDATE',
            [$requestEventId]
        );
    }

    public function insertReopenEvent(array $event): int
    {
        $statement = $this->db->prepare(
            'INSERT INTO staff_discipline_reopen_events (
                request_event_id, case_id, prior_decision_id, new_evidence_id,
                prior_case_status, status, requested_by_user_id, requested_at,
                authorized_by_user_id, authorized_at, reopen_reason,
                idempotency_key, reopen_hash
            ) VALUES (
                :request_event_id, :case_id, :prior_decision_id, :new_evidence_id,
                :prior_case_status, :status, :requested_by_user_id, :requested_at,
                :authorized_by_user_id, :authorized_at, :reopen_reason,
                :idempotency_key, :reopen_hash
            )'
        );
        $statement->execute([
            'request_event_id' => $event['request_event_id'] ?? null,
            'case_id' => (int) $event['case_id'],
            'prior_decision_id' => $event['prior_decision_id'] ?? null,
            'new_evidence_id' => (int) $event['new_evidence_id'],
            'prior_case_status' => (string) $event['prior_case_status'],
            'status' => (string) $event['status'],
            'requested_by_user_id' => $event['requested_by_user_id'] ?? null,
            'requested_at' => (string) $event['requested_at'],
            'authorized_by_user_id' => $event['authorized_by_user_id'] ?? null,
            'authorized_at' => $event['authorized_at'] ?? null,
            'reopen_reason' => (string) $event['reopen_reason'],
            'idempotency_key' => (string) $event['idempotency_key'],
            'reopen_hash' => (string) $event['reopen_hash'],
        ]);

        return (int) $this->db->lastInsertId();
    }

    /** @param list<mixed> $params @return array<string,mixed>|null */
    private function oneForUpdate(string $sql, array $params): ?array
    {
        $statement = $this->db->prepare($sql);
        $statement->execute($params);
        $row = $statement->fetch(PDO::FETCH_ASSOC);

        return $row === false ? null : $row;
    }

    private function isRetryableConcurrencyFailure(\Throwable $exception): bool
    {
        if (!$exception instanceof PDOException) {
            return false;
        }
        $code = (string) $exception->getCode();
        if (in_array($code, ['40001', '1213'], true)) {
            return true;
        }
        $message = strtolower($exception->getMessage());

        return str_contains($message, 'deadlock') || str_contains($message, 'serialization failure');
    }
}
