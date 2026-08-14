<?php
/**
 * ملف الطالب — Student File
 * يُتيح اختيار طالب أو أكثر وتحديد أقسام البيانات وطباعة ملف الطالب الكامل أو المخصص.
 */
$page_title = "ملف الطالب";
$custom_page_title = true;

require_once '../config/database.php';
require_once '../classes/utilities.php';
require_once '../classes/AcademicYear.php';
require_once '../classes/ProfileAttachmentStorage.php';
require_once '../classes/user.php';
require_once '../classes/ScopedStaffPortalContext.php';
require_once '../includes/session_config.php';
require_once '../includes/csrf.php';
Utilities::validateSession('admin');

requireCsrfPost();

$database = new Database();
$db = $database->getConnection();
$db->exec("SET NAMES 'utf8mb4'");

$currentAcademicYearId = AcademicYear::currentId($db);
$portalContext = new ScopedStaffPortalContext($db, $currentAcademicYearId);
$allowedClassIds = $portalContext->allowedClassIds();
$studentScopeSql = '';
$studentScopeParams = [];
if ($allowedClassIds !== null) {
    if ($allowedClassIds === []) {
        $studentScopeSql = ' AND 1 = 0';
    } else {
        $studentScopeSql = ' AND se.class_id IN (' . implode(',', array_fill(0, count($allowedClassIds), '?')) . ')';
        $studentScopeParams = $allowedClassIds;
    }
}

// ===== إعدادات المدرسة =====
$settings = $db->query("SELECT setting_key, setting_value FROM settings")->fetchAll(PDO::FETCH_KEY_PAIR) ?: [];
$schoolName     = $settings['school_name']              ?? '';
$schoolDirector = $settings['school_director']          ?? '';
$studentAffairs = $settings['student_affairs_officer']  ?? '';
$adminDirector  = $settings['admin_director']           ?? '';
$eduDirectorate = $settings['educational_directorate']  ?? '';
$eduAdmin       = $settings['educational_administration'] ?? '';

$logoPath = '';
$logoFile = $settings['school_logo'] ?? '';
if ($logoFile && file_exists(__DIR__ . '/../uploads/' . $logoFile)) {
    $logoPath = '../uploads/' . htmlspecialchars($logoFile, ENT_QUOTES, 'UTF-8');
} elseif (file_exists(__DIR__ . '/../assets/img/logo.png')) {
    $logoPath = '../assets/img/logo.png';
}

// ===== قائمة الطلاب للـ select =====
$studentsStmt = $db->prepare(
    "SELECT u.id, u.name, sp.student_code,
            se.stage_id, se.grade_id, se.class_id,
            COALESCE(g.grade_name, '') AS grade_name,
            COALESCE(c.name, '')       AS class_name
     FROM users u
     LEFT JOIN student_profiles sp ON sp.user_id = u.id
     LEFT JOIN student_enrollments se
           ON se.student_id = u.id
          AND se.academic_year_id = ?
          AND se.enrollment_status IN ('enrolled','graduated')
     LEFT JOIN grades g ON g.id = se.grade_id
     LEFT JOIN classes c ON c.id = se.class_id
     WHERE u.role = 'student' AND u.deleted_at IS NULL {$studentScopeSql}
     ORDER BY c.name, u.name"
);
$studentsStmt->execute(array_merge([$currentAcademicYearId], $studentScopeParams));
$allStudents = $studentsStmt->fetchAll(PDO::FETCH_ASSOC);

// ===== جلب المراحل والصفوف والفصول للفلترة =====
$stages = $db->query("SELECT id, stage_name FROM stages ORDER BY id")->fetchAll(PDO::FETCH_ASSOC) ?: [];
$grades = $db->query("SELECT id, grade_name, stage_id FROM grades ORDER BY id")->fetchAll(PDO::FETCH_ASSOC) ?: [];
$classes = $db->query("SELECT c.id, c.name AS class_name, c.grade_id, COALESCE(g.stage_id, 0) AS stage_id FROM classes c LEFT JOIN grades g ON g.id = c.grade_id ORDER BY c.name")->fetchAll(PDO::FETCH_ASSOC) ?: [];
if ($allowedClassIds !== null) {
    $allowedClassMap = array_fill_keys($allowedClassIds, true);
    $classes = array_values(array_filter($classes, static fn(array $class): bool => isset($allowedClassMap[(int) $class['id']])));
    $allowedGradeMap = array_fill_keys(array_map(static fn(array $class): int => (int) $class['grade_id'], $classes), true);
    $allowedStageMap = array_fill_keys(array_map(static fn(array $class): int => (int) $class['stage_id'], $classes), true);
    $grades = array_values(array_filter($grades, static fn(array $grade): bool => isset($allowedGradeMap[(int) $grade['id']])));
    $stages = array_values(array_filter($stages, static fn(array $stage): bool => isset($allowedStageMap[(int) $stage['id']])));
}

// ===== الحقول المتاحة وتكوينها =====
$fieldsConfig = [
    'personal' => [
        'title' => 'البيانات الشخصية',
        'icon' => 'fas fa-user text-success',
        'fields' => [
            'name_ar' => 'الاسم باللغة العربية',
            'name_en' => 'الاسم باللغة الإنجليزية',
            'religion' => 'الديانة',
            'gender' => 'النوع',
            'nationality' => 'الجنسية',
            'national_id' => 'الرقم القومي للمصريين',
            'passport_number' => 'رقم جواز السفر',
            'birth_date' => 'تاريخ الميلاد',
            'current_age' => 'العمر الحالي',
            'age_october' => 'العمر في 1 أكتوبر من العام الحالي',
            'birth_place' => 'محل الميلاد',
            'educational_guardianship' => 'الوصاية التعليمية',
        ]
    ],
    'enrollment' => [
        'title' => 'كود الطالب وحالة القيد الدراسي',
        'icon' => 'fas fa-barcode text-primary',
        'fields' => [
            'student_code' => 'كود الطالب لدى المدرسة',
            'ministry_code' => 'كود الطالب بوزارة التربية والتعليم',
            'grade_name' => 'الصف',
            'class_name' => 'الفصل',
            'transfer_reason' => 'سبب النقل',
            'enrollment_status' => 'حالة القيد',
            'enrollment_date' => 'تاريخ القيد بالمدرسة',
            'previous_school' => 'المدرسة القادم منها الطالب',
        ]
    ],
    'contact' => [
        'title' => 'العناوين وبيانات التواصل',
        'icon' => 'fas fa-phone text-info',
        'fields' => [
            'city_area' => 'المدينة / المنطقة',
            'address_current' => 'العنوان التفصيلي',
            'phone_emergency' => 'رقم الطوارئ',
            'phone_mobile' => 'رقم موبايل الطالب الأساسي',
            'phone_home' => 'رقم الهاتف الأرضي الأساسي',
        ]
    ],
    'father' => [
        'title' => 'بيانات الأب',
        'icon' => 'fas fa-user-tie text-primary',
        'fields' => [
            'father_name' => 'الاسم الرباعي للأب',
            'father_relation' => 'صلة القرابة للأب',
            'father_birth_date' => 'تاريخ ميلاد الأب',
            'father_birth_place' => 'محل ميلاد الأب',
            'father_religion' => 'ديانة الأب',
            'father_nationality' => 'جنسية الأب',
            'father_national_id' => 'الرقم القومي للأب',
            'father_passport_number' => 'رقم جواز سفر الأب',
            'father_phone' => 'رقم موبايل الأب الأساسي',
            'father_landline' => 'رقم هاتف الأب الأرضي الأساسي',
            'father_email' => 'البريد الإلكتروني للأب',
            'father_address' => 'عنوان الأب الحالي بالتفصيل',
            'father_qualification' => 'المؤهل الدراسي للأب',
            'father_job_title' => 'الوظيفة / المسمى الوظيفي للأب',
            'father_employer' => 'جهة العمل / الشركة للأب',
            'father_work_phone' => 'هاتف العمل للأب',
        ]
    ],
    'mother' => [
        'title' => 'بيانات الأم',
        'icon' => 'fas fa-person-breastfeeding text-danger',
        'fields' => [
            'mother_name' => 'الاسم الرباعي للأم',
            'mother_relation' => 'صلة القرابة للأم',
            'mother_birth_date' => 'تاريخ ميلاد الأم',
            'mother_birth_place' => 'محل ميلاد الأم',
            'mother_religion' => 'ديانة الأم',
            'mother_nationality' => 'جنسية الأم',
            'mother_national_id' => 'الرقم القومي للأم',
            'mother_passport_number' => 'رقم جواز سفر الأم',
            'mother_phone' => 'رقم موبايل الأم الأساسي',
            'mother_landline' => 'رقم هاتف الأم الأرضي الأساسي',
            'mother_email' => 'البريد الإلكتروني للأم',
            'mother_address' => 'عنوان الأم الحالي بالتفصيل',
            'mother_qualification' => 'المؤهل الدراسي للأم',
            'mother_job_title' => 'الوظيفة / المسمى الوظيفي للأم',
            'mother_employer' => 'جهة العمل / الشركة للأم',
            'mother_work_phone' => 'هاتف العمل للأم',
        ]
    ],
    'health' => [
        'title' => 'البيانات الصحية والنفسية',
        'icon' => 'fas fa-heartbeat text-danger',
        'fields' => [
            'blood_type' => 'فصيلة الدم',
            'insurance_number' => 'رقم التأمين الطبي',
            'insurance_start_date' => 'تاريخ بداية التأمين',
            'insurance_end_date' => 'تاريخ نهاية التأمين',
            'health_status' => 'الحالة الصحية العامة',
            'chronic_diseases' => 'الأمراض المزمنة',
            'allergies' => 'الحساسية',
            'disabilities' => 'الإعاقات',
            'medications' => 'العلاج / الأدوية',
            'treatment_plan' => 'خطط علاجية متبعة',
            'previous_medical_reports' => 'تقارير طبية سابقة',
            'emergency_medical_notes' => 'ملاحظات طبية طارئة',
            'psychological_notes' => 'الحالة النفسية والسلوكية',
        ]
    ],
    'siblings' => [
        'title' => 'الإخوة والأشقاء',
        'icon' => 'fas fa-user-friends text-secondary',
        'fields' => [
            'siblings_list' => 'جدول الإخوة بالكامل',
        ]
    ]
];

