<?php

declare(strict_types=1);

namespace EduCore\Modules\Finance\Contracts\Repositories;

/**
 * Repository contract for unapplied-credit applications (credit -> installment).
 */
interface UnappliedCreditApplicationRepository
{
    /**
     * Apply a portion of an unapplied credit to an installment.
     *
     * @param int $creditId
     * @param int $installmentId
     * @param int|null $allocationId
     * @param string $appliedAmount decimal
     * @param int $subledgerTxId
     * @param string $idempotencyKey
     * @return int the application id
     */
    public function createApplication(int $creditId, int $installmentId, ?int $allocationId, string $appliedAmount, int $subledgerTxId, string $idempotencyKey): int;

    /**
     * Sum applications for a credit.
     *
     * @param int $creditId
     * @return string decimal sum
     */
    public function sumForCredit(int $creditId): string;

    public function findByRequestId(string $requestId): ?array;
}
