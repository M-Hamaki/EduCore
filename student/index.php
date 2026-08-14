<?php
// Disable direct error display in production
ini_set('display_errors', 0);
ini_set('display_startup_errors', 0);
error_reporting(0);

// Set page title
$page_title = "النقاط والمكافآت";

// Include database and necessary classes
require_once '../config/database.php';
require_once '../classes/user.php';
require_once '../classes/evaluation.php';
require_once '../classes/utilities.php';
require_once '../includes/template_helper.php';

// Initialize database connection
$database = new Database();
$db = $database->getConnection();

// تحميل إعدادات الجلسة الموحدة
require_once '../includes/session_config.php';

// Validate session for student role
Utilities::validateSession('student');

// Initialize user object
$user = new User($db);
$user->id = $_SESSION['user_id'];
$user->readOne();

// Initialize evaluation object
$evaluation = new Evaluation($db);

// Unified points summary
$pointsSummary = Utilities::getStudentPointsSummary($db, $user->id);
$total_points = $pointsSummary['total'];
$positive_points = $pointsSummary['positive'];
$negative_points = $pointsSummary['negative'];

// Calculate student level and progress
$levelData = Utilities::getStudentLevel($total_points);

// Get student evaluations
$stmt = $evaluation->readByStudent($user->id);

// تجميع التقييمات حسب الشهر
$evaluationsByMonth = [];
$monthlyStats = [];

// أسماء الشهور بالعربية
$arabicMonths = [
    1 => 'يناير', 2 => 'فبراير', 3 => 'مارس', 4 => 'أبريل',
    5 => 'مايو', 6 => 'يونيو', 7 => 'يوليو', 8 => 'أغسطس',
    9 => 'سبتمبر', 10 => 'أكتوبر', 11 => 'نوفمبر', 12 => 'ديسمبر'
];

while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
    $date = strtotime($row['date_created']);
    $monthKey = date('Y-m', $date); // مثال: 2025-10
    $year = date('Y', $date);
    $month = (int)date('m', $date);
    
    // إنشاء مفتاح الشهر مع الاسم العربي
    $monthName = $arabicMonths[$month] . ' ' . $year;
    
    if (!isset($evaluationsByMonth[$monthKey])) {
        $evaluationsByMonth[$monthKey] = [
            'name' => $monthName,
            'evaluations' => [],
            'count' => 0,
            'total_points' => 0,
            'positive_points' => 0,
            'negative_points' => 0
        ];
    }
    
    $evaluationsByMonth[$monthKey]['evaluations'][] = $row;
    $evaluationsByMonth[$monthKey]['count']++;
    
    $points = (int)$row['display_points'];
    $evaluationsByMonth[$monthKey]['total_points'] += $points;
    
    if ($points > 0) {
        $evaluationsByMonth[$monthKey]['positive_points'] += $points;
    } else {
        $evaluationsByMonth[$monthKey]['negative_points'] += abs($points);
    }
}

// ترتيب الشهور من الأحدث للأقدم
krsort($evaluationsByMonth);

