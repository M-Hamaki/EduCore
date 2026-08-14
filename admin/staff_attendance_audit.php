<?php
/**
 * سجل تدقيق حضور الموظفين
 */
$page_title = "سجل تدقيق الحضور";
$custom_page_title = true;

require_once '../config/database.php';
require_once '../classes/StaffAttendanceService.php';
require_once '../classes/utilities.php';
require_once '../includes/session_config.php';
require_once '../includes/csrf.php';
require_once '../vendor/autoload.php';
require_once '../src/Modules/Operations/Audit/AuditService.php';
Utilities::validateSession('admin');
requireCsrfPost();

$database = new Database();
$db = $database->getConnection();
$attendanceService = new StaffAttendanceService($db);
$attendanceService->ensureAttendanceAuditTable();
$attendanceFactory = new \EduCore\Modules\Attendance\Infrastructure\AttendanceModuleFactory(
    $db,
    new \EduCore\Modules\Operations\Audit\AuditService($db)
);
$periodService = $attendanceFactory->attendancePeriodService();
$periodFeedback = null;
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['attendance_period_intent'])) {
    try {
        $actorId = (int) ($_SESSION['user_id'] ?? 0);
        $intent = (string) $_POST['attendance_period_intent'];
        if ($intent === 'close') {
            $receipt = $periodService->closePeriod(
                $actorId,
                (string) ($_POST['period_key'] ?? ''),
                (int) ($_POST['expected_lock_version'] ?? 1),
                null,
                (string) ($_POST['reason'] ?? '')
            );
        } elseif ($intent === 'request_change') {
            $receipt = $periodService->requestAffectedDayChange(
                $actorId,
                (int) ($_POST['staff_user_id'] ?? 0),
                new DateTimeImmutable((string) ($_POST['work_date'] ?? '')),
                (string) ($_POST['request_type'] ?? ''),
                'staff_hr_acceptance_ui',
                null,
                hash('sha256', (string) ($_POST['source_fingerprint'] ?? '')),
                (string) ($_POST['reason_code'] ?? ''),
                (string) ($_POST['idempotency_key'] ?? '')
            );
        } elseif ($intent === 'decide_change') {
            $receipt = $periodService->decideChangeRequest(
                $actorId,
                (int) ($_POST['change_request_id'] ?? 0),
                (int) ($_POST['expected_change_lock_version'] ?? 0),
                (int) ($_POST['expected_period_lock_version'] ?? 0),
                (string) ($_POST['decision'] ?? ''),
                (string) ($_POST['review_comment'] ?? ''),
                (string) ($_POST['idempotency_key'] ?? '')
            );
        } else {
            throw new DomainException('ATTENDANCE_PERIOD_ACTION_INVALID');
        }
        $_SESSION['attendance_period_feedback'] = ['kind' => 'success', 'receipt' => $receipt];
    } catch (Throwable $exception) {
        error_log('Attendance period action failed: ' . $exception->getMessage());
        $_SESSION['attendance_period_feedback'] = ['kind' => 'danger', 'code' => $exception->getMessage()];
    }
    header('Location: staff_attendance_audit.php');
    exit;
}
$periodFeedback = is_array($_SESSION['attendance_period_feedback'] ?? null) ? $_SESSION['attendance_period_feedback'] : null;
unset($_SESSION['attendance_period_feedback']);
$attendancePeriods = $periodService->periods();
$attendancePeriodChanges = $periodService->changeRequests();

$filterUser = $_GET['user_id'] ?? '';
$filterAction = $_GET['action_type'] ?? '';
$filterSource = $_GET['source'] ?? '';
$filterChangedBy = $_GET['changed_by'] ?? '';
$filterFrom = $_GET['date_from'] ?? '';
$filterTo = $_GET['date_to'] ?? '';

$staffList = $attendanceService->getActiveStaffList();
$adminUsersStmt = $db->query("SELECT id, name FROM users WHERE role IN ('admin','super_admin') ORDER BY name");
$adminUsers = $adminUsersStmt->fetchAll(PDO::FETCH_ASSOC);

$actionLabels = [
    'insert' => 'إضافة',
    'update' => 'تعديل',
    'delete' => 'حذف',
    'biometric_import' => 'مزامنة بصمة'
];
$actionBadges = [
    'insert' => 'success',
    'update' => 'primary',
    'delete' => 'danger',
    'biometric_import' => 'warning'
];
$sourceLabels = [
    'manual' => 'يدوي',
    'biometric' => 'بصمة'
];

