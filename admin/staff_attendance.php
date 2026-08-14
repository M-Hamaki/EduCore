<?php
/**
 * إدارة حضور وغياب الموظفين
 */
$page_title = "حضور وغياب الموظفين";
$custom_page_title = true;

require_once '../config/database.php';
require_once '../classes/user.php';
require_once '../classes/StaffAttendanceService.php';
require_once '../classes/StaffEmploymentLifecycleService.php';
require_once '../classes/utilities.php';
require_once '../includes/session_config.php';
require_once '../includes/csrf.php';
require_once '../includes/pagination.php';
require_once '../vendor/autoload.php';
require_once '../src/Modules/Operations/Audit/AuditService.php';
Utilities::validateSession('admin');

requireCsrfPost();

$database = new Database();
$db = $database->getConnection();
$attendanceService = new StaffAttendanceService($db);
$staffHrFlags = \EduCore\Modules\Staff\Infrastructure\StaffHrFeatureFlags::fromEnvironment();
$usesAttendanceEventPipeline = $staffHrFlags->calculatesNewResults();
$isOfficialAttendanceMode = $staffHrFlags->usesNewResultsAsOfficial();
if (!$isOfficialAttendanceMode) {
    // Kept only for the legacy compatibility writer. Migration-owned
    // attendance tables are never created during a request.
    $attendanceService->ensureAttendanceAuditTable();
}

// Get messages from session
$success_message = $_SESSION['success_message'] ?? null;
$error_message = $_SESSION['error_message'] ?? null;
unset($_SESSION['success_message'], $_SESSION['error_message']);

$user = new User($db);

$attendanceStatus = [
    'present' => 'حاضر',
    'absent' => 'غائب',
    'late' => 'متأخر',
    'excused' => 'بعذر'
];
$attendanceBadges = [
    'present' => 'success',
    'absent' => 'danger',
    'late' => 'warning',
    'excused' => 'info'
];

// إعدادات الدوام الافتراضية (تُستخدم للتنبيه عند عدم التسجيل)
$shiftSettings = $attendanceService->getDefaultShiftSettings();
$shiftStart = $shiftSettings['shift_start'];
$shiftEnd = $shiftSettings['shift_end'];
$shiftGraceMinutes = (int)$shiftSettings['shift_grace_minutes'];

// الفلاتر العامة
$selectedDate = $_GET['date'] ?? date('Y-m-d');
$filterUser = $_GET['user_id'] ?? '';
$filterStatus = $_GET['filter_status'] ?? '';
$filterStageId = $_GET['stage_id'] ?? '';
$filterJobTitle = StaffEmploymentLifecycleService::canonicalJobTitle($_GET['job_title'] ?? null) ?? '';
$viewMode = $_GET['view'] ?? 'daily'; // daily or records
$selectedDateForAttendanceReview = is_string($selectedDate)
    && preg_match('/^\d{4}-\d{2}-\d{2}$/', $selectedDate) === 1
    ? $selectedDate
    : date('Y-m-d');

// In staged rollout modes, show only a redacted, read-only summary of the
// migration-owned evidence that requires human review. The legacy attendance
// page remains the compatibility surface until the official result and
// documented correction workflow are enabled.
$attendanceExceptionSnapshot = null;
$attendanceExceptionSnapshotError = null;
if ($usesAttendanceEventPipeline) {
    try {
        $attendanceFactory = new \EduCore\Modules\Attendance\Infrastructure\AttendanceModuleFactory(
            $db,
            new \EduCore\Modules\Operations\Audit\AuditService($db)
        );
        $attendanceExceptionSnapshot = $attendanceFactory->attendanceExceptionQuery()->review([
            'date_from' => $selectedDateForAttendanceReview,
            'date_to' => $selectedDateForAttendanceReview,
            'category' => 'all',
        ]);
    } catch (Throwable $exception) {
        error_log('attendance exception snapshot unavailable: ' . $exception->getMessage());
        $attendanceExceptionSnapshotError = 'لا تتوفر بيانات المراجعة الجديدة الآن. تحقق من تطبيق ترحيلات الحضور قبل تفعيل هذا المسار.';
    }
}

// خيارات الفلاتر
$stagesOptions = $db->query("SELECT id, stage_name FROM stages ORDER BY id")->fetchAll(PDO::FETCH_ASSOC);
$jobTitleStmt = $db->query("SELECT DISTINCT job_title FROM staff_profiles WHERE job_title IS NOT NULL AND job_title <> '' ORDER BY job_title");
$jobTitleOptions = StaffEmploymentLifecycleService::canonicalJobTitleOptionsFromValues(
    $jobTitleStmt->fetchAll(PDO::FETCH_COLUMN)
);

