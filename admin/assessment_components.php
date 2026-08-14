<?php
$page_title = "بنود التقييم";
$custom_page_title = true;

require_once '../config/database.php';
require_once '../classes/utilities.php';
require_once '../classes/ActivityLog.php';
require_once '../classes/AcademicYear.php';
require_once '../classes/AcademicYearWriteGuard.php';
require_once '../classes/AssessmentEngine.php';
require_once '../classes/AssessmentBulkActionService.php';
require_once '../classes/AssessmentSchemeReadinessService.php';
require_once '../classes/UndoManager.php';
require_once '../src/Modules/Operations/Audit/AuditService.php';
require_once '../includes/session_config.php';
require_once '../includes/csrf.php';
Utilities::validateSession('admin');
requireCsrfPost();

$database = new Database();
$db = $database->getConnection();

$success_message = $_SESSION['success_message'] ?? null;
$error_message = $_SESSION['error_message'] ?? null;
unset($_SESSION['success_message'], $_SESSION['error_message']);

function components_table_exists(PDO $db, string $table): bool
{
    $stmt = $db->prepare('SELECT 1 FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?');
    $stmt->execute([$table]);
    return (bool) $stmt->fetchColumn();
}

function components_column_exists(PDO $db, string $table, string $column): bool
{
    $stmt = $db->prepare('SELECT 1 FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?');
    $stmt->execute([$table, $column]);
    return (bool) $stmt->fetchColumn();
}

function components_redirect(): void
{
    header('Location: assessment_components.php');
    exit();
}

function components_selected($left, $right): string
{
    return (string) $left === (string) $right ? 'selected' : '';
}

function components_checked($value): string
{
    return !empty($value) ? 'checked' : '';
}

function components_count_dependencies(PDO $db, int $componentId): int
{
    $checks = [
        ['assessment_windows', 'component_id'],
        ['student_marks', 'component_id'],
        ['report_window_items', 'component_id'],
        ['published_report_details', 'component_id'],
    ];
    $dependencies = 0;
    foreach ($checks as $check) {
        [$table, $column] = $check;
        if (components_table_exists($db, $table) && components_column_exists($db, $table, $column)) {
            $stmt = $db->prepare("SELECT COUNT(*) FROM `$table` WHERE `$column` = ?");
            $stmt->execute([$componentId]);
            $dependencies += (int) $stmt->fetchColumn();
        }
    }
    return $dependencies;
}

function components_assert_selected_year(?int $currentAcademicYearId, array $row, string $message): void
{
    if ($currentAcademicYearId && (int) ($row['academic_year_id'] ?? 0) !== $currentAcademicYearId) {
        throw new InvalidArgumentException($message);
    }
}

$componentsReady = components_table_exists($db, 'assessment_components');
$schemesReady = components_table_exists($db, 'assessment_schemes');
$componentWeekRulesReady = components_table_exists($db, 'assessment_component_week_rules');
$calendarReady = components_table_exists($db, 'academic_years') && components_table_exists($db, 'academic_terms');

$currentAcademicYear = AcademicYear::getCurrent($db) ?: AcademicYear::getActive($db);
$currentAcademicYearId = $currentAcademicYear ? (int) $currentAcademicYear['id'] : 0;
$currentAcademicYearName = $currentAcademicYear['name'] ?? '';

