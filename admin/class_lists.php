<?php
/**
 * قوائم الفصول - عرض توزيع الطلاب على الفصول
 * التصميم: جدول ملخص الفصول → عند اختيار فصل تظهر قائمة طلابه
 */
$page_title = "قوائم الفصول";
$custom_page_title = true;

require_once '../config/database.php';
require_once '../classes/classroom.php';
require_once '../classes/utilities.php';
require_once '../classes/AcademicYear.php';
require_once '../classes/UndoManager.php';
require_once '../classes/ScopedStaffPortalContext.php';
require_once '../classes/StudentChangeRequestService.php';
require_once '../classes/Presentation/ClassLists/ClassListExportSupport.php';
require_once '../classes/Presentation/ClassLists/ClassListStudentQuery.php';
require_once '../includes/session_config.php';
require_once '../includes/print_template.php';
Utilities::validateSession('admin');

$database = new Database();
$db = $database->getConnection();
$classListStudentQuery = new ClassListStudentQuery($db);
$currentAcademicYearId = AcademicYear::currentId($db);
$portalContext = new ScopedStaffPortalContext($db, $currentAcademicYearId);
$allowedClassIds = $portalContext->allowedClassIds();
$isSpecialistPortal = $portalContext->role() === 'specialist';
$studentChangeRequestService = StudentChangeRequestService::create($db);
$studentCommandService = \EduCore\Modules\Students\StudentProfileCommandService::fromDatabase($db);

function classLists_toArabicNumerals($number) {
    $indian_numerals = ['٠', '١', '٢', '٣', '٤', '٥', '٦', '٧', '٨', '٩'];
    return str_replace(range(0, 9), $indian_numerals, $number);
}

function classLists_getExportColumns($isCustomTab, $showSerialAr, $showCode, $showNameAr, $showClassAr, $showGender, $showGenderEn, $showClassEn, $showNameEn, $showCodeEn, $showSerialEn) {
    $cols = [];
    if ($showSerialAr) {
        $cols[] = [
            'header' => '#',
            'value' => function($st, $idx) { return classLists_toArabicNumerals($idx + 1); }
        ];
    }
    if ($showCode) {
        $cols[] = [
            'header' => 'الكود',
            'value' => function($st) { return $st['student_code'] ?? '-'; }
        ];
    }
    if ($showNameAr) {
        $cols[] = [
            'header' => 'اسم الطالب',
            'value' => function($st) { return $st['name']; }
        ];
    }
    if ($isCustomTab && $showClassAr) {
        $cols[] = [
            'header' => 'اسم الفصل',
            'value' => function($st) { return $st['class_name_ar'] ?? $st['class_name'] ?? ''; }
        ];
    }
    if ($showGender) {
        $cols[] = [
            'header' => 'النوع',
            'value' => function($st) { return ($st['gender'] ?? '') === 'male' ? 'ذكر' : (($st['gender'] ?? '') === 'female' ? 'أنثى' : '-'); }
        ];
    }
    if ($showGenderEn) {
        $cols[] = [
            'header' => 'Gender',
            'value' => function($st) { return ($st['gender'] ?? '') === 'male' ? 'Male' : (($st['gender'] ?? '') === 'female' ? 'Female' : '-'); }
        ];
    }
    if ($isCustomTab && $showClassEn) {
        $cols[] = [
            'header' => 'Class',
            'value' => function($st) { return $st['class_name_en'] ?? ''; }
        ];
    }
    if ($showNameEn) {
        $cols[] = [
            'header' => 'Student Name',
            'value' => function($st) { return $st['name_en'] ?? '-'; }
        ];
    }
    if ($showCodeEn) {
        $cols[] = [
            'header' => 'Code',
            'value' => function($st) { return $st['student_code'] ?? '-'; }
        ];
    }
    if ($showSerialEn) {
        $cols[] = [
            'header' => '#',
            'value' => function($st, $idx) { return $idx + 1; }
        ];
    }

    if (empty($cols)) {
        $cols[] = [
            'header' => 'اسم الطالب',
            'value' => function($st) { return $st['name']; }
        ];
    }
    return $cols;
}

