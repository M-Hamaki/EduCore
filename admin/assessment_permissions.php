<?php
$page_title = "صلاحيات الدرجات";
$custom_page_title = true;

require_once '../config/database.php';
require_once '../classes/utilities.php';
require_once '../classes/ActivityLog.php';
require_once '../includes/session_config.php';
require_once '../includes/csrf.php';
Utilities::validateSession('admin');
requireCsrfPost();

$database = new Database();
$db = $database->getConnection();

$success_message = $_SESSION['success_message'] ?? null;
$error_message = $_SESSION['error_message'] ?? null;
unset($_SESSION['success_message'], $_SESSION['error_message']);

function permissions_table_exists(PDO $db, string $table): bool
{
    $stmt = $db->prepare('SELECT 1 FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?');
    $stmt->execute([$table]);
    return (bool) $stmt->fetchColumn();
}

function permissions_redirect(): void
{
    header('Location: assessment_permissions.php');
    exit();
}

$roleLabels = [
    'teacher' => 'معلم',
    'specialist' => 'أخصائي',
    'admin' => 'أدمن',
];
$permissionLabels = [
    'delete_mark' => 'حذف درجة',
    'review_marks' => 'مراجعة درجات',
    'edit_locked_mark' => 'تعديل درجة مقفلة',
    'reopen_window' => 'إعادة فتح نافذة',
    'publish_report' => 'نشر تقرير',
];
$scopeLabels = [
    'global' => 'عام',
    'subject' => 'مادة',
    'grade' => 'صف',
    'class' => 'فصل',
    'scheme' => 'خطة درجات',
];
$scopeTables = [
    'subject' => 'subjects',
    'grade' => 'grades',
    'class' => 'classes',
    'scheme' => 'assessment_schemes',
];

