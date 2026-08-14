<?php
/**
 * Fee Calculator — حاسبة المصروفات الدراسية
 * Calculate total fees including sibling discounts
 */
$page_title = "حاسبة المصروفات";
$custom_page_title = true;

require_once '../config/database.php';
require_once '../classes/utilities.php';
require_once '../includes/session_config.php';
Utilities::validateSession('admin');
require_once __DIR__ . '/../classes/FinanceLegacyAdapter.php';
FinanceLegacyAdapter::delegateRequestIfEnabled(__FILE__);

$database = new Database();
$db = $database->getConnection();

// Academic year
$current_year = date('Y');
$current_month = date('n');
if ($current_month >= 9) {
    $academic_year = $current_year . '-' . ($current_year + 1);
} else {
    $academic_year = ($current_year - 1) . '-' . $current_year;
}
$selected_year = $_GET['year'] ?? $academic_year;

// =============== AJAX ENDPOINT ===============
if (isset($_GET['ajax']) && $_GET['ajax'] === 'calculate') {
    header('Content-Type: application/json; charset=utf-8');

    $grade_id = (int)($_GET['grade_id'] ?? 0);
    $sibling_count = (int)($_GET['sibling_count'] ?? 1); // how many siblings total (including this student)
    $sibling_order = (int)($_GET['sibling_order'] ?? 1); // this student's order among siblings
    $bus_zone_id = (int)($_GET['bus_zone_id'] ?? 0);
    $year = $_GET['year'] ?? $selected_year;

    // Get tuition for grade
    $stmt = $db->prepare("SELECT total_amount FROM fee_structure WHERE grade_id = ? AND academic_year = ? AND status = 'active'");
    $stmt->execute([$grade_id, $year]);
    $tuition = (float)($stmt->fetchColumn() ?: 0);

    // Get sibling discount for this student's order
    $stmt = $db->prepare("SELECT discount_percentage FROM sibling_discounts WHERE academic_year = ? AND sibling_order = ?");
    $stmt->execute([$year, $sibling_order]);
    $discount_pct = (float)($stmt->fetchColumn() ?: 0);

    $sibling_discount = $tuition * ($discount_pct / 100);
    $tuition_after_discount = $tuition - $sibling_discount;

    // Bus fee
    $bus_fee = 0;
    if ($bus_zone_id > 0) {
        $stmt = $db->prepare("SELECT fee_amount FROM bus_fee_zones WHERE id = ? AND academic_year = ?");
        $stmt->execute([$bus_zone_id, $year]);
        $bus_fee = (float)($stmt->fetchColumn() ?: 0);
    }

    $total = $tuition_after_discount + $bus_fee;

    echo json_encode([
        'success' => true,
        'tuition' => $tuition,
        'discount_pct' => $discount_pct,
        'sibling_discount' => $sibling_discount,
        'tuition_after_discount' => $tuition_after_discount,
        'bus_fee' => $bus_fee,
        'total' => $total
    ]);
    exit();
}

