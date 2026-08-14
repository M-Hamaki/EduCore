<?php

declare(strict_types=1);

require_once __DIR__ . '/AssessmentSchemeScopeResolver.php';

/**
 * Read model for the spreadsheet-style assessment overview.
 *
 * This query never mutates data. Sheet writes pass through
 * AssessmentMarkAdministrationService so audit, undo, atomicity and lock
 * policies remain identical in the sheet and register views.
 */
final class AssessmentMarkSheetQuery
{
    private const MAX_STUDENTS = 1200;

    private PDO $db;
    private array $tableCache = [];

    public function __construct(PDO $db)
    {
        $this->db = $db;
    }

    public function options(int $academicYearId): array
    {
        $empty = ['grades' => [], 'terms' => [], 'classes' => [], 'schemes' => []];
        if ($academicYearId <= 0 || !$this->tableExists('assessment_schemes')) {
            return $empty;
        }

        $grades = $this->fetchAll(
            "SELECT DISTINCT g.id, g.grade_name AS name, g.stage_id, s.stage_name
             FROM assessment_schemes scheme
             JOIN grades g ON g.id = scheme.grade_id
             LEFT JOIN stages s ON s.id = g.stage_id
             WHERE scheme.academic_year_id = ?
             ORDER BY COALESCE(s.stage_order, 0), g.grade_order, g.grade_name",
            [$academicYearId]
        );
        $terms = $this->fetchAll(
            "SELECT id, name, term_order, status
             FROM academic_terms
             WHERE academic_year_id = ?
             ORDER BY term_order, id",
            [$academicYearId]
        );
        $classes = $this->fetchAll(
            "SELECT c.id, c.name, c.grade_id
             FROM classes c
             WHERE EXISTS (
                 SELECT 1 FROM assessment_schemes scheme
                 WHERE scheme.academic_year_id = ? AND scheme.grade_id = c.grade_id
             )
             ORDER BY c.name",
            [$academicYearId]
        );
        $schemes = $this->fetchAll(
            "SELECT scheme.id, scheme.grade_id, scheme.term_id, scheme.subject_id,
                    scheme.name AS scheme_name, scheme.status, subject.name AS subject_name
             FROM assessment_schemes scheme
             JOIN subjects subject ON subject.id = scheme.subject_id
             WHERE scheme.academic_year_id = ?
             ORDER BY scheme.grade_id, scheme.term_id,
                      CASE scheme.status WHEN 'active' THEN 0 WHEN 'draft' THEN 1 ELSE 2 END,
                      subject.name, scheme.id",
            [$academicYearId]
        );

        return compact('grades', 'terms', 'classes', 'schemes');
    }

