<?php
/**
 * ميزانية المدرسة — School Budget
 * يعرض إحصائيات الهيكل العام والقدرة الاستيعابية وتوزيع أعداد الطلاب السنوية مع خيارات التصدير والطباعة والمطابقة البصرية للتقارير الرسمية.
 */
$page_title = "ميزانية المدرسة";
$custom_page_title = true;

require_once '../config/database.php';
require_once '../classes/utilities.php';
require_once '../includes/session_config.php';
Utilities::validateSession('admin');
require_once __DIR__ . '/../classes/FinanceLegacyAdapter.php';
FinanceLegacyAdapter::delegateRequestIfEnabled(__FILE__);

$database = new Database();
$db = $database->getConnection();
$db->exec("SET NAMES 'utf8mb4'");

require_once __DIR__ . '/../classes/AcademicYear.php';
$currentAcademicYearId = AcademicYear::currentId($db);

// جلب تفاصيل ميزانية الفصول والصفوف والمراحل الدراسية للعام الأكاديمي الحالي (التبويب الأول)
if ($currentAcademicYearId > 0) {
    $query = "
        SELECT 
            s.id AS stage_id,
            s.stage_name,
            g.id AS grade_id,
            g.grade_name,
            c.id AS class_id,
            c.name AS class_name,
            SUM(CASE WHEN u.id IS NOT NULL AND sp.gender = 'male' THEN 1 ELSE 0 END) AS male_count,
            SUM(CASE WHEN u.id IS NOT NULL AND sp.gender = 'female' THEN 1 ELSE 0 END) AS female_count,
            SUM(CASE WHEN u.id IS NOT NULL THEN 1 ELSE 0 END) AS total_count
        FROM stages s
        JOIN grades g ON g.stage_id = s.id
        JOIN classes c ON c.grade_id = g.id
        LEFT JOIN student_enrollments se 
            ON se.class_id = c.id 
           AND se.academic_year_id = :academic_year_id
           AND se.enrollment_status = 'enrolled'
        LEFT JOIN users u 
            ON u.id = se.student_id 
           AND u.role = 'student' 
           AND u.status = 'active'
           AND u.deleted_at IS NULL
        LEFT JOIN student_profiles sp 
            ON sp.user_id = u.id
        WHERE s.status = 'active' AND COALESCE(s.is_experimental, 0) = 0
          AND g.status = 'active' AND COALESCE(g.is_experimental, 0) = 0
          AND c.status = 'active' AND COALESCE(c.is_experimental, 0) = 0
        GROUP BY s.id, g.id, c.id
        ORDER BY s.stage_order, s.id, g.grade_order, g.id, c.name
    ";
    $stmt = $db->prepare($query);
    $stmt->execute(['academic_year_id' => $currentAcademicYearId]);
} else {
    $query = "
        SELECT 
            s.id AS stage_id,
            s.stage_name,
            g.id AS grade_id,
            g.grade_name,
            c.id AS class_id,
            c.name AS class_name,
            SUM(CASE WHEN u.id IS NOT NULL AND sp.gender = 'male' THEN 1 ELSE 0 END) AS male_count,
            SUM(CASE WHEN u.id IS NOT NULL AND sp.gender = 'female' THEN 1 ELSE 0 END) AS female_count,
            SUM(CASE WHEN u.id IS NOT NULL THEN 1 ELSE 0 END) AS total_count
        FROM stages s
        JOIN grades g ON g.stage_id = s.id
        JOIN classes c ON c.grade_id = g.id
        LEFT JOIN users u 
            ON u.class_id = c.id 
           AND u.role = 'student' 
           AND u.status = 'active'
           AND u.deleted_at IS NULL
        LEFT JOIN student_profiles sp 
            ON sp.user_id = u.id AND COALESCE(sp.enrollment_status, 'enrolled') = 'enrolled'
        WHERE s.status = 'active' AND COALESCE(s.is_experimental, 0) = 0
          AND g.status = 'active' AND COALESCE(g.is_experimental, 0) = 0
          AND c.status = 'active' AND COALESCE(c.is_experimental, 0) = 0
        GROUP BY s.id, g.id, c.id
        ORDER BY s.stage_order, s.id, g.grade_order, g.id, c.name
    ";
    $stmt = $db->prepare($query);
    $stmt->execute();
}
$rows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

// حساب الإحصائيات العامة للعام الحالي
$uniqueStages = array_filter(array_unique(array_column($rows, 'stage_name')));
$uniqueGrades = array_filter(array_unique(array_column($rows, 'grade_name')));
$uniqueClasses = array_values(array_filter(array_unique(array_column($rows, 'class_name'))));

$totalStages = count($uniqueStages);
$totalGrades = count($uniqueGrades);
$totalClasses = count($rows);
$totalStudents = array_sum(array_column($rows, 'total_count'));
$totalMales = array_sum(array_column($rows, 'male_count'));
$totalFemales = array_sum(array_column($rows, 'female_count'));

// جلب الإعدادات العامة (اسم المدرسة)
$settings = $db->query("SELECT setting_key, setting_value FROM settings")->fetchAll(PDO::FETCH_KEY_PAIR) ?: [];
$schoolName = $settings['school_name'] ?? 'مدارس الدلتا الحديثة للغات';
$directorate = $settings['educational_directorate'] ?? 'مديرية التربية والتعليم بالدقهلية';
$administration = $settings['educational_administration'] ?? 'إدارة طلخا التعليمية';
$studentAffairsOfficer = $settings['student_affairs_officer'] ?? '';
$schoolDirector = $settings['school_director'] ?? '';
$adminDirector = $settings['admin_director'] ?? '';
$kgStageDirector = $settings['kg_director'] ?? '';
$primaryStageDirector = $settings['primary_director'] ?? '';
$prepSecStageDirector = $settings['prep_sec_director'] ?? '';
$schoolNameEn = $settings['school_name_en'] ?? $schoolName;
$directorateEn = $settings['educational_directorate_en'] ?? $directorate;
$administrationEn = $settings['educational_administration_en'] ?? $administration;
$studentAffairsOfficerEn = $settings['student_affairs_officer_en'] ?? $studentAffairsOfficer;
$schoolDirectorEn = $settings['school_director_en'] ?? $schoolDirector;
$adminDirectorEn = $settings['admin_director_en'] ?? $adminDirector;
$kgStageDirectorEn = $settings['kg_director_en'] ?? $kgStageDirector;
$primaryStageDirectorEn = $settings['primary_director_en'] ?? $primaryStageDirector;
$prepSecStageDirectorEn = $settings['prep_sec_director_en'] ?? $prepSecStageDirector;
$schoolLogo = !empty($settings['school_logo'])
    && file_exists(__DIR__ . '/../uploads/' . $settings['school_logo'])
    ? '../uploads/' . htmlspecialchars($settings['school_logo'], ENT_QUOTES, 'UTF-8')
    : '';

$currentYearName = '';
if ($currentAcademicYearId > 0) {
    $currentYearName = $db->query("SELECT name FROM academic_years WHERE id = " . (int)$currentAcademicYearId)->fetchColumn() ?: '';
}
$printDateValue = date('Y-m-d');
$printDateDisplay = date('Y/m/d');
$displayAcademicYear = $currentYearName ?: '2025-2026';

// ----------------------------------------------------
// 1) جلب بيانات التبويب الثاني: إحصاء الطلاب ونسبة الزيادة (10%)
// ----------------------------------------------------
$queryBuffer = "
    SELECT 
        g.id AS grade_id,
        g.grade_name,
        s.stage_name,
        COUNT(CASE
            WHEN u.id IS NOT NULL
             AND (ec.id IS NULL OR (ec.status = 'active' AND COALESCE(ec.is_experimental, 0) = 0))
            THEN u.id
        END) AS student_count
    FROM grades g
    JOIN stages s ON s.id = g.stage_id
    LEFT JOIN student_enrollments se 
       ON se.grade_id = g.id
      AND se.academic_year_id = :academic_year_id
      AND se.enrollment_status = 'enrolled'
    LEFT JOIN classes ec ON ec.id = se.class_id
    LEFT JOIN users u 
        ON u.id = se.student_id 
       AND u.role = 'student' 
       AND u.status = 'active'
       AND u.deleted_at IS NULL
    WHERE g.status = 'active' AND COALESCE(g.is_experimental, 0) = 0
      AND s.status = 'active' AND COALESCE(s.is_experimental, 0) = 0
    GROUP BY g.id, g.grade_name, s.stage_name, g.grade_order, s.stage_order
    ORDER BY s.stage_order, s.id, g.grade_order, g.id
";
$stmtBuffer = $db->prepare($queryBuffer);
if ($currentAcademicYearId > 0) {
    $stmtBuffer->execute(['academic_year_id' => $currentAcademicYearId]);
} else {
    // fallback في حال عدم تعيين عام أكاديمي
    $queryBufferFallback = "
        SELECT 
            g.id AS grade_id,
            g.grade_name,
            s.stage_name,
            COUNT(u.id) AS student_count
        FROM grades g
        JOIN stages s ON s.id = g.stage_id
        LEFT JOIN users u 
            ON u.class_id IN (
                SELECT id FROM classes
                WHERE grade_id = g.id AND COALESCE(is_experimental, 0) = 0
            )
           AND u.role = 'student' 
           AND u.status = 'active'
           AND u.deleted_at IS NULL
        WHERE g.status = 'active' AND COALESCE(g.is_experimental, 0) = 0
          AND s.status = 'active' AND COALESCE(s.is_experimental, 0) = 0
        GROUP BY g.id, g.grade_name, s.stage_name, g.grade_order, s.stage_order
        ORDER BY s.stage_order, s.id, g.grade_order, g.id
    ";
    $stmtBuffer = $db->prepare($queryBufferFallback);
    $stmtBuffer->execute();
}
$bufferRows = $stmtBuffer->fetchAll(PDO::FETCH_ASSOC) ?: [];
$bufferStages = array_values(array_filter(array_unique(array_column($bufferRows, 'stage_name'))));
$bufferGrades = array_values(array_filter(array_unique(array_column($bufferRows, 'grade_name'))));
$bufferClassCounts = [];
foreach ($rows as $detailRow) {
    $bufferGradeId = (int)$detailRow['grade_id'];
    $bufferClassName = (string)$detailRow['class_name'];
    if ($bufferClassName !== '') {
        $bufferClassCounts[$bufferGradeId][$bufferClassName] = (int)$detailRow['total_count'];
    }
}

// ----------------------------------------------------
// 2) جلب بيانات التبويب الثالث: مصفوفة إحصاء الطلاب التاريخية
// ----------------------------------------------------
$yearsQuery = $db->query("SELECT id, name FROM academic_years ORDER BY id ASC");
$years = $yearsQuery->fetchAll(PDO::FETCH_ASSOC) ?: [];

