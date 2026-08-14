<?php
/**
 * بوابة المعلمين الخارجيين - تسجيل الدخول والتسجيل الجديد
 * External Teachers Portal - Sign In / Sign Up
 */
define('ACCESS_ALLOWED', true);

ini_set('display_errors', 0);
error_reporting(E_ALL);

require_once 'includes/session_config.php';
require_once 'config/database.php';
require_once 'config/encryption.php';

header("Cache-Control: no-store, no-cache, must-revalidate, max-age=0");
header("Pragma: no-cache");

$database = new Database();
$db = $database->getConnection();

// إذا كان المعلم الخارجي مسجل دخوله بالفعل
if (isset($_SESSION['user_id']) && $_SESSION['role'] === 'external_teacher') {
    header('Location: external/index.php');
    exit;
}

// جلب الإعدادات
$regStmt = $db->prepare("SELECT setting_value FROM settings WHERE setting_key = 'external_registration_enabled'");
$regStmt->execute();
$registration_enabled = ($regStmt->fetchColumn() ?: '1') === '1';

$autoStmt = $db->prepare("SELECT setting_value FROM settings WHERE setting_key = 'external_auto_approve'");
$autoStmt->execute();
$auto_approve = ($autoStmt->fetchColumn() ?: '0') === '1';

// جلب اسم المدرسة
$schoolStmt = $db->prepare("SELECT setting_value FROM settings WHERE setting_key = 'school_name'");
$schoolStmt->execute();
$school_name = $schoolStmt->fetchColumn() ?: 'EduCore';

$error_message = '';
$success_message = '';
$active_tab = 'signin';
$show_reset = false;

