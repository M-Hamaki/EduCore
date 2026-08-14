<?php
// تحميل إعدادات الجلسة الموحدة
require_once __DIR__ . '/session_config.php';
?>
<!DOCTYPE html>
<html lang="ar" dir="rtl">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo isset($page_title) ? $page_title . ' - ' : ''; ?>نظام تقييم الطلاب</title>
    <meta name="csrf-token" content="<?php echo htmlspecialchars($_SESSION['csrf_token'], ENT_QUOTES, 'UTF-8'); ?>">
    
    <!-- Bootstrap RTL CSS -->
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.rtl.min.css">
    
    <!-- Font Awesome -->
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    
    <!-- Google Font: Tajawal -->
    <link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=Tajawal:wght@400;500;700&display=swap">
    
    <!-- DataTables CSS -->
    <link rel="stylesheet" href="https://cdn.datatables.net/1.13.7/css/dataTables.bootstrap5.min.css">
    
    <?php 
    // Asset helper for cache-busting
    if (!function_exists('asset_url')) { require_once __DIR__ . '/template_helper.php'; }
    ?>
    <script src="<?php echo asset_url('../assets/js/datatable-state.js'); ?>"></script>
    <!-- Custom CSS -->
    <link rel="stylesheet" href="<?php echo asset_url('../assets/css/style.css'); ?>">
    <!-- Premium Dashboard Design System -->
    <link rel="stylesheet" href="<?php echo asset_url('../assets/css/premium-dashboard.css'); ?>">
    <!-- Unified Button Design System -->
    <link rel="stylesheet" href="<?php echo asset_url('../assets/css/buttons.css'); ?>">
    <!-- jQuery (Loaded in head for inline scripts to work) -->
    <script src="https://code.jquery.com/jquery-3.7.1.min.js"></script>
    <style>
    /* إصلاح مشكلة اختفاء القائمة المنسدلة بسرعة */
    .navbar .dropdown-menu {
        margin-top: 0 !important;
        border-top-left-radius: 0;
        border-top-right-radius: 0;
    }

    /* منع إغلاق القائمة عند تحريك الماوس داخلها */
    .navbar .dropdown:hover .dropdown-menu {
        display: block;
        animation: fadeIn 0.2s;
    }

    @keyframes fadeIn {
        from { opacity: 0; transform: translateY(-10px); }
        to { opacity: 1; transform: translateY(0); }
    }

    /* تحسين مساحة النقر على عناصر القائمة */
    .dropdown-menu .dropdown-item {
        padding: 0.75rem 1.5rem;
        transition: all 0.2s;
    }

    .dropdown-menu .dropdown-item:hover {
        background-color: #f8f9fa;
        padding-right: 1.75rem;
    }

    /* إضافة مساحة آمنة بين الزر والقائمة */
    .navbar .dropdown::before {
        content: '';
        position: absolute;
        top: 100%;
        left: 0;
        right: 0;
        height: 5px;
    }
    </style>
