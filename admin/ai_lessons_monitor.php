<?php
/**
 * متابعة تحضير الدروس بالذكاء الاصطناعي
 * AI Lesson Preparation Monitoring
 */
$page_title = "متابعة تحضير الدروس";
$custom_page_title = true;

require_once '../config/database.php';
require_once '../classes/utilities.php';
require_once '../classes/user.php';

if (session_status() == PHP_SESSION_NONE) {
    session_start();
}

Utilities::validateSession('admin');

$database = new Database();
$db = $database->getConnection();
$db->exec("SET NAMES 'utf8mb4'");

// تحديد التبويب النشط
$activeTab = $_GET['tab'] ?? 'teachers';
$validTabs = ['teachers', 'api_analytics', 'monthly_trend'];
if (!in_array($activeTab, $validTabs)) {
    $activeTab = 'teachers';
}

// جلب قوائم الهيكل الأكاديمي لفلترة المعلم داخل المدرسة
$stages = $db->query("SELECT id, stage_name FROM stages ORDER BY id")->fetchAll(PDO::FETCH_ASSOC);
$grades = $db->query("SELECT id, grade_name, stage_id FROM grades ORDER BY id")->fetchAll(PDO::FETCH_ASSOC);
$classes = $db->query("SELECT id, name, grade_id FROM classes ORDER BY display_order, name")->fetchAll(PDO::FETCH_ASSOC);
$subjects = $db->query("SELECT id, name FROM subjects WHERE is_active = 1 ORDER BY sort_order, name")->fetchAll(PDO::FETCH_ASSOC);

// قيم الفلاتر المستقبلة عبر GET لجدول المعلمين
$filter_teacher_type = trim((string)($_GET['teacher_type'] ?? 'all'));
$filter_stage = (int)($_GET['stage_id'] ?? 0);
$filter_grade = (int)($_GET['grade_id'] ?? 0);
$filter_class = (int)($_GET['class_id'] ?? 0);
$filter_subject = (int)($_GET['subject_id'] ?? 0);
$filter_date_from = trim((string)($_GET['date_from'] ?? ''));
$filter_date_to = trim((string)($_GET['date_to'] ?? ''));