$gradesQuery = $db->query("
    SELECT g.id, g.grade_name, s.stage_name 
    FROM grades g 
    JOIN stages s ON s.id = g.stage_id 
    WHERE g.status = 'active' AND COALESCE(g.is_experimental, 0) = 0
      AND s.status = 'active' AND COALESCE(s.is_experimental, 0) = 0
    ORDER BY s.stage_order, s.id, g.grade_order, g.id
");
$allActiveGrades = $gradesQuery->fetchAll(PDO::FETCH_ASSOC) ?: [];
$historicalStages = array_values(array_filter(array_unique(array_column($allActiveGrades, 'stage_name'))));

$countsQuery = $db->query("
    SELECT 
        se.academic_year_id,
        se.grade_id,
        c.name AS class_name,
        COUNT(u.id) AS student_count
    FROM student_enrollments se
    JOIN grades g ON g.id = se.grade_id
    JOIN stages s ON s.id = g.stage_id
    LEFT JOIN classes c ON c.id = se.class_id
    JOIN users u ON u.id = se.student_id AND u.role = 'student' AND u.status = 'active' AND u.deleted_at IS NULL
    WHERE se.enrollment_status = 'enrolled'
      AND g.status = 'active' AND COALESCE(g.is_experimental, 0) = 0
      AND s.status = 'active' AND COALESCE(s.is_experimental, 0) = 0
      AND (c.id IS NULL OR (c.status = 'active' AND COALESCE(c.is_experimental, 0) = 0))
    GROUP BY se.academic_year_id, se.grade_id, c.id, c.name
");
$countsData = $countsQuery->fetchAll(PDO::FETCH_ASSOC) ?: [];

$historicalMatrix = [];
$historicalClassMatrix = [];
foreach ($countsData as $c) {
    $yearId = (int)$c['academic_year_id'];
    $gradeId = (int)$c['grade_id'];
    $count = (int)$c['student_count'];
    $historicalMatrix[$yearId][$gradeId] = ($historicalMatrix[$yearId][$gradeId] ?? 0) + $count;
    if (!empty($c['class_name'])) {
        $historicalClassMatrix[$yearId][$gradeId][$c['class_name']] = ($historicalClassMatrix[$yearId][$gradeId][$c['class_name']] ?? 0) + $count;
    }
}
$historicalClasses = array_values(array_filter(array_unique(array_column($countsData, 'class_name'))));

require_once '../includes/admin_header.php';
echo FinanceLegacyAdapter::bridgeNotice(__FILE__);

// عنصر اختيار متعدد موحد لفلاتر كل تقرير؛ الاختيارات تبقى محلية لكل تبويب.
$renderBudgetMultiFilter = static function (string $id, string $label, string $tabId, string $filterType, array $options, string $allLabel): void {
    $safeId = htmlspecialchars($id, ENT_QUOTES, 'UTF-8');
    $safeLabel = htmlspecialchars($label, ENT_QUOTES, 'UTF-8');
    $safeTabId = htmlspecialchars($tabId, ENT_QUOTES, 'UTF-8');
    $safeFilterType = htmlspecialchars($filterType, ENT_QUOTES, 'UTF-8');
    $safeAllLabel = htmlspecialchars($allLabel, ENT_QUOTES, 'UTF-8');
    $dropdownId = $safeId . 'Dropdown';
    $checkboxClass = $safeFilterType === 'stage' ? 'stage-checkbox' : ($safeFilterType === 'grade' ? 'grade-checkbox' : 'class-checkbox');
    $itemClass = $safeFilterType === 'stage' ? 'stage-item' : ($safeFilterType === 'grade' ? 'grade-item' : 'class-item');
    $labelId = 'selected' . ucfirst($safeTabId) . ucfirst($safeFilterType) . 'Label';
    if ($safeTabId === 'detailed') {
        $labelId = 'selected' . ucfirst($safeFilterType) . 'sLabel';
    }

    echo '<div class="dropdown d-inline-block me-2 budget-multi-select budget-filter-select" id="' . $safeId . '" data-budget-filter="' . $safeFilterType . '" data-budget-tab="' . $safeTabId . '" data-budget-all-label="' . $safeAllLabel . '">';
    echo '<button class="btn btn-light dropdown-toggle btn-sm filter-dropdown-btn budget-multiselect-toggle" type="button" id="' . $dropdownId . '" data-bs-toggle="dropdown" data-bs-auto-close="outside" aria-expanded="false" aria-controls="' . $safeId . '_menu" aria-label="' . $safeLabel . '" style="background: white; border-color: #dee2e6; color: #495057; height: 31px; display: inline-flex; align-items: center; justify-content: space-between; min-width: 140px;">';
    echo '<span>' . $safeLabel . ': <span id="' . htmlspecialchars($labelId, ENT_QUOTES, 'UTF-8') . '" class="fw-bold budget-multiselect-label" data-budget-filter-label>' . $safeAllLabel . '</span></span>';
    echo '</button>';
    echo '<div class="dropdown-menu p-3 budget-multiselect-menu" id="' . $safeId . '_menu" role="listbox" aria-labelledby="' . $dropdownId . '" aria-multiselectable="true" style="max-height: 250px; overflow-y: auto; min-width: 220px; text-align: right; box-shadow: 0 10px 15px -3px rgba(0, 0, 0, 0.1);">';
    echo '<button type="button" class="dropdown-item budget-multiselect-clear" data-budget-filter-clear>إظهار الكل</button>';
    echo '<div class="budget-multiselect-options">';
    if (!$options) {
        echo '<span class="budget-multiselect-empty">لا توجد خيارات</span>';
    }
    foreach ($options as $optionIndex => $option) {
        $optionValue = is_array($option) ? (string)($option['value'] ?? '') : (string)$option;
        $optionLabel = is_array($option) ? (string)($option['label'] ?? $optionValue) : (string)$option;
        $optionStage = is_array($option) ? (string)($option['stage'] ?? '') : '';
        $optionGrade = is_array($option) ? (string)($option['grade'] ?? '') : '';
        if ($optionValue === '') {
            continue;
        }
        $safeValue = htmlspecialchars($optionValue, ENT_QUOTES, 'UTF-8');
        $safeOptionLabel = htmlspecialchars($optionLabel, ENT_QUOTES, 'UTF-8');
        $safeOptionStage = htmlspecialchars($optionStage, ENT_QUOTES, 'UTF-8');
        $safeOptionGrade = htmlspecialchars($optionGrade, ENT_QUOTES, 'UTF-8');
        $inputId = $safeId . '_option_' . (int)$optionIndex;
        echo '<div class="form-check mb-1 budget-multiselect-option ' . $itemClass . '" data-budget-option-item data-budget-stage="' . $safeOptionStage . '" data-budget-grade="' . $safeOptionGrade . '">';
        echo '<input class="form-check-input ' . $checkboxClass . '" type="checkbox" id="' . htmlspecialchars($inputId, ENT_QUOTES, 'UTF-8') . '" value="' . $safeValue . '" data-budget-filter-option data-budget-label="' . $safeOptionLabel . '" data-budget-stage="' . $safeOptionStage . '" data-budget-grade="' . $safeOptionGrade . '">';
        echo '<label class="form-check-label" for="' . htmlspecialchars($inputId, ENT_QUOTES, 'UTF-8') . '">' . $safeOptionLabel . '</label></div>';
    }
    echo '</div></div></div>';
};
$historicalGradeFilterOptions = array_map(static function (array $grade): array {
    return [
        'value' => (string)$grade['id'],
        'label' => (string)$grade['grade_name'],
        'stage' => (string)$grade['stage_name'],
    ];
}, $allActiveGrades);

// Dynamic filter option metadata.
$budgetStageFilterOptionsByValue = [];
$budgetGradeFilterOptionsByValue = [];
$budgetClassFilterOptionsByValue = [];
foreach ($rows as $budgetFilterRow) {
    $stageValue = (string)($budgetFilterRow['stage_name'] ?? '');
    $gradeValue = (string)($budgetFilterRow['grade_name'] ?? '');
    $classValue = (string)($budgetFilterRow['class_name'] ?? '');
    if ($stageValue !== '') {
        $budgetStageFilterOptionsByValue[$stageValue] = ['value' => $stageValue, 'label' => $stageValue];
    }
    if ($gradeValue !== '') {
        $budgetGradeFilterOptionsByValue[$gradeValue] = ['value' => $gradeValue, 'label' => $gradeValue, 'stage' => $stageValue];
    }
    if ($classValue !== '') {
        $budgetClassFilterOptionsByValue[$classValue] = ['value' => $classValue, 'label' => $classValue, 'stage' => $stageValue, 'grade' => $gradeValue];
    }
}
$budgetStageFilterOptions = array_values($budgetStageFilterOptionsByValue);
$budgetGradeFilterOptions = array_values($budgetGradeFilterOptionsByValue);
$budgetClassFilterOptions = array_values($budgetClassFilterOptionsByValue);

$historicalClassFilterOptionsByValue = [];
$historicalGradeById = [];
foreach ($allActiveGrades as $activeGrade) {
    $historicalGradeById[(string)$activeGrade['id']] = $activeGrade;
}
foreach ($countsData as $historicalFilterRow) {
    $classValue = (string)($historicalFilterRow['class_name'] ?? '');
    $gradeId = (string)($historicalFilterRow['grade_id'] ?? '');
    $historicalGrade = $historicalGradeById[$gradeId] ?? [];
    if ($classValue !== '') {
        $historicalClassFilterOptionsByValue[$classValue] = [
            'value' => $classValue,
            'label' => $classValue,
            'stage' => (string)($historicalGrade['stage_name'] ?? ''),
            'grade' => $gradeId,
        ];
    }
}
$historicalClassFilterOptions = array_values($historicalClassFilterOptionsByValue);
?>

<!-- خطوط المستند الرسمي المستخدمة في الإفادات -->
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=Amiri:wght@400;700&family=Noto+Naskh+Arabic:wght@400;500;600;700&family=Tajawal:wght@400;500;700;800&display=swap" rel="stylesheet">

<style>
    @import url("../assets/css/student-numbers-reports.css?v=<?php echo (int) @filemtime(__DIR__ . '/../assets/css/student-numbers-reports.css'); ?>");

    .budget-header-title .budget-report-subtitle:empty {
        display: none;
    }
    .budget-header-title .budget-report-subtitle {
        color: #64748b;
        font-size: 0.76rem;
        margin-bottom: 0.25rem;
    }
    .budget-header-title .budget-report-title {
        color: #1e293b;
        font-size: 1.25rem;
        font-weight: 800;
        line-height: 1.5;
        text-decoration: none;
    }
    .budget-header-title .budget-report-title[data-budget-title-underline="true"] {
        text-decoration: underline;
        text-underline-offset: 0.35em;
    }
    .budget-official-footer {
        direction: rtl;
        border-top: 0;
        margin-top: 1.5rem;
        padding-top: 0;
        display: grid;
        grid-template-columns: repeat(auto-fit, minmax(0, 1fr));
        gap: 1rem;
        text-align: center;
        font-size: 1.15rem;
        line-height: 1.8;
        font-weight: 700;
    }
    .budget-official-footer .budget-footer-label {
        color: #475569;
        display: block;
        margin-bottom: 0.2rem;
        font-size: 1.08rem;
    }
    .budget-official-footer .budget-footer-name {
        color: #0f172a;
        min-height: 1.2em;
        font-size: 1.15rem;
    }
    /* ترتيب التوقيعات: شؤون الطلاب، مديرات المراحل، ثم مدير المدرسة. */
    .budget-official-footer > [data-budget-signature-col="student_affairs"] { order: 1; }
    .budget-official-footer > [data-budget-signature-col="stage_kg"] { order: 2; }
    .budget-official-footer > [data-budget-signature-col="stage_primary"] { order: 3; }
    .budget-official-footer > [data-budget-signature-col="stage_prep_sec"] { order: 4; }
    .budget-official-footer > [data-budget-signature-col="school_director"] { order: 5; }
    .budget-official-footer > [data-budget-signature-col="admin_director"] { order: 6; }
    .report-paper-sheet[data-signature-mode="titles"] .budget-official-footer .budget-footer-name {
        display: none;
    }
    .report-paper-sheet [data-budget-signature-col][data-budget-signature-visible="false"] {
        display: none !important;
    }
    /* عند اختيار توقيع واحد فقط يوضع في الجهة اليسرى من الفوتر العربي. */
    .report-paper-sheet:not([data-budget-language="en"]) .budget-official-footer[data-visible-signatures="1"] > [data-budget-signature-col] {
        width: 50%;
        justify-self: end;
    }
    .report-paper-sheet[data-budget-language="en"] .budget-official-footer[data-visible-signatures="1"] > [data-budget-signature-col] {
        width: 50%;
        justify-self: start;
    }
    .budget-filter-select {
        min-width: 150px;
        height: 31px;
    }
    .budget-filter-select:disabled {
        cursor: not-allowed;
        opacity: .7;
    }
    .budget-multi-select {
        position: relative;
        min-width: 140px;
        height: 31px;
        z-index: 20;
        display: inline-block;
    }
    .budget-multiselect-toggle {
        width: 100%;
        min-width: 140px;
        height: 31px;
        display: inline-flex;
        align-items: center;
        justify-content: space-between;
        gap: .35rem;
        white-space: nowrap;
        overflow: hidden;
        text-overflow: ellipsis;
        border: 1px solid #cbd5e1;
        color: #0f172a;
        background-color: #fff;
    }
    .budget-multiselect-toggle:focus-visible {
        outline: 2px solid rgba(37, 99, 235, .35);
        outline-offset: 2px;
    }
    .budget-multiselect-label {
        overflow: hidden;
        text-overflow: ellipsis;
    }
    .budget-multiselect-menu {
        display: none;
        position: absolute;
        top: calc(100% + 5px);
        inset-inline-end: 0;
        min-width: 230px;
        max-width: min(300px, 82vw);
        max-height: 290px;
        overflow: auto;
        padding: .45rem;
        border: 1px solid #cbd5e1;
        border-radius: .45rem;
        background: #fff;
        box-shadow: 0 10px 24px rgba(15, 23, 42, .16);
        z-index: 1080;
    }
    .budget-multi-select.is-open {
        z-index: 1090;
    }
    .budget-multi-select.is-open .budget-multiselect-menu {
        display: block;
    }
    .budget-multiselect-menu.show {
        display: block;
    }
    .budget-multiselect-clear {
        width: 100%;
        margin-bottom: .35rem;
        text-align: start;
        color: #1d4ed8;
        border: 0;
        background: transparent;
    }
    .budget-multiselect-options {
        display: grid;
        gap: .1rem;
    }
    .budget-multiselect-option {
        display: flex;
        align-items: center;
        gap: .5rem;
        width: 100%;
        padding: .38rem .45rem;
        border-radius: .3rem;
        color: #1e293b;
        cursor: pointer;
        font-size: .85rem;
        line-height: 1.35;
    }
    .budget-multiselect-option .form-check-label {
        flex: 1 1 auto;
        cursor: pointer;
        margin: 0;
    }
    .budget-multiselect-option:hover {
        background: #eff6ff;
    }
    .budget-multiselect-option input {
        flex: 0 0 auto;
        width: 1rem;
        height: 1rem;
        accent-color: #2563eb;
    }
    .budget-multiselect-option[hidden] {
        display: none !important;
    }
    .budget-multiselect-empty {
        display: block;
        padding: .35rem .45rem;
        color: #64748b;
        font-size: .8rem;
    }
    @media print {
        .budget-multi-select {
            display: none !important;
        }
    }
    .report-paper-sheet[data-budget-paper="detailed"] table.admin-data-table tbody tr,
    .report-paper-sheet[data-budget-paper="detailed"] table.admin-data-table tbody tr:nth-child(odd),
    .report-paper-sheet[data-budget-paper="detailed"] table.admin-data-table tbody tr:nth-child(even),
    .report-paper-sheet[data-budget-paper="detailed"] table.admin-data-table tbody tr > * {
        background-color: #ffffff !important;
        background-image: none !important;
        --bs-table-bg-type: #ffffff !important;
        --bs-table-accent-bg: #ffffff !important;
    }
    .report-paper-sheet[data-budget-paper="detailed"] table.admin-data-table tbody tr:hover > * {
        background-color: #ffffff !important;
        background-image: none !important;
    }
    
    /* تنسيقات الجدول الاحترافي داخل الورقة ليشبه المطبوعات الرسمية */
    .report-paper-sheet table.admin-data-table {
        border-collapse: collapse !important;
        table-layout: fixed !important;
        width: 100% !important;
        border: 2px solid #000 !important;
        margin-top: 10px !important;
    }
    /* الورقة نفسها تعرض الجدول كاملًا؛ لا حاجة لتمرير داخلي طالما أن التخطيط ثابت داخل A4. */
    .report-paper-sheet.budget-editable-paper,
    .report-paper-sheet .table-responsive.admin-table-wrap,
    .report-paper-sheet .admin-list-surface .admin-table-wrap {
        overflow: visible !important;
        overflow-x: visible !important;
        overflow-y: visible !important;
        height: auto !important;
        max-height: none !important;
    }
    /* توزيع أعمدة الميزانية التفصيلية حسب طبيعة البيانات: النص يأخذ المساحة، والأعداد تبقى مضغوطة. */
    .report-paper-sheet #budgetTable col.budget-col-index { width: 7% !important; }
    .report-paper-sheet #budgetTable col.budget-col-stage { width: 18% !important; }
    .report-paper-sheet #budgetTable col.budget-col-grade { width: 26% !important; }
    .report-paper-sheet #budgetTable col.budget-col-class { width: 24% !important; }
    .report-paper-sheet #budgetTable col.budget-col-male,
    .report-paper-sheet #budgetTable col.budget-col-female { width: 5.5% !important; }
    .report-paper-sheet #budgetTable col.budget-col-total { width: 14% !important; }
    .report-paper-sheet #budgetTable th:nth-child(2),
    .report-paper-sheet #budgetTable td:nth-child(2),
    .report-paper-sheet #budgetTable th:nth-child(3),
    .report-paper-sheet #budgetTable td:nth-child(3),
    .report-paper-sheet #budgetTable th:nth-child(4),
    .report-paper-sheet #budgetTable td:nth-child(4) {
        white-space: nowrap;
    }
    .report-paper-sheet table.admin-data-table th {
        background-color: #f8fafc !important;
        color: #0f172a !important;
        border: 1px solid #000 !important;
        font-weight: 700 !important;
        padding: 12px 8px !important;
        text-align: center !important;
        font-size: var(--budget-table-font-size) !important;
        cursor: default !important; /* إلغاء مؤشر اليد لعدم الإيحاء بالترتيب */
    }
    /* إخفاء أسهم الترتيب الخاصة بـ DataTable داخل الورقة لتظهر كورقة رسمية */
    .report-paper-sheet table.admin-data-table th.sorting::before,
    .report-paper-sheet table.admin-data-table th.sorting::after,
    .report-paper-sheet table.admin-data-table th.sorting_asc::before,
    .report-paper-sheet table.admin-data-table th.sorting_asc::after,
    .report-paper-sheet table.admin-data-table th.sorting_desc::before,
    .report-paper-sheet table.admin-data-table th.sorting_desc::after {
        display: none !important;
    }
    .report-paper-sheet table.admin-data-table thead th[data-budget-editable-heading="true"] {
        cursor: text !important;
        user-select: text;
    }
    .report-paper-sheet table.admin-data-table td {
        border: 1px solid #000 !important;
        padding: 10px 8px !important;
        color: #1e293b !important;
        font-size: var(--budget-table-font-size) !important;
        vertical-align: middle !important;
        text-align: center !important;
    }
    .report-paper-sheet table.admin-data-table th,
    .report-paper-sheet table.admin-data-table td {
        text-align: center !important;
        vertical-align: middle !important;
    }
    /* إلغاء التظليل المخطط (zebra striping) والظلال الداخلية لمنع تداخله وتشويهه للخلايا المدمجة */
    .report-paper-sheet table.admin-data-table tbody tr td {
        background-color: #ffffff !important;
        background-image: none !important;
        box-shadow: none !important;
    }
    .report-paper-sheet table.admin-data-table tbody tr:hover td {
        background-color: #ffffff !important;
        background-image: none !important;
        box-shadow: none !important;
    }
    .report-paper-sheet table.admin-data-table tfoot tr {
        background-color: #f1f5f9 !important;
        font-weight: 700 !important;
    }
    .report-paper-sheet table.admin-data-table tfoot td {
        border: 1px solid #000 !important;
        color: #0f172a !important;
        font-size: var(--budget-table-font-size) !important;
    }
    /* تثبيت توسيط جميع الخلايا ضد أي قواعد Bootstrap أو كلاسات text-start */
    .report-paper-sheet table.admin-data-table > :not(caption) > * > * {
        text-align: center !important;
        vertical-align: middle !important;
    }
    /* توحيد محاذاة كل خلايا أي جدول داخل ورقة التقرير، بما فيها الجداول المضافة أثناء التحرير */
    .report-paper-sheet table th,
    .report-paper-sheet table td,
    .report-paper-sheet table.admin-data-table > :not(caption) > * > * {
        text-align: center !important;
        vertical-align: middle !important;
    }
    /* يتغلب على قواعد RTL العامة الخاصة بـ DataTables التي تفرض المحاذاة لليمين */
    .report-paper-sheet .dataTables_wrapper table.admin-data-table th,
    .report-paper-sheet .dataTables_wrapper table.admin-data-table td,
    .report-paper-sheet .dataTables_wrapper table.admin-data-table > :not(caption) > * > * {
        text-align: center !important;
        vertical-align: middle !important;
    }
    /* شبكة موحدة سوداء لكل خلايا التقرير: نفس اللون والسمك أفقياً ورأسياً */
    body .report-paper-sheet table.admin-data-table > thead > tr > th,
    body .report-paper-sheet table.admin-data-table > tbody > tr > td,
    body .report-paper-sheet table.admin-data-table > tfoot > tr > td {
        border-top: 1px solid #000 !important;
        border-right: 1px solid #000 !important;
        border-bottom: 1px solid #000 !important;
        border-left: 1px solid #000 !important;
        border-color: #000 !important;
    }
    .report-paper-sheet table {
        border: 2px solid #000 !important;
        border-collapse: collapse !important;
    }
    .report-paper-sheet table.admin-data-table tfoot > tr:first-child > td {
        border-top: 3px solid #000 !important;
    }
    .report-paper-sheet table.admin-data-table tbody tr.budget-stage-break > td {
        border-top: 2px solid #000 !important;
    }
    .report-paper-sheet table.admin-data-table tbody tr > *,
    .report-paper-sheet table.admin-data-table tbody tr:nth-child(odd) > *,
    .report-paper-sheet table.admin-data-table tbody tr:nth-child(even) > * {
        background-color: #ffffff !important;
        background-image: none !important;
        box-shadow: none !important;
    }
    body .report-paper-sheet table.admin-data-table td[rowspan] {
        border-inline: 1px solid #000 !important;
    }
    .report-paper-sheet[data-table-density="compact"][data-print-margin="normal"] {
        padding: 2rem 2.25rem;
    }
    .report-paper-sheet[data-table-density="compact"] table.admin-data-table th,
    .report-paper-sheet[data-table-density="compact"] table.admin-data-table td {
        padding: 0 3px !important;
        font-size: 14px !important;
        line-height: 1 !important;
    }
    .report-paper-sheet[data-table-density="compact"] .report-sheet-header {
        margin-bottom: .5rem !important;
    }
    .report-paper-sheet[data-table-density="compact"] .budget-official-header {
        padding-bottom: .55rem;
        margin-bottom: .55rem;
    }
    .report-paper-sheet[data-table-density="compact"] .budget-official-footer {
        margin-top: .65rem;
    }
    .report-paper-sheet[data-table-density="compact"] table.admin-data-table {
        margin-top: 3px !important;
    }
    /* زيادة بسيطة للقراءة على الشاشة مع إبراز صف العناوين والإجمالي؛ الطباعة لها كثافتها المستقلة أدناه. */
    @media screen {
        .report-paper-sheet[data-table-density="compact"][data-print-margin="normal"] {
            padding: .8rem 2.25rem;
        }
        .report-paper-sheet[data-table-density="compact"] table.admin-data-table > tbody > tr > td {
            padding: .25px 3px !important;
            line-height: 1 !important;
        }
        .report-paper-sheet[data-table-density="compact"] table.admin-data-table > thead > tr > th,
        .report-paper-sheet[data-table-density="compact"] table.admin-data-table > tfoot > tr > td {
            padding: 2px 3px !important;
            line-height: 1.15 !important;
        }
    }

    /* فاصل واضح بين رؤوس الأعمدة والبيانات مع زيادة بسيطة لارتفاع صف العناوين في التبويبات الثلاثة. */
    .report-paper-sheet table.admin-data-table > thead,
    .report-paper-sheet table.admin-data-table > thead > tr {
        border-bottom: 3px solid #000 !important;
    }
    .report-paper-sheet table.admin-data-table > thead > tr > th {
        border-bottom: 3px solid #000 !important;
        height: 38px !important;
        line-height: 1.25 !important;
    }
    .report-paper-sheet[data-table-density="compact"] table.admin-data-table > thead > tr > th {
        padding: 6px 3px !important;
    }

    .report-paper-sheet table.admin-data-table > tfoot > tr > th,
    .report-paper-sheet table.admin-data-table > tfoot > tr > td {
        height: 34px !important;
        padding: 6px 3px !important;
        line-height: 1.2 !important;
    }

    /* تنسيق خاص للطباعة والحدود السوداء للتقارير الرسمية المرفقة */
    @page budgetPortrait {
        size: A4 portrait;
        margin: 4mm;
    }
    @page budgetLandscape {
        size: A4 landscape;
        margin: 4mm;
    }

    /*
     * طباعة مطابقة للمعاينة: نطبع عنصر الورقة نفسه بأبعاد A4 الكاملة،
     * ونستخدم الحشو الداخلي ذاته بدل إضافة هوامش خارجية أو إعادة تحجيم الجدول.
     */
    @media print {
        html,
        body,
        body.school-budget-printing {
            margin: 0 !important;
            padding: 0 !important;
            width: 100% !important;
            height: 100% !important;
            overflow: hidden !important;
            background: #fff !important;
        }

        body.school-budget-printing * {
            visibility: hidden !important;
        }

        body.school-budget-printing .budget-print-active,
        body.school-budget-printing .budget-print-active *,
        body.school-budget-printing .budget-print-active::before {
            visibility: visible !important;
        }

        body.school-budget-printing .budget-print-active {
            display: block !important;
            position: fixed !important;
            inset: 0 auto auto 0 !important;
            box-sizing: border-box !important;
            margin: 0 !important;
            max-width: none !important;
            max-height: none !important;
            border: 0 !important;
            border-radius: 0 !important;
            box-shadow: none !important;
            outline: 0 !important;
            overflow: hidden !important;
            transform: none !important;
            page-break-before: avoid !important;
            page-break-after: avoid !important;
            page-break-inside: avoid !important;
            break-inside: avoid-page !important;
            -webkit-print-color-adjust: exact !important;
            print-color-adjust: exact !important;
        }

        body.school-budget-printing .budget-print-active[data-print-orientation="portrait"] {
            page: budgetPortrait !important;
            width: 202mm !important;
            min-width: 202mm !important;
            height: 289mm !important;
            min-height: 289mm !important;
        }

        body.school-budget-printing .budget-print-active[data-print-orientation="landscape"] {
            page: budgetLandscape !important;
            width: 289mm !important;
            min-width: 289mm !important;
            height: 202mm !important;
            min-height: 202mm !important;
        }

        body.school-budget-printing .budget-print-active[data-print-margin="narrow"] {
            padding: 2.25rem 2rem !important;
        }

        body.school-budget-printing .budget-print-active[data-print-margin="normal"] {
            padding: 3rem 2.75rem !important;
        }

        body.school-budget-printing .budget-print-active[data-print-margin="wide"] {
            padding: 4rem 3.5rem !important;
        }

        body.school-budget-printing .budget-print-active[data-table-density="compact"][data-print-margin="normal"] {
            padding: .8rem 2.25rem !important;
        }

        body.school-budget-printing .budget-print-active[data-show-border="true"] {
            padding: 18mm 16mm !important;
        }

        body.school-budget-printing .budget-print-active[data-show-border="true"]::before {
            content: "" !important;
            display: block !important;
            position: absolute !important;
            inset: 7mm !important;
            border: 3.5px double #000 !important;
            border-radius: 3px !important;
            box-sizing: border-box !important;
            pointer-events: none !important;
            z-index: 9999 !important;
        }

        body.school-budget-printing .budget-print-active table.admin-data-table {
            width: 100% !important;
            margin-top: 10px !important;
            border-collapse: collapse !important;
            table-layout: fixed !important;
            border: 2px solid #000 !important;
        }

        body.school-budget-printing .budget-print-active table.admin-data-table th {
            padding: 12px 8px !important;
            border: 1px solid #000 !important;
            background-color: #f8fafc !important;
            color: #0f172a !important;
            font-size: var(--budget-table-font-size) !important;
            font-weight: 700 !important;
            text-align: center !important;
            vertical-align: middle !important;
        }

        body.school-budget-printing .budget-print-active table.admin-data-table td {
            padding: 10px 8px !important;
            border: 1px solid #000 !important;
            background-color: #fff !important;
            color: #1e293b !important;
            font-size: var(--budget-table-font-size) !important;
            font-weight: 400 !important;
            text-align: center !important;
            vertical-align: middle !important;
        }

        body.school-budget-printing .budget-print-active table.admin-data-table > thead,
        body.school-budget-printing .budget-print-active table.admin-data-table > thead > tr {
            border-bottom: 3px solid #000 !important;
        }

        body.school-budget-printing .budget-print-active table.admin-data-table > thead > tr > th {
            height: 38px !important;
            padding: 12px 8px !important;
            border-bottom: 3px solid #000 !important;
            line-height: 1.25 !important;
        }

        body.school-budget-printing .budget-print-active table.admin-data-table tfoot td,
        body.school-budget-printing .budget-print-active table.admin-data-table tr[data-total-row="true"] > *,
        body.school-budget-printing .budget-print-active table.admin-data-table tr.budget-total-row > *,
        body.school-budget-printing .budget-print-active table.admin-data-table tr.total-row > * {
            height: 34px !important;
            padding-top: 6px !important;
            padding-bottom: 6px !important;
            background-color: #f1f5f9 !important;
            color: #0f172a !important;
            font-weight: 700 !important;
            line-height: 1.2 !important;
        }

        body.school-budget-printing .budget-print-active[data-table-density="compact"] table.admin-data-table > tbody > tr > td {
            padding: .25px 3px !important;
            font-size: 14px !important;
            line-height: .96 !important;
        }

        body.school-budget-printing .budget-print-active[data-table-density="compact"] table.admin-data-table > thead > tr > th {
            /* 24px + الحشو والحدود = نحو 38px فعلياً، حتى تبقى الورقة داخل A4. */
            height: 24px !important;
            padding: 6px 3px !important;
            font-size: 14px !important;
            line-height: 1.25 !important;
        }

        body.school-budget-printing .budget-print-active[data-table-density="compact"] table.admin-data-table > tfoot > tr > td {
            height: 34px !important;
            padding: 6px 3px !important;
            font-size: 14px !important;
            line-height: 1.2 !important;
        }

        body.school-budget-printing .budget-print-active[data-table-density="compact"] .report-sheet-header {
            margin-bottom: .5rem !important;
        }

        body.school-budget-printing .budget-print-active[data-table-density="compact"] .budget-official-header {
            gap: 1rem !important;
            padding-bottom: .55rem !important;
            margin-bottom: .55rem !important;
        }

        body.school-budget-printing .budget-print-active[data-table-density="compact"] .budget-official-footer {
            margin-top: .65rem !important;
        }

        body.school-budget-printing .budget-print-active[data-table-density="compact"] .budget-header-school,
        body.school-budget-printing .budget-print-active[data-table-density="compact"] .budget-header-meta {
            font-size: 13px !important;
            line-height: 1.6 !important;
        }

        body.school-budget-printing .budget-print-active[data-table-density="compact"] .budget-header-logo img {
            max-width: 105px !important;
            max-height: 105px !important;
        }

        body.school-budget-printing .budget-print-active .budget-header-title .budget-report-title {
            color: #1e293b !important;
            font-size: 1.25rem !important;
            line-height: 1.5 !important;
        }

        body.school-budget-printing .budget-print-active .budget-header-title .budget-report-subtitle {
            color: #64748b !important;
        }

        body.school-budget-printing .budget-print-active .budget-official-footer .budget-footer-label {
            color: #475569 !important;
        }

        body.school-budget-printing .budget-print-active .budget-official-footer .budget-footer-name {
            color: #0f172a !important;
        }

        body.school-budget-printing .budget-print-active tr,
        body.school-budget-printing .budget-print-active td,
        body.school-budget-printing .budget-print-active th {
            break-inside: avoid !important;
            page-break-inside: avoid !important;
        }
    }
</style>
<style>
.report-paper-sheet .budget-title-toolbar-ready {
    text-decoration: none !important;
    border-bottom: 0 !important;
}
.report-paper-sheet .budget-title-toolbar-ready::before,
.report-paper-sheet .budget-title-toolbar-ready::after {
    display: none !important;
}
.report-paper-sheet .budget-title-toolbar-ready > u,
.report-paper-sheet .budget-title-toolbar-ready > ins {
    text-decoration-line: underline;
    text-decoration-thickness: 1.5px;
    text-underline-offset: 3px;
}
</style>
<script src="../assets/js/school-budget-title-underline.js?v=<?= (int) @filemtime(__DIR__ . '/../assets/js/school-budget-title-underline.js') ?>"></script>

<div class="admin-page-heading no-print d-flex justify-content-between align-items-center flex-wrap gap-2 mb-4">
    <div>
        <h1 class="h2 fw-bold text-dark"><i class="fas fa-calculator me-3 text-primary"></i>ميزانية المدرسة</h1>
        <p class="text-muted m-0">تقارير قابلة للتخصيص والتحرير والطباعة على ورق A4</p>
    </div>
    <div class="admin-top-actions d-flex gap-2">
        <button type="button" id="exportBtnTop" class="btn btn-header-premium btn-export-soft">
            <i class="fas fa-file-excel me-1"></i>تصدير Excel
        </button>
        <button type="button" id="budgetPdfBtn" class="btn btn-header-premium btn-pdf-soft" title="حفظ المستند بصيغة PDF من نافذة الطباعة">
            <i class="fas fa-file-pdf me-1"></i>تصدير PDF
        </button>
        <button type="button" id="budgetPrintBtn" class="btn btn-header-premium btn-print-soft">
            <i class="fas fa-print me-1"></i>طباعة المستند
        </button>
    </div>
</div>

<!-- شريط تنسيق موحّد للورقة النشطة مثل محرر الإفادات -->
<div class="budget-editor-toolbar no-print mb-3" id="budgetEditorToolbar" dir="rtl" role="toolbar" aria-label="أدوات تنسيق ورقة ميزانية المدرسة">
    <div class="budget-editor-toolbar-label">
        <span class="badge bg-light text-primary border shadow-sm px-3 py-2">
            <i class="fas fa-edit me-1"></i>المستند قابل للتعديل المباشر
        </span>
    </div>
    <span class="fw-bold text-dark budget-editor-tools-title"><i class="fas fa-file-word text-primary me-1"></i>أدوات التنسيق:</span>

    <div class="btn-group btn-group-sm" role="group" aria-label="تنسيق النص">
        <button type="button" class="btn btn-outline-secondary" data-budget-command="bold" title="عريض"><i class="fas fa-bold"></i></button>
        <button type="button" class="btn btn-outline-secondary" data-budget-command="italic" title="مائل"><i class="fas fa-italic"></i></button>
        <button type="button" class="btn btn-outline-secondary" data-budget-command="underline" title="تحته خط"><i class="fas fa-underline"></i></button>
        <button type="button" class="btn btn-outline-secondary" data-budget-command="strikeThrough" title="يتوسطه خط"><i class="fas fa-strikethrough"></i></button>
    </div>

    <div class="budget-editor-control" title="تطبيق الخط على كامل الورقة">
        <i class="fas fa-font text-muted"></i>
        <label for="budgetEditorFont" class="visually-hidden">خط الورقة</label>
        <select id="budgetEditorFont" class="form-select form-select-sm budget-editor-select budget-editor-font-select" aria-label="خط الورقة">
            <option value="Tajawal">تجوال — عصري</option>
            <option value="Amiri">أميري — رسمي</option>
            <option value="Noto Naskh Arabic">نوتو نسخ — طباعي</option>
        </select>
    </div>

    <div class="budget-editor-control">
        <button type="button" class="btn btn-outline-secondary btn-sm" data-budget-font-step="-1" title="تصغير الخط"><i class="fas fa-font"></i>-</button>
        <label for="budgetEditorFontSize" class="visually-hidden">حجم الخط</label>
        <select id="budgetEditorFontSize" class="form-select form-select-sm budget-editor-select budget-editor-size-select" aria-label="حجم الخط">
            <option value="">الحجم</option>
            <option value="12px">12</option>
            <option value="14px">14</option>
            <option value="16px">16</option>
            <option value="18px">18</option>
            <option value="20px">20</option>
            <option value="24px">24</option>
            <option value="28px">28</option>
            <option value="32px">32</option>
            <option value="36px">36</option>
        </select>
        <button type="button" class="btn btn-outline-secondary btn-sm" data-budget-font-step="1" title="تكبير الخط"><i class="fas fa-font"></i>+</button>
    </div>

    <div class="budget-editor-color-control" title="لون الخط">
        <i class="fas fa-palette text-muted"></i>
        <label for="budgetEditorTextColor" class="visually-hidden">لون الخط</label>
        <input type="color" id="budgetEditorTextColor" class="form-control form-control-color" value="#000000" aria-label="لون الخط">
    </div>
    <div class="budget-editor-color-control" title="لون التظليل">
        <i class="fas fa-highlighter text-muted"></i>
        <label for="budgetEditorHighlightColor" class="visually-hidden">لون التظليل</label>
        <input type="color" id="budgetEditorHighlightColor" class="form-control form-control-color" value="#ffff00" aria-label="لون التظليل">
    </div>

    <div class="btn-group btn-group-sm" role="group" aria-label="محاذاة النص">
        <button type="button" class="btn btn-outline-secondary" data-budget-command="justifyRight" title="محاذاة لليمين"><i class="fas fa-align-right"></i></button>
        <button type="button" class="btn btn-outline-secondary" data-budget-command="justifyCenter" title="محاذاة للوسط"><i class="fas fa-align-center"></i></button>
        <button type="button" class="btn btn-outline-secondary" data-budget-command="justifyLeft" title="محاذاة لليسار"><i class="fas fa-align-left"></i></button>
        <button type="button" class="btn btn-outline-secondary" data-budget-command="justifyFull" title="محاذاة كاملة"><i class="fas fa-align-justify"></i></button>
    </div>

    <div class="btn-group btn-group-sm" role="group" aria-label="القوائم والمسافات البادئة">
        <button type="button" class="btn btn-outline-secondary" data-budget-command="insertUnorderedList" title="قائمة نقطية"><i class="fas fa-list-ul"></i></button>
        <button type="button" class="btn btn-outline-secondary" data-budget-command="insertOrderedList" title="قائمة رقمية"><i class="fas fa-list-ol"></i></button>
        <button type="button" class="btn btn-outline-secondary" data-budget-command="outdent" title="تقليل المسافة البادئة"><i class="fas fa-outdent"></i></button>
        <button type="button" class="btn btn-outline-secondary" data-budget-command="indent" title="زيادة المسافة البادئة"><i class="fas fa-indent"></i></button>
    </div>

    <label for="budgetEditorLineHeight" class="visually-hidden">تباعد الأسطر</label>
    <select id="budgetEditorLineHeight" class="form-select form-select-sm budget-editor-select budget-editor-line-height" aria-label="تباعد الأسطر">
        <option value="">تباعد</option>
        <option value="1">1.0</option>
        <option value="1.15">1.15</option>
        <option value="1.5">1.5</option>
        <option value="2">2.0</option>
        <option value="2.6">2.6</option>
    </select>

    <div class="btn-group btn-group-sm" role="group" aria-label="التراجع وإعادة التنسيق">
        <button type="button" class="btn btn-outline-secondary" data-budget-command="undo" title="تراجع"><i class="fas fa-undo"></i></button>
        <button type="button" class="btn btn-outline-secondary" data-budget-command="redo" title="إعادة"><i class="fas fa-redo"></i></button>
        <button type="button" class="btn btn-outline-danger" data-budget-command="removeFormat" title="إزالة التنسيق"><i class="fas fa-eraser"></i></button>
    </div>
</div>



<!-- قائمة التبويبات التفاعلية -->
<ul class="nav nav-tabs nav-tabs-premium no-print mb-4" id="budgetTabs" role="tablist">
    <li class="nav-item" role="presentation">
        <button class="nav-link active" id="detailed-tab" data-bs-toggle="tab" data-bs-target="#detailed-pane" type="button" role="tab" aria-controls="detailed-pane" aria-selected="true">
            <i class="fas fa-list-alt me-2"></i>ميزانية الفصول التفصيلية
        </button>
    </li>
    <li class="nav-item" role="presentation">
        <button class="nav-link" id="buffer-tab" data-bs-toggle="tab" data-bs-target="#buffer-pane" type="button" role="tab" aria-controls="buffer-pane" aria-selected="false">
            <i class="fas fa-percentage me-2"></i>إحصاء الطلاب بالزيادة (10%)
        </button>
    </li>
    <li class="nav-item" role="presentation">
        <button class="nav-link" id="historical-tab" data-bs-toggle="tab" data-bs-target="#historical-pane" type="button" role="tab" aria-controls="historical-pane" aria-selected="false">
            <i class="fas fa-chart-line me-2"></i>إحصاء الطلاب التاريخي
        </button>
    </li>
</ul>

<!-- محتوى التبويبات -->
<div class="tab-content" id="budgetTabsContent">
    
    <!-- 1) التبويب الأول: التفصيلي الحالي -->
    <div class="tab-pane fade show active" id="detailed-pane" role="tabpanel" aria-labelledby="detailed-tab">
        <!-- بار الفلاتر (Filters Bar) -->
        <div class="admin-filter-bar mb-3 no-print">
            <div class="admin-filter-controls d-flex flex-wrap gap-2 align-items-center">
                <!-- فلتر المراحل -->
                <?php $renderBudgetMultiFilter('stageFilter', 'المراحل', 'detailed', 'stage', $budgetStageFilterOptions, 'الكل'); ?>
                
                <!-- فلتر الصفوف -->
                <?php $renderBudgetMultiFilter('gradeFilter', 'الصفوف', 'detailed', 'grade', $budgetGradeFilterOptions, 'الكل'); ?>
                <?php $renderBudgetMultiFilter('classFilter', 'الفصول', 'detailed', 'class', $budgetClassFilterOptions, 'الكل'); ?>
            </div>
            
            <div class="admin-filter-actions">
                <button type="button" class="btn btn-light btn-sm" id="resetFilters" style="height: 31px !important; display: inline-flex !important; align-items: center !important; justify-content: center !important; vertical-align: middle !important;">
                    <i class="fas fa-undo me-1"></i>إعادة تعيين
                </button>
                <button type="button" class="btn btn-light btn-sm" data-bs-toggle="modal" data-bs-target="#settingsModal_detailed" style="height: 31px !important; display: inline-flex !important; align-items: center !important; justify-content: center !important; vertical-align: middle !important;">
                    <i class="fas fa-cog me-1"></i>إعدادات الجدول
                </button>
                <button type="button" class="btn btn-light btn-sm" data-budget-print-settings="detailed" style="height: 31px !important; display: inline-flex !important; align-items: center !important; justify-content: center !important; vertical-align: middle !important;">
                    <i class="fas fa-print me-1"></i>إعدادات الطباعة
                </button>
            </div>
        </div>

        <div class="report-paper-sheet shadow-sm budget-editable-paper" data-budget-paper="detailed" data-print-orientation="portrait" data-print-margin="normal" data-table-density="compact" data-signature-mode="titles_names" data-show-header="true" data-show-meta="true" data-show-note="false" contenteditable="true" role="textbox" aria-multiline="true" aria-label="ورقة ميزانية الفصول التفصيلية قابلة للتعديل" spellcheck="true">
            <!-- ترويسة الورقة الرسمية للتبويب الأول -->
            <div class="report-sheet-header text-center mb-4">
                <div class="budget-official-header">
                    <div class="budget-header-school">
                        <div data-budget-ar="وزارة التربية والتعليم والتعليم الفني" data-budget-en="Ministry of Education and Technical Education">وزارة التربية والتعليم والتعليم الفني</div>
                        <div data-budget-ar="<?php echo htmlspecialchars($directorate, ENT_QUOTES, 'UTF-8'); ?>" data-budget-en="<?php echo htmlspecialchars($directorateEn, ENT_QUOTES, 'UTF-8'); ?>"><?php echo htmlspecialchars($directorate, ENT_QUOTES, 'UTF-8'); ?></div>
                        <div data-budget-ar="<?php echo htmlspecialchars($administration, ENT_QUOTES, 'UTF-8'); ?>" data-budget-en="<?php echo htmlspecialchars($administrationEn, ENT_QUOTES, 'UTF-8'); ?>"><?php echo htmlspecialchars($administration, ENT_QUOTES, 'UTF-8'); ?></div>
                        <div data-budget-ar="<?php echo htmlspecialchars($schoolName, ENT_QUOTES, 'UTF-8'); ?>" data-budget-en="<?php echo htmlspecialchars($schoolNameEn, ENT_QUOTES, 'UTF-8'); ?>"><?php echo htmlspecialchars($schoolName, ENT_QUOTES, 'UTF-8'); ?></div>
                    </div>
                    <div class="budget-header-logo" aria-label="شعار المدرسة">
                        <?php if ($schoolLogo): ?>
                            <img src="<?php echo $schoolLogo; ?>" alt="شعار المدرسة">
                        <?php else: ?>
                            <svg width="85" height="85" viewBox="0 0 100 100" fill="none" xmlns="http://www.w3.org/2000/svg" aria-hidden="true">
                                <circle cx="50" cy="50" r="45" stroke="#1e3a8a" stroke-width="4" fill="#f8fafc"/>
                                <path d="M50 20L25 40H75L50 20Z" fill="#1e3a8a"/>
                                <rect x="30" y="40" width="40" height="35" fill="#1e3a8a"/>
                                <rect x="42" y="55" width="16" height="20" fill="#f8fafc"/>
                            </svg>
                        <?php endif; ?>
                    </div>
                    <div class="budget-header-meta">
                        <div data-budget-meta="academicYear"><span data-budget-ar="العام الدراسي: " data-budget-en="Academic year: ">العام الدراسي: </span><span data-budget-field="academicYear"><?php echo htmlspecialchars($displayAcademicYear, ENT_QUOTES, 'UTF-8'); ?></span></div>
                        <div data-budget-meta="printDate"><span data-budget-ar="تحريرا في: " data-budget-en="Edited on: ">تحريرا في: </span><span data-budget-field="printDate" data-budget-value="<?php echo htmlspecialchars($printDateValue, ENT_QUOTES, 'UTF-8'); ?>"><?php echo htmlspecialchars($printDateDisplay, ENT_QUOTES, 'UTF-8'); ?></span></div>
                    </div>
                    <div class="budget-header-title">
                        <div class="budget-report-subtitle" data-budget-field="subtitle"></div>
                        <div class="budget-report-title" data-budget-field="title" data-budget-title-underline="true">تقرير ميزانية الفصول وتوزيع أعداد الطلاب التفصيلي</div>
                    </div>
                </div>
            </div>

            <div class="admin-list-surface mb-4 budget-report-table-container">
                <div class="table-responsive admin-table-wrap">
                    <table class="table admin-data-table" id="budgetTable" style="width: 100%;">
                        <colgroup>
                            <col class="budget-col-index">
                            <col class="budget-col-stage">
                            <col class="budget-col-grade">
                            <col class="budget-col-class">
                            <col class="budget-col-male">
                            <col class="budget-col-female">
                            <col class="budget-col-total">
                        </colgroup>
                        <thead>
                            <tr>
                            <th style="width: 50px;">#</th>
                            <th>المرحلة</th>
                            <th>الصف</th>
                            <th>الفصل</th>
                            <th class="text-center">بنين</th>
                            <th class="text-center">بنات</th>
                            <th class="text-center">إجمالي الطلاب</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php 
                        $index = 1;
                        foreach ($rows as $row): 
                        ?>
                            <tr data-budget-stage="<?php echo htmlspecialchars($row['stage_name'], ENT_QUOTES, 'UTF-8'); ?>" data-budget-grade="<?php echo htmlspecialchars($row['grade_name'], ENT_QUOTES, 'UTF-8'); ?>" data-budget-class="<?php echo htmlspecialchars($row['class_name'], ENT_QUOTES, 'UTF-8'); ?>">
                                <td><?php echo $index++; ?></td>
                                <td><?php echo htmlspecialchars($row['stage_name'], ENT_QUOTES, 'UTF-8'); ?></td>
                                <td><span class="fw-semibold text-dark"><?php echo htmlspecialchars($row['grade_name'], ENT_QUOTES, 'UTF-8'); ?></span></td>
                                <td class="fw-bold text-primary"><?php echo htmlspecialchars($row['class_name'], ENT_QUOTES, 'UTF-8'); ?></td>
                                <td class="text-center text-info fw-semibold"><?php echo (int)$row['male_count']; ?></td>
                                <td class="text-center text-danger fw-semibold"><?php echo (int)$row['female_count']; ?></td>
                                <td class="text-center fw-bold text-success"><?php echo (int)$row['total_count']; ?></td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                    <tfoot>
                        <tr class="table-light fw-bold">
                            <td colspan="4" class="text-start">الإجمالي العام:</td>
                            <td class="text-center text-info fs-6" id="totalMales"><?php echo $totalMales; ?></td>
                            <td class="text-center text-danger fs-6" id="totalFemales"><?php echo $totalFemales; ?></td>
                            <td class="text-center text-success fs-6" id="totalStudents"><?php echo $totalStudents; ?></td>
                        </tr>
                    </tfoot>
                </table>
            </div>
            <div class="budget-print-note" data-budget-field="note"></div>
            <div class="budget-official-footer">
                <div data-budget-signature-col="student_affairs" data-budget-signature-visible="true"><span class="budget-footer-label" data-budget-ar="شؤون الطلاب" data-budget-en="Student Affairs">شؤون الطلاب</span><span class="budget-footer-name" data-budget-ar="<?php echo htmlspecialchars($studentAffairsOfficer, ENT_QUOTES, 'UTF-8'); ?>" data-budget-en="<?php echo htmlspecialchars($studentAffairsOfficerEn, ENT_QUOTES, 'UTF-8'); ?>"><?php echo htmlspecialchars($studentAffairsOfficer, ENT_QUOTES, 'UTF-8'); ?></span></div>
                <div data-budget-signature-col="school_director" data-budget-signature-visible="true"><span class="budget-footer-label" data-budget-ar="مدير المدرسة" data-budget-en="School Principal">مدير المدرسة</span><span class="budget-footer-name" data-budget-ar="<?php echo htmlspecialchars($schoolDirector, ENT_QUOTES, 'UTF-8'); ?>" data-budget-en="<?php echo htmlspecialchars($schoolDirectorEn, ENT_QUOTES, 'UTF-8'); ?>"><?php echo htmlspecialchars($schoolDirector, ENT_QUOTES, 'UTF-8'); ?></span></div>
                <div data-budget-signature-col="admin_director" data-budget-signature-visible="false"><span class="budget-footer-label" data-budget-ar="المدير الإداري" data-budget-en="Administrative Director">المدير الإداري</span><span class="budget-footer-name" data-budget-ar="<?php echo htmlspecialchars($adminDirector, ENT_QUOTES, 'UTF-8'); ?>" data-budget-en="<?php echo htmlspecialchars($adminDirectorEn, ENT_QUOTES, 'UTF-8'); ?>"><?php echo htmlspecialchars($adminDirector, ENT_QUOTES, 'UTF-8'); ?></span></div>
                <div data-budget-signature-col="stage_kg" data-budget-signature-visible="false"><span class="budget-footer-label" data-budget-ar="مديرة المرحلة" data-budget-en="Stage Director">مديرة المرحلة</span><span class="budget-footer-name" data-budget-ar="<?php echo htmlspecialchars($kgStageDirector, ENT_QUOTES, 'UTF-8'); ?>" data-budget-en="<?php echo htmlspecialchars($kgStageDirectorEn, ENT_QUOTES, 'UTF-8'); ?>"><?php echo htmlspecialchars($kgStageDirector, ENT_QUOTES, 'UTF-8'); ?></span></div>
                <div data-budget-signature-col="stage_primary" data-budget-signature-visible="false"><span class="budget-footer-label" data-budget-ar="مديرة المرحلة" data-budget-en="Stage Director">مديرة المرحلة</span><span class="budget-footer-name" data-budget-ar="<?php echo htmlspecialchars($primaryStageDirector, ENT_QUOTES, 'UTF-8'); ?>" data-budget-en="<?php echo htmlspecialchars($primaryStageDirectorEn, ENT_QUOTES, 'UTF-8'); ?>"><?php echo htmlspecialchars($primaryStageDirector, ENT_QUOTES, 'UTF-8'); ?></span></div>
                <div data-budget-signature-col="stage_prep_sec" data-budget-signature-visible="false"><span class="budget-footer-label" data-budget-ar="مديرة المرحلة" data-budget-en="Stage Director">مديرة المرحلة</span><span class="budget-footer-name" data-budget-ar="<?php echo htmlspecialchars($prepSecStageDirector, ENT_QUOTES, 'UTF-8'); ?>" data-budget-en="<?php echo htmlspecialchars($prepSecStageDirectorEn, ENT_QUOTES, 'UTF-8'); ?>"><?php echo htmlspecialchars($prepSecStageDirector, ENT_QUOTES, 'UTF-8'); ?></span></div>
            </div>
        </div>
    </div>
