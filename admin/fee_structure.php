<?php
/**
 * Fee Structure Management — المصاريف الدراسية
 * Manage tuition fees per grade, installments, sibling discounts, bus zone fees
 */
$page_title = "المصاريف الدراسية";
$custom_page_title = true;

require_once '../config/database.php';
require_once '../classes/utilities.php';
require_once '../classes/ActivityLog.php';
require_once '../includes/session_config.php';
Utilities::validateSession('admin');
require_once __DIR__ . '/../classes/FinanceLegacyAdapter.php';
FinanceLegacyAdapter::delegateRequestIfEnabled(__FILE__);

$database = new Database();
$db = $database->getConnection();

// PRG: retrieve session messages
$success_message = $_SESSION['success_message'] ?? null;
$error_message = $_SESSION['error_message'] ?? null;
unset($_SESSION['success_message'], $_SESSION['error_message']);

// Academic year
$current_year = date('Y');
$current_month = date('n');
if ($current_month >= 9) {
    $academic_year = $current_year . '-' . ($current_year + 1);
} else {
    $academic_year = ($current_year - 1) . '-' . $current_year;
}
$selected_year = $_GET['year'] ?? $academic_year;

// =============== AJAX ENDPOINTS (before any HTML) ===============
if (isset($_GET['ajax'])) {
    header('Content-Type: application/json; charset=utf-8');
    $ajax = $_GET['ajax'];

    if ($ajax === 'view_installments') {
        $fs_id = (int)($_GET['fs_id'] ?? 0);
        $stmt = $db->prepare("SELECT * FROM fee_installments WHERE fee_structure_id = ? ORDER BY display_order");
        $stmt->execute([$fs_id]);
        $installments = $stmt->fetchAll(PDO::FETCH_ASSOC);
        $total = array_sum(array_column($installments, 'amount'));
        echo json_encode(['success' => true, 'installments' => $installments, 'total' => $total]);
        exit();
    }

    if ($ajax === 'get_fee_structure') {
        $fs_id = (int)($_GET['fs_id'] ?? 0);
        $stmt = $db->prepare("SELECT * FROM fee_structure WHERE id = ?");
        $stmt->execute([$fs_id]);
        $fee = $stmt->fetch(PDO::FETCH_ASSOC);
        $instStmt = $db->prepare("SELECT * FROM fee_installments WHERE fee_structure_id = ? ORDER BY display_order");
        $instStmt->execute([$fs_id]);
        $installments = $instStmt->fetchAll(PDO::FETCH_ASSOC);
        echo json_encode(['success' => true, 'fee' => $fee, 'installments' => $installments]);
        exit();
    }

    echo json_encode(['success' => false, 'message' => 'Invalid action']);
    exit();
}

// Tab persistence
$activeTab = $_GET['tab'] ?? 'tuition';
$validTabs = ['tuition', 'discounts', 'other_discounts', 'bus_fees'];
if (!in_array($activeTab, $validTabs)) $activeTab = 'tuition';

