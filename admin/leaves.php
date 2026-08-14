<?php
/**
 * إدارة أجازات الموظفين (اعتيادية، مرضية، عارضة، استثنائية، أخرى)
 */
$page_title = "الأجازات";
$custom_page_title = true;

require_once '../config/database.php';
require_once '../classes/utilities.php';
require_once '../includes/session_config.php';
require_once '../vendor/autoload.php';
require_once '../src/Modules/Operations/Audit/AuditService.php';
require_once '../src/Modules/Staff/bootstrap.php';
Utilities::validateSession('admin');

$database = new Database();
$dt = $database->getConnection();
$staffModuleFactory = new \EduCore\Modules\Staff\Infrastructure\StaffModuleFactory(
    $dt,
    new \EduCore\Modules\Operations\Audit\AuditService($dt)
);
$leaveService = $staffModuleFactory->legacyLeaveCompatibility();

// Get messages from session
$success_message = $_SESSION['success_message'] ?? null;
$error_message = $_SESSION['error_message'] ?? null;
unset($_SESSION['success_message'], $_SESSION['error_message']);

$leaveTypes = [
    'regular' => 'اعتيادية',
    'sick' => 'مرضية',
    'casual' => 'عارضة',
    'exceptional' => 'استثنائية',
    'other' => 'أخرى'
];
$leaveBadges = [
    'regular' => 'primary',
    'sick' => 'danger',
    'casual' => 'warning',
    'exceptional' => 'info',
    'other' => 'secondary'
];
$statusLabels = ['pending' => 'قيد المراجعة', 'approved' => 'موافق عليها', 'rejected' => 'مرفوضة'];
$statusBadges = ['pending' => 'warning', 'approved' => 'success', 'rejected' => 'danger'];

// قراءة سياسة خصم الرصيد حسب النوع لاستخدامها في نموذج الإعدادات داخل هذه الصفحة
$deductibleTypes = $leaveService->getDeductibleTypes($leaveTypes);

// جلب قائمة كل الموظفين الفعّالين (وليس المعلمين/الأخصائيين فقط)
$staffList = $leaveService->getActiveStaffList();


// معالجة النماذج
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $csrfToken = $_POST['csrf_token'] ?? '';
    if (!hash_equals($_SESSION['csrf_token'] ?? '', $csrfToken)) {
        $_SESSION['error_message'] = 'خطأ في التحقق الأمني. يرجى إعادة المحاولة.';
        header('Location: leaves.php');
        exit();
    }

    try {
        if (isset($_POST['save_leave_policy'])) {
            $selectedDeductTypes = $_POST['deduct_types'] ?? [];
            if (!is_array($selectedDeductTypes)) {
                $selectedDeductTypes = [];
            }
            $leaveService->saveDeductibleTypes($selectedDeductTypes, $leaveTypes);
            $_SESSION['success_message'] = 'تم حفظ سياسة خصم رصيد الإجازات بنجاح';
        } elseif (isset($_POST['add_leave'])) {
            $leaveService->saveLeave($_POST, (int)($_SESSION['user_id'] ?? 0));
            $_SESSION['success_message'] = 'تم إضافة الأجازة بنجاح';
        } elseif (isset($_POST['edit_leave'])) {
            $leaveService->saveLeave($_POST, (int)($_SESSION['user_id'] ?? 0), (int)($_POST['id'] ?? 0));
            $_SESSION['success_message'] = 'تم تحديث الأجازة بنجاح';
        } elseif (isset($_POST['delete_leave'])) {
            $leaveService->deleteLeave((int)($_POST['id'] ?? 0));
            $_SESSION['success_message'] = 'تم حذف الأجازة';
        }
    } catch (InvalidArgumentException $exception) {
        $_SESSION['error_message'] = $exception->getMessage();
    } catch (Throwable $exception) {
        $reference = 'LEAVE-' . strtoupper(substr(bin2hex(random_bytes(4)), 0, 8));
        error_log($reference . ' legacy leave compatibility error: ' . $exception->getMessage());
        $_SESSION['error_message'] = 'تعذر تنفيذ عملية الإجازات الآن. لم يتم حفظ أي تغيير غير مكتمل. مرجع المتابعة: ' . $reference;
    }

    header('Location: leaves.php');
    exit();
}

