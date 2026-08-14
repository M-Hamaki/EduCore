<?php
/**
 * إدارة ومتابعة الأنشطة التفاعلية
 * Interactive Activities Monitoring & Management
 */
$page_title = "متابعة الأنشطة التفاعلية";
$custom_page_title = true;

require_once '../config/database.php';
require_once '../classes/utilities.php';
require_once '../includes/session_config.php';
require_once '../includes/csrf.php';
require_once '../src/Modules/Operations/Audit/AuditService.php';
Utilities::validateSession('admin');
requireCsrfPost();

$database = new Database();
$dt = $database->getConnection();
$dt->exec("SET NAMES 'utf8mb4'");

// Activity types map
$activity_types = [
    'quiz' => ['name' => 'اختبار سريع', 'icon' => 'fas fa-question-circle', 'color' => '#3b82f6'],
    'true_false' => ['name' => 'صح أو خطأ', 'icon' => 'fas fa-check-double', 'color' => '#10b981'],
    'match' => ['name' => 'المطابقة', 'icon' => 'fas fa-link', 'color' => '#8b5cf6'],
    'group_sort' => ['name' => 'تصنيف المجموعات', 'icon' => 'fas fa-object-group', 'color' => '#f59e0b'],
    'order' => ['name' => 'الترتيب', 'icon' => 'fas fa-sort-numeric-down', 'color' => '#ef4444'],
    'flashcards' => ['name' => 'بطاقات تعليمية', 'icon' => 'fas fa-clone', 'color' => '#0ea5e9'],
    'wheel' => ['name' => 'العجلة العشوائية', 'icon' => 'fas fa-dharmachakra', 'color' => '#f97316'],
    'open_box' => ['name' => 'افتح الصندوق', 'icon' => 'fas fa-box-open', 'color' => '#ec4899'],
    'missing_word' => ['name' => 'الكلمة المفقودة', 'icon' => 'fas fa-font', 'color' => '#6366f1'],
    'anagram' => ['name' => 'إعادة ترتيب الحروف', 'icon' => 'fas fa-random', 'color' => '#14b8a6'],
    'balloon_pop' => ['name' => 'فرقعة البالونات', 'icon' => 'fas fa-circle', 'color' => '#e11d48'],
    'memory_game' => ['name' => 'لعبة الذاكرة', 'icon' => 'fas fa-train', 'color' => '#7c3aed'],
];

