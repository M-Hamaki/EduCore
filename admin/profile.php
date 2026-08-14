<?php
// Start session and validate authentication
require_once '../includes/session_config.php';
require_once '../classes/utilities.php';
require_once '../src/Modules/Operations/Audit/AuditService.php';
require_once '../includes/csrf.php';
Utilities::validateSession('admin');
requireCsrfPost();

// Set page title
$page_title = "الملف الشخصي";
$custom_page_title = true; // This page has its own custom title

// Include database and necessary classes
require_once '../config/database.php';
require_once '../classes/user.php';
require_once '../classes/utilities.php';

// Initialize database connection
$database = new Database();
$db = $database->getConnection();

// Initialize user object
$user = new User($db);

// Get user ID from session
$user->id = $_SESSION['user_id'];

// Read user data
$user_found = false;
try {
    $user_found = $user->readOne();
} catch (Exception $e) {
    error_log("Profile page error: " . $e->getMessage());
    $user_found = false;
}

// Process form submission
$success_message = $_SESSION['success_message'] ?? '';
$error_message = $_SESSION['error_message'] ?? '';
unset($_SESSION['success_message'], $_SESSION['error_message']);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (isset($_POST['update_profile'])) {
        // Set user properties
        $user->name = $_POST['name'];
        $user->username = $_POST['username'];
        
        // Keep existing role and class_id (don't change them)
        // These should already be loaded from readOne()
        
        // Update password if provided
        if (!empty($_POST['password'])) {
            $user->password = $_POST['password'];
        } else {
            $user->password = ''; // Clear password to prevent updating it
        }
        
        try {
            $db->beginTransaction();
            $beforeStmt = $db->prepare('SELECT * FROM users WHERE id = ? FOR UPDATE');
            $beforeStmt->execute([(int)$user->id]);
            $before = $beforeStmt->fetch(PDO::FETCH_ASSOC);
            if (!$before || !$user->update()) {
                throw new RuntimeException('Profile update failed.');
            }
            $afterStmt = $db->prepare('SELECT * FROM users WHERE id = ?');
            $afterStmt->execute([(int)$user->id]);
            $after = $afterStmt->fetch(PDO::FETCH_ASSOC) ?: [];
            $audit = new \EduCore\Modules\Operations\Audit\AuditService($db);
            if ($user->password === '') {
                $audit->recordUpdate(
                    'admin_profile', 'users', (int)$user->id, (string)$user->name,
                    $before, $after, 'تحديث الملف الشخصي للمدير'
                );
            } else {
                $audit->recordEvent(
                    'security_update', 'admin_profile', (int)$user->id, (string)$user->name,
                    [
                        'changes' => \EduCore\Modules\Operations\Audit\EntityChangeTracker::diff($before, $after),
                        'password_changed' => true,
                        'undo_policy' => 'credential_change_not_direct_undo',
                    ]
                );
            }
            $db->commit();
            $_SESSION['success_message'] = "تم تحديث بيانات الملف الشخصي بنجاح.";
            $_SESSION['name'] = $user->name;
            $_SESSION['username'] = $user->username;
        } catch (Throwable $e) {
            if ($db->inTransaction()) {
                $db->rollBack();
            }
            error_log('admin profile update error: ' . $e->getMessage());
            $_SESSION['error_message'] = "حدث خطأ أثناء تحديث الملف الشخصي. الرجاء المحاولة مرة أخرى.";
        }
        header("Location: profile.php");
        exit();
    }
}

// Include header
include_once '../includes/admin_header.php';
?>

<!-- Page Title and Description -->
<div class="d-flex justify-content-between flex-wrap flex-md-nowrap align-items-center pt-3 pb-2 mb-3 border-bottom">
    <h1 class="h2"><i class="fas fa-user-circle me-2"></i>الملف الشخصي</h1>
</div>

<!-- Content area -->
<div class="row justify-content-center">
    <div class="col-md-8">
        <div class="card shadow">
            <div class="card-header bg-primary text-white">
                <h5 class="mb-0"><i class="fas fa-user-circle me-2"></i> تحديث البيانات الشخصية</h5>
            </div>
            
            <div class="card-body">
                    <?php if ($success_message): ?>
                        <div class="alert alert-success alert-dismissible fade show" role="alert">
                            <?php echo $success_message; ?>
                            <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
                        </div>
                    <?php endif; ?>
                    
                    <?php if ($error_message): ?>
                        <div class="alert alert-danger alert-dismissible fade show" role="alert">
                            <?php echo $error_message; ?>
                            <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
                        </div>
                    <?php endif; ?>                      <?php if ($user_found): ?>
