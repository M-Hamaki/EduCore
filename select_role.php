<?php

declare(strict_types=1);

define('ACCESS_ALLOWED', true);

require_once __DIR__ . '/includes/session_config.php';
require_once __DIR__ . '/includes/csrf.php';
require_once __DIR__ . '/config/database.php';
require_once __DIR__ . '/classes/utilities.php';
require_once __DIR__ . '/classes/StaffActiveRoleService.php';
require_once __DIR__ . '/classes/StaffRoleAssignmentService.php';
require_once __DIR__ . '/includes/template_helper.php';

if (empty($_SESSION['user_id'])) {
    header('Location: index.php');
    exit;
}

$db = (new Database())->getConnection();
$userId = (int)$_SESSION['user_id'];
$roleAssignments = new StaffRoleAssignmentService($db);
$activeRoleService = new StaffActiveRoleService($db);
$roles = array_values(array_filter(
    $roleAssignments->rolesForUser($userId, true),
    static fn(array $role): bool => (string)$role['role_key'] !== StaffRoleAssignmentService::EMPLOYEE_ROLE
));
$errorMessage = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    requireCsrfPost();
    try {
        $selectedRole = $activeRoleService->activateRole(
            $_SESSION,
            $userId,
            (string)($_POST['role_key'] ?? '')
        );
        session_regenerate_id(true);
        Utilities::logAction('role_switch', 'Active portal role changed to ' . $selectedRole, $userId);
        header('Location: ' . Utilities::getDashboardUrl($selectedRole));
        exit;
    } catch (InvalidArgumentException $e) {
        $errorMessage = $e->getMessage();
        $roles = array_values(array_filter(
            $roleAssignments->rolesForUser($userId, true),
            static fn(array $role): bool => (string)$role['role_key'] !== StaffRoleAssignmentService::EMPLOYEE_ROLE
        ));
    } catch (Throwable $e) {
        error_log('Active role switch failed: ' . $e->getMessage());
        $errorMessage = 'تعذر تفعيل الدور المحدد حالياً.';
    }
}

$roleIcons = [
    'admin' => ['icon' => 'fa-user-shield', 'gradient' => 'linear-gradient(135deg, #1e3a8a, #3b82f6)', 'badge' => 'إدارة عليا', 'border' => '#3b82f6', 'desc' => 'إدارة كامل النظام، المستخدمين، والصلاحيات والإعدادات العامة'],
    'super_admin' => ['icon' => 'fa-shield-halved', 'gradient' => 'linear-gradient(135deg, #0f172a, #3b82f6)', 'badge' => 'وصول مطلق', 'border' => '#334155', 'desc' => 'الوصول المطلق لجميع الأقسام وتكوين النظام البنيوي'],
    'teacher' => ['icon' => 'fa-chalkboard-user', 'gradient' => 'linear-gradient(135deg, #065f46, #10b981)', 'badge' => 'بوابة الأكاديميا', 'border' => '#10b981', 'desc' => 'متابعة الحصص والطلاب، إدخال الدرجات والواجبات والحضور'],
    'supervisor' => ['icon' => 'fa-user-check', 'gradient' => 'linear-gradient(135deg, #0f766e, #14b8a6)', 'badge' => 'إشراف تربوي', 'border' => '#14b8a6', 'desc' => 'متابعة الأداء الأكاديمي والإشراف على المعلمين والتقارير'],
    'specialist' => ['icon' => 'fa-user-graduate', 'gradient' => 'linear-gradient(135deg, #5b21b6, #8b5cf6)', 'badge' => 'توجيه وإرشاد', 'border' => '#8b5cf6', 'desc' => 'متابعة الحالات الطلابية والتوجيه والإرشاد الطلابي'],
    'doctor' => ['icon' => 'fa-user-doctor', 'gradient' => 'linear-gradient(135deg, #9f1239, #f43f5e)', 'badge' => 'رعاية صحية', 'border' => '#f43f5e', 'desc' => 'السجل الصحي للطلاب والعيادة المدرسية والتقارير الطبية'],
    'librarian' => ['icon' => 'fa-book-open-reader', 'gradient' => 'linear-gradient(135deg, #92400e, #f59e0b)', 'badge' => 'المكتبة الرقمية', 'border' => '#f59e0b', 'desc' => 'إدارة المكتبة واستعارة الكتب والفهرسة الرقمية'],
    'student_affairs_manager' => ['icon' => 'fa-users', 'gradient' => 'linear-gradient(135deg, #4c1d95, #7c3aed)', 'badge' => 'شؤون الطلاب', 'border' => '#7c3aed', 'desc' => 'شؤون الطلاب والتسجيل والقبول وسجلات أولياء الأمور'],
    'transport_manager' => ['icon' => 'fa-bus', 'gradient' => 'linear-gradient(135deg, #c2410c, #f97316)', 'badge' => 'النقل والمواصلات', 'border' => '#f97316', 'desc' => 'إدارة الحافلات وخطوط السير ومتابعة النقل المدرسي'],
    'roles_permissions_manager' => ['icon' => 'fa-user-lock', 'gradient' => 'linear-gradient(135deg, #1e293b, #475569)', 'badge' => 'إدارة الصلاحيات', 'border' => '#475569', 'desc' => 'إدارة الأدوار وصلاحيات الوصول للمستخدمين'],
];