// ===== AJAX: تغيير فصل الطالب =====
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['ajax_change_class'])) {
    header('Content-Type: application/json; charset=utf-8');

    // CSRF check
    $csrf = $_POST['csrf_token'] ?? '';
    if (empty($csrf) || !hash_equals($_SESSION['csrf_token'] ?? '', $csrf)) {
        echo json_encode(['success' => false, 'message' => 'رمز الحماية غير صالح']);
        exit;
    }

    $studentIdStr = trim($_POST['student_id'] ?? '');
    $newClassId = !empty($_POST['new_class_id']) ? (int)$_POST['new_class_id'] : null;

    if (empty($studentIdStr) || !$newClassId) {
        echo json_encode(['success' => false, 'message' => 'بيانات غير مكتملة']);
        exit;
    }

    $studentIds = array_values(array_unique(array_filter(array_map('intval', explode(',', $studentIdStr)))));
    if (empty($studentIds)) {
        echo json_encode(['success' => false, 'message' => 'لم يتم تحديد أي طلاب']);
        exit;
    }

    // جلب معلومات الفصل الجديد والصف الدراسي المرتبط به
    $clsStmt = $db->prepare("SELECT name, grade_id FROM classes WHERE id = ?");
    $clsStmt->execute([$newClassId]);
    $newClassData = $clsStmt->fetch(PDO::FETCH_ASSOC);
    if (!$newClassData) {
        http_response_code(422);
        echo json_encode(['success' => false, 'message' => 'الفصل الجديد غير موجود أو لم يعد متاحاً.'], JSON_UNESCAPED_UNICODE);
        exit;
    }
    $newClassName = $newClassData['name'] ?? '';

    if ($isSpecialistPortal) {
        $db->beginTransaction();
        try {
            $reason = trim((string) ($_POST['transfer_reason'] ?? ''));
            foreach ($studentIds as $studentId) {
                $studentChangeRequestService->submitClassTransfer(
                    $portalContext->userId(),
                    $currentAcademicYearId,
                    $studentId,
                    $newClassId,
                    $reason
                );
            }
            $db->commit();
            $requestCount = count($studentIds);
            echo json_encode([
                'success' => true,
                'pending' => true,
                'message' => "تم إرسال طلب نقل {$requestCount} طالب/طلاب إلى العمليات المعلقة لمراجعة الإدارة.",
                'new_class_name' => $newClassName,
            ], JSON_UNESCAPED_UNICODE);
        } catch (Throwable $e) {
            if ($db->inTransaction()) {
                $db->rollBack();
            }
            http_response_code(422);
            if ($e instanceof PDOException) {
                error_log('Specialist class transfer request failed: ' . $e->getMessage());
            }
            echo json_encode([
                'success' => false,
                'message' => $e instanceof PDOException
                    ? 'تعذر حفظ طلب النقل. لم تُحفظ أي تغييرات جزئية.'
                    : $e->getMessage(),
            ], JSON_UNESCAPED_UNICODE);
        }
        exit;
    }

    $db->beginTransaction();
    try {
        $transferredCount = 0;
        $undoBatchId = UndoManager::newBatchId();
        $currentClassStmt = $db->prepare("SELECT class_id FROM student_enrollments
            WHERE student_id = ? AND academic_year_id = ? AND enrollment_status = 'enrolled'
            LIMIT 1 FOR UPDATE");
        $transferReason = trim((string) ($_POST['transfer_reason'] ?? ''));
        foreach ($studentIds as $studentId) {
            $currentClassStmt->execute([$studentId, $currentAcademicYearId]);
            $oldClassId = (int) $currentClassStmt->fetchColumn();
            if ($oldClassId <= 0) {
                throw new RuntimeException('لا يملك أحد الطلاب المحددين قيداً نشطاً في العام الدراسي الحالي.');
            }
            if ($oldClassId === $newClassId) continue;

            $studentCommandService->applyClassTransfer(
                $studentId,
                $currentAcademicYearId,
                $oldClassId,
                $newClassId,
                $transferReason,
                (int) $_SESSION['user_id'],
                $undoBatchId
            );

            $transferredCount++;
        }

        if ($transferredCount === 0) {
            throw new Exception('لا يوجد طلاب يمكن نقلهم (قد يكون الطلاب بالفعل في هذا الفصل أو غير موجودين)');
        }

        $db->commit();
        echo json_encode(['success' => true, 'message' => "تم نقل الطلاب بنجاح (عدد: {$transferredCount})", 'new_class_name' => $newClassName]);
    } catch (Throwable $e) {
        if ($db->inTransaction()) {
            $db->rollBack();
        }
        if ($e instanceof PDOException) {
            error_log('Admin bulk class transfer failed: ' . $e->getMessage());
        }
        http_response_code(422);
        echo json_encode([
            'success' => false,
            'message' => $e instanceof PDOException
                ? 'تعذر نقل الطلاب. تم التراجع عن الدفعة كاملة ولم تُحفظ تغييرات جزئية.'
                : 'تعذر نقل الطلاب: ' . $e->getMessage(),
        ], JSON_UNESCAPED_UNICODE);
    }
    exit;
}

// ===== AJAX: جلب طلاب بقائمة معينة من المعرفات =====
if (isset($_GET['ajax_get_students_by_ids'])) {
    header('Content-Type: application/json; charset=utf-8');
    $ids = array_filter(array_map('intval', explode(',', $_GET['ids'] ?? '')));
    $sortOrder = $_GET['sort_order'] ?? 'ar_alpha';
    $students = [];
    if (!empty($ids)) {
        foreach ($ids as $studentId) $portalContext->assertStudentAllowed((int) $studentId);
        $students = classLists_fetchCustomStudents($db, $ids, $sortOrder);
    }
    echo json_encode($students);
    exit;
}

// ===== AJAX: جلب فصول صف معين =====
if (isset($_GET['ajax_get_grade_classes'])) {
    header('Content-Type: application/json; charset=utf-8');
    $gradeId = (int)($_GET['grade_id'] ?? 0);
    if (!$gradeId) {
        echo json_encode([]);
        exit;
    }
    $yearFilter = $currentAcademicYearId > 0 ? "AND (c.academic_year_id = ? OR c.academic_year_id IS NULL)" : "";
    $stmt = $db->prepare("SELECT c.id, c.name, c.room_location FROM classes c WHERE c.grade_id = ? AND c.status = 'active' {$yearFilter} ORDER BY c.display_order, c.name");
    if ($currentAcademicYearId > 0) {
        $stmt->execute([$gradeId, $currentAcademicYearId]);
    } else {
        $stmt->execute([$gradeId]);
    }
    $gradeClasses = $stmt->fetchAll(PDO::FETCH_ASSOC);
    if ($allowedClassIds !== null) {
        $allowedMap = array_fill_keys($allowedClassIds, true);
        $gradeClasses = array_values(array_filter($gradeClasses, static fn(array $class): bool => isset($allowedMap[(int) $class['id']])));
    }
    echo json_encode($gradeClasses);
    exit;
}

// ===== AJAX: جلب طلاب فصل معين =====
if (isset($_GET['ajax_get_class_students'])) {
    header('Content-Type: application/json; charset=utf-8');
    $classId = (int)($_GET['class_id'] ?? 0);
    if (!$classId) {
        echo json_encode(['success' => false, 'message' => 'معرف الفصل مطلوب']);
        exit;
    }
    $portalContext->assertClassAllowed($classId);

    $sortOrder = $_GET['sort_order'] ?? 'ar_alpha';
    if (!in_array($sortOrder, ['ar_alpha', 'en_alpha', 'ar_female_first', 'ar_male_first', 'en_female_first', 'en_male_first'])) {
        $sortOrder = 'ar_alpha';
    }

    $orderBy = "u.name ASC";
    if ($sortOrder === 'en_alpha') {
        $orderBy = "CASE WHEN name_en IS NULL OR name_en = '' THEN 1 ELSE 0 END, name_en ASC, u.name ASC";
    } elseif ($sortOrder === 'ar_female_first') {
        $orderBy = "CASE WHEN sp.gender = 'female' THEN 0 ELSE 1 END, u.name ASC";
    } elseif ($sortOrder === 'ar_male_first') {
        $orderBy = "CASE WHEN sp.gender = 'male' THEN 0 ELSE 1 END, u.name ASC";
    } elseif ($sortOrder === 'en_female_first') {
        $orderBy = "CASE WHEN sp.gender = 'female' THEN 0 ELSE 1 END, CASE WHEN name_en IS NULL OR name_en = '' THEN 1 ELSE 0 END, name_en ASC, u.name ASC";
    } elseif ($sortOrder === 'en_male_first') {
        $orderBy = "CASE WHEN sp.gender = 'male' THEN 0 ELSE 1 END, CASE WHEN name_en IS NULL OR name_en = '' THEN 1 ELSE 0 END, name_en ASC, u.name ASC";
    }

    if ($currentAcademicYearId > 0) {
        $stmt = $db->prepare("
            SELECT u.id, u.name, sp.student_code, sp.gender,
                   CONCAT_WS(' ', sp.first_name_en, sp.second_name_en, sp.third_name_en, sp.fourth_name_en, sp.family_name_en) as name_en,
                   c.name as class_name, c.grade_id, g.grade_name, s.stage_name, s.id as stage_id
            FROM users u
            JOIN student_enrollments se ON se.student_id = u.id
                AND se.academic_year_id = ? AND se.enrollment_status = 'enrolled'
            LEFT JOIN student_profiles sp ON u.id = sp.user_id
            LEFT JOIN classes c ON c.id = se.class_id
            LEFT JOIN grades g ON c.grade_id = g.id
            LEFT JOIN stages s ON g.stage_id = s.id
            WHERE se.class_id = ? AND u.role = 'student' AND u.status = 'active'
            ORDER BY $orderBy
        ");
        $stmt->execute([$currentAcademicYearId, $classId]);
    } else {
        $stmt = $db->prepare("
            SELECT u.id, u.name, sp.student_code, sp.gender,
                   CONCAT_WS(' ', sp.first_name_en, sp.second_name_en, sp.third_name_en, sp.fourth_name_en, sp.family_name_en) as name_en,
                   c.name as class_name, c.grade_id, g.grade_name, s.stage_name, s.id as stage_id
            FROM users u
            LEFT JOIN student_profiles sp ON u.id = sp.user_id
            LEFT JOIN classes c ON u.class_id = c.id
            LEFT JOIN grades g ON c.grade_id = g.id
            LEFT JOIN stages s ON g.stage_id = s.id
            WHERE u.class_id = ? AND u.role = 'student' AND u.status = 'active' AND NOT EXISTS (SELECT 1 FROM student_profiles esp WHERE esp.user_id=u.id AND esp.enrollment_status <> 'enrolled')
            ORDER BY $orderBy
        ");
        $stmt->execute([$classId]);
    }
    $students = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // عدّ طلاب الفصل (بالجنس) حسب العام الحالي
    $scSubq = $currentAcademicYearId > 0
        ? "(SELECT COUNT(*) FROM student_enrollments se JOIN users su ON su.id=se.student_id WHERE se.class_id=c.id AND se.academic_year_id={$currentAcademicYearId} AND se.enrollment_status='enrolled' AND su.role='student' AND su.status='active')"
        : "(SELECT COUNT(*) FROM users su WHERE su.class_id = c.id AND su.role = 'student' AND su.status = 'active' AND NOT EXISTS (SELECT 1 FROM student_profiles esp WHERE esp.user_id=su.id AND esp.enrollment_status <> 'enrolled'))";
    $maleSubq = $currentAcademicYearId > 0
        ? "(SELECT COUNT(*) FROM student_enrollments se JOIN users u2 ON u2.id=se.student_id LEFT JOIN student_profiles sp2 ON u2.id = sp2.user_id WHERE se.class_id=c.id AND se.academic_year_id={$currentAcademicYearId} AND se.enrollment_status='enrolled' AND u2.role='student' AND u2.status='active' AND COALESCE(sp2.enrollment_status,'enrolled')='enrolled' AND sp2.gender = 'male')"
        : "(SELECT COUNT(*) FROM users u2 LEFT JOIN student_profiles sp2 ON u2.id = sp2.user_id WHERE u2.class_id = c.id AND u2.role = 'student' AND u2.status = 'active' AND COALESCE(sp2.enrollment_status,'enrolled')='enrolled' AND sp2.gender = 'male')";
    $femaleSubq = $currentAcademicYearId > 0
        ? "(SELECT COUNT(*) FROM student_enrollments se JOIN users u2 ON u2.id=se.student_id LEFT JOIN student_profiles sp2 ON u2.id = sp2.user_id WHERE se.class_id=c.id AND se.academic_year_id={$currentAcademicYearId} AND se.enrollment_status='enrolled' AND u2.role='student' AND u2.status='active' AND COALESCE(sp2.enrollment_status,'enrolled')='enrolled' AND sp2.gender = 'female')"
        : "(SELECT COUNT(*) FROM users u2 LEFT JOIN student_profiles sp2 ON u2.id = sp2.user_id WHERE u2.class_id = c.id AND u2.role = 'student' AND u2.status = 'active' AND COALESCE(sp2.enrollment_status,'enrolled')='enrolled' AND sp2.gender = 'female')";

    $clsStmt = $db->prepare("
        SELECT c.id, c.name, c.grade_id, c.room_location, g.grade_name, s.stage_name, s.id as sid,
            {$scSubq} as student_count,
            {$maleSubq} as male_count,
            {$femaleSubq} as female_count
        FROM classes c
        LEFT JOIN grades g ON c.grade_id = g.id
        LEFT JOIN stages s ON g.stage_id = s.id
        WHERE c.id = ?
    ");
    $clsStmt->execute([$classId]);
    $classData = $clsStmt->fetch(PDO::FETCH_ASSOC);

    echo json_encode(['success' => true, 'students' => $students, 'classData' => $classData]);
    exit;
}

// جلب المراحل والصفوف والفصول مع الإحصائيات
$stages = $db->query("SELECT id, stage_name FROM stages ORDER BY id")->fetchAll(PDO::FETCH_ASSOC);
$grades = $db->query("SELECT id, grade_name, stage_id FROM grades ORDER BY stage_id, id")->fetchAll(PDO::FETCH_ASSOC);

// استعلامات الفرع للعدّ حسب العام الحالي (تُعاد استخدامها في عدة استعلامات)
$_scSub = $currentAcademicYearId > 0
    ? "(SELECT COUNT(*) FROM student_enrollments se JOIN users su ON su.id=se.student_id WHERE se.class_id=c.id AND se.academic_year_id={$currentAcademicYearId} AND se.enrollment_status='enrolled' AND su.role='student' AND su.status='active')"
    : "(SELECT COUNT(*) FROM users su WHERE su.class_id = c.id AND su.role = 'student' AND su.status = 'active' AND NOT EXISTS (SELECT 1 FROM student_profiles esp WHERE esp.user_id=su.id AND esp.enrollment_status <> 'enrolled'))";
$_maleSub = $currentAcademicYearId > 0
    ? "(SELECT COUNT(*) FROM student_enrollments se JOIN users u ON u.id=se.student_id LEFT JOIN student_profiles sp ON u.id = sp.user_id WHERE se.class_id=c.id AND se.academic_year_id={$currentAcademicYearId} AND se.enrollment_status='enrolled' AND u.role='student' AND u.status='active' AND COALESCE(sp.enrollment_status,'enrolled')='enrolled' AND sp.gender = 'male')"
    : "(SELECT COUNT(*) FROM users u LEFT JOIN student_profiles sp ON u.id = sp.user_id WHERE u.class_id = c.id AND u.role = 'student' AND u.status = 'active' AND COALESCE(sp.enrollment_status,'enrolled')='enrolled' AND sp.gender = 'male')";
$_femaleSub = $currentAcademicYearId > 0
    ? "(SELECT COUNT(*) FROM student_enrollments se JOIN users u ON u.id=se.student_id LEFT JOIN student_profiles sp ON u.id = sp.user_id WHERE se.class_id=c.id AND se.academic_year_id={$currentAcademicYearId} AND se.enrollment_status='enrolled' AND u.role='student' AND u.status='active' AND COALESCE(sp.enrollment_status,'enrolled')='enrolled' AND sp.gender = 'female')"
    : "(SELECT COUNT(*) FROM users u LEFT JOIN student_profiles sp ON u.id = sp.user_id WHERE u.class_id = c.id AND u.role = 'student' AND u.status = 'active' AND COALESCE(sp.enrollment_status,'enrolled')='enrolled' AND sp.gender = 'female')";

$yearFilterClause = $currentAcademicYearId > 0 ? "AND (c.academic_year_id = {$currentAcademicYearId} OR c.academic_year_id IS NULL)" : "";
$allClasses = $db->query("
    SELECT c.id, c.name, c.grade_id, c.room_location, g.grade_name, g.id as gid, s.stage_name, s.id as sid,
        {$_scSub} as student_count,
        {$_maleSub} as male_count,
        {$_femaleSub} as female_count
    FROM classes c
    LEFT JOIN grades g ON c.grade_id = g.id
    LEFT JOIN stages s ON g.stage_id = s.id
    WHERE c.status = 'active' {$yearFilterClause}
    ORDER BY s.id, g.id, c.display_order, c.name
")->fetchAll(PDO::FETCH_ASSOC);
if ($allowedClassIds !== null) {
    $allowedClassMap = array_fill_keys($allowedClassIds, true);
    $allClasses = array_values(array_filter($allClasses, static fn(array $class): bool => isset($allowedClassMap[(int) $class['id']])));
    $allowedGradeMap = array_fill_keys(array_map(static fn(array $class): int => (int) $class['grade_id'], $allClasses), true);
    $allowedStageMap = array_fill_keys(array_map(static fn(array $class): int => (int) $class['sid'], $allClasses), true);
    $grades = array_values(array_filter($grades, static fn(array $grade): bool => isset($allowedGradeMap[(int) $grade['id']])));
    $stages = array_values(array_filter($stages, static fn(array $stage): bool => isset($allowedStageMap[(int) $stage['id']])));
}

// فلاتر (دعم الاختيار المتعدد والفردي)
$filterStages = isset($_GET['stage_ids']) && is_array($_GET['stage_ids'])
    ? array_map('intval', $_GET['stage_ids'])
    : (!empty($_GET['stage_id']) ? [(int)$_GET['stage_id']] : []);

$filterGrades = isset($_GET['grade_ids']) && is_array($_GET['grade_ids'])
    ? array_map('intval', $_GET['grade_ids'])
    : (!empty($_GET['grade_id']) ? [(int)$_GET['grade_id']] : []);

$filterClassesInput = isset($_GET['class_ids']) && is_array($_GET['class_ids'])
    ? array_map('intval', $_GET['class_ids'])
    : (!empty($_GET['class_id']) ? [(int)$_GET['class_id']] : []);

// تطبيق الفلاتر على الفصول
$filteredClasses = $allClasses;
if (!empty($filterStages)) {
    $filteredClasses = array_filter($filteredClasses, fn($c) => in_array($c['sid'], $filterStages));
}
if (!empty($filterGrades)) {
    $filteredClasses = array_filter($filteredClasses, fn($c) => in_array($c['grade_id'], $filterGrades));
}
if (!empty($filterClassesInput)) {
    $filteredClasses = array_filter($filteredClasses, fn($c) => in_array($c['id'], $filterClassesInput));
}
$filteredClasses = array_values($filteredClasses);
$sortOrder = $_GET['sort_order'] ?? 'ar_alpha';
if (!in_array($sortOrder, ['ar_alpha', 'en_alpha', 'ar_female_first', 'ar_male_first', 'en_female_first', 'en_male_first'])) {
    $sortOrder = 'ar_alpha';
}
$printLayoutLang = $_GET['print_layout_lang'] ?? 'ar';
if (!in_array($printLayoutLang, ['ar', 'en'])) {
    $printLayoutLang = 'ar';
}
$showPrintStats = !isset($_GET['show_print_stats']) || $_GET['show_print_stats'] == '1';
$showPrintDate = !isset($_GET['show_print_date']) || $_GET['show_print_date'] == '1';

$isFiltered = isset($_GET['show_results']) || isset($_GET['stage_ids']) || isset($_GET['stage_id'])
    || isset($_GET['grade_ids']) || isset($_GET['grade_id'])
    || isset($_GET['class_ids']) || isset($_GET['class_id']);

if (isset($_GET['edit_custom']) && !empty($_GET['ids'])) {
    $editCustomIds = array_filter(array_map('intval', explode(',', $_GET['ids'])));
    if (!empty($editCustomIds)) {
        foreach ($editCustomIds as $studentId) $portalContext->assertStudentAllowed((int) $studentId);
        $placeholders = implode(',', array_fill(0, count($editCustomIds), '?'));
        $editClassesQuery = $db->prepare("
            SELECT DISTINCT c.id, c.name, g.grade_name, s.stage_name, c.grade_id, g.id as gid, s.id as sid,
                   (SELECT COUNT(*) FROM users su WHERE su.class_id = c.id AND su.role = 'student' AND su.status = 'active') as student_count,
                   (SELECT COUNT(*) FROM users su LEFT JOIN student_profiles sp ON su.id = sp.user_id WHERE su.class_id = c.id AND su.role = 'student' AND su.status = 'active' AND sp.gender = 'male') as male_count,
                   (SELECT COUNT(*) FROM users su LEFT JOIN student_profiles sp ON su.id = sp.user_id WHERE su.class_id = c.id AND su.role = 'student' AND su.status = 'active' AND sp.gender = 'female') as female_count
            FROM users u
            JOIN classes c ON u.class_id = c.id
            JOIN grades g ON c.grade_id = g.id
            JOIN stages s ON g.stage_id = s.id
            WHERE u.id IN ($placeholders) AND u.role = 'student' AND u.status = 'active'
        ");
        $editClassesQuery->execute($editCustomIds);
        $filteredClasses = $editClassesQuery->fetchAll(PDO::FETCH_ASSOC);
        $isFiltered = true;
    }
}

// إحصائيات
$totalStudents = array_sum(array_column($filteredClasses, 'student_count'));
$totalMale = array_sum(array_column($filteredClasses, 'male_count'));
$totalFemale = array_sum(array_column($filteredClasses, 'female_count'));
$totalClasses = count($filteredClasses);

if (isset($_GET['ajax_get_summary'])) {
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode([
        'success' => true,
        'total_students' => $totalStudents,
        'total_classes' => $totalClasses,
        'total_male' => $totalMale,
        'total_female' => $totalFemale,
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

function translate_class_to_ar($className) {
    if (empty($className)) return '';
    $map = [
        'Birds' => 'طيور',
        'Flowers' => 'زهور',
        'Fruits' => 'فواكه',
        'Pyramids' => 'أهرامات',
        'Seasons' => 'فصول',
        'Shapes' => 'أشكال',
        'Towers' => 'أبراج'
    ];
    foreach ($map as $en => $ar) {
        if (stripos($className, $en) === 0) {
            return str_ireplace($en, $ar, $className);
        }
    }
    return $className;
}

function classLists_fetchCustomStudents(PDO $db, array $studentIds, string $sortOrder = 'ar_alpha'): array {
    if (empty($studentIds)) return [];

    $orderBy = "u.name ASC";
    if ($sortOrder === 'en_alpha') {
        $orderBy = "CASE WHEN name_en IS NULL OR name_en = '' THEN 1 ELSE 0 END, name_en ASC, u.name ASC";
    } elseif ($sortOrder === 'ar_female_first') {
        $orderBy = "CASE WHEN sp.gender = 'female' THEN 0 ELSE 1 END, u.name ASC";
    } elseif ($sortOrder === 'ar_male_first') {
        $orderBy = "CASE WHEN sp.gender = 'male' THEN 0 ELSE 1 END, u.name ASC";
    } elseif ($sortOrder === 'en_female_first') {
        $orderBy = "CASE WHEN sp.gender = 'female' THEN 0 ELSE 1 END, CASE WHEN name_en IS NULL OR name_en = '' THEN 1 ELSE 0 END, name_en ASC, u.name ASC";
    } elseif ($sortOrder === 'en_male_first') {
        $orderBy = "CASE WHEN sp.gender = 'male' THEN 0 ELSE 1 END, CASE WHEN name_en IS NULL OR name_en = '' THEN 1 ELSE 0 END, name_en ASC, u.name ASC";
    }

    $placeholders = implode(',', array_fill(0, count($studentIds), '?'));
    $stmt = $db->prepare("
        SELECT u.id, u.name, u.class_id, sp.student_code, sp.gender,
               CONCAT_WS(' ', sp.first_name_en, sp.second_name_en, sp.third_name_en, sp.fourth_name_en, sp.family_name_en) as name_en,
               c.name as class_name_ar, c.name as class_name_en
        FROM users u
        LEFT JOIN student_profiles sp ON u.id = sp.user_id
        LEFT JOIN classes c ON u.class_id = c.id
        WHERE u.id IN ($placeholders) AND u.role = 'student' AND u.status = 'active'
        ORDER BY $orderBy
    ");
    $stmt->execute($studentIds);
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

// دالة موحدة لجلب طلاب فصل (مرتبطة بالعام الحالي عبر التسجيلات السنوية)
// ===== تصدير Excel =====
if (isset($_GET['export_excel'])) {
    $isCustomTab = (($_GET['tab'] ?? '') === 'custom');

    $showSerialAr = ($_GET['show_serial_ar'] ?? '1') === '1';
    $showCode = ($_GET['show_code'] ?? '0') === '1';
    $showNameAr = ($_GET['show_name_ar'] ?? '1') === '1';
    $showClassAr = ($_GET['show_class_ar'] ?? '1') === '1';
    $showGender = ($_GET['show_gender'] ?? '1') === '1';
    $showGenderEn = ($_GET['show_gender_en'] ?? '0') === '1';
    $showClassEn = ($_GET['show_class_en'] ?? '0') === '1';
    $showNameEn = ($_GET['show_name_en'] ?? '1') === '1';
    $showCodeEn = ($_GET['show_code_en'] ?? '0') === '1';
    $showSerialEn = ($_GET['show_serial_en'] ?? '1') === '1';

    $colLetters = ['A', 'B', 'C', 'D', 'E', 'F', 'G', 'H', 'I', 'J', 'K', 'L', 'M', 'N', 'O', 'P', 'Q', 'R', 'S', 'T', 'U', 'V', 'W', 'X', 'Y', 'Z'];

    if ($isCustomTab) {
        $customIds = [];
        if (!empty($_GET['ids'])) {
            $customIds = array_filter(array_map('intval', explode(',', $_GET['ids'])));
        }
        foreach ($customIds as $studentId) $portalContext->assertStudentAllowed((int) $studentId);
        $customTitle = trim((string) ($_GET['title'] ?? 'قائمة مخصصة'));
        if ($customTitle === '') {
            $customTitle = 'قائمة مخصصة';
        }
        $students = classLists_fetchCustomStudents($db, $customIds, $sortOrder);

        $exportColumns = classLists_getExportColumns(true, $showSerialAr, $showCode, $showNameAr, $showClassAr, $showGender, $showGenderEn, $showClassEn, $showNameEn, $showCodeEn, $showSerialEn);

        $autoloadPath = dirname(__FILE__) . '/../vendor/autoload.php';
        $hasPhpSpreadsheet = false;
        if (file_exists($autoloadPath)) {
            require_once $autoloadPath;
            $hasPhpSpreadsheet = class_exists('PhpOffice\\PhpSpreadsheet\\Spreadsheet');
        }

        if ($hasPhpSpreadsheet) {
            $spreadsheet = new \PhpOffice\PhpSpreadsheet\Spreadsheet();
            $sheet = $spreadsheet->getActiveSheet();
            $sheetTitle = ClassListExportSupport::safeWorksheetTitle($customTitle, 'قائمة مخصصة');
            $sheet->setTitle($sheetTitle);
            $sheet->setRightToLeft(true);

            // Title
            ClassListExportSupport::setSpreadsheetText($sheet, 'A1', $customTitle . ' | الإجمالي: ' . count($students));
            $lastColIndex = count($exportColumns);
            $lastCol = $colLetters[$lastColIndex - 1] ?? 'A';
            $sheet->mergeCells('A1:' . $lastCol . '1');
            $sheet->getStyle('A1')->getFont()->setBold(true)->setSize(14);

            // Headers
            $row = 3;
            foreach ($exportColumns as $cidx => $col) {
                $letter = $colLetters[$cidx];
                ClassListExportSupport::setSpreadsheetText($sheet, $letter . $row, $col['header']);
            }
            $headerRange = 'A' . $row . ':' . $lastCol . $row;
            $sheet->getStyle($headerRange)->getFont()->setBold(true);
            $sheet->getStyle($headerRange)->getFill()->setFillType(\PhpOffice\PhpSpreadsheet\Style\Fill::FILL_SOLID)->getStartColor()->setRGB('F0F0F0');
            $row++;

            $n = 0;
            foreach ($students as $st) {
                foreach ($exportColumns as $cidx => $col) {
                    $letter = $colLetters[$cidx];
                    $val = $col['value']($st, $n);
                    ClassListExportSupport::setSpreadsheetText($sheet, $letter . $row, $val);
                }
                $row++;
                $n++;
            }

            foreach (range('A', $lastCol) as $c) {
                $sheet->getColumnDimension($c)->setAutoSize(true);
            }
            $tableRange = 'A3:' . $lastCol . ($row - 1);
            $sheet->getStyle($tableRange)->getBorders()->getAllBorders()->setBorderStyle(\PhpOffice\PhpSpreadsheet\Style\Border::BORDER_THIN);

            ob_clean();
            $filename = ClassListExportSupport::safeFileBase($customTitle, 'custom_list') . '_' . date('Y-m-d') . '.xlsx';
            ClassListExportSupport::sendDownloadHeaders('application/vnd.openxmlformats-officedocument.spreadsheetml.sheet', $filename);
            header('Cache-Control: max-age=0');
            $writer = new \PhpOffice\PhpSpreadsheet\Writer\Xlsx($spreadsheet);
            $writer->save('php://output');
            $spreadsheet->disconnectWorksheets();
            exit;
        } else {
            // Fallback CSV
            $filename = ClassListExportSupport::safeFileBase($customTitle, 'custom_list') . '_' . date('Y-m-d') . '.csv';
            ClassListExportSupport::sendDownloadHeaders('text/csv; charset=utf-8', $filename);
            echo "\xEF\xBB\xBF";
            $out = fopen('php://output', 'w');
            fputcsv($out, [ClassListExportSupport::safeCsvValue($customTitle . ' | الإجمالي: ' . count($students))]);

            $headers = [];
            foreach ($exportColumns as $col) {
                $headers[] = ClassListExportSupport::safeCsvValue($col['header']);
            }
            fputcsv($out, $headers);

            $n = 0;
            foreach ($students as $st) {
                $r = [];
                foreach ($exportColumns as $col) {
                    $r[] = ClassListExportSupport::safeCsvValue($col['value']($st, $n));
                }
                fputcsv($out, $r);
                $n++;
            }
            fclose($out);
            exit;
        }
    } else {
        // Standard class lists Excel export
        $classIds = $_GET['class_ids'] ?? [];
        if (!empty($classIds) && !is_array($classIds)) {
            $classIds = array_filter(array_map('intval', explode(',', $classIds)));
        }
        if (!empty($classIds) && is_array($classIds)) {
            $classIds = array_map('intval', $classIds);
            $filteredClasses = array_filter($filteredClasses, fn($c) => in_array($c['id'], $classIds));
            $filteredClasses = array_values($filteredClasses);
        }

        $exportColumns = classLists_getExportColumns(false, $showSerialAr, $showCode, $showNameAr, $showClassAr, $showGender, $showGenderEn, $showClassEn, $showNameEn, $showCodeEn, $showSerialEn);
        $studentsByClassId = $classListStudentQuery->fetchByClassIds(
            $currentAcademicYearId,
            array_column($filteredClasses, 'id'),
            $sortOrder
        );

        $autoloadPath = dirname(__FILE__) . '/../vendor/autoload.php';
        $hasPhpSpreadsheet = false;
        if (file_exists($autoloadPath)) {
            require_once $autoloadPath;
            $hasPhpSpreadsheet = class_exists('PhpOffice\\PhpSpreadsheet\\Spreadsheet');
        }

        if ($hasPhpSpreadsheet) {
            $spreadsheet = new \PhpOffice\PhpSpreadsheet\Spreadsheet();
            $sheetIndex = 0;
            foreach ($filteredClasses as $cl) {
                $students = $studentsByClassId[(int) $cl['id']] ?? [];

                if ($sheetIndex > 0) {
                    $sheet = $spreadsheet->createSheet();
                } else {
                    $sheet = $spreadsheet->getActiveSheet();
                }
                $sheetTitle = ClassListExportSupport::safeWorksheetTitle(
                    (string) $cl['name'] . ' #' . (int) $cl['id'],
                    'Class ' . (int) $cl['id']
                );
                $sheet->setTitle($sheetTitle);
                $sheet->setRightToLeft(true);

                // title
                ClassListExportSupport::setSpreadsheetText($sheet, 'A1', $cl['name'] . ' - ' . ($cl['grade_name'] ?? '') . ' | الإجمالي: ' . count($students));
                $lastColIndex = count($exportColumns);
                $lastCol = $colLetters[$lastColIndex - 1] ?? 'A';
                $sheet->mergeCells('A1:' . $lastCol . '1');
                $sheet->getStyle('A1')->getFont()->setBold(true)->setSize(14);

                // headers
                $row = 3;
                foreach ($exportColumns as $cidx => $col) {
                    $letter = $colLetters[$cidx];
                    ClassListExportSupport::setSpreadsheetText($sheet, $letter . $row, $col['header']);
                }
                $headerRange = 'A' . $row . ':' . $lastCol . $row;
                $sheet->getStyle($headerRange)->getFont()->setBold(true);
                $sheet->getStyle($headerRange)->getFill()->setFillType(\PhpOffice\PhpSpreadsheet\Style\Fill::FILL_SOLID)->getStartColor()->setRGB('F0F0F0');
                $row++;

                $n = 0;
                foreach ($students as $st) {
                    foreach ($exportColumns as $cidx => $col) {
                        $letter = $colLetters[$cidx];
                        $val = $col['value']($st, $n);
                        ClassListExportSupport::setSpreadsheetText($sheet, $letter . $row, $val);
                    }
                    $row++;
                    $n++;
                }

                foreach (range('A', $lastCol) as $c) {
                    $sheet->getColumnDimension($c)->setAutoSize(true);
                }
                // border
                $tableRange = 'A3:' . $lastCol . ($row - 1);
                $sheet->getStyle($tableRange)->getBorders()->getAllBorders()->setBorderStyle(\PhpOffice\PhpSpreadsheet\Style\Border::BORDER_THIN);

                $sheetIndex++;
            }

            ob_clean();
            $filename = 'قوائم_الفصول_' . date('Y-m-d') . '.xlsx';
            header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
            header('Content-Disposition: attachment; filename="' . $filename . '"');
            header('Cache-Control: max-age=0');
            $writer = new \PhpOffice\PhpSpreadsheet\Writer\Xlsx($spreadsheet);
            $writer->save('php://output');
            $spreadsheet->disconnectWorksheets();
            exit;
        } else {
            // Fallback CSV
            $filename = 'قوائم_الفصول_' . date('Y-m-d') . '.csv';
            header('Content-Type: text/csv; charset=utf-8');
            header('Content-Disposition: attachment; filename="' . $filename . '"');
            echo "\xEF\xBB\xBF";
            $out = fopen('php://output', 'w');
            foreach ($filteredClasses as $cl) {
                fputcsv($out, [ClassListExportSupport::safeCsvValue($cl['name'] . ' - ' . ($cl['grade_name'] ?? ''))]);

                $headers = [];
                foreach ($exportColumns as $col) {
                    $headers[] = ClassListExportSupport::safeCsvValue($col['header']);
                }
                fputcsv($out, $headers);

                $students = $studentsByClassId[(int) $cl['id']] ?? [];
                $n = 0;
                foreach ($students as $st) {
                    $r = [];
                    foreach ($exportColumns as $col) {
                        $r[] = ClassListExportSupport::safeCsvValue($col['value']($st, $n));
                    }
                    fputcsv($out, $r);
                    $n++;
                }
                fputcsv($out, []);
            }
            fclose($out);
            exit;
        }
    }
}

// ===== طباعة جميع القوائم (صفحة طباعة مستقلة) =====
if (isset($_GET['print_all'])) {
    if (($_GET['tab'] ?? '') === 'custom') {
        $customIds = [];
        if (!empty($_GET['ids'])) {
            $customIds = array_filter(array_map('intval', explode(',', $_GET['ids'])));
        }
        foreach ($customIds as $studentId) $portalContext->assertStudentAllowed((int) $studentId);
        $customTitle = trim((string) ($_GET['title'] ?? 'قائمة مخصصة')) ?: 'قائمة مخصصة';
        $students = classLists_fetchCustomStudents($db, $customIds, $sortOrder);

        $maleCount = 0;
        $femaleCount = 0;
        foreach ($students as $st) {
            if ($st['gender'] === 'male') $maleCount++;
            elseif ($st['gender'] === 'female') $femaleCount++;
        }

        $printStageIdParam = $_GET['print_stage_id'] ?? 'auto';
        $selectedStageId = null;
        $selectedStageName = null;

        if ($printStageIdParam === 'auto') {
            if (!empty($customIds)) {
                $placeholders = implode(',', array_fill(0, count($customIds), '?'));
                $stmt = $db->prepare("
                    SELECT DISTINCT g.stage_id, s.stage_name
                    FROM users u
                    JOIN classes c ON u.class_id = c.id
                    JOIN grades g ON c.grade_id = g.id
                    JOIN stages s ON g.stage_id = s.id
                    WHERE u.id IN ($placeholders) AND u.role = 'student' AND u.status = 'active'
                ");
                $stmt->execute($customIds);
                $detectedStages = $stmt->fetchAll(PDO::FETCH_ASSOC);
                if (count($detectedStages) === 1) {
                    $selectedStageId = (int)$detectedStages[0]['stage_id'];
                    $selectedStageName = $detectedStages[0]['stage_name'];
                }
            }
        } elseif ($printStageIdParam !== 'none') {
            $stmt = $db->prepare("SELECT id, stage_name FROM stages WHERE id = ? LIMIT 1");
            $stmt->execute([(int)$printStageIdParam]);
            $stg = $stmt->fetch(PDO::FETCH_ASSOC);
            if ($stg) {
                $selectedStageId = (int)$stg['id'];
                $selectedStageName = $stg['stage_name'];
            }
        }

        $chunks = array_chunk($students, 35);
        $printClasses = [];
        $total_pages = count($chunks);
        if (empty($chunks)) {
            $printClasses[] = [
                'id' => 'custom',
                'name' => $customTitle,
                'grade_name' => '',
                'stage_name' => $selectedStageName,
                'stage_id' => $selectedStageId,
                'male_count' => 0,
                'female_count' => 0,
                'student_count' => 0,
                'students' => [],
                'page_num' => 1,
                'total_pages' => 1,
                'is_custom' => true
            ];
        } else {
            foreach ($chunks as $idx => $chunk) {
                $printClasses[] = [
                    'id' => 'custom',
                    'name' => $customTitle,
                    'grade_name' => '',
                    'stage_name' => $selectedStageName,
                    'stage_id' => $selectedStageId,
                    'male_count' => $maleCount,
                    'female_count' => $femaleCount,
                    'student_count' => count($students),
                    'students' => $chunk,
                    'page_num' => $idx + 1,
                    'total_pages' => $total_pages,
                    'is_custom' => true
                ];
            }
        }
        $printTitle = $customTitle;
    } else {
        // دعم تحديد فصول معينة
        $classIds = $_GET['class_ids'] ?? [];
        if (!empty($classIds) && is_array($classIds)) {
            $classIds = array_map('intval', $classIds);
            $filteredClasses = array_filter($filteredClasses, fn($c) => in_array($c['id'], $classIds));
            $filteredClasses = array_values($filteredClasses);
        }
        // جلب طلاب كل الفصول المفلترة
        $studentsByClassId = $classListStudentQuery->fetchByClassIds(
            $currentAcademicYearId,
            array_column($filteredClasses, 'id'),
            $sortOrder
        );
        $printClasses = [];
        foreach ($filteredClasses as $cl) {
            $students = $studentsByClassId[(int) $cl['id']] ?? [];
            $chunks = array_chunk($students, 35);
            if (empty($chunks)) {
                $cl['students'] = [];
                $cl['page_num'] = 1;
                $cl['total_pages'] = 1;
                $printClasses[] = $cl;
            } else {
                $total_pages = count($chunks);
                foreach ($chunks as $idx => $chunk) {
                    $pageCl = $cl;
                    $pageCl['students'] = $chunk;
                    $pageCl['page_num'] = $idx + 1;
                    $pageCl['total_pages'] = $total_pages;
                    $printClasses[] = $pageCl;
                }
            }
        }

        $printTitle = 'قوائم جميع الفصول';
    }
    if (count($filterStages) === 1) {
        foreach ($stages as $st) {
            if ($st['id'] == $filterStages[0]) { $printTitle = 'قوائم فصول ' . $st['stage_name']; break; }
        }
    }
    if (count($filterGrades) === 1) {
        foreach ($grades as $gr) {
            if ($gr['id'] == $filterGrades[0]) { $printTitle = 'قوائم فصول ' . $gr['grade_name']; break; }
        }
    }
?>
<!DOCTYPE html>
<html dir="rtl" lang="ar">
<head>
    <meta charset="UTF-8">
    <title><?php echo htmlspecialchars($printTitle); ?></title>
    <link href="https://fonts.googleapis.com/css2?family=Tajawal:wght@400;500;700;800&display=swap" rel="stylesheet">
    <?php echo print_template_css(); ?>
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body { font-family: 'Tajawal', sans-serif; direction: rtl; font-size: 14px; color: #000; }
        @page { size: A4 portrait; margin: 10mm; }
        .class-page { page-break-after: always; page-break-inside: avoid; position: relative; overflow: hidden;
            display: block; width: 100%; }
        .class-page:last-child { page-break-after: avoid; }
        .class-page-inner { transform-origin: top center; width: 100%; }
        table.students-table tr { page-break-inside: avoid; }
        .print-only-header, .print-only-footer { display: block !important; }
        .print-logo-img { width: 60px; height: 60px; object-fit: contain; margin-bottom: 3px; }
        .print-header-table { width: 100%; border-collapse: collapse; margin-bottom: 5px; }
        .print-header-table td { vertical-align: top; padding: 0 5px; }
        .print-header-right { text-align: right; width: 30%; }
        .print-header-center { text-align: center; width: 40%; }
        .print-header-left { text-align: left; width: 30%; }
        .print-directorate { font-size: 12px; font-weight: bold; color: #333; margin-bottom: 2px; }
        .print-administration { font-size: 11px; font-weight: 600; color: #555; }
        .print-school-name { font-size: 16px; font-weight: 800; color: #000; margin-bottom: 2px; }
        .print-academic-year { font-size: 10px; color: #666; }
        .print-doc-title { font-size: 13px; font-weight: bold; color: #333; margin-bottom: 2px; }
        .print-doc-subtitle { font-size: 11px; color: #555; margin-bottom: 2px; }
        .print-date { font-size: 10px; color: #888; }
        .print-header-line { border-top: 2px solid #333; margin: 8px 0 12px 0; }
        .print-footer-line { border-top: 1px solid #999; margin: 20px 0 10px 0; }
        .print-footer-table { width: 100%; border-collapse: collapse; }
        .print-footer-cell { width: 50%; text-align: center; padding: 5px 20px; }
        .print-footer-label { font-size: 13px; color: #555; font-weight: 600; margin-bottom: 3px; }
        .print-footer-name { font-size: 13px; font-weight: bold; color: #000; padding-top: 0; margin-top: 3px; }
        .class-info-bar {
            text-align: center; font-size: 16px; font-weight: bold;
            margin-bottom: 10px; padding: 6px 0; border-bottom: 1px solid #ccc;
        }
        .custom-print-page .print-header-line {
            display: none !important;
        }
        .custom-print-page .class-info-bar {
            border-bottom: none !important;
            margin-bottom: 0 !important;
            padding: 0 !important;
        }
        table.students-table { width: 100%; table-layout: fixed; border-collapse: collapse; margin-bottom: 10px; }
        table.students-table th { background: #f0f0f0; border: 1px solid #aaa; padding: 4px 8px; font-size: 12px; font-weight: bold; text-align: center; }
        table.students-table td { border: 1px solid #aaa; padding: 4px 8px; font-size: 12px; line-height: 1.3; font-weight: bold; }
        table.students-table th.col-serial, table.students-table td.col-serial { width: 38px; text-align: center; }
        table.students-table th.col-code, table.students-table td.col-code { width: 80px; text-align: center; }
        table.students-table th.col-gender, table.students-table td.col-gender { width: 80px; text-align: center; }
        table.students-table th.col-class, table.students-table td.col-class { width: 90px; text-align: center; }
        table.students-table td.student-name-cell { padding: 4px 6px; overflow: hidden; }
        table.students-table td.student-name-cell .name-text { display: inline-block; white-space: nowrap; }
        .empty-class { text-align: center; color: #999; padding: 20px; font-style: italic; }
        .back-bar {
            background: #f8f9fa; padding: 10px 20px; text-align: center;
            border-bottom: 1px solid #ddd; font-family: 'Tajawal', sans-serif;
        }
        .back-bar a { color: #0d6efd; text-decoration: none; font-weight: 600; }
        @media print {
            .back-bar { display: none !important; }
        }
        @media screen {
            .class-page { border: 1px solid #ddd; padding: 15mm; margin: 15px auto; width: 210mm; height: 297mm; background: #fff; box-shadow: 0 2px 8px rgba(0,0,0,0.1); position: relative; }
        }
    </style>
</head>
<body>
    <?php
    $showSerialAr = !isset($_GET['show_serial_ar']) || $_GET['show_serial_ar'] == '1';
    $showCode = isset($_GET['show_code']) && $_GET['show_code'] == '1';
    $showNameAr = !isset($_GET['show_name_ar']) || $_GET['show_name_ar'] == '1';
    $showClassAr = !isset($_GET['show_class_ar']) || $_GET['show_class_ar'] == '1';
    $showGender = !isset($_GET['show_gender']) || $_GET['show_gender'] == '1';
    $showClassEn = isset($_GET['show_class_en']) && $_GET['show_class_en'] == '1';
    $showGenderEn = isset($_GET['show_gender_en']) && $_GET['show_gender_en'] == '1';
    $showNameEn = !isset($_GET['show_name_en']) || $_GET['show_name_en'] == '1';
    $showCodeEn = isset($_GET['show_code_en']) && $_GET['show_code_en'] == '1';
    $showSerialEn = !isset($_GET['show_serial_en']) || $_GET['show_serial_en'] == '1';
    $showPrintStats = !isset($_GET['show_print_stats']) || $_GET['show_print_stats'] == '1';
    $showPrintDate = !isset($_GET['show_print_date']) || $_GET['show_print_date'] == '1';
    ?>
    <div class="back-bar">
        <?php
        $backParams = [];
        if (!empty($filterStages)) $backParams['stage_ids'] = $filterStages;
        if (!empty($filterGrades)) $backParams['grade_ids'] = $filterGrades;
        if (!empty($filterClassesInput)) $backParams['class_ids'] = $filterClassesInput;
        if (isset($_GET['show_results'])) $backParams['show_results'] = 1;
        $backParams['sort_order'] = $sortOrder;
        $backParams['print_layout_lang'] = $printLayoutLang;
        $backParams['show_print_stats'] = $showPrintStats ? '1' : '0';
        $backParams['show_print_date'] = $showPrintDate ? '1' : '0';
        ?>
        <a href="class_lists.php?<?php echo http_build_query($backParams); ?>">
            <i class="fas fa-arrow-right"></i> العودة لقوائم الفصول
        </a>
        &nbsp;&nbsp;|&nbsp;&nbsp;
        <a href="#" onclick="window.print(); return false;">
            <i class="fas fa-print"></i> طباعة
        </a>
        &nbsp;&nbsp;|&nbsp;&nbsp;
        <?php
        $excelParams = [
            'export_excel' => 1,
            'sort_order' => $sortOrder,
            'print_layout_lang' => $printLayoutLang,
            'show_print_stats' => $showPrintStats ? '1' : '0',
            'show_print_date' => $showPrintDate ? '1' : '0'
        ];
        if (($_GET['tab'] ?? '') === 'custom') {
            $excelParams['tab'] = 'custom';
            $excelParams['ids'] = $_GET['ids'] ?? '';
            $excelParams['title'] = $_GET['title'] ?? '';
        } else {
            if (!empty($filterStages)) $excelParams['stage_ids'] = $filterStages;
            if (!empty($filterGrades)) $excelParams['grade_ids'] = $filterGrades;
            if (!empty($filterClassesInput)) $excelParams['class_ids'] = $filterClassesInput;
        }
        $excelParams['show_serial_ar'] = $showSerialAr ? '1' : '0';
        $excelParams['show_code'] = $showCode ? '1' : '0';
        $excelParams['show_name_ar'] = $showNameAr ? '1' : '0';
        $excelParams['show_class_ar'] = $showClassAr ? '1' : '0';
        $excelParams['show_gender'] = $showGender ? '1' : '0';
        $excelParams['show_gender_en'] = $showGenderEn ? '1' : '0';
        $excelParams['show_class_en'] = $showClassEn ? '1' : '0';
        $excelParams['show_name_en'] = $showNameEn ? '1' : '0';
        $excelParams['show_code_en'] = $showCodeEn ? '1' : '0';
        $excelParams['show_serial_en'] = $showSerialEn ? '1' : '0';
        ?>
        <a href="class_lists.php?<?php echo http_build_query($excelParams); ?>">
            <i class="fas fa-file-excel"></i> تصدير Excel
        </a>
    </div>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
    <?php foreach ($printClasses as $ci => $pcl): ?>
    <div class="class-page <?php echo !empty($pcl['is_custom']) ? 'custom-print-page' : ''; ?>">
    <div class="class-page-inner">
        <?php
        $titleSuffix = $pcl['total_pages'] > 1 ? ($printLayoutLang === 'en' ? " (Page {$pcl['page_num']} of {$pcl['total_pages']})" : " (صفحة {$pcl['page_num']} من {$pcl['total_pages']})") : "";
        $headerTitle = $titleSuffix;
        echo print_header_html(
            $headerTitle,
            ($pcl['stage_name'] ?? ''),
            $printLayoutLang,
            $showPrintDate
        ); ?>

        <div class="class-info-bar" style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 12px; font-size: 14px; <?php echo $printLayoutLang === 'en' ? 'flex-direction: row-reverse;' : ''; ?>">
            <div>
                <?php
                if (!empty($pcl['is_custom'])) {
                    // Do not print "قائمة طلاب مخصصة" text inside the sheet
                } else {
                    $prefix = $printLayoutLang === 'en' ? 'Class List: ' : 'قائمة فصل: ';
                    $displayName = '<strong style="font-size: 17px; color: #1e3a8a; border-bottom: 2px solid #2563eb; padding-bottom: 1px; margin: 0 4px;">' . htmlspecialchars($pcl['name'] ?? '') . '</strong>';
                    $displayGrade = $printLayoutLang === 'en' ? translate_text_to_en($pcl['grade_name'] ?? '') : ($pcl['grade_name'] ?? '');
                    echo $prefix . $displayName . ' - ' . htmlspecialchars($displayGrade);
                }
                ?>
            </div>
            <?php if ($showPrintStats): ?>
                <div style="font-size: 12px; font-weight: bold; background-color: #f8fafc; border: 1px solid #e2e8f0; padding: 4px 10px; border-radius: 6px; color: #334155; display: inline-flex; align-items: center;">
                    <?php if ($printLayoutLang === 'en'): ?>
                        <span>Boys: <strong style="color:#2563eb;"><?php echo $pcl['male_count']; ?></strong></span>
                        <span style="margin: 0 6px; color: #cbd5e1;">|</span>
                        <span>Girls: <strong style="color:#db2777;"><?php echo $pcl['female_count']; ?></strong></span>
                        <span style="margin: 0 6px; color: #cbd5e1;">|</span>
                        <span>Total: <strong style="color:#0f172a;"><?php echo $pcl['student_count']; ?></strong></span>
                    <?php else: ?>
                        <span>بنين: <strong style="color:#2563eb;"><?php echo $pcl['male_count']; ?></strong></span>
                        <span style="margin: 0 6px; color: #cbd5e1;">|</span>
                        <span>بنات: <strong style="color:#db2777;"><?php echo $pcl['female_count']; ?></strong></span>
                        <span style="margin: 0 6px; color: #cbd5e1;">|</span>
                        <span>الإجمالي: <strong style="color:#0f172a;"><?php echo $pcl['student_count']; ?></strong></span>
                    <?php endif; ?>
                </div>
            <?php endif; ?>
        </div>

        <?php if (!empty($pcl['is_custom'])): ?>
            <div style="text-align: center; margin: 10px 0 15px 0;">
                <div style="font-size: 18px; font-weight: 800; color: #1e3a8a; border: 2px solid #1e3a8a; padding: 6px 30px; display: inline-block; border-radius: 30px; background-color: #f8fafc; box-shadow: 0 4px 6px rgba(0, 0, 0, 0.05); font-family: 'Tajawal', sans-serif;">
                    <?php echo htmlspecialchars($pcl['name']); ?>
                </div>
            </div>
        <?php endif; ?>

        <?php if (empty($pcl['students'])): ?>
            <div class="empty-class"><?php echo $printLayoutLang === 'en' ? 'No students in this class' : 'لا يوجد طلاب في هذا الفصل'; ?></div>
        <?php else: ?>
        <table class="students-table">
            <thead>
                <tr>
                    <?php if ($showSerialAr): ?><th class="col-serial">#</th><?php endif; ?>
                    <?php if ($showCode): ?><th class="col-code">كود الطالب</th><?php endif; ?>
                    <?php if ($showNameAr): ?><th>اسم الطالب</th><?php endif; ?>
                    <?php if (!empty($pcl['is_custom']) && $showClassAr): ?><th class="col-class">اسم الفصل</th><?php endif; ?>
                    <?php if ($showGender): ?><th class="col-gender">النوع</th><?php endif; ?>
                    <?php if ($showGenderEn): ?><th class="col-gender" style="text-align: left !important;">Gender</th><?php endif; ?>
                    <?php if (!empty($pcl['is_custom']) && $showClassEn): ?><th class="col-class" style="text-align: left !important;">Class</th><?php endif; ?>
                    <?php if ($showNameEn): ?><th style="text-align: left !important;">Student Name</th><?php endif; ?>
                    <?php if ($showCodeEn): ?><th class="col-code" style="text-align: left !important;">Code</th><?php endif; ?>
                    <?php if ($showSerialEn): ?><th class="col-serial" style="text-align: left !important;">#</th><?php endif; ?>
                </tr>
            </thead>
            <tbody>
                <?php
                $startIdx = ($pcl['page_num'] - 1) * 35;
                $si = 0;
                foreach ($pcl['students'] as $stu):
                    $si++;
                ?>
                <tr>
                    <?php if ($showSerialAr): ?><td class="col-serial"><?php echo classLists_toArabicNumerals($startIdx + $si); ?></td><?php endif; ?>
                    <?php if ($showCode): ?><td class="col-code"><?php echo htmlspecialchars($stu['student_code'] ?? '-'); ?></td><?php endif; ?>
                    <?php if ($showNameAr): ?><td class="student-name-cell"><strong><span class="name-text"><?php echo htmlspecialchars($stu['name']); ?></span></strong></td><?php endif; ?>
                    <?php if (!empty($pcl['is_custom']) && $showClassAr): ?>
                        <td class="col-class"><strong><?php echo htmlspecialchars($stu['class_name_ar'] ?? ''); ?></strong></td>
                    <?php endif; ?>
                    <?php if ($showGender): ?><td class="col-gender"><?php echo ($stu['gender'] ?? '') === 'male' ? 'ذكر' : (($stu['gender'] ?? '') === 'female' ? 'أنثى' : '-'); ?></td><?php endif; ?>
                    <?php if ($showGenderEn): ?><td class="col-gender"><?php echo ($stu['gender'] ?? '') === 'male' ? 'Male' : (($stu['gender'] ?? '') === 'female' ? 'Female' : '-'); ?></td><?php endif; ?>
                    <?php if (!empty($pcl['is_custom']) && $showClassEn): ?>
                        <td class="col-class" style="text-align: left !important;"><strong><?php echo htmlspecialchars($stu['class_name_en'] ?? ''); ?></strong></td>
                    <?php endif; ?>
                    <?php if ($showNameEn): ?><td dir="ltr" class="student-name-cell" style="text-align: left !important;"><span class="name-text"><?php echo htmlspecialchars($stu['name_en'] ?? '-'); ?></span></td><?php endif; ?>
                    <?php if ($showCodeEn): ?><td dir="ltr" class="col-code" style="text-align: left !important;"><?php echo htmlspecialchars($stu['student_code'] ?? '-'); ?></td><?php endif; ?>
                    <?php if ($showSerialEn): ?><td class="col-serial" style="text-align: left !important;"><?php echo $startIdx + $si; ?></td><?php endif; ?>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
        <?php endif; ?>

        <?php
        if ($pcl['page_num'] == $pcl['total_pages']) {
            echo print_footer_html(
                $pcl['stage_id'] ?? $pcl['sid'] ?? null,
                null,
                $pcl['stage_name'] ?? null,
                $printLayoutLang,
                $showPrintDate
            );
        }
        ?>
    </div><!-- /class-page-inner -->
    </div>
    <?php endforeach; ?>

    <script>
    window.onload = function() {
        var A4_HEIGHT = 1040;
        var pages = document.querySelectorAll('.class-page-inner');
        pages.forEach(function(inner) {
            var table = inner.querySelector('table.students-table');
            inner.style.paddingTop = '0px';

            if (table) {
                var cells = table.querySelectorAll('th, td');
                var fontSize = 12;

                // Step 1: Reduce font size dynamically if it exceeds A4 height
                while (inner.scrollHeight > A4_HEIGHT && fontSize > 8) {
                    fontSize -= 0.5;
                    cells.forEach(function(c) {
                        c.style.fontSize = fontSize + 'px';
                    });
                }

                var nameSpans = inner.querySelectorAll('.student-name-cell .name-text');
                nameSpans.forEach(function(span) {
                    var cell = span.closest('td');
                    if (!cell || cell.clientWidth <= 8) return;
                    var availWidth = cell.clientWidth - 8;
                    var fSize = parseFloat(window.getComputedStyle(span).fontSize) || fontSize || 12;
                    while (span.offsetWidth > availWidth && fSize > 6.5) {
                        fSize -= 0.5;
                        span.style.fontSize = fSize + 'px';
                    }
                });

                // Step 2: Reduce padding if it still exceeds A4 height
                if (inner.scrollHeight > A4_HEIGHT) {
                    cells.forEach(function(c) {
                        c.style.padding = '2px 4px';
                    });
                }

                // Step 3: Reduce line-height if it still exceeds A4 height
                if (inner.scrollHeight > A4_HEIGHT) {
                    cells.forEach(function(c) {
                        c.style.lineHeight = '1.1';
                    });
                }
            }

            // Step 4: Fallback CSS scale transform if it STILL exceeds A4 height
            var h = inner.scrollHeight;
            if (h > A4_HEIGHT) {
                var scale = Math.max(0.4, (A4_HEIGHT - 5) / h);
                inner.style.transform = 'scale(' + scale.toFixed(4) + ')';
                inner.style.transformOrigin = 'top center';
                inner.parentElement.style.height = A4_HEIGHT + 'px';
            }
        });
        setTimeout(function() { window.print(); }, 400);
    };
    </script>
</body>
</html>
<?php
    exit;
}

// ===== Active Tab =====
$activeTab = $_GET['tab'] ?? 'lists';
if (!in_array($activeTab, ['lists', 'log', 'custom'])) $activeTab = 'lists';

// ===== جلب سجل العمليات (عمليات النقل) =====
$transferLog = [];
try {
    if ($allowedClassIds === null) {
        $totalTransfersCount = (int)$db->query("SELECT COUNT(*) FROM student_transfers")->fetchColumn();
    } elseif ($allowedClassIds === []) {
        $totalTransfersCount = 0;
    } else {
        $scopePlaceholders = implode(',', array_fill(0, count($allowedClassIds), '?'));
        $countStmt = $db->prepare("SELECT COUNT(*) FROM student_transfers WHERE from_class_id IN ($scopePlaceholders) OR to_class_id IN ($scopePlaceholders)");
        $countStmt->execute(array_merge($allowedClassIds, $allowedClassIds));
        $totalTransfersCount = (int) $countStmt->fetchColumn();
    }
} catch (Exception $e) {
    $totalTransfersCount = 0;
}

if ($activeTab === 'log') {
    $logScopeSql = '';
    $logScopeParams = [];
    if ($allowedClassIds !== null) {
        if ($allowedClassIds === []) {
            $logScopeSql = ' WHERE 1 = 0';
        } else {
            $scopePlaceholders = implode(',', array_fill(0, count($allowedClassIds), '?'));
            $logScopeSql = " WHERE st.from_class_id IN ($scopePlaceholders) OR st.to_class_id IN ($scopePlaceholders)";
            $logScopeParams = array_merge($allowedClassIds, $allowedClassIds);
        }
    }
    $logSql = "
        SELECT st.id, st.transfer_date, st.reason, st.notes, st.created_at,
               u.name as student_name, sp.student_code,
               cf.name as from_class, gf.grade_name as from_grade,
               ct.name as to_class, gt.grade_name as to_grade,
               tb.name as transferred_by_name
        FROM student_transfers st
        LEFT JOIN users u ON st.student_id = u.id
        LEFT JOIN student_profiles sp ON u.id = sp.user_id
        LEFT JOIN classes cf ON st.from_class_id = cf.id
        LEFT JOIN grades gf ON cf.grade_id = gf.id
        LEFT JOIN classes ct ON st.to_class_id = ct.id
        LEFT JOIN grades gt ON ct.grade_id = gt.id
        LEFT JOIN users tb ON st.transferred_by = tb.id
        {$logScopeSql}
        ORDER BY st.created_at DESC
        LIMIT 500
    ";
    $logStmt = $db->prepare($logSql);
    $logStmt->execute($logScopeParams);
    $transferLog = $logStmt->fetchAll(PDO::FETCH_ASSOC);
}

require_once '../includes/admin_header.php';
?>

<link rel="stylesheet" href="../assets/css/class-lists.css">

<?php echo print_template_css(); ?>

<!-- Page Header -->
<div class="admin-page-heading no-print mb-4">
    <h1 class="h2"><i class="fas fa-list-alt me-2 text-primary"></i>قوائم الفصول</h1>
    <div class="admin-top-actions no-print">
        <?php
        $excelParams = ['export_excel' => 1];
        if (!empty($filterStages)) $excelParams['stage_ids'] = $filterStages;
        if (!empty($filterGrades)) $excelParams['grade_ids'] = $filterGrades;
        if (!empty($filterClassesInput)) $excelParams['class_ids'] = $filterClassesInput;
        if (isset($_GET['show_results'])) $excelParams['show_results'] = 1;

        $printParams = ['print_all' => 1];
        if (!empty($filterStages)) $printParams['stage_ids'] = $filterStages;
        if (!empty($filterGrades)) $printParams['grade_ids'] = $filterGrades;
        if (!empty($filterClassesInput)) $printParams['class_ids'] = $filterClassesInput;
        if (isset($_GET['show_results'])) $printParams['show_results'] = 1;
        ?>
        <a href="class_lists.php?<?php echo http_build_query($excelParams); ?>" id="exportExcelBtn" class="btn btn-header-premium btn-export-soft <?php echo !$isFiltered ? 'disabled' : ''; ?>">
            <i class="fas fa-file-excel me-1"></i>تصدير Excel
        </a>
        <a href="class_lists.php?<?php echo http_build_query($printParams); ?>" id="printAllBtn" class="btn btn-header-premium btn-print-soft <?php echo !$isFiltered ? 'disabled' : ''; ?>" target="_blank">
            <i class="fas fa-print me-1"></i>طباعة
        </a>
    </div>
</div>

<!-- Stat cards row -->
<div class="row row-cols-2 row-cols-md-4 g-3 mb-4 no-print animate-up">
    <div class="col">
        <div class="stat-card" style="--card-gradient: linear-gradient(135deg, #3b82f6, #2563eb);">
            <div class="stat-card-icon"><i class="fas fa-user-graduate"></i></div>
            <div class="stat-card-info">
                <div class="stat-card-number counter" id="classListsTotalStudents" data-target="<?php echo $totalStudents; ?>">0</div>
                <div class="stat-card-label">إجمالي الطلاب</div>
                <div class="stat-card-sub"><i class="fas fa-users"></i> إجمالي المقيدين بالفصول</div>
            </div>
        </div>
    </div>
    <div class="col">
        <div class="stat-card" style="--card-gradient: linear-gradient(135deg, #10b981, #059669);">
            <div class="stat-card-icon"><i class="fas fa-door-open"></i></div>
            <div class="stat-card-info">
                <div class="stat-card-number counter" id="classListsTotalClasses" data-target="<?php echo $totalClasses; ?>">0</div>
                <div class="stat-card-label">عدد الفصول</div>
                <div class="stat-card-sub"><i class="fas fa-school"></i> إجمالي الفصول المتاحة</div>
            </div>
        </div>
    </div>
    <div class="col">
        <div class="stat-card" style="--card-gradient: linear-gradient(135deg, #0ea5e9, #0284c7);">
            <div class="stat-card-icon"><i class="fas fa-mars"></i></div>
            <div class="stat-card-info">
                <div class="stat-card-number counter" id="classListsTotalMale" data-target="<?php echo $totalMale; ?>">0</div>
                <div class="stat-card-label">بنين</div>
                <div class="stat-card-sub"><i class="fas fa-mars"></i> إجمالي الطلاب البنين</div>
            </div>
        </div>
    </div>
    <div class="col">
        <div class="stat-card" style="--card-gradient: linear-gradient(135deg, #ec4899, #db2777);">
            <div class="stat-card-icon"><i class="fas fa-venus"></i></div>
            <div class="stat-card-info">
                <div class="stat-card-number counter" id="classListsTotalFemale" data-target="<?php echo $totalFemale; ?>">0</div>
                <div class="stat-card-label">بنات</div>
                <div class="stat-card-sub"><i class="fas fa-venus"></i> إجمالي الطالبات البنات</div>
            </div>
        </div>
    </div>
</div>

<!-- Tabs Navigation -->
<ul class="nav nav-tabs admin-tabs mb-4 no-print" id="classListsTabs">
    <li class="nav-item">
        <?php
        $listsParams = ['tab' => 'lists'];
        if (!empty($filterStages)) $listsParams['stage_ids'] = $filterStages;
        if (!empty($filterGrades)) $listsParams['grade_ids'] = $filterGrades;
        if (!empty($filterClassesInput)) $listsParams['class_ids'] = $filterClassesInput;
        if (isset($_GET['show_results'])) $listsParams['show_results'] = 1;
        ?>
        <a class="nav-link <?php echo $activeTab === 'lists' ? 'active' : ''; ?>" href="class_lists.php?<?php echo http_build_query($listsParams); ?>">
            <i class="fas fa-list-alt me-2"></i>قوائم الفصول
            <span class="badge rounded-pill bg-primary ms-1"><?php echo $totalClasses; ?></span>
        </a>
    </li>
    <li class="nav-item">
        <a class="nav-link <?php echo $activeTab === 'log' ? 'active' : ''; ?>" href="class_lists.php?tab=log">
            <i class="fas fa-history me-2"></i>سجل العمليات
            <span class="badge rounded-pill bg-primary ms-1"><?php echo $totalTransfersCount; ?></span>
        </a>
    </li>
    <li class="nav-item">
        <a class="nav-link <?php echo $activeTab === 'custom' ? 'active' : ''; ?>" href="class_lists.php?tab=custom" id="customListTabLink">
            <i class="fas fa-user-tag me-2"></i>قوائم مخصصة
            <span class="badge rounded-pill bg-primary ms-1" id="customListsCountBadge">0</span>
        </a>
    </li>
</ul>

<?php if ($activeTab === 'lists'): ?>
<!-- ==================== تبويب القوائم ==================== -->

<!-- فلاتر مدمجة بالتصميم الجديد -->
<form method="GET" class="admin-filter-bar no-print" id="filterForm">
    <input type="hidden" name="show_results" value="1">
    <input type="hidden" name="sort_order" id="sortOrderInput" value="<?php echo htmlspecialchars($sortOrder); ?>">
    <input type="hidden" name="print_layout_lang" id="printLayoutLangInput" value="<?php echo htmlspecialchars($printLayoutLang); ?>">
    <input type="hidden" name="show_print_stats" id="showPrintStatsInput" value="<?php echo $showPrintStats ? '1' : '0'; ?>">
    <input type="hidden" name="show_print_date" id="showPrintDateInput" value="<?php echo $showPrintDate ? '1' : '0'; ?>">
    <div class="admin-filter-controls">
        <!-- Stages Dropdown -->
        <div class="dropdown d-inline-block">
            <button class="btn btn-light dropdown-toggle btn-sm filter-dropdown-btn" type="button" id="stageDropdown" data-bs-toggle="dropdown" data-bs-auto-close="outside" aria-expanded="false">
                <span>المراحل: <span id="selectedStagesLabel" class="fw-bold">الكل</span></span>
            </button>
            <div class="dropdown-menu p-3" aria-labelledby="stageDropdown" style="max-height: 250px; overflow-y: auto; min-width: 200px; text-align: right; box-shadow: 0 10px 15px -3px rgba(0, 0, 0, 0.1);">
                <?php foreach ($stages as $st): ?>
                    <div class="form-check mb-1">
                        <input class="form-check-input stage-checkbox" type="checkbox" name="stage_ids[]" value="<?php echo $st['id']; ?>" id="stage_<?php echo $st['id']; ?>" <?php echo in_array($st['id'], $filterStages) ? 'checked' : ''; ?>>
                        <label class="form-check-label" for="stage_<?php echo $st['id']; ?>"><?php echo htmlspecialchars($st['stage_name']); ?></label>
                    </div>
                <?php endforeach; ?>
            </div>
        </div>

        <!-- Grades Dropdown -->
        <div class="dropdown d-inline-block">
            <button class="btn btn-light dropdown-toggle btn-sm filter-dropdown-btn" type="button" id="gradeDropdown" data-bs-toggle="dropdown" data-bs-auto-close="outside" aria-expanded="false">
                <span>الصفوف: <span id="selectedGradesLabel" class="fw-bold">الكل</span></span>
            </button>
            <div class="dropdown-menu p-3" aria-labelledby="gradeDropdown" style="max-height: 250px; overflow-y: auto; min-width: 220px; text-align: right; box-shadow: 0 10px 15px -3px rgba(0, 0, 0, 0.1);">
                <?php foreach ($grades as $gr): ?>
                    <div class="form-check mb-1 grade-item" data-stage="<?php echo $gr['stage_id']; ?>">
                        <input class="form-check-input grade-checkbox" type="checkbox" name="grade_ids[]" value="<?php echo $gr['id']; ?>" id="grade_<?php echo $gr['id']; ?>" <?php echo in_array($gr['id'], $filterGrades) ? 'checked' : ''; ?>>
                        <label class="form-check-label" for="grade_<?php echo $gr['id']; ?>"><?php echo htmlspecialchars($gr['grade_name']); ?></label>
                    </div>
                <?php endforeach; ?>
            </div>
        </div>

        <!-- Classes Dropdown -->
        <div class="dropdown d-inline-block">
            <button class="btn btn-light dropdown-toggle btn-sm filter-dropdown-btn" type="button" id="classDropdown" data-bs-toggle="dropdown" data-bs-auto-close="outside" aria-expanded="false">
                <span>الفصول: <span id="selectedClassesLabel" class="fw-bold">الكل</span></span>
            </button>
            <div class="dropdown-menu p-3" aria-labelledby="classDropdown" style="max-height: 250px; overflow-y: auto; min-width: 220px; text-align: right; box-shadow: 0 10px 15px -3px rgba(0, 0, 0, 0.1);">
                <?php foreach ($allClasses as $cl): ?>
                    <div class="form-check mb-1 class-item" data-grade="<?php echo $cl['grade_id']; ?>">
                        <input class="form-check-input class-checkbox" type="checkbox" name="class_ids[]" value="<?php echo $cl['id']; ?>" id="class_<?php echo $cl['id']; ?>" <?php echo in_array($cl['id'], $filterClassesInput) ? 'checked' : ''; ?>>
                        <label class="form-check-label" for="class_<?php echo $cl['id']; ?>"><?php echo htmlspecialchars($cl['name']); ?></label>
                    </div>
                <?php endforeach; ?>
            </div>
        </div>
    </div>
    <div class="admin-filter-actions">
        <button type="submit" class="btn btn-primary btn-sm">
            <i class="fas fa-eye me-1"></i>عرض
        </button>
        <a href="class_lists.php" class="btn btn-light btn-sm">
            <i class="fas fa-undo me-1"></i>إعادة تعيين
        </a>
        <button type="button" class="btn btn-light btn-sm <?php echo (!$isFiltered && $activeTab !== 'custom') ? 'd-none' : ''; ?>" id="btnTableSettings" data-bs-toggle="modal" data-bs-target="#tableSettingsModal" title="إعدادات القائمة">
            <i class="fas fa-cog me-1"></i>إعدادات القائمة
        </button>
    </div>
</form>



<!-- حاوية قوائم الطلاب -->
<div id="classListsContainer">
    <?php if ($isFiltered): ?>
        <?php if (empty($filteredClasses)): ?>
            <div class="text-center text-muted py-5 border rounded bg-white mb-4 no-print">
                <i class="fas fa-info-circle fa-3x mb-3 text-muted"></i>
                <h5>لا توجد فصول بالفلاتر المحددة</h5>
            </div>
        <?php else: ?>
            <?php
            $studentsByClassId = $classListStudentQuery->fetchByClassIds(
                $currentAcademicYearId,
                array_column($filteredClasses, 'id'),
                $sortOrder
            );
            foreach ($filteredClasses as $cl):
                $students = $studentsByClassId[(int) $cl['id']] ?? [];
                ?>
                <div class="card shadow mb-4 admin-card-surface" id="class-card-<?php echo $cl['id']; ?>">
                    <div class="card-header bg-primary text-white d-flex justify-content-between align-items-center">
                        <h6 class="mb-0"><i class="fas fa-users me-2"></i><?php echo htmlspecialchars($cl['name']); ?> — <?php echo htmlspecialchars($cl['grade_name']); ?> (<?php echo htmlspecialchars($cl['stage_name']); ?>)</h6>
                        <div class="no-print d-flex align-items-center gap-2">
                            <button type="button" class="btn btn-sm bulk-transfer-btn d-none" data-class-id="<?php echo (int) $cl['id']; ?>" data-grade-id="<?php echo (int) $cl['grade_id']; ?>" data-class-name="<?php echo htmlspecialchars((string) $cl['name'], ENT_QUOTES, 'UTF-8'); ?>">
                                <i class="fas fa-random me-1"></i>نقل جماعي (<span class="bulk-selected-count">0</span>)
                            </button>
                            <span class="badge d-inline-flex align-items-center justify-content-center text-primary-emphasis" style="height: 30px; font-size: 0.8rem; border-radius: 20px; font-weight: 600; padding: 0 12px; margin: 0; background-color: rgba(59, 130, 246, 0.1); color: #1d4ed8; border: none;">
                                <i class="fas fa-male text-primary me-1"></i><?php echo $cl['male_count']; ?>
                            </span>
                            <span class="badge d-inline-flex align-items-center justify-content-center text-danger-emphasis" style="height: 30px; font-size: 0.8rem; border-radius: 20px; font-weight: 600; padding: 0 12px; margin: 0; background-color: rgba(239, 68, 68, 0.1); color: #b91c1c; border: none;">
                                <i class="fas fa-female text-danger me-1"></i><?php echo $cl['female_count']; ?>
                            </span>
                            <span class="badge d-inline-flex align-items-center justify-content-center" style="height: 30px; font-size: 0.8rem; border-radius: 20px; font-weight: 600; padding: 0 12px; margin: 0; background-color: rgba(100, 116, 139, 0.12); color: #475569; border: none;">
                                <i class="fas fa-users text-secondary me-1"></i><?php echo $cl['student_count']; ?>
                            </span>
                        </div>
                    </div>
                    <div class="card-body p-3">
                        <?php if (empty($students)): ?>
                            <div class="text-center text-muted py-4"><i class="fas fa-info-circle me-1"></i>لا يوجد طلاب في هذا فصل</div>
                        <?php else: ?>
                            <div class="table-responsive admin-table-wrap">
                                <table class="table table-hover table-striped mb-0 admin-data-table" id="classStudentsTable_<?php echo $cl['id']; ?>">
                                    <thead class="table-light">
                                        <tr>
                                            <th class="no-print text-center select-all-col" width="35">
                                                <input type="checkbox" class="form-check-input select-all-class-students" style="cursor: pointer;">
                                            </th>
                                            <th class="col-serial-ar" width="50">#</th>
                                            <th class="col-code">كود الطالب</th>
                                            <th class="col-name-ar">اسم الطالب</th>
                                            <th class="text-center col-gender">النوع</th>
                                            <th class="text-center col-gender-en" style="text-align: left !important;">Gender</th>
                                            <th class="col-name-en" style="text-align: left !important;">Student Name</th>
                                            <th class="col-code-en" style="text-align: left !important;">Code</th>
                                            <th class="text-center col-serial-en" width="50">#</th>
                                            <th class="text-center no-print" width="60">نقل</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php $sidx = 0; foreach ($students as $st): $sidx++; ?>
                                            <tr id="student-row-<?php echo $st['id']; ?>">
                                                <td class="no-print text-center select-student-col">
                                                    <input type="checkbox" class="form-check-input select-student-chk" value="<?php echo $st['id']; ?>" data-name="<?php echo htmlspecialchars($st['name']); ?>" data-grade-id="<?php echo $cl['grade_id']; ?>" data-class-id="<?php echo $cl['id']; ?>" data-class-name="<?php echo htmlspecialchars($cl['name']); ?>" style="cursor: pointer;">
                                                </td>
                                                <td class="col-serial-ar"><?php echo classLists_toArabicNumerals($sidx); ?></td>
                                                <td class="col-code"><span class="text-muted"><?php echo htmlspecialchars($st['student_code'] ?? '-'); ?></span></td>
                                                <td class="col-name-ar"><strong><?php echo htmlspecialchars($st['name']); ?></strong></td>
                                                <td class="text-center col-gender">
                                                    <?php if ($st['gender'] === 'male'): ?>
                                                        <span class="badge bg-primary-subtle text-primary px-2 py-1">ذكر</span>
                                                    <?php elseif ($st['gender'] === 'female'): ?>
                                                        <span class="badge bg-danger-subtle text-danger px-2 py-1">أنثى</span>
                                                    <?php else: ?>
                                                        <span class="badge bg-secondary-subtle text-secondary px-2 py-1">-</span>
                                                    <?php endif; ?>
                                                </td>
                                                <td class="text-center col-gender-en" style="text-align: left !important;">
                                                    <?php if ($st['gender'] === 'male'): ?>
                                                        <span class="badge bg-primary-subtle text-primary px-2 py-1">Male</span>
                                                    <?php elseif ($st['gender'] === 'female'): ?>
                                                        <span class="badge bg-danger-subtle text-danger px-2 py-1">Female</span>
                                                    <?php else: ?>
                                                        <span class="badge bg-secondary-subtle text-secondary px-2 py-1">-</span>
                                                    <?php endif; ?>
                                                </td>
                                                <td class="col-name-en" dir="ltr" style="text-align: left !important;"><?php echo htmlspecialchars($st['name_en'] ?? '-'); ?></td>
                                                <td class="col-code-en" dir="ltr" style="text-align: left !important;"><span class="text-muted"><?php echo htmlspecialchars($st['student_code'] ?? '-'); ?></span></td>
                                                <td class="text-center col-serial-en" style="text-align: left !important;"><?php echo $sidx; ?></td>
                                                <td class="text-center no-print">
                                                    <button class="btn btn-action-pills btn-edit transfer-btn"
                                                            data-id="<?php echo $st['id']; ?>"
                                                            data-name="<?php echo htmlspecialchars($st['name']); ?>"
                                                            data-grade-id="<?php echo $cl['grade_id']; ?>"
                                                            data-current-class="<?php echo $cl['id']; ?>"
                                                            data-bs-toggle="tooltip"
                                                            title="نقل">
                                                        <i class="fas fa-exchange-alt"></i>
                                                    </button>
                                                </td>
                                            </tr>
                                        <?php endforeach; ?>
                                    </tbody>
                                </table>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>
            <?php endforeach; ?>
        <?php endif; ?>
    <?php else: ?>
        <div class="text-center text-muted py-5 border rounded bg-white mb-4 no-print">
            <i class="fas fa-filter fa-3x mb-3 text-muted"></i>
            <h5>الرجاء اختيار المرحلة، الصف، أو الفصل</h5>
            <p class="text-muted mb-0">ثم اضغط على زر "عرض" لإظهار قوائم الفصول والطلاب المقيدين فيها.</p>
        </div>
    <?php endif; ?>
</div>


<?php elseif ($activeTab === 'log'): ?>
<!-- ==================== تبويب سجل العمليات ==================== -->

<div class="admin-list-surface">
    <?php if (empty($transferLog)): ?>
        <div class="text-center text-muted py-5 border rounded bg-white">
            <i class="fas fa-inbox fa-2x mb-2 d-block text-muted"></i>
            لا توجد عمليات نقل مسجلة
        </div>
    <?php else: ?>
    <div class="table-responsive admin-table-wrap">
        <table class="table table-hover table-striped align-middle admin-data-table mb-0" id="transferLogTable">
            <thead>
                <tr>
                    <th width="50">#</th>
                    <th>التاريخ</th>
                    <th>الكود</th>
                    <th>اسم الطالب</th>
                    <th>من فصل</th>
                    <th>إلى فصل</th>
                    <th>السبب</th>
                    <th>بواسطة</th>
                </tr>
            </thead>
            <tbody>
                <?php $logIdx = 0; foreach ($transferLog as $log): $logIdx++; ?>
                <tr>
                    <td><?php echo $logIdx; ?></td>
                    <td><?php echo htmlspecialchars($log['transfer_date']); ?></td>
                    <td><span class="badge bg-secondary-subtle text-secondary px-2 py-1"><?php echo htmlspecialchars($log['student_code'] ?? '-'); ?></span></td>
                    <td class="fw-bold"><?php echo htmlspecialchars($log['student_name'] ?? '-'); ?></td>
                    <td>
                        <span class="badge bg-danger-subtle text-danger px-2 py-1"><?php echo htmlspecialchars($log['from_class'] ?? '-'); ?></span>
                        <?php if (!empty($log['from_grade'])): ?>
                            <small class="text-muted d-block mt-1"><?php echo htmlspecialchars($log['from_grade']); ?></small>
                        <?php endif; ?>
                    </td>
                    <td>
                        <span class="badge bg-success-subtle text-success px-2 py-1"><?php echo htmlspecialchars($log['to_class'] ?? '-'); ?></span>
                        <?php if (!empty($log['to_grade'])): ?>
                            <small class="text-muted d-block mt-1"><?php echo htmlspecialchars($log['to_grade']); ?></small>
                        <?php endif; ?>
                    </td>
                    <td><?php echo htmlspecialchars($log['reason'] ?? '-'); ?></td>
                    <td><span class="badge bg-light text-dark border px-2 py-1"><?php echo htmlspecialchars($log['transferred_by_name'] ?? '-'); ?></span></td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
    <?php endif; ?>
</div>

<?php elseif ($activeTab === 'custom'): ?>
<!-- ==================== تبويب قوائم مخصصة ==================== -->
<div class="admin-list-surface" id="customListsContainer">
    <!-- Will be dynamically populated by JS -->
</div>
<?php endif; // end tabs ?>

<!-- Floating Selection Bar for Custom List -->
<div class="selection-bar shadow no-print" id="customListSelectionBar">
    <div class="d-flex align-items-center justify-content-between gap-3 flex-wrap w-100">
        <div class="d-flex align-items-center gap-2">
            <i class="fas fa-check-square fs-5 text-white"></i>
            <span>تم تحديد <strong id="customSelectedCount" class="sel-count">0</strong> طالب/طالبة</span>
        </div>
        <div class="d-flex gap-2">
            <button class="btn btn-light btn-sm selection-bar-action" id="clearCustomSelectionBtn">
                <i class="fas fa-times me-1"></i>إلغاء التحديد
            </button>
            <button class="btn btn-light btn-sm selection-bar-action" id="bulkTransferSelectedBtn">
                <i class="fas fa-random me-1"></i>نقل جماعي
            </button>
            <button class="btn btn-success btn-sm selection-bar-action" id="createCustomListBtn">
                <i class="fas fa-plus me-1"></i>إنشاء قائمة مخصصة
            </button>
        </div>
    </div>
</div>

<!-- Table Settings Modal (إعدادات القائمة) -->
<div class="modal fade no-print" id="tableSettingsModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content admin-modal admin-modal-premium admin-modal-view">
            <div class="modal-header">
                <h5 class="modal-title"><i class="fas fa-cog me-2"></i>إعدادات القائمة</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body p-4" style="font-family: 'Tajawal', sans-serif;">
                <!-- Section 1: Columns Visibility -->
                <div class="mb-4">
                    <p class="mb-3 fw-bold text-dark" style="font-size: 0.95rem; border-bottom: 2px solid #3b82f6; padding-bottom: 6px; display: inline-block; color: #000000;">
                        <i class="fas fa-columns me-1"></i> أعمدة الجدول المعروضة
                    </p>

                    <div class="row row-cols-1 row-cols-md-2 g-3">
                        <!-- 1. Arabic Serial (Right) -->
                        <div class="col">
                            <div class="d-flex align-items-center justify-content-between">
                                <label class="form-check-label fw-bold text-dark mb-0" for="toggleSerialAr" style="font-size: 0.85rem; cursor: pointer; color: #000000;">الترقيم باللغة العربية</label>
                                <div class="form-check form-switch mb-0">
                                    <input class="form-check-input column-toggle" type="checkbox" id="toggleSerialAr" checked style="cursor: pointer;">
                                </div>
                            </div>
                        </div>

                        <!-- 1. English Serial (Left) -->
                        <div class="col">
                            <div class="d-flex align-items-center justify-content-between">
                                <label class="form-check-label fw-bold text-dark mb-0" for="toggleSerialEn" style="font-size: 0.85rem; cursor: pointer; color: #000000;">الترقيم باللغة الإنجليزية</label>
                                <div class="form-check form-switch mb-0">
                                    <input class="form-check-input column-toggle" type="checkbox" id="toggleSerialEn" checked style="cursor: pointer;">
                                </div>
                            </div>
                        </div>

                        <!-- 2. Arabic Code (Right) -->
                        <div class="col">
                            <div class="d-flex align-items-center justify-content-between">
                                <label class="form-check-label fw-bold text-dark mb-0" for="toggleCode" style="font-size: 0.85rem; cursor: pointer; color: #000000;">الكود باللغة العربية</label>
                                <div class="form-check form-switch mb-0">
                                    <input class="form-check-input column-toggle" type="checkbox" id="toggleCode" style="cursor: pointer;">
                                </div>
                            </div>
                        </div>

                        <!-- 2. English Code (Left) -->
                        <div class="col">
                            <div class="d-flex align-items-center justify-content-between">
                                <label class="form-check-label fw-bold text-dark mb-0" for="toggleCodeEn" style="font-size: 0.85rem; cursor: pointer; color: #000000;">الكود باللغة الإنجليزية</label>
                                <div class="form-check form-switch mb-0">
                                    <input class="form-check-input column-toggle" type="checkbox" id="toggleCodeEn" style="cursor: pointer;">
                                </div>
                            </div>
                        </div>

                        <!-- 3. Arabic Name (Right) -->
                        <div class="col">
                            <div class="d-flex align-items-center justify-content-between">
                                <label class="form-check-label fw-bold text-dark mb-0" for="toggleNameAr" style="font-size: 0.85rem; cursor: pointer; color: #000000;">اسم الطالب باللغة العربية</label>
                                <div class="form-check form-switch mb-0">
                                    <input class="form-check-input column-toggle" type="checkbox" id="toggleNameAr" checked style="cursor: pointer;">
                                </div>
                            </div>
                        </div>

                        <!-- 3. English Name (Left) -->
                        <div class="col">
                            <div class="d-flex align-items-center justify-content-between">
                                <label class="form-check-label fw-bold text-dark mb-0" for="toggleNameEn" style="font-size: 0.85rem; cursor: pointer; color: #000000;">الاسم باللغة الإنجليزية</label>
                                <div class="form-check form-switch mb-0">
                                    <input class="form-check-input column-toggle" type="checkbox" id="toggleNameEn" checked style="cursor: pointer;">
                                </div>
                            </div>
                        </div>

                        <!-- 4. Arabic Gender (Right) -->
                        <div class="col">
                            <div class="d-flex align-items-center justify-content-between">
                                <label class="form-check-label fw-bold text-dark mb-0" for="toggleGender" style="font-size: 0.85rem; cursor: pointer; color: #000000;">النوع باللغة العربية</label>
                                <div class="form-check form-switch mb-0">
                                    <input class="form-check-input column-toggle" type="checkbox" id="toggleGender" checked style="cursor: pointer;">
                                </div>
                            </div>
                        </div>

                        <!-- 4. English Gender (Left) -->
                        <div class="col">
                            <div class="d-flex align-items-center justify-content-between">
                                <label class="form-check-label fw-bold text-dark mb-0" for="toggleGenderEn" style="font-size: 0.85rem; cursor: pointer; color: #000000;">النوع باللغة الإنجليزية</label>
                                <div class="form-check form-switch mb-0">
                                    <input class="form-check-input column-toggle" type="checkbox" id="toggleGenderEn" style="cursor: pointer;">
                                </div>
                            </div>
                        </div>

                        <!-- 5. Arabic Class (Right) -->
                        <div class="col" id="toggleClassArContainer" style="display: none;">
                            <div class="d-flex align-items-center justify-content-between">
                                <label class="form-check-label fw-bold text-dark mb-0" for="toggleClassAr" style="font-size: 0.85rem; cursor: pointer; color: #000000;">اسم الفصل باللغة العربية</label>
                                <div class="form-check form-switch mb-0">
                                    <input class="form-check-input column-toggle" type="checkbox" id="toggleClassAr" checked style="cursor: pointer;">
                                </div>
                            </div>
                        </div>

                        <!-- 5. English Class (Left) -->
                        <div class="col" id="toggleClassEnContainer" style="display: none;">
                            <div class="d-flex align-items-center justify-content-between">
                                <label class="form-check-label fw-bold text-dark mb-0" for="toggleClassEn" style="font-size: 0.85rem; cursor: pointer; color: #000000;">اسم الفصل باللغة الإنجليزية</label>
                                <div class="form-check form-switch mb-0">
                                    <input class="form-check-input column-toggle" type="checkbox" id="toggleClassEn" style="cursor: pointer;">
                                </div>
                            </div>
                        </div>
                    </div>
                </div>

                <hr class="my-3">

                <!-- Section 2: Sorting Order -->
                <div class="mb-4">
                    <p class="mb-3 fw-bold text-dark" style="font-size: 0.95rem; border-bottom: 2px solid #3b82f6; padding-bottom: 6px; display: inline-block; color: #000000;">
                        <i class="fas fa-sort-alpha-down me-1"></i> ترتيب الطلاب داخل القائمة
                    </p>
                    <select id="listSortOrder" class="form-select border shadow-sm" style="border-radius: 8px; font-size: 0.9rem; border-color: #cbd5e1; cursor: pointer; background-color: #ffffff;">
                        <option value="ar_alpha" <?php echo $sortOrder === 'ar_alpha' ? 'selected' : ''; ?>>أبجدياً باللغة العربية</option>
                        <option value="en_alpha" <?php echo $sortOrder === 'en_alpha' ? 'selected' : ''; ?>>أبجدياً باللغة الإنجليزية</option>
                        <option value="ar_female_first" <?php echo $sortOrder === 'ar_female_first' ? 'selected' : ''; ?>>أبجدياً باللغة العربية (الإناث أولاً ثم الذكور)</option>
                        <option value="ar_male_first" <?php echo $sortOrder === 'ar_male_first' ? 'selected' : ''; ?>>أبجدياً باللغة العربية (الذكور أولاً ثم الإناث)</option>
                        <option value="en_female_first" <?php echo $sortOrder === 'en_female_first' ? 'selected' : ''; ?>>أبجدياً باللغة الإنجليزية (الإناث أولاً ثم الذكور)</option>
                        <option value="en_male_first" <?php echo $sortOrder === 'en_male_first' ? 'selected' : ''; ?>>أبجدياً باللغة الإنجليزية (الذكور أولاً ثم الإناث)</option>
                    </select>
                </div>

                <hr class="my-3">

                <!-- Section 3: Print Settings -->
                <div class="mb-2">
                    <p class="mb-3 fw-bold text-dark" style="font-size: 0.95rem; border-bottom: 2px solid #3b82f6; padding-bottom: 6px; display: inline-block; color: #000000;">
                        <i class="fas fa-print me-1"></i> إعدادات الطباعة
                    </p>

                    <!-- Toggle Print Stats -->
                    <div class="d-flex align-items-center justify-content-between mb-3">
                        <label class="form-check-label fw-bold text-dark mb-0" for="togglePrintStats" style="font-size: 0.85rem; cursor: pointer; color: #000000;">
                            <i class="fas fa-chart-pie text-secondary me-2"></i>طباعة إحصائيات الطلاب (بنين/بنات/إجمالي)
                        </label>
                        <div class="form-check form-switch mb-0">
                            <input class="form-check-input" type="checkbox" id="togglePrintStats" <?php echo $showPrintStats ? 'checked' : ''; ?> style="cursor: pointer;">
                        </div>
                    </div>

                    <!-- Toggle Print Date -->
                    <div class="d-flex align-items-center justify-content-between mb-3">
                        <label class="form-check-label fw-bold text-dark mb-0" for="togglePrintDate" style="font-size: 0.85rem; cursor: pointer; color: #000000;">
                            <i class="fas fa-calendar-alt text-secondary me-2"></i>إضافة تاريخ الطباعة
                        </label>
                        <div class="form-check form-switch mb-0">
                            <input class="form-check-input" type="checkbox" id="togglePrintDate" <?php echo $showPrintDate ? 'checked' : ''; ?> style="cursor: pointer;">
                        </div>
                    </div>

                    <!-- Print Stage Selection -->
                    <div class="mb-3" id="printStageSelectContainer">
                        <label for="printStageSelect" class="form-label fw-bold text-dark mb-2" style="font-size: 0.85rem; color: #000000;"><i class="fas fa-user-shield me-1"></i> مديرة المرحلة في توقيع الطباعة:</label>
                        <select id="printStageSelect" class="form-select border shadow-sm" style="border-radius: 8px; font-size: 0.9rem; border-color: #cbd5e1; cursor: pointer; background-color: #ffffff;">
                            <option value="auto" <?php echo ($_GET['print_stage_id'] ?? '') === 'auto' || !isset($_GET['print_stage_id']) ? 'selected' : ''; ?>>تحديد تلقائي (حسب مرحلة الطلاب)</option>
                            <?php
                            foreach ($stages as $stg) {
                                $sel = (isset($_GET['print_stage_id']) && (string)$_GET['print_stage_id'] === (string)$stg['id']) ? 'selected' : '';
                                echo '<option value="' . $stg['id'] . '" ' . $sel . '>مديرة ' . htmlspecialchars($stg['stage_name']) . '</option>';
                            }
                            ?>
                            <option value="none" <?php echo ($_GET['print_stage_id'] ?? '') === 'none' ? 'selected' : ''; ?>>عدم طباعة توقيع مديرة المرحلة (مدير المدرسة فقط)</option>
                        </select>
                    </div>

                    <!-- Print Layout Language Select -->
                    <div class="mb-1">
                        <label for="printLayoutLang" class="form-label fw-bold text-dark mb-2" style="font-size: 0.85rem; color: #000000;"><i class="fas fa-language me-1"></i> اللغة المستخدمة في الطباعة (الهيدر والفوتر):</label>
                        <select id="printLayoutLang" class="form-select border shadow-sm" style="border-radius: 8px; font-size: 0.9rem; border-color: #cbd5e1; cursor: pointer; background-color: #ffffff;">
                            <option value="ar" <?php echo $printLayoutLang === 'ar' ? 'selected' : ''; ?>>اللغة العربية</option>
                            <option value="en" <?php echo $printLayoutLang === 'en' ? 'selected' : ''; ?>>اللغة الإنجليزية</option>
                        </select>
                    </div>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">
                    <i class="fas fa-times me-1"></i>إلغاء
                </button>
                <button type="button" class="btn btn-primary" id="btnApplySettings">
                    <i class="fas fa-check me-1"></i>حفظ وتطبيق
                </button>
            </div>
        </div>
    </div>
</div>

<!-- Generic Alert Modal -->
<div class="modal fade no-print" id="genericAlertModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content admin-modal admin-modal-premium admin-modal-view">
            <div class="modal-header">
                <h5 class="modal-title"><i class="fas fa-exclamation-triangle me-2 text-warning"></i>تنبيه</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body text-center p-4">
                <div class="mb-3">
                    <i class="fas fa-exclamation-circle text-warning" style="font-size: 4rem; filter: drop-shadow(0 4px 6px rgba(245, 158, 11, 0.25));"></i>
                </div>
                <h5 class="fw-bold text-dark mb-3" style="font-family: 'Tajawal', sans-serif; font-size: 1.2rem;">تنبيه هام</h5>

                <div class="alert alert-warning border-0 shadow-sm text-start p-3 mx-auto" style="border-radius: 12px; width: 95%; background-color: #fffbeb; border-right: 4px solid #f59e0b !important;">
                    <div style="font-size: 0.9rem; line-height: 1.7; color: #78350f; font-weight: 500; font-family: 'Tajawal', sans-serif; display: flex; align-items: flex-start; gap: 0.5rem;">
                        <i class="fas fa-info-circle text-warning fs-5 mt-1"></i>
                        <span id="genericAlertMessage"></span>
                    </div>
                </div>
            </div>
            <div class="modal-footer justify-content-center border-0 pt-0">
                <button type="button" class="btn btn-secondary px-5 class-lists-ack-btn" data-bs-dismiss="modal">
                    <i class="fas fa-check me-1"></i>حسناً، موافق
                </button>
            </div>
        </div>
    </div>
</div>

<!-- Modal تأكيد حذف القائمة المخصصة -->
<div class="modal fade no-print" id="deleteCustomListModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content admin-modal admin-modal-premium admin-modal-delete">
            <div class="modal-header">
                <h5 class="modal-title"><i class="fas fa-trash-alt me-2"></i>حذف القائمة المخصصة</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body p-4" style="font-family: 'Tajawal', sans-serif;">
                <div class="text-center mb-3">
                    <i class="fas fa-trash-alt text-danger" style="font-size: 3rem;"></i>
                </div>
                <p class="text-center">هل أنت متأكد من رغبتك في حذف القائمة المخصصة <span class="fw-bold text-danger" id="deleteListNameSpan">الحالية</span>؟</p>
                <div class="alert alert-danger">
                    <i class="fas fa-exclamation-triangle me-1"></i>
                    سيتم مسح هذه القائمة من الذاكرة فقط، ولن يؤثر ذلك على تواجد الطلاب في فصولهم الأساسية.
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">إلغاء</button>
                <button type="button" class="btn btn-danger" id="confirmDeleteCustomListBtn">نعم، احذف القائمة</button>
            </div>
        </div>
    </div>
</div>

<!-- Modal إنشاء قائمة مخصصة -->
<div class="modal fade no-print" id="createCustomListModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content admin-modal admin-modal-premium">
            <div class="modal-header">
                <h5 class="modal-title"><i class="fas fa-plus-circle me-2"></i>إنشاء قائمة مخصصة</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body p-4" style="font-family: 'Tajawal', sans-serif;">
                <div class="mb-3">
                    <label for="customListTitleInput" class="form-label fw-bold text-dark">عنوان القائمة المخصصة</label>
                    <input type="text" class="form-control border shadow-sm" id="customListTitleInput" placeholder="مثال: مجموعات التقوية - لغة عربية" style="border-radius: 8px;" required>
                </div>
                <div class="alert alert-info">
                    <i class="fas fa-info-circle me-1"></i>
                    سيتم إنشاء قائمة مخصصة تحتوي على <strong id="modalSelectedCount">0</strong> طالب/طالبة تم تحديدهم.
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">إلغاء</button>
                <button type="button" class="btn btn-primary" id="confirmCreateCustomListBtn">إنشاء القائمة</button>
            </div>
        </div>
    </div>
</div>

<!-- Modal تغيير الفصل -->
<div class="modal fade" id="transferModal" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content admin-modal admin-modal-premium admin-modal-view">
            <div class="modal-header">
                <h5 class="modal-title"><i class="fas fa-exchange-alt me-2"></i>نقل طالب لفصل آخر</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <div class="text-center mb-3">
                    <i class="fas fa-user-graduate text-primary" style="font-size: 3rem;"></i>
                </div>
                <p class="text-center">نقل الطالب <span class="fw-bold text-primary" id="transferStudentName"></span></p>
                <input type="hidden" id="transferStudentId">
                <input type="hidden" id="transferGradeId">
                <input type="hidden" id="transferCurrentClass">
                <div class="mb-3">
                    <label class="form-label fw-bold">اختر الفصل الجديد</label>
                    <select id="transferClassSelect" class="form-select" required>
                        <option value="">-- جاري تحميل الفصول --</option>
                    </select>
                    <div class="form-text"><i class="fas fa-info-circle me-1"></i>يتم عرض فصول نفس الصف الدراسي فقط</div>
                </div>
                <div class="mb-3">
                    <label class="form-label fw-bold">سبب النقل <span class="text-muted fw-normal">(اختياري)</span></label>
                    <input type="text" id="transferReasonInput" class="form-control" placeholder="مثال: رغبة ولي الأمر، تحسين البيئة التعليمية">
                </div>
                <?php if ($isSpecialistPortal): ?>
                    <div class="alert alert-info">
                        <i class="fas fa-hourglass-half me-2"></i>
                        لن يتغير فصل الطالب مباشرة؛ سيظهر الطلب لدى الإدارة في صفحة العمليات المعلقة.
                    </div>
                <?php endif; ?>
                <div id="transferAlert" class="d-none"></div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">
                    <i class="fas fa-times me-1"></i>إلغاء
                </button>
                <button type="button" class="btn btn-primary" id="confirmTransferBtn">
                    <i class="fas <?php echo $isSpecialistPortal ? 'fa-paper-plane' : 'fa-check'; ?> me-1"></i><?php echo $isSpecialistPortal ? 'إرسال للمراجعة' : 'تأكيد النقل'; ?>
                </button>
            </div>
        </div>
    </div>
</div>

<script src="../assets/js/admin_table_actions.js"></script>
<?php require __DIR__ . '/../classes/Presentation/ClassLists/page_scripts.php'; ?>

<?php require_once '../includes/admin_footer.php'; ?>
