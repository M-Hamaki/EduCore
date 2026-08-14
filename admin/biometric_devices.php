<?php
/**
 * إدارة أجهزة البصمة - ربط ومزامنة أجهزة ZKTeco
 */
$page_title = "أجهزة البصمة";
$custom_page_title = true;

require_once '../config/database.php';
require_once '../classes/ZKTecoDevice.php';
require_once '../classes/StaffAttendanceService.php';
require_once '../classes/StaffBiometricIdentityService.php';
require_once '../classes/StaffProfileErrorPresenter.php';
require_once '../classes/SchemaReadinessGuard.php';
require_once '../classes/utilities.php';
require_once '../includes/session_config.php';
require_once '../includes/csrf.php';
require_once '../src/Modules/Operations/Audit/AuditService.php';
Utilities::validateSession('admin');
requireCsrfPost();

$database = new Database();
$db = $database->getConnection();
$biometricIdentityService = new StaffBiometricIdentityService($db);

(new SchemaReadinessGuard($db))->assertColumns('biometric_devices', ['comm_password', 'protocol']);
(new SchemaReadinessGuard($db))->assertTable('biometric_sync_log');

$attendanceService = new StaffAttendanceService($db);
$attendanceService->ensureBiometricTables();
$attendanceService->ensureEmployeeCodeColumn();

$success_message = $_SESSION['success_message'] ?? null;
$error_message = $_SESSION['error_message'] ?? null;
unset($_SESSION['success_message'], $_SESSION['error_message']);

// معالجة POST
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    if ($action === 'add_device' || $action === 'edit_device') {
        $deviceId = (int)($_POST['device_id'] ?? 0);
        $deviceName = trim($_POST['device_name'] ?? '');
        $ipAddress = trim($_POST['ip_address'] ?? '');
        $port = (int)($_POST['port'] ?? 4370);
        $commPassword = (int)($_POST['comm_password'] ?? 0);
        $protocol = $_POST['protocol'] ?? 'auto';
        if (!in_array($protocol, ['auto', 'TCP', 'UDP'])) $protocol = 'auto';
        $model = trim($_POST['model'] ?? '');
        if ($model === 'أخرى') {
            $customModel = trim($_POST['custom_model'] ?? '');
            $model = $customModel !== '' ? $customModel : '';
        }
        $locationName = trim($_POST['location_name'] ?? '');
        $serialNumber = trim($_POST['serial_number'] ?? '');
        $isActive = isset($_POST['is_active']) ? 1 : 0;
        $autoSync = isset($_POST['auto_sync']) ? 1 : 0;
        $clearAfterSync = isset($_POST['clear_after_sync']) ? 1 : 0;

        if ($deviceName === '' || $ipAddress === '') {
            $_SESSION['error_message'] = 'اسم الجهاز وعنوان IP مطلوبان';
        } elseif (!filter_var($ipAddress, FILTER_VALIDATE_IP)) {
            $_SESSION['error_message'] = 'عنوان IP غير صالح';
        } elseif ($port < 1 || $port > 65535) {
            $_SESSION['error_message'] = 'رقم المنفذ يجب أن يكون بين 1 و 65535';
        } else {
            try {
                $db->beginTransaction();
                $before = null;
                if ($action === 'edit_device') {
                    $beforeStmt = $db->prepare('SELECT * FROM biometric_devices WHERE id = ? FOR UPDATE');
                    $beforeStmt->execute([$deviceId]);
                    $before = $beforeStmt->fetch(PDO::FETCH_ASSOC);
                    if (!$before) {
                        throw new RuntimeException('Biometric device not found.');
                    }
                }
                if ($action === 'add_device') {
                    $stmt = $db->prepare("INSERT INTO biometric_devices (device_name, serial_number, ip_address, port, comm_password, protocol, model, location_name, is_active, auto_sync, clear_after_sync) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
                    $stmt->execute([$deviceName, $serialNumber ?: null, $ipAddress, $port, $commPassword, $protocol, $model ?: null, $locationName ?: null, $isActive, $autoSync, $clearAfterSync]);
                    $deviceId = (int)$db->lastInsertId();
                    $_SESSION['success_message'] = 'تم إضافة الجهاز بنجاح';
                } else {
                    $stmt = $db->prepare("UPDATE biometric_devices SET device_name=?, serial_number=?, ip_address=?, port=?, comm_password=?, protocol=?, model=?, location_name=?, is_active=?, auto_sync=?, clear_after_sync=? WHERE id=?");
                    $stmt->execute([$deviceName, $serialNumber ?: null, $ipAddress, $port, $commPassword, $protocol, $model ?: null, $locationName ?: null, $isActive, $autoSync, $clearAfterSync, $deviceId]);
                    $_SESSION['success_message'] = 'تم تحديث بيانات الجهاز بنجاح';
                }
                $afterStmt = $db->prepare('SELECT * FROM biometric_devices WHERE id = ?');
                $afterStmt->execute([$deviceId]);
                $after = $afterStmt->fetch(PDO::FETCH_ASSOC) ?: [];
                (new \EduCore\Modules\Operations\Audit\AuditService($db))->recordEvent(
                    $action === 'add_device' ? 'create' : 'update',
                    'biometric_device', $deviceId, $deviceName,
                    [
                        'changes' => \EduCore\Modules\Operations\Audit\EntityChangeTracker::diff($before ?: [], $after),
                        'undo_policy' => 'credential_bearing_device_restore_not_enabled',
                    ]
                );
                $db->commit();
            } catch (Throwable $e) {
                if ($db->inTransaction()) {
                    $db->rollBack();
                }
                if (strpos($e->getMessage(), 'Duplicate') !== false) {
                    $_SESSION['error_message'] = 'يوجد جهاز مسجل بنفس عنوان IP والمنفذ';
                } else {
                    $_SESSION['error_message'] = 'حدث خطأ أثناء حفظ بيانات الجهاز';
                    error_log('Biometric device save error: ' . $e->getMessage());
                }
            }
        }
        header('Location: biometric_devices.php');
        exit();
    }

    if ($action === 'delete_device') {
        $deviceId = (int)($_POST['device_id'] ?? 0);
        if ($deviceId > 0) {
            try {
                $db->beginTransaction();
                $beforeStmt = $db->prepare('SELECT * FROM biometric_devices WHERE id = ? FOR UPDATE');
                $beforeStmt->execute([$deviceId]);
                $before = $beforeStmt->fetch(PDO::FETCH_ASSOC);
                if (!$before) {
                    throw new RuntimeException('Biometric device not found.');
                }
                $countStmt = $db->prepare('SELECT COUNT(*) FROM biometric_sync_log WHERE device_id = ?');
                $countStmt->execute([$deviceId]);
                $syncLogCount = (int)$countStmt->fetchColumn();
                $db->prepare("DELETE FROM biometric_sync_log WHERE device_id = ?")->execute([$deviceId]);
                $db->prepare("DELETE FROM biometric_devices WHERE id = ?")->execute([$deviceId]);
                (new \EduCore\Modules\Operations\Audit\AuditService($db))->recordEvent(
                    'delete', 'biometric_device', $deviceId,
                    (string)($before['device_name'] ?? ('جهاز #' . $deviceId)),
                    [
                        'before' => $before,
                        'deleted_sync_log_count' => $syncLogCount,
                        'undo_policy' => 'credential_bearing_composite_restore_not_enabled',
                    ]
                );
                $db->commit();
                $_SESSION['success_message'] = 'تم حذف الجهاز بنجاح';
            } catch (Throwable $e) {
                if ($db->inTransaction()) {
                    $db->rollBack();
                }
                error_log('Biometric device delete error: ' . $e->getMessage());
                $_SESSION['error_message'] = 'تعذر حذف الجهاز.';
            }
        }
        header('Location: biometric_devices.php');
        exit();
    }

    if ($action === 'update_employee_code') {
        $userId = (int)($_POST['user_id'] ?? 0);
        $empCode = trim($_POST['employee_code'] ?? '');
        if ($userId > 0) {
            try {
                $db->beginTransaction();
                $sync = $biometricIdentityService->synchronizeWithinTransaction(
                    $userId,
                    $empCode
                );
                (new \EduCore\Modules\Operations\Audit\AuditService($db))->recordCompositeUpdate(
                    'staff_biometric_identity',
                    $userId,
                    (string)($sync['user_name'] ?? ('عامل #' . $userId)),
                    [
                        [
                            'table' => 'staff_profiles',
                            'record_id' => (int)($sync['before_profile']['id'] ?? 0),
                            'before' => $sync['before_profile'],
                            'after' => $sync['after_profile'],
                            'description' => 'تحديث رقم البصمة في ملف العامل',
                        ],
                    ],
                    ['summary' => 'تحديث رقم البصمة المستقل للعامل']
                );
                $db->commit();
                $_SESSION['success_message'] = 'تم تحديث رقم البصمة بنجاح';
            } catch (Throwable $e) {
                if ($db->inTransaction()) {
                    $db->rollBack();
                }
                $_SESSION['error_message'] = StaffProfileErrorPresenter::saveMessage(
                    $e,
                    'biometric_update'
                );
            }
        }
        header('Location: biometric_devices.php#mapping');
        exit();
    }

    header('Location: biometric_devices.php');
    exit();
}

