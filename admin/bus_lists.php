<?php
/**
 * قوائم الحافلات - Bus Lists
 * Displays details of a selected bus (driver, supervisor) and its assigned students.
 */
$page_title = "قوائم الحافلات";
$custom_page_title = true;

require_once '../config/database.php';
require_once '../classes/utilities.php';
require_once '../classes/AcademicYear.php';
require_once '../includes/session_config.php';
Utilities::validateSession('admin');

$database = new Database();
$db = $database->getConnection();
$db->exec("SET NAMES 'utf8mb4'");

$currentAcademicYearId = AcademicYear::currentId($db);
$academicYearName = '';
if ($currentAcademicYearId > 0) {
    $academicYearName = $db->query("SELECT name FROM academic_years WHERE id = {$currentAcademicYearId}")->fetchColumn() ?: '';
}

// =============== RETRIEVE SCHOOL SETTINGS ===============
$settings = $db->query("SELECT setting_key, setting_value FROM settings")->fetchAll(PDO::FETCH_KEY_PAIR) ?: [];
$schoolLogo = $settings['school_logo'] ?? '';
$schoolName = $settings['school_name'] ?? '';
$transportOfficer = $settings['transport_movement_officer'] ?? '';
$adminDirector = $settings['admin_director'] ?? '';

$logoPath = '';
if ($schoolLogo && file_exists(__DIR__ . '/../uploads/' . $schoolLogo)) {
    $logoPath = '../uploads/' . $schoolLogo;
} elseif (file_exists(__DIR__ . '/../assets/img/logo.png')) {
    $logoPath = '../assets/img/logo.png';
}

// =============== JOIN FOR ACTIVE YEAR ===============
$enrollJoin = $currentAcademicYearId > 0
    ? "JOIN student_enrollments se ON se.student_id = u.id AND se.academic_year_id = {$currentAcademicYearId} AND se.enrollment_status = 'enrolled'
       LEFT JOIN classes c ON c.id = se.class_id"
    : "LEFT JOIN classes c ON u.class_id = c.id";

// Parse selected bus ID (support "all" which maps to -1)
$selectedBusId = 0;
if (isset($_GET['bus_id'])) {
    if ($_GET['bus_id'] === 'all') {
        $selectedBusId = -1;
    } else {
        $selectedBusId = (int)$_GET['bus_id'];
    }
}

// =============== STATISTICS FOR STAT CARDS ===============
$totalBuses = $db->query("SELECT COUNT(*) FROM buses")->fetchColumn();
$activeBusesCount = $db->query("SELECT COUNT(*) FROM buses WHERE status = 'active'")->fetchColumn();
$totalCapacity = $db->query("SELECT SUM(capacity) FROM buses WHERE status = 'active'")->fetchColumn() ?: 0;

$assignedSql = "SELECT COUNT(DISTINCT sba.student_id) 
    FROM student_bus_assignments sba 
    JOIN users u ON u.id = sba.student_id 
    {$enrollJoin} 
    WHERE u.status = 'active' AND u.deleted_at IS NULL";
if ($currentAcademicYearId > 0) {
    $assignedSql .= " AND sba.academic_year_id = {$currentAcademicYearId}";
}
$totalAssignedStudents = $db->query($assignedSql)->fetchColumn();
$generalOccupancyRate = $totalCapacity > 0 ? round(($totalAssignedStudents / $totalCapacity) * 100) : 0;

