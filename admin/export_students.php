<?php
/**
 * صفحة تصدير وطباعة بيانات الطلاب
 * تتيح اختيار الحقول والفلاتر ثم التصدير إلى Excel أو PDF أو الطباعة
 */
$page_title = "تصدير بيانات الطلاب";
$custom_page_title = true;

require_once '../config/database.php';
require_once '../classes/user.php';
require_once '../classes/classroom.php';
require_once '../classes/utilities.php';
require_once '../classes/excel_handler.php';
require_once '../classes/AcademicYear.php';
require_once '../classes/ScopedStaffPortalContext.php';
require_once '../classes/ProfileAttachmentStorage.php';
require_once '../includes/session_config.php';
require_once '../includes/csrf.php';
require_once '../src/Modules/Students/Presentation/StudentExportFieldCatalog.php';
require_once '../src/Modules/Students/Presentation/StudentExportValueFormatter.php';
Utilities::validateSession('admin');

requireCsrfPost();

$database = new Database();
$db = $database->getConnection();
$user = new User($db);
$class = new ClassRoom($db);
$excel_handler = new ExcelHandler();
$attachmentStorage = new ProfileAttachmentStorage();
$currentAcademicYearId = AcademicYear::currentId($db);
$portalContext = new ScopedStaffPortalContext($db, $currentAcademicYearId);
$allowedClassIds = $portalContext->allowedClassIds();
$currentYear = AcademicYear::getCurrent($db);
$yearStart = $currentYear['start_date'] ?? null;
$yearEnd   = $currentYear['end_date']   ?? null;
$octoberReferenceDate = (preg_match('/^\d{4}/', (string) $yearStart, $yearMatch) === 1
    ? $yearMatch[0]
    : date('Y')) . '-10-01';

// تسميات عربية
$genderLabels = ['male' => 'ذكر', 'female' => 'أنثى'];
$religionLabels = ['muslim' => 'مسلم', 'christian' => 'مسيحي', 'other' => 'أخرى'];
$relationshipLabels = [
    'father' => 'أب', 'mother' => 'أم', 'grandfather' => 'جد', 'grandmother' => 'جدة',
    'uncle_paternal' => 'عم', 'aunt_paternal' => 'عمة', 'uncle_maternal' => 'خال',
    'aunt_maternal' => 'خالة', 'brother' => 'أخ', 'sister' => 'أخت', 'legal_guardian' => 'ولي أمر قانوني'
];

// جلب الفصول والمراحل
$allClasses = $db->query("SELECT c.id, c.name, c.grade_id FROM classes c ORDER BY c.name")->fetchAll(PDO::FETCH_ASSOC);
$stages = $db->query("SELECT id, stage_name FROM stages ORDER BY id")->fetchAll(PDO::FETCH_ASSOC);
$grades = $db->query("SELECT id, grade_name, stage_id FROM grades ORDER BY stage_id, id")->fetchAll(PDO::FETCH_ASSOC);
if ($allowedClassIds !== null) {
    $allowedClassMap = array_fill_keys($allowedClassIds, true);
    $allClasses = array_values(array_filter($allClasses, static fn(array $class): bool => isset($allowedClassMap[(int) $class['id']])));
    $allowedGradeIds = array_values(array_unique(array_map(static fn(array $class): int => (int) $class['grade_id'], $allClasses)));
    $allowedGradeMap = array_fill_keys($allowedGradeIds, true);
    $grades = array_values(array_filter($grades, static fn(array $grade): bool => isset($allowedGradeMap[(int) $grade['id']])));
    $allowedStageMap = array_fill_keys(array_map(static fn(array $grade): int => (int) $grade['stage_id'], $grades), true);
    $stages = array_values(array_filter($stages, static fn(array $stage): bool => isset($allowedStageMap[(int) $stage['id']])));
}

// جلب قائمة الطلاب للفلترة بالاسم (مرتبطة بالعام الحالي)
if ($currentAcademicYearId > 0) {
    $studentListSql = "SELECT u.id, u.name as display_name, se.class_id, u.status,
            CASE WHEN se.enrollment_status = 'withdrawn' THEN 'discontinued'
                 ELSE se.enrollment_status END AS enrollment_status,
            se.enrollment_date, c.name as class_name
        FROM users u
        JOIN student_enrollments se ON se.student_id = u.id AND se.academic_year_id = {$currentAcademicYearId}
            AND se.enrollment_status IN ('enrolled', 'graduated', 'transferred', 'discontinued', 'withdrawn')
        LEFT JOIN classes c ON c.id = se.class_id
        WHERE u.role = 'student' AND u.deleted_at IS NULL";
    $studentListParams = [];
    if ($allowedClassIds !== null) {
        if ($allowedClassIds === []) {
            $studentListSql .= ' AND 1 = 0';
        } else {
            $studentListSql .= ' AND se.class_id IN (' . implode(',', array_fill(0, count($allowedClassIds), '?')) . ')';
            $studentListParams = $allowedClassIds;
        }
    }
    $studentListStmt = $db->prepare($studentListSql . ' ORDER BY u.name');
    $studentListStmt->execute($studentListParams);
    $studentList = $studentListStmt->fetchAll(PDO::FETCH_ASSOC);
} else {
    $studentListSql = "SELECT u.id, u.name as display_name, u.class_id, u.status,
            CASE
                WHEN COALESCE(sp.enrollment_status, '') <> '' THEN sp.enrollment_status
                WHEN u.status = 'graduated' THEN 'graduated'
                WHEN u.status = 'transferred' THEN 'transferred'
                ELSE 'enrolled'
            END AS enrollment_status,
            sp.enrollment_date, c.name as class_name
        FROM users u
        LEFT JOIN classes c ON u.class_id = c.id
        LEFT JOIN student_profiles sp ON sp.user_id = u.id
        WHERE u.role = 'student' AND u.deleted_at IS NULL";
    $studentListParams = [];
    if ($allowedClassIds !== null) {
        if ($allowedClassIds === []) {
            $studentListSql .= ' AND 1 = 0';
        } else {
            $studentListSql .= ' AND u.class_id IN (' . implode(',', array_fill(0, count($allowedClassIds), '?')) . ')';
            $studentListParams = $allowedClassIds;
        }
    }
    $studentListStmt = $db->prepare($studentListSql . ' ORDER BY u.name');
    $studentListStmt->execute($studentListParams);
    $studentList = $studentListStmt->fetchAll(PDO::FETCH_ASSOC);
}

// مصدر واحد لتعريف الحقول وتقسيماتها وقيمها الافتراضية.
$columnSections = \EduCore\Modules\Students\Presentation\StudentExportFieldCatalog::sections();
$allFields = \EduCore\Modules\Students\Presentation\StudentExportFieldCatalog::labels();
$defaultFields = \EduCore\Modules\Students\Presentation\StudentExportFieldCatalog::defaultFields();
$selectedFields = [];

// تهيئة متغيرات الفلاتر لتكون متاحة دائماً سواء في طلب GET أو POST
$normalizeExportIds = static function ($values): array {
    if (!is_array($values)) return [];
    $ids = [];
    foreach ($values as $value) {
        if (!is_scalar($value)) continue;
        $id = (int) $value;
        if ($id > 0) $ids[$id] = $id;
    }
    return array_values($ids);
};
$filterStages = isset($_POST['stage_ids']) && is_array($_POST['stage_ids'])
    ? $normalizeExportIds($_POST['stage_ids'])
    : [];
$filterGrades = isset($_POST['grade_ids']) && is_array($_POST['grade_ids'])
    ? $normalizeExportIds($_POST['grade_ids'])
    : [];
$filterClasses = isset($_POST['class_ids']) && is_array($_POST['class_ids'])
    ? $normalizeExportIds($_POST['class_ids'])
    : [];
if ($allowedClassIds !== null) {
    $filterClasses = $filterClasses === [] ? [] : array_values(array_intersect($filterClasses, $allowedClassIds));
}
$filterStudentIds = isset($_POST['filter_student_ids']) && is_array($_POST['filter_student_ids'])
    ? $normalizeExportIds($_POST['filter_student_ids'])
    : [];

// فلتر حالة القيد (الحالات الفعلية + المنقولون إلى المدرسة خلال العام)
$_allowed_statuses = ['enrolled', 'graduated', 'transferred', 'discontinued', 'transferred_in'];
$filterEnrollmentStatuses = isset($_POST['enrollment_statuses']) && is_array($_POST['enrollment_statuses'])
    ? array_values(array_filter($_POST['enrollment_statuses'], fn($s) => in_array($s, $_allowed_statuses, true)))
    : [];

