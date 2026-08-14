<?php
/**
 * إحصائيات الحركة والنقل - Transport Statistics
 * Professional dashboard for school bus occupancy, staff, and stages distribution.
 */
$page_title = "إحصائيات الحركة والنقل";
$custom_page_title = true;

require_once '../config/database.php';
require_once '../classes/utilities.php';
require_once '../includes/session_config.php';
Utilities::validateSession('admin');

$database = new Database();
$db = $database->getConnection();
$db->exec("SET NAMES 'utf8mb4'");

require_once __DIR__ . '/../classes/AcademicYear.php';
$currentAcademicYearId = AcademicYear::currentId($db);

// Join path for active current academic year students
$enrollJoin = $currentAcademicYearId > 0
    ? "JOIN student_enrollments se ON se.student_id = u.id AND se.academic_year_id = {$currentAcademicYearId} AND se.enrollment_status = 'enrolled'
       LEFT JOIN classes c ON c.id = se.class_id"
    : "LEFT JOIN classes c ON u.class_id = c.id";

// =============== STATS CALCULATIONS ===============
$totalBuses = $db->query("SELECT COUNT(*) FROM buses")->fetchColumn();
$activeBuses = $db->query("SELECT COUNT(*) FROM buses WHERE status = 'active'")->fetchColumn();
$inactiveBuses = $totalBuses - $activeBuses;

$totalCapacity = $db->query("SELECT SUM(capacity) FROM buses WHERE status = 'active'")->fetchColumn() ?: 0;

$assignedStudentsSql = "SELECT COUNT(DISTINCT sba.student_id) 
    FROM student_bus_assignments sba 
    JOIN users u ON u.id = sba.student_id 
    {$enrollJoin}
    WHERE u.status = 'active' AND u.deleted_at IS NULL";
if ($currentAcademicYearId > 0) {
    $assignedStudentsSql .= " AND sba.academic_year_id = {$currentAcademicYearId}";
}
$totalAssignedStudents = $db->query($assignedStudentsSql)->fetchColumn();

$primaryStudentsSql = "SELECT COUNT(DISTINCT sba.student_id) 
    FROM student_bus_assignments sba 
    JOIN users u ON u.id = sba.student_id 
    {$enrollJoin}
    WHERE u.status = 'active' AND u.deleted_at IS NULL AND sba.bus_id IS NOT NULL";
if ($currentAcademicYearId > 0) {
    $primaryStudentsSql .= " AND sba.academic_year_id = {$currentAcademicYearId}";
}
$primaryStudents = $db->query($primaryStudentsSql)->fetchColumn();

$backupStudentsSql = "SELECT COUNT(DISTINCT sba.student_id) 
    FROM student_bus_assignments sba 
    JOIN users u ON u.id = sba.student_id 
    {$enrollJoin}
    WHERE u.status = 'active' AND u.deleted_at IS NULL AND sba.backup_bus_id IS NOT NULL";
if ($currentAcademicYearId > 0) {
    $backupStudentsSql .= " AND sba.academic_year_id = {$currentAcademicYearId}";
}
$backupStudents = $db->query($backupStudentsSql)->fetchColumn();

$driversCount = $db->query("SELECT COUNT(*) FROM bus_staff WHERE role = 'driver'")->fetchColumn();
$supervisorsCount = $db->query("SELECT COUNT(*) FROM bus_staff WHERE role = 'supervisor'")->fetchColumn();

$totalStaff = $driversCount + $supervisorsCount;
$generalOccupancyRate = $totalCapacity > 0 ? round(($primaryStudents / $totalCapacity) * 100) : 0;

