<?php

declare(strict_types=1);

namespace EduCore\Modules\Staff\Infrastructure;

use EduCore\Modules\Staff\Contracts\LeaveStaffingOverrideApprovalQuery;
use EduCore\Modules\Staff\Contracts\LeaveStaffingOverrideRepository;
use JsonException;
use PDO;
use RuntimeException;

/**
 * PDO adapter for immutable staffing-exception decisions.
 *
 * It returns only the minimum evidence needed at normal leave submission;
 * the decision reason and assessment snapshot remain write/audit evidence.
 */
final class PdoLeaveStaffingOverrideRepository implements LeaveStaffingOverrideRepository, LeaveStaffingOverrideApprovalQuery
{
    public function __construct(private PDO $db)
    {
    }

    public function decisionByIdempotencyForUpdate(string $idempotencyKey): ?array
    {
        $statement = $this->db->prepare(
            'SELECT id, leave_request_id, request_hash, decision_outcome,
                    required_role_keys, requirement_fingerprint, reason_hash,
                    decision_idempotency_key, decision_hash, decided_by, decided_at
             FROM staff_leave_staffing_overrides
             WHERE decision_idempotency_key = ?
             FOR UPDATE'
        );
        $statement->execute([$idempotencyKey]);

        return $this->row($statement->fetch(PDO::FETCH_ASSOC));
    }

    public function approvedDecisionForRequestHashForUpdate(int $requestId, string $requestHash): ?array
    {
        $statement = $this->db->prepare(
            "SELECT id, leave_request_id, request_hash, decision_outcome,
                    required_role_keys, requirement_fingerprint, decision_hash, decided_by, decided_at
             FROM staff_leave_staffing_overrides
             WHERE leave_request_id = ?
               AND request_hash = ?
               AND decision_outcome = 'approved'
             FOR UPDATE"
        );
        $statement->execute([$requestId, $requestHash]);

        return $this->row($statement->fetch(PDO::FETCH_ASSOC));
    }

    public function insertDecision(array $decision): int
    {
        $statement = $this->db->prepare(
            'INSERT INTO staff_leave_staffing_overrides (
                leave_request_id, request_hash, decision_outcome, required_role_keys,
                requirement_fingerprint, assessment_snapshot, decision_reason, reason_hash,
                decision_idempotency_key, decision_hash, decided_by, decided_at
             ) VALUES (
                :leave_request_id, :request_hash, :decision_outcome, :required_role_keys,
                :requirement_fingerprint, :assessment_snapshot, :decision_reason, :reason_hash,
                :decision_idempotency_key, :decision_hash, :decided_by, :decided_at
             )'
        );
        $statement->execute([
            'leave_request_id' => (int) ($decision['leave_request_id'] ?? 0),
            'request_hash' => (string) ($decision['request_hash'] ?? ''),
            'decision_outcome' => (string) ($decision['decision_outcome'] ?? ''),
            'required_role_keys' => $this->encode($decision['required_role_keys'] ?? null),
            'requirement_fingerprint' => (string) ($decision['requirement_fingerprint'] ?? ''),
            'assessment_snapshot' => $this->encode($decision['assessment_snapshot'] ?? null),
            'decision_reason' => (string) ($decision['decision_reason'] ?? ''),
            'reason_hash' => (string) ($decision['reason_hash'] ?? ''),
            'decision_idempotency_key' => (string) ($decision['decision_idempotency_key'] ?? ''),
            'decision_hash' => (string) ($decision['decision_hash'] ?? ''),
            'decided_by' => (int) ($decision['decided_by'] ?? 0),
            'decided_at' => (string) ($decision['decided_at'] ?? ''),
        ]);

        return (int) $this->db->lastInsertId();
    }

    /** @param array<string,mixed>|false $row @return array<string,mixed>|null */
    private function row(array|false $row): ?array
    {
        if ($row === false) {
            return null;
        }
        $roles = $this->decode((string) ($row['required_role_keys'] ?? ''));
        if (!is_array($roles)) {
            throw new RuntimeException('LEAVE_STAFFING_OVERRIDE_EVIDENCE_INVALID');
        }

        return [
            'id' => (int) ($row['id'] ?? 0),
            'leave_request_id' => (int) ($row['leave_request_id'] ?? 0),
            'request_hash' => (string) ($row['request_hash'] ?? ''),
            'decision_outcome' => (string) ($row['decision_outcome'] ?? ''),
            'required_role_keys' => array_values($roles),
            'requirement_fingerprint' => (string) ($row['requirement_fingerprint'] ?? ''),
            'reason_hash' => isset($row['reason_hash']) ? (string) $row['reason_hash'] : null,
            'decision_idempotency_key' => isset($row['decision_idempotency_key'])
                ? (string) $row['decision_idempotency_key']
                : null,
            'decision_hash' => (string) ($row['decision_hash'] ?? ''),
            'decided_by' => (int) ($row['decided_by'] ?? 0),
            'decided_at' => isset($row['decided_at']) && $row['decided_at'] !== null
                ? (string) $row['decided_at']
                : null,
        ];
    }

    private function encode(mixed $value): string
    {
        try {
            return json_encode($value, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        } catch (JsonException $exception) {
            throw new RuntimeException('LEAVE_STAFFING_OVERRIDE_SERIALIZATION_INVALID', 0, $exception);
        }
    }

    private function decode(string $value): mixed
    {
        try {
            return json_decode($value, true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException $exception) {
            throw new RuntimeException('LEAVE_STAFFING_OVERRIDE_EVIDENCE_INVALID', 0, $exception);
        }
    }
}
