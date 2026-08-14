<?php

declare(strict_types=1);

namespace EduCore\Modules\Students;

use InvalidArgumentException;
use PDO;

final class StudentCompletenessReadRepository
{
    private PDO $db;
    private int $academicYearId;
    private int $currentAcademicYearId;
    /** @var array<int,array<string,mixed>> */
    private array $fields;

    /** @param array<int,array<string,mixed>> $fields */
    public function __construct(PDO $db, int $academicYearId, int $currentAcademicYearId, array $fields)
    {
        if ($academicYearId <= 0) {
            throw new InvalidArgumentException('يجب اختيار عام دراسي صالح.');
        }
        $this->db = $db;
        $this->academicYearId = $academicYearId;
        $this->currentAcademicYearId = $currentAcademicYearId;
        $this->fields = array_values($fields);
    }

    /**
     * @param array<string,mixed> $filters
     * @param array<int,int>|null $allowedClassIds
     * @return array{recordsTotal:int,recordsFiltered:int,data:array<int,array<string,mixed>>}
     */
    public function dataTable(
        array $filters,
        ?array $allowedClassIds,
        int $start,
        int $length,
        string $sortKey,
        string $sortDirection
    ): array {
        $records = $this->loadCandidates($allowedClassIds);
        $baseFilters = [
            'enrollment_status' => $filters['enrollment_status'] ?? 'enrolled',
            'experimental_scope' => $filters['experimental_scope'] ?? 'official',
        ];
        $recordsTotal = count($this->applyFilters($records, $baseFilters));
        $filtered = $this->applyFilters($records, $filters);
        $this->sortRecords($filtered, $sortKey, $sortDirection);

        return [
            'recordsTotal' => $recordsTotal,
            'recordsFiltered' => count($filtered),
            'data' => $length === -1
                ? array_slice($filtered, max(0, $start))
                : array_slice($filtered, max(0, $start), max(1, min(500, $length))),
        ];
    }

    /**
     * @param array<string,mixed> $filters
     * @param array<int,int>|null $allowedClassIds
     * @return array<string,int|float>
     */
    public function stats(array $filters, ?array $allowedClassIds): array
    {
        $records = $this->applyFilters($this->loadCandidates($allowedClassIds), $filters);
        $total = count($records);
        $complete = 0;
        $partial = 0;
        $critical = 0;
        $annualAttention = 0;
        $sum = 0;

        foreach ($records as $record) {
            $sum += (int) $record['profile_pct'];
            if ($record['profile_level'] === 'complete') {
                $complete++;
            } elseif ($record['profile_level'] === 'partial') {
                $partial++;
            } else {
                $critical++;
            }
            if ($record['annual_state'] !== 'ready') {
                $annualAttention++;
            }
        }

        return [
            'total' => $total,
            'profile_complete' => $complete,
            'profile_partial' => $partial,
            'profile_critical' => $critical,
            'profile_attention' => $partial + $critical,
            'annual_attention' => $annualAttention,
            'avg_profile' => $total > 0 ? round($sum / $total, 1) : 0.0,
        ];
    }

    /**
     * @param array<int,int>|null $allowedClassIds
     * @return array{stages:array<int,array<string,mixed>>,grades:array<int,array<string,mixed>>,classes:array<int,array<string,mixed>>}
     */
    public function filterOptions(?array $allowedClassIds): array
    {
        if ($allowedClassIds !== null && $allowedClassIds === []) {
            return ['stages' => [], 'grades' => [], 'classes' => []];
        }

        $classWhere = ["c.status = 'active'", 'c.academic_year_id = ?'];
        $classParams = [$this->academicYearId];
        if ($allowedClassIds !== null) {
            $classWhere[] = 'c.id IN (' . implode(',', array_fill(0, count($allowedClassIds), '?')) . ')';
            array_push($classParams, ...$allowedClassIds);
        }
        $stmt = $this->db->prepare(
            'SELECT c.id, c.name, c.grade_id, c.is_experimental,
                    g.stage_id, g.grade_name, g.is_experimental AS grade_is_experimental,
                    s.stage_name, s.is_experimental AS stage_is_experimental
             FROM classes c
             JOIN grades g ON g.id = c.grade_id
             JOIN stages s ON s.id = g.stage_id
             WHERE ' . implode(' AND ', $classWhere) . '
             ORDER BY s.stage_order, g.grade_order, c.display_order, c.name'
        );
        $stmt->execute($classParams);
        $classes = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

        $gradeIds = [];
        $stageIds = [];
        foreach ($classes as $class) {
            $gradeIds[(int) $class['grade_id']] = true;
            $stageIds[(int) $class['stage_id']] = true;
        }

        $stages = $this->db->query(
            "SELECT id, stage_name, is_experimental FROM stages WHERE status = 'active' ORDER BY stage_order, id"
        )->fetchAll(PDO::FETCH_ASSOC) ?: [];
        $grades = $this->db->query(
            "SELECT id, grade_name, stage_id, is_experimental FROM grades WHERE status = 'active' ORDER BY grade_order, id"
        )->fetchAll(PDO::FETCH_ASSOC) ?: [];

        if ($allowedClassIds !== null) {
            $stages = array_values(array_filter($stages, static fn(array $row): bool => isset($stageIds[(int) $row['id']])));
            $grades = array_values(array_filter($grades, static fn(array $row): bool => isset($gradeIds[(int) $row['id']])));
        }

        return ['stages' => $stages, 'grades' => $grades, 'classes' => $classes];
    }