</div>

    <!-- 2) التبويب الثاني: إحصاء الطلاب بالزيادة (10%) -->
    <div class="tab-pane fade" id="buffer-pane" role="tabpanel" aria-labelledby="buffer-tab">
        <div class="admin-filter-bar mb-3 no-print">
            <div class="admin-filter-controls d-flex flex-wrap gap-2 align-items-center">
                <?php $renderBudgetMultiFilter('bufferStageFilter', 'المراحل', 'buffer', 'stage', $budgetStageFilterOptions, 'الكل'); ?>
                <?php $renderBudgetMultiFilter('bufferGradeFilter', 'الصفوف', 'buffer', 'grade', $budgetGradeFilterOptions, 'الكل'); ?>
                <?php $renderBudgetMultiFilter('bufferClassFilter', 'الفصول', 'buffer', 'class', $budgetClassFilterOptions, 'الكل'); ?>
            </div>
            <div class="admin-filter-actions">
                <button type="button" class="btn btn-light btn-sm" id="resetBufferFilters">
                    <i class="fas fa-undo me-1"></i>إعادة تعيين
                </button>
                <button type="button" class="btn btn-light btn-sm" data-budget-print-settings="buffer">
                    <i class="fas fa-print me-1"></i>إعدادات الطباعة
                </button>
                <button type="button" class="btn btn-light btn-sm" data-bs-toggle="modal" data-bs-target="#settingsModal_buffer">
                    <i class="fas fa-cog me-1"></i>إعدادات الجدول
                </button>
            </div>
        </div>
        <div class="report-paper-sheet shadow-sm budget-editable-paper" data-budget-paper="buffer" data-print-orientation="portrait" data-print-margin="normal" data-table-density="normal" data-signature-mode="titles_names" data-show-header="true" data-show-meta="true" data-show-note="false" contenteditable="true" role="textbox" aria-multiline="true" aria-label="ورقة إحصاء الزيادة قابلة للتعديل" spellcheck="true">
            <!-- ترويسة الورقة الرسمية للتبويب الثاني -->
            <div class="report-sheet-header text-center mb-4">
                <div class="budget-official-header">
                    <div class="budget-header-school">
                        <div data-budget-ar="وزارة التربية والتعليم والتعليم الفني" data-budget-en="Ministry of Education and Technical Education">وزارة التربية والتعليم والتعليم الفني</div>
                        <div data-budget-ar="<?php echo htmlspecialchars($directorate, ENT_QUOTES, 'UTF-8'); ?>" data-budget-en="<?php echo htmlspecialchars($directorateEn, ENT_QUOTES, 'UTF-8'); ?>"><?php echo htmlspecialchars($directorate, ENT_QUOTES, 'UTF-8'); ?></div>
                        <div data-budget-ar="<?php echo htmlspecialchars($administration, ENT_QUOTES, 'UTF-8'); ?>" data-budget-en="<?php echo htmlspecialchars($administrationEn, ENT_QUOTES, 'UTF-8'); ?>"><?php echo htmlspecialchars($administration, ENT_QUOTES, 'UTF-8'); ?></div>
                        <div data-budget-ar="<?php echo htmlspecialchars($schoolName, ENT_QUOTES, 'UTF-8'); ?>" data-budget-en="<?php echo htmlspecialchars($schoolNameEn, ENT_QUOTES, 'UTF-8'); ?>"><?php echo htmlspecialchars($schoolName, ENT_QUOTES, 'UTF-8'); ?></div>
                    </div>
                    <div class="budget-header-logo" aria-label="شعار المدرسة">
                        <?php if ($schoolLogo): ?>
                            <img src="<?php echo $schoolLogo; ?>" alt="شعار المدرسة">
                        <?php else: ?>
                            <svg width="85" height="85" viewBox="0 0 100 100" fill="none" xmlns="http://www.w3.org/2000/svg" aria-hidden="true">
                                <circle cx="50" cy="50" r="45" stroke="#1e3a8a" stroke-width="4" fill="#f8fafc"/>
                                <path d="M50 20L25 40H75L50 20Z" fill="#1e3a8a"/>
                                <rect x="30" y="40" width="40" height="35" fill="#1e3a8a"/>
                                <rect x="42" y="55" width="16" height="20" fill="#f8fafc"/>
                            </svg>
                        <?php endif; ?>
                    </div>
                    <div class="budget-header-meta">
                        <div data-budget-meta="academicYear"><span data-budget-ar="العام الدراسي: " data-budget-en="Academic year: ">العام الدراسي: </span><span data-budget-field="academicYear"><?php echo htmlspecialchars($displayAcademicYear, ENT_QUOTES, 'UTF-8'); ?></span></div>
                        <div data-budget-meta="printDate"><span data-budget-ar="تحريرا في: " data-budget-en="Edited on: ">تحريرا في: </span><span data-budget-field="printDate" data-budget-value="<?php echo htmlspecialchars($printDateValue, ENT_QUOTES, 'UTF-8'); ?>"><?php echo htmlspecialchars($printDateDisplay, ENT_QUOTES, 'UTF-8'); ?></span></div>
                    </div>
                    <div class="budget-header-title">
                        <div class="budget-report-subtitle" data-budget-field="subtitle"></div>
                        <div class="budget-report-title" data-budget-field="title" data-budget-title-underline="true">تقرير القدرة الاستيعابية للصفوف والزيادة المستقبلية المقترحة 10%</div>
                    </div>
                </div>
            </div>

            <div class="admin-list-surface mb-4 budget-report-table-container">
                <div class="table-responsive admin-table-wrap">
                    <table class="table admin-data-table" id="bufferTable" style="width: 100%;">
                        <thead>
                            <tr>
                            <th style="width: 60px;">#</th>
                            <th>المرحلة الدراسية</th>
                            <th class="text-center" style="width: 200px;">عدد الطلاب</th>
                            <th class="text-center" style="width: 250px;">باضافة نسبة 10%</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php 
                        $bIndex = 1;
                        $totalBufferStudents = 0;
                        $totalBufferPlusTen = 0;
                        foreach ($bufferRows as $brow): 
                            $count = (int)$brow['student_count'];
                            $plusTen = (int)ceil($count * 1.10);
                            $totalBufferStudents += $count;
                            $totalBufferPlusTen += $plusTen;
                        ?>
                            <tr data-budget-stage="<?php echo htmlspecialchars($brow['stage_name'], ENT_QUOTES, 'UTF-8'); ?>" data-budget-grade="<?php echo htmlspecialchars($brow['grade_name'], ENT_QUOTES, 'UTF-8'); ?>" data-budget-grade-id="<?php echo (int)$brow['grade_id']; ?>" data-budget-base-count="<?php echo $count; ?>" data-budget-class-counts="<?php echo htmlspecialchars(json_encode($bufferClassCounts[(int)$brow['grade_id']] ?? [], JSON_UNESCAPED_UNICODE), ENT_QUOTES, 'UTF-8'); ?>">
                                <td><?php echo $bIndex++; ?></td>
                                <td class="fw-bold text-dark text-start ps-4"><?php echo htmlspecialchars($brow['grade_name'], ENT_QUOTES, 'UTF-8'); ?></td>
                                <td class="text-center fw-bold text-primary fs-6"><?php echo $count; ?></td>
                                <td class="text-center fw-bold text-success fs-6"><?php echo $plusTen; ?></td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                    <tfoot>
                        <tr class="table-light fw-bold">
                            <td colspan="2" class="text-start ps-4">الإجمالي العام:</td>
                            <td class="text-center text-primary fs-5" id="totalBufferStudents"><?php echo $totalBufferStudents; ?></td>
                            <td class="text-center text-success fs-5" id="totalBufferPlusTen"><?php echo $totalBufferPlusTen; ?></td>
                        </tr>
                    </tfoot>
                </table>
            </div>
            <div class="budget-print-note" data-budget-field="note"></div>
            <div class="budget-official-footer">
                <div data-budget-signature-col="student_affairs" data-budget-signature-visible="true"><span class="budget-footer-label" data-budget-ar="شؤون الطلاب" data-budget-en="Student Affairs">شؤون الطلاب</span><span class="budget-footer-name" data-budget-ar="<?php echo htmlspecialchars($studentAffairsOfficer, ENT_QUOTES, 'UTF-8'); ?>" data-budget-en="<?php echo htmlspecialchars($studentAffairsOfficerEn, ENT_QUOTES, 'UTF-8'); ?>"><?php echo htmlspecialchars($studentAffairsOfficer, ENT_QUOTES, 'UTF-8'); ?></span></div>
                <div data-budget-signature-col="school_director" data-budget-signature-visible="true"><span class="budget-footer-label" data-budget-ar="مدير المدرسة" data-budget-en="School Principal">مدير المدرسة</span><span class="budget-footer-name" data-budget-ar="<?php echo htmlspecialchars($schoolDirector, ENT_QUOTES, 'UTF-8'); ?>" data-budget-en="<?php echo htmlspecialchars($schoolDirectorEn, ENT_QUOTES, 'UTF-8'); ?>"><?php echo htmlspecialchars($schoolDirector, ENT_QUOTES, 'UTF-8'); ?></span></div>
                <div data-budget-signature-col="admin_director" data-budget-signature-visible="false"><span class="budget-footer-label" data-budget-ar="المدير الإداري" data-budget-en="Administrative Director">المدير الإداري</span><span class="budget-footer-name" data-budget-ar="<?php echo htmlspecialchars($adminDirector, ENT_QUOTES, 'UTF-8'); ?>" data-budget-en="<?php echo htmlspecialchars($adminDirectorEn, ENT_QUOTES, 'UTF-8'); ?>"><?php echo htmlspecialchars($adminDirector, ENT_QUOTES, 'UTF-8'); ?></span></div>
                <div data-budget-signature-col="stage_kg" data-budget-signature-visible="false"><span class="budget-footer-label" data-budget-ar="مديرة المرحلة" data-budget-en="Stage Director">مديرة المرحلة</span><span class="budget-footer-name" data-budget-ar="<?php echo htmlspecialchars($kgStageDirector, ENT_QUOTES, 'UTF-8'); ?>" data-budget-en="<?php echo htmlspecialchars($kgStageDirectorEn, ENT_QUOTES, 'UTF-8'); ?>"><?php echo htmlspecialchars($kgStageDirector, ENT_QUOTES, 'UTF-8'); ?></span></div>
                <div data-budget-signature-col="stage_primary" data-budget-signature-visible="false"><span class="budget-footer-label" data-budget-ar="مديرة المرحلة" data-budget-en="Stage Director">مديرة المرحلة</span><span class="budget-footer-name" data-budget-ar="<?php echo htmlspecialchars($primaryStageDirector, ENT_QUOTES, 'UTF-8'); ?>" data-budget-en="<?php echo htmlspecialchars($primaryStageDirectorEn, ENT_QUOTES, 'UTF-8'); ?>"><?php echo htmlspecialchars($primaryStageDirector, ENT_QUOTES, 'UTF-8'); ?></span></div>
                <div data-budget-signature-col="stage_prep_sec" data-budget-signature-visible="false"><span class="budget-footer-label" data-budget-ar="مديرة المرحلة" data-budget-en="Stage Director">مديرة المرحلة</span><span class="budget-footer-name" data-budget-ar="<?php echo htmlspecialchars($prepSecStageDirector, ENT_QUOTES, 'UTF-8'); ?>" data-budget-en="<?php echo htmlspecialchars($prepSecStageDirectorEn, ENT_QUOTES, 'UTF-8'); ?>"><?php echo htmlspecialchars($prepSecStageDirector, ENT_QUOTES, 'UTF-8'); ?></span></div>
            </div>
        </div>
    </div>
