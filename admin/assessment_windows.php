<?php
$page_title = "نوافذ الرصد";
$custom_page_title = true;

require_once '../config/database.php';
require_once '../classes/utilities.php';
require_once '../classes/ActivityLog.php';
require_once '../classes/AcademicYear.php';
require_once '../classes/AcademicYearWriteGuard.php';
require_once '../classes/AssessmentEngine.php';
require_once '../classes/AssessmentSchemeScopeResolver.php';
require_once '../classes/AssessmentBulkActionService.php';
require_once '../classes/AssessmentWindowLifecycleService.php';
require_once '../classes/AssessmentMarkAdministrationService.php';
require_once '../includes/session_config.php';
require_once '../includes/csrf.php';
Utilities::validateSession('admin');
requireCsrfPost();

$database = new Database();
$db = $database->getConnection();

$success_message = $_SESSION['success_message'] ?? null;
$error_message = $_SESSION['error_message'] ?? null;
unset($_SESSION['success_message'], $_SESSION['error_message']);

function windows_table_exists(PDO $db, string $table): bool
{
    $stmt = $db->prepare('SELECT 1 FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?');
    $stmt->execute([$table]);
    return (bool) $stmt->fetchColumn();
}

function windows_column_exists(PDO $db, string $table, string $column): bool
{
    $stmt = $db->prepare('SELECT 1 FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?');
    $stmt->execute([$table, $column]);
    return (bool) $stmt->fetchColumn();
}

function windows_redirect(): void
{
    header('Location: assessment_windows.php');
    exit();
}

function windows_datetime(?string $value): ?string
{
    $value = trim((string) $value);
    return $value !== '' ? str_replace('T', ' ', $value) . ':00' : null;
}

function windows_datetime_input($value): string
{
    if (empty($value)) {
        return '';
    }
    return str_replace(' ', 'T', substr((string) $value, 0, 16));
}

function windows_fetch_component(PDO $db, int $schemeId, int $componentId): array
{
    $stmt = $db->prepare("SELECT ac.id, ac.name AS component_name, ac.is_active, ac.is_weekly,
            ac.calculation_mode, ac.counts_in_average, sch.academic_year_id, sch.term_id,
            sch.id AS scheme_id, sch.subject_id, sch.grade_id, sch.name AS scheme_name, sch.status AS scheme_status
        FROM assessment_components ac
        JOIN assessment_schemes sch ON sch.id = ac.scheme_id
        WHERE ac.id = ? AND ac.scheme_id = ?
        LIMIT 1");
    $stmt->execute([$componentId, $schemeId]);
    $component = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$component) {
        throw new InvalidArgumentException('البند المحدد لا يتبع خطة الدرجات المختارة.');
    }
    if (($component['scheme_status'] ?? '') !== 'active') {
        throw new InvalidArgumentException('لا يمكن فتح نافذة رصد قبل تفعيل خطة الدرجات.');
    }
    if (empty($component['is_active'])) {
        throw new InvalidArgumentException('لا يمكن فتح نافذة رصد لبند معطل.');
    }
    return $component;
}

function windows_assert_selected_year(?int $currentAcademicYearId, array $row, string $message): void
{
    if ($currentAcademicYearId && (int) ($row['academic_year_id'] ?? 0) !== $currentAcademicYearId) {
        throw new InvalidArgumentException($message);
    }
}

