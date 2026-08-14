<?php
$page_title = "قواعد أسابيع البنود";
$custom_page_title = true;

require_once '../config/database.php';
require_once '../classes/utilities.php';
require_once '../classes/ActivityLog.php';
require_once '../classes/AcademicYear.php';
require_once '../classes/AcademicYearWriteGuard.php';
require_once '../classes/AssessmentEngine.php';
require_once '../classes/AssessmentBulkActionService.php';
require_once '../includes/session_config.php';
require_once '../includes/csrf.php';
Utilities::validateSession('admin');
requireCsrfPost();

$database = new Database();
$db = $database->getConnection();

$success_message = $_SESSION['success_message'] ?? null;
$error_message = $_SESSION['error_message'] ?? null;
unset($_SESSION['success_message'], $_SESSION['error_message']);

function week_rules_table_exists(PDO $db, string $table): bool
{
    $stmt = $db->prepare('SELECT 1 FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?');
    $stmt->execute([$table]);
    return (bool) $stmt->fetchColumn();
}

function week_rules_column_exists(PDO $db, string $table, string $column): bool
{
    $stmt = $db->prepare('SELECT 1 FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?');
    $stmt->execute([$table, $column]);
    return (bool) $stmt->fetchColumn();
}

function week_rules_redirect(): void
{
    header('Location: assessment_component_week_rules.php');
    exit();
}

function week_rules_selected($left, $right): string
{
    return (string) $left === (string) $right ? 'selected' : '';
}

function week_rules_checked($value): string
{
    return !empty($value) ? 'checked' : '';
}

function week_rules_fetch_component(PDO $db, int $componentId): array
{
    $stmt = $db->prepare("SELECT ac.*, sch.name AS scheme_name, sch.academic_year_id, sch.term_id
        FROM assessment_components ac
        JOIN assessment_schemes sch ON sch.id = ac.scheme_id
        WHERE ac.id = ?
        LIMIT 1");
    $stmt->execute([$componentId]);
    $component = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$component) {
        throw new InvalidArgumentException('بند التقييم غير موجود.');
    }
    return $component;
}

function week_rules_fetch_week(PDO $db, int $weekId): array
{
    $stmt = $db->prepare('SELECT * FROM academic_weeks WHERE id = ? LIMIT 1');
    $stmt->execute([$weekId]);
    $week = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$week) {
        throw new InvalidArgumentException('الأسبوع غير موجود.');
    }
    return $week;
}

function week_rules_assert_same_scope(array $component, array $week): void
{
    if ((int) $week['academic_year_id'] !== (int) $component['academic_year_id'] || (int) $week['term_id'] !== (int) $component['term_id']) {
        throw new InvalidArgumentException('الأسبوع لا يتبع نفس عام وترم خطة البند.');
    }
}

function week_rules_has_mark_dependency(PDO $db, int $componentId, int $weekId): bool
{
    if (!week_rules_table_exists($db, 'student_marks')) {
        return false;
    }
    $where = 'component_id = ?';
    $params = [$componentId];
    if (week_rules_column_exists($db, 'student_marks', 'week_id')) {
        $where .= ' AND week_id = ?';
        $params[] = $weekId;
    } elseif (week_rules_column_exists($db, 'student_marks', 'week_slot')) {
        $where .= ' AND week_slot = ?';
        $params[] = $weekId;
    } else {
        return false;
    }
    $stmt = $db->prepare("SELECT COUNT(*) FROM student_marks WHERE {$where}");
    $stmt->execute($params);
    return (int) $stmt->fetchColumn() > 0;
}

function week_rules_assert_selected_year(?int $currentAcademicYearId, array $row, string $message): void
{
    if ($currentAcademicYearId && (int) ($row['academic_year_id'] ?? 0) !== $currentAcademicYearId) {
        throw new InvalidArgumentException($message);
    }
}

$rulesReady = week_rules_table_exists($db, 'assessment_component_week_rules');
$componentsReady = week_rules_table_exists($db, 'assessment_components');
$schemesReady = week_rules_table_exists($db, 'assessment_schemes');
$weeksReady = week_rules_table_exists($db, 'academic_weeks');
$calendarReady = week_rules_table_exists($db, 'academic_years') && week_rules_table_exists($db, 'academic_terms') && $weeksReady;