// تحديد الشهر الحالي
$currentMonth = date('Y-m');
?>
<?php if (empty($_SESSION['csrf_token'])) { $_SESSION['csrf_token']=bin2hex(random_bytes(32)); } ?>
<!DOCTYPE html>
<html lang="ar" dir="rtl">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo $page_title; ?> - نظام الإدارة المدرسية</title>
    <meta name="csrf-token" content="<?php echo htmlspecialchars($_SESSION['csrf_token'], ENT_QUOTES, 'UTF-8'); ?>">
    
    <!-- AGGRESSIVE CACHE BUSTING - كسر الـ Cache بقوة -->
    <meta http-equiv="Cache-Control" content="no-cache, no-store, must-revalidate, max-age=0, pre-check=0, post-check=0">
    <meta http-equiv="Pragma" content="no-cache">
    <meta http-equiv="Expires" content="0">
    <meta name="version" content="3.0-<?php echo time(); ?>-<?php echo mt_rand(); ?>">
    <meta name="last-modified" content="<?php echo gmdate('D, d M Y H:i:s') . ' GMT'; ?>">
    
    <!-- Bootstrap RTL CSS -->
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.rtl.min.css?v=<?php echo time(); ?>-<?php echo mt_rand(); ?>">
    <!-- Font Awesome -->
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css?v=<?php echo mt_rand(); ?>">
    <!-- Animate.css for level animations -->
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/animate.css/4.1.1/animate.min.css">
    <!-- Theme Toggle Styles - نفس البوابة -->
    <link rel="stylesheet" href="styles.css?v=<?php echo time(); ?>">
    
    <style>
        /* ====================================
           REWARDS PAGE CUSTOM STYLES
           جميع تنسيقات صفحة المكافآت
           Priority: HIGH (Override everything)
           ====================================
        */
        
        /* تطبيق نفس هوية البوابة - خلفية بيضاء */
        body {
            padding-top: 0 !important;
            background: #ffffff !important;
            min-height: 100vh;
            margin: 0;
        }
        
        html {
            background: #ffffff !important;
        }
        
        /* Dark Mode Support */
        body.dark-mode {
            background: #1a1a2e !important;
        }
        
        html.dark-mode {
            background: #1a1a2e !important;
        }
        
        /* Particles Container - نفس البوابة */
        #particles-js {
            position: fixed !important;
            top: 0 !important;
            left: 0 !important;
            width: 100% !important;
            height: 100% !important;
            z-index: 1 !important;
            background: #ffffff !important;
            pointer-events: none !important;
            transition: background 0.3s ease;
        }
        
        body.dark-mode #particles-js {
            background: #1a1a2e !important;
        }
        
        /* Logo Section - نفس تصميم البوابة */
        .rewards-logo-section {
            text-align: center;
            padding: 2rem 1rem 0.5rem;
            background: transparent !important;
        }
        
        body.dark-mode .rewards-logo-section {
            background: transparent !important;
        }
        
        .rewards-logo-section a {
            display: inline-block;
            transition: transform 0.3s ease;
        }
        
        .rewards-logo-section a:hover {
            transform: scale(1.05);
        }
        
        .rewards-school-logo {
            max-width: 200px;
            height: auto;
            filter: drop-shadow(0 4px 12px rgba(0, 0, 0, 0.3));
            animation: fadeInDown 0.8s ease-out;
            margin-bottom: 1.5rem;
            cursor: pointer;
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
        
        /* Page Title - نفس تصميم البوابة */
        .rewards-title-section {
            text-align: center;
            padding: 0 1rem 2rem;
            background: transparent !important;
        }
        
        body.dark-mode .rewards-title-section {
            background: transparent !important;
        }
        
        .rewards-main-title {
            font-size: 2.5rem;
            font-weight: 800;
            color: #1e293b;
            text-shadow: 0 2px 4px rgba(0, 0, 0, 0.1);
            margin: 0 0 0.5rem 0;
            letter-spacing: 1px;
        }
        
        .rewards-subtitle {
            font-size: 1.3rem;
            color: #334155;
            font-weight: 500;
            margin: 0;
        }
        
        /* Dark Mode for Title */
        body.dark-mode .rewards-main-title {
            color: #f1f5f9;
            text-shadow: 0 2px 8px rgba(0, 0, 0, 0.3);
        }
        
        body.dark-mode .rewards-subtitle {
            color: #cbd5e1;
        }
        
        /* Main Container - نفس تصميم البطاقات في البوابة */
        .rewards-main-container {
            max-width: 1200px;
            margin: 0 auto;
            padding: 0 1rem 3rem;
            background: transparent !important;
        }
        
        body.dark-mode .rewards-main-container {
            background: transparent !important;
        }
        
        /* Card Style - نفس البطاقات البيضاء في البوابة */
        .rewards-card {
            background: white;
            border-radius: 20px;
            padding: 2rem;
            margin-bottom: 2rem;
            box-shadow: 
                0 10px 30px rgba(0, 0, 0, 0.15),
                0 4px 12px rgba(0, 0, 0, 0.1);
            transition: all 0.3s ease;
            border: 2px solid rgba(255, 255, 255, 0.3);
        }
        
        .rewards-card:hover {
            transform: translateY(-5px);
            box-shadow: 
                0 15px 40px rgba(0, 0, 0, 0.2),
                0 6px 15px rgba(0, 0, 0, 0.15);
        }
        
        /* Dark Mode Cards */
        body.dark-mode .rewards-card {
            background: #1e293b !important;
            color: #f1f5f9 !important;
            border: 1px solid #334155 !important;
            box-shadow: 
                0 10px 30px rgba(0, 0, 0, 0.5),
                0 4px 12px rgba(0, 0, 0, 0.3) !important;
        }
        
        body.dark-mode .rewards-card:hover {
            box-shadow: 
                0 15px 40px rgba(0, 0, 0, 0.6),
                0 6px 15px rgba(0, 0, 0, 0.4) !important;
        }
        
        /* Points Display - تحسين التصميم */
        .total-points-display h3 {
            font-size: 3rem;
            font-weight: bold;
            margin: 0;
        }
        
        .points-card {
            border: none;
            transition: all 0.3s ease;
            /* background removed - will use inline styles */
        }
        
        .points-card:hover {
            transform: translateY(-2px);
            box-shadow: 0 8px 20px rgba(0,0,0,0.15) !important;
        }
        
        /* Dark Mode - Level Display Card */
        body.dark-mode .points-card.p-4.rounded.shadow-sm[style*="linear-gradient"] {
            background: linear-gradient(135deg, rgba(129, 140, 248, 0.2) 0%, rgba(147, 51, 234, 0.2) 100%) !important;
            border: 2px solid #818cf8 !important;
        }
        
        body.dark-mode .points-card .text-dark {
            color: #cbd5e1 !important;
        }
        
        body.dark-mode .points-card .text-primary {
            color: #818cf8 !important;
        }
        
        body.dark-mode .points-card .alert-success {
            background-color: rgba(16, 185, 129, 0.2) !important;
            color: #cbd5e1 !important;
            border-color: rgba(16, 185, 129, 0.3) !important;
        }
        
        /* Total Points Card - أبيض مع خط أزرق */
        .total-points-card {
            background: #ffffff !important;
            border: 2px solid #e5e7eb !important;
            border-right: 5px solid #667eea !important;
        }
        
        /* Dark Mode - Total Points Card */
        body.dark-mode .total-points-card {
            background: #334155 !important;
            border: 2px solid #475569 !important;
            border-right: 5px solid #818cf8 !important;
        }
        
        body.dark-mode .total-points-card .total-points-display h3 {
            color: #818cf8 !important;
        }
        
        body.dark-mode .total-points-card .total-points-display p {
            color: #cbd5e1 !important;
        }
        
        /* Positive Points Card - أخضر مع خط أخضر */
        .positive-points-card {
            background: #d1fae5 !important;
            border: 2px solid #a7f3d0 !important;
            border-right: 5px solid #10b981 !important;
        }
        
        /* Dark Mode - Positive Points Card */
        body.dark-mode .positive-points-card {
            background: rgba(16, 185, 129, 0.2) !important;
            border: 2px solid rgba(16, 185, 129, 0.3) !important;
            border-right: 5px solid #10b981 !important;
        }
        
        body.dark-mode .positive-points-card small {
            color: #cbd5e1 !important;
        }
        
        /* Negative Points Card - أحمر مع خط أحمر */
        .negative-points-card {
            background: #fee2e2 !important;
            border: 2px solid #fecaca !important;
            border-right: 5px solid #ef4444 !important;
        }
        
        /* Dark Mode - Negative Points Card */
        body.dark-mode .negative-points-card {
            background: rgba(239, 68, 68, 0.2) !important;
            border: 2px solid rgba(239, 68, 68, 0.3) !important;
            border-right: 5px solid #ef4444 !important;
        }
        
        body.dark-mode .negative-points-card small {
            color: #cbd5e1 !important;
        }
        
        /* Current Level Badge - نفس لون أيقونة اسم المستخدم في البوابة */
        .current-level-badge {
            background: #667eea !important;
            color: #ffffff !important;
            border: none !important;
            box-shadow: 0 2px 6px rgba(102, 126, 234, 0.3) !important;
            font-weight: 600 !important;
        }
        
        span.badge.current-level-badge {
            background: #667eea !important;
            color: #ffffff !important;
        }
        
        .current-level-badge:hover {
            background: #5565d8 !important;
            transform: translateY(-2px);
            box-shadow: 0 4px 10px rgba(102, 126, 234, 0.4) !important;
        }
        
        /* Level Card - نفس تصميم البطاقات الملونة */
        .level-card {
            transition: transform 0.3s ease, box-shadow 0.3s ease;
            border-radius: 15px;
            background: white;
        }
        
        .level-card:hover {
            transform: translateY(-5px);
            box-shadow: 0 10px 25px rgba(0,0,0,0.15);
        }
        
        /* Dark Mode - Level Cards */
        body.dark-mode .level-card {
            background: #334155 !important;
            color: #f1f5f9 !important;
        }
        
        body.dark-mode .level-card h5 {
            color: inherit !important;
        }
        
        body.dark-mode .level-card p {
            color: #cbd5e1 !important;
        }
        
        body.dark-mode .level-card .badge.bg-secondary {
            background: #475569 !important;
            color: #e2e8f0 !important;
        }
        
        /* Progress Bar */
        .progress-bar {
            transition: width 0.8s ease-in-out;
        }
        
        /* Current Level Glow */
        .current-level {
            animation: glow 2s ease-in-out infinite alternate;
        }
        
        @keyframes glow {
            from { box-shadow: 0 0 10px rgba(13, 110, 253, 0.5); }
            to { box-shadow: 0 0 25px rgba(13, 110, 253, 0.8); }
        }
        
        /* Back Button - نفس تصميم الأزرار في البوابة */
        .back-to-portal-btn {
            background: white;
            color: #667eea;
            padding: 12px 30px;
            border-radius: 50px;
            border: 2px solid #667eea;
            font-weight: 600;
            transition: all 0.3s ease;
            text-decoration: none;
            display: inline-flex;
            align-items: center;
            gap: 0.5rem;
            box-shadow: 0 4px 12px rgba(0, 0, 0, 0.1);
        }
        
        .back-to-portal-btn:hover {
            background: #667eea;
            color: white;
            transform: translateY(-2px);
            box-shadow: 0 6px 20px rgba(102, 126, 234, 0.4);
        }
        
        /* Dark Mode - Back Button */
        body.dark-mode .back-to-portal-btn {
            background: #334155;
            color: #818cf8;
            border-color: #818cf8;
        }
        
        body.dark-mode .back-to-portal-btn:hover {
            background: #818cf8;
            color: #1e293b;
        }
        
        /* Table Improvements */
        .table-responsive {
            border-radius: 10px;
            overflow-x: auto;
            overflow-y: hidden;
            -webkit-overflow-scrolling: touch;
            width: 100%;
            margin: 0;
        }

        /* Dark Mode Table Styles */
        body.dark-mode .table {
            background: #1e293b !important;
            color: #f1f5f9 !important;
        }
        
        body.dark-mode .table thead {
            background: #0f172a !important;
            border-bottom: 3px solid #475569 !important;
        }
        
        body.dark-mode .table thead th {
            color: #e2e8f0 !important;
            background: #0f172a !important;
            border-right: 2px solid #475569 !important;
        }
        
        body.dark-mode .table thead th:last-child {
            border-right: 2px solid #475569 !important;
        }
        
        body.dark-mode .table tbody tr {
            background: #1e293b !important;
            color: #000000 !important;
            border: 1px solid #334155 !important;
            border-bottom: 2px solid #475569 !important;
            cursor: pointer;
        }
        
        body.dark-mode .table tbody tr:hover,
        body.dark-mode .table tbody tr:active,
        body.dark-mode .table tbody tr:focus {
            background: #334155 !important;
        }
        
        /* تظليل للمس على الشاشات الصغيرة - الوضع الداكن */
        @media (hover: none) and (pointer: coarse) {
            body.dark-mode .table tbody tr:active {
                background: #475569 !important;
                box-shadow: 0 4px 12px rgba(129, 140, 248, 0.3) !important;
                transform: scale(0.99) !important;
            }
        }
        
        body.dark-mode .table tbody td {
            color: #000000 !important;
            border-right: 2px solid #475569 !important;
            font-weight: 700 !important;
        }
        
        body.dark-mode .table tbody td strong {
            color: #000000 !important;
            font-weight: 700 !important;
        }
        
        body.dark-mode .table tbody td small {
            color: #000000 !important;
            font-weight: 700 !important;
        }
        
        body.dark-mode .table tbody td:last-child {
            border-right: 2px solid #475569 !important;
        }
        
        body.dark-mode .table tbody td .text-muted {
            color: #000000 !important;
            font-weight: 700 !important;
        }
        
        /* Badges Colors - Keep them colored */
        body.dark-mode .table .badge.bg-success {
            background: #22c55e !important;
            color: #fff !important;
        }
        
        body.dark-mode .table .badge.bg-danger {
            background: #ef4444 !important;
            color: #fff !important;
        }
        
        body.dark-mode .table .badge.bg-secondary {
            background: #475569 !important;
            color: #fff !important;
        }
        
        body.dark-mode .table .badge.bg-primary {
            background: #6366f1 !important;
            color: #fff !important;
        }
        
        body.dark-mode .table .badge {
            border: none !important;
        }
        
        .table {
            width: 100%;
            min-width: 600px;
            margin-bottom: 0;
            table-layout: auto; /* عرض ديناميكي */
        }
        
        /* أعمدة الجدول - عرض ديناميكي */
        .table th,
        .table td {
            white-space: normal; /* السماح بتكسير النص */
            word-wrap: break-word;
            min-width: 80px; /* عرض أدنى */
        }
        
        /* أعمدة محددة */
        .table th:nth-child(1), /* التاريخ */
        .table td:nth-child(1) {
            min-width: 100px;
            width: 12%;
        }
        
        .table th:nth-child(2), /* التقييم */
        .table td:nth-child(2) {
            min-width: 120px;
            width: 18%;
        }
        
        .table th:nth-child(3), /* النقاط */
        .table td:nth-child(3) {
            min-width: 80px;
            width: 10%;
            text-align: center;
        }
        
        .table th:nth-child(4), /* المعلم */
        .table td:nth-child(4) {
            min-width: 100px;
            width: 15%;
        }
        
        .table th:nth-child(5), /* السبب */
        .table td:nth-child(5) {
            min-width: 150px;
            width: 45%;
        }
        
        /* ======================================
           MODERN TABLE DESIGN - تصميم جدول حديث
           ====================================== */
        
        /* إزالة جميع تنسيقات Bootstrap الافتراضية */
        .table {
            border-collapse: separate !important;
            border-spacing: 0 !important;
            background: transparent !important;
            margin-bottom: 0 !important;
        }
        
        /* رأس الجدول - تصميم عصري نظيف */
        .table thead {
            background: #1e293b !important;
            box-shadow: 0 4px 12px rgba(30, 41, 59, 0.15) !important;
            border-bottom: 3px solid #667eea !important;
        }
        
        .table thead tr {
            border: none !important;
        }
        
        .table thead th {
            padding: 1.2rem 1rem !important;
            font-weight: 700 !important;
            color: #ffffff !important;
            border: none !important;
            font-size: 1rem !important;
            text-align: center !important;
            white-space: nowrap !important;
            text-transform: uppercase !important;
            letter-spacing: 0.5px !important;
            background: transparent !important;
            border-right: 2px solid rgba(255, 255, 255, 0.2) !important;
        }
        
        .table thead th:last-child {
            border-right: 2px solid rgba(255, 255, 255, 0.2) !important;
        }
        
        /* صف الجدول - تصميم بطاقات */
        .table tbody tr {
            background: #ffffff !important;
            box-shadow: 0 2px 4px rgba(0, 0, 0, 0.05) !important;
            margin-bottom: 0.5rem !important;
            transition: all 0.3s ease !important;
            border: 1px solid #e5e7eb !important;
            border-bottom: 2px solid #cbd5e1 !important;
            cursor: pointer;
        }
        
        .table tbody tr:hover,
        .table tbody tr:active,
        .table tbody tr:focus {
            background: #f8f9fa !important;
            box-shadow: 0 4px 12px rgba(102, 126, 234, 0.15) !important;
            transform: translateY(-2px) !important;
        }
        
        /* تظليل للمس على الشاشات الصغيرة */
        @media (hover: none) and (pointer: coarse) {
            .table tbody tr:active {
                background: #e3f2fd !important;
                box-shadow: 0 4px 12px rgba(102, 126, 234, 0.25) !important;
                transform: scale(0.99) !important;
            }
        }
        
        .table tbody td {
            padding: 1rem !important;
            vertical-align: middle !important;
            border: none !important;
            border-right: 2px solid #cbd5e1 !important;
            color: #000000 !important;
            font-size: 0.95rem !important;
            font-weight: 700 !important;
        }
        
        .table tbody td:last-child {
            border-right: 2px solid #cbd5e1 !important;
        }
        
        .table tbody td strong {
            color: #000000 !important;
            font-weight: 700 !important;
        }
        
        .table tbody td small {
            color: #000000 !important;
            font-weight: 700 !important;
        }
        
        .table tbody td .text-muted {
            color: #000000 !important;
            font-weight: 700 !important;
        }
        
        /* ألوان خاصة للنقاط */
        .table tbody td:nth-child(3) {
            font-weight: 700 !important;
            font-size: 1.1rem !important;
        }
        
        /* Dark Mode */
        body.dark-mode .table thead {
            background: #0f172a !important;
            border-bottom: 3px solid #818cf8 !important;
        }
        
        body.dark-mode .table thead th {
            color: #e2e8f0 !important;
            border-right: 2px solid #475569 !important;
        }
        
        body.dark-mode .table tbody tr {
            background: #1e293b !important;
            border-color: #334155 !important;
        }
        
        body.dark-mode .table tbody tr:hover {
            background: #334155 !important;
        }
        
        body.dark-mode .table tbody td {
            color: #000000 !important;
            border-right: 2px solid #475569 !important;
            font-weight: 700 !important;
        }
        
        body.dark-mode .table tbody td strong {
            color: #000000 !important;
            font-weight: 700 !important;
        }
        
        body.dark-mode .table tbody td small {
            color: #000000 !important;
            font-weight: 700 !important;
        }
        
        body.dark-mode .table tbody td .text-muted {
            color: #000000 !important;
            font-weight: 700 !important;
        }
        
        .table tbody tr:hover {
            background: rgba(102, 126, 234, 0.05);
        }
        
        /* Section Headers - تصميم عصري بسيط */
        .section-header {
            background: transparent;
            color: #1e293b;
            padding: 0 0 1rem 0;
            border-radius: 0;
            margin: 0 0 1.5rem 0;
            border-bottom: 2px solid rgba(102, 126, 234, 0.2);
        }
        
        .section-header h5 {
            margin: 0;
            font-weight: 700;
            font-size: 1.5rem;
            color: #1e293b;
            display: flex;
            align-items: center;
            gap: 0.5rem;
            text-shadow: 0 1px 2px rgba(0, 0, 0, 0.05);
        }
        
        .section-header h5 i {
            color: #667eea;
            font-size: 1.4rem;
            filter: drop-shadow(0 2px 4px rgba(102, 126, 234, 0.3));
        }
        
        /* Dark Mode للعناوين */
        body.dark-mode .section-header {
            color: #f1f5f9;
            border-bottom-color: rgba(96, 165, 250, 0.3);
        }
        
        body.dark-mode .section-header h5 {
            color: #f1f5f9;
        }
        
        /* Footer Styles */
        .rewards-footer {
            transition: all 0.3s ease;
            position: relative;
            z-index: 2;
            width: 100%;
            max-width: 100vw;
            overflow-x: hidden;
            margin-left: 0 !important;
            margin-right: 0 !important;
            box-sizing: border-box;
        }
        
        .rewards-footer .container {
            max-width: 100%;
            padding-left: 1rem;
            padding-right: 1rem;
        }
        
        /* Dark Mode - Footer */
        body.dark-mode .rewards-footer {
            color: #94a3b8 !important;
        }
        
        body.dark-mode .rewards-footer .container-fluid {
            color: #94a3b8 !important;
        }
        
        /* Social Media Icons Hover Effects */
        .social-footer-icon:hover {
            transform: translateY(-4px) scale(1.1);
        }
        
        .social-footer-icon.facebook:hover {
            box-shadow: 0 8px 20px rgba(24, 119, 242, 0.6) !important;
        }
        
        .social-footer-icon.whatsapp:hover {
            box-shadow: 0 8px 20px rgba(37, 211, 102, 0.6) !important;
        }
        
        .social-footer-icon.instagram:hover {
            box-shadow: 0 8px 20px rgba(225, 48, 108, 0.6) !important;
        }
        
        /* Mobile Optimizations */
        @media (max-width: 768px) {
            .rewards-main-title {
                font-size: 1.8rem;
            }
            
            .rewards-subtitle {
                font-size: 1rem;
            }
            
            .rewards-card {
                padding: 1.5rem 1rem;
            }
            
            .total-points-display h3 {
                font-size: 2rem;
            }
            
            .rewards-school-logo {
                max-width: 120px;
            }
            
            .level-card {
                margin-bottom: 1rem;
            }
            
            .table-responsive {
                margin: 0 -1rem;
                border-radius: 0;
                width: calc(100% + 2rem);
                padding: 0;
            }
            
            .table {
                font-size: 0.85rem;
                width: 100%;
                min-width: 500px;
            }
            
            /* تقليص حجم الأعمدة على الموبايل */
            .table th,
            .table td {
                padding: 0.6rem 0.4rem;
                font-size: 0.8rem;
            }
            
            .table th {
                font-size: 0.75rem;
            }
            
            /* تصغير عرض الأعمدة */
            .table th:nth-child(1),
            .table td:nth-child(1) {
                min-width: 75px;
            }
            
            .table th:nth-child(2),
            .table td:nth-child(2) {
                min-width: 90px;
            }
            
            .table th:nth-child(3),
            .table td:nth-child(3) {
                min-width: 60px;
            }
            
            .table th:nth-child(4),
            .table td:nth-child(4) {
                min-width: 80px;
            }
            
            .table th:nth-child(5),
            .table td:nth-child(5) {
                min-width: 120px;
            }
            }
            
            .table th,
            .table td {
                padding: 0.5rem 0.4rem;
                white-space: nowrap;
            }
        }
        
        /* ====================================
           ACCORDION STYLES FOR EVALUATIONS
           تنسيقات Accordion التقييمات
           ====================================
        */
        .accordion-button:not(.collapsed) {
            background: linear-gradient(135deg, rgba(102, 126, 234, 0.1) 0%, rgba(118, 75, 162, 0.1) 100%) !important;
            color: #1e293b !important;
            box-shadow: inset 0 -1px 0 rgba(102, 126, 234, 0.3) !important;
        }
        
        .accordion-button:focus {
            box-shadow: 0 0 0 0.25rem rgba(102, 126, 234, 0.25) !important;
            border-color: #667eea !important;
        }
        
        .accordion-button::after {
            filter: brightness(0) saturate(100%) invert(27%) sepia(51%) saturate(2878%) hue-rotate(234deg) brightness(104%) contrast(97%);
        }
        
        .accordion-item {
            border: none !important;
        }
        
        /* Month name responsive sizing */
        .month-name-text {
            font-size: 1.1rem;
            color: #1e293b;
            font-weight: 600;
        }
        
        body.dark-mode .month-name-text {
            color: #f1f5f9 !important;
        }
        
        /* Stats badges responsive */
        .stats-badge {
            padding: 0.5rem 0.75rem;
            font-size: 0.9rem;
            white-space: nowrap;
            font-weight: 600;
        }
        
        /* Points badges container alignment */
        .points-badges-container {
            width: 100%;
        }
        
        /* Points badge in table */
        .points-badge {
            padding: 0.5rem 0.75rem;
            font-size: 0.9rem;
            white-space: nowrap;
        }
        
        /* Date cell formatting */
        .date-cell {
            white-space: nowrap;
        }
        
        .date-cell small {
            font-weight: normal;
        }
        
        /* Mobile optimizations */
        @media (max-width: 767px) {
            .month-name-text {
                font-size: 0.95rem;
            }
            
            .stats-badge {
                padding: 0.35rem 0.55rem;
                font-size: 0.75rem;
                font-weight: 600;
            }
            
            /* تصغير الأيقونات على الموبايل */
            .stats-badge i {
                font-size: 0.7rem;
            }
            
            .points-badge {
                padding: 0.4rem 0.6rem;
                font-size: 0.85rem;
            }
            
            .accordion-button {
                padding: 0.75rem 0.5rem;
                font-size: 0.9rem;
            }
            
            /* التاريخ Bold على الموبايل */
            .date-cell small {
                font-weight: 600;
            }
            
            /* Responsive table - تصغير الخطوط وتقليل المسافات */
            .table {
                font-size: 0.75rem;
            }
            
            .table td,
            .table th {
                padding: 0.4rem 0.25rem;
                vertical-align: top;
            }
            
            /* عرض الأعمدة على الموبايل */
            .table th:nth-child(1),
            .table td:nth-child(1) {
                width: 15%; /* التاريخ */
                font-size: 0.7rem;
            }
            
            .table th:nth-child(2),
            .table td:nth-child(2) {
                width: 30%; /* التقييم */
            }
            
            .table th:nth-child(3),
            .table td:nth-child(3) {
                width: 15%; /* النقاط */
            }
            
            .table th:nth-child(4),
            .table td:nth-child(4) {
                width: 20%; /* المعلم */
                font-size: 0.7rem;
            }
            
            .table th:nth-child(5),
            .table td:nth-child(5) {
                width: 20%; /* السبب */
                font-size: 0.7rem;
            }
            
            .date-cell small {
                font-size: 0.65rem;
            }
        }
        
        /* Small mobile screens */
        @media (max-width: 575px) {
            .month-name-text {
                font-size: 0.85rem;
            }
            
            .stats-badge {
                padding: 0.25rem 0.45rem;
                font-size: 0.7rem;
                font-weight: 600;
            }
            
            .stats-badge i {
                font-size: 0.65rem;
            }
            
            .accordion-button {
                padding: 0.6rem 0.4rem;
                font-size: 0.85rem;
            }
            
            .table {
                font-size: 0.7rem;
            }
            
            .table td,
            .table th {
                padding: 0.3rem 0.2rem;
            }
            
            .points-badge {
                padding: 0.3rem 0.5rem;
                font-size: 0.75rem;
            }
            
            .date-cell small {
                font-size: 0.6rem;
                font-weight: 700;
            }
        }
        
        /* Very small screens - عرض مكثف جداً */
        @media (max-width: 400px) {
            .month-name-text {
                font-size: 0.75rem;
            }
            
            .stats-badge {
                padding: 0.2rem 0.35rem;
                font-size: 0.65rem;
                font-weight: 600;
            }
            
            .stats-badge i {
                font-size: 0.6rem;
                margin-left: 0.15rem !important;
            }
            
            .table {
                font-size: 0.65rem;
            }
            
            .table td,
            .table th {
                padding: 0.25rem 0.15rem;
            }
            
            .points-badge {
                padding: 0.25rem 0.4rem;
                font-size: 0.7rem;
            }
            
            .date-cell small {
                font-weight: 700;
            }
        }
        
        /* Dark Mode for Accordion */
        body.dark-mode .accordion-button {
            background: #1e293b !important;
            color: #f1f5f9 !important;
        }
        
        body.dark-mode .accordion-button:not(.collapsed) {
            background: rgba(129, 140, 248, 0.2) !important;
            color: #ffffff !important;
        }
        
        body.dark-mode .accordion-button:hover {
            background: #334155 !important;
        }
        
        body.dark-mode .accordion-body {
            background: #0f172a !important;
            color: #f1f5f9 !important;
        }
        
        body.dark-mode .accordion-item {
            background: #1e293b !important;
            border-color: #334155 !important;
        }
        
        body.dark-mode .table-light {
            background-color: rgba(51, 65, 85, 0.3) !important;
            color: #e2e8f0 !important;
        }
        
        body.dark-mode .table-hover tbody tr:hover {
            background-color: rgba(102, 126, 234, 0.1) !important;
        }
        
        /* Smooth transitions */
        .accordion-button {
            transition: all 0.3s ease;
        }
        
        .accordion-collapse {
            transition: height 0.35s ease;
        }

        /* ====================================
           LEGENDARY STARS SYSTEM
           نظام النجوم للمستويات الأسطورية
           ====================================
        */
        .level-stars {
            display: inline-block;
            color: #FFD700 !important;
            text-shadow: 0 0 10px rgba(255, 215, 0, 0.5);
            animation: starGlow 2s ease-in-out infinite;
        }

        @keyframes starGlow {
            0%, 100% {
                text-shadow: 0 0 10px rgba(255, 215, 0, 0.5);
            }
            50% {
                text-shadow: 0 0 20px rgba(255, 215, 0, 0.8), 0 0 30px rgba(255, 215, 0, 0.4);
            }
        }

        /* Dark mode stars */
        body.dark-mode .level-stars {
            color: #FFC107 !important;
            text-shadow: 0 0 15px rgba(255, 193, 7, 0.6);
        }

        /* ====================================
           LEGENDARY GOLD COLOR - بطل أسطوري
           بطاقة ذهبية ساطعة مع تنين SVG متحرك حديث
           ====================================
        */
        
        /* Legendary Gold - بطل أسطوري */
        .bg-legendary-gold {
            background-color: #FFD700 !important;
        }
        
        .border-legendary-gold {
            border-color: #FFD700 !important;
        }

        .text-legendary-gold {
            color: #FFD700 !important;
        }

        /* Professional Dragon Icon with Fire Effects */
        .legendary-dragon-wrapper {
            position: relative;
            display: inline-block;
        }

        .dragon-fire-container {
            position: relative;
            display: inline-block;
        }

        /* Dragon Icon - Professional Style - Same size as other icons */
        .legendary-dragon-icon {
            font-size: 3rem;
            background: linear-gradient(135deg, #FFD700 0%, #FFA500 50%, #FF8C00 100%);
            -webkit-background-clip: text;
            -webkit-text-fill-color: transparent;
            background-clip: text;
            filter: drop-shadow(0 4px 12px rgba(255, 215, 0, 0.6));
            display: inline-block;
            position: relative;
            z-index: 2;
        }

        /* Dragon breathing animation */
        .dragon-breathing {
            animation: dragonBreathe 3s ease-in-out infinite;
        }

        @keyframes dragonBreathe {
            0%, 100% {
                transform: scale(1) rotate(0deg);
                filter: drop-shadow(0 4px 12px rgba(255, 215, 0, 0.6));
            }
            50% {
                transform: scale(1.05) rotate(2deg);
                filter: drop-shadow(0 6px 20px rgba(255, 165, 0, 0.8));
            }
        }

        /* Strong pulse animation for current level icons */
        .icon-pulse {
            animation: iconPulse 2s ease-in-out infinite;
        }

        @keyframes iconPulse {
            0%, 100% {
                transform: scale(1);
                opacity: 1;
            }
            50% {
                transform: scale(1.15);
                opacity: 0.85;
            }
        }

        /* Fire Particles */
        .fire-particle {
            position: absolute;
            border-radius: 50%;
            pointer-events: none;
            z-index: 1;
        }

        .fire-p1 {
            width: 12px;
            height: 12px;
            background: radial-gradient(circle, #FF4500 0%, #FF6347 50%, transparent 70%);
            top: 20%;
            right: -20px;
            animation: fireFloat1 2s ease-in-out infinite;
        }

        .fire-p2 {
            width: 10px;
            height: 10px;
            background: radial-gradient(circle, #FF6347 0%, #FFD700 50%, transparent 70%);
            top: 35%;
            right: -30px;
            animation: fireFloat2 1.8s ease-in-out infinite 0.3s;
        }

        .fire-p3 {
            width: 8px;
            height: 8px;
            background: radial-gradient(circle, #FFD700 0%, #FFA500 50%, transparent 70%);
            top: 50%;
            right: -25px;
            animation: fireFloat3 2.2s ease-in-out infinite 0.6s;
        }

        .fire-p4 {
            width: 6px;
            height: 6px;
            background: radial-gradient(circle, #FF8C00 0%, #FFD700 50%, transparent 70%);
            top: 25%;
            right: -35px;
            animation: fireFloat1 1.5s ease-in-out infinite 0.4s;
        }

        .fire-p5 {
            width: 9px;
            height: 9px;
            background: radial-gradient(circle, #FF4500 0%, #FF8C00 50%, transparent 70%);
            top: 42%;
            right: -38px;
            animation: fireFloat2 2s ease-in-out infinite 0.2s;
        }

        @keyframes fireFloat1 {
            0% {
                opacity: 0;
                transform: translate(0, 0) scale(0.5);
            }
            50% {
                opacity: 1;
                transform: translate(15px, -10px) scale(1);
            }
            100% {
                opacity: 0;
                transform: translate(30px, -20px) scale(0.3);
            }
        }

        @keyframes fireFloat2 {
            0% {
                opacity: 0;
                transform: translate(0, 0) scale(0.5);
            }
            50% {
                opacity: 1;
                transform: translate(20px, 0) scale(1);
            }
            100% {
                opacity: 0;
                transform: translate(40px, 5px) scale(0.3);
            }
        }

        @keyframes fireFloat3 {
            0% {
                opacity: 0;
                transform: translate(0, 0) scale(0.5);
            }
            50% {
                opacity: 1;
                transform: translate(18px, 10px) scale(1);
            }
            100% {
                opacity: 0;
                transform: translate(35px, 20px) scale(0.3);
            }
        }

        /* Hover effect for legendary card */
        .level-card.border-legendary-gold:hover .legendary-dragon-icon {
            animation: dragonRoar 0.6s ease-in-out;
        }

        @keyframes dragonRoar {
            0%, 100% {
                transform: scale(1) rotate(0deg);
            }
            25% {
                transform: scale(1.15) rotate(-5deg);
            }
            75% {
                transform: scale(1.15) rotate(5deg);
            }
        }

        .level-card.border-legendary-gold:hover .fire-particle {
            animation-duration: 1s !important;
        }

        /* Dark Mode - Legendary Gold */
        body.dark-mode .bg-legendary-gold {
            background-color: #FFA500 !important;
        }
        
        body.dark-mode .border-legendary-gold {
            border-color: #FFA500 !important;
        }
        
        body.dark-mode .text-legendary-gold {
            color: #FFD700 !important;
        }
        
        body.dark-mode .level-card.border-legendary-gold {
            background: linear-gradient(135deg, #2C2416 0%, #3D3020 100%) !important;
        }
        
        body.dark-mode .legendary-dragon-container {
            filter: drop-shadow(0 4px 20px rgba(255, 165, 0, 0.6));
        }
    </style>
    <!-- Student page specific fixes -->
    <link rel="stylesheet" href="<?php echo asset_url('../assets/css/student-fixes.css'); ?>">
    <!-- Dark mode & particles styles centralized in global stylesheet -->
</head>
<body class="student-page">
    
    <!-- Particles Background - نفس البوابة -->
    <div id="particles-js"></div>
    
    <!-- School Logo - نفس تصميم البوابة -->
    <div class="rewards-logo-section" style="position: relative; z-index: 2;">
        <a href="portal.php" title="العودة للبوابة الرئيسية">
            <img src="<?php echo asset_url('../assets/img/logo.png'); ?>" alt="School Logo" class="rewards-school-logo">
        </a>
    </div>
    
    <!-- Page Title - نفس تصميم البوابة -->
    <div class="rewards-title-section" style="position: relative; z-index: 2;">
        <h1 class="rewards-main-title">
            <i class="fas fa-award" style="color: #FFC107;"></i>
             النقاط والمكافآت
        </h1>
        <p class="rewards-subtitle">تابع نقاطك ومكافآتك</p>
    </div>

    <!-- Main Content Container -->
    <div class="rewards-main-container" style="position: relative; z-index: 2;">
        
        <?php
        // === Student Notifications ===
        require_once '../includes/notifications_helper.php';
        $studentNotifications = getStudentNotifications($db, $_SESSION['user_id'], $user->class_id ?? null);
        if (!empty($studentNotifications)):
        ?>
        <div style="max-width: 900px; margin: 0 auto 1.5rem auto; padding: 0 15px;">
            <?php echo renderNotificationAlerts($studentNotifications, '../api/dismiss_notification.php'); ?>
        </div>
        <?php endif; ?>
        
        <!-- زر العودة للبوابة -->
        <div class="text-center mb-4">
            <a href="portal.php" class="back-to-portal-btn">
                <i class="fas fa-home"></i>
                العودة للبوابة الرئيسية
            </a>
        </div>
        
        <!-- Points Summary Card - بتصميم البوابة -->
        <div class="rewards-card">
            <div class="section-header">
                <h5><i class="fas fa-chart-line me-2"></i>ملخص النقاط</h5>
            </div>
            
            <div class="row align-items-center justify-content-center">
                <div class="col-lg-4 col-md-6 text-center mb-3">
                    <!-- Total Points - البطاقة الرئيسية -->
                    <div class="points-card total-points-card p-4 rounded shadow-sm mb-3">
                        <i class="fas fa-star fa-3x mb-3" style="color: #FFC107; filter: drop-shadow(0 2px 4px rgba(255,193,7,0.5));"></i>
                        <div class="total-points-display">
                            <h3 class="mb-2 fw-bold" style="color: #667eea; font-size: 3.5rem; text-shadow: 0 2px 6px rgba(102,126,234,0.3);">
                                <?php echo $total_points; ?>
                            </h3>
                            <p class="mb-0 fw-bold" style="color: #667eea; font-size: 1.3rem;">إجمالي النقاط</p>
                        </div>
                    </div>
                    
                    <!-- Positive & Negative Points -->
                    <div class="row g-2">
                        <div class="col-6">
                            <div class="points-card positive-points-card p-3 rounded shadow-sm">
                                <i class="fas fa-plus-circle text-success fa-2x mb-2"></i>
                                <div class="fw-bold fs-4 text-success">+<?php echo $positive_points; ?></div>
                                <small class="text-dark fw-semibold">إيجابية</small>
                            </div>
                        </div>
                        <div class="col-6">
                            <div class="points-card negative-points-card p-3 rounded shadow-sm">
                                <i class="fas fa-minus-circle text-danger fa-2x mb-2"></i>
                                <div class="fw-bold fs-4 text-danger">-<?php echo $negative_points; ?></div>
                                <small class="text-dark fw-semibold">سلبية</small>
                            </div>
                        </div>
                    </div>
                </div>
                
                <div class="col-lg-8 col-md-6">
                    <!-- Student Level Display -->
                    <div class="points-card p-4 rounded shadow-sm" style="background: linear-gradient(135deg, rgba(102, 126, 234, 0.1) 0%, rgba(118, 75, 162, 0.1) 100%); border: 2px solid #667eea;">
                        <div class="text-center mb-3">
                            <i class="fas <?php echo $levelData['current']['icon']; ?> fa-4x mb-3" style="color: var(--bs-<?php echo $levelData['current']['color']; ?>);"></i>
                            <h4 class="mb-2 fw-bold" style="color: var(--bs-<?php echo $levelData['current']['color']; ?>);">
                                <?php echo $levelData['current']['name']; ?>
                                <?php 
                                if (isset($levelData['stars_display']) && !empty($levelData['stars_display'])) {
                                    echo '<span style="color: #FFD700; font-size: 0.85em;">' . $levelData['stars_display'] . '</span>';
                                }
                                ?>
                            </h4>
                            <p class="text-dark mb-3 fw-semibold">مستواك الحالي</p>
                        </div>
                        
                        <!-- Progress to Next Level -->
                        <?php if ($levelData['next']): ?>
                            <div class="mb-3">
                                <div class="d-flex justify-content-between mb-2">
                                    <span class="small fw-bold text-dark">
                                        <?php echo $levelData['current']['name']; ?>
                                        <?php echo isset($levelData['current']['stars']) && $levelData['current']['stars'] > 0 ? ' ' . str_repeat('⭐', $levelData['current']['stars']) : ''; ?>
                                    </span>
                                    <span class="small fw-bold text-dark">
                                        <?php echo $levelData['next']['name']; ?>
                                        <?php echo isset($levelData['next']['stars']) && $levelData['next']['stars'] > 0 ? ' ' . str_repeat('⭐', $levelData['next']['stars']) : ''; ?>
                                    </span>
                                </div>
                                <div class="progress" style="height: 20px; border-radius: 10px;">
                                    <div class="progress-bar progress-bar-striped progress-bar-animated bg-<?php echo $levelData['current']['color']; ?>" 
                                         role="progressbar" 
                                         style="width: <?php echo $levelData['progress']; ?>%"
                                         aria-valuenow="<?php echo $levelData['progress']; ?>" 
                                         aria-valuemin="0" 
                                         aria-valuemax="100">
                                        <strong><?php echo $levelData['progress']; ?>%</strong>
                                    </div>
                                </div>
                                <p class="text-center mt-2 mb-0 small text-dark fw-semibold">
                                    <i class="fas fa-arrow-up text-success"></i>
                                    تحتاج <strong class="text-primary"><?php echo $levelData['points_to_next']; ?> نقطة</strong> للوصول لمستوى "<strong class="text-success"><?php echo $levelData['next']['name']; ?></strong>"
                                </p>
                            </div>
                        <?php else: ?>
                            <div class="alert alert-success text-center mb-0">
                                <i class="fas fa-trophy fa-2x mb-2"></i>
                                <h5 class="mb-0">🎉 تهانينا! وصلت للمستوى الأعلى!</h5>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
        </div>
        
        <!-- Evaluations List - بتصميم البوابة مع Accordion بالشهور -->
        <div class="rewards-card">
            <div class="section-header">
                <h5><i class="fas fa-list-alt me-2"></i>سجل التقييمات</h5>
            </div>
            
            <?php if (!empty($evaluationsByMonth)): ?>
                <div class="accordion" id="evaluationsAccordion">
                    <?php 
                    $accordionIndex = 0;
                    foreach ($evaluationsByMonth as $monthKey => $monthData): 
                        $accordionIndex++;
                        $isCurrentMonth = ($monthKey === $currentMonth);
                        $collapseId = "collapse" . $accordionIndex;
                        
                        // حساب اللون بناءً على النقاط
                        $totalPoints = $monthData['total_points'];
                        $headerClass = $totalPoints >= 0 ? 'border-success' : 'border-danger';
                        $badgeClass = $totalPoints >= 0 ? 'bg-success' : 'bg-danger';
                        $iconClass = $totalPoints >= 0 ? 'fa-smile' : 'fa-frown';
                    ?>
                        <div class="accordion-item mb-3 border-2 <?php echo $headerClass; ?>" style="border-radius: 12px; overflow: hidden; box-shadow: 0 2px 8px rgba(0,0,0,0.1);">
                            <h2 class="accordion-header" id="heading<?php echo $accordionIndex; ?>">
                                <button class="accordion-button <?php echo !$isCurrentMonth ? 'collapsed' : ''; ?>" 
                                        type="button" 
                                        data-bs-toggle="collapse" 
                                        data-bs-target="#<?php echo $collapseId; ?>" 
                                        aria-expanded="<?php echo $isCurrentMonth ? 'true' : 'false'; ?>" 
                                        aria-controls="<?php echo $collapseId; ?>"
                                        style="background: linear-gradient(135deg, rgba(102, 126, 234, 0.05) 0%, rgba(118, 75, 162, 0.05) 100%); font-weight: 600;">
                                    <div class="d-flex flex-column w-100 pe-2 pe-md-3 gap-2 gap-md-3">
                                        <!-- السطر الأول: اسم الشهر وعدد التقييمات -->
                                        <div class="d-flex align-items-center justify-content-between w-100">
                                            <div class="d-flex align-items-center">
                                                <i class="far fa-calendar-alt me-2 me-md-3 d-none d-md-inline" style="font-size: 1.5rem; color: #667eea;"></i>
                                                <i class="far fa-calendar-alt me-2 d-md-none" style="font-size: 1.2rem; color: #667eea;"></i>
                                                <span class="month-name-text">
                                                    <?php echo $monthData['name']; ?>
                                                    <?php if ($isCurrentMonth): ?>
                                                        <span class="badge bg-primary ms-2" style="font-size: 0.75rem;">الشهر الحالي</span>
                                                    <?php endif; ?>
                                                </span>
                                            </div>
                                            <span class="badge bg-secondary stats-badge">
                                                <i class="fas fa-list me-1"></i>
                                                <span><?php echo $monthData['count']; ?> تقييم</span>
                                            </span>
                                        </div>
                                        
                                        <!-- السطر الثاني: النقاط (إيجابية، سلبية، إجمالي) -->
                                        <div class="d-flex align-items-center gap-2 gap-md-3 justify-content-end points-badges-container">
                                            <span class="badge bg-success stats-badge">
                                                <i class="fas fa-plus-circle me-1"></i>
                                                <span>+<?php echo $monthData['positive_points']; ?></span>
                                            </span>
                                            <span class="badge bg-danger stats-badge">
                                                <i class="fas fa-minus-circle me-1"></i>
                                                <span>-<?php echo $monthData['negative_points']; ?></span>
                                            </span>
                                            <span class="badge <?php echo $badgeClass; ?> stats-badge">
                                                <i class="fas <?php echo $iconClass; ?> me-1"></i>
                                                <span><?php echo $totalPoints >= 0 ? '+' : ''; ?><?php echo $totalPoints; ?> الإجمالي</span>
                                            </span>
                                        </div>
                                    </div>
                                </button>
                            </h2>
                            <div id="<?php echo $collapseId; ?>" 
                                 class="accordion-collapse collapse <?php echo $isCurrentMonth ? 'show' : ''; ?>" 
                                 aria-labelledby="heading<?php echo $accordionIndex; ?>" 
                                 data-bs-parent="#evaluationsAccordion">
                                <div class="accordion-body p-0">
                                    <!-- جدول التقييمات -->
                                    <div class="table-responsive">
                                        <table class="table table-hover align-middle mb-0">
                                            <thead class="table-light">
                                                <tr>
                                                    <th>التاريخ</th>
                                                    <th>التقييم</th>
                                                    <th>النقاط</th>
                                                    <th>المعلم</th>
                                                    <th>السبب</th>
                                                </tr>
                                            </thead>
                                            <tbody>
                                                <?php foreach ($monthData['evaluations'] as $row): ?>
                                                    <tr>
                                                        <td>
                                                            <div class="date-cell">
                                                                <small class="d-block"><?php echo date('Y-m-d', strtotime($row['date_created'])); ?></small>
                                                                <small class="text-muted d-block"><?php echo date('h:i A', strtotime($row['date_created'])); ?></small>
                                                            </div>
                                                        </td>
                                                        <td>
                                                            <strong><?php echo $row['evaluation_name']; ?></strong>
                                                        </td>
                                                        <td>
                                                            <?php 
                                                            $badge_class = $row['display_type'] == 'positive' ? 'bg-success' : 'bg-danger';
                                                            $sign = $row['display_points'] >= 0 ? '+' : '';
                                                            ?>
                                                            <span class="badge <?php echo $badge_class; ?> points-badge">
                                                                <?php echo $sign . $row['display_points']; ?>
                                                            </span>
                                                        </td>
                                                        <td>
                                                            <small><?php echo $row['teacher_name']; ?></small>
                                                        </td>
                                                        <td>
                                                            <?php if (!empty($row['reason'])): ?>
                                                                <span class="text-muted"><small><?php echo htmlspecialchars($row['reason']); ?></small></span>
                                                            <?php else: ?>
                                                                <span class="text-muted">-</span>
                                                            <?php endif; ?>
                                                        </td>
                                                    </tr>
                                                <?php endforeach; ?>
                                            </tbody>
                                        </table>
                                    </div>
                                </div>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>
            <?php else: ?>
                <div class="alert alert-info mb-0">
                    <i class="fas fa-info-circle me-2"></i>
                    لا توجد تقييمات حتى الآن.
                </div>
            <?php endif; ?>
        </div>
        <!-- Levels Guide - بتصميم البوابة -->
        <div class="rewards-card">
            <div class="section-header">
                <h5><i class="fas fa-trophy me-2"></i>دليل المستويات</h5>
            </div>
            
            <div class="row g-3">
                <?php 
                // Define levels for display (with legendary levels and stars - stars start from متفوق)
                // Using Bootstrap 5 standard colors only
                $all_levels = [
                    ['name' => 'مبتدئ', 'min' => 0, 'max' => 10, 'color' => 'secondary', 'icon' => 'fa-seedling', 'stars' => 0],
                    ['name' => 'متطور', 'min' => 11, 'max' => 25, 'color' => 'info', 'icon' => 'fa-leaf', 'stars' => 0],
                    ['name' => 'جيد', 'min' => 26, 'max' => 50, 'color' => 'primary', 'icon' => 'fa-tree', 'stars' => 0],
                    ['name' => 'ممتاز', 'min' => 51, 'max' => 75, 'color' => 'success', 'icon' => 'fa-star', 'stars' => 0],
                    ['name' => 'متفوق', 'min' => 76, 'max' => 100, 'color' => 'warning', 'icon' => 'fa-crown', 'stars' => 1],
                    ['name' => 'بطل', 'min' => 101, 'max' => 150, 'color' => 'danger', 'icon' => 'fa-trophy', 'stars' => 2],
                    ['name' => 'بطل ذهبي', 'min' => 151, 'max' => 200, 'color' => 'warning', 'icon' => 'fa-medal', 'stars' => 3],
                    ['name' => 'بطل ماسي', 'min' => 201, 'max' => 250, 'color' => 'info', 'icon' => 'fa-gem', 'stars' => 4],
                    ['name' => 'بطل أسطوري', 'min' => 251, 'max' => 999, 'color' => 'legendary-gold', 'icon' => 'fa-dragon', 'stars' => 5]
                ];
                
                foreach ($all_levels as $level): 
                    $is_current = ($level['name'] == $levelData['current']['name']);
                    $is_achieved = ($total_points >= $level['min']);
                ?>
                    <div class="col-lg-4 col-md-6">
                        <?php 
                        // Handle custom legendary-gold color with bright and beautiful gold
                        $border_color = ($level['color'] == 'legendary-gold') ? '#FFD700' : 'var(--bs-' . $level['color'] . ')';
                        $icon_color = ($level['color'] == 'legendary-gold') ? '#FFD700' : 'var(--bs-' . $level['color'] . ')';
                        $text_color = ($level['color'] == 'legendary-gold') ? '#B8860B' : 'var(--bs-' . $level['color'] . ')';
                        
                        // Background for legendary gold card - bright and shiny
                        if ($level['color'] == 'legendary-gold') {
                            $bg_style = $is_current 
                                ? 'linear-gradient(135deg, #FFF9E6 0%, #FFE5B4 50%, #FFDEAD 100%)'
                                : 'linear-gradient(135deg, #FFFAF0 0%, #FFF8DC 100%)';
                        } else {
                            $bg_style = $is_current ? 'linear-gradient(135deg, rgba(102, 126, 234, 0.1) 0%, rgba(118, 75, 162, 0.1) 100%)' : 'white';
                        }
                        ?>
                        <div class="level-card border-<?php echo $level['color']; ?> <?php echo $is_current ? 'current-level' : ''; ?>" 
                             style="padding: 1.5rem; border-radius: 15px; border: 2px solid <?php echo $border_color; ?>; background: <?php echo $bg_style; ?>;">
                            <div class="text-center">
                                <?php if ($level['color'] == 'legendary-gold'): ?>
                                    <!-- Professional Dragon with Fire -->
                                    <div class="legendary-dragon-wrapper mb-3">
                                        <div class="dragon-fire-container">
                                            <!-- Fire particles behind dragon -->
                                            <div class="fire-particle fire-p1"></div>
                                            <div class="fire-particle fire-p2"></div>
                                            <div class="fire-particle fire-p3"></div>
                                            <div class="fire-particle fire-p4"></div>
                                            <div class="fire-particle fire-p5"></div>
                                            
                                            <!-- Dragon Icon -->
                                            <i class="fas fa-dragon legendary-dragon-icon <?php echo $is_current ? 'dragon-breathing' : ''; ?>"></i>
                                        </div>
                                    </div>
                                <?php else: ?>
                                    <i class="fas <?php echo $level['icon']; ?> fa-3x mb-3 <?php echo $is_current ? 'icon-pulse' : ''; ?>" 
                                       style="color: <?php echo $icon_color; ?>;"></i>
                                <?php endif; ?>
                                <h5 class="mb-2 fw-bold" style="color: <?php echo $text_color; ?>;">
                                    <?php echo $level['name']; ?>
                                    <?php 
                                    if (isset($level['stars']) && $level['stars'] > 0) {
                                        echo ' <span style="color: #FFD700; font-size: 0.85em;">' . str_repeat('⭐', $level['stars']) . '</span>';
                                    }
                                    ?>
                                </h5>
                                <p class="small text-dark fw-semibold mb-3">
                                    <?php echo $level['min']; ?> - <?php echo $level['max'] == 999 ? '∞' : $level['max']; ?> نقطة
                                </p>
                                <?php if ($is_current): ?>
                                    <span class="badge current-level-badge px-3 py-2" style="background: #667eea !important; color: #ffffff !important; font-size: 0.95rem; border: none !important; box-shadow: 0 2px 6px rgba(102, 126, 234, 0.3) !important;">
                                        <i class="fas fa-check-circle me-1"></i>
                                        مستواك الحالي
                                    </span>
                                <?php elseif ($is_achieved): ?>
                                    <span class="badge bg-success px-3 py-2">
                                        <i class="fas fa-check me-1"></i>
                                        مُنجز
                                    </span>
                                <?php else: ?>
                                    <span class="badge bg-secondary text-white px-3 py-2">
                                        <i class="fas fa-lock me-1"></i>
                                        مقفل
                                    </span>
                                <?php endif; ?>
                            </div>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>
        </div>
    </div>

    <!-- Footer - بدون خلفية مثل Materials -->
    <footer class="rewards-footer" style="padding: 2rem 0; margin: 3rem 0 0 0;">
        <div class="container-fluid text-center" style="color: #64748b; max-width: 100%; padding: 0 2rem;">
            <p class="mb-2" style="font-size: 1rem;">
                <strong>جميع الحقوق محفوظة © <?php echo date('Y'); ?></strong>
            </p>
            <p class="mb-0" style="font-size: 0.95rem;">
                Delta Modern Language Schools<br>
                Computer Department
            </p>
            
            <!-- Social Media Icons in Footer -->
            <div class="social-media-footer" style="display: flex; justify-content: center; gap: 15px; margin-top: 1.5rem; margin-bottom: 0.5rem;">
                <a href="https://www.facebook.com/DELTA.MLS" target="_blank" class="social-footer-icon facebook" title="صفحتنا على الفيسبوك" style="width: 48px; height: 48px; border-radius: 50%; display: flex; align-items: center; justify-content: center; color: white; text-decoration: none; font-size: 1.3rem; transition: all 0.3s ease; box-shadow: 0 4px 12px rgba(0, 0, 0, 0.3); background: linear-gradient(135deg, #1877f2 0%, #0c63d4 100%);">
                    <i class="fab fa-facebook-f"></i>
                </a>
                <a href="https://wa.me/201289999818" target="_blank" class="social-footer-icon whatsapp" title="الدعم الفني - واتساب" style="width: 48px; height: 48px; border-radius: 50%; display: flex; align-items: center; justify-content: center; color: white; text-decoration: none; font-size: 1.3rem; transition: all 0.3s ease; box-shadow: 0 4px 12px rgba(0, 0, 0, 0.3); background: linear-gradient(135deg, #25d366 0%, #128c7e 100%);">
                    <i class="fab fa-whatsapp"></i>
                </a>
                <a href="https://www.instagram.com/delta.mls" target="_blank" class="social-footer-icon instagram" title="حسابنا على انستجرام" style="width: 48px; height: 48px; border-radius: 50%; display: flex; align-items: center; justify-content: center; color: white; text-decoration: none; font-size: 1.3rem; transition: all 0.3s ease; box-shadow: 0 4px 12px rgba(0, 0, 0, 0.3); background: linear-gradient(135deg, #e1306c 0%, #c13584 50%, #833ab4 100%);">
                    <i class="fab fa-instagram"></i>
                </a>
            </div>
        </div>
    </footer>

    <!-- jQuery -->
    <script src="https://code.jquery.com/jquery-3.7.1.min.js"></script>
    <!-- Bootstrap JS -->
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
    
    <!-- Particles.js Library - نفس البوابة -->
    <script src="https://cdn.jsdelivr.net/particles.js/2.0.0/particles.min.js"></script>
    
    <!-- Dark Mode & Particles Theme Toggle Script - نفس البوابة -->
    <script src="script.js?v=<?php echo time(); ?>"></script>
    
    <!-- Existing Custom JS -->
    <script src="../assets/js/main.js"></script>
    
    <!-- Force Apply Styles Script - فرض تطبيق التنسيقات -->
    <script>
        // تطبيق الأنماط بعد تحميل الصفحة مباشرة
        document.addEventListener('DOMContentLoaded', function() {
            console.log('🔍 CSS Application Check Started...');
            console.log('⏰ Page Load Time:', new Date().toLocaleString('ar-EG'));
            console.log('📝 Version:', '<?php echo time(); ?>');
            
            // Force apply badge styles
            const badges = document.querySelectorAll('.current-level-badge');
            console.log('🏷️ Found', badges.length, 'current-level-badge elements');
            
            badges.forEach((badge, index) => {
                const computedStyle = window.getComputedStyle(badge);
                console.log(`Badge ${index + 1}:`);
                console.log('  - Background:', computedStyle.backgroundColor);
                console.log('  - Color:', computedStyle.color);
                console.log('  - Has inline style:', badge.hasAttribute('style'));
                
                // Force apply correct color - نفس لون أيقونة اسم المستخدم
                badge.style.setProperty('background', '#667eea', 'important');
                badge.style.setProperty('background-color', '#667eea', 'important');
                badge.style.setProperty('color', '#ffffff', 'important');
                badge.style.setProperty('border', 'none', 'important');
                badge.style.setProperty('box-shadow', '0 2px 6px rgba(102, 126, 234, 0.3)', 'important');
                
                console.log('  ✅ Styles force-applied!');
            });
            
            // Check points cards
            const totalCard = document.querySelector('.total-points-card');
            if (totalCard) {
                const bgColor = window.getComputedStyle(totalCard).backgroundColor;
                const borderRight = window.getComputedStyle(totalCard).borderRightColor;
                console.log('📊 Total Points Card:');
                console.log('  - Background:', bgColor);
                console.log('  - Border Right:', borderRight);
                
                // Expected: rgb(255, 255, 255) and rgb(102, 126, 234)
                if (bgColor !== 'rgb(255, 255, 255)') {
                    console.warn('  ⚠️ Background not white! Forcing...');
                    totalCard.style.setProperty('background', '#ffffff', 'important');
                }
            }
            
            console.log('✅ CSS Application Check Completed!');
            console.log('💡 Open DevTools Console to see this report');
        });
        
        // Prevent any caching
        window.addEventListener('beforeunload', function() {
            // Clear session storage
            sessionStorage.clear();
        });
        
        // Log warning if page loaded from cache
        if (performance.navigation.type === 2) {
            console.warn('⚠️ PAGE LOADED FROM CACHE! Press Ctrl+Shift+R to force refresh.');
        }
    </script>
</body>
</html>
