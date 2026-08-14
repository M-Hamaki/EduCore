<?php
$page_title = "خطط الدرجات";
$custom_page_title = true;

require_once '../config/database.php';
require_once '../classes/utilities.php';
require_once '../classes/ActivityLog.php';
require_once '../classes/AcademicYear.php';
require_once '../classes/AcademicYearWriteGuard.php';
require_once '../classes/AssessmentEngine.php';
require_once '../classes/AssessmentBulkActionService.php';
require_once '../classes/AssessmentSchemeBatchService.php';
require_once '../classes/AssessmentSchemeReadinessService.php';
require_once '../includes/session_config.php';
require_once '../includes/csrf.php';
Utilities::validateSession('admin');
requireCsrfPost();

$database = new Database();
$db = $database->getConnection();

$success_message = $_SESSION['success_message'] ?? null;
$error_message = $_SESSION['error_message'] ?? null;
unset($_SESSION['success_message'], $_SESSION['error_message']);

function schemes_table_exists(PDO $db, string $table): bool
{
    $stmt = $db->prepare('SELECT 1 FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?');
    $stmt->execute([$table]);
    return (bool) $stmt->fetchColumn();
}

function schemes_column_exists(PDO $db, string $table, string $column): bool
{
    $stmt = $db->prepare('SELECT 1 FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?');
    $stmt->execute([$table, $column]);
    return (bool) $stmt->fetchColumn();
}

function schemes_redirect(): void
{
    header('Location: assessment_schemes.php');
    exit();
}

function schemes_public_error(Throwable $error): string
{
    $cursor = $error;
    while ($cursor !== null) {
        if ($cursor instanceof PDOException) {
            error_log('Assessment schemes database operation failed: ' . $error->getMessage());
            return 'تعذر تنفيذ العملية بسبب خطأ في قاعدة البيانات. حاول مرة أخرى أو راجع سجل النظام.';
        }
        $cursor = $cursor->getPrevious();
    }

    if ($error instanceof InvalidArgumentException || $error instanceof RuntimeException) {
        return $error->getMessage();
    }

    error_log('Assessment schemes operation failed [' . get_class($error) . ']: ' . $error->getMessage());
    return 'تعذر تنفيذ العملية. حاول مرة أخرى.';
}

function schemes_selected($left, $right): string
{
    return (string) $left === (string) $right ? 'selected' : '';
}

function schemes_checked($value): string
{
    return !empty($value) ? 'checked' : '';
}

function schemes_status_badge(string $status): string
{
    if ($status === 'active') {
        return 'bg-success';
    }
    if ($status === 'archived') {
        return 'bg-secondary';
    }
    return 'bg-warning text-dark';
}

function schemes_name_exists(PDO $db, int $assignmentId, int $termId, string $name, int $ignoreId = 0): bool
{
    $sql = 'SELECT id FROM assessment_schemes WHERE subject_assignment_id = ? AND term_id = ? AND name = ?';
    $params = [$assignmentId, $termId, $name];
    if ($ignoreId > 0) {
        $sql .= ' AND id <> ?';
        $params[] = $ignoreId;
    }
    $sql .= ' LIMIT 1';
    $stmt = $db->prepare($sql);
    $stmt->execute($params);
    return (bool) $stmt->fetchColumn();
}

function schemes_fetch_assignment(PDO $db, int $assignmentId): array
{
    $stmt = $db->prepare("SELECT sga.*, s.name AS subject_name, g.grade_name
        FROM subject_grade_assignments sga
        JOIN subjects s ON s.id = sga.subject_id
        JOIN grades g ON g.id = sga.grade_id
        WHERE sga.id = ? AND sga.is_active = 1
        LIMIT 1");
    $stmt->execute([$assignmentId]);
    $assignment = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$assignment) {
        throw new InvalidArgumentException('ربط المادة بالصف غير موجود أو غير نشط.');
    }
    return $assignment;
}

function schemes_assert_selected_year(?int $currentAcademicYearId, array $row, string $message): void
{
    if ($currentAcademicYearId && (int) ($row['academic_year_id'] ?? 0) !== $currentAcademicYearId) {
        throw new InvalidArgumentException($message);
    }
}

function schemes_assert_assignment_term(PDO $db, array $assignment, int $termId): void
{
    $termStmt = $db->prepare('SELECT academic_year_id FROM academic_terms WHERE id = ? AND status = "active" LIMIT 1');
    $termStmt->execute([$termId]);
    $termYearId = (int) $termStmt->fetchColumn();
    if ($termYearId <= 0) {
        throw new InvalidArgumentException('الترم المختار غير موجود أو غير نشط.');
    }
    if ($termYearId !== (int) $assignment['academic_year_id']) {
        throw new InvalidArgumentException('الترم المختار لا يتبع عام ربط المادة.');
    }
    if (!empty($assignment['term_id']) && (int) $assignment['term_id'] !== $termId) {
        throw new InvalidArgumentException('ربط المادة محدد لترم مختلف عن الترم المختار.');
    }
}

