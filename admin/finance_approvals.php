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
    if ($action === 'approve_request') { $factory->approvalWorkflowService()->approve((int) ($post['request_id'] ?? 0), $actorId); return; }
    if ($action === 'reject_request') { $factory->approvalWorkflowService()->reject((int) ($post['request_id'] ?? 0), $actorId, (string) ($post['reason'] ?? '')); return; }
    throw new InvalidArgumentException('عملية الاعتماد غير مدعومة.');
};
$financePage = [
    'title' => 'طلبات الاعتماد المالي', 'icon' => 'fa-user-check', 'view' => 'approvals',
    'columns' => [
        ['key' => 'id', 'label' => 'الطلب'], ['key' => 'operation_type', 'label' => 'العملية'],
        ['key' => 'requested_by', 'label' => 'مقدم الطلب'], ['key' => 'requested_at', 'label' => 'وقت الطلب', 'type' => 'date'],
        ['key' => 'status', 'label' => 'الحالة', 'type' => 'status'], ['key' => 'decided_by', 'label' => 'صاحب القرار'],
        ['key' => 'decided_at', 'label' => 'وقت القرار', 'type' => 'date'], ['key' => 'result_ref_type', 'label' => 'نوع الناتج'],
        ['key' => 'result_ref_id', 'label' => 'رقم الناتج'],
    ],
];
$financeRowActions = static function (array $row): string {
    if (($row['status'] ?? '') !== 'pending') { return ''; }
    $id = (int) $row['id'];
    return '<button type="button" class="btn btn-action-pills btn-activate me-1" data-bs-toggle="modal" data-bs-target="#approveRequestModal" data-request-id="' . $id . '" title="اعتماد"><i class="fas fa-check"></i></button><button type="button" class="btn btn-action-pills btn-deactivate" data-bs-toggle="modal" data-bs-target="#rejectRequestModal" data-request-id="' . $id . '" title="رفض"><i class="fas fa-ban"></i></button>';
};
$financeModalRenderer = static function (): void { ?>
<div class="modal fade" id="approveRequestModal" tabindex="-1"><div class="modal-dialog"><div class="modal-content admin-modal admin-modal-premium admin-modal-confirm"><form method="post" action="finance_approvals.php"><input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($_SESSION['csrf_token'] ?? '', ENT_QUOTES, 'UTF-8'); ?>"><input type="hidden" name="action" value="approve_request"><input type="hidden" name="request_id" class="approval-request-id"><div class="modal-header"><h5 class="modal-title"><i class="fas fa-user-check me-2"></i>اعتماد العملية المالية</h5><button type="button" class="btn-close" data-bs-dismiss="modal"></button></div><div class="modal-body"><div class="text-center mb-3"><i class="fas fa-user-check text-success" style="font-size:3rem"></i></div><p class="text-center">سيتم تنفيذ العملية باسم مقدم الطلب واعتمادها بحسابك الحالي، وتُرفض تلقائيًا إذا كنت مقدم الطلب نفسه.</p></div><div class="modal-footer"><button type="button" class="btn btn-secondary" data-bs-dismiss="modal"><i class="fas fa-times me-1"></i>إلغاء</button><button type="submit" class="btn btn-primary"><i class="fas fa-check me-1"></i>اعتماد وتنفيذ</button></div></form></div></div></div>
<div class="modal fade" id="rejectRequestModal" tabindex="-1"><div class="modal-dialog"><div class="modal-content admin-modal admin-modal-premium admin-modal-delete"><form method="post" action="finance_approvals.php"><input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($_SESSION['csrf_token'] ?? '', ENT_QUOTES, 'UTF-8'); ?>"><input type="hidden" name="action" value="reject_request"><input type="hidden" name="request_id" class="approval-request-id"><div class="modal-header"><h5 class="modal-title"><i class="fas fa-ban me-2"></i>رفض طلب الاعتماد</h5><button type="button" class="btn-close" data-bs-dismiss="modal"></button></div><div class="modal-body"><div class="text-center mb-3"><i class="fas fa-ban text-danger" style="font-size:3rem"></i></div><div class="mb-3"><label class="form-label">سبب الرفض</label><textarea name="reason" class="form-control" required></textarea></div></div><div class="modal-footer"><button type="button" class="btn btn-secondary" data-bs-dismiss="modal"><i class="fas fa-times me-1"></i>إلغاء</button><button type="submit" class="btn btn-danger"><i class="fas fa-ban me-1"></i>رفض</button></div></form></div></div></div>
<script>['approveRequestModal','rejectRequestModal'].forEach(function(id){document.getElementById(id).addEventListener('show.bs.modal',function(e){this.querySelector('.approval-request-id').value=e.relatedTarget.dataset.requestId;});});</script>
<?php };
require __DIR__ . '/includes/finance_list_page.php';
require_once '../includes/admin_footer.php';
