<?php
require_once '../config/database.php';
require_once '../classes/utilities.php';
require_once '../classes/AcademicYear.php';
require_once '../classes/ProfileAttachmentStorage.php';
require_once '../includes/session_config.php';
require_once '../includes/print_template.php';
Utilities::validateSession('admin');
require_once __DIR__ . '/../classes/FinanceLegacyAdapter.php';
FinanceLegacyAdapter::delegateRequestIfEnabled(__FILE__);

$database = new Database();
$db = $database->getConnection();
$currentAcademicYearId = AcademicYear::currentId($db);

$settingsStmt = $db->query("SELECT setting_key, setting_value FROM settings");
$allSettings = $settingsStmt ? $settingsStmt->fetchAll(PDO::FETCH_KEY_PAIR) : [];

$schoolName = $allSettings['school_name'] ?? 'مدرسة الدلتا الحديثة للغات';
$cleanSchoolName = preg_replace('/^(مدرسة|مدارس)\s+/u', '', trim($schoolName));
$directorate = $allSettings['educational_directorate'] ?? 'مديرية التربية والتعليم بالدقهلية';
$administration = $allSettings['educational_administration'] ?? 'إدارة طلخا التعليمية';
$schoolLogo = !empty($allSettings['school_logo']) && file_exists(__DIR__ . '/../uploads/' . $allSettings['school_logo']) ? '../uploads/' . htmlspecialchars($allSettings['school_logo']) : '';

$studentAffairsOfficer = $allSettings['student_affairs_officer'] ?? '';
$schoolDirector = $allSettings['school_director'] ?? '';
$adminDirector = $allSettings['admin_director'] ?? '';

$schoolNameEn = !empty($allSettings['school_name_en']) ? $allSettings['school_name_en'] : translate_setting_to_en('school_name', $schoolName);
$directorateEn = !empty($allSettings['educational_directorate_en']) ? $allSettings['educational_directorate_en'] : translate_setting_to_en('educational_directorate', $directorate);
$administrationEn = !empty($allSettings['educational_administration_en']) ? $allSettings['educational_administration_en'] : translate_setting_to_en('educational_administration', $administration);

$studentAffairsOfficerEn = !empty($allSettings['student_affairs_officer_en']) ? $allSettings['student_affairs_officer_en'] : translate_setting_to_en('student_affairs_officer', $studentAffairsOfficer);
$schoolDirectorEn = !empty($allSettings['school_director_en']) ? $allSettings['school_director_en'] : translate_setting_to_en('school_director', $schoolDirector);
$adminDirectorEn = !empty($allSettings['admin_director_en']) ? $allSettings['admin_director_en'] : translate_setting_to_en('admin_director', $adminDirector);

$typeTitlesEn = [
    'enrollment'      => 'CERTIFICATE OF ENROLLMENT',
    'transfer'        => 'SCHOOL TRANSFER REQUEST',
    'second_session'  => 'SECOND SESSION EXAMINATION NOTICE',
    'withdrawal'      => 'FILE WITHDRAWAL ACKNOWLEDGMENT',
    'behavior'        => 'BEHAVIOR STATEMENT',
    'behavior_pledge' => 'BEHAVIOR ACKNOWLEDGMENT',
    'grades'          => 'STATEMENT OF MARKS',
    'success'         => 'CERTIFICATE OF SUCCESS'
];

$studentId = (int)($_GET['student_id'] ?? 0);
$type = $_GET['type'] ?? 'enrollment';

$validTypes = ['enrollment', 'transfer', 'second_session', 'withdrawal', 'behavior', 'behavior_pledge', 'grades', 'success'];
if (!in_array($type, $validTypes, true)) {
    $type = 'enrollment';
}

$signaturesDefaultOff = in_array($type, ['withdrawal', 'behavior_pledge'], true);

$studentAffairsSigTitle = 'ش.ط المدرسة';
$studentAffairsSigTitleEn = 'School Student Affairs';
$directorSigTitle = 'إدارة المدرسة';
$directorSigTitleEn = 'School Administration';

$typeTitles = [
    'enrollment'      => 'بيـــــان قـيـــــد',
    'transfer'        => 'طلب تحويل',
    'second_session'  => 'إخطار دور ثاني',
    'withdrawal'      => 'إقرار سحب ملف',
    'behavior'        => 'إفادة سلوك',
    'behavior_pledge' => 'إقرار سلوك',
    'grades'          => 'بيان درجات',
    'success'         => 'شهادة نجاح'
];

$typeIcons = [
    'enrollment'      => 'fas fa-id-card',
    'transfer'        => 'fas fa-exchange-alt',
    'second_session'  => 'fas fa-exclamation-triangle',
    'withdrawal'      => 'fas fa-file-contract',
    'behavior'        => 'fas fa-user-check',
    'behavior_pledge' => 'fas fa-user-shield',
    'grades'          => 'fas fa-poll-h',
    'success'         => 'fas fa-award'
];

$documentTitle = $typeTitles[$type];
$documentTitleEn = $typeTitlesEn[$type] ?? 'OFFICIAL STATEMENT';
$documentTitleDisplay = $documentTitle === 'بيان قيد' ? 'بـيــان  قـيــد' : $documentTitle;
$page_title = "إفادات رسمية - " . $documentTitle;
$custom_page_title = true;

// 1. Fetch Stages, Grades, Classes for Filter dropdowns
$stagesList = $db->query("SELECT id, stage_name FROM stages WHERE status = 'active' ORDER BY stage_order, id")->fetchAll(PDO::FETCH_ASSOC);
$gradesList = $db->query("SELECT id, grade_name, stage_id FROM grades ORDER BY grade_order, id")->fetchAll(PDO::FETCH_ASSOC);
$classesList = $db->query("SELECT id, name, grade_id FROM classes WHERE status = 'active' ORDER BY display_order, name")->fetchAll(PDO::FETCH_ASSOC);

