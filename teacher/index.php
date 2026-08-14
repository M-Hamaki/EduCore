<?php
// Production settings
ini_set('display_errors', 0);
ini_set('display_startup_errors', 0);
error_reporting(0);

// Set page title
$page_title = "لوحة التحكم";

// Include database and necessary classes
require_once '../config/database.php';
require_once '../classes/user.php';
require_once '../classes/classroom.php';
require_once '../classes/evaluation.php';
require_once '../classes/evaluation_type.php';
require_once '../classes/utilities.php';
require_once '../includes/template_helper.php';
require_once '../includes/session_config.php';
require_once '../includes/csrf.php';

Utilities::validateSession('teacher');
requireCsrfPost();

// Initialize database connection
$database = new Database();
$db = $database->getConnection();

// Initialize user object
$user = new User($db);
$user->id = $_SESSION['user_id'];

// Check if evaluations are allowed
$evaluation_check = Utilities::areEvaluationsAllowed($db);

// Process evaluation submission
if ($_SERVER['REQUEST_METHOD'] === 'POST' && (isset($_POST['add_evaluation']) || isset($_POST['submit_evaluation']) || isset($_POST['form_submitted']))) {
    try {
        // Check if evaluations are allowed
        if (!$evaluation_check['allowed']) {
            throw new Exception($evaluation_check['message']);
        }
        
        // Validate required fields - support multiple students
        if (!isset($_POST['student_ids']) || empty($_POST['student_ids']) || empty($_POST['evaluation_type_id']) || empty($_POST['class_id'])) {
            throw new Exception('يرجى اختيار طالب واحد على الأقل ونوع التقييم والفصل.');
        }
        
        // Ensure student_ids is an array and has values
        $student_ids = is_array($_POST['student_ids']) ? $_POST['student_ids'] : [$_POST['student_ids']];
        $student_ids = array_filter($student_ids); // Remove empty values
        
        // Remove duplicates that may come from mobile/desktop dual forms
        $student_ids = array_unique($student_ids);
        $student_ids = array_values($student_ids); // Reset array keys
        
        if (empty($student_ids)) {
            throw new Exception('يرجى اختيار طالب واحد على الأقل.');
        }
        
        // Initialize evaluation object
        $evaluation = new Evaluation($db);
        
        $success_count = 0;
        $failed_count = 0;
        $duplicate_count = 0;
        $student_names = [];
        $duplicate_names = [];
        $error_details = [];
        
        // Process each selected student
        foreach ($student_ids as $student_id) {
            try {
                // Validate student ID
                $student_id = intval($student_id);
                if ($student_id <= 0) {
                    $failed_count++;
                    $error_details[] = "Invalid student ID: $student_id";
                    continue;
                }
                
                // Set evaluation properties
                $evaluation->student_id = $student_id;
                $evaluation->teacher_id = $_SESSION['user_id'];
                $evaluation->evaluation_type_id = intval($_POST['evaluation_type_id']);
                $evaluation->class_id = intval($_POST['class_id']);
                
                // Add reason if provided (hidden from teacher interface as requested)
                if (!empty($_POST['reason'])) {
                    $evaluation->reason = trim($_POST['reason']);
                } else {
                    $evaluation->reason = null;
                }
                
                // Create evaluation
                if ($evaluation->create()) {
                    $success_count++;
                    
                    // Get student name for success message
                    $student_stmt = $db->prepare("SELECT name FROM users WHERE id = ?");
                    $student_stmt->execute([$student_id]);
                    $student_name = $student_stmt->fetchColumn();
                    if ($student_name) {
                        $student_names[] = $student_name;
                    }
                } else {
                    // Check if this is a duplicate submission error
                    if (isset($evaluation->last_error) && strpos($evaluation->last_error, 'تم إضافة هذا التقييم مسبقاً') !== false) {
                        // This is a duplicate - count separately
                        $duplicate_count++;
                        
                        // Get student name for warning message
                        $student_stmt = $db->prepare("SELECT name FROM users WHERE id = ?");
                        $student_stmt->execute([$student_id]);
                        $student_name = $student_stmt->fetchColumn();
                        
                        if ($student_name) {
                            $duplicate_names[] = $student_name;
                        }
                    } else {
                        // Other error
                        $failed_count++;
                        $error_detail = "Failed to create evaluation for student ID: $student_id";
                        if (isset($evaluation->last_error)) {
                            $error_detail .= " - " . $evaluation->last_error;
                        }
                        $error_details[] = $error_detail;
                    }
                }
            } catch (Exception $e) {
                $failed_count++;
                $error_detail = "Error creating evaluation for student ID $student_id: " . $e->getMessage();
                $error_details[] = $error_detail;
            }
        }
        
        // Prepare success/error messages
        if ($success_count > 0) {
            // Get evaluation type info for message
            $eval_stmt = $db->prepare("SELECT name, points FROM evaluation_types WHERE id = ?");
            $eval_stmt->execute([$_POST['evaluation_type_id']]);
            $eval_data = $eval_stmt->fetch(PDO::FETCH_ASSOC);
            
            if ($success_count == 1) {
                // Store for alert banner
                $_SESSION['success_message'] = "تم إضافة التقييم بنجاح للطالب: " . $student_names[0] . " - " . $eval_data['name'] . " (" . $eval_data['points'] . " نقطة)";
                
                // Store for modal
                $_SESSION['success_modal'] = "✅ تم إضافة التقييم بنجاح\n\n";
                $_SESSION['success_modal'] .= "الطالب: " . $student_names[0] . "\n";
                $_SESSION['success_modal'] .= "التقييم: " . $eval_data['name'] . "\n";
                $_SESSION['success_modal'] .= "النقاط: " . $eval_data['points'] . " نقطة";
            } else {
                // Store for alert banner
                $_SESSION['success_message'] = "تم إضافة التقييم بنجاح لـ $success_count طالب - " . $eval_data['name'] . " (" . $eval_data['points'] . " نقطة)";
                
                // Store for modal
                $_SESSION['success_modal'] = "✅ تم إضافة التقييم بنجاح\n\n";
                $_SESSION['success_modal'] .= "عدد الطلاب: $success_count طالب\n";
                $_SESSION['success_modal'] .= "التقييم: " . $eval_data['name'] . "\n";
                $_SESSION['success_modal'] .= "النقاط: " . $eval_data['points'] . " نقطة\n\n";
                $_SESSION['success_modal'] .= "الطلاب:\n• " . implode("\n• ", $student_names);
            }
        }
        
        // Handle duplicate submissions separately
        if ($duplicate_count > 0) {
            $duplicate_message = "⚠️ تنبيه: تم تجاهل $duplicate_count تقييم مكرر\n\n";
            $duplicate_message .= "السبب: تم إضافة نفس التقييم لهؤلاء الطلاب خلال آخر 20 ثانية:\n";
            $duplicate_message .= "• " . implode("\n• ", $duplicate_names);
            $duplicate_message .= "\n\nملاحظة: لا يمكن إضافة نفس التقييم لنفس الطالب أكثر من مرة خلال 20 ثانية لمنع التكرار الخاطئ.";
            
            // Store for JavaScript alert
            $_SESSION['duplicate_alert'] = $duplicate_message;
        }
        
        if ($failed_count > 0) {
            $error_message = "فشل في إضافة التقييم لـ $failed_count طالب/طلاب.";
            if (!empty($error_details)) {
                $error_message .= "\nتفاصيل الأخطاء:\n" . implode("\n", $error_details);
            }
            $_SESSION['error_message'] = $error_message;
        }

    } catch (Exception $e) {
        $_SESSION['error_message'] = $e->getMessage();
    }
    
    // PRG Pattern: Redirect after POST to prevent form resubmission
    // احفظ class_id للعودة إليه بعد Redirect
    $redirect_class_id = isset($_POST['class_id']) ? $_POST['class_id'] : $selected_class_id;
    header("Location: index.php?class_id=" . $redirect_class_id);
    exit();
}

