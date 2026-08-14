<?php

declare(strict_types=1);

namespace EduCore\Modules\Search\Application;

use EduCore\Modules\Search\Contracts\GlobalSearchReadRepository;

require_once dirname(__DIR__) . '/Contracts/GlobalSearchReadRepository.php';

/**
 * Application service for the admin header's read-only, role-scoped search.
 */
final class GlobalSearchQueryService
{
    private const GROUPS = ['students', 'staff', 'classes', 'subjects', 'buses'];

    public function __construct(private GlobalSearchReadRepository $repository)
    {
    }

    /**
     * @param array<string,bool> $capabilities
     * @param array<int,int>|null $allowedClassIds Null means unrestricted.
     * @return array{
     *   students:array<int,array<string,mixed>>,
     *   staff:array<int,array<string,mixed>>,
     *   classes:array<int,array<string,mixed>>,
     *   subjects:array<int,array<string,mixed>>,
     *   buses:array<int,array<string,mixed>>
     * }
     */
    public function search(
        string $query,
        array $capabilities,
        int $academicYearId,
        ?array $allowedClassIds,
        int $limit = 5
    ): array {
        $results = $this->emptyResults();
        $query = mb_substr(trim($query), 0, 120, 'UTF-8');
        $normalizedQuery = self::normalize($query);
        if (mb_strlen($normalizedQuery, 'UTF-8') < 2) {
            return $results;
        }

        $tokens = $this->tokens($query);
        if ($tokens === []) {
            return $results;
        }

        $limit = max(1, min(10, $limit));
        $candidateLimit = max(20, $limit * 4);
        $scopeIsEmpty = $allowedClassIds !== null && $allowedClassIds === [];

        if (($capabilities['students'] ?? false) && !$scopeIsEmpty) {
            $rows = $this->repository->searchStudents(
                $tokens,
                $academicYearId,
                $allowedClassIds,
                $candidateLimit
            );
            $results['students'] = $this->rankAndLimit(
                $rows,
                $normalizedQuery,
                ['name'],
                ['student_code'],
                $limit
            );
            foreach ($results['students'] as &$student) {
                $student['url'] = 'students.php?action=view&id=' . (int)($student['id'] ?? 0);
            }
            unset($student);
        }

        if ($capabilities['staff'] ?? false) {
            $rows = $this->repository->searchStaff(
                $tokens,
                $candidateLimit
            );
            $results['staff'] = $this->rankAndLimit(
                $rows,
                $normalizedQuery,
                ['name', 'full_name_ar'],
                ['employee_code'],
                $limit
            );
            foreach ($results['staff'] as &$staff) {
                $staff['url'] = 'staff.php?action=view&id=' . (int)($staff['id'] ?? 0);
            }
            unset($staff);
        }

        if (($capabilities['classes'] ?? false) && !$scopeIsEmpty) {
            $rows = $this->repository->searchClasses(
                $tokens,
                $academicYearId,
                $allowedClassIds,
                $candidateLimit
            );
            $results['classes'] = $this->rankAndLimit($rows, $normalizedQuery, ['name'], [], $limit);
            foreach ($results['classes'] as &$class) {
                $class['url'] = 'class_lists.php?class_id=' . (int)($class['id'] ?? 0);
            }
            unset($class);
        }

        if ($capabilities['subjects'] ?? false) {
            $rows = $this->repository->searchSubjects(
                $tokens,
                $candidateLimit
            );
            $results['subjects'] = $this->rankAndLimit($rows, $normalizedQuery, ['name'], ['code'], $limit);
            foreach ($results['subjects'] as &$subject) {
                $subject['url'] = 'subjects.php';
            }
            unset($subject);
        }

        if ($capabilities['buses'] ?? false) {
            $rows = $this->repository->searchBuses(
                $tokens,
                $candidateLimit
            );
            $results['buses'] = $this->rankAndLimit($rows, $normalizedQuery, ['bus_number'], [], $limit);
            foreach ($results['buses'] as &$bus) {
                $bus['url'] = 'transport_statistics.php';
            }
            unset($bus);
        }

        return $results;
    }