// جلب بيانات الأجهزة
$devices = $db->query("SELECT * FROM biometric_devices ORDER BY is_active DESC, device_name ASC")->fetchAll(PDO::FETCH_ASSOC);

// إحصائيات
$totalDevices = count($devices);
$activeDevices = count(array_filter($devices, fn($d) => $d['is_active']));
$totalSynced = array_sum(array_column($devices, 'total_synced_records'));
$lastSync = null;
foreach ($devices as $d) {
    if ($d['last_sync_at'] && ($lastSync === null || $d['last_sync_at'] > $lastSync)) {
        $lastSync = $d['last_sync_at'];
    }
}

// جلب قائمة الموظفين مع أكواد التعريف
$staffList = $db->query(
    "SELECT u.id, u.name,
            NULLIF(TRIM(sp.biometric_id), '') AS employee_code,
            u.role
     FROM users u
     INNER JOIN staff_profiles sp ON sp.user_id = u.id
     WHERE COALESCE(u.role, '') <> 'student' AND u.status = 'active'
     ORDER BY u.name"
)->fetchAll(PDO::FETCH_ASSOC);

// جلب آخر سجلات المزامنة
$syncLogs = $db->query("SELECT sl.*, bd.device_name, bd.ip_address FROM biometric_sync_log sl JOIN biometric_devices bd ON bd.id = sl.device_id ORDER BY sl.created_at DESC LIMIT 50")->fetchAll(PDO::FETCH_ASSOC);

$activeTab = $_GET['tab'] ?? 'devices';
$validTabs = ['devices', 'mapping', 'logs'];
if (!in_array($activeTab, $validTabs)) $activeTab = 'devices';

require_once '../includes/admin_header.php';
?>