// Retrieve messages from session (after redirect)
$success_message = isset($_SESSION['success_message']) ? $_SESSION['success_message'] : null;
$error_message = isset($_SESSION['error_message']) ? $_SESSION['error_message'] : null;

// Clear messages from session after retrieving
if (isset($_SESSION['success_message'])) unset($_SESSION['success_message']);
if (isset($_SESSION['error_message'])) unset($_SESSION['error_message']);

// Get selected class
$selected_class_id = isset($_GET['class_id']) ? $_GET['class_id'] : null;

// Get assigned classes for the teacher
$assigned_classes = $user->getAssignedClasses();

// If no class is selected, use the first one if available
if ($selected_class_id === null && !empty($assigned_classes)) {
    $selected_class_id = $assigned_classes[0]['id'];
}
?>
<!DOCTYPE html>
<html lang="ar" dir="rtl">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">    <title><?php echo $page_title; ?> - نظام الإدارة المدرسية</title>
    <!-- Optimized loading for mobile -->
    <meta name="theme-color" content="#0d6efd">
    
    <!-- Preload critical CSS -->
    <link rel="preload" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.rtl.min.css" as="style" onload="this.onload=null;this.rel='stylesheet'">
    <noscript><link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.rtl.min.css"></noscript>
    
    <!-- Load Font Awesome asynchronously -->
    <link rel="preload" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css" as="style" onload="this.onload=null;this.rel='stylesheet'">
    <noscript><link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css"></noscript>
    
    <!-- Custom CSS (global + mobile) -->
    <link rel="stylesheet" href="<?php echo asset_url('../assets/css/style.css'); ?>">
    <link rel="stylesheet" href="<?php echo asset_url('../assets/css/mobile-fixes.css'); ?>">
    
    <style>
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
        
        /* منع الضغط المزدوج - تعطيل الزر بصرياً */
        .btn-submitting {
            opacity: 0.6;
            cursor: not-allowed !important;
            pointer-events: none !important;
        }
        
        /* تأثير بصري للزر عند التحميل */
        @keyframes pulse {
            0%, 100% {
                opacity: 0.6;
            }
            50% {
                opacity: 0.8;
            }
        }
        
        .btn-submitting {
            animation: pulse 1.5s infinite;
        }
    </style>
