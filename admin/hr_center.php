<?php
/**
 * مركز شؤون العاملين
 * نقطة دخول موحدة لصفحات HR
 */
$page_title = "مركز شؤون العاملين";
$custom_page_title = true;

require_once '../config/database.php';
require_once '../classes/StaffAttendanceService.php';
require_once '../classes/StaffLeaveService.php';
require_once '../classes/StaffPermissionService.php';
require_once '../classes/utilities.php';
require_once '../includes/session_config.php';
Utilities::validateSession('admin');
require_once '../src/Modules/Operations/Audit/AuditService.php';
require_once '../src/Modules/Staff/bootstrap.php';
require_once '../src/Modules/Attendance/bootstrap.php';

use EduCore\Modules\Operations\Audit\AuditService;
use EduCore\Modules\Staff\Infrastructure\StaffHrFeatureFlags;
use EduCore\Modules\Staff\Infrastructure\StaffModuleFactory;
use EduCore\Modules\Staff\Presentation\ManagerApprovalInbox;
use EduCore\Modules\Attendance\Infrastructure\AttendanceModuleFactory;

$database = new Database();
$db = $database->getConnection();
$attendanceService = new StaffAttendanceService($db);
$leaveService = new StaffLeaveService($db);
$permissionService = new StaffPermissionService($db);
$staffHrFlags = StaffHrFeatureFlags::fromEnvironment();
$showsAssignedApprovals = $staffHrFlags->exposesNewResults();
$showsUnifiedStaffViews = $staffHrFlags->exposesNewResults();
$sharedAudit = null;
$staffFactory = null;
$staffTimelineQuery = null;
$documentExpiryService = null;
$unifiedStaffLoadError = null;
$approvalInboxQuery = null;
$approvalWorkflowService = null;
$approvalInboxLoadError = null;

if ($showsAssignedApprovals || $showsUnifiedStaffViews) {
    try {
        $sharedAudit = new AuditService($db);
        $staffFactory = new StaffModuleFactory($db, $sharedAudit);
        if ($showsUnifiedStaffViews) {
            $staffTimelineQuery = $staffFactory->staffTimeline();
            $documentExpiryService = $staffFactory->documentExpiryService();
        }
    } catch (Throwable $exception) {
        error_log('staff HR module services unavailable: ' . $exception->getMessage());
        $unifiedStaffLoadError = 'لا تتوفر مؤشرات التشغيل الموحّدة الآن. تحقق من تطبيق ترحيلات شؤون العاملين ثم أعد المحاولة.';
        $approvalInboxLoadError = 'لا تتوفر اعتماداتك المعيّنة الآن. تحقق من تطبيق ترحيلات شؤون العاملين ثم أعد المحاولة.';
    }
}

if ($showsAssignedApprovals && $staffFactory !== null && $sharedAudit !== null) {
    try {
        $attendanceFactory = new AttendanceModuleFactory($db, $sharedAudit);
        $approvalInboxQuery = $staffFactory->assignedApprovalInbox();
        $approvalWorkflowService = $staffFactory->approvalWorkflowService(
            $attendanceFactory->approvedCoverageChangeGateway()
        );
    } catch (Throwable $exception) {
        error_log('assigned approval services unavailable: ' . $exception->getMessage());
        $approvalInboxLoadError = 'لا تتوفر اعتماداتك المعيّنة الآن. تحقق من تطبيق ترحيلات شؤون العاملين ثم أعد المحاولة.';
    }
}
$attendanceService->ensureAttendanceAuditTable();
$attendanceService->ensureBiometricTables();
$attendanceService->ensureEmployeeCodeColumn();
$leaveService->ensureLeaveBalanceColumns();
$staffList = $permissionService->getActiveStaffList();

$activeTab = $_GET['tab'] ?? ($_POST['active_tab'] ?? 'dashboard');
$activeTab = $activeTab === 'credentials' ? 'timeline' : $activeTab;
$validTabs = ['dashboard', 'requests', 'integration'];
if ($showsAssignedApprovals) {
    $validTabs[] = 'assigned_approvals';
}
if ($showsUnifiedStaffViews) {
    $validTabs[] = 'timeline';
}
if (!in_array($activeTab, $validTabs, true)) {
    $activeTab = 'dashboard';
}

// فلتر اسم الموظف على صندوق الطلبات المعلقة
$filterPendingName = trim((string)($_GET['pending_name'] ?? ''));
$centerUserId = (int)($_GET['user_id'] ?? 0);
$centerStatus = trim((string)($_GET['status'] ?? ''));
$centerDateFrom = trim((string)($_GET['date_from'] ?? ''));
$centerDateTo = trim((string)($_GET['date_to'] ?? ''));

$cairoTimezone = new DateTimeZone('Africa/Cairo');
$timelineToday = new DateTimeImmutable('today', $cairoTimezone);
$timelineDateFrom = isset($_GET['timeline_from']) && is_string($_GET['timeline_from'])
    ? trim($_GET['timeline_from'])
    : $timelineToday->modify('first day of this month')->format('Y-m-d');
$timelineDateTo = isset($_GET['timeline_to']) && is_string($_GET['timeline_to'])
    ? trim($_GET['timeline_to'])
    : $timelineToday->format('Y-m-d');
$timelineUserInput = isset($_GET['timeline_user_id']) && is_string($_GET['timeline_user_id'])
    ? trim($_GET['timeline_user_id'])
    : '';
$timelineUserId = $timelineUserInput === '' ? 0 : (int) filter_var(
    $timelineUserInput,
    FILTER_VALIDATE_INT,
    ['options' => ['min_range' => 1]]
);
$timelineValidationError = null;
if ($timelineUserInput !== '' && $timelineUserId <= 0) {
    $timelineValidationError = 'اختيار العامل غير صالح. اختر عاملًا من القائمة ثم أعد المحاولة.';
    $timelineUserId = 0;
}

$parseTimelineDate = static function (string $value, DateTimeZone $timezone): ?DateTimeImmutable {
    $date = DateTimeImmutable::createFromFormat('!Y-m-d', $value, $timezone);
    $errors = DateTimeImmutable::getLastErrors();
    if ($date === false
        || ($errors !== false && ($errors['warning_count'] > 0 || $errors['error_count'] > 0))
        || $date->format('Y-m-d') !== $value
    ) {
        return null;
    }

    return $date;
};
$timelineFromDate = $parseTimelineDate($timelineDateFrom, $cairoTimezone);
$timelineToDate = $parseTimelineDate($timelineDateTo, $cairoTimezone);
if ($timelineFromDate === null || $timelineToDate === null || $timelineToDate < $timelineFromDate) {
    $timelineValidationError = 'الفترة غير صالحة. استخدم تاريخين صحيحين وتأكد أن تاريخ النهاية لا يسبق البداية.';
    $timelineFromDate = $timelineToday->modify('first day of this month');
    $timelineToDate = $timelineToday;
    $timelineDateFrom = $timelineFromDate->format('Y-m-d');
    $timelineDateTo = $timelineToDate->format('Y-m-d');
}

$overview           = $attendanceService->getHrCenterOverview();
$todayAttendance    = $overview['attendance_today'];
$pendingPermissions = $permissionService->getPendingPermissions(20);
$pendingLeaves      = $leaveService->getPendingLeaves(20);
$unsyncedLogs       = $attendanceService->getUnsyncedBiometricLogs(30);
$recentPermissionRows = $permissionService->getRecentPermissions([
    'user_id' => $centerUserId,
    'status' => $centerStatus,
    'date_from' => $centerDateFrom,
    'date_to' => $centerDateTo,
], 6);
$recentLeaveRows = $leaveService->getRecentLeaves([
    'user_id' => $centerUserId,
    'status' => $centerStatus,
    'date_from' => $centerDateFrom,
    'date_to' => $centerDateTo,
], 6);

// تطبيق فلتر الاسم
if ($filterPendingName !== '') {
    $pendingPermissions = array_filter($pendingPermissions, static function ($row) use ($filterPendingName) {
        return mb_stripos($row['staff_name'], $filterPendingName) !== false;
    });
    $pendingLeaves = array_filter($pendingLeaves, static function ($row) use ($filterPendingName) {
        return mb_stripos($row['staff_name'], $filterPendingName) !== false;
    });
}