<style>
.device-status { display:inline-flex; align-items:center; gap:4px; }
.device-status .dot { width:10px; height:10px; border-radius:50%; display:inline-block; }
.device-status .dot.online { background:#10b981; box-shadow:0 0 6px rgba(16,185,129,0.5); }
.device-status .dot.offline { background:#ef4444; }
.device-status .dot.unknown { background:#94a3b8; }
.nav-pills .nav-link { color:#475569; font-weight:600; border-radius:10px; padding:8px 20px; }
.nav-pills .nav-link.active { background: linear-gradient(135deg, #3b82f6, #2563eb); color:#fff; }
.nav-pills .nav-link.active .badge { background-color: #fff !important; color: #2563eb !important; }
.sync-badge { font-size:0.75rem; padding:4px 8px; }
</style>

<div class="d-flex justify-content-between flex-wrap flex-md-nowrap align-items-center pt-3 pb-2 mb-3 border-bottom">
    <h1 class="h2"><i class="fas fa-fingerprint me-2"></i>أجهزة البصمة</h1>
    <div class="admin-top-actions no-print">
        <button class="btn btn-header-premium btn-success shadow-sm" data-bs-toggle="modal" data-bs-target="#addDeviceModal">
            <i class="fas fa-plus me-1"></i>إضافة جهاز
        </button>
        <button class="btn btn-header-premium btn-outline-primary" onclick="syncAllDevices()">
            <i class="fas fa-sync me-1"></i>مزامنة الكل
        </button>
    </div>
</div>

<?php if ($success_message): ?>
<div class="alert alert-success alert-dismissible fade show" role="alert">
    <i class="fas fa-check-circle me-2"></i><?php echo htmlspecialchars($success_message, ENT_QUOTES, 'UTF-8'); ?>
    <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
</div>
<?php endif; ?>

<?php if ($error_message): ?>
<div class="alert alert-danger alert-dismissible fade show" role="alert">
    <i class="fas fa-exclamation-circle me-2"></i><?php echo htmlspecialchars($error_message, ENT_QUOTES, 'UTF-8'); ?>
    <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
</div>
<?php endif; ?>

<!-- بطاقات الإحصائيات -->
<div class="row row-cols-2 row-cols-md-4 g-3 mb-4">
    <div class="col">
        <div class="stat-card" style="--card-gradient: linear-gradient(135deg, #3b82f6, #2563eb);">
            <div class="stat-card-icon"><i class="fas fa-server"></i></div>
            <div class="stat-card-info">
                <div class="stat-card-number counter" data-target="<?php echo $totalDevices; ?>">0</div>
                <div class="stat-card-label">إجمالي الأجهزة</div>
            </div>
        </div>
    </div>
    <div class="col">
        <div class="stat-card" style="--card-gradient: linear-gradient(135deg, #10b981, #059669);">
            <div class="stat-card-icon"><i class="fas fa-wifi"></i></div>
            <div class="stat-card-info">
                <div class="stat-card-number counter" data-target="<?php echo $activeDevices; ?>">0</div>
                <div class="stat-card-label">أجهزة مفعّلة</div>
            </div>
        </div>
    </div>
    <div class="col">
        <div class="stat-card" style="--card-gradient: linear-gradient(135deg, #8b5cf6, #7c3aed);">
            <div class="stat-card-icon"><i class="fas fa-database"></i></div>
            <div class="stat-card-info">
                <div class="stat-card-number counter" data-target="<?php echo (int)$totalSynced; ?>">0</div>
                <div class="stat-card-label">سجلات تمت مزامنتها</div>
            </div>
        </div>
    </div>
    <div class="col">
        <div class="stat-card" style="--card-gradient: linear-gradient(135deg, #f59e0b, #d97706);">
            <div class="stat-card-icon"><i class="fas fa-clock"></i></div>
            <div class="stat-card-info">
                <div class="stat-card-number" style="font-size:1rem;"><?php echo $lastSync ? date('H:i', strtotime($lastSync)) : '--'; ?></div>
                <div class="stat-card-label">آخر مزامنة<?php echo $lastSync ? ' (' . date('m/d', strtotime($lastSync)) . ')' : ''; ?></div>
            </div>
        </div>
    </div>
</div>

<!-- التبويبات -->
<ul class="nav nav-pills mb-3" role="tablist">
    <li class="nav-item">
        <a class="nav-link <?php echo $activeTab === 'devices' ? 'active' : ''; ?>" data-bs-toggle="pill" href="#pane-devices">
            الأجهزة <span class="badge rounded-pill bg-primary ms-1"><?php echo $totalDevices; ?></span>
        </a>
    </li>
    <li class="nav-item">
        <a class="nav-link <?php echo $activeTab === 'mapping' ? 'active' : ''; ?>" data-bs-toggle="pill" href="#pane-mapping">
            ربط الموظفين <span class="badge rounded-pill bg-primary ms-1"><?php echo count($staffList); ?></span>
        </a>
    </li>
    <li class="nav-item">
        <a class="nav-link <?php echo $activeTab === 'logs' ? 'active' : ''; ?>" data-bs-toggle="pill" href="#pane-logs">
            سجل المزامنة <span class="badge rounded-pill bg-primary ms-1"><?php echo count($syncLogs); ?></span>
        </a>
    </li>
</ul>

<div class="tab-content">
<!-- ====== تبويب الأجهزة ====== -->
<div class="tab-pane fade <?php echo $activeTab === 'devices' ? 'show active' : ''; ?>" id="pane-devices">
<div class="card shadow">
    <div class="card-header bg-primary text-white">
        <h5 class="mb-0"><i class="fas fa-list me-2"></i>قائمة الأجهزة</h5>
    </div>
    <div class="card-body">
        <?php if (empty($devices)): ?>
            <div class="alert alert-info mb-0">
                <i class="fas fa-info-circle me-2"></i>لم يتم إضافة أي أجهزة بعد.
                <button class="btn btn-sm btn-success ms-2" data-bs-toggle="modal" data-bs-target="#addDeviceModal">
                    <i class="fas fa-plus me-1"></i>إضافة جهاز الآن
                </button>
            </div>
        <?php else: ?>
            <div class="table-responsive">
                <table class="table table-hover table-striped" id="devicesTable">
                    <thead>
                        <tr>
                            <th>#</th>
                            <th>الجهاز</th>
                            <th>عنوان IP</th>
                            <th>المنفذ</th>
                            <th>البروتوكول</th>
                            <th>الموديل</th>
                            <th>الموقع</th>
                            <th>الحالة</th>
                            <th>آخر مزامنة</th>
                            <th>السجلات</th>
                            <th>إجراءات</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($devices as $i => $device): ?>
                        <tr>
                            <td><?php echo $i + 1; ?></td>
                            <td>
                                <strong><?php echo htmlspecialchars($device['device_name'], ENT_QUOTES, 'UTF-8'); ?></strong>
                                <?php if ($device['serial_number']): ?>
                                    <br><small class="text-muted">SN: <?php echo htmlspecialchars($device['serial_number'], ENT_QUOTES, 'UTF-8'); ?></small>
                                <?php endif; ?>
                            </td>
                            <td><code><?php echo htmlspecialchars($device['ip_address'], ENT_QUOTES, 'UTF-8'); ?></code></td>
                            <td><?php echo (int)$device['port']; ?></td>
                            <td><span class="badge bg-secondary"><?php echo htmlspecialchars($device['protocol'] ?? 'auto', ENT_QUOTES, 'UTF-8'); ?></span></td>
                            <td><?php echo htmlspecialchars($device['model'] ?: '-', ENT_QUOTES, 'UTF-8'); ?></td>
                            <td><?php echo htmlspecialchars($device['location_name'] ?: '-', ENT_QUOTES, 'UTF-8'); ?></td>
                            <td>
                                <?php if ($device['is_active']): ?>
                                    <span class="device-status"><span class="dot online"></span> مفعّل</span>
                                <?php else: ?>
                                    <span class="device-status"><span class="dot offline"></span> معطّل</span>
                                <?php endif; ?>
                                <?php if ($device['auto_sync']): ?>
                                    <br><span class="badge bg-info sync-badge">مزامنة تلقائية</span>
                                <?php endif; ?>
                            </td>
                            <td>
                                <?php if ($device['last_sync_at']): ?>
                                    <?php echo date('Y-m-d H:i', strtotime($device['last_sync_at'])); ?>
                                    <br>
                                    <?php if ($device['last_sync_status'] === 'success'): ?>
                                        <span class="badge bg-success sync-badge">نجاح</span>
                                    <?php elseif ($device['last_sync_status'] === 'error'): ?>
                                        <span class="badge bg-danger sync-badge">خطأ</span>
                                    <?php else: ?>
                                        <span class="badge bg-warning sync-badge">جزئي</span>
                                    <?php endif; ?>
                                <?php else: ?>
                                    <span class="text-muted">لم تتم</span>
                                <?php endif; ?>
                            </td>
                            <td>
                                <span class="badge bg-primary"><?php echo number_format($device['total_synced_records']); ?></span>
                            </td>
                            <td>
                                <button class="btn btn-sm btn-outline-info me-1" onclick="testDevice(<?php echo (int)$device['id']; ?>)" data-bs-toggle="tooltip" title="اختبار الاتصال">
                                    <i class="fas fa-plug"></i>
                                </button>
                                <button class="btn btn-sm btn-outline-success me-1" onclick="syncDevice(<?php echo (int)$device['id']; ?>)" data-bs-toggle="tooltip" title="مزامنة الآن">
                                    <i class="fas fa-sync"></i>
                                </button>
                                <button class="btn btn-action-pills btn-edit me-1" onclick="editDevice(<?php echo htmlspecialchars(json_encode($device), ENT_QUOTES, 'UTF-8'); ?>)" data-bs-toggle="tooltip" title="تعديل">
                                    <i class="fas fa-edit"></i>
                                </button>
                                <button class="btn btn-sm btn-outline-warning me-1" onclick="getEnrolledUsers(<?php echo (int)$device['id']; ?>)" data-bs-toggle="tooltip" title="المستخدمون على الجهاز">
                                    <i class="fas fa-users"></i>
                                </button>
                                <button class="btn btn-action-pills btn-delete" onclick="confirmDeleteDevice(<?php echo (int)$device['id']; ?>, '<?php echo htmlspecialchars($device['device_name'], ENT_QUOTES, 'UTF-8'); ?>')" data-bs-toggle="tooltip" title="حذف">
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
</div>
</div>

<!-- ====== تبويب ربط الموظفين ====== -->
<div class="tab-pane fade <?php echo $activeTab === 'mapping' ? 'show active' : ''; ?>" id="pane-mapping">
<div class="card shadow">
    <div class="card-header bg-primary text-white">
        <div class="row align-items-center">
            <div class="col-md-4">
                <h5 class="mb-0"><i class="fas fa-link me-2"></i>ربط الموظفين</h5>
            </div>
            <div class="col-md-8">
                <div class="d-flex justify-content-end align-items-center gap-2 flex-wrap">
                    <select id="mappingDeviceSelect" class="form-select form-select-sm" style="width:auto; min-width:180px;">
                        <option value="">-- اختر جهاز --</option>
                        <?php foreach ($devices as $d): if ($d['is_active']): ?>
                        <option value="<?php echo (int)$d['id']; ?>"><?php echo htmlspecialchars($d['device_name'] . ' (' . $d['ip_address'] . ')', ENT_QUOTES, 'UTF-8'); ?></option>
                        <?php endif; endforeach; ?>
                    </select>
                    <button type="button" class="btn btn-light btn-sm" onclick="loadDeviceUsersForMapping()" id="btnLoadUsers">
                        <i class="fas fa-download me-1"></i>جلب المستخدمين
                    </button>
                    <button type="button" class="btn btn-light btn-sm" onclick="autoMatchUsers()" id="btnAutoMatch" disabled>
                        <i class="fas fa-magic me-1"></i>ربط تلقائي
                    </button>
                    <button type="button" class="btn btn-success btn-sm" onclick="bulkSaveMappings()">
                        <i class="fas fa-save me-1"></i>حفظ الكل
                    </button>
                </div>
            </div>
        </div>
    </div>
    <div class="card-body">
        <div id="mappingLoadStatus"></div>
        <div class="alert alert-info mb-3">
            <i class="fas fa-info-circle me-2"></i>
            <strong>كيفية الربط:</strong> اختر جهاز بصمة ثم اضغط "جلب المستخدمين" لتحميل قائمة المسجلين على الجهاز.
            ستظهر اقتراحات تلقائية عند الكتابة في حقل "رقم البصمة". يمكنك أيضاً استخدام "ربط تلقائي" لمطابقة الأسماء تلقائياً.
        </div>
        <datalist id="deviceUsersList"></datalist>

        <div class="table-responsive">
            <table class="table table-hover table-striped" id="mappingTable">
                <thead>
                    <tr>
                        <th>#</th>
                        <th>اسم الموظف</th>
                        <th>الدور</th>
                        <th>رقم البصمة (ID على الجهاز)</th>
                        <th>الحالة</th>
                        <th>إجراء</th>
                    </tr>
                </thead>
                <tbody>
                    <?php
                    $roleLabels = ['admin' => 'مدير', 'teacher' => 'معلم', 'specialist' => 'أخصائي'];
                    foreach ($staffList as $i => $staff):
                    ?>
                    <tr>
                        <td><?php echo $i + 1; ?></td>
                        <td><?php echo htmlspecialchars($staff['name'], ENT_QUOTES, 'UTF-8'); ?></td>
                        <td><span class="badge bg-secondary"><?php echo $roleLabels[$staff['role']] ?? $staff['role']; ?></span></td>
                        <td>
<form method="POST" class="d-flex align-items-center gap-2" style="min-width:200px;">
    <?php echo csrfField(); ?>
                                <input type="hidden" name="action" value="update_employee_code">
                                <input type="hidden" name="user_id" value="<?php echo (int)$staff['id']; ?>">
                                <input type="text" name="employee_code" class="form-control form-control-sm emp-code-input"
                                       style="width:160px;" list="deviceUsersList"
                                       data-user-id="<?php echo (int)$staff['id']; ?>"
                                       data-staff-name="<?php echo htmlspecialchars($staff['name'], ENT_QUOTES, 'UTF-8'); ?>"
                                       data-original="<?php echo htmlspecialchars($staff['employee_code'] ?? '', ENT_QUOTES, 'UTF-8'); ?>"
                                       value="<?php echo htmlspecialchars($staff['employee_code'] ?? '', ENT_QUOTES, 'UTF-8'); ?>"
                                       placeholder="اكتب أو اختر من القائمة">
                                <button type="submit" class="btn btn-sm btn-outline-primary" data-bs-toggle="tooltip" title="حفظ">
                                    <i class="fas fa-save"></i>
                                </button>
                            </form>
                        </td>
                        <td>
                            <?php if (!empty($staff['employee_code'])): ?>
                                <span class="badge bg-success"><i class="fas fa-check me-1"></i>مربوط</span>
                            <?php else: ?>
                                <span class="badge bg-warning text-dark"><i class="fas fa-exclamation me-1"></i>غير مربوط</span>
                            <?php endif; ?>
                        </td>
                        <td>
                            <?php if (!empty($staff['employee_code'])): ?>
                                <button type="button" class="btn btn-action-pills btn-delete" data-bs-toggle="tooltip" title="إزالة الربط"
                                        onclick="confirmUnlinkEmployee(<?php echo (int)$staff['id']; ?>, '<?php echo htmlspecialchars($staff['name'], ENT_QUOTES, 'UTF-8'); ?>')">
                                    <i class="fas fa-unlink"></i>
                                </button>
                            <?php endif; ?>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>
</div>

<!-- ====== تبويب سجل المزامنة ====== -->
<div class="tab-pane fade <?php echo $activeTab === 'logs' ? 'show active' : ''; ?>" id="pane-logs">
<div class="card shadow">
    <div class="card-header bg-primary text-white">
        <h5 class="mb-0"><i class="fas fa-history me-2"></i>سجل عمليات المزامنة</h5>
    </div>
    <div class="card-body">
        <?php if (empty($syncLogs)): ?>
            <div class="alert alert-info mb-0"><i class="fas fa-info-circle me-2"></i>لا توجد عمليات مزامنة بعد.</div>
        <?php else: ?>
            <div class="table-responsive">
                <table class="table table-hover table-striped" id="syncLogsTable">
                    <thead>
                        <tr>
                            <th>#</th>
                            <th>الجهاز</th>
                            <th>النوع</th>
                            <th>البدء</th>
                            <th>الانتهاء</th>
                            <th>الحالة</th>
                            <th>إجمالي</th>
                            <th>جديد</th>
                            <th>مكرر</th>
                            <th>غير مربوط</th>
                            <th>حضور</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($syncLogs as $i => $log): ?>
                        <tr>
                            <td><?php echo $i + 1; ?></td>
                            <td>
                                <?php echo htmlspecialchars($log['device_name'], ENT_QUOTES, 'UTF-8'); ?>
                                <br><small class="text-muted"><?php echo htmlspecialchars($log['ip_address'], ENT_QUOTES, 'UTF-8'); ?></small>
                            </td>
                            <td>
                                <?php
                                $typeLabels = ['manual' => 'يدوي', 'auto' => 'تلقائي', 'cron' => 'مجدول'];
                                echo $typeLabels[$log['sync_type']] ?? $log['sync_type'];
                                ?>
                            </td>
                            <td><?php echo $log['started_at']; ?></td>
                            <td><?php echo $log['completed_at'] ?: '-'; ?></td>
                            <td>
                                <?php if ($log['status'] === 'success'): ?>
                                    <span class="badge bg-success">نجاح</span>
                                <?php elseif ($log['status'] === 'error'): ?>
                                    <span class="badge bg-danger">خطأ</span>
                                <?php else: ?>
                                    <span class="badge bg-warning">جزئي</span>
                                <?php endif; ?>
                            </td>
                            <td><?php echo (int)$log['total_records']; ?></td>
                            <td><span class="badge bg-success"><?php echo (int)$log['new_records']; ?></span></td>
                            <td><span class="badge bg-secondary"><?php echo (int)$log['duplicate_records']; ?></span></td>
                            <td>
                                <?php if ($log['unmapped_records'] > 0): ?>
                                    <span class="badge bg-danger"><?php echo (int)$log['unmapped_records']; ?></span>
                                <?php else: ?>
                                    <span class="badge bg-success">0</span>
                                <?php endif; ?>
                            </td>
                            <td><span class="badge bg-primary"><?php echo (int)$log['synced_attendance']; ?></span></td>
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

<!-- ====== مودال إضافة/تعديل جهاز ====== -->
<div class="modal fade" id="addDeviceModal" tabindex="-1">
    <div class="modal-dialog modal-lg">
        <div class="modal-content admin-modal admin-modal-premium admin-modal-create" id="deviceModalContent">
<form method="POST" id="deviceForm">
    <?php echo csrfField(); ?>
                <input type="hidden" name="action" id="formAction" value="add_device">
                <input type="hidden" name="device_id" id="formDeviceId" value="0">

                <div class="modal-header" id="deviceModalHeader">
                    <h5 class="modal-title" id="deviceModalTitle"><i class="fas fa-plus me-2"></i>إضافة جهاز جديد</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <div class="row g-3">
                        <div class="col-md-6">
                            <label class="form-label fw-semibold">اسم الجهاز <span class="text-danger">*</span></label>
                            <input type="text" name="device_name" id="fDeviceName" class="form-control" required maxlength="100" placeholder="مثال: جهاز البوابة الرئيسية">
                        </div>
                        <div class="col-md-6">
                            <label class="form-label fw-semibold">عنوان IP <span class="text-danger">*</span></label>
                            <input type="text" name="ip_address" id="fIpAddress" class="form-control" required maxlength="45" placeholder="مثال: 10.0.0.165" dir="ltr">
                        </div>
                        <div class="col-md-3">
                            <label class="form-label fw-semibold">المنفذ</label>
                            <input type="number" name="port" id="fPort" class="form-control" value="4370" min="1" max="65535">
                        </div>
                        <div class="col-md-3">
                            <label class="form-label fw-semibold">البروتوكول</label>
                            <select name="protocol" id="fProtocol" class="form-select">
                                <option value="auto">تلقائي (TCP ثم UDP)</option>
                                <option value="TCP">TCP</option>
                                <option value="UDP">UDP</option>
                            </select>
                        </div>
                        <div class="col-md-3">
                            <label class="form-label fw-semibold">مفتاح الاتصال <small class="text-muted">(رقم)</small></label>
                            <input type="number" name="comm_password" id="fCommPassword" class="form-control" value="0" min="0" max="999999" placeholder="0 = بدون">
                            <small class="text-muted">Communication Key (ليس كلمة مرور الويب)</small>
                        </div>
                        <div class="col-md-3">
                            <label class="form-label fw-semibold">الموديل</label>
                            <select name="model" id="fModel" class="form-select" onchange="toggleCustomModel()">
                                <option value="">-- اختر --</option>
                                <optgroup label="بصمة إصبع">
                                    <option value="K20">K20</option>
                                    <option value="K40">K40</option>
                                    <option value="K50">K50</option>
                                    <option value="K60">K60</option>
                                    <option value="UA400">UA400</option>
                                    <option value="UA860">UA860</option>
                                    <option value="iClock 580">iClock 580</option>
                                    <option value="iClock 680">iClock 680</option>
                                    <option value="iClock 880">iClock 880</option>
                                    <option value="SF100">SF100</option>
                                    <option value="SF200">SF200</option>
                                    <option value="SF300">SF300</option>
                                    <option value="SF400">SF400</option>
                                    <option value="LX14">LX14</option>
                                    <option value="LX17">LX17</option>
                                    <option value="LX40">LX40</option>
                                    <option value="LX50">LX50</option>
                                    <option value="F18">F18</option>
                                    <option value="X7">X7</option>
                                </optgroup>
                                <optgroup label="تعرف على الوجه / متعدد">
                                    <option value="MB20">MB20</option>
                                    <option value="MB160">MB160</option>
                                    <option value="MB360">MB360</option>
                                    <option value="MB460">MB460</option>
                                    <option value="MB560">MB560</option>
                                    <option value="uFace 202">uFace 202</option>
                                    <option value="uFace 402">uFace 402</option>
                                    <option value="uFace 602">uFace 602</option>
                                    <option value="uFace 800">uFace 800</option>
                                    <option value="MultiBio 700">MultiBio 700</option>
                                    <option value="MultiBio 800">MultiBio 800</option>
                                    <option value="SpeedFace-V5L">SpeedFace-V5L</option>
                                    <option value="SpeedFace-H5L">SpeedFace-H5L</option>
                                    <option value="ProFace X">ProFace X</option>
                                    <option value="VF380">VF380</option>
                                    <option value="VF680">VF680</option>
                                </optgroup>
                                <option value="أخرى">أخرى</option>
                            </select>
                        </div>
                        <div class="col-md-3" id="customModelWrapper" style="display:none;">
                            <label class="form-label fw-semibold">اسم الموديل</label>
                            <input type="text" name="custom_model" id="fCustomModel" class="form-control" maxlength="50" placeholder="أدخل اسم الموديل">
                        </div>
                        <div class="col-md-3">
                            <label class="form-label fw-semibold">الرقم التسلسلي</label>
                            <input type="text" name="serial_number" id="fSerialNumber" class="form-control" maxlength="50" placeholder="يُجلب تلقائياً" dir="ltr">
                        </div>
                        <div class="col-md-12">
                            <label class="form-label fw-semibold">موقع الجهاز</label>
                            <input type="text" name="location_name" id="fLocationName" class="form-control" maxlength="200" placeholder="مثال: البوابة الرئيسية - المبنى الأول">
                        </div>
                        <div class="col-md-4">
                            <div class="form-check form-switch">
                                <input class="form-check-input" type="checkbox" name="is_active" id="fIsActive" checked>
                                <label class="form-check-label" for="fIsActive">مفعّل</label>
                            </div>
                        </div>
                        <div class="col-md-4">
                            <div class="form-check form-switch">
                                <input class="form-check-input" type="checkbox" name="auto_sync" id="fAutoSync" checked>
                                <label class="form-check-label" for="fAutoSync">مزامنة تلقائية</label>
                            </div>
                        </div>
                        <div class="col-md-4">
                            <div class="form-check form-switch">
                                <input class="form-check-input" type="checkbox" name="clear_after_sync" id="fClearAfterSync">
                                <label class="form-check-label" for="fClearAfterSync">مسح بعد المزامنة</label>
                            </div>
                        </div>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">
                        <i class="fas fa-times me-1"></i>إلغاء
                    </button>
                    <button type="submit" class="btn btn-success" id="deviceSubmitBtn">
                        <i class="fas fa-save me-1"></i>حفظ
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- مودال تأكيد الحذف -->
<div class="modal fade" id="deleteDeviceModal" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content admin-modal admin-modal-premium admin-modal-delete">
<form method="POST">
    <?php echo csrfField(); ?>
                <input type="hidden" name="action" value="delete_device">
                <input type="hidden" name="device_id" id="deleteDeviceId">
                <div class="modal-header">
                    <h5 class="modal-title"><i class="fas fa-trash me-2"></i>حذف جهاز</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <div class="text-center mb-3">
                        <i class="fas fa-exclamation-triangle text-danger" style="font-size:3rem;"></i>
                    </div>
                    <p class="text-center">هل تريد حذف الجهاز <span class="fw-bold text-primary" id="deleteDeviceName"></span>؟</p>
                    <div class="alert alert-warning">
                        <i class="fas fa-info-circle me-2"></i>
                        سيتم حذف الجهاز وسجل المزامنة الخاص به. سجلات البصمة المستوردة سابقاً لن تتأثر.
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal"><i class="fas fa-times me-1"></i>إلغاء</button>
                    <button type="submit" class="btn btn-danger"><i class="fas fa-check me-1"></i>تأكيد الحذف</button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- مودال نتائج العملية -->
<div class="modal fade" id="resultModal" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content admin-modal admin-modal-premium admin-modal-view" id="resultModalContent">
            <div class="modal-header" id="resultModalHeader">
                <h5 class="modal-title" id="resultModalTitle"></h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body" id="resultModalBody"></div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">حسناً</button>
            </div>
        </div>
    </div>
</div>

<!-- مودال تأكيد إزالة الربط -->
<div class="modal fade" id="unlinkEmployeeModal" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content admin-modal admin-modal-premium admin-modal-warning">
<form method="POST">
    <?php echo csrfField(); ?>
                <input type="hidden" name="action" value="update_employee_code">
                <input type="hidden" name="user_id" id="unlinkUserId">
                <input type="hidden" name="employee_code" value="">
                <div class="modal-header">
                    <h5 class="modal-title"><i class="fas fa-unlink me-2"></i>إزالة ربط موظف</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <div class="text-center mb-3">
                        <i class="fas fa-unlink text-warning" style="font-size: 3rem;"></i>
                    </div>
                    <p class="text-center">هل تريد إزالة ربط الموظف <span class="fw-bold text-primary" id="unlinkEmployeeName"></span> من جهاز البصمة؟</p>
                    <div class="alert alert-warning">
                        <i class="fas fa-info-circle me-2"></i>
                        سيتم إزالة رقم البصمة فقط. سجلات الحضور المستوردة سابقاً لن تتأثر.
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal"><i class="fas fa-times me-1"></i>إلغاء</button>
                    <button type="submit" class="btn btn-danger"><i class="fas fa-unlink me-1"></i>إزالة الربط</button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- مودال المستخدمين على الجهاز -->
<div class="modal fade" id="deviceUsersModal" tabindex="-1">
    <div class="modal-dialog modal-lg">
        <div class="modal-content admin-modal admin-modal-premium admin-modal-view">
            <div class="modal-header">
                <h5 class="modal-title"><i class="fas fa-users me-2"></i>المستخدمون المسجلون على الجهاز</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body" id="deviceUsersBody">
                <div class="text-center py-4"><i class="fas fa-spinner fa-spin fa-2x text-muted"></i></div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">إغلاق</button>
            </div>
        </div>
    </div>
</div>

<script>
var csrfToken = '<?php echo htmlspecialchars($_SESSION['csrf_token'] ?? '', ENT_QUOTES, 'UTF-8'); ?>';

function toggleCustomModel() {
    var sel = document.getElementById('fModel');
    var wrapper = document.getElementById('customModelWrapper');
    var input = document.getElementById('fCustomModel');
    if (sel.value === 'أخرى') {
        wrapper.style.display = '';
        input.required = true;
    } else {
        wrapper.style.display = 'none';
        input.value = '';
        input.required = false;
    }
}

// تهيئة DataTables
document.addEventListener('DOMContentLoaded', function() {
    if (typeof $ !== 'undefined' && $.fn.DataTable) {
        var dtLang = {
            url: '//cdn.datatables.net/plug-ins/1.13.6/i18n/ar.json',
            search: 'بحث:', lengthMenu: 'عرض _MENU_ سجل',
            info: 'عرض _START_ إلى _END_ من _TOTAL_'
        };
        if (document.getElementById('devicesTable')) {
            $('#devicesTable').DataTable({ pageLength: 50, language: dtLang, order: [[0, 'asc']] });
        }
        if (document.getElementById('mappingTable')) {
            $('#mappingTable').DataTable({ pageLength: 50, language: dtLang, order: [[1, 'asc']] });
        }
        if (document.getElementById('syncLogsTable')) {
            $('#syncLogsTable').DataTable({ pageLength: 50, language: dtLang, order: [[3, 'desc']] });
        }
    }

    // Tab persistence
    document.querySelectorAll('.nav-pills .nav-link').forEach(function(tab) {
        tab.addEventListener('shown.bs.tab', function(e) {
            var name = e.target.getAttribute('href').replace('#pane-', '');
            var url = new URL(window.location);
            url.searchParams.set('tab', name);
            window.history.replaceState({}, '', url);
        });
    });
});

function editDevice(device) {
    document.getElementById('formAction').value = 'edit_device';
    document.getElementById('formDeviceId').value = device.id;
    document.getElementById('fDeviceName').value = device.device_name;
    document.getElementById('fIpAddress').value = device.ip_address;
    document.getElementById('fPort').value = device.port;
    document.getElementById('fProtocol').value = device.protocol || 'auto';
    document.getElementById('fCommPassword').value = device.comm_password || 0;
    // تعيين الموديل - إذا لم يكن من القائمة الأساسية، اعتبره "أخرى"
    var modelSelect = document.getElementById('fModel');
    var modelValue = device.model || '';
    var optionExists = false;
    for (var i = 0; i < modelSelect.options.length; i++) {
        if (modelSelect.options[i].value === modelValue) { optionExists = true; break; }
    }
    if (modelValue && !optionExists) {
        modelSelect.value = 'أخرى';
        document.getElementById('fCustomModel').value = modelValue;
    } else {
        modelSelect.value = modelValue;
        document.getElementById('fCustomModel').value = '';
    }
    toggleCustomModel();
    document.getElementById('fSerialNumber').value = device.serial_number || '';
    document.getElementById('fLocationName').value = device.location_name || '';
    document.getElementById('fIsActive').checked = device.is_active == 1;
    document.getElementById('fAutoSync').checked = device.auto_sync == 1;
    document.getElementById('fClearAfterSync').checked = device.clear_after_sync == 1;
    document.getElementById('deviceModalTitle').innerHTML = '<i class="fas fa-edit me-2"></i>تعديل بيانات الجهاز';
    document.getElementById('deviceModalContent').classList.remove('admin-modal-create');
    document.getElementById('deviceModalContent').classList.add('admin-modal-edit');
    document.getElementById('deviceSubmitBtn').className = 'btn btn-primary';
    bootstrap.Modal.getOrCreateInstance(document.getElementById('addDeviceModal')).show();
}

// إعادة تعيين المودال عند الإغلاق
document.getElementById('addDeviceModal').addEventListener('hidden.bs.modal', function() {
    document.getElementById('formAction').value = 'add_device';
    document.getElementById('formDeviceId').value = 0;
    document.getElementById('deviceForm').reset();
    document.getElementById('fPort').value = 4370;
    document.getElementById('fProtocol').value = 'auto';
    document.getElementById('fCommPassword').value = 0;
    document.getElementById('fIsActive').checked = true;
    document.getElementById('fAutoSync').checked = true;
    document.getElementById('fClearAfterSync').checked = false;
    document.getElementById('fCustomModel').value = '';
    toggleCustomModel();
    document.getElementById('deviceModalTitle').innerHTML = '<i class="fas fa-plus me-2"></i>إضافة جهاز جديد';
    document.getElementById('deviceModalContent').classList.remove('admin-modal-edit');
    document.getElementById('deviceModalContent').classList.add('admin-modal-create');
    document.getElementById('deviceSubmitBtn').className = 'btn btn-success';
});

function confirmDeleteDevice(id, name) {
    document.getElementById('deleteDeviceId').value = id;
    document.getElementById('deleteDeviceName').textContent = name;
    bootstrap.Modal.getOrCreateInstance(document.getElementById('deleteDeviceModal')).show();
}

function showResult(title, body, type) {
    var modalContent = document.getElementById('resultModalContent');
    modalContent.classList.remove('admin-modal-create', 'admin-modal-delete', 'admin-modal-warning', 'admin-modal-view');
    modalContent.classList.add(type === 'success' ? 'admin-modal-create' : type === 'danger' ? 'admin-modal-delete' : type === 'warning' ? 'admin-modal-warning' : 'admin-modal-view');
    document.getElementById('resultModalTitle').innerHTML = title;
    document.getElementById('resultModalBody').innerHTML = body;
    var el = document.getElementById('resultModal');
    var modal = bootstrap.Modal.getOrCreateInstance(el);
    modal.show();
}

function testDevice(deviceId) {
    showResult('<i class="fas fa-spinner fa-spin me-2"></i>جاري الاختبار...', '<div class="text-center py-3"><i class="fas fa-spinner fa-spin fa-3x text-primary"></i><p class="mt-3">جاري الاتصال بالجهاز...</p></div>', 'info');

    fetch('ajax/biometric_device_actions.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/x-www-form-urlencoded', 'X-CSRF-TOKEN': csrfToken },
        body: 'action=test_connection&device_id=' + deviceId + '&csrf_token=' + encodeURIComponent(csrfToken)
    })
    .then(function(r) { return r.json(); })
    .then(function(data) {
        if (data.success) {
            var info = data.info;
            showResult(
                '<i class="fas fa-check-circle me-2"></i>الاتصال ناجح',
                '<div class="alert alert-success"><i class="fas fa-check me-2"></i>تم الاتصال بالجهاز بنجاح</div>' +
                '<table class="table table-sm">' +
                '<tr><th>IP</th><td dir="ltr">' + escapeHtml(info.ip || '') + ':' + (info.port || '') + '</td></tr>' +
                '<tr><th>البروتوكول</th><td><span class="badge bg-info">' + escapeHtml(info.protocol || '-') + '</span></td></tr>' +
                '<tr><th>البرنامج الثابت</th><td dir="ltr">' + escapeHtml(info.firmware || '-') + '</td></tr>' +
                '<tr><th>الرقم التسلسلي</th><td dir="ltr">' + escapeHtml(info.serial || '-') + '</td></tr>' +
                '<tr><th>المنصة</th><td dir="ltr">' + escapeHtml(info.platform || '-') + '</td></tr>' +
                '<tr><th>اسم الجهاز</th><td>' + escapeHtml(info.device_name || '-') + '</td></tr>' +
                '</table>',
                'success'
            );
        } else {
            showResult(
                '<i class="fas fa-times-circle me-2"></i>فشل الاتصال',
                '<div class="alert alert-danger"><i class="fas fa-exclamation-triangle me-2"></i>' + escapeHtml(data.error || 'فشل الاتصال بالجهاز') + '</div>' +
                '<p class="text-muted">تأكد من:</p><ul class="text-muted">' +
                '<li>أن الجهاز متصل بالشبكة ويعمل</li>' +
                '<li>أن عنوان IP صحيح</li>' +
                '<li>أن المنفذ 4370 مفتوح (TCP أو UDP)</li>' +
                '<li>أن السيرفر على نفس الشبكة المحلية</li>' +
                '<li>مفتاح الاتصال (Communication Key) هو <strong>رقم</strong> يُضبط من إعدادات الجهاز — وليس كلمة مرور الويب</li></ul>',
                'danger'
            );
        }
    })
    .catch(function(err) {
        showResult('<i class="fas fa-times-circle me-2"></i>خطأ', '<div class="alert alert-danger">حدث خطأ في الاتصال بالسيرفر</div>', 'danger');
    });
}

function syncDevice(deviceId) {
    showResult('<i class="fas fa-sync fa-spin me-2"></i>جاري المزامنة...', '<div class="text-center py-3"><i class="fas fa-sync fa-spin fa-3x text-success"></i><p class="mt-3">جاري سحب البيانات من الجهاز...<br>قد تستغرق العملية دقيقة أو أكثر حسب حجم البيانات</p></div>', 'info');

    fetch('ajax/biometric_device_actions.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/x-www-form-urlencoded', 'X-CSRF-TOKEN': csrfToken },
        body: 'action=sync_device&device_id=' + deviceId + '&csrf_token=' + encodeURIComponent(csrfToken)
    })
    .then(function(r) { return r.json(); })
    .then(function(data) {
        if (data.success) {
            var r = data.result;
            showResult(
                '<i class="fas fa-check-circle me-2"></i>تمت المزامنة بنجاح',
                '<div class="alert alert-success"><i class="fas fa-check me-2"></i>تم سحب البيانات وتحديث الحضور</div>' +
                '<table class="table table-sm">' +
                '<tr><th>إجمالي السجلات</th><td><span class="badge bg-primary">' + (r.total_records || 0) + '</span></td></tr>' +
                '<tr><th>سجلات جديدة</th><td><span class="badge bg-success">' + (r.new_records || 0) + '</span></td></tr>' +
                '<tr><th>سجلات مكررة</th><td><span class="badge bg-secondary">' + (r.duplicate_records || 0) + '</span></td></tr>' +
                '<tr><th>غير مربوط بموظف</th><td><span class="badge bg-' + ((r.unmapped_records || 0) > 0 ? 'danger' : 'success') + '">' + (r.unmapped_records || 0) + '</span></td></tr>' +
                '<tr><th>سجلات حضور محدّثة</th><td><span class="badge bg-info">' + (r.synced_attendance || 0) + '</span></td></tr>' +
                '</table>' +
                ((r.unmapped_records || 0) > 0 ? '<div class="alert alert-warning mt-2"><i class="fas fa-exclamation-triangle me-2"></i>يوجد سجلات لموظفين غير مربوطين. اذهب إلى تبويب "ربط الموظفين" لربطهم.</div>' : ''),
                'success'
            );
            // تحديث الصفحة بعد 2 ثانية
            setTimeout(function() { location.reload(); }, 2000);
        } else {
            showResult(
                '<i class="fas fa-times-circle me-2"></i>فشلت المزامنة',
                '<div class="alert alert-danger"><i class="fas fa-exclamation-triangle me-2"></i>' + escapeHtml(data.error || 'فشلت المزامنة') + '</div>',
                'danger'
            );
        }
    })
    .catch(function(err) {
        showResult('<i class="fas fa-times-circle me-2"></i>خطأ', '<div class="alert alert-danger">حدث خطأ في الاتصال بالسيرفر</div>', 'danger');
    });
}

function syncAllDevices() {
    showResult('<i class="fas fa-sync fa-spin me-2"></i>جاري مزامنة جميع الأجهزة...', '<div class="text-center py-3"><i class="fas fa-sync fa-spin fa-3x text-success"></i><p class="mt-3">جاري المزامنة من جميع الأجهزة المفعّلة...<br>يرجى الانتظار</p></div>', 'info');

    fetch('ajax/biometric_device_actions.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/x-www-form-urlencoded', 'X-CSRF-TOKEN': csrfToken },
        body: 'action=sync_all&csrf_token=' + encodeURIComponent(csrfToken)
    })
    .then(function(r) { return r.json(); })
    .then(function(data) {
        if (data.success) {
            var results = data.results || [];
            var html = '<div class="alert alert-success"><i class="fas fa-check me-2"></i>تمت المزامنة</div>';
            results.forEach(function(r) {
                var badge = r.status === 'success' ? 'success' : (r.status === 'error' ? 'danger' : 'warning');
                var statusLabel = r.status === 'success' ? 'نجاح' : (r.status === 'partial' ? 'جزئي' : 'خطأ');
                var res = r.result || {};
                html += '<div class="border rounded p-2 mb-2">' +
                    '<strong>' + escapeHtml(r.device_name || '') + '</strong> ' +
                    '<span class="badge bg-' + badge + '">' + statusLabel + '</span>' +
                    (res.new_records !== undefined ? ' | جديد: <span class="badge bg-success">' + res.new_records + '</span>' : '') +
                    (res.duplicate_records ? ' | مكرر: ' + res.duplicate_records : '') +
                    (res.unmapped_records ? ' | غير مربوط: <span class="badge bg-danger">' + res.unmapped_records + '</span>' : '') +
                    (r.error ? ' <br><small class="text-danger">' + escapeHtml(r.error) + '</small>' : '') +
                    '</div>';
            });
            showResult('<i class="fas fa-check-circle me-2"></i>نتائج المزامنة', html, 'success');
            setTimeout(function() { location.reload(); }, 3000);
        } else {
            showResult('<i class="fas fa-times-circle me-2"></i>خطأ', '<div class="alert alert-danger">' + escapeHtml(data.error || 'فشلت المزامنة') + '</div>', 'danger');
        }
    })
    .catch(function(err) {
        showResult('<i class="fas fa-times-circle me-2"></i>خطأ', '<div class="alert alert-danger">حدث خطأ في الاتصال</div>', 'danger');
    });
}

function getEnrolledUsers(deviceId) {
    document.getElementById('deviceUsersBody').innerHTML = '<div class="text-center py-4"><i class="fas fa-spinner fa-spin fa-2x text-primary"></i><p class="mt-2">جاري جلب بيانات المستخدمين من الجهاز...</p></div>';
    var el = document.getElementById('deviceUsersModal');
    bootstrap.Modal.getOrCreateInstance(el).show();

    fetch('ajax/biometric_device_actions.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/x-www-form-urlencoded', 'X-CSRF-TOKEN': csrfToken },
        body: 'action=get_enrolled_users&device_id=' + deviceId + '&csrf_token=' + encodeURIComponent(csrfToken)
    })
    .then(function(r) { return r.json(); })
    .then(function(data) {
        if (data.success) {
            var users = data.users || [];
            if (users.length === 0) {
                document.getElementById('deviceUsersBody').innerHTML = '<div class="alert alert-info text-center"><i class="fas fa-info-circle me-2"></i>لا يوجد مستخدمون مسجلون على الجهاز</div>';
                return;
            }
            var html = '<div class="alert alert-info"><i class="fas fa-users me-2"></i>عدد المستخدمين: <strong>' + data.count + '</strong></div>';
            var mappedCount = 0;
            var unmappedCount = 0;
            users.forEach(function(u) { if (u.staff_name) mappedCount++; else unmappedCount++; });
            html += '<div class="mb-2"><span class="badge bg-success me-1">' + mappedCount + ' مربوط</span><span class="badge bg-warning text-dark">' + unmappedCount + ' غير مربوط</span></div>';
            html += '<div class="table-responsive"><table class="table table-sm table-hover table-striped"><thead><tr><th>رقم المستخدم (UID)</th><th>الاسم على الجهاز</th><th>اسم الموظف في النظام</th><th>الصلاحية</th></tr></thead><tbody>';
            users.forEach(function(u) {
                var priv = u.privilege === 14 ? '<span class="badge bg-danger">مدير</span>' : '<span class="badge bg-secondary">مستخدم</span>';
                var staffCol = u.staff_name ? '<span class="text-success fw-bold">' + escapeHtml(u.staff_name) + '</span>' : '<span class="text-muted">غير مربوط</span>';
                html += '<tr><td><code>' + escapeHtml(u.uid || '') + '</code></td><td>' + escapeHtml(u.name || '-') + '</td><td>' + staffCol + '</td><td>' + priv + '</td></tr>';
            });
            html += '</tbody></table></div>';
            if (unmappedCount > 0) {
                html += '<div class="alert alert-warning mt-2"><i class="fas fa-lightbulb me-2"></i>يوجد ' + unmappedCount + ' مستخدم غير مربوط. اذهب لتبويب "ربط الموظفين" لربطهم بالموظفين في النظام.</div>';
            }
            document.getElementById('deviceUsersBody').innerHTML = html;
        } else {
            document.getElementById('deviceUsersBody').innerHTML = '<div class="alert alert-danger"><i class="fas fa-exclamation-triangle me-2"></i>' + escapeHtml(data.error || 'فشل جلب البيانات') + '</div>';
        }
    })
    .catch(function(err) {
        document.getElementById('deviceUsersBody').innerHTML = '<div class="alert alert-danger">حدث خطأ في الاتصال بالسيرفر</div>';
    });
}