// 2. Fetch all student records for the cascading select dropdown
$studentsStmt = $db->prepare("SELECT u.id, u.name, sp.student_code, c.name AS class_name,
       se.stage_id, se.grade_id, se.class_id
    FROM users u
    LEFT JOIN student_profiles sp ON sp.user_id = u.id
    LEFT JOIN student_enrollments se ON se.student_id = u.id AND se.academic_year_id = ? AND se.enrollment_status IN ('enrolled','graduated')
    LEFT JOIN classes c ON c.id = se.class_id
    WHERE u.role = 'student' AND u.deleted_at IS NULL
    ORDER BY c.name, u.name");
$studentsStmt->execute([$currentAcademicYearId]);
$students = $studentsStmt->fetchAll(PDO::FETCH_ASSOC);

// 3. Fetch detailed student record if selected
$student = null;
if ($studentId > 0) {
    $stmt = $db->prepare("SELECT u.id, u.name, u.username, sp.*, ay.name AS academic_year_name,
            s.stage_name, g.grade_name, c.name AS class_name, se.enrollment_status, se.graduation_year,
            (SELECT id FROM student_attachments WHERE user_id = u.id AND label = 'الصورة الشخصية' ORDER BY id DESC LIMIT 1) AS profile_image_id,
            (SELECT file_name FROM student_attachments WHERE user_id = u.id AND label = 'الصورة الشخصية' ORDER BY id DESC LIMIT 1) AS profile_image
        FROM users u
        LEFT JOIN student_profiles sp ON sp.user_id = u.id
        LEFT JOIN student_enrollments se ON se.student_id = u.id AND se.academic_year_id = ?
        LEFT JOIN academic_years ay ON ay.id = se.academic_year_id
        LEFT JOIN stages s ON s.id = se.stage_id
        LEFT JOIN grades g ON g.id = se.grade_id
        LEFT JOIN classes c ON c.id = se.class_id
        WHERE u.id = ? AND u.role = 'student' AND u.deleted_at IS NULL");
    $stmt->execute([$currentAcademicYearId, $studentId]);
    $student = $stmt->fetch(PDO::FETCH_ASSOC);
}

// Age calculation helper on Oct 1st
function calculateAgeOnOct1st($birthDate, $targetYear = null) {
    if (!$birthDate) return ['years' => '..', 'months' => '..', 'days' => '..'];
    if (!$targetYear) $targetYear = date('Y');

    $birth = new DateTime($birthDate);
    $target = new DateTime("$targetYear-10-01");

    if ($birth > $target) {
        return ['years' => 0, 'months' => 0, 'days' => 0];
    }

    $diff = $birth->diff($target);
    return [
        'years' => $diff->y,
        'months' => $diff->m,
        'days' => $diff->d
    ];
}

$ageOct = $student ? calculateAgeOnOct1st($student['birth_date'] ?? null) : ['years' => '..', 'months' => '..', 'days' => '..'];

// Arabic labels
$genderLabels = ['male' => 'ذكر', 'female' => 'أنثى'];

require_once '../includes/admin_header.php';
echo FinanceLegacyAdapter::bridgeNotice(__FILE__);
?>

<!-- Google Fonts: official document font choices -->
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=Amiri:ital,wght@0,400;0,700;1,400;1,700&family=Noto+Naskh+Arabic:wght@400;500;600;700&family=Tajawal:wght@400;500;700;800&display=swap" rel="stylesheet">

<!-- Premium Document Preview Styles -->
<link rel="stylesheet" href="../assets/css/statements.css?v=<?php echo (int) @filemtime(__DIR__ . '/../assets/css/statements.css'); ?>">

<!-- Page Heading matching Enrolled Students layout -->
<div class="admin-page-heading no-print mb-4">
    <h1 class="h2"><i class="fas fa-file-invoice me-2 text-primary"></i>إفادات رسمية</h1>
    <div class="admin-top-actions no-print">
        <button type="button" onclick="exportOfficialDocumentPdf()" class="btn btn-header-premium btn-pdf-soft" title="حفظ المستند بصيغة PDF من نافذة الطباعة" <?php echo !$student ? 'disabled' : ''; ?>>
            <i class="fas fa-file-pdf me-1"></i>تصدير PDF
        </button>
        <!-- Print Button -->
        <button type="button" onclick="printOfficialDocument()" class="btn btn-header-premium btn-print-soft" <?php echo !$student ? 'disabled' : ''; ?>>
            <i class="fas fa-print me-1"></i>طباعة المستند
        </button>
    </div>
</div>

<!-- Tabs matching Enrolled Students with dynamic statements -->
<ul class="nav nav-tabs mb-3 border-bottom no-print" role="tablist">
    <?php foreach ($typeTitles as $tKey => $tTitle): ?>
        <li class="nav-item" role="presentation">
            <a class="nav-link fw-semibold <?php echo $type === $tKey ? 'active' : ''; ?>" href="statements.php?type=<?php echo $tKey; ?><?php echo $studentId ? '&student_id=' . $studentId : ''; ?>">
                <i class="<?php echo $typeIcons[$tKey]; ?> me-2"></i><?php echo $tTitle; ?>
            </a>
        </li>
    <?php endforeach; ?>
</ul>

<!-- Filter bar matching Enrolled Students -->
<form method="GET" id="documentFilterForm" class="admin-filter-bar mb-4 no-print">
    <input type="hidden" name="type" value="<?php echo htmlspecialchars($type); ?>">

    <div class="admin-filter-controls">
        <!-- Stage Dropdown -->
        <div class="dropdown d-inline-block">
            <button class="btn btn-light dropdown-toggle btn-sm filter-dropdown-btn border" type="button" id="stageDropdown" data-bs-toggle="dropdown" data-bs-auto-close="outside" aria-expanded="false" style="background: white; color: #495057; height: 31px; display: inline-flex; align-items: center; justify-content: space-between; min-width: 140px; font-weight: bold;">
                <span>المراحل: <span id="selectedStagesLabel" class="fw-bold">الكل</span></span>
            </button>
            <div class="dropdown-menu p-3 text-end" aria-labelledby="stageDropdown" style="max-height: 250px; overflow-y: auto; min-width: 200px; box-shadow: 0 10px 15px -3px rgba(0, 0, 0, 0.1);">
                <?php foreach ($stagesList as $st): ?>
                    <div class="form-check mb-1">
                        <input class="form-check-input stage-checkbox" type="checkbox" id="stage_<?php echo $st['id']; ?>" value="<?php echo $st['id']; ?>">
                        <label class="form-check-label ms-2" for="stage_<?php echo $st['id']; ?>"><?php echo htmlspecialchars($st['stage_name']); ?></label>
                    </div>
                <?php endforeach; ?>
            </div>
        </div>

        <!-- Grade Dropdown -->
        <div class="dropdown d-inline-block">
            <button class="btn btn-light dropdown-toggle btn-sm filter-dropdown-btn border" type="button" id="gradeDropdown" data-bs-toggle="dropdown" data-bs-auto-close="outside" aria-expanded="false" style="background: white; color: #495057; height: 31px; display: inline-flex; align-items: center; justify-content: space-between; min-width: 140px; font-weight: bold;">
                <span>الصفوف: <span id="selectedGradesLabel" class="fw-bold">الكل</span></span>
            </button>
            <div class="dropdown-menu p-3 text-end" aria-labelledby="gradeDropdown" style="max-height: 250px; overflow-y: auto; min-width: 220px; box-shadow: 0 10px 15px -3px rgba(0, 0, 0, 0.1);">
                <?php foreach ($gradesList as $gr): ?>
                    <div class="form-check mb-1 grade-item" data-stage="<?php echo $gr['stage_id']; ?>">
                        <input class="form-check-input grade-checkbox" type="checkbox" id="grade_<?php echo $gr['id']; ?>" value="<?php echo $gr['id']; ?>">
                        <label class="form-check-label ms-2" for="grade_<?php echo $gr['id']; ?>"><?php echo htmlspecialchars($gr['grade_name']); ?></label>
                    </div>
                <?php endforeach; ?>
            </div>
        </div>

        <!-- Class Dropdown -->
        <div class="dropdown d-inline-block">
            <button class="btn btn-light dropdown-toggle btn-sm filter-dropdown-btn border" type="button" id="classDropdown" data-bs-toggle="dropdown" data-bs-auto-close="outside" aria-expanded="false" style="background: white; color: #495057; height: 31px; display: inline-flex; align-items: center; justify-content: space-between; min-width: 140px; font-weight: bold;">
                <span>الفصول: <span id="selectedClassesLabel" class="fw-bold">الكل</span></span>
            </button>
            <div class="dropdown-menu p-3 text-end" aria-labelledby="classDropdown" style="max-height: 250px; overflow-y: auto; min-width: 220px; box-shadow: 0 10px 15px -3px rgba(0, 0, 0, 0.1);">
                <?php foreach ($classesList as $cl): ?>
                    <div class="form-check mb-1 class-item" data-grade="<?php echo $cl['grade_id']; ?>">
                        <input class="form-check-input class-checkbox" type="checkbox" id="class_<?php echo $cl['id']; ?>" value="<?php echo $cl['id']; ?>">
                        <label class="form-check-label ms-2" for="class_<?php echo $cl['id']; ?>"><?php echo htmlspecialchars($cl['name']); ?></label>
                    </div>
                <?php endforeach; ?>
            </div>
        </div>

        <!-- Student Dropdown (Single Radio select with Search field) -->
        <div class="dropdown d-inline-block">
            <button class="btn btn-light dropdown-toggle btn-sm filter-dropdown-btn border" type="button" id="studentDropdown" data-bs-toggle="dropdown" data-bs-auto-close="outside" aria-expanded="false" style="background: white; color: #495057; height: 31px; display: inline-flex; align-items: center; justify-content: space-between; min-width: 200px; font-weight: bold;">
                <span>الطالب: <span id="selectedStudentLabel" class="fw-bold">اختر الطالب</span></span>
            </button>
            <div class="dropdown-menu p-3 text-end" aria-labelledby="studentDropdown" style="max-height: 300px; overflow-y: auto; min-width: 280px; box-shadow: 0 10px 15px -3px rgba(0, 0, 0, 0.1);">
                <div class="mb-2">
                    <input type="text" id="studentSearchInput" class="form-control form-control-sm" placeholder="ابحث بالاسم أو الكود...">
                </div>
                <div id="studentListContainer">
                    <?php foreach ($students as $row): ?>
                        <div class="form-check mb-1 student-item"
                             data-stage="<?php echo (int)$row['stage_id']; ?>"
                             data-grade="<?php echo (int)$row['grade_id']; ?>"
                             data-class="<?php echo (int)$row['class_id']; ?>"
                             data-code="<?php echo htmlspecialchars($row['student_code'] ?? ''); ?>"
                             data-name-lower="<?php echo htmlspecialchars(mb_strtolower($row['name'])); ?>">
                            <input class="form-check-input student-radio" type="radio" name="student_id" value="<?php echo $row['id']; ?>" id="student_<?php echo $row['id']; ?>" <?php echo $studentId === (int)$row['id'] ? 'checked' : ''; ?>>
                            <label class="form-check-label ms-2" for="student_<?php echo $row['id']; ?>">
                                <?php echo htmlspecialchars($row['name']); ?>
                            </label>
                        </div>
                    <?php endforeach; ?>
                </div>
            </div>
        </div>
    </div>

    <div class="admin-filter-actions">
        <button type="submit" class="btn btn-primary btn-sm text-white" style="background: linear-gradient(135deg, #3b82f6, #1d4ed8) !important; border-color: #1d4ed8 !important; color: #ffffff !important;">
            <i class="fas fa-eye me-1" style="color: #ffffff !important;"></i>عرض المستند
        </button>
        <button type="button" id="resetFiltersBtn" class="btn btn-light btn-sm">
            <i class="fas fa-undo me-1"></i>إعادة تعيين
        </button>
        <button type="button" class="btn btn-light btn-sm" data-bs-toggle="modal" data-bs-target="#printSettingsModal">
            <i class="fas fa-cog me-1"></i>إعدادات الطباعة
        </button>
    </div>
</form>

<!-- Modal for Document Customize Print Settings -->
<div class="modal fade" id="printSettingsModal" tabindex="-1" aria-labelledby="printSettingsModalLabel" aria-hidden="true" style="text-align: right;">
    <div class="modal-dialog modal-lg modal-dialog-centered">
        <div class="modal-content admin-modal admin-modal-premium">
            <div class="modal-header d-flex justify-content-between align-items-center">
                <h5 class="modal-title" id="printSettingsModalLabel"><i class="fas fa-cog me-2"></i>إعدادات وتخصيص مستند الطباعة</h5>
                <button type="button" class="btn-close ms-0" data-bs-dismiss="modal" aria-label="إغلاق"></button>
            </div>
            <div class="modal-body p-4">
                <div class="row g-4">
                    <!-- Column 1: Display Options & Language -->
                    <div class="col-md-6 border-end-md pe-md-4">
                        <h6 class="fw-bold text-primary mb-3"><i class="fas fa-sliders-h me-2"></i>عناصر وخيارات المستند</h6>
                        <div class="form-check form-switch mb-3">
                            <input class="form-check-input" type="checkbox" id="toggleSchoolHeader" checked>
                            <label class="form-check-label fw-bold ms-2" for="toggleSchoolHeader">عرض ترويسة المدرسة الرسمية</label>
                        </div>
                        <div class="form-check form-switch mb-3">
                            <input class="form-check-input" type="checkbox" id="toggleLogo" checked>
                            <label class="form-check-label fw-bold ms-2" for="toggleLogo">عرض شعار المدرسة</label>
                        </div>
                        <div class="form-check form-switch mb-3">
                            <input class="form-check-input" type="checkbox" id="togglePhoto">
                            <label class="form-check-label fw-bold ms-2" for="togglePhoto">عرض الصورة الشخصية للطالب (إن وجدت)</label>
                        </div>
                        <div class="form-check form-switch mb-3">
                            <input class="form-check-input" type="checkbox" id="toggleBorder">
                            <label class="form-check-label fw-bold ms-2" for="toggleBorder">عرض إطار مزخرف للمستند</label>
                        </div>
                        <div class="form-check form-switch mb-3">
                            <input class="form-check-input" type="checkbox" id="toggleSignatures" <?php echo $signaturesDefaultOff ? '' : 'checked'; ?>>
                            <label class="form-check-label fw-bold ms-2" for="toggleSignatures">عرض حقول التوقيع والختم بالأسفل</label>
                        </div>
                        <div class="form-check form-switch mb-3">
                            <input class="form-check-input" type="checkbox" id="toggleDocDate" checked>
                            <label class="form-check-label fw-bold ms-2" for="toggleDocDate">عرض تاريخ التحرير بالمستند</label>
                        </div>
                        <div class="form-check form-switch mb-3" style="<?php echo ($type === 'transfer' || $type === 'withdrawal' || $type === 'second_session' || $type === 'behavior' || $type === 'behavior_pledge') ? 'display:none;' : ''; ?>">
                            <input class="form-check-input" type="checkbox" id="toggleDetailsTable">
                            <label class="form-check-label fw-bold ms-2" for="toggleDetailsTable">عرض جدول البيانات التفصيلي</label>
                        </div>

                        <div class="mt-4">
                            <label for="printLayoutLang" class="form-label fw-bold text-secondary"><i class="fas fa-language me-1"></i>لغة الترويسة الرسمية والفوتر</label>
                            <select id="printLayoutLang" class="form-select border shadow-sm" style="border-radius: 8px; font-size: 0.9rem;">
                                <option value="ar" selected>اللغة العربية</option>
                                <option value="en">اللغة الإنجليزية (English)</option>
                            </select>
                        </div>
                    </div>

                    <!-- Column 2: Signatures & Metadata Inputs -->
                    <div class="col-md-6 ps-md-4">
                        <h6 class="fw-bold text-primary mb-3"><i class="fas fa-file-signature me-2"></i>إعدادات التوقيعات والبيانات</h6>
                        <div class="mb-3">
                            <label for="signatureModeSelect" class="form-label fw-bold text-secondary">نظام عرض التوقيعات</label>
                            <select id="signatureModeSelect" class="form-select border shadow-sm" style="border-radius: 8px; font-size: 0.9rem;">
                                <option value="titles_only" selected>عرض الألقاب فقط (بدون أسماء)</option>
                                <option value="titles_and_names">عرض الألقاب مع الأسماء المعتمدة</option>
                            </select>
                        </div>

                        <label class="form-label fw-bold text-secondary mb-2">تحديد المسئولين المظهرين بالتوقيع:</label>
                        <div class="bg-light p-3 rounded border mb-3">
                            <div class="form-check form-switch mb-2">
                                <input class="form-check-input signature-toggle" type="checkbox" id="toggleStudentAffairs" <?php echo $signaturesDefaultOff ? '' : 'checked'; ?> data-target="sigColStudentAffairs">
                                <label class="form-check-label fw-semibold ms-2" for="toggleStudentAffairs">توقيع <?php echo htmlspecialchars($studentAffairsSigTitle); ?></label>
                            </div>
                            <div class="form-check form-switch mb-2">
                                <input class="form-check-input signature-toggle" type="checkbox" id="toggleSchoolDirector" <?php echo $signaturesDefaultOff ? '' : 'checked'; ?> data-target="sigColSchoolDirector">
                                <label class="form-check-label fw-semibold ms-2" for="toggleSchoolDirector">توقيع <?php echo htmlspecialchars($directorSigTitle); ?></label>
                            </div>
                            <div class="form-check form-switch mb-0">
                                <input class="form-check-input signature-toggle" type="checkbox" id="toggleAdminDirector" data-target="sigColAdminDirector">
                                <label class="form-check-label fw-semibold ms-2" for="toggleAdminDirector">توقيع المدير الإداري</label>
                            </div>
                        </div>

                        <?php if ($type === 'transfer'): ?>
                            <div class="mb-3">
                                <label class="form-label fw-bold text-secondary">المدرسة المطلوب التحويل إليها</label>
                                <input type="text" id="destinationSchoolInput" class="form-control" placeholder="أدخل اسم المدرسة المطلوب التحويل إليها...">
                            </div>
                        <?php endif; ?>

                        <?php if ($type === 'withdrawal'): ?>
                            <div class="row g-2 mb-3">
                                <div class="col-7">
                                    <label class="form-label fw-bold text-secondary">اسم ولي الأمر المقر</label>
                                    <input type="text" id="guardianNameInput" class="form-control" value="<?php echo htmlspecialchars($student['guardian_name'] ?? $student['father_name'] ?? ''); ?>">
                                </div>
                                <div class="col-5">
                                    <label class="form-label fw-bold text-secondary">الصفة</label>
                                    <input type="text" id="guardianRelationInput" class="form-control" value="<?php echo htmlspecialchars($student['guardian_relationship'] ?? 'أب'); ?>">
                                </div>
                            </div>
                        <?php endif; ?>


                        <div class="mb-0">
                            <label class="form-label fw-bold text-secondary">ملاحظات إضافية أسفل البيان</label>
                            <textarea id="additionalNotesInput" class="form-control" rows="2" placeholder="أدخل أي بنود أو ملاحظات إضافية تود ظهورها..."></textarea>
                        </div>
                    </div>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal"><i class="fas fa-times me-1"></i>إغلاق</button>
                <button type="button" class="btn btn-primary" onclick="bootstrap.Modal.getInstance(document.getElementById('printSettingsModal')).hide()"><i class="fas fa-check me-1"></i>تطبيق</button>
            </div>
        </div>
    </div>
</div>

<?php if ($student): ?>
    <!-- Premium Document Preview Container displayed directly on page -->
    <div class="d-flex flex-column align-items-center mb-5 document-workspace-scaler">

        <!-- Main Workspace Container: Side-by-side flex layout (Sidebar Right + Toolbar & Paper Left) -->
        <div class="document-preview-container">

            <!-- Saved Entities Sidebar Panel (Docked on Right) -->
            <div class="saved-entities-sidebar bg-white rounded shadow-sm border p-3 no-print">
                <div class="d-flex align-items-center justify-content-between border-bottom pb-2 mb-3">
                    <h6 class="fw-bold m-0 text-dark" style="font-size: 13.5px;">
                        <i class="fas fa-bookmark me-1 text-warning"></i><?php echo $type === 'transfer' ? 'سجل المدارس المسجلة' : ($type === 'second_session' ? 'سجل المواد المسجلة' : ($type === 'withdrawal' ? 'سجل الشهادات المسجلة' : 'سجل الجهات المسجلة')); ?>
                    </h6>
                    <button type="button" class="btn btn-sm btn-outline-primary py-0 px-2" id="btnAddCurrentEntity" style="font-size: 11px; height: 24px;" title="<?php echo $type === 'transfer' ? 'إضافة مدرسة جديدة للقائمة' : ($type === 'second_session' ? 'إضافة مادة جديدة للقائمة' : ($type === 'withdrawal' ? 'إضافة شهادة جديدة للقائمة' : 'إضافة جهة جديدة للقائمة')); ?>">
                        <i class="fas fa-plus me-1"></i>جديد
                    </button>
                </div>

                <p class="text-muted small mb-2" style="font-size: 11px;"><i class="fas fa-mouse-pointer text-primary me-1"></i><?php echo $type === 'transfer' ? 'اضغط على اسم المدرسة لإدراجها فوراً:' : ($type === 'second_session' ? 'اضغط على اسم المادة لإدراجها فوراً:' : ($type === 'withdrawal' ? 'اضغط على اسم الشهادة لإدراجها فوراً:' : 'اضغط على اسم الجهة لإدراجها فوراً:')); ?></p>

                <!-- Dynamic Entities List Container -->
                <div id="savedEntitiesContainer" class="d-flex flex-column gap-2" style="max-height: 450px; overflow-y: auto;"></div>
            </div>

            <!-- Left Column: Word-Style Toolbar + Official Document Paper -->
            <div class="paper-and-toolbar-column">
                <!-- Word-Style Formatting Toolbar -->
                <div class="doc-editor-toolbar mb-3 p-2 bg-white rounded shadow-sm border d-flex flex-wrap align-items-center justify-content-center gap-2 no-print" role="toolbar" aria-label="أدوات تنسيق المستند" style="width: 100%; border-color: #cbd5e1 !important;">
                    <!-- Direct Editable Note (Separate Line) -->
                    <div class="w-100 text-center pb-2 mb-1 border-bottom d-flex justify-content-center align-items-center">
                        <span class="badge bg-light text-primary border shadow-sm px-3 py-1.5 d-inline-flex align-items-center" style="font-size: 11.5px; font-weight: bold;">
                            <i class="fas fa-edit me-1 text-primary"></i>المستند قابل للتعديل المباشر
                        </span>
                    </div>

                    <div class="fw-bold text-dark me-1 border-end pe-2 d-none d-sm-block" style="font-size: 13px;">
                        <i class="fas fa-file-word text-primary me-1"></i>أدوات التنسيق:
                    </div>

                    <!-- Text Styling: Bold, Italic, Underline, Strikethrough -->
                    <div class="btn-group btn-group-sm" role="group">
                        <button type="button" class="btn btn-outline-secondary" data-doc-command="bold" onclick="execDocCmd('bold')" data-bs-toggle="tooltip" title="عريض (Bold)" aria-pressed="false">
                            <i class="fas fa-bold"></i>
                        </button>
                        <button type="button" class="btn btn-outline-secondary" data-doc-command="italic" onclick="execDocCmd('italic')" data-bs-toggle="tooltip" title="مائل (Italic)" aria-pressed="false">
                            <i class="fas fa-italic"></i>
                        </button>
                        <button type="button" class="btn btn-outline-secondary" data-doc-command="underline" onclick="execDocCmd('underline')" data-bs-toggle="tooltip" title="تحته خط (Underline)" aria-pressed="false">
                            <i class="fas fa-underline"></i>
                        </button>
                        <button type="button" class="btn btn-outline-secondary" data-doc-command="strikethrough" onclick="execDocCmd('strikethrough')" data-bs-toggle="tooltip" title="يتوسطه خط" aria-pressed="false">
                            <i class="fas fa-strikethrough"></i>
                        </button>
                    </div>

                    <!-- Whole Document Font -->
                    <div class="d-flex align-items-center gap-1" data-bs-toggle="tooltip" title="تطبيق الخط على كامل المستند">
                        <i class="fas fa-font text-muted" style="font-size: 12px;"></i>
                        <label for="docFontFamilySelect" class="visually-hidden">خط المستند بالكامل</label>
                        <select id="docFontFamilySelect" class="form-select form-select-sm" style="width: 150px; height: 31px; font-size: 12px;" aria-label="خط المستند بالكامل">
                            <option value="tajawal" style="font-family: 'Tajawal', sans-serif;">تجوال — عصري</option>
                            <option value="amiri" style="font-family: 'Amiri', serif;">أميري — كلاسيكي</option>
                            <option value="noto-naskh" style="font-family: 'Noto Naskh Arabic', serif;">نوتو نسخ — طباعي</option>
                        </select>
                    </div>

                    <!-- Font Size Selector & Increase / Decrease Buttons -->
                    <div class="d-flex align-items-center gap-1">
                        <button type="button" class="btn btn-outline-secondary btn-sm" onclick="changeFontSize(-1)" data-bs-toggle="tooltip" title="تصغير الخط">
                            <i class="fas fa-font" style="font-size: 11px;"></i>-
                        </button>
                        <select id="docFontSizeSelect" class="form-select form-select-sm" style="width: 80px; height: 31px; font-size: 12px;" onchange="applyCustomFontSize(this.value)" data-bs-toggle="tooltip" title="حجم الخط">
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
                        <button type="button" class="btn btn-outline-secondary btn-sm" onclick="changeFontSize(1)" data-bs-toggle="tooltip" title="تكبير الخط">
                            <i class="fas fa-font" style="font-size: 11px;"></i>+
                        </button>
                    </div>

                    <!-- Text Color -->
                    <div class="d-flex align-items-center gap-1 bg-light px-2 py-1 rounded border" style="height: 31px;" data-bs-toggle="tooltip" title="لون الخط">
                        <i class="fas fa-palette text-muted" style="font-size: 12px;"></i>
                        <input type="color" id="docTextColorInput" class="form-control form-control-color border-0 p-0" value="#000000" style="width: 22px; height: 22px; cursor: pointer;" oninput="execDocCmd('foreColor', this.value)" aria-label="لون الخط">
                    </div>

                    <!-- Highlight Color -->
                    <div class="d-flex align-items-center gap-1 bg-light px-2 py-1 rounded border" style="height: 31px;" data-bs-toggle="tooltip" title="لون التظليل">
                        <i class="fas fa-highlighter text-muted" style="font-size: 12px;"></i>
                        <input type="color" id="docHighlightColorInput" class="form-control form-control-color border-0 p-0" value="#ffff00" style="width: 22px; height: 22px; cursor: pointer;" oninput="execDocCmd('hiliteColor', this.value)" aria-label="لون التظليل">
                    </div>

                    <!-- Text Alignment -->
                    <div class="btn-group btn-group-sm" role="group">
                        <button type="button" class="btn btn-outline-secondary" data-doc-command="justifyRight" onclick="execDocCmd('justifyRight')" data-bs-toggle="tooltip" title="محاذاة لليمين" aria-pressed="false">
                            <i class="fas fa-align-right"></i>
                        </button>
                        <button type="button" class="btn btn-outline-secondary" data-doc-command="justifyCenter" onclick="execDocCmd('justifyCenter')" data-bs-toggle="tooltip" title="محاذاة للوسط" aria-pressed="false">
                            <i class="fas fa-align-center"></i>
                        </button>
                        <button type="button" class="btn btn-outline-secondary" data-doc-command="justifyLeft" onclick="execDocCmd('justifyLeft')" data-bs-toggle="tooltip" title="محاذاة لليسار" aria-pressed="false">
                            <i class="fas fa-align-left"></i>
                        </button>
                        <button type="button" class="btn btn-outline-secondary" data-doc-command="justifyFull" onclick="execDocCmd('justifyFull')" data-bs-toggle="tooltip" title="محاذاة كاملة" aria-pressed="false">
                            <i class="fas fa-align-justify"></i>
                        </button>
                    </div>

                    <!-- Lists, Indentation & Line Spacing -->
                    <div class="btn-group btn-group-sm" role="group">
                        <button type="button" class="btn btn-outline-secondary" data-doc-command="insertUnorderedList" onclick="execDocCmd('insertUnorderedList')" data-bs-toggle="tooltip" title="قائمة نقطية (Ctrl+Shift+8)" aria-pressed="false">
                            <i class="fas fa-list-ul"></i>
                        </button>
                        <button type="button" class="btn btn-outline-secondary" data-doc-command="insertOrderedList" onclick="execDocCmd('insertOrderedList')" data-bs-toggle="tooltip" title="قائمة رقمية (Ctrl+Shift+7)" aria-pressed="false">
                            <i class="fas fa-list-ol"></i>
                        </button>
                        <button type="button" class="btn btn-outline-secondary" onclick="execDocCmd('outdent')" data-bs-toggle="tooltip" title="تقليل المسافة البادئة (Shift+Tab)">
                            <i class="fas fa-outdent"></i>
                        </button>
                        <button type="button" class="btn btn-outline-secondary" onclick="execDocCmd('indent')" data-bs-toggle="tooltip" title="زيادة المسافة البادئة (Tab)">
                            <i class="fas fa-indent"></i>
                        </button>
                    </div>

                    <select id="docLineHeightSelect" class="form-select form-select-sm" style="width: 96px; height: 31px; font-size: 12px;" onchange="applyDocLineHeight(this.value)" data-bs-toggle="tooltip" title="تباعد الأسطر">
                        <option value="">تباعد</option>
                        <option value="1">1.0</option>
                        <option value="1.15">1.15</option>
                        <option value="1.5">1.5</option>
                        <option value="2">2.0</option>
                        <option value="2.6">2.6</option>
                    </select>

                    <!-- Undo, Redo, Remove Formatting -->
                    <div class="btn-group btn-group-sm" role="group">
                        <button type="button" id="docUndoButton" class="btn btn-outline-secondary" onclick="execDocCmd('undo')" data-bs-toggle="tooltip" title="تراجع" disabled>
                            <i class="fas fa-undo"></i>
                        </button>
                        <button type="button" id="docRedoButton" class="btn btn-outline-secondary" onclick="execDocCmd('redo')" data-bs-toggle="tooltip" title="إعادة" disabled>
                            <i class="fas fa-redo"></i>
                        </button>
                        <button type="button" class="btn btn-outline-danger" onclick="execDocCmd('removeFormat')" data-bs-toggle="tooltip" title="إزالة التنسيق">
                            <i class="fas fa-eraser"></i>
                        </button>
                    </div>
                </div>

                <!-- Document Paper Container -->
                <div id="officialDocPaper" class="official-doc-paper shadow show-header show-logo <?php echo $signaturesDefaultOff ? '' : 'show-signatures'; ?> show-doc-date" contenteditable="true" role="textbox" aria-multiline="true" aria-label="محرر نص المستند الرسمي" spellcheck="true" style="border-radius: 8px; background: #ffffff;">

            <!-- Standard School Official Header -->
            <div class="doc-header-box border-bottom pb-3 mb-4" style="display: grid; grid-template-columns: 1fr auto 1fr; align-items: start;">
                <!-- Right Side: Ministry & School Info -->
                <div style="grid-column: 1; justify-self: start; text-align: center; font-size: 13px; line-height: 1.6; font-weight: bold; white-space: nowrap;" class="d-flex flex-column align-items-center">
                    <div data-ar="وزارة التربية والتعليم والتعليم الفني" data-en="Ministry of Education and Technical Education">وزارة التربية والتعليم والتعليم الفني</div>
                    <div data-ar="<?php echo htmlspecialchars($directorate); ?>" data-en="<?php echo htmlspecialchars($directorateEn); ?>"><?php echo htmlspecialchars($directorate); ?></div>
                    <div data-ar="<?php echo htmlspecialchars($administration); ?>" data-en="<?php echo htmlspecialchars($administrationEn); ?>"><?php echo htmlspecialchars($administration); ?></div>
                    <div data-ar="<?php echo htmlspecialchars($schoolName); ?>" data-en="<?php echo htmlspecialchars($schoolNameEn); ?>"><?php echo htmlspecialchars($schoolName); ?></div>
                </div>

                <!-- Center: Logo (100% Dead Center) -->
                <div class="doc-logo-box text-center" contenteditable="false" style="grid-column: 2; justify-self: center;">
                    <?php if (!empty($schoolLogo)): ?>
                        <img src="<?php echo htmlspecialchars($schoolLogo); ?>" alt="شعار المدرسة" style="max-height: 105px; max-width: 105px; object-fit: contain;">
                    <?php else: ?>
                        <svg width="85" height="85" viewBox="0 0 100 100" fill="none" xmlns="http://www.w3.org/2000/svg">
                            <circle cx="50" cy="50" r="45" stroke="#1e3a8a" stroke-width="4" fill="#f8fafc"/>
                            <path d="M50 20L25 40H75L50 20Z" fill="#1e3a8a"/>
                            <rect x="30" y="40" width="40" height="35" fill="#1e3a8a"/>
                            <rect x="42" y="55" width="16" height="20" fill="#f8fafc"/>
                        </svg>
                    <?php endif; ?>
                </div>

                <!-- Left Side: Ref No -->
                <div style="grid-column: 3; justify-self: end; text-align: center; font-size: 13px; line-height: 1.6; font-weight: bold; white-space: nowrap;" class="d-flex flex-column align-items-center">
                    <div><span data-ar="الرقم الصادر: " data-en="Ref. No.: ">الرقم الصادر: </span><span style="color: #999999; font-weight: normal; letter-spacing: 2px;">...............</span></div>
                </div>
            </div>

            <!-- TABS & TEMPLATES RENDERING -->
            <?php
            $studentPhotoUrl = !empty($student['profile_image_id'])
                ? ProfileAttachmentStorage::adminDownloadUrl('student', (int) $student['profile_image_id'])
                : '';

            $gender = strtolower(trim($student['gender'] ?? ''));
            $isFemale = ($gender === 'female' || $gender === 'أنثى' || $gender === 'f');
            $sTerm = $isFemale ? 'الطالبة' : 'الطالب';
            $eTerm = $isFemale ? 'مقيدة' : 'مقيد';
            $alETerm = $isFemale ? 'المقيدة' : 'المقيد';
            $toTerm = $isFemale ? 'لها' : 'له';
            $hisTerm = $isFemale ? 'طلبها' : 'طلبه';
            $sonTerm = $isFemale ? 'نجلتكم' : 'نجلكم';
            $performsTerm = $isFemale ? 'تؤدي' : 'يؤدي';
            $sName = htmlspecialchars($student['name'] ?? '');

            $rawGradeName = $student['grade_name'] ?? '';
            $cleanGradeName = preg_replace('/^(الصف|صف)\s+/u', '', trim($rawGradeName));
            if (empty($cleanGradeName)) {
                $cleanGradeName = $rawGradeName ?: '-';
            }
            $gName = htmlspecialchars($cleanGradeName);
            $ayName = htmlspecialchars($student['academic_year_name'] ?? ($printSettings['academic_year'] ?? date('Y')));
            $tDate = date('Y/m/d');
            $editorBlankLine = '<div class="doc-text-line doc-editor-blank-line"><br></div>';

            $cityArea = trim($student['city_area'] ?? '');
            $addressCurrent = trim($student['address_current'] ?? '');
            $rawAddress = trim($student['address'] ?? '');

            $addressParts = [];
            if (!empty($cityArea)) {
                $addressParts[] = $cityArea;
            }
            if (!empty($addressCurrent)) {
                $addressParts[] = $addressCurrent;
            } elseif (!empty($rawAddress)) {
                $addressParts[] = $rawAddress;
            }

            $studentAddressFormatted = !empty($addressParts) ? implode(' - ', $addressParts) : '...........................';
            ?>
            <?php if ($type === 'enrollment' || $type === 'grades' || $type === 'success'): ?>

                <!-- Header title -->
                <div class="mb-3 mt-4 pt-2">
                    <h3 class="doc-document-title fw-bold d-block w-100" data-ar="&lt;u&gt;<?php echo htmlspecialchars($documentTitleDisplay); ?>&lt;/u&gt;" data-en="&lt;u&gt;<?php echo htmlspecialchars($documentTitleEn); ?>&lt;/u&gt;" style="text-align: center; padding: 0 4px 4px 4px; font-weight: 800; font-size: 26px;">
                        <u><?php echo htmlspecialchars($documentTitleDisplay); ?></u>
                    </h3>
                </div>

                <!-- Student Photo and Details Block -->
                <?php /* Details block: shown/hidden via CSS toggle (show-details-table class) */ ?>
                    <div class="row align-items-start mb-0">
                        <!-- Detailed Table Option (CSS class controlled) -->
                        <div class="col-12 doc-details-table">
                            <table class="table table-bordered border-dark text-center align-middle mb-0" style="font-size: 14px; border: 1.5px solid #000;">
                                <tbody>
                                    <tr>
                                        <td class="bg-light fw-bold" style="width: 20%;" data-ar="كود الطالب" data-en="Student Code">كود الطالب</td>
                                        <td class="fw-bold" style="width: 30%;"><?php echo htmlspecialchars($student['ministry_code'] ?? '-'); ?></td>
                                        <td class="bg-light fw-bold" style="width: 20%;" data-ar="الرقم القومي" data-en="National ID">الرقم القومي</td>
                                        <td class="fw-bold" style="width: 30%;"><?php echo htmlspecialchars($student['national_id'] ?? '-'); ?></td>
                                    </tr>
                                    <tr>
                                        <td class="bg-light fw-bold" style="width: 20%;" data-ar="تاريخ الميلاد" data-en="Date of Birth">تاريخ الميلاد</td>
                                        <td><?php echo htmlspecialchars($student['birth_date'] ?? '-'); ?></td>
                                        <td class="bg-light fw-bold" style="width: 20%;" data-ar="الجنسية / النوع" data-en="Nationality / Gender">الجنسية / النوع</td>
                                        <td><?php echo htmlspecialchars(($student['nationality'] ?? '-') . ' / ' . ($genderLabels[$student['gender'] ?? ''] ?? '-')); ?></td>
                                    </tr>
                                </tbody>
                            </table>
                        </div>

                        <!-- Inline Standard Details Box -->
                        <div class="col doc-details-inline fs-5" style="line-height: 1.8;">
                            <div class="mb-2"><strong data-ar="اسم الطالب:" data-en="Student Name:">اسم الطالب:</strong> <span data-ar="<?php echo htmlspecialchars($student['name']); ?>" data-en="<?php echo htmlspecialchars($student['name_en'] ?? $student['name']); ?>"><?php echo htmlspecialchars($student['name']); ?></span></div>
                            <?php if (!empty($student['student_code'])): ?>
                                <div class="mb-2"><strong data-ar="كود الطالب:" data-en="Student Code:">كود الطالب:</strong> <span><?php echo htmlspecialchars($student['student_code']); ?></span></div>
                            <?php endif; ?>
                            <div class="mb-2"><strong data-ar="الصف الدراسي:" data-en="Grade:">الصف الدراسي:</strong> <span data-ar="<?php echo htmlspecialchars($student['grade_name'] ?? '-'); ?>" data-en="<?php echo htmlspecialchars(translate_text_to_en($student['grade_name'] ?? '-')); ?>"><?php echo htmlspecialchars($student['grade_name'] ?? '-'); ?></span></div>
                            <div class="mb-2"><strong data-ar="الفصل:" data-en="Class:">الفصل:</strong> <span data-ar="<?php echo htmlspecialchars($student['class_name'] ?? '-'); ?>" data-en="<?php echo htmlspecialchars(translate_text_to_en($student['class_name'] ?? '-')); ?>"><?php echo htmlspecialchars($student['class_name'] ?? '-'); ?></span></div>
                            <div class="mb-2"><strong data-ar="المرحلة:" data-en="Stage:">المرحلة:</strong> <span data-ar="<?php echo htmlspecialchars($student['stage_name'] ?? '-'); ?>" data-en="<?php echo htmlspecialchars(translate_text_to_en($student['stage_name'] ?? '-')); ?>"><?php echo htmlspecialchars($student['stage_name'] ?? '-'); ?></span></div>
                        </div>

                        <!-- Student Photo Column -->
                        <div class="col-auto text-start doc-student-photo ps-3 ms-auto" contenteditable="false">
                            <?php if ($studentPhotoUrl !== ''): ?>
                                <img src="<?php echo htmlspecialchars($studentPhotoUrl); ?>" alt="صورة الطالب" style="width: 105px; height: 130px; object-fit: cover; border: 1.5px solid #000; padding: 2px; background: #fff;">
                            <?php else: ?>
                                <div style="width: 105px; height: 130px; border: 1.5px dashed #000; display: inline-flex; flex-direction: column; align-items: center; justify-content: center; background: #fafafa; color: #555;">
                                    <i class="fas fa-user-tie fa-2x mb-1"></i>
                                    <span style="font-size: 9px; font-weight: bold;">صورة الطالب</span>
                                </div>
                            <?php endif; ?>
                        </div>
                    </div>
                <?php /* End details block */ ?>

                <!-- Document Body Content Box -->
                <?php
                // Photo HTML block anchored higher up at top-LEFT corner aligning with document title

                // Photo HTML block anchored higher up at top-LEFT corner aligning with document title
                $photoHtml = '<div class="doc-student-photo" contenteditable="false" style="position: absolute; top: -55px; left: 0; right: auto; z-index: 10;">' .
                    (!empty($studentPhotoUrl) ?
                        '<img src="' . htmlspecialchars($studentPhotoUrl) . '" alt="صورة الطالب" style="width: 115px; height: 145px; object-fit: cover; border: 1.5px solid #000; padding: 2px; background: #fff;" onerror="this.onerror=null; this.outerHTML=\'<div style=\\\'\width: 115px; height: 145px; border: 1.5px dashed #000; display: inline-flex; flex-direction: column; align-items: center; justify-content: center; background: #fafafa; color: #555;\\\'>\\\<i class=\\\'\fas fa-user-tie fa-2x mb-1\\\'>\\\</i>\\\<span style=\\\'\font-size: 9px; font-weight: bold;\\\'>صورة الطالب\\\</span>\\\</div>\\\';">' :
                        '<div style="width: 115px; height: 145px; border: 1.5px dashed #000; display: inline-flex; flex-direction: column; align-items: center; justify-content: center; background: #fafafa; color: #555;"><i class="fas fa-user-tie fa-2x mb-1"></i><span style="font-size: 9px; font-weight: bold;">صورة الطالب</span></div>'
                    ) . '</div>';

                $arEnrollmentBody = $photoHtml .
                    $editorBlankLine .
                    '<div class="doc-text-line mb-3">تشهد مدارس الدلتا الحديثة للغات بأن ' . $sTerm . ' / <strong>' . $sName . '</strong></div>' .
                    '<div class="doc-text-line mb-3">' . $eTerm . ' بالصف / <strong>' . $gName . '</strong> - للعام الدراسي <strong>' . $ayName . '</strong></div>' .
                    '<div class="doc-text-line mb-3">وقد أعطيت ' . $toTerm . ' هذه الشهادة بناء على ' . $hisTerm . ' وذلك لتقديمها إلى /&nbsp;<strong><span class="entity-name-display" style="cursor: pointer; border-bottom: 1px dashed #999; padding: 0 2px;" title="اضغط لكتابة اسم الجهة">[ اضغط هنا لكتابة اسم الجهة ]</span></strong></div>' .
                    '<div class="doc-text-line mb-3">و هذه شهادة منا بذلك دون أدني مسئولية علي المدرسة.</div>' .
                    '<div class="w-100 text-center mt-5 fw-bold" style="text-align: center !important;">و تفضلوا بقبول فائق الاحترام ،،،</div>' .
                    '<div class="doc-date-box text-start mt-4">تحريرا في: <strong>' . $tDate . '</strong></div>';

                $stNameEn = htmlspecialchars($student['name_en'] ?? $student['name']);
                $stGradeEn = htmlspecialchars(translate_text_to_en($student['grade_name'] ?? '-'));
                $pronounEnTo = $isFemale ? 'her' : 'him';
                $pronounEnHis = $isFemale ? 'her' : 'his';

                $enEnrollmentBody = $photoHtml .
                    $editorBlankLine .
                    '<div class="doc-text-line mb-3">EduCore certifies that student <strong>' . $stNameEn . '</strong></div>' .
                    '<div class="doc-text-line mb-3">is enrolled in Grade <strong>' . $stGradeEn . '</strong> for the academic year <strong>' . $ayName . '</strong>.</div>' .
                    '<div class="doc-text-line mb-3">This certificate has been issued to ' . $pronounEnTo . ' upon ' . $pronounEnHis . ' request to be presented to /&nbsp;<strong><span class="entity-name-display" style="cursor: pointer; border-bottom: 1px dashed #999; padding: 0 2px;" title="Click to type entity name">[ Click here to type entity name ]</span></strong></div>' .
                    '<div class="doc-text-line mb-3">This certificate is issued upon request without any liability on the school.</div>' .
                    '<div class="w-100 text-center mt-5 fw-bold" style="text-align: center !important;">Yours sincerely,,,</div>' .
                    '<div class="doc-date-box text-start mt-4">Issued on: <strong>' . $tDate . '</strong></div>';
                $arGradesBody = $editorBlankLine . 'هذا بيان درجات مبدئي للطالب/ة <strong>' . htmlspecialchars($student['name']) . '</strong>. يمكن استكمال تفاصيل الدرجات من نظام التقارير الشهرية حسب الصف والمواد.';
                $enGradesBody = $editorBlankLine . 'This is a provisional statement of marks for student <strong>' . $stNameEn . '</strong>. Complete details can be obtained from the monthly report system.';
                $arSuccessBody = $editorBlankLine . 'تشهد إدارة المدرسة بأن الطالب/ة <strong>' . htmlspecialchars($student['name']) . '</strong> قد اجتاز/ت العام الدراسي بنجاح طبقاً لسجلات المدرسة.';
                $enSuccessBody = $editorBlankLine . 'This is to certify that student <strong>' . $stNameEn . '</strong> has successfully passed the academic year according to school records.';
                ?>
                <div id="docBodyContentBox" class="doc-body-content p-0 mb-4 fs-5 bg-transparent border-0 position-relative" data-editor-boundary="document-body" style="line-height: 1.8;"
                    data-ar="<?php
                        if ($type === 'enrollment') echo htmlspecialchars($arEnrollmentBody, ENT_QUOTES, 'UTF-8');
                        elseif ($type === 'grades') echo htmlspecialchars($arGradesBody, ENT_QUOTES, 'UTF-8');
                        elseif ($type === 'success') echo htmlspecialchars($arSuccessBody, ENT_QUOTES, 'UTF-8');
                    ?>"
                    data-en="<?php
                        if ($type === 'enrollment') echo htmlspecialchars($enEnrollmentBody, ENT_QUOTES, 'UTF-8');
                        elseif ($type === 'grades') echo htmlspecialchars($enGradesBody, ENT_QUOTES, 'UTF-8');
                        elseif ($type === 'success') echo htmlspecialchars($enSuccessBody, ENT_QUOTES, 'UTF-8');
                    ?>">
                    <?php
                    if ($type === 'enrollment') {
                        echo $arEnrollmentBody;
                    } elseif ($type === 'grades') {
                        echo $arGradesBody;
                    } elseif ($type === 'success') {
                        echo $arSuccessBody;
                    }
                    ?>
                </div>

            <?php elseif ($type === 'transfer'): ?>
                <!-- ================== طلب تحويل ================== -->
                <div class="mb-4">
                    <h3 class="doc-document-title fw-bold px-3 py-1 d-block w-100" data-ar="&lt;u&gt;طـلـب تـحـويـل&lt;/u&gt;" data-en="&lt;u&gt;SCHOOL TRANSFER REQUEST&lt;/u&gt;" style="text-align: center; letter-spacing: 1px;"><u>طـلـب تـحـويـل</u></h3>
                </div>

                <div class="row align-items-start mb-4 fs-5" style="line-height: 2.1;">
                    <div class="col">
                        <div>اسم الطالب : <strong><?php echo htmlspecialchars($student['name'] ?? '-'); ?></strong></div>
                        <div>السنة الدراسية: <?php echo $eTerm; ?> بالصف <strong><?php echo htmlspecialchars($cleanGradeName); ?></strong></div>
                        <div>تاريخ الميلاد : <strong><?php echo htmlspecialchars($student['birth_date'] ?? '-'); ?></strong></div>

                        <div class="d-flex align-items-center justify-content-between my-2" style="max-width: 580px;">
                            <div>السن في :- <strong>1 / 10 / <?php echo date('Y'); ?></strong></div>
                            <div class="d-flex flex-column align-items-center">
                                <div class="d-flex gap-4 text-center border-bottom pb-1 mb-1 fs-5 fw-bold">
                                    <span style="width: 45px; display: inline-block;">يوم</span>
                                    <span style="width: 45px; display: inline-block;">شهر</span>
                                    <span style="width: 45px; display: inline-block;">سنه</span>
                                </div>
                                <div class="d-flex gap-4 text-center fs-5 fw-bold">
                                    <span style="width: 45px; display: inline-block;"><?php echo sprintf('%02d', (int)($ageOct['days'] ?? 0)); ?></span>
                                    <span style="width: 45px; display: inline-block;"><?php echo sprintf('%02d', (int)($ageOct['months'] ?? 0)); ?></span>
                                    <span style="width: 45px; display: inline-block;"><?php echo sprintf('%02d', (int)($ageOct['years'] ?? 0)); ?></span>
                                </div>
                            </div>
                        </div>

                        <div>عنوان السكن : <strong><?php echo htmlspecialchars($studentAddressFormatted); ?></strong></div>
                        <div>المدرسة المطلوب التحويل إليها : <strong><span class="entity-name-display" id="lbl_destination_school" style="cursor: pointer; border-bottom: 1px dashed #999; padding: 0 2px;" title="اضغط لكتابة اسم المدرسة">[ اضغط هنا لكتابة اسم المدرسة ]</span></strong></div>
                    </div>

                    <!-- Student Photo Column -->
                    <div class="col-auto text-start doc-student-photo ps-3 ms-auto" contenteditable="false">
                        <?php if (!empty($studentPhotoUrl)): ?>
                            <img src="<?php echo htmlspecialchars($studentPhotoUrl); ?>" alt="صورة الطالب" style="width: 105px; height: 130px; object-fit: cover; border: 1.5px solid #000; padding: 2px; background: #fff;" onerror="this.onerror=null; this.outerHTML='<div style=\'width: 105px; height: 130px; border: 1.5px dashed #000; display: inline-flex; flex-direction: column; align-items: center; justify-content: center; background: #fafafa; color: #555;\'><i class=\'fas fa-user-tie fa-2x mb-1\'></i><span style=\'font-size: 9px; font-weight: bold;\'>صورة الطالب</span></div>';">
                        <?php else: ?>
                            <div style="width: 105px; height: 130px; border: 1.5px dashed #000; display: inline-flex; flex-direction: column; align-items: center; justify-content: center; background: #fafafa; color: #555;">
                                <i class="fas fa-user-tie fa-2x mb-1"></i>
                                <span style="font-size: 9px; font-weight: bold;">صورة الطالب</span>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>

                <div class="fs-5 mb-4" style="line-height: 2;">
                    <p class="mb-4 text-start">برجاء التكرم بإفادتنا بإمكانية تحويل الطالب المذكور من عدمه.</p>
                    <p class="text-center fw-bold mt-4 mb-4">وتفضلوا بقبول فائق الإحترام ،،،</p>
                </div>

                <div class="doc-date-box text-start fs-5 fw-bold mb-4">
                    <div>تحريراً في: <span class="doc-date-display fw-bold"><?php echo date('d / m / Y'); ?></span></div>
                </div>

                <!-- Signatures & Separator Line Container -->
                <div class="mt-4 pt-2 mb-3 border-bottom pb-4">
                    <div class="doc-signatures-box row text-center justify-content-between fs-5 fw-bold" style="line-height: 1.8;">
                        <div class="col-4" id="sigColStudentAffairs">
                            <div class="doc-sig-title" data-ar="<?php echo htmlspecialchars($studentAffairsSigTitle); ?>" data-en="<?php echo htmlspecialchars($studentAffairsSigTitleEn); ?>"><?php echo htmlspecialchars($studentAffairsSigTitle); ?></div>
                            <div class="small text-muted fw-normal mt-1 signature-name" style="display: none;" data-ar="<?php echo htmlspecialchars($studentAffairsOfficer); ?>" data-en="<?php echo htmlspecialchars($studentAffairsOfficerEn); ?>"><?php echo htmlspecialchars($studentAffairsOfficer); ?></div>
                        </div>
                        <div class="col-4" id="sigColSchoolDirector">
                            <div class="doc-sig-title" data-ar="<?php echo htmlspecialchars($directorSigTitle); ?>" data-en="<?php echo htmlspecialchars($directorSigTitleEn); ?>"><?php echo htmlspecialchars($directorSigTitle); ?></div>
                            <div class="small text-muted fw-normal mt-1 signature-name" style="display: none;" data-ar="<?php echo htmlspecialchars($schoolDirector); ?>" data-en="<?php echo htmlspecialchars($schoolDirectorEn); ?>"><?php echo htmlspecialchars($schoolDirector); ?></div>
                        </div>
                        <div class="col-4" id="sigColAdminDirector" style="display: none;">
                            <div class="doc-sig-title" data-ar="المدير الإداري" data-en="Administrative Director">المدير الإداري</div>
                            <div class="small text-muted fw-normal mt-1 signature-name" style="display: none;" data-ar="<?php echo htmlspecialchars($adminDirector); ?>" data-en="<?php echo htmlspecialchars($adminDirectorEn); ?>"><?php echo htmlspecialchars($adminDirector); ?></div>
                        </div>
                    </div>
                    <!-- Blank line space before the dividing line -->
                    <div class="my-3"><br></div>
                </div>

                <!-- Blank line space after signatures line -->
                <div class="my-3"><br></div>

                <!-- Bottom Destination School Accept block -->
                <div class="p-4 rounded border mt-4 bg-light fs-5" style="border: 2.5px double #000000 !important;">
                    <div class="text-center mb-3">السيد الأستاذ مدير مدارس : <strong><?php echo htmlspecialchars($cleanSchoolName); ?></strong></div>
                    <p class="text-center mb-4">يمكن قبول تحويل الطالب</p>
                    <div class="row text-center fs-5">
                        <div class="col">التاريخ / ....................</div>
                        <div class="col">التوقيع / ....................</div>
                        <div class="col">الختم / ....................</div>
                    </div>
                </div>

            <?php elseif ($type === 'second_session'): ?>
                <!-- ================== إخطار دور ثاني ================== -->
                <div class="mb-4">
                    <h3 class="doc-document-title fw-bold px-3 py-1 d-block w-100" data-ar="&lt;u&gt;إخـطـار ثـان&lt;/u&gt;" data-en="&lt;u&gt;SECOND SESSION EXAMINATION NOTICE&lt;/u&gt;" style="text-align: center; letter-spacing: 1px;"><u>إخـطـار ثـان</u></h3>
                </div>

                <div class="fs-5 px-3 mb-4" style="line-height: 2.2;">
                    <div class="mb-3">السيد ولي امر <?php echo $sTerm; ?>/ <strong><?php echo htmlspecialchars($student['name'] ?? '-'); ?></strong></div>
                    <div class="mb-4"><?php echo $alETerm; ?> بالصف/ <strong><?php echo htmlspecialchars($cleanGradeName); ?></strong></div>

                    <div class="text-center fw-bold mb-4">تحية طيبة وبعد :-</div>

                    <p class="mb-4 text-start">
                        نحيط علم سيادتكم علماً بأن <?php echo $sonTerm; ?> سوف <?php echo $performsTerm; ?> امتحان دور ثان لمادة/ <strong><span class="entity-name-display" id="lbl_subject_name" title="اضغط لكتابة اسم المادة/المواد">[ اضغط هنا لكتابة اسم المادة/المواد ]</span></strong> مرفق لسيادتكم صورة من جدول الامتحانات.
                    </p>

                    <p class="mb-4 text-start" style="text-decoration: underline;">
                        و هذه شهادة منا بذلك دون أدني مسئولية علي المدرسة.
                    </p>

                    <p class="text-center fw-bold mt-4 mb-4">وتفضلوا بقبول فائق الاحترام ،،،</p>
                </div>

                <div class="doc-date-box text-start fs-5 fw-bold mb-4">
                    <div>تحريراً في: <span class="doc-date-display fw-bold"><?php echo date('d / m / Y'); ?></span></div>
                </div>

            <?php elseif ($type === 'withdrawal'): ?>
                <!-- ================== إقرار سحب ملف ================== -->
                <div class="mb-4">
                    <h3 class="doc-document-title fw-bold px-3 py-1 d-block w-100" data-ar="&lt;u&gt;نموذج إقرار&lt;/u&gt;" data-en="&lt;u&gt;FILE WITHDRAWAL ACKNOWLEDGMENT&lt;/u&gt;" style="text-align: center; letter-spacing: 1px;"><u>نموذج إقرار</u></h3>
                </div>

                <div class="fs-5 px-3 mb-5" style="line-height: 2.3;">
                    <p class="mb-4">
                        <?php
                        $gNameVal = trim($student['guardian_name'] ?? $student['father_name'] ?? '');
                        $gRelVal = trim($student['guardian_relationship'] ?? '');
                        ?>
                        أقر أنا/ <strong><span class="entity-name-display <?php echo !empty($gNameVal) ? 'is-filled' : ''; ?>" id="lbl_guardian_name" data-placeholder="[ اضغط هنا لكتابة اسم ولي الأمر ]" title="اضغط لكتابة اسم ولي الأمر"><?php echo !empty($gNameVal) ? htmlspecialchars($gNameVal) : '[ اضغط هنا لكتابة اسم ولي الأمر ]'; ?></span></strong>
                        بصفتي/ <strong><span class="entity-name-display <?php echo !empty($gRelVal) ? 'is-filled' : ''; ?>" id="lbl_guardian_relation" data-placeholder="[ اضغط هنا لكتابة الصفة ]" title="اضغط لكتابة الصفة"><?php echo !empty($gRelVal) ? htmlspecialchars($gRelVal) : '[ اضغط هنا لكتابة الصفة ]'; ?></span></strong>
                    </p>

                    <p class="mb-3">
                        بأنني استلمت ملف الطالب/ <strong><?php echo htmlspecialchars($student['name'] ?? '-'); ?></strong>
                    </p>

                    <div class="fw-bold mb-3 ps-3">ويحتوي علي كلا من :</div>
                    <ol class="fw-bold mb-4 ps-4" style="line-height: 2;">
                        <li>أصل شهادة الميلاد الكمبيوتر.</li>
                        <li>الصور الشخصية للطالب.</li>
                        <li>صورة بطاقة الأب والأم.</li>
                        <li>شهادة <span class="entity-name-display" id="lbl_cert_stage" data-placeholder="[ اضغط هنا لكتابة مرحلة الشهادة ]" title="اضغط لكتابة مرحلة الشهادة">[ اضغط هنا لكتابة مرحلة الشهادة ]</span>.</li>
                    </ol>

                    <p class="mb-4">
                        <?php echo $alETerm; ?> بالصف/ <strong><?php echo htmlspecialchars($cleanGradeName); ?></strong> للعام الدراسي/ <strong><?php echo htmlspecialchars($student['academic_year_name'] ?? '-'); ?></strong>
                    </p>

                    <p class="mb-4 fw-bold">وأقر بمسئوليتي الكامله عن الملف.</p>

                    <p class="text-center fw-bold mt-4 mb-4">وهذا إقرار مني بذلك ،،،</p>
                </div>

                <div class="d-flex flex-column align-items-start ms-auto text-start fs-5 fw-bold mt-4 mb-3" style="width: 250px;">
                    <div class="mb-2">التوقيع/</div>
                    <div class="doc-date-box">التاريخ/ <span class="doc-date-display fw-bold"><?php echo date('d / m / Y'); ?></span></div>
                </div>

            <?php elseif ($type === 'behavior'): ?>
                <!-- ================== إفادة سلوك ================== -->
                <div class="mb-4">
                    <h3 class="doc-document-title fw-bold px-3 py-1 d-block w-100" data-ar="&lt;u&gt;إلـى مـن يـهـمـه الأمـر&lt;/u&gt;" data-en="&lt;u&gt;TO WHOM IT MAY CONCERN&lt;/u&gt;" style="text-align: center; letter-spacing: 1px;"><u>إلـى مـن يـهـمـه الأمـر</u></h3>
                </div>

                <div class="fs-5 px-3 mb-5" style="line-height: 2.3;">
                    <p class="mb-4">
                        تشهد مدارس الدلتا الحديثة للغات بأن <?php echo $sTerm; ?>/ <strong><?php echo htmlspecialchars($student['name'] ?? '-'); ?></strong>
                    </p>

                    <p class="mb-4">
                        <?php echo $eTerm; ?> بالمدرسة بالصف/ <strong><?php echo htmlspecialchars($cleanGradeName); ?></strong> للعام الدراسي/ <strong><?php echo htmlspecialchars($student['academic_year_name'] ?? '-'); ?></strong>
                    </p>

                    <p class="mb-4">
                        وملتزم بالحضور في المدرسة و<?php echo $sTerm; ?> المذكور<?php echo $isFemale ? 'ة' : ''; ?> <?php echo $isFemale ? 'تتميز' : 'يتميز'; ?> <u>بحسن السير والسلوك</u> .
                    </p>

                    <p class="mb-4 fw-bold text-start">
                        و هذه شهادة منا بذلك دون أدني مسئولية علي المدرسة.
                    </p>

                    <p class="text-center fw-bold mt-5 mb-4">وتفضلوا بقبول فائق الاحترام ،،،</p>

                    <p class="fw-bold mt-4 mb-4 text-start">
                        تحريراً في: <span class="doc-date-display fw-bold"><?php echo date('d / m / Y'); ?></span>
                    </p>
                </div>

            <?php elseif ($type === 'behavior_pledge'): ?>
                <!-- ================== إقرار سلوك ================== -->
                <div class="mb-4">
                    <h3 class="doc-document-title fw-bold px-3 py-1 d-block w-100" data-ar="&lt;u&gt;إقــــرار&lt;/u&gt;" data-en="&lt;u&gt;BEHAVIOR ACKNOWLEDGMENT&lt;/u&gt;" style="text-align: center; letter-spacing: 1px;"><u>إقــــرار</u></h3>
                </div>

                <div class="fs-5 px-3 mb-5" style="line-height: 2.3;">
                    <?php
                    $childTerm = $isFemale ? 'الطفلة' : 'الطفل';
                    $itselfTerm = $isFemale ? 'بنفسها' : 'بنفسه';
                    $mySonTerm = $isFemale ? 'لنجلتي' : 'لنجلي';
                    $gNameVal = trim($student['guardian_name'] ?? $student['father_name'] ?? '');
                    $gNationalIdVal = trim($student['guardian_national_id'] ?? $student['father_national_id'] ?? '');
                    ?>
                    <p class="mb-4">
                        أقر أنا ولي أمر <?php echo $childTerm; ?>/ <strong><?php echo htmlspecialchars($student['name'] ?? '-'); ?></strong>
                    </p>

                    <p class="mb-4">
                        بأنه في حالة قيام <?php echo $childTerm; ?> بأي سلوك حركي ضار <?php echo $itselfTerm; ?> أو بالأطفال الآخرين
                    </p>

                    <p class="mb-4">
                        يحق للمدرسة طلب تحويل <?php echo $childTerm; ?> إلى مدرسة أخرى في نهاية العام الدراسي/ <strong><?php echo htmlspecialchars($student['academic_year_name'] ?? '-'); ?></strong>
                    </p>

                    <p class="mb-4">
                        وهذا تعهد مني بتعديل السلوك الحركي <?php echo $mySonTerm; ?>.
                    </p>

                    <p class="text-center fw-bold mt-4 mb-4">المقر بما فيه ،،،</p>
                </div>

                <div class="d-flex flex-column align-items-start ms-auto text-start fs-5 fw-bold mt-4 mb-4" style="width: 320px; line-height: 2.2;">
                    <div class="d-flex align-items-center w-100 mb-2">
                        <span style="min-width: 125px;">ولي الأمـــر/</span>
                        <span class="fw-normal opacity-75 text-muted flex-grow-1 text-nowrap">....................................</span>
                    </div>
                    <div class="d-flex align-items-center w-100 mb-2">
                        <span style="min-width: 125px;">التوقيـــع/</span>
                        <span class="fw-normal opacity-75 text-muted flex-grow-1 text-nowrap">....................................</span>
                    </div>
                    <div class="d-flex align-items-center w-100 mb-2">
                        <span style="min-width: 125px;">رقم البطاقة/</span>
                        <span class="fw-normal opacity-75 text-muted flex-grow-1 text-nowrap">....................................</span>
                    </div>
                </div>

            <?php endif; ?>

            <!-- Optional Additional Notes Box -->
            <div id="docAdditionalNotesBox" class="border border-warning rounded p-3 mb-4 bg-light" style="display: none; border-style: dashed !important; font-size: 14px; line-height: 1.6;">
                <div class="fw-bold text-dark mb-1"><i class="fas fa-exclamation-circle me-1 text-warning"></i>ملاحظات إضافية:</div>
                <div id="docAdditionalNotesContent" class="text-muted"></div>
            </div>

            <?php if ($type !== 'transfer'): ?>
                <!-- Official Signature Fields Block -->
                <div class="doc-signatures-box row text-center justify-content-between mt-5 pt-3 fs-5 fw-bold" style="line-height: 1.8;">
                    <div class="col-4" id="sigColStudentAffairs">
                        <div class="doc-sig-title" data-ar="<?php echo htmlspecialchars($studentAffairsSigTitle); ?>" data-en="<?php echo htmlspecialchars($studentAffairsSigTitleEn); ?>"><?php echo htmlspecialchars($studentAffairsSigTitle); ?></div>
                        <div class="small text-muted fw-normal mt-1 signature-name" id="docStudentAffairsName" style="display: none;" data-ar="<?php echo htmlspecialchars($studentAffairsOfficer); ?>" data-en="<?php echo htmlspecialchars($studentAffairsOfficerEn); ?>"><?php echo htmlspecialchars($studentAffairsOfficer); ?></div>
                    </div>
                    <div class="col-4" id="sigColSchoolDirector">
                        <div class="doc-sig-title" data-ar="<?php echo htmlspecialchars($directorSigTitle); ?>" data-en="<?php echo htmlspecialchars($directorSigTitleEn); ?>"><?php echo htmlspecialchars($directorSigTitle); ?></div>
                        <div class="small text-muted fw-normal mt-1 signature-name" id="docSchoolDirectorName" style="display: none;" data-ar="<?php echo htmlspecialchars($schoolDirector); ?>" data-en="<?php echo htmlspecialchars($schoolDirectorEn); ?>"><?php echo htmlspecialchars($schoolDirector); ?></div>
                    </div>
                    <div class="col-4" id="sigColAdminDirector" style="display: none;">
                        <div class="doc-sig-title" data-ar="المدير الإداري" data-en="Administrative Director">المدير الإداري</div>
                        <div class="small text-muted fw-normal mt-1 signature-name" id="docAdminDirectorName" style="display: none;" data-ar="<?php echo htmlspecialchars($adminDirector); ?>" data-en="<?php echo htmlspecialchars($adminDirectorEn); ?>"><?php echo htmlspecialchars($adminDirector); ?></div>
                    </div>
                </div>
            <?php endif; ?>
        </div>

        <div class="doc-editor-status-bar no-print d-flex flex-wrap align-items-center justify-content-between gap-2 px-3 py-2" role="status" aria-live="polite">
            <div class="d-flex align-items-center gap-2">
                <span id="docEditStatus"><i class="fas fa-check-circle text-success me-1"></i>جاهز للتحرير</span>
                <span class="status-divider"></span>
                <span>الكلمات: <strong id="docWordCount">0</strong></span>
                <span>الأحرف: <strong id="docCharacterCount">0</strong></span>
                <span id="docSelectionCount" class="text-muted"></span>
            </div>
            <div class="d-flex align-items-center gap-2">
                <span id="docPageUsage">الصفحة: 0%</span>
                <span id="docPageOverflowWarning" class="doc-page-overflow-warning d-none">
                    <i class="fas fa-triangle-exclamation me-1"></i>المحتوى يتجاوز صفحة A4
                </span>
                <label for="docZoomSelect" class="visually-hidden">تكبير المعاينة</label>
                <select id="docZoomSelect" class="form-select form-select-sm" style="width: 105px;" title="تكبير المعاينة">
                    <option value="auto">ملاءمة</option>
                    <option value="0.75">75%</option>
                    <option value="0.9">90%</option>
                    <option value="1">100%</option>
                    <option value="1.1">110%</option>
                    <option value="1.25">125%</option>
                </select>
            </div>
        </div>
    </div>

</div>
<?php else: ?>
    <!-- Notice if no student selected -->
    <div class="alert alert-info py-4 text-center shadow-xs border-0 no-print" style="border-radius: 12px;">
        <i class="fas fa-info-circle fa-2x mb-2 text-primary"></i>
        <h5 class="fw-bold text-dark">برجاء اختيار وتحديد الطالب أولاً</h5>
        <p class="text-muted mb-0">استخدم شريط الفلاتر السريع بالاعلى لتصفية واختيار الطالب المستهدف، ثم اضغط على "عرض المستند".</p>
    </div>
<?php endif; ?>

<script src="../assets/js/statements-editor.js"></script>
<!-- JavaScript Cascading Filters & Settings Binding -->
<script>
document.addEventListener('DOMContentLoaded', function() {
    // ----------------------------------------------------
    // § 1 — Cascading Filter & Dynamic Search Logic
    // ----------------------------------------------------
    const stageCheckboxes = document.querySelectorAll('.stage-checkbox');
    const gradeCheckboxes = document.querySelectorAll('.grade-checkbox');
    const classCheckboxes = document.querySelectorAll('.class-checkbox');
    const studentRadios = document.querySelectorAll('.student-radio');
    const studentSearchInput = document.getElementById('studentSearchInput');

    const selectedStagesLabel = document.getElementById('selectedStagesLabel');
    const selectedGradesLabel = document.getElementById('selectedGradesLabel');
    const selectedClassesLabel = document.getElementById('selectedClassesLabel');
    const selectedStudentLabel = document.getElementById('selectedStudentLabel');
    const resetFiltersBtn = document.getElementById('resetFiltersBtn');

    function applyFilters() {
        const activeStages = Array.from(stageCheckboxes).filter(cb => cb.checked).map(cb => cb.value);
        const activeGrades = Array.from(gradeCheckboxes).filter(cb => cb.checked).map(cb => cb.value);
        const activeClasses = Array.from(classCheckboxes).filter(cb => cb.checked).map(cb => cb.value);
        const searchVal = studentSearchInput ? studentSearchInput.value.toLowerCase().trim() : '';

        // 1. Stages Label
        if (selectedStagesLabel) {
            selectedStagesLabel.innerText = activeStages.length === 0 ? 'الكل' : (activeStages.length === 1 ? document.querySelector('label[for="stage_' + activeStages[0] + '"]').innerText.trim() : activeStages.length);
        }

        // 2. Cascade Grades
        document.querySelectorAll('.grade-item').forEach(item => {
            const stageId = item.getAttribute('data-stage');
            const cb = item.querySelector('.grade-checkbox');
            if (activeStages.length === 0 || activeStages.includes(stageId)) {
                item.style.display = '';
            } else {
                item.style.display = 'none';
                cb.checked = false;
            }
        });

        const updatedActiveGrades = Array.from(gradeCheckboxes).filter(cb => cb.checked).map(cb => cb.value);
        if (selectedGradesLabel) {
            selectedGradesLabel.innerText = updatedActiveGrades.length === 0 ? 'الكل' : (updatedActiveGrades.length === 1 ? document.querySelector('label[for="grade_' + updatedActiveGrades[0] + '"]').innerText.trim() : updatedActiveGrades.length);
        }

        // 3. Cascade Classes
        document.querySelectorAll('.class-item').forEach(item => {
            const gradeId = item.getAttribute('data-grade');
            const cb = item.querySelector('.class-checkbox');
            if (updatedActiveGrades.length === 0 || updatedActiveGrades.includes(gradeId)) {
                item.style.display = '';
            } else {
                item.style.display = 'none';
                cb.checked = false;
            }
        });

        const updatedActiveClasses = Array.from(classCheckboxes).filter(cb => cb.checked).map(cb => cb.value);
        if (selectedClassesLabel) {
            selectedClassesLabel.innerText = updatedActiveClasses.length === 0 ? 'الكل' : (updatedActiveClasses.length === 1 ? document.querySelector('label[for="class_' + updatedActiveClasses[0] + '"]').innerText.trim() : updatedActiveClasses.length);
        }

        // 4. Filter Students
        document.querySelectorAll('.student-item').forEach(item => {
            const sStage = item.getAttribute('data-stage');
            const sGrade = item.getAttribute('data-grade');
            const sClass = item.getAttribute('data-class');
            const sCode = item.getAttribute('data-code').toLowerCase();
            const sNameLower = item.getAttribute('data-name-lower');
            const radio = item.querySelector('.student-radio');

            let show = true;
            if (activeStages.length > 0 && !activeStages.includes(sStage)) show = false;
            if (updatedActiveGrades.length > 0 && !updatedActiveGrades.includes(sGrade)) show = false;
            if (updatedActiveClasses.length > 0 && !updatedActiveClasses.includes(sClass)) show = false;

            if (searchVal !== '') {
                const matchesSearch = sNameLower.includes(searchVal) || sCode.includes(searchVal);
                if (!matchesSearch) show = false;
            }

            if (show) {
                item.style.display = '';
            } else {
                item.style.display = 'none';
                if (radio.checked) {
                    radio.checked = false;
                    if (selectedStudentLabel) selectedStudentLabel.innerText = 'اختر الطالب';
                }
            }
        });
    }

    stageCheckboxes.forEach(cb => cb.addEventListener('change', function() {
        gradeCheckboxes.forEach(gcb => gcb.checked = false);
        classCheckboxes.forEach(ccb => ccb.checked = false);
        applyFilters();
    }));
    gradeCheckboxes.forEach(cb => cb.addEventListener('change', function() {
        classCheckboxes.forEach(ccb => ccb.checked = false);
        applyFilters();
    }));
    classCheckboxes.forEach(cb => cb.addEventListener('change', applyFilters));
    if (studentSearchInput) studentSearchInput.addEventListener('input', applyFilters);

    studentRadios.forEach(radio => {
        radio.addEventListener('change', function() {
            if (this.checked) {
                const labelText = this.nextElementSibling.innerText.trim();
                if (selectedStudentLabel) selectedStudentLabel.innerText = labelText;
            }
        });
    });

    if (resetFiltersBtn) {
        resetFiltersBtn.addEventListener('click', function() {
            stageCheckboxes.forEach(cb => cb.checked = false);
            gradeCheckboxes.forEach(cb => cb.checked = false);
            classCheckboxes.forEach(cb => cb.checked = false);
            studentRadios.forEach(r => r.checked = false);
            if (studentSearchInput) studentSearchInput.value = '';
            if (selectedStudentLabel) selectedStudentLabel.innerText = 'اختر الطالب';
            applyFilters();
        });
    }

    const checkedRadio = Array.from(studentRadios).find(r => r.checked);
    if (checkedRadio && selectedStudentLabel) {
        selectedStudentLabel.innerText = checkedRadio.nextElementSibling.innerText.trim();
    }

    applyFilters();

    // ----------------------------------------------------
    // § 2 — Live Update Print Settings Controls
    // ----------------------------------------------------
    const docPaper = document.getElementById('officialDocPaper');
    if (!docPaper) return;

    const toggleSchoolHeader = document.getElementById('toggleSchoolHeader');
    const toggleLogo = document.getElementById('toggleLogo');
    const togglePhoto = document.getElementById('togglePhoto');
    const toggleBorder = document.getElementById('toggleBorder');
    const toggleSignatures = document.getElementById('toggleSignatures');
    const toggleDetailsTable = document.getElementById('toggleDetailsTable');
    const toggleDocDate = document.getElementById('toggleDocDate');
    const additionalNotesInput = document.getElementById('additionalNotesInput');

    // Custom inputs
    const destinationSchoolInput = document.getElementById('destinationSchoolInput');
    const guardianNameInput = document.getElementById('guardianNameInput');
    const guardianRelationInput = document.getElementById('guardianRelationInput');

    const notesBox = document.getElementById('docAdditionalNotesBox');
    const notesContent = document.getElementById('docAdditionalNotesContent');

    const signatureModeSelect = document.getElementById('signatureModeSelect');
    const printLayoutLang = document.getElementById('printLayoutLang');
    const documentFontSelect = document.getElementById('docFontFamilySelect');
    const sigToggles = document.querySelectorAll('.signature-toggle');
    const documentFontKeys = new Set(['tajawal', 'amiri', 'noto-naskh']);

    // ── LocalStorage Settings Persistence (Scoped per Document Tab) ──
    const currentDocType = '<?php echo htmlspecialchars($type); ?>';
    const SETTINGS_KEY = 'educore_statements_print_settings_' + currentDocType;
    const signaturesDefaultOff = ['withdrawal', 'behavior_pledge'].includes(currentDocType);
    const SIGNATURE_DEFAULTS_VERSION = 1;

    // Migrate legacy settings once for tabs whose signatures now start disabled.
    if (signaturesDefaultOff) {
        try {
            const rawStored = localStorage.getItem(SETTINGS_KEY);
            if (rawStored) {
                const parsed = JSON.parse(rawStored);
                if (parsed.signatureDefaultsVersion !== SIGNATURE_DEFAULTS_VERSION) {
                    const legacyWithdrawalEnabled = currentDocType === 'withdrawal'
                        && parsed.withdrawalSignaturesExplicitlyEnabled === true;
                    parsed.toggleSignatures = legacyWithdrawalEnabled;
                    parsed.sigStudentAffairs = legacyWithdrawalEnabled
                        ? (parsed.sigStudentAffairs !== undefined ? parsed.sigStudentAffairs : true)
                        : false;
                    parsed.sigSchoolDirector = legacyWithdrawalEnabled
                        ? (parsed.sigSchoolDirector !== undefined ? parsed.sigSchoolDirector : true)
                        : false;
                    parsed.signaturesExplicitlyEnabled = legacyWithdrawalEnabled;
                    parsed.signatureDefaultsVersion = SIGNATURE_DEFAULTS_VERSION;
                    localStorage.setItem(SETTINGS_KEY, JSON.stringify(parsed));
                }
            }
        } catch(e) {}
    }

    function getCurrentSettings() {
        return {
            toggleSchoolHeader: toggleSchoolHeader ? toggleSchoolHeader.checked : true,
            toggleLogo: toggleLogo ? toggleLogo.checked : true,
            togglePhoto: togglePhoto ? togglePhoto.checked : false,
            toggleBorder: toggleBorder ? toggleBorder.checked : false,
            toggleSignatures: toggleSignatures ? toggleSignatures.checked : !signaturesDefaultOff,
            toggleDocDate: toggleDocDate ? toggleDocDate.checked : true,
            toggleDetailsTable: toggleDetailsTable ? toggleDetailsTable.checked : false,
            signatureMode: signatureModeSelect ? signatureModeSelect.value : 'titles_only',
            printLang: printLayoutLang ? printLayoutLang.value : 'ar',
            documentFont: documentFontSelect ? documentFontSelect.value : 'tajawal',
            sigStudentAffairs: document.getElementById('toggleStudentAffairs') ? document.getElementById('toggleStudentAffairs').checked : !signaturesDefaultOff,
            sigSchoolDirector: document.getElementById('toggleSchoolDirector') ? document.getElementById('toggleSchoolDirector').checked : !signaturesDefaultOff,
            sigAdminDirector: document.getElementById('toggleAdminDirector') ? document.getElementById('toggleAdminDirector').checked : false,
            signaturesExplicitlyEnabled: signaturesDefaultOff && toggleSignatures && toggleSignatures.checked,
            signatureDefaultsVersion: signaturesDefaultOff ? SIGNATURE_DEFAULTS_VERSION : 0,
            withdrawalSignaturesExplicitlyEnabled: currentDocType === 'withdrawal' && toggleSignatures && toggleSignatures.checked
        };
    }

    function saveSettings() {
        try {
            localStorage.setItem(SETTINGS_KEY, JSON.stringify(getCurrentSettings()));
        } catch(e) {}
    }

    function loadSettings() {
        try {
            const stored = localStorage.getItem(SETTINGS_KEY);
            if (!stored) return null;
            return JSON.parse(stored);
        } catch(e) { return null; }
    }

    function applyDocumentFont(fontKey, persist = true) {
        const normalizedFont = documentFontKeys.has(fontKey) ? fontKey : 'tajawal';
        docPaper.dataset.documentFont = normalizedFont;
        if (documentFontSelect) documentFontSelect.value = normalizedFont;
        if (persist) saveSettings();
    }

    function applyStoredSettings() {
        const s = loadSettings();
        const toggleSA = document.getElementById('toggleStudentAffairs');
        const toggleSD = document.getElementById('toggleSchoolDirector');
        const toggleAD = document.getElementById('toggleAdminDirector');

        if (!s) {
            if (signaturesDefaultOff) {
                if (toggleSignatures) toggleSignatures.checked = false;
                if (toggleSA) toggleSA.checked = false;
                if (toggleSD) toggleSD.checked = false;
            }
            return;
        }

        if (toggleSchoolHeader) toggleSchoolHeader.checked = (s.toggleSchoolHeader !== undefined) ? s.toggleSchoolHeader : true;
        if (toggleLogo) toggleLogo.checked = (s.toggleLogo !== undefined) ? s.toggleLogo : true;
        if (togglePhoto) togglePhoto.checked = (s.togglePhoto !== undefined) ? s.togglePhoto : false;
        if (toggleBorder) toggleBorder.checked = (s.toggleBorder !== undefined) ? s.toggleBorder : false;

        if (signaturesDefaultOff) {
            // Respect an explicit later choice while keeping the first load disabled.
            const explicitlyEnabled = s.signaturesExplicitlyEnabled === true
                || (currentDocType === 'withdrawal' && s.withdrawalSignaturesExplicitlyEnabled === true);
            if (toggleSignatures) toggleSignatures.checked = explicitlyEnabled;
            if (toggleSA) toggleSA.checked = (s.sigStudentAffairs !== undefined) ? s.sigStudentAffairs : false;
            if (toggleSD) toggleSD.checked = (s.sigSchoolDirector !== undefined) ? s.sigSchoolDirector : false;
        } else {
            if (toggleSignatures) toggleSignatures.checked = (s.toggleSignatures !== undefined) ? s.toggleSignatures : true;
            if (toggleSA) toggleSA.checked = (s.sigStudentAffairs !== undefined) ? s.sigStudentAffairs : true;
            if (toggleSD) toggleSD.checked = (s.sigSchoolDirector !== undefined) ? s.sigSchoolDirector : true;
        }

        if (toggleDocDate) toggleDocDate.checked = (s.toggleDocDate !== undefined) ? s.toggleDocDate : true;
        if (toggleDetailsTable) toggleDetailsTable.checked = (s.toggleDetailsTable !== undefined) ? s.toggleDetailsTable : false;
        if (signatureModeSelect) signatureModeSelect.value = s.signatureMode || 'titles_only';
        if (printLayoutLang) printLayoutLang.value = s.printLang || 'ar';
        if (documentFontSelect) {
            documentFontSelect.value = documentFontKeys.has(s.documentFont) ? s.documentFont : 'tajawal';
        }
        if (toggleAD) toggleAD.checked = s.sigAdminDirector !== undefined ? s.sigAdminDirector : false;
    }

    // Apply stored settings BEFORE syncing classes
    applyStoredSettings();
    applyDocumentFont(documentFontSelect ? documentFontSelect.value : 'tajawal', false);
    syncPreviewClasses();
    saveSettings();

    // ── DEBUG: Log initial checkbox states after applyStoredSettings ──
    console.log('[DEBUG] After applyStoredSettings+syncPreviewClasses+saveSettings');
    console.log('[DEBUG] toggleSignatures.checked =', toggleSignatures ? toggleSignatures.checked : 'N/A');
    console.log('[DEBUG] toggleStudentAffairs.checked =', document.getElementById('toggleStudentAffairs') ? document.getElementById('toggleStudentAffairs').checked : 'N/A');
    console.log('[DEBUG] toggleSchoolDirector.checked =', document.getElementById('toggleSchoolDirector') ? document.getElementById('toggleSchoolDirector').checked : 'N/A');
    console.log('[DEBUG] docPaper classList =', docPaper.classList.toString());

    // ── syncPreviewClasses: toggle CSS classes on doc paper ──
    function syncPreviewClasses() {
        if (toggleSchoolHeader && toggleSchoolHeader.checked) docPaper.classList.add('show-header');
        else if (toggleSchoolHeader) docPaper.classList.remove('show-header');

        if (toggleLogo && toggleLogo.checked) docPaper.classList.add('show-logo');
        else if (toggleLogo) docPaper.classList.remove('show-logo');

        if (togglePhoto && togglePhoto.checked) docPaper.classList.add('show-photo');
        else if (togglePhoto) docPaper.classList.remove('show-photo');

        if (toggleBorder && toggleBorder.checked) docPaper.classList.add('has-border');
        else if (toggleBorder) docPaper.classList.remove('has-border');

        if (toggleSignatures && toggleSignatures.checked) docPaper.classList.add('show-signatures');
        else if (toggleSignatures) docPaper.classList.remove('show-signatures');

        if (toggleDocDate && toggleDocDate.checked) docPaper.classList.add('show-doc-date');
        else if (toggleDocDate) docPaper.classList.remove('show-doc-date');

        if (toggleDetailsTable && toggleDetailsTable.checked) docPaper.classList.add('show-details-table');
        else if (toggleDetailsTable) docPaper.classList.remove('show-details-table');
    }

    [toggleSchoolHeader, toggleLogo, togglePhoto, toggleBorder, toggleSignatures, toggleDocDate, toggleDetailsTable].forEach(el => {
        if (el) el.addEventListener('change', syncPreviewClasses);
    });

    // When the main signatures toggle is turned ON, auto-enable default individual
    // signature columns if none are currently checked (prevents empty signature box)
    // Also re-sync the display state of all checked columns to fix stale inline styles
    if (toggleSignatures) {
        toggleSignatures.addEventListener('change', function() {
            if (this.checked) {
                const anyChecked = Array.from(sigToggles).some(t => t.checked);
                if (!anyChecked) {
                    // No individual column selected — auto-enable SA and SD
                    sigToggles.forEach(toggle => {
                        const targetId = toggle.getAttribute('data-target');
                        if (targetId === 'sigColStudentAffairs' || targetId === 'sigColSchoolDirector') {
                            toggle.checked = true;
                        }
                    });
                }
                // Always re-sync display state for every checked individual toggle
                // to ensure columns are visible (fixes stale display:none from prior state)
                sigToggles.forEach(toggle => {
                    const targetId = toggle.getAttribute('data-target');
                    const target = document.getElementById(targetId);
                    if (target) target.style.display = toggle.checked ? '' : 'none';
                });
                updateSignaturesLayout();
            }
            saveSettings();

            // ── DEBUG: Log after custom toggleSignatures handler ──
            console.log('[DEBUG] After toggleSignatures change handler');
            console.log('[DEBUG] show-signatures class present:', docPaper.classList.contains('show-signatures'));
            console.log('[DEBUG] sigColStudentAffairs display:', document.getElementById('sigColStudentAffairs') ? document.getElementById('sigColStudentAffairs').style.display : 'N/A');
            console.log('[DEBUG] sigColSchoolDirector display:', document.getElementById('sigColSchoolDirector') ? document.getElementById('sigColSchoolDirector').style.display : 'N/A');
        });
    }

    // ── Date Display: uses today's date, respects current language ──
    function updateDateDisplay() {
        const dateDisplays = document.querySelectorAll('.doc-date-display');
        if (dateDisplays.length === 0) return;
        const dateObj = new Date();
        const year = dateObj.getFullYear();
        const month = String(dateObj.getMonth() + 1).padStart(2, '0');
        const day = String(dateObj.getDate()).padStart(2, '0');
        const formattedDate = `${day} / ${month} / ${year}`;
        dateDisplays.forEach(function(dd) {
            dd.innerText = formattedDate;
        });
    }

    // Initial date update
    updateDateDisplay();

    if (additionalNotesInput) {
        additionalNotesInput.addEventListener('input', function() {
            if (notesBox && notesContent) {
                if (this.value.trim() !== '') {
                    notesContent.innerText = this.value;
                    notesBox.style.display = 'block';
                } else {
                    notesBox.style.display = 'none';
                }
            }
        });
    }

    // Dynamic bindings for custom inputs
    if (destinationSchoolInput) {
        destinationSchoolInput.addEventListener('input', function() {
            const val = this.value.trim() || '.......................';
            setEntityNameInDocument(val);
        });
    }

    if (guardianNameInput) {
        guardianNameInput.addEventListener('input', function() {
            const val = this.value.trim();
            const lbl = document.getElementById('lbl_guardian_name');
            if (lbl) {
                lbl.textContent = val || '[ اضغط هنا لكتابة اسم ولي الأمر ]';
                if (val && !val.startsWith('[')) {
                    lbl.classList.add('is-filled');
                } else {
                    lbl.classList.remove('is-filled');
                }
            }
        });
    }

    if (guardianRelationInput) {
        guardianRelationInput.addEventListener('input', function() {
            const val = this.value.trim();
            const lbl = document.getElementById('lbl_guardian_relation');
            if (lbl) {
                lbl.textContent = val || '[ اضغط هنا لكتابة الصفة ]';
                if (val && !val.startsWith('[')) {
                    lbl.classList.add('is-filled');
                } else {
                    lbl.classList.remove('is-filled');
                }
            }
        });
    }

    // ── Signature Mode ──
    if (signatureModeSelect) {
        signatureModeSelect.addEventListener('change', function() {
            const sigNames = document.querySelectorAll('.signature-name');
            sigNames.forEach(el => {
                el.style.display = (this.value === 'titles_only') ? 'none' : '';
            });
            saveSettings();
        });
        // Apply initial state from stored settings
        if (signatureModeSelect.value === 'titles_and_names') {
            document.querySelectorAll('.signature-name').forEach(el => { el.style.display = ''; });
        }
    }

    function updateSignaturesLayout() {
        document.querySelectorAll('.doc-signatures-box').forEach(function(sigBox) {
            const visibleCols = Array.from(sigBox.querySelectorAll('.col-4')).filter(col => col.style.display !== 'none');
            sigBox.classList.remove('justify-content-center', 'justify-content-between', 'justify-content-end', 'justify-content-start');
            if (visibleCols.length === 1) {
                sigBox.classList.add('justify-content-end');
            } else {
                sigBox.classList.add('justify-content-between');
            }
        });
    }

    sigToggles.forEach(toggle => {
        const targetId = toggle.getAttribute('data-target');
        const updateTargets = function(checked) {
            const target = document.getElementById(targetId);
            if (target) target.style.display = checked ? '' : 'none';
        };
        updateTargets(toggle.checked);

        // ── DEBUG: Log initial sigToggle state ──
        console.log('[DEBUG] sigToggle init:', targetId, 'checked =', toggle.checked);

        toggle.addEventListener('change', function() {
            updateTargets(this.checked);
            updateSignaturesLayout();
            saveSettings();
        });
    });
    updateSignaturesLayout();

    // ── DEBUG: Log state after sigToggles setup ──
    console.log('[DEBUG] After sigToggles setup');
    console.log('[DEBUG] sigColStudentAffairs display:', document.getElementById('sigColStudentAffairs') ? document.getElementById('sigColStudentAffairs').style.display : 'N/A');
    console.log('[DEBUG] sigColSchoolDirector display:', document.getElementById('sigColSchoolDirector') ? document.getElementById('sigColSchoolDirector').style.display : 'N/A');

    // ── Language Switch: translate elements, swap direction, update date locale ──
    function applyLanguage(lang) {
        const isEn = lang === 'en';
        const paper = document.getElementById('officialDocPaper');
        if (paper) {
            paper.setAttribute('dir', isEn ? 'ltr' : 'rtl');
            paper.style.textAlign = isEn ? 'left' : 'right';
        }

        // Header grid: swap direction so columns reorder visually
        const headerBox = document.querySelector('.doc-header-box');
        if (headerBox) {
            headerBox.style.direction = isEn ? 'ltr' : 'rtl';
        }

        // Translate all elements with data-ar/data-en attributes
        const langElements = document.querySelectorAll('[data-ar][data-en]');
        langElements.forEach(el => {
            const targetText = isEn ? el.getAttribute('data-en') : el.getAttribute('data-ar');
            if (targetText !== null) {
                el.innerHTML = targetText;
            }
        });

        // Update date displays to match language locale
        updateDateDisplay();

        updateSignaturesLayout();
        saveSettings();
    }

    if (printLayoutLang) {
        printLayoutLang.addEventListener('change', function() {
            applyLanguage(this.value);
        });
        // Apply initial language from stored settings
        if (printLayoutLang.value === 'en') {
            applyLanguage('en');
        }
    }

    if (documentFontSelect) {
        documentFontSelect.addEventListener('change', function() {
            applyDocumentFont(this.value);
        });
    }

    // Run initial preview styling sync & signature layout calculation
    syncPreviewClasses();
    updateSignaturesLayout();
    saveSettings();

    // ----------------------------------------------------
    // § Entity Name: Inline editable span inside <strong>
    // Prevents browser from injecting font-weight:normal spans
    // ----------------------------------------------------
    // § Entity Name: Click-to-Input pattern
    // Clicking the display span creates a real <input> in its place.
    // On blur, input hides and span shows with updated text.
    // This avoids ALL contenteditable font/style browser quirks.
    // ----------------------------------------------------
    function initEntityClickToInput(displaySpan) {
        const initialText = displaySpan.textContent.trim();
        const defaultPlaceholder = displaySpan.getAttribute('data-placeholder') || initialText;
        let currentValue = '';

        // Build a styled input mirroring the bold document font
        const inp = document.createElement('input');
        inp.type = 'text';
        inp.className = 'entity-inline-input';
        inp.setAttribute('dir', 'auto');
        inp.style.cssText = [
            'font-family: inherit',
            'font-size: inherit',
            'font-weight: bold',
            'color: #000',
            'background: transparent',
            'border: none',
            'border-bottom: 1.5px solid #333',
            'outline: none',
            'padding: 0 2px',
            'min-width: 160px',
            'max-width: 100%',
            'display: none',
            'vertical-align: baseline'
        ].join(';');

        // Insert input right after the display span
        displaySpan.parentNode.insertBefore(inp, displaySpan.nextSibling);

        function autoResizeInput() {
            const len = Math.max((inp.value || '').length, 15);
            inp.style.width = (len + 3) + 'ch';
        }

        function showInput() {
            inp.value = currentValue;
            autoResizeInput();
            inp.style.display = 'inline-block';
            displaySpan.style.display = 'none';
            inp.focus();
            inp.select();
        }

        inp.addEventListener('input', autoResizeInput);

        function hideInput() {
            currentValue = inp.value.trim();
            inp.style.display = 'none';
            displaySpan.style.display = '';
            const finalVal = currentValue || defaultPlaceholder;
            displaySpan.textContent = finalVal;
            if (currentValue && !currentValue.startsWith('[')) {
                displaySpan.classList.add('is-filled');
                if (displaySpan.id === 'lbl_guardian_name' && guardianNameInput) {
                    guardianNameInput.value = currentValue;
                } else if (displaySpan.id === 'lbl_guardian_relation' && guardianRelationInput) {
                    guardianRelationInput.value = currentValue;
                } else {
                    addEntityToSaved(currentValue);
                }
            } else {
                displaySpan.classList.remove('is-filled');
            }
        }

        displaySpan.addEventListener('click', showInput);
        inp.addEventListener('blur', hideInput);
        inp.addEventListener('keydown', function(e) {
            if (e.key === 'Enter') { inp.blur(); }
            if (e.key === 'Escape') { inp.value = currentValue; inp.blur(); }
        });
    }

    function attachEntityEditors() {
        document.querySelectorAll('.entity-name-display').forEach(function(el) {
            if (!el.dataset.editInit) {
                el.dataset.editInit = '1';
                initEntityClickToInput(el);
            }
        });
    }

    attachEntityEditors();

    // Re-attach when language switch re-injects content
    (function() {
        const bodyBox = document.getElementById('docBodyContentBox');
        if (!bodyBox) return;
        new MutationObserver(attachEntityEditors).observe(bodyBox, { childList: true, subtree: true });
    })();

    // ----------------------------------------------------
    // § Saved Entities Sidebar Manager (LocalStorage & Inline Edit/Delete)
    // ----------------------------------------------------
    const ENTITIES_KEY = 'educore_saved_entities_list_' + currentDocType;

    let defaultEntities = ['جامعة المنصورة', 'مديرية التربية والتعليم بالدقهلية', 'إدارة طلخا التعليمية', 'مصلحة الأحوال المدنية', 'السفارة السعودية'];
    if (currentDocType === 'transfer') {
        defaultEntities = ['الدلتا الأمريكية', 'مدرسة النيل الدولية', 'مدرسة السلام الخاصة', 'مدرسة اللغات المتميزة'];
    } else if (currentDocType === 'second_session') {
        defaultEntities = ['اللغة الإنجليزية', 'المستوى الرفيع', 'الرياضيات', 'العلوم'];
    } else if (currentDocType === 'withdrawal') {
        defaultEntities = ['الإعدادية', 'الابتدائية', 'الثانوية العامة', 'الدبلومة الأمريكية'];
    }

    function getSavedEntities() {
        try {
            const stored = localStorage.getItem(ENTITIES_KEY);
            if (stored) return JSON.parse(stored);
        } catch(e) {}
        return defaultEntities;
    }

    function saveEntitiesList(list) {
        try {
            localStorage.setItem(ENTITIES_KEY, JSON.stringify(list));
        } catch(e) {}
        renderSavedEntitiesBar();
    }

    function addEntityToSaved(name) {
        if (!name) return;
        name = name.trim();
        if (!name || name.startsWith('[')) return;
        let list = getSavedEntities();
        if (!list.includes(name)) {
            list.unshift(name);
            if (list.length > 25) list = list.slice(0, 25);
            saveEntitiesList(list);
        }
    }

    function editEntityInline(rowEl, oldName) {
        rowEl.innerHTML = '';

        const inp = document.createElement('input');
        inp.type = 'text';
        inp.className = 'form-control form-control-sm py-0 px-1 me-1';
        inp.style.fontSize = '12px';
        inp.value = oldName;

        const saveBtn = document.createElement('button');
        saveBtn.className = 'btn btn-sm btn-success p-0 px-1 me-1';
        saveBtn.type = 'button';
        saveBtn.title = 'حفظ التعديل';
        saveBtn.innerHTML = '<i class="fas fa-check" style="font-size: 10px;"></i>';

        const cancelBtn = document.createElement('button');
        cancelBtn.className = 'btn btn-sm btn-secondary p-0 px-1';
        cancelBtn.type = 'button';
        cancelBtn.title = 'إلغاء';
        cancelBtn.innerHTML = '<i class="fas fa-times" style="font-size: 10px;"></i>';

        function doSave() {
            const trimmed = inp.value.trim();
            if (trimmed && trimmed !== oldName) {
                let list = getSavedEntities();
                const idx = list.indexOf(oldName);
                if (idx !== -1) {
                    list[idx] = trimmed;
                    saveEntitiesList(list);

                    const currentDisplay = document.querySelector('.entity-name-display');
                    if (currentDisplay && currentDisplay.textContent.trim() === oldName) {
                        setEntityNameInDocument(trimmed);
                    }
                }
            } else {
                renderSavedEntitiesBar();
            }
        }

        saveBtn.addEventListener('click', doSave);
        cancelBtn.addEventListener('click', renderSavedEntitiesBar);
        inp.addEventListener('keydown', function(e) {
            if (e.key === 'Enter') doSave();
            if (e.key === 'Escape') renderSavedEntitiesBar();
        });

        rowEl.appendChild(inp);
        rowEl.appendChild(saveBtn);
        rowEl.appendChild(cancelBtn);
        inp.focus();
        inp.select();
    }

    function removeEntityFromSaved(name) {
        let list = getSavedEntities();
        list = list.filter(item => item !== name);
        saveEntitiesList(list);
    }

    function setEntityNameInDocument(name) {
        if (currentDocType === 'withdrawal') {
            const certEl = document.getElementById('lbl_cert_stage');
            if (certEl) {
                certEl.textContent = name;
                if (name && !name.startsWith('[')) {
                    certEl.classList.add('is-filled');
                } else {
                    certEl.classList.remove('is-filled');
                }
            }
        } else {
            document.querySelectorAll('.entity-name-display').forEach(function(el) {
                el.textContent = name;
                if (name && !name.startsWith('[')) {
                    el.classList.add('is-filled');
                } else {
                    el.classList.remove('is-filled');
                }
            });
        }
        document.querySelectorAll('input.entity-inline-input').forEach(function(inp) {
            inp.value = name;
        });
    }

    function addNewEntityInline() {
        const container = document.getElementById('savedEntitiesContainer');
        if (!container) return;

        const newRow = document.createElement('div');
        newRow.className = 'entity-item-row p-1 rounded border bg-white d-flex align-items-center mb-1 shadow-sm';
        newRow.style.borderColor = '#3b82f6 !important';

        const inp = document.createElement('input');
        inp.type = 'text';
        inp.className = 'form-control form-control-sm py-0 px-1 me-1';
        inp.style.fontSize = '12px';
        inp.placeholder = currentDocType === 'transfer' ? 'اسم المدرسة الجديدة...' : (currentDocType === 'second_session' ? 'اسم المادة الجديدة...' : (currentDocType === 'withdrawal' ? 'اسم الشهادة الجديدة...' : 'اسم الجهة الجديدة...'));

        const saveBtn = document.createElement('button');
        saveBtn.className = 'btn btn-sm btn-success p-0 px-1 me-1';
        saveBtn.type = 'button';
        saveBtn.title = 'حفظ';
        saveBtn.innerHTML = '<i class="fas fa-check" style="font-size: 10px;"></i>';

        const cancelBtn = document.createElement('button');
        cancelBtn.className = 'btn btn-sm btn-secondary p-0 px-1';
        cancelBtn.type = 'button';
        cancelBtn.title = 'إلغاء';
        cancelBtn.innerHTML = '<i class="fas fa-times" style="font-size: 10px;"></i>';

        function doSaveNew() {
            const trimmed = inp.value.trim();
            if (trimmed) {
                addEntityToSaved(trimmed);
                setEntityNameInDocument(trimmed);
            } else {
                renderSavedEntitiesBar();
            }
        }

        saveBtn.addEventListener('click', doSaveNew);
        cancelBtn.addEventListener('click', renderSavedEntitiesBar);
        inp.addEventListener('keydown', function(e) {
            if (e.key === 'Enter') doSaveNew();
            if (e.key === 'Escape') renderSavedEntitiesBar();
        });

        newRow.appendChild(inp);
        newRow.appendChild(saveBtn);
        newRow.appendChild(cancelBtn);

        container.insertBefore(newRow, container.firstChild);
        inp.focus();
    }

    function renderSavedEntitiesBar() {
        const container = document.getElementById('savedEntitiesContainer');
        if (!container) return;
        const list = getSavedEntities();
        container.innerHTML = '';

        if (list.length === 0) {
            container.innerHTML = '<div class="text-muted small text-center py-3">لا توجد جهات مسجلة بعد</div>';
            return;
        }

        list.forEach(function(item) {
            const row = document.createElement('div');
            row.className = 'entity-item-row p-2 rounded border bg-light d-flex align-items-center justify-content-between gap-1 shadow-sm';
            row.style.cssText = 'border-color: #cbd5e1 !important; transition: all 0.2s ease;';

            const title = document.createElement('span');
            title.className = 'entity-title text-dark fw-bold flex-grow-1 text-truncate me-1';
            title.style.cssText = 'cursor: pointer; font-size: 12.5px;';
            title.textContent = item;
            title.title = item;
            title.addEventListener('click', function() {
                setEntityNameInDocument(item);
            });

            const actions = document.createElement('div');
            actions.className = 'btn-group btn-group-sm flex-shrink-0';

            const editBtn = document.createElement('button');
            editBtn.type = 'button';
            editBtn.className = 'btn btn-link text-primary p-0 px-1 border-0';
            editBtn.title = 'تعديل هذا الاسم';
            editBtn.innerHTML = '<i class="fas fa-pen" style="font-size: 11px;"></i>';
            editBtn.addEventListener('click', function(e) {
                e.stopPropagation();
                editEntityInline(row, item);
            });

            const delBtn = document.createElement('button');
            delBtn.type = 'button';
            delBtn.className = 'btn btn-link text-danger p-0 px-1 border-0';
            delBtn.title = 'حذف من السجل';
            delBtn.innerHTML = '<i class="fas fa-trash-alt" style="font-size: 11px;"></i>';
            delBtn.addEventListener('click', function(e) {
                e.stopPropagation();
                removeEntityFromSaved(item);
            });

            actions.appendChild(editBtn);
            actions.appendChild(delBtn);

            row.appendChild(title);
            row.appendChild(actions);
            container.appendChild(row);
        });
    }

    const btnAddCurrent = document.getElementById('btnAddCurrentEntity');
    if (btnAddCurrent) {
        btnAddCurrent.addEventListener('click', function() {
            const bodyContent = document.getElementById('savedEntitiesBody');
            const btnToggle = document.getElementById('btnToggleEntitiesCollapse');
            if (bodyContent && bodyContent.style.display === 'none') {
                bodyContent.style.display = 'block';
                if (btnToggle) {
                    const txt = document.getElementById('toggleEntitiesText');
                    if (txt) txt.textContent = 'إخفاء';
                    const icon = btnToggle.querySelector('i');
                    if (icon) icon.className = 'fas fa-chevron-up me-1';
                }
            }
            addNewEntityInline();
        });
    }

    const btnToggleEntities = document.getElementById('btnToggleEntitiesCollapse');
    const savedEntitiesBody = document.getElementById('savedEntitiesBody');
    if (btnToggleEntities && savedEntitiesBody) {
        btnToggleEntities.addEventListener('click', function() {
            const isHidden = getComputedStyle(savedEntitiesBody).display === 'none';
            savedEntitiesBody.style.display = isHidden ? 'block' : 'none';
            const txt = document.getElementById('toggleEntitiesText');
            if (txt) txt.textContent = isHidden ? 'إخفاء' : 'عرض';
            const icon = btnToggleEntities.querySelector('i');
            if (icon) icon.className = isHidden ? 'fas fa-chevron-up me-1' : 'fas fa-chevron-down me-1';
        });
    }

    renderSavedEntitiesBar();
});
</script>

<?php require_once '../includes/admin_footer.php'; ?>