$permissionTypeLabels = [
    'early_leave' => 'انصراف مبكر',
    'late_arrival' => 'تأخير',
    'errand' => 'مأمورية'
];
$leaveTypeLabels = [
    'regular' => 'اعتيادية',
    'sick' => 'مرضية',
    'casual' => 'عارضة',
    'exceptional' => 'استثنائية',
    'other' => 'أخرى'
];
$statusLabels = [
    'pending' => 'قيد المراجعة',
    'approved' => 'موافق عليه',
    'rejected' => 'مرفوض'
];
$statusBadges = [
    'pending' => 'warning',
    'approved' => 'success',
    'rejected' => 'danger'
];

// استرجاع رسائل الجلسة
$success_message = $_SESSION['success_message'] ?? null;
$error_message   = $_SESSION['error_message']   ?? null;
$approvalInboxFeedback = $_SESSION['approval_inbox_feedback'] ?? null;
unset($_SESSION['success_message'], $_SESSION['error_message'], $_SESSION['approval_inbox_feedback']);

// معالجة الموافقة/الرفض السريع (PRG)
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $redirectQuery = Utilities::buildQueryString(['pending_name', 'user_id', 'status', 'date_from', 'date_to', 'tab']);
    $csrfToken = $_POST['csrf_token'] ?? '';
    $isAssignedApprovalDecision = (string) ($_POST['approval_intent'] ?? '') === 'decide';
    if (!hash_equals($_SESSION['csrf_token'] ?? '', $csrfToken)) {
        if ($isAssignedApprovalDecision) {
            $_SESSION['approval_inbox_feedback'] = ['kind' => 'danger', 'code' => 'CSRF_INVALID'];
        } else {
            $_SESSION['error_message'] = 'خطأ في التحقق الأمني — لم تُنفَّذ العملية';
        }
        header('Location: hr_center.php' . $redirectQuery);
        exit();
    }

    $quickAction = $_POST['action'] ?? '';
    $itemId      = (int)($_POST['item_id'] ?? 0);
    $notes       = trim($_POST['notes'] ?? '');
    $approvedBy  = (int)($_SESSION['user_id'] ?? 0);

    try {
        if ($isAssignedApprovalDecision) {
            if (!$showsAssignedApprovals || $approvalWorkflowService === null) {
                throw new RuntimeException('APPROVAL_INBOX_UNAVAILABLE');
            }
            $result = $approvalWorkflowService->decide([
                'actor_id' => $approvedBy,
                'step_id' => (int) ($_POST['step_id'] ?? 0),
                'expected_lock_version' => (int) ($_POST['expected_lock_version'] ?? 0),
                'decision' => (string) ($_POST['decision'] ?? ''),
                'comment' => $_POST['comment'] ?? null,
                'idempotency_key' => (string) ($_POST['idempotency_key'] ?? ''),
                'decided_at' => new DateTimeImmutable('now', new DateTimeZone('Africa/Cairo')),
            ]);
            $_SESSION['approval_inbox_feedback'] = [
                'kind' => 'success',
                'message' => ($result['replayed'] ?? false)
                    ? 'تم استرجاع نتيجة القرار السابق دون تكرار الاعتماد.'
                    : 'تم حفظ قرار الاعتماد بنجاح.',
            ];
        } elseif ($quickAction === 'quick_approve_permission' && $itemId > 0) {
            $permissionService->changePermissionStatus($itemId, 'approved', $approvedBy, $notes);
            $_SESSION['success_message'] = 'تمت الموافقة على الإذن بنجاح';
        } elseif ($quickAction === 'quick_reject_permission' && $itemId > 0) {
            $permissionService->changePermissionStatus($itemId, 'rejected', $approvedBy, $notes);
            $_SESSION['success_message'] = 'تم رفض الإذن';
        } elseif ($quickAction === 'quick_approve_leave' && $itemId > 0) {
            $leaveService->changeLeaveStatus($itemId, 'approved', $approvedBy, $notes);
            $_SESSION['success_message'] = 'تمت الموافقة على الإجازة بنجاح';
        } elseif ($quickAction === 'quick_reject_leave' && $itemId > 0) {
            $leaveService->changeLeaveStatus($itemId, 'rejected', $approvedBy, $notes);
            $_SESSION['success_message'] = 'تم رفض طلب الإجازة';
        }
    } catch (Throwable $e) {
        if ($isAssignedApprovalDecision) {
            $_SESSION['approval_inbox_feedback'] = ['kind' => 'danger', 'code' => $e->getMessage()];
        } else {
            $_SESSION['error_message'] = $e->getMessage();
        }
    }

    header('Location: hr_center.php' . $redirectQuery);
    exit();
}

$assignedApprovalInbox = ['items' => [], 'total' => 0];
if ($showsAssignedApprovals && $approvalInboxQuery !== null) {
    try {
        $assignedApprovalInbox = $approvalInboxQuery->forAssignee(
            (int) ($_SESSION['user_id'] ?? 0),
            ['resource_type' => 'permission_request', 'per_page' => 25]
        );
        $assignedApprovalInbox['items'] = array_map(static function (array $item): array {
            return $item + [
                'actions' => [
                    'approve' => 'approval-decide:' . bin2hex(random_bytes(24)),
                    'reject' => 'approval-decide:' . bin2hex(random_bytes(24)),
                ],
            ];
        }, $assignedApprovalInbox['items']);
    } catch (Throwable $exception) {
        error_log('assigned approval inbox unavailable: ' . $exception->getMessage());
        $assignedApprovalInbox = ['items' => [], 'total' => 0];
        $approvalInboxLoadError = 'لا يمكن تحميل الاعتمادات المعيّنة الآن. تحقق من تطبيق ترحيلات شؤون العاملين ثم أعد المحاولة.';
    }
}
$assignedApprovalTotal = (int) ($assignedApprovalInbox['total'] ?? 0);
$legacyQuickActionsAvailable = !$showsAssignedApprovals || $staffHrFlags->usesLegacyFallback();

$staffNamesById = [];
foreach ($staffList as $staffMember) {
    $staffNamesById[(int) $staffMember['id']] = (string) $staffMember['name'];
}
if ($timelineUserId > 0 && !isset($staffNamesById[$timelineUserId])) {
    $timelineValidationError = 'العامل المختار غير متاح ضمن قائمة العاملين الفعالين.';
    $timelineUserId = 0;
}

$staffTimeline = ['events' => [], 'warnings' => [], 'has_more' => false];
$credentialAlerts = [];
$timelineLoadError = null;
$credentialAlertLoadError = null;
if ($showsUnifiedStaffViews && $activeTab === 'timeline') {
    if ($documentExpiryService !== null) {
        try {
            $credentialAlerts = $documentExpiryService->expiryAlerts(
                new DateTimeImmutable('now', $cairoTimezone),
                30,
                100
            );
        } catch (Throwable $exception) {
            error_log('staff credential expiry alerts unavailable: ' . $exception->getMessage());
            $credentialAlertLoadError = 'تعذر تحميل تنبيهات انتهاء الوثائق الآن. لا يؤثر ذلك على بيانات العاملين المحفوظة.';
        }
    }

    if ($timelineUserId > 0 && $timelineValidationError === null && $staffTimelineQuery !== null) {
        try {
            $staffTimeline = $staffTimelineQuery->forStaff(
                $timelineUserId,
                $timelineFromDate,
                $timelineToDate->modify('+1 day'),
                100
            );
        } catch (Throwable $exception) {
            error_log('staff timeline unavailable: ' . $exception->getMessage());
            $timelineLoadError = 'تعذر تحميل الخط الزمني للعامل الآن. تحقق من الفترة ثم أعد المحاولة.';
        }
    }
}