// =============== BUS DATA PREPARATION ===============
// Fetch all active buses with their drivers and supervisors
$activeBusesDetails = $db->query("SELECT b.id, b.bus_number, b.area, b.capacity,
    (SELECT GROUP_CONCAT(name SEPARATOR '، ') FROM bus_staff WHERE bus_id = b.id AND role = 'driver') as driver_name,
    (SELECT GROUP_CONCAT(phones SEPARATOR '، ') FROM bus_staff WHERE bus_id = b.id AND role = 'driver') as driver_phone,
    (SELECT GROUP_CONCAT(name SEPARATOR '، ') FROM bus_staff WHERE bus_id = b.id AND role = 'supervisor') as supervisor_name,
    (SELECT GROUP_CONCAT(phones SEPARATOR '، ') FROM bus_staff WHERE bus_id = b.id AND role = 'supervisor') as supervisor_phone
    FROM buses b WHERE b.status = 'active' ORDER BY b.bus_number")->fetchAll(PDO::FETCH_ASSOC);

// Map dropdown buses list
$busesList = [];
foreach ($activeBusesDetails as $b) {
    $busesList[] = [
        'id' => $b['id'],
        'bus_number' => $b['bus_number'],
        'area' => $b['area']
    ];
}

$busesToDisplay = [];
$studentsByBus = [];

if ($selectedBusId > 0 || $selectedBusId === -1) {
    // Determine which buses to load
    if ($selectedBusId === -1) {
        $busesToDisplay = $activeBusesDetails;
    } else {
        foreach ($activeBusesDetails as $b) {
            if ((int)$b['id'] === $selectedBusId) {
                $busesToDisplay[] = $b;
                break;
            }
        }
    }

    if (!empty($busesToDisplay)) {
        // Fetch all assigned students for the query in one go
        $studentsSql = "SELECT u.id, u.name as student_name, c.name as class_name, g.grade_name,
            sba.notes as bus_notes, sba.bus_id, sba.backup_bus_id,
            (SELECT sg.phone_primary FROM student_guardians sg WHERE sg.student_id = u.id AND sg.relationship = 'father' LIMIT 1) AS father_phone,
            (SELECT sg.phone_primary FROM student_guardians sg WHERE sg.student_id = u.id AND sg.relationship = 'mother' LIMIT 1) AS mother_phone,
            sp.phone_emergency AS student_emergency_phone
            FROM student_bus_assignments sba
            JOIN users u ON sba.student_id = u.id
            {$enrollJoin}
            LEFT JOIN grades g ON c.grade_id = g.id
            LEFT JOIN student_profiles sp ON u.id = sp.user_id
            WHERE u.status = 'active' AND u.deleted_at IS NULL
              AND (sba.bus_id IS NOT NULL OR sba.backup_bus_id IS NOT NULL)" . ($currentAcademicYearId > 0 ? " AND sba.academic_year_id = {$currentAcademicYearId}" : "") . "
            ORDER BY u.name";
        $stmtSt = $db->query($studentsSql);
        $allStudents = $stmtSt->fetchAll(PDO::FETCH_ASSOC);

        // Group students by bus ID
        foreach ($allStudents as $student) {
            if ($student['bus_id']) {
                $studentsByBus[$student['bus_id']][] = $student;
            }
            if ($student['backup_bus_id'] && $student['backup_bus_id'] != $student['bus_id']) {
                $studentsByBus[$student['backup_bus_id']][] = $student;
            }
        }
    }
}

// =============== EXCEL EXPORT (BEFORE HEADERS) ===============
if (isset($_GET['export']) && $_GET['export'] === 'excel' && !empty($busesToDisplay)) {
    require_once '../classes/excel_handler.php';
    $excel_handler = new ExcelHandler();
    
    $excelData = [];
    $excelData[] = ['كشف ركوب الطلاب بالحافلات المدرسية'];
    if ($academicYearName) {
        $excelData[] = ['العام الدراسي:', $academicYearName];
    }
    $excelData[] = []; // Spacer row

    foreach ($busesToDisplay as $bus) {
        $busId = $bus['id'];
        $busStudents = $studentsByBus[$busId] ?? [];
        
        $excelData[] = ['بيانات الحافلة رقم:', $bus['bus_number']];
        $excelData[] = ['المنطقة الجغرافية:', $bus['area'] ?: '—'];
        $excelData[] = ['السائق:', $bus['driver_name'] ?: '—', 'رقم هاتف السائق:', $bus['driver_phone'] ?: '—'];
        $excelData[] = ['المشرف:', $bus['supervisor_name'] ?: '—', 'رقم هاتف المشرف:', $bus['supervisor_phone'] ?: '—'];
        
        // Table Headers
        $excelData[] = ['#', 'اسم الطالب', 'الصف', 'الفصل', 'رقم موبايل الأب', 'رقم موبايل الأم', 'رقم الطوارئ', 'ملاحظات'];
        
        if (empty($busStudents)) {
            $excelData[] = ['لا يوجد طلاب مقيدين بهذه الحافلة حالياً'];
        } else {
            $idx = 0;
            foreach ($busStudents as $student) {
                $idx++;
                $excelData[] = [
                    $idx,
                    $student['student_name'],
                    $student['grade_name'] ?: '—',
                    $student['class_name'] ?: '—',
                    $student['father_phone'] ?: '—',
                    $student['mother_phone'] ?: '—',
                    $student['student_emergency_phone'] ?: '—',
                    $student['bus_notes'] ?: '—'
                ];
            }
        }
        $excelData[] = []; // Spacers between groups
        $excelData[] = [];
    }

    $filename = ($selectedBusId === -1) ? 'كشوفات_كافة_الحافلات' : 'كشف_ركوب_حافلة_' . $busesToDisplay[0]['bus_number'];
    $filepath = $excel_handler->exportToExcel($excelData, $filename);
    
    if ($filepath && file_exists($filepath)) {
        if (ob_get_level() > 0) ob_clean();
        $ext = pathinfo($filepath, PATHINFO_EXTENSION);
        if ($ext === 'xlsx') {
            header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
            header('Content-Disposition: attachment; filename="' . $filename . '_' . date('Y-m-d') . '.xlsx"');
        } else {
            header('Content-Type: text/csv; charset=utf-8');
            header('Content-Disposition: attachment; filename="' . $filename . '_' . date('Y-m-d') . '.csv"');
        }
        header('Content-Length: ' . filesize($filepath));
        header('Cache-Control: must-revalidate');
        readfile($filepath);
        unlink($filepath);
        exit;
    }
}

require_once '../includes/admin_header.php';
?>

<style>
.shadow-xs {
    box-shadow: 0 1px 3px rgba(0, 0, 0, 0.05);
}
/* Screen Styles to liberate the table from the card container constraints */
.card {
    border: none !important;
    box-shadow: none !important;
    background: transparent !important;
}
.card-body {
    padding: 0 !important;
}
.card-header {
    border-radius: 10px !important;
    margin-bottom: 20px !important;
    border: none !important;
    box-shadow: 0 4px 6px -1px rgba(0, 0, 0, 0.08), 0 2px 4px -1px rgba(0, 0, 0, 0.04) !important;
}
.bus-metadata-bar {
    background-color: #ffffff; /* Clean white card-like bar on screen */
    border: 1px solid #e2e8f0;
    border-radius: 8px;
    padding: 12px 18px;
    font-size: 0.95rem;
    box-shadow: 0 2px 4px rgba(0, 0, 0, 0.04);
    margin-bottom: 15px !important;
}
/* Style screen table wrapper into standalone white boxes */
.table-responsive {
    background: #ffffff;
    border-radius: 10px;
    padding: 15px;
    box-shadow: 0 4px 6px -1px rgba(0, 0, 0, 0.05), 0 2px 4px -1px rgba(0, 0, 0, 0.03);
    border: 1px solid #e2e8f0;
}

/* CSS Printing Optimization (Prints ONLY the sheet content in landscape layout) */
@media print {
    @page {
        size: A4 landscape;
        margin: 4mm 5mm; /* Minimized margins for max printable area */
    }

    body,
    body.admin-page {
        padding-top: 0 !important;
        margin-top: 0 !important;
        background: #fff !important;
        background-color: #fff !important;
        border: none !important;
    }

    header, 
    .navbar,
    #sidebarMenu, 
    .sidebar,
    .no-print, 
    #widget-kpi, 
    .card-header, 
    .modal, 
    .modal-backdrop, 
    footer,
    .screen-only-container {
        display: none !important;
    }
    
    #mainContent, 
    .main-content,
    .container-fluid,
    main {
        margin: 0 !important;
        padding: 0 !important;
        border: none !important;
        border-width: 0 !important;
        box-shadow: none !important;
        background: #fff !important;
        background-color: #fff !important;
        min-height: 0 !important;  /* Kill calc(100vh - ...) from style.css */
        height: auto !important;
        margin-top: 0 !important; /* Kill var(--top-header-height) */
        margin-right: 0 !important; /* Kill var(--sidebar-width) */
    }
    
    main, 
    .content, 
    .col-md-9, 
    .col-lg-10,
    .col-12 {
        width: 100% !important;
        max-width: 100% !important;
        flex: 0 0 100% !important;
        margin: 0 !important;
        padding: 0 !important;
    }
    
    /* Kill all Bootstrap spacing utilities in print */
    .py-3, .px-md-3, .pt-3, .pb-2 {
        padding: 0 !important;
    }
    
    /* Fix Bootstrap negative margins on rows which clips right side of table */
    .row {
        margin-right: 0 !important;
        margin-left: 0 !important;
        border: none !important;
    }
    
    .card,
    .card-body {
        border: none !important;
        border-width: 0 !important;
        box-shadow: none !important;
        margin: 0 !important;
        padding: 0 !important;
        background: #fff !important;
        background-color: #fff !important;
    }

    #printArea {
        display: block !important;
        width: 100% !important;
        margin: 0 !important;
        padding: 0 !important;
        border: none !important;
        background: #fff !important;
        background-color: #fff !important;
    }
    
    .bus-group-container {
        /* page-break is now controlled exclusively by JavaScript rebuildPrintArea() */
        margin-bottom: 0 !important;
        padding-bottom: 0 !important;
        border: none !important;
        background: #fff !important;
        background-color: #fff !important;
    }
    
    /* Kill all screen-only spacing that leaks into print */
    .p-3, .p-4, .admin-list-surface {
        padding: 0 !important;
        margin: 0 !important;
        border: none !important;
        box-shadow: none !important;
        border-radius: 0 !important;
    }
    .mb-3, .mb-4, .mb-5 {
        margin-bottom: 0 !important;
    }
    
    .bus-metadata-bar {
        background-color: #f1f5f9 !important;
        border: 1px solid #ccc !important;
        font-size: 10px !important; /* legible print font size */
        padding: 4px 8px !important;
        margin-bottom: 6px !important;
        -webkit-print-color-adjust: exact;
        print-color-adjust: exact;
    }
    
    /* Fix for Bootstrap table-responsive printing page break bug */
    .table-responsive {
        overflow: visible !important;
        overflow-x: visible !important;
        border: none !important;
        padding: 0 !important;  /* Kill screen padding: 15px leak */
        margin: 0 !important;
        border-radius: 0 !important;
        box-shadow: none !important;
        background: #fff !important;
        background-color: #fff !important;
    }
    
    table {
        width: 100% !important;
        border-collapse: collapse !important;
        page-break-inside: auto;
        border: none !important; /* Remove outer table borders */
        background: #fff !important;
        background-color: #fff !important;
    }

    tr {
        page-break-inside: avoid !important;
        page-break-after: auto;
        background: #fff !important;
        background-color: #fff !important;
    }
    
    th, td {
        border-top: none !important;
        border-left: none !important;
        border-right: none !important;
        border-bottom: 1px solid #ddd !important;
        padding: 2.5px 5px !important; /* Elegant, compact but highly readable padding */
        font-size: 9px !important; /* Legible standard print font size */
        line-height: 1.1 !important;
        color: #000 !important;
        background: #fff !important;
        background-color: #fff !important;
    }
    
    th {
        background: #f1f5f9 !important; /* Soft header print background */
        background-color: #f1f5f9 !important;
        color: #000 !important;
        font-weight: bold !important;
        -webkit-print-color-adjust: exact;
        print-color-adjust: exact;
    }

    /* Print Header/Footer specific layout fixes */
    .print-header-table td, .print-footer-table td {
        border: none !important;
        padding: 1px !important;
        background: #fff !important;
        background-color: #fff !important;
    }
    
    .print-footer {
        page-break-inside: avoid !important;
        break-inside: avoid !important;
        margin-top: 5px !important;
    }
    
    .print-footer-table td {
        padding: 4px !important;
    }
    
    .print-footer-table div {
        font-size: 10px !important;
        margin-top: 8px !important;
    }

    .print-logo-img {
        width: 36px; /* Elegant print logo size */
        height: 36px;
        object-fit: contain;
    }
    
    /* Compact print header title sizes */
    .print-header-table td div {
        font-size: 10.5px !important;
        line-height: 1.3 !important;
    }
    .print-header-table .fs-5 {
        font-size: 12px !important;
    }
    .print-header-table .mb-3 {
        margin-bottom: 3px !important;
    }
}
</style>

