<?php

declare(strict_types=1);

namespace EduCore\Modules\Finance\Contracts\Repositories;

interface LegacyCompatibilityRepository
{
    /** @return array<string,mixed>|null */
    public function findActive(string $sourceType, string $sourceKey): ?array;

    /** @return array<string,mixed>|null */
    public function findActiveTarget(string $targetType, int $targetId): ?array;

    public function storeVersion(
        string $sourceType,
        string $sourceKey,
        string $targetType,
        int $targetId,
        ?int $academicYearId,
        array $payload,
        int $createdBy
    ): int;

    public function archive(string $sourceType, string $sourceKey): void;
}