$timelineSourceLabels = [
    'assignments' => 'التعيينات الوظيفية',
    'credentials' => 'المؤهلات والوثائق',
];
$timelineEventLabels = [
    'staff.assignment.effective' => 'بدء سريان تعيين وظيفي',
    'staff.credential.qualification' => 'تسجيل مؤهل',
    'staff.credential.training' => 'تسجيل تدريب',
    'staff.credential.document' => 'تسجيل وثيقة',
];
$timelineWarningLabels = [
    'source_unavailable' => 'المصدر غير متاح مؤقتًا',
    'invalid_source_response' => 'استجابة المصدر غير صالحة',
    'source_limit_exceeded' => 'تجاوز المصدر حد النتائج الآمن',
    'invalid_event' => 'تم تجاهل حدث غير صالح',
    'event_outside_window' => 'تم تجاهل حدث خارج الفترة',
    'duplicate_event' => 'تم تجاهل حدث مكرر',
];
$credentialKindLabels = [
    'qualification' => 'مؤهل',
    'training' => 'تدريب',
    'document' => 'وثيقة',
];
$credentialExpiryLabels = [
    'expired' => 'منتهية',
    'expires_today' => 'تنتهي اليوم',
    'expires_soon' => 'تنتهي قريبًا',
];
$credentialExpiryBadges = [
    'expired' => 'danger',
    'expires_today' => 'warning',
    'expires_soon' => 'info',
];
$credentialVerificationLabels = [
    'unverified' => 'غير متحقق منها',
    'verified' => 'متحقق منها',
    'rejected' => 'مرفوضة',
];

require_once '../includes/admin_header.php';
require_once '../includes/widgets/hr_stat_cards.php';

$hrOverviewCards = [
    [
        'value' => (int)$overview['staff_count'],
        'label' => 'عدد العاملين الفعالين',
        'icon' => 'fa-users',
        'gradient' => '#3b82f6, #2563eb'
    ],
    [
        'value' => $showsAssignedApprovals ? $assignedApprovalTotal : (int)$overview['pending_permissions'],
        'label' => $showsAssignedApprovals ? 'اعتماداتي المعيّنة' : 'أذونات معلقة',
        'icon' => $showsAssignedApprovals ? 'fa-inbox' : 'fa-clock',
        'gradient' => '#f59e0b, #d97706'
    ],
    [
        'value' => (int)$overview['pending_leaves'],
        'label' => 'إجازات معلقة',
        'icon' => 'fa-calendar-check',
        'gradient' => '#10b981, #059669'
    ],
    [
        'value' => (int)$overview['custom_shifts'],
        'label' => 'ورديات مخصصة',
        'icon' => 'fa-business-time',
        'gradient' => '#8b5cf6, #7c3aed'
    ],
];
?>



<div class="d-flex justify-content-between flex-wrap flex-md-nowrap align-items-center pt-3 pb-2 mb-4 border-bottom animate-up">
    <div>
        <h1 class="h2 fw-bold text-dark"><i class="fas fa-layer-group me-3 text-primary"></i>مركز شؤون العاملين</h1>
        <p class="text-muted m-0">نقطة انطلاق شاملة لإدارة الكادر الإداري والأكاديمي</p>
    </div>
    <div class="btn-toolbar mb-2 mb-md-0 gap-2">
        <?php if ($showsAssignedApprovals): ?>
            <?php echo ManagerApprovalInbox::renderDashboardCounter($assignedApprovalTotal, 'hr_center.php?tab=assigned_approvals'); ?>
        <?php endif; ?>
        <a href="hr_audit.php" class="btn btn-outline-secondary shadow-sm px-3 py-2"><i class="fas fa-shield-halved me-2"></i>تدقيق شؤون العاملين</a>
        <a href="hr_organization.php" class="btn btn-outline-primary shadow-sm px-3 py-2"><i class="fas fa-sitemap me-2"></i>الهيكل والتعيينات</a>
        <a href="staff.php" class="btn btn-outline-primary shadow-sm px-4 py-2"><i class="fas fa-users-cog me-2"></i>إدارة الموظفين</a>
    </div>
</div>

<?php if ($success_message): ?>
<div class="alert alert-success alert-dismissible fade show" role="alert">
    <i class="fas fa-check-circle me-2"></i><?php echo htmlspecialchars($success_message, ENT_QUOTES, 'UTF-8'); ?>
    <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
</div>
<?php endif; ?>
<?php if ($error_message): ?>
<div class="alert alert-danger alert-dismissible fade show" role="alert">
    <i class="fas fa-exclamation-triangle me-2"></i><?php echo htmlspecialchars($error_message, ENT_QUOTES, 'UTF-8'); ?>
    <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
</div>
<?php endif; ?>

<div class="premium-card bg-primary text-white p-4 mb-4 animate-up delay-1 overflow-hidden position-relative">
    <div class="row align-items-center g-3 position-relative" style="z-index: 2;">
        <div class="col-lg-8">
            <h2 class="h3 fw-bold mb-3">مرحباً بك في وحدة الموارد البشرية المتكاملة</h2>
            <p class="mb-0 opacity-90 fs-6">هذه الواحدة تتيح لك مراقبة الحضور، الانصراف، الأذونات، والورديات بشكل ذكي ومباشر، مع إمكانية المزامنة اللحظية مع أجهزة البصمة.</p>
        </div>
        <div class="col-lg-4 text-lg-end">
            <div class="glass-bg-light p-3 rounded-4 d-inline-block border border-white border-opacity-25">
                <div class="small opacity-75">تاريخ مراجعة اليوم</div>
                <div class="h5 fw-bold mb-0"><?php echo Utilities::formatDateArabic(date('Y-m-d')); ?></div>
            </div>
        </div>
    </div>

</div>

<?php renderHrStatCards($hrOverviewCards); ?>

<div class="row row-cols-2 row-cols-md-3 row-cols-xl-4 g-3 mb-4">
    <div class="col"><a class="hr-quick-link" href="staff_attendance.php"><span class="hr-quick-icon" style="background: linear-gradient(135deg,#0ea5e9,#0284c7)"><i class="fas fa-user-clock"></i></span><h6 class="mb-1">الحضور اليومي</h6><div class="text-muted small">تسجيل ومتابعة اليوم الحالي</div></a></div>
    <div class="col"><a class="hr-quick-link" href="permissions.php"><span class="hr-quick-icon" style="background: linear-gradient(135deg,#f59e0b,#d97706)"><i class="fas fa-clock"></i></span><h6 class="mb-1">الأذونات</h6><div class="text-muted small">اعتماد ومراجعة أذونات العاملين</div></a></div>
    <div class="col"><a class="hr-quick-link" href="leaves.php"><span class="hr-quick-icon" style="background: linear-gradient(135deg,#10b981,#059669)"><i class="fas fa-calendar-check"></i></span><h6 class="mb-1">الإجازات</h6><div class="text-muted small">إدارة الرصيد والطلبات</div></a></div>
    <div class="col"><a class="hr-quick-link" href="staff_shifts.php"><span class="hr-quick-icon" style="background: linear-gradient(135deg,#3b82f6,#2563eb)"><i class="fas fa-business-time"></i></span><h6 class="mb-1">الورديات</h6><div class="text-muted small">الدوام الافتراضي والمخصص</div></a></div>
    <div class="col"><a class="hr-quick-link" href="staff_biometric_import.php"><span class="hr-quick-icon" style="background: linear-gradient(135deg,#f97316,#ea580c)"><i class="fas fa-fingerprint"></i></span><h6 class="mb-1">استيراد البصمة</h6><div class="text-muted small">معاينة ثم تأكيد ثم مزامنة</div></a></div>
    <div class="col"><a class="hr-quick-link" href="staff_attendance_reports.php"><span class="hr-quick-icon" style="background: linear-gradient(135deg,#14b8a6,#0f766e)"><i class="fas fa-chart-line"></i></span><h6 class="mb-1">التقارير</h6><div class="text-muted small">يومي وتأخيرات وأجندة شهرية</div></a></div>
    <div class="col"><a class="hr-quick-link" href="staff_attendance_audit.php"><span class="hr-quick-icon" style="background: linear-gradient(135deg,#ef4444,#dc2626)"><i class="fas fa-history"></i></span><h6 class="mb-1">سجل التدقيق</h6><div class="text-muted small">كل التعديلات اليدوية ومزامنة البصمة</div></a></div>
    <div class="col"><a class="hr-quick-link" href="staff.php"><span class="hr-quick-icon" style="background: linear-gradient(135deg,#64748b,#334155)"><i class="fas fa-id-card"></i></span><h6 class="mb-1">ملفات العاملين</h6><div class="text-muted small">البيانات الأساسية والوظيفية</div></a></div>