<!-- Page Header (Hidden during print) -->
<div class="d-flex justify-content-between flex-wrap flex-md-nowrap align-items-center pt-3 pb-2 mb-3 border-bottom no-print">
    <h1 class="h2"><i class="fas fa-list-ol me-2 text-primary"></i>قوائم الحافلات</h1>
    <div class="admin-top-actions no-print">
        <?php if (!empty($busesToDisplay)): ?>
            <a href="bus_lists.php?export=excel&bus_id=<?php echo ($selectedBusId === -1) ? 'all' : $selectedBusId; ?>" class="btn btn-header-premium btn-export-soft">
                <i class="fas fa-file-excel me-2"></i>تصدير إكسل
            </a>
            <button onclick="window.print()" class="btn btn-header-premium btn-print-soft">
                <i class="fas fa-print me-2"></i>طباعة الكشف
            </button>
        <?php endif; ?>
    </div>
</div>

<!-- Statistics Cards -->
<div class="row row-cols-2 row-cols-md-5 g-3 mb-4 no-print" id="widget-kpi">
    <div class="col">
        <div class="stat-card" style="--card-gradient: linear-gradient(135deg, #3b82f6, #2563eb);">
            <div class="stat-card-icon"><i class="fas fa-bus"></i></div>
            <div class="stat-card-info">
                <div class="stat-card-number counter" data-target="<?php echo $totalBuses; ?>">0</div>
                <div class="stat-card-label">إجمالي الحافلات</div>
            </div>
        </div>
    </div>
    <div class="col">
        <div class="stat-card" style="--card-gradient: linear-gradient(135deg, #10b981, #059669);">
            <div class="stat-card-icon"><i class="fas fa-check-circle"></i></div>
            <div class="stat-card-info">
                <div class="stat-card-number counter" data-target="<?php echo $activeBusesCount; ?>">0</div>
                <div class="stat-card-label">حافلات نشطة</div>
            </div>
        </div>
    </div>
    <div class="col">
        <div class="stat-card" style="--card-gradient: linear-gradient(135deg, #f97316, #ea580c);">
            <div class="stat-card-icon"><i class="fas fa-chair"></i></div>
            <div class="stat-card-info">
                <div class="stat-card-number counter" data-target="<?php echo $totalCapacity; ?>">0</div>
                <div class="stat-card-label">السعة المقعدية</div>
            </div>
        </div>
    </div>
    <div class="col">
        <div class="stat-card" style="--card-gradient: linear-gradient(135deg, #8b5cf6, #7c3aed);">
            <div class="stat-card-icon"><i class="fas fa-user-graduate"></i></div>
            <div class="stat-card-info">
                <div class="stat-card-number counter" data-target="<?php echo $totalAssignedStudents; ?>">0</div>
                <div class="stat-card-label">المقيدون بالحافلة</div>
            </div>
        </div>
    </div>
    <div class="col">
        <div class="stat-card" style="--card-gradient: linear-gradient(135deg, #0ea5e9, #0284c7);">
            <div class="stat-card-icon"><i class="fas fa-percentage"></i></div>
            <div class="stat-card-info">
                <div class="stat-card-number"><span class="counter" data-target="<?php echo $generalOccupancyRate; ?>">0</span>%</div>
                <div class="stat-card-label">إشغال الحافلات</div>
            </div>
        </div>
    </div>