$componentTypeLabels = [
    'monthly' => 'شهري',
    'weekly' => 'أسبوعي',
    'final' => 'نهائي',
    'practical' => 'عملي',
    'activity' => 'نشاط',
    'behavior' => 'سلوك',
    'custom' => 'مخصص',
];
$calculationModeLabels = [
    'direct' => 'مباشر',
    'average_weeks' => 'متوسط الأسابيع',
    'sum_children' => 'مجموع بنود فرعية',
    'manual' => 'يدوي',
];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        (new AcademicYearWriteGuard($db))->assertWritable($currentAcademicYearId);
        if (!$componentsReady || !$schemesReady) {
            throw new RuntimeException('جداول البنود أو خطط الدرجات غير مطبقة بعد.');
        }

        $action = (string) ($_POST['action'] ?? '');

        if ($action === 'assessment_bulk_action') {
            $result = (new AssessmentBulkActionService($db))->execute(
                'component',
                (string) ($_POST['bulk_operation'] ?? ''),
                AssessmentBulkActionService::normalizeIds($_POST['selected_ids'] ?? ''),
                $currentAcademicYearId
            );
            $_SESSION['success_message'] = $result['message'];
            components_redirect();
        }

        if ($action === 'add_component' || $action === 'update_component') {
            $componentId = (int) ($_POST['component_id'] ?? 0);
            $schemeId = (int) ($_POST['scheme_id'] ?? 0);
            $name = trim((string) ($_POST['name'] ?? ''));
            $componentType = in_array(($_POST['component_type'] ?? 'custom'), array_keys($componentTypeLabels), true) ? (string) $_POST['component_type'] : 'custom';
            $maxGrade = (float) ($_POST['max_grade'] ?? 0);
            $sortOrder = (int) ($_POST['sort_order'] ?? 0);
            $calculationMode = in_array(($_POST['calculation_mode'] ?? 'direct'), array_keys($calculationModeLabels), true) ? (string) $_POST['calculation_mode'] : 'direct';
            $isWeekly = isset($_POST['is_weekly']) ? 1 : 0;
            $repeatPerWeek = isset($_POST['repeat_per_week']) ? 1 : 0;
            $countsInAverage = isset($_POST['counts_in_average']) ? 1 : 0;
            $countsInTotal = isset($_POST['counts_in_total']) ? 1 : 0;
            $visibleToStudent = isset($_POST['visible_to_student']) ? 1 : 0;
            $acceptsAbsence = isset($_POST['accepts_absence']) ? 1 : 0;
            $acceptsExcusedAbsence = isset($_POST['accepts_excused_absence']) ? 1 : 0;

            if (($action === 'update_component' && $componentId <= 0) || $schemeId <= 0 || $name === '') {
                throw new InvalidArgumentException('اختر الخطة واكتب اسم البند.');
            }
            if ($maxGrade < 0 || $maxGrade > 1000) {
                throw new InvalidArgumentException('الدرجة الكبرى للبند غير صحيحة.');
            }
            if ($sortOrder < 0 || $sortOrder > 9999) {
                throw new InvalidArgumentException('ترتيب البند غير صحيح.');
            }

            $db->beginTransaction();
            $batchId = UndoManager::newBatchId();
            $schemeStmt = $db->prepare('SELECT id, name, status, academic_year_id FROM assessment_schemes WHERE id = ? LIMIT 1 FOR UPDATE');
            $schemeStmt->execute([$schemeId]);
            $scheme = $schemeStmt->fetch(PDO::FETCH_ASSOC);
            if (!$scheme) {
                throw new InvalidArgumentException('خطة الدرجات المحددة غير موجودة.');
            }
            components_assert_selected_year($currentAcademicYearId, $scheme, 'لا يمكن حفظ بند تقييم في خطة خارج العام الدراسي المختار.');
            if ((string) ($scheme['status'] ?? '') === 'active') {
                throw new RuntimeException('لا يمكن تعديل بنود خطة نشطة. عطّل الخطة أولا ثم عدّل بنودها وأعد تفعيلها بعد التحقق.');
            }

            if ($action === 'add_component') {
                $stmt = $db->prepare("INSERT INTO assessment_components
                    (scheme_id, name, component_type, max_grade, is_weekly, repeat_per_week,
                     counts_in_average, counts_in_total, visible_to_student, accepts_absence,
                     accepts_excused_absence, sort_order, calculation_mode, is_active)
                    VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 1)");
                $stmt->execute([$schemeId, $name, $componentType, $maxGrade, $isWeekly, $repeatPerWeek, $countsInAverage, $countsInTotal, $visibleToStudent, $acceptsAbsence, $acceptsExcusedAbsence, $sortOrder, $calculationMode]);
                $componentId = (int) $db->lastInsertId();
                $createdStmt = $db->prepare('SELECT * FROM assessment_components WHERE id = ?');
                $createdStmt->execute([$componentId]);
                $createdComponent = $createdStmt->fetch(PDO::FETCH_ASSOC) ?: [];
                (new \EduCore\Modules\Operations\Audit\AuditService($db))->recordInsert(
                    'assessment_component',
                    'assessment_components',
                    $componentId,
                    $name,
                    $createdComponent,
                    'إضافة بند تقييم',
                    $batchId
                );
                (new AssessmentSchemeReadinessService($db))->refresh($schemeId, $batchId, true);
                $db->commit();
                ActivityLog::logCreate('assessment_component', $componentId, $name, [
                    'scheme' => $scheme['name'],
                    'component' => $name,
                    'type' => $componentType,
                    'max_grade' => $maxGrade,
                    'calculation_mode' => $calculationMode,
                ]);
                $_SESSION['success_message'] = 'تم إضافة بند التقييم بنجاح.';
                components_redirect();
            }

            $oldStmt = $db->prepare('SELECT ac.*, sch.name AS scheme_name, sch.status AS scheme_status, sch.academic_year_id FROM assessment_components ac JOIN assessment_schemes sch ON sch.id = ac.scheme_id WHERE ac.id = ? LIMIT 1 FOR UPDATE');
            $oldStmt->execute([$componentId]);
            $oldComponent = $oldStmt->fetch(PDO::FETCH_ASSOC);
            if (!$oldComponent) {
                throw new InvalidArgumentException('بند التقييم غير موجود.');
            }
            components_assert_selected_year($currentAcademicYearId, $oldComponent, 'لا يمكن تعديل بند تقييم خارج العام الدراسي المختار.');
            if ((string) ($oldComponent['scheme_status'] ?? '') === 'active') {
                throw new RuntimeException('لا يمكن نقل أو تعديل بند تابع لخطة نشطة. عطّل الخطة أولا.');
            }
            if (components_count_dependencies($db, $componentId) > 0) {
                throw new RuntimeException('لا يمكن تعديل بند استُخدم في نوافذ رصد أو درجات أو تقارير. أنشئ بندًا أو خطة بديلة للحفاظ على التاريخ.');
            }

            $beforeStmt = $db->prepare('SELECT * FROM assessment_components WHERE id = ?');
            $beforeStmt->execute([$componentId]);
            $beforeComponent = $beforeStmt->fetch(PDO::FETCH_ASSOC) ?: [];
            $stmt = $db->prepare("UPDATE assessment_components
                SET scheme_id = ?, name = ?, component_type = ?, max_grade = ?, is_weekly = ?, repeat_per_week = ?,
                    counts_in_average = ?, counts_in_total = ?, visible_to_student = ?, accepts_absence = ?,
                    accepts_excused_absence = ?, sort_order = ?, calculation_mode = ?
                WHERE id = ?");
            $stmt->execute([$schemeId, $name, $componentType, $maxGrade, $isWeekly, $repeatPerWeek, $countsInAverage, $countsInTotal, $visibleToStudent, $acceptsAbsence, $acceptsExcusedAbsence, $sortOrder, $calculationMode, $componentId]);
            $afterStmt = $db->prepare('SELECT * FROM assessment_components WHERE id = ?');
            $afterStmt->execute([$componentId]);
            $afterComponent = $afterStmt->fetch(PDO::FETCH_ASSOC) ?: [];
            (new \EduCore\Modules\Operations\Audit\AuditService($db))->recordUpdate(
                'assessment_component',
                'assessment_components',
                $componentId,
                $name,
                $beforeComponent,
                $afterComponent,
                'تعديل بند تقييم',
                $batchId
            );
            $readiness = new AssessmentSchemeReadinessService($db);
            $readiness->refresh((int) $oldComponent['scheme_id'], $batchId, true);
            if ((int) $oldComponent['scheme_id'] !== $schemeId) {
                $readiness->refresh($schemeId, $batchId, true);
            }
            $db->commit();
            ActivityLog::logUpdate('assessment_component', $componentId, $name, [
                'old_name' => $oldComponent['name'],
                'new_name' => $name,
                'old_scheme' => $oldComponent['scheme_name'],
                'new_scheme' => $scheme['name'],
                'max_grade' => $maxGrade,
                'calculation_mode' => $calculationMode,
            ]);
            $_SESSION['success_message'] = 'تم تعديل بند التقييم بنجاح.';
            components_redirect();
        }

        if ($action === 'toggle_component_status') {
            $componentId = (int) ($_POST['component_id'] ?? 0);
            $db->beginTransaction();
            $batchId = UndoManager::newBatchId();
            $componentStmt = $db->prepare("SELECT ac.*, sch.name AS scheme_name, sch.status AS scheme_status, sch.academic_year_id
                FROM assessment_components ac
                JOIN assessment_schemes sch ON sch.id = ac.scheme_id
                WHERE ac.id = ?
                LIMIT 1 FOR UPDATE");
            $componentStmt->execute([$componentId]);
            $component = $componentStmt->fetch(PDO::FETCH_ASSOC);
            if (!$component) {
                throw new InvalidArgumentException('بند التقييم غير موجود.');
            }
            components_assert_selected_year($currentAcademicYearId, $component, 'لا يمكن تغيير حالة بند تقييم خارج العام الدراسي المختار.');
            if ((string) ($component['scheme_status'] ?? '') === 'active') {
                throw new RuntimeException('لا يمكن تغيير حالة بند تابع لخطة نشطة. عطّل الخطة أولا.');
            }
            if (components_count_dependencies($db, $componentId) > 0) {
                throw new RuntimeException('لا يمكن تغيير حالة بند له نوافذ رصد أو درجات أو تقارير تاريخية.');
            }
            $newStatus = !empty($component['is_active']) ? 0 : 1;
            $beforeStmt = $db->prepare('SELECT * FROM assessment_components WHERE id = ?');
            $beforeStmt->execute([$componentId]);
            $beforeComponent = $beforeStmt->fetch(PDO::FETCH_ASSOC) ?: [];
            $db->prepare('UPDATE assessment_components SET is_active = ? WHERE id = ?')->execute([$newStatus, $componentId]);
            $afterStmt = $db->prepare('SELECT * FROM assessment_components WHERE id = ?');
            $afterStmt->execute([$componentId]);
            $afterComponent = $afterStmt->fetch(PDO::FETCH_ASSOC) ?: [];
            (new \EduCore\Modules\Operations\Audit\AuditService($db))->recordUpdate(
                'assessment_component',
                'assessment_components',
                $componentId,
                (string) $component['name'],
                $beforeComponent,
                $afterComponent,
                $newStatus ? 'تفعيل بند تقييم' : 'تعطيل بند تقييم',
                $batchId
            );
            (new AssessmentSchemeReadinessService($db))->refresh((int) $component['scheme_id'], $batchId, true);
            $db->commit();
            ActivityLog::logUpdate('assessment_component', $componentId, (string) $component['name'], [
                'scheme' => $component['scheme_name'],
                'old_status' => !empty($component['is_active']) ? 'active' : 'inactive',
                'new_status' => $newStatus ? 'active' : 'inactive',
            ]);
            $_SESSION['success_message'] = $newStatus ? 'تم تفعيل بند التقييم.' : 'تم تعطيل بند التقييم.';
            components_redirect();
        }

        if ($action === 'delete_component') {
            $componentId = (int) ($_POST['component_id'] ?? 0);
            $db->beginTransaction();
            $batchId = UndoManager::newBatchId();
            $componentStmt = $db->prepare("SELECT ac.*, sch.name AS scheme_name, sch.status AS scheme_status, sch.academic_year_id
                FROM assessment_components ac
                JOIN assessment_schemes sch ON sch.id = ac.scheme_id
                WHERE ac.id = ?
                LIMIT 1 FOR UPDATE");
            $componentStmt->execute([$componentId]);
            $component = $componentStmt->fetch(PDO::FETCH_ASSOC);
            if (!$component) {
                throw new InvalidArgumentException('بند التقييم غير موجود.');
            }
            components_assert_selected_year($currentAcademicYearId, $component, 'لا يمكن حذف بند تقييم خارج العام الدراسي المختار.');
            if ((string) ($component['scheme_status'] ?? '') === 'active') {
                throw new RuntimeException('لا يمكن حذف بند تابع لخطة نشطة. عطّل الخطة أولا.');
            }
            if (!empty($component['is_active'])) {
                throw new RuntimeException('لا يمكن حذف بند نشط. عطّله أولا ثم حاول الحذف.');
            }
            if (components_count_dependencies($db, $componentId) > 0) {
                throw new RuntimeException('لا يمكن حذف البند لوجود نوافذ رصد أو درجات أو تقارير مرتبطة به.');
            }
            $beforeStmt = $db->prepare('SELECT * FROM assessment_components WHERE id = ?');
            $beforeStmt->execute([$componentId]);
            $beforeComponent = $beforeStmt->fetch(PDO::FETCH_ASSOC) ?: [];
            (new \EduCore\Modules\Operations\Audit\AuditService($db))->recordDelete(
                'assessment_component',
                'assessment_components',
                $componentId,
                (string) $component['name'],
                $beforeComponent,
                'حذف بند تقييم',
                $batchId
            );
            $db->prepare('DELETE FROM assessment_components WHERE id = ?')->execute([$componentId]);
            (new AssessmentSchemeReadinessService($db))->refresh((int) $component['scheme_id'], $batchId, true);
            $db->commit();
            ActivityLog::logDelete('assessment_component', $componentId, (string) $component['name'], ['scheme' => $component['scheme_name']]);
            $_SESSION['success_message'] = 'تم حذف بند التقييم بنجاح.';
            components_redirect();
        }
    } catch (Throwable $e) {
        if ($db->inTransaction()) {
            $db->rollBack();
        }
        $_SESSION['error_message'] = $e->getMessage();
        components_redirect();
    }
}

$schemes = [];
$components = [];
$componentsCount = 0;
$activeComponentsCount = 0;
$weeklyComponentsCount = 0;
$visibleComponentsCount = 0;

if ($schemesReady) {
    $schemeSql = "SELECT sch.*, t.name AS term_name, s.name AS subject_name, g.grade_name
        FROM assessment_schemes sch
        JOIN academic_terms t ON t.id = sch.term_id
        JOIN subjects s ON s.id = sch.subject_id
        JOIN grades g ON g.id = sch.grade_id
        WHERE sch.status <> 'active'";
    $schemeParams = [];
    if ($currentAcademicYearId > 0) {
        $schemeSql .= ' AND sch.academic_year_id = ?';
        $schemeParams[] = $currentAcademicYearId;
    }
    $schemeSql .= ' ORDER BY t.term_order ASC, s.name, g.grade_order, sch.name';
    $schemeStmt = $db->prepare($schemeSql);
    $schemeStmt->execute($schemeParams);
    $schemes = $schemeStmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
}

if ($componentsReady) {
    $rulesCountSelect = $componentWeekRulesReady ? 'COALESCE(rule_counts.rules_count, 0) AS rules_count' : '0 AS rules_count';
    $rulesCountJoin = $componentWeekRulesReady ? "LEFT JOIN (
            SELECT component_id, COUNT(*) AS rules_count
            FROM assessment_component_week_rules
            GROUP BY component_id
        ) rule_counts ON rule_counts.component_id = ac.id" : '';
    $componentSql = "SELECT ac.*, sch.name AS scheme_name, sch.status AS scheme_status,
            sch.academic_year_id, sch.term_id, s.name AS subject_name, g.grade_name,
            t.name AS term_name, {$rulesCountSelect}
        FROM assessment_components ac
        JOIN assessment_schemes sch ON sch.id = ac.scheme_id
        JOIN academic_terms t ON t.id = sch.term_id
        JOIN subjects s ON s.id = sch.subject_id
        JOIN grades g ON g.id = sch.grade_id
        {$rulesCountJoin}";
    $componentParams = [];
    if ($currentAcademicYearId > 0) {
        $componentSql .= ' WHERE sch.academic_year_id = ?';
        $componentParams[] = $currentAcademicYearId;
    }
    $componentSql .= ' ORDER BY t.term_order ASC, s.name, g.grade_order, sch.name, ac.sort_order ASC, ac.id ASC';
    $componentStmt = $db->prepare($componentSql);
    $componentStmt->execute($componentParams);
    $components = $componentStmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    $componentsCount = count($components);
    foreach ($components as $component) {
        if (!empty($component['is_active'])) {
            $activeComponentsCount++;
        }
        if (!empty($component['is_weekly'])) {
            $weeklyComponentsCount++;
        }
        if (!empty($component['visible_to_student'])) {
            $visibleComponentsCount++;
        }
    }
}

