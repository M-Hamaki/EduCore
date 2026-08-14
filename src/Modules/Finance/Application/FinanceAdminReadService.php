<?php

declare(strict_types=1);

namespace EduCore\Modules\Finance\Application;

use EduCore\Modules\Finance\Contracts\Queries\FinanceAdminQuery;
use InvalidArgumentException;

final class FinanceAdminReadService
{
    private const VIEWS = [
        'fee_plans', 'discounts', 'receipts', 'debts', 'staff_contracts',
        'payroll_runs', 'payroll_items', 'staff_advances', 'student_ledger', 'staff_ledger',
        'cashboxes', 'budgets', 'archive', 'imports', 'student_accounts',
        'buses', 'journal', 'accounts', 'audit_log', 'vouchers', 'approvals', 'discount_awards', 'periods', 'refunds', 'payroll_payments',
    ];

    public function __construct(private FinanceAdminQuery $query)
    {
    }

    /** @return list<array<string,mixed>> */
    public function rows(string $view, array $filters = [], int $limit = 100): array
    {
        $this->assertSupportedView($view);
        return $this->query->rows($view, $filters, max(1, min($limit, 500)));
    }

    /** @return array{total:int,filtered:int,rows:list<array<string,mixed>>} */
    public function page(
        string $view,
        array $filters,
        string $search,
        string $orderBy,
        string $orderDirection,
        int $offset,
        int $limit
    ): array {
        $this->assertSupportedView($view);
        return $this->query->page(
            $view,
            $filters,
            trim($search),
            $orderBy,
            strtolower($orderDirection) === 'asc' ? 'asc' : 'desc',
            max(0, $offset),
            $limit === -1 ? -1 : max(1, min($limit, 500))
        );
    }

    private function assertSupportedView(string $view): void
    {
        if (!in_array($view, self::VIEWS, true)) {
            throw new InvalidArgumentException('Unsupported finance admin view.');
        }
    }
}
