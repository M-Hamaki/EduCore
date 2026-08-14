<?php
/**
 * إدارة أذونات الموظفين (انصراف مبكر، تأخير، مأمورية)
 */
$page_title = "الأذونات";
$custom_page_title = true;

require_once '../config/database.php';
require_once '../classes/utilities.php';
require_once '../includes/session_config.php';
require_once '../vendor/autoload.php';
require_once '../src/Modules/Operations/Audit/AuditService.php';
require_once '../src/Modules/Staff/bootstrap.php';
Utilities::validateSession('admin');

$database = new Database();
$db = $database->getConnection();
$staffModuleFactory = new \EduCore\Modules\Staff\Infrastructure\StaffModuleFactory(
    $db,
    new \EduCore\Modules\Operations\Audit\AuditService($db)
);
$permissionService = $staffModuleFactory->legacyPermissionCompatibility();

// Get messages from session
$success_message = $_SESSION['success_message'] ?? null;
$error_message = $_SESSION['error_message'] ?? null;
unset($_SESSION['success_message'], $_SESSION['error_message']);

$permissionTypes = [
    'early_leave' => 'انصراف مبكر',
    'late_arrival' => 'تأخير',
    'errand' => 'مأمورية'
];
$permissionBadges = [
    'early_leave' => 'info',
    'late_arrival' => 'warning',
    'errand' => 'primary'
];
$statusLabels = ['pending' => 'قيد المراجعة', 'approved' => 'موافق عليه', 'rejected' => 'مرفوض'];
$statusBadges = ['pending' => 'warning', 'approved' => 'success', 'rejected' => 'danger'];

// جلب قائمة كل الموظفين الفعّالين (وليس المعلمين/الأخصائيين فقط)
$staffList = $permissionService->getActiveStaffList();


// معالجة النماذج
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $csrfToken = $_POST['csrf_token'] ?? '';
    if (!hash_equals($_SESSION['csrf_token'] ?? '', $csrfToken)) {
        $_SESSION['error_message'] = 'خطأ في التحقق الأمني. يرجى إعادة المحاولة.';
        header('Location: permissions.php');
        exit();
    }

    try {
        $formMode = $_POST['permission_form_mode'] ?? '';
        if (isset($_POST['add_permission']) || $formMode === 'add') {
            $permissionService->savePermission($_POST, (int)($_SESSION['user_id'] ?? 0));
            $_SESSION['success_message'] = 'تم إضافة الإذن بنجاح';
        } elseif (isset($_POST['edit_permission']) || $formMode === 'edit') {
            $permissionService->savePermission($_POST, (int)($_SESSION['user_id'] ?? 0), (int)($_POST['id'] ?? 0));
            $_SESSION['success_message'] = 'تم تحديث الإذن بنجاح';
        } elseif (isset($_POST['delete_permission'])) {
            $permissionService->deletePermission((int)($_POST['id'] ?? 0));
            $_SESSION['success_message'] = 'تم حذف الإذن';
        }
    } catch (InvalidArgumentException $exception) {
        $_SESSION['error_message'] = $exception->getMessage();
    } catch (Throwable $exception) {
        $reference = 'PERM-' . strtoupper(substr(bin2hex(random_bytes(4)), 0, 8));
        error_log($reference . ' legacy permission adapter error: ' . $exception->getMessage());
        $_SESSION['error_message'] = 'تعذر تنفيذ عملية الإذن الآن. راجع البيانات ثم أعد المحاولة. مرجع المتابعة: ' . $reference;
    }

    header('Location: permissions.php');
    exit();
}

// جلب البيانات
$editData = null;
if (isset($_GET['action']) && $_GET['action'] === 'edit' && isset($_GET['id'])) {
    $editData = $permissionService->getPermissionById((int)$_GET['id']);
}

// فلاتر
$filterUser = $_GET['user_id'] ?? '';
$filterType = $_GET['type'] ?? '';
$filterStatus = $_GET['filter_status'] ?? '';
$filterFrom = $_GET['date_from'] ?? '';
$filterTo = $_GET['date_to'] ?? '';

$permissions = $permissionService->getPermissions([
    'user_id' => $filterUser,
    'type' => $filterType,
    'status' => $filterStatus,
    'date_from' => $filterFrom,
    'date_to' => $filterTo
]);

// إحصائيات
$permissionStats = $permissionService->getPermissionStats();
$permStats = $permissionStats['type_stats'];
$statusStats = $permissionStats['status_stats'];