$columnSections = [
    'البيانات الأساسية' => [
        ['__header__', 'البيانات الشخصية'],
        ['name_ar', 'chk_name_ar', 'الاسم باللغة العربية', true],
        ['name_en', 'chk_name_en', 'الاسم باللغة الإنجليزية', true],
        ['religion', 'chk_religion', 'الديانة', false],
        ['gender', 'chk_gender', 'النوع', true],
        ['nationality', 'chk_nationality', 'جنسية الطالب', false],
        ['national_id', 'chk_national_id', 'الرقم القومي للمصريين', true],
        ['passport_number', 'chk_passport_number', 'رقم جواز السفر', false],
        ['birth_date', 'chk_birth_date', 'تاريخ الميلاد', true],
        ['current_age', 'chk_current_age', 'العمر الحالي', false],
        ['age_october', 'chk_age_october', 'العمر في 1 أكتوبر من العام الحالي', false],
        ['birth_place', 'chk_birth_place', 'محل الميلاد', true],
        ['educational_guardianship', 'chk_educational_guardianship', 'الوصاية التعليمية', false],
        
        ['__header__', 'كود الطالب وحالة القيد الدراسي'],
        ['student_code', 'chk_student_code', 'كود الطالب لدى المدرسة', true],
        ['ministry_code', 'chk_ministry_code', 'كود الطالب بوزارة التربية والتعليم', true],
        ['grade_name', 'chk_grade_name', 'الصف', true],
        ['class_name', 'chk_class_name', 'الفصل', true],
        ['transfer_reason', 'chk_transfer_reason', 'سبب النقل', false],
        ['enrollment_status', 'chk_enrollment_status', 'حالة القيد', false],
        ['enrollment_date', 'chk_enrollment_date', 'تاريخ القيد بالمدرسة', true],
        ['previous_school', 'chk_previous_school', 'المدرسة القادم منها الطالب', false],
        
        ['__header__', 'العناوين وبيانات التواصل'],
        ['city_area', 'chk_city_area', 'المدينة / المنطقة', true],
        ['address_current', 'chk_address_current', 'العنوان التفصيلي', true],
        ['phone_emergency', 'chk_phone_emergency', 'رقم الطوارئ', true],
        ['phone_mobile', 'chk_phone_mobile', 'رقم موبايل الطالب الأساسي', false],
        ['phone_home', 'chk_phone_home', 'رقم الهاتف الأرضي الأساسي', false],
    ],
    'بيانات الأب' => [
        ['__header__', 'البيانات الشخصية للأب'],
        ['father_name', 'chk_father_name', 'الاسم الرباعي للأب', true],
        ['father_relation', 'chk_father_relation', 'صلة القرابة للأب', false],
        ['father_birth_date', 'chk_father_birth_date', 'تاريخ ميلاد الأب', true],
        ['father_birth_place', 'chk_father_birth_place', 'محل ميلاد الأب', true],
        ['father_religion', 'chk_father_religion', 'ديانة الأب', false],
        ['father_nationality', 'chk_father_nationality', 'جنسية الأب', false],
        ['father_national_id', 'chk_father_national_id', 'الرقم القومي للأب', true],
        ['father_passport_number', 'chk_father_passport_number', 'رقم جواز سفر الأب', false],
        
        ['__header__', 'العناوين وبيانات التواصل للأب'],
        ['father_phone', 'chk_father_phone', 'رقم موبايل الأب الأساسي', true],
        ['father_landline', 'chk_father_landline', 'رقم هاتف الأب الأرضي الأساسي', false],
        ['father_email', 'chk_father_email', 'البريد الإلكتروني للأب', false],
        ['father_address', 'chk_father_address', 'عنوان الأب الحالي بالتفصيل', false],
        
        ['__header__', 'المؤهل وبيانات العمل للأب'],
        ['father_qualification', 'chk_father_qualification', 'المؤهل الدراسي للأب', true],
        ['father_job_title', 'chk_father_job_title', 'الوظيفة / المسمى الوظيفي للأب', true],
        ['father_employer', 'chk_father_employer', 'جهة العمل / الشركة للأب', true],
        ['father_work_phone', 'chk_father_work_phone', 'هاتف العمل للأب', false],
    ],
    'بيانات الأم' => [
        ['__header__', 'البيانات الشخصية للأم'],
        ['mother_name', 'chk_mother_name', 'الاسم الرباعي للأم', true],
        ['mother_relation', 'chk_mother_relation', 'صلة القرابة للأم', false],
        ['mother_birth_date', 'chk_mother_birth_date', 'تاريخ ميلاد الأم', true],
        ['mother_birth_place', 'chk_mother_birth_place', 'محل ميلاد الأم', true],
        ['mother_religion', 'chk_mother_religion', 'ديانة الأم', false],
        ['mother_nationality', 'chk_mother_nationality', 'جنسية الأم', false],
        ['mother_national_id', 'chk_mother_national_id', 'الرقم القومي للأم', true],
        ['mother_passport_number', 'chk_mother_passport_number', 'رقم جواز سفر الأم', false],
        
        ['__header__', 'العناوين وبيانات التواصل للأم'],
        ['mother_phone', 'chk_mother_phone', 'رقم موبايل الأم الأساسي', true],
        ['mother_landline', 'chk_mother_landline', 'رقم هاتف الأم الأرضي الأساسي', false],
        ['mother_email', 'chk_mother_email', 'البريد الإلكتروني للأم', false],
        ['mother_address', 'chk_mother_address', 'عنوان الأم الحالي بالتفصيل', false],
        
        ['__header__', 'المؤهل وبيانات العمل للأم'],
        ['mother_qualification', 'chk_mother_qualification', 'المؤهل الدراسي للأم', true],
        ['mother_job_title', 'chk_mother_job_title', 'الوظيفة / المسمى الوظيفي للأم', true],
        ['mother_employer', 'chk_mother_employer', 'جهة العمل / الشركة للأم', true],
        ['mother_work_phone', 'chk_mother_work_phone', 'هاتف العمل للأم', false],
    ],
    'البيانات الصحية والنفسية' => [
        ['__header__', 'الحالة الصحية'],
        ['blood_type', 'chk_blood_type', 'فصيلة الدم', true],
        ['insurance_number', 'chk_insurance_number', 'رقم التأمين الطبي', true],
        ['insurance_start_date', 'chk_insurance_start_date', 'تاريخ بداية التأمين', true],
        ['insurance_end_date', 'chk_insurance_end_date', 'تاريخ نهاية التأمين', true],
        ['health_status', 'chk_health_status', 'الحالة الصحية العامة', true],
        ['chronic_diseases', 'chk_chronic_diseases', 'الأمراض المزمنة', false],
        ['allergies', 'chk_allergies', 'الحساسية', false],
        ['disabilities', 'chk_disabilities', 'الإعاقات', false],
        ['medications', 'chk_medications', 'العلاج / الأدوية', true],
        ['treatment_plan', 'chk_treatment_plan', 'خطط علاجية متبعة', false],
        ['previous_medical_reports', 'chk_previous_medical_reports', 'تقارير طبية سابقة', false],
        ['emergency_medical_notes', 'chk_emergency_medical_notes', 'ملاحظات طبية طارئة', false],
        
        ['__header__', 'الحالة النفسية والسلوكية'],
        ['psychological_notes', 'chk_psychological_notes', 'الحالة النفسية والسلوكية', false],
    ],
    'الإخوة والأشقاء' => [
        ['siblings_list', 'chk_siblings_list', 'جدول الإخوة بالكامل', true],
    ]
];

$sectionIcons = [
    'البيانات الأساسية'         => 'fas fa-id-card',
    'بيانات الأب'               => 'fas fa-male',
    'بيانات الأم'               => 'fas fa-female',
    'البيانات الصحية والنفسية'   => 'fas fa-heartbeat',
    'الإخوة والأشقاء'           => 'fas fa-users',
];

// ===== معالجة POST =====
$selectedIds   = [];
$studentsData  = [];
$sections      = [];
$printOptions  = [];
$selectedFields = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $allowedFieldKeys = [];
    foreach ($fieldsConfig as $fieldSection) {
        $allowedFieldKeys = array_merge($allowedFieldKeys, array_keys($fieldSection['fields']));
    }
    $submittedFields = is_array($_POST['fields'] ?? null) ? $_POST['fields'] : [];
    $selectedFields = array_values(array_filter(
        $submittedFields,
        static fn($field): bool => is_string($field) && in_array($field, $allowedFieldKeys, true)
    ));
    
    // تعيين الأقسام تلقائياً بناءً على الحقول المحددة
    foreach ($fieldsConfig as $secKey => $secData) {
        $secKeys = array_keys($secData['fields']);
        $intersect = array_intersect($secKeys, $selectedFields);
        $sections[$secKey] = !empty($intersect);
    }

    // خيارات الطباعة
    $printOptions = [
        'show_logo'           => true,
        'show_school'         => true,
        'show_affairs'        => false,
        'show_admin_director' => false,
        'show_director'       => false,
        'show_photo'          => isset($_POST['opt_photo']),
    ];

    $rawIds = is_array($_POST['student_ids'] ?? null) ? $_POST['student_ids'] : [];
    foreach ($rawIds as $rid) {
        if (!is_scalar($rid)) continue;
        $sid = (int)$rid;
        if ($sid > 0) {
            $selectedIds[] = $sid;
        }
    }
    $selectedIds = array_unique($selectedIds);
    foreach ($selectedIds as $selectedStudentId) {
        $portalContext->assertStudentAllowed((int) $selectedStudentId);
    }

    // جلب بيانات كل طالب محدد
    if (!empty($selectedIds)) {
        $placeholders = implode(',', array_fill(0, count($selectedIds), '?'));
        $params = array_merge([$currentAcademicYearId], $selectedIds);

        $stmt = $db->prepare(
            "SELECT u.id, u.name, u.username, u.status,
                    sp.student_code, sp.ministry_code,
                    sp.first_name_ar, sp.second_name_ar, sp.third_name_ar, sp.fourth_name_ar, sp.family_name_ar,
                    sp.first_name_en, sp.second_name_en, sp.third_name_en, sp.fourth_name_en, sp.family_name_en,
                    sp.birth_date, sp.birth_place, sp.national_id, sp.nationality, sp.passport_number,
                    sp.religion, sp.gender, sp.blood_type,
                    sp.address_current, sp.city_area, sp.phone_mobile, sp.phone_home, sp.phone_emergency, sp.extra_phones,
                    sp.enrollment_date, sp.previous_school, sp.notes,
                    sp.health_status, sp.chronic_diseases, sp.allergies, sp.disabilities,
                    sp.medications, sp.psychological_notes, sp.emergency_medical_notes,
                    sp.extra_data,
                    sp.insurance_number, sp.insurance_start_date, sp.insurance_end_date,
                    sp.treatment_plan, sp.previous_medical_reports,
                    ay.name AS academic_year_name,
                    s.stage_name, g.grade_name, c.name AS class_name,
                    se.enrollment_status, se.graduation_year,
                    setr.reason AS transfer_reason
             FROM users u
             LEFT JOIN student_profiles sp ON sp.user_id = u.id
             LEFT JOIN student_enrollments se
                   ON se.student_id = u.id AND se.academic_year_id = ?
             LEFT JOIN academic_years ay ON ay.id = se.academic_year_id
             LEFT JOIN stages s ON s.id = se.stage_id
             LEFT JOIN grades g ON g.id = se.grade_id
             LEFT JOIN classes c ON c.id = se.class_id
             LEFT JOIN student_external_transfers setr ON setr.student_id = u.id
             WHERE u.id IN ($placeholders) AND u.role = 'student' AND u.deleted_at IS NULL
             ORDER BY c.name, u.name"
        );
        $stmt->execute($params);
        $rawStudents = $stmt->fetchAll(PDO::FETCH_ASSOC);

        foreach ($rawStudents as $stu) {
            $uid = (int)$stu['id'];

            // الإخوة
            $siblings = [];
            if ($sections['siblings']) {
                $sibStmt = $db->prepare(
                    "SELECT u2.name AS sibling_name, ss.relationship,
                            COALESCE(g2.grade_name,'') AS grade_name,
                            COALESCE(c2.name,'') AS class_name
                     FROM student_siblings ss
                     JOIN users u2 ON u2.id = ss.sibling_id
                     LEFT JOIN student_enrollments se2
                           ON se2.student_id = ss.sibling_id AND se2.academic_year_id = ?
                     LEFT JOIN grades g2 ON g2.id = se2.grade_id
                     LEFT JOIN classes c2 ON c2.id = se2.class_id
                     WHERE ss.student_id = ? AND ss.confirmed = 1
                     ORDER BY u2.name"
                );
                $sibStmt->execute([$currentAcademicYearId, $uid]);
                $siblings = $sibStmt->fetchAll(PDO::FETCH_ASSOC);
            }

            // أولياء الأمور من جدول student_guardians
            $guardians = [];
            if ($sections['father'] || $sections['mother']) {
                $grdStmt = $db->prepare(
                    "SELECT guardian_name, relationship, relationship_other,
                            phone_primary, national_id, job_title, employer,
                            address, qualification, email, is_primary,
                            passport_number, nationality, religion, birth_date, birth_place,
                            phone_landline, work_phone
                     FROM student_guardians
                     WHERE student_id = ?
                     ORDER BY is_primary DESC, FIELD(relationship, 'father', 'mother'), id ASC"
                );
                $grdStmt->execute([$uid]);
                $guardians = $grdStmt->fetchAll(PDO::FETCH_ASSOC);
            }

            // الصورة الشخصية
            $profileImage = '';
            $profileImageId = 0;
            if ($printOptions['show_photo']) {
                $imgStmt = $db->prepare("SELECT id, file_name FROM student_attachments WHERE user_id = ? AND label = 'الصورة الشخصية' LIMIT 1");
                $imgStmt->execute([$uid]);
                $profileImageRow = $imgStmt->fetch(PDO::FETCH_ASSOC) ?: [];
                $profileImage = (string)($profileImageRow['file_name'] ?? '');
                $profileImageId = (int)($profileImageRow['id'] ?? 0);
            }

            $studentsData[] = array_merge($stu, [
                '_siblings'      => $siblings,
                '_guardians'     => $guardians,
                '_profile_image' => $profileImage,
                '_profile_image_id' => $profileImageId,
            ]);
        }
    }
} else {
    // ===== الافتراضي لأول تحميل =====
    $defaultBasicFields = [
        'name_ar', 'name_en', 'gender', 'national_id', 'birth_date', 'birth_place',
        'student_code', 'ministry_code', 'grade_name', 'class_name', 'enrollment_date',
        'city_area', 'address_current', 'phone_emergency',
        'father_name', 'father_birth_date', 'father_birth_place', 'father_national_id', 'father_phone', 'father_qualification', 'father_job_title', 'father_employer',
        'mother_name', 'mother_birth_date', 'mother_birth_place', 'mother_national_id', 'mother_phone', 'mother_qualification', 'mother_job_title', 'mother_employer',
        'blood_type', 'insurance_number', 'insurance_start_date', 'insurance_end_date', 'health_status', 'medications',
        'siblings_list'
    ];
    $selectedFields = $defaultBasicFields;
    foreach ($fieldsConfig as $secKey => $secData) {
        $secKeys = array_keys($secData['fields']);
        $intersect = array_intersect($secKeys, $selectedFields);
        $sections[$secKey] = !empty($intersect);
    }
    $printOptions = [
        'show_logo'           => true,
        'show_school'         => true,
        'show_affairs'        => false,
        'show_admin_director' => false,
        'show_director'       => false,
        'show_photo'          => true,
    ];
}

