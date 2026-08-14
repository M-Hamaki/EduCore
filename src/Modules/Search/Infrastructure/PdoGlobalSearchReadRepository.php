<?php

declare(strict_types=1);

namespace EduCore\Modules\Search\Infrastructure;

use EduCore\Modules\Search\Contracts\GlobalSearchReadRepository;
use PDO;

require_once dirname(__DIR__) . '/Contracts/GlobalSearchReadRepository.php';
require_once dirname(__DIR__, 4) . '/classes/StaffEmploymentLifecycleService.php';

/**
 * PDO adapter for the reviewed, read-only global-search projection.
 */
final class PdoGlobalSearchReadRepository implements GlobalSearchReadRepository
{
    public function __construct(private PDO $db)
    {
    }

    public function searchStudents(
        array $tokens,
        int $academicYearId,
        ?array $allowedClassIds,
        int $limit
    ): array {
        $params = [$academicYearId];
        $scopeSql = '';
        if ($allowedClassIds !== null) {
            $allowedClassIds = $this->positiveIds($allowedClassIds);
            if ($allowedClassIds === []) {
                return [];
            }
            $scopeSql = ' AND se.class_id IN (' . implode(',', array_fill(0, count($allowedClassIds), '?')) . ')';
            array_push($params, ...$allowedClassIds);
        }

        $profileName = "NULLIF(TRIM(CONCAT_WS(' ', sp.first_name_ar, sp.second_name_ar, sp.third_name_ar, sp.family_name_ar)), '')";
        $filter = $this->buildTokenFilter(
            $tokens,
            ['u.name', 'u.username', 'sp.student_code', 'sp.search_key_ar', 'sp.first_name_ar', 'sp.second_name_ar', 'sp.third_name_ar', 'sp.family_name_ar'],
            ['u.name', 'sp.search_key_ar', $profileName],
            $params
        );
        $limit = $this->safeLimit($limit);

        $sql = "
            SELECT DISTINCT
                   u.id,
                   COALESCE({$profileName}, u.name) AS name,
                   c.name AS class_name,
                   sp.student_code
            FROM users u
            LEFT JOIN student_profiles sp ON sp.user_id = u.id
            LEFT JOIN student_enrollments se
                   ON se.student_id = u.id
                  AND se.academic_year_id = ?
                  AND se.enrollment_status = 'enrolled'
            LEFT JOIN classes c ON c.id = COALESCE(se.class_id, u.class_id)
            WHERE u.role = 'student'
              AND u.status = 'active'
              AND u.deleted_at IS NULL
              {$scopeSql}
              AND {$filter}
            ORDER BY u.name ASC
            LIMIT {$limit}";

        $stmt = $this->db->prepare($sql);
        $stmt->execute($params);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    public function searchStaff(array $tokens, int $limit): array
    {
        $params = [];
        $filter = $this->buildTokenFilter(
            $tokens,
            ['u.name', 'u.username', 'sp.full_name_ar', 'sp.employee_code', 'sp.job_title', 'sp.department'],
            ['u.name', 'sp.full_name_ar'],
            $params
        );
        $limit = $this->safeLimit($limit);

        $sql = "
            SELECT u.id,
                   COALESCE(NULLIF(TRIM(sp.full_name_ar), ''), u.name) AS name,
                   sp.full_name_ar,
                   u.role,
                   sp.employee_code,
                   sp.job_title,
                   sp.department
            FROM users u
            INNER JOIN staff_profiles sp ON sp.user_id = u.id
            WHERE u.role <> 'student'
              AND u.status = 'active'
              AND u.deleted_at IS NULL
              AND {$filter}
            ORDER BY u.name ASC
            LIMIT {$limit}";

        $stmt = $this->db->prepare($sql);
        $stmt->execute($params);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
        foreach ($rows as &$row) {
            $row['job_title'] = \StaffEmploymentLifecycleService::canonicalJobTitle($row['job_title'] ?? null);
        }
        unset($row);
        return $rows;
    }

    public function searchClasses(
        array $tokens,
        int $academicYearId,
        ?array $allowedClassIds,
        int $limit
    ): array {
        $params = [];
        $yearSql = '';
        if ($academicYearId > 0) {
            $yearSql = ' AND (c.academic_year_id = ? OR c.academic_year_id IS NULL)';
            $params[] = $academicYearId;
        }

        $scopeSql = '';
        if ($allowedClassIds !== null) {
            $allowedClassIds = $this->positiveIds($allowedClassIds);
            if ($allowedClassIds === []) {
                return [];
            }
            $scopeSql = ' AND c.id IN (' . implode(',', array_fill(0, count($allowedClassIds), '?')) . ')';
            array_push($params, ...$allowedClassIds);
        }

        $filter = $this->buildTokenFilter($tokens, ['c.name'], ['c.name'], $params);
        $limit = $this->safeLimit($limit);

        $sql = "
            SELECT c.id, c.name, g.grade_name, s.stage_name
            FROM classes c
            LEFT JOIN grades g ON g.id = c.grade_id
            LEFT JOIN stages s ON s.id = g.stage_id
            WHERE 1 = 1
              {$yearSql}
              {$scopeSql}
              AND {$filter}
            ORDER BY c.name ASC
            LIMIT {$limit}";

        $stmt = $this->db->prepare($sql);
        $stmt->execute($params);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    public function searchSubjects(array $tokens, int $limit): array
    {
        $params = [];
        $filter = $this->buildTokenFilter($tokens, ['name', 'code'], ['name'], $params);
        $limit = $this->safeLimit($limit);

        $stmt = $this->db->prepare(
            "SELECT id, name, code
             FROM subjects
             WHERE {$filter}
             ORDER BY name ASC
             LIMIT {$limit}"
        );
        $stmt->execute($params);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    public function searchBuses(array $tokens, int $limit): array
    {
        $params = [];
        $filter = $this->buildTokenFilter($tokens, ['b.bus_number'], ['b.bus_number'], $params);
        $limit = $this->safeLimit($limit);

        $stmt = $this->db->prepare(
            "SELECT b.id, b.bus_number, b.capacity, b.status
             FROM buses b
             WHERE {$filter}
             ORDER BY b.bus_number ASC
             LIMIT {$limit}"
        );
        $stmt->execute($params);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    /**
     * @param array<int,array{original:string,normalized:string}> $tokens
     * @param array<int,string> $plainColumns
     * @param array<int,string> $normalizedColumns
     * @param array<int,mixed> $params
     */
    private function buildTokenFilter(
        array $tokens,
        array $plainColumns,
        array $normalizedColumns,
        array &$params
    ): string {
        $tokenGroups = [];
        foreach ($tokens as $token) {
            $comparisons = [];
            foreach ($plainColumns as $column) {
                $comparisons[] = "{$column} LIKE ? ESCAPE '='";
                $params[] = '%' . $this->escapeLike((string)($token['original'] ?? '')) . '%';
            }
            foreach ($normalizedColumns as $column) {
                $comparisons[] = $this->normalizedExpression($column) . " LIKE ? ESCAPE '='";
                $params[] = '%' . $this->escapeLike((string)($token['normalized'] ?? '')) . '%';
            }
            if ($comparisons !== []) {
                $tokenGroups[] = '(' . implode(' OR ', $comparisons) . ')';
            }
        }

        return $tokenGroups === [] ? '1 = 0' : implode(' AND ', $tokenGroups);
    }

    private function normalizedExpression(string $column): string
    {
        $expression = "LOWER(COALESCE({$column}, ''))";
        $replacements = [
            'أ' => 'ا',
            'إ' => 'ا',
            'آ' => 'ا',
            'ء' => 'ا',
            'ؤ' => 'و',
            'ئ' => 'ي',
            'ى' => 'ي',
            'ة' => 'ه',
            'ً' => '',
            'ٌ' => '',
            'ٍ' => '',
            'َ' => '',
            'ُ' => '',
            'ِ' => '',
            'ّ' => '',
            'ْ' => '',
            'ٰ' => '',
        ];
        foreach ($replacements as $from => $to) {
            $expression = "REPLACE({$expression}, '{$from}', '{$to}')";
        }

        return $expression;
    }

    private function escapeLike(string $value): string
    {
        return str_replace(['=', '%', '_'], ['==', '=%', '=_'], $value);
    }

    /** @param array<int,mixed> $ids @return array<int,int> */
    private function positiveIds(array $ids): array
    {
        return array_values(array_unique(array_filter(
            array_map('intval', $ids),
            static fn(int $id): bool => $id > 0
        )));
    }

    private function safeLimit(int $limit): int
    {
        return max(1, min(40, $limit));
    }
}