</div>

<!-- Main Content Area -->
<form method="GET" class="admin-filter-bar no-print mb-3" id="busFilterForm">
    <div class="admin-filter-controls">
        <select class="form-select form-select-sm" name="bus_id" style="width:auto; min-width:220px;" onchange="this.form.submit()">
            <option value="">-- اختر الحافلة --</option>
            <option value="all" <?php echo $selectedBusId === -1 ? 'selected' : ''; ?>>-- عرض كافة الحافلات --</option>
            <?php foreach ($busesList as $bus): ?>
                <option value="<?php echo $bus['id']; ?>" <?php echo $selectedBusId == $bus['id'] ? 'selected' : ''; ?>>
                    حافلة رقم <?php echo htmlspecialchars($bus['bus_number']); ?> <?php if ($bus['area']): ?>(<?php echo htmlspecialchars($bus['area']); ?>)<?php endif; ?>
                </option>
            <?php endforeach; ?>
        </select>
    </div>
    <div class="admin-filter-actions">
        <a href="bus_lists.php" class="btn btn-light btn-sm"><i class="fas fa-rotate-left me-1"></i>إعادة تعيين</a>
        
        <?php if ($selectedBusId > 0 || $selectedBusId === -1): ?>
        <button type="button" class="btn btn-light btn-sm" data-bs-toggle="modal" data-bs-target="#tableSettingsModal" title="إعدادات الجدول">
            <i class="fas fa-cog me-1"></i>إعدادات الجدول
        </button>
        <button type="button" class="btn btn-light btn-sm" data-bs-toggle="modal" data-bs-target="#printSettingsModal" title="إعدادات الطباعة">
            <i class="fas fa-print me-1"></i>إعدادات الطباعة
        </button>
        <?php endif; ?>
    </div>
</form>