    /**
     * @param array<int,int>|null $allowedClassIds
     * @return array<int,array<string,mixed>>
     */
    private function loadCandidates(?array $allowedClassIds): array
    {
        if ($allowedClassIds !== null && $allowedClassIds === []) {
            return [];
        }

        $fieldSelects = [];
        foreach ($this->fields as $index => $field) {
            $table = (string) ($field['db_table'] ?? '');
            $column = (string) ($field['db_column'] ?? '');
            $alias = 'field_' . $index;
            if ($table === 'student_profiles') {
                $fieldSelects[] = "sp.`{$column}` AS `{$alias}`";
            } elseif ($table === 'student_guardians_father') {
                $fieldSelects[] = "fa.`{$column}` AS `{$alias}`";
            } elseif ($table === 'student_guardians_mother') {
                $fieldSelects[] = "mo.`{$column}` AS `{$alias}`";
            } elseif ($table === 'student_attachments') {
                $fieldSelects[] = "COALESCE(pai.has_profile_image, 0) AS `{$alias}`";
            }
        }

        $where = ["u.role = 'student'", 'u.deleted_at IS NULL'];
        $params = [$this->academicYearId];
        if ($this->academicYearId === $this->currentAcademicYearId) {
            $where[] = "(se.id IS NOT NULL OR sp.enrollment_status = 'enrolled')";
        } else {
            $where[] = 'se.id IS NOT NULL';
        }
        if ($allowedClassIds !== null) {
            $where[] = 'se.class_id IN (' . implode(',', array_fill(0, count($allowedClassIds), '?')) . ')';
            array_push($params, ...$allowedClassIds);
        }

        $sql = 'SELECT
                    u.id, u.name, u.is_test_account,
                    sp.student_code, sp.enrollment_status AS profile_enrollment_status,
                    se.id AS enrollment_id, se.stage_id, se.grade_id, se.class_id,
                    se.enrollment_status, se.academic_status,
                    s.id AS joined_stage_id, s.stage_name, s.is_experimental AS stage_is_experimental,
                    g.id AS joined_grade_id, g.grade_name, g.stage_id AS grade_stage_id,
                    g.is_experimental AS grade_is_experimental,
                    c.id AS joined_class_id, c.name AS class_name, c.grade_id AS class_grade_id,
                    c.academic_year_id AS class_year_id, c.is_experimental AS class_is_experimental' .
                    ($fieldSelects ? ",\n                    " . implode(",\n                    ", $fieldSelects) : '') . '
                FROM users u
                LEFT JOIN student_profiles sp ON sp.user_id = u.id
                LEFT JOIN student_enrollments se
                    ON se.student_id = u.id AND se.academic_year_id = ?
                LEFT JOIN stages s ON s.id = se.stage_id
                LEFT JOIN grades g ON g.id = se.grade_id
                LEFT JOIN classes c ON c.id = se.class_id
                LEFT JOIN (
                    SELECT student_id, MAX(id) AS guardian_id
                    FROM student_guardians WHERE relationship = \'father\' GROUP BY student_id
                ) father_latest ON father_latest.student_id = u.id
                LEFT JOIN student_guardians fa ON fa.id = father_latest.guardian_id
                LEFT JOIN (
                    SELECT student_id, MAX(id) AS guardian_id
                    FROM student_guardians WHERE relationship = \'mother\' GROUP BY student_id
                ) mother_latest ON mother_latest.student_id = u.id
                LEFT JOIN student_guardians mo ON mo.id = mother_latest.guardian_id
                LEFT JOIN (
                    SELECT user_id, 1 AS has_profile_image
                    FROM student_attachments WHERE label = \'الصورة الشخصية\' GROUP BY user_id
                ) pai ON pai.user_id = u.id
                WHERE ' . implode(' AND ', $where);

        $stmt = $this->db->prepare($sql);
        $stmt->execute($params);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

        return array_map(fn(array $row): array => $this->normaliseRecord($row), $rows);
    }

