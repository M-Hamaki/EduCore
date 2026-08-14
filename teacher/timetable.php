<?php
/**
 * عرض الجدول المدرسي للمعلم - Teacher Timetable View
 */
require_once '../includes/session_config.php';
require_once '../classes/utilities.php';
Utilities::validateSession('teacher');
require_once '../config/database.php';

$database = new Database();
$db = $database->getConnection();

$teacher_id = $_SESSION['user_id'];

$day_names = [
    1 => 'الأحد',
    2 => 'الاثنين',
    3 => 'الثلاثاء',
    4 => 'الأربعاء',
    5 => 'الخميس'
];

// Day colors for visual distinction
$day_colors = [
    1 => '#e3f2fd',
    2 => '#e8f5e9',
    3 => '#fff3e0',
    4 => '#f3e5f5',
    5 => '#fce4ec'
];

// Get all periods
$periods = $db->query("SELECT * FROM timetable_periods ORDER BY sort_order")->fetchAll(PDO::FETCH_ASSOC);

// Get teacher's timetable entries
$stmt = $db->prepare("
    SELECT te.*, tp.period_name, tp.start_time, tp.end_time, tp.is_break, tp.sort_order,
           s.name as subject_name, c.name as class_name, g.grade_name
    FROM timetable_entries te
    JOIN timetable_periods tp ON te.period_id = tp.id
    LEFT JOIN subjects s ON te.subject_id = s.id
    LEFT JOIN classes c ON te.class_id = c.id
    LEFT JOIN grades g ON c.grade_id = g.id
    WHERE te.teacher_id = ?
    ORDER BY te.day_of_week, tp.sort_order
");
$stmt->execute([$teacher_id]);
$all_entries = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Organize entries: [period_id][day_of_week] = entry
$entries = [];
foreach ($all_entries as $e) {
    $entries[$e['period_id']][$e['day_of_week']] = $e;
}

// Calculate stats
$total_lessons = count($all_entries);
$unique_classes = count(array_unique(array_column($all_entries, 'class_id')));
$unique_subjects = count(array_unique(array_filter(array_column($all_entries, 'subject_id'))));

// Today's schedule
$today_num = date('w'); // 0=Sun
// Map PHP day to our numbering: Sun=1, Mon=2, Tue=3, Wed=4, Thu=5
$today_mapped = $today_num == 0 ? 1 : $today_num + 1;
if ($today_mapped > 5) $today_mapped = null; // Friday/Saturday
$today_entries = array_filter($all_entries, fn($e) => $e['day_of_week'] == $today_mapped);
?>
<!DOCTYPE html>
<html lang="ar" dir="rtl">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>الجدول المدرسي - نظام الإدارة المدرسية</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.rtl.min.css" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css" rel="stylesheet">
    <link href="https://fonts.googleapis.com/css2?family=Tajawal:wght@400;500;700&display=swap" rel="stylesheet">
    <link href="../assets/css/premium-dashboard.css" rel="stylesheet">
</head>
<body style="font-family: 'Tajawal', sans-serif; background: #f0f2f5; min-height: 100vh;">

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

    .timetable-cell {
        min-width: 130px;
        padding: 4px !important;
        font-size: 0.85rem;
        vertical-align: middle;
    }
    .timetable-cell .subject { font-weight: 600; color: #0d6efd; }
    .timetable-cell .class-name { font-size: 0.75rem; color: #6c757d; }
    .timetable-cell .room { font-size: 0.7rem; color: #17a2b8; }
    .today-badge { animation: pulse 2s infinite; }
    @keyframes pulse { 0%,100% { opacity: 1; } 50% { opacity: 0.6; } }
    @media print {
        .no-print { display: none !important; }
        .card { border: 1px solid #ddd !important; }
    }
    /* Mobile responsive timetable */
    @media (max-width: 768px) {
        .container-fluid { padding-left: 8px; padding-right: 8px; }
        .container-fluid h2 { font-size: 1.2rem; }
        .timetable-cell { min-width: 90px !important; font-size: 0.75rem; padding: 3px !important; }
        .timetable-cell .subject { font-size: 0.75rem; }
        .timetable-cell .class-name { font-size: 0.65rem; }
        .table-bordered th { font-size: 0.75rem; padding: 6px 3px; }
        .table-bordered td { padding: 4px 2px; }
        /* Stats - 2 columns */
        .col-md-3 { flex: 0 0 50%; max-width: 50%; }
    }
</style>

<div class="timetable-page mt-4">
    <!-- Header -->
    <div class="d-flex justify-content-between align-items-center mb-4 no-print">
        <div>
            <h2 class="mb-1"><i class="fas fa-calendar-alt me-2 text-primary"></i>الجدول المدرسي</h2>
            <p class="text-muted mb-0">جدول الحصص الأسبوعي</p>
        </div>
        <div class="d-flex gap-2">
            <button class="btn btn-primary btn-sm" onclick="window.print()">
                <i class="fas fa-print me-1"></i>طباعة
            </button>
            <a href="portal.php" class="portal-back-btn">
                <i class="fas fa-arrow-right me-1"></i>العودة للبوابة
            </a>
        </div>
    </div>

    <!-- Stats Cards -->
    <div class="row g-3 mb-4 no-print">
        <div class="col-6 col-md-3">
            <div class="stat-card" style="--card-gradient: var(--primary-gradient);">
                <div class="stat-card-icon"><i class="fas fa-calendar-alt"></i></div>
                <div class="stat-card-info">
                    <div class="stat-card-number"><?php echo $total_lessons; ?></div>
                    <div class="stat-card-label">حصة أسبوعياً</div>
                </div>
            </div>
        </div>
        <div class="col-6 col-md-3">
            <div class="stat-card" style="--card-gradient: var(--success-gradient);">
                <div class="stat-card-icon"><i class="fas fa-school"></i></div>
                <div class="stat-card-info">
                    <div class="stat-card-number"><?php echo $unique_classes; ?></div>
                    <div class="stat-card-label">فصل</div>
                </div>
            </div>
        </div>
        <div class="col-6 col-md-3">
            <div class="stat-card" style="--card-gradient: var(--warning-gradient);">
                <div class="stat-card-icon"><i class="fas fa-book"></i></div>
                <div class="stat-card-info">
                    <div class="stat-card-number"><?php echo $unique_subjects; ?></div>
                    <div class="stat-card-label">مادة</div>
                </div>
            </div>
        </div>
        <div class="col-6 col-md-3">
            <div class="stat-card" style="--card-gradient: var(--info-gradient);">
                <div class="stat-card-icon"><i class="fas fa-clock"></i></div>
                <div class="stat-card-info">
                    <div class="stat-card-number"><?php echo count($today_entries); ?></div>
                    <div class="stat-card-label">حصص اليوم</div>
                </div>
            </div>
        </div>
    </div>

    <?php if (empty($all_entries)): ?>
        <div class="alert alert-info text-center">
            <i class="fas fa-info-circle me-2"></i>لم يتم تعيين جدول لك بعد. يرجى مراجعة الإدارة.
        </div>
    <?php else: ?>

    <!-- Today's Schedule (if applicable) -->
    <?php if ($today_mapped && !empty($today_entries)): ?>
    <div class="card shadow-sm mb-4 border-start border-4 border-primary">
        <div class="card-header bg-white">
            <h5 class="mb-0">
                <i class="fas fa-sun me-2 text-warning"></i>جدول اليوم 
                <span class="badge bg-primary today-badge"><?php echo $day_names[$today_mapped] ?? ''; ?></span>
            </h5>
        </div>
        <div class="card-body p-0">
            <div class="table-responsive">
                <table class="table table-sm mb-0">
                    <thead class="table-light">
                        <tr><th>الحصة</th><th>الوقت</th><th>المادة</th><th>الفصل</th><th>القاعة</th></tr>
                    </thead>
                    <tbody>
                        <?php foreach ($today_entries as $te): ?>
                        <tr>
                            <td class="fw-bold"><?php echo htmlspecialchars($te['period_name']); ?></td>
                            <td class="small text-muted"><?php echo substr($te['start_time'],0,5) . ' - ' . substr($te['end_time'],0,5); ?></td>
                            <td class="text-primary fw-bold"><?php echo htmlspecialchars($te['subject_name'] ?? '---'); ?></td>
                            <td><?php echo htmlspecialchars(($te['grade_name'] ? $te['grade_name'] . ' - ' : '') . $te['class_name']); ?></td>
                            <td class="small text-info"><?php echo htmlspecialchars($te['room'] ?? '-'); ?></td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
    <?php endif; ?>

    <!-- Full Weekly Timetable -->
    <div class="card shadow-sm">
        <div class="card-header bg-primary text-white">
            <h5 class="mb-0"><i class="fas fa-th me-2"></i>الجدول الأسبوعي الكامل</h5>
        </div>
        <div class="card-body p-0">
            <div class="table-responsive">
                <table class="table table-bordered mb-0">
                    <thead>
                        <tr class="table-primary text-center">
                            <th style="width: 120px;">الحصة / الوقت</th>
                            <?php foreach ($day_names as $dn => $name): ?>
                                <th class="<?php echo $dn == $today_mapped ? 'bg-warning bg-opacity-25' : ''; ?>">
                                    <?php echo $name; ?>
                                    <?php if ($dn == $today_mapped): ?>
                                        <br><small class="badge bg-warning text-dark">اليوم</small>
                                    <?php endif; ?>
                                </th>
                            <?php endforeach; ?>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($periods as $period): ?>
                        <tr class="<?php echo $period['is_break'] ? 'table-warning' : ''; ?>">
                            <td class="text-center small fw-bold">
                                <?php echo htmlspecialchars($period['period_name']); ?>
                                <br><small class="text-muted"><?php echo substr($period['start_time'],0,5) . ' - ' . substr($period['end_time'],0,5); ?></small>
                            </td>
                            <?php foreach ($day_names as $day_num => $dn):
                                $entry = $entries[$period['id']][$day_num] ?? null;
                            ?>
                            <td class="text-center timetable-cell" style="<?php echo $day_num == $today_mapped ? 'background-color: #fff9c4;' : ''; ?>">
                                <?php if ($period['is_break']): ?>
                                    <span class="text-warning"><i class="fas fa-coffee"></i></span>
                                <?php elseif ($entry): ?>
                                    <div class="subject"><?php echo htmlspecialchars($entry['subject_name'] ?? '---'); ?></div>
                                    <div class="class-name"><?php echo htmlspecialchars(($entry['grade_name'] ? $entry['grade_name'] . ' - ' : '') . $entry['class_name']); ?></div>
                                    <?php if ($entry['room']): ?>
                                        <div class="room"><i class="fas fa-door-open"></i> <?php echo htmlspecialchars($entry['room']); ?></div>
                                    <?php endif; ?>
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
    </div>
    <?php endif; ?>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>