function schemes_validate_activation(PDO $db, int $schemeId, float $totalGrade): array
{
    if (!schemes_table_exists($db, 'assessment_components')) {
        throw new RuntimeException('لا يمكن تفعيل الخطة قبل تطبيق جدول بنود التقييم.');
    }
    $stmt = $db->prepare('SELECT COALESCE(SUM(max_grade), 0) AS components_total, COUNT(*) AS components_count
        FROM assessment_components
        WHERE scheme_id = ? AND is_active = 1 AND counts_in_total = 1');
    $stmt->execute([$schemeId]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC) ?: ['components_total' => 0, 'components_count' => 0];
    $componentsTotal = (float) $row['components_total'];
    $componentsCount = (int) $row['components_count'];
    if ($componentsCount <= 0) {
        throw new InvalidArgumentException('لا يمكن تفعيل الخطة قبل إضافة بنود فعالة داخلة في المجموع.');
    }
    if (abs($componentsTotal - $totalGrade) > 0.01) {
        throw new InvalidArgumentException('لا يمكن تفعيل الخطة لأن مجموع البنود الداخلة في المجموع لا يساوي مجموع الخطة.');
    }
    return ['components_total' => $componentsTotal, 'components_count' => $componentsCount];
}

function schemes_count_dependencies(PDO $db, int $schemeId): int
{
    $checks = [
        ['assessment_windows', 'scheme_id'],
        ['student_marks', 'scheme_id'],
        ['report_window_items', 'scheme_id'],
        ['published_report_details', 'scheme_id'],
    ];
    $dependencies = 0;
    foreach ($checks as $check) {
        [$table, $column] = $check;
        if (schemes_table_exists($db, $table) && schemes_column_exists($db, $table, $column)) {
            $stmt = $db->prepare("SELECT COUNT(*) FROM `$table` WHERE `$column` = ?");
            $stmt->execute([$schemeId]);
            $dependencies += (int) $stmt->fetchColumn();
        }
    }
    return $dependencies;
}

function schemes_grouping_ready(PDO $db): bool
{
    foreach (['assessment_scheme_families', 'assessment_scheme_scopes', 'assessment_annual_policies', 'assessment_annual_policy_terms'] as $table) {
        if (!schemes_table_exists($db, $table)) {
            return false;
        }
    }
    foreach (['family_id', 'readiness_status', 'readiness_reason', 'batch_id'] as $column) {
        if (!schemes_column_exists($db, 'assessment_schemes', $column)) {
            return false;
        }
    }
    if (!schemes_column_exists($db, 'assessment_scheme_scopes', 'scope_identity')) {
        return false;
    }
    return true;
}

$schemesReady = schemes_table_exists($db, 'assessment_schemes');
$schemeGroupingReady = $schemesReady && schemes_grouping_ready($db);
$assignmentsReady = schemes_table_exists($db, 'subject_grade_assignments');
$componentsReady = schemes_table_exists($db, 'assessment_components');
$calendarReady = schemes_table_exists($db, 'academic_years') && schemes_table_exists($db, 'academic_terms');

$currentAcademicYear = AcademicYear::getCurrent($db) ?: AcademicYear::getActive($db);
$currentAcademicYearId = $currentAcademicYear ? (int) $currentAcademicYear['id'] : 0;
$currentAcademicYearName = $currentAcademicYear['name'] ?? '';
$batchFilter = trim((string) ($_GET['batch'] ?? ''));
if (!preg_match('/^[a-f0-9]{32}$/i', $batchFilter)) {
    $batchFilter = '';
}

$normalAbsenceLabels = ['zero' => 'الغياب = صفر', 'exclude' => 'استبعاد الغياب', 'note' => 'ملاحظة فقط'];
$excusedAbsenceLabels = ['zero' => 'بعذر = صفر', 'exclude' => 'استبعاد بعذر', 'note' => 'بعذر كملاحظة'];
$roundingModeLabels = ['none' => 'بدون تقريب', 'nearest_half' => 'أقرب نصف', 'integer' => 'رقم صحيح', 'two_decimals' => 'منزلتان'];
$roundingScopeLabels = ['total' => 'المجموع', 'components' => 'البنود', 'both' => 'الكل'];
$statusLabels = ['draft' => 'مسودة', 'active' => 'نشطة', 'archived' => 'معطلة'];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        (new AcademicYearWriteGuard($db))->assertWritable($currentAcademicYearId);
        if (!$schemesReady || !$assignmentsReady || !$calendarReady) {
            throw new RuntimeException('جداول خطط الدرجات أو ربط المواد أو التقويم غير مطبقة بعد.');
        }

        $action = (string) ($_POST['action'] ?? '');

        if ($action === 'assessment_bulk_action') {
            $result = (new AssessmentBulkActionService($db))->execute(
                'scheme',
                (string) ($_POST['bulk_operation'] ?? ''),
                AssessmentBulkActionService::normalizeIds($_POST['selected_ids'] ?? ''),
                $currentAcademicYearId
            );
            $_SESSION['success_message'] = $result['message'];
            schemes_redirect();
        }

        if ($action === 'bulk_copy_schemes') {
            if (!$componentsReady) {
                throw new RuntimeException('جدول بنود التقييم غير مطبق بعد.');
            }
            $result = (new AssessmentBulkActionService($db))->copySchemes(
                AssessmentBulkActionService::normalizeIds($_POST['selected_ids'] ?? ''),
                (int) ($_POST['target_subject_assignment_id'] ?? 0),
                (int) ($_POST['target_term_id'] ?? 0),
                $currentAcademicYearId,
                (int) ($_SESSION['user_id'] ?? 0) ?: null
            );
            $_SESSION['success_message'] = $result['message'];
            schemes_redirect();
        }

        if ($action === 'add_scheme' || $action === 'update_scheme') {
            $schemeId = (int) ($_POST['scheme_id'] ?? 0);
            $assignmentId = (int) ($_POST['subject_assignment_id'] ?? 0);
            $termId = (int) ($_POST['term_id'] ?? 0);
            $name = trim((string) ($_POST['name'] ?? ''));
            $totalGrade = (float) ($_POST['total_grade'] ?? 100);
            $passGrade = trim((string) ($_POST['pass_grade'] ?? ''));
            $countsInTotal = isset($_POST['counts_in_total']) ? 1 : 0;
            $enableExcusedAbsence = isset($_POST['enable_excused_absence']) ? 1 : 0;
            $normalAbsencePolicy = in_array(($_POST['normal_absence_policy'] ?? 'zero'), ['zero', 'exclude', 'note'], true) ? (string) $_POST['normal_absence_policy'] : 'zero';
            $excusedAbsencePolicy = in_array(($_POST['excused_absence_policy'] ?? 'exclude'), ['zero', 'exclude', 'note'], true) ? (string) $_POST['excused_absence_policy'] : 'exclude';
            $roundingEnabled = isset($_POST['rounding_enabled']) ? 1 : 0;
            $roundingMode = in_array(($_POST['rounding_mode'] ?? 'none'), ['none', 'nearest_half', 'integer', 'two_decimals'], true) ? (string) $_POST['rounding_mode'] : 'none';
            $roundingScope = in_array(($_POST['rounding_scope'] ?? 'total'), ['total', 'components', 'both'], true) ? (string) $_POST['rounding_scope'] : 'total';
            $annualEnabled = isset($_POST['annual_result_enabled']) ? 1 : 0;
            $firstTermWeight = (float) ($_POST['first_term_weight'] ?? 50);
            $secondTermWeight = (float) ($_POST['second_term_weight'] ?? 50);
            $status = in_array(($_POST['status'] ?? 'draft'), ['draft', 'active', 'archived'], true) ? (string) $_POST['status'] : 'draft';

            if (($action === 'update_scheme' && $schemeId <= 0) || $assignmentId <= 0 || $termId <= 0 || $name === '') {
                throw new InvalidArgumentException('اختر ربط المادة والترم واكتب اسم الخطة.');
            }
            if ($totalGrade <= 0 || $totalGrade > 1000) {
                throw new InvalidArgumentException('المجموع الكلي للخطة غير صحيح.');
            }
            if ($passGrade !== '' && ((float) $passGrade < 0 || (float) $passGrade > $totalGrade)) {
                throw new InvalidArgumentException('درجة النجاح يجب أن تكون بين صفر ومجموع الخطة.');
            }
            if ($annualEnabled && abs(($firstTermWeight + $secondTermWeight) - 100) > 0.01) {
                throw new InvalidArgumentException('مجموع أوزان الترمين يجب أن يساوي 100%.');
            }
            if ($action === 'add_scheme' && $status === 'active') {
                throw new InvalidArgumentException('أنشئ الخطة كمسودة أولا، ثم أضف البنود وفعّلها بعد مطابقة مجموع البنود لمجموع الخطة.');
            }

            $assignment = schemes_fetch_assignment($db, $assignmentId);
            schemes_assert_selected_year($currentAcademicYearId, $assignment, 'لا يمكن حفظ خطة درجات خارج العام الدراسي المختار.');
            schemes_assert_assignment_term($db, $assignment, $termId);
            if (schemes_name_exists($db, $assignmentId, $termId, $name, $action === 'update_scheme' ? $schemeId : 0)) {
                throw new InvalidArgumentException('توجد خطة بنفس الاسم لنفس المادة/الصف/الترم بالفعل.');
            }

            if ($status === 'active' && $action === 'update_scheme') {
                schemes_validate_activation($db, $schemeId, $totalGrade);
            }

            if ($action === 'add_scheme') {
                $stmt = $db->prepare("INSERT INTO assessment_schemes
                    (academic_year_id, term_id, subject_assignment_id, subject_id, stage_id, grade_id, name,
                     total_grade, pass_grade, counts_in_total, enable_excused_absence,
                     normal_absence_policy, excused_absence_policy, rounding_enabled, rounding_mode,
                     rounding_scope, annual_result_enabled, first_term_weight, second_term_weight,
                     status, created_by)
                    VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
                $stmt->execute([
                    (int) $assignment['academic_year_id'],
                    $termId,
                    $assignmentId,
                    (int) $assignment['subject_id'],
                    $assignment['stage_id'] ? (int) $assignment['stage_id'] : null,
                    (int) $assignment['grade_id'],
                    $name,
                    $totalGrade,
                    $passGrade !== '' ? (float) $passGrade : null,
                    $countsInTotal,
                    $enableExcusedAbsence,
                    $normalAbsencePolicy,
                    $excusedAbsencePolicy,
                    $roundingEnabled,
                    $roundingMode,
                    $roundingScope,
                    $annualEnabled,
                    $firstTermWeight,
                    $secondTermWeight,
                    $status,
                    (int) ($_SESSION['user_id'] ?? 0) ?: null,
                ]);
                $schemeId = (int) $db->lastInsertId();
                ActivityLog::logCreate('assessment_scheme', $schemeId, $name, [
                    'subject' => $assignment['subject_name'],
                    'grade' => $assignment['grade_name'],
                    'term' => $termId,
                    'total_grade' => $totalGrade,
                    'status' => $status,
                ]);
                if (schemes_grouping_ready($db)) {
                    (new AssessmentSchemeReadinessService($db))->refresh($schemeId);
                }
                $_SESSION['success_message'] = 'تم إنشاء خطة الدرجات بنجاح.';
                schemes_redirect();
            }

            $oldStmt = $db->prepare('SELECT * FROM assessment_schemes WHERE id = ? LIMIT 1');
            $oldStmt->execute([$schemeId]);
            $oldScheme = $oldStmt->fetch(PDO::FETCH_ASSOC);
            if (!$oldScheme) {
                throw new InvalidArgumentException('خطة الدرجات غير موجودة.');
            }
            schemes_assert_selected_year($currentAcademicYearId, $oldScheme, 'لا يمكن تعديل خطة درجات خارج العام الدراسي المختار.');
            if (!empty($oldScheme['family_id'])) {
                throw new RuntimeException('لا يمكن تعديل خطة ضمن مجموعة من النموذج الفردي. استخدم إدارة المجموعة حتى لا يتغير نطاق الصفوف أو الترمات بصورة غير متسقة.');
            }
            if ((string) ($oldScheme['status'] ?? '') === 'active') {
                throw new RuntimeException('لا يمكن تعديل خطة نشطة. عطّلها أولا ثم أنشئ خطة بديلة إذا كانت مرتبطة ببيانات تشغيلية.');
            }
            if ($status !== (string) ($oldScheme['status'] ?? 'draft')) {
                throw new RuntimeException('غيّر حالة الخطة من زر التفعيل أو التعطيل المخصص حتى يُطبق فحص الجاهزية بصورة صحيحة.');
            }
            if (schemes_count_dependencies($db, $schemeId) > 0) {
                throw new RuntimeException('لا يمكن تعديل خطة استُخدمت في نوافذ رصد أو درجات أو تقارير. أنشئ خطة بديلة للحفاظ على البيانات التاريخية.');
            }

            $stmt = $db->prepare("UPDATE assessment_schemes
                SET academic_year_id = ?, term_id = ?, subject_assignment_id = ?, subject_id = ?, stage_id = ?, grade_id = ?,
                    name = ?, total_grade = ?, pass_grade = ?, counts_in_total = ?, enable_excused_absence = ?,
                    normal_absence_policy = ?, excused_absence_policy = ?, rounding_enabled = ?, rounding_mode = ?,
                    rounding_scope = ?, annual_result_enabled = ?, first_term_weight = ?, second_term_weight = ?, status = ?
                WHERE id = ?");
            $stmt->execute([
                (int) $assignment['academic_year_id'],
                $termId,
                $assignmentId,
                (int) $assignment['subject_id'],
                $assignment['stage_id'] ? (int) $assignment['stage_id'] : null,
                (int) $assignment['grade_id'],
                $name,
                $totalGrade,
                $passGrade !== '' ? (float) $passGrade : null,
                $countsInTotal,
                $enableExcusedAbsence,
                $normalAbsencePolicy,
                $excusedAbsencePolicy,
                $roundingEnabled,
                $roundingMode,
                $roundingScope,
                $annualEnabled,
                $firstTermWeight,
                $secondTermWeight,
                $status,
                $schemeId,
            ]);
            if (schemes_grouping_ready($db)) {
                (new AssessmentSchemeReadinessService($db))->refresh($schemeId);
            }
            ActivityLog::logUpdate('assessment_scheme', $schemeId, $name, [
                'old_name' => $oldScheme['name'],
                'new_name' => $name,
                'subject' => $assignment['subject_name'],
                'grade' => $assignment['grade_name'],
                'total_grade' => $totalGrade,
                'status' => $status,
            ]);
            $_SESSION['success_message'] = 'تم تعديل خطة الدرجات بنجاح.';
            schemes_redirect();
        }

        if ($action === 'copy_scheme') {
            if (!$componentsReady) {
                throw new RuntimeException('جدول بنود التقييم غير مطبق بعد.');
            }
            $sourceSchemeId = (int) ($_POST['source_scheme_id'] ?? 0);
            $targetAssignmentId = (int) ($_POST['target_subject_assignment_id'] ?? 0);
            $targetTermId = (int) ($_POST['target_term_id'] ?? 0);
            $targetName = trim((string) ($_POST['target_name'] ?? ''));
            $targetTotalRaw = trim((string) ($_POST['target_total_grade'] ?? ''));
            $scaleComponents = isset($_POST['scale_components']);
            if ($sourceSchemeId <= 0 || $targetAssignmentId <= 0 || $targetTermId <= 0) {
                throw new InvalidArgumentException('اختر الخطة المصدر وربط المادة/الصف الهدف والترم.');
            }
            $sourceStmt = $db->prepare('SELECT * FROM assessment_schemes WHERE id = ? LIMIT 1');
            $sourceStmt->execute([$sourceSchemeId]);
            $source = $sourceStmt->fetch(PDO::FETCH_ASSOC);
            if (!$source) {
                throw new InvalidArgumentException('الخطة المصدر غير موجودة.');
            }
            schemes_assert_selected_year($currentAcademicYearId, $source, 'لا يمكن نسخ خطة درجات من خارج العام الدراسي المختار.');
            $sourceTotal = (float) $source['total_grade'];
            $targetTotal = $targetTotalRaw !== '' ? (float) $targetTotalRaw : $sourceTotal;
            if ($targetTotal <= 0) {
                throw new InvalidArgumentException('مجموع الخطة الهدف يجب أن يكون أكبر من صفر.');
            }
            $gradeScale = ($scaleComponents && $sourceTotal > 0 && abs($targetTotal - $sourceTotal) > 0.01)
                ? ($targetTotal / $sourceTotal)
                : 1.0;
            $targetPassGrade = $source['pass_grade'] !== null ? (float) $source['pass_grade'] : null;
            if ($targetPassGrade !== null && $gradeScale !== 1.0) {
                $targetPassGrade = round($targetPassGrade * $gradeScale, 2);
            }
            $assignment = schemes_fetch_assignment($db, $targetAssignmentId);
            schemes_assert_selected_year($currentAcademicYearId, $assignment, 'لا يمكن نسخ خطة درجات إلى ربط خارج العام الدراسي المختار.');
            schemes_assert_assignment_term($db, $assignment, $targetTermId);
            $targetName = $targetName !== '' ? $targetName : ('نسخة من ' . $source['name']);
            if ((int) $source['subject_assignment_id'] === $targetAssignmentId && (int) $source['term_id'] === $targetTermId) {
                throw new InvalidArgumentException('لا يمكن نسخ الخطة إلى نفس ربط المادة ونفس الترم.');
            }
            if (schemes_name_exists($db, $targetAssignmentId, $targetTermId, $targetName)) {
                throw new InvalidArgumentException('توجد خطة بنفس الاسم في الهدف المحدد بالفعل.');
            }

            $db->beginTransaction();
            $stmt = $db->prepare("INSERT INTO assessment_schemes
                (academic_year_id, term_id, subject_assignment_id, subject_id, stage_id, grade_id, name,
                 total_grade, pass_grade, counts_in_total, enable_excused_absence,
                 normal_absence_policy, excused_absence_policy, rounding_enabled, rounding_mode,
                 rounding_scope, annual_result_enabled, first_term_weight, second_term_weight,
                 status, copied_from_scheme_id, created_by)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'draft', ?, ?)");
            $stmt->execute([
                (int) $assignment['academic_year_id'],
                $targetTermId,
                $targetAssignmentId,
                (int) $assignment['subject_id'],
                $assignment['stage_id'] ? (int) $assignment['stage_id'] : null,
                (int) $assignment['grade_id'],
                $targetName,
                $targetTotal,
                $targetPassGrade,
                (int) $source['counts_in_total'],
                (int) $source['enable_excused_absence'],
                $source['normal_absence_policy'],
                $source['excused_absence_policy'],
                (int) $source['rounding_enabled'],
                $source['rounding_mode'],
                $source['rounding_scope'],
                (int) $source['annual_result_enabled'],
                (float) $source['first_term_weight'],
                (float) $source['second_term_weight'],
                $sourceSchemeId,
                (int) ($_SESSION['user_id'] ?? 0) ?: null,
            ]);
            $targetSchemeId = (int) $db->lastInsertId();
            $copiedCount = (new AssessmentEngine($db))->copySchemeComponents($sourceSchemeId, $targetSchemeId, $gradeScale);
            $db->commit();
            ActivityLog::logCreate('assessment_scheme', $targetSchemeId, $targetName, [
                'source_scheme_id' => $sourceSchemeId,
                'target_scheme_id' => $targetSchemeId,
                'target_total_grade' => $targetTotal,
                'grade_scale' => $gradeScale,
                'count' => $copiedCount,
            ]);
            $_SESSION['success_message'] = "تم نسخ الخطة وإنشاء {$copiedCount} بندا في الخطة الجديدة.";
            schemes_redirect();
        }

        if ($action === 'apply_component_template') {
            if (!$componentsReady) {
                throw new RuntimeException('جدول بنود التقييم غير مطبق بعد.');
            }
            $schemeId = (int) ($_POST['scheme_id'] ?? 0);
            $templateKey = (string) ($_POST['template_key'] ?? '');
            $replaceExisting = isset($_POST['replace_existing']);
            $scaleTemplate = isset($_POST['scale_template']);
            $schemeStmt = $db->prepare('SELECT * FROM assessment_schemes WHERE id = ? LIMIT 1');
            $schemeStmt->execute([$schemeId]);
            $scheme = $schemeStmt->fetch(PDO::FETCH_ASSOC);
            if (!$scheme || $templateKey === '') {
                throw new InvalidArgumentException('اختر الخطة والقالب المطلوب تطبيقه.');
            }
            schemes_assert_selected_year($currentAcademicYearId, $scheme, 'لا يمكن تطبيق قالب على خطة خارج العام الدراسي المختار.');
            if (($scheme['status'] ?? '') === 'active') {
                throw new InvalidArgumentException('لا يمكن تطبيق قالب على خطة نشطة. أعدها إلى مسودة أولا.');
            }
            $templates = AssessmentEngine::componentTemplates();
            if (!isset($templates[$templateKey])) {
                throw new InvalidArgumentException('قالب البنود غير معروف.');
            }
            $templateTotal = (float) $templates[$templateKey]['total_grade'];
            $schemeTotal = (float) $scheme['total_grade'];
            $gradeScale = 1.0;
            if (abs($templateTotal - $schemeTotal) > 0.01) {
                if (!$scaleTemplate || $templateTotal <= 0) {
                    throw new InvalidArgumentException('مجموع القالب لا يساوي مجموع الخطة. فعّل خيار تحجيم درجات القالب لمجموع الخطة إذا أردت استخدامه.');
                }
                $gradeScale = $schemeTotal / $templateTotal;
            }
            $createdCount = (new AssessmentEngine($db))->applyComponentTemplate($schemeId, $templateKey, $replaceExisting, $gradeScale);
            if (schemes_grouping_ready($db)) {
                (new AssessmentSchemeReadinessService($db))->refresh($schemeId);
            }
            ActivityLog::logUpdate('assessment_scheme', $schemeId, (string) $scheme['name'], [
                'template' => $templates[$templateKey]['label'],
                'count' => $createdCount,
                'replace_existing' => $replaceExisting ? 1 : 0,
                'grade_scale' => $gradeScale,
            ]);
            $_SESSION['success_message'] = "تم تطبيق القالب وإنشاء {$createdCount} بندا.";
            schemes_redirect();
        }

        if ($action === 'update_scheme_status') {
            $schemeId = (int) ($_POST['scheme_id'] ?? 0);
            $newStatus = in_array(($_POST['new_status'] ?? ''), ['draft', 'active', 'archived'], true) ? (string) $_POST['new_status'] : '';
            $schemeStmt = $db->prepare('SELECT * FROM assessment_schemes WHERE id = ? LIMIT 1');
            $schemeStmt->execute([$schemeId]);
            $scheme = $schemeStmt->fetch(PDO::FETCH_ASSOC);
            if (!$scheme || $newStatus === '') {
                throw new InvalidArgumentException('بيانات تغيير حالة الخطة غير صحيحة.');
            }
            schemes_assert_selected_year($currentAcademicYearId, $scheme, 'لا يمكن تغيير حالة خطة خارج العام الدراسي المختار.');
            $expectedStatus = (string) ($scheme['status'] ?? '') === 'active' ? 'archived' : 'active';
            if ($newStatus !== $expectedStatus) {
                throw new InvalidArgumentException('انتقال حالة الخطة غير صالح. استخدم زر التفعيل أو التعطيل الظاهر في الجدول.');
            }
            if (!empty($scheme['family_id'])) {
                $familyService = new AssessmentSchemeBatchService($db);
                if ($newStatus === 'active') {
                    $changed = $familyService->activateFamily($currentAcademicYearId, (int) $scheme['family_id']);
                    $_SESSION['success_message'] = 'تم تفعيل ' . count($changed) . ' خطة ضمن المجموعة بعد التحقق من الجاهزية.';
                } elseif ($newStatus === 'archived') {
                    $changed = $familyService->archiveFamily($currentAcademicYearId, (int) $scheme['family_id']);
                    $_SESSION['success_message'] = 'تم تعطيل ' . count($changed) . ' خطة ضمن المجموعة كوحدة واحدة.';
                } else {
                    throw new InvalidArgumentException('الحالة المطلوبة للمجموعة غير صالحة.');
                }
                schemes_redirect();
            }
            if ($newStatus === 'active' && schemes_grouping_ready($db)) {
                (new AssessmentSchemeBatchService($db))->activate($currentAcademicYearId, [$schemeId]);
                $_SESSION['success_message'] = 'تم تفعيل خطة الدرجات بعد التحقق من نطاقها وربط المادة وبنود التقييم.';
                schemes_redirect();
            }
            $activationSummary = null;
            if ($newStatus === 'active') {
                $activationSummary = schemes_validate_activation($db, $schemeId, (float) $scheme['total_grade']);
            }
            $db->prepare('UPDATE assessment_schemes SET status = ? WHERE id = ?')->execute([$newStatus, $schemeId]);
            ActivityLog::logUpdate('assessment_scheme', $schemeId, (string) $scheme['name'], [
                'old_status' => $scheme['status'],
                'new_status' => $newStatus,
                'components_total' => $activationSummary['components_total'] ?? null,
                'components_count' => $activationSummary['components_count'] ?? null,
            ]);
            $_SESSION['success_message'] = 'تم تحديث حالة خطة الدرجات بنجاح.';
            schemes_redirect();
        }

        if ($action === 'delete_scheme') {
            $schemeId = (int) ($_POST['scheme_id'] ?? 0);
            $schemeStmt = $db->prepare('SELECT * FROM assessment_schemes WHERE id = ? LIMIT 1');
            $schemeStmt->execute([$schemeId]);
            $scheme = $schemeStmt->fetch(PDO::FETCH_ASSOC);
            if (!$scheme) {
                throw new InvalidArgumentException('خطة الدرجات غير موجودة.');
            }
            schemes_assert_selected_year($currentAcademicYearId, $scheme, 'لا يمكن حذف خطة خارج العام الدراسي المختار.');
            if (!empty($scheme['family_id'])) {
                throw new RuntimeException('لا يمكن حذف خطة تابعة لمجموعة بصورة منفردة؛ احذف أو عدّل المجموعة كوحدة واحدة.');
            }
            if (($scheme['status'] ?? '') === 'active') {
                throw new RuntimeException('لا يمكن حذف خطة نشطة. عطّلها أولا ثم حاول الحذف.');
            }
            if (schemes_count_dependencies($db, $schemeId) > 0) {
                throw new RuntimeException('لا يمكن حذف الخطة لوجود نوافذ رصد أو درجات أو تقارير مرتبطة بها. يمكن تعطيلها بدلا من الحذف.');
            }
            $componentCount = 0;
            if ($componentsReady) {
                $countStmt = $db->prepare('SELECT COUNT(*) FROM assessment_components WHERE scheme_id = ?');
                $countStmt->execute([$schemeId]);
                $componentCount = (int) $countStmt->fetchColumn();
            }
            $db->prepare('DELETE FROM assessment_schemes WHERE id = ?')->execute([$schemeId]);
            ActivityLog::logDelete('assessment_scheme', $schemeId, (string) $scheme['name'], ['components_deleted' => $componentCount]);
            $_SESSION['success_message'] = 'تم حذف خطة الدرجات بنجاح.';
            schemes_redirect();
        }
    } catch (Throwable $e) {
        if ($db->inTransaction()) {
            $db->rollBack();
        }
        $_SESSION['error_message'] = schemes_public_error($e);
        schemes_redirect();
    }
}

$activeSubjectAssignments = [];
$terms = [];
$schemes = [];
$annualPolicyByFamily = [];
$componentTemplates = AssessmentEngine::componentTemplates();
$schemesCount = 0;
$activeSchemesCount = 0;
$draftSchemesCount = 0;
$unbalancedSchemesCount = 0;

if ($calendarReady) {
    $termSql = "SELECT t.*, ay.name AS academic_year_name
        FROM academic_terms t
        JOIN academic_years ay ON ay.id = t.academic_year_id
        WHERE t.status = 'active'";
    $termParams = [];
    if ($currentAcademicYearId > 0) {
        $termSql .= ' AND t.academic_year_id = ?';
        $termParams[] = $currentAcademicYearId;
    }
    $termSql .= ' ORDER BY t.term_order ASC, t.id ASC';
    $termStmt = $db->prepare($termSql);
    $termStmt->execute($termParams);
    $terms = $termStmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
}

if ($assignmentsReady) {
    $assignmentSql = "SELECT sga.*, ay.name AS academic_year_name, t.name AS term_name,
            s.name AS subject_name, g.grade_name, c.name AS class_name
        FROM subject_grade_assignments sga
        JOIN academic_years ay ON ay.id = sga.academic_year_id
        LEFT JOIN academic_terms t ON t.id = sga.term_id
        JOIN subjects s ON s.id = sga.subject_id
        JOIN grades g ON g.id = sga.grade_id
        LEFT JOIN classes c ON c.id = sga.class_id
        WHERE sga.is_active = 1";
    $assignmentParams = [];
    if ($currentAcademicYearId > 0) {
        $assignmentSql .= ' AND sga.academic_year_id = ?';
        $assignmentParams[] = $currentAcademicYearId;
    }
    $assignmentSql .= ' ORDER BY s.name, g.grade_order, c.name';
    $assignmentStmt = $db->prepare($assignmentSql);
    $assignmentStmt->execute($assignmentParams);
    $activeSubjectAssignments = $assignmentStmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
}

if ($schemesReady) {
    $componentsSelect = $componentsReady
        ? 'COALESCE(component_totals.components_total, 0) AS components_total, COALESCE(component_totals.components_count, 0) AS components_count'
        : '0 AS components_total, 0 AS components_count';
    $componentsJoin = $componentsReady
        ? "LEFT JOIN (
                SELECT scheme_id, SUM(max_grade) AS components_total, COUNT(*) AS components_count
                FROM assessment_components
                WHERE is_active = 1 AND counts_in_total = 1
                GROUP BY scheme_id
            ) component_totals ON component_totals.scheme_id = sch.id"
        : '';
    $schemeSql = "SELECT sch.*, ay.name AS academic_year_name, t.name AS term_name,
            s.name AS subject_name, g.grade_name, {$componentsSelect}
        FROM assessment_schemes sch
        JOIN academic_years ay ON ay.id = sch.academic_year_id
        JOIN academic_terms t ON t.id = sch.term_id
        JOIN subjects s ON s.id = sch.subject_id
        JOIN grades g ON g.id = sch.grade_id
        {$componentsJoin}";
    $schemeParams = [];
    if ($currentAcademicYearId > 0) {
        $schemeSql .= ' WHERE sch.academic_year_id = ?';
        $schemeParams[] = $currentAcademicYearId;
    }
    if ($batchFilter !== '' && $schemeGroupingReady) {
        $schemeSql .= $schemeParams === [] ? ' WHERE sch.batch_id = ?' : ' AND sch.batch_id = ?';
        $schemeParams[] = $batchFilter;
    }
    $schemeSql .= ' ORDER BY t.term_order ASC, s.name, g.grade_order, sch.name';
    $schemeStmt = $db->prepare($schemeSql);
    $schemeStmt->execute($schemeParams);
    $schemes = $schemeStmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

    if ($schemeGroupingReady) {
        $familyIds = array_values(array_unique(array_filter(array_map(
            static fn(array $scheme): int => (int) ($scheme['family_id'] ?? 0),
            $schemes
        ))));
        if ($familyIds !== []) {
            $placeholders = implode(',', array_fill(0, count($familyIds), '?'));
            $policyStmt = $db->prepare("SELECT p.family_id, p.is_enabled, apt.term_id, apt.weight,
                    t.name AS term_name, t.term_order
                FROM assessment_annual_policies p
                LEFT JOIN assessment_annual_policy_terms apt ON apt.policy_id = p.id
                LEFT JOIN academic_terms t ON t.id = apt.term_id
                WHERE p.family_id IN ({$placeholders})
                ORDER BY p.family_id, t.term_order, apt.term_id");
            $policyStmt->execute($familyIds);
            foreach ($policyStmt->fetchAll(PDO::FETCH_ASSOC) ?: [] as $policyRow) {
                $familyId = (int) $policyRow['family_id'];
                if (!isset($annualPolicyByFamily[$familyId])) {
                    $annualPolicyByFamily[$familyId] = [
                        'enabled' => (int) $policyRow['is_enabled'] === 1,
                        'weights' => [],
                    ];
                }
                if ($policyRow['term_id'] !== null) {
                    $annualPolicyByFamily[$familyId]['weights'][] = [
                        'term_name' => (string) ($policyRow['term_name'] ?? ''),
                        'weight' => (float) $policyRow['weight'],
                    ];
                }
            }
        }
    }
    $schemesCount = count($schemes);
    foreach ($schemes as $scheme) {
        if (($scheme['status'] ?? '') === 'active') {
            $activeSchemesCount++;
        }
        if (($scheme['status'] ?? '') === 'draft') {
            $draftSchemesCount++;
        }
        $componentsTotal = (float) ($scheme['components_total'] ?? 0);
        $schemeTotal = (float) ($scheme['total_grade'] ?? 0);
        if (abs($componentsTotal - $schemeTotal) > 0.01 || (int) ($scheme['components_count'] ?? 0) <= 0) {
            $unbalancedSchemesCount++;
        }
    }
}

require_once '../includes/admin_header.php';
?>

<div class="admin-page-heading">
    <h1 class="h2"><i class="fas fa-diagram-project me-2 text-primary"></i>خطط الدرجات</h1>
    <div class="admin-top-actions no-print">
        <?php if ($schemesReady && $assignmentsReady && $calendarReady): ?>
            <button type="button" class="btn btn-header-premium btn-success shadow-sm" data-bs-toggle="modal" data-bs-target="#assessmentSchemeBatchModal">
                <i class="fas fa-layer-group me-1"></i>إنشاء خطط
            </button>
            <button type="button" class="btn btn-header-premium btn-import-soft" data-bs-toggle="modal" data-bs-target="#addSchemeModal">
                <i class="fas fa-plus-circle me-1"></i>إضافة مفردة
            </button>
            <button type="button" class="btn btn-header-premium btn-print-soft" data-bs-toggle="modal" data-bs-target="#copySchemeModal">
                <i class="fas fa-copy me-1"></i>نسخ خطة
            </button>
            <button type="button" class="btn btn-header-premium btn-print-soft" data-bs-toggle="modal" data-bs-target="#templateModal">
                <i class="fas fa-wand-magic-sparkles me-1"></i>تطبيق قالب
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



<?php if (!$schemesReady || !$assignmentsReady || !$calendarReady): ?>
    <div class="alert alert-warning">
        <i class="fas fa-triangle-exclamation me-2"></i>طبّق جداول محرك الدرجات وربط المواد والتقويم أولا.
    </div>
<?php else: ?>

<div class="row row-cols-2 row-cols-md-4 g-3 mb-4">
    <div class="col">
        <div class="stat-card" style="--card-gradient: linear-gradient(135deg, #3b82f6, #2563eb);">
            <div class="stat-card-icon"><i class="fas fa-diagram-project"></i></div>
            <div class="stat-card-info">
                <div class="stat-card-number counter" data-target="<?php echo (int)$schemesCount; ?>">0</div>
                <div class="stat-card-label">إجمالي الخطط</div>
                <div class="stat-card-sub">للعام الحالي</div>
            </div>
        </div>
    </div>
    <div class="col">
        <div class="stat-card" style="--card-gradient: linear-gradient(135deg, #10b981, #059669);">
            <div class="stat-card-icon"><i class="fas fa-check-circle"></i></div>
            <div class="stat-card-info">
                <div class="stat-card-number counter" data-target="<?php echo (int)$activeSchemesCount; ?>">0</div>
                <div class="stat-card-label">خطط نشطة</div>
                <div class="stat-card-sub">جاهزة للرصد</div>
            </div>
        </div>
    </div>
    <div class="col">
        <div class="stat-card" style="--card-gradient: linear-gradient(135deg, #f59e0b, #d97706);">
            <div class="stat-card-icon"><i class="fas fa-pen-ruler"></i></div>
            <div class="stat-card-info">
                <div class="stat-card-number counter" data-target="<?php echo (int)$draftSchemesCount; ?>">0</div>
                <div class="stat-card-label">مسودات</div>
                <div class="stat-card-sub">قابلة للتعديل</div>
            </div>
        </div>
    </div>
    <div class="col">
        <div class="stat-card" style="--card-gradient: linear-gradient(135deg, #ef4444, #dc2626);">
            <div class="stat-card-icon"><i class="fas fa-scale-unbalanced-flip"></i></div>
            <div class="stat-card-info">
                <div class="stat-card-number counter" data-target="<?php echo (int)$unbalancedSchemesCount; ?>">0</div>
                <div class="stat-card-label">تحتاج ضبط بنود</div>
                <div class="stat-card-sub"><?php echo htmlspecialchars($currentAcademicYearName ?: 'العام الحالي', ENT_QUOTES, 'UTF-8'); ?></div>
            </div>
        </div>
    </div>
</div>

<div data-assessment-bulk-root data-bulk-modal="schemeBulkActionModal" data-entity-label="خطط الدرجات" data-deactivate-label="تعطيل">
<div class="admin-bulk-action-bar d-none">
    <div class="admin-bulk-info">
        <span>السجلات المحددة:</span>
        <span class="admin-bulk-badge" data-assessment-selected-count>0</span>
    </div>
    <div class="admin-bulk-actions">
        <button type="button" class="btn btn-warning btn-sm assessment-bulk-trigger" data-operation="deactivate" disabled><i class="fas fa-ban me-1"></i>تعطيل المحدد</button>
        <button type="button" class="btn btn-primary btn-sm assessment-bulk-trigger" data-copy-target="bulkCopySchemeIds" data-copy-modal="bulkCopySchemeModal" disabled><i class="fas fa-copy me-1"></i>نسخ المحدد</button>
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
                        <th>الترم</th>
                        <th>المادة</th>
                        <th>الصف</th>
                        <th>المجموع</th>
                        <th>بنود المجموع</th>
                        <th>سياسات</th>
                        <th>نهاية العام</th>
                        <th>الحالة</th>
                        <th>الجاهزية</th>
                        <th class="admin-col-180px">إجراءات</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($schemes)): ?>
                        <tr><td colspan="12" class="text-center text-muted py-4">لم يتم إنشاء خطط درجات بعد.</td></tr>
                    <?php else: ?>
                        <?php foreach ($schemes as $scheme): ?>
                            <?php
                            $componentsTotal = (float) ($scheme['components_total'] ?? 0);
                            $schemeTotal = (float) $scheme['total_grade'];
                            $isBalanced = abs($componentsTotal - $schemeTotal) <= 0.01 && (int) ($scheme['components_count'] ?? 0) > 0;
                            $readinessStatus = (string) ($scheme['readiness_status'] ?? 'legacy');
                            $readinessLabels = ['ready' => 'جاهزة', 'waiting_for_subject_link' => 'تنتظر ربط المادة', 'needs_components' => 'تحتاج بنودًا', 'legacy' => 'تحتاج ترقية'];
                            $readinessClass = $readinessStatus === 'ready' ? 'bg-success' : ($readinessStatus === 'legacy' ? 'bg-secondary' : 'bg-warning text-dark');
                            $familyAnnualPolicy = !empty($scheme['family_id'])
                                ? ($annualPolicyByFamily[(int) $scheme['family_id']] ?? null)
                                : null;
                            $annualEnabled = $familyAnnualPolicy !== null
                                ? (bool) $familyAnnualPolicy['enabled']
                                : !empty($scheme['annual_result_enabled']);
                            $annualWeightLabels = [];
                            if ($familyAnnualPolicy !== null) {
                                foreach ($familyAnnualPolicy['weights'] as $annualWeight) {
                                    $termLabel = trim((string) $annualWeight['term_name']);
                                    $weightLabel = AssessmentEngine::formatNumber((float) $annualWeight['weight']) . '%';
                                    $annualWeightLabels[] = $termLabel !== '' ? ($termLabel . ': ' . $weightLabel) : $weightLabel;
                                }
                            } elseif ($annualEnabled) {
                                $annualWeightLabels[] = AssessmentEngine::formatNumber((float) $scheme['first_term_weight']) . '%';
                                $annualWeightLabels[] = AssessmentEngine::formatNumber((float) $scheme['second_term_weight']) . '%';
                            }
                            ?>
                            <tr>
                                <td class="text-center"><input type="checkbox" class="form-check-input assessment-row-select" value="<?php echo (int) $scheme['id']; ?>" aria-label="تحديد خطة <?php echo htmlspecialchars($scheme['name'], ENT_QUOTES, 'UTF-8'); ?>" <?php echo !empty($scheme['family_id']) ? 'disabled title="تُدار الخطة ضمن مجموعتها"' : ''; ?>></td>
                                <td><strong><?php echo htmlspecialchars($scheme['name'], ENT_QUOTES, 'UTF-8'); ?></strong><?php if (!empty($scheme['family_id'])): ?><div class="small text-primary"><i class="fas fa-layer-group me-1"></i>ضمن مجموعة مترابطة</div><?php endif; ?></td>
                                <td><?php echo htmlspecialchars($scheme['term_name'], ENT_QUOTES, 'UTF-8'); ?><div class="small text-muted"><?php echo htmlspecialchars($scheme['academic_year_name'], ENT_QUOTES, 'UTF-8'); ?></div></td>
                                <td><?php echo htmlspecialchars($scheme['subject_name'], ENT_QUOTES, 'UTF-8'); ?></td>
                                <td><?php echo htmlspecialchars($scheme['grade_name'], ENT_QUOTES, 'UTF-8'); ?></td>
                                <td><?php echo htmlspecialchars(AssessmentEngine::formatNumber($schemeTotal), ENT_QUOTES, 'UTF-8'); ?><div class="small text-muted">نجاح: <?php echo $scheme['pass_grade'] !== null ? htmlspecialchars(AssessmentEngine::formatNumber((float) $scheme['pass_grade']), ENT_QUOTES, 'UTF-8') : '-'; ?></div></td>
                                <td>
                                    <span class="badge <?php echo $isBalanced ? 'bg-success' : 'bg-danger'; ?>">
                                        <?php echo htmlspecialchars(AssessmentEngine::formatNumber($componentsTotal), ENT_QUOTES, 'UTF-8'); ?> /
                                        <?php echo htmlspecialchars(AssessmentEngine::formatNumber($schemeTotal), ENT_QUOTES, 'UTF-8'); ?>
                                    </span>
                                    <div class="small text-muted"><?php echo number_format((int) ($scheme['components_count'] ?? 0)); ?> بند</div>
                                </td>
                                <td>
                                    <div><?php echo htmlspecialchars($normalAbsenceLabels[$scheme['normal_absence_policy']] ?? $scheme['normal_absence_policy'], ENT_QUOTES, 'UTF-8'); ?></div>
                                    <?php if (!empty($scheme['enable_excused_absence'])): ?><span class="badge bg-info text-dark"><?php echo htmlspecialchars($excusedAbsenceLabels[$scheme['excused_absence_policy']] ?? 'غياب بعذر', ENT_QUOTES, 'UTF-8'); ?></span><?php endif; ?>
                                    <?php if (!empty($scheme['rounding_enabled'])): ?><span class="badge bg-primary"><?php echo htmlspecialchars($roundingModeLabels[$scheme['rounding_mode']] ?? $scheme['rounding_mode'], ENT_QUOTES, 'UTF-8'); ?></span><?php endif; ?>
                                </td>
                                <td>
                                    <?php if ($annualEnabled): ?>
                                        <span class="badge bg-success">مفعل</span>
                                        <?php if ($annualWeightLabels !== []): ?><div class="small text-muted"><?php echo htmlspecialchars(implode(' / ', $annualWeightLabels), ENT_QUOTES, 'UTF-8'); ?></div><?php endif; ?>
                                    <?php else: ?>
                                        <span class="badge bg-secondary">غير مفعل</span>
                                    <?php endif; ?>
                                </td>
                                <td><span class="badge <?php echo schemes_status_badge((string) $scheme['status']); ?>"><?php echo htmlspecialchars($statusLabels[$scheme['status']] ?? $scheme['status'], ENT_QUOTES, 'UTF-8'); ?></span></td>
                                <td><span class="badge <?php echo $readinessClass; ?>" title="<?php echo htmlspecialchars((string) ($scheme['readiness_reason'] ?? ''), ENT_QUOTES, 'UTF-8'); ?>"><?php echo htmlspecialchars($readinessLabels[$readinessStatus] ?? $readinessStatus, ENT_QUOTES, 'UTF-8'); ?></span></td>
                                <td class="actions-column admin-table-actions">
                                    <?php if (empty($scheme['family_id']) && (string) ($scheme['status'] ?? '') !== 'active'): ?><button type="button" class="btn btn-sm btn-action-pills btn-edit edit-scheme-btn me-1" data-bs-toggle="tooltip" title="تعديل"
                                            data-scheme-id="<?php echo (int) $scheme['id']; ?>"
                                            data-assignment-id="<?php echo (int) $scheme['subject_assignment_id']; ?>"
                                            data-term-id="<?php echo (int) $scheme['term_id']; ?>"
                                            data-name="<?php echo htmlspecialchars($scheme['name'], ENT_QUOTES, 'UTF-8'); ?>"
                                            data-total-grade="<?php echo htmlspecialchars((string) $scheme['total_grade'], ENT_QUOTES, 'UTF-8'); ?>"
                                            data-pass-grade="<?php echo htmlspecialchars((string) ($scheme['pass_grade'] ?? ''), ENT_QUOTES, 'UTF-8'); ?>"
                                            data-counts-in-total="<?php echo !empty($scheme['counts_in_total']) ? '1' : '0'; ?>"
                                            data-enable-excused="<?php echo !empty($scheme['enable_excused_absence']) ? '1' : '0'; ?>"
                                            data-normal-policy="<?php echo htmlspecialchars((string) $scheme['normal_absence_policy'], ENT_QUOTES, 'UTF-8'); ?>"
                                            data-excused-policy="<?php echo htmlspecialchars((string) $scheme['excused_absence_policy'], ENT_QUOTES, 'UTF-8'); ?>"
                                            data-rounding-enabled="<?php echo !empty($scheme['rounding_enabled']) ? '1' : '0'; ?>"
                                            data-rounding-mode="<?php echo htmlspecialchars((string) $scheme['rounding_mode'], ENT_QUOTES, 'UTF-8'); ?>"
                                            data-rounding-scope="<?php echo htmlspecialchars((string) $scheme['rounding_scope'], ENT_QUOTES, 'UTF-8'); ?>"
                                            data-annual-enabled="<?php echo !empty($scheme['annual_result_enabled']) ? '1' : '0'; ?>"
                                            data-first-weight="<?php echo htmlspecialchars((string) $scheme['first_term_weight'], ENT_QUOTES, 'UTF-8'); ?>"
                                            data-second-weight="<?php echo htmlspecialchars((string) $scheme['second_term_weight'], ENT_QUOTES, 'UTF-8'); ?>"
                                            data-status="<?php echo htmlspecialchars((string) $scheme['status'], ENT_QUOTES, 'UTF-8'); ?>"><i class="fas fa-edit"></i></button><?php elseif (!empty($scheme['family_id'])): ?><a class="btn btn-sm btn-action-pills btn-edit me-1" data-bs-toggle="tooltip" title="إدارة المجموعة" href="assessment_scheme_family.php?family_id=<?php echo (int) $scheme['family_id']; ?>"><i class="fas fa-layer-group"></i></a><?php endif; ?>
                                    <?php if (empty($scheme['family_id'])): ?><button type="button" class="btn btn-sm btn-action-pills btn-services copy-row-scheme-btn me-1" data-bs-toggle="tooltip" title="نسخ"
                                            data-scheme-id="<?php echo (int) $scheme['id']; ?>"
                                            data-scheme-name="<?php echo htmlspecialchars($scheme['name'], ENT_QUOTES, 'UTF-8'); ?>"><i class="fas fa-copy"></i></button><?php endif; ?>
                                    <?php if (empty($scheme['family_id'])): ?><button type="button" class="btn btn-sm me-1 btn-action-pills <?php echo ($scheme['status'] ?? '') === 'active' ? 'btn-deactivate' : 'btn-activate'; ?> status-scheme-btn" data-bs-toggle="tooltip" title="<?php echo ($scheme['status'] ?? '') === 'active' ? 'تعطيل' : 'تفعيل'; ?>"
                                            data-scheme-id="<?php echo (int) $scheme['id']; ?>"
                                            data-scheme-name="<?php echo htmlspecialchars($scheme['name'], ENT_QUOTES, 'UTF-8'); ?>"
                                            data-new-status="<?php echo ($scheme['status'] ?? '') === 'active' ? 'archived' : 'active'; ?>"
                                            data-status-label="<?php echo ($scheme['status'] ?? '') === 'active' ? 'تعطيل' : 'تفعيل'; ?>"><i class="fas <?php echo ($scheme['status'] ?? '') === 'active' ? 'fa-ban' : 'fa-check'; ?>"></i></button><?php endif; ?>
                                    <?php if (empty($scheme['family_id'])): ?><button type="button" class="btn btn-sm btn-action-pills btn-delete assessment-smart-delete" data-bs-toggle="tooltip" title="حذف"
                                            data-row-id="<?php echo (int) $scheme['id']; ?>"
                                            data-row-name="<?php echo htmlspecialchars($scheme['name'], ENT_QUOTES, 'UTF-8'); ?>"
                                            data-row-active="<?php echo ($scheme['status'] ?? '') === 'active' ? '1' : '0'; ?>"><i class="fas fa-trash"></i></button><?php endif; ?>
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
$schemeFormFields = static function (string $prefix, array $defaults = []) use ($activeSubjectAssignments, $terms, $normalAbsenceLabels, $excusedAbsenceLabels, $roundingModeLabels, $roundingScopeLabels, $statusLabels): void {
    $id = static fn(string $name): string => $prefix . ucfirst($name);
?>
    <div class="row g-3">
        <div class="col-md-6">
            <label class="form-label">المادة/الصف</label>
            <select name="subject_assignment_id" id="<?php echo $id('assignment'); ?>" class="form-select scheme-assignment-select" required>
                <option value="">اختر المادة/الصف</option>
                <?php foreach ($activeSubjectAssignments as $assignment): ?>
                    <option value="<?php echo (int) $assignment['id']; ?>" data-year="<?php echo (int) $assignment['academic_year_id']; ?>" <?php echo schemes_selected($defaults['subject_assignment_id'] ?? '', $assignment['id']); ?>>
                        <?php echo htmlspecialchars($assignment['subject_name'] . ' - ' . $assignment['grade_name'] . ' - ' . ($assignment['class_name'] ?? 'كل الفصول'), ENT_QUOTES, 'UTF-8'); ?>
                    </option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="col-md-6">
            <label class="form-label">الترم</label>
            <select name="term_id" id="<?php echo $id('term'); ?>" class="form-select scheme-term-select" required>
                <option value="">اختر الترم</option>
                <?php foreach ($terms as $term): ?>
                    <option value="<?php echo (int) $term['id']; ?>" data-year="<?php echo (int) $term['academic_year_id']; ?>" <?php echo schemes_selected($defaults['term_id'] ?? '', $term['id']); ?>>
                        <?php echo htmlspecialchars($term['name'] . ' - ' . $term['academic_year_name'], ENT_QUOTES, 'UTF-8'); ?>
                    </option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="col-md-6"><label class="form-label">اسم الخطة</label><input type="text" name="name" id="<?php echo $id('name'); ?>" class="form-control" required maxlength="190" value="<?php echo htmlspecialchars((string) ($defaults['name'] ?? ''), ENT_QUOTES, 'UTF-8'); ?>"></div>
        <div class="col-md-3"><label class="form-label">المجموع</label><input type="number" name="total_grade" id="<?php echo $id('total'); ?>" class="form-control" min="1" max="1000" step="0.01" required value="<?php echo htmlspecialchars((string) ($defaults['total_grade'] ?? '100'), ENT_QUOTES, 'UTF-8'); ?>"></div>
        <div class="col-md-3"><label class="form-label">درجة النجاح</label><input type="number" name="pass_grade" id="<?php echo $id('pass'); ?>" class="form-control" min="0" max="1000" step="0.01" value="<?php echo htmlspecialchars((string) ($defaults['pass_grade'] ?? ''), ENT_QUOTES, 'UTF-8'); ?>"></div>
        <div class="col-md-4"><label class="form-label">الغياب العادي</label><select name="normal_absence_policy" id="<?php echo $id('normalPolicy'); ?>" class="form-select"><?php foreach ($normalAbsenceLabels as $key => $label): ?><option value="<?php echo $key; ?>" <?php echo schemes_selected($defaults['normal_absence_policy'] ?? 'zero', $key); ?>><?php echo htmlspecialchars($label, ENT_QUOTES, 'UTF-8'); ?></option><?php endforeach; ?></select></div>
        <div class="col-md-4"><label class="form-label">غياب بعذر</label><select name="excused_absence_policy" id="<?php echo $id('excusedPolicy'); ?>" class="form-select"><?php foreach ($excusedAbsenceLabels as $key => $label): ?><option value="<?php echo $key; ?>" <?php echo schemes_selected($defaults['excused_absence_policy'] ?? 'exclude', $key); ?>><?php echo htmlspecialchars($label, ENT_QUOTES, 'UTF-8'); ?></option><?php endforeach; ?></select></div>
        <div class="col-md-4"><label class="form-label">الحالة</label><?php if ($prefix === 'add'): ?><input type="hidden" name="status" value="draft"><div class="form-control bg-light"><?php echo htmlspecialchars($statusLabels['draft'], ENT_QUOTES, 'UTF-8'); ?></div><?php else: ?><select name="status" id="<?php echo $id('status'); ?>" class="form-select"><?php foreach ($statusLabels as $key => $label): ?><option value="<?php echo $key; ?>" <?php echo schemes_selected($defaults['status'] ?? 'draft', $key); ?>><?php echo htmlspecialchars($label, ENT_QUOTES, 'UTF-8'); ?></option><?php endforeach; ?></select><?php endif; ?></div>
        <div class="col-md-4"><label class="form-label">نوع التقريب</label><select name="rounding_mode" id="<?php echo $id('roundingMode'); ?>" class="form-select"><?php foreach ($roundingModeLabels as $key => $label): ?><option value="<?php echo $key; ?>" <?php echo schemes_selected($defaults['rounding_mode'] ?? 'none', $key); ?>><?php echo htmlspecialchars($label, ENT_QUOTES, 'UTF-8'); ?></option><?php endforeach; ?></select></div>
        <div class="col-md-4"><label class="form-label">نطاق التقريب</label><select name="rounding_scope" id="<?php echo $id('roundingScope'); ?>" class="form-select"><?php foreach ($roundingScopeLabels as $key => $label): ?><option value="<?php echo $key; ?>" <?php echo schemes_selected($defaults['rounding_scope'] ?? 'total', $key); ?>><?php echo htmlspecialchars($label, ENT_QUOTES, 'UTF-8'); ?></option><?php endforeach; ?></select></div>
        <div class="col-md-4"><label class="form-label">أوزان نهاية العام</label><div class="input-group"><input type="number" name="first_term_weight" id="<?php echo $id('firstWeight'); ?>" class="form-control" min="0" max="100" step="0.01" value="<?php echo htmlspecialchars((string) ($defaults['first_term_weight'] ?? '50'), ENT_QUOTES, 'UTF-8'); ?>"><input type="number" name="second_term_weight" id="<?php echo $id('secondWeight'); ?>" class="form-control" min="0" max="100" step="0.01" value="<?php echo htmlspecialchars((string) ($defaults['second_term_weight'] ?? '50'), ENT_QUOTES, 'UTF-8'); ?>"></div></div>
        <div class="col-md-3"><div class="form-check mt-4"><input class="form-check-input" type="checkbox" name="counts_in_total" id="<?php echo $id('counts'); ?>" value="1" <?php echo schemes_checked($defaults['counts_in_total'] ?? 1); ?>><label class="form-check-label" for="<?php echo $id('counts'); ?>">يدخل في المجموع</label></div></div>
        <div class="col-md-3"><div class="form-check mt-4"><input class="form-check-input" type="checkbox" name="enable_excused_absence" id="<?php echo $id('excused'); ?>" value="1" <?php echo schemes_checked($defaults['enable_excused_absence'] ?? 0); ?>><label class="form-check-label" for="<?php echo $id('excused'); ?>">تفعيل الغياب بعذر</label></div></div>
        <div class="col-md-3"><div class="form-check mt-4"><input class="form-check-input" type="checkbox" name="rounding_enabled" id="<?php echo $id('rounding'); ?>" value="1" <?php echo schemes_checked($defaults['rounding_enabled'] ?? 0); ?>><label class="form-check-label" for="<?php echo $id('rounding'); ?>">تفعيل التقريب</label></div></div>
        <div class="col-md-3"><div class="form-check mt-4"><input class="form-check-input" type="checkbox" name="annual_result_enabled" id="<?php echo $id('annual'); ?>" value="1" <?php echo schemes_checked($defaults['annual_result_enabled'] ?? 0); ?>><label class="form-check-label" for="<?php echo $id('annual'); ?>">تفعيل نهاية العام</label></div></div>
    </div>
<?php
};
?>