    /**
     * @return array{
     *   students:array<int,array<string,mixed>>,
     *   staff:array<int,array<string,mixed>>,
     *   classes:array<int,array<string,mixed>>,
     *   subjects:array<int,array<string,mixed>>,
     *   buses:array<int,array<string,mixed>>
     * }
     */
    public function emptyResults(): array
    {
        return array_fill_keys(self::GROUPS, []);
    }

    public static function normalize(string $value): string
    {
        $value = mb_strtolower($value, 'UTF-8');
        $value = preg_replace('/[\x{064B}-\x{065F}\x{0670}]/u', '', $value) ?? $value;
        $value = strtr($value, [
            'أ' => 'ا',
            'إ' => 'ا',
            'آ' => 'ا',
            'ء' => 'ا',
            'ؤ' => 'و',
            'ئ' => 'ي',
            'ى' => 'ي',
            'ة' => 'ه',
        ]);
        $value = preg_replace('/[^\p{L}\p{N}\s]/u', ' ', $value) ?? $value;
        $value = preg_replace('/\s+/u', ' ', $value) ?? $value;

        return trim($value);
    }

    /**
     * @return array<int,array{original:string,normalized:string}>
     */
    private function tokens(string $query): array
    {
        $query = mb_strtolower($query, 'UTF-8');
        $query = preg_replace('/[^\p{L}\p{N}\s]/u', ' ', $query) ?? $query;
        $parts = preg_split('/\s+/u', trim($query)) ?: [];
        $tokens = [];
        foreach ($parts as $part) {
            $part = trim($part);
            $normalized = self::normalize($part);
            if ($normalized === '') {
                continue;
            }
            $tokens[] = ['original' => $part, 'normalized' => $normalized];
            if (count($tokens) === 8) {
                break;
            }
        }

        return $tokens;
    }

    /**
     * @param array<int,array<string,mixed>> $rows
     * @param array<int,string> $labelFields
     * @param array<int,string> $codeFields
     * @return array<int,array<string,mixed>>
     */
    private function rankAndLimit(
        array $rows,
        string $normalizedQuery,
        array $labelFields,
        array $codeFields,
        int $limit
    ): array {
        foreach ($rows as $index => &$row) {
            $labels = $this->normalizedValues($row, $labelFields);
            $codes = $this->normalizedValues($row, $codeFields);
            $score = 10;
            if (in_array($normalizedQuery, $labels, true) || in_array($normalizedQuery, $codes, true)) {
                $score = 100;
            } elseif ($this->anyStartsWith($labels, $normalizedQuery) || $this->anyStartsWith($codes, $normalizedQuery)) {
                $score = 70;
            } elseif ($this->allQueryTokensMatch($labels, $codes, $normalizedQuery)) {
                $score = 40;
            }
            $row['__search_score'] = $score;
            $row['__search_index'] = $index;
        }
        unset($row);

        usort($rows, static function (array $left, array $right): int {
            $scoreCompare = ((int)$right['__search_score']) <=> ((int)$left['__search_score']);
            if ($scoreCompare !== 0) {
                return $scoreCompare;
            }
            return ((int)$left['__search_index']) <=> ((int)$right['__search_index']);
        });

        $rows = array_slice($rows, 0, $limit);
        foreach ($rows as &$row) {
            unset($row['__search_score'], $row['__search_index']);
        }
        unset($row);

        return array_values($rows);
    }

    /**
     * @param array<string,mixed> $row
     * @param array<int,string> $fields
     * @return array<int,string>
     */
    private function normalizedValues(array $row, array $fields): array
    {
        $values = [];
        foreach ($fields as $field) {
            $value = self::normalize((string)($row[$field] ?? ''));
            if ($value !== '') {
                $values[] = $value;
            }
        }
        return $values;
    }

    /** @param array<int,string> $values */
    private function anyStartsWith(array $values, string $query): bool
    {
        foreach ($values as $value) {
            if (str_starts_with($value, $query)) {
                return true;
            }
        }
        return false;
    }

    /**
     * @param array<int,string> $labels
     * @param array<int,string> $codes
     */
    private function allQueryTokensMatch(array $labels, array $codes, string $query): bool
    {
        $haystack = implode(' ', array_merge($labels, $codes));
        foreach (array_filter(explode(' ', $query)) as $token) {
            if (!str_contains($haystack, $token)) {
                return false;
            }
        }
        return true;
    }
}
