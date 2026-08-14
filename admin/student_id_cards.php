<?php
/**
 * توليد كروت الطلاب الشخصية — Student ID Cards Generator
 * يُتيح تصفية الطلاب واختيارهم وتوليد كروت شخصية (ID Cards) تفاعلية قابلة للتخصيص والطباعة.
 */
$page_title = "كروت الطلاب (ID)";
$custom_page_title = true;

require_once '../config/database.php';
require_once '../classes/utilities.php';
require_once '../classes/AcademicYear.php';
require_once '../classes/ProfileAttachmentStorage.php';
require_once '../classes/ScopedStaffPortalContext.php';
require_once '../includes/session_config.php';
Utilities::validateSession('admin');

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
$schoolName = $settings['school_name'] ?? '';

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

// ===== معالجة POST لتوليد الكروت =====
$selectedIds   = [];
$studentsData  = [];
$options = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // التحقق من رمز CSRF
    $csrfToken = is_string($_POST['csrf_token'] ?? null) ? $_POST['csrf_token'] : '';
    if (!hash_equals($_SESSION['csrf_token'] ?? '', $csrfToken)) {
        die('خطأ في التحقق من صحة الطلب (CSRF).');
    }
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

    // خيارات الكارت
    $layout = is_string($_POST['opt_layout'] ?? null) && in_array($_POST['opt_layout'], ['portrait', 'landscape'], true)
        ? $_POST['opt_layout'] : 'portrait';
    $theme = is_string($_POST['opt_theme'] ?? null) && in_array($_POST['opt_theme'], ['school', 'blue', 'green', 'purple', 'red'], true)
        ? $_POST['opt_theme'] : 'school';
    $cardBorder = is_string($_POST['opt_card_border'] ?? null) && in_array($_POST['opt_card_border'], ['solid', 'double', 'rounded'], true)
        ? $_POST['opt_card_border'] : 'rounded';
    $options = [
        'layout'       => $layout,
        'theme'        => $theme,
        'show_photo'   => isset($_POST['opt_show_photo']),
        'show_code'    => isset($_POST['opt_show_code']),
        'show_class'   => isset($_POST['opt_show_class']),
        'show_barcode' => isset($_POST['opt_show_barcode']),
        'show_logo'    => isset($_POST['opt_show_logo']),
        'show_year'    => isset($_POST['opt_show_year']),
        'card_border'  => $cardBorder,
    ];

    if (!empty($selectedIds)) {
        $placeholders = implode(',', array_fill(0, count($selectedIds), '?'));
        $params = array_merge([$currentAcademicYearId], $selectedIds);

        $stmt = $db->prepare(
            "SELECT u.id, u.name,
                    sp.student_code, sp.national_id, sp.gender,
                    s.stage_name, g.grade_name, c.name AS class_name,
                    ay.name AS academic_year_name
             FROM users u
             LEFT JOIN student_profiles sp ON sp.user_id = u.id
             LEFT JOIN student_enrollments se
                   ON se.student_id = u.id AND se.academic_year_id = ?
             LEFT JOIN academic_years ay ON ay.id = se.academic_year_id
             LEFT JOIN stages s ON s.id = se.stage_id
             LEFT JOIN grades g ON g.id = se.grade_id
             LEFT JOIN classes c ON c.id = se.class_id
             WHERE u.id IN ($placeholders) AND u.role = 'student' AND u.deleted_at IS NULL"
        );
        $stmt->execute($params);
        $rawStudents = $stmt->fetchAll(PDO::FETCH_ASSOC);

        foreach ($rawStudents as $stu) {
            $uid = (int)$stu['id'];
            // جلب الصورة الشخصية
            $profileImageId = 0;
            if ($options['show_photo']) {
                $imgStmt = $db->prepare("SELECT id FROM student_attachments WHERE user_id = ? AND label = 'الصورة الشخصية' LIMIT 1");
                $imgStmt->execute([$uid]);
                $imgRow = $imgStmt->fetch(PDO::FETCH_ASSOC);
                $profileImageId = (int)($imgRow['id'] ?? 0);
            }
            $studentsData[] = array_merge($stu, ['_profile_image_id' => $profileImageId]);
        }
    }
} else {
    // الخيارات الافتراضية
    $options = [
        'layout'       => 'portrait',
        'theme'        => 'school',
        'show_photo'   => true,
        'show_code'    => true,
        'show_class'   => true,
        'show_barcode' => true,
        'show_logo'    => true,
        'show_year'    => true,
        'card_border'  => 'rounded',
    ];
}

require_once '../includes/admin_header.php';
?>

<!-- ألوان التيم المخصصة -->
<style>
:root {
    --color-school-primary: #32328c;
    --color-school-accent: #fa821e;
    
    --color-blue-primary: #1e3a8a;
    --color-blue-accent: #3b82f6;
    
    --color-green-primary: #065f46;
    --color-green-accent: #10b981;
    
    --color-purple-primary: #4c1d95;
    --color-purple-accent: #8b5cf6;
    
    --color-red-primary: #7f1d1d;
    --color-red-accent: #ef4444;
}

