<?php
/**
 * أرشيف الدروس المحضرة
 * Lesson Archive Page
 */
// تحميل إعدادات الجلسة
require_once '../includes/session_config.php';
require_once '../classes/utilities.php';
require_once '../includes/csrf.php';
// التحقق من تسجيل الدخول (يسمح للمعلمين الداخليين والخارجيين)
if (!isset($_SESSION['user_id']) || !in_array($_SESSION['role'], ['teacher', 'external_teacher'])) {
    header('Location: ../index.php');
    exit;
}
requireCsrfPost();
require_once '../config/database.php';
require_once '../classes/LessonGenerator.php';
$database = new Database();
$db = $database->getConnection();
$teacherId = $_SESSION['user_id'];
$generator = new LessonGenerator($db, $teacherId);
// معالجة الحذف (عبر POST فقط مع تحقق CSRF)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['delete_lesson_id']) && is_numeric($_POST['delete_lesson_id'])) {
        $lessonId = intval($_POST['delete_lesson_id']);
        if ($generator->deleteLesson($lessonId)) {
            $successMessage = 'تم حذف الدرس بنجاح';
        } else {
            $errorMessage = 'فشل في حذف الدرس';
        }
}
// الحصول على الدروس
$lessons = $generator->getTeacherLessons(200);
// الحصول على الامتحانات المنشورة
$publishedExams = [];
try {
    $stmt = $db->prepare("SELECT id, lesson_id, exam_code FROM ai_online_exams WHERE teacher_id = ? AND is_active = 1");
    $stmt->execute([$teacherId]);
    while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
        $publishedExams[$row['lesson_id']] = $row;
    }
}
catch (Exception $e) {
// الجدول قد لا يكون موجوداً
}
// حساب الإحصائيات
$stats = [
    'total' => count($lessons),
    'completed' => 0,
    'draft' => 0,
    'with_exam' => count($publishedExams),
    'this_month' => 0
];
$currentMonth = date('Y-m');
foreach ($lessons as $lesson) {
    if ($lesson['status'] === 'completed')
        $stats['completed']++;
    if ($lesson['status'] === 'draft')
        $stats['draft']++;
    if (substr($lesson['created_at'], 0, 7) === $currentMonth)
        $stats['this_month']++;
}
// جمع القيم الفريدة للفلاتر
$uniqueSubjects = [];
$uniqueGrades = [];
$uniqueLanguages = [];
$uniqueStatuses = [];
// جلب الصفوف النشطة أولاً لترتيبها الصحيح (استبعاد الصفوف التجريبية)
try {
    $gradeStmt = $db->prepare("
        SELECT grade_name 
        FROM grades 
        WHERE status = 'active' 
          AND (is_experimental = 0 OR is_experimental IS NULL)
          AND grade_code NOT LIKE '%test%' 
          AND grade_code NOT LIKE '%qa%' 
          AND LOWER(grade_name) NOT LIKE '%test%' 
          AND grade_name NOT LIKE '%تجريب%' 
        ORDER BY grade_order ASC
    ");
    $gradeStmt->execute();
    while ($gRow = $gradeStmt->fetch(PDO::FETCH_ASSOC)) {
        if (!empty($gRow['grade_name'])) {
            $uniqueGrades[$gRow['grade_name']] = true;
        }
    }
} catch (Exception $e) {}
foreach ($lessons as $lesson) {
    if (!empty($lesson['subject']))
        $uniqueSubjects[$lesson['subject']] = true;
    if (!empty($lesson['grade_level'])) {
        $glLower = mb_strtolower($lesson['grade_level']);
        if (!str_contains($glLower, 'test') && !str_contains($glLower, 'تجريب')) {
            $uniqueGrades[$lesson['grade_level']] = true;
        }
    }
    if (!empty($lesson['language']))
        $uniqueLanguages[$lesson['language']] = true;
    if (!empty($lesson['status']))
        $uniqueStatuses[$lesson['status']] = true;
}
$teacher_name = isset($_SESSION['name']) ? $_SESSION['name'] : 'المعلم';
$statusLabels = [
    'draft' => 'مسودة',
    'generating' => 'قيد التوليد',
    'completed' => 'مكتمل',
    'error' => 'خطأ'
];
$langLabels = [
    'ar' => 'عربي',
    'en' => 'English',
    'fr' => 'Français'
];
function getLessonTypeDetails($title) {
    if (str_contains($title, '[امتحان مستقل]') || str_contains($title, 'امتحان مستقل')) {
        $cleanTitle = trim(str_replace(['[امتحان مستقل]', 'امتحان مستقل -', 'امتحان مستقل'], '', $title));
        return [
            'key' => 'exam',
            'label' => 'امتحان إلكتروني',
            'clean_title' => $cleanTitle ?: $title,
            'icon' => 'fa-file-signature',
            'class' => 'pill-type-exam'
        ];
    }
    if (str_contains($title, '[بنك أسئلة مستقل]') || str_contains($title, 'بنك أسئلة مستقل') || str_contains($title, '[بنك أسئلة]')) {
        $cleanTitle = trim(str_replace(['[بنك أسئلة مستقل]', '[بنك أسئلة]', 'بنك أسئلة مستقل -', 'بنك أسئلة مستقل'], '', $title));
        return [
            'key' => 'qbank',
            'label' => 'بنك أسئلة',
            'clean_title' => $cleanTitle ?: $title,
            'icon' => 'fa-database',
            'class' => 'pill-type-qbank'
        ];
    }
    if (str_contains($title, '[PowerPoint]') || str_contains($title, '[عرض تقديمي]') || str_contains($title, 'PowerPoint') || str_contains($title, 'عرض تقديمي')) {
        $cleanTitle = trim(str_replace(['[PowerPoint]', '[عرض تقديمي]', 'PowerPoint -', 'عرض تقديمي -'], '', $title));
        return [
            'key' => 'powerpoint',
            'label' => 'عرض تقديمي',
            'clean_title' => $cleanTitle ?: $title,
            'icon' => 'fa-file-powerpoint',
            'class' => 'pill-type-powerpoint'
        ];
    }
    return [
        'key' => 'lesson',
        'label' => 'تحضير درس',
        'clean_title' => $title,
        'icon' => 'fa-book-open',
        'class' => 'pill-type-lesson'
    ];
}
?>
<!DOCTYPE html>
<html lang="ar" dir="rtl">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <script src="../assets/js/datatable-state.js?v=3"></script>
    <title>أرشيف الدروس - EduCore</title>
    <!-- Bootstrap RTL CSS -->
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.rtl.min.css">
    <link href="https://fonts.googleapis.com/css2?family=Cairo:wght@300;400;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
    <!-- DataTables -->
    <link rel="stylesheet" href="https://cdn.datatables.net/1.13.6/css/dataTables.bootstrap5.min.css">
    <link rel="stylesheet" href="../assets/css/premium-dashboard.css">
    <link rel="stylesheet" href="../assets/css/buttons.css?v=2.0">
    <link rel="stylesheet" href="../assets/css/admin-unified.css?v=2.0">
    <style>
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }
        body {
            font-family: 'Cairo', sans-serif;
            background: #fafbfc;
            min-height: 100vh;
            display: flex;
            flex-direction: column;
            direction: rtl;
            transition: all 0.3s ease;
            overflow-x: hidden;
        }
        body.dark-mode {
            background: #0f1419;
        }
        #particles-js {
            position: fixed;
            width: 100%;
            height: 100%;
            top: 0;
            left: 0;
            z-index: 0;
            pointer-events: none;
        }
        .main-container {
            flex: 1;
            width: 100%;
            max-width: 1400px;
            margin: 0 auto;
            padding: 20px;
            position: relative;
            z-index: 1;
        }
        #lessonsTable {
            width: 100% !important;
            table-layout: auto;
        }
        #lessonsTable th {
            white-space: nowrap !important;
            padding: 12px 10px !important;
            vertical-align: middle !important;
        }
        #lessonsTable td {
            padding: 10px 10px !important;
            vertical-align: middle !important;
        }
        .lesson-title-cell {
            display: flex;
            align-items: center;
            gap: 8px;
            flex-wrap: wrap;
            min-width: 220px;
        }
        .lesson-title-text {
            font-weight: 700;
            color: #1e293b;
            font-size: 0.92rem;
            line-height: 1.4;
            display: inline-block;
        }
        .actions-column {
            min-width: 250px !important;
            width: 250px !important;
            white-space: nowrap !important;
            text-align: center !important;
        }
        .actions-cell {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            gap: 4px;
            white-space: nowrap;
            max-width: 100%;
        }
        .actions-cell .btn-action-pills {
            margin: 0 !important;
            flex-shrink: 0;
        }
        /* Unified Modern Archive Badges */
        .archive-pill {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            gap: 5px;
            padding: 4px 9px;
            font-size: 0.8rem;
            font-weight: 600;
            line-height: 1.2;
            border-radius: 7px;
            white-space: nowrap;
            letter-spacing: 0.01em;
            box-shadow: 0 1px 2px rgba(0, 0, 0, 0.03);
            transition: all 0.2s ease;
        }
        /* Type Badges */
        .pill-type-lesson {
            background: #ecfdf5;
            color: #047857;
            border: 1px solid #a7f3d0;
        }
        .pill-type-exam {
            background: #eff6ff;
            color: #1d4ed8;
            border: 1px solid #bfdbfe;
        }
        .pill-type-qbank {
            background: #f5f3ff;
            color: #6d28d9;
            border: 1px solid #ddd6fe;
        }
        .pill-type-powerpoint {
            background: #eef2ff;
            color: #4338ca;
            border: 1px solid #c7d2fe;
        }
        /* Language Badges */
        .pill-lang {
            background: #f8fafc;
            color: #334155;
            border: 1px solid #e2e8f0;
            font-weight: 600;
        }
        /* Duration Badge */
        .pill-duration {
            background: #f8fafc;
            color: #475569;
            border: 1px solid #e2e8f0;
            font-weight: 600;
            font-variant-numeric: tabular-nums;
        }
        /* Status Badges */
        .pill-status-completed {
            background: #f0fdf4;
            color: #15803d;
            border: 1px solid #bbf7d0;
            font-weight: 700;
        }
        .pill-status-draft {
            background: #fffbeb;
            color: #b45309;
            border: 1px solid #fde68a;
            font-weight: 700;
        }
        .pill-status-generating {
            background: #f0f9ff;
            color: #0369a1;
            border: 1px solid #bae6fd;
            font-weight: 700;
        }
        .pill-status-error {
            background: #fef2f2;
            color: #b91c1c;
            border: 1px solid #fecaca;
            font-weight: 700;
        }
        /* Subject Badge */
        .pill-subject {
            background: #f8fafc;
            color: #1e293b;
            border: 1px solid #e2e8f0;
            font-weight: 600;
        }
        body.dark-mode .main-container {
            /* Standard look */
        }
        /* Header */
        .page-header {
            background: white;
            border-radius: 20px;
            padding: 30px;
            margin-bottom: 25px;
            box-shadow: 0 10px 40px rgba(0, 0, 0, 0.1);
            display: flex;
            justify-content: space-between;
            align-items: center;
            flex-wrap: wrap;
            gap: 20px;
            position: relative;
            overflow: hidden;
        }
        .page-header::before {
            content: '';
            position: absolute;
            bottom: 0;
            left: 0;
            right: 0;
            height: 4px;
            background: linear-gradient(90deg, #10b981, #3b82f6, #8b5cf6, #ec4899);
            border-radius: 0 0 20px 20px;
        }
        .page-header::after {
            content: '';
            position: absolute;
            top: 0;
            left: 0;
            right: 0;
            height: 4px;
            background: linear-gradient(90deg, #10b981, #3b82f6, #8b5cf6, #ec4899);
            border-radius: 20px 20px 0 0;
        }
        body.dark-mode .page-header {
            background: #1e293b;
        }
        .header-title {
            display: flex;
            align-items: center;
            gap: 15px;
        }
        .header-icon {
            width: 85px;
            height: 85px;
            position: relative;
            flex-shrink: 0;
        }
        .header-icon-ring {
            width: 85px;
            height: 85px;
            border-radius: 50%;
            background: linear-gradient(135deg, #1e3a5f, #10b981, #3b82f6);
            padding: 3px;
            animation: headerIconRotate 8s linear infinite;
            position: absolute;
            top: 0;
            left: 0;
        }
        .header-icon-inner {
            width: 100%;
            height: 100%;
            border-radius: 50%;
            background: linear-gradient(135deg, #1e293b, #0f172a);
            display: flex;
            align-items: center;
            justify-content: center;
            position: relative;
            overflow: hidden;
        }
        body.dark-mode .header-icon-inner {
            background: linear-gradient(135deg, #0f172a, #020617);
        }
        .header-icon-inner::before {
            content: '';
            position: absolute;
            width: 35px;
            height: 35px;
            background: radial-gradient(circle, rgba(139, 92, 246, 0.35) 0%, transparent 70%);
            animation: headerIconPulse 3s ease-in-out infinite;
        }
        .header-icon-inner i {
            font-size: 2.2rem;
            background: linear-gradient(135deg, #a78bfa, #60a5fa, #34d399);
            -webkit-background-clip: text;
            -webkit-text-fill-color: transparent;
            background-clip: text;
            position: relative;
            z-index: 2;
            filter: drop-shadow(0 0 8px rgba(139, 92, 246, 0.4));
        }
        @keyframes headerIconRotate {
            from { transform: rotate(0deg); }
            to { transform: rotate(360deg); }
        }
        @keyframes headerIconPulse {
            0%, 100% { transform: scale(1); opacity: 0.5; }
            50% { transform: scale(1.3); opacity: 0.8; }
        }
        .header-text h1 {
            font-size: 1.8rem;
            color: #1e293b;
            margin-bottom: 5px;
        }
        body.dark-mode .header-text h1 {
            color: #f1f5f9;
        }
        .header-text p {
            color: #64748b;
            font-size: 1rem;
        }
        body.dark-mode .header-text p {
            color: #94a3b8;
        }
        .header-actions {
            display: flex;
            gap: 12px;
        }
        .btn-action {
            padding: 12px 24px;
            border-radius: 12px;
            font-weight: 600;
            text-decoration: none;
            display: flex;
            align-items: center;
            gap: 8px;
            transition: all 0.3s ease;
            border: none;
            cursor: pointer;
            font-family: 'Cairo', sans-serif;
        }
        .btn-back, .portal-back-btn {
            background: linear-gradient(135deg, #2563eb, #1d4ed8);
            color: #fff;
            box-shadow: 0 3px 12px rgba(37,99,235,0.35);
        }
        .btn-back:hover, .portal-back-btn:hover {
            background: linear-gradient(135deg, #3b82f6, #2563eb);
            color: #fff;
            transform: translateY(-2px);
            box-shadow: 0 5px 18px rgba(37,99,235,0.45);
        }
        /* Force no horizontal scrollbar on desktop screens */
        @media (min-width: 992px) {
            .admin-table-wrap,
            .table-responsive,
            .admin-list-surface,
            .dataTables_wrapper {
                overflow-x: visible !important;
                overflow: visible !important;
            }
            #lessonsTable {
                table-layout: fixed !important;
                width: 100% !important;
            }
        }
        /* Align DataTables Search & Length controls flush with table outer borders */
        .dataTables_wrapper .row:first-child {
            display: flex !important;
            flex-direction: row-reverse !important;
            align-items: center !important;
            justify-content: space-between !important;
            width: 100% !important;
            max-width: 100% !important;
            margin: 0 0 1.25rem 0 !important;
            padding: 0 !important;
        }
        .dataTables_wrapper .row:first-child > [class*="col-"] {
            padding-left: 0 !important;
            padding-right: 0 !important;
            margin-left: 0 !important;
            margin-right: 0 !important;
            width: auto !important;
            max-width: 100% !important;
        }
        .dataTables_wrapper .dataTables_filter {
            text-align: right !important;
            margin: 0 !important;
            padding: 0 !important;
            float: right !important;
        }
        .dataTables_wrapper .dataTables_filter label {
            margin: 0 !important;
            padding: 0 !important;
            display: inline-flex !important;
            align-items: center !important;
            gap: 6px !important;
        }
        .dataTables_wrapper .dataTables_filter input {
            margin-right: 0 !important;
            margin-left: 0 !important;
        }
        .dataTables_wrapper .dataTables_length {
            text-align: left !important;
            margin: 0 !important;
            padding: 0 !important;
            float: left !important;
        }
        .dataTables_wrapper .dataTables_length label {
            margin: 0 !important;
            padding: 0 !important;
            display: inline-flex !important;
            align-items: center !important;
            gap: 6px !important;
        }
        .dataTables_wrapper .dataTables_length select {
            margin-right: 0 !important;
            margin-left: 0 !important;
        }
        /* Filter Card */
        .filter-card {
            background: #ffffff !important;
            border-radius: 16px !important;
            padding: 22px 26px !important;
            margin-bottom: 25px !important;
            box-shadow: 0 10px 30px rgba(15, 23, 42, 0.06) !important;
            border: 1px solid #e2e8f0 !important;
            position: relative !important;
            z-index: 2 !important;
        }
        body.dark-mode .filter-card {
            background: #1e293b !important;
            border-color: #334155 !important;
            box-shadow: 0 10px 30px rgba(0, 0, 0, 0.4) !important;
        }
        .filter-title {
            font-size: 1.05rem !important;
            font-weight: 700 !important;
            color: #0f172a !important;
            margin-bottom: 18px !important;
            display: flex !important;
            align-items: center !important;
            gap: 10px !important;
            padding-bottom: 12px !important;
            border-bottom: 1px solid #e2e8f0 !important;
        }
        body.dark-mode .filter-title {
            color: #f8fafc !important;
            border-bottom-color: #334155 !important;
        }
        .filter-title i {
            color: #2563eb !important;
            font-size: 1.1rem !important;
        }
        .filter-row {
            display: flex !important;
            gap: 16px !important;
            flex-wrap: wrap !important;
            align-items: flex-end !important;
        }
        .filter-group {
            flex: 1 !important;
            min-width: 150px !important;
        }
        .filter-group label {
            display: flex !important;
            align-items: center !important;
            gap: 6px !important;
            font-size: 0.85rem !important;
            font-weight: 700 !important;
            color: #334155 !important;
            margin-bottom: 8px !important;
        }
        .filter-group label i {
            color: #2563eb !important;
            font-size: 0.9rem !important;
        }
        body.dark-mode .filter-group label {
            color: #cbd5e1 !important;
        }
        .filter-group select,
        .filter-group input.flatpickr-date {
            width: 100% !important;
            height: 44px !important;
            padding: 0 14px !important;
            border: 1.5px solid #cbd5e1 !important;
            border-radius: 12px !important;
            font-size: 0.9rem !important;
            font-family: 'Cairo', sans-serif !important;
            background: #ffffff !important;
            transition: all 0.25s ease !important;
            color: #0f172a !important;
            font-weight: 600 !important;
            box-shadow: 0 2px 5px rgba(0, 0, 0, 0.02) !important;
        }
        body.dark-mode .filter-group select,
        body.dark-mode .filter-group input.flatpickr-date {
            background: #0f172a !important;
            border-color: #334155 !important;
            color: #f1f5f9 !important;
        }
        .filter-group select:hover,
        .filter-group input.flatpickr-date:hover {
            border-color: #94a3b8 !important;
        }
        .filter-group select:focus,
        .filter-group input.flatpickr-date:focus {
            outline: none !important;
            border-color: #2563eb !important;
            box-shadow: 0 0 0 3.5px rgba(37, 99, 235, 0.15) !important;
            background: #ffffff !important;
        }
        body.dark-mode .filter-group select:focus,
        body.dark-mode .filter-group input.flatpickr-date:focus {
            border-color: #60a5fa !important;
            box-shadow: 0 0 0 3.5px rgba(96, 165, 250, 0.2) !important;
            background: #0f172a !important;
        }
        .btn-filter-reset {
            height: 44px !important;
            padding: 0 20px !important;
            border: 1.5px solid #cbd5e1 !important;
            border-radius: 12px !important;
            background: #f8fafc !important;
            color: #475569 !important;
            font-weight: 700 !important;
            font-size: 0.9rem !important;
            font-family: 'Cairo', sans-serif !important;
            cursor: pointer !important;
            transition: all 0.25s ease !important;
            display: inline-flex !important;
            align-items: center !important;
            justify-content: center !important;
            gap: 8px !important;
            white-space: nowrap !important;
            box-shadow: 0 2px 5px rgba(0, 0, 0, 0.02) !important;
        }
        .btn-filter-reset:hover {
            background: #fef2f2 !important;
            border-color: #fca5a5 !important;
            color: #ef4444 !important;
            transform: translateY(-1px) !important;
        }
        body.dark-mode .btn-filter-reset {
            background: #0f172a !important;
            border-color: #334155 !important;
            color: #94a3b8 !important;
        }
        body.dark-mode .btn-filter-reset:hover {
            background: #450a0a !important;
            border-color: #ef4444 !important;
            color: #fca5a5 !important;
        }
        /* Main Card */
        .content-card {
            background: #ffffff !important;
            border-radius: 20px !important;
            padding: 24px 20px !important;
            box-shadow: 0 10px 30px rgba(15, 23, 42, 0.08) !important;
            margin-bottom: 25px !important;
            border: 1px solid #cbd5e1 !important;
            position: relative !important;
            z-index: 2 !important;
        }
        body.dark-mode .content-card {
            background: #1e293b !important;
            border: 1px solid #334155 !important;
        }
        /* Alert Messages */
        .alert {
            padding: 15px 20px;
            border-radius: 12px;
            margin-bottom: 20px;
            display: flex;
            align-items: center;
            gap: 12px;
        }
        .alert-success {
            background: #dcfce7;
            border: 1px solid #22c55e;
            color: #166534;
        }
        .alert-error {
            background: #fee2e2;
            border: 1px solid #ef4444;
            color: #991b1b;
        }
        /* Table Styles */
        .table-wrapper {
            border-radius: 12px;
            overflow-x: auto;
            border: none !important;
            box-shadow: none !important;
            background: transparent !important;
            margin: 0 !important;
            padding: 0 !important;
            width: 100% !important;
        }
        @media (min-width: 992px) {
            .table-wrapper {
                overflow-x: visible !important;
            }
            #lessonsTable {
                table-layout: fixed !important;
                width: 100% !important;
            }
        }
        body.dark-mode .table-wrapper {
            border: none !important;
            box-shadow: none !important;
            background: transparent !important;
        }
        .table {
            color: #1e293b;
            margin-bottom: 0 !important;
            border-collapse: separate;
            border-spacing: 0;
            border: none !important;
        }
        .table-wrapper .table,
        .table-wrapper .table thead,
        .table-wrapper .table tbody,
        .table-wrapper .table tr,
        .table-wrapper .table th {
            border-color: transparent !important;
            outline: none !important;
            box-shadow: none !important;
        }
        .dataTables_wrapper {
            border: none !important;
        }
        body.dark-mode .table {
            color: #f1f5f9;
        }
        .table thead th {
            background: linear-gradient(135deg, #1e3a5f, #0f172a);
            color: #e2e8f0;
            font-weight: 700;
            padding: 12px 10px;
            border: none;
            text-align: right;
            font-size: 0.88rem;
            letter-spacing: 0.02em;
            white-space: nowrap;
            position: static !important;
        }
        .table thead th:first-child {
            border-radius: 0 !important;
        }
        .table thead th:last-child {
            border-radius: 0 !important;
        }
        body.dark-mode .table thead th {
            background: linear-gradient(135deg, #0f172a, #1e293b) !important;
            color: #94a3b8 !important;
        }
        .table tbody td {
            padding: 10px 10px;
            vertical-align: middle;
            border-bottom: 1px solid #f1f5f9;
            transition: background 0.15s ease;
        }
        body.dark-mode .table tbody td {
            border-color: #1e293b !important;
            background-color: transparent !important;
            color: #f1f5f9 !important;
        }
        .table tbody tr:nth-child(even) {
            background: #f8fafc;
        }
        body.dark-mode .table tbody tr:nth-child(even) td {
            background-color: #0f172a !important;
        }
        body.dark-mode .table tbody tr.odd td {
            background-color: #1e293b !important;
        }
        .table tbody tr:hover td {
            background: rgba(30, 58, 95, 0.06) !important;
        }
        body.dark-mode .table tbody tr:hover td {
            background-color: rgba(30, 58, 95, 0.45) !important;
        }
        /* Straight Table Edges - Zero Border Radius Everywhere */
        .table,
        .table thead,
        .table tbody,
        .table tr,
        .table th,
        .table td,
        .table thead th,
        .table tbody td,
        .table tbody tr:last-child td:first-child,
        .table tbody tr:last-child td:last-child {
            border-radius: 0 !important;
        }
        /* Row number styling */
        .table tbody td:first-child {
            font-weight: 700;
            color: #667eea;
            text-align: center;
            min-width: 45px;
        }
        body.dark-mode .table tbody td:first-child {
            color: #a5b4fc;
        }
        /* Title column */
        .lesson-title-cell {
            display: flex;
            align-items: center;
            gap: 10px;
        }
        .lesson-title-cell .title-icon {
            width: 36px;
            height: 36px;
            border-radius: 10px;
            background: linear-gradient(135deg, #ede9fe, #dbeafe);
            display: flex;
            align-items: center;
            justify-content: center;
            flex-shrink: 0;
        }
        .lesson-title-cell .title-icon i {
            color: #667eea;
            font-size: 0.9rem;
        }
        body.dark-mode .lesson-title-cell .title-icon {
            background: linear-gradient(135deg, #312e81, #1e3a8a);
        }
        body.dark-mode .lesson-title-cell .title-icon i {
            color: #a5b4fc;
        }
        .lesson-title-text {
            font-weight: 600;
            color: #1e293b;
            line-height: 1.4;
        }
        body.dark-mode .lesson-title-text {
            color: #f1f5f9;
        }
        /* Duration badge */
        .duration-badge {
            display: inline-flex;
            align-items: center;
            gap: 4px;
            padding: 4px 10px;
            border-radius: 8px;
            font-size: 0.82rem;
            font-weight: 600;
            background: #f0f9ff;
            color: #0369a1;
            white-space: nowrap;
        }
        body.dark-mode .duration-badge {
            background: #0c4a6e;
            color: #7dd3fc;
        }
        /* Date styling */
        .date-cell {
            font-size: 0.85rem;
            color: #64748b;
            white-space: nowrap;
        }
        .date-cell .date-day {
            font-weight: 600;
            color: #1e293b;
        }
        body.dark-mode .date-cell .date-day {
            color: #e2e8f0;
        }
        body.dark-mode .date-cell {
            color: #94a3b8;
        }
        /* Status Badges */
        .status-badge {
            padding: 6px 14px;
            border-radius: 20px;
            font-size: 0.85rem;
            font-weight: 600;
            white-space: nowrap;
        }
        .status-completed {
            background: #dcfce7;
            color: #166534;
        }
        .status-generating {
            background: #fef3c7;
            color: #92400e;
        }
        .status-error {
            background: #fee2e2;
            color: #991b1b;
        }
        .status-draft {
            background: #e2e8f0;
            color: #475569;
        }
        /* Action Buttons */
        .actions-cell {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            gap: 4px;
            white-space: nowrap;
            flex-wrap: nowrap;
        }
        .action-results {
            background: #f0fdf4;
            color: #15803d;
            border-color: #86efac;
        }
        .action-results:hover {
            background: #15803d;
            color: white;
            border-color: #15803d;
        }
        .action-btn:hover {
            transform: translateY(-2px);
            box-shadow: 0 4px 14px rgba(0, 0, 0, 0.12);
        }
        /* Dark mode action buttons */
        body.dark-mode .action-view {
            background: #1e3a5f;
            color: #93c5fd;
            border-color: #1e40af;
        }
        body.dark-mode .action-view:hover {
            background: #3b82f6;
            color: white;
            border-color: #3b82f6;
        }
        body.dark-mode .action-download {
            background: #064e3b;
            color: #6ee7b7;
            border-color: #065f46;
        }
        body.dark-mode .action-download:hover {
            background: #10b981;
            color: white;
            border-color: #10b981;
        }
        body.dark-mode .action-delete {
            background: #450a0a;
            color: #fca5a5;
            border-color: #7f1d1d;
        }
        body.dark-mode .action-delete:hover {
            background: #ef4444;
            color: white;
            border-color: #ef4444;
        }
        body.dark-mode .action-results {
            background: #052e16;
            color: #86efac;
            border-color: #14532d;
        }
        body.dark-mode .action-results:hover {
            background: #22c55e;
            color: white;
            border-color: #22c55e;
        }
        /* Empty State */
        .empty-state {
            text-align: center;
            padding: 60px 20px;
        }
        .empty-state-icon {
            font-size: 5rem;
            color: #cbd5e1;
            margin-bottom: 20px;
        }
        .empty-state h3 {
            color: #64748b;
            margin-bottom: 15px;
        }
        .empty-state p {
            color: #94a3b8;
            margin-bottom: 25px;
        }
        /* Language Badge */
        .lang-badge {
            padding: 4px 10px;
            border-radius: 6px;
            font-size: 0.8rem;
            font-weight: 600;
            white-space: nowrap;
        }
        .lang-ar {
            background: #dbeafe;
            color: #1e40af;
        }
        .lang-en {
            background: #fef3c7;
            color: #92400e;
        }
        .lang-fr {
            background: #ede9fe;
            color: #5b21b6;
        }
        .subject-badge {
            padding: 4px 12px;
            border-radius: 8px;
            font-size: 0.82rem;
            font-weight: 600;
            background: #f0fdf4;
            color: #166534;
            white-space: nowrap;
        }
        body.dark-mode .subject-badge {
            background: #064e3b;
            color: #6ee7b7;
        }
        /* DataTables RTL Fix */
        .dataTables_wrapper .dataTables_filter {
            float: left !important;
            text-align: left;
            margin-left: 30px;
        }
        .dataTables_wrapper .dataTables_length {
            float: right !important;
            text-align: right;
            margin-right: 30px;
        }
        .dataTables_wrapper .dataTables_info {
            float: right;
            color: #64748b;
        }
        body.dark-mode .dataTables_wrapper .dataTables_info {
            color: #94a3b8;
        }
        .dataTables_wrapper .dataTables_paginate {
            float: left;
        }
        .dataTables_wrapper .row {
            margin-bottom: 20px;
        }
        .dataTables_wrapper .dataTables_filter input,
        .dataTables_wrapper .dataTables_length select {
            border: 2px solid #e2e8f0;
            border-radius: 8px;
            padding: 8px 12px;
            margin: 0 8px;
            background: #f8fafc;
        }
        body.dark-mode .dataTables_wrapper .dataTables_filter input,
        body.dark-mode .dataTables_wrapper .dataTables_length select {
            background: #0f172a;
            border-color: #334155;
            color: #f1f5f9;
        }
        .dataTables_wrapper .dataTables_filter label,
        .dataTables_wrapper .dataTables_length label {
            color: #1e293b;
            font-weight: 600;
        }
        body.dark-mode .dataTables_wrapper .dataTables_filter label,
        body.dark-mode .dataTables_wrapper .dataTables_length label {
            color: #f1f5f9;
        }
        /* Responsive */
        @media (max-width: 768px) {
            .page-header {
                flex-direction: column;
                text-align: center;
            }
            .header-title {
                flex-direction: column;
            }
            .header-actions {
                width: 100%;
                justify-content: center;
            }
            .action-btn {
                padding: 6px 10px;
                font-size: 0.75rem;
            }
            .filter-row {
                flex-direction: column;
            }
            .filter-group {
                min-width: 100%;
            }
            .stats-grid {
                grid-template-columns: repeat(2, 1fr);
            }
            .stat-card .stat-number {
                font-size: 1.5rem;
            }
            .stat-card .stat-icon {
                font-size: 1.4rem;
            }
        }
        @media (max-width: 480px) {
            .stats-grid {
                grid-template-columns: repeat(2, 1fr);
                gap: 10px;
            }
            .stat-card {
                padding: 12px;
            }
        }
        /* Delete Modal */
        .modal-content {
            border-radius: 16px;
            border: none;
            overflow: hidden;
        }
        body.dark-mode .modal-content {
            background: #1e293b;
        }
        .modal-header.bg-danger {
            background: linear-gradient(135deg, #ef4444, #dc2626) !important;
            border: none;
        }
        .modal-body {
            padding: 25px 30px;
        }
        body.dark-mode .modal-body {
            color: #f1f5f9;
        }
        .modal-footer {
            border-color: #e2e8f0;
        }
        body.dark-mode .modal-footer {
            border-color: #334155;
        }
        .delete-icon-wrapper {
            text-align: center;
            margin-bottom: 15px;
        }
        .delete-icon-wrapper i {
            font-size: 3rem;
            color: #ef4444;
        }
        .delete-warning {
            background: #fef2f2;
            border: 1px solid #fecaca;
            border-radius: 10px;
            padding: 12px 15px;
            color: #991b1b;
            font-size: 0.9rem;
        }
        body.dark-mode .delete-warning {
            background: #450a0a;
            border-color: #7f1d1d;
            color: #fca5a5;
        }
        /* Dark Mode - Additional Styles */
        body.dark-mode .btn-back {
            background: linear-gradient(135deg, #3b82f6, #2563eb);
            color: #fff;
            box-shadow: 0 3px 12px rgba(37,99,235,0.3);
        }
        body.dark-mode .btn-back:hover {
            background: linear-gradient(135deg, #60a5fa, #3b82f6);
            color: #fff;
        }
        body.dark-mode .alert-success {
            background: #052e16;
            border-color: #15803d;
            color: #4ade80;
        }
        body.dark-mode .alert-error {
            background: #450a0a;
            border-color: #b91c1c;
            color: #fca5a5;
        }
        body.dark-mode .status-completed {
            background: #064e3b;
            color: #6ee7b7;
        }
        body.dark-mode .status-generating {
            background: #78350f;
            color: #fcd34d;
        }
        body.dark-mode .status-error {
            background: #450a0a;
            color: #fca5a5;
        }
        body.dark-mode .status-draft {
            background: #334155;
            color: #94a3b8;
        }
        body.dark-mode .lang-ar {
            background: #1e3a5f;
            color: #93c5fd;
        }
        body.dark-mode .lang-en {
            background: #78350f;
            color: #fcd34d;
        }
        body.dark-mode .lang-fr {
            background: #2e1065;
            color: #c4b5fd;
        }
        body.dark-mode .empty-state-icon {
            color: #475569;
        }
        body.dark-mode .empty-state h3 {
            color: #94a3b8;
        }
        body.dark-mode .empty-state p {
            color: #64748b;
        }
        body.dark-mode .dataTables_wrapper .pagination .page-link {
            color: #94a3b8 !important;
            background-color: #1e293b !important;
            border-color: #334155 !important;
        }
        body.dark-mode .dataTables_wrapper .pagination .page-item.disabled .page-link {
            color: #475569 !important;
            background-color: #0f172a !important;
            border-color: #1e293b !important;
        }
        body.dark-mode .dataTables_wrapper .pagination .page-link:hover {
            color: #f1f5f9 !important;
            background-color: #334155 !important;
            border-color: #475569 !important;
        }
        body.dark-mode .dataTables_wrapper .pagination .page-item.active .page-link {
            color: white !important;
            background: linear-gradient(135deg, #667eea, #764ba2) !important;
            border-color: #667eea !important;
        }
        body.dark-mode .dataTables_wrapper .dataTables_info,
        body.dark-mode .dataTables_wrapper .dataTables_length,
        body.dark-mode .dataTables_wrapper .dataTables_filter {
            color: #94a3b8 !important;
        }
        body.dark-mode .dataTables_wrapper .dataTables_length select,
        body.dark-mode .dataTables_wrapper .dataTables_filter input {
            background-color: #0f172a !important;
            border-color: #334155 !important;
            color: #f1f5f9 !important;
        }
        /* Footer Styling */
        .portal-footer {
            background: transparent !important;
            padding: 2rem 0 1.5rem 0 !important;
            border-top: none !important;
            margin-top: 35px !important;
            position: relative !important;
            z-index: 10 !important;
        }
        body.dark-mode .portal-footer {
            background: transparent !important;
            border-top: none !important;
        }
        .portal-footer p {
            margin: 0 0 0.5rem 0 !important;
            color: #1e293b !important;
            font-size: 1rem !important;
            font-weight: 700 !important;
        }
        body.dark-mode .portal-footer p {
            color: #f8fafc !important;
        }
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
            transform: translateY(-3px) scale(1.1);
        }
        /* ===== Enhanced Mobile Responsive ===== */
        @media (max-width: 768px) {
            .main-container {
                margin: 10px auto;
                padding: 10px;
            }
            .page-header {
                padding: 20px 15px;
                border-radius: 14px;
            }
            .header-icon {
                width: 70px !important;
                height: 70px !important;
            }
            .header-icon-ring {
                width: 70px !important;
                height: 70px !important;
            }
            .header-icon-inner i {
                font-size: 1.8rem !important;
            }
            .header-text h1 {
                font-size: 1.3rem !important;
            }
            .header-text p {
                font-size: 0.85rem !important;
            }
            .content-card {
                padding: 15px 12px;
                border-radius: 14px;
            }
            .table-wrapper {
                overflow-x: auto;
                -webkit-overflow-scrolling: touch;
            }
            .table th, .table td {
                padding: 10px 8px !important;
                font-size: 0.82rem !important;
            }
            .lesson-title-cell {
                min-width: 160px;
            }
            .actions-cell {
                min-width: 100px;
            }
            .dataTables_wrapper .dataTables_filter,
            .dataTables_wrapper .dataTables_length {
                float: none !important;
                text-align: center !important;
                margin: 8px 0 !important;
            }
            .dataTables_wrapper .dataTables_filter label,
            .dataTables_wrapper .dataTables_length label {
                margin-left: 0 !important;
                margin-right: 0 !important;
            }
            .dataTables_wrapper .dataTables_info,
            .dataTables_wrapper .dataTables_paginate {
                float: none !important;
                text-align: center !important;
                margin: 8px 0 !important;
            }
        }
        @media (max-width: 480px) {
            .main-container {
                margin: 5px;
                padding: 6px;
            }
            .page-header {
                padding: 15px 10px;
            }
            .header-icon {
                width: 60px !important;
                height: 60px !important;
            }
            .header-icon-ring {
                width: 60px !important;
                height: 60px !important;
            }
            .header-icon-inner i {
                font-size: 1.5rem !important;
            }
            .header-text h1 {
                font-size: 1.1rem !important;
            }
            .content-card {
                padding: 10px 8px;
                border-radius: 12px;
            }
            .table th, .table td {
                padding: 8px 5px !important;
                font-size: 0.75rem !important;
            }
            .action-btn {
                padding: 4px 6px !important;
                font-size: 0.7rem !important;
            }
            .stat-card {
                padding: 10px 8px;
            }
            .stat-card .stat-number {
                font-size: 1.2rem;
            }
        }
    </style>
</head>
<body>
    <div class="main-container">
        <!-- Unified Page Heading -->
        <div class="admin-page-heading mb-4">
            <h1 class="h2"><i class="fas fa-archive me-2 text-primary"></i>أرشيف الدروس</h1>
            <div class="admin-top-actions no-print">
                <a href="lesson_prep.php" class="btn btn-header-premium btn-success shadow-sm">
                    <i class="fas fa-plus-circle me-1"></i>تحضير درس جديد
                </a>
                <a href="<?php echo ($_SESSION['role'] === 'external_teacher') ? '../external/index.php' : 'portal.php'; ?>" class="btn btn-header-premium btn-import-soft">
                    <i class="fas fa-arrow-right me-1"></i>العودة للبوابة
                </a>
            </div>
        </div>
        <!-- Unified Stat Cards with Count-Up -->
        <div class="dashboard-canvas sortable-dashboard mb-4">
            <div class="row row-cols-1 row-cols-sm-2 row-cols-xl-5 g-3 sortable-dashboard" id="widget-archive-stats">
                <div class="col">
                    <div class="stat-card" style="--card-gradient: linear-gradient(135deg, #6366f1, #4f46e5);">
                        <div class="stat-card-icon"><i class="fas fa-book-open"></i></div>
                        <div class="stat-card-info">
                            <div class="stat-card-number"><span class="counter" data-target="<?php echo (int) $stats['total']; ?>">0</span></div>
                            <div class="stat-card-label">إجمالي الدروس</div>
                        </div>
                    </div>
                </div>
                <div class="col">
                    <div class="stat-card" style="--card-gradient: linear-gradient(135deg, #10b981, #059669);">
                        <div class="stat-card-icon"><i class="fas fa-check-circle"></i></div>
                        <div class="stat-card-info">
                            <div class="stat-card-number"><span class="counter" data-target="<?php echo (int) $stats['completed']; ?>">0</span></div>
                            <div class="stat-card-label">دروس مكتملة</div>
                        </div>
                    </div>
                </div>
                <div class="col">
                    <div class="stat-card" style="--card-gradient: linear-gradient(135deg, #0ea5e9, #0284c7);">
                        <div class="stat-card-icon"><i class="fas fa-calendar-check"></i></div>
                        <div class="stat-card-info">
                            <div class="stat-card-number"><span class="counter" data-target="<?php echo (int) $stats['this_month']; ?>">0</span></div>
                            <div class="stat-card-label">هذا الشهر</div>
                        </div>
                    </div>
                </div>
                <div class="col">
                    <div class="stat-card" style="--card-gradient: linear-gradient(135deg, #f59e0b, #d97706);">
                        <div class="stat-card-icon"><i class="fas fa-file-alt"></i></div>
                        <div class="stat-card-info">
                            <div class="stat-card-number"><span class="counter" data-target="<?php echo (int) $stats['with_exam']; ?>">0</span></div>
                            <div class="stat-card-label">امتحانات منشورة</div>
                        </div>
                    </div>
                </div>
                <div class="col">
                    <div class="stat-card" style="--card-gradient: linear-gradient(135deg, #8b5cf6, #7c3aed);">
                        <div class="stat-card-icon"><i class="fas fa-pencil-alt"></i></div>
                        <div class="stat-card-info">
                            <div class="stat-card-number"><span class="counter" data-target="<?php echo (int) $stats['draft']; ?>">0</span></div>
                            <div class="stat-card-label">مسودات</div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
        <!-- Alert Messages -->
        <?php if (isset($successMessage)): ?>
        <div class="alert alert-success mb-3">
            <i class="fas fa-check-circle me-1"></i> <?php echo $successMessage; ?>
        </div>
        <?php endif; ?>
        <?php if (isset($errorMessage)): ?>
        <div class="alert alert-danger mb-3">
            <i class="fas fa-exclamation-circle me-1"></i> <?php echo $errorMessage; ?>
        </div>
        <?php endif; ?>
        <?php if (!empty($lessons)): ?>
        <!-- Unified Filter Bar -->
        <form id="lessonArchiveFilterForm" class="admin-filter-bar mb-3" novalidate>
            <div class="admin-filter-title w-100 d-flex align-items-center gap-2 mb-2 pb-2 border-bottom">
                <i class="fas fa-filter text-primary me-1"></i>
                <span class="fw-bold text-dark" style="font-size: 0.95rem;">خيارات الفلترة والتصفية</span>
            </div>
            <div class="admin-filter-controls">
                <select id="filterType" class="form-select form-select-sm admin-inline-select-sm" onchange="applyFilters()" aria-label="نوع المحتوى">
                    <option value="">جميع الأنواع</option>
                    <option value="lesson">تحضير درس</option>
                    <option value="exam">امتحان إلكتروني</option>
                    <option value="qbank">بنك أسئلة</option>
                    <option value="powerpoint">عرض تقديمي</option>
                </select>
                <?php if (!empty($uniqueSubjects)): ?>
                <select id="filterSubject" class="form-select form-select-sm admin-inline-select-sm" onchange="applyFilters()" aria-label="المادة">
                    <option value="">جميع المواد</option>
                    <?php foreach (array_keys($uniqueSubjects) as $subj): ?>
                        <option value="<?php echo htmlspecialchars($subj); ?>"><?php echo htmlspecialchars($subj); ?></option>
                    <?php endforeach; ?>
                </select>
                <?php endif; ?>
                <?php if (!empty($uniqueGrades)): ?>
                <select id="filterGrade" class="form-select form-select-sm admin-inline-select-sm" onchange="applyFilters()" aria-label="الصف الدراسي">
                    <option value="">جميع الصفوف</option>
                    <?php foreach (array_keys($uniqueGrades) as $grd): ?>
                        <option value="<?php echo htmlspecialchars($grd); ?>"><?php echo htmlspecialchars($grd); ?></option>
                    <?php endforeach; ?>
                </select>
                <?php endif; ?>
                <select id="filterLanguage" class="form-select form-select-sm admin-inline-select-sm" onchange="applyFilters()" aria-label="اللغة">
                    <option value="">جميع اللغات</option>
                    <?php foreach (array_keys($uniqueLanguages) as $lang): ?>
                        <option value="<?php echo htmlspecialchars($lang); ?>"><?php echo htmlspecialchars($langLabels[$lang] ?? $lang); ?></option>
                    <?php endforeach; ?>
                </select>
                <select id="filterStatus" class="form-select form-select-sm admin-inline-select-sm" onchange="applyFilters()" aria-label="الحالة">
                    <option value="">جميع الحالات</option>
                    <?php foreach (array_keys($uniqueStatuses) as $st): ?>
                        <option value="<?php echo htmlspecialchars($st); ?>"><?php echo htmlspecialchars($statusLabels[$st] ?? $st); ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="admin-filter-actions">
                <button type="button" class="btn btn-light btn-sm" onclick="resetFilters()">
                    <i class="fas fa-rotate-left me-1"></i>إعادة تعيين
                </button>
            </div>
        </form>
        <?php endif; ?>
        <!-- Content Surface -->
        <div class="admin-list-surface">
            <?php if (empty($lessons)): ?>
            <div class="empty-state p-5 text-center">
                <div class="empty-state-icon mb-3" style="font-size: 3rem;">📚</div>
                <h3>لا توجد دروس محفوظة</h3>
                <p class="text-muted">ابدأ بتحضير درسك الأول باستخدام الذكاء الاصطناعي</p>
                <a href="lesson_prep.php" class="btn btn-success shadow-sm">
                    <i class="fas fa-plus me-1"></i> تحضير درس جديد
                </a>
            </div>
            <?php else: ?>
            <div class="table-responsive admin-table-wrap">
            <table class="table table-hover table-striped datatable admin-data-table align-middle" id="lessonsTable">
                <thead>
                    <tr>
                        <th class="text-center" style="width: 40px; min-width: 40px;">#</th>
                        <th style="min-width: 220px;">عنوان الدرس</th>
                        <th class="text-center" style="width: 135px; min-width: 135px; white-space: nowrap;">النوع</th>
                        <?php if (!empty($uniqueSubjects)): ?>
                        <th style="width: 105px; min-width: 105px; white-space: nowrap;">المادة</th>
                        <?php endif; ?>
                        <th class="text-center" style="width: 85px; min-width: 85px; white-space: nowrap;">اللغة</th>
                        <th class="text-center" style="width: 85px; min-width: 85px; white-space: nowrap;">المدة</th>
                        <th class="text-center" style="width: 120px; min-width: 120px; white-space: nowrap;">الحالة</th>
                        <th class="text-center" style="width: 120px; min-width: 120px; white-space: nowrap;">تاريخ الإنشاء</th>
                        <th class="text-center actions-column" style="width: 250px; min-width: 250px; white-space: nowrap;">الإجراءات</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($lessons as $index => $lesson): 
                        $typeInfo = getLessonTypeDetails($lesson['title']);
                    ?>
                    <tr data-subject="<?php echo htmlspecialchars($lesson['subject'] ?? ''); ?>"
                        data-grade="<?php echo htmlspecialchars($lesson['grade_level'] ?? ''); ?>"
                        data-type="<?php echo $typeInfo['key']; ?>"
                        data-language="<?php echo htmlspecialchars($lesson['language'] ?? ''); ?>"
                        data-status="<?php echo htmlspecialchars($lesson['status'] ?? ''); ?>"
                        data-date="<?php echo date('Y-m-d', strtotime($lesson['created_at'])); ?>">
                        <td class="text-center"><?php echo $index + 1; ?></td>
                        <td>
                            <div class="lesson-title-cell">
                                <span class="lesson-title-text"><?php echo htmlspecialchars($typeInfo['clean_title']); ?></span>
                                <?php if (!empty($lesson['grade_level'])): ?>
                                <span class="badge bg-info-subtle text-info-emphasis border border-info-subtle ms-2" style="font-size: 0.75rem;">
                                    <i class="fas fa-graduation-cap me-1"></i><?php echo htmlspecialchars($lesson['grade_level']); ?>
                                </span>
                                <?php endif; ?>
                            </div>
                        </td>
                        <td class="text-center">
                            <span class="archive-pill <?php echo $typeInfo['class']; ?>">
                                <i class="fas <?php echo $typeInfo['icon']; ?>"></i>
                                <?php echo $typeInfo['label']; ?>
                            </span>
                        </td>
                        <?php if (!empty($uniqueSubjects)): ?>
                        <td>
                            <?php if (!empty($lesson['subject'])): ?>
                            <span class="archive-pill pill-subject">
                                <i class="fas fa-bookmark text-primary"></i>
                                <?php echo htmlspecialchars($lesson['subject']); ?>
                            </span>
                            <?php else: ?>
                            <span class="text-muted">—</span>
                            <?php endif; ?>
                        </td>
                        <?php endif; ?>
                        <td class="text-center">
                            <?php
                                $langCode = $lesson['language'] ?? 'ar';
                                $langText = $langLabels[$langCode] ?? 'عربي';
                                $langIcon = match($langCode) {
                                    'en' => 'fa-globe-americas',
                                    'fr' => 'fa-globe-europe',
                                    default => 'fa-globe-asia'
                                };
                            ?>
                            <span class="archive-pill pill-lang">
                                <i class="fas <?php echo $langIcon; ?> text-primary"></i>
                                <?php echo $langText; ?>
                            </span>
                        </td>
                        <td class="text-center">
                            <span class="archive-pill pill-duration">
                                <i class="far fa-clock text-secondary"></i>
                                <?php echo $lesson['duration_minutes']; ?> د
                            </span>
                        </td>
                        <td class="text-center">
                            <?php
                                $statusKey = $lesson['status'] ?? 'completed';
                                $statusText = $statusLabels[$statusKey] ?? $statusKey;
                                $statusIcon = match($statusKey) {
                                    'completed' => 'fa-check-circle',
                                    'draft' => 'fa-pencil-alt',
                                    'generating' => 'fa-hourglass-half',
                                    'error' => 'fa-exclamation-circle',
                                    default => 'fa-info-circle'
                                };
                            ?>
                            <span class="archive-pill pill-status-<?php echo htmlspecialchars($statusKey); ?>">
                                <i class="fas <?php echo $statusIcon; ?>"></i>
                                <?php echo $statusText; ?>
                            </span>
                        </td>
                        <td class="text-center">
                            <div class="date-cell">
                                <div class="date-day"><?php echo date('Y/m/d', strtotime($lesson['created_at'])); ?></div>
                                <div><?php echo date('h:i A', strtotime($lesson['created_at'])); ?></div>
                            </div>
                        </td>
                        <td class="text-center actions-column">
                            <div class="actions-cell">
                                <a href="lesson_view.php?id=<?php echo $lesson['id']; ?>" class="btn btn-action-pills btn-view me-1" data-bs-toggle="tooltip" title="عرض الدرس" aria-label="عرض الدرس">
                                    <i class="fas fa-eye"></i>
                                </a>
                                <?php if ($lesson['status'] === 'completed'): ?>
                                <a href="lesson_download.php?id=<?php echo $lesson['id']; ?>&type=exam" class="btn btn-action-pills btn-activate me-1" data-bs-toggle="tooltip" title="تحميل الامتحان" aria-label="تحميل الامتحان">
                                    <i class="fas fa-file-download"></i>
                                </a>
                                <?php endif; ?>
                                <?php if (isset($publishedExams[$lesson['id']])): ?>
                                <a href="exam_results.php?exam_id=<?php echo $publishedExams[$lesson['id']]['id']; ?>" class="btn btn-action-pills btn-edit me-1" data-bs-toggle="tooltip" title="عرض النتائج" aria-label="عرض النتائج">
                                    <i class="fas fa-chart-pie"></i>
                                </a>
                                <?php endif; ?>
                                <?php if ($lesson['status'] === 'completed'): ?>
                                <button type="button" class="btn btn-action-pills btn-services me-1" data-bs-toggle="tooltip" title="نسخ الدرس" aria-label="نسخ الدرس"
                                        onclick="cloneLesson(<?php echo $lesson['id']; ?>, '<?php echo htmlspecialchars(addslashes($lesson['title']), ENT_QUOTES); ?>')">
                                    <i class="fas fa-copy"></i>
                                </button>
                                <button type="button" class="btn btn-action-pills btn-grades me-1" data-bs-toggle="tooltip" title="تصدير بنك الأسئلة (QB)" aria-label="تصدير بنك الأسئلة"
                                        onclick="exportQuestionBank(<?php echo $lesson['id']; ?>)">
                                    <i class="fas fa-database"></i>
                                </button>
                                <?php endif; ?>
                                <button type="button" class="btn btn-action-pills btn-delete" data-bs-toggle="tooltip" title="حذف الدرس" aria-label="حذف الدرس"
                                        onclick="showDeleteModal(<?php echo $lesson['id']; ?>, '<?php echo htmlspecialchars(addslashes($lesson['title']), ENT_QUOTES); ?>')">
                                    <i class="fas fa-trash"></i>
                                </button>
                            </div>
                        </td>
                    </tr>
                    <?php
    endforeach; ?>
                </tbody>
            </table>
            </div>
            <?php endif; ?>
        </div>
    </div>
    <!-- Delete Confirmation Modal -->
    <div class="modal fade" id="deleteModal" tabindex="-1">
        <div class="modal-dialog">
            <div class="modal-content">
                <div class="modal-header bg-danger text-white">
                    <h5 class="modal-title"><i class="fas fa-exclamation-triangle me-2"></i> تأكيد الحذف</h5>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <div class="delete-icon-wrapper">
                        <i class="fas fa-trash-alt"></i>
                    </div>
                    <p class="text-center" id="deleteText"></p>
                    <div class="delete-warning">
                        <i class="fas fa-exclamation-circle me-2"></i>
                        هذا الإجراء لا يمكن التراجع عنه. سيتم حذف الدرس والامتحانات المرتبطة نهائياً.
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">
                        <i class="fas fa-times me-1"></i> إلغاء
                    </button>
                    <form id="deleteForm" method="POST" action="lesson_archive.php" style="display:inline;">
                        <input type="hidden" name="delete_lesson_id" id="deleteLessonId" value="">
                        <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($_SESSION['csrf_token']); ?>">
                        <button type="submit" class="btn btn-danger">
                            <i class="fas fa-trash me-2"></i> حذف
                        </button>
                    </form>
                </div>
            </div>
        </div>
    </div>
    <!-- Scripts -->
    <script src="https://code.jquery.com/jquery-3.7.0.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
    <script src="https://cdn.datatables.net/1.13.6/js/jquery.dataTables.min.js"></script>
    <script src="https://cdn.datatables.net/1.13.6/js/dataTables.bootstrap5.min.js"></script>
    <script>
        let lessonsTable = null;
        // Custom DataTables filter for lesson archive
        $.fn.dataTable.ext.search.push(function(settings, data, dataIndex) {
            if (!lessonsTable || settings.nTable.id !== 'lessonsTable') {
                return true;
            }
            const type = document.getElementById('filterType')?.value || '';
            const subject = document.getElementById('filterSubject')?.value || '';
            const grade = document.getElementById('filterGrade')?.value || '';
            const language = document.getElementById('filterLanguage')?.value || '';
            const status = document.getElementById('filterStatus')?.value || '';
            // If no filters are active, pass all rows
            if (!type && !subject && !grade && !language && !status) {
                return true;
            }
            const row = lessonsTable.row(dataIndex).node();
            if (!row) {
                return true;
            }
            const rowType = row.getAttribute('data-type') || '';
            const rowSubject = row.getAttribute('data-subject') || '';
            const rowGrade = row.getAttribute('data-grade') || '';
            const rowLanguage = row.getAttribute('data-language') || '';
            const rowStatus = row.getAttribute('data-status') || '';
            if (type && rowType !== type) return false;
            if (subject && rowSubject !== subject) return false;
            if (grade && rowGrade !== grade) return false;
            if (language && rowLanguage !== language) return false;
            if (status && rowStatus !== status) return false;
            return true;
        });
        $(document).ready(function() {
            document.querySelectorAll('[data-bs-toggle="tooltip"]').forEach(function(el) {
                new bootstrap.Tooltip(el);
            });
            <?php if (!empty($lessons)): ?>
            lessonsTable = $('#lessonsTable').DataTable({
                language: {
                    "emptyTable": "لا توجد بيانات متاحة في الجدول",
                    "info": "عرض _START_ إلى _END_ من أصل _TOTAL_ سجل",
                    "infoEmpty": "عرض 0 إلى 0 من أصل 0 سجل",
                    "infoFiltered": "(تمت التصفية من _MAX_ سجل)",
                    "lengthMenu": "عرض _MENU_ سجل",
                    "loadingRecords": "جارٍ التحميل...",
                    "processing": "جارٍ المعالجة...",
                    "search": "بحث:",
                    "zeroRecords": "لم يتم العثور على نتائج مطابقة",
                    "paginate": {
                        "first": "الأول",
                        "last": "الأخير",
                        "next": "التالي",
                        "previous": "السابق"
                    },
                    "aria": {
                        "sortAscending": ": ترتيب تصاعدي",
                        "sortDescending": ": ترتيب تنازلي"
                    }
                },
                columnDefs: [
                    { orderable: false, targets: [0, -1] }
                ],
                order: [[<?php echo !empty($uniqueSubjects) ? 7 : 6; ?>, 'desc']],
                pageLength: 50,
                autoWidth: false,
                responsive: false
            });
            lessonsTable.on('order.dt search.dt page.dt', function () {
                lessonsTable.column(0, { search: 'applied', order: 'applied' }).nodes().each(function (cell, i) {
                    cell.innerHTML = i + 1;
                });
            }).draw();
            <?php endif; ?>
        });
        // =============================================
        // Filters
        // =============================================
        function applyFilters() {
            if (lessonsTable) {
                lessonsTable.draw();
            } else {
                const type = document.getElementById('filterType')?.value || '';
                const subject = document.getElementById('filterSubject')?.value || '';
                const grade = document.getElementById('filterGrade')?.value || '';
                const language = document.getElementById('filterLanguage')?.value || '';
                const status = document.getElementById('filterStatus')?.value || '';
                document.querySelectorAll('#lessonsTable tbody tr').forEach(row => {
                    const rowType = row.getAttribute('data-type') || '';
                    const rowSubject = row.getAttribute('data-subject') || '';
                    const rowGrade = row.getAttribute('data-grade') || '';
                    const rowLanguage = row.getAttribute('data-language') || '';
                    const rowStatus = row.getAttribute('data-status') || '';
                    let show = true;
                    if (type && rowType !== type) show = false;
                    if (subject && rowSubject !== subject) show = false;
                    if (grade && rowGrade !== grade) show = false;
                    if (language && rowLanguage !== language) show = false;
                    if (status && rowStatus !== status) show = false;
                    row.style.display = show ? '' : 'none';
                });
            }
        }
        function resetFilters() {
            const ids = ['filterType', 'filterSubject', 'filterGrade', 'filterLanguage', 'filterStatus'];
            ids.forEach(id => {
                const el = document.getElementById(id);
                if (el) el.value = '';
            });
            if (lessonsTable) {
                lessonsTable.draw();
            } else {
                document.querySelectorAll('#lessonsTable tbody tr').forEach(row => {
                    row.style.display = '';
                });
            }
        }
        // =============================================
        // Delete Modal
        // =============================================
        function showDeleteModal(id, title) {
            document.getElementById('deleteText').innerHTML =
                'هل أنت متأكد من حذف الدرس: <strong>' + title + '</strong>؟';
            document.getElementById('deleteLessonId').value = id;
            new bootstrap.Modal(document.getElementById('deleteModal')).show();
        }
        // =============================================
        // Clone Lesson
        // =============================================
        function cloneLesson(lessonId, title) {
            if (!confirm('هل تريد نسخ درس: ' + title + '؟')) return;
            fetch('ajax/clone_lesson.php', {
                method: 'POST',
                headers: {'Content-Type': 'application/x-www-form-urlencoded'},
                body: 'lesson_id=' + lessonId + '&csrf_token=' + encodeURIComponent('<?php echo $_SESSION['csrf_token']; ?>')
            })
            .then(r => r.json())
            .then(data => {
                if (data.success) {
                    alert('تم نسخ الدرس بنجاح!');
                    location.reload();
                } else {
                    alert('خطأ: ' + data.message);
                }
            })
            .catch(err => alert('خطأ في الاتصال: ' + err.message));
        }
        // =============================================
        // Export Question Bank
        // =============================================
        function exportQuestionBank(lessonId) {
            fetch('ajax/export_qbank.php', {
                method: 'POST',
                headers: {'Content-Type': 'application/x-www-form-urlencoded'},
                body: 'action=export_html&lesson_id=' + lessonId + '&csrf_token=' + encodeURIComponent('<?php echo $_SESSION['csrf_token']; ?>')
            })
            .then(r => r.json())
            .then(data => {
                if (data.success) {
                    var win = window.open('', '_blank');
                    win.document.write(data.html);
                    win.document.close();
                } else {
                    alert('خطأ: ' + data.message);
                }
            })
            .catch(err => alert('خطأ في الاتصال: ' + err.message));
        }
        // Enforce Light Mode
        document.body.classList.remove('dark-mode');
    </script>
    <script src="../assets/js/premium-dashboard.js"></script>
    <script src="script.js?v=1.2"></script>
</body>
</html>