</head>
<body class="teacher-page">
    <div id="particles-js"></div>
    
    <!-- Main Content -->
    <div class="container" style="margin-top: 0; padding-top: 1.5rem;">
        
        <?php
        // === Teacher Notifications ===
        require_once '../includes/notifications_helper.php';
        $teacherNotifications = getTeacherNotifications($db, $_SESSION['user_id']);
        if (!empty($teacherNotifications)):
        ?>
        <div class="mb-3">
            <?php echo renderNotificationAlerts($teacherNotifications, '../api/dismiss_notification.php'); ?>
        </div>
        <?php endif; ?>
        
        <!-- زر العودة للبوابة -->
        <div class="mb-3 text-end">
            <a href="portal.php" class="portal-back-btn">
                <i class="fas fa-arrow-right me-2"></i>العودة للبوابة
            </a>
        </div>
        
        <!-- عنوان الصفحة -->
        <div class="text-center mb-4">
            <h2 class="mb-2">
                <i class="fas fa-clipboard-check me-2" style="color: #0d6efd;"></i>
                إضافة تقييمات الطلاب
            </h2>
            <p class="text-muted">قم بتقييم الطلاب وإضافة النقاط</p>
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
        
        <!-- Alerts -->
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
        
        <?php 
        // عرض الرسائل في Modal بدلاً من Alert المضمن
        ?>
        
        <?php if (count($assigned_classes) == 0): ?>
            <div class="alert alert-warning">
                <i class="fas fa-exclamation-triangle me-2"></i> لم يتم إسناد أي فصول إليك بعد. يرجى التواصل مع الإدارة.
            </div>
        <?php elseif (!$evaluation_check['allowed']): ?>
            <div class="alert alert-info text-center sticky-alert">
                <p class="mb-0 mt-2">يمكنك الانتقال إلى <a href="evaluations.php" class="alert-link">سجل التقييمات</a> لمراجعة التقييمات السابقة</p>
            </div>
        <?php else: ?>
            <!-- Class Selection -->
            <div class="card shadow mb-4">
                <div class="card-header bg-primary text-white">
                    <h5 class="mb-0"><i class="fas fa-school me-2"></i> اختر الفصل</h5>
                </div>                <div class="card-body">                    <!-- عرض الفصول - تصميم أنيق ومضغوط -->
                    <div class="row g-3">
                        <?php foreach ($assigned_classes as $class): ?>
                            <div class="col-6 col-sm-4 col-md-3 col-lg-2">
                                <a href="index.php?class_id=<?php echo $class['id']; ?>" class="text-decoration-none">
                                    <div class="card class-card <?php echo $class['id'] == $selected_class_id ? 'border-primary' : 'border-light'; ?> h-100 text-center">
                                        <div class="card-body">
                                            <i class="fas fa-users mb-2 text-primary"></i>
                                            <h6 class="card-title"><?php echo $class['name']; ?></h6>
                                            <?php if ($class['id'] == $selected_class_id): ?>
                                                <small class="text-primary fw-bold">
                                                    <i class="fas fa-check-circle me-1"></i>مُختار
                                                </small>
                                            <?php endif; ?>
                                        </div>
                                    </div>
                                </a>
                            </div>
                        <?php endforeach; ?>
                    </div>
                </div>
            </div>
              <?php if ($selected_class_id): ?>                <!-- Evaluation Form -->
