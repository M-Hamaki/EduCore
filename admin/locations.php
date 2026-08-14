<?php
/**
 * Locations Management — المناطق الجغرافية
 * Hierarchical: محافظة → مدينة → مركز → حي → شارع
 */
$page_title = "المناطق الجغرافية";
$custom_page_title = true;

require_once '../config/database.php';
require_once '../classes/utilities.php';
require_once '../classes/ActivityLog.php';
require_once '../includes/session_config.php';
Utilities::validateSession('admin');

$database = new Database();
$db = $database->getConnection();

// PRG: session messages
$success_message = $_SESSION['success_message'] ?? null;
$error_message = $_SESSION['error_message'] ?? null;
unset($_SESSION['success_message'], $_SESSION['error_message']);

// Active level tab
$activeLevel = $_GET['level'] ?? 'governorates';
$validLevels = ['governorates', 'cities', 'centers', 'neighborhoods', 'streets'];
if (!in_array($activeLevel, $validLevels)) $activeLevel = 'governorates';

// =============== AJAX ENDPOINTS ===============
if (isset($_GET['ajax'])) {
    header('Content-Type: application/json; charset=utf-8');

    // Get children for a parent (cascading dropdowns)
    if ($_GET['ajax'] === 'get_children') {
        $parent_type = $_GET['parent_type'] ?? '';
        $parent_id = (int)($_GET['parent_id'] ?? 0);

        $map = [
            'governorate' => ['table' => 'cities', 'fk' => 'governorate_id'],
            'city'        => ['table' => 'centers', 'fk' => 'city_id'],
            'center'      => ['table' => 'neighborhoods', 'fk' => 'center_id'],
            'neighborhood'=> ['table' => 'streets', 'fk' => 'neighborhood_id'],
        ];

        if (!isset($map[$parent_type]) || $parent_id <= 0) {
            echo json_encode(['success' => false, 'items' => []]);
            exit();
        }

        $cfg = $map[$parent_type];
        $stmt = $db->prepare("SELECT id, name, status FROM {$cfg['table']} WHERE {$cfg['fk']} = ? ORDER BY display_order, name");
        $stmt->execute([$parent_id]);
        echo json_encode(['success' => true, 'items' => $stmt->fetchAll(PDO::FETCH_ASSOC)]);
        exit();
    }

    echo json_encode(['success' => false, 'message' => 'Invalid action']);
    exit();
}