</div>

    <!-- 3) التبويب الثالث: إحصاء الطلاب التاريخي (سنوي / صفوف) -->
    <div class="tab-pane fade" id="historical-pane" role="tabpanel" aria-labelledby="historical-tab">
        <div class="admin-filter-bar mb-3 no-print">
            <div class="admin-filter-controls d-flex flex-wrap gap-2 align-items-center">
                <?php $renderBudgetMultiFilter('historicalStageFilter', 'المراحل', 'historical', 'stage', $historicalStages, 'الكل'); ?>
                <?php $renderBudgetMultiFilter('historicalGradeFilter', 'الصفوف', 'historical', 'grade', $historicalGradeFilterOptions, 'الكل'); ?>
                <?php $renderBudgetMultiFilter('historicalClassFilter', 'الفصول', 'historical', 'class', $historicalClassFilterOptions, 'الكل'); ?>
            </div>
            <div class="admin-filter-actions">
                <button type="button" class="btn btn-light btn-sm" id="resetHistoricalFilters">
                    <i class="fas fa-undo me-1"></i>إعادة تعيين
                </button>
                <button type="button" class="btn btn-light btn-sm" data-budget-print-settings="historical">
                    <i class="fas fa-print me-1"></i>إعدادات الطباعة
                </button>
                <button type="button" class="btn btn-light btn-sm" data-bs-toggle="modal" data-bs-target="#settingsModal_historical">
                    <i class="fas fa-cog me-1"></i>إعدادات الجدول
                </button>
            </div>
        </div>
        <div class="report-paper-sheet shadow-sm budget-editable-paper" data-budget-paper="historical" data-print-orientation="landscape" data-print-margin="normal" data-table-density="normal" data-signature-mode="titles_names" data-show-header="true" data-show-meta="true" data-show-note="false" contenteditable="true" role="textbox" aria-multiline="true" aria-label="ورقة الإحصاء التاريخي قابلة للتعديل" spellcheck="true">
            <!-- ترويسة الورقة الرسمية للتبويب الثالث -->
            <div class="report-sheet-header text-center mb-4">
                <div class="budget-official-header">
                    <div class="budget-header-school">
                        <div data-budget-ar="وزارة التربية والتعليم والتعليم الفني" data-budget-en="Ministry of Education and Technical Education">وزارة التربية والتعليم والتعليم الفني</div>
                        <div data-budget-ar="<?php echo htmlspecialchars($directorate, ENT_QUOTES, 'UTF-8'); ?>" data-budget-en="<?php echo htmlspecialchars($directorateEn, ENT_QUOTES, 'UTF-8'); ?>"><?php echo htmlspecialchars($directorate, ENT_QUOTES, 'UTF-8'); ?></div>
                        <div data-budget-ar="<?php echo htmlspecialchars($administration, ENT_QUOTES, 'UTF-8'); ?>" data-budget-en="<?php echo htmlspecialchars($administrationEn, ENT_QUOTES, 'UTF-8'); ?>"><?php echo htmlspecialchars($administration, ENT_QUOTES, 'UTF-8'); ?></div>
                        <div data-budget-ar="<?php echo htmlspecialchars($schoolName, ENT_QUOTES, 'UTF-8'); ?>" data-budget-en="<?php echo htmlspecialchars($schoolNameEn, ENT_QUOTES, 'UTF-8'); ?>"><?php echo htmlspecialchars($schoolName, ENT_QUOTES, 'UTF-8'); ?></div>
                    </div>
                    <div class="budget-header-logo" aria-label="شعار المدرسة">
                        <?php if ($schoolLogo): ?>
                            <img src="<?php echo $schoolLogo; ?>" alt="شعار المدرسة">
                        <?php else: ?>
                            <svg width="85" height="85" viewBox="0 0 100 100" fill="none" xmlns="http://www.w3.org/2000/svg" aria-hidden="true">
                                <circle cx="50" cy="50" r="45" stroke="#1e3a8a" stroke-width="4" fill="#f8fafc"/>
                                <path d="M50 20L25 40H75L50 20Z" fill="#1e3a8a"/>
                                <rect x="30" y="40" width="40" height="35" fill="#1e3a8a"/>
                                <rect x="42" y="55" width="16" height="20" fill="#f8fafc"/>
                            </svg>
                        <?php endif; ?>
                    </div>
                    <div class="budget-header-meta">
                        <div data-budget-meta="academicYear"><span data-budget-ar="العام الدراسي: " data-budget-en="Academic year: ">العام الدراسي: </span><span data-budget-field="academicYear"><?php echo htmlspecialchars($displayAcademicYear, ENT_QUOTES, 'UTF-8'); ?></span></div>
                        <div data-budget-meta="printDate"><span data-budget-ar="تحريرا في: " data-budget-en="Edited on: ">تحريرا في: </span><span data-budget-field="printDate" data-budget-value="<?php echo htmlspecialchars($printDateValue, ENT_QUOTES, 'UTF-8'); ?>"><?php echo htmlspecialchars($printDateDisplay, ENT_QUOTES, 'UTF-8'); ?></span></div>
                    </div>
                    <div class="budget-header-title">
                        <div class="budget-report-subtitle" data-budget-field="subtitle"></div>
                        <div class="budget-report-title" data-budget-field="title" data-budget-title-underline="true">بيان تدرج أعداد الطلاب للسنوات الدراسية التاريخية</div>
                    </div>
                </div>
            </div>

            <div class="admin-list-surface mb-4 budget-report-table-container">
                <div class="table-responsive admin-table-wrap">
                    <table class="table admin-data-table" id="historicalTable" style="width: 100%;">
                        <thead>
                            <tr>
                            <th>العام الدراسي</th>
                            <?php foreach ($allActiveGrades as $g): ?>
                                <th class="text-center" data-budget-grade-id="<?php echo (int)$g['id']; ?>" data-budget-stage="<?php echo htmlspecialchars($g['stage_name'], ENT_QUOTES, 'UTF-8'); ?>"><?php echo htmlspecialchars($g['grade_name'], ENT_QUOTES, 'UTF-8'); ?></th>
                            <?php endforeach; ?>
                            <th class="text-center text-success bg-light-subtle">الإجمالي العام</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php 
                        $gradeTotals = array_fill_keys(array_column($allActiveGrades, 'id'), 0);
                        $grandTotalAllYears = 0;
                        foreach ($years as $y): 
                            $yearId = $y['id'];
                            $rowTotal = 0;
                        ?>
                            <tr>
                                <td class="fw-bold text-dark text-start ps-3"><?php echo htmlspecialchars($y['name'], ENT_QUOTES, 'UTF-8'); ?></td>
                                <?php 
                                foreach ($allActiveGrades as $g): 
                                    $gradeId = $g['id'];
                                    $count = $historicalMatrix[$yearId][$gradeId] ?? 0;
                                    $classCountsForCell = $historicalClassMatrix[$yearId][$gradeId] ?? [];
                                    $rowTotal += $count;
                                    $gradeTotals[$gradeId] += $count;
                                ?>
                                    <td class="text-center fw-bold text-secondary" data-budget-base-count="<?php echo $count; ?>" data-budget-class-counts="<?php echo htmlspecialchars(json_encode($classCountsForCell, JSON_UNESCAPED_UNICODE), ENT_QUOTES, 'UTF-8'); ?>"><?php echo $count; ?></td>
                                <?php endforeach; ?>
                                <td class="text-center fw-bold text-success bg-light-subtle fs-6"><?php echo $rowTotal; ?></td>
                            </tr>
                        <?php 
                            $grandTotalAllYears += $rowTotal;
                        endforeach; 
                        ?>
                    </tbody>
                    <tfoot>
                        <tr class="table-light fw-bold">
                            <td>الإجمالي العام:</td>
                            <?php foreach ($allActiveGrades as $g): ?>
                                <td class="text-center text-primary" id="totalHistGrade_<?php echo $g['id']; ?>"><?php echo $gradeTotals[$g['id']]; ?></td>
                            <?php endforeach; ?>
                            <td class="text-center text-success fs-5 bg-light-subtle" id="grandTotalAllYears"><?php echo $grandTotalAllYears; ?></td>
                        </tr>
                    </tfoot>
                </table>
            </div>
            <div class="budget-print-note" data-budget-field="note"></div>
            <div class="budget-official-footer">
                <div data-budget-signature-col="student_affairs" data-budget-signature-visible="true"><span class="budget-footer-label" data-budget-ar="شؤون الطلاب" data-budget-en="Student Affairs">شؤون الطلاب</span><span class="budget-footer-name" data-budget-ar="<?php echo htmlspecialchars($studentAffairsOfficer, ENT_QUOTES, 'UTF-8'); ?>" data-budget-en="<?php echo htmlspecialchars($studentAffairsOfficerEn, ENT_QUOTES, 'UTF-8'); ?>"><?php echo htmlspecialchars($studentAffairsOfficer, ENT_QUOTES, 'UTF-8'); ?></span></div>
                <div data-budget-signature-col="school_director" data-budget-signature-visible="true"><span class="budget-footer-label" data-budget-ar="مدير المدرسة" data-budget-en="School Principal">مدير المدرسة</span><span class="budget-footer-name" data-budget-ar="<?php echo htmlspecialchars($schoolDirector, ENT_QUOTES, 'UTF-8'); ?>" data-budget-en="<?php echo htmlspecialchars($schoolDirectorEn, ENT_QUOTES, 'UTF-8'); ?>"><?php echo htmlspecialchars($schoolDirector, ENT_QUOTES, 'UTF-8'); ?></span></div>
                <div data-budget-signature-col="admin_director" data-budget-signature-visible="false"><span class="budget-footer-label" data-budget-ar="المدير الإداري" data-budget-en="Administrative Director">المدير الإداري</span><span class="budget-footer-name" data-budget-ar="<?php echo htmlspecialchars($adminDirector, ENT_QUOTES, 'UTF-8'); ?>" data-budget-en="<?php echo htmlspecialchars($adminDirectorEn, ENT_QUOTES, 'UTF-8'); ?>"><?php echo htmlspecialchars($adminDirector, ENT_QUOTES, 'UTF-8'); ?></span></div>
                <div data-budget-signature-col="stage_kg" data-budget-signature-visible="false"><span class="budget-footer-label" data-budget-ar="مديرة المرحلة" data-budget-en="Stage Director">مديرة المرحلة</span><span class="budget-footer-name" data-budget-ar="<?php echo htmlspecialchars($kgStageDirector, ENT_QUOTES, 'UTF-8'); ?>" data-budget-en="<?php echo htmlspecialchars($kgStageDirectorEn, ENT_QUOTES, 'UTF-8'); ?>"><?php echo htmlspecialchars($kgStageDirector, ENT_QUOTES, 'UTF-8'); ?></span></div>
                <div data-budget-signature-col="stage_primary" data-budget-signature-visible="false"><span class="budget-footer-label" data-budget-ar="مديرة المرحلة" data-budget-en="Stage Director">مديرة المرحلة</span><span class="budget-footer-name" data-budget-ar="<?php echo htmlspecialchars($primaryStageDirector, ENT_QUOTES, 'UTF-8'); ?>" data-budget-en="<?php echo htmlspecialchars($primaryStageDirectorEn, ENT_QUOTES, 'UTF-8'); ?>"><?php echo htmlspecialchars($primaryStageDirector, ENT_QUOTES, 'UTF-8'); ?></span></div>
                <div data-budget-signature-col="stage_prep_sec" data-budget-signature-visible="false"><span class="budget-footer-label" data-budget-ar="مديرة المرحلة" data-budget-en="Stage Director">مديرة المرحلة</span><span class="budget-footer-name" data-budget-ar="<?php echo htmlspecialchars($prepSecStageDirector, ENT_QUOTES, 'UTF-8'); ?>" data-budget-en="<?php echo htmlspecialchars($prepSecStageDirectorEn, ENT_QUOTES, 'UTF-8'); ?>"><?php echo htmlspecialchars($prepSecStageDirector, ENT_QUOTES, 'UTF-8'); ?></span></div>
            </div>
        </div>
    </div>