// جلب قائمة الموظفين
$staffList = $attendanceService->getActiveStaffList([
    'user_id' => $filterUser,
    'stage_id' => $filterStageId,
    'job_title' => $filterJobTitle
]);

$staffIds = array_values(array_map(static function ($s) {
    return (int)$s['id'];
}, $staffList));

// الدوامات المخصصة (إن وجدت)
$shiftOverridesByUser = $attendanceService->getShiftOverridesByUser($staffIds);


// معالجة النماذج
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    requireCsrfPost();
    if ($isOfficialAttendanceMode && (isset($_POST['save_bulk_attendance']) || isset($_POST['delete_attendance']))) {
        $_SESSION['error_message'] = 'نتائج الحضور الجديدة أصبحت رسمية. لا يسمح هذا السطح بتعديل السجل القديم مباشرة؛ استخدم مسار التصحيح والمراجعة المعتمد.';
        $returnView = in_array($viewMode, ['daily', 'records'], true) ? $viewMode : 'daily';
        header('Location: staff_attendance.php?' . http_build_query([
            'view' => $returnView,
            'date' => $selectedDateForAttendanceReview,
        ]));
        exit();
    }

    // تسجيل حضور يوم كامل لعدة موظفين
    if (isset($_POST['save_bulk_attendance'])) {
        $date = $_POST['attendance_date'];
        $count = 0;
        $adminId = isset($_SESSION['user_id']) ? (int)$_SESSION['user_id'] : 0;
        if (!empty($_POST['staff_status']) && is_array($_POST['staff_status'])) {
            $db->beginTransaction();
            try {
                foreach ($_POST['staff_status'] as $userId => $status) {
                    $checkIn = !empty($_POST['check_in'][$userId]) ? $_POST['check_in'][$userId] : null;
                    $checkOut = !empty($_POST['check_out'][$userId]) ? $_POST['check_out'][$userId] : null;
                    $lateMins = (int)($_POST['late_minutes'][$userId] ?? 0);
                    $notes = trim($_POST['att_notes'][$userId] ?? '');
                    if (!isset($attendanceStatus[$status])) {
                        $status = 'present';
                    }

                    $saveResult = $attendanceService->saveManualAttendanceWithAudit(
                        $adminId,
                        (int)$userId,
                        $date,
                        $status,
                        $checkIn,
                        $checkOut,
                        $lateMins,
                        $notes
                    );
                    if (!empty($saveResult['changed'])) {
                        $count++;
                    }
                }
                $db->commit();
            } catch (Exception $e) {
                $db->rollBack();
                $_SESSION['error_message'] = 'حدث خطأ أثناء حفظ الحضور';
                $count = 0;
            }
        }
        if ($count > 0) {
            $_SESSION['success_message'] = "تم حفظ حضور $count موظف بنجاح";
            header("Location: staff_attendance.php?view=daily&date=" . $date);
            exit();
        }
    }

    if (isset($_POST['delete_attendance'])) {
        $id = (int)$_POST['id'];
        try {
            $db->beginTransaction();
            $deleted = $attendanceService->deleteAttendanceByIdWithAudit($id, (int)($_SESSION['user_id'] ?? 0));
            $db->commit();
            $_SESSION['success_message'] = $deleted ? 'تم حذف السجل' : 'السجل غير موجود أو تم حذفه مسبقاً';
        } catch (Throwable $e) {
            if ($db->inTransaction()) {
                $db->rollBack();
            }
            $_SESSION['error_message'] = 'تعذر حذف سجل الحضور. لم يتم حفظ أي تغيير.';
        }
        $redirectQuery = [
            'view' => 'records',
            'user_id' => $filterUser,
            'filter_status' => $filterStatus,
            'stage_id' => $filterStageId,
            'job_title' => $filterJobTitle,
            'date_from' => $_GET['date_from'] ?? '',
            'date_to' => $_GET['date_to'] ?? ''
        ];
        header("Location: staff_attendance.php" . Utilities::buildQueryString($redirectQuery));
        exit();
    }
}

// جلب حضور اليوم المحدد
$dayAttendance = [];
if ($viewMode === 'daily') {
    $dayAttendance = $attendanceService->getAttendanceByDate($selectedDate, $staffIds);
}

// جلب الإجازات والأذونات المعتمدة لليوم المحدد (لمنع احتساب الغياب بشكل خاطئ)
$approvedLeavesByUser = [];
$approvedPermissionsByUser = [];
if ($viewMode === 'daily' && !empty($staffList)) {
    $approvedLeavesByUser = $attendanceService->getApprovedLeavesByDate($selectedDate, $staffIds);
    $approvedPermissionsByUser = $attendanceService->getApprovedPermissionsByDate($selectedDate, $staffIds);
}