<div class="modal fade" id="addSchemeModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-xl"><div class="modal-content admin-modal admin-modal-premium admin-modal-create"><form method="post" action="assessment_schemes.php">
        <?php echo csrfField(); ?><input type="hidden" name="action" value="add_scheme">
        <div class="modal-header"><h5 class="modal-title"><i class="fas fa-plus-circle me-2"></i>إضافة خطة درجات</h5><button type="button" class="btn-close" data-bs-dismiss="modal"></button></div>
        <div class="modal-body"><?php $schemeFormFields('add'); ?></div>
        <div class="modal-footer"><button type="button" class="btn btn-secondary" data-bs-dismiss="modal"><i class="fas fa-times me-1"></i>إلغاء</button><button type="submit" class="btn btn-success"><i class="fas fa-plus me-1"></i>إضافة</button></div>
    </form></div></div>
</div>

<div class="modal fade" id="editSchemeModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-xl"><div class="modal-content admin-modal admin-modal-premium admin-modal-edit"><form method="post" action="assessment_schemes.php">
        <?php echo csrfField(); ?><input type="hidden" name="action" value="update_scheme"><input type="hidden" name="scheme_id" id="editSchemeId">
        <div class="modal-header"><h5 class="modal-title"><i class="fas fa-edit me-2"></i>تعديل خطة الدرجات</h5><button type="button" class="btn-close" data-bs-dismiss="modal"></button></div>
        <div class="modal-body"><?php $schemeFormFields('edit'); ?></div>
        <div class="modal-footer"><button type="button" class="btn btn-secondary" data-bs-dismiss="modal"><i class="fas fa-times me-1"></i>إلغاء</button><button type="submit" class="btn btn-primary"><i class="fas fa-save me-1"></i>حفظ</button></div>
    </form></div></div>
