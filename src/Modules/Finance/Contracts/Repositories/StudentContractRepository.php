<?php

declare(strict_types=1);

namespace EduCore\Modules\Finance\Contracts\Repositories;

interface StudentContractRepository
{
    public function create(int $studentAccountId, int $feePlanVersionId, string $snapshotJson, int $createdBy): int;
    public function findById(int $id): ?array;
    public function findByAccountAndVersion(int $studentAccountId, int $feePlanVersionId): ?array;
}
