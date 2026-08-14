<?php
/**
 * تقارير الحركة والتنقلات — Transport Reports
 * تتيح اختيار الحقول والفلاتر ثم التصدير إلى Excel أو الطباعة
 */
$page_title = "تقارير الحركة والتنقلات";
$custom_page_title = true;

require_once '../config/database.php';
require_once '../classes/utilities.php';
require_once '../classes/excel_handler.php';
require_once '../classes/AcademicYear.php';
require_once '../includes/session_config.php';
require_once '../includes/csrf.php';
Utilities::validateSession('admin');
require_once __DIR__ . '/../classes/FinanceLegacyAdapter.php';
FinanceLegacyAdapter::delegateRequestIfEnabled(__FILE__);

requireCsrfPost();

$database = new Database();
$db = $database->getConnection();
$db->exec("SET NAMES 'utf8mb4'");
$currentAcademicYearId = AcademicYear::currentId($db);

// التسميات العربية والمجموعات
$genderLabels = ['male' => 'ذكر', 'female' => 'أنثى'];
$relationshipLabels = [
    'father' => 'أب', 'mother' => 'أم', 'grandfather' => 'جد', 'grandmother' => 'جدة',
    'uncle_paternal' => 'عم', 'aunt_paternal' => 'عمة', 'uncle_maternal' => 'خال',
    'aunt_maternal' => 'خالة', 'brother' => 'أخ', 'sister' => 'أخت', 'legal_guardian' => 'ولي أمر قانوني'
];

// جلب التاب النشط
$activeTab = $_GET['tab'] ?? ($_POST['active_tab'] ?? 'geographic');
$validTabs = ['geographic', 'buses', 'staff', 'manifests'];
if (!in_array($activeTab, $validTabs)) {
    $activeTab = 'geographic';
}

// جلب الفلاتر الأساسية لقوائم الاختيار
$allBuses = $db->query("SELECT id, bus_number, area FROM buses WHERE status = 'active' ORDER BY bus_number")->fetchAll(PDO::FETCH_ASSOC);
$stages = $db->query("SELECT id, stage_name FROM stages ORDER BY stage_order")->fetchAll(PDO::FETCH_ASSOC);
$grades = $db->query("SELECT id, grade_name, stage_id FROM grades ORDER BY stage_id, id")->fetchAll(PDO::FETCH_ASSOC);
$allClasses = $db->query("SELECT id, name, grade_id FROM classes WHERE status='active' ORDER BY display_order, name")->fetchAll(PDO::FETCH_ASSOC);

// تعريف كل الحقول المتاحة لكل تاب
$geoFields = [
    'level_name'    => 'المستوى الجغرافي',
    'name'          => 'الاسم',
    'parent_name'   => 'العنصر الأب',
    'display_order' => 'الترتيب',
    'status'        => 'الحالة',
];

$busFields = [
    'bus_number'        => 'رقم الحافلة',
    'capacity'          => 'السعة',
    'area'              => 'المنطقة المغطاة',
    'route_description' => 'خط السير',
    'primary_count'     => 'عدد الطلاب أساسي',
    'backup_count'      => 'عدد الطلاب احتياطي',
    'driver_names'      => 'السائقين',
    'supervisor_names'  => 'المشرفين',
    'status'            => 'الحالة',
];

$staffFields = [
    'name'       => 'الاسم',
    'role'       => 'الدور',
    'phones'     => 'أرقام الهواتف',
    'bus_number' => 'الحافلة المعين عليها',
    'notes'      => 'ملاحظات',
];

$manifestFields = [
    'index'          => 'مسلسل',
    'student_name'   => 'اسم الطالب',
    'grade_class'    => 'الصف / الفصل',
    'guardian_phone' => 'أرقام أولياء الأمور',
    'bus_notes'      => 'الملاحظات',
];

// دوال تنسيق البيانات للمخرجات
function formatGeoValue($field, $row) {
    switch ($field) {
        case 'level_name': return $row['level_name'] ?? '-';
        case 'name': return $row['name'] ?? '-';
        case 'parent_name': return $row['parent_name'] ?? '-';
        case 'display_order': return $row['display_order'] ?? '0';
        case 'status': return ($row['status'] === 'active' ? 'نشط' : 'معطل');
        default: return '-';
    }
}

function formatBusValue($field, $row, $busStaffMap) {
    $busId = $row['id'];
    switch ($field) {
        case 'bus_number': return $row['bus_number'] ?? '-';
        case 'capacity': return $row['capacity'] ?? '0';
        case 'area': return $row['area'] ?? '-';
        case 'route_description': return $row['route_description'] ?? '-';
        case 'primary_count': return $row['primary_count'] ?? '0';
        case 'backup_count': return $row['backup_count'] ?? '0';
        case 'driver_names':
            $drivers = $busStaffMap[$busId]['driver'] ?? [];
            return !empty($drivers) ? implode(', ', $drivers) : '—';
        case 'supervisor_names':
            $supervisors = $busStaffMap[$busId]['supervisor'] ?? [];
            return !empty($supervisors) ? implode(', ', $supervisors) : '—';
        case 'status': return ($row['status'] === 'active' ? 'نشط' : 'متوقف');
        default: return '-';
    }
}

function formatStaffValue($field, $row) {
    switch ($field) {
        case 'name': return $row['name'] ?? '-';
        case 'role': return ($row['role'] === 'driver' ? 'سائق' : 'مشرف');
        case 'phones': return $row['phones'] ?? '-';
        case 'bus_number': return $row['bus_number'] ?? 'غير معين';
        case 'notes': return $row['notes'] ?? '-';
        default: return '-';
    }
}

function formatManifestValue($field, $student, $index) {
    switch ($field) {
        case 'index': return $index;
        case 'student_name': return $student['student_name'] ?? '-';
        case 'grade_class': 
            $grade = $student['grade_name'] ?? '';
            $class = $student['class_name'] ?? '';
            if ($grade && $class) return $grade . ' / ' . $class;
            return $grade ?: ($class ?: '-');
        case 'guardian_phone':
            $parts = [];
            if (!empty($student['guardian_name'])) {
                $parts[] = $student['guardian_name'];
            }
            $phones = array_filter([$student['phone_primary'], $student['phone_secondary']]);
            if (!empty($phones)) {
                $parts[] = '(' . implode(' - ', $phones) . ')';
            }
            return !empty($parts) ? implode(' ', $parts) : '-';
        case 'bus_notes': return $student['bus_notes'] ?? '-';
        default: return '-';
    }
}

$resultsData = [];
$selectedFields = [];
$showPrintView = false;

// متغيرات كشف قوائم الحافلة
$manifestBusData = null;
$manifestStaff = [];
$manifestStudents = [];

