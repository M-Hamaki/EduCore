<?php

require_once __DIR__ . '/../src/Modules/Operations/Audit/AuditService.php';
require_once __DIR__ . '/AcademicYearWriteGuard.php';
require_once __DIR__ . '/AssessmentAnnualPolicyService.php';
require_once __DIR__ . '/AssessmentSchemeReadinessService.php';
require_once __DIR__ . '/UndoManager.php';

/**
 * خدمات مساعدة لمحرك الدرجات المرن.
 *
 * هذا الكلاس لا يستبدل واجهات الرصد القديمة بعد؛ هو أساس آمن للبناء التدريجي
 * فوق جداول assessment_* الجديدة.
 */
class AssessmentEngine
{
    public const STATUS_PRESENT = 'present';
    public const STATUS_ABSENT = 'absent';
    public const STATUS_EXCUSED_ABSENT = 'excused_absent';
    public const STATUS_EXEMPT = 'exempt';
    public const STATUS_EMPTY = 'empty';

    private PDO $db;

    public function __construct(PDO $db)
    {
        $this->db = $db;
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

    /**
     * تحويل إدخال المعلم إلى قيمة منظمة مع منع الحروف غير المسموحة.
     *
     * @return array{value:?float,status:string,label:string}
     */
    public static function normalizeMarkInput($raw, float $maxGrade, bool $excusedAbsenceEnabled = false, bool $absenceEnabled = true): array
    {
        $value = trim((string) $raw);
        if ($value === '') {
            return ['value' => null, 'status' => self::STATUS_EMPTY, 'label' => ''];
        }

        $lower = mb_strtolower($value, 'UTF-8');
        if ($value === 'غ' || $lower === 'abs') {
            if (!$absenceEnabled) {
                throw new InvalidArgumentException('هذا البند لا يسمح بتسجيل الغياب بدلا من الدرجة.');
            }
            return ['value' => null, 'status' => self::STATUS_ABSENT, 'label' => 'غ'];
        }

        if ($excusedAbsenceEnabled && in_array($lower, ['غ.ع', 'غ ع', 'excused', 'excused_abs', 'abs_excused'], true)) {
            return ['value' => null, 'status' => self::STATUS_EXCUSED_ABSENT, 'label' => 'غياب بعذر'];
        }

        if (!preg_match('/^\d+(\.\d{1,2})?$/', $value)) {
            $allowedText = $excusedAbsenceEnabled
                ? 'رقماً أو غ أو abs أو غ.ع للغياب بعذر'
                : 'رقماً أو غ أو abs فقط';
            throw new InvalidArgumentException('القيمة يجب أن تكون ' . $allowedText . '.');
        }

        $number = (float) $value;
        if ($number < 0) {
            throw new InvalidArgumentException('لا يمكن إدخال درجة سالبة.');
        }
        if ($number > $maxGrade) {
            throw new InvalidArgumentException('الدرجة أكبر من الدرجة الكبرى المحددة للبند.');
        }

        return ['value' => $number, 'status' => self::STATUS_PRESENT, 'label' => self::formatNumber($number)];
    }

    public static function roundValue(float $value, bool $enabled, string $mode): float
    {
        if (!$enabled || $mode === 'none') {
            return $value;
        }

        switch ($mode) {
            case 'nearest_half':
                return round($value * 2) / 2;
            case 'integer':
                return round($value);
            case 'two_decimals':
                return round($value, 2);
            default:
                return $value;
        }
    }

    public static function formatNumber(float $value): string
    {
        $formatted = number_format($value, 2, '.', '');
        return rtrim(rtrim($formatted, '0'), '.');
    }

    /**
     * قوالب أولية لتقليل تكرار إدخال بنود الدرجات.
     *
     * @return array<string,array{label:string,total_grade:float,components:array<int,array<string,mixed>>}>
     */
    public static function componentTemplates(): array
    {
        return [
            'primary_100' => [
                'label' => 'قالب ابتدائي 100 درجة',
                'total_grade' => 100.0,
                'components' => [
                    ['name' => 'امتحان شهر أول', 'component_type' => 'monthly', 'max_grade' => 5, 'sort_order' => 10],
                    ['name' => 'امتحان شهر ثاني', 'component_type' => 'monthly', 'max_grade' => 5, 'sort_order' => 20],
                    ['name' => 'كراسة النشاط', 'component_type' => 'activity', 'max_grade' => 5, 'is_weekly' => 1, 'counts_in_average' => 1, 'calculation_mode' => 'average_weeks', 'sort_order' => 30],
                    ['name' => 'كراسة الواجب', 'component_type' => 'activity', 'max_grade' => 5, 'is_weekly' => 1, 'counts_in_average' => 1, 'calculation_mode' => 'average_weeks', 'sort_order' => 40],
                    ['name' => 'سلوك ومواظبة', 'component_type' => 'behavior', 'max_grade' => 5, 'is_weekly' => 1, 'counts_in_average' => 1, 'calculation_mode' => 'average_weeks', 'sort_order' => 50],
                    ['name' => 'التقييم الأسبوعي', 'component_type' => 'weekly', 'max_grade' => 5, 'is_weekly' => 1, 'counts_in_average' => 1, 'calculation_mode' => 'average_weeks', 'sort_order' => 60],
                    ['name' => 'المهام الأدائية', 'component_type' => 'practical', 'max_grade' => 10, 'is_weekly' => 1, 'counts_in_average' => 1, 'calculation_mode' => 'average_weeks', 'sort_order' => 70],
                    ['name' => 'امتحان الفصل الدراسي', 'component_type' => 'final', 'max_grade' => 60, 'sort_order' => 80],
                ],
            ],
            'preparatory_100' => [
                'label' => 'قالب إعدادي 100 درجة',
                'total_grade' => 100.0,
                'components' => [
                    ['name' => 'امتحان شهر أول', 'component_type' => 'monthly', 'max_grade' => 15, 'sort_order' => 10],
                    ['name' => 'امتحان شهر ثاني', 'component_type' => 'monthly', 'max_grade' => 15, 'sort_order' => 20],
                    ['name' => 'كراسة الواجب', 'component_type' => 'activity', 'max_grade' => 10, 'is_weekly' => 1, 'counts_in_average' => 1, 'calculation_mode' => 'average_weeks', 'sort_order' => 30],
                    ['name' => 'سلوك ومواظبة', 'component_type' => 'behavior', 'max_grade' => 10, 'is_weekly' => 1, 'counts_in_average' => 1, 'calculation_mode' => 'average_weeks', 'sort_order' => 40],
                    ['name' => 'التقييم الأسبوعي', 'component_type' => 'weekly', 'max_grade' => 20, 'is_weekly' => 1, 'counts_in_average' => 1, 'calculation_mode' => 'average_weeks', 'sort_order' => 50],
                    ['name' => 'امتحان الفصل الدراسي', 'component_type' => 'final', 'max_grade' => 30, 'sort_order' => 60],
                ],
            ],
            'generic_80' => [
                'label' => 'قالب عام 80 درجة',
                'total_grade' => 80.0,
                'components' => [
                    ['name' => 'امتحان شهر أول', 'component_type' => 'monthly', 'max_grade' => 10, 'sort_order' => 10],
                    ['name' => 'امتحان شهر ثاني', 'component_type' => 'monthly', 'max_grade' => 10, 'sort_order' => 20],
                    ['name' => 'أعمال سنة أسبوعية', 'component_type' => 'weekly', 'max_grade' => 20, 'is_weekly' => 1, 'counts_in_average' => 1, 'calculation_mode' => 'average_weeks', 'sort_order' => 30],
                    ['name' => 'امتحان الفصل الدراسي', 'component_type' => 'final', 'max_grade' => 40, 'sort_order' => 40],
                ],
            ],
        ];
    }

    public function applyComponentTemplate(int $schemeId, string $templateKey, bool $replaceExisting = false, float $gradeScale = 1.0): int
    {
        $templates = self::componentTemplates();
        if (!isset($templates[$templateKey])) {
            throw new InvalidArgumentException('قالب البنود غير معروف.');
        }
        if ($gradeScale <= 0) {
            throw new InvalidArgumentException('معامل تحجيم درجات القالب غير صحيح.');
        }

        $startedTransaction = !$this->db->inTransaction();
        if ($startedTransaction) {
            $this->db->beginTransaction();
        }

        try {
            $this->assertSchemeWritable($schemeId);
            $existingStmt = $this->db->prepare('SELECT * FROM assessment_components WHERE scheme_id = ? ORDER BY id' . $this->forUpdateClause());
            $existingStmt->execute([$schemeId]);
            $beforeComponents = $existingStmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
            $existingCount = count($beforeComponents);
            if ($existingCount > 0 && !$replaceExisting) {
                throw new InvalidArgumentException('هذه الخطة تحتوي بنودا بالفعل. فعّل خيار استبدال البنود إذا أردت تطبيق القالب.');
            }
            $beforeRules = $this->fetchWeekRulesForComponents(array_column($beforeComponents, 'id'), true);
            if ($replaceExisting && $existingCount > 0) {
                $this->assertComponentsHaveNoOperationalDependencies(array_column($beforeComponents, 'id'));
                $deleteStmt = $this->db->prepare('DELETE FROM assessment_components WHERE scheme_id = ?');
                $deleteStmt->execute([$schemeId]);
            }

            $insert = $this->db->prepare("INSERT INTO assessment_components
                (scheme_id, name, component_type, max_grade, is_weekly, repeat_per_week,
                 counts_in_average, counts_in_total, visible_to_student, accepts_absence,
                 accepts_excused_absence, sort_order, calculation_mode, is_active)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 1)");

            foreach ($templates[$templateKey]['components'] as $component) {
                $insert->execute([
                    $schemeId,
                    $component['name'],
                    $component['component_type'] ?? 'custom',
                    round((float) $component['max_grade'] * $gradeScale, 2),
                    !empty($component['is_weekly']) ? 1 : 0,
                    !empty($component['repeat_per_week']) ? 1 : 0,
                    !empty($component['counts_in_average']) ? 1 : 0,
                    array_key_exists('counts_in_total', $component) ? (int) $component['counts_in_total'] : 1,
                    array_key_exists('visible_to_student', $component) ? (int) $component['visible_to_student'] : 1,
                    array_key_exists('accepts_absence', $component) ? (int) $component['accepts_absence'] : 1,
                    array_key_exists('accepts_excused_absence', $component) ? (int) $component['accepts_excused_absence'] : 1,
                    (int) ($component['sort_order'] ?? 0),
                    $component['calculation_mode'] ?? 'direct',
                ]);
            }

            $afterStmt = $this->db->prepare('SELECT * FROM assessment_components WHERE scheme_id = ? ORDER BY id');
            $afterStmt->execute([$schemeId]);
            $afterComponents = $afterStmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
            $afterRules = $this->fetchWeekRulesForComponents(array_column($afterComponents, 'id'), false);
            $batchId = UndoManager::newBatchId();
            $this->auditStateTransition(
                'assessment_component_template', $schemeId, 'قالب بنود الخطة #' . $schemeId,
                ['assessment_component_week_rules' => $beforeRules, 'assessment_components' => $beforeComponents],
                ['assessment_components' => $afterComponents, 'assessment_component_week_rules' => $afterRules],
                ['summary' => 'تطبيق قالب بنود تقييم', 'template_key' => $templateKey, 'replace_existing' => $replaceExisting],
                $batchId
            );
            (new AssessmentSchemeReadinessService($this->db))->refresh($schemeId, $batchId, true);

            if ($startedTransaction) {
                $this->db->commit();
            }
        } catch (Throwable $e) {
            if ($startedTransaction && $this->db->inTransaction()) {
                $this->db->rollBack();
            }
            throw $e;
        }

        return count($templates[$templateKey]['components']);
    }

    /**
     * جلب الأسابيع الدراسية الداخلة في المتوسط لخطة/ترم.
     *
     * @return array<int,array<string,mixed>>
     */
    public function getStudyWeeks(int $termId): array
    {
        $stmt = $this->db->prepare("SELECT *
            FROM academic_weeks
            WHERE term_id = ? AND week_type = 'study' AND counts_for_average = 1
            ORDER BY week_order, start_date");
        $stmt->execute([$termId]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    }

    /**
     * نسخ بنود خطة إلى خطة أخرى مع قواعد الأسابيع المرتبطة بكل بند.
     */
    public function copySchemeComponents(
        int $sourceSchemeId,
        int $targetSchemeId,
        float $gradeScale = 1.0,
        ?string $batchId = null,
        bool $copyWeekRules = true
    ): int
    {
        if ($gradeScale <= 0) {
            throw new InvalidArgumentException('معامل تحجيم الدرجات غير صحيح.');
        }

        $startedTransaction = !$this->db->inTransaction();
        if ($startedTransaction) $this->db->beginTransaction();
        try {
        $this->assertSchemeWritable($targetSchemeId);
        $targetStmt = $this->db->prepare('SELECT * FROM assessment_components WHERE scheme_id = ? ORDER BY id' . $this->forUpdateClause());
        $targetStmt->execute([$targetSchemeId]);
        $beforeComponents = $targetStmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
        $beforeRules = $this->fetchWeekRulesForComponents(array_column($beforeComponents, 'id'), true);
        $stmt = $this->db->prepare("SELECT *
            FROM assessment_components
            WHERE scheme_id = ?
            ORDER BY parent_component_id IS NOT NULL, sort_order, id");
        $stmt->execute([$sourceSchemeId]);
        $components = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

        $idMap = [];
        $insert = $this->db->prepare("INSERT INTO assessment_components
            (scheme_id, parent_component_id, name, component_type, max_grade, is_weekly,
             repeat_per_week, counts_in_average, counts_in_total, visible_to_student,
             accepts_absence, accepts_excused_absence, sort_order, calculation_mode, is_active)
            VALUES
            (:scheme_id, :parent_component_id, :name, :component_type, :max_grade, :is_weekly,
             :repeat_per_week, :counts_in_average, :counts_in_total, :visible_to_student,
             :accepts_absence, :accepts_excused_absence, :sort_order, :calculation_mode, :is_active)");
        $copyWeekRulesEnabled = $copyWeekRules && $this->tableExists('assessment_component_week_rules');
        $weekRuleSelect = $copyWeekRulesEnabled
            ? $this->db->prepare('SELECT week_id, is_included, max_grade_override FROM assessment_component_week_rules WHERE component_id = ? ORDER BY week_id')
            : null;
        $weekRuleInsert = $copyWeekRulesEnabled
            ? $this->db->prepare('INSERT INTO assessment_component_week_rules (component_id, week_id, is_included, max_grade_override) VALUES (?, ?, ?, ?)')
            : null;

        foreach ($components as $component) {
            $oldParent = $component['parent_component_id'] ? (int) $component['parent_component_id'] : null;
            $newParent = $oldParent && isset($idMap[$oldParent]) ? $idMap[$oldParent] : null;

            $insert->execute([
                ':scheme_id' => $targetSchemeId,
                ':parent_component_id' => $newParent,
                ':name' => $component['name'],
                ':component_type' => $component['component_type'],
                ':max_grade' => round((float) $component['max_grade'] * $gradeScale, 2),
                ':is_weekly' => $component['is_weekly'],
                ':repeat_per_week' => $component['repeat_per_week'],
                ':counts_in_average' => $component['counts_in_average'],
                ':counts_in_total' => $component['counts_in_total'],
                ':visible_to_student' => $component['visible_to_student'],
                ':accepts_absence' => $component['accepts_absence'],
                ':accepts_excused_absence' => $component['accepts_excused_absence'],
                ':sort_order' => $component['sort_order'],
                ':calculation_mode' => $component['calculation_mode'],
                ':is_active' => $component['is_active'],
            ]);

            $oldComponentId = (int) $component['id'];
            $newComponentId = (int) $this->db->lastInsertId();
            $idMap[$oldComponentId] = $newComponentId;

            if ($weekRuleSelect && $weekRuleInsert) {
                $weekRuleSelect->execute([$oldComponentId]);
                foreach ($weekRuleSelect->fetchAll(PDO::FETCH_ASSOC) ?: [] as $rule) {
                    $weekRuleInsert->execute([
                        $newComponentId,
                        (int) $rule['week_id'],
                        (int) $rule['is_included'],
                        $rule['max_grade_override'] !== null ? round((float) $rule['max_grade_override'] * $gradeScale, 2) : null,
                    ]);
                }
            }
        }
        $targetStmt = $this->db->prepare('SELECT * FROM assessment_components WHERE scheme_id = ? ORDER BY id');
        $targetStmt->execute([$targetSchemeId]);
        $afterComponents = $targetStmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
        $afterRules = $this->fetchWeekRulesForComponents(array_column($afterComponents, 'id'), false);
        $batchId = $batchId ?: UndoManager::newBatchId();
        $this->auditStateTransition(
            'assessment_component_copy', $targetSchemeId, 'نسخ بنود إلى الخطة #' . $targetSchemeId,
            ['assessment_components' => $beforeComponents, 'assessment_component_week_rules' => $beforeRules],
            ['assessment_components' => $afterComponents, 'assessment_component_week_rules' => $afterRules],
            [
                'summary' => $copyWeekRulesEnabled
                    ? 'نسخ بنود وقواعد أسابيع بين خطط التقييم'
                    : 'نسخ بنود التقييم دون قواعد الأسابيع',
                'source_scheme_id' => $sourceSchemeId,
                'copied_count' => count($components),
                'copied_week_rules' => $copyWeekRulesEnabled,
            ],
            $batchId
        );
        (new AssessmentSchemeReadinessService($this->db))->refresh($targetSchemeId, $batchId, true);
        if ($startedTransaction) $this->db->commit();
        return count($components);
        } catch (Throwable $e) {
            if ($startedTransaction && $this->db->inTransaction()) $this->db->rollBack();
            throw $e;
        }
    }

    /**
     * بناء Snapshot لتقرير طالب من الدرجات المرصودة في نطاق نافذة تقرير.
     *
     * @return array<string,mixed>
     */
    public function buildStudentReportSnapshot(int $reportWindowId, int $studentId): array
    {
        $window = $this->fetchReportWindow($reportWindowId);
        if (!$window) {
            throw new InvalidArgumentException('نافذة التقرير غير موجودة.');
        }

        $studentStmt = $this->db->prepare("SELECT u.id, u.name, u.username, c.name AS class_name, g.grade_name
            FROM users u
            JOIN student_enrollments se
              ON se.student_id = u.id
             AND se.academic_year_id = ?
             AND se.enrollment_status = 'enrolled'
            LEFT JOIN classes c ON c.id = se.class_id
            LEFT JOIN grades g ON g.id = COALESCE(se.grade_id, c.grade_id)
            WHERE u.id = ?
              AND u.role = 'student'
              AND u.status = 'active'
              AND u.deleted_at IS NULL
            LIMIT 1");
        $studentStmt->execute([(int) $window['academic_year_id'], $studentId]);
        $student = $studentStmt->fetch(PDO::FETCH_ASSOC);
        if (!$student) {
            throw new InvalidArgumentException('الطالب غير موجود.');
        }

        $hasItemsStmt = $this->db->prepare('SELECT COUNT(*) FROM report_window_items WHERE report_window_id = ? AND include_item = 1');
        $hasItemsStmt->execute([$reportWindowId]);
        $hasItems = (int) $hasItemsStmt->fetchColumn() > 0;

        $where = [
            'sm.student_id = ?',
            'sm.academic_year_id = ?',
        ];
        $params = [$studentId, (int) $window['academic_year_id']];

        if (!empty($window['term_id'])) {
            $where[] = 'sm.term_id = ?';
            $params[] = (int) $window['term_id'];
        }
        if (!empty($window['date_from'])) {
            $where[] = '(w.id IS NULL OR w.end_date >= ?)';
            $params[] = $window['date_from'];
        }
        if (!empty($window['date_to'])) {
            $where[] = '(w.id IS NULL OR w.start_date <= ?)';
            $params[] = $window['date_to'];
        }
        if ($hasItems) {
            $where[] = "EXISTS (
                SELECT 1 FROM report_window_items rwi
                WHERE rwi.report_window_id = ?
                  AND rwi.include_item = 1
                  AND (rwi.scheme_id IS NULL OR rwi.scheme_id = sm.scheme_id)
                  AND (rwi.component_id IS NULL OR rwi.component_id = sm.component_id)
                  AND (rwi.week_id IS NULL OR (sm.week_id IS NOT NULL AND rwi.week_id = sm.week_id))
                  AND (rwi.subject_id IS NULL OR rwi.subject_id = sm.subject_id)
            )";
            $params[] = $reportWindowId;
        }

        $sql = "SELECT sm.*, s.name AS subject_name, sch.name AS scheme_name,
                sch.rounding_enabled, sch.rounding_mode, sch.rounding_scope,
                sch.normal_absence_policy, sch.excused_absence_policy,
                sch.annual_result_enabled, sch.first_term_weight, sch.second_term_weight, sch.total_grade AS scheme_total_grade,
                ac.name AS component_name, COALESCE(cwr.max_grade_override, ac.max_grade) AS max_grade,
                ac.counts_in_total, ac.visible_to_student,
                ac.counts_in_average, ac.calculation_mode,
                ac.sort_order AS component_order, w.name AS week_name, w.week_order,
                entry_class.name AS class_name_at_entry,
                t.name AS term_name, t.term_order
            FROM student_marks sm
            JOIN subjects s ON s.id = sm.subject_id
            JOIN assessment_schemes sch ON sch.id = sm.scheme_id
            JOIN assessment_components ac ON ac.id = sm.component_id
            LEFT JOIN assessment_component_week_rules cwr ON cwr.component_id = sm.component_id AND cwr.week_id = sm.week_id
            JOIN academic_terms t ON t.id = sm.term_id
            LEFT JOIN academic_weeks w ON w.id = sm.week_id
            LEFT JOIN classes entry_class ON entry_class.id = sm.class_id_at_entry
            WHERE " . implode(' AND ', $where) . "
              AND ac.visible_to_student = 1
              AND (sm.week_id IS NULL OR cwr.is_included IS NULL OR cwr.is_included = 1)
              AND NOT EXISTS (
                  SELECT 1
                  FROM assessment_windows awr
                  WHERE awr.scheme_id = sm.scheme_id
                    AND awr.component_id = sm.component_id
                    AND ((awr.week_id IS NULL AND sm.week_id IS NULL) OR awr.week_id = sm.week_id)
                    AND (awr.class_id IS NULL OR awr.class_id = sm.class_id_at_entry)
                    AND awr.requires_review = 1
                    AND sm.review_status <> 'approved'
              )
            ORDER BY s.name, ac.sort_order, ac.id, w.week_order";
        $stmt = $this->db->prepare($sql);
        $stmt->execute($params);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

        $subjects = [];
        $details = [];
        $contributions = [];
        $annualPolicyService = new AssessmentAnnualPolicyService($this->db);
        $annualPolicyCache = [];
        $sort = 0;

        foreach ($rows as $row) {
            $subjectId = (int) $row['subject_id'];
            if (!isset($subjects[$subjectId])) {
                $subjects[$subjectId] = [
                    'subject_id' => $subjectId,
                    'subject_name' => $row['subject_name'],
                    'items' => [],
                    'total' => 0.0,
                    'max_total' => 0.0,
                ];
            }

            $valueLabel = $this->formatMarkLabel($row);
            $label = (string) $row['component_name'];
            if (!empty($row['week_name'])) {
                $label .= ' - ' . $row['week_name'];
            }

            $numericValue = $row['value'] !== null ? (float) $row['value'] : null;
            $maxGrade = $row['max_grade'] !== null ? (float) $row['max_grade'] : null;

            $item = [
                'subject_id' => $subjectId,
                'scheme_id' => (int) $row['scheme_id'],
                'component_id' => (int) $row['component_id'],
                'week_id' => $row['week_id'] !== null ? (int) $row['week_id'] : null,
                'class_id_at_entry' => $row['class_id_at_entry'] !== null ? (int) $row['class_id_at_entry'] : null,
                'class_name_at_entry' => $row['class_name_at_entry'],
                'label' => $label,
                'value_label' => $valueLabel,
                'numeric_value' => $numericValue,
                'max_grade' => $maxGrade,
                'status' => $row['mark_status'],
                'note' => $row['note'],
                'term_id' => (int) $row['term_id'],
                'term_name' => $row['term_name'],
                'term_order' => (int) $row['term_order'],
                'sort_order' => $sort++,
            ];
            $subjects[$subjectId]['items'][] = $item;
            $details[] = $item + ['subject_name' => $row['subject_name']];

            if (empty($row['counts_in_total']) || $maxGrade === null) {
                continue;
            }

            $markStatus = (string) ($row['mark_status'] ?? self::STATUS_EMPTY);
            $calculationValue = null;
            $includeInContribution = false;
            if ($markStatus === self::STATUS_PRESENT && $numericValue !== null) {
                $calculationValue = $numericValue;
                $includeInContribution = true;
            } elseif ($markStatus === self::STATUS_ABSENT) {
                $absencePolicy = (string) ($row['normal_absence_policy'] ?? 'zero');
                if ($absencePolicy === 'zero') {
                    $calculationValue = 0.0;
                    $includeInContribution = true;
                }
            } elseif ($markStatus === self::STATUS_EXCUSED_ABSENT) {
                $absencePolicy = (string) ($row['excused_absence_policy'] ?? 'exclude');
                if ($absencePolicy === 'zero') {
                    $calculationValue = 0.0;
                    $includeInContribution = true;
                }
            }

            if (!$includeInContribution) {
                continue;
            }

            $usesAverage = ($row['calculation_mode'] ?? '') === 'average_weeks' || !empty($row['counts_in_average']);
            $contributionKey = $usesAverage
                ? 'avg:' . (int) $row['term_id'] . ':' . (int) $row['scheme_id'] . ':' . (int) $row['component_id'] . ':' . $subjectId
                : 'direct:' . (int) $row['id'];

            $schemeId = (int) $row['scheme_id'];
            if (!array_key_exists($schemeId, $annualPolicyCache)) {
                $annualPolicyCache[$schemeId] = $annualPolicyService->policyForScheme(
                    $schemeId,
                    !empty($row['annual_result_enabled']),
                    (float) ($row['first_term_weight'] ?? 50),
                    (float) ($row['second_term_weight'] ?? 50)
                );
            }
            $annualPolicy = $annualPolicyCache[$schemeId];

            if (!isset($contributions[$contributionKey])) {
                $contributions[$contributionKey] = [
                    'type' => $usesAverage ? 'average' : 'direct',
                    'subject_id' => $subjectId,
                    'subject_name' => $row['subject_name'],
                    'scheme_id' => $schemeId,
                    'component_id' => (int) $row['component_id'],
                    'term_id' => (int) $row['term_id'],
                    'term_name' => $row['term_name'],
                    'term_order' => (int) $row['term_order'],
                    'label' => $usesAverage ? ('متوسط ' . $row['component_name']) : $label,
                    'sum' => 0.0,
                    'count' => 0,
                    'max_grade' => $maxGrade,
                    'rounding_enabled' => !empty($row['rounding_enabled']),
                    'rounding_mode' => (string) ($row['rounding_mode'] ?? 'none'),
                    'rounding_scope' => (string) ($row['rounding_scope'] ?? 'total'),
                    'annual_result_enabled' => !empty($row['annual_result_enabled']),
                    'first_term_weight' => (float) ($row['first_term_weight'] ?? 50),
                    'second_term_weight' => (float) ($row['second_term_weight'] ?? 50),
                    'scheme_total_grade' => (float) ($row['scheme_total_grade'] ?? 100),
                    'annual_policy' => $annualPolicy,
                    'sort_order' => $sort++,
                ];
            }

            $contributions[$contributionKey]['sum'] += (float) $calculationValue;
            $contributions[$contributionKey]['count']++;
        }

        $totalGrade = 0.0;
        $maxTotal = 0.0;
        $totalRounding = null;
        $termTotals = [];
        foreach ($contributions as $contribution) {
            $value = null;
            if ($contribution['type'] === 'average') {
                $value = $contribution['count'] > 0
                    ? ((float) $contribution['sum'] / (int) $contribution['count'])
                    : 0.0;
            } elseif ($contribution['count'] > 0) {
                $value = (float) $contribution['sum'];
            } else {
                $value = 0.0;
            }

            if ($contribution['rounding_enabled'] && in_array($contribution['rounding_scope'], ['components', 'both'], true)) {
                $value = self::roundValue($value, true, $contribution['rounding_mode']);
            }

            $subjectId = (int) $contribution['subject_id'];
            if (!isset($subjects[$subjectId])) {
                continue;
            }
            $subjects[$subjectId]['total'] += $value;
            $subjects[$subjectId]['max_total'] += (float) $contribution['max_grade'];
            $totalGrade += $value;
            $maxTotal += (float) $contribution['max_grade'];

            $annualPolicy = is_array($contribution['annual_policy'] ?? null)
                ? $contribution['annual_policy']
                : [];
            $policyIdentity = ($annualPolicy['source'] ?? 'legacy') === 'family'
                && !empty($annualPolicy['family_id'])
                ? 'family:' . (int) $annualPolicy['family_id']
                : 'legacy';
            $termKey = $subjectId . ':' . $policyIdentity . ':' . (int) $contribution['term_id'];
            if (!isset($termTotals[$termKey])) {
                $termTotals[$termKey] = [
                    'subject_id' => $subjectId,
                    'subject_name' => $contribution['subject_name'],
                    'term_id' => (int) $contribution['term_id'],
                    'term_name' => $contribution['term_name'],
                    'term_order' => (int) $contribution['term_order'],
                    'total' => 0.0,
                    'max_total' => 0.0,
                    'annual_result_enabled' => !empty($contribution['annual_result_enabled']),
                    'first_term_weight' => (float) $contribution['first_term_weight'],
                    'second_term_weight' => (float) $contribution['second_term_weight'],
                    'scheme_total_grade' => (float) $contribution['scheme_total_grade'],
                    'annual_policy' => $annualPolicy,
                    'policy_identity' => $policyIdentity,
                ];
            }
            $termTotals[$termKey]['total'] += $value;
            $termTotals[$termKey]['max_total'] += (float) $contribution['max_grade'];

            if ($contribution['rounding_enabled'] && in_array($contribution['rounding_scope'], ['total', 'both'], true)) {
                $totalRounding = [
                    'mode' => $contribution['rounding_mode'],
                ];
            }

            if ($contribution['type'] === 'average') {
                $summaryItem = [
                    'subject_id' => $subjectId,
                    'scheme_id' => (int) $contribution['scheme_id'],
                    'component_id' => (int) $contribution['component_id'],
                    'week_id' => null,
                    'term_id' => (int) $contribution['term_id'],
                    'term_name' => $contribution['term_name'],
                    'term_order' => (int) $contribution['term_order'],
                    'label' => $contribution['label'],
                    'value_label' => self::formatNumber($value),
                    'numeric_value' => $value,
                    'max_grade' => (float) $contribution['max_grade'],
                    'status' => 'summary',
                    'sort_order' => $contribution['sort_order'],
                ];
                $subjects[$subjectId]['items'][] = $summaryItem;
                $details[] = $summaryItem + ['subject_name' => $contribution['subject_name']];
            }
        }

        if ($totalRounding !== null) {
            $totalGrade = self::roundValue($totalGrade, true, $totalRounding['mode']);
        }
        $percentage = $maxTotal > 0 ? round(($totalGrade / $maxTotal) * 100, 2) : null;
        $annualSummary = $this->buildAnnualSummary($termTotals, (string) $window['report_type']);
        $annualComplete = !empty($annualSummary)
            && !array_filter($annualSummary, static fn(array $summary): bool => empty($summary['is_complete']));
        if ($annualComplete) {
            $totalGrade = array_sum(array_column($annualSummary, 'annual_value'));
            $maxTotal = array_sum(array_column($annualSummary, 'annual_max'));
            $percentage = $maxTotal > 0 ? round(($totalGrade / $maxTotal) * 100, 2) : null;
        }

        return [
            'window' => [
                'id' => (int) $window['id'],
                'name' => $window['name'],
                'report_type' => $window['report_type'],
                'date_from' => $window['date_from'],
                'date_to' => $window['date_to'],
                'include_details' => !empty($window['include_details']),
                'include_absence' => !empty($window['include_absence']),
                'include_teacher_notes' => !empty($window['include_teacher_notes']),
            ],
            'student' => [
                'id' => (int) $student['id'],
                'name' => $student['name'],
                'username' => $student['username'],
                'class_name' => $student['class_name'],
                'grade_name' => $student['grade_name'],
            ],
            'subjects' => array_values($subjects),
            'details' => $details,
            'annual_summary' => $annualSummary,
            'annual_complete' => $annualComplete,
            'total_grade' => $totalGrade,
            'max_total' => $maxTotal,
            'percentage' => $percentage,
            'generated_at' => date('Y-m-d H:i:s'),
        ];
    }

    /**
     * @return array{published:int,skipped:int}
     */
    public function publishReportWindow(int $reportWindowId, ?int $classId, int $publishedBy): array
    {
        $window = $this->fetchReportWindow($reportWindowId);
        if (!$window) {
            throw new InvalidArgumentException('نافذة التقرير غير موجودة.');
        }
        $pendingReview = $this->countPendingReviewMarksForReportWindow($reportWindowId, $classId);

        $studentIds = $this->fetchReportTargetStudentIds($window, $classId);

        $upsertReport = $this->db->prepare("INSERT INTO published_reports
            (report_window_id, student_id, academic_year_id, term_id, snapshot_json, total_grade, percentage, published_by)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?)
            ON DUPLICATE KEY UPDATE
                snapshot_json = VALUES(snapshot_json),
                total_grade = VALUES(total_grade),
                percentage = VALUES(percentage),
                published_by = VALUES(published_by),
                published_at = CURRENT_TIMESTAMP");
        $findReport = $this->db->prepare('SELECT id FROM published_reports WHERE report_window_id = ? AND student_id = ? LIMIT 1');
        $deleteDetails = $this->db->prepare('DELETE FROM published_report_details WHERE published_report_id = ?');
        $insertDetail = $this->db->prepare("INSERT INTO published_report_details
            (published_report_id, subject_id, scheme_id, component_id, week_id, class_id_at_entry, label, value_label, status, numeric_value, max_grade, class_name_at_entry, note, sort_order)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");

        $published = 0;
        $skipped = 0;
        $freezeOnPublish = !empty($window['freeze_on_publish']);
        $startedTransaction = !$this->db->inTransaction();
        if ($startedTransaction) {
            $this->db->beginTransaction();
        }

        try {
            $windowLock = $this->db->prepare('SELECT * FROM report_windows WHERE id = ? FOR UPDATE');
            $windowLock->execute([$reportWindowId]);
            $window = $windowLock->fetch(PDO::FETCH_ASSOC);
            if (!$window) throw new InvalidArgumentException('نافذة التقرير غير موجودة.');
            $this->assertAcademicYearWritable((int) ($window['academic_year_id'] ?? 0));
            $pendingReview = $this->countPendingReviewMarksForReportWindow($reportWindowId, $classId);
            $studentIds = $this->fetchReportTargetStudentIds($window, $classId);
            $beforeReports = $this->fetchPublishedReports($reportWindowId, $studentIds, true);
            $beforeDetails = $this->fetchPublishedReportDetails(array_column($beforeReports, 'id'), true);
            $beforeWindow = $window;
            foreach ($studentIds as $studentId) {
                $findReport->execute([$reportWindowId, $studentId]);
                $existingReportId = (int) $findReport->fetchColumn();
                if ($freezeOnPublish && $existingReportId > 0) {
                    $skipped++;
                    continue;
                }

                $snapshot = $this->buildStudentReportSnapshot($reportWindowId, $studentId);
                if (($window['report_type'] ?? '') === 'annual' && empty($snapshot['annual_complete'])) {
                    $skipped++;
                    continue;
                }
                if (empty($snapshot['details']) && empty($snapshot['annual_summary'])) {
                    continue;
                }
                if (empty($window['include_absence'])) {
                    $hideAbsence = static function (array $detail): bool {
                        return !in_array($detail['status'] ?? '', [self::STATUS_ABSENT, self::STATUS_EXCUSED_ABSENT], true);
                    };
                    $snapshot['details'] = array_values(array_filter($snapshot['details'], $hideAbsence));
                    foreach ($snapshot['subjects'] as &$subject) {
                        $subject['items'] = array_values(array_filter($subject['items'] ?? [], $hideAbsence));
                    }
                    unset($subject);
                }
                if (empty($window['include_teacher_notes'])) {
                    foreach ($snapshot['details'] as &$detail) {
                        $detail['note'] = null;
                    }
                    unset($detail);
                    foreach ($snapshot['subjects'] as &$subject) {
                        foreach ($subject['items'] as &$item) {
                            $item['note'] = null;
                        }
                        unset($item);
                    }
                    unset($subject);
                }
                $includeDetails = !empty($window['include_details']);
                $detailsToPublish = $includeDetails ? ($snapshot['details'] ?? []) : [];
                if (!$includeDetails) {
                    $snapshot['details'] = [];
                    foreach ($snapshot['subjects'] as &$subject) {
                        $subject['items'] = [];
                    }
                    unset($subject);
                }

                $upsertReport->execute([
                    $reportWindowId,
                    $studentId,
                    (int) $window['academic_year_id'],
                    !empty($window['term_id']) ? (int) $window['term_id'] : null,
                    json_encode($snapshot, JSON_UNESCAPED_UNICODE),
                    (float) $snapshot['total_grade'],
                    $snapshot['percentage'],
                    $publishedBy ?: null,
                ]);

                $findReport->execute([$reportWindowId, $studentId]);
                $publishedReportId = (int) $findReport->fetchColumn();
                $deleteDetails->execute([$publishedReportId]);
                foreach ($detailsToPublish as $detail) {
                    $insertDetail->execute([
                        $publishedReportId,
                        $detail['subject_id'],
                        $detail['scheme_id'],
                        $detail['component_id'],
                        $detail['week_id'],
                        $detail['class_id_at_entry'] ?? null,
                        $detail['label'],
                        $detail['value_label'],
                        $detail['status'] ?? null,
                        $detail['numeric_value'],
                        $detail['max_grade'],
                        $detail['class_name_at_entry'] ?? null,
                        $detail['note'] ?? null,
                        $detail['sort_order'],
                    ]);
                }
                $published++;
            }

            $stmt = $this->db->prepare('UPDATE report_windows SET is_published = 1, published_at = NOW(), hidden_at = NULL WHERE id = ?');
            $stmt->execute([$reportWindowId]);

            $afterReports = $this->fetchPublishedReports($reportWindowId, $studentIds, false);
            $afterDetails = $this->fetchPublishedReportDetails(array_column($afterReports, 'id'), false);
            $afterWindow = $this->fetchReportWindow($reportWindowId);
            if (!$afterWindow) throw new RuntimeException('Published report window could not be reloaded.');
            $this->auditStateTransition(
                'published_report_window', $reportWindowId, (string)($window['name'] ?? ('نافذة تقرير #' . $reportWindowId)),
                ['published_report_details' => $beforeDetails, 'published_reports' => $beforeReports, 'report_windows' => [$beforeWindow]],
                ['published_reports' => $afterReports, 'published_report_details' => $afterDetails, 'report_windows' => [$afterWindow]],
                ['summary' => 'نشر تقارير نافذة تقييم', 'published_count' => $published, 'skipped_count' => $skipped, 'class_id' => $classId]
            );

            if ($startedTransaction) {
                $this->db->commit();
            }
        } catch (Throwable $e) {
            if ($startedTransaction && $this->db->inTransaction()) {
                $this->db->rollBack();
            }
            throw $e;
        }

        return [
            'published' => $published,
            'skipped' => $skipped,
            'pending_review' => $pendingReview,
        ];
    }

    /**
     * Remove every student snapshot for a report window and return it to an unpublished state.
     *
     * @return array{deleted_reports:int,deleted_details:int,batch_id:?string}
     */
    public function unpublishReportWindow(int $reportWindowId): array
    {
        if ($reportWindowId <= 0) {
            throw new InvalidArgumentException('نافذة التقرير غير موجودة.');
        }

        $startedTransaction = !$this->db->inTransaction();
        if ($startedTransaction) {
            $this->db->beginTransaction();
        }

        try {
            $windowStmt = $this->db->prepare(
                'SELECT * FROM report_windows WHERE id = ?' . $this->forUpdateClause()
            );
            $windowStmt->execute([$reportWindowId]);
            $beforeWindow = $windowStmt->fetch(PDO::FETCH_ASSOC);
            if (!$beforeWindow) {
                throw new InvalidArgumentException('نافذة التقرير غير موجودة.');
            }
            $this->assertAcademicYearWritable((int) ($beforeWindow['academic_year_id'] ?? 0));

            $reportStmt = $this->db->prepare(
                'SELECT * FROM published_reports WHERE report_window_id = ? ORDER BY id' . $this->forUpdateClause()
            );
            $reportStmt->execute([$reportWindowId]);
            $beforeReports = $reportStmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
            if (!$beforeReports) {
                throw new RuntimeException('لا توجد نسخ منشورة لهذه النافذة.');
            }

            $beforeDetails = $this->fetchPublishedReportDetails(array_column($beforeReports, 'id'), true);
            $reportIds = array_map('intval', array_column($beforeReports, 'id'));
            $placeholders = implode(',', array_fill(0, count($reportIds), '?'));

            $deleteDetails = $this->db->prepare(
                "DELETE FROM published_report_details WHERE published_report_id IN ($placeholders)"
            );
            $deleteDetails->execute($reportIds);
            if ($deleteDetails->rowCount() !== count($beforeDetails)) {
                throw new RuntimeException('لم يكتمل حذف تفاصيل النسخ المنشورة.');
            }

            $deleteReports = $this->db->prepare(
                "DELETE FROM published_reports WHERE id IN ($placeholders)"
            );
            $deleteReports->execute($reportIds);
            if ($deleteReports->rowCount() !== count($beforeReports)) {
                throw new RuntimeException('لم يكتمل حذف نسخ الطلاب المنشورة.');
            }

            $this->db->prepare(
                'UPDATE report_windows SET is_published = 0, published_at = NULL, hidden_at = NOW() WHERE id = ?'
            )->execute([$reportWindowId]);
            $afterWindow = $this->fetchReportWindow($reportWindowId);
            if (!$afterWindow) {
                throw new RuntimeException('تعذر إعادة تحميل نافذة التقرير بعد إلغاء النشر.');
            }

            $batchId = $this->db->getAttribute(PDO::ATTR_DRIVER_NAME) === 'mysql'
                ? UndoManager::newBatchId()
                : null;
            $this->auditStateTransition(
                'published_report_window',
                $reportWindowId,
                (string) ($beforeWindow['name'] ?? ('نافذة تقرير #' . $reportWindowId)),
                [
                    'published_report_details' => $beforeDetails,
                    'published_reports' => $beforeReports,
                    'report_windows' => [$beforeWindow],
                ],
                ['report_windows' => [$afterWindow]],
                [
                    'summary' => 'إلغاء نشر تقرير وحذف نسخ الطلاب',
                    'deleted_reports' => count($beforeReports),
                    'deleted_details' => count($beforeDetails),
                ],
                $batchId
            );

            if ($startedTransaction) {
                $this->db->commit();
            }

            return [
                'deleted_reports' => count($beforeReports),
                'deleted_details' => count($beforeDetails),
                'batch_id' => $batchId,
            ];
        } catch (Throwable $e) {
            if ($startedTransaction && $this->db->inTransaction()) {
                $this->db->rollBack();
            }
            throw $e;
        }
    }

    /**
     * @return array{target_students:int,publishable:int,empty:int,frozen_existing:int,annual_incomplete:int,pending_review:int}
     */
    public function getReportWindowPublishReadiness(int $reportWindowId, ?int $classId = null): array
    {
        $window = $this->fetchReportWindow($reportWindowId);
        if (!$window) {
            throw new InvalidArgumentException('نافذة التقرير غير موجودة.');
        }

        $studentIds = $this->fetchReportTargetStudentIds($window, $classId);
        $findReport = $this->db->prepare('SELECT id FROM published_reports WHERE report_window_id = ? AND student_id = ? LIMIT 1');
        $freezeOnPublish = !empty($window['freeze_on_publish']);
        $publishable = 0;
        $empty = 0;
        $frozenExisting = 0;
        $annualIncomplete = 0;

        foreach ($studentIds as $studentId) {
            $findReport->execute([$reportWindowId, $studentId]);
            $existingReportId = (int) $findReport->fetchColumn();
            if ($freezeOnPublish && $existingReportId > 0) {
                $frozenExisting++;
                continue;
            }

            $snapshot = $this->buildStudentReportSnapshot($reportWindowId, $studentId);
            if (($window['report_type'] ?? '') === 'annual' && empty($snapshot['annual_complete'])) {
                $annualIncomplete++;
                continue;
            }
            if (empty($snapshot['details']) && empty($snapshot['annual_summary'])) {
                $empty++;
                continue;
            }
            $publishable++;
        }

        return [
            'target_students' => count($studentIds),
            'publishable' => $publishable,
            'empty' => $empty,
            'frozen_existing' => $frozenExisting,
            'annual_incomplete' => $annualIncomplete,
            'pending_review' => $this->countPendingReviewMarksForReportWindow($reportWindowId, $classId),
        ];
    }

    /**
     * @param array<string,mixed> $window
     * @return int[]
     */
    private function fetchReportTargetStudentIds(array $window, ?int $classId = null): array
    {
        $studentsSql = "SELECT u.id
            FROM student_enrollments se
            JOIN users u ON u.id = se.student_id
            WHERE se.academic_year_id = ? AND se.enrollment_status = 'enrolled'
              AND u.role = 'student' AND u.status = 'active'
              AND u.deleted_at IS NULL";
        $params = [(int) $window['academic_year_id']];
        if ($classId !== null && $classId > 0) {
            $studentsSql .= ' AND se.class_id = ?';
            $params[] = $classId;
        }
        $studentsSql .= ' ORDER BY u.name';
        $studentsStmt = $this->db->prepare($studentsSql);
        $studentsStmt->execute($params);
        return array_map('intval', $studentsStmt->fetchAll(PDO::FETCH_COLUMN) ?: []);
    }

    public function countPendingReviewMarksForReportWindow(int $reportWindowId, ?int $classId = null): int
    {
        $window = $this->fetchReportWindow($reportWindowId);
        if (!$window) {
            throw new InvalidArgumentException('نافذة التقرير غير موجودة.');
        }

        $hasItemsStmt = $this->db->prepare('SELECT COUNT(*) FROM report_window_items WHERE report_window_id = ? AND include_item = 1');
        $hasItemsStmt->execute([$reportWindowId]);
        $hasItems = (int) $hasItemsStmt->fetchColumn() > 0;

        $where = [
            'sm.academic_year_id = ?',
            'se.enrollment_status = ?',
            "u.role = 'student'",
            "u.status = 'active'",
            'u.deleted_at IS NULL',
            'awr.requires_review = 1',
            "sm.review_status <> 'approved'",
        ];
        $params = [(int) $window['academic_year_id'], 'enrolled'];

        if (!empty($window['term_id'])) {
            $where[] = 'sm.term_id = ?';
            $params[] = (int) $window['term_id'];
        }
        if ($classId !== null && $classId > 0) {
            $where[] = 'se.class_id = ?';
            $params[] = $classId;
        }
        if (!empty($window['date_from'])) {
            $where[] = '(w.id IS NULL OR w.end_date >= ?)';
            $params[] = $window['date_from'];
        }
        if (!empty($window['date_to'])) {
            $where[] = '(w.id IS NULL OR w.start_date <= ?)';
            $params[] = $window['date_to'];
        }
        if ($hasItems) {
            $where[] = "EXISTS (
                SELECT 1 FROM report_window_items rwi
                WHERE rwi.report_window_id = ?
                  AND rwi.include_item = 1
                  AND (rwi.scheme_id IS NULL OR rwi.scheme_id = sm.scheme_id)
                  AND (rwi.component_id IS NULL OR rwi.component_id = sm.component_id)
                  AND (rwi.week_id IS NULL OR (sm.week_id IS NOT NULL AND rwi.week_id = sm.week_id))
                  AND (rwi.subject_id IS NULL OR rwi.subject_id = sm.subject_id)
            )";
            $params[] = $reportWindowId;
        }

        $sql = "SELECT COUNT(DISTINCT sm.id)
            FROM student_marks sm
            JOIN student_enrollments se
              ON se.student_id = sm.student_id
             AND se.academic_year_id = sm.academic_year_id
            JOIN users u ON u.id = sm.student_id
            LEFT JOIN academic_weeks w ON w.id = sm.week_id
            JOIN assessment_windows awr
              ON awr.scheme_id = sm.scheme_id
             AND awr.component_id = sm.component_id
             AND ((awr.week_id IS NULL AND sm.week_id IS NULL) OR awr.week_id = sm.week_id)
             AND (awr.class_id IS NULL OR awr.class_id = sm.class_id_at_entry)
            WHERE " . implode(' AND ', $where);
        $stmt = $this->db->prepare($sql);
        $stmt->execute($params);
        return (int) $stmt->fetchColumn();
    }

    /**
     * يحسب ملخص نهاية العام من إجماليات الترمين عند تفعيل ذلك في الخطة.
     *
     * @param array<string,array<string,mixed>> $termTotals
     * @return array<int,array<string,mixed>>
     */
    private function buildAnnualSummary(array $termTotals, string $reportType): array
    {
        if ($reportType !== 'annual' || empty($termTotals)) {
            return [];
        }

        $byPolicy = [];
        foreach ($termTotals as $term) {
            $policy = is_array($term['annual_policy'] ?? null) ? $term['annual_policy'] : [];
            if (empty($policy['enabled'])) {
                continue;
            }
            $subjectId = (int) $term['subject_id'];
            $policyIdentity = (string) ($term['policy_identity'] ?? 'legacy');
            $summaryKey = $subjectId . ':' . $policyIdentity;
            if (!isset($byPolicy[$summaryKey])) {
                $byPolicy[$summaryKey] = [
                    'subject_id' => $subjectId,
                    'subject_name' => $term['subject_name'],
                    'scheme_total_grade' => (float) $term['scheme_total_grade'],
                    'first_term_weight' => (float) ($term['first_term_weight'] ?? 50),
                    'second_term_weight' => (float) ($term['second_term_weight'] ?? 50),
                    'annual_policy' => $policy,
                    'terms' => [],
                ];
            }
            $termKey = ($policy['source'] ?? 'legacy') === 'family'
                ? (int) $term['term_id']
                : (int) $term['term_order'];
            $byPolicy[$summaryKey]['terms'][$termKey] = [
                'term_id' => (int) $term['term_id'],
                'term_name' => $term['term_name'],
                'total' => (float) $term['total'],
                'max_total' => (float) $term['max_total'],
                'percentage' => (float) $term['max_total'] > 0 ? round(((float) $term['total'] / (float) $term['max_total']) * 100, 2) : null,
            ];
        }

        $summary = [];
        foreach ($byPolicy as $subject) {
            $policy = $subject['annual_policy'];
            $weights = ($policy['source'] ?? 'legacy') === 'family'
                ? (array) ($policy['weights_by_term_id'] ?? [])
                : (array) ($policy['weights_by_term_order'] ?? []);
            $weights = array_filter(
                $weights,
                static fn($weight): bool => (float) $weight > 0
            );
            $annualPercentage = 0.0;
            $missingTerms = [];
            foreach ($weights as $termKey => $weight) {
                $termKey = (int) $termKey;
                $term = $subject['terms'][$termKey] ?? null;
                if (!is_array($term) || $term['percentage'] === null) {
                    $missingTerms[] = is_array($term)
                        ? (string) $term['term_name']
                        : 'الترم #' . $termKey;
                    continue;
                }
                $annualPercentage += ((float) $term['percentage'] * ((float) $weight / 100));
            }

            $annualMax = (float) $subject['scheme_total_grade'];
            $isComplete = !empty($policy['valid']) && $weights !== [] && $missingTerms === [];
            $annualValue = $isComplete ? ($annualPercentage / 100) * $annualMax : null;
            $summary[] = [
                'subject_id' => (int) $subject['subject_id'],
                'subject_name' => $subject['subject_name'],
                'terms' => $subject['terms'],
                'weights' => $weights,
                'policy_source' => (string) ($policy['source'] ?? 'legacy'),
                'is_complete' => $isComplete,
                'missing_terms' => $missingTerms,
                'annual_percentage' => $isComplete ? round($annualPercentage, 2) : null,
                'annual_value' => $annualValue !== null ? round($annualValue, 2) : null,
                'annual_max' => $annualMax,
                'first_term_weight' => (float) $subject['first_term_weight'],
                'second_term_weight' => (float) $subject['second_term_weight'],
            ];
        }

        return $summary;
    }

    public function userHasPermission(int $userId, string $roleName, string $permissionKey, string $scopeType = 'global', ?int $scopeId = null): bool
    {
        $allowedKeys = ['delete_mark', 'edit_locked_mark', 'publish_report', 'reopen_window', 'review_marks'];
        $allowedScopes = ['global', 'subject', 'grade', 'class', 'scheme'];
        if (!in_array($permissionKey, $allowedKeys, true) || !in_array($scopeType, $allowedScopes, true)) {
            return false;
        }

        $stmt = $this->db->prepare("SELECT is_allowed
            FROM assessment_permissions
            WHERE permission_key = ?
              AND (user_id = ? OR (user_id IS NULL AND role_name = ?))
              AND (
                    scope_type = 'global'
                    OR (scope_type = ? AND scope_id = ?)
                  )
            ORDER BY user_id IS NOT NULL DESC, scope_type = ? DESC, id DESC
            LIMIT 1");
        $stmt->execute([$permissionKey, $userId, $roleName, $scopeType, $scopeId, $scopeType]);
        $value = $stmt->fetchColumn();
        return $value !== false && (int) $value === 1;
    }

    public function userHasAnyPermissionRole(int $userId, array $roleNames, string $permissionKey, string $scopeType = 'global', ?int $scopeId = null): bool
    {
        $normalizedRoles = [];
        foreach ($roleNames as $roleName) {
            $roleName = trim((string) $roleName);
            if ($roleName !== '') {
                $normalizedRoles[$roleName] = true;
            }
        }

        foreach (array_keys($normalizedRoles) as $roleName) {
            if ($this->userHasPermission($userId, $roleName, $permissionKey, $scopeType, $scopeId)) {
                return true;
            }
        }

        return false;
    }

    public function syncStudentLocksFromEnrollments(int $academicYearId, ?int $lockedBy = null): int
    {
        if ($academicYearId <= 0) {
            throw new InvalidArgumentException('العام الدراسي غير صحيح.');
        }

        $startedTransaction = !$this->db->inTransaction();
        if ($startedTransaction) $this->db->beginTransaction();
        try {
        $this->assertAcademicYearWritable($academicYearId);
        $beforeStmt = $this->db->prepare('SELECT * FROM assessment_student_locks WHERE academic_year_id = ? ORDER BY id' . $this->forUpdateClause());
        $beforeStmt->execute([$academicYearId]);
        $beforeRows = $beforeStmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
        $deleteStmt = $this->db->prepare("DELETE asl
            FROM assessment_student_locks asl
            LEFT JOIN student_enrollments se
              ON se.student_id = asl.student_id
             AND se.academic_year_id = asl.academic_year_id
            WHERE asl.academic_year_id = ?
              AND asl.lock_reason IN ('graduated', 'transferred', 'discontinued')
              AND (se.student_id IS NULL OR NOT (
                    se.academic_status = 'graduated'
                    OR se.enrollment_status IN ('graduated', 'transferred', 'discontinued', 'withdrawn')
              ))");
        $deleteStmt->execute([$academicYearId]);
        $deleted = $deleteStmt->rowCount();

        $stmt = $this->db->prepare("INSERT INTO assessment_student_locks
            (student_id, academic_year_id, lock_reason, locked_by, notes)
            SELECT se.student_id, se.academic_year_id,
                   CASE
                       WHEN se.academic_status = 'graduated' OR se.enrollment_status = 'graduated' THEN 'graduated'
                       WHEN se.enrollment_status = 'transferred' THEN 'transferred'
                       ELSE 'discontinued'
                   END,
                   ?,
                   CASE WHEN se.academic_status = 'graduated' OR se.enrollment_status = 'graduated'
                        THEN 'قفل تلقائي بسبب تخرج الطالب'
                        WHEN se.enrollment_status = 'transferred'
                        THEN 'قفل تلقائي بسبب نقل الطالب من المدرسة'
                        ELSE 'قفل تلقائي بسبب انقطاع الطالب'
                   END
             FROM student_enrollments se
             WHERE se.academic_year_id = ?
               AND (
                    se.academic_status = 'graduated'
                    OR se.enrollment_status IN ('graduated', 'transferred', 'discontinued', 'withdrawn')
               )
            ON DUPLICATE KEY UPDATE
                lock_reason = VALUES(lock_reason),
                locked_by = COALESCE(assessment_student_locks.locked_by, VALUES(locked_by)),
                notes = VALUES(notes)");
        $stmt->execute([$lockedBy, $academicYearId]);
        $affected = $stmt->rowCount() + $deleted;
        $afterStmt = $this->db->prepare('SELECT * FROM assessment_student_locks WHERE academic_year_id = ? ORDER BY id');
        $afterStmt->execute([$academicYearId]);
        $afterRows = $afterStmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
        $this->auditStateTransition(
            'assessment_student_lock_sync', $academicYearId, 'أقفال طلاب العام #' . $academicYearId,
            ['assessment_student_locks' => $beforeRows], ['assessment_student_locks' => $afterRows],
            ['summary' => 'مزامنة أقفال التقييم من حالات التسجيل', 'affected_count' => $affected]
        );
        if ($startedTransaction) $this->db->commit();
        return $affected;
        } catch (Throwable $e) {
            if ($startedTransaction && $this->db->inTransaction()) $this->db->rollBack();
            throw $e;
        }
    }

    public function isStudentLocked(int $studentId, int $academicYearId): bool
    {
        $stmt = $this->db->prepare('SELECT 1 FROM assessment_student_locks WHERE student_id = ? AND academic_year_id = ? LIMIT 1');
        $stmt->execute([$studentId, $academicYearId]);
        return (bool) $stmt->fetchColumn();
    }

    public function getLockedStudentIds(array $studentIds, int $academicYearId): array
    {
        $studentIds = array_values(array_filter(array_map('intval', $studentIds)));
        if (empty($studentIds) || $academicYearId <= 0) {
            return [];
        }
        $placeholders = implode(',', array_fill(0, count($studentIds), '?'));
        $params = $studentIds;
        $params[] = $academicYearId;
        $stmt = $this->db->prepare("SELECT student_id FROM assessment_student_locks
            WHERE student_id IN ($placeholders) AND academic_year_id = ?");
        $stmt->execute($params);
        return array_map('intval', $stmt->fetchAll(PDO::FETCH_COLUMN) ?: []);
    }

    private function fetchReportWindow(int $reportWindowId): ?array
    {
        $stmt = $this->db->prepare('SELECT * FROM report_windows WHERE id = ? LIMIT 1');
        $stmt->execute([$reportWindowId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row ?: null;
    }

    private function fetchWeekRulesForComponents(array $componentIds, bool $lock): array
    {
        $ids = array_values(array_filter(array_map('intval', $componentIds), static fn(int $id): bool => $id > 0));
        if (!$ids || !$this->tableExists('assessment_component_week_rules')) return [];
        $placeholders = implode(',', array_fill(0, count($ids), '?'));
        $sql = "SELECT * FROM assessment_component_week_rules WHERE component_id IN ($placeholders) ORDER BY id";
        if ($lock) $sql .= $this->forUpdateClause();
        $stmt = $this->db->prepare($sql);
        $stmt->execute($ids);
        return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    }

    private function fetchPublishedReports(int $windowId, array $studentIds, bool $lock): array
    {
        $ids = array_values(array_filter(array_map('intval', $studentIds), static fn(int $id): bool => $id > 0));
        if (!$ids) return [];
        $placeholders = implode(',', array_fill(0, count($ids), '?'));
        $sql = "SELECT * FROM published_reports WHERE report_window_id = ? AND student_id IN ($placeholders) ORDER BY id";
        if ($lock) $sql .= $this->forUpdateClause();
        $stmt = $this->db->prepare($sql);
        $stmt->execute(array_merge([$windowId], $ids));
        return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    }

    private function fetchPublishedReportDetails(array $reportIds, bool $lock): array
    {
        $ids = array_values(array_filter(array_map('intval', $reportIds), static fn(int $id): bool => $id > 0));
        if (!$ids) return [];
        $placeholders = implode(',', array_fill(0, count($ids), '?'));
        $sql = "SELECT * FROM published_report_details WHERE published_report_id IN ($placeholders) ORDER BY id";
        if ($lock) $sql .= $this->forUpdateClause();
        $stmt = $this->db->prepare($sql);
        $stmt->execute($ids);
        return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    }

    private function auditStateTransition(
        string $entityType,
        $recordId,
        string $name,
        array $beforeSets,
        array $afterSets,
        array $details,
        ?string $batchId = null
    ): void {
        if ($this->db->getAttribute(PDO::ATTR_DRIVER_NAME) !== 'mysql') return;
        $beforeIndex = $this->indexStateRows($beforeSets);
        $afterIndex = $this->indexStateRows($afterSets);
        $deleted = [];
        $inserted = [];
        $updated = [];

        foreach ($beforeIndex as $key => $item) {
            if (!isset($afterIndex[$key])) {
                $deleted[] = [
                    'table' => $item['table'], 'record_id' => $item['row']['id'],
                    'snapshot' => $item['row'], 'description' => $details['summary'] ?? 'استبدال بيانات تقييم',
                ];
            }
        }
        foreach ($afterIndex as $key => $item) {
            if (!isset($beforeIndex[$key])) {
                $inserted[] = [
                    'table' => $item['table'], 'record_id' => $item['row']['id'],
                    'snapshot' => $item['row'], 'description' => $details['summary'] ?? 'استبدال بيانات تقييم',
                ];
            } elseif ($beforeIndex[$key]['row'] != $item['row']) {
                $updated[] = [
                    'table' => $item['table'], 'record_id' => $item['row']['id'],
                    'before' => $beforeIndex[$key]['row'], 'after' => $item['row'],
                    'description' => $details['summary'] ?? 'تحديث بيانات تقييم',
                ];
            }
        }

        if (!$deleted && !$inserted && !$updated) return;
        $batchId = $batchId ?: UndoManager::newBatchId();
        $audit = new \EduCore\Modules\Operations\Audit\AuditService($this->db);
        if ($deleted || $inserted) {
            $audit->recordReplacement($entityType, $recordId, $name, $deleted, $inserted, $details, $batchId);
        }
        if ($updated) {
            $audit->recordCompositeUpdate($entityType, $recordId, $name, $updated, $details, $batchId);
        }
    }

    private function indexStateRows(array $sets): array
    {
        $indexed = [];
        foreach ($sets as $table => $rows) {
            foreach ($rows as $row) {
                if (!is_array($row) || !isset($row['id'])) {
                    throw new RuntimeException('Assessment audit row is missing its primary identifier.');
                }
                $indexed[$table . ':' . (string)$row['id']] = ['table' => $table, 'row' => $row];
            }
        }
        return $indexed;
    }

    private function forUpdateClause(): string
    {
        return $this->db->getAttribute(PDO::ATTR_DRIVER_NAME) === 'mysql' ? ' FOR UPDATE' : '';
    }

    private function formatMarkLabel(array $mark): string
    {
        $status = (string) ($mark['mark_status'] ?? self::STATUS_EMPTY);
        if ($status === self::STATUS_ABSENT) {
            return 'غ';
        }
        if ($status === self::STATUS_EXCUSED_ABSENT) {
            return 'غياب بعذر';
        }
        if ($status === self::STATUS_EXEMPT) {
            return 'معفى';
        }
        if ($status === self::STATUS_EMPTY || $mark['value'] === null) {
            return '';
        }
        return self::formatNumber((float) $mark['value']);
    }

    private function assertSchemeWritable(int $schemeId): void
    {
        if (!$this->tableExists('assessment_schemes')) {
            return;
        }
        $stmt = $this->db->prepare('SELECT academic_year_id, status FROM assessment_schemes WHERE id = ? LIMIT 1' . $this->forUpdateClause());
        $stmt->execute([$schemeId]);
        $scheme = $stmt->fetch(PDO::FETCH_ASSOC);
        $yearId = (int) ($scheme['academic_year_id'] ?? 0);
        if (!$scheme || $yearId <= 0) {
            throw new RuntimeException('خطة التقييم غير موجودة أو غير مرتبطة بعام دراسي.');
        }
        if ((string) ($scheme['status'] ?? '') === 'active') {
            throw new RuntimeException('لا يمكن تعديل بنود خطة نشطة. عطّل الخطة أولا.');
        }
        $this->assertAcademicYearWritable($yearId);
    }

    /** @param list<int|string> $componentIds */
    private function assertComponentsHaveNoOperationalDependencies(array $componentIds): void
    {
        $ids = array_values(array_unique(array_filter(
            array_map('intval', $componentIds),
            static fn(int $id): bool => $id > 0
        )));
        if ($ids === []) {
            return;
        }
        $placeholders = implode(',', array_fill(0, count($ids), '?'));
        foreach ([
            'assessment_windows' => 'component_id',
            'student_marks' => 'component_id',
            'report_window_items' => 'component_id',
            'published_report_details' => 'component_id',
        ] as $table => $column) {
            if (!$this->tableExists($table)) {
                continue;
            }
            $stmt = $this->db->prepare("SELECT COUNT(*) FROM `{$table}` WHERE `{$column}` IN ({$placeholders})");
            $stmt->execute($ids);
            if ((int) $stmt->fetchColumn() > 0) {
                throw new RuntimeException('لا يمكن استبدال بنود استُخدمت في نوافذ رصد أو درجات أو تقارير. أنشئ خطة بديلة للحفاظ على التاريخ.');
            }
        }
    }

    private function assertAcademicYearWritable(int $academicYearId): void
    {
        if ($academicYearId <= 0) {
            throw new InvalidArgumentException('العام الدراسي غير صحيح.');
        }
        if (!$this->tableExists('academic_years')) {
            return;
        }
        (new AcademicYearWriteGuard($this->db))->assertWritable($academicYearId);
    }
}