</div>

<div class="modal fade" id="copySchemeModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-lg"><div class="modal-content admin-modal admin-modal-premium admin-modal-create"><form method="post" action="assessment_schemes.php">
        <?php echo csrfField(); ?><input type="hidden" name="action" value="copy_scheme">
        <div class="modal-header"><h5 class="modal-title"><i class="fas fa-copy me-2"></i>نسخ خطة درجات</h5><button type="button" class="btn-close" data-bs-dismiss="modal"></button></div>
        <div class="modal-body"><div class="row g-3">
            <div class="col-md-12"><label class="form-label">الخطة المصدر</label><select name="source_scheme_id" id="copySourceScheme" class="form-select" required><option value="">اختر الخطة المصدر</option><?php foreach ($schemes as $scheme): ?><option value="<?php echo (int) $scheme['id']; ?>" data-total="<?php echo htmlspecialchars((string) $scheme['total_grade'], ENT_QUOTES, 'UTF-8'); ?>"><?php echo htmlspecialchars($scheme['subject_name'] . ' - ' . $scheme['grade_name'] . ' - ' . $scheme['term_name'] . ' - ' . $scheme['name'] . ' / ' . AssessmentEngine::formatNumber((float) $scheme['total_grade']), ENT_QUOTES, 'UTF-8'); ?></option><?php endforeach; ?></select></div>
            <div class="col-md-6"><label class="form-label">إلى مادة/صف</label><select name="target_subject_assignment_id" id="copyTargetAssignment" class="form-select scheme-assignment-select" required><option value="">اختر الهدف</option><?php foreach ($activeSubjectAssignments as $assignment): ?><option value="<?php echo (int) $assignment['id']; ?>" data-year="<?php echo (int) $assignment['academic_year_id']; ?>"><?php echo htmlspecialchars($assignment['subject_name'] . ' - ' . $assignment['grade_name'] . ' - ' . ($assignment['class_name'] ?? 'كل الفصول'), ENT_QUOTES, 'UTF-8'); ?></option><?php endforeach; ?></select></div>
            <div class="col-md-6"><label class="form-label">الترم الهدف</label><select name="target_term_id" id="copyTargetTerm" class="form-select scheme-term-select" required><option value="">اختر الترم</option><?php foreach ($terms as $term): ?><option value="<?php echo (int) $term['id']; ?>" data-year="<?php echo (int) $term['academic_year_id']; ?>"><?php echo htmlspecialchars($term['name'] . ' - ' . $term['academic_year_name'], ENT_QUOTES, 'UTF-8'); ?></option><?php endforeach; ?></select></div>
            <div class="col-md-6"><label class="form-label">مجموع الخطة الهدف</label><input type="number" name="target_total_grade" id="copyTargetTotal" class="form-control" min="1" max="1000" step="0.01" placeholder="اتركه فارغا لاستخدام نفس المجموع"></div>
            <div class="col-md-6 d-flex align-items-end"><div class="form-check mb-2"><input class="form-check-input" type="checkbox" name="scale_components" id="copyScaleComponents" value="1" checked><label class="form-check-label" for="copyScaleComponents">تحجيم درجات البنود بنفس النسبة</label></div></div>
            <div class="col-md-12"><label class="form-label">اسم الخطة الجديدة</label><input type="text" name="target_name" id="copyTargetName" class="form-control" maxlength="190" placeholder="اتركه فارغا لاستخدام اسم تلقائي"></div>
        </div></div>
        <div class="modal-footer"><button type="button" class="btn btn-secondary" data-bs-dismiss="modal"><i class="fas fa-times me-1"></i>إلغاء</button><button type="submit" class="btn btn-success"><i class="fas fa-copy me-1"></i>نسخ</button></div>
    </form></div></div>