// ===== معالجة طلب التصدير =====
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['do_export'])) {
    // بناء الاستعلام بناءً على الفلاتر (مرتبطة بالعام الحالي)
    $where = ["u.role = 'student'", "u.deleted_at IS NULL"];
    $params = [];
    $_useYear = $currentAcademicYearId > 0;
    if ($allowedClassIds !== null) {
        if ($allowedClassIds === []) {
            $where[] = '1 = 0';
        } else {
            $placeholders = implode(',', array_fill(0, count($allowedClassIds), '?'));
            $where[] = $_useYear ? "se.class_id IN ($placeholders)" : "u.class_id IN ($placeholders)";
            $params = array_merge($params, $allowedClassIds);
        }
    }

    if (!empty($filterStages)) {
        $placeholders = implode(',', array_fill(0, count($filterStages), '?'));
        $where[] = $_useYear ? "se.stage_id IN ($placeholders)" : "g.stage_id IN ($placeholders)";
        $params = array_merge($params, $filterStages);
    }
    if (!empty($filterGrades)) {
        $placeholders = implode(',', array_fill(0, count($filterGrades), '?'));
        $where[] = $_useYear ? "se.grade_id IN ($placeholders)" : "c.grade_id IN ($placeholders)";
        $params = array_merge($params, $filterGrades);
    }
    if (!empty($filterClasses)) {
        $placeholders = implode(',', array_fill(0, count($filterClasses), '?'));
        $where[] = $_useYear ? "se.class_id IN ($placeholders)" : "u.class_id IN ($placeholders)";
        $params = array_merge($params, $filterClasses);
    }
    if (!empty($filterStudentIds)) {
        foreach ($filterStudentIds as $filterStudentId) {
            $portalContext->assertStudentAllowed((int) $filterStudentId);
        }
        $placeholders = implode(',', array_fill(0, count($filterStudentIds), '?'));
        $where[] = "u.id IN ($placeholders)";
        $params = array_merge($params, $filterStudentIds);
    }



    $selectedFields = \EduCore\Modules\Students\Presentation\StudentExportFieldCatalog::canonicalize(
        $_POST['fields'] ?? null
    );
    $submittedFormat = is_string($_POST['export_format'] ?? null) ? $_POST['export_format'] : 'preview';
    $exportFormat = in_array($submittedFormat, ['preview', 'excel', 'pdf'], true) ? $submittedFormat : 'preview';

    // تحديد ما إذا كنا نحتاج بيانات ولي الأمر
    $guardianFieldsList = \EduCore\Modules\Students\Presentation\StudentExportFieldCatalog::guardianFields();
    $needGuardian = !empty(array_intersect($selectedFields, $guardianFieldsList));
    $_enrollmentStatusSql = $_useYear
        ? "CASE WHEN se.enrollment_status = 'withdrawn' THEN 'discontinued'
                ELSE COALESCE(se.enrollment_status, 'enrolled') END"
        : "CASE
               WHEN COALESCE(sp.enrollment_status, '') <> '' THEN sp.enrollment_status
               WHEN u.status = 'graduated' THEN 'graduated'
               WHEN u.status = 'transferred' THEN 'transferred'
               ELSE 'enrolled'
           END";
    $_academicStatusSql = $_useYear
        ? "COALESCE(se.academic_status, 'new')"
        : "CASE
               WHEN COALESCE(sp.enrollment_status, '') = 'graduated' OR u.status = 'graduated' THEN 'graduated'
               ELSE 'new'
           END";

    $query = "SELECT u.id, u.name, u.class_id, u.status,
                     c.name as class_name, g.grade_name, st.stage_name,
                     {$_enrollmentStatusSql} as enrollment_status,
                     {$_academicStatusSql} as academic_status,
                     sp.student_code,
                     sp.first_name_ar, sp.second_name_ar, sp.third_name_ar, sp.fourth_name_ar, sp.family_name_ar,
                     sp.first_name_en, sp.second_name_en, sp.third_name_en, sp.fourth_name_en, sp.family_name_en,
                     sp.national_id, sp.birth_date, sp.birth_place, sp.gender, sp.religion,
                     sp.city_area, sp.address_current, sp.phone_home, sp.phone_emergency,
                     sp.enrollment_date, sp.previous_school, sp.blood_type,
                     sp.health_status, sp.chronic_diseases, sp.allergies, sp.disabilities,
                     sp.medications, sp.insurance_number, sp.passport_number, sp.nationality,
                     sp.ministry_code, sp.extra_phones, sp.extra_data, sp.notes, sp.phone_mobile,
                     sp.insurance_start_date, sp.insurance_end_date, sp.treatment_plan,
                     sp.previous_medical_reports, sp.emergency_medical_notes, sp.psychological_notes,
                     setr.destination AS transfer_destination,
                     setr.transfer_date AS external_transfer_date,
                     setr.reason AS external_transfer_reason,
                     setr.notes AS external_transfer_notes,
                     (SELECT GROUP_CONCAT(sib_u.name ORDER BY sib_u.name SEPARATOR '، ') FROM student_siblings ss_info JOIN users sib_u ON sib_u.id = ss_info.sibling_id AND sib_u.deleted_at IS NULL WHERE ss_info.student_id = u.id) AS siblings,
                     (SELECT GROUP_CONCAT(CONCAT(COALESCE(kt.name, 'صلة قرابة'), ': ', rel_u.name, IF(COALESCE(sk.notes, '') <> '', CONCAT(' (', sk.notes, ')'), '')) ORDER BY kt.name, rel_u.name SEPARATOR '، ')
                        FROM student_kinships sk
                        JOIN users rel_u ON rel_u.id = sk.relative_id AND rel_u.deleted_at IS NULL
                        LEFT JOIN kinship_types kt ON kt.id = sk.kinship_type_id
                        WHERE sk.student_id = u.id) AS kinships";

    // بناء قيود حالة القيد. transferred_in = مقيدون تاريخ قيدهم داخل نطاق العام الدراسي الحالي.
    $_hasTranIn    = in_array('transferred_in', $filterEnrollmentStatuses, true);
    $_realStatuses = array_values(array_filter($filterEnrollmentStatuses, fn($s) => $s !== 'transferred_in'));
    $_enrollmentDateSql = $_useYear ? 'se.enrollment_date' : 'sp.enrollment_date';

    $_classJoin = $_useYear
        ? "JOIN student_enrollments se ON se.student_id = u.id AND se.academic_year_id = {$currentAcademicYearId}
           LEFT JOIN classes c ON c.id = se.class_id"
        : "LEFT JOIN classes c ON u.class_id = c.id";

    if (empty($filterEnrollmentStatuses)) {
        // لا اختيار: اعرض جميع حالات القيد الرئيسية.
        $where[] = "{$_enrollmentStatusSql} IN (?,?,?,?)";
        $params = array_merge($params, ['enrolled', 'graduated', 'transferred', 'discontinued']);
    } elseif ($_hasTranIn && empty($_realStatuses)) {
        // "منقول إلى" فقط: مقيدون تاريخ قيدهم داخل العام الحالي
        $where[] = "{$_enrollmentStatusSql} = 'enrolled'";
        if ($yearStart && $yearEnd) {
            $where[] = "{$_enrollmentDateSql} IS NOT NULL AND {$_enrollmentDateSql} >= ? AND {$_enrollmentDateSql} <= ?";
            array_push($params, $yearStart, $yearEnd);
        } elseif ($yearStart) {
            $where[] = "{$_enrollmentDateSql} IS NOT NULL AND {$_enrollmentDateSql} >= ?";
            $params[] = $yearStart;
        } else {
            $where[] = "{$_enrollmentDateSql} IS NOT NULL";
        }
    } elseif ($_hasTranIn && !empty($_realStatuses)) {
        // "منقول إلى" + حالات أخرى
        $_enrollPlaceholders = implode(',', array_fill(0, count($_realStatuses), '?'));
        $params = array_merge($params, $_realStatuses);
        $_tranInDate = "{$_enrollmentDateSql} IS NOT NULL";
        if ($yearStart && $yearEnd) {
            $_tranInDate .= " AND {$_enrollmentDateSql} >= ? AND {$_enrollmentDateSql} <= ?";
            array_push($params, $yearStart, $yearEnd);
        } elseif ($yearStart) {
            $_tranInDate .= " AND {$_enrollmentDateSql} >= ?";
            $params[] = $yearStart;
        }
        $where[] = "({$_enrollmentStatusSql} IN ($_enrollPlaceholders)
            OR ({$_enrollmentStatusSql} = 'enrolled' AND {$_tranInDate}))";
    } else {
        // حالات حقيقية فقط
        $_enrollPlaceholders = implode(',', array_fill(0, count($_realStatuses), '?'));
        $where[] = "{$_enrollmentStatusSql} IN ($_enrollPlaceholders)";
        $params = array_merge($params, $_realStatuses);
    }

    $whereSQL = implode(' AND ', $where);

    $query .= " FROM users u
              {$_classJoin}
              LEFT JOIN grades g ON c.grade_id = g.id
              LEFT JOIN stages st ON g.stage_id = st.id
              LEFT JOIN student_profiles sp ON u.id = sp.user_id
              LEFT JOIN student_external_transfers setr ON setr.student_id = u.id
              WHERE $whereSQL
              ORDER BY st.id, g.id, c.name, u.name";

    $stmt = $db->prepare($query);
    $stmt->execute($params);
    $studentsData = $stmt->fetchAll(PDO::FETCH_ASSOC);
    $studentIds = array_values(array_map('intval', array_column($studentsData, 'id')));

    // المرفقات: نظهر أسماء العرض، ونحتفظ بمسار الصورة داخلياً فقط لتضمينها في Excel.
    if ($studentIds !== [] && array_intersect($selectedFields, ['profile_image', 'attachments'])) {
        $placeholders = implode(',', array_fill(0, count($studentIds), '?'));
        $attachmentStmt = $db->prepare(
            "SELECT id, user_id, label, file_name,
                    COALESCE(NULLIF(original_name, ''), label, 'مرفق') AS display_name
             FROM student_attachments
             WHERE user_id IN ($placeholders)
             ORDER BY user_id, uploaded_at, id"
        );
        $attachmentStmt->execute($studentIds);
        $attachmentMap = [];
        while ($attachment = $attachmentStmt->fetch(PDO::FETCH_ASSOC)) {
            $studentId = (int) $attachment['user_id'];
            $label = trim((string) ($attachment['label'] ?? '')) ?: 'مرفق';
            $displayName = trim((string) ($attachment['display_name'] ?? ''));
            if ($displayName === '') {
                continue;
            }
            $attachmentMap[$studentId]['items'][] = $displayName;
            if ($label === 'الصورة الشخصية' && empty($attachmentMap[$studentId]['profile_image_id'])) {
                $attachmentMap[$studentId]['profile_image_id'] = (int) $attachment['id'];
                $attachmentMap[$studentId]['profile_image_path'] = $attachmentStorage->absolutePath(
                    'student',
                    (string) ($attachment['file_name'] ?? '')
                );
            }
        }
        foreach ($studentsData as &$student) {
            $studentId = (int) $student['id'];
            $student['profile_image_id'] = $attachmentMap[$studentId]['profile_image_id'] ?? null;
            $student['profile_image_path'] = $attachmentMap[$studentId]['profile_image_path'] ?? null;
            $student['attachments'] = !empty($attachmentMap[$studentId]['items'])
                ? implode(' | ', $attachmentMap[$studentId]['items'])
                : null;
        }
        unset($student);
    }

    // المسار الدراسي يُجلب كسجلات مستقلة لتجنب اقتطاع GROUP_CONCAT للتاريخ الطويل.
    if ($studentIds !== [] && in_array('academic_history', $selectedFields, true)) {
        $placeholders = implode(',', array_fill(0, count($studentIds), '?'));
        $historyStmt = $db->prepare(
            "SELECT se.student_id, ay.name AS academic_year, se.enrollment_status, se.academic_status,
                    st.stage_name, g.grade_name, c.name AS class_name, se.enrollment_date
             FROM student_enrollments se
             LEFT JOIN academic_years ay ON ay.id = se.academic_year_id
             LEFT JOIN stages st ON st.id = se.stage_id
             LEFT JOIN grades g ON g.id = se.grade_id
             LEFT JOIN classes c ON c.id = se.class_id
             WHERE se.student_id IN ($placeholders)
             ORDER BY se.student_id, ay.start_date, se.id"
        );
        $historyStmt->execute($studentIds);
        $historyMap = [];
        $enrollmentLabels = [
            'enrolled' => 'مقيد', 'transferred' => 'منقول خارج المدرسة',
            'discontinued' => 'منقطع', 'withdrawn' => 'منسحب', 'graduated' => 'خريج',
        ];
        $academicLabels = [
            'new' => 'مستجد', 'promoted' => 'ناجح ومنقول',
            'retained' => 'راسب', 'graduated' => 'خريج', 'pending' => 'قيد المراجعة',
        ];
        while ($history = $historyStmt->fetch(PDO::FETCH_ASSOC)) {
            $parts = [
                trim((string) ($history['academic_year'] ?? '')) ?: 'عام غير محدد',
                $enrollmentLabels[$history['enrollment_status'] ?? ''] ?? (string) ($history['enrollment_status'] ?? '-'),
                $academicLabels[$history['academic_status'] ?? ''] ?? (string) ($history['academic_status'] ?? '-'),
            ];
            foreach (['stage_name', 'grade_name', 'class_name'] as $historyField) {
                $value = trim((string) ($history[$historyField] ?? ''));
                if ($value !== '') {
                    $parts[] = $value;
                }
            }
            if (!empty($history['enrollment_date'])) {
                $parts[] = 'القيد: ' . $history['enrollment_date'];
            }
            $historyMap[(int) $history['student_id']][] = implode(' - ', $parts);
        }
        foreach ($studentsData as &$student) {
            $student['academic_history'] = !empty($historyMap[(int) $student['id']])
                ? implode(' | ', $historyMap[(int) $student['id']])
                : null;
        }
        unset($student);
    }

    // جلب بيانات أولياء الأمور إذا لزم الأمر
    $guardiansMap = [];
    if ($needGuardian && !empty($studentsData)) {
        $placeholders = implode(',', array_fill(0, count($studentIds), '?'));
        $gStmt = $db->prepare(
            "SELECT * FROM student_guardians
             WHERE student_id IN ($placeholders)
             ORDER BY student_id, id"
        );
        $gStmt->execute($studentIds);
        while ($g = $gStmt->fetch(PDO::FETCH_ASSOC)) {
            $sid = $g['student_id'];
            if (!isset($guardiansMap[$sid])) {
                $guardiansMap[$sid] = ['father' => [], 'mother' => [], 'others' => []];
            }
            $rel = trim((string)($g['relationship'] ?? ''));
            if ($rel === 'father' && $guardiansMap[$sid]['father'] === []) {
                $guardiansMap[$sid]['father'] = $g;
            } elseif ($rel === 'mother' && $guardiansMap[$sid]['mother'] === []) {
                $guardiansMap[$sid]['mother'] = $g;
            } else {
                $guardiansMap[$sid]['others'][] = $g;
            }
        }
    }

    // دالة تنسيق القيم
    function formatStudentValue($field, $student, $guardian, $genderLabels, $religionLabels, $relationshipLabels, $octoberReferenceDate = null) {
        if (is_string($field) && array_key_exists(
            $field,
            \EduCore\Modules\Students\Presentation\StudentExportFieldCatalog::labels()
        )) {
            return \EduCore\Modules\Students\Presentation\StudentExportValueFormatter::format(
                $field,
                is_array($student) ? $student : [],
                is_array($guardian) ? $guardian : [],
                is_string($octoberReferenceDate) ? $octoberReferenceDate : null
            );
        }
        switch ($field) {
            // Student basic and personal
            case 'student_code': return $student['student_code'] ?? '-';
            case 'full_name_ar':
                $parts = array_filter([$student['first_name_ar'] ?? '', $student['second_name_ar'] ?? '', $student['third_name_ar'] ?? '', $student['fourth_name_ar'] ?? '', $student['family_name_ar'] ?? '']);
                return !empty($parts) ? implode(' ', $parts) : ($student['name'] ?? '-');
            case 'full_name_en':
                $parts = array_filter([$student['first_name_en'] ?? '', $student['second_name_en'] ?? '', $student['third_name_en'] ?? '', $student['fourth_name_en'] ?? '', $student['family_name_en'] ?? '']);
                return !empty($parts) ? implode(' ', $parts) : '-';
            case 'username': return $student['username'] ?? '-';
            case 'class_name': return $student['class_name'] ?? '-';
            case 'grade_name': return $student['grade_name'] ?? '-';
            case 'stage_name': return $student['stage_name'] ?? '-';
            case 'enrollment_date': return $student['enrollment_date'] ?? '-';
            case 'previous_school': return $student['previous_school'] ?? '-';
            case 'status':
                $enroll = $student['enrollment_status'] ?? '';
                if ($enroll === 'enrolled') return 'مقيد';
                if ($enroll === 'graduated') return 'خريج';
                if ($enroll === 'transferred') return 'منقول';
                // fallback إلى حالة الحساب
                $s = $student['status'] ?? '';
                if ($s === 'active') return 'نشط';
                if ($s === 'graduated') return 'خريج';
                return 'معطل';
            case 'national_id': return $student['national_id'] ?? '-';
            case 'birth_date': return $student['birth_date'] ?? '-';
            case 'birth_place': return $student['birth_place'] ?? '-';
            case 'gender': return $genderLabels[$student['gender'] ?? ''] ?? '-';
            case 'religion': return $religionLabels[$student['religion'] ?? ''] ?? '-';
            case 'age_display':
                $y = $student['age_years'] ?? null;
                if ($y === null) return '-';
                return $y . ' سنة ' . ($student['age_months'] ?? 0) . ' شهر';
            case 'age_october':
                $bd = $student['birth_date'] ?? '';
                if (empty($bd)) return '-';
                try {
                    $birthDate = new DateTime($bd);
                    $octoberFirst = new DateTime(date('Y') . '-10-01');
                    if ($birthDate > $octoberFirst) {
                        return 'لم يولد بعد';
                    }
                    $diff = $birthDate->diff($octoberFirst);
                    return $diff->y . ' سنة ' . $diff->m . ' شهر ' . $diff->d . ' يوم';
                } catch (Exception $e) {
                    return '-';
                }
            case 'blood_type': return $student['blood_type'] ?? '-';
            case 'passport': return $student['passport_number'] ?? '-';
            case 'nationality': return $student['nationality'] ?? '-';
            case 'ministry_code': return $student['ministry_code'] ?? '-';
            case 'educational_guardianship':
                $val = '-';
                if (!empty($student['extra_data'])) {
                    $items = json_decode($student['extra_data'], true);
                    if (is_array($items)) {
                        foreach ($items as $item) {
                            if (in_array($item['label'] ?? '', ['__educational_guardianship', 'الوصاية التعليمية'], true)) {
                                $val = (string)($item['value'] ?? '-');
                                break;
                            }
                        }
                    }
                }
                return $relationshipLabels[$val] ?? $val;
            case 'notes': return $student['notes'] ?? '-';
            case 'city_area': return $student['city_area'] ?? '-';
            case 'address_current': return $student['address_current'] ?? '-';
            case 'phone_home': return $student['phone_home'] ?? '-';
            case 'phone_emergency': return $student['phone_emergency'] ?? '-';
            case 'phone_mobile': return $student['phone_mobile'] ?? '-';
            case 'transfer_reason': return $student['transfer_reason'] ?? '-';
            case 'transfer_destination': return $student['transfer_destination'] ?? '-';
            case 'transfer_date': return $student['transfer_date'] ?? '-';


            // Father fields
            case 'father_name':
                $fname = $guardian['father']['guardian_name'] ?? '';
                if (empty($fname)) {
                    $parts = array_filter([$student['second_name_ar'] ?? '', $student['third_name_ar'] ?? '', $student['fourth_name_ar'] ?? '', $student['family_name_ar'] ?? '']);
                    $fname = implode(' ', $parts);
                }
                return !empty($fname) ? $fname : '-';
            case 'father_relationship': return 'أب';
            case 'father_birth_date': return $guardian['father']['birth_date'] ?? '-';
            case 'father_birth_place': return $guardian['father']['birth_place'] ?? '-';
            case 'father_religion': return $religionLabels[$guardian['father']['religion'] ?? ''] ?? '-';
            case 'father_nationality': return $guardian['father']['nationality'] ?? '-';
            case 'father_national_id': return $guardian['father']['national_id'] ?? '-';
            case 'father_passport': return $guardian['father']['passport_number'] ?? '-';
            case 'father_mobile': return $guardian['father']['phone_primary'] ?? '-';
            case 'father_landline': return $guardian['father']['phone_landline'] ?? '-';
            case 'father_email': return $guardian['father']['email'] ?? '-';
            case 'father_address': return $guardian['father']['address'] ?? '-';
            case 'father_qualification': return $guardian['father']['qualification'] ?? '-';
            case 'father_job': return $guardian['father']['job_title'] ?? '-';
            case 'father_employer': return $guardian['father']['employer'] ?? '-';
            case 'father_work_phone': return $guardian['father']['work_phone'] ?? '-';

            // Mother fields
            case 'mother_name': return $guardian['mother']['guardian_name'] ?? '-';
            case 'mother_relationship': return 'أم';
            case 'mother_birth_date': return $guardian['mother']['birth_date'] ?? '-';
            case 'mother_birth_place': return $guardian['mother']['birth_place'] ?? '-';
            case 'mother_religion': return $religionLabels[$guardian['mother']['religion'] ?? ''] ?? '-';
            case 'mother_nationality': return $guardian['mother']['nationality'] ?? '-';
            case 'mother_national_id': return $guardian['mother']['national_id'] ?? '-';
            case 'mother_passport': return $guardian['mother']['passport_number'] ?? '-';
            case 'mother_mobile': return $guardian['mother']['phone_primary'] ?? '-';
            case 'mother_landline': return $guardian['mother']['phone_landline'] ?? '-';
            case 'mother_email': return $guardian['mother']['email'] ?? '-';
            case 'mother_address': return $guardian['mother']['address'] ?? '-';
            case 'mother_qualification': return $guardian['mother']['qualification'] ?? '-';
            case 'mother_job': return $guardian['mother']['job_title'] ?? '-';
            case 'mother_employer': return $guardian['mother']['employer'] ?? '-';
            case 'mother_work_phone': return $guardian['mother']['work_phone'] ?? '-';

            // Health fields
            case 'health_status': return $student['health_status'] ?? '-';
            case 'chronic_diseases': return $student['chronic_diseases'] ?? '-';
            case 'allergies': return $student['allergies'] ?? '-';
            case 'disabilities': return $student['disabilities'] ?? '-';
            case 'medications': return $student['medications'] ?? '-';
            case 'insurance_number': return $student['insurance_number'] ?? '-';
            case 'insurance_start': return $student['insurance_start_date'] ?? '-';
            case 'insurance_end': return $student['insurance_end_date'] ?? '-';
            case 'treatment': return $student['treatment_plan'] ?? '-';
            case 'medical_reports': return $student['previous_medical_reports'] ?? '-';
            case 'emergency_notes': return $student['emergency_medical_notes'] ?? '-';
            case 'psychological_notes': return $student['psychological_notes'] ?? '-';

            // Siblings
            case 'siblings': return $student['siblings'] ?? '-';

            // Profile image
            case 'profile_image': return !empty($student['profile_image_id']) ? 'صورة مرفقة' : '-';
            default: return '-';
        }
    }

    function exportStudentSpreadsheetValue($value) {
        if (is_string($value) && preg_match('/^\s*[=+\-@]/u', $value)) {
            return "'" . $value;
        }
        return $value;
    }

    // إذا كان التصدير Excel
    if ($exportFormat === 'excel' && !empty($selectedFields)) {
        if (ob_get_level() > 0) ob_clean();
        $data = [];
        $spreadsheetImages = [];
        $headers = [];
        foreach ($selectedFields as $f) {
            if (isset($allFields[$f])) $headers[] = $allFields[$f];
        }
        $data[] = $headers;
        foreach ($studentsData as $s) {
            $guardian = $guardiansMap[$s['id']] ?? [];
            $row = [];
            foreach ($selectedFields as $f) {
                if ($f === 'profile_image') {
                    $row[] = !empty($s['profile_image_path']) ? '' : '-';
                    if (!empty($s['profile_image_path'])) {
                        $spreadsheetImages[] = [
                            'row' => count($data) + 1,
                            'column' => count($row),
                            'path' => $s['profile_image_path'],
                            'name' => 'الصورة الشخصية',
                            'description' => 'الصورة الشخصية للطالب',
                        ];
                    }
                } else {
                    $row[] = exportStudentSpreadsheetValue(
                        formatStudentValue($f, $s, $guardian, $genderLabels, $religionLabels, $relationshipLabels, $octoberReferenceDate)
                    );
                }
            }
            $data[] = $row;
        }
        $filepath = $excel_handler->exportToExcel($data, 'تقرير_الطلاب', $spreadsheetImages);
        if ($filepath && file_exists($filepath)) {
            if (ob_get_level() > 0) ob_clean();
            $ext = pathinfo($filepath, PATHINFO_EXTENSION);
            if ($ext === 'xlsx') {
                header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
                header('Content-Disposition: attachment; filename="تقرير_الطلاب_' . date('Y-m-d') . '.xlsx"');
            } else {
                header('Content-Type: text/csv; charset=utf-8');
                header('Content-Disposition: attachment; filename="تقرير_الطلاب_' . date('Y-m-d') . '.csv"');
            }
            header('Content-Length: ' . filesize($filepath));
            header('Cache-Control: must-revalidate');
            readfile($filepath);
            unlink($filepath);
            exit;
        }
    }

    // إذا كان التصدير PDF
    if ($exportFormat === 'pdf' && !empty($selectedFields)) {
        $showPrintView = true;
    }
}
$checkedFields = $_SERVER['REQUEST_METHOD'] === 'POST' ? $selectedFields : $defaultFields;