function escapeHtml(text) {
    var div = document.createElement('div');
    div.appendChild(document.createTextNode(text));
    return div.innerHTML;
}

// ====== وظائف ربط الموظفين ======
var loadedDeviceUsers = []; // مصفوفة المستخدمين المحملة من الجهاز

function confirmUnlinkEmployee(userId, name) {
    document.getElementById('unlinkUserId').value = userId;
    document.getElementById('unlinkEmployeeName').textContent = name;
    bootstrap.Modal.getOrCreateInstance(document.getElementById('unlinkEmployeeModal')).show();
}

function loadDeviceUsersForMapping() {
    var deviceId = document.getElementById('mappingDeviceSelect').value;
    if (!deviceId) {
        document.getElementById('mappingLoadStatus').innerHTML = '<div class="alert alert-warning alert-dismissible fade show"><i class="fas fa-exclamation-triangle me-2"></i>اختر جهاز بصمة أولاً<button class="btn-close" data-bs-dismiss="alert"></button></div>';
        return;
    }

    var btn = document.getElementById('btnLoadUsers');
    btn.disabled = true;
    btn.innerHTML = '<i class="fas fa-spinner fa-spin me-1"></i>جاري التحميل...';
    document.getElementById('mappingLoadStatus').innerHTML = '<div class="text-center py-2"><i class="fas fa-spinner fa-spin fa-lg text-primary"></i> جاري جلب المستخدمين من الجهاز...</div>';

    fetch('ajax/biometric_device_actions.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/x-www-form-urlencoded', 'X-CSRF-TOKEN': csrfToken },
        body: 'action=get_enrolled_users&device_id=' + deviceId + '&csrf_token=' + encodeURIComponent(csrfToken)
    })
    .then(function(r) { return r.json(); })
    .then(function(data) {
        btn.disabled = false;
        btn.innerHTML = '<i class="fas fa-download me-1"></i>جلب المستخدمين';

        if (data.success) {
            loadedDeviceUsers = data.users || [];

            // ملء قائمة الاقتراحات
            var datalist = document.getElementById('deviceUsersList');
            datalist.innerHTML = '';
            var existingCodes = {};
            document.querySelectorAll('.emp-code-input').forEach(function(inp) {
                var val = inp.value.trim();
                if (val) existingCodes[val] = true;
            });

            var unmappedCount = 0;
            loadedDeviceUsers.forEach(function(u) {
                var opt = document.createElement('option');
                opt.value = u.uid;
                var label = u.uid;
                if (u.name && u.name !== u.uid) label += ' - ' + u.name;
                opt.textContent = label;
                datalist.appendChild(opt);
                if (!existingCodes[u.uid]) unmappedCount++;
            });

            // تفعيل زر الربط التلقائي
            document.getElementById('btnAutoMatch').disabled = false;

            var mapped = loadedDeviceUsers.length - unmappedCount;
            document.getElementById('mappingLoadStatus').innerHTML =
                '<div class="alert alert-success alert-dismissible fade show">' +
                '<i class="fas fa-check-circle me-2"></i>' +
                'تم تحميل <strong>' + loadedDeviceUsers.length + '</strong> مستخدم من الجهاز. ' +
                '<span class="badge bg-success">' + mapped + ' مربوط</span> ' +
                '<span class="badge bg-warning text-dark">' + unmappedCount + ' غير مربوط</span> — ' +
                'اكتب في حقل "رقم البصمة" للحصول على اقتراحات.' +
                '<button class="btn-close" data-bs-dismiss="alert"></button></div>';
        } else {
            document.getElementById('mappingLoadStatus').innerHTML =
                '<div class="alert alert-danger alert-dismissible fade show"><i class="fas fa-times-circle me-2"></i>' +
                escapeHtml(data.error || 'فشل جلب البيانات') +
                '<button class="btn-close" data-bs-dismiss="alert"></button></div>';
        }
    })
    .catch(function(err) {
        btn.disabled = false;
        btn.innerHTML = '<i class="fas fa-download me-1"></i>جلب المستخدمين';
        document.getElementById('mappingLoadStatus').innerHTML =
            '<div class="alert alert-danger alert-dismissible fade show"><i class="fas fa-times-circle me-2"></i>حدث خطأ في الاتصال بالسيرفر<button class="btn-close" data-bs-dismiss="alert"></button></div>';
    });
}