<div class="admin-list-surface">
    <div class="p-3">
        <?php if (($selectedBusId > 0 || $selectedBusId === -1) && !empty($busesToDisplay)): ?>
            <!-- Screen Area Wrapper -->
            <div id="screenArea" class="screen-only-container">
                <?php foreach ($busesToDisplay as $bus): 
                    $busId = $bus['id'];
                    $busStudents = $studentsByBus[$busId] ?? [];
                ?>
                    <!-- Single Bus Group Container -->
                    <div class="bus-group-container mb-5">
                        
                        <!-- Print-Only Page Header -->
                        <div class="d-none d-print-block print-header">
                            <table class="print-header-table" style="width:100%; border-collapse:collapse; margin-bottom:4px;">
                                <tr>
                                    <!-- Right: Directorate and School Name -->
                                    <td style="width:35%; text-align:right; vertical-align:middle;">
                                        <div class="fw-bold fs-5 text-dark"><?php echo htmlspecialchars($schoolName ?: 'المدرسة'); ?></div>
                                        <div class="text-secondary small"><?php echo htmlspecialchars($settings['educational_administration'] ?? ''); ?></div>
                                    </td>
                                    <!-- Center: Logo -->
                                    <td style="width:30%; text-align:center; vertical-align:middle;">
                                        <?php if ($logoPath): ?>
                                            <img src="<?php echo htmlspecialchars($logoPath); ?>" alt="الشعار" class="print-logo-img">
                                        <?php endif; ?>
                                    </td>
                                    <!-- Left: Doc Title & Academic Year -->
                                    <td style="width:35%; text-align:left; vertical-align:middle;">
                                        <div class="fw-bold text-dark fs-5">كشف ركاب الحافلات المدرسية</div>
                                        <?php if ($academicYearName): ?>
                                            <div class="text-secondary small fw-semibold">العام الدراسي: <?php echo htmlspecialchars($academicYearName); ?></div>
                                        <?php endif; ?>
                                        <div class="text-muted small" style="font-size: 9px;">تاريخ الطباعة: <?php echo date('Y-m-d'); ?></div>
                                    </td>
                                </tr>
                            </table>
                        </div>
                        
                        <!-- Bus Staff & Info Section (Inline Horizontal Row) -->
                        <div class="bus-metadata-bar d-flex align-items-center justify-content-between flex-wrap gap-3 mb-3 fw-bold text-dark fs-6">
                            <div>
                                <i class="fas fa-bus text-primary me-1 no-print"></i>
                                <span>حافلة رقم: <strong class="text-primary"><?php echo htmlspecialchars($bus['bus_number']); ?></strong></span>
                                <span class="text-muted mx-2">|</span>
                                <span class="text-secondary fw-semibold">المنطقة: </span><span><?php echo htmlspecialchars($bus['area'] ?: '—'); ?></span>
                                <span class="text-muted mx-2">|</span>
                                <span class="text-secondary fw-semibold">السعة: </span><span><?php echo (int)$bus['capacity']; ?> مقعد</span>
                                <span class="text-muted mx-2">|</span>
                                <span class="text-secondary fw-semibold">الطلاب: </span><span><?php echo count($busStudents); ?> طالب</span>
                            </div>
                            <div class="d-flex align-items-center gap-3">
                                <span>
                                    <i class="fas fa-user-tie text-success me-1 no-print"></i>
                                    <span class="text-secondary fw-semibold">السائق: </span><span><?php echo htmlspecialchars($bus['driver_name'] ?: '—'); ?></span>
                                    <?php if ($bus['driver_phone']): ?>
                                         <a href="tel:<?php echo htmlspecialchars($bus['driver_phone']); ?>" class="text-success text-decoration-none ms-1" dir="ltr">
                                             <i class="fas fa-phone text-success me-1 small"></i> <span><?php echo htmlspecialchars($bus['driver_phone']); ?></span>
                                         </a>
                                    <?php endif; ?>
                                </span>
                                <span class="text-muted">|</span>
                                <span>
                                    <i class="fas fa-user-shield text-info me-1 no-print"></i>
                                    <span class="text-secondary fw-semibold">المشرف: </span><span><?php echo htmlspecialchars($bus['supervisor_name'] ?: '—'); ?></span>
                                    <?php if ($bus['supervisor_phone']): ?>
                                        <a href="tel:<?php echo htmlspecialchars($bus['supervisor_phone']); ?>" class="text-info text-decoration-none ms-1" dir="ltr">
                                             <i class="fas fa-phone text-info me-1 small"></i> <span><?php echo htmlspecialchars($bus['supervisor_phone']); ?></span>
                                        </a>
                                    <?php endif; ?>
                                </span>
                            </div>
                        </div>
                        
                        <!-- Students Table -->
                        <div class="table-responsive mb-4">
                            <table class="table table-hover table-striped align-middle bus-students-table" id="busStudentsTable_<?php echo $busId; ?>">
                                <thead class="table-light">
                                    <tr>
                                        <th data-col="col_num" width="50" class="text-center">#</th>
                                        <th data-col="col_name">اسم الطالب</th>
                                        <th data-col="col_grade">الصف</th>
                                        <th data-col="col_class">الفصل</th>
                                        <th data-col="col_father_phone">رقم موبايل الأب</th>
                                        <th data-col="col_mother_phone">رقم موبايل الأم</th>
                                        <th data-col="col_emergency_phone">رقم الطوارئ</th>
                                        <th data-col="col_notes">ملاحظات</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php if (empty($busStudents)): ?>
                                        <tr>
                                            <td colspan="8" class="text-center py-4 text-muted">
                                                <i class="fas fa-info-circle me-1 opacity-70"></i>لا يوجد طلاب مقيدون بهذه الحافلة حالياً
                                            </td>
                                        </tr>
                                    <?php else: ?>
                                        <?php $idx = 0; foreach ($busStudents as $student): $idx++; 
                                             $isBackup = ($student['backup_bus_id'] == $busId && $student['bus_id'] != $busId);
                                        ?>
                                        <tr>
                                            <td data-col="col_num" class="text-center text-secondary fw-bold"><?php echo $idx; ?></td>
                                            <td data-col="col_name">
                                                <strong><?php echo htmlspecialchars($student['student_name']); ?></strong>
                                                <?php if ($isBackup): ?>
                                                    <span class="badge bg-warning-subtle text-warning-emphasis border border-warning ms-1 small" style="font-size: 0.72rem; padding: 2px 5px; font-weight: bold; -webkit-print-color-adjust: exact; print-color-adjust: exact;">
                                                        (احتياطي)
                                                    </span>
                                                <?php endif; ?>
                                            </td>
                                            <td data-col="col_grade"><?php echo htmlspecialchars($student['grade_name'] ?: '—'); ?></td>
                                            <td data-col="col_class"><?php echo htmlspecialchars($student['class_name'] ?: '—'); ?></td>
                                            <td data-col="col_father_phone">
                                                <?php if ($student['father_phone']): ?>
                                                    <a href="tel:<?php echo htmlspecialchars($student['father_phone']); ?>" class="text-primary text-decoration-none" dir="ltr" style="color: #0d6efd !important;">
                                                        <i class="fas fa-phone no-print" style="margin-right: 6px; font-size: 0.85em; color: #0d6efd !important;"></i><span><?php echo htmlspecialchars($student['father_phone']); ?></span>
                                                    </a>
                                                <?php else: ?>
                                                    <span class="text-muted">—</span>
                                                <?php endif; ?>
                                            </td>
                                            <td data-col="col_mother_phone">
                                                <?php if ($student['mother_phone']): ?>
                                                    <a href="tel:<?php echo htmlspecialchars($student['mother_phone']); ?>" class="text-primary text-decoration-none" dir="ltr" style="color: #0d6efd !important;">
                                                        <i class="fas fa-phone no-print" style="margin-right: 6px; font-size: 0.85em; color: #0d6efd !important;"></i><span><?php echo htmlspecialchars($student['mother_phone']); ?></span>
                                                    </a>
                                                <?php else: ?>
                                                    <span class="text-muted">—</span>
                                                <?php endif; ?>
                                            </td>
                                            <td data-col="col_emergency_phone">
                                                <?php if ($student['student_emergency_phone']): ?>
                                                    <a href="tel:<?php echo htmlspecialchars($student['student_emergency_phone']); ?>" class="text-primary text-decoration-none fw-bold" dir="ltr" style="color: #0d6efd !important;">
                                                        <i class="fas fa-phone no-print" style="margin-right: 6px; font-size: 0.85em; color: #0d6efd !important;"></i><span><?php echo htmlspecialchars($student['student_emergency_phone']); ?></span>
                                                    </a>
                                                <?php else: ?>
                                                    <span class="text-muted">—</span>
                                                <?php endif; ?>
                                            </td>
                                            <td data-col="col_notes"><small class="text-muted"><?php echo htmlspecialchars($student['bus_notes'] ?: '—'); ?></small></td>
                                        </tr>
                                        <?php endforeach; ?>
                                    <?php endif; ?>
                                </tbody>
                            </table>
                        </div>

                        <!-- Print-Only Page Footer -->
                        <div class="d-none d-print-block print-footer">
                            <div class="mb-2"></div>
                            <table class="print-footer-table" style="width:100%; border-collapse:collapse; text-align:center;">
                                <tr>
                                    <td style="width:50%; vertical-align:top;">
                                        <div class="text-secondary fw-semibold small" style="font-size: 11px;">مسؤول الحركة والتنقلات</div>
                                        <div class="fw-bold mt-3 text-dark" style="font-size: 11px; border-top: 1px dotted #000; display: inline-block; min-width: 150px; padding-top: 3px;">
                                            <?php echo htmlspecialchars($transportOfficer ?: '—'); ?>
                                        </div>
                                    </td>
                                    <td style="width:50%; vertical-align:top;">
                                        <div class="text-secondary fw-semibold small" style="font-size: 11px;">المدير الإداري</div>
                                        <div class="fw-bold mt-3 text-dark" style="font-size: 11px; border-top: 1px dotted #000; display: inline-block; min-width: 150px; padding-top: 3px;">
                                            <?php echo htmlspecialchars($adminDirector ?: '—'); ?>
                                        </div>
                                    </td>
                                </tr>
                            </table>
                        </div>

                    </div>
                <?php endforeach; ?>
            </div>
            <!-- Dynamic Print Area Wrapper (Populated via JS) -->
            <div id="printArea" class="d-none d-print-block"></div>
        <?php else: ?>
            <div class="text-center py-5 text-muted">
                <i class="fas fa-bus fa-4x mb-3 text-primary opacity-50"></i>
                <h5 class="fw-bold text-dark">يرجى اختيار الحافلة من الأعلى لعرض كشف الحافلة</h5>
                <p class="mb-0 text-secondary">سيتم عرض قائمة الطلاب المسجلين وبيانات المشرف والسائق فور التحديد.</p>
            </div>
        <?php endif; ?>
    </div>