// تعديل
$editData = null;
if (isset($_GET['action']) && $_GET['action'] === 'edit' && isset($_GET['id'])) {
    $editData = $leaveService->getLeaveById((int)$_GET['id']);
}

// فلاتر
$filterUser = $_GET['user_id'] ?? '';
$filterType = $_GET['type'] ?? '';
$filterStatus = $_GET['filter_status'] ?? '';
$filterFrom = $_GET['date_from'] ?? '';
$filterTo = $_GET['date_to'] ?? '';

$leaves = $leaveService->getLeaves([
    'user_id' => $filterUser,
    'type' => $filterType,
    'status' => $filterStatus,
    'date_from' => $filterFrom,
    'date_to' => $filterTo
]);

// خريطة أرصدة الإجازات السنوية للموظفين
$currentYear = (int)date('Y');
$annualBalanceRows = $leaveService->getAnnualLeaveBalanceRows($currentYear, $deductibleTypes, null, 'all');
$leaveBalanceMap = [];
foreach ($annualBalanceRows as $row) {
    $leaveBalanceMap[(int)$row['user_id']] = $row;
}

// إحصائيات ملخصة
$leaveStats = $leaveService->getLeaveStats();
$statsMap = $leaveStats['leave_stats_map'];
$totalLeaves = $leaveStats['total'];
$statusCounts = $leaveStats['status_stats'];

$leaveSummaryCards = [
    [
        'value' => $totalLeaves,
        'label' => 'إجمالي الأجازات',
        'icon' => 'fa-calendar-alt',
        'gradient' => '#3b82f6, #2563eb'
    ],
    [
        'value' => $statusCounts['approved'] ?? 0,
        'label' => 'موافق عليها',
        'icon' => 'fa-check-circle',
        'gradient' => '#10b981, #059669'
    ],
    [
        'value' => $statusCounts['pending'] ?? 0,
        'label' => 'قيد المراجعة',
        'icon' => 'fa-hourglass-half',
        'gradient' => '#f59e0b, #d97706'
    ],
    [
        'value' => $statusCounts['rejected'] ?? 0,
        'label' => 'مرفوضة',
        'icon' => 'fa-times-circle',
        'gradient' => '#ef4444, #dc2626'
    ],
];

$typeGradients = [
    'regular' => '#6366f1, #4f46e5',
    'sick' => '#ec4899, #db2777',
    'casual' => '#f97316, #ea580c',
    'exceptional' => '#06b6d4, #0891b2',
    'other' => '#64748b, #475569'
];
$typeIcons = [
    'regular' => 'fa-umbrella-beach',
    'sick' => 'fa-notes-medical',
    'casual' => 'fa-mug-hot',
    'exceptional' => 'fa-star',
    'other' => 'fa-ellipsis-h'
];
$leaveTypeCards = [];
foreach ($leaveTypes as $typeKey => $typeLabel) {
    $count = $statsMap[$typeKey]['cnt'] ?? 0;
    $days = $statsMap[$typeKey]['total_days'] ?? 0;
    if ($count === 0) {
        continue;
    }

    $leaveTypeCards[] = [
        'value' => $count,
        'label' => $typeLabel,
        'sub' => $days . ' يوم',
        'sub_icon' => 'fa-calendar-day',
        'icon' => $typeIcons[$typeKey] ?? 'fa-ellipsis-h',
        'gradient' => $typeGradients[$typeKey] ?? '#64748b, #475569'
    ];
}

require_once '../includes/admin_header.php';
require_once '../includes/widgets/hr_stat_cards.php';
?>

<!-- Page Header -->
<div class="admin-page-heading">
    <h1 class="h2"><i class="fas fa-calendar-check me-2 text-primary"></i>أجازات الموظفين</h1>
    <div class="admin-top-actions no-print">
        <a href="leave_balances.php" class="btn btn-outline-primary shadow-sm me-2"><i class="fas fa-wallet me-1"></i>أرصدة الإجازات</a>
        <button type="button" class="btn btn-header-premium btn-success shadow-sm" onclick="openAddLeaveModal()">
            <i class="fas fa-plus-circle me-1"></i>إضافة أجازة
        </button>
    </div>
</div>

<!-- Alerts -->
<?php if ($success_message): ?>
<div class="alert alert-success alert-dismissible fade show" role="alert">
    <i class="fas fa-check-circle me-2"></i><?php echo $success_message; ?>
    <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
