<?php

declare(strict_types=1);

namespace EduCore\Modules\Finance\Infrastructure\Pdo;

use EduCore\Modules\Finance\Contracts\Repositories\StaffCompensationComponentRepository;
use EduCore\Modules\Finance\Contracts\Repositories\StaffCompensationContractRepository;
use PDO;

/**
 * PDO implementation for staff compensation contracts and components.
 */
final class PdoStaffCompensationRepository implements StaffCompensationContractRepository, StaffCompensationComponentRepository
{
    public function __construct(private PDO $db)
    {
    }

    public function createContract(int $staffId, string $effectiveFrom, string $provenance, string $historyConfidence, int $createdBy): int
    {
        $versionStmt = $this->db->prepare(
            'SELECT version_number
             FROM staff_compensation_contracts
             WHERE staff_id = ? AND effective_from = ?
             ORDER BY version_number FOR UPDATE'
        );
        $versionStmt->execute([$staffId, $effectiveFrom]);
        $versions = array_map('intval', $versionStmt->fetchAll(PDO::FETCH_COLUMN));
        $version = ($versions === [] ? 0 : max($versions)) + 1;
        $this->db->prepare(
            'INSERT INTO staff_compensation_contracts
                (staff_id, effective_from, version_number, status, provenance, history_confidence, created_by)
             VALUES (?, ?, ?, ?, ?, ?, ?)'
        )->execute([$staffId, $effectiveFrom, $version, 'draft', $provenance, $historyConfidence, $createdBy]);
        return (int) $this->db->lastInsertId();
    }

    public function addComponent(int $contractId, int $componentId, string $amount, string $direction, string $effectiveFrom): int
    {
        $this->db->prepare(
            'INSERT INTO staff_compensation_contract_components
                (contract_id, payroll_component_id, amount, effective_from, direction, status)
             VALUES (?, ?, ?, ?, ?, ?)'
        )->execute([$contractId, $componentId, $amount, $effectiveFrom, $direction, 'active']);
        return (int) $this->db->lastInsertId();
    }

    public function findActiveContract(int $staffId): ?array
    {
        $stmt = $this->db->prepare(
            'SELECT * FROM staff_compensation_contracts
             WHERE staff_id = ? AND status = ?
             ORDER BY effective_from DESC, version_number DESC LIMIT 1'
        );
        $stmt->execute([$staffId, 'active']);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row ?: null;
    }

    public function findEffectiveContract(int $staffId, string $effectiveDate): ?array
    {
        $stmt = $this->db->prepare(
            'SELECT * FROM staff_compensation_contracts
             WHERE staff_id = ? AND status = ? AND effective_from <= ?
               AND (effective_to IS NULL OR effective_to >= ?)
             ORDER BY effective_from DESC, version_number DESC LIMIT 1'
        );
        $stmt->execute([$staffId, 'active', $effectiveDate, $effectiveDate]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row ?: null;
    }

    public function findContractById(int $contractId): ?array
    {
        $stmt = $this->db->prepare('SELECT * FROM staff_compensation_contracts WHERE id = ? LIMIT 1 FOR UPDATE');
        $stmt->execute([$contractId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row ?: null;
    }

    public function activateContract(int $contractId, int $approvedBy): void
    {
        $contract = $this->findContractById($contractId);
        if ($contract === null || (string) $contract['status'] !== 'draft') {
            throw new \RuntimeException('Only a draft compensation contract can be activated.');
        }
        $this->db->prepare(
            'UPDATE staff_compensation_contracts
             SET status = ?, effective_to = DATE_SUB(?, INTERVAL 1 DAY)
             WHERE staff_id = ? AND status = ? AND id <> ?'
        )->execute(['superseded', (string) $contract['effective_from'], (int) $contract['staff_id'], 'active', $contractId]);
        $this->db->prepare(
            'UPDATE staff_compensation_contracts SET status = ?, approved_by = ?, approved_at = NOW() WHERE id = ?'
        )->execute(['active', $approvedBy, $contractId]);
    }

    public function componentsForContract(int $contractId): array
    {
        $stmt = $this->db->prepare(
            'SELECT * FROM staff_compensation_contract_components
             WHERE contract_id = ? AND status = ?
             ORDER BY id'
        );
        $stmt->execute([$contractId, 'active']);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    public function componentsForContractAtDate(int $contractId, string $effectiveDate): array
    {
        $stmt = $this->db->prepare(
            'SELECT * FROM staff_compensation_contract_components
             WHERE contract_id = ? AND status = ?
               AND (effective_from IS NULL OR effective_from <= ?)
               AND (effective_to IS NULL OR effective_to >= ?)
             ORDER BY id'
        );
        $stmt->execute([$contractId, 'active', $effectiveDate, $effectiveDate]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    public function findByContract(int $contractId): array
    {
        $stmt = $this->db->prepare(
            'SELECT * FROM staff_compensation_contract_components WHERE contract_id = ? ORDER BY id'
        );
        $stmt->execute([$contractId]);

        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    public function findActive(int $contractId): array
    {
        $stmt = $this->db->prepare(
            'SELECT * FROM staff_compensation_contract_components
             WHERE contract_id = ? AND status = ?
               AND (effective_from IS NULL OR effective_from <= CURRENT_DATE)
               AND (effective_to IS NULL OR effective_to >= CURRENT_DATE)
             ORDER BY id'
        );
        $stmt->execute([$contractId, 'active']);

        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }
}
