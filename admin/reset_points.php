<?php
// Set page title
$page_title = "تصفير النقاط";

// Include database and necessary classes
require_once '../config/database.php';
require_once '../classes/user.php';
require_once '../classes/evaluation.php';
require_once '../classes/utilities.php';
require_once '../classes/ActivityLog.php';
require_once '../classes/EvaluationBackupService.php';
require_once '../classes/SystemAdministratorRoleService.php';
require_once '../includes/session_config.php';
require_once '../includes/csrf.php';

Utilities::validateSession('super_admin');
requireCsrfPost();

// Initialize database connection
$database = new Database();
$db = $database->getConnection();
$systemAdministratorRoleService = new SystemAdministratorRoleService($db);
try {
    $systemAdministratorRoleService->assertActorCanManage(
        (int)($_SESSION['user_id'] ?? 0),
        (string)($_SESSION['active_role'] ?? $_SESSION['role'] ?? '')
    );
} catch (RuntimeException $e) {
    error_log('reset_points super-admin authorization denied: ' . $e->getMessage());
    $_SESSION['error_message'] = 'هذه العملية متاحة لمدير النظام الأعلى فقط.';
    header('Location: index.php');
    exit;
}

// PRG Pattern: retrieve and clear session messages
$success_message = $_SESSION['success_message'] ?? null;
$error_message = $_SESSION['error_message'] ?? null;
unset($_SESSION['success_message'], $_SESSION['error_message']);

// Handle reset points action with enhanced security
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['confirm_reset'])) {    // Triple verification
    if ($_POST['confirm_reset'] === 'CONFIRM_RESET_ALL_POINTS' && 
        isset($_POST['admin_password']) && 
        !empty($_POST['admin_password']) &&
        isset($_POST['final_confirmation'])) {        try {
            // Verify admin password
            $user = new User($db);
            $user->id = $_SESSION['user_id'];
            
            // Check if entered password is not empty
            if (empty($_POST['admin_password'])) {
                throw new Exception("يرجى إدخال كلمة مرور المدير");
            }

            $entered_password = $_POST['admin_password'];
            if (!$user->verifyPassword($entered_password)) {
                throw new Exception("كلمة المرور غير صحيحة");
            }
            
            $backupService = new EvaluationBackupService($db);
            $stats = $backupService->resetAll(
                (int)$_SESSION['user_id'],
                (string)($_SESSION['name'] ?? '')
            );
            $backup_table = $stats['backup_key'];
            
            // Only proceed if there are evaluations to delete
            if ($stats['total_evaluations'] > 0) {
                $delete_result = true;
                
                if ($delete_result) {
                    // Comprehensive logging
                    $log_message = "CRITICAL ADMIN ACTION: All points reset by admin '" . $_SESSION['name'] . "' (ID: " . $_SESSION['user_id'] . "). " .
                        "Statistics: {$stats['total_evaluations']} evaluations deleted, " .
                        "{$stats['affected_students']} students affected, " .
                        "{$stats['teachers_involved']} teachers involved, " .
                        "{$stats['classes_involved']} classes involved. " .
                        "Date range: {$stats['oldest_evaluation']} to {$stats['newest_evaluation']}. " .
                        "Backup table: $backup_table. " .
                        "Timestamp: " . date('Y-m-d H:i:s') . ". " .
                        "IP: " . ($_SERVER['REMOTE_ADDR'] ?? 'Unknown');
                    
                    error_log($log_message);
                    
                    ActivityLog::log('reset', 'points', null, 'تصفير جميع النقاط (التقييمات)', [
                        'total_evaluations' => $stats['total_evaluations'],
                        'affected_students' => $stats['affected_students'],
                        'backup_table' => $backup_table
                    ]);
                    
                    // Set detailed success message
                    $_SESSION['success_message'] = "تم تصفير جميع النقاط بنجاح!<br>" .
                        "<strong>الإحصائيات:</strong><br>" .
                        "• تم حذف {$stats['total_evaluations']} تقييم<br>" .
                        "• تأثر {$stats['affected_students']} طالب<br>" .
                        "• شارك {$stats['teachers_involved']} معلم<br>" .
                        "• من {$stats['classes_involved']} فصل<br>" .
                        "• تم إنشاء نسخة احتياطية: $backup_table";
                    header("Location: " . $_SERVER['PHP_SELF']);
                    exit();
                    
                } else {
                    throw new Exception("فشل في تنفيذ عملية الحذف");
                }
            } else {
                $error_message = "لا توجد تقييمات لحذفها. النظام فارغ بالفعل.";
                // no redirect needed — just informational
            }
            
        } catch (Exception $e) {
            // Log error with details
            error_log("ERROR: Failed to reset points by admin '" . $_SESSION['name'] . "' - " . $e->getMessage() . " - IP: " . ($_SERVER['REMOTE_ADDR'] ?? 'Unknown'));
            
            // Set error message
            $_SESSION['error_message'] = "خطأ في تصفير النقاط: " . $e->getMessage();
            header("Location: " . $_SERVER['PHP_SELF']);
            exit();
        }
    } else {
        // Invalid confirmation or missing data
        $_SESSION['error_message'] = "فشل في التحقق من البيانات. لم يتم تصفير النقاط.";
        header("Location: " . $_SERVER['PHP_SELF']);
        exit();
    }
}