function permissions_validate_payload(PDO $db, array $roleLabels, array $permissionLabels, array $scopeLabels, array $scopeTables, ?int $ignoreId = null): array
{
    $roleName = in_array(($_POST['role_name'] ?? 'teacher'), array_keys($roleLabels), true) ? (string) $_POST['role_name'] : 'teacher';
    $userId = !empty($_POST['user_id']) ? (int) $_POST['user_id'] : null;
    $permissionKey = in_array(($_POST['permission_key'] ?? 'delete_mark'), array_keys($permissionLabels), true) ? (string) $_POST['permission_key'] : 'delete_mark';
    $scopeType = in_array(($_POST['scope_type'] ?? 'global'), array_keys($scopeLabels), true) ? (string) $_POST['scope_type'] : 'global';
    $scopeId = !empty($_POST['scope_id']) ? (int) $_POST['scope_id'] : null;
    $isAllowed = isset($_POST['is_allowed']) ? 1 : 0;

    if ($scopeType === 'global') {
        $scopeId = null;
    } elseif ($scopeId === null || $scopeId <= 0) {
        throw new InvalidArgumentException('يجب تحديد معرف النطاق عند اختيار مادة أو صف أو فصل أو خطة.');
    } else {
        $scopeTable = $scopeTables[$scopeType] ?? null;
        if ($scopeTable) {
            $scopeStmt = $db->prepare("SELECT 1 FROM {$scopeTable} WHERE id = ? LIMIT 1");
            $scopeStmt->execute([$scopeId]);
            if (!$scopeStmt->fetchColumn()) {
                throw new InvalidArgumentException('معرف النطاق المحدد غير موجود في النظام.');
            }
        }
    }

    if ($userId !== null) {
        $userStmt = $db->prepare("SELECT 1
            FROM users u
            WHERE u.id = ?
              AND EXISTS (
                  SELECT 1 FROM user_role_assignments ura
                  WHERE ura.user_id = u.id AND ura.role_key = ? AND ura.status = 'active'
              )
            LIMIT 1");
        $userStmt->execute([$userId, $roleName]);
        if (!$userStmt->fetchColumn()) {
            throw new InvalidArgumentException('المستخدم المحدد لا يحمل الدور المختار.');
        }
    }

    $duplicateSql = "SELECT id FROM assessment_permissions
        WHERE role_name = ?
          AND ((user_id IS NULL AND ? IS NULL) OR user_id = ?)
          AND permission_key = ?
          AND scope_type = ?
          AND ((scope_id IS NULL AND ? IS NULL) OR scope_id = ?)";
    $duplicateParams = [$roleName, $userId, $userId, $permissionKey, $scopeType, $scopeId, $scopeId];
    if ($ignoreId !== null) {
        $duplicateSql .= ' AND id <> ?';
        $duplicateParams[] = $ignoreId;
    }
    $duplicateSql .= ' LIMIT 1';
    $duplicateStmt = $db->prepare($duplicateSql);
    $duplicateStmt->execute($duplicateParams);
    if ($duplicateStmt->fetchColumn()) {
        throw new InvalidArgumentException('تم تعريف نفس الصلاحية لهذا النطاق من قبل.');
    }

    return [$roleName, $userId, $permissionKey, $scopeType, $scopeId, $isAllowed];
}

$permissionsReady = permissions_table_exists($db, 'assessment_permissions');

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        if (!$permissionsReady) {
            throw new RuntimeException('جدول صلاحيات الدرجات غير مطبق بعد.');
        }
        $action = (string) ($_POST['action'] ?? '');

        if ($action === 'add_assessment_permission') {
            [$roleName, $userId, $permissionKey, $scopeType, $scopeId, $isAllowed] = permissions_validate_payload($db, $roleLabels, $permissionLabels, $scopeLabels, $scopeTables);
            $stmt = $db->prepare("INSERT INTO assessment_permissions
                (role_name, user_id, permission_key, scope_type, scope_id, is_allowed, created_by)
                VALUES (?, ?, ?, ?, ?, ?, ?)");
            $stmt->execute([$roleName, $userId, $permissionKey, $scopeType, $scopeId, $isAllowed, (int) ($_SESSION['user_id'] ?? 0) ?: null]);
            ActivityLog::logCreate('assessment_permission', (int) $db->lastInsertId(), 'صلاحية درجات', [
                'role' => $roleName,
                'user_id' => $userId,
                'permission' => $permissionKey,
                'scope' => $scopeType,
                'scope_id' => $scopeId,
            ]);
            $_SESSION['success_message'] = 'تم حفظ صلاحية الدرجات.';
            permissions_redirect();
        }

        if ($action === 'update_assessment_permission') {
            $permissionId = (int) ($_POST['permission_id'] ?? 0);
            if ($permissionId <= 0) {
                throw new InvalidArgumentException('الصلاحية غير محددة.');
            }
            $oldStmt = $db->prepare('SELECT * FROM assessment_permissions WHERE id = ? LIMIT 1');
            $oldStmt->execute([$permissionId]);
            $oldPermission = $oldStmt->fetch(PDO::FETCH_ASSOC);
            if (!$oldPermission) {
                throw new InvalidArgumentException('الصلاحية غير موجودة.');
            }
            [$roleName, $userId, $permissionKey, $scopeType, $scopeId, $isAllowed] = permissions_validate_payload($db, $roleLabels, $permissionLabels, $scopeLabels, $scopeTables, $permissionId);
            $stmt = $db->prepare('UPDATE assessment_permissions SET role_name = ?, user_id = ?, permission_key = ?, scope_type = ?, scope_id = ?, is_allowed = ? WHERE id = ?');
            $stmt->execute([$roleName, $userId, $permissionKey, $scopeType, $scopeId, $isAllowed, $permissionId]);
            ActivityLog::logUpdate('assessment_permission', $permissionId, $permissionKey, [
                'old' => $oldPermission,
                'new' => [
                    'role_name' => $roleName,
                    'user_id' => $userId,
                    'permission_key' => $permissionKey,
                    'scope_type' => $scopeType,
                    'scope_id' => $scopeId,
                    'is_allowed' => $isAllowed,
                ],
            ]);
            $_SESSION['success_message'] = 'تم تعديل صلاحية الدرجات.';
            permissions_redirect();
        }

        if ($action === 'toggle_assessment_permission') {
            $permissionId = (int) ($_POST['permission_id'] ?? 0);
            $permissionStmt = $db->prepare('SELECT * FROM assessment_permissions WHERE id = ? LIMIT 1');
            $permissionStmt->execute([$permissionId]);
            $permission = $permissionStmt->fetch(PDO::FETCH_ASSOC);
            if (!$permission) {
                throw new InvalidArgumentException('الصلاحية غير موجودة.');
            }
            $newAllowed = !empty($permission['is_allowed']) ? 0 : 1;
            $db->prepare('UPDATE assessment_permissions SET is_allowed = ? WHERE id = ?')->execute([$newAllowed, $permissionId]);
            ActivityLog::logUpdate('assessment_permission', $permissionId, (string) $permission['permission_key'], [
                'old_status' => !empty($permission['is_allowed']) ? 'allowed' : 'denied',
                'new_status' => $newAllowed ? 'allowed' : 'denied',
            ]);
            $_SESSION['success_message'] = 'تم تغيير حالة الصلاحية بنجاح.';
            permissions_redirect();
        }

        if ($action === 'delete_assessment_permission') {
            $permissionId = (int) ($_POST['permission_id'] ?? 0);
            $permissionStmt = $db->prepare('SELECT * FROM assessment_permissions WHERE id = ? LIMIT 1');
            $permissionStmt->execute([$permissionId]);
            $permission = $permissionStmt->fetch(PDO::FETCH_ASSOC);
            if (!$permission) {
                throw new InvalidArgumentException('الصلاحية غير موجودة.');
            }
            $db->prepare('DELETE FROM assessment_permissions WHERE id = ?')->execute([$permissionId]);
            ActivityLog::logDelete('assessment_permission', $permissionId, (string) $permission['permission_key'], [
                'role' => $permission['role_name'],
                'user_id' => $permission['user_id'],
                'scope' => $permission['scope_type'],
                'scope_id' => $permission['scope_id'],
            ]);
            $_SESSION['success_message'] = 'تم حذف صلاحية الدرجات.';
            permissions_redirect();
        }
    } catch (Throwable $e) {
        $_SESSION['error_message'] = $e->getMessage();
        permissions_redirect();
    }
}