// معالجة النموذج
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // التحقق من CSRF
    $submittedCsrfToken = (string)($_POST['csrf_token'] ?? '');
    $sessionCsrfToken = (string)($_SESSION['csrf_token'] ?? '');
    if ($submittedCsrfToken === '' || $sessionCsrfToken === '' || !hash_equals($sessionCsrfToken, $submittedCsrfToken)) {
        $error_message = 'طلب غير صالح. يرجى المحاولة مرة أخرى.';
    } else {
        $action = $_POST['action'] ?? '';

        // ===== تسجيل الدخول =====
        if ($action === 'signin') {
            $email = trim($_POST['email'] ?? '');
            $password = $_POST['password'] ?? '';

            if (empty($email) || empty($password)) {
                $error_message = 'يرجى إدخال البريد الإلكتروني وكلمة المرور';
            } else {
                $stmt = $db->prepare("SELECT id, name, email, password_hash, status FROM external_teachers WHERE email = ?");
                $stmt->execute([$email]);
                $teacher = $stmt->fetch(PDO::FETCH_ASSOC);

                // Support both AES-256 encrypted and legacy bcrypt passwords
                $passwordValid = false;
                if ($teacher) {
                    if (isPasswordEncrypted($teacher['password_hash'])) {
                        $passwordValid = (decryptPassword($teacher['password_hash']) === $password);
                    } else {
                        $passwordValid = password_verify($password, $teacher['password_hash']);
                    }
                }

                if (!$teacher || !$passwordValid) {
                    $error_message = 'البريد الإلكتروني أو كلمة المرور غير صحيحة';
                } elseif ($teacher['status'] === 'pending') {
                    $error_message = 'حسابك قيد المراجعة. سيتم تفعيله من قبل الإدارة قريباً.';
                } elseif ($teacher['status'] === 'blocked') {
                    $error_message = 'تم حظر حسابك. يرجى التواصل مع الإدارة.';
                } else {
                    // تسجيل دخول ناجح
                    session_regenerate_id(true);
                    $_SESSION['user_id'] = $teacher['id'];
                    $_SESSION['name'] = $teacher['name'];
                    $_SESSION['role'] = 'external_teacher';
                    $_SESSION['external_email'] = $teacher['email'];

                    // تحديث آخر دخول
                    $upd = $db->prepare("UPDATE external_teachers SET last_login = NOW() WHERE id = ?");
                    $upd->execute([$teacher['id']]);

                    header('Location: external/index.php');
                    exit;
                }
            }
        }

        // ===== استعادة كلمة المرور =====
        elseif ($action === 'reset_password') {
            $active_tab = 'signin';
            $show_reset = true;
            $reset_email = trim($_POST['reset_email'] ?? '');
            $reset_phone = trim($_POST['reset_phone'] ?? '');
            $new_pass = $_POST['reset_new_password'] ?? '';
            $confirm_pass = $_POST['reset_confirm_password'] ?? '';

            if (empty($reset_email) || empty($reset_phone) || empty($new_pass)) {
                $error_message = 'يرجى ملء جميع الحقول المطلوبة';
            } elseif (mb_strlen($new_pass) < 6) {
                $error_message = 'كلمة المرور يجب أن تكون 6 أحرف على الأقل';
            } elseif ($new_pass !== $confirm_pass) {
                $error_message = 'كلمة المرور وتأكيدها غير متطابقين';
            } else {
                $stmt = $db->prepare("SELECT id, phone, status FROM external_teachers WHERE email = ?");
                $stmt->execute([$reset_email]);
                $teacher = $stmt->fetch(PDO::FETCH_ASSOC);

                if (!$teacher) {
                    $error_message = 'لا يوجد حساب مسجل بهذا البريد الإلكتروني';
                } elseif ($teacher['status'] === 'blocked') {
                    $error_message = 'هذا الحساب محظور. يرجى التواصل مع الإدارة.';
                } elseif (empty($teacher['phone']) || $teacher['phone'] !== $reset_phone) {
                    $error_message = 'رقم الهاتف لا يتطابق مع البيانات المسجلة';
                } else {
                    $hash = encryptPassword($new_pass);
                    $upd = $db->prepare("UPDATE external_teachers SET password_hash = ? WHERE id = ?");
                    $upd->execute([$hash, $teacher['id']]);
                    $success_message = 'تم تغيير كلمة المرور بنجاح! يمكنك الآن تسجيل الدخول.';
                    $show_reset = false;
                }
            }
        }

        // ===== التسجيل الجديد =====
        elseif ($action === 'signup') {
            $active_tab = 'signup';

            if (!$registration_enabled) {
                $error_message = 'التسجيل مغلق حالياً. يرجى التواصل مع الإدارة.';
            } else {
                $name = trim($_POST['name'] ?? '');
                $email = trim($_POST['signup_email'] ?? '');
                $password = $_POST['signup_password'] ?? '';
                $password_confirm = $_POST['signup_password_confirm'] ?? '';
                $phone = trim($_POST['phone'] ?? '');
                $school = trim($_POST['school_name'] ?? '');
                $specialization = trim($_POST['specialization'] ?? '');

                // التحقق من المدخلات
                if (empty($name) || empty($email) || empty($password)) {
                    $error_message = 'يرجى ملء جميع الحقول المطلوبة';
                } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
                    $error_message = 'البريد الإلكتروني غير صالح';
                } elseif (mb_strlen($password) < 6) {
                    $error_message = 'كلمة المرور يجب أن تكون 6 أحرف على الأقل';
                } elseif ($password !== $password_confirm) {
                    $error_message = 'كلمة المرور وتأكيدها غير متطابقين';
                } else {
                    // التحقق من عدم وجود بريد مكرر
                    $chk = $db->prepare("SELECT id FROM external_teachers WHERE email = ?");
                    $chk->execute([$email]);
                    if ($chk->fetch()) {
                        $error_message = 'هذا البريد الإلكتروني مسجل بالفعل. يمكنك تسجيل الدخول.';
                    } else {
                        $hash = encryptPassword($password);
                        $status = $auto_approve ? 'active' : 'pending';

                        $ins = $db->prepare("INSERT INTO external_teachers (name, email, password_hash, phone, school_name, specialization, status) VALUES (?, ?, ?, ?, ?, ?, ?)");
                        $ins->execute([$name, $email, $hash, $phone ?: null, $school ?: null, $specialization ?: null, $status]);
                        $newTeacherId = $db->lastInsertId();

                        // توليد كود المعلم الخارجي تلقائياً
                        $year = date('Y');
                        $maxStmt = $db->prepare("SELECT MAX(CAST(SUBSTRING(teacher_code, 6) AS UNSIGNED)) as max_num FROM external_teachers WHERE teacher_code LIKE ?");
                        $maxStmt->execute(["X{$year}%"]);
                        $maxRow = $maxStmt->fetch(PDO::FETCH_ASSOC);
                        $nextNum = ($maxRow['max_num'] ?? 0) + 1;
                        $teacherCode = 'X' . $year . str_pad($nextNum, 4, '0', STR_PAD_LEFT);
                        $db->prepare("UPDATE external_teachers SET teacher_code = ? WHERE id = ?")->execute([$teacherCode, $newTeacherId]);

                        if ($auto_approve) {
                            $success_message = 'تم إنشاء حسابك بنجاح! يمكنك الآن تسجيل الدخول.';
                            $active_tab = 'signin';
                        } else {
                            $success_message = 'تم إنشاء حسابك بنجاح! سيتم مراجعته وتفعيله من قبل الإدارة قريباً.';
                        }
                    }
                }
            }
        }
    }
}