    /** @return array<string,mixed> */
    private function normaliseRecord(array $row): array
    {
        $activeWeight = 0;
        $earnedWeight = 0;
        $sectionTotals = [];
        $sectionEarned = [];
        $missingAll = [];
        $missingEssential = [];

        foreach ($this->fields as $index => $field) {
            $priority = (string) ($field['priority'] ?? 'ignored');
            if ($priority === 'ignored') {
                continue;
            }
            $weight = max(0, (int) ($field['weight'] ?? 0));
            $section = (string) ($field['section'] ?? 'أخرى');
            $value = $row['field_' . $index] ?? null;
            $filled = $value !== null && trim((string) $value) !== '' && (string) $value !== '0';
            if (($field['db_table'] ?? '') === 'student_attachments') {
                $filled = (int) $value === 1;
            }

            $activeWeight += $weight;
            $sectionTotals[$section] = ($sectionTotals[$section] ?? 0) + $weight;
            if ($filled) {
                $earnedWeight += $weight;
                $sectionEarned[$section] = ($sectionEarned[$section] ?? 0) + $weight;
            } else {
                $missingAll[] = [
                    'key' => (string) $field['key'],
                    'label' => (string) $field['label'],
                    'section' => $section,
                    'priority' => $priority,
                ];
                if (in_array($priority, ['required', 'important'], true)) {
                    $missingEssential[] = (string) $field['label'];
                }
            }
        }

        $profilePct = $activeWeight > 0 ? (int) round(($earnedWeight / $activeWeight) * 100) : 100;
        $sectionPercentages = [];
        foreach ($sectionTotals as $section => $total) {
            $sectionPercentages[$section] = $total > 0
                ? (int) round((($sectionEarned[$section] ?? 0) / $total) * 100)
                : 100;
        }

        $row['profile_pct'] = $profilePct;
        $row['profile_level'] = $profilePct >= 80 ? 'complete' : ($profilePct >= 50 ? 'partial' : 'critical');
        $row['section_percentages'] = $sectionPercentages;
        $row['missing_fields'] = $missingAll;
        $row['missing_essential'] = array_values(array_unique($missingEssential));
        $row['annual_state'] = $this->annualState($row);
        $row['effective_is_experimental'] = max(
            (int) ($row['is_test_account'] ?? 0),
            (int) ($row['stage_is_experimental'] ?? 0),
            (int) ($row['grade_is_experimental'] ?? 0),
            (int) ($row['class_is_experimental'] ?? 0)
        );

        return $row;
    }

    private function annualState(array $row): string
    {
        if (empty($row['enrollment_id'])) {
            return 'missing_enrollment';
        }
        if (empty($row['stage_id']) || empty($row['grade_id'])
            || trim((string) ($row['enrollment_status'] ?? '')) === ''
            || trim((string) ($row['academic_status'] ?? '')) === '') {
            return 'missing_structure';
        }
        if (empty($row['joined_stage_id']) || empty($row['joined_grade_id'])
            || (int) $row['grade_stage_id'] !== (int) $row['stage_id']) {
            return 'inconsistent_structure';
        }
        if (!empty($row['class_id']) && (
            empty($row['joined_class_id'])
            || (int) $row['class_grade_id'] !== (int) $row['grade_id']
            || (int) $row['class_year_id'] !== $this->academicYearId
        )) {
            return 'inconsistent_structure';
        }
        if (($row['enrollment_status'] ?? '') === 'enrolled'
            && in_array((string) ($row['academic_status'] ?? ''), ['new', 'promoted', 'retained'], true)
            && empty($row['class_id'])) {
            return 'awaiting_placement';
        }

        return 'ready';
    }