<form method="POST" action="" id="evaluationForm">
    <?php echo csrfField(); ?>
                    <input type="hidden" name="class_id" id="class_id" value="<?php echo $selected_class_id; ?>">
                    
                    <!-- Students Section -->
                    <div class="card shadow mb-4">
                        <div class="card-header bg-primary text-white d-flex justify-content-between align-items-center">
                            <h5 class="mb-0"><i class="fas fa-user-graduate me-2"></i> اختر الطلاب</h5>
                            <div>
                                <button type="button" class="btn btn-sm btn-light" id="selectAllBtn">
                                    <i class="fas fa-check-double me-1"></i>
                                    اختيار الكل
                                </button>
                                <button type="button" class="btn btn-sm btn-light" id="clearAllBtn">
                                    <i class="fas fa-times me-1"></i>
                                    إلغاء الكل
                                </button>
                                <small class="text-light ms-2" id="selectedCount">0 طالب محدد</small>
                            </div>
                        </div><div class="card-body">
                                    <?php
                                    // Get students in the selected class - استعلام واحد فقط
                                    $student_user = new User($db);
                                    $students = $student_user->getUsersByClass($selected_class_id);
                                    ?>
                                    
                                    <!-- عرض عادي للشاشات الكبيرة -->
                                    <div class="row row-cols-1 row-cols-md-2 row-cols-lg-3 row-cols-xl-4 g-3 d-none d-sm-flex">
                                        <?php foreach ($students as $row): ?>
                                            <div class="col">
                                                <label class="evaluation-checkbox w-100">
                                                    <input type="checkbox" name="student_ids[]" id="student_<?php echo $row['id']; ?>" value="<?php echo $row['id']; ?>" class="student-checkbox">
                                                    <div class="card student-card h-100">
                                                        <div class="card-body text-center">
                                                            <i class="fas fa-user fa-2x mb-2 text-primary"></i>
                                                            <h6 class="card-title"><?php echo $row['name']; ?></h6>
                                                        </div>
                                                    </div>
                                                </label>
                                            </div>
                                        <?php endforeach; ?>
                                    </div>                                    <!-- عرض مضغوط للشاشات الصغيرة - تصميم احترافي -->
                                    <div class="d-block d-sm-none">
                                        <div class="student-mobile-list">
                                            <?php foreach ($students as $row): ?>
                                                <div class="student-mobile-item">
                                                    <label>
                                                        <input type="checkbox" name="student_ids[]" id="student_mobile_<?php echo $row['id']; ?>" value="<?php echo $row['id']; ?>" class="form-check-input student-checkbox" onchange="selectStudentMobile(this)">
                                                        <div class="student-info">
                                                            <div class="student-name">
                                                                <i class="fas fa-user text-primary me-2"></i>
                                                                <?php echo $row['name']; ?>
                                                            </div>
                                                        </div>
                                                    </label>
                                                </div>
                                            <?php endforeach; ?>
                                        </div>
                                    </div>
                                </div>
                            </div>                        </div>
                    </div>
                    
                    <!-- Evaluation Types Section -->
                    <div class="card shadow mb-4">
                        <div class="card-header bg-primary text-white">
                            <h5 class="mb-0"><i class="fas fa-star me-2"></i> اختر التقييم</h5>
                        </div><div class="card-body">
                                    <?php
                                    // جلب أنواع التقييمات مرة واحدة فقط وتنظيمها حسب النوع (بدون الأنواع المخصصة للأدمن)
                                    $evaluation_type = new EvaluationType($db);
                                    $all_evaluation_types = $evaluation_type->readAll(false); // false = exclude admin-only types
                                    
                                    $positive_types = [];
                                    $negative_types = [];
                                    
                                    while ($row = $all_evaluation_types->fetch(PDO::FETCH_ASSOC)) {
                                        if ($row['type'] === 'positive') {
                                            $positive_types[] = $row;
                                        } else {
                                            $negative_types[] = $row;
                                        }
                                    }
                                    ?>
                                    
                                    <ul class="nav nav-tabs mb-3" id="evaluationTypeTabs" role="tablist">
                                        <li class="nav-item" role="presentation">
                                            <button class="nav-link active" id="positive-tab" data-bs-toggle="tab" data-bs-target="#positive" type="button" role="tab">
                                                <i class="fas fa-plus-circle text-success me-1"></i> إيجابي
                                            </button>
                                        </li>
                                        <li class="nav-item" role="presentation">
                                            <button class="nav-link" id="negative-tab" data-bs-toggle="tab" data-bs-target="#negative" type="button" role="tab">
                                                <i class="fas fa-minus-circle text-danger me-1"></i> سلبي
                                            </button>
                                        </li>
                                    </ul>
                                      <div class="tab-content" id="evaluationTypeTabsContent">
                                        <!-- Positive Evaluations -->
                                        <div class="tab-pane fade show active" id="positive" role="tabpanel">
                                            <!-- عنوان للموبايل -->
                                            <div class="d-block d-sm-none mobile-section-title">
                                                <i class="fas fa-plus-circle me-1"></i> التقييمات الإيجابية
                                            </div>
                                            
                                            <!-- عرض عادي للشاشات الكبيرة -->
                                            <div class="row row-cols-1 row-cols-md-2 row-cols-lg-3 row-cols-xl-4 g-3 d-none d-sm-flex">
                                                <?php foreach ($positive_types as $row): ?>
                                                    <div class="col">
                                                        <label class="evaluation-checkbox w-100">
                                                            <input type="radio" name="evaluation_type_id" id="eval_type_positive_<?php echo $row['id']; ?>" value="<?php echo $row['id']; ?>" required>
                                                            <div class="card evaluation-type-card h-100">
                                                                <div class="card-body text-center">
                                                                    <span class="badge bg-success mb-2">+<?php echo $row['points']; ?></span>
                                                                    <h6 class="card-title"><?php echo $row['name']; ?></h6>
                                                                </div>
                                                            </div>
                                                        </label>
                                                    </div>
                                                <?php endforeach; ?>
                                            </div>
                                            
                                            <!-- عرض مضغوط للشاشات الصغيرة -->
                                            <div class="d-block d-sm-none">
                                                <div class="evaluation-type-mobile-list">
                                                    <?php foreach ($positive_types as $row): ?>
                                                        <div class="evaluation-mobile-item">
                                                            <label>
                                                                <input type="radio" name="evaluation_type_id" value="<?php echo $row['id']; ?>" required style="display: none;" onchange="selectEvaluationMobile(this)">
                                                                <div class="eval-info">
                                                                    <div class="eval-name"><?php echo $row['name']; ?></div>
                                                                </div>
                                                                <div class="eval-points positive">+<?php echo $row['points']; ?></div>
                                                            </label>
                                                        </div>
                                                    <?php endforeach; ?>
                                                </div>
                                            </div>
                                        </div>
                                        
                                        <!-- Negative Evaluations -->
                                        <div class="tab-pane fade" id="negative" role="tabpanel">
                                            <!-- عنوان للموبايل -->
                                            <div class="d-block d-sm-none mobile-section-title">
                                                <i class="fas fa-minus-circle me-1"></i> التقييمات السلبية
                                            </div>
                                            
                                            <!-- عرض عادي للشاشات الكبيرة -->
                                            <div class="row row-cols-1 row-cols-md-2 row-cols-lg-3 row-cols-xl-4 g-3 d-none d-sm-flex">
                                                <?php foreach ($negative_types as $row): ?>
                                                    <div class="col">
                                                        <label class="evaluation-checkbox w-100">
                                                            <input type="radio" name="evaluation_type_id" value="<?php echo $row['id']; ?>" required>
                                                            <div class="card evaluation-type-card h-100">
                                                                <div class="card-body text-center">
                                                                    <span class="badge bg-danger mb-2">-<?php echo $row['points']; ?></span>
                                                                    <h6 class="card-title"><?php echo $row['name']; ?></h6>
                                                                </div>
                                                            </div>
                                                        </label>
                                                    </div>
                                                <?php endforeach; ?>
                                            </div>
                                            
                                            <!-- عرض مضغوط للشاشات الصغيرة -->
                                            <div class="d-block d-sm-none">
                                                <div class="evaluation-type-mobile-list">
                                                    <?php foreach ($negative_types as $row): ?>
                                                        <div class="evaluation-mobile-item">
                                                            <label>
                                                                <input type="radio" name="evaluation_type_id" value="<?php echo $row['id']; ?>" required style="display: none;" onchange="selectEvaluationMobile(this)">
                                                                <div class="eval-info">
                                                                    <div class="eval-name"><?php echo $row['name']; ?></div>
                                                                </div>
                                                                <div class="eval-points negative">-<?php echo $row['points']; ?></div>
                                                            </label>
                                                        </div>
                                                    <?php endforeach; ?>
                                                </div>
                                            </div>
                                        </div>
                                    </div>
                                </div>
                            </div>                        </div>
                    </div>
                    <!-- Reason section hidden from teacher interface as requested -->
                    <!-- <div class="card shadow mb-4 reason-section">
                        <div class="card-header bg-primary text-white">
                            <h5 class="mb-0"><i class="fas fa-comment me-2"></i> السبب أو الوصف (اختياري)</h5>
                        </div>
                        <div class="card-body">
                            <div class="mb-3">
                                <textarea class="form-control" name="reason" rows="3" placeholder="اكتب السبب أو وصف للتقييم (اختياري)..."></textarea>
                                <div class="form-text">يمكنك إضافة توضيح أو سبب للتقييم مثل: "مشاركة ممتازة في الحصة" أو "عدم أداء الواجب"</div>
                            </div>
                        </div>
                    </div> -->                    <div class="d-grid">
                        <input type="hidden" name="add_evaluation" id="add_evaluation" value="1">
                        <input type="hidden" name="form_submitted" id="form_submitted" value="1">
                        <?php if ($evaluation_check['allowed']): ?>
                            <button type="submit" name="submit_evaluation" value="1" class="btn btn-primary btn-lg">
                                <i class="fas fa-save me-1"></i> حفظ التقييم
                            </button>
                        <?php else: ?>
                            <button type="button" class="btn btn-secondary btn-lg" disabled>
                                <i class="fas fa-ban me-1"></i> التقييمات متوقفة حالياً
                            </button>
                        <?php endif; ?>
                    </div>
                </form>
            <?php endif; ?>
        <?php endif; ?>
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

    <!-- Footer -->
    <footer class="bg-light py-4 mt-5">
        <div class="container text-center">
            <p class="mb-0"><?php echo date('Y'); ?> جميع الحقوق محفوظة ©<br>
        EduCore <br>
        Computer Department</p>
        </div>
    </footer>    <!-- Scripts loaded asynchronously for better performance -->
    <!-- Essential Bootstrap JS only -->
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js" defer></script>
      <!-- Core functionality only - no jQuery for mobile performance -->
    <script defer>
    document.addEventListener('DOMContentLoaded', function() {
        // Multi-select functionality for students
        
        // Select All functionality - only for currently visible view
        document.getElementById('selectAllBtn')?.addEventListener('click', function() {
            const isDesktop = window.innerWidth >= 576;
            let checkboxes;
            
            if (isDesktop) {
                checkboxes = document.querySelectorAll('.d-none.d-sm-flex .student-checkbox');
            } else {
                checkboxes = document.querySelectorAll('.d-block.d-sm-none .student-checkbox');
            }
            
            checkboxes.forEach(checkbox => {
                checkbox.checked = true;
                updateStudentCard(checkbox, true);
            });
            updateSelectedCount();
        });
        
        // Clear All functionality - only for currently visible view
        document.getElementById('clearAllBtn')?.addEventListener('click', function() {
            const isDesktop = window.innerWidth >= 576;
            let checkboxes;
            
            if (isDesktop) {
                checkboxes = document.querySelectorAll('.d-none.d-sm-flex .student-checkbox');
            } else {
                checkboxes = document.querySelectorAll('.d-block.d-sm-none .student-checkbox');
            }
            
            checkboxes.forEach(checkbox => {
                checkbox.checked = false;
                updateStudentCard(checkbox, false);
            });
            updateSelectedCount();
        });
        
        // Update student card visual state and sync between views
        function updateStudentCard(checkbox, isSelected) {
            const studentId = checkbox.value;
            
            // Update visual state for the current checkbox
            const card = checkbox.closest('.student-card, .student-mobile-item');
            if (card) {
                if (isSelected) {
                    card.classList.add('selected');
                } else {
                    card.classList.remove('selected');
                }
            }
            
            // Sync the corresponding checkbox in the other view (desktop/mobile)
            const allCheckboxesForStudent = document.querySelectorAll(`input[value="${studentId}"][name="student_ids[]"]`);
            allCheckboxesForStudent.forEach(cb => {
                if (cb !== checkbox) {
                    cb.checked = isSelected;
                    const otherCard = cb.closest('.student-card, .student-mobile-item');
                    if (otherCard) {
                        if (isSelected) {
                            otherCard.classList.add('selected');
                        } else {
                            otherCard.classList.remove('selected');
                        }
                    }
                }
            });
        }
        
        // Update selected count display - count only visible checkboxes
        function updateSelectedCount() {
            // Get the currently visible checkboxes (desktop or mobile view)
            const isDesktop = window.innerWidth >= 576;
            let visibleCheckboxes, selectedCheckboxes;
            
            if (isDesktop) {
                // Desktop view - count checkboxes in the grid layout
                visibleCheckboxes = document.querySelectorAll('.d-none.d-sm-flex .student-checkbox');
                selectedCheckboxes = document.querySelectorAll('.d-none.d-sm-flex .student-checkbox:checked');
            } else {
                // Mobile view - count checkboxes in the mobile list
                visibleCheckboxes = document.querySelectorAll('.d-block.d-sm-none .student-checkbox');
                selectedCheckboxes = document.querySelectorAll('.d-block.d-sm-none .student-checkbox:checked');
            }
            
            const selectedCount = selectedCheckboxes.length;
            const totalCount = visibleCheckboxes.length;
            const countElement = document.getElementById('selectedCount');
            
            if (countElement) {
                countElement.textContent = `${selectedCount} من ${totalCount}`;
            }
        }
        
        // Add change listeners to all student checkboxes
        document.querySelectorAll('.student-checkbox').forEach(checkbox => {
            checkbox.addEventListener('change', function() {
                updateStudentCard(this, this.checked);
                updateSelectedCount();
            });
        });
        
        // Initial count update
        updateSelectedCount();
        
        // Update count when window resizes (desktop/mobile view change)
        window.addEventListener('resize', function() {
            setTimeout(updateSelectedCount, 100); // Small delay to ensure layout has updated
        });
        
        // Mobile student selection handler
        window.selectStudentMobile = function(input) {
            updateStudentCard(input, input.checked);
            updateSelectedCount();
        }
        
        // Mobile evaluation type selection handler  
        window.selectEvaluationMobile = function(input) {
            // Clear other selections
            document.querySelectorAll('.evaluation-mobile-item').forEach(item => {
                item.classList.remove('selected');
            });
            // Mark current as selected
            input.closest('.evaluation-mobile-item').classList.add('selected');
        }
        
        // Add click handlers for mobile items
        document.querySelectorAll('.student-mobile-item label').forEach(label => {
            label.addEventListener('click', function(e) {
                e.preventDefault();
                const checkbox = this.querySelector('input[type="checkbox"]');
                if (checkbox) {
                    checkbox.checked = !checkbox.checked;
                    selectStudentMobile(checkbox);
                }
            });
        });
        
        document.querySelectorAll('.evaluation-mobile-item label').forEach(label => {
            label.addEventListener('click', function(e) {
                e.preventDefault();
                const radio = this.querySelector('input[type="radio"]');
                if (radio) {
                    radio.checked = true;
                    selectEvaluationMobile(radio);
                }
            });
        });
        
        // Form validation with enhanced error display and double-submit prevention
        const form = document.getElementById('evaluationForm');
        let isSubmitting = false; // Flag to prevent double submission
        
        if (form) {
            form.addEventListener('submit', function(e) {
                // Prevent double submission
                if (isSubmitting) {
                    e.preventDefault();
                    console.log('Form already submitting, preventing duplicate submission');
                    return false;
                }
                
                // Get only the visible/active checkboxes based on current view
                const isDesktop = window.innerWidth >= 576;
                let selectedStudents;
                
                if (isDesktop) {
                    selectedStudents = document.querySelectorAll('.d-none.d-sm-flex .student-checkbox:checked');
                } else {
                    selectedStudents = document.querySelectorAll('.d-block.d-sm-none .student-checkbox:checked');
                }
                
                const evaluation = document.querySelector('input[name="evaluation_type_id"]:checked');
                
                // Clear previous error states
                document.querySelectorAll('.form-validation-error').forEach(el => {
                    el.classList.remove('form-validation-error');
                });
                
                document.querySelectorAll('.student-selection-required').forEach(el => {
                    el.classList.remove('student-selection-required');
                });
                
                document.querySelectorAll('.evaluation-type-required').forEach(el => {
                    el.classList.remove('evaluation-type-required');
                });
                
                // Debug logging
                console.log('Form submission attempt:');
                console.log('View type:', isDesktop ? 'Desktop' : 'Mobile');
                console.log('Selected students count:', selectedStudents.length);
                console.log('Selected students:', Array.from(selectedStudents).map(s => s.value));
                console.log('Selected evaluation:', evaluation ? evaluation.value : 'none');
                
                let hasError = false;
                
                if (selectedStudents.length === 0) {
                    e.preventDefault();
                    hasError = true;
                    
                    // Highlight student selection area
                    const studentCard = document.querySelector('.card:has(.student-checkbox)');
                    if (studentCard) {
                        studentCard.classList.add('student-selection-required');
                    }
                    
                    // Show error message
                    alert('⚠️ يرجى اختيار طالب واحد على الأقل');
                    
                    // Scroll to students section
                    const studentsSection = document.querySelector('.card:has(.student-checkbox)');
                    if (studentsSection) {
                        studentsSection.scrollIntoView({ behavior: 'smooth', block: 'start' });
                    }
                }
                
                if (!evaluation) {
                    e.preventDefault();
                    hasError = true;
                    
                    // Highlight evaluation type area
                    const evalCard = document.querySelector('.card:has(input[name="evaluation_type_id"])');
                    if (evalCard) {
                        evalCard.classList.add('evaluation-type-required');
                    }
                    
                    // Show error message if not already shown
                    if (selectedStudents.length > 0) {
                        alert('⚠️ يرجى اختيار نوع التقييم');
                        
                        // Scroll to evaluation types section
                        const evalSection = document.querySelector('.card:has(input[name="evaluation_type_id"])');
                        if (evalSection) {
                            evalSection.scrollIntoView({ behavior: 'smooth', block: 'start' });
                        }
                    }
                }
                
                if (hasError) {
                    return false;
                }
                
                // Mark form as submitting
                isSubmitting = true;
                
                // Show loading state and disable button
                const btn = this.querySelector('button[type="submit"]');
                if (btn) {
                    btn.disabled = true;
                    btn.classList.add('btn-submitting');
                    btn.style.pointerEvents = 'none';
                    btn.innerHTML = '<i class="fas fa-spinner fa-spin me-1"></i> جاري الحفظ...';
                    
                    // Ensure proper form submission
                    const hiddenInput = document.createElement('input');
                    hiddenInput.type = 'hidden';
                    hiddenInput.name = 'add_evaluation';
                    hiddenInput.value = '1';
                    this.appendChild(hiddenInput);
                }
                
                // Reset flag after 5 seconds as a safety measure (in case of network issues)
                setTimeout(function() {
                    isSubmitting = false;
                    if (btn) {
                        btn.disabled = false;
                        btn.classList.remove('btn-submitting');
                        btn.style.pointerEvents = 'auto';
                        btn.innerHTML = '<i class="fas fa-save me-1"></i> حفظ التقييم';
                    }
                }, 5000);
                
                return true;
            });
        }
        
        // Show messages in modal
        <?php if (isset($_SESSION['duplicate_alert'])): ?>
        showMessageModal(
            'warning',
            'تنبيه - تقييمات مكررة',
            <?php echo json_encode($_SESSION['duplicate_alert']); ?>
        );
        <?php 
            unset($_SESSION['duplicate_alert']); // Clear after showing
        endif; 
        ?>
        
        <?php if (isset($_SESSION['success_modal'])): ?>
        showMessageModal(
            'success',
            'تم بنجاح',
            <?php echo json_encode($_SESSION['success_modal']); ?>
        );
        <?php 
            unset($_SESSION['success_modal']); // Clear after showing
        endif; 
        ?>
        
        // Function to show message modal
        function showMessageModal(type, title, message) {
            const modal = document.getElementById('messageModal');
            const modalHeader = document.getElementById('messageModalHeader');
            const modalTitle = document.getElementById('messageModalTitle');
            const modalIcon = document.getElementById('messageModalIcon');
            const modalBody = document.getElementById('messageModalBody');
            
            // Set content
            modalTitle.textContent = title;
            modalBody.textContent = message;
            
            // Set colors and icons based on type
            if (type === 'success') {
                modalHeader.className = 'modal-header modal-header-success';
                modalIcon.className = 'fas fa-check-circle me-2';
            } else if (type === 'warning') {
                modalHeader.className = 'modal-header modal-header-warning';
                modalIcon.className = 'fas fa-exclamation-triangle me-2';
            } else if (type === 'error') {
                modalHeader.className = 'modal-header modal-header-danger';
                modalIcon.className = 'fas fa-times-circle me-2';
            }
            
            // Show modal
            const bsModal = new bootstrap.Modal(modal);
            bsModal.show();
            
            // Clean up backdrop when modal is hidden
            modal.addEventListener('hidden.bs.modal', function () {
                // Remove any leftover backdrops
                const backdrops = document.querySelectorAll('.modal-backdrop');
                backdrops.forEach(backdrop => backdrop.remove());
                // Restore body scroll
                document.body.classList.remove('modal-open');
                document.body.style.overflow = '';
                document.body.style.paddingRight = '';
            }, { once: true });
        }
        
        // عرض رسائل النجاح أو الخطأ في Modal
        <?php if (isset($success_message)): ?>
        showMessageModal('success', 'تم بنجاح', '<?php echo addslashes($success_message); ?>');
        <?php endif; ?>
        <?php if (isset($error_message)): ?>
        showMessageModal('error', 'خطأ', '<?php echo addslashes($error_message); ?>');
        <?php endif; ?>
        
        // Auto-hide flash messages (except sticky alerts)
        setTimeout(function() {
            const alerts = document.querySelectorAll('.alert:not(.sticky-alert)');
            alerts.forEach(alert => {
                alert.style.opacity = '0';
                setTimeout(() => alert.style.display = 'none', 300);
            });
        }, 3000);
    });
    </script>
    <!-- Particles + Theme Toggle Scripts -->
    <script src="https://cdn.jsdelivr.net/npm/particles.js@2.0.0/particles.min.js"></script>
    <script src="<?php echo asset_url('../assets/js/particles_theme.js'); ?>"></script>
    <!-- Note: main.js removed to avoid jQuery errors since this page uses vanilla JS -->
</body>
</html>