require_once '../includes/admin_header.php';
?>

<div class="admin-page-heading">
    <h1 class="h2"><i class="fas fa-list-check me-2 text-primary"></i>بنود التقييم</h1>
    <div class="admin-top-actions no-print">
        <?php if ($componentsReady && $schemesReady): ?>
            <button type="button" class="btn btn-header-premium btn-success shadow-sm" data-bs-toggle="modal" data-bs-target="#addComponentModal">
                <i class="fas fa-plus-circle me-2"></i>إضافة بند
            </button>
        <?php endif; ?>
    </div>
</div>

<?php if (!empty($success_message)): ?>
    <div class="alert alert-success alert-dismissible fade show" role="alert">
        <i class="fas fa-check-circle me-2"></i><?php echo htmlspecialchars($success_message, ENT_QUOTES, 'UTF-8'); ?>
        <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
    </div>
<?php endif; ?>
<?php if (!empty($error_message)): ?>
    <div class="alert alert-danger alert-dismissible fade show" role="alert">
        <i class="fas fa-exclamation-circle me-2"></i><?php echo htmlspecialchars($error_message, ENT_QUOTES, 'UTF-8'); ?>
        <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
    </div>
<?php endif; ?>



<?php if (!$componentsReady || !$schemesReady || !$calendarReady): ?>
    <div class="alert alert-warning">
        <i class="fas fa-triangle-exclamation me-2"></i>طبّق جداول محرك الدرجات وخطط الدرجات أولا.
    </div>