// =============== POST HANDLERS ===============
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!isset($_POST['csrf_token']) || !hash_equals($_SESSION['csrf_token'], $_POST['csrf_token'])) {
        $_SESSION['error_message'] = "خطأ في التحقق من الأمان";
        header("Location: locations.php?level=$activeLevel");
        exit();
    }

    $action = $_POST['action'] ?? '';

    // Table/FK mapping for all levels
    $levelConfig = [
        'governorates'  => ['table' => 'governorates', 'fk' => null, 'label' => 'محافظة', 'log_type' => 'governorate'],
        'cities'        => ['table' => 'cities', 'fk' => 'governorate_id', 'label' => 'مدينة', 'log_type' => 'city'],
        'centers'       => ['table' => 'centers', 'fk' => 'city_id', 'label' => 'مركز', 'log_type' => 'center'],
        'neighborhoods' => ['table' => 'neighborhoods', 'fk' => 'center_id', 'label' => 'حي', 'log_type' => 'neighborhood'],
        'streets'       => ['table' => 'streets', 'fk' => 'neighborhood_id', 'label' => 'شارع', 'log_type' => 'street'],
    ];

    $level = $_POST['level'] ?? 'governorates';
    if (!isset($levelConfig[$level])) {
        $_SESSION['error_message'] = "مستوى غير صحيح";
        header("Location: locations.php?level=$activeLevel");
        exit();
    }
    $cfg = $levelConfig[$level];

    // ADD
    if ($action === 'add') {
        $name = trim($_POST['name'] ?? '');
        $parent_id = (int)($_POST['parent_id'] ?? 0);
        $display_order = (int)($_POST['display_order'] ?? 0);

        if (empty($name)) {
            $_SESSION['error_message'] = "الاسم مطلوب";
            header("Location: locations.php?level=$level");
            exit();
        }

        // Convert parent_id 0 to NULL for optional hierarchy
        $parent_id = ($cfg['fk'] && $parent_id > 0) ? $parent_id : null;

        try {
            if ($cfg['fk']) {
                $stmt = $db->prepare("INSERT INTO {$cfg['table']} (name, {$cfg['fk']}, display_order) VALUES (?, ?, ?)");
                $stmt->execute([$name, $parent_id, $display_order]);
            } else {
                $stmt = $db->prepare("INSERT INTO {$cfg['table']} (name, display_order) VALUES (?, ?)");
                $stmt->execute([$name, $display_order]);
            }
            $newId = $db->lastInsertId();
            ActivityLog::logCreate($cfg['log_type'], $newId, $name, ['level' => $cfg['label']]);
            $_SESSION['success_message'] = "تمت إضافة {$cfg['label']} «{$name}» بنجاح";
        } catch (PDOException $e) {
            if (strpos($e->getMessage(), 'Duplicate') !== false) {
                $_SESSION['error_message'] = "هذا الاسم موجود بالفعل في نفس المستوى";
            } else {
                $_SESSION['error_message'] = "خطأ: " . $e->getMessage();
            }
        }
        header("Location: locations.php?level=$level");
        exit();
    }

    // EDIT
    if ($action === 'edit') {
        $id = (int)($_POST['id'] ?? 0);
        $name = trim($_POST['name'] ?? '');
        $display_order = (int)($_POST['display_order'] ?? 0);
        $status = ($_POST['status'] ?? 'active') === 'active' ? 'active' : 'inactive';

        if ($id <= 0 || empty($name)) {
            $_SESSION['error_message'] = "بيانات غير صحيحة";
            header("Location: locations.php?level=$level");
            exit();
        }

        try {
            // Get old data for log
            $stmt = $db->prepare("SELECT * FROM {$cfg['table']} WHERE id = ?");
            $stmt->execute([$id]);
            $old = $stmt->fetch(PDO::FETCH_ASSOC);

            $stmt = $db->prepare("UPDATE {$cfg['table']} SET name = ?, display_order = ?, status = ? WHERE id = ?");
            $stmt->execute([$name, $display_order, $status, $id]);

            ActivityLog::logUpdate($cfg['log_type'], $id, $name, [
                'old_name' => $old['name'] ?? '',
                'new_name' => $name,
                'status' => $status
            ]);
            $_SESSION['success_message'] = "تم تعديل {$cfg['label']} «{$name}» بنجاح";
        } catch (PDOException $e) {
            if (strpos($e->getMessage(), 'Duplicate') !== false) {
                $_SESSION['error_message'] = "هذا الاسم موجود بالفعل";
            } else {
                $_SESSION['error_message'] = "خطأ: " . $e->getMessage();
            }
        }
        header("Location: locations.php?level=$level");
        exit();
    }

    // DELETE
    if ($action === 'delete') {
        $id = (int)($_POST['id'] ?? 0);

        if ($id <= 0) {
            $_SESSION['error_message'] = "بيانات غير صحيحة";
            header("Location: locations.php?level=$level");
            exit();
        }

        try {
            $stmt = $db->prepare("SELECT name FROM {$cfg['table']} WHERE id = ?");
            $stmt->execute([$id]);
            $name = $stmt->fetchColumn();

            $stmt = $db->prepare("DELETE FROM {$cfg['table']} WHERE id = ?");
            $stmt->execute([$id]);

            ActivityLog::logDelete($cfg['log_type'], $id, $name, ['level' => $cfg['label']]);
            $_SESSION['success_message'] = "تم حذف {$cfg['label']} «{$name}» وجميع العناصر التابعة";
        } catch (Exception $e) {
            $_SESSION['error_message'] = "خطأ: " . $e->getMessage();
        }
        header("Location: locations.php?level=$level");
        exit();
    }

    header("Location: locations.php?level=$activeLevel");
    exit();
}

// =============== FETCH DATA ===============
// Counts per level
$govCount = (int)$db->query("SELECT COUNT(*) FROM governorates")->fetchColumn();
$cityCount = (int)$db->query("SELECT COUNT(*) FROM cities")->fetchColumn();
$centerCount = (int)$db->query("SELECT COUNT(*) FROM centers")->fetchColumn();
$neighCount = (int)$db->query("SELECT COUNT(*) FROM neighborhoods")->fetchColumn();
$streetCount = (int)$db->query("SELECT COUNT(*) FROM streets")->fetchColumn();

// Fetch items for active level
$items = [];
$parentOptions = [];