$currentAcademicYear = AcademicYear::getCurrent($db) ?: AcademicYear::getActive($db);
$currentAcademicYearId = $currentAcademicYear ? (int) $currentAcademicYear['id'] : 0;
$currentAcademicYearName = $currentAcademicYear['name'] ?? '';
$weekTypeLabels = ['study' => 'دراسي', 'holiday' => 'عطلة', 'exam' => 'امتحانات', 'revision' => 'مراجعة'];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        (new AcademicYearWriteGuard($db))->assertWritable($currentAcademicYearId);
        if (!$rulesReady || !$componentsReady || !$schemesReady || !$calendarReady) {
            throw new RuntimeException('جداول قواعد الأسابيع أو البنود أو التقويم غير مطبقة بعد.');
        }

        $action = (string) ($_POST['action'] ?? '');

        if ($action === 'assessment_bulk_action') {
            $result = (new AssessmentBulkActionService($db))->execute(
                'week_rule',
                (string) ($_POST['bulk_operation'] ?? ''),
                AssessmentBulkActionService::normalizeIds($_POST['selected_ids'] ?? ''),
                $currentAcademicYearId
            );
            $_SESSION['success_message'] = $result['message'];
            week_rules_redirect();
        }

        if ($action === 'save_component_week_rule') {
            $componentId = (int) ($_POST['component_id'] ?? 0);
            $weekId = (int) ($_POST['week_id'] ?? 0);
            $isIncluded = isset($_POST['is_included']) ? 1 : 0;
            $overrideRaw = trim((string) ($_POST['max_grade_override'] ?? ''));
            $maxGradeOverride = $overrideRaw !== '' ? (float) $overrideRaw : null;

            if ($componentId <= 0 || $weekId <= 0) {
                throw new InvalidArgumentException('اختر البند والأسبوع لحفظ القاعدة.');
            }
            if ($maxGradeOverride !== null && ($maxGradeOverride < 0 || $maxGradeOverride > 1000)) {
                throw new InvalidArgumentException('الدرجة الكبرى البديلة يجب أن تكون بين 0 و 1000.');
            }

            $component = week_rules_fetch_component($db, $componentId);
            week_rules_assert_selected_year($currentAcademicYearId, $component, 'لا يمكن حفظ قاعدة أسبوع لبند خارج العام الدراسي المختار.');
            $week = week_rules_fetch_week($db, $weekId);
            week_rules_assert_same_scope($component, $week);
            if (($week['week_type'] ?? '') !== 'study') {
                $isIncluded = 0;
            }

            $stmt = $db->prepare("INSERT INTO assessment_component_week_rules
                (component_id, week_id, is_included, max_grade_override)
                VALUES (?, ?, ?, ?)
                ON DUPLICATE KEY UPDATE
                    is_included = VALUES(is_included),
                    max_grade_override = VALUES(max_grade_override)");
            $stmt->execute([$componentId, $weekId, $isIncluded, $maxGradeOverride]);

            ActivityLog::logUpdate('assessment_component', $componentId, (string) $component['name'], [
                'scheme' => $component['scheme_name'],
                'component' => $component['name'],
                'week' => $week['name'],
                'is_active' => $isIncluded,
                'max_grade' => $maxGradeOverride,
            ]);
            $_SESSION['success_message'] = 'تم حفظ قاعدة الأسبوع للبند.';
            week_rules_redirect();
        }

        if ($action === 'toggle_component_week_rule') {
            $ruleId = (int) ($_POST['rule_id'] ?? 0);
            $stmt = $db->prepare("SELECT cwr.*, ac.name AS component_name, sch.name AS scheme_name, sch.academic_year_id, w.name AS week_name, w.week_type
                FROM assessment_component_week_rules cwr
                JOIN assessment_components ac ON ac.id = cwr.component_id
                JOIN assessment_schemes sch ON sch.id = ac.scheme_id
                JOIN academic_weeks w ON w.id = cwr.week_id
                WHERE cwr.id = ?
                LIMIT 1");
            $stmt->execute([$ruleId]);
            $rule = $stmt->fetch(PDO::FETCH_ASSOC);
            if (!$rule) {
                throw new InvalidArgumentException('قاعدة الأسبوع غير موجودة.');
            }
            week_rules_assert_selected_year($currentAcademicYearId, $rule, 'لا يمكن تغيير حالة قاعدة أسبوع خارج العام الدراسي المختار.');
            if (($rule['week_type'] ?? '') !== 'study' && empty($rule['is_included'])) {
                throw new RuntimeException('لا يمكن تفعيل أسبوع غير دراسي داخل المتوسط.');
            }
            $newIncluded = !empty($rule['is_included']) ? 0 : 1;
            $db->prepare('UPDATE assessment_component_week_rules SET is_included = ? WHERE id = ?')->execute([$newIncluded, $ruleId]);
            ActivityLog::logUpdate('assessment_component_week_rule', $ruleId, (string) $rule['component_name'], [
                'scheme' => $rule['scheme_name'],
                'week' => $rule['week_name'],
                'old_status' => !empty($rule['is_included']) ? 'included' : 'excluded',
                'new_status' => $newIncluded ? 'included' : 'excluded',
            ]);
            $_SESSION['success_message'] = $newIncluded ? 'تم تفعيل قاعدة الأسبوع داخل المتوسط.' : 'تم تعطيل قاعدة الأسبوع واستبعادها من المتوسط.';
            week_rules_redirect();
        }

        if ($action === 'delete_component_week_rule') {
            $ruleId = (int) ($_POST['rule_id'] ?? 0);
            $stmt = $db->prepare("SELECT cwr.*, ac.name AS component_name, sch.name AS scheme_name, sch.academic_year_id, w.name AS week_name
                FROM assessment_component_week_rules cwr
                JOIN assessment_components ac ON ac.id = cwr.component_id
                JOIN assessment_schemes sch ON sch.id = ac.scheme_id
                JOIN academic_weeks w ON w.id = cwr.week_id
                WHERE cwr.id = ?
                LIMIT 1");
            $stmt->execute([$ruleId]);
            $rule = $stmt->fetch(PDO::FETCH_ASSOC);
            if (!$rule) {
                throw new InvalidArgumentException('قاعدة الأسبوع غير موجودة.');
            }
            week_rules_assert_selected_year($currentAcademicYearId, $rule, 'لا يمكن حذف قاعدة أسبوع خارج العام الدراسي المختار.');
            if (week_rules_has_mark_dependency($db, (int) $rule['component_id'], (int) $rule['week_id'])) {
                throw new RuntimeException('لا يمكن حذف القاعدة لوجود درجات مرصودة مرتبطة بنفس البند والأسبوع.');
            }
            $db->prepare('DELETE FROM assessment_component_week_rules WHERE id = ?')->execute([$ruleId]);
            ActivityLog::logDelete('assessment_component_week_rule', $ruleId, (string) $rule['component_name'], [
                'scheme' => $rule['scheme_name'],
                'week' => $rule['week_name'],
            ]);
            $_SESSION['success_message'] = 'تم حذف قاعدة الأسبوع بنجاح.';
            week_rules_redirect();
        }
    } catch (Throwable $e) {
        $_SESSION['error_message'] = $e->getMessage();
        week_rules_redirect();
    }
}

$components = [];
$weeks = [];
$rules = [];
$rulesCount = 0;
$includedRulesCount = 0;
$excludedRulesCount = 0;
$overrideRulesCount = 0;

if ($componentsReady && $schemesReady) {
    $componentSql = "SELECT ac.*, sch.name AS scheme_name, sch.academic_year_id, sch.term_id,
            s.name AS subject_name, g.grade_name, t.name AS term_name
        FROM assessment_components ac
        JOIN assessment_schemes sch ON sch.id = ac.scheme_id
        JOIN academic_terms t ON t.id = sch.term_id
        JOIN subjects s ON s.id = sch.subject_id
        JOIN grades g ON g.id = sch.grade_id
        WHERE ac.is_active = 1
          AND (ac.is_weekly = 1 OR ac.counts_in_average = 1 OR ac.calculation_mode = 'average_weeks')";
    $componentParams = [];
    if ($currentAcademicYearId > 0) {
        $componentSql .= ' AND sch.academic_year_id = ?';
        $componentParams[] = $currentAcademicYearId;
    }
    $componentSql .= ' ORDER BY t.term_order ASC, s.name, g.grade_order, sch.name, ac.sort_order ASC';
    $componentStmt = $db->prepare($componentSql);
    $componentStmt->execute($componentParams);
    $components = $componentStmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
}

if ($weeksReady) {
    $weekSql = "SELECT w.*, t.name AS term_name
        FROM academic_weeks w
        JOIN academic_terms t ON t.id = w.term_id";
    $weekParams = [];
    if ($currentAcademicYearId > 0) {
        $weekSql .= ' WHERE w.academic_year_id = ?';
        $weekParams[] = $currentAcademicYearId;
    }
    $weekSql .= ' ORDER BY t.term_order ASC, w.week_order ASC';
    $weekStmt = $db->prepare($weekSql);
    $weekStmt->execute($weekParams);
    $weeks = $weekStmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
}

if ($rulesReady) {
    $ruleSql = "SELECT cwr.*, ac.name AS component_name, ac.max_grade AS default_max_grade,
            sch.name AS scheme_name, sch.academic_year_id, sch.term_id,
            s.name AS subject_name, g.grade_name, t.name AS term_name,
            w.name AS week_name, w.month_label, w.week_order, w.week_type
        FROM assessment_component_week_rules cwr
        JOIN assessment_components ac ON ac.id = cwr.component_id
        JOIN assessment_schemes sch ON sch.id = ac.scheme_id
        JOIN academic_terms t ON t.id = sch.term_id
        JOIN subjects s ON s.id = sch.subject_id
        JOIN grades g ON g.id = sch.grade_id
        JOIN academic_weeks w ON w.id = cwr.week_id";
    $ruleParams = [];
    if ($currentAcademicYearId > 0) {
        $ruleSql .= ' WHERE sch.academic_year_id = ?';
        $ruleParams[] = $currentAcademicYearId;
    }
    $ruleSql .= ' ORDER BY t.term_order ASC, s.name, g.grade_order, sch.name, ac.sort_order ASC, w.week_order ASC';
    $ruleStmt = $db->prepare($ruleSql);
    $ruleStmt->execute($ruleParams);
    $rules = $ruleStmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    $rulesCount = count($rules);
    foreach ($rules as $rule) {
        if (!empty($rule['is_included'])) {
            $includedRulesCount++;
        } else {
            $excludedRulesCount++;
        }
        if ($rule['max_grade_override'] !== null) {
            $overrideRulesCount++;
        }
    }
}

require_once '../includes/admin_header.php';
?>

<div class="admin-page-heading">
    <h1 class="h2 d-flex align-items-center">
        <i class="fas fa-calendar-check me-2 text-primary"></i>
        <span>قواعد أسابيع البنود</span>
        <i class="fas fa-info-circle ms-2 text-muted fs-6"
           data-bs-toggle="tooltip"
           data-bs-placement="top"
           title="تُستخدم هذه القواعد لاستبعاد أسبوع محدد من بند أسبوعي أو تغيير درجته الكبرى دون تعديل الخطة الأصلية."
           style="cursor: pointer;"
           aria-label="معلومات عن قواعد أسابيع البنود"></i>
    </h1>
    <div class="admin-top-actions no-print">
        <?php if ($rulesReady && $componentsReady && $weeksReady): ?>
            <button type="button" class="btn btn-header-premium btn-success shadow-sm" data-bs-toggle="modal" data-bs-target="#addWeekRuleModal">
                <i class="fas fa-plus-circle me-2"></i>إضافة قاعدة
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



<?php if (!$rulesReady || !$componentsReady || !$weeksReady || !$calendarReady): ?>
    <div class="alert alert-warning">
        <i class="fas fa-triangle-exclamation me-2"></i>طبّق جداول قواعد الأسابيع والبنود والتقويم أولا.
    </div>
<?php else: ?>

<div class="row row-cols-2 row-cols-md-4 g-3 mb-4">
    <div class="col"><div class="stat-card" style="--card-gradient: linear-gradient(135deg, #3b82f6, #2563eb);"><div class="stat-card-icon"><i class="fas fa-calendar-check"></i></div><div class="stat-card-info"><div class="stat-card-number counter" data-target="<?php echo (int)$rulesCount; ?>">0</div><div class="stat-card-label">إجمالي القواعد</div><div class="stat-card-sub"><?php echo htmlspecialchars($currentAcademicYearName ?: 'العام الحالي', ENT_QUOTES, 'UTF-8'); ?></div></div></div></div>
    <div class="col"><div class="stat-card" style="--card-gradient: linear-gradient(135deg, #10b981, #059669);"><div class="stat-card-icon"><i class="fas fa-check-circle"></i></div><div class="stat-card-info"><div class="stat-card-number counter" data-target="<?php echo (int)$includedRulesCount; ?>">0</div><div class="stat-card-label">داخلة في الرصد</div><div class="stat-card-sub">قواعد مفعلة</div></div></div></div>
    <div class="col"><div class="stat-card" style="--card-gradient: linear-gradient(135deg, #ef4444, #dc2626);"><div class="stat-card-icon"><i class="fas fa-ban"></i></div><div class="stat-card-info"><div class="stat-card-number counter" data-target="<?php echo (int)$excludedRulesCount; ?>">0</div><div class="stat-card-label">مستبعدة</div><div class="stat-card-sub">أسابيع لا تُرصد</div></div></div></div>
    <div class="col"><div class="stat-card" style="--card-gradient: linear-gradient(135deg, #f59e0b, #d97706);"><div class="stat-card-icon"><i class="fas fa-pen-ruler"></i></div><div class="stat-card-info"><div class="stat-card-number counter" data-target="<?php echo (int)$overrideRulesCount; ?>">0</div><div class="stat-card-label">درجات بديلة</div><div class="stat-card-sub">تخصيص أسبوعي</div></div></div></div>
</div>

<div data-assessment-bulk-root data-bulk-modal="weekRuleBulkActionModal" data-entity-label="قواعد الأسابيع" data-deactivate-label="استبعاد">
<div class="admin-bulk-action-bar d-none">
    <div class="admin-bulk-info">
        <span>السجلات المحددة:</span>
        <span class="admin-bulk-badge" data-assessment-selected-count>0</span>
    </div>
    <div class="admin-bulk-actions">
        <button type="button" class="btn btn-warning btn-sm assessment-bulk-trigger" data-operation="deactivate" disabled><i class="fas fa-ban me-1"></i>استبعاد المحدد</button>
        <button type="button" class="btn btn-danger btn-sm assessment-bulk-trigger" data-operation="delete" disabled><i class="fas fa-trash me-1"></i>حذف المحدد</button>
    </div>
</div>
<div class="admin-list-surface">
        <div class="table-responsive admin-table-wrap">
            <table class="table table-hover table-striped align-middle datatable admin-data-table">
                <thead>
                    <tr>
                        <th class="text-center no-sort" data-orderable="false" style="width: 42px;"><input type="checkbox" class="form-check-input assessment-select-page" title="تحديد سجلات الصفحة الحالية" aria-label="تحديد سجلات الصفحة الحالية"></th>
                        <th>البند</th>
                        <th>الأسبوع</th>
                        <th>نوع الأسبوع</th>
                        <th>الحالة</th>
                        <th>الدرجة الأصلية</th>
                        <th>الدرجة البديلة</th>
                        <th class="admin-col-150px">إجراءات</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($rules)): ?>
                        <tr><td colspan="8" class="text-center text-muted py-4">لا توجد قواعد مخصصة للأسابيع بعد.</td></tr>
                    <?php else: ?>
                        <?php foreach ($rules as $rule): ?>
                            <tr>
                                <td class="text-center"><input type="checkbox" class="form-check-input assessment-row-select" value="<?php echo (int) $rule['id']; ?>" aria-label="تحديد قاعدة <?php echo htmlspecialchars($rule['component_name'] . ' - ' . $rule['week_name'], ENT_QUOTES, 'UTF-8'); ?>"></td>
                                <td><strong><?php echo htmlspecialchars($rule['component_name'], ENT_QUOTES, 'UTF-8'); ?></strong><div class="small text-muted"><?php echo htmlspecialchars($rule['subject_name'] . ' - ' . $rule['grade_name'] . ' - ' . $rule['term_name'] . ' - ' . $rule['scheme_name'], ENT_QUOTES, 'UTF-8'); ?></div></td>
                                <td><?php echo htmlspecialchars($rule['week_name'], ENT_QUOTES, 'UTF-8'); ?><div class="small text-muted"><?php echo htmlspecialchars($rule['month_label'] ?? '', ENT_QUOTES, 'UTF-8'); ?></div></td>
                                <td><?php echo htmlspecialchars($rule['week_type'] ?? '', ENT_QUOTES, 'UTF-8'); ?></td>
                                <td><?php echo !empty($rule['is_included']) ? '<span class="badge bg-success">يدخل</span>' : '<span class="badge bg-danger">مستبعد</span>'; ?></td>
                                <td><?php echo htmlspecialchars(AssessmentEngine::formatNumber((float) $rule['default_max_grade']), ENT_QUOTES, 'UTF-8'); ?></td>
                                <td><?php echo $rule['max_grade_override'] !== null ? '<span class="badge bg-warning text-dark">' . htmlspecialchars(AssessmentEngine::formatNumber((float) $rule['max_grade_override']), ENT_QUOTES, 'UTF-8') . '</span>' : '<span class="text-muted">-</span>'; ?></td>
                                <td class="actions-column admin-table-actions">
                                     <button type="button" class="btn btn-sm btn-action-pills btn-edit edit-rule-btn me-1" data-bs-toggle="tooltip" title="تعديل"
                                            data-rule-id="<?php echo (int) $rule['id']; ?>"
                                            data-component-id="<?php echo (int) $rule['component_id']; ?>"
                                            data-week-id="<?php echo (int) $rule['week_id']; ?>"
                                            data-included="<?php echo !empty($rule['is_included']) ? '1' : '0'; ?>"
                                            data-override="<?php echo htmlspecialchars((string) ($rule['max_grade_override'] ?? ''), ENT_QUOTES, 'UTF-8'); ?>"><i class="fas fa-edit"></i></button>
                                    <button type="button" class="btn btn-sm me-1 btn-action-pills <?php echo !empty($rule['is_included']) ? 'btn-deactivate' : 'btn-activate'; ?> toggle-rule-btn"
                                            data-bs-toggle="tooltip"
                                            title="<?php echo !empty($rule['is_included']) ? 'تعطيل' : 'تفعيل'; ?>"
                                            data-rule-id="<?php echo (int) $rule['id']; ?>"
                                            data-rule-name="<?php echo htmlspecialchars($rule['component_name'] . ' - ' . $rule['week_name'], ENT_QUOTES, 'UTF-8'); ?>"
                                            data-current-status="<?php echo !empty($rule['is_included']) ? 'active' : 'inactive'; ?>">
                                        <i class="fas <?php echo !empty($rule['is_included']) ? 'fa-ban' : 'fa-check'; ?>"></i>
                                    </button>
                                     <button type="button" class="btn btn-sm btn-action-pills btn-delete assessment-smart-delete" data-bs-toggle="tooltip" title="حذف"
                                            data-row-id="<?php echo (int) $rule['id']; ?>"
                                            data-row-name="<?php echo htmlspecialchars($rule['component_name'] . ' - ' . $rule['week_name'], ENT_QUOTES, 'UTF-8'); ?>"
                                            data-row-active="<?php echo !empty($rule['is_included']) ? '1' : '0'; ?>"><i class="fas fa-trash"></i></button>
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
$ruleFormFields = static function (string $prefix) use ($components, $weeks, $weekTypeLabels): void {
    $id = static fn(string $name): string => $prefix . ucfirst($name);
?>
    <div class="row g-3">
        <div class="col-md-6">
            <label class="form-label">البند الأسبوعي</label>
            <select name="component_id" id="<?php echo $id('component'); ?>" class="form-select rule-component-select" required>
                <option value="">اختر البند</option>
                <?php foreach ($components as $component): ?>
                    <option value="<?php echo (int) $component['id']; ?>" data-year="<?php echo (int) $component['academic_year_id']; ?>" data-term="<?php echo (int) $component['term_id']; ?>">
                        <?php echo htmlspecialchars($component['subject_name'] . ' - ' . $component['grade_name'] . ' - ' . $component['term_name'] . ' - ' . $component['name'], ENT_QUOTES, 'UTF-8'); ?>
                    </option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="col-md-6">
            <label class="form-label">الأسبوع</label>
            <select name="week_id" id="<?php echo $id('week'); ?>" class="form-select rule-week-select" required>
                <option value="">اختر الأسبوع</option>
                <?php foreach ($weeks as $week): ?>
                    <option value="<?php echo (int) $week['id']; ?>" data-year="<?php echo (int) $week['academic_year_id']; ?>" data-term="<?php echo (int) $week['term_id']; ?>" data-type="<?php echo htmlspecialchars($week['week_type'], ENT_QUOTES, 'UTF-8'); ?>">
                        <?php echo htmlspecialchars($week['term_name'] . ' - ' . $week['name'] . ' - ' . ($week['month_label'] ?? '') . ' - ' . ($weekTypeLabels[$week['week_type']] ?? $week['week_type']), ENT_QUOTES, 'UTF-8'); ?>
                    </option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="col-md-6"><label class="form-label">درجة كبرى بديلة</label><input type="number" name="max_grade_override" id="<?php echo $id('override'); ?>" class="form-control" min="0" max="1000" step="0.01" placeholder="اتركها فارغة لاستخدام درجة البند الأصلية"></div>
        <div class="col-md-6"><div class="form-check mt-4"><input class="form-check-input" type="checkbox" name="is_included" id="<?php echo $id('included'); ?>" value="1" checked><label class="form-check-label" for="<?php echo $id('included'); ?>">يدخل في الرصد</label></div></div>
    </div>
<?php
};
?>

