<?php

declare(strict_types=1);

namespace EduCore\Modules\Finance\Contracts\Repositories;

interface FinanceApprovalRequestRepository
{
    public function create(string $operationType, array $payload, int $requestedBy, string $requestKey): int;
    public function findByRequestKey(string $requestKey): ?array;
    public function lockById(int $id): ?array;
    public function markApproved(int $id, int $decidedBy, string $resultRefType, int $resultRefId): void;
    public function markRejected(int $id, int $decidedBy, string $reason): void;
}