// ============================================
// معالجة إرسال النماذج والطلبات (POST)
// ============================================
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['do_export'])) {
    $selectedFields = $_POST['fields'] ?? [];
    $exportFormat = $_POST['export_format'] ?? 'preview';

    if (!empty($selectedFields) || $activeTab === 'manifests') {
        
        // 1. تصفية ومعالجة تاب المناطق الجغرافية
        if ($activeTab === 'geographic') {
            $geoLevel = $_POST['filter_geo_level'] ?? '';
            $geoStatus = $_POST['filter_geo_status'] ?? '';
            
            $queries = [];
            if (empty($geoLevel) || $geoLevel === 'governorates') {
                $where = [];
                if (!empty($geoStatus)) $where[] = "status = '" . ($geoStatus === 'active' ? 'active' : 'inactive') . "'";
                $whereSQL = !empty($where) ? " WHERE " . implode(" AND ", $where) : "";
                $queries[] = "SELECT 'محافظة' as level_name, id, name, '-' as parent_name, display_order, status FROM governorates" . $whereSQL;
            }
            if (empty($geoLevel) || $geoLevel === 'cities') {
                $where = [];
                if (!empty($geoStatus)) $where[] = "c.status = '" . ($geoStatus === 'active' ? 'active' : 'inactive') . "'";
                $whereSQL = !empty($where) ? " WHERE " . implode(" AND ", $where) : "";
                $queries[] = "SELECT 'مدينة' as level_name, c.id, c.name, g.name as parent_name, c.display_order, c.status FROM cities c LEFT JOIN governorates g ON c.governorate_id = g.id" . $whereSQL;
            }
            if (empty($geoLevel) || $geoLevel === 'centers') {
                $where = [];
                if (!empty($geoStatus)) $where[] = "cn.status = '" . ($geoStatus === 'active' ? 'active' : 'inactive') . "'";
                $whereSQL = !empty($where) ? " WHERE " . implode(" AND ", $where) : "";
                $queries[] = "SELECT 'مركز' as level_name, cn.id, cn.name, ci.name as parent_name, cn.display_order, cn.status FROM centers cn LEFT JOIN cities ci ON cn.city_id = ci.id" . $whereSQL;
            }
            if (empty($geoLevel) || $geoLevel === 'neighborhoods') {
                $where = [];
                if (!empty($geoStatus)) $where[] = "n.status = '" . ($geoStatus === 'active' ? 'active' : 'inactive') . "'";
                $whereSQL = !empty($where) ? " WHERE " . implode(" AND ", $where) : "";
                $queries[] = "SELECT 'منطقة/حي' as level_name, n.id, n.name, cn.name as parent_name, n.display_order, n.status FROM neighborhoods n LEFT JOIN centers cn ON n.center_id = cn.id" . $whereSQL;
            }
            if (empty($geoLevel) || $geoLevel === 'streets') {
                $where = [];
                if (!empty($geoStatus)) $where[] = "s.status = '" . ($geoStatus === 'active' ? 'active' : 'inactive') . "'";
                $whereSQL = !empty($where) ? " WHERE " . implode(" AND ", $where) : "";
                $queries[] = "SELECT 'شارع' as level_name, s.id, s.name, n.name as parent_name, s.display_order, s.status FROM streets s LEFT JOIN neighborhoods n ON s.neighborhood_id = n.id" . $whereSQL;
            }

            $sql = implode(" UNION ALL ", $queries) . " ORDER BY level_name, name";
            $stmt = $db->query($sql);
            $resultsData = $stmt->fetchAll(PDO::FETCH_ASSOC);

            // تصدير Excel
            if ($exportFormat === 'excel') {
                $excel_handler = new ExcelHandler();
                if (ob_get_level() > 0) ob_clean();

                $excelData = [];
                $excelHeaders = [];
                foreach ($selectedFields as $f) {
                    if (isset($geoFields[$f])) $excelHeaders[] = $geoFields[$f];
                }
                $excelData[] = $excelHeaders;

                foreach ($resultsData as $row) {
                    $excelRow = [];
                    foreach ($selectedFields as $f) {
                        $excelRow[] = formatGeoValue($f, $row);
                    }
                    $excelData[] = $excelRow;
                }

                $filepath = $excel_handler->exportToExcel($excelData, 'تقرير_المناطق_الجغرافية');
                if ($filepath && file_exists($filepath)) {
                    header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
                    header('Content-Disposition: attachment; filename="تقرير_المناطق_الجغرافية_' . date('Y-m-d') . '.xlsx"');
                    readfile($filepath);
                    unlink($filepath);
                    exit;
                }
            }
        }

        // 2. تصفية ومعالجة تاب الحافلات
        elseif ($activeTab === 'buses') {
            $where = [];
            $params = [];
            if (!empty($_POST['filter_bus_id'])) {
                $where[] = "b.id = ?";
                $params[] = $_POST['filter_bus_id'];
            }
            if (!empty($_POST['filter_status'])) {
                $where[] = "b.status = ?";
                $params[] = $_POST['filter_status'];
            }
            $whereSQL = !empty($where) ? " WHERE " . implode(" AND ", $where) : "";
            $sql = "SELECT b.id, b.bus_number, b.capacity, b.area, b.route_description, b.status,
                           (SELECT COUNT(*) FROM student_bus_assignments sba_count
                            JOIN users su ON su.id = sba_count.student_id AND su.status = 'active' AND su.deleted_at IS NULL
                            WHERE sba_count.bus_id = b.id" . ($currentAcademicYearId > 0 ? " AND sba_count.academic_year_id = {$currentAcademicYearId}" : "") . ") as primary_count,
                           (SELECT COUNT(*) FROM student_bus_assignments sba_count
                            JOIN users su ON su.id = sba_count.student_id AND su.status = 'active' AND su.deleted_at IS NULL
                            WHERE sba_count.backup_bus_id = b.id" . ($currentAcademicYearId > 0 ? " AND sba_count.academic_year_id = {$currentAcademicYearId}" : "") . ") as backup_count
                    FROM buses b" . $whereSQL . " ORDER BY b.bus_number";
            
            $stmt = $db->prepare($sql);
            $stmt->execute($params);
            $resultsData = $stmt->fetchAll(PDO::FETCH_ASSOC);

            // جلب طاقم الحافلات (Many-to-Many via pivot)
            $busStaffMap = [];
            $staffRows = $db->query("SELECT bs.*, bsa.bus_id FROM bus_staff bs JOIN bus_staff_assignments bsa ON bs.id = bsa.staff_id ORDER BY bs.role, bs.id")->fetchAll(PDO::FETCH_ASSOC);
            foreach ($staffRows as $sr) {
                $busId = $sr['bus_id'];
                $role = $sr['role'];
                if ($busId) {
                    $busStaffMap[$busId][$role][] = $sr['name'];
                }
            }

            // تصدير Excel
            if ($exportFormat === 'excel') {
                $excel_handler = new ExcelHandler();
                if (ob_get_level() > 0) ob_clean();

                $excelData = [];
                $excelHeaders = [];
                foreach ($selectedFields as $f) {
                    if (isset($busFields[$f])) $excelHeaders[] = $busFields[$f];
                }
                $excelData[] = $excelHeaders;

                foreach ($resultsData as $row) {
                    $excelRow = [];
                    foreach ($selectedFields as $f) {
                        $excelRow[] = formatBusValue($f, $row, $busStaffMap);
                    }
                    $excelData[] = $excelRow;
                }

                $filepath = $excel_handler->exportToExcel($excelData, 'تقرير_الحافلات');
                if ($filepath && file_exists($filepath)) {
                    header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
                    header('Content-Disposition: attachment; filename="تقرير_الحافلات_' . date('Y-m-d') . '.xlsx"');
                    readfile($filepath);
                    unlink($filepath);
                    exit;
                }
            }
        }

        // 3. تصفية ومعالجة تاب طاقم الحافلات
        elseif ($activeTab === 'staff') {
            $where = [];
            $params = [];
            if (!empty($_POST['filter_staff_role'])) {
                $where[] = "bs.role = ?";
                $params[] = $_POST['filter_staff_role'];
            }
            if (!empty($_POST['filter_staff_bus_id'])) {
                $busFilter = $_POST['filter_staff_bus_id'];
                if ($busFilter === 'unassigned') {
                    $where[] = "NOT EXISTS (SELECT 1 FROM bus_staff_assignments bsa WHERE bsa.staff_id = bs.id)";
                } else {
                    $where[] = "EXISTS (SELECT 1 FROM bus_staff_assignments bsa WHERE bsa.staff_id = bs.id AND bsa.bus_id = ?)";
                    $params[] = $busFilter;
                }
            }
            $whereSQL = !empty($where) ? " WHERE " . implode(" AND ", $where) : "";
            $sql = "SELECT bs.id, bs.name, bs.role, bs.phones, bs.notes, 
                           (SELECT GROUP_CONCAT(b.bus_number ORDER BY b.bus_number SEPARATOR '، ') 
                            FROM bus_staff_assignments bsa 
                            JOIN buses b ON bsa.bus_id = b.id 
                            WHERE bsa.staff_id = bs.id) as bus_number
                    FROM bus_staff bs" . $whereSQL . " ORDER BY bs.role, bs.name";
            
            $stmt = $db->prepare($sql);
            $stmt->execute($params);
            $resultsData = $stmt->fetchAll(PDO::FETCH_ASSOC);

            // تصدير Excel
            if ($exportFormat === 'excel') {
                $excel_handler = new ExcelHandler();
                if (ob_get_level() > 0) ob_clean();

                $excelData = [];
                $excelHeaders = [];
                foreach ($selectedFields as $f) {
                    if (isset($staffFields[$f])) $excelHeaders[] = $staffFields[$f];
                }
                $excelData[] = $excelHeaders;

                foreach ($resultsData as $row) {
                    $excelRow = [];
                    foreach ($selectedFields as $f) {
                        $excelRow[] = formatStaffValue($f, $row);
                    }
                    $excelData[] = $excelRow;
                }

                $filepath = $excel_handler->exportToExcel($excelData, 'تقرير_طاقم_الحافلات');
                if ($filepath && file_exists($filepath)) {
                    header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
                    header('Content-Disposition: attachment; filename="تقرير_طاقم_الحافلات_' . date('Y-m-d') . '.xlsx"');
                    readfile($filepath);
                    unlink($filepath);
                    exit;
                }
            }
        }

        // 4. تصفية ومعالجة تاب قوائم الحافلات (Manifests)
        elseif ($activeTab === 'manifests') {
            $manifestBusId = (int)($_POST['filter_manifest_bus_id'] ?? 0);
            $manifestAssignType = $_POST['filter_manifest_assign_type'] ?? '';

            if ($manifestBusId > 0) {
                // جلب بيانات الحافلة
                $stmt = $db->prepare("SELECT * FROM buses WHERE id = ?");
                $stmt->execute([$manifestBusId]);
                $manifestBusData = $stmt->fetch(PDO::FETCH_ASSOC);

                if ($manifestBusData) {
                    // جلب طاقم الحافلة (Many-to-Many via pivot)
                    $stmtS = $db->prepare("SELECT bs.* FROM bus_staff bs JOIN bus_staff_assignments bsa ON bs.id = bsa.staff_id WHERE bsa.bus_id = ? ORDER BY bs.role, bs.id");
                    $stmtS->execute([$manifestBusId]);
                    $manifestStaff = $stmtS->fetchAll(PDO::FETCH_ASSOC);

                    // جلب طلاب الحافلة
                    $where = ["u.status = 'active'", 'u.deleted_at IS NULL'];
                    $params = [];

                    if ($manifestAssignType === 'primary') {
                        $where[] = "sba.bus_id = ?";
                        $params[] = $manifestBusId;
                    } elseif ($manifestAssignType === 'backup') {
                        $where[] = "sba.backup_bus_id = ?";
                        $params[] = $manifestBusId;
                    } else {
                        $where[] = "(sba.bus_id = ? OR sba.backup_bus_id = ?)";
                        $params[] = $manifestBusId;
                        $params[] = $manifestBusId;
                    }

                    $whereSQL = implode(' AND ', $where);
                    
                    $enrollJoin = $currentAcademicYearId > 0
                        ? "JOIN student_enrollments se ON se.student_id = u.id AND se.academic_year_id = {$currentAcademicYearId} AND se.enrollment_status = 'enrolled'
                           LEFT JOIN classes c ON c.id = se.class_id"
                        : "LEFT JOIN classes c ON u.class_id = c.id";

                    $sql = "SELECT u.id, u.name as student_name, c.name as class_name, g.grade_name,
                                   sba.notes as bus_notes, sba.bus_id, sba.backup_bus_id,
                                   sg.guardian_name, sg.phone_primary, sg.phone_secondary
                            FROM student_bus_assignments sba
                            JOIN users u ON sba.student_id = u.id
                            {$enrollJoin}
                            LEFT JOIN grades g ON c.grade_id = g.id
                            LEFT JOIN student_guardians sg ON u.id = sg.student_id AND sg.is_primary = 1
                            WHERE {$whereSQL}" . ($currentAcademicYearId > 0 ? " AND sba.academic_year_id = {$currentAcademicYearId}" : "") . "
                            ORDER BY u.name";
                    $stmtSt = $db->prepare($sql);
                    $stmtSt->execute($params);
                    $manifestStudents = $stmtSt->fetchAll(PDO::FETCH_ASSOC);
                }
            }

            // تصدير Excel لكشف قوائم الحافلة
            if ($exportFormat === 'excel' && $manifestBusData) {
                $excel_handler = new ExcelHandler();
                if (ob_get_level() > 0) ob_clean();

                $excelData = [];
                $drivers = array_filter($manifestStaff, fn($s) => $s['role'] === 'driver');
                $supervisors = array_filter($manifestStaff, fn($s) => $s['role'] === 'supervisor');
                
                $driverStr = implode(', ', array_map(fn($d) => $d['name'] . ' (' . $d['phones'] . ')', $drivers));
                $supervisorStr = implode(', ', array_map(fn($s) => $s['name'] . ' (' . $s['phones'] . ')', $supervisors));

                $excelData[] = ['كشف ركوب حافلة رقم:', $manifestBusData['bus_number'] ?? '-'];
                $excelData[] = ['المناطق الجغرافية:', $manifestBusData['area'] ?? '-'];
                $excelData[] = ['السائقين:', $driverStr ?: '—'];
                $excelData[] = ['المشرفين:', $supervisorStr ?: '—'];
                $excelData[] = []; // سطر فارغ فاصل

                $excelHeaders = [];
                foreach ($selectedFields as $f) {
                    if (isset($manifestFields[$f])) $excelHeaders[] = $manifestFields[$f];
                }
                $excelData[] = $excelHeaders;

                $counter = 0;
                foreach ($manifestStudents as $student) {
                    $counter++;
                    $excelRow = [];
                    foreach ($selectedFields as $f) {
                        $excelRow[] = formatManifestValue($f, $student, $counter);
                    }
                    $excelData[] = $excelRow;
                }

                $filepath = $excel_handler->exportToExcel($excelData, 'كشف_ركوب_حافلة_' . ($manifestBusData['bus_number'] ?? ''));
                if ($filepath && file_exists($filepath)) {
                    header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
                    header('Content-Disposition: attachment; filename="كشف_حافلة_' . ($manifestBusData['bus_number'] ?? '') . '_' . date('Y-m-d') . '.xlsx"');
                    readfile($filepath);
                    unlink($filepath);
                    exit;
                }
            }
        }

        // معالجة الطباعة المباشرة (فتح حوار الطباعة)
        if ($exportFormat === 'pdf') {
            $showPrintView = true;
        }
    }
}