</div>

<!-- Table Settings Modal (Only generated if a bus is selected) -->
<?php if ($selectedBusId > 0 || $selectedBusId === -1): ?>
<div class="modal fade" id="tableSettingsModal" tabindex="-1" aria-labelledby="tableSettingsModalLabel" aria-hidden="true">
    <div class="modal-dialog">
        <div class="modal-content admin-modal admin-modal-premium admin-modal-view">
            <div class="modal-header">
                <h5 class="modal-title" id="tableSettingsModalLabel"><i class="fas fa-cog me-2"></i>إعدادات أعمدة الجدول</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body">
                <p class="mb-3">اختر الأعمدة التي ترغب في إظهارها في الجدول (يتم التحديث فوراً لكافة الحافلات):</p>
                <div class="row g-3">
                    <div class="col-6">
                        <div class="form-check">
                            <input class="form-check-input" type="checkbox" id="col_num" checked>
                            <label class="form-check-label" for="col_num">#</label>
                        </div>
                    </div>
                    <div class="col-6">
                        <div class="form-check">
                            <input class="form-check-input" type="checkbox" id="col_name" checked>
                            <label class="form-check-label" for="col_name">اسم الطالب</label>
                        </div>
                    </div>
                    <div class="col-6">
                        <div class="form-check">
                            <input class="form-check-input" type="checkbox" id="col_grade" checked>
                            <label class="form-check-label" for="col_grade">الصف</label>
                        </div>
                    </div>
                    <div class="col-6">
                        <div class="form-check">
                            <input class="form-check-input" type="checkbox" id="col_class" checked>
                            <label class="form-check-label" for="col_class">الفصل</label>
                        </div>
                    </div>
                    <div class="col-6">
                        <div class="form-check">
                            <input class="form-check-input" type="checkbox" id="col_father_phone" checked>
                            <label class="form-check-label" for="col_father_phone">رقم موبايل الأب</label>
                        </div>
                    </div>
                    <div class="col-6">
                        <div class="form-check">
                            <input class="form-check-input" type="checkbox" id="col_mother_phone" checked>
                            <label class="form-check-label" for="col_mother_phone">رقم موبايل الأم</label>
                        </div>
                    </div>
                    <div class="col-6">
                        <div class="form-check">
                            <input class="form-check-input" type="checkbox" id="col_emergency_phone" checked>
                            <label class="form-check-label" for="col_emergency_phone">رقم الطوارئ</label>
                        </div>
                    </div>
                    <div class="col-6">
                        <div class="form-check">
                            <input class="form-check-input" type="checkbox" id="col_notes" checked>
                            <label class="form-check-label" for="col_notes">ملاحظات</label>
                        </div>
                    </div>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">
                    <i class="fas fa-check me-1"></i>إغلاق
                </button>
            </div>
        </div>
    </div>
</div>