    public function load(int $academicYearId, int $gradeId, int $termId, int $schemeId, int $classId = 0): array
    {
        if ($academicYearId <= 0 || $gradeId <= 0 || $termId <= 0 || $schemeId <= 0) {
            throw new InvalidArgumentException('اختر الصف الدراسي والترم والمادة أولًا.');
        }

        $schemeStmt = $this->db->prepare(
            "SELECT scheme.*, subject.name AS subject_name, grade.grade_name, term.name AS term_name
             FROM assessment_schemes scheme
             JOIN subjects subject ON subject.id = scheme.subject_id
             JOIN grades grade ON grade.id = scheme.grade_id
             JOIN academic_terms term ON term.id = scheme.term_id
             WHERE scheme.id = ? AND scheme.academic_year_id = ?
               AND scheme.grade_id = ? AND scheme.term_id = ?
             LIMIT 1"
        );
        $schemeStmt->execute([$schemeId, $academicYearId, $gradeId, $termId]);
        $scheme = $schemeStmt->fetch(PDO::FETCH_ASSOC);
        if (!$scheme) {
            throw new RuntimeException('المادة المختارة لا تنتمي إلى الصف أو الترم المحدد.');
        }

        if ($classId > 0) {
            $classStmt = $this->db->prepare('SELECT id FROM classes WHERE id = ? AND grade_id = ? LIMIT 1');
            $classStmt->execute([$classId, $gradeId]);
            if (!$classStmt->fetchColumn()) {
                throw new RuntimeException('الفصل المختار لا ينتمي إلى الصف المحدد.');
            }
        }

        $scopeResolver = new AssessmentSchemeScopeResolver($this->db);
        if ($classId > 0) {
            $scopeResolver->assertSchemeCoversClass($schemeId, $gradeId, $classId);
        }
        $allowedClassIds = $this->allowedScopeClassIds($scopeResolver, $schemeId, $gradeId);
        $students = $this->students($academicYearId, $gradeId, $termId, $schemeId, $classId, $allowedClassIds);
        $truncated = count($students) > self::MAX_STUDENTS;
        if ($truncated) {
            $students = array_slice($students, 0, self::MAX_STUDENTS);
        }
        $studentLockMap = $this->studentLocks($academicYearId, array_map('intval', array_column($students, 'id')));

        $components = $this->fetchAll(
            "SELECT component.id, component.name, component.max_grade, component.is_weekly,
                    component.repeat_per_week, component.accepts_absence,
                    component.accepts_excused_absence, component.sort_order,
                    component.calculation_mode, component.is_active
             FROM assessment_components component
             WHERE component.scheme_id = ?
               AND (component.is_active = 1 OR EXISTS (
                   SELECT 1 FROM student_marks mark_row
                   WHERE mark_row.component_id = component.id AND mark_row.academic_year_id = ?
               ))
             ORDER BY component.sort_order, component.id",
            [$schemeId, $academicYearId]
        );
        $weeks = $this->fetchAll(
            "SELECT week.id, week.name, week.week_order, week.start_date, week.end_date,
                    week.week_type, week.counts_for_average
             FROM academic_weeks week
             WHERE week.academic_year_id = ? AND week.term_id = ?
               AND (
                   (week.week_type = 'study' AND week.counts_for_average = 1)
                   OR EXISTS (SELECT 1 FROM student_marks mark_row WHERE mark_row.scheme_id = ? AND mark_row.week_id = week.id)
                   OR EXISTS (SELECT 1 FROM assessment_windows window_row WHERE window_row.scheme_id = ? AND window_row.week_id = week.id)
                   OR EXISTS (
                       SELECT 1 FROM assessment_component_week_rules rule_row
                       JOIN assessment_components rule_component ON rule_component.id = rule_row.component_id
                       WHERE rule_component.scheme_id = ? AND rule_row.week_id = week.id
                   )
               )
             ORDER BY week.week_order, week.start_date, week.id",
            [$academicYearId, $termId, $schemeId, $schemeId, $schemeId]
        );

        $componentIds = array_map('intval', array_column($components, 'id'));
        $rules = $this->weekRules($componentIds);
        $windowSlots = $this->windowSlots($schemeId);
        $writableWindows = $this->writableWindows($academicYearId, $schemeId);
        $marks = $this->marks($academicYearId, $termId, $schemeId, $students);
        $markSlots = [];
        foreach ($marks as $mark) {
            $markSlots[$this->slotKey((int) $mark['component_id'], $mark['week_id'])] = true;
        }

        $groups = [];
        foreach ($weeks as $week) {
            $columns = [];
            $weekId = (int) $week['id'];
            foreach ($components as $component) {
                $componentId = (int) $component['id'];
                $key = $this->slotKey($componentId, $weekId);
                $rule = $rules[$key] ?? null;
                $isWeekly = !empty($component['is_weekly']) || !empty($component['repeat_per_week']);
                $include = isset($markSlots[$key]) || isset($windowSlots[$key]);
                if ($rule !== null) {
                    $include = $include || !empty($rule['is_included']);
                } elseif ($isWeekly && ($week['week_type'] ?? '') === 'study' && !empty($week['counts_for_average'])) {
                    $include = true;
                }
                if (!$include) {
                    continue;
                }
                $columns[] = $this->column($component, $weekId, $rule, !empty($scheme['enable_excused_absence']));
            }
            if ($columns !== []) {
                $groups[] = [
                    'key' => 'week-' . $weekId,
                    'name' => (string) $week['name'],
                    'date_label' => $this->dateLabel($week['start_date'] ?? null, $week['end_date'] ?? null),
                    'columns' => $columns,
                ];
            }
        }

        $generalColumns = [];
        foreach ($components as $component) {
            $componentId = (int) $component['id'];
            $nullKey = $this->slotKey($componentId, null);
            $isWeekly = !empty($component['is_weekly']) || !empty($component['repeat_per_week']);
            $hasSpecificSlot = false;
            foreach ($weeks as $week) {
                $specificKey = $this->slotKey($componentId, (int) $week['id']);
                if (isset($markSlots[$specificKey]) || isset($windowSlots[$specificKey]) || !empty($rules[$specificKey]['is_included'])) {
                    $hasSpecificSlot = true;
                    break;
                }
            }
            if (isset($markSlots[$nullKey]) || isset($windowSlots[$nullKey]) || (!$isWeekly && !$hasSpecificSlot)) {
                $generalColumns[] = $this->column($component, null, null, !empty($scheme['enable_excused_absence']));
            }
        }
        if ($generalColumns !== []) {
            $groups[] = [
                'key' => 'general',
                'name' => 'أعمال عامة',
                'date_label' => 'مكوّنات غير مرتبطة بأسبوع',
                'columns' => $generalColumns,
            ];
        }

        $marksByStudent = [];
        foreach ($marks as $mark) {
            $studentId = (int) $mark['student_id'];
            $marksByStudent[$studentId][$this->slotKey((int) $mark['component_id'], $mark['week_id'])] = $this->presentMark($mark);
        }

        $columnCount = array_sum(array_map(static fn(array $group): int => count($group['columns']), $groups));
        $markCount = count(array_filter($marks, static fn(array $mark): bool => ($mark['mark_status'] ?? 'empty') !== 'empty'));

        return [
            'scheme' => [
                'id' => (int) $scheme['id'],
                'name' => (string) $scheme['name'],
                'subject_name' => (string) $scheme['subject_name'],
                'grade_name' => (string) $scheme['grade_name'],
                'term_name' => (string) $scheme['term_name'],
                'status' => (string) $scheme['status'],
            ],
            'students' => array_map(static function (array $student) use ($marksByStudent, $studentLockMap): array {
                $studentId = (int) $student['id'];
                return [
                    'id' => $studentId,
                    'name' => (string) $student['name'],
                    'username' => (string) ($student['username'] ?? ''),
                    'student_code' => (string) ($student['student_code'] ?? ''),
                    'class_id' => (int) ($student['class_id'] ?? 0),
                    'class_name' => (string) ($student['class_name'] ?? 'بدون فصل'),
                    'account_status' => (string) ($student['account_status'] ?? ''),
                    'locked' => isset($studentLockMap[$studentId]),
                    'marks' => $marksByStudent[$studentId] ?? new stdClass(),
                ];
            }, $students),
            'groups' => $groups,
            'writable_windows' => $writableWindows,
            'summary' => [
                'students' => count($students),
                'columns' => $columnCount,
                'marks' => $markCount,
                'missing' => max(0, (count($students) * $columnCount) - $markCount),
            ],
            'truncated' => $truncated,
        ];
    }

