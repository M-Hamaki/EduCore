<?php
/**
 * إدارة المعلمين الخارجيين
 * Admin - External Teachers Management
 */
$page_title = "المعلمين الخارجيين";
$custom_page_title = true;

require_once '../config/database.php';
require_once '../config/encryption.php';
require_once '../classes/utilities.php';
require_once '../classes/ActivityLog.php';
require_once '../includes/session_config.php';
require_once '../includes/csrf.php';
require_once '../src/Modules/Operations/Audit/AuditService.php';

// التحقق من صلاحيات الأدمن أولاً وقبل أي معالجة أو اتصال بقاعدة البيانات
Utilities::validateSession('admin');
requireCsrfPost();

$database = new Database();
$db = $database->getConnection();

$success_message = $_SESSION['success_message'] ?? '';
$error_message = $_SESSION['error_message'] ?? '';
unset($_SESSION['success_message'], $_SESSION['error_message']);

// ===== معالجة الطلبات =====
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    // تغيير حالة المعلم
    if ($action === 'change_status' && isset($_POST['teacher_id'], $_POST['new_status'])) {
        $tid = intval($_POST['teacher_id']);
        $newStatus = $_POST['new_status'];
        if (in_array($newStatus, ['active', 'pending', 'blocked'])) {
            try {
                $db->beginTransaction();
                $beforeStmt = $db->prepare('SELECT * FROM external_teachers WHERE id = ? FOR UPDATE');
                $beforeStmt->execute([$tid]);
                $before = $beforeStmt->fetch(PDO::FETCH_ASSOC);
                if (!$before) {
                    throw new RuntimeException('External teacher not found.');
                }
                $stmt = $db->prepare("UPDATE external_teachers SET status = ? WHERE id = ?");
                $stmt->execute([$newStatus, $tid]);
                $after = $before;
                $after['status'] = $newStatus;
                (new \EduCore\Modules\Operations\Audit\AuditService($db))->recordUpdate(
                    'external_teacher', 'external_teachers', $tid, (string)$before['name'],
                    $before, $after, 'تغيير حالة حساب معلم خارجي'
                );
                $db->commit();
                $statusLabels = ['active' => 'تم تفعيل حساب المعلم', 'blocked' => 'تم حظر حساب المعلم', 'pending' => 'تم تعليق الحساب'];
                $_SESSION['success_message'] = $statusLabels[$newStatus] . ' بنجاح';
            } catch (Throwable $e) {
                if ($db->inTransaction()) {
                    $db->rollBack();
                }
                error_log('external teacher status error: ' . $e->getMessage());
                $_SESSION['error_message'] = 'تعذر تغيير حالة المعلم.';
            }
        }
        header("Location: external_teachers.php" . Utilities::buildQueryString(['filter' => $_GET['filter'] ?? '']));
        exit();
    }

    // حذف معلم
    elseif ($action === 'delete' && isset($_POST['teacher_id'])) {
        $tid = intval($_POST['teacher_id']);
        try {
            $db->beginTransaction();
            $beforeStmt = $db->prepare('SELECT * FROM external_teachers WHERE id = ? FOR UPDATE');
            $beforeStmt->execute([$tid]);
            $before = $beforeStmt->fetch(PDO::FETCH_ASSOC);
            if (!$before) {
                throw new RuntimeException('External teacher not found.');
            }
            $stmt = $db->prepare("DELETE FROM external_teachers WHERE id = ?");
            $stmt->execute([$tid]);
            (new \EduCore\Modules\Operations\Audit\AuditService($db))->recordDelete(
                'external_teacher', 'external_teachers', $tid, (string)$before['name'],
                $before, 'حذف حساب معلم خارجي'
            );
            $db->commit();
            $_SESSION['success_message'] = 'تم حذف المعلم بنجاح';
        } catch (Throwable $e) {
            if ($db->inTransaction()) {
                $db->rollBack();
            }
            error_log('external teacher delete error: ' . $e->getMessage());
            $_SESSION['error_message'] = 'تعذر حذف المعلم.';
        }
        header("Location: external_teachers.php" . Utilities::buildQueryString(['filter' => $_GET['filter'] ?? '']));
        exit();
    }

    // حفظ إعدادات الخدمات
    elseif ($action === 'save_services') {
        $services = $_POST['services'] ?? [];
        $json = json_encode(array_values($services));
        $regEnabled = isset($_POST['registration_enabled']) ? '1' : '0';
        $autoApprove = isset($_POST['auto_approve']) ? '1' : '0';
        $afterSettings = [
            'external_teacher_services' => $json,
            'external_registration_enabled' => $regEnabled,
            'external_auto_approve' => $autoApprove,
        ];
        try {
            $db->beginTransaction();
            $beforeStmt = $db->query("SELECT setting_key, setting_value FROM settings WHERE setting_key IN ('external_teacher_services', 'external_registration_enabled', 'external_auto_approve') FOR UPDATE");
            $beforeSettings = $beforeStmt->fetchAll(PDO::FETCH_KEY_PAIR);
            $stmt = $db->prepare('UPDATE settings SET setting_value = ? WHERE setting_key = ?');
            foreach ($afterSettings as $key => $value) {
                $stmt->execute([$value, $key]);
            }
            (new \EduCore\Modules\Operations\Audit\AuditService($db))->recordEvent(
                'update', 'external_teacher_settings', null, 'إعدادات المعلمين الخارجيين',
                [
                    'changes' => \EduCore\Modules\Operations\Audit\EntityChangeTracker::diff($beforeSettings, $afterSettings),
                    'undo_policy' => 'settings_batch_restore_not_enabled',
                ]
            );
            $db->commit();
            $_SESSION['success_message'] = 'تم حفظ الإعدادات بنجاح';
        } catch (Throwable $e) {
            if ($db->inTransaction()) {
                $db->rollBack();
            }
            error_log('external teacher settings error: ' . $e->getMessage());
            $_SESSION['error_message'] = 'تعذر حفظ إعدادات المعلمين الخارجيين.';
        }
        header("Location: external_teachers.php" . Utilities::buildQueryString(['filter' => $_GET['filter'] ?? '']));
        exit();
    }

    // تفعيل الكل
    elseif ($action === 'approve_all_pending') {
        try {
            $db->beginTransaction();
            $pendingStmt = $db->query("SELECT * FROM external_teachers WHERE status = 'pending' FOR UPDATE");
            $pending = $pendingStmt->fetchAll(PDO::FETCH_ASSOC);
            $stmt = $db->prepare("UPDATE external_teachers SET status = 'active' WHERE status = 'pending'");
            $stmt->execute();
            $items = [];
            foreach ($pending as $teacher) {
                $after = $teacher;
                $after['status'] = 'active';
                $items[] = [
                    'table' => 'external_teachers',
                    'record_id' => (int)$teacher['id'],
                    'before' => $teacher,
                    'after' => $after,
                    'description' => 'تفعيل جماعي لمعلم خارجي',
                ];
            }
            $count = count($items);
            if ($count > 0) {
                (new \EduCore\Modules\Operations\Audit\AuditService($db))->recordCompositeUpdate(
                    'external_teacher_bulk_status', null, 'تفعيل المعلمين الخارجيين المعلقين',
                    $items, ['count' => $count]
                );
            }
            $db->commit();
            $_SESSION['success_message'] = "تم تفعيل {$count} معلم معلق بنجاح";
        } catch (Throwable $e) {
            if ($db->inTransaction()) {
                $db->rollBack();
            }
            error_log('external teacher bulk approval error: ' . $e->getMessage());
            $_SESSION['error_message'] = 'تعذر تفعيل المعلمين المعلقين.';
        }
        header("Location: external_teachers.php" . Utilities::buildQueryString(['filter' => $_GET['filter'] ?? '']));
        exit();
    }

    // تعديل بيانات المعلم
    elseif ($action === 'edit_teacher' && isset($_POST['teacher_id'])) {
        $tid = intval($_POST['teacher_id']);
        $name = trim($_POST['edit_name'] ?? '');
        $email = trim($_POST['edit_email'] ?? '');
        $phone = trim($_POST['edit_phone'] ?? '');
        $school = trim($_POST['edit_school'] ?? '');
        $spec = trim($_POST['edit_specialization'] ?? '');
        $newPass = trim($_POST['edit_password'] ?? '');

        if (empty($name) || empty($email)) {
            $error_message = 'الاسم والبريد الإلكتروني مطلوبان';
        } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $error_message = 'البريد الإلكتروني غير صالح';
        } else {
            // التحقق من عدم تكرار البريد
            $checkStmt = $db->prepare("SELECT id FROM external_teachers WHERE email = ? AND id != ?");
            $checkStmt->execute([$email, $tid]);
            if ($checkStmt->fetch()) {
                $error_message = 'البريد الإلكتروني مستخدم بالفعل من معلم آخر';
            } else {
                try {
                    $db->beginTransaction();
                    $beforeStmt = $db->prepare('SELECT * FROM external_teachers WHERE id = ? FOR UPDATE');
                    $beforeStmt->execute([$tid]);
                    $before = $beforeStmt->fetch(PDO::FETCH_ASSOC);
                    if (!$before) {
                        throw new RuntimeException('External teacher not found.');
                    }
                    $stmt = $db->prepare("UPDATE external_teachers SET name = ?, email = ?, phone = ?, school_name = ?, specialization = ? WHERE id = ?");
                    $stmt->execute([$name, $email, $phone, $school, $spec, $tid]);
                    if (!empty($newPass)) {
                        $encrypted = encryptPassword($newPass);
                        $passStmt = $db->prepare("UPDATE external_teachers SET password_hash = ? WHERE id = ?");
                        $passStmt->execute([$encrypted, $tid]);
                    }
                    $afterStmt = $db->prepare('SELECT * FROM external_teachers WHERE id = ?');
                    $afterStmt->execute([$tid]);
                    $after = $afterStmt->fetch(PDO::FETCH_ASSOC) ?: [];
                    $audit = new \EduCore\Modules\Operations\Audit\AuditService($db);
                    if ($newPass === '') {
                        $audit->recordUpdate(
                            'external_teacher', 'external_teachers', $tid, $name,
                            $before, $after, 'تعديل بيانات معلم خارجي'
                        );
                    } else {
                        $audit->recordEvent(
                            'security_update', 'external_teacher', $tid, $name,
                            [
                                'changes' => \EduCore\Modules\Operations\Audit\EntityChangeTracker::diff($before, $after),
                                'password_changed' => true,
                                'undo_policy' => 'credential_change_not_direct_undo',
                            ]
                        );
                    }
                    $db->commit();
                    $_SESSION['success_message'] = 'تم تعديل بيانات المعلم بنجاح';
                } catch (Throwable $e) {
                    if ($db->inTransaction()) {
                        $db->rollBack();
                    }
                    error_log('external teacher update error: ' . $e->getMessage());
                    $error_message = 'تعذر تعديل بيانات المعلم.';
                }
            }
        }
        $_SESSION['error_message'] = $error_message;
        header("Location: external_teachers.php" . Utilities::buildQueryString(['filter' => $_GET['filter'] ?? '']));
        exit();
    }
}

