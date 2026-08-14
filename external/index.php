<?php
/**
 * لوحة تحكم المعلم الخارجي
 * External Teacher Dashboard
 */
require_once '../includes/session_config.php';
require_once '../config/database.php';

// التحقق من تسجيل دخول المعلم الخارجي
if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'external_teacher') {
    header('Location: ../external_login.php');
    exit;
}

$database = new Database();
$db = $database->getConnection();

// جلب بيانات المعلم
$stmt = $db->prepare("SELECT * FROM external_teachers WHERE id = ? AND status = 'active'");
$stmt->execute([$_SESSION['user_id']]);
$teacher = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$teacher) {
    // الحساب محذوف أو معطل
    session_destroy();
    header('Location: ../external_login.php');
    exit;
}

// جلب الخدمات المتاحة
$svcStmt = $db->prepare("SELECT setting_value FROM settings WHERE setting_key = 'external_teacher_services'");
$svcStmt->execute();
$allowed_services = json_decode($svcStmt->fetchColumn() ?: '[]', true);

// جلب اسم المدرسة
$schoolStmt = $db->prepare("SELECT setting_value FROM settings WHERE setting_key = 'school_name'");
$schoolStmt->execute();
$school_name = $schoolStmt->fetchColumn() ?: 'EduCore';

// قائمة جميع الخدمات الممكنة
$all_services = [
    'lesson_prep' => [
        'label' => 'تحضير الدروس بالذكاء الاصطناعي',
        'icon' => 'fas fa-robot',
        'color' => '#3b82f6',
        'gradient' => 'linear-gradient(135deg, #3b82f6, #6366f1)',
        'desc' => 'أنشئ تحضيرات دروس احترافية باستخدام الذكاء الاصطناعي',
        'url' => '../teacher/lesson_welcome.php'
    ],
    'training' => [
        'label' => 'التطوير المهني',
        'icon' => 'fas fa-award',
        'color' => '#f59e0b',
        'gradient' => 'linear-gradient(135deg, #f59e0b, #d97706)',
        'desc' => 'دورات تدريبية وبرامج تطوير مهني',
        'url' => '../teacher/training.php'
    ],
];