    /** @param list<int>|null $allowedClassIds Null means every class in the grade is in scope. */
    private function students(
        int $academicYearId,
        int $gradeId,
        int $termId,
        int $schemeId,
        int $classId,
        ?array $allowedClassIds
    ): array
    {
        $classExpression = 'COALESCE(enrollment.class_id, latest_mark.class_id_at_entry, student.class_id)';
        $where = ["student.role = 'student'", '(enrollment.student_id IS NOT NULL OR latest_mark.id IS NOT NULL)'];
        $params = [$academicYearId, $schemeId, $academicYearId, $termId];
        if ($classId > 0) {
            $where[] = $classExpression . ' = ?';
            $params[] = $classId;
        } elseif ($allowedClassIds !== null) {
            if ($allowedClassIds === []) {
                return [];
            }
            $where[] = $classExpression . ' IN (' . implode(',', array_fill(0, count($allowedClassIds), '?')) . ')';
            foreach ($allowedClassIds as $allowedClassId) {
                $params[] = $allowedClassId;
            }
        }
        $params[] = $gradeId;

        $sql = "SELECT student.id, student.name, student.username, student.status AS account_status,
                       profile.student_code, {$classExpression} AS class_id, class_entry.name AS class_name
                FROM users student
                LEFT JOIN student_enrollments enrollment
                  ON enrollment.student_id = student.id
                 AND enrollment.academic_year_id = ?
                 AND enrollment.enrollment_status = 'enrolled'
                LEFT JOIN student_marks latest_mark ON latest_mark.id = (
                    SELECT mark_lookup.id FROM student_marks mark_lookup
                    WHERE mark_lookup.student_id = student.id
                      AND mark_lookup.scheme_id = ?
                      AND mark_lookup.academic_year_id = ?
                      AND mark_lookup.term_id = ?
                    ORDER BY mark_lookup.id DESC LIMIT 1
                )
                LEFT JOIN student_profiles profile ON profile.user_id = student.id
                LEFT JOIN classes class_entry ON class_entry.id = {$classExpression}
                WHERE " . implode(' AND ', $where) . "
                  AND (enrollment.grade_id = ? OR latest_mark.id IS NOT NULL)
                ORDER BY class_entry.name, student.name, student.id
                LIMIT " . (self::MAX_STUDENTS + 1);
        $stmt = $this->db->prepare($sql);
        $stmt->execute($params);
        return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    }

