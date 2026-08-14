<?php

require_once dirname(__DIR__) . '/classes/AssessmentEngine.php';
require_once dirname(__DIR__) . '/classes/AssessmentAnnualPolicyService.php';
require_once dirname(__DIR__) . '/classes/AssessmentSchemeScopeResolver.php';

function assessment_check_exception(callable $fn): bool
{
    try {
        $fn();
    } catch (InvalidArgumentException $e) {
        return true;
    }
    return false;
}

class AssessmentEnginePermissionProbe extends AssessmentEngine
{
    private array $allowedRoles;

    public function __construct(array $allowedRoles)
    {
        $this->allowedRoles = $allowedRoles;
    }

    public function userHasPermission(int $userId, string $roleName, string $permissionKey, string $scopeType = 'global', ?int $scopeId = null): bool
    {
        return in_array($roleName, $this->allowedRoles, true);
    }
}

function assessment_annual_summary(array $termTotals, string $reportType): array
{
    $ref = new ReflectionClass(AssessmentEngine::class);
    $engine = $ref->newInstanceWithoutConstructor();
    $method = $ref->getMethod('buildAnnualSummary');
    $method->setAccessible(true);
    return $method->invoke($engine, $termTotals, $reportType);
}

function assessment_copy_scheme_components_result(): array
{
    $db = new PDO('sqlite::memory:');
    $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $db->exec("CREATE TABLE assessment_components (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        scheme_id INTEGER NOT NULL,
        parent_component_id INTEGER NULL,
        name TEXT NOT NULL,
        component_type TEXT NOT NULL,
        max_grade REAL NOT NULL,
        is_weekly INTEGER NOT NULL,
        repeat_per_week INTEGER NOT NULL,
        counts_in_average INTEGER NOT NULL,
        counts_in_total INTEGER NOT NULL,
        visible_to_student INTEGER NOT NULL,
        accepts_absence INTEGER NOT NULL,
        accepts_excused_absence INTEGER NOT NULL,
        sort_order INTEGER NOT NULL,
        calculation_mode TEXT NOT NULL,
        is_active INTEGER NOT NULL
    )");
    $db->exec("CREATE TABLE assessment_component_week_rules (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        component_id INTEGER NOT NULL,
        week_id INTEGER NOT NULL,
        is_included INTEGER NOT NULL,
        max_grade_override REAL NULL
    )");
    $db->exec("INSERT INTO assessment_components
        (scheme_id, parent_component_id, name, component_type, max_grade, is_weekly, repeat_per_week,
         counts_in_average, counts_in_total, visible_to_student, accepts_absence, accepts_excused_absence,
         sort_order, calculation_mode, is_active)
        VALUES
        (1, NULL, 'الأعمال الأسبوعية', 'weekly_average', 10, 1, 1, 1, 1, 1, 1, 0, 1, 'average_weeks', 1),
        (1, 1, 'التقييم الأسبوعي', 'weekly', 5, 1, 1, 1, 1, 1, 1, 0, 2, 'direct', 1)");
    $db->exec("INSERT INTO assessment_component_week_rules
        (component_id, week_id, is_included, max_grade_override)
        VALUES (2, 7, 0, 4.5)");

    $copiedCount = (new AssessmentEngine($db))->copySchemeComponents(1, 2);
    $copiedComponents = $db->query("SELECT id, parent_component_id, name, max_grade FROM assessment_components WHERE scheme_id = 2 ORDER BY sort_order, id")
        ->fetchAll(PDO::FETCH_ASSOC);
    $copiedRule = $db->query("SELECT r.week_id, r.is_included, r.max_grade_override, c.parent_component_id
        FROM assessment_component_week_rules r
        JOIN assessment_components c ON c.id = r.component_id
        WHERE c.scheme_id = 2
        LIMIT 1")->fetch(PDO::FETCH_ASSOC) ?: [];
    $scaledCopiedCount = (new AssessmentEngine($db))->copySchemeComponents(1, 3, 0.5);
    $scaledComponents = $db->query("SELECT id, parent_component_id, name, max_grade FROM assessment_components WHERE scheme_id = 3 ORDER BY sort_order, id")
        ->fetchAll(PDO::FETCH_ASSOC);
    $scaledRule = $db->query("SELECT r.week_id, r.is_included, r.max_grade_override
        FROM assessment_component_week_rules r
        JOIN assessment_components c ON c.id = r.component_id
        WHERE c.scheme_id = 3
        LIMIT 1")->fetch(PDO::FETCH_ASSOC) ?: [];

    return [
        'copied_count' => $copiedCount,
        'components' => $copiedComponents,
        'rule' => $copiedRule,
        'scaled_count' => $scaledCopiedCount,
        'scaled_components' => $scaledComponents,
        'scaled_rule' => $scaledRule,
    ];
}

function assessment_scaled_template_result(): array
{
    $db = new PDO('sqlite::memory:');
    $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $db->exec("CREATE TABLE assessment_components (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        scheme_id INTEGER NOT NULL,
        parent_component_id INTEGER NULL,
        name TEXT NOT NULL,
        component_type TEXT NOT NULL,
        max_grade REAL NOT NULL,
        is_weekly INTEGER NOT NULL,
        repeat_per_week INTEGER NOT NULL,
        counts_in_average INTEGER NOT NULL,
        counts_in_total INTEGER NOT NULL,
        visible_to_student INTEGER NOT NULL,
        accepts_absence INTEGER NOT NULL,
        accepts_excused_absence INTEGER NOT NULL,
        sort_order INTEGER NOT NULL,
        calculation_mode TEXT NOT NULL,
        is_active INTEGER NOT NULL
    )");

    $createdCount = (new AssessmentEngine($db))->applyComponentTemplate(10, 'primary_100', false, 0.8);
    $total = (float) $db->query('SELECT COALESCE(SUM(max_grade), 0) FROM assessment_components WHERE scheme_id = 10')
        ->fetchColumn();
    $final = (float) $db->query("SELECT max_grade FROM assessment_components WHERE scheme_id = 10 AND name = 'امتحان الفصل الدراسي' LIMIT 1")
        ->fetchColumn();

    return [
        'created_count' => $createdCount,
        'total' => $total,
        'final' => $final,
    ];
}

function assessment_report_snapshot_enrollment_result(): array
{
    $db = new PDO('sqlite::memory:');
    $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $db->exec("CREATE TABLE report_windows (
        id INTEGER PRIMARY KEY,
        academic_year_id INTEGER NOT NULL,
        term_id INTEGER NULL,
        name TEXT NOT NULL,
        report_type TEXT NOT NULL,
        date_from TEXT NULL,
        date_to TEXT NULL,
        include_details INTEGER NOT NULL,
        include_absence INTEGER NOT NULL,
        include_teacher_notes INTEGER NOT NULL
    )");
    $db->exec("CREATE TABLE users (
        id INTEGER PRIMARY KEY,
        name TEXT NOT NULL,
        username TEXT NULL,
        role TEXT NOT NULL,
        status TEXT NOT NULL,
        deleted_at TEXT NULL
    )");
    $db->exec("CREATE TABLE student_enrollments (
        student_id INTEGER NOT NULL,
        academic_year_id INTEGER NOT NULL,
        enrollment_status TEXT NOT NULL,
        class_id INTEGER NULL,
        grade_id INTEGER NULL
    )");
    $db->exec("CREATE TABLE classes (id INTEGER PRIMARY KEY, name TEXT NOT NULL, grade_id INTEGER NULL)");
    $db->exec("CREATE TABLE grades (id INTEGER PRIMARY KEY, grade_name TEXT NOT NULL)");
    $db->exec("CREATE TABLE report_window_items (
        id INTEGER PRIMARY KEY,
        report_window_id INTEGER NOT NULL,
        include_item INTEGER NOT NULL,
        scheme_id INTEGER NULL,
        component_id INTEGER NULL,
        week_id INTEGER NULL,
        subject_id INTEGER NULL
    )");
    $db->exec("CREATE TABLE subjects (id INTEGER PRIMARY KEY, name TEXT NOT NULL)");
    $db->exec("CREATE TABLE academic_terms (id INTEGER PRIMARY KEY, name TEXT NOT NULL, term_order INTEGER NOT NULL)");
    $db->exec("CREATE TABLE academic_weeks (
        id INTEGER PRIMARY KEY,
        name TEXT NOT NULL,
        week_order INTEGER NOT NULL,
        start_date TEXT NULL,
        end_date TEXT NULL
    )");
    $db->exec("CREATE TABLE assessment_schemes (
        id INTEGER PRIMARY KEY,
        name TEXT NOT NULL,
        rounding_enabled INTEGER NOT NULL,
        rounding_mode TEXT NOT NULL,
        rounding_scope TEXT NOT NULL,
        normal_absence_policy TEXT NOT NULL,
        excused_absence_policy TEXT NOT NULL,
        annual_result_enabled INTEGER NOT NULL,
        first_term_weight REAL NOT NULL,
        second_term_weight REAL NOT NULL,
        total_grade REAL NOT NULL
    )");
    $db->exec("CREATE TABLE assessment_components (
        id INTEGER PRIMARY KEY,
        name TEXT NOT NULL,
        max_grade REAL NOT NULL,
        counts_in_total INTEGER NOT NULL,
        visible_to_student INTEGER NOT NULL,
        counts_in_average INTEGER NOT NULL,
        calculation_mode TEXT NOT NULL,
        sort_order INTEGER NOT NULL
    )");
    $db->exec("CREATE TABLE assessment_component_week_rules (
        component_id INTEGER NOT NULL,
        week_id INTEGER NULL,
        is_included INTEGER NULL,
        max_grade_override REAL NULL
    )");
    $db->exec("CREATE TABLE assessment_windows (
        id INTEGER PRIMARY KEY,
        scheme_id INTEGER NOT NULL,
        component_id INTEGER NOT NULL,
        week_id INTEGER NULL,
        class_id INTEGER NULL,
        requires_review INTEGER NOT NULL
    )");
    $db->exec("CREATE TABLE student_marks (
        id INTEGER PRIMARY KEY,
        student_id INTEGER NOT NULL,
        scheme_id INTEGER NOT NULL,
        component_id INTEGER NOT NULL,
        week_id INTEGER NULL,
        academic_year_id INTEGER NOT NULL,
        term_id INTEGER NOT NULL,
        subject_id INTEGER NOT NULL,
        grade_id INTEGER NULL,
        class_id_at_entry INTEGER NULL,
        value REAL NULL,
        mark_status TEXT NOT NULL,
        note TEXT NULL,
        review_status TEXT NOT NULL
    )");

    $db->exec("INSERT INTO report_windows VALUES (1, 2026, 1, 'تقرير شهر', 'monthly', NULL, NULL, 1, 1, 1)");
    $db->exec("INSERT INTO users VALUES
        (10, 'طالب مقيد', 's10', 'student', 'active', NULL),
        (11, 'طالب غير مقيد', 's11', 'student', 'active', NULL),
        (12, 'طالب غير نشط', 's12', 'student', 'inactive', NULL)");
    $db->exec("INSERT INTO grades VALUES (3, 'الصف الثالث')");
    $db->exec("INSERT INTO classes VALUES (5, '3A', 3), (6, '3B', 3)");
    $db->exec("INSERT INTO student_enrollments VALUES
        (10, 2026, 'enrolled', 5, 3),
        (12, 2026, 'enrolled', 5, 3)");
    $db->exec("INSERT INTO subjects VALUES (7, 'اللغة العربية')");
    $db->exec("INSERT INTO academic_terms VALUES (1, 'الترم الأول', 1)");
    $db->exec("INSERT INTO assessment_schemes VALUES (2, 'عربي ثالث', 0, 'none', 'total', 'zero', 'exclude', 0, 50, 50, 100)");
    $db->exec("INSERT INTO assessment_components VALUES (4, 'امتحان شهر أول', 15, 1, 1, 0, 'direct', 1)");
    $db->exec("INSERT INTO student_marks VALUES (20, 10, 2, 4, NULL, 2026, 1, 7, 3, 6, 12, 'present', 'ممتاز', 'not_required')");

    $engine = new AssessmentEngine($db);
    $snapshot = $engine->buildStudentReportSnapshot(1, 10);

    return [
        'student_id' => (int) ($snapshot['student']['id'] ?? 0),
        'student_class' => (string) ($snapshot['student']['class_name'] ?? ''),
        'entry_class' => (string) (($snapshot['details'][0]['class_name_at_entry'] ?? '')),
        'details_count' => count($snapshot['details'] ?? []),
        'inactive_rejected' => assessment_check_exception(static function () use ($engine) {
            $engine->buildStudentReportSnapshot(1, 12);
        }),
        'unenrolled_rejected' => assessment_check_exception(static function () use ($engine) {
            $engine->buildStudentReportSnapshot(1, 11);
        }),
    ];
}

$present = AssessmentEngine::normalizeMarkInput('7.5', 10);
$empty = AssessmentEngine::normalizeMarkInput('', 10);
$absent = AssessmentEngine::normalizeMarkInput('abs', 10);
$arabicAbsent = AssessmentEngine::normalizeMarkInput('غ', 10);
$excused = AssessmentEngine::normalizeMarkInput('غ.ع', 10, true);
$permissionProbe = new AssessmentEnginePermissionProbe(['subject_supervisor']);
$copiedScheme = assessment_copy_scheme_components_result();
$scaledTemplate = assessment_scaled_template_result();
$snapshotEnrollment = assessment_report_snapshot_enrollment_result();
$annualSummary = assessment_annual_summary([
    [
        'annual_result_enabled' => 1,
        'annual_policy' => [
            'source' => 'legacy',
            'family_id' => null,
            'enabled' => true,
            'weights_by_term_id' => [],
            'weights_by_term_order' => [1 => 40, 2 => 60],
            'valid' => true,
        ],
        'policy_identity' => 'legacy',
        'subject_id' => 10,
        'subject_name' => 'اللغة العربية',
        'scheme_total_grade' => 100,
        'first_term_weight' => 40,
        'second_term_weight' => 60,
        'term_order' => 1,
        'term_id' => 1,
        'term_name' => 'الترم الأول',
        'total' => 80,
        'max_total' => 100,
    ],
    [
        'annual_result_enabled' => 1,
        'annual_policy' => [
            'source' => 'legacy',
            'family_id' => null,
            'enabled' => true,
            'weights_by_term_id' => [],
            'weights_by_term_order' => [1 => 40, 2 => 60],
            'valid' => true,
        ],
        'policy_identity' => 'legacy',
        'subject_id' => 10,
        'subject_name' => 'اللغة العربية',
        'scheme_total_grade' => 100,
        'first_term_weight' => 40,
        'second_term_weight' => 60,
        'term_order' => 2,
        'term_id' => 2,
        'term_name' => 'الترم الثاني',
        'total' => 90,
        'max_total' => 100,
    ],
], 'annual');
$periodAnnualSummary = assessment_annual_summary([
    [
        'annual_result_enabled' => 1,
        'annual_policy' => [
            'source' => 'legacy',
            'family_id' => null,
            'enabled' => true,
            'weights_by_term_id' => [],
            'weights_by_term_order' => [1 => 50, 2 => 50],
            'valid' => true,
        ],
        'policy_identity' => 'legacy',
        'subject_id' => 10,
        'subject_name' => 'اللغة العربية',
        'scheme_total_grade' => 100,
        'first_term_weight' => 50,
        'second_term_weight' => 50,
        'term_order' => 1,
        'term_id' => 1,
        'term_name' => 'الترم الأول',
        'total' => 80,
        'max_total' => 100,
    ],
], 'period');
$threeTermAnnualSummary = assessment_annual_summary([
    [
        'subject_id' => 11, 'subject_name' => 'العلوم', 'scheme_total_grade' => 100,
        'term_order' => 1, 'term_id' => 11, 'term_name' => 'الترم الأول', 'total' => 70, 'max_total' => 100,
        'annual_policy' => ['source' => 'family', 'family_id' => 77, 'enabled' => true, 'weights_by_term_id' => [11 => 20, 12 => 30, 13 => 50], 'weights_by_term_order' => [], 'valid' => true],
        'policy_identity' => 'family:77',
    ],
    [
        'subject_id' => 11, 'subject_name' => 'العلوم', 'scheme_total_grade' => 100,
        'term_order' => 2, 'term_id' => 12, 'term_name' => 'الترم الثاني', 'total' => 80, 'max_total' => 100,
        'annual_policy' => ['source' => 'family', 'family_id' => 77, 'enabled' => true, 'weights_by_term_id' => [11 => 20, 12 => 30, 13 => 50], 'weights_by_term_order' => [], 'valid' => true],
        'policy_identity' => 'family:77',
    ],
    [
        'subject_id' => 11, 'subject_name' => 'العلوم', 'scheme_total_grade' => 100,
        'term_order' => 3, 'term_id' => 13, 'term_name' => 'الترم الثالث', 'total' => 90, 'max_total' => 100,
        'annual_policy' => ['source' => 'family', 'family_id' => 77, 'enabled' => true, 'weights_by_term_id' => [11 => 20, 12 => 30, 13 => 50], 'weights_by_term_order' => [], 'valid' => true],
        'policy_identity' => 'family:77',
    ],
], 'annual');
$incompleteAnnualSummary = assessment_annual_summary([
    [
        'subject_id' => 11, 'subject_name' => 'العلوم', 'scheme_total_grade' => 100,
        'term_order' => 1, 'term_id' => 11, 'term_name' => 'الترم الأول', 'total' => 70, 'max_total' => 100,
        'annual_policy' => ['source' => 'family', 'family_id' => 77, 'enabled' => true, 'weights_by_term_id' => [11 => 50, 12 => 50], 'weights_by_term_order' => [], 'valid' => true],
        'policy_identity' => 'family:77',
    ],
], 'annual');
$templates = AssessmentEngine::componentTemplates();
$templateTotals = [];
foreach ($templates as $key => $template) {
    $templateTotals[$key] = array_sum(array_map(static function ($component) {
        return (float) $component['max_grade'];
    }, $template['components']));
}

function assessment_scope_resolver_result(): array
{
    $db = new PDO('sqlite::memory:');
    $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $db->exec("CREATE TABLE assessment_schemes (id INTEGER PRIMARY KEY, family_id INTEGER NULL, academic_year_id INTEGER NOT NULL, subject_id INTEGER NOT NULL, term_id INTEGER NOT NULL, grade_id INTEGER NOT NULL, status TEXT NOT NULL, subject_assignment_id INTEGER NULL)");
    $db->exec('CREATE TABLE assessment_scheme_scopes (id INTEGER PRIMARY KEY, scheme_id INTEGER NOT NULL, grade_id INTEGER NOT NULL, class_id INTEGER NULL, scope_kind TEXT NOT NULL)');
    $db->exec('CREATE TABLE subject_grade_assignments (id INTEGER PRIMARY KEY, academic_year_id INTEGER NOT NULL, term_id INTEGER NULL, subject_id INTEGER NOT NULL, grade_id INTEGER NOT NULL, class_id INTEGER NULL, is_active INTEGER NOT NULL)');
    $db->exec("INSERT INTO assessment_schemes (id, family_id, academic_year_id, subject_id, term_id, grade_id, status) VALUES (1, NULL, 2025, 12, 1, 4, 'draft'), (2, NULL, 2025, 12, 1, 4, 'draft'), (3, 77, 2025, 12, 1, 4, 'draft')");
    $db->exec("INSERT INTO assessment_scheme_scopes (id, scheme_id, grade_id, class_id, scope_kind) VALUES (1, 1, 4, NULL, 'grade'), (2, 2, 4, 17, 'class')");
    $db->exec("INSERT INTO subject_grade_assignments (id, academic_year_id, term_id, subject_id, grade_id, class_id, is_active) VALUES (10, 2025, NULL, 12, 4, NULL, 1), (11, 2025, 1, 12, 4, 17, 1)");

    $resolver = new AssessmentSchemeScopeResolver($db);
    $db->beginTransaction();
    $rows = $resolver->scopesForScheme(1, true);
    $db->rollBack();

    return [
        'rows' => $rows,
        'whole_grade_covers_class' => $resolver->schemeCoversClass(1, 4, 17),
        'whole_grade_covers_global' => $resolver->schemeCoversClass(1, 4, null),
        'class_scope_covers_its_class' => $resolver->schemeCoversClass(2, 4, 17),
        'class_scope_does_not_cover_global' => !$resolver->schemeCoversClass(2, 4, null),
        'grouped_scheme_without_scope_fails_closed' => $resolver->scopesForScheme(3) === []
            && !$resolver->schemeCoversClass(3, 4, 17),
        'grade_link_dependencies' => $resolver->countSchemesDependentOnSubjectAssignment([
            'id' => 10, 'academic_year_id' => 2025, 'subject_id' => 12, 'term_id' => null, 'grade_id' => 4, 'class_id' => null,
        ]),
        'class_link_dependencies' => $resolver->countSchemesDependentOnSubjectAssignment([
            'id' => 11, 'academic_year_id' => 2025, 'subject_id' => 12, 'term_id' => 1, 'grade_id' => 4, 'class_id' => 17,
        ]),
    ];
}

function assessment_family_annual_policy_result(): array
{
    $db = new PDO('sqlite::memory:');
    $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $db->exec('CREATE TABLE assessment_schemes (id INTEGER PRIMARY KEY, family_id INTEGER NULL)');
    $db->exec('CREATE TABLE assessment_annual_policies (id INTEGER PRIMARY KEY, family_id INTEGER NOT NULL, is_enabled INTEGER NOT NULL)');
    $db->exec('CREATE TABLE assessment_annual_policy_terms (id INTEGER PRIMARY KEY, policy_id INTEGER NOT NULL, term_id INTEGER NOT NULL, weight REAL NOT NULL)');
    $db->exec('INSERT INTO assessment_schemes (id, family_id) VALUES (1, 77)');
    $db->exec('INSERT INTO assessment_annual_policies (id, family_id, is_enabled) VALUES (8, 77, 1)');
    $db->exec('INSERT INTO assessment_annual_policy_terms (id, policy_id, term_id, weight) VALUES (1, 8, 11, 20), (2, 8, 12, 30), (3, 8, 13, 50)');

    return (new AssessmentAnnualPolicyService($db))->policyForScheme(1);
}

function assessment_single_term_family_policy_result(): array
{
    $db = new PDO('sqlite::memory:');
    $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $db->exec('CREATE TABLE assessment_schemes (id INTEGER PRIMARY KEY, family_id INTEGER NULL)');
    $db->exec('CREATE TABLE assessment_annual_policies (id INTEGER PRIMARY KEY, family_id INTEGER NOT NULL, is_enabled INTEGER NOT NULL)');
    $db->exec('CREATE TABLE assessment_annual_policy_terms (id INTEGER PRIMARY KEY, policy_id INTEGER NOT NULL, term_id INTEGER NOT NULL, weight REAL NOT NULL)');
    $db->exec('INSERT INTO assessment_schemes (id, family_id) VALUES (1, 88)');
    $db->exec('INSERT INTO assessment_annual_policies (id, family_id, is_enabled) VALUES (9, 88, 1)');
    $db->exec('INSERT INTO assessment_annual_policy_terms (id, policy_id, term_id, weight) VALUES (1, 9, 21, 100), (2, 9, 22, 0)');

    return (new AssessmentAnnualPolicyService($db))->policyForScheme(1);
}

$scopeResolverResult = assessment_scope_resolver_result();
$familyAnnualPolicy = assessment_family_annual_policy_result();
$singleTermFamilyPolicy = assessment_single_term_family_policy_result();

$checks = [
    'numeric_value' => $present['status'] === AssessmentEngine::STATUS_PRESENT && $present['value'] === 7.5,
    'empty_value' => $empty['status'] === AssessmentEngine::STATUS_EMPTY && $empty['value'] === null,
    'abs_value' => $absent['status'] === AssessmentEngine::STATUS_ABSENT && $absent['label'] === 'غ',
    'arabic_abs_value' => $arabicAbsent['status'] === AssessmentEngine::STATUS_ABSENT,
    'excused_abs_value' => $excused['status'] === AssessmentEngine::STATUS_EXCUSED_ABSENT,
    'reject_letters' => assessment_check_exception(static function () {
        AssessmentEngine::normalizeMarkInput('abc', 10);
    }),
    'reject_over_max' => assessment_check_exception(static function () {
        AssessmentEngine::normalizeMarkInput('11', 10);
    }),
    'reject_abs_when_disabled' => assessment_check_exception(static function () {
        AssessmentEngine::normalizeMarkInput('غ', 10, false, false);
    }),
    'round_nearest_half' => AssessmentEngine::roundValue(8.26, true, 'nearest_half') === 8.5,
    'round_integer' => AssessmentEngine::roundValue(8.49, true, 'integer') === 8.0,
    'round_two_decimals' => AssessmentEngine::roundValue(8.456, true, 'two_decimals') === 8.46,
    'format_number' => AssessmentEngine::formatNumber(8.50) === '8.5',
    'primary_template_total' => isset($templateTotals['primary_100']) && abs($templateTotals['primary_100'] - 100.0) <= 0.001,
    'preparatory_template_total' => isset($templateTotals['preparatory_100']) && abs($templateTotals['preparatory_100'] - 100.0) <= 0.001,
    'generic_80_template_total' => isset($templateTotals['generic_80']) && abs($templateTotals['generic_80'] - 80.0) <= 0.001,
    'template_has_weekly_average' => !empty(array_filter($templates['primary_100']['components'] ?? [], static function ($component) {
        return !empty($component['counts_in_average']) && ($component['calculation_mode'] ?? '') === 'average_weeks';
    })),
    'permission_any_role_allowed' => $permissionProbe->userHasAnyPermissionRole(1, ['teacher', 'subject_supervisor'], 'delete_mark'),
    'permission_any_role_rejects_missing' => !$permissionProbe->userHasAnyPermissionRole(1, ['teacher', 'specialist'], 'delete_mark'),
    'permission_any_role_ignores_blank_duplicates' => $permissionProbe->userHasAnyPermissionRole(1, ['', 'subject_supervisor', 'subject_supervisor'], 'delete_mark'),
    'copy_scheme_components_with_week_rules' => $copiedScheme['copied_count'] === 2
        && count($copiedScheme['components']) === 2
        && (int) ($copiedScheme['components'][1]['parent_component_id'] ?? 0) === (int) ($copiedScheme['components'][0]['id'] ?? 0)
        && (int) ($copiedScheme['rule']['week_id'] ?? 0) === 7
        && (int) ($copiedScheme['rule']['is_included'] ?? 1) === 0
        && abs(((float) ($copiedScheme['rule']['max_grade_override'] ?? 0)) - 4.5) <= 0.001,
    'copy_scheme_components_scaled' => $copiedScheme['scaled_count'] === 2
        && count($copiedScheme['scaled_components']) === 2
        && abs(((float) ($copiedScheme['scaled_components'][0]['max_grade'] ?? 0)) - 5.0) <= 0.001
        && abs(((float) ($copiedScheme['scaled_components'][1]['max_grade'] ?? 0)) - 2.5) <= 0.001
        && abs(((float) ($copiedScheme['scaled_rule']['max_grade_override'] ?? 0)) - 2.25) <= 0.001,
    'apply_template_scaled_to_80' => $scaledTemplate['created_count'] === count($templates['primary_100']['components'])
        && abs($scaledTemplate['total'] - 80.0) <= 0.001
        && abs($scaledTemplate['final'] - 48.0) <= 0.001,
    'report_snapshot_requires_active_enrollment' => ($snapshotEnrollment['student_id'] ?? 0) === 10
        && ($snapshotEnrollment['student_class'] ?? '') === '3A'
        && ($snapshotEnrollment['entry_class'] ?? '') === '3B'
        && ($snapshotEnrollment['details_count'] ?? 0) === 1
        && !empty($snapshotEnrollment['inactive_rejected'])
        && !empty($snapshotEnrollment['unenrolled_rejected']),
    'annual_weighted_summary' => isset($annualSummary[0])
        && !empty($annualSummary[0]['is_complete'])
        && abs($annualSummary[0]['annual_percentage'] - 86.0) <= 0.001
        && abs($annualSummary[0]['annual_value'] - 86.0) <= 0.001
        && (float) $annualSummary[0]['first_term_weight'] === 40.0
        && (float) $annualSummary[0]['second_term_weight'] === 60.0,
    'annual_policy_supports_any_term_count' => isset($threeTermAnnualSummary[0])
        && !empty($threeTermAnnualSummary[0]['is_complete'])
        && abs((float) $threeTermAnnualSummary[0]['annual_percentage'] - 83.0) <= 0.001,
    'annual_policy_rejects_partial_result' => isset($incompleteAnnualSummary[0])
        && empty($incompleteAnnualSummary[0]['is_complete'])
        && $incompleteAnnualSummary[0]['annual_value'] === null,
    'explicit_scope_supports_sqlite_locking_and_coverage' => count($scopeResolverResult['rows']) === 1
        && $scopeResolverResult['whole_grade_covers_class']
        && $scopeResolverResult['whole_grade_covers_global']
        && $scopeResolverResult['class_scope_covers_its_class']
        && $scopeResolverResult['class_scope_does_not_cover_global']
        && $scopeResolverResult['grouped_scheme_without_scope_fails_closed']
        && $scopeResolverResult['grade_link_dependencies'] === 1
        && $scopeResolverResult['class_link_dependencies'] === 0,
    'family_annual_policy_reads_any_term_weights' => $familyAnnualPolicy['source'] === 'family'
        && !empty($familyAnnualPolicy['enabled'])
        && !empty($familyAnnualPolicy['valid'])
        && $familyAnnualPolicy['weights_by_term_id'] === [11 => 20.0, 12 => 30.0, 13 => 50.0],
    'family_annual_policy_requires_two_positive_terms' => !empty($singleTermFamilyPolicy['enabled'])
        && empty($singleTermFamilyPolicy['valid']),
    'annual_summary_only_for_annual_reports' => $periodAnnualSummary === [],
];

foreach ($checks as $name => $passed) {
    echo $name . ': ' . ($passed ? 'PASS' : 'FAIL') . PHP_EOL;
}

exit(in_array(false, $checks, true) ? 1 : 0);