</div>
</div>

<!-- ==========================================================================
   نوافذ الإعدادات المنبثقة لكل جدول (Settings Modals)
   ========================================================================== -->

<!-- إعدادات ورقة الطباعة: تحفظ لكل تبويب منفرداً ولا تختلط بين التقارير -->
<div class="modal fade no-print" id="budgetPrintSettingsModal" tabindex="-1" aria-labelledby="budgetPrintSettingsTitle" aria-hidden="true" style="text-align: right;">
    <div class="modal-dialog modal-lg modal-dialog-centered">
        <div class="modal-content admin-modal admin-modal-premium">
            <div class="modal-header d-flex justify-content-between align-items-center">
                <h5 class="modal-title" id="budgetPrintSettingsTitle"><i class="fas fa-cog me-2"></i>إعدادات وتخصيص مستند الطباعة</h5>
                <button type="button" class="btn-close ms-0" data-bs-dismiss="modal" aria-label="إغلاق"></button>
            </div>
            <div class="modal-body p-4">
                <div id="budgetLivePreviewStatus" class="small text-primary mb-3" role="status" aria-live="polite">
                    <i class="fas fa-eye me-1"></i>المعاينة الحية مفعّلة — التغييرات ظاهرة الآن ولم تُحفظ بعد.
                </div>
                <div class="row g-4">
                    <div class="col-md-6 border-end-md pe-md-4">
                        <h6 class="fw-bold text-primary mb-3"><i class="fas fa-sliders-h me-2"></i>عناصر وخيارات التقرير</h6>

                        <div class="mb-3">
                            <label class="form-label fw-bold text-secondary" for="budgetPrintOrientation">اتجاه ورقة A4</label>
                            <select class="form-select border shadow-sm" id="budgetPrintOrientation">
                                <option value="auto">تلقائي حسب عرض التقرير</option>
                                <option value="portrait">عمودي Portrait</option>
                                <option value="landscape">أفقي Landscape</option>
                            </select>
                        </div>
                        <div class="mb-3">
                            <label class="form-label fw-bold text-secondary" for="budgetPrintMargin">هوامش الورقة</label>
                            <select class="form-select border shadow-sm" id="budgetPrintMargin">
                                <option value="narrow">ضيقة — مساحة أكبر للجدول</option>
                                <option value="normal">عادية — مناسبة للتقارير الرسمية</option>
                                <option value="wide">واسعة — مساحة تنفس أكبر</option>
                            </select>
                        </div>
                        <div class="mb-3">
                            <label class="form-label fw-bold text-secondary" for="budgetTableDensity">كثافة الجدول</label>
                            <select class="form-select border shadow-sm" id="budgetTableDensity">
                                <option value="compact">مضغوط — مناسب للمصفوفات الطويلة</option>
                                <option value="normal">متوازن</option>
                                <option value="comfortable">مريح للقراءة</option>
                            </select>
                        </div>
                        <div class="form-check form-switch mb-3">
                            <input class="form-check-input" type="checkbox" id="budgetShowHeader" checked>
                            <label class="form-check-label fw-bold ms-2" for="budgetShowHeader">عرض ترويسة المدرسة الرسمية</label>
                        </div>
                        <div class="form-check form-switch mb-3">
                            <input class="form-check-input" type="checkbox" id="budgetShowLogo" checked>
                            <label class="form-check-label fw-bold ms-2" for="budgetShowLogo">عرض شعار المدرسة</label>
                        </div>
                        <div class="form-check form-switch mb-3">
                            <input class="form-check-input" type="checkbox" id="budgetShowBorder">
                            <label class="form-check-label fw-bold ms-2" for="budgetShowBorder">عرض إطار مزخرف للمستند</label>
                        </div>
                        <div class="mb-3">
                            <label class="form-label fw-bold text-secondary" for="budgetPrintLanguage">لغة الترويسة الرسمية والفوتر</label>
                            <select class="form-select border shadow-sm" id="budgetPrintLanguage">
                                <option value="ar">العربية</option>
                                <option value="en">English</option>
                            </select>
                        </div>
                    </div>

                    <div class="col-md-6 ps-md-4">
                        <h6 class="fw-bold text-primary mb-3"><i class="fas fa-file-signature me-2"></i>إعدادات التقرير والبيانات</h6>

                        <div class="mb-3">
                            <label class="form-label fw-bold text-secondary" for="budgetSignatureMode">نظام عرض التوقيعات</label>
                            <select class="form-select border shadow-sm" id="budgetSignatureMode">
                                <option value="titles_only">عرض الألقاب فقط (بدون أسماء)</option>
                                <option value="titles_and_names">عرض الألقاب مع الأسماء المعتمدة</option>
                            </select>
                        </div>
                        <div class="mb-3">
                            <div class="form-check form-switch mb-3 budget-signature-master-toggle">
                                <input class="form-check-input" type="checkbox" id="budgetShowSignatures" checked>
                                <label class="form-check-label fw-bold ms-2" for="budgetShowSignatures">عرض حقول التوقيع والختم بالأسفل</label>
                            </div>
                            <label class="form-label fw-bold text-secondary">تحديد المسؤولين الظاهرين بالتوقيع:</label>
                            <div class="border rounded-3 bg-light p-3">
                                <div class="form-check form-switch mb-2">
                                    <input class="form-check-input" type="checkbox" id="budgetShowStudentAffairs" checked>
                                    <label class="form-check-label fw-bold ms-2" for="budgetShowStudentAffairs">توقيع شؤون الطلاب</label>
                                </div>
                                <div class="form-check form-switch mb-2">
                                    <input class="form-check-input" type="checkbox" id="budgetShowSchoolDirector" checked>
                                    <label class="form-check-label fw-bold ms-2" for="budgetShowSchoolDirector">توقيع مدير المدرسة</label>
                                </div>
                                <div class="form-check form-switch mb-0">
                                    <input class="form-check-input" type="checkbox" id="budgetShowAdminDirector">
                                    <label class="form-check-label fw-bold ms-2" for="budgetShowAdminDirector">توقيع المدير الإداري</label>
                                </div>
                                <hr class="my-3">
                                <div class="small fw-bold text-secondary mb-2">مديرات المراحل</div>
                                <div class="form-check form-switch mb-2">
                                    <input class="form-check-input" type="checkbox" id="budgetShowKgDirector">
                                    <label class="form-check-label fw-bold ms-2" for="budgetShowKgDirector">توقيع مديرة المرحلة — رياض الأطفال</label>
                                </div>
                                <div class="form-check form-switch mb-2">
                                    <input class="form-check-input" type="checkbox" id="budgetShowPrimaryDirector">
                                    <label class="form-check-label fw-bold ms-2" for="budgetShowPrimaryDirector">توقيع مديرة المرحلة — المرحلة الابتدائية</label>
                                </div>
                                <div class="form-check form-switch mb-0">
                                    <input class="form-check-input" type="checkbox" id="budgetShowPrepSecDirector">
                                    <label class="form-check-label fw-bold ms-2" for="budgetShowPrepSecDirector">توقيع مديرة المرحلة — الإعدادية والثانوية</label>
                                </div>
                            </div>
                        </div>

                        <div class="mb-3">
                            <label class="form-label fw-bold text-secondary" for="budgetPrintSubtitle">وصف اختياري أسفل اسم المدرسة</label>
                            <input type="text" class="form-control border shadow-sm" id="budgetPrintSubtitle" maxlength="180">
                        </div>
                        <div class="mb-0">
                            <div class="form-check form-switch mb-2">
                                <input class="form-check-input" type="checkbox" id="budgetShowNote">
                                <label class="form-check-label fw-bold ms-2" for="budgetShowNote">عرض الملاحظة أسفل الجدول</label>
                            </div>
                            <label class="form-label fw-bold text-secondary" for="budgetPrintNote">ملاحظات إضافية أسفل التقرير</label>
                            <textarea class="form-control border shadow-sm" id="budgetPrintNote" rows="2" maxlength="500" placeholder="أدخل أي بنود أو ملاحظات إضافية تود ظهورها..."></textarea>
                        </div>
                    </div>
                </div>
            </div>
            <div class="modal-footer justify-content-between">
                <button type="button" class="btn btn-primary" id="budgetApplySettings"><i class="fas fa-check me-1"></i>تطبيق وحفظ</button>
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal"><i class="fas fa-times me-1"></i>إغلاق</button>
            </div>
        </div>
    </div>