</div>
<?php endif; ?>

<?php if ($error_message): ?>
<div class="alert alert-danger alert-dismissible fade show" role="alert">
    <i class="fas fa-exclamation-circle me-2"></i><?php echo $error_message; ?>
    <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
</div>
<?php endif; ?>

<!-- Stat Cards -->
<div class="dashboard-canvas sortable-dashboard mb-4">
    <?php renderHrStatCards($leaveSummaryCards); ?>
    <?php if (!empty($leaveTypeCards)): ?>
        <div class="mt-3">
            <?php renderHrStatCards($leaveTypeCards, 'row-cols-2 row-cols-md-5'); ?>
        </div>
    <?php endif; ?>
</div>

<!-- سياسة خصم الرصيد حسب نوع الإجازة -->
<div class="card border-0 shadow-sm mb-4">
    <div class="card-header bg-light border-0 py-3">
        <h6 class="mb-0 fw-bold text-dark"><i class="fas fa-sliders-h me-2 text-primary"></i>سياسة خصم رصيد الإجازات</h6>
    </div>
    <div class="card-body">
        <form method="POST" class="row g-3 align-items-center">
            <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($_SESSION['csrf_token'] ?? '', ENT_QUOTES, 'UTF-8'); ?>">
            <div class="col-12">
                <label class="form-label fw-bold">اختر أنواع الإجازات التي تخصم من الرصيد السنوي:</label>
                <div class="d-flex flex-wrap gap-3 mt-1">
                    <?php foreach ($leaveTypes as $k => $v): ?>
                        <div class="form-check">
                            <input class="form-check-input" type="checkbox" name="deduct_types[]" id="deduct_<?php echo htmlspecialchars($k); ?>" value="<?php echo htmlspecialchars($k); ?>" <?php echo in_array($k, $deductibleTypes, true) ? 'checked' : ''; ?>>
                            <label class="form-check-label" for="deduct_<?php echo htmlspecialchars($k); ?>"><?php echo htmlspecialchars($v); ?></label>
                        </div>
                    <?php endforeach; ?>
                </div>
            </div>
            <div class="col-md-3">
                <button type="submit" name="save_leave_policy" class="btn btn-primary btn-sm px-3"><i class="fas fa-save me-1"></i>حفظ السياسة</button>
            </div>
            <div class="col-md-9">
                <div class="text-muted small mb-0">
                    <i class="fas fa-info-circle me-1 text-info"></i>
                    الأنواع المختارة فقط سيتم احتساب أيامها ضمن "المستهلك" في الرصيد السنوي للموظفين.
                </div>
            </div>
        </form>
    </div>
</div>

<!-- Filter Bar -->
<form method="GET" class="admin-filter-bar mb-3" novalidate>
    <div class="admin-filter-controls">
        <select class="form-select form-select-sm admin-inline-select-sm" name="user_id" aria-label="فلترة الموظف">
            <option value="">كل الموظفين</option>
            <?php foreach ($staffList as $s): ?>
                <option value="<?php echo $s['id']; ?>" <?php echo $filterUser == $s['id'] ? 'selected' : ''; ?>><?php echo htmlspecialchars($s['name']); ?></option>
            <?php endforeach; ?>
        </select>
        <select class="form-select form-select-sm admin-inline-select-sm" name="type" aria-label="فلترة نوع الأجازة">
            <option value="">كل الأنواع</option>
            <?php foreach ($leaveTypes as $k => $v): ?>
                <option value="<?php echo $k; ?>" <?php echo $filterType === $k ? 'selected' : ''; ?>><?php echo $v; ?></option>
            <?php endforeach; ?>
        </select>
        <select class="form-select form-select-sm admin-inline-select-sm" name="filter_status" aria-label="فلترة الحالة">
            <option value="">كل الحالات</option>
            <?php foreach ($statusLabels as $k => $v): ?>
                <option value="<?php echo $k; ?>" <?php echo $filterStatus === $k ? 'selected' : ''; ?>><?php echo $v; ?></option>
            <?php endforeach; ?>
        </select>
        <input type="text" class="form-control form-control-sm flatpickr-date admin-inline-select-sm" name="date_from" value="<?php echo htmlspecialchars($filterFrom, ENT_QUOTES, 'UTF-8'); ?>" placeholder="من تاريخ" style="width: 140px;">
        <input type="text" class="form-control form-control-sm flatpickr-date admin-inline-select-sm" name="date_to" value="<?php echo htmlspecialchars($filterTo, ENT_QUOTES, 'UTF-8'); ?>" placeholder="إلى تاريخ" style="width: 140px;">
    </div>
    <div class="admin-filter-actions">
        <button type="submit" class="btn btn-light btn-sm"><i class="fas fa-search me-1"></i>بحث</button>
        <?php if ($filterUser || $filterType || $filterStatus || $filterFrom || $filterTo): ?>
            <a href="leaves.php" class="btn btn-light btn-sm"><i class="fas fa-rotate-left me-1"></i>إعادة تعيين</a>
        <?php endif; ?>
    </div>