// Get current statistics for display
$current_stats_query = "SELECT 
    COUNT(*) as total_evaluations,
    COUNT(DISTINCT student_id) as total_students_with_points,
    COUNT(DISTINCT teacher_id) as total_teachers_with_evaluations,
    COUNT(DISTINCT class_id) as total_classes_with_evaluations,
    MIN(date_created) as oldest_evaluation,
    MAX(date_created) as newest_evaluation
    FROM evaluations";
$current_stats_stmt = $db->prepare($current_stats_query);
$current_stats_stmt->execute();
$current_stats = $current_stats_stmt->fetch(PDO::FETCH_ASSOC);

// Include header
include_once '../includes/admin_header.php';
?>

<div class="container-fluid">
    <!-- Alerts -->
    <?php if (!empty($success_message)): ?>
        <div class="alert alert-success alert-dismissible fade show" role="alert">
            <?php echo $success_message; ?>
            <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
        </div>
    <?php endif; ?>
    <?php if (!empty($error_message)): ?>
        <div class="alert alert-danger alert-dismissible fade show" role="alert">
            <?php echo $error_message; ?>
            <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
        </div>
    <?php endif; ?>

    <!-- Header -->
    <div class="row mb-4">
        <div class="col-12">
            <div class="card border-danger">
                <div class="card-header bg-danger text-white">
                    <h4 class="mb-0">
                        <i class="fas fa-exclamation-triangle me-2"></i>
                        تصفير جميع النقاط - منطقة خطيرة
                    </h4>
                </div>
                <div class="card-body">
                    <div class="alert alert-danger">
                        <h5><i class="fas fa-warning me-2"></i>تحذير شديد الأهمية!</h5>
                        <p class="mb-0">هذا الإجراء سيؤدي إلى <strong>حذف جميع التقييمات والنقاط بشكل نهائي</strong>. 
                        يُستخدم عادة في بداية فصل دراسي جديد أو لإعادة تهيئة النظام بالكامل.</p>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- Current Statistics -->
    <div class="row mb-4">
        <div class="col-12">
            <div class="card">
                <div class="card-header bg-info text-white">
                    <h5 class="mb-0"><i class="fas fa-chart-bar me-2"></i>الإحصائيات الحالية</h5>
                </div>
                <div class="card-body">
                    <?php if ($current_stats['total_evaluations'] > 0): ?>
                        <div class="row text-center">
                            <div class="col-md-3">
                                <div class="border rounded p-3 bg-light">
                                    <h3 class="text-primary"><?php echo $current_stats['total_evaluations']; ?></h3>
                                    <p class="mb-0 small">إجمالي التقييمات</p>
                                </div>
                            </div>
                            <div class="col-md-3">
                                <div class="border rounded p-3 bg-light">
                                    <h3 class="text-success"><?php echo $current_stats['total_students_with_points']; ?></h3>
                                    <p class="mb-0 small">طلاب لديهم نقاط</p>
                                </div>
                            </div>
                            <div class="col-md-3">
                                <div class="border rounded p-3 bg-light">
                                    <h3 class="text-warning"><?php echo $current_stats['total_teachers_with_evaluations']; ?></h3>
                                    <p class="mb-0 small">معلمين أضافوا تقييمات</p>
                                </div>
                            </div>
                            <div class="col-md-3">
                                <div class="border rounded p-3 bg-light">
                                    <h3 class="text-info"><?php echo $current_stats['total_classes_with_evaluations']; ?></h3>
                                    <p class="mb-0 small">فصول بها تقييمات</p>
                                </div>
                            </div>
                        </div>
                        <hr>
                        <div class="row">
                            <div class="col-md-6">
                                <p><strong>أقدم تقييم:</strong> <?php echo date('Y-m-d H:i', strtotime($current_stats['oldest_evaluation'])); ?></p>
                            </div>
                            <div class="col-md-6">
                                <p><strong>أحدث تقييم:</strong> <?php echo date('Y-m-d H:i', strtotime($current_stats['newest_evaluation'])); ?></p>
                            </div>
                        </div>
                    <?php else: ?>
                        <div class="alert alert-info text-center">
                            <h5><i class="fas fa-info-circle me-2"></i>لا توجد تقييمات</h5>
                            <p class="mb-0">النظام فارغ حالياً. لا توجد نقاط لتصفيرها.</p>
                        </div>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>

    <!-- Reset Form -->
    <?php if ($current_stats['total_evaluations'] > 0): ?>
    <div class="row justify-content-center">
        <div class="col-md-8">
            <div class="card border-danger">
                <div class="card-header bg-danger text-white">
                    <h5 class="mb-0"><i class="fas fa-eraser me-2"></i>نموذج تصفير النقاط</h5>
                </div>
                <div class="card-body">
                    <form method="POST" action="" id="resetForm" data-confirm-message="تأكيد أخير: سيتم حذف التقييمات والنقاط المحددة، ولا يمكن التراجع عن هذا الإجراء." data-confirm-operation="delete">
                        <?php echo csrfField(); ?>
                        <!-- Step 1: Confirmation Text -->
                        <div class="mb-4">
                            <label class="form-label">
                                <strong>الخطوة 1: اكتب النص التالي بالضبط:</strong>
                            </label>
                            <div class="bg-dark text-white p-3 rounded mb-2 text-center">
                                <code style="font-size: 1.1rem;">CONFIRM_RESET_ALL_POINTS</code>
                            </div>
                            <input type="text" class="form-control" name="confirm_reset" 
                                   placeholder="اكتب النص هنا..." required autocomplete="off">
                        </div>

                        <!-- Step 2: Admin Password -->
                        <div class="mb-4">
                            <label class="form-label">
                                <strong>الخطوة 2: أدخل كلمة مرور المدير للتأكيد:</strong>
                            </label>
                            <input type="password" class="form-control" name="admin_password" 
                                   placeholder="كلمة مرور المدير..." required>
                            <small class="form-text text-muted">كلمة المرور الخاصة بحسابك كمدير للنظام.</small>
                        </div>

                        <!-- Step 3: Final Confirmation -->
                        <div class="mb-4">
                            <div class="form-check">
                                <input class="form-check-input" type="checkbox" name="final_confirmation" 
                                       value="yes" id="final_confirmation" required>
                                <label class="form-check-label" for="final_confirmation">
                                    <strong>أفهم أن هذا الإجراء سيحذف جميع التقييمات والنقاط بشكل نهائي</strong>
                                </label>
                            </div>
                        </div>

                        <!-- Backup Note -->
                        <div class="alert alert-info">
                            <h6><i class="fas fa-database me-2"></i>نسخة احتياطية تلقائية</h6>
                            <p class="mb-0">سيتم إنشاء نسخة احتياطية تلقائياً من جميع البيانات قبل الحذف.</p>
                        </div>

                        <!-- Action Buttons -->
                        <div class="row">
                            <div class="col-md-6">
                                <div class="d-grid">
                                    <a href="index.php" class="btn btn-outline-primary btn-lg">
                                        <i class="fas fa-arrow-left me-1"></i>العودة للوحة التحكم
                                    </a>
                                </div>
                            </div>
                            <div class="col-md-6">
                                <div class="d-grid">
                                    <button type="submit" class="btn btn-danger btn-lg" id="submitBtn" disabled>
                                        <i class="fas fa-eraser me-1"></i>تصفير جميع النقاط
                                    </button>
                                </div>
                            </div>
                        </div>
                    </form>
                </div>
            </div>
        </div>
    </div>
    <?php else: ?>
    <div class="row justify-content-center">
        <div class="col-md-6">
            <div class="text-center">
                <a href="index.php" class="btn btn-outline-primary btn-lg">
                    <i class="fas fa-arrow-left me-1"></i>العودة للوحة التحكم
                </a>
            </div>
        </div>
    </div>
    <?php endif; ?>
