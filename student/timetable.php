<?php
/**
 * عرض الجدول المدرسي للطالب - Student Timetable View
 */
require_once '../includes/session_config.php';
require_once '../classes/utilities.php';
require_once '../config/database.php';

if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'student') {
    header('Location: ../index.php');
    exit;
}

$database = new Database();
$db = $database->getConnection();

$student_id = $_SESSION['user_id'];
$student_name = $_SESSION['name'] ?? 'الطالب';

// Get student's class
$stmt = $db->prepare("SELECT class_id FROM users WHERE id = ?");
$stmt->execute([$student_id]);
$class_id = $stmt->fetchColumn();

if (!$class_id) {
    echo '<div style="text-align:center;padding:50px;font-family:Tajawal,sans-serif;"><h2>لم يتم تعيين فصل لك بعد.</h2><a href="portal.php">العودة</a></div>';
    exit;
}

// Get class info
$stmt = $db->prepare("SELECT c.name as class_name, c.timetable_image, g.grade_name FROM classes c LEFT JOIN grades g ON c.grade_id = g.id WHERE c.id = ?");
$stmt->execute([$class_id]);
$class_info = $stmt->fetch(PDO::FETCH_ASSOC);
$timetable_image = $class_info['timetable_image'] ?? '';

$day_names = [
    1 => 'الأحد',
    2 => 'الاثنين',
    3 => 'الثلاثاء',
    4 => 'الأربعاء',
    5 => 'الخميس'
];

// Get periods
$periods = $db->query("SELECT * FROM timetable_periods ORDER BY sort_order")->fetchAll(PDO::FETCH_ASSOC);