// --- Handle POST actions ---
$success_message = $_SESSION['success_message'] ?? null;
$error_message = $_SESSION['error_message'] ?? null;
unset($_SESSION['success_message'], $_SESSION['error_message']);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    $activity_id = intval($_POST['activity_id'] ?? 0);

    if ($action === 'toggle_status' && $activity_id) {
        $new_status = $_POST['new_status'] ?? 'inactive';
        if (in_array($new_status, ['active', 'inactive'])) {
            try {
                $dt->beginTransaction();
                $beforeStmt = $dt->prepare('SELECT * FROM activities WHERE id = ? FOR UPDATE');
                $beforeStmt->execute([$activity_id]);
                $before = $beforeStmt->fetch(PDO::FETCH_ASSOC);
                if (!$before) {
                    throw new RuntimeException('Activity not found.');
                }
                $stmt = $dt->prepare("UPDATE activities SET status = ? WHERE id = ?");
                $stmt->execute([$new_status, $activity_id]);
                $after = $before;
                $after['status'] = $new_status;
                (new \EduCore\Modules\Operations\Audit\AuditService($dt))->recordUpdate(
                    'activity', 'activities', $activity_id,
                    (string)($before['title'] ?? ('نشاط #' . $activity_id)),
                    $before, $after, 'تغيير حالة النشاط'
                );
                $dt->commit();
                $_SESSION['success_message'] = "تم تغيير حالة النشاط بنجاح";
            } catch (Throwable $e) {
                if ($dt->inTransaction()) {
                    $dt->rollBack();
                }
                error_log('activity status update error: ' . $e->getMessage());
                $_SESSION['error_message'] = 'تعذر تغيير حالة النشاط.';
            }
        }
        header("Location: " . $_SERVER['PHP_SELF']);
        exit();
    }

    if ($action === 'delete' && $activity_id) {
        $dt->beginTransaction();
        try {
            $activityStmt = $dt->prepare('SELECT * FROM activities WHERE id = ? FOR UPDATE');
            $activityStmt->execute([$activity_id]);
            $activity = $activityStmt->fetch(PDO::FETCH_ASSOC);
            if (!$activity) {
                throw new RuntimeException('Activity not found.');
            }
            $countStmt = $dt->prepare('SELECT COUNT(*) FROM activity_results WHERE activity_id = ?');
            $countStmt->execute([$activity_id]);
            $resultCount = (int)$countStmt->fetchColumn();
            $stmt = $dt->prepare("DELETE FROM activity_results WHERE activity_id = ?");
            $stmt->execute([$activity_id]);
            $stmt = $dt->prepare("DELETE FROM activities WHERE id = ?");
            $stmt->execute([$activity_id]);
            (new \EduCore\Modules\Operations\Audit\AuditService($dt))->recordEvent(
                'delete', 'activity', $activity_id,
                (string)($activity['title'] ?? ('نشاط #' . $activity_id)),
                [
                    'before' => $activity,
                    'deleted_result_count' => $resultCount,
                    'undo_policy' => 'composite_restore_not_enabled',
                ]
            );
            $dt->commit();
            $_SESSION['success_message'] = "تم حذف النشاط ونتائجه بنجاح";
        } catch (Throwable $e) {
            if ($dt->inTransaction()) {
                $dt->rollBack();
            }
            error_log('activity delete error: ' . $e->getMessage());
            $_SESSION['error_message'] = "حدث خطأ أثناء الحذف";
        }
        header("Location: " . $_SERVER['PHP_SELF']);
        exit();
    }
}