switch ($activeLevel) {
    case 'governorates':
        $items = $db->query("SELECT g.*, (SELECT COUNT(*) FROM cities WHERE governorate_id = g.id) as child_count FROM governorates g ORDER BY g.display_order, g.name")->fetchAll(PDO::FETCH_ASSOC);
        break;

    case 'cities':
        $items = $db->query("SELECT c.*, g.name as parent_name, (SELECT COUNT(*) FROM centers WHERE city_id = c.id) as child_count FROM cities c LEFT JOIN governorates g ON c.governorate_id = g.id ORDER BY g.name, c.display_order, c.name")->fetchAll(PDO::FETCH_ASSOC);
        $parentOptions = $db->query("SELECT id, name FROM governorates WHERE status = 'active' ORDER BY display_order, name")->fetchAll(PDO::FETCH_ASSOC);
        break;

    case 'centers':
        $items = $db->query("SELECT cn.*, ci.name as parent_name, g.name as grandparent_name, (SELECT COUNT(*) FROM neighborhoods WHERE center_id = cn.id) as child_count FROM centers cn LEFT JOIN cities ci ON cn.city_id = ci.id LEFT JOIN governorates g ON ci.governorate_id = g.id ORDER BY g.name, ci.name, cn.display_order, cn.name")->fetchAll(PDO::FETCH_ASSOC);
        // Parent = cities, but we need cascading: gov → city
        $parentOptions = $db->query("SELECT id, name FROM governorates WHERE status = 'active' ORDER BY display_order, name")->fetchAll(PDO::FETCH_ASSOC);
        break;

    case 'neighborhoods':
        $items = $db->query("SELECT n.*, cn.name as parent_name, ci.name as grandparent_name, (SELECT COUNT(*) FROM streets WHERE neighborhood_id = n.id) as child_count FROM neighborhoods n LEFT JOIN centers cn ON n.center_id = cn.id LEFT JOIN cities ci ON cn.city_id = ci.id ORDER BY ci.name, cn.name, n.display_order, n.name")->fetchAll(PDO::FETCH_ASSOC);
        $parentOptions = $db->query("SELECT id, name FROM governorates WHERE status = 'active' ORDER BY display_order, name")->fetchAll(PDO::FETCH_ASSOC);
        break;

    case 'streets':
        $items = $db->query("SELECT s.*, n.name as parent_name, cn.name as grandparent_name, cn.id as center_id FROM streets s LEFT JOIN neighborhoods n ON s.neighborhood_id = n.id LEFT JOIN centers cn ON n.center_id = cn.id ORDER BY cn.name, n.name, s.display_order, s.name")->fetchAll(PDO::FETCH_ASSOC);
        $parentOptions = $db->query("SELECT id, name FROM governorates WHERE status = 'active' ORDER BY display_order, name")->fetchAll(PDO::FETCH_ASSOC);
        break;
}

$levelLabels = [
    'governorates'  => ['name' => 'المحافظات', 'singular' => 'محافظة', 'icon' => 'fas fa-globe-africa', 'color' => '#3b82f6', 'parent_label' => null],
    'cities'        => ['name' => 'المدن', 'singular' => 'مدينة', 'icon' => 'fas fa-city', 'color' => '#10b981', 'parent_label' => 'المحافظة'],
    'centers'       => ['name' => 'المراكز', 'singular' => 'مركز', 'icon' => 'fas fa-building', 'color' => '#f59e0b', 'parent_label' => 'المدينة'],
    'neighborhoods' => ['name' => 'المنطقة أو الحي', 'singular' => 'منطقة أو حي', 'icon' => 'fas fa-map-signs', 'color' => '#8b5cf6', 'parent_label' => 'المركز'],
    'streets'       => ['name' => 'الشوارع', 'singular' => 'شارع', 'icon' => 'fas fa-road', 'color' => '#ef4444', 'parent_label' => 'المنطقة أو الحي'],
];
$currentLevel = $levelLabels[$activeLevel];

include_once '../includes/admin_header.php';
?>

