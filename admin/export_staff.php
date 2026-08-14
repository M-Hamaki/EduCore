<?php
/**
 * صفحة تصدير وطباعة بيانات الموظفين
 * تتيح اختيار الحقول والفلاتر ثم التصدير إلى Excel أو PDF أو الطباعة
 */
$page_title = "تصدير بيانات الموظفين";
$custom_page_title = true;

require_once '../config/database.php';
require_once '../classes/user.php';
require_once '../classes/classroom.php';
require_once '../classes/utilities.php';
require_once '../classes/excel_handler.php';
require_once '../classes/ActivityLog.php';
require_once '../classes/StaffEmploymentLifecycleService.php';
require_once '../includes/session_config.php';
require_once '../includes/csrf.php';
Utilities::validateSession('admin');

$database = new Database();
$db = $database->getConnection();
$user = new User($db);
$class = new ClassRoom($db);
$excel_handler = new ExcelHandler();

// تسميات عربية
$genderLabels = ['male' => 'ذكر', 'female' => 'أنثى'];
$religionLabels = ['muslim' => 'مسلم', 'christian' => 'مسيحي', 'other' => 'أخرى'];
$maritalLabels = ['single' => 'أعزب', 'married' => 'متزوج', 'divorced' => 'مطلق', 'widowed' => 'أرمل'];
$contractLabels = ['permanent' => 'دائم', 'temporary' => 'مؤقت', 'parttime' => 'جزئي'];
$roleLabels = ['teacher' => 'معلم', 'specialist' => 'أخصائي'];
try {
    $roleTableExists = (int)$db->query("SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'staff_roles'")->fetchColumn() > 0;
    if ($roleTableExists) {
        $customRoleRows = $db->query("SELECT role_key, role_name FROM staff_roles WHERE status = 'active' ORDER BY role_name")->fetchAll(PDO::FETCH_ASSOC);
        foreach ($customRoleRows as $roleRow) {
            $roleLabels[$roleRow['role_key']] = $roleRow['role_name'];
        }
    }
} catch (Throwable $e) {
    error_log('Export staff custom roles load failed: ' . $e->getMessage());
}

