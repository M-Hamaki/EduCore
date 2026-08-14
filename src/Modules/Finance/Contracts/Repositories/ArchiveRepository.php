<?php

declare(strict_types=1);

namespace EduCore\Modules\Finance\Contracts\Repositories;

interface ArchiveRepository
{
    public function archive(string $entityType, int $entityId): void;
    public function canRestore(string $entityType, int $entityId): bool;
    public function restore(string $entityType, int $entityId): void;
}