<!-- Page Title and Buttons Toolbar -->
<div class="d-flex justify-content-between flex-wrap flex-md-nowrap align-items-center pt-3 pb-2 mb-3 border-bottom">
    <h1 class="h2"><i class="fas fa-map-marked-alt me-2"></i>المناطق الجغرافية</h1>
    <div class="admin-top-actions no-print">
        <button type="button" class="btn btn-header-premium btn-success shadow-sm" data-bs-toggle="modal" data-bs-target="#addModal">
            <i class="fas fa-plus-circle me-1"></i>إضافة <?php echo $currentLevel['singular']; ?>
        </button>
        <button type="button" class="btn btn-header-premium btn-import-soft" data-bs-toggle="modal" data-bs-target="#importLocationsModal">
            <i class="fas fa-file-import me-1"></i>استيراد Excel
        </button>
    </div>
</div>

<!-- Alerts -->
<?php if ($success_message): ?>
<div class="alert alert-success alert-dismissible fade show" role="alert">
    <i class="fas fa-check-circle me-2"></i><?php echo $success_message; ?>
    <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
</div>
<?php endif; ?>
<?php if ($error_message): ?>
<div class="alert alert-danger alert-dismissible fade show" role="alert">
    <i class="fas fa-exclamation-circle me-2"></i><?php echo htmlspecialchars($error_message); ?>
    <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
</div>
<?php endif; ?>

<!-- Stat Cards -->
<div class="row row-cols-2 row-cols-md-5 g-3 mb-4">
    <?php
    $statItems = [
        ['count' => $govCount, 'label' => 'المحافظات', 'icon' => 'fas fa-globe-africa', 'grad' => '#3b82f6, #2563eb', 'level' => 'governorates'],
        ['count' => $cityCount, 'label' => 'المدن', 'icon' => 'fas fa-city', 'grad' => '#10b981, #059669', 'level' => 'cities'],
        ['count' => $centerCount, 'label' => 'المراكز', 'icon' => 'fas fa-building', 'grad' => '#f59e0b, #d97706', 'level' => 'centers'],
        ['count' => $neighCount, 'label' => 'المنطقة أو الحي', 'icon' => 'fas fa-map-signs', 'grad' => '#8b5cf6, #7c3aed', 'level' => 'neighborhoods'],
        ['count' => $streetCount, 'label' => 'الشوارع', 'icon' => 'fas fa-road', 'grad' => '#ef4444, #dc2626', 'level' => 'streets'],
    ];
    foreach ($statItems as $si): ?>
    <div class="col">
        <a href="locations.php?level=<?php echo $si['level']; ?>" class="text-decoration-none">
        <div class="stat-card" style="--card-gradient: linear-gradient(135deg, <?php echo $si['grad']; ?>);">
            <div class="stat-card-icon"><i class="<?php echo $si['icon']; ?>"></i></div>
            <div class="stat-card-info">
                <div class="stat-card-number counter" data-target="<?php echo $si['count']; ?>">0</div>
                <div class="stat-card-label"><?php echo $si['label']; ?></div>
            </div>
        </div>
        </a>
    </div>
    <?php endforeach; ?>
</div>

<!-- Level Tabs -->
<?php $levelCounts = ['governorates' => $govCount, 'cities' => $cityCount, 'centers' => $centerCount, 'neighborhoods' => $neighCount, 'streets' => $streetCount]; ?>
<ul class="nav nav-tabs mb-3 border-bottom admin-tabs no-print" id="levelTabs">
    <?php foreach ($levelLabels as $lKey => $lVal): ?>
    <li class="nav-item">
        <a class="nav-link <?php echo $activeLevel === $lKey ? 'active' : ''; ?>" href="locations.php?level=<?php echo $lKey; ?>">
            <i class="<?php echo $lVal['icon']; ?> me-1"></i><?php echo $lVal['name']; ?>
            <span class="badge bg-secondary ms-1"><?php echo $levelCounts[$lKey]; ?></span>
        </a>
    </li>
    <?php endforeach; ?>
</ul>

<!-- Filter Bar -->
<form id="locationsFilterForm" class="admin-filter-bar no-print mb-4" novalidate>
    <div class="admin-filter-controls">
        <select id="filterLocationStatus" class="form-select form-select-sm" style="min-width: 150px;">
            <option value="">الحالة: الكل</option>
            <option value="مفعّل">مفعّل</option>
            <option value="معطّل">معطّل</option>
        </select>
    </div>
    <div class="admin-filter-actions">
        <button type="button" class="btn btn-light btn-sm" id="btnResetLocationFilters"><i class="fas fa-rotate-left me-1"></i>إعادة تعيين</button>
    </div>
</form>