$hour = (int)date('H');
$timeGreeting = ($hour >= 5 && $hour < 12) ? 'صباح الخير' : 'مساء الخير';

if (!function_exists('hexToRgba')) {
    function hexToRgba(string $hex, float $alpha = 0.35): string {
        $hex = ltrim($hex, '#');
        if (strlen($hex) === 3) {
            $hex = $hex[0] . $hex[0] . $hex[1] . $hex[1] . $hex[2] . $hex[2];
        }
        if (strlen($hex) !== 6) {
            return "rgba(59, 130, 246, {$alpha})";
        }
        $r = hexdec(substr($hex, 0, 2));
        $g = hexdec(substr($hex, 2, 2));
        $b = hexdec(substr($hex, 4, 2));
        return "rgba({$r}, {$g}, {$b}, {$alpha})";
    }
}

if (!function_exists('hexToRgbValues')) {
    function hexToRgbValues(string $hex): string {
        $hex = ltrim($hex, '#');
        if (strlen($hex) === 3) {
            $hex = $hex[0] . $hex[0] . $hex[1] . $hex[1] . $hex[2] . $hex[2];
        }
        if (strlen($hex) !== 6) {
            return "59, 130, 246";
        }
        $r = hexdec(substr($hex, 0, 2));
        $g = hexdec(substr($hex, 2, 2));
        $b = hexdec(substr($hex, 4, 2));
        return "{$r}, {$g}, {$b}";
    }
}
?>
<!doctype html>
<html lang="ar" dir="rtl">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>اختيار البوابة | EduCore Portal</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Cairo:wght@400;500;600;700;800;900&family=Plus+Jakarta+Sans:wght@500;600;700;800&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.rtl.min.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css">
    <link rel="stylesheet" href="<?php echo asset_url('assets/css/style.css'); ?>">
    <link rel="stylesheet" href="<?php echo asset_url('assets/css/premium-dashboard.css'); ?>">
    <link rel="stylesheet" href="<?php echo asset_url('assets/css/buttons.css'); ?>">
    <style>
        :root {
            --portal-bg: #090d16;
            --portal-card-bg: rgba(255, 255, 255, 0.95);
            --portal-card-border: rgba(255, 255, 255, 0.85);
            --portal-text-main: #0f172a;
            --portal-text-muted: #64748b;
            --input-bg: #f8fafc;
            --role-card-bg: #ffffff;
            --role-card-border: #e2e8f0;
            --role-pill-bg: #f1f5f9;
            --role-pill-text: #475569;
            --key-badge-bg: #ffffff;
            --key-badge-border: #cbd5e1;
            --key-badge-text: #334155;
            --header-badge-bg: #f1f5f9;
        }

        body.dark-mode {
            --portal-bg: #030712;
            --portal-card-bg: rgba(15, 23, 42, 0.88);
            --portal-card-border: rgba(255, 255, 255, 0.12);
            --portal-text-main: #f8fafc;
            --portal-text-muted: #94a3b8;
            --input-bg: rgba(15, 23, 42, 0.7);
            --role-card-bg: rgba(30, 41, 59, 0.65);
            --role-card-border: rgba(255, 255, 255, 0.1);
            --role-pill-bg: rgba(15, 23, 42, 0.8);
            --role-pill-text: #94a3b8;
            --key-badge-bg: rgba(15, 23, 42, 0.9);
            --key-badge-border: rgba(255, 255, 255, 0.15);
            --key-badge-text: #cbd5e1;
            --header-badge-bg: rgba(30, 41, 59, 0.6);
        }

        body.select-role-body {
            font-family: 'Cairo', 'Plus Jakarta Sans', system-ui, -apple-system, sans-serif;
            background-color: var(--portal-bg);
            background-image: 
                radial-gradient(at 5% 5%, rgba(59, 130, 246, 0.28) 0px, transparent 50%),
                radial-gradient(at 95% 10%, rgba(147, 51, 234, 0.22) 0px, transparent 50%),
                radial-gradient(at 90% 90%, rgba(16, 185, 129, 0.2) 0px, transparent 50%),
                radial-gradient(at 10% 95%, rgba(236, 72, 153, 0.2) 0px, transparent 50%);
            background-attachment: fixed;
            min-height: 100vh;
            display: flex;
            align-items: center;
            justify-content: center;
            position: relative;
            overflow-x: hidden;
            transition: background-color 0.4s ease;
        }

        .portal-bg-blobs {
            position: absolute;
            top: 0; left: 0; right: 0; bottom: 0;
            overflow: hidden;
            pointer-events: none;
            z-index: 0;
        }

        .portal-blob {
            position: absolute;
            border-radius: 50%;
            filter: blur(100px);
            opacity: 0.45;
            animation: blobFloat 20s ease-in-out infinite alternate;
        }

        .portal-blob-1 {
            width: 450px; height: 450px;
            background: radial-gradient(circle, #3b82f6, #1d4ed8);
            top: -120px; right: -100px;
        }

        .portal-blob-2 {
            width: 400px; height: 400px;
            background: radial-gradient(circle, #8b5cf6, #6d28d9);
            bottom: -100px; left: -80px;
            animation-delay: -10s;
        }

        .portal-blob-3 {
            width: 300px; height: 300px;
            background: radial-gradient(circle, #10b981, #047857);
            top: 40%; left: 15%;
            animation-delay: -5s;
        }

        @keyframes blobFloat {
            0% { transform: translate(0, 0) scale(1); }
            50% { transform: translate(50px, -40px) scale(1.12); }
            100% { transform: translate(-40px, 50px) scale(0.92); }
        }

        .portal-grid-pattern {
            position: absolute;
            top: 0; left: 0; right: 0; bottom: 0;
            background-image: 
                linear-gradient(rgba(255, 255, 255, 0.04) 1px, transparent 1px),
                linear-gradient(90deg, rgba(255, 255, 255, 0.04) 1px, transparent 1px);
            background-size: 40px 40px;
            pointer-events: none;
            z-index: 0;
        }

        .role-wrapper {
            position: relative;
            z-index: 1;
            width: 100%;
        }

        .role-main-card {
            background: var(--portal-card-bg);
            backdrop-filter: blur(32px);
            -webkit-backdrop-filter: blur(32px);
            border: 1px solid var(--portal-card-border);
            border-radius: 2.25rem;
            box-shadow: 0 30px 80px -20px rgba(0, 0, 0, 0.45), 0 0 0 1px rgba(255, 255, 255, 0.1);
            animation: fadeInScale 0.6s cubic-bezier(0.16, 1, 0.3, 1) forwards;
            position: relative;
            overflow: hidden;
        }

        .role-main-card::before {
            content: '';
            position: absolute;
            top: 0; left: 0; right: 0;
            height: 4px;
            background: linear-gradient(90deg, #3b82f6, #8b5cf6, #10b981, #f59e0b, #ec4899, #3b82f6);
            background-size: 300% 100%;
            animation: gradientBorder 10s linear infinite;
        }

        @keyframes gradientBorder {
            0% { background-position: 0% 0%; }
            100% { background-position: 300% 0%; }
        }

        @keyframes fadeInScale {
            from { opacity: 0; transform: scale(0.95) translateY(24px); }
            to { opacity: 1; transform: scale(1) translateY(0); }
        }

        .portal-logo-container {
            display: inline-block;
            margin-bottom: 1.25rem;
            transition: transform 0.4s cubic-bezier(0.34, 1.56, 0.64, 1);
        }

        .portal-logo-container:hover {
            transform: scale(1.08) translateY(-2px);
        }

        .portal-logo-img {
            max-height: 95px;
            width: auto;
            object-fit: contain;
            filter: drop-shadow(0 10px 22px rgba(0, 0, 0, 0.12));
            transition: filter 0.3s ease;
        }

        body.dark-mode .portal-logo-img {
            filter: drop-shadow(0 10px 26px rgba(255, 255, 255, 0.2));
        }

        .header-info-pill {
            font-size: 0.875rem;
            font-weight: 700;
            padding: 0.5rem 1.1rem;
            border-radius: 50rem;
            display: inline-flex;
            align-items: center;
            gap: 0.5rem;
            backdrop-filter: blur(12px);
            transition: all 0.3s cubic-bezier(0.34, 1.56, 0.64, 1);
            box-shadow: 0 4px 12px rgba(0, 0, 0, 0.03);
        }

        .header-info-pill-primary {
            background: rgba(59, 130, 246, 0.1);
            color: #2563eb;
            border: 1px solid rgba(59, 130, 246, 0.25);
        }

        body.dark-mode .header-info-pill-primary {
            background: rgba(59, 130, 246, 0.18);
            color: #60a5fa;
            border-color: rgba(96, 165, 250, 0.3);
        }

        .header-info-pill-secondary {
            background: var(--input-bg);
            color: var(--portal-text-muted);
            border: 1px solid var(--role-card-border);
        }

        .portal-status-pill {
            background: var(--input-bg);
            border: 1px solid var(--role-card-border);
            color: var(--portal-text-muted);
            border-radius: 50rem;
            padding: 0.35rem 1rem;
            font-size: 0.85rem;
            font-weight: 600;
            display: inline-flex;
            align-items: center;
            gap: 0.5rem;
            box-shadow: 0 2px 8px rgba(0, 0, 0, 0.03);
        }

        .role-card-btn {
            background: var(--role-card-bg);
            border: 1.5px solid var(--role-card-border) !important;
            border-radius: 1.75rem !important;
            transition: all 0.35s cubic-bezier(0.34, 1.56, 0.64, 1);
            position: relative;
            overflow: hidden;
            cursor: pointer;
            animation: cardSlideUp 0.5s ease-out backwards;
            backdrop-filter: blur(16px);
            -webkit-backdrop-filter: blur(16px);
        }

        .role-card-btn::after {
            content: '';
            position: absolute;
            inset: 0;
            border-radius: 1.75rem;
            padding: 2px;
            background: linear-gradient(135deg, var(--role-glow-color, #3b82f6), transparent 70%);
            -webkit-mask: linear-gradient(#fff 0 0) content-box, linear-gradient(#fff 0 0);
            -webkit-mask-composite: xor;
            mask-composite: exclude;
            opacity: 0;
            transition: opacity 0.35s ease;
            pointer-events: none;
        }

        @keyframes cardSlideUp {
            from { opacity: 0; transform: translateY(28px); }
            to { opacity: 1; transform: translateY(0); }
        }

        .role-card-btn:hover {
            transform: translateY(-8px) scale(1.01);
            box-shadow: 0 22px 42px -12px var(--role-glow-shadow, rgba(59, 130, 246, 0.3)) !important;
            border-color: transparent !important;
        }

        .role-card-btn:hover::after {
            opacity: 1;
        }

        .role-icon-box {
            width: 62px;
            height: 62px;
            border-radius: 1.35rem;
            box-shadow: 0 10px 22px -6px rgba(0, 0, 0, 0.28);
            transition: transform 0.4s cubic-bezier(0.34, 1.56, 0.64, 1);
        }

        .role-card-btn:hover .role-icon-box {
            transform: scale(1.15) rotate(6deg);
        }

        .role-tag-badge {
            font-size: 0.775rem;
            font-weight: 700;
            padding: 0.35rem 0.85rem;
            border-radius: 50rem;
            display: inline-flex;
            align-items: center;
            gap: 0.45rem;
            background: rgba(var(--role-rgb, 59, 130, 246), 0.12);
            color: var(--role-glow-color, #3b82f6);
            border: 1px solid rgba(var(--role-rgb, 59, 130, 246), 0.28);
            backdrop-filter: blur(8px);
            transition: all 0.3s cubic-bezier(0.34, 1.56, 0.64, 1);
            letter-spacing: -0.01em;
        }

        body.dark-mode .role-tag-badge {
            background: rgba(var(--role-rgb, 59, 130, 246), 0.18);
            border-color: rgba(var(--role-rgb, 59, 130, 246), 0.4);
        }

        .role-tag-dot {
            width: 6px;
            height: 6px;
            border-radius: 50%;
            background-color: var(--role-glow-color, #3b82f6);
            box-shadow: 0 0 8px var(--role-glow-color, #3b82f6);
            flex-shrink: 0;
            transition: all 0.3s ease;
        }

        .role-card-btn:hover .role-tag-badge {
            background: var(--role-glow-color, #3b82f6);
            color: #ffffff;
            border-color: var(--role-glow-color, #3b82f6);
            box-shadow: 0 4px 14px var(--role-glow-shadow, rgba(59, 130, 246, 0.35));
            transform: scale(1.03);
        }

        .role-card-btn:hover .role-tag-dot {
            background-color: #ffffff;
            box-shadow: 0 0 8px #ffffff;
        }

        .role-action-pill {
            background: var(--role-pill-bg);
            color: var(--role-pill-text);
            font-size: 0.85rem;
            font-weight: 700;
            padding: 0.45rem 0.95rem;
            border-radius: 50rem;
            transition: all 0.3s ease;
            display: inline-flex;
            align-items: center;
            gap: 0.4rem;
        }

        .role-action-pill i {
            transition: transform 0.3s cubic-bezier(0.34, 1.56, 0.64, 1);
        }

        .role-card-btn:hover .role-action-pill {
            background: var(--role-glow-color, #2563eb);
            color: #ffffff;
            box-shadow: 0 6px 18px var(--role-glow-shadow, rgba(37, 99, 235, 0.4));
        }

        .role-card-btn:hover .role-action-pill i {
            transform: translateX(-5px);
        }

        .shortcut-key-badge {
            min-width: 28px;
            height: 28px;
            padding: 0 6px;
            border-radius: 8px;
            background: var(--key-badge-bg);
            border: 1px solid var(--key-badge-border);
            color: var(--key-badge-text);
            font-size: 0.75rem;
            font-weight: 800;
            display: flex;
            align-items: center;
            justify-content: center;
            font-family: 'Plus Jakarta Sans', monospace;
            transition: all 0.3s ease;
            box-shadow: 0 2px 4px rgba(0, 0, 0, 0.05), inset 0 -2px 0 rgba(0, 0, 0, 0.12);
        }

        .role-card-btn:hover .shortcut-key-badge {
            background: var(--role-glow-color, #3b82f6);
            color: #ffffff;
            border-color: var(--role-glow-color, #3b82f6);
            box-shadow: 0 4px 12px var(--role-glow-shadow, rgba(59, 130, 246, 0.4));
        }

        .status-dot {
            width: 8px;
            height: 8px;
            background-color: #10b981;
            border-radius: 50%;
            display: inline-block;
            box-shadow: 0 0 0 3px rgba(16, 185, 129, 0.25);
            animation: pulseDot 2s infinite;
        }

        @keyframes pulseDot {
            0% { box-shadow: 0 0 0 0 rgba(16, 185, 129, 0.5); }
            70% { box-shadow: 0 0 0 8px rgba(16, 185, 129, 0); }
            100% { box-shadow: 0 0 0 0 rgba(16, 185, 129, 0); }
        }

        .theme-toggle-btn {
            position: absolute;
            top: 1.75rem;
            left: 1.75rem;
            width: 46px;
            height: 46px;
            border-radius: 50%;
            background: rgba(255, 255, 255, 0.88);
            border: 1px solid rgba(226, 232, 240, 0.8);
            display: flex;
            align-items: center;
            justify-content: center;
            color: #475569;
            cursor: pointer;
            transition: all 0.35s cubic-bezier(0.34, 1.56, 0.64, 1);
            z-index: 10;
            box-shadow: 0 6px 16px rgba(0, 0, 0, 0.08);
        }

        body.dark-mode .theme-toggle-btn {
            background: rgba(30, 41, 59, 0.88);
            border-color: rgba(255, 255, 255, 0.15);
            color: #f8fafc;
        }

        .theme-toggle-btn:hover {
            transform: scale(1.12) rotate(20deg);
        }

        .portal-header-section {
            margin-bottom: 1.25rem;
            padding-bottom: 0;
        }

        #rolesGridContainer {
            margin-top: 0.25rem;
        }

        .greeting-title {
            color: var(--portal-text-main);
        }
    </style>
</head>
<body class="select-role-body py-4 py-md-5">
<div class="portal-bg-blobs">
    <div class="portal-blob portal-blob-1"></div>
    <div class="portal-blob portal-blob-2"></div>
    <div class="portal-blob portal-blob-3"></div>
</div>
<div class="portal-grid-pattern"></div>
<main class="container role-wrapper">
    <div class="row justify-content-center">
        <div class="col-12 col-lg-10 col-xl-8">
            <div class="role-main-card p-4 p-md-5">
                <!-- Theme Toggle Button -->
                <button type="button" id="themeToggleBtn" class="theme-toggle-btn" title="تبديل المظهر (Dark / Light)">
                    <i class="fas fa-moon" id="themeIcon"></i>
                </button>

                <!-- Header -->
                <div class="text-center portal-header-section">
                    <div class="portal-logo-container">
                        <img src="<?php echo asset_url(get_school_logo('')); ?>" alt="شعار المدرسة" class="portal-logo-img">
                    </div>
                    <div>
                        <div class="portal-status-pill mb-3">
                            <span class="status-dot"></span>
                            <span><?php echo $timeGreeting; ?> • البوابة الآمنة الموحدة</span>
                        </div>
                    </div>
                    <h1 class="fs-2 mt-3 pt-1 mb-2 fw-black greeting-title" id="welcomeHeading">
                        مرحباً بك، <?php echo htmlspecialchars((string)($_SESSION['name'] ?? 'مستخدم EduCore'), ENT_QUOTES, 'UTF-8'); ?> 👋
                    </h1>
                    <p class="text-secondary mb-3 fs-6">اختر البوابة المطلوبة لبدء تنفيذ مهامك وسجل الصلاحيات</p>
                    
                    <?php if ($roles !== []): ?>
                        <div class="d-flex align-items-center justify-content-center gap-3 flex-wrap pt-2">
                            <span class="header-info-pill header-info-pill-primary">
                                <i class="fas fa-shield-halved fs-6"></i>
                                <span>لديك <?php echo count($roles); ?> أدوار مصرح بها</span>
                            </span>
                            <span class="header-info-pill header-info-pill-secondary d-none d-sm-inline-flex">
                                <i class="far fa-keyboard text-primary fs-6"></i>
                                <span>اضغط الأرقام [1 - <?php echo count($roles); ?>] للاختيار السريع</span>
                            </span>
                        </div>
                    <?php endif; ?>
                </div>

                <!-- Error Message -->
                <?php if ($errorMessage !== null): ?>
                    <div class="alert alert-danger d-flex align-items-center rounded-4 mb-4 shadow-sm" role="alert">
                        <i class="fas fa-circle-exclamation me-3 fs-4"></i>
                        <div><?php echo htmlspecialchars($errorMessage, ENT_QUOTES, 'UTF-8'); ?></div>
                    </div>
                <?php endif; ?>

                <!-- Role Selection Cards -->
                <?php if ($roles === []): ?>
                    <div class="alert alert-warning text-center rounded-4 p-4 shadow-sm" role="alert">
                        <i class="fas fa-shield-cat me-2 fs-3 d-block mb-2 text-warning"></i>
                        <h5 class="fw-bold text-dark">لا توجد بوابة نشطة متاحة لهذا الحساب</h5>
                        <p class="small text-muted mb-0">يرجى التواصل مع مسؤول النظام لتأكيد تعيين الصلاحيات الخاصة بك.</p>
                    </div>
                <?php else: ?>
                    <div class="row row-cols-1 row-cols-md-2 g-3 g-md-4 mb-4" id="rolesGridContainer">
                        <?php 
                        $delayIndex = 0;
                        foreach ($roles as $role): 
                            $delayIndex++;
                            $roleKey = (string)$role['role_key'];
                            $family = trim((string)($role['base_role_key'] ?? '')) ?: $roleKey;
                            $meta = $roleIcons[$family] ?? [
                                'icon' => 'fa-id-badge',
                                'gradient' => 'linear-gradient(135deg, #1d4ed8, #60a5fa)',
                                'badge' => 'دور عام',
                                'border' => '#3b82f6',
                                'desc' => 'الدخول إلى صفحات وصلاحيات هذا الدور المحدد'
                            ];
                            $cardBorder = $meta['border'] ?? '#3b82f6';
                            $cardGlowRgba = hexToRgba($cardBorder, 0.35);
                            $cardRgb = hexToRgbValues($cardBorder);
                        ?>
                            <div class="col role-card-col" data-role-name="<?php echo htmlspecialchars((string)$role['role_name'], ENT_QUOTES, 'UTF-8'); ?>">
                                <form method="post" class="h-100 role-submit-form">
                                    <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars((string)$_SESSION['csrf_token'], ENT_QUOTES, 'UTF-8'); ?>">
                                    <input type="hidden" name="role_key" value="<?php echo htmlspecialchars($roleKey, ENT_QUOTES, 'UTF-8'); ?>">
                                    <button type="submit" class="role-card-btn w-100 h-100 p-4 text-start" style="--role-glow-color: <?php echo $cardBorder; ?>; --role-glow-shadow: <?php echo $cardGlowRgba; ?>; --role-rgb: <?php echo $cardRgb; ?>; animation-delay: <?php echo $delayIndex * 0.08; ?>s;" data-shortcut-key="<?php echo $delayIndex; ?>">
                                        <div class="d-flex align-items-start gap-3">
                                            <div class="role-icon-box flex-shrink-0 d-flex align-items-center justify-content-center text-white" style="background: <?php echo $meta['gradient']; ?>;">
                                                <i class="fas <?php echo $meta['icon']; ?> fs-3"></i>
                                            </div>
                                            <div class="flex-grow-1 min-w-0">
                                                <div class="d-flex align-items-center justify-content-between mb-1">
                                                    <span class="fw-extrabold fs-5 greeting-title role-card-title text-truncate">
                                                        <?php echo htmlspecialchars((string)$role['role_name'], ENT_QUOTES, 'UTF-8'); ?>
                                                    </span>
                                                    <span class="shortcut-key-badge" title="اختصار المفتاح <?php echo $delayIndex; ?>"><?php echo $delayIndex; ?></span>
                                                </div>
                                                <div class="small text-secondary lh-sm text-wrap mb-3">
                                                    <?php echo htmlspecialchars($meta['desc'], ENT_QUOTES, 'UTF-8'); ?>
                                                </div>
                                                <div class="d-flex align-items-center justify-content-between pt-1">
                                                    <span class="role-tag-badge">
                                                        <span class="role-tag-dot"></span>
                                                        <span><?php echo htmlspecialchars($meta['badge'], ENT_QUOTES, 'UTF-8'); ?></span>
                                                    </span>
                                                    <span class="role-action-pill">
                                                        <span>دخول البوابة</span>
                                                        <i class="fas fa-arrow-left"></i>
                                                    </span>
                                                </div>
                                            </div>
                                        </div>
                                    </button>
                                </form>
                            </div>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>

                <!-- Footer -->
                <div class="border-top pt-4 d-flex flex-column flex-sm-row align-items-center justify-content-between gap-3">
                    <span class="text-secondary small d-flex align-items-center gap-2">
                        <i class="fas fa-shield-halved text-success fs-6"></i>
                        <span>حماية وتشفير EduCore SSL • Enterprise Grade TLS 1.3</span>
                    </span>
                    <a href="logout.php" class="btn btn-secondary px-4 py-2 shadow-sm">
                        <i class="fas fa-right-from-bracket me-2"></i>تسجيل الخروج
                    </a>
                </div>
            </div>
        </div>
    </div>
</main>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
<script>
document.addEventListener('DOMContentLoaded', () => {
    // 1. Theme Switcher Logic
    const themeBtn = document.getElementById('themeToggleBtn');
    const themeIcon = document.getElementById('themeIcon');
    const savedTheme = localStorage.getItem('educore_portal_theme') || 'light';
    
    if (savedTheme === 'dark') {
        document.body.classList.add('dark-mode');
        if (themeIcon) themeIcon.className = 'fas fa-sun';
    }

    if (themeBtn) {
        themeBtn.addEventListener('click', () => {
            document.body.classList.toggle('dark-mode');
            const isDark = document.body.classList.contains('dark-mode');
            localStorage.setItem('educore_portal_theme', isDark ? 'dark' : 'light');
            if (themeIcon) themeIcon.className = isDark ? 'fas fa-sun' : 'fas fa-moon';
        });
    }

    // 2. Keyboard Shortcuts (Press 1..9 to submit role)
    document.addEventListener('keydown', (e) => {
        if (['INPUT', 'TEXTAREA'].includes(document.activeElement.tagName)) return;
        const key = e.key;
        if (key >= '1' && key <= '9') {
            const targetBtn = document.querySelector(`button[data-shortcut-key="${key}"]`);
            if (targetBtn) {
                targetBtn.classList.add('active');
                targetBtn.focus();
                targetBtn.click();
            }
        }
    });
});
</script>
</body>
</html>
