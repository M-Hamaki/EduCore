<?php
/**
 * إعدادات النسخ الاحتياطي (SQL)
 */
require_once '../config/database.php';
require_once '../classes/utilities.php';
require_once '../includes/session_config.php';
require_once '../classes/DbSqlBackupManager.php';
require_once '../classes/ActivityLog.php';
require_once '../includes/csrf.php';

Utilities::validateSession('admin');
requireCsrfPost();

$database = new Database();
$db = $database->getConnection();

// --- استخراج رسائل الجلسة ---
$success_message = $_SESSION['settings_success'] ?? null;
$error_message = $_SESSION['settings_error'] ?? null;
unset($_SESSION['settings_success'], $_SESSION['settings_error']);

// ==========================================
// معالجة الإجراءات (POST)
// ==========================================
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // ---- حفظ إعدادات النسخ الاحتياطي SQL ----
    if (isset($_POST['update_backup_sql_settings'])) {
        try {
            $defaultDumpDir = dirname(__DIR__) . DIRECTORY_SEPARATOR . 'storage' . DIRECTORY_SEPARATOR . 'backups' . DIRECTORY_SEPARATOR . 'sql';
            if (!is_dir($defaultDumpDir)) {
                @mkdir($defaultDumpDir, 0775, true);
            }

            $db_backup_sql_enabled = isset($_POST['db_backup_sql_enabled']) ? '1' : '0';
            $db_backup_sql_interval_minutes = isset($_POST['db_backup_sql_interval_minutes']) ? (int)$_POST['db_backup_sql_interval_minutes'] : 1440;
            if ($db_backup_sql_interval_minutes < 1) {
                $db_backup_sql_interval_minutes = 1440;
            }

            $db_backup_sql_dump_path = trim((string)($_POST['db_backup_sql_dump_path'] ?? ''));
            if ($db_backup_sql_dump_path === '') {
                $db_backup_sql_dump_path = $defaultDumpDir;
            }

            $db_backup_sql_retention_days = isset($_POST['db_backup_sql_retention_days']) ? (int)$_POST['db_backup_sql_retention_days'] : 14;
            if ($db_backup_sql_retention_days < 0) {
                $db_backup_sql_retention_days = 14;
            }

            $dynamic_settings = [
                'db_backup_sql_enabled' => [$db_backup_sql_enabled, 'تمكين تصدير SQL تلقائي لقاعدة البيانات (1=مفعل, 0=معطل)'],
                'db_backup_sql_interval_minutes' => [$db_backup_sql_interval_minutes, 'الفترة الزمنية لتصدير SQL التلقائي بالـدقائق'],
                'db_backup_sql_dump_path' => [$db_backup_sql_dump_path, 'مسار مجلد تخزين ملفات SQL للنسخ الاحتياطي'],
                'db_backup_sql_retention_days' => [$db_backup_sql_retention_days, 'عدد الأيام للاحتفاظ بملفات SQL (0 = احتفظ بكل شيء)']
            ];

            foreach ($dynamic_settings as $key => $info) {
                $val = $info[0];
                $desc = $info[1];

                $stmt = $db->prepare("SELECT id FROM settings WHERE setting_key = ?");
                $stmt->execute([$key]);

                if ($stmt->rowCount() > 0) {
                    $stmt = $db->prepare("UPDATE settings SET setting_value = ? WHERE setting_key = ?");
                    $stmt->execute([$val, $key]);
                } else {
                    $stmt = $db->prepare("INSERT INTO settings (setting_key, setting_value, description) VALUES (?, ?, ?)");
                    $stmt->execute([$key, $val, $desc]);
                }
            }

            if ($db_backup_sql_enabled === '1') {
                DbSqlBackupManager::createOrUpdateScheduledTask($db);
            } else {
                DbSqlBackupManager::disableScheduledTask($db);
            }

            $_SESSION['settings_success'] = "تم حفظ إعدادات النسخ الاحتياطي SQL بنجاح.";
            ActivityLog::log('settings', 'settings', null, 'تحديث إعدادات النسخ الاحتياطي SQL', [
                'db_backup_sql_enabled' => $db_backup_sql_enabled,
                'db_backup_sql_interval_minutes' => $db_backup_sql_interval_minutes,
                'db_backup_sql_dump_path' => $db_backup_sql_dump_path,
                'db_backup_sql_retention_days' => $db_backup_sql_retention_days
            ]);
        } catch (Exception $e) {
            $_SESSION['settings_error'] = "خطأ في حفظ إعدادات النسخ الاحتياطي SQL: " . $e->getMessage();
        }

        header("Location: sql_backups.php");
        exit();
    }
}

