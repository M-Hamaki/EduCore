<?php
// Set page title
$page_title = "إدارة النسخ الاحتياطية";
$custom_page_title = true;

// Include database and necessary classes
require_once '../config/database.php';
require_once '../classes/user.php';
require_once '../classes/utilities.php';
require_once '../classes/EvaluationBackupService.php';
require_once '../includes/session_config.php';
Utilities::validateSession('admin');

// Initialize database connection
$database = new Database();
$db = $database->getConnection();
$backupService = new EvaluationBackupService($db);

$success_message = $_SESSION['success_message'] ?? null;
$error_message = $_SESSION['error_message'] ?? null;
unset($_SESSION['success_message'], $_SESSION['error_message']);

// Handle restore action
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    // CSRF validation
    $csrfToken = $_POST['csrf_token'] ?? '';
    if (!hash_equals($_SESSION['csrf_token'] ?? '', $csrfToken)) {
        $_SESSION['error_message'] = 'خطأ في التحقق الأمني. يرجى إعادة المحاولة.';
        header('Location: manage_backups.php');
        exit();
    }

    // Restore backup
    if ($_POST['action'] === 'restore' && isset($_POST['backup_table']) && isset($_POST['admin_password'])) {
        try {
            // Verify admin password
            $user = new User($db);
            $user->id = $_SESSION['user_id'];
            
            $entered_password = $_POST['admin_password'];
            if (!$user->verifyPassword($entered_password)) {
                throw new Exception("كلمة المرور غير صحيحة");
            }
            
            $backup_table = (string)$_POST['backup_table'];
            $restoreResult = $backupService->restore(
                $backup_table,
                (int)$_SESSION['user_id'],
                (string)($_SESSION['name'] ?? '')
            );
            $pre_restore_backup = $restoreResult['pre_restore_key'];
            $count_before = $restoreResult['before'];
            $count_after = $restoreResult['after'];
            
            // Log the action
            error_log("BACKUP RESTORED: Admin '{$_SESSION['name']}' restored backup '$backup_table'. Before: $count_before, After: $count_after. Pre-restore backup: $pre_restore_backup");
            
            $_SESSION['success_message'] = "تم استرجاع النسخة الاحتياطية بنجاح!<br>" .
                "<strong>التفاصيل:</strong><br>" .
                "• النسخة المسترجعة: $backup_table<br>" .
                "• عدد التقييمات قبل الاسترجاع: $count_before<br>" .
                "• عدد التقييمات بعد الاسترجاع: $count_after<br>" .
                "• تم إنشاء نسخة احتياطية قبل الاسترجاع: $pre_restore_backup";

        } catch (Exception $e) {
            $_SESSION['error_message'] = 'خطأ في استرجاع النسخة: ' . htmlspecialchars($e->getMessage(), ENT_QUOTES, 'UTF-8');
        }
        header('Location: manage_backups.php');
        exit();
    }
    
    // Delete backup
    elseif ($_POST['action'] === 'delete' && isset($_POST['backup_table']) && isset($_POST['admin_password'])) {
        try {
            // Verify admin password
            $user = new User($db);
            $user->id = $_SESSION['user_id'];
            
            $entered_password = $_POST['admin_password'];
            if (!$user->verifyPassword($entered_password)) {
                throw new Exception("كلمة المرور غير صحيحة");
            }
            
            $backup_table = (string)$_POST['backup_table'];
            $backupService->delete($backup_table);
            
            error_log("BACKUP DELETED: Admin '{$_SESSION['name']}' deleted backup '$backup_table'");
            
            $_SESSION['success_message'] = "تم حذف النسخة الاحتياطية: $backup_table";
            
        } catch (Exception $e) {
            $_SESSION['error_message'] = 'خطأ في حذف النسخة: ' . htmlspecialchars($e->getMessage(), ENT_QUOTES, 'UTF-8');
        }
        header('Location: manage_backups.php');
        exit();
    }
}

$backups = $backupService->all();

// Get current evaluations count
$current_count = $db->query("SELECT COUNT(*) FROM evaluations")->fetchColumn();
$current_students = $db->query("SELECT COUNT(DISTINCT student_id) FROM evaluations")->fetchColumn();

require_once '../includes/admin_header.php';
?>

