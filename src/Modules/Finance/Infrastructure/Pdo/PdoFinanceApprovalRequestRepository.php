<?php

declare(strict_types=1);

namespace EduCore\Modules\Finance\Infrastructure\Pdo;

use EduCore\Modules\Finance\Contracts\Repositories\FinanceApprovalRequestRepository;
use PDO;
use RuntimeException;

final class PdoFinanceApprovalRequestRepository implements FinanceApprovalRequestRepository
{
    public function __construct(private PDO $db) {}

    public function create(string $operationType, array $payload, int $requestedBy, string $requestKey): int
    {
        $json = json_encode($payload, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE);
        $this->db->prepare('INSERT INTO finance_approval_requests (operation_type, payload_json, request_key, requested_by) VALUES (?, ?, ?, ?)')->execute([$operationType, $json, $requestKey, $requestedBy]);
        return (int) $this->db->lastInsertId();
    }

    public function findByRequestKey(string $requestKey): ?array
    {
        $stmt = $this->db->prepare('SELECT * FROM finance_approval_requests WHERE request_key = ? LIMIT 1');
        $stmt->execute([$requestKey]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row ?: null;
    }

    public function lockById(int $id): ?array
    {
        if (!$this->db->inTransaction()) { throw new RuntimeException('Approval request locking requires an active transaction.'); }
        $stmt = $this->db->prepare('SELECT * FROM finance_approval_requests WHERE id = ? FOR UPDATE');
        $stmt->execute([$id]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row ?: null;
    }

    public function markApproved(int $id, int $decidedBy, string $resultRefType, int $resultRefId): void
    {
        $stmt = $this->db->prepare("UPDATE finance_approval_requests SET status='approved', decided_by=?, decided_at=NOW(), result_ref_type=?, result_ref_id=? WHERE id=? AND status='pending'");
        $stmt->execute([$decidedBy, $resultRefType, $resultRefId, $id]);
        if ($stmt->rowCount() !== 1) { throw new RuntimeException('Approval request is no longer pending.'); }
    }

    public function markRejected(int $id, int $decidedBy, string $reason): void
    {
        $stmt = $this->db->prepare("UPDATE finance_approval_requests SET status='rejected', decided_by=?, decided_at=NOW(), decision_reason=? WHERE id=? AND status='pending'");
        $stmt->execute([$decidedBy, trim($reason), $id]);
        if ($stmt->rowCount() !== 1) { throw new RuntimeException('Approval request is no longer pending.'); }
    }
}