</div>

<div class="modal fade" id="templateModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-lg"><div class="modal-content admin-modal admin-modal-premium admin-modal-edit"><form method="post" action="assessment_schemes.php">
        <?php echo csrfField(); ?><input type="hidden" name="action" value="apply_component_template">
        <div class="modal-header"><h5 class="modal-title"><i class="fas fa-wand-magic-sparkles me-2"></i>تطبيق قالب بنود على خطة</h5><button type="button" class="btn-close" data-bs-dismiss="modal"></button></div>
        <div class="modal-body"><div class="row g-3">
            <div class="col-md-7"><label class="form-label">الخطة</label><select name="scheme_id" class="form-select" required><option value="">اختر خطة مسودة أو معطلة</option><?php foreach ($schemes as $scheme): ?><?php if (($scheme['status'] ?? '') !== 'active'): ?><option value="<?php echo (int) $scheme['id']; ?>"><?php echo htmlspecialchars($scheme['subject_name'] . ' - ' . $scheme['grade_name'] . ' - ' . $scheme['term_name'] . ' - ' . $scheme['name'] . ' / ' . AssessmentEngine::formatNumber((float) $scheme['total_grade']), ENT_QUOTES, 'UTF-8'); ?></option><?php endif; ?><?php endforeach; ?></select></div>
            <div class="col-md-5"><label class="form-label">القالب</label><select name="template_key" class="form-select" required><option value="">اختر القالب</option><?php foreach ($componentTemplates as $templateKey => $template): ?><option value="<?php echo htmlspecialchars($templateKey, ENT_QUOTES, 'UTF-8'); ?>"><?php echo htmlspecialchars($template['label'] . ' / ' . AssessmentEngine::formatNumber((float) $template['total_grade']), ENT_QUOTES, 'UTF-8'); ?></option><?php endforeach; ?></select></div>
            <div class="col-md-6"><div class="form-check"><input class="form-check-input" type="checkbox" name="replace_existing" id="replaceExistingComponents" value="1"><label class="form-check-label" for="replaceExistingComponents">استبدال البنود الحالية</label></div></div>
            <div class="col-md-6"><div class="form-check"><input class="form-check-input" type="checkbox" name="scale_template" id="scaleTemplateComponents" value="1" checked><label class="form-check-label" for="scaleTemplateComponents">تحجيم درجات القالب لمجموع الخطة</label></div></div>
        </div></div>
        <div class="modal-footer"><button type="button" class="btn btn-secondary" data-bs-dismiss="modal"><i class="fas fa-times me-1"></i>إلغاء</button><button type="submit" class="btn btn-primary"><i class="fas fa-check me-1"></i>تطبيق</button></div>
    </form></div></div>