// =============== DETAILED BUSES SUMMARY ===============
$busesListSql = "SELECT b.id, b.bus_number, b.capacity, b.status,
    (SELECT COUNT(*) FROM student_bus_assignments sba_count JOIN users su ON su.id = sba_count.student_id AND su.status = 'active' AND su.deleted_at IS NULL WHERE sba_count.bus_id = b.id" . ($currentAcademicYearId > 0 ? " AND sba_count.academic_year_id = {$currentAcademicYearId}" : "") . ") as primary_student_count,
    (SELECT COUNT(*) FROM student_bus_assignments sba_count JOIN users su ON su.id = sba_count.student_id AND su.status = 'active' AND su.deleted_at IS NULL WHERE sba_count.backup_bus_id = b.id" . ($currentAcademicYearId > 0 ? " AND sba_count.academic_year_id = {$currentAcademicYearId}" : "") . ") as backup_student_count,
    (SELECT GROUP_CONCAT(name SEPARATOR ', ') FROM bus_staff WHERE bus_id = b.id AND role = 'driver') as drivers,
    (SELECT GROUP_CONCAT(name SEPARATOR ', ') FROM bus_staff WHERE bus_id = b.id AND role = 'supervisor') as supervisors
    FROM buses b
    ORDER BY b.bus_number";
$busesList = $db->query($busesListSql)->fetchAll(PDO::FETCH_ASSOC);

// =============== STAGE DISTRIBUTION ===============
$stageDistSql = "SELECT COALESCE(s.stage_name, 'غير محدد') as stage_name, COUNT(DISTINCT sba.student_id) as students_count
    FROM student_bus_assignments sba
    JOIN users u ON u.id = sba.student_id
    {$enrollJoin}
    LEFT JOIN grades g ON c.grade_id = g.id
    LEFT JOIN stages s ON g.stage_id = s.id
    WHERE u.status = 'active' AND u.deleted_at IS NULL " . ($currentAcademicYearId > 0 ? "AND sba.academic_year_id = {$currentAcademicYearId}" : "") . "
    GROUP BY s.id
    ORDER BY s.stage_order";
$stageDistribution = $db->query($stageDistSql)->fetchAll(PDO::FETCH_ASSOC);

// =============== GEOGRAPHIC LOCATION COUNTS & STUDENT AREA DISTRIBUTION ===============
$govCount = $db->query("SELECT COUNT(*) FROM governorates")->fetchColumn();
$cityCount = $db->query("SELECT COUNT(*) FROM cities")->fetchColumn();
$centerCount = $db->query("SELECT COUNT(*) FROM centers")->fetchColumn();
$neighCount = $db->query("SELECT COUNT(*) FROM neighborhoods")->fetchColumn();
$streetCount = $db->query("SELECT COUNT(*) FROM streets")->fetchColumn();

$areaDistSql = "SELECT COALESCE(NULLIF(TRIM(sp.city_area), ''), 'غير محدد') as area_name, COUNT(DISTINCT sba.student_id) as students_count
    FROM student_bus_assignments sba
    JOIN users u ON u.id = sba.student_id
    {$enrollJoin}
    LEFT JOIN student_profiles sp ON u.id = sp.user_id
    WHERE u.status = 'active' AND u.deleted_at IS NULL " . ($currentAcademicYearId > 0 ? "AND sba.academic_year_id = {$currentAcademicYearId}" : "") . "
    GROUP BY area_name
    ORDER BY students_count DESC
    LIMIT 5";
$areaDistribution = $db->query($areaDistSql)->fetchAll(PDO::FETCH_ASSOC);

$maxAreaStudents = 0;
foreach ($areaDistribution as $ad) {
    if ($ad['students_count'] > $maxAreaStudents) {
        $maxAreaStudents = $ad['students_count'];
    }
}

// =============== DETAILED REGIONAL STATISTICS (STUDENTS & BUSES PER AREA) ===============
$areaStatsSql = "SELECT COALESCE(NULLIF(TRIM(sp.city_area), ''), 'غير محدد') as area_name,
    COUNT(DISTINCT sba.student_id) as students_count,
    COUNT(DISTINCT sba.bus_id) as buses_count,
    GROUP_CONCAT(DISTINCT b.bus_number ORDER BY b.bus_number SEPARATOR ', ') as bus_numbers,
    GROUP_CONCAT(DISTINCT CASE WHEN bst.role = 'driver' THEN bst.name END ORDER BY bst.name SEPARATOR ', ') as driver_names,
    GROUP_CONCAT(DISTINCT CASE WHEN bst.role = 'driver' THEN bst.phones END ORDER BY bst.name SEPARATOR ', ') as driver_phones
    FROM student_bus_assignments sba
    JOIN users u ON u.id = sba.student_id
    {$enrollJoin}
    LEFT JOIN student_profiles sp ON u.id = sp.user_id
    LEFT JOIN buses b ON sba.bus_id = b.id
    LEFT JOIN bus_staff bst ON bst.bus_id = b.id AND bst.role = 'driver'
    WHERE u.status = 'active' AND u.deleted_at IS NULL " . ($currentAcademicYearId > 0 ? "AND sba.academic_year_id = {$currentAcademicYearId}" : "") . "
    GROUP BY area_name
    ORDER BY students_count DESC";