<div class="modal fade" id="addWeekRuleModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-lg"><div class="modal-content admin-modal admin-modal-premium admin-modal-create"><form method="post" action="assessment_component_week_rules.php">
        <?php echo csrfField(); ?><input type="hidden" name="action" value="save_component_week_rule">
        <div class="modal-header"><h5 class="modal-title"><i class="fas fa-plus-circle me-2"></i>إضافة قاعدة أسبوع</h5><button type="button" class="btn-close" data-bs-dismiss="modal"></button></div>
        <div class="modal-body"><?php $ruleFormFields('add'); ?></div>
        <div class="modal-footer"><button type="button" class="btn btn-secondary" data-bs-dismiss="modal"><i class="fas fa-times me-1"></i>إلغاء</button><button type="submit" class="btn btn-success"><i class="fas fa-save me-1"></i>حفظ</button></div>
    </form></div></div>
</div>

<div class="modal fade" id="editWeekRuleModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-lg"><div class="modal-content admin-modal admin-modal-premium admin-modal-edit"><form method="post" action="assessment_component_week_rules.php">
        <?php echo csrfField(); ?><input type="hidden" name="action" value="save_component_week_rule">
        <div class="modal-header"><h5 class="modal-title"><i class="fas fa-edit me-2"></i>تعديل قاعدة الأسبوع</h5><button type="button" class="btn-close" data-bs-dismiss="modal"></button></div>
        <div class="modal-body"><?php $ruleFormFields('edit'); ?></div>
        <div class="modal-footer"><button type="button" class="btn btn-secondary" data-bs-dismiss="modal"><i class="fas fa-times me-1"></i>إلغاء</button><button type="submit" class="btn btn-primary"><i class="fas fa-save me-1"></i>حفظ</button></div>
    </form></div></div>