</div>

<!-- 1) نافذة إعدادات ميزانية الفصول التفصيلية -->
<div class="modal fade no-print" id="settingsModal_detailed" tabindex="-1" aria-labelledby="settingsModalDetailedTitle" aria-hidden="true">
    <div class="modal-dialog">
        <div class="modal-content admin-modal admin-modal-premium admin-modal-view">
            <div class="modal-header">
                <h5 class="modal-title" id="settingsModalDetailedTitle"><i class="fas fa-cog me-2 text-primary"></i>إعدادات أعمدة ميزانية الفصول</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="إغلاق"></button>
            </div>
            <div class="modal-body">
                <div class="row g-3">
                    <div class="col-6"><div class="form-check"><input class="form-check-input" type="checkbox" id="colStage" checked><label class="form-check-label" for="colStage">المرحلة</label></div></div>
                    <div class="col-6"><div class="form-check"><input class="form-check-input" type="checkbox" id="colGrade" checked><label class="form-check-label" for="colGrade">الصف</label></div></div>
                    <div class="col-6"><div class="form-check"><input class="form-check-input" type="checkbox" id="colClass" checked><label class="form-check-label" for="colClass">الفصل</label></div></div>
                    <div class="col-6"><div class="form-check"><input class="form-check-input" type="checkbox" id="colMale" checked><label class="form-check-label" for="colMale">بنين</label></div></div>
                    <div class="col-6"><div class="form-check"><input class="form-check-input" type="checkbox" id="colFemale" checked><label class="form-check-label" for="colFemale">بنات</label></div></div>
                    <div class="col-6"><div class="form-check"><input class="form-check-input" type="checkbox" id="colTotal" checked><label class="form-check-label" for="colTotal">إجمالي الطلاب</label></div></div>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal"><i class="fas fa-times me-1"></i>إغلاق</button>
            </div>
        </div>
    </div>
