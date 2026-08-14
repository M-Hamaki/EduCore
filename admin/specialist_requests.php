<?php

declare(strict_types=1);

$page_title = 'طلباتي';
$custom_page_title = true;

require_once '../config/database.php';
require_once '../classes/utilities.php';
require_once '../includes/session_config.php';
require_once '../classes/AcademicYear.php';
require_once '../classes/StudentChangeFieldPolicy.php';
require_once '../classes/StudentChangeRequestService.php';
require_once '../src/Modules/Students/Presentation/StudentChangeRequestPresenter.php';

Utilities::validateSession('admin');
if (!Utilities::isActingAsSpecialist()) {
    header('Location: index.php');
    exit;
}

$db = (new Database())->getConnection();
$academicYear = AcademicYear::getCurrent($db);
$academicYearId = (int) ($academicYear['id'] ?? 0);
$specialistId = (int) ($_SESSION['user_id'] ?? 0);
$status = (string) ($_GET['status'] ?? 'all');
$allowedStatuses = ['all', 'pending', 'approved', 'rejected', 'conflict', 'cancelled'];
if (!in_array($status, $allowedStatuses, true)) {
    $status = 'all';
}

$requests = StudentChangeRequestService::create($db);
$allRows = $requests->listForSpecialist($specialistId, $academicYearId, 'all');
$rows = $status === 'all'
    ? $allRows
    : array_values(array_filter(
        $allRows,
        static fn(array $row): bool => (string) ($row['status'] ?? '') === $status
    ));

$counts = ['pending' => 0, 'approved' => 0, 'rejected' => 0, 'conflict' => 0];
foreach ($allRows as $requestRow) {
    $requestStatus = (string) ($requestRow['status'] ?? '');
    if (array_key_exists($requestStatus, $counts)) {
        $counts[$requestStatus]++;
    }
}

$changePresenter = new \EduCore\Modules\Students\Presentation\StudentChangeRequestPresenter(
    StudentChangeFieldPolicy::labels()
);
$statusLabels = [
    'all' => 'كل الطلبات',
    'pending' => 'قيد المراجعة',
    'approved' => 'تمت الموافقة',
    'rejected' => 'مرفوضة',
    'conflict' => 'متعذرة بسبب تعارض',
    'cancelled' => 'ملغاة',
];
$statusMeta = [
    'pending' => ['warning text-dark', 'قيد المراجعة', 'fa-clock'],
    'approved' => ['success', 'تمت الموافقة', 'fa-check-double'],
    'rejected' => ['danger', 'مرفوضة', 'fa-circle-xmark'],
    'conflict' => ['secondary', 'تعارض', 'fa-code-compare'],
    'cancelled' => ['secondary', 'ملغاة', 'fa-ban'],
];
$formatDate = static function (mixed $value): string {
    $timestamp = strtotime((string) $value);
    return $timestamp ? date('Y-m-d H:i', $timestamp) : '—';
};

$adminAssetOptions = [
    'datatables' => false,
    'sweetalert' => false,
    'sortable' => false,
    'instant_attachment_upload' => false,
    'dashboard_sortable' => false,
];
require_once '../includes/admin_header.php';
?>

<div class="admin-page-heading">
    <h1 class="h2"><i class="fas fa-paper-plane me-2 text-warning"></i>طلباتي</h1>
</div>

<div class="alert alert-info" role="alert">
    <i class="fas fa-circle-info me-2"></i>
    تعرض هذه الصفحة تعديلاتك المرسلة في العام الدراسي الحالي
    <strong><?php echo htmlspecialchars((string) ($academicYear['name'] ?? '—'), ENT_QUOTES, 'UTF-8'); ?></strong>
    وقرار الإدارة عليها. لا تُطبّق التعديلات إلا بعد الموافقة.
</div>

