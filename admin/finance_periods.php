<?php

declare(strict_types=1);

use EduCore\Modules\Finance\Infrastructure\FinanceServiceFactory;

require_once '../config/database.php';
require_once '../classes/utilities.php';
require_once '../includes/session_config.php';
require_once '../includes/csrf.php';
require_once '../vendor/autoload.php';
Utilities::validateSession('admin');
requireCsrfPost();

$financeActionHandler = static function (FinanceServiceFactory $factory, array $post, int $actorId): void {
    $action = (string) ($post['action'] ?? '');
    if ($action === 'create_period') {
        $factory->financePeriodService()->createPeriod((int) ($post['academic_year_id'] ?? 0), (string) ($post['name'] ?? ''), ($post['start_date'] ?? '') !== '' ? (string) $post['start_date'] : null, ($post['end_date'] ?? '') !== '' ? (string) $post['end_date'] : null, $actorId);
        return;
    }
    if ($action === 'request_close') {
        $factory->approvalWorkflowService()->request('period_close', ['period_id' => (int) ($post['period_id'] ?? 0)], $actorId);
        return;
    }
    if ($action === 'request_reopen') {
        $factory->approvalWorkflowService()->request('period_reopen', ['period_id' => (int) ($post['period_id'] ?? 0), 'reason' => (string) ($post['reason'] ?? '')], $actorId);
        return;
    }
    throw new InvalidArgumentException('عملية الفترة المالية غير مدعومة.');
};

$financePage = [
    'title' => 'الفترات المالية', 'icon' => 'fa-calendar-check', 'view' => 'periods', 'create_modal' => 'createPeriodModal', 'create_label' => 'فترة مالية جديدة',
    'columns' => [
        ['key' => 'id', 'label' => 'الرقم'], ['key' => 'academic_year_name', 'label' => 'العام الدراسي'], ['key' => 'name', 'label' => 'الفترة'],
        ['key' => 'start_date', 'label' => 'من'], ['key' => 'end_date', 'label' => 'إلى'], ['key' => 'status', 'label' => 'الحالة', 'type' => 'status'],
        ['key' => 'closed_at', 'label' => 'تاريخ الإقفال'], ['key' => 'reopened_at', 'label' => 'آخر إعادة فتح'],
    ],
];
$financeRowActions = static function (array $row): string {
    if ((string) ($row['status'] ?? '') === 'closed') {
        return '<button type="button" class="btn btn-action-pills btn-activate" data-bs-toggle="modal" data-bs-target="#periodTransitionModal" data-period-id="' . (int) $row['id'] . '" data-action="request_reopen" data-label="إعادة فتح الفترة" title="إعادة فتح"><i class="fas fa-lock-open"></i></button>';
    }
    return '<button type="button" class="btn btn-action-pills btn-deactivate" data-bs-toggle="modal" data-bs-target="#periodTransitionModal" data-period-id="' . (int) $row['id'] . '" data-action="request_close" data-label="إقفال الفترة" title="إقفال"><i class="fas fa-lock"></i></button>';
};
$financeModalRenderer = static function (): void { ?>
<div class="modal fade" id="createPeriodModal" tabindex="-1"><div class="modal-dialog"><div class="modal-content admin-modal admin-modal-premium admin-modal-create"><form method="post" action="finance_periods.php"><input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($_SESSION['csrf_token'] ?? '', ENT_QUOTES, 'UTF-8'); ?>"><input type="hidden" name="action" value="create_period"><div class="modal-header"><h5 class="modal-title"><i class="fas fa-calendar-plus me-2"></i>فترة مالية جديدة</h5><button type="button" class="btn-close" data-bs-dismiss="modal"></button></div><div class="modal-body"><div class="mb-3"><label class="form-label">العام الدراسي</label><input type="number" min="1" name="academic_year_id" class="form-control" required></div><div class="mb-3"><label class="form-label">اسم الفترة</label><input name="name" class="form-control" required></div><div class="row g-3"><div class="col-md-6"><label class="form-label">من</label><input type="date" name="start_date" class="form-control"></div><div class="col-md-6"><label class="form-label">إلى</label><input type="date" name="end_date" class="form-control"></div></div></div><div class="modal-footer"><button type="button" class="btn btn-secondary" data-bs-dismiss="modal"><i class="fas fa-times me-1"></i>إلغاء</button><button type="submit" class="btn btn-success"><i class="fas fa-save me-1"></i>إنشاء</button></div></form></div></div></div>
<div class="modal fade" id="periodTransitionModal" tabindex="-1"><div class="modal-dialog"><div class="modal-content admin-modal admin-modal-premium admin-modal-confirm"><form method="post" action="finance_periods.php"><input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($_SESSION['csrf_token'] ?? '', ENT_QUOTES, 'UTF-8'); ?>"><input type="hidden" name="action" id="periodAction"><input type="hidden" name="period_id" id="periodId"><div class="modal-header"><h5 class="modal-title"><i class="fas fa-user-check me-2"></i><span id="periodActionLabel">طلب اعتماد</span></h5><button type="button" class="btn-close" data-bs-dismiss="modal"></button></div><div class="modal-body"><div class="text-center mb-3"><i class="fas fa-calendar-check text-primary" style="font-size:3rem"></i></div><p class="text-center">سيُنشأ طلب اعتماد، ويجب أن ينفذه مستخدم آخر.</p><div class="mb-3" id="reopenReasonWrap"><label class="form-label">سبب إعادة الفتح</label><textarea name="reason" id="reopenReason" class="form-control" rows="3"></textarea></div></div><div class="modal-footer"><button type="button" class="btn btn-secondary" data-bs-dismiss="modal"><i class="fas fa-times me-1"></i>إلغاء</button><button type="submit" class="btn btn-primary"><i class="fas fa-paper-plane me-1"></i>إرسال للاعتماد</button></div></form></div></div></div>
<script>document.getElementById('periodTransitionModal').addEventListener('show.bs.modal',function(e){var d=e.relatedTarget.dataset;document.getElementById('periodId').value=d.periodId;document.getElementById('periodAction').value=d.action;document.getElementById('periodActionLabel').textContent=d.label;var reopen=d.action==='request_reopen';document.getElementById('reopenReasonWrap').style.display=reopen?'block':'none';document.getElementById('reopenReason').required=reopen;});</script>
<?php };
require __DIR__ . '/includes/finance_list_page.php';
require_once '../includes/admin_footer.php';