// تصفية الخدمات المسموح بها فقط
$visible_services = [];
foreach ($allowed_services as $key) {
    if (isset($all_services[$key])) {
        $visible_services[$key] = $all_services[$key];
    }
}
?>
<!DOCTYPE html>
<html lang="ar" dir="rtl">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>بوابة المعلم - <?php echo htmlspecialchars($school_name); ?></title>
    <meta http-equiv="Cache-Control" content="no-cache, no-store, must-revalidate">
    <meta http-equiv="Pragma" content="no-cache">
    <meta http-equiv="Expires" content="0">

    <!-- Bootstrap RTL CSS -->
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.rtl.min.css">
    <!-- Font Awesome -->
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <!-- Google Fonts -->
    <link href="https://fonts.googleapis.com/css2?family=Cairo:wght@300;400;600;700&display=swap" rel="stylesheet">

    <style>
        * { font-family: 'Cairo', 'Tajawal', sans-serif; }

        body {
            padding-top: 0 !important;
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            min-height: 100vh;
            margin: 0;
        }
        body.dark-mode {
            background: linear-gradient(135deg, #1e3a8a 0%, #4c1d95 100%);
        }

        /* Particles Background */
        #particles-js {
            position: fixed;
            top: 0; left: 0; right: 0; bottom: 0;
            z-index: 0;
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
        body.dark-mode .logout-button-top {
            background: linear-gradient(135deg, #dc2626 0%, #991b1b 100%);
            box-shadow: 0 4px 15px rgba(220, 38, 38, 0.5);
        }

        /* Theme Toggle - Bottom Right */
        .theme-toggle {
            position: fixed;
            bottom: 30px;
            left: 30px !important;
            right: auto !important;
            z-index: 1001;
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
        }
        body.dark-mode .theme-toggle {
            background: #334155;
            color: #fbbf24;
        }
        .theme-toggle:hover { transform: scale(1.1); }

        /* School Logo */
        .portal-logo-section {
            text-align: center;
            padding: 2rem 1rem 0.5rem;
            position: relative;
            z-index: 10;
        }
        .portal-school-logo {
            max-width: 200px;
            height: auto;
            filter: drop-shadow(0 4px 12px rgba(0, 0, 0, 0.3));
            animation: fadeInDown 0.8s ease-out;
            margin-bottom: 1.5rem;
        }
        @keyframes fadeInDown {
            from { opacity: 0; transform: translateY(-20px); }
            to { opacity: 1; transform: translateY(0); }
        }

        /* Portal Title */
        .portal-title-text {
            text-align: center;
            padding: 0 1rem 2rem;
            position: relative;
            z-index: 10;
        }
        .portal-main-title-text {
            font-size: 2.5rem;
            font-weight: 800;
            color: #1e293b;
            text-shadow: 0 2px 4px rgba(0, 0, 0, 0.1);
            margin: 0 0 0.5rem 0;
            letter-spacing: 1px;
        }
        body.dark-mode .portal-main-title-text {
            color: #f1f5f9;
            text-shadow: 0 2px 8px rgba(0, 0, 0, 0.3);
        }

        /* Container */
        .container {
            max-width: 1200px;
            margin: 0 auto 3rem;
            padding: 0 1rem;
            position: relative;
            z-index: 10;
        }

        /* Welcome Card */
        .portal-welcome-card {
            background: white;
            color: #1e293b;
            padding: 40px 30px;
            border-radius: 15px;
            text-decoration: none;
            transition: all 0.3s ease;
            box-shadow: 0 4px 15px rgba(0, 0, 0, 0.1), 0 2px 8px rgba(0, 0, 0, 0.1);
            text-align: center;
            margin: 0 auto 2rem;
            max-width: 800px;
            position: relative;
            overflow: hidden;
            border: 2px solid rgba(102, 126, 234, 0.3);
        }
        .portal-welcome-card::before {
            content: '';
            position: absolute;
            top: 0; left: 0; right: 0;
            height: 4px;
            background: linear-gradient(90deg, #667eea, #764ba2);
            transform: scaleX(0);
            transition: transform 0.3s ease;
            transform-origin: left;
        }
        .portal-welcome-card:hover::before { transform: scaleX(1); }

        body.dark-mode .portal-welcome-card {
            background: #1e293b;
            color: #f1f5f9;
            border: 1px solid #334155;
            box-shadow: 0 4px 15px rgba(0, 0, 0, 0.3), 0 2px 4px rgba(0, 0, 0, 0.2);
        }

        .portal-welcome-title {
            font-size: 2rem;
            color: #1e293b;
            margin: 0 0 1rem 0;
            font-weight: 700;
        }
        body.dark-mode .portal-welcome-title { color: #f1f5f9; }

        .portal-welcome-icon {
            margin-left: 0.5rem;
            font-size: 2rem;
            color: #FFC107;
            animation: wave 2s ease-in-out infinite;
            filter: drop-shadow(0 2px 4px rgba(255, 193, 7, 0.3));
        }
        @keyframes wave {
            0%, 100% { transform: rotate(0deg); }
            25% { transform: rotate(20deg); }
            75% { transform: rotate(-20deg); }
        }

        .portal-teacher-name {
            font-size: 1.6rem;
            font-weight: 600;
            color: #1e293b;
            margin: 0 0 2rem 0;
            padding-bottom: 1.5rem;
            border-bottom: 2px solid rgba(102, 126, 234, 0.2);
        }
        body.dark-mode .portal-teacher-name {
            color: #f1f5f9;
            border-bottom-color: rgba(96, 165, 250, 0.3);
        }

        .teacher-info-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 1.5rem;
            margin-top: 1.5rem;
        }
        .teacher-info-item {
            text-align: center;
            padding: 1.5rem;
            background: rgba(102, 126, 234, 0.05);
            border-radius: 12px;
        }
        .teacher-info-item i {
            font-size: 2rem;
            margin-bottom: 0.7rem;
            color: #667eea;
        }
        body.dark-mode .teacher-info-item {
            background: rgba(96, 165, 250, 0.1);
        }
        body.dark-mode .teacher-info-item i { color: #60a5fa; }

        .info-label {
            font-size: 1rem;
            color: #64748b;
            margin-bottom: 0.5rem;
            font-weight: 500;
        }
        body.dark-mode .info-label { color: #94a3b8; }

        .info-value {
            font-size: 1.2rem;
            font-weight: 600;
            color: #1e293b;
            word-break: break-word;
            overflow-wrap: break-word;
        }
        body.dark-mode .info-value { color: #f1f5f9; }

        /* Navigation Grid - Same as teacher portal */
        .nav-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(280px, 1fr));
            gap: 25px;
            margin-top: 80px;
            max-width: 900px;
            margin-left: auto;
            margin-right: auto;
        }
        .nav-button {
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
            border: 1px solid rgba(0,0,0,0.1);
        }
        body.dark-mode .nav-button {
            background: #1e293b;
            color: #f1f5f9;
            border: 1px solid #334155;
            box-shadow: 0 4px 15px rgba(0,0,0,0.3), 0 2px 4px rgba(0,0,0,0.2);
        }
        .nav-button::before {
            content: '';
            position: absolute;
            top: 0; left: 0; right: 0;
            height: 4px;
            background: linear-gradient(90deg, #667eea, #764ba2);
            transform: scaleX(0);
            transition: transform 0.3s ease;
            transform-origin: left;
        }
        .nav-button:hover::before { transform: scaleX(1); }
        .nav-button:hover {
            transform: translateY(-8px);
            box-shadow: 0 15px 35px rgba(0,0,0,0.1), 0 5px 15px rgba(0,0,0,0.08);
            color: #1e293b;
        }
        body.dark-mode .nav-button:hover {
            box-shadow: 0 15px 35px rgba(0,0,0,0.4), 0 5px 15px rgba(0,0,0,0.3);
            color: #f1f5f9;
        }
        .nav-button i {
            font-size: 2.5rem;
            margin-bottom: 20px;
            color: #667eea;
            transition: all 0.3s ease;
        }
        body.dark-mode .nav-button i { color: #60a5fa; }
        .nav-button:hover i { transform: scale(1.1); color: #764ba2; }
        body.dark-mode .nav-button:hover i { color: #93c5fd; }
        .nav-button h3 {
            font-size: 1.4rem;
            font-weight: 600;
            margin-bottom: 10px;
            color: #1e293b;
        }
        body.dark-mode .nav-button h3 { color: #f1f5f9; }
        .nav-button p {
            font-size: 0.95rem;
            color: #64748b;
            font-weight: 400;
            margin: 0;
        }
        body.dark-mode .nav-button p { color: #94a3b8; }

        /* No Services */
        .no-services {
            text-align: center;
            padding: 60px 20px;
            background: white;
            border-radius: 15px;
            box-shadow: 0 4px 15px rgba(0,0,0,0.08);
        }
        .no-services i { font-size: 3rem; margin-bottom: 15px; display: block; color: #94a3b8; }
        .no-services p { color: #64748b; font-size: 1.1rem; }
        body.dark-mode .no-services {
            background: #1e293b;
        }
        body.dark-mode .no-services i { color: #64748b; }
        body.dark-mode .no-services p { color: #94a3b8; }

        /* Footer */
        .portal-footer {
            background: #1e293b;
            color: white;
            padding: 2rem 0 1rem 0;
            margin-top: 3rem;
            border-top: 3px solid rgba(102, 126, 234, 0.3);
            position: relative;
            z-index: 10;
        }
        body.dark-mode .portal-footer {
            background: #0f172a;
            border-top: 3px solid rgba(100, 116, 139, 0.3);
        }
        .portal-footer p {
            margin: 0 0 1rem 0;
            color: rgba(255, 255, 255, 0.9);
            font-size: 1rem;
        }
        body.dark-mode .portal-footer p { color: #cbd5e1; }



        /* Loading Overlay */
        .loading-overlay {
            position: fixed;
            top: 0; left: 0; right: 0; bottom: 0;
            background: rgba(255,255,255,0.9);
            display: none;
            justify-content: center;
            align-items: center;
            flex-direction: column;
            z-index: 9999;
        }
        .loading-spinner {
            width: 50px; height: 50px;
            border: 4px solid #e2e8f0;
            border-top: 4px solid #667eea;
            border-radius: 50%;
            animation: spin 0.8s linear infinite;
        }
        @keyframes spin { to { transform: rotate(360deg); } }

        /* Mobile Responsive */
        @media (max-width: 768px) {
            .portal-school-logo { max-width: 150px; margin-bottom: 1rem; }
            .portal-logo-section { padding: 1.5rem 1rem 0.5rem; }
            .portal-title-text { padding: 0 1rem 1.5rem; }
            .portal-main-title-text { font-size: 1.8rem; }
            .portal-welcome-card { margin: 0 1rem 2rem; padding: 30px 20px; }
            .portal-welcome-title { font-size: 1.4rem; }
            .portal-teacher-name { font-size: 1.3rem; }
            .teacher-info-grid { grid-template-columns: 1fr; gap: 1rem; }
            .nav-grid { grid-template-columns: 1fr; gap: 20px; margin-top: 60px; }
            .nav-button { padding: 30px 25px; }
            .nav-button i { font-size: 2rem; margin-bottom: 15px; }
            .nav-button h3 { font-size: 1.2rem; }
            .nav-button p { font-size: 0.9rem; }
            .logout-button-top { padding: 10px 18px; font-size: 0.9rem; }
        }
        @media (max-width: 480px) {
            .portal-school-logo { max-width: 120px; }
            .portal-main-title-text { font-size: 1.5rem; }
            .portal-welcome-title { font-size: 1.2rem; }
            .portal-teacher-name { font-size: 1.2rem; }
            .nav-button { padding: 25px 20px; }
            .nav-button i { font-size: 1.8rem; }
            .nav-button h3 { font-size: 1.1rem; }
            .logout-button-top { padding: 8px 14px; font-size: 0.85rem; }
        }
    </style>
</head>
<body>
    <!-- Particles Background -->
    <div id="particles-js"></div>

    <!-- Top Fixed Buttons -->
    <div class="top-fixed-buttons">
        <a href="../logout.php" class="logout-button-top">
            <i class="fas fa-sign-out-alt"></i>
            <span>خروج</span>
        </a>
    </div>

    <!-- Theme Toggle - Bottom Right -->
    <button class="theme-toggle" id="themeToggle" title="تبديل الوضع المظلم/الفاتح">
        <i class="fas fa-moon"></i>
    </button>

    <!-- School Logo -->
    <div class="portal-logo-section">
        <?php if (!function_exists('get_school_logo')) { require_once __DIR__ . '/../includes/template_helper.php'; } ?>
        <img src="<?php echo get_school_logo('../'); ?>" alt="شعار المدرسة" class="portal-school-logo">
    </div>

    <!-- Portal Title -->
    <div class="portal-title-text">
        <h1 class="portal-main-title-text">👨‍🏫 بوابة المعلم</h1>
    </div>

    <div class="container">
        <!-- Welcome Card -->
        <div class="portal-welcome-card">
            <h2 class="portal-welcome-title">
                <i class="fas fa-hand-sparkles portal-welcome-icon"></i>
                مرحباً بك أستاذ
            </h2>
            <h3 class="portal-teacher-name"><?php echo htmlspecialchars($teacher['name']); ?></h3>

            <div class="teacher-info-grid">
                <?php if ($teacher['email']): ?>
                <div class="teacher-info-item">
                    <i class="fas fa-envelope" style="color: #3b82f6;"></i>
                    <div class="info-label">البريد الإلكتروني</div>
                    <div class="info-value"><?php echo htmlspecialchars($teacher['email']); ?></div>
                </div>
                <?php endif; ?>

                <?php if ($teacher['phone']): ?>
                <div class="teacher-info-item">
                    <i class="fas fa-phone" style="color: #10b981;"></i>
                    <div class="info-label">رقم الهاتف</div>
                    <div class="info-value"><?php echo htmlspecialchars($teacher['phone']); ?></div>
                </div>
                <?php endif; ?>

                <?php if ($teacher['specialization']): ?>
                <div class="teacher-info-item">
                    <i class="fas fa-graduation-cap" style="color: #f59e0b;"></i>
                    <div class="info-label">التخصص</div>
                    <div class="info-value"><?php echo htmlspecialchars($teacher['specialization']); ?></div>
                </div>
                <?php endif; ?>

                <?php if ($teacher['school_name']): ?>
                <div class="teacher-info-item">
                    <i class="fas fa-school" style="color: #8b5cf6;"></i>
                    <div class="info-label">المدرسة</div>
                    <div class="info-value"><?php echo htmlspecialchars($teacher['school_name']); ?></div>
                </div>
                <?php endif; ?>
            </div>
        </div>
    </div>

    <!-- Services Grid -->
    <div class="container">
        <?php if (empty($visible_services)): ?>
            <div class="no-services">
                <i class="fas fa-hourglass-half"></i>
                <p>لا توجد خدمات متاحة حالياً.<br>يرجى التواصل مع إدارة المدرسة.</p>
            </div>
        <?php else: ?>
            <div class="nav-grid">
                <?php foreach ($visible_services as $key => $svc): ?>
                    <a href="<?php echo htmlspecialchars($svc['url']); ?>" class="nav-button">
                        <i class="<?php echo htmlspecialchars($svc['icon']); ?>"></i>
                        <h3><?php echo htmlspecialchars($svc['label']); ?></h3>
                        <p><?php echo htmlspecialchars($svc['desc']); ?></p>
                    </a>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>
    </div>

    <!-- Footer -->
    <footer class="portal-footer">
        <div class="container text-center">
            <p>جميع الحقوق محفوظة &copy; <?php echo date('Y'); ?><br>EduCore<br>Open Source School Platform</p>
        </div>
    </footer>

    <!-- Loading Overlay -->
    <div class="loading-overlay" id="loadingOverlay">
        <div class="loading-spinner"></div>
        <p>جاري التحميل...</p>
    </div>

    <script src="https://cdn.jsdelivr.net/particles.js/2.0.0/particles.min.js"></script>
    <script src="../teacher/script.js?v=1.2"></script>

    <script>
    // Theme Toggle
    (function() {
        const themeToggle = document.getElementById('themeToggle');
        const savedTheme = localStorage.getItem('theme');
        if (savedTheme === 'dark') {
            document.body.classList.add('dark-mode');
            document.body.classList.remove('light-mode');
            themeToggle.innerHTML = '<i class="fas fa-sun"></i>';
        } else {
            document.body.classList.remove('dark-mode');
            document.body.classList.add('light-mode');
            themeToggle.innerHTML = '<i class="fas fa-moon"></i>';
        }
        if (typeof updateParticlesTheme === 'function') updateParticlesTheme(savedTheme || 'light');
        themeToggle.addEventListener('click', () => {
            document.body.classList.toggle('dark-mode');
            if (document.body.classList.contains('dark-mode')) {
                document.body.classList.remove('light-mode');
                localStorage.setItem('theme', 'dark');
                themeToggle.innerHTML = '<i class="fas fa-sun"></i>';
                if (typeof updateParticlesTheme === 'function') updateParticlesTheme('dark');
            } else {
                document.body.classList.add('light-mode');
                localStorage.setItem('theme', 'light');
                themeToggle.innerHTML = '<i class="fas fa-moon"></i>';
                if (typeof updateParticlesTheme === 'function') updateParticlesTheme('light');
            }
        });
    })();

    // Show loading overlay on nav-button click
    document.querySelectorAll('.nav-button').forEach(btn => {
        btn.addEventListener('click', () => {
            document.getElementById('loadingOverlay').style.display = 'flex';
        });
    });
    </script>
</body>
</html>