function normalizeArabic(str) {
    return str.replace(/[إأآا]/g, 'ا').replace(/[ة]/g, 'ه').replace(/[ى]/g, 'ي').replace(/[\u064B-\u065F\u0670]/g, '').trim().toLowerCase();
}

function autoMatchUsers() {
    if (loadedDeviceUsers.length === 0) {
        document.getElementById('mappingLoadStatus').innerHTML =
            '<div class="alert alert-warning alert-dismissible fade show"><i class="fas fa-exclamation-triangle me-2"></i>حمّل المستخدمين من الجهاز أولاً<button class="btn-close" data-bs-dismiss="alert"></button></div>';
        return;
    }

    // بناء خريطة أسماء الجهاز
    var deviceNameMap = {};
    loadedDeviceUsers.forEach(function(u) {
        if (u.name && u.name !== u.uid && u.name.length > 2) {
            deviceNameMap[normalizeArabic(u.name)] = u.uid;
            // أيضاً المطابقة بالأحرف اللاتينية (lowercase)
            deviceNameMap[u.name.trim().toLowerCase()] = u.uid;
        }
    });

    var matchCount = 0;
    var inputs = document.querySelectorAll('.emp-code-input');

    // للوصول لكل الصفوف في DataTable
    if (typeof $ !== 'undefined' && $.fn.DataTable && $.fn.DataTable.isDataTable('#mappingTable')) {
        var dt = $('#mappingTable').DataTable();
        dt.rows().every(function() {
            var node = this.node();
            var inp = node.querySelector('.emp-code-input');
            if (!inp || inp.value.trim() !== '') return; // تخطي المربوطين

            var staffName = inp.getAttribute('data-staff-name') || '';
            var normalizedStaffName = normalizeArabic(staffName);
            var lowerStaffName = staffName.trim().toLowerCase();

            // بحث مطابق تماماً
            var matched = deviceNameMap[normalizedStaffName] || deviceNameMap[lowerStaffName] || null;

            // بحث جزئي: هل اسم الموظف يحتوي على اسم الجهاز أو العكس
            if (!matched) {
                for (var dName in deviceNameMap) {
                    if (dName.length > 3 && (normalizedStaffName.indexOf(dName) !== -1 || dName.indexOf(normalizedStaffName) !== -1)) {
                        matched = deviceNameMap[dName];
                        break;
                    }
                    if (lowerStaffName.length > 3 && (lowerStaffName.indexOf(dName) !== -1 || dName.indexOf(lowerStaffName) !== -1)) {
                        matched = deviceNameMap[dName];
                        break;
                    }
                }
            }

            if (matched) {
                inp.value = matched;
                inp.style.backgroundColor = '#d4edda';
                matchCount++;
            }
        });
    } else {
        inputs.forEach(function(inp) {
            if (inp.value.trim() !== '') return;
            var staffName = inp.getAttribute('data-staff-name') || '';
            var normalizedStaffName = normalizeArabic(staffName);
            var lowerStaffName = staffName.trim().toLowerCase();
            var matched = deviceNameMap[normalizedStaffName] || deviceNameMap[lowerStaffName] || null;
            if (matched) {
                inp.value = matched;
                inp.style.backgroundColor = '#d4edda';
                matchCount++;
            }
        });
    }

    if (matchCount > 0) {
        document.getElementById('mappingLoadStatus').innerHTML =
            '<div class="alert alert-success alert-dismissible fade show"><i class="fas fa-magic me-2"></i>' +
            'تم مطابقة <strong>' + matchCount + '</strong> موظف تلقائياً بناءً على الأسماء. ' +
            'راجع النتائج (مُظللة بالأخضر) ثم اضغط "حفظ الكل".' +
            '<button class="btn-close" data-bs-dismiss="alert"></button></div>';
    } else {
        document.getElementById('mappingLoadStatus').innerHTML =
            '<div class="alert alert-info alert-dismissible fade show"><i class="fas fa-info-circle me-2"></i>' +
            'لم يتم العثور على مطابقات تلقائية. الأسماء على الجهاز مختلفة عن أسماء الموظفين في النظام. ' +
            'يرجى الربط يدوياً باستخدام حقول الإدخال.' +
            '<button class="btn-close" data-bs-dismiss="alert"></button></div>';
    }
}

