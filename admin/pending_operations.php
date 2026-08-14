<?php

declare(strict_types=1);

$page_title = 'العمليات المعلقة';
$custom_page_title = true;

require_once '../config/database.php';
require_once '../classes/utilities.php';
require_once '../classes/AcademicYear.php';
require_once '../classes/ActivityLog.php';
require_once '../classes/UndoManager.php';
require_once '../classes/ProfileAttachmentStorage.php';
require_once '../classes/StudentProfileRepository.php';
require_once '../classes/StudentProfileRequestMapper.php';
require_once '../classes/StudentEnrollmentService.php';
require_once '../classes/StudentGuardianService.php';
require_once '../classes/StudentProfileLifecycleService.php';
require_once '../classes/StudentProfileCommandService.php';
require_once '../classes/StudentChangeFieldPolicy.php';
require_once '../classes/StudentChangeRequestService.php';
require_once '../classes/SpecialistAcademicScopeService.php';
require_once '../src/Modules/Students/Presentation/StudentChangeRequestPresenter.php';
require_once '../includes/session_config.php';
require_once '../includes/csrf.php';

Utilities::validateSession('admin');

$db = (new Database())->getConnection();
ActivityLog::setDb($db);
UndoManager::setDb($db);
$requests = StudentChangeRequestService::create($db);

function pending_operations_public_error(Throwable $error): string
{
    for ($cursor = $error; $cursor !== null; $cursor = $cursor->getPrevious()) {
        if ($cursor instanceof PDOException) {
            error_log('Pending student operation failed: ' . $error->getMessage());
            return 'تعذر تنفيذ العملية. تم التراجع عنها ولم تُحفظ تغييرات جزئية.';
        }
    }
    return $error->getMessage();
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    requireCsrfPost();
    $requestId = max(0, (int)($_POST['request_id'] ?? 0));
    try {
        if (isset($_POST['approve_request'])) {
            $result = $requests->approve($requestId, (int)($_SESSION['user_id'] ?? 0));
            $_SESSION[$result['status'] === 'approved' ? 'success_message' : 'error_message'] = $result['message'];
        } elseif (isset($_POST['reject_request'])) {
            $requests->reject($requestId, (int)($_SESSION['user_id'] ?? 0), (string)($_POST['rejection_reason'] ?? ''));
            $_SESSION['success_message'] = 'تم رفض الطلب وتسجيل السبب دون تغيير بيانات الطالب.';
        } else {
            throw new InvalidArgumentException('عملية غير معروفة.');
        }
    } catch (Throwable $e) {
        $_SESSION['error_message'] = pending_operations_public_error($e);
    }
    header('Location: pending_operations.php');
    exit;
}

$status = (string)($_GET['status'] ?? 'all');
$rows = $requests->listForAdmin($status);
$labels = StudentChangeFieldPolicy::labels();
$changePresenter = new \EduCore\Modules\Students\Presentation\StudentChangeRequestPresenter($labels);
$counts = ['pending' => 0, 'approved' => 0, 'rejected' => 0, 'conflict' => 0];
$countRows = $db->query("SELECT status, COUNT(*) AS total FROM student_change_requests GROUP BY status")->fetchAll(PDO::FETCH_ASSOC) ?: [];
foreach ($countRows as $countRow) {
    if (isset($counts[$countRow['status']])) $counts[$countRow['status']] = (int)$countRow['total'];
}
$successMessage = $_SESSION['success_message'] ?? null;
$errorMessage = $_SESSION['error_message'] ?? null;
unset($_SESSION['success_message'], $_SESSION['error_message']);

require_once '../includes/admin_header.php';
?>

<div class="admin-page-heading">
    <h1 class="h2"><i class="fas fa-hourglass-half me-2 text-warning"></i>العمليات المعلقة</h1>
</div>

<?php if ($successMessage): ?>
    <div class="alert alert-success alert-dismissible fade show"><i class="fas fa-check-circle me-2"></i><?php echo htmlspecialchars((string)$successMessage, ENT_QUOTES, 'UTF-8'); ?><button class="btn-close" data-bs-dismiss="alert"></button></div>
<?php endif; ?>
<?php if ($errorMessage): ?>
    <div class="alert alert-danger alert-dismissible fade show"><i class="fas fa-exclamation-circle me-2"></i><?php echo htmlspecialchars((string)$errorMessage, ENT_QUOTES, 'UTF-8'); ?><button class="btn-close" data-bs-dismiss="alert"></button></div>
<?php endif; ?>

