<?php

declare(strict_types=1);

namespace EduCore\Modules\Finance\Contracts\Repositories;

/**
 * Repository contract for staff compensation contracts (versioned, component-based).
 */
interface StaffCompensationContractRepository
{
    /**
     * Create a compensation contract for a staff member.
     *
     * @param int $staffId
     * @param string $effectiveFrom
     * @param string $provenance       'business_decision'|'legacy_migration'|'other'
     * @param string $historyConfidence 'confirmed'|'uncertain'
     * @param int $createdBy
     * @return int the contract id
     */
    public function createContract(int $staffId, string $effectiveFrom, string $provenance, string $historyConfidence, int $createdBy): int;

    /**
     * Add a compensation component to a contract.
     *
     * @param int $contractId
     * @param int $componentId
     * @param string $amount    decimal
     * @param string $direction 'earning'|'deduction'
     * @param string $effectiveFrom
     * @return int the component line id
     */
    public function addComponent(int $contractId, int $componentId, string $amount, string $direction, string $effectiveFrom): int;

    /**
     * Find the currently active contract for a staff member.
     *
     * @param int $staffId
     * @return array|null
     */
    public function findActiveContract(int $staffId): ?array;

    public function findEffectiveContract(int $staffId, string $effectiveDate): ?array;

    public function findContractById(int $contractId): ?array;

    public function activateContract(int $contractId, int $approvedBy): void;

    /**
     * Return all components for a contract.
     *
     * @param int $contractId
     * @return array
     */
    public function componentsForContract(int $contractId): array;

    public function componentsForContractAtDate(int $contractId, string $effectiveDate): array;
}
