<?php

declare(strict_types=1);

/**
 * The one authoritative interpreter for an assessment scheme's grade/class scope.
 *
 * A row with class_id NULL is a dynamic whole-grade scope.  A row with class_id set
 * is an explicit, fixed class scope.  Legacy schemes continue to resolve through
 * their subject_grade_assignment until they are migrated deliberately.
 */
final class AssessmentSchemeScopeResolver
{
    private PDO $db;

    public function __construct(PDO $db)
    {
        $this->db = $db;
    }

    public function supportsExplicitScopes(): bool
    {
        return $this->tableExists('assessment_scheme_scopes');
    }

    /** @return list<array{grade_id:int,class_id:?int,scope_kind:string}> */
    public function scopesForScheme(int $schemeId, bool $forUpdate = false): array
    {
        if ($schemeId <= 0) {
            throw new InvalidArgumentException('الخطة غير صالحة.');
        }

        $supportsExplicitScopes = $this->supportsExplicitScopes();
        if ($supportsExplicitScopes) {
            $sql = 'SELECT grade_id, class_id, scope_kind FROM assessment_scheme_scopes WHERE scheme_id = ? ORDER BY grade_id, class_id';
            if ($forUpdate && $this->db->inTransaction() && $this->db->getAttribute(PDO::ATTR_DRIVER_NAME) === 'mysql') {
                $sql .= ' FOR UPDATE';
            }
            $stmt = $this->db->prepare($sql);
            $stmt->execute([$schemeId]);
            $rows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
            if ($rows !== []) {
                return array_map(static fn(array $row): array => [
                    'grade_id' => (int) $row['grade_id'],
                    'class_id' => $row['class_id'] !== null ? (int) $row['class_id'] : null,
                    'scope_kind' => (string) $row['scope_kind'],
                ], $rows);
            }
            if ($this->columnExists('assessment_schemes', 'family_id')) {
                $familySql = 'SELECT family_id FROM assessment_schemes WHERE id = ? LIMIT 1';
                if ($forUpdate && $this->db->inTransaction() && $this->db->getAttribute(PDO::ATTR_DRIVER_NAME) === 'mysql') {
                    $familySql .= ' FOR UPDATE';
                }
                $familyStmt = $this->db->prepare($familySql);
                $familyStmt->execute([$schemeId]);
                $familyId = $familyStmt->fetchColumn();
                if ($familyId !== false && $familyId !== null) {
                    return [];
                }
            }
        }

        $sql = "SELECT s.grade_id, s.subject_assignment_id, a.class_id
            FROM assessment_schemes s
            LEFT JOIN subject_grade_assignments a ON a.id = s.subject_assignment_id
            WHERE s.id = ? LIMIT 1";
        if ($forUpdate && $this->db->inTransaction() && $this->db->getAttribute(PDO::ATTR_DRIVER_NAME) === 'mysql') {
            $sql .= ' FOR UPDATE';
        }
        $stmt = $this->db->prepare($sql);
        $stmt->execute([$schemeId]);
        $scheme = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$scheme) {
            throw new InvalidArgumentException('الخطة غير موجودة.');
        }

