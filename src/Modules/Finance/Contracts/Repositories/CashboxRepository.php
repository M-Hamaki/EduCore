<?php

declare(strict_types=1);

namespace EduCore\Modules\Finance\Contracts\Repositories;

/**
 * Repository contract for cashboxes and cash settlements.
 */
interface CashboxRepository
{
    /**
     * Return all active cashboxes.
     *
     * @return array
     */
    public function findActiveCashboxes(): array;

    /**
     * Find a cashbox by id.
     *
     * @return array|null
     */
    public function findById(int $id): ?array;

    public function lockById(int $id): ?array;

    public function findSettlement(int $id): ?array;

    /**
     * Create a cash settlement (opening float + expected vs counted totals).
     *
     * @param int $cashboxId
     * @param int|null $periodId
     * @param string $date
     * @param string $openingFloat  decimal
     * @param string $expectedTotal  decimal
     * @param string $countedTotal   decimal
     * @return int the settlement id
     */
    public function createSettlement(int $cashboxId, ?int $periodId, string $date, string $openingFloat, string $expectedTotal, string $countedTotal): int;

    /**
     * Mark a settlement as settled.
     *
     * @param int $id
     * @param int $settledBy
     */
    public function settleSettlement(int $id, string $countedTotal, string $difference, int $settledBy): void;

    public function expectedReceiptTotal(int $cashboxId, string $date): string;
}