// =============== AJAX: Calculate for all siblings ===============
if (isset($_GET['ajax']) && $_GET['ajax'] === 'calculate_family') {
    header('Content-Type: application/json; charset=utf-8');

    $year = $_GET['year'] ?? $selected_year;
    $siblings_json = $_GET['siblings'] ?? '[]';
    $siblings = json_decode($siblings_json, true);

    if (!is_array($siblings) || empty($siblings)) {
        echo json_encode(['success' => false, 'message' => 'لم يتم إدخال بيانات الإخوة']);
        exit();
    }

    // Load all discounts for this year
    $stmt = $db->prepare("SELECT sibling_order, discount_percentage FROM sibling_discounts WHERE academic_year = ?");
    $stmt->execute([$year]);
    $discountMap = [];
    while ($r = $stmt->fetch(PDO::FETCH_ASSOC)) {
        $discountMap[(int)$r['sibling_order']] = (float)$r['discount_percentage'];
    }

    $results = [];
    $familyTotal = 0;
    $familyDiscount = 0;

    foreach ($siblings as $idx => $sib) {
        $grade_id = (int)($sib['grade_id'] ?? 0);
        $bus_zone_id = (int)($sib['bus_zone_id'] ?? 0);
        $order = $idx + 1;

        // Get tuition
        $stmt = $db->prepare("SELECT total_amount FROM fee_structure WHERE grade_id = ? AND academic_year = ? AND status = 'active'");
        $stmt->execute([$grade_id, $year]);
        $tuition = (float)($stmt->fetchColumn() ?: 0);

        $discount_pct = $discountMap[$order] ?? 0;
        $discount_amount = $tuition * ($discount_pct / 100);
        $after_discount = $tuition - $discount_amount;

        // Bus fee
        $bus_fee = 0;
        if ($bus_zone_id > 0) {
            $stmt = $db->prepare("SELECT fee_amount FROM bus_fee_zones WHERE id = ? AND academic_year = ?");
            $stmt->execute([$bus_zone_id, $year]);
            $bus_fee = (float)($stmt->fetchColumn() ?: 0);
        }

        $subtotal = $after_discount + $bus_fee;

        $results[] = [
            'order' => $order,
            'grade_id' => $grade_id,
            'tuition' => $tuition,
            'discount_pct' => $discount_pct,
            'discount_amount' => $discount_amount,
            'after_discount' => $after_discount,
            'bus_fee' => $bus_fee,
            'subtotal' => $subtotal
        ];

        $familyTotal += $subtotal;
        $familyDiscount += $discount_amount;
    }

    echo json_encode([
        'success' => true,
        'results' => $results,
        'family_total' => $familyTotal,
        'family_discount' => $familyDiscount
    ]);
    exit();
}

// Fetch data for dropdowns
$grades = $db->query("SELECT g.id, g.grade_name, s.stage_name FROM grades g LEFT JOIN stages s ON g.stage_id = s.id WHERE g.status = 'active' ORDER BY g.grade_order")->fetchAll(PDO::FETCH_ASSOC);

$stmt = $db->prepare("SELECT * FROM bus_fee_zones WHERE academic_year = ? ORDER BY zone_name");
$stmt->execute([$selected_year]);
$busZones = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Sibling discounts for display
$stmt = $db->prepare("SELECT * FROM sibling_discounts WHERE academic_year = ? ORDER BY sibling_order");
$stmt->execute([$selected_year]);
$siblingDiscounts = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Grade fees for display
$stmt = $db->prepare("SELECT g.grade_name, fs.total_amount FROM fee_structure fs JOIN grades g ON fs.grade_id = g.id WHERE fs.academic_year = ? AND fs.status = 'active' ORDER BY g.grade_order");
$stmt->execute([$selected_year]);
$gradeFees = $stmt->fetchAll(PDO::FETCH_ASSOC);

include_once '../includes/admin_header.php';
echo FinanceLegacyAdapter::bridgeNotice(__FILE__);
?>