</div>

<div class="hr-tab-nav nav animate-up delay-3" id="hrCenterTabs" role="tablist">
    <button class="nav-link premium-tab-btn <?php echo $activeTab === 'dashboard' ? 'active' : ''; ?>" id="tab-dashboard" data-bs-toggle="pill" data-bs-target="#pane-dashboard" type="button" role="tab" aria-controls="pane-dashboard" aria-selected="<?php echo $activeTab === 'dashboard' ? 'true' : 'false'; ?>">
        <i class="fas fa-chart-pie me-2"></i>لوحة التشغيل
    </button>
    <button class="nav-link premium-tab-btn <?php echo $activeTab === 'requests' ? 'active' : ''; ?>" id="tab-requests" data-bs-toggle="pill" data-bs-target="#pane-requests" type="button" role="tab" aria-controls="pane-requests" aria-selected="<?php echo $activeTab === 'requests' ? 'true' : 'false'; ?>">
        <i class="fas fa-inbox me-2"></i>الطلبات والموافقات
    </button>
    <?php if ($showsAssignedApprovals): ?>
        <button class="nav-link premium-tab-btn <?php echo $activeTab === 'assigned_approvals' ? 'active' : ''; ?>" id="tab-assigned-approvals" data-bs-toggle="pill" data-bs-target="#pane-assigned-approvals" type="button" role="tab" aria-controls="pane-assigned-approvals" aria-selected="<?php echo $activeTab === 'assigned_approvals' ? 'true' : 'false'; ?>">
            <i class="fas fa-user-check me-2"></i>اعتماداتي المعيّنة
        </button>
    <?php endif; ?>
    <?php if ($showsUnifiedStaffViews): ?>
        <button class="nav-link premium-tab-btn <?php echo $activeTab === 'timeline' ? 'active' : ''; ?>" id="tab-timeline" data-bs-toggle="pill" data-bs-target="#pane-timeline" type="button" role="tab" aria-controls="pane-timeline" aria-selected="<?php echo $activeTab === 'timeline' ? 'true' : 'false'; ?>">
            <i class="fas fa-wave-square me-2"></i>الخط الزمني والتنبيهات
        </button>
    <?php endif; ?>
    <button class="nav-link premium-tab-btn <?php echo $activeTab === 'integration' ? 'active' : ''; ?>" id="tab-integration" data-bs-toggle="pill" data-bs-target="#pane-integration" type="button" role="tab" aria-controls="pane-integration" aria-selected="<?php echo $activeTab === 'integration' ? 'true' : 'false'; ?>">
        <i class="fas fa-link me-2"></i>التكامل والمتابعة
    </button>
</div>

