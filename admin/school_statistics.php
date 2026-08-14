<?php
$page_title = "إحصائيات الهيكل المدرسي";
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

// بند JOIN الموحّد لطلاب العام الحالي (يُستبدل u.class_id بالتسجيلات السنوية)
$_yearStudentsJoin = $currentAcademicYearId > 0
    ? "LEFT JOIN student_enrollments se ON se.class_id = c.id AND se.academic_year_id = {$currentAcademicYearId} AND se.enrollment_status = 'enrolled'
       LEFT JOIN users u ON u.id = se.student_id AND u.role = 'student' AND u.status='active' AND u.deleted_at IS NULL"
    : "LEFT JOIN users u ON u.class_id = c.id AND u.role = 'student' AND u.status='active' AND u.deleted_at IS NULL AND NOT EXISTS (SELECT 1 FROM student_profiles sp WHERE sp.user_id=u.id AND sp.enrollment_status <> 'enrolled')";

// شرط فلترة الفصول بالعام الدراسي
$_yearClassesFilter = $currentAcademicYearId > 0
    ? "AND (academic_year_id = {$currentAcademicYearId} OR academic_year_id IS NULL)"
    : "";
$_yearClassesJoinCond = $currentAcademicYearId > 0
    ? "AND (c.academic_year_id = {$currentAcademicYearId} OR c.academic_year_id IS NULL)"
    : "";

// ============================================================
// استعلامات البيانات
// ============================================================