</div>

<div class="modal fade" id="deleteWeekRuleModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog"><div class="modal-content admin-modal admin-modal-premium admin-modal-delete"><form method="post" action="assessment_component_week_rules.php">
        <?php echo csrfField(); ?><input type="hidden" name="action" value="delete_component_week_rule"><input type="hidden" name="rule_id" id="deleteRuleId">
        <div class="modal-header"><h5 class="modal-title"><i class="fas fa-trash me-2"></i>حذف قاعدة الأسبوع</h5><button type="button" class="btn-close" data-bs-dismiss="modal"></button></div>
        <div class="modal-body text-center"><i class="fas fa-triangle-exclamation text-danger mb-3 admin-modal-icon-lg"></i><p>هل تريد حذف قاعدة <span id="deleteRuleName" class="fw-bold text-primary"></span>؟</p><div class="alert alert-warning text-start">سيمنع النظام الحذف إذا وُجدت درجات مرصودة لنفس البند والأسبوع.</div></div>
        <div class="modal-footer"><button type="button" class="btn btn-secondary" data-bs-dismiss="modal"><i class="fas fa-times me-1"></i>إلغاء</button><button type="submit" class="btn btn-danger"><i class="fas fa-trash me-1"></i>حذف</button></div>
    </form></div></div>