require_once '../includes/admin_header.php';

// --- دالة مساعدة للعرض الآمن ---
function sf(?string $v, string $fallback = '—'): string {
    $v = trim((string)($v ?? ''));
    return $v !== '' ? htmlspecialchars($v, ENT_QUOTES, 'UTF-8') : $fallback;
}
?>

<!-- ===== CSS الطباعة والمعاينة ===== -->
<style>
/* لوحة التحكم */
.sf-premium-card {
    border: 1px solid #e2e8f0 !important;
    border-radius: 12px !important;
    background-color: #ffffff !important;
    box-shadow: 0 10px 25px -5px rgba(15, 23, 42, 0.04), 0 8px 16px -6px rgba(15, 23, 42, 0.04) !important;
    overflow: hidden;
    transition: all 0.3s ease;
}
.sf-premium-card:hover {
    box-shadow: 0 12px 30px -5px rgba(15, 23, 42, 0.08), 0 10px 20px -6px rgba(15, 23, 42, 0.08) !important;
}
.sf-premium-header {
    background: #f8fafc !important; 
    border-bottom: 2px solid #32328c !important; /* خط سفلي مميز بلون المدرسة الكحلي */
    padding: 12px 18px !important;
}
.sf-premium-header h6 {
    color: #1e293b !important;
    font-weight: 700 !important;
    font-size: 0.92rem !important;
    display: inline-flex;
    align-items: center;
    gap: 8px;
    margin: 0;
}
.sf-premium-header h6 i {
    color: #fa821e !important; /* اللون البرتقالي المميز للمدرسة */
    font-size: 1.05rem;
}
.sf-print-options {
    padding: 0 !important;
}
.sf-checkbox-box,
.sf-print-options .form-check {
    display: flex !important;
    align-items: center !important;
    gap: 8px !important;
    padding: 6px 12px !important;
    border-radius: 8px !important;
    margin: 0 !important;
    background: #ffffff !important;
    border: 1px solid #e2e8f0 !important;
    transition: all 0.2s ease-in-out !important;
    cursor: pointer !important;
    margin-bottom: 8px !important;
}

.sf-checkbox-box:hover,
.sf-print-options .form-check:hover {
    background: #f8fafc !important;
    border-color: #cbd5e1 !important;
}

.sf-checkbox-box:has(.form-check-input:checked),
.sf-print-options .form-check:has(.form-check-input:checked) {
    background: #f0f9ff !important;
    border-color: #0284c7 !important;
}

.sf-checkbox-box .form-check-input,
.sf-print-options .form-check-input {
    margin: 0 !important;
    float: none !important;
    position: relative !important;
    cursor: pointer !important;
    flex-shrink: 0 !important;
    left: auto !important;
    right: auto !important;
}

.sf-checkbox-box .form-check-label,
.sf-print-options .form-check-label {
    margin: 0 !important;
    padding: 0 !important;
    cursor: pointer !important;
    font-size: 0.82rem !important;
    font-weight: 500 !important;
    color: #334155 !important;
    user-select: none !important;
    flex-grow: 1 !important;
}

.sf-control-panel .form-label {
    font-size: 0.88rem;
    color: #475569;
}
.sf-control-panel select.form-select {
    font-size: 0.92rem;
    padding: 0.45rem 2rem 0.45rem 0.9rem;
    border-radius: 8px;
}
.sf-control-panel #studentSelect {
    font-size: 0.92rem;
    border-radius: 8px;
    padding: 8px;
    border: 1.5px solid #cbd5e1;
}
#studentSelect optgroup {
    font-weight: 700;
    color: #1e1e5a;
    background: #f1f5f9;
    padding: 6px 10px;
    margin-top: 6px;
    font-size: 0.88rem;
}
#studentSelect option {
    padding: 6px 12px;
    border-radius: 4px;
    margin: 2px 0;
}


/* منطقة المعاينة والطباعة */
.sf-student-block {
    background: #fff;
    border: 1.5px solid #cbd5e1;
    border-radius: 16px;
    margin-bottom: 40px;
    box-shadow: 0 10px 30px rgba(0,0,0,.04);
    overflow: hidden;
    position: relative;
}