// جلب إحصائيات سريعة للبطاقات
$assignedCountSql = "SELECT COUNT(*) FROM student_bus_assignments sba
    JOIN users u ON u.id = sba.student_id
    WHERE u.status = 'active' AND u.deleted_at IS NULL";
if ($currentAcademicYearId > 0) {
    $assignedCountSql .= " AND sba.academic_year_id = {$currentAcademicYearId}";
}
$assignedCount = (int)$db->query($assignedCountSql)->fetchColumn();
$totalStudentsCountQuery = "SELECT COUNT(*) FROM users u " . 
    ($currentAcademicYearId > 0 
        ? "JOIN student_enrollments se ON se.student_id = u.id AND se.academic_year_id = {$currentAcademicYearId} AND se.enrollment_status = 'enrolled'"
        : "") . 
    " WHERE u.role = 'student' AND u.status = 'active' AND u.deleted_at IS NULL";
$totalStudentsCount = (int)$db->query($totalStudentsCountQuery)->fetchColumn();
$unassignedCount = max(0, $totalStudentsCount - $assignedCount);
$activeBusesCount = (int)$db->query("SELECT COUNT(*) FROM buses WHERE status='active'")->fetchColumn();

// نسبة إشغال الحافلات الكلية
$capacitySum = (int)$db->query("SELECT SUM(capacity) FROM buses WHERE status='active'")->fetchColumn();
$occupancyRate = $capacitySum > 0 ? round(($assignedCount / $capacitySum) * 100) : 0;