</form>

<!-- Table Surface -->
<div class="admin-list-surface mb-4">
    <?php if (count($leaves) > 0): ?>
    <div class="table-responsive admin-table-wrap">
        <table class="table table-hover table-striped admin-data-table" id="leavesTable">
            <thead>
                <tr>
                    <th width="50">#</th>
                    <th>الموظف</th>
                    <th>نوع الأجازة</th>
                    <th>من</th>
                    <th>إلى</th>
                    <th>عدد الأيام</th>
                    <th>المستهلك/المتبقي</th>
                    <th>السبب</th>
                    <th>الحالة</th>
                    <th class="text-center" width="120">الإجراءات</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($leaves as $i => $l): ?>
                <tr>
                    <td><?php echo $i + 1; ?></td>
                    <td class="fw-bold text-primary"><?php echo htmlspecialchars($l['staff_name']); ?></td>
                    <td><span class="badge bg-<?php echo $leaveBadges[$l['leave_type']] ?? 'secondary'; ?>"><?php echo $leaveTypes[$l['leave_type']] ?? $l['leave_type']; ?></span></td>
                    <td><?php echo htmlspecialchars($l['start_date'], ENT_QUOTES, 'UTF-8'); ?></td>
                    <td><?php echo htmlspecialchars($l['end_date'], ENT_QUOTES, 'UTF-8'); ?></td>
                    <td><span class="badge bg-dark"><?php echo (int)$l['days_count']; ?> يوم</span></td>
                    <td>
                        <?php $tal = $leaveBalanceMap[(int)$l['user_id']] ?? null; ?>
                        <?php if ($tal): ?>
                            <span class="badge bg-warning text-dark me-1"><?php echo (float)$tal['consumed_days']; ?></span>
                            <span class="badge bg-success"><?php echo (float)$tal['remaining_days']; ?></span>
                        <?php else: ?>
                            <span class="text-muted">-</span>
                        <?php endif; ?>
                    </td>
                    <td><?php echo htmlspecialchars($l['reason'] ?? '-'); ?></td>
                    <td><span class="badge bg-<?php echo $statusBadges[$l['status']] ?? 'secondary'; ?>"><?php echo $statusLabels[$l['status']] ?? $l['status']; ?></span></td>
                    <td class="text-center actions-column admin-table-actions">
                        <button type="button"
                                class="btn btn-action-pills btn-edit me-1"
                                onclick="openEditLeaveModal(<?php echo htmlspecialchars(json_encode($l, JSON_HEX_APOS | JSON_HEX_QUOT), ENT_QUOTES, 'UTF-8'); ?>)"
                                data-bs-toggle="tooltip"
                                title="تعديل"><i class="fas fa-edit"></i></button>
                        <button type="button"
                                class="btn btn-action-pills btn-delete"
                                onclick="openDeleteLeaveModal(<?php echo (int)$l['id']; ?>, '<?php echo htmlspecialchars($l['staff_name'], ENT_QUOTES, 'UTF-8'); ?>')"
                                data-bs-toggle="tooltip"
                                title="حذف"><i class="fas fa-trash"></i></button>
                    </td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
    <?php else: ?>
        <div class="alert alert-info m-3"><i class="fas fa-info-circle me-2"></i>لا توجد أجازات مسجلة.</div>
    <?php endif; ?>
</div>

