<?php
/**
 * الأنشطة التفاعلية - WordWall Style
 * teacher/activities.php
 */

$page_title = "الأنشطة التفاعلية";
$custom_page_title = true;

require_once '../config/database.php';
require_once '../classes/utilities.php';
require_once '../classes/user.php';
require_once '../classes/ActivityLog.php';
require_once '../includes/session_config.php';

Utilities::validateSession('teacher');

$database = new Database();
$db = $database->getConnection();

$teacher_id = $_SESSION['user_id'];
$teacher_name = $_SESSION['name'] ?? '';

// --- Session messages (PRG) ---
$success_message = $_SESSION['success_message'] ?? null;
$error_message = $_SESSION['error_message'] ?? null;
unset($_SESSION['success_message'], $_SESSION['error_message']);

// --- Activity types ---
$activity_types = [
    'quiz' => ['name' => 'اختبار سريع', 'icon' => 'fas fa-question-circle', 'color' => '#3b82f6', 'desc' => 'أسئلة اختيار من متعدد'],
    'true_false' => ['name' => 'صح أو خطأ', 'icon' => 'fas fa-check-double', 'color' => '#10b981', 'desc' => 'عبارات صحيحة أو خاطئة'],
    'match' => ['name' => 'المطابقة', 'icon' => 'fas fa-link', 'color' => '#8b5cf6', 'desc' => 'وصل الأزواج المتناسبة'],
    'group_sort' => ['name' => 'تصنيف المجموعات', 'icon' => 'fas fa-object-group', 'color' => '#f59e0b', 'desc' => 'صنّف العناصر في مجموعاتها'],
    'order' => ['name' => 'الترتيب', 'icon' => 'fas fa-sort-numeric-down', 'color' => '#ef4444', 'desc' => 'رتّب العناصر ترتيباً صحيحاً'],
    'flashcards' => ['name' => 'بطاقات تعليمية', 'icon' => 'fas fa-clone', 'color' => '#0ea5e9', 'desc' => 'بطاقات تقلب بين السؤال والجواب'],
    'wheel' => ['name' => 'العجلة العشوائية', 'icon' => 'fas fa-dharmachakra', 'color' => '#f97316', 'desc' => 'دوّر العجلة واختر عشوائياً'],
    'open_box' => ['name' => 'افتح الصندوق', 'icon' => 'fas fa-box-open', 'color' => '#ec4899', 'desc' => 'افتح الصندوق وأجب عن السؤال'],
    'missing_word' => ['name' => 'الكلمة المفقودة', 'icon' => 'fas fa-font', 'color' => '#6366f1', 'desc' => 'أكمل الفراغات في النص'],
    'anagram' => ['name' => 'إعادة ترتيب الحروف', 'icon' => 'fas fa-random', 'color' => '#14b8a6', 'desc' => 'أعد ترتيب الحروف لتكوين الكلمة'],
    'balloon_pop' => ['name' => 'فرقعة البالونات', 'icon' => 'fas fa-circle', 'color' => '#e11d48', 'desc' => 'افرقع البالون الصحيح'],
    'memory_game' => ['name' => 'لعبة الذاكرة', 'icon' => 'fas fa-brain', 'color' => '#7c3aed', 'desc' => 'اعثر على الأزواج المتطابقة'],
];

// --- Get teacher's subjects and classes ---
$teacherSubjects = [];
$stmt = $db->prepare("SELECT s.id, s.name FROM subjects s INNER JOIN teacher_subjects ts ON s.id = ts.subject_id WHERE ts.teacher_id = ? ORDER BY s.name");
$stmt->execute([$teacher_id]);
$teacherSubjects = $stmt->fetchAll(PDO::FETCH_ASSOC);

$teacherClasses = [];
$stmt = $db->prepare("SELECT c.id, c.name, g.grade_name, g.id as grade_id FROM classes c JOIN user_class_access uca ON c.id = uca.class_id LEFT JOIN grades g ON c.grade_id = g.id WHERE uca.user_id = ? AND c.status = 'active' ORDER BY g.grade_order, c.display_order");
$stmt->execute([$teacher_id]);
$teacherClasses = $stmt->fetchAll(PDO::FETCH_ASSOC);

// --- Generate unique code ---
function generateActivityCode($db) {
    do {
        $code = strtoupper(substr(md5(uniqid(mt_rand(), true)), 0, 6));
        $stmt = $db->prepare("SELECT COUNT(*) FROM activities WHERE code = ?");
        $stmt->execute([$code]);
    } while ($stmt->fetchColumn() > 0);
    return $code;
}

// ========== AJAX HANDLERS ==========
if (isset($_GET['ajax'])) {
    header('Content-Type: application/json; charset=utf-8');
    $action = $_GET['ajax'];

    // CSRF check for POST
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        $csrf = $_POST['csrf_token'] ?? ($_SERVER['HTTP_X_CSRF_TOKEN'] ?? '');
        if (!hash_equals($_SESSION['csrf_token'] ?? '', $csrf)) {
            echo json_encode(['success' => false, 'message' => 'خطأ في التحقق الأمني']);
            exit;
        }
    }

    // --- Save activity ---
    if ($action === 'save' && $_SERVER['REQUEST_METHOD'] === 'POST') {
        try {
            $id = intval($_POST['activity_id'] ?? 0);
            $title = trim($_POST['title'] ?? '');
            $description = trim($_POST['description'] ?? '');
            $type = $_POST['activity_type'] ?? '';
            $subject_id = !empty($_POST['subject_id']) ? intval($_POST['subject_id']) : null;
            $grade_id = !empty($_POST['grade_id']) ? intval($_POST['grade_id']) : null;
            $class_id = !empty($_POST['class_id']) ? intval($_POST['class_id']) : null;
            $items_data = $_POST['items_data'] ?? '[]';
            $settings = $_POST['settings'] ?? '{}';
            $is_public = isset($_POST['is_public']) ? 1 : 0;

            if (empty($title)) throw new Exception('عنوان النشاط مطلوب');
            if (!isset($activity_types[$type])) throw new Exception('نوع النشاط غير صالح');

            // Validate JSON
            $itemsArr = json_decode($items_data, true);
            if (json_last_error() !== JSON_ERROR_NONE) throw new Exception('بيانات العناصر غير صالحة');
            if (empty($itemsArr) && $type !== 'wheel') throw new Exception('يجب إضافة عنصر واحد على الأقل');

            if ($id > 0) {
                // Update
                $stmt = $db->prepare("UPDATE activities SET title=?, description=?, activity_type=?, subject_id=?, grade_id=?, class_id=?, items_data=?, settings=?, is_public=? WHERE id=? AND teacher_id=?");
                $stmt->execute([$title, $description, $type, $subject_id, $grade_id, $class_id, $items_data, $settings, $is_public, $id, $teacher_id]);
                ActivityLog::logUpdate('activity', $id, $title, ['type' => $type]);
                echo json_encode(['success' => true, 'message' => 'تم تحديث النشاط بنجاح']);
            } else {
                // Create
                $code = generateActivityCode($db);
                $stmt = $db->prepare("INSERT INTO activities (code, title, description, activity_type, teacher_id, subject_id, grade_id, class_id, items_data, settings, is_public) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
                $stmt->execute([$code, $title, $description, $type, $teacher_id, $subject_id, $grade_id, $class_id, $items_data, $settings, $is_public]);
                $newId = $db->lastInsertId();
                ActivityLog::logCreate('activity', $newId, $title, ['type' => $type, 'code' => $code]);
                echo json_encode(['success' => true, 'message' => 'تم إنشاء النشاط بنجاح', 'id' => $newId, 'code' => $code]);
            }
        } catch (Exception $e) {
            echo json_encode(['success' => false, 'message' => $e->getMessage()]);
        }
        exit;
    }

    // --- Delete activity ---
    if ($action === 'delete' && $_SERVER['REQUEST_METHOD'] === 'POST') {
        $id = intval($_POST['id'] ?? 0);
        $stmt = $db->prepare("SELECT title FROM activities WHERE id = ? AND teacher_id = ?");
        $stmt->execute([$id, $teacher_id]);
        $act = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($act) {
            $db->prepare("DELETE FROM activities WHERE id = ? AND teacher_id = ?")->execute([$id, $teacher_id]);
            ActivityLog::logDelete('activity', $id, $act['title'], []);
            echo json_encode(['success' => true, 'message' => 'تم حذف النشاط']);
        } else {
            echo json_encode(['success' => false, 'message' => 'النشاط غير موجود']);
        }
        exit;
    }

    // --- Toggle status ---
    if ($action === 'toggle_status' && $_SERVER['REQUEST_METHOD'] === 'POST') {
        $id = intval($_POST['id'] ?? 0);
        $stmt = $db->prepare("SELECT status FROM activities WHERE id = ? AND teacher_id = ?");
        $stmt->execute([$id, $teacher_id]);
        $current = $stmt->fetchColumn();
        $new_status = ($current === 'active') ? 'inactive' : 'active';
        $db->prepare("UPDATE activities SET status = ? WHERE id = ? AND teacher_id = ?")->execute([$new_status, $id, $teacher_id]);
        echo json_encode(['success' => true, 'message' => 'تم تحديث الحالة', 'new_status' => $new_status]);
        exit;
    }

    // --- Get activity data for editing ---
    if ($action === 'get_activity') {
        $id = intval($_GET['id'] ?? 0);
        $stmt = $db->prepare("SELECT * FROM activities WHERE id = ? AND teacher_id = ?");
        $stmt->execute([$id, $teacher_id]);
        $act = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($act) {
            echo json_encode(['success' => true, 'data' => $act]);
        } else {
            echo json_encode(['success' => false, 'message' => 'غير موجود']);
        }
        exit;
    }

    // --- Get results ---
    if ($action === 'get_results') {
        $id = intval($_GET['id'] ?? 0);
        $stmt = $db->prepare("SELECT ar.*, COALESCE(u.name, ar.player_name, 'مجهول') as name FROM activity_results ar LEFT JOIN users u ON ar.student_id = u.id WHERE ar.activity_id = ? ORDER BY ar.completed_at DESC LIMIT 100");
        $stmt->execute([$id]);
        $results = $stmt->fetchAll(PDO::FETCH_ASSOC);
        echo json_encode(['success' => true, 'results' => $results]);
        exit;
    }

    echo json_encode(['success' => false, 'message' => 'إجراء غير معروف']);
    exit;
}