// Get timetable entries for this class
$stmt = $db->prepare("
    SELECT te.*, tp.period_name, tp.start_time, tp.end_time, tp.is_break, tp.sort_order,
           s.name as subject_name, u.name as teacher_name
    FROM timetable_entries te
    JOIN timetable_periods tp ON te.period_id = tp.id
    LEFT JOIN subjects s ON te.subject_id = s.id
    LEFT JOIN users u ON te.teacher_id = u.id
    WHERE te.class_id = ?
    ORDER BY te.day_of_week, tp.sort_order
");
$stmt->execute([$class_id]);
$all_entries = $stmt->fetchAll(PDO::FETCH_ASSOC);

$entries = [];
foreach ($all_entries as $e) {
    $entries[$e['period_id']][$e['day_of_week']] = $e;
}

// Today's day
$today_num = date('w');
$today_mapped = $today_num == 0 ? 1 : $today_num + 1;
if ($today_mapped > 5)
    $today_mapped = null;
$today_entries = array_filter($all_entries, fn($e) => $e['day_of_week'] == $today_mapped);

// Get dark mode setting
$dark_mode = false;
try {
    $setting_stmt = $db->query("SELECT setting_value FROM system_settings WHERE setting_key = 'dark_mode'");
    if ($setting_stmt) {
        $dm_val = $setting_stmt->fetchColumn();
        $dark_mode = ($dm_val === '1' || $dm_val === 'true');
    }
} catch (Exception $e) {
}
?>
<!DOCTYPE html>
<html lang="ar" dir="rtl">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>الجدول المدرسي - EduCore</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.rtl.min.css" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css" rel="stylesheet">
    <link href="https://fonts.googleapis.com/css2?family=Tajawal:wght@400;500;700;800&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="styles.css?v=<?php echo time(); ?>">
    <style>
        /* === Portal-style background === */
        body {
            font-family: 'Tajawal', sans-serif;
            padding-top: 0 !important;
            background: #ffffff !important;
            min-height: 100vh;
            margin: 0;
        }

        html {
            background: #ffffff !important;
        }

        body.dark-mode {
            background: #1a1a2e !important;
        }

        html.dark-mode {
            background: #1a1a2e !important;
        }

        /* Particles */
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

        /* Logo Section */
        .timetable-logo-section {
            text-align: center;
            padding: 2rem 1rem 0.5rem;
            background: transparent !important;
        }

        .timetable-logo-section a {
            display: inline-block;
            transition: transform 0.3s ease;
        }

        .timetable-logo-section a:hover {
            transform: scale(1.05);
        }

        .timetable-school-logo {
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

        /* Title Section */
        .timetable-title-section {
            text-align: center;
            padding: 0 1rem 2rem;
            background: transparent !important;
        }

        .timetable-main-title {
            font-size: 2.5rem;
            font-weight: 800;
            color: #1e293b;
            text-shadow: 0 2px 4px rgba(0, 0, 0, 0.1);
            margin: 0 0 0.5rem 0;
            letter-spacing: 1px;
        }

        .timetable-subtitle {
            font-size: 1.3rem;
            color: #334155;
            font-weight: 500;
            margin: 0;
        }

        body.dark-mode .timetable-main-title {
            color: #f1f5f9;
            text-shadow: 0 2px 8px rgba(0, 0, 0, 0.3);
        }

        body.dark-mode .timetable-subtitle {
            color: #cbd5e1;
        }

        /* Main Container */
        .timetable-main-container {
            max-width: 1200px;
            margin: 0 auto;
            padding: 0 1rem 3rem;
            background: transparent !important;
        }

        /* Card Style - same as rewards */
        .timetable-card {
            background: white;
            border-radius: 20px;
            padding: 2rem;
            margin-bottom: 2rem;
            box-shadow: 0 10px 30px rgba(0, 0, 0, 0.15), 0 4px 12px rgba(0, 0, 0, 0.1);
            transition: all 0.3s ease;
            border: 2px solid rgba(255, 255, 255, 0.3);
        }

        .timetable-card:hover {
            transform: translateY(-3px);
            box-shadow: 0 15px 40px rgba(0, 0, 0, 0.2), 0 6px 15px rgba(0, 0, 0, 0.15);
        }

        body.dark-mode .timetable-card {
            background: #1e293b !important;
            color: #f1f5f9 !important;
            border: 1px solid #334155 !important;
            box-shadow: 0 10px 30px rgba(0, 0, 0, 0.5), 0 4px 12px rgba(0, 0, 0, 0.3) !important;
        }

        /* Back Button - same as rewards */
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

        body.dark-mode .back-to-portal-btn {
            background: #334155;
            color: #818cf8;
            border-color: #818cf8;
        }

        body.dark-mode .back-to-portal-btn:hover {
            background: #818cf8;
            color: #1e293b;
        }

        /* Today card */
        .today-card {
            border-right: 5px solid #ffc107;
            background: linear-gradient(to left, rgba(255, 193, 7, 0.05), transparent);
        }

        /* Timetable table */
        table.timetable {
            font-size: 0.85rem;
        }

        table.timetable th {
            font-weight: 700;
            white-space: nowrap;
        }

        table.timetable td {
            vertical-align: middle;
            min-width: 120px;
        }

        .subject-cell {
            font-weight: 700;
            color: #667eea;
            font-size: 0.9rem;
        }

        .teacher-cell {
            font-size: 0.75rem;
            color: #6c757d;
        }

        .break-row {
            background-color: #fff8e1 !important;
        }

        .today-col {
            background-color: rgba(255, 193, 7, 0.1) !important;
        }

        /* Table responsive */
        .table-responsive {
            border-radius: 10px;
            overflow-x: auto;
            -webkit-overflow-scrolling: touch;
        }

        /* Dark mode table */
        body.dark-mode .table {
            color: #e2e8f0;
            background: #1e293b !important;
        }

        body.dark-mode .table thead {
            background: #0f172a !important;
        }

        body.dark-mode .table thead th {
            color: #e2e8f0 !important;
            background: #0f172a !important;
        }

        body.dark-mode .table-primary {
            background-color: #1e3a5f !important;
        }

        body.dark-mode .subject-cell {
            color: #818cf8;
        }

        body.dark-mode .teacher-cell {
            color: #94a3b8;
        }

        body.dark-mode .break-row {
            background-color: #3d3100 !important;
        }

        body.dark-mode .today-col {
            background-color: rgba(255, 193, 7, 0.08) !important;
        }

        body.dark-mode .table-bordered>:not(caption)>*>* {
            border-color: #334155;
        }

        body.dark-mode .card-header {
            background: #0f172a !important;
            color: #e2e8f0 !important;
        }

        /* Section header */
        .section-header h5 {
            font-weight: 700;
            color: #1e293b;
            margin-bottom: 1rem;
        }

        body.dark-mode .section-header h5 {
            color: #f1f5f9;
        }

        /* Print */
        @media print {
            body {
                background: white !important;
            }

            .no-print {
                display: none !important;
            }

            .timetable-main-container {
                padding: 0;
                max-width: 100%;
            }

            .timetable-card {
                box-shadow: none !important;
            }

            #particles-js {
                display: none !important;
            }
        }

        /* Mobile */
        @media (max-width: 768px) {
            .timetable-main-title {
                font-size: 1.5rem;
            }

            .timetable-subtitle {
                font-size: 1rem;
            }

            .timetable-school-logo {
                max-width: 120px;
            }

            table.timetable {
                font-size: 0.75rem;
            }

            table.timetable td {
                min-width: 85px;
            }
        }
    </style>
</head>

<body class="<?php echo $dark_mode ? 'dark-mode' : ''; ?>">

    <!-- Particles Background -->
    <div id="particles-js"></div>

    <!-- School Logo -->
    <div class="timetable-logo-section no-print" style="position: relative; z-index: 2;">
        <a href="portal.php" title="العودة للبوابة الرئيسية">
            <img src="../assets/img/logo.png" alt="School Logo" class="timetable-school-logo">
        </a>
    </div>

    <!-- Page Title -->
    <div class="timetable-title-section no-print" style="position: relative; z-index: 2;">
        <h1 class="timetable-main-title">
            <i class="fas fa-calendar-alt" style="color: #667eea;"></i>
            الجدول المدرسي
        </h1>
        <p class="timetable-subtitle"><?php echo htmlspecialchars($student_name); ?> -
            <?php echo htmlspecialchars(($class_info['grade_name'] ? $class_info['grade_name'] . ' - ' : '') . $class_info['class_name']); ?>
        </p>
    </div>

    <!-- Main Content Container -->
    <div class="timetable-main-container" style="position: relative; z-index: 2;">

        <!-- Back Button -->
        <div class="text-center mb-4 no-print">
            <a href="portal.php" class="back-to-portal-btn">
                <i class="fas fa-home"></i>
                العودة للبوابة الرئيسية
            </a>
        </div>

        <?php if ($timetable_image && file_exists('../' . $timetable_image)): ?>
            <!-- Image Timetable -->
            <div class="timetable-card text-center">
                <div class="section-header text-start mb-3">
                    <h5>
                        <i class="fas fa-image text-primary me-2"></i>صورة الجدول المدرسي
                    </h5>
                </div>
                <div class="mb-3 bg-light p-2 rounded d-inline-block shadow-sm" style="max-width: 100%;">
                    <img src="../<?php echo htmlspecialchars($timetable_image); ?>?v=<?php echo time(); ?>"
                        class="img-fluid rounded" style="max-height: 700px; border: 3px solid #fff;" alt="جدول الفصل">
                </div>
                <div class="mt-3">
                    <a href="../<?php echo htmlspecialchars($timetable_image); ?>" target="_blank"
                        class="btn btn-primary px-4 py-2" style="border-radius: 50px;">
                        <i class="fas fa-external-link-alt me-1"></i>عرض بالحجم الكامل
                    </a>
                    <a href="../<?php echo htmlspecialchars($timetable_image); ?>"
                        download="timetable_<?php echo htmlspecialchars($class_info['class_name']); ?>"
                        class="btn btn-success px-4 py-2 ms-2" style="border-radius: 50px;">
                        <i class="fas fa-download me-1"></i>تحميل الجدول
                    </a>
                </div>
            </div>
        <?php elseif (!empty($all_entries)): ?>
            <!-- Today's Schedule -->
            <?php if ($today_mapped && !empty($today_entries)): ?>
                <div class="timetable-card today-card">
                    <div class="section-header">
                        <h5>
                            <i class="fas fa-sun text-warning me-2"></i>جدول اليوم
                            <span class="badge bg-warning text-dark"><?php echo $day_names[$today_mapped]; ?></span>
                        </h5>
                    </div>
                    <div class="row g-2">
                        <?php foreach ($today_entries as $te): ?>
                            <div class="col-6 col-md-4 col-lg-3">
                                <div class="border rounded p-2 text-center h-100" style="background: rgba(102,126,234,0.03);">
                                    <div class="small text-muted"><?php echo htmlspecialchars($te['period_name']); ?></div>
                                    <div class="subject-cell"><?php echo htmlspecialchars($te['subject_name'] ?? '---'); ?></div>
                                    <div class="teacher-cell"><?php echo htmlspecialchars($te['teacher_name'] ?? ''); ?></div>
                                    <div class="small text-muted"><?php echo substr($te['start_time'], 0, 5); ?></div>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    </div>
                </div>
            <?php endif; ?>

            <!-- Full Weekly Grid -->
            <div class="timetable-card" style="padding: 0; overflow: hidden;">
                <div class="table-responsive">
                    <table class="table table-bordered timetable mb-0">
                        <thead>
                            <tr class="table-primary text-center">
                                <th style="width: 100px;">الحصة</th>
                                <?php foreach ($day_names as $dn => $name): ?>
                                    <th class="<?php echo $dn == $today_mapped ? 'today-col' : ''; ?>">
                                        <?php echo $name; ?>
                                        <?php if ($dn == $today_mapped): ?>
                                            <br><span class="badge bg-warning text-dark" style="font-size:0.65rem;">اليوم</span>
                                        <?php endif; ?>
                                    </th>
                                <?php endforeach; ?>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($periods as $period): ?>
                                <tr class="<?php echo $period['is_break'] ? 'break-row' : ''; ?> text-center">
                                    <td class="fw-bold small">
                                        <?php echo htmlspecialchars($period['period_name']); ?>
                                        <br><small class="text-muted"><?php echo substr($period['start_time'], 0, 5); ?></small>
                                    </td>
                                    <?php foreach ($day_names as $day_num => $dn):
                                        $entry = $entries[$period['id']][$day_num] ?? null;
                                        ?>
                                        <td class="<?php echo $day_num == $today_mapped ? 'today-col' : ''; ?>">
                                            <?php if ($period['is_break']): ?>
                                                <span class="text-warning"><i class="fas fa-coffee"></i></span>
                                            <?php elseif ($entry): ?>
                                                <div class="subject-cell">
                                                    <?php echo htmlspecialchars($entry['subject_name'] ?? '---'); ?></div>
                                                <div class="teacher-cell"><?php echo htmlspecialchars($entry['teacher_name'] ?? ''); ?>
                                                </div>
                                            <?php else: ?>
                                                <span class="text-muted">-</span>
                                            <?php endif; ?>
                                        </td>
                                    <?php endforeach; ?>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        <?php else: ?>
            <div class="timetable-card text-center">
                <i class="fas fa-calendar-times fa-3x text-muted mb-3"></i>
                <h4>لم يتم إعداد الجدول بعد</h4>
                <p class="text-muted">يرجى مراجعة الإدارة للحصول على الجدول المدرسي.</p>
            </div>
        <?php endif; ?>
    </div>

    <!-- Scripts -->
    <script src="https://code.jquery.com/jquery-3.7.1.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
    <script src="https://cdn.jsdelivr.net/particles.js/2.0.0/particles.min.js"></script>
    <script src="script.js?v=<?php echo time(); ?>"></script>
</body>

</html>