// =============== POST HANDLERS ===============
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // CSRF check
    if (!isset($_POST['csrf_token']) || !hash_equals($_SESSION['csrf_token'], $_POST['csrf_token'])) {
        $_SESSION['error_message'] = "خطأ في التحقق من الأمان";
        header("Location: fee_structure.php?year=" . urlencode($selected_year) . "&tab=" . urlencode($activeTab));
        exit();
    }

    $action = $_POST['action'] ?? '';

    // --- Save Grade Fee Structure ---
    if ($action === 'save_fee_structure') {
        $activeTab = 'tuition';
        try {
            $grade_id = (int)$_POST['grade_id'];
            $year = $_POST['academic_year'] ?? $selected_year;
            $notes = trim($_POST['notes'] ?? '');
            $installment_names = $_POST['installment_name'] ?? [];
            $installment_amounts = $_POST['installment_amount'] ?? [];
            $installment_dates = $_POST['installment_due_date'] ?? [];

            if ($grade_id <= 0) throw new Exception("يجب اختيار الصف الدراسي");
            if (empty($installment_names)) throw new Exception("يجب إضافة قسط واحد على الأقل");

            // Calculate total
            $total = 0;
            foreach ($installment_amounts as $amt) {
                $total += (float)$amt;
            }

            $db->beginTransaction();

            // Upsert fee_structure
            $stmt = $db->prepare("SELECT id FROM fee_structure WHERE grade_id = ? AND academic_year = ?");
            $stmt->execute([$grade_id, $year]);
            $existing = $stmt->fetch(PDO::FETCH_ASSOC);

            if ($existing) {
                $fs_id = $existing['id'];
                $stmt = $db->prepare("UPDATE fee_structure SET total_amount = ?, notes = ?, status = 'active', updated_at = NOW() WHERE id = ?");
                $stmt->execute([$total, $notes, $fs_id]);
                // Delete old installments
                $db->prepare("DELETE FROM fee_installments WHERE fee_structure_id = ?")->execute([$fs_id]);
            } else {
                $stmt = $db->prepare("INSERT INTO fee_structure (grade_id, academic_year, total_amount, notes, created_by) VALUES (?, ?, ?, ?, ?)");
                $stmt->execute([$grade_id, $year, $total, $notes, $_SESSION['user_id']]);
                $fs_id = $db->lastInsertId();
            }

            // Insert installments
            $stmt = $db->prepare("INSERT INTO fee_installments (fee_structure_id, installment_name, amount, due_date, display_order) VALUES (?, ?, ?, ?, ?)");
            foreach ($installment_names as $i => $name) {
                $name = trim($name);
                $amount = (float)($installment_amounts[$i] ?? 0);
                $due = !empty($installment_dates[$i]) ? $installment_dates[$i] : null;
                if (!empty($name) && $amount > 0) {
                    $stmt->execute([$fs_id, $name, $amount, $due, $i + 1]);
                }
            }

            $db->commit();

            // Get grade name for log
            $gStmt = $db->prepare("SELECT grade_name FROM grades WHERE id = ?");
            $gStmt->execute([$grade_id]);
            $gName = $gStmt->fetchColumn() ?: $grade_id;

            ActivityLog::logUpdate('fee_structure', $fs_id, $gName, [
                'academic_year' => $year,
                'total_amount' => $total,
                'installments_count' => count($installment_names)
            ]);

            $_SESSION['success_message'] = "تم حفظ المصاريف الدراسية لـ " . htmlspecialchars($gName) . " بنجاح (الإجمالي: " . number_format($total, 2) . " جنيه)";
        } catch (Exception $e) {
            if ($db->inTransaction()) $db->rollBack();
            $_SESSION['error_message'] = "خطأ: " . $e->getMessage();
        }
        header("Location: fee_structure.php?year=" . urlencode($selected_year) . "&tab=tuition");
        exit();
    }

    // --- Copy Fee Structure between grades ---
    if ($action === 'copy_fee_structure') {
        $activeTab = 'tuition';
        try {
            $from_grade = (int)$_POST['from_grade_id'];
            $to_grade = (int)$_POST['to_grade_id'];
            $year = $_POST['academic_year'] ?? $selected_year;

            if ($from_grade <= 0 || $to_grade <= 0) throw new Exception("يجب اختيار الصف المصدر والصف الهدف");
            if ($from_grade === $to_grade) throw new Exception("لا يمكن النسخ لنفس الصف");

            // Get source
            $stmt = $db->prepare("SELECT * FROM fee_structure WHERE grade_id = ? AND academic_year = ?");
            $stmt->execute([$from_grade, $year]);
            $source = $stmt->fetch(PDO::FETCH_ASSOC);
            if (!$source) throw new Exception("لا توجد مصاريف محددة للصف المصدر");

            $db->beginTransaction();

            // Check if target exists
            $stmt = $db->prepare("SELECT id FROM fee_structure WHERE grade_id = ? AND academic_year = ?");
            $stmt->execute([$to_grade, $year]);
            $targetEx = $stmt->fetch(PDO::FETCH_ASSOC);

            if ($targetEx) {
                $target_id = $targetEx['id'];
                $stmt = $db->prepare("UPDATE fee_structure SET total_amount = ?, notes = ?, status = 'active', updated_at = NOW() WHERE id = ?");
                $stmt->execute([$source['total_amount'], $source['notes'], $target_id]);
                $db->prepare("DELETE FROM fee_installments WHERE fee_structure_id = ?")->execute([$target_id]);
            } else {
                $stmt = $db->prepare("INSERT INTO fee_structure (grade_id, academic_year, total_amount, notes, created_by) VALUES (?, ?, ?, ?, ?)");
                $stmt->execute([$to_grade, $year, $source['total_amount'], $source['notes'], $_SESSION['user_id']]);
                $target_id = $db->lastInsertId();
            }

            // Copy installments
            $instStmt = $db->prepare("SELECT * FROM fee_installments WHERE fee_structure_id = ? ORDER BY display_order");
            $instStmt->execute([$source['id']]);
            $inserts = $instStmt->fetchAll(PDO::FETCH_ASSOC);

            $insertStmt = $db->prepare("INSERT INTO fee_installments (fee_structure_id, installment_name, amount, due_date, display_order) VALUES (?, ?, ?, ?, ?)");
            foreach ($inserts as $inst) {
                $insertStmt->execute([$target_id, $inst['installment_name'], $inst['amount'], $inst['due_date'], $inst['display_order']]);
            }

            $db->commit();
            $_SESSION['success_message'] = "تم نسخ المصاريف بنجاح";
        } catch (Exception $e) {
            if ($db->inTransaction()) $db->rollBack();
            $_SESSION['error_message'] = "خطأ: " . $e->getMessage();
        }
        header("Location: fee_structure.php?year=" . urlencode($selected_year) . "&tab=tuition");
        exit();
    }

    // --- Delete Fee Structure ---
    if ($action === 'delete_fee_structure') {
        $activeTab = 'tuition';
        try {
            $fs_id = (int)$_POST['fee_structure_id'];
            $stmt = $db->prepare("SELECT fs.*, g.grade_name FROM fee_structure fs JOIN grades g ON fs.grade_id = g.id WHERE fs.id = ?");
            $stmt->execute([$fs_id]);
            $fs = $stmt->fetch(PDO::FETCH_ASSOC);
            if (!$fs) throw new Exception("لم يتم العثور على هيكل الرسوم");

            $db->prepare("DELETE FROM fee_structure WHERE id = ?")->execute([$fs_id]);
            ActivityLog::logDelete('fee_structure', $fs_id, $fs['grade_name']);
            $_SESSION['success_message'] = "تم حذف المصاريف الدراسية لـ " . htmlspecialchars($fs['grade_name']);
        } catch (Exception $e) {
            $_SESSION['error_message'] = "خطأ: " . $e->getMessage();
        }
        header("Location: fee_structure.php?year=" . urlencode($selected_year) . "&tab=tuition");
        exit();
    }

    // --- Save Sibling Discounts ---
    if ($action === 'save_sibling_discounts') {
        $activeTab = 'discounts';
        try {
            $year = $_POST['academic_year'] ?? $selected_year;
            $orders = $_POST['sibling_order'] ?? [];
            $percentages = $_POST['discount_percentage'] ?? [];

            $db->beginTransaction();
            $db->prepare("DELETE FROM sibling_discounts WHERE academic_year = ?")->execute([$year]);

            $stmt = $db->prepare("INSERT INTO sibling_discounts (academic_year, sibling_order, discount_percentage) VALUES (?, ?, ?)");
            foreach ($orders as $i => $order) {
                $order = (int)$order;
                $pct = (float)($percentages[$i] ?? 0);
                if ($order > 0) {
                    $stmt->execute([$year, $order, $pct]);
                }
            }
            $db->commit();

            ActivityLog::logUpdate('sibling_discounts', null, 'خصومات الإخوة', ['academic_year' => $year, 'count' => count($orders)]);
            $_SESSION['success_message'] = "تم حفظ نسب خصومات الإخوة بنجاح";
        } catch (Exception $e) {
            if ($db->inTransaction()) $db->rollBack();
            $_SESSION['error_message'] = "خطأ: " . $e->getMessage();
        }
        header("Location: fee_structure.php?year=" . urlencode($selected_year) . "&tab=discounts");
        exit();
    }

    // --- Save Bus Zone Fee ---
    if ($action === 'save_bus_zone') {
        $activeTab = 'bus_fees';
        try {
            $zone_id = (int)($_POST['zone_id'] ?? 0);
            $zone_name = trim($_POST['zone_name'] ?? '');
            $fee_amount = (float)($_POST['fee_amount'] ?? 0);
            $year = $_POST['academic_year'] ?? $selected_year;
            $notes = trim($_POST['zone_notes'] ?? '');

            if (empty($zone_name)) throw new Exception("يجب إدخال اسم المنطقة");
            if ($fee_amount <= 0) throw new Exception("يجب إدخال قيمة الاشتراك");

            if ($zone_id > 0) {
                $stmt = $db->prepare("UPDATE bus_fee_zones SET zone_name = ?, fee_amount = ?, notes = ?, updated_at = NOW() WHERE id = ? AND academic_year = ?");
                $stmt->execute([$zone_name, $fee_amount, $notes, $zone_id, $year]);
                ActivityLog::logUpdate('bus_fee_zone', $zone_id, $zone_name);
            } else {
                $stmt = $db->prepare("INSERT INTO bus_fee_zones (zone_name, academic_year, fee_amount, notes) VALUES (?, ?, ?, ?)");
                $stmt->execute([$zone_name, $year, $fee_amount, $notes]);
                ActivityLog::logCreate('bus_fee_zone', $db->lastInsertId(), $zone_name);
            }

            $_SESSION['success_message'] = "تم حفظ بيانات المنطقة بنجاح";
        } catch (Exception $e) {
            $_SESSION['error_message'] = "خطأ: " . $e->getMessage();
        }
        header("Location: fee_structure.php?year=" . urlencode($selected_year) . "&tab=bus_fees");
        exit();
    }

    // --- Save Other Discount ---
    if ($action === 'save_other_discount') {
        $activeTab = 'other_discounts';
        try {
            $od_id = (int)($_POST['od_id'] ?? 0);
            $od_name = trim($_POST['od_name'] ?? '');
            $discount_type = $_POST['discount_type'] ?? 'amount';
            $discount_value = (float)($_POST['discount_value'] ?? 0);
            $od_year = $_POST['academic_year'] ?? $selected_year;

            if (empty($od_name)) throw new Exception("يجب إدخال اسم الخصم");
            if ($discount_value <= 0) throw new Exception("يجب إدخال قيمة الخصم");
            if (!in_array($discount_type, ['amount', 'percentage'])) $discount_type = 'amount';
            if ($discount_type === 'percentage' && $discount_value > 100) throw new Exception("النسبة لا يمكن أن تتجاوز 100%");

            if ($od_id > 0) {
                $stmt = $db->prepare("UPDATE other_discounts SET name = ?, discount_type = ?, discount_value = ?, updated_at = NOW() WHERE id = ?");
                $stmt->execute([$od_name, $discount_type, $discount_value, $od_id]);
                ActivityLog::logUpdate('other_discount', $od_id, $od_name);
            } else {
                $stmt = $db->prepare("INSERT INTO other_discounts (name, discount_type, discount_value, academic_year) VALUES (?, ?, ?, ?)");
                $stmt->execute([$od_name, $discount_type, $discount_value, $od_year]);
                ActivityLog::logCreate('other_discount', $db->lastInsertId(), $od_name);
            }

            $_SESSION['success_message'] = "تم حفظ الخصم بنجاح";
        } catch (Exception $e) {
            $_SESSION['error_message'] = "خطأ: " . $e->getMessage();
        }
        header("Location: fee_structure.php?year=" . urlencode($selected_year) . "&tab=other_discounts");
        exit();
    }

    // --- Delete Other Discount ---
    if ($action === 'delete_other_discount') {
        $activeTab = 'other_discounts';
        try {
            $od_id = (int)$_POST['od_id'];
            $stmt = $db->prepare("SELECT name FROM other_discounts WHERE id = ?");
            $stmt->execute([$od_id]);
            $odName = $stmt->fetchColumn();
            // تحقق من عدم استخدامه
            $stmt = $db->prepare("SELECT COUNT(*) FROM student_other_discounts WHERE other_discount_id = ?");
            $stmt->execute([$od_id]);
            if ((int)$stmt->fetchColumn() > 0) {
                throw new Exception("لا يمكن الحذف — هذا الخصم معيّن لطلاب");
            }
            $db->prepare("DELETE FROM other_discounts WHERE id = ?")->execute([$od_id]);
            ActivityLog::logDelete('other_discount', $od_id, $odName ?: $od_id);
            $_SESSION['success_message'] = "تم حذف الخصم بنجاح";
        } catch (Exception $e) {
            $_SESSION['error_message'] = "خطأ: " . $e->getMessage();
        }
        header("Location: fee_structure.php?year=" . urlencode($selected_year) . "&tab=other_discounts");
        exit();
    }

    // --- Toggle Other Discount ---
    if ($action === 'toggle_other_discount') {
        $od_id = (int)($_POST['od_id'] ?? 0);
        $new_status = $_POST['new_status'] ?? 'active';
        $db->prepare("UPDATE other_discounts SET status = ?, updated_at = NOW() WHERE id = ?")->execute([$new_status, $od_id]);
        $_SESSION['success_message'] = "تم تحديث حالة الخصم";
        header("Location: fee_structure.php?year=" . urlencode($selected_year) . "&tab=other_discounts");
        exit();
    }

    // --- Delete Bus Zone Fee ---
    if ($action === 'delete_bus_zone') {
        $activeTab = 'bus_fees';
        try {
            $zone_id = (int)$_POST['zone_id'];
            $stmt = $db->prepare("SELECT zone_name FROM bus_fee_zones WHERE id = ?");
            $stmt->execute([$zone_id]);
            $zn = $stmt->fetchColumn();
            $db->prepare("DELETE FROM bus_fee_zones WHERE id = ?")->execute([$zone_id]);
            ActivityLog::logDelete('bus_fee_zone', $zone_id, $zn ?: $zone_id);
            $_SESSION['success_message'] = "تم حذف المنطقة بنجاح";
        } catch (Exception $e) {
            $_SESSION['error_message'] = "خطأ: " . $e->getMessage();
        }
        header("Location: fee_structure.php?year=" . urlencode($selected_year) . "&tab=bus_fees");
        exit();
    }
}

