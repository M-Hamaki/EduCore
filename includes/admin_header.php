<?php
// تحميل إعدادات الجلسة الموحدة
require_once __DIR__ . '/session_config.php';

// Include utilities
if (!class_exists('Utilities')) {
    require_once '../classes/utilities.php';
}
// Asset helper for cache-busting
if (!function_exists('asset_url')) {
    require_once __DIR__ . '/template_helper.php';
}

Utilities::validateSession('admin');
$__adminAllowedPages = Utilities::getAllowedAdminPagesForRole($_SESSION['active_role'] ?? $_SESSION['role'] ?? '');
$__adminDashboardUrl = Utilities::getDashboardUrl((string)($_SESSION['active_role'] ?? $_SESSION['role'] ?? 'admin'));
$__adminHomeHref = str_starts_with($__adminDashboardUrl, 'admin/')
    ? basename($__adminDashboardUrl)
    : 'index.php';

// Apply the current administrator's validated UI preferences on every admin page.
$__uiPreferenceRules = [
    'app_theme' => ['light', 'dark'],
    'layout_density' => ['cozy', 'compact'],
    'micro_interactions' => ['active', 'none'],
    'font_size' => ['100', '110', '120'],
    'button_style' => ['solid', 'glass'],
    'counter_animation' => ['enabled', 'disabled'],
    'table_header_style' => ['transparent', 'accent', 'dark'],
    'status_badge_style' => ['subtle', 'solid', 'outline'],
    'page_title_style' => ['simple', 'gradient'],
    'sidebar_theme' => ['light', 'dark'],
];
$__uiPreferenceDefaults = [
    'app_theme' => 'light',
    'layout_density' => 'cozy',
    'micro_interactions' => 'active',
    'font_size' => '100',
    'button_style' => 'solid',
    'counter_animation' => 'enabled',
    'table_header_style' => 'transparent',
    'status_badge_style' => 'subtle',
    'page_title_style' => 'simple',
    'sidebar_theme' => 'light',
];
$__uiPreferences = [];
foreach ($__uiPreferenceRules as $__preferenceKey => $__allowedValues) {
    $__preferenceValue = (string) Utilities::getUserPreference(
        $__preferenceKey,
        $__uiPreferenceDefaults[$__preferenceKey]
    );
    $__uiPreferences[$__preferenceKey] = in_array($__preferenceValue, $__allowedValues, true)
        ? $__preferenceValue
        : $__uiPreferenceDefaults[$__preferenceKey];
}
$__accentColor = (string) Utilities::getUserPreference('accent_color', '#0078d4');
if (!in_array($__accentColor, ['#0078d4', '#10b981', '#8b5cf6', '#ef4444', '#f97316'], true)) {
    $__accentColor = '#0078d4';
}
$__adminBodyClasses = [
    $__uiPreferences['app_theme'] === 'dark' ? 'app-dark-mode' : 'app-light-mode',
    $__uiPreferences['layout_density'] === 'compact' ? 'compact-density' : 'cozy-density',
    $__uiPreferences['micro_interactions'] === 'none' ? 'micro-interactions-none' : 'active-interactions',
    'font-size-' . $__uiPreferences['font_size'],
    'button-style-' . $__uiPreferences['button_style'],
    'counter-animation-' . $__uiPreferences['counter_animation'],
    'table-header-' . $__uiPreferences['table_header_style'],
    'status-badge-' . $__uiPreferences['status_badge_style'],
    'page-title-' . $__uiPreferences['page_title_style'],
    'sidebar-theme-' . $__uiPreferences['sidebar_theme'],
];

// Ensure $action is defined for all admin pages
if (!isset($action)) {
    $action = $_GET['action'] ?? ($_POST['action'] ?? '');
}
if (!isset($page_action)) {
    $page_action = $action;
}

// جلب إعدادات المدرسة من قاعدة البيانات
$_schoolSettings = [];
try {
    if (!isset($db)) {
        require_once __DIR__ . '/../config/database.php';
        $database = new Database();
        $db = $database->getConnection();
    }
    $stmtSettings = $db->query("SELECT setting_key, setting_value FROM settings WHERE setting_key IN ('school_name','school_logo','educational_administration','academic_year')");
    while ($rowS = $stmtSettings->fetch(PDO::FETCH_ASSOC)) {
        $_schoolSettings[$rowS['setting_key']] = $rowS['setting_value'];
    }
} catch (Exception $e) {
    // fallback to defaults
}
$_schoolName = !empty($_schoolSettings['school_name']) ? $_schoolSettings['school_name'] : 'نظام الإدارة المدرسية';
$_schoolLogo = get_school_logo('../');

// ====== نظام الأعوام الدراسية: معالجة تبديل العام + تجهيز المحدد ======
require_once __DIR__ . '/../classes/AcademicYear.php';
// تبديل العام المختار (تصفّح فقط، لا يغيّر العام النشط).
// الأخصائي مُقيّد بالعام النشط ويُرفض تبديله حتى لو أرسل المعامل يدوياً.
$__academicYearSwitchAllowed = !AcademicYear::roleUsesActiveYearOnly((string) ($_SESSION['role'] ?? ''));
if ($__academicYearSwitchAllowed && isset($_GET['switch_academic_year']) && is_numeric($_GET['switch_academic_year'])) {
    $switchId = (int) $_GET['switch_academic_year'];
    $check = $db->prepare("SELECT id FROM academic_years WHERE id = ? AND status = 'active' LIMIT 1");
    $check->execute([$switchId]);
    if ($check->fetchColumn()) {
        AcademicYear::setCurrent($db, $switchId);
    }
    // إزالة المعامل من الـ URL (PRG-like)
    $_SERVER['REQUEST_URI'] = preg_replace('/[?&]switch_academic_year=\d+/', '', $_SERVER['REQUEST_URI'] ?? '');
}
// العام الحالي كما يراه المستخدم
$_currentAcademicYear = AcademicYear::getCurrent($db);
$_currentAcademicYearId = $_currentAcademicYear ? (int) $_currentAcademicYear['id'] : 0;
$_allAcademicYears = AcademicYear::getAll($db, true);
$__pendingOperationsCount = 0;
$__sessionRole = (string) ($_SESSION['role'] ?? '');
$__sessionIsSpecialist = Utilities::isActingAsSpecialist();
if ($__sessionIsSpecialist
    || Utilities::roleCanAccessAdminPage($__sessionRole, 'pending_operations.php')) {
    try {
        require_once __DIR__ . '/../classes/StudentChangeRequestService.php';
        $__pendingOperationsCount = $__sessionIsSpecialist
            ? StudentChangeRequestService::pendingCount(
                $db,
                (int) ($_SESSION['user_id'] ?? 0),
                $_currentAcademicYearId
            )
            : StudentChangeRequestService::pendingCount($db);
    } catch (Throwable $pendingOperationsError) {
        $__pendingOperationsCount = 0;
    }
}

// توافق طبقة الأعوام: عند تصفّح عام غير النشط، نُزامن users.class_id مؤقتاً
// مع تسجيلات العام المختار حتى تعمل الصفحات التي تعتمد على u.class_id بشكل صحيح.
// (تتم المزامنة مرة واحدة فقط لكل عام عبر علامة في السيشن لتجنّب إعادة التنفيذ المتكرر.)
if ($_currentAcademicYearId > 0) {
    $syncFlag = 'ay_synced_' . $_currentAcademicYearId;
    if (empty($_SESSION[$syncFlag])) {
        try {
            AcademicYear::syncUsersClassForYear($db, $_currentAcademicYearId);
        } catch (Throwable $syncErr) {
            // تجاهل أخطاء المزامنة (غير حرجة لعرض الصفحة)
        }
        $_SESSION[$syncFlag] = true;
    }
}
?>
<!DOCTYPE html>
<html lang="ar" dir="rtl">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>
        <?php echo isset($page_title) && !isset($custom_page_title) ? $page_title . ' - ' . htmlspecialchars($_schoolName) : htmlspecialchars($_schoolName); ?>
    </title>
    <meta name="csrf-token" content="<?php echo htmlspecialchars($_SESSION['csrf_token'], ENT_QUOTES, 'UTF-8'); ?>">
    <script src="<?php echo asset_url('../assets/js/datatable-state.js'); ?>"></script>

    <?php
    // إضافة meta tags لمنع التخزين المؤقت
    require_once __DIR__ . '/no_cache.php';
    addNoCacheMetaTags();
    ?>

    <link rel="preconnect" href="https://cdn.jsdelivr.net">
    <link rel="preconnect" href="https://cdn.datatables.net">
    <link rel="preconnect" href="https://cdnjs.cloudflare.com">
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link rel="preconnect" href="https://code.jquery.com">

    <!-- Bootstrap RTL CSS -->
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.rtl.min.css">
    <!-- DataTables CSS -->
    <link rel="stylesheet" href="https://cdn.datatables.net/1.13.7/css/dataTables.bootstrap5.min.css">
    <!-- Font Awesome -->
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css">
    <!-- Google Font: Tajawal -->
    <link rel="stylesheet"
        href="https://fonts.googleapis.com/css2?family=Tajawal:wght@300;400;500;700;800;900&display=swap">
    <!-- Custom CSS -->
    <link rel="stylesheet" href="<?php echo asset_url('../assets/css/style.css'); ?>">
    <!-- Premium Dashboard Design System -->
    <link rel="stylesheet" href="../assets/css/premium-dashboard.css?v=1.1.9">
    <!-- Unified Button Design System (MUST load after Bootstrap + premium-dashboard) -->
    <link rel="stylesheet" href="<?php echo asset_url('../assets/css/buttons.css'); ?>">
    <?php foreach ((array) ($page_stylesheets ?? []) as $pageStylesheet): ?>
        <link rel="stylesheet" href="<?php echo htmlspecialchars((string) $pageStylesheet, ENT_QUOTES, 'UTF-8'); ?>">
    <?php endforeach; ?>
    <!-- Unified Admin UI Layer (opt-in classes; loads last) -->
    <link rel="stylesheet" href="<?php echo asset_url('../assets/css/admin-unified.css'); ?>">
    <!-- jQuery (Loaded in head for inline scripts to work) -->
    <script src="https://code.jquery.com/jquery-3.7.1.min.js"></script>
</head>
<?php
$sidebarCookieSet = isset($_COOKIE['sidebar_collapsed']);
$sidebarCollapsed = $sidebarCookieSet && $_COOKIE['sidebar_collapsed'] === '1';

$isSpecialist = Utilities::isActingAsSpecialist();
$isStudentAffairs = Utilities::isActingAsStudentAffairs();
$keepSidebarDefaultOpen = $isSpecialist || $isStudentAffairs;

if (!$sidebarCookieSet && $keepSidebarDefaultOpen) {
    $sidebarCollapsed = false;
}
?>