$areaStatsList = $db->query($areaStatsSql)->fetchAll(PDO::FETCH_ASSOC);

$stageColors = ['#3b82f6', '#10b981', '#f59e0b', '#8b5cf6', '#ef4444', '#0ea5e9'];

require_once '../includes/admin_header.php';
?>

<style>
.chart-legend-dot {
    width: 12px;
    height: 12px;
    border-radius: 3px;
    display: inline-block;
}
.premium-card {
    background: #fff;
    border: none;
    border-radius: 16px;
    box-shadow: 0 4px 20px rgba(0, 0, 0, 0.05);
    overflow: hidden;
}
</style>

<!-- Page Header -->
<div class="d-flex justify-content-between flex-wrap flex-md-nowrap align-items-center pt-3 pb-2 mb-3 border-bottom">
    <h1 class="h2"><i class="fas fa-chart-pie me-2 text-primary"></i>إحصائيات الحركة والنقل</h1>
    <div class="admin-top-actions no-print">
        <button type="button" class="btn btn-header-premium btn-print-soft" data-bs-toggle="offcanvas" data-bs-target="#dashboardSettings" aria-controls="dashboardSettings">
            <i class="fas fa-sliders-h me-1"></i>تخصيص لوحة الإحصائيات
        </button>
        <button onclick="window.print()" class="btn btn-header-premium btn-print-soft">
            <i class="fas fa-print me-1"></i>طباعة
        </button>
    </div>
</div>

<div class="dashboard-canvas sortable-dashboard">

<!-- Stat Cards Grid -->
<div class="row row-cols-2 row-cols-md-3 row-cols-lg-6 g-3 mb-4" id="widget-kpi">
    <!-- Card 1 -->
    <div class="col">
        <div class="stat-card" style="--card-gradient: linear-gradient(135deg, #3b82f6, #2563eb);">
            <div class="stat-card-icon"><i class="fas fa-bus"></i></div>
            <div class="stat-card-info">
                <div class="stat-card-number counter" data-target="<?php echo $totalBuses; ?>">0</div>
                <div class="stat-card-label">إجمالي الحافلات</div>
            </div>
        </div>
    </div>
    <!-- Card 2 -->
    <div class="col">
        <div class="stat-card" style="--card-gradient: linear-gradient(135deg, #10b981, #059669);">
            <div class="stat-card-icon"><i class="fas fa-check-circle"></i></div>
            <div class="stat-card-info">
                <div class="stat-card-number counter" data-target="<?php echo $activeBuses; ?>">0</div>
                <div class="stat-card-label">حافلات نشطة</div>
            </div>
        </div>
    </div>
    <!-- Card 3 -->
    <div class="col">
        <div class="stat-card" style="--card-gradient: linear-gradient(135deg, #ef4444, #dc2626);">
            <div class="stat-card-icon"><i class="fas fa-ban"></i></div>
            <div class="stat-card-info">
                <div class="stat-card-number counter" data-target="<?php echo $inactiveBuses; ?>">0</div>
                <div class="stat-card-label">حافلات متوقفة</div>
            </div>
        </div>
    </div>
    <!-- Card 4 -->
    <div class="col">
        <div class="stat-card" style="--card-gradient: linear-gradient(135deg, #8b5cf6, #7c3aed);">
            <div class="stat-card-icon"><i class="fas fa-user-graduate"></i></div>
            <div class="stat-card-info">
                <div class="stat-card-number counter" data-target="<?php echo $totalAssignedStudents; ?>">0</div>
                <div class="stat-card-label">طلاب مقيدون بالحافلة</div>
            </div>
        </div>
    </div>
    <!-- Card 5 -->
    <div class="col">
        <div class="stat-card" style="--card-gradient: linear-gradient(135deg, #f97316, #ea580c);">
            <div class="stat-card-icon"><i class="fas fa-chair"></i></div>
            <div class="stat-card-info">
                <div class="stat-card-number counter" data-target="<?php echo $totalCapacity; ?>">0</div>
                <div class="stat-card-label">السعة المقعدية</div>
            </div>
        </div>
    </div>
    <!-- Card 6 -->
    <div class="col">
        <div class="stat-card" style="--card-gradient: linear-gradient(135deg, #0ea5e9, #0284c7);">
            <div class="stat-card-icon"><i class="fas fa-percentage"></i></div>
            <div class="stat-card-info">
                <div class="stat-card-number"><span class="counter" data-target="<?php echo $generalOccupancyRate; ?>">0</span>%</div>
                <div class="stat-card-label">نسبة الإشغال العامة</div>
            </div>
        </div>
    </div>