<div class="fee-calculator-page admin-unified-container">
    <!-- Page Header -->
    <div class="admin-page-heading">
        <h1 class="h2"><i class="fas fa-calculator me-2 text-primary"></i>حاسبة المصروفات الدراسية</h1>
        <div class="admin-top-actions no-print">
            <span class="badge bg-primary fs-6 px-3 py-2 rounded-pill shadow-sm">
                <i class="fas fa-calendar-alt me-1"></i>العام الدراسي: <?php echo htmlspecialchars($selected_year); ?>
            </span>
        </div>
    </div>

    <!-- Stat Cards -->
    <div class="row row-cols-1 row-cols-md-3 g-3 mb-4">
        <div class="col">
            <div class="stat-card" style="--card-gradient: linear-gradient(135deg, #3b82f6, #2563eb);">
                <div class="stat-card-icon"><i class="fas fa-graduation-cap"></i></div>
                <div class="stat-card-info">
                    <div class="stat-card-number counter" data-target="<?php echo count($gradeFees ?? []); ?>">0</div>
                    <div class="stat-card-label">صفوف بمصاريف مُعرّفة</div>
                    <div class="stat-card-sub"><i class="fas fa-calendar-alt me-1"></i> العام <?php echo htmlspecialchars($selected_year); ?></div>
                </div>
            </div>
        </div>
        <div class="col">
            <div class="stat-card" style="--card-gradient: linear-gradient(135deg, #f59e0b, #d97706);">
                <div class="stat-card-icon"><i class="fas fa-bus"></i></div>
                <div class="stat-card-info">
                    <div class="stat-card-number counter" data-target="<?php echo count($busZones); ?>">0</div>
                    <div class="stat-card-label">مناطق حافلات مسبقة</div>
                    <div class="stat-card-sub"><i class="fas fa-map-marker-alt me-1"></i> رسوم النقل المدرسي</div>
                </div>
            </div>
        </div>
        <div class="col">
            <div class="stat-card" style="--card-gradient: linear-gradient(135deg, #10b981, #059669);">
                <div class="stat-card-icon"><i class="fas fa-percent"></i></div>
                <div class="stat-card-info">
                    <div class="stat-card-number counter" data-target="<?php echo count($siblingDiscounts); ?>">0</div>
                    <div class="stat-card-label">شرائح خصم الإخوة</div>
                    <div class="stat-card-sub"><i class="fas fa-users me-1"></i> خصم التتابع</div>
                </div>
            </div>
        </div>
    </div>

    <!-- Main Content Grid -->
    <div class="row g-4">
        <!-- Calculator Column -->
        <div class="col-xl-7 col-lg-7">
            <div class="card border-0 shadow-sm rounded-3 mb-4">
                <div class="card-header bg-light py-3 px-4 border-bottom d-flex align-items-center justify-content-between">
                    <h5 class="fw-bold text-dark mb-0 d-flex align-items-center">
                        <i class="fas fa-calculator text-primary me-2"></i>حاسبة مصاريف الأسرة
                    </h5>
                    <span class="badge bg-light-subtle text-primary border border-primary-subtle px-2 py-1 small">
                        <i class="fas fa-coins me-1"></i>حساب تجميعي
                    </span>
                </div>
                <div class="card-body p-4">
                    <div class="alert alert-info py-2 px-3 mb-3 small d-flex align-items-center">
                        <i class="fas fa-info-circle me-2 fs-5 flex-shrink-0"></i>
                        <span>أضف كل أخ/أخت واختر صفه الدراسي ومنطقة الحافلة لحساب إجمالي المصاريف وتطبيق الخصومات تلقائياً.</span>
                    </div>

                    <div id="siblingInputs" class="mb-3">
                        <!-- First sibling row -->
                        <div class="card mb-3 sibling-input-card border rounded-3 bg-light-subtle shadow-xs">
                            <div class="card-body p-3">
                                <div class="row align-items-center g-2">
                                    <div class="col-auto">
                                        <span class="badge bg-primary rounded-circle sibling-order-badge fs-6 shadow-xs" style="width:32px; height:32px; display:inline-flex; align-items:center; justify-content:center;">1</span>
                                    </div>
                                    <div class="col-md-5">
                                        <label class="form-label small fw-bold text-dark mb-1">الصف الدراسي <span class="text-danger">*</span></label>
                                        <select class="form-select sibling-grade" required>
                                            <option value="">-- اختر الصف الدراسي --</option>
                                            <?php foreach ($grades as $g): ?>
                                            <option value="<?php echo $g['id']; ?>"><?php echo htmlspecialchars($g['stage_name'] . ' - ' . $g['grade_name']); ?></option>
                                            <?php endforeach; ?>
                                        </select>
                                    </div>
                                    <div class="col-md-5">
                                        <label class="form-label small fw-bold text-dark mb-1">منطقة الحافلة</label>
                                        <select class="form-select sibling-bus-zone">
                                            <option value="0">-- بدون حافلة --</option>
                                            <?php foreach ($busZones as $z): ?>
                                            <option value="<?php echo $z['id']; ?>"><?php echo htmlspecialchars($z['zone_name']); ?> (<?php echo number_format($z['fee_amount'], 2); ?> ج.م)</option>
                                            <?php endforeach; ?>
                                        </select>
                                    </div>
                                    <div class="col-auto pt-4">
                                        <!-- First sibling cannot be deleted -->
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>

                    <div class="d-flex flex-wrap gap-2 justify-content-between align-items-center pt-2 mb-3">
                        <button type="button" class="btn btn-outline-primary shadow-sm px-3" onclick="addSiblingInput()">
                            <i class="fas fa-plus-circle me-1"></i>إضافة أخ/أخت
                        </button>
                        <button type="button" class="btn btn-primary px-4 shadow-sm" onclick="calculateFamily()">
                            <i class="fas fa-calculator me-2"></i>احسب المصاريف
                        </button>
                    </div>

                    <!-- Results Section -->
                    <div id="calcResults" class="mt-4 pt-3 border-top" style="display:none;">
                        <h5 class="fw-bold text-dark mb-3 d-flex align-items-center justify-content-center">
                            <i class="fas fa-receipt text-success me-2"></i>تفاصيل حساب مصاريف الأسرة
                        </h5>
                        <div class="admin-list-surface border rounded-3 p-2 bg-white shadow-xs">
                            <div class="table-responsive">
                                <table class="table table-hover table-striped align-middle mb-0" id="resultsTable">
                                    <thead class="table-light">
                                        <tr>
                                            <th>الترتيب</th>
                                            <th>المصاريف الأصلية</th>
                                            <th>نسبة الخصم</th>
                                            <th>قيمة الخصم</th>
                                            <th>بعد الخصم</th>
                                            <th>رسوم الحافلة</th>
                                            <th>الإجمالي</th>
                                        </tr>
                                    </thead>
                                    <tbody id="resultsBody"></tbody>
                                    <tfoot id="resultsFoot"></tfoot>
                                </table>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <!-- Info Sidebar Column -->
        <div class="col-xl-5 col-lg-5">
            <!-- Sibling Discounts Reference -->
            <div class="card border-0 shadow-sm rounded-3 mb-4">
                <div class="card-header bg-light py-2 px-3 border-bottom d-flex align-items-center justify-content-between">
                    <h6 class="fw-bold text-dark mb-0"><i class="fas fa-percent me-2 text-success"></i>جدول خصومات الإخوة</h6>
                    <span class="badge bg-success-subtle text-success border border-success-subtle small">مُطَبَّق</span>
                </div>
                <div class="card-body p-0">
                    <?php if (empty($siblingDiscounts)): ?>
                        <div class="p-3 text-center text-muted small">لم يتم تحديد خصومات لهذا العام</div>
                    <?php else: ?>
                    <div class="table-responsive">
                        <table class="table table-hover table-striped align-middle mb-0">
                            <thead class="table-light">
                                <tr><th>الترتيب</th><th>نسبة الخصم</th></tr>
                            </thead>
                            <tbody>
                                <?php foreach ($siblingDiscounts as $d): ?>
                                <tr>
                                    <td class="fw-semibold">الأخ رقم <?php echo (int)$d['sibling_order']; ?></td>
                                    <td><span class="badge <?php echo $d['discount_percentage'] > 0 ? 'bg-success' : 'bg-secondary'; ?> px-2 py-1 fs-7"><?php echo htmlspecialchars($d['discount_percentage']); ?>%</span></td>
                                </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                    <?php endif; ?>
                </div>
            </div>

            <!-- Bus Zones Reference -->
            <div class="card border-0 shadow-sm rounded-3 mb-4">
                <div class="card-header bg-light py-2 px-3 border-bottom d-flex align-items-center justify-content-between">
                    <h6 class="fw-bold text-dark mb-0"><i class="fas fa-bus me-2 text-warning"></i>رسوم مناطق الحافلات</h6>
                    <span class="badge bg-warning-subtle text-warning-emphasis border border-warning-subtle small"><?php echo count($busZones); ?> مناطق</span>
                </div>
                <div class="card-body p-0">
                    <?php if (empty($busZones)): ?>
                        <div class="p-3 text-center text-muted small">لم يتم تحديد مناطق حافلات بعد</div>
                    <?php else: ?>
                    <div class="table-responsive">
                        <table class="table table-hover table-striped align-middle mb-0">
                            <thead class="table-light">
                                <tr><th>المنطقة</th><th>الرسوم</th></tr>
                            </thead>
                            <tbody>
                                <?php foreach ($busZones as $z): ?>
                                <tr>
                                    <td class="fw-semibold"><?php echo htmlspecialchars($z['zone_name']); ?></td>
                                    <td><strong class="text-success"><?php echo number_format($z['fee_amount'], 2); ?> ج.م</strong></td>
                                </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                    <?php endif; ?>
                </div>
            </div>

            <!-- Grade Fees Reference -->
            <div class="card border-0 shadow-sm rounded-3 mb-4">
                <div class="card-header bg-light py-2 px-3 border-bottom d-flex align-items-center justify-content-between">
                    <h6 class="fw-bold text-dark mb-0"><i class="fas fa-money-bill-wave me-2 text-primary"></i>مصاريف الصفوف</h6>
                    <span class="badge bg-primary-subtle text-primary border border-primary-subtle small"><?php echo count($gradeFees); ?> صفوف</span>
                </div>
                <div class="card-body p-0">
                    <?php if (empty($gradeFees)): ?>
                        <div class="p-3 text-center text-muted small">لم يتم تحديد مصاريف للصفوف لهذا العام</div>
                    <?php else: ?>
                    <div class="table-responsive">
                        <table class="table table-hover table-striped align-middle mb-0">
                            <thead class="table-light">
                                <tr><th>الصف</th><th>الإجمالي</th></tr>
                            </thead>
                            <tbody>
                                <?php foreach ($gradeFees as $gf): ?>
                                <tr>
                                    <td class="fw-semibold"><?php echo htmlspecialchars($gf['grade_name']); ?></td>
                                    <td><strong class="text-primary"><?php echo number_format($gf['total_amount'], 2); ?> ج.م</strong></td>
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
</div>

