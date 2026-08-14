<?php

declare(strict_types=1);

namespace EduCore\Modules\Staff\Infrastructure;

use EduCore\Modules\Staff\Contracts\DisciplineDecisionRepository;
use PDO;
use PDOException;

/**
 * PDO adapter for Staff-owned discipline decision state only.
 *
 * It deliberately has no Finance, payroll, notification transport, Attendance,
 * or Ertaq writer. Cross-module operations remain behind their own contracts.
 */
final class PdoDisciplineDecisionRepository implements DisciplineDecisionRepository
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

    public function investigationForUpdate(int $investigationId): ?array
    {
        return $this->oneForUpdate(
            'SELECT * FROM staff_discipline_investigations WHERE id = ? FOR UPDATE',
            [$investigationId]
        );
    }

    public function decisionByIdempotencyForUpdate(string $idempotencyKey): ?array
    {
        return $this->oneForUpdate(
            'SELECT * FROM staff_discipline_decisions WHERE idempotency_key = ? FOR UPDATE',
            [$idempotencyKey]
        );
    }

    public function decisionForUpdate(int $decisionId): ?array
    {
        return $this->oneForUpdate(
            'SELECT * FROM staff_discipline_decisions WHERE id = ? FOR UPDATE',
            [$decisionId]
        );
    }

    public function nextDecisionSequenceForUpdate(int $caseId): int
    {
        $statement = $this->db->prepare(
            'SELECT COALESCE(MAX(decision_sequence), 0) + 1
             FROM staff_discipline_decisions
             WHERE case_id = ?
             FOR UPDATE'
        );
        $statement->execute([$caseId]);

        return (int) $statement->fetchColumn();
    }

    public function insertDecision(array $decision): int
    {
        $statement = $this->db->prepare(
            "INSERT INTO staff_discipline_decisions (
                case_id, investigation_id, decision_no, decision_sequence,
                sanction_code, status, prepared_by_user_id,
                effective_from, effective_to, decision_reason, policy_snapshot,
                notification_status, financial_effect_requested, decision_hash, idempotency_key
            ) VALUES (
                :case_id, :investigation_id, :decision_no, :decision_sequence,
                :sanction_code, 'proposed', :prepared_by_user_id,
                :effective_from, :effective_to, :decision_reason, :policy_snapshot,
                'pending', :financial_effect_requested, :decision_hash, :idempotency_key
            )"
        );
        $statement->execute([
            'case_id' => (int) $decision['case_id'],
            'investigation_id' => (int) $decision['investigation_id'],
            'decision_no' => (string) $decision['decision_no'],
            'decision_sequence' => (int) $decision['decision_sequence'],
            'sanction_code' => (string) $decision['sanction_code'],
            'prepared_by_user_id' => (int) $decision['prepared_by_user_id'],
            'effective_from' => $decision['effective_from'] ?? null,
            'effective_to' => $decision['effective_to'] ?? null,
            'decision_reason' => (string) $decision['decision_reason'],
            'policy_snapshot' => (string) $decision['policy_snapshot'],
            'financial_effect_requested' => (int) $decision['financial_effect_requested'],
            'decision_hash' => (string) $decision['decision_hash'],
            'idempotency_key' => (string) $decision['idempotency_key'],
        ]);

        return (int) $this->db->lastInsertId();
    }

    public function attachWorkflowInstance(
        int $decisionId,
        int $expectedLockVersion,
        int $workflowInstanceId
    ): bool {
        $statement = $this->db->prepare(
            "UPDATE staff_discipline_decisions
             SET workflow_instance_id = :workflow_instance_id,
                 lock_version = lock_version + 1
             WHERE id = :id
               AND status = 'proposed'
               AND workflow_instance_id IS NULL
               AND lock_version = :lock_version"
        );
        $statement->execute([
            'id' => $decisionId,
            'lock_version' => $expectedLockVersion,
            'workflow_instance_id' => $workflowInstanceId,
        ]);

        return $statement->rowCount() === 1;
    }

    public function issueDecision(
        int $decisionId,
        int $expectedLockVersion,
        int $decidedByUserId,
        string $decidedAt,
        string $issuedAt
    ): bool {
        $statement = $this->db->prepare(
            "UPDATE staff_discipline_decisions
             SET status = 'issued',
                 decided_by_user_id = :decided_by_user_id,
                 decided_at = :decided_at,
                 issued_at = :issued_at,
                 notification_status = 'pending',
                 lock_version = lock_version + 1
             WHERE id = :id
               AND status = 'proposed'
               AND workflow_instance_id IS NOT NULL
               AND lock_version = :lock_version"
        );
        $statement->execute([
            'id' => $decisionId,
            'lock_version' => $expectedLockVersion,
            'decided_by_user_id' => $decidedByUserId,
            'decided_at' => $decidedAt,
            'issued_at' => $issuedAt,
        ]);

        return $statement->rowCount() === 1;
    }

    public function cancelProposedDecision(int $decisionId, int $expectedLockVersion): bool
    {
        $statement = $this->db->prepare(
            "UPDATE staff_discipline_decisions
             SET status = 'cancelled',
                 notification_status = 'not_required',
                 lock_version = lock_version + 1
             WHERE id = :id
               AND status = 'proposed'
               AND workflow_instance_id IS NOT NULL
               AND lock_version = :lock_version"
        );
        $statement->execute(['id' => $decisionId, 'lock_version' => $expectedLockVersion]);

        return $statement->rowCount() === 1;
    }

    public function markNotification(
        int $decisionId,
        int $expectedLockVersion,
        string $status,
        ?string $reference,
        ?string $notifiedAt
    ): bool {
        if (!in_array($status, ['sent', 'delivery_failed'], true)) {
            throw new \InvalidArgumentException('Discipline notification status is invalid.');
        }
        $statement = $this->db->prepare(
            "UPDATE staff_discipline_decisions
             SET notification_status = :notification_status,
                 notification_reference = :notification_reference,
                 notified_at = :notified_at,
                 lock_version = lock_version + 1
             WHERE id = :id
               AND status = 'issued'
               AND lock_version = :lock_version"
        );
        $statement->execute([
            'id' => $decisionId,
            'lock_version' => $expectedLockVersion,
            'notification_status' => $status,
            'notification_reference' => $reference,
            'notified_at' => $notifiedAt,
        ]);

        return $statement->rowCount() === 1;
    }

    public function recordReceipt(int $decisionId, int $expectedLockVersion, string $receiptAt): bool
    {
        $statement = $this->db->prepare(
            "UPDATE staff_discipline_decisions
             SET notification_status = 'received',
                 receipt_at = :receipt_at,
                 lock_version = lock_version + 1
             WHERE id = :id
               AND status = 'issued'
               AND notification_status = 'sent'
               AND lock_version = :lock_version"
        );
        $statement->execute([
            'id' => $decisionId,
            'lock_version' => $expectedLockVersion,
            'receipt_at' => $receiptAt,
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
