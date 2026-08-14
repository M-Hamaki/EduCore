<?php
// Include no_cache first before any output
require_once '../includes/no_cache.php';

// Set page title
$page_title = "إعدادات نظام نقاط المكافئات";
$custom_page_title = true; // هذه الصفحة لديها عنوان مخصص

// Include database and necessary classes
require_once '../config/database.php';
require_once '../classes/user.php';
require_once '../classes/utilities.php';
require_once '../classes/ActivityLog.php';
require_once '../includes/csrf.php';

// Initialize database connection
$database = new Database();
$db = $database->getConnection();

// Check if session is started
if (session_status() == PHP_SESSION_NONE) {
    session_start();
}

// Validate session for admin role
Utilities::validateSession('admin');

// Get messages from session
$success_message = $_SESSION['success_message'] ?? null;
$error_message = $_SESSION['error_message'] ?? null;
unset($_SESSION['success_message'], $_SESSION['error_message']);

// Handle form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['update_settings'])) {
    requireCsrfPost();
    try {
        // Helper: upsert a setting (INSERT if missing, UPDATE if present).
        // This guarantees persistence regardless of whether the row pre-exists,
        // unlike a bare UPDATE which silently affects 0 rows on a fresh DB.
        $upsertSetting = function ($key, $value, $description = '') use ($db) {
            $stmt = $db->prepare("SELECT id FROM settings WHERE setting_key = ?");
            $stmt->execute([$key]);
            if ($stmt->rowCount() > 0) {
                $stmt = $db->prepare("UPDATE settings SET setting_value = ? WHERE setting_key = ?");
                $stmt->execute([$value, $key]);
            } else {
                $stmt = $db->prepare("INSERT INTO settings (setting_key, setting_value, description) VALUES (?, ?, ?)");
                $stmt->execute([$key, $value, $description]);
            }
        };

        // Update evaluation settings - checkbox is only present when checked
        // So we need to handle both checked (1) and unchecked (0) states
        $evaluations_enabled = isset($_POST['evaluations_enabled']) ? '1' : '0';

        // Update allowed days
        $allowed_days = isset($_POST['allowed_days'])
            ? (is_array($_POST['allowed_days']) ? implode(',', $_POST['allowed_days']) : '')
            : '';

        // Update time windows with light H:i validation (defaults to 00:00 / 23:59 on bad input)
        $allowed_time_from = isset($_POST['allowed_time_from']) ? trim($_POST['allowed_time_from']) : '00:00';
        $allowed_time_to   = isset($_POST['allowed_time_to'])   ? trim($_POST['allowed_time_to'])   : '23:59';
        if (!preg_match('/^([01][0-9]|2[0-3]):[0-5][0-9]$/', $allowed_time_from)) { $allowed_time_from = '00:00'; }
        if (!preg_match('/^([01][0-9]|2[0-3]):[0-5][0-9]$/', $allowed_time_to))   { $allowed_time_to   = '23:59'; }

        // Update unlimited time setting
        $unlimited_time = isset($_POST['unlimited_time']) ? '1' : '0';

        // Get current teacher deletion settings for transition check
        $stmt = $db->prepare("SELECT setting_value FROM settings WHERE setting_key = 'teacher_delete_limit_enabled'");
        $stmt->execute();
        $prev_enabled = $stmt->fetchColumn();

        // Update teacher deletion settings from POST
        $teacher_delete_limit_enabled = isset($_POST['teacher_delete_limit_enabled']) ? '1' : '0';
        $teacher_delete_limit_minutes = isset($_POST['teacher_delete_limit_minutes']) ? intval($_POST['teacher_delete_limit_minutes']) : 180;
        $teacher_delete_retroactive = isset($_POST['teacher_delete_retroactive']) ? $_POST['teacher_delete_retroactive'] : '1';

        // Determine enabled_at timestamp
        if ($prev_enabled === '0' && $teacher_delete_limit_enabled === '1') {
            // Switched from OFF to ON -> Start new window
            $teacher_delete_enabled_at = date('Y-m-d H:i:s');
        } else {
            // Keep existing timestamp or initialize if never set
            $stmt = $db->prepare("SELECT setting_value FROM settings WHERE setting_key = 'teacher_delete_enabled_at'");
            $stmt->execute();
            $teacher_delete_enabled_at = $stmt->fetchColumn() ?: date('Y-m-d H:i:s');
        }

        // Upsert ALL settings uniformly (fixes silent-write-loss bug for the first 4 keys)
        $dynamic_settings = [
            'evaluations_enabled'         => [$evaluations_enabled, 'تفعيل نظام التقييمات (1=مفعل, 0=معطل)'],
            'allowed_days'                => [$allowed_days, 'الأيام المسموح بها للتقييم (مفصولة بفواصل)'],
            'allowed_time_from'           => [$allowed_time_from, 'بداية الفترة المسموح بها للتقييم'],
            'allowed_time_to'             => [$allowed_time_to, 'نهاية الفترة المسموح بها للتقييم'],
            'unlimited_time'              => [$unlimited_time, 'السماح بالتقييم في أي وقت طوال اليوم (1=مفعل, 0=معطل)'],
            'teacher_delete_limit_enabled'=> [$teacher_delete_limit_enabled, 'السماح للمعلم بحذف تقييماته (1=مفعل, 0=معطل)'],
            'teacher_delete_limit_minutes'=> [$teacher_delete_limit_minutes, 'المدة المسموح بها للمعلم لحذف التقييم (بالدقائق)'],
            'teacher_delete_retroactive'  => [$teacher_delete_retroactive, 'تطبيق سياسة الحذف بأثر رجعي (1=نعم، 0=الجديد فقط)'],
            'teacher_delete_enabled_at'   => [$teacher_delete_enabled_at, 'وقت آخر تفعيل لخاصية الحذف']
        ];

        foreach ($dynamic_settings as $key => $info) {
            $upsertSetting($key, $info[0], $info[1]);
        }

        $_SESSION['success_message'] = "تم تحديث الإعدادات بنجاح ✓";

        ActivityLog::log('settings', 'settings', null, 'تحديث إعدادات نظام التقييم', [
            'evaluations_enabled' => $evaluations_enabled,
            'unlimited_time' => $unlimited_time,
            'teacher_delete_limit_enabled' => $teacher_delete_limit_enabled,
            'teacher_delete_limit_minutes' => $teacher_delete_limit_minutes,
            'teacher_delete_retroactive' => $teacher_delete_retroactive
        ]);
        
        header("Location: evaluation_settings.php");
        exit();
    } catch (Exception $e) {
        $error_message = "خطأ في حفظ الإعدادات: " . $e->getMessage();
        $_SESSION['error_message'] = $error_message;
        header("Location: evaluation_settings.php");
        exit();
    }
}