// جلب السجلات (عرض الكل)
$records = [];
$recordsPagination = paginationState(0, 50, 'records_page');
if ($viewMode === 'records') {
    $where = "1=1";
    $params = [];
    if ($filterUser) { $where .= " AND a.user_id = ?"; $params[] = (int)$filterUser; }
    if ($filterStatus) { $where .= " AND a.status = ?"; $params[] = $filterStatus; }
    if ($filterJobTitle !== '') {
        $jobTitleValues = StaffEmploymentLifecycleService::jobTitleFilterValues($filterJobTitle);
        if ($jobTitleValues === []) {
            $where .= ' AND 1 = 0';
        } else {
            $where .= ' AND sp.job_title IN (' . implode(',', array_fill(0, count($jobTitleValues), '?')) . ')';
            array_push($params, ...$jobTitleValues);
        }
    }
    if ($filterStageId !== '') {
        $where .= " AND (
            EXISTS (
                SELECT 1
                FROM user_class_access uca
                JOIN classes c ON c.id = uca.class_id
                JOIN grades g ON g.id = c.grade_id
                WHERE uca.user_id = u.id AND g.stage_id = ?
            )
            OR EXISTS (
                SELECT 1
                FROM specialist_active_classes sc
                JOIN classes c2 ON c2.id = sc.class_id
                JOIN grades g2 ON g2.id = c2.grade_id
                WHERE sc.specialist_id = u.id AND g2.stage_id = ?
            )
        )";
        $params[] = (int)$filterStageId;
        $params[] = (int)$filterStageId;
    }
    if (!empty($_GET['date_from'])) { $where .= " AND a.attendance_date >= ?"; $params[] = $_GET['date_from']; }
    if (!empty($_GET['date_to'])) { $where .= " AND a.attendance_date <= ?"; $params[] = $_GET['date_to']; }

    $countStmt = $db->prepare("SELECT COUNT(*) FROM staff_attendance a JOIN users u ON a.user_id = u.id LEFT JOIN staff_profiles sp ON sp.user_id = u.id WHERE $where");
    $countStmt->execute($params);
    $recordsPagination = paginationState((int)$countStmt->fetchColumn(), 50, 'records_page');
    // قيمتان صحيحتان مضمونتان بـ (int) — الاستيفاء المباشر آمن لـ LIMIT/OFFSET
    // لأن PDO في وضع emulate-prepares يُقتبس قيم bound params ويُسبب خطأ 1064.
    $limit  = max(1, (int)$recordsPagination['limit']);
    $offset = max(0, (int)$recordsPagination['offset']);
    $stmt = $db->prepare("SELECT a.*, u.name as staff_name, sp.job_title
                          FROM staff_attendance a
                          JOIN users u ON a.user_id = u.id
                          LEFT JOIN staff_profiles sp ON sp.user_id = u.id
                          WHERE $where
                          ORDER BY a.attendance_date DESC, u.name LIMIT $limit OFFSET $offset");
    $stmt->execute($params);
    $records = $stmt->fetchAll(PDO::FETCH_ASSOC);
    foreach ($records as &$record) {
        $record['job_title'] = StaffEmploymentLifecycleService::canonicalJobTitle($record['job_title'] ?? null);
    }
    unset($record);
}

// إحصائيات الشهر الحالي
$monthStart = date('Y-m-01');
$monthEnd = date('Y-m-t');
$monthStats = $db->prepare("SELECT status, COUNT(*) as cnt FROM staff_attendance WHERE attendance_date BETWEEN ? AND ? GROUP BY status");
$monthStats->execute([$monthStart, $monthEnd]);
$monthStatsData = $monthStats->fetchAll(PDO::FETCH_KEY_PAIR);

// إحصائيات اليوم المحدد + تنبيه من لم يسجل بعد وقت السماح
$dailyStatsData = ['present' => 0, 'absent' => 0, 'late' => 0, 'excused' => 0, 'unregistered' => 0];
$missingRegistrationStaff = [];
$isSelectedToday = false;
$shiftCutoffPassed = false;

if ($viewMode === 'daily') {
    $dashboardData = $attendanceService->getDailyDashboardStats(
        $selectedDate,
        $staffList,
        $dayAttendance,
        $approvedLeavesByUser,
        $approvedPermissionsByUser,
        $shiftSettings,
        $shiftOverridesByUser
    );
    $dailyStatsData = $dashboardData['stats'];
    $missingRegistrationStaff = $dashboardData['missing_registration_staff'];
    $isSelectedToday = $dashboardData['is_selected_today'];
    $shiftCutoffPassed = $dashboardData['shift_cutoff_passed'];
}