<body class="admin-page <?php echo $sidebarCollapsed ? 'sidebar-collapsed ' : ''; ?><?php echo htmlspecialchars(implode(' ', $__adminBodyClasses), ENT_QUOTES, 'UTF-8'); ?>"
    style="--ms-primary: <?php echo htmlspecialchars($__accentColor, ENT_QUOTES, 'UTF-8'); ?>;">
    <script>
        // التحقق التكميلي من sessionStorage للحالات التي لم يتم فيها تحديث الكوكيز بعد
        if (sessionStorage.getItem('sidebar_collapsed') === 'true' && window.innerWidth >= 992) {
            document.body.classList.add('sidebar-collapsed');
        } else if (sessionStorage.getItem('sidebar_collapsed') === 'false' && window.innerWidth >= 992) {
            document.body.classList.remove('sidebar-collapsed');
        }
    </script>

    <!-- Mobile search responsive override -->
    <style>
        @media (min-width: 992px) {
            #mobileSearchToggleBtn,
            #mobileSearchBar { display: none !important; }
        }
    </style>

    <!-- Top Header -->
    <header class="navbar navbar-light bg-light fixed-top admin-top-header border-bottom" style="flex-wrap: wrap; align-items: stretch;">
        <div class="container-fluid d-flex align-items-center justify-content-between" style="gap: 8px; flex-wrap: nowrap;">
            <!-- Right Block: Mobile Toggle + Brand Logo/Name + Academic Year icon -->
            <div class="d-flex align-items-center gap-1 gap-md-2" style="flex: 1; min-width: 0;">
                <!-- Mobile Toggle Button -->
                <button class="btn border-0 bg-transparent p-2 d-lg-none" type="button" id="sidebarToggleBtnMobile" title="فتح القائمة">
                    <i class="fas fa-bars"></i>
                </button>
                <a class="navbar-brand m-0 p-0 d-flex align-items-center flex-shrink-0" href="<?php echo htmlspecialchars($__adminHomeHref, ENT_QUOTES, 'UTF-8'); ?>">
                    <img src="<?php echo asset_url($_schoolLogo); ?>" alt="شعار المدرسة" class="logo-img me-2"
                        style="height:32px;">
                    <span class="full-title d-none d-xxl-inline fw-bold text-dark me-2"><?php echo htmlspecialchars($_schoolName); ?></span>
                </a>

                <!-- Academic Year: icon-only with tooltip, next to logo -->
                <?php
                $_isAyActive = !empty($_currentAcademicYear) && (int)$_currentAcademicYear['is_active'] === 1;
                $_ayClass = $_isAyActive ? 'ay-active' : 'ay-review';
                ?>
                <?php if (!empty($_allAcademicYears) && $__academicYearSwitchAllowed): ?>
                <div class="dropdown flex-shrink-0 d-none d-md-block">
                    <a class="btn btn-sm d-flex align-items-center justify-content-center px-2 py-1 rounded-3 <?php echo $_ayClass; ?>"
                       href="#" id="academicYearDropdown" role="button"
                       data-bs-toggle="dropdown" aria-expanded="false"
                       title="<?php echo htmlspecialchars('العام الدراسي: ' . ($_currentAcademicYear['name'] ?? '—') . ($_isAyActive ? ' (نشط)' : ' (وضع استعراض)')); ?>">
                        <i class="fas fa-calendar-days" style="font-size: 0.95rem;"></i>
                    </a>
                    <ul class="dropdown-menu shadow border-0" aria-labelledby="academicYearDropdown" style="min-width: 210px;">
                        <li><h6 class="dropdown-header text-muted small"><i class="fas fa-calendar-days me-1"></i>اختر العام الدراسي</h6></li>
                        <?php foreach ($_allAcademicYears as $_ay): ?>
                            <?php $_ayActive = ((int)$_ay['is_active'] === 1); ?>
                            <li>
                                <a class="dropdown-item py-2 d-flex justify-content-between align-items-center <?php echo ((int)$_ay['id'] === $_currentAcademicYearId) ? 'active' : ''; ?>"
                                   href="?switch_academic_year=<?php echo (int)$_ay['id']; ?>">
                                    <span>
                                        <i class="fas <?php echo $_ayActive ? 'fa-circle-check text-success' : 'fa-circle text-muted'; ?> me-2"></i>
                                        <span dir="ltr"><?php echo htmlspecialchars($_ay['name']); ?></span>
                                    </span>
                                    <?php if ($_ayActive): ?><span class="badge bg-success">نشط</span><?php endif; ?>
                                </a>
                            </li>
                        <?php endforeach; ?>
                    </ul>
                </div>
                <?php elseif (!empty($_currentAcademicYear)): ?>
                <span class="flex-shrink-0 d-none d-md-flex align-items-center justify-content-center px-2 py-1 rounded-3 <?php echo $_isAyActive ? 'ay-badge-active' : 'ay-badge-review'; ?>"
                      title="<?php echo htmlspecialchars('العام الدراسي: ' . ($_currentAcademicYear['name'] ?? '—') . ($_isAyActive ? ' (نشط)' : ' (وضع استعراض)')); ?>"
                      style="cursor: default;">
                    <i class="fas fa-calendar-days" style="font-size: 0.95rem;"></i>
                </span>
                <?php endif; ?>
            </div>

            <!-- Center Block: Search Bar (desktop only) -->
            <div class="d-none d-lg-flex align-items-center justify-content-center flex-shrink-1" style="flex: 0 1 290px; min-width: 100px; max-width: 320px;">
                <div class="input-group search-bar-ms align-items-center"
                    style="border-radius: 8px; background-color: #f3f2f1; border: 1px solid #e1dfdd; transition: border-color 0.2s; width: 100%; position: relative;">
                    <span class="input-group-text bg-transparent border-0 px-2" id="searchIcon" style="color: #605e5c;"><i
                            class="fas fa-search"></i></span>
                    <input type="search" class="form-control bg-transparent border-0 shadow-none ps-0"
                        placeholder="البحث في النظام" style="text-align: center; color: #201f1e; font-size: 0.88rem;"
                        aria-label="Search" aria-describedby="searchIcon">
                    <kbd class="d-none d-xl-inline-block text-muted bg-white border px-2 py-0 me-2 rounded shadow-2xs" style="font-size:0.68rem; font-family:inherit; font-weight:500; pointer-events:none; border-color:#d1d5db !important;">Ctrl K</kbd>
                </div>
            </div>

            <!-- Left Block: User Profile + Mobile Search Toggle -->
            <div class="d-flex align-items-center justify-content-end gap-1 gap-md-2" style="flex: 1; min-width: 0; flex-shrink: 0;">

                <!-- زر البحث للشاشات الصغيرة فقط -->
                <button class="btn btn-sm d-none d-sm-inline-block d-lg-none" id="mobileSearchToggleBtn"
                        type="button" title="بحث"
                        style="color: #475569; border: none; background: none;">
                    <i class="fas fa-search" style="font-size: 1rem;"></i>
                </button>

                <div class="dropdown">
                    <a class="text-dark text-decoration-none dropdown-toggle d-flex align-items-center" href="#"
                        id="userDropdown" role="button" data-bs-toggle="dropdown" aria-expanded="false">
                        <i class="fas fa-user-circle me-1 fs-4"></i>
                        <span class="d-none d-md-inline me-1"><?php echo htmlspecialchars($_SESSION['name']); ?></span>
                    </a>
                    <ul class="dropdown-menu dropdown-menu-end shadow border-0" aria-labelledby="userDropdown">
                        <li><a class="dropdown-item py-2" href="profile.php"><i
                                    class="fas fa-user me-2 text-primary"></i> الملف الشخصي</a></li>
                        <?php if (count((array)($_SESSION['available_roles'] ?? [])) > 1): ?>
                            <li><a class="dropdown-item py-2" href="../select_role.php"><i
                                        class="fas fa-repeat me-2 text-success"></i> تبديل الدور النشط</a></li>
                        <?php endif; ?>
                        <?php if ($__adminAllowedPages === null || in_array('activity_logs.php', $__adminAllowedPages, true)): ?>
                            <li><a class="dropdown-item py-2" href="activity_logs.php"><i class="fas fa-clipboard-list me-2"
                                        style="color: #fd7e14;"></i> سجل النشاطات</a></li>
                        <?php endif; ?>
                        <?php if ((string)($_SESSION['active_role'] ?? $_SESSION['role'] ?? '') === 'super_admin'): ?>
                            <li>
                                <hr class="dropdown-divider">
                            </li>
                            <li><a class="dropdown-item py-2" href="reset_points.php"><i
                                        class="fas fa-redo-alt me-2 text-danger"></i> إعادة ضبط النقاط</a></li>
                        <?php endif; ?>
                        <li>
                            <hr class="dropdown-divider">
                        </li>
                        <li><a class="dropdown-item py-2" href="#" id="pushNotifBtn" role="button"><i
                                    class="fas fa-bell me-2 text-info"></i> تفعيل الإشعارات</a></li>
                        <li>
                            <hr class="dropdown-divider">
                        </li>
                        <li><a class="dropdown-item py-2 text-danger" href="../logout.php"><i
                                    class="fas fa-sign-out-alt me-2"></i> تسجيل الخروج</a></li>
                    </ul>
                </div>
            </div>
        </div>
        <!-- Mobile Search Bar (slides down below navbar on icon click) -->
        <div id="mobileSearchBar" class="d-lg-none w-100" style="display: none !important; padding: 8px 12px; background: rgba(255,255,255,0.97); border-top: 1px solid #e2e8f0; backdrop-filter: blur(12px);">
            <div class="input-group search-bar-ms" style="border-radius: 8px; background-color: #f3f2f1; border: 1px solid #e1dfdd; position: relative;">
                <span class="input-group-text bg-transparent border-0 px-2" style="color: #605e5c;"><i class="fas fa-search"></i></span>
                <input type="search" id="mobileSearchInput" class="form-control bg-transparent border-0 shadow-none ps-0"
                    placeholder="البحث في النظام..." style="text-align: right; color: #201f1e; font-size: 0.9rem;"
                    aria-label="Mobile Search">
                <button class="btn btn-sm border-0 bg-transparent px-2" id="mobileSearchClose" type="button" style="color: #94a3b8;">
                    <i class="fas fa-times"></i>
                </button>
            </div>
        </div>
    </header>

    <script>
    (function() {
        var btn    = document.getElementById('mobileSearchToggleBtn');
        var bar    = document.getElementById('mobileSearchBar');
        var closeBtn = document.getElementById('mobileSearchClose');
        var inp    = document.getElementById('mobileSearchInput');
        var icon   = btn ? btn.querySelector('i') : null;

        function openBar() {
            bar.style.setProperty('display', 'block', 'important');
            if (icon) { icon.classList.remove('fa-search'); icon.classList.add('fa-times'); }
            if (inp) inp.focus();
        }
        function closeBar() {
            bar.style.setProperty('display', 'none', 'important');
            if (icon) { icon.classList.remove('fa-times'); icon.classList.add('fa-search'); }
            if (inp) inp.value = '';
        }

        if (btn && bar) {
            btn.addEventListener('click', function () {
                var isVisible = bar.style.display === 'block';
                isVisible ? closeBar() : openBar();
            });
        }
        if (closeBtn && bar) {
            closeBtn.addEventListener('click', closeBar);
        }
    })();
    </script>


    <!-- Sidebar Overlay for Mobile -->
    <div id="sidebarOverlay" class="sidebar-overlay"></div>

    <!-- Sidebar Navigation -->
    <?php if ($isStudentAffairs): ?>
    <aside class="admin-sidebar shadow specialist-flat-sidebar" id="adminSidebar">
        <div class="sidebar-menu">
            <ul class="nav flex-column mb-auto sidebar-nav" id="sidebarNavAccordion">
                <!-- Desktop Sidebar Toggle (Visible on desktop only) -->
                <li class="nav-item d-none d-lg-block toggle-nav-item">
                    <a class="nav-link" href="#" id="sidebarToggleBtn" style="cursor: pointer;">
                        <i class="fas fa-bars nav-icon"></i>
                    </a>
                </li>

                <!-- لوحة التحكم -->
                <li class="nav-item">
                    <a class="nav-link <?php echo in_array(Utilities::getCurrentPage(), ['role_dashboard.php', 'index.php'], true) ? 'active' : ''; ?>"
                        href="role_dashboard.php">
                        <i class="fas fa-tachometer-alt nav-icon text-primary"></i>
                        <span>لوحة التحكم</span>
                    </a>
                </li>

                <!-- قسم إدارة شؤون الطلاب -->
                <li class="sidebar-category-header" style="font-size: 0.72rem; font-weight: 700; color: #94a3b8; padding: 22px 15px 8px; display: flex; align-items: center; gap: 8px; pointer-events: none; text-transform: uppercase;">
                    <span style="width: 5px; height: 5px; background-color: #3b82f6; border-radius: 50%; display: inline-block;"></span>
                    <span>إدارة شؤون الطلاب</span>
                </li>

                <li class="nav-item">
                    <a class="nav-link <?php echo Utilities::getCurrentPage() == 'students.php' ? 'active' : ''; ?>" href="students.php">
                        <i class="fas fa-user-check nav-icon" style="color: #10b981;"></i>
                        <span>الطلاب المقيدين</span>
                    </a>
                </li>
                <li class="nav-item">
                    <a class="nav-link <?php echo Utilities::getCurrentPage() == 'student_operations.php' ? 'active' : ''; ?>" href="student_operations.php">
                        <i class="fas fa-clock-rotate-left nav-icon" style="color: #2563eb;"></i>
                        <span>سجل عمليات الطلاب</span>
                    </a>
                </li>
                <li class="nav-item">
                    <a class="nav-link <?php echo Utilities::getCurrentPage() == 'pending_operations.php' ? 'active' : ''; ?>" href="pending_operations.php">
                        <i class="fas fa-hourglass-half nav-icon" style="color: #f59e0b;"></i>
                        <span>العمليات المعلقة</span>
                        <span class="badge rounded-pill bg-warning text-dark ms-auto" aria-label="طلبات قيد المراجعة"><?php echo $__pendingOperationsCount; ?></span>
                    </a>
                </li>
                <li class="nav-item">
                    <a class="nav-link <?php echo Utilities::getCurrentPage() == 'new_students.php' ? 'active' : ''; ?>" href="new_students.php">
                        <i class="fas fa-user-plus nav-icon" style="color: #10b981;"></i>
                        <span>منقول إلى المدرسة</span>
                    </a>
                </li>
                <li class="nav-item">
                    <a class="nav-link <?php echo Utilities::getCurrentPage() == 'transferred_students.php' ? 'active' : ''; ?>" href="transferred_students.php">
                        <i class="fas fa-user-minus nav-icon" style="color: #f97316;"></i>
                        <span>منقول من المدرسة</span>
                    </a>
                </li>
                <li class="nav-item">
                    <a class="nav-link <?php echo Utilities::getCurrentPage() == 'graduate_students.php' ? 'active' : ''; ?>" href="graduate_students.php">
                        <i class="fas fa-user-graduate nav-icon" style="color: #8b5cf6;"></i>
                        <span>الطلاب الخريجون</span>
                    </a>
                </li>
                <li class="nav-item">
                    <a class="nav-link <?php echo Utilities::getCurrentPage() == 'student_archive.php' ? 'active' : ''; ?>" href="student_archive.php">
                        <i class="fas fa-box-archive nav-icon" style="color: #64748b;"></i>
                        <span>أرشيف الطلاب</span>
                    </a>
                </li>
                <li class="nav-item">
                    <a class="nav-link <?php echo Utilities::getCurrentPage() == 'student_data_completeness.php' ? 'active' : ''; ?>" href="student_data_completeness.php">
                        <i class="fas fa-list-check nav-icon" style="color: #8b5cf6;"></i>
                        <span>اكتمال البيانات</span>
                    </a>
                </li>
                <li class="nav-item">
                    <a class="nav-link <?php echo Utilities::getCurrentPage() == 'class_lists.php' ? 'active' : ''; ?>" href="class_lists.php">
                        <i class="fas fa-table-list nav-icon" style="color: #06b6d4;"></i>
                        <span>قوائم الفصول</span>
                    </a>
                </li>
                <li class="nav-item">
                    <a class="nav-link <?php echo Utilities::getCurrentPage() == 'siblings.php' ? 'active' : ''; ?>" href="siblings.php">
                        <i class="fas fa-people-roof nav-icon" style="color: #f43f5e;"></i>
                        <span>صلات القرابة</span>
                    </a>
                </li>
                <li class="nav-item">
                    <a class="nav-link <?php echo Utilities::getCurrentPage() == 'attendance.php' ? 'active' : ''; ?>" href="attendance.php">
                        <i class="fas fa-calendar-check nav-icon" style="color: #10b981;"></i>
                        <span>الحضور والغياب</span>
                    </a>
                </li>

                <!-- قسم التقارير والمستخرجات -->
                <li class="sidebar-category-header" style="font-size: 0.72rem; font-weight: 700; color: #94a3b8; padding: 22px 15px 8px; display: flex; align-items: center; gap: 8px; pointer-events: none; text-transform: uppercase;">
                    <span style="width: 5px; height: 5px; background-color: #a855f7; border-radius: 50%; display: inline-block;"></span>
                    <span>التقارير والمستخرجات</span>
                </li>

                <li class="nav-item">
                    <a class="nav-link <?php echo Utilities::getCurrentPage() == 'statements.php' ? 'active' : ''; ?>" href="statements.php">
                        <i class="fas fa-file-signature nav-icon" style="color: #2563eb;"></i>
                        <span>مستخرجات رسمية</span>
                    </a>
                </li>
                <li class="nav-item">
                    <a class="nav-link <?php echo Utilities::getCurrentPage() == 'student_file.php' ? 'active' : ''; ?>" href="student_file.php">
                        <i class="fas fa-folder-open nav-icon" style="color: #f59e0b;"></i>
                        <span>ملف الطالب</span>
                    </a>
                </li>
                <li class="nav-item">
                    <a class="nav-link <?php echo Utilities::getCurrentPage() == 'student_id_cards.php' ? 'active' : ''; ?>" href="student_id_cards.php">
                        <i class="fas fa-id-card nav-icon" style="color: #6366f1;"></i>
                        <span>كروت الطلاب (ID)</span>
                    </a>
                </li>
                <li class="nav-item">
                    <a class="nav-link <?php echo Utilities::getCurrentPage() == 'export_students.php' ? 'active' : ''; ?>" href="export_students.php">
                        <i class="fas fa-file-export nav-icon" style="color: #64748b;"></i>
                        <span>تصدير البيانات</span>
                    </a>
                </li>
                <li class="nav-item">
                    <a class="nav-link <?php echo Utilities::getCurrentPage() == 'student_statistics.php' ? 'active' : ''; ?>" href="student_statistics.php">
                        <i class="fas fa-chart-column nav-icon" style="color: #10b981;"></i>
                        <span>إحصائيات الطلاب</span>
                    </a>
                </li>
                <li class="nav-item">
                    <a class="nav-link <?php echo Utilities::getCurrentPage() == 'student_numbers_reports.php' ? 'active' : ''; ?>" href="student_numbers_reports.php">
                        <i class="fas fa-chart-pie nav-icon" style="color: #8b5cf6;"></i>
                        <span>ميزانية المدرسة</span>
                    </a>
                </li>
                <li class="nav-item">
                    <a class="nav-link <?php echo Utilities::getCurrentPage() == 'calculation_tools.php' ? 'active' : ''; ?>" href="calculation_tools.php">
                        <i class="fas fa-calculator nav-icon" style="color: #f59e0b;"></i>
                        <span>أدوات الحساب</span>
                    </a>
                </li>
            </ul>
        </div>
    </aside>
    <?php elseif ($isSpecialist): ?>
    <aside class="admin-sidebar shadow specialist-flat-sidebar" id="adminSidebar">
        <div class="sidebar-menu">
            <ul class="nav flex-column mb-auto sidebar-nav" id="sidebarNavAccordion">
                <!-- Desktop Sidebar Toggle (Visible on desktop only) -->
                <li class="nav-item d-none d-lg-block toggle-nav-item">
                    <a class="nav-link" href="#" id="sidebarToggleBtn" style="cursor: pointer;">
                        <i class="fas fa-bars nav-icon"></i>
                    </a>
                </li>

                <!-- لوحة التحكم -->
                <li class="nav-item">
                    <a class="nav-link <?php echo Utilities::getCurrentPage() == 'specialist_dashboard.php' ? 'active' : ''; ?>"
                        href="specialist_dashboard.php">
                        <i class="fas fa-tachometer-alt nav-icon text-primary"></i>
                        <span>لوحة التحكم</span>
                    </a>
                </li>

                <li class="sidebar-category-header" style="font-size: 0.72rem; font-weight: 700; color: #94a3b8; padding: 22px 15px 8px; display: flex; align-items: center; gap: 8px; pointer-events: none; text-transform: uppercase;">
                    <span style="width: 5px; height: 5px; background-color: #3b82f6; border-radius: 50%; display: inline-block;"></span>
                    <span>شؤون الطلاب</span>
                </li>

                <li class="nav-item">
                    <a class="nav-link <?php echo Utilities::getCurrentPage() == 'students.php' ? 'active' : ''; ?>" href="students.php">
                        <i class="fas fa-user-check nav-icon" style="color: #10b981;"></i>
                        <span>الطلاب المقيدين</span>
                    </a>
                </li>
                <li class="nav-item">
                    <a class="nav-link <?php echo Utilities::getCurrentPage() == 'specialist_requests.php' ? 'active' : ''; ?>" href="specialist_requests.php">
                        <i class="fas fa-paper-plane nav-icon" style="color: #f59e0b;"></i>
                        <span>طلبات التعديل</span>
                        <span class="badge rounded-pill bg-warning text-dark ms-auto" aria-label="طلبات قيد المراجعة"><?php echo $__pendingOperationsCount; ?></span>
                    </a>
                </li>
                <li class="nav-item">
                    <a class="nav-link <?php echo Utilities::getCurrentPage() == 'class_lists.php' ? 'active' : ''; ?>" href="class_lists.php">
                        <i class="fas fa-list-alt nav-icon text-info"></i>
                        <span>قوائم الفصول</span>
                    </a>
                </li>
                <li class="nav-item">
                    <a class="nav-link <?php echo Utilities::getCurrentPage() == 'attendance.php' ? 'active' : ''; ?>" href="attendance.php">
                        <i class="fas fa-clipboard-check nav-icon text-success"></i>
                        <span>الحضور والغياب</span>
                    </a>
                </li>

                <li class="sidebar-category-header" style="font-size: 0.72rem; font-weight: 700; color: #94a3b8; padding: 22px 15px 8px; display: flex; align-items: center; gap: 8px; pointer-events: none; text-transform: uppercase;">
                    <span style="width: 5px; height: 5px; background-color: #a855f7; border-radius: 50%; display: inline-block;"></span>
                    <span>التقارير والمستخرجات</span>
                </li>

                <li class="nav-item">
                    <a class="nav-link <?php echo Utilities::getCurrentPage() == 'student_file.php' ? 'active' : ''; ?>" href="student_file.php">
                        <i class="fas fa-folder-open nav-icon" style="color: #2563eb;"></i>
                        <span>ملف الطالب</span>
                    </a>
                </li>
                <li class="nav-item">
                    <a class="nav-link <?php echo Utilities::getCurrentPage() == 'student_id_cards.php' ? 'active' : ''; ?>" href="student_id_cards.php">
                        <i class="fas fa-id-card nav-icon" style="color: #6366f1;"></i>
                        <span>كروت الطلاب (ID)</span>
                    </a>
                </li>
                <li class="nav-item">
                    <a class="nav-link <?php echo Utilities::getCurrentPage() == 'export_students.php' ? 'active' : ''; ?>" href="export_students.php">
                        <i class="fas fa-file-export nav-icon text-secondary"></i>
                        <span>تصدير البيانات</span>
                    </a>
                </li>
                <li class="nav-item">
                    <a class="nav-link <?php echo Utilities::getCurrentPage() == 'student_statistics.php' ? 'active' : ''; ?>" href="student_statistics.php">
                        <i class="fas fa-chart-pie nav-icon text-success"></i>
                        <span>إحصائيات الطلاب</span>
                    </a>
                </li>
                <li class="nav-item">
                    <a class="nav-link <?php echo Utilities::getCurrentPage() == 'calculation_tools.php' ? 'active' : ''; ?>" href="calculation_tools.php">
                        <i class="fas fa-calculator nav-icon text-warning"></i>
                        <span>أدوات الحساب</span>
                    </a>
                </li>

                <li class="sidebar-category-header" style="font-size: 0.72rem; font-weight: 700; color: #94a3b8; padding: 22px 15px 8px; display: flex; align-items: center; gap: 8px; pointer-events: none; text-transform: uppercase;">
                    <span style="width: 5px; height: 5px; background-color: #ec4899; border-radius: 50%; display: inline-block;"></span>
                    <span>الخدمات والتقييم</span>
                </li>

                <li class="nav-item">
                    <a class="nav-link <?php echo Utilities::getCurrentPage() == 'student_evaluations.php' ? 'active' : ''; ?>" href="student_evaluations.php">
                        <i class="fas fa-star nav-icon" style="color: #eab308;"></i>
                        <span>تقييمات الطلاب</span>
                    </a>
                </li>
                <li class="nav-item">
                    <a class="nav-link <?php echo Utilities::getCurrentPage() == 'teacher_evaluations.php' ? 'active' : ''; ?>" href="teacher_evaluations.php">
                        <i class="fas fa-chalkboard-teacher nav-icon" style="color: #3b82f6;"></i>
                        <span>تقييمات المعلمين</span>
                    </a>
                </li>
                <li class="nav-item">
                    <a class="nav-link <?php echo Utilities::getCurrentPage() == 'evaluation_analytics.php' ? 'active' : ''; ?>" href="evaluation_analytics.php">
                        <i class="fas fa-chart-line nav-icon" style="color: #10b981;"></i>
                        <span>إحصائيات التقييم</span>
                    </a>
                </li>
                <li class="nav-item">
                    <a class="nav-link <?php echo Utilities::getCurrentPage() == 'evaluation_reports.php' ? 'active' : ''; ?>" href="evaluation_reports.php">
                        <i class="fas fa-file-invoice nav-icon" style="color: #a855f7;"></i>
                        <span>تقارير التقييم</span>
                    </a>
                </li>
                <li class="nav-item">
                    <a class="nav-link <?php echo Utilities::getCurrentPage() == 'student_clinic.php' ? 'active' : ''; ?>" href="student_clinic.php">
                        <i class="fas fa-clinic-medical nav-icon text-danger"></i>
                        <span>العيادة الطبية</span>
                    </a>
                </li>
            </ul>
        </div>
    </aside>
    <?php else: ?>
    <aside class="admin-sidebar shadow" id="adminSidebar">
        <div class="sidebar-menu">
            <ul class="nav flex-column mb-auto sidebar-nav" id="sidebarNavAccordion">
                <!-- Desktop Sidebar Toggle (Visible on desktop only) -->
                <li class="nav-item d-none d-lg-block toggle-nav-item">
                    <a class="nav-link" href="#" id="sidebarToggleBtn" style="cursor: pointer;">
                        <i class="fas fa-bars nav-icon"></i>
                    </a>
                </li>

                <li class="nav-item">
                    <a class="nav-link <?php echo Utilities::getCurrentPage() == $__adminHomeHref ? 'active' : ''; ?>"
                        href="<?php echo htmlspecialchars($__adminHomeHref, ENT_QUOTES, 'UTF-8'); ?>">
                        <i class="fas fa-tachometer-alt nav-icon text-primary"></i>
                        <span>لوحة التحكم</span>
                    </a>
                </li>

                <!-- الهيكل المدرسي -->
                <?php $isSchoolActive = in_array(Utilities::getCurrentPage(), ['school_profile.php', 'stages.php', 'grades.php', 'classes.php', 'school_statistics.php']); ?>
                <li class="nav-item">
                    <a class="nav-link <?php echo $isSchoolActive ? 'active' : 'collapsed'; ?>" href="#schoolMenu"
                        data-bs-toggle="collapse" aria-expanded="<?php echo $isSchoolActive ? 'true' : 'false'; ?>">
                        <i class="fas fa-school nav-icon text-success"></i>
                        <span>الهيكل المدرسي</span>
                        <i class="fas fa-chevron-down ms-auto arrow-icon"></i>
                    </a>
                    <div class="collapse <?php echo $isSchoolActive ? 'show' : ''; ?>" id="schoolMenu">
                        <ul class="nav flex-column sub-menu">
                            <li><a class="nav-link <?php echo Utilities::getCurrentPage() == 'school_profile.php' ? 'active' : ''; ?>"
                                    href="school_profile.php"><i class="fas fa-school text-primary me-2"></i>بيانات المدرسة</a></li>
                            <li><a class="nav-link <?php echo Utilities::getCurrentPage() == 'stages.php' ? 'active' : ''; ?>"
                                    href="stages.php"><i class="fas fa-layer-group text-success me-2"></i>المراحل
                                    الدراسية</a></li>
                            <li><a class="nav-link <?php echo Utilities::getCurrentPage() == 'grades.php' ? 'active' : ''; ?>"
                                    href="grades.php"><i class="fas fa-graduation-cap text-info me-2"></i>الصفوف
                                    الدراسية</a></li>
                            <li><a class="nav-link <?php echo Utilities::getCurrentPage() == 'classes.php' ? 'active' : ''; ?>"
                                    href="classes.php"><i class="fas fa-door-open text-primary me-2"></i>الفصول</a></li>
                            <li><a class="nav-link <?php echo Utilities::getCurrentPage() == 'school_statistics.php' ? 'active' : ''; ?>"
                                    href="school_statistics.php"><i
                                        class="fas fa-chart-pie text-primary me-2"></i>الإحصائيات</a></li>
                        </ul>
                    </div>
                </li>

                <!-- المواد والدرجات -->
                <?php $isSubjectsActive = in_array(Utilities::getCurrentPage(), ['subjects.php', 'assessment_calendar.php', 'assessment_subject_assignments.php', 'assessment_teacher_assignments.php', 'assessment_schemes.php', 'assessment_components.php', 'assessment_component_week_rules.php', 'assessment_windows.php', 'assessment_marks.php', 'assessment_marks_sheet.php', 'assessment_reports.php', 'assessment_permissions.php', 'assessment_student_locks.php']); ?>
                <li class="nav-item">
                    <a class="nav-link <?php echo $isSubjectsActive ? 'active' : 'collapsed'; ?>" href="#subjectsMenu"
                        data-bs-toggle="collapse" aria-expanded="<?php echo $isSubjectsActive ? 'true' : 'false'; ?>">
                        <i class="fas fa-book-open nav-icon" style="color: #fd7e14;"></i>
                        <span>المواد والدرجات</span>
                        <i class="fas fa-chevron-down ms-auto arrow-icon"></i>
                    </a>
                    <div class="collapse <?php echo $isSubjectsActive ? 'show' : ''; ?>" id="subjectsMenu">
                        <ul class="nav flex-column sub-menu">
                            <li><a class="nav-link <?php echo Utilities::getCurrentPage() == 'subjects.php' ? 'active' : ''; ?>"
                                    href="subjects.php"><i class="fas fa-book me-2" style="color: #fd7e14;"></i>المواد
                                    الدراسية</a></li>
                            <li><a class="nav-link <?php echo Utilities::getCurrentPage() == 'assessment_calendar.php' ? 'active' : ''; ?>"
                                    href="assessment_calendar.php"><i class="fas fa-calendar-check text-primary me-2"></i>التقويم</a></li>
                            <li><a class="nav-link <?php echo Utilities::getCurrentPage() == 'assessment_subject_assignments.php' ? 'active' : ''; ?>"
                                    href="assessment_subject_assignments.php"><i class="fas fa-link text-info me-2"></i>ربط
                                    المواد بالصفوف</a></li>
                            <li><a class="nav-link <?php echo Utilities::getCurrentPage() == 'assessment_teacher_assignments.php' ? 'active' : ''; ?>"
                                    href="assessment_teacher_assignments.php"><i class="fas fa-chalkboard-user text-success me-2"></i>تعيينات
                                    المعلمين</a></li>
                            <li><a class="nav-link <?php echo Utilities::getCurrentPage() == 'assessment_schemes.php' ? 'active' : ''; ?>"
                                    href="assessment_schemes.php"><i class="fas fa-diagram-project text-warning me-2"></i>خطط
                                    الدرجات</a></li>
                            <li><a class="nav-link <?php echo Utilities::getCurrentPage() == 'assessment_components.php' ? 'active' : ''; ?>"
                                    href="assessment_components.php"><i class="fas fa-list-check text-purple me-2"></i>بنود
                                    التقييم</a></li>
                            <li><a class="nav-link <?php echo Utilities::getCurrentPage() == 'assessment_component_week_rules.php' ? 'active' : ''; ?>"
                                    href="assessment_component_week_rules.php"><i class="fas fa-calendar-check text-info me-2"></i>قواعد
                                    أسابيع البنود</a></li>
                            <li><a class="nav-link <?php echo Utilities::getCurrentPage() == 'assessment_windows.php' ? 'active' : ''; ?>"
                                    href="assessment_windows.php"><i class="fas fa-lock-open text-success me-2"></i>نوافذ
                                    الرصد</a></li>
                            <li><a class="nav-link <?php echo Utilities::getCurrentPage() == 'assessment_marks.php' ? 'active' : ''; ?>"
                                    href="assessment_marks.php"><i class="fas fa-graduation-cap text-primary me-2"></i>درجات
                                    الطلاب</a></li>
                            <li><a class="nav-link <?php echo Utilities::getCurrentPage() == 'assessment_marks_sheet.php' ? 'active' : ''; ?>"
                                    href="assessment_marks_sheet.php"><i class="fas fa-table-cells-large text-success me-2"></i>شيت
                                    الدرجات</a></li>
                            <li><a class="nav-link <?php echo Utilities::getCurrentPage() == 'assessment_reports.php' ? 'active' : ''; ?>"
                                    href="assessment_reports.php"><i class="fas fa-file-lines text-danger me-2"></i>تقارير
                                    الدرجات</a></li>
                            <li><a class="nav-link <?php echo Utilities::getCurrentPage() == 'assessment_permissions.php' ? 'active' : ''; ?>"
                                    href="assessment_permissions.php"><i class="fas fa-user-shield text-primary me-2"></i>صلاحيات
                                    الدرجات</a></li>
                            <li><a class="nav-link <?php echo Utilities::getCurrentPage() == 'assessment_student_locks.php' ? 'active' : ''; ?>"
                                    href="assessment_student_locks.php"><i class="fas fa-user-lock text-secondary me-2"></i>أقفال
                                    الطلاب</a></li>
                        </ul>
                    </div>
                </li>

                <!-- شؤون الطلاب -->
                <?php $isStudentsActive = in_array(Utilities::getCurrentPage(), ['students.php', 'student_operations.php', 'pending_operations.php', 'specialist_requests.php', 'new_students.php', 'graduate_students.php', 'transferred_students.php', 'export_students.php', 'class_lists.php', 'siblings.php', 'relationship_discovery.php', 'attendance.php', 'statements.php', 'student_file.php', 'student_statistics.php', 'calculation_tools.php', 'student_id_cards.php', 'student_numbers_reports.php', 'student_archive.php', 'student_data_completeness.php']); ?>
                <li class="nav-item">
                    <a class="nav-link <?php echo $isStudentsActive ? 'active' : 'collapsed'; ?>" href="#studentsMenu"
                        data-bs-toggle="collapse" aria-expanded="<?php echo $isStudentsActive ? 'true' : 'false'; ?>">
                        <i class="fas fa-user-graduate nav-icon" style="color: #6f42c1;"></i>
                        <span>شؤون الطلاب</span>
                        <i class="fas fa-chevron-down ms-auto arrow-icon"></i>
                    </a>
                    <div class="collapse <?php echo $isStudentsActive ? 'show' : ''; ?>" id="studentsMenu">
                        <ul class="nav flex-column sub-menu">
                            <?php $isStudentDataActive = in_array(Utilities::getCurrentPage(), ['students.php', 'student_operations.php', 'pending_operations.php', 'specialist_requests.php', 'new_students.php', 'transferred_students.php', 'graduate_students.php', 'student_archive.php', 'student_data_completeness.php']); ?>
                            <li class="nav-item">
                                <a class="nav-link sub-menu-toggle <?php echo $isStudentDataActive ? '' : 'collapsed'; ?>"
                                   href="#studentDataMenu" data-bs-toggle="collapse">
                                     <i class="fas fa-id-card text-primary me-2"></i>بيانات الطلاب
                                     <i class="fas fa-chevron-down ms-auto arrow-icon" style="font-size:0.7rem"></i>
                                 </a>
                                <div class="collapse <?php echo $isStudentDataActive ? 'show' : ''; ?>" id="studentDataMenu">
                                    <ul class="nav flex-column sub-sub-menu">
                                        <li><a class="nav-link <?php echo Utilities::getCurrentPage() == 'students.php' ? 'active' : ''; ?>"
                                                href="students.php"><i class="fas fa-user-check me-2" style="color: #10b981;"></i>المقيدين</a></li>
                                        <?php if (!$isSpecialist && Utilities::roleCanAccessAdminPage((string) ($_SESSION['active_role'] ?? $_SESSION['role'] ?? ''), 'student_operations.php')): ?>
                                            <li><a class="nav-link <?php echo Utilities::getCurrentPage() == 'student_operations.php' ? 'active' : ''; ?>"
                                                    href="student_operations.php"><i class="fas fa-clock-rotate-left me-2" style="color: #2563eb;"></i>سجل العمليات</a></li>
                                        <?php endif; ?>
                                        <?php if ($isSpecialist): ?>
                                            <li><a class="nav-link <?php echo Utilities::getCurrentPage() == 'specialist_requests.php' ? 'active' : ''; ?>"
                                                    href="specialist_requests.php"><i class="fas fa-paper-plane me-2" style="color: #f59e0b;"></i>طلباتي<span class="badge rounded-pill bg-warning text-dark ms-auto" aria-label="طلبات قيد المراجعة"><?php echo $__pendingOperationsCount; ?></span></a></li>
                                        <?php else: ?>
                                            <li><a class="nav-link <?php echo Utilities::getCurrentPage() == 'pending_operations.php' ? 'active' : ''; ?>"
                                                    href="pending_operations.php"><i class="fas fa-hourglass-half me-2" style="color: #f59e0b;"></i>العمليات المعلقة<span class="badge rounded-pill bg-warning text-dark ms-auto" aria-label="عمليات قيد المراجعة"><?php echo $__pendingOperationsCount; ?></span></a></li>
                                        <?php endif; ?>
                                        <li><a class="nav-link <?php echo Utilities::getCurrentPage() == 'new_students.php' ? 'active' : ''; ?>"
                                                href="new_students.php"><i class="fas fa-user-plus me-2" style="color: #0284c7;"></i>منقول إلى المدرسة</a></li>
                                        <li><a class="nav-link <?php echo Utilities::getCurrentPage() == 'transferred_students.php' ? 'active' : ''; ?>"
                                                href="transferred_students.php"><i class="fas fa-user-minus me-2" style="color: #f59e0b;"></i>منقول من المدرسة</a></li>
                                        <li><a class="nav-link <?php echo Utilities::getCurrentPage() == 'graduate_students.php' ? 'active' : ''; ?>"
                                                href="graduate_students.php"><i class="fas fa-user-graduate me-2" style="color: #6f42c1;"></i>الخريجين</a></li>
                                        <li><a class="nav-link <?php echo Utilities::getCurrentPage() == 'student_archive.php' ? 'active' : ''; ?>"
                                                href="student_archive.php"><i class="fas fa-archive me-2" style="color: #64748b;"></i>أرشيف الطلاب</a></li>
                                        <li><a class="nav-link <?php echo Utilities::getCurrentPage() == 'student_data_completeness.php' ? 'active' : ''; ?>"
                                                href="student_data_completeness.php"><i class="fas fa-check-double me-2" style="color: #a855f7;"></i>اكتمال البيانات</a></li>
                                    </ul>
                                </div>
                            </li>
                            <li><a class="nav-link <?php echo Utilities::getCurrentPage() == 'class_lists.php' ? 'active' : ''; ?>"
                                    href="class_lists.php"><i class="fas fa-list-alt text-info me-2"></i>قوائم
                                    الفصول</a></li>
                            <li><a class="nav-link <?php echo Utilities::getCurrentPage() == 'siblings.php' ? 'active' : ''; ?>"
                                    href="siblings.php"><i class="fas fa-user-friends me-2"
                                        style="color: #e91e63;"></i>صلات القرابة</a></li>
                            <li><a class="nav-link <?php echo Utilities::getCurrentPage() == 'attendance.php' ? 'active' : ''; ?>"
                                    href="attendance.php"><i class="fas fa-clipboard-check text-success me-2"></i>الحضور
                                    والغياب</a></li>

                            <?php $isOfficialDocsActive = in_array(Utilities::getCurrentPage(), ['statements.php', 'student_file.php', 'student_id_cards.php', 'student_numbers_reports.php']); ?>
                            <li class="nav-item">
                                <a class="nav-link sub-menu-toggle <?php echo $isOfficialDocsActive ? '' : 'collapsed'; ?>"
                                   href="#officialDocsMenu" data-bs-toggle="collapse">
                                    <i class="fas fa-file-signature text-primary me-2"></i>مستخرجات رسمية وتقارير
                                    <i class="fas fa-chevron-down ms-auto arrow-icon" style="font-size:0.7rem"></i>
                                </a>
                                <div class="collapse <?php echo $isOfficialDocsActive ? 'show' : ''; ?>" id="officialDocsMenu">
                                    <ul class="nav flex-column sub-sub-menu">
                                        <li><a class="nav-link <?php echo Utilities::getCurrentPage() == 'statements.php' ? 'active' : ''; ?>" href="statements.php"><i class="fas fa-file-alt me-2" style="color: #0ea5e9;"></i>إفادات رسمية</a></li>
                                        <li><a class="nav-link <?php echo Utilities::getCurrentPage() == 'student_file.php' ? 'active' : ''; ?>" href="student_file.php"><i class="fas fa-folder-open me-2" style="color: #2563eb;"></i>ملف الطالب</a></li>
                                        <li><a class="nav-link <?php echo Utilities::getCurrentPage() == 'student_numbers_reports.php' ? 'active' : ''; ?>" href="student_numbers_reports.php"><i class="fas fa-chart-pie me-2" style="color: #10b981;"></i>ميزانية المدرسة</a></li>
                                        <li><a class="nav-link <?php echo Utilities::getCurrentPage() == 'student_id_cards.php' ? 'active' : ''; ?>" href="student_id_cards.php"><i class="fas fa-id-card me-2" style="color: #6366f1;"></i>كروت الطلاب (ID)</a></li>
                                    </ul>
                                </div>
                            </li>

                            <li><a class="nav-link <?php echo Utilities::getCurrentPage() == 'export_students.php' ? 'active' : ''; ?>"
                                    href="export_students.php"><i
                                        class="fas fa-file-export text-secondary me-2"></i>تصدير البيانات</a></li>
                            <li><a class="nav-link <?php echo Utilities::getCurrentPage() == 'student_statistics.php' ? 'active' : ''; ?>"
                                    href="student_statistics.php"><i
                                        class="fas fa-chart-pie text-success me-2"></i>إحصائيات الطلاب</a></li>
                            <li><a class="nav-link <?php echo Utilities::getCurrentPage() == 'calculation_tools.php' ? 'active' : ''; ?>"
                                    href="calculation_tools.php"><i
                                        class="fas fa-calculator text-warning me-2"></i>أدوات الحساب</a></li>
                        </ul>
                    </div>
                </li>

                <!-- الأقسام والخدمات -->
                <?php $isServicesActive = in_array(Utilities::getCurrentPage(), ['library.php', 'materials_center.php', 'activities_monitor.php', 'evaluation_types.php', 'student_evaluations.php', 'teacher_evaluations.php', 'evaluation_analytics.php', 'evaluation_reports.php', 'evaluation_settings.php', 'timetable.php', 'student_clinic.php']); ?>
                <li class="nav-item">
                    <a class="nav-link <?php echo $isServicesActive ? 'active' : 'collapsed'; ?>" href="#servicesMenu"
                        data-bs-toggle="collapse" aria-expanded="<?php echo $isServicesActive ? 'true' : 'false'; ?>">
                        <i class="fas fa-layer-group nav-icon" style="color: #e83e8c;"></i>
                        <span>خدمات الطلاب</span>
                        <i class="fas fa-chevron-down ms-auto arrow-icon"></i>
                    </a>
                    <div class="collapse <?php echo $isServicesActive ? 'show' : ''; ?>" id="servicesMenu">
                        <ul class="nav flex-column sub-menu">
                            <!-- نقاط المكافآت -->
                            <?php $isPointsActive = in_array(Utilities::getCurrentPage(), ['evaluation_types.php', 'student_evaluations.php', 'teacher_evaluations.php', 'evaluation_analytics.php', 'evaluation_reports.php', 'evaluation_settings.php']); ?>
                            <li class="nav-item">
                                <a class="nav-link sub-menu-toggle <?php echo $isPointsActive ? '' : 'collapsed'; ?>"
                                    href="#pointsMenu2" data-bs-toggle="collapse">
                                    <i class="fas fa-award me-2" style="color: #fd7e14;"></i> نظام نقاط المكافئات <i
                                        class="fas fa-chevron-down ms-auto arrow-icon" style="font-size:0.7rem"></i>
                                </a>
                                <div class="collapse <?php echo $isPointsActive ? 'show' : ''; ?>" id="pointsMenu2">
                                    <ul class="nav flex-column sub-sub-menu">
                                        <li><a class="nav-link <?php echo Utilities::getCurrentPage() == 'evaluation_types.php' ? 'active' : ''; ?>"
                                                href="evaluation_types.php"><i class="fas fa-tags me-2" style="color: #6366f1;"></i>أنواع التقييمات</a></li>
                                        <li><a class="nav-link <?php echo Utilities::getCurrentPage() == 'student_evaluations.php' ? 'active' : ''; ?>"
                                                href="student_evaluations.php"><i class="fas fa-star me-2" style="color: #eab308;"></i>تقييمات الطلاب</a></li>
                                        <li><a class="nav-link <?php echo Utilities::getCurrentPage() == 'teacher_evaluations.php' ? 'active' : ''; ?>"
                                                href="teacher_evaluations.php"><i class="fas fa-chalkboard-teacher me-2" style="color: #3b82f6;"></i>تقييمات المعلمين</a></li>
                                        <li><a class="nav-link <?php echo Utilities::getCurrentPage() == 'evaluation_analytics.php' ? 'active' : ''; ?>"
                                                href="evaluation_analytics.php"><i class="fas fa-chart-line me-2" style="color: #10b981;"></i>الإحصائيات</a></li>
                                        <li><a class="nav-link <?php echo Utilities::getCurrentPage() == 'evaluation_reports.php' ? 'active' : ''; ?>"
                                                href="evaluation_reports.php"><i class="fas fa-file-invoice me-2" style="color: #a855f7;"></i>التقارير</a></li>
                                        <li><a class="nav-link <?php echo Utilities::getCurrentPage() == 'evaluation_settings.php' ? 'active' : ''; ?>"
                                                href="evaluation_settings.php"><i class="fas fa-sliders-h me-2" style="color: #64748b;"></i>إعدادات النظام</a></li>
                                    </ul>
                                </div>
                            </li>

                            <li><a class="nav-link <?php echo Utilities::getCurrentPage() == 'activities_monitor.php' ? 'active' : ''; ?>"
                                    href="activities_monitor.php"><i
                                        class="fas fa-gamepad text-primary me-2"></i>الأنشطة التفاعلية</a></li>
                            <li><a class="nav-link <?php echo Utilities::getCurrentPage() == 'library.php' ? 'active' : ''; ?>"
                                    href="library.php"><i class="fas fa-book-reader text-primary me-2"></i>المكتبة</a></li>
                            <li><a class="nav-link <?php echo Utilities::getCurrentPage() == 'materials_center.php' ? 'active' : ''; ?>"
                                    href="materials_center.php"><i class="fas fa-cloud-upload-alt text-info me-2"></i>مركز رفع المواد التعليمية</a></li>
                            <li><a class="nav-link <?php echo Utilities::getCurrentPage() == 'timetable.php' ? 'active' : ''; ?>"
                                    href="timetable.php"><i class="fas fa-calendar-alt me-2"
                                        style="color: #fd7e14;"></i>الجدول المدرسي</a></li>
                            <li><a class="nav-link <?php echo Utilities::getCurrentPage() == 'student_clinic.php' ? 'active' : ''; ?>"
                                    href="student_clinic.php"><i class="fas fa-clinic-medical text-danger me-2"></i>العيادة</a></li>


                        </ul>
                    </div>
                </li>

                <!-- الحركة والتنقلات -->
                <?php $isTransportActive = in_array(Utilities::getCurrentPage(), ['buses.php', 'student_buses.php', 'bus_lists.php', 'bus_report.php', 'locations.php', 'transport_statistics.php', 'bus_staff.php']); ?>
                <li class="nav-item">
                    <a class="nav-link <?php echo $isTransportActive ? 'active' : 'collapsed'; ?>" href="#transportMenu"
                        data-bs-toggle="collapse" aria-expanded="<?php echo $isTransportActive ? 'true' : 'false'; ?>">
                        <i class="fas fa-route nav-icon" style="color: #0dcaf0;"></i>
                        <span>الحركة والتنقلات</span>
                        <i class="fas fa-chevron-down ms-auto arrow-icon"></i>
                    </a>
                    <div class="collapse <?php echo $isTransportActive ? 'show' : ''; ?>" id="transportMenu">
                        <ul class="nav flex-column sub-menu">
                            <li><a class="nav-link <?php echo Utilities::getCurrentPage() == 'locations.php' ? 'active' : ''; ?>"
                                    href="locations.php"><i class="fas fa-map-marked-alt text-warning me-2"></i>المناطق
                                    الجغرافية</a></li>
                            <li><a class="nav-link <?php echo Utilities::getCurrentPage() == 'bus_staff.php' ? 'active' : ''; ?>"
                                    href="bus_staff.php"><i class="fas fa-users text-primary me-2"></i>طاقم الحافلات</a></li>
                            <li><a class="nav-link <?php echo Utilities::getCurrentPage() == 'buses.php' ? 'active' : ''; ?>"
                                    href="buses.php"><i class="fas fa-bus text-info me-2"></i>إدارة الحافلات</a></li>
                            <li><a class="nav-link <?php echo Utilities::getCurrentPage() == 'student_buses.php' ? 'active' : ''; ?>"
                                    href="student_buses.php"><i class="fas fa-user-check text-success me-2"></i>تعيين
                                    الطلاب</a></li>
                            <li><a class="nav-link <?php echo Utilities::getCurrentPage() == 'bus_lists.php' ? 'active' : ''; ?>"
                                    href="bus_lists.php"><i class="fas fa-list-ol text-info me-2"></i>قوائم الحافلات</a></li>
                            <li><a class="nav-link <?php echo Utilities::getCurrentPage() == 'bus_report.php' ? 'active' : ''; ?>"
                                    href="bus_report.php"><i class="fas fa-chart-bar text-primary me-2"></i>تقارير الحركة
                                    والتنقلات</a></li>
                            <li><a class="nav-link <?php echo Utilities::getCurrentPage() == 'transport_statistics.php' ? 'active' : ''; ?>"
                                    href="transport_statistics.php"><i class="fas fa-chart-pie text-danger me-2"></i>إحصائيات
                                    النقل</a></li>
                        </ul>
                    </div>
                </li>

                <li class="sidebar-category-header" style="font-size: 0.72rem; font-weight: 700; color: #94a3b8; padding: 22px 15px 8px; display: flex; align-items: center; gap: 8px; pointer-events: none; text-transform: uppercase;">
                    <span style="width: 5px; height: 5px; background-color: #10b981; border-radius: 50%; display: inline-block;"></span>
                    <span>شؤون العاملين والمعلمين</span>
                </li>

                <!-- شؤون العاملين -->
                <?php
                $staffCurrentPage = Utilities::getCurrentPage();
                $staffProfilePages = ['staff.php', 'staff_statistics.php', 'export_staff.php', 'disciplinary.php'];
                $staffRequestPages = ['permissions.php', 'leaves.php', 'leave_balances.php'];
                $staffAttendancePages = ['staff_attendance.php', 'staff_shifts.php', 'staff_attendance_reports.php', 'staff_biometric_import.php', 'biometric_devices.php', 'staff_attendance_audit.php', 'hr_attendance_exceptions.php'];
                $staffAdministrationPages = ['hr_organization.php', 'hr_policy_calendar.php', 'hr_approval_workflows.php', 'hr_ertaq.php', 'hr_audit.php'];
                $isStaffActive = in_array($staffCurrentPage, array_merge(['hr_center.php'], $staffProfilePages, $staffRequestPages, $staffAttendancePages, $staffAdministrationPages), true);
                $isStaffRequestsActive = in_array($staffCurrentPage, $staffRequestPages);
                $isStaffAttendanceActive = in_array($staffCurrentPage, $staffAttendancePages);
                ?>
                <li class="nav-item">
                    <a class="nav-link <?php echo $isStaffActive ? 'active' : 'collapsed'; ?>" href="#staffMenu"
                        data-bs-toggle="collapse" aria-expanded="<?php echo $isStaffActive ? 'true' : 'false'; ?>">
                        <i class="fas fa-users-cog nav-icon text-danger"></i>
                        <span>شؤون العاملين</span>
                        <i class="fas fa-chevron-down ms-auto arrow-icon"></i>
                    </a>
                    <div class="collapse <?php echo $isStaffActive ? 'show' : ''; ?>" id="staffMenu">
                        <ul class="nav flex-column sub-menu">
                            <li><a class="nav-link <?php echo $staffCurrentPage == 'hr_center.php' ? 'active' : ''; ?>"
                                    href="hr_center.php"><i class="fas fa-layer-group text-primary me-2"></i>مركز شؤون
                                    العاملين</a></li>

                            <li class="px-3 pt-2 pb-1 text-uppercase text-muted small fw-bold">الإدارة المتكاملة</li>
                            <li><a class="nav-link <?php echo $staffCurrentPage == 'hr_organization.php' ? 'active' : ''; ?>"
                                    href="hr_organization.php"><i class="fas fa-sitemap me-2" style="color: #2563eb;"></i>الهيكل والتعيينات</a></li>
                            <li><a class="nav-link <?php echo $staffCurrentPage == 'hr_policy_calendar.php' ? 'active' : ''; ?>"
                                    href="hr_policy_calendar.php"><i class="fas fa-calendar-check me-2" style="color: #0ea5e9;"></i>سياسات الدوام والتقويم</a></li>
                            <li><a class="nav-link <?php echo $staffCurrentPage == 'hr_approval_workflows.php' ? 'active' : ''; ?>"
                                    href="hr_approval_workflows.php"><i class="fas fa-route me-2" style="color: #8b5cf6;"></i>مسارات الاعتماد والتفويض</a></li>
                            <li><a class="nav-link <?php echo $staffCurrentPage == 'hr_ertaq.php' ? 'active' : ''; ?>"
                                    href="hr_ertaq.php"><i class="fas fa-comments me-2" style="color: #10b981;"></i>منصة ارتق</a></li>
                            <li><a class="nav-link <?php echo $staffCurrentPage == 'hr_audit.php' ? 'active' : ''; ?>"
                                    href="hr_audit.php"><i class="fas fa-shield-halved me-2" style="color: #64748b;"></i>سجل مراجعة شؤون العاملين</a></li>

                            <li><a class="nav-link <?php echo $staffCurrentPage == 'staff.php' ? 'active' : ''; ?>"
                                    href="staff.php"><i class="fas fa-id-card text-primary me-2"></i>بيانات الموظفين</a>
                            </li>
                            <li><a class="nav-link <?php echo $staffCurrentPage == 'disciplinary.php' ? 'active' : ''; ?>"
                                    href="disciplinary.php"><i class="fas fa-gavel text-danger me-2"></i>الجزاءات
                                    والتأديب</a></li>

                            <li class="nav-item">
                                <a class="nav-link sub-menu-toggle <?php echo $isStaffRequestsActive ? '' : 'collapsed'; ?>"
                                    href="#staffRequestsMenu" data-bs-toggle="collapse">
                                    <i class="fas fa-clipboard-list text-success me-2"></i>الأذونات والأجازات<i
                                        class="fas fa-chevron-down ms-auto arrow-icon" style="font-size:0.7rem"></i>
                                </a>
                                <div class="collapse <?php echo $isStaffRequestsActive ? 'show' : ''; ?>"
                                    id="staffRequestsMenu">
                                    <ul class="nav flex-column sub-sub-menu">
                                        <li><a class="nav-link <?php echo $staffCurrentPage == 'permissions.php' ? 'active' : ''; ?>"
                                                href="permissions.php"><i class="fas fa-id-badge me-2" style="color: #3b82f6;"></i>الأذونات</a></li>
                                        <li><a class="nav-link <?php echo $staffCurrentPage == 'leaves.php' ? 'active' : ''; ?>"
                                                href="leaves.php"><i class="fas fa-plane-departure me-2" style="color: #f43f5e;"></i>الأجازات</a></li>
                                        <li><a class="nav-link <?php echo $staffCurrentPage == 'leave_balances.php' ? 'active' : ''; ?>"
                                                href="leave_balances.php"><i class="fas fa-wallet me-2" style="color: #10b981;"></i>أرصدة الإجازات</a></li>
                                    </ul>
                                </div>
                            </li>

                            <li class="nav-item">
                                <a class="nav-link sub-menu-toggle <?php echo $isStaffAttendanceActive ? '' : 'collapsed'; ?>"
                                    href="#staffAttendanceMenu" data-bs-toggle="collapse">
                                    <i class="fas fa-user-clock text-warning me-2"></i> الحضور والانصراف <i
                                        class="fas fa-chevron-down ms-auto arrow-icon" style="font-size:0.7rem"></i>
                                </a>
                                <div class="collapse <?php echo $isStaffAttendanceActive ? 'show' : ''; ?>"
                                    id="staffAttendanceMenu">
                                    <ul class="nav flex-column sub-sub-menu">
                                        <li><a class="nav-link <?php echo $staffCurrentPage == 'staff_attendance.php' ? 'active' : ''; ?>"
                                                href="staff_attendance.php"><i class="fas fa-user-check me-2" style="color: #10b981;"></i>الحضور والغياب</a></li>
                                        <li><a class="nav-link <?php echo $staffCurrentPage == 'hr_attendance_exceptions.php' ? 'active' : ''; ?>"
                                                href="hr_attendance_exceptions.php"><i class="fas fa-triangle-exclamation me-2" style="color: #f59e0b;"></i>مركز الاستثناءات</a></li>
                                        <li><a class="nav-link <?php echo $staffCurrentPage == 'staff_shifts.php' ? 'active' : ''; ?>"
                                                href="staff_shifts.php"><i class="fas fa-business-time me-2" style="color: #06b6d4;"></i>إعدادات الدوام</a></li>
                                        <li><a class="nav-link <?php echo $staffCurrentPage == 'staff_biometric_import.php' ? 'active' : ''; ?>"
                                                href="staff_biometric_import.php"><i class="fas fa-file-import me-2" style="color: #6366f1;"></i>استيراد البصمة</a></li>
                                        <li><a class="nav-link <?php echo $staffCurrentPage == 'biometric_devices.php' ? 'active' : ''; ?>"
                                                href="biometric_devices.php"><i class="fas fa-fingerprint me-2" style="color: #ec4899;"></i>أجهزة البصمة</a></li>
                                        <li><a class="nav-link <?php echo $staffCurrentPage == 'staff_attendance_reports.php' ? 'active' : ''; ?>"
                                                href="staff_attendance_reports.php"><i class="fas fa-print me-2" style="color: #8b5cf6;"></i>تقارير الحضور</a></li>
                                        <li><a class="nav-link <?php echo $staffCurrentPage == 'staff_attendance_audit.php' ? 'active' : ''; ?>"
                                                href="staff_attendance_audit.php"><i class="fas fa-history me-2" style="color: #64748b;"></i>سجل تدقيق الحضور</a></li>
                                    </ul>
                                </div>
                            </li>

                            <li><a class="nav-link <?php echo $staffCurrentPage == 'export_staff.php' ? 'active' : ''; ?>"
                                    href="export_staff.php"><i class="fas fa-file-export text-secondary me-2"></i>تصدير
                                    البيانات</a></li>
                            <li><a class="nav-link <?php echo $staffCurrentPage == 'staff_statistics.php' ? 'active' : ''; ?>"
                                    href="staff_statistics.php"><i
                                        class="fas fa-chart-bar text-success me-2"></i>إحصائيات العاملين</a></li>
                        </ul>
                    </div>
                </li>

                <!-- خدمات المعلمين -->
                <?php
                $teacherCurrentPage = Utilities::getCurrentPage();
                $teacherTrainingPages = ['training_programs.php', 'training_courses.php', 'training_reports.php'];
                $teacherLessonPages = ['ai_lessons_monitor.php', 'teacher_lessons.php'];
                $isTeacherActive = in_array($teacherCurrentPage, array_merge($teacherLessonPages, $teacherTrainingPages, ['external_teachers.php']));
                $isTrainingSubActive = in_array($teacherCurrentPage, $teacherTrainingPages);
                ?>
                <li class="nav-item">
                    <a class="nav-link <?php echo $isTeacherActive ? 'active' : 'collapsed'; ?>" href="#teacherServicesMenu"
                        data-bs-toggle="collapse" aria-expanded="<?php echo $isTeacherActive ? 'true' : 'false'; ?>">
                        <i class="fas fa-chalkboard-teacher nav-icon" style="color: #20c997;"></i>
                        <span>خدمات المعلمين</span>
                        <i class="fas fa-chevron-down ms-auto arrow-icon"></i>
                    </a>
                    <div class="collapse <?php echo $isTeacherActive ? 'show' : ''; ?>" id="teacherServicesMenu">
                        <ul class="nav flex-column sub-menu">
                            <!-- التدريب والجودة -->
                            <li class="nav-item">
                                <a class="nav-link sub-menu-toggle <?php echo $isTrainingSubActive ? '' : 'collapsed'; ?>"
                                    href="#teacherTrainingMenu" data-bs-toggle="collapse">
                                    <i class="fas fa-school text-success me-2"></i> التدريب والجودة <i
                                        class="fas fa-chevron-down ms-auto arrow-icon" style="font-size:0.7rem"></i>
                                </a>
                                <div class="collapse <?php echo $isTrainingSubActive ? 'show' : ''; ?>" id="teacherTrainingMenu">
                                    <ul class="nav flex-column sub-sub-menu">
                                        <li><a class="nav-link <?php echo $teacherCurrentPage == 'training_programs.php' ? 'active' : ''; ?>"
                                                href="training_programs.php"><i class="fas fa-graduation-cap me-2" style="color: #8b5cf6;"></i>البرامج</a></li>
                                        <li><a class="nav-link <?php echo $teacherCurrentPage == 'training_courses.php' ? 'active' : ''; ?>"
                                                href="training_courses.php"><i class="fas fa-certificate me-2" style="color: #eab308;"></i>الدورات</a></li>
                                        <li><a class="nav-link <?php echo $teacherCurrentPage == 'training_reports.php' ? 'active' : ''; ?>"
                                                href="training_reports.php"><i class="fas fa-file-contract me-2" style="color: #10b981;"></i>التقارير</a></li>
                                    </ul>
                                </div>
                            </li>

                            <li><a class="nav-link <?php echo $teacherCurrentPage == 'external_teachers.php' ? 'active' : ''; ?>"
                                    href="external_teachers.php"><i
                                        class="fas fa-users-rectangle text-info me-2"></i>المعلمين الخارجيين</a></li>

                            <li><a class="nav-link <?php echo in_array($teacherCurrentPage, $teacherLessonPages) ? 'active' : ''; ?>"
                                    href="ai_lessons_monitor.php"><i class="fas fa-robot text-danger me-2"></i>أداة التحضير بـ AI</a></li>
                        </ul>
                    </div>
                </li>

                <li class="sidebar-category-header" style="font-size: 0.72rem; font-weight: 700; color: #94a3b8; padding: 22px 15px 8px; display: flex; align-items: center; gap: 8px; pointer-events: none; text-transform: uppercase;">
                    <span style="width: 5px; height: 5px; background-color: #f59e0b; border-radius: 50%; display: inline-block;"></span>
                    <span>الشؤون المالية</span>
                </li>

                <!-- الشؤون المالية -->
                <?php
                $financePages = [
                    'fee_structure.php', 'fee_calculator.php', 'fee_payments.php', 'staff_financial_data.php',
                    'finance_dashboard.php', 'finance_fee_plans.php', 'finance_discounts.php', 'finance_discount_awards.php', 'finance_receipts.php', 'finance_refunds.php',
                    'finance_debts.php', 'finance_student_accounts.php', 'finance_student_ledger.php', 'finance_buses.php',
                    'finance_staff_contracts.php', 'finance_payroll_runs.php', 'finance_payroll_items.php', 'finance_payroll_payments.php', 'finance_payslip.php', 'finance_staff_advances.php', 'finance_staff_ledger.php',
                    'finance_vouchers.php', 'finance_cashboxes.php', 'finance_budgets.php', 'finance_journal.php',
                    'finance_reports.php', 'finance_import_export.php', 'finance_archive.php', 'finance_audit_log.php', 'finance_approvals.php', 'finance_periods.php',
                ];
                $isFinanceActive = in_array(Utilities::getCurrentPage(), $financePages, true);
                ?>
                <li class="nav-item">
                    <a class="nav-link <?php echo $isFinanceActive ? 'active' : 'collapsed'; ?>" href="#financeMenu"
                        data-bs-toggle="collapse" aria-expanded="<?php echo $isFinanceActive ? 'true' : 'false'; ?>">
                        <i class="fas fa-coins nav-icon text-warning"></i>
                        <span>الشؤون المالية</span>
                        <i class="fas fa-chevron-down ms-auto arrow-icon"></i>
                    </a>
                    <div class="collapse <?php echo $isFinanceActive ? 'show' : ''; ?>" id="financeMenu">
                        <ul class="nav flex-column sub-menu">
                            <li class="px-3 pt-2 pb-1 text-uppercase text-muted small fw-bold">نظرة عامة والتحصيل</li>
                            <li><a class="nav-link <?php echo Utilities::getCurrentPage() == 'finance_dashboard.php' ? 'active' : ''; ?>"
                                    href="finance_dashboard.php"><i class="fas fa-chart-pie text-warning me-2"></i>لوحة المالية الجديدة</a></li>
                            <li><a class="nav-link <?php echo Utilities::getCurrentPage() == 'finance_student_accounts.php' ? 'active' : ''; ?>"
                                    href="finance_student_accounts.php"><i class="fas fa-user-graduate text-primary me-2"></i>حسابات الطلاب المالية</a></li>
                            <li><a class="nav-link <?php echo Utilities::getCurrentPage() == 'finance_receipts.php' ? 'active' : ''; ?>"
                                    href="finance_receipts.php"><i class="fas fa-receipt text-success me-2"></i>الإيصالات والتحصيل</a></li>
                            <li><a class="nav-link <?php echo Utilities::getCurrentPage() == 'finance_refunds.php' ? 'active' : ''; ?>"
                                    href="finance_refunds.php"><i class="fas fa-hand-holding-usd text-warning me-2"></i>الاستردادات</a></li>
                            <li><a class="nav-link <?php echo Utilities::getCurrentPage() == 'finance_debts.php' ? 'active' : ''; ?>"
                                    href="finance_debts.php"><i class="fas fa-exclamation-triangle text-danger me-2"></i>مديونيات الطلاب</a></li>
                            <li><a class="nav-link <?php echo Utilities::getCurrentPage() == 'finance_buses.php' ? 'active' : ''; ?>"
                                    href="finance_buses.php"><i class="fas fa-bus text-info me-2"></i>ماليات الحافلات</a></li>

                            <li><hr class="dropdown-divider"></li>
                            <li class="px-3 pt-1 pb-1 text-uppercase text-muted small fw-bold">الرسوم والخصومات</li>
                            <li><a class="nav-link <?php echo Utilities::getCurrentPage() == 'finance_fee_plans.php' ? 'active' : ''; ?>"
                                    href="finance_fee_plans.php"><i class="fas fa-layer-group text-primary me-2"></i>خطط الرسوم والأقساط</a></li>
                            <li><a class="nav-link <?php echo Utilities::getCurrentPage() == 'finance_discounts.php' ? 'active' : ''; ?>"
                                    href="finance_discounts.php"><i class="fas fa-percent text-success me-2"></i>سياسات الخصومات</a></li>
                            <li><a class="nav-link <?php echo Utilities::getCurrentPage() == 'finance_discount_awards.php' ? 'active' : ''; ?>"
                                    href="finance_discount_awards.php"><i class="fas fa-tags text-warning me-2"></i>طلبات خصومات الطلاب</a></li>

                            <li><hr class="dropdown-divider"></li>
                            <li class="px-3 pt-1 pb-1 text-uppercase text-muted small fw-bold">العاملون والرواتب</li>
                            <li><a class="nav-link <?php echo Utilities::getCurrentPage() == 'finance_staff_contracts.php' ? 'active' : ''; ?>"
                                    href="finance_staff_contracts.php"><i class="fas fa-file-signature text-info me-2"></i>عقود ورواتب العاملين</a></li>
                            <li><a class="nav-link <?php echo Utilities::getCurrentPage() == 'finance_payroll_runs.php' ? 'active' : ''; ?>"
                                    href="finance_payroll_runs.php"><i class="fas fa-calendar-alt text-primary me-2"></i>دورات الرواتب</a></li>
                            <li><a class="nav-link <?php echo Utilities::getCurrentPage() == 'finance_payroll_items.php' ? 'active' : ''; ?>"
                                    href="finance_payroll_items.php"><i class="fas fa-list-check text-info me-2"></i>بنود وقسائم الرواتب</a></li>
                            <li><a class="nav-link <?php echo Utilities::getCurrentPage() == 'finance_payroll_payments.php' ? 'active' : ''; ?>"
                                    href="finance_payroll_payments.php"><i class="fas fa-money-check-alt text-success me-2"></i>مدفوعات الرواتب</a></li>
                            <li><a class="nav-link <?php echo Utilities::getCurrentPage() == 'finance_staff_advances.php' ? 'active' : ''; ?>"
                                    href="finance_staff_advances.php"><i class="fas fa-hand-holding-usd text-warning me-2"></i>سلف العاملين</a></li>
                            <li><a class="nav-link <?php echo Utilities::getCurrentPage() == 'finance_staff_ledger.php' ? 'active' : ''; ?>"
                                    href="finance_staff_ledger.php"><i class="fas fa-book text-primary me-2"></i>السجل المالي للعاملين</a></li>

                            <li><hr class="dropdown-divider"></li>
                            <li class="px-3 pt-1 pb-1 text-uppercase text-muted small fw-bold">المحاسبة والرقابة</li>
                            <li><a class="nav-link <?php echo Utilities::getCurrentPage() == 'finance_vouchers.php' ? 'active' : ''; ?>"
                                    href="finance_vouchers.php"><i class="fas fa-file-invoice text-warning me-2"></i>المصروفات والإيرادات</a></li>
                            <li><a class="nav-link <?php echo Utilities::getCurrentPage() == 'finance_cashboxes.php' ? 'active' : ''; ?>"
                                    href="finance_cashboxes.php"><i class="fas fa-vault text-success me-2"></i>الصناديق والبنوك</a></li>
                            <li><a class="nav-link <?php echo Utilities::getCurrentPage() == 'finance_budgets.php' ? 'active' : ''; ?>"
                                    href="finance_budgets.php"><i class="fas fa-scale-balanced text-info me-2"></i>الموازنات</a></li>
                            <li><a class="nav-link <?php echo Utilities::getCurrentPage() == 'finance_journal.php' ? 'active' : ''; ?>"
                                    href="finance_journal.php"><i class="fas fa-book-open text-primary me-2"></i>دفتر اليومية والأستاذ</a></li>
                            <li><a class="nav-link <?php echo Utilities::getCurrentPage() == 'finance_reports.php' ? 'active' : ''; ?>"
                                    href="finance_reports.php"><i class="fas fa-chart-bar text-primary me-2"></i>التقارير المالية</a></li>
                            <li><a class="nav-link <?php echo Utilities::getCurrentPage() == 'finance_approvals.php' ? 'active' : ''; ?>"
                                    href="finance_approvals.php"><i class="fas fa-user-check text-success me-2"></i>طلبات الاعتماد</a></li>
                            <li><a class="nav-link <?php echo Utilities::getCurrentPage() == 'finance_periods.php' ? 'active' : ''; ?>"
                                    href="finance_periods.php"><i class="fas fa-calendar-check text-info me-2"></i>الفترات المالية</a></li>
                            <li><a class="nav-link <?php echo Utilities::getCurrentPage() == 'finance_import_export.php' ? 'active' : ''; ?>"
                                    href="finance_import_export.php"><i class="fas fa-file-import text-warning me-2"></i>الاستيراد والتصدير</a></li>
                            <li><a class="nav-link <?php echo Utilities::getCurrentPage() == 'finance_archive.php' ? 'active' : ''; ?>"
                                    href="finance_archive.php"><i class="fas fa-box-archive text-secondary me-2"></i>الأرشيف المالي</a></li>
                            <li><a class="nav-link <?php echo Utilities::getCurrentPage() == 'finance_audit_log.php' ? 'active' : ''; ?>"
                                    href="finance_audit_log.php"><i class="fas fa-clock-rotate-left text-danger me-2"></i>سجل العمليات المالية</a></li>

                            <li><hr class="dropdown-divider"></li>
                            <li class="px-3 pt-1 pb-1 text-uppercase text-muted small fw-bold">الصفحات الحالية</li>
                            <li><a class="nav-link <?php echo Utilities::getCurrentPage() == 'fee_structure.php' ? 'active' : ''; ?>"
                                    href="fee_structure.php"><i
                                        class="fas fa-file-invoice-dollar text-primary me-2"></i>المصاريف الدراسية</a>
                            </li>
                            <li><a class="nav-link <?php echo Utilities::getCurrentPage() == 'fee_calculator.php' ? 'active' : ''; ?>"
                                    href="fee_calculator.php"><i class="fas fa-calculator text-success me-2"></i>حاسبة
                                    المصروفات</a></li>
                             <li><a class="nav-link <?php echo Utilities::getCurrentPage() == 'fee_payments.php' ? 'active' : ''; ?>"
                                     href="fee_payments.php"><i class="fas fa-cash-register text-info me-2"></i>سداد
                                     الرسوم والمصاريف</a></li>
                            <li><a class="nav-link <?php echo Utilities::getCurrentPage() == 'staff_financial_data.php' ? 'active' : ''; ?>"
                                    href="staff_financial_data.php"><i class="fas fa-money-check-alt text-danger me-2"></i>مالية
                                    العاملين</a></li>
                        </ul>
                    </div>
                </li>
                <li class="sidebar-category-header" style="font-size: 0.72rem; font-weight: 700; color: #94a3b8; padding: 22px 15px 8px; display: flex; align-items: center; gap: 8px; pointer-events: none; text-transform: uppercase;">
                    <span style="width: 5px; height: 5px; background-color: #8b3dff; border-radius: 50%; display: inline-block;"></span>
                    <span>الإدارة والتهيئة العامة</span>
                </li>

                <!-- إدارة الحسابات و MS Teams -->
                <?php $isAccountsActive = in_array(Utilities::getCurrentPage(), ['school_settings.php', 'student_accounts.php', 'staff_accounts.php']); ?>
                <li class="nav-item">
                    <a class="nav-link <?php echo $isAccountsActive ? 'active' : 'collapsed'; ?>" href="#accountsMenu"
                        data-bs-toggle="collapse" aria-expanded="<?php echo $isAccountsActive ? 'true' : 'false'; ?>">
                        <i class="fas fa-user-shield nav-icon text-info"></i>
                        <span>إدارة الحسابات والصلاحيات</span>
                        <i class="fas fa-chevron-down ms-auto arrow-icon"></i>
                    </a>
                    <div class="collapse <?php echo $isAccountsActive ? 'show' : ''; ?>" id="accountsMenu">
                        <ul class="nav flex-column sub-menu">
                            <li><a class="nav-link <?php echo Utilities::getCurrentPage() == 'school_settings.php' ? 'active' : ''; ?>"
                                    href="school_settings.php"><i class="fas fa-envelope text-success me-2"></i>حسابات المدرسة (SMTP)</a>
                            </li>
                            <li><a class="nav-link <?php echo Utilities::getCurrentPage() == 'student_accounts.php' ? 'active' : ''; ?>"
                                    href="student_accounts.php"><i class="fas fa-user-graduate text-primary me-2"></i>حسابات الطلاب</a>
                            </li>
                            <li><a class="nav-link <?php echo Utilities::getCurrentPage() == 'staff_accounts.php' ? 'active' : ''; ?>"
                                    href="staff_accounts.php"><i class="fas fa-users-cog text-danger me-2"></i>حسابات العاملين</a>
                            </li>
                        </ul>
                    </div>
                </li>


                <!-- إعدادات النظام -->
                <?php $isSettingsActive = in_array(Utilities::getCurrentPage(), ['system_settings.php', 'academic_years.php', 'academic_year_setup.php', 'sql_backups.php', 'notifications.php', 'system_tools.php', 'activity_logs.php', 'data_versions.php', 'recycle_bin.php', 'manage_backups.php', 'ai_settings.php', 'canva_settings.php', 'canva_templates.php', 'lesson_ppt_templates.php', 'ui_settings.php', 'ui_preview.php', 'system_code_analytics.php']); ?>
                <li class="nav-item">
                    <a class="nav-link <?php echo $isSettingsActive ? 'active' : 'collapsed'; ?>" href="#settingsMenu"
                        data-bs-toggle="collapse" aria-expanded="<?php echo $isSettingsActive ? 'true' : 'false'; ?>">
                        <i class="fas fa-cogs nav-icon text-secondary"></i>
                        <span>إعدادات النظام</span>
                        <i class="fas fa-chevron-down ms-auto arrow-icon"></i>
                    </a>
                    <div class="collapse <?php echo $isSettingsActive ? 'show' : ''; ?>" id="settingsMenu">
                        <ul class="nav flex-column sub-menu">
                            <li><a class="nav-link <?php echo Utilities::getCurrentPage() == 'academic_years.php' ? 'active' : ''; ?>"
                                    href="academic_years.php"><i
                                        class="fas fa-calendar-alt text-success me-2"></i>الأعوام الدراسية</a></li>
                            <li><a class="nav-link <?php echo Utilities::getCurrentPage() == 'academic_year_setup.php' ? 'active' : ''; ?>"
                                    href="academic_year_setup.php"><i
                                        class="fas fa-calendar-plus text-warning me-2"></i>تهيئة عام جديد</a></li>
                            <li><a class="nav-link <?php echo Utilities::getCurrentPage() == 'sql_backups.php' ? 'active' : ''; ?>"
                                    href="sql_backups.php"><i
                                        class="fas fa-database text-danger me-2"></i>النسخ الاحتياطي (SQL)</a></li>
                            <li><a class="nav-link <?php echo Utilities::getCurrentPage() == 'notifications.php' ? 'active' : ''; ?>"
                                    href="notifications.php"><i class="fas fa-bell me-2"
                                        style="color: #fd7e14;"></i>التنبيهات والإشعارات</a></li>
                            <li><a class="nav-link <?php echo Utilities::getCurrentPage() == 'system_tools.php' ? 'active' : ''; ?>"
                                    href="system_tools.php"><i class="fas fa-toolbox text-danger me-2"></i>أدوات النظام</a></li>
                            <li><a class="nav-link <?php echo Utilities::getCurrentPage() == 'activity_logs.php' ? 'active' : ''; ?>"
                                    href="activity_logs.php"><i class="fas fa-clipboard-list me-2"
                                        style="color: #fd7e14;"></i>سجل النشاطات</a></li>
                            <li><a class="nav-link <?php echo Utilities::getCurrentPage() == 'data_versions.php' ? 'active' : ''; ?>"
                                    href="data_versions.php"><i class="fas fa-history text-primary me-2"></i>الإصدارات
                                    والتراجع</a></li>
                            <li><a class="nav-link <?php echo Utilities::getCurrentPage() == 'recycle_bin.php' ? 'active' : ''; ?>"
                                    href="recycle_bin.php"><i
                                        class="fas fa-trash-restore text-warning me-2"></i>المحذوفات المؤقتة</a></li>
                            <li><a class="nav-link <?php echo Utilities::getCurrentPage() == 'manage_backups.php' ? 'active' : ''; ?>"
                                    href="manage_backups.php"><i class="fas fa-database text-success me-2"></i>النسخ
                                    الاحتياطية</a></li>
                            <li><a class="nav-link <?php echo Utilities::getCurrentPage() == 'ai_settings.php' ? 'active' : ''; ?>"
                                    href="ai_settings.php"><i class="fas fa-brain text-purple me-2"></i>إعدادات الذكاء
                                    الاصطناعي</a></li>
                            <li><a class="nav-link <?php echo Utilities::getCurrentPage() == 'canva_settings.php' ? 'active' : ''; ?>"
                                    href="canva_settings.php"><i class="fas fa-palette me-2" style="color:#8b3dff;"></i>تكامل Canva</a></li>
                            <li><a class="nav-link <?php echo Utilities::getCurrentPage() == 'canva_templates.php' ? 'active' : ''; ?>"
                                    href="canva_templates.php"><i class="fas fa-layer-group me-2" style="color:#8b3dff;"></i>قوالب تصاميم Canva</a></li>
                            <li><a class="nav-link <?php echo Utilities::getCurrentPage() == 'lesson_ppt_templates.php' ? 'active' : ''; ?>"
                                    href="lesson_ppt_templates.php"><i class="fas fa-file-powerpoint text-danger me-2"></i>قوالب PowerPoint التعليمية</a></li>
                            <li><a class="nav-link <?php echo Utilities::getCurrentPage() == 'ui_settings.php' ? 'active' : ''; ?>"
                                    href="ui_settings.php"><i class="fas fa-sliders-h me-2" style="color: #3b82f6;"></i>التحكم في مظهر النظام</a></li>
                            <li><a class="nav-link <?php echo Utilities::getCurrentPage() == 'ui_preview.php' ? 'active' : ''; ?>"
                                    href="ui_preview.php"><i class="fas fa-palette text-primary me-2"></i>معاينة التنسيق الموحد</a></li>
                            <li><a class="nav-link <?php echo Utilities::getCurrentPage() == 'system_code_analytics.php' ? 'active' : ''; ?>"
                                    href="system_code_analytics.php"><i class="fas fa-code text-info me-2"></i>إحصائيات الكود البرمجي</a></li>
                        </ul>
                    </div>
                </li>

            </ul>
        </div>
    </aside>
    <?php endif; ?>

    <?php if (is_array($__adminAllowedPages)): ?>
    <script>
        document.addEventListener('DOMContentLoaded', function () {
            var allowedPages = <?php echo json_encode(array_values($__adminAllowedPages), JSON_UNESCAPED_UNICODE); ?>;
            var allowedSet = new Set(allowedPages);
            document.querySelectorAll('#sidebarNavAccordion a.nav-link[href]').forEach(function (link) {
                var href = (link.getAttribute('href') || '').trim();
                if (!href || href.charAt(0) === '#' || href.indexOf('javascript:') === 0) {
                    return;
                }
                var clean = href.split('?')[0].split('#')[0].split('/').pop();
                if (clean && !allowedSet.has(clean)) {
                    var item = link.closest('li');
                    if (item) {
                        item.remove();
                    }
                }
            });
            document.querySelectorAll('#sidebarNavAccordion .collapse').forEach(function (menu) {
                if (!menu.querySelector('a.nav-link[href]:not([href^="#"])')) {
                    var parentItem = menu.closest('li.nav-item');
                    if (parentItem) {
                        parentItem.remove();
                    }
                }
            });
            // Remove empty category headers
            document.querySelectorAll('#sidebarNavAccordion .sidebar-category-header').forEach(function (header) {
                var next = header.nextElementSibling;
                var hasAnyItems = false;
                while (next && !next.classList.contains('sidebar-category-header')) {
                    if (next.classList.contains('nav-item') && !next.classList.contains('toggle-nav-item')) {
                        hasAnyItems = true;
                        break;
                    }
                    next = next.nextElementSibling;
                }
                if (!hasAnyItems) {
                    header.remove();
                }
            });
        });
    </script>
    <?php endif; ?>

    <?php
    // التحقق من حالة النظام وعرض تنبيه إذا كان معطلاً
    try {
        require_once __DIR__ . '/../classes/utilities.php';
        $evaluation_check = Utilities::areEvaluationsAllowed($db ?? null);
        if (!$evaluation_check['allowed'] && $evaluation_check['reason'] == 'disabled'):
            ?>
            <div class="alert alert-danger alert-dismissible fade show mb-0"
                style="border-radius: 0; position: sticky; top: 60px; z-index: 1019;" role="alert">
                <div class="container-fluid">
                    <strong><i class="fas fa-exclamation-triangle me-2"></i>تنبيه:</strong>
                    نظام التقييمات معطل حالياً من قبل الإدارة. لن يتمكن المعلمون والأخصائيون من إعطاء أي تقييمات.
                    <?php if (Utilities::roleCanAccessAdminPage((string) ($_SESSION['role'] ?? ''), 'evaluation_settings.php')): ?>
                        <a href="<?php echo strpos($_SERVER['PHP_SELF'], '/admin/') !== false ? '' : 'admin/'; ?>evaluation_settings.php"
                            class="alert-link">اذهب للإعدادات</a>
                    <?php endif; ?>
                    <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="إغلاق"></button>
                </div>
            </div>
        <?php
        endif;
    } catch (Exception $e) {
        // لا تفعل شيء في حالة الخطأ
    }
    ?>

    <!-- مسح الكاش وتحسين الأداء -->
    <script src="<?php echo asset_url('../assets/js/no-cache.js'); ?>"></script>

    <!-- Main Content Container -->
    <div class="main-content" id="mainContent">
        <div class="container-fluid">
            <div class="row">
                <main class="col-12 px-md-3 py-3">
                    <?php if (!isset($custom_page_title) || !$custom_page_title): ?>
                        <div
                            class="d-flex justify-content-between flex-wrap flex-md-nowrap align-items-center pb-2 mb-3 border-bottom">
                            <h1 class="h3 text-gray-800"><?php echo isset($page_title) ? $page_title : 'لوحة التحكم'; ?>
                            </h1>
                        </div>
                    <?php endif; ?>