// --- Summary Statistics ---
$stats = $dt->query("SELECT 
    COUNT(*) as total_activities,
    COUNT(DISTINCT teacher_id) as total_teachers,
    SUM(CASE WHEN status = 'active' THEN 1 ELSE 0 END) as active_count,
    SUM(CASE WHEN status = 'inactive' THEN 1 ELSE 0 END) as inactive_count,
    SUM(CASE WHEN status = 'draft' THEN 1 ELSE 0 END) as draft_count,
    SUM(play_count) as total_plays
    FROM activities")->fetch(PDO::FETCH_ASSOC);

// Results stats
$results_stats = $dt->query("SELECT 
    COUNT(*) as total_results,
    COUNT(DISTINCT student_id) as unique_students,
    ROUND(AVG(percentage), 1) as avg_score
    FROM activity_results WHERE student_id IS NOT NULL")->fetch(PDO::FETCH_ASSOC);

// Activities by type
$type_counts = [];
$type_rows = $dt->query("SELECT activity_type, COUNT(*) as cnt FROM activities GROUP BY activity_type ORDER BY cnt DESC")->fetchAll(PDO::FETCH_ASSOC);
foreach ($type_rows as $r) {
    $type_counts[$r['activity_type']] = $r['cnt'];
}

// --- Filters ---
$filter_teacher = isset($_GET['teacher_id']) && $_GET['teacher_id'] !== '' ? intval($_GET['teacher_id']) : null;
$filter_type = isset($_GET['type']) && $_GET['type'] !== '' ? $_GET['type'] : null;
$filter_status = isset($_GET['status']) && $_GET['status'] !== '' ? $_GET['status'] : null;

// Get teachers for filter
$teachers = $dt->query("SELECT DISTINCT u.id, u.name 
                         FROM users u 
                         INNER JOIN activities a ON u.id = a.teacher_id 
                         ORDER BY u.name")->fetchAll(PDO::FETCH_ASSOC);

// --- Get activities list ---
$sql = "SELECT a.*, u.name as teacher_name, s.name as subject_name,
               c.name as class_name, g.grade_name,
               (SELECT COUNT(*) FROM activity_results WHERE activity_id = a.id) as results_count,
               (SELECT ROUND(AVG(percentage), 1) FROM activity_results WHERE activity_id = a.id) as avg_score
        FROM activities a
        LEFT JOIN users u ON a.teacher_id = u.id
        LEFT JOIN subjects s ON a.subject_id = s.id
        LEFT JOIN classes c ON a.class_id = c.id
        LEFT JOIN grades g ON a.grade_id = g.id
        WHERE 1=1";
$params = [];

if ($filter_teacher) {
    $sql .= " AND a.teacher_id = ?";
    $params[] = $filter_teacher;
}
if ($filter_type && array_key_exists($filter_type, $activity_types)) {
    $sql .= " AND a.activity_type = ?";
    $params[] = $filter_type;
}
if ($filter_status && in_array($filter_status, ['active', 'inactive', 'draft'])) {
    $sql .= " AND a.status = ?";
    $params[] = $filter_status;
}

$sql .= " ORDER BY a.created_at DESC";
$stmt = $dt->prepare($sql);
$stmt->execute($params);
$activities = $stmt->fetchAll(PDO::FETCH_ASSOC);

// --- Top teachers ---
$top_teachers = $dt->query("SELECT u.id, u.name, 
    COUNT(a.id) as activity_count,
    SUM(a.play_count) as total_plays,
    MAX(a.created_at) as last_activity
    FROM users u
    INNER JOIN activities a ON u.id = a.teacher_id
    GROUP BY u.id, u.name
    ORDER BY activity_count DESC
    LIMIT 10")->fetchAll(PDO::FETCH_ASSOC);

// --- Monthly trend (last 6 months) ---
$trend = $dt->query("SELECT 
    DATE_FORMAT(created_at, '%Y-%m') as month_key,
    DATE_FORMAT(created_at, '%m/%Y') as month_label,
    COUNT(*) as activity_count,
    COUNT(DISTINCT teacher_id) as teacher_count
    FROM activities
    WHERE created_at >= DATE_SUB(NOW(), INTERVAL 6 MONTH)
    GROUP BY month_key, month_label
    ORDER BY month_key")->fetchAll(PDO::FETCH_ASSOC);

require_once '../includes/admin_header.php';
?>

<!-- Page Header -->
<div class="d-flex justify-content-between flex-wrap flex-md-nowrap align-items-center pt-3 pb-2 mb-3 border-bottom">
    <div>
        <h1 class="h2"><i class="fas fa-gamepad me-2 text-primary"></i>الأنشطة التفاعلية</h1>
        <small class="text-muted">متابعة وإدارة الأنشطة التفاعلية المنشأة بواسطة المعلمين</small>
    </div>
</div>

<!-- Alerts -->
<?php if ($success_message): ?>
<div class="alert alert-success alert-dismissible fade show" role="alert">
    <i class="fas fa-check-circle me-2"></i><?= htmlspecialchars($success_message) ?>
    <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
</div>
<?php endif; ?>
<?php if ($error_message): ?>
<div class="alert alert-danger alert-dismissible fade show" role="alert">
    <i class="fas fa-exclamation-circle me-2"></i><?= htmlspecialchars($error_message) ?>
    <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
</div>
<?php endif; ?>



<div class="row row-cols-2 row-cols-md-3 row-cols-xl-6 g-3 mt-4">
    <div class="col">
        <div class="stat-card" style="--card-gradient: linear-gradient(135deg, #3b82f6, #2563eb);">
            <div class="stat-card-icon"><i class="fas fa-gamepad"></i></div>
            <div class="stat-card-info">
                <div class="stat-card-number counter" data-target="<?= (int)$stats['total_activities'] ?>">0</div>
                <div class="stat-card-label">إجمالي الأنشطة</div>
            </div>
        </div>
    </div>
    <div class="col">
        <div class="stat-card" style="--card-gradient: linear-gradient(135deg, #10b981, #059669);">
            <div class="stat-card-icon"><i class="fas fa-check-circle"></i></div>
            <div class="stat-card-info">
                <div class="stat-card-number counter" data-target="<?= (int)$stats['active_count'] ?>">0</div>
                <div class="stat-card-label">أنشطة فعّالة</div>
            </div>
        </div>
    </div>
    <div class="col">
        <div class="stat-card" style="--card-gradient: linear-gradient(135deg, #8b5cf6, #7c3aed);">
            <div class="stat-card-icon"><i class="fas fa-chalkboard-teacher"></i></div>
            <div class="stat-card-info">
                <div class="stat-card-number counter" data-target="<?= (int)$stats['total_teachers'] ?>">0</div>
                <div class="stat-card-label">معلم مُشارك</div>
            </div>
        </div>
    </div>
    <div class="col">
        <div class="stat-card" style="--card-gradient: linear-gradient(135deg, #f59e0b, #d97706);">
            <div class="stat-card-icon"><i class="fas fa-play-circle"></i></div>
            <div class="stat-card-info">
                <div class="stat-card-number counter" data-target="<?= (int)($stats['total_plays'] ?? 0) ?>">0</div>
                <div class="stat-card-label">مرات اللعب</div>
            </div>
        </div>
    </div>
    <div class="col">
        <div class="stat-card" style="--card-gradient: linear-gradient(135deg, #0ea5e9, #0284c7);">
            <div class="stat-card-icon"><i class="fas fa-users"></i></div>
            <div class="stat-card-info">
                <div class="stat-card-number counter" data-target="<?= (int)($results_stats['unique_students'] ?? 0) ?>">0</div>
                <div class="stat-card-label">طالب شارك</div>
            </div>
        </div>
    </div>
    <div class="col">
        <div class="stat-card" style="--card-gradient: linear-gradient(135deg, #ec4899, #db2777);">
            <div class="stat-card-icon"><i class="fas fa-chart-line"></i></div>
            <div class="stat-card-info">
                <div class="stat-card-number"><span class="counter" data-target="<?= (int)($results_stats['avg_score'] ?? 0) ?>">0</span>%</div>
                <div class="stat-card-label">متوسط النتائج</div>
            </div>
        </div>
    </div>
</div>

<!-- Activity Types Distribution + Monthly Trend -->
<div class="row g-3 mt-4">
    <!-- Types Distribution -->
    <div class="col-lg-6">
        <div class="card shadow">
            <div class="card-header bg-primary text-white">
                <h5 class="mt-0"><i class="fas fa-chart-pie me-2"></i>توزيع أنواع الأنشطة</h5>
            </div>
            <div class="card-body">
                <canvas id="typesChart" height="280"></canvas>
            </div>
        </div>
    </div>
    <!-- Monthly Trend -->
    <div class="col-lg-6">
        <div class="card shadow">
            <div class="card-header bg-primary text-white">
                <h5 class="mt-0"><i class="fas fa-chart-bar me-2"></i>الاتجاه الشهري (آخر 6 أشهر)</h5>
            </div>
            <div class="card-body">
                <?php if (!empty($trend)): ?>
                <canvas id="trendChart" height="280"></canvas>
                <?php else: ?>
                <div class="text-center py-5 text-muted">
                    <i class="fas fa-chart-bar fa-3x mt-3 d-block"></i>
                    لا توجد بيانات كافية للاتجاه الشهري
                </div>
                <?php endif; ?>
            </div>
        </div>
    </div>
</div>

<!-- Top Teachers -->
<?php if (!empty($top_teachers)): ?>
<div class="card shadow mt-4">
    <div class="card-header bg-primary text-white">
        <h5 class="mt-0"><i class="fas fa-trophy me-2"></i>أنشط المعلمين <span class="badge bg-light text-dark ms-2"><?= count($top_teachers) ?></span></h5>
    </div>
    <div class="card-body p-0">
        <div class="table-responsive">
            <table class="table table-hover table-striped mt-0">
                <thead class="table-light">
                    <tr>
                        <th width="50">#</th>
                        <th>المعلم</th>
                        <th class="text-center">عدد الأنشطة</th>
                        <th class="text-center">مرات اللعب</th>
                        <th class="text-center">آخر نشاط</th>
                    </tr>
                </thead>
                <tbody>
                <?php foreach ($top_teachers as $i => $t): ?>
                    <tr>
                        <td><span class="badge bg-<?= $i < 3 ? 'warning text-dark' : 'secondary' ?>"><?= $i + 1 ?></span></td>
                        <td><i class="fas fa-user-tie text-primary me-1"></i><?= htmlspecialchars($t['name']) ?></td>
                        <td class="text-center"><span class="badge bg-primary"><?= $t['activity_count'] ?></span></td>
                        <td class="text-center"><?= number_format($t['total_plays'] ?? 0) ?></td>
                        <td class="text-center"><?= $t['last_activity'] ? date('Y/m/d', strtotime($t['last_activity'])) : '-' ?></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>
<?php endif; ?>

<!-- Activities Table -->
<div class="card shadow mt-4">
    <div class="card-header bg-primary text-white">
        <div class="row align-items-center">
            <div class="col-md-3">
                <h5 class="mt-0"><i class="fas fa-list me-2"></i>جميع الأنشطة <span class="badge bg-light text-dark ms-2"><?= count($activities) ?></span></h5>
            </div>
            <div class="col-md-9">
                <form method="GET" class="d-flex justify-content-end align-items-center gap-2 flex-wrap">
                    <select class="form-select form-select-sm" name="teacher_id" style="width:auto; min-width:160px;">
                        <option value="">كل المعلمين</option>
                        <?php foreach ($teachers as $t): ?>
                        <option value="<?= $t['id'] ?>" <?= $filter_teacher == $t['id'] ? 'selected' : '' ?>><?= htmlspecialchars($t['name']) ?></option>
                        <?php endforeach; ?>
                    </select>
                    <select class="form-select form-select-sm" name="type" style="width:auto; min-width:140px;">
                        <option value="">كل الأنواع</option>
                        <?php foreach ($activity_types as $key => $typeInfo): ?>
                        <option value="<?= $key ?>" <?= $filter_type === $key ? 'selected' : '' ?>><?= $typeInfo['name'] ?></option>
                        <?php endforeach; ?>
                    </select>
                    <select class="form-select form-select-sm" name="status" style="width:auto; min-width:120px;">
                        <option value="">كل الحالات</option>
                        <option value="active" <?= $filter_status === 'active' ? 'selected' : '' ?>>فعّال</option>
                        <option value="inactive" <?= $filter_status === 'inactive' ? 'selected' : '' ?>>متوقف</option>
                        <option value="draft" <?= $filter_status === 'draft' ? 'selected' : '' ?>>مسودة</option>
                    </select>
                    <button type="submit" class="btn btn-light btn-sm"><i class="fas fa-search me-1"></i>بحث</button>
                    <a href="activities_monitor.php" class="btn btn-secondary btn-sm"><i class="fas fa-rotate-left me-1"></i>إعادة تعيين</a>
                </form>
            </div>
        </div>
    </div>
    <div class="card-body">
        <div class="table-responsive">
            <table class="table table-hover table-striped" id="activitiesTable">
                <thead class="table-light">
                    <tr>
                        <th width="50">#</th>
                        <th>النشاط</th>
                        <th>النوع</th>
                        <th>المعلم</th>
                        <th>المادة</th>
                        <th>الفصل / الصف</th>
                        <th class="text-center">مرات اللعب</th>
                        <th class="text-center">النتائج</th>
                        <th class="text-center">متوسط الدرجة</th>
                        <th class="text-center">الحالة</th>
                        <th class="text-center">تاريخ الإنشاء</th>
                        <th class="text-center" width="130">إجراءات</th>
                    </tr>
                </thead>
                <tbody>
                <?php if (empty($activities)): ?>
                    <tr><td colspan="12" class="text-center text-muted py-4"><i class="fas fa-info-circle me-2"></i>لا توجد أنشطة مطابقة للفلاتر</td></tr>
                <?php else: ?>
                <?php foreach ($activities as $i => $act):
                    $typeInfo = $activity_types[$act['activity_type']] ?? ['name' => $act['activity_type'], 'icon' => 'fas fa-circle', 'color' => '#6b7280'];
                ?>
                    <tr>
                        <td><?= $i + 1 ?></td>
                        <td>
                            <div class="fw-bold"><?= htmlspecialchars($act['title']) ?></div>
                            <small class="text-muted">الكود: <?= htmlspecialchars($act['code']) ?></small>
                        </td>
                        <td>
                            <span class="badge rounded-pill" style="background:<?= $typeInfo['color'] ?>">
                                <i class="<?= $typeInfo['icon'] ?> me-1"></i><?= $typeInfo['name'] ?>
                            </span>
                        </td>
                        <td><?= htmlspecialchars($act['teacher_name'] ?? '-') ?></td>
                        <td><?= htmlspecialchars($act['subject_name'] ?? '-') ?></td>
                        <td>
                            <?php if ($act['class_name']): ?>
                                <span class="badge bg-info"><?= htmlspecialchars($act['class_name']) ?></span>
                            <?php elseif ($act['grade_name']): ?>
                                <span class="badge bg-secondary"><?= htmlspecialchars($act['grade_name']) ?></span>
                            <?php elseif ($act['is_public']): ?>
                                <span class="badge bg-success"><i class="fas fa-globe me-1"></i>عام</span>
                            <?php else: ?>
                                <span class="text-muted">-</span>
                            <?php endif; ?>
                        </td>
                        <td class="text-center"><?= number_format($act['play_count']) ?></td>
                        <td class="text-center">
                            <?php if ($act['results_count'] > 0): ?>
                            <span class="badge bg-primary"><?= $act['results_count'] ?></span>
                            <?php else: ?>
                            <span class="text-muted">0</span>
                            <?php endif; ?>
                        </td>
                        <td class="text-center">
                            <?php if ($act['avg_score'] !== null): ?>
                            <span class="badge bg-<?= $act['avg_score'] >= 80 ? 'success' : ($act['avg_score'] >= 50 ? 'warning text-dark' : 'danger') ?>">
                                <?= $act['avg_score'] ?>%
                            </span>
                            <?php else: ?>
                            <span class="text-muted">-</span>
                            <?php endif; ?>
                        </td>
                        <td class="text-center">
                            <?php if ($act['status'] === 'active'): ?>
                            <span class="badge bg-success">فعّال</span>
                            <?php elseif ($act['status'] === 'inactive'): ?>
                            <span class="badge bg-danger">متوقف</span>
                            <?php else: ?>
                            <span class="badge bg-secondary">مسودة</span>
                            <?php endif; ?>
                        </td>
                        <td class="text-center"><?= date('Y/m/d', strtotime($act['created_at'])) ?></td>
                        <td class="text-center">
                            <a href="../play_activity.php?code=<?= htmlspecialchars($act['code']) ?>" target="_blank" class="btn btn-sm btn-outline-primary me-1" data-bs-toggle="tooltip" title="معاينة">
                                <i class="fas fa-eye"></i>
                            </a>
                            <?php if ($act['status'] === 'active'): ?>
                            <button class="btn btn-sm btn-outline-warning me-1" data-bs-toggle="tooltip" title="تعطيل"
                                onclick="confirmToggle(<?= $act['id'] ?>, '<?= htmlspecialchars($act['title'], ENT_QUOTES) ?>', 'inactive')">
                                <i class="fas fa-ban"></i>
                            </button>
                            <?php else: ?>
                            <button class="btn btn-sm btn-outline-success me-1" data-bs-toggle="tooltip" title="تفعيل"
                                onclick="confirmToggle(<?= $act['id'] ?>, '<?= htmlspecialchars($act['title'], ENT_QUOTES) ?>', 'active')">
                                <i class="fas fa-check"></i>
                            </button>
                            <?php endif; ?>
                            <button class="btn btn-sm btn-outline-danger" data-bs-toggle="tooltip" title="حذف"
                                onclick="confirmDelete(<?= $act['id'] ?>, '<?= htmlspecialchars($act['title'], ENT_QUOTES) ?>')">
                                <i class="fas fa-trash"></i>
                            </button>
                        </td>
                    </tr>
                <?php endforeach; ?>
                <?php endif; ?>
                </tbody>
            </tatle>
        </div>
    </div>
</div>

<div class="modal fade" id="toggleModal" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content admin-modal admin-modal-premium admin-modal-warning" id="toggleActivityModalContent">
<form method="post" action="activities_monitor.php">
    <?php echo csrfField(); ?>
                <input type="hidden" name="action" value="toggle_status">
                <input type="hidden" name="activity_id" id="toggleActivityId">
                <input type="hidden" name="new_status" id="toggleNewStatus">
                <div class="modal-header">
                    <h5 class="modal-title"><i class="fas fa-toggle-on me-2" id="toggleActivityHeaderIcon"></i>تغيير حالة النشاط</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <div class="text-center mt-3">
                        <i class="fas fa-toggle-on text-warning admin-modal-icon-lg" id="toggleActivityBodyIcon"></i>
                    </div>
                    <p class="text-center">هل تريد <span id="toggleActionText" class="fw-bold"></span> النشاط: <span class="fw-bold text-primary" id="toggleActivityName"></span>؟</p>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal"><i class="fas fa-times me-1"></i>إلغاء</button>
                    <button type="submit" class="btn btn-warning" id="toggleActivitySubmit"><i class="fas fa-ban me-1"></i>تأكيد</button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- Delete Modal -->
<div class="modal fade" id="deleteModal" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content admin-modal admin-modal-premium admin-modal-delete">
<form method="post" action="activities_monitor.php">
    <?php echo csrfField(); ?>
                <input type="hidden" name="action" value="delete">
                <input type="hidden" name="activity_id" id="deleteActivityId">
                <div class="modal-header">
                    <h5 class="modal-title"><i class="fas fa-trash me-2"></i>حذف نشاط</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <div class="text-center mt-3">
                        <i class="fas fa-exclamation-triangle text-danger" style="font-size: 3rem;"></i>
                    </div>
                    <p class="text-center">هل أنت متأكد من حذف النشاط: <span class="fw-bold text-primary" id="deleteActivityName"></span>؟</p>
                    <div class="alert alert-danger">
                        <i class="fas fa-info-circle me-2"></i>
                        سيتم حذف النشاط وجميع نتائج الطلاب المرتبطة به نهائياً. هذا الإجراء لا يمكن التراجع عنه.
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal"><i class="fas fa-times me-1"></i>إلغاء</button>
                    <button type="submit" class="btn btn-danger"><i class="fas fa-trash me-1"></i>حذف نهائي</button>
                </div>
            </form>
        </div>
    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.0"></script>
<script>
// Initialize DataTable
$(document).ready(function() {
    if ($.fn.DataTable) {
        $('#activitiesTable').DataTable({
            language: { url: '//cdn.datatables.net/plug-ins/1.13.6/i18n/ar.json' },
            pageLength: 50,
            order: [],
            responsive: true
        });
    }
    // Tooltips
    var tooltipTriggerList = [].slice.call(document.querySelectorAll('[data-bs-toggle="tooltip"]'));
    tooltipTriggerList.map(function(el) { return new bootstrap.Tooltip(el); });
});

function confirmToggle(id, name, newStatus) {
    const isActive = newStatus === 'inactive';
    document.getElementById('toggleActivityId').value = id;
    document.getElementById('toggleNewStatus').value = newStatus;
    document.getElementById('toggleActivityName').textContent = name;
    document.getElementById('toggleActionText').textContent = isActive ? 'تعطيل' : 'تفعيل';

    const submitButton = document.getElementById('toggleActivitySubmit');
    if (submitButton) {
        submitButton.className = isActive ? 'btn btn-warning' : 'btn btn-success';
        submitButton.innerHTML = isActive ? '<i class="fas fa-ban me-1"></i>تعطيل' : '<i class="fas fa-check me-1"></i>تفعيل';
    }

    const modalContent = document.getElementById('toggleActivityModalContent');
    if (modalContent) {
        modalContent.classList.toggle('admin-modal-warning', isActive);
        modalContent.classList.toggle('admin-modal-create', !isActive);
    }
    const bodyIcon = document.getElementById('toggleActivityBodyIcon');
    const headerIcon = document.getElementById('toggleActivityHeaderIcon');
    if (bodyIcon) {
        bodyIcon.className = isActive ? 'fas fa-ban text-warning admin-modal-icon-lg' : 'fas fa-check-circle text-success admin-modal-icon-lg';
    }
    if (headerIcon) {
        headerIcon.className = isActive ? 'fas fa-ban me-2' : 'fas fa-check-circle me-2';
    }

    new bootstrap.Modal(document.getElementById('toggleModal')).show();
}

// Delete confirmation
function confirmDelete(id, name) {
    document.getElementById('deleteActivityId').value = id;
    document.getElementById('deleteActivityName').textContent = name;
    new bootstrap.Modal(document.getElementById('deleteModal')).show();
}

// Charts
<?php if (!empty($type_counts)): ?>
(function() {
    var typeLabels = <?= json_encode(array_map(function($k) use ($activity_types) {
        return $activity_types[$k]['name'] ?? $k;
    }, array_keys($type_counts))) ?>;
    var typeData = <?= json_encode(array_values($type_counts)) ?>;
    var typeColors = <?= json_encode(array_map(function($k) use ($activity_types) {
        return $activity_types[$k]['color'] ?? '#6b7280';
    }, array_keys($type_counts))) ?>;

    new Chart(document.getElementById('typesChart'), {
        type: 'doughnut',
        data: {
            labels: typeLabels,
            datasets: [{
                data: typeData,
                backgroundColor: typeColors,
                borderWidth: 2,
                borderColor: '#fff'
            }]
        },
        options: {
            responsive: true,
            maintainAspectRatio: false,
            plugins: {
                legend: { position: 'right', labels: { font: { family: 'Tajawal', size: 12 }, padding: 12 } }
            }
        }
    });
})();
<?php endif; ?>

<?php if (!empty($trend)): ?>
(function() {
    var trendLabels = <?= json_encode(array_column($trend, 'month_label')) ?>;
    var trendActivities = <?= json_encode(array_map('intval', array_column($trend, 'activity_count'))) ?>;
    var trendTeachers = <?= json_encode(array_map('intval', array_column($trend, 'teacher_count'))) ?>;

    new Chart(document.getElementById('trendChart'), {
        type: 'bar',
        data: {
            labels: trendLabels,
            datasets: [
                {
                    label: 'عدد الأنشطة',
                    data: trendActivities,
                    backgroundColor: 'rgba(59, 130, 246, 0.7)',
                    borderColor: '#3b82f6',
                    borderWidth: 1,
                    borderRadius: 6
                },
                {
                    label: 'عدد المعلمين',
                    data: trendTeachers,
                    backgroundColor: 'rgba(139, 92, 246, 0.7)',
                    borderColor: '#8b5cf6',
                    borderWidth: 1,
                    borderRadius: 6
                }
            ]
        },
        options: {
            responsive: true,
            maintainAspectRatio: false,
            plugins: {
                legend: { labels: { font: { family: 'Tajawal' } } }
            },
            scales: {
                y: { beginAtZero: true, ticks: { stepSize: 1 } }
            }
        }
    });
})();
<?php endif; ?>
</script>

<?php require_once '../includes/admin_footer.php'; ?>
