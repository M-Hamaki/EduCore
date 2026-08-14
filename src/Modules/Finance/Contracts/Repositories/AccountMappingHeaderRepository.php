<?php

declare(strict_types=1);

namespace EduCore\Modules\Finance\Contracts\Repositories;

/**
 * Repository contract for account-mapping headers (versioned GL <-> sub-ledger mapping).
 */
interface AccountMappingHeaderRepository
{
    /**
     * Find the currently active mapping header.
     *
     * @return array|null
     */
    public function findActiveHeader(): ?array;

    /**
     * Create a new mapping header version (draft).
     *
     * @param int $versionNumber
     * @param int $createdBy
     * @return int the header id
     */
    public function create(int $versionNumber, int $createdBy): int;
}