/* البانر العلوي الموحد (مستوحى من الصور المرفقة بألوان الشعار) */
.sf-banner {
    background: linear-gradient(135deg, #32328c 0%, #1e1e5a 100%) !important;
    color: #fff !important;
    padding: 35px 35px 45px 35px;
    position: relative;
    border-bottom: 5px solid #fa821e !important;
    -webkit-print-color-adjust: exact;
    print-color-adjust: exact;
}
.sf-banner::after {
    content: '';
    position: absolute;
    bottom: 0;
    left: 0;
    width: 100%;
    height: 100%;
    background: radial-gradient(circle at 10% 20%, rgba(250, 130, 30, 0.1) 0%, transparent 40%);
    pointer-events: none;
}
.sf-banner-school {
    font-size: 1.45rem;
    font-weight: 800;
    letter-spacing: 0.5px;
    text-shadow: 0 2px 4px rgba(0,0,0,0.2);
    line-height: 1.2;
}
.sf-banner-dir {
    font-size: 0.92rem;
    opacity: 0.9;
    margin-top: 5px;
    line-height: 1.2;
}

/* شريط الملف الشخصي المتداخل */
.sf-profile-bar {
    padding: 0 35px;
    margin-top: 0; /* تم نقل الهامش السالب للصورة فقط لمنع تداخل الاسم */
    margin-bottom: 25px;
    position: relative;
    z-index: 10;
}
.sf-profile-info {
    padding-bottom: 5px;
}
.sf-student-name { 
    font-size: 1.65rem !important; 
    font-weight: 800 !important; 
    color: #1e1e5a !important; /* لون الكحلي الداكن من الشعار لمنع تداخل أي ألوان أخرى */
    text-shadow: none !important;
}
.sf-student-meta { 
    font-size: 0.95rem; 
    color: #64748b; 
}
.sf-student-meta span {
    margin-left: 20px;
}
.sf-student-meta i {
    color: #fa821e;
}

/* الصورة الشخصية الدائرية المتداخلة */
.sf-profile-photo-wrap {
    flex-shrink: 0;
}
.sf-profile-photo {
    width: 140px;
    height: 140px;
    object-fit: cover;
    border-radius: 50% !important;
    border: 4px solid #fff;
    box-shadow: 0 8px 20px rgba(0,0,0,0.15);
    background: #fff;
}
.sf-profile-photo-placeholder {
    width: 140px;
    height: 140px;
    border-radius: 50% !important;
    border: 4px solid #fff;
    box-shadow: 0 8px 20px rgba(0,0,0,0.15);
    background: #f8fafc;
    color: #cbd5e1;
}

/* كروت الأقسام (توزيع متداخل مثل الصورة المرفقة) */
.sf-section-card {
    border: 1.5px solid #cbd5e1;
    border-radius: 12px;
    padding: 24px 20px 15px 20px;
    margin-top: 30px;
    position: relative;
    background: #fff;
    box-shadow: 0 4px 12px rgba(0,0,0,0.015);
}
.sf-section-badge {
    position: absolute;
    top: -16px;
    right: 20px;
    background: #32328c;
    color: #fff;
    padding: 6px 18px;
    border-radius: 30px;
    font-size: 0.88rem;
    font-weight: 700;
    box-shadow: 0 4px 10px rgba(50, 50, 140, 0.25);
    display: flex;
    align-items: center;
    gap: 8px;
}
.sf-section-badge i {
    color: #fa821e;
}

/* الجداول والبيانات */
.sf-data-table { 
    width: 100%; 
    border-collapse: collapse; 
    font-size: 0.95rem; 
}
.sf-data-table td { 
    padding: 8px 12px; 
    vertical-align: middle; 
    border-bottom: 1px solid #f1f5f9; 
}
.sf-data-table tr:last-child td {
    border-bottom: none;
}
.sf-data-table td:first-child { 
    width: 35%; 
    color: #475569; 
    font-weight: 600; 
}
.sf-data-table td:last-child { 
    color: #0f172a; 
}

/* الإخوة والتوقيعات خارج الشبكة مع حواف ومسافات متناسقة */
.sf-siblings-wrap {
    padding: 0 35px;
    margin-top: 15px;
}
.sf-footer-row { 
    margin-top: 40px; 
    border-top: 2px solid #e2e8f0; 
    padding: 25px 35px 30px 35px; 
}
.sf-footer-row td { 
    text-align: center; 
    font-size: 0.95rem; 
    color: #475569; 
}
.sf-footer-name { 
    font-weight: 700; 
    color: #0f172a; 
    margin-top: 18px; 
    font-size: 1rem; 
}

/* شبكة الأقسام */
.sf-sections-grid {
    display: grid;
    grid-template-columns: 1fr 1fr;
    gap: 25px;
    align-items: start;
    padding: 10px 35px;
}
.sf-grid-column {
    display: flex;
    flex-direction: column;
    gap: 30px;
}
.sf-parent-row-container {
    padding: 10px 35px;
    margin-top: 10px;
}
.sf-parent-row {
    display: grid;
    gap: 25px;
}
@media (max-width: 767.98px) {
    .sf-parent-row-container {
        padding: 10px 20px;
    }
    .sf-parent-row {
        grid-template-columns: 1fr !important;
        gap: 15px;
    }
    .sf-sections-grid {
        grid-template-columns: 1fr;
        gap: 15px;
        padding: 10px 20px;
    }
    .sf-siblings-wrap {
        padding: 0 20px;
    }
    .sf-banner {
        padding: 20px 20px 45px 20px;
    }
    .sf-profile-bar {
        padding: 0 20px;
    }
}

/* طباعة */
@media print {
    @page {
        size: A4 portrait;
        margin: 6mm 8mm 6mm 8mm;
    }
    .no-print, .admin-sidebar, .admin-header, nav, footer,
    .sf-control-panel, .page-header-bar { display:none !important; }
    main { margin:0 !important; padding:0 !important; }
    body { background:#fff !important; font-size:10pt !important; }
    .sf-student-block {
        box-sizing: border-box !important;
        width: 100% !important;
        height: 284mm !important;
        max-height: 284mm !important;
        border: 2px solid #32328c !important;
        box-shadow:none !important;
        border-radius:16px !important;
        padding: 0 !important;
        margin: 0 !important;
        page-break-after: always !important;
        page-break-inside: avoid !important;
        overflow: hidden !important;
        position: relative !important;
    }
    .sf-student-block:last-child { page-break-after: avoid !important; }
    #printArea { padding: 0 !important; margin: 0 !important; }
    
    .sf-banner {
        background: linear-gradient(135deg, #32328c 0%, #1e1e5a 100%) !important;
        color: #fff !important;
        padding: 12px 25px 15px 25px !important;
        -webkit-print-color-adjust: exact;
        print-color-adjust: exact;
    }
    .sf-banner-school {
        font-size: 1.2rem !important;
    }
    .sf-banner-dir {
        font-size: 0.85rem !important;
        margin-top: 3px !important;
    }
    .sf-profile-bar {
        padding: 0 25px !important;
        margin-bottom: 5px !important;
        min-height: 60px !important;
    }
    .sf-profile-photo-wrap {
        position: absolute !important;
        top: -50px !important;
        left: 25px !important;
        z-index: 15 !important;
    }
    .sf-profile-photo {
        width: 80px !important;
        height: 80px !important;
        border-width: 3px !important;
    }
    .sf-profile-photo-placeholder {
        width: 80px !important;
        height: 80px !important;
        border-width: 3px !important;
    }
    .sf-profile-photo-placeholder i {
        font-size: 2.2rem !important;
    }
    .sf-student-name {
        font-size: 12.5pt !important;
    }
    .sf-student-meta {
        font-size: 8.5pt !important;
        gap: 12px !important;
        margin-top: 4px !important;
    }
    .sf-section-card {
        border: 1.5px solid #cbd5e1 !important;
        page-break-inside: avoid !important;
        margin-top: 12px !important;
        padding: 10px 14px 6px 14px !important;
        border-radius: 10px !important;
    }
    .sf-section-badge {
        background: #32328c !important;
        color: #fff !important;
        top: -11px !important;
        font-size: 7.5pt !important;
        padding: 3px 10px !important;
        border-radius: 5px !important;
        -webkit-print-color-adjust: exact;
        print-color-adjust: exact;
    }
    .sf-section-badge i {
        color: #fa821e !important;
        -webkit-print-color-adjust: exact;
        print-color-adjust: exact;
    }
    .sf-data-table td {
        border-bottom: 1px solid #e2e8f0 !important;
        padding: 3px 8px !important;
        font-size: 8.5pt !important;
        line-height: 1.25 !important;
    }
    .sf-data-table td:first-child {
        color: #475569 !important;
    }
    a[href]:after { content: none !important; }
    
    .sf-sections-grid {
        display: grid !important;
        grid-template-columns: 1fr 1fr !important;
        gap: 14px !important;
        align-items: start !important;
        padding: 5px 25px !important;
    }
    .sf-grid-column {
        gap: 14px !important;
    }
    .sf-parent-row-container {
        padding: 5px 25px !important;
        margin-top: 5px !important;
    }
    .sf-parent-row {
        display: grid !important;
        grid-template-columns: repeat(auto-fit, minmax(200px, 1fr)) !important;
        gap: 14px !important;
    }
    .sf-siblings-wrap {
        padding: 5px 25px !important;
        margin-top: 5px !important;
    }
    .sf-siblings-wrap .sf-section-card {
        margin-top: 12px !important;
    }
}

/* تخصيص وتنسيق فلتر الطلاب المنسدل */
#studentDropdown + .dropdown-menu {
    min-width: 320px !important;
    text-align: right !important;
}
#studentDropdown + .dropdown-menu .student-item {
    display: flex;
    flex-direction: row !important;
    align-items: center !important;
    justify-content: flex-start !important;
    gap: 8px !important;
    text-align: right !important;
    padding: 6px 12px !important;
    border-radius: 6px !important;
    cursor: pointer !important;
    margin-bottom: 2px !important;
    transition: background 0.15s ease-in-out;
}
#studentDropdown + .dropdown-menu .student-item:hover {
    background-color: #f1f5f9 !important;
}
#studentDropdown + .dropdown-menu .student-item:has(.student-checkbox:checked) {
    background-color: #e0f2fe !important;
}
#studentDropdown + .dropdown-menu .student-checkbox {
    margin: 0 !important;
    float: none !important;
    position: relative !important;
    flex-shrink: 0 !important;
    cursor: pointer !important;
}
#studentDropdown + .dropdown-menu .form-check-label {
    margin: 0 !important;
    padding: 0 !important;
    cursor: pointer !important;
    font-size: 0.85rem !important;
    color: #334155 !important;
    flex-grow: 1 !important;
    text-align: right !important;
}

