<?php
ini_set('display_errors', 0);
ini_set('display_startup_errors', 0);
error_reporting(0);

// Set page title
$page_title = "سجل التقييمات";

// Include database and necessary classes
require_once '../config/database.php';
require_once '../classes/user.php';
require_once '../classes/classroom.php';
require_once '../classes/evaluation.php';
require_once '../classes/evaluation_type.php';
require_once '../classes/utilities.php';
require_once '../includes/template_helper.php';

// Initialize database connection
$database = new Database();
$db = $database->getConnection();

// تحميل إعدادات الجلسة الموحدة
require_once '../includes/session_config.php';

// Validate session for teacher role
Utilities::validateSession('teacher');

// التحقق من أن التقييمات مسموحة
$evaluation_check = Utilities::areEvaluationsAllowed($db);

// Initialize objects
$user = new User($db);
$classroom = new ClassRoom($db);
$evaluation = new Evaluation($db);
$evaluation_type = new EvaluationType($db);

$user->id = $_SESSION['user_id'];

// Get teacher's assigned classes
$assigned_classes = $user->getAssignedClasses();

// Get all students from teacher's assigned classes
$students_query = "SELECT DISTINCT u.id, u.name 
                   FROM users u
                   JOIN user_class_access uca ON u.class_id = uca.class_id
                   WHERE uca.user_id = :teacher_id
                   AND u.role = 'student'
                   AND u.status = 'active'
                   AND NOT EXISTS (SELECT 1 FROM student_profiles sp WHERE sp.user_id=u.id AND sp.enrollment_status <> 'enrolled')
                   ORDER BY u.name";
$students_stmt = $db->prepare($students_query);
$students_stmt->bindValue(':teacher_id', $_SESSION['user_id']);
$students_stmt->execute();
$teacher_students = $students_stmt->fetchAll(PDO::FETCH_ASSOC);

// Process filter form if submitted
$filter_class = isset($_GET['class_id']) && !empty($_GET['class_id']) ? $_GET['class_id'] : null;
$filter_student = isset($_GET['student_id']) && !empty($_GET['student_id']) ? $_GET['student_id'] : null;
$filter_date_from = isset($_GET['date_from']) && !empty($_GET['date_from']) ? $_GET['date_from'] : null;
$filter_date_to = isset($_GET['date_to']) && !empty($_GET['date_to']) ? $_GET['date_to'] : null;
$filter_type = isset($_GET['type']) && !empty($_GET['type']) ? $_GET['type'] : null;

// Build query for teacher's evaluations with custom points handling
$query = "SELECT e.id, e.date_created, 
          s.name as student_name, 
          c.name as class_name,
          et.name as evaluation_name, 
          et.type, 
          et.points,
          e.custom_points,
          e.reason,
          CASE 
              WHEN e.custom_points IS NOT NULL THEN 
                  ABS(e.custom_points)
              ELSE 
                  et.points
          END as display_points,
          CASE 
              WHEN e.custom_points IS NOT NULL THEN 
                  CASE WHEN e.custom_points >= 0 THEN 'positive' ELSE 'negative' END
              ELSE 
                  et.type
          END as display_type
          FROM evaluations e
          JOIN users s ON e.student_id = s.id
          JOIN classes c ON e.class_id = c.id
          JOIN evaluation_types et ON e.evaluation_type_id = et.id
          WHERE e.teacher_id = :teacher_id";

$params = [':teacher_id' => $_SESSION['user_id']];

// Apply filters
if ($filter_class) {
    $query .= " AND e.class_id = :class_id";
    $params[':class_id'] = $filter_class;
}

if ($filter_student) {
    $query .= " AND e.student_id = :student_id";
    $params[':student_id'] = $filter_student;
}

if ($filter_date_from) {
    $query .= " AND e.date_created >= :date_from";
    $params[':date_from'] = $filter_date_from . ' 00:00:00';
}

if ($filter_date_to) {
    $query .= " AND e.date_created <= :date_to";
    $params[':date_to'] = $filter_date_to . ' 23:59:59';
}

if ($filter_type) {
    if ($filter_type == 'positive' || $filter_type == 'negative') {
        $query .= " AND CASE 
                        WHEN e.custom_points IS NOT NULL THEN 
                            CASE WHEN e.custom_points >= 0 THEN 'positive' ELSE 'negative' END
                        ELSE 
                            et.type
                    END = :type";
        $params[':type'] = $filter_type;
    }
}

$query .= " ORDER BY e.date_created DESC";