<!-- Data Table -->
<div class="admin-list-surface">
        <?php if (empty($items)): ?>
            <div class="text-center py-5 text-muted p-3">
                <i class="<?php echo $currentLevel['icon']; ?> fa-3x mb-3 opacity-50"></i>
                <p>لا توجد <?php echo $currentLevel['name']; ?> مسجلة بعد</p>
                <button class="btn btn-success btn-sm" data-bs-toggle="modal" data-bs-target="#addModal">
                    <i class="fas fa-plus me-1"></i>إضافة <?php echo $currentLevel['singular']; ?>
                </button>
            </div>
        <?php else: ?>
        <div class="table-responsive admin-table-wrap">
            <table class="table table-hover table-striped admin-data-table" id="locationsTable">
                <thead>
                    <tr>
                        <th>#</th>
                        <th>الاسم</th>
                        <?php if ($currentLevel['parent_label']): ?>
                        <th><?php echo $currentLevel['parent_label']; ?></th>
                        <?php endif; ?>
                        <?php if ($activeLevel !== 'streets'): ?>
                        <th>العناصر الفرعية</th>
                        <?php endif; ?>
                        <th>الترتيب</th>
                        <th>الحالة</th>
                        <th>إجراءات</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($items as $idx => $item): ?>
                    <tr>
                        <td><?php echo $idx + 1; ?></td>
                        <td><strong><?php echo htmlspecialchars($item['name']); ?></strong></td>
                        <?php if ($currentLevel['parent_label']): ?>
                        <td>
                            <?php if (!empty($item['parent_name'])): ?>
                                <?php echo htmlspecialchars($item['parent_name']); ?>
                            <?php else: ?>
                                <span class="badge bg-secondary"><i class="fas fa-globe me-1"></i>بدون تصنيف</span>
                            <?php endif; ?>
                            <?php if (!empty($item['grandparent_name'])): ?>
                            <small class="text-muted d-block"><?php echo htmlspecialchars($item['grandparent_name']); ?></small>
                            <?php endif; ?>
                        </td>
                        <?php endif; ?>
                        <?php if ($activeLevel !== 'streets'): ?>
                        <td><span class="badge bg-info"><?php echo $item['child_count']; ?></span></td>
                        <?php endif; ?>
                        <td><?php echo $item['display_order']; ?></td>
                        <td><span class="badge <?php echo $item['status'] === 'active' ? 'bg-success' : 'bg-secondary'; ?>"><?php echo $item['status'] === 'active' ? 'مفعّل' : 'معطّل'; ?></span></td>
                        <td class="actions-column">
                            <button class="btn btn-action-pills btn-edit me-1" data-bs-toggle="tooltip" title="تعديل"
                                onclick="openEditModal(<?php echo htmlspecialchars(json_encode($item)); ?>)">
                                <i class="fas fa-edit"></i>
                            </button>
                            <button class="btn btn-action-pills btn-delete" data-bs-toggle="tooltip" title="حذف"
                                onclick="openDeleteModal(<?php echo $item['id']; ?>, '<?php echo htmlspecialchars($item['name'], ENT_QUOTES); ?>')">
                                <i class="fas fa-trash"></i>
                            </button>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <?php endif; ?>
</div>

