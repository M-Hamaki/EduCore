<?php
// تعريف للسماح بالوصول للملفات المحمية
define('ACCESS_ALLOWED', true);

// إعدادات الإنتاج - قم بتعديل هذه القيم عند النشر
ini_set('display_errors', 0); // غيّر إلى 0 في الإنتاج
ini_set('display_startup_errors', 0); // غيّر إلى 0 في الإنتاج
error_reporting(0); // غيّر إلى 0 في الإنتاج

// Start session if not already started
if (session_status() == PHP_SESSION_NONE) {
    session_start();
}

// Generate CSRF token if not exists
if (!isset($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}
require_once '../includes/csrf.php';
requireCsrfPost();

// Include database and necessary classes
require_once 'config/database.php';
require_once 'classes/user.php';
require_once 'classes/utilities.php';

// Initialize database connection
$database = new Database();
$db = $database->getConnection();

// Initialize user object
$user = new User($db);

// If user is already logged in, redirect to their dashboard
if (isset($_SESSION['user_id'])) {
    $role = $_SESSION['role'];
    header("Location: " . Utilities::getDashboardUrl($role));
    exit;
}

// Check if form is submitted
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (empty($_POST['username']) || empty($_POST['password'])) {
        $error_message = "يرجى إدخال اسم المستخدم وكلمة المرور";
    } else {
        // Get user data from form
        $user->username = trim($_POST['username']);
        $user->password = trim($_POST['password']);
        
        // Log login attempt
        error_log("Login attempt for username: " . $user->username . " from IP: " . $_SERVER['REMOTE_ADDR']);
        
        // Try to login
        if ($user->login()) {
            // Set session variables
            $_SESSION['user_id'] = $user->id;
            $_SESSION['name'] = $user->name;
            $_SESSION['role'] = $user->role;
            $_SESSION['class_id'] = $user->class_id; // حفظ معرف الفصل في الجلسة
            
            // Log successful login
            error_log("Successful login for user ID: " . $user->id . " (" . $user->username . ") with role: " . $user->role);
            Utilities::logAction('login', 'User logged in successfully', $user->id);
            
            // Redirect to appropriate dashboard
            header("Location: " . Utilities::getDashboardUrl($user->role));
            exit;
        } else {
            // Log failed login attempt
            error_log("Failed login attempt for username: " . $user->username . " from IP: " . $_SERVER['REMOTE_ADDR']);
            $error_message = $user->getLoginDenialMessage() ?: "اسم المستخدم أو كلمة المرور غير صحيحة";
        }
    }
}
?>
<!DOCTYPE html>
<html lang="ar" dir="rtl">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>تسجيل الدخول - نظام إدارة نقاط التقييم الصفي</title>
    <!-- Bootstrap RTL CSS -->
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.rtl.min.css">
    <!-- Custom CSS -->
    <link rel="stylesheet" href="../assets/css/style.css">
    <!-- Font Awesome -->
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css">
</head>
<body>
    <div class="container mt-5">
        <div class="row justify-content-center">
            <!-- Logo -->            <div class="col-md-6 text-center mb-4">
                <img src="assets/img/logo.png" alt="شعار النظام" class="img-fluid logo-img" style="max-height: 150px;">
            </div>
        </div>
        <div class="row justify-content-center">
            <div class="col-md-6">
                <div class="card shadow">
                    <div class="card-body p-5">
                        <h1 class="text-center mb-4">تسجيل الدخول</h1>
                        
                        <?php if (isset($error_message)): ?>
                            <div class="alert alert-danger" role="alert">
                                <?php echo $error_message; ?>
                            </div>
                        <?php endif; ?>                        <form method="POST" action="<?php echo htmlspecialchars($_SERVER['PHP_SELF']); ?>">
                            <!-- CSRF Protection -->
                            <input type="hidden" name="csrf_token" value="<?php echo $_SESSION['csrf_token']; ?>">
                            
                            <div class="mb-3">
                                <label for="username" class="form-label">اسم المستخدم</label>
                                <div class="input-group">
                                    <span class="input-group-text"><i class="fas fa-user"></i></span>
                                    <input type="text" class="form-control" id="username" name="username" required autocomplete="username">
                                </div>
                            </div>
                            <div class="mb-4">
                                <label for="password" class="form-label">كلمة المرور</label>
                                <div class="input-group">
                                    <span class="input-group-text"><i class="fas fa-lock"></i></span>
                                    <input type="password" class="form-control" id="password" name="password" required autocomplete="current-password">
                                </div>
                            </div>
                            <div class="d-grid gap-2">
                                <button type="submit" class="btn btn-primary btn-lg">
                                    <i class="fas fa-sign-in-alt me-2"></i>تسجيل الدخول
                                </button>
                            </div>
                        </form>
                        
                        <div class="text-center mt-4">
                            <a href="portal.php" class="text-decoration-none" style="font-size: 1rem; font-weight: 500; color: #0d6efd;">
                                <i class="fas fa-chevron-left ms-1"></i> العودة للبوابة الرئيسية
                            </a>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- Bootstrap JS -->
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
    <!-- Custom JS -->
    <script src="assets/js/main.js"></script>
</body>
</html>