<div class="row row-cols-2 row-cols-md-4 g-3 mb-4">
    <div class="col">
        <div class="stat-card" style="--card-gradient: linear-gradient(135deg, #f59e0b, #d97706);">
            <div class="stat-card-icon"><i class="fas fa-clock"></i></div>
            <div class="stat-card-info">
                <div class="stat-card-number counter" data-target="<?php echo $counts['pending']; ?>">0</div>
                <div class="stat-card-label">قيد المراجعة</div>
            </div>
        </div>
    </div>
    <div class="col">
        <div class="stat-card" style="--card-gradient: linear-gradient(135deg, #10b981, #059669);">
            <div class="stat-card-icon"><i class="fas fa-check-double"></i></div>
            <div class="stat-card-info">
                <div class="stat-card-number counter" data-target="<?php echo $counts['approved']; ?>">0</div>
                <div class="stat-card-label">تمت الموافقة</div>
            </div>
        </div>
    </div>
    <div class="col">
        <div class="stat-card" style="--card-gradient: linear-gradient(135deg, #ef4444, #dc2626);">
            <div class="stat-card-icon"><i class="fas fa-circle-xmark"></i></div>
            <div class="stat-card-info">
                <div class="stat-card-number counter" data-target="<?php echo $counts['rejected']; ?>">0</div>
                <div class="stat-card-label">مرفوضة</div>
            </div>
        </div>
    </div>
    <div class="col">
        <div class="stat-card" style="--card-gradient: linear-gradient(135deg, #8b5cf6, #7c3aed);">
            <div class="stat-card-icon"><i class="fas fa-code-compare"></i></div>
            <div class="stat-card-info">
                <div class="stat-card-number counter" data-target="<?php echo $counts['conflict']; ?>">0</div>
                <div class="stat-card-label">تعارضات</div>
            </div>
        </div>
    </div>
</div>

<form method="get" class="admin-filter-bar">
    <div class="admin-filter-controls">
        <label for="requestStatus" class="visually-hidden">حالة الطلب</label>
        <select name="status" id="requestStatus" class="form-select form-select-sm admin-inline-select-sm"
            onchange="this.form.submit()">
            <?php foreach ($statusLabels as $statusKey => $statusLabel): ?>
                <option value="<?php echo htmlspecialchars($statusKey, ENT_QUOTES, 'UTF-8'); ?>"
                    <?php echo $status === $statusKey ? 'selected' : ''; ?>>
                    <?php echo htmlspecialchars($statusLabel, ENT_QUOTES, 'UTF-8'); ?>
                </option>
            <?php endforeach; ?>
        </select>
    </div>
    <div class="admin-filter-actions">
        <span class="small text-muted"><i class="fas fa-filter me-1"></i><?php echo count($rows); ?> طلب</span>
    </div>
</form>