<!-- ====== ADD MODAL ====== -->
<div class="modal fade" id="addModal" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content admin-modal admin-modal-premium admin-modal-create">
            <form method="post" action="locations.php">
                <input type="hidden" name="csrf_token" value="<?php echo $_SESSION['csrf_token']; ?>">
                <input type="hidden" name="action" value="add">
                <input type="hidden" name="level" value="<?php echo $activeLevel; ?>">

                <div class="modal-header">
                    <h5 class="modal-title"><i class="<?php echo $currentLevel['icon']; ?> me-2"></i>إضافة <?php echo $currentLevel['singular']; ?></h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <?php if ($activeLevel !== 'governorates'): ?>
                    <!-- Cascading parent selectors -->
                    <?php if (in_array($activeLevel, ['centers', 'neighborhoods', 'streets'])): ?>
                    <div class="mb-3">
                        <label class="form-label fw-bold">المحافظة <small class="text-muted">(اختياري)</small></label>
                        <select class="form-select" id="addGov" onchange="loadChildren('governorate', this.value, 'addCity')">
                            <option value="">-- اختر المحافظة --</option>
                            <?php foreach ($parentOptions as $p): ?>
                            <option value="<?php echo $p['id']; ?>"><?php echo htmlspecialchars($p['name']); ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <?php endif; ?>

                    <?php if ($activeLevel === 'cities'): ?>
                    <div class="mb-3">
                        <label class="form-label fw-bold"><?php echo $currentLevel['parent_label']; ?> <small class="text-muted">(اختياري)</small></label>
                        <select class="form-select" name="parent_id">
                            <option value="">-- اختر <?php echo $currentLevel['parent_label']; ?> --</option>
                            <?php foreach ($parentOptions as $p): ?>
                            <option value="<?php echo $p['id']; ?>"><?php echo htmlspecialchars($p['name']); ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <?php endif; ?>

                    <?php if (in_array($activeLevel, ['centers', 'neighborhoods', 'streets'])): ?>
                    <div class="mb-3">
                        <label class="form-label fw-bold">المدينة <small class="text-muted">(اختياري)</small></label>
                        <select class="form-select" id="addCity" <?php echo $activeLevel === 'centers' ? 'name="parent_id"' : ''; ?> onchange="<?php echo $activeLevel !== 'centers' ? "loadChildren('city', this.value, 'addCenter')" : ''; ?>">
                            <option value="">-- اختر المدينة --</option>
                        </select>
                    </div>
                    <?php endif; ?>

                    <?php if (in_array($activeLevel, ['neighborhoods', 'streets'])): ?>
                    <div class="mb-3">
                        <label class="form-label fw-bold">المركز <small class="text-muted">(اختياري)</small></label>
                        <select class="form-select" id="addCenter" <?php echo $activeLevel === 'neighborhoods' ? 'name="parent_id"' : ''; ?> onchange="<?php echo $activeLevel === 'streets' ? "loadChildren('center', this.value, 'addNeighborhood')" : ''; ?>">
                            <option value="">-- اختر المركز --</option>
                        </select>
                    </div>
                    <?php endif; ?>

                    <?php if ($activeLevel === 'streets'): ?>
                    <div class="mb-3">
                        <label class="form-label fw-bold">المنطقة أو الحي <small class="text-muted">(اختياري)</small></label>
                        <select class="form-select" id="addNeighborhood" name="parent_id">
                            <option value="">-- اختر المنطقة أو الحي --</option>
                        </select>
                    </div>
                    <?php endif; ?>
                    <?php endif; ?>

                    <div class="mb-3">
                        <label class="form-label fw-bold">الاسم <span class="text-danger">*</span></label>
                        <input type="text" name="name" class="form-control" required maxlength="150" placeholder="أدخل اسم <?php echo $currentLevel['singular']; ?>">
                    </div>
                    <div class="mb-3">
                        <label class="form-label">ترتيب العرض</label>
                        <input type="number" name="display_order" class="form-control" value="0" min="0">
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal"><i class="fas fa-times me-1"></i>إلغاء</button>
                    <button type="submit" class="btn btn-success"><i class="fas fa-plus me-1"></i>إضافة</button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- ====== EDIT MODAL ====== -->
<div class="modal fade" id="editModal" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content admin-modal admin-modal-premium admin-modal-edit">
            <form method="post" action="locations.php">
                <input type="hidden" name="csrf_token" value="<?php echo $_SESSION['csrf_token']; ?>">
                <input type="hidden" name="action" value="edit">
                <input type="hidden" name="level" value="<?php echo $activeLevel; ?>">
                <input type="hidden" name="id" id="editId">

                <div class="modal-header">
                    <h5 class="modal-title"><i class="fas fa-edit me-2"></i>تعديل <?php echo $currentLevel['singular']; ?></h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <div class="mb-3">
                        <label class="form-label fw-bold">الاسم <span class="text-danger">*</span></label>
                        <input type="text" name="name" id="editName" class="form-control" required maxlength="150">
                    </div>
                    <div class="mb-3">
                        <label class="form-label">ترتيب العرض</label>
                        <input type="number" name="display_order" id="editOrder" class="form-control" min="0">
                    </div>
                    <div class="mb-3">
                        <label class="form-label fw-bold">الحالة</label>
                        <select name="status" id="editStatus" class="form-select">
                            <option value="active">مفعّل</option>
                            <option value="inactive">معطّل</option>
                        </select>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal"><i class="fas fa-times me-1"></i>إلغاء</button>
                    <button type="submit" class="btn btn-primary"><i class="fas fa-save me-1"></i>حفظ</button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- ====== DELETE MODAL ====== -->