<div class="row row-cols-2 row-cols-md-4 g-3 mb-4">
    <div class="col"><div class="stat-card" style="--card-gradient: linear-gradient(135deg, #f59e0b, #d97706);"><div class="stat-card-icon"><i class="fas fa-clock"></i></div><div class="stat-card-info"><div class="stat-card-number counter" data-target="<?php echo $counts['pending']; ?>">0</div><div class="stat-card-label">بانتظار المراجعة</div></div></div></div>
    <div class="col"><div class="stat-card" style="--card-gradient: linear-gradient(135deg, #10b981, #059669);"><div class="stat-card-icon"><i class="fas fa-check-double"></i></div><div class="stat-card-info"><div class="stat-card-number counter" data-target="<?php echo $counts['approved']; ?>">0</div><div class="stat-card-label">تمت الموافقة</div></div></div></div>
    <div class="col"><div class="stat-card" style="--card-gradient: linear-gradient(135deg, #ef4444, #dc2626);"><div class="stat-card-icon"><i class="fas fa-times-circle"></i></div><div class="stat-card-info"><div class="stat-card-number counter" data-target="<?php echo $counts['rejected']; ?>">0</div><div class="stat-card-label">مرفوضة</div></div></div></div>
    <div class="col"><div class="stat-card" style="--card-gradient: linear-gradient(135deg, #8b5cf6, #7c3aed);"><div class="stat-card-icon"><i class="fas fa-code-compare"></i></div><div class="stat-card-info"><div class="stat-card-number counter" data-target="<?php echo $counts['conflict']; ?>">0</div><div class="stat-card-label">تعارضات</div></div></div></div>
</div>

<form method="get" class="admin-filter-bar">
    <div class="admin-filter-controls">
        <select name="status" class="form-select form-select-sm admin-inline-select-sm" aria-label="فلترة الحالة" onchange="this.form.submit()">
            <?php foreach (['all' => 'الكل', 'pending' => 'معلقة', 'approved' => 'موافق عليها', 'rejected' => 'مرفوضة', 'conflict' => 'متعارضة'] as $key => $text): ?>
                <option value="<?php echo $key; ?>" <?php echo $status === $key ? 'selected' : ''; ?>><?php echo $text; ?></option>
            <?php endforeach; ?>
        </select>
    </div>
</form>

<div class="admin-list-surface">
    <div class="table-responsive admin-table-wrap">
        <table class="table table-hover table-striped align-middle admin-data-table">
            <thead><tr><th>#</th><th>الطالب</th><th>الأخصائي</th><th>الصف / الفصل</th><th>التغييرات</th><th>الحالة</th><th>التاريخ</th><th class="text-center">الإجراءات</th></tr></thead>
            <tbody>
            <?php $requestRowNumber = 1; ?>
            <?php foreach ($rows as $row): ?>
                <?php
                $before = json_decode((string)$row['before_payload'], true) ?: [];
                $proposed = json_decode((string)$row['proposed_payload'], true) ?: [];
                $diffRows = $changePresenter->diffRows($before, $proposed, $row);
                $statusMeta = [
                    'pending' => ['warning text-dark', 'معلق'], 'approved' => ['success', 'موافق عليه'],
                    'rejected' => ['danger', 'مرفوض'], 'conflict' => ['secondary', 'متعارض'], 'cancelled' => ['secondary', 'ملغي'],
                ][$row['status']] ?? ['secondary', (string)$row['status']];
                ?>
                <tr>
                    <td><?php echo $requestRowNumber++; ?></td>
                    <td><strong><?php echo htmlspecialchars((string)$row['student_name'], ENT_QUOTES, 'UTF-8'); ?></strong><div class="small text-muted"><?php echo htmlspecialchars((string)($row['student_code'] ?? ''), ENT_QUOTES, 'UTF-8'); ?></div></td>
                    <td><?php echo htmlspecialchars((string)$row['specialist_name'], ENT_QUOTES, 'UTF-8'); ?></td>
                    <td><?php echo htmlspecialchars(trim((string)($row['grade_name'] ?? '') . ' / ' . (string)($row['class_name'] ?? ''), ' /'), ENT_QUOTES, 'UTF-8'); ?></td>
                    <td>
                        <?php foreach ($diffRows as $diff): ?>
                            <div class="small mb-2"><strong><?php echo htmlspecialchars($diff['label'], ENT_QUOTES, 'UTF-8'); ?>:</strong> <span class="text-danger text-decoration-line-through"><?php echo htmlspecialchars($diff['before'], ENT_QUOTES, 'UTF-8'); ?></span> <i class="fas fa-arrow-left mx-1 text-muted"></i> <span class="text-success fw-semibold"><?php echo htmlspecialchars($diff['after'], ENT_QUOTES, 'UTF-8'); ?></span></div>
                        <?php endforeach; ?>
                        <?php if ($diffRows === []): ?><span class="small text-muted"><i class="fas fa-check-circle me-1"></i>لا توجد فروق فعلية قابلة للعرض.</span><?php endif; ?>
                    </td>
                    <td><span class="badge bg-<?php echo $statusMeta[0]; ?>"><?php echo $statusMeta[1]; ?></span><?php if (!empty($row['rejection_reason'])): ?><div class="small text-muted mt-1"><?php echo htmlspecialchars((string)$row['rejection_reason'], ENT_QUOTES, 'UTF-8'); ?></div><?php endif; ?></td>
                    <td><?php echo htmlspecialchars((string)$row['created_at'], ENT_QUOTES, 'UTF-8'); ?></td>
                    <td class="text-center actions-column admin-table-actions">
                        <?php if ($row['status'] === 'pending'): ?>
                            <button type="button" class="btn btn-action-pills btn-activate has-tooltip me-1 approve-request" data-bs-toggle="tooltip" title="موافقة" data-id="<?php echo (int)$row['id']; ?>" data-name="<?php echo htmlspecialchars((string)$row['student_name'], ENT_QUOTES, 'UTF-8'); ?>" aria-label="موافقة"><i class="fas fa-check"></i></button>
                            <button type="button" class="btn btn-action-pills btn-delete has-tooltip reject-request" data-bs-toggle="tooltip" title="رفض" data-id="<?php echo (int)$row['id']; ?>" data-name="<?php echo htmlspecialchars((string)$row['student_name'], ENT_QUOTES, 'UTF-8'); ?>" aria-label="رفض"><i class="fas fa-times"></i></button>
                        <?php else: ?><span class="text-muted">—</span><?php endif; ?>
                    </td>
                </tr>
            <?php endforeach; ?>
            <?php if (!$rows): ?><tr><td colspan="8" class="text-center text-muted py-5"><i class="fas fa-inbox fa-3x d-block mb-3"></i>لا توجد عمليات مطابقة.</td></tr><?php endif; ?>
            </tbody>
        </table>
    </div>
