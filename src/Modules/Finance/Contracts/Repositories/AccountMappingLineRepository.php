<?php

declare(strict_types=1);

namespace EduCore\Modules\Finance\Contracts\Repositories;

/**
 * Repository contract for account-mapping lines (operation selectors -> GL accounts).
 */
interface AccountMappingLineRepository
{
    /**
     * Find active mapping lines for an operation type matching the selectors.
     *
     * @param string $operationType
     * @param array $selectors
     * @return array
     */
    public function findActiveLines(string $operationType, array $selectors): array;

    /**
     * Create a mapping line.
     *
     * @param array $fields
     * @return int the line id
     */
    public function create(array $fields): int;
}