// =============== FETCH DATA ===============
// Grades with stages
$grades = $db->query("SELECT g.id, g.grade_name, s.stage_name FROM grades g LEFT JOIN stages s ON g.stage_id = s.id WHERE g.status = 'active' ORDER BY g.grade_order")->fetchAll(PDO::FETCH_ASSOC);

// Fee structures for selected year
$feeStructures = [];
$stmt = $db->prepare("SELECT fs.*, g.grade_name, s.stage_name,
    (SELECT COUNT(*) FROM fee_installments fi WHERE fi.fee_structure_id = fs.id) as installment_count
    FROM fee_structure fs
    JOIN grades g ON fs.grade_id = g.id
    LEFT JOIN stages s ON g.stage_id = s.id
    WHERE fs.academic_year = ?
    ORDER BY g.grade_order");
$stmt->execute([$selected_year]);
$feeStructures = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Sibling discounts
$siblingDiscounts = [];
$stmt = $db->prepare("SELECT * FROM sibling_discounts WHERE academic_year = ? ORDER BY sibling_order");
$stmt->execute([$selected_year]);
$siblingDiscounts = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Bus zone fees
$busZones = [];
$stmt = $db->prepare("SELECT * FROM bus_fee_zones WHERE academic_year = ? ORDER BY zone_name");
$stmt->execute([$selected_year]);
$busZones = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Other discounts
$otherDiscounts = [];
$stmt = $db->prepare("SELECT od.*, (SELECT COUNT(*) FROM student_other_discounts sod WHERE sod.other_discount_id = od.id) as usage_count FROM other_discounts od WHERE od.academic_year = ? ORDER BY od.name");
$stmt->execute([$selected_year]);
$otherDiscounts = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Grades that don't have fee structure yet
$configuredGradeIds = array_column($feeStructures, 'grade_id');
$unconfiguredGrades = array_filter($grades, function($g) use ($configuredGradeIds) {
    return !in_array($g['id'], $configuredGradeIds);
});

include_once '../includes/admin_header.php';
echo FinanceLegacyAdapter::bridgeNotice(__FILE__);
?>

<!-- Page Header -->
<div class="admin-page-heading mb-4">
    <div class="admin-page-title">
        <h1 class="h3 mb-0 text-gray-800">
            <i class="fas fa-file-invoice-dollar me-2 text-primary"></i>المصاريف الدراسية
        </h1>
    </div>
    <div class="admin-top-actions">
        <span class="badge bg-light border text-dark fs-6 px-3 py-2 rounded-pill shadow-sm">
            <i class="fas fa-calendar me-1 text-primary"></i>العام الدراسي: <?php echo htmlspecialchars($selected_year); ?>
        </span>
        <?php if (count($feeStructures) > 0): ?>
        <button type="button" class="btn btn-outline-primary shadow-sm" data-bs-toggle="modal" data-bs-target="#copyFeeModal">
            <i class="fas fa-copy me-2"></i>نسخ بين الصفوف
        </button>
        <?php endif; ?>
        <button type="button" class="btn btn-header-premium btn-success shadow-sm" data-bs-toggle="modal" data-bs-target="#feeStructureModal" onclick="resetFeeForm()">
            <i class="fas fa-plus-circle me-2"></i>إضافة مصاريف لصف جديد
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
<div class="row row-cols-2 row-cols-md-4 g-3 mb-4">
    <div class="col">
        <div class="stat-card" style="--card-gradient: linear-gradient(135deg, #3b82f6, #2563eb);">
            <div class="stat-card-icon"><i class="fas fa-file-invoice-dollar"></i></div>
            <div class="stat-card-info">
                <div class="stat-card-number counter" data-target="<?php echo count($feeStructures); ?>">0</div>
                <div class="stat-card-label">صفوف مُعدة</div>
                <div class="stat-card-sub"><i class="fas fa-layer-group me-1"></i>من <?php echo count($grades); ?> صف</div>
            </div>
        </div>
    </div>
    <div class="col">
        <div class="stat-card" style="--card-gradient: linear-gradient(135deg, #10b981, #059669);">
            <div class="stat-card-icon"><i class="fas fa-users"></i></div>
            <div class="stat-card-info">
                <div class="stat-card-number counter" data-target="<?php echo count($siblingDiscounts); ?>">0</div>
                <div class="stat-card-label">مستويات خصم إخوة</div>
            </div>
        </div>
    </div>
    <div class="col">
        <div class="stat-card" style="--card-gradient: linear-gradient(135deg, #f59e0b, #d97706);">
            <div class="stat-card-icon"><i class="fas fa-bus"></i></div>
            <div class="stat-card-info">
                <div class="stat-card-number counter" data-target="<?php echo count($busZones); ?>">0</div>
                <div class="stat-card-label">مناطق حافلات</div>
            </div>
        </div>
    </div>
    <div class="col">
        <div class="stat-card" style="--card-gradient: linear-gradient(135deg, #8b5cf6, #7c3aed);">
            <div class="stat-card-icon"><i class="fas fa-exclamation-triangle"></i></div>
            <div class="stat-card-info">
                <div class="stat-card-number counter" data-target="<?php echo count($unconfiguredGrades); ?>">0</div>
                <div class="stat-card-label">صفوف بدون مصاريف</div>
            </div>
        </div>
    </div>
</div>

<!-- Tabs -->
<ul class="nav nav-tabs mb-3" id="feeTabs">
    <li class="nav-item">
        <a class="nav-link <?php echo $activeTab === 'tuition' ? 'active' : ''; ?>" href="#pane-tuition" data-bs-toggle="tab">
            <i class="fas fa-money-bill-wave me-1"></i>الأقساط الدراسية <span class="badge rounded-pill bg-primary ms-1"><?php echo count($feeStructures); ?></span>
        </a>
    </li>
    <li class="nav-item">
        <a class="nav-link <?php echo $activeTab === 'discounts' ? 'active' : ''; ?>" href="#pane-discounts" data-bs-toggle="tab">
            <i class="fas fa-percent me-1"></i>خصومات الإخوة
        </a>
    </li>
    <li class="nav-item">
        <a class="nav-link <?php echo $activeTab === 'other_discounts' ? 'active' : ''; ?>" href="#pane-other_discounts" data-bs-toggle="tab">
            <i class="fas fa-tags me-1"></i>الخصومات الأخرى <span class="badge rounded-pill bg-primary ms-1"><?php echo count($otherDiscounts); ?></span>
        </a>
    </li>
    <li class="nav-item">
        <a class="nav-link <?php echo $activeTab === 'bus_fees' ? 'active' : ''; ?>" href="#pane-bus_fees" data-bs-toggle="tab">
            <i class="fas fa-bus me-1"></i>مصروفات الحافلات <span class="badge rounded-pill bg-primary ms-1"><?php echo count($busZones); ?></span>
        </a>
    </li>
</ul>

<div class="tab-content">
    <!-- ====== TAB 1: Tuition Fees ====== -->
    <div class="tab-pane fade <?php echo $activeTab === 'tuition' ? 'show active' : ''; ?>" id="pane-tuition">

        <!-- Fee Structures Table -->
        <div class="admin-list-surface">
            <div class="admin-table-wrap">
                <?php if (empty($feeStructures)): ?>
                    <div class="text-center py-5 text-muted">
                        <i class="fas fa-file-invoice-dollar fa-3x mb-3 opacity-50"></i>
                        <p>لم يتم تحديد مصاريف دراسية بعد للعام <?php echo htmlspecialchars($selected_year); ?></p>
                    </div>
                <?php else: ?>
                <table class="table table-hover table-striped datatable admin-data-table" id="feeTable">
                    <thead>
                        <tr>
                            <th>#</th>
                            <th>المرحلة</th>
                            <th>الصف الدراسي</th>
                            <th>عدد الأقساط</th>
                            <th>إجمالي المصاريف</th>
                            <th>ملاحظات</th>
                            <th class="text-center actions-column admin-table-actions">إجراءات</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($feeStructures as $idx => $fs): ?>
                        <tr>
                            <td><?php echo $idx + 1; ?></td>
                            <td><?php echo htmlspecialchars($fs['stage_name'] ?? '-'); ?></td>
                            <td><strong><?php echo htmlspecialchars($fs['grade_name']); ?></strong></td>
                            <td><span class="badge bg-info"><?php echo $fs['installment_count']; ?></span></td>
                            <td><strong class="text-success"><?php echo number_format($fs['total_amount'], 2); ?> جنيه</strong></td>
                            <td><?php echo htmlspecialchars($fs['notes'] ?? '-'); ?></td>
                            <td class="text-center actions-column admin-table-actions">
                                <button type="button" class="btn btn-action-pills btn-edit me-1" data-bs-toggle="tooltip" title="عرض التفاصيل" onclick="viewInstallments(<?php echo $fs['id']; ?>)">
                                    <i class="fas fa-eye"></i>
                                </button>
                                <button type="button" class="btn btn-action-pills btn-edit me-1" data-bs-toggle="tooltip" title="تعديل" onclick="editFeeStructure(<?php echo $fs['id']; ?>)">
                                    <i class="fas fa-edit"></i>
                                </button>
                                <button type="button" class="btn btn-action-pills btn-delete" data-bs-toggle="tooltip" title="حذف" onclick="confirmDeleteFS(<?php echo $fs['id']; ?>, '<?php echo htmlspecialchars($fs['grade_name'], ENT_QUOTES); ?>')">
                                    <i class="fas fa-trash"></i>
                                </button>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
                <?php endif; ?>
            </div>
        </div>
    </div>

    <!-- ====== TAB 2: Sibling Discounts ====== -->
    <div class="tab-pane fade <?php echo $activeTab === 'discounts' ? 'show active' : ''; ?>" id="pane-discounts">
        <div class="card shadow">
            <div class="card-header bg-primary text-white">
                <h5 class="mb-0"><i class="fas fa-percent me-2"></i>نسب خصومات الإخوة — <?php echo htmlspecialchars($selected_year); ?></h5>
            </div>
            <div class="card-body">
                <p class="text-muted mb-3"><i class="fas fa-info-circle me-1"></i>حدد نسبة الخصم لكل ترتيب أخ. الأخ الأول عادة بدون خصم (0%).</p>
                <form method="POST" action="fee_structure.php?year=<?php echo urlencode($selected_year); ?>&tab=discounts">
                    <input type="hidden" name="csrf_token" value="<?php echo $_SESSION['csrf_token']; ?>">
                    <input type="hidden" name="action" value="save_sibling_discounts">
                    <input type="hidden" name="academic_year" value="<?php echo htmlspecialchars($selected_year); ?>">

                    <div id="siblingDiscountRows">
                        <?php
                        $discountRows = !empty($siblingDiscounts) ? $siblingDiscounts : [
                            ['sibling_order' => 1, 'discount_percentage' => 0],
                            ['sibling_order' => 2, 'discount_percentage' => 10],
                            ['sibling_order' => 3, 'discount_percentage' => 15],
                            ['sibling_order' => 4, 'discount_percentage' => 20],
                            ['sibling_order' => 5, 'discount_percentage' => 25]
                        ];
                        foreach ($discountRows as $i => $d):
                        ?>
                        <div class="row mb-2 align-items-center sibling-row">
                            <div class="col-md-4">
                                <div class="input-group">
                                    <span class="input-group-text">الأخ رقم</span>
                                    <input type="number" name="sibling_order[]" class="form-control" value="<?php echo (int)$d['sibling_order']; ?>" min="1" readonly>
                                </div>
                            </div>
                            <div class="col-md-4">
                                <div class="input-group">
                                    <input type="number" name="discount_percentage[]" class="form-control" value="<?php echo (float)$d['discount_percentage']; ?>" min="0" max="100" step="0.5">
                                    <span class="input-group-text">%</span>
                                </div>
                            </div>
                            <div class="col-md-2">
                                <?php if ($i > 0): ?>
                                <button type="button" class="btn btn-outline-danger btn-sm" onclick="this.closest('.sibling-row').remove()">
                                    <i class="fas fa-times"></i>
                                </button>
                                <?php endif; ?>
                            </div>
                        </div>
                        <?php endforeach; ?>
                    </div>

                    <div class="mt-3">
                        <button type="button" class="btn btn-outline-success btn-sm" onclick="addSiblingRow()">
                            <i class="fas fa-plus me-1"></i>إضافة مستوى
                        </button>
                    </div>

                    <hr>
                    <button type="submit" class="btn btn-primary">
                        <i class="fas fa-save me-1"></i>حفظ الخصومات
                    </button>
                </form>
            </div>
        </div>
    </div>

    <!-- ====== TAB 3: Bus Zone Fees ====== -->
    <!-- ====== TAB 3: Other Discounts ====== -->
    <div class="tab-pane fade <?php echo $activeTab === 'other_discounts' ? 'show active' : ''; ?>" id="pane-other_discounts">
        <div class="mb-3">
            <button type="button" class="btn btn-outline-success" data-bs-toggle="modal" data-bs-target="#otherDiscountModal" onclick="resetOtherDiscountForm()">
                <i class="fas fa-plus me-1"></i>إضافة خصم جديد
            </button>
        </div>

        <div class="admin-list-surface">
            <div class="admin-table-wrap">
                <?php if (empty($otherDiscounts)): ?>
                    <div class="text-center py-5 text-muted">
                        <i class="fas fa-tags fa-3x mb-3 opacity-50"></i>
                        <p>لم يتم تحديد خصومات أخرى بعد</p>
                    </div>
                <?php else: ?>
                <table class="table table-hover table-striped datatable admin-data-table">
                    <thead>
                        <tr>
                            <th>#</th>
                            <th>اسم الخصم</th>
                            <th>النوع</th>
                            <th>القيمة</th>
                            <th>عدد الطلاب</th>
                            <th>الحالة</th>
                            <th class="text-center actions-column admin-table-actions">إجراءات</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($otherDiscounts as $idx => $od): ?>
                        <tr>
                            <td><?php echo $idx + 1; ?></td>
                            <td><strong><?php echo htmlspecialchars($od['name']); ?></strong></td>
                            <td>
                                <?php if ($od['discount_type'] === 'percentage'): ?>
                                    <span class="badge bg-info">نسبة مئوية</span>
                                <?php else: ?>
                                    <span class="badge bg-success">مبلغ ثابت</span>
                                <?php endif; ?>
                            </td>
                            <td>
                                <strong class="text-primary">
                                    <?php echo number_format($od['discount_value'], 2); ?>
                                    <?php echo $od['discount_type'] === 'percentage' ? '%' : ' جنيه'; ?>
                                </strong>
                            </td>
                            <td><span class="badge bg-secondary"><?php echo $od['usage_count']; ?></span></td>
                            <td>
                                <?php if ($od['status'] === 'active'): ?>
                                    <span class="badge bg-success">فعّال</span>
                                <?php else: ?>
                                    <span class="badge bg-secondary">معطّل</span>
                                <?php endif; ?>
                            </td>
                            <td class="text-center actions-column admin-table-actions">
                                <button type="button" class="btn btn-action-pills btn-edit me-1" data-bs-toggle="tooltip" title="تعديل" onclick="editOtherDiscount(<?php echo htmlspecialchars(json_encode($od)); ?>)">
                                    <i class="fas fa-edit"></i>
                                </button>
                                <?php if ($od['status'] === 'active'): ?>
                                <form method="POST" class="d-inline">
                                    <input type="hidden" name="csrf_token" value="<?php echo $_SESSION['csrf_token']; ?>">
                                    <input type="hidden" name="action" value="toggle_other_discount">
                                    <input type="hidden" name="od_id" value="<?php echo $od['id']; ?>">
                                    <input type="hidden" name="new_status" value="inactive">
                                    <button type="submit" class="btn btn-action-pills btn-deactivate me-1" data-bs-toggle="tooltip" title="تعطيل"><i class="fas fa-ban"></i></button>
                                </form>
                                <?php else: ?>
                                <form method="POST" class="d-inline">
                                    <input type="hidden" name="csrf_token" value="<?php echo $_SESSION['csrf_token']; ?>">
                                    <input type="hidden" name="action" value="toggle_other_discount">
                                    <input type="hidden" name="od_id" value="<?php echo $od['id']; ?>">
                                    <input type="hidden" name="new_status" value="active">
                                    <button type="submit" class="btn btn-action-pills btn-activate me-1" data-bs-toggle="tooltip" title="تفعيل"><i class="fas fa-check"></i></button>
                                </form>
                                <?php endif; ?>
                                <button type="button" class="btn btn-action-pills btn-delete" data-bs-toggle="tooltip" title="حذف" onclick="confirmDeleteOtherDiscount(<?php echo $od['id']; ?>, '<?php echo htmlspecialchars($od['name'], ENT_QUOTES); ?>')">
                                    <i class="fas fa-trash"></i>
                                </button>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
                <?php endif; ?>
            </div>
        </div>
    </div>

    <!-- ====== TAB 4: Bus Fees ====== -->
    <div class="tab-pane fade <?php echo $activeTab === 'bus_fees' ? 'show active' : ''; ?>" id="pane-bus_fees">
        <div class="mb-3">
            <button type="button" class="btn btn-outline-success" data-bs-toggle="modal" data-bs-target="#busZoneModal" onclick="resetBusZoneForm()">
                <i class="fas fa-plus me-1"></i>إضافة منطقة جديدة
            </button>
        </div>

        <div class="admin-list-surface">
            <div class="admin-table-wrap">
                <?php if (empty($busZones)): ?>
                    <div class="text-center py-5 text-muted">
                        <i class="fas fa-bus fa-3x mb-3 opacity-50"></i>
                        <p>لم يتم تحديد مناطق حافلات بعد</p>
                    </div>
                <?php else: ?>
                <table class="table table-hover table-striped datatable admin-data-table" id="busZoneTable">
                    <thead>
                        <tr>
                            <th>#</th>
                            <th>اسم المنطقة</th>
                            <th>قيمة الاشتراك</th>
                            <th>ملاحظات</th>
                            <th class="text-center actions-column admin-table-actions">إجراءات</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($busZones as $idx => $zone): ?>
                        <tr>
                            <td><?php echo $idx + 1; ?></td>
                            <td><strong><?php echo htmlspecialchars($zone['zone_name']); ?></strong></td>
                            <td><strong class="text-success"><?php echo number_format($zone['fee_amount'], 2); ?> جنيه</strong></td>
                            <td><?php echo htmlspecialchars($zone['notes'] ?? '-'); ?></td>
                            <td class="text-center actions-column admin-table-actions">
                                <button type="button" class="btn btn-action-pills btn-edit me-1" data-bs-toggle="tooltip" title="تعديل" onclick="editBusZone(<?php echo htmlspecialchars(json_encode($zone)); ?>)">
                                    <i class="fas fa-edit"></i>
                                </button>
                                <button type="button" class="btn btn-action-pills btn-delete" data-bs-toggle="tooltip" title="حذف" onclick="confirmDeleteZone(<?php echo $zone['id']; ?>, '<?php echo htmlspecialchars($zone['zone_name'], ENT_QUOTES); ?>')">
                                    <i class="fas fa-trash"></i>
                                </button>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
                <?php endif; ?>
            </div>
        </div>
    </div>
</div>

<!-- ====== MODALS ====== -->

<!-- Fee Structure Modal (Add/Edit) -->
<div class="modal fade" id="feeStructureModal" tabindex="-1">
    <div class="modal-dialog modal-lg">
        <div class="modal-content admin-modal admin-modal-premium admin-modal-view">
            <form method="POST" action="fee_structure.php?year=<?php echo urlencode($selected_year); ?>&tab=tuition" id="feeStructureForm">
                <input type="hidden" name="csrf_token" value="<?php echo $_SESSION['csrf_token']; ?>">
                <input type="hidden" name="action" value="save_fee_structure">
                <input type="hidden" name="academic_year" value="<?php echo htmlspecialchars($selected_year); ?>">

                <div class="modal-header">
                    <h5 class="modal-title" id="feeModalTitle"><i class="fas fa-plus-circle me-2"></i>إضافة مصاريف دراسية</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <div class="mb-3">
                        <label class="form-label fw-bold">الصف الدراسي <span class="text-danger">*</span></label>
                        <select name="grade_id" id="feeGradeId" class="form-select" required>
                            <option value="">-- اختر الصف --</option>
                            <?php foreach ($grades as $g): ?>
                            <option value="<?php echo $g['id']; ?>"><?php echo htmlspecialchars($g['stage_name'] . ' - ' . $g['grade_name']); ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <div class="mb-3">
                        <label class="form-label fw-bold">الأقساط</label>
                        <div id="installmentRows">
                            <!-- Dynamic rows added by JS -->
                        </div>
                        <button type="button" class="btn btn-outline-success btn-sm mt-2" onclick="addInstallmentRow()">
                            <i class="fas fa-plus me-1"></i>إضافة قسط
                        </button>
                    </div>

                    <div class="alert alert-info mb-3">
                        <i class="fas fa-calculator me-2"></i>
                        الإجمالي: <strong id="totalAmount">0.00</strong> جنيه
                    </div>

                    <div class="mb-3">
                        <label class="form-label">ملاحظات</label>
                        <textarea name="notes" id="feeNotes" class="form-control" rows="2"></textarea>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal"><i class="fas fa-times me-1"></i>إلغاء</button>
                    <button type="submit" class="btn btn-primary" id="feeSubmitBtn"><i class="fas fa-save me-1"></i>حفظ</button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- Copy Fee Modal -->
<div class="modal fade" id="copyFeeModal" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content admin-modal admin-modal-premium admin-modal-create">
            <form method="POST" action="fee_structure.php?year=<?php echo urlencode($selected_year); ?>&tab=tuition">
                <input type="hidden" name="csrf_token" value="<?php echo $_SESSION['csrf_token']; ?>">
                <input type="hidden" name="action" value="copy_fee_structure">
                <input type="hidden" name="academic_year" value="<?php echo htmlspecialchars($selected_year); ?>">

                <div class="modal-header">
                    <h5 class="modal-title"><i class="fas fa-copy me-2"></i>نسخ المصاريف بين الصفوف</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <div class="mb-3">
                        <label class="form-label fw-bold">نسخ من الصف</label>
                        <select name="from_grade_id" class="form-select" required>
                            <option value="">-- اختر --</option>
                            <?php foreach ($feeStructures as $fs): ?>
                            <option value="<?php echo $fs['grade_id']; ?>"><?php echo htmlspecialchars($fs['grade_name']); ?> (<?php echo number_format($fs['total_amount'], 2); ?> جنيه)</option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="mb-3">
                        <label class="form-label fw-bold">نسخ إلى الصف</label>
                        <select name="to_grade_id" class="form-select" required>
                            <option value="">-- اختر --</option>
                            <?php foreach ($grades as $g): ?>
                            <option value="<?php echo $g['id']; ?>"><?php echo htmlspecialchars($g['stage_name'] . ' - ' . $g['grade_name']); ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="alert alert-warning">
                        <i class="fas fa-exclamation-triangle me-2"></i>
                        سيتم استبدال المصاريف الحالية للصف الهدف إن وجدت.
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal"><i class="fas fa-times me-1"></i>إلغاء</button>
                    <button type="submit" class="btn btn-outline-primary"><i class="fas fa-copy me-1"></i>نسخ</button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- View Installments Modal -->
<div class="modal fade" id="viewInstallmentsModal" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content admin-modal admin-modal-premium admin-modal-view">
            <div class="modal-header">
                <h5 class="modal-title"><i class="fas fa-eye me-2"></i>تفاصيل الأقساط</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body" id="installmentsViewBody">
                <div class="text-center"><i class="fas fa-spinner fa-spin fa-2x"></i></div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal"><i class="fas fa-times me-1"></i>إغلاق</button>
            </div>
        </div>
    </div>
</div>

<!-- Delete Fee Structure Confirmation Modal -->
<div class="modal fade" id="deleteFSModal" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content admin-modal admin-modal-premium admin-modal-delete">
            <form method="POST" action="fee_structure.php?year=<?php echo urlencode($selected_year); ?>&tab=tuition">
                <input type="hidden" name="csrf_token" value="<?php echo $_SESSION['csrf_token']; ?>">
                <input type="hidden" name="action" value="delete_fee_structure">
                <input type="hidden" name="fee_structure_id" id="deleteFSId">
                <div class="modal-header">
                    <h5 class="modal-title"><i class="fas fa-trash me-2"></i>حذف المصاريف</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <div class="text-center mb-3"><i class="fas fa-exclamation-triangle text-danger" style="font-size: 3rem;"></i></div>
                    <p class="text-center">هل تريد حذف مصاريف <span class="fw-bold text-primary" id="deleteFSName"></span>؟</p>
                    <div class="alert alert-danger"><i class="fas fa-info-circle me-2"></i>سيتم حذف جميع الأقساط المرتبطة بهذا الصف.</div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal"><i class="fas fa-times me-1"></i>إلغاء</button>
                    <button type="submit" class="btn btn-danger"><i class="fas fa-trash me-1"></i>تأكيد الحذف</button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- Bus Zone Modal (Add/Edit) -->
<div class="modal fade" id="busZoneModal" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content admin-modal admin-modal-premium admin-modal-view">
            <form method="POST" action="fee_structure.php?year=<?php echo urlencode($selected_year); ?>&tab=bus_fees">
                <input type="hidden" name="csrf_token" value="<?php echo $_SESSION['csrf_token']; ?>">
                <input type="hidden" name="action" value="save_bus_zone">
                <input type="hidden" name="zone_id" id="busZoneId" value="0">
                <input type="hidden" name="academic_year" value="<?php echo htmlspecialchars($selected_year); ?>">

                <div class="modal-header">
                    <h5 class="modal-title" id="busZoneModalTitle"><i class="fas fa-plus-circle me-2"></i>إضافة منطقة</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <div class="mb-3">
                        <label class="form-label fw-bold">اسم المنطقة <span class="text-danger">*</span></label>
                        <input type="text" name="zone_name" id="busZoneName" class="form-control" required>
                    </div>
                    <div class="mb-3">
                        <label class="form-label fw-bold">قيمة الاشتراك (جنيه) <span class="text-danger">*</span></label>
                        <input type="number" name="fee_amount" id="busZoneFee" class="form-control" min="0" step="0.01" required>
                    </div>
                    <div class="mb-3">
                        <label class="form-label">ملاحظات</label>
                        <textarea name="zone_notes" id="busZoneNotes" class="form-control" rows="2"></textarea>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal"><i class="fas fa-times me-1"></i>إلغاء</button>
                    <button type="submit" class="btn btn-success" id="busZoneSubmitBtn"><i class="fas fa-save me-1"></i>حفظ</button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- Delete Bus Zone Confirmation Modal -->
<div class="modal fade" id="deleteZoneModal" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content admin-modal admin-modal-premium admin-modal-delete">
            <form method="POST" action="fee_structure.php?year=<?php echo urlencode($selected_year); ?>&tab=bus_fees">
                <input type="hidden" name="csrf_token" value="<?php echo $_SESSION['csrf_token']; ?>">
                <input type="hidden" name="action" value="delete_bus_zone">
                <input type="hidden" name="zone_id" id="deleteZoneId">
                <div class="modal-header">
                    <h5 class="modal-title"><i class="fas fa-trash me-2"></i>حذف المنطقة</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <div class="text-center mb-3"><i class="fas fa-exclamation-triangle text-danger" style="font-size: 3rem;"></i></div>
                    <p class="text-center">هل تريد حذف منطقة <span class="fw-bold text-primary" id="deleteZoneName"></span>؟</p>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal"><i class="fas fa-times me-1"></i>إلغاء</button>
                    <button type="submit" class="btn btn-danger"><i class="fas fa-trash me-1"></i>تأكيد الحذف</button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- Other Discount Modal (Add/Edit) -->
<div class="modal fade" id="otherDiscountModal" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content admin-modal admin-modal-premium admin-modal-view">
            <form method="POST" action="fee_structure.php?year=<?php echo urlencode($selected_year); ?>&tab=other_discounts">
                <input type="hidden" name="csrf_token" value="<?php echo $_SESSION['csrf_token']; ?>">
                <input type="hidden" name="action" value="save_other_discount">
                <input type="hidden" name="od_id" id="odId" value="0">
                <input type="hidden" name="academic_year" value="<?php echo htmlspecialchars($selected_year); ?>">

                <div class="modal-header">
                    <h5 class="modal-title" id="odModalTitle"><i class="fas fa-plus-circle me-2"></i>إضافة خصم</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <div class="mb-3">
                        <label class="form-label fw-bold">اسم الخصم <span class="text-danger">*</span></label>
                        <input type="text" name="od_name" id="odName" class="form-control" required placeholder="مثال: خصم أبناء العاملين">
                    </div>
                    <div class="mb-3">
                        <label class="form-label fw-bold">نوع الخصم <span class="text-danger">*</span></label>
                        <select name="discount_type" id="odType" class="form-select" required>
                            <option value="amount">مبلغ ثابت (جنيه)</option>
                            <option value="percentage">نسبة مئوية (%)</option>
                        </select>
                    </div>
                    <div class="mb-3">
                        <label class="form-label fw-bold">القيمة <span class="text-danger">*</span></label>
                        <div class="input-group">
                            <input type="number" name="discount_value" id="odValue" class="form-control" min="0.01" step="0.01" required>
                            <span class="input-group-text" id="odValueUnit">جنيه</span>
                        </div>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal"><i class="fas fa-times me-1"></i>إلغاء</button>
                    <button type="submit" class="btn btn-success" id="odSubmitBtn"><i class="fas fa-save me-1"></i>حفظ</button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- Delete Other Discount Modal -->
<div class="modal fade" id="deleteOtherDiscountModal" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content admin-modal admin-modal-premium admin-modal-delete">
            <form method="POST" action="fee_structure.php?year=<?php echo urlencode($selected_year); ?>&tab=other_discounts">
                <input type="hidden" name="csrf_token" value="<?php echo $_SESSION['csrf_token']; ?>">
                <input type="hidden" name="action" value="delete_other_discount">
                <input type="hidden" name="od_id" id="deleteOdId">
                <div class="modal-header">
                    <h5 class="modal-title"><i class="fas fa-trash me-2"></i>حذف الخصم</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <div class="text-center mb-3"><i class="fas fa-exclamation-triangle text-danger" style="font-size: 3rem;"></i></div>
                    <p class="text-center">هل تريد حذف الخصم <span class="fw-bold text-primary" id="deleteOdName"></span>؟</p>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal"><i class="fas fa-times me-1"></i>إلغاء</button>
                    <button type="submit" class="btn btn-danger"><i class="fas fa-trash me-1"></i>تأكيد الحذف</button>
                </div>
            </form>
        </div>
    </div>
</div>

<style>
/* Stat Cards */
</style>

<script>
// ========== Installment Rows ==========
var installmentIdx = 0;
function addInstallmentRow(name, amount, dueDate) {
    installmentIdx++;
    var html = '<div class="row mb-2 align-items-center installment-row">' +
        '<div class="col-md-4"><input type="text" name="installment_name[]" class="form-control form-control-sm" placeholder="اسم القسط (مثال: القسط الأول)" value="' + (name || '') + '" required></div>' +
        '<div class="col-md-3"><div class="input-group input-group-sm"><input type="number" name="installment_amount[]" class="form-control installment-amount" placeholder="المبلغ" value="' + (amount || '') + '" min="0" step="0.01" required oninput="calcTotal()"><span class="input-group-text">جنيه</span></div></div>' +
        '<div class="col-md-3"><input type="text" name="installment_due_date[]" class="form-control form-control-sm flatpickr-date" placeholder="اختر التاريخ..." value="' + (dueDate || '') + '"></div>' +
        '<div class="col-md-2"><button type="button" class="btn btn-outline-danger btn-sm" onclick="this.closest(\'.installment-row\').remove();calcTotal()"><i class="fas fa-times"></i></button></div>' +
        '</div>';
    var rowsContainer = document.getElementById('installmentRows');
    rowsContainer.insertAdjacentHTML('beforeend', html);
    // تهيئة Air Datepicker على حقول التاريخ الجديدة المُحقنة ديناميكياً
    var newRow = rowsContainer.lastElementChild;
    if (newRow && typeof initAirDatepickers === 'function') {
        initAirDatepickers(newRow);
    }
    calcTotal();
}

function calcTotal() {
    var total = 0;
    document.querySelectorAll('.installment-amount').forEach(function(el) {
        total += parseFloat(el.value) || 0;
    });
    document.getElementById('totalAmount').textContent = total.toFixed(2);
}

function resetFeeForm() {
    document.getElementById('feeModalTitle').innerHTML = '<i class="fas fa-plus-circle me-2"></i>إضافة مصاريف دراسية';
    document.getElementById('feeGradeId').value = '';
    document.getElementById('feeNotes').value = '';
    document.getElementById('installmentRows').innerHTML = '';
    document.getElementById('feeSubmitBtn').className = 'btn btn-outline-success';
    installmentIdx = 0;
    addInstallmentRow('القسط الأول', '', '');
}

// ========== View Installments via AJAX ==========
function viewInstallments(fsId) {
    var modal = new bootstrap.Modal(document.getElementById('viewInstallmentsModal'));
    var body = document.getElementById('installmentsViewBody');
    body.innerHTML = '<div class="text-center"><i class="fas fa-spinner fa-spin fa-2x"></i></div>';
    modal.show();

    $.ajax({
        url: 'fee_structure.php?ajax=view_installments&fs_id=' + fsId,
        method: 'GET',
        dataType: 'json',
        success: function(data) {
            if (data.success) {
                var html = '<table class="table table-bordered table-sm"><thead><tr><th>القسط</th><th>المبلغ</th><th>تاريخ الاستحقاق</th></tr></thead><tbody>';
                data.installments.forEach(function(inst) {
                    html += '<tr><td>' + inst.installment_name + '</td><td class="text-success fw-bold">' + parseFloat(inst.amount).toLocaleString('en', {minimumFractionDigits:2}) + ' جنيه</td><td>' + (inst.due_date || '-') + '</td></tr>';
                });
                html += '</tbody><tfoot><tr class="table-primary"><td><strong>الإجمالي</strong></td><td colspan="2"><strong class="text-primary">' + parseFloat(data.total).toLocaleString('en', {minimumFractionDigits:2}) + ' جنيه</strong></td></tr></tfoot></table>';
                body.innerHTML = html;
            } else {
                body.innerHTML = '<div class="alert alert-danger">' + data.message + '</div>';
            }
        },
        error: function() {
            body.innerHTML = '<div class="alert alert-danger">حدث خطأ في جلب البيانات</div>';
        }
    });
}

// ========== Edit Fee Structure ==========
function editFeeStructure(fsId) {
    $.ajax({
        url: 'fee_structure.php?ajax=get_fee_structure&fs_id=' + fsId,
        method: 'GET',
        dataType: 'json',
        success: function(data) {
            if (data.success) {
                document.getElementById('feeModalTitle').innerHTML = '<i class="fas fa-edit me-2"></i>تعديل المصاريف';
                document.getElementById('feeGradeId').value = data.fee.grade_id;
                document.getElementById('feeNotes').value = data.fee.notes || '';
                document.getElementById('installmentRows').innerHTML = '';
                document.getElementById('feeSubmitBtn').className = 'btn btn-outline-primary';
                installmentIdx = 0;
                data.installments.forEach(function(inst) {
                    addInstallmentRow(inst.installment_name, inst.amount, inst.due_date || '');
                });
                calcTotal();
                new bootstrap.Modal(document.getElementById('feeStructureModal')).show();
            }
        }
    });
}

// ========== Delete Fee Structure ==========
function confirmDeleteFS(id, name) {
    document.getElementById('deleteFSId').value = id;
    document.getElementById('deleteFSName').textContent = name;
    new bootstrap.Modal(document.getElementById('deleteFSModal')).show();
}

// ========== Sibling Discount Rows ==========
function addSiblingRow() {
    var rows = document.querySelectorAll('#siblingDiscountRows .sibling-row');
    var nextOrder = rows.length + 1;
    var html = '<div class="row mb-2 align-items-center sibling-row">' +
        '<div class="col-md-4"><div class="input-group"><span class="input-group-text">الأخ رقم</span><input type="number" name="sibling_order[]" class="form-control" value="' + nextOrder + '" min="1" readonly></div></div>' +
        '<div class="col-md-4"><div class="input-group"><input type="number" name="discount_percentage[]" class="form-control" value="0" min="0" max="100" step="0.5"><span class="input-group-text">%</span></div></div>' +
        '<div class="col-md-2"><button type="button" class="btn btn-outline-danger btn-sm" onclick="this.closest(\'.sibling-row\').remove()"><i class="fas fa-times"></i></button></div>' +
        '</div>';
    document.getElementById('siblingDiscountRows').insertAdjacentHTML('beforeend', html);
}

// ========== Bus Zone ==========
function resetBusZoneForm() {
    document.getElementById('busZoneModalTitle').innerHTML = '<i class="fas fa-plus-circle me-2"></i>إضافة منطقة';
    document.getElementById('busZoneId').value = 0;
    document.getElementById('busZoneName').value = '';
    document.getElementById('busZoneFee').value = '';
    document.getElementById('busZoneNotes').value = '';
    document.getElementById('busZoneSubmitBtn').className = 'btn btn-outline-success';
}

function editBusZone(zone) {
    document.getElementById('busZoneModalTitle').innerHTML = '<i class="fas fa-edit me-2"></i>تعديل المنطقة';
    document.getElementById('busZoneId').value = zone.id;
    document.getElementById('busZoneName').value = zone.zone_name;
    document.getElementById('busZoneFee').value = zone.fee_amount;
    document.getElementById('busZoneNotes').value = zone.notes || '';
    document.getElementById('busZoneSubmitBtn').className = 'btn btn-outline-primary';
    new bootstrap.Modal(document.getElementById('busZoneModal')).show();
}

function confirmDeleteZone(id, name) {
    document.getElementById('deleteZoneId').value = id;
    document.getElementById('deleteZoneName').textContent = name;
    new bootstrap.Modal(document.getElementById('deleteZoneModal')).show();
}

// ========== Other Discounts ==========
function resetOtherDiscountForm() {
    document.getElementById('odModalTitle').innerHTML = '<i class="fas fa-plus-circle me-2"></i>إضافة خصم';
    document.getElementById('odId').value = 0;
    document.getElementById('odName').value = '';
    document.getElementById('odType').value = 'amount';
    document.getElementById('odValue').value = '';
    document.getElementById('odValueUnit').textContent = 'جنيه';
    document.getElementById('odSubmitBtn').className = 'btn btn-outline-success';
}

function editOtherDiscount(od) {
    document.getElementById('odModalTitle').innerHTML = '<i class="fas fa-edit me-2"></i>تعديل خصم';
    document.getElementById('odId').value = od.id;
    document.getElementById('odName').value = od.name;
    document.getElementById('odType').value = od.discount_type;
    document.getElementById('odValue').value = od.discount_value;
    document.getElementById('odValueUnit').textContent = od.discount_type === 'percentage' ? '%' : 'جنيه';
    document.getElementById('odSubmitBtn').className = 'btn btn-outline-primary';
    new bootstrap.Modal(document.getElementById('otherDiscountModal')).show();
}

document.getElementById('odType').addEventListener('change', function() {
    document.getElementById('odValueUnit').textContent = this.value === 'percentage' ? '%' : 'جنيه';
});

function confirmDeleteOtherDiscount(id, name) {
    document.getElementById('deleteOdId').value = id;
    document.getElementById('deleteOdName').textContent = name;
    new bootstrap.Modal(document.getElementById('deleteOtherDiscountModal')).show();
}

// ========== Tab Persistence ==========
document.querySelectorAll('#feeTabs a[data-bs-toggle="tab"]').forEach(function(tab) {
    tab.addEventListener('shown.bs.tab', function(e) {
        var tabName = e.target.getAttribute('href').replace('#pane-', '');
        var url = new URL(window.location);
        url.searchParams.set('tab', tabName);
        window.history.replaceState({}, '', url);
    });
});

// ========== DataTables ==========
$(document).ready(function() {
    if ($('#feeTable').length && typeof $.fn.DataTable !== 'undefined') {
        if (!$.fn.DataTable.isDataTable('#feeTable')) {
            $('#feeTable').DataTable({
                language: { url: '//cdn.datatables.net/plug-ins/1.13.7/i18n/ar.json' },
                order: [[1, 'asc'], [2, 'asc']],
                pageLength: 50,
                responsive: true
            });
        }
    }
    if ($('#busZoneTable').length && typeof $.fn.DataTable !== 'undefined') {
        if (!$.fn.DataTable.isDataTable('#busZoneTable')) {
            $('#busZoneTable').DataTable({
                language: { url: '//cdn.datatables.net/plug-ins/1.13.7/i18n/ar.json' },
                order: [[1, 'asc']],
                pageLength: 50,
                responsive: true
            });
        }
    }
    // Tooltips
    $('[data-bs-toggle="tooltip"]').tooltip();
});
</script>

<?php require_once '../includes/admin_footer.php'; ?>