    /** @return list<int>|null Null means every class in the grade is in scope. */
    private function allowedScopeClassIds(AssessmentSchemeScopeResolver $resolver, int $schemeId, int $gradeId): ?array
    {
        $classIds = [];
        foreach ($resolver->scopesForScheme($schemeId) as $scope) {
            if ((int) $scope['grade_id'] !== $gradeId) {
                continue;
            }
            if ($scope['class_id'] === null) {
                return null;
            }
            $classIds[(int) $scope['class_id']] = true;
        }
        return array_keys($classIds);
    }

    private function marks(int $academicYearId, int $termId, int $schemeId, array $students): array
    {
        if ($students === []) {
            return [];
        }
        $studentIds = array_map('intval', array_column($students, 'id'));
        $placeholders = implode(',', array_fill(0, count($studentIds), '?'));
        $weekRuleJoin = $this->tableExists('assessment_component_week_rules')
            ? 'LEFT JOIN assessment_component_week_rules week_rule ON week_rule.component_id = mark_row.component_id AND week_rule.week_id = mark_row.week_id'
            : '';
        $maxGrade = $this->tableExists('assessment_component_week_rules')
            ? 'COALESCE(week_rule.max_grade_override, component.max_grade)'
            : 'component.max_grade';

        $sql = "SELECT mark_row.id, mark_row.student_id, mark_row.component_id, mark_row.week_id,
                       mark_row.class_id_at_entry,
                       mark_row.value, mark_row.mark_status, mark_row.note, mark_row.review_status,
                       mark_row.locked_at, mark_row.updated_at, component.name AS component_name,
                       {$maxGrade} AS max_grade, component.accepts_absence,
                       component.accepts_excused_absence
                FROM student_marks mark_row
                JOIN assessment_components component ON component.id = mark_row.component_id
                {$weekRuleJoin}
                WHERE mark_row.academic_year_id = ? AND mark_row.term_id = ? AND mark_row.scheme_id = ?
                  AND mark_row.student_id IN ({$placeholders})
                ORDER BY mark_row.id";
        $stmt = $this->db->prepare($sql);
        $stmt->execute(array_merge([$academicYearId, $termId, $schemeId], $studentIds));
        $marks = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
        $studentLocks = $this->studentLocks($academicYearId, $studentIds);
        $publishedCounts = $this->publishedCounts($academicYearId, $studentIds);
        $lockedWindows = $this->fetchAll(
            "SELECT component_id, week_id, class_id
             FROM assessment_windows
             WHERE scheme_id = ? AND status = 'locked'",
            [$schemeId]
        );
        $lockedWindowsByComponent = [];
        foreach ($lockedWindows as $window) {
            $lockedWindowsByComponent[(int) $window['component_id']][] = $window;
        }

        foreach ($marks as &$mark) {
            $studentId = (int) $mark['student_id'];
            $componentId = (int) $mark['component_id'];
            $weekId = $mark['week_id'] !== null ? (int) $mark['week_id'] : null;
            $classId = $mark['class_id_at_entry'] !== null ? (int) $mark['class_id_at_entry'] : null;
            $mark['student_locked'] = isset($studentLocks[$studentId]) ? 1 : 0;
            $mark['published_count'] = $publishedCounts[$studentId . ':' . $this->slotKey($componentId, $weekId)] ?? 0;
            $mark['locked_window_count'] = 0;
            foreach ($lockedWindowsByComponent[$componentId] ?? [] as $window) {
                if ($window['week_id'] !== null && (int) $window['week_id'] !== $weekId) {
                    continue;
                }
                if ($window['class_id'] !== null && (int) $window['class_id'] !== $classId) {
                    continue;
                }
                $mark['locked_window_count']++;
            }
        }
        unset($mark);
        return $marks;
    }