require_once '../includes/admin_header.php';
?>

<div class="d-flex justify-content-between flex-wrap flex-md-nowrap align-items-center pt-3 pb-2 mb-3 border-bottom">
    <h1 class="h2"><i class="fas fa-history me-2"></i>سجل تدقيق الحضور</h1>
    <div class="btn-toolbar mb-2 mb-md-0 gap-2">
        <a href="hr_center.php" class="btn btn-sm btn-outline-secondary"><i class="fas fa-layer-group me-1"></i>مركز شؤون العاملين</a>
        <a href="staff_attendance.php" class="btn btn-sm btn-outline-secondary"><i class="fas fa-arrow-right me-1"></i>العودة للحضور</a>
    </div>
</div>

<div class="card shadow admin-card-surface mb-4" id="attendancePeriodControl">
    <div class="card-header bg-primary text-white"><h5 class="mb-0"><i class="fas fa-calendar-check me-2"></i>إقفال فترات الحضور وإعادة الفتح المنضبطة</h5></div>
    <div class="card-body">
        <?php if ($periodFeedback !== null): $periodReceipt = (array) ($periodFeedback['receipt'] ?? []); ?>
            <div class="alert alert-<?php echo htmlspecialchars((string) ($periodFeedback['kind'] ?? 'danger'), ENT_QUOTES, 'UTF-8'); ?>"
                 data-period-id="<?php echo (int) ($periodReceipt['period_id'] ?? $periodReceipt['id'] ?? 0); ?>"
                 data-change-request-id="<?php echo (int) ($periodReceipt['change_request_id'] ?? $periodReceipt['id'] ?? 0); ?>"
                 data-period-state="<?php echo htmlspecialchars((string) ($periodReceipt['period_state'] ?? $periodReceipt['state'] ?? ''), ENT_QUOTES, 'UTF-8'); ?>"
                 data-change-status="<?php echo htmlspecialchars((string) ($periodReceipt['status'] ?? ''), ENT_QUOTES, 'UTF-8'); ?>"
                 data-lock-version="<?php echo (int) ($periodReceipt['lock_version'] ?? 0); ?>"
                 data-period-lock-version="<?php echo (int) ($periodReceipt['period_lock_version'] ?? 0); ?>"
                 data-period-reopened="<?php echo !empty($periodReceipt['reopened']) ? '1' : '0'; ?>"
                 data-replayed="<?php echo !empty($periodReceipt['replayed']) ? '1' : '0'; ?>">
                <?php echo ($periodFeedback['kind'] ?? '') === 'success' ? 'تم تنفيذ إجراء الفترة وتسجيله في سجل التدقيق.' : 'تعذر تنفيذ الإجراء؛ راجع حالة الفترة وإصدارات القفل.'; ?>
            </div>
        <?php endif; ?>
        <div class="row g-3 mb-4">
            <form method="post" class="col-lg-4 row g-2" id="attendancePeriodCloseForm">
                <?php echo csrfField(); ?><input type="hidden" name="attendance_period_intent" value="close"><input type="hidden" name="expected_lock_version" value="1">
                <div class="col-5"><label class="form-label">الفترة</label><input class="form-control" name="period_key" pattern="\d{4}-\d{2}" placeholder="2026-08" required></div>
                <div class="col-7"><label class="form-label">سبب الإقفال</label><input class="form-control" name="reason" required></div>
                <div class="col-12"><button class="btn btn-primary" type="submit"><i class="fas fa-lock me-1"></i>إقفال الفترة</button></div>
            </form>
            <form method="post" class="col-lg-8 row g-2" id="attendancePeriodChangeForm">
                <?php echo csrfField(); ?><input type="hidden" name="attendance_period_intent" value="request_change"><input type="hidden" name="idempotency_key" value="period-change-<?php echo bin2hex(random_bytes(12)); ?>"><input type="hidden" name="source_fingerprint" value="ui-period-change-<?php echo bin2hex(random_bytes(12)); ?>">
                <div class="col-md-2"><label class="form-label">العامل</label><input class="form-control" name="staff_user_id" type="number" min="1" required></div>
                <div class="col-md-2"><label class="form-label">اليوم</label><input class="form-control" name="work_date" type="date" required></div>
                <div class="col-md-3"><label class="form-label">نوع الأثر</label><select class="form-select" name="request_type"><option value="coverage_approved">تغطية معتمدة متأخرة</option><option value="leave_reversed">عكس إجازة</option><option value="late_event">بصمة متأخرة</option></select></div>
                <div class="col-md-3"><label class="form-label">كود السبب</label><input class="form-control" name="reason_code" value="POST_CLOSE_CHANGE" required></div>
                <div class="col-md-2 d-flex align-items-end"><button class="btn btn-primary w-100" type="submit"><i class="fas fa-file-circle-plus me-1"></i>تسجيل</button></div>
            </form>
        </div>
        <div class="admin-table-wrap"><table class="table table-hover table-striped admin-data-table"><thead><tr><th>الفترة</th><th>الحالة</th><th>القفل</th><th>آخر إقفال</th></tr></thead><tbody>
            <?php if ($attendancePeriods === []): ?><tr><td colspan="4" class="text-center text-muted">لا توجد فترات مسجلة.</td></tr><?php endif; ?>
            <?php foreach ($attendancePeriods as $period): ?><tr data-period-key="<?php echo htmlspecialchars((string) $period['period_key'], ENT_QUOTES, 'UTF-8'); ?>" data-period-row-state="<?php echo htmlspecialchars((string) $period['state'], ENT_QUOTES, 'UTF-8'); ?>" data-period-row-lock-version="<?php echo (int) $period['lock_version']; ?>"><td><?php echo htmlspecialchars((string) $period['period_key'], ENT_QUOTES, 'UTF-8'); ?></td><td><?php echo htmlspecialchars((string) $period['state'], ENT_QUOTES, 'UTF-8'); ?></td><td><?php echo (int) $period['lock_version']; ?></td><td><?php echo htmlspecialchars((string) ($period['closed_at'] ?? '—'), ENT_QUOTES, 'UTF-8'); ?></td></tr><?php endforeach; ?>
        </tbody></table></div>
        <div class="admin-table-wrap mt-3"><table class="table table-hover table-striped admin-data-table"><thead><tr><th>#</th><th>الفترة</th><th>العامل/اليوم</th><th>الأثر</th><th>الحالة</th><th>المراجعة</th></tr></thead><tbody>
            <?php if ($attendancePeriodChanges === []): ?><tr><td colspan="6" class="text-center text-muted">لا توجد تغييرات بعد الإقفال.</td></tr><?php endif; ?>
            <?php foreach ($attendancePeriodChanges as $change): ?><tr data-period-change-id="<?php echo (int) $change['id']; ?>"><td><?php echo (int) $change['id']; ?></td><td><?php echo htmlspecialchars((string) $change['period_key'], ENT_QUOTES, 'UTF-8'); ?></td><td><?php echo (int) $change['staff_user_id']; ?> / <?php echo htmlspecialchars((string) $change['work_date'], ENT_QUOTES, 'UTF-8'); ?></td><td><?php echo htmlspecialchars((string) $change['request_type'], ENT_QUOTES, 'UTF-8'); ?></td><td><?php echo htmlspecialchars((string) $change['status'], ENT_QUOTES, 'UTF-8'); ?></td><td>
                <?php if ((string) $change['status'] === 'pending'): ?><form method="post" class="d-flex gap-2" data-no-form-safety="true"><?php echo csrfField(); ?><input type="hidden" name="attendance_period_intent" value="decide_change"><input type="hidden" name="change_request_id" value="<?php echo (int) $change['id']; ?>"><input type="hidden" name="expected_change_lock_version" value="<?php echo (int) $change['lock_version']; ?>"><input type="hidden" name="expected_period_lock_version" value="<?php echo (int) $change['period_lock_version']; ?>"><input type="hidden" name="review_comment" value="تمت مراجعة أثر التغيير بعد الإقفال"><input type="hidden" name="idempotency_key" value="period-decision-<?php echo (int) $change['id']; ?>"><button class="btn btn-success btn-sm" name="decision" value="approve"><i class="fas fa-lock-open me-1"></i>اعتماد وإعادة فتح</button><button class="btn btn-danger btn-sm" name="decision" value="reject"><i class="fas fa-times me-1"></i>رفض</button></form><?php else: ?>—<?php endif; ?>
            </td></tr><?php endforeach; ?>
        </tbody></table></div>
    </div>
