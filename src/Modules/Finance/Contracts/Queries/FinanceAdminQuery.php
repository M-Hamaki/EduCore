<?php

declare(strict_types=1);

namespace EduCore\Modules\Finance\Contracts\Queries;

/** Read-only finance back-office projections; no write is permitted through this contract. */
interface FinanceAdminQuery
{
    /** @return list<array<string,mixed>> */
    public function rows(string $view, array $filters = [], int $limit = 100): array;

    /**
     * @return array{total:int,filtered:int,rows:list<array<string,mixed>>}
     */
    public function page(
        string $view,
        array $filters,
        string $search,
        string $orderBy,
        string $orderDirection,
        int $offset,
        int $limit
    ): array;
}