    private function studentLocks(int $academicYearId, array $studentIds): array
    {
        if ($studentIds === [] || !$this->tableExists('assessment_student_locks')) {
            return [];
        }
        $placeholders = implode(',', array_fill(0, count($studentIds), '?'));
        $stmt = $this->db->prepare("SELECT student_id FROM assessment_student_locks
            WHERE academic_year_id = ? AND student_id IN ({$placeholders})");
        $stmt->execute(array_merge([$academicYearId], $studentIds));
        return array_fill_keys(array_map('intval', $stmt->fetchAll(PDO::FETCH_COLUMN) ?: []), true);
    }

    private function publishedCounts(int $academicYearId, array $studentIds): array
    {
        if ($studentIds === [] || !$this->tableExists('published_report_details') || !$this->tableExists('published_reports')) {
            return [];
        }
        $placeholders = implode(',', array_fill(0, count($studentIds), '?'));
        $stmt = $this->db->prepare("SELECT report_row.student_id, report_detail.component_id,
                report_detail.week_id, COUNT(*) AS published_count
            FROM published_reports report_row
            JOIN published_report_details report_detail ON report_detail.published_report_id = report_row.id
            WHERE report_row.academic_year_id = ? AND report_row.student_id IN ({$placeholders})
            GROUP BY report_row.student_id, report_detail.component_id, report_detail.week_id");
        $stmt->execute(array_merge([$academicYearId], $studentIds));
        $counts = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) ?: [] as $row) {
            $key = (int) $row['student_id'] . ':' . $this->slotKey((int) $row['component_id'], $row['week_id']);
            $counts[$key] = (int) $row['published_count'];
        }
        return $counts;
    }