<div class="tab-content">
    <div class="tab-pane fade <?php echo $activeTab === 'dashboard' ? 'show active' : ''; ?>" id="pane-dashboard" role="tabpanel" aria-labelledby="tab-dashboard" tabindex="0">
        <div class="premium-card mb-4 animate-up delay-4">
            <div class="card-header bg-white py-3">
                <div class="row align-items-center">
                    <div class="col-md-3"><h5 class="mb-0 fw-bold"><i class="fas fa-filter me-2 text-primary"></i>فلاتر مركز HR</h5></div>
                    <div class="col-md-9">
                        <form method="get" class="d-flex justify-content-end align-items-center gap-2 flex-wrap">
                            <input type="hidden" name="tab" class="active-tab-input" value="<?php echo htmlspecialchars($activeTab, ENT_QUOTES, 'UTF-8'); ?>">
                            <select class="form-select form-select-sm" name="user_id" style="width:auto; min-width:170px;">
                                <option value="0">كل الموظفين</option>
                                <?php foreach ($staffList as $staffMember): ?>
                                    <option value="<?php echo (int)$staffMember['id']; ?>" <?php echo $centerUserId === (int)$staffMember['id'] ? 'selected' : ''; ?>><?php echo htmlspecialchars($staffMember['name'], ENT_QUOTES, 'UTF-8'); ?></option>
                                <?php endforeach; ?>
                            </select>
                            <select class="form-select form-select-sm" name="status" style="width:auto; min-width:130px;">
                                <option value="">كل الحالات</option>
                                <?php foreach ($statusLabels as $statusKey => $statusLabel): ?>
                                    <option value="<?php echo htmlspecialchars($statusKey, ENT_QUOTES, 'UTF-8'); ?>" <?php echo $centerStatus === $statusKey ? 'selected' : ''; ?>><?php echo htmlspecialchars($statusLabel, ENT_QUOTES, 'UTF-8'); ?></option>
                                <?php endforeach; ?>
                            </select>
                            <input type="text" class="form-control form-control-sm flatpickr-date" name="date_from" value="<?php echo htmlspecialchars($centerDateFrom, ENT_QUOTES, 'UTF-8'); ?>" style="width:auto;">
                            <input type="text" class="form-control form-control-sm flatpickr-date" name="date_to" value="<?php echo htmlspecialchars($centerDateTo, ENT_QUOTES, 'UTF-8'); ?>" style="width:auto;">
                            <input type="text" name="pending_name" class="form-control form-control-sm" style="width:160px;" placeholder="بحث باسم الموظف…" value="<?php echo htmlspecialchars($filterPendingName, ENT_QUOTES, 'UTF-8'); ?>">
                            <button type="submit" class="btn btn-light btn-sm px-3 shadow-sm"><i class="fas fa-search me-1"></i>تطبيق</button>
                        </form>
                    </div>
                </div>
            </div>
        </div>

        <div class="row g-4">
            <div class="col-lg-6 animate-up delay-5">
                <div class="premium-card h-100">
                    <div class="card-header bg-white py-3 border-bottom">
                        <h5 class="mb-0 fw-bold"><i class="fas fa-calendar-day me-2 text-success"></i>ملخص اليوم</h5>
                    </div>
                    <div class="card-body">
                        <div class="table-responsive">
                            <table class="table table-hover mb-0">
                                <tbody>
                                    <tr><th class="border-0">حاضر</th><td class="border-0"><span class="badge bg-success-subtle text-success px-3"><?php echo (int)$todayAttendance['present']; ?></span></td></tr>
                                    <tr><th>متأخر</th><td><span class="badge bg-warning-subtle text-warning px-3"><?php echo (int)$todayAttendance['late']; ?></span></td></tr>
                                    <tr><th>غائب</th><td><span class="badge bg-danger-subtle text-danger px-3"><?php echo (int)$todayAttendance['absent']; ?></span></td></tr>
                                    <tr><th>بعذر</th><td><span class="badge bg-info-subtle text-info px-3"><?php echo (int)$todayAttendance['excused']; ?></span></td></tr>
                                    <tr><th>سجلات بصمة اليوم</th><td><span class="badge bg-primary-subtle text-primary px-3"><?php echo (int)$overview['biometric_logs_today']; ?></span></td></tr>
                                    <tr><th>تعديلات مراجعة</th><td><span class="badge bg-secondary-subtle text-secondary px-3"><?php echo (int)$overview['audit_changes_today']; ?></span></td></tr>
                                </tbody>
                            </table>
                        </div>
                    </div>
                </div>
            </div>
            <div class="col-lg-6">
                <div class="card shadow h-100">
                    <div class="card-header bg-primary text-white">
                        <h5 class="mb-0"><i class="fas fa-sitemap me-2"></i>تصور التوحيد العملي</h5>
                    </div>
                    <div class="card-body">
                        <div class="alert alert-info">
                            <i class="fas fa-info-circle me-2"></i>المركز أصبح الآن يدعم فلترة موحدة ومراجعة سريعة، بينما انتقلت عمليات الموافقات في الأذونات والإجازات إلى طبقة الخدمات بدل التنفيذ المباشر من الصفحة.
                        </div>
                        <ol class="mb-0">
                            <li>جعل هذا المركز نقطة الدخول الافتراضية لكل مهام HR.</li>
                            <li>توحيد الفلاتر المشتركة: الموظف، التاريخ، الحالة.</li>
                            <li>عرض أحدث الطلبات ضمن لوحة تشغيل واحدة بدل التنقل المتشعب.</li>
                            <li>توحيد عمليات الكتابة داخل Services بدل الصفحة.</li>
                            <li>فصل المراجعة السريعة عن صفحات التفاصيل الثقيلة.</li>
                            <li>الاحتفاظ بالصفحات التخصصية للشاشات التفصيلية الثقيلة.</li>
                        </ol>
                    </div>
                </div>
            </div>
        </div>

        <div class="row g-4 mt-0">
            <div class="col-lg-6">
                <div class="card shadow h-100">
                    <div class="card-header bg-primary text-white d-flex justify-content-between align-items-center">
                        <h5 class="mb-0"><i class="fas fa-history me-2"></i>أحدث الأذونات</h5>
                        <span class="badge bg-light text-dark"><?php echo count($recentPermissionRows); ?> سجل</span>
                    </div>
                    <div class="card-body">
                        <?php if (!empty($recentPermissionRows)): ?>
                            <div class="table-responsive">
                                <table class="table table-sm table-hover table-striped align-middle mb-0">
                                    <thead>
                                        <tr>
                                            <th>الموظف</th>
                                            <th>النوع</th>
                                            <th>التاريخ</th>
                                            <th>الحالة</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php foreach ($recentPermissionRows as $recentPermission): ?>
                                            <tr>
                                                <td><?php echo htmlspecialchars($recentPermission['staff_name'], ENT_QUOTES, 'UTF-8'); ?></td>
                                                <td><?php echo htmlspecialchars($permissionTypeLabels[$recentPermission['permission_type']] ?? $recentPermission['permission_type'], ENT_QUOTES, 'UTF-8'); ?></td>
                                                <td><?php echo htmlspecialchars($recentPermission['permission_date'], ENT_QUOTES, 'UTF-8'); ?></td>
                                                <td><span class="badge bg-<?php echo htmlspecialchars($statusBadges[$recentPermission['status']] ?? 'secondary', ENT_QUOTES, 'UTF-8'); ?>"><?php echo htmlspecialchars($statusLabels[$recentPermission['status']] ?? $recentPermission['status'], ENT_QUOTES, 'UTF-8'); ?></span></td>
                                            </tr>
                                        <?php endforeach; ?>
                                    </tbody>
                                </table>
                            </div>
                        <?php else: ?>
                            <div class="alert alert-info mb-0 py-2"><i class="fas fa-info-circle me-2"></i>لا توجد أذونات تطابق الفلاتر الحالية.</div>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
            <div class="col-lg-6">
                <div class="card shadow h-100">
                    <div class="card-header bg-primary text-white d-flex justify-content-between align-items-center">
                        <h5 class="mb-0"><i class="fas fa-history me-2"></i>أحدث الإجازات</h5>
                        <span class="badge bg-light text-dark"><?php echo count($recentLeaveRows); ?> سجل</span>
                    </div>
                    <div class="card-body">
                        <?php if (!empty($recentLeaveRows)): ?>
                            <div class="table-responsive">
                                <table class="table table-sm table-hover table-striped align-middle mb-0">
                                    <thead>
                                        <tr>
                                            <th>الموظف</th>
                                            <th>النوع</th>
                                            <th>الفترة</th>
                                            <th>الحالة</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php foreach ($recentLeaveRows as $recentLeave): ?>
                                            <tr>
                                                <td><?php echo htmlspecialchars($recentLeave['staff_name'], ENT_QUOTES, 'UTF-8'); ?></td>
                                                <td><?php echo htmlspecialchars($leaveTypeLabels[$recentLeave['leave_type']] ?? $recentLeave['leave_type'], ENT_QUOTES, 'UTF-8'); ?></td>
                                                <td><?php echo htmlspecialchars($recentLeave['start_date'], ENT_QUOTES, 'UTF-8'); ?> - <?php echo htmlspecialchars($recentLeave['end_date'], ENT_QUOTES, 'UTF-8'); ?></td>
                                                <td><span class="badge bg-<?php echo htmlspecialchars($statusBadges[$recentLeave['status']] ?? 'secondary', ENT_QUOTES, 'UTF-8'); ?>"><?php echo htmlspecialchars($statusLabels[$recentLeave['status']] ?? $recentLeave['status'], ENT_QUOTES, 'UTF-8'); ?></span></td>
                                            </tr>
                                        <?php endforeach; ?>
                                    </tbody>
                                </table>
                            </div>
                        <?php else: ?>
                            <div class="alert alert-info mb-0 py-2"><i class="fas fa-info-circle me-2"></i>لا توجد إجازات تطابق الفلاتر الحالية.</div>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <div class="tab-pane fade <?php echo $activeTab === 'requests' ? 'show active' : ''; ?>" id="pane-requests" role="tabpanel" aria-labelledby="tab-requests" tabindex="0">
        <div class="card shadow mb-4">
            <div class="card-header bg-primary text-white">
                <div class="d-flex justify-content-between align-items-center flex-wrap gap-2">
                    <h5 class="mb-0"><i class="fas fa-inbox me-2"></i>صندوق العمليات المعلقة</h5>
                    <div class="d-flex align-items-center gap-2 flex-wrap">
                        <span class="badge bg-light text-dark"><?php echo count($pendingPermissions) + count($pendingLeaves); ?> عنصر</span>
                        <form method="get" class="d-flex gap-1 m-0">
                            <input type="hidden" name="tab" class="active-tab-input" value="<?php echo htmlspecialchars($activeTab, ENT_QUOTES, 'UTF-8'); ?>">
                            <input type="text" name="pending_name" class="form-control form-control-sm" style="width:160px;"
                                   placeholder="بحث باسم الموظف…"
                                   value="<?php echo htmlspecialchars($filterPendingName, ENT_QUOTES, 'UTF-8'); ?>">
                            <button type="submit" class="btn btn-light btn-sm"><i class="fas fa-search"></i></button>
                            <?php if ($filterPendingName !== ''): ?>
                            <a href="hr_center.php?tab=<?php echo urlencode($activeTab); ?>" class="btn btn-light btn-sm"><i class="fas fa-times"></i></a>
                            <?php endif; ?>
                        </form>
                    </div>
                </div>
            </div>
            <div class="card-body">
                <?php if (!$legacyQuickActionsAvailable): ?>
                    <div class="alert alert-info py-2">
                        <i class="fas fa-shield-halved me-2"></i>هذه قائمة توافقية للطلبات القديمة فقط. القرارات الجديدة تُتخذ من تبويب «اعتماداتي المعيّنة» وفق الإسناد الفعلي.
                    </div>
                <?php endif; ?>
                <h6 class="mb-3">الأذونات المعلقة</h6>
                <?php if (!empty($pendingPermissions)): ?>
                    <div class="table-responsive mb-4">
                        <table class="table table-sm table-striped align-middle mb-0">
                            <thead>
                                <tr>
                                    <th>الموظف</th>
                                    <th>النوع</th>
                                    <th>التاريخ</th>
                                    <th>إجراء</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($pendingPermissions as $permission): ?>
                                    <tr>
                                        <td><?php echo htmlspecialchars($permission['staff_name'], ENT_QUOTES, 'UTF-8'); ?></td>
                                        <td><?php echo htmlspecialchars($permissionTypeLabels[$permission['permission_type']] ?? $permission['permission_type'], ENT_QUOTES, 'UTF-8'); ?></td>
                                        <td><?php echo htmlspecialchars($permission['permission_date'], ENT_QUOTES, 'UTF-8'); ?></td>
                                        <td>
                                            <?php if ($legacyQuickActionsAvailable): ?>
                                                <button type="button" class="btn btn-action-pills btn-activate me-1 quick-approve"
                                                        data-action="quick_approve_permission"
                                                        data-id="<?php echo (int)$permission['id']; ?>"
                                                        data-name="<?php echo htmlspecialchars($permission['staff_name'], ENT_QUOTES, 'UTF-8'); ?>"
                                                        data-bs-toggle="tooltip" title="موافقة">
                                                    <i class="fas fa-check"></i>
                                                </button>
                                                <button type="button" class="btn btn-action-pills btn-delete me-1 quick-reject"
                                                        data-action="quick_reject_permission"
                                                        data-id="<?php echo (int)$permission['id']; ?>"
                                                        data-name="<?php echo htmlspecialchars($permission['staff_name'], ENT_QUOTES, 'UTF-8'); ?>"
                                                        data-bs-toggle="tooltip" title="رفض">
                                                    <i class="fas fa-times"></i>
                                                </button>
                                            <?php else: ?>
                                                <span class="text-secondary small">قرار مقيّد بالاعتماد المعيّن</span>
                                            <?php endif; ?>
                                            <a href="permissions.php?action=edit&id=<?php echo (int)$permission['id']; ?>"
                                               class="btn btn-action-pills btn-edit me-1" data-bs-toggle="tooltip" title="تفاصيل">
                                                <i class="fas fa-eye"></i>
                                            </a>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                <?php else: ?>
                    <div class="alert alert-success py-2"><i class="fas fa-check-circle me-2"></i>لا توجد أذونات معلقة حالياً.</div>
                <?php endif; ?>

                <h6 class="mb-3">الإجازات المعلقة</h6>
                <?php if (!empty($pendingLeaves)): ?>
                    <div class="table-responsive">
                        <table class="table table-sm table-striped align-middle mb-0">
                            <thead>
                                <tr>
                                    <th>الموظف</th>
                                    <th>النوع</th>
                                    <th>الفترة</th>
                                    <th>إجراء</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($pendingLeaves as $leave): ?>
                                    <tr>
                                        <td><?php echo htmlspecialchars($leave['staff_name'], ENT_QUOTES, 'UTF-8'); ?></td>
                                        <td><?php echo htmlspecialchars($leaveTypeLabels[$leave['leave_type']] ?? $leave['leave_type'], ENT_QUOTES, 'UTF-8'); ?></td>
                                        <td><?php echo htmlspecialchars($leave['start_date'], ENT_QUOTES, 'UTF-8'); ?> - <?php echo htmlspecialchars($leave['end_date'], ENT_QUOTES, 'UTF-8'); ?></td>
                                        <td>
                                            <?php if ($legacyQuickActionsAvailable): ?>
                                                <button type="button" class="btn btn-action-pills btn-activate me-1 quick-approve"
                                                        data-action="quick_approve_leave"
                                                        data-id="<?php echo (int)$leave['id']; ?>"
                                                        data-name="<?php echo htmlspecialchars($leave['staff_name'], ENT_QUOTES, 'UTF-8'); ?>"
                                                        data-bs-toggle="tooltip" title="موافقة">
                                                    <i class="fas fa-check"></i>
                                                </button>
                                                <button type="button" class="btn btn-action-pills btn-delete me-1 quick-reject"
                                                        data-action="quick_reject_leave"
                                                        data-id="<?php echo (int)$leave['id']; ?>"
                                                        data-name="<?php echo htmlspecialchars($leave['staff_name'], ENT_QUOTES, 'UTF-8'); ?>"
                                                        data-bs-toggle="tooltip" title="رفض">
                                                    <i class="fas fa-times"></i>
                                                </button>
                                            <?php else: ?>
                                                <span class="text-secondary small">قرار مقيّد بالاعتماد المعيّن</span>
                                            <?php endif; ?>
                                            <a href="leaves.php?action=edit&id=<?php echo (int)$leave['id']; ?>"
                                               class="btn btn-action-pills btn-edit me-1" data-bs-toggle="tooltip" title="تفاصيل">
                                                <i class="fas fa-eye"></i>
                                            </a>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                <?php else: ?>
                    <div class="alert alert-success py-2 mb-0"><i class="fas fa-check-circle me-2"></i>لا توجد إجازات معلقة حالياً.</div>
                <?php endif; ?>
            </div>
        </div>
    </div>

    <?php if ($showsUnifiedStaffViews): ?>
        <div class="tab-pane fade <?php echo $activeTab === 'timeline' ? 'show active' : ''; ?>" id="pane-timeline" role="tabpanel" aria-labelledby="tab-timeline" tabindex="0">
            <div class="card shadow admin-card-surface mb-4">
                <div class="card-header bg-primary text-white">
                    <div class="row align-items-center g-3">
                        <div class="col-lg-4">
                            <h5 class="mb-0"><i class="fas fa-wave-square me-2"></i>نبض التشغيل الآمن</h5>
                        </div>
                        <div class="col-lg-8">
                            <form method="get" class="d-flex justify-content-end align-items-center gap-2 flex-wrap">
                                <input type="hidden" name="tab" value="timeline">
                                <select class="form-select form-select-sm" name="timeline_user_id" aria-label="العامل">
                                    <option value="">اختر عاملًا لعرض خطه الزمني</option>
                                    <?php foreach ($staffList as $staffMember): ?>
                                        <option value="<?php echo (int) $staffMember['id']; ?>" <?php echo $timelineUserId === (int) $staffMember['id'] ? 'selected' : ''; ?>><?php echo htmlspecialchars($staffMember['name'], ENT_QUOTES, 'UTF-8'); ?></option>
                                    <?php endforeach; ?>
                                </select>
                                <input type="date" class="form-control form-control-sm" name="timeline_from" value="<?php echo htmlspecialchars($timelineDateFrom, ENT_QUOTES, 'UTF-8'); ?>" aria-label="من تاريخ">
                                <input type="date" class="form-control form-control-sm" name="timeline_to" value="<?php echo htmlspecialchars($timelineDateTo, ENT_QUOTES, 'UTF-8'); ?>" aria-label="إلى تاريخ">
                                <button type="submit" class="btn btn-light btn-sm"><i class="fas fa-search me-1"></i>عرض</button>
                            </form>
                        </div>
                    </div>
                </div>
                <div class="card-body">
                    <div class="alert alert-info" role="alert">
                        <i class="fas fa-shield-halved me-2"></i>يعرض هذا السطح ملخصات تشغيلية فقط: نوع الحدث ومعرّف المورد وحالته. لا يعرض أسباب الطلبات أو المرفقات أو محتوى الوثائق.
                    </div>

                    <?php if ($unifiedStaffLoadError !== null): ?>
                        <div class="alert alert-warning" role="alert"><i class="fas fa-triangle-exclamation me-2"></i><?php echo htmlspecialchars($unifiedStaffLoadError, ENT_QUOTES, 'UTF-8'); ?></div>
                    <?php endif; ?>
                    <?php if ($timelineValidationError !== null): ?>
                        <div class="alert alert-danger" role="alert"><i class="fas fa-circle-exclamation me-2"></i><?php echo htmlspecialchars($timelineValidationError, ENT_QUOTES, 'UTF-8'); ?></div>
                    <?php endif; ?>
                    <?php if ($timelineLoadError !== null): ?>
                        <div class="alert alert-warning" role="alert"><i class="fas fa-triangle-exclamation me-2"></i><?php echo htmlspecialchars($timelineLoadError, ENT_QUOTES, 'UTF-8'); ?></div>
                    <?php endif; ?>

                    <?php if ($timelineUserId <= 0 && $timelineValidationError === null): ?>
                        <div class="text-center py-5 text-secondary">
                            <i class="fas fa-user-clock fa-3x mb-3"></i>
                            <h6>اختر عاملًا وحدد الفترة</h6>
                            <p class="mb-0">ستظهر هنا التعيينات والمؤهلات والوثائق بترتيب زمني موحّد.</p>
                        </div>
                    <?php elseif ($timelineUserId > 0 && $timelineLoadError === null): ?>
                        <div class="d-flex justify-content-between align-items-center flex-wrap gap-2 mb-3">
                            <h6 class="mb-0"><i class="fas fa-user me-2 text-primary"></i><?php echo htmlspecialchars($staffNamesById[$timelineUserId], ENT_QUOTES, 'UTF-8'); ?></h6>
                            <div class="d-flex gap-2">
                                <span class="badge bg-primary"><?php echo count($staffTimeline['events']); ?> حدث</span>
                                <span class="badge bg-<?php echo empty($staffTimeline['warnings']) ? 'success' : 'warning'; ?>"><?php echo count($staffTimeline['warnings']); ?> تنبيه مصدر</span>
                            </div>
                        </div>

                        <?php if (!empty($staffTimeline['warnings'])): ?>
                            <div class="alert alert-warning" role="alert">
                                <div class="fw-semibold mb-2"><i class="fas fa-triangle-exclamation me-2"></i>اكتمل العرض مع تنبيهات من بعض المصادر:</div>
                                <ul class="mb-0">
                                    <?php foreach ($staffTimeline['warnings'] as $warning): ?>
                                        <li><?php echo htmlspecialchars($timelineSourceLabels[$warning['source']] ?? $warning['source'], ENT_QUOTES, 'UTF-8'); ?>: <?php echo htmlspecialchars($timelineWarningLabels[$warning['code']] ?? $warning['code'], ENT_QUOTES, 'UTF-8'); ?></li>
                                    <?php endforeach; ?>
                                </ul>
                            </div>
                        <?php endif; ?>

                        <?php if (!empty($staffTimeline['events'])): ?>
                            <div class="table-responsive">
                                <table class="table table-hover table-striped align-middle mb-0">
                                    <thead>
                                        <tr>
                                            <th>التاريخ</th>
                                            <th>المصدر</th>
                                            <th>الحدث</th>
                                            <th>المورد</th>
                                            <th>الحالة</th>
                                            <th>الإصدار</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php foreach ($staffTimeline['events'] as $event): ?>
                                            <tr>
                                                <td><?php echo htmlspecialchars($event['occurred_at']->format('Y-m-d H:i'), ENT_QUOTES, 'UTF-8'); ?></td>
                                                <td><?php echo htmlspecialchars($timelineSourceLabels[$event['source']] ?? $event['source'], ENT_QUOTES, 'UTF-8'); ?></td>
                                                <td><?php echo htmlspecialchars($timelineEventLabels[$event['event_type']] ?? $event['event_type'], ENT_QUOTES, 'UTF-8'); ?></td>
                                                <td><span class="badge bg-light text-dark"><?php echo htmlspecialchars($event['resource_type'], ENT_QUOTES, 'UTF-8'); ?> #<?php echo (int) $event['resource_id']; ?></span></td>
                                                <td><?php echo htmlspecialchars($event['status'], ENT_QUOTES, 'UTF-8'); ?></td>
                                                <td><?php echo $event['version'] === null ? '—' : (int) $event['version']; ?></td>
                                            </tr>
                                        <?php endforeach; ?>
                                    </tbody>
                                </table>
                            </div>
                            <?php if ($staffTimeline['has_more']): ?>
                                <div class="alert alert-info mt-3 mb-0"><i class="fas fa-circle-info me-2"></i>توجد أحداث إضافية. قلّص الفترة لعرضها ضمن الحد الآمن.</div>
                            <?php endif; ?>
                        <?php else: ?>
                            <div class="alert alert-light border mb-0"><i class="fas fa-circle-info me-2"></i>لا توجد أحداث تشغيلية للعامل ضمن الفترة المحددة.</div>
                        <?php endif; ?>
                    <?php endif; ?>
                </div>
            </div>

            <div class="card shadow admin-card-surface mb-4">
                <div class="card-header bg-primary text-white d-flex justify-content-between align-items-center flex-wrap gap-2">
                    <h5 class="mb-0"><i class="fas fa-certificate me-2"></i>وثائق تنتهي خلال 30 يومًا</h5>
                    <span class="badge bg-light text-dark"><?php echo count($credentialAlerts); ?> تنبيه</span>
                </div>
                <div class="card-body">
                    <?php if ($credentialAlertLoadError !== null): ?>
                        <div class="alert alert-warning mb-0"><i class="fas fa-triangle-exclamation me-2"></i><?php echo htmlspecialchars($credentialAlertLoadError, ENT_QUOTES, 'UTF-8'); ?></div>
                    <?php elseif (!empty($credentialAlerts)): ?>
                        <div class="table-responsive">
                            <table class="table table-hover table-striped align-middle mb-0">
                                <thead>
                                    <tr>
                                        <th>العامل</th>
                                        <th>النوع</th>
                                        <th>تاريخ الانتهاء</th>
                                        <th>التنبيه</th>
                                        <th>التحقق</th>
                                        <th>المرجع</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($credentialAlerts as $alert): ?>
                                        <tr>
                                            <td><?php echo htmlspecialchars($staffNamesById[$alert['staff_user_id']] ?? ('عامل #' . $alert['staff_user_id']), ENT_QUOTES, 'UTF-8'); ?></td>
                                            <td><?php echo htmlspecialchars($credentialKindLabels[$alert['credential_kind']] ?? $alert['credential_kind'], ENT_QUOTES, 'UTF-8'); ?></td>
                                            <td><?php echo htmlspecialchars($alert['expires_on'], ENT_QUOTES, 'UTF-8'); ?></td>
                                            <td><span class="badge bg-<?php echo htmlspecialchars($credentialExpiryBadges[$alert['expiry_state']] ?? 'secondary', ENT_QUOTES, 'UTF-8'); ?>"><?php echo htmlspecialchars($credentialExpiryLabels[$alert['expiry_state']] ?? $alert['expiry_state'], ENT_QUOTES, 'UTF-8'); ?></span></td>
                                            <td><?php echo htmlspecialchars($credentialVerificationLabels[$alert['verification_status']] ?? $alert['verification_status'], ENT_QUOTES, 'UTF-8'); ?></td>
                                            <td>#<?php echo (int) $alert['credential_id']; ?> · إصدار <?php echo (int) $alert['version']; ?></td>
                                        </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    <?php else: ?>
                        <div class="alert alert-success mb-0"><i class="fas fa-check-circle me-2"></i>لا توجد وثائق منتهية أو قاربت على الانتهاء ضمن نافذة التنبيه الحالية.</div>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    <?php endif; ?>

    <?php if ($showsAssignedApprovals): ?>
        <div class="tab-pane fade <?php echo $activeTab === 'assigned_approvals' ? 'show active' : ''; ?>" id="pane-assigned-approvals" role="tabpanel" aria-labelledby="tab-assigned-approvals" tabindex="0">
            <?php if ($approvalInboxLoadError !== null): ?>
                <div class="alert alert-warning" role="alert">
                    <i class="fas fa-triangle-exclamation me-2"></i><?php echo htmlspecialchars($approvalInboxLoadError, ENT_QUOTES, 'UTF-8'); ?>
                </div>
            <?php else: ?>
                <?php echo ManagerApprovalInbox::renderInbox([
                    'csrf_token' => (string) ($_SESSION['csrf_token'] ?? ''),
                    'action_url' => 'hr_center.php?tab=assigned_approvals',
                    'items' => $assignedApprovalInbox['items'],
                    'total' => $assignedApprovalTotal,
                    'feedback' => $approvalInboxFeedback,
                ]); ?>
            <?php endif; ?>
        </div>
    <?php endif; ?>

    <div class="tab-pane fade <?php echo $activeTab === 'integration' ? 'show active' : ''; ?>" id="pane-integration" role="tabpanel" aria-labelledby="tab-integration" tabindex="0">

