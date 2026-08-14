<?php
$page_title = "رصد الدرجات الجديد";
$custom_page_title = true;

require_once '../config/database.php';
require_once '../classes/utilities.php';
require_once '../classes/AcademicYear.php';
require_once '../classes/AcademicYearWriteGuard.php';
require_once '../classes/AssessmentEngine.php';
require_once '../classes/AssessmentSchemeScopeResolver.php';
require_once '../classes/ActivityLog.php';
require_once '../includes/session_config.php';
require_once '../includes/csrf.php';
Utilities::validateSession('teacher');
requireCsrfPost();

$database = new Database();
$db = $database->getConnection();

$teacherId = (int) ($_SESSION['user_id'] ?? 0);
$success_message = $_SESSION['success_message'] ?? null;
$error_message = $_SESSION['error_message'] ?? null;
unset($_SESSION['success_message'], $_SESSION['error_message']);

function tam_table_exists(PDO $db, string $table): bool
{
    $stmt = $db->prepare('SELECT 1 FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?');
    $stmt->execute([$table]);
    return (bool) $stmt->fetchColumn();
}

function tam_permission_roles(): array
{
    $roles = [
        $_SESSION['role'] ?? '',
        class_exists('Utilities') ? Utilities::getEffectiveRole() : '',
        'teacher',
    ];
    if (class_exists('Utilities') && Utilities::isSupervisor()) {
        $roles[] = 'supervisor';
        $roles[] = 'subject_supervisor';
        $roles[] = 'department_head';
    }

    return array_values(array_unique(array_filter($roles)));
}

function tam_redirect(array $params = []): void
{
    $query = $params ? ('?' . http_build_query($params)) : '';
    header('Location: assessment_marks.php' . $query);
    exit();
}

function tam_required_tables_ready(PDO $db): bool
{
    foreach (['assessment_windows', 'assessment_schemes', 'assessment_components', 'student_marks', 'academic_terms', 'assessment_student_locks'] as $table) {
        if (!tam_table_exists($db, $table)) {
            return false;
        }
    }
    return true;
}