// جلب إحصائيات سريعة من نفس نطاق العام/الفصول المستخدم في التصدير.
$statsParams = [];
$statsWhere = ["u.role = 'student'", 'u.deleted_at IS NULL'];
if ($currentAcademicYearId > 0) {
    $statsJoin = 'JOIN student_enrollments se ON se.student_id = u.id AND se.academic_year_id = ?';
    $statsParams[] = $currentAcademicYearId;
    $statsClassColumn = 'se.class_id';
} else {
    $statsJoin = '';
    $statsClassColumn = 'u.class_id';
}
if ($allowedClassIds !== null) {
    if ($allowedClassIds === []) {
        $statsWhere[] = '1 = 0';
    } else {
        $statsWhere[] = $statsClassColumn . ' IN (' . implode(',', array_fill(0, count($allowedClassIds), '?')) . ')';
        $statsParams = array_merge($statsParams, $allowedClassIds);
    }
}
$studentCountsStmt = $db->prepare("SELECT
    COUNT(*) AS total,
    SUM(CASE WHEN u.status = 'active' THEN 1 ELSE 0 END) AS active_count,
    SUM(CASE WHEN u.status <> 'active' THEN 1 ELSE 0 END) AS inactive_count,
    COUNT(DISTINCT {$statsClassColumn}) AS class_count
    FROM users u {$statsJoin}
    WHERE " . implode(' AND ', $statsWhere));
$studentCountsStmt->execute($statsParams);
$studentCounts = $studentCountsStmt->fetch(PDO::FETCH_ASSOC) ?: [
    'total' => 0,
    'active_count' => 0,
    'inactive_count' => 0,
    'class_count' => 0,
];

require_once '../includes/admin_header.php';
?>

<form method="POST" action="" id="exportForm" data-no-form-safety="true">
    <?php echo csrfField(); ?>
<input type="hidden" name="do_export" value="1">
<input type="hidden" name="export_format" id="exportFormatInput" value="preview">

<!-- Page Header -->
<div class="admin-page-heading no-print">
    <h1 class="h2"><i class="fas fa-file-export me-2"></i>تصدير وطباعة بيانات الطلاب</h1>
    <div class="btn-toolbar admin-top-actions gap-2">
        <button type="submit" class="btn btn-header-premium btn-primary shadow-sm" onclick="document.getElementById('exportFormatInput').value='preview'">
            <i class="fas fa-eye me-1"></i>معاينة البيانات
        </button>
        <button type="submit" class="btn btn-header-premium btn-export-soft" onclick="document.getElementById('exportFormatInput').value='excel'" style="border: 1.5px solid #86efac !important; background-color: #e2f1e9 !important; color: #134e4a !important;">
            <i class="fas fa-file-excel me-1"></i>تصدير Excel
        </button>
        <button type="submit" class="btn btn-header-premium btn-pdf-soft" onclick="document.getElementById('exportFormatInput').value='pdf'" style="border: 1.5px solid #fca5a5 !important; background-color: #fee2e2 !important; color: #dc2626 !important;">
            <i class="fas fa-file-pdf me-1"></i>PDF
        </button>
        <button type="submit" class="btn btn-header-premium btn-print-soft" onclick="document.getElementById('exportFormatInput').value='pdf'" style="border: 1.5px solid #94a3b8 !important; background-color: #f1f5f9 !important; color: #475569 !important;">
            <i class="fas fa-print me-1"></i>طباعة
        </button>
    </div>
</div>

<!-- إحصائيات سريعة -->
<div class="row row-cols-2 row-cols-md-4 g-3 mb-4 no-print">
    <div class="col">
        <div class="stat-card" style="--card-gradient: linear-gradient(135deg, #3b82f6, #2563eb);">
            <div class="stat-card-icon"><i class="fas fa-users"></i></div>
            <div class="stat-card-info">
                <div class="stat-card-number counter" data-target="<?php echo (int)$studentCounts['total']; ?>">0</div>
                <div class="stat-card-label">إجمالي الطلاب</div>
            </div>
        </div>
    </div>
    <div class="col">
        <div class="stat-card" style="--card-gradient: linear-gradient(135deg, #10b981, #059669);">
            <div class="stat-card-icon"><i class="fas fa-user-check"></i></div>
            <div class="stat-card-info">
                <div class="stat-card-number counter" data-target="<?php echo (int)$studentCounts['active_count']; ?>">0</div>
                <div class="stat-card-label">طالب نشط</div>
            </div>
        </div>
    </div>
    <div class="col">
        <div class="stat-card" style="--card-gradient: linear-gradient(135deg, #ef4444, #dc2626);">
            <div class="stat-card-icon"><i class="fas fa-user-times"></i></div>
            <div class="stat-card-info">
                <div class="stat-card-number counter" data-target="<?php echo (int)$studentCounts['inactive_count']; ?>">0</div>
                <div class="stat-card-label">طالب معطل</div>
            </div>
        </div>
    </div>
    <div class="col">
        <div class="stat-card" style="--card-gradient: linear-gradient(135deg, #8b5cf6, #7c3aed);">
            <div class="stat-card-icon"><i class="fas fa-school"></i></div>
            <div class="stat-card-info">
                <div class="stat-card-number counter" data-target="<?php echo (int)$studentCounts['class_count']; ?>">0</div>
                <div class="stat-card-label">عدد الفصول</div>
            </div>
        </div>
    </div>
</div>

<!-- شريط الفلاتر الحديث -->
<!-- شريط الفلاتر الحديث -->
<div class="admin-filter-bar mb-4 no-print">
    <div class="admin-filter-controls">
        
        <!-- Stage Dropdown -->
        <div class="dropdown d-inline-block">
            <button class="btn btn-light dropdown-toggle btn-sm filter-dropdown-btn border" type="button" id="stageDropdown" data-bs-toggle="dropdown" data-bs-auto-close="outside" aria-expanded="false" style="background: white; color: #495057; height: 31px; display: inline-flex; align-items: center; justify-content: space-between; min-width: 150px;">
                <span>المراحل: <span id="selectedStagesLabel" class="fw-bold">الكل</span></span>
            </button>
            <div class="dropdown-menu p-3" aria-labelledby="stageDropdown" style="max-height: 250px; overflow-y: auto; min-width: 200px; text-align: right; box-shadow: 0 10px 15px -3px rgba(0, 0, 0, 0.1);">
                <?php foreach ($stages as $stage): ?>
                    <div class="form-check mb-1">
                        <input class="form-check-input stage-checkbox" type="checkbox" name="stage_ids[]" value="<?php echo (int) $stage['id']; ?>" id="stage_<?php echo (int) $stage['id']; ?>" <?php echo in_array((int)$stage['id'], $filterStages, true) ? 'checked' : ''; ?>>
                        <label class="form-check-label" for="stage_<?php echo (int) $stage['id']; ?>"><?php echo htmlspecialchars($stage['stage_name'], ENT_QUOTES, 'UTF-8'); ?></label>
                    </div>
                <?php endforeach; ?>
            </div>
        </div>

        <!-- Grade Dropdown -->
        <div class="dropdown d-inline-block">
            <button class="btn btn-light dropdown-toggle btn-sm filter-dropdown-btn border" type="button" id="gradeDropdown" data-bs-toggle="dropdown" data-bs-auto-close="outside" aria-expanded="false" style="background: white; border-color: #dee2e6; color: #495057; height: 31px; display: inline-flex; align-items: center; justify-content: space-between; min-width: 150px;">
                <span>الصفوف: <span id="selectedGradesLabel" class="fw-bold">الكل</span></span>
            </button>
            <div class="dropdown-menu p-3" aria-labelledby="gradeDropdown" style="max-height: 250px; overflow-y: auto; min-width: 220px; text-align: right; box-shadow: 0 10px 15px -3px rgba(0, 0, 0, 0.1);">
                <?php foreach ($grades as $grade): ?>
                    <div class="form-check mb-1 grade-item" data-stage="<?php echo (int) $grade['stage_id']; ?>">
                        <input class="form-check-input grade-checkbox" type="checkbox" name="grade_ids[]" value="<?php echo (int) $grade['id']; ?>" id="grade_<?php echo (int) $grade['id']; ?>" <?php echo in_array((int)$grade['id'], $filterGrades, true) ? 'checked' : ''; ?>>
                        <label class="form-check-label" for="grade_<?php echo (int) $grade['id']; ?>"><?php echo htmlspecialchars($grade['grade_name'], ENT_QUOTES, 'UTF-8'); ?></label>
                    </div>
                <?php endforeach; ?>
            </div>
        </div>

        <!-- Class Dropdown -->
        <div class="dropdown d-inline-block">
            <button class="btn btn-light dropdown-toggle btn-sm filter-dropdown-btn border" type="button" id="classDropdown" data-bs-toggle="dropdown" data-bs-auto-close="outside" aria-expanded="false" style="background: white; border-color: #dee2e6; color: #495057; height: 31px; display: inline-flex; align-items: center; justify-content: space-between; min-width: 150px;">
                <span>الفصول: <span id="selectedClassesLabel" class="fw-bold">الكل</span></span>
            </button>
            <div class="dropdown-menu p-3" aria-labelledby="classDropdown" style="max-height: 250px; overflow-y: auto; min-width: 220px; text-align: right; box-shadow: 0 10px 15px -3px rgba(0, 0, 0, 0.1);">
                <?php foreach ($allClasses as $class): ?>
                    <div class="form-check mb-1 class-item" data-grade="<?php echo (int) $class['grade_id']; ?>">
                        <input class="form-check-input class-checkbox" type="checkbox" name="class_ids[]" value="<?php echo (int) $class['id']; ?>" id="class_<?php echo (int) $class['id']; ?>" <?php echo in_array((int)$class['id'], $filterClasses, true) ? 'checked' : ''; ?>>
                        <label class="form-check-label" for="class_<?php echo (int) $class['id']; ?>"><?php echo htmlspecialchars($class['name'], ENT_QUOTES, 'UTF-8'); ?></label>
                    </div>
                <?php endforeach; ?>
            </div>
        </div>

        <!-- Enrollment Status Dropdown -->
        <div class="dropdown d-inline-block">
            <button class="btn btn-light dropdown-toggle btn-sm filter-dropdown-btn border" type="button" id="enrollmentStatusDropdown" data-bs-toggle="dropdown" data-bs-auto-close="outside" aria-expanded="false" style="background: white; color: #495057; height: 31px; display: inline-flex; align-items: center; justify-content: space-between; min-width: 160px;">
                <span>حالة القيد: <span id="selectedEnrollmentLabel" class="fw-bold">الكل</span></span>
            </button>
            <div class="dropdown-menu p-3" aria-labelledby="enrollmentStatusDropdown" style="min-width: 200px; text-align: right; box-shadow: 0 10px 15px -3px rgba(0,0,0,.1);">
                <?php foreach (['enrolled' => 'مقيد 🎒', 'graduated' => 'خريج 🎓', 'transferred' => 'منقول من المدرسة 🔄', 'discontinued' => 'منقطع ⏸️', 'transferred_in' => 'منقول إلى المدرسة 🔁'] as $statusVal => $statusLbl): ?>
                    <div class="form-check mb-1">
                        <input class="form-check-input enrollment-status-checkbox" type="checkbox"
                               name="enrollment_statuses[]" value="<?php echo $statusVal; ?>"
                               id="enroll_<?php echo $statusVal; ?>"
                               <?php echo in_array($statusVal, $filterEnrollmentStatuses, true) ? 'checked' : ''; ?>>
                        <label class="form-check-label" for="enroll_<?php echo $statusVal; ?>"><?php echo $statusLbl; ?></label>
                    </div>
                <?php endforeach; ?>
            </div>
        </div>

        <!-- Student Dropdown with Checkboxes and Search inside -->
        <div class="dropdown d-inline-block">
            <button class="btn btn-light dropdown-toggle btn-sm filter-dropdown-btn border" type="button" id="studentDropdown" data-bs-toggle="dropdown" data-bs-auto-close="outside" aria-expanded="false" style="background: white; border-color: #dee2e6; color: #495057; height: 31px; display: inline-flex; align-items: center; justify-content: space-between; min-width: 180px;">
                <span>الطلاب: <span id="selectedStudentsLabel" class="fw-bold">الكل</span></span>
            </button>
            <div class="dropdown-menu p-3" aria-labelledby="studentDropdown" style="max-height: 300px; overflow-y: auto; min-width: 300px; text-align: right; box-shadow: 0 10px 15px -3px rgba(0, 0, 0, 0.1);">
                <input type="text" id="studentSearchInput" class="form-control form-control-sm mb-2" placeholder="ابحث بالاسم لتصفية القائمة...">
                <div id="studentCheckboxesContainer">
                    <?php
                    // استخدم القائمة المقيّدة مسبقاً بصلاحيات المستخدم، دون استعلام ثانٍ يتجاوز نطاق الفصول.
                    foreach ($studentList as $s):
                        $enrollStatus = $s['enrollment_status'] ?? 'enrolled';
                        $enrollmentDate = trim((string) ($s['enrollment_date'] ?? ''));
                        $isTransferredIn = $enrollStatus === 'enrolled' && $enrollmentDate !== '';
                        if ($isTransferredIn && $yearStart) {
                            $isTransferredIn = $enrollmentDate >= $yearStart;
                        }
                        if ($isTransferredIn && $yearEnd) {
                            $isTransferredIn = $enrollmentDate <= $yearEnd;
                        }
                        $enrollLabel = [
                            'enrolled' => '',
                            'graduated' => ' [خريج]',
                            'transferred' => ' [منقول]',
                            'discontinued' => ' [منقطع]',
                        ][$enrollStatus] ?? '';
                    ?>
                        <div
                            class="form-check mb-1 student-item"
                            data-class="<?php echo (int) ($s['class_id'] ?? 0); ?>"
                            data-enrollment-status="<?php echo htmlspecialchars((string) $enrollStatus, ENT_QUOTES, 'UTF-8'); ?>"
                            data-transferred-in="<?php echo $isTransferredIn ? '1' : '0'; ?>"
                        >
                            <input class="form-check-input export-student-checkbox" type="checkbox" name="filter_student_ids[]" value="<?php echo (int) $s['id']; ?>" id="student_<?php echo (int) $s['id']; ?>" <?php echo in_array((int)$s['id'], $filterStudentIds, true) ? 'checked' : ''; ?>>
                            <label class="form-check-label text-truncate" for="student_<?php echo (int) $s['id']; ?>" style="max-width: 250px;" title="<?php echo htmlspecialchars($s['display_name']); ?>">
                                <?php echo htmlspecialchars($s['display_name']); ?> <small class="text-muted">(<?php echo htmlspecialchars($s['class_name'] ?? 'بدون فصل'); ?><?php echo $enrollLabel; ?>)</small>
                            </label>
                        </div>
                    <?php endforeach; ?>
                </div>
            </div>
        </div>

    </div>
    
    <div class="admin-filter-actions">
        <a href="export_students.php" class="btn btn-light btn-sm"><i class="fas fa-rotate-left me-1"></i>إعادة تعيين</a>
        <button type="button" class="btn btn-light btn-sm" data-bs-toggle="modal" data-bs-target="#printSettingsModal">
            <i class="fas fa-cog me-1"></i>إعدادات الطباعة
        </button>
    </div>
</div>

<?php
$sectionIcons = [
    'البيانات الأساسية'         => 'fas fa-id-card',
    'بيانات الأب'               => 'fas fa-male',
    'بيانات الأم'               => 'fas fa-female',
    'أولياء الأمور الآخرون'     => 'fas fa-user-shield',
    'البيانات الصحية والنفسية'   => 'fas fa-heartbeat',
    'الأسرة وصلات القرابة'      => 'fas fa-users',
    'المسار الدراسي'            => 'fas fa-route',
    'الصورة الشخصية والمرفقات'   => 'fas fa-paperclip',
];
?>
<div class="modal fade" id="printSettingsModal" tabindex="-1" aria-labelledby="printSettingsModalLabel" aria-hidden="true" style="text-align: right;">
    <div class="modal-dialog modal-lg modal-dialog-scrollable">
        <div class="modal-content admin-modal admin-modal-premium admin-modal-view">
            <div class="modal-header d-flex justify-content-between align-items-center">
                <h5 class="modal-title" id="printSettingsModalLabel"><i class="fas fa-cog me-2"></i>إعدادات الطباعة وتحديد الحقول</h5>
                <button type="button" class="btn-close ms-0" data-bs-dismiss="modal" aria-label="إغلاق"></button>
            </div>
            <div class="modal-body">
                <div class="d-flex gap-2 mb-4 pb-3 border-bottom justify-content-center flex-wrap no-print">
                    <button type="button" class="btn btn-success btn-sm px-3" id="selectAllColumns" style="border-radius: 6px;">
                        <i class="fas fa-check-double me-1"></i>تحديد الكل
                    </button>
                    <button type="button" class="btn btn-secondary btn-sm px-3" id="deselectAllColumns" style="border-radius: 6px;">
                        <i class="fas fa-times me-1"></i>إلغاء الكل
                    </button>
                    <button type="button" class="btn btn-primary btn-sm px-3" id="selectBasicColumns" style="border-radius: 6px;">
                        <i class="fas fa-star me-1"></i>البيانات الأساسية
                    </button>
                </div>
                
                <?php
                foreach ($columnSections as $sectionTitle => $cols):
                    $sectionIcon = $sectionIcons[$sectionTitle] ?? 'fas fa-folder-open';
                    $hasToggles = false;
                    foreach ($cols as $_c) { if ($_c[0] !== '__header__' && $_c[0] !== '__note__') { $hasToggles = true; break; } }
                    ?>
                    <div class="card mb-4 border-0 shadow-sm" style="border-radius: 12px; background: #ffffff; border: 1px solid #e2e8f0 !important;">
                        <div class="card-header border-0 d-flex justify-content-between align-items-center py-3" style="background: rgba(37, 99, 235, 0.05); border-top-left-radius: 12px; border-top-right-radius: 12px;">
                            <h6 class="mb-0 fw-bold text-primary d-flex align-items-center" style="font-size: 0.95rem;">
                                <span class="d-inline-flex align-items-center justify-content-center bg-white text-primary rounded-circle shadow-sm me-2" style="width: 32px; height: 32px; margin-left: 8px;">
                                    <i class="<?php echo htmlspecialchars($sectionIcon); ?>"></i>
                                </span>
                                <span><?php echo htmlspecialchars($sectionTitle); ?></span>
                            </h6>
                            <?php if ($hasToggles): ?>
                            <div class="d-flex gap-1" role="group">
                                <button type="button" class="btn btn-outline-success btn-sm select-section px-2 py-1"
                                    data-target-section="<?php echo htmlspecialchars($sectionTitle); ?>" title="تحديد القسم" style="border-radius: 6px; font-size: 0.75rem;">
                                    <i class="fas fa-check"></i> تحديد
                                </button>
                                <button type="button" class="btn btn-outline-secondary btn-sm deselect-section px-2 py-1"
                                    data-target-section="<?php echo htmlspecialchars($sectionTitle); ?>" title="إلغاء القسم" style="border-radius: 6px; font-size: 0.75rem;">
                                    <i class="fas fa-times"></i> إلغاء
                                </button>
                            </div>
                            <?php endif; ?>
                        </div>
                        <div class="card-body py-3">
                            <div class="row g-3" data-section="<?php echo htmlspecialchars($sectionTitle); ?>">
                                <?php foreach ($cols as $c): ?>
                                    <?php if ($c[0] === '__header__'): ?>
                                        <div class="col-12">
                                            <div class="d-flex align-items-center mb-1 mt-2">
                                                <span class="badge rounded-pill text-bg-light border me-2" style="font-size:0.75rem; color:#374151 !important;">
                                                    <i class="fas fa-layer-group me-1 text-primary"></i>
                                                    <?php echo htmlspecialchars($c[1]); ?>
                                                </span>
                                                <hr class="flex-grow-1 my-0" style="border-color:#dee2e6;">
                                            </div>
                                        </div>
                                    <?php else: 
                                        $checked = in_array($c[0], $checkedFields, true) ? 'checked' : '';
                                        ?>
                                        <div class="col-lg-3 col-md-4 col-sm-6 col-6">
                                            <div class="form-check form-switch custom-switch-premium">
                                                <input class="form-check-input col-toggle-checkbox" type="checkbox" role="switch"
                                                    name="fields[]" value="<?php echo $c[0]; ?>" id="<?php echo $c[1]; ?>" <?php echo $checked; ?> style="cursor: pointer;">
                                                <label class="form-check-label text-secondary fw-medium"
                                                    for="<?php echo $c[1]; ?>" style="cursor: pointer; font-size: 0.85rem;"><?php echo htmlspecialchars($c[2]); ?></label>
                                            </div>
                                        </div>
                                    <?php endif; ?>
                                <?php endforeach; ?>
                            </div>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">إغلاق</button>
                <button type="submit" name="do_export" value="1" class="btn btn-primary" onclick="document.getElementById('exportFormatInput').value='preview'"><i class="fas fa-eye me-1"></i>حفظ ومعاينة</button>
            </div>
        </div>
    </div>
</div>



</form>

<!-- عرض النتائج / المعاينة -->
<?php if (isset($studentsData) && !empty($selectedFields)): ?>
<div class="admin-list-surface mb-4">
    <div class="d-flex justify-content-between align-items-center flex-wrap gap-2 mb-3 no-print">
        <h5 class="mb-0"><i class="fas fa-table me-2"></i>نتائج التصدير (<?php echo count($studentsData); ?> طالب)</h5>
        <span class="badge bg-light text-dark"><?php echo count($selectedFields); ?> حقل محدد</span>
    </div>
        <div class="table-responsive admin-table-wrap admin-preview-scroll">
            <table class="table table-striped table-hover preview-table admin-data-table mb-0" id="previewTable">
                <thead>
                    <tr>
                        <th>#</th>
                        <?php foreach ($selectedFields as $f): ?>
                            <th><?php echo htmlspecialchars($allFields[$f] ?? $f); ?></th>
                        <?php endforeach; ?>
                    </tr>
                </thead>
                <tbody>
                    <?php $counter = 0; foreach ($studentsData as $s): $counter++; ?>
                    <?php $guardian = $guardiansMap[$s['id']] ?? []; ?>
                    <tr>
                        <td><?php echo $counter; ?></td>
                        <?php foreach ($selectedFields as $f): ?>
                            <?php if ($f === 'profile_image'): ?>
                                <td class="text-center">
                                    <?php if (!empty($s['profile_image_id'])): ?>
                                        <img
                                            src="<?php echo htmlspecialchars(ProfileAttachmentStorage::adminDownloadUrl('student', (int) $s['profile_image_id']), ENT_QUOTES, 'UTF-8'); ?>"
                                            class="rounded shadow-sm student-export-photo"
                                            width="56"
                                            height="56"
                                            alt="الصورة الشخصية للطالب"
                                        >
                                    <?php else: ?>
                                        -
                                    <?php endif; ?>
                                </td>
                            <?php else: ?>
                                <td><?php echo htmlspecialchars(formatStudentValue($f, $s, $guardian, $genderLabels, $religionLabels, $relationshipLabels, $octoberReferenceDate)); ?></td>
                            <?php endif; ?>
                        <?php endforeach; ?>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
    </div>
</div>
<?php elseif (isset($studentsData) && empty($selectedFields)): ?>
<div class="alert alert-warning no-print">
    <i class="fas fa-exclamation-triangle me-2"></i>الرجاء اختيار حقل واحد على الأقل للتصدير.
</div>
<?php endif; ?>

<?php if (isset($showPrintView) && $showPrintView): ?>
<script>
document.addEventListener('DOMContentLoaded', function() {
    var images = Array.from(document.querySelectorAll('.student-export-photo'));
    Promise.all(images.map(function (image) {
        if (image.complete) return Promise.resolve();
        return new Promise(function (resolve) {
            image.addEventListener('load', resolve, {once: true});
            image.addEventListener('error', resolve, {once: true});
        });
    })).then(function () {
        window.print();
    });
});
</script>
<?php endif; ?>

<script>
document.addEventListener('DOMContentLoaded', function() {
    // 1. تحديد الكل والبيانات الأساسية بمودال إعدادات الطباعة
    var selectAllBtn = document.getElementById('selectAllColumns');
    if (selectAllBtn) {
        selectAllBtn.addEventListener('click', function() {
            document.querySelectorAll('#printSettingsModal .col-toggle-checkbox').forEach(cb => cb.checked = true);
        });
    }
    var deselectAllBtn = document.getElementById('deselectAllColumns');
    if (deselectAllBtn) {
        deselectAllBtn.addEventListener('click', function() {
            document.querySelectorAll('#printSettingsModal .col-toggle-checkbox').forEach(cb => cb.checked = false);
        });
    }
    var selectBasicBtn = document.getElementById('selectBasicColumns');
    if (selectBasicBtn) {
        selectBasicBtn.addEventListener('click', function() {
            var basic = <?php echo json_encode($defaultFields, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT); ?>;
            document.querySelectorAll('#printSettingsModal .col-toggle-checkbox').forEach(function(cb) {
                cb.checked = basic.includes(cb.value);
            });
        });
    }

    // 2. تحديد وإلغاء تحديد الأقسام بمودال إعدادات الطباعة
    document.querySelectorAll('#printSettingsModal .select-section').forEach(function (btn) {
        btn.addEventListener('click', function () {
            const section = this.getAttribute('data-target-section');
            document.querySelectorAll('#printSettingsModal .row[data-section="' + section + '"] .col-toggle-checkbox').forEach(function (cb) {
                cb.checked = true;
            });
        });
    });
    document.querySelectorAll('#printSettingsModal .deselect-section').forEach(function (btn) {
        btn.addEventListener('click', function () {
            const section = this.getAttribute('data-target-section');
            document.querySelectorAll('#printSettingsModal .row[data-section="' + section + '"] .col-toggle-checkbox').forEach(function (cb) {
                cb.checked = false;
            });
        });
    });

    // 3. تحديث مسميات الفلاتر في الأزرار لتوضيح الاختيارات
    function updateDropdownLabels() {
        // Stages
        var checkedStages = document.querySelectorAll('.stage-checkbox:checked');
        var stageLabel = document.getElementById('selectedStagesLabel');
        if (stageLabel) {
            stageLabel.innerText = checkedStages.length === 0 ? 'الكل' : (checkedStages.length === 1 ? checkedStages[0].nextElementSibling.innerText.trim() : checkedStages.length);
        }
        // Grades
        var checkedGrades = document.querySelectorAll('.grade-checkbox:checked');
        var gradeLabel = document.getElementById('selectedGradesLabel');
        if (gradeLabel) {
            gradeLabel.innerText = checkedGrades.length === 0 ? 'الكل' : (checkedGrades.length === 1 ? checkedGrades[0].nextElementSibling.innerText.trim() : checkedGrades.length);
        }
        // Classes
        var checkedClasses = document.querySelectorAll('.class-checkbox:checked');
        var classLabel = document.getElementById('selectedClassesLabel');
        if (classLabel) {
            classLabel.innerText = checkedClasses.length === 0 ? 'الكل' : (checkedClasses.length === 1 ? checkedClasses[0].nextElementSibling.innerText.trim() : checkedClasses.length);
        }
        // Students
        var checkedStudents = document.querySelectorAll('.export-student-checkbox:checked');
        var studentLabel = document.getElementById('selectedStudentsLabel');
        if (studentLabel) {
            studentLabel.innerText = checkedStudents.length === 0 ? 'الكل' : (checkedStudents.length === 1 ? checkedStudents[0].nextElementSibling.innerText.split('(')[0].trim() : checkedStudents.length);
        }
        // Enrollment status
        var checkedEnrollments = document.querySelectorAll('.enrollment-status-checkbox:checked');
        var enrollmentLabel = document.getElementById('selectedEnrollmentLabel');
        if (enrollmentLabel) {
            var labelMap = {enrolled: 'مقيد', graduated: 'خريج', transferred: 'منقول من', discontinued: 'منقطع', transferred_in: 'منقول إلى'};
            if (checkedEnrollments.length === 0) {
                enrollmentLabel.innerText = 'الكل';
            } else if (checkedEnrollments.length === 1) {
                enrollmentLabel.innerText = labelMap[checkedEnrollments[0].value] || checkedEnrollments[0].value;
            } else {
                enrollmentLabel.innerText = checkedEnrollments.length;
            }
        }
    }

    // تصفية قائمة الطلاب لحظياً حسب الفصل وحالة القيد ونص البحث.
    function filterStudentOptions(uncheckOutsideFilters) {
        var checkedClasses = Array.from(document.querySelectorAll('.class-checkbox:checked')).map(function (cb) {
            return cb.value;
        });
        var checkedStatuses = Array.from(document.querySelectorAll('.enrollment-status-checkbox:checked')).map(function (cb) {
            return cb.value;
        });
        var studentSearchInput = document.getElementById('studentSearchInput');
        var term = studentSearchInput ? studentSearchInput.value.trim().toLowerCase() : '';

        document.querySelectorAll('.student-item').forEach(function (item) {
            var classId = item.getAttribute('data-class');
            var enrollmentStatus = item.getAttribute('data-enrollment-status') || 'enrolled';
            var isTransferredIn = item.getAttribute('data-transferred-in') === '1';
            var nameText = (item.querySelector('label')?.innerText || '').toLowerCase();

            var matchesClass = checkedClasses.length === 0 || checkedClasses.includes(classId);
            var matchesStatus = checkedStatuses.length === 0 || checkedStatuses.some(function (status) {
                return status === 'transferred_in' ? isTransferredIn : enrollmentStatus === status;
            });
            var matchesSearch = term === '' || nameText.includes(term);
            var matchesStructuralFilters = matchesClass && matchesStatus;

            item.style.display = matchesStructuralFilters && matchesSearch ? '' : 'none';
            if (uncheckOutsideFilters && !matchesStructuralFilters) {
                var checkbox = item.querySelector('.export-student-checkbox');
                if (checkbox) checkbox.checked = false;
            }
        });

        updateDropdownLabels();
    }

    // 4. الفلاتر المتتالية (Cascading Filters)
    function filterCascades() {
        // المراحل النشطة
        var checkedStages = Array.from(document.querySelectorAll('.stage-checkbox:checked')).map(cb => cb.value);
        
        // تصفية الصفوف
        var grades = document.querySelectorAll('.grade-item');
        grades.forEach(function(item) {
            var stageId = item.getAttribute('data-stage');
            if (checkedStages.length === 0 || checkedStages.includes(stageId)) {
                item.style.display = '';
            } else {
                item.style.display = 'none';
                item.querySelector('.grade-checkbox').checked = false;
            }
        });

        // الصفوف النشطة
        var checkedGrades = Array.from(document.querySelectorAll('.grade-checkbox:checked')).map(cb => cb.value);
        
        // تصفية الفصول
        var classes = document.querySelectorAll('.class-item');
        classes.forEach(function(item) {
            var gradeId = item.getAttribute('data-grade');
            if (checkedGrades.length === 0 || checkedGrades.includes(gradeId)) {
                item.style.display = '';
            } else {
                item.style.display = 'none';
                item.querySelector('.class-checkbox').checked = false;
            }
        });

        filterStudentOptions(true);
    }

    // ربط أحداث التغيير بالفلاتر المتتالية
    document.querySelectorAll('.stage-checkbox').forEach(cb => cb.addEventListener('change', filterCascades));
    document.querySelectorAll('.grade-checkbox').forEach(cb => cb.addEventListener('change', filterCascades));
    document.querySelectorAll('.class-checkbox').forEach(cb => cb.addEventListener('change', filterCascades));
    document.querySelectorAll('.export-student-checkbox').forEach(cb => cb.addEventListener('change', updateDropdownLabels));
    document.querySelectorAll('.enrollment-status-checkbox').forEach(function (cb) {
        cb.addEventListener('change', function () {
            filterStudentOptions(true);
        });
    });

    // بحث وتصفية قائمة الطلاب ديناميكياً داخل القائمة المنسدلة
    var studentSearch = document.getElementById('studentSearchInput');
    if (studentSearch) {
        studentSearch.addEventListener('input', function() {
            filterStudentOptions(false);
        });
    }

    // تهيئة المسميات والتبعيات عند تحميل الصفحة أول مرة
    filterCascades();
});
</script>

<?php
require_once '../includes/admin_footer.php';
?>
