<?php

declare(strict_types=1);

namespace EduCore\Modules\Finance\Contracts\Repositories;

/**
 * Repository contract for sub-ledger transaction lines (per-bucket balance tracking).
 */
interface SubledgerLineRepository
{
    /**
     * Find a sub-ledger line by id.
     *
     * @return array|null
     */
    public function findById(int $id): ?array;

    /**
     * Return the immutable lines of one transaction in line-number order.
     *
     * @return list<array<string, mixed>>
     */
    public function findByTransaction(int $transactionId): array;

    /**
     * Sum the signed balance for a sub-ledger account within a bucket.
     *
     * @param int $subledgerAccountId
     * @param string $bucketCode
     * @return string decimal sum
     */
    public function sumForBucket(int $subledgerAccountId, string $bucketCode): string;

    public function sumForPartyTypeBucket(string $partyType, string $bucketCode): string;

    /**
     * Sum the signed balance for all lines of a sub-ledger transaction.
     *
     * @param int $transactionId
     * @return string decimal sum
     */
    public function sumForTransaction(int $transactionId): string;
}