$permissionSummaryCards = [
    [
        'value' => array_sum($permStats),
        'label' => 'إجمالي الأذونات',
        'icon' => 'fa-list-alt',
        'gradient' => '#3b82f6, #2563eb'
    ],
    [
        'value' => $permStats['early_leave'] ?? 0,
        'label' => 'انصراف مبكر',
        'icon' => 'fa-sign-out-alt',
        'gradient' => '#06b6d4, #0891b2'
    ],
    [
        'value' => $permStats['late_arrival'] ?? 0,
        'label' => 'تأخير',
        'icon' => 'fa-hourglass-half',
        'gradient' => '#f59e0b, #d97706'
    ],
    [
        'value' => $permStats['errand'] ?? 0,
        'label' => 'مأمورية',
        'icon' => 'fa-route',
        'gradient' => '#8b5cf6, #7c3aed'
    ],
    [
        'value' => $statusStats['approved'] ?? 0,
        'label' => 'موافق عليها',
        'icon' => 'fa-check-circle',
        'gradient' => '#10b981, #059669'
    ],
    [
        'value' => $statusStats['rejected'] ?? 0,
        'label' => 'مرفوضة',
        'icon' => 'fa-times-circle',
        'gradient' => '#ef4444, #dc2626'
    ],
];

require_once '../includes/admin_header.php';
require_once '../includes/widgets/hr_stat_cards.php';
?>

<!-- Page Header -->
<div class="admin-page-heading">
    <h1 class="h2"><i class="fas fa-clock me-2 text-primary"></i>أذونات الموظفين</h1>
    <div class="admin-top-actions no-print">
        <button type="button" class="btn btn-header-premium btn-success shadow-sm" id="openAddPermissionModal">
            <i class="fas fa-plus-circle me-1"></i>إضافة إذن
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
    <?php renderHrStatCards($permissionSummaryCards, 'row-cols-2 row-cols-md-3 row-cols-lg-6'); ?>
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
        <select class="form-select form-select-sm admin-inline-select-sm" name="type" aria-label="فلترة نوع الإذن">
            <option value="">كل الأنواع</option>
            <?php foreach ($permissionTypes as $k => $v): ?>
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
            <a href="permissions.php" class="btn btn-light btn-sm"><i class="fas fa-rotate-left me-1"></i>إعادة تعيين</a>
        <?php endif; ?>
    </div>
</form>

<!-- Table Surface -->
<div class="admin-list-surface mb-4">
    <?php if (count($permissions) > 0): ?>
    <div class="table-responsive admin-table-wrap">
        <table class="table table-hover table-striped admin-data-table" id="permissionsTable">
            <thead>
                <tr>
                    <th width="50">#</th>
                    <th>الموظف</th>
                    <th>نوع الإذن</th>
                    <th>التاريخ</th>
                    <th>من</th>
                    <th>إلى</th>
                    <th>السبب</th>
                    <th>الحالة</th>
                    <th class="text-center" width="120">الإجراءات</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($permissions as $i => $p): ?>
                <tr>
                    <td><?php echo $i + 1; ?></td>
                    <td class="fw-bold text-primary"><?php echo htmlspecialchars($p['staff_name']); ?></td>
                    <td><span class="badge bg-<?php echo $permissionBadges[$p['permission_type']] ?? 'secondary'; ?>"><?php echo $permissionTypes[$p['permission_type']] ?? $p['permission_type']; ?></span></td>
                    <td><?php echo htmlspecialchars($p['permission_date'], ENT_QUOTES, 'UTF-8'); ?></td>
                    <td><?php echo $p['time_from'] ? substr($p['time_from'], 0, 5) : '-'; ?></td>
                    <td><?php echo $p['time_to'] ? substr($p['time_to'], 0, 5) : '-'; ?></td>
                    <td><?php echo htmlspecialchars($p['reason'] ?? '-'); ?></td>
                    <td><span class="badge bg-<?php echo $statusBadges[$p['status']] ?? 'secondary'; ?>"><?php echo $statusLabels[$p['status']] ?? $p['status']; ?></span></td>
                    <td class="text-center actions-column admin-table-actions">
                        <button type="button"
                                class="btn btn-action-pills btn-edit me-1 edit-permission"
                                data-id="<?php echo (int)$p['id']; ?>"
                                data-user-id="<?php echo (int)$p['user_id']; ?>"
                                data-type="<?php echo htmlspecialchars($p['permission_type'], ENT_QUOTES, 'UTF-8'); ?>"
                                data-date="<?php echo htmlspecialchars($p['permission_date'], ENT_QUOTES, 'UTF-8'); ?>"
                                data-time-from="<?php echo htmlspecialchars($p['time_from'] ? substr($p['time_from'], 0, 5) : '', ENT_QUOTES, 'UTF-8'); ?>"
                                data-time-to="<?php echo htmlspecialchars($p['time_to'] ? substr($p['time_to'], 0, 5) : '', ENT_QUOTES, 'UTF-8'); ?>"
                                data-status="<?php echo htmlspecialchars($p['status'], ENT_QUOTES, 'UTF-8'); ?>"
                                data-reason="<?php echo htmlspecialchars((string)($p['reason'] ?? ''), ENT_QUOTES, 'UTF-8'); ?>"
                                data-notes="<?php echo htmlspecialchars((string)($p['notes'] ?? ''), ENT_QUOTES, 'UTF-8'); ?>"
                                data-bs-toggle="tooltip"
                                title="تعديل الإذن"><i class="fas fa-edit"></i></button>
                        <button type="button" class="btn btn-action-pills btn-delete delete-permission" data-id="<?php echo $p['id']; ?>" data-name="<?php echo htmlspecialchars($p['staff_name']); ?>" data-bs-toggle="tooltip" title="حذف الإذن"><i class="fas fa-trash"></i></button>
                    </td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
    <?php else: ?>
        <div class="alert alert-info m-3"><i class="fas fa-info-circle me-2"></i>لا توجد أذونات مسجلة.</div>
    <?php endif; ?>