$statGradients = [
    'present' => 'linear-gradient(135deg, #10b981, #059669)',
    'absent'  => 'linear-gradient(135deg, #ef4444, #dc2626)',
    'late'    => 'linear-gradient(135deg, #f59e0b, #d97706)',
    'excused' => 'linear-gradient(135deg, #3b82f6, #2563eb)'
];
$statIcons = [
    'present' => 'fa-user-check',
    'absent'  => 'fa-user-times',
    'late'    => 'fa-clock',
    'excused' => 'fa-user-shield'
];

$attendanceSummaryCards = [];
foreach ($attendanceStatus as $key => $label) {
    $attendanceSummaryCards[] = [
        'value' => $viewMode === 'daily' ? ($dailyStatsData[$key] ?? 0) : ($monthStatsData[$key] ?? 0),
        'label' => $label . ' - ' . ($viewMode === 'daily' ? 'اليوم المحدد' : 'هذا الشهر'),
        'icon' => $statIcons[$key],
        'gradient' => str_replace('linear-gradient(135deg, ', '', rtrim($statGradients[$key], ')'))
    ];
}

if ($viewMode === 'daily') {
    $attendanceSummaryCards[] = [
        'value' => $dailyStatsData['unregistered'] ?? 0,
        'label' => 'غير مسجلين حتى الآن',
        'icon' => 'fa-user-clock',
        'gradient' => '#f97316, #ea580c'
    ];
}

require_once '../includes/admin_header.php';
require_once '../includes/widgets/hr_stat_cards.php';
?>

<!-- Page Header -->
<div class="d-flex justify-content-between flex-wrap flex-md-nowrap align-items-center pt-3 pb-2 mb-3 border-bottom">
    <h1 class="h2"><i class="fas fa-user-clock me-2"></i>حضور وغياب الموظفين</h1>
    <div class="btn-toolbar mb-2 mb-md-0 gap-2">
        <a href="hr_attendance_exceptions.php" class="btn btn-sm btn-outline-warning">
            <i class="fas fa-triangle-exclamation me-1"></i>مركز الاستثناءات
        </a>
        <a href="staff_biometric_import.php" class="btn btn-sm btn-outline-primary">
            <i class="fas fa-fingerprint me-1"></i>استيراد البصمة
        </a>
        <a href="staff_attendance.php?view=daily&amp;date=<?php echo rawurlencode($selectedDateForAttendanceReview); ?>" class="btn btn-sm btn-outline-<?php echo $viewMode === 'daily' ? 'primary' : 'success'; ?> me-2">
            <i class="fas fa-calendar-day me-1"></i>تسجيل يومي
        </a>
        <a href="staff_attendance.php?view=records" class="btn btn-sm btn-outline-<?php echo $viewMode === 'records' ? 'primary' : 'success'; ?>">
            <i class="fas fa-list me-1"></i>السجلات
        </a>
    </div>
</div>

<!-- Alerts -->
<?php if ($success_message): ?>
<div class="alert alert-success alert-dismissible fade show" role="alert">
    <i class="fas fa-check-circle me-2"></i><?php echo htmlspecialchars($success_message, ENT_QUOTES, 'UTF-8'); ?>
    <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
</div>
<?php endif; ?>

<?php if ($error_message): ?>
<div class="alert alert-danger alert-dismissible fade show" role="alert">
    <i class="fas fa-exclamation-circle me-2"></i><?php echo htmlspecialchars($error_message, ENT_QUOTES, 'UTF-8'); ?>
    <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
</div>
<?php endif; ?>

<?php if ($usesAttendanceEventPipeline): ?>
<div class="alert alert-<?php echo $isOfficialAttendanceMode ? 'danger' : 'info'; ?> mb-4">
    <div class="d-flex justify-content-between align-items-start gap-3 flex-wrap">
        <div>
            <i class="fas <?php echo $isOfficialAttendanceMode ? 'fa-shield-halved' : 'fa-flask'; ?> me-2"></i>
            <strong>وضع الحضور الجديد: <?php echo htmlspecialchars($staffHrFlags->mode(), ENT_QUOTES, 'UTF-8'); ?>.</strong>
            <?php if ($isOfficialAttendanceMode): ?>
                هذه الصفحة تعرض السجل القديم للقراءة فقط؛ لا يُسمح بالحفظ أو الحذف المباشرين بعد اعتماد النتائج الجديدة رسميًا.
            <?php else: ?>
                هذا السطح ما زال يعرض ويحدّث السجل القديم للتوافق المرحلي فقط؛ لا تصبح النتائج الجديدة رسمية قبل الاعتماد الصريح.
            <?php endif; ?>
        </div>
        <a href="hr_attendance_exceptions.php?date_from=<?php echo rawurlencode($selectedDateForAttendanceReview); ?>&amp;date_to=<?php echo rawurlencode($selectedDateForAttendanceReview); ?>" class="btn btn-sm btn-outline-dark">
            <i class="fas fa-arrow-up-right-from-square me-1"></i>مراجعة الاستثناءات
        </a>
    </div>
    <?php if (is_array($attendanceExceptionSnapshot)): ?>
        <?php $exceptionSummary = (array)($attendanceExceptionSnapshot['summary'] ?? []); ?>
        <div class="mt-2 small">
            مراجعة يوم <?php echo htmlspecialchars($selectedDateForAttendanceReview, ENT_QUOTES, 'UTF-8'); ?>:
            <span class="badge bg-danger">بصمات خام: <?php echo (int)($exceptionSummary['raw_events'] ?? 0); ?></span>
            <span class="badge bg-warning text-dark">نتائج تحتاج حسمًا: <?php echo (int)($exceptionSummary['unresolved_days'] ?? 0); ?></span>
            <span class="badge bg-secondary">فروق انتقالية: <?php echo (int)($exceptionSummary['comparison_differences'] ?? 0); ?></span>
        </div>
    <?php elseif ($attendanceExceptionSnapshotError): ?>
        <div class="mt-2 small"><i class="fas fa-circle-info me-1"></i><?php echo htmlspecialchars($attendanceExceptionSnapshotError, ENT_QUOTES, 'UTF-8'); ?></div>
    <?php endif; ?>
