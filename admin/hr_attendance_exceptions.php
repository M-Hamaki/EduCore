<?php

declare(strict_types=1);

$page_title = 'مركز استثناءات الحضور';
$custom_page_title = true;
$adminAssetOptions = [
    'datatables' => false,
    'sortable' => false,
    'instant_attachment_upload' => false,
    'dashboard_sortable' => false,
];

require_once '../config/database.php';
require_once '../classes/utilities.php';
require_once '../includes/session_config.php';
Utilities::validateSession('admin');

require_once '../vendor/autoload.php';
require_once '../src/Modules/Operations/Audit/AuditService.php';

$fallbackFilters = [
    'date_from' => date('Y-m-01'),
    'date_to' => date('Y-m-d'),
    'staff_user_id' => null,
    'category' => 'all',
    'limit' => 100,
];
$review = [
    'filters' => $fallbackFilters,
    'summary' => ['raw_events' => 0, 'unresolved_days' => 0, 'comparison_differences' => 0, 'total' => 0],
    'items' => [],
    'filtered_total' => 0,
    'limit_reached' => false,
];
$error_message = null;
$feedback = null;
$csrfToken = (string) ($_SESSION['csrf_token'] ?? '');
$recalculationIdempotencyKey = 'hr-attendance-recalc-' . bin2hex(random_bytes(12));

try {
    $database = new Database();
    $db = $database->getConnection();
    $factory = new \EduCore\Modules\Attendance\Infrastructure\AttendanceModuleFactory(
        $db,
        new \EduCore\Modules\Operations\Audit\AuditService($db)
    );
    if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
        $postedToken = (string) ($_POST['csrf_token'] ?? $_SERVER['HTTP_X_CSRF_TOKEN'] ?? '');
        if ($csrfToken === '' || !hash_equals($csrfToken, $postedToken)) {
            throw new DomainException('ATTENDANCE_RECALCULATION_CSRF_INVALID');
        }
        $recalculationIntent = (string) ($_POST['recalculation_intent'] ?? '');
        if (!in_array($recalculationIntent, ['run', 'calculate_initial'], true)) {
            throw new DomainException('ATTENDANCE_RECALCULATION_INTENT_INVALID');
        }
        $staffUserId = filter_var($_POST['staff_user_id'] ?? null, FILTER_VALIDATE_INT, [
            'options' => ['min_range' => 1],
        ]);
        $workDateText = trim((string) ($_POST['work_date'] ?? ''));
        $workDate = DateTimeImmutable::createFromFormat(
            '!Y-m-d',
            $workDateText,
            new DateTimeZone('Africa/Cairo')
        );
        if ($staffUserId === false
            || $workDate === false
            || $workDate->format('Y-m-d') !== $workDateText) {
            throw new DomainException('ATTENDANCE_RECALCULATION_INPUT_INVALID');
        }
        $idempotencyKey = trim((string) ($_POST['idempotency_key'] ?? ''));
        if (preg_match('/^hr-attendance-recalc-[a-f0-9]{24}$/D', $idempotencyKey) !== 1) {
            throw new DomainException('ATTENDANCE_RECALCULATION_IDEMPOTENCY_KEY_INVALID');
        }
        $recalculationService = $factory->attendanceRecalculationService();
        $receipt = $recalculationIntent === 'calculate_initial'
            ? $recalculationService->calculateInitial(
                (int) ($_SESSION['user_id'] ?? 0),
                (int) $staffUserId,
                $workDate,
                'INITIAL_OFFICIAL_CALCULATION',
                $idempotencyKey
            )
            : $recalculationService->recalculate(
                (int) ($_SESSION['user_id'] ?? 0),
                (int) $staffUserId,
                $workDate,
                'MANUAL_HR_REVIEW',
                $idempotencyKey
            );
        $feedback = [
            'kind' => 'success',
            'message' => ($receipt['calculated'] ?? false)
                ? 'تم إنشاء النسخة الرسمية الأولى لنتيجة اليوم من الأدلة المؤرخة.'
                : (($receipt['no_change'] ?? false)
                    ? 'تمت مراجعة اليوم رسميًا ولا توجد تغييرات جديدة على النتيجة.'
                    : 'تم إنشاء نسخة رسمية جديدة لنتيجة اليوم مع الاحتفاظ بالنسخة السابقة.'),
        ];
    }
    $exceptionQuery = $factory->attendanceExceptionQuery();
    try {
        $review = $exceptionQuery->review($_GET);
    } catch (InvalidArgumentException $exception) {
        $error_message = $exception->getMessage();
        $review = $exceptionQuery->review([]);
    }
} catch (Throwable $exception) {
    $reference = 'HRA-' . strtoupper(substr(bin2hex(random_bytes(4)), 0, 8));
    error_log($reference . ' attendance exception review initialization error: ' . $exception->getMessage());
    $messages = [
        'ATTENDANCE_RECALCULATION_CSRF_INVALID' => 'انتهت صلاحية جلسة الحفظ. حدّث الصفحة ثم حاول مرة أخرى.',
        'ATTENDANCE_RECALCULATION_INTENT_INVALID' => 'تعذر تحديد عملية إعادة الاحتساب المطلوبة.',
        'ATTENDANCE_RECALCULATION_INPUT_INVALID' => 'اختر عاملًا صحيحًا وتاريخ يوم صالحًا لإعادة الاحتساب.',
        'ATTENDANCE_RECALCULATION_IDEMPOTENCY_KEY_INVALID' => 'انتهت صلاحية نموذج إعادة الاحتساب. حدّث الصفحة ثم حاول مرة أخرى.',
        'ATTENDANCE_RECALCULATION_OFFICIAL_DAY_NOT_FOUND' => 'لا توجد نتيجة رسمية لهذا العامل في اليوم المحدد.',
        'ATTENDANCE_INITIAL_OFFICIAL_DAY_EXISTS' => 'توجد نتيجة رسمية لهذا اليوم بالفعل؛ استخدم إعادة الاحتساب بدل إنشاء النسخة الأولى.',
        'ATTENDANCE_RECALCULATION_SCHEDULE_UNRESOLVED' => 'تعذر إعادة الاحتساب لأن دوام العامل في هذا اليوم غير محسوم.',
        'ATTENDANCE_RECALCULATION_PERIOD_CLOSED' => 'الفترة مقفلة؛ أنشئ طلب تغيير معتمدًا قبل إعادة الاحتساب.',
    ];
    $error_message = $messages[$exception->getMessage()]
        ?? 'تعذر تنفيذ مراجعة الحضور الآن. لم تُعرض أي تفاصيل تقنية. مرجع المتابعة: ' . $reference;
}