</div>

<!-- Add/Edit Modal -->
<div class="modal fade" id="permissionModal" tabindex="-1" aria-labelledby="permissionModalLabel">
    <div class="modal-dialog modal-lg">
        <div class="modal-content admin-modal admin-modal-premium admin-modal-view">
            <form method="POST" id="permissionForm">
                <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($_SESSION['csrf_token'] ?? '', ENT_QUOTES, 'UTF-8'); ?>">
                <input type="hidden" name="id" id="permission_id" value="">
                <input type="hidden" name="permission_form_mode" id="permission_form_mode" value="add">
                <div class="modal-header">
                    <h5 class="modal-title" id="permissionModalLabel"><i class="fas fa-plus-circle me-2"></i>إضافة إذن جديد</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <div class="row g-3">
                        <div class="col-md-4">
                            <label class="form-label">الموظف <span class="text-danger">*</span></label>
                            <select class="form-select" name="user_id" id="permission_user_id" required>
                                <option value="">اختر الموظف...</option>
                                <?php foreach ($staffList as $s): ?>
                                    <option value="<?php echo $s['id']; ?>"><?php echo htmlspecialchars($s['name']); ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-md-3">
                            <label class="form-label">نوع الإذن <span class="text-danger">*</span></label>
                            <select class="form-select" name="permission_type" id="permission_type" required>
                                <?php foreach ($permissionTypes as $k => $v): ?>
                                    <option value="<?php echo $k; ?>"><?php echo $v; ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-md-3">
                            <label class="form-label">التاريخ <span class="text-danger">*</span></label>
                            <input type="text" class="form-control flatpickr-date" name="permission_date" id="permission_date" value="<?php echo date('Y-m-d'); ?>" required>
                        </div>
                        <div class="col-md-2">
                            <label class="form-label">الحالة</label>
                            <select class="form-select" name="status" id="permission_status">
                                <?php foreach ($statusLabels as $k => $v): ?>
                                    <option value="<?php echo $k; ?>" <?php echo $k === 'approved' ? 'selected' : ''; ?>><?php echo $v; ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-md-3">
                            <label class="form-label">من الساعة</label>
                            <input type="time" class="form-control" name="time_from" id="permission_time_from" value="">
                        </div>
                        <div class="col-md-3">
                            <label class="form-label">إلى الساعة</label>
                            <input type="time" class="form-control" name="time_to" id="permission_time_to" value="">
                        </div>
                        <div class="col-md-6">
                            <label class="form-label">السبب</label>
                            <input type="text" class="form-control" name="reason" id="permission_reason" value="">
                        </div>
                        <div class="col-md-12">
                            <label class="form-label">ملاحظات</label>
                            <input type="text" class="form-control" name="notes" id="permission_notes" value="">
                        </div>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal"><i class="fas fa-times me-1"></i>إلغاء</button>
                    <button type="submit" class="btn btn-success" id="permissionSubmitButton"><i class="fas fa-save me-1"></i>إضافة الإذن</button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- Delete Modal -->
<div class="modal fade" id="deleteModal" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content admin-modal admin-modal-premium admin-modal-delete">
            <div class="modal-header">
                <h5 class="modal-title"><i class="fas fa-trash me-2"></i>حذف إذن</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body text-center">
                <i class="fas fa-exclamation-triangle text-warning fa-3x mb-3"></i>
                <p>هل أنت متأكد من حذف إذن <strong id="delete_name"></strong>؟</p>
            </div>
            <div class="modal-footer">
                <form method="POST">
                    <input type="hidden" name="id" id="delete_id">
                    <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($_SESSION['csrf_token'] ?? '', ENT_QUOTES, 'UTF-8'); ?>">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">إلغاء</button>
                    <button type="submit" name="delete_permission" class="btn btn-danger">حذف</button>
                </form>
            </div>
        </div>
    </div>