</div>

<div class="modal fade" id="statusSchemeModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog"><div class="modal-content admin-modal admin-modal-premium admin-modal-warning" id="statusSchemeModalContent"><form method="post" action="assessment_schemes.php">
        <?php echo csrfField(); ?><input type="hidden" name="action" value="update_scheme_status"><input type="hidden" name="scheme_id" id="statusSchemeId"><input type="hidden" name="new_status" id="statusSchemeNewStatus">
        <div class="modal-header"><h5 class="modal-title"><i class="fas fa-toggle-on me-2" id="statusSchemeHeaderIcon"></i>تغيير حالة الخطة</h5><button type="button" class="btn-close" data-bs-dismiss="modal"></button></div>
        <div class="modal-body text-center">
            <div class="mb-3"><i class="fas fa-toggle-on text-warning admin-modal-icon-lg" id="statusSchemeBodyIcon"></i></div>
            <p>هل تريد <span id="statusSchemeAction" class="fw-bold"></span> خطة <span id="statusSchemeName" class="fw-bold text-primary"></span>؟</p>
            <div class="alert alert-info text-start">عند التفعيل يتحقق النظام من أن مجموع البنود يساوي مجموع الخطة.</div>
        </div>
        <div class="modal-footer"><button type="button" class="btn btn-secondary" data-bs-dismiss="modal"><i class="fas fa-times me-1"></i>إلغاء</button><button type="submit" class="btn btn-warning" id="statusSchemeSubmit"><i class="fas fa-ban me-1"></i>تأكيد</button></div>
    </form></div></div>