</div>
<?php endif; ?>

<!-- Stat Cards -->
<?php renderHrStatCards($attendanceSummaryCards, $viewMode === 'daily' ? 'row-cols-2 row-cols-md-5' : 'row-cols-2 row-cols-md-4'); ?>

<?php if ($viewMode === 'daily'): ?>
<div class="row row-cols-1 row-cols-md-2 g-3 mb-4">
    <div class="col">
        <div class="alert alert-info mb-0">
            <i class="fas fa-business-time me-2"></i>
            الدوام المعتمد: من <strong><?php echo htmlspecialchars($shiftStart); ?></strong> إلى <strong><?php echo htmlspecialchars($shiftEnd); ?></strong>
            <span class="mx-2">|</span>
            فترة السماح: <strong><?php echo (int)$shiftGraceMinutes; ?> دقيقة</strong>
        </div>
    </div>
    <div class="col">
        <div class="alert alert-warning mb-0">
            <i class="fas fa-user-clock me-2"></i>
            غير مسجلين حتى الآن: <strong><?php echo (int)$dailyStatsData['unregistered']; ?></strong>
        </div>
    </div>
</div>

<?php if (!empty($missingRegistrationStaff)): ?>
<div class="alert alert-danger mb-4">
    <div class="d-flex justify-content-between align-items-center flex-wrap gap-2">
        <div>
            <i class="fas fa-bell me-2"></i>
            <strong>تنبيه:</strong> يوجد <?php echo count($missingRegistrationStaff); ?> موظف لم يسجل حضوره بعد مرور وقت السماح من بداية الدوام.
        </div>
    </div>
    <div class="mt-2 small">
        <?php
        $missingNames = array_map(static function ($s) {
            return htmlspecialchars($s['name'], ENT_QUOTES, 'UTF-8');
        }, $missingRegistrationStaff);
        echo implode(' - ', $missingNames);
        ?>
    </div>
</div>
<?php endif; ?>
<?php endif; ?>