</div>

<!-- Charts Section -->
<div class="row g-4 mb-4 sortable-dashboard" id="widget-charts">
    <!-- Chart 1: Bus Status Distribution -->
    <div class="col-md-6 col-lg-4" id="widget-chart-status">
        <div class="premium-card h-100">
            <div class="card-header bg-white py-3 border-bottom d-flex align-items-center">
                <i class="fas fa-grip-horizontal drag-handle me-2 text-muted" style="cursor: grab;" title="سحب لتغيير الترتيب"></i>
                <h5 class="mb-0 fw-bold"><i class="fas fa-traffic-light me-2 text-primary"></i>حالة الحافلات</h5>
            </div>
            <div class="card-body d-flex flex-column align-items-center justify-content-center py-4">
                <?php if ($totalBuses == 0): ?>
                    <div class="text-center py-4 text-muted">
                        <i class="fas fa-info-circle fa-2x mb-2 opacity-50"></i>
                        <p class="mb-0">لا توجد حافلات لعرضها</p>
                    </div>
                <?php else: ?>
                    <canvas id="statusDonut" width="200" height="200" style="max-width:200px;"></canvas>
                    <div class="mt-4 w-100 px-3">
                        <div class="d-flex align-items-center justify-content-between py-1 border-bottom">
                            <div class="d-flex align-items-center gap-2">
                                <span class="chart-legend-dot" style="background:#10b981;"></span>
                                <small class="fw-bold">حافلات نشطة</small>
                            </div>
                            <small class="fw-bold"><?php echo $activeBuses; ?> (<?php echo $totalBuses > 0 ? round(($activeBuses / $totalBuses) * 100) : 0; ?>%)</small>
                        </div>
                        <div class="d-flex align-items-center justify-content-between py-1">
                            <div class="d-flex align-items-center gap-2">
                                <span class="chart-legend-dot" style="background:#ef4444;"></span>
                                <small class="fw-bold">حافلات متوقفة</small>
                            </div>
                            <small class="fw-bold"><?php echo $inactiveBuses; ?> (<?php echo $totalBuses > 0 ? round(($inactiveBuses / $totalBuses) * 100) : 0; ?>%)</small>
                        </div>
                    </div>
                <?php endif; ?>
            </div>
        </div>
    </div>

    <!-- Chart 2: Student Distribution by Academic Stage -->
    <div class="col-md-6 col-lg-8" id="widget-chart-stages">
        <div class="premium-card h-100">
            <div class="card-header bg-white py-3 border-bottom d-flex align-items-center">
                <i class="fas fa-grip-horizontal drag-handle me-2 text-muted" style="cursor: grab;" title="سحب لتغيير الترتيب"></i>
                <h5 class="mb-0 fw-bold"><i class="fas fa-graduation-cap me-2 text-primary"></i>توزيع الطلاب حسب المراحل</h5>
            </div>
            <div class="card-body d-flex flex-column justify-content-center py-4">
                <?php if (empty($stageDistribution)): ?>
                    <div class="text-center py-5 text-muted">
                        <i class="fas fa-user-graduate fa-3x mb-3 opacity-50"></i>
                        <p class="mb-0">لا يوجد طلاب معينون بالحافلات بعد</p>
                    </div>
                <?php else: ?>
                    <div class="row align-items-center">
                        <div class="col-md-5 text-center">
                            <canvas id="stageDonut" width="200" height="200" style="max-width:200px; margin: 0 auto;"></canvas>
                        </div>
                        <div class="col-md-7 mt-3 mt-md-0">
                            <div class="w-100">
                                <?php foreach ($stageDistribution as $i => $row): ?>
                                <?php $c = $stageColors[$i % count($stageColors)]; ?>
                                <div class="d-flex align-items-center justify-content-between py-2 border-bottom">
                                    <div class="d-flex align-items-center gap-2">
                                        <span class="chart-legend-dot" style="background:<?php echo $c; ?>;"></span>
                                        <span class="fw-bold text-dark" style="font-size:0.9rem;"><?php echo htmlspecialchars($row['stage_name']); ?></span>
                                    </div>
                                    <span class="badge bg-light text-dark fw-bold" style="font-size:0.9rem;"><?php echo (int)$row['students_count']; ?> طالب</span>
                                </div>
                                <?php endforeach; ?>
                            </div>
                        </div>
                    </div>
                <?php endif; ?>
            </div>
        </div>
    </div>