<div class="modal fade" id="deleteModal" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content admin-modal admin-modal-premium admin-modal-delete">
            <form method="post" action="locations.php">
                <input type="hidden" name="csrf_token" value="<?php echo $_SESSION['csrf_token']; ?>">
                <input type="hidden" name="action" value="delete">
                <input type="hidden" name="level" value="<?php echo $activeLevel; ?>">
                <input type="hidden" name="id" id="deleteId">

                <div class="modal-header">
                    <h5 class="modal-title"><i class="fas fa-trash me-2"></i>حذف <?php echo $currentLevel['singular']; ?></h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <div class="text-center mb-3"><i class="fas fa-exclamation-triangle text-danger" style="font-size: 3rem;"></i></div>
                    <p class="text-center">هل تريد حذف <?php echo $currentLevel['singular']; ?> <strong class="text-primary" id="deleteName"></strong>؟</p>
                    <div class="alert alert-danger"><i class="fas fa-info-circle me-2"></i>سيتم حذف جميع العناصر الفرعية التابعة أيضاً (CASCADE).</div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal"><i class="fas fa-times me-1"></i>إلغاء</button>
                    <button type="submit" class="btn btn-danger"><i class="fas fa-trash me-1"></i>تأكيد الحذف</button>
                </div>
            </form>
        </div>
    </div>
</div>

<div class="modal fade" id="importLocationsModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog">
        <div class="modal-content admin-modal admin-modal-premium admin-modal-create">
            <div class="modal-header">
                <h5 class="modal-title"><i class="fas fa-file-import me-2"></i>استيراد <?php echo $currentLevel['name']; ?></h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body">
                <p>ارفع ملف Excel أو CSV لاستيراد بيانات <?php echo $currentLevel['name']; ?>.</p>
                <form id="importLocationsForm" method="post" enctype="multipart/form-data" action="import_locations.php">
                    <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($_SESSION['csrf_token']); ?>">
                    <input type="hidden" name="level" value="<?php echo htmlspecialchars($activeLevel); ?>">
                    <div class="mb-3">
                        <label for="locationsFile" class="form-label">اختر الملف</label>
                        <input type="file" class="form-control" id="locationsFile" name="file" accept=".xlsx,.xls,.csv" required>
                    </div>
                </form>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">إلغاء</button>
                <button type="submit" class="btn btn-success" form="importLocationsForm"><i class="fas fa-upload me-1"></i>استيراد</button>
            </div>
        </div>
    </div>
</div>

<div class="modal fade" id="tableSettingsModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog">
        <div class="modal-content admin-modal admin-modal-premium admin-modal-view">
            <div class="modal-header">
                <h5 class="modal-title"><i class="fas fa-cog me-2"></i>إعدادات أعمدة الجدول</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body">
                <div class="form-check">
                    <input class="form-check-input" type="checkbox" id="col_name" checked>
                    <label class="form-check-label" for="col_name">الاسم</label>
                </div>
                <?php if ($currentLevel['parent_label']): ?>
                <div class="form-check">
                    <input class="form-check-input" type="checkbox" id="col_parent" checked>
                    <label class="form-check-label" for="col_parent"><?php echo htmlspecialchars($currentLevel['parent_label']); ?></label>
                </div>
                <?php endif; ?>
                <?php if ($activeLevel !== 'streets'): ?>
                <div class="form-check">
                    <input class="form-check-input" type="checkbox" id="col_children" checked>
                    <label class="form-check-label" for="col_children">العناصر الفرعية</label>
                </div>
                <?php endif; ?>
                <div class="form-check">
                    <input class="form-check-input" type="checkbox" id="col_order" checked>
                    <label class="form-check-label" for="col_order">الترتيب</label>
                </div>
                <div class="form-check">
                    <input class="form-check-input" type="checkbox" id="col_status" checked>
                    <label class="form-check-label" for="col_status">الحالة</label>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal"><i class="fas fa-check me-1"></i>إغلاق</button>
            </div>
        </div>
    </div>
</div>