<?php if ($viewMode === 'daily'): ?>
<!-- تسجيل الحضور اليومي -->
<div class="card shadow">
    <div class="card-header bg-primary text-white">
        <div class="row align-items-center">
            <div class="col-md-6">
                <h5 class="mb-0"><i class="fas fa-calendar-check me-2"></i>تسجيل حضور يوم: <?php echo htmlspecialchars((string)$selectedDate, ENT_QUOTES, 'UTF-8'); ?></h5>
            </div>
            <div class="col-md-6">
                <div class="d-flex justify-content-end">
                    <form method="GET" class="d-flex gap-2 align-items-center flex-wrap justify-content-end">
                        <input type="hidden" name="view" value="daily">
                        <label class="text-white small me-1 text-nowrap">التاريخ:</label>
                        <input type="text" class="form-control form-control-sm flatpickr-date" name="date" value="<?php echo htmlspecialchars((string)$selectedDate, ENT_QUOTES, 'UTF-8'); ?>" style="width:auto; min-width:150px;">
                        <select class="form-select form-select-sm" name="stage_id" style="width:auto; min-width:130px;">
                            <option value="">كل المراحل</option>
                            <?php foreach ($stagesOptions as $st): ?>
                                <option value="<?php echo (int)$st['id']; ?>" <?php echo ((string)$filterStageId === (string)$st['id']) ? 'selected' : ''; ?>><?php echo htmlspecialchars($st['stage_name']); ?></option>
                            <?php endforeach; ?>
                        </select>
                        <select class="form-select form-select-sm" name="job_title" style="width:auto; min-width:150px;">
                            <option value="">كل المسميات</option>
                            <?php foreach ($jobTitleOptions as $jt): ?>
                                <option value="<?php echo htmlspecialchars($jt); ?>" <?php echo ($filterJobTitle === $jt) ? 'selected' : ''; ?>><?php echo htmlspecialchars($jt); ?></option>
                            <?php endforeach; ?>
                        </select>
                        <select class="form-select form-select-sm" name="user_id" style="width:auto; min-width:160px;">
                            <option value="">كل الموظفين</option>
                            <?php foreach ($staffList as $s): ?>
                                <option value="<?php echo (int)$s['id']; ?>" <?php echo ((string)$filterUser === (string)$s['id']) ? 'selected' : ''; ?>><?php echo htmlspecialchars($s['name']); ?></option>
                            <?php endforeach; ?>
                        </select>
                        <button type="submit" class="btn btn-light btn-sm"><i class="fas fa-search me-1"></i>تطبيق</button>
                    </form>
                </div>
            </div>
        </div>
    </div>
    <div class="card-body">
        <?php if ($isOfficialAttendanceMode): ?>
        <div class="alert alert-warning">
            <i class="fas fa-lock me-2"></i>
            تم تعطيل التعديل اليدوي على السجل القديم لأن وضع النتائج الرسمية الجديدة مفعّل. استخدم مسار التصحيح والمراجعة المعتمد.
        </div>
        <?php endif; ?>
        <form method="POST">
            <?php echo csrfField(); ?>
            <input type="hidden" name="attendance_date" value="<?php echo htmlspecialchars($selectedDate, ENT_QUOTES, 'UTF-8'); ?>">
            <div class="table-responsive">
                <table class="table table-hover table-striped">
                    <thead>
                        <tr>
                            <th width="40">#</th>
                            <th>الموظف</th>
                            <th width="170">موقف معتمد</th>
                            <th width="150">الحالة</th>
                            <th width="120">وقت الحضور</th>
                            <th width="120">وقت الانصراف</th>
                            <th width="100">دقائق التأخير</th>
                            <th>ملاحظات</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($staffList as $idx => $s):
                            $att = $dayAttendance[$s['id']] ?? null;
                            $uid = (int)$s['id'];
                            $leaveInfo = $approvedLeavesByUser[$uid] ?? null;
                            $permInfo = $approvedPermissionsByUser[$uid] ?? null;
                            $entryContext = $attendanceService->buildDailyEntryContext($att, $leaveInfo, $permInfo, $attendanceStatus);
                            $selectedStatus = $entryContext['selected_status'];
                            $noteValue = $entryContext['note_value'];
                            $approvedContext = $entryContext['approved'];
                        ?>
                        <tr>
                            <td><?php echo $idx + 1; ?></td>
                            <td><strong><?php echo htmlspecialchars($s['name']); ?></strong></td>
                            <td>
                                <?php if ($approvedContext): ?>
                                    <span class="badge bg-<?php echo htmlspecialchars($approvedContext['badge'], ENT_QUOTES, 'UTF-8'); ?>" data-bs-toggle="tooltip" title="<?php echo htmlspecialchars($approvedContext['tooltip'], ENT_QUOTES, 'UTF-8'); ?>">
                                        <i class="fas <?php echo htmlspecialchars($approvedContext['icon'], ENT_QUOTES, 'UTF-8'); ?> me-1"></i><?php echo htmlspecialchars($approvedContext['label'], ENT_QUOTES, 'UTF-8'); ?>
                                    </span>
                                <?php else: ?>
                                    <span class="text-muted small">لا يوجد</span>
                                <?php endif; ?>
                            </td>
                            <td>
                                <select class="form-select form-select-sm" name="staff_status[<?php echo $s['id']; ?>]" <?php echo $isOfficialAttendanceMode ? 'disabled aria-disabled="true"' : ''; ?>>
                                    <?php foreach ($attendanceStatus as $k => $v): ?>
                                        <option value="<?php echo $k; ?>" <?php echo $selectedStatus === $k ? 'selected' : ''; ?>><?php echo $v; ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </td>
                            <td><input type="time" class="form-control form-control-sm" name="check_in[<?php echo $s['id']; ?>]" value="<?php echo htmlspecialchars((string)($att['check_in'] ?? ''), ENT_QUOTES, 'UTF-8'); ?>" <?php echo $isOfficialAttendanceMode ? 'disabled aria-disabled="true"' : ''; ?>></td>
                            <td><input type="time" class="form-control form-control-sm" name="check_out[<?php echo $s['id']; ?>]" value="<?php echo htmlspecialchars((string)($att['check_out'] ?? ''), ENT_QUOTES, 'UTF-8'); ?>" <?php echo $isOfficialAttendanceMode ? 'disabled aria-disabled="true"' : ''; ?>></td>
                            <td><input type="number" class="form-control form-control-sm" name="late_minutes[<?php echo $s['id']; ?>]" min="0" value="<?php echo (int)($att['late_minutes'] ?? 0); ?>" <?php echo $isOfficialAttendanceMode ? 'disabled aria-disabled="true"' : ''; ?>></td>
                            <td><input type="text" class="form-control form-control-sm" name="att_notes[<?php echo $s['id']; ?>]" value="<?php echo htmlspecialchars((string)$noteValue, ENT_QUOTES, 'UTF-8'); ?>" <?php echo $isOfficialAttendanceMode ? 'disabled aria-disabled="true"' : ''; ?>></td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
            <div class="text-end mt-3">
                <button type="submit" name="save_bulk_attendance" class="btn btn-success btn-lg" <?php echo $isOfficialAttendanceMode ? 'disabled aria-disabled="true"' : ''; ?>>
                    <i class="fas <?php echo $isOfficialAttendanceMode ? 'fa-lock' : 'fa-save'; ?> me-1"></i><?php echo $isOfficialAttendanceMode ? 'السجل القديم للقراءة فقط' : 'حفظ الحضور'; ?>
                </button>
            </div>
        </form>
    </div>
