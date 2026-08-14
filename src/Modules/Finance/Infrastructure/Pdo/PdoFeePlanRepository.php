<?php

declare(strict_types=1);

namespace EduCore\Modules\Finance\Infrastructure\Pdo;

use EduCore\Modules\Finance\Contracts\Repositories\FeePlanRepository;
use PDO;

final class PdoFeePlanRepository implements FeePlanRepository
{
    public function __construct(private PDO $db)
    {
    }

    public function createPlan(int $chargeTypeId, int $academicYearId, ?int $gradeId, string $name, int $createdBy): int
    {
        $this->db->prepare('INSERT INTO finance_fee_plans (charge_type_id, academic_year_id, stage_id, grade_id, name, status, created_by) VALUES (?, ?, NULL, ?, ?, ?, ?)')
            ->execute([$chargeTypeId, $academicYearId, $gradeId, $name, 'draft', $createdBy]);
        return (int) $this->db->lastInsertId();
    }

    public function nextVersionNumber(int $feePlanId): int
    {
        $stmt = $this->db->prepare('SELECT COALESCE(MAX(version_number), 0) + 1 FROM finance_fee_plan_versions WHERE fee_plan_id = ? FOR UPDATE');
        $stmt->execute([$feePlanId]);
        return (int) $stmt->fetchColumn();
    }

    public function findActivePlan(int $chargeTypeId, int $academicYearId, ?int $gradeId): ?array
    {
        $sql = 'SELECT fp.id, fp.charge_type_id, fp.academic_year_id, fp.grade_id, fp.name, fp.status
                FROM finance_fee_plans fp
                WHERE fp.charge_type_id = ? AND fp.academic_year_id = ? AND fp.status = ?';
        $params = [$chargeTypeId, $academicYearId, 'active'];
        if ($gradeId !== null) {
            $sql .= ' AND fp.grade_id = ?';
            $params[] = $gradeId;
        }
        $sql .= ' LIMIT 1';
        $stmt = $this->db->prepare($sql);
        $stmt->execute($params);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row ?: null;
    }

    public function findPlan(int $chargeTypeId, int $academicYearId, ?int $gradeId): ?array
    {
        $sql = 'SELECT fp.id, fp.charge_type_id, fp.academic_year_id, fp.grade_id, fp.name, fp.status
                FROM finance_fee_plans fp
                WHERE fp.charge_type_id = ? AND fp.academic_year_id = ? AND ';
        $params = [$chargeTypeId, $academicYearId];
        if ($gradeId === null) {
            $sql .= 'fp.grade_id IS NULL';
        } else {
            $sql .= 'fp.grade_id = ?';
            $params[] = $gradeId;
        }
        $sql .= ' ORDER BY fp.id DESC LIMIT 1';
        $stmt = $this->db->prepare($sql);
        $stmt->execute($params);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row ?: null;
    }

    public function findPlanById(int $feePlanId): ?array
    {
        $stmt = $this->db->prepare('SELECT * FROM finance_fee_plans WHERE id = ? LIMIT 1');
        $stmt->execute([$feePlanId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row ?: null;
    }

    public function createVersion(int $feePlanId, int $versionNumber, ?string $snapshotJson, string $effectiveFrom): int
    {
        $this->db->prepare(
            'INSERT INTO finance_fee_plan_versions (fee_plan_id, version_number, snapshot_json, effective_from, status)
             VALUES (?, ?, ?, ?, ?)'
        )->execute([$feePlanId, $versionNumber, $snapshotJson, $effectiveFrom, 'draft']);
        return (int) $this->db->lastInsertId();
    }

    public function findVersion(int $versionId): ?array
    {
        $stmt = $this->db->prepare(
            'SELECT id, fee_plan_id, version_number, snapshot_json, effective_from, effective_to, status, superseded_at
             FROM finance_fee_plan_versions WHERE id = ? LIMIT 1'
        );
        $stmt->execute([$versionId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row ?: null;
    }

    public function findActiveVersion(int $feePlanId): ?array
    {
        $stmt = $this->db->prepare('SELECT * FROM finance_fee_plan_versions WHERE fee_plan_id = ? AND status = ? ORDER BY version_number DESC LIMIT 1');
        $stmt->execute([$feePlanId, 'active']);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row ?: null;
    }

    public function supersedeVersion(int $versionId): void
    {
        $this->db->prepare(
            'UPDATE finance_fee_plan_versions SET status = ?, superseded_at = NOW() WHERE id = ?'
        )->execute(['superseded', $versionId]);
    }

    public function activateVersion(int $versionId): void
    {
        $version = $this->findVersion($versionId);
        if ($version === null || (string) $version['status'] !== 'draft') {
            throw new \RuntimeException('Only a draft fee-plan version can be activated.');
        }
        $this->db->prepare('UPDATE finance_fee_plan_versions SET status = ?, superseded_at = NOW() WHERE fee_plan_id = ? AND status = ?')
            ->execute(['superseded', (int) $version['fee_plan_id'], 'active']);
        $this->db->prepare('UPDATE finance_fee_plan_versions SET status = ? WHERE id = ?')->execute(['active', $versionId]);
        $this->db->prepare('UPDATE finance_fee_plans SET status = ? WHERE id = ?')->execute(['active', (int) $version['fee_plan_id']]);
    }

    public function isVersionUsed(int $versionId): bool
    {
        $stmt = $this->db->prepare('SELECT COUNT(*) FROM finance_student_contracts WHERE fee_plan_version_id = ?');
        $stmt->execute([$versionId]);
        return (int) $stmt->fetchColumn() > 0;
    }

    public function isPlanUsed(int $feePlanId): bool
    {
        $stmt = $this->db->prepare(
            'SELECT COUNT(*)
             FROM finance_student_contracts fsc
             JOIN finance_fee_plan_versions fpv ON fpv.id = fsc.fee_plan_version_id
             WHERE fpv.fee_plan_id = ?'
        );
        $stmt->execute([$feePlanId]);
        return (int) $stmt->fetchColumn() > 0;
    }

    public function archivePlan(int $feePlanId): void
    {
        $this->db->prepare('UPDATE finance_fee_plans SET status = ? WHERE id = ?')
            ->execute(['archived', $feePlanId]);
    }

    public function addInstallment(int $versionId, string $name, string $grossAmount, ?string $dueDate, int $displayOrder): int
    {
        $this->db->prepare(
            'INSERT INTO finance_fee_plan_installments (fee_plan_version_id, installment_name, gross_amount, due_date, display_order)
             VALUES (?, ?, ?, ?, ?)'
        )->execute([$versionId, $name, $grossAmount, $dueDate, $displayOrder]);
        return (int) $this->db->lastInsertId();
    }

    public function installmentsForVersion(int $versionId): array
    {
        $stmt = $this->db->prepare(
            'SELECT id, installment_name, gross_amount, due_date, display_order
             FROM finance_fee_plan_installments
             WHERE fee_plan_version_id = ?
             ORDER BY display_order'
        );
        $stmt->execute([$versionId]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }
}