$staffBaseWhere = "sp.user_id IS NOT NULL AND (u.role IS NULL OR u.role NOT IN ('admin','student'))";
$departments = $db->query("SELECT DISTINCT sp.department
    FROM staff_profiles sp
    JOIN users u ON u.id = sp.user_id
    WHERE {$staffBaseWhere}
      AND sp.department IS NOT NULL
      AND TRIM(sp.department) <> ''
    ORDER BY sp.department")->fetchAll(PDO::FETCH_COLUMN);

// جلب الفصول والمراحل
$allClasses = $class->readAll();
$stages = $db->query("SELECT id, stage_name FROM stages ORDER BY id")->fetchAll(PDO::FETCH_ASSOC);
$subjects = $db->query("SELECT id, name FROM subjects ORDER BY name")->fetchAll(PDO::FETCH_ASSOC);

// جلب قائمة الموظفين للفلترة بالاسم
$staffList = $db->query("SELECT u.id, COALESCE(sp.full_name_ar, u.name) as display_name,
        COALESCE((SELECT GROUP_CONCAT(ura.role_key ORDER BY ura.is_primary DESC, ura.role_key SEPARATOR ',')
                  FROM user_role_assignments ura WHERE ura.user_id = u.id AND ura.status = 'active'), '') AS role,
        u.status
    FROM users u
    JOIN staff_profiles sp ON u.id = sp.user_id
    WHERE {$staffBaseWhere}
    ORDER BY display_name")->fetchAll(PDO::FETCH_ASSOC);

// تعريف كل الحقول المتاحة
$allFields = [
    'employee_code'    => 'كود الموظف',
    'full_name_ar'     => 'الاسم بالعربية',
    'full_name_en'     => 'الاسم بالإنجليزية',
    'username'         => 'اسم المستخدم',
    'role'             => 'الدور',
    'national_id'      => 'الرقم القومي',
    'birth_date'       => 'تاريخ الميلاد',
    'gender'           => 'النوع',
    'religion'         => 'الديانة',
    'nationality'      => 'الجنسية',
    'marital_status'   => 'الحالة الاجتماعية',
    'military_status'  => 'الموقف من التجنيد للذكور',
    'public_service_status' => 'الموقف من الخدمة العامة للإناث',
    'number_of_children'=> 'عدد الأبناء',
    'blood_type'       => 'فصيلة الدم',
    'phone_mobile'     => 'رقم الموبايل',
    'phone_home'       => 'رقم المنزل',
    'phone_emergency'  => 'رقم الطوارئ',
    'emergency_contact_name' => 'اسم جهة الطوارئ',
    'email_personal'   => 'البريد الإلكتروني',
    'address_detail'   => 'العنوان التفصيلي',
    'city_area'        => 'المدينة / المنطقة',
    'hire_date'        => 'تاريخ التعيين',
    'job_title'        => 'المسمى الوظيفي',
    'department'       => 'القسم',
    'job_grade'        => 'الدرجة الوظيفية',
    'contract_type'    => 'نوع التعاقد',
    'contract_start'   => 'بداية التعاقد',
    'contract_end'     => 'نهاية التعاقد',
    'qualification'    => 'المؤهل',
    'qualification_year'=> 'سنة التخرج',
    'qualification_university'=> 'الجامعة',
    'specialization'   => 'التخصص',
    'years_of_experience'=> 'سنوات الخبرة',
    'promotions'       => 'الترقيات والتدرج الوظيفي',
    'status_history'   => 'سجل حالات الموظف',
    'insurance_number' => 'رقم التأمين',
    'basic_salary'     => 'الراتب الأساسي',
    'allowance_transport'=> 'بدل انتقال',
    'allowance_housing'=> 'بدل سكن',
    'deduction_insurance'=> 'استقطاع تأمينات',
    'deduction_tax'    => 'استقطاع ضرائب',
    'net_salary'       => 'صافي المرتب',
    'status'           => 'الحالة',
];

// ===== معالجة طلب التصدير =====
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['do_export'])) {
    // حماية CSRF — التصدير يكشف بيانات حساسة فيجب أن يكون محمياً من تزوير الطلبات
    requireCsrfPost();

    // بناء الاستعلام بناءً على الفلاتر
    $where = [$staffBaseWhere];
    $params = [];

    if (!empty($_POST['filter_role'])) {
        $role = $_POST['filter_role'];
        $where[] = "EXISTS (SELECT 1 FROM user_role_assignments ura_filter WHERE ura_filter.user_id = u.id AND ura_filter.role_key = ? AND ura_filter.status = 'active')";
        $params[] = $role;
    }
    if (!empty($_POST['filter_status'])) {
        $where[] = "u.status = ?";
        $params[] = $_POST['filter_status'];
    }
    if (!empty($_POST['filter_department'])) {
        $where[] = "sp.department = ?";
        $params[] = $_POST['filter_department'];
    }
    if (!empty($_POST['filter_gender'])) {
        $where[] = "sp.gender = ?";
        $params[] = $_POST['filter_gender'];
    }
    if (!empty($_POST['filter_contract_type'])) {
        $where[] = "sp.contract_type = ?";
        $params[] = $_POST['filter_contract_type'];
    }
    if (!empty($_POST['filter_qualification'])) {
        $where[] = "sp.qualification LIKE ?";
        $params[] = '%' . $_POST['filter_qualification'] . '%';
    }
    if (!empty($_POST['filter_hire_date_from'])) {
        $where[] = "sp.hire_date >= ?";
        $params[] = $_POST['filter_hire_date_from'];
    }
    if (!empty($_POST['filter_hire_date_to'])) {
        $where[] = "sp.hire_date <= ?";
        $params[] = $_POST['filter_hire_date_to'];
    }
    if (!empty($_POST['filter_staff_ids']) && is_array($_POST['filter_staff_ids'])) {
        $ids = array_map('intval', $_POST['filter_staff_ids']);
        $ids = array_filter($ids, function($v) { return $v > 0; });
        if (!empty($ids)) {
            $placeholders = implode(',', array_fill(0, count($ids), '?'));
            $where[] = "u.id IN ($placeholders)";
            $params = array_merge($params, $ids);
        }
    }

    $whereSQL = implode(' AND ', $where);

    $query = "SELECT u.id, u.name, u.username,
                     COALESCE((SELECT GROUP_CONCAT(ura.role_key ORDER BY ura.is_primary DESC, ura.role_key SEPARATOR ',')
                               FROM user_role_assignments ura WHERE ura.user_id = u.id AND ura.status = 'active'), '') AS role,
                     u.status,
                     sp.employee_code, sp.full_name_ar, sp.full_name_en, sp.national_id,
                     sp.birth_date, sp.gender, sp.religion, sp.nationality,
                     sp.address_detail, sp.city_area, sp.phone_mobile, sp.phone_home, sp.phone_emergency,
                     sp.emergency_contact_name, sp.email_personal, sp.marital_status, sp.military_status, sp.public_service_status,
                     sp.number_of_children, sp.blood_type,
                     sp.hire_date, sp.job_title, sp.department, sp.job_grade,
                     sp.contract_type, sp.contract_start, sp.contract_end,
                     sp.qualification, sp.qualification_year, sp.qualification_university,
                     sp.specialization, sp.years_of_experience, sp.promotions,
                     sp.basic_salary, sp.allowance_transport, sp.allowance_housing,
                     sp.deduction_insurance, sp.deduction_tax, sp.net_salary,
                     sp.insurance_number
              FROM users u
              JOIN staff_profiles sp ON u.id = sp.user_id
              WHERE $whereSQL
              GROUP BY u.id
              ORDER BY u.name";

    $stmt = $db->prepare($query);
    $stmt->execute($params);
    $staffData = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // الحقول المختارة
    $selectedFields = isset($_POST['fields']) && is_array($_POST['fields']) ? array_values(array_intersect($_POST['fields'], array_keys($allFields))) : [];
    $exportFormat = $_POST['export_format'] ?? 'preview';

    // تنسيق قيم العرض
    function formatStaffValue($field, $value, $genderLabels, $religionLabels, $maritalLabels, $contractLabels, $roleLabels) {
        if ($value === null || $value === '') return '-';
        switch ($field) {
            case 'gender': return $genderLabels[$value] ?? $value;
            case 'religion': return $religionLabels[$value] ?? $value;
            case 'marital_status': return $maritalLabels[$value] ?? $value;
            case 'contract_type': return $contractLabels[$value] ?? $value;
            case 'job_title': return StaffEmploymentLifecycleService::canonicalJobTitle($value) ?? '-';
            case 'role':
                $roleKeys = array_filter(array_map('trim', explode(',', (string)$value)));
                return implode('، ', array_map(static fn(string $key): string => $roleLabels[$key] ?? $key, $roleKeys));
            case 'status': return $value === 'active' ? 'نشط' : 'معطل';
            case 'basic_salary': case 'allowance_transport': case 'allowance_housing':
            case 'deduction_insurance': case 'deduction_tax': case 'net_salary':
                return number_format((float)$value, 2);
            default: return $value;
        }
    }

    // إذا كان التصدير Excel
    if ($exportFormat === 'excel' && !empty($selectedFields)) {
        ob_clean();
        $data = [];
        $headers = [];
        foreach ($selectedFields as $f) {
            if (isset($allFields[$f])) $headers[] = $allFields[$f];
        }
        $data[] = $headers;
        foreach ($staffData as $s) {
            $row = [];
            foreach ($selectedFields as $f) {
                $val = $s[$f] ?? '';
                $row[] = formatStaffValue($f, $val, $genderLabels, $religionLabels, $maritalLabels, $contractLabels, $roleLabels);
            }
            $data[] = $row;
        }
        $filepath = $excel_handler->exportToExcel($data, 'تقرير_الموظفين');
        if ($filepath && file_exists($filepath)) {
            // تسجيل عملية التصدير للمراجعة الأمنية (كشف بيانات حساسة)
            ActivityLog::log('export', 'staff', null, 'تصدير بيانات الموظفين', [
                'format' => 'excel_csv',
                'record_count' => max(0, count($staffData)),
                'fields' => array_values($selectedFields),
            ]);
            header('Content-Type: text/csv; charset=utf-8');
            header('Content-Disposition: attachment; filename="تقرير_الموظفين_' . date('Y-m-d') . '.csv"');
            header('Content-Length: ' . filesize($filepath));
            header('Cache-Control: must-revalidate');
            readfile($filepath);
            unlink($filepath);
            exit;
        }
    }

    // إذا كان التصدير PDF
    if ($exportFormat === 'pdf' && !empty($selectedFields)) {
        // سنعرض الجدول مع أمر الطباعة كـ PDF
        $showPrintView = true;
        // تسجيل عملية التصدير للمراجعة الأمنية (كشف بيانات حساسة)
        ActivityLog::log('export', 'staff', null, 'تصدير بيانات الموظفين', [
            'format' => 'pdf_print',
            'record_count' => max(0, count($staffData)),
            'fields' => array_values($selectedFields),
        ]);
    }
}