/* تنسيق لوحة التحكم بالفلاتر */
.sf-premium-card {
    border: 1px solid #e2e8f0 !important;
    border-radius: 12px !important;
    background-color: #ffffff !important;
    box-shadow: 0 4px 6px -1px rgba(0,0,0,0.05), 0 2px 4px -1px rgba(0,0,0,0.03) !important;
}
.sf-premium-header {
    background: #f8fafc !important;
    border-bottom: 2px solid var(--color-school-primary) !important;
    padding: 12px 18px !important;
}
.sf-checkbox-box {
    display: flex !important;
    align-items: center !important;
    gap: 12px !important;
    padding: 10px 16px !important;
    border-radius: 10px !important;
    background: #ffffff !important;
    border: 1px solid #cbd5e1 !important;
    transition: all 0.2s ease-in-out !important;
    cursor: pointer !important;
    margin-bottom: 8px !important;
    width: 100% !important;
    box-sizing: border-box !important;
}
.sf-checkbox-box:hover {
    background: #f8fafc !important;
    border-color: #94a3b8 !important;
}
.sf-checkbox-box:has(input:checked) {
    background: #eff6ff !important;
    border-color: #2563eb !important;
    box-shadow: 0 0 0 1px #2563eb !important;
}
.sf-checkbox-box .form-check-input {
    margin: 0 !important;
    float: none !important;
    width: 1.25rem !important;
    height: 1.25rem !important;
    border-radius: 4px !important;
    border: 2px solid #94a3b8 !important;
    cursor: pointer !important;
    flex-shrink: 0 !important;
    transition: all 0.2s !important;
}
.sf-checkbox-box .form-check-input:checked {
    border-color: #2563eb !important;
    background-color: #2563eb !important;
}
.sf-checkbox-box .form-check-label {
    margin: 0 !important;
    padding: 0 !important;
    cursor: pointer !important;
    font-weight: 700 !important;
    font-size: 0.95rem !important;
    color: #334155 !important;
    user-select: none !important;
    flex-grow: 1 !important;
    text-align: right !important;
}
.sf-checkbox-box:has(input:checked) .form-check-label {
    color: #1e3a8a !important;
}

/* تخطيط شبكة الكروت للمعاينة */
.id-cards-grid {
    display: grid;
    grid-template-columns: repeat(auto-fill, minmax(260px, 1fr));
    gap: 20px;
    padding: 20px 0;
}
.id-cards-grid.layout-landscape {
    grid-template-columns: repeat(auto-fill, minmax(360px, 1fr));
}

