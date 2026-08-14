<?php

declare(strict_types=1);

namespace EduCore\Modules\Finance\Contracts\Repositories;

interface StaffAdvanceMovementRepository
{
    public function create(array $fields): int;
    public function findByRequestId(string $requestId): ?array;
    public function lockById(int $movementId): ?array;
    public function findByReversalOf(int $movementId): ?array;
    public function findByAdvance(int $advanceId): array;
    public function linkPosting(int $movementId, int $subledgerTransactionId): void;
}