</div>

<div class="modal fade" id="toggleWeekRuleModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog"><div class="modal-content admin-modal admin-modal-premium admin-modal-warning" id="toggleWeekRuleModalContent"><form method="post" action="assessment_component_week_rules.php">
        <?php echo csrfField(); ?><input type="hidden" name="action" value="toggle_component_week_rule"><input type="hidden" name="rule_id" id="toggleRuleId">
        <div class="modal-header"><h5 class="modal-title"><i class="fas fa-toggle-on me-2" id="toggleRuleHeaderIcon"></i>تغيير حالة قاعدة الأسبوع</h5><button type="button" class="btn-close" data-bs-dismiss="modal"></button></div>
        <div class="modal-body">
            <div class="text-center mb-3"><i class="fas fa-toggle-on text-warning admin-modal-icon-lg" id="toggleRuleBodyIcon"></i></div>
            <p class="text-center">هل تريد <span class="fw-bold" id="toggleRuleAction"></span> قاعدة <span class="fw-bold text-primary" id="toggleRuleName"></span>؟</p>
            <div class="alert alert-info mb-0"><i class="fas fa-info-circle me-2"></i>تغيير الحالة يحدد هل يدخل هذا الأسبوع في رصد البند، ولا يحذف أي درجات مرصودة.</div>
        </div>
        <div class="modal-footer"><button type="button" class="btn btn-secondary" data-bs-dismiss="modal"><i class="fas fa-times me-1"></i>إلغاء</button><button type="submit" class="btn btn-warning" id="toggleRuleSubmit"><i class="fas fa-ban me-1"></i><span id="toggleRuleSubmitText">تعطيل</span></button></div>
    </form></div></div>