</div>

<div class="modal fade" id="approveRequestModal" tabindex="-1"><div class="modal-dialog"><div class="modal-content admin-modal admin-modal-premium admin-modal-create"><form method="post"><input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars((string)$_SESSION['csrf_token'], ENT_QUOTES, 'UTF-8'); ?>"><input type="hidden" name="request_id" id="approveRequestId"><div class="modal-header"><h5 class="modal-title"><i class="fas fa-check-double me-2"></i>الموافقة على التعديل</h5><button type="button" class="btn-close" data-bs-dismiss="modal"></button></div><div class="modal-body"><div class="text-center mb-3"><i class="fas fa-user-check text-success" style="font-size:3rem"></i></div><p class="text-center">تطبيق التعديلات المقترحة على الطالب <strong id="approveStudentName"></strong>؟</p><div class="alert alert-info"><i class="fas fa-info-circle me-2"></i>سيعاد فحص التعارض ثم يطبق التعديل ويسجل في سجل العمليات.</div></div><div class="modal-footer"><button type="button" class="btn btn-secondary" data-bs-dismiss="modal"><i class="fas fa-times me-1"></i>إلغاء</button><button type="submit" name="approve_request" class="btn btn-success"><i class="fas fa-check me-1"></i>موافقة وتطبيق</button></div></form></div></div></div>

<div class="modal fade" id="rejectRequestModal" tabindex="-1"><div class="modal-dialog"><div class="modal-content admin-modal admin-modal-premium admin-modal-delete"><form method="post"><input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars((string)$_SESSION['csrf_token'], ENT_QUOTES, 'UTF-8'); ?>"><input type="hidden" name="request_id" id="rejectRequestId"><div class="modal-header"><h5 class="modal-title"><i class="fas fa-times-circle me-2"></i>رفض طلب التعديل</h5><button type="button" class="btn-close" data-bs-dismiss="modal"></button></div><div class="modal-body"><div class="text-center mb-3"><i class="fas fa-ban text-danger" style="font-size:3rem"></i></div><p class="text-center">رفض طلب تعديل الطالب <strong id="rejectStudentName"></strong>؟</p><label class="form-label fw-bold" for="rejectionReason">سبب الرفض</label><textarea class="form-control" id="rejectionReason" name="rejection_reason" maxlength="500" required></textarea></div><div class="modal-footer"><button type="button" class="btn btn-secondary" data-bs-dismiss="modal"><i class="fas fa-times me-1"></i>إلغاء</button><button type="submit" name="reject_request" class="btn btn-danger"><i class="fas fa-ban me-1"></i>رفض الطلب</button></div></form></div></div></div>

<script>
document.addEventListener('click', function (event) {
    var approve = event.target.closest('.approve-request');
    if (approve) {
        document.getElementById('approveRequestId').value = approve.dataset.id;
        document.getElementById('approveStudentName').textContent = approve.dataset.name;
        new bootstrap.Modal(document.getElementById('approveRequestModal')).show();
    }
    var reject = event.target.closest('.reject-request');
    if (reject) {
        document.getElementById('rejectRequestId').value = reject.dataset.id;
        document.getElementById('rejectStudentName').textContent = reject.dataset.name;
        document.getElementById('rejectionReason').value = '';
        new bootstrap.Modal(document.getElementById('rejectRequestModal')).show();
    }
});
</script>

<?php require_once '../includes/admin_footer.php'; ?>