<!-- Print Settings Modal -->
<div class="modal fade" id="printSettingsModal" tabindex="-1" aria-labelledby="printSettingsModalLabel" aria-hidden="true">
    <div class="modal-dialog">
        <div class="modal-content admin-modal admin-modal-premium admin-modal-view">
            <div class="modal-header">
                <h5 class="modal-title" id="printSettingsModalLabel"><i class="fas fa-print me-2"></i>إعدادات طباعة الكشوفات</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body">
                <h6 class="fw-bold mb-3"><i class="fas fa-table me-2 text-primary"></i>الأعمدة المراد طباعتها في الكشف الورقي:</h6>
                <div class="row g-3 mb-4">
                    <div class="col-6">
                        <div class="form-check">
                            <input class="form-check-input print-col-check" type="checkbox" id="print_col_num" checked>
                            <label class="form-check-label" for="print_col_num">#</label>
                        </div>
                    </div>
                    <div class="col-6">
                        <div class="form-check">
                            <input class="form-check-input print-col-check" type="checkbox" id="print_col_name" checked>
                            <label class="form-check-label" for="print_col_name">اسم الطالب</label>
                        </div>
                    </div>
                    <div class="col-6">
                        <div class="form-check">
                            <input class="form-check-input print-col-check" type="checkbox" id="print_col_grade" checked>
                            <label class="form-check-label" for="print_col_grade">الصف</label>
                        </div>
                    </div>
                    <div class="col-6">
                        <div class="form-check">
                            <input class="form-check-input print-col-check" type="checkbox" id="print_col_class" checked>
                            <label class="form-check-label" for="print_col_class">الفصل</label>
                        </div>
                    </div>
                    <div class="col-6">
                        <div class="form-check">
                            <input class="form-check-input print-col-check" type="checkbox" id="print_col_father_phone" checked>
                            <label class="form-check-label" for="print_col_father_phone">رقم موبايل الأب</label>
                        </div>
                    </div>
                    <div class="col-6">
                        <div class="form-check">
                            <input class="form-check-input print-col-check" type="checkbox" id="print_col_mother_phone" checked>
                            <label class="form-check-label" for="print_col_mother_phone">رقم موبايل الأم</label>
                        </div>
                    </div>
                    <div class="col-6">
                        <div class="form-check">
                            <input class="form-check-input print-col-check" type="checkbox" id="print_col_emergency_phone" checked>
                            <label class="form-check-label" for="print_col_emergency_phone">رقم الطوارئ</label>
                        </div>
                    </div>
                    <div class="col-6">
                        <div class="form-check">
                            <input class="form-check-input print-col-check" type="checkbox" id="print_col_notes" checked>
                            <label class="form-check-label" for="print_col_notes">ملاحظات</label>
                        </div>
                    </div>
                </div>
                
                <h6 class="fw-bold mb-3"><i class="fas fa-list-ol me-2 text-primary"></i>تقسيم الصفحات:</h6>
                <div class="mb-3">
                    <label for="printRowsPerPage" class="form-label small">أقصى عدد صفوف (طلاب) في الورقة الواحدة:</label>
                    <div class="input-group input-group-sm" style="max-width: 150px;">
                        <input type="number" id="printRowsPerPage" class="form-control" value="35" min="5" max="100">
                        <span class="input-group-text">سطر</span>
                    </div>
                    <div class="form-text text-muted">سيتم تقسيم الكشف تلقائياً وإدراج توقيع المسؤولين والترويسة لكل ورقة مطبوعة بشكل منفصل.</div>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">إلغاء</button>
                <button type="button" class="btn btn-primary" id="btnSavePrintSettings"><i class="fas fa-save me-1"></i>حفظ الإعدادات</button>
            </div>
        </div>
    </div>
</div>
<?php endif; ?>

