<?php
$page_title = "لوحة إحصائيات العاملين المتقدمة";
$custom_page_title = true;

require_once '../config/database.php';
require_once '../classes/utilities.php';
require_once '../classes/user.php';
require_once '../classes/StaffEmploymentLifecycleService.php';
require_once '../includes/session_config.php';
Utilities::validateSession('admin');

$database = new Database();
$db = $database->getConnection();
$staffBaseWhere = "sp.user_id IS NOT NULL AND (u.role IS NULL OR u.role NOT IN ('admin','student'))";

// --- 1. Basic Stats (Group 1) ---
$staff_stats = $db->query("SELECT
    COUNT(*) as total,
    SUM(CASE WHEN u.status='active' THEN 1 ELSE 0 END) as active_count,
    SUM(CASE WHEN u.status='inactive' THEN 1 ELSE 0 END) as inactive_count,
    SUM(CASE WHEN EXISTS (SELECT 1 FROM user_role_assignments ura_t WHERE ura_t.user_id = u.id AND ura_t.role_key = 'teacher' AND ura_t.status = 'active') THEN 1 ELSE 0 END) as teachers_count,
    SUM(CASE WHEN EXISTS (SELECT 1 FROM user_role_assignments ura_s WHERE ura_s.user_id = u.id AND ura_s.role_key = 'specialist' AND ura_s.status = 'active') THEN 1 ELSE 0 END) as specialists_count
    FROM users u
    JOIN staff_profiles sp ON sp.user_id = u.id
    WHERE {$staffBaseWhere}")->fetch(PDO::FETCH_ASSOC);

// Genders stats
$gender_stats = $db->query("SELECT 
    SUM(CASE WHEN sp.gender = 'male' THEN 1 ELSE 0 END) as male_count,
    SUM(CASE WHEN sp.gender = 'female' THEN 1 ELSE 0 END) as female_count,
    SUM(CASE WHEN sp.gender IS NULL OR TRIM(sp.gender) = '' THEN 1 ELSE 0 END) as unspecified_count
    FROM users u 
    JOIN staff_profiles sp ON u.id = sp.user_id
    WHERE {$staffBaseWhere} AND u.status = 'active'")->fetch(PDO::FETCH_ASSOC);

// Contract types stats
$contract_stats = $db->query("SELECT 
    SUM(CASE WHEN sp.contract_type = 'permanent' THEN 1 ELSE 0 END) as permanent_count,
    SUM(CASE WHEN sp.contract_type = 'temporary' THEN 1 ELSE 0 END) as temporary_count,
    SUM(CASE WHEN sp.contract_type = 'parttime' THEN 1 ELSE 0 END) as parttime_count,
    SUM(CASE WHEN sp.contract_type IS NULL OR TRIM(sp.contract_type) = '' THEN 1 ELSE 0 END) as unspecified_count
    FROM users u 
    JOIN staff_profiles sp ON u.id = sp.user_id
    WHERE {$staffBaseWhere} AND u.status = 'active'")->fetch(PDO::FETCH_ASSOC);

// Total departments count
$departments_count = (int)$db->query("SELECT COUNT(DISTINCT sp.department)
    FROM users u
    JOIN staff_profiles sp ON u.id = sp.user_id
    WHERE {$staffBaseWhere}
      AND sp.department IS NOT NULL
      AND TRIM(sp.department) != ''")->fetchColumn();

// Staff added in last 30 days
try {
    $month_new_staff = $db->query("SELECT COUNT(*)
        FROM users u
        JOIN staff_profiles sp ON u.id = sp.user_id
        WHERE {$staffBaseWhere}
          AND sp.created_at >= DATE_SUB(NOW(), INTERVAL 30 DAY)")->fetchColumn();
} catch (Exception $e) {
    $month_new_staff = 0;
}

// --- 2. Staff per Department (Distribution) ---
try {
    $departments_dist = $db->query("
        SELECT COALESCE(NULLIF(TRIM(sp.department),''),'غير محدد') as label, COUNT(u.id) as total
        FROM users u
        JOIN staff_profiles sp ON sp.user_id = u.id
        WHERE {$staffBaseWhere} AND u.status = 'active'
        GROUP BY label
        ORDER BY total DESC
    ")->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) {
    $departments_dist = [];
}

// --- 3. Top Job Titles ---
try {
    $raw_top_jobs = $db->query("
        SELECT COALESCE(NULLIF(TRIM(sp.job_title),''),'غير محدد') as label, COUNT(u.id) as total
        FROM users u
        JOIN staff_profiles sp ON sp.user_id = u.id
        WHERE {$staffBaseWhere} AND u.status = 'active'
        GROUP BY label
        ORDER BY total DESC
    ")->fetchAll(PDO::FETCH_ASSOC);
    $top_job_totals = [];
    foreach ($raw_top_jobs as $raw_top_job) {
        $canonical_label = StaffEmploymentLifecycleService::canonicalJobTitle($raw_top_job['label'] ?? null) ?? 'غير محدد';
        $top_job_totals[$canonical_label] = ($top_job_totals[$canonical_label] ?? 0) + (int)($raw_top_job['total'] ?? 0);
    }
    arsort($top_job_totals, SORT_NUMERIC);
    $top_jobs = [];
    foreach (array_slice($top_job_totals, 0, 5, true) as $label => $total) {
        $top_jobs[] = ['label' => $label, 'total' => $total];
    }
} catch (Exception $e) {
    $top_jobs = [];
}

// --- 4. Monthly Registrations (Last 12 Months) ---
try {
    $monthly_regs = $db->query("
        SELECT DATE_FORMAT(sp.created_at, '%Y-%m') as month_year, COUNT(*) as count
        FROM users u
        JOIN staff_profiles sp ON u.id = sp.user_id
        WHERE {$staffBaseWhere}
          AND sp.created_at >= DATE_SUB(NOW(), INTERVAL 12 MONTH)
        GROUP BY month_year
        ORDER BY month_year ASC
    ")->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) {
    $monthly_regs = [];
}

// --- 5. Recent Staff Registrations ---
try {
    $recent_staff = $db->query("
        SELECT u.name, sp.created_at, sp.job_title, sp.department, u.status
        FROM users u
        JOIN staff_profiles sp ON u.id = sp.user_id
        WHERE {$staffBaseWhere}
        ORDER BY sp.created_at DESC
        LIMIT 5
    ")->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) {
    $recent_staff = [];
}

require_once '../includes/admin_header.php';
?>

<!-- Chart.js CDN -->
<script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.0/dist/chart.umd.min.js"></script>

<!-- Header Actions -->
<div class="d-flex justify-content-between flex-wrap flex-md-nowrap align-items-center pt-3 pb-2 mb-3 border-bottom">
    <div>
        <h1 class="h2"><i class="fas fa-chart-line text-primary me-2"></i>التحليلات الذكية للعاملين</h1>
        <p class="text-muted mb-0">نظرة عامة شاملة على توزيع ومؤشرات نمو كادر المدرسة التعليمي والمساند</p>
    </div>
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
    
    <!-- Row 1: KPI Cards -->
    <div class="row row-cols-1 row-cols-sm-2 row-cols-md-3 row-cols-xl-4 g-4 mb-4 widget-card-group sortable-dashboard" id="widget-kpi-1">
        <!-- إجمالي العاملين -->
        <div class="col animate-up delay-1">
            <div class="stat-card" style="--card-gradient: var(--primary-gradient);">
                <div class="stat-card-icon"><i class="fas fa-users-cog"></i></div>
                <div class="stat-card-info">
                    <div class="stat-card-number counter" data-target="<?php echo (int)($staff_stats['total'] ?? 0); ?>">0</div>
                    <div class="stat-card-label">إجمالي الكادر</div>
                </div>
            </div>
        </div>
        
        <!-- العاملين النشطين -->
        <div class="col animate-up delay-2">
            <div class="stat-card" style="--card-gradient: var(--success-gradient);">
                <div class="stat-card-icon"><i class="fas fa-user-check"></i></div>
                <div class="stat-card-info">
                    <div class="stat-card-number counter" data-target="<?php echo (int)($staff_stats['active_count'] ?? 0); ?>">0</div>
                    <div class="stat-card-label">موظفون نشطون</div>
                </div>
            </div>
        </div>
        
        <!-- تسجيلات حديثة -->
        <div class="col animate-up delay-3">
            <div class="stat-card" style="--card-gradient: var(--warning-gradient);">
                <div class="stat-card-icon"><i class="fas fa-user-plus"></i></div>
                <div class="stat-card-info">
                    <div class="stat-card-number counter" data-target="<?php echo (int)($month_new_staff ?? 0); ?>">0</div>
                    <div class="stat-card-label">انضموا حديثاً (30 يوم)</div>
                </div>
            </div>
        </div>

        <!-- المعطلين -->
        <div class="col animate-up delay-4">
            <div class="stat-card" style="--card-gradient: var(--danger-gradient);">
                <div class="stat-card-icon"><i class="fas fa-user-slash"></i></div>
                <div class="stat-card-info">
                    <div class="stat-card-number counter" data-target="<?php echo (int)($staff_stats['inactive_count'] ?? 0); ?>">0</div>
                    <div class="stat-card-label">موظفون معطلون</div>
                </div>
            </div>
        </div>
    </div>

    <!-- Row 2: Secondary KPI Cards -->
    <div class="row row-cols-1 row-cols-sm-2 row-cols-md-3 row-cols-xl-4 g-4 mb-5 widget-card-group sortable-dashboard" id="widget-kpi-2">
        <!-- المعلمون -->
        <div class="col animate-up delay-1">
            <div class="stat-card" style="--card-gradient: linear-gradient(135deg, #0ea5e9, #0284c7);">
                <div class="stat-card-icon"><i class="fas fa-chalkboard-teacher"></i></div>
                <div class="stat-card-info">
                    <div class="stat-card-number counter" data-target="<?php echo (int)($staff_stats['teachers_count'] ?? 0); ?>">0</div>
                    <div class="stat-card-label">أعضاء هيئة التدريس</div>
                </div>
            </div>
        </div>

        <!-- الأخصائيون -->
        <div class="col animate-up delay-2">
            <div class="stat-card" style="--card-gradient: linear-gradient(135deg, #8b5cf6, #6d28d9);">
                <div class="stat-card-icon"><i class="fas fa-user-nurse"></i></div>
                <div class="stat-card-info">
                    <div class="stat-card-number counter" data-target="<?php echo (int)($staff_stats['specialists_count'] ?? 0); ?>">0</div>
                    <div class="stat-card-label">الأخصائيون</div>
                </div>
            </div>
        </div>

        <!-- الأقسام -->
        <div class="col animate-up delay-4">
            <div class="stat-card" style="--card-gradient: linear-gradient(135deg, #14b8a6, #0f766e);">
                <div class="stat-card-icon"><i class="fas fa-building"></i></div>
                <div class="stat-card-info">
                    <div class="stat-card-number counter" data-target="<?php echo (int)($departments_count ?? 0); ?>">0</div>
                    <div class="stat-card-label">إجمالي الأقسام والتخصصات</div>
                </div>
            </div>
        </div>
    </div>

    <!-- Row 3: Charts (Distribution & Gender) -->
    <div class="row g-4 mb-4 sortable-dashboard">
        <!-- Distribution By Department -->
        <div class="col-lg-8" id="widget-chart-dept">
            <div class="premium-card h-100">
                <div class="card-header bg-white py-3 border-bottom d-flex align-items-center">
                    <i class="fas fa-grip-horizontal drag-handle me-2 text-muted" style="cursor: grab;" title="سحب لتغيير الترتيب"></i>
                    <h5 class="mb-0 fw-bold"><i class="fas fa-chart-column text-primary me-2"></i>توزيع العاملين حسب الأقسام والتخصصات</h5>
                </div>
                <div class="card-body">
                    <canvas id="deptChart" height="120"></canvas>
                </div>
            </div>
        </div>
        
        <!-- Gender Doughnut -->
        <div class="col-lg-4" id="widget-chart-gender">
            <div class="premium-card h-100">
                <div class="card-header bg-white py-3 border-bottom d-flex align-items-center">
                    <i class="fas fa-grip-horizontal drag-handle me-2 text-muted" style="cursor: grab;" title="سحب لتغيير الترتيب"></i>
                    <h5 class="mb-0 fw-bold"><i class="fas fa-venus-mars text-danger me-2"></i>التركيبة النوعية (نشط)</h5>
                </div>
                <div class="card-body">
                    <canvas id="genderChart" height="200"></canvas>
                </div>
            </div>
        </div>
    </div>

    <!-- Row 4: Charts (Age & Trend) -->
    <div class="row g-4 mb-4 sortable-dashboard">
        <!-- Registration Growth Trend -->
        <div class="col-lg-8" id="widget-chart-trend">
            <div class="premium-card h-100">
                <div class="card-header bg-white py-3 border-bottom d-flex align-items-center">
                    <i class="fas fa-grip-horizontal drag-handle me-2 text-muted" style="cursor: grab;" title="سحب لتغيير الترتيب"></i>
                    <h5 class="mb-0 fw-bold"><i class="fas fa-chart-line text-success me-2"></i>معدل تسجيل الموظفين بالمنظومة (آخر 12 شهر)</h5>
                </div>
                <div class="card-body">
                    <canvas id="trendChart" height="120"></canvas>
                </div>
            </div>
        </div>

        <!-- Contract Types -->
        <div class="col-lg-4" id="widget-chart-contract">
            <div class="premium-card h-100">
                <div class="card-header bg-white py-3 border-bottom d-flex align-items-center">
                    <i class="fas fa-grip-horizontal drag-handle me-2 text-muted" style="cursor: grab;" title="سحب لتغيير الترتيب"></i>
                    <h5 class="mb-0 fw-bold"><i class="fas fa-file-contract text-warning me-2"></i>توزيع فئات التعاقد (نشط)</h5>
                </div>
                <div class="card-body">
                    <canvas id="contractChart" height="200"></canvas>
                </div>
            </div>
        </div>
    </div>

    <!-- Row 5: Tables (Job Titles & Recent Hires) -->
    <div class="row g-4 mb-4 sortable-dashboard" id="widget-tables">
        <!-- Top Job Titles -->
        <div class="col-lg-6" id="widget-table-jobs">
            <div class="premium-card h-100">
                <div class="card-header bg-white py-3 border-bottom d-flex justify-content-between align-items-center">
                    <div class="d-flex align-items-center">
                        <i class="fas fa-grip-horizontal drag-handle me-2 text-muted" style="cursor: grab;" title="سحب لتغيير الترتيب"></i>
                        <h5 class="mb-0 fw-bold"><i class="fas fa-briefcase text-info me-2"></i>المسميات الوظيفية الأكثر تكراراً (نشط)</h5>
                    </div>
                    <button class="btn btn-sm btn-outline-primary px-3" onclick="exportTableToCSV('table-top-jobs', 'staff_job_titles.csv')">
                        <i class="fas fa-download me-1"></i> CSV
                    </button>
                </div>
                <div class="card-body">
                    <div class="table-responsive">
                        <table class="table table-hover align-middle mb-0" id="table-top-jobs">
                            <thead class="table-light">
                                <tr>
                                    <th>المسمى الوظيفي</th>
                                    <th class="text-center">العدد</th>
                                    <th>مؤشر نسبي</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php if(!empty($top_jobs)): 
                                    $max_job = max(1, (int)($top_jobs[0]['total'] ?? 1));
                                    foreach($top_jobs as $tj): 
                                        $pct = min(100, ($tj['total'] / $max_job) * 100);
                                ?>
                                <tr>
                                    <td class="fw-bold"><?php echo htmlspecialchars($tj['label'] ?: 'غير محدد'); ?></td>
                                    <td class="text-center fw-bold text-primary"><?php echo $tj['total']; ?></td>
                                    <td class="w-50">
                                        <div class="progress" style="height: 8px;">
                                          <div class="progress-bar bg-info" role="progressbar" style="width: <?php echo $pct; ?>%"></div>
                                        </div>
                                    </td>
                                </tr>
                                <?php endforeach; else: ?>
                                <tr><td colspan="3" class="text-center text-muted">لا توجد بيانات</td></tr>
                                <?php endif; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
        </div>

        <!-- Recent Registered Staff -->
        <div class="col-lg-6" id="widget-table-recent">
            <div class="premium-card h-100">
                <div class="card-header bg-white py-3 border-bottom d-flex justify-content-between align-items-center">
                    <div class="d-flex align-items-center">
                        <i class="fas fa-grip-horizontal drag-handle me-2 text-muted" style="cursor: grab;" title="سحب لتغيير الترتيب"></i>
                        <h5 class="mb-0 fw-bold"><i class="fas fa-history text-secondary me-2"></i>أحدث تسجيلات الكادر بالمنظومة</h5>
                    </div>
                    <button class="btn btn-sm btn-outline-primary px-3" onclick="exportTableToCSV('table-recent-staff', 'recent_staff.csv')">
                        <i class="fas fa-download me-1"></i> CSV
                    </button>
                </div>
                <div class="card-body">
                    <div class="table-responsive">
                        <table class="table table-hover align-middle mb-0" id="table-recent-staff">
                            <thead class="table-light">
                                <tr>
                                    <th>الاسم</th>
                                    <th>تاريخ التسجيل</th>
                                    <th>القسم</th>
                                    <th>الحالة</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php if(!empty($recent_staff)): foreach($recent_staff as $rs): ?>
                                <tr>
                                    <td><i class="fas fa-user-circle text-muted me-1"></i> <?php echo htmlspecialchars($rs['name']); ?></td>
                                    <td dir="ltr" class="text-end"><?php echo date('Y-m-d', strtotime($rs['created_at'])); ?></td>
                                    <td><?php echo htmlspecialchars($rs['department'] ?: 'غير محدد'); ?></td>
                                    <td>
                                        <?php if($rs['status'] == 'active'): ?>
                                            <span class="badge bg-success">نشط</span>
                                        <?php else: ?>
                                            <span class="badge bg-secondary"><?php echo htmlspecialchars($rs['status']); ?></span>
                                        <?php endif; ?>
                                    </td>
                                </tr>
                                <?php endforeach; else: ?>
                                <tr><td colspan="4" class="text-center text-muted">لا توجد تسجيلات حديثة</td></tr>
                                <?php endif; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
        </div>
    </div>

</div>



<!-- Offcanvas Settings Panel -->
<div class="offcanvas offcanvas-end" tabindex="-1" id="dashboardSettings" aria-labelledby="dashboardSettingsLabel">
  <div class="offcanvas-header bg-dark text-white p-4">
    <h5 class="offcanvas-title fw-bold" id="dashboardSettingsLabel"><i class="fas fa-sliders-h me-2"></i> تخصيص لوحة الإحصائيات</h5>
    <button type="button" class="btn-close btn-close-white text-reset" data-bs-dismiss="offcanvas" aria-label="Close"></button>
  </div>
  <div class="offcanvas-body p-4">
    <!-- Quick Presets -->
    <div class="mb-4">
        <h6 class="text-uppercase text-muted fw-bold small mb-3"><i class="fas fa-magic me-1 text-primary"></i> القوالب الجاهزة</h6>
        <div class="d-flex flex-wrap gap-2">
            <button type="button" class="btn btn-outline-primary btn-sm px-3 py-2 preset-btn" data-preset="all">
                <i class="fas fa-th-large me-1"></i> عرض الكل
            </button>
            <button type="button" class="btn btn-outline-primary btn-sm px-3 py-2 preset-btn" data-preset="kpis">
                <i class="fas fa-chart-pie me-1"></i> المؤشرات فقط
            </button>
            <button type="button" class="btn btn-outline-primary btn-sm px-3 py-2 preset-btn" data-preset="charts">
                <i class="fas fa-chart-bar me-1"></i> الرسوم فقط
            </button>
            <button type="button" class="btn btn-outline-primary btn-sm px-3 py-2 preset-btn" data-preset="tables">
                <i class="fas fa-table me-1"></i> الجداول فقط
            </button>
        </div>
    </div>

    <div class="mb-4 border-top pt-3">
        <h6 class="text-uppercase text-muted fw-bold small mb-3">إعدادات العرض</h6>
        <div class="form-check form-switch mb-3 p-0 d-flex justify-content-between align-items-center">
          <label class="form-check-label fw-bold" for="toggle-widget-kpi-1">الإحصائيات الرئيسية</label>
          <input class="form-check-input widget-toggle ms-0" type="checkbox" id="toggle-widget-kpi-1" data-target="widget-kpi-1" checked>
        </div>
        <div class="form-check form-switch mb-3 p-0 d-flex justify-content-between align-items-center">
          <label class="form-check-label fw-bold" for="toggle-widget-kpi-2">إحصائيات الأدوار والأقسام</label>
          <input class="form-check-input widget-toggle ms-0" type="checkbox" id="toggle-widget-kpi-2" data-target="widget-kpi-2" checked>
        </div>
    </div>
    
    <div class="mb-4 border-top pt-3">
        <h6 class="text-uppercase text-muted fw-bold small mb-3">الرسوم البيانية</h6>
        <div class="form-check form-switch mb-3 p-0 d-flex justify-content-between align-items-center">
          <label class="form-check-label fw-bold" for="toggle-widget-chart-dept">توزيع الأقسام</label>
          <input class="form-check-input widget-toggle ms-0" type="checkbox" id="toggle-widget-chart-dept" data-target="widget-chart-dept" checked>
        </div>
        <div class="form-check form-switch mb-3 p-0 d-flex justify-content-between align-items-center">
          <label class="form-check-label fw-bold" for="toggle-widget-chart-gender">التركيبة النوعية</label>
          <input class="form-check-input widget-toggle ms-0" type="checkbox" id="toggle-widget-chart-gender" data-target="widget-chart-gender" checked>
        </div>
        <div class="form-check form-switch mb-3 p-0 d-flex justify-content-between align-items-center">
          <label class="form-check-label fw-bold" for="toggle-widget-chart-trend">معدل التسجيل</label>
          <input class="form-check-input widget-toggle ms-0" type="checkbox" id="toggle-widget-chart-trend" data-target="widget-chart-trend" checked>
        </div>
        <div class="form-check form-switch mb-3 p-0 d-flex justify-content-between align-items-center">
          <label class="form-check-label fw-bold" for="toggle-widget-chart-contract">فئات التعاقد</label>
          <input class="form-check-input widget-toggle ms-0" type="checkbox" id="toggle-widget-chart-contract" data-target="widget-chart-contract" checked>
        </div>
    </div>

    <div class="mb-4 border-top pt-3">
        <h6 class="text-uppercase text-muted fw-bold small mb-3">التقارير والجداول</h6>
        <div class="form-check form-switch mb-3 p-0 d-flex justify-content-between align-items-center">
          <label class="form-check-label fw-bold" for="toggle-widget-table-jobs">المسميات الوظيفية</label>
          <input class="form-check-input widget-toggle ms-0" type="checkbox" id="toggle-widget-table-jobs" data-target="widget-table-jobs" checked>
        </div>
        <div class="form-check form-switch mb-3 p-0 d-flex justify-content-between align-items-center">
          <label class="form-check-label fw-bold" for="toggle-widget-table-recent">أحدث التسجيلات</label>
          <input class="form-check-input widget-toggle ms-0" type="checkbox" id="toggle-widget-table-recent" data-target="widget-table-recent" checked>
        </div>
    </div>

    <!-- Reset Button -->
    <div class="mt-4 pt-3 border-top">
        <button type="button" class="btn btn-danger w-100 py-2 btn-sm" id="reset-dashboard-prefs">
            <i class="fas fa-undo me-2"></i> استعادة الإعدادات الافتراضية
        </button>
    </div>
  </div>
</div>

<!-- Initialize Dashboard Customizer & Chart.js -->
<script>
// === Dashboard Customizer Logic ===
function initDashboardCustomizer() {
    const STORAGE_KEY = 'eduCoreStaffDashboardPrefs';
    const defaultWidgets = [
        'widget-kpi-1', 'widget-kpi-2', 'widget-chart-dept', 
        'widget-chart-gender', 'widget-chart-trend', 'widget-chart-contract', 
        'widget-table-jobs', 'widget-table-recent'
    ];
    
    // Define Presets
    const presets = {
        all: {
            'widget-kpi-1': true, 'widget-kpi-2': true,
            'widget-chart-dept': true, 'widget-chart-gender': true, 'widget-chart-trend': true, 'widget-chart-contract': true,
            'widget-table-jobs': true, 'widget-table-recent': true
        },
        kpis: {
            'widget-kpi-1': true, 'widget-kpi-2': true,
            'widget-chart-dept': false, 'widget-chart-gender': false, 'widget-chart-trend': false, 'widget-chart-contract': false,
            'widget-table-jobs': false, 'widget-table-recent': false
        },
        charts: {
            'widget-kpi-1': false, 'widget-kpi-2': false,
            'widget-chart-dept': true, 'widget-chart-gender': true, 'widget-chart-trend': true, 'widget-chart-contract': true,
            'widget-table-jobs': false, 'widget-table-recent': false
        },
        tables: {
            'widget-kpi-1': false, 'widget-kpi-2': false,
            'widget-chart-dept': false, 'widget-chart-gender': false, 'widget-chart-trend': false, 'widget-chart-contract': false,
            'widget-table-jobs': true, 'widget-table-recent': true
        }
    };
    
    let prefs = JSON.parse(localStorage.getItem(STORAGE_KEY) || '{}');
    const hasPrefs = localStorage.getItem(STORAGE_KEY) !== null;
    
    const floatBtn = document.querySelector('.btn-gear-float');
    if (!hasPrefs && floatBtn) {
        floatBtn.classList.add('pulse-effect');
    }

    const showWidget = (el) => {
        if (!el) return;
        el.classList.remove('d-none');
        el.classList.add('card-fade-transition');
        el.classList.add('card-fade-hidden');
        el.offsetHeight; // Force reflow
        el.classList.remove('card-fade-hidden');
        setTimeout(() => {
            el.classList.remove('card-fade-transition');
        }, 300);
    };

    const hideWidget = (el) => {
        if (!el) return;
        el.classList.add('card-fade-transition');
        el.classList.add('card-fade-hidden');
        setTimeout(() => {
            el.classList.add('d-none');
            el.classList.remove('card-fade-transition');
        }, 300);
    };

    const updateBadge = () => {
        const badge = document.getElementById('customizer-badge');
        if (!badge) return;
        let hiddenCount = 0;
        
        defaultWidgets.forEach(widgetId => {
            if (prefs[widgetId] === false) hiddenCount++;
        });
        
        if (hiddenCount > 0) {
            badge.textContent = hiddenCount;
            badge.classList.remove('d-none');
        } else {
            badge.classList.add('d-none');
        }
    };
    
    const applyPrefs = (animate = false) => {
        defaultWidgets.forEach(widgetId => {
            const el = document.getElementById(widgetId);
            const toggle = document.getElementById('toggle-' + widgetId);
            
            if (el && toggle) {
                const isVisible = prefs[widgetId] !== false;
                toggle.checked = isVisible;
                
                if (animate) {
                    if (isVisible) showWidget(el);
                    else hideWidget(el);
                } else {
                    if (isVisible) el.classList.remove('d-none');
                    else el.classList.add('d-none');
                }
            }
        });
        updateBadge();
    };
    
    applyPrefs(false);

    document.querySelectorAll('.widget-toggle').forEach(t => {
        t.addEventListener('change', function() {
            const targetId = this.getAttribute('data-target');
            const targetEl = document.getElementById(targetId);
            
            if (floatBtn) floatBtn.classList.remove('pulse-effect');
            
            if (this.checked) {
                showWidget(targetEl);
                prefs[targetId] = true;
            } else {
                hideWidget(targetEl);
                prefs[targetId] = false;
            }
            localStorage.setItem(STORAGE_KEY, JSON.stringify(prefs));
            updateBadge();
        });
    });

    document.querySelectorAll('.preset-btn').forEach(btn => {
        btn.addEventListener('click', function() {
            const presetKey = this.dataset.preset;
            if (presets[presetKey]) {
                if (floatBtn) floatBtn.classList.remove('pulse-effect');
                prefs = {...presets[presetKey]};
                localStorage.setItem(STORAGE_KEY, JSON.stringify(prefs));
                applyPrefs(true);
            }
        });
    });

    const resetBtn = document.getElementById('reset-dashboard-prefs');
    if (resetBtn) {
        resetBtn.addEventListener('click', function() {
            localStorage.removeItem(STORAGE_KEY);
            prefs = {};
            if (floatBtn) floatBtn.classList.remove('pulse-effect');
            applyPrefs(true);
        });
    }
}

// === CSV Export Logic ===
function exportTableToCSV(tableId, filename) {
    var csv = [];
    var table = document.getElementById(tableId);
    if (!table) return;
    
    var rows = table.querySelectorAll("tr");
    for (var i = 0; i < rows.length; i++) {
        var row = [], cols = rows[i].querySelectorAll("td, th");
        for (var j = 0; j < cols.length; j++) {
            if (j === cols.length - 1 && cols[j].querySelector('.progress')) continue;
            let text = cols[j].innerText.replace(/(\r\n|\n|\r)/gm, "").trim();
            row.push('"' + text + '"');
        }
        if (row.length > 0) csv.push(row.join(","));
    }

    var csvFile = new Blob(["\uFEFF" + csv.join("\n")], {type: "text/csv;charset=utf-8;"});
    var downloadLink = document.createElement("a");
    downloadLink.download = filename;
    downloadLink.href = window.URL.createObjectURL(csvFile);
    downloadLink.style.display = "none";
    document.body.appendChild(downloadLink);
    downloadLink.click();
    document.body.removeChild(downloadLink);
}

document.addEventListener("DOMContentLoaded", function() {
    initDashboardCustomizer();

    // 1. Department Distribution Chart (Horizontal Bar Chart)
    const deptCtx = document.getElementById('deptChart').getContext('2d');
    const deptData = <?php echo json_encode($departments_dist); ?>;
    
    new Chart(deptCtx, {
        type: 'bar',
        data: {
            labels: deptData.map(d => d.label),
            datasets: [{
                label: 'عدد الموظفين النشطين',
                data: deptData.map(d => d.total),
                backgroundColor: 'rgba(59, 130, 246, 0.75)',
                borderColor: 'rgba(37, 99, 235, 1)',
                borderWidth: 1,
                borderRadius: 4
            }]
        },
        options: {
            indexAxis: 'y', // Makes it a horizontal bar chart
            responsive: true,
            scales: {
                x: { beginAtZero: true, suggestedMax: 5 }
            },
            plugins: {
                legend: { display: false }
            }
        }
    });

    // 2. Gender Doughnut Chart
    const genderCtx = document.getElementById('genderChart').getContext('2d');
    const maleC = <?php echo (int)($gender_stats['male_count'] ?? 0); ?>;
    const femaleC = <?php echo (int)($gender_stats['female_count'] ?? 0); ?>;
    const unspecifiedC = <?php echo (int)($gender_stats['unspecified_count'] ?? 0); ?>;
    
    new Chart(genderCtx, {
        type: 'doughnut',
        data: {
            labels: ['ذكور', 'إناث', 'غير محدد'],
            datasets: [{
                data: [maleC, femaleC, unspecifiedC],
                backgroundColor: [
                    'rgba(14, 165, 233, 0.8)', // blue
                    'rgba(236, 72, 153, 0.8)', // pink
                    'rgba(156, 163, 175, 0.8)' // gray
                ],
                borderWidth: 2,
                borderColor: '#ffffff'
            }]
        },
        options: {
            responsive: true,
            cutout: '65%',
            plugins: {
                legend: { position: 'bottom' }
            }
        }
    });

    // 3. Trends Line Chart (Monthly Registrations)
    const trendCtx = document.getElementById('trendChart').getContext('2d');
    const trendData = <?php echo json_encode($monthly_regs); ?>;
    
    new Chart(trendCtx, {
        type: 'line',
        data: {
            labels: trendData.map(d => d.month_year),
            datasets: [{
                label: 'تسجيلات الكادر',
                data: trendData.map(d => d.count),
                borderColor: '#10b981',
                backgroundColor: 'rgba(16, 185, 129, 0.1)',
                borderWidth: 3,
                fill: true,
                tension: 0.4,
                pointBackgroundColor: '#059669',
                pointRadius: 4
            }]
        },
        options: {
            responsive: true,
            scales: {
                y: { beginAtZero: true, suggestedMax: 5 }
            },
            plugins: {
                legend: { display: false }
            }
        }
    });

    // 4. Contract Types Pie Chart
    const contractCtx = document.getElementById('contractChart').getContext('2d');
    const permanentC = <?php echo (int)($contract_stats['permanent_count'] ?? 0); ?>;
    const temporaryC = <?php echo (int)($contract_stats['temporary_count'] ?? 0); ?>;
    const parttimeC = <?php echo (int)($contract_stats['parttime_count'] ?? 0); ?>;
    const unspecifiedContract = <?php echo (int)($contract_stats['unspecified_count'] ?? 0); ?>;
    
    new Chart(contractCtx, {
        type: 'pie',
        data: {
            labels: ['تعاقد دائم', 'تعاقد مؤقت', 'عمل جزئي', 'غير محدد'],
            datasets: [{
                data: [permanentC, temporaryC, parttimeC, unspecifiedContract],
                backgroundColor: [
                    '#3b82f6', '#f59e0b', '#8b5cf6', '#9ca3af'
                ],
                borderWidth: 2,
                borderColor: '#ffffff'
            }]
        },
        options: {
            responsive: true,
            plugins: {
                legend: { position: 'bottom' }
            }
        }
    });
});
</script>

<?php include_once '../includes/admin_footer.php'; ?>
