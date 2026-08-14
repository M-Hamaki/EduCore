<?php
use EduCore\Modules\Finance\Domain\Money;
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
    $amountText = (string) ($post['amount'] ?? '');
    if (preg_match('/^\d+(?:\.\d{1,2})?$/', $amountText) !== 1) { throw new InvalidArgumentException('قيمة السلفة أو الحركة غير صحيحة.'); }
    $amount = Money::fromDecimalString($amountText);
    if ($action === 'issue_advance') {
        $factory->staffAdvanceService()->issueAdvance((int) ($post['staff_id'] ?? 0), $amount, (string) ($post['issue_date'] ?? ''), (string) ($post['reason'] ?? ''), $actorId);
        return;
    }
    if ($action === 'cash_repayment') {
        $factory->staffAdvanceService()->recordCashRepayment((int) ($post['advance_id'] ?? 0), (int) ($post['staff_id'] ?? 0), (int) ($post['cashbox_id'] ?? 0), $amount, $actorId);
        return;
    }
    if ($action === 'write_off_advance') {
        $factory->approvalWorkflowService()->request('advance_write_off', ['advance_id' => (int) ($post['advance_id'] ?? 0), 'staff_id' => (int) ($post['staff_id'] ?? 0), 'amount' => $amount->toDatabaseString(), 'reason' => (string) ($post['reason'] ?? '')], $actorId);
        return;
    }
    throw new InvalidArgumentException('عملية السلفة غير مدعومة.');
};
$financePage = [
    'title' => 'سلف العاملين', 'icon' => 'fa-hand-holding-usd', 'view' => 'staff_advances', 'create_modal' => 'advanceModal', 'create_label' => 'صرف سلفة',
    'money_total_field' => 'amount', 'filters' => ['staff_id'],
    'columns' => [
        ['key' => 'id', 'label' => 'السلفة'], ['key' => 'staff_id', 'label' => 'العامل'],
        ['key' => 'amount', 'label' => 'القيمة الأصلية', 'type' => 'money'], ['key' => 'issue_date', 'label' => 'تاريخ الصرف', 'type' => 'date'],
        ['key' => 'reason', 'label' => 'السبب'], ['key' => 'status', 'label' => 'الحالة', 'type' => 'status'],
    ],
];
$financeRowActions = static function (array $row): string {
    if (($row['status'] ?? '') !== 'active') { return ''; }
    $data = ' data-advance-id="' . (int) $row['id'] . '" data-staff-id="' . (int) $row['staff_id'] . '"';
    return '<button type="button" class="btn btn-action-pills btn-edit me-1" data-bs-toggle="modal" data-bs-target="#repaymentModal"' . $data . ' title="سداد نقدي"><i class="fas fa-money-bill-wave"></i></button><button type="button" class="btn btn-action-pills btn-deactivate" data-bs-toggle="modal" data-bs-target="#advanceWriteOffModal"' . $data . ' title="تسوية السلفة"><i class="fas fa-file-signature"></i></button>';
};
$financeModalRenderer = static function (): void { ?>
<div class="modal fade" id="advanceModal" tabindex="-1"><div class="modal-dialog"><div class="modal-content admin-modal admin-modal-premium admin-modal-create"><form method="post" action="finance_staff_advances.php"><input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($_SESSION['csrf_token'] ?? '', ENT_QUOTES, 'UTF-8'); ?>"><input type="hidden" name="action" value="issue_advance"><div class="modal-header"><h5 class="modal-title"><i class="fas fa-plus-circle me-2"></i>صرف سلفة</h5><button type="button" class="btn-close" data-bs-dismiss="modal"></button></div><div class="modal-body"><div class="mb-3"><label class="form-label">العامل</label><input type="number" min="1" name="staff_id" class="form-control" required></div><div class="mb-3"><label class="form-label">القيمة</label><input type="number" min="0.01" step="0.01" name="amount" class="form-control" required></div><div class="mb-3"><label class="form-label">تاريخ الصرف</label><input type="date" name="issue_date" class="form-control" required></div><div class="mb-3"><label class="form-label">السبب</label><textarea name="reason" class="form-control" required></textarea></div></div><div class="modal-footer"><button type="button" class="btn btn-secondary" data-bs-dismiss="modal"><i class="fas fa-times me-1"></i>إلغاء</button><button type="submit" class="btn btn-success"><i class="fas fa-save me-1"></i>صرف السلفة</button></div></form></div></div></div>
<div class="modal fade" id="repaymentModal" tabindex="-1"><div class="modal-dialog"><div class="modal-content admin-modal admin-modal-premium admin-modal-confirm"><form method="post" action="finance_staff_advances.php"><input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($_SESSION['csrf_token'] ?? '', ENT_QUOTES, 'UTF-8'); ?>"><input type="hidden" name="action" value="cash_repayment"><input type="hidden" name="advance_id" class="advance-id"><input type="hidden" name="staff_id" class="advance-staff-id"><div class="modal-header"><h5 class="modal-title"><i class="fas fa-money-bill-wave me-2"></i>سداد نقدي للسلفة</h5><button type="button" class="btn-close" data-bs-dismiss="modal"></button></div><div class="modal-body"><div class="mb-3"><label class="form-label">الصندوق</label><input type="number" min="1" name="cashbox_id" class="form-control" required></div><div class="mb-3"><label class="form-label">قيمة السداد</label><input type="number" min="0.01" step="0.01" name="amount" class="form-control" required></div></div><div class="modal-footer"><button type="button" class="btn btn-secondary" data-bs-dismiss="modal"><i class="fas fa-times me-1"></i>إلغاء</button><button type="submit" class="btn btn-primary"><i class="fas fa-check me-1"></i>تسجيل السداد</button></div></form></div></div></div>
<div class="modal fade" id="advanceWriteOffModal" tabindex="-1"><div class="modal-dialog"><div class="modal-content admin-modal admin-modal-premium admin-modal-delete"><form method="post" action="finance_staff_advances.php"><input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($_SESSION['csrf_token'] ?? '', ENT_QUOTES, 'UTF-8'); ?>"><input type="hidden" name="action" value="write_off_advance"><input type="hidden" name="advance_id" class="advance-id"><input type="hidden" name="staff_id" class="advance-staff-id"><div class="modal-header"><h5 class="modal-title"><i class="fas fa-file-signature me-2"></i>طلب تسوية سلفة</h5><button type="button" class="btn-close" data-bs-dismiss="modal"></button></div><div class="modal-body"><div class="text-center mb-3"><i class="fas fa-file-signature text-danger" style="font-size:3rem"></i></div><div class="mb-3"><label class="form-label">القيمة</label><input type="number" min="0.01" step="0.01" name="amount" class="form-control" required></div><div class="mb-3"><label class="form-label">السبب</label><textarea name="reason" class="form-control" required></textarea></div><div class="alert alert-warning"><i class="fas fa-user-check me-2"></i>سيُرسل الطلب لمستخدم آخر لاعتماده قبل إنشاء الحركة.</div></div><div class="modal-footer"><button type="button" class="btn btn-secondary" data-bs-dismiss="modal"><i class="fas fa-times me-1"></i>إلغاء</button><button type="submit" class="btn btn-danger"><i class="fas fa-paper-plane me-1"></i>إرسال الطلب</button></div></form></div></div></div>
<script>['repaymentModal','advanceWriteOffModal'].forEach(function(id){document.getElementById(id).addEventListener('show.bs.modal',function(e){this.querySelector('.advance-id').value=e.relatedTarget.dataset.advanceId;this.querySelector('.advance-staff-id').value=e.relatedTarget.dataset.staffId;});});</script>
<?php };
require __DIR__ . '/includes/finance_list_page.php';
require_once '../includes/admin_footer.php';
