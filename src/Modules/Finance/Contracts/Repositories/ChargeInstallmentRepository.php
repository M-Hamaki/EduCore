<?php

declare(strict_types=1);

namespace EduCore\Modules\Finance\Contracts\Repositories;

/**
 * Repository contract for charge installments (scheduled due dates).
 */
interface ChargeInstallmentRepository
{
    /**
     * Find an installment by id.
     *
     * @return array|null
     */
    public function findById(int $id): ?array;

    /**
     * Return all installments for a charge.
     *
     * @param int $chargeId
     * @return array
     */
    public function findByCharge(int $chargeId): array;

    /**
     * Remaining amount due for an installment (gross - allocations - credit applications).
     *
     * @param int $installmentId
     * @return string decimal amount
     */
    public function remainingDue(int $installmentId): string;

    /**
     * Update the status of an installment.
     *
     * @param int $id
     * @param string $status
     */
    public function updateStatus(int $id, string $status): void;
}