// إجمالي المراحل والصفوف والفصول والطلاب
if ($currentAcademicYearId > 0) {
    $overviewStmt = $db->prepare("
        SELECT
            (SELECT COUNT(*) FROM stages) AS total_stages,
            (SELECT COUNT(*) FROM stages WHERE status = 'active') AS active_stages,
            (SELECT COUNT(*) FROM grades) AS total_grades,
            (SELECT COUNT(*) FROM grades WHERE status = 'active') AS active_grades,
            (SELECT COUNT(*) FROM classes WHERE (academic_year_id = ? OR academic_year_id IS NULL)) AS total_classes,
            (SELECT COUNT(*) FROM classes WHERE status = 'active' AND (academic_year_id = ? OR academic_year_id IS NULL)) AS active_classes,
            (SELECT COUNT(DISTINCT se.student_id) FROM student_enrollments se JOIN users u ON u.id = se.student_id WHERE se.academic_year_id = ? AND u.role = 'student' AND u.deleted_at IS NULL) AS total_students,
            (SELECT COUNT(DISTINCT se.student_id) FROM student_enrollments se JOIN users u ON u.id = se.student_id WHERE se.academic_year_id = ? AND se.enrollment_status = 'enrolled' AND u.role = 'student' AND u.status = 'active' AND u.deleted_at IS NULL) AS active_students
    ");
    $overviewStmt->execute([$currentAcademicYearId, $currentAcademicYearId, $currentAcademicYearId, $currentAcademicYearId]);
} else {
    $overviewStmt = $db->query("
        SELECT
            (SELECT COUNT(*) FROM stages) AS total_stages,
            (SELECT COUNT(*) FROM stages WHERE status = 'active') AS active_stages,
            (SELECT COUNT(*) FROM grades) AS total_grades,
            (SELECT COUNT(*) FROM grades WHERE status = 'active') AS active_grades,
            (SELECT COUNT(*) FROM classes) AS total_classes,
            (SELECT COUNT(*) FROM classes WHERE status = 'active') AS active_classes,
            (SELECT COUNT(*) FROM users WHERE role = 'student' AND deleted_at IS NULL) AS total_students,
            (SELECT COUNT(*) FROM users u WHERE u.role = 'student' AND u.status = 'active' AND u.deleted_at IS NULL AND NOT EXISTS (SELECT 1 FROM student_profiles sp WHERE sp.user_id=u.id AND sp.enrollment_status <> 'enrolled')) AS active_students
    ");
}
$overview = $overviewStmt->fetch(PDO::FETCH_ASSOC);

// توزيع الطلاب حسب المرحلة
$stageDistStmt = $db->query("
    SELECT s.stage_name, s.stage_code,
           COUNT(DISTINCT g.id) AS grades_count,
           COUNT(DISTINCT c.id) AS classes_count,
           COUNT(DISTINCT u.id) AS students_count
    FROM stages s
    LEFT JOIN grades g ON g.stage_id = s.id
    LEFT JOIN classes c ON c.grade_id = g.id {$_yearClassesJoinCond}
    {$_yearStudentsJoin}
    GROUP BY s.id
    ORDER BY s.stage_order
");
$stageDistribution = $stageDistStmt->fetchAll(PDO::FETCH_ASSOC);

// توزيع الطلاب حسب الصف
$gradeDistStmt = $db->query("
    SELECT g.grade_name, s.stage_name,
           COUNT(DISTINCT c.id) AS classes_count,
           COUNT(DISTINCT u.id) AS students_count
    FROM grades g
    LEFT JOIN stages s ON s.id = g.stage_id
    LEFT JOIN classes c ON c.grade_id = g.id {$_yearClassesJoinCond}
    {$_yearStudentsJoin}
    GROUP BY g.id
    ORDER BY g.grade_order
");
$gradeDistribution = $gradeDistStmt->fetchAll(PDO::FETCH_ASSOC);

// أكبر 10 فصول من حيث عدد الطلاب
$topClassesStmt = $db->query("
    SELECT c.name AS class_name, g.grade_name, s.stage_name,
           COUNT(u.id) AS students_count
    FROM classes c
    LEFT JOIN grades g ON g.id = c.grade_id
    LEFT JOIN stages s ON s.id = g.stage_id
    {$_yearStudentsJoin}
    WHERE c.status = 'active' {$_yearClassesJoinCond}
    GROUP BY c.id
    ORDER BY students_count DESC
    LIMIT 10
");
$topClasses = $topClassesStmt->fetchAll(PDO::FETCH_ASSOC);

// فصول بدون طلاب
$emptyClassesStmt = $db->query("
    SELECT c.name, g.grade_name, s.stage_name, c.status
    FROM classes c
    LEFT JOIN grades g ON g.id = c.grade_id
    LEFT JOIN stages s ON s.id = g.stage_id
    {$_yearStudentsJoin}
    WHERE c.status = 'active' {$_yearClassesJoinCond}
    GROUP BY c.id
    HAVING COUNT(u.id) = 0
    ORDER BY s.stage_order, g.grade_order, c.name
");
$emptyClasses = $emptyClassesStmt->fetchAll(PDO::FETCH_ASSOC);

// متوسط عدد الطلاب لكل فصل (لكل مرحلة)
if ($currentAcademicYearId > 0) {
    $avgPerStageStmt = $db->prepare("
        SELECT s.stage_name,
               ROUND(AVG(COALESCE(class_counts.cnt, 0)), 1) AS avg_students_per_class,
               MAX(COALESCE(class_counts.cnt, 0)) AS max_students,
               MIN(COALESCE(class_counts.cnt, 0)) AS min_students
        FROM stages s
        JOIN grades g ON g.stage_id = s.id
        JOIN classes c ON c.grade_id = g.id AND c.status = 'active' AND (c.academic_year_id = ? OR c.academic_year_id IS NULL)
        LEFT JOIN (
            SELECT se.class_id, COUNT(DISTINCT se.student_id) AS cnt
            FROM student_enrollments se
            JOIN users u ON u.id = se.student_id AND u.role = 'student' AND u.status = 'active' AND u.deleted_at IS NULL
            WHERE se.academic_year_id = ? AND se.enrollment_status = 'enrolled'
            GROUP BY se.class_id
        ) class_counts ON class_counts.class_id = c.id
        GROUP BY s.id
        ORDER BY s.stage_order
    ");
    $avgPerStageStmt->execute([$currentAcademicYearId, $currentAcademicYearId]);
} else {
    $avgPerStageStmt = $db->query("
        SELECT s.stage_name,
               ROUND(AVG(COALESCE(class_counts.cnt, 0)), 1) AS avg_students_per_class,
               MAX(COALESCE(class_counts.cnt, 0)) AS max_students,
               MIN(COALESCE(class_counts.cnt, 0)) AS min_students
        FROM stages s
        JOIN grades g ON g.stage_id = s.id
        JOIN classes c ON c.grade_id = g.id AND c.status = 'active'
        LEFT JOIN (
            SELECT u.class_id, COUNT(*) AS cnt
            FROM users u
            WHERE u.role = 'student' AND u.status = 'active' AND u.deleted_at IS NULL
              AND NOT EXISTS (SELECT 1 FROM student_profiles sp WHERE sp.user_id = u.id AND sp.enrollment_status <> 'enrolled')
            GROUP BY u.class_id
        ) class_counts ON class_counts.class_id = c.id
        GROUP BY s.id
        ORDER BY s.stage_order
    ");
}
$avgPerStage = $avgPerStageStmt->fetchAll(PDO::FETCH_ASSOC);

// معدل الشغل (نسبة الفصول النشطة بطلاب)
$totalClasses = (int)$overview['active_classes'];
$emptyCount   = count($emptyClasses);
$occupancyRate = $totalClasses > 0 ? round((($totalClasses - $emptyCount) / $totalClasses) * 100) : 0;

// مجموع الطلاب لحساب النسب
$totalStudents = (int)$overview['total_students'];
$distributionStudents = (int)$overview['active_students'];

// ألوان للمراحل
$stageColors = ['#6366f1','#0ea5e9','#10b981','#f59e0b','#ef4444','#8b5cf6','#14b8a6'];

require_once '../includes/admin_header.php';
?>

<style>
.stage-bar-wrap { background: #f1f5f9; border-radius: 10px; overflow: hidden; height: 14px; }
.stage-bar { height: 100%; border-radius: 10px; transition: width 1s ease; }

.chart-donut-wrap { position: relative; }
.chart-legend-dot { width: 12px; height: 12px; border-radius: 50%; display: inline-block; }

.class-rank-badge {
    width: 32px; height: 32px; border-radius: 50%;
    display: flex; align-items: center; justify-content: center;
    font-weight: 700; font-size: .85rem; flex-shrink: 0;
}

.section-title-bar {
    border-right: 4px solid;
    padding-right: 12px;
    margin-bottom: 1.2rem;
}

@keyframes countUp {
    from { opacity: 0; transform: translateY(8px); }
    to   { opacity: 1; transform: translateY(0); }
}
.animate-count { animation: countUp .6s ease forwards; }
</style>

<!-- Page Header -->
<div class="d-flex justify-content-between flex-wrap flex-md-nowrap align-items-center pt-3 pb-2 mb-4 border-bottom animate-up">
    <div>
        <h1 class="h2 fw-bold text-dark"><i class="fas fa-chart-pie me-3 text-primary"></i>إحصائيات الهيكل المدرسي</h1>
        <p class="text-muted m-0">نظرة شاملة على توزيع الطلاب والفصول والمراحل الدراسية</p>
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

<!-- Hero Stats Row -->
<div class="dashboard-canvas sortable-dashboard">

<div class="row g-3 mb-4 animate-up delay-1 sortable-dashboard" id="widget-hero">
    <div class="col-6 col-md-3">
        <div class="stat-card" style="--card-gradient: linear-gradient(135deg,#6366f1,#4f46e5);">
            <div class="stat-card-icon"><i class="fas fa-layer-group"></i></div>
            <div class="stat-card-badge"><?php echo (int)$overview['active_stages']; ?> نشطة</div>
            <div class="stat-card-info">
                <div class="stat-card-number counter" data-target="<?php echo (int)$overview['total_stages']; ?>">0</div>
                <div class="stat-card-label">إجمالي المراحل</div>
            </div>
        </div>
    </div>
    <div class="col-6 col-md-3">
        <div class="stat-card" style="--card-gradient: linear-gradient(135deg,#0ea5e9,#0284c7);">
            <div class="stat-card-icon"><i class="fas fa-graduation-cap"></i></div>
            <div class="stat-card-badge"><?php echo (int)$overview['active_grades']; ?> نشط</div>
            <div class="stat-card-info">
                <div class="stat-card-number counter" data-target="<?php echo (int)$overview['total_grades']; ?>">0</div>
                <div class="stat-card-label">إجمالي الصفوف</div>
            </div>
        </div>
    </div>
    <div class="col-6 col-md-3">
        <div class="stat-card" style="--card-gradient: linear-gradient(135deg,#f59e0b,#d97706);">
            <div class="stat-card-icon"><i class="fas fa-door-open"></i></div>
            <div class="stat-card-badge"><?php echo (int)$overview['active_classes']; ?> نشط</div>
            <div class="stat-card-info">
                <div class="stat-card-number counter" data-target="<?php echo (int)$overview['total_classes']; ?>">0</div>
                <div class="stat-card-label">إجمالي الفصول</div>
            </div>
        </div>
    </div>
    <div class="col-6 col-md-3">
        <div class="stat-card" style="--card-gradient: linear-gradient(135deg,#10b981,#059669);">
            <div class="stat-card-icon"><i class="fas fa-user-graduate"></i></div>
            <div class="stat-card-badge"><?php echo (int)$overview['active_students']; ?> نشط</div>
            <div class="stat-card-info">
                <div class="stat-card-number counter" data-target="<?php echo $totalStudents; ?>">0</div>
                <div class="stat-card-label">إجمالي الطلاب</div>
            </div>
        </div>
    </div>
</div>

<!-- Row: Stage Distribution + Occupancy -->
<div class="row g-4 mb-4 animate-up delay-2 sortable-dashboard" id="widget-stage-dist">

    <!-- توزيع الطلاب حسب المرحلة -->
    <div class="col-lg-7" id="widget-stage-table">
        <div class="premium-card h-100">
            <div class="card-header bg-white py-3 border-bottom d-flex align-items-center">
                <i class="fas fa-grip-horizontal drag-handle me-2 text-muted" style="cursor: grab;" title="سحب لتغيير الترتيب"></i>
                <h5 class="mb-0 fw-bold"><i class="fas fa-chart-bar me-2 text-primary"></i>توزيع الطلاب حسب المرحلة الدراسية</h5>
            </div>
            <div class="card-body">
                <?php if (empty($stageDistribution)): ?>
                    <div class="alert alert-info mb-0"><i class="fas fa-info-circle me-2"></i>لا توجد بيانات بعد.</div>
                <?php else: ?>
                    <div class="table-responsive">
                        <table class="table table-hover align-middle mb-0">
                            <thead class="table-light">
                                <tr>
                                    <th>المرحلة</th>
                                    <th class="text-center">الصفوف</th>
                                    <th class="text-center">الفصول</th>
                                    <th class="text-center">الطلاب</th>
                                    <th style="width:30%">النسبة</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($stageDistribution as $i => $row): ?>
                                <?php
                                    $color = $stageColors[$i % count($stageColors)];
                                    $pct = $distributionStudents > 0 ? round(($row['students_count'] / $distributionStudents) * 100, 1) : 0;
                                ?>
                                <tr>
                                    <td>
                                        <span class="d-flex align-items-center gap-2">
                                            <span class="chart-legend-dot" style="background:<?php echo $color; ?>"></span>
                                            <strong><?php echo htmlspecialchars($row['stage_name']); ?></strong>
                                        </span>
                                    </td>
                                    <td class="text-center"><span class="badge bg-primary-subtle text-primary"><?php echo (int)$row['grades_count']; ?></span></td>
                                    <td class="text-center"><span class="badge bg-warning-subtle text-warning"><?php echo (int)$row['classes_count']; ?></span></td>
                                    <td class="text-center fw-bold" style="color:<?php echo $color; ?>"><?php echo (int)$row['students_count']; ?></td>
                                    <td>
                                        <div class="d-flex align-items-center gap-2">
                                            <div class="stage-bar-wrap flex-grow-1">
                                                <div class="stage-bar" style="width:<?php echo $pct; ?>%; background:<?php echo $color; ?>"></div>
                                            </div>
                                            <small class="text-muted fw-bold" style="min-width:38px"><?php echo $pct; ?>%</small>
                                        </div>
                                    </td>
                                </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                <?php endif; ?>
            </div>
        </div>
    </div>

    <!-- بطاقات الكفاءة -->
    <div class="col-lg-5" id="widget-occupancy-cards">
        <div class="row g-3 h-100">
            <!-- معدل شغل الفصول -->
            <div class="col-12">
                <div class="premium-card">
                    <div class="card-header bg-white py-2 border-bottom d-flex align-items-center">
                        <i class="fas fa-grip-horizontal drag-handle me-2 text-muted" style="cursor: grab;" title="سحب لتغيير الترتيب"></i>
                        <h6 class="mb-0 fw-bold"><i class="fas fa-percentage text-success me-2"></i>معدل شغل الفصول</h6>
                    </div>
                    <div class="card-body text-center py-4">
                        <div style="position:relative; display:inline-block;">
                            <svg width="140" height="140" viewBox="0 0 140 140">
                                <circle cx="70" cy="70" r="58" fill="none" stroke="#e2e8f0" stroke-width="14"/>
                                <circle cx="70" cy="70" r="58" fill="none"
                                    stroke="<?php echo $occupancyRate >= 80 ? '#10b981' : ($occupancyRate >= 50 ? '#f59e0b' : '#ef4444'); ?>"
                                    stroke-width="14"
                                    stroke-dasharray="<?php echo round(2 * 3.14159 * 58 * $occupancyRate / 100); ?> 400"
                                    stroke-dashoffset="91"
                                    stroke-linecap="round"
                                    transform="rotate(-90 70 70)"/>
                            </svg>
                            <div style="position:absolute; top:50%; left:50%; transform:translate(-50%,-50%); text-align:center;">
                                <div style="font-size:1.8rem; font-weight:800; color:#1e293b;"><?php echo $occupancyRate; ?>%</div>
                                <div style="font-size:.7rem; color:#64748b;">الإشغال</div>
                            </div>
                        </div>
                        <p class="text-muted small mb-0">
                            <?php echo $totalClasses - $emptyCount; ?> فصل مشغول من أصل <?php echo $totalClasses; ?>
                        </p>
                    </div>
                </div>
            </div>

            <!-- متوسط الطلاب لكل فصل -->
            <div class="col-12">
                <div class="premium-card">
                    <div class="card-header bg-white py-2 border-bottom d-flex align-items-center">
                        <i class="fas fa-grip-horizontal drag-handle me-2 text-muted" style="cursor: grab;" title="سحب لتغيير الترتيب"></i>
                        <h6 class="mb-0 fw-bold"><i class="fas fa-users me-2 text-info"></i>متوسط الطلاب / فصل لكل مرحلة</h6>
                    </div>
                    <div class="card-body py-2">
                        <?php if (empty($avgPerStage)): ?>
                            <p class="text-muted small mb-0">لا بيانات</p>
                        <?php else: ?>
                            <?php foreach ($avgPerStage as $i => $row): ?>
                            <?php $c = $stageColors[$i % count($stageColors)]; ?>
                            <div class="d-flex align-items-center justify-content-between py-1 border-bottom border-opacity-25">
                                <div class="d-flex align-items-center gap-2">
                                    <span class="chart-legend-dot" style="background:<?php echo $c; ?>"></span>
                                    <small class="fw-semibold"><?php echo htmlspecialchars($row['stage_name']); ?></small>
                                </div>
                                <div class="d-flex gap-3">
                                    <small class="text-muted">متوسط: <strong style="color:<?php echo $c; ?>"><?php echo $row['avg_students_per_class']; ?></strong></small>
                                    <small class="text-muted">أقصى: <strong class="text-danger"><?php echo $row['max_students']; ?></strong></small>
                                </div>
                            </div>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

<!-- Row: Grade Distribution table + Top Classes -->
<div class="row g-4 mb-4 animate-up delay-3 sortable-dashboard" id="widget-grade-dist">

    <!-- توزيع حسب الصف -->
    <div class="col-lg-6" id="widget-grade-table">
        <div class="premium-card h-100">
            <div class="card-header bg-white py-3 border-bottom d-flex align-items-center">
                <i class="fas fa-grip-horizontal drag-handle me-2 text-muted" style="cursor: grab;" title="سحب لتغيير الترتيب"></i>
                <h5 class="mb-0 fw-bold"><i class="fas fa-graduation-cap me-2 text-success"></i>توزيع الطلاب حسب الصف</h5>
            </div>
            <div class="card-body p-0">
                <div class="table-responsive" style="max-height:360px; overflow-y:auto;">
                    <table class="table table-sm table-hover align-middle mb-0">
                        <thead class="table-light sticky-top">
                            <tr>
                                <th>الصف</th>
                                <th>المرحلة</th>
                                <th class="text-center">فصول</th>
                                <th class="text-center">طلاب</th>
                                <th style="width:28%">النسبة</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($gradeDistribution as $i => $row): ?>
                            <?php
                                $c = $stageColors[$i % count($stageColors)];
                                $p = $distributionStudents > 0 ? round(($row['students_count'] / $distributionStudents) * 100, 1) : 0;
                            ?>
                            <tr>
                                <td><strong><?php echo htmlspecialchars($row['grade_name']); ?></strong></td>
                                <td><span class="badge bg-secondary-subtle text-secondary" style="font-size:.72rem;"><?php echo htmlspecialchars($row['stage_name'] ?? '—'); ?></span></td>
                                <td class="text-center"><?php echo (int)$row['classes_count']; ?></td>
                                <td class="text-center fw-bold" style="color:<?php echo $c; ?>"><?php echo (int)$row['students_count']; ?></td>
                                <td>
                                    <div style="background:#f1f5f9; border-radius:6px; height:8px; overflow:hidden;">
                                        <div style="width:<?php echo $p; ?>%; height:100%; background:<?php echo $c; ?>; border-radius:6px; transition:width 1s ease;"></div>
                                    </div>
                                </td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </div>

    <!-- أعلى الفصول -->
    <div class="col-lg-6" id="widget-top-classes-list">
        <div class="premium-card h-100">
            <div class="card-header bg-white py-3 border-bottom d-flex align-items-center">
                <i class="fas fa-grip-horizontal drag-handle me-2 text-muted" style="cursor: grab;" title="سحب لتغيير الترتيب"></i>
                <h5 class="mb-0 fw-bold"><i class="fas fa-trophy me-2 text-warning"></i>أعلى الفصول من حيث عدد الطلاب</h5>
            </div>
            <div class="card-body p-0">
                <div class="p-3" style="max-height:360px; overflow-y:auto;">
                    <?php if (empty($topClasses)): ?>
                        <div class="alert alert-info mb-0 py-2"><i class="fas fa-info-circle me-2"></i>لا توجد بيانات.</div>
                    <?php else: ?>
                        <?php
                        $medalColors = ['#f59e0b','#94a3b8','#d97706'];
                        $maxStudents = max(array_column($topClasses, 'students_count')) ?: 1;
                        ?>
                        <?php foreach ($topClasses as $rank => $cls): ?>
                        <?php
                            $rankColor = $rank < 3 ? $medalColors[$rank] : '#6366f1';
                            $barPct = round(($cls['students_count'] / $maxStudents) * 100);
                        ?>
                        <div class="d-flex align-items-center gap-3 mb-3">
                            <div class="class-rank-badge" style="background:<?php echo $rankColor; ?>20; color:<?php echo $rankColor; ?>;">
                                <?php if ($rank < 3): ?>
                                    <i class="fas fa-medal"></i>
                                <?php else: ?>
                                    <span><?php echo $rank + 1; ?></span>
                                <?php endif; ?>
                            </div>
                            <div class="flex-grow-1">
                                <div class="d-flex justify-content-between mb-1">
                                    <span class="fw-semibold small"><?php echo htmlspecialchars($cls['class_name']); ?></span>
                                    <strong class="small" style="color:<?php echo $rankColor; ?>"><?php echo (int)$cls['students_count']; ?> طالب</strong>
                                </div>
                                <div class="d-flex align-items-center gap-2">
                                    <small class="text-muted" style="white-space:nowrap;"><?php echo htmlspecialchars($cls['grade_name'] ?? ''); ?></small>
                                    <div style="flex:1; background:#f1f5f9; border-radius:4px; height:6px; overflow:hidden;">
                                        <div style="width:<?php echo $barPct; ?>%; height:100%; background:<?php echo $rankColor; ?>; border-radius:4px;"></div>
                                    </div>
                                </div>
                            </div>
                        </div>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>
</div>

<!-- Donut chart via canvas + Empty Classes -->
<div class="row g-4 mb-4 animate-up delay-4 sortable-dashboard" id="widget-empty-classes">

    <!-- رسم بياني دائري للمراحل -->
    <div class="col-lg-5" id="widget-stage-donut">
        <div class="premium-card h-100">
            <div class="card-header bg-white py-3 border-bottom d-flex align-items-center">
                <i class="fas fa-grip-horizontal drag-handle me-2 text-muted" style="cursor: grab;" title="سحب لتغيير الترتيب"></i>
                <h5 class="mb-0 fw-bold"><i class="fas fa-chart-pie me-2 text-primary"></i>نسبة الطلاب حسب المرحلة</h5>
            </div>
            <div class="card-body d-flex flex-column align-items-center justify-content-center py-4">
                <canvas id="stageDonut" width="220" height="220" style="max-width:220px;"></canvas>
                <div class="mt-3 w-100">
                    <?php foreach ($stageDistribution as $i => $row): ?>
                    <?php $c = $stageColors[$i % count($stageColors)]; ?>
                    <div class="d-flex align-items-center justify-content-between py-1">
                        <div class="d-flex align-items-center gap-2">
                            <span class="chart-legend-dot" style="background:<?php echo $c; ?>;"></span>
                            <small><?php echo htmlspecialchars($row['stage_name']); ?></small>
                        </div>
                        <small class="fw-bold"><?php echo (int)$row['students_count']; ?></small>
                    </div>
                    <?php endforeach; ?>
                </div>
            </div>
        </div>
    </div>

    <!-- فصول بدون طلاب -->
    <div class="col-lg-7" id="widget-empty-classes-table">
        <div class="premium-card h-100">
            <div class="card-header bg-white py-3 border-bottom d-flex align-items-center justify-content-between">
                <div>
                    <i class="fas fa-grip-horizontal drag-handle me-2 text-muted" style="cursor: grab;" title="سحب لتغيير الترتيب"></i>
                    <h5 class="mb-0 fw-bold d-inline-block"><i class="fas fa-exclamation-circle me-2 text-danger"></i>فصول بدون طلاب</h5>
                </div>
                <span class="badge bg-danger-subtle text-danger"><?php echo count($emptyClasses); ?> فصل</span>
            </div>
            <div class="card-body p-0">
                <?php if (empty($emptyClasses)): ?>
                    <div class="text-center py-5">
                        <i class="fas fa-check-circle text-success" style="font-size:3rem;"></i>
                        <p class="text-muted mt-3 mb-0">رائع! لا توجد فصول فارغة.</p>
                    </div>
                <?php else: ?>
                    <div class="table-responsive" style="max-height:300px; overflow-y:auto;">
                        <table class="table table-sm table-hover align-middle mb-0">
                            <thead class="table-light sticky-top">
                                <tr>
                                    <th>اسم الفصل</th>
                                    <th>الصف</th>
                                    <th>المرحلة</th>
                                    <th>الحالة</th>
                                    <th>إجراء</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($emptyClasses as $cls): ?>
                                <tr>
                                    <td><strong><?php echo htmlspecialchars($cls['name']); ?></strong></td>
                                    <td><?php echo htmlspecialchars($cls['grade_name'] ?? '—'); ?></td>
                                    <td><span class="badge bg-secondary-subtle text-secondary"><?php echo htmlspecialchars($cls['stage_name'] ?? '—'); ?></span></td>
                                    <td>
                                        <?php if ($cls['status'] === 'active'): ?>
                                            <span class="badge bg-success">نشط</span>
                                        <?php else: ?>
                                            <span class="badge bg-secondary">معطل</span>
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <a href="classes.php" class="btn btn-outline-primary btn-sm py-0">
                                            <i class="fas fa-arrow-left"></i>
                                        </a>
                                    </td>
                                </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                <?php endif; ?>
            </div>
        </div>
    </div>
</div>
</div> <!-- End Dashboard Canvas -->

<!-- Offcanvas Settings Panel -->
<div class="offcanvas offcanvas-end" tabindex="-1" id="dashboardSettings" aria-labelledby="dashboardSettingsLabel">
  <div class="offcanvas-header bg-dark text-white p-4">
    <h5 class="offcanvas-title fw-bold" id="dashboardSettingsLabel"><i class="fas fa-sliders-h me-2"></i> تخصيص لوحة الإحصائيات</h5>
    <button type="button" class="btn-close btn-close-white text-reset" data-bs-dismiss="offcanvas" aria-label="Close"></button>
  </div>
  <div class="offcanvas-body p-4">
    <div class="mb-4 pt-3">
        <h6 class="text-uppercase text-muted fw-bold small mb-3">إعدادات العرض</h6>
        <div class="form-check form-switch mb-3 p-0 d-flex justify-content-between align-items-center">
          <label class="form-check-label fw-bold" for="toggle-widget-hero">الإحصائيات المجمعة</label>
          <input class="form-check-input widget-toggle ms-0" type="checkbox" id="toggle-widget-hero" data-target="widget-hero" checked>
        </div>
        <div class="form-check form-switch mb-3 p-0 d-flex justify-content-between align-items-center">
          <label class="form-check-label fw-bold" for="toggle-widget-stage-dist">توزيع المراحل الدراسية</label>
          <input class="form-check-input widget-toggle ms-0" type="checkbox" id="toggle-widget-stage-dist" data-target="widget-stage-dist" checked>
        </div>
        <div class="form-check form-switch mb-3 p-0 d-flex justify-content-between align-items-center">
          <label class="form-check-label fw-bold" for="toggle-widget-grade-dist">توزيع الصفوف والفصول</label>
          <input class="form-check-input widget-toggle ms-0" type="checkbox" id="toggle-widget-grade-dist" data-target="widget-grade-dist" checked>
        </div>
        <div class="form-check form-switch mb-3 p-0 d-flex justify-content-between align-items-center">
          <label class="form-check-label fw-bold" for="toggle-widget-empty-classes">الرسم البياني والفصول الشاغرة</label>
          <input class="form-check-input widget-toggle ms-0" type="checkbox" id="toggle-widget-empty-classes" data-target="widget-empty-classes" checked>
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

<script>
// === Dashboard Customizer Logic ===
(function() {
    const STORAGE_KEY = 'eduCoreSchoolDashboardPrefs';
    const defaultWidgets = ['widget-hero', 'widget-stage-dist', 'widget-grade-dist', 'widget-empty-classes'];
    let prefs = {};
    try {
        const storedPrefs = JSON.parse(localStorage.getItem(STORAGE_KEY) || '{}');
        prefs = storedPrefs && typeof storedPrefs === 'object' && !Array.isArray(storedPrefs)
            ? storedPrefs
            : {};
    } catch (error) {
        localStorage.removeItem(STORAGE_KEY);
        prefs = {};
    }

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

// ======= Counter Animation =======
document.querySelectorAll('.counter[data-target]').forEach(function(el) {
    var target = parseInt(el.getAttribute('data-target'));
    var duration = 1200;
    var step = target / (duration / 16);
    var current = 0;
    var timer = setInterval(function() {
        current += step;
        if (current >= target) { el.textContent = target.toLocaleString('en-US'); clearInterval(timer); }
        else { el.textContent = Math.floor(current).toLocaleString('en-US'); }
    }, 16);
});

// ======= Donut Chart =======
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

    var cx = 110, cy = 110, r = 90, inner = 55;
    var startAngle = -Math.PI / 2;

    data.forEach(function(val, i) {
        var slice = (val / total) * 2 * Math.PI;
        ctx.beginPath();
        ctx.moveTo(cx, cy);
        ctx.arc(cx, cy, r, startAngle, startAngle + slice);
        ctx.closePath();
        ctx.fillStyle = colors[i];
        ctx.fill();

        // gap
        ctx.beginPath();
        ctx.moveTo(cx, cy);
        ctx.arc(cx, cy, r, startAngle + slice - 0.01, startAngle + slice + 0.01);
        ctx.closePath();
        ctx.fillStyle = '#fff';
        ctx.fill();

        startAngle += slice;
    });

    // donut hole
    ctx.beginPath();
    ctx.arc(cx, cy, inner, 0, 2 * Math.PI);
    ctx.fillStyle = '#fff';
    ctx.fill();

    // center text
    ctx.fillStyle = '#1e293b';
    ctx.font = 'bold 22px Tajawal,sans-serif';
    ctx.textAlign = 'center';
    ctx.textBaseline = 'middle';
    ctx.fillText(total.toLocaleString('en-US'), cx, cy - 8);
    ctx.font = '12px Tajawal,sans-serif';
    ctx.fillStyle = '#64748b';
    ctx.fillText('طالب', cx, cy + 14);
})();
</script>

<?php require_once '../includes/admin_footer.php'; ?>
