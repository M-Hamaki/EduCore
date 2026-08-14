<?php

declare(strict_types=1);

namespace EduCore\Modules\Finance\Contracts\Repositories;

interface FinancePeriodRepository
{
    public function create(int $academicYearId, string $name, ?string $startDate, ?string $endDate): int;
    public function findById(int $id): ?array;
    public function lockById(int $id): ?array;
    public function close(int $id, int $closedBy, int $approvedBy): void;
    public function reopen(int $id, string $reason, int $reopenedBy, int $approvedBy): void;
}