<script>
var gradeOptions = <?php echo json_encode($grades); ?>;
var busZoneOptions = <?php echo json_encode($busZones); ?>;
var selectedYear = <?php echo json_encode($selected_year); ?>;

function addSiblingInput() {
    var container = document.getElementById('siblingInputs');
    var count = container.querySelectorAll('.sibling-input-card').length + 1;

    var gradeOpts = '<option value="">-- اختر الصف الدراسي --</option>';
    gradeOptions.forEach(function(g) {
        gradeOpts += '<option value="' + g.id + '">' + g.stage_name + ' - ' + g.grade_name + '</option>';
    });

    var busOpts = '<option value="0">-- بدون حافلة --</option>';
    busZoneOptions.forEach(function(z) {
        busOpts += '<option value="' + z.id + '">' + z.zone_name + ' (' + parseFloat(z.fee_amount).toFixed(2) + ' ج.م)</option>';
    });

    var html = '<div class="card mb-3 sibling-input-card border rounded-3 bg-light-subtle shadow-xs">' +
        '<div class="card-body p-3"><div class="row align-items-center g-2">' +
        '<div class="col-auto"><span class="badge bg-primary rounded-circle sibling-order-badge fs-6 shadow-xs" style="width:32px; height:32px; display:inline-flex; align-items:center; justify-content:center;">' + count + '</span></div>' +
        '<div class="col-md-5"><label class="form-label small fw-bold text-dark mb-1">الصف الدراسي <span class="text-danger">*</span></label><select class="form-select sibling-grade" required>' + gradeOpts + '</select></div>' +
        '<div class="col-md-5"><label class="form-label small fw-bold text-dark mb-1">منطقة الحافلة</label><select class="form-select sibling-bus-zone">' + busOpts + '</select></div>' +
        '<div class="col-auto pt-4"><button type="button" class="btn btn-action-pills btn-delete me-1" title="حذف" onclick="removeSiblingInput(this)"><i class="fas fa-trash"></i></button></div>' +
        '</div></div></div>';

    container.insertAdjacentHTML('beforeend', html);
}

