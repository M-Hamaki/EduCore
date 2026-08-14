<?php

declare(strict_types=1);

namespace EduCore\Modules\Staff\Infrastructure;

use EduCore\Modules\Staff\Contracts\DisciplineCaseRepository;
use JsonException;
use PDO;
use PDOException;
use RuntimeException;

/**
 * PDO implementation for the Staff-owned discipline case aggregate.
 *
 * It locks the subject user row before creating an incident/case and locks
 * every changed aggregate row. It does not read or mutate the linked
 * Attendance, Ertaq, notification, or Finance resource.
 */
final class PdoDisciplineCaseRepository implements DisciplineCaseRepository
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

    public function lockStaff(int $staffUserId): bool
    {
        $statement = $this->db->prepare('SELECT id FROM users WHERE id = ? FOR UPDATE');
        $statement->execute([$staffUserId]);

        return $statement->fetchColumn() !== false;
    }

    public function incidentByCreateIdempotencyForUpdate(string $idempotencyKey): ?array
    {
        return $this->oneForUpdate(
            'SELECT * FROM staff_discipline_incidents WHERE create_idempotency_key = ? FOR UPDATE',
            [$idempotencyKey]
        );
    }

    public function incidentForUpdate(int $incidentId): ?array
    {
        return $this->oneForUpdate(
            'SELECT * FROM staff_discipline_incidents WHERE id = ? FOR UPDATE',
            [$incidentId]
        );
    }

    public function insertIncident(array $incident): int
    {
        $statement = $this->db->prepare(
            'INSERT INTO staff_discipline_incidents (
                incident_no, subject_staff_user_id, reported_by_user_id, occurred_at,
                source_resource_type, source_resource_id, source_reference_snapshot,
                classification, confidentiality_level, description, status,
                create_idempotency_key, incident_hash
            ) VALUES (
                :incident_no, :subject_staff_user_id, :reported_by_user_id, :occurred_at,
                :source_resource_type, :source_resource_id, :source_reference_snapshot,
                :classification, :confidentiality_level, :description, \'reported\',
                :create_idempotency_key, :incident_hash
            )'
        );
        $statement->execute([
            'incident_no' => (string) $incident['incident_no'],
            'subject_staff_user_id' => $incident['subject_staff_user_id'] ?? null,
            'reported_by_user_id' => (int) $incident['reported_by_user_id'],
            'occurred_at' => $incident['occurred_at'] ?? null,
            'source_resource_type' => $incident['source_resource_type'] ?? null,
            'source_resource_id' => $incident['source_resource_id'] ?? null,
            'source_reference_snapshot' => $this->json($incident['source_reference_snapshot'] ?? null),
            'classification' => (string) $incident['classification'],
            'confidentiality_level' => (string) $incident['confidentiality_level'],
            'description' => (string) $incident['description'],
            'create_idempotency_key' => (string) $incident['create_idempotency_key'],
            'incident_hash' => (string) $incident['incident_hash'],
        ]);

        return (int) $this->db->lastInsertId();
    }

    public function markIncidentTriaged(int $incidentId, int $expectedLockVersion): bool
    {
        $statement = $this->db->prepare(
            "UPDATE staff_discipline_incidents
             SET status = 'triage', lock_version = lock_version + 1
             WHERE id = :id
               AND status = 'reported'
               AND lock_version = :lock_version"
        );
        $statement->execute(['id' => $incidentId, 'lock_version' => $expectedLockVersion]);

        return $statement->rowCount() === 1;
    }

    public function cancelIncident(
        int $incidentId,
        int $expectedLockVersion,
        int $actorId,
        string $reason,
        string $cancelledAt
    ): bool {
        $statement = $this->db->prepare(
            "UPDATE staff_discipline_incidents
             SET status = 'cancelled',
                 cancellation_reason = :reason,
                 cancelled_by_user_id = :actor_id,
                 cancelled_at = :cancelled_at,
                 lock_version = lock_version + 1
             WHERE id = :id
               AND status IN ('draft', 'reported', 'triage')
               AND lock_version = :lock_version"
        );
        $statement->execute([
            'id' => $incidentId,
            'lock_version' => $expectedLockVersion,
            'actor_id' => $actorId,
            'reason' => $reason,
            'cancelled_at' => $cancelledAt,
        ]);

        return $statement->rowCount() === 1;
    }

    public function caseByCreateIdempotencyForUpdate(string $idempotencyKey): ?array
    {
        return $this->oneForUpdate(
            'SELECT * FROM staff_discipline_cases WHERE create_idempotency_key = ? FOR UPDATE',
            [$idempotencyKey]
        );
    }

    public function caseByIncidentForUpdate(int $incidentId): ?array
    {
        return $this->oneForUpdate(
            'SELECT * FROM staff_discipline_cases WHERE incident_id = ? FOR UPDATE',
            [$incidentId]
        );
    }

    public function caseForUpdate(int $caseId): ?array
    {
        return $this->oneForUpdate(
            'SELECT * FROM staff_discipline_cases WHERE id = ? FOR UPDATE',
            [$caseId]
        );
    }

    public function insertCase(array $case): int
    {
        $statement = $this->db->prepare(
            "INSERT INTO staff_discipline_cases (
                case_no, incident_id, subject_staff_user_id, classification,
                confidentiality_level, status, opened_by_user_id, opened_at,
                create_idempotency_key, case_hash
            ) VALUES (
                :case_no, :incident_id, :subject_staff_user_id, :classification,
                :confidentiality_level, 'reported', :opened_by_user_id, :opened_at,
                :create_idempotency_key, :case_hash
            )"
        );
        $statement->execute([
            'case_no' => (string) $case['case_no'],
            'incident_id' => (int) $case['incident_id'],
            'subject_staff_user_id' => (int) $case['subject_staff_user_id'],
            'classification' => (string) $case['classification'],
            'confidentiality_level' => (string) $case['confidentiality_level'],
            'opened_by_user_id' => (int) $case['opened_by_user_id'],
            'opened_at' => (string) $case['opened_at'],
            'create_idempotency_key' => (string) $case['create_idempotency_key'],
            'case_hash' => (string) $case['case_hash'],
        ]);

        return (int) $this->db->lastInsertId();
    }

    public function transitionCase(
        int $caseId,
        int $expectedLockVersion,
        string $fromStatus,
        string $toStatus,
        array $changes
    ): bool {
        $allowedColumns = [
            'closed_by_user_id' => 'closed_by_user_id',
            'closed_at' => 'closed_at',
            'legal_hold' => 'legal_hold',
        ];
        $sets = ['status = :to_status', 'lock_version = lock_version + 1'];
        $params = [
            'id' => $caseId,
            'lock_version' => $expectedLockVersion,
            'from_status' => $fromStatus,
            'to_status' => $toStatus,
        ];
        foreach ($allowedColumns as $input => $column) {
            if (!array_key_exists($input, $changes)) {
                continue;
            }
            $sets[] = $column . ' = :' . $input;
            $params[$input] = $changes[$input];
        }
        $statement = $this->db->prepare(
            'UPDATE staff_discipline_cases
             SET ' . implode(', ', $sets) . '
             WHERE id = :id
               AND status = :from_status
               AND lock_version = :lock_version'
        );
        $statement->execute($params);

        return $statement->rowCount() === 1;
    }

    public function cancelCase(
        int $caseId,
        int $expectedLockVersion,
        string $fromStatus,
        int $actorId,
        string $reason,
        string $cancelledAt
    ): bool {
        $statement = $this->db->prepare(
            "UPDATE staff_discipline_cases
             SET status = 'cancelled',
                 cancellation_reason = :reason,
                 cancelled_by_user_id = :actor_id,
                 cancelled_at = :cancelled_at,
                 lock_version = lock_version + 1
             WHERE id = :id
               AND status = :from_status
               AND lock_version = :lock_version"
        );
        $statement->execute([
            'id' => $caseId,
            'lock_version' => $expectedLockVersion,
            'from_status' => $fromStatus,
            'actor_id' => $actorId,
            'reason' => $reason,
            'cancelled_at' => $cancelledAt,
        ]);

        return $statement->rowCount() === 1;
    }

    public function partyByIdempotencyForUpdate(string $idempotencyKey): ?array
    {
        return $this->oneForUpdate(
            'SELECT * FROM staff_discipline_case_parties WHERE idempotency_key = ? FOR UPDATE',
            [$idempotencyKey]
        );
    }

    public function partyForUpdate(int $partyId): ?array
    {
        return $this->oneForUpdate(
            'SELECT * FROM staff_discipline_case_parties WHERE id = ? FOR UPDATE',
            [$partyId]
        );
    }

    public function insertParty(array $party): int
    {
        $statement = $this->db->prepare(
            "INSERT INTO staff_discipline_case_parties (
                case_id, party_user_id, external_party_label, party_role,
                visibility_scope, status, added_by_user_id, idempotency_key, party_hash
            ) VALUES (
                :case_id, :party_user_id, :external_party_label, :party_role,
                :visibility_scope, 'active', :added_by_user_id, :idempotency_key, :party_hash
            )"
        );
        $statement->execute([
            'case_id' => (int) $party['case_id'],
            'party_user_id' => $party['party_user_id'] ?? null,
            'external_party_label' => $party['external_party_label'] ?? null,
            'party_role' => (string) $party['party_role'],
            'visibility_scope' => (string) $party['visibility_scope'],
            'added_by_user_id' => (int) $party['added_by_user_id'],
            'idempotency_key' => (string) $party['idempotency_key'],
            'party_hash' => (string) $party['party_hash'],
        ]);

        return (int) $this->db->lastInsertId();
    }

    public function declarePartyConflict(
        int $partyId,
        int $expectedLockVersion,
        string $declaration,
        string $declaredAt
    ): bool {
        $statement = $this->db->prepare(
            "UPDATE staff_discipline_case_parties
             SET conflict_declared_at = :declared_at,
                 conflict_declaration = :declaration,
                 lock_version = lock_version + 1
             WHERE id = :id
               AND status = 'active'
               AND lock_version = :lock_version"
        );
        $statement->execute([
            'id' => $partyId,
            'lock_version' => $expectedLockVersion,
            'declaration' => $declaration,
            'declared_at' => $declaredAt,
        ]);

        return $statement->rowCount() === 1;
    }

    public function withdrawParty(
        int $partyId,
        int $expectedLockVersion,
        int $actorId,
        string $reason,
        string $withdrawnAt
    ): bool {
        $statement = $this->db->prepare(
            "UPDATE staff_discipline_case_parties
             SET status = 'withdrawn',
                 withdrawn_by_user_id = :actor_id,
                 withdrawn_at = :withdrawn_at,
                 withdrawal_reason = :reason,
                 lock_version = lock_version + 1
             WHERE id = :id
               AND status = 'active'
               AND lock_version = :lock_version"
        );
        $statement->execute([
            'id' => $partyId,
            'lock_version' => $expectedLockVersion,
            'actor_id' => $actorId,
            'reason' => $reason,
            'withdrawn_at' => $withdrawnAt,
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

    private function json(mixed $value): ?string
    {
        if ($value === null) {
            return null;
        }
        try {
            return json_encode($value, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        } catch (JsonException) {
            throw new RuntimeException('DISCIPLINE_SOURCE_SNAPSHOT_SERIALIZATION_FAILED');
        }
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