</div>

<!-- Geographic Statistics Section -->
<div class="row g-4 mb-4" id="widget-geo">
    <!-- Top Geographic Areas Bar Chart -->
    <div class="col-md-12">
        <div class="premium-card">
            <div class="card-header bg-white py-3 border-bottom d-flex align-items-center">
                <i class="fas fa-grip-horizontal drag-handle me-2 text-muted" style="cursor: grab;" title="سحب لتغيير الترتيب"></i>
                <h5 class="mb-0 fw-bold"><i class="fas fa-map-marked-alt me-2 text-primary"></i>أكثر المناطق الجغرافية تسجيلاً لطلاب الحافلات</h5>
            </div>
            <div class="card-body py-4">
                <?php if (empty($areaDistribution)): ?>
                    <div class="text-center py-5 text-muted">
                        <i class="fas fa-map-pin fa-3x mb-3 opacity-50"></i>
                        <p class="mb-0">لا توجد بيانات لمحل إقامة الطلاب المعينين بالحافلة</p>
                    </div>
                <?php else: ?>
                    <div class="d-flex flex-column gap-4">
                        <?php foreach ($areaDistribution as $row): 
                            $pct = $maxAreaStudents > 0 ? round(($row['students_count'] / $maxAreaStudents) * 100) : 0;
                        ?>
                            <div>
                                <div class="d-flex justify-content-between align-items-center mb-2">
                                    <span class="fw-bold text-dark"><i class="fas fa-map-marker-alt text-danger me-2"></i><?php echo htmlspecialchars($row['area_name']); ?></span>
                                    <span class="badge bg-primary-subtle text-primary fw-bold" style="font-size: 0.9rem;"><?php echo (int)$row['students_count']; ?> طالب</span>
                                </div>
                                <div class="progress" style="height: 12px; border-radius: 6px;">
                                    <div class="progress-bar bg-primary progress-bar-striped progress-bar-animated" role="progressbar" style="width: <?php echo $pct; ?>%;" aria-valuenow="<?php echo $pct; ?>" aria-valuemin="0" aria-valuemax="100"></div>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>
            </div>
        </div>
    </div>
</div>