// ==========================================
// جلب البيانات
// ==========================================
$settings = [];
$stmt = $db->query("SELECT setting_key, setting_value FROM settings");
while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
    $settings[$row['setting_key']] = $row['setting_value'];
}

$defaultDumpDirView = dirname(__DIR__) . DIRECTORY_SEPARATOR . 'storage' . DIRECTORY_SEPARATOR . 'backups' . DIRECTORY_SEPARATOR . 'sql';
$db_backup_sql_enabled = isset($settings['db_backup_sql_enabled']) ? $settings['db_backup_sql_enabled'] : '0';
$db_backup_sql_interval_minutes = isset($settings['db_backup_sql_interval_minutes']) ? $settings['db_backup_sql_interval_minutes'] : '1440';
$db_backup_sql_dump_path = isset($settings['db_backup_sql_dump_path']) ? $settings['db_backup_sql_dump_path'] : $defaultDumpDirView;
$db_backup_sql_retention_days = isset($settings['db_backup_sql_retention_days']) ? $settings['db_backup_sql_retention_days'] : '14';
$db_backup_sql_last_status = $settings['db_backup_sql_last_status'] ?? '';
$db_backup_sql_last_scheduler_status = $settings['db_backup_sql_last_scheduler_status'] ?? '';
$db_backup_sql_last_shown = !empty($db_backup_sql_last_status)
    ? $db_backup_sql_last_status
    : (!empty($db_backup_sql_last_scheduler_status) ? $db_backup_sql_last_scheduler_status : 'لا توجد حالة سابقة');

$page_title = 'النسخ الاحتياطي (SQL)';
$custom_page_title = true;
require_once '../includes/admin_header.php';
?>

<!-- Page Header -->
<div class="d-flex justify-content-between flex-wrap flex-md-nowrap align-items-center pt-3 pb-2 mb-4 border-bottom animate-up">
    <div>
        <h1 class="h2 fw-bold text-dark"><i class="fas fa-database me-3 text-primary"></i>النسخ الاحتياطي (SQL)</h1>
        <p class="text-muted m-0">إعدادات النسخ الاحتياطي التلقائي وجدولة تصدير قاعدة البيانات</p>
    </div>
</div>

<!-- رسائل النجاح والخطأ -->
<?php if ($success_message): ?>
    <div class="alert alert-success alert-dismissible fade show" role="alert">
        <i class="fas fa-check-circle me-2"></i><?php echo htmlspecialchars($success_message); ?>
        <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
    </div>
<?php endif; ?>
<?php if ($error_message): ?>
    <div class="alert alert-danger alert-dismissible fade show" role="alert">
        <i class="fas fa-exclamation-triangle me-2"></i><?php echo htmlspecialchars($error_message); ?>
        <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
    </div>
<?php endif; ?>

