<?php

declare(strict_types=1);

/**
 * Read model for the central assessment-marks administration page.
 * Marks are canonical assessment slots; windows only provide scoped access to them.
 */
final class AssessmentMarkAdministrationQuery
{
    private PDO $db;
    private array $tableCache = [];

    public function __construct(PDO $db)
    {
        $this->db = $db;
    }

    public function summary(int $academicYearId, array $request = []): array
    {
        if ($academicYearId <= 0 || !$this->tableExists('student_marks')) {
            return ['total' => 0, 'present' => 0, 'absence' => 0, 'pending' => 0];
        }

        [$where, $params] = $this->filters($academicYearId, $request, false);
        $stmt = $this->db->prepare("SELECT COUNT(*) AS total,
                COALESCE(SUM(sm.mark_status = 'present'), 0) AS present,
                COALESCE(SUM(sm.mark_status IN ('absent', 'excused_absent')), 0) AS absence,
                COALESCE(SUM(sm.review_status = 'pending'), 0) AS pending
            {$this->baseFromSql()}
            WHERE " . implode(' AND ', $where));
        $stmt->execute($params);
        return array_merge(['total' => 0, 'present' => 0, 'absence' => 0, 'pending' => 0], $stmt->fetch(PDO::FETCH_ASSOC) ?: []);
    }

    public function load(int $academicYearId, array $request): array
    {
        $draw = max(0, (int) ($request['draw'] ?? 0));
        if ($academicYearId <= 0 || !$this->tableExists('student_marks')) {
            return ['draw' => $draw, 'recordsTotal' => 0, 'recordsFiltered' => 0, 'rows' => [], 'summary' => $this->summary(0)];
        }

        $start = max(0, (int) ($request['start'] ?? 0));
        $requestedLength = (int) ($request['length'] ?? 50);
        $length = $requestedLength === -1 ? 500 : max(10, min($requestedLength, 500));
        [$where, $params] = $this->filters($academicYearId, $request, true);
        $whereSql = implode(' AND ', $where);

        $totalStmt = $this->db->prepare('SELECT COUNT(*) FROM student_marks WHERE academic_year_id = ?');
        $totalStmt->execute([$academicYearId]);
        $total = (int) $totalStmt->fetchColumn();

        $countStmt = $this->db->prepare("SELECT COUNT(*) {$this->baseFromSql()} WHERE {$whereSql}");
        $countStmt->execute($params);
        $filtered = (int) $countStmt->fetchColumn();

        $orderMap = [
            1 => 'student.name',
            2 => 'stage.stage_name',
            3 => 'subject.name',
            4 => 'component.name',
            5 => 'sm.value',
            6 => 'sm.review_status',
            7 => 'sm.updated_at',
            8 => 'published_count',
        ];
        $orderRequest = is_array($request['order'][0] ?? null) ? $request['order'][0] : [];
        $orderColumn = $orderMap[(int) ($orderRequest['column'] ?? 1)] ?? 'student.name';
        $direction = strtolower((string) ($orderRequest['dir'] ?? 'asc')) === 'desc' ? 'DESC' : 'ASC';

        $weekRuleJoin = $this->tableExists('assessment_component_week_rules')
            ? 'LEFT JOIN assessment_component_week_rules cwr ON cwr.component_id = sm.component_id AND cwr.week_id = sm.week_id'
            : '';
        $maxGradeSelect = $this->tableExists('assessment_component_week_rules')
            ? 'COALESCE(cwr.max_grade_override, component.max_grade)'
            : 'component.max_grade';
        $studentLockSelect = $this->tableExists('assessment_student_locks')
            ? "EXISTS(SELECT 1 FROM assessment_student_locks asl WHERE asl.student_id = sm.student_id AND asl.academic_year_id = sm.academic_year_id)"
            : '0';
        $publishedSelect = $this->publishedCountSql();

        $sql = "SELECT sm.*, student.name AS student_name, sp.student_code,
                subject.name AS subject_name, scheme.name AS scheme_name, component.name AS component_name,
                grade.grade_name, stage.stage_name, class_entry.name AS class_name, week.name AS week_name,
                recorder.name AS recorded_by_name, reviewer.name AS reviewed_by_name,
                {$maxGradeSelect} AS max_grade,
                {$publishedSelect} AS published_count,
                {$studentLockSelect} AS student_locked,
                (SELECT COUNT(*) FROM assessment_windows locked_window
                    WHERE locked_window.scheme_id = sm.scheme_id
                      AND locked_window.component_id = sm.component_id
                      AND (locked_window.week_id IS NULL OR locked_window.week_id = sm.week_id)
                      AND (locked_window.class_id IS NULL OR locked_window.class_id = sm.class_id_at_entry)
                      AND locked_window.status = 'locked') AS locked_window_count,
                (SELECT COUNT(*) FROM assessment_windows matching_window
                    WHERE matching_window.scheme_id = sm.scheme_id
                      AND matching_window.component_id = sm.component_id
                      AND (matching_window.week_id IS NULL OR matching_window.week_id = sm.week_id)
                      AND (matching_window.class_id IS NULL OR matching_window.class_id = sm.class_id_at_entry)) AS matching_window_count
            {$this->baseFromSql()}
            {$weekRuleJoin}
            WHERE {$whereSql}
            ORDER BY {$orderColumn} {$direction}, sm.id ASC
            LIMIT {$length} OFFSET {$start}";
        $stmt = $this->db->prepare($sql);
        $stmt->execute($params);

        return [
            'draw' => $draw,
            'recordsTotal' => $total,
            'recordsFiltered' => $filtered,
            'rows' => $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [],
            'summary' => $this->summary($academicYearId, $request),
        ];
    }

    public function filterOptions(int $academicYearId): array
    {
        $empty = ['stages' => [], 'grades' => [], 'classes' => [], 'subjects' => [], 'schemes' => [], 'components' => [], 'weeks' => [], 'windows' => []];
        if ($academicYearId <= 0 || !$this->tableExists('student_marks')) {
            return $empty;
        }

        $queries = [
            'stages' => "SELECT DISTINCT stage.id, stage.stage_name AS name
                FROM student_marks sm JOIN assessment_schemes scheme ON scheme.id = sm.scheme_id
                LEFT JOIN grades grade ON grade.id = COALESCE(sm.grade_id, scheme.grade_id)
                LEFT JOIN stages stage ON stage.id = COALESCE(grade.stage_id, scheme.stage_id)
                WHERE sm.academic_year_id = ? AND stage.id IS NOT NULL ORDER BY stage.stage_name",
            'grades' => "SELECT DISTINCT grade.id, grade.grade_name AS name, grade.stage_id
                FROM student_marks sm JOIN assessment_schemes scheme ON scheme.id = sm.scheme_id
                LEFT JOIN grades grade ON grade.id = COALESCE(sm.grade_id, scheme.grade_id)
                WHERE sm.academic_year_id = ? AND grade.id IS NOT NULL ORDER BY grade.grade_order, grade.grade_name",
            'classes' => "SELECT DISTINCT class_entry.id, class_entry.name, class_entry.grade_id
                FROM student_marks sm JOIN classes class_entry ON class_entry.id = sm.class_id_at_entry
                WHERE sm.academic_year_id = ? ORDER BY class_entry.name",
            'subjects' => "SELECT DISTINCT subject.id, subject.name
                FROM student_marks sm JOIN subjects subject ON subject.id = sm.subject_id
                WHERE sm.academic_year_id = ? ORDER BY subject.name",
            'schemes' => "SELECT DISTINCT scheme.id, scheme.name, scheme.subject_id, scheme.grade_id, scheme.term_id
                FROM student_marks sm JOIN assessment_schemes scheme ON scheme.id = sm.scheme_id
                WHERE sm.academic_year_id = ? ORDER BY scheme.name",
            'components' => "SELECT DISTINCT component.id, component.name, component.scheme_id
                FROM student_marks sm JOIN assessment_components component ON component.id = sm.component_id
                WHERE sm.academic_year_id = ? ORDER BY component.sort_order, component.name",
            'weeks' => "SELECT DISTINCT week.id, week.name, week.term_id
                FROM student_marks sm JOIN academic_weeks week ON week.id = sm.week_id
                WHERE sm.academic_year_id = ? ORDER BY week.start_date, week.id",
            'windows' => "SELECT aw.id, aw.window_name AS name, aw.scheme_id, aw.component_id, aw.week_id, aw.class_id,
                    subject.name AS subject_name, grade.grade_name
                FROM assessment_windows aw
                JOIN assessment_schemes scheme ON scheme.id = aw.scheme_id
                JOIN subjects subject ON subject.id = scheme.subject_id
                JOIN grades grade ON grade.id = scheme.grade_id
                WHERE scheme.academic_year_id = ? ORDER BY aw.id DESC",
        ];

        $result = [];
        foreach ($queries as $key => $sql) {
            $stmt = $this->db->prepare($sql);
            $stmt->execute([$academicYearId]);
            $result[$key] = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
        }
        return array_merge($empty, $result);
    }

    private function filters(int $academicYearId, array $request, bool $includeSearch): array
    {
        $where = ['sm.academic_year_id = ?'];
        $params = [$academicYearId];
        $filterMap = [
            'stage_id' => 'stage.id',
            'grade_id' => 'grade.id',
            'class_id' => 'sm.class_id_at_entry',
            'subject_id' => 'sm.subject_id',
            'scheme_id' => 'sm.scheme_id',
            'component_id' => 'sm.component_id',
            'week_id' => 'sm.week_id',
        ];
        foreach ($filterMap as $key => $column) {
            $value = (int) ($request[$key] ?? 0);
            if ($value > 0) {
                $where[] = "{$column} = ?";
                $params[] = $value;
            }
        }

        $markStatus = (string) ($request['mark_status'] ?? '');
        if (in_array($markStatus, ['present', 'absent', 'excused_absent', 'exempt', 'empty'], true)) {
            $where[] = 'sm.mark_status = ?';
            $params[] = $markStatus;
        }
        $reviewStatus = (string) ($request['review_status'] ?? '');
        if (in_array($reviewStatus, ['not_required', 'pending', 'approved', 'rejected'], true)) {
            $where[] = 'sm.review_status = ?';
            $params[] = $reviewStatus;
        }

        $windowId = (int) ($request['window_id'] ?? 0);
        if ($windowId > 0) {
            $windowStmt = $this->db->prepare('SELECT aw.scheme_id, aw.component_id, aw.week_id, aw.class_id
                FROM assessment_windows aw JOIN assessment_schemes scheme ON scheme.id = aw.scheme_id
                WHERE aw.id = ? AND scheme.academic_year_id = ? LIMIT 1');
            $windowStmt->execute([$windowId, $academicYearId]);
            $window = $windowStmt->fetch(PDO::FETCH_ASSOC);
            if (!$window) {
                $where[] = '1 = 0';
            } else {
                $where[] = 'sm.scheme_id = ?';
                $params[] = (int) $window['scheme_id'];
                $where[] = 'sm.component_id = ?';
                $params[] = (int) $window['component_id'];
                if ($window['week_id'] !== null) {
                    $where[] = 'sm.week_id = ?';
                    $params[] = (int) $window['week_id'];
                }
                if ($window['class_id'] !== null) {
                    $where[] = 'sm.class_id_at_entry = ?';
                    $params[] = (int) $window['class_id'];
                }
            }
        }

        if ($includeSearch) {
            $search = trim((string) ($request['search']['value'] ?? ''));
            if ($search !== '') {
                $like = '%' . $search . '%';
                $where[] = '(student.name LIKE ? OR sp.student_code LIKE ? OR subject.name LIKE ? OR scheme.name LIKE ? OR component.name LIKE ? OR class_entry.name LIKE ?)';
                array_push($params, $like, $like, $like, $like, $like, $like);
            }
        }

        return [$where, $params];
    }

    private function baseFromSql(): string
    {
        return "FROM student_marks sm
            JOIN users student ON student.id = sm.student_id
            LEFT JOIN student_profiles sp ON sp.user_id = student.id
            JOIN assessment_schemes scheme ON scheme.id = sm.scheme_id
            JOIN assessment_components component ON component.id = sm.component_id
            JOIN subjects subject ON subject.id = sm.subject_id
            LEFT JOIN grades grade ON grade.id = COALESCE(sm.grade_id, scheme.grade_id)
            LEFT JOIN stages stage ON stage.id = COALESCE(grade.stage_id, scheme.stage_id)
            LEFT JOIN classes class_entry ON class_entry.id = sm.class_id_at_entry
            LEFT JOIN academic_weeks week ON week.id = sm.week_id
            LEFT JOIN users recorder ON recorder.id = sm.recorded_by
            LEFT JOIN users reviewer ON reviewer.id = sm.reviewed_by";
    }

    private function publishedCountSql(): string
    {
        if (!$this->tableExists('published_report_details') || !$this->tableExists('published_reports')) {
            return '0';
        }
        return "(SELECT COUNT(*) FROM published_report_details prd
            JOIN published_reports pr ON pr.id = prd.published_report_id
            WHERE pr.student_id = sm.student_id
              AND pr.academic_year_id = sm.academic_year_id
              AND prd.component_id = sm.component_id
              AND ((prd.week_id IS NULL AND sm.week_id IS NULL) OR prd.week_id = sm.week_id))";
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