// ===== جلب البيانات =====

// الإعدادات
$svcStmt = $db->prepare("SELECT setting_value FROM settings WHERE setting_key = 'external_teacher_services'");
$svcStmt->execute();
$allowed_services = json_decode($svcStmt->fetchColumn() ?: '[]', true);

$regStmt = $db->prepare("SELECT setting_value FROM settings WHERE setting_key = 'external_registration_enabled'");
$regStmt->execute();
$registration_enabled = ($regStmt->fetchColumn() ?: '1') === '1';

$autoStmt = $db->prepare("SELECT setting_value FROM settings WHERE setting_key = 'external_auto_approve'");
$autoStmt->execute();
$auto_approve = ($autoStmt->fetchColumn() ?: '0') === '1';

// قائمة الخدمات المتاحة
$available_services = [
    'lesson_prep' => 'تحضير الدروس بالذكاء الاصطناعي',
    'training' => 'التطوير المهني والتدريبات',
];

// إحصائيات
$statsStmt = $db->query("SELECT 
    COUNT(*) as total,
    SUM(status = 'active') as active,
    SUM(status = 'pending') as pending,
    SUM(status = 'blocked') as blocked
    FROM external_teachers");
$stats = $statsStmt->fetch(PDO::FETCH_ASSOC);

// قائمة المعلمين
$filter = $_GET['filter'] ?? 'all';
$where = '';
if ($filter === 'active') $where = " WHERE et.status = 'active'";
elseif ($filter === 'pending') $where = " WHERE et.status = 'pending'";
elseif ($filter === 'blocked') $where = " WHERE et.status = 'blocked'";

$teachersStmt = $db->query("SELECT et.*, (SELECT COUNT(*) FROM ai_lessons WHERE teacher_id = et.id) as prepared_lessons_count FROM external_teachers et{$where} ORDER BY et.created_at DESC");
$teachers = $teachersStmt->fetchAll(PDO::FETCH_ASSOC);

// رابط الصفحة الخارجية
$external_url = (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off' ? 'https' : 'http') . '://' . $_SERVER['HTTP_HOST'] . dirname($_SERVER['SCRIPT_NAME']) . '/../external_login.php';
$external_url = str_replace('/admin/../', '/', $external_url);

require_once '../includes/admin_header.php';
?>

<!-- عنوان الصفحة والأزرار الإدارية -->
<div class="admin-page-heading">
    <div>
        <h1 class="h2"><i class="fas fa-globe text-primary me-2"></i>المعلمين الخارجيين</h1>
        <p class="text-muted mb-0">إدارة حسابات وصلاحيات المعلمين الخارجيين وتفعيل الخدمات المتاحة لهم</p>
    </div>
    <div class="admin-top-actions">
        <?php if ($stats['pending'] > 0): ?>
            <button class="btn btn-success shadow-sm" onclick="confirmApproveAll()">
                <i class="fas fa-check-double me-2"></i>تفعيل جميع المعلقين (<?php echo (int)$stats['pending']; ?>)
            </button>
        <?php endif; ?>
    </div>
</div>

<!-- التنبيهات -->
<?php if ($success_message): ?>
    <div class="alert alert-success alert-dismissible fade show" role="alert">
        <i class="fas fa-check-circle me-2"></i><?php echo htmlspecialchars($success_message); ?>
        <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
    </div>
<?php endif; ?>
<?php if ($error_message): ?>
    <div class="alert alert-danger alert-dismissible fade show" role="alert">
        <i class="fas fa-exclamation-circle me-2"></i><?php echo htmlspecialchars($error_message); ?>
        <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
    </div>
<?php endif; ?>

<!-- الإحصائيات -->
<div class="row row-cols-2 row-cols-md-4 g-3 mb-4 widget-card-group">
    <div class="col animate-up delay-1">
        <div class="stat-card" style="--card-gradient: var(--primary-gradient);">
            <div class="stat-card-icon"><i class="fas fa-users"></i></div>
            <div class="stat-card-info">
                <div class="stat-card-number counter" data-target="<?php echo (int)($stats['total'] ?? 0); ?>">0</div>
                <div class="stat-card-label">إجمالي المعلمين</div>
                <div class="stat-card-sub"><i class="fas fa-globe"></i> جميع المسجلين بالمنصة</div>
            </div>
        </div>
    </div>
    <div class="col animate-up delay-2">
        <div class="stat-card" style="--card-gradient: var(--success-gradient);">
            <div class="stat-card-icon"><i class="fas fa-check-circle"></i></div>
            <div class="stat-card-info">
                <div class="stat-card-number counter" data-target="<?php echo (int)($stats['active'] ?? 0); ?>">0</div>
                <div class="stat-card-label">نشط</div>
                <div class="stat-card-sub"><i class="fas fa-circle text-white"></i> معلمين مفعّلين</div>
            </div>
        </div>
    </div>
    <div class="col animate-up delay-3">
        <div class="stat-card" style="--card-gradient: var(--warning-gradient);">
            <div class="stat-card-icon"><i class="fas fa-clock"></i></div>
            <div class="stat-card-info">
                <div class="stat-card-number counter" data-target="<?php echo (int)($stats['pending'] ?? 0); ?>">0</div>
                <div class="stat-card-label">في الانتظار</div>
                <div class="stat-card-sub"><i class="fas fa-hourglass-half text-white"></i> بانتظار التفعيل</div>
            </div>
        </div>
    </div>
    <div class="col animate-up delay-4">
        <div class="stat-card" style="--card-gradient: var(--danger-gradient);">
            <div class="stat-card-icon"><i class="fas fa-ban"></i></div>
            <div class="stat-card-info">
                <div class="stat-card-number counter" data-target="<?php echo (int)($stats['blocked'] ?? 0); ?>">0</div>
                <div class="stat-card-label">محظور</div>
                <div class="stat-card-sub"><i class="fas fa-times-circle text-white"></i> معلمين محظورين</div>
            </div>
        </div>
    </div>
</div>

<!-- الحاويات العلوية: رابط التسجيل الخارجي وإعدادات الخدمات والتسجيل -->
<div class="row g-4 mb-4">
    <!-- رابط التسجيل -->
    <div class="col-md-6 col-lg-5">
        <div class="card shadow-sm border-0 h-100" style="border-radius: 12px;">
            <div class="card-header bg-white border-bottom py-3">
                <h5 class="mb-0 fw-bold text-primary"><i class="fas fa-link me-2"></i>رابط التسجيل الخارجي</h5>
            </div>
            <div class="card-body">
                <p class="text-muted small mb-2">شارك هذا الرابط مع المعلمين الخارجيين ليتمكنوا من التسجيل:</p>
                <div class="input-group">
                    <input type="text" class="form-control form-control-sm" id="externalLink" value="<?php echo htmlspecialchars($external_url); ?>" readonly dir="ltr">
                    <button class="btn btn-outline-primary btn-sm" onclick="copyLink()" title="نسخ الرابط"><i class="fas fa-copy me-1"></i>نسخ</button>
                </div>
                <div id="copyMsg" class="text-success small mt-2 d-none"><i class="fas fa-check me-1"></i>تم نسخ الرابط بنجاح</div>
            </div>
        </div>
    </div>

    <!-- إعدادات الخدمات والتسجيل -->
    <div class="col-md-6 col-lg-7">
        <div class="card shadow-sm border-0 h-100" style="border-radius: 12px;">
            <form method="POST">
                <?php echo csrfField(); ?>
                <input type="hidden" name="action" value="save_services">
                <div class="card-header bg-white border-bottom py-2 d-flex justify-content-between align-items-center">
                    <h5 class="mb-0 fw-bold text-primary"><i class="fas fa-cog me-2"></i>إعدادات الخدمات والتسجيل</h5>
                    <button type="submit" class="btn btn-primary btn-sm px-3 shadow-sm"><i class="fas fa-save me-1"></i>حفظ الإعدادات</button>
                </div>
                <div class="card-body">
                    <div class="row">
                        <div class="col-md-6 mb-3 mb-md-0">
                            <h6 class="fw-bold mb-3"><i class="fas fa-concierge-bell me-2 text-primary"></i>الخدمات المتاحة لهم</h6>
                            <?php foreach ($available_services as $key => $label): ?>
                                <div class="form-check mb-2">
                                    <input class="form-check-input" type="checkbox" name="services[]" value="<?php echo $key; ?>"
                                        id="svc_<?php echo $key; ?>" <?php echo in_array($key, $allowed_services) ? 'checked' : ''; ?>>
                                    <label class="form-check-label" for="svc_<?php echo $key; ?>"><?php echo htmlspecialchars($label); ?></label>
                                </div>
                            <?php endforeach; ?>
                        </div>
                        <div class="col-md-6">
                            <h6 class="fw-bold mb-3"><i class="fas fa-sliders-h me-2 text-primary"></i>خيارات التسجيل والموافقة</h6>
                            <div class="form-check form-switch mb-2">
                                <input class="form-check-input" type="checkbox" name="registration_enabled" id="regEnabled"
                                    <?php echo $registration_enabled ? 'checked' : ''; ?>>
                                <label class="form-check-label" for="regEnabled">السماح بالتسجيل الجديد</label>
                            </div>
                            <div class="form-check form-switch mb-2">
                                <input class="form-check-input" type="checkbox" name="auto_approve" id="autoApprove"
                                    <?php echo $auto_approve ? 'checked' : ''; ?>>
                                <label class="form-check-label" for="autoApprove">الموافقة التلقائية على الحسابات الجديدة</label>
                            </div>
                        </div>
                    </div>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- قائمة المعلمين الخارجيين بعرض الشاشة الكاملة -->
<div class="admin-filter-bar">
    <div class="admin-filter-controls">
        <form method="GET" class="d-flex align-items-center gap-2 mb-0">
            <select class="form-select form-select-sm" name="filter" style="width:auto; min-width:140px;" onchange="this.form.submit()">
                <option value="all" <?php echo $filter === 'all' ? 'selected' : ''; ?>>كل الحالات</option>
                <option value="active" <?php echo $filter === 'active' ? 'selected' : ''; ?>>نشط</option>
                <option value="pending" <?php echo $filter === 'pending' ? 'selected' : ''; ?>>في الانتظار</option>
                <option value="blocked" <?php echo $filter === 'blocked' ? 'selected' : ''; ?>>محظور</option>
            </select>
        </form>
    </div>
    <div class="admin-filter-actions">
        <a href="external_teachers.php" class="btn btn-light btn-sm" title="إعادة تعيين الفلاتر">
            <i class="fas fa-undo me-1"></i>إعادة تعيين
        </a>
    </div>
</div>

<div class="admin-list-surface mb-4">
    <?php if (empty($teachers)): ?>
        <div class="text-center text-muted py-5">
            <i class="fas fa-inbox fa-3x mb-3 d-block opacity-25"></i>
            لا يوجد معلمون خارجيون حالياً ضمن هذا التصنيف
        </div>
    <?php else: ?>
        <div class="table-responsive admin-table-wrap">
            <table class="table table-hover table-striped datatable admin-data-table">
                <thead>
                    <tr>
                        <th width="50">#</th>
                        <th>الكود</th>
                        <th>الاسم</th>
                        <th>البريد الإلكتروني</th>
                        <th>المدرسة</th>
                        <th>التخصص</th>
                        <th class="text-center">الدروس المحضرة</th>
                        <th>الحالة</th>
                        <th>تاريخ التسجيل</th>
                        <th width="140" class="text-center actions-column">إجراءات</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($teachers as $i => $t): 
                        unset($t['password_hash']);
                    ?>
                        <tr>
                            <td><?php echo $i + 1; ?></td>
                            <td><small class="text-muted" dir="ltr"><?php echo htmlspecialchars($t['teacher_code'] ?? '-'); ?></small></td>
                            <td class="fw-bold text-primary"><?php echo htmlspecialchars($t['name']); ?></td>
                            <td dir="ltr" class="text-start"><small><?php echo htmlspecialchars($t['email']); ?></small></td>
                            <td><?php echo htmlspecialchars($t['school_name'] ?: '—'); ?></td>
                            <td><?php echo htmlspecialchars($t['specialization'] ?: '—'); ?></td>
                            <td class="text-center">
                                <span class="badge bg-primary-subtle text-primary fw-bold px-2 py-1" style="font-size: 0.85rem;">
                                    <i class="fas fa-book-open me-1"></i><?php echo (int)($t['prepared_lessons_count'] ?? 0); ?>
                                </span>
                            </td>
                            <td>
                                <?php
                                $badges = [
                                    'active' => 'bg-success-subtle text-success',
                                    'pending' => 'bg-warning-subtle text-warning-emphasis',
                                    'blocked' => 'bg-danger-subtle text-danger'
                                ];
                                $labels = ['active' => 'نشط', 'pending' => 'معلق', 'blocked' => 'محظور'];
                                ?>
                                <span class="badge <?php echo $badges[$t['status']]; ?>"><?php echo $labels[$t['status']]; ?></span>
                            </td>
                            <td><small class="text-muted"><?php echo date('Y/m/d', strtotime($t['created_at'])); ?></small></td>
                            <td class="text-center actions-column admin-table-actions">
                                <?php if ($t['status'] !== 'active'): ?>
                                    <button type="button" class="btn btn-action-pills btn-activate me-1" data-bs-toggle="tooltip" title="تفعيل الحساب" onclick="confirmStatusChange(<?php echo $t['id']; ?>, '<?php echo htmlspecialchars($t['name'], ENT_QUOTES, 'UTF-8'); ?>', 'active')">
                                        <i class="fas fa-check"></i>
                                    </button>
                                <?php endif; ?>
                                <?php if ($t['status'] !== 'blocked'): ?>
                                    <button type="button" class="btn btn-action-pills btn-deactivate me-1" data-bs-toggle="tooltip" title="حظر الحساب" onclick="confirmStatusChange(<?php echo $t['id']; ?>, '<?php echo htmlspecialchars($t['name'], ENT_QUOTES, 'UTF-8'); ?>', 'blocked')">
                                        <i class="fas fa-ban"></i>
                                    </button>
                                <?php endif; ?>
                                <button type="button" class="btn btn-action-pills btn-edit me-1" data-bs-toggle="tooltip" title="تعديل البيانات" onclick="editTeacher(<?php echo htmlspecialchars(json_encode($t), ENT_QUOTES, 'UTF-8'); ?>)">
                                    <i class="fas fa-edit"></i>
                                </button>
                                <button type="button" class="btn btn-action-pills btn-delete" data-bs-toggle="tooltip" title="حذف المعلم" onclick="confirmDelete(<?php echo $t['id']; ?>, '<?php echo htmlspecialchars($t['name'], ENT_QUOTES, 'UTF-8'); ?>')">
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

<!-- ==================== مودالات التأكيد المخصصة ==================== -->

<!-- مودال تغيير حالة المعلم (تفعيل أو حظر) -->
<div class="modal fade" id="confirmStatusModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog">
        <div class="modal-content admin-modal admin-modal-premium admin-modal-warning" id="statusModalContent">
<form method="POST">
    <?php echo csrfField(); ?>
                <input type="hidden" name="action" value="change_status">
                <input type="hidden" name="teacher_id" id="statusTeacherId">
                <input type="hidden" name="new_status" id="statusNewValue">
                
                <div class="modal-header">
                    <h5 class="modal-title"><i class="fas fa-info-circle me-2"></i><span id="statusModalTitle">تغيير حالة المعلم</span></h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body text-center">
                    <div class="mb-3">
                        <i id="statusModalIcon" class="fas fa-question-circle" style="font-size: 3rem;"></i>
                    </div>
                    <p class="fs-5" id="statusModalText">هل أنت متأكد من تغيير حالة المعلم؟</p>
                    <div class="alert" id="statusModalAlert">
                        <i class="fas fa-exclamation-triangle me-2"></i>
                        <span id="statusModalAlertText">سيتم تغيير حالة المعلم فوراً وتحديث صلاحيات وصوله للمنصة.</span>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">
                        <i class="fas fa-times me-1"></i>إلغاء
                    </button>
                    <button type="submit" class="btn" id="statusModalSubmitBtn">
                        <i class="fas fa-check me-1"></i>تأكيد
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- مودال حذف المعلم الخارجي -->
<div class="modal fade" id="confirmDeleteModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog">
        <div class="modal-content admin-modal admin-modal-premium admin-modal-delete">
<form method="POST">
    <?php echo csrfField(); ?>
                <input type="hidden" name="action" value="delete">
                <input type="hidden" name="teacher_id" id="deleteTeacherId">
                
                <div class="modal-header">
                    <h5 class="modal-title"><i class="fas fa-trash me-2"></i>حذف المعلم الخارجي</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body text-center">
                    <div class="mb-3 text-danger">
                        <i class="fas fa-exclamation-circle" style="font-size: 3rem;"></i>
                    </div>
                    <p class="fs-5">هل أنت متأكد من حذف المعلم <span class="fw-bold text-primary" id="deleteTeacherName"></span>؟</p>
                    <div class="alert alert-danger">
                        <i class="fas fa-exclamation-triangle me-2"></i>
                        تحذير: هذا الإجراء نهائي ولا يمكن التراجع عنه! سيتم مسح حساب المعلم نهائياً وجميع البيانات المرتبطة به.
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">
                        <i class="fas fa-times me-1"></i>إلغاء
                    </button>
                    <button type="submit" class="btn btn-danger">
                        <i class="fas fa-trash-alt me-1"></i>حذف نهائي
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- مودال تفعيل جميع المعلقين دفعة واحدة -->
<div class="modal fade" id="confirmApproveAllModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog">
        <div class="modal-content admin-modal admin-modal-premium admin-modal-create">
<form method="POST">
    <?php echo csrfField(); ?>
                <input type="hidden" name="action" value="approve_all_pending">
                
                <div class="modal-header">
                    <h5 class="modal-title"><i class="fas fa-check-double me-2"></i>تفعيل جميع المعلقين</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body text-center">
                    <div class="mb-3 text-success">
                        <i class="fas fa-users-cog" style="font-size: 3rem;"></i>
                    </div>
                    <p class="fs-5">هل أنت متأكد من تفعيل جميع المعلمين الخارجيين المعلقين حالياً؟</p>
                    <div class="alert alert-success">
                        <i class="fas fa-info-circle me-2"></i>
                        سيتم تنشيط كافة حسابات المعلمين المسجلين المعلقين حالياً دفعة واحدة، ليتمكنوا من تسجيل الدخول فوراً.
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">
                        <i class="fas fa-times me-1"></i>إلغاء
                    </button>
                    <button type="submit" class="btn btn-success">
                        <i class="fas fa-check me-1"></i>تأكيد التفعيل
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- مودال تعديل بيانات المعلم -->
<div class="modal fade" id="editTeacherModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog">
        <div class="modal-content admin-modal admin-modal-premium admin-modal-edit">
<form method="POST">
    <?php echo csrfField(); ?>
                <input type="hidden" name="action" value="edit_teacher">
                <input type="hidden" name="teacher_id" id="editTeacherId">
                <div class="modal-header">
                    <h5 class="modal-title"><i class="fas fa-edit me-2"></i>تعديل بيانات المعلم</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <div class="mb-3">
                        <label class="form-label fw-bold">كود المعلم</label>
                        <input type="text" class="form-control" id="editTeacherCode" readonly dir="ltr" style="background-color: #e9ecef;">
                    </div>
                    <div class="mb-3">
                        <label class="form-label fw-bold">الاسم <span class="text-danger">*</span></label>
                        <input type="text" class="form-control" name="edit_name" id="editName" required>
                    </div>
                    <div class="mb-3">
                        <label class="form-label fw-bold">البريد الإلكتروني <span class="text-danger">*</span></label>
                        <input type="email" class="form-control" name="edit_email" id="editEmail" required dir="ltr">
                    </div>
                    
                    <!-- حقل كلمة المرور الموحد (للاستعراض والتعديل) -->
                    <div class="mb-3">
                        <label class="form-label fw-bold">كلمة المرور</label>
                        <div class="input-group">
                            <input type="password" class="form-control" name="edit_password" id="editPassword" placeholder="اتركه فارغاً للإبقاء على الحالية، أو اكتب للتغيير" dir="ltr" autocomplete="off">
                            <button class="btn btn-outline-secondary" type="button" id="toggleOrRevealPasswordBtn" title="عرض كلمة المرور الحالية">
                                <i class="fas fa-eye"></i>
                            </button>
                        </div>
                        <small class="text-muted mt-1 d-block" style="font-size: 0.75rem;">انقر على العين لعرض كلمة المرور الحالية. اكتب كلمة مرور جديدة للتعديل مباشرة.</small>
                    </div>
                    
                    <div class="mb-3">
                        <label class="form-label fw-bold">رقم الهاتف</label>
                        <input type="text" class="form-control" name="edit_phone" id="editPhone" dir="ltr">
                    </div>
                    <div class="mb-3">
                        <label class="form-label fw-bold">المدرسة الحالية</label>
                        <input type="text" class="form-control" name="edit_school" id="editSchool">
                    </div>
                    <div class="mb-3">
                        <label class="form-label fw-bold">التخصص الدراسي</label>
                        <input type="text" class="form-control" name="edit_specialization" id="editSpec">
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">
                        <i class="fas fa-times me-1"></i>إلغاء
                    </button>
                    <button type="submit" class="btn btn-primary">
                        <i class="fas fa-save me-1"></i>حفظ التعديلات
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>

<script>
let currentEditTeacherId = null;

function copyLink() {
    const input = document.getElementById('externalLink');
    input.select();
    navigator.clipboard.writeText(input.value).then(() => {
        const msg = document.getElementById('copyMsg');
        msg.classList.remove('d-none');
        setTimeout(() => msg.classList.add('d-none'), 3000);
    });
}

function editTeacher(t) {
    currentEditTeacherId = t.id;
    document.getElementById('editTeacherId').value = t.id;
    document.getElementById('editTeacherCode').value = t.teacher_code || '-';
    document.getElementById('editName').value = t.name;
    document.getElementById('editEmail').value = t.email;
    document.getElementById('editPhone').value = t.phone || '';
    document.getElementById('editSchool').value = t.school_name || '';
    document.getElementById('editSpec').value = t.specialization || '';
    
    // إعادة تهيئة حقل كلمة المرور الموحد في مودال التعديل
    const passInput = document.getElementById('editPassword');
    passInput.value = '';
    passInput.type = 'password';
    passInput.dataset.passwordLoaded = 'false';
    if (passInput.passwordClearTimer) {
        window.clearTimeout(passInput.passwordClearTimer);
        passInput.passwordClearTimer = null;
    }
    
    const toggleBtn = document.getElementById('toggleOrRevealPasswordBtn');
    toggleBtn.title = "عرض كلمة المرور الحالية";
    const icon = toggleBtn.querySelector('i');
    if (icon) icon.className = 'fas fa-eye';
    
    new bootstrap.Modal(document.getElementById('editTeacherModal')).show();
}

// التحكم بحقل كلمة المرور الموحد داخل مودال التعديل
document.addEventListener("DOMContentLoaded", function() {
    const passwordInput = document.getElementById('editPassword');
    const toggleBtn = document.getElementById('toggleOrRevealPasswordBtn');
    
    if (passwordInput && toggleBtn) {
        // حدث عند قيام المستخدم بالكتابة (تعديل كلمة المرور)
        passwordInput.addEventListener('input', function() {
            this.dataset.passwordLoaded = 'false';
            if (this.passwordClearTimer) {
                window.clearTimeout(this.passwordClearTimer);
                this.passwordClearTimer = null;
            }
            
            // تغيير أيقونة التبديل العادية لمعاينة النص المكتوب
            const icon = toggleBtn.querySelector('i');
            if (icon) {
                if (this.type === 'text') {
                    icon.className = 'fas fa-eye-slash';
                } else {
                    icon.className = 'fas fa-eye';
                }
            }
            toggleBtn.title = "إظهار/إخفاء النص المكتوب";
        });
        
        // حدث عند الضغط على زر العين
        toggleBtn.addEventListener('click', function() {
            const icon = this.querySelector('i');
            
            // الحالة 1: الحقل يحتوي على نص قام المستخدم بكتابته للتو (وليس مجلوباً من قاعدة البيانات)
            if (passwordInput.value !== '' && passwordInput.dataset.passwordLoaded !== 'true') {
                if (passwordInput.type === 'password') {
                    passwordInput.type = 'text';
                    if (icon) icon.className = 'fas fa-eye-slash';
                } else {
                    passwordInput.type = 'password';
                    if (icon) icon.className = 'fas fa-eye';
                }
                return;
            }
            
            // الحالة 2: الحقل فارغ، نقوم بجلب كلمة المرور الحالية من قاعدة البيانات
            if (passwordInput.value === '') {
                this.title = "إخفاء كلمة المرور";
                revealUserPassword(currentEditTeacherId, 'editPassword', this, 'external_teacher');
                return;
            }
            
            // الحالة 3: كلمة المرور مجلوبة بالفعل من قاعدة البيانات (dataset.passwordLoaded = true)
            // سيتولى السكريبت المشترك revealUserPassword عملية إخفاء/إظهار الحقل وتصفيره بعد 15 ثانية تلقائياً
            revealUserPassword(currentEditTeacherId, 'editPassword', this, 'external_teacher');
        });
    }
});

function confirmStatusChange(teacherId, teacherName, newStatus) {
    document.getElementById('statusTeacherId').value = teacherId;
    document.getElementById('statusNewValue').value = newStatus;
    
    const modalContent = document.getElementById('statusModalContent');
    const title = document.getElementById('statusModalTitle');
    const icon = document.getElementById('statusModalIcon');
    const text = document.getElementById('statusModalText');
    const alertBox = document.getElementById('statusModalAlert');
    const submitBtn = document.getElementById('statusModalSubmitBtn');
    
    // Reset classes
    modalContent.classList.remove('admin-modal-create', 'admin-modal-warning');
    title.classList.remove('text-dark');
    icon.className = 'fas';
    alertBox.className = 'alert';
    submitBtn.className = 'btn';
    
    if (newStatus === 'active') {
        modalContent.classList.add('admin-modal-create');
        title.innerText = 'تفعيل المعلم الخارجي';
        icon.classList.add('fa-check-circle', 'text-success');
        text.innerHTML = `هل أنت متأكد من تفعيل المعلم <span class="fw-bold text-primary">${teacherName}</span>؟`;
        alertBox.classList.add('alert-success');
        document.getElementById('statusModalAlertText').innerText = 'سيتمكن المعلم من تسجيل الدخول للمنصة فوراً واستخدام كافة الخدمات المتاحة.';
        submitBtn.classList.add('btn-success');
        submitBtn.innerHTML = '<i class="fas fa-check me-1"></i>تفعيل الحساب';
    } else if (newStatus === 'blocked') {
        modalContent.classList.add('admin-modal-warning');
        title.innerText = 'حظر المعلم الخارجي';
        title.classList.add('text-dark');
        icon.classList.add('fa-ban', 'text-warning');
        text.innerHTML = `هل أنت متأكد من حظر المعلم <span class="fw-bold text-primary">${teacherName}</span>؟`;
        alertBox.classList.add('alert-warning');
        document.getElementById('statusModalAlertText').innerText = 'سيتم منع المعلم من تسجيل الدخول نهائياً أو استخدام أي من خدمات المنظومة.';
        submitBtn.classList.add('btn-warning');
        submitBtn.innerHTML = '<i class="fas fa-ban me-1"></i>حظر الحساب';
    }
    
    new bootstrap.Modal(document.getElementById('confirmStatusModal')).show();
}

function confirmDelete(teacherId, teacherName) {
    document.getElementById('deleteTeacherId').value = teacherId;
    document.getElementById('deleteTeacherName').innerText = teacherName;
    new bootstrap.Modal(document.getElementById('confirmDeleteModal')).show();
}

function confirmApproveAll() {
    new bootstrap.Modal(document.getElementById('confirmApproveAllModal')).show();
}
</script>

<?php include_once '../includes/admin_footer.php'; ?>
