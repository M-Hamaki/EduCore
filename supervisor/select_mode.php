<?php
/**
 * صفحة اختيار وضع الدخول للمشرف
 * المشرف يمكنه الدخول كمعلم أو عبر بوابة إشراف مستقلة.
 */
require_once '../config/database.php';
require_once '../classes/utilities.php';
require_once '../includes/session_config.php';
require_once '../includes/csrf.php';
require_once '../src/Modules/Operations/Audit/AuditService.php';

// Start session
if (session_status() == PHP_SESSION_NONE) {
    session_start();
}

// التحقق من تسجيل الدخول وصلاحيات المشرف (دور supervisor أو معلم مع is_supervisor)
if (!isset($_SESSION['user_id']) || !Utilities::isSupervisor()) {
    header('Location: ../login.php');
    exit;
}

function recordSupervisorModeSwitch(string $mode): void
{
    $db = Database::getInstance();
    (new \EduCore\Modules\Operations\Audit\AuditService($db))->recordEvent(
        'session_mode_switched',
        'session_mode',
        (int) $_SESSION['user_id'],
        (string) ($_SESSION['name'] ?? ''),
        [
            'mode_before' => $_SESSION['active_mode'] ?? null,
            'mode_after' => $mode,
            'persistent_data_changed' => false,
        ]
    );
}

// التبديل المباشر عبر GET (من أزرار التبديل السريع)
if (isset($_GET['switch']) && in_array($_GET['switch'], ['teacher', 'supervisor'])) {
    requireCsrfToken($_GET['csrf_token'] ?? '');
    recordSupervisorModeSwitch((string) $_GET['switch']);
    $_SESSION['active_mode'] = $_GET['switch'];
    if ($_GET['switch'] === 'teacher') {
        header('Location: ../teacher/portal.php');
    } else {
        header('Location: index.php');
    }
    exit;
}

// معالجة اختيار الوضع عبر POST (من صفحة الاختيار)
requireCsrfPost();
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['mode'])) {
    $mode = $_POST['mode'];
    if (in_array($mode, ['teacher', 'supervisor'])) {
        recordSupervisorModeSwitch((string) $mode);
        $_SESSION['active_mode'] = $mode;
        
        if ($mode === 'teacher') {
            header('Location: ../teacher/portal.php');
        } else {
            header('Location: index.php');
        }
        exit;
    }
}

// Asset helper for cache-busting
if (!function_exists('asset_url')) {
    require_once '../includes/template_helper.php';
}

// جلب الخدمات المتاحة للمعلم من إعدادات المراحل
$teacher_id = $_SESSION['user_id'];
$allowed_teacher_services = [];
$has_stage_config = false;
try {
    $db = Database::getInstance();
    
    // Check per-user override first
    $usq = $db->prepare("SELECT services, override_stage FROM user_service_overrides WHERE user_id = ? AND override_stage = 1");
    $usq->execute([$teacher_id]);
    $user_override = $usq->fetch(PDO::FETCH_ASSOC);
    
    // Get from stage settings
    $query = "SELECT DISTINCT s.teacher_services 
              FROM stages s 
              INNER JOIN grades g ON s.id = g.stage_id 
              INNER JOIN classes c ON g.id = c.grade_id 
              INNER JOIN user_class_access uca ON c.id = uca.class_id 
              WHERE uca.user_id = ? AND s.status = 'active'";
    $stmt = $db->prepare($query);
    $stmt->execute([$teacher_id]);
    $stage_rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    foreach ($stage_rows as $row) {
        if (!empty($row['teacher_services'])) {
            $services = json_decode($row['teacher_services'], true);
            if (is_array($services)) {
                $allowed_teacher_services = array_merge($allowed_teacher_services, $services);
            }
        }
    }
    $allowed_teacher_services = array_unique($allowed_teacher_services);
    $has_stage_config = !empty($stage_rows);
    
    if ($user_override && $user_override['override_stage']) {
        $override_services = json_decode($user_override['services'], true);
        if (is_array($override_services)) {
            $allowed_teacher_services = $override_services;
            $has_stage_config = true;
        }
    }
} catch (Exception $e) {
    error_log("select_mode: Error fetching teacher services: " . $e->getMessage());
}

