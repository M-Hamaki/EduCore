<?php

declare(strict_types=1);

namespace EduCore\Modules\Finance\Contracts\Repositories;

interface RefundRepository
{
    public function create(array $fields): int;
    public function lockById(int $id): ?array;
    public function findByRequestId(string $requestId): ?array;
    public function findByReversalOf(int $refundId): ?array;
    public function sumForAllocation(int $allocationId): string;
}