<div class="d-flex justify-content-between flex-wrap flex-md-nowrap align-items-center pt-3 pb-2 mb-3 border-bottom">
    <h1 class="h2"><i class="fas fa-database me-2"></i>إدارة النسخ الاحتياطية</h1>
    <div class="btn-toolbar mb-2 mb-md-0">
        <a href="index.php" class="btn btn-sm btn-outline-primary"><i class="fas fa-arrow-right me-1"></i>لوحة التحكم</a>
    </div>
</div>

<?php if ($success_message): ?>
<div class="alert alert-success alert-dismissible fade show" role="alert">
    <i class="fas fa-check-circle me-2"></i><?php echo $success_message; ?>
    <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
</div>
<?php endif; ?>
<?php if ($error_message): ?>
<div class="alert alert-danger alert-dismissible fade show" role="alert">
    <i class="fas fa-exclamation-triangle me-2"></i><?php echo htmlspecialchars($error_message, ENT_QUOTES, 'UTF-8'); ?>
    <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
</div>
<?php endif; ?>

<div class="row row-cols-2 row-cols-md-3 g-3 mb-4">
    <div class="col"><div class="stat-card" style="--card-gradient: linear-gradient(135deg, #3b82f6, #2563eb);"><div class="stat-card-icon"><i class="fas fa-file-alt"></i></div><div class="stat-card-info"><div class="stat-card-number"><?php echo number_format($current_count); ?></div><div class="stat-card-label">التقييمات الحالية</div></div></div></div>
    <div class="col"><div class="stat-card" style="--card-gradient: linear-gradient(135deg, #10b981, #059669);"><div class="stat-card-icon"><i class="fas fa-user-graduate"></i></div><div class="stat-card-info"><div class="stat-card-number"><?php echo number_format($current_students); ?></div><div class="stat-card-label">الطلاب الممثلون</div></div></div></div>
    <div class="col"><div class="stat-card" style="--card-gradient: linear-gradient(135deg, #f59e0b, #d97706);"><div class="stat-card-icon"><i class="fas fa-archive"></i></div><div class="stat-card-info"><div class="stat-card-number"><?php echo count($backups); ?></div><div class="stat-card-label">نسخ احتياطية متاحة</div></div></div></div>
</div>

<style>
</style>

<div class="card shadow">
    <div class="card-header bg-primary text-white">
        <h5 class="mb-0"><i class="fas fa-list me-2"></i>قائمة النسخ الاحتياطية <span class="badge bg-light text-dark ms-2"><?php echo count($backups); ?></span></h5>
    </div>
                <div class="card-body">
                    <?php if (empty($backups)): ?>
                        <div class="alert alert-secondary text-center">
                            <i class="fas fa-inbox fa-3x mb-3"></i>
                            <h5>لا توجد نسخ احتياطية</h5>
                            <p class="mb-0">سيتم إنشاء نسخة احتياطية تلقائياً عند استخدام خيار "تصفير النقاط"</p>
                        </div>
                    <?php else: ?>
                        <div class="table-responsive">
                            <table class="table table-hover table-striped">
                                <thead class="table-dark">
                                    <tr>
                                        <th>#</th>
                                        <th>تاريخ النسخة</th>
                                        <th>عدد التقييمات</th>
                                        <th>عدد الطلاب</th>
                                        <th>النوع</th>
                                        <th>الإجراءات</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($backups as $index => $backup): ?>
                                        <tr>
                                            <td><?php echo $index + 1; ?></td>
                                            <td>
                                                <i class="fas fa-calendar-alt text-primary me-1"></i>
                                                <?php echo $backup['date']; ?>
                                            </td>
                                            <td>
                                                <span class="badge bg-primary"><?php echo number_format($backup['total_evaluations']); ?></span>
                                            </td>
                                            <td>
                                                <span class="badge bg-success"><?php echo number_format($backup['total_students']); ?></span>
                                            </td>
                                            <td>
                                                <?php if (isset($backup['is_pre_restore'])): ?>
                                                    <span class="badge bg-warning text-dark">
                                                        <i class="fas fa-undo me-1"></i>قبل استرجاع
                                                    </span>
                                                <?php else: ?>
                                                    <span class="badge bg-info">
                                                        <i class="fas fa-eraser me-1"></i>تصفير نقاط
                                                    </span>
                                                <?php endif; ?>
                                            </td>
                                            <td>
                                                <button type="button" class="btn btn-sm btn-outline-success me-1" 
                                                        onclick="showRestoreModal('<?php echo $backup['table_name']; ?>', '<?php echo $backup['date']; ?>', <?php echo $backup['total_evaluations']; ?>)">
                                                    <i class="fas fa-undo me-1"></i>استرجاع
                                                </button>
                                                <button type="button" class="btn btn-sm btn-outline-danger" 
                                                        onclick="showDeleteModal('<?php echo $backup['table_name']; ?>', '<?php echo $backup['date']; ?>')">
                                                    <i class="fas fa-trash me-1"></i>حذف
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