</div>

<?php else: ?>
<!-- سجلات الحضور -->
<div class="card shadow">
    <div class="card-header bg-primary text-white">
        <div class="row align-items-center">
            <div class="col-md-3">
                <h5 class="mb-0"><i class="fas fa-clipboard-list me-2"></i>سجلات الحضور <span class="badge bg-light text-dark ms-2"><?php echo count($records); ?></span></h5>
            </div>
            <div class="col-md-9">
                <form method="GET" class="d-flex justify-content-end align-items-center gap-2 flex-wrap">
                    <input type="hidden" name="view" value="records">
                    <select class="form-select form-select-sm" name="user_id" style="width:auto; min-width:140px;">
                        <option value="">كل الموظفين</option>
                        <?php foreach ($staffList as $s): ?>
                            <option value="<?php echo $s['id']; ?>" <?php echo $filterUser == $s['id'] ? 'selected' : ''; ?>><?php echo htmlspecialchars($s['name']); ?></option>
                        <?php endforeach; ?>
                    </select>
                    <select class="form-select form-select-sm" name="stage_id" style="width:auto; min-width:130px;">
                        <option value="">كل المراحل</option>
                        <?php foreach ($stagesOptions as $st): ?>
                            <option value="<?php echo (int)$st['id']; ?>" <?php echo ((string)$filterStageId === (string)$st['id']) ? 'selected' : ''; ?>><?php echo htmlspecialchars($st['stage_name']); ?></option>
                        <?php endforeach; ?>
                    </select>
                    <select class="form-select form-select-sm" name="job_title" style="width:auto; min-width:150px;">
                        <option value="">كل المسميات</option>
                        <?php foreach ($jobTitleOptions as $jt): ?>
                            <option value="<?php echo htmlspecialchars($jt); ?>" <?php echo ($filterJobTitle === $jt) ? 'selected' : ''; ?>><?php echo htmlspecialchars($jt); ?></option>
                        <?php endforeach; ?>
                    </select>
                    <select class="form-select form-select-sm" name="filter_status" style="width:auto; min-width:100px;">
                        <option value="">كل الحالات</option>
                        <?php foreach ($attendanceStatus as $k => $v): ?>
                            <option value="<?php echo $k; ?>" <?php echo $filterStatus === $k ? 'selected' : ''; ?>><?php echo $v; ?></option>
                        <?php endforeach; ?>
                    </select>
                    <input type="text" class="form-control form-control-sm flatpickr-date" name="date_from" value="<?php echo htmlspecialchars($_GET['date_from'] ?? '', ENT_QUOTES, 'UTF-8'); ?>" style="width:auto;">
                    <input type="text" class="form-control form-control-sm flatpickr-date" name="date_to" value="<?php echo htmlspecialchars($_GET['date_to'] ?? '', ENT_QUOTES, 'UTF-8'); ?>" style="width:auto;">
                    <button type="submit" class="btn btn-light btn-sm"><i class="fas fa-search me-1"></i>بحث</button>
                </form>
            </div>
        </div>
    </div>
    <div class="card-body">
        <?php if (count($records) > 0): ?>
        <div class="table-responsive">
            <table class="table table-hover table-striped" id="attendanceTable">
                <thead>
                    <tr>
                        <th width="50">#</th>
                        <th>الموظف</th>
                        <th>التاريخ</th>
                        <th>الحالة</th>
                        <th>الحضور</th>
                        <th>الانصراف</th>
                        <th>تأخير (دقيقة)</th>
                        <th>ملاحظات</th>
                            <th width="80">الإجراء</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($records as $i => $r): ?>
                    <tr>
                        <td><?php echo $i + 1; ?></td>
                        <td><?php echo htmlspecialchars($r['staff_name']); ?></td>
                        <td><?php echo $r['attendance_date']; ?></td>
                        <td><span class="badge bg-<?php echo $attendanceBadges[$r['status']] ?? 'secondary'; ?>"><?php echo $attendanceStatus[$r['status']] ?? $r['status']; ?></span></td>
                        <td><?php echo $r['check_in'] ? substr($r['check_in'], 0, 5) : '-'; ?></td>
                        <td><?php echo $r['check_out'] ? substr($r['check_out'], 0, 5) : '-'; ?></td>
                        <td><?php echo $r['late_minutes'] ?: '-'; ?></td>
                        <td><?php echo htmlspecialchars($r['notes'] ?? '-', ENT_QUOTES, 'UTF-8'); ?></td>
                        <td>
                            <?php if ($isOfficialAttendanceMode): ?>
                                <span class="text-muted small">للقراءة فقط</span>
                            <?php else: ?>
                                <button type="button" class="btn btn-action-pills btn-delete open-delete-modal" data-bs-toggle="tooltip" title="حذف" data-id="<?php echo (int)$r['id']; ?>" data-name="<?php echo htmlspecialchars($r['staff_name'], ENT_QUOTES, 'UTF-8'); ?>" data-date="<?php echo htmlspecialchars($r['attendance_date'], ENT_QUOTES, 'UTF-8'); ?>">
                                    <i class="fas fa-trash"></i>
                                </button>
                            <?php endif; ?>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <?php renderPagination($recordsPagination); ?>
        <?php else: ?>
            <div class="alert alert-info"><i class="fas fa-info-circle me-2"></i>لا توجد سجلات حضور.</div>
        <?php endif; ?>
    </div>