function windows_validate_teacher_scope(PDO $db, array $component, ?int $teacherId, ?int $classId, ?string $opensAt, ?string $closesAt): void
{
    if ($teacherId === null) {
        return;
    }

    $teacherStmt = $db->prepare("SELECT 1 FROM users u WHERE u.id = ? AND u.status = 'active'
        AND EXISTS (SELECT 1 FROM user_role_assignments ura WHERE ura.user_id = u.id AND ura.role_key = 'teacher' AND ura.status = 'active') LIMIT 1");
    $teacherStmt->execute([$teacherId]);
    if (!$teacherStmt->fetchColumn()) {
        throw new InvalidArgumentException('المعلم المحدد غير موجود أو غير نشط.');
    }

    if (!windows_table_exists($db, 'teacher_subject_assignments')) {
        throw new RuntimeException('لا يمكن تخصيص نافذة لمعلم محدد قبل تطبيق جدول تعيينات المعلمين التفصيلية.');
    }

    $opensDate = $opensAt ? substr($opensAt, 0, 10) : null;
    $closesDate = $closesAt ? substr($closesAt, 0, 10) : null;
    $assignmentStmt = $db->prepare("SELECT 1
        FROM teacher_subject_assignments
        WHERE teacher_id = ?
          AND academic_year_id = ?
          AND subject_id = ?
          AND is_active = 1
          AND can_record = 1
          AND (term_id IS NULL OR term_id = ?)
          AND (grade_id IS NULL OR grade_id = ?)
          AND ((? IS NULL AND class_id IS NULL) OR (? IS NOT NULL AND (class_id IS NULL OR class_id = ?)))
          AND (starts_at IS NULL OR ? IS NULL OR starts_at <= ?)
          AND (ends_at IS NULL OR ? IS NULL OR ends_at >= ?)
        LIMIT 1");
    $assignmentStmt->execute([
        $teacherId,
        (int) $component['academic_year_id'],
        (int) $component['subject_id'],
        (int) $component['term_id'],
        (int) $component['grade_id'],
        $classId,
        $classId,
        $classId,
        $closesDate,
        $closesDate,
        $opensDate,
        $opensDate,
    ]);
    if (!$assignmentStmt->fetchColumn()) {
        throw new InvalidArgumentException('لا يمكن تخصيص النافذة لهذا المعلم لأنه لا يملك تعيينا نشطا للرصد في نفس المادة والنطاق.');
    }
}

function windows_component_requires_week(array $component): bool
{
    return !empty($component['is_weekly'])
        || !empty($component['counts_in_average'])
        || ($component['calculation_mode'] ?? '') === 'average_weeks';
}

function windows_validate_scope(PDO $db, array $component, ?int $weekId, ?int $classId, bool $componentWeekRulesReady): void
{
    $requiresWeek = windows_component_requires_week($component);
    if ($requiresWeek && $weekId === null) {
        throw new InvalidArgumentException('هذا البند أسبوعي أو يدخل في متوسط الأسابيع، لذلك يجب اختيار أسبوع دراسي.');
    }
    if (!$requiresWeek && $weekId !== null) {
        throw new InvalidArgumentException('لا يمكن اختيار أسبوع دراسي لبند غير أسبوعي.');
    }
    if ($weekId !== null) {
        $weekStmt = $db->prepare('SELECT id, academic_year_id, term_id, week_type FROM academic_weeks WHERE id = ? LIMIT 1');
        $weekStmt->execute([$weekId]);
        $week = $weekStmt->fetch(PDO::FETCH_ASSOC);
        if (!$week) {
            throw new InvalidArgumentException('الأسبوع الدراسي المحدد غير موجود.');
        }
        if ((int) $week['academic_year_id'] !== (int) $component['academic_year_id'] || (int) $week['term_id'] !== (int) $component['term_id']) {
            throw new InvalidArgumentException('الأسبوع المحدد لا يتبع نفس عام وترم خطة الدرجات.');
        }
        if (($week['week_type'] ?? '') !== 'study') {
            throw new InvalidArgumentException('لا يمكن فتح رصد أسبوعي على أسبوع عطلة أو مراجعة أو امتحانات.');
        }
        if ($componentWeekRulesReady) {
            $ruleStmt = $db->prepare('SELECT is_included FROM assessment_component_week_rules WHERE component_id = ? AND week_id = ? LIMIT 1');
            $ruleStmt->execute([(int) $component['id'], $weekId]);
            $isIncluded = $ruleStmt->fetchColumn();
            if ($isIncluded !== false && (int) $isIncluded === 0) {
                throw new InvalidArgumentException('هذا الأسبوع مستبعد من هذا البند في قواعد الأسابيع، ولا يمكن فتح نافذة رصد له.');
            }
        }
    }
    if ($classId !== null) {
        $classStmt = $db->prepare("SELECT grade_id FROM classes WHERE id = ? AND status = 'active' LIMIT 1");
        $classStmt->execute([$classId]);
        $classGradeId = (int) $classStmt->fetchColumn();
        if ($classGradeId <= 0 || $classGradeId !== (int) $component['grade_id']) {
            throw new InvalidArgumentException('الفصل المختار غير صحيح أو لا يتبع صف خطة الدرجات.');
        }
    }
    (new AssessmentSchemeScopeResolver($db))->assertSchemeCoversClass(
        (int) $component['scheme_id'],
        (int) $component['grade_id'],
        $classId
    );
}

function windows_validate_class_scope(PDO $db, array $component, ?int $classId): void
{
    if ($classId !== null) {
        $classStmt = $db->prepare("SELECT grade_id FROM classes WHERE id = ? AND status = 'active' LIMIT 1");
        $classStmt->execute([$classId]);
        $classGradeId = (int) $classStmt->fetchColumn();
        if ($classGradeId <= 0 || $classGradeId !== (int) $component['grade_id']) {
            throw new InvalidArgumentException('الفصل المختار غير صحيح أو لا يتبع صف خطة الدرجات.');
        }
    }
    (new AssessmentSchemeScopeResolver($db))->assertSchemeCoversClass(
        (int) $component['scheme_id'],
        (int) $component['grade_id'],
        $classId
    );
}

function windows_has_marks(PDO $db, int $windowId): bool
{
    if (!windows_table_exists($db, 'student_marks')) {
        return false;
    }
    $windowStmt = $db->prepare('SELECT scheme_id, component_id, week_id, class_id FROM assessment_windows WHERE id = ? LIMIT 1');
    $windowStmt->execute([$windowId]);
    $window = $windowStmt->fetch(PDO::FETCH_ASSOC);
    if (!$window) {
        return false;
    }
    $where = 'scheme_id = ? AND component_id = ?';
    $params = [(int) $window['scheme_id'], (int) $window['component_id']];
    if ($window['week_id'] !== null && windows_column_exists($db, 'student_marks', 'week_id')) {
        $where .= ' AND week_id = ?';
        $params[] = (int) $window['week_id'];
    }
    if ($window['class_id'] !== null && windows_column_exists($db, 'student_marks', 'class_id_at_entry')) {
        $where .= ' AND class_id_at_entry = ?';
        $params[] = (int) $window['class_id'];
    }
    $stmt = $db->prepare("SELECT COUNT(*) FROM student_marks WHERE {$where}");
    $stmt->execute($params);
    return (int) $stmt->fetchColumn() > 0;
}

$windowsReady = windows_table_exists($db, 'assessment_windows');
$componentsReady = windows_table_exists($db, 'assessment_components');
$schemesReady = windows_table_exists($db, 'assessment_schemes');
$weeksReady = windows_table_exists($db, 'academic_weeks');
$componentWeekRulesReady = windows_table_exists($db, 'assessment_component_week_rules');
$calendarReady = windows_table_exists($db, 'academic_years') && windows_table_exists($db, 'academic_terms') && $weeksReady;

$currentAcademicYear = AcademicYear::getCurrent($db) ?: AcademicYear::getActive($db);
$currentAcademicYearId = $currentAcademicYear ? (int) $currentAcademicYear['id'] : 0;
$currentAcademicYearName = $currentAcademicYear['name'] ?? '';
$activeRole = (string) ($_SESSION['active_role'] ?? $_SESSION['role'] ?? '');
$isSuperAdmin = $activeRole === 'super_admin';
$statusLabels = ['draft' => 'مسودة', 'open' => 'مفتوحة', 'closed' => 'مغلقة', 'locked' => 'مقفلة'];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        (new AcademicYearWriteGuard($db))->assertWritable($currentAcademicYearId);
        if (!$windowsReady || !$componentsReady || !$schemesReady) {
            throw new RuntimeException('جداول نوافذ الرصد أو الخطط غير مطبقة بعد.');
        }
        $action = (string) ($_POST['action'] ?? '');

        if ($action === 'assessment_bulk_action') {
            $result = (new AssessmentBulkActionService($db))->execute(
                'window',
                (string) ($_POST['bulk_operation'] ?? ''),
                AssessmentBulkActionService::normalizeIds($_POST['selected_ids'] ?? ''),
                $currentAcademicYearId
            );
            $_SESSION['success_message'] = $result['message'];
            windows_redirect();
        }

        if ($action === 'super_admin_delete_window') {
            $result = (new AssessmentMarkAdministrationService($db))->deleteWindowPreservingMarks(
                (int) ($_POST['window_id'] ?? 0),
                $currentAcademicYearId,
                (int) ($_SESSION['user_id'] ?? 0),
                $activeRole,
                (string) ($_POST['deletion_reason'] ?? ''),
                (string) ($_POST['confirmation_name'] ?? '')
            );
            $_SESSION['success_message'] = 'تم حذف نافذة الرصد استثنائيًا مع الاحتفاظ بـ ' . (int) $result['preserved_marks'] . ' درجة أصلية في صفحة إدارة الدرجات.';
            windows_redirect();
        }

        if ($action === 'add_window') {
            $schemeId = (int) ($_POST['scheme_id'] ?? 0);
            $componentId = (int) ($_POST['component_id'] ?? 0);
            $weekId = !empty($_POST['week_id']) ? (int) $_POST['week_id'] : null;
            $classId = !empty($_POST['class_id']) ? (int) $_POST['class_id'] : null;
            $teacherId = !empty($_POST['teacher_id']) ? (int) $_POST['teacher_id'] : null;
            $windowName = trim((string) ($_POST['window_name'] ?? ''));
            $opensAt = windows_datetime($_POST['opens_at'] ?? '');
            $closesAt = windows_datetime($_POST['closes_at'] ?? '');
            $status = in_array(($_POST['status'] ?? 'draft'), ['draft', 'open'], true) ? (string) $_POST['status'] : 'draft';
            if ($schemeId <= 0 || $componentId <= 0 || $windowName === '') {
                throw new InvalidArgumentException('اختر الخطة والبند واكتب اسم نافذة الرصد.');
            }
            if ($opensAt && $closesAt && $opensAt > $closesAt) {
                throw new InvalidArgumentException('تاريخ فتح الرصد يجب أن يكون قبل تاريخ الإغلاق.');
            }
            $component = windows_fetch_component($db, $schemeId, $componentId);
            windows_assert_selected_year($currentAcademicYearId, $component, 'لا يمكن إنشاء نافذة رصد خارج العام الدراسي المختار.');
            windows_validate_scope($db, $component, $weekId, $classId, $componentWeekRulesReady);
            windows_validate_teacher_scope($db, $component, $teacherId, $classId, $opensAt, $closesAt);
            $duplicateStmt = $db->prepare("SELECT window_name FROM assessment_windows
                WHERE scheme_id = ? AND component_id = ?
                  AND ((week_id IS NULL AND ? IS NULL) OR week_id = ?)
                  AND ((class_id IS NULL AND ? IS NULL) OR class_id = ?)
                  AND ((teacher_id IS NULL AND ? IS NULL) OR teacher_id = ?)
                LIMIT 1");
            $duplicateStmt->execute([$schemeId, $componentId, $weekId, $weekId, $classId, $classId, $teacherId, $teacherId]);
            if ($duplicateStmt->fetchColumn()) {
                throw new InvalidArgumentException('توجد نافذة رصد بنفس النطاق بالفعل.');
            }
            $stmt = $db->prepare("INSERT INTO assessment_windows
                (scheme_id, component_id, week_id, class_id, grade_id, teacher_id, window_name,
                 opens_at, closes_at, status, allow_edit_after_save, requires_review, opened_by)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
            $stmt->execute([$schemeId, $componentId, $weekId, $classId, (int) $component['grade_id'], $teacherId, $windowName, $opensAt, $closesAt, $status, isset($_POST['allow_edit_after_save']) ? 1 : 0, isset($_POST['requires_review']) ? 1 : 0, (int) ($_SESSION['user_id'] ?? 0) ?: null]);
            $windowId = (int) $db->lastInsertId();
            ActivityLog::logCreate('assessment_window', $windowId, $windowName, ['scheme' => $component['scheme_name'], 'component' => $component['component_name'], 'status' => $status]);
            $_SESSION['success_message'] = 'تم إنشاء نافذة الرصد بنجاح.';
            windows_redirect();
        }

        if ($action === 'add_weekly_windows') {
            $schemeId = (int) ($_POST['scheme_id'] ?? 0);
            $componentId = (int) ($_POST['component_id'] ?? 0);
            $classId = !empty($_POST['class_id']) ? (int) $_POST['class_id'] : null;
            $teacherId = !empty($_POST['teacher_id']) ? (int) $_POST['teacher_id'] : null;
            $windowPrefix = trim((string) ($_POST['window_name_prefix'] ?? ''));
            $opensAt = windows_datetime($_POST['opens_at'] ?? '');
            $closesAt = windows_datetime($_POST['closes_at'] ?? '');
            $status = in_array(($_POST['status'] ?? 'draft'), ['draft', 'open'], true) ? (string) $_POST['status'] : 'draft';
            if ($schemeId <= 0 || $componentId <= 0 || $windowPrefix === '') {
                throw new InvalidArgumentException('اختر الخطة والبند واكتب بادئة اسم نوافذ الرصد الأسبوعية.');
            }
            if ($opensAt && $closesAt && $opensAt > $closesAt) {
                throw new InvalidArgumentException('تاريخ فتح الرصد يجب أن يكون قبل تاريخ الإغلاق.');
            }
            $component = windows_fetch_component($db, $schemeId, $componentId);
            windows_assert_selected_year($currentAcademicYearId, $component, 'لا يمكن إنشاء نوافذ رصد أسبوعية خارج العام الدراسي المختار.');
            if (!windows_component_requires_week($component)) {
                throw new InvalidArgumentException('الإنشاء الدفعي متاح للبنود الأسبوعية أو بنود متوسط الأسابيع فقط.');
            }
            windows_validate_class_scope($db, $component, $classId);
            windows_validate_teacher_scope($db, $component, $teacherId, $classId, $opensAt, $closesAt);
            $weeksSql = $componentWeekRulesReady
                ? "SELECT w.id, w.name FROM academic_weeks w LEFT JOIN assessment_component_week_rules cwr ON cwr.week_id = w.id AND cwr.component_id = ? WHERE w.academic_year_id = ? AND w.term_id = ? AND w.week_type = 'study' AND w.counts_for_average = 1 AND (cwr.is_included IS NULL OR cwr.is_included = 1) ORDER BY w.week_order, w.start_date"
                : "SELECT id, name FROM academic_weeks WHERE academic_year_id = ? AND term_id = ? AND week_type = 'study' AND counts_for_average = 1 ORDER BY week_order, start_date";
            $weeksStmt = $db->prepare($weeksSql);
            $componentWeekRulesReady
                ? $weeksStmt->execute([$componentId, (int) $component['academic_year_id'], (int) $component['term_id']])
                : $weeksStmt->execute([(int) $component['academic_year_id'], (int) $component['term_id']]);
            $studyWeeks = $weeksStmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
            if (empty($studyWeeks)) {
                throw new InvalidArgumentException('لا توجد أسابيع دراسية داخلة في المتوسط لهذا الترم.');
            }
            $duplicateStmt = $db->prepare("SELECT id FROM assessment_windows WHERE scheme_id = ? AND component_id = ? AND week_id = ? AND ((class_id IS NULL AND ? IS NULL) OR class_id = ?) AND ((teacher_id IS NULL AND ? IS NULL) OR teacher_id = ?) LIMIT 1");
            $insertStmt = $db->prepare("INSERT INTO assessment_windows
                (scheme_id, component_id, week_id, class_id, grade_id, teacher_id, window_name, opens_at, closes_at, status, allow_edit_after_save, requires_review, opened_by)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
            $createdCount = 0;
            $skippedCount = 0;
            $db->beginTransaction();
            foreach ($studyWeeks as $week) {
                $weekId = (int) $week['id'];
                $duplicateStmt->execute([$schemeId, $componentId, $weekId, $classId, $classId, $teacherId, $teacherId]);
                if ($duplicateStmt->fetchColumn()) {
                    $skippedCount++;
                    continue;
                }
                $insertStmt->execute([$schemeId, $componentId, $weekId, $classId, (int) $component['grade_id'], $teacherId, $windowPrefix . ' - ' . $week['name'], $opensAt, $closesAt, $status, isset($_POST['allow_edit_after_save']) ? 1 : 0, isset($_POST['requires_review']) ? 1 : 0, (int) ($_SESSION['user_id'] ?? 0) ?: null]);
                $createdCount++;
            }
            $db->commit();
            ActivityLog::logCreate('assessment_window', $schemeId, $windowPrefix, ['count' => $createdCount, 'skipped' => $skippedCount, 'status' => $status]);
            $_SESSION['success_message'] = "تم إنشاء {$createdCount} نافذة أسبوعية.";
            if ($skippedCount > 0) {
                $_SESSION['success_message'] .= " وتم تخطي {$skippedCount} نافذة موجودة مسبقا.";
            }
            windows_redirect();
        }

        if ($action === 'update_window_status') {
            $windowId = (int) ($_POST['window_id'] ?? 0);
            $newStatus = in_array(($_POST['new_status'] ?? ''), array_keys($statusLabels), true) ? (string) $_POST['new_status'] : '';
            if ($windowId <= 0 || $newStatus === '') {
                throw new InvalidArgumentException('بيانات تغيير حالة النافذة غير صحيحة.');
            }
            $result = (new AssessmentWindowLifecycleService($db))->transition(
                $windowId,
                $newStatus,
                (int) ($_SESSION['user_id'] ?? 0),
                (string) ($_SESSION['role'] ?? ''),
                trim((string) ($_POST['transition_reason'] ?? '')),
                windows_datetime($_POST['reopen_closes_at'] ?? '')
            );
            $successLabels = ['open' => 'تم فتح نافذة الرصد.', 'closed' => 'تم إغلاق نافذة الرصد وبدء مرحلة المراجعة.', 'locked' => 'تم قفل نافذة الرصد نهائيًا.'];
            $_SESSION['success_message'] = $successLabels[$result['new_status']] ?? 'تم تحديث حالة نافذة الرصد.';
            windows_redirect();
        }

        if ($action === 'update_window_settings') {
            $windowId = (int) ($_POST['window_id'] ?? 0);
            $windowName = trim((string) ($_POST['window_name'] ?? ''));
            $opensAt = windows_datetime($_POST['opens_at'] ?? '');
            $closesAt = windows_datetime($_POST['closes_at'] ?? '');
            if ($windowId <= 0 || $windowName === '') {
                throw new InvalidArgumentException('اختر نافذة الرصد واكتب اسمها.');
            }
            if ($opensAt && $closesAt && $opensAt > $closesAt) {
                throw new InvalidArgumentException('تاريخ فتح الرصد يجب أن يكون قبل تاريخ الإغلاق.');
            }
            $windowStmt = $db->prepare('SELECT aw.*, sch.academic_year_id
                FROM assessment_windows aw
                JOIN assessment_schemes sch ON sch.id = aw.scheme_id
                WHERE aw.id = ?
                LIMIT 1');
            $windowStmt->execute([$windowId]);
            $window = $windowStmt->fetch(PDO::FETCH_ASSOC);
            if (!$window) {
                throw new InvalidArgumentException('نافذة الرصد غير موجودة.');
            }
            windows_assert_selected_year($currentAcademicYearId, $window, 'لا يمكن تعديل إعدادات نافذة رصد خارج العام الدراسي المختار.');
            if (($window['status'] ?? '') === 'locked') {
                throw new RuntimeException('لا يمكن تعديل إعدادات نافذة مقفلة نهائيًا. أعد فتحها بصلاحية خاصة أولًا.');
            }
            $newAllowEdit = isset($_POST['allow_edit_after_save']) ? 1 : 0;
            $newRequiresReview = isset($_POST['requires_review']) ? 1 : 0;
            if (windows_has_marks($db, $windowId)
                && ($newAllowEdit !== (int) $window['allow_edit_after_save']
                    || $newRequiresReview !== (int) $window['requires_review'])) {
                throw new RuntimeException('لا يمكن تغيير سياسات التعديل أو المراجعة بعد رصد درجات في نطاق النافذة.');
            }
            $db->prepare('UPDATE assessment_windows SET window_name = ?, opens_at = ?, closes_at = ?, allow_edit_after_save = ?, requires_review = ? WHERE id = ?')
                ->execute([$windowName, $opensAt, $closesAt, $newAllowEdit, $newRequiresReview, $windowId]);
            ActivityLog::logUpdate('assessment_window', $windowId, $windowName, ['old_name' => $window['window_name'], 'new_name' => $windowName]);
            $_SESSION['success_message'] = 'تم تحديث إعدادات نافذة الرصد بنجاح.';
            windows_redirect();
        }

        if ($action === 'delete_window') {
            $windowId = (int) ($_POST['window_id'] ?? 0);
            $windowStmt = $db->prepare('SELECT aw.*, sch.academic_year_id
                FROM assessment_windows aw
                JOIN assessment_schemes sch ON sch.id = aw.scheme_id
                WHERE aw.id = ?
                LIMIT 1');
            $windowStmt->execute([$windowId]);
            $window = $windowStmt->fetch(PDO::FETCH_ASSOC);
            if (!$window) {
                throw new InvalidArgumentException('نافذة الرصد غير موجودة.');
            }
            windows_assert_selected_year($currentAcademicYearId, $window, 'لا يمكن حذف نافذة رصد خارج العام الدراسي المختار.');
            if (($window['status'] ?? '') === 'open') {
                throw new RuntimeException('لا يمكن حذف نافذة مفتوحة. أغلقها أو اقفلها أولا.');
            }
            if (($window['status'] ?? '') === 'locked') {
                throw new RuntimeException('لا يمكن حذف نافذة مقفلة نهائيًا. أعد فتحها بصلاحية خاصة أولًا.');
            }
            if (windows_has_marks($db, $windowId)) {
                throw new RuntimeException('لا يمكن حذف النافذة لوجود درجات مرصودة ضمن نطاقها.');
            }
            $db->prepare('DELETE FROM assessment_windows WHERE id = ?')->execute([$windowId]);
            ActivityLog::logDelete('assessment_window', $windowId, (string) $window['window_name'], ['status' => $window['status']]);
            $_SESSION['success_message'] = 'تم حذف نافذة الرصد بنجاح.';
            windows_redirect();
        }
    } catch (Throwable $e) {
        if ($db->inTransaction()) {
            $db->rollBack();
        }
        $_SESSION['error_message'] = $e->getMessage();
        windows_redirect();
    }
}

$schemes = [];
$components = [];
$weeks = [];
$classes = [];
$teachers = [];
$windows = [];
$windowsCount = 0;
$openWindowsCount = 0;
$closedWindowsCount = 0;
$lockedWindowsCount = 0;

if ($schemesReady) {
    $schemeSql = "SELECT sch.*, t.name AS term_name, s.name AS subject_name, g.grade_name FROM assessment_schemes sch JOIN academic_terms t ON t.id = sch.term_id JOIN subjects s ON s.id = sch.subject_id JOIN grades g ON g.id = sch.grade_id WHERE sch.status = 'active'";
    $schemeParams = [];
    if ($currentAcademicYearId > 0) {
        $schemeSql .= ' AND sch.academic_year_id = ?';
        $schemeParams[] = $currentAcademicYearId;
    }
    $schemeSql .= ' ORDER BY t.term_order ASC, s.name, g.grade_order, sch.name';
    $stmt = $db->prepare($schemeSql);
    $stmt->execute($schemeParams);
    $schemes = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
}

if ($componentsReady) {
    $componentSql = "SELECT ac.*, sch.status AS scheme_status, sch.academic_year_id, sch.term_id, sch.grade_id, sch.name AS scheme_name, s.name AS subject_name, g.grade_name, t.name AS term_name FROM assessment_components ac JOIN assessment_schemes sch ON sch.id = ac.scheme_id JOIN academic_terms t ON t.id = sch.term_id JOIN subjects s ON s.id = sch.subject_id JOIN grades g ON g.id = sch.grade_id WHERE sch.status = 'active' AND ac.is_active = 1";
    $componentParams = [];
    if ($currentAcademicYearId > 0) {
        $componentSql .= ' AND sch.academic_year_id = ?';
        $componentParams[] = $currentAcademicYearId;
    }
    $componentSql .= ' ORDER BY t.term_order ASC, s.name, g.grade_order, sch.name, ac.sort_order ASC';
    $stmt = $db->prepare($componentSql);
    $stmt->execute($componentParams);
    $components = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
}

if ($weeksReady) {
    $weekSql = "SELECT w.*, t.name AS term_name FROM academic_weeks w JOIN academic_terms t ON t.id = w.term_id";
    $weekParams = [];
    if ($currentAcademicYearId > 0) {
        $weekSql .= ' WHERE w.academic_year_id = ?';
        $weekParams[] = $currentAcademicYearId;
    }
    $weekSql .= ' ORDER BY t.term_order ASC, w.week_order ASC';
    $stmt = $db->prepare($weekSql);
    $stmt->execute($weekParams);
    $weeks = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
}

$classes = $db->query("SELECT id, name, grade_id FROM classes WHERE status = 'active' ORDER BY grade_id, display_order, name")->fetchAll(PDO::FETCH_ASSOC) ?: [];
$teachers = $db->query("SELECT u.id, u.name FROM users u WHERE u.status = 'active'
    AND EXISTS (SELECT 1 FROM user_role_assignments ura WHERE ura.user_id = u.id AND ura.role_key = 'teacher' AND ura.status = 'active') ORDER BY u.name")->fetchAll(PDO::FETCH_ASSOC) ?: [];

if ($windowsReady) {
    $windowMaxSelect = $componentWeekRulesReady ? 'COALESCE(cwr.max_grade_override, ac.max_grade) AS max_grade' : 'ac.max_grade AS max_grade';
    $windowRuleJoin = $componentWeekRulesReady ? 'LEFT JOIN assessment_component_week_rules cwr ON cwr.component_id = aw.component_id AND cwr.week_id = aw.week_id' : '';
    $windowMarksSelect = windows_table_exists($db, 'student_marks')
        ? '(SELECT COUNT(*) FROM student_marks sm WHERE sm.scheme_id = aw.scheme_id AND sm.component_id = aw.component_id AND (aw.week_id IS NULL OR sm.week_id = aw.week_id) AND (aw.class_id IS NULL OR sm.class_id_at_entry = aw.class_id)) AS marks_count'
        : '0 AS marks_count';
    $windowSql = "SELECT aw.*, ac.name AS component_name, {$windowMaxSelect}, sch.name AS scheme_name,
            s.name AS subject_name, g.grade_name, c.name AS class_name, u.name AS teacher_name, w.name AS week_name,
            {$windowMarksSelect}
        FROM assessment_windows aw
        JOIN assessment_components ac ON ac.id = aw.component_id
        {$windowRuleJoin}
        JOIN assessment_schemes sch ON sch.id = aw.scheme_id
        JOIN subjects s ON s.id = sch.subject_id
        JOIN grades g ON g.id = aw.grade_id
        LEFT JOIN classes c ON c.id = aw.class_id
        LEFT JOIN users u ON u.id = aw.teacher_id
        LEFT JOIN academic_weeks w ON w.id = aw.week_id";
    $windowParams = [];
    if ($currentAcademicYearId > 0) {
        $windowSql .= ' WHERE sch.academic_year_id = ?';
        $windowParams[] = $currentAcademicYearId;
    }
    $windowSql .= " ORDER BY FIELD(aw.status, 'open', 'draft', 'closed', 'locked'), aw.id DESC";
    $stmt = $db->prepare($windowSql);
    $stmt->execute($windowParams);
    $windows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    $windowsCount = count($windows);
    foreach ($windows as $window) {
        if (($window['status'] ?? '') === 'open') {
            $openWindowsCount++;
        } elseif (($window['status'] ?? '') === 'closed') {
            $closedWindowsCount++;
        } elseif (($window['status'] ?? '') === 'locked') {
            $lockedWindowsCount++;
        }
    }
}

require_once '../includes/admin_header.php';
?>

<div class="admin-page-heading">
    <h1 class="h2"><i class="fas fa-lock-open me-2 text-primary"></i>نوافذ الرصد</h1>
    <div class="admin-top-actions no-print">
        <?php if ($windowsReady && $componentsReady && $schemesReady): ?>
            <button type="button" class="btn btn-header-premium btn-success shadow-sm" data-bs-toggle="modal" data-bs-target="#addWindowModal"><i class="fas fa-plus-circle me-2"></i>فتح نافذة</button>
            <button type="button" class="btn btn-header-premium btn-print-soft" data-bs-toggle="modal" data-bs-target="#addBatchWindowModal"><i class="fas fa-calendar-plus me-2"></i>فتح أسبوعي دفعة</button>
        <?php endif; ?>
    </div>
</div>

<?php if (!empty($success_message)): ?><div class="alert alert-success alert-dismissible fade show" role="alert"><i class="fas fa-check-circle me-2"></i><?php echo htmlspecialchars($success_message, ENT_QUOTES, 'UTF-8'); ?><button type="button" class="btn-close" data-bs-dismiss="alert"></button></div><?php endif; ?>
<?php if (!empty($error_message)): ?><div class="alert alert-danger alert-dismissible fade show" role="alert"><i class="fas fa-exclamation-circle me-2"></i><?php echo htmlspecialchars($error_message, ENT_QUOTES, 'UTF-8'); ?><button type="button" class="btn-close" data-bs-dismiss="alert"></button></div><?php endif; ?>



<?php if (!$windowsReady || !$componentsReady || !$schemesReady || !$calendarReady): ?>
    <div class="alert alert-warning"><i class="fas fa-triangle-exclamation me-2"></i>طبّق جداول نوافذ الرصد والخطط والتقويم أولا.</div>
<?php else: ?>
<div class="row row-cols-2 row-cols-md-4 g-3 mb-4">
    <div class="col"><div class="stat-card" style="--card-gradient: linear-gradient(135deg, #3b82f6, #2563eb);"><div class="stat-card-icon"><i class="fas fa-lock-open"></i></div><div class="stat-card-info"><div class="stat-card-number counter" data-target="<?php echo (int)$windowsCount; ?>">0</div><div class="stat-card-label">إجمالي النوافذ</div><div class="stat-card-sub"><?php echo htmlspecialchars($currentAcademicYearName ?: 'العام الحالي', ENT_QUOTES, 'UTF-8'); ?></div></div></div></div>
    <div class="col"><div class="stat-card" style="--card-gradient: linear-gradient(135deg, #10b981, #059669);"><div class="stat-card-icon"><i class="fas fa-door-open"></i></div><div class="stat-card-info"><div class="stat-card-number counter" data-target="<?php echo (int)$openWindowsCount; ?>">0</div><div class="stat-card-label">مفتوحة</div><div class="stat-card-sub">متاحة للمعلمين</div></div></div></div>
    <div class="col"><div class="stat-card" style="--card-gradient: linear-gradient(135deg, #f59e0b, #d97706);"><div class="stat-card-icon"><i class="fas fa-ban"></i></div><div class="stat-card-info"><div class="stat-card-number counter" data-target="<?php echo (int)$closedWindowsCount; ?>">0</div><div class="stat-card-label">مغلقة</div><div class="stat-card-sub">غير متاحة للرصد</div></div></div></div>
    <div class="col"><div class="stat-card" style="--card-gradient: linear-gradient(135deg, #111827, #374151);"><div class="stat-card-icon"><i class="fas fa-lock"></i></div><div class="stat-card-info"><div class="stat-card-number counter" data-target="<?php echo (int)$lockedWindowsCount; ?>">0</div><div class="stat-card-label">مقفلة</div><div class="stat-card-sub">محفوظة نهائيا</div></div></div></div>
</div>

<div data-assessment-bulk-root data-bulk-modal="windowBulkActionModal" data-entity-label="نوافذ الرصد" data-deactivate-label="إغلاق">
<div class="admin-bulk-action-bar d-none">
    <div class="admin-bulk-info">
        <span>السجلات المحددة:</span>
        <span class="admin-bulk-badge" data-assessment-selected-count>0</span>
    </div>
    <div class="admin-bulk-actions">
        <button type="button" class="btn btn-warning btn-sm assessment-bulk-trigger" data-operation="deactivate" disabled><i class="fas fa-ban me-1"></i>إغلاق المحدد</button>
        <button type="button" class="btn btn-danger btn-sm assessment-bulk-trigger" data-operation="delete" disabled><i class="fas fa-trash me-1"></i>حذف المحدد</button>
    </div>
</div>
<div class="admin-list-surface">
        <div class="table-responsive admin-table-wrap">
            <table class="table table-hover table-striped align-middle datatable admin-data-table">
                <thead><tr><th class="text-center no-sort" data-orderable="false" style="width: 42px;"><input type="checkbox" class="form-check-input assessment-select-page" title="تحديد سجلات الصفحة الحالية" aria-label="تحديد سجلات الصفحة الحالية"></th><th>النافذة</th><th>المادة/الصف</th><th>البند</th><th>الأسبوع</th><th>الفصل</th><th>المعلم</th><th>الفترة</th><th>الدرجات</th><th>الحالة</th><th class="admin-col-170px">إجراءات</th></tr></thead>
                <tbody>
                <?php if (empty($windows)): ?>
                    <tr><td colspan="11" class="text-center text-muted py-4">لم يتم إنشاء نوافذ رصد بعد.</td></tr>
                <?php else: ?>
                    <?php foreach ($windows as $window): ?>
                        <?php
                            $isScheduled = $window['status'] === 'open' && !empty($window['opens_at']) && strtotime((string) $window['opens_at']) > time();
                            $isExpired = $window['status'] === 'open' && !empty($window['closes_at']) && strtotime((string) $window['closes_at']) < time();
                            $displayStatus = $isScheduled ? 'مجدولة' : ($isExpired ? 'منتهية زمنيًا' : ($statusLabels[$window['status']] ?? $window['status']));
                            $statusClass = $isScheduled ? 'bg-info text-dark' : ($isExpired ? 'bg-danger' : ($window['status'] === 'open' ? 'bg-success' : ($window['status'] === 'draft' ? 'bg-warning text-dark' : ($window['status'] === 'locked' ? 'bg-dark' : 'bg-secondary'))));
                        ?>
                        <tr>
                            <td class="text-center"><input type="checkbox" class="form-check-input assessment-row-select" value="<?php echo (int) $window['id']; ?>" aria-label="تحديد نافذة <?php echo htmlspecialchars($window['window_name'], ENT_QUOTES, 'UTF-8'); ?>"></td>
                            <td><strong><?php echo htmlspecialchars($window['window_name'], ENT_QUOTES, 'UTF-8'); ?></strong></td>
                            <td><?php echo htmlspecialchars($window['subject_name'], ENT_QUOTES, 'UTF-8'); ?><div class="small text-muted"><?php echo htmlspecialchars($window['grade_name'], ENT_QUOTES, 'UTF-8'); ?></div></td>
                            <td><?php echo htmlspecialchars($window['component_name'] . ' / ' . AssessmentEngine::formatNumber((float) $window['max_grade']), ENT_QUOTES, 'UTF-8'); ?></td>
                            <td><?php echo htmlspecialchars($window['week_name'] ?? '-', ENT_QUOTES, 'UTF-8'); ?></td>
                            <td><?php echo htmlspecialchars($window['class_name'] ?? 'كل الفصول', ENT_QUOTES, 'UTF-8'); ?></td>
                            <td><?php echo htmlspecialchars($window['teacher_name'] ?? 'كل معلمي المادة', ENT_QUOTES, 'UTF-8'); ?></td>
                            <td><span dir="ltr"><?php echo htmlspecialchars(($window['opens_at'] ?? '-') . ' / ' . ($window['closes_at'] ?? '-'), ENT_QUOTES, 'UTF-8'); ?></span></td>
                            <td><a href="assessment_marks.php?window_id=<?php echo (int) $window['id']; ?>" class="badge bg-<?php echo (int) $window['marks_count'] > 0 ? 'primary' : 'light text-dark border'; ?> text-decoration-none" title="عرض الدرجات ضمن نطاق النافذة"><?php echo (int) $window['marks_count']; ?></a></td>
                            <td><span class="badge <?php echo $statusClass; ?>"><?php echo htmlspecialchars($displayStatus, ENT_QUOTES, 'UTF-8'); ?></span></td>
                            <td class="actions-column admin-table-actions">
                                <button type="button" class="btn btn-sm btn-action-pills btn-edit me-1 edit-window-btn" data-bs-toggle="tooltip" title="تعديل" data-window-id="<?php echo (int) $window['id']; ?>" data-window-name="<?php echo htmlspecialchars($window['window_name'], ENT_QUOTES, 'UTF-8'); ?>" data-window-opens="<?php echo htmlspecialchars(windows_datetime_input($window['opens_at'] ?? null), ENT_QUOTES, 'UTF-8'); ?>" data-window-closes="<?php echo htmlspecialchars(windows_datetime_input($window['closes_at'] ?? null), ENT_QUOTES, 'UTF-8'); ?>" data-window-allow-edit="<?php echo !empty($window['allow_edit_after_save']) ? '1' : '0'; ?>" data-window-requires-review="<?php echo !empty($window['requires_review']) ? '1' : '0'; ?>"><i class="fas fa-edit"></i></button>
                                <button type="button" class="btn btn-sm btn-action-pills btn-edit me-1 manage-window-status-btn" data-bs-toggle="tooltip" title="إدارة حالة النافذة" data-window-id="<?php echo (int) $window['id']; ?>" data-window-name="<?php echo htmlspecialchars($window['window_name'], ENT_QUOTES, 'UTF-8'); ?>" data-current-status="<?php echo htmlspecialchars((string) $window['status'], ENT_QUOTES, 'UTF-8'); ?>" data-requires-review="<?php echo !empty($window['requires_review']) ? '1' : '0'; ?>"><i class="fas fa-arrow-right-arrow-left"></i></button>
                                <a href="assessment_marks.php?window_id=<?php echo (int) $window['id']; ?>" class="btn btn-sm btn-action-pills btn-services me-1" data-bs-toggle="tooltip" title="عرض الدرجات المرتبطة"><i class="fas fa-graduation-cap"></i></a>
                                <?php if (($window['status'] ?? '') === 'open'): ?>
                                    <button type="button" class="btn btn-sm btn-action-pills btn-delete" data-bs-toggle="tooltip" title="أغلق النافذة قبل حذفها" disabled aria-disabled="true"><i class="fas fa-trash"></i></button>
                                <?php elseif ((int) ($window['marks_count'] ?? 0) > 0 || ($window['status'] ?? '') === 'locked'): ?>
                                    <?php if ($isSuperAdmin): ?>
                                        <button type="button" class="btn btn-sm btn-action-pills btn-delete super-delete-window-btn" data-bs-toggle="tooltip" title="حذف استثنائي مع الاحتفاظ بالدرجات" data-window-id="<?php echo (int) $window['id']; ?>" data-window-name="<?php echo htmlspecialchars($window['window_name'], ENT_QUOTES, 'UTF-8'); ?>" data-marks-count="<?php echo (int) ($window['marks_count'] ?? 0); ?>"><i class="fas fa-shield-halved"></i></button>
                                    <?php else: ?>
                                        <button type="button" class="btn btn-sm btn-action-pills btn-delete" data-bs-toggle="tooltip" title="الحذف الاستثنائي متاح لمدير النظام الأعلى فقط" disabled aria-disabled="true"><i class="fas fa-trash"></i></button>
                                    <?php endif; ?>
                                <?php else: ?>
                                    <button type="button" class="btn btn-sm btn-action-pills btn-delete assessment-smart-delete" data-bs-toggle="tooltip" title="حذف" data-row-id="<?php echo (int) $window['id']; ?>" data-row-name="<?php echo htmlspecialchars($window['window_name'], ENT_QUOTES, 'UTF-8'); ?>" data-row-active="<?php echo ($window['status'] ?? '') === 'open' ? '1' : '0'; ?>"><i class="fas fa-trash"></i></button>
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
$windowFormFields = static function (string $prefix, bool $batch = false) use ($schemes, $components, $weeks, $classes, $teachers): void {
    $id = static fn(string $name): string => $prefix . ucfirst($name);
?>
    <div class="row g-3">
        <div class="col-md-6"><label class="form-label">الخطة</label><select name="scheme_id" id="<?php echo $id('scheme'); ?>" class="form-select window-scheme-select" required><option value="">اختر الخطة</option><?php foreach ($schemes as $scheme): ?><option value="<?php echo (int) $scheme['id']; ?>" data-year="<?php echo (int) $scheme['academic_year_id']; ?>" data-term="<?php echo (int) $scheme['term_id']; ?>" data-grade="<?php echo (int) $scheme['grade_id']; ?>"><?php echo htmlspecialchars($scheme['subject_name'] . ' - ' . $scheme['grade_name'] . ' - ' . $scheme['term_name'] . ' - ' . $scheme['name'], ENT_QUOTES, 'UTF-8'); ?></option><?php endforeach; ?></select></div>
        <div class="col-md-6"><label class="form-label">البند</label><select name="component_id" id="<?php echo $id('component'); ?>" class="form-select window-component-select" required><option value="">اختر البند</option><?php foreach ($components as $component): ?><?php $requiresWeek = windows_component_requires_week($component); if ($batch && !$requiresWeek) { continue; } ?><option value="<?php echo (int) $component['id']; ?>" data-scheme="<?php echo (int) $component['scheme_id']; ?>" data-requires-week="<?php echo $requiresWeek ? '1' : '0'; ?>"><?php echo htmlspecialchars($component['name'] . ' / ' . AssessmentEngine::formatNumber((float) $component['max_grade']), ENT_QUOTES, 'UTF-8'); ?></option><?php endforeach; ?></select></div>
        <?php if (!$batch): ?><div class="col-md-6"><label class="form-label">الأسبوع</label><select name="week_id" id="<?php echo $id('week'); ?>" class="form-select window-week-select"><option value="">بدون أسبوع</option><?php foreach ($weeks as $week): ?><option value="<?php echo (int) $week['id']; ?>" data-year="<?php echo (int) $week['academic_year_id']; ?>" data-term="<?php echo (int) $week['term_id']; ?>" data-type="<?php echo htmlspecialchars($week['week_type'], ENT_QUOTES, 'UTF-8'); ?>"><?php echo htmlspecialchars($week['term_name'] . ' - ' . $week['name'] . ' - ' . ($week['month_label'] ?? ''), ENT_QUOTES, 'UTF-8'); ?></option><?php endforeach; ?></select></div><?php endif; ?>
        <div class="col-md-6"><label class="form-label">الفصل</label><select name="class_id" id="<?php echo $id('class'); ?>" class="form-select window-class-select"><option value="">كل الفصول</option><?php foreach ($classes as $class): ?><option value="<?php echo (int) $class['id']; ?>" data-grade="<?php echo (int) $class['grade_id']; ?>"><?php echo htmlspecialchars($class['name'], ENT_QUOTES, 'UTF-8'); ?></option><?php endforeach; ?></select></div>
        <div class="col-md-6"><label class="form-label">المعلم</label><select name="teacher_id" class="form-select"><option value="">كل معلمي المادة</option><?php foreach ($teachers as $teacher): ?><option value="<?php echo (int) $teacher['id']; ?>"><?php echo htmlspecialchars($teacher['name'], ENT_QUOTES, 'UTF-8'); ?></option><?php endforeach; ?></select></div>
        <div class="col-md-6"><label class="form-label"><?php echo $batch ? 'بادئة النوافذ' : 'اسم النافذة'; ?></label><input type="text" name="<?php echo $batch ? 'window_name_prefix' : 'window_name'; ?>" class="form-control" required maxlength="190"></div>
        <div class="col-md-4"><label class="form-label">يفتح في</label><input type="datetime-local" name="opens_at" class="form-control"></div>
        <div class="col-md-4"><label class="form-label">يغلق في</label><input type="datetime-local" name="closes_at" class="form-control"></div>
        <div class="col-md-4"><label class="form-label">الحالة الابتدائية</label><select name="status" class="form-select"><option value="draft">مسودة</option><option value="open">مفتوح</option></select><div class="form-text">الإغلاق والقفل يتمان لاحقًا من دورة الحالة.</div></div>
        <div class="col-md-6"><div class="form-check mt-2"><input class="form-check-input" type="checkbox" name="allow_edit_after_save" value="1" checked><label class="form-check-label">السماح بتعديل الدرجة بعد الحفظ</label></div></div>
        <div class="col-md-6"><div class="form-check mt-2"><input class="form-check-input" type="checkbox" name="requires_review" value="1"><label class="form-check-label">تتطلب مراجعة قبل التقارير</label></div></div>
    </div>
<?php
};
?>

<div class="modal fade" id="addWindowModal" tabindex="-1" aria-hidden="true"><div class="modal-dialog modal-xl"><div class="modal-content admin-modal admin-modal-premium admin-modal-create"><form method="post" action="assessment_windows.php"><?php echo csrfField(); ?><input type="hidden" name="action" value="add_window"><div class="modal-header"><h5 class="modal-title"><i class="fas fa-plus-circle me-2"></i>فتح نافذة رصد</h5><button type="button" class="btn-close" data-bs-dismiss="modal"></button></div><div class="modal-body"><?php $windowFormFields('add', false); ?></div><div class="modal-footer"><button type="button" class="btn btn-secondary" data-bs-dismiss="modal"><i class="fas fa-times me-1"></i>إلغاء</button><button type="submit" class="btn btn-success"><i class="fas fa-save me-1"></i>فتح</button></div></form></div></div></div>
<div class="modal fade" id="addBatchWindowModal" tabindex="-1" aria-hidden="true"><div class="modal-dialog modal-xl"><div class="modal-content admin-modal admin-modal-premium admin-modal-create"><form method="post" action="assessment_windows.php"><?php echo csrfField(); ?><input type="hidden" name="action" value="add_weekly_windows"><div class="modal-header"><h5 class="modal-title"><i class="fas fa-calendar-plus me-2"></i>فتح نوافذ أسبوعية دفعة</h5><button type="button" class="btn-close" data-bs-dismiss="modal"></button></div><div class="modal-body"><?php $windowFormFields('batch', true); ?></div><div class="modal-footer"><button type="button" class="btn btn-secondary" data-bs-dismiss="modal"><i class="fas fa-times me-1"></i>إلغاء</button><button type="submit" class="btn btn-success"><i class="fas fa-calendar-plus me-1"></i>فتح دفعة</button></div></form></div></div></div>

<div class="modal fade" id="editWindowModal" tabindex="-1" aria-hidden="true"><div class="modal-dialog"><div class="modal-content admin-modal admin-modal-premium admin-modal-edit"><form method="post" action="assessment_windows.php"><?php echo csrfField(); ?><input type="hidden" name="action" value="update_window_settings"><input type="hidden" name="window_id" id="editWindowId"><div class="modal-header"><h5 class="modal-title"><i class="fas fa-edit me-2"></i>تعديل إعدادات نافذة الرصد</h5><button type="button" class="btn-close" data-bs-dismiss="modal"></button></div><div class="modal-body"><div class="mb-3"><label class="form-label">اسم النافذة</label><input type="text" name="window_name" id="editWindowName" class="form-control" required maxlength="190"></div><div class="row g-3"><div class="col-md-6"><label class="form-label">يفتح في</label><input type="datetime-local" name="opens_at" id="editWindowOpens" class="form-control"></div><div class="col-md-6"><label class="form-label">يغلق في</label><input type="datetime-local" name="closes_at" id="editWindowCloses" class="form-control"></div></div><div class="row g-3 mt-2"><div class="col-md-6"><label class="form-check"><input class="form-check-input" type="checkbox" name="allow_edit_after_save" id="editWindowAllowEdit" value="1"><span class="form-check-label">السماح بتعديل الدرجة بعد الحفظ</span></label></div><div class="col-md-6"><label class="form-check"><input class="form-check-input" type="checkbox" name="requires_review" id="editWindowRequiresReview" value="1"><span class="form-check-label">تتطلب مراجعة</span></label></div></div><div class="alert alert-info mt-3 mb-0"><i class="fas fa-circle-info me-2"></i>تعديل نطاق النافذة أو البند يتم بإنشاء نافذة جديدة حتى لا تختلط الدرجات القديمة.</div></div><div class="modal-footer"><button type="button" class="btn btn-secondary" data-bs-dismiss="modal"><i class="fas fa-times me-1"></i>إلغاء</button><button type="submit" class="btn btn-primary"><i class="fas fa-save me-1"></i>حفظ</button></div></form></div></div></div>
<div class="modal fade" id="statusWindowModal" tabindex="-1" aria-hidden="true"><div class="modal-dialog"><div class="modal-content admin-modal admin-modal-premium admin-modal-warning"><form method="post" action="assessment_windows.php" id="statusWindowForm"><?php echo csrfField(); ?><input type="hidden" name="action" value="update_window_status"><input type="hidden" name="window_id" id="statusWindowId"><input type="hidden" id="statusWindowCurrentStatus"><div class="modal-header"><h5 class="modal-title"><i class="fas fa-arrow-right-arrow-left me-2"></i>إدارة حالة نافذة الرصد</h5><button type="button" class="btn-close" data-bs-dismiss="modal"></button></div><div class="modal-body"><div class="text-center mb-3"><i class="fas fa-shield-halved text-warning admin-modal-icon-lg"></i></div><p class="text-center">النافذة: <span id="statusWindowName" class="fw-bold text-primary"></span></p><div class="alert alert-info"><i class="fas fa-circle-info me-2"></i>الحالة الحالية: <strong id="statusWindowCurrentLabel"></strong>. يعرض النظام الانتقالات الصالحة فقط.</div><div class="mb-3" id="statusWindowCloseField"><label class="form-label">موعد الإغلاق الجديد</label><input type="datetime-local" name="reopen_closes_at" id="statusWindowClosesAt" class="form-control"><div class="form-text">إلزامي عند إعادة فتح نافذة مغلقة أو مقفلة.</div></div><div class="mb-0"><label class="form-label">سبب الانتقال</label><textarea name="transition_reason" id="statusWindowReason" class="form-control" rows="2" maxlength="500" placeholder="مثال: تصحيح درجات مرفوضة أو اعتماد نهائي بعد المراجعة"></textarea><div class="form-text">إلزامي للقفل النهائي ولإعادة الفتح.</div></div><div class="alert alert-warning mt-3 mb-0" id="statusWindowReviewNotice"><i class="fas fa-list-check me-2"></i>القفل النهائي متاح بعد اعتماد كل الدرجات المطلوبة للمراجعة. يتحقق الخادم من ذلك عند التنفيذ.</div><div class="alert alert-secondary mt-3 mb-0" id="statusWindowPublishedNotice"><i class="fas fa-file-lines me-2"></i>إعادة الفتح لا تغيّر نسخ التقارير المنشورة سابقًا؛ يلزم إعادة نشرها صراحةً إذا تغيرت الدرجات.</div></div><div class="modal-footer"><button type="button" class="btn btn-secondary" data-bs-dismiss="modal"><i class="fas fa-times me-1"></i>إلغاء</button><button type="submit" name="new_status" value="open" class="btn btn-success status-transition-submit" data-target-status="open"><i class="fas fa-lock-open me-1"></i><span>فتح</span></button><button type="submit" name="new_status" value="closed" class="btn btn-warning status-transition-submit" data-target-status="closed"><i class="fas fa-ban me-1"></i>إغلاق للرصد والمراجعة</button><button type="submit" name="new_status" value="locked" class="btn btn-danger status-transition-submit" data-target-status="locked"><i class="fas fa-lock me-1"></i>قفل نهائي</button></div></form></div></div></div>
<?php if ($isSuperAdmin): ?>
<div class="modal fade" id="superDeleteWindowModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog"><div class="modal-content admin-modal admin-modal-premium admin-modal-delete"><form method="post" action="assessment_windows.php">
        <?php echo csrfField(); ?><input type="hidden" name="action" value="super_admin_delete_window"><input type="hidden" name="window_id" id="superDeleteWindowId">
        <div class="modal-header"><h5 class="modal-title"><i class="fas fa-shield-halved me-2"></i>حذف استثنائي لنافذة الرصد</h5><button type="button" class="btn-close" data-bs-dismiss="modal"></button></div>
        <div class="modal-body"><div class="text-center mb-3"><i class="fas fa-shield-halved text-danger admin-modal-icon-lg"></i></div><p class="text-center">حذف نافذة <strong id="superDeleteWindowName" class="text-primary"></strong> مع الاحتفاظ بدرجاتها؟</p><div class="alert alert-danger"><i class="fas fa-user-shield me-2"></i>هذا الإجراء متاح لمدير النظام الأعلى فقط، وسيحذف النافذة حتى لو كانت مقفلة أو تحتوي درجات.</div><div class="alert alert-info"><i class="fas fa-graduation-cap me-2"></i>سيبقى <strong id="superDeleteWindowMarksCount">0</strong> سجل درجة في الصفحة المركزية ولن تتأثر التقارير المنشورة.</div><div class="mb-3"><label class="form-label">سبب الحذف الاستثنائي</label><textarea name="deletion_reason" class="form-control" rows="3" minlength="5" maxlength="500" required placeholder="مثال: نافذة تجريبية أُنشئت بنطاق خاطئ"></textarea></div><div class="mb-0"><label class="form-label">اكتب اسم النافذة للتأكيد</label><input type="text" name="confirmation_name" id="superDeleteWindowConfirmation" class="form-control" required autocomplete="off"></div></div>
        <div class="modal-footer"><button type="button" class="btn btn-secondary" data-bs-dismiss="modal"><i class="fas fa-times me-1"></i>إلغاء</button><button type="submit" class="btn btn-danger"><i class="fas fa-trash me-1"></i>حذف النافذة فقط</button></div>
    </form></div></div>
</div>
<?php endif; ?>

<div class="modal fade" id="windowBulkActionModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog"><div class="modal-content admin-modal admin-modal-premium admin-modal-delete"><form method="post" action="assessment_windows.php">
        <?php echo csrfField(); ?><input type="hidden" name="action" value="assessment_bulk_action"><input type="hidden" name="selected_ids" value="">
        <div class="modal-header"><h5 class="modal-title"><i class="fas fa-list-check me-2"></i><span data-bulk-modal-title>عملية على نوافذ الرصد</span></h5><button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="إغلاق"></button></div>
        <div class="modal-body"><div class="text-center mb-3"><i class="fas fa-triangle-exclamation text-warning admin-modal-icon-lg"></i></div><p class="text-center" data-bulk-modal-message></p><div class="alert alert-warning mb-0"><i class="fas fa-shield-alt me-2"></i>عدد السجلات: <strong data-bulk-modal-count>0</strong>. تُغلق النوافذ المفتوحة قبل الحذف، لكن وجود درجات في نطاق أي نافذة يلغي العملية كلها.</div></div>
        <div class="modal-footer"><button type="button" class="btn btn-secondary" data-bs-dismiss="modal"><i class="fas fa-times me-1"></i>إلغاء</button><button type="submit" name="bulk_operation" value="deactivate" class="btn btn-warning" data-bulk-deactivate-submit><i class="fas fa-ban me-1"></i>إغلاق فقط</button><button type="submit" name="bulk_operation" value="delete" class="btn btn-danger" data-bulk-delete-submit><i class="fas fa-trash me-1"></i>حذف</button></div>
    </form></div></div>
</div>

<script>
document.addEventListener('DOMContentLoaded', function () {
    function showModal(id) { const el = document.getElementById(id); if (el && window.bootstrap) new bootstrap.Modal(el).show(); }
    function setValue(id, value) { const el = document.getElementById(id); if (el) el.value = value || ''; }
    function setChecked(id, value) { const el = document.getElementById(id); if (el) el.checked = value === '1'; }
    function filterWindowForm(schemeSelect) {
        const modal = schemeSelect.closest('.modal');
        if (!modal) return;
        const selected = schemeSelect.options[schemeSelect.selectedIndex];
        const scheme = selected ? selected.value : '';
        const year = selected ? selected.getAttribute('data-year') : '';
        const term = selected ? selected.getAttribute('data-term') : '';
        const grade = selected ? selected.getAttribute('data-grade') : '';
        const componentSelect = modal.querySelector('.window-component-select');
        const weekSelect = modal.querySelector('.window-week-select');
        const classSelect = modal.querySelector('.window-class-select');
        if (componentSelect) {
            componentSelect.querySelectorAll('option[data-scheme]').forEach(function (opt) { opt.style.display = (!scheme || opt.getAttribute('data-scheme') === scheme) ? '' : 'none'; });
            const current = componentSelect.options[componentSelect.selectedIndex];
            if (current && current.getAttribute('data-scheme') && scheme && current.getAttribute('data-scheme') !== scheme) componentSelect.value = '';
        }
        if (weekSelect) {
            weekSelect.querySelectorAll('option[data-year]').forEach(function (opt) {
                const match = (!year || opt.getAttribute('data-year') === year) && (!term || opt.getAttribute('data-term') === term) && opt.getAttribute('data-type') === 'study';
                opt.style.display = match ? '' : 'none';
            });
        }
        if (classSelect) {
            classSelect.querySelectorAll('option[data-grade]').forEach(function (opt) { opt.style.display = (!grade || opt.getAttribute('data-grade') === grade) ? '' : 'none'; });
            const currentClass = classSelect.options[classSelect.selectedIndex];
            if (currentClass && currentClass.getAttribute('data-grade') && grade && currentClass.getAttribute('data-grade') !== grade) classSelect.value = '';
        }
    }
    document.querySelectorAll('.window-scheme-select').forEach(function (select) { select.addEventListener('change', function () { filterWindowForm(this); }); filterWindowForm(select); });
    document.querySelectorAll('.edit-window-btn').forEach(function (button) { button.addEventListener('click', function () { setValue('editWindowId', this.dataset.windowId); setValue('editWindowName', this.dataset.windowName); setValue('editWindowOpens', this.dataset.windowOpens); setValue('editWindowCloses', this.dataset.windowCloses); setChecked('editWindowAllowEdit', this.dataset.windowAllowEdit); setChecked('editWindowRequiresReview', this.dataset.windowRequiresReview); showModal('editWindowModal'); }); });
    const statusLabels = { draft: 'مسودة', open: 'مفتوحة', closed: 'مغلقة للمراجعة', locked: 'مقفلة نهائيًا' };
    const allowedTransitions = { draft: ['open'], open: ['closed'], closed: ['open', 'locked'], locked: ['open'] };
    document.querySelectorAll('.manage-window-status-btn').forEach(function (button) { button.addEventListener('click', function () {
        const current = this.dataset.currentStatus || '';
        setValue('statusWindowId', this.dataset.windowId);
        setValue('statusWindowCurrentStatus', current);
        document.getElementById('statusWindowName').textContent = this.dataset.windowName || '';
        document.getElementById('statusWindowCurrentLabel').textContent = statusLabels[current] || current;
        document.getElementById('statusWindowReason').value = '';
        document.getElementById('statusWindowClosesAt').value = '';
        document.querySelectorAll('.status-transition-submit').forEach(function (submit) { submit.classList.toggle('d-none', !(allowedTransitions[current] || []).includes(submit.dataset.targetStatus)); });
        const openButton = document.querySelector('.status-transition-submit[data-target-status="open"] span');
        if (openButton) openButton.textContent = current === 'draft' ? 'فتح' : (current === 'locked' ? 'إعادة فتح بصلاحية خاصة' : 'إعادة فتح');
        document.getElementById('statusWindowCloseField').classList.toggle('d-none', !['closed', 'locked'].includes(current));
        document.getElementById('statusWindowReviewNotice').classList.toggle('d-none', current !== 'closed' || this.dataset.requiresReview !== '1');
        document.getElementById('statusWindowPublishedNotice').classList.toggle('d-none', !['closed', 'locked'].includes(current));
        showModal('statusWindowModal');
    }); });
    document.getElementById('statusWindowForm')?.addEventListener('submit', function (event) {
        const submitter = event.submitter;
        const target = submitter ? submitter.value : '';
        const current = document.getElementById('statusWindowCurrentStatus').value;
        const reason = document.getElementById('statusWindowReason');
        const closesAt = document.getElementById('statusWindowClosesAt');
        reason.required = target === 'locked' || (target === 'open' && ['closed', 'locked'].includes(current));
        closesAt.required = target === 'open' && ['closed', 'locked'].includes(current);
        if (!this.reportValidity()) event.preventDefault();
    });
    document.querySelectorAll('.super-delete-window-btn').forEach(function (button) { button.addEventListener('click', function () { setValue('superDeleteWindowId', this.dataset.windowId); document.getElementById('superDeleteWindowName').textContent = this.dataset.windowName || ''; document.getElementById('superDeleteWindowMarksCount').textContent = this.dataset.marksCount || '0'; document.getElementById('superDeleteWindowConfirmation').value = ''; showModal('superDeleteWindowModal'); }); });
});
</script>
<script src="../assets/js/assessment-bulk-actions.js"></script>
<?php endif; ?>

<?php require_once '../includes/admin_footer.php'; ?>