function bulkSaveMappings() {
    var mappings = [];

    // جمع البيانات من كل الصفوف (بما في ذلك صفحات DataTable المخفية)
    if (typeof $ !== 'undefined' && $.fn.DataTable && $.fn.DataTable.isDataTable('#mappingTable')) {
        var dt = $('#mappingTable').DataTable();
        dt.rows().every(function() {
            var node = this.node();
            var inp = node.querySelector('.emp-code-input');
            if (!inp) return;

            var userId = inp.getAttribute('data-user-id');
            var currentVal = inp.value.trim();
            var originalVal = inp.getAttribute('data-original') || '';

            if (currentVal !== originalVal && currentVal !== '') {
                mappings.push({ user_id: parseInt(userId), employee_code: currentVal });
            }
        });
    } else {
        document.querySelectorAll('.emp-code-input').forEach(function(inp) {
            var userId = inp.getAttribute('data-user-id');
            var currentVal = inp.value.trim();
            var originalVal = inp.getAttribute('data-original') || '';
            if (currentVal !== originalVal && currentVal !== '') {
                mappings.push({ user_id: parseInt(userId), employee_code: currentVal });
            }
        });
    }

    if (mappings.length === 0) {
        document.getElementById('mappingLoadStatus').innerHTML =
            '<div class="alert alert-info alert-dismissible fade show"><i class="fas fa-info-circle me-2"></i>' +
            'لا توجد تغييرات لحفظها. عدّل حقول "رقم البصمة" أولاً.' +
            '<button class="btn-close" data-bs-dismiss="alert"></button></div>';
        return;
    }

    document.getElementById('mappingLoadStatus').innerHTML =
        '<div class="text-center py-2"><i class="fas fa-spinner fa-spin fa-lg text-primary"></i> جاري حفظ ' + mappings.length + ' ربط...</div>';

    fetch('ajax/biometric_device_actions.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/x-www-form-urlencoded', 'X-CSRF-TOKEN': csrfToken },
        body: 'action=bulk_map_employees&mappings=' + encodeURIComponent(JSON.stringify(mappings)) + '&csrf_token=' + encodeURIComponent(csrfToken)
    })
    .then(function(r) { return r.json(); })
    .then(function(data) {
        if (data.success) {
            var html = '<div class="alert alert-success alert-dismissible fade show"><i class="fas fa-check-circle me-2"></i>' +
                'تم حفظ <strong>' + data.updated + '</strong> من أصل ' + data.total + ' ربط بنجاح.';
            if (data.errors && data.errors.length > 0) {
                html += '<br><small class="text-danger">' + data.errors.join('، ') + '</small>';
            }
            html += '<button class="btn-close" data-bs-dismiss="alert"></button></div>';
            document.getElementById('mappingLoadStatus').innerHTML = html;

            // تحديث data-original للقيم المحفوظة
            document.querySelectorAll('.emp-code-input').forEach(function(inp) {
                inp.setAttribute('data-original', inp.value.trim());
                inp.style.backgroundColor = '';
            });
            // إعادة تحميل الصفحة بعد ثانيتين
            setTimeout(function() { location.reload(); }, 2000);
        } else {
            document.getElementById('mappingLoadStatus').innerHTML =
                '<div class="alert alert-danger alert-dismissible fade show"><i class="fas fa-times-circle me-2"></i>' +
                escapeHtml(data.error || 'فشل الحفظ') + '<button class="btn-close" data-bs-dismiss="alert"></button></div>';
        }
    })
    .catch(function(err) {
        document.getElementById('mappingLoadStatus').innerHTML =
            '<div class="alert alert-danger alert-dismissible fade show"><i class="fas fa-times-circle me-2"></i>حدث خطأ في الاتصال بالسيرفر<button class="btn-close" data-bs-dismiss="alert"></button></div>';
    });
}
</script>

<?php require_once '../includes/admin_footer.php'; ?>
