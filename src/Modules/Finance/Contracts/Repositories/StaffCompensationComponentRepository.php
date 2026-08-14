<?php

declare(strict_types=1);

namespace EduCore\Modules\Finance\Contracts\Repositories;

/**
 * Repository contract for staff compensation components (read-only lookups).
 */
interface StaffCompensationComponentRepository
{
    /**
     * Return all components for a contract.
     *
     * @param int $contractId
     * @return array
     */
    public function findByContract(int $contractId): array;

    /**
     * Return active (non-expired) components for a contract.
     *
     * @param int $contractId
     * @return array
     */
    public function findActive(int $contractId): array;
}
