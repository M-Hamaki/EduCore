<?php

declare(strict_types=1);

namespace EduCore\Modules\PublicPortal\Contracts;

interface MaterialCatalogQuery
{
    /** @return array<int,array<string,mixed>> */
    public function listPublicMaterials(array $filters = []): array;

    public function countPublicMaterials(array $filters = []): int;

    /** @return array<string,mixed>|null */
    public function findDownloadableMaterial(int $materialId): ?array;

    /** @return array{stages:array<int,array<string,mixed>>,grades:array<int,array<string,mixed>>} */
    public function filterOptions(): array;
}