<!-- Add/Edit Leave Modal -->
<div class="modal fade" id="leaveModal" tabindex="-1" aria-labelledby="leaveModalLabel" aria-hidden="true">
    <div class="modal-dialog modal-lg">
        <div class="modal-content admin-modal admin-modal-premium" id="leaveModalContent">
            <form method="POST" id="leaveForm">
                <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($_SESSION['csrf_token'] ?? '', ENT_QUOTES, 'UTF-8'); ?>">
                <input type="hidden" name="id" id="leaveId" value="">
                <div class="modal-header">
                    <h5 class="modal-title" id="leaveModalTitle"><i class="fas fa-plus-circle me-2" id="leaveModalIcon"></i><span id="leaveModalTitleText">إضافة أجازة جديدة</span></h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="إغلاق"></button>
                </div>
                <div class="modal-body">
                    <div class="row g-3">
                        <div class="col-md-6">
                            <label class="form-label" for="leaveUserId">الموظف <span class="text-danger">*</span></label>
                            <select class="form-select" name="user_id" id="leaveUserId" required>
                                <option value="">اختر الموظف...</option>
                                <?php foreach ($staffList as $s): ?>
                                    <option value="<?php echo $s['id']; ?>"><?php echo htmlspecialchars($s['name']); ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label" for="leaveType">نوع الأجازة <span class="text-danger">*</span></label>
                            <select class="form-select" name="leave_type" id="leaveType" required>
                                <?php foreach ($leaveTypes as $k => $v): ?>
                                    <option value="<?php echo $k; ?>"><?php echo $v; ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-md-4">
                            <label class="form-label" for="leaveStartDate">من تاريخ <span class="text-danger">*</span></label>
                            <input type="text" class="form-control flatpickr-date" name="start_date" id="leaveStartDate" value="<?php echo date('Y-m-d'); ?>" required>
                        </div>
                        <div class="col-md-4">
                            <label class="form-label" for="leaveEndDate">إلى تاريخ <span class="text-danger">*</span></label>
                            <input type="text" class="form-control flatpickr-date" name="end_date" id="leaveEndDate" value="<?php echo date('Y-m-d'); ?>" required>
                        </div>
                        <div class="col-md-4">
                            <label class="form-label" for="leaveStatus">الحالة</label>
                            <select class="form-select" name="status" id="leaveStatus">
                                <?php foreach ($statusLabels as $k => $v): ?>
                                    <option value="<?php echo $k; ?>" <?php echo $k === 'approved' ? 'selected' : ''; ?>><?php echo $v; ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-12">
                            <label class="form-label" for="leaveReason">السبب</label>
                            <input type="text" class="form-control" name="reason" id="leaveReason" placeholder="سبب الأجازة">
                        </div>
                        <div class="col-12">
                            <label class="form-label" for="leaveNotes">ملاحظات</label>
                            <input type="text" class="form-control" name="notes" id="leaveNotes" placeholder="ملاحظات إضافية">
                        </div>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal"><i class="fas fa-times me-1"></i>إلغاء</button>
                    <button type="submit" name="add_leave" id="leaveSubmitBtn" class="btn btn-success">
                        <i class="fas fa-save me-1"></i><span id="leaveSubmitBtnText">إضافة الأجازة</span>
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- Delete Modal -->
<div class="modal fade" id="deleteModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog">
        <div class="modal-content admin-modal admin-modal-premium admin-modal-delete">
            <form method="POST">
                <input type="hidden" name="id" id="delete_id">
                <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($_SESSION['csrf_token'] ?? '', ENT_QUOTES, 'UTF-8'); ?>">
                <div class="modal-header">
                    <h5 class="modal-title"><i class="fas fa-trash me-2"></i>حذف أجازة</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="إغلاق"></button>
                </div>
                <div class="modal-body text-center">
                    <i class="fas fa-exclamation-triangle text-warning admin-modal-icon-lg mb-3"></i>
                    <p>هل أنت متأكد من حذف أجازة <strong id="delete_name"></strong>؟</p>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal"><i class="fas fa-times me-1"></i>إلغاء</button>
                    <button type="submit" name="delete_leave" class="btn btn-danger"><i class="fas fa-trash me-1"></i>حذف الأجازة</button>
                </div>
            </form>
        </div>
    </div>
</div>

