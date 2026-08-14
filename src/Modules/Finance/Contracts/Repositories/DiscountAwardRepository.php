<?php

declare(strict_types=1);

namespace EduCore\Modules\Finance\Contracts\Repositories;

/**
 * Repository contract for discount awards (rule applied to a student account).
 */
interface DiscountAwardRepository
{
    /**
     * Create a discount award for a student account.
     *
     * @param int $studentAccountId
     * @param int $ruleId
     * @param string $awardedAmount decimal
     * @param string $reason
     * @param int $requestedBy
     * @param int|null $approvedBy
     * @return int the award id
     */
    public function createAward(int $studentAccountId, int $ruleId, string $awardedAmount, string $reason, int $requestedBy, ?int $approvedBy): int;

    /**
     * Find an award by id.
     *
     * @return array|null
     */
    public function findById(int $id): ?array;

    /** Lock an award before approval/application totals are evaluated. */
    public function lockById(int $id): ?array;

    /**
     * Approve a pending award.
     *
     * @param int $id
     * @param int $approvedBy
     */
    public function approve(int $id, int $approvedBy): void;
}