<!-- Restore Modal -->
<div class="modal fade" id="restoreModal" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content admin-modal admin-modal-premium admin-modal-view">
            <div class="modal-header">
                <h5 class="modal-title"><i class="fas fa-undo me-2"></i>استرجاع نسخة احتياطية</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <form method="POST" action="">
                <div class="modal-body">
                    <input type="hidden" name="action" value="restore">
                    <input type="hidden" name="backup_table" id="restore_backup_table">
                    <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($_SESSION['csrf_token'] ?? '', ENT_QUOTES, 'UTF-8'); ?>">
                    
                    <div class="alert alert-warning">
                        <i class="fas fa-exclamation-triangle me-2"></i>
                        <strong>تحذير:</strong> سيتم استبدال جميع التقييمات الحالية بالنسخة الاحتياطية المحددة.
                        <br>سيتم إنشاء نسخة احتياطية من الوضع الحالي قبل الاسترجاع.
                    </div>
                    
                    <div class="mb-3">
                        <label class="form-label"><strong>النسخة المحددة:</strong></label>
                        <p id="restore_info" class="text-primary"></p>
                    </div>
                    
                    <div class="mb-3">
                        <label class="form-label"><strong>كلمة مرور المدير للتأكيد:</strong></label>
                        <input type="password" class="form-control" name="admin_password" required>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">إلغاء</button>
                    <button type="submit" class="btn btn-success">
                        <i class="fas fa-undo me-1"></i>تأكيد الاسترجاع
                    </button>
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
                <h5 class="modal-title"><i class="fas fa-trash me-2"></i>حذف نسخة احتياطية</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <form method="POST" action="">
                <div class="modal-body">
                    <input type="hidden" name="action" value="delete">
                    <input type="hidden" name="backup_table" id="delete_backup_table">
                    <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($_SESSION['csrf_token'] ?? '', ENT_QUOTES, 'UTF-8'); ?>">
                    
                    <div class="alert alert-danger">
                        <i class="fas fa-exclamation-triangle me-2"></i>
                        <strong>تحذير:</strong> سيتم حذف هذه النسخة الاحتياطية نهائياً ولا يمكن استرجاعها!
                    </div>
                    
                    <div class="mb-3">
                        <label class="form-label"><strong>النسخة المحددة:</strong></label>
                        <p id="delete_info" class="text-danger"></p>
                    </div>
                    
                    <div class="mb-3">
                        <label class="form-label"><strong>كلمة مرور المدير للتأكيد:</strong></label>
                        <input type="password" class="form-control" name="admin_password" required>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal"><i class="fas fa-times me-1"></i>إلغاء</button>
                    <button type="submit" class="btn btn-danger">
                        <i class="fas fa-trash me-1"></i>تأكيد الحذف
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>

<script>
function showRestoreModal(tableName, date, count) {
    document.getElementById('restore_backup_table').value = tableName;
    document.getElementById('restore_info').innerHTML = 
        '<i class="fas fa-calendar me-1"></i>' + date + '<br>' +
        '<i class="fas fa-file-alt me-1"></i>' + count + ' تقييم';
    new bootstrap.Modal(document.getElementById('restoreModal')).show();
}

function showDeleteModal(tableName, date) {
    document.getElementById('delete_backup_table').value = tableName;
    document.getElementById('delete_info').innerHTML = 
        '<i class="fas fa-calendar me-1"></i>' + date;
    new bootstrap.Modal(document.getElementById('deleteModal')).show();
}
</script>

<?php require_once '../includes/admin_footer.php'; ?>