<!-- Regional Transportation Statistics Table -->
<div class="premium-card mb-4" id="widget-regional-table">
    <div class="card-header bg-white py-3 border-bottom d-flex align-items-center">
        <i class="fas fa-grip-horizontal drag-handle me-2 text-muted" style="cursor: grab;" title="سحب لتغيير الترتيب"></i>
        <h5 class="mb-0 fw-bold"><i class="fas fa-map-marked-alt me-2 text-primary"></i>تحليلات النقل لكل منطقة</h5>
    </div>
    <div class="card-body p-0">
        <?php if (empty($areaStatsList)): ?>
            <div class="text-center py-5 text-muted">
                <i class="fas fa-map-marker-alt fa-3x mb-3 opacity-50"></i>
                <p class="mb-0">لا توجد بيانات مناطق أو تعيينات طلاب حالياً</p>
            </div>
        <?php else: ?>
            <div class="table-responsive">
                <table class="table table-hover table-striped align-middle mb-0">
                    <thead class="table-light">
                        <tr>
                            <th width="50">#</th>
                            <th>المنطقة</th>
                            <th width="130">عدد الطلاب</th>
                            <th width="130">عدد الحافلات</th>
                            <th>أرقام الحافلات</th>
                            <th>السائقين</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php $idx = 0; foreach ($areaStatsList as $area): $idx++; ?>
                        <tr>
                            <td><?php echo $idx; ?></td>
                            <td><span class="fw-bold text-dark"><i class="fas fa-map-marker-alt text-danger me-2"></i><?php echo htmlspecialchars($area['area_name']); ?></span></td>
                            <td><span class="badge bg-primary-subtle text-primary fw-bold"><?php echo (int)$area['students_count']; ?> طالب</span></td>
                            <td><span class="badge bg-success-subtle text-success fw-bold"><?php echo (int)$area['buses_count']; ?> حافلة</span></td>
                            <td><?php echo !empty($area['bus_numbers']) ? htmlspecialchars($area['bus_numbers']) : '—'; ?></td>
                            <td><?php echo !empty($area['driver_names']) ? htmlspecialchars($area['driver_names']) : '—'; ?></td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php endif; ?>
    </div>
</div>

<!-- Buses Occupancy Table -->
<div class="premium-card mb-4" id="widget-buses-table">
    <div class="card-header bg-white py-3 border-bottom d-flex align-items-center">
        <i class="fas fa-grip-horizontal drag-handle me-2 text-muted" style="cursor: grab;" title="سحب لتغيير الترتيب"></i>
        <h5 class="mb-0 fw-bold"><i class="fas fa-bus me-2 text-primary"></i>إشغال وتشغيل الحافلات</h5>
    </div>
    <div class="card-body p-0">
        <?php if (empty($busesList)): ?>
            <div class="text-center py-5 text-muted">
                <i class="fas fa-bus fa-3x mb-3 opacity-50"></i>
                <p class="mb-0">لا توجد حافلات مسجلة حالياً</p>
            </div>
        <?php else: ?>
            <div class="table-responsive">
                <table class="table table-hover table-striped align-middle mb-0">
                    <thead class="table-light">
                        <tr>
                            <th width="60">#</th>
                            <th width="120">رقم الحافلة</th>
                            <th>السائق</th>
                            <th>السعة</th>
                            <th>الإشغال</th>
                            <th width="100">الحالة</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php $i = 0; foreach ($busesList as $b): $i++;
                            $primary = (int)$b['primary_student_count'];
                            $cap = (int)$b['capacity'];
                            $occupancy = $cap > 0 ? round(($primary / $cap) * 100) : 0;
                            $isOverloaded = $occupancy > 100;
                        ?>
                        <tr>
                            <td><?php echo $i; ?></td>
                            <td><span class="fw-bold"><?php echo htmlspecialchars($b['bus_number']); ?></span></td>
                            <td><small><?php echo htmlspecialchars($b['drivers'] ?: '—'); ?></small></td>
                            <td class="fw-bold"><?php echo $cap; ?></td>
                            <td>
                                <div class="d-flex align-items-center gap-2">
                                    <div class="progress flex-grow-1" style="height: 8px; border-radius: 4px;">
                                        <div class="progress-bar <?php echo $isOverloaded ? 'bg-danger progress-bar-striped progress-bar-animated' : ($occupancy > 80 ? 'bg-warning' : 'bg-success'); ?>" role="progressbar" style="width: <?php echo min($occupancy, 100); ?>%; border-radius: 4px;"></div>
                                    </div>
                                    <small class="fw-bold <?php echo $isOverloaded ? 'text-danger' : ($occupancy > 80 ? 'text-warning' : 'text-success'); ?>"><?php echo $occupancy; ?>%</small>
                                </div>
                            </td>
                            <td><?php echo ($b['status'] === 'active') ? '<span class="badge bg-success">نشط</span>' : '<span class="badge bg-danger">متوقف</span>'; ?></td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php endif; ?>
    </div>