<?php else: ?>

<div class="row row-cols-2 row-cols-md-4 g-3 mb-4">
    <div class="col"><div class="stat-card" style="--card-gradient: linear-gradient(135deg, #3b82f6, #2563eb);"><div class="stat-card-icon"><i class="fas fa-list-check"></i></div><div class="stat-card-info"><div class="stat-card-number counter" data-target="<?php echo (int)$componentsCount; ?>">0</div><div class="stat-card-label">إجمالي البنود</div><div class="stat-card-sub"><?php echo htmlspecialchars($currentAcademicYearName ?: 'العام الحالي', ENT_QUOTES, 'UTF-8'); ?></div></div></div></div>
    <div class="col"><div class="stat-card" style="--card-gradient: linear-gradient(135deg, #10b981, #059669);"><div class="stat-card-icon"><i class="fas fa-check-circle"></i></div><div class="stat-card-info"><div class="stat-card-number counter" data-target="<?php echo (int)$activeComponentsCount; ?>">0</div><div class="stat-card-label">بنود نشطة</div><div class="stat-card-sub">متاحة للرصد</div></div></div></div>
    <div class="col"><div class="stat-card" style="--card-gradient: linear-gradient(135deg, #0ea5e9, #0284c7);"><div class="stat-card-icon"><i class="fas fa-calendar-week"></i></div><div class="stat-card-info"><div class="stat-card-number counter" data-target="<?php echo (int)$weeklyComponentsCount; ?>">0</div><div class="stat-card-label">بنود أسبوعية</div><div class="stat-card-sub">للمتوسطات</div></div></div></div>
    <div class="col"><div class="stat-card" style="--card-gradient: linear-gradient(135deg, #8b5cf6, #7c3aed);"><div class="stat-card-icon"><i class="fas fa-eye"></i></div><div class="stat-card-info"><div class="stat-card-number counter" data-target="<?php echo (int)$visibleComponentsCount; ?>">0</div><div class="stat-card-label">ظاهرة للطالب</div><div class="stat-card-sub">في التقارير</div></div></div></div>
