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
    $amount = trim((string) ($post['amount'] ?? ''));
    if (in_array($action, ['request_allocation_refund', 'request_credit_refund'], true) && preg_match('/^\d+(?:\.\d{1,2})?$/', $amount) !== 1) { throw new InvalidArgumentException('قيمة الاسترداد غير صحيحة.'); }
    if ($action === 'request_allocation_refund') {
        $factory->approvalWorkflowService()->request('refund_allocation', ['allocation_id' => (int) ($post['allocation_id'] ?? 0), 'receipt_id' => (int) ($post['receipt_id'] ?? 0), 'student_id' => (int) ($post['student_id'] ?? 0), 'academic_year_id' => (int) ($post['academic_year_id'] ?? 0), 'amount' => $amount], $actorId);
        return;
    }
    if ($action === 'request_credit_refund') {
        $factory->approvalWorkflowService()->request('refund_unapplied_credit', ['credit_id' => (int) ($post['credit_id'] ?? 0), 'student_id' => (int) ($post['student_id'] ?? 0), 'academic_year_id' => (int) ($post['academic_year_id'] ?? 0), 'amount' => $amount], $actorId);
        return;
    }
    if ($action === 'request_refund_reversal') {
        $factory->approvalWorkflowService()->request('refund_reverse', ['refund_id' => (int) ($post['refund_id'] ?? 0), 'student_id' => (int) ($post['student_id'] ?? 0), 'academic_year_id' => (int) ($post['academic_year_id'] ?? 0), 'entry_date' => (string) ($post['entry_date'] ?? date('Y-m-d'))], $actorId);
        return;
    }
    throw new InvalidArgumentException('عملية الاسترداد غير مدعومة.');
};

$financePage = [
    'title' => 'استردادات الطلاب', 'icon' => 'fa-hand-holding-usd', 'view' => 'refunds', 'create_modal' => 'refundModal', 'create_label' => 'طلب استرداد', 'money_total_field' => 'signed_amount',
    'columns' => [
        ['key' => 'id', 'label' => 'الرقم'], ['key' => 'receipt_number', 'label' => 'الإيصال'], ['key' => 'student_id', 'label' => 'الطالب'], ['key' => 'academic_year_id', 'label' => 'العام'],
        ['key' => 'refund_type', 'label' => 'النوع'], ['key' => 'signed_amount', 'label' => 'القيمة', 'type' => 'money'], ['key' => 'payment_method', 'label' => 'طريقة الدفع'],
        ['key' => 'status', 'label' => 'الحالة', 'type' => 'status'], ['key' => 'reversal_of', 'label' => 'عكس'], ['key' => 'posted_at', 'label' => 'تاريخ الترحيل'],
    ],
];
$financeRowActions = static function (array $row): string {
    if ((string) ($row['status'] ?? '') !== 'posted' || !empty($row['reversal_of'])) { return ''; }
    return '<button type="button" class="btn btn-action-pills btn-deactivate" data-bs-toggle="modal" data-bs-target="#refundReversalModal" data-refund-id="' . (int) $row['id'] . '" data-student-id="' . (int) $row['student_id'] . '" data-year-id="' . (int) $row['academic_year_id'] . '" title="عكس الاسترداد"><i class="fas fa-undo"></i></button>';
};
$financeModalRenderer = static function (): void { ?>
<div class="modal fade" id="refundModal" tabindex="-1"><div class="modal-dialog"><div class="modal-content admin-modal admin-modal-premium admin-modal-create"><form method="post" action="finance_refunds.php"><input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($_SESSION['csrf_token'] ?? '', ENT_QUOTES, 'UTF-8'); ?>"><div class="modal-header"><h5 class="modal-title"><i class="fas fa-hand-holding-usd me-2"></i>طلب استرداد</h5><button type="button" class="btn-close" data-bs-dismiss="modal"></button></div><div class="modal-body"><div class="mb-3"><label class="form-label">مصدر الاسترداد</label><select name="action" id="refundAction" class="form-select"><option value="request_allocation_refund">دفعة موزعة على قسط</option><option value="request_credit_refund">رصيد دائن غير موزع</option></select></div><div class="mb-3" id="allocationFields"><label class="form-label">معرف التوزيع</label><input type="number" min="1" name="allocation_id" class="form-control"><label class="form-label mt-2">معرف الإيصال</label><input type="number" min="1" name="receipt_id" class="form-control"></div><div class="mb-3" id="creditFields"><label class="form-label">معرف الرصيد الدائن</label><input type="number" min="1" name="credit_id" class="form-control"></div><div class="row g-3"><div class="col-md-6"><label class="form-label">الطالب</label><input type="number" min="1" name="student_id" class="form-control" required></div><div class="col-md-6"><label class="form-label">العام الدراسي</label><input type="number" min="1" name="academic_year_id" class="form-control" required></div></div><div class="mt-3"><label class="form-label">القيمة</label><input type="number" min="0.01" step="0.01" name="amount" class="form-control" required></div><div class="alert alert-info mt-3"><i class="fas fa-info-circle me-2"></i>تُحفظ طريقة الدفع من الإيصال الأصلي ولا يمكن تغييرها هنا.</div></div><div class="modal-footer"><button type="button" class="btn btn-secondary" data-bs-dismiss="modal"><i class="fas fa-times me-1"></i>إلغاء</button><button type="submit" class="btn btn-primary"><i class="fas fa-paper-plane me-1"></i>إرسال للاعتماد</button></div></form></div></div></div>
<div class="modal fade" id="refundReversalModal" tabindex="-1"><div class="modal-dialog"><div class="modal-content admin-modal admin-modal-premium admin-modal-confirm"><form method="post" action="finance_refunds.php"><input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($_SESSION['csrf_token'] ?? '', ENT_QUOTES, 'UTF-8'); ?>"><input type="hidden" name="action" value="request_refund_reversal"><input type="hidden" name="refund_id" id="reverseRefundId"><input type="hidden" name="student_id" id="reverseRefundStudent"><input type="hidden" name="academic_year_id" id="reverseRefundYear"><div class="modal-header"><h5 class="modal-title"><i class="fas fa-undo me-2"></i>طلب عكس الاسترداد</h5><button type="button" class="btn-close" data-bs-dismiss="modal"></button></div><div class="modal-body"><div class="text-center mb-3"><i class="fas fa-undo text-warning" style="font-size:3rem"></i></div><label class="form-label">تاريخ العكس</label><input type="date" name="entry_date" value="<?php echo date('Y-m-d'); ?>" class="form-control" required></div><div class="modal-footer"><button type="button" class="btn btn-secondary" data-bs-dismiss="modal"><i class="fas fa-times me-1"></i>إلغاء</button><button type="submit" class="btn btn-primary"><i class="fas fa-paper-plane me-1"></i>إرسال للاعتماد</button></div></form></div></div></div>
<script>function toggleRefundSource(){var credit=document.getElementById('refundAction').value==='request_credit_refund';document.getElementById('allocationFields').style.display=credit?'none':'block';document.getElementById('creditFields').style.display=credit?'block':'none';}document.getElementById('refundAction').addEventListener('change',toggleRefundSource);toggleRefundSource();document.getElementById('refundReversalModal').addEventListener('show.bs.modal',function(e){var d=e.relatedTarget.dataset;document.getElementById('reverseRefundId').value=d.refundId;document.getElementById('reverseRefundStudent').value=d.studentId;document.getElementById('reverseRefundYear').value=d.yearId;});</script>
<?php };
require __DIR__ . '/includes/finance_list_page.php';
require_once '../includes/admin_footer.php';
