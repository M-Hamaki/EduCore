<?php

declare(strict_types=1);

namespace EduCore\Modules\Finance\Contracts\Repositories;

/**
 * Repository contract for discount applications (award applied to a charge/installment).
 */
interface DiscountApplicationRepository
{
    /**
     * Apply a discount award to a charge (or a specific installment).
     *
     * @param int $awardId
     * @param int $chargeId
     * @param int|null $installmentId
     * @param string $appliedAmount decimal
     * @return int the application id
     */
    public function createApplication(
        int $awardId,
        int $chargeId,
        ?int $installmentId,
        string $appliedAmount,
        string $ledgerEffectAmount = '0.00',
        ?int $adjustmentId = null,
        ?int $subledgerTransactionId = null,
        ?string $requestId = null
    ): int;

    /**
     * Sum discount applications for a charge.
     *
     * @param int $chargeId
     * @return string decimal sum
     */
    public function sumForCharge(int $chargeId): string;

    public function sumForAward(int $awardId): string;

    public function findByRequestId(string $requestId): ?array;
}