    /**
     * @param array<int,array<string,mixed>> $records
     * @param array<string,mixed> $filters
     * @return array<int,array<string,mixed>>
     */
    private function applyFilters(array $records, array $filters): array
    {
        $stageIds = $this->normaliseIds($filters['stage_ids'] ?? []);
        $gradeIds = $this->normaliseIds($filters['grade_ids'] ?? []);
        $classIds = $this->normaliseIds($filters['class_ids'] ?? []);
        $enrollmentStatus = (string) ($filters['enrollment_status'] ?? 'enrolled');
        $academicStatus = (string) ($filters['academic_status'] ?? '');
        $annualState = (string) ($filters['annual_state'] ?? '');
        $profileLevel = (string) ($filters['profile_level'] ?? '');
        $missingSection = (string) ($filters['missing_section'] ?? '');
        $experimentalScope = (string) ($filters['experimental_scope'] ?? 'official');
        $search = mb_strtolower(trim((string) ($filters['search'] ?? '')), 'UTF-8');

        return array_values(array_filter($records, static function (array $record) use (
            $stageIds,
            $gradeIds,
            $classIds,
            $enrollmentStatus,
            $academicStatus,
            $annualState,
            $profileLevel,
            $missingSection,
            $experimentalScope,
            $search
        ): bool {
            if ($experimentalScope === 'official' && (int) $record['effective_is_experimental'] === 1) {
                return false;
            }
            if ($experimentalScope === 'experimental' && (int) $record['effective_is_experimental'] !== 1) {
                return false;
            }
            $effectiveEnrollmentStatus = $record['enrollment_id']
                ? (string) $record['enrollment_status']
                : (string) $record['profile_enrollment_status'];
            if ($enrollmentStatus !== '' && $enrollmentStatus !== 'all' && $effectiveEnrollmentStatus !== $enrollmentStatus) {
                return false;
            }
            if ($academicStatus !== '' && $academicStatus !== 'all' && (string) $record['academic_status'] !== $academicStatus) {
                return false;
            }
            if ($stageIds && !in_array((int) $record['stage_id'], $stageIds, true)) {
                return false;
            }
            if ($gradeIds && !in_array((int) $record['grade_id'], $gradeIds, true)) {
                return false;
            }
            if ($classIds && !in_array((int) $record['class_id'], $classIds, true)) {
                return false;
            }
            if ($annualState !== '' && $annualState !== 'all' && $record['annual_state'] !== $annualState) {
                return false;
            }
            if ($profileLevel !== '' && $profileLevel !== 'all' && $record['profile_level'] !== $profileLevel) {
                return false;
            }
            if ($missingSection !== '' && (($record['section_percentages'][$missingSection] ?? 100) >= 100)) {
                return false;
            }
            if ($search !== '') {
                $haystack = mb_strtolower((string) $record['name'] . ' ' . (string) $record['student_code'], 'UTF-8');
                if (mb_strpos($haystack, $search, 0, 'UTF-8') === false) {
                    return false;
                }
            }
            return true;
        }));
    }

    /** @param mixed $ids @return array<int,int> */
    private function normaliseIds($ids): array
    {
        if (!is_array($ids)) {
            $ids = $ids === '' || $ids === null ? [] : [$ids];
        }
        return array_values(array_unique(array_filter(
            array_map('intval', $ids),
            static fn(int $id): bool => $id > 0
        )));
    }

    /** @param array<int,array<string,mixed>> $records */
    private function sortRecords(array &$records, string $sortKey, string $sortDirection): void
    {
        $allowed = ['name', 'student_code', 'stage_name', 'grade_name', 'class_name', 'profile_pct', 'annual_state'];
        $sortKey = in_array($sortKey, $allowed, true) ? $sortKey : 'name';
        $direction = strtolower($sortDirection) === 'desc' ? -1 : 1;
        usort($records, static function (array $left, array $right) use ($sortKey, $direction): int {
            $a = $left[$sortKey] ?? '';
            $b = $right[$sortKey] ?? '';
            if (is_numeric($a) && is_numeric($b)) {
                return ((float) $a <=> (float) $b) * $direction;
            }
            return strnatcasecmp((string) $a, (string) $b) * $direction;
        });
    }
}
