<?php

declare(strict_types=1);

namespace EduCore\Modules\PublicPortal\Application;

use EduCore\Modules\PublicPortal\Contracts\MaterialCatalogQuery;

final class GetPublicMaterials
{
    private MaterialCatalogQuery $materials;

    public function __construct(MaterialCatalogQuery $materials)
    {
        $this->materials = $materials;
    }

    /** @return array{enabled:bool,materials:array<int,array<string,mixed>>,filters:array<string,mixed>,pagination:array<string,int>} */
    public function execute(array $filters = []): array
    {
        $page = max(1, (int) ($filters['page'] ?? 1));
        $perPage = max(12, min(60, (int) ($filters['per_page'] ?? 24)));
        $filters['page'] = $page;
        $filters['per_page'] = $perPage;
        $total = $this->materials->countPublicMaterials($filters);
        $totalPages = $total === 0 ? 0 : (int) ceil($total / $perPage);
        if ($totalPages > 0 && $page > $totalPages) {
            $page = $totalPages;
            $filters['page'] = $page;
        }

        return [
            'enabled' => true,
            'materials' => $this->materials->listPublicMaterials($filters),
            'filters' => $this->materials->filterOptions(),
            'pagination' => [
                'page' => $page,
                'per_page' => $perPage,
                'total' => $total,
                'total_pages' => $totalPages,
            ],
        ];
    }
}