</div>
</div> <!-- End Dashboard Canvas -->

<!-- Offcanvas Settings Panel -->
<div class="offcanvas offcanvas-end" tabindex="-1" id="dashboardSettings" aria-labelledby="dashboardSettingsLabel">
  <div class="offcanvas-header bg-dark text-white p-4">
    <h5 class="offcanvas-title fw-bold" id="dashboardSettingsLabel"><i class="fas fa-sliders-h me-2"></i> تخصيص الإحصائيات</h5>
    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="offcanvas" aria-label="Close"></button>
  </div>
  <div class="offcanvas-body p-4">
    <div class="mb-4 pt-3">
        <h6 class="text-uppercase text-muted fw-bold small mb-3">عناصر لوحة التحكم</h6>
        <div class="form-check form-switch mb-3 d-flex justify-content-between">
          <label class="form-check-label fw-bold" for="toggle-widget-kpi">البطاقات الإحصائية</label>
          <input class="form-check-input widget-toggle" type="checkbox" id="toggle-widget-kpi" data-target="widget-kpi" checked>
        </div>
        <div class="form-check form-switch mb-3 d-flex justify-content-between">
          <label class="form-check-label fw-bold" for="toggle-widget-charts">الرسوم البيانية</label>
          <input class="form-check-input widget-toggle" type="checkbox" id="toggle-widget-charts" data-target="widget-charts" checked>
        </div>
        <div class="form-check form-switch mb-3 d-flex justify-content-between">
          <label class="form-check-label fw-bold" for="toggle-widget-geo">المناطق الجغرافية</label>
          <input class="form-check-input widget-toggle" type="checkbox" id="toggle-widget-geo" data-target="widget-geo" checked>
        </div>
        <div class="form-check form-switch mb-3 d-flex justify-content-between">
          <label class="form-check-label fw-bold" for="toggle-widget-regional-table">تحليلات المناطق</label>
          <input class="form-check-input widget-toggle" type="checkbox" id="toggle-widget-regional-table" data-target="widget-regional-table" checked>
        </div>
        <div class="form-check form-switch mb-3 d-flex justify-content-between">
          <label class="form-check-label fw-bold" for="toggle-widget-buses-table">إشغال الحافلات</label>
          <input class="form-check-input widget-toggle" type="checkbox" id="toggle-widget-buses-table" data-target="widget-buses-table" checked>
        </div>
    </div>
    <button type="button" class="btn btn-danger w-100 py-2" id="reset-dashboard-prefs">
        <i class="fas fa-undo me-2"></i> استعادة الافتراضي
    </button>
  </div>
</div>

<script>
// === Dashboard Customizer Logic ===
(function() {
    const STORAGE_KEY = 'eduCoreTransportDashboardPrefs';
    const defaultWidgets = ['widget-kpi', 'widget-charts', 'widget-geo', 'widget-regional-table', 'widget-buses-table'];
    let prefs = JSON.parse(localStorage.getItem(STORAGE_KEY) || '{}');

    function applyPrefs() {
        defaultWidgets.forEach(id => {
            const isVisible = prefs[id] !== false;
            const el = document.getElementById(id);
            const toggle = document.querySelector(`.widget-toggle[data-target="${id}"]`);
            if(el) el.style.display = isVisible ? '' : 'none';
            if(toggle) toggle.checked = isVisible;
        });
    }
    document.addEventListener('DOMContentLoaded', applyPrefs);

    document.querySelectorAll('.widget-toggle').forEach(t => {
        t.addEventListener('change', function() {
            prefs[this.dataset.target] = this.checked;
            localStorage.setItem(STORAGE_KEY, JSON.stringify(prefs));
            applyPrefs();
        });
    });

    const resetBtn = document.getElementById('reset-dashboard-prefs');
    if(resetBtn) {
        resetBtn.addEventListener('click', function() {
            localStorage.removeItem(STORAGE_KEY);
            prefs = {};
            applyPrefs();
        });
    }
})();

