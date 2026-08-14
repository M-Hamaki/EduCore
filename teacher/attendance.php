<?php
/**
 * نظام الحضور والغياب - Teacher Attendance Recording
 */
ini_set('display_errors', 0);
ini_set('display_startup_errors', 0);
error_reporting(0);

$page_title = "الحضور والغياب";

require_once '../config/database.php';
require_once '../classes/user.php';
require_once '../classes/classroom.php';
require_once '../classes/utilities.php';
require_once '../classes/ActivityLog.php';
require_once '../classes/AcademicYear.php';
require_once '../classes/AcademicYearWriteGuard.php';
require_once '../classes/StudentAttendanceService.php';
require_once '../includes/template_helper.php';
require_once '../includes/session_config.php';
require_once '../includes/csrf.php';

Utilities::validateSession('teacher');
requireCsrfPost();

$database = new Database();
$db = $database->getConnection();
$currentAcademicYearId = AcademicYear::currentId($db);

$user = new User($db);
$user->id = $_SESSION['user_id'];
$assigned_classes = $user->getAssignedClasses();

// Selected class and date
$selected_class_id = isset($_GET['class_id']) ? intval($_GET['class_id']) : (isset($_POST['class_id']) ? intval($_POST['class_id']) : 0);
$raw_date_input = $_GET['date'] ?? ($_POST['attendance_date'] ?? date('Y-m-d'));
$raw_date = is_scalar($raw_date_input) ? (string) $raw_date_input : '';
$parsed_date = DateTimeImmutable::createFromFormat('!Y-m-d', $raw_date);
$parsed_date_errors = DateTimeImmutable::getLastErrors();
$selected_date = $parsed_date
    && (!is_array($parsed_date_errors) || (($parsed_date_errors['warning_count'] ?? 0) === 0 && ($parsed_date_errors['error_count'] ?? 0) === 0))
    && $parsed_date->format('Y-m-d') === $raw_date
    && $parsed_date <= new DateTimeImmutable('today')
        ? $raw_date
        : date('Y-m-d');

// Verify teacher has access to selected class
$has_access = false;
$selected_class_name = '';
if ($selected_class_id && $assigned_classes) {
    foreach ($assigned_classes as $cls) {
        if ($cls['id'] == $selected_class_id) {
            $has_access = true;
            $selected_class_name = $cls['name'];
            break;
        }
    }
}

// Handle AJAX save
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['save_attendance'])) {
    header('Content-Type: application/json; charset=utf-8');
    
    try {
        if (!$has_access) {
            throw new Exception('لا تملك صلاحية لهذا الفصل');
        }
        
        $attendance_date = (string) ($_POST['attendance_date'] ?? '');
        $class_id = (int) ($_POST['class_id'] ?? 0);
        $statuses = $_POST['status'] ?? [];
        $notes = $_POST['notes'] ?? [];
        if (!is_array($statuses) || !is_array($notes)) {
            throw new InvalidArgumentException('بيانات الحضور غير صالحة.');
        }
        $result = (new StudentAttendanceService($db))->saveClassDay(
            $class_id,
            $currentAcademicYearId,
            $attendance_date,
            $statuses,
            $notes,
            (int) ($_SESSION['user_id'] ?? 0),
            'teacher'
        );
        
        echo json_encode(['success' => true, 'message' => "تم حفظ حضور {$result['count']} طالب بنجاح"]);
    } catch (Throwable $e) {
        if ($e instanceof PDOException) {
            error_log('Teacher student attendance save failed: ' . $e->getMessage());
            $message = 'تعذر حفظ الحضور بسبب خطأ في قاعدة البيانات. لم يتم اعتماد أي تغيير جزئي.';
        } else {
            $message = $e->getMessage();
        }
        echo json_encode(['success' => false, 'message' => $message]);
    }
    exit;
}