// --- Summary Statistics ---
$statsQuery = $db->query("SELECT 
    COUNT(*) as total_lessons,
    COUNT(DISTINCT teacher_id) as total_teachers,
    SUM(CASE WHEN status = 'completed' THEN 1 ELSE 0 END) as completed_lessons,
    SUM(CASE WHEN created_at >= DATE_SUB(NOW(), INTERVAL 30 DAY) THEN 1 ELSE 0 END) as lessons_this_month,
    SUM(CASE WHEN created_at >= DATE_SUB(NOW(), INTERVAL 7 DAY) THEN 1 ELSE 0 END) as lessons_this_week
    FROM ai_lessons");
$stats = $statsQuery->fetch(PDO::FETCH_ASSOC);

// --- Online exams count ---
$examsCount = $db->query("SELECT COUNT(*) as total FROM ai_online_exams")->fetch(PDO::FETCH_ASSOC)['total'];

// --- API Usage Stats ---
$apiStats = ['total_calls' => 0, 'total_tokens' => 0, 'avg_response' => 0, 'error_rate' => 0, 'monthly' => []];
try {
    $apiQuery = $db->query("SELECT 
        COUNT(*) as total_calls,
        COALESCE(SUM(tokens_used), 0) as total_tokens,
        COALESCE(ROUND(AVG(response_time_ms)), 0) as avg_response,
        COALESCE(ROUND(SUM(CASE WHEN status = 'error' THEN 1 ELSE 0 END) / COUNT(*) * 100, 1), 0) as error_rate
        FROM ai_api_logs");
    $apiStats = array_merge($apiStats, $apiQuery->fetch(PDO::FETCH_ASSOC));
    
    // Monthly API usage
    $monthlyApi = $db->query("SELECT 
        DATE_FORMAT(created_at, '%Y-%m') as month_key,
        DATE_FORMAT(created_at, '%m/%Y') as month_label,
        COUNT(*) as calls,
        COALESCE(SUM(tokens_used), 0) as tokens,
        COALESCE(ROUND(AVG(response_time_ms)), 0) as avg_ms
        FROM ai_api_logs
        WHERE created_at >= DATE_SUB(NOW(), INTERVAL 6 MONTH)
        GROUP BY month_key, month_label
        ORDER BY month_key ASC");
    $apiStats['monthly'] = $monthlyApi->fetchAll(PDO::FETCH_ASSOC);
    
    // Top consuming teachers
    $topApiUsers = $db->query("SELECT 
        u.name as teacher_name,
        COUNT(a.id) as api_calls,
        COALESCE(SUM(a.tokens_used), 0) as total_tokens
        FROM ai_api_logs a
        JOIN users u ON a.teacher_id = u.id
        GROUP BY a.teacher_id, u.name
        ORDER BY total_tokens DESC
        LIMIT 10")->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) {
    $topApiUsers = [];
}

// --- Teachers Query (Internal vs External with Detailed Columns & Academic Filters) ---
$teachers = [];

// 1. المعلمون داخل المدرسة
if ($filter_teacher_type === 'all' || $filter_teacher_type === 'internal') {
    $internalSql = "SELECT 
        'internal' as teacher_type,
        u.id,
        u.name as teacher_name,
        u.username,
        COUNT(DISTINCT l.id) as lesson_count,
        COUNT(DISTINCT CASE WHEN l.status = 'completed' THEN l.id END) as completed_count,
        MAX(l.created_at) as last_usage,
        (SELECT GROUP_CONCAT(DISTINCT stg.stage_name ORDER BY stg.stage_name SEPARATOR '، ')
         FROM teacher_classes tc
         INNER JOIN classes c ON tc.class_id = c.id
         INNER JOIN grades g ON c.grade_id = g.id
         INNER JOIN stages stg ON g.stage_id = stg.id
         WHERE tc.teacher_id = u.id
        ) as stage_name,
        (SELECT GROUP_CONCAT(DISTINCT g.grade_name ORDER BY g.grade_name SEPARATOR '، ')
         FROM teacher_classes tc
         INNER JOIN classes c ON tc.class_id = c.id
         INNER JOIN grades g ON c.grade_id = g.id
         WHERE tc.teacher_id = u.id
        ) as grade_name,
        (SELECT GROUP_CONCAT(DISTINCT c.name ORDER BY c.name SEPARATOR '، ')
         FROM teacher_classes tc
         INNER JOIN classes c ON tc.class_id = c.id
         WHERE tc.teacher_id = u.id
        ) as class_name,
        (SELECT GROUP_CONCAT(DISTINCT s.name ORDER BY s.name SEPARATOR '، ')
         FROM subjects s
         WHERE s.id IN (
            SELECT ts.subject_id FROM teacher_subjects ts WHERE ts.teacher_id = u.id
            UNION
            SELECT tsa.subject_id FROM teacher_subject_assignments tsa WHERE tsa.teacher_id = u.id
         )
        ) as subject_name,
        (SELECT COUNT(*) FROM ai_api_logs a WHERE a.teacher_id = u.id) as api_calls,
        (SELECT COALESCE(SUM(a.tokens_used), 0) FROM ai_api_logs a WHERE a.teacher_id = u.id) as total_tokens
        FROM users u
        INNER JOIN ai_lessons l ON u.id = l.teacher_id
        WHERE EXISTS (
            SELECT 1 FROM user_role_assignments ura
            WHERE ura.user_id = u.id AND ura.role_key = 'teacher' AND ura.status = 'active'
        )";

    $internalParams = [];

    if ($filter_stage > 0) {
        $internalSql .= " AND u.id IN (SELECT tc.teacher_id FROM teacher_classes tc JOIN classes c ON tc.class_id = c.id JOIN grades g ON c.grade_id = g.id WHERE g.stage_id = ?)";
        $internalParams[] = $filter_stage;
    }
    if ($filter_grade > 0) {
        $internalSql .= " AND u.id IN (SELECT tc.teacher_id FROM teacher_classes tc JOIN classes c ON tc.class_id = c.id WHERE c.grade_id = ?)";
        $internalParams[] = $filter_grade;
    }
    if ($filter_class > 0) {
        $internalSql .= " AND u.id IN (SELECT tc.teacher_id FROM teacher_classes tc WHERE tc.class_id = ?)";
        $internalParams[] = $filter_class;
    }
    if ($filter_subject > 0) {
        $internalSql .= " AND (u.id IN (SELECT teacher_id FROM teacher_subjects WHERE subject_id = ?) OR u.id IN (SELECT teacher_id FROM teacher_subject_assignments WHERE subject_id = ?))";
        $internalParams[] = $filter_subject;
        $internalParams[] = $filter_subject;
    }
    if ($filter_date_from !== '') {
        $internalSql .= " AND l.created_at >= ?";
        $internalParams[] = $filter_date_from . ' 00:00:00';
    }
    if ($filter_date_to !== '') {
        $internalSql .= " AND l.created_at <= ?";
        $internalParams[] = $filter_date_to . ' 23:59:59';
    }

    $internalSql .= " GROUP BY u.id, u.name, u.username";
    $stmtInternal = $db->prepare($internalSql);
    $stmtInternal->execute($internalParams);
    $internalResults = $stmtInternal->fetchAll(PDO::FETCH_ASSOC);
    $teachers = array_merge($teachers, $internalResults);
}

// 2. المعلمون الخارجيون
if (($filter_teacher_type === 'all' || $filter_teacher_type === 'external') && $filter_stage == 0 && $filter_grade == 0 && $filter_class == 0 && $filter_subject == 0) {
    $externalSql = "SELECT 
        'external' as teacher_type,
        et.id,
        et.name as teacher_name,
        et.email as username,
        COUNT(DISTINCT l.id) as lesson_count,
        COUNT(DISTINCT CASE WHEN l.status = 'completed' THEN l.id END) as completed_count,
        MAX(l.created_at) as last_usage,
        '-' as stage_name,
        '-' as grade_name,
        'معلم خارجي' as class_name,
        COALESCE(et.specialization, '-') as subject_name,
        (SELECT COUNT(*) FROM ai_api_logs a WHERE a.teacher_id = et.id) as api_calls,
        (SELECT COALESCE(SUM(a.tokens_used), 0) FROM ai_api_logs a WHERE a.teacher_id = et.id) as total_tokens
        FROM external_teachers et
        INNER JOIN ai_lessons l ON et.id = l.teacher_id
        WHERE 1=1";

    $externalParams = [];

    if ($filter_date_from !== '') {
        $externalSql .= " AND l.created_at >= ?";
        $externalParams[] = $filter_date_from . ' 00:00:00';
    }
    if ($filter_date_to !== '') {
        $externalSql .= " AND l.created_at <= ?";
        $externalParams[] = $filter_date_to . ' 23:59:59';
    }

    $externalSql .= " GROUP BY et.id, et.name, et.email";
    $stmtExternal = $db->prepare($externalSql);
    $stmtExternal->execute($externalParams);
    $externalResults = $stmtExternal->fetchAll(PDO::FETCH_ASSOC);
    $teachers = array_merge($teachers, $externalResults);
}

// ترتيب القائمة حسب عدد الدروس تنازلياً
usort($teachers, function($a, $b) {
    return $b['lesson_count'] <=> $a['lesson_count'];
});

// --- Monthly usage trend (last 6 months) ---
$trendQuery = $db->query("SELECT 
    DATE_FORMAT(created_at, '%Y-%m') as month_key,
    DATE_FORMAT(created_at, '%m/%Y') as month_label,
    COUNT(*) as lesson_count,
    COUNT(DISTINCT teacher_id) as teacher_count
    FROM ai_lessons
    WHERE created_at >= DATE_SUB(NOW(), INTERVAL 6 MONTH)
    GROUP BY month_key, month_label
    ORDER BY month_key ASC");
$trend = $trendQuery->fetchAll(PDO::FETCH_ASSOC);

include_once '../includes/admin_header.php';
?>

<style>
/* Shrink statistics cards slightly for better fit and comfort */
/* Style adjustments for table cells */
#teachersTable th, #teachersTable td {
    padding: 8px 10px !important;
    font-size: 0.9rem;
}
</style>

<!-- عنوان الصفحة والأزرار الإدارية -->
<div class="admin-page-heading">
    <div>
        <h1 class="h2"><i class="fas fa-robot text-danger me-2"></i>متابعة دروس الذكاء الاصطناعي</h1>
    </div>
</div>

<!-- مصفوفة كافة الكروت الإحصائية الموحدة في الأعلى -->
<div class="row row-cols-2 row-cols-md-4 row-cols-lg-5 row-cols-xxl-9 g-2 mb-4">
    <div class="col animate-up delay-1">
        <div class="stat-card" style="--card-gradient: linear-gradient(135deg, #3b82f6, #2563eb);">
            <div class="stat-card-icon"><i class="fas fa-book-open"></i></div>
            <div class="stat-card-info">
                <div class="stat-card-number counter" data-target="<?php echo (int)($stats['total_lessons'] ?? 0); ?>">0</div>
                <div class="stat-card-label">إجمالي الدروس</div>
                <div class="stat-card-sub"><i class="fas fa-list"></i> جميع المخرجات</div>
            </div>
        </div>
    </div>
    <div class="col animate-up delay-2">
        <div class="stat-card" style="--card-gradient: linear-gradient(135deg, #10b981, #059669);">
            <div class="stat-card-icon"><i class="fas fa-chalkboard-teacher"></i></div>
            <div class="stat-card-info">
                <div class="stat-card-number counter" data-target="<?php echo (int)($stats['total_teachers'] ?? 0); ?>">0</div>
                <div class="stat-card-label">معلمون نشطون</div>
                <div class="stat-card-sub"><i class="fas fa-users"></i> مستخدمو الأداة</div>
            </div>
        </div>
    </div>
    <div class="col animate-up delay-3">
        <div class="stat-card" style="--card-gradient: linear-gradient(135deg, #0ea5e9, #0284c7);">
            <div class="stat-card-icon"><i class="fas fa-check-circle"></i></div>
            <div class="stat-card-info">
                <div class="stat-card-number counter" data-target="<?php echo (int)($stats['completed_lessons'] ?? 0); ?>">0</div>
                <div class="stat-card-label">درس مكتمل</div>
                <div class="stat-card-sub"><i class="fas fa-check"></i> مخرجات ناجحة</div>
            </div>
        </div>
    </div>
    <div class="col animate-up delay-4">
        <div class="stat-card" style="--card-gradient: linear-gradient(135deg, #f59e0b, #d97706);">
            <div class="stat-card-icon"><i class="fas fa-calendar-week"></i></div>
            <div class="stat-card-info">
                <div class="stat-card-number counter" data-target="<?php echo (int)($stats['lessons_this_month'] ?? 0); ?>">0</div>
                <div class="stat-card-label">آخر 30 يوم</div>
                <div class="stat-card-sub"><i class="fas fa-calendar-alt"></i> الشهر الحالي</div>
            </div>
        </div>
    </div>
    <div class="col animate-up delay-5">
        <div class="stat-card" style="--card-gradient: linear-gradient(135deg, #8b5cf6, #7c3aed);">
            <div class="stat-card-icon"><i class="fas fa-laptop-code"></i></div>
            <div class="stat-card-info">
                <div class="stat-card-number counter" data-target="<?php echo (int)($examsCount ?? 0); ?>">0</div>
                <div class="stat-card-label">اختبار إلكتروني</div>
                <div class="stat-card-sub"><i class="fas fa-file-alt"></i> اختبارات منشورة</div>
            </div>
        </div>
    </div>

    <div class="col animate-up delay-1">
        <div class="stat-card" style="--card-gradient: linear-gradient(135deg, #ef4444, #dc2626);">
            <div class="stat-card-icon"><i class="fas fa-server"></i></div>
            <div class="stat-card-info">
                <div class="stat-card-number counter" data-target="<?php echo (int)($apiStats['total_calls'] ?? 0); ?>">0</div>
                <div class="stat-card-label">إجمالي الطلبات</div>
                <div class="stat-card-sub"><i class="fas fa-microchip"></i> استهلاك API</div>
            </div>
        </div>
    </div>
    <div class="col animate-up delay-2">
        <div class="stat-card" style="--card-gradient: linear-gradient(135deg, #06b6d4, #0891b2);">
            <div class="stat-card-icon"><i class="fas fa-coins"></i></div>
            <div class="stat-card-info">
                <div class="stat-card-number counter" data-target="<?php echo (int)($apiStats['total_tokens'] ?? 0); ?>">0</div>
                <div class="stat-card-label">إجمالي التوكنز</div>
                <div class="stat-card-sub"><i class="fas fa-database"></i> حجم البيانات</div>
            </div>
        </div>
    </div>
    <div class="col animate-up delay-3">
        <div class="stat-card" style="--card-gradient: linear-gradient(135deg, #10b981, #047857);">
            <div class="stat-card-icon"><i class="fas fa-tachometer-alt"></i></div>
            <div class="stat-card-info">
                <div class="stat-card-number counter" data-target="<?php echo (int)($apiStats['avg_response'] ?? 0); ?>">0</div>
                <div class="stat-card-label">متوسط الاستجابة ms</div>
                <div class="stat-card-sub"><i class="fas fa-bolt"></i> السرعة والأداء</div>
            </div>
        </div>
    </div>
    <div class="col animate-up delay-4">
        <div class="stat-card" style="--card-gradient: linear-gradient(135deg, #f97316, #ea580c);">
            <div class="stat-card-icon"><i class="fas fa-exclamation-triangle"></i></div>
            <div class="stat-card-info">
                <div class="stat-card-number"><span class="counter" data-target="<?php echo (int)($apiStats['error_rate'] ?? 0); ?>">0</span>%</div>
                <div class="stat-card-label">نسبة الأخطاء</div>
                <div class="stat-card-sub"><i class="fas fa-bug"></i> استقرار الخدمات</div>
            </div>
        </div>
    </div>
</div>

<!-- تبويبات تنظيم الصفحة -->
<ul class="nav nav-tabs mb-4" id="aiMonitorTabs" role="tablist">
    <li class="nav-item" role="presentation">
        <button class="nav-link <?php echo $activeTab === 'teachers' ? 'active' : ''; ?>" id="teachers-tab" data-bs-toggle="tab" data-bs-target="#teachers-pane" type="button" role="tab" onclick="updateTabUrl('teachers')">
            <i class="fas fa-chalkboard-teacher me-2"></i>المعلمون المستخدمون للأداة
            <span class="badge rounded-pill bg-primary ms-1"><?php echo count($teachers); ?></span>
        </button>
    </li>
    <li class="nav-item" role="presentation">
        <button class="nav-link <?php echo $activeTab === 'api_analytics' ? 'active' : ''; ?>" id="api-analytics-tab" data-bs-toggle="tab" data-bs-target="#api-analytics-pane" type="button" role="tab" onclick="updateTabUrl('api_analytics')">
            <i class="fas fa-microchip me-2"></i>تحليلات استهلاك API والخدمات
        </button>
    </li>
    <li class="nav-item" role="presentation">
        <button class="nav-link <?php echo $activeTab === 'monthly_trend' ? 'active' : ''; ?>" id="monthly-trend-tab" data-bs-toggle="tab" data-bs-target="#monthly-trend-pane" type="button" role="tab" onclick="updateTabUrl('monthly_trend')">
            <i class="fas fa-chart-line me-2"></i>معدل الاستخدام والاتجاه الشهري
        </button>
    </li>
</ul>

<div class="tab-content" id="aiMonitorTabsContent">
    <!-- التبويب الأول: جدول المعلمين -->
    <div class="tab-pane fade <?php echo $activeTab === 'teachers' ? 'show active' : ''; ?>" id="teachers-pane" role="tabpanel">
        <div class="admin-filter-bar">
            <div class="admin-filter-controls">
                <form method="GET" action="ai_lessons_monitor.php" id="teachersFilterForm" class="d-flex align-items-center gap-2 flex-wrap mb-0">
                    <input type="hidden" name="tab" value="teachers">
                    
                    <select class="form-select form-select-sm" name="teacher_type" id="filterTeacherType" style="width:auto; min-width:140px;" onchange="toggleSchoolFilters(this.value); this.form.submit();">
                        <option value="all" <?php echo $filter_teacher_type === 'all' ? 'selected' : ''; ?>>كل المعلمين</option>
                        <option value="internal" <?php echo $filter_teacher_type === 'internal' ? 'selected' : ''; ?>>معلم داخل المدرسة</option>
                        <option value="external" <?php echo $filter_teacher_type === 'external' ? 'selected' : ''; ?>>معلم خارجي</option>
                    </select>

                    <div class="school-filter-col d-inline-block" style="<?php echo $filter_teacher_type === 'external' ? 'display:none;' : ''; ?>">
                        <select class="form-select form-select-sm" name="stage_id" id="pageFilterStage" style="width:auto; min-width:130px;" onchange="this.form.submit()">
                            <option value="">كل المراحل</option>
                            <?php foreach ($stages as $stg): ?>
                                <option value="<?php echo $stg['id']; ?>" <?php echo $filter_stage === (int)$stg['id'] ? 'selected' : ''; ?>><?php echo htmlspecialchars($stg['stage_name']); ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <div class="school-filter-col d-inline-block" style="<?php echo $filter_teacher_type === 'external' ? 'display:none;' : ''; ?>">
                        <select class="form-select form-select-sm" name="grade_id" id="pageFilterGrade" style="width:auto; min-width:130px;" onchange="this.form.submit()">
                            <option value="">كل الصفوف</option>
                            <?php foreach ($grades as $grd): ?>
                                <option value="<?php echo $grd['id']; ?>" data-stage="<?php echo $grd['stage_id']; ?>" <?php echo $filter_grade === (int)$grd['id'] ? 'selected' : ''; ?>><?php echo htmlspecialchars($grd['grade_name']); ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <div class="school-filter-col d-inline-block" style="<?php echo $filter_teacher_type === 'external' ? 'display:none;' : ''; ?>">
                        <select class="form-select form-select-sm" name="class_id" id="pageFilterClass" style="width:auto; min-width:130px;" onchange="this.form.submit()">
                            <option value="">كل الفصول</option>
                            <?php foreach ($classes as $cls): ?>
                                <option value="<?php echo $cls['id']; ?>" data-grade="<?php echo $cls['grade_id']; ?>" <?php echo $filter_class === (int)$cls['id'] ? 'selected' : ''; ?>><?php echo htmlspecialchars($cls['name']); ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <div class="school-filter-col d-inline-block" style="<?php echo $filter_teacher_type === 'external' ? 'display:none;' : ''; ?>">
                        <select class="form-select form-select-sm" name="subject_id" id="pageFilterSubject" style="width:auto; min-width:130px;" onchange="this.form.submit()">
                            <option value="">كل المواد</option>
                            <?php foreach ($subjects as $sbj): ?>
                                <option value="<?php echo $sbj['id']; ?>" <?php echo $filter_subject === (int)$sbj['id'] ? 'selected' : ''; ?>><?php echo htmlspecialchars($sbj['name']); ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <input type="text" class="form-control form-control-sm flatpickr-date" name="date_from" value="<?php echo htmlspecialchars($filter_date_from); ?>" style="width:auto;" onchange="this.form.submit()" title="من تاريخ">
                    <input type="text" class="form-control form-control-sm flatpickr-date" name="date_to" value="<?php echo htmlspecialchars($filter_date_to); ?>" style="width:auto;" onchange="this.form.submit()" title="إلى تاريخ">
                </form>
            </div>
            <div class="admin-filter-actions">
                <a href="ai_lessons_monitor.php?tab=teachers" class="btn btn-light btn-sm" title="إعادة تعيين الفلاتر">
                    <i class="fas fa-undo me-1"></i>إعادة تعيين
                </a>
                <button type="button" class="btn btn-light btn-sm" data-bs-toggle="modal" data-bs-target="#tableSettingsModal" title="تخصيص أعمدة الجدول">
                    <i class="fas fa-cog me-1"></i>إعدادات الجدول
                </button>
            </div>
        </div>

        <div class="admin-list-surface mb-4">
            <?php if (empty($teachers)): ?>
                <div class="text-center py-5 text-muted">
                    <i class="fas fa-info-circle fa-3x mb-3 text-secondary"></i>
                    <p class="h5">لا توجد نتائج مطابقة للفلاتر المختارة</p>
                </div>
            <?php else: ?>
                <div class="table-responsive admin-table-wrap">
                    <table class="table table-hover table-striped datatable admin-data-table" id="teachersTable">
                        <thead>
                            <tr>
                                <th width="50" class="text-center">#</th>
                                <th style="min-width: 180px;">المعلم</th>
                                <th>الصفوف والفصول</th>
                                <th>المادة</th>
                                <th style="min-width: 180px;">الاستخدام والاستهلاك</th>
                                <th style="min-width: 140px;">آخر استخدام</th>
                                <th width="120" class="text-center actions-column">الإجراءات</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($teachers as $i => $t): ?>
                            <tr>
                                <td class="fw-bold text-center align-middle"><?php echo $i + 1; ?></td>
                                <td class="align-middle py-3">
                                    <div class="fw-bold text-primary"><?php echo htmlspecialchars($t['teacher_name']); ?></div>
                                    <div class="small text-muted mt-1 dir-ltr text-end" style="font-size: 0.8rem;"><code>@<?php echo htmlspecialchars($t['username']); ?></code></div>
                                    <div class="mt-1">
                                        <?php if ($t['teacher_type'] === 'external'): ?>
                                            <span class="badge bg-warning-subtle text-warning-emphasis" style="font-size: 0.75rem;"><i class="fas fa-external-link-alt me-1"></i>معلم خارجي</span>
                                        <?php else: ?>
                                            <span class="badge bg-info-subtle text-info-emphasis" style="font-size: 0.75rem;"><i class="fas fa-school me-1"></i>معلم داخل المدرسة</span>
                                        <?php endif; ?>
                                    </div>
                                </td>
                                <td class="align-middle py-3 small">
                                    <?php if ($t['teacher_type'] === 'external'): ?>
                                        <span class="text-muted">معلم خارجي</span>
                                    <?php else: ?>
                                        <div class="mb-1"><strong>المرحلة:</strong> <span class="text-secondary"><?php echo htmlspecialchars($t['stage_name'] ?: '-'); ?></span></div>
                                        <div class="mb-1"><strong>الصف:</strong> <span class="text-secondary"><?php echo htmlspecialchars($t['grade_name'] ?: '-'); ?></span></div>
                                        <div class="mb-0"><strong>الفصل:</strong> 
                                            <?php if (!empty($t['class_name'])): ?>
                                                <span class="text-secondary"><?php echo htmlspecialchars($t['class_name']); ?></span>
                                            <?php else: ?>
                                                <span class="text-muted">-</span>
                                            <?php endif; ?>
                                        </div>
                                    <?php endif; ?>
                                </td>
                                <td class="align-middle py-3">
                                    <?php if (!empty($t['subject_name'])): ?>
                                        <div class="d-flex flex-wrap gap-1" style="max-width: 200px;">
                                            <?php foreach (explode('، ', $t['subject_name']) as $sbj): ?>
                                                <span class="badge bg-light text-dark border px-2 py-1" style="font-size: 0.75rem;"><?php echo htmlspecialchars($sbj); ?></span>
                                            <?php endforeach; ?>
                                        </div>
                                    <?php else: ?>
                                        <span class="text-muted">-</span>
                                    <?php endif; ?>
                                </td>
                                <td class="align-middle py-3 small">
                                    <div class="mb-1"><strong>الدروس:</strong> <span class="badge bg-primary-subtle text-primary px-2 py-1 ms-1 font-monospace" style="font-size: 0.78rem;"><?php echo $t['lesson_count']; ?></span> <span class="text-muted">(<?php echo $t['completed_count']; ?> مكتمل)</span></div>
                                    <div class="mb-1"><strong>الطلبات:</strong> <span class="badge bg-secondary-subtle text-secondary px-2 py-1 ms-1 font-monospace" style="font-size: 0.78rem;"><?php echo number_format($t['api_calls']); ?></span></div>
                                    <div class="mb-0"><strong>التوكنز:</strong> <span class="badge bg-dark-subtle text-dark px-2 py-1 ms-1 font-monospace" style="font-size: 0.78rem;"><?php echo number_format($t['total_tokens']); ?></span></div>
                                </td>
                                <td class="align-middle py-3">
                                    <span class="badge bg-light text-dark border px-2 py-1 text-nowrap" style="font-size: 0.8rem;">
                                        <i class="far fa-calendar-alt me-1 text-muted"></i><?php echo date('Y/m/d', strtotime($t['last_usage'])); ?>
                                        <i class="far fa-clock ms-2 me-1 text-muted"></i><span dir="ltr"><?php echo date('h:i A', strtotime($t['last_usage'])); ?></span>
                                    </span>
                                </td>
                                <td class="align-middle text-center actions-column admin-table-actions py-3">
                                    <a href="teacher_lessons.php?teacher_id=<?php echo (int)$t['id']; ?>" class="btn btn-action-pills btn-view has-tooltip" title="أرشيف الدروس">
                                        <i class="fas fa-folder-open"></i>
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

    <!-- التبويب الثاني: تحليلات استهلاك API والخدمات -->
    <div class="tab-pane fade <?php echo $activeTab === 'api_analytics' ? 'show active' : ''; ?>" id="api-analytics-pane" role="tabpanel">
        <div class="card border-0 shadow-sm mb-4" style="border-radius: 12px;">
            <div class="card-header bg-white border-bottom py-3">
                <h5 class="mb-0 fw-bold text-primary"><i class="fas fa-microchip me-2"></i>تفاصيل تتبع استهلاك API والخدمات الذكية</h5>
            </div>
            <div class="card-body">
                <?php if (!empty($apiStats['monthly'])): ?>
                <div class="mb-4">
                    <h6 class="fw-bold mb-3 text-primary"><i class="fas fa-chart-bar me-2"></i>استهلاك التوكن والطلبات الشهري</h6>
                    <div style="position: relative; height: 300px;">
                        <canvas id="apiUsageChart"></canvas>
                    </div>
                </div>
                <hr class="my-4">
                <?php endif; ?>

                <?php if (!empty($topApiUsers)): ?>
                <h6 class="fw-bold mb-3 text-primary"><i class="fas fa-trophy me-2 text-warning"></i>أكثر المعلمين استهلاكاً للخدمات الذكية</h6>
                <div class="table-responsive">
                    <table class="table table-hover align-middle">
                        <thead class="table-light">
                            <tr>
                                <th>#</th>
                                <th>المعلم</th>
                                <th>عدد الطلبات</th>
                                <th>إجمالي التوكنز</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($topApiUsers as $idx => $u): ?>
                            <tr>
                                <td class="fw-bold"><?php echo $idx + 1; ?></td>
                                <td class="fw-bold text-dark"><?php echo htmlspecialchars($u['teacher_name']); ?></td>
                                <td><span class="badge bg-primary-subtle text-primary px-3 py-2 fs-6"><?php echo number_format($u['api_calls']); ?></span></td>
                                <td><span class="badge bg-info-subtle text-info-emphasis px-3 py-2 fs-6"><?php echo number_format($u['total_tokens']); ?></span></td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
                <?php else: ?>
                <div class="text-center py-4 text-muted">
                    <i class="fas fa-info-circle fa-2x mb-2"></i>
                    <p>لا توجد بيانات استهلاك API مسجلة حالياً</p>
                </div>
                <?php endif; ?>
            </div>
        </div>
    </div>

    <!-- التبويب الثالث: معدل الاستخدام والاتجاه الشهري -->
    <div class="tab-pane fade <?php echo $activeTab === 'monthly_trend' ? 'show active' : ''; ?>" id="monthly-trend-pane" role="tabpanel">
        <div class="card border-0 shadow-sm mb-4" style="border-radius: 12px;">
            <div class="card-header bg-white border-bottom py-3">
                <h5 class="mb-0 fw-bold text-primary"><i class="fas fa-chart-area me-2"></i>معدل الاستخدام والاتجاه الشهري لتحضير الدروس</h5>
            </div>
            <div class="card-body">
                <?php if (!empty($trend)): ?>
                    <div style="position: relative; height: 350px;">
                        <canvas id="usageTrendChart"></canvas>
                    </div>
                <?php else: ?>
                    <div class="text-center py-5 text-muted">
                        <i class="fas fa-chart-line fa-3x mb-3 text-secondary"></i>
                        <p class="h5">لا توجد بيانات اتجاه شهري مسجلة</p>
                    </div>
                <?php endif; ?>
            </div>
        </div>
    </div>
</div>

<!-- مودال تخصيص أعمدة جدول المعلمين -->
<div class="modal fade" id="tableSettingsModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-lg">
        <div class="modal-content admin-modal admin-modal-premium admin-modal-view">
            <div class="modal-header">
                <h5 class="modal-title"><i class="fas fa-cog me-2"></i>إعدادات أعمدة جدول المعلمين</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <p class="text-muted small">اختر الأعمدة التي تريد عرضها في جدول المعلمين:</p>
                <div class="row g-2">
                    <div class="col-md-4 col-6">
                        <div class="form-check">
                            <input class="form-check-input" type="checkbox" id="col_seq" checked>
                            <label class="form-check-label" for="col_seq">مسلسل / #</label>
                        </div>
                    </div>
                    <div class="col-md-4 col-6">
                        <div class="form-check">
                            <input class="form-check-input" type="checkbox" id="col_teacher_info" checked>
                            <label class="form-check-label" for="col_teacher_info">المعلم</label>
                        </div>
                    </div>
                    <div class="col-md-4 col-6">
                        <div class="form-check">
                            <input class="form-check-input" type="checkbox" id="col_academic_scope" checked>
                            <label class="form-check-label" for="col_academic_scope">الصفوف والفصول</label>
                        </div>
                    </div>
                    <div class="col-md-4 col-6">
                        <div class="form-check">
                            <input class="form-check-input" type="checkbox" id="col_subject" checked>
                            <label class="form-check-label" for="col_subject">المادة</label>
                        </div>
                    </div>
                    <div class="col-md-4 col-6">
                        <div class="form-check">
                            <input class="form-check-input" type="checkbox" id="col_usage_stats" checked>
                            <label class="form-check-label" for="col_usage_stats">الاستخدام والاستهلاك</label>
                        </div>
                    </div>
                    <div class="col-md-4 col-6">
                        <div class="form-check">
                            <input class="form-check-input" type="checkbox" id="col_last_usage" checked>
                            <label class="form-check-label" for="col_last_usage">آخر استخدام</label>
                        </div>
                    </div>
                    <div class="col-md-4 col-6">
                        <div class="form-check">
                            <input class="form-check-input" type="checkbox" id="col_actions" checked>
                            <label class="form-check-label" for="col_actions">الإجراءات</label>
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

<!-- Chart.js CDN -->
<script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.0/dist/chart.umd.min.js"></script>
<script src="../assets/js/admin_table_actions.js"></script>

<script>
function updateTabUrl(tabName) {
    const newUrl = new URL(window.location);
    newUrl.searchParams.set('tab', tabName);
    window.history.replaceState({}, '', newUrl);
}

function toggleSchoolFilters(teacherType) {
    const schoolCols = document.querySelectorAll('.school-filter-col');
    schoolCols.forEach(col => {
        col.style.display = (teacherType === 'external') ? 'none' : '';
    });
}

document.addEventListener('DOMContentLoaded', function() {
    // 1. تخصيص أعمدة الجدول فورياً
    if (typeof initializeTableColumnSettings === 'function' && document.getElementById('teachersTable')) {
        initializeTableColumnSettings('teachersTable', {
            col_seq: 0,
            col_teacher_info: 1,
            col_academic_scope: 2,
            col_subject: 3,
            col_usage_stats: 4,
            col_last_usage: 5,
            col_actions: 6
        }, 'ai_teachers_table_columns_v3');
    }

    // 2. تصفية تتابع الفلاتر لصفحة المعلمين (المرحلة -> الصف -> الفصل)
    const pageStageFilter = document.getElementById('pageFilterStage');
    const pageGradeFilter = document.getElementById('pageFilterGrade');
    const pageClassFilter = document.getElementById('pageFilterClass');

    if (pageStageFilter && pageGradeFilter && pageClassFilter) {
        pageStageFilter.addEventListener('change', function() {
            const stageId = this.value;
            pageGradeFilter.value = '';
            pageGradeFilter.querySelectorAll('option[data-stage]').forEach(opt => {
                opt.style.display = (!stageId || opt.getAttribute('data-stage') === stageId) ? '' : 'none';
            });
            pageClassFilter.value = '';
            pageClassFilter.querySelectorAll('option[data-grade]').forEach(opt => { opt.style.display = 'none'; });
        });

        pageGradeFilter.addEventListener('change', function() {
            const gradeId = this.value;
            pageClassFilter.value = '';
            pageClassFilter.querySelectorAll('option[data-grade]').forEach(opt => {
                opt.style.display = (!gradeId || opt.getAttribute('data-grade') === gradeId) ? '' : 'none';
            });
        });
    }

    // 3. رسم بياني لتتبع استهلاك API
    const apiCanvas = document.getElementById('apiUsageChart');
    if (apiCanvas) {
        const monthlyData = <?php echo json_encode($apiStats['monthly'] ?? []); ?>;
        if (monthlyData.length > 0) {
            new Chart(apiCanvas, {
                type: 'bar',
                data: {
                    labels: monthlyData.map(d => d.month_label),
                    datasets: [
                        {
                            label: 'عدد الطلبات',
                            data: monthlyData.map(d => d.calls),
                            backgroundColor: 'rgba(59, 130, 246, 0.7)',
                            borderColor: '#3b82f6',
                            borderWidth: 1,
                            yAxisID: 'y'
                        },
                        {
                            label: 'إجمالي التوكنز',
                            data: monthlyData.map(d => d.tokens),
                            type: 'line',
                            borderColor: '#10b981',
                            backgroundColor: 'rgba(16, 185, 129, 0.1)',
                            fill: true,
                            tension: 0.3,
                            yAxisID: 'y1'
                        }
                    ]
                },
                options: {
                    responsive: true,
                    maintainAspectRatio: false,
                    scales: {
                        y: { type: 'linear', display: true, position: 'left', title: { display: true, text: 'الطلبات' } },
                        y1: { type: 'linear', display: true, position: 'right', grid: { drawOnChartArea: false }, title: { display: true, text: 'التوكنز' } }
                    }
                }
            });
        }
    }

    // 4. رسم بياني لمعدل الاستخدام الشهري للدروس
    const trendCanvas = document.getElementById('usageTrendChart');
    if (trendCanvas) {
        const trendData = <?php echo json_encode($trend ?? []); ?>;
        if (trendData.length > 0) {
            new Chart(trendCanvas, {
                type: 'line',
                data: {
                    labels: trendData.map(d => d.month_label),
                    datasets: [
                        {
                            label: 'عدد الدروس المحضرة',
                            data: trendData.map(d => d.lesson_count),
                            borderColor: '#8b5cf6',
                            backgroundColor: 'rgba(139, 92, 246, 0.15)',
                            fill: true,
                            tension: 0.4,
                            borderWidth: 3
                        },
                        {
                            label: 'المعلمون المشاركون',
                            data: trendData.map(d => d.teacher_count),
                            borderColor: '#f59e0b',
                            backgroundColor: 'transparent',
                            borderDash: [5, 5],
                            tension: 0.4,
                            borderWidth: 2
                        }
                    ]
                },
                options: {
                    responsive: true,
                    maintainAspectRatio: false,
                    plugins: {
                        legend: { position: 'top' }
                    }
                }
            });
        }
    }
});
</script>

<?php include_once '../includes/admin_footer.php'; ?>