$filters = (array) ($review['filters'] ?? $fallbackFilters);
$summary = (array) ($review['summary'] ?? []);
$items = (array) ($review['items'] ?? []);
$escape = static fn (mixed $value): string => htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
$severityClasses = [
    'danger' => 'bg-danger',
    'warning' => 'bg-warning text-dark',
    'info' => 'bg-info text-dark',
];
$stats = [
    ['#ef4444', '#dc2626', 'fa-fingerprint', (int) ($summary['raw_events'] ?? 0), 'أحداث بصمة تحتاج مراجعة'],
    ['#f59e0b', '#d97706', 'fa-triangle-exclamation', (int) ($summary['unresolved_days'] ?? 0), 'نتائج أيام غير محسومة'],
    ['#0ea5e9', '#0284c7', 'fa-code-compare', (int) ($summary['comparison_differences'] ?? 0), 'فروق مقارنة انتقالية'],
    ['#8b5cf6', '#7c3aed', 'fa-list-check', (int) ($summary['total'] ?? 0), 'إجمالي عناصر المتابعة'],
];

require_once '../includes/admin_header.php';
?>

<div class="admin-page-heading">
    <h1 class="h2"><i class="fas fa-triangle-exclamation me-2 text-warning"></i>مركز استثناءات الحضور</h1>
    <div class="admin-top-actions no-print">
        <a href="hr_policy_calendar.php" class="btn btn-outline-primary shadow-sm px-3 py-2">
            <i class="fas fa-calendar-alt me-1"></i>سياسات الدوام
        </a>
        <a href="staff_attendance.php" class="btn btn-outline-secondary shadow-sm px-3 py-2">
            <i class="fas fa-user-clock me-1"></i>الحضور اليومي
        </a>
    </div>
</div>

<?php if ($error_message !== null): ?>
    <div class="alert alert-danger alert-dismissible fade show" role="alert">
        <i class="fas fa-circle-exclamation me-2"></i><?php echo $escape($error_message); ?>
        <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="إغلاق"></button>
    </div>
<?php endif; ?>

<?php if ($feedback !== null): ?>
    <div class="alert alert-success alert-dismissible fade show" role="alert">
        <i class="fas fa-circle-check me-2"></i><?php echo $escape($feedback['message']); ?>
        <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="إغلاق"></button>
    </div>
