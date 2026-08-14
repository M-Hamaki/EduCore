<?php
$page_title = "لوحة إحصائيات الطلاب المتقدمة";
$custom_page_title = true;

require_once '../config/database.php';
require_once '../classes/utilities.php';
require_once '../classes/user.php';
require_once '../includes/session_config.php';
Utilities::validateSession('admin');

$database = new Database();
$db = $database->getConnection();
require_once __DIR__ . '/../classes/AcademicYear.php';
require_once __DIR__ . '/../classes/ScopedStaffPortalContext.php';
$currentAcademicYearId = AcademicYear::currentId($db);
$portalContext = new ScopedStaffPortalContext($db, $currentAcademicYearId);
$allowedClassIds = $portalContext->allowedClassIds();

function student_statistics_class_scope(?array $allowedClassIds, string $column): string
{
    if ($allowedClassIds === null) {
        return '1 = 1';
    }
    $ids = array_values(array_unique(array_filter(array_map('intval', $allowedClassIds), static fn(int $id): bool => $id > 0)));
    return $ids === [] ? '1 = 0' : $column . ' IN (' . implode(',', $ids) . ')';
}

$enrollmentScopeSql = student_statistics_class_scope($allowedClassIds, 'se.class_id');
$classScopeSql = student_statistics_class_scope($allowedClassIds, 'c.id');

// بند JOIN الموحّد لطلاب العام الحالي (بديل u.class_id)
$_yearStudentsJoin = $currentAcademicYearId > 0
    ? "LEFT JOIN student_enrollments se ON se.class_id = c.id AND se.academic_year_id = {$currentAcademicYearId} AND se.enrollment_status = 'enrolled'
       LEFT JOIN users u ON u.id = se.student_id AND u.role = 'student' AND u.status = 'active' AND u.deleted_at IS NULL"
    : "LEFT JOIN users u ON u.class_id = c.id AND u.role = 'student' AND u.status = 'active' AND u.deleted_at IS NULL AND NOT EXISTS (SELECT 1 FROM student_profiles esp WHERE esp.user_id=u.id AND esp.enrollment_status <> 'enrolled')";

