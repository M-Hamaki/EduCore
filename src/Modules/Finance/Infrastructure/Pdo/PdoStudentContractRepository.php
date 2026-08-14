<?php

declare(strict_types=1);

namespace EduCore\Modules\Finance\Infrastructure\Pdo;

use EduCore\Modules\Finance\Contracts\Repositories\StudentContractRepository;
use PDO;

final class PdoStudentContractRepository implements StudentContractRepository
{
    public function __construct(private PDO $db) {}
    public function create(int $studentAccountId, int $feePlanVersionId, string $snapshotJson, int $createdBy): int
    {
        $this->db->prepare('INSERT INTO finance_student_contracts (student_account_id, fee_plan_version_id, snapshot_json, signed_at, status, created_by) VALUES (?, ?, ?, NOW(), ?, ?)')->execute([$studentAccountId, $feePlanVersionId, $snapshotJson, 'active', $createdBy]);
        return (int) $this->db->lastInsertId();
    }
    public function findById(int $id): ?array
    {
        $stmt = $this->db->prepare('SELECT * FROM finance_student_contracts WHERE id = ? LIMIT 1');
        $stmt->execute([$id]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row ?: null;
    }
    public function findByAccountAndVersion(int $studentAccountId, int $feePlanVersionId): ?array
    {
        $stmt = $this->db->prepare('SELECT * FROM finance_student_contracts WHERE student_account_id = ? AND fee_plan_version_id = ? LIMIT 1');
        $stmt->execute([$studentAccountId, $feePlanVersionId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row ?: null;
    }
}