<?php if (!empty($unsyncedLogs)): ?>
<div class="row g-4 mt-0">
    <div class="col-12">
        <div class="card shadow">
            <div class="card-header bg-warning text-dark">
                <div class="d-flex justify-content-between align-items-center">
                    <h5 class="mb-0"><i class="fas fa-exclamation-triangle me-2"></i>سجلات بصمة لم تُزامَن بعد</h5>
                    <span class="badge bg-dark"><?php echo count($unsyncedLogs); ?> صف غير مزامَن</span>
                </div>
            </div>
            <div class="card-body">
                <div class="alert alert-warning py-2 mb-3">
                    <i class="fas fa-info-circle me-2"></i>
                    هذه السجلات موجودة في قاعدة البصمة لكن ليس لها سجل حضور مقابل. اذهب إلى
                    <a href="staff_biometric_import.php" class="alert-link">صفحة البصمة</a> وأعد الاستيراد أو أضف الحضور يدوياً
                    من <a href="staff_attendance.php" class="alert-link">سجل الحضور</a>.
                </div>
                <div class="table-responsive">
                    <table class="table table-sm table-hover table-striped align-middle mb-0">
                        <thead>
                            <tr>
                                <th>الموظف</th>
                                <th>تاريخ السجل</th>
                                <th>أول تسجيل</th>
                                <th>آخر تسجيل</th>
                                <th>عدد السجلات</th>
                                <th>إجراء</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($unsyncedLogs as $ulog): ?>
                                <tr>
                                    <td><?php echo htmlspecialchars($ulog['staff_name'], ENT_QUOTES, 'UTF-8'); ?></td>
                                    <td><?php echo htmlspecialchars($ulog['log_date'], ENT_QUOTES, 'UTF-8'); ?></td>
                                    <td><?php echo htmlspecialchars(substr($ulog['first_log'], 11, 5), ENT_QUOTES, 'UTF-8'); ?></td>
                                    <td><?php echo htmlspecialchars(substr($ulog['last_log'], 11, 5), ENT_QUOTES, 'UTF-8'); ?></td>
                                    <td><span class="badge bg-secondary"><?php echo (int)$ulog['log_count']; ?></span></td>
                                    <td>
                                        <a href="staff_attendance.php?date=<?php echo urlencode($ulog['log_date']); ?>"
                                           class="btn btn-sm btn-outline-primary me-1" data-bs-toggle="tooltip" title="تسجيل يدوي">
                                            <i class="fas fa-pencil-alt"></i>
                                        </a>
                                        <a href="staff_biometric_import.php"
                                           class="btn btn-sm btn-outline-warning me-1" data-bs-toggle="tooltip" title="إعادة استيراد">
                                            <i class="fas fa-fingerprint"></i>
                                        </a>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </div>