// --- 1. Basic Stats (Group 1) ---
$statsStmt = $db->prepare("SELECT
    COUNT(se.student_id) as total,
    SUM(CASE WHEN se.enrollment_status = 'enrolled' AND u.status = 'active' THEN 1 ELSE 0 END) as active_count,
    SUM(CASE WHEN u.status = 'inactive' THEN 1 ELSE 0 END) as inactive_count,
    SUM(CASE WHEN se.enrollment_status = 'graduated' OR u.status = 'graduated' THEN 1 ELSE 0 END) as graduated_count,
    SUM(CASE WHEN se.enrollment_status = 'transferred' THEN 1 ELSE 0 END) as transferred_count
    FROM student_enrollments se
    JOIN users u ON u.id = se.student_id AND u.role = 'student' AND u.deleted_at IS NULL
    LEFT JOIN student_profiles sp ON sp.user_id = u.id
    WHERE se.academic_year_id = ? AND {$enrollmentScopeSql}");
$statsStmt->execute([$currentAcademicYearId]);
$student_stats = $statsStmt->fetch(PDO::FETCH_ASSOC);

// Gender stats
$genderStmt = $db->prepare("SELECT 
    SUM(CASE WHEN sp.gender = 'male' THEN 1 ELSE 0 END) as male_count,
    SUM(CASE WHEN sp.gender = 'female' THEN 1 ELSE 0 END) as female_count
    FROM student_enrollments se
    JOIN users u ON u.id = se.student_id AND u.role = 'student' AND u.status = 'active' AND u.deleted_at IS NULL
    LEFT JOIN student_profiles sp ON u.id = sp.user_id
    WHERE se.academic_year_id = ? AND se.enrollment_status = 'enrolled' AND {$enrollmentScopeSql}");
$genderStmt->execute([$currentAcademicYearId]);
$gender_stats = $genderStmt->fetch(PDO::FETCH_ASSOC);

$student_classes_count = $db->query("SELECT COUNT(*) FROM classes c WHERE c.status = 'active' AND {$classScopeSql}")->fetchColumn();

// Stage and Grades count
try {
    if ($allowedClassIds === null) {
        $stages_count = $db->query("SELECT COUNT(*) FROM stages")->fetchColumn();
        $grades_count = $db->query("SELECT COUNT(*) FROM grades")->fetchColumn();
    } else {
        $grades_count = $db->query("SELECT COUNT(DISTINCT c.grade_id) FROM classes c WHERE {$classScopeSql}")->fetchColumn();
        $stages_count = $db->query("SELECT COUNT(DISTINCT g.stage_id) FROM classes c JOIN grades g ON g.id = c.grade_id WHERE {$classScopeSql}")->fetchColumn();
    }
} catch (Exception $e) {
    $stages_count = 0;
    $grades_count = 0;
}

// Students added in last 30 days
try {
    if ($allowedClassIds === null) {
        $month_new_students = $db->query("SELECT COUNT(*) FROM users WHERE role = 'student' AND deleted_at IS NULL AND created_at >= DATE_SUB(NOW(), INTERVAL 30 DAY)")->fetchColumn();
    } else {
        $monthStmt = $db->prepare("SELECT COUNT(DISTINCT u.id)
            FROM student_enrollments se
            JOIN users u ON u.id = se.student_id AND u.role = 'student' AND u.deleted_at IS NULL
            WHERE se.academic_year_id = ? AND {$enrollmentScopeSql}
              AND u.created_at >= DATE_SUB(NOW(), INTERVAL 30 DAY)");
        $monthStmt->execute([$currentAcademicYearId]);
        $month_new_students = $monthStmt->fetchColumn();
    }
} catch (Exception $e) {
    try {
        // Fallback: Check if student_profiles has registration_date
        if ($allowedClassIds === null) {
            $month_new_students = $db->query("SELECT COUNT(*) FROM student_profiles sp JOIN users u ON u.id = sp.user_id WHERE u.deleted_at IS NULL AND sp.registration_date >= DATE_SUB(NOW(), INTERVAL 30 DAY)")->fetchColumn();
        } else {
            $monthStmt = $db->prepare("SELECT COUNT(DISTINCT u.id)
                FROM student_profiles sp
                JOIN users u ON u.id = sp.user_id AND u.deleted_at IS NULL
                JOIN student_enrollments se ON se.student_id = u.id AND se.academic_year_id = ?
                WHERE {$enrollmentScopeSql} AND sp.registration_date >= DATE_SUB(NOW(), INTERVAL 30 DAY)");
            $monthStmt->execute([$currentAcademicYearId]);
            $month_new_students = $monthStmt->fetchColumn();
        }
    } catch (Exception $e2) {
        $month_new_students = 0;
    }
}

// --- 2. Students per Grade (Distribution) ---
try {
    $grades_dist = $db->query("
        SELECT g.grade_name, COUNT(u.id) as student_count
        FROM grades g
        LEFT JOIN classes c ON c.grade_id = g.id
        {$_yearStudentsJoin}
        WHERE {$classScopeSql}
        GROUP BY g.id
        HAVING student_count > 0
        ORDER BY g.grade_order ASC
    ")->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) {
    $grades_dist = [];
}

// --- 3. Top Crowded Classes ---
try {
    $top_classes = $db->query("
        SELECT c.name as class_name, COUNT(u.id) as student_count
        FROM classes c
        {$_yearStudentsJoin}
        WHERE c.status = 'active' AND {$classScopeSql}
        GROUP BY c.id
        ORDER BY student_count DESC
        LIMIT 5
    ")->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) {
    $top_classes = [];
}

// --- 4. Monthly Registrations (Last 12 Months) ---
try {
    if ($allowedClassIds === null) {
        $monthly_regs = $db->query("
            SELECT DATE_FORMAT(created_at, '%Y-%m') as month_year, COUNT(*) as count
            FROM users
            WHERE role = 'student' AND deleted_at IS NULL AND created_at >= DATE_SUB(NOW(), INTERVAL 12 MONTH)
            GROUP BY month_year
            ORDER BY month_year ASC
        ")->fetchAll(PDO::FETCH_ASSOC);
    } else {
        $monthlyStmt = $db->prepare("SELECT DATE_FORMAT(u.created_at, '%Y-%m') as month_year, COUNT(DISTINCT u.id) as count
            FROM student_enrollments se
            JOIN users u ON u.id = se.student_id AND u.role = 'student' AND u.deleted_at IS NULL
            WHERE se.academic_year_id = ? AND {$enrollmentScopeSql}
              AND u.created_at >= DATE_SUB(NOW(), INTERVAL 12 MONTH)
            GROUP BY month_year ORDER BY month_year ASC");
        $monthlyStmt->execute([$currentAcademicYearId]);
        $monthly_regs = $monthlyStmt->fetchAll(PDO::FETCH_ASSOC);
    }
} catch (Exception $e) {
    try {
        if ($allowedClassIds === null) {
            $monthly_regs = $db->query("
                SELECT DATE_FORMAT(registration_date, '%Y-%m') as month_year, COUNT(*) as count
                FROM student_profiles sp
                JOIN users u ON u.id = sp.user_id
                WHERE u.deleted_at IS NULL AND sp.registration_date >= DATE_SUB(NOW(), INTERVAL 12 MONTH)
                GROUP BY month_year ORDER BY month_year ASC
            ")->fetchAll(PDO::FETCH_ASSOC);
        } else {
            $monthlyStmt = $db->prepare("SELECT DATE_FORMAT(sp.registration_date, '%Y-%m') as month_year, COUNT(DISTINCT u.id) as count
                FROM student_profiles sp
                JOIN users u ON u.id = sp.user_id AND u.deleted_at IS NULL
                JOIN student_enrollments se ON se.student_id = u.id AND se.academic_year_id = ?
                WHERE {$enrollmentScopeSql} AND sp.registration_date >= DATE_SUB(NOW(), INTERVAL 12 MONTH)
                GROUP BY month_year ORDER BY month_year ASC");
            $monthlyStmt->execute([$currentAcademicYearId]);
            $monthly_regs = $monthlyStmt->fetchAll(PDO::FETCH_ASSOC);
        }
    } catch (Exception $e2) {
        $monthly_regs = [];
    }
}

// --- 5. Age Demographics ---
try {
    $ageStmt = $db->prepare("SELECT 
            SUM(CASE WHEN TIMESTAMPDIFF(YEAR, sp.birth_date, CURDATE()) < 6 THEN 1 ELSE 0 END) as under_6,
            SUM(CASE WHEN TIMESTAMPDIFF(YEAR, sp.birth_date, CURDATE()) BETWEEN 6 AND 10 THEN 1 ELSE 0 END) as age_6_10,
            SUM(CASE WHEN TIMESTAMPDIFF(YEAR, sp.birth_date, CURDATE()) BETWEEN 11 AND 15 THEN 1 ELSE 0 END) as age_11_15,
            SUM(CASE WHEN TIMESTAMPDIFF(YEAR, sp.birth_date, CURDATE()) > 15 THEN 1 ELSE 0 END) as over_15
        FROM student_enrollments se
        JOIN users u ON u.id = se.student_id AND u.role = 'student' AND u.status = 'active' AND u.deleted_at IS NULL
        JOIN student_profiles sp ON u.id = sp.user_id
        WHERE se.academic_year_id = ? AND se.enrollment_status = 'enrolled' AND {$enrollmentScopeSql}
          AND sp.birth_date IS NOT NULL AND sp.birth_date != '0000-00-00'");
    $ageStmt->execute([$currentAcademicYearId]);
    $age_demo = $ageStmt->fetch(PDO::FETCH_ASSOC);
} catch (Exception $e) {
    $age_demo = ['under_6' => 0, 'age_6_10' => 0, 'age_11_15' => 0, 'over_15' => 0];
}

// --- 6. Recent Enrolled Students ---
try {
    $recentStmt = $db->prepare("SELECT u.name, se.created_at, c.name as class_name, u.status
        FROM student_enrollments se
        JOIN users u ON u.id = se.student_id AND u.role = 'student' AND u.deleted_at IS NULL
        LEFT JOIN classes c ON se.class_id = c.id
        WHERE se.academic_year_id = ? AND {$enrollmentScopeSql}
        ORDER BY se.created_at DESC
        LIMIT 5");
    $recentStmt->execute([$currentAcademicYearId]);
    $recent_students = $recentStmt->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) {
    $recent_students = [];
}

require_once '../includes/admin_header.php';
?>

<!-- Chart.js and Datalabels CDN -->
<script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.0/dist/chart.umd.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/chartjs-plugin-datalabels@2.2.0"></script>
<script>
    Chart.register(ChartDataLabels);
</script>



<div class="admin-page-heading no-print">
    <h1 class="h2"><i class="fas fa-chart-line me-2 text-primary"></i>التحليلات الذكية للطلاب</h1>
    <div class="admin-top-actions">
        <button type="button" class="btn btn-header-premium btn-print-soft" data-bs-toggle="offcanvas"
            data-bs-target="#dashboardSettings" aria-controls="dashboardSettings">
            <i class="fas fa-sliders-h me-1"></i>تخصيص لوحة الإحصائيات
        </button>
        <button type="button" onclick="window.print()" class="btn btn-header-premium btn-print-soft">
            <i class="fas fa-print me-1"></i>طباعة
        </button>
    </div>
</div>

<div class="dashboard-canvas sortable-dashboard">

    <!-- Row 1: KPI Cards -->
    <div class="row row-cols-1 row-cols-sm-2 row-cols-md-3 row-cols-xl-4 g-4 mb-4 widget-card-group sortable-dashboard"
        id="widget-kpi-1">
        <!-- إجمالي الطلاب -->
        <div class="col animate-up delay-1">
            <div class="stat-card" style="--card-gradient: var(--primary-gradient);">
                <div class="stat-card-icon"><i class="fas fa-users"></i></div>
                <div class="stat-card-info">
                    <div class="stat-card-number counter"
                        data-target="<?php echo (int) ($student_stats['total'] ?? 0); ?>">0</div>
                    <div class="stat-card-label">إجمالي الطلاب</div>
                </div>
            </div>
        </div>

        <!-- الطلاب النشطين -->
        <div class="col animate-up delay-2">
            <div class="stat-card" style="--card-gradient: var(--success-gradient);">
                <div class="stat-card-icon"><i class="fas fa-user-check"></i></div>
                <div class="stat-card-info">
                    <div class="stat-card-number counter"
                        data-target="<?php echo (int) ($student_stats['active_count'] ?? 0); ?>">0</div>
                    <div class="stat-card-label">طلاب نشطون</div>
                </div>
            </div>
        </div>

        <!-- انضموا أخيرًا -->
        <div class="col animate-up delay-3">
            <div class="stat-card" style="--card-gradient: var(--warning-gradient);">
                <div class="stat-card-icon"><i class="fas fa-user-plus"></i></div>
                <div class="stat-card-info">
                    <div class="stat-card-number counter" data-target="<?php echo (int) ($month_new_students ?? 0); ?>">0
                    </div>
                    <div class="stat-card-label">تسجيلات (30 يوم)</div>
                </div>
            </div>
        </div>

        <!-- الخريجين -->
        <div class="col animate-up delay-4">
            <div class="stat-card" style="--card-gradient: var(--info-gradient);">
                <div class="stat-card-icon"><i class="fas fa-graduation-cap"></i></div>
                <div class="stat-card-info">
                    <div class="stat-card-number counter"
                        data-target="<?php echo (int) ($student_stats['graduated_count'] ?? 0); ?>">0</div>
                    <div class="stat-card-label">إجمالي الخريجين</div>
                </div>
            </div>
        </div>
    </div>

    <!-- Row 2: Secondary KPI Cards -->
    <div class="row row-cols-1 row-cols-sm-2 row-cols-md-3 row-cols-xl-4 g-4 mb-5 widget-card-group sortable-dashboard"
        id="widget-kpi-2">
        <!-- ذكور -->
        <div class="col animate-up delay-1">
            <div class="stat-card" style="--card-gradient: linear-gradient(135deg, #0ea5e9, #0284c7);">
                <div class="stat-card-icon"><i class="fas fa-male"></i></div>
                <div class="stat-card-info">
                    <div class="stat-card-number counter"
                        data-target="<?php echo (int) ($gender_stats['male_count'] ?? 0); ?>">0</div>
                    <div class="stat-card-label">ذكور (نشط)</div>
                </div>
            </div>
        </div>

        <!-- إناث -->
        <div class="col animate-up delay-2">
            <div class="stat-card" style="--card-gradient: linear-gradient(135deg, #ec4899, #be185d);">
                <div class="stat-card-icon"><i class="fas fa-female"></i></div>
                <div class="stat-card-info">
                    <div class="stat-card-number counter"
                        data-target="<?php echo (int) ($gender_stats['female_count'] ?? 0); ?>">0</div>
                    <div class="stat-card-label">إناث (نشط)</div>
                </div>
            </div>
        </div>

        <!-- الفصول -->
        <div class="col animate-up delay-3">
            <div class="stat-card" style="--card-gradient: linear-gradient(135deg, #8b5cf6, #6d28d9);">
                <div class="stat-card-icon"><i class="fas fa-door-open"></i></div>
                <div class="stat-card-info">
                    <div class="stat-card-number counter"
                        data-target="<?php echo (int) ($student_classes_count ?? 0); ?>">0</div>
                    <div class="stat-card-label">الفصول الدراسية</div>
                </div>
            </div>
        </div>

        <!-- أرقام المراحل -->
        <div class="col animate-up delay-4">
            <div class="stat-card" style="--card-gradient: linear-gradient(135deg, #14b8a6, #0f766e);">
                <div class="stat-card-icon"><i class="fas fa-layer-group"></i></div>
                <div class="stat-card-info">
                    <div class="stat-card-number counter" data-target="<?php echo (int) ($grades_count ?? 0); ?>">0</div>
                    <div class="stat-card-label">الصفوف في <?php echo $stages_count; ?> مراحل</div>
                </div>
            </div>
        </div>
    </div>

    <!-- Row 3: Charts (Distribution & Trend) -->
    <div class="row g-4 mb-4 sortable-dashboard">
        <!-- Distribution By Grade -->
        <div class="col-lg-8" id="widget-chart-grade">
            <div class="premium-card h-100">
                <div class="card-header bg-white py-3 border-bottom d-flex align-items-center">
                    <i class="fas fa-grip-horizontal drag-handle me-2 text-muted" title="سحب لتغيير الترتيب"></i>
                    <h5 class="mb-0 fw-bold"><i class="fas fa-chart-column text-primary me-2"></i>توزيع الطلاب حسب الصف
                    </h5>
                </div>
                <div class="card-body">
                    <canvas id="gradeChart" height="140"></canvas>
                </div>
            </div>
        </div>

        <!-- Gender Doughnut & Status -->
        <div class="col-lg-4" id="widget-chart-gender">
            <div class="premium-card h-100">
                <div class="card-header bg-white py-3 border-bottom d-flex align-items-center">
                    <i class="fas fa-grip-horizontal drag-handle me-2 text-muted" title="سحب لتغيير الترتيب"></i>
                    <h5 class="mb-0 fw-bold"><i class="fas fa-venus-mars text-danger me-2"></i>التركيبة النوعية والحالة
                    </h5>
                </div>
                <div class="card-body">
                    <canvas id="genderChart" height="200"></canvas>
                </div>
            </div>
        </div>
    </div>

    <!-- Row 4: Charts (Age & Time Trend) -->
    <div class="row g-4 mb-4 sortable-dashboard">
        <!-- Monthly Registrations -->
        <div class="col-lg-8" id="widget-chart-trend">
            <div class="premium-card h-100">
                <div class="card-header bg-white py-3 border-bottom d-flex align-items-center">
                    <i class="fas fa-grip-horizontal drag-handle me-2 text-muted" title="سحب لتغيير الترتيب"></i>
                    <h5 class="mb-0 fw-bold"><i class="fas fa-chart-line text-success me-2"></i>نمو القبول والتسجيل (آخر
                        12 شهر)</h5>
                </div>
                <div class="card-body">
                    <canvas id="trendChart" height="100"></canvas>
                </div>
            </div>
        </div>

        <!-- Age Demographics -->
        <div class="col-lg-4" id="widget-chart-age">
            <div class="premium-card h-100">
                <div class="card-header bg-white py-3 border-bottom d-flex align-items-center">
                    <i class="fas fa-grip-horizontal drag-handle me-2 text-muted" title="سحب لتغيير الترتيب"></i>
                    <h5 class="mb-0 fw-bold"><i class="fas fa-birthday-cake text-warning me-2"></i>التركيبة العمرية
                        (نشط)</h5>
                </div>
                <div class="card-body">
                    <canvas id="ageChart" height="200"></canvas>
                </div>
            </div>
        </div>
    </div>

    <!-- Row 5: Tables (Crowded & Recent) -->
    <div class="row g-4 mb-4 sortable-dashboard" id="widget-tables">
        <!-- Top Classes -->
        <div class="col-lg-6" id="widget-table-classes">
            <div class="premium-card h-100">
                <div class="card-header bg-white py-3 border-bottom d-flex justify-content-between align-items-center">
                    <div class="d-flex align-items-center">
                        <i class="fas fa-grip-horizontal drag-handle me-2 text-muted" title="سحب لتغيير الترتيب"></i>
                        <h5 class="mb-0 fw-bold"><i class="fas fa-sort-amount-down text-info me-2"></i>الفصول الأعلى
                            كثافة (نشطة)</h5>
                    </div>
                    <button class="btn-header-premium btn-export-soft"
                        onclick="exportTableToCSV('table-crowded-classes', 'top_classes.csv')"><i
                            class="fas fa-download me-1"></i> تصدير Excel</button>
                </div>
                <div class="card-body">
                    <div class="table-responsive admin-table-wrap">
                        <table class="table table-hover table-striped align-middle mb-0 admin-data-table"
                            id="table-crowded-classes">
                            <thead class="table-light">
                                <tr>
                                    <th>الفصل</th>
                                    <th class="text-center">الكثافة (عدد الطلاب)</th>
                                    <th>مؤشر</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php if (!empty($top_classes)):
                                    foreach ($top_classes as $tc):
                                        $pct = min(100, ($tc['student_count'] / 50) * 100); // Visual max 50
                                        ?>
                                        <tr>
                                            <td class="fw-bold"><?php echo htmlspecialchars($tc['class_name'] ?: 'غير محدد'); ?>
                                            </td>
                                            <td class="text-center fw-bold text-primary"><?php echo $tc['student_count']; ?>
                                            </td>
                                            <td class="w-50">
                                                <div class="progress admin-progress-thin">
                                                    <div class="progress-bar bg-info" role="progressbar"
                                                        style="width: <?php echo $pct; ?>%"></div>
                                                </div>
                                            </td>
                                        </tr>
                                    <?php endforeach; else: ?>
                                    <tr>
                                        <td colspan="3" class="text-center text-muted">لا توجد بيانات</td>
                                    </tr>
                                <?php endif; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
        </div>

        <!-- Recent Students -->
        <div class="col-lg-6" id="widget-table-recent">
            <div class="premium-card h-100">
                <div class="card-header bg-white py-3 border-bottom d-flex justify-content-between align-items-center">
                    <div class="d-flex align-items-center">
                        <i class="fas fa-grip-horizontal drag-handle me-2 text-muted" title="سحب لتغيير الترتيب"></i>
                        <h5 class="mb-0 fw-bold"><i class="fas fa-history text-secondary me-2"></i>أحدث التسجيلات
                            بالمنظومة</h5>
                    </div>
                    <button class="btn-header-premium btn-export-soft"
                        onclick="exportTableToCSV('table-recent-students', 'recent_students.csv')"><i
                            class="fas fa-download me-1"></i> تصدير Excel</button>
                </div>
                <div class="card-body">
                    <div class="table-responsive admin-table-wrap">
                        <table class="table table-hover table-striped align-middle mb-0 admin-data-table"
                            id="table-recent-students">
                            <thead class="table-light">
                                <tr>
                                    <th>الاسم</th>
                                    <th>تاريخ الانضمام</th>
                                    <th>الفصل الحالي</th>
                                    <th>الحالة</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php if (!empty($recent_students)):
                                    foreach ($recent_students as $rs): ?>
                                        <tr>
                                            <td><i class="fas fa-user-circle text-muted me-1"></i>
                                                <?php echo htmlspecialchars($rs['name']); ?></td>
                                            <td dir="ltr" class="text-end">
                                                <?php echo date('Y-m-d', strtotime($rs['created_at'])); ?></td>
                                            <td><?php echo htmlspecialchars($rs['class_name'] ?: 'غير مسكن'); ?></td>
                                            <td>
                                                <?php if ($rs['status'] == 'active'): ?>
                                                    <span class="badge bg-success">نشط</span>
                                                <?php else: ?>
                                                    <span
                                                        class="badge bg-secondary"><?php echo htmlspecialchars($rs['status']); ?></span>
                                                <?php endif; ?>
                                            </td>
                                        </tr>
                                    <?php endforeach; else: ?>
                                    <tr>
                                        <td colspan="4" class="text-center text-muted">لا توجد تسجيلات حديثة</td>
                                    </tr>
                                <?php endif; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
        </div>
    </div>

</div>

</div>



<!-- Offcanvas Settings Panel -->
<div class="offcanvas offcanvas-end" tabindex="-1" id="dashboardSettings" aria-labelledby="dashboardSettingsLabel">
    <div class="offcanvas-header bg-dark text-white p-4">
        <h5 class="offcanvas-title fw-bold" id="dashboardSettingsLabel"><i class="fas fa-sliders-h me-2"></i> تخصيص لوحة
            القيادة</h5>
        <button type="button" class="btn-close btn-close-white text-reset" data-bs-dismiss="offcanvas"
            aria-label="Close"></button>
    </div>
    <div class="offcanvas-body p-4">
        <!-- Quick Presets -->
        <div class="mb-4">
            <h6 class="text-uppercase text-muted fw-bold small mb-3"><i class="fas fa-magic me-1 text-primary"></i>
                القوالب الجاهزة</h6>
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
                <input class="form-check-input widget-toggle ms-0" type="checkbox" id="toggle-widget-kpi-1"
                    data-target="widget-kpi-1" checked>
            </div>
            <div class="form-check form-switch mb-3 p-0 d-flex justify-content-between align-items-center">
                <label class="form-check-label fw-bold" for="toggle-widget-kpi-2">إحصائيات اجتماعية</label>
                <input class="form-check-input widget-toggle ms-0" type="checkbox" id="toggle-widget-kpi-2"
                    data-target="widget-kpi-2" checked>
            </div>
        </div>

        <div class="mb-4 border-top pt-3">
            <h6 class="text-uppercase text-muted fw-bold small mb-3">الرسوم البيانية</h6>
            <div class="form-check form-switch mb-3 p-0 d-flex justify-content-between align-items-center">
                <label class="form-check-label fw-bold" for="toggle-widget-chart-grade">توزيع الصفوف</label>
                <input class="form-check-input widget-toggle ms-0" type="checkbox" id="toggle-widget-chart-grade"
                    data-target="widget-chart-grade" checked>
            </div>
            <div class="form-check form-switch mb-3 p-0 d-flex justify-content-between align-items-center">
                <label class="form-check-label fw-bold" for="toggle-widget-chart-gender">التركيبة النوعية</label>
                <input class="form-check-input widget-toggle ms-0" type="checkbox" id="toggle-widget-chart-gender"
                    data-target="widget-chart-gender" checked>
            </div>
            <div class="form-check form-switch mb-3 p-0 d-flex justify-content-between align-items-center">
                <label class="form-check-label fw-bold" for="toggle-widget-chart-trend">نمو التسجيل</label>
                <input class="form-check-input widget-toggle ms-0" type="checkbox" id="toggle-widget-chart-trend"
                    data-target="widget-chart-trend" checked>
            </div>
            <div class="form-check form-switch mb-3 p-0 d-flex justify-content-between align-items-center">
                <label class="form-check-label fw-bold" for="toggle-widget-chart-age">التركيبة العمرية</label>
                <input class="form-check-input widget-toggle ms-0" type="checkbox" id="toggle-widget-chart-age"
                    data-target="widget-chart-age" checked>
            </div>
        </div>

        <div class="mb-4 border-top pt-3">
            <h6 class="text-uppercase text-muted fw-bold small mb-3">التقارير والجداول</h6>
            <div class="form-check form-switch mb-3 p-0 d-flex justify-content-between align-items-center">
                <label class="form-check-label fw-bold" for="toggle-widget-table-classes">الفصول الكثيفة</label>
                <input class="form-check-input widget-toggle ms-0" type="checkbox" id="toggle-widget-table-classes"
                    data-target="widget-table-classes" checked>
            </div>
            <div class="form-check form-switch mb-3 p-0 d-flex justify-content-between align-items-center">
                <label class="form-check-label fw-bold" for="toggle-widget-table-recent">أحدث التسجيلات</label>
                <input class="form-check-input widget-toggle ms-0" type="checkbox" id="toggle-widget-table-recent"
                    data-target="widget-table-recent" checked>
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

<!-- Initialize Charts -->
<script>
    // === Dashboard Customizer Logic ===
    function initDashboardCustomizer() {
        const STORAGE_KEY = 'eduCoreDashboardPrefs';
        const defaultWidgets = [
            'widget-kpi-1', 'widget-kpi-2', 'widget-chart-grade',
            'widget-chart-gender', 'widget-chart-trend', 'widget-chart-age',
            'widget-table-classes', 'widget-table-recent'
        ];

        // Define Presets
        const presets = {
            all: {
                'widget-kpi-1': true, 'widget-kpi-2': true,
                'widget-chart-grade': true, 'widget-chart-gender': true, 'widget-chart-trend': true, 'widget-chart-age': true,
                'widget-table-classes': true, 'widget-table-recent': true
            },
            kpis: {
                'widget-kpi-1': true, 'widget-kpi-2': true,
                'widget-chart-grade': false, 'widget-chart-gender': false, 'widget-chart-trend': false, 'widget-chart-age': false,
                'widget-table-classes': false, 'widget-table-recent': false
            },
            charts: {
                'widget-kpi-1': false, 'widget-kpi-2': false,
                'widget-chart-grade': true, 'widget-chart-gender': true, 'widget-chart-trend': true, 'widget-chart-age': true,
                'widget-table-classes': false, 'widget-table-recent': false
            },
            tables: {
                'widget-kpi-1': false, 'widget-kpi-2': false,
                'widget-chart-grade': false, 'widget-chart-gender': false, 'widget-chart-trend': false, 'widget-chart-age': false,
                'widget-table-classes': true, 'widget-table-recent': true
            }
        };

        let prefs = JSON.parse(localStorage.getItem(STORAGE_KEY) || '{}');
        const hasPrefs = localStorage.getItem(STORAGE_KEY) !== null;

        // Add pulse effect if no preferences are saved yet
        const floatBtn = document.querySelector('.btn-gear-float');
        if (!hasPrefs && floatBtn) {
            floatBtn.classList.add('pulse-effect');
        }

        // Helper functions for smooth hiding/showing of cards
        const showWidget = (el) => {
            if (!el) return;
            el.classList.remove('d-none');
            el.classList.add('card-fade-transition');
            el.classList.add('card-fade-hidden');
            // Force reflow
            el.offsetHeight;
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

        // Update customization badge
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

        // Apply Preferences
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

        // Handle Toggles
        document.querySelectorAll('.widget-toggle').forEach(t => {
            t.addEventListener('change', function () {
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

        // Handle Presets
        document.querySelectorAll('.preset-btn').forEach(btn => {
            btn.addEventListener('click', function () {
                const presetKey = this.dataset.preset;
                if (presets[presetKey]) {
                    if (floatBtn) floatBtn.classList.remove('pulse-effect');

                    prefs = { ...presets[presetKey] };
                    localStorage.setItem(STORAGE_KEY, JSON.stringify(prefs));
                    applyPrefs(true);
                }
            });
        });

        // Handle Reset Defaults
        const resetBtn = document.getElementById('reset-dashboard-prefs');
        if (resetBtn) {
            resetBtn.addEventListener('click', function () {
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
                if (j === cols.length - 1 && cols[j].querySelector('.progress')) continue; // Skip progress col
                let text = cols[j].innerText.replace(/(\r\n|\n|\r)/gm, "").trim();
                row.push('"' + text + '"');
            }
            if (row.length > 0) csv.push(row.join(","));
        }

        var csvFile = new Blob(["\uFEFF" + csv.join("\n")], { type: "text/csv;charset=utf-8;" });
        var downloadLink = document.createElement("a");
        downloadLink.download = filename;
        downloadLink.href = window.URL.createObjectURL(csvFile);
        downloadLink.style.display = "none";
        document.body.appendChild(downloadLink);
        downloadLink.click();
        document.body.removeChild(downloadLink);
    }

    document.addEventListener("DOMContentLoaded", function () {
        initDashboardCustomizer();
        // 1. Grade Distribution Chart
        const gradeCtx = document.getElementById('gradeChart').getContext('2d');
        const gradeData = <?php echo json_encode($grades_dist); ?>;

        new Chart(gradeCtx, {
            type: 'bar',
            data: {
                labels: gradeData.map(d => d.grade_name || 'غير محدد'),
                datasets: [{
                    label: 'عدد الطلاب النشطين',
                    data: gradeData.map(d => d.student_count),
                    backgroundColor: 'rgba(59, 130, 246, 0.7)',
                    borderColor: 'rgba(37, 99, 235, 1)',
                    borderWidth: 1,
                    borderRadius: 4
                }]
            },
            options: {
                indexAxis: 'y',
                responsive: true,
                layout: {
                    padding: {
                        right: 35
                    }
                },
                scales: {
                    x: { beginAtZero: true, suggestedMax: 10 }
                },
                plugins: {
                    legend: { display: false },
                    datalabels: {
                        anchor: function(context) {
                            return context.dataset.data[context.dataIndex] > 15 ? 'end' : 'end';
                        },
                        align: function(context) {
                            return context.dataset.data[context.dataIndex] > 15 ? 'start' : 'right';
                        },
                        color: function(context) {
                            return context.dataset.data[context.dataIndex] > 15 ? '#ffffff' : '#1e293b';
                        },
                        font: {
                            weight: 'bold',
                            size: 10
                        },
                        formatter: function(value, context) {
                            let sum = context.chart.data.datasets[0].data.reduce((a, b) => Number(a) + Number(b), 0);
                            if (sum === 0) return '0';
                            let percentage = (Number(value) * 100 / sum).toFixed(1) + "%";
                            return value + " (" + percentage + ")";
                        }
                    }
                }
            }
        });

        // 2. Gender Doughnut Chart
        const genderCtx = document.getElementById('genderChart').getContext('2d');
        const maleC = <?php echo (int) ($gender_stats['male_count'] ?? 0); ?>;
        const femaleC = <?php echo (int) ($gender_stats['female_count'] ?? 0); ?>;

        new Chart(genderCtx, {
            type: 'doughnut',
            data: {
                labels: ['ذكور', 'إناث'],
                datasets: [{
                    data: [maleC, femaleC],
                    backgroundColor: [
                        'rgba(14, 165, 233, 0.8)', // blue
                        'rgba(236, 72, 153, 0.8)'  // pink
                    ],
                    borderWidth: 2,
                    borderColor: '#ffffff'
                }]
            },
            options: {
                responsive: true,
                cutout: '65%',
                layout: {
                    padding: 15
                },
                plugins: {
                    legend: { position: 'bottom' },
                    datalabels: {
                        anchor: 'center',
                        align: 'center',
                        color: '#ffffff',
                        font: { weight: 'bold', size: 11 },
                        display: function(context) {
                            let sum = context.dataset.data.reduce((a, b) => Number(a) + Number(b), 0);
                            if (sum === 0) return false;
                            let pct = (Number(context.dataset.data[context.dataIndex]) * 100 / sum);
                            return pct >= 7;
                        },
                        formatter: function(value, context) {
                            let sum = context.chart.data.datasets[0].data.reduce((a, b) => Number(a) + Number(b), 0);
                            if (sum === 0) return '';
                            let pct = (Number(value) * 100 / sum).toFixed(1) + "%";
                            return value + "\n" + pct;
                        }
                    }
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
                    label: 'تسجيلات جديدة',
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
                layout: {
                    padding: {
                        top: 25
                    }
                },
                scales: {
                    y: { beginAtZero: true, suggestedMax: 5 }
                },
                plugins: {
                    legend: { display: false },
                    datalabels: {
                        anchor: 'end',
                        align: 'top',
                        color: '#059669',
                        font: {
                            weight: 'bold',
                            size: 10
                        },
                        formatter: function(value) {
                            return Number(value) > 0 ? value : '';
                        }
                    }
                }
            }
        });

        // 4. Age Demographics Pie Chart
        const ageCtx = document.getElementById('ageChart').getContext('2d');
        const ageData = <?php echo json_encode($age_demo); ?>;

        new Chart(ageCtx, {
            type: 'pie',
            data: {
                labels: ['أقل من 6', '6 - 10 سنوات', '11 - 15 سنة', '16 وأكبر'],
                datasets: [{
                    data: [
                        ageData.under_6 || 0,
                        ageData.age_6_10 || 0,
                        ageData.age_11_15 || 0,
                        ageData.over_15 || 0
                    ],
                    backgroundColor: [
                        '#f59e0b', '#8b5cf6', '#3b82f6', '#10b981'
                    ],
                    borderWidth: 2,
                    borderColor: '#ffffff'
                }]
            },
            options: {
                responsive: true,
                layout: {
                    padding: 15
                },
                plugins: {
                    legend: { position: 'bottom' },
                    datalabels: {
                        anchor: 'center',
                        align: 'center',
                        color: '#ffffff',
                        font: { weight: 'bold', size: 11 },
                        display: function(context) {
                            let sum = context.dataset.data.reduce((a, b) => Number(a) + Number(b), 0);
                            if (sum === 0) return false;
                            let pct = (Number(context.dataset.data[context.dataIndex]) * 100 / sum);
                            return pct >= 7;
                        },
                        formatter: function(value, context) {
                            let sum = context.chart.data.datasets[0].data.reduce((a, b) => Number(a) + Number(b), 0);
                            if (sum === 0) return '';
                            let pct = (Number(value) * 100 / sum).toFixed(1) + "%";
                            return value + "\n" + pct;
                        }
                    }
                }
            }
        });
    });
</script>

<?php include_once '../includes/admin_footer.php'; ?>