/* التصميم الأساسي للكرت */
.id-card {
    background: #fff;
    border-radius: 12px;
    box-shadow: 0 10px 20px rgba(0,0,0,0.08);
    position: relative;
    overflow: hidden;
    background-image: radial-gradient(circle at 100% 0%, rgba(200, 200, 200, 0.05) 0%, transparent 80%),
                      linear-gradient(to bottom, #ffffff 60%, #f8fafc 100%);
    box-sizing: border-box;
    display: flex;
    flex-direction: column;
    direction: rtl;
    text-align: right;
    transition: transform 0.2s ease;
}
.id-card:hover {
    transform: translateY(-3px);
}

/* الثيمات */
.id-card.theme-school { --primary: var(--color-school-primary); --accent: var(--color-school-accent); }
.id-card.theme-blue { --primary: var(--color-blue-primary); --accent: var(--color-blue-accent); }
.id-card.theme-green { --primary: var(--color-green-primary); --accent: var(--color-green-accent); }
.id-card.theme-purple { --primary: var(--color-purple-primary); --accent: var(--color-purple-accent); }
.id-card.theme-red { --primary: var(--color-red-primary); --accent: var(--color-red-accent); }

/* أشكال الحواف */
.id-card.border-rounded { border: 1px solid #e2e8f0; }
.id-card.border-solid { border: 3px solid var(--primary); }
.id-card.border-double { border: 5px double var(--primary); }

/* كرت طولي */
.id-card.card-portrait {
    width: 260px;
    height: 400px;
    margin: 0 auto;
}
/* كرت عرضي */
.id-card.card-landscape {
    width: 380px;
    height: 240px;
    margin: 0 auto;
}

/* ترويسة الكرت */
.id-card-header {
    background: linear-gradient(135deg, var(--primary) 0%, #1e1e5a 100%);
    color: #fff;
    padding: 10px 12px;
    display: flex;
    align-items: center;
    justify-content: space-between;
    border-bottom: 3px solid var(--accent);
    height: 52px;
}
.id-card-header .school-info {
    display: flex;
    flex-direction: column;
}
.id-card-header .school-name {
    font-size: 0.78rem;
    font-weight: 800;
    line-height: 1.2;
}
.id-card-header .card-tag {
    font-size: 0.62rem;
    opacity: 0.85;
    background: rgba(255,255,255,0.15);
    padding: 2px 6px;
    border-radius: 4px;
}
.id-card-header .school-logo-img {
    height: 32px;
    width: 32px;
    object-fit: contain;
    background: rgba(255,255,255,0.9);
    padding: 2px;
    border-radius: 50%;
}

/* جسم الكارت الموحد */
.id-card-body {
    padding: 10px 14px;
    display: flex;
    flex-direction: column;
    flex-grow: 1;
    justify-content: flex-start;
    box-sizing: border-box;
}

/* صف الاسم العلوي في المنتصف */
.id-card-name-row {
    width: 100%;
    text-align: center;
    margin-bottom: 8px;
    border-bottom: 1px dashed rgba(0,0,0,0.1);
    padding-bottom: 6px;
}
.id-card-name-row .student-name {
    font-weight: 800;
    color: var(--primary);
    margin: 0;
    line-height: 1.2;
}
.id-card.card-portrait .id-card-name-row .student-name {
    font-size: 1.15rem;
}
.id-card.card-landscape .id-card-name-row .student-name {
    font-size: 1.3rem;
}

/* تخطيط المحتوى السفلي */
.id-card-content-layout {
    display: flex;
    width: 100%;
    align-items: center;
    box-sizing: border-box;
}

/* تخصيص المحتوى للطولي */
.id-card.card-portrait .id-card-content-layout {
    flex-direction: column;
    justify-content: space-between;
    align-items: center;
    flex-grow: 1;
}
.id-card.card-portrait .photo-container {
    width: 95px;
    height: 95px;
    border-radius: 12px;
    overflow: hidden;
    border: 3px solid #fff;
    box-shadow: 0 4px 8px rgba(0,0,0,0.1);
    background: #f8fafc;
    display: flex;
    align-items: center;
    justify-content: center;
    margin-bottom: 6px;
}
.id-card.card-portrait .photo-container img {
    width: 100%;
    height: 100%;
    object-fit: cover;
}
.id-card.card-portrait .photo-placeholder {
    color: #cbd5e1;
}
.id-card.card-portrait .student-details {
    width: 100%;
    text-align: center;
    margin-bottom: 4px;
}
.id-card.card-portrait .detail-row {
    font-size: 0.95rem; /* حجم خط افتراضي متوازن للطولي */
    color: #334155;
    margin-bottom: 3px;
    font-weight: 700;
}
.id-card.card-portrait .detail-label {
    font-weight: 800;
    color: var(--primary);
    margin-left: 2px;
}
.id-card.card-portrait .detail-val {
    font-weight: 700;
    color: #0f172a;
}
.id-card.card-portrait .barcode-area {
    display: flex;
    flex-direction: column;
    align-items: center;
    justify-content: center;
    margin-top: auto;
    width: 100%;
}
.id-card.card-portrait .barcode-svg {
    max-width: 100%;
    height: 35px;
}

/* تخصيص المحتوى للعرضي باستخدام CSS Grid لتكديس الصورة والباركود يميناً */
.id-card.card-landscape .id-card-content-layout {
    display: grid;
    grid-template-columns: 1fr 110px; /* التفاصيل يساراً (1fr)، والصورة والباركود يميناً (110px) */
    grid-template-rows: auto auto;
    align-items: center;
    gap: 4px 10px;
    flex-grow: 1;
    width: 100%;
}
.id-card.card-landscape .photo-container {
    grid-column: 2;
    grid-row: 1;
    justify-self: center;
    width: 80px;
    height: 80px;
    border-radius: 10px;
    overflow: hidden;
    border: 3px solid #fff;
    box-shadow: 0 3px 8px rgba(0,0,0,0.1);
    background: #f8fafc;
    display: flex;
    align-items: center;
    justify-content: center;
    margin: 0;
}
.id-card.card-landscape .photo-container img {
    width: 100%;
    height: 100%;
    object-fit: cover;
}
.id-card.card-landscape .photo-placeholder {
    color: #cbd5e1;
}
.id-card.card-landscape .student-details {
    grid-column: 1;
    grid-row: 1 / span 2;
    display: flex;
    flex-direction: column;
    justify-content: center;
    text-align: right;
    padding-left: 10px;
}
.id-card.card-landscape .detail-row {
    font-size: 1.05rem; /* حجم خط افتراضي متوازن للعرضي */
    color: #334155;
    margin-bottom: 2px;
    line-height: 1.3;
    font-weight: 700;
}
.id-card.card-landscape .detail-label {
    font-weight: 800;
    color: var(--primary);
    margin-left: 4px;
}
.id-card.card-landscape .detail-val {
    font-weight: 700;
    color: #0f172a;
}
.id-card.card-landscape .barcode-area {
    grid-column: 2;
    grid-row: 2;
    justify-self: center;
    width: 105px;
    display: flex;
    flex-direction: column;
    align-items: center;
    justify-content: center;
    margin: 0;
}
.id-card.card-landscape .barcode-svg {
    width: 100%;
    height: 32px;
}

/* زر تحميل الكارت كصورة */
.download-card-btn {
    position: absolute;
    top: 8px;
    left: 8px;
    z-index: 99;
    opacity: 0.5;
    transition: all 0.2s ease-in-out;
    background: rgba(255, 255, 255, 0.95) !important;
    border: 1px solid #cbd5e1 !important;
    padding: 3px 6px !important;
    border-radius: 4px !important;
    box-shadow: 0 2px 5px rgba(0,0,0,0.1);
    cursor: pointer;
}
.download-card-btn:hover {
    opacity: 1 !important;
    background: #ffffff !important;
    border-color: var(--primary) !important;
    transform: scale(1.08);
}
.download-card-btn i {
    font-size: 0.78rem !important;
}

/* إخفاء القوائم وأمور الطباعة */
@media print {
    @page {
        size: A4 portrait;
        margin: 8mm;
    }
    .no-print, .admin-sidebar, .admin-header, nav, footer, .sf-control-panel, .page-header-bar {
        display: none !important;
    }
    main {
        margin: 0 !important;
        padding: 0 !important;
    }
    body {
        background: #fff !important;
    }
    .id-cards-grid {
        display: grid !important;
        grid-template-columns: repeat(3, 1fr) !important;
        gap: 12px !important;
        padding: 0 !important;
    }
    .id-cards-grid.layout-landscape {
        grid-template-columns: repeat(2, 1fr) !important;
        gap: 15px !important;
    }
    .id-card {
        box-shadow: none !important;
        border: 1px solid #cbd5e1 !important;
        page-break-inside: avoid !important;
        break-inside: avoid !important;
        -webkit-print-color-adjust: exact;
        print-color-adjust: exact;
    }
    .id-card-header {
        -webkit-print-color-adjust: exact;
        print-color-adjust: exact;
    }
}

/* تنسيق فلتر الطلاب المنسدل */
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
</style>

<!-- ===== رأس الصفحة ===== -->
<div class="d-flex justify-content-between flex-wrap flex-md-nowrap align-items-center pt-3 pb-2 mb-4 border-bottom no-print">
    <div>
        <h1 class="h2 fw-bold text-dark"><i class="fas fa-id-card me-3 text-primary"></i>كروت الطلاب (ID)</h1>
        <p class="text-muted m-0">توليد وطباعة الكروت التعريفية الشخصية للطلاب بشكل جماعي أو فردي</p>
    </div>
    <div class="admin-top-actions no-print">
        <?php if (!empty($studentsData)): ?>
        <button type="button" id="downloadAllBtn" class="btn btn-header-premium btn-import-soft me-2">
            <i class="fas fa-file-download me-1"></i>تحميل الكل كصور
        </button>
        <button type="button" onclick="window.print()" class="btn btn-header-premium btn-print-soft">
            <i class="fas fa-print me-1"></i>طباعة الكروت
        </button>
        <?php endif; ?>
    </div>
</div>

<!-- ===== لوحة التحكم والفلاتر ===== -->
<div class="sf-control-panel no-print mb-4">
    <form method="POST" id="idCardForm">
        <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($_SESSION['csrf_token'] ?? '', ENT_QUOTES, 'UTF-8'); ?>">
        
        <!-- بار التصفية (Filters Bar) -->
        <div class="admin-filter-bar mb-3">
            <div class="admin-filter-controls">
                <!-- Dropdown المراحل -->
                <div class="dropdown d-inline-block">
                    <button class="btn btn-light dropdown-toggle btn-sm filter-dropdown-btn" type="button" id="stageDropdown" data-bs-toggle="dropdown" data-bs-auto-close="outside" aria-expanded="false" style="background: white; border-color: #dee2e6; color: #495057; height: 31px; display: inline-flex; align-items: center; justify-content: space-between; min-width: 140px;">
                        <span>المراحل: <span id="selectedStagesLabel" class="fw-bold">الكل</span></span>
                    </button>
                    <div class="dropdown-menu p-3" aria-labelledby="stageDropdown" style="max-height: 250px; overflow-y: auto; min-width: 200px; text-align: right;">
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
                    <div class="dropdown-menu p-3" aria-labelledby="gradeDropdown" style="max-height: 250px; overflow-y: auto; min-width: 220px; text-align: right;">
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
                    <div class="dropdown-menu p-3" aria-labelledby="classDropdown" style="max-height: 250px; overflow-y: auto; min-width: 220px; text-align: right;">
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
                        <span>الطلاب: <span id="selectedStudentsLabel" class="fw-bold">لا يوجد</span></span>
                    </button>
                    <div class="dropdown-menu p-3" aria-labelledby="studentDropdown" style="max-height: 300px; overflow-y: auto; min-width: 280px; text-align: right;">
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
                <button type="button" class="btn btn-light btn-sm" data-bs-toggle="modal" data-bs-target="#idCardSettingsModal">
                    <i class="fas fa-sliders-h me-1"></i>تخصيص وإعدادات الكارت
                </button>
                <button type="submit" class="btn btn-primary btn-sm px-3" style="color: #ffffff !important; padding: var(--btn-padding-y-sm) var(--btn-padding-x-sm) !important; font-size: var(--btn-font-size-sm) !important;">
                    <i class="fas fa-id-card me-1"></i>توليد الكروت
                </button>
            </div>
        </div>

        <!-- ===== Modal إعدادات وتخصيص كروت الـ ID ===== -->
        <div class="modal fade" id="idCardSettingsModal" tabindex="-1" aria-labelledby="idCardSettingsModalLabel" aria-hidden="true">
            <div class="modal-dialog modal-dialog-centered">
                <div class="modal-content admin-modal admin-modal-premium admin-modal-view">
                    <div class="modal-header">
                        <h5 class="modal-title" id="idCardSettingsModalLabel">
                            <i class="fas fa-sliders-h me-2"></i>إعدادات وتخصيص كروت الـ ID
                        </h5>
                        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="إغلاق"></button>
                    </div>
                    <div class="modal-body p-4">
                        <!-- اتجاه الكارت -->
                        <div class="mb-3">
                            <label class="form-label fw-bold small text-muted d-block">تخطيط الكارت (طولي / عرضي)</label>
                            <div class="btn-group w-100" role="group">
                                <input type="radio" class="btn-check" name="opt_layout" id="lay_portrait" value="portrait" <?php echo $options['layout'] === 'portrait' ? 'checked' : ''; ?>>
                                <label class="btn btn-outline-primary btn-sm" for="lay_portrait"><i class="fas fa-portrait me-1"></i>طولي (Portrait)</label>
                                
                                <input type="radio" class="btn-check" name="opt_layout" id="lay_landscape" value="landscape" <?php echo $options['layout'] === 'landscape' ? 'checked' : ''; ?>>
                                <label class="btn btn-outline-primary btn-sm" for="lay_landscape"><i class="fas fa-image me-1"></i>عرضي (Landscape)</label>
                            </div>
                        </div>

                        <!-- ثيم الكارت -->
                        <div class="mb-3">
                            <label class="form-label fw-bold small text-muted">لون ثيم الكارت</label>
                            <select class="form-select form-select-sm" name="opt_theme">
                                <option value="school" <?php echo $options['theme'] === 'school' ? 'selected' : ''; ?>>كحلي / برتقالي (ثيم المدرسة)</option>
                                <option value="blue" <?php echo $options['theme'] === 'blue' ? 'selected' : ''; ?>>أزرق ملكي</option>
                                <option value="green" <?php echo $options['theme'] === 'green' ? 'selected' : ''; ?>>أخضر زمردي</option>
                                <option value="purple" <?php echo $options['theme'] === 'purple' ? 'selected' : ''; ?>>بنفسجي داكن</option>
                                <option value="red" <?php echo $options['theme'] === 'red' ? 'selected' : ''; ?>>أحمر غامق</option>
                            </select>
                        </div>

                        <!-- إطار الكارت -->
                        <div class="mb-3">
                            <label class="form-label fw-bold small text-muted">شكل حواف الكارت</label>
                            <select class="form-select form-select-sm" name="opt_card_border">
                                <option value="rounded" <?php echo $options['card_border'] === 'rounded' ? 'selected' : ''; ?>>حواف دائرية أنيقة</option>
                                <option value="solid" <?php echo $options['card_border'] === 'solid' ? 'selected' : ''; ?>>إطار ملون سميك</option>
                                <option value="double" <?php echo $options['card_border'] === 'double' ? 'selected' : ''; ?>>إطار كلاسيكي مزدوج</option>
                            </select>
                        </div>

                        <!-- خيارات العرض داخل الكارت -->
                        <div class="mb-2">
                            <label class="form-label fw-bold small text-muted">عناصر الكارت المُراد عرضها</label>
                            <div class="row row-cols-2 g-2">
                                <div class="col">
                                    <div class="sf-checkbox-box">
                                        <input class="form-check-input" type="checkbox" name="opt_show_photo" id="show_photo" value="1" <?php echo $options['show_photo'] ? 'checked' : ''; ?>>
                                        <label class="form-check-label" for="show_photo">الصورة الشخصية</label>
                                    </div>
                                </div>
                                <div class="col">
                                    <div class="sf-checkbox-box">
                                        <input class="form-check-input" type="checkbox" name="opt_show_code" id="show_code" value="1" <?php echo $options['show_code'] ? 'checked' : ''; ?>>
                                        <label class="form-check-label" for="show_code">كود الطالب</label>
                                    </div>
                                </div>
                                <div class="col">
                                    <div class="sf-checkbox-box">
                                        <input class="form-check-input" type="checkbox" name="opt_show_class" id="show_class" value="1" <?php echo $options['show_class'] ? 'checked' : ''; ?>>
                                        <label class="form-check-label" for="show_class">الصف والفصل</label>
                                    </div>
                                </div>
                                <div class="col">
                                    <div class="sf-checkbox-box">
                                        <input class="form-check-input" type="checkbox" name="opt_show_barcode" id="show_barcode" value="1" <?php echo $options['show_barcode'] ? 'checked' : ''; ?>>
                                        <label class="form-check-label" for="show_barcode">الباركود التفاعلي</label>
                                    </div>
                                </div>
                                <div class="col">
                                    <div class="sf-checkbox-box">
                                        <input class="form-check-input" type="checkbox" name="opt_show_logo" id="show_logo" value="1" <?php echo $options['show_logo'] ? 'checked' : ''; ?>>
                                        <label class="form-check-label" for="show_logo">شعار المدرسة</label>
                                    </div>
                                </div>
                                <div class="col">
                                    <div class="sf-checkbox-box">
                                        <input class="form-check-input" type="checkbox" name="opt_show_year" id="show_year" value="1" <?php echo $options['show_year'] ? 'checked' : ''; ?>>
                                        <label class="form-check-label" for="show_year">العام الدراسي</label>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary btn-sm" data-bs-dismiss="modal">
                            <i class="fas fa-times me-1"></i>إغلاق
                        </button>
                        <button type="submit" class="btn btn-primary btn-sm">
                            <i class="fas fa-save me-1"></i>حفظ وتطبيق
                        </button>
                    </div>
                </div>
            </div>
        </div>
    </form>
</div>

<!-- ===== منطقة عرض ومعاينة كروت الـ ID ===== -->
<div id="printArea" class="<?php echo $options['layout'] === 'landscape' ? 'layout-landscape' : ''; ?>">
    <?php if (!empty($studentsData)): ?>
        <div class="id-cards-grid <?php echo $options['layout'] === 'landscape' ? 'layout-landscape' : ''; ?>">
            <?php foreach ($studentsData as $stu): 
                $layoutClass = $options['layout'] === 'landscape' ? 'card-landscape' : 'card-portrait';
                $themeClass = 'theme-' . $options['theme'];
                $borderClass = 'border-' . $options['card_border'];
            ?>
                <div class="id-card <?php echo $layoutClass; ?> <?php echo $themeClass; ?> <?php echo $borderClass; ?>">
                    <!-- زر تحميل الكارت كصورة -->
                    <button class="btn btn-sm btn-icon download-card-btn no-print" data-html2canvas-ignore="true" data-name="<?php echo htmlspecialchars($stu['name'], ENT_QUOTES, 'UTF-8'); ?>" title="تحميل كصورة">
                        <i class="fas fa-download text-muted"></i>
                    </button>
                    <!-- ترويسة الكارت -->
                    <div class="id-card-header">
                        <div class="school-info">
                            <span class="school-name"><?php echo htmlspecialchars($schoolName, ENT_QUOTES, 'UTF-8'); ?></span>
                            <span class="card-tag">بطاقة تعريفية</span>
                        </div>
                        <?php if ($options['show_logo'] && $logoPath): ?>
                            <img src="<?php echo $logoPath; ?>" class="school-logo-img" alt="شعار المدرسة">
                        <?php endif; ?>
                    </div>
                    
                    <!-- جسم الكارت -->
                    <div class="id-card-body">
                        <!-- صف الاسم العلوي منفصل وفي المنتصف -->
                        <div class="id-card-name-row">
                            <div class="student-name"><?php echo htmlspecialchars($stu['name'], ENT_QUOTES, 'UTF-8'); ?></div>
                        </div>
                        
                        <!-- التخطيط السفلي للمحتوى -->
                        <div class="id-card-content-layout">
                            <!-- 1. الصورة الشخصية (أولاً من اليمين في RTL) -->
                            <?php if ($options['show_photo']): ?>
                                <div class="photo-container">
                                    <?php if (!empty($stu['_profile_image_id'])): ?>
                                        <img src="<?php echo htmlspecialchars(ProfileAttachmentStorage::adminDownloadUrl('student', (int)$stu['_profile_image_id']), ENT_QUOTES, 'UTF-8'); ?>" alt="صورة الطالب">
                                    <?php else: ?>
                                        <div class="photo-placeholder"><i class="fas fa-user fa-3x"></i></div>
                                    <?php endif; ?>
                                </div>
                            <?php endif; ?>
                            
                            <!-- 2. تفاصيل الطالب (في المنتصف) -->
                            <div class="student-details">
                                <?php if ($options['show_class']): ?>
                                    <div class="detail-row">
                                        <span class="detail-label">المرحلة:</span> 
                                        <span class="detail-val"><?php echo htmlspecialchars($stu['stage_name'] ?? '—', ENT_QUOTES, 'UTF-8'); ?></span>
                                    </div>
                                    <div class="detail-row">
                                        <span class="detail-label">الصف:</span> 
                                        <span class="detail-val"><?php echo htmlspecialchars($stu['grade_name'] ?? '—', ENT_QUOTES, 'UTF-8'); ?></span>
                                    </div>
                                    <div class="detail-row">
                                        <span class="detail-label">الفصل:</span> 
                                        <span class="detail-val"><?php echo htmlspecialchars($stu['class_name'] ?? '—', ENT_QUOTES, 'UTF-8'); ?></span>
                                    </div>
                                <?php endif; ?>
                                
                                <?php if ($options['show_code'] && !empty($stu['student_code'])): ?>
                                    <div class="detail-row">
                                        <span class="detail-label">كود الطالب:</span> 
                                        <span class="detail-val fw-bold"><?php echo htmlspecialchars($stu['student_code'], ENT_QUOTES, 'UTF-8'); ?></span>
                                    </div>
                                <?php endif; ?>
                                
                                <?php if ($options['show_year']): ?>
                                    <div class="detail-row text-muted" style="font-size: 0.72rem; margin-top: 4px;">
                                        <span class="detail-label text-muted">العام الدراسي:</span>
                                        <span class="detail-val text-muted"><?php echo htmlspecialchars($stu['academic_year_name'] ?? '—', ENT_QUOTES, 'UTF-8'); ?></span>
                                    </div>
                                <?php endif; ?>
                            </div>
                            
                            <!-- 3. باركود الهوية (يسار في RTL) -->
                            <?php if ($options['show_barcode'] && !empty($stu['student_code'])): ?>
                                <div class="barcode-area">
                                    <svg class="barcode-svg" data-value="<?php echo htmlspecialchars($stu['student_code'], ENT_QUOTES, 'UTF-8'); ?>"></svg>
                                </div>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>
            <?php endforeach; ?>
        </div>
    <?php elseif ($_SERVER['REQUEST_METHOD'] === 'POST'): ?>
        <div class="alert alert-warning no-print">
            <i class="fas fa-exclamation-triangle me-2"></i>
            يرجى اختيار طالب واحد على الأقل وتوليد الكروت.
        </div>
    <?php else: ?>
        <div class="alert alert-info no-print">
            <i class="fas fa-info-circle me-2"></i>
            اختر الطلاب المطلوبين من لوحة التصفية أعلاه، ثم اضغط على زر <strong>توليد الكروت</strong> لمعاينتها وطباعتها.
        </div>
    <?php endif; ?>
</div>

<!-- تضمين مكتبة JsBarcode و html2canvas و JSZip لعمل الكروت وضغطها وتحميلها تفاعلياً -->
<script src="https://cdn.jsdelivr.net/npm/jsbarcode@3.11.5/dist/JsBarcode.all.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/html2canvas@1.4.1/dist/html2canvas.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/jszip@3.10.1/dist/jszip.min.js"></script>
<script>
document.addEventListener("DOMContentLoaded", function() {
    // توليد الباركود
    generateBarcodes();
    // ضبط حجم خط الاسم ديناميكياً ليناسب سطر واحد
    adjustNameFontSizes();
    // ضبط حجم خط البيانات الداخلية ديناميكياً ليناسب سطر واحد
    adjustDetailsFontSizes();
    // ربط أزرار تحميل الكروت كصور فردية
    initDownloadCardHandlers();
    // ربط زر تحميل جميع الكروت كصور دفعة واحدة
    initDownloadAllBtn();

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
    const formEl = document.getElementById('idCardForm');
    if (formEl) {
        formEl.addEventListener('submit', function(e) {
            const checkedStudents = document.querySelectorAll('.student-checkbox:checked');
            if (checkedStudents.length === 0) {
                alert('يرجى اختيار طالب واحد على الأقل لتوليد كروت الـ ID.');
                e.preventDefault();
            }
        });
    }
});

function generateBarcodes() {
    document.querySelectorAll(".barcode-svg").forEach(svg => {
        const val = svg.getAttribute("data-value");
        if (val) {
            try {
                JsBarcode(svg, val, {
                    format: "CODE128",
                    width: 1.25,
                    height: 35,
                    displayValue: false,
                    margin: 0
                });
            } catch(e) {
                console.error("Barcode generation failed for:", val, e);
            }
        }
    });
}

function adjustNameFontSizes() {
    document.querySelectorAll('.id-card-name-row').forEach(row => {
        const nameEl = row.querySelector('.student-name');
        if (!nameEl) return;
        
        // إعادة تهيئة حجم الخط الافتراضي قبل الحساب لتجنب التراكم
        nameEl.style.fontSize = '';
        nameEl.style.whiteSpace = 'nowrap';
        nameEl.style.display = 'inline-block';
        
        const maxWidth = row.clientWidth - 12; // 12px margin safety
        let fontSize = parseFloat(window.getComputedStyle(nameEl).fontSize);
        
        let attempts = 0;
        // تقليص حجم الخط ديناميكياً حتى يتناسب الاسم مع العرض المتاح للكرت في سطر واحد
        while (nameEl.offsetWidth > maxWidth && fontSize > 9 && attempts < 30) {
            fontSize -= 0.5;
            nameEl.style.fontSize = fontSize + 'px';
            attempts++;
        }
        
        nameEl.style.display = ''; // استعادة النمط الأصلي
    });
}

function adjustDetailsFontSizes() {
    document.querySelectorAll('.id-card').forEach(card => {
        const isLandscape = card.classList.contains('card-landscape');
        
        // جلب حاوية الجسم لمعرفة العرض الحقيقي الثابت للكرت
        const bodyEl = card.querySelector('.id-card-body');
        if (!bodyEl) return;
        const bodyWidth = bodyEl.clientWidth;
        
        // حساب العرض المتاح للبيانات بدون تداخل
        let maxWidth = bodyWidth;
        if (isLandscape) {
            // في الكرت العرضي، العمود الأيمن للصورة والباركود هو 110px والمسافة 10px
            maxWidth = bodyWidth - 110 - 10;
        }
        
        maxWidth -= 8; // مسافة أمان إضافية للهوامش
        if (maxWidth <= 0) return;
        
        const detailsCol = card.querySelector('.student-details');
        if (!detailsCol) return;
        
        detailsCol.querySelectorAll('.detail-row').forEach(row => {
            row.style.fontSize = ''; // إعادة تعيين حجم الخط الافتراضي
            row.style.whiteSpace = 'nowrap';
            row.style.display = 'inline-block';
            
            let fontSize = parseFloat(window.getComputedStyle(row).fontSize);
            let attempts = 0;
            
            // تقليص حجم خط صف التفاصيل ديناميكياً ليناسب العرض المتاح للعمود بدون تأثير حلقي
            while (row.offsetWidth > maxWidth && fontSize > 9 && attempts < 30) {
                fontSize -= 0.5;
                row.style.fontSize = fontSize + 'px';
                attempts++;
            }
            
            row.style.display = ''; // استعادة النمط الأصلي
        });
    });
}

function initDownloadCardHandlers() {
    document.querySelectorAll('.download-card-btn').forEach(btn => {
        btn.addEventListener('click', function(e) {
            e.preventDefault();
            e.stopPropagation();
            
            const cardEl = this.closest('.id-card');
            if (!cardEl) return;
            
            const studentName = this.getAttribute('data-name') || 'Student';
            
            // مؤشر انتظار تفاعلي
            const originalInner = this.innerHTML;
            this.innerHTML = '<i class="fas fa-spinner fa-spin text-primary"></i>';
            this.style.pointerEvents = 'none';
            
            html2canvas(cardEl, {
                scale: 2, // استخدام مقياس 2 يوفر دقة عالية ويوفر 60% من استهلاك الذاكرة والمعالجة مقارنة بمقياس 3
                useCORS: true,
                backgroundColor: null,
                logging: false
            }).then(canvas => {
                const link = document.createElement('a');
                link.download = `كارت_طالب_${studentName.trim().replace(/\s+/g, '_')}.png`;
                link.href = canvas.toDataURL('image/png');
                link.click();
                
                // استعادة حالة الزر
                this.innerHTML = originalInner;
                this.style.pointerEvents = '';
            }).catch(err => {
                console.error('فشل تحويل الكارت لصورة:', err);
                alert('حدث خطأ أثناء تحميل الكارت كصورة.');
                this.innerHTML = originalInner;
                this.style.pointerEvents = '';
            });
        });
    });
}

function initDownloadAllBtn() {
    const downloadAllBtn = document.getElementById('downloadAllBtn');
    if (!downloadAllBtn) return;
    
    downloadAllBtn.addEventListener('click', async function(e) {
        e.preventDefault();
        
        const cards = document.querySelectorAll('.id-card');
        if (cards.length === 0) {
            alert('لا توجد كروت معروضة لتحميلها.');
            return;
        }
        
        // تغيير مظهر الزر لتنبيه المستخدم ببدء معالجة الضغط والتحميل الجماعي
        const originalInner = this.innerHTML;
        this.innerHTML = '<i class="fas fa-spinner fa-spin me-1"></i>جاري البدء...';
        this.style.pointerEvents = 'none';
        this.classList.remove('btn-import-soft');
        this.classList.add('btn-primary');
        
        // إنشاء أرشيف مضغوط جديد
        const zip = new JSZip();
        const folder = zip.folder("student_id_cards");
        
        for (let i = 0; i < cards.length; i++) {
            const cardEl = cards[i];
            const downloadBtn = cardEl.querySelector('.download-card-btn');
            const studentName = downloadBtn ? downloadBtn.getAttribute('data-name') : `طالب_${i + 1}`;
            
            // تحديث نص التقدم على الزر الرئيسي فورياً
            this.innerHTML = `<i class="fas fa-spinner fa-spin me-1"></i>جاري معالجة الكروت (${i + 1} / ${cards.length})...`;
            
            // تغيير حالة المؤشر على الكارت نفسه لإعلام المستخدم
            const tempOriginal = downloadBtn ? downloadBtn.innerHTML : '';
            if (downloadBtn) {
                downloadBtn.innerHTML = '<i class="fas fa-spinner fa-spin text-primary"></i>';
            }
            
            // إعطاء فرصة للمتصفح لتحديث واجهة المستخدم (تحديث الأيقونة الدوارة والنصوص) لمنع تجمد الصفحة
            await new Promise(resolveYield => setTimeout(resolveYield, 40));
            
            await new Promise((resolve) => {
                html2canvas(cardEl, {
                    scale: 2, // مقياس 2 بدلاً من 3 لسرعة فائقة وذاكرة مستقرة
                    useCORS: true,
                    backgroundColor: null,
                    logging: false
                }).then(canvas => {
                    // استخراج البيانات الثنائية للصورة بصيغة base64
                    const imgData = canvas.toDataURL('image/png').split(',')[1];
                    const fileName = `كارت_طالب_${studentName.trim().replace(/\s+/g, '_')}.png`;
                    
                    // إضافة الصورة للأرشيف المضغوط
                    folder.file(fileName, imgData, {base64: true});
                    
                    if (downloadBtn) {
                        downloadBtn.innerHTML = tempOriginal;
                    }
                    resolve();
                }).catch(err => {
                    console.error('فشل تحويل الكارت كصورة:', studentName, err);
                    if (downloadBtn) {
                        downloadBtn.innerHTML = tempOriginal;
                    }
                    resolve();
                });
            });
        }
        
        // توليد ملف الـ ZIP وتحميله دفعة واحدة
        this.innerHTML = '<i class="fas fa-file-archive me-1"></i>جاري توليد ملف الـ ZIP...';
        
        // إعطاء فرصة للمتصفح لتحديث النص قبل عملية الضغط الكثيفة
        await new Promise(resolveYield => setTimeout(resolveYield, 40));
        
        try {
            const content = await zip.generateAsync({type: "blob"});
            const link = document.createElement('a');
            const today = new Date().toISOString().slice(0, 10);
            link.download = `كروت_الطلاب_${today}.zip`;
            link.href = URL.createObjectURL(content);
            link.click();
        } catch (zipErr) {
            console.error('فشل توليد ملف ZIP:', zipErr);
            alert('حدث خطأ أثناء تجميع الكروت في ملف مضغوط.');
        }
        
        // استعادة حالة الزر الأصلية
        this.innerHTML = originalInner;
        this.style.pointerEvents = '';
        this.classList.remove('btn-primary');
        this.classList.add('btn-import-soft');
    });
}

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

    // تصفية وإخفاء/إظهار الطلاب بناءً على الفلاتر النشطة
    document.querySelectorAll('.student-item').forEach(item => {
        const stageId = item.getAttribute('data-stage');
        const gradeId = item.getAttribute('data-grade');
        const classId = item.getAttribute('data-class');

        const matchStg = checkedStages.length === 0 || checkedStages.includes(stageId);
        const matchGrd = checkedGrades.length === 0 || checkedGrades.includes(gradeId);
        const matchCls = checkedClasses.length === 0 || checkedClasses.includes(classId);

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
    window.location.href = 'student_id_cards.php';
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
</script>

<?php require_once '../includes/admin_footer.php'; ?>