<?php endif; ?>

<div class="alert alert-info" role="status">
    <i class="fas fa-shield-halved me-2"></i>
    هذه الصفحة للمراجعة والتوجيه فقط: لا تعدّل البصمات الخام ولا تغيّر نتيجة الحضور أو السجل السابق تلقائيًا.
</div>

<div class="card shadow admin-card-surface mb-4">
    <div class="card-header bg-primary text-white">
        <h5 class="mb-0"><i class="fas fa-rotate me-2"></i>إعادة احتساب يوم رسمي</h5>
    </div>
    <div class="card-body">
        <form method="post" id="attendanceRecalculationForm" class="row g-3 align-items-end">
            <input type="hidden" name="csrf_token" value="<?php echo $escape($csrfToken); ?>">
            <input type="hidden" name="idempotency_key" value="<?php echo $escape($recalculationIdempotencyKey); ?>">
            <div class="col-md-4">
                <label class="form-label" for="recalculationStaffId">رقم العامل</label>
                <input class="form-control" id="recalculationStaffId" name="staff_user_id" type="number" min="1" step="1" required>
            </div>
            <div class="col-md-4">
                <label class="form-label" for="recalculationWorkDate">تاريخ اليوم</label>
                <input class="form-control" id="recalculationWorkDate" name="work_date" type="date" required>
            </div>
            <div class="col-md-4">
                <div class="d-grid gap-2"><button type="submit" name="recalculation_intent" value="calculate_initial" class="btn btn-success"><i class="fas fa-calculator me-1"></i>إنشاء النتيجة الرسمية الأولى</button><button type="submit" name="recalculation_intent" value="run" class="btn btn-primary"><i class="fas fa-rotate me-1"></i>إعادة احتساب نتيجة موجودة</button></div>
            </div>
        </form>
        <p class="small text-secondary mt-3 mb-0"><i class="fas fa-shield-halved me-1"></i>ينشئ النظام نسخة رسمية لاحقة عند تغير المصادر، ولا يعدّل البصمات الخام أو يحذف النسخة السابقة.</p>
    </div>
</div>

<div class="row row-cols-2 row-cols-md-4 g-3 mb-4" aria-label="ملخص الاستثناءات">
    <?php foreach ($stats as $stat): ?>
        <div class="col">
            <div class="stat-card" style="--card-gradient: linear-gradient(135deg, <?php echo $stat[0]; ?>, <?php echo $stat[1]; ?>);">
                <div class="stat-card-icon"><i class="fas <?php echo $stat[2]; ?>"></i></div>
                <div class="stat-card-info">
                    <div class="stat-card-number counter" data-target="<?php echo (int) $stat[3]; ?>">0</div>
                    <div class="stat-card-label"><?php echo $escape($stat[4]); ?></div>
                    <div class="stat-card-sub"><i class="fas fa-filter"></i> حسب الفترة والعامل المحددين</div>
                </div>
            </div>
        </div>
    <?php endforeach; ?>
</div>

<form method="GET" class="admin-filter-bar" aria-label="فلاتر مركز استثناءات الحضور">
    <div class="admin-filter-controls">
        <div>
            <label for="exceptionDateFrom" class="visually-hidden">من تاريخ</label>
            <input id="exceptionDateFrom" class="form-control form-control-sm" type="date" name="date_from" value="<?php echo $escape($filters['date_from'] ?? ''); ?>" aria-label="من تاريخ">
        </div>
        <div>
            <label for="exceptionDateTo" class="visually-hidden">إلى تاريخ</label>
            <input id="exceptionDateTo" class="form-control form-control-sm" type="date" name="date_to" value="<?php echo $escape($filters['date_to'] ?? ''); ?>" aria-label="إلى تاريخ">
        </div>
        <div>
            <label for="exceptionCategory" class="visually-hidden">فئة الاستثناء</label>
            <select id="exceptionCategory" class="form-select form-select-sm admin-inline-select-sm" name="category" aria-label="فئة الاستثناء">
                <?php foreach (['all' => 'كل الاستثناءات', 'raw' => 'أحداث البصمة', 'day' => 'نتائج الأيام', 'comparison' => 'فروق المقارنة'] as $value => $label): ?>
                    <option value="<?php echo $escape($value); ?>" <?php echo ($filters['category'] ?? 'all') === $value ? 'selected' : ''; ?>><?php echo $escape($label); ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <div>
            <label for="exceptionStaffId" class="visually-hidden">رقم العامل</label>
            <input id="exceptionStaffId" class="form-control form-control-sm" type="number" min="1" step="1" name="staff_user_id" value="<?php echo $escape($filters['staff_user_id'] ?? ''); ?>" placeholder="رقم العامل" aria-label="رقم العامل">
        </div>
    </div>
    <div class="admin-filter-actions">
        <button type="submit" class="btn btn-light btn-sm"><i class="fas fa-search me-1"></i>بحث</button>
        <a href="hr_attendance_exceptions.php" class="btn btn-light btn-sm"><i class="fas fa-rotate-left me-1"></i>إعادة تعيين</a>
    </div>