</div>

<div data-assessment-bulk-root data-bulk-modal="componentBulkActionModal" data-entity-label="بنود التقييم" data-deactivate-label="تعطيل">
<div class="admin-bulk-action-bar d-none">
    <div class="admin-bulk-info">
        <span>السجلات المحددة:</span>
        <span class="admin-bulk-badge" data-assessment-selected-count>0</span>
    </div>
    <div class="admin-bulk-actions">
        <button type="button" class="btn btn-warning btn-sm assessment-bulk-trigger" data-operation="deactivate" disabled><i class="fas fa-ban me-1"></i>تعطيل المحدد</button>
        <button type="button" class="btn btn-danger btn-sm assessment-bulk-trigger" data-operation="delete" disabled><i class="fas fa-trash me-1"></i>حذف المحدد</button>
    </div>
</div>
<div class="admin-list-surface">
        <div class="table-responsive admin-table-wrap">
            <table class="table table-hover table-striped align-middle datatable admin-data-table">
                <thead>
                    <tr>
                        <th class="text-center no-sort" data-orderable="false" style="width: 42px;"><input type="checkbox" class="form-check-input assessment-select-page" title="تحديد سجلات الصفحة الحالية" aria-label="تحديد سجلات الصفحة الحالية"></th>
                        <th>الخطة</th>
                        <th>البند</th>
                        <th>النوع</th>
                        <th>الدرجة</th>
                        <th>الحساب</th>
                        <th>خصائص</th>
                        <th>قواعد الأسابيع</th>
                        <th>الحالة</th>
                        <th class="admin-col-150px">إجراءات</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($components)): ?>
                        <tr><td colspan="10" class="text-center text-muted py-4">لم تتم إضافة بنود تقييم بعد.</td></tr>
                    <?php else: ?>
                        <?php foreach ($components as $component): ?>
                            <tr>
                                <td class="text-center"><input type="checkbox" class="form-check-input assessment-row-select" value="<?php echo (int) $component['id']; ?>" aria-label="تحديد بند <?php echo htmlspecialchars($component['name'], ENT_QUOTES, 'UTF-8'); ?>" <?php echo (string) ($component['scheme_status'] ?? '') === 'active' ? 'disabled title="عطّل الخطة أولا"' : ''; ?>></td>
                                <td><strong><?php echo htmlspecialchars($component['subject_name'], ENT_QUOTES, 'UTF-8'); ?></strong><div class="small text-muted"><?php echo htmlspecialchars($component['grade_name'] . ' - ' . $component['term_name'] . ' - ' . $component['scheme_name'], ENT_QUOTES, 'UTF-8'); ?></div></td>
                                <td><strong><?php echo htmlspecialchars($component['name'], ENT_QUOTES, 'UTF-8'); ?></strong><div class="small text-muted">ترتيب: <?php echo (int) $component['sort_order']; ?></div></td>
                                <td><?php echo htmlspecialchars($componentTypeLabels[$component['component_type']] ?? $component['component_type'], ENT_QUOTES, 'UTF-8'); ?></td>
                                <td><?php echo htmlspecialchars(AssessmentEngine::formatNumber((float) $component['max_grade']), ENT_QUOTES, 'UTF-8'); ?></td>
                                <td><?php echo htmlspecialchars($calculationModeLabels[$component['calculation_mode']] ?? $component['calculation_mode'], ENT_QUOTES, 'UTF-8'); ?></td>
                                <td>
                                    <?php echo !empty($component['is_weekly']) ? '<span class="badge bg-info text-dark">أسبوعي</span>' : ''; ?>
                                    <?php echo !empty($component['counts_in_average']) ? '<span class="badge bg-primary">متوسط</span>' : ''; ?>
                                    <?php echo !empty($component['counts_in_total']) ? '<span class="badge bg-success">مجموع</span>' : '<span class="badge bg-secondary">خارج المجموع</span>'; ?>
                                    <?php echo !empty($component['visible_to_student']) ? '<span class="badge bg-light text-dark">ظاهر</span>' : ''; ?>
                                </td>
                                <td><span class="badge bg-info text-dark"><?php echo number_format((int) ($component['rules_count'] ?? 0)); ?></span></td>
                                <td><?php echo !empty($component['is_active']) ? '<span class="badge bg-success">نشط</span>' : '<span class="badge bg-secondary">معطل</span>'; ?></td>
                                <td class="actions-column admin-table-actions">
                                    <?php if ((string) ($component['scheme_status'] ?? '') === 'active'): ?>
                                        <span class="badge bg-light text-dark border" title="عطّل الخطة قبل تعديل بنودها">الخطة نشطة</span>
                                    <?php else: ?>
                                    <button type="button" class="btn btn-sm btn-action-pills btn-edit me-1 edit-component-btn" data-bs-toggle="tooltip" title="تعديل"
                                            data-component-id="<?php echo (int) $component['id']; ?>"
                                            data-scheme-id="<?php echo (int) $component['scheme_id']; ?>"
                                            data-name="<?php echo htmlspecialchars($component['name'], ENT_QUOTES, 'UTF-8'); ?>"
                                            data-component-type="<?php echo htmlspecialchars($component['component_type'], ENT_QUOTES, 'UTF-8'); ?>"
                                            data-max-grade="<?php echo htmlspecialchars((string) $component['max_grade'], ENT_QUOTES, 'UTF-8'); ?>"
                                            data-sort-order="<?php echo (int) $component['sort_order']; ?>"
                                            data-calculation-mode="<?php echo htmlspecialchars($component['calculation_mode'], ENT_QUOTES, 'UTF-8'); ?>"
                                            data-is-weekly="<?php echo !empty($component['is_weekly']) ? '1' : '0'; ?>"
                                            data-repeat-per-week="<?php echo !empty($component['repeat_per_week']) ? '1' : '0'; ?>"
                                            data-counts-average="<?php echo !empty($component['counts_in_average']) ? '1' : '0'; ?>"
                                            data-counts-total="<?php echo !empty($component['counts_in_total']) ? '1' : '0'; ?>"
                                            data-visible-student="<?php echo !empty($component['visible_to_student']) ? '1' : '0'; ?>"
                                            data-accepts-absence="<?php echo !empty($component['accepts_absence']) ? '1' : '0'; ?>"
                                            data-accepts-excused="<?php echo !empty($component['accepts_excused_absence']) ? '1' : '0'; ?>"><i class="fas fa-edit"></i></button>
                                    <button type="button" class="btn btn-sm me-1 btn-action-pills <?php echo !empty($component['is_active']) ? 'btn-deactivate' : 'btn-activate'; ?> status-component-btn" data-bs-toggle="tooltip" title="<?php echo !empty($component['is_active']) ? 'تعطيل' : 'تفعيل'; ?>" data-component-id="<?php echo (int) $component['id']; ?>" data-component-name="<?php echo htmlspecialchars($component['name'], ENT_QUOTES, 'UTF-8'); ?>" data-action-label="<?php echo !empty($component['is_active']) ? 'تعطيل' : 'تفعيل'; ?>"><i class="fas <?php echo !empty($component['is_active']) ? 'fa-ban' : 'fa-check'; ?>"></i></button>
                                    <button type="button" class="btn btn-sm btn-action-pills btn-delete assessment-smart-delete" data-bs-toggle="tooltip" title="حذف" data-row-id="<?php echo (int) $component['id']; ?>" data-row-name="<?php echo htmlspecialchars($component['name'], ENT_QUOTES, 'UTF-8'); ?>" data-row-active="<?php echo !empty($component['is_active']) ? '1' : '0'; ?>"><i class="fas fa-trash"></i></button>
                                    <?php endif; ?>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