$permissions = [];
$permissionUsers = [];
$permissionsCount = 0;
$allowedCount = 0;
$deniedCount = 0;
$userScopedCount = 0;

if ($permissionsReady) {
    $permissions = $db->query("SELECT ap.*, u.name AS user_name
        FROM assessment_permissions ap
        LEFT JOIN users u ON u.id = ap.user_id
        ORDER BY ap.id DESC")->fetchAll(PDO::FETCH_ASSOC) ?: [];
    $permissionsCount = count($permissions);
    foreach ($permissions as $permission) {
        if (!empty($permission['is_allowed'])) {
            $allowedCount++;
        } else {
            $deniedCount++;
        }
        if (!empty($permission['user_id'])) {
            $userScopedCount++;
        }
    }
    $permissionUsers = $db->query("SELECT u.id, u.name,
            GROUP_CONCAT(DISTINCT ura.role_key ORDER BY FIELD(ura.role_key, 'teacher', 'specialist', 'admin') SEPARATOR ', ') AS role
        FROM users u
        JOIN user_role_assignments ura ON ura.user_id = u.id AND ura.status = 'active'
        WHERE ura.role_key IN ('teacher', 'specialist', 'admin')
          AND u.status IN ('active', 'approved')
        GROUP BY u.id, u.name
        ORDER BY u.name")->fetchAll(PDO::FETCH_ASSOC) ?: [];
}

require_once '../includes/admin_header.php';
?>

<div class="admin-page-heading">
    <h1 class="h2"><i class="fas fa-user-shield me-2 text-primary"></i>صلاحيات الدرجات</h1>
    <div class="admin-top-actions no-print">
        <?php if ($permissionsReady): ?>
            <button type="button" class="btn btn-header-premium btn-success shadow-sm" data-bs-toggle="modal" data-bs-target="#addPermissionModal">
                <i class="fas fa-plus-circle me-2"></i>إضافة صلاحية
            </button>
        <?php endif; ?>
    </div>
</div>

<?php if (!empty($success_message)): ?>
    <div class="alert alert-success alert-dismissible fade show" role="alert"><i class="fas fa-check-circle me-2"></i><?php echo htmlspecialchars($success_message, ENT_QUOTES, 'UTF-8'); ?><button type="button" class="btn-close" data-bs-dismiss="alert"></button></div>
<?php endif; ?>
<?php if (!empty($error_message)): ?>
    <div class="alert alert-danger alert-dismissible fade show" role="alert"><i class="fas fa-exclamation-circle me-2"></i><?php echo htmlspecialchars($error_message, ENT_QUOTES, 'UTF-8'); ?><button type="button" class="btn-close" data-bs-dismiss="alert"></button></div>
<?php endif; ?>



<?php if (!$permissionsReady): ?>
    <div class="alert alert-warning"><i class="fas fa-triangle-exclamation me-2"></i>جدول صلاحيات الدرجات غير مطبق بعد.</div>
<?php else: ?>

<div class="row row-cols-2 row-cols-md-4 g-3 mb-4">
    <div class="col"><div class="stat-card" style="--card-gradient: linear-gradient(135deg, #3b82f6, #2563eb);"><div class="stat-card-icon"><i class="fas fa-user-shield"></i></div><div class="stat-card-info"><div class="stat-card-number counter" data-target="<?php echo (int)$permissionsCount; ?>">0</div><div class="stat-card-label">إجمالي الصلاحيات</div><div class="stat-card-sub">قواعد التحكم</div></div></div></div>
    <div class="col"><div class="stat-card" style="--card-gradient: linear-gradient(135deg, #10b981, #059669);"><div class="stat-card-icon"><i class="fas fa-check-circle"></i></div><div class="stat-card-info"><div class="stat-card-number counter" data-target="<?php echo (int)$allowedCount; ?>">0</div><div class="stat-card-label">مسموح</div><div class="stat-card-sub">تصاريح فعالة</div></div></div></div>
    <div class="col"><div class="stat-card" style="--card-gradient: linear-gradient(135deg, #ef4444, #dc2626);"><div class="stat-card-icon"><i class="fas fa-ban"></i></div><div class="stat-card-info"><div class="stat-card-number counter" data-target="<?php echo (int)$deniedCount; ?>">0</div><div class="stat-card-label">ممنوع</div><div class="stat-card-sub">استثناءات منع</div></div></div></div>
    <div class="col"><div class="stat-card" style="--card-gradient: linear-gradient(135deg, #8b5cf6, #7c3aed);"><div class="stat-card-icon"><i class="fas fa-user"></i></div><div class="stat-card-info"><div class="stat-card-number counter" data-target="<?php echo (int)$userScopedCount; ?>">0</div><div class="stat-card-label">لمستخدم محدد</div><div class="stat-card-sub">بدل الدور العام</div></div></div></div>
</div>

<div class="admin-list-surface">
        <div class="table-responsive admin-table-wrap">
            <table class="table table-hover table-striped align-middle datatable admin-data-table">
                <thead><tr><th>الدور/المستخدم</th><th>الصلاحية</th><th>النطاق</th><th>الحالة</th><th class="admin-col-150px">إجراءات</th></tr></thead>
                <tbody>
                <?php if (empty($permissions)): ?>
                    <tr><td colspan="5" class="text-center text-muted py-4">لم يتم تعريف صلاحيات درجات بعد.</td></tr>
                <?php else: ?>
                    <?php foreach ($permissions as $permission): ?>
                        <?php
                        $roleLabel = $roleLabels[$permission['role_name']] ?? $permission['role_name'];
                        $permissionLabel = $permissionLabels[$permission['permission_key']] ?? $permission['permission_key'];
                        $scopeLabel = $scopeLabels[$permission['scope_type']] ?? $permission['scope_type'];
                        ?>
                        <tr>
                            <td><?php echo htmlspecialchars($roleLabel, ENT_QUOTES, 'UTF-8'); ?><div class="small text-muted"><?php echo htmlspecialchars($permission['user_name'] ?? 'كل مستخدمي الدور', ENT_QUOTES, 'UTF-8'); ?></div></td>
                            <td><?php echo htmlspecialchars($permissionLabel, ENT_QUOTES, 'UTF-8'); ?><div class="small text-muted"><?php echo htmlspecialchars($permission['permission_key'], ENT_QUOTES, 'UTF-8'); ?></div></td>
                            <td><?php echo htmlspecialchars($scopeLabel . ($permission['scope_id'] ? ' #' . $permission['scope_id'] : ''), ENT_QUOTES, 'UTF-8'); ?></td>
                            <td><?php echo !empty($permission['is_allowed']) ? '<span class="badge bg-success">مسموح</span>' : '<span class="badge bg-danger">ممنوع</span>'; ?></td>
                            <td class="actions-column admin-table-actions">
                                <button type="button" class="btn btn-sm btn-action-pills btn-edit me-1 edit-permission-btn" data-bs-toggle="tooltip" title="تعديل"
                                        data-permission-id="<?php echo (int) $permission['id']; ?>"
                                        data-role-name="<?php echo htmlspecialchars($permission['role_name'], ENT_QUOTES, 'UTF-8'); ?>"
                                        data-user-id="<?php echo htmlspecialchars((string) ($permission['user_id'] ?? ''), ENT_QUOTES, 'UTF-8'); ?>"
                                        data-permission-key="<?php echo htmlspecialchars($permission['permission_key'], ENT_QUOTES, 'UTF-8'); ?>"
                                        data-scope-type="<?php echo htmlspecialchars($permission['scope_type'], ENT_QUOTES, 'UTF-8'); ?>"
                                        data-scope-id="<?php echo htmlspecialchars((string) ($permission['scope_id'] ?? ''), ENT_QUOTES, 'UTF-8'); ?>"
                                        data-is-allowed="<?php echo !empty($permission['is_allowed']) ? '1' : '0'; ?>"><i class="fas fa-edit"></i></button>
                                <button type="button" class="btn btn-sm me-1 btn-action-pills <?php echo !empty($permission['is_allowed']) ? 'btn-deactivate' : 'btn-activate'; ?> toggle-permission-btn" data-bs-toggle="tooltip" title="<?php echo !empty($permission['is_allowed']) ? 'منع' : 'سماح'; ?>" data-permission-id="<?php echo (int) $permission['id']; ?>" data-permission-name="<?php echo htmlspecialchars($permissionLabel . ' - ' . $roleLabel, ENT_QUOTES, 'UTF-8'); ?>" data-action-label="<?php echo !empty($permission['is_allowed']) ? 'منع' : 'سماح'; ?>"><i class="fas <?php echo !empty($permission['is_allowed']) ? 'fa-ban' : 'fa-check'; ?>"></i></button>
                                <button type="button" class="btn btn-sm btn-action-pills btn-delete delete-permission-btn" data-bs-toggle="tooltip" title="حذف" data-permission-id="<?php echo (int) $permission['id']; ?>" data-permission-name="<?php echo htmlspecialchars($permissionLabel . ' - ' . $roleLabel, ENT_QUOTES, 'UTF-8'); ?>"><i class="fas fa-trash"></i></button>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
                </tbody>
            </table>
        </div>
</div>

<?php
$permissionFormFields = static function (string $prefix) use ($roleLabels, $permissionLabels, $scopeLabels, $permissionUsers): void {
    $id = static fn(string $name): string => $prefix . ucfirst($name);
?>
    <div class="row g-3">
        <div class="col-md-6"><label class="form-label">الدور</label><select name="role_name" id="<?php echo $id('role'); ?>" class="form-select"><?php foreach ($roleLabels as $key => $label): ?><option value="<?php echo $key; ?>"><?php echo htmlspecialchars($label, ENT_QUOTES, 'UTF-8'); ?></option><?php endforeach; ?></select></div>
        <div class="col-md-6"><label class="form-label">المستخدم</label><select name="user_id" id="<?php echo $id('user'); ?>" class="form-select"><option value="">حسب الدور</option><?php foreach ($permissionUsers as $permissionUser): ?><option value="<?php echo (int) $permissionUser['id']; ?>"><?php echo htmlspecialchars($permissionUser['name'] . ' - ' . $permissionUser['role'], ENT_QUOTES, 'UTF-8'); ?></option><?php endforeach; ?></select></div>
        <div class="col-md-6"><label class="form-label">الصلاحية</label><select name="permission_key" id="<?php echo $id('key'); ?>" class="form-select"><?php foreach ($permissionLabels as $key => $label): ?><option value="<?php echo $key; ?>"><?php echo htmlspecialchars($label, ENT_QUOTES, 'UTF-8'); ?></option><?php endforeach; ?></select></div>
        <div class="col-md-3"><label class="form-label">نوع النطاق</label><select name="scope_type" id="<?php echo $id('scopeType'); ?>" class="form-select permission-scope-type"><?php foreach ($scopeLabels as $key => $label): ?><option value="<?php echo $key; ?>"><?php echo htmlspecialchars($label, ENT_QUOTES, 'UTF-8'); ?></option><?php endforeach; ?></select></div>
        <div class="col-md-3"><label class="form-label">ID النطاق</label><input type="number" name="scope_id" id="<?php echo $id('scopeId'); ?>" class="form-control permission-scope-id" min="1"></div>
        <div class="col-12"><div class="form-check"><input class="form-check-input" type="checkbox" name="is_allowed" id="<?php echo $id('allowed'); ?>" value="1" checked><label class="form-check-label" for="<?php echo $id('allowed'); ?>">مسموح</label></div></div>
    </div>
<?php
};
?>

<div class="modal fade" id="addPermissionModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-lg"><div class="modal-content admin-modal admin-modal-premium admin-modal-create"><form method="post" action="assessment_permissions.php">
        <?php echo csrfField(); ?><input type="hidden" name="action" value="add_assessment_permission">
        <div class="modal-header"><h5 class="modal-title"><i class="fas fa-plus-circle me-2"></i>إضافة صلاحية درجات</h5><button type="button" class="btn-close" data-bs-dismiss="modal"></button></div>
        <div class="modal-body"><?php $permissionFormFields('add'); ?></div>
        <div class="modal-footer"><button type="button" class="btn btn-secondary" data-bs-dismiss="modal"><i class="fas fa-times me-1"></i>إلغاء</button><button type="submit" class="btn btn-success"><i class="fas fa-save me-1"></i>حفظ</button></div>
    </form></div></div>
</div>

<div class="modal fade" id="editPermissionModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-lg"><div class="modal-content admin-modal admin-modal-premium admin-modal-edit"><form method="post" action="assessment_permissions.php">
        <?php echo csrfField(); ?><input type="hidden" name="action" value="update_assessment_permission"><input type="hidden" name="permission_id" id="editPermissionId">
        <div class="modal-header"><h5 class="modal-title"><i class="fas fa-edit me-2"></i>تعديل صلاحية درجات</h5><button type="button" class="btn-close" data-bs-dismiss="modal"></button></div>
        <div class="modal-body"><?php $permissionFormFields('edit'); ?></div>
        <div class="modal-footer"><button type="button" class="btn btn-secondary" data-bs-dismiss="modal"><i class="fas fa-times me-1"></i>إلغاء</button><button type="submit" class="btn btn-primary"><i class="fas fa-save me-1"></i>حفظ</button></div>
    </form></div></div>
</div>

<div class="modal fade" id="togglePermissionModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog"><div class="modal-content admin-modal admin-modal-premium admin-modal-warning" id="togglePermissionModalContent"><form method="post" action="assessment_permissions.php">
        <?php echo csrfField(); ?><input type="hidden" name="action" value="toggle_assessment_permission"><input type="hidden" name="permission_id" id="togglePermissionId">
        <div class="modal-header"><h5 class="modal-title"><i class="fas fa-toggle-on me-2" id="togglePermissionHeaderIcon"></i>تغيير حالة الصلاحية</h5><button type="button" class="btn-close" data-bs-dismiss="modal"></button></div>
        <div class="modal-body text-center">
            <div class="mb-3"><i class="fas fa-toggle-on text-warning admin-modal-icon-lg" id="togglePermissionBodyIcon"></i></div>
            <p>هل تريد <span id="togglePermissionAction" class="fw-bold"></span> صلاحية <span id="togglePermissionName" class="fw-bold text-primary"></span>؟</p>
        </div>
        <div class="modal-footer"><button type="button" class="btn btn-secondary" data-bs-dismiss="modal"><i class="fas fa-times me-1"></i>إلغاء</button><button type="submit" class="btn btn-warning" id="togglePermissionSubmit"><i class="fas fa-ban me-1"></i>تأكيد</button></div>
    </form></div></div>
</div>

<div class="modal fade" id="deletePermissionModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog"><div class="modal-content admin-modal admin-modal-premium admin-modal-delete"><form method="post" action="assessment_permissions.php">
        <?php echo csrfField(); ?><input type="hidden" name="action" value="delete_assessment_permission"><input type="hidden" name="permission_id" id="deletePermissionId">
        <div class="modal-header"><h5 class="modal-title"><i class="fas fa-trash me-2"></i>حذف صلاحية درجات</h5><button type="button" class="btn-close" data-bs-dismiss="modal"></button></div>
        <div class="modal-body text-center"><i class="fas fa-triangle-exclamation text-danger mb-3 admin-modal-icon-lg"></i><p>هل تريد حذف صلاحية <span id="deletePermissionName" class="fw-bold text-primary"></span>؟</p></div>
        <div class="modal-footer"><button type="button" class="btn btn-secondary" data-bs-dismiss="modal"><i class="fas fa-times me-1"></i>إلغاء</button><button type="submit" class="btn btn-danger"><i class="fas fa-trash me-1"></i>حذف</button></div>
    </form></div></div>
</div>

<script>
document.addEventListener('DOMContentLoaded', function () {
    function showModal(id) { const el = document.getElementById(id); if (el && window.bootstrap) new bootstrap.Modal(el).show(); }
    function setValue(id, value) { const el = document.getElementById(id); if (el) el.value = value || ''; }
    function setChecked(id, value) { const el = document.getElementById(id); if (el) el.checked = value === '1'; }
    function syncScopeInput(select) {
        const modal = select.closest('.modal');
        if (!modal) return;
        const input = modal.querySelector('.permission-scope-id');
        if (input) {
            const isGlobal = select.value === 'global';
            input.disabled = isGlobal;
            if (isGlobal) input.value = '';
        }
    }
    document.querySelectorAll('.permission-scope-type').forEach(function (select) {
        select.addEventListener('change', function () { syncScopeInput(this); });
        syncScopeInput(select);
    });
    document.querySelectorAll('.edit-permission-btn').forEach(function (button) {
        button.addEventListener('click', function () {
            setValue('editPermissionId', this.dataset.permissionId);
            setValue('editRole', this.dataset.roleName);
            setValue('editUser', this.dataset.userId);
            setValue('editKey', this.dataset.permissionKey);
            setValue('editScopeType', this.dataset.scopeType);
            setValue('editScopeId', this.dataset.scopeId);
            setChecked('editAllowed', this.dataset.isAllowed);
            const scopeSelect = document.getElementById('editScopeType');
            if (scopeSelect) syncScopeInput(scopeSelect);
            showModal('editPermissionModal');
        });
    });
    document.querySelectorAll('.toggle-permission-btn').forEach(function (button) {
        button.addEventListener('click', function () {
            setValue('togglePermissionId', this.dataset.permissionId);
            const actionLabel = this.dataset.actionLabel || '';
            const isActive = actionLabel === 'تعطيل';
            document.getElementById('togglePermissionName').textContent = this.dataset.permissionName || '';
            document.getElementById('togglePermissionAction').textContent = actionLabel;

            const submitButton = document.getElementById('togglePermissionSubmit');
            if (submitButton) {
                submitButton.className = isActive ? 'btn btn-warning' : 'btn btn-success';
                submitButton.innerHTML = isActive ? '<i class="fas fa-ban me-1"></i>تعطيل' : '<i class="fas fa-check me-1"></i>تفعيل';
            }

            const modalContent = document.getElementById('togglePermissionModalContent');
            if (modalContent) {
                modalContent.classList.toggle('admin-modal-warning', isActive);
                modalContent.classList.toggle('admin-modal-create', !isActive);
            }
            const bodyIcon = document.getElementById('togglePermissionBodyIcon');
            const headerIcon = document.getElementById('togglePermissionHeaderIcon');
            if (bodyIcon) {
                bodyIcon.className = isActive ? 'fas fa-ban text-warning admin-modal-icon-lg' : 'fas fa-check-circle text-success admin-modal-icon-lg';
            }
            if (headerIcon) {
                headerIcon.className = isActive ? 'fas fa-ban me-2' : 'fas fa-check-circle me-2';
            }

            showModal('togglePermissionModal');
        });
    });
    document.querySelectorAll('.delete-permission-btn').forEach(function (button) {
        button.addEventListener('click', function () {
            setValue('deletePermissionId', this.dataset.permissionId);
            document.getElementById('deletePermissionName').textContent = this.dataset.permissionName || '';
            showModal('deletePermissionModal');
        });
    });
});
</script>
<?php endif; ?>

<?php require_once '../includes/admin_footer.php'; ?>