$stmt = $db->prepare($query);
foreach ($params as $key => $value) {
    $stmt->bindValue($key, $value);
}
$stmt->execute();
$evaluations = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Get statistics for dashboard
$stats_query = "SELECT 
                COUNT(*) as total_evaluations,
                SUM(CASE 
                    WHEN e.custom_points IS NOT NULL THEN 
                        CASE WHEN e.custom_points > 0 THEN 1 ELSE 0 END
                    ELSE 
                        CASE WHEN et.type = 'positive' THEN 1 ELSE 0 END
                END) as positive_count,
                SUM(CASE 
                    WHEN e.custom_points IS NOT NULL THEN 
                        CASE WHEN e.custom_points < 0 THEN 1 ELSE 0 END
                    ELSE 
                        CASE WHEN et.type = 'negative' THEN 1 ELSE 0 END
                END) as negative_count
                FROM evaluations e
                JOIN evaluation_types et ON e.evaluation_type_id = et.id
                WHERE e.teacher_id = :teacher_id";

$stats_stmt = $db->prepare($stats_query);
$stats_stmt->bindValue(':teacher_id', $_SESSION['user_id']);
$stats_stmt->execute();
$stats = $stats_stmt->fetch(PDO::FETCH_ASSOC);

// Get count of assigned classes for this teacher
$classes_query = "SELECT COUNT(DISTINCT class_id) as total_classes 
                  FROM user_class_access 
                  WHERE user_id = :teacher_id";
$classes_stmt = $db->prepare($classes_query);
$classes_stmt->bindValue(':teacher_id', $_SESSION['user_id']);
$classes_stmt->execute();
$classes_stats = $classes_stmt->fetch(PDO::FETCH_ASSOC);

// Get count of assigned students for this teacher
$students_query = "SELECT COUNT(DISTINCT s.id) as total_students
                   FROM users s
                   JOIN user_class_access uca ON s.class_id = uca.class_id
                   WHERE uca.user_id = :teacher_id AND s.role = 'student' AND s.status='active'
                   AND NOT EXISTS (SELECT 1 FROM student_profiles sp WHERE sp.user_id=s.id AND sp.enrollment_status <> 'enrolled')";