// Get students for selected class
$students = [];
$existing_attendance = [];
if ($selected_class_id && $has_access) {
    if ($currentAcademicYearId > 0) {
        $stmt = $db->prepare("SELECT u.id, u.name FROM users u
            JOIN student_enrollments se ON se.student_id = u.id
                AND se.academic_year_id = ? AND se.enrollment_status = 'enrolled'
            WHERE se.class_id = ? AND u.role = 'student' AND u.status = 'active' AND u.deleted_at IS NULL
            ORDER BY u.name");
        $stmt->execute([$currentAcademicYearId, $selected_class_id]);
    } else {
        $stmt = $db->prepare("SELECT u.id, u.name FROM users u WHERE u.class_id = ? AND u.role = 'student' AND u.status = 'active' AND u.deleted_at IS NULL AND NOT EXISTS (SELECT 1 FROM student_profiles sp WHERE sp.user_id = u.id AND sp.enrollment_status <> 'enrolled') ORDER BY u.name");
        $stmt->execute([$selected_class_id]);
    }
    $students = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // Get existing attendance for this date
    $attendanceYearSql = $currentAcademicYearId > 0 ? ' AND academic_year_id = ?' : '';
    $att_stmt = $db->prepare("SELECT student_id, status, notes FROM attendance WHERE class_id = ? AND attendance_date = ?{$attendanceYearSql}");
    $att_stmt->execute($currentAcademicYearId > 0
        ? [$selected_class_id, $selected_date, $currentAcademicYearId]
        : [$selected_class_id, $selected_date]);
    while ($row = $att_stmt->fetch(PDO::FETCH_ASSOC)) {
        $existing_attendance[$row['student_id']] = $row;
    }
}

// Get attendance stats for this class (last 30 days)
$stats = [];
if ($selected_class_id && $has_access) {
    $stats_stmt = $db->prepare("
        SELECT status, COUNT(*) as count 
        FROM attendance 
        WHERE class_id = ? AND attendance_date >= DATE_SUB(CURDATE(), INTERVAL 30 DAY)
          " . ($currentAcademicYearId > 0 ? "AND academic_year_id = ?" : "") . "
        GROUP BY status
    ");
    $stats_stmt->execute($currentAcademicYearId > 0
        ? [$selected_class_id, $currentAcademicYearId]
        : [$selected_class_id]);
    while ($row = $stats_stmt->fetch(PDO::FETCH_ASSOC)) {
        $stats[$row['status']] = $row['count'];
    }
}

?>
<!DOCTYPE html>
<html lang="ar" dir="rtl">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>الحضور والغياب - نظام الإدارة المدرسية</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.rtl.min.css" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css" rel="stylesheet">
    <link href="https://fonts.googleapis.com/css2?family=Tajawal:wght@400;500;700&display=swap" rel="stylesheet">
    <link href="../assets/css/premium-dashboard.css" rel="stylesheet">
</head>
<body style="font-family: 'Tajawal', sans-serif; background: #f0f2f5; min-height: 100vh;">

<style>
    .attendance-container {
        max-width: 1200px;
        margin: 0 auto;
        padding: 20px;
    }
    .class-selector {
        background: white;
        border-radius: 12px;
        padding: 20px;
        box-shadow: 0 2px 10px rgba(0,0,0,0.08);
        margin-bottom: 20px;
    }
    .attendance-table {
        background: white;
        border-radius: 12px;
        box-shadow: 0 2px 10px rgba(0,0,0,0.08);
        overflow: hidden;
    }
    .attendance-table .table {
        margin-bottom: 0;
    }
    .attendance-table .table th {
        background: #4361ee;
        color: white;
        font-weight: 500;
        border: none;
        padding: 12px 8px;
    }
    .attendance-table .table td {
        padding: 8px;
        vertical-align: middle;
    }
    .status-btn {
        border: 2px solid #dee2e6;
        background: white;
        border-radius: 8px;
        padding: 6px 12px;
        font-size: 0.85rem;
        cursor: pointer;
        transition: all 0.2s;
        margin: 2px;
    }
    .status-btn:hover {
        transform: scale(1.05);
    }
    .status-btn.active-present {
        background: #28a745;
        color: white;
        border-color: #28a745;
    }
    .status-btn.active-absent {
        background: #dc3545;
        color: white;
        border-color: #dc3545;
    }
    .status-btn.active-late {
        background: #ffc107;
        color: #333;
        border-color: #ffc107;
    }
    .status-btn.active-excused {
        background: #17a2b8;
        color: white;
        border-color: #17a2b8;
    }
    .student-num {
        display: inline-flex;
        align-items: center;
        justify-content: center;
        width: 28px;
        height: 28px;
        border-radius: 50%;
        background: #e9ecef;
        font-size: 0.8rem;
        font-weight: 600;
        margin-left: 8px;
    }
    .notes-input {
        font-size: 0.8rem;
        padding: 4px 8px;
        border-radius: 6px;
        border: 1px solid #dee2e6;
        width: 100%;
        max-width: 200px;
    }
    .mark-all-btns {
        display: flex;
        gap: 8px;
        margin-bottom: 15px;
        flex-wrap: wrap;
    }
    .mark-all-btns .btn {
        border-radius: 20px;
        padding: 5px 15px;
        font-size: 0.85rem;
    }
    .save-bar {
        position: sticky;
        bottom: 0;
        background: white;
        padding: 15px 20px;
        border-top: 2px solid #4361ee;
        box-shadow: 0 -4px 15px rgba(0,0,0,0.1);
        z-index: 100;
        display: flex;
        justify-content: space-between;
        align-items: center;
    }
    .portal-back-btn {
        display: inline-flex;
        align-items: center;
        gap: 8px;
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

    /* ===== Mobile Responsive ===== */
    @media (max-width: 768px) {
        .attendance-container {
            padding: 10px;
        }
        .attendance-container h2 {
            font-size: 1.2rem;
        }
        .attendance-container .portal-back-btn {
            padding: 6px 10px;
            font-size: 0.8rem;
        }
        .class-selector {
            padding: 12px;
        }
        .class-selector .row {
            gap: 0;
        }
        /* Hide the desktop table on mobile */
        .attendance-table .table thead { display: none; }
        .attendance-table .table, 
        .attendance-table .table tbody, 
        .attendance-table .table tr, 
        .attendance-table .table td {
            display: block;
            width: 100%;
        }
        .attendance-table .table tr {
            border: 1px solid #e9ecef;
            border-radius: 10px;
            margin-bottom: 10px;
            padding: 10px;
            background: #fff;
            box-shadow: 0 1px 4px rgba(0,0,0,0.05);
        }
        .attendance-table .table td {
            padding: 4px 0;
            border: none;
        }
        /* Student name row */
        .attendance-table .table td:nth-child(1) {
            display: inline;
            font-weight: 700;
        }
        .attendance-table .table td:nth-child(2) {
            display: inline;
            font-weight: 700;
            font-size: 1rem;
        }
        /* Status buttons - 2x2 grid on mobile */
        .attendance-table .table td:nth-child(3) .d-flex {
            display: grid !important;
            grid-template-columns: 1fr 1fr;
            gap: 5px;
            margin-top: 6px;
        }
        .status-btn {
            padding: 8px 4px;
            font-size: 0.8rem;
            text-align: center;
            border-radius: 8px;
            margin: 0;
        }
        /* Notes input full width */
        .attendance-table .table td:nth-child(4) {
            margin-top: 6px;
        }
        .notes-input {
            max-width: 100%;
            padding: 8px;
            font-size: 0.85rem;
        }
        /* Mark-all buttons */
        .mark-all-btns {
            gap: 4px;
            justify-content: center;
        }
        .mark-all-btns .btn {
            padding: 5px 10px;
            font-size: 0.75rem;
        }
        .mark-all-btns span.text-muted {
            display: none;
        }
        /* Save bar - stack vertically */
        .save-bar {
            flex-direction: column;
            gap: 10px;
            padding: 10px 15px;
            text-align: center;
        }
        .save-bar .text-muted {
            font-size: 0.75rem;
        }
        .save-bar #saveBtn {
            width: 100%;
        }
        /* Stat cards */
        .stat-card {
            padding: 10px;
        }
        .stat-card .h3 {
            font-size: 1.3rem;
        }
    }
</style>

<div class="attendance-container">
    <!-- Back button and title -->
    <div class="d-flex justify-content-between align-items-center mb-4">
        <div>
            <h2><i class="fas fa-clipboard-check me-2 text-primary"></i>الحضور والغياب</h2>
            <p class="text-muted mb-0">تسجيل حضور وغياب الطلاب يومياً</p>
        </div>
        <a href="portal.php" class="portal-back-btn">
            <i class="fas fa-arrow-right"></i> العودة للبوابة
        </a>
    </div>

    <!-- Class & Date Selector -->
    <div class="class-selector">
        <form method="GET" class="row g-3 align-items-end">
            <div class="col-md-5">
                <label class="form-label fw-bold"><i class="fas fa-school me-1"></i>اختر الفصل</label>
                <select name="class_id" class="form-select" onchange="this.form.submit()" required>
                    <option value="">-- اختر فصلاً --</option>
                    <?php if ($assigned_classes): ?>
                        <?php foreach ($assigned_classes as $cls): ?>
                            <option value="<?php echo $cls['id']; ?>" <?php echo $selected_class_id == $cls['id'] ? 'selected' : ''; ?>>
                                <?php echo htmlspecialchars($cls['name']); ?>
                            </option>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </select>
            </div>
            <div class="col-md-4">
                <label class="form-label fw-bold"><i class="fas fa-calendar-alt me-1"></i>التاريخ</label>
                <input type="text" name="date" class="form-control flatpickr-date" placeholder="اختر التاريخ..." value="<?php echo $selected_date; ?>" onchange="this.form.submit()" max="<?php echo date('Y-m-d'); ?>">
            </div>
            <div class="col-md-3">
                <button type="submit" class="btn btn-primary w-100">
                    <i class="fas fa-search me-1"></i>عرض
                </button>
            </div>
        </form>
    </div>

    <?php if ($selected_class_id && $has_access && !empty($students)): ?>
    
    <!-- Quick Stats -->
    <?php if (!empty($stats)): ?>
    <div class="row g-3 mb-4">
        <div class="col-6 col-md-3">
            <div class="stat-card" style="--card-gradient: var(--success-gradient);">
                <div class="stat-card-icon"><i class="fas fa-check-circle"></i></div>
                <div class="stat-card-info">
                    <div class="stat-card-number"><?php echo $stats['present'] ?? 0; ?></div>
                    <div class="stat-card-label">حاضر (30 يوم)</div>
                </div>
            </div>
        </div>
        <div class="col-6 col-md-3">
            <div class="stat-card" style="--card-gradient: var(--danger-gradient);">
                <div class="stat-card-icon"><i class="fas fa-times-circle"></i></div>
                <div class="stat-card-info">
                    <div class="stat-card-number"><?php echo $stats['absent'] ?? 0; ?></div>
                    <div class="stat-card-label">غائب (30 يوم)</div>
                </div>
            </div>
        </div>
        <div class="col-6 col-md-3">
            <div class="stat-card" style="--card-gradient: var(--warning-gradient);">
                <div class="stat-card-icon"><i class="fas fa-clock"></i></div>
                <div class="stat-card-info">
                    <div class="stat-card-number"><?php echo $stats['late'] ?? 0; ?></div>
                    <div class="stat-card-label">متأخر (30 يوم)</div>
                </div>
            </div>
        </div>
        <div class="col-6 col-md-3">
            <div class="stat-card" style="--card-gradient: var(--info-gradient);">
                <div class="stat-card-icon"><i class="fas fa-file-alt"></i></div>
                <div class="stat-card-info">
                    <div class="stat-card-number"><?php echo $stats['excused'] ?? 0; ?></div>
                    <div class="stat-card-label">بإذن (30 يوم)</div>
                </div>
            </div>
        </div>
    </div>
    <?php endif; ?>

    <!-- Mark All Buttons -->
    <div class="mark-all-btns">
        <span class="text-muted me-2 align-self-center">تحديد الكل:</span>
        <button type="button" class="btn btn-success btn-sm" onclick="markAll('present')">
            <i class="fas fa-check me-1"></i>حاضر
        </button>
        <button type="button" class="btn btn-danger btn-sm" onclick="markAll('absent')">
            <i class="fas fa-times me-1"></i>غائب
        </button>
        <button type="button" class="btn btn-warning btn-sm" onclick="markAll('late')">
            <i class="fas fa-clock me-1"></i>متأخر
        </button>
        <button type="button" class="btn btn-info btn-sm" onclick="markAll('excused')">
            <i class="fas fa-file-alt me-1"></i>بإذن
        </button>
    </div>

    <!-- Attendance Table -->
    <form id="attendanceForm" method="POST">
        <?php echo csrfField(); ?>
        <input type="hidden" name="save_attendance" value="1">
        <input type="hidden" name="class_id" value="<?php echo $selected_class_id; ?>">
        <input type="hidden" name="attendance_date" value="<?php echo $selected_date; ?>">
        
        <div class="attendance-table">
            <table class="table table-hover">
                <thead>
                    <tr>
                        <th style="width: 50px;">#</th>
                        <th>اسم الطالب</th>
                        <th style="width: 320px;">الحالة</th>
                        <th>ملاحظات</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($students as $i => $student): 
                        $existing = $existing_attendance[$student['id']] ?? null;
                        $current_status = $existing ? $existing['status'] : 'present';
                        $current_notes = $existing ? $existing['notes'] : '';
                    ?>
                    <tr>
                        <td><span class="student-num"><?php echo $i + 1; ?></span></td>
                        <td class="fw-bold"><?php echo htmlspecialchars($student['name']); ?></td>
                        <td>
                            <div class="d-flex flex-wrap">
                                <input type="hidden" name="status[<?php echo $student['id']; ?>]" id="status_<?php echo $student['id']; ?>" value="<?php echo $current_status; ?>">
                                <button type="button" class="status-btn <?php echo $current_status === 'present' ? 'active-present' : ''; ?>" 
                                        onclick="setStatus(<?php echo $student['id']; ?>, 'present', this)" data-status="present">
                                    <i class="fas fa-check"></i> حاضر
                                </button>
                                <button type="button" class="status-btn <?php echo $current_status === 'absent' ? 'active-absent' : ''; ?>" 
                                        onclick="setStatus(<?php echo $student['id']; ?>, 'absent', this)" data-status="absent">
                                    <i class="fas fa-times"></i> غائب
                                </button>
                                <button type="button" class="status-btn <?php echo $current_status === 'late' ? 'active-late' : ''; ?>" 
                                        onclick="setStatus(<?php echo $student['id']; ?>, 'late', this)" data-status="late">
                                    <i class="fas fa-clock"></i> متأخر
                                </button>
                                <button type="button" class="status-btn <?php echo $current_status === 'excused' ? 'active-excused' : ''; ?>" 
                                        onclick="setStatus(<?php echo $student['id']; ?>, 'excused', this)" data-status="excused">
                                    <i class="fas fa-file-alt"></i> بإذن
                                </button>
                            </div>
                        </td>
                        <td>
                            <input type="text" name="notes[<?php echo $student['id']; ?>]" class="notes-input" 
                                   placeholder="ملاحظات..." value="<?php echo htmlspecialchars($current_notes); ?>">
                        </td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>

        <!-- Save Bar -->
        <div class="save-bar">
            <div>
                <span class="text-muted">
                    <i class="fas fa-users me-1"></i>
                    <?php echo count($students); ?> طالب | 
                    <i class="fas fa-calendar me-1"></i>
                    <?php echo $selected_date; ?> |
                    <i class="fas fa-school me-1"></i>
                    <?php echo htmlspecialchars($selected_class_name); ?>
                </span>
            </div>
            <div>
                <span id="saveStatus" class="me-3"></span>
                <button type="button" id="saveBtn" class="btn btn-primary btn-lg" onclick="saveAttendance()">
                    <i class="fas fa-save me-2"></i>حفظ الحضور
                </button>
            </div>
        </div>
    </form>

    <?php elseif ($selected_class_id && $has_access && empty($students)): ?>
        <div class="text-center py-5">
            <i class="fas fa-users-slash fa-3x text-muted mb-3"></i>
            <p class="text-muted h5">لا يوجد طلاب في هذا الفصل</p>
        </div>
    <?php elseif ($selected_class_id && !$has_access): ?>
        <div class="alert alert-danger">
            <i class="fas fa-exclamation-triangle me-2"></i>لا تملك صلاحية الوصول لهذا الفصل
        </div>
    <?php else: ?>
        <div class="text-center py-5">
            <i class="fas fa-hand-pointer fa-3x text-muted mb-3"></i>
            <p class="text-muted h5">اختر فصلاً لتسجيل الحضور</p>
        </div>
    <?php endif; ?>
</div>

<script>
function setStatus(studentId, status, btn) {
    // Update hidden input
    document.getElementById('status_' + studentId).value = status;
    
    // Remove active classes from siblings
    const parent = btn.parentElement;
    parent.querySelectorAll('.status-btn').forEach(b => {
        b.className = 'status-btn';
    });
    
    // Add active class
    btn.classList.add('active-' + status);
}

function markAll(status) {
    document.querySelectorAll('input[id^="status_"]').forEach(input => {
        input.value = status;
    });
    
    // Update button styles
    document.querySelectorAll('.status-btn').forEach(btn => {
        btn.className = 'status-btn';
        if (btn.dataset.status === status) {
            btn.classList.add('active-' + status);
        }
    });
}

function saveAttendance() {
    const btn = document.getElementById('saveBtn');
    const statusEl = document.getElementById('saveStatus');
    
    btn.disabled = true;
    btn.innerHTML = '<i class="fas fa-spinner fa-spin me-2"></i>جاري الحفظ...';
    
    const formData = new FormData(document.getElementById('attendanceForm'));
    
    fetch('attendance.php', {
        method: 'POST',
        body: formData
    })
    .then(res => res.json())
    .then(data => {
        if (data.success) {
            statusEl.innerHTML = '<span class="text-success"><i class="fas fa-check-circle me-1"></i>' + data.message + '</span>';
            btn.innerHTML = '<i class="fas fa-check me-2"></i>تم الحفظ';
            btn.classList.remove('btn-primary');
            btn.classList.add('btn-success');
            
            setTimeout(() => {
                btn.disabled = false;
                btn.innerHTML = '<i class="fas fa-save me-2"></i>حفظ الحضور';
                btn.classList.remove('btn-success');
                btn.classList.add('btn-primary');
            }, 3000);
        } else {
            statusEl.innerHTML = '<span class="text-danger"><i class="fas fa-exclamation-circle me-1"></i>' + data.message + '</span>';
            btn.disabled = false;
            btn.innerHTML = '<i class="fas fa-save me-2"></i>حفظ الحضور';
        }
    })
    .catch(err => {
        statusEl.innerHTML = '<span class="text-danger"><i class="fas fa-exclamation-circle me-1"></i>حدث خطأ في الاتصال</span>';
        btn.disabled = false;
        btn.innerHTML = '<i class="fas fa-save me-2"></i>حفظ الحضور';
    });
}
</script>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
<!-- Air Datepicker (حامل التاريخ الموحد) -->
<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/air-datepicker@3.5.0/air-datepicker.css">
<script src="https://cdn.jsdelivr.net/npm/air-datepicker@3.5.0/air-datepicker.js"></script>
<script src="<?php echo asset_url('../assets/js/air-datepicker-init.js'); ?>"></script>
</body>
</html>
