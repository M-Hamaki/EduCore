<?php

declare(strict_types=1);

namespace EduCore\Modules\PublicPortal\Infrastructure;

use EduCore\Modules\PublicPortal\Contracts\MaterialCatalogQuery;
use PDO;

final class LegacyMaterialCatalogAdapter implements MaterialCatalogQuery
{
    private PDO $db;

    public function __construct(PDO $db)
    {
        $this->db = $db;
    }

    public function listPublicMaterials(array $filters = []): array
    {
        [$where, $params] = $this->buildFilters($filters);
        $page = max(1, (int) ($filters['page'] ?? 1));
        $perPage = max(12, min(60, (int) ($filters['per_page'] ?? 24)));
        $offset = ($page - 1) * $perPage;

        $sql = 'SELECT m.id, m.subject_name, m.original_file_name, m.file_size, m.downloadable,
                       m.term, m.stage_id, m.grade_id, s.stage_name, g.grade_name
                FROM materials m
                INNER JOIN stages s ON s.id = m.stage_id AND s.status = \'active\'
                INNER JOIN grades g ON g.id = m.grade_id AND g.status = \'active\'
                WHERE ' . implode(' AND ', $where) . '
                ORDER BY s.stage_order, g.grade_order, m.term, m.sort_order, m.id DESC
                LIMIT ' . $perPage . ' OFFSET ' . $offset;
        $stmt = $this->db->prepare($sql);
        $stmt->execute($params);
        return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    }

    public function countPublicMaterials(array $filters = []): int
    {
        [$where, $params] = $this->buildFilters($filters);
        $stmt = $this->db->prepare(
            'SELECT COUNT(*)
             FROM materials m
             INNER JOIN stages s ON s.id = m.stage_id AND s.status = \'active\'
             INNER JOIN grades g ON g.id = m.grade_id AND g.status = \'active\'
             WHERE ' . implode(' AND ', $where)
        );
        $stmt->execute($params);
        return (int) $stmt->fetchColumn();
    }

    public function findDownloadableMaterial(int $materialId): ?array
    {
        if ($materialId <= 0) {
            return null;
        }

        $stmt = $this->db->prepare(
            'SELECT m.id, m.subject_name, m.file_name, m.original_file_name, m.file_size
             FROM materials m
             INNER JOIN stages s ON s.id = m.stage_id AND s.status = \'active\'
             INNER JOIN grades g ON g.id = m.grade_id AND g.status = \'active\'
             WHERE m.id = ? AND m.enabled = 1 AND m.downloadable = 1
             LIMIT 1'
        );
        $stmt->execute([$materialId]);
        $material = $stmt->fetch(PDO::FETCH_ASSOC);
        return $material ?: null;
    }

    public function filterOptions(): array
    {
        $stages = $this->db->query(
            "SELECT id, stage_name FROM stages WHERE status = 'active' ORDER BY stage_order, id"
        )->fetchAll(PDO::FETCH_ASSOC) ?: [];
        $grades = $this->db->query(
            "SELECT id, grade_name, stage_id FROM grades WHERE status = 'active' ORDER BY stage_id, grade_order, id"
        )->fetchAll(PDO::FETCH_ASSOC) ?: [];

        return ['stages' => $stages, 'grades' => $grades];
    }

    /** @return array{0:array<int,string>,1:array<int,mixed>} */
    private function buildFilters(array $filters): array
    {
        $where = ['m.enabled = 1'];
        $params = [];

        $stageId = (int) ($filters['stage_id'] ?? 0);
        if ($stageId > 0) {
            $where[] = 'm.stage_id = ?';
            $params[] = $stageId;
        }

        $gradeId = (int) ($filters['grade_id'] ?? 0);
        if ($gradeId > 0) {
            $where[] = 'm.grade_id = ?';
            $params[] = $gradeId;
        }

        $term = (string) ($filters['term'] ?? '');
        if (in_array($term, ['term1', 'term2'], true)) {
            $where[] = 'm.term = ?';
            $params[] = $term;
        }

        return [$where, $params];
    }
}