function tam_teacher_can_record_scope(PDO $db, int $teacherId, int $academicYearId, int $termId, int $subjectId, ?int $gradeId, ?int $classId, bool $allowSpecificClassForGradeWindow = false): bool
{
    if (tam_table_exists($db, 'teacher_subject_assignments')) {
        $stmt = $db->prepare("SELECT 1 FROM teacher_subject_assignments
            WHERE teacher_id = ?
              AND academic_year_id = ?
              AND (term_id IS NULL OR term_id = ?)
              AND subject_id = ?
              AND is_active = 1
              AND can_record = 1
              AND (grade_id IS NULL OR grade_id = ?)
              AND (
                    (? IS NULL AND (? = 1 OR class_id IS NULL))
                    OR (? IS NOT NULL AND (class_id IS NULL OR class_id = ?))
                  )
              AND (starts_at IS NULL OR starts_at <= CURDATE())
              AND (ends_at IS NULL OR ends_at >= CURDATE())
            LIMIT 1");
        $stmt->execute([
            $teacherId,
            $academicYearId,
            $termId,
            $subjectId,
            $gradeId,
            $classId,
            $allowSpecificClassForGradeWindow ? 1 : 0,
            $classId,
            $classId,
        ]);
        if ($stmt->fetchColumn()) {
            return true;
        }

        return false;
    }

    if (tam_table_exists($db, 'teacher_subjects')) {
        $stmt = $db->prepare('SELECT 1 FROM teacher_subjects WHERE teacher_id = ? AND subject_id = ? LIMIT 1');
        $stmt->execute([$teacherId, $subjectId]);
        return (bool) $stmt->fetchColumn();
    }

    return false;
}

function tam_teacher_has_class(PDO $db, int $teacherId, int $academicYearId, int $termId, int $subjectId, int $gradeId, int $classId): bool
{
    if (tam_table_exists($db, 'teacher_subject_assignments')) {
        return tam_teacher_can_record_scope($db, $teacherId, $academicYearId, $termId, $subjectId, $gradeId, $classId);
    }

    if (tam_table_exists($db, 'user_class_access')) {
        $stmt = $db->prepare('SELECT 1 FROM user_class_access WHERE user_id = ? AND class_id = ? LIMIT 1');
        $stmt->execute([$teacherId, $classId]);
        return (bool) $stmt->fetchColumn();
    }

    return false;
}

function tam_fetch_open_windows(PDO $db, int $teacherId, int $academicYearId): array
{
    if (!tam_required_tables_ready($db) || $academicYearId <= 0) {
        return [];
    }

    $weekRulesReady = tam_table_exists($db, 'assessment_component_week_rules');
    $maxGradeSelect = $weekRulesReady ? 'COALESCE(cwr.max_grade_override, ac.max_grade) AS max_grade' : 'ac.max_grade';
    $weekRuleJoin = $weekRulesReady ? 'LEFT JOIN assessment_component_week_rules cwr ON cwr.component_id = aw.component_id AND cwr.week_id = aw.week_id' : '';
    $weekRuleWhere = $weekRulesReady ? 'AND (aw.week_id IS NULL OR cwr.is_included IS NULL OR cwr.is_included = 1)' : '';

    $stmt = $db->prepare("SELECT aw.*, sch.academic_year_id, sch.term_id, sch.subject_id, sch.grade_id,
            sch.name AS scheme_name, sch.enable_excused_absence, sch.normal_absence_policy,
            ac.name AS component_name, {$maxGradeSelect},
            ac.accepts_absence, ac.accepts_excused_absence,
            s.name AS subject_name, g.grade_name, t.name AS term_name, w.name AS week_name,
            c.name AS class_name
        FROM assessment_windows aw
        JOIN assessment_schemes sch ON sch.id = aw.scheme_id
        JOIN assessment_components ac ON ac.id = aw.component_id
        {$weekRuleJoin}
        JOIN subjects s ON s.id = sch.subject_id
        JOIN grades g ON g.id = sch.grade_id
        JOIN academic_terms t ON t.id = sch.term_id
        LEFT JOIN academic_weeks w ON w.id = aw.week_id
        LEFT JOIN classes c ON c.id = aw.class_id
        WHERE aw.status = 'open'
          AND sch.academic_year_id = ?
          AND sch.status = 'active'
          AND ac.is_active = 1
          AND (aw.teacher_id IS NULL OR aw.teacher_id = ?)
          AND (aw.opens_at IS NULL OR aw.opens_at <= NOW())
          AND (aw.closes_at IS NULL OR aw.closes_at >= NOW())
          {$weekRuleWhere}
        ORDER BY aw.closes_at IS NULL ASC, aw.closes_at ASC, aw.id DESC");
    $stmt->execute([$academicYearId, $teacherId]);
    $windows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

    return array_values(array_filter($windows, static function (array $window) use ($db, $teacherId): bool {
        return tam_fetch_window_classes($db, $window, $teacherId) !== [];
    }));
}

function tam_fetch_window_classes(PDO $db, array $window, int $teacherId): array
{
    $scopeResolver = new AssessmentSchemeScopeResolver($db);
    $schemeId = (int) ($window['scheme_id'] ?? 0);
    $gradeId = (int) ($window['grade_id'] ?? 0);
    if (!empty($window['class_id'])) {
        $classId = (int) $window['class_id'];
        if (!$scopeResolver->schemeCoversClass($schemeId, $gradeId, $classId)
            || !tam_teacher_has_class(
                $db,
                $teacherId,
                (int) $window['academic_year_id'],
                (int) $window['term_id'],
                (int) $window['subject_id'],
                $gradeId,
                $classId
            )) {
            return [];
        }
        return [[
            'id' => (int) $window['class_id'],
            'name' => (string) ($window['class_name'] ?? ''),
            'grade_id' => $gradeId,
        ]];
    }

    $stmt = $db->prepare('SELECT id, name, grade_id FROM classes WHERE grade_id = ? ORDER BY name');
    $stmt->execute([(int) $window['grade_id']]);
    $classes = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

    return array_values(array_filter($classes, function (array $class) use ($db, $window, $teacherId, $scopeResolver, $schemeId, $gradeId): bool {
        if (!$scopeResolver->schemeCoversClass($schemeId, $gradeId, (int) $class['id'])) {
            return false;
        }
        return tam_teacher_has_class(
            $db,
            $teacherId,
            (int) $window['academic_year_id'],
            (int) $window['term_id'],
            (int) $window['subject_id'],
            (int) $window['grade_id'],
            (int) $class['id']
        );
    }));
}

function tam_fetch_students(PDO $db, int $classId, int $academicYearId): array
{
    if (tam_table_exists($db, 'student_enrollments')) {
        $stmt = $db->prepare("SELECT u.id, u.name, u.username, se.class_id, c.name AS class_name
            FROM student_enrollments se
            JOIN users u ON u.id = se.student_id
            LEFT JOIN classes c ON c.id = se.class_id
            WHERE se.class_id = ? AND se.academic_year_id = ? AND se.enrollment_status = 'enrolled'
              AND u.role = 'student' AND u.status = 'active'
              AND u.deleted_at IS NULL
            ORDER BY u.name");
        $stmt->execute([$classId, $academicYearId]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    }

    $stmt = $db->prepare("SELECT u.id, u.name, u.username, u.class_id, c.name AS class_name
        FROM users u
        LEFT JOIN classes c ON c.id = u.class_id
        WHERE u.class_id = ? AND u.role = 'student' AND u.status = 'active'
          AND u.deleted_at IS NULL
        ORDER BY u.name");
    $stmt->execute([$classId]);
    return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
}

function tam_fetch_marks(PDO $db, array $students, array $window): array
{
    if (empty($students) || !tam_table_exists($db, 'student_marks')) {
        return [];
    }

    $studentIds = array_map('intval', array_column($students, 'id'));
    $placeholders = implode(',', array_fill(0, count($studentIds), '?'));
    $weekSql = empty($window['week_id']) ? 'week_id IS NULL' : 'week_id = ?';
    $params = $studentIds;
    $params[] = (int) $window['component_id'];
    if (!empty($window['week_id'])) {
        $params[] = (int) $window['week_id'];
    }
    $params[] = (int) $window['academic_year_id'];
    $params[] = (int) $window['term_id'];

    $stmt = $db->prepare("SELECT * FROM student_marks
        WHERE student_id IN ($placeholders)
          AND component_id = ?
          AND $weekSql
          AND academic_year_id = ?
          AND term_id = ?");
    $stmt->execute($params);

    $marks = [];
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) ?: [] as $mark) {
        $marks[(int) $mark['student_id']] = $mark;
    }
    return $marks;
}

function tam_teacher_can_delete_mark(PDO $db, int $teacherId, array $window, int $classId): bool
{
    if (!tam_table_exists($db, 'assessment_permissions')) {
        return false;
    }

    $engine = new AssessmentEngine($db);
    $checks = [
        ['global', null],
        ['subject', (int) $window['subject_id']],
        ['grade', (int) $window['grade_id']],
        ['class', $classId],
        ['scheme', (int) $window['scheme_id']],
    ];
    foreach ($checks as $check) {
        if ($engine->userHasAnyPermissionRole($teacherId, tam_permission_roles(), 'delete_mark', $check[0], $check[1])) {
            return true;
        }
    }
    return false;
}

function tam_mark_display(array $mark = null): string
{
    if (!$mark || ($mark['mark_status'] ?? 'empty') === AssessmentEngine::STATUS_EMPTY) {
        return '';
    }
    if (($mark['mark_status'] ?? '') === AssessmentEngine::STATUS_ABSENT) {
        return 'غ';
    }
    if (($mark['mark_status'] ?? '') === AssessmentEngine::STATUS_EXCUSED_ABSENT) {
        return 'غ.ع';
    }
    return AssessmentEngine::formatNumber($mark['value'] ?? null);
}

function tam_mark_status_label(?string $status): string
{
    $labels = [
        AssessmentEngine::STATUS_PRESENT => 'درجة مرصودة',
        AssessmentEngine::STATUS_ABSENT => 'غياب رصد درجات',
        AssessmentEngine::STATUS_EXCUSED_ABSENT => 'غياب رصد درجات بعذر',
        AssessmentEngine::STATUS_EMPTY => 'فارغة',
    ];

    return $labels[$status ?? AssessmentEngine::STATUS_EMPTY] ?? (string) $status;
}

function tam_review_status_label(?string $status): string
{
    $labels = [
        'pending' => 'بانتظار المراجعة',
        'approved' => 'معتمدة',
        'rejected' => 'مرفوضة',
        'not_required' => 'لا تتطلب مراجعة',
    ];

    return $labels[$status ?? 'not_required'] ?? (string) $status;
}

function tam_send_marks_csv(array $students, array $marks, array $window, string $className): void
{
    $filenameParts = [
        'assessment_marks',
        preg_replace('/[^A-Za-z0-9_-]+/', '_', (string) ($window['subject_name'] ?? 'subject')),
        date('Ymd_His'),
    ];
    $filename = implode('_', array_filter($filenameParts)) . '.csv';

    header('Content-Type: text/csv; charset=UTF-8');
    header('Content-Disposition: attachment; filename="' . $filename . '"');
    header('Pragma: no-cache');
    header('Expires: 0');

    echo "\xEF\xBB\xBF";
    $output = fopen('php://output', 'w');
    if (!$output) {
        exit();
    }

    fputcsv($output, [
        '#',
        'اسم الطالب',
        'اسم المستخدم',
        'الفصل',
        'المادة',
        'المرحلة/الصف',
        'الفصل الدراسي',
        'البند',
        'الأسبوع',
        'النهاية العظمى',
        'الدرجة/الحالة',
        'حالة الرصد',
        'الملاحظة',
        'حالة المراجعة',
        'ملاحظة المراجعة',
    ]);

    foreach ($students as $index => $student) {
        $mark = $marks[(int) $student['id']] ?? null;
        fputcsv($output, [
            $index + 1,
            $student['name'] ?? '',
            $student['username'] ?? '',
            $className,
            $window['subject_name'] ?? '',
            $window['grade_name'] ?? '',
            $window['term_name'] ?? '',
            $window['component_name'] ?? '',
            $window['week_name'] ?? '',
            AssessmentEngine::formatNumber($window['max_grade'] ?? null),
            tam_mark_display($mark),
            tam_mark_status_label($mark['mark_status'] ?? AssessmentEngine::STATUS_EMPTY),
            $mark['note'] ?? '',
            tam_review_status_label($mark['review_status'] ?? 'not_required'),
            $mark['review_note'] ?? '',
        ]);
    }

    fclose($output);
    exit();
}

function tam_csv_cell(array $row, ?int $index): string
{
    if ($index === null || !array_key_exists($index, $row)) {
        return '';
    }

    return trim(preg_replace('/^\xEF\xBB\xBF/', '', (string) $row[$index]));
}

function tam_csv_header_index(array $header, array $names, ?int $fallback = null): ?int
{
    $normalizedNames = array_map(static function (string $name): string {
        return mb_strtolower(trim($name), 'UTF-8');
    }, $names);

    foreach ($header as $index => $label) {
        $clean = mb_strtolower(tam_csv_cell($header, (int) $index), 'UTF-8');
        if (in_array($clean, $normalizedNames, true)) {
            return (int) $index;
        }
    }

    return $fallback !== null && array_key_exists($fallback, $header) ? $fallback : null;
}

function tam_student_lookup_key($value): string
{
    return mb_strtolower(trim((string) $value), 'UTF-8');
}

function tam_parse_marks_csv(string $path, array $students): array
{
    $handle = fopen($path, 'r');
    if (!$handle) {
        throw new RuntimeException('تعذر قراءة ملف CSV.');
    }

    $header = fgetcsv($handle);
    if ($header === false) {
        fclose($handle);
        throw new InvalidArgumentException('ملف CSV فارغ.');
    }

    $usernameIndex = tam_csv_header_index($header, ['اسم المستخدم', 'username', 'user_name'], 2);
    $nameIndex = tam_csv_header_index($header, ['اسم الطالب', 'student_name', 'name'], 1);
    $markIndex = tam_csv_header_index($header, ['الدرجة/الحالة', 'الدرجة', 'mark', 'grade', 'score'], 10);
    $noteIndex = tam_csv_header_index($header, ['الملاحظة', 'ملاحظة', 'note', 'notes'], 12);
    if ($markIndex === null) {
        fclose($handle);
        throw new InvalidArgumentException('لم يتم العثور على عمود الدرجة/الحالة في ملف CSV.');
    }

    $studentsByUsername = [];
    $studentsByName = [];
    foreach ($students as $student) {
        $studentId = (int) $student['id'];
        $usernameKey = tam_student_lookup_key($student['username'] ?? '');
        $nameKey = tam_student_lookup_key($student['name'] ?? '');
        if ($usernameKey !== '') {
            $studentsByUsername[$usernameKey] = $studentId;
        }
        if ($nameKey !== '') {
            $studentsByName[$nameKey] = $studentId;
        }
    }

    $marks = [];
    $notes = [];
    $matched = 0;
    $skipped = 0;
    $lineNumber = 1;
    while (($row = fgetcsv($handle)) !== false) {
        $lineNumber++;
        if (count(array_filter($row, static function ($value): bool {
            return trim((string) $value) !== '';
        })) === 0) {
            continue;
        }

        $usernameKey = tam_student_lookup_key(tam_csv_cell($row, $usernameIndex));
        $nameKey = tam_student_lookup_key(tam_csv_cell($row, $nameIndex));
        $studentId = $usernameKey !== '' && isset($studentsByUsername[$usernameKey])
            ? $studentsByUsername[$usernameKey]
            : ($nameKey !== '' && isset($studentsByName[$nameKey]) ? $studentsByName[$nameKey] : 0);

        if ($studentId <= 0) {
            $skipped++;
            continue;
        }
        if (array_key_exists($studentId, $marks)) {
            fclose($handle);
            throw new InvalidArgumentException('يوجد الطالب أكثر من مرة في ملف CSV عند السطر ' . $lineNumber . '.');
        }

        $mark = tam_csv_cell($row, $markIndex);
        $note = tam_csv_cell($row, $noteIndex);
        if ($mark === '' && $note === '') {
            continue;
        }

        $marks[$studentId] = $mark;
        $notes[$studentId] = $note;
        $matched++;
    }
    fclose($handle);

    if ($matched === 0) {
        throw new InvalidArgumentException('لم يتم العثور على أي درجات قابلة للاستيراد داخل الملف.');
    }

    return [
        'marks' => $marks,
        'notes' => $notes,
        'matched' => $matched,
        'skipped' => $skipped,
    ];
}

$currentAcademicYear = AcademicYear::getCurrent($db);
$currentAcademicYearId = $currentAcademicYear ? (int) $currentAcademicYear['id'] : 0;
$foundationReady = tam_required_tables_ready($db);
$openWindows = tam_fetch_open_windows($db, $teacherId, $currentAcademicYearId);
$openWindowsById = [];
foreach ($openWindows as $window) {
    $openWindowsById[(int) $window['id']] = $window;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && $foundationReady) {
    try {
        $action = (string) ($_POST['action'] ?? '');
        if (!in_array($action, ['save_marks', 'import_marks_csv'], true)) {
            throw new InvalidArgumentException('الإجراء المطلوب غير معروف.');
        }
        $isCsvImport = $action === 'import_marks_csv';

        $windowId = (int) ($_POST['window_id'] ?? 0);
        $classId = (int) ($_POST['class_id'] ?? 0);
        if (!isset($openWindowsById[$windowId]) || $classId <= 0) {
            throw new InvalidArgumentException('نافذة الرصد أو الفصل غير متاحين لك.');
        }

        $window = $openWindowsById[$windowId];
        $availableClasses = tam_fetch_window_classes($db, $window, $teacherId);
        $allowedClassIds = array_map('intval', array_column($availableClasses, 'id'));
        if (!in_array($classId, $allowedClassIds, true)) {
            throw new InvalidArgumentException('الفصل المحدد غير ضمن صلاحياتك.');
        }

        $students = tam_fetch_students($db, $classId, $currentAcademicYearId);
        $studentIds = array_map('intval', array_column($students, 'id'));
        $lockedStudentIds = (new AssessmentEngine($db))->getLockedStudentIds($studentIds, $currentAcademicYearId);
        $lockedStudentMap = array_fill_keys($lockedStudentIds, true);
        $existingMarks = tam_fetch_marks($db, $students, $window);
        $importResult = null;
        if ($isCsvImport) {
            $upload = $_FILES['csv_file'] ?? null;
            if (!$upload || (int) ($upload['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
                throw new InvalidArgumentException('اختر ملف CSV صالحا للاستيراد.');
            }
            $extension = mb_strtolower(pathinfo((string) ($upload['name'] ?? ''), PATHINFO_EXTENSION), 'UTF-8');
            if ($extension !== 'csv') {
                throw new InvalidArgumentException('صيغة الملف يجب أن تكون CSV.');
            }
            $importResult = tam_parse_marks_csv((string) $upload['tmp_name'], $students);
            $submittedMarks = $importResult['marks'];
            $submittedNotes = $importResult['notes'];
            $submittedDeletes = [];
        } else {
            $submittedMarks = is_array($_POST['marks'] ?? null) ? $_POST['marks'] : [];
            $submittedNotes = is_array($_POST['notes'] ?? null) ? $_POST['notes'] : [];
            $submittedDeletes = is_array($_POST['delete_marks'] ?? null) ? $_POST['delete_marks'] : [];
        }
        $submittedStudentMap = array_fill_keys(array_map('intval', array_unique(array_merge(
            array_keys($submittedMarks),
            array_keys($submittedNotes),
            array_keys($submittedDeletes)
        ))), true);
        $bulkAction = in_array(($_POST['bulk_action'] ?? ''), ['fill_column', 'absent_all', 'excused_absent_all'], true)
            ? (string) $_POST['bulk_action']
            : '';
        $bulkActionLabels = [
            'fill_column' => 'ملء جماعي للعمود',
            'absent_all' => 'غياب رصد درجات جماعي للفصل',
            'excused_absent_all' => 'غياب رصد درجات بعذر للفصل',
        ];
        $auditReason = $isCsvImport ? 'استيراد CSV من صفحة المعلم الجديدة' : ($bulkActionLabels[$bulkAction] ?? 'رصد من صفحة المعلم الجديدة');
        $deleteAuditReason = mb_substr(
            'حذف من صفحة المعلم الجديدة'
            . ' | النافذة: ' . (string) ($window['window_name'] ?? '')
            . ' | المادة: ' . (string) ($window['subject_name'] ?? '')
            . ' | البند: ' . (string) ($window['component_name'] ?? '')
            . (!empty($window['week_name']) ? ' | الأسبوع: ' . (string) $window['week_name'] : '')
            . ' | class_id: ' . $classId,
            0,
            500,
            'UTF-8'
        );
        $canDeleteMark = tam_teacher_can_delete_mark($db, $teacherId, $window, $classId);

        $selectStmt = $db->prepare("SELECT * FROM student_marks
            WHERE student_id = ? AND component_id = ?
              AND ((week_id IS NULL AND ? IS NULL) OR week_id = ?)
              AND academic_year_id = ? AND term_id = ?
            LIMIT 1");
        $insertStmt = $db->prepare("INSERT INTO student_marks
            (student_id, scheme_id, component_id, week_id, week_slot, academic_year_id, term_id, subject_id,
             grade_id, class_id_at_entry, value, mark_status, note, recorded_by, review_status)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
        $updateStmt = $db->prepare("UPDATE student_marks
            SET value = ?, mark_status = ?, note = ?, recorded_by = ?,
                review_status = ?, reviewed_by = NULL, reviewed_at = NULL, review_note = NULL,
                class_id_at_entry = ?
            WHERE id = ?");
        $syncClassStmt = $db->prepare('UPDATE student_marks SET class_id_at_entry = ? WHERE id = ?');
        $deleteStmt = $db->prepare('DELETE FROM student_marks WHERE id = ?');
        $auditStmt = $db->prepare("INSERT INTO student_mark_audit
            (mark_id, student_id, action, old_value, new_value, old_status, new_status, reason, changed_by)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)");

        $savedCount = 0;
        $deletedCount = 0;
        $db->beginTransaction();
        $windowLockStmt = $db->prepare("SELECT status,
                (opens_at IS NULL OR opens_at <= NOW()) AS has_started,
                (closes_at IS NULL OR closes_at >= NOW()) AS has_not_expired
            FROM assessment_windows WHERE id = ? FOR UPDATE");
        $windowLockStmt->execute([$windowId]);
        $liveWindowState = $windowLockStmt->fetch(PDO::FETCH_ASSOC);
        if (!$liveWindowState
            || (string) ($liveWindowState['status'] ?? '') !== 'open'
            || (int) ($liveWindowState['has_started'] ?? 0) !== 1
            || (int) ($liveWindowState['has_not_expired'] ?? 0) !== 1) {
            throw new RuntimeException('أُغلقت نافذة الرصد أو انتهى وقتها قبل الحفظ. لم تُحفظ أي درجة.');
        }
        (new AcademicYearWriteGuard($db))->assertWritable((int) $window['academic_year_id']);
        foreach ($studentIds as $studentId) {
            if ($isCsvImport && !isset($submittedStudentMap[$studentId])) {
                continue;
            }
            if (isset($lockedStudentMap[$studentId])) {
                if (isset($submittedMarks[$studentId]) || isset($submittedNotes[$studentId]) || isset($submittedDeletes[$studentId])) {
                    throw new RuntimeException('لا يمكن تعديل درجات طالب مقفل بسبب تخرجه أو نقله.');
                }
                continue;
            }

            $raw = (string) ($submittedMarks[$studentId] ?? '');
            $note = trim((string) ($submittedNotes[$studentId] ?? ''));

            $selectStmt->execute([
                $studentId,
                (int) $window['component_id'],
                $window['week_id'] !== null ? (int) $window['week_id'] : null,
                $window['week_id'] !== null ? (int) $window['week_id'] : null,
                (int) $window['academic_year_id'],
                (int) $window['term_id'],
            ]);
            $oldMark = $selectStmt->fetch(PDO::FETCH_ASSOC) ?: null;
            if (isset($submittedDeletes[$studentId]) && $oldMark) {
                if (!$canDeleteMark) {
                    throw new RuntimeException('ليس لديك صلاحية حذف الدرجات.');
                }
                $auditStmt->execute([
                    (int) $oldMark['id'],
                    $studentId,
                    'delete',
                    $oldMark['value'] !== null ? (string) $oldMark['value'] : null,
                    null,
                    $oldMark['mark_status'] ?? null,
                    null,
                    $deleteAuditReason,
                    $teacherId,
                ]);
                $deleteStmt->execute([(int) $oldMark['id']]);
                $deletedCount++;
                continue;
            }

            $normalized = AssessmentEngine::normalizeMarkInput(
                $raw,
                (float) $window['max_grade'],
                !empty($window['enable_excused_absence']) && !empty($window['accepts_excused_absence']),
                !empty($window['accepts_absence'])
            );
            if (!$oldMark && $normalized['status'] === AssessmentEngine::STATUS_EMPTY && $note === '') {
                continue;
            }

            if ($oldMark) {
                $oldValue = $oldMark['value'] !== null ? (float) $oldMark['value'] : null;
                $newValue = $normalized['value'] !== null ? (float) $normalized['value'] : null;
                $oldNote = trim((string) ($oldMark['note'] ?? ''));
                $newNote = $note;
                $sameValue = ($oldValue === null && $newValue === null)
                    || ($oldValue !== null && $newValue !== null && abs($oldValue - $newValue) <= 0.001);
                $sameMark = $sameValue
                    && (string) ($oldMark['mark_status'] ?? AssessmentEngine::STATUS_EMPTY) === (string) $normalized['status']
                    && $oldNote === $newNote;
                $sameClass = (int) ($oldMark['class_id_at_entry'] ?? 0) === $classId;

                if ($sameMark && $sameClass) {
                    continue;
                }

                if (!$sameMark && empty($window['allow_edit_after_save']) && ($oldMark['mark_status'] ?? AssessmentEngine::STATUS_EMPTY) !== AssessmentEngine::STATUS_EMPTY) {
                    throw new RuntimeException('هذه النافذة لا تسمح بتعديل درجة محفوظة مسبقا.');
                }
            }

            if ($oldMark && $sameMark && !$sameClass) {
                $syncClassStmt->execute([$classId, (int) $oldMark['id']]);
                $auditStmt->execute([
                    (int) $oldMark['id'],
                    $studentId,
                    'update',
                    $oldMark['value'] !== null ? (string) $oldMark['value'] : null,
                    $oldMark['value'] !== null ? (string) $oldMark['value'] : null,
                    $oldMark['mark_status'] ?? null,
                    $oldMark['mark_status'] ?? null,
                    'مزامنة فصل الطالب بعد النقل',
                    $teacherId,
                ]);
                $savedCount++;
                continue;
            }

            $reviewStatus = !empty($window['requires_review']) ? 'pending' : 'not_required';

            if ($oldMark) {
                $updateStmt->execute([
                    $normalized['value'],
                    $normalized['status'],
                    $note !== '' ? $note : null,
                    $teacherId,
                    $reviewStatus,
                    $classId,
                    (int) $oldMark['id'],
                ]);
                $markId = (int) $oldMark['id'];
                $actionName = 'update';
            } else {
                $insertStmt->execute([
                    $studentId,
                    (int) $window['scheme_id'],
                    (int) $window['component_id'],
                    $window['week_id'] !== null ? (int) $window['week_id'] : null,
                    $window['week_id'] !== null ? (int) $window['week_id'] : 0,
                    (int) $window['academic_year_id'],
                    (int) $window['term_id'],
                    (int) $window['subject_id'],
                    (int) $window['grade_id'],
                    $classId,
                    $normalized['value'],
                    $normalized['status'],
                    $note !== '' ? $note : null,
                    $teacherId,
                    $reviewStatus,
                ]);
                $markId = (int) $db->lastInsertId();
                $actionName = 'create';
            }

            $auditStmt->execute([
                $markId,
                $studentId,
                $actionName,
                $oldMark ? (string) ($oldMark['value'] ?? '') : null,
                $normalized['value'] !== null ? (string) $normalized['value'] : null,
                $oldMark['mark_status'] ?? null,
                $normalized['status'],
                $auditReason,
                $teacherId,
            ]);
            $savedCount++;
        }
        $db->commit();

        if ($isCsvImport) {
            ActivityLog::logImport('student_mark', $savedCount, [
                'window' => $window['window_name'],
                'component' => $window['component_name'],
                'subject' => $window['subject_name'],
                'class_id' => $classId,
                'matched_rows' => (int) ($importResult['matched'] ?? 0),
                'skipped_rows' => (int) ($importResult['skipped'] ?? 0),
            ]);
        } else {
            ActivityLog::logUpdate('student_mark', $windowId, (string) $window['window_name'], [
                'window' => $window['window_name'],
                'component' => $window['component_name'],
                'subject' => $window['subject_name'],
                'class_id' => $classId,
                'count' => $savedCount,
                'deleted_count' => $deletedCount,
                'bulk_action' => $bulkAction ?: null,
            ]);
        }

        if ($isCsvImport) {
            $_SESSION['success_message'] = 'تم استيراد CSV وحفظ ' . $savedCount . ' درجة/حالة'
                . (((int) ($importResult['skipped'] ?? 0) > 0) ? ' وتخطي ' . (int) $importResult['skipped'] . ' صف غير مطابق.' : '.');
        } elseif ($savedCount === 0 && $deletedCount === 0) {
            $_SESSION['success_message'] = 'لم يتم العثور على تغييرات جديدة للحفظ.';
        } else {
            $bulkMessage = $bulkAction ? ' (' . $bulkActionLabels[$bulkAction] . ')' : '';
            $_SESSION['success_message'] = "تم حفظ {$savedCount} درجة/حالة وحذف {$deletedCount} درجة بنجاح{$bulkMessage}.";
        }
        tam_redirect(['window_id' => $windowId, 'class_id' => $classId]);
    } catch (Throwable $e) {
        if ($db->inTransaction()) {
            $db->rollBack();
        }
        $_SESSION['error_message'] = $e->getMessage();
        tam_redirect([
            'window_id' => (int) ($_POST['window_id'] ?? 0),
            'class_id' => (int) ($_POST['class_id'] ?? 0),
        ]);
    }
}

$selectedWindowId = (int) ($_GET['window_id'] ?? 0);
$selectedWindow = $openWindowsById[$selectedWindowId] ?? null;
$filterSubjectId = (int) ($_GET['subject_id'] ?? 0);
$filterGradeId = (int) ($_GET['grade_id'] ?? 0);
if ($selectedWindow && $filterSubjectId <= 0) {
    $filterSubjectId = (int) $selectedWindow['subject_id'];
}
if ($selectedWindow && $filterGradeId <= 0) {
    $filterGradeId = (int) $selectedWindow['grade_id'];
}
if ($selectedWindow) {
    $windowMatchesFilters = ($filterSubjectId <= 0 || (int) $selectedWindow['subject_id'] === $filterSubjectId)
        && ($filterGradeId <= 0 || (int) $selectedWindow['grade_id'] === $filterGradeId);
    if (!$windowMatchesFilters) {
        $selectedWindow = null;
        $selectedWindowId = 0;
    }
}
$subjectOptions = [];
$gradeOptions = [];
foreach ($openWindows as $window) {
    $subjectId = (int) $window['subject_id'];
    $gradeId = (int) $window['grade_id'];
    $subjectOptions[$subjectId] = [
        'id' => $subjectId,
        'name' => (string) ($window['subject_name'] ?? ''),
    ];
    if ($filterSubjectId <= 0 || $subjectId === $filterSubjectId) {
        $gradeOptions[$gradeId] = [
            'id' => $gradeId,
            'name' => (string) ($window['grade_name'] ?? ''),
        ];
    }
}
uasort($subjectOptions, static function (array $a, array $b): int {
    return strnatcasecmp($a['name'], $b['name']);
});
uasort($gradeOptions, static function (array $a, array $b): int {
    return strnatcasecmp($a['name'], $b['name']);
});
$filteredOpenWindows = array_values(array_filter($openWindows, static function (array $window) use ($filterSubjectId, $filterGradeId): bool {
    return ($filterSubjectId <= 0 || (int) $window['subject_id'] === $filterSubjectId)
        && ($filterGradeId <= 0 || (int) $window['grade_id'] === $filterGradeId);
}));
$availableClasses = $selectedWindow ? tam_fetch_window_classes($db, $selectedWindow, $teacherId) : [];
$selectedClassId = (int) ($_GET['class_id'] ?? 0);
if ($selectedWindow && $selectedClassId <= 0 && count($availableClasses) === 1) {
    $selectedClassId = (int) $availableClasses[0]['id'];
}
$allowedClassIds = array_map('intval', array_column($availableClasses, 'id'));
if ($selectedClassId > 0 && !in_array($selectedClassId, $allowedClassIds, true)) {
    $selectedClassId = 0;
}

$students = ($selectedWindow && $selectedClassId > 0) ? tam_fetch_students($db, $selectedClassId, $currentAcademicYearId) : [];
$marks = ($selectedWindow && $selectedClassId > 0) ? tam_fetch_marks($db, $students, $selectedWindow) : [];
$lockedStudentIds = ($selectedWindow && $selectedClassId > 0)
    ? (new AssessmentEngine($db))->getLockedStudentIds(array_map('intval', array_column($students, 'id')), $currentAcademicYearId)
    : [];
$lockedStudentMap = array_fill_keys($lockedStudentIds, true);
$canDeleteSelectedMarks = ($selectedWindow && $selectedClassId > 0)
    ? tam_teacher_can_delete_mark($db, $teacherId, $selectedWindow, $selectedClassId)
    : false;
$absenceAvailable = $selectedWindow
    ? !empty($selectedWindow['accepts_absence'])
    : false;
$excusedAbsenceAvailable = $selectedWindow
    ? (!empty($selectedWindow['enable_excused_absence']) && !empty($selectedWindow['accepts_excused_absence']))
    : false;
$reviewRequired = $selectedWindow ? !empty($selectedWindow['requires_review']) : false;
$selectedClassName = '';
foreach ($availableClasses as $class) {
    if ((int) $class['id'] === $selectedClassId) {
        $selectedClassName = (string) $class['name'];
        break;
    }
}

$stats = [
    'students' => count($students),
    'empty' => 0,
    'zero' => 0,
    'below_half' => 0,
    'absent' => 0,
    'excused_absent' => 0,
    'locked' => count($lockedStudentIds),
    'review_pending' => 0,
    'review_approved' => 0,
    'review_rejected' => 0,
];
if ($selectedWindow && $students) {
    $halfGrade = ((float) $selectedWindow['max_grade']) / 2;
    foreach ($students as $student) {
        $mark = $marks[(int) $student['id']] ?? null;
        if (!$mark || ($mark['mark_status'] ?? AssessmentEngine::STATUS_EMPTY) === AssessmentEngine::STATUS_EMPTY) {
            $stats['empty']++;
            continue;
        }
        if (!empty($selectedWindow['requires_review'])) {
            $reviewStatus = (string) ($mark['review_status'] ?? 'not_required');
            if ($reviewStatus === 'pending') {
                $stats['review_pending']++;
            } elseif ($reviewStatus === 'approved') {
                $stats['review_approved']++;
            } elseif ($reviewStatus === 'rejected') {
                $stats['review_rejected']++;
            }
        }
        if (($mark['mark_status'] ?? '') === AssessmentEngine::STATUS_ABSENT) {
            $stats['absent']++;
            continue;
        }
        if (($mark['mark_status'] ?? '') === AssessmentEngine::STATUS_EXCUSED_ABSENT) {
            $stats['excused_absent']++;
            continue;
        }
        $value = (float) ($mark['value'] ?? 0);
        if ($value == 0.0) {
            $stats['zero']++;
        }
        if ($value > 0 && $value < $halfGrade) {
            $stats['below_half']++;
        }
    }
}

if (($_GET['export'] ?? '') === 'csv') {
    if (!$selectedWindow || $selectedClassId <= 0 || !in_array($selectedClassId, $allowedClassIds, true)) {
        $_SESSION['error_message'] = 'اختر نافذة رصد وفصلا متاحين لك قبل التصدير.';
        tam_redirect([
            'window_id' => $selectedWindowId,
            'class_id' => $selectedClassId,
        ]);
    }

    ActivityLog::log('export', 'student_mark', (int) $selectedWindow['id'], (string) $selectedWindow['window_name'], [
        'window' => $selectedWindow['window_name'],
        'component' => $selectedWindow['component_name'],
        'subject' => $selectedWindow['subject_name'],
        'grade' => $selectedWindow['grade_name'],
        'class_id' => $selectedClassId,
        'class_name' => $selectedClassName,
        'students_count' => count($students),
    ]);
    tam_send_marks_csv($students, $marks, $selectedWindow, $selectedClassName);
}

include_once '../includes/teacher_header.php';
?>

<div class="d-flex justify-content-between flex-wrap flex-md-nowrap align-items-center pt-3 pb-2 mb-3 border-bottom">
    <h1 class="h2"><i class="fas fa-pen-to-square me-2 text-primary"></i>رصد الدرجات الجديد</h1>
    <div class="btn-toolbar mb-2 mb-md-0 gap-2">
        <a href="portal.php" class="btn btn-outline-secondary shadow-sm px-3 py-2">
            <i class="fas fa-arrow-right me-2"></i>العودة للبوابة
        </a>
    </div>
</div>

<?php if (!$foundationReady): ?>
    <div class="alert alert-warning alert-dismissible fade show" role="alert">
        <i class="fas fa-exclamation-triangle me-2"></i>
        محرك الدرجات الجديد لم يتم تطبيق جداول قاعدة بياناته بعد.
        <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
    </div>
<?php endif; ?>
<?php if (!empty($success_message)): ?>
    <div class="alert alert-success alert-dismissible fade show" role="alert">
        <i class="fas fa-check-circle me-2"></i><?php echo htmlspecialchars($success_message, ENT_QUOTES, 'UTF-8'); ?>
        <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
    </div>
<?php endif; ?>
<?php if (!empty($error_message)): ?>
    <div class="alert alert-danger alert-dismissible fade show" role="alert">
        <i class="fas fa-exclamation-circle me-2"></i><?php echo htmlspecialchars($error_message, ENT_QUOTES, 'UTF-8'); ?>
        <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
    </div>
<?php endif; ?>

<style>
.stat-card { background: var(--card-gradient); border-radius: 14px; padding: 1rem 1.1rem; color: #fff; display: flex; align-items: center; gap: 0.9rem; min-height: 90px; box-shadow: 0 3px 12px rgba(0,0,0,0.12); }
.stat-card-icon { width: 48px; height: 48px; border-radius: 12px; background: rgba(255,255,255,0.18); display: flex; align-items: center; justify-content: center; flex-shrink: 0; font-size: 1.25rem; }
.stat-card-info { flex: 1; min-width: 0; }
.stat-card-number { font-size: 1.55rem; font-weight: 800; line-height: 1.2; }
.stat-card-label { font-size: 0.82rem; font-weight: 600; opacity: 0.92; margin-top: 2px; }
.mark-input { min-width: 86px; max-width: 110px; text-align: center; direction: ltr; font-weight: 700; }
.mark-empty { background: #f8fafc !important; }
.mark-zero { background: #fee2e2 !important; }
.mark-low { background: #fef3c7 !important; }
.mark-absent { background: #e0f2fe !important; }
.mark-locked { background: #e5e7eb !important; color: #4b5563; }
.mark-cell.mark-empty { background: #f8fafc !important; }
.mark-cell.mark-zero { background: #fee2e2 !important; }
.mark-cell.mark-low { background: #fef3c7 !important; }
.mark-cell.mark-absent { background: #e0f2fe !important; }
.mark-review-pending { box-shadow: inset 4px 0 0 #f59e0b; }
.mark-review-approved { box-shadow: inset 4px 0 0 #10b981; }
.mark-review-rejected { box-shadow: inset 4px 0 0 #ef4444; }
.mark-validation-message { display: none; }
.mark-validation-message.show { display: block; }
</style>

<div class="row row-cols-2 row-cols-md-3 row-cols-xl-6 g-3 mb-4">
    <div class="col">
        <div class="stat-card" style="--card-gradient: linear-gradient(135deg, #3b82f6, #2563eb);">
            <div class="stat-card-icon"><i class="fas fa-users"></i></div>
            <div class="stat-card-info">
                <div class="stat-card-number" data-live-stat="students"><?php echo number_format($stats['students']); ?></div>
                <div class="stat-card-label">طلاب القائمة</div>
            </div>
        </div>
    </div>
    <div class="col">
        <div class="stat-card" style="--card-gradient: linear-gradient(135deg, #f59e0b, #d97706);">
            <div class="stat-card-icon"><i class="fas fa-inbox"></i></div>
            <div class="stat-card-info">
                <div class="stat-card-number" data-live-stat="empty"><?php echo number_format($stats['empty']); ?></div>
                <div class="stat-card-label">خانات فارغة</div>
            </div>
        </div>
    </div>
    <div class="col">
        <div class="stat-card" style="--card-gradient: linear-gradient(135deg, #ef4444, #dc2626);">
            <div class="stat-card-icon"><i class="fas fa-0"></i></div>
            <div class="stat-card-info">
                <div class="stat-card-number" data-live-stat="zero"><?php echo number_format($stats['zero']); ?></div>
                <div class="stat-card-label">حصلوا على صفر</div>
            </div>
        </div>
    </div>
    <div class="col">
        <div class="stat-card" style="--card-gradient: linear-gradient(135deg, #f97316, #ea580c);">
            <div class="stat-card-icon"><i class="fas fa-arrow-trend-down"></i></div>
            <div class="stat-card-info">
                <div class="stat-card-number" data-live-stat="below_half"><?php echo number_format($stats['below_half']); ?></div>
                <div class="stat-card-label">أقل من النصف</div>
            </div>
        </div>
    </div>
    <div class="col">
        <div class="stat-card" style="--card-gradient: linear-gradient(135deg, #0ea5e9, #0284c7);">
            <div class="stat-card-icon"><i class="fas fa-user-slash"></i></div>
            <div class="stat-card-info">
                <div class="stat-card-number" data-live-stat="absent"><?php echo number_format($stats['absent']); ?></div>
                <div class="stat-card-label">غياب عادي</div>
            </div>
        </div>
    </div>
    <?php if ($excusedAbsenceAvailable): ?>
        <div class="col">
            <div class="stat-card" style="--card-gradient: linear-gradient(135deg, #10b981, #059669);">
                <div class="stat-card-icon"><i class="fas fa-user-check"></i></div>
                <div class="stat-card-info">
                    <div class="stat-card-number" data-live-stat="excused_absent"><?php echo number_format($stats['excused_absent']); ?></div>
                    <div class="stat-card-label">غياب بعذر</div>
                </div>
            </div>
        </div>
    <?php endif; ?>
    <div class="col">
        <div class="stat-card" style="--card-gradient: linear-gradient(135deg, #64748b, #475569);">
            <div class="stat-card-icon"><i class="fas fa-user-lock"></i></div>
            <div class="stat-card-info">
                <div class="stat-card-number"><?php echo number_format($stats['locked']); ?></div>
                <div class="stat-card-label">مقفلون</div>
            </div>
        </div>
    </div>
    <?php if ($reviewRequired): ?>
        <div class="col">
            <div class="stat-card" style="--card-gradient: linear-gradient(135deg, #f59e0b, #d97706);">
                <div class="stat-card-icon"><i class="fas fa-clock"></i></div>
                <div class="stat-card-info">
                    <div class="stat-card-number"><?php echo number_format($stats['review_pending']); ?></div>
                    <div class="stat-card-label">بانتظار المراجعة</div>
                </div>
            </div>
        </div>
        <div class="col">
            <div class="stat-card" style="--card-gradient: linear-gradient(135deg, #10b981, #059669);">
                <div class="stat-card-icon"><i class="fas fa-check"></i></div>
                <div class="stat-card-info">
                    <div class="stat-card-number"><?php echo number_format($stats['review_approved']); ?></div>
                    <div class="stat-card-label">معتمدة</div>
                </div>
            </div>
        </div>
        <div class="col">
            <div class="stat-card" style="--card-gradient: linear-gradient(135deg, #ef4444, #dc2626);">
                <div class="stat-card-icon"><i class="fas fa-xmark"></i></div>
                <div class="stat-card-info">
                    <div class="stat-card-number"><?php echo number_format($stats['review_rejected']); ?></div>
                    <div class="stat-card-label">مرفوضة</div>
                </div>
            </div>
        </div>
    <?php endif; ?>
</div>

<div class="card shadow mb-4">
    <div class="card-header bg-primary text-white">
        <div class="row align-items-center">
            <div class="col-md-4">
                <h5 class="mb-0"><i class="fas fa-filter me-2"></i>اختيار نافذة الرصد</h5>
            </div>
            <div class="col-md-8">
                <form method="get" id="windowFilterForm" class="d-flex justify-content-end align-items-center gap-2 flex-wrap">
                    <select name="subject_id" id="subjectFilter" class="form-select form-select-sm" style="width:auto; min-width:170px;">
                        <option value="">كل المواد المفتوحة</option>
                        <?php foreach ($subjectOptions as $subject): ?>
                            <option value="<?php echo (int) $subject['id']; ?>" <?php echo $filterSubjectId === (int) $subject['id'] ? 'selected' : ''; ?>>
                                <?php echo htmlspecialchars($subject['name'], ENT_QUOTES, 'UTF-8'); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                    <select name="grade_id" id="gradeFilter" class="form-select form-select-sm" style="width:auto; min-width:160px;">
                        <option value="">كل الصفوف المتاحة</option>
                        <?php foreach ($gradeOptions as $grade): ?>
                            <option value="<?php echo (int) $grade['id']; ?>" <?php echo $filterGradeId === (int) $grade['id'] ? 'selected' : ''; ?>>
                                <?php echo htmlspecialchars($grade['name'], ENT_QUOTES, 'UTF-8'); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                    <select name="window_id" id="windowSelect" class="form-select form-select-sm" style="width:auto; min-width:260px;">
                        <option value="">اختر المادة/البند المفتوح</option>
                        <?php foreach ($filteredOpenWindows as $window): ?>
                            <option value="<?php echo (int) $window['id']; ?>" <?php echo $selectedWindow && (int) $selectedWindow['id'] === (int) $window['id'] ? 'selected' : ''; ?>>
                                <?php echo htmlspecialchars($window['subject_name'] . ' - ' . $window['grade_name'] . ' - ' . $window['component_name'] . ' - ' . $window['window_name'], ENT_QUOTES, 'UTF-8'); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                    <select name="class_id" id="classFilter" class="form-select form-select-sm" style="width:auto; min-width:160px;">
                        <option value="">اختر الفصل</option>
                        <?php foreach ($availableClasses as $class): ?>
                            <option value="<?php echo (int) $class['id']; ?>" <?php echo $selectedClassId === (int) $class['id'] ? 'selected' : ''; ?>>
                                <?php echo htmlspecialchars($class['name'], ENT_QUOTES, 'UTF-8'); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                    <button type="submit" class="btn btn-light btn-sm"><i class="fas fa-search me-1"></i>عرض</button>
                </form>
            </div>
        </div>
    </div>
    <div class="card-body">
        <?php if (empty($openWindows)): ?>
            <div class="alert alert-info mb-0">
                <i class="fas fa-circle-info me-2"></i>لا توجد نوافذ رصد مفتوحة لك حاليا.
            </div>
        <?php elseif (empty($filteredOpenWindows)): ?>
            <div class="alert alert-warning mb-0">
                <i class="fas fa-filter me-2"></i>لا توجد نوافذ رصد مفتوحة تطابق المادة أو الصف المحدد.
            </div>
        <?php elseif ($selectedWindow): ?>
            <div class="row g-3">
                <div class="col-md-3"><strong>المادة:</strong> <?php echo htmlspecialchars($selectedWindow['subject_name'], ENT_QUOTES, 'UTF-8'); ?></div>
                <div class="col-md-3"><strong>البند:</strong> <?php echo htmlspecialchars($selectedWindow['component_name'], ENT_QUOTES, 'UTF-8'); ?></div>
                <div class="col-md-3"><strong>النهاية:</strong> <?php echo htmlspecialchars(AssessmentEngine::formatNumber($selectedWindow['max_grade']), ENT_QUOTES, 'UTF-8'); ?></div>
                <div class="col-md-3"><strong>الأسبوع:</strong> <?php echo htmlspecialchars($selectedWindow['week_name'] ?? '-', ENT_QUOTES, 'UTF-8'); ?></div>
            </div>
        <?php else: ?>
            <div class="alert alert-info mb-0">
                <i class="fas fa-circle-info me-2"></i>اختر مادة وصفا ثم نافذة رصد لعرض فصولك وطلابك.
            </div>
        <?php endif; ?>
    </div>
</div>

<?php if ($selectedWindow && $selectedClassId > 0): ?>
<form method="post" action="assessment_marks.php" id="importCsvForm" enctype="multipart/form-data">
    <?php echo csrfField(); ?>
    <input type="hidden" name="action" value="import_marks_csv">
    <input type="hidden" name="window_id" value="<?php echo (int) $selectedWindow['id']; ?>">
    <input type="hidden" name="class_id" value="<?php echo (int) $selectedClassId; ?>">
</form>
<form method="post" action="assessment_marks.php" id="marksForm">
    <?php echo csrfField(); ?>
    <input type="hidden" name="action" value="save_marks">
    <input type="hidden" name="window_id" value="<?php echo (int) $selectedWindow['id']; ?>">
    <input type="hidden" name="class_id" value="<?php echo (int) $selectedClassId; ?>">
    <input type="hidden" name="bulk_action" id="bulkActionInput" value="">

    <div class="card shadow">
        <div class="card-header bg-primary text-white">
            <div class="row align-items-center">
                <div class="col-md-4">
                    <h5 class="mb-0"><i class="fas fa-table me-2"></i>قائمة الرصد <span class="badge bg-light text-dark ms-2"><?php echo count($students); ?></span></h5>
                </div>
                <div class="col-md-8">
                    <div class="d-flex justify-content-end align-items-center gap-2 flex-wrap">
                        <a href="assessment_marks.php?<?php echo htmlspecialchars(http_build_query(['subject_id' => $filterSubjectId, 'grade_id' => $filterGradeId, 'window_id' => (int) $selectedWindow['id'], 'class_id' => (int) $selectedClassId, 'export' => 'csv']), ENT_QUOTES, 'UTF-8'); ?>" class="btn btn-outline-light btn-sm">
                            <i class="fas fa-file-csv me-1"></i>تصدير CSV
                        </a>
                        <input type="file" name="csv_file" form="importCsvForm" class="form-control form-control-sm" accept=".csv,text/csv" required style="width:auto; max-width:210px;">
                        <button type="submit" form="importCsvForm" class="btn btn-light btn-sm">
                            <i class="fas fa-file-import me-1"></i>استيراد CSV
                        </button>
                        <input type="text" id="bulkMarkValue" class="form-control form-control-sm" placeholder="درجة للعمود" style="width:auto; min-width:120px;" inputmode="decimal">
                        <button type="button" id="fillColumnBtn" class="btn btn-light btn-sm">
                            <i class="fas fa-fill-drip me-1"></i>ملء العمود
                        </button>
                        <?php if ($absenceAvailable): ?>
                            <button type="button" id="fillAbsentBtn" class="btn btn-light btn-sm">
                                <i class="fas fa-user-slash me-1"></i>غياب للجميع
                            </button>
                        <?php endif; ?>
                        <?php if ($excusedAbsenceAvailable): ?>
                            <button type="button" id="fillExcusedAbsentBtn" class="btn btn-light btn-sm">
                                <i class="fas fa-user-check me-1"></i>غياب بعذر للجميع
                            </button>
                        <?php endif; ?>
                        <button type="submit" class="btn btn-success btn-sm">
                            <i class="fas fa-save me-1"></i>حفظ الدرجات
                        </button>
                    </div>
                </div>
            </div>
        </div>
        <div class="card-body">
            <div id="markValidationMessage" class="alert alert-danger mark-validation-message" role="alert">
                <i class="fas fa-triangle-exclamation me-2"></i>
                <span></span>
            </div>
            <div class="table-responsive">
                <table class="table table-hover table-striped align-middle">
                    <thead>
                        <tr>
                            <th style="width:70px;">#</th>
                            <th>الطالب</th>
                            <th style="width:140px;">الدرجة / الحالة</th>
                            <th>ملاحظة</th>
                            <?php if ($reviewRequired): ?>
                                <th style="width:150px;">المراجعة</th>
                            <?php endif; ?>
                            <?php if ($canDeleteSelectedMarks): ?>
                                <th style="width:90px;">حذف</th>
                            <?php endif; ?>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (empty($students)): ?>
                            <tr>
                                <td colspan="<?php echo 4 + ($reviewRequired ? 1 : 0) + ($canDeleteSelectedMarks ? 1 : 0); ?>" class="text-center text-muted py-4">لا يوجد طلاب في هذا الفصل.</td>
                            </tr>
                        <?php else: ?>
                            <?php foreach ($students as $index => $student): ?>
                                <?php
                                $mark = $marks[(int) $student['id']] ?? null;
                                $isLockedStudent = isset($lockedStudentMap[(int) $student['id']]);
                                $displayValue = tam_mark_display($mark);
                                $reviewStatus = (string) ($mark['review_status'] ?? 'not_required');
                                $reviewLabels = [
                                    'pending' => ['بانتظار المراجعة', 'bg-warning text-dark'],
                                    'approved' => ['معتمدة', 'bg-success'],
                                    'rejected' => ['مرفوضة', 'bg-danger'],
                                    'not_required' => ['لا تتطلب مراجعة', 'bg-secondary'],
                                ];
                                $reviewLabel = $reviewLabels[$reviewStatus] ?? [$reviewStatus, 'bg-secondary'];
                                $rowClass = $isLockedStudent ? 'mark-locked' : 'mark-empty';
                                if (!$isLockedStudent && $mark) {
                                    if (($mark['mark_status'] ?? '') === AssessmentEngine::STATUS_ABSENT || ($mark['mark_status'] ?? '') === AssessmentEngine::STATUS_EXCUSED_ABSENT) {
                                        $rowClass = 'mark-absent';
                                    } elseif (($mark['mark_status'] ?? '') === AssessmentEngine::STATUS_PRESENT) {
                                        $value = (float) ($mark['value'] ?? 0);
                                        if ($value == 0.0) {
                                            $rowClass = 'mark-zero';
                                        } elseif ($value < ((float) $selectedWindow['max_grade'] / 2)) {
                                            $rowClass = 'mark-low';
                                        } else {
                                            $rowClass = '';
                                        }
                                    }
                                    if ($reviewRequired && in_array($reviewStatus, ['pending', 'approved', 'rejected'], true)) {
                                        $rowClass = trim($rowClass . ' mark-review-' . $reviewStatus);
                                    }
                                }
                                    $baseRowClass = trim(str_replace(['mark-review-pending', 'mark-review-approved', 'mark-review-rejected'], '', $rowClass));
                                ?>
                                <tr class="<?php echo $rowClass; ?>" data-review-class="<?php echo $reviewRequired && in_array($reviewStatus, ['pending', 'approved', 'rejected'], true) ? 'mark-review-' . htmlspecialchars($reviewStatus, ENT_QUOTES, 'UTF-8') : ''; ?>">
                                    <td><?php echo $index + 1; ?></td>
                                    <td>
                                        <strong><?php echo htmlspecialchars($student['name'], ENT_QUOTES, 'UTF-8'); ?></strong>
                                        <?php if ($isLockedStudent): ?>
                                            <span class="badge bg-secondary ms-1">مقفل</span>
                                        <?php endif; ?>
                                        <div class="small text-muted"><?php echo htmlspecialchars($student['username'] ?? '', ENT_QUOTES, 'UTF-8'); ?></div>
                                    </td>
                                    <td class="mark-cell <?php echo htmlspecialchars($baseRowClass, ENT_QUOTES, 'UTF-8'); ?>">
                                        <input type="text"
                                               name="marks[<?php echo (int) $student['id']; ?>]"
                                               class="form-control form-control-sm mark-input"
                                               value="<?php echo htmlspecialchars($displayValue, ENT_QUOTES, 'UTF-8'); ?>"
                                               data-max="<?php echo htmlspecialchars((string) $selectedWindow['max_grade'], ENT_QUOTES, 'UTF-8'); ?>"
                                               data-absence="<?php echo $absenceAvailable ? '1' : '0'; ?>"
                                               data-excused="<?php echo $excusedAbsenceAvailable ? '1' : '0'; ?>"
                                               data-student-name="<?php echo htmlspecialchars($student['name'], ENT_QUOTES, 'UTF-8'); ?>"
                                               title="<?php echo $excusedAbsenceAvailable ? 'اكتب درجة أو غ أو abs أو غ.ع للغياب بعذر' : ($absenceAvailable ? 'اكتب درجة أو غ أو abs' : 'اكتب درجة فقط'); ?>"
                                               inputmode="decimal"
                                               <?php echo $isLockedStudent ? 'disabled' : ''; ?>>
                                    </td>
                                    <td>
                                        <input type="text"
                                               name="notes[<?php echo (int) $student['id']; ?>]"
                                               class="form-control form-control-sm"
                                               maxlength="500"
                                               value="<?php echo htmlspecialchars($mark['note'] ?? '', ENT_QUOTES, 'UTF-8'); ?>"
                                               <?php echo $isLockedStudent ? 'disabled' : ''; ?>>
                                    </td>
                                    <?php if ($reviewRequired): ?>
                                        <td>
                                            <?php if ($mark): ?>
                                                <span class="badge <?php echo $reviewLabel[1]; ?>"><?php echo htmlspecialchars($reviewLabel[0], ENT_QUOTES, 'UTF-8'); ?></span>
                                                <?php if (!empty($mark['review_note'])): ?>
                                                    <div class="small text-muted mt-1"><?php echo htmlspecialchars($mark['review_note'], ENT_QUOTES, 'UTF-8'); ?></div>
                                                <?php endif; ?>
                                            <?php else: ?>
                                                <span class="text-muted">-</span>
                                            <?php endif; ?>
                                        </td>
                                    <?php endif; ?>
                                    <?php if ($canDeleteSelectedMarks): ?>
                                        <td class="text-center">
                                            <?php if ($mark && !$isLockedStudent): ?>
                                                <input class="form-check-input" type="checkbox" name="delete_marks[<?php echo (int) $student['id']; ?>]" value="1" title="حذف الدرجة المسجلة">
                                            <?php else: ?>
                                                <span class="text-muted">-</span>
                                            <?php endif; ?>
                                        </td>
                                    <?php endif; ?>
                                </tr>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</form>

<div class="modal fade" id="confirmDeleteMarksModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header bg-danger text-white">
                <h5 class="modal-title"><i class="fas fa-trash me-2"></i>تأكيد حذف الدرجات</h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body">
                <div class="text-center mb-3">
                    <i class="fas fa-triangle-exclamation text-danger" style="font-size: 3rem;"></i>
                </div>
                <p class="text-center mb-2">تم تحديد درجات للحذف من قائمة الرصد.</p>
                <div class="alert alert-warning mb-0">
                    <i class="fas fa-info-circle me-2"></i>
                    سيتم تسجيل عملية الحذف في سجل مراجعة الدرجات قبل تنفيذها.
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-danger" data-bs-dismiss="modal">
                    <i class="fas fa-times me-1"></i>إلغاء
                </button>
                <button type="button" class="btn btn-danger" id="confirmDeleteMarksBtn">
                    <i class="fas fa-trash me-1"></i>تأكيد الحذف والحفظ
                </button>
            </div>
        </div>
    </div>
</div>
<?php endif; ?>

<script>
document.addEventListener('DOMContentLoaded', function () {
    const windowFilterForm = document.getElementById('windowFilterForm');
    const subjectFilter = document.getElementById('subjectFilter');
    const gradeFilter = document.getElementById('gradeFilter');
    const windowSelect = document.getElementById('windowSelect');
    const classFilter = document.getElementById('classFilter');
    function submitWindowFilters(resetWindow, resetClass) {
        if (resetWindow && windowSelect) {
            windowSelect.value = '';
        }
        if (resetClass && classFilter) {
            classFilter.value = '';
        }
        if (windowFilterForm) {
            windowFilterForm.submit();
        }
    }
    if (subjectFilter) {
        subjectFilter.addEventListener('change', function () {
            submitWindowFilters(true, true);
        });
    }
    if (gradeFilter) {
        gradeFilter.addEventListener('change', function () {
            submitWindowFilters(true, true);
        });
    }
    if (windowSelect) {
        windowSelect.addEventListener('change', function () {
            submitWindowFilters(false, true);
        });
    }

    const validationMessage = document.getElementById('markValidationMessage');

    function showMarkValidationMessage(message) {
        if (!validationMessage) return;
        validationMessage.querySelector('span').textContent = message || '';
        validationMessage.classList.toggle('show', !!message);
    }

    function validateMarkValue(value, max, absenceEnabled, excusedEnabled) {
        const trimmed = value.trim();
        const maxValue = parseFloat(max);
        if (trimmed === '') return { valid: true, message: '' };
        if (trimmed === 'غ' || trimmed.toLowerCase() === 'abs') {
            return absenceEnabled
                ? { valid: true, message: '' }
                : { valid: false, message: 'هذا البند لا يسمح بتسجيل الغياب بدلا من الدرجة.' };
        }
        const lowered = trimmed.toLowerCase();
        if (['غ.ع', 'غ ع', 'excused', 'excused_abs', 'abs_excused'].includes(lowered)) {
            return excusedEnabled
                ? { valid: true, message: '' }
                : { valid: false, message: 'الغياب بعذر غير مفعل لهذا البند.' };
        }
        if (!/^\d+(\.\d{1,2})?$/.test(trimmed)) {
            const allowed = excusedEnabled
                ? 'اكتب رقما أو غ أو abs أو غ.ع فقط.'
                : (absenceEnabled ? 'اكتب رقما أو غ أو abs فقط.' : 'اكتب رقما فقط.');
            return { valid: false, message: allowed };
        }
        if (parseFloat(trimmed) > maxValue) {
            return { valid: false, message: 'الدرجة لا يمكن أن تتجاوز ' + max + '.' };
        }
        return { valid: true, message: '' };
    }

    function isAllowedMark(value, max, absenceEnabled, excusedEnabled) {
        return validateMarkValue(value, max, absenceEnabled, excusedEnabled).valid;
    }

    function classifyMarkValue(input) {
        const value = (input.value || '').trim();
        const lowered = value.toLowerCase();
        const maxValue = parseFloat(input.dataset.max || '0');
        if (value === '') return 'empty';
        if (value === 'غ' || lowered === 'abs') return 'absent';
        if (['غ.ع', 'غ ع', 'excused', 'excused_abs', 'abs_excused'].includes(lowered)) return 'excused_absent';
        const numericValue = parseFloat(value);
        if (!Number.isFinite(numericValue)) return 'invalid';
        if (numericValue === 0) return 'zero';
        if (maxValue > 0 && numericValue < (maxValue / 2)) return 'below_half';
        return 'normal';
    }

    function baseClassForCategory(category) {
        if (category === 'empty') return 'mark-empty';
        if (category === 'zero') return 'mark-zero';
        if (category === 'below_half') return 'mark-low';
        if (category === 'absent' || category === 'excused_absent') return 'mark-absent';
        return '';
    }

    function updateMarkVisual(input) {
        if (input.disabled) return;
        const row = input.closest('tr');
        const cell = input.closest('.mark-cell');
        const category = classifyMarkValue(input);
        const baseClasses = ['mark-empty', 'mark-zero', 'mark-low', 'mark-absent'];
        const nextClass = baseClassForCategory(category);
        if (row) {
            row.classList.remove(...baseClasses);
            if (nextClass) row.classList.add(nextClass);
            const reviewClass = row.dataset.reviewClass || '';
            if (reviewClass) row.classList.add(reviewClass);
        }
        if (cell) {
            cell.classList.remove(...baseClasses);
            if (nextClass) cell.classList.add(nextClass);
        }
    }

    function updateLiveStats() {
        const totals = { empty: 0, zero: 0, below_half: 0, absent: 0, excused_absent: 0 };
        document.querySelectorAll('.mark-input').forEach(function (input) {
            const category = classifyMarkValue(input);
            if (Object.prototype.hasOwnProperty.call(totals, category)) {
                totals[category]++;
            }
        });
        Object.keys(totals).forEach(function (key) {
            const stat = document.querySelector('[data-live-stat="' + key + '"]');
            if (stat) {
                stat.textContent = totals[key].toLocaleString('ar-EG');
            }
        });
    }

    document.querySelectorAll('.mark-input').forEach(function (input) {
        input.addEventListener('input', function () {
            const result = validateMarkValue(this.value, this.dataset.max, this.dataset.absence === '1', this.dataset.excused === '1');
            this.classList.toggle('is-invalid', !result.valid);
            updateMarkVisual(this);
            updateLiveStats();
            showMarkValidationMessage(result.valid ? '' : ((this.dataset.studentName || 'طالب') + ': ' + result.message));
        });
    });
    updateLiveStats();

    const fillBtn = document.getElementById('fillColumnBtn');
    const fillAbsentBtn = document.getElementById('fillAbsentBtn');
    const bulkInput = document.getElementById('bulkMarkValue');
    const bulkActionInput = document.getElementById('bulkActionInput');
    function setBulkAction(action) {
        if (bulkActionInput) {
            bulkActionInput.value = action || '';
        }
    }
    function fillAvailableMarks(value) {
        let invalidMessage = '';
        document.querySelectorAll('.mark-input:not([disabled])').forEach(function (input) {
            const result = validateMarkValue(value, input.dataset.max, input.dataset.absence === '1', input.dataset.excused === '1');
            if (result.valid) {
                input.value = value;
                input.classList.remove('is-invalid');
                updateMarkVisual(input);
            } else {
                if (!invalidMessage) {
                    invalidMessage = result.message;
                }
                input.classList.add('is-invalid');
            }
        });
        updateLiveStats();
        showMarkValidationMessage(invalidMessage);
        return invalidMessage === '';
    }
    if (fillBtn && bulkInput) {
        fillBtn.addEventListener('click', function () {
            const value = bulkInput.value.trim();
            const filled = fillAvailableMarks(value);
            bulkInput.classList.toggle('is-invalid', !filled);
            setBulkAction(filled ? 'fill_column' : '');
        });
        bulkInput.addEventListener('input', function () {
            this.classList.remove('is-invalid');
            showMarkValidationMessage('');
            setBulkAction('');
        });
    }
    if (fillAbsentBtn) {
        fillAbsentBtn.addEventListener('click', function () {
            setBulkAction(fillAvailableMarks('غ') ? 'absent_all' : '');
        });
    }
    const fillExcusedAbsentBtn = document.getElementById('fillExcusedAbsentBtn');
    if (fillExcusedAbsentBtn) {
        fillExcusedAbsentBtn.addEventListener('click', function () {
            setBulkAction(fillAvailableMarks('غ.ع') ? 'excused_absent_all' : '');
        });
    }

    const marksForm = document.getElementById('marksForm');
    const confirmDeleteBtn = document.getElementById('confirmDeleteMarksBtn');
    const deleteModalEl = document.getElementById('confirmDeleteMarksModal');
    if (marksForm) {
        marksForm.addEventListener('submit', function (event) {
            let firstInvalid = null;
            marksForm.querySelectorAll('.mark-input:not([disabled])').forEach(function (input) {
                const result = validateMarkValue(input.value, input.dataset.max, input.dataset.absence === '1', input.dataset.excused === '1');
                input.classList.toggle('is-invalid', !result.valid);
                updateMarkVisual(input);
                if (!result.valid && !firstInvalid) {
                    firstInvalid = input;
                    showMarkValidationMessage((input.dataset.studentName || 'طالب') + ': ' + result.message);
                }
            });
            updateLiveStats();
            if (firstInvalid) {
                event.preventDefault();
                firstInvalid.focus();
            } else {
                showMarkValidationMessage('');
            }
        });
    }
    if (marksForm && confirmDeleteBtn && deleteModalEl) {
        const deleteModal = new bootstrap.Modal(deleteModalEl);
        marksForm.addEventListener('submit', function (event) {
            if (event.defaultPrevented) {
                return;
            }
            const hasDeletes = marksForm.querySelector('input[name^="delete_marks"]:checked');
            if (hasDeletes && marksForm.dataset.deleteConfirmed !== '1') {
                event.preventDefault();
                deleteModal.show();
            }
        });
        confirmDeleteBtn.addEventListener('click', function () {
            marksForm.dataset.deleteConfirmed = '1';
            deleteModal.hide();
            marksForm.requestSubmit();
        });
    }
});
</script>

<?php include_once '../includes/teacher_footer.php'; ?>