</div>
</div>

<?php
$componentFormFields = static function (string $prefix) use ($schemes, $componentTypeLabels, $calculationModeLabels): void {
    $id = static fn(string $name): string => $prefix . ucfirst($name);
?>
    <div class="row g-3">
        <div class="col-md-6">
            <label class="form-label">الخطة</label>
            <select name="scheme_id" id="<?php echo $id('scheme'); ?>" class="form-select" required>
                <option value="">اختر الخطة</option>
                <?php foreach ($schemes as $scheme): ?>
                    <option value="<?php echo (int) $scheme['id']; ?>"><?php echo htmlspecialchars($scheme['subject_name'] . ' - ' . $scheme['grade_name'] . ' - ' . $scheme['term_name'] . ' - ' . $scheme['name'], ENT_QUOTES, 'UTF-8'); ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="col-md-6"><label class="form-label">اسم البند</label><input type="text" name="name" id="<?php echo $id('name'); ?>" class="form-control" required maxlength="190"></div>
        <div class="col-md-3"><label class="form-label">النوع</label><select name="component_type" id="<?php echo $id('type'); ?>" class="form-select"><?php foreach ($componentTypeLabels as $key => $label): ?><option value="<?php echo $key; ?>"><?php echo htmlspecialchars($label, ENT_QUOTES, 'UTF-8'); ?></option><?php endforeach; ?></select></div>
        <div class="col-md-3"><label class="form-label">الدرجة الكبرى</label><input type="number" name="max_grade" id="<?php echo $id('max'); ?>" class="form-control" min="0" max="1000" step="0.01" required></div>
        <div class="col-md-3"><label class="form-label">الترتيب</label><input type="number" name="sort_order" id="<?php echo $id('order'); ?>" class="form-control" min="0" max="9999" value="0"></div>
        <div class="col-md-3"><label class="form-label">طريقة الحساب</label><select name="calculation_mode" id="<?php echo $id('calculation'); ?>" class="form-select"><?php foreach ($calculationModeLabels as $key => $label): ?><option value="<?php echo $key; ?>"><?php echo htmlspecialchars($label, ENT_QUOTES, 'UTF-8'); ?></option><?php endforeach; ?></select></div>
        <div class="col-md-3"><div class="form-check mt-4"><input class="form-check-input" type="checkbox" name="is_weekly" id="<?php echo $id('weekly'); ?>" value="1"><label class="form-check-label" for="<?php echo $id('weekly'); ?>">أسبوعي</label></div></div>
        <div class="col-md-3"><div class="form-check mt-4"><input class="form-check-input" type="checkbox" name="repeat_per_week" id="<?php echo $id('repeat'); ?>" value="1"><label class="form-check-label" for="<?php echo $id('repeat'); ?>">يتكرر أسبوعيا</label></div></div>
        <div class="col-md-3"><div class="form-check mt-4"><input class="form-check-input" type="checkbox" name="counts_in_average" id="<?php echo $id('average'); ?>" value="1"><label class="form-check-label" for="<?php echo $id('average'); ?>">يدخل في المتوسط</label></div></div>
        <div class="col-md-3"><div class="form-check mt-4"><input class="form-check-input" type="checkbox" name="counts_in_total" id="<?php echo $id('total'); ?>" value="1" checked><label class="form-check-label" for="<?php echo $id('total'); ?>">يدخل في المجموع</label></div></div>
        <div class="col-md-4"><div class="form-check mt-2"><input class="form-check-input" type="checkbox" name="visible_to_student" id="<?php echo $id('visible'); ?>" value="1" checked><label class="form-check-label" for="<?php echo $id('visible'); ?>">ظاهر للطالب</label></div></div>
        <div class="col-md-4"><div class="form-check mt-2"><input class="form-check-input" type="checkbox" name="accepts_absence" id="<?php echo $id('absence'); ?>" value="1" checked><label class="form-check-label" for="<?php echo $id('absence'); ?>">يقبل غياب</label></div></div>
        <div class="col-md-4"><div class="form-check mt-2"><input class="form-check-input" type="checkbox" name="accepts_excused_absence" id="<?php echo $id('excused'); ?>" value="1" checked><label class="form-check-label" for="<?php echo $id('excused'); ?>">يقبل غياب بعذر</label></div></div>
    </div>
<?php
};
?>