function removeSiblingInput(btn) {
    btn.closest('.sibling-input-card').remove();
    document.querySelectorAll('#siblingInputs .sibling-order-badge').forEach(function(badge, idx) {
        badge.textContent = idx + 1;
    });
}

function calculateFamily() {
    var cards = document.querySelectorAll('#siblingInputs .sibling-input-card');
    var siblings = [];

    cards.forEach(function(card) {
        var gradeId = card.querySelector('.sibling-grade').value;
        var busZoneId = card.querySelector('.sibling-bus-zone').value;
        if (gradeId) {
            siblings.push({ grade_id: gradeId, bus_zone_id: busZoneId || 0 });
        }
    });

    if (siblings.length === 0) {
        alert('يرجى اختيار الصف الدراسي لأخ واحد على الأقل');
        return;
    }

    $.ajax({
        url: 'fee_calculator.php?ajax=calculate_family&year=' + encodeURIComponent(selectedYear) + '&siblings=' + encodeURIComponent(JSON.stringify(siblings)),
        method: 'GET',
        dataType: 'json',
        success: function(data) {
            if (data.success) {
                var tbody = document.getElementById('resultsBody');
                var tfoot = document.getElementById('resultsFoot');
                tbody.innerHTML = '';

                data.results.forEach(function(r) {
                    tbody.innerHTML += '<tr>' +
                        '<td><span class="badge bg-primary px-2 py-1">' + r.order + '</span></td>' +
                        '<td class="fw-semibold">' + fmt(r.tuition) + ' ج.م</td>' +
                        '<td><span class="badge ' + (r.discount_pct > 0 ? 'bg-success' : 'bg-secondary') + ' px-2 py-1">' + r.discount_pct + '%</span></td>' +
                        '<td class="text-danger fw-semibold">' + (r.discount_amount > 0 ? '-' + fmt(r.discount_amount) + ' ج.م' : '-') + '</td>' +
                        '<td class="fw-semibold">' + fmt(r.after_discount) + ' ج.م</td>' +
                        '<td>' + (r.bus_fee > 0 ? fmt(r.bus_fee) + ' ج.م' : '-') + '</td>' +
                        '<td><strong class="text-primary fs-6">' + fmt(r.subtotal) + ' ج.م</strong></td>' +
                        '</tr>';
                });

                tfoot.innerHTML = '<tr class="table-warning">' +
                    '<td colspan="3"><strong class="text-dark">إجمالي خصم الإخوة</strong></td>' +
                    '<td colspan="4"><strong class="text-danger fs-6">' + fmt(data.family_discount) + ' ج.م</strong></td>' +
                    '</tr>' +
                    '<tr class="table-success">' +
                    '<td colspan="3"><strong class="text-dark">الإجمالي المطلوب للأسرة</strong></td>' +
                    '<td colspan="4"><strong class="text-success fs-5">' + fmt(data.family_total) + ' ج.م</strong></td>' +
                    '</tr>';

                document.getElementById('calcResults').style.display = 'block';
            }
        }
    });
}

function fmt(n) {
    return parseFloat(n).toLocaleString('en', {minimumFractionDigits: 2, maximumFractionDigits: 2});
}
</script>

<?php require_once '../includes/admin_footer.php'; ?>