</div>

<div class="modal fade" id="weekRuleBulkActionModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog"><div class="modal-content admin-modal admin-modal-premium admin-modal-delete"><form method="post" action="assessment_component_week_rules.php">
        <?php echo csrfField(); ?><input type="hidden" name="action" value="assessment_bulk_action"><input type="hidden" name="selected_ids" value="">
        <div class="modal-header"><h5 class="modal-title"><i class="fas fa-list-check me-2"></i><span data-bulk-modal-title>عملية على قواعد الأسابيع</span></h5><button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="إغلاق"></button></div>
        <div class="modal-body"><div class="text-center mb-3"><i class="fas fa-triangle-exclamation text-warning admin-modal-icon-lg"></i></div><p class="text-center" data-bulk-modal-message></p><div class="alert alert-warning mb-0"><i class="fas fa-shield-alt me-2"></i>عدد السجلات: <strong data-bulk-modal-count>0</strong>. وجود درجة مرصودة لنفس البند والأسبوع يلغي حذف الدفعة كلها، ويمكن الاستبعاد دون حذف الدرجات.</div></div>
        <div class="modal-footer"><button type="button" class="btn btn-secondary" data-bs-dismiss="modal"><i class="fas fa-times me-1"></i>إلغاء</button><button type="submit" name="bulk_operation" value="deactivate" class="btn btn-warning" data-bulk-deactivate-submit><i class="fas fa-ban me-1"></i>استبعاد فقط</button><button type="submit" name="bulk_operation" value="delete" class="btn btn-danger" data-bulk-delete-submit><i class="fas fa-trash me-1"></i>حذف</button></div>
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
    function filterWeeks(componentSelect) {
        const modal = componentSelect.closest('.modal');
        if (!modal) {
            return;
        }
        const weekSelect = modal.querySelector('.rule-week-select');
        if (!weekSelect) {
            return;
        }
        const selected = componentSelect.options[componentSelect.selectedIndex];
        const year = selected ? selected.getAttribute('data-year') : '';
        const term = selected ? selected.getAttribute('data-term') : '';
        weekSelect.querySelectorAll('option[data-year]').forEach(function (option) {
            const match = (!year || option.getAttribute('data-year') === year) && (!term || option.getAttribute('data-term') === term);
            option.style.display = match ? '' : 'none';
        });
        const current = weekSelect.options[weekSelect.selectedIndex];
        if (current && current.getAttribute('data-year')) {
            const mismatch = (year && current.getAttribute('data-year') !== year) || (term && current.getAttribute('data-term') !== term);
            if (mismatch) {
                weekSelect.value = '';
            }
        }
    }
    document.querySelectorAll('.rule-component-select').forEach(function (select) {
        select.addEventListener('change', function () {
            filterWeeks(this);
        });
        filterWeeks(select);
    });
    document.querySelectorAll('.edit-rule-btn').forEach(function (button) {
        button.addEventListener('click', function () {
            setValue('editComponent', this.dataset.componentId);
            const componentSelect = document.getElementById('editComponent');
            if (componentSelect) {
                filterWeeks(componentSelect);
            }
            setValue('editWeek', this.dataset.weekId);
            setValue('editOverride', this.dataset.override);
            setChecked('editIncluded', this.dataset.included);
            showModal('editWeekRuleModal');
        });
    });
    document.querySelectorAll('.toggle-rule-btn').forEach(function (button) {
        button.addEventListener('click', function () {
            const isActive = this.dataset.currentStatus === 'active';
            const submitButton = document.getElementById('toggleRuleSubmit');
            const actionText = document.getElementById('toggleRuleAction');
            const submitText = document.getElementById('toggleRuleSubmitText');

            setValue('toggleRuleId', this.dataset.ruleId);
            document.getElementById('toggleRuleName').textContent = this.dataset.ruleName || '';
            actionText.textContent = isActive ? 'تعطيل' : 'تفعيل';
            actionText.className = isActive ? 'fw-bold text-warning' : 'fw-bold text-success';
            submitText.textContent = isActive ? 'تعطيل' : 'تفعيل';
            const modalContent = document.getElementById('toggleWeekRuleModalContent');
            if (modalContent) {
                modalContent.classList.toggle('admin-modal-warning', isActive);
                modalContent.classList.toggle('admin-modal-create', !isActive);
            }
            const bodyIcon = document.getElementById('toggleRuleBodyIcon');
            const headerIcon = document.getElementById('toggleRuleHeaderIcon');
            if (bodyIcon) {
                bodyIcon.className = isActive ? 'fas fa-ban text-warning admin-modal-icon-lg' : 'fas fa-check-circle text-success admin-modal-icon-lg';
            }
            if (headerIcon) {
                headerIcon.className = isActive ? 'fas fa-ban me-2' : 'fas fa-check-circle me-2';
            }
            submitButton.className = isActive ? 'btn btn-warning' : 'btn btn-success';
            submitButton.querySelector('i').className = isActive ? 'fas fa-ban me-1' : 'fas fa-check me-1';
            showModal('toggleWeekRuleModal');
        });
    });
    document.querySelectorAll('.delete-rule-btn').forEach(function (button) {
        button.addEventListener('click', function () {
            setValue('deleteRuleId', this.dataset.ruleId);
            document.getElementById('deleteRuleName').textContent = this.dataset.ruleName || '';
            showModal('deleteWeekRuleModal');
        });
    });
});
</script>
<script src="../assets/js/assessment-bulk-actions.js"></script>
<?php endif; ?>

<?php require_once '../includes/admin_footer.php'; ?>