</div>

<!-- 2) نافذة إعدادات إحصاء الاستيعاب والزيادة (10%) -->
<div class="modal fade no-print" id="settingsModal_buffer" tabindex="-1" aria-labelledby="settingsModalBufferTitle" aria-hidden="true">
    <div class="modal-dialog">
        <div class="modal-content admin-modal admin-modal-premium admin-modal-view">
            <div class="modal-header">
                <h5 class="modal-title" id="settingsModalBufferTitle"><i class="fas fa-cog me-2 text-primary"></i>إعدادات أعمدة إحصاء الاستيعاب</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="إغلاق"></button>
            </div>
            <div class="modal-body">
                <div class="row g-3">
                    <div class="col-12"><div class="form-check"><input class="form-check-input" type="checkbox" id="colBufferGrade" checked><label class="form-check-label" for="colBufferGrade">المرحلة الدراسية</label></div></div>
                    <div class="col-12"><div class="form-check"><input class="form-check-input" type="checkbox" id="colBufferCount" checked><label class="form-check-label" for="colBufferCount">عدد الطلاب</label></div></div>
                    <div class="col-12"><div class="form-check"><input class="form-check-input" type="checkbox" id="colBufferPlusTen" checked><label class="form-check-label" for="colBufferPlusTen">باضافة نسبة 10%</label></div></div>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal"><i class="fas fa-times me-1"></i>إغلاق</button>
            </div>
        </div>
    </div>