<script src="../assets/js/admin_table_actions.js"></script>
<script>
// Cascading dropdown loader
function loadChildren(parentType, parentId, targetId) {
    var target = document.getElementById(targetId);
    if (!target) return;
    target.innerHTML = '<option value="">جاري التحميل...</option>';

    // Clear downstream selects
    var downstream = {
        'addCity': ['addCenter', 'addNeighborhood'],
        'addCenter': ['addNeighborhood'],
        'addNeighborhood': []
    };
    if (downstream[targetId]) {
        downstream[targetId].forEach(function(dId) {
            var d = document.getElementById(dId);
            if (d) d.innerHTML = '<option value="">-- اختر --</option>';
        });
    }

    if (!parentId) {
        target.innerHTML = '<option value="">-- اختر --</option>';
        return;
    }

    $.ajax({
        url: 'locations.php?ajax=get_children&parent_type=' + parentType + '&parent_id=' + parentId,
        method: 'GET',
        dataType: 'json',
        success: function(data) {
            var placeholder = {
                'addCity': 'المدينة',
                'addCenter': 'المركز',
                'addNeighborhood': 'المنطقة أو الحي'
            };
            var html = '<option value="">-- اختر ' + (placeholder[targetId] || '') + ' --</option>';
            if (data.success && data.items) {
                data.items.forEach(function(item) {
                    if (item.status === 'active') {
                        html += '<option value="' + item.id + '">' + escHtml(item.name) + '</option>';
                    }
                });
            }
            target.innerHTML = html;
        }
    });
}

function openEditModal(item) {
    document.getElementById('editId').value = item.id;
    document.getElementById('editName').value = item.name;
    document.getElementById('editOrder').value = item.display_order;
    document.getElementById('editStatus').value = item.status;
    new bootstrap.Modal(document.getElementById('editModal')).show();
}

function openDeleteModal(id, name) {
    document.getElementById('deleteId').value = id;
    document.getElementById('deleteName').textContent = name;
    new bootstrap.Modal(document.getElementById('deleteModal')).show();
}

function escHtml(s) {
    var d = document.createElement('div');
    d.textContent = s || '';
    return d.innerHTML;
}

$(document).ready(function() {
    if ($('#locationsTable').length && typeof $.fn.DataTable !== 'undefined') {
        var table = $('#locationsTable').DataTable({
            language: {
                search: "بحث:",
                lengthMenu: "عرض _MENU_ سجلات",
                info: "عرض _START_ إلى _END_ من أصل _TOTAL_ سجل",
                infoEmpty: "عرض 0 إلى 0 من أصل 0 سجل",
                infoFiltered: "(تصفية من إجمالي _MAX_ سجل)",
                zeroRecords: "لم يتم العثور على أي نتائج",
                paginate: {
                    first: "الأول",
                    previous: "السابق",
                    next: "التالي",
                    last: "الأخير"
                }
            },
            order: [[0, 'asc']],
            pageLength: 50,
            responsive: true
        });

        var statusColIdx = <?php echo $currentLevel['parent_label'] ? ($activeLevel !== 'streets' ? 5 : 4) : ($activeLevel !== 'streets' ? 4 : 3); ?>;

        $('#filterLocationStatus').on('change', function() {
            var val = $(this).val();
            table.column(statusColIdx).search(val ? '^' + val + '$' : '', true, false).draw();
        });

        $('#btnResetLocationFilters').on('click', function() {
            $('#filterLocationStatus').val('');
            table.column(statusColIdx).search('').draw();
        });
    }
    $('[data-bs-toggle="tooltip"]').tooltip();

    initializeTableColumnSettings('locationsTable', {
        col_name: 1,
        <?php if ($currentLevel['parent_label']): ?>col_parent: 2,<?php endif; ?>
        <?php if ($activeLevel !== 'streets'): ?>col_children: <?php echo $currentLevel['parent_label'] ? 3 : 2; ?>,<?php endif; ?>
        col_order: <?php echo $currentLevel['parent_label'] ? ($activeLevel !== 'streets' ? 4 : 3) : ($activeLevel !== 'streets' ? 3 : 2); ?>,
        col_status: <?php echo $currentLevel['parent_label'] ? ($activeLevel !== 'streets' ? 5 : 4) : ($activeLevel !== 'streets' ? 4 : 3); ?>
    }, 'locationsTableSettings_<?php echo $activeLevel; ?>');
});

function exportLocationsTableToCSV() {
    exportTableToCsv('locationsTable', 'locations_<?php echo $activeLevel; ?>_' + new Date().toISOString().slice(0,10) + '.csv');
}

function exportLocationsToPDF() {
    exportTableToPdf('locationsTable', 'المناطق الجغرافية - <?php echo htmlspecialchars($currentLevel['name']); ?>');
}
</script>

<?php require_once '../includes/admin_footer.php'; ?>