/* تنسيق زر عرض الملف داخل الفلاتر لمنع تداخل الألوان وتثبيت اللون الأزرق */
.admin-filter-bar button.btn-primary {
    background: linear-gradient(135deg, #3b82f6, #1d4ed8) !important;
    border-color: #1d4ed8 !important;
    color: #ffffff !important;
    font-weight: 700 !important;
}
.admin-filter-bar button.btn-primary:hover {
    background: linear-gradient(135deg, #2563eb, #1e40af) !important;
    border-color: #1e40af !important;
    color: #ffffff !important;
}
</style>

<!-- ===== رأس الصفحة ===== -->
<div class="d-flex justify-content-between flex-wrap flex-md-nowrap align-items-center pt-3 pb-2 mb-4 border-bottom no-print">
    <div>
        <h1 class="h2 fw-bold text-dark"><i class="fas fa-folder-open me-3 text-primary"></i>ملف الطالب</h1>
        <p class="text-muted m-0">تخصيص وطباعة ملف البيانات الشامل للطلاب</p>
    </div>
    <div class="admin-top-actions no-print">
        <?php if (!empty($studentsData)): ?>
        <button type="button" onclick="exportPdf()" class="btn btn-header-premium btn-pdf-soft">
            <i class="fas fa-file-pdf me-1"></i>PDF
        </button>
        <button type="button" onclick="window.print()" class="btn btn-header-premium btn-print-soft">
            <i class="fas fa-print"></i>طباعة
        </button>
        <?php endif; ?>
    </div>
</div>

<!-- ===== لوحة التحكم ===== -->
<div class="sf-control-panel no-print mb-4">
    <form method="POST" id="sfForm">
        <?php echo csrfField(); ?>
        
        <!-- بار التصفية (Filters Bar) -->
        <div class="admin-filter-bar mb-3">
            <div class="admin-filter-controls">
                <!-- Dropdown المراحل -->
                <div class="dropdown d-inline-block">
                    <button class="btn btn-light dropdown-toggle btn-sm filter-dropdown-btn" type="button" id="stageDropdown" data-bs-toggle="dropdown" data-bs-auto-close="outside" aria-expanded="false" style="background: white; border-color: #dee2e6; color: #495057; height: 31px; display: inline-flex; align-items: center; justify-content: space-between; min-width: 140px;">
                        <span>المراحل: <span id="selectedStagesLabel" class="fw-bold">الكل</span></span>
                    </button>
                    <div class="dropdown-menu p-3" aria-labelledby="stageDropdown" style="max-height: 250px; overflow-y: auto; min-width: 200px; text-align: right; box-shadow: 0 10px 15px -3px rgba(0, 0, 0, 0.1);">
                        <?php foreach ($stages as $stg): ?>
                            <div class="form-check mb-1">
                                <input class="form-check-input stage-checkbox" type="checkbox" value="<?php echo $stg['id']; ?>" id="stage_<?php echo $stg['id']; ?>">
                                <label class="form-check-label" for="stage_<?php echo $stg['id']; ?>"><?php echo htmlspecialchars($stg['stage_name'], ENT_QUOTES, 'UTF-8'); ?></label>
                            </div>
                        <?php endforeach; ?>
                    </div>
                </div>

                <!-- Dropdown الصفوف -->
                <div class="dropdown d-inline-block">
                    <button class="btn btn-light dropdown-toggle btn-sm filter-dropdown-btn" type="button" id="gradeDropdown" data-bs-toggle="dropdown" data-bs-auto-close="outside" aria-expanded="false" style="background: white; border-color: #dee2e6; color: #495057; height: 31px; display: inline-flex; align-items: center; justify-content: space-between; min-width: 140px;">
                        <span>الصفوف: <span id="selectedGradesLabel" class="fw-bold">الكل</span></span>
                    </button>
                    <div class="dropdown-menu p-3" aria-labelledby="gradeDropdown" style="max-height: 250px; overflow-y: auto; min-width: 220px; text-align: right; box-shadow: 0 10px 15px -3px rgba(0, 0, 0, 0.1);">
                        <?php foreach ($grades as $grd): ?>
                            <div class="form-check mb-1 grade-item" data-stage="<?php echo $grd['stage_id']; ?>">
                                <input class="form-check-input grade-checkbox" type="checkbox" value="<?php echo $grd['id']; ?>" id="grade_<?php echo $grd['id']; ?>">
                                <label class="form-check-label" for="grade_<?php echo $grd['id']; ?>"><?php echo htmlspecialchars($grd['grade_name'], ENT_QUOTES, 'UTF-8'); ?></label>
                            </div>
                        <?php endforeach; ?>
                    </div>
                </div>

                <!-- Dropdown الفصول -->
                <div class="dropdown d-inline-block">
                    <button class="btn btn-light dropdown-toggle btn-sm filter-dropdown-btn" type="button" id="classDropdown" data-bs-toggle="dropdown" data-bs-auto-close="outside" aria-expanded="false" style="background: white; border-color: #dee2e6; color: #495057; height: 31px; display: inline-flex; align-items: center; justify-content: space-between; min-width: 140px;">
                        <span>الفصول: <span id="selectedClassesLabel" class="fw-bold">الكل</span></span>
                    </button>
                    <div class="dropdown-menu p-3" aria-labelledby="classDropdown" style="max-height: 250px; overflow-y: auto; min-width: 220px; text-align: right; box-shadow: 0 10px 15px -3px rgba(0, 0, 0, 0.1);">
                        <?php foreach ($classes as $cls): ?>
                            <div class="form-check mb-1 class-item" data-grade="<?php echo $cls['grade_id']; ?>">
                                <input class="form-check-input class-checkbox" type="checkbox" value="<?php echo $cls['id']; ?>" id="class_<?php echo $cls['id']; ?>">
                                <label class="form-check-label" for="class_<?php echo $cls['id']; ?>"><?php echo htmlspecialchars($cls['class_name'], ENT_QUOTES, 'UTF-8'); ?></label>
                            </div>
                        <?php endforeach; ?>
                    </div>
                </div>

                <!-- Dropdown الطلاب -->
                <div class="dropdown d-inline-block">
                    <button class="btn btn-light dropdown-toggle btn-sm filter-dropdown-btn" type="button" id="studentDropdown" data-bs-toggle="dropdown" data-bs-auto-close="outside" aria-expanded="false" style="background: white; border-color: #dee2e6; color: #495057; height: 31px; display: inline-flex; align-items: center; justify-content: space-between; min-width: 160px;">
                        <span>الطلاب: <span id="selectedStudentsLabel" class="fw-bold">الكل</span></span>
                    </button>
                    <div class="dropdown-menu p-3" aria-labelledby="studentDropdown" style="max-height: 300px; overflow-y: auto; min-width: 280px; text-align: right; box-shadow: 0 10px 15px -3px rgba(0, 0, 0, 0.1);">
                        <div class="d-flex justify-content-between align-items-center mb-2 pb-2 border-bottom">
                            <span class="fw-bold text-muted" style="font-size: 0.8rem;">تحديد الطلاب</span>
                            <div class="btn-group btn-group-sm">
                                <button type="button" class="btn btn-light btn-sm py-0 px-2" onclick="selectAll()" style="font-size: 0.75rem;">تحديد الكل</button>
                                <button type="button" class="btn btn-light btn-sm py-0 px-2" onclick="clearAll()" style="font-size: 0.75rem;">إلغاء الكل</button>
                            </div>
                        </div>
                        <div id="studentCheckboxesContainer">
                            <?php foreach ($allStudents as $st):
                                $isSelected = in_array((int)$st['id'], $selectedIds) ? 'checked' : '';
                                $grade = $st['grade_name'] ? ' (' . $st['grade_name'] . ')' : '';
                            ?>
                                <div class="form-check mb-1 student-item" 
                                     data-stage="<?php echo (int)$st['stage_id']; ?>" 
                                     data-grade="<?php echo (int)$st['grade_id']; ?>" 
                                     data-class="<?php echo (int)$st['class_id']; ?>">
                                    <input class="form-check-input student-checkbox" type="checkbox" name="student_ids[]" value="<?php echo (int)$st['id']; ?>" id="stu_<?php echo (int)$st['id']; ?>" <?php echo $isSelected; ?>>
                                    <label class="form-check-label flex-grow-1" for="stu_<?php echo (int)$st['id']; ?>">
                                        <?php echo htmlspecialchars($st['name'] . $grade, ENT_QUOTES, 'UTF-8'); ?>
                                    </label>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    </div>
                </div>
            </div>
            
            <div class="admin-filter-actions">
                <button type="button" class="btn btn-light btn-sm" onclick="resetFilters()">
                    <i class="fas fa-rotate-left me-1"></i>إعادة تعيين
                </button>
                <button type="button" class="btn btn-light btn-sm" data-bs-toggle="modal" data-bs-target="#printSettingsModal">
                    <i class="fas fa-sliders-h me-1"></i>تحديد البيانات وإعدادات الطباعة
                </button>
                <button type="submit" class="btn btn-primary btn-sm px-3">
                    <i class="fas fa-eye me-1"></i>عرض الملف
                </button>
            </div>
        </div>

        <!-- ===== Modal تحديد البيانات وإعدادات الطباعة ===== -->
        <div class="modal fade" id="printSettingsModal" tabindex="-1" aria-labelledby="printSettingsModalLabel" aria-hidden="true">
            <div class="modal-dialog modal-lg modal-dialog-centered modal-dialog-scrollable">
                <div class="modal-content admin-modal admin-modal-premium admin-modal-view">
                    <div class="modal-header">
                        <h5 class="modal-title" id="printSettingsModalLabel">
                            <i class="fas fa-sliders-h me-2"></i>تحديد البيانات وإعدادات الطباعة
                        </h5>
                        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="إغلاق"></button>
                    </div>
                    <div class="modal-body p-0 d-flex flex-column">
                        <!-- التبويبات -->
                        <ul class="nav nav-tabs justify-content-center px-4" id="settingsTabs" role="tablist" style="padding-top: 18px !important; margin-bottom: 15px !important;">
                            <li class="nav-item" role="presentation">
                                <button class="nav-link active py-2 fw-bold" id="fields-tab" data-bs-toggle="tab" data-bs-target="#fields-panel" type="button" role="tab" aria-controls="fields-panel" aria-selected="true" style="font-size: 0.88rem;">
                                    <i class="fas fa-list-check me-1"></i>تخصيص بيانات الملف
                                </button>
                            </li>
                            <li class="nav-item" role="presentation">
                                <button class="nav-link py-2 fw-bold" id="options-tab" data-bs-toggle="tab" data-bs-target="#options-panel" type="button" role="tab" aria-controls="options-panel" aria-selected="false" style="font-size: 0.88rem;">
                                    <i class="fas fa-print me-1"></i>إعدادات الطباعة
                                </button>
                            </li>
                        </ul>
                        
                        <div class="tab-content pt-2 px-4 pb-4 overflow-auto" id="settingsTabsContent" style="max-height: 65vh;">
                            <!-- تبويب تخصيص بيانات الملف -->
                            <div class="tab-pane fade show active" id="fields-panel" role="tabpanel" aria-labelledby="fields-tab">
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
                                                        $checked = in_array($c[0], $selectedFields) ? 'checked' : '';
                                                        ?>
                                                        <div class="col-lg-4 col-md-6 col-sm-12">
                                                            <div class="form-check form-switch custom-switch-premium d-flex align-items-center gap-2 m-0">
                                                                <input class="form-check-input col-toggle-checkbox" type="checkbox" role="switch"
                                                                    name="fields[]" value="<?php echo $c[0]; ?>" id="f_<?php echo $c[0]; ?>" <?php echo $checked; ?> style="cursor: pointer; float: none; margin: 0;">
                                                                <label class="form-check-label text-secondary fw-medium flex-grow-1"
                                                                    for="f_<?php echo $c[0]; ?>" style="cursor: pointer; font-size: 0.85rem; text-align: right;"><?php echo htmlspecialchars($c[2]); ?></label>
                                                            </div>
                                                        </div>
                                                    <?php endif; ?>
                                                <?php endforeach; ?>
                                            </div>
                                        </div>
                                    </div>
                                <?php endforeach; ?>
                            </div>
                            
                            <!-- تبويب إعدادات الطباعة -->
                            <div class="tab-pane fade" id="options-panel" role="tabpanel" aria-labelledby="options-tab">
                                <div class="sf-print-options p-3 border rounded bg-light">
                                    <p class="fw-bold mb-3 text-dark" style="font-size: 0.88rem;">إعدادات ملف الطالب:</p>
                                    <div class="form-check form-switch custom-switch-premium d-flex align-items-center gap-2 m-0">
                                        <input class="form-check-input" type="checkbox" name="opt_photo" id="opt_photo"
                                               <?php echo $printOptions['show_photo'] ? 'checked' : ''; ?> style="cursor: pointer; float: none; margin: 0;">
                                        <label class="form-check-label fw-bold text-secondary flex-grow-1" for="opt_photo" style="cursor: pointer; font-size: 0.88rem; text-align: right;">
                                            الصورة الشخصية للطالب
                                        </label>
                                    </div>
                                    <hr class="my-3">
                                    <p class="text-muted m-0" style="font-size: 0.8rem;">
                                        <i class="fas fa-info-circle me-1"></i>
                                        ملاحظة: شعار المدرسة واسمها يظهران تلقائياً كبيانات أساسية في الملف، كما تم إلغاء خانات التوقيعات تماماً بناءً على التعليمات.
                                    </p>
                                </div>
                            </div>
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="submit" form="sfForm" class="btn btn-primary">
                            <i class="fas fa-save me-1"></i>حفظ التغييرات
                        </button>
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">
                            <i class="fas fa-times me-1"></i>إلغاء
                        </button>
                    </div>
                </div>
            </div>
        </div>

    </form>
</div>

<!-- ===== منطقة الطباعة ===== -->
<div id="printArea">
<?php if (!empty($studentsData)):
    foreach ($studentsData as $stu):
        $showFooter = ($printOptions['show_affairs'] && $studentAffairs) || ($printOptions['show_director'] && $schoolDirector) || ($printOptions['show_admin_director'] && $adminDirector);
?>
<div class="sf-student-block">

    <!-- البانر العلوي الموحد (شعار المدرسة والألوان الرسمية) -->
    <div class="sf-banner">
        <div class="d-flex justify-content-between align-items-center flex-wrap gap-3">
            <div class="d-flex align-items-center gap-3">
                <?php if ($logoPath): ?>
                <div class="sf-banner-logo">
                    <img src="<?php echo $logoPath; ?>" alt="شعار المدرسة" style="height: 55px; filter: drop-shadow(0 2px 4px rgba(0,0,0,0.15));">
                </div>
                <?php endif; ?>
                <div>
                    <?php if ($schoolName): ?>
                    <div class="sf-banner-school"><?php echo sf($schoolName); ?></div>
                    <?php endif; ?>
                    <?php if ($eduDirectorate || $eduAdmin): ?>
                    <div class="sf-banner-dir">
                        <?php echo sf($eduDirectorate); ?>
                        <?php if ($eduDirectorate && $eduAdmin): echo ' | '; endif; ?>
                        <?php echo sf($eduAdmin); ?>
                    </div>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>

    <!-- شريط الملف الشخصي المتداخل (اسم الطالب وصورته) -->
    <div class="sf-profile-bar position-relative" style="min-height: 80px;">
        <div class="sf-profile-info text-center w-100" style="margin-top: 15px;">
            <div class="sf-student-name">
                <?php echo sf($stu['name']); ?>
            </div>
            <div class="sf-student-meta mt-2 d-flex flex-wrap justify-content-center gap-3">
                <?php if ($stu['student_code']): ?>
                <span><i class="fas fa-hashtag me-1"></i><?php echo sf($stu['student_code']); ?></span>
                <?php endif; ?>
                <?php if ($stu['grade_name']): ?>
                <span><i class="fas fa-layer-group me-1"></i><?php echo sf($stu['grade_name']); ?></span>
                <?php endif; ?>
                <?php if ($stu['class_name']): ?>
                <span><i class="fas fa-door-open me-1"></i><?php echo sf($stu['class_name']); ?></span>
                <?php endif; ?>
            </div>
        </div>

        <?php if ($printOptions['show_photo']): ?>
        <div class="sf-profile-photo-wrap" style="position: absolute; left: 35px; top: -115px; z-index: 15;">
            <?php if (!empty($stu['_profile_image'])): ?>
                <img src="<?php echo htmlspecialchars(ProfileAttachmentStorage::adminDownloadUrl('student', (int)$stu['_profile_image_id']), ENT_QUOTES, 'UTF-8'); ?>" class="sf-profile-photo">
            <?php else: ?>
                <div class="sf-profile-photo-placeholder d-flex align-items-center justify-content-center">
                    <i class="fas fa-user fa-4x"></i>
                </div>
            <?php endif; ?>
        </div>
        <?php endif; ?>
    </div>

    <!-- ===== شبكة الأقسام (عمودين) ===== -->
    <div class="sf-sections-grid">
        <!-- العمود الأيمن (البيانات الشخصية، بيانات الاتصال) -->
        <div class="sf-grid-column">
            <!-- ===== البيانات الشخصية ===== -->
            <?php if ($sections['personal']): ?>
            <div class="sf-section-card" style="margin-top: 0;">
                <div class="sf-section-badge">
                    <i class="fas fa-user me-1"></i>
                    <span>البيانات الشخصية</span>
                </div>
                <table class="sf-data-table">
                    <?php if (in_array('name_ar', $selectedFields)):
                        $arName = trim(implode(' ', array_filter([
                            $stu['first_name_ar'], $stu['second_name_ar'],
                            $stu['third_name_ar'], $stu['fourth_name_ar'], $stu['family_name_ar']
                        ])));
                        if (empty($arName)) $arName = $stu['name'];
                    ?>
                    <tr><td>الاسم باللغة العربية</td><td><?php echo sf($arName); ?></td></tr>
                    <?php endif; ?>
    
                    <?php if (in_array('name_en', $selectedFields)):
                        $enName = trim(implode(' ', array_filter([
                            $stu['first_name_en'], $stu['second_name_en'],
                            $stu['third_name_en'], $stu['fourth_name_en'], $stu['family_name_en']
                        ])));
                    ?>
                    <tr><td>الاسم باللغة الإنجليزية</td><td><?php echo sf($enName); ?></td></tr>
                    <?php endif; ?>
    
                    <?php
                    $religionMap = ['muslim' => 'مسلم', 'christian' => 'مسيحي', 'other' => 'أخرى'];
                    if (in_array('religion', $selectedFields)): ?>
                    <tr><td>الديانة</td><td><?php echo $religionMap[$stu['religion'] ?? ''] ?? sf($stu['religion'] ?? ''); ?></td></tr>
                    <?php endif; ?>
    
                    <?php
                    $genderMap = ['male' => 'ذكر', 'female' => 'أنثى'];
                    if (in_array('gender', $selectedFields)): ?>
                    <tr><td>النوع</td><td><?php echo $genderMap[$stu['gender'] ?? ''] ?? sf($stu['gender'] ?? ''); ?></td></tr>
                    <?php endif; ?>
    
                    <?php if (in_array('nationality', $selectedFields)): ?>
                    <tr><td>الجنسية</td><td><?php echo sf($stu['nationality']); ?></td></tr>
                    <?php endif; ?>
    
                    <?php if (in_array('national_id', $selectedFields)): ?>
                    <tr><td>الرقم القومي للمصريين</td><td><?php echo sf($stu['national_id']); ?></td></tr>
                    <?php endif; ?>
    
                    <?php if (in_array('passport_number', $selectedFields)): ?>
                    <tr><td>رقم جواز السفر</td><td><?php echo sf($stu['passport_number']); ?></td></tr>
                    <?php endif; ?>
    
                    <?php if (in_array('birth_date', $selectedFields)): ?>
                    <tr><td>تاريخ الميلاد</td><td><?php echo sf($stu['birth_date']); ?></td></tr>
                    <?php endif; ?>
    
                    <?php if (in_array('current_age', $selectedFields)):
                        $curAge = User::calculateCurrentAge($stu['birth_date'] ?? null);
                        $ageStr = ($curAge && empty($curAge['is_future'])) ? $curAge['years'] . ' سنة و ' . $curAge['months'] . ' شهر و ' . $curAge['days'] . ' يوم' : '—';
                    ?>
                    <tr><td>العمر الحالي</td><td><?php echo htmlspecialchars($ageStr, ENT_QUOTES, 'UTF-8'); ?></td></tr>
                    <?php endif; ?>
    
                    <?php if (in_array('age_october', $selectedFields)):
                        $octAge = User::calculateAgeFromOctober($stu['birth_date'] ?? null);
                        $ageOctStr = $octAge ? $octAge['years'] . ' سنة و ' . $octAge['months'] . ' شهر و ' . $octAge['days'] . ' يوم' : '—';
                    ?>
                    <tr><td>العمر في 1 أكتوبر من العام الحالي</td><td><?php echo htmlspecialchars($ageOctStr, ENT_QUOTES, 'UTF-8'); ?></td></tr>
                    <?php endif; ?>
    
                    <?php if (in_array('birth_place', $selectedFields)): ?>
                    <tr><td>محل الميلاد</td><td><?php echo sf($stu['birth_place']); ?></td></tr>
                    <?php endif; ?>
    
                    <?php if (in_array('educational_guardianship', $selectedFields)):
                        $relationLabels = [
                            'father' => 'الأب',
                            'mother' => 'الأم',
                            'grandfather' => 'الجد',
                            'grandmother' => 'الجدة',
                            'uncle_paternal' => 'العم',
                            'aunt_paternal' => 'العمة',
                            'uncle_maternal' => 'الخال',
                            'aunt_maternal' => 'الخالة',
                            'brother' => 'الأخ',
                            'sister' => 'الأخت',
                            'legal_guardian' => 'وصي قانوني',
                            'other' => 'أخرى'
                        ];
                        // Extract guardianship from extra_data
                        $guardianshipVal = '';
                        $items = json_decode((string)($stu['extra_data'] ?? ''), true);
                        if (is_array($items)) {
                            foreach ($items as $item) {
                                if (in_array($item['label'] ?? '', ['__educational_guardianship', 'الوصاية التعليمية'], true)) {
                                    $guardianshipVal = (string) ($item['value'] ?? '');
                                    break;
                                }
                            }
                        }
                        $guardianshipLabel = $relationLabels[$guardianshipVal] ?? $guardianshipVal;
                    ?>
                    <tr><td>الوصاية التعليمية</td><td><?php echo sf($guardianshipLabel); ?></td></tr>
                    <?php endif; ?>
                </table>
            </div>
            <?php endif; ?>
    
            <!-- ===== العناوين وبيانات التواصل ===== -->
            <?php if ($sections['contact']): ?>
            <div class="sf-section-card" style="margin-top: 0;">
                <div class="sf-section-badge">
                    <i class="fas fa-phone me-1"></i>
                    <span>العناوين وبيانات التواصل</span>
                </div>
                <table class="sf-data-table">
                    <?php if (in_array('city_area', $selectedFields)): ?>
                    <tr><td>المدينة / المنطقة</td><td><?php echo sf($stu['city_area']); ?></td></tr>
                    <?php endif; ?>
    
                    <?php if (in_array('address_current', $selectedFields)): ?>
                    <tr><td>العنوان التفصيلي</td><td><?php echo sf($stu['address_current']); ?></td></tr>
                    <?php endif; ?>
    
                    <?php if (in_array('phone_emergency', $selectedFields)): ?>
                    <tr><td>رقم الطوارئ</td><td><?php echo sf($stu['phone_emergency']); ?></td></tr>
                    <?php endif; ?>
    
                    <?php if (in_array('phone_mobile', $selectedFields)): ?>
                    <tr><td>رقم موبايل الطالب الأساسي</td><td><?php echo sf($stu['phone_mobile']); ?></td></tr>
                    <?php endif; ?>
    
                    <?php if (in_array('phone_home', $selectedFields)): ?>
                    <tr><td>رقم الهاتف الأرضي الأساسي</td><td><?php echo sf($stu['phone_home']); ?></td></tr>
                    <?php endif; ?>
                </table>
            </div>
            <?php endif; ?>
        </div>

        <!-- العمود الأيسر (حالة القيد والرمز، الحالة الصحية) -->
        <div class="sf-grid-column">
            <!-- ===== كود الطالب وحالة القيد الدراسي ===== -->
            <?php if ($sections['enrollment']): ?>
            <div class="sf-section-card" style="margin-top: 0;">
                <div class="sf-section-badge">
                    <i class="fas fa-barcode me-1"></i>
                    <span>كود الطالب وحالة القيد الدراسي</span>
                </div>
                <table class="sf-data-table">
                    <?php if (in_array('student_code', $selectedFields)): ?>
                    <tr><td>كود الطالب لدى المدرسة</td><td><?php echo sf($stu['student_code']); ?></td></tr>
                    <?php endif; ?>
    
                    <?php if (in_array('ministry_code', $selectedFields)): ?>
                    <tr><td>كود الطالب بوزارة التربية والتعليم</td><td><?php echo sf($stu['ministry_code']); ?></td></tr>
                    <?php endif; ?>
    
                    <?php if (in_array('grade_name', $selectedFields)): ?>
                    <tr><td>الصف</td><td><?php echo sf($stu['grade_name']); ?></td></tr>
                    <?php endif; ?>
    
                    <?php if (in_array('class_name', $selectedFields)): ?>
                    <tr><td>الفصل</td><td><?php echo sf($stu['class_name']); ?></td></tr>
                    <?php endif; ?>
    
                    <?php if (in_array('transfer_reason', $selectedFields)): ?>
                    <tr><td>سبب النقل</td><td><?php echo sf($stu['transfer_reason']); ?></td></tr>
                    <?php endif; ?>
    
                    <?php
                    $statusMap = ['enrolled' => 'مقيد', 'graduated' => 'متخرج', 'transferred' => 'محول', 'withdrawn' => 'منسحب'];
                    if (in_array('enrollment_status', $selectedFields)): ?>
                    <tr><td>حالة القيد</td><td><?php echo $statusMap[$stu['enrollment_status'] ?? ''] ?? sf($stu['enrollment_status'] ?? ''); ?></td></tr>
                    <?php endif; ?>
    
                    <?php if (in_array('enrollment_date', $selectedFields)): ?>
                    <tr><td>تاريخ القيد بالمدرسة</td><td><?php echo sf($stu['enrollment_date']); ?></td></tr>
                    <?php endif; ?>
    
                    <?php if (in_array('previous_school', $selectedFields)): ?>
                    <tr><td>المدرسة القادم منها الطالب</td><td><?php echo sf($stu['previous_school']); ?></td></tr>
                    <?php endif; ?>
                </table>
            </div>
            <?php endif; ?>
    
            <!-- ===== البيانات الصحية والنفسية ===== -->
            <?php if ($sections['health']): ?>
            <div class="sf-section-card" style="margin-top: 0;">
                <div class="sf-section-badge">
                    <i class="fas fa-heartbeat me-1"></i>
                    <span>البيانات الصحية والنفسية</span>
                </div>
                <table class="sf-data-table">
                    <?php
                    $healthFieldsMap = [
                        'blood_type' => ['فصيلة الدم', sf($stu['blood_type'])],
                        'insurance_number' => ['رقم التأمين الطبي', sf($stu['insurance_number'])],
                        'insurance_start_date' => ['تاريخ بداية التأمين', sf($stu['insurance_start_date'])],
                        'insurance_end_date' => ['تاريخ نهاية التأمين', sf($stu['insurance_end_date'])],
                        'health_status' => ['الحالة الصحية العامة', sf($stu['health_status'])],
                        'chronic_diseases' => ['الأمراض المزمنة', sf($stu['chronic_diseases'])],
                        'allergies' => ['الحساسية', sf($stu['allergies'])],
                        'disabilities' => ['الإعاقات', sf($stu['disabilities'])],
                        'medications' => ['العلاج / الأدوية', sf($stu['medications'])],
                        'treatment_plan' => ['خطط علاجية متبعة', sf($stu['treatment_plan'])],
                        'previous_medical_reports' => ['تقارير طبية سابقة', sf($stu['previous_medical_reports'])],
                        'emergency_medical_notes' => ['ملاحظات طبية طارئة', sf($stu['emergency_medical_notes'])],
                        'psychological_notes' => ['الحالة النفسية والسلوكية', sf($stu['psychological_notes'])],
                    ];
                    $hasHealth = false;
                    foreach ($healthFieldsMap as $fKey => $fInfo):
                        if (!in_array($fKey, $selectedFields)) continue;
                        $hasHealth = true;
                    ?>
                    <tr><td><?php echo $fInfo[0]; ?></td><td><?php echo $fInfo[1]; ?></td></tr>
                    <?php endforeach; ?>
                    <?php if (!$hasHealth): ?>
                    <tr><td colspan="2" class="text-muted small">لا توجد بيانات صحية أو نفسية مسجلة</td></tr>
                    <?php endif; ?>
                </table>
            </div>
            <?php endif; ?>
        </div>
    </div><!-- /.sf-sections-grid -->

    <!-- ===== بيانات الأب والأم بجانب بعضهما ===== -->
    <?php if ($sections['father'] || $sections['mother']): ?>
    <div class="sf-parent-row-container">
        <div class="sf-parent-row" style="grid-template-columns: <?php echo ($sections['father'] && $sections['mother']) ? '1fr 1fr' : '1fr'; ?>;">
            <!-- ===== بيانات الأب ===== -->
            <?php if ($sections['father']): ?>
            <div class="sf-section-card" style="margin-top: 0;">
                <div class="sf-section-badge">
                    <i class="fas fa-user-tie me-1"></i>
                    <span>بيانات الأب</span>
                </div>
                <?php
                $fatherGrd = null;
                if (!empty($stu['_guardians'])) {
                    foreach ($stu['_guardians'] as $grd) {
                        if (($grd['relationship'] ?? '') === 'father') {
                            $fatherGrd = $grd;
                            break;
                        }
                    }
                }
                if ($fatherGrd):
                    $relationLabels = [
                        'father' => 'الأب',
                        'mother' => 'الأم',
                        'grandfather' => 'الجد',
                        'grandmother' => 'الجدة',
                        'uncle_paternal' => 'العم',
                        'aunt_paternal' => 'العمة',
                        'uncle_maternal' => 'الخال',
                        'aunt_maternal' => 'الخالة',
                        'brother' => 'الأخ',
                        'sister' => 'الأخت',
                        'legal_guardian' => 'وصي قانوني',
                        'other' => 'أخرى'
                    ];
                    $religionMap = ['muslim' => 'مسلم', 'christian' => 'مسيحي', 'other' => 'أخرى'];
    
                    $fatherFieldsMap = [
                        'father_name' => ['الاسم الرباعي', sf($fatherGrd['guardian_name'] ?? '')],
                        'father_relation' => ['صلة القرابة بالطالب', $relationLabels[$fatherGrd['relationship'] ?? ''] ?? sf($fatherGrd['relationship'] ?? '')],
                        'father_birth_date' => ['تاريخ الميلاد', sf($fatherGrd['birth_date'] ?? '')],
                        'father_birth_place' => ['محل الميلاد', sf($fatherGrd['birth_place'] ?? '')],
                        'father_religion' => ['الديانة', $religionMap[$fatherGrd['religion'] ?? ''] ?? sf($fatherGrd['religion'] ?? '')],
                        'father_nationality' => ['الجنسية', sf($fatherGrd['nationality'] ?? '')],
                        'father_national_id' => ['الرقم القومي للمصريين', sf($fatherGrd['national_id'] ?? '')],
                        'father_passport_number' => ['رقم جواز السفر', sf($fatherGrd['passport_number'] ?? '')],
                        'father_phone' => ['رقم الموبايل الأساسي', sf($fatherGrd['phone_primary'] ?? '')],
                        'father_landline' => ['رقم الهاتف الأرضي الأساسي', sf($fatherGrd['phone_landline'] ?? '')],
                        'father_email' => ['البريد الإلكتروني', sf($fatherGrd['email'] ?? '')],
                        'father_address' => ['العنوان الحالي بالتفصيل', sf($fatherGrd['address'] ?? '')],
                        'father_qualification' => ['المؤهل الدراسي', sf($fatherGrd['qualification'] ?? '')],
                        'father_job_title' => ['الوظيفة / المسمى الوظيفي', sf($fatherGrd['job_title'] ?? '')],
                        'father_employer' => ['جهة العمل / الشركة', sf($fatherGrd['employer'] ?? '')],
                        'father_work_phone' => ['هاتف العمل', sf($fatherGrd['work_phone'] ?? '')],
                    ];
                ?>
                <table class="sf-data-table">
                    <?php foreach ($fatherFieldsMap as $fKey => [$fLabel, $fVal]):
                        if (!in_array($fKey, $selectedFields)) continue;
                    ?>
                    <tr><td><?php echo $fLabel; ?></td><td><?php echo $fVal; ?></td></tr>
                    <?php endforeach; ?>
                </table>
                <?php else: ?>
                <p class="small text-muted ms-2 pt-2">بيانات الأب غير مسجلة.</p>
                <?php endif; ?>
            </div>
            <?php endif; ?>
    
            <!-- ===== بيانات الأم ===== -->
            <?php if ($sections['mother']): ?>
            <div class="sf-section-card" style="margin-top: 0;">
                <div class="sf-section-badge">
                    <i class="fas fa-person-breastfeeding me-1"></i>
                    <span>بيانات الأم</span>
                </div>
                <?php
                $motherGrd = null;
                if (!empty($stu['_guardians'])) {
                    foreach ($stu['_guardians'] as $grd) {
                        if (($grd['relationship'] ?? '') === 'mother') {
                            $motherGrd = $grd;
                            break;
                        }
                    }
                }
                if ($motherGrd):
                    $relationLabels = [
                        'father' => 'الأب',
                        'mother' => 'الأم',
                        'grandfather' => 'الجد',
                        'grandmother' => 'الجدة',
                        'uncle_paternal' => 'العم',
                        'aunt_paternal' => 'العمة',
                        'uncle_maternal' => 'الخال',
                        'aunt_maternal' => 'الخالة',
                        'brother' => 'الأخ',
                        'sister' => 'الأخت',
                        'legal_guardian' => 'وصي قانوني',
                        'other' => 'أخرى'
                    ];
                    $religionMap = ['muslim' => 'مسلم', 'christian' => 'مسيحي', 'other' => 'أخرى'];
    
                    $motherFieldsMap = [
                        'mother_name' => ['الاسم الرباعي', sf($motherGrd['guardian_name'] ?? '')],
                        'mother_relation' => ['صلة القرابة بالطالب', $relationLabels[$motherGrd['relationship'] ?? ''] ?? sf($motherGrd['relationship'] ?? '')],
                        'mother_birth_date' => ['تاريخ الميلاد', sf($motherGrd['birth_date'] ?? '')],
                        'mother_birth_place' => ['محل الميلاد', sf($motherGrd['birth_place'] ?? '')],
                        'mother_religion' => ['الديانة', $religionMap[$motherGrd['religion'] ?? ''] ?? sf($motherGrd['religion'] ?? '')],
                        'mother_nationality' => ['الجنسية', sf($motherGrd['nationality'] ?? '')],
                        'mother_national_id' => ['الرقم القومي للمصريين', sf($motherGrd['national_id'] ?? '')],
                        'mother_passport_number' => ['رقم جواز السفر', sf($motherGrd['passport_number'] ?? '')],
                        'mother_phone' => ['رقم الموبايل الأساسي', sf($motherGrd['phone_primary'] ?? '')],
                        'mother_landline' => ['رقم الهاتف الأرضي الأساسي', sf($motherGrd['phone_landline'] ?? '')],
                        'mother_email' => ['البريد الإلكتروني', sf($motherGrd['email'] ?? '')],
                        'mother_address' => ['العنوان الحالي بالتفصيل', sf($motherGrd['address'] ?? '')],
                        'mother_qualification' => ['المؤهل الدراسي', sf($motherGrd['qualification'] ?? '')],
                        'mother_job_title' => ['الوظيفة / المسمى الوظيفي', sf($motherGrd['job_title'] ?? '')],
                        'mother_employer' => ['جهة العمل / الشركة', sf($motherGrd['employer'] ?? '')],
                        'mother_work_phone' => ['هاتف العمل', sf($motherGrd['work_phone'] ?? '')],
                    ];
                ?>
                <table class="sf-data-table">
                    <?php foreach ($motherFieldsMap as $fKey => [$fLabel, $fVal]):
                        if (!in_array($fKey, $selectedFields)) continue;
                    ?>
                    <tr><td><?php echo $fLabel; ?></td><td><?php echo $fVal; ?></td></tr>
                    <?php endforeach; ?>
                </table>
                <?php else: ?>
                <p class="small text-muted ms-2 pt-2">بيانات الأم غير مسجلة.</p>
                <?php endif; ?>
            </div>
            <?php endif; ?>
        </div>
    </div>
    <?php endif; ?>

    <!-- ===== الإخوة (خارج الشبكة لملء العرض) ===== -->
    <?php if ($sections['siblings'] && in_array('siblings_list', $selectedFields)): ?>
    <div class="sf-siblings-wrap">
        <div class="sf-section-card">
            <div class="sf-section-badge">
                <i class="fas fa-user-friends me-1"></i>
                <span>الإخوة المسجلون</span>
            </div>
            <?php if (!empty($stu['_siblings'])): ?>
            <table class="sf-data-table">
                <tr style="background:#f8fafc;">
                    <td class="fw-bold">الاسم</td>
                    <td class="fw-bold">صلة القرابة</td>
                    <td class="fw-bold">الصف</td>
                    <td class="fw-bold">الفصل</td>
                </tr>
                <?php foreach ($stu['_siblings'] as $sib): ?>
                <tr>
                    <td><?php echo sf($sib['sibling_name']); ?></td>
                    <td><?php echo sf($sib['relationship']); ?></td>
                    <td><?php echo sf($sib['grade_name']); ?></td>
                    <td><?php echo sf($sib['class_name']); ?></td>
                </tr>
                <?php endforeach; ?>
            </table>
            <?php else: ?>
            <p class="small text-muted ms-2 pt-2">لا يوجد إخوة مسجلون مرتبطون بهذا الطالب.</p>
            <?php endif; ?>
        </div>
    </div>
    <?php endif; ?>



</div><!-- /.sf-student-block -->
<?php endforeach;
elseif ($_SERVER['REQUEST_METHOD'] === 'POST'): ?>
<div class="alert alert-warning no-print">
    <i class="fas fa-exclamation-triangle me-2"></i>
    يرجى اختيار طالب واحد على الأقل وتحديد أقسام البيانات المطلوبة.
</div>
<?php else: ?>
<div class="alert alert-info no-print">
    <i class="fas fa-info-circle me-2"></i>
    اختر الطلاب والأقسام المطلوبة من لوحة التحكم أعلاه ثم اضغط <strong>عرض الملف</strong>.
</div>
<?php endif; ?>
</div><!-- /#printArea -->

<script>
document.addEventListener("DOMContentLoaded", function() {
    // ربط فلاتر الاختيار بالحدث change
    document.querySelectorAll('.stage-checkbox, .grade-checkbox, .class-checkbox').forEach(cb => {
        cb.addEventListener('change', applyCascadingFilters);
    });

    // ربط checkboxes الطلاب بتحديث التسمية
    document.querySelectorAll('.student-checkbox').forEach(cb => {
        cb.addEventListener('change', updateSelectedStudentsLabel);
    });

    // تهيئة التسمية للمرة الأولى
    updateSelectedStudentsLabel();

    // التحقق من اختيار طالب واحد على الأقل قبل الإرسال
    const formEl = document.getElementById('sfForm');
    if (formEl) {
        formEl.addEventListener('submit', function(e) {
            const checkedStudents = document.querySelectorAll('.student-checkbox:checked');
            if (checkedStudents.length === 0) {
                alert('يرجى اختيار طالب واحد على الأقل.');
                e.preventDefault();
            }
        });
    }

    // تحديد الكل والبيانات الأساسية بمودال إعدادات الطباعة
    const selectAllBtn = document.getElementById('selectAllColumns');
    if (selectAllBtn) {
        selectAllBtn.addEventListener('click', function() {
            document.querySelectorAll('#printSettingsModal .col-toggle-checkbox').forEach(cb => cb.checked = true);
        });
    }
    const deselectAllBtn = document.getElementById('deselectAllColumns');
    if (deselectAllBtn) {
        deselectAllBtn.addEventListener('click', function() {
            document.querySelectorAll('#printSettingsModal .col-toggle-checkbox').forEach(cb => cb.checked = false);
        });
    }
    const selectBasicBtn = document.getElementById('selectBasicColumns');
    if (selectBasicBtn) {
        selectBasicBtn.addEventListener('click', function() {
            const basic = [
                'name_ar', 'name_en', 'gender', 'national_id', 'birth_date', 'birth_place',
                'student_code', 'ministry_code', 'grade_name', 'class_name', 'enrollment_date',
                'city_area', 'address_current', 'phone_emergency',
                'father_name', 'father_birth_date', 'father_birth_place', 'father_national_id', 'father_phone', 'father_qualification', 'father_job_title', 'father_employer',
                'mother_name', 'mother_birth_date', 'mother_birth_place', 'mother_national_id', 'mother_phone', 'mother_qualification', 'mother_job_title', 'mother_employer',
                'blood_type', 'insurance_number', 'insurance_start_date', 'insurance_end_date', 'health_status', 'medications',
                'siblings_list'
            ];
            document.querySelectorAll('#printSettingsModal .col-toggle-checkbox').forEach(function(cb) {
                cb.checked = basic.includes(cb.value);
            });
        });
    }

    // تحديد وإلغاء تحديد الأقسام بمودال إعدادات الطباعة
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
});

function applyCascadingFilters() {
    const checkedStages = Array.from(document.querySelectorAll('.stage-checkbox:checked')).map(cb => cb.value);
    const checkedGrades = Array.from(document.querySelectorAll('.grade-checkbox:checked')).map(cb => cb.value);
    const checkedClasses = Array.from(document.querySelectorAll('.class-checkbox:checked')).map(cb => cb.value);

    // تفعيل وتحديث حالة الزر النشط في الفلاتر
    document.getElementById('stageDropdown').classList.toggle('active-filter', checkedStages.length > 0);
    document.getElementById('gradeDropdown').classList.toggle('active-filter', checkedGrades.length > 0);
    document.getElementById('classDropdown').classList.toggle('active-filter', checkedClasses.length > 0);

    // تحديث نصوص الفلاتر
    document.getElementById('selectedStagesLabel').textContent = checkedStages.length > 0 ? checkedStages.length + ' حدد' : 'الكل';
    document.getElementById('selectedGradesLabel').textContent = checkedGrades.length > 0 ? checkedGrades.length + ' حدد' : 'الكل';
    document.getElementById('selectedClassesLabel').textContent = checkedClasses.length > 0 ? checkedClasses.length + ' حدد' : 'الكل';

    // إخفاء/إظهار الصفوف بناءً على المراحل المحددة
    document.querySelectorAll('.grade-item').forEach(item => {
        const stageId = item.getAttribute('data-stage');
        if (checkedStages.length === 0 || checkedStages.includes(stageId)) {
            item.style.display = '';
        } else {
            item.style.display = 'none';
            const input = item.querySelector('.grade-checkbox');
            if (input) input.checked = false;
        }
    });

    // إخفاء/إظهار الفصول بناءً على الصفوف المحددة
    document.querySelectorAll('.class-item').forEach(item => {
        const gradeId = item.getAttribute('data-grade');
        const gradeInput = document.querySelector(`.grade-checkbox[value="${gradeId}"]`);
        const gradeItem = gradeInput ? gradeInput.closest('.grade-item') : null;
        const stageId = gradeItem ? gradeItem.getAttribute('data-stage') : null;

        const stageMatches = checkedStages.length === 0 || (stageId && checkedStages.includes(stageId));
        const gradeMatches = checkedGrades.length === 0 || checkedGrades.includes(gradeId);

        if (stageMatches && gradeMatches) {
            item.style.display = '';
        } else {
            item.style.display = 'none';
            const input = item.querySelector('.class-checkbox');
            if (input) input.checked = false;
        }
    });

    // إعادة القراءة بعد إلغاء تحديد الفلاتر المخفية
    const activeStages = Array.from(document.querySelectorAll('.stage-checkbox:checked')).map(cb => cb.value);
    const activeGrades = Array.from(document.querySelectorAll('.grade-checkbox:checked')).map(cb => cb.value);
    const activeClasses = Array.from(document.querySelectorAll('.class-checkbox:checked')).map(cb => cb.value);

    // تصفية وإخفاء/إظهار الطلاب بناءً على الفلاتر النشطة
    document.querySelectorAll('.student-item').forEach(item => {
        const stageId = item.getAttribute('data-stage');
        const gradeId = item.getAttribute('data-grade');
        const classId = item.getAttribute('data-class');

        const matchStg = activeStages.length === 0 || activeStages.includes(stageId);
        const matchGrd = activeGrades.length === 0 || activeGrades.includes(gradeId);
        const matchCls = activeClasses.length === 0 || activeClasses.includes(classId);

        if (matchStg && matchGrd && matchCls) {
            item.style.display = '';
        } else {
            item.style.display = 'none';
            const input = item.querySelector('.student-checkbox');
            if (input) input.checked = false;
        }
    });

    updateSelectedStudentsLabel();
}

function resetFilters() {
    window.location.href = 'student_file.php';
}

function selectAll() {
    document.querySelectorAll('.student-item').forEach(item => {
        if (item.style.display !== 'none') {
            const cb = item.querySelector('.student-checkbox');
            if (cb) cb.checked = true;
        }
    });
    updateSelectedStudentsLabel();
}

function clearAll() {
    document.querySelectorAll('.student-checkbox').forEach(cb => {
        cb.checked = false;
    });
    updateSelectedStudentsLabel();
}

function updateSelectedStudentsLabel() {
    const checked = document.querySelectorAll('.student-checkbox:checked');
    const total = document.querySelectorAll('.student-checkbox').length;
    const label = document.getElementById('selectedStudentsLabel');
    if (label) {
        if (checked.length === 0) {
            label.textContent = 'لا يوجد';
        } else if (checked.length === total) {
            label.textContent = 'الكل';
        } else {
            label.textContent = checked.length + ' حدد';
        }
        document.getElementById('studentDropdown').classList.toggle('active-filter', checked.length > 0);
    }
}



function exportPdf() {
    alert('ستُفتح نافذة الطباعة. من قائمة "الطابعة" اختر "Save as PDF" أو "Microsoft Print to PDF" لحفظ الملف بصيغة PDF.');
    window.print();
}

</script>

<?php require_once '../includes/admin_footer.php'; ?>