// ======= Donut Chart: Bus Status =======
(function() {
    var canvas = document.getElementById('statusDonut');
    if (!canvas) return;
    var ctx = canvas.getContext('2d');
    var data = [<?php echo $activeBuses; ?>, <?php echo $inactiveBuses; ?>];
    var colors = ['#10b981', '#ef4444'];
    var total = data[0] + data[1];
    if (total === 0) return;

    var cx = 100, cy = 100, r = 85, inner = 55;
    var startAngle = -Math.PI / 2;

    data.forEach(function(val, i) {
        if (val === 0) return;
        var slice = (val / total) * 2 * Math.PI;
        ctx.beginPath();
        ctx.moveTo(cx, cy);
        ctx.arc(cx, cy, r, startAngle, startAngle + slice);
        ctx.closePath();
        ctx.fillStyle = colors[i];
        ctx.fill();

        // draw gap
        ctx.beginPath();
        ctx.moveTo(cx, cy);
        ctx.arc(cx, cy, r, startAngle + slice - 0.02, startAngle + slice + 0.02);
        ctx.closePath();
        ctx.fillStyle = '#fff';
        ctx.fill();

        startAngle += slice;
    });

    // Donut hole
    ctx.beginPath();
    ctx.arc(cx, cy, inner, 0, 2 * Math.PI);
    ctx.fillStyle = '#fff';
    ctx.fill();

    // Center Text
    ctx.fillStyle = '#1e293b';
    ctx.font = 'bold 20px Tajawal, sans-serif';
    ctx.textAlign = 'center';
    ctx.textBaseline = 'middle';
    ctx.fillText(total.toString(), cx, cy - 8);
    ctx.font = '11px Tajawal, sans-serif';
    ctx.fillStyle = '#64748b';
    ctx.fillText('حافلة', cx, cy + 14);
})();

// ======= Donut Chart: Stage Distribution =======
(function() {
    var canvas = document.getElementById('stageDonut');
    if (!canvas) return;
    var ctx = canvas.getContext('2d');
    var data = [<?php
        $arr = array_map(fn($r) => (int)$r['students_count'], $stageDistribution);
        echo implode(',', $arr);
    ?>];
    var colors = [<?php
        $cols = array_map(fn($i) => '"' . $stageColors[$i % count($stageColors)] . '"', array_keys($stageDistribution));
        echo implode(',', $cols);
    ?>];
    var total = data.reduce(function(a, b) { return a + b; }, 0);
    if (total === 0) return;

    var cx = 100, cy = 100, r = 85, inner = 55;
    var startAngle = -Math.PI / 2;

    data.forEach(function(val, i) {
        if (val === 0) return;
        var slice = (val / total) * 2 * Math.PI;
        ctx.beginPath();
        ctx.moveTo(cx, cy);
        ctx.arc(cx, cy, r, startAngle, startAngle + slice);
        ctx.closePath();
        ctx.fillStyle = colors[i];
        ctx.fill();

        // draw gap
        ctx.beginPath();
        ctx.moveTo(cx, cy);
        ctx.arc(cx, cy, r, startAngle + slice - 0.02, startAngle + slice + 0.02);
        ctx.closePath();
        ctx.fillStyle = '#fff';
        ctx.fill();

        startAngle += slice;
    });

    // Donut hole
    ctx.beginPath();
    ctx.arc(cx, cy, inner, 0, 2 * Math.PI);
    ctx.fillStyle = '#fff';
    ctx.fill();

    // Center Text
    ctx.fillStyle = '#1e293b';
    ctx.font = 'bold 20px Tajawal, sans-serif';
    ctx.textAlign = 'center';
    ctx.textBaseline = 'middle';
    ctx.fillText(total.toString(), cx, cy - 8);
    ctx.font = '11px Tajawal, sans-serif';
    ctx.fillStyle = '#64748b';
    ctx.fillText('طالب بالحافلة', cx, cy + 14);
})();
</script>

<?php require_once '../includes/admin_footer.php'; ?>
