<?php

declare(strict_types=1);

namespace EduCore\Modules\Finance\Contracts;

interface FinanceExportRenderer
{
    /** @param list<string> $columns @param list<array<string,mixed>> $rows */
    public function render(string $format, array $columns, array $rows): string;
}
