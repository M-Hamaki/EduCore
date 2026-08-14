<?php

declare(strict_types=1);

namespace EduCore\Modules\Finance\Contracts\Repositories;

/**
 * Repository contract for voucher lines.
 */
interface VoucherLineRepository
{
    /**
     * Return all lines for a voucher.
     *
     * @param int $voucherId
     * @return array
     */
    public function findByVoucher(int $voucherId): array;

    /**
     * Sum the debit column for a voucher.
     *
     * @param int $voucherId
     * @return string decimal sum
     */
    public function sumDebit(int $voucherId): string;

    /**
     * Sum the credit column for a voucher.
     *
     * @param int $voucherId
     * @return string decimal sum
     */
    public function sumCredit(int $voucherId): string;
}