<script>
function openAddLeaveModal() {
    var form = document.getElementById('leaveForm');
    if (form) form.reset();
    document.getElementById('leaveId').value = '';
    document.getElementById('leaveStartDate').value = '<?php echo date('Y-m-d'); ?>';
    document.getElementById('leaveEndDate').value = '<?php echo date('Y-m-d'); ?>';
    document.getElementById('leaveModalTitleText').textContent = 'إضافة أجازة جديدة';
    document.getElementById('leaveModalIcon').className = 'fas fa-plus-circle me-2';
    document.getElementById('leaveSubmitBtnText').textContent = 'إضافة الأجازة';
    var submitBtn = document.getElementById('leaveSubmitBtn');
    if (submitBtn) {
        submitBtn.name = 'add_leave';
        submitBtn.className = 'btn btn-success';
    }
    var modalContent = document.getElementById('leaveModalContent');
    if (modalContent) {
        modalContent.className = 'modal-content admin-modal admin-modal-premium admin-modal-create';
    }
    var modalEl = document.getElementById('leaveModal');
    if (modalEl && window.bootstrap) {
        bootstrap.Modal.getOrCreateInstance(modalEl).show();
    }
}

function openEditLeaveModal(record) {
    if (!record) return;
    document.getElementById('leaveId').value = record.id || '';
    document.getElementById('leaveUserId').value = record.user_id || '';
    document.getElementById('leaveType').value = record.leave_type || 'regular';
    document.getElementById('leaveStartDate').value = record.start_date || '<?php echo date('Y-m-d'); ?>';
    document.getElementById('leaveEndDate').value = record.end_date || '<?php echo date('Y-m-d'); ?>';
    document.getElementById('leaveStatus').value = record.status || 'approved';
    document.getElementById('leaveReason').value = record.reason || '';
    document.getElementById('leaveNotes').value = record.notes || '';

    document.getElementById('leaveModalTitleText').textContent = 'تعديل أجازة';
    document.getElementById('leaveModalIcon').className = 'fas fa-edit me-2';
    document.getElementById('leaveSubmitBtnText').textContent = 'حفظ التعديلات';
    var submitBtn = document.getElementById('leaveSubmitBtn');
    if (submitBtn) {
        submitBtn.name = 'edit_leave';
        submitBtn.className = 'btn btn-primary';
    }
    var modalContent = document.getElementById('leaveModalContent');
    if (modalContent) {
        modalContent.className = 'modal-content admin-modal admin-modal-premium admin-modal-edit';
    }
    var modalEl = document.getElementById('leaveModal');
    if (modalEl && window.bootstrap) {
        bootstrap.Modal.getOrCreateInstance(modalEl).show();
    }
}

function openDeleteLeaveModal(id, name) {
    document.getElementById('delete_id').value = id;
    document.getElementById('delete_name').textContent = name || '-';
    var modalEl = document.getElementById('deleteModal');
    if (modalEl && window.bootstrap) {
        bootstrap.Modal.getOrCreateInstance(modalEl).show();
    }
}

document.addEventListener('DOMContentLoaded', function() {
    <?php if ($editData): ?>
    openEditLeaveModal(<?php echo htmlspecialchars(json_encode($editData, JSON_HEX_APOS | JSON_HEX_QUOT), ENT_QUOTES, 'UTF-8'); ?>);
    if (window.history && window.history.replaceState) {
        window.history.replaceState({}, '', 'leaves.php');
    }
    <?php endif; ?>

    document.querySelectorAll('.delete-leave').forEach(function(btn) {
        btn.addEventListener('click', function() {
            openDeleteLeaveModal(this.dataset.id, this.dataset.name);
        });
    });

    if (typeof $ !== 'undefined' && $.fn.DataTable && !$.fn.DataTable.isDataTable('#leavesTable')) {
        $('#leavesTable').DataTable({
            pageLength: 50,
            order: [[3, 'desc']],
            language: {
                url: '//cdn.datatables.net/plug-ins/1.13.6/i18n/ar.json',
                search: "بحث:", lengthMenu: "عرض _MENU_ سجل",
                info: "عرض _START_ إلى _END_ من _TOTAL_ سجل",
                paginate: { first: "الأول", last: "الأخير", next: "التالي", previous: "السابق" }
            }
        });
    }
});
</script>

<?php require_once '../includes/admin_footer.php'; ?>