</div>

<form id="attendanceAuditFilters" method="GET" class="admin-filter-bar">
    <div class="admin-filter-controls">
        <select class="form-select form-select-sm" name="user_id" style="width:auto; min-width:150px;">
            <option value="">كل الموظفين</option>
            <?php foreach ($staffList as $s): ?>
                <option value="<?php echo (int)$s['id']; ?>" <?php echo ((string)$filterUser === (string)$s['id']) ? 'selected' : ''; ?>><?php echo htmlspecialchars($s['name'], ENT_QUOTES, 'UTF-8'); ?></option>
            <?php endforeach; ?>
        </select>
        <select class="form-select form-select-sm" name="action_type" style="width:auto; min-width:130px;">
            <option value="">كل العمليات</option>
            <?php foreach ($actionLabels as $key => $label): ?>
                <option value="<?php echo htmlspecialchars($key, ENT_QUOTES, 'UTF-8'); ?>" <?php echo $filterAction === $key ? 'selected' : ''; ?>><?php echo htmlspecialchars($label, ENT_QUOTES, 'UTF-8'); ?></option>
            <?php endforeach; ?>
        </select>
        <select class="form-select form-select-sm" name="source" style="width:auto; min-width:110px;">
            <option value="">كل المصادر</option>
            <?php foreach ($sourceLabels as $key => $label): ?>
                <option value="<?php echo htmlspecialchars($key, ENT_QUOTES, 'UTF-8'); ?>" <?php echo $filterSource === $key ? 'selected' : ''; ?>><?php echo htmlspecialchars($label, ENT_QUOTES, 'UTF-8'); ?></option>
            <?php endforeach; ?>
        </select>
        <select class="form-select form-select-sm" name="changed_by" style="width:auto; min-width:140px;">
            <option value="">كل المنفذين</option>
            <?php foreach ($adminUsers as $adminUser): ?>
                <option value="<?php echo (int)$adminUser['id']; ?>" <?php echo ((string)$filterChangedBy === (string)$adminUser['id']) ? 'selected' : ''; ?>><?php echo htmlspecialchars($adminUser['name'], ENT_QUOTES, 'UTF-8'); ?></option>
            <?php endforeach; ?>
        </select>
        <input type="text" class="form-control form-control-sm flatpickr-date" name="date_from" value="<?php echo htmlspecialchars($filterFrom, ENT_QUOTES, 'UTF-8'); ?>" style="width:auto;">
        <input type="text" class="form-control form-control-sm flatpickr-date" name="date_to" value="<?php echo htmlspecialchars($filterTo, ENT_QUOTES, 'UTF-8'); ?>" style="width:auto;">
    </div>
    <div class="admin-filter-actions">
        <button type="submit" class="btn btn-light btn-sm"><i class="fas fa-search me-1"></i>بحث</button>
        <a href="staff_attendance_audit.php" class="btn btn-light btn-sm"><i class="fas fa-rotate-left me-1"></i>إعادة تعيين</a>
    </div>