</div>

<div class="modal fade" id="deleteSchemeModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog"><div class="modal-content admin-modal admin-modal-premium admin-modal-delete"><form method="post" action="assessment_schemes.php">
        <?php echo csrfField(); ?><input type="hidden" name="action" value="delete_scheme"><input type="hidden" name="scheme_id" id="deleteSchemeId">
        <div class="modal-header"><h5 class="modal-title"><i class="fas fa-trash me-2"></i>حذف خطة درجات</h5><button type="button" class="btn-close" data-bs-dismiss="modal"></button></div>
        <div class="modal-body text-center"><i class="fas fa-triangle-exclamation text-danger mb-3 admin-modal-icon-lg"></i><p>هل تريد حذف خطة <span id="deleteSchemeName" class="fw-bold text-primary"></span>؟</p><div class="alert alert-warning text-start">سيمنع النظام الحذف إذا وُجدت نوافذ رصد أو درجات أو تقارير مرتبطة بالخطة.</div></div>
        <div class="modal-footer"><button type="button" class="btn btn-secondary" data-bs-dismiss="modal"><i class="fas fa-times me-1"></i>إلغاء</button><button type="submit" class="btn btn-danger"><i class="fas fa-trash me-1"></i>حذف</button></div>
    </form></div></div>
</div>

<div class="modal fade" id="assessmentSchemeBatchModal" tabindex="-1" aria-labelledby="assessmentSchemeBatchModalLabel" aria-hidden="true">
    <div class="modal-dialog modal-xl modal-dialog-scrollable modal-fullscreen-xl-down">
        <div class="modal-content admin-modal admin-modal-premium admin-modal-create">
            <div class="modal-header">
                <h5 class="modal-title" id="assessmentSchemeBatchModalLabel"><i class="fas fa-layer-group me-2"></i>إنشاء خطط درجات جماعية</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="إغلاق"></button>
            </div>
            <div class="modal-body" id="assessmentSchemeBatchModalBody" aria-live="polite">
                <div class="d-flex align-items-center justify-content-center gap-2 py-5 text-muted" role="status">
                    <span class="spinner-border spinner-border-sm" aria-hidden="true"></span>
                    <span>جاري تجهيز نموذج الخطط…</span>
                </div>
            </div>
            <div class="modal-footer d-none" id="assessmentSchemeBatchModalFooter">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal"><i class="fas fa-times me-1"></i>إلغاء</button>
                <div class="d-flex gap-2" data-batch-modal-actions></div>
            </div>
        </div>
    </div>
</div>

<div class="modal fade" id="schemeBulkActionModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog"><div class="modal-content admin-modal admin-modal-premium admin-modal-delete"><form method="post" action="assessment_schemes.php">
        <?php echo csrfField(); ?><input type="hidden" name="action" value="assessment_bulk_action"><input type="hidden" name="selected_ids" value="">
        <div class="modal-header"><h5 class="modal-title"><i class="fas fa-list-check me-2"></i><span data-bulk-modal-title>عملية على خطط الدرجات</span></h5><button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="إغلاق"></button></div>
        <div class="modal-body">
            <div class="text-center mb-3"><i class="fas fa-triangle-exclamation text-warning admin-modal-icon-lg"></i></div>
            <p class="text-center" data-bulk-modal-message></p>
            <div class="alert alert-warning mb-0"><i class="fas fa-shield-alt me-2"></i>عدد السجلات: <strong data-bulk-modal-count>0</strong>. وجود درجات أو نوافذ أو تقارير مرتبطة يمنع الحذف بالكامل.</div>
        </div>
        <div class="modal-footer"><button type="button" class="btn btn-secondary" data-bs-dismiss="modal"><i class="fas fa-times me-1"></i>إلغاء</button><button type="submit" name="bulk_operation" value="deactivate" class="btn btn-warning" data-bulk-deactivate-submit><i class="fas fa-ban me-1"></i>تعطيل فقط</button><button type="submit" name="bulk_operation" value="delete" class="btn btn-danger" data-bulk-delete-submit><i class="fas fa-trash me-1"></i>حذف</button></div>
    </form></div></div>
</div>