// Fetch current settings
$settings = [];
$stmt = $db->query("SELECT setting_key, setting_value FROM settings");
while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
    $settings[$row['setting_key']] = $row['setting_value'];
}
?>
<!DOCTYPE html>
<html lang="ar" dir="rtl">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo $page_title; ?> - نظام الإدارة المدرسية</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.rtl.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css">
    <link rel="stylesheet" href="../assets/css/style.css">
    <style>
        .settings-card {
            border: none;
            border-radius: 10px;
            box-shadow: 0 2px 8px rgba(0,0,0,0.08);
            margin-bottom: 1.5rem;
            transition: box-shadow 0.3s ease;
        }
        .settings-card:hover {
            box-shadow: 0 4px 12px rgba(0,0,0,0.12);
        }
        .section-header {
            background: #f8f9fa;
            border-bottom: 2px solid #e9ecef;
            padding: 1rem 1.25rem;
            font-weight: 600;
        }
        .status-badge {
            display: inline-flex;
            align-items: center;
            padding: 0.5rem 1rem;
            border-radius: 50px;
            font-weight: 500;
        }
        .status-badge i {
            margin-left: 0.5rem;
        }
        .day-checkbox {
            position: relative;
            padding: 0.75rem;
            border: 2px solid #e0e0e0;
            border-radius: 10px;
            text-align: center;
            cursor: pointer;
            transition: all 0.25s ease;
            background: #ffffff;
            box-shadow: 0 2px 4px rgba(0,0,0,0.05);
        }
        .day-checkbox:hover {
            border-color: #0d6efd;
            background: #f0f7ff;
            box-shadow: 0 4px 8px rgba(13,110,253,0.15);
            transform: translateY(-2px);
        }
        .day-checkbox.selected {
            background: linear-gradient(135deg, #0d6efd 0%, #0a58ca 100%);
            color: white;
            border-color: #0d6efd;
            box-shadow: 0 4px 12px rgba(13,110,253,0.3);
        }
        .day-checkbox.selected:hover {
            background: linear-gradient(135deg, #0a58ca 0%, #084298 100%);
            transform: translateY(-2px);
        }
        .day-checkbox input[type="checkbox"] {
            position: absolute;
            opacity: 0;
        }
        .day-checkbox i {
            display: block;
            font-size: 1.5rem;
            margin-bottom: 0.5rem;
        }
        .day-checkbox span {
            display: block;
            font-weight: 600;
            font-size: 0.95rem;
        }
        .time-input-group {
            background: #f8f9fa;
            padding: 1.5rem;
            border-radius: 8px;
            border: 1px solid #dee2e6;
        }
        
        /* رسالة حالة النظام الثابتة */
        .sticky-status-alert {
            position: sticky !important;
            top: 20px !important;
            z-index: 1020 !important;
            animation: none !important;
            transition: all 0.3s ease !important;
            display: block !important;
            opacity: 1 !important;
            visibility: visible !important;
        }
        
        /* منع Bootstrap من إخفاء الرسالة */
        .sticky-status-alert.fade {
            opacity: 1 !important;
        }
        
        .sticky-status-alert.show {
            display: block !important;
        }
        
        .sticky-status-alert:hover {
            transform: translateY(-2px);
            box-shadow: 0 8px 20px rgba(0,0,0,0.15) !important;
        }
        
        /* ألوان مخصصة لكل حالة */
        .alert-success.sticky-status-alert {
            background: linear-gradient(135deg, #d4edda 0%, #c3e6cb 100%) !important;
            border: 2px solid #28a745 !important;
            color: #155724 !important;
        }
        
        .alert-warning.sticky-status-alert {
            background: linear-gradient(135deg, #fff3cd 0%, #ffeaa7 100%) !important;
            border: 2px solid #ffc107 !important;
            color: #856404 !important;
        }
        
        .alert-danger.sticky-status-alert {
            background: linear-gradient(135deg, #f8d7da 0%, #f5c6cb 100%) !important;
            border: 2px solid #dc3545 !important;
            color: #721c24 !important;
        }
        
        /* تنسيق زر التفعيل/التعطيل */
        .evaluation-toggle {
            width: 3rem !important;
            height: 1.5rem !important;
            min-width: 3rem !important;
            min-height: 1.5rem !important;
            cursor: pointer;
            transition: all 0.3s ease;
            transform: scale(1);
        }
        
        /* حالة التفعيل - أخضر */
        .evaluation-toggle:checked {
            background-color: #198754 !important;
            border-color: #198754 !important;
            box-shadow: 0 0 0 0.25rem rgba(25, 135, 84, 0.25) !important;
        }
        
        /* حالة التعطيل - أحمر */
        .evaluation-toggle:not(:checked) {
            background-color: #dc3545 !important;
            border-color: #dc3545 !important;
        }
        
        .evaluation-toggle:not(:checked):hover {
            box-shadow: 0 0 0 0.25rem rgba(220, 53, 69, 0.25);
        }
        
        .evaluation-toggle:checked:hover {
            background-color: #157347 !important;
        }
        
        /* تجاوز كل CSS من Bootstrap للزر */
        input[type="checkbox"].evaluation-toggle.form-check-input {
            width: 3rem !important;
            height: 1.5rem !important;
            min-width: 3rem !important;
            min-height: 1.5rem !important;
            flex-shrink: 0 !important;
        }
        
        .form-switch .evaluation-toggle {
            width: 3rem !important;
            height: 1.5rem !important;
        }
        
        /* Time inputs transition */
        #time-inputs-container {
            transition: opacity 0.3s ease, filter 0.3s ease;
        }
        
        #time-inputs-container:has(input:disabled) {
            filter: grayscale(0.5);
        }
    </style>
</head>
<body>
    <?php include '../includes/admin_header.php'; ?>

    <div class="container-fluid px-4 py-4">
        <!-- Page Header -->
        <div class="d-flex justify-content-between align-items-center mb-4">
            <div>
                <h2 class="mb-1">
                    <i class="fas fa-cog me-2 text-primary"></i>إعدادات نظام نقاط المكافئات
                </h2>
                <p class="text-muted mb-0">التحكم في إعدادات نظام نقاط المكافئات</p>
            </div>
        </div>

        <!-- Alerts -->
        <div class="row">
            <div class="col-12">
                <?php if (!empty($success_message)): ?>
                    <div class="alert alert-success alert-dismissible fade show shadow-sm" role="alert">
                        <div class="d-flex align-items-center">
                            <i class="fas fa-check-circle fa-2x me-3"></i>
                            <div>
                                <strong>تم بنجاح!</strong>
                                <p class="mb-0"><?php echo $success_message; ?></p>
                            </div>
                        </div>
                        <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                    </div>
                <?php endif; ?>

                <?php if (!empty($error_message)): ?>
                    <div class="alert alert-danger alert-dismissible fade show shadow-sm" role="alert">
                        <div class="d-flex align-items-center">
                            <i class="fas fa-exclamation-triangle fa-2x me-3"></i>
                            <div>
                                <strong>حدث خطأ!</strong>
                                <p class="mb-0"><?php echo $error_message; ?></p>
                            </div>
                        </div>
                        <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                    </div>
                <?php endif; ?>
            </div>
        </div>

        <!-- System Status Card - Fixed Display -->
        <?php 
        $is_enabled = isset($settings['evaluations_enabled']) && $settings['evaluations_enabled'] == '1';
        $evaluation_status = Utilities::areEvaluationsAllowed($db);
        
        // تحديد اللون بناءً على الحالة الكاملة
        if (!$is_enabled) {
            $status_color = 'danger'; // أحمر - النظام معطل
            $status_icon = 'fa-times-circle';
            $status_text = 'معطّل';
            $status_message = 'التقييمات متوقفة مؤقتاً';
        } elseif ($evaluation_status['allowed']) {
            $status_color = 'success'; // أخضر - كل شيء يعمل
            $status_icon = 'fa-check-circle';
            $status_text = 'مفعّل';
            $status_message = 'يمكن إضافة التقييمات الآن';
        } else {
            $status_color = 'warning'; // أصفر - مفعل لكن خارج الأوقات
            $status_icon = 'fa-exclamation-circle';
            $status_text = 'مفعّل (خارج الأوقات)';
            $status_message = htmlspecialchars($evaluation_status['message']);
        }
        ?>
        <div class="row mb-3">
            <div class="col-12">
                <div class="alert alert-<?php echo $status_color; ?> alert-dismissible border-0 shadow-sm mb-0 sticky-status-alert" role="status">
                    <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="إغلاق"></button>
                    <div class="d-flex align-items-center">
                        <i class="fas <?php echo $status_icon; ?> fa-2x me-3"></i>
                        <div class="flex-grow-1">
                            <h5 class="mb-1">
                                حالة نظام التقييمات: 
                                <span class="badge bg-<?php echo $status_color; ?> fs-6">
                                    <?php echo $status_text; ?>
                                </span>
                            </h5>
                            <p class="mb-0">
                                <?php echo $status_message; ?>
                            </p>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <!-- Settings Form -->
        <form method="POST" action="">
            <?php echo csrfField(); ?>
            <div class="row">
                <!-- تفعيل/تعطيل النظام -->
                <div class="col-12 mb-3">
                    <div class="card settings-card">
                        <div class="section-header">
                            <i class="fas fa-power-off me-2"></i>التحكم في تفعيل النظام
                        </div>
                        <div class="card-body">
                            <div class="d-flex align-items-center justify-content-between p-3 rounded" style="background: #f8f9fa;">
                                <div class="flex-grow-1">
                                    <h5 class="mb-2">
                                        <i class="fas fa-power-off me-2"></i>حالة نظام التقييمات
                                    </h5>
                                    <p class="mb-0 text-muted">
                                        <i class="fas fa-info-circle me-1"></i>
                                        عند التعطيل، لن يتمكن المعلمون والأخصائيون من إضافة تقييمات جديدة
                                    </p>
                                </div>
                                <div class="form-check form-switch ms-4">
                                    <input class="form-check-input evaluation-toggle" type="checkbox" role="switch" 
                                           id="evaluations_enabled" name="evaluations_enabled" 
                                           value="1" <?php echo $is_enabled ? 'checked' : ''; ?>>
                                    <label class="form-check-label me-2" for="evaluations_enabled"></label>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- الأيام المسموح بها -->
                <div class="col-12 mb-3">
                    <div class="card settings-card">
                        <div class="section-header">
                            <i class="fas fa-calendar-check me-2"></i>الأيام المسموح بها للتقييم
                        </div>
                        <div class="card-body">
                            <?php 
                            $all_days = [
                                'السبت' => ['icon' => 'fa-calendar-week', 'color' => '#8b5cf6'],
                                'الأحد' => ['icon' => 'fa-sun', 'color' => '#f59e0b'],
                                'الاثنين' => ['icon' => 'fa-moon', 'color' => '#3b82f6'],
                                'الثلاثاء' => ['icon' => 'fa-star', 'color' => '#ec4899'],
                                'الأربعاء' => ['icon' => 'fa-cloud-sun', 'color' => '#06b6d4'],
                                'الخميس' => ['icon' => 'fa-bolt', 'color' => '#10b981'],
                                'الجمعة' => ['icon' => 'fa-mosque', 'color' => '#ef4444']
                            ];
                            $selected_days = isset($settings['allowed_days']) ? explode(',', $settings['allowed_days']) : [];
                            $selected_days = array_map('trim', $selected_days);
                            ?>
                            <div class="days-selector-container">
                                <?php foreach ($all_days as $day => $info): ?>
                                    <div class="day-card-wrapper">
                                        <input type="checkbox" 
                                               class="day-input" 
                                               name="allowed_days[]" 
                                               value="<?php echo htmlspecialchars($day); ?>" 
                                               id="day_<?php echo htmlspecialchars($day); ?>"
                                               <?php echo in_array($day, $selected_days) ? 'checked' : ''; ?>>
                                        <label for="day_<?php echo htmlspecialchars($day); ?>" 
                                               class="day-card" 
                                               style="--day-color: <?php echo $info['color']; ?>">
                                            <div class="day-icon-wrapper">
                                                <i class="fas <?php echo $info['icon']; ?>"></i>
                                            </div>
                                            <div class="day-name"><?php echo htmlspecialchars($day); ?></div>
                                            <div class="check-indicator">
                                                <i class="fas fa-check-circle"></i>
                                            </div>
                                        </label>
                                    </div>
                                <?php endforeach; ?>
                            </div>
                            <div class="days-info-bar">
                                <div class="selected-count">
                                    <i class="fas fa-calendar-check me-2"></i>
                                    <span id="selected-days-count"><?php echo count($selected_days); ?></span> أيام محددة
                                </div>
                                <small class="text-muted">
                                    <i class="fas fa-info-circle me-1"></i>انقر على اليوم للتحديد/الإلغاء
                                </small>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- أوقات التقييم -->
                <div class="col-12 mb-3">
                    <div class="card settings-card">
                        <div class="section-header">
                            <i class="fas fa-clock me-2"></i>أوقات التقييم المسموح بها
                        </div>
                        <div class="card-body">
                            <!-- خيار الوقت المفتوح -->
                            <div class="d-flex align-items-center justify-content-between p-3 rounded mb-3" style="background: #f8f9fa;">
                                <div class="flex-grow-1">
                                    <h5 class="mb-2">
                                        <i class="fas fa-infinity me-2"></i>السماح بالتقييم في أي وقت
                                    </h5>
                                    <p class="mb-0 text-muted">
                                        <i class="fas fa-info-circle me-1"></i>
                                        عند التفعيل، سيتم السماح بالتقييمات طوال اليوم (24 ساعة) بناءً على الأيام المحددة فقط
                                    </p>
                                </div>
                                <div class="form-check form-switch ms-4">
                                    <input class="form-check-input" type="checkbox" role="switch" 
                                           id="unlimited_time" name="unlimited_time" value="1"
                                           style="width: 3rem; height: 1.5rem;"
                                           <?php echo (isset($settings['unlimited_time']) && $settings['unlimited_time'] == '1') ? 'checked' : ''; ?>
                                           onchange="toggleTimeInputs(this.checked)">
                                    <label class="form-check-label me-2" for="unlimited_time"></label>
                                </div>
                            </div>
                            
                            <div class="row g-3" id="time-inputs-container">
                                <div class="col-md-6">
                                    <div class="time-input-group">
                                        <label class="form-label fw-bold mb-2">
                                            <i class="fas fa-play-circle me-2 text-success"></i>بداية وقت التقييم
                                        </label>
                                        <input type="time" class="form-control time-input" 
                                               id="allowed_time_from" name="allowed_time_from" 
                                               value="<?php echo isset($settings['allowed_time_from']) ? htmlspecialchars($settings['allowed_time_from']) : '08:00'; ?>"
                                               <?php echo (isset($settings['unlimited_time']) && $settings['unlimited_time'] == '1') ? 'disabled' : ''; ?>>
                                        <small class="text-muted d-block mt-1">
                                            وقت بداية السماح بالتقييمات
                                        </small>
                                    </div>
                                </div>
                                <div class="col-md-6">
                                    <div class="time-input-group">
                                        <label class="form-label fw-bold mb-2">
                                            <i class="fas fa-stop-circle me-2 text-danger"></i>نهاية وقت التقييم
                                        </label>
                                        <input type="time" class="form-control time-input" 
                                               id="allowed_time_to" name="allowed_time_to" 
                                               value="<?php echo isset($settings['allowed_time_to']) ? htmlspecialchars($settings['allowed_time_to']) : '15:00'; ?>"
                                               <?php echo (isset($settings['unlimited_time']) && $settings['unlimited_time'] == '1') ? 'disabled' : ''; ?>>
                                        <small class="text-muted d-block mt-1">
                                            وقت نهاية السماح بالتقييمات
                                        </small>
                                    </div>
                                </div>
                            </div>
                            
                            <div class="alert alert-warning mt-3 mb-0 sticky-alert">
                                <i class="fas fa-exclamation-triangle me-2"></i>
                                <strong>ملاحظة:</strong> إذا كان وقت البداية أكبر من وقت النهاية (مثل: 08:00 → 07:00)، 
                                سيتم السماح بالتقييم من وقت البداية حتى منتصف الليل، ثم من منتصف الليل حتى وقت النهاية في اليوم التالي.
                            </div>
                        </div>
                    </div>
                </div>

                <!-- إعدادات حذف التقييم للمعلم -->
                <div class="col-12 mb-3">
                    <div class="card settings-card">
                        <div class="section-header">
                            <i class="fas fa-trash-alt me-2"></i>إعدادات حذف التقييمات للمعلم
                        </div>
                        <div class="card-body">
                            <!-- خيار السماح بالحذف -->
                            <div class="d-flex align-items-center justify-content-between p-3 rounded mb-3" style="background: #f8f9fa;">
                                <div class="flex-grow-1">
                                    <h5 class="mb-2">
                                        <i class="fas fa-user-edit me-2"></i>السماح للمعلم بحذف تقييماته
                                    </h5>
                                    <p class="mb-0 text-muted">
                                        <i class="fas fa-info-circle me-1"></i>
                                        عند التفعيل، سيتمكن المعلم من حذف التقييمات التي قام بإضافتها خلال المدة الزمنية المحددة بالأسفل
                                    </p>
                                </div>
                                <div class="form-check form-switch ms-4">
                                    <input class="form-check-input" type="checkbox" role="switch" 
                                           id="teacher_delete_limit_enabled" name="teacher_delete_limit_enabled" value="1"
                                           style="width: 3rem; height: 1.5rem;"
                                           <?php echo (isset($settings['teacher_delete_limit_enabled']) && $settings['teacher_delete_limit_enabled'] == '1') ? 'checked' : ''; ?>
                                           onchange="toggleDeleteMinutes(this.checked)">
                                    <label class="form-check-label me-2" for="teacher_delete_limit_enabled"></label>
                                </div>
                            </div>
                            
                            <div class="row g-3" id="delete-minutes-container">
                                <div class="col-md-6">
                                    <div class="time-input-group h-100">
                                        <label class="form-label fw-bold mb-2">
                                            <i class="fas fa-hourglass-half me-2 text-warning"></i>المدة المسموح بها للحذف (بالدقائق)
                                        </label>
                                        <div class="input-group" style="max-width: 200px;">
                                            <input type="number" class="form-control" 
                                                   id="teacher_delete_limit_minutes" name="teacher_delete_limit_minutes" 
                                                   value="<?php echo isset($settings['teacher_delete_limit_minutes']) ? htmlspecialchars($settings['teacher_delete_limit_minutes']) : '180'; ?>"
                                                   min="1" step="1"
                                                   <?php echo (!isset($settings['teacher_delete_limit_enabled']) || $settings['teacher_delete_limit_enabled'] == '0') ? 'disabled' : ''; ?>>
                                            <span class="input-group-text">دقيقة</span>
                                        </div>
                                        <small class="text-muted d-block mt-2">
                                            <i class="fas fa-clock me-1"></i>
                                            60 = ساعة، 180 = 3 ساعات
                                        </small>
                                    </div>
                                </div>
                                <div class="col-md-6">
                                    <div class="time-input-group h-100">
                                        <label class="form-label fw-bold mb-3">
                                            <i class="fas fa-history me-2 text-info"></i>نطاق تطبيق الحذف
                                        </label>
                                        <?php 
                                        $delete_retro = isset($settings['teacher_delete_retroactive']) ? $settings['teacher_delete_retroactive'] : '1';
                                        ?>
                                        <div class="d-flex flex-column gap-2">
                                            <div class="form-check">
                                                <input class="form-check-input" type="radio" name="teacher_delete_retroactive" id="delete_retro_yes" value="1" <?php echo ($delete_retro == '1') ? 'checked' : ''; ?> <?php echo (!isset($settings['teacher_delete_limit_enabled']) || $settings['teacher_delete_limit_enabled'] == '0') ? 'disabled' : ''; ?>>
                                                <label class="form-check-label" for="delete_retro_yes">
                                                    تطبيق بأثر رجعي (أي تقييم ضمن المهلة)
                                                </label>
                                            </div>
                                            <div class="form-check">
                                                <input class="form-check-input" type="radio" name="teacher_delete_retroactive" id="delete_retro_no" value="0" <?php echo ($delete_retro == '0') ? 'checked' : ''; ?> <?php echo (!isset($settings['teacher_delete_limit_enabled']) || $settings['teacher_delete_limit_enabled'] == '0') ? 'disabled' : ''; ?>>
                                                <label class="form-check-label" for="delete_retro_no">
                                                    تطبيق على التقييمات الجديدة فقط (بعد التفعيل)
                                                </label>
                                            </div>
                                            <?php if ($delete_retro == '0' && isset($settings['teacher_delete_enabled_at'])): ?>
                                                <small class="text-primary mt-1">
                                                    <i class="fas fa-calendar-alt me-1"></i>
                                                    تاريخ التفعيل الحالي: <?php echo date('Y-m-d H:i', strtotime($settings['teacher_delete_enabled_at'])); ?>
                                                </small>
                                            <?php endif; ?>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- أزرار الحفظ -->
                <div class="col-12">
                    <div class="card settings-card">
                        <div class="card-body">
                            <div class="d-flex justify-content-between align-items-center">
                                <small class="text-muted">
                                    <i class="fas fa-info-circle me-1"></i>سيتم تطبيق التغييرات فوراً
                                </small>
                                <div class="d-flex gap-2">
                                    <a href="index.php" class="btn btn-outline-secondary px-4">
                                        <i class="fas fa-times me-2"></i>إلغاء
                                    </a>
                                    <button type="submit" name="update_settings" class="btn btn-primary px-4">
                                        <i class="fas fa-save me-2"></i>حفظ الإعدادات
                                    </button>
                                </div>
                            </div>
                        </div>
                        </div>
                    </div>
                </div>
            </div>
        </form>
    </div>

    <?php include '../includes/admin_footer.php'; ?>

    <script>
        // Toggle time inputs based on unlimited_time checkbox
        function toggleTimeInputs(isUnlimited) {
            const timeInputs = document.querySelectorAll('.time-input');
            const timeContainer = document.getElementById('time-inputs-container');
            
            timeInputs.forEach(input => {
                input.disabled = isUnlimited;
            });
            
            if (isUnlimited) {
                timeContainer.style.opacity = '0.5';
                timeContainer.style.pointerEvents = 'none';
            } else {
                timeContainer.style.opacity = '1';
                timeContainer.style.pointerEvents = 'auto';
            }
        }
        
        // Toggle delete minutes input based on checkbox
        function toggleDeleteMinutes(isEnabled) {
            const minutesInput = document.getElementById('teacher_delete_limit_minutes');
            const radioYes = document.getElementById('delete_retro_yes');
            const radioNo = document.getElementById('delete_retro_no');
            const container = document.getElementById('delete-minutes-container');
            
            minutesInput.disabled = !isEnabled;
            radioYes.disabled = !isEnabled;
            radioNo.disabled = !isEnabled;
            
            if (isEnabled) {
                container.style.opacity = '1';
                container.style.pointerEvents = 'auto';
            } else {
                container.style.opacity = '0.5';
                container.style.pointerEvents = 'none';
            }
        }

        // Initialize on page load
        document.addEventListener('DOMContentLoaded', function() {
            const unlimitedCheckbox = document.getElementById('unlimited_time');
            if (unlimitedCheckbox) {
                toggleTimeInputs(unlimitedCheckbox.checked);
            }
            
            const deleteLimitCheckbox = document.getElementById('teacher_delete_limit_enabled');
            if (deleteLimitCheckbox) {
                toggleDeleteMinutes(deleteLimitCheckbox.checked);
            }

        });
        
        // إخفاء رسائل النجاح والخطأ فقط بعد 5 ثوان
        setTimeout(function() {
            const alerts = document.querySelectorAll('.alert:not(.sticky-status-alert)');
            alerts.forEach(alert => {
                if (alert.querySelector('.btn-close')) {
                    const bsAlert = new bootstrap.Alert(alert);
                    bsAlert.close();
                }
            });
        }, 5000);

        // Update status text when toggle changes
        document.getElementById('evaluations_enabled').addEventListener('change', function() {
            const statusText = document.getElementById('status_text');
            const card = this.closest('.card');
            const cardHeader = card ? card.querySelector('.card-header') : null;
            const statusDiv = card ? card.querySelector('.rounded') : null;

            // Defensive guard: the inline status markup has been redesigned and these
            // elements may be absent. Bail out early to avoid throwing on every toggle.
            if (!statusText || !card || !cardHeader || !statusDiv) {
                return;
            }

            if (this.checked) {
                statusText.textContent = 'النظام مفعّل';
                card.classList.remove('border-danger');
                card.classList.add('border-success');
                cardHeader.classList.remove('bg-danger');
                cardHeader.classList.add('bg-success');
                statusDiv.style.backgroundColor = '#d1fae5';
                statusDiv.querySelector('strong').classList.remove('text-danger');
                statusDiv.querySelector('strong').classList.add('text-success');
                statusDiv.querySelector('i').classList.remove('fa-times');
                statusDiv.querySelector('i').classList.add('fa-check');
            } else {
                statusText.textContent = 'النظام معطّل';
                card.classList.remove('border-success');
                card.classList.add('border-danger');
                cardHeader.classList.remove('bg-success');
                cardHeader.classList.add('bg-danger');
                statusDiv.style.backgroundColor = '#fee2e2';
                statusDiv.querySelector('strong').classList.remove('text-success');
                statusDiv.querySelector('strong').classList.add('text-danger');
                statusDiv.querySelector('i').classList.remove('fa-check');
                statusDiv.querySelector('i').classList.add('fa-times');
            }
        });

        // Update selected days counter
        function updateDaysCounter() {
            const checkedDays = document.querySelectorAll('input[name="allowed_days[]"]:checked').length;
            const counterElement = document.getElementById('selected-days-count');
            if (counterElement) {
                counterElement.textContent = checkedDays;
                
                // Add animation
                counterElement.style.transform = 'scale(1.3)';
                setTimeout(() => {
                    counterElement.style.transform = 'scale(1)';
                }, 200);
            }
        }
        
        // Listen to day selection changes
        document.querySelectorAll('input[name="allowed_days[]"]').forEach(function(checkbox) {
            checkbox.addEventListener('change', function() {
                updateDaysCounter();
            });
        });

        // Confirmation before submitting
        document.querySelector('form').addEventListener('submit', async function(e) {
            if (this.dataset.confirmApproved === 'true') return;
            const isEnabled = document.getElementById('evaluations_enabled').checked;
            
            if (!isEnabled) {
                e.preventDefault();
                const approved = await window.adminConfirm('تحذير: أنت على وشك تعطيل نظام التقييمات. لن يتمكن المعلمون والأخصائيون من إضافة تقييمات جديدة حتى يتم تفعيل النظام مرة أخرى.');
                if (!approved) return;
                this.dataset.confirmApproved = 'true';
                this.requestSubmit(e.submitter || null);
            }
        });
    </script>
</body>
</html>