</div>
<?php endif; ?>

<?php if (empty($unsyncedLogs)): ?>
<div class="card shadow">
    <div class="card-header bg-success text-white">
        <h5 class="mb-0"><i class="fas fa-check-circle me-2"></i>حالة التكامل</h5>
    </div>
    <div class="card-body">
        <div class="alert alert-success mb-0"><i class="fas fa-check-circle me-2"></i>لا توجد حالياً سجلات بصمة غير مزامنة، والتكامل يعمل ضمن الحدود الحالية.</div>
    </div>
</div>
<?php endif; ?>
    </div>
</div>

<!-- Modal: موافقة سريعة -->
<div class="modal fade" id="quickApproveModal" tabindex="-1" aria-labelledby="quickApproveModalLabel">
    <div class="modal-dialog">
        <div class="modal-content admin-modal admin-modal-premium admin-modal-create">
            <form method="post" action="hr_center.php<?php echo htmlspecialchars(Utilities::buildQueryString(['pending_name', 'user_id', 'status', 'date_from', 'date_to', 'tab']), ENT_QUOTES, 'UTF-8'); ?>">
                <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($_SESSION['csrf_token'] ?? '', ENT_QUOTES, 'UTF-8'); ?>">
                <input type="hidden" name="active_tab" class="active-tab-input" value="<?php echo htmlspecialchars($activeTab, ENT_QUOTES, 'UTF-8'); ?>">
                <input type="hidden" name="action" id="approveAction" value="">
                <input type="hidden" name="item_id" id="approveItemId" value="">
                <div class="modal-header">
                    <h5 class="modal-title" id="quickApproveModalLabel"><i class="fas fa-check-circle me-2"></i>تأكيد الاعتماد</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <div class="text-center mb-3"><i class="fas fa-check-circle text-success" style="font-size:3rem;"></i></div>
                    <p class="text-center mb-3">هل تريد اعتماد الطلب المتعلق بـ<span class="fw-bold text-primary" id="approveItemName"></span>؟</p>
                    <div class="mb-0">
                        <label class="form-label fw-semibold">ملاحظات (اختياري)</label>
                        <textarea class="form-control" name="notes" rows="2" placeholder="أدخل أي ملاحظات هنا..."></textarea>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal"><i class="fas fa-times me-1"></i>إلغاء</button>
                    <button type="submit" class="btn btn-success"><i class="fas fa-check me-1"></i>اعتماد</button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- Modal: رفض سريع -->
