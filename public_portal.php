<?php
// بوابة الطالب العامة - للمراحل غير الابتدائية
define('ACCESS_ALLOWED', true);

$publicPortalConfig = require __DIR__ . '/config/public_portal.php';
if (!empty($publicPortalConfig['unified_access_portal_enabled'])) {
    $query = ['skip_intro' => '1'];
    if ((string) ($_GET['from_teams'] ?? '') === '1') {
        $query['from_teams'] = '1';
    }
    header('Location: index.php?' . http_build_query($query));
    exit;
}
unset($publicPortalConfig, $query);

// لا تعرض أخطاء PHP للمستخدمين في الصفحات العامة.
ini_set('display_errors', 0);
ini_set('display_startup_errors', 0);
error_reporting(E_ALL);
ini_set('log_errors', 1);

// تحميل إعدادات الجلسة
require_once 'includes/session_config.php';
require_once 'includes/template_helper.php';
require_once 'config/database.php';

$organizationName = trim((string) env('ORGANIZATION_NAME', 'EduCore Deployment'));
$resultsPortalUrl = trim((string) env('RESULTS_PORTAL_URL', ''));

// الحصول على المرحلة من الرابط
$stage = isset($_GET['stage']) ? $_GET['stage'] : 'kindergarten';

// الحصول على الخدمات المتاحة للمرحلة من قاعدة البيانات
$database = new Database();
$db = $database->getConnection();

$available_services = [];
$query = "SELECT services FROM stages WHERE stage_code = ? AND status = 'active' LIMIT 1";
$stmt = $db->prepare($query);
$stmt->execute([$stage]);
$result = $stmt->fetch(PDO::FETCH_ASSOC);

if ($result && !empty($result['services'])) {
    $services_json = json_decode($result['services'], true);
    if (is_array($services_json)) {
        $available_services = $services_json;
    }
}

// تحديد اسم المرحلة بالعربية
$stage_names = [
    'kindergarten' => 'مرحلة رياض الأطفال',
    'preparatory' => 'المرحلة الإعدادية',
    'secondary' => 'المرحلة الثانوية'
];

$stage_icons = [
    'kindergarten' => 'fas fa-child',
    'preparatory' => 'fas fa-user-graduate',
    'secondary' => 'fas fa-graduation-cap'
];

$stage_colors = [
    'kindergarten' => '#ff6b6b',
    'preparatory' => '#a29bfe',
    'secondary' => '#fd79a8'
];

