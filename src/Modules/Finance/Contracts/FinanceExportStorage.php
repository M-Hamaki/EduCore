<?php

declare(strict_types=1);

namespace EduCore\Modules\Finance\Contracts;

interface FinanceExportStorage
{
    public function store(string $prefix, string $extension, string $contents): string;
    public function delete(string $relativeRef): void;
    public function cleanupOlderThan(int $unixTimestamp): int;
    public function exists(string $relativeRef): bool;
    public function read(string $relativeRef): string;
}