        $classId = $scheme['class_id'] !== null ? (int) $scheme['class_id'] : null;
        return [[
            'grade_id' => (int) $scheme['grade_id'],
            'class_id' => $classId,
            'scope_kind' => $classId === null ? 'grade' : 'class',
        ]];
    }

    public function schemeCoversClass(int $schemeId, int $gradeId, ?int $classId): bool
    {
        if ($gradeId <= 0) {
            return false;
        }

        foreach ($this->scopesForScheme($schemeId) as $scope) {
            if ($scope['grade_id'] !== $gradeId) {
                continue;
            }
            if ($scope['class_id'] === null || ($classId !== null && $scope['class_id'] === $classId)) {
                return true;
            }
        }

        return false;
    }

    public function assertSchemeCoversClass(int $schemeId, int $gradeId, ?int $classId): void
    {
        if (!$this->schemeCoversClass($schemeId, $gradeId, $classId)) {
            throw new RuntimeException('الفصل المحدد خارج نطاق خطة الدرجات.');
        }
    }

    /**
     * Finds the active subject link that can legally support one scheme scope.
     * A grade-wide material link covers an explicit class; a class link never
     * implicitly covers the entire grade.
     *
     * @return array<string,mixed>|null
     */
    public function findActiveSubjectLink(
        int $academicYearId,
        int $termId,
        int $subjectId,
        int $gradeId,
        ?int $classId
    ): ?array {
        $sql = "SELECT id, academic_year_id, term_id, subject_id, stage_id, grade_id, class_id
            FROM subject_grade_assignments
            WHERE academic_year_id = :year_id
              AND subject_id = :subject_id
              AND grade_id = :grade_id
              AND is_active = 1
              AND (term_id = :term_id OR term_id IS NULL)";
        $params = [
            ':year_id' => $academicYearId,
            ':subject_id' => $subjectId,
            ':grade_id' => $gradeId,
            ':term_id' => $termId,
        ];
        if ($classId === null) {
            $sql .= ' AND class_id IS NULL';
        } else {
            $sql .= ' AND (class_id = :class_id OR class_id IS NULL)';
            $params[':class_id'] = $classId;
        }
        $sql .= ' ORDER BY class_id IS NULL ASC, id DESC LIMIT 1';
        $stmt = $this->db->prepare($sql);
        $stmt->execute($params);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row ?: null;
    }

    /** @param list<array{grade_id:int,class_id:?int,scope_kind:string}> $scopes */
    public function firstMissingSubjectLink(int $academicYearId, int $termId, int $subjectId, array $scopes): ?array
    {
        foreach ($scopes as $scope) {
            if ($this->findActiveSubjectLink($academicYearId, $termId, $subjectId, (int) $scope['grade_id'], $scope['class_id']) === null) {
                return $scope;
            }
        }
        return null;
    }

    /**
     * @param array{grade_id:int,class_id:?int,scope_kind:string} $scope
     * @param list<int> $excludeSchemeIds
     * @return list<array<string,mixed>>
     */
    public function findActiveOverlaps(
        int $academicYearId,
        int $termId,
        int $subjectId,
        array $scope,
        array $excludeSchemeIds = []
    ): array {
        $params = [
            ':year_id' => $academicYearId,
            ':term_id' => $termId,
            ':subject_id' => $subjectId,
        ];
        $sql = "SELECT id, name, grade_id, subject_assignment_id
            FROM assessment_schemes
            WHERE academic_year_id = :year_id
              AND term_id = :term_id
              AND subject_id = :subject_id
              AND status = 'active'";
        $excludeSchemeIds = array_values(array_unique(array_filter(array_map('intval', $excludeSchemeIds), static fn(int $id): bool => $id > 0)));
        if ($excludeSchemeIds !== []) {
            $placeholders = [];
            foreach ($excludeSchemeIds as $index => $id) {
                $key = ':exclude_' . $index;
                $placeholders[] = $key;
                $params[$key] = $id;
            }
            $sql .= ' AND id NOT IN (' . implode(',', $placeholders) . ')';
        }
        $stmt = $this->db->prepare($sql);
        $stmt->execute($params);
        $candidates = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
        $overlaps = [];
        foreach ($candidates as $candidate) {
            foreach ($this->scopesForScheme((int) $candidate['id']) as $candidateScope) {
                if ($this->scopesOverlap($scope, $candidateScope)) {
                    $candidate['overlap_scope'] = $candidateScope;
                    $overlaps[] = $candidate;
                    break;
                }
            }
        }
        return $overlaps;
    }

    /**
     * @param array{grade_id:int,class_id:?int,scope_kind:string} $scope
     * @param list<int> $excludeSchemeIds
     */
    public function assertNoActiveOverlap(
        int $academicYearId,
        int $termId,
        int $subjectId,
        array $scope,
        array $excludeSchemeIds = []
    ): void {
        $overlaps = $this->findActiveOverlaps($academicYearId, $termId, $subjectId, $scope, $excludeSchemeIds);
        if ($overlaps === []) {
            return;
        }
        $names = array_slice(array_map(static fn(array $row): string => (string) $row['name'], $overlaps), 0, 3);
        throw new RuntimeException('يوجد تداخل مع خطة درجات نشطة: ' . implode('، ', $names) . '.');
    }

    /** @param array{grade_id:int,class_id:?int,scope_kind:string} $left @param array{grade_id:int,class_id:?int,scope_kind:string} $right */
    public function scopesOverlap(array $left, array $right): bool
    {
        if ((int) $left['grade_id'] !== (int) $right['grade_id']) {
            return false;
        }
        return $left['class_id'] === null || $right['class_id'] === null || (int) $left['class_id'] === (int) $right['class_id'];
    }

    public function countOperationalDependencies(int $schemeId): int
    {
        $count = 0;
        foreach (['assessment_windows' => 'scheme_id', 'student_marks' => 'scheme_id', 'student_report_snapshots' => 'scheme_id'] as $table => $column) {
            if (!$this->tableExists($table)) {
                continue;
            }
            $stmt = $this->db->prepare("SELECT COUNT(*) FROM `$table` WHERE `$column` = ?");
            $stmt->execute([$schemeId]);
            $count += (int) $stmt->fetchColumn();
        }
        return $count;
    }

    /**
     * Counts non-archived plans with explicit scopes that would lose a source
     * material link if the supplied subject/grade assignment were removed or
     * moved. A whole-grade link can support every explicit class in that grade;
     * a class-only link can support only that exact class.
     *
     * @param array<string,mixed> $assignment
     */
    public function countSchemesDependentOnSubjectAssignment(array $assignment): int
    {
        if (!$this->supportsExplicitScopes()) {
            return 0;
        }
        $academicYearId = (int) ($assignment['academic_year_id'] ?? 0);
        $subjectId = (int) ($assignment['subject_id'] ?? 0);
        $gradeId = (int) ($assignment['grade_id'] ?? 0);
        $termValue = $assignment['term_id'] ?? null;
        $classValue = $assignment['class_id'] ?? null;
        $termId = $termValue !== null && $termValue !== ''
            ? (int) $termValue
            : null;
        $classId = $classValue !== null && $classValue !== ''
            ? (int) $classValue
            : null;
        if ($academicYearId <= 0 || $subjectId <= 0 || $gradeId <= 0) {
            return 0;
        }

        $sql = "SELECT scheme.id AS scheme_id, scheme.term_id, scope.class_id
            FROM assessment_schemes scheme
            JOIN assessment_scheme_scopes scope ON scope.scheme_id = scheme.id
            WHERE scheme.academic_year_id = :year_id
              AND scheme.subject_id = :subject_id
              AND scheme.status <> 'archived'
              AND scope.grade_id = :grade_id";
        $params = [
            ':year_id' => $academicYearId,
            ':subject_id' => $subjectId,
            ':grade_id' => $gradeId,
        ];
        if ($termId !== null) {
            $sql .= ' AND scheme.term_id = :term_id';
            $params[':term_id'] = $termId;
        }
        if ($classId !== null) {
            $sql .= ' AND scope.class_id = :class_id';
            $params[':class_id'] = $classId;
        }

        $sql .= ' ORDER BY scheme.id, scope.class_id';

        $stmt = $this->db->prepare($sql);
        $stmt->execute($params);
        $dependentSchemeIds = [];
        $alternateBaseSql = "SELECT 1
            FROM subject_grade_assignments
            WHERE id <> :assignment_id
              AND academic_year_id = :year_id
              AND subject_id = :subject_id
              AND grade_id = :grade_id
              AND is_active = 1
              AND (term_id = :term_id OR term_id IS NULL)";
        $alternateGrade = $this->db->prepare($alternateBaseSql . ' AND class_id IS NULL LIMIT 1');
        $alternateClass = $this->db->prepare($alternateBaseSql . ' AND (class_id = :scope_class_id OR class_id IS NULL) LIMIT 1');
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) ?: [] as $scope) {
            $schemeId = (int) $scope['scheme_id'];
            if (isset($dependentSchemeIds[$schemeId])) {
                continue;
            }
            $scopeClassId = $scope['class_id'] !== null ? (int) $scope['class_id'] : null;
            $alternate = $scopeClassId === null ? $alternateGrade : $alternateClass;
            $alternateParams = [
                ':assignment_id' => (int) ($assignment['id'] ?? 0),
                ':year_id' => $academicYearId,
                ':subject_id' => $subjectId,
                ':grade_id' => $gradeId,
                ':term_id' => (int) $scope['term_id'],
            ];
            if ($scopeClassId !== null) {
                $alternateParams[':scope_class_id'] = $scopeClassId;
            }
            $alternate->execute($alternateParams);
            if (!$alternate->fetchColumn()) {
                $dependentSchemeIds[$schemeId] = true;
            }
        }

        return count($dependentSchemeIds);
    }

    private function tableExists(string $table): bool
    {
        try {
            if ($this->db->getAttribute(PDO::ATTR_DRIVER_NAME) === 'sqlite') {
                $stmt = $this->db->prepare("SELECT 1 FROM sqlite_master WHERE type = 'table' AND name = ? LIMIT 1");
                $stmt->execute([$table]);
                return (bool) $stmt->fetchColumn();
            }
            $stmt = $this->db->prepare('SELECT 1 FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?');
            $stmt->execute([$table]);
            return (bool) $stmt->fetchColumn();
        } catch (Throwable $e) {
            return false;
        }
    }

    private function columnExists(string $table, string $column): bool
    {
        try {
            if ($this->db->getAttribute(PDO::ATTR_DRIVER_NAME) === 'sqlite') {
                $stmt = $this->db->query("PRAGMA table_info('" . str_replace("'", "''", $table) . "')");
                foreach ($stmt ? ($stmt->fetchAll(PDO::FETCH_ASSOC) ?: []) : [] as $row) {
                    if ((string) ($row['name'] ?? '') === $column) {
                        return true;
                    }
                }
                return false;
            }
            $stmt = $this->db->prepare('SELECT 1 FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?');
            $stmt->execute([$table, $column]);
            return (bool) $stmt->fetchColumn();
        } catch (Throwable $e) {
            return false;
        }
    }
}