<script src="../assets/js/admin_table_actions.js"></script>
<script>
document.addEventListener('DOMContentLoaded', function() {
    <?php if (($selectedBusId > 0 || $selectedBusId === -1) && !empty($busesToDisplay)): ?>
    
    // 1. Back up original screen HTML as the source template for print chunking
    var screenArea = document.getElementById('screenArea');
    if (screenArea) {
        window.originalPrintAreaHtml = screenArea.innerHTML;
    }

    // Shared columns mapping for all screen tables
    var colMapping = {
        col_num: 0,
        col_name: 1,
        col_grade: 2,
        col_class: 3,
        col_father_phone: 4,
        col_mother_phone: 5,
        col_emergency_phone: 6,
        col_notes: 7
    };

    // Get list of active bus IDs rendered on screen
    var busIds = <?php echo json_encode(array_column($busesToDisplay, 'id')); ?>;

    // Loop and initialize settings + DataTables for each screen bus table separately
    busIds.forEach(function(busId) {
        var tableId = 'busStudentsTable_' + busId;
        
        // Initialize column visibility settings (for screen)
        initializeTableColumnSettings(tableId, colMapping, 'bus_lists_columns');

        // Initialize DataTable on screen table
        if (typeof $ !== 'undefined' && $.fn.DataTable) {
            if ($.fn.DataTable.isDataTable('#' + tableId)) {
                $('#' + tableId).DataTable().destroy();
            }
            $('#' + tableId).DataTable({
                destroy: true,
                paging: false,      // Show all rows
                searching: false,   // Hide search box
                info: false,        // Hide info text
                lengthChange: false,// Hide length selector
                ordering: true,     // Enable column sorting
                order: [[0, 'asc']], // Sort by serial number (#)
                columnDefs: [
                    { orderable: false, targets: [4, 5, 6, 7] } // Phones and notes non-sortable
                ],
                responsive: true
            });
        }
    });

    // 2. Rebuild Print Area based on Print Settings
    function rebuildPrintArea() {
        var printArea = document.getElementById('printArea');
        if (!printArea || !window.originalPrintAreaHtml) return;

        // Load settings from localStorage
        var printColsSetting = localStorage.getItem('bus_lists_print_columns');
        var printColumns = printColsSetting ? JSON.parse(printColsSetting) : {
            col_num: true,
            col_name: true,
            col_grade: true,
            col_class: true,
            col_father_phone: true,
            col_mother_phone: true,
            col_emergency_phone: true,
            col_notes: true
        };
        
        var rowsPerPageSetting = localStorage.getItem('bus_lists_print_rows_per_page');
        var rowsPerPage = rowsPerPageSetting ? parseInt(rowsPerPageSetting, 10) : 35;
        if (isNaN(rowsPerPage) || rowsPerPage < 5) rowsPerPage = 35;

        // Parse original HTML
        var tempContainer = document.createElement('div');
        tempContainer.innerHTML = window.originalPrintAreaHtml;

        var originalGroups = tempContainer.querySelectorAll('.bus-group-container');
        printArea.innerHTML = '';

        originalGroups.forEach(function(originalGroup, groupIdx) {
            var headerHtml = originalGroup.querySelector('.print-header') ? originalGroup.querySelector('.print-header').outerHTML : '';
            var metadataBar = originalGroup.querySelector('.bus-metadata-bar');
            var metadataHtml = metadataBar ? metadataBar.outerHTML : '';
            var footerHtml = originalGroup.querySelector('.print-footer') ? originalGroup.querySelector('.print-footer').outerHTML : '';
            
            var originalTable = originalGroup.querySelector('.bus-students-table');
            if (!originalTable) return;
            
            // Clean table header for clean printing
            var tableHeadHtml = originalTable.querySelector('thead') ? originalTable.querySelector('thead').outerHTML : '';
            var allRows = Array.from(originalTable.querySelectorAll('tbody tr'));
            
            if (allRows.length === 0) {
                var container = document.createElement('div');
                container.className = 'bus-group-container mb-5';
                container.innerHTML = headerHtml + metadataHtml + 
                    '<div class="table-responsive mb-4"><table class="table align-middle bus-students-table">' + 
                    tableHeadHtml + '<tbody><tr><td colspan="8" class="text-center py-4 text-muted">لا يوجد طلاب مقيدون بهذه الحافلة حالياً</td></tr></tbody></table></div>' + 
                    footerHtml;
                printArea.appendChild(container);
                return;
            }

            // Split into pages/chunks
            var chunks = [];
            for (var i = 0; i < allRows.length; i += rowsPerPage) {
                chunks.push(allRows.slice(i, i + rowsPerPage));
            }

            chunks.forEach(function(chunk, chunkIdx) {
                var container = document.createElement('div');
                container.className = 'bus-group-container mb-5';
                
                // Adjust metadata bar to show print page index
                var currentMetadataHtml = metadataHtml;
                if (chunks.length > 1) {
                    var pageNumLabel = ' <span class="badge bg-secondary text-white ms-2" style="font-size: 8px; -webkit-print-color-adjust: exact; print-color-adjust: exact;">صفحة ' + (chunkIdx + 1) + ' من ' + chunks.length + '</span>';
                    var tempMetadata = document.createElement('div');
                    tempMetadata.innerHTML = metadataHtml;
                    var firstDiv = tempMetadata.firstElementChild;
                    if (firstDiv && firstDiv.firstElementChild) {
                        firstDiv.firstElementChild.insertAdjacentHTML('afterend', pageNumLabel);
                    }
                    currentMetadataHtml = tempMetadata.innerHTML;
                }

                var chunkRowsHtml = '';
                chunk.forEach(function(row) {
                    chunkRowsHtml += row.outerHTML;
                });

                var tableHtml = '<div class="table-responsive mb-4">' +
                    '<table class="table table-hover table-striped align-middle bus-students-table">' +
                    tableHeadHtml +
                    '<tbody>' + chunkRowsHtml + '</tbody>' +
                    '</table>' +
                    '</div>';

                container.innerHTML = headerHtml + currentMetadataHtml + tableHtml + footerHtml;
                
                // Force-show print-header and print-footer (they have d-none on screen but must show here)
                container.querySelectorAll('.print-header, .print-footer').forEach(function(el) {
                    el.style.setProperty('display', 'block', 'important');
                });
                
                // Remove d-none class from print-header/footer so they show in printArea
                container.querySelectorAll('.d-none').forEach(function(el) {
                    if (el.classList.contains('print-header') || el.classList.contains('print-footer')) {
                        el.classList.remove('d-none');
                        el.classList.remove('d-print-block');
                    }
                });
                
                // Hide columns not chosen for print
                Object.keys(printColumns).forEach(function(colId) {
                    if (!printColumns[colId]) {
                        container.querySelectorAll('[data-col="' + colId + '"]').forEach(function(cell) {
                            cell.style.setProperty('display', 'none', 'important');
                        });
                    }
                });

                // Configure page breaks
                if (groupIdx === originalGroups.length - 1 && chunkIdx === chunks.length - 1) {
                    container.style.setProperty('page-break-after', 'avoid', 'important');
                    container.style.setProperty('break-after', 'avoid', 'important');
                } else {
                    container.style.setProperty('page-break-after', 'always', 'important');
                    container.style.setProperty('break-after', 'page', 'important');
                }

                printArea.appendChild(container);
            });
        });
    }

    // 3. Print Settings Modal logic and load values
    var printSettingsModal = document.getElementById('printSettingsModal');
    if (printSettingsModal) {
        printSettingsModal.addEventListener('show.bs.modal', function() {
            var printColsSetting = localStorage.getItem('bus_lists_print_columns');
            var printColumns = printColsSetting ? JSON.parse(printColsSetting) : {
                col_num: true,
                col_name: true,
                col_grade: true,
                col_class: true,
                col_father_phone: true,
                col_mother_phone: true,
                col_emergency_phone: true,
                col_notes: true
            };
            
            var rowsPerPageSetting = localStorage.getItem('bus_lists_print_rows_per_page');
            var rowsPerPage = rowsPerPageSetting ? parseInt(rowsPerPageSetting, 10) : 35;

            // Check checkboxes
            document.getElementById('print_col_num').checked = !!printColumns.col_num;
            document.getElementById('print_col_name').checked = !!printColumns.col_name;
            document.getElementById('print_col_grade').checked = !!printColumns.col_grade;
            document.getElementById('print_col_class').checked = !!printColumns.col_class;
            document.getElementById('print_col_father_phone').checked = !!printColumns.col_father_phone;
            document.getElementById('print_col_mother_phone').checked = !!printColumns.col_mother_phone;
            document.getElementById('print_col_emergency_phone').checked = !!printColumns.col_emergency_phone;
            document.getElementById('print_col_notes').checked = !!printColumns.col_notes;

            // Set rows input
            document.getElementById('printRowsPerPage').value = rowsPerPage;
        });

        document.getElementById('btnSavePrintSettings').addEventListener('click', function() {
            var printColumns = {
                col_num: document.getElementById('print_col_num').checked,
                col_name: document.getElementById('print_col_name').checked,
                col_grade: document.getElementById('print_col_grade').checked,
                col_class: document.getElementById('print_col_class').checked,
                col_father_phone: document.getElementById('print_col_father_phone').checked,
                col_mother_phone: document.getElementById('print_col_mother_phone').checked,
                col_emergency_phone: document.getElementById('print_col_emergency_phone').checked,
                col_notes: document.getElementById('print_col_notes').checked
            };

            var rowsInput = parseInt(document.getElementById('printRowsPerPage').value, 10);
            if (isNaN(rowsInput) || rowsInput < 5) rowsInput = 35;

            localStorage.setItem('bus_lists_print_columns', JSON.stringify(printColumns));
            localStorage.setItem('bus_lists_print_rows_per_page', rowsInput);

            rebuildPrintArea();

            var modalInstance = bootstrap.Modal.getInstance(printSettingsModal);
            if (modalInstance) {
                modalInstance.hide();
            }
        });
    }

    // Rebuild initially on load
    rebuildPrintArea();

    <?php endif; ?>
});
</script>

<?php require_once '../includes/admin_footer.php'; ?>