// توليد CSRF token
if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}
$csrf = $_SESSION['csrf_token'];
?>
<!DOCTYPE html>
<html lang="ar" dir="rtl">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>بوابة المعلمين - <?php echo htmlspecialchars($school_name); ?></title>
    
    <!-- Bootstrap RTL CSS -->
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.rtl.min.css">
    <!-- Font Awesome -->
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <!-- Google Font: Tajawal -->
    <link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=Tajawal:wght@400;500;700&display=swap">
    
    <?php 
    if (!function_exists('asset_url')) { require_once __DIR__ . '/includes/template_helper.php'; }
    ?>
    <!-- Custom CSS -->
    <link rel="stylesheet" href="<?php echo asset_url('assets/css/style.css'); ?>">
    <style>
    /* التبويبات */
    .auth-tabs {
        display: flex;
        gap: 8px;
        margin-bottom: 25px;
        background: #f1f5f9;
        border-radius: 12px;
        padding: 5px;
    }
    .auth-tab {
        flex: 1;
        padding: 10px;
        border: none;
        background: transparent;
        color: #64748b;
        font-family: 'Tajawal', sans-serif;
        font-size: 0.95rem;
        font-weight: 600;
        border-radius: 8px;
        cursor: pointer;
        transition: all 0.3s ease;
    }
    .auth-tab.active {
        background: linear-gradient(135deg, #667eea, #764ba2);
        color: #fff;
        box-shadow: 0 4px 12px rgba(102, 126, 234, 0.3);
    }
    .auth-tab:hover:not(.active) {
        color: #334155;
        background: #e2e8f0;
    }

    /* النماذج */
    .auth-form { display: none; }
    .auth-form.active { display: block; }

    /* حقل الإدخال المخصص */
    .input-wrapper {
        position: relative;
    }
    .input-wrapper i.field-icon {
        position: absolute;
        right: 14px;
        top: 50%;
        transform: translateY(-50%);
        color: #94a3b8;
        font-size: 0.9rem;
        z-index: 2;
        pointer-events: none;
    }
    .input-wrapper .form-control {
        padding-right: 42px;
    }
    .toggle-password {
        position: absolute;
        left: 14px;
        top: 50%;
        transform: translateY(-50%);
        background: none;
        border: none;
        color: #94a3b8;
        cursor: pointer;
        padding: 0;
        font-size: 0.9rem;
        z-index: 2;
    }
    .toggle-password:hover { color: #667eea; }

    /* أزرار المصادقة */
    .btn-signin {
        background: linear-gradient(135deg, #667eea, #764ba2);
        border: none;
        color: #fff;
        font-weight: 700;
        padding: 12px;
        border-radius: 10px;
        font-size: 1rem;
        box-shadow: 0 4px 15px rgba(102, 126, 234, 0.3);
        transition: all 0.3s;
    }
    .btn-signin:hover {
        transform: translateY(-2px);
        box-shadow: 0 6px 25px rgba(102, 126, 234, 0.4);
        color: #fff;
    }
    .btn-signup {
        background: linear-gradient(135deg, #10b981, #059669);
        border: none;
        color: #fff;
        font-weight: 700;
        padding: 12px;
        border-radius: 10px;
        font-size: 1rem;
        box-shadow: 0 4px 15px rgba(16, 185, 129, 0.3);
        transition: all 0.3s;
    }
    .btn-signup:hover {
        transform: translateY(-2px);
        box-shadow: 0 6px 25px rgba(16, 185, 129, 0.4);
        color: #fff;
    }
    .btn-reset {
        background: linear-gradient(135deg, #f59e0b, #d97706);
        border: none;
        color: #fff;
        font-weight: 700;
        padding: 12px;
        border-radius: 10px;
        font-size: 1rem;
        box-shadow: 0 4px 15px rgba(245, 158, 11, 0.3);
        transition: all 0.3s;
    }
    .btn-reset:hover {
        transform: translateY(-2px);
        box-shadow: 0 6px 25px rgba(245, 158, 11, 0.4);
        color: #fff;
    }

    /* رابط نسيت كلمة المرور */
    .forgot-link {
        text-align: center;
        margin-top: 15px;
    }
    .forgot-link a {
        color: #64748b;
        text-decoration: none;
        font-size: 0.85rem;
        font-weight: 600;
        transition: color 0.3s;
    }
    .forgot-link a:hover { color: #f59e0b; }

    /* نموذج الاستعادة */
    .reset-header {
        text-align: center;
        margin-bottom: 25px;
    }
    .reset-header i {
        font-size: 2.5rem; color: #f59e0b;
        margin-bottom: 12px; display: block;
    }
    .reset-header h3 {
        color: #1e293b; font-size: 1.2rem;
        font-weight: 700; margin-bottom: 6px;
    }
    .reset-header p {
        color: #64748b; font-size: 0.82rem;
    }

    /* صف حقلين */
    .form-row-custom {
        display: flex; gap: 12px;
    }
    .form-row-custom > div { flex: 1; }
    @media (max-width: 480px) {
        .form-row-custom { flex-direction: column; gap: 0; }
    }

    /* التسجيل مغلق */
    .reg-closed {
        text-align: center; padding: 30px 20px; color: #94a3b8;
    }
    .reg-closed i { font-size: 2.5rem; margin-bottom: 15px; display: block; color: #cbd5e1; }

    /* Required star */
    .required { color: #ef4444; }

    /* Theme Toggle Button */
    .theme-toggle {
        position: fixed;
        bottom: 30px;
        right: 30px;
        z-index: 1001;
        width: 45px;
        height: 45px;
        border-radius: 50%;
        border: none;
        background: #667eea;
        color: white;
        font-size: 1rem;
        cursor: pointer;
        box-shadow: 0 4px 15px rgba(102, 126, 234, 0.3);
        transition: all 0.3s ease;
        display: flex;
        align-items: center;
        justify-content: center;
    }
    .theme-toggle::before {
        content: '\f186';
        font-family: 'Font Awesome 6 Free';
        font-weight: 900;
        font-size: 1rem;
        transition: all 0.3s ease;
    }
    .theme-toggle:hover {
        transform: scale(1.1) rotate(15deg);
        box-shadow: 0 6px 20px rgba(102, 126, 234, 0.4);
    }
    body.dark-mode .theme-toggle {
        background: #60a5fa;
        box-shadow: 0 4px 15px rgba(96, 165, 250, 0.3);
    }
    body.dark-mode .theme-toggle::before { content: '\f185'; }
    body.dark-mode .theme-toggle:hover {
        box-shadow: 0 6px 20px rgba(96, 165, 250, 0.4);
    }

    /* Dark Mode Styles */
    body.dark-mode {
        background: linear-gradient(135deg, #1a1a2e 0%, #16213e 100%);
    }
    body.dark-mode .card {
        background: #1e293b !important;
        border-color: #334155 !important;
    }
    body.dark-mode .card-body { color: #f1f5f9; }
    body.dark-mode h1, body.dark-mode h2, body.dark-mode h3 {
        color: #f1f5f9 !important;
    }
    body.dark-mode .form-label { color: #cbd5e1; }
    body.dark-mode .form-control {
        background-color: #0f172a;
        border-color: #334155;
        color: #f1f5f9;
    }
    body.dark-mode .form-control::placeholder { color: #64748b; }
    body.dark-mode .text-muted { color: #94a3b8 !important; }
    body.dark-mode .alert-danger {
        background-color: rgba(239, 68, 68, 0.2);
        border-color: #ef4444;
        color: #fca5a5;
    }
    body.dark-mode .alert-success {
        background-color: rgba(16, 185, 129, 0.2);
        border-color: #10b981;
        color: #6ee7b7;
    }
    body.dark-mode .auth-tabs { background: rgba(255, 255, 255, 0.05); }
    body.dark-mode .auth-tab { color: rgba(255, 255, 255, 0.5); }
    body.dark-mode .auth-tab:hover:not(.active) {
        color: rgba(255, 255, 255, 0.8);
        background: rgba(255, 255, 255, 0.08);
    }
    body.dark-mode .input-wrapper i.field-icon { color: rgba(255, 255, 255, 0.3); }
    body.dark-mode .toggle-password { color: rgba(255, 255, 255, 0.3); }
    body.dark-mode .forgot-link a { color: rgba(255, 255, 255, 0.5); }
    body.dark-mode .reset-header h3 { color: #f1f5f9; }
    body.dark-mode .reset-header p { color: #94a3b8; }
    body.dark-mode .reg-closed { color: rgba(255, 255, 255, 0.5); }
    body.dark-mode .reg-closed i { color: rgba(255, 255, 255, 0.2); }

    /* School Portal Footer Styling */
    .portal-footer {
        background: transparent;
        text-align: center;
        padding: 2rem 1rem 1rem 1rem;
        font-size: 0.9rem;
        position: relative;
        z-index: 15;
    }
    /* Light Mode (Default) */
    .portal-footer p {
        color: #475569;
        margin: 0.5rem 0;
        line-height: 1.6;
    }
    .portal-footer p strong {
        color: #1e293b;
    }
    .portal-footer a {
        color: #3b82f6;
        text-decoration: none;
    }
    .social-media-footer {
        display: flex;
        justify-content: center;
        gap: 12px;
        margin-top: 1rem;
    }
    .social-footer-icon {
        width: 42px;
        height: 42px;
        border-radius: 50%;
        display: flex;
        align-items: center;
        justify-content: center;
        color: #fff !important;
        font-size: 1.1rem;
        transition: all 0.3s ease;
        text-decoration: none;
        box-shadow: 0 4px 10px rgba(0,0,0,0.12);
    }
    .social-footer-icon.facebook { background: linear-gradient(135deg, #1877f2, #0c63d4); }
    .social-footer-icon.whatsapp { background: linear-gradient(135deg, #25d366, #128c7e); }
    .social-footer-icon.instagram { background: linear-gradient(135deg, #e1306c, #c13584 50%, #833ab4); }
    .social-footer-icon:hover { transform: translateY(-4px) scale(1.1); box-shadow: 0 6px 15px rgba(0,0,0,0.2); }

    /* Dark Mode Overrides */
    body.dark-mode .portal-footer p {
        color: #94a3b8;
    }
    body.dark-mode .portal-footer p strong {
        color: #f1f5f9;
    }
    body.dark-mode .portal-footer a {
        color: #93c5fd;
    }
    body.dark-mode .social-footer-icon {
        box-shadow: 0 4px 12px rgba(0,0,0,0.4);
    }
    </style>
</head>
<body class="login-page">
    <!-- Particles Background -->
    <div id="particles-js"></div>
    
    <div class="login-outer top-aligned">
        <div class="container">
            <div class="row justify-content-center">
                <div class="col-md-8 col-lg-6">
                    
                    <!-- الشعار -->
                    <div class="text-center mb-4" style="position: relative; z-index: 15;">
                        <a href="external_login.php">
                            <img src="<?php echo asset_url(get_school_logo('')); ?>" alt="شعار المدرسة" class="img-fluid mb-3" style="max-height: 80px;">
                        </a>
                        <h1 class="fs-4 mb-1">بوابة المعلمين</h1>
                        <p class="text-muted mb-0"><?php echo htmlspecialchars($school_name); ?></p>
                    </div>

                    <div class="card shadow-sm" style="border-radius: 16px;">
                        <div class="card-body p-4 p-md-5">
                            
                            <!-- التبويبات -->
                            <div class="auth-tabs">
                                <button class="auth-tab <?php echo $active_tab === 'signin' ? 'active' : ''; ?>" data-tab="signin">
                                    <i class="fas fa-sign-in-alt me-1"></i> تسجيل الدخول
                                </button>
                                <button class="auth-tab <?php echo $active_tab === 'signup' ? 'active' : ''; ?>" data-tab="signup">
                                    <i class="fas fa-user-plus me-1"></i> حساب جديد
                                </button>
                            </div>

                            <!-- الرسائل -->
                            <?php if ($error_message): ?>
                                <div class="alert alert-danger d-flex align-items-center gap-2 py-2" role="alert">
                                    <i class="fas fa-exclamation-circle"></i>
                                    <?php echo htmlspecialchars($error_message); ?>
                                </div>
                            <?php endif; ?>

                            <?php if ($success_message): ?>
                                <div class="alert alert-success d-flex align-items-center gap-2 py-2" role="alert">
                                    <i class="fas fa-check-circle"></i>
                                    <?php echo htmlspecialchars($success_message); ?>
                                </div>
                            <?php endif; ?>

                            <!-- نموذج تسجيل الدخول -->
                            <form class="auth-form <?php echo $active_tab === 'signin' ? 'active' : ''; ?>" id="signinForm" method="POST" action="">
                                <input type="hidden" name="csrf_token" value="<?php echo $csrf; ?>">
                                <input type="hidden" name="action" value="signin">

                                <div class="mb-3">
                                    <label class="form-label">البريد الإلكتروني</label>
                                    <div class="input-wrapper">
                                        <i class="fas fa-envelope field-icon"></i>
                                        <input type="email" name="email" class="form-control" placeholder="example@email.com" required autocomplete="email" dir="ltr">
                                    </div>
                                </div>

                                <div class="mb-3">
                                    <label class="form-label">كلمة المرور</label>
                                    <div class="input-wrapper">
                                        <i class="fas fa-lock field-icon"></i>
                                        <input type="password" name="password" class="form-control" placeholder="••••••••" required autocomplete="current-password" dir="ltr" id="signinPass" style="padding-left: 42px;">
                                        <button type="button" class="toggle-password" onclick="togglePass('signinPass', this)">
                                            <i class="fas fa-eye"></i>
                                        </button>
                                    </div>
                                </div>

                                <div class="d-grid">
                                    <button type="submit" class="btn btn-signin">
                                        <i class="fas fa-sign-in-alt me-1"></i> تسجيل الدخول
                                    </button>
                                </div>

                                <div class="forgot-link">
                                    <a href="#" onclick="showResetForm(); return false;"><i class="fas fa-key me-1"></i>نسيت كلمة المرور؟</a>
                                </div>
                            </form>

                            <!-- نموذج استعادة كلمة المرور -->
                            <form class="auth-form <?php echo !empty($show_reset) ? 'active' : ''; ?>" id="resetForm" method="POST" action="">
                                <input type="hidden" name="csrf_token" value="<?php echo $csrf; ?>">
                                <input type="hidden" name="action" value="reset_password">

                                <div class="reset-header">
                                    <i class="fas fa-unlock-alt"></i>
                                    <h3>استعادة كلمة المرور</h3>
                                    <p>أدخل بريدك الإلكتروني ورقم الهاتف المسجل للتحقق من هويتك</p>
                                </div>

                                <div class="mb-3">
                                    <label class="form-label">البريد الإلكتروني <span class="required">*</span></label>
                                    <div class="input-wrapper">
                                        <i class="fas fa-envelope field-icon"></i>
                                        <input type="email" name="reset_email" class="form-control" placeholder="example@email.com" required dir="ltr" autocomplete="email" value="<?php echo htmlspecialchars($_POST['reset_email'] ?? ''); ?>">
                                    </div>
                                </div>

                                <div class="mb-3">
                                    <label class="form-label">رقم الهاتف المسجل <span class="required">*</span></label>
                                    <div class="input-wrapper">
                                        <i class="fas fa-phone field-icon"></i>
                                        <input type="tel" name="reset_phone" class="form-control" placeholder="رقم الهاتف الذي سجلت به" required dir="ltr" value="<?php echo htmlspecialchars($_POST['reset_phone'] ?? ''); ?>">
                                    </div>
                                </div>

                                <div class="form-row-custom">
                                    <div class="mb-3">
                                        <label class="form-label">كلمة المرور الجديدة <span class="required">*</span></label>
                                        <div class="input-wrapper">
                                            <i class="fas fa-lock field-icon"></i>
                                            <input type="password" name="reset_new_password" class="form-control" placeholder="6 أحرف على الأقل" required minlength="6" dir="ltr" id="resetPass" style="padding-left: 42px;">
                                            <button type="button" class="toggle-password" onclick="togglePass('resetPass', this)">
                                                <i class="fas fa-eye"></i>
                                            </button>
                                        </div>
                                    </div>
                                    <div class="mb-3">
                                        <label class="form-label">تأكيد كلمة المرور <span class="required">*</span></label>
                                        <div class="input-wrapper">
                                            <i class="fas fa-lock field-icon"></i>
                                            <input type="password" name="reset_confirm_password" class="form-control" placeholder="أعد كلمة المرور" required minlength="6" dir="ltr" id="resetPassConfirm">
                                        </div>
                                    </div>
                                </div>

                                <div class="d-grid">
                                    <button type="submit" class="btn btn-reset">
                                        <i class="fas fa-save me-1"></i> تغيير كلمة المرور
                                    </button>
                                </div>

                                <div class="forgot-link">
                                    <a href="#" onclick="hideResetForm(); return false;"><i class="fas fa-arrow-right me-1"></i>العودة لتسجيل الدخول</a>
                                </div>
                            </form>

                            <!-- نموذج التسجيل الجديد -->
                            <form class="auth-form <?php echo $active_tab === 'signup' ? 'active' : ''; ?>" id="signupForm" method="POST" action="">
                                <input type="hidden" name="csrf_token" value="<?php echo $csrf; ?>">
                                <input type="hidden" name="action" value="signup">

                                <?php if (!$registration_enabled): ?>
                                    <div class="reg-closed">
                                        <i class="fas fa-lock"></i>
                                        <p>التسجيل مغلق حالياً.<br>يرجى التواصل مع إدارة المدرسة.</p>
                                    </div>
                                <?php else: ?>
                                    <div class="mb-3">
                                        <label class="form-label">الاسم الكامل <span class="required">*</span></label>
                                        <div class="input-wrapper">
                                            <i class="fas fa-user field-icon"></i>
                                            <input type="text" name="name" class="form-control" placeholder="أدخل اسمك الكامل" required value="<?php echo htmlspecialchars($_POST['name'] ?? ''); ?>">
                                        </div>
                                    </div>

                                    <div class="mb-3">
                                        <label class="form-label">البريد الإلكتروني <span class="required">*</span></label>
                                        <div class="input-wrapper">
                                            <i class="fas fa-envelope field-icon"></i>
                                            <input type="email" name="signup_email" class="form-control" placeholder="example@email.com" required dir="ltr" autocomplete="email" value="<?php echo htmlspecialchars($_POST['signup_email'] ?? ''); ?>">
                                        </div>
                                    </div>

                                    <div class="form-row-custom">
                                        <div class="mb-3">
                                            <label class="form-label">كلمة المرور <span class="required">*</span></label>
                                            <div class="input-wrapper">
                                                <i class="fas fa-lock field-icon"></i>
                                                <input type="password" name="signup_password" class="form-control" placeholder="6 أحرف على الأقل" required minlength="6" dir="ltr" id="signupPass" style="padding-left: 42px;">
                                                <button type="button" class="toggle-password" onclick="togglePass('signupPass', this)">
                                                    <i class="fas fa-eye"></i>
                                                </button>
                                            </div>
                                        </div>
                                        <div class="mb-3">
                                            <label class="form-label">تأكيد كلمة المرور <span class="required">*</span></label>
                                            <div class="input-wrapper">
                                                <i class="fas fa-lock field-icon"></i>
                                                <input type="password" name="signup_password_confirm" class="form-control" placeholder="أعد كلمة المرور" required minlength="6" dir="ltr" id="signupPassConfirm">
                                            </div>
                                        </div>
                                    </div>

                                    <div class="form-row-custom">
                                        <div class="mb-3">
                                            <label class="form-label">رقم الهاتف</label>
                                            <div class="input-wrapper">
                                                <i class="fas fa-phone field-icon"></i>
                                                <input type="tel" name="phone" class="form-control" placeholder="01xxxxxxxxx" dir="ltr" value="<?php echo htmlspecialchars($_POST['phone'] ?? ''); ?>">
                                            </div>
                                        </div>
                                        <div class="mb-3">
                                            <label class="form-label">التخصص</label>
                                            <div class="input-wrapper">
                                                <i class="fas fa-graduation-cap field-icon"></i>
                                                <input type="text" name="specialization" class="form-control" placeholder="مثال: لغة عربية" value="<?php echo htmlspecialchars($_POST['specialization'] ?? ''); ?>">
                                            </div>
                                        </div>
                                    </div>

                                    <div class="mb-3">
                                        <label class="form-label">اسم المدرسة</label>
                                        <div class="input-wrapper">
                                            <i class="fas fa-school field-icon"></i>
                                            <input type="text" name="school_name" class="form-control" placeholder="اسم مدرستك" value="<?php echo htmlspecialchars($_POST['school_name'] ?? ''); ?>">
                                        </div>
                                    </div>

                                    <div class="d-grid">
                                        <button type="submit" class="btn btn-signup">
                                            <i class="fas fa-user-plus me-1"></i> إنشاء حساب
                                        </button>
                                    </div>
                                <?php endif; ?>
                            </form>

                        </div>
                    </div>

                    <!-- School Portal Footer -->
                    <footer class="portal-footer">
                        <div class="container text-center">
                            <p style="margin: 0.5rem 0; line-height: 1.6;">
                                <strong>جميع الحقوق محفوظة © <?php echo date('Y'); ?></strong>
                            </p>
                            <p style="margin: 0.5rem 0; line-height: 1.6;">
                                Delta Modern Language Schools<br>
                                Computer Department
                            </p>
                            
                            <!-- Social Media Icons in Footer -->
                            <div class="social-media-footer">
                                <a href="https://www.facebook.com/DELTA.MLS" target="_blank" class="social-footer-icon facebook" title="صفحتنا على الفيسبوك">
                                    <i class="fab fa-facebook-f"></i>
                                </a>
                                <a href="https://wa.me/201289999818" target="_blank" class="social-footer-icon whatsapp" title="الدعم الفني - واتساب">
                                    <i class="fab fa-whatsapp"></i>
                                </a>
                                <a href="https://www.instagram.com/delta.mls" target="_blank" class="social-footer-icon instagram" title="حسابنا على انستجرام">
                                    <i class="fab fa-instagram"></i>
                                </a>
                            </div>
                        </div>
                    </footer>

                </div>
            </div>
        </div>
    </div>

    <!-- Bootstrap JS -->
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <!-- Particles + Theme -->
    <script src="https://cdn.jsdelivr.net/npm/particles.js@2.0.0/particles.min.js"></script>
    <script src="<?php echo asset_url('assets/js/particles_theme.js'); ?>"></script>
    <!-- Custom JS -->
    <script src="<?php echo asset_url('assets/js/main.js'); ?>"></script>
    
    <script>
    // تبديل التبويبات
    document.querySelectorAll('.auth-tab').forEach(tab => {
        tab.addEventListener('click', () => {
            document.querySelectorAll('.auth-tab').forEach(t => t.classList.remove('active'));
            document.querySelectorAll('.auth-form').forEach(f => f.classList.remove('active'));
            tab.classList.add('active');
            document.getElementById(tab.dataset.tab + 'Form').classList.add('active');
        });
    });

    // إظهار/إخفاء كلمة المرور
    function togglePass(inputId, btn) {
        const input = document.getElementById(inputId);
        const icon = btn.querySelector('i');
        if (input.type === 'password') {
            input.type = 'text';
            icon.classList.replace('fa-eye', 'fa-eye-slash');
        } else {
            input.type = 'password';
            icon.classList.replace('fa-eye-slash', 'fa-eye');
        }
    }

    // إظهار نموذج استعادة كلمة المرور
    function showResetForm() {
        document.querySelectorAll('.auth-form').forEach(f => f.classList.remove('active'));
        document.querySelectorAll('.auth-tab').forEach(t => t.classList.remove('active'));
        document.getElementById('resetForm').classList.add('active');
        document.querySelector('.auth-tabs').style.display = 'none';
    }

    // العودة لتسجيل الدخول
    function hideResetForm() {
        document.querySelectorAll('.auth-form').forEach(f => f.classList.remove('active'));
        document.getElementById('signinForm').classList.add('active');
        document.querySelector('.auth-tabs').style.display = 'flex';
        document.querySelectorAll('.auth-tab').forEach(t => t.classList.remove('active'));
        document.querySelector('[data-tab="signin"]').classList.add('active');
    }

    // إذا كان نموذج الاستعادة ظاهراً (بعد POST)
    <?php if (!empty($show_reset)): ?>
    document.addEventListener('DOMContentLoaded', function() {
        showResetForm();
    });
    <?php endif; ?>
    </script>
</body>
</html>