require_once '../includes/admin_header.php';
echo FinanceLegacyAdapter::bridgeNotice(__FILE__);
?>

<style>
.field-group { border: 1px solid #dee2e6; border-radius: 8px; padding: 15px; margin-bottom: 15px; background: #f8f9fa; }
.field-group h6 { color: #495057; margin-bottom: 12px; border-bottom: 2px solid #3b82f6; padding-bottom: 6px; }
.field-checkbox { display: inline-flex; align-items: center; min-width: 220px; padding: 4px 8px; margin: 2px; border-radius: 4px; transition: background 0.2s; }
.field-checkbox:hover { background: #e2e6ea; }
.field-checkbox input { margin-left: 8px; }
.filter-card { border-radius: 10px; border: 1px solid #dee2e6; }
.filter-card .card-header { background: linear-gradient(135deg, #3b82f6, #2563eb); color: white; border-radius: 10px 10px 0 0; }
.export-actions-bar { position: sticky; top: 70px; z-index: 100; }
.preview-table { font-size: 0.85rem; }
.preview-table th { background: #3b82f6; color: white; white-space: nowrap; }
.preview-table td { white-space: nowrap; }
.group-toggle { cursor: pointer; user-select: none; }
.group-toggle:hover { color: #2563eb; }

@media print {
    .no-print, .sidebar, .navbar, .btn-toolbar, .filter-section, .export-actions-bar, .stat-card-row, .nav-tabs { display: none !important; }
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
    <h1 class="h2"><i class="fas fa-file-alt me-2 text-primary"></i>تقارير الحركة والتنقلات</h1>
    <div class="admin-top-actions no-print">
        <button type="button" id="topBtn-preview" class="btn btn-header-premium btn-pdf-soft">
            <i class="fas fa-eye me-2"></i>معاينة التقرير
        </button>
        <button type="button" id="topBtn-excel" class="btn btn-header-premium btn-export-soft">
            <i class="fas fa-file-excel me-2"></i>تصدير Excel
        </button>
        <button type="button" id="topBtn-print" class="btn btn-header-premium btn-print-soft">
            <i class="fas fa-print me-2"></i>عرض للطباعة
        </button>
    </div>
</div>

<!-- Stat Cards -->
<div class="row row-cols-2 row-cols-md-4 g-3 mb-4 no-print">
    <div class="col">
        <div class="stat-card" style="--card-gradient: linear-gradient(135deg, #3b82f6, #2563eb);">
            <div class="stat-card-icon"><i class="fas fa-user-check"></i></div>
            <div class="stat-card-info">
                <div class="stat-card-number counter" data-target="<?php echo $assignedCount; ?>">0</div>
                <div class="stat-card-label">طلاب معينين بحافلة</div>
            </div>
        </div>
    </div>
    <div class="col">
        <div class="stat-card" style="--card-gradient: linear-gradient(135deg, #f59e0b, #d97706);">
            <div class="stat-card-icon"><i class="fas fa-user-slash"></i></div>
            <div class="stat-card-info">
                <div class="stat-card-number counter" data-target="<?php echo $unassignedCount; ?>">0</div>
                <div class="stat-card-label">طلاب بدون حافلة</div>
            </div>
        </div>
    </div>
    <div class="col">
        <div class="stat-card" style="--card-gradient: linear-gradient(135deg, #10b981, #059669);">
            <div class="stat-card-icon"><i class="fas fa-bus"></i></div>
            <div class="stat-card-info">
                <div class="stat-card-number counter" data-target="<?php echo $activeBusesCount; ?>">0</div>
                <div class="stat-card-label">الحافلات النشطة</div>
            </div>
        </div>
    </div>
    <div class="col">
        <div class="stat-card" style="--card-gradient: linear-gradient(135deg, #8b5cf6, #7c3aed);">
            <div class="stat-card-icon"><i class="fas fa-percentage"></i></div>
            <div class="stat-card-info">
                <div class="stat-card-number"><span class="counter" data-target="<?php echo $occupancyRate; ?>">0</span>%</div>
                <div class="stat-card-label">نسبة الإشغال الكلية</div>
            </div>
        </div>
    </div>
</div>

<!-- نظام التابات (Tabs) -->
<ul class="nav nav-tabs mb-4 border-bottom admin-tabs no-print" id="reportTabs" role="tablist">
    <li class="nav-item" role="presentation">
        <button class="nav-link <?php echo $activeTab === 'geographic' ? 'active' : ''; ?>" id="tab-geographic" data-bs-toggle="tab" data-bs-target="#pane-geographic" type="button" role="tab"><i class="fas fa-map-marked-alt me-2"></i>المناطق الجغرافية</button>
    </li>
    <li class="nav-item" role="presentation">
        <button class="nav-link <?php echo $activeTab === 'buses' ? 'active' : ''; ?>" id="tab-buses" data-bs-toggle="tab" data-bs-target="#pane-buses" type="button" role="tab"><i class="fas fa-bus me-2"></i>الحافلات</button>
    </li>
    <li class="nav-item" role="presentation">
        <button class="nav-link <?php echo $activeTab === 'staff' ? 'active' : ''; ?>" id="tab-staff" data-bs-toggle="tab" data-bs-target="#pane-staff" type="button" role="tab"><i class="fas fa-users-cog me-2"></i>طاقم الحافلات</button>
    </li>
    <li class="nav-item" role="presentation">
        <button class="nav-link <?php echo $activeTab === 'manifests' ? 'active' : ''; ?>" id="tab-manifests" data-bs-toggle="tab" data-bs-target="#pane-manifests" type="button" role="tab"><i class="fas fa-clipboard-list me-2"></i>قوائم الحافلات</button>
    </li>
</ul>

<div class="tab-content" id="reportTabsContent">
    
    <!-- التاب الأول: المناطق الجغرافية -->
    <div class="tab-pane fade <?php echo $activeTab === 'geographic' ? 'show active' : ''; ?>" id="pane-geographic" role="tabpanel">
        <form method="POST" action="?tab=geographic" id="form-geographic">
            <?php echo csrfField(); ?>
            <input type="hidden" name="active_tab" class="active-tab-input" value="geographic">
            
            <div class="admin-filter-bar no-print mb-4">
                <div class="admin-filter-controls">
                    <select name="filter_geo_level" class="form-select form-select-sm" style="min-width:180px;">
                        <option value="">المستوى: الكل</option>
                        <option value="governorates" <?php echo ($_POST['filter_geo_level'] ?? '') === 'governorates' ? 'selected' : ''; ?>>المحافظات</option>
                        <option value="cities" <?php echo ($_POST['filter_geo_level'] ?? '') === 'cities' ? 'selected' : ''; ?>>المدن</option>
                        <option value="centers" <?php echo ($_POST['filter_geo_level'] ?? '') === 'centers' ? 'selected' : ''; ?>>المراكز</option>
                        <option value="neighborhoods" <?php echo ($_POST['filter_geo_level'] ?? '') === 'neighborhoods' ? 'selected' : ''; ?>>الأحياء والمناطق</option>
                        <option value="streets" <?php echo ($_POST['filter_geo_level'] ?? '') === 'streets' ? 'selected' : ''; ?>>الشوارع</option>
                    </select>
                    <select name="filter_geo_status" class="form-select form-select-sm ms-2" style="min-width:120px;">
                        <option value="">الحالة: الكل</option>
                        <option value="active" <?php echo ($_POST['filter_geo_status'] ?? '') === 'active' ? 'selected' : ''; ?>>نشط</option>
                        <option value="inactive" <?php echo ($_POST['filter_geo_status'] ?? '') === 'inactive' ? 'selected' : ''; ?>>معطل</option>
                    </select>
                </div>
                <div class="admin-filter-actions gap-2">
                    <a href="bus_report.php?tab=geographic" class="btn btn-light btn-sm" title="إعادة تعيين"><i class="fas fa-rotate-left me-1"></i>إعادة تعيين</a>
                    <button type="button" class="btn btn-light btn-sm" data-bs-toggle="modal" data-bs-target="#geoColumnsModal" title="إعدادات الطباعة"><i class="fas fa-print me-1"></i>إعدادات الطباعة</button>
                    <input type="hidden" name="do_export" value="1">
                    <input type="hidden" name="export_format" id="exportFormatInput-geo" value="preview">
                </div>
            </div>

            <!-- Modal customization for columns -->
            <div class="modal fade" id="geoColumnsModal" tabindex="-1" aria-hidden="true">
                <div class="modal-dialog">
                    <div class="modal-content admin-modal admin-modal-premium admin-modal-create">
                        <div class="modal-header">
                            <h5 class="modal-title"><i class="fas fa-cog me-2"></i>إعدادات طباعة تقرير المناطق</h5>
                            <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                        </div>
                        <div class="modal-body">
                            <p class="text-muted small mb-3">اختر الأعمدة التي تريد تضمينها في التقرير:</p>
                            <div class="d-flex justify-content-end gap-2 mb-3">
                                <button type="button" class="btn btn-sm btn-outline-primary select-all-btn"><i class="fas fa-check-double me-1"></i>تحديد الكل</button>
                                <button type="button" class="btn btn-sm btn-outline-secondary deselect-all-btn"><i class="fas fa-times me-1"></i>إلغاء الكل</button>
                            </div>
                            <div class="row g-2">
                                <?php foreach ($geoFields as $fKey => $fVal): 
                                    $checked = isset($_POST['fields']) ? (in_array($fKey, $_POST['fields']) ? 'checked' : '') : 'checked';
                                ?>
                                <div class="col-6">
                                    <div class="form-check">
                                        <input class="form-check-input" type="checkbox" name="fields[]" id="geo_f_<?php echo $fKey; ?>" value="<?php echo $fKey; ?>" <?php echo $checked; ?>>
                                        <label class="form-check-label" for="geo_f_<?php echo $fKey; ?>"><?php echo $fVal; ?></label>
                                    </div>
                                </div>
                                <?php endforeach; ?>
                            </div>
                        </div>
                        <div class="modal-footer">
                            <button type="button" class="btn btn-secondary" data-bs-dismiss="modal"><i class="fas fa-check me-1"></i>حفظ وإغلاق</button>
                        </div>
                    </div>
                </div>
            </div>
        </form>
    </div>

    <!-- التاب الثاني: الحافلات -->
    <div class="tab-pane fade <?php echo $activeTab === 'buses' ? 'show active' : ''; ?>" id="pane-buses" role="tabpanel">
        <form method="POST" action="?tab=buses" id="form-buses">
            <?php echo csrfField(); ?>
            <input type="hidden" name="active_tab" class="active-tab-input" value="buses">

            <div class="admin-filter-bar no-print mb-4">
                <div class="admin-filter-controls">
                    <select name="filter_bus_id" class="form-select form-select-sm" style="min-width:220px;">
                        <option value="">الحافلة: الكل (جميع الحافلات)</option>
                        <?php foreach ($allBuses as $b): ?>
                            <option value="<?php echo $b['id']; ?>" <?php echo ($_POST['filter_bus_id'] ?? '') == $b['id'] ? 'selected' : ''; ?>>الحافلة رقم: <?php echo htmlspecialchars($b['bus_number']); ?> <?php if ($b['area']): ?> (<?php echo htmlspecialchars($b['area']); ?>)<?php endif; ?></option>
                        <?php endforeach; ?>
                    </select>
                    <select name="filter_status" class="form-select form-select-sm ms-2" style="min-width:120px;">
                        <option value="">الحالة: الكل</option>
                        <option value="active" <?php echo ($_POST['filter_status'] ?? '') === 'active' ? 'selected' : ''; ?>>نشط</option>
                        <option value="inactive" <?php echo ($_POST['filter_status'] ?? '') === 'inactive' ? 'selected' : ''; ?>>معطل</option>
                    </select>
                </div>
                <div class="admin-filter-actions gap-2">
                    <a href="bus_report.php?tab=buses" class="btn btn-light btn-sm" title="إعادة تعيين"><i class="fas fa-rotate-left me-1"></i>إعادة تعيين</a>
                    <button type="button" class="btn btn-light btn-sm" data-bs-toggle="modal" data-bs-target="#busColumnsModal" title="إعدادات الطباعة"><i class="fas fa-print me-1"></i>إعدادات الطباعة</button>
                    <input type="hidden" name="do_export" value="1">
                    <input type="hidden" name="export_format" id="exportFormatInput-bus" value="preview">
                </div>
            </div>

            <!-- Modal customization for columns -->
            <div class="modal fade" id="busColumnsModal" tabindex="-1" aria-hidden="true">
                <div class="modal-dialog">
                    <div class="modal-content admin-modal admin-modal-premium admin-modal-create">
                        <div class="modal-header">
                            <h5 class="modal-title"><i class="fas fa-cog me-2"></i>إعدادات طباعة تقرير الحافلات</h5>
                            <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                        </div>
                        <div class="modal-body">
                            <p class="text-muted small mb-3">اختر الأعمدة التي تريد تضمينها في التقرير:</p>
                            <div class="d-flex justify-content-end gap-2 mb-3">
                                <button type="button" class="btn btn-sm btn-outline-primary select-all-btn"><i class="fas fa-check-double me-1"></i>تحديد الكل</button>
                                <button type="button" class="btn btn-sm btn-outline-secondary deselect-all-btn"><i class="fas fa-times me-1"></i>إلغاء الكل</button>
                            </div>
                            <div class="row g-2">
                                <?php foreach ($busFields as $fKey => $fVal): 
                                    $checked = isset($_POST['fields']) ? (in_array($fKey, $_POST['fields']) ? 'checked' : '') : 'checked';
                                ?>
                                <div class="col-6">
                                    <div class="form-check">
                                        <input class="form-check-input" type="checkbox" name="fields[]" id="bus_f_<?php echo $fKey; ?>" value="<?php echo $fKey; ?>" <?php echo $checked; ?>>
                                        <label class="form-check-label" for="bus_f_<?php echo $fKey; ?>"><?php echo $fVal; ?></label>
                                    </div>
                                </div>
                                <?php endforeach; ?>
                            </div>
                        </div>
                        <div class="modal-footer">
                            <button type="button" class="btn btn-secondary" data-bs-dismiss="modal"><i class="fas fa-check me-1"></i>حفظ وإغلاق</button>
                        </div>
                    </div>
                </div>
            </div>
        </form>
    </div>

    <!-- التاب الثالث: طاقم الحافلات -->
    <div class="tab-pane fade <?php echo $activeTab === 'staff' ? 'show active' : ''; ?>" id="pane-staff" role="tabpanel">
        <form method="POST" action="?tab=staff" id="form-staff">
            <?php echo csrfField(); ?>
            <input type="hidden" name="active_tab" class="active-tab-input" value="staff">

            <div class="admin-filter-bar no-print mb-4">
                <div class="admin-filter-controls">
                    <select name="filter_staff_role" class="form-select form-select-sm" style="min-width:120px;">
                        <option value="">الدور: الكل</option>
                        <option value="driver" <?php echo ($_POST['filter_staff_role'] ?? '') === 'driver' ? 'selected' : ''; ?>>سائق</option>
                        <option value="supervisor" <?php echo ($_POST['filter_staff_role'] ?? '') === 'supervisor' ? 'selected' : ''; ?>>مشرف</option>
                    </select>
                    <select name="filter_staff_bus_id" class="form-select form-select-sm ms-2" style="min-width:220px;">
                        <option value="">الحافلة المعين عليها: الكل</option>
                        <option value="unassigned" <?php echo ($_POST['filter_staff_bus_id'] ?? '') === 'unassigned' ? 'selected' : ''; ?>>غير معين على حافلة</option>
                        <?php foreach ($allBuses as $b): ?>
                            <option value="<?php echo $b['id']; ?>" <?php echo ($_POST['filter_staff_bus_id'] ?? '') == $b['id'] ? 'selected' : ''; ?>>الحافلة رقم: <?php echo htmlspecialchars($b['bus_number']); ?> <?php if ($b['area']): ?> (<?php echo htmlspecialchars($b['area']); ?>)<?php endif; ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="admin-filter-actions gap-2">
                    <a href="bus_report.php?tab=staff" class="btn btn-light btn-sm" title="إعادة تعيين"><i class="fas fa-rotate-left me-1"></i>إعادة تعيين</a>
                    <button type="button" class="btn btn-light btn-sm" data-bs-toggle="modal" data-bs-target="#staffColumnsModal" title="إعدادات الطباعة"><i class="fas fa-print me-1"></i>إعدادات الطباعة</button>
                    <input type="hidden" name="do_export" value="1">
                    <input type="hidden" name="export_format" id="exportFormatInput-staff" value="preview">
                </div>
            </div>

            <!-- Modal customization for columns -->
            <div class="modal fade" id="staffColumnsModal" tabindex="-1" aria-hidden="true">
                <div class="modal-dialog">
                    <div class="modal-content admin-modal admin-modal-premium admin-modal-create">
                        <div class="modal-header">
                            <h5 class="modal-title"><i class="fas fa-cog me-2"></i>إعدادات طباعة تقرير طاقم الحافلات</h5>
                            <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                        </div>
                        <div class="modal-body">
                            <p class="text-muted small mb-3">اختر الأعمدة التي تريد تضمينها في التقرير:</p>
                            <div class="d-flex justify-content-end gap-2 mb-3">
                                <button type="button" class="btn btn-sm btn-outline-primary select-all-btn"><i class="fas fa-check-double me-1"></i>تحديد الكل</button>
                                <button type="button" class="btn btn-sm btn-outline-secondary deselect-all-btn"><i class="fas fa-times me-1"></i>إلغاء الكل</button>
                            </div>
                            <div class="row g-2">
                                <?php foreach ($staffFields as $fKey => $fVal): 
                                    $checked = isset($_POST['fields']) ? (in_array($fKey, $_POST['fields']) ? 'checked' : '') : 'checked';
                                ?>
                                <div class="col-6">
                                    <div class="form-check">
                                        <input class="form-check-input" type="checkbox" name="fields[]" id="staff_f_<?php echo $fKey; ?>" value="<?php echo $fKey; ?>" <?php echo $checked; ?>>
                                        <label class="form-check-label" for="staff_f_<?php echo $fKey; ?>"><?php echo $fVal; ?></label>
                                    </div>
                                </div>
                                <?php endforeach; ?>
                            </div>
                        </div>
                        <div class="modal-footer">
                            <button type="button" class="btn btn-secondary" data-bs-dismiss="modal"><i class="fas fa-check me-1"></i>حفظ وإغلاق</button>
                        </div>
                    </div>
                </div>
            </div>
        </form>
    </div>

    <!-- التاب الرابع: قوائم الحافلات (Manifests) -->
    <div class="tab-pane fade <?php echo $activeTab === 'manifests' ? 'show active' : ''; ?>" id="pane-manifests" role="tabpanel">
        <form method="POST" action="?tab=manifests" id="form-manifests">
            <?php echo csrfField(); ?>
            <input type="hidden" name="active_tab" class="active-tab-input" value="manifests">

            <div class="admin-filter-bar no-print mb-4">
                <div class="admin-filter-controls">
                    <select name="filter_manifest_bus_id" class="form-select form-select-sm" style="min-width:220px;" required>
                        <option value="">الحافلة: اختر حافلة للاستخراج *</option>
                        <?php foreach ($allBuses as $b): ?>
                            <option value="<?php echo $b['id']; ?>" <?php echo ($_POST['filter_manifest_bus_id'] ?? '') == $b['id'] ? 'selected' : ''; ?>>الحافلة رقم: <?php echo htmlspecialchars($b['bus_number']); ?> <?php if ($b['area']): ?> (<?php echo htmlspecialchars($b['area']); ?>)<?php endif; ?></option>
                        <?php endforeach; ?>
                    </select>
                    <select name="filter_manifest_assign_type" class="form-select form-select-sm ms-2" style="min-width:180px;">
                        <option value="">نوع التعيين: الكل (أساسي واحتياطي)</option>
                        <option value="primary" <?php echo ($_POST['filter_manifest_assign_type'] ?? '') === 'primary' ? 'selected' : ''; ?>>أساسي فقط</option>
                        <option value="backup" <?php echo ($_POST['filter_manifest_assign_type'] ?? '') === 'backup' ? 'selected' : ''; ?>>احتياطي فقط</option>
                    </select>
                </div>
                <div class="admin-filter-actions gap-2">
                    <a href="bus_report.php?tab=manifests" class="btn btn-light btn-sm" title="إعادة تعيين"><i class="fas fa-rotate-left me-1"></i>إعادة تعيين</a>
                    <button type="button" class="btn btn-light btn-sm" data-bs-toggle="modal" data-bs-target="#manifestColumnsModal" title="إعدادات الطباعة"><i class="fas fa-print me-1"></i>إعدادات الطباعة</button>
                    <input type="hidden" name="do_export" value="1">
                    <input type="hidden" name="export_format" id="exportFormatInput-manifest" value="preview">
                </div>
            </div>

            <!-- Modal customization for columns -->
            <div class="modal fade" id="manifestColumnsModal" tabindex="-1" aria-hidden="true">
                <div class="modal-dialog">
                    <div class="modal-content admin-modal admin-modal-premium admin-modal-create">
                        <div class="modal-header">
                            <h5 class="modal-title"><i class="fas fa-cog me-2"></i>إعدادات طباعة كشف الطلاب</h5>
                            <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                        </div>
                        <div class="modal-body">
                            <p class="text-muted small mb-3">اختر الأعمدة التي تريد تضمينها في الكشف:</p>
                            <div class="d-flex justify-content-end gap-2 mb-3">
                                <button type="button" class="btn btn-sm btn-outline-primary select-all-btn"><i class="fas fa-check-double me-1"></i>تحديد الكل</button>
                                <button type="button" class="btn btn-sm btn-outline-secondary deselect-all-btn"><i class="fas fa-times me-1"></i>إلغاء الكل</button>
                            </div>
                            <div class="row g-2">
                                <?php foreach ($manifestFields as $fKey => $fVal): 
                                    $checked = isset($_POST['fields']) ? (in_array($fKey, $_POST['fields']) ? 'checked' : '') : 'checked';
                                ?>
                                <div class="col-6">
                                    <div class="form-check">
                                        <input class="form-check-input" type="checkbox" name="fields[]" id="manifest_f_<?php echo $fKey; ?>" value="<?php echo $fKey; ?>" <?php echo $checked; ?>>
                                        <label class="form-check-label" for="manifest_f_<?php echo $fKey; ?>"><?php echo $fVal; ?></label>
                                    </div>
                                </div>
                                <?php endforeach; ?>
                            </div>
                        </div>
                        <div class="modal-footer">
                            <button type="button" class="btn btn-secondary" data-bs-dismiss="modal"><i class="fas fa-check me-1"></i>حفظ وإغلاق</button>
                        </div>
                    </div>
                </div>
            </div>
        </form>
    </div>

</div>

<!-- ============================================
عرض نتائج التقرير والمعاينة
============================================ -->
<?php if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['do_export'])): ?>
    
    <!-- معاينة المناطق الجغرافية -->
    <?php if ($activeTab === 'geographic' && !empty($selectedFields)): ?>
    <div class="admin-list-surface mb-4">
        <div class="p-3 border-bottom d-flex justify-content-between align-items-center bg-white no-print">
            <h5 class="mb-0 fw-bold text-primary"><i class="fas fa-table me-2"></i>معاينة المناطق الجغرافية (<?php echo count($resultsData); ?> سجل)</h5>
            <span class="badge bg-primary-subtle text-primary"><?php echo count($selectedFields); ?> حقل محدد</span>
        </div>
        <div class="table-responsive admin-table-wrap" style="max-height: 500px; overflow-y: auto;">
            <table class="table table-hover table-striped align-middle admin-data-table mb-0">
                <thead>
                    <tr>
                        <th width="40">#</th>
                        <?php foreach ($selectedFields as $f): ?>
                            <th><?php echo htmlspecialchars($geoFields[$f] ?? $f); ?></th>
                        <?php endforeach; ?>
                    </tr>
                </thead>
                <tbody>
                    <?php $n = 0; foreach ($resultsData as $row): $n++; ?>
                    <tr>
                        <td><?php echo $n; ?></td>
                        <?php foreach ($selectedFields as $f): ?>
                            <td><?php echo htmlspecialchars(formatGeoValue($f, $row)); ?></td>
                        <?php endforeach; ?>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
    <?php endif; ?>

    <!-- معاينة الحافلات -->
    <?php if ($activeTab === 'buses' && !empty($selectedFields)): ?>
    <div class="admin-list-surface mb-4">
        <div class="p-3 border-bottom d-flex justify-content-between align-items-center bg-white no-print">
            <h5 class="mb-0 fw-bold text-primary"><i class="fas fa-table me-2"></i>معاينة بيانات الحافلات (<?php echo count($resultsData); ?> حافلة)</h5>
            <span class="badge bg-primary-subtle text-primary"><?php echo count($selectedFields); ?> حقل محدد</span>
        </div>
        <div class="table-responsive admin-table-wrap" style="max-height: 500px; overflow-y: auto;">
            <table class="table table-hover table-striped align-middle admin-data-table mb-0">
                <thead>
                    <tr>
                        <th width="40">#</th>
                        <?php foreach ($selectedFields as $f): ?>
                            <th><?php echo htmlspecialchars($busFields[$f] ?? $f); ?></th>
                        <?php endforeach; ?>
                    </tr>
                </thead>
                <tbody>
                    <?php $n = 0; foreach ($resultsData as $row): $n++; ?>
                    <tr>
                        <td><?php echo $n; ?></td>
                        <?php foreach ($selectedFields as $f): ?>
                            <td><?php echo htmlspecialchars(formatBusValue($f, $row, $busStaffMap)); ?></td>
                        <?php endforeach; ?>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
    <?php endif; ?>

    <!-- معاينة طاقم الحافلات -->
    <?php if ($activeTab === 'staff' && !empty($selectedFields)): ?>
    <div class="admin-list-surface mb-4">
        <div class="p-3 border-bottom d-flex justify-content-between align-items-center bg-white no-print">
            <h5 class="mb-0 fw-bold text-primary"><i class="fas fa-table me-2"></i>معاينة طاقم الحافلات (<?php echo count($resultsData); ?> عضو)</h5>
            <span class="badge bg-primary-subtle text-primary"><?php echo count($selectedFields); ?> حقل محدد</span>
        </div>
        <div class="table-responsive admin-table-wrap" style="max-height: 500px; overflow-y: auto;">
            <table class="table table-hover table-striped align-middle admin-data-table mb-0">
                <thead>
                    <tr>
                        <th width="40">#</th>
                        <?php foreach ($selectedFields as $f): ?>
                            <th><?php echo htmlspecialchars($staffFields[$f] ?? $f); ?></th>
                        <?php endforeach; ?>
                    </tr>
                </thead>
                <tbody>
                    <?php $n = 0; foreach ($resultsData as $row): $n++; ?>
                    <tr>
                        <td><?php echo $n; ?></td>
                        <?php foreach ($selectedFields as $f): ?>
                            <td><?php echo htmlspecialchars(formatStaffValue($f, $row)); ?></td>
                        <?php endforeach; ?>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
    <?php endif; ?>

    <!-- معاينة قوائم الحافلات (Manifests) -->
    <?php if ($activeTab === 'manifests' && $manifestBusData): ?>
    <div class="admin-list-surface mb-4">
        <div class="p-3 border-bottom d-flex justify-content-between align-items-center bg-white no-print">
            <h5 class="mb-0 fw-bold text-primary"><i class="fas fa-print me-2"></i>كشف ركوب حافلة رقم: <?php echo htmlspecialchars($manifestBusData['bus_number']); ?></h5>
            <span class="badge bg-primary-subtle text-primary">معاينة الكشف</span>
        </div>
        <div class="p-3">
            <!-- ترويسة الكشف المخصصة والمعلومات الأساسية -->
            <div class="manifest-header-box p-3 mb-4 border rounded bg-light">
                <div class="row">
                    <div class="col-md-6 mb-2">
                        <strong>رقم الحافلة:</strong> <span class="badge bg-primary fs-6"><?php echo htmlspecialchars($manifestBusData['bus_number']); ?></span>
                    </div>
                    <div class="col-md-6 mb-2">
                        <strong>المناطق الجغرافية:</strong> <span class="text-secondary"><?php echo htmlspecialchars($manifestBusData['area'] ?? 'غير محدد'); ?></span>
                    </div>
                    <div class="col-md-6 mb-2">
                        <strong>طاقم الحافلة (السائقين):</strong> 
                        <span class="text-secondary">
                            <?php 
                            $drivers = array_filter($manifestStaff, fn($s) => $s['role'] === 'driver');
                            if (empty($drivers)) echo '—';
                            else {
                                $dArr = [];
                                foreach ($drivers as $d) {
                                    $dArr[] = htmlspecialchars($d['name']) . ' (' . htmlspecialchars($d['phones']) . ')';
                                }
                                echo implode(' - ', $dArr);
                            }
                            ?>
                        </span>
                    </div>
                    <div class="col-md-6 mb-2">
                        <strong>طاقم الحافلة (المشرفين):</strong> 
                        <span class="text-secondary">
                            <?php 
                            $supervisors = array_filter($manifestStaff, fn($s) => $s['role'] === 'supervisor');
                            if (empty($supervisors)) echo '—';
                            else {
                                $sArr = [];
                                foreach ($supervisors as $s) {
                                    $sArr[] = htmlspecialchars($s['name']) . ' (' . htmlspecialchars($s['phones']) . ')';
                                }
                                echo implode(' - ', $sArr);
                            }
                            ?>
                        </span>
                    </div>
                </div>
            </div>

            <!-- جدول الطلاب المعينين -->
            <?php if (empty($manifestStudents)): ?>
                <div class="alert alert-warning text-center"><i class="fas fa-exclamation-triangle me-2"></i>لا يوجد طلاب معينين على هذه الحافلة بهذا الفلتر.</div>
            <?php else: ?>
                <div class="table-responsive admin-table-wrap">
                    <table class="table table-hover table-striped align-middle admin-data-table mb-0">
                        <thead>
                            <tr>
                                <?php foreach ($selectedFields as $f): ?>
                                    <th><?php echo htmlspecialchars($manifestFields[$f] ?? $f); ?></th>
                                <?php endforeach; ?>
                            </tr>
                        </thead>
                        <tbody>
                            <?php $counter = 0; foreach ($manifestStudents as $student): $counter++; ?>
                            <tr>
                                <?php foreach ($selectedFields as $f): ?>
                                    <td><?php echo htmlspecialchars(formatManifestValue($f, $student, $counter)); ?></td>
                                <?php endforeach; ?>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php endif; ?>
        </div>
    </div>
    <?php endif; ?>

<?php endif; ?>

<?php if ($showPrintView): ?>
<script>
document.addEventListener('DOMContentLoaded', function() {
    setTimeout(function() { window.print(); }, 500);
});
</script>
<?php endif; ?>

<script>
document.addEventListener('DOMContentLoaded', function() {
    // مزامنة حالة التاب في العنوان URL والحقول المخفية
    var tabEls = document.querySelectorAll('button[data-bs-toggle="tab"]');
    tabEls.forEach(function(tabEl) {
        tabEl.addEventListener('shown.bs.tab', function (event) {
            var targetAttr = event.target.getAttribute('data-bs-target');
            var tabName = targetAttr.replace('#pane-', '');
            
            // تحديث الحقل المخفي في جميع التابات
            document.querySelectorAll('.active-tab-input').forEach(function(input) {
                input.value = tabName;
            });
            
            // تحديث بار العنوان
            var newUrl = new URL(window.location);
            newUrl.searchParams.set('tab', tabName);
            window.history.replaceState({}, '', newUrl);
        });
    });

    // أكشنز التحديد وإلغاء التحديد للـ Checkboxes داخل التابات
    document.querySelectorAll('.select-all-btn').forEach(btn => {
        btn.addEventListener('click', function() {
            var pane = this.closest('.tab-pane');
            pane.querySelectorAll('input[name="fields[]"]').forEach(cb => cb.checked = true);
        });
    });

    document.querySelectorAll('.deselect-all-btn').forEach(btn => {
        btn.addEventListener('click', function() {
            var pane = this.closest('.tab-pane');
            pane.querySelectorAll('input[name="fields[]"]').forEach(cb => cb.checked = false);
        });
    });

    // ربط الأزرار العلوية بالنماذج النشطة في التبويبات
    var setupTopButton = function(btnId, formatValue) {
        var btn = document.getElementById(btnId);
        if (btn) {
            btn.addEventListener('click', function() {
                var activePane = document.querySelector('.tab-pane.active');
                if (activePane) {
                    var form = activePane.querySelector('form');
                    var formatInput = activePane.querySelector('input[name="export_format"]');
                    if (form && formatInput) {
                        formatInput.value = formatValue;
                        if (typeof form.reportValidity === 'function') {
                            if (!form.reportValidity()) {
                                return;
                            }
                        }
                        form.submit();
                    }
                }
            });
        }
    };
    
    setupTopButton('topBtn-preview', 'preview');
    setupTopButton('topBtn-excel', 'excel');
    setupTopButton('topBtn-print', 'pdf');
});
</script>

<?php require_once '../includes/admin_footer.php'; ?>