// خريطة الخدمات مع أسمائها وأيقوناتها
$teacher_service_map = [
    'rewards'     => ['name' => 'نظام المكافآت ورصد النقاط',   'icon' => 'fas fa-award'],
    'lesson_prep' => ['name' => 'تحضير الدروس والاختبارات',     'icon' => 'fas fa-robot'],
    'grade_system'=> ['name' => 'نظام رصد الدرجات',             'icon' => 'fas fa-graduation-cap'],
    'attendance'  => ['name' => 'الحضور والغياب',               'icon' => 'fas fa-clipboard-check'],
    'timetable'   => ['name' => 'الجدول المدرسي',               'icon' => 'fas fa-calendar-alt'],
    'training'    => ['name' => 'التطوير المهني والتدريبات',     'icon' => 'fas fa-chalkboard-teacher'],
];

// تحديد الخدمات المعروضة
if ($has_stage_config) {
    $visible_teacher_services = array_intersect_key($teacher_service_map, array_flip($allowed_teacher_services));
} else {
    $visible_teacher_services = $teacher_service_map; // لا يوجد تهيئة = كل الخدمات
}

// خدمات المشرف المستقلة عن صلاحيات الأخصائي.
$supervisor_services = [
    ['name' => 'إدارة ومتابعة الطلاب',     'icon' => 'fas fa-users'],
    ['name' => 'الإحصائيات والتحليلات',     'icon' => 'fas fa-chart-line'],
    ['name' => 'التقارير التفصيلية',         'icon' => 'fas fa-file-alt'],
    ['name' => 'متابعة التقييمات والأداء',   'icon' => 'fas fa-tasks'],
];
?>
<!DOCTYPE html>
<html lang="ar" dir="rtl">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>اختيار وضع الدخول - نظام الإدارة المدرسية</title>
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.rtl.min.css">
    <link href="https://fonts.googleapis.com/css2?family=Cairo:wght@300;400;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css">
    <link rel="stylesheet" href="<?php echo asset_url('../assets/css/style.css'); ?>">
    <style>
        body {
            background: #f8fafc;
            min-height: 100vh;
            font-family: 'Cairo', 'Tajawal', sans-serif;
            padding: 0;
            margin: 0;
            color: #1e293b;
        }
        
        #particles-js {
            position: fixed !important;
            width: 100% !important;
            height: 100% !important;
            top: 0; left: 0;
            z-index: -1 !important;
            background: #fafbfc;
            pointer-events: auto !important;
        }

        /* Top Fixed Buttons */
        .top-fixed-buttons {
            position: fixed;
            top: 20px;
            left: 20px;
            display: flex;
            align-items: center;
            gap: 12px;
            z-index: 1000;
        }
        .logout-button-top {
            background: linear-gradient(135deg, #e74c3c 0%, #c0392b 100%);
            color: white;
            padding: 12px 24px;
            border-radius: 10px;
            text-decoration: none;
            font-weight: 600;
            box-shadow: 0 4px 15px rgba(231, 76, 60, 0.4);
            transition: all 0.3s ease;
            display: flex;
            align-items: center;
            gap: 8px;
        }
        .logout-button-top:hover {
            transform: translateY(-2px);
            box-shadow: 0 6px 20px rgba(231, 76, 60, 0.5);
            color: white;
        }

        /* Logo Section */
        .portal-logo-section {
            text-align: center;
            padding: 2.5rem 1rem 0.5rem;
            position: relative;
            z-index: 10;
        }
        .portal-school-logo {
            max-width: 180px;
            height: auto;
            filter: drop-shadow(0 4px 12px rgba(0,0,0,0.3));
            animation: fadeInDown 0.8s ease-out;
            margin-bottom: 1rem;
        }
        @keyframes fadeInDown {
            from { opacity: 0; transform: translateY(-20px); }
            to { opacity: 1; transform: translateY(0); }
        }

        /* Welcome Section */
        .portal-title-text {
            text-align: center;
            padding: 0 1rem 1rem;
            position: relative;
            z-index: 10;
        }
        .portal-main-title-text {
            font-size: 2.2rem;
            font-weight: 800;
            color: #1e293b;
            text-shadow: 0 2px 4px rgba(0,0,0,0.1);
            margin: 0 0 0.3rem;
        }
        .portal-subtitle-text {
            font-size: 1.2rem;
            color: #334155;
            font-weight: 500;
            margin: 0;
        }

        /* Main Container */
        .mode-container {
            max-width: 900px;
            margin: 0 auto 3rem;
            padding: 0 1.5rem;
            position: relative;
            z-index: 10;
        }

        /* Mode Cards - same style as teacher portal nav-button */
        .mode-card {
            background: white;
            color: #1e293b;
            padding: 40px 30px;
            border-radius: 15px;
            text-decoration: none;
            transition: all 0.3s ease;
            box-shadow: 0 4px 15px rgba(0,0,0,0.08), 0 2px 6px rgba(0,0,0,0.12);
            display: flex;
            flex-direction: column;
            align-items: center;
            text-align: center;
            position: relative;
            overflow: hidden;
            border: 2px solid rgba(0,0,0,0.06);
            cursor: pointer;
            height: 100%;
        }
        .mode-card::before {
            content: '';
            position: absolute;
            top: 0; left: 0; right: 0;
            height: 4px;
            transform: scaleX(0);
            transition: transform 0.3s ease;
            transform-origin: left;
        }
        .teacher-mode::before {
            background: linear-gradient(90deg, #667eea, #3b82f6);
        }
        .supervisor-mode::before {
            background: linear-gradient(90deg, #8b5cf6, #a855f7);
        }
        .mode-card:hover::before {
            transform: scaleX(1);
        }
        .mode-card:hover {
            transform: translateY(-8px);
            box-shadow: 0 12px 30px rgba(0,0,0,0.15);
        }
        .mode-card:active {
            transform: translateY(-4px);
        }

        /* Icon */
        .mode-icon {
            width: 90px;
            height: 90px;
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            margin-bottom: 1.5rem;
            font-size: 2.2rem;
            color: #fff;
            transition: transform 0.3s ease;
        }
        .mode-card:hover .mode-icon {
            transform: scale(1.1);
        }
        .teacher-mode .mode-icon {
            background: linear-gradient(135deg, #667eea, #3b82f6);
        }
        .supervisor-mode .mode-icon {
            background: linear-gradient(135deg, #8b5cf6, #7c3aed);
        }

        /* Card Content */
        .mode-card h4 {
            font-size: 1.4rem;
            font-weight: 700;
            margin-bottom: 0.5rem;
            color: #1e293b;
        }
        .mode-card .mode-desc {
            color: #64748b;
            font-size: 0.95rem;
            margin-bottom: 1.5rem;
        }
        .mode-features {
            text-align: right;
            list-style: none;
            padding: 0;
            margin: 0;
            width: 100%;
        }
        .mode-features li {
            padding: 0.4rem 0;
            font-size: 0.9rem;
            color: #4b5563;
            display: flex;
            align-items: center;
        }
        .mode-features li i {
            margin-left: 0.5rem;
            width: 20px;
            text-align: center;
            font-size: 0.8rem;
        }
        .teacher-mode .mode-features li i { color: #667eea; }
        .supervisor-mode .mode-features li i { color: #8b5cf6; }

        /* Footer */
        .portal-footer {
            background: #1e293b;
            color: white;
            padding: 2rem 0 1rem;
            margin-top: 3rem;
            border-top: 3px solid rgba(102,126,234,0.3);
            position: relative;
            z-index: 10;
        }
        .portal-footer p {
            margin: 0 0 1rem;
            color: rgba(255,255,255,0.9);
            font-size: 1rem;
        }

        /* Theme Toggle Button - Bottom Right */
        .theme-toggle {
            position: fixed;
            bottom: 20px;
            right: 20px;
            width: 50px;
            height: 50px;
            border-radius: 50%;
            border: none;
            background: white;
            color: #667eea;
            font-size: 1.3rem;
            cursor: pointer;
            box-shadow: 0 4px 15px rgba(0, 0, 0, 0.2);
            transition: all 0.3s ease;
            z-index: 1000;
            display: flex;
            align-items: center;
            justify-content: center;
        }
        .theme-toggle:hover {
            transform: scale(1.1);
        }

        /* Social Media Footer */
        .social-media-footer {
            display: flex;
            justify-content: center;
            gap: 15px;
            margin-top: 1.5rem;
            margin-bottom: 0.5rem;
        }
        .social-footer-icon {
            width: 48px;
            height: 48px;
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            color: white;
            text-decoration: none;
            font-size: 1.3rem;
            transition: all 0.3s ease;
            box-shadow: 0 4px 12px rgba(0, 0, 0, 0.3);
        }
        .social-footer-icon:hover {
            transform: translateY(-4px) scale(1.1);
            box-shadow: 0 8px 20px rgba(0, 0, 0, 0.4);
            color: white;
        }
        .social-footer-icon.facebook {
            background: linear-gradient(135deg, #1877f2 0%, #0c63d4 100%);
        }
        .social-footer-icon.facebook:hover {
            box-shadow: 0 8px 20px rgba(24, 119, 242, 0.6);
        }
        .social-footer-icon.whatsapp {
            background: linear-gradient(135deg, #25d366 0%, #128c7e 100%);
        }
        .social-footer-icon.whatsapp:hover {
            box-shadow: 0 8px 20px rgba(37, 211, 102, 0.6);
        }
        .social-footer-icon.instagram {
            background: linear-gradient(135deg, #e1306c 0%, #c13584 50%, #833ab4 100%);
        }
        .social-footer-icon.instagram:hover {
            box-shadow: 0 8px 20px rgba(225, 48, 108, 0.6);
        }

        /* ========== Dark Mode ========== */
        body.dark-mode {
            background: #0f172a;
            color: #f1f5f9;
        }
        body.dark-mode .portal-main-title-text {
            color: #f1f5f9;
            text-shadow: 0 2px 4px rgba(0,0,0,0.3);
        }
        body.dark-mode .portal-subtitle-text {
            color: #cbd5e1;
        }
        body.dark-mode .mode-card {
            background: #1e293b;
            color: #f1f5f9;
            border-color: #334155;
            box-shadow: 0 4px 15px rgba(0,0,0,0.3);
        }
        body.dark-mode .mode-card:hover {
            box-shadow: 0 12px 30px rgba(0,0,0,0.4);
        }
        body.dark-mode .mode-card h4 {
            color: #f1f5f9;
        }
        body.dark-mode .mode-card .mode-desc {
            color: #94a3b8;
        }
        body.dark-mode .mode-features li {
            color: #cbd5e1;
        }
        body.dark-mode .portal-footer {
            background: #0f172a;
            border-top-color: rgba(102,126,234,0.2);
        }
        body.dark-mode .portal-footer p {
            color: rgba(255,255,255,0.8);
        }
        body.dark-mode .logout-button-top {
            background: linear-gradient(135deg, #dc2626 0%, #991b1b 100%);
        }
        body.dark-mode .theme-toggle {
            background: #334155;
            color: #fbbf24;
            box-shadow: 0 4px 15px rgba(0,0,0,0.3);
        }
        body.dark-mode .social-footer-icon {
            box-shadow: 0 4px 12px rgba(0, 0, 0, 0.5);
        }
        body.dark-mode .social-footer-icon:hover {
            box-shadow: 0 8px 20px rgba(0, 0, 0, 0.6);
        }

        /* Mobile */
        @media (max-width: 768px) {
            .portal-school-logo { max-width: 130px; }
            .portal-main-title-text { font-size: 1.7rem; }
            .portal-subtitle-text { font-size: 1rem; }
            .mode-card { padding: 30px 20px; }
            .mode-icon { width: 70px; height: 70px; font-size: 1.8rem; }
            .mode-card h4 { font-size: 1.2rem; }
            .logout-button-top { padding: 10px 18px; font-size: 0.9rem; }
        }
        @media (max-width: 480px) {
            .portal-school-logo { max-width: 100px; }
            .portal-main-title-text { font-size: 1.4rem; }
            .mode-card { padding: 25px 15px; }
            .logout-button-top { padding: 8px 14px; font-size: 0.85rem; top: 10px; left: 10px; }
        }
    </style>
</head>
<body>
    <div id="particles-js"></div>

    <!-- Top Fixed Buttons -->
    <div class="top-fixed-buttons">
        <a href="../logout.php" class="logout-button-top">
            <i class="fas fa-sign-out-alt"></i>
            <span>خروج</span>
        </a>
    </div>

    <!-- Theme Toggle - Bottom Right -->
    <button class="theme-toggle" id="themeToggle" title="تبديل الوضع الداكن/الفاتح">
        <i class="fas fa-moon"></i>
    </button>

    <!-- School Logo -->
    <div class="portal-logo-section">
        <?php if (!function_exists('get_school_logo')) { require_once __DIR__ . '/../includes/template_helper.php'; } ?>
        <img src="<?php echo get_school_logo('../'); ?>" alt="شعار المدرسة" class="portal-school-logo">
    </div>

    <!-- Title -->
    <div class="portal-title-text">
        <h1 class="portal-main-title-text">مرحباً <?php echo htmlspecialchars($_SESSION['name'] ?? 'مشرف'); ?> 👋</h1>
        <p class="portal-subtitle-text">اختر وضع الدخول المناسب لك</p>
    </div>

    <!-- Mode Cards -->
    <div class="mode-container">
        <div class="row g-4">
            <!-- وضع المعلم -->
            <div class="col-md-6">
                <form method="POST">
                    <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($_SESSION['csrf_token'], ENT_QUOTES, 'UTF-8'); ?>">
                    <input type="hidden" name="mode" value="teacher">
                    <button type="submit" class="mode-card teacher-mode w-100 border-0">
                        <div class="mode-icon">
                            <i class="fas fa-chalkboard-teacher"></i>
                        </div>
                        <h4>الدخول كمعلم</h4>
                        <p class="mode-desc">الوصول لبوابة المعلم وأدوات التقييم</p>
                        <ul class="mode-features">
                            <?php foreach ($visible_teacher_services as $svc): ?>
                            <li><i class="fas fa-check-circle"></i> <?php echo htmlspecialchars($svc['name']); ?></li>
                            <?php endforeach; ?>
                            <?php if (empty($visible_teacher_services)): ?>
                            <li><i class="fas fa-info-circle"></i> لا توجد خدمات مفعلة حالياً</li>
                            <?php endif; ?>
                        </ul>
                    </button>
                </form>
            </div>
            
            <!-- وضع المشرف -->
            <div class="col-md-6">
                <form method="POST">
                    <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($_SESSION['csrf_token'], ENT_QUOTES, 'UTF-8'); ?>">
                    <input type="hidden" name="mode" value="supervisor">
                    <button type="submit" class="mode-card supervisor-mode w-100 border-0">
                        <div class="mode-icon">
                            <i class="fas fa-user-shield"></i>
                        </div>
                        <h4>الدخول كمشرف</h4>
                        <p class="mode-desc">الوصول لأدوات الإشراف والمتابعة</p>
                        <ul class="mode-features">
                            <?php foreach ($supervisor_services as $svc): ?>
                            <li><i class="fas fa-check-circle"></i> <?php echo htmlspecialchars($svc['name']); ?></li>
                            <?php endforeach; ?>
                        </ul>
                    </button>
                </form>
            </div>
        </div>
    </div>

    <!-- Footer -->
    <footer class="portal-footer">
        <div class="container text-center">
            <p>جميع الحقوق محفوظة © <?php echo date('Y'); ?><br>Delta Modern Language Schools<br>Computer Department</p>
            <div class="social-media-footer">
                <a href="https://www.facebook.com/DELTA.MLS" target="_blank" rel="noopener noreferrer" class="social-footer-icon facebook" title="Facebook">
                    <i class="fab fa-facebook-f"></i>
                </a>
                <a href="https://wa.me/201289999818" target="_blank" rel="noopener noreferrer" class="social-footer-icon whatsapp" title="WhatsApp">
                    <i class="fab fa-whatsapp"></i>
                </a>
                <a href="https://www.instagram.com/delta.mls" target="_blank" rel="noopener noreferrer" class="social-footer-icon instagram" title="Instagram">
                    <i class="fab fa-instagram"></i>
                </a>
            </div>
        </div>
    </footer>
    
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
    <script src="https://cdn.jsdelivr.net/particles.js/2.0.0/particles.min.js"></script>
    <script>
    // Initialize particles
    particlesJS('particles-js', {
        "particles": {
            "number": { "value": 60, "density": { "enable": true, "value_area": 800 } },
            "color": { "value": "#667eea" },
            "shape": { "type": "circle" },
            "opacity": { "value": 0.3, "random": false },
            "size": { "value": 3, "random": true },
            "line_linked": { "enable": true, "distance": 150, "color": "#667eea", "opacity": 0.2, "width": 1 },
            "move": { "enable": true, "speed": 2, "direction": "none", "random": false, "straight": false, "out_mode": "out" }
        },
        "interactivity": {
            "detect_on": "canvas",
            "events": { "onhover": { "enable": true, "mode": "repulse" }, "onclick": { "enable": true, "mode": "push" }, "resize": true },
            "modes": { "repulse": { "distance": 100, "duration": 0.4 }, "push": { "particles_nb": 2 } }
        },
        "retina_detect": true
    });

    // Update particles theme colors
    function updateParticlesTheme(theme) {
        var particlesContainer = document.getElementById('particles-js');
        if (!particlesContainer) return;
        if (theme === 'dark') {
            particlesContainer.style.background = '#0f1419';
        } else {
            particlesContainer.style.background = '#fafbfc';
        }
        if (window.pJSDom && window.pJSDom[0] && window.pJSDom[0].pJS) {
            var particleColor = theme === 'dark' ? '#6366f1' : '#4f46e5';
            window.pJSDom[0].pJS.particles.color.value = particleColor;
            window.pJSDom[0].pJS.particles.line_linked.color = particleColor;
        }
    }

    // Dark mode toggle
    (function() {
        var themeToggle = document.getElementById('themeToggle');
        var savedTheme = localStorage.getItem('theme');
        if (savedTheme === 'dark') {
            document.body.classList.add('dark-mode');
            themeToggle.innerHTML = '<i class="fas fa-sun"></i>';
        } else {
            document.body.classList.add('light-mode');
            themeToggle.innerHTML = '<i class="fas fa-moon"></i>';
        }
        updateParticlesTheme(savedTheme || 'light');

        themeToggle.addEventListener('click', function() {
            document.body.classList.toggle('dark-mode');
            var isDark = document.body.classList.contains('dark-mode');
            if (isDark) {
                document.body.classList.remove('light-mode');
                themeToggle.innerHTML = '<i class="fas fa-sun"></i>';
                localStorage.setItem('theme', 'dark');
            } else {
                document.body.classList.add('light-mode');
                themeToggle.innerHTML = '<i class="fas fa-moon"></i>';
                localStorage.setItem('theme', 'light');
            }
            updateParticlesTheme(isDark ? 'dark' : 'light');
        });
    })();
    </script>
</body>
</html>