<div class="modal fade" id="bulkCopySchemeModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-lg"><div class="modal-content admin-modal admin-modal-premium admin-modal-create"><form method="post" action="assessment_schemes.php">
        <?php echo csrfField(); ?><input type="hidden" name="action" value="bulk_copy_schemes"><input type="hidden" name="selected_ids" id="bulkCopySchemeIds">
        <div class="modal-header"><h5 class="modal-title"><i class="fas fa-copy me-2"></i>نسخ الخطط المحددة</h5><button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="إغلاق"></button></div>
        <div class="modal-body"><div class="alert alert-info"><i class="fas fa-info-circle me-2"></i>سيتم إنشاء <strong data-bulk-copy-count>0</strong> خطة كمسودات بأسماء تلقائية فريدة، مع نسخ البنود وقواعد الأسابيع.</div><div class="row g-3">
            <div class="col-md-6"><label class="form-label">إلى مادة/صف</label><select name="target_subject_assignment_id" class="form-select scheme-assignment-select" required><option value="">اختر الهدف</option><?php foreach ($activeSubjectAssignments as $assignment): ?><option value="<?php echo (int) $assignment['id']; ?>" data-year="<?php echo (int) $assignment['academic_year_id']; ?>"><?php echo htmlspecialchars($assignment['subject_name'] . ' - ' . $assignment['grade_name'] . ' - ' . ($assignment['class_name'] ?? 'كل الفصول'), ENT_QUOTES, 'UTF-8'); ?></option><?php endforeach; ?></select></div>
            <div class="col-md-6"><label class="form-label">الترم الهدف</label><select name="target_term_id" class="form-select scheme-term-select" required><option value="">اختر الترم</option><?php foreach ($terms as $term): ?><option value="<?php echo (int) $term['id']; ?>" data-year="<?php echo (int) $term['academic_year_id']; ?>"><?php echo htmlspecialchars($term['name'] . ' - ' . $term['academic_year_name'], ENT_QUOTES, 'UTF-8'); ?></option><?php endforeach; ?></select></div>
        </div></div>
        <div class="modal-footer"><button type="button" class="btn btn-secondary" data-bs-dismiss="modal"><i class="fas fa-times me-1"></i>إلغاء</button><button type="submit" class="btn btn-success"><i class="fas fa-copy me-1"></i>نسخ الخطط</button></div>
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
    function filterTermsForAssignment(assignmentSelect) {
        const modal = assignmentSelect.closest('.modal');
        if (!modal) {
            return;
        }
        const termSelect = modal.querySelector('.scheme-term-select');
        if (!termSelect) {
            return;
        }
        const selected = assignmentSelect.options[assignmentSelect.selectedIndex];
        const year = selected ? selected.getAttribute('data-year') : '';
        termSelect.querySelectorAll('option[data-year]').forEach(function (option) {
            option.style.display = (!year || option.getAttribute('data-year') === year) ? '' : 'none';
        });
        const current = termSelect.options[termSelect.selectedIndex];
        if (current && current.getAttribute('data-year') && year && current.getAttribute('data-year') !== year) {
            termSelect.value = '';
        }
    }
    document.querySelectorAll('.scheme-assignment-select').forEach(function (select) {
        select.addEventListener('change', function () {
            filterTermsForAssignment(this);
        });
        filterTermsForAssignment(select);
    });
    document.querySelectorAll('.edit-scheme-btn').forEach(function (button) {
        button.addEventListener('click', function () {
            setValue('editSchemeId', this.dataset.schemeId);
            setValue('editAssignment', this.dataset.assignmentId);
            setValue('editTerm', this.dataset.termId);
            setValue('editName', this.dataset.name);
            setValue('editTotal', this.dataset.totalGrade);
            setValue('editPass', this.dataset.passGrade);
            setValue('editNormalPolicy', this.dataset.normalPolicy);
            setValue('editExcusedPolicy', this.dataset.excusedPolicy);
            setValue('editRoundingMode', this.dataset.roundingMode);
            setValue('editRoundingScope', this.dataset.roundingScope);
            setValue('editFirstWeight', this.dataset.firstWeight);
            setValue('editSecondWeight', this.dataset.secondWeight);
            setValue('editStatus', this.dataset.status);
            setChecked('editCounts', this.dataset.countsInTotal);
            setChecked('editExcused', this.dataset.enableExcused);
            setChecked('editRounding', this.dataset.roundingEnabled);
            setChecked('editAnnual', this.dataset.annualEnabled);
            const assignmentSelect = document.getElementById('editAssignment');
            if (assignmentSelect) {
                filterTermsForAssignment(assignmentSelect);
                setValue('editTerm', this.dataset.termId);
            }
            showModal('editSchemeModal');
        });
    });
    document.querySelectorAll('.copy-row-scheme-btn').forEach(function (button) {
        button.addEventListener('click', function () {
            setValue('copySourceScheme', this.dataset.schemeId);
            const sourceSelect = document.getElementById('copySourceScheme');
            const sourceOption = sourceSelect ? sourceSelect.options[sourceSelect.selectedIndex] : null;
            setValue('copyTargetTotal', sourceOption ? sourceOption.getAttribute('data-total') : '');
            setValue('copyTargetName', this.dataset.schemeName ? ('نسخة من ' + this.dataset.schemeName) : '');
            showModal('copySchemeModal');
        });
    });
    const copySourceScheme = document.getElementById('copySourceScheme');
    if (copySourceScheme) {
        copySourceScheme.addEventListener('change', function () {
            const selected = this.options[this.selectedIndex];
            setValue('copyTargetTotal', selected ? selected.getAttribute('data-total') : '');
        });
    }
    document.querySelectorAll('.status-scheme-btn').forEach(function (button) {
        button.addEventListener('click', function () {
            setValue('statusSchemeId', this.dataset.schemeId);
            const newStatus = this.dataset.newStatus || '';
            setValue('statusSchemeNewStatus', newStatus);
            const isActive = newStatus === 'archived';
            document.getElementById('statusSchemeName').textContent = this.dataset.schemeName || '';
            document.getElementById('statusSchemeAction').textContent = this.dataset.statusLabel || '';

            const submitButton = document.getElementById('statusSchemeSubmit');
            if (submitButton) {
                submitButton.className = isActive ? 'btn btn-warning' : 'btn btn-success';
                submitButton.innerHTML = isActive ? '<i class="fas fa-ban me-1"></i>تعطيل' : '<i class="fas fa-check me-1"></i>تفعيل';
            }

            const modalContent = document.getElementById('statusSchemeModalContent');
            if (modalContent) {
                modalContent.classList.toggle('admin-modal-warning', isActive);
                modalContent.classList.toggle('admin-modal-create', !isActive);
            }
            const bodyIcon = document.getElementById('statusSchemeBodyIcon');
            const headerIcon = document.getElementById('statusSchemeHeaderIcon');
            if (bodyIcon) {
                bodyIcon.className = isActive ? 'fas fa-ban text-warning admin-modal-icon-lg' : 'fas fa-check-circle text-success admin-modal-icon-lg';
            }
            if (headerIcon) {
                headerIcon.className = isActive ? 'fas fa-ban me-2' : 'fas fa-check-circle me-2';
            }

            showModal('statusSchemeModal');
        });
    });
    document.querySelectorAll('.delete-scheme-btn').forEach(function (button) {
        button.addEventListener('click', function () {
            setValue('deleteSchemeId', this.dataset.schemeId);
            document.getElementById('deleteSchemeName').textContent = this.dataset.schemeName || '';
            showModal('deleteSchemeModal');
        });
    });
});
</script>
<script src="../assets/js/assessment-scheme-batch-form.js?v=<?php echo file_exists('../assets/js/assessment-scheme-batch-form.js') ? filemtime('../assets/js/assessment-scheme-batch-form.js') : '2.0'; ?>"></script>
<script>
document.addEventListener('DOMContentLoaded', function () {
    var modalElement = document.getElementById('assessmentSchemeBatchModal');
    var modalBody = document.getElementById('assessmentSchemeBatchModalBody');
    var modalFooter = document.getElementById('assessmentSchemeBatchModalFooter');
    var modalActions = modalFooter ? modalFooter.querySelector('[data-batch-modal-actions]') : null;
    if (!modalElement || !modalBody || !modalFooter || !modalActions) {
        return;
    }

    var activeBatchRequest = null;
    var batchRequestSequence = 0;

    function startBatchRequest() {
        if (activeBatchRequest && activeBatchRequest.controller) {
            activeBatchRequest.controller.abort();
        }
        activeBatchRequest = {
            id: ++batchRequestSequence,
            controller: typeof AbortController !== 'undefined' ? new AbortController() : null
        };
        return activeBatchRequest;
    }

    function isCurrentBatchRequest(request) {
        return !!activeBatchRequest && activeBatchRequest.id === request.id;
    }

    function finishBatchRequest(request) {
        if (isCurrentBatchRequest(request)) {
            activeBatchRequest = null;
        }
    }

    function batchRequestError(status, message) {
        var error = new Error(message || ('Request failed with status ' + status));
        error.status = status;
        return error;
    }

    function batchRequestErrorMessage(error, context) {
        var status = Number(error && error.status ? error.status : 0);
        if (status === 419) {
            return 'انتهت جلسة الأمان. أعد تحميل الصفحة ثم حاول مرة أخرى.';
        }
        if (status === 401) {
            return 'انتهت جلسة الدخول. سجّل الدخول مرة أخرى لإكمال العملية.';
        }
        if (status === 403) {
            return 'ليست لديك صلاحية لتنفيذ هذه العملية.';
        }
        if (status >= 500) {
            return 'حدث خطأ في الخادم ولم تُحفظ تغييرات جزئية. حاول مرة أخرى.';
        }
        return context === 'submit'
            ? 'تعذر إرسال النموذج. تحقق من الاتصال ثم حاول مرة أخرى.'
            : 'تعذر تحميل نموذج الخطط الجماعية. أعد فتح النافذة وحاول مرة أخرى.';
    }

    function loadingMarkup() {
        return '<div class="d-flex align-items-center justify-content-center gap-2 py-5 text-muted" role="status">'
            + '<span class="spinner-border spinner-border-sm" aria-hidden="true"></span>'
            + '<span>جاري تجهيز نموذج الخطط…</span></div>';
    }

    function errorMarkup(message) {
        var wrapper = document.createElement('div');
        wrapper.className = 'alert alert-danger mb-0';
        wrapper.dataset.batchRequestError = '1';
        var icon = document.createElement('i');
        icon.className = 'fas fa-triangle-exclamation me-2';
        wrapper.appendChild(icon);
        wrapper.appendChild(document.createTextNode(message));
        return wrapper;
    }

    function moveFormActions(form) {
        modalActions.innerHTML = '';
        var actionBar = modalBody.querySelector('[data-batch-form-actions]');
        if (!actionBar) {
            modalFooter.classList.add('d-none');
            return;
        }
        var actionGroup = actionBar.querySelector('.d-flex.gap-2');
        if (actionGroup) {
            Array.prototype.slice.call(actionGroup.children).forEach(function (control) {
                if (control.tagName === 'BUTTON') {
                    control.setAttribute('form', form.id);
                }
                modalActions.appendChild(control);
            });
        }
        actionBar.remove();
        modalFooter.classList.remove('d-none');
    }

    function bindForm(form) {
        form.addEventListener('submit', function (event) {
            event.preventDefault();
            var oldRequestError = modalBody.querySelector('[data-batch-request-error]');
            if (oldRequestError) {
                oldRequestError.remove();
            }
            var submitter = event.submitter;
            var data = new FormData(form);
            if (submitter && submitter.name) {
                data.set(submitter.name, submitter.value);
            }
            modalActions.querySelectorAll('button').forEach(function (button) {
                button.disabled = true;
            });

            var formAction = new URL(form.getAttribute('action') || window.location.href, document.baseURI).toString();
            var request = startBatchRequest();
            var options = {
                method: 'POST',
                body: data,
                credentials: 'same-origin',
                headers: { 'X-Requested-With': 'XMLHttpRequest' }
            };
            if (request.controller) {
                options.signal = request.controller.signal;
            }
            fetch(formAction, options).then(function (response) {
                return renderResponse(response, request);
            }).catch(function (error) {
                if ((error && error.name === 'AbortError') || !isCurrentBatchRequest(request)) {
                    return;
                }
                finishBatchRequest(request);
                console.error('Assessment scheme batch submission failed.', error);
                modalActions.querySelectorAll('button').forEach(function (button) {
                    button.disabled = false;
                });
                modalBody.prepend(errorMarkup(batchRequestErrorMessage(error, 'submit')));
                modalBody.dataset.loaded = '1';
            });
        });
    }

    function renderResponse(response, request) {
        if (!isCurrentBatchRequest(request)) {
            return Promise.resolve();
        }
        var responseUrl = new URL(response.url, window.location.href);
        var contentType = String(response.headers.get('Content-Type') || '').toLowerCase();
        if (contentType.indexOf('application/json') !== -1) {
            return response.json().then(function (payload) {
                if (!isCurrentBatchRequest(request)) {
                    return;
                }
                if (!response.ok || !payload || payload.success !== true) {
                    throw batchRequestError(response.status, payload && payload.message ? payload.message : 'Invalid JSON response');
                }
                if (!payload.redirect) {
                    throw batchRequestError(500, 'Successful response is missing redirect URL');
                }
                finishBatchRequest(request);
                window.location.assign(payload.redirect);
            });
        }
        if (response.redirected && responseUrl.pathname.indexOf('assessment_schemes.php') !== -1) {
            finishBatchRequest(request);
            window.location.assign(response.url);
            return Promise.resolve();
        }
        if (!response.ok) {
            throw batchRequestError(response.status);
        }
        return response.text().then(function (html) {
            if (!isCurrentBatchRequest(request)) {
                return;
            }
            var parsed = new DOMParser().parseFromString(html, 'text/html');
            var content = parsed.getElementById('assessmentSchemeBatchContent');
            if (!content) {
                throw batchRequestError(401, 'Batch form fragment is missing');
            }
            modalBody.innerHTML = content.innerHTML;
            var form = modalBody.querySelector('#assessmentSchemeBatchForm');
            if (!form) {
                modalFooter.classList.add('d-none');
                modalBody.scrollTop = 0;
                modalBody.dataset.loaded = '1';
                finishBatchRequest(request);
                return;
            }
            if (typeof window.initAssessmentSchemeBatchForm === 'function') {
                window.initAssessmentSchemeBatchForm(modalBody);
            }
            moveFormActions(form);
            bindForm(form);
            modalBody.scrollTop = 0;
            modalBody.dataset.loaded = '1';
            finishBatchRequest(request);
        });
    }

    function loadBatchForm(forceReload) {
        if (!forceReload && modalBody.dataset.loaded === '1') {
            return;
        }
        modalBody.dataset.loaded = '0';
        modalBody.innerHTML = loadingMarkup();
        modalFooter.classList.add('d-none');
        var request = startBatchRequest();
        var options = {
            credentials: 'same-origin',
            headers: { 'X-Requested-With': 'XMLHttpRequest' }
        };
        if (request.controller) {
            options.signal = request.controller.signal;
        }
        fetch('assessment_scheme_batch.php?embed=1', options).then(function (response) {
            return renderResponse(response, request);
        }).catch(function (error) {
            if ((error && error.name === 'AbortError') || !isCurrentBatchRequest(request)) {
                return;
            }
            finishBatchRequest(request);
            console.error('Assessment scheme batch form loading failed.', error);
            modalBody.innerHTML = '';
            modalBody.appendChild(errorMarkup(batchRequestErrorMessage(error, 'load')));
            modalBody.dataset.loaded = '0';
        });
    }

    modalElement.addEventListener('show.bs.modal', function () {
        loadBatchForm(false);
    });

    modalElement.addEventListener('hidden.bs.modal', function () {
        if (activeBatchRequest && activeBatchRequest.controller) {
            activeBatchRequest.controller.abort();
        }
        activeBatchRequest = null;
        modalActions.querySelectorAll('button').forEach(function (button) {
            button.disabled = false;
        });
    });

    var currentUrl = new URL(window.location.href);
    if (currentUrl.searchParams.get('open_batch') === '1') {
        currentUrl.searchParams.delete('open_batch');
        window.history.replaceState({}, '', currentUrl.toString());
        bootstrap.Modal.getOrCreateInstance(modalElement).show();
    }
});
</script>
<script src="../assets/js/assessment-bulk-actions.js"></script>
<?php endif; ?>

<?php require_once '../includes/admin_footer.php'; ?>