</head>
<body class="teacher-page">
    <div id="particles-js"></div>
    
    <!-- Horizontal Navigation -->
    <nav class="navbar navbar-admin navbar-expand-lg fixed-top">
        <div class="container-fluid">
            <a class="navbar-brand" href="../teacher/index.php">
                <img src="<?php echo asset_url(get_school_logo('../')); ?>" alt="شعار المدرسة" class="logo-img" loading="eager">
                <span class="full-title">نظام الإدارة المدرسية</span>
            </a>
            
            <!-- Mobile Toggle Button -->
            <button class="navbar-toggler" type="button" data-bs-toggle="collapse" data-bs-target="#teacherNavbar" 
                    aria-controls="teacherNavbar" aria-expanded="false" aria-label="القائمة">
                <i class="fas fa-bars text-white"></i>
            </button>
            
            <!-- Navbar Menu Items -->
            <div class="collapse navbar-collapse" id="teacherNavbar">
                <ul class="navbar-nav me-auto mb-2 mb-lg-0">
                    <li class="nav-item">
                        <a class="nav-link <?php echo basename($_SERVER['PHP_SELF']) == 'index.php' ? 'active' : ''; ?>" href="index.php">
                            <i class="fas fa-tachometer-alt"></i>
                            <span>لوحة التحكم</span>
                        </a>
                    </li>
                    <li class="nav-item">
                        <a class="nav-link <?php echo basename($_SERVER['PHP_SELF']) == 'evaluations.php' ? 'active' : ''; ?>" href="evaluations.php">
                            <i class="fas fa-star"></i>
                            <span>التقييمات</span>
                        </a>
                    </li>
                    <li class="nav-item">
                        <a class="nav-link <?php echo basename($_SERVER['PHP_SELF']) == 'assessment_marks.php' ? 'active' : ''; ?>" href="assessment_marks.php">
                            <i class="fas fa-pen-to-square"></i>
                            <span>رصد الدرجات</span>
                        </a>
                    </li>
                    <li class="nav-item">
                        <a class="nav-link <?php echo basename($_SERVER['PHP_SELF']) == 'assessment_review.php' ? 'active' : ''; ?>" href="assessment_review.php">
                            <i class="fas fa-clipboard-check"></i>
                            <span>مراجعة الدرجات</span>
                        </a>
                    </li>
                    <li class="nav-item">
                        <a class="nav-link <?php echo basename($_SERVER['PHP_SELF']) == 'assessment_reports.php' ? 'active' : ''; ?>" href="assessment_reports.php">
                            <i class="fas fa-file-export"></i>
                            <span>نشر التقارير</span>
                        </a>
                    </li>
                    <li class="nav-item">
                        <a class="nav-link <?php echo basename($_SERVER['PHP_SELF']) == 'activities.php' ? 'active' : ''; ?>" href="activities.php">
                            <i class="fas fa-gamepad"></i>
                            <span>الأنشطة التفاعلية</span>
                        </a>
                    </li>
                    <li class="nav-item dropdown">
                        <a class="nav-link dropdown-toggle <?php echo in_array(basename($_SERVER['PHP_SELF']), ['training.php', 'training_course.php', 'training_my.php']) ? 'active' : ''; ?>" 
                           href="#" id="trainingDropdown" role="button" data-bs-toggle="dropdown" aria-expanded="false">
                            <i class="fas fa-graduation-cap"></i>
                            <span>التدريب</span>
                        </a>
                        <ul class="dropdown-menu" aria-labelledby="trainingDropdown">
                            <li>
                                <a class="dropdown-item <?php echo basename($_SERVER['PHP_SELF']) == 'training.php' ? 'active' : ''; ?>" href="training.php">
                                    <i class="fas fa-th-large me-2 text-primary"></i> تصفح الدورات
                                </a>
                            </li>
                            <li>
                                <a class="dropdown-item <?php echo basename($_SERVER['PHP_SELF']) == 'training_my.php' ? 'active' : ''; ?>" href="training_my.php">
                                    <i class="fas fa-book-reader me-2 text-success"></i> تدريباتي
                                </a>
                            </li>
                            <li><hr class="dropdown-divider"></li>
                            <li>
                                <a class="dropdown-item" href="training_my.php?view=certificates">
                                    <i class="fas fa-certificate me-2 text-warning"></i> شهاداتي
                                </a>
                            </li>
                        </ul>
                    </li>
                </ul>
                
                <!-- Right-aligned elements: user dropdown -->
                <ul class="navbar-nav ms-auto">
                    <li class="nav-item dropdown">
                        <a class="nav-link dropdown-toggle d-flex align-items-center" href="#" id="userDropdown" role="button" data-bs-toggle="dropdown" aria-expanded="false">
                            <i class="fas fa-user-circle me-2" style="font-size: 1.2rem;"></i>
                            <span class="d-none d-md-inline"><?php echo htmlspecialchars($_SESSION['name']); ?></span>
                        </a>
                        <ul class="dropdown-menu dropdown-menu-end" aria-labelledby="userDropdown">
                            <?php if (count((array)($_SESSION['available_roles'] ?? [])) > 1): ?>
                            <li>
                                <a class="dropdown-item d-flex align-items-center" href="../select_role.php">
                                    <i class="fas fa-repeat me-2 text-success"></i> تبديل الدور النشط
                                </a>
                            </li>
                            <li><hr class="dropdown-divider"></li>
                            <?php endif; ?>
                            <?php if (Utilities::isSupervisor()): ?>
                            <li>
                                <a class="dropdown-item d-flex align-items-center" href="../supervisor/select_mode.php">
                                    <i class="fas fa-exchange-alt me-2 text-primary"></i> تبديل إلى وضع المشرف
                                </a>
                            </li>
                            <li><hr class="dropdown-divider"></li>
                            <?php endif; ?>
                            <li>
                                <a class="dropdown-item d-flex align-items-center" href="#" id="pushNotifBtn" role="button">
                                    <i class="fas fa-bell me-2"></i> تفعيل الإشعارات
                                </a>
                            </li>
                            <li><hr class="dropdown-divider"></li>
                            <li>
                                <a class="dropdown-item d-flex align-items-center" href="../logout.php">
                                    <i class="fas fa-sign-out-alt me-2"></i> تسجيل الخروج
                                </a>
                            </li>
                        </ul>
                    </li>
                </ul>
            </div>
        </div>
    </nav>
    <div id="navbarOverlay"></div>

    <!-- Main Container -->
    <div class="container-fluid">
        <div class="row">
            <!-- Main Content (Now full width) -->
            <main class="col-12 px-md-2">
                <?php if (!isset($custom_page_title) || !$custom_page_title): ?>
                    <div class="d-flex justify-content-between flex-wrap flex-md-nowrap align-items-center pb-2 mb-3">
                        <h1 class="h2"><?php echo isset($page_title) ? $page_title : 'لوحة التحكم'; ?></h1>
                    </div>
                <?php endif; ?>