<div class="modal fade" id="addComponentModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-xl"><div class="modal-content admin-modal admin-modal-premium admin-modal-create"><form method="post" action="assessment_components.php">
        <?php echo csrfField(); ?><input type="hidden" name="action" value="add_component">
        <div class="modal-header"><h5 class="modal-title"><i class="fas fa-plus-circle me-2"></i>إضافة بند تقييم</h5><button type="button" class="btn-close" data-bs-dismiss="modal"></button></div>
        <div class="modal-body"><?php $componentFormFields('add'); ?></div>
        <div class="modal-footer"><button type="button" class="btn btn-secondary" data-bs-dismiss="modal"><i class="fas fa-times me-1"></i>إلغاء</button><button type="submit" class="btn btn-success"><i class="fas fa-plus me-1"></i>إضافة</button></div>
    </form></div></div>
</div>

<div class="modal fade" id="editComponentModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-xl"><div class="modal-content admin-modal admin-modal-premium admin-modal-edit"><form method="post" action="assessment_components.php">
        <?php echo csrfField(); ?><input type="hidden" name="action" value="update_component"><input type="hidden" name="component_id" id="editComponentId">
        <div class="modal-header"><h5 class="modal-title"><i class="fas fa-edit me-2"></i>تعديل بند التقييم</h5><button type="button" class="btn-close" data-bs-dismiss="modal"></button></div>
        <div class="modal-body"><?php $componentFormFields('edit'); ?></div>
        <div class="modal-footer"><button type="button" class="btn btn-secondary" data-bs-dismiss="modal"><i class="fas fa-times me-1"></i>إلغاء</button><button type="submit" class="btn btn-primary"><i class="fas fa-save me-1"></i>حفظ</button></div>
    </form></div></div>
</div>

