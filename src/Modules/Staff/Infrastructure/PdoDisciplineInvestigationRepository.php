<?php

declare(strict_types=1);

namespace EduCore\Modules\Staff\Infrastructure;

use EduCore\Modules\Staff\Contracts\DisciplineInvestigationRepository;
use PDO;
use PDOException;

/**
 * PDO adapter for Staff-owned investigation and evidence records.
 *
 * It does not access the linked source resource other than retaining its scalar
 * type/identifier on the Staff evidence record.
 */
final class PdoDisciplineInvestigationRepository implements DisciplineInvestigationRepository
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

    public function investigationByIdempotencyForUpdate(string $idempotencyKey): ?array
    {
        return $this->oneForUpdate(
            'SELECT * FROM staff_discipline_investigations WHERE idempotency_key = ? FOR UPDATE',
            [$idempotencyKey]
        );
    }

    public function investigationForUpdate(int $investigationId): ?array
    {
        return $this->oneForUpdate(
            'SELECT * FROM staff_discipline_investigations WHERE id = ? FOR UPDATE',
            [$investigationId]
        );
    }

    public function insertInvestigation(array $investigation): int
    {
        $statement = $this->db->prepare(
            "INSERT INTO staff_discipline_investigations (
                case_id, investigator_user_id, assigned_by_user_id, assigned_at,
                started_at, status, allegation, confidentiality_level,
                idempotency_key, investigation_hash
            ) VALUES (
                :case_id, :investigator_user_id, :assigned_by_user_id, :assigned_at,
                :started_at, 'in_progress', :allegation, :confidentiality_level,
                :idempotency_key, :investigation_hash
            )"
        );
        $statement->execute([
            'case_id' => (int) $investigation['case_id'],
            'investigator_user_id' => (int) $investigation['investigator_user_id'],
            'assigned_by_user_id' => (int) $investigation['assigned_by_user_id'],
            'assigned_at' => (string) $investigation['assigned_at'],
            'started_at' => (string) $investigation['started_at'],
            'allegation' => $investigation['allegation'] ?? null,
            'confidentiality_level' => (string) $investigation['confidentiality_level'],
            'idempotency_key' => (string) $investigation['idempotency_key'],
            'investigation_hash' => (string) $investigation['investigation_hash'],
        ]);

        return (int) $this->db->lastInsertId();
    }

    public function completeInvestigation(
        int $investigationId,
        int $expectedLockVersion,
        string $findings,
        string $recommendation,
        string $completedAt
    ): bool {
        $statement = $this->db->prepare(
            "UPDATE staff_discipline_investigations
             SET status = 'completed',
                 findings = :findings,
                 recommendation = :recommendation,
                 completed_at = :completed_at,
                 lock_version = lock_version + 1
             WHERE id = :id
               AND status = 'in_progress'
               AND lock_version = :lock_version"
        );
        $statement->execute([
            'id' => $investigationId,
            'lock_version' => $expectedLockVersion,
            'findings' => $findings,
            'recommendation' => $recommendation,
            'completed_at' => $completedAt,
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

    public function evidenceByIdempotencyForUpdate(string $idempotencyKey): ?array
    {
        return $this->oneForUpdate(
            'SELECT * FROM staff_discipline_evidence WHERE idempotency_key = ? FOR UPDATE',
            [$idempotencyKey]
        );
    }

    public function evidenceForUpdate(int $evidenceId): ?array
    {
        return $this->oneForUpdate(
            'SELECT * FROM staff_discipline_evidence WHERE id = ? FOR UPDATE',
            [$evidenceId]
        );
    }

    public function insertEvidence(array $evidence): int
    {
        $statement = $this->db->prepare(
            "INSERT INTO staff_discipline_evidence (
                case_id, investigation_id, prior_evidence_id, evidence_kind,
                source_resource_type, source_resource_id, storage_area,
                storage_ref, original_name, mime_type, byte_size, content_sha256,
                chain_hash, evidence_summary, collected_by_user_id, collected_at,
                status, idempotency_key
            ) VALUES (
                :case_id, :investigation_id, :prior_evidence_id, :evidence_kind,
                :source_resource_type, :source_resource_id, 'private',
                :storage_ref, :original_name, :mime_type, :byte_size, :content_sha256,
                :chain_hash, :evidence_summary, :collected_by_user_id, :collected_at,
                'collected', :idempotency_key
            )"
        );
        $statement->execute([
            'case_id' => (int) $evidence['case_id'],
            'investigation_id' => $evidence['investigation_id'] ?? null,
            'prior_evidence_id' => $evidence['prior_evidence_id'] ?? null,
            'evidence_kind' => (string) $evidence['evidence_kind'],
            'source_resource_type' => $evidence['source_resource_type'] ?? null,
            'source_resource_id' => $evidence['source_resource_id'] ?? null,
            'storage_ref' => $evidence['storage_ref'] ?? null,
            'original_name' => $evidence['original_name'] ?? null,
            'mime_type' => $evidence['mime_type'] ?? null,
            'byte_size' => $evidence['byte_size'] ?? null,
            'content_sha256' => $evidence['content_sha256'] ?? null,
            'chain_hash' => (string) $evidence['chain_hash'],
            'evidence_summary' => $evidence['evidence_summary'] ?? null,
            'collected_by_user_id' => (int) $evidence['collected_by_user_id'],
            'collected_at' => (string) $evidence['collected_at'],
            'idempotency_key' => (string) $evidence['idempotency_key'],
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