</div>

<script>
document.addEventListener('DOMContentLoaded', function() {
    const form = document.getElementById('resetForm');
    const confirmText = document.querySelector('input[name="confirm_reset"]');
    const adminPassword = document.querySelector('input[name="admin_password"]');
    const finalCheck = document.querySelector('input[name="final_confirmation"]');
    const submitBtn = document.getElementById('submitBtn');    function checkFormValidity() {
        const isTextCorrect = confirmText.value === 'CONFIRM_RESET_ALL_POINTS';
        const hasPassword = adminPassword.value.trim().length > 0;
        const isChecked = finalCheck.checked;
        
        if (isTextCorrect && hasPassword && isChecked) {
            submitBtn.disabled = false;
            submitBtn.classList.remove('btn-secondary');
            submitBtn.classList.add('btn-danger');
        } else {
            submitBtn.disabled = true;
            submitBtn.classList.remove('btn-danger');
            submitBtn.classList.add('btn-secondary');
        }
    }

    // Add event listeners
    confirmText.addEventListener('input', checkFormValidity);
    adminPassword.addEventListener('input', checkFormValidity);
    finalCheck.addEventListener('change', checkFormValidity);

    // Form submission confirmation
    form.addEventListener('submit', function(e) {
        // Additional validation before submission
        if (confirmText.value !== 'CONFIRM_RESET_ALL_POINTS') {
            alert('يرجى كتابة نص التأكيد بالضبط كما هو مطلوب');
            e.preventDefault();
            return false;
        }
        
        if (adminPassword.value.trim().length === 0) {
            alert('يرجى إدخال كلمة مرور المدير');
            e.preventDefault();
            return false;
        }
        
        if (!finalCheck.checked) {
            alert('يرجى تأكيد فهمك لطبيعة العملية');
            e.preventDefault();
            return false;
        }
        
        
        // Show loading state
        submitBtn.innerHTML = '<i class="fas fa-spinner fa-spin me-1"></i>جاري التنفيذ...';
        submitBtn.disabled = true;
    });
});
</script>

<?php
// Include footer
include_once '../includes/admin_footer.php';
?>