$current_stage_name = isset($stage_names[$stage]) ? $stage_names[$stage] : 'مرحلة رياض الأطفال';
$current_stage_icon = isset($stage_icons[$stage]) ? $stage_icons[$stage] : 'fas fa-baby-carriage';
$current_stage_color = isset($stage_colors[$stage]) ? $stage_colors[$stage] : '#ff6b6b';
?>
<!DOCTYPE html>
<html lang="ar" dir="rtl">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo htmlspecialchars($current_stage_name . ' - ' . $organizationName, ENT_QUOTES, 'UTF-8'); ?></title>
    
    <!-- Prevent caching issues -->
    <meta http-equiv="Cache-Control" content="no-cache, no-store, must-revalidate">
    <meta http-equiv="Pragma" content="no-cache">
    <meta http-equiv="Expires" content="0">
    
    <!-- Bootstrap RTL CSS -->
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.rtl.min.css">
    <link href="https://fonts.googleapis.com/css2?family=Cairo:wght@300;400;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
    <link rel="stylesheet" href="student/styles.css?v=1.3">
    
    <style>
        /* Override body background */
        body {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            min-height: 100vh;
            padding-top: 0 !important;
            display: flex;
            flex-direction: column;
        }
        
        body.dark-mode {
            background: linear-gradient(135deg, #1e3a8a 0%, #4c1d95 100%);
        }
        
        /* Back Button - same style as logout button */
        .back-button {
            position: fixed;
            top: 20px;
            left: 20px;
            z-index: 1000;
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
        
        .back-button:hover {
            transform: translateY(-2px);
            box-shadow: 0 6px 20px rgba(231, 76, 60, 0.5);
            color: white;
        }
        
        body.dark-mode .back-button {
            background: linear-gradient(135deg, #dc2626 0%, #991b1b 100%);
            box-shadow: 0 4px 15px rgba(220, 38, 38, 0.5);
        }
        
        body.dark-mode .back-button:hover {
            box-shadow: 0 6px 20px rgba(220, 38, 38, 0.6);
        }
        
        /* Theme Toggle Button */
        .theme-toggle {
            position: fixed;
            bottom: 20px;
            left: 20px !important;
            right: auto !important;
            z-index: 1001;
            width: 50px;
            height: 50px;
            border-radius: 50%;
            border: none;
            background: #ffffff;
            color: #667eea;
            font-size: 1.3rem;
            cursor: pointer;
            box-shadow: 0 4px 15px rgba(0, 0, 0, 0.2);
            transition: all 0.3s ease;
            display: flex;
            align-items: center;
            justify-content: center;
        }
        
        .theme-toggle::before {
            content: '\f186';
            font-family: 'Font Awesome 6 Free';
            font-weight: 900;
            font-size: 1.3rem;
            transition: all 0.3s ease;
        }
        
        .theme-toggle:hover {
            transform: scale(1.1);
        }
        
        body.dark-mode .theme-toggle {
            background: #334155;
            color: #fbbf24;
            box-shadow: 0 4px 15px rgba(0, 0, 0, 0.4);
        }
        
        body.dark-mode .theme-toggle::before {
            content: '\f185';
        }
        
        /* Portal Logo Section */
        .portal-logo-section {
            text-align: center;
            padding: 2rem 1rem 0.5rem;
        }
        
        .portal-school-logo {
            max-width: 200px;
            height: auto;
            filter: drop-shadow(0 4px 12px rgba(0, 0, 0, 0.3));
            animation: fadeInDown 0.8s ease-out;
            margin-bottom: 1.5rem;
            transition: all 0.3s ease;
        }
        
        .portal-school-logo:hover {
            transform: scale(1.05);
            filter: drop-shadow(0 6px 16px rgba(0, 0, 0, 0.4));
        }
        
        @keyframes fadeInDown {
            from {
                opacity: 0;
                transform: translateY(-20px);
            }
            to {
                opacity: 1;
                transform: translateY(0);
            }
        }
        
        /* Portal Title */
        .portal-title-text {
            text-align: center;
            padding: 0 1rem 1rem;
        }
        
        .portal-main-title-text {
            font-size: 2.5rem;
            font-weight: 800;
            color: #1e293b;
            text-shadow: 0 2px 8px rgba(0, 0, 0, 0.1);
            margin: 0 0 0.5rem 0;
            letter-spacing: 1px;
        }
        
        body.dark-mode .portal-main-title-text {
            color: #f1f5f9;
            text-shadow: 0 2px 8px rgba(0, 0, 0, 0.5);
        }
        
        /* Stage Badge - كارت توضيحي للمرحلة */
        .stage-badge {
            display: inline-block;
            background: white;
            color: #0f172a;
            padding: 1rem 2.5rem;
            border-radius: 50px;
            font-size: 1.4rem;
            font-weight: 700;
            box-shadow: 0 4px 15px rgba(0, 0, 0, 0.15);
            margin-top: 0.5rem;
            border: 1px solid rgba(0, 0, 0, 0.08);
            transition: all 0.3s ease;
            animation: fadeInUp 0.8s ease-out;
            animation-delay: 0.3s;
            opacity: 0;
            animation-fill-mode: forwards;
        }
        
        @keyframes fadeInUp {
            from {
                opacity: 0;
                transform: translateY(30px);
            }
            to {
                opacity: 1;
                transform: translateY(0);
            }
        }
        
        .stage-badge:hover {
            transform: translateY(-2px);
            box-shadow: 0 6px 20px rgba(0, 0, 0, 0.2);
        }
        
        .stage-badge i {
            margin-left: 0.5rem;
            color: <?php echo $current_stage_color; ?>;
            font-size: 1.5rem;
            filter: saturate(1.2) brightness(0.95);
        }
        
        body.dark-mode .stage-badge {
            background: #1e293b;
            color: #f8fafc;
            box-shadow: 0 4px 15px rgba(0, 0, 0, 0.4);
            border: 1px solid #334155;
        }
        
        body.dark-mode .stage-badge:hover {
            box-shadow: 0 6px 20px rgba(0, 0, 0, 0.5);
        }
        
        body.dark-mode .stage-badge i {
            filter: brightness(1.3) saturate(1.1);
        }
        
        body.dark-mode .portal-main-title-text {
            color: #f1f5f9;
        }
        
        /* Services Container */
        .container {
            max-width: 900px;
            margin: 2rem auto 0;
            padding: 0 20px 60px;
        }
        
        .nav-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(300px, 1fr));
            gap: 25px;
        }
        
        /* Info Note */
        .info-note {
            background: white;
            border-radius: 15px;
            padding: 30px;
            margin: 2rem auto;
            max-width: 800px;
            box-shadow: 
                0 4px 15px rgba(0, 0, 0, 0.1),
                0 2px 8px rgba(0, 0, 0, 0.08);
            text-align: center;
            border-right: 5px solid #3498db;
        }
        
        body.dark-mode .info-note {
            background: #1e293b;
            border: 1px solid #334155;
            box-shadow:
                0 4px 15px rgba(0, 0, 0, 0.3),
                0 2px 4px rgba(0, 0, 0, 0.2);
        }
        
        .info-note i {
            font-size: 2rem;
            color: #3498db;
            margin-bottom: 1rem;
        }
        
        .info-note p {
            color: #1e293b;
            font-size: 1.1rem;
            margin: 0;
            line-height: 1.8;
        }
        
        body.dark-mode .info-note p {
            color: #f1f5f9;
        }
        
        /* Footer */
        .portal-footer {
            background: #1e293b;
            color: white;
            padding: 1.5rem 0 1.5rem 0;
            margin-top: auto !important;
            margin-bottom: 0 !important;
            border-top: 3px solid rgba(102, 126, 234, 0.3);
        }
        
        .portal-footer .container {
            padding-bottom: 0 !important;
        }
        
        body.dark-mode .portal-footer {
            background: #0f172a;
            border-top: 3px solid rgba(100, 116, 139, 0.3);
        }
        
        .portal-footer p {
            margin: 0 0 0.5rem 0;
            color: rgba(255, 255, 255, 0.9);
            font-size: 1rem;
        }
        
        body.dark-mode .portal-footer p {
            color: #cbd5e1;
        }
        
        /* Social Media Icons */
        .social-media-footer {
            display: flex;
            justify-content: center;
            gap: 15px;
            margin-top: 1rem;
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
        
        .social-footer-icon.facebook {
            background: linear-gradient(135deg, #1877f2 0%, #0c63d4 100%);
        }
        
        .social-footer-icon.whatsapp {
            background: linear-gradient(135deg, #25d366 0%, #128c7e 100%);
        }
        
        .social-footer-icon.instagram {
            background: linear-gradient(135deg, #e1306c 0%, #c13584 50%, #833ab4 100%);
        }
        
        .social-footer-icon:hover {
            transform: translateY(-4px) scale(1.1);
            box-shadow: 0 8px 20px rgba(0, 0, 0, 0.4);
        }
        
        /* Responsive Design */
        @media (max-width: 768px) {
            .portal-main-title-text {
                font-size: 2rem;
            }
            
            .stage-badge {
                font-size: 1.2rem;
                padding: 0.8rem 2rem;
            }
            
            .stage-badge i {
                font-size: 1.3rem;
            }
            
            .nav-grid {
                grid-template-columns: 1fr;
                gap: 20px;
            }
            
            .back-button {
                padding: 10px 18px;
                font-size: 0.9rem;
                top: 15px;
                left: 15px;
            }
        }
        
        @media (max-width: 480px) {
            .portal-school-logo {
                max-width: 150px;
            }
            
            .portal-main-title-text {
                font-size: 1.6rem;
            }
            
            .stage-badge {
                font-size: 1.1rem;
                padding: 0.7rem 1.5rem;
            }
            
            .stage-badge i {
                font-size: 1.2rem;
            }
        }
    </style>
</head>
<body>
    <!-- Back Button -->
    <a href="index.php" class="back-button">
        <i class="fas fa-arrow-right"></i>
        رجوع للمراحل
    </a>
    
    <!-- Loading Overlay -->
    <div class="loading-overlay" id="loadingOverlay">
        <div class="spinner-border text-light" role="status">
            <span class="visually-hidden">جاري التحميل...</span>
        </div>
    </div>
    
    <!-- Particles Background -->
    <div id="particles-js"></div>
    
    <!-- Portal Logo Section -->
    <div class="portal-logo-section">
        <a href="index.php" style="display: inline-block; cursor: pointer;">
            <?php if (!function_exists('get_school_logo')) { require_once 'includes/template_helper.php'; } ?>
            <img src="<?php echo asset_url(get_school_logo('')); ?>" alt="شعار المدرسة" class="portal-school-logo">
        </a>
    </div>
    
    <!-- Portal Title -->
    <div class="portal-title-text">
        <div class="stage-badge">
            <i class="<?php echo $current_stage_icon; ?>"></i>
            <?php echo $current_stage_name; ?>
        </div>
    </div>
    
    <!-- Services Section -->
    <div class="container">
        <?php if (empty($available_services)): ?>
        <!-- رسالة: لا توجد خدمات متاحة -->
        <div class="alert alert-info" style="text-align: center; margin: 2rem auto; max-width: 800px; padding: 2rem; border-radius: 15px; background: linear-gradient(135deg, #60a5fa 0%, #3b82f6 100%); color: #1e3a8a; box-shadow: 0 4px 15px rgba(96, 165, 250, 0.3);">
            <i class="fas fa-info-circle" style="font-size: 3rem; margin-bottom: 1rem;"></i>
            <h4 style="margin-bottom: 1rem; font-weight: 700;">لا توجد خدمات متاحة حالياً</h4>
            <p style="font-size: 1.1rem; margin-bottom: 0;">
                لم يتم تفعيل أي خدمات لهذه المرحلة حالياً.
            </p>
        </div>
        <?php endif; ?>
        
        <div class="nav-grid">
            <?php if ($stage === 'kindergarten'): ?>
                <!-- خدمات رياض الأطفال - من قاعدة البيانات -->
                <?php if (in_array('materials', $available_services)): ?>
                <a href="student/materials/index.html" class="nav-button">
                    <div class="nav-icon-wrapper">
                        <i class="fas fa-book nav-icon"></i>
                    </div>
                    <h3 class="nav-title">Materials</h3>
                    <p class="nav-description">المواد والموارد التعليمية</p>
                </a>
                <?php endif; ?>
                
                <?php if (in_array('results', $available_services) && $resultsPortalUrl !== ''): ?>
                <a href="<?php echo htmlspecialchars($resultsPortalUrl, ENT_QUOTES, 'UTF-8'); ?>" target="_blank" rel="noopener noreferrer" class="nav-button">
                    <div class="nav-icon-wrapper">
                        <i class="fas fa-chart-line nav-icon"></i>
                    </div>
                    <h3 class="nav-title">Results</h3>
                    <p class="nav-description">نتيجة امتحانات العام الدراسي</p>
                </a>
                <?php endif; ?>
                
                <?php if (in_array('ebooks', $available_services)): ?>
                <a href="student/ebook/" class="nav-button">
                    <div class="nav-icon-wrapper">
                        <i class="fas fa-book-open nav-icon"></i>
                    </div>
                    <h3 class="nav-title">E-Books</h3>
                    <p class="nav-description">الكتب الإلكترونية</p>
                </a>
                <?php endif; ?>
                
            <?php elseif ($stage === 'preparatory' || $stage === 'secondary'): ?>
                <!-- خدمات المرحلة الإعدادية والثانوية -->
                <!-- Student Login - دائماً يظهر -->
                <a href="login.php?stage=<?php echo $stage; ?>" class="nav-button">
                    <div class="nav-icon-wrapper">
                        <i class="fas fa-sign-in-alt nav-icon"></i>
                    </div>
                    <h3 class="nav-title">Student Login</h3>
                    <p class="nav-description">تسجيل دخول الطلاب</p>
                </a>
                
                <?php if (in_array('materials', $available_services)): ?>
                <a href="student/materials/index.html" class="nav-button">
                    <div class="nav-icon-wrapper">
                        <i class="fas fa-book nav-icon"></i>
                    </div>
                    <h3 class="nav-title">Materials</h3>
                    <p class="nav-description">المواد والموارد التعليمية</p>
                </a>
                <?php endif; ?>
                
                <?php if (in_array('results', $available_services) && $resultsPortalUrl !== ''): ?>
                <a href="<?php echo htmlspecialchars($resultsPortalUrl, ENT_QUOTES, 'UTF-8'); ?>" target="_blank" rel="noopener noreferrer" class="nav-button">
                    <div class="nav-icon-wrapper">
                        <i class="fas fa-chart-line nav-icon"></i>
                    </div>
                    <h3 class="nav-title">Results</h3>
                    <p class="nav-description">نتيجة امتحانات العام الدراسي</p>
                </a>
                <?php endif; ?>
                
                <?php if (in_array('ebooks', $available_services)): ?>
                <a href="student/ebook/" class="nav-button">
                    <div class="nav-icon-wrapper">
                        <i class="fas fa-book-open nav-icon"></i>
                    </div>
                    <h3 class="nav-title">E-Books</h3>
                    <p class="nav-description">الكتب الإلكترونية</p>
                </a>
                <?php endif; ?>
                
                <?php if (in_array('reports', $available_services)): ?>
                <a href="student/reports/published_reports.php" class="nav-button">
                    <div class="nav-icon-wrapper">
                        <i class="fas fa-chart-bar nav-icon"></i>
                    </div>
                    <h3 class="nav-title">Reports</h3>
                    <p class="nav-description">تقارير الدرجات المنشورة</p>
                </a>
                <?php endif; ?>
                
            <?php endif; ?>
        </div>
    </div>
    
    <!-- Footer -->
    <footer class="portal-footer">
        <div class="container text-center">
            <p style="margin: 0.5rem 0; line-height: 1.6;">
                <strong>جميع الحقوق محفوظة © <?php echo date('Y'); ?></strong>
            </p>
            <p style="margin: 0.5rem 0; line-height: 1.6;">
                <?php echo htmlspecialchars($organizationName, ENT_QUOTES, 'UTF-8'); ?><br>
                EduCore Deployment
            </p>
            
            <!-- Social Media Icons in Footer -->
            <div class="social-media-footer">
                <a href="https://github.com/M-Hamaki/EduCore" target="_blank" class="social-footer-icon github" title="مستودع المشروع">
                    <i class="fab fa-github"></i>
                </a>
            </div>
        </div>
    </footer>
    
    <!-- Bootstrap JS Bundle -->
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
    
    <!-- Particles.js -->
    <script src="https://cdn.jsdelivr.net/particles.js/2.0.0/particles.min.js"></script>
    
    <!-- Theme Toggle & Particles Script -->
    <script src="student/script.js?v=1.3"></script>
    
    <script>
        // Hide loading overlay when page is fully loaded
        window.addEventListener('load', function() {
            setTimeout(function() {
                document.getElementById('loadingOverlay').style.display = 'none';
            }, 500);