<div class="modal fade" id="statusComponentModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog"><div class="modal-content admin-modal admin-modal-premium admin-modal-warning" id="statusComponentModalContent"><form method="post" action="assessment_components.php">
        <?php echo csrfField(); ?><input type="hidden" name="action" value="toggle_component_status"><input type="hidden" name="component_id" id="statusComponentId">
        <div class="modal-header"><h5 class="modal-title"><i class="fas fa-toggle-on me-2" id="statusComponentHeaderIcon"></i>تغيير حالة البند</h5><button type="button" class="btn-close" data-bs-dismiss="modal"></button></div>
        <div class="modal-body text-center">
            <div class="mb-3"><i class="fas fa-toggle-on text-warning admin-modal-icon-lg" id="statusComponentBodyIcon"></i></div>
            <p>هل تريد <span id="statusComponentAction" class="fw-bold"></span> بند <span id="statusComponentName" class="fw-bold text-primary"></span>؟</p>
        </div>
        <div class="modal-footer"><button type="button" class="btn btn-secondary" data-bs-dismiss="modal"><i class="fas fa-times me-1"></i>إلغاء</button><button type="submit" class="btn btn-warning" id="statusComponentSubmit"><i class="fas fa-ban me-1"></i>تأكيد</button></div>
    </form></div></div>
</div>

<div class="modal fade" id="componentBulkActionModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog"><div class="modal-content admin-modal admin-modal-premium admin-modal-delete"><form method="post" action="assessment_components.php">
        <?php echo csrfField(); ?><input type="hidden" name="action" value="assessment_bulk_action"><input type="hidden" name="selected_ids" value="">
        <div class="modal-header"><h5 class="modal-title"><i class="fas fa-list-check me-2"></i><span data-bulk-modal-title>عملية على بنود التقييم</span></h5><button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="إغلاق"></button></div>
        <div class="modal-body"><div class="text-center mb-3"><i class="fas fa-triangle-exclamation text-warning admin-modal-icon-lg"></i></div><p class="text-center" data-bulk-modal-message></p><div class="alert alert-warning mb-0"><i class="fas fa-shield-alt me-2"></i>عدد السجلات: <strong data-bulk-modal-count>0</strong>. يفحص النظام البند وكل بنوده الفرعية؛ أي درجات أو نوافذ أو تقارير مرتبطة تلغي الحذف كله.</div></div>
        <div class="modal-footer"><button type="button" class="btn btn-secondary" data-bs-dismiss="modal"><i class="fas fa-times me-1"></i>إلغاء</button><button type="submit" name="bulk_operation" value="deactivate" class="btn btn-warning" data-bulk-deactivate-submit><i class="fas fa-ban me-1"></i>تعطيل فقط</button><button type="submit" name="bulk_operation" value="delete" class="btn btn-danger" data-bulk-delete-submit><i class="fas fa-trash me-1"></i>حذف</button></div>
    </form></div></div>
</div>

<script>
document.addEventListener('DOMContentLoaded', function () {
    function showModal(id) {
        const modalEl = document.getElementById(id);
        if (modalEl && window.bootstrap) {
            new bootstrap.Modal(modalEl).show();
        }
    }
    function setValue(id, value) {
        const el = document.getElementById(id);
        if (el) {
            el.value = value || '';
        }
    }
    function setChecked(id, value) {
        const el = document.getElementById(id);
        if (el) {
            el.checked = value === '1';
        }
    }
    document.querySelectorAll('.edit-component-btn').forEach(function (button) {
        button.addEventListener('click', function () {
            setValue('editComponentId', this.dataset.componentId);
            setValue('editScheme', this.dataset.schemeId);
            setValue('editName', this.dataset.name);
            setValue('editType', this.dataset.componentType);
            setValue('editMax', this.dataset.maxGrade);
            setValue('editOrder', this.dataset.sortOrder);
            setValue('editCalculation', this.dataset.calculationMode);
            setChecked('editWeekly', this.dataset.isWeekly);
            setChecked('editRepeat', this.dataset.repeatPerWeek);
            setChecked('editAverage', this.dataset.countsAverage);
            setChecked('editTotal', this.dataset.countsTotal);
            setChecked('editVisible', this.dataset.visibleStudent);
            setChecked('editAbsence', this.dataset.acceptsAbsence);
            setChecked('editExcused', this.dataset.acceptsExcused);
            showModal('editComponentModal');
        });
    });
    document.querySelectorAll('.status-component-btn').forEach(function (button) {
        button.addEventListener('click', function () {
            setValue('statusComponentId', this.dataset.componentId);
            const actionLabel = this.dataset.actionLabel || '';
            const isActive = actionLabel === 'تعطيل';
            document.getElementById('statusComponentName').textContent = this.dataset.componentName || '';
            document.getElementById('statusComponentAction').textContent = actionLabel;

            const submitButton = document.getElementById('statusComponentSubmit');
            if (submitButton) {
                submitButton.className = isActive ? 'btn btn-warning' : 'btn btn-success';
                submitButton.innerHTML = isActive ? '<i class="fas fa-ban me-1"></i>تعطيل' : '<i class="fas fa-check me-1"></i>تفعيل';
            }

            const modalContent = document.getElementById('statusComponentModalContent');
            if (modalContent) {
                modalContent.classList.toggle('admin-modal-warning', isActive);
                modalContent.classList.toggle('admin-modal-create', !isActive);
            }
            const bodyIcon = document.getElementById('statusComponentBodyIcon');
            const headerIcon = document.getElementById('statusComponentHeaderIcon');
            if (bodyIcon) {
                bodyIcon.className = isActive ? 'fas fa-ban text-warning admin-modal-icon-lg' : 'fas fa-check-circle text-success admin-modal-icon-lg';
            }
            if (headerIcon) {
                headerIcon.className = isActive ? 'fas fa-ban me-2' : 'fas fa-check-circle me-2';
            }

            showModal('statusComponentModal');
        });
    });
});
</script>
<script src="../assets/js/assessment-bulk-actions.js"></script>
<?php endif; ?>

<?php require_once '../includes/admin_footer.php'; ?>