    private function weekRules(array $componentIds): array
    {
        if ($componentIds === [] || !$this->tableExists('assessment_component_week_rules')) {
            return [];
        }
        $placeholders = implode(',', array_fill(0, count($componentIds), '?'));
        $stmt = $this->db->prepare("SELECT component_id, week_id, is_included, max_grade_override
            FROM assessment_component_week_rules WHERE component_id IN ({$placeholders})");
        $stmt->execute($componentIds);
        $rules = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) ?: [] as $rule) {
            $rules[$this->slotKey((int) $rule['component_id'], $rule['week_id'])] = $rule;
        }
        return $rules;
    }

    private function windowSlots(int $schemeId): array
    {
        $stmt = $this->db->prepare('SELECT DISTINCT component_id, week_id FROM assessment_windows WHERE scheme_id = ?');
        $stmt->execute([$schemeId]);
        $slots = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) ?: [] as $row) {
            $slots[$this->slotKey((int) $row['component_id'], $row['week_id'])] = true;
        }
        return $slots;
    }

    private function writableWindows(int $academicYearId, int $schemeId): array
    {
        $stmt = $this->db->prepare("SELECT window_row.id, window_row.scheme_id, window_row.component_id,
                window_row.week_id, window_row.class_id, window_row.allow_edit_after_save,
                window_row.requires_review, COALESCE(week_rule.max_grade_override, component.max_grade) AS max_grade
            FROM assessment_windows window_row
            JOIN assessment_schemes scheme ON scheme.id = window_row.scheme_id
            JOIN assessment_components component ON component.id = window_row.component_id
            LEFT JOIN assessment_component_week_rules week_rule
              ON week_rule.component_id = window_row.component_id AND week_rule.week_id = window_row.week_id
            WHERE window_row.scheme_id = ? AND scheme.academic_year_id = ?
              AND window_row.status = 'open'
              AND (window_row.opens_at IS NULL OR window_row.opens_at <= NOW())
              AND (window_row.closes_at IS NULL OR window_row.closes_at >= NOW())
              AND scheme.status = 'active' AND component.is_active = 1
              AND (window_row.week_id IS NULL OR week_rule.is_included IS NULL OR week_rule.is_included = 1)
            ORDER BY window_row.id");
        $stmt->execute([$schemeId, $academicYearId]);

        return array_map(static function (array $window): array {
            return [
                'id' => (int) $window['id'],
                'scheme_id' => (int) $window['scheme_id'],
                'component_id' => (int) $window['component_id'],
                'week_id' => $window['week_id'] !== null ? (int) $window['week_id'] : null,
                'class_id' => $window['class_id'] !== null ? (int) $window['class_id'] : null,
                'allow_edit_after_save' => !empty($window['allow_edit_after_save']),
                'requires_review' => !empty($window['requires_review']),
                'max_grade' => (float) $window['max_grade'],
            ];
        }, $stmt->fetchAll(PDO::FETCH_ASSOC) ?: []);
    }

    private function column(array $component, ?int $weekId, ?array $rule, bool $excusedAbsenceEnabled): array
    {
        $maxGrade = $rule !== null && $rule['max_grade_override'] !== null
            ? (float) $rule['max_grade_override']
            : (float) $component['max_grade'];
        return [
            'key' => $this->slotKey((int) $component['id'], $weekId),
            'component_id' => (int) $component['id'],
            'week_id' => $weekId,
            'name' => (string) $component['name'],
            'max_grade' => $maxGrade,
            'accepts_absence' => !empty($component['accepts_absence']),
            'accepts_excused_absence' => $excusedAbsenceEnabled
                && !empty($component['accepts_excused_absence']),
        ];
    }

    private function presentMark(array $mark): array
    {
        return [
            'id' => (int) $mark['id'],
            'value' => $mark['value'] !== null ? (float) $mark['value'] : null,
            'status' => (string) ($mark['mark_status'] ?? 'empty'),
            'note' => (string) ($mark['note'] ?? ''),
            'review_status' => (string) ($mark['review_status'] ?? 'not_required'),
            'max_grade' => (float) ($mark['max_grade'] ?? 0),
            'published_count' => (int) ($mark['published_count'] ?? 0),
            'locked' => !empty($mark['locked_at']) || !empty($mark['student_locked']) || (int) ($mark['locked_window_count'] ?? 0) > 0,
            'updated_at' => (string) ($mark['updated_at'] ?? ''),
        ];
    }

    private function slotKey(int $componentId, $weekId): string
    {
        return $componentId . ':' . ($weekId === null ? '0' : (string) (int) $weekId);
    }

    private function dateLabel($startDate, $endDate): string
    {
        if (!$startDate || !$endDate) {
            return '';
        }
        return (string) $startDate . ' — ' . (string) $endDate;
    }

    private function fetchAll(string $sql, array $params): array
    {
        $stmt = $this->db->prepare($sql);
        $stmt->execute($params);
        return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    }

    private function tableExists(string $table): bool
    {
        if (array_key_exists($table, $this->tableCache)) {
            return $this->tableCache[$table];
        }
        $stmt = $this->db->prepare('SELECT 1 FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?');
        $stmt->execute([$table]);
        return $this->tableCache[$table] = (bool) $stmt->fetchColumn();
    }
}
