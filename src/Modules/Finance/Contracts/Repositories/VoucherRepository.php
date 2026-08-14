<?php

declare(strict_types=1);

namespace EduCore\Modules\Finance\Contracts\Repositories;

/**
 * Repository contract for vouchers (manual GL adjustments / memoranda).
 */
interface VoucherRepository
{
    /**
     * Create a voucher (draft).
     *
     * @param array $fields
     * @return int the voucher id
     */
    public function create(array $fields): int;

    /**
     * Append a line to a voucher.
     *
     * @param int $voucherId
     * @param int $accountId
     * @param int|null $costCenterId
     * @param string $debit  decimal
     * @param string $credit decimal
     * @param string|null $description
     */
    public function addLine(int $voucherId, int $accountId, ?int $costCenterId, string $debit, string $credit, ?string $description): void;

    /**
     * Verify that SUM(debit) = SUM(credit) for a voucher.
     *
     * @param int $voucherId
     */
    public function isBalanced(int $voucherId): bool;

    /**
     * Find a voucher by id.
     *
     * @return array|null
     */
    public function findById(int $id): ?array;

    public function findByRequestId(string $requestId): ?array;

    public function lockById(int $id): ?array;

    public function findByReversalOf(int $voucherId): ?array;

    public function findByVoucher(int $voucherId): array;
}