<div class="admin-list-surface">
    <div class="table-responsive admin-table-wrap">
        <table class="table table-hover table-striped align-middle admin-data-table">
            <thead>
                <tr>
                    <th>#</th>
                    <th>الطالب</th>
                    <th>الصف / الفصل</th>
                    <th>التغييرات المرسلة</th>
                    <th>الحالة</th>
                    <th>تاريخ الإرسال</th>
                    <th>رد الإدارة</th>
                </tr>
            </thead>
            <tbody>
                <?php $requestRowNumber = 1; ?>
                <?php foreach ($rows as $row): ?>
                    <?php
                    $before = json_decode((string) ($row['before_payload'] ?? ''), true) ?: [];
                    $proposed = json_decode((string) ($row['proposed_payload'] ?? ''), true) ?: [];
                    $diffRows = $changePresenter->diffRows($before, $proposed, $row);
                    $rowStatus = (string) ($row['status'] ?? 'pending');
                    [$badgeClass, $badgeText, $badgeIcon] = $statusMeta[$rowStatus]
                        ?? ['secondary', $rowStatus, 'fa-circle-question'];
                    $reviewerName = trim((string) ($row['reviewer_name'] ?? ''));
                    $reviewReason = trim((string) ($row['rejection_reason'] ?? ''));
                    ?>
                    <tr>
                        <td><?php echo $requestRowNumber++; ?></td>
                        <td>
                            <strong><?php echo htmlspecialchars((string) ($row['student_name'] ?? ''), ENT_QUOTES, 'UTF-8'); ?></strong>
                            <div class="small text-muted"><?php echo htmlspecialchars((string) ($row['student_code'] ?? ''), ENT_QUOTES, 'UTF-8'); ?></div>
                        </td>
                        <td><?php echo htmlspecialchars(trim((string) ($row['grade_name'] ?? '') . ' / ' . (string) ($row['class_name'] ?? ''), ' /'), ENT_QUOTES, 'UTF-8'); ?></td>
                        <td>
                            <?php foreach ($diffRows as $diff): ?>
                                <div class="small mb-2">
                                    <strong><?php echo htmlspecialchars($diff['label'], ENT_QUOTES, 'UTF-8'); ?>:</strong>
                                    <span class="text-danger text-decoration-line-through"><?php echo htmlspecialchars($diff['before'], ENT_QUOTES, 'UTF-8'); ?></span>
                                    <i class="fas fa-arrow-left mx-1 text-muted"></i>
                                    <span class="text-success fw-semibold"><?php echo htmlspecialchars($diff['after'], ENT_QUOTES, 'UTF-8'); ?></span>
                                </div>
                            <?php endforeach; ?>
                            <?php if ($diffRows === []): ?>
                                <span class="small text-muted"><i class="fas fa-check-circle me-1"></i>لا توجد فروق فعلية قابلة للعرض.</span>
                            <?php endif; ?>
                        </td>
                        <td>
                            <span class="badge bg-<?php echo htmlspecialchars($badgeClass, ENT_QUOTES, 'UTF-8'); ?>">
                                <i class="fas <?php echo htmlspecialchars($badgeIcon, ENT_QUOTES, 'UTF-8'); ?> me-1"></i><?php echo htmlspecialchars($badgeText, ENT_QUOTES, 'UTF-8'); ?>
                            </span>
                        </td>
                        <td class="text-nowrap"><?php echo htmlspecialchars($formatDate($row['created_at'] ?? null), ENT_QUOTES, 'UTF-8'); ?></td>
                        <td>
                            <?php if ($rowStatus === 'pending'): ?>
                                <span class="text-warning-emphasis"><i class="fas fa-hourglass-half me-1"></i>بانتظار مراجعة الإدارة</span>
                            <?php elseif ($rowStatus === 'approved'): ?>
                                <div class="text-success fw-semibold"><i class="fas fa-check-circle me-1"></i>تم اعتماد التعديل</div>
                            <?php elseif (in_array($rowStatus, ['rejected', 'conflict'], true)): ?>
                                <div class="fw-semibold <?php echo $rowStatus === 'rejected' ? 'text-danger' : 'text-secondary'; ?>">
                                    <i class="fas <?php echo $rowStatus === 'rejected' ? 'fa-circle-xmark' : 'fa-code-compare'; ?> me-1"></i>
                                    <?php echo htmlspecialchars($reviewReason !== '' ? $reviewReason : 'لم يُسجل سبب إضافي.', ENT_QUOTES, 'UTF-8'); ?>
                                </div>
                            <?php else: ?>
                                <span class="text-muted">أُلغي الطلب.</span>
                            <?php endif; ?>
                            <?php if ($rowStatus !== 'pending' && !empty($row['reviewed_at'])): ?>
                                <div class="small text-muted mt-1">
                                    <?php if ($reviewerName !== ''): ?><i class="fas fa-user-check me-1"></i><?php echo htmlspecialchars($reviewerName, ENT_QUOTES, 'UTF-8'); ?> — <?php endif; ?>
                                    <?php echo htmlspecialchars($formatDate($row['reviewed_at']), ENT_QUOTES, 'UTF-8'); ?>
                                </div>
                            <?php endif; ?>
                        </td>
                    </tr>
                <?php endforeach; ?>
                <?php if ($rows === []): ?>
                    <tr>
                        <td colspan="7" class="text-center py-5 text-muted">
                            <i class="fas fa-inbox fa-2x mb-3 d-block"></i>
                            لا توجد طلبات مطابقة لهذه الحالة في العام الحالي.
                        </td>
                    </tr>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>

<?php require_once '../includes/admin_footer.php'; ?>