</div>
<?php endif; ?>

<!-- Delete Confirmation Modal -->
<div class="modal fade" id="deleteAttendanceModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog">
        <div class="modal-content admin-modal admin-modal-premium admin-modal-delete">
            <form method="POST">
                <?php echo csrfField(); ?>
                <input type="hidden" name="id" id="deleteAttendanceId">
                <div class="modal-header">
                    <h5 class="modal-title"><i class="fas fa-trash me-2"></i>تأكيد حذف سجل الحضور</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <div class="text-center mb-3">
                        <i class="fas fa-exclamation-triangle text-danger" style="font-size: 3rem;"></i>
                    </div>
                    <p class="text-center">هل تريد حذف سجل الحضور للموظف <span class="fw-bold text-primary" id="deleteAttendanceName"></span> بتاريخ <span class="fw-bold" id="deleteAttendanceDate"></span>؟</p>
                    <div class="alert alert-warning mb-0"><i class="fas fa-info-circle me-2"></i>لا يمكن التراجع عن هذا الإجراء.</div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal"><i class="fas fa-times me-1"></i>إلغاء</button>
                    <button type="submit" name="delete_attendance" class="btn btn-danger"><i class="fas fa-trash me-1"></i>تأكيد الحذف</button>
                </div>
            </form>
        </div>
    </div>
</div>

<script>
document.addEventListener('DOMContentLoaded', function() {
    if (typeof $ !== 'undefined' && $.fn.DataTable) {
        $('#attendanceTable').DataTable({
            pageLength: 50,
            order: [[2, 'desc']],
            language: {
                url: '//cdn.datatables.net/plug-ins/1.13.6/i18n/ar.json',
                search: "بحث:", lengthMenu: "عرض _MENU_ سجل",
                info: "عرض _START_ إلى _END_ من _TOTAL_ سجل",
                paginate: { first: "الأول", last: "الأخير", next: "التالي", previous: "السابق" }
            }
        });
    }

    document.addEventListener('click', function(e) {
        const btn = e.target.closest('.open-delete-modal');
        if (!btn) return;
        document.getElementById('deleteAttendanceId').value = btn.getAttribute('data-id') || '';
        document.getElementById('deleteAttendanceName').textContent = btn.getAttribute('data-name') || '-';
        document.getElementById('deleteAttendanceDate').textContent = btn.getAttribute('data-date') || '-';
        const modalEl = document.getElementById('deleteAttendanceModal');
        if (modalEl && window.bootstrap) {
            new bootstrap.Modal(modalEl).show();
        }
    });
});
</script>

<?php require_once '../includes/admin_footer.php'; ?>