</div>

<!-- 3) نافذة إعدادات مصفوفة الإحصاء التاريخية -->
<div class="modal fade no-print" id="settingsModal_historical" tabindex="-1" aria-labelledby="settingsModalHistoricalTitle" aria-hidden="true">
    <div class="modal-dialog modal-dialog-scrollable">
        <div class="modal-content admin-modal admin-modal-premium admin-modal-view">
            <div class="modal-header">
                <h5 class="modal-title" id="settingsModalHistoricalTitle"><i class="fas fa-cog me-2 text-primary"></i>إعدادات أعمدة المصفوفة التاريخية</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="إغلاق"></button>
            </div>
            <div class="modal-body">
                <div class="row g-3">
                    <div class="col-12">
                        <div class="form-check">
                            <input class="form-check-input" type="checkbox" id="colHistYear" checked>
                            <label class="form-check-label fw-bold" for="colHistYear">العام الدراسي</label>
                        </div>
                    </div>
                    <hr class="my-1">
                    <?php 
                    foreach ($allActiveGrades as $g): 
                    ?>
                        <div class="col-6">
                            <div class="form-check">
                                <input class="form-check-input" type="checkbox" id="colHistGrade_<?php echo $g['id']; ?>" checked>
                                <label class="form-check-label" for="colHistGrade_<?php echo $g['id']; ?>"><?php echo htmlspecialchars($g['grade_name'], ENT_QUOTES, 'UTF-8'); ?></label>
                            </div>
                        </div>
                    <?php 
                    endforeach; 
                    ?>
                    <hr class="my-1">
                    <div class="col-12">
                        <div class="form-check">
                            <input class="form-check-input" type="checkbox" id="colHistTotal" checked>
                            <label class="form-check-label fw-bold" for="colHistTotal">الإجمالي العام</label>
                        </div>
                    </div>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal"><i class="fas fa-times me-1"></i>إغلاق</button>
            </div>
        </div>
    </div>
</div>

<!-- السكربتات المخصصة للملف -->
<script src="../assets/js/admin_table_actions.js?v=<?= (int) @filemtime(__DIR__ . '/../assets/js/admin_table_actions.js') ?>"></script>
<?php
$schoolBudgetFiltersPath = __DIR__ . '/../assets/js/school-budget-filters.js';
$schoolBudgetEditorPath = __DIR__ . '/../assets/js/school-budget-editor.js';
$schoolBudgetFiltersVersion = is_file($schoolBudgetFiltersPath) ? (string)filemtime($schoolBudgetFiltersPath) : '1';
$schoolBudgetEditorVersion = is_file($schoolBudgetEditorPath) ? (string)filemtime($schoolBudgetEditorPath) : '1';
?>
<script src="../assets/js/school-budget-filters.js?v=<?php echo htmlspecialchars($schoolBudgetFiltersVersion, ENT_QUOTES, 'UTF-8'); ?>"></script>
<script src="../assets/js/school-budget-editor.js?v=<?php echo htmlspecialchars($schoolBudgetEditorVersion, ENT_QUOTES, 'UTF-8'); ?>"></script>
<script>
document.addEventListener("DOMContentLoaded", function() {
    // DataTables يضيف قواعد RTL عامة قد تعيد الخلايا إلى اليمين بعد كل إعادة رسم؛
    // نثبت المحاذاة inline مع !important لضمان التوسيط في الشاشة والطباعة.
    function centerBudgetTableCells() {
        document.querySelectorAll('.report-paper-sheet table th, .report-paper-sheet table td').forEach(function (cell) {
            cell.style.setProperty('text-align', 'center', 'important');
            cell.style.setProperty('vertical-align', 'middle', 'important');
        });
    }

    // عناوين الأعمدة جزء من المستند القابل للتحرير وليست أدوات فرز؛ نثبت ذلك بعد كل إعادة رسم.
    function enableBudgetHeaderEditing() {
        document.querySelectorAll('.report-paper-sheet table.admin-data-table thead th').forEach(function (heading) {
            heading.setAttribute('contenteditable', 'true');
            heading.setAttribute('spellcheck', 'true');
            heading.setAttribute('data-budget-editable-heading', 'true');
            heading.setAttribute('data-dt-order', 'disable');
        });
    }

    // 1) تهيئة DataTable للجدول التفصيلي الأول
    var table = $('#budgetTable').DataTable({
        paging: false, // الحفاظ على ظهور كافة البيانات كصفحة واحدة ورقية
        dom: 't', // إظهار الجدول فقط وإخفاء صندوق البحث والترقيم والخيارات الإضافية لتظهر كأوراق تقارير نظيفة
        ordering: false, // عناوين الجدول قابلة للتحرير؛ الفلترة تتم من شريط الفلاتر المخصص.
        language: {
            url: '../assets/js/datatables-ar.json'
        },
        order: [], // الحفاظ على ترتيب العام الدراسي والمراحل القادم من قاعدة البيانات
        drawCallback: function() {
            var api = this.api();
            var intVal = function (i) {
                return typeof i === 'string' ?
                    i.replace(/[\$,]/g, '')*1 :
                    typeof i === 'number' ?
                        i : 0;
            };
            var totalMales = api.column(4, {page: 'all', search: 'applied'}).data().reduce(function (a, b) {
                return intVal(a) + intVal(b);
            }, 0);
            var totalFemales = api.column(5, {page: 'all', search: 'applied'}).data().reduce(function (a, b) {
                return intVal(a) + intVal(b);
            }, 0);
            var totalStudents = api.column(6, {page: 'all', search: 'applied'}).data().reduce(function (a, b) {
                return intVal(a) + intVal(b);
            }, 0);
            
            $('#totalMales').text(totalMales);
            $('#totalFemales').text(totalFemales);
            $('#totalStudents').text(totalStudents);

            // دمج خلايا المرحلة والصف تلقائياً بعد كل رسم للجدول
            mergeTableCells('budgetTable', [1, 2]);
            markBudgetStageBreaks('budgetTable');
            centerBudgetTableCells();
            enableBudgetHeaderEditing();
        }
    });

    // دالة دمج الخلايا المتجاورة رأسياً للـ rowspan التفاعلي
    function mergeTableCells(tableId, colIndexes) {
        var table = $('#' + tableId);
        colIndexes.forEach(function(colIdx) {
            var lastVal = null;
            var firstCell = null;
            var rowspan = 1;
            
            table.find('tbody tr').each(function() {
                var cell = $(this).find('td').eq(colIdx);
                
                // إنشاء مفتاح فريد يضم العمود الحالي وجميع الأعمدة السابقة لمنع التداخل بين المراحل المختلفة
                var keyParts = [];
                for (var i = 1; i <= colIdx; i++) {
                    keyParts.push($(this).find('td').eq(i).text().trim());
                }
                var val = keyParts.join(' || ');
                
                if (lastVal === val) {
                    rowspan++;
                    cell.hide();
                    if (firstCell) {
                        firstCell.attr('rowspan', rowspan);
                    }
                } else {
                    lastVal = val;
                    firstCell = cell;
                    rowspan = 1;
                    cell.show().attr('rowspan', 1);
                }
            });
        });
    }

    // إبراز بداية كل مرحلة بفاصل أسود سميك، ويتجدد بعد كل تصفية للصفوف.
    function markBudgetStageBreaks(tableId) {
        var previousStage = null;
        $('#' + tableId + ' tbody tr').each(function (index) {
            var stage = this.getAttribute('data-budget-stage') || '';
            this.classList.toggle('budget-stage-break', index > 0 && stage !== previousStage);
            previousStage = stage;
        });
    }

    // 2) تهيئة DataTable للجدول الثاني (إحصاء الاستيعاب والزيادة 10%)
    var bufferTable = $('#bufferTable').DataTable({
        paging: false,
        dom: 't',
        ordering: false,
        language: {
            url: '../assets/js/datatables-ar.json'
        },
        // يحافظ على ترتيب الاستعلام (المرحلة ثم الصف) بدلاً من الفرز الأبجدي للصفوف.
        order: [],
        drawCallback: function() {
            var api = this.api();
            var intVal = function (i) {
                return typeof i === 'string' ?
                    i.replace(/[\$,]/g, '')*1 :
                    typeof i === 'number' ?
                        i : 0;
            };
            var totalCount = api.column(2, {page: 'all', search: 'applied'}).data().reduce(function (a, b) {
                return intVal(a) + intVal(b);
            }, 0);
            var totalPlusTen = api.column(3, {page: 'all', search: 'applied'}).data().reduce(function (a, b) {
                return intVal(a) + intVal(b);
            }, 0);
            
            $('#totalBufferStudents').text(totalCount);
            $('#totalBufferPlusTen').text(totalPlusTen);
            markBudgetStageBreaks('bufferTable');
            centerBudgetTableCells();
            enableBudgetHeaderEditing();
        }
    });

    // 3) تهيئة DataTable للجدول الثالث (المصفوفة التاريخية)
    var historicalTable = $('#historicalTable').DataTable({
        paging: false,
        dom: 't',
        ordering: false,
        language: {
            url: '../assets/js/datatables-ar.json'
        },
        order: [[0, 'asc']],
        drawCallback: function() {
            var api = this.api();
            var intVal = function (i) {
                return typeof i === 'string' ?
                    i.replace(/[\$,]/g, '')*1 :
                    typeof i === 'number' ?
                        i : 0;
            };
            
            <?php 
            $colIdx = 1;
            foreach ($allActiveGrades as $g): 
            ?>
                var totalGrade_<?php echo $g['id']; ?> = api.column(<?php echo $colIdx; ?>, {page: 'all', search: 'applied'}).data().reduce(function (a, b) {
                    return intVal(a) + intVal(b);
                }, 0);
                $('#totalHistGrade_<?php echo $g['id']; ?>').text(totalGrade_<?php echo $g['id']; ?>);
            <?php 
                $colIdx++;
            endforeach; 
            ?>
            
            var grandTotalVal = api.column(<?php echo $colIdx; ?>, {page: 'all', search: 'applied'}).data().reduce(function (a, b) {
                return intVal(a) + intVal(b);
            }, 0);
            $('#grandTotalAllYears').text(grandTotalVal);
            centerBudgetTableCells();
            enableBudgetHeaderEditing();
        }
    });

    centerBudgetTableCells();
    enableBudgetHeaderEditing();

    if (typeof window.initializeSchoolBudgetFilters === 'function') {
        window.initializeSchoolBudgetFilters({
            detailed: table,
            buffer: bufferTable,
            historical: historicalTable
        });
    }

    // تهيئة إعدادات أعمدة الجداول الثلاثة تفاعلياً وحفظ خيارات المستخدم
    if (typeof initializeTableColumnSettings === 'function') {
        // 1) إعدادات ميزانية الفصول
        initializeTableColumnSettings('budgetTable', {
            colStage: 1,
            colGrade: 2,
            colClass: 3,
            colMale: 4,
            colFemale: 5,
            colTotal: 6
        }, 'school_budget_detailed_columns');

        // 2) إعدادات إحصاء الاستيعاب والزيادة (10%)
        initializeTableColumnSettings('bufferTable', {
            colBufferGrade: 1,
            colBufferCount: 2,
            colBufferPlusTen: 3
        }, 'school_budget_buffer_columns');

        // 3) إعدادات مصفوفة أعداد الطلاب التاريخية
        var historicalMap = {
            colHistYear: 0
        };
        <?php 
        $jsColIdx = 1;
        foreach ($allActiveGrades as $g): 
        ?>
            historicalMap['colHistGrade_<?php echo $g['id']; ?>'] = <?php echo $jsColIdx++; ?>;
        <?php 
        endforeach; 
        ?>
        historicalMap['colHistTotal'] = <?php echo $jsColIdx; ?>;

        initializeTableColumnSettings('historicalTable', historicalMap, 'school_budget_historical_columns');
    }

    // تطبيق آخر اختيارات محفوظة بعد إنشاء الجداول وإعداد أعمدتها فعلياً.
    window.captureHistoricalFilterBaseline();
    window.applyBudgetFilterState('detailed');
    window.applyBudgetFilterState('buffer');
    window.applyBudgetFilterState('historical');

    // تصدير XLSX حقيقي للتقرير النشط مع كل الأعمدة والصفوف الظاهرة.
    $('#exportBtnTop').on('click', function() {
        var activeTabId = $('.nav-tabs-premium .active').attr('id');
        var targetTableId = 'budgetTable';
        var reportKey = 'detailed';
        var filename = 'student_numbers_detailed';
        
        if (activeTabId === 'buffer-tab') {
            targetTableId = 'bufferTable';
            reportKey = 'buffer';
            filename = 'student_numbers_10_percent';
        } else if (activeTabId === 'historical-tab') {
            targetTableId = 'historicalTable';
            reportKey = 'historical';
            filename = 'student_numbers_historical';
        }

        var button = this;
        var originalHtml = button.innerHTML;
        var activePane = document.querySelector('.tab-pane.show.active');
        var titleElement = activePane ? activePane.querySelector('.budget-report-title') : null;
        var reportTitle = titleElement ? titleElement.textContent.trim() : 'تقرير أعداد الطلاب';
        button.disabled = true;
        button.innerHTML = '<i class="fas fa-spinner fa-spin me-1"></i>جارٍ إنشاء Excel';

        exportTableToXlsx(
            targetTableId,
            filename + '_' + new Date().toISOString().slice(0, 10) + '.xlsx',
            reportTitle,
            {
                endpoint: 'export_student_numbers_report.php',
                reportKey: reportKey,
                excludeLastColumn: false
            }
        ).catch(function(error) {
            window.alert(error && error.message ? error.message : 'تعذر تصدير ملف Excel.');
        }).finally(function() {
            button.disabled = false;
            button.innerHTML = originalHtml;
        });
    });
});
</script>

<?php require_once '../includes/admin_footer.php'; ?>