</form>

<?php if (($review['limit_reached'] ?? false) === true): ?>
    <div class="alert alert-secondary mt-3 mb-3">
        <i class="fas fa-list-ol me-2"></i>تظهر أول 100 نتيجة فقط. ضيّق الفترة أو رقم العامل لمراجعة العناصر المتبقية بدقة.
    </div>
<?php endif; ?>

<div class="admin-list-surface">
    <div class="table-responsive admin-table-wrap">
        <table class="table table-hover table-striped admin-data-table" aria-describedby="attendanceExceptionHint">
            <thead>
                <tr>
                    <th>التاريخ/الوقت</th>
                    <th>الفئة</th>
                    <th>العامل</th>
                    <th>سبب المراجعة</th>
                    <th>الحالة</th>
                    <th>المصدر</th>
                    <th class="text-center">إجراء</th>
                </tr>
            </thead>
            <tbody>
                <?php if ($items === []): ?>
                    <tr>
                        <td colspan="7" class="text-center text-muted py-4">
                            <i class="fas fa-circle-check me-1 text-success"></i>لا توجد استثناءات مطابقة للفلاتر الحالية.
                        </td>
                    </tr>
                <?php else: ?>
                    <?php foreach ($items as $item): ?>
                        <?php
                        $category = (string) ($item['category'] ?? '');
                        $staffUserId = isset($item['staff_user_id']) ? (int) $item['staff_user_id'] : 0;
                        $occurrence = (string) ($item['occurred_at'] ?? '');
                        $reviewHref = 'staff_biometric_import.php';
                        if ($category !== 'raw') {
                            $attendanceQuery = ['view' => 'daily'];
                            if (preg_match('/^\d{4}-\d{2}-\d{2}/', $occurrence, $dateMatch) === 1) {
                                $attendanceQuery['date'] = $dateMatch[0];
                            }
                            if ($staffUserId > 0) {
                                $attendanceQuery['user_id'] = $staffUserId;
                            }
                            $reviewHref = 'staff_attendance.php?' . http_build_query($attendanceQuery, '', '&', PHP_QUERY_RFC3986);
                        }
                        $severityClass = $severityClasses[(string) ($item['severity'] ?? '')] ?? 'bg-secondary';
                        ?>
                        <tr>
                            <td dir="ltr" class="text-end"><?php echo $escape($occurrence); ?></td>
                            <td><span class="badge bg-secondary"><?php echo $escape($item['category_label'] ?? '—'); ?></span></td>
                            <td><?php echo $staffUserId > 0 ? '#' . $staffUserId : 'غير محدد'; ?></td>
                            <td>
                                <strong><?php echo $escape($item['issue_label'] ?? '—'); ?></strong>
                                <div class="small text-muted"><?php echo $escape($item['detail'] ?? ''); ?></div>
                            </td>
                            <td>
                                <span class="badge <?php echo $escape($severityClass); ?>"><?php echo $escape($item['severity_label'] ?? 'يحتاج مراجعة'); ?></span>
                                <div class="small text-muted mt-1"><?php echo $escape($item['state_label'] ?? ''); ?></div>
                            </td>
                            <td><?php echo $escape($item['source_label'] ?? '—'); ?></td>
                            <td class="text-center">
                                <a href="<?php echo $escape($reviewHref); ?>" class="btn btn-action-pills btn-edit" data-bs-toggle="tooltip" title="فتح سجل المراجعة" aria-label="فتح سجل المراجعة">
                                    <i class="fas fa-arrow-up-right-from-square"></i>
                                </a>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>
<p id="attendanceExceptionHint" class="small text-muted mt-2 mb-4">
    لا يظهر هنا رقم البصمة أو محتوى ملف البصمة أو أي مرفق خاص؛ تُعرض فقط معلومات كافية لتحديد مسار المراجعة الصحيح.
</p>

<?php require_once '../includes/admin_footer.php'; ?>