<form method="post" action="" id="profileForm">
    <?php echo csrfField(); ?>
                            <div class="row mb-3">
                                <div class="col-md-6">
                                    <label for="name" class="form-label">الاسم الكامل</label>
                                    <input type="text" class="form-control" id="name" name="name" value="<?php echo htmlspecialchars($user->name ?: $_SESSION['name'] ?: 'غير محدد'); ?>" required>
                                </div>
                                <div class="col-md-6">
                                    <label for="username" class="form-label">اسم المستخدم</label>
                                    <input type="text" class="form-control" id="username" name="username" value="<?php echo htmlspecialchars($user->username ?: $_SESSION['username'] ?: 'غير محدد'); ?>" required>
                                </div>
                            </div>
                            
                            <div class="mb-3">
                                <label for="role" class="form-label">الدور الوظيفي</label>
                                <input type="text" class="form-control" id="role" value="<?php echo Utilities::translateRole($user->role ?: $_SESSION['role'] ?: 'admin'); ?>" readonly>
                                <div class="form-text">لا يمكن تغيير الدور الوظيفي من هنا</div>
                            </div>
                            
                            <div class="mb-4">
                                <label for="password" class="form-label">كلمة المرور الجديدة</label>
                                <input type="password" class="form-control" id="password" name="password" placeholder="اتركها فارغة للإبقاء على كلمة المرور الحالية">
                                <div class="form-text">أدخل كلمة مرور جديدة فقط إذا كنت تريد تغييرها</div>
                            </div>
                            
                            <div class="d-grid gap-2 d-md-flex justify-content-md-end">
                                <button type="button" class="btn btn-secondary me-md-2" onclick="resetForm()">
                                    <i class="fas fa-undo me-1"></i> إعادة ضبط
                                </button>
                                <button type="submit" name="update_profile" class="btn btn-primary">
                                    <i class="fas fa-save me-1"></i> حفظ التغييرات
                                </button>
                            </div>
                        </form>                    <?php else: ?>
                        <div class="alert alert-warning">
                            <i class="fas fa-exclamation-triangle me-2"></i> 
                            لم يتم العثور على بيانات المستخدم في قاعدة البيانات. يمكنك استخدام النموذج أدناه لإنشاء/تحديث بياناتك.
                        </div>
                        
                        <!-- Show form with session data as fallback -->
<form method="post" action="" id="profileForm">
    <?php echo csrfField(); ?>
                            <div class="row mb-3">
                                <div class="col-md-6">
                                    <label for="name" class="form-label">الاسم الكامل</label>
                                    <input type="text" class="form-control" id="name" name="name" value="<?php echo htmlspecialchars($_SESSION['name'] ?? 'الأدمن'); ?>" required>
                                </div>
                                <div class="col-md-6">
                                    <label for="username" class="form-label">اسم المستخدم</label>
                                    <input type="text" class="form-control" id="username" name="username" value="<?php echo htmlspecialchars($_SESSION['username'] ?? 'admin'); ?>" required>
                                </div>
                            </div>
                            
                            <div class="mb-3">
                                <label for="role" class="form-label">الدور الوظيفي</label>
                                <input type="text" class="form-control" id="role" value="<?php echo Utilities::translateRole($_SESSION['role'] ?? 'admin'); ?>" readonly>
                                <div class="form-text">لا يمكن تغيير الدور الوظيفي من هنا</div>
                            </div>
                            
                            <div class="mb-4">
                                <label for="password" class="form-label">كلمة المرور الجديدة</label>
                                <input type="password" class="form-control" id="password" name="password" placeholder="أدخل كلمة مرور جديدة">
                                <div class="form-text">مطلوب إدخال كلمة مرور لإنشاء/تحديث الحساب</div>
                            </div>
                            
                            <div class="d-grid gap-2 d-md-flex justify-content-md-end">
                                <button type="button" class="btn btn-secondary me-md-2" onclick="resetForm()">
                                    <i class="fas fa-undo me-1"></i> إعادة ضبط
                                </button>
                                <button type="submit" name="update_profile" class="btn btn-primary">
                                    <i class="fas fa-save me-1"></i> حفظ التغييرات
                                </button>
                            </div>
                        </form>
                    <?php endif; ?></div>
            </div>
        </div>    </div>
</div>

<script>
function resetForm() {
    document.getElementById('profileForm').reset();
    // Clear password field specifically
    document.getElementById('password').value = '';
}
</script>

<?php 
// Include footer
include_once '../includes/admin_footer.php';
?>