</div>

<script>
document.addEventListener('DOMContentLoaded', function() {
    var permissionModalElement = document.getElementById('permissionModal');
    var permissionModal = new bootstrap.Modal(permissionModalElement);
    var permissionModalLabel = document.getElementById('permissionModalLabel');
    var permissionFormMode = document.getElementById('permission_form_mode');
    var permissionId = document.getElementById('permission_id');
    var permissionUserId = document.getElementById('permission_user_id');
    var permissionType = document.getElementById('permission_type');
    var permissionDate = document.getElementById('permission_date');
    var permissionTimeFrom = document.getElementById('permission_time_from');
    var permissionTimeTo = document.getElementById('permission_time_to');
    var permissionStatus = document.getElementById('permission_status');
    var permissionReason = document.getElementById('permission_reason');
    var permissionNotes = document.getElementById('permission_notes');
    var permissionSubmitButton = document.getElementById('permissionSubmitButton');

    function fillPermissionForm(mode, data) {
        permissionFormMode.value = mode;
        permissionId.value = data.id || '';
        permissionUserId.value = data.userId || '';
        permissionType.value = data.type || 'early_leave';
        permissionDate.value = data.date || '<?php echo date('Y-m-d'); ?>';
        permissionTimeFrom.value = data.timeFrom || '';
        permissionTimeTo.value = data.timeTo || '';
        permissionStatus.value = data.status || 'approved';
        permissionReason.value = data.reason || '';
        permissionNotes.value = data.notes || '';

        if (mode === 'edit') {
            permissionModalLabel.innerHTML = '<i class="fas fa-edit me-2"></i>تعديل إذن';
            permissionSubmitButton.innerHTML = '<i class="fas fa-save me-1"></i>حفظ التعديلات';
        } else {
            permissionModalLabel.innerHTML = '<i class="fas fa-plus-circle me-2"></i>إضافة إذن جديد';
            permissionSubmitButton.innerHTML = '<i class="fas fa-save me-1"></i>إضافة الإذن';
        }
    }

    document.getElementById('openAddPermissionModal').addEventListener('click', function() {
        fillPermissionForm('add', {});
        permissionModal.show();
    });

    document.querySelectorAll('.edit-permission').forEach(function(btn) {
        btn.addEventListener('click', function() {
            fillPermissionForm('edit', {
                id: this.dataset.id,
                userId: this.dataset.userId,
                type: this.dataset.type,
                date: this.dataset.date,
                timeFrom: this.dataset.timeFrom,
                timeTo: this.dataset.timeTo,
                status: this.dataset.status,
                reason: this.dataset.reason,
                notes: this.dataset.notes
            });
            permissionModal.show();
        });
    });

    document.querySelectorAll('.delete-permission').forEach(function(btn) {
        btn.addEventListener('click', function() {
            document.getElementById('delete_id').value = this.dataset.id;
            document.getElementById('delete_name').textContent = this.dataset.name;
            new bootstrap.Modal(document.getElementById('deleteModal')).show();
        });
    });

    if (typeof $ !== 'undefined' && $.fn.DataTable && !$.fn.DataTable.isDataTable('#permissionsTable')) {
        $('#permissionsTable').DataTable({
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

    <?php if ($editData): ?>
    fillPermissionForm('edit', {
        id: '<?php echo (int)$editData['id']; ?>',
        userId: '<?php echo (int)$editData['user_id']; ?>',
        type: '<?php echo htmlspecialchars($editData['permission_type'], ENT_QUOTES, 'UTF-8'); ?>',
        date: '<?php echo htmlspecialchars($editData['permission_date'], ENT_QUOTES, 'UTF-8'); ?>',
        timeFrom: '<?php echo htmlspecialchars(!empty($editData['time_from']) ? substr($editData['time_from'], 0, 5) : '', ENT_QUOTES, 'UTF-8'); ?>',
        timeTo: '<?php echo htmlspecialchars(!empty($editData['time_to']) ? substr($editData['time_to'], 0, 5) : '', ENT_QUOTES, 'UTF-8'); ?>',
        status: '<?php echo htmlspecialchars($editData['status'] ?? 'approved', ENT_QUOTES, 'UTF-8'); ?>',
        reason: '<?php echo htmlspecialchars((string)($editData['reason'] ?? ''), ENT_QUOTES, 'UTF-8'); ?>',
        notes: '<?php echo htmlspecialchars((string)($editData['notes'] ?? ''), ENT_QUOTES, 'UTF-8'); ?>'
    });
    permissionModal.show();
    if (window.history && window.history.replaceState) {
        window.history.replaceState({}, '', 'permissions.php');
    }
    <?php endif; ?>
});
</script>

<?php require_once '../includes/admin_footer.php'; ?>