</form>

<div class="admin-list-surface">
    <div class="table-responsive admin-table-wrap">
        <table class="table table-hover table-striped admin-data-table" id="attendanceAuditTable">
            <thead>
                <tr>
                    <th>#</th>
                    <th>الموظف</th>
                    <th>تاريخ الحضور</th>
                    <th>العملية</th>
                    <th>المصدر</th>
                    <th>منفذ العملية</th>
                    <th>قبل</th>
                    <th>بعد</th>
                    <th>وقت التنفيذ</th>
                </tr>
            </thead>
            <tbody><tr><td colspan="9" class="text-center text-muted py-5">جاري تحميل سجل التدقيق…</td></tr></tbody>
        </table>
    </div>
</div>

<script src="../assets/js/admin-server-side-table.js"></script>
<script>
document.addEventListener('DOMContentLoaded', function () {
    if (!window.AdminServerSideTable) return;
    window.AdminServerSideTable.init({
        selector: '#attendanceAuditTable',
        url: 'ajax_staff_attendance_audit_datatable.php',
        order: [[8, 'desc']],
        language: {
            processing: '<i class="fas fa-spinner fa-spin me-2"></i>جاري تحميل سجل التدقيق…',
            emptyTable: 'لا توجد سجلات تدقيق مطابقة للفلاتر المحددة.'
        },
        requestData: function () {
            var form = document.getElementById('attendanceAuditFilters');
            var value = function (name) { return form && form.elements[name] ? form.elements[name].value : ''; };
            return {
                user_id: value('user_id'),
                action_type: value('action_type'),
                source: value('source'),
                changed_by: value('changed_by'),
                date_from: value('date_from'),
                date_to: value('date_to')
            };
        }
    });
});
</script>

<?php require_once '../includes/admin_footer.php'; ?>