$students_stmt = $db->prepare($students_query);
$students_stmt->bindValue(':teacher_id', $_SESSION['user_id']);
$students_stmt->execute();
$students_stats = $students_stmt->fetch(PDO::FETCH_ASSOC);
?>
<!DOCTYPE html>
<html lang="ar" dir="rtl">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <script src="../assets/js/datatable-state.js?v=3"></script>
    <title><?php echo $page_title; ?> - نظام الإدارة المدرسية</title>
    <!-- Bootstrap RTL CSS -->
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.rtl.min.css">
    <!-- Font Awesome -->
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css">
    <!-- DataTables CSS -->
    <link rel="stylesheet" href="https://cdn.datatables.net/1.13.7/css/dataTables.bootstrap5.min.css">
    <!-- Custom CSS -->
    <link rel="stylesheet" href="<?php echo asset_url('../assets/css/style.css'); ?>">
    <!-- Air Datepicker CSS (حامل التاريخ الموحد) -->
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/air-datepicker@3.5.0/air-datepicker.css">
    <link rel="stylesheet" href="<?php echo asset_url('../assets/css/premium-dashboard.css'); ?>">
    
    <style>
        /* جعل عرض الجدول ديناميكي حسب المحتوى */
        #evaluationsTable {
            width: 100% !important;
            table-layout: auto !important;
        }
        
        /* عرض مرن للأعمدة */
        #evaluationsTable th,
        #evaluationsTable td {
            width: auto !important;
            white-space: nowrap;
        }
        
        /* تحسينات بطاقات الإحصائيات */
        .statistics-cards .card {
            border: none;
            border-radius: 10px;
            box-shadow: 0 4px 6px rgba(0, 0, 0, 0.1);
            transition: transform 0.2s;
        }
        
        .statistics-cards .card:hover {
            transform: translateY(-2px);
        }
        
        .statistics-cards .card-text {
            font-size: 0.9rem;
            margin-bottom: 0.5rem;
        }
        
        .statistics-cards .card-title {
            font-size: 2rem;
            font-weight: bold;
            margin-bottom: 0;
        }
        
        /* تحسينات للشاشات الصغيرة */
        @media (max-width: 768px) {
            .statistics-cards .card-title {
                font-size: 1.5rem;
            }
            
            .statistics-cards .card-text {
                font-size: 0.8rem;
            }
        }
        
        /* تحسينات جدول التقييمات للشاشات الصغيرة */
        @media (max-width: 768px) {
            .container {
                padding-left: 10px;
                padding-right: 10px;
            }
            
            /* تحسين عرض DataTables Responsive */
            table.dataTable.dtr-inline.collapsed > tbody > tr > td.dtr-control:before,
            table.dataTable.dtr-inline.collapsed > tbody > tr > th.dtr-control:before {
                background-color: #0d6efd;
                border-radius: 3px;
            }
            
            /* تحسين عرض الصف الفرعي للتفاصيل */
            table.dataTable.dtr-inline.collapsed > tbody > tr.parent > td:first-child:before,
            table.dataTable.dtr-inline.collapsed > tbody > tr.parent > th:first-child:before {
                background-color: #dc3545;
            }
            
            /* تحسين عرض التفاصيل المخفية */
            table.dataTable > tbody > tr.child ul.dtr-details {
                width: 100%;
                padding: 10px;
            }
            
            table.dataTable > tbody > tr.child ul.dtr-details > li {
                padding: 8px 0;
                border-bottom: 1px solid #dee2e6;
            }
            
            table.dataTable > tbody > tr.child span.dtr-title {
                font-weight: bold;
                color: #495057;
                min-width: 100px;
                display: inline-block;
            }
            
            table.dataTable > tbody > tr.child span.dtr-data {
                color: #212529;
            }
            
            /* إخفاء أعمدة معينة على الشاشات الصغيرة - سيتم التحكم بها عبر Responsive */
            /* .table-responsive table th:nth-child(1),
            .table-responsive table td:nth-child(1) {
                display: none;
            }
            
            .table-responsive table th:nth-child(3),
            .table-responsive table td:nth-child(3) {
                display: none;
            } */
            
            /* تحسين عرض الأعمدة المتبقية */
            .table-responsive {
                font-size: 0.85rem;
            }
            
            .table th,
            .table td {
                padding: 8px 4px;
                vertical-align: middle;
            }
            
            /* تحسين عرض اسم الطالب */
            .table td:nth-child(2) {
                font-weight: 600;
                max-width: 100px;
                word-wrap: break-word;
            }
            
            /* تحسين عرض التقييم */
            .table td:nth-child(4) {
                font-size: 0.8rem;
            }
            
            /* تحسين عرض النقاط */
            .badge {
                font-size: 0.75rem !important;
                padding: 4px 8px !important;
            }
            
            /* تحسين عرض التاريخ */
            .table td:nth-child(7) {
                font-size: 0.75rem;
                white-space: nowrap;
            }
            
            /* تحسين عرض عناصر التحكم في الجدول */
            .dataTables_wrapper .dataTables_length,
            .dataTables_wrapper .dataTables_filter {
                margin-bottom: 10px;
            }
            
            .dataTables_wrapper .dataTables_length select {
                padding: 4px;
                font-size: 0.9rem;
            }
            
            .dataTables_wrapper .dataTables_filter input {
                padding: 4px 8px;
                font-size: 0.9rem;
            }
            
            .dataTables_wrapper .dataTables_paginate {
                margin-top: 10px;
            }
            
            .dataTables_wrapper .dataTables_paginate .paginate_button {
                padding: 4px 8px;
                font-size: 0.85rem;
            }
        }
        
        /* تحسينات للشاشات الصغيرة جداً */
        @media (max-width: 576px) {
            /* تحسين نموذج الفلتر */
            #filterForm .col-md-2,
            #filterForm .col-md-3 {
                padding-left: 8px !important;
                padding-right: 8px !important;
            }
            
            #filterForm .form-label {
                font-size: 0.9rem;
                margin-bottom: 4px;
            }
            
            #filterForm .form-select,
            #filterForm .form-control {
                font-size: 0.85rem;
                padding: 6px 10px;
            }
            
            #filterForm .btn {
                font-size: 0.85rem;
                padding: 6px 12px;
                width: 100%;
                margin-bottom: 5px;
            }
            
            #filterForm .btn-primary,
            #filterForm .btn-secondary {
                margin: 0;
            }
            
            /* تحسين عرض البطاقة */
            .card-body {
                padding: 10px;
            }
            
            /* جعل عرض الأعمدة ديناميكي حسب المحتوى */
            #evaluationsTable {
                table-layout: auto !important;
                width: auto !important;
                font-size: 0.75rem;
            }
            
            /* تحسين عرض عناصر الجدول */
            .table th,
            .table td {
                padding: 6px 8px;
                font-size: 0.75rem;
                white-space: nowrap;
                width: auto !important;
            }
            
            /* عرض تلقائي للأعمدة حسب المحتوى */
            .table th:nth-child(1), /* الطالب */
            .table td:nth-child(1) {
                min-width: 80px;
                max-width: 150px;
            }
            
            .table th:nth-child(2), /* الفصل */
            .table td:nth-child(2) {
                min-width: 60px;
            }
            
            .table th:nth-child(3), /* التقييم */
            .table td:nth-child(3) {
                min-width: 100px;
            }
            
            .table th:nth-child(4), /* النوع */
            .table td:nth-child(4) {
                min-width: 50px;
                text-align: center;
            }
            
            .table th:nth-child(5), /* النقاط */
            .table td:nth-child(5) {
                min-width: 45px;
                text-align: center;
            }
            
            .table th:nth-child(6), /* التاريخ */
            .table td:nth-child(6) {
                min-width: 95px;
            }
            
            .badge {
                font-size: 0.65rem !important;
                padding: 2px 5px !important;
                white-space: nowrap;
            }
            
            /* تحسين عرض DataTables */
            .dataTables_wrapper .dataTables_length select,
            .dataTables_wrapper .dataTables_filter input {
                font-size: 0.85rem;
            }
            
            /* تفعيل التمرير الأفقي */
            .table-responsive {
                overflow-x: auto;
                -webkit-overflow-scrolling: touch;
            }
        }
        
        /* تحسين عرض اسم النظام على الشاشات المختلفة */
        
        /* زر العودة للبوابة الموحد */
        .portal-back-btn {
            display: inline-flex;
            align-items: center;
            padding: 10px 22px;
            border-radius: 10px;
            background: linear-gradient(135deg, #2563eb, #1d4ed8);
            color: #fff;
            text-decoration: none;
            border: none;
            font-weight: 600;
            font-size: 0.9rem;
            box-shadow: 0 3px 12px rgba(37,99,235,0.35);
            transition: all 0.3s ease;
        }
        .portal-back-btn:hover {
            background: linear-gradient(135deg, #3b82f6, #2563eb);
            color: #fff;
            transform: translateY(-2px);
            box-shadow: 0 5px 18px rgba(37,99,235,0.45);
        }

        /* إزالة المسافة الفارغة بعد حذف الشريط العلوي */
        body.teacher-page {
            padding-top: 0 !important;
        }
    </style>
</head>
<body class="teacher-page">
    <!-- Particles Background -->
    <div id="particles-js"></div>
    
    <!-- Main Content -->
    <div class="container" style="margin-top: 0; padding-top: 1.5rem;">
        
        <!-- زر العودة للبوابة -->
        <div class="mb-3 text-end">
            <a href="portal.php" class="portal-back-btn">
                <i class="fas fa-arrow-right me-2"></i>العودة للبوابة
            </a>
        </div>
        
        <!-- عنوان الصفحة -->
        <div class="text-center mb-4">
            <h2 class="mb-2">
                <i class="fas fa-history me-2" style="color: #0d6efd;"></i>
                سجل التقييمات
            </h2>
            <p class="text-muted">عرض وإدارة جميع التقييمات المسجلة</p>
        </div>
        
        <!-- Navigation Links moved inside page -->
        <div class="row mb-4">
            <div class="col-12">
                <div class="card shadow-sm">
                    <div class="card-body p-2">
                        <div class="d-flex justify-content-center gap-2 nav-buttons">
                            <a class="btn btn-primary" href="index.php">
                                <i class="fas fa-home me-1"></i> الرئيسية
                            </a>
                            <a class="btn btn-primary" href="evaluations.php">
                                <i class="fas fa-history me-1"></i> سجل التقييمات
                            </a>
                        </div>
                    </div>
                </div>
            </div>
        </div>
        
        <?php echo Utilities::showFlashMessage(); ?>
        
        <?php if (!$evaluation_check['allowed']): ?>
            <div class="alert alert-warning alert-dismissible fade show sticky-alert" role="alert" style="position: sticky; top: var(--navbar-height); z-index: 1019;">
                <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
                <h5 class="alert-heading"><i class="fas fa-exclamation-triangle me-2"></i>التقييمات غير متاحة حالياً</h5>
                <p class="mb-2"><strong><?php echo htmlspecialchars($evaluation_check['message']); ?></strong></p>
                <?php if ($evaluation_check['reason'] == 'day_not_allowed' && isset($evaluation_check['allowed_days'])): ?>
                    <hr>
                    <p class="mb-0"><i class="fas fa-calendar-check me-2"></i>الأيام المسموح بها: <strong><?php echo htmlspecialchars($evaluation_check['allowed_days']); ?></strong></p>
                <?php elseif ($evaluation_check['reason'] == 'time_not_allowed'): ?>
                    <hr>
                    <p class="mb-0"><i class="fas fa-clock me-2"></i>الأوقات المسموح بها: من <strong><?php echo htmlspecialchars($evaluation_check['allowed_time_from']); ?></strong> إلى <strong><?php echo htmlspecialchars($evaluation_check['allowed_time_to']); ?></strong></p>
                <?php endif; ?>
            </div>
        <?php endif; ?>
        
        <!-- Page Title -->
        <div class="d-flex justify-content-between flex-wrap flex-md-nowrap align-items-center pt-3 pb-2 mb-3 border-bottom">
            <h1 class="h2"><i class="fas fa-history me-2"></i>سجل التقييمات</h1>
            <div class="btn-toolbar mb-2 mb-md-0">
                <?php if ($evaluation_check['allowed']): ?>
                    <a href="index.php" class="btn btn-sm btn-primary">
                        <i class="fas fa-plus me-1"></i>إضافة تقييم جديد
                    </a>
                <?php else: ?>
                    <button class="btn btn-sm btn-secondary" disabled>
                        <i class="fas fa-ban me-1"></i>التقييمات متوقفة
                    </button>
                <?php endif; ?>
            </div>
        </div>        <!-- Statistics Cards -->
        <div class="row mb-4 statistics-cards">
            <div class="col-md-4 col-sm-6 mb-3">
                <div class="card text-white bg-info">
                    <div class="card-body">
                        <div class="row">
                            <div class="col">
                                <p class="card-text">الفصول</p>
                                <h3 class="card-title"><?php echo $classes_stats['total_classes']; ?></h3>
                            </div>
                            <div class="col-auto">
                                <i class="fas fa-school fa-2x"></i>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
            <div class="col-md-4 col-sm-6 mb-3">
                <div class="card text-white bg-warning">
                    <div class="card-body">
                        <div class="row">
                            <div class="col">
                                <p class="card-text">الطلاب</p>
                                <h3 class="card-title"><?php echo $students_stats['total_students']; ?></h3>
                            </div>
                            <div class="col-auto">
                                <i class="fas fa-users fa-2x"></i>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
            <div class="col-md-4 col-sm-6 mb-3">
                <div class="card text-white bg-primary">
                    <div class="card-body">
                        <div class="row">
                            <div class="col">
                                <p class="card-text">إجمالي التقييمات</p>
                                <h3 class="card-title"><?php echo $stats['total_evaluations']; ?></h3>
                            </div>
                            <div class="col-auto">
                                <i class="fas fa-clipboard-list fa-2x"></i>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
        
        <!-- Evaluation Stats Row -->
        <div class="row mb-4 statistics-cards">
            <div class="col-md-6 col-sm-6 mb-3">
                <div class="card text-white bg-success">
                    <div class="card-body">
                        <div class="row">
                            <div class="col">
                                <p class="card-text">التقييمات الإيجابية</p>
                                <h3 class="card-title"><?php echo $stats['positive_count']; ?></h3>
                            </div>
                            <div class="col-auto">
                                <i class="fas fa-thumbs-up fa-2x"></i>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
            <div class="col-md-6 col-sm-6 mb-3">
                <div class="card text-white bg-danger">
                    <div class="card-body">
                        <div class="row">
                            <div class="col">
                                <p class="card-text">التقييمات السلبية</p>
                                <h3 class="card-title"><?php echo $stats['negative_count']; ?></h3>
                            </div>
                            <div class="col-auto">
                                <i class="fas fa-thumbs-down fa-2x"></i>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <!-- Filter Form -->
        <div class="card mb-4">
            <div class="card-header bg-light">
                <h5 class="mb-0"><i class="fas fa-filter me-2"></i>تصفية التقييمات</h5>
            </div>
            <div class="card-body">
                <form method="GET" action="evaluations.php" class="row g-3" id="filterForm">
                    <!-- Class Filter -->
                    <div class="col-md-2">
                        <label for="class_id" class="form-label">الفصل</label>
                        <select class="form-select" id="class_id" name="class_id">
                            <option value="">-- جميع الفصول --</option>
                            <?php foreach ($assigned_classes as $class): ?>
                                <option value="<?php echo $class['id']; ?>" <?php echo ($filter_class == $class['id']) ? 'selected' : ''; ?>>
                                    <?php echo $class['name']; ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    
                    <!-- Student Filter -->
                    <div class="col-md-3">
                        <label for="student_id" class="form-label">الطالب</label>
                        <select class="form-select" id="student_id" name="student_id">
                            <option value="">-- جميع الطلاب --</option>
                            <?php foreach ($teacher_students as $student): ?>
                                <option value="<?php echo $student['id']; ?>" <?php echo ($filter_student == $student['id']) ? 'selected' : ''; ?>>
                                    <?php echo $student['name']; ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    
                    <!-- Type Filter -->
                    <div class="col-md-2">
                        <label for="type" class="form-label">نوع التقييم</label>
                        <select class="form-select" id="type" name="type">
                            <option value="">-- جميع الأنواع --</option>
                            <option value="positive" <?php echo ($filter_type == 'positive') ? 'selected' : ''; ?>>إيجابي</option>
                            <option value="negative" <?php echo ($filter_type == 'negative') ? 'selected' : ''; ?>>سلبي</option>
                        </select>
                    </div>
                    
                    <!-- Date From Filter -->
                    <div class="col-md-2">
                        <label for="date_from" class="form-label">من تاريخ</label>
                        <input type="text" class="form-control flatpickr-date" id="date_from" name="date_from" placeholder="اختر التاريخ..." value="<?php echo $filter_date_from; ?>">
                    </div>
                    
                    <!-- Date To Filter -->
                    <div class="col-md-2">
                        <label for="date_to" class="form-label">إلى تاريخ</label>
                        <input type="text" class="form-control flatpickr-date" id="date_to" name="date_to" placeholder="اختر التاريخ..." value="<?php echo $filter_date_to; ?>">
                    </div>
                    
                    <!-- Submit Button -->
                    <div class="col-md-2 d-flex align-items-end">
                        <button type="submit" class="btn btn-primary me-2">
                            <i class="fas fa-search me-1"></i>بحث
                        </button>
                        <a href="evaluations.php" class="btn btn-secondary">
                            <i class="fas fa-redo me-1"></i>إعادة ضبط
                        </a>
                    </div>
                </div>
                </form>
            </div>
        </div>

        <!-- Evaluations Table -->
        <div class="card">
            <div class="card-header bg-light">
                <h5 class="mb-0"><i class="fas fa-list me-2"></i>قائمة التقييمات</h5>
            </div>
            
            <div class="card-body">
                <div class="table-responsive">
                    <table class="table table-hover align-middle" id="evaluationsTable">
                        <thead>
                            <tr>
                                <th>الطالب</th>
                                <th>الفصل</th>
                                <th>التقييم</th>
                                <th>النوع</th>
                                <th>النقاط</th>
                                <th>التاريخ</th>
                                <th>الإجراءات</th>
                            </tr>
                        </thead>
                        <tbody>
                            <!-- DataTables will populate this via AJAX -->
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </div>

    <!-- Message Modal -->
    <div class="modal fade" id="messageModal" tabindex="-1" aria-labelledby="messageModalLabel" aria-hidden="true">
        <div class="modal-dialog modal-dialog-centered">
            <div class="modal-content">
                <div class="modal-header" id="messageModalHeader">
                    <h5 class="modal-title" id="messageModalLabel">
                        <i class="fas fa-check-circle me-2" id="messageModalIcon"></i>
                        <span id="messageModalTitle">رسالة</span>
                    </h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body" id="messageModalBody" style="white-space: pre-line; text-align: right; line-height: 1.8;">
                    <!-- Message content will be inserted here -->
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">
                        <i class="fas fa-times me-1"></i>إغلاق
                    </button>
                </div>
            </div>
        </div>
    </div>

    <!-- Confirm Delete Modal -->
    <div class="modal fade" id="confirmModal" tabindex="-1" aria-labelledby="confirmModalLabel" aria-hidden="true">
        <div class="modal-dialog modal-dialog-centered">
            <div class="modal-content">
                <div class="modal-header modal-header-danger">
                    <h5 class="modal-title" id="confirmModalLabel">
                        <i class="fas fa-exclamation-triangle me-2"></i>تأكيد الحذف
                    </h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body" style="text-align: right; line-height: 1.8;">
                    <p id="confirmQuestion" class="mb-3 fw-bold"></p>
                    <div id="confirmDetails" style="white-space: pre-line;" class="text-muted"></div>
                    <p id="confirmWarning" class="mt-3 text-danger fw-bold"></p>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">
                        <i class="fas fa-times me-1"></i>إلغاء
                    </button>
                    <button type="button" class="btn btn-danger" id="confirmDeleteBtn">
                        <i class="fas fa-trash me-1"></i>تأكيد الحذف
                    </button>
                </div>
            </div>
        </div>
    </div>

    <!-- Footer -->
    <footer class="bg-light py-4 mt-5">
        <div class="container text-center">
            <p class="mb-0"><?php echo date('Y'); ?> جميع الحقوق محفوظة ©<br>
        EduCore <br>
        Computer Department</p>
        </div>
    </footer>

    <!-- jQuery -->
    <script src="https://code.jquery.com/jquery-3.7.1.min.js"></script>
    <!-- Bootstrap JS -->
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
    <!-- DataTables JS -->
    <script src="https://cdn.datatables.net/1.13.7/js/jquery.dataTables.min.js"></script>
    <script src="https://cdn.datatables.net/1.13.7/js/dataTables.bootstrap5.min.js"></script>
    
    <!-- Particles.js -->
    <script src="https://cdn.jsdelivr.net/npm/particles.js@2.0.0/particles.min.js"></script>
    <!-- Theme Toggle Script -->
    <?php require_once __DIR__ . '/../includes/template_helper.php'; ?>
    <script src="<?php echo asset_url('../assets/js/particles_theme.js'); ?>"></script>
    <!-- Air Datepicker (حامل التاريخ الموحد) -->
    <script src="https://cdn.jsdelivr.net/npm/air-datepicker@3.5.0/air-datepicker.js"></script>
    <script src="<?php echo asset_url('../assets/js/air-datepicker-init.js'); ?>"></script>
    
    <script>
    $(document).ready(function() {
        // Initialize DataTable with server-side processing
        const table = $('#evaluationsTable').DataTable({
            processing: true,
            serverSide: true,
            autoWidth: false, // تعطيل العرض التلقائي لـ DataTables
            ajax: {
                url: '../includes/ajax_handlers.php',
                type: 'GET',
                data: function (d) {
                    d.action = 'teacher_evaluations_datatable';
                    d.class_id = $('#class_id').val();
                    d.student_id = $('#student_id').val();
                    d.type = $('#type').val();
                    d.date_from = $('#date_from').val();
                    d.date_to = $('#date_to').val();
                }
            },
            language: {
                "search": "البحث:",
                "lengthMenu": "عرض _MENU_ مدخلات",
                "info": "عرض _START_ إلى _END_ من أصل _TOTAL_ مدخل",
                "infoEmpty": "عرض 0 إلى 0 من أصل 0 مدخل",
                "infoFiltered": "(منقح من _MAX_ مدخل إجمالي)",
                "loadingRecords": "جاري التحميل...",
                "zeroRecords": "لم يتم العثور على أي سجلات مطابقة",
                "emptyTable": "لا توجد بيانات متاحة في الجدول",
                "paginate": {
                    "first": "الأول",
                    "last": "الأخير",
                    "next": "التالي",
                    "previous": "السابق"
                }
            },
            order: [[5, 'desc']], // عمود التاريخ
            pageLength: 50,
            lengthMenu: [[10, 25, 50, 100, 200, 500, -1], [10, 25, 50, 100, 200, 500, 'الكل']],
            columnDefs: [
                { targets: '_all', className: 'text-right' },
                { targets: 6, orderable: false } // عمود الإجراءات غير قابل للفرز
            ]
        });

        // Reload DataTable when form values change
        $('#filterForm').on('submit', function(e) {
            e.preventDefault();
            table.ajax.reload();
        });

        // Handle delete evaluation - show confirmation modal
        $(document).on('click', '.delete-evaluation-btn', function() {
            const evalId = $(this).data('id');
            const evalInfo = $(this).data('info');
            
            // Show confirmation modal
            showConfirmModal(
                'تأكيد الحذف',
                'هل أنت متأكد من حذف هذا التقييم؟',
                evalInfo + '\n\nلا يمكن التراجع عن هذا الإجراء!',
                function() {
                    // User confirmed - proceed with delete
                    $.ajax({
                        url: '../includes/ajax_handlers.php',
                        type: 'POST',
                        data: {
                            action: 'delete_teacher_evaluation',
                            evaluation_id: evalId
                        },
                        dataType: 'json',
                        success: function(response) {
                            if (response.success) {
                                // Show success modal
                                showMessageModal('success', 'تم الحذف بنجاح', response.message);
                                // Reload table
                                table.ajax.reload(null, false);
                            } else {
                            // Show error modal
                            showMessageModal('error', 'خطأ', response.message);
                        }
                    },
                    error: function(xhr, status, error) {
                        showMessageModal('error', 'خطأ', 'حدث خطأ أثناء حذف التقييم: ' + error);
                    }
                });
            });
        });
        
        // Function to show message modal
        function showMessageModal(type, title, message) {
            const modal = document.getElementById('messageModal');
            const modalHeader = document.getElementById('messageModalHeader');
            const modalTitle = document.getElementById('messageModalTitle');
            const modalIcon = document.getElementById('messageModalIcon');
            const modalBody = document.getElementById('messageModalBody');
            
            modalTitle.textContent = title;
            modalBody.textContent = message;
            
            if (type === 'success') {
                modalHeader.className = 'modal-header modal-header-success';
                modalIcon.className = 'fas fa-check-circle me-2';
            } else if (type === 'error') {
                modalHeader.className = 'modal-header modal-header-danger';
                modalIcon.className = 'fas fa-times-circle me-2';
            }
            
            const bsModal = new bootstrap.Modal(modal);
            bsModal.show();
            
            // Clean up backdrop when modal is hidden
            modal.addEventListener('hidden.bs.modal', function () {
                const backdrops = document.querySelectorAll('.modal-backdrop');
                backdrops.forEach(backdrop => backdrop.remove());
                document.body.classList.remove('modal-open');
                document.body.style.overflow = '';
                document.body.style.paddingRight = '';
            }, { once: true });
        }
        
        // دالة لعرض Modal التأكيد
        function showConfirmModal(title, question, details, onConfirm) {
            const modal = document.getElementById('confirmModal');
            const modalTitle = modal.querySelector('.modal-title');
            const modalQuestion = modal.querySelector('#confirmQuestion');
            const modalDetails = modal.querySelector('#confirmDetails');
            const modalWarning = modal.querySelector('#confirmWarning');
            const confirmBtn = modal.querySelector('#confirmDeleteBtn');
            
            // Set content
            modalTitle.innerHTML = '<i class="fas fa-exclamation-triangle me-2"></i>' + title;
            modalQuestion.textContent = question;
            
            // فصل التفاصيل والتحذير
            const parts = details.split('\n\n');
            const evalDetails = parts[0]; // تفاصيل التقييم
            const warning = parts[1] || ''; // التحذير
            
            // تحويل \n إلى <br> لعرض الأسطر بشكل صحيح
            modalDetails.innerHTML = evalDetails.replace(/\\n/g, '<br>');
            modalWarning.textContent = warning;
            
            // Set confirm button click handler
            confirmBtn.onclick = function() {
                bootstrap.Modal.getInstance(modal).hide();
                if (onConfirm) onConfirm();
            };
            
            // Show modal
            const bsModal = new bootstrap.Modal(modal);
            bsModal.show();
            
            // Clean up backdrop when modal is hidden
            modal.addEventListener('hidden.bs.modal', function () {
                const backdrops = document.querySelectorAll('.modal-backdrop');
                backdrops.forEach(backdrop => backdrop.remove());
                document.body.classList.remove('modal-open');
                document.body.style.overflow = '';
                document.body.style.paddingRight = '';
            }, { once: true });
        }
        
        // Handle class selection change to update students list
        $('#class_id').on('change', function() {
            const classId = $(this).val();
            const studentSelect = $('#student_id');
            
            // Reset student selection
            studentSelect.html('<option value="">-- جميع الطلاب --</option>');
            
            if (classId) {
                // Show loading
                studentSelect.html('<option value="">جاري التحميل...</option>');
                
                // AJAX request to get students for selected class
                $.ajax({
                    url: '../includes/ajax_handlers.php',
                    type: 'POST',
                    data: {
                        action: 'get_students_by_class',
                        class_id: classId
                    },
                    dataType: 'json',
                    success: function(students) {
                        studentSelect.html('<option value="">-- جميع الطلاب --</option>');
                        
                        if (students && students.length > 0) {
                            students.forEach(function(student) {
                                studentSelect.append(
                                    $('<option></option>')
                                        .attr('value', student.id)
                                        .text(student.name)
                                );
                            });
                        } else {
                            studentSelect.append('<option value="">لا يوجد طلاب في هذا الفصل</option>');
                        }
                    },
                    error: function(xhr, status, error) {
                        console.error('خطأ في جلب قائمة الطلاب:', error);
                        studentSelect.html('<option value="">خطأ في التحميل</option>');
                    }
                });
            }
        });
    });
    </script>
</body>
</html>