// ========== FETCH ACTIVITIES FOR LIST ==========
$filter_type = $_GET['type'] ?? '';
$filter_status = $_GET['status'] ?? '';

$where = "WHERE a.teacher_id = ?";
$params = [$teacher_id];

if ($filter_type && isset($activity_types[$filter_type])) {
    $where .= " AND a.activity_type = ?";
    $params[] = $filter_type;
}
if ($filter_status && in_array($filter_status, ['active', 'inactive', 'draft'])) {
    $where .= " AND a.status = ?";
    $params[] = $filter_status;
}

$stmt = $db->prepare("SELECT a.*, s.name as subject_name, g.grade_name,
    (SELECT COUNT(*) FROM activity_results WHERE activity_id = a.id) as results_count
    FROM activities a
    LEFT JOIN subjects s ON a.subject_id = s.id
    LEFT JOIN grades g ON a.grade_id = g.id
    $where ORDER BY a.created_at DESC");
$stmt->execute($params);
$activities = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Stats
$stmtStats = $db->prepare("SELECT COUNT(*) as total,
    SUM(status='active') as active_count,
    SUM(status='inactive') as inactive_count,
    SUM(play_count) as total_plays
    FROM activities WHERE teacher_id = ?");
$stmtStats->execute([$teacher_id]);
$stats = $stmtStats->fetch(PDO::FETCH_ASSOC);

require_once '../includes/teacher_header.php';
?>

<style>
/* Type selector grid */
.type-grid { display: grid; grid-template-columns: repeat(auto-fill, minmax(150px, 1fr)); gap: 12px; }
.type-card { border: 2px solid #e5e7eb; border-radius: 12px; padding: 16px; text-align: center; cursor: pointer; transition: all .2s; }
.type-card:hover { border-color: #3b82f6; transform: translateY(-2px); box-shadow: 0 4px 12px rgba(0,0,0,.1); }
.type-card.selected { border-color: #3b82f6; background: #eff6ff; }
.type-card .type-icon { font-size: 2rem; margin-bottom: 8px; }
.type-card .type-name { font-weight: 600; font-size: .9rem; }
.type-card .type-desc { font-size: .75rem; color: #6b7280; margin-top: 4px; }

/* Activity cards */
.activity-card { background: #fff; border-radius: 12px; box-shadow: 0 2px 10px rgba(0,0,0,.06); border: 1px solid #e5e7eb; transition: .2s; overflow: hidden; }
.activity-card:hover { box-shadow: 0 4px 20px rgba(0,0,0,.1); transform: translateY(-2px); }
.activity-card .card-type-bar { height: 4px; }
.activity-card .card-body { padding: 16px; }
.activity-card .act-title { font-size: 1.05rem; font-weight: 600; margin-bottom: 6px; }
.activity-card .act-meta { font-size: .8rem; color: #6b7280; }
.activity-card .act-meta i { width: 16px; }
.activity-card .card-actions { padding: 10px 16px; border-top: 1px solid #f3f4f6; display: flex; gap: 6px; flex-wrap: wrap; }

/* Items builder */
.items-builder { background: #f9fafb; border-radius: 12px; padding: 16px; border: 1px solid #e5e7eb; }
.item-row { background: #fff; border: 1px solid #e5e7eb; border-radius: 8px; padding: 12px; margin-bottom: 8px; position: relative; }
.item-row .remove-item { position: absolute; top: 8px; left: 8px; background: none; border: none; color: #ef4444; cursor: pointer; font-size: 1.1rem; }
.item-row .drag-handle { cursor: grab; color: #9ca3af; margin-left: 8px; }

/* Group builder */
.group-container { background: #fff; border: 2px dashed #d1d5db; border-radius: 10px; padding: 12px; margin-bottom: 12px; }
.group-container .group-header { display: flex; align-items: center; gap: 8px; margin-bottom: 8px; }
.group-item-row { display: flex; align-items: center; gap: 6px; margin-bottom: 4px; }

/* Creator form */
#creatorSection { display: none; }
.creator-step { display: none; }
.creator-step.active { display: block; }

/* Share modal */
.share-link-box { background: #f0fdf4; border: 2px solid #10b981; border-radius: 10px; padding: 16px; text-align: center; word-break: break-all; font-size: 1.1rem; font-weight: 600; color: #065f46; }

/* Results */
.result-bar { height: 24px; border-radius: 12px; background: #e5e7eb; overflow: hidden; }
.result-bar .fill { height: 100%; border-radius: 12px; background: linear-gradient(90deg, #10b981, #059669); transition: width .5s; }
</style>

<!-- Page Header (Admin Style) -->
<div class="d-flex justify-content-between flex-wrap flex-md-nowrap align-items-center pt-3 pb-2 mb-3 border-bottom">
    <h1 class="h2">
        <i class="fas fa-gamepad me-2 text-primary"></i>الأنشطة التفاعلية
    </h1>
    <div class="btn-toolbar mb-2 mb-md-0 gap-2">
        <a href="portal.php" class="btn btn-outline-secondary shadow-sm px-3 py-2">
            <i class="fas fa-arrow-right me-2"></i>العودة للبوابة
        </a>
        <button class="btn btn-success shadow px-4 py-2" onclick="showCreator()">
            <i class="fas fa-plus-circle me-2"></i>نشاط جديد
        </button>
    </div>
</div>

<?php if ($success_message): ?>
<div class="alert alert-success alert-dismissible fade show"><i class="fas fa-check-circle me-2"></i><?= htmlspecialchars($success_message) ?><button class="btn-close" data-bs-dismiss="alert"></button></div>
<?php endif; ?>
<?php if ($error_message): ?>
<div class="alert alert-danger alert-dismissible fade show"><i class="fas fa-exclamation-circle me-2"></i><?= htmlspecialchars($error_message) ?><button class="btn-close" data-bs-dismiss="alert"></button></div>
<?php endif; ?>

<!-- Stats (Admin Style Stat Cards) -->
<div class="row row-cols-2 row-cols-md-4 g-3 mb-4">
    <div class="col">
        <div class="stat-card" style="--card-gradient: var(--primary-gradient);">
            <div class="stat-card-icon"><i class="fas fa-gamepad"></i></div>
            <div class="stat-card-info">
                <div class="stat-card-number"><?= intval($stats['total']) ?></div>
                <div class="stat-card-label">إجمالي الأنشطة</div>
            </div>
        </div>
    </div>
    <div class="col">
        <div class="stat-card" style="--card-gradient: var(--success-gradient);">
            <div class="stat-card-icon"><i class="fas fa-check-circle"></i></div>
            <div class="stat-card-info">
                <div class="stat-card-number"><?= intval($stats['active_count']) ?></div>
                <div class="stat-card-label">نشطة</div>
            </div>
        </div>
    </div>
    <div class="col">
        <div class="stat-card" style="--card-gradient: var(--warning-gradient);">
            <div class="stat-card-icon"><i class="fas fa-pause-circle"></i></div>
            <div class="stat-card-info">
                <div class="stat-card-number"><?= intval($stats['inactive_count']) ?></div>
                <div class="stat-card-label">متوقفة</div>
            </div>
        </div>
    </div>
    <div class="col">
        <div class="stat-card" style="--card-gradient: var(--info-gradient);">
            <div class="stat-card-icon"><i class="fas fa-play"></i></div>
            <div class="stat-card-info">
                <div class="stat-card-number"><?= intval($stats['total_plays']) ?></div>
                <div class="stat-card-label">مرات اللعب</div>
            </div>
        </div>
    </div>
</div>

<!-- ==================== LIST SECTION ==================== -->
<div id="listSection">
    <!-- Filters (Admin Style Card with Inline Filters) -->
    <div class="card shadow mb-3">
        <div class="card-header bg-primary text-white">
            <div class="row align-items-center">
                <div class="col-md-3">
                    <h5 class="mb-0"><i class="fas fa-list me-2"></i>الأنشطة <span class="badge bg-light text-dark ms-2"><?= count($activities) ?></span></h5>
                </div>
                <div class="col-md-9">
                    <form method="GET" class="d-flex justify-content-end align-items-center gap-2 flex-wrap">
                        <select name="type" class="form-select form-select-sm" style="width:auto;min-width:160px">
                            <option value="">كل الأنواع</option>
                            <?php foreach ($activity_types as $k => $t): ?>
                            <option value="<?= $k ?>" <?= $filter_type === $k ? 'selected' : '' ?>><?= $t['name'] ?></option>
                            <?php endforeach; ?>
                        </select>
                        <select name="status" class="form-select form-select-sm" style="width:auto;min-width:120px">
                            <option value="">كل الحالات</option>
                            <option value="active" <?= $filter_status === 'active' ? 'selected' : '' ?>>نشط</option>
                            <option value="inactive" <?= $filter_status === 'inactive' ? 'selected' : '' ?>>متوقف</option>
                            <option value="draft" <?= $filter_status === 'draft' ? 'selected' : '' ?>>مسودة</option>
                        </select>
                        <button type="submit" class="btn btn-light btn-sm"><i class="fas fa-search me-1"></i>بحث</button>
                        <a href="activities.php" class="btn btn-secondary btn-sm"><i class="fas fa-rotate-left me-1"></i>إعادة تعيين</a>
                    </form>
                </div>
            </div>
        </div>
        <div class="card-body">
    <?php if (empty($activities)): ?>
    <div class="text-center py-5">
        <i class="fas fa-gamepad text-muted" style="font-size:4rem"></i>
        <h4 class="mt-3 text-muted">لا توجد أنشطة بعد</h4>
        <p class="text-muted">ابدأ بإنشاء أول نشاط تفاعلي لطلابك</p>
        <button class="btn btn-success btn-lg" onclick="showCreator()"><i class="fas fa-plus me-2"></i>إنشاء نشاط جديد</button>
    </div>
    <?php else: ?>
    <div class="row g-3">
        <?php foreach ($activities as $act):
            $typeInfo = $activity_types[$act['activity_type']] ?? ['name'=>$act['activity_type'],'icon'=>'fas fa-circle','color'=>'#6b7280'];
        ?>
        <div class="col-md-6 col-lg-4" id="act-card-<?= $act['id'] ?>">
            <div class="activity-card">
                <div class="card-type-bar" style="background:<?= $typeInfo['color'] ?>"></div>
                <div class="card-body">
                    <div class="d-flex justify-content-between align-items-start">
                        <div class="act-title"><?= htmlspecialchars($act['title']) ?></div>
                        <span class="badge <?= $act['status']==='active'? 'bg-success':'bg-secondary' ?>"><?= $act['status']==='active'?'نشط':'متوقف' ?></span>
                    </div>
                    <div class="act-meta mb-2">
                        <span class="me-3"><i class="<?= $typeInfo['icon'] ?>" style="color:<?= $typeInfo['color'] ?>"></i> <?= $typeInfo['name'] ?></span>
                        <?php if ($act['subject_name']): ?><span class="me-3"><i class="fas fa-book"></i> <?= htmlspecialchars($act['subject_name']) ?></span><?php endif; ?>
                    </div>
                    <div class="act-meta">
                        <span class="me-3"><i class="fas fa-play"></i> <?= intval($act['play_count']) ?> مرة</span>
                        <span class="me-3"><i class="fas fa-users"></i> <?= intval($act['results_count']) ?> نتيجة</span>
                        <span><i class="fas fa-clock"></i> <?= date('Y/m/d', strtotime($act['created_at'])) ?></span>
                    </div>
                </div>
                <div class="card-actions">
                    <button class="btn btn-sm btn-primary me-1" onclick="editActivity(<?= $act['id'] ?>)" data-bs-toggle="tooltip" title="تعديل"><i class="fas fa-edit"></i></button>
                    <a href="../play_activity.php?code=<?= $act['code'] ?>" target="_blank" class="btn btn-sm btn-success me-1" data-bs-toggle="tooltip" title="معاينة"><i class="fas fa-eye"></i></a>
                    <button class="btn btn-sm btn-info me-1" onclick="shareActivity(this)" data-code="<?= htmlspecialchars($act['code'], ENT_QUOTES, 'UTF-8') ?>" data-title="<?= htmlspecialchars($act['title'], ENT_QUOTES, 'UTF-8') ?>" data-bs-toggle="tooltip" title="مشاركة"><i class="fas fa-share-alt"></i></button>
                    <button class="btn btn-sm btn-warning me-1" onclick="viewResults(<?= $act['id'] ?>, this.dataset.title)" data-title="<?= htmlspecialchars($act['title'], ENT_QUOTES, 'UTF-8') ?>" data-bs-toggle="tooltip" title="النتائج"><i class="fas fa-chart-bar"></i></button>
                    <button class="btn btn-sm btn-<?= $act['status']==='active'?'secondary':'success' ?> me-1" onclick="toggleStatus(<?= $act['id'] ?>)" data-bs-toggle="tooltip" title="<?= $act['status']==='active'?'إيقاف':'تفعيل' ?>"><i class="fas fa-<?= $act['status']==='active'?'pause':'play' ?>"></i></button>
                    <button class="btn btn-sm btn-danger" onclick="confirmDelete(<?= $act['id'] ?>, this.dataset.title)" data-title="<?= htmlspecialchars($act['title'], ENT_QUOTES, 'UTF-8') ?>" data-bs-toggle="tooltip" title="حذف"><i class="fas fa-trash"></i></button>
                </div>
            </div>
        </div>
        <?php endforeach; ?>
    </div>
    <?php endif; ?>
        </div><!-- /.card-body -->
    </div><!-- /.card -->
</div>

<!-- ==================== CREATOR SECTION ==================== -->
<div id="creatorSection">
    <div class="d-flex justify-content-between align-items-center mb-3">
        <h4 id="creatorTitle"><i class="fas fa-plus-circle me-2"></i>إنشاء نشاط جديد</h4>
        <button class="btn btn-secondary" onclick="hideCreator()"><i class="fas fa-arrow-right me-1"></i>العودة للقائمة</button>
    </div>

    <!-- Step indicators -->
    <div class="d-flex gap-2 mb-4">
        <span class="badge bg-primary fs-6 step-badge" id="stepBadge1">1. اختر النوع</span>
        <span class="badge bg-secondary fs-6 step-badge" id="stepBadge2">2. المعلومات</span>
        <span class="badge bg-secondary fs-6 step-badge" id="stepBadge3">3. المحتوى</span>
    </div>

    <!-- Step 1: Choose Type -->
    <div class="creator-step active" id="step1">
        <div class="card shadow-sm">
            <div class="card-header bg-primary text-white"><h5 class="mb-0"><i class="fas fa-th-large me-2"></i>اختر نوع النشاط</h5></div>
            <div class="card-body">
                <div class="type-grid">
                    <?php foreach ($activity_types as $key => $type): ?>
                    <div class="type-card" data-type="<?= $key ?>" onclick="selectType('<?= $key ?>')">
                        <div class="type-icon" style="color:<?= $type['color'] ?>"><i class="<?= $type['icon'] ?>"></i></div>
                        <div class="type-name"><?= $type['name'] ?></div>
                        <div class="type-desc"><?= $type['desc'] ?></div>
                    </div>
                    <?php endforeach; ?>
                </div>
            </div>
        </div>
    </div>

    <!-- Step 2: Info -->
    <div class="creator-step" id="step2">
        <div class="card shadow-sm">
            <div class="card-header bg-primary text-white"><h5 class="mb-0"><i class="fas fa-info-circle me-2"></i>معلومات النشاط</h5></div>
            <div class="card-body">
                <div class="row g-3">
                    <div class="col-md-8">
                        <label class="form-label fw-bold">عنوان النشاط <span class="text-danger">*</span></label>
                        <input type="text" id="actTitle" class="form-control" placeholder="مثال: مراجعة الفصل الأول" required>
                    </div>
                    <div class="col-md-4">
                        <label class="form-label fw-bold">المادة</label>
                        <select id="actSubject" class="form-select">
                            <option value="">بدون تحديد</option>
                            <?php foreach ($teacherSubjects as $s): ?>
                            <option value="<?= $s['id'] ?>"><?= htmlspecialchars($s['name']) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="col-md-6">
                        <label class="form-label fw-bold">الصف / الفصل</label>
                        <select id="actClass" class="form-select">
                            <option value="">متاح للجميع</option>
                            <?php foreach ($teacherClasses as $c): ?>
                            <option value="<?= $c['id'] ?>" data-grade="<?= $c['grade_id'] ?>"><?= htmlspecialchars($c['grade_name'] . ' - ' . $c['name']) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="col-md-6">
                        <label class="form-label fw-bold">الوصف</label>
                        <input type="text" id="actDesc" class="form-control" placeholder="وصف مختصر (اختياري)">
                    </div>
                    <div class="col-12">
                        <div class="form-check">
                            <input type="checkbox" class="form-check-input" id="actPublic" checked>
                            <label class="form-check-label" for="actPublic">متاح للعب بدون تسجيل دخول (رابط عام)</label>
                        </div>
                    </div>
                </div>
                <div class="mt-3 d-flex gap-2">
                    <button class="btn btn-secondary" onclick="goToStep(1)"><i class="fas fa-arrow-right me-1"></i>السابق</button>
                    <button class="btn btn-primary" onclick="goToStep(3)"><i class="fas fa-arrow-left me-1"></i>التالي — إضافة المحتوى</button>
                </div>
            </div>
        </div>
    </div>

    <!-- Step 3: Items Builder -->
    <div class="creator-step" id="step3">
        <div class="card shadow-sm">
            <div class="card-header bg-primary text-white d-flex justify-content-between align-items-center">
                <h5 class="mb-0"><i class="fas fa-puzzle-piece me-2"></i>محتوى النشاط — <span id="stepTypeLabel"></span></h5>
            </div>
            <div class="card-body">
                <div id="itemsBuilderArea" class="items-builder">
                    <!-- Dynamic content per type -->
                </div>
                <div class="mt-3 d-flex gap-2 flex-wrap">
                    <button class="btn btn-secondary" onclick="goToStep(2)"><i class="fas fa-arrow-right me-1"></i>السابق</button>
                    <button class="btn btn-success btn-lg" onclick="saveActivity()"><i class="fas fa-save me-2"></i>حفظ النشاط</button>
                    <button class="btn btn-info" onclick="previewActivity()"><i class="fas fa-eye me-2"></i>معاينة</button>
                </div>
            </div>
        </div>
    </div>
</div>

<!-- Share Modal -->
<div class="modal fade" id="shareModal" tabindex="-1">
    <div class="modal-dialog"><div class="modal-content">
        <div class="modal-header bg-info text-white"><h5 class="modal-title"><i class="fas fa-share-alt me-2"></i>مشاركة النشاط</h5><button class="btn-close btn-close-white" data-bs-dismiss="modal"></button></div>
        <div class="modal-body">
            <h6 class="text-center mb-3" id="shareTitle"></h6>
            <div class="share-link-box mb-3" id="shareLink"></div>
            <div class="d-flex gap-2 justify-content-center">
                <button class="btn btn-success" onclick="copyShareLink()"><i class="fas fa-copy me-1"></i>نسخ الرابط</button>
                <a id="shareWhatsapp" href="#" target="_blank" class="btn btn-success"><i class="fab fa-whatsapp me-1"></i>واتساب</a>
            </div>
        </div>
    </div></div>
</div>

<!-- Results Modal -->
<div class="modal fade" id="resultsModal" tabindex="-1">
    <div class="modal-dialog modal-lg"><div class="modal-content">
        <div class="modal-header bg-warning text-dark"><h5 class="modal-title"><i class="fas fa-chart-bar me-2"></i>نتائج النشاط</h5><button class="btn-close" data-bs-dismiss="modal"></button></div>
        <div class="modal-body">
            <h6 id="resultsTitle" class="mb-3"></h6>
            <div id="resultsContent"><div class="text-center py-3"><i class="fas fa-spinner fa-spin fa-2x"></i></div></div>
        </div>
    </div></div>
</div>

<!-- Delete Modal -->
<div class="modal fade" id="deleteModal" tabindex="-1">
    <div class="modal-dialog"><div class="modal-content">
        <div class="modal-header bg-danger text-white"><h5 class="modal-title"><i class="fas fa-trash me-2"></i>حذف النشاط</h5><button class="btn-close btn-close-white" data-bs-dismiss="modal"></button></div>
        <div class="modal-body text-center">
            <i class="fas fa-exclamation-triangle text-danger" style="font-size:3rem"></i>
            <p class="mt-3">هل أنت متأكد من حذف النشاط:<br><strong id="deleteTitle"></strong>؟</p>
            <div class="alert alert-danger"><i class="fas fa-info-circle me-1"></i>سيتم حذف جميع النتائج المرتبطة بهذا النشاط</div>
        </div>
        <div class="modal-footer">
            <button class="btn btn-secondary" data-bs-dismiss="modal"><i class="fas fa-times me-1"></i>إلغاء</button>
            <button class="btn btn-danger" id="confirmDeleteBtn"><i class="fas fa-trash me-1"></i>تأكيد الحذف</button>
        </div>
    </div></div>
</div>

<input type="hidden" id="csrfToken" value="<?= htmlspecialchars($_SESSION['csrf_token'] ?? '') ?>">
<input type="hidden" id="editingId" value="0">
<input type="hidden" id="selectedType" value="">
<input type="hidden" id="baseUrl" value="<?= rtrim((isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on' ? 'https' : 'http') . '://' . $_SERVER['HTTP_HOST'] . dirname(dirname($_SERVER['PHP_SELF'])), '/') ?>">

<script>
var activityTypes = <?= json_encode($activity_types) ?>;
var csrf = document.getElementById('csrfToken').value;
var baseUrl = document.getElementById('baseUrl').value;

// ============ NAVIGATION ============
function showCreator(editId) {
    document.getElementById('listSection').style.display = 'none';
    document.getElementById('creatorSection').style.display = 'block';
    if (!editId) {
        document.getElementById('editingId').value = '0';
        document.getElementById('creatorTitle').innerHTML = '<i class="fas fa-plus-circle me-2"></i>إنشاء نشاط جديد';
        resetCreator();
        goToStep(1);
    }
}

function hideCreator() {
    document.getElementById('listSection').style.display = 'block';
    document.getElementById('creatorSection').style.display = 'none';
    resetCreator();
}

function resetCreator() {
    document.getElementById('actTitle').value = '';
    document.getElementById('actDesc').value = '';
    document.getElementById('actSubject').value = '';
    document.getElementById('actClass').value = '';
    document.getElementById('actPublic').checked = true;
    document.getElementById('selectedType').value = '';
    document.getElementById('editingId').value = '0';
    document.querySelectorAll('.type-card').forEach(c => c.classList.remove('selected'));
    document.getElementById('itemsBuilderArea').innerHTML = '';
}

function goToStep(step) {
    if (step === 2 && !document.getElementById('selectedType').value) {
        alert('يرجى اختيار نوع النشاط أولاً');
        return;
    }
    if (step === 3) {
        var type = document.getElementById('selectedType').value;
        document.getElementById('stepTypeLabel').textContent = activityTypes[type].name;
        buildItemsUI(type);
    }
    document.querySelectorAll('.creator-step').forEach(s => s.classList.remove('active'));
    document.getElementById('step' + step).classList.add('active');
    // Update badges
    for (var i = 1; i <= 3; i++) {
        document.getElementById('stepBadge' + i).className = 'badge fs-6 step-badge ' + (i <= step ? 'bg-primary' : 'bg-secondary');
    }
}

function selectType(type) {
    document.getElementById('selectedType').value = type;
    document.querySelectorAll('.type-card').forEach(c => c.classList.remove('selected'));
    document.querySelector('.type-card[data-type="' + type + '"]').classList.add('selected');
    setTimeout(() => goToStep(2), 200);
}

// ============ ITEMS BUILDER ============
var itemCounter = 0;

function buildItemsUI(type) {
    var area = document.getElementById('itemsBuilderArea');
    itemCounter = 0;
    var html = '';

    switch (type) {
        case 'quiz':
            html = '<div id="itemsList"></div>' +
                '<button class="btn btn-success mt-2" onclick="addQuizItem()"><i class="fas fa-plus me-1"></i>إضافة سؤال</button>';
            area.innerHTML = html;
            addQuizItem(); addQuizItem();
            break;
        case 'true_false':
            html = '<div id="itemsList"></div>' +
                '<button class="btn btn-success mt-2" onclick="addTrueFalseItem()"><i class="fas fa-plus me-1"></i>إضافة عبارة</button>';
            area.innerHTML = html;
            addTrueFalseItem(); addTrueFalseItem();
            break;
        case 'match':
            html = '<div id="itemsList"></div>' +
                '<button class="btn btn-success mt-2" onclick="addMatchItem()"><i class="fas fa-plus me-1"></i>إضافة زوج</button>';
            area.innerHTML = html;
            addMatchItem(); addMatchItem(); addMatchItem();
            break;
        case 'flashcards':
            html = '<div id="itemsList"></div>' +
                '<button class="btn btn-success mt-2" onclick="addFlashcardItem()"><i class="fas fa-plus me-1"></i>إضافة بطاقة</button>';
            area.innerHTML = html;
            addFlashcardItem(); addFlashcardItem();
            break;
        case 'wheel':
            html = '<div id="itemsList"></div>' +
                '<button class="btn btn-success mt-2" onclick="addWheelItem()"><i class="fas fa-plus me-1"></i>إضافة خيار</button>';
            area.innerHTML = html;
            for (var i = 0; i < 4; i++) addWheelItem();
            break;
        case 'group_sort':
            html = '<div id="groupsList"></div>' +
                '<button class="btn btn-success mt-2" onclick="addGroup()"><i class="fas fa-plus me-1"></i>إضافة مجموعة</button>';
            area.innerHTML = html;
            addGroup(); addGroup();
            break;
        case 'order':
            html = '<p class="text-muted mb-2"><i class="fas fa-info-circle me-1"></i>أدخل العناصر بالترتيب الصحيح — سيتم خلطها عند اللعب</p>' +
                '<div id="itemsList"></div>' +
                '<button class="btn btn-success mt-2" onclick="addOrderItem()"><i class="fas fa-plus me-1"></i>إضافة عنصر</button>';
            area.innerHTML = html;
            addOrderItem(); addOrderItem(); addOrderItem();
            break;
        case 'open_box':
            html = '<div id="itemsList"></div>' +
                '<button class="btn btn-success mt-2" onclick="addOpenBoxItem()"><i class="fas fa-plus me-1"></i>إضافة صندوق</button>';
            area.innerHTML = html;
            addOpenBoxItem(); addOpenBoxItem();
            break;
        case 'missing_word':
            html = '<p class="text-muted mb-2"><i class="fas fa-info-circle me-1"></i>ضع الكلمة المراد إخفاؤها بين أقواس معقوفة مثل: السماء [زرقاء] اللون</p>' +
                '<div id="itemsList"></div>' +
                '<button class="btn btn-success mt-2" onclick="addMissingWordItem()"><i class="fas fa-plus me-1"></i>إضافة جملة</button>';
            area.innerHTML = html;
            addMissingWordItem();
            break;
        case 'anagram':
            html = '<div id="itemsList"></div>' +
                '<button class="btn btn-success mt-2" onclick="addAnagramItem()"><i class="fas fa-plus me-1"></i>إضافة كلمة</button>';
            area.innerHTML = html;
            addAnagramItem(); addAnagramItem();
            break;
        case 'balloon_pop':
            html = '<div id="itemsList"></div>' +
                '<button class="btn btn-success mt-2" onclick="addBalloonItem()"><i class="fas fa-plus me-1"></i>إضافة سؤال</button>';
            area.innerHTML = html;
            addBalloonItem(); addBalloonItem();
            break;
        case 'memory_game':
            html = '<div id="itemsList"></div>' +
                '<button class="btn btn-success mt-2" onclick="addMemoryItem()"><i class="fas fa-plus me-1"></i>إضافة زوج</button>';
            area.innerHTML = html;
            addMemoryItem(); addMemoryItem(); addMemoryItem(); addMemoryItem();
            break;
    }
}

// --- Quiz ---
function addQuizItem() {
    itemCounter++;
    var id = itemCounter;
    var html = '<div class="item-row" id="item-' + id + '">' +
        '<button class="remove-item" onclick="removeItem(' + id + ')"><i class="fas fa-times-circle"></i></button>' +
        '<div class="mb-2"><label class="form-label fw-bold">السؤال ' + id + '</label>' +
        '<input type="text" class="form-control item-question" placeholder="اكتب السؤال"></div>' +
        '<div class="row g-2">';
    for (var i = 0; i < 4; i++) {
        html += '<div class="col-md-6"><div class="input-group input-group-sm">' +
            '<div class="input-group-text"><input type="radio" name="correct-' + id + '" value="' + i + '"' + (i === 0 ? ' checked' : '') + '></div>' +
            '<input type="text" class="form-control item-option" placeholder="الخيار ' + (i + 1) + '"></div></div>';
    }
    html += '</div></div>';
    document.getElementById('itemsList').insertAdjacentHTML('beforeend', html);
}

// --- True/False ---
function addTrueFalseItem() {
    itemCounter++;
    var id = itemCounter;
    var html = '<div class="item-row" id="item-' + id + '">' +
        '<button class="remove-item" onclick="removeItem(' + id + ')"><i class="fas fa-times-circle"></i></button>' +
        '<div class="d-flex gap-2 align-items-center">' +
        '<input type="text" class="form-control item-statement" placeholder="اكتب العبارة...">' +
        '<select class="form-select item-answer" style="width:100px"><option value="true">صح ✓</option><option value="false">خطأ ✗</option></select>' +
        '</div></div>';
    document.getElementById('itemsList').insertAdjacentHTML('beforeend', html);
}

// --- Match ---
function addMatchItem() {
    itemCounter++;
    var id = itemCounter;
    var html = '<div class="item-row" id="item-' + id + '">' +
        '<button class="remove-item" onclick="removeItem(' + id + ')"><i class="fas fa-times-circle"></i></button>' +
        '<div class="d-flex gap-2 align-items-center">' +
        '<input type="text" class="form-control item-left" placeholder="العنصر الأيسر">' +
        '<i class="fas fa-arrows-alt-h text-primary"></i>' +
        '<input type="text" class="form-control item-right" placeholder="العنصر الأيمن (المطابق)">' +
        '</div></div>';
    document.getElementById('itemsList').insertAdjacentHTML('beforeend', html);
}

// --- Flashcards ---
function addFlashcardItem() {
    itemCounter++;
    var id = itemCounter;
    var html = '<div class="item-row" id="item-' + id + '">' +
        '<button class="remove-item" onclick="removeItem(' + id + ')"><i class="fas fa-times-circle"></i></button>' +
        '<div class="d-flex gap-2 align-items-center">' +
        '<input type="text" class="form-control item-front" placeholder="الوجه الأمامي (السؤال)">' +
        '<i class="fas fa-sync-alt text-info"></i>' +
        '<input type="text" class="form-control item-back" placeholder="الوجه الخلفي (الإجابة)">' +
        '</div></div>';
    document.getElementById('itemsList').insertAdjacentHTML('beforeend', html);
}

// --- Wheel ---
function addWheelItem() {
    itemCounter++;
    var id = itemCounter;
    var html = '<div class="item-row" id="item-' + id + '" style="padding:8px 12px">' +
        '<button class="remove-item" onclick="removeItem(' + id + ')"><i class="fas fa-times-circle"></i></button>' +
        '<input type="text" class="form-control form-control-sm item-segment" placeholder="خيار ' + id + '">' +
        '</div>';
    document.getElementById('itemsList').insertAdjacentHTML('beforeend', html);
}

// --- Group Sort ---
var groupCounter = 0;
function addGroup() {
    groupCounter++;
    var gid = groupCounter;
    var colors = ['#3b82f6', '#10b981', '#f59e0b', '#ef4444', '#8b5cf6', '#0ea5e9'];
    var color = colors[(gid - 1) % colors.length];
    var html = '<div class="group-container" id="group-' + gid + '">' +
        '<div class="group-header">' +
        '<span style="width:12px;height:12px;border-radius:50%;background:' + color + ';display:inline-block"></span>' +
        '<input type="text" class="form-control form-control-sm group-name" placeholder="اسم المجموعة" style="flex:1">' +
        '<button class="btn btn-sm btn-danger" onclick="removeGroup(' + gid + ')"><i class="fas fa-times"></i></button>' +
        '</div>' +
        '<div class="group-items" id="groupItems-' + gid + '"></div>' +
        '<button class="btn btn-sm btn-success mt-1" onclick="addGroupItem(' + gid + ')"><i class="fas fa-plus me-1"></i>إضافة عنصر</button>' +
        '</div>';
    document.getElementById('groupsList').insertAdjacentHTML('beforeend', html);
    addGroupItem(gid); addGroupItem(gid);
}

function addGroupItem(gid) {
    var html = '<div class="group-item-row">' +
        '<input type="text" class="form-control form-control-sm group-item" placeholder="عنصر">' +
        '<button class="btn btn-sm btn-outline-danger" onclick="this.parentElement.remove()"><i class="fas fa-times"></i></button>' +
        '</div>';
    document.getElementById('groupItems-' + gid).insertAdjacentHTML('beforeend', html);
}

function removeGroup(gid) {
    document.getElementById('group-' + gid).remove();
}

// --- Order ---
function addOrderItem() {
    itemCounter++;
    var id = itemCounter;
    var html = '<div class="item-row" id="item-' + id + '" style="padding:8px 12px">' +
        '<button class="remove-item" onclick="removeItem(' + id + ')"><i class="fas fa-times-circle"></i></button>' +
        '<div class="d-flex align-items-center gap-2">' +
        '<span class="badge bg-primary">' + id + '</span>' +
        '<input type="text" class="form-control form-control-sm item-order-text" placeholder="العنصر ' + id + ' (بالترتيب الصحيح)">' +
        '</div></div>';
    document.getElementById('itemsList').insertAdjacentHTML('beforeend', html);
}

// --- Open Box ---
function addOpenBoxItem() {
    itemCounter++;
    var id = itemCounter;
    var html = '<div class="item-row" id="item-' + id + '">' +
        '<button class="remove-item" onclick="removeItem(' + id + ')"><i class="fas fa-times-circle"></i></button>' +
        '<div class="row g-2">' +
        '<div class="col-md-6"><input type="text" class="form-control item-box-question" placeholder="السؤال"></div>' +
        '<div class="col-md-6"><input type="text" class="form-control item-box-answer" placeholder="الإجابة"></div>' +
        '</div></div>';
    document.getElementById('itemsList').insertAdjacentHTML('beforeend', html);
}

// --- Missing Word ---
function addMissingWordItem() {
    itemCounter++;
    var id = itemCounter;
    var html = '<div class="item-row" id="item-' + id + '">' +
        '<button class="remove-item" onclick="removeItem(' + id + ')"><i class="fas fa-times-circle"></i></button>' +
        '<textarea class="form-control item-missing-text" rows="2" placeholder="السماء [زرقاء] اللون والعشب [أخضر]"></textarea>' +
        '</div>';
    document.getElementById('itemsList').insertAdjacentHTML('beforeend', html);
}

// --- Anagram ---
function addAnagramItem() {
    itemCounter++;
    var id = itemCounter;
    var html = '<div class="item-row" id="item-' + id + '">' +
        '<button class="remove-item" onclick="removeItem(' + id + ')"><i class="fas fa-times-circle"></i></button>' +
        '<div class="d-flex gap-2">' +
        '<input type="text" class="form-control item-anagram-word" placeholder="الكلمة الصحيحة">' +
        '<input type="text" class="form-control item-anagram-hint" placeholder="تلميح (اختياري)" style="max-width:200px">' +
        '</div></div>';
    document.getElementById('itemsList').insertAdjacentHTML('beforeend', html);
}

// --- Balloon Pop ---
function addBalloonItem() {
    itemCounter++;
    var id = itemCounter;
    var html = '<div class="item-row" id="item-' + id + '">' +
        '<button class="remove-item" onclick="removeItem(' + id + ')"><i class="fas fa-times-circle"></i></button>' +
        '<div class="mb-2"><input type="text" class="form-control item-balloon-q" placeholder="السؤال (مثال: اختر الأعداد الزوجية)"></div>' +
        '<div class="row g-2">' +
        '<div class="col-md-6"><label class="text-success fw-bold"><i class="fas fa-check me-1"></i>الإجابات الصحيحة (كل سطر إجابة)</label>' +
        '<textarea class="form-control item-balloon-correct" rows="2" placeholder="2\n4\n6"></textarea></div>' +
        '<div class="col-md-6"><label class="text-danger fw-bold"><i class="fas fa-times me-1"></i>الإجابات الخاطئة</label>' +
        '<textarea class="form-control item-balloon-wrong" rows="2" placeholder="1\n3\n5"></textarea></div>' +
        '</div></div>';
    document.getElementById('itemsList').insertAdjacentHTML('beforeend', html);
}

// --- Memory Game ---
function addMemoryItem() {
    itemCounter++;
    var id = itemCounter;
    var html = '<div class="item-row" id="item-' + id + '">' +
        '<button class="remove-item" onclick="removeItem(' + id + ')"><i class="fas fa-times-circle"></i></button>' +
        '<div class="d-flex gap-2 align-items-center">' +
        '<input type="text" class="form-control item-mem-a" placeholder="البطاقة الأولى">' +
        '<i class="fas fa-equals text-primary"></i>' +
        '<input type="text" class="form-control item-mem-b" placeholder="البطاقة المطابقة">' +
        '</div></div>';
    document.getElementById('itemsList').insertAdjacentHTML('beforeend', html);
}

function removeItem(id) {
    var el = document.getElementById('item-' + id);
    if (el) el.remove();
}

// ============ COLLECT DATA ============
function collectItemsData() {
    var type = document.getElementById('selectedType').value;
    var data = [];

    switch (type) {
        case 'quiz':
            document.querySelectorAll('#itemsList .item-row').forEach(function(row) {
                var q = row.querySelector('.item-question').value.trim();
                var opts = [];
                row.querySelectorAll('.item-option').forEach(function(o) { opts.push(o.value.trim()); });
                var correct = 0;
                row.querySelectorAll('input[type=radio]').forEach(function(r, i) { if (r.checked) correct = i; });
                if (q) data.push({question: q, options: opts, correct: correct});
            });
            break;
        case 'true_false':
            document.querySelectorAll('#itemsList .item-row').forEach(function(row) {
                var s = row.querySelector('.item-statement').value.trim();
                var a = row.querySelector('.item-answer').value === 'true';
                if (s) data.push({statement: s, answer: a});
            });
            break;
        case 'match':
            document.querySelectorAll('#itemsList .item-row').forEach(function(row) {
                var l = row.querySelector('.item-left').value.trim();
                var r = row.querySelector('.item-right').value.trim();
                if (l && r) data.push({left: l, right: r});
            });
            break;
        case 'flashcards':
            document.querySelectorAll('#itemsList .item-row').forEach(function(row) {
                var f = row.querySelector('.item-front').value.trim();
                var b = row.querySelector('.item-back').value.trim();
                if (f && b) data.push({front: f, back: b});
            });
            break;
        case 'wheel':
            document.querySelectorAll('#itemsList .item-row').forEach(function(row) {
                var s = row.querySelector('.item-segment').value.trim();
                if (s) data.push(s);
            });
            break;
        case 'group_sort':
            document.querySelectorAll('.group-container').forEach(function(gc) {
                var name = gc.querySelector('.group-name').value.trim();
                var items = [];
                gc.querySelectorAll('.group-item').forEach(function(gi) {
                    var v = gi.value.trim();
                    if (v) items.push(v);
                });
                if (name && items.length) data.push({name: name, items: items});
            });
            break;
        case 'order':
            document.querySelectorAll('#itemsList .item-row').forEach(function(row) {
                var t = row.querySelector('.item-order-text').value.trim();
                if (t) data.push(t);
            });
            break;
        case 'open_box':
            document.querySelectorAll('#itemsList .item-row').forEach(function(row) {
                var q = row.querySelector('.item-box-question').value.trim();
                var a = row.querySelector('.item-box-answer').value.trim();
                if (q && a) data.push({question: q, answer: a});
            });
            break;
        case 'missing_word':
            document.querySelectorAll('#itemsList .item-row').forEach(function(row) {
                var t = row.querySelector('.item-missing-text').value.trim();
                if (t) data.push(t);
            });
            break;
        case 'anagram':
            document.querySelectorAll('#itemsList .item-row').forEach(function(row) {
                var w = row.querySelector('.item-anagram-word').value.trim();
                var h = row.querySelector('.item-anagram-hint').value.trim();
                if (w) data.push({word: w, hint: h});
            });
            break;
        case 'balloon_pop':
            document.querySelectorAll('#itemsList .item-row').forEach(function(row) {
                var q = row.querySelector('.item-balloon-q').value.trim();
                var correct = row.querySelector('.item-balloon-correct').value.trim().split('\n').filter(function(s){return s.trim();});
                var wrong = row.querySelector('.item-balloon-wrong').value.trim().split('\n').filter(function(s){return s.trim();});
                if (q && correct.length) data.push({question: q, correct: correct, wrong: wrong});
            });
            break;
        case 'memory_game':
            document.querySelectorAll('#itemsList .item-row').forEach(function(row) {
                var a = row.querySelector('.item-mem-a').value.trim();
                var b = row.querySelector('.item-mem-b').value.trim();
                if (a && b) data.push({a: a, b: b});
            });
            break;
    }
    return data;
}

// ============ SAVE ============
function saveActivity() {
    var type = document.getElementById('selectedType').value;
    var title = document.getElementById('actTitle').value.trim();
    if (!title) { alert('يرجى كتابة عنوان النشاط'); goToStep(2); return; }

    var items = collectItemsData();
    if (items.length === 0) { alert('يرجى إضافة عنصر واحد على الأقل'); return; }

    var formData = new FormData();
    formData.append('csrf_token', csrf);
    formData.append('activity_id', document.getElementById('editingId').value);
    formData.append('title', title);
    formData.append('description', document.getElementById('actDesc').value.trim());
    formData.append('activity_type', type);
    formData.append('subject_id', document.getElementById('actSubject').value);
    var classOpt = document.getElementById('actClass');
    if (classOpt.value) {
        formData.append('class_id', classOpt.value);
        var selOpt = classOpt.options[classOpt.selectedIndex];
        formData.append('grade_id', selOpt.dataset.grade || '');
    } else {
        formData.append('grade_id', '');
    }
    formData.append('items_data', JSON.stringify(items));
    formData.append('settings', JSON.stringify({}));
    if (document.getElementById('actPublic').checked) formData.append('is_public', '1');

    fetch('activities.php?ajax=save', { method: 'POST', body: formData })
    .then(r => r.json())
    .then(function(res) {
        if (res.success) {
            alert(res.message);
            window.location.href = 'activities.php';
        } else {
            alert(res.message || 'حدث خطأ');
        }
    }).catch(function() { alert('خطأ في الاتصال'); });
}

// ============ EDIT ============
function editActivity(id) {
    fetch('activities.php?ajax=get_activity&id=' + id)
    .then(r => r.json())
    .then(function(res) {
        if (!res.success) { alert(res.message); return; }
        var d = res.data;
        document.getElementById('editingId').value = d.id;
        document.getElementById('selectedType').value = d.activity_type;
        document.getElementById('actTitle').value = d.title;
        document.getElementById('actDesc').value = d.description || '';
        document.getElementById('actSubject').value = d.subject_id || '';
        document.getElementById('actClass').value = d.class_id || '';
        document.getElementById('actPublic').checked = d.is_public == 1;

        document.querySelectorAll('.type-card').forEach(function(c) {
            c.classList.toggle('selected', c.dataset.type === d.activity_type);
        });

        document.getElementById('creatorTitle').innerHTML = '<i class="fas fa-edit me-2"></i>تعديل النشاط';
        showCreator(true);
        // Go to step 3 but skip buildItemsUI since populateItems will call it
        document.querySelectorAll('.creator-step').forEach(function(s) { s.classList.remove('active'); });
        document.getElementById('step3').classList.add('active');
        for (var i = 1; i <= 3; i++) { document.getElementById('stepBadge' + i).className = 'badge fs-6 step-badge bg-primary'; }
        document.getElementById('stepTypeLabel').textContent = activityTypes[d.activity_type].name;

        // Populate items (buildItemsUI called inside)
        setTimeout(function() { populateItems(d.activity_type, JSON.parse(d.items_data)); }, 100);
    });
}

function populateItems(type, items) {
    var area = document.getElementById('itemsBuilderArea');
    buildItemsUI(type);

    // Clear default items
    var list = document.getElementById('itemsList');
    if (list) list.innerHTML = '';
    var glist = document.getElementById('groupsList');
    if (glist) glist.innerHTML = '';
    itemCounter = 0;
    groupCounter = 0;

    switch (type) {
        case 'quiz':
            items.forEach(function(item) {
                addQuizItem();
                var rows = list.querySelectorAll('.item-row');
                var row = rows[rows.length - 1];
                row.querySelector('.item-question').value = item.question;
                row.querySelectorAll('.item-option').forEach(function(o, i) { o.value = item.options[i] || ''; });
                row.querySelectorAll('input[type=radio]').forEach(function(r, i) { r.checked = (i === item.correct); });
            });
            break;
        case 'true_false':
            items.forEach(function(item) {
                addTrueFalseItem();
                var rows = list.querySelectorAll('.item-row');
                var row = rows[rows.length - 1];
                row.querySelector('.item-statement').value = item.statement;
                row.querySelector('.item-answer').value = item.answer ? 'true' : 'false';
            });
            break;
        case 'match':
            items.forEach(function(item) {
                addMatchItem();
                var rows = list.querySelectorAll('.item-row');
                var row = rows[rows.length - 1];
                row.querySelector('.item-left').value = item.left;
                row.querySelector('.item-right').value = item.right;
            });
            break;
        case 'flashcards':
            items.forEach(function(item) {
                addFlashcardItem();
                var rows = list.querySelectorAll('.item-row');
                var row = rows[rows.length - 1];
                row.querySelector('.item-front').value = item.front;
                row.querySelector('.item-back').value = item.back;
            });
            break;
        case 'wheel':
            items.forEach(function(seg) {
                addWheelItem();
                var rows = list.querySelectorAll('.item-row');
                rows[rows.length - 1].querySelector('.item-segment').value = seg;
            });
            break;
        case 'group_sort':
            items.forEach(function(g) {
                addGroup();
                var groups = document.querySelectorAll('.group-container');
                var gc = groups[groups.length - 1];
                gc.querySelector('.group-name').value = g.name;
                var itemsDiv = gc.querySelector('.group-items');
                itemsDiv.innerHTML = '';
                g.items.forEach(function(itm) {
                    var gid = gc.id.replace('group-', '');
                    addGroupItem(gid);
                    var inputs = itemsDiv.querySelectorAll('.group-item');
                    inputs[inputs.length - 1].value = itm;
                });
            });
            break;
        case 'order':
            items.forEach(function(itm) {
                addOrderItem();
                var rows = list.querySelectorAll('.item-row');
                rows[rows.length - 1].querySelector('.item-order-text').value = itm;
            });
            break;
        case 'open_box':
            items.forEach(function(item) {
                addOpenBoxItem();
                var rows = list.querySelectorAll('.item-row');
                var row = rows[rows.length - 1];
                row.querySelector('.item-box-question').value = item.question;
                row.querySelector('.item-box-answer').value = item.answer;
            });
            break;
        case 'missing_word':
            items.forEach(function(txt) {
                addMissingWordItem();
                var rows = list.querySelectorAll('.item-row');
                rows[rows.length - 1].querySelector('.item-missing-text').value = txt;
            });
            break;
        case 'anagram':
            items.forEach(function(item) {
                addAnagramItem();
                var rows = list.querySelectorAll('.item-row');
                var row = rows[rows.length - 1];
                row.querySelector('.item-anagram-word').value = item.word;
                row.querySelector('.item-anagram-hint').value = item.hint || '';
            });
            break;
        case 'balloon_pop':
            items.forEach(function(item) {
                addBalloonItem();
                var rows = list.querySelectorAll('.item-row');
                var row = rows[rows.length - 1];
                row.querySelector('.item-balloon-q').value = item.question;
                row.querySelector('.item-balloon-correct').value = item.correct.join('\n');
                row.querySelector('.item-balloon-wrong').value = item.wrong.join('\n');
            });
            break;
        case 'memory_game':
            items.forEach(function(item) {
                addMemoryItem();
                var rows = list.querySelectorAll('.item-row');
                var row = rows[rows.length - 1];
                row.querySelector('.item-mem-a').value = item.a;
                row.querySelector('.item-mem-b').value = item.b;
            });
            break;
    }
}

// ============ ACTIONS ============
function shareActivity(btn) {
    var code = btn.dataset.code;
    var title = btn.dataset.title;
    var link = baseUrl + '/play_activity.php?code=' + encodeURIComponent(code);
    document.getElementById('shareTitle').textContent = title;
    document.getElementById('shareLink').textContent = link;
    document.getElementById('shareWhatsapp').href = 'https://wa.me/?text=' + encodeURIComponent(title + '\n' + link);
    new bootstrap.Modal(document.getElementById('shareModal')).show();
}

function copyShareLink() {
    var text = document.getElementById('shareLink').textContent;
    navigator.clipboard.writeText(text).then(function() {
        alert('تم نسخ الرابط');
    });
}

function viewResults(id, title) {
    document.getElementById('resultsTitle').textContent = title;
    document.getElementById('resultsContent').innerHTML = '<div class="text-center py-3"><i class="fas fa-spinner fa-spin fa-2x"></i></div>';
    new bootstrap.Modal(document.getElementById('resultsModal')).show();

    fetch('activities.php?ajax=get_results&id=' + id)
    .then(r => r.json())
    .then(function(res) {
        if (!res.results || !res.results.length) {
            document.getElementById('resultsContent').innerHTML = '<div class="text-center py-4 text-muted"><i class="fas fa-inbox fa-3x mb-2"></i><p>لا توجد نتائج بعد</p></div>';
            return;
        }
        var html = '<div class="table-responsive"><table class="table table-hover table-striped"><thead><tr>' +
            '<th>#</th><th>الاسم</th><th>النتيجة</th><th>النسبة</th><th>الوقت</th><th>التاريخ</th></tr></thead><tbody>';
        res.results.forEach(function(r, i) {
            var pct = r.max_score > 0 ? Math.round((r.score / r.max_score) * 100) : 0;
            var color = pct >= 80 ? '#10b981' : (pct >= 50 ? '#f59e0b' : '#ef4444');
            html += '<tr><td>' + (i + 1) + '</td><td>' + escHtml(r.name) + '</td>' +
                '<td>' + r.score + '/' + r.max_score + '</td>' +
                '<td><div class="result-bar" style="width:100px"><div class="fill" style="width:' + pct + '%;background:' + color + '"></div></div> ' + pct + '%</td>' +
                '<td>' + formatTime(r.time_spent) + '</td>' +
                '<td>' + escHtml(r.completed_at) + '</td></tr>';
        });
        html += '</tbody></table></div>';
        document.getElementById('resultsContent').innerHTML = html;
    });
}

function toggleStatus(id) {
    fetch('activities.php?ajax=toggle_status', {
        method: 'POST',
        headers: {'Content-Type': 'application/x-www-form-urlencoded'},
        body: 'id=' + id + '&csrf_token=' + csrf
    }).then(r => r.json()).then(function(res) {
        if (res.success) {
            var card = document.getElementById('act-card-' + id);
            if (card) {
                var badge = card.querySelector('.badge');
                var toggleBtn = card.querySelector('[onclick*="toggleStatus"]');
                if (res.new_status === 'active') {
                    if (badge) { badge.className = 'badge bg-success'; badge.textContent = 'نشط'; }
                    if (toggleBtn) { toggleBtn.className = 'btn btn-sm btn-secondary me-1'; toggleBtn.title = 'إيقاف'; toggleBtn.innerHTML = '<i class="fas fa-pause"></i>'; }
                } else {
                    if (badge) { badge.className = 'badge bg-secondary'; badge.textContent = 'متوقف'; }
                    if (toggleBtn) { toggleBtn.className = 'btn btn-sm btn-success me-1'; toggleBtn.title = 'تفعيل'; toggleBtn.innerHTML = '<i class="fas fa-play"></i>'; }
                }
            }
        } else alert(res.message);
    });
}

var deleteId = 0;
function confirmDelete(id, title) {
    deleteId = id;
    document.getElementById('deleteTitle').textContent = title;
    new bootstrap.Modal(document.getElementById('deleteModal')).show();
}

document.getElementById('confirmDeleteBtn').addEventListener('click', function() {
    fetch('activities.php?ajax=delete', {
        method: 'POST',
        headers: {'Content-Type': 'application/x-www-form-urlencoded'},
        body: 'id=' + deleteId + '&csrf_token=' + csrf
    }).then(r => r.json()).then(function(res) {
        if (res.success) {
            var card = document.getElementById('act-card-' + deleteId);
            if (card) { card.style.transition = 'opacity .3s'; card.style.opacity = '0'; setTimeout(function() { card.remove(); }, 300); }
            bootstrap.Modal.getInstance(document.getElementById('deleteModal')).hide();
        } else { alert(res.message); }
    });
});

function previewActivity() {
    var type = document.getElementById('selectedType').value;
    var items = collectItemsData();
    if (!items.length) { alert('أضف عناصر أولاً'); return; }
    // Open in new window with preview data
    var preview = window.open('', '_blank');
    preview.document.write('<html><head><meta charset="utf-8"><title>معاينة</title></head><body><h2>جارٍ التحميل...</h2></body></html>');
    // Store in sessionStorage for preview
    sessionStorage.setItem('preview_activity', JSON.stringify({
        title: document.getElementById('actTitle').value || 'معاينة',
        activity_type: type,
        items_data: JSON.stringify(items)
    }));
    preview.location.href = baseUrl + '/play_activity.php?preview=1';
}

// ============ HELPERS ============
function escHtml(t) {
    var d = document.createElement('div');
    d.textContent = t;
    return d.innerHTML;
}

function formatTime(secs) {
    secs = parseInt(secs) || 0;
    var m = Math.floor(secs / 60);
    var s = secs % 60;
    return m + ':' + (s < 10 ? '0' : '') + s;
}

// Init tooltips
document.querySelectorAll('[data-bs-toggle="tooltip"]').forEach(function(el) {
    new bootstrap.Tooltip(el);
});
</script>

<?php require_once '../includes/teacher_footer.php'; ?>