// جلب إحصائيات سريعة
$staffCounts = $db->query("SELECT 
    COUNT(*) as total,
    SUM(CASE WHEN EXISTS (SELECT 1 FROM user_role_assignments ura_t WHERE ura_t.user_id = u.id AND ura_t.role_key = 'teacher' AND ura_t.status = 'active') THEN 1 ELSE 0 END) as teachers,
    SUM(CASE WHEN EXISTS (SELECT 1 FROM user_role_assignments ura_s WHERE ura_s.user_id = u.id AND ura_s.role_key = 'specialist' AND ura_s.status = 'active') THEN 1 ELSE 0 END) as specialists,
    SUM(CASE WHEN u.status='active' THEN 1 ELSE 0 END) as active_count,
    SUM(CASE WHEN u.status!='active' THEN 1 ELSE 0 END) as inactive_count
    FROM users u
    JOIN staff_profiles sp ON sp.user_id = u.id
    WHERE {$staffBaseWhere}")->fetch(PDO::FETCH_ASSOC);

require_once '../includes/admin_header.php';
?>

<style>
.field-group { border: 1px solid #dee2e6; border-radius: 8px; padding: 15px; margin-bottom: 15px; background: #f8f9fa; }
.field-group h6 { color: #495057; margin-bottom: 12px; border-bottom: 2px solid #0d6efd; padding-bottom: 6px; }
.field-checkbox { display: inline-flex; align-items: center; min-width: 200px; padding: 4px 8px; margin: 2px; border-radius: 4px; transition: background 0.2s; }
.field-checkbox:hover { background: #e2e6ea; }
.field-checkbox input { margin-left: 6px; }
.filter-card { border-radius: 10px; border: 1px solid #dee2e6; }
.export-actions { position: sticky; top: 70px; z-index: 100; }
.preview-table { font-size: 0.85rem; }
.preview-table th { background: #0d6efd; color: white; white-space: nowrap; position: sticky; top: 0; }
.preview-table td { white-space: nowrap; }
.group-toggle { cursor: pointer; user-select: none; }
.group-toggle:hover { color: #0d6efd; }
@media print {
    .no-print, .sidebar, .navbar, .btn-toolbar, .filter-section, .export-actions-bar { display: none !important; }
    .main-content { margin: 0 !important; padding: 0 !important; }
    .preview-table { font-size: 11px !important; }
    .preview-table th { background: #333 !important; color: white !important; -webkit-print-color-adjust: exact; print-color-adjust: exact; }
    @page { size: landscape; margin: 10mm; }
    .container-fluid { padding: 0 !important; }
    body { direction: rtl; }
}
</style>

<!-- Page Header -->
<div class="d-flex justify-content-between flex-wrap flex-md-nowrap align-items-center pt-3 pb-2 mb-3 border-bottom">
    <h1 class="h2"><i class="fas fa-file-export me-2"></i>تصدير وطباعة بيانات الموظفين</h1>
    <div class="btn-toolbar mb-2 mb-md-0 gap-2">
        <a href="staff.php" class="btn btn-sm btn-secondary">
            <i class="fas fa-arrow-right me-1"></i>رجوع لإدارة الموظفين
        </a>
    </div>
</div>

<!-- إحصائيات سريعة -->
<div class="row row-cols-2 row-cols-md-4 g-3 mb-4 no-print">
    <div class="col">
        <div class="stat-card" style="--card-gradient: linear-gradient(135deg, #3b82f6, #2563eb);">
            <div class="stat-card-icon"><i class="fas fa-users"></i></div>
            <div class="stat-card-info">
                <div class="stat-card-number counter" data-target="<?php echo (int)$staffCounts['total']; ?>">0</div>
                <div class="stat-card-label">إجمالي الموظفين</div>
            </div>
        </div>
    </div>
    <div class="col">
        <div class="stat-card" style="--card-gradient: linear-gradient(135deg, #10b981, #059669);">
            <div class="stat-card-icon"><i class="fas fa-chalkboard-teacher"></i></div>
            <div class="stat-card-info">
                <div class="stat-card-number counter" data-target="<?php echo (int)$staffCounts['teachers']; ?>">0</div>
                <div class="stat-card-label">المعلمون</div>
            </div>
        </div>
    </div>
    <div class="col">
        <div class="stat-card" style="--card-gradient: linear-gradient(135deg, #8b5cf6, #7c3aed);">
            <div class="stat-card-icon"><i class="fas fa-user-tie"></i></div>
            <div class="stat-card-info">
                <div class="stat-card-number counter" data-target="<?php echo (int)$staffCounts['specialists']; ?>">0</div>
                <div class="stat-card-label">الأخصائيون</div>
            </div>
        </div>
    </div>
    <div class="col">
        <div class="stat-card" style="--card-gradient: linear-gradient(135deg, #f59e0b, #d97706);">
            <div class="stat-card-icon"><i class="fas fa-check-circle"></i></div>
            <div class="stat-card-info">
                <div class="stat-card-number counter" data-target="<?php echo (int)$staffCounts['active_count']; ?>">0</div>
                <div class="stat-card-label">نشط</div>
            </div>
        </div>
    </div>
</div>

<form method="POST" action="" id="exportForm">
    <?php echo csrfField(); ?>

<!-- قسم الفلاتر -->
<div class="card filter-card mb-4 no-print filter-section">
    <div class="card-header">
        <h5 class="mb-0"><i class="fas fa-filter me-2"></i>الفلاتر</h5>
    </div>
    <div class="card-body">
        <div class="row g-3">
            <div class="col-md-3">
                <label class="form-label fw-bold">الدور</label>
                <select name="filter_role" class="form-select">
                    <option value="">الكل</option>
                    <?php foreach ($roleLabels as $roleKey => $roleLabel): ?>
                        <option value="<?php echo htmlspecialchars($roleKey, ENT_QUOTES, 'UTF-8'); ?>" <?php echo (isset($_POST['filter_role']) && $_POST['filter_role'] === $roleKey) ? 'selected' : ''; ?>>
                            <?php echo htmlspecialchars($roleLabel, ENT_QUOTES, 'UTF-8'); ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-md-3">
                <label class="form-label fw-bold">الحالة</label>
                <select name="filter_status" class="form-select">
                    <option value="">الكل</option>
                    <option value="active" <?php echo (isset($_POST['filter_status']) && $_POST['filter_status']==='active') ? 'selected' : ''; ?>>نشط</option>
                    <option value="inactive" <?php echo (isset($_POST['filter_status']) && $_POST['filter_status']==='inactive') ? 'selected' : ''; ?>>معطل</option>
                </select>
            </div>
            <div class="col-md-3">
                <label class="form-label fw-bold">القسم</label>
                <select name="filter_department" class="form-select">
                    <option value="">الكل</option>
                    <?php foreach ($departments as $dept): ?>
                        <option value="<?php echo htmlspecialchars($dept, ENT_QUOTES, 'UTF-8'); ?>" <?php echo (isset($_POST['filter_department']) && $_POST['filter_department']===$dept) ? 'selected' : ''; ?>>
                            <?php echo htmlspecialchars($dept, ENT_QUOTES, 'UTF-8'); ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-md-3">
                <label class="form-label fw-bold">النوع</label>
                <select name="filter_gender" class="form-select">
                    <option value="">الكل</option>
                    <option value="male" <?php echo (isset($_POST['filter_gender']) && $_POST['filter_gender']==='male') ? 'selected' : ''; ?>>ذكر</option>
                    <option value="female" <?php echo (isset($_POST['filter_gender']) && $_POST['filter_gender']==='female') ? 'selected' : ''; ?>>أنثى</option>
                </select>
            </div>
            <div class="col-md-3">
                <label class="form-label fw-bold">نوع التعاقد</label>
                <select name="filter_contract_type" class="form-select">
                    <option value="">الكل</option>
                    <option value="permanent" <?php echo (isset($_POST['filter_contract_type']) && $_POST['filter_contract_type']==='permanent') ? 'selected' : ''; ?>>دائم</option>
                    <option value="temporary" <?php echo (isset($_POST['filter_contract_type']) && $_POST['filter_contract_type']==='temporary') ? 'selected' : ''; ?>>مؤقت</option>
                    <option value="parttime" <?php echo (isset($_POST['filter_contract_type']) && $_POST['filter_contract_type']==='parttime') ? 'selected' : ''; ?>>جزئي</option>
                </select>
            </div>
            <div class="col-md-3">
                <label class="form-label fw-bold">المؤهل (بحث)</label>
                <input type="text" name="filter_qualification" class="form-control" placeholder="مثال: بكالوريوس" value="<?php echo htmlspecialchars($_POST['filter_qualification'] ?? '', ENT_QUOTES, 'UTF-8'); ?>">
            </div>
            <div class="col-md-3">
                <label class="form-label fw-bold">تاريخ التعيين من</label>
                <input type="text" name="filter_hire_date_from" class="form-control flatpickr-date" value="<?php echo htmlspecialchars($_POST['filter_hire_date_from'] ?? '', ENT_QUOTES, 'UTF-8'); ?>">
            </div>
            <div class="col-md-3">
                <label class="form-label fw-bold">تاريخ التعيين إلى</label>
                <input type="text" name="filter_hire_date_to" class="form-control flatpickr-date" value="<?php echo htmlspecialchars($_POST['filter_hire_date_to'] ?? '', ENT_QUOTES, 'UTF-8'); ?>">
            </div>
            <div class="col-md-6">
                <label class="form-label fw-bold"><i class="fas fa-user-check me-1"></i>اختيار موظفين بعينهم <span class="text-muted fw-normal">(<?php echo count($staffList); ?> موظف)</span></label>
                <select name="filter_staff_ids[]" class="form-select" id="staffPersonSelect" multiple size="8" style="min-height: 200px;">
                    <?php foreach ($staffList as $s): 
                        $statusLabel = ($s['status'] === 'active') ? '' : ' [معطل]';
                        $staffRoleKeys = array_filter(array_map('trim', explode(',', (string)$s['role'])));
                        $roleName = $staffRoleKeys === []
                            ? 'بدون دور'
                            : implode('، ', array_map(static fn(string $key): string => $roleLabels[$key] ?? $key, $staffRoleKeys));
                    ?>
                        <option value="<?php echo $s['id']; ?>" <?php echo (isset($_POST['filter_staff_ids']) && in_array($s['id'], $_POST['filter_staff_ids'])) ? 'selected' : ''; ?>>
                            <?php echo htmlspecialchars($s['display_name'], ENT_QUOTES, 'UTF-8'); ?> (<?php echo $roleName; ?>)<?php echo $statusLabel; ?>
                        </option>
                    <?php endforeach; ?>
                </select>
                <div class="form-text">اترك فارغاً لتصدير الكل. استخدم Ctrl+Click لاختيار أكثر من شخص.</div>
                <input type="text" id="staffSearchInput" class="form-control form-control-sm mt-1" placeholder="ابحث بالاسم لتصفية القائمة...">
            </div>
            </div>
        </div>
    </div>
</div>

<!-- قسم اختيار الحقول -->
<div class="card filter-card mb-4 no-print">
    <div class="card-header d-flex justify-content-between align-items-center">
        <h5 class="mb-0"><i class="fas fa-columns me-2"></i>اختيار الحقول للتصدير</h5>
        <div>
            <button type="button" class="btn btn-sm btn-light me-1" id="selectAll"><i class="fas fa-check-double me-1"></i>تحديد الكل</button>
            <button type="button" class="btn btn-sm btn-light me-1" id="deselectAll"><i class="fas fa-times me-1"></i>إلغاء الكل</button>
            <button type="button" class="btn btn-sm btn-light" id="selectBasic"><i class="fas fa-star me-1"></i>البيانات الأساسية</button>
        </div>
    </div>
    <div class="card-body">
        <!-- البيانات الأساسية -->
        <div class="field-group">
            <h6 class="group-toggle" data-target="basic-fields"><i class="fas fa-user me-1"></i>البيانات الأساسية <i class="fas fa-chevron-down float-start"></i></h6>
            <div id="basic-fields">
                <?php 
                $basicFields = ['employee_code','full_name_ar','full_name_en','username','role','national_id','status'];
                foreach ($basicFields as $f): 
                    $checked = isset($_POST['fields']) ? (in_array($f, $_POST['fields']) ? 'checked' : '') : (in_array($f, ['employee_code','full_name_ar','role','status']) ? 'checked' : '');
                ?>
                <label class="field-checkbox">
                    <input type="checkbox" name="fields[]" value="<?php echo $f; ?>" <?php echo $checked; ?>>
                    <?php echo $allFields[$f] ?? $f; ?>
                </label>
                <?php endforeach; ?>
            </div>
        </div>

        <!-- البيانات الشخصية -->
        <div class="field-group">
            <h6 class="group-toggle" data-target="personal-fields"><i class="fas fa-id-card me-1"></i>البيانات الشخصية <i class="fas fa-chevron-down float-start"></i></h6>
            <div id="personal-fields">
                <?php 
                $personalFields = ['birth_date','gender','religion','nationality','marital_status','military_status','public_service_status','number_of_children','blood_type'];
                foreach ($personalFields as $f): 
                    $checked = isset($_POST['fields']) ? (in_array($f, $_POST['fields']) ? 'checked' : '') : '';
                ?>
                <label class="field-checkbox">
                    <input type="checkbox" name="fields[]" value="<?php echo $f; ?>" <?php echo $checked; ?>>
                    <?php echo $allFields[$f]; ?>
                </label>
                <?php endforeach; ?>
            </div>
        </div>

        <!-- البيانات التواصل -->
        <div class="field-group">
            <h6 class="group-toggle" data-target="contact-fields"><i class="fas fa-phone me-1"></i>بيانات التواصل <i class="fas fa-chevron-down float-start"></i></h6>
            <div id="contact-fields">
                <?php 
                $contactFields = ['phone_mobile','phone_home','phone_emergency','emergency_contact_name','email_personal','address_detail','city_area'];
                foreach ($contactFields as $f): 
                    $checked = isset($_POST['fields']) ? (in_array($f, $_POST['fields']) ? 'checked' : '') : '';
                ?>
                <label class="field-checkbox">
                    <input type="checkbox" name="fields[]" value="<?php echo $f; ?>" <?php echo $checked; ?>>
                    <?php echo $allFields[$f]; ?>
                </label>
                <?php endforeach; ?>
            </div>
        </div>

        <!-- البيانات الوظيفية -->
        <div class="field-group">
            <h6 class="group-toggle" data-target="job-fields"><i class="fas fa-briefcase me-1"></i>البيانات الوظيفية <i class="fas fa-chevron-down float-start"></i></h6>
            <div id="job-fields">
                <?php 
                $jobFields = ['hire_date','job_title','department','job_grade','contract_type','contract_start','contract_end','promotions'];
                foreach ($jobFields as $f): 
                    $checked = isset($_POST['fields']) ? (in_array($f, $_POST['fields']) ? 'checked' : '') : '';
                ?>
                <label class="field-checkbox">
                    <input type="checkbox" name="fields[]" value="<?php echo $f; ?>" <?php echo $checked; ?>>
                    <?php echo $allFields[$f]; ?>
                </label>
                <?php endforeach; ?>
            </div>
        </div>

        <!-- المؤهلات -->
        <div class="field-group">
            <h6 class="group-toggle" data-target="qual-fields"><i class="fas fa-graduation-cap me-1"></i>المؤهلات والخبرات <i class="fas fa-chevron-down float-start"></i></h6>
            <div id="qual-fields">
                <?php 
                $qualFields = ['qualification','qualification_year','qualification_university','specialization','years_of_experience','insurance_number'];
                foreach ($qualFields as $f): 
                    $checked = isset($_POST['fields']) ? (in_array($f, $_POST['fields']) ? 'checked' : '') : '';
                ?>
                <label class="field-checkbox">
                    <input type="checkbox" name="fields[]" value="<?php echo $f; ?>" <?php echo $checked; ?>>
                    <?php echo $allFields[$f]; ?>
                </label>
                <?php endforeach; ?>
            </div>
        </div>

        <!-- البيانات المالية -->
        <div class="field-group">
            <h6 class="group-toggle" data-target="finance-fields"><i class="fas fa-money-bill-wave me-1"></i>البيانات المالية <i class="fas fa-chevron-down float-start"></i></h6>
            <div id="finance-fields">
                <?php 
                $financeFields = ['basic_salary','allowance_transport','allowance_housing','deduction_insurance','deduction_tax','net_salary'];
                foreach ($financeFields as $f): 
                    $checked = isset($_POST['fields']) ? (in_array($f, $_POST['fields']) ? 'checked' : '') : '';
                ?>
                <label class="field-checkbox">
                    <input type="checkbox" name="fields[]" value="<?php echo $f; ?>" <?php echo $checked; ?>>
                    <?php echo $allFields[$f]; ?>
                </label>
                <?php endforeach; ?>
            </div>
        </div>
    </div>
</div>

<!-- أزرار التصدير -->
<div class="card mb-4 no-print export-actions-bar">
    <div class="card-body d-flex flex-wrap gap-2 justify-content-center align-items-center">
        <button type="submit" name="do_export" value="1" class="btn btn-primary" onclick="document.getElementById('exportFormatInput').value='preview'">
            <i class="fas fa-eye me-1"></i>معاينة البيانات
        </button>
        <button type="submit" name="do_export" value="1" class="btn btn-success" onclick="document.getElementById('exportFormatInput').value='excel'">
            <i class="fas fa-file-excel me-1"></i>تصدير Excel
        </button>
        <button type="submit" name="do_export" value="1" class="btn btn-danger" onclick="document.getElementById('exportFormatInput').value='pdf'">
            <i class="fas fa-file-pdf me-1"></i>تصدير PDF
        </button>
        <button type="button" class="btn btn-info text-white" id="printPreviewBtn" <?php echo empty($staffData ?? []) ? 'disabled' : ''; ?> onclick="window.print()">
            <i class="fas fa-print me-1"></i>طباعة
        </button>
        <input type="hidden" name="export_format" id="exportFormatInput" value="preview">
    </div>
</div>

</form>

<!-- عرض النتائج / المعاينة -->
<?php if (isset($staffData) && !empty($selectedFields)): ?>
<div class="card mb-4">
    <div class="card-header bg-primary text-white d-flex justify-content-between align-items-center">
        <h5 class="mb-0"><i class="fas fa-table me-2"></i>نتائج التصدير (<?php echo count($staffData); ?> موظف)</h5>
        <span class="badge bg-light text-dark"><?php echo count($selectedFields); ?> حقل محدد</span>
    </div>
    <div class="card-body p-0">
        <div class="table-responsive" style="max-height: 600px; overflow-y: auto;">
            <table class="table table-bordered table-striped table-hover preview-table mb-0" id="previewTable">
                <thead>
                    <tr>
                        <th>#</th>
                        <?php foreach ($selectedFields as $f): ?>
                            <th><?php echo htmlspecialchars($allFields[$f] ?? $f, ENT_QUOTES, 'UTF-8'); ?></th>
                        <?php endforeach; ?>
                    </tr>
                </thead>
                <tbody>
                    <?php $counter = 0; foreach ($staffData as $s): $counter++; ?>
                    <tr>
                        <td><?php echo $counter; ?></td>
                        <?php foreach ($selectedFields as $f): ?>
                            <td><?php echo htmlspecialchars(formatStaffValue($f, $s[$f] ?? '', $genderLabels, $religionLabels, $maritalLabels, $contractLabels, $roleLabels), ENT_QUOTES, 'UTF-8'); ?></td>
                        <?php endforeach; ?>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>
<?php elseif (isset($staffData) && empty($selectedFields)): ?>
<div class="alert alert-warning no-print">
    <i class="fas fa-exclamation-triangle me-2"></i>الرجاء اختيار حقل واحد على الأقل للتصدير.
</div>
<?php endif; ?>

<?php if (isset($showPrintView) && $showPrintView): ?>
<script>
document.addEventListener('DOMContentLoaded', function() {
    setTimeout(function() { window.print(); }, 500);
});
</script>
<?php endif; ?>

<script>
document.addEventListener('DOMContentLoaded', function() {
    // تحديد الكل
    document.getElementById('selectAll').addEventListener('click', function() {
        document.querySelectorAll('input[name="fields[]"]').forEach(cb => cb.checked = true);
    });
    // إلغاء الكل
    document.getElementById('deselectAll').addEventListener('click', function() {
        document.querySelectorAll('input[name="fields[]"]').forEach(cb => cb.checked = false);
    });
    // البيانات الأساسية فقط
    document.getElementById('selectBasic').addEventListener('click', function() {
        var basic = ['employee_code','full_name_ar','username','role','phone_mobile','job_title','department','status'];
        document.querySelectorAll('input[name="fields[]"]').forEach(function(cb) {
            cb.checked = basic.includes(cb.value);
        });
    });
    // طي/فتح المجموعات
    document.querySelectorAll('.group-toggle').forEach(function(el) {
        el.addEventListener('click', function() {
            var target = document.getElementById(this.getAttribute('data-target'));
            if (target) {
                target.style.display = target.style.display === 'none' ? '' : 'none';
                var icon = this.querySelector('.float-start');
                if (icon) icon.classList.toggle('fa-chevron-up');
                if (icon) icon.classList.toggle('fa-chevron-down');
            }
        });
    });
    // بحث في قائمة الموظفين
    var staffSearch = document.getElementById('staffSearchInput');
    var staffSelect = document.getElementById('staffPersonSelect');
    if (staffSearch && staffSelect) {
        staffSearch.addEventListener('input', function() {
            var term = this.value.toLowerCase();
            Array.from(staffSelect.options).forEach(function(opt) {
                opt.style.display = opt.text.toLowerCase().includes(term) ? '' : 'none';
            });
        });
    }
});
</script>

<?php
require_once '../includes/admin_footer.php';
?>