<div class="row g-4 animate-up delay-1">
    <div class="col-lg-12">
        <div class="card shadow mb-4">
            <div class="card-header bg-primary text-white">
                <h5 class="mb-0 fw-bold text-white"><i class="fas fa-database me-2"></i>إعدادات النسخ الاحتياطي (SQL)</h5>
            </div>
            <div class="card-body">
                <form method="POST">
                    <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($_SESSION['csrf_token']); ?>">

                    <div class="row g-3">
                        <div class="col-12">
                            <div class="d-flex align-items-center justify-content-between p-3 rounded" style="background:#f8f9fa;">
                                <div>
                                    <h5 class="mb-1">
                                        <i class="fas fa-shield-alt me-2"></i>تشغيل تصدير SQL تلقائي
                                    </h5>
                                    <p class="mb-0 text-muted">يتم إنشاء ملفات `.sql` في المجلد المحدد.</p>
                                </div>
                                <div class="form-check form-switch ms-4">
                                    <input class="form-check-input" type="checkbox" role="switch"
                                           id="db_backup_sql_enabled" name="db_backup_sql_enabled" value="1"
                                           <?php echo ($db_backup_sql_enabled === '1') ? 'checked' : ''; ?>>
                                    <label class="form-check-label me-2" for="db_backup_sql_enabled"></label>
                                </div>
                            </div>
                        </div>

                        <div class="col-md-6">
                            <label class="form-label fw-bold">
                                <i class="fas fa-clock me-2 text-primary"></i>الفترة (بالدقائق)
                            </label>
                            <input type="number" class="form-control"
                                   id="db_backup_sql_interval_minutes" name="db_backup_sql_interval_minutes"
                                   value="<?php echo htmlspecialchars((string)$db_backup_sql_interval_minutes); ?>"
                                   min="1" step="1"
                                   <?php echo ($db_backup_sql_enabled !== '1') ? 'disabled' : ''; ?>>
                            <small class="text-muted d-block mt-2">
                                مثال: 60 دقيقة أو 1440 يوم كامل
                            </small>
                        </div>

                        <div class="col-md-6">
                            <label class="form-label fw-bold">
                                <i class="fas fa-folder-open me-2 text-success"></i>مسار التخزين
                            </label>
                            <input type="text" class="form-control"
                                   id="db_backup_sql_dump_path" name="db_backup_sql_dump_path"
                                   value="<?php echo htmlspecialchars((string)$db_backup_sql_dump_path); ?>"
                                   placeholder="مثال: D:\Backups\EduCore\"
                                   <?php echo ($db_backup_sql_enabled !== '1') ? 'disabled' : ''; ?>>
                            <small class="text-muted d-block mt-2">
                                المجلد يجب أن يكون قابل للكتابة بواسطة مهمة ويندوز (SYSTEM).
                            </small>
                        </div>

                        <div class="col-md-6">
                            <label class="form-label fw-bold">
                                <i class="fas fa-trash-restore me-2 text-warning"></i>الاحتفاظ (بالأيام)
                            </label>
                            <input type="number" class="form-control"
                                   id="db_backup_sql_retention_days" name="db_backup_sql_retention_days"
                                   value="<?php echo htmlspecialchars((string)$db_backup_sql_retention_days); ?>"
                                   min="0" step="1"
                                   <?php echo ($db_backup_sql_enabled !== '1') ? 'disabled' : ''; ?>>
                            <small class="text-muted d-block mt-2">0 = احتفظ بكل الملفات بدون حذف تلقائي</small>
                        </div>

                        <div class="col-md-6">
                            <label class="form-label fw-bold">
                                <i class="fas fa-history me-2 text-info"></i>آخر حالة تنفيذ
                            </label>
                            <div class="form-control" style="background:#f8f9fa; border:none;">
                                <?php echo htmlspecialchars((string)$db_backup_sql_last_shown); ?>
                            </div>
                        </div>
                    </div>

                    <div class="alert alert-warning mt-3 mb-0">
                        <i class="fas fa-exclamation-triangle me-2"></i>
                        يتم تنفيذ التصدير عبر أمر `mysqldump` بواسطة مهمة ويندوز تم إنشاؤها تلقائيًا عند تفعيل هذا الخيار.
                    </div>

                    <div class="text-end mt-4">
                        <button type="submit" name="update_backup_sql_settings" class="btn btn-primary">
                            <i class="fas fa-save me-1"></i>حفظ إعدادات النسخ الاحتياطي
                        </button>
                    </div>
                </form>
            </div>
        </div>
    </div>
</div>

<script>
document.addEventListener('DOMContentLoaded', function () {
    const backupSwitch = document.getElementById('db_backup_sql_enabled');
    const intervalInput = document.getElementById('db_backup_sql_interval_minutes');
    const pathInput = document.getElementById('db_backup_sql_dump_path');
    const retentionInput = document.getElementById('db_backup_sql_retention_days');

    if (backupSwitch) {
        backupSwitch.addEventListener('change', function () {
            const enabled = this.checked;
            intervalInput.disabled = !enabled;
            pathInput.disabled = !enabled;
            retentionInput.disabled = !enabled;
        });
    }
});
</script>

<?php require_once '../includes/admin_footer.php'; ?>