<div class="modal fade" id="quickRejectModal" tabindex="-1" aria-labelledby="quickRejectModalLabel">
    <div class="modal-dialog">
        <div class="modal-content admin-modal admin-modal-premium admin-modal-view">
            <form method="post" action="hr_center.php<?php echo htmlspecialchars(Utilities::buildQueryString(['pending_name', 'user_id', 'status', 'date_from', 'date_to', 'tab']), ENT_QUOTES, 'UTF-8'); ?>">
                <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($_SESSION['csrf_token'] ?? '', ENT_QUOTES, 'UTF-8'); ?>">
                <input type="hidden" name="active_tab" class="active-tab-input" value="<?php echo htmlspecialchars($activeTab, ENT_QUOTES, 'UTF-8'); ?>">
                <input type="hidden" name="action" id="rejectAction" value="">
                <input type="hidden" name="item_id" id="rejectItemId" value="">
                <div class="modal-header">
                    <h5 class="modal-title" id="quickRejectModalLabel"><i class="fas fa-times-circle me-2"></i>تأكيد الرفض</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <div class="text-center mb-3"><i class="fas fa-times-circle text-danger" style="font-size:3rem;"></i></div>
                    <p class="text-center mb-3">هل تريد رفض الطلب المتعلق بـ<span class="fw-bold text-primary" id="rejectItemName"></span>؟</p>
                    <div class="alert alert-warning py-2"><i class="fas fa-info-circle me-2"></i>يُنصح بإضافة سبب الرفض لتوثيق القرار.</div>
                    <div class="mb-0">
                        <label class="form-label fw-semibold">سبب الرفض (اختياري)</label>
                        <textarea class="form-control" name="notes" rows="2" placeholder="أدخل سبب الرفض هنا..."></textarea>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal"><i class="fas fa-times me-1"></i>إلغاء</button>
                    <button type="submit" class="btn btn-danger"><i class="fas fa-ban me-1"></i>رفض</button>
                </div>
            </form>
        </div>
    </div>
</div>

<script>
document.addEventListener('DOMContentLoaded', function () {
    var approveModal = new bootstrap.Modal(document.getElementById('quickApproveModal'));
    var rejectModal  = new bootstrap.Modal(document.getElementById('quickRejectModal'));
    var tabButtons = document.querySelectorAll('#hrCenterTabs [data-bs-toggle="pill"]');
    var activeTabInputs = document.querySelectorAll('.active-tab-input');
    var storedTab = sessionStorage.getItem('hr_center_active_tab');
    var url = new URL(window.location.href);
    var urlTab = url.searchParams.get('tab');

    function syncActiveTab(tabName) {
        activeTabInputs.forEach(function (input) {
            input.value = tabName;
        });
        url.searchParams.set('tab', tabName);
        window.history.replaceState({}, '', url);
        sessionStorage.setItem('hr_center_active_tab', tabName);
    }

    tabButtons.forEach(function (button) {
        button.addEventListener('shown.bs.tab', function () {
            syncActiveTab(this.getAttribute('data-bs-target').replace('#pane-', ''));
        });
    });

    if (!urlTab && storedTab) {
        var storedButton = document.querySelector('#hrCenterTabs [data-bs-target="#pane-' + storedTab + '"]');
        if (storedButton) {
            bootstrap.Tab.getOrCreateInstance(storedButton).show();
        }
    } else {
        syncActiveTab('<?php echo htmlspecialchars($activeTab, ENT_QUOTES, 'UTF-8'); ?>');
    }

    document.querySelectorAll('.quick-approve').forEach(function (btn) {
        btn.addEventListener('click', function () {
            document.getElementById('approveAction').value        = this.dataset.action;
            document.getElementById('approveItemId').value        = this.dataset.id;
            document.getElementById('approveItemName').textContent = this.dataset.name;
            document.querySelector('#quickApproveModal textarea[name="notes"]').value = '';
            approveModal.show();
        });
    });

    document.querySelectorAll('.quick-reject').forEach(function (btn) {
        btn.addEventListener('click', function () {
            document.getElementById('rejectAction').value        = this.dataset.action;
            document.getElementById('rejectItemId').value        = this.dataset.id;
            document.getElementById('rejectItemName').textContent = this.dataset.name;
            document.querySelector('#quickRejectModal textarea[name="notes"]').value = '';
            rejectModal.show();
        });
    });
});
</script>

<?php require_once '../includes/admin_footer.php'; ?>
