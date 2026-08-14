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
    if (($post['action'] ?? '') !== 'request_payment_reversal') { throw new InvalidArgumentException('عملية مدفوعات الرواتب غير مدعومة.'); }
    $factory->approvalWorkflowService()->request('payroll_payment_reverse', ['payment_id' => (int) ($post['payment_id'] ?? 0), 'staff_id' => (int) ($post['staff_id'] ?? 0), 'reason' => (string) ($post['reason'] ?? '')], $actorId);
};
$financePage = [
    'title' => 'مدفوعات الرواتب', 'icon' => 'fa-money-bill-wave', 'view' => 'payroll_payments', 'money_total_field' => 'amount',
    'toolbar_links' => [['href' => 'finance_payroll_items.php', 'label' => 'بنود وقسائم الرواتب', 'icon' => 'fa-file-invoice-dollar']],
    'columns' => [
        ['key' => 'id', 'label' => 'العملية'], ['key' => 'payroll_run_item_id', 'label' => 'بند الراتب'], ['key' => 'payroll_run_id', 'label' => 'الدورة'], ['key' => 'staff_id', 'label' => 'العامل'],
        ['key' => 'cashbox_code', 'label' => 'الصندوق'], ['key' => 'amount', 'label' => 'القيمة', 'type' => 'money'], ['key' => 'payment_method', 'label' => 'الطريقة'],
        ['key' => 'status', 'label' => 'الحالة', 'type' => 'status'], ['key' => 'reversal_of', 'label' => 'عكس'], ['key' => 'posted_at', 'label' => 'التاريخ'],
    ],
];
$financeRowActions = static function (array $row): string {
    if ((string) ($row['status'] ?? '') !== 'posted' || !empty($row['reversal_of'])) { return ''; }
    return '<button type="button" class="btn btn-action-pills btn-deactivate" data-bs-toggle="modal" data-bs-target="#payrollPaymentReverseModal" data-payment-id="' . (int) $row['id'] . '" data-staff-id="' . (int) $row['staff_id'] . '" title="عكس الصرف"><i class="fas fa-undo"></i></button>';
};
$financeModalRenderer = static function (): void { ?>
<div class="modal fade" id="payrollPaymentReverseModal" tabindex="-1"><div class="modal-dialog"><div class="modal-content admin-modal admin-modal-premium admin-modal-confirm"><form method="post" action="finance_payroll_payments.php"><input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($_SESSION['csrf_token'] ?? '', ENT_QUOTES, 'UTF-8'); ?>"><input type="hidden" name="action" value="request_payment_reversal"><input type="hidden" name="payment_id" id="reversePayrollPaymentId"><input type="hidden" name="staff_id" id="reversePayrollPaymentStaff"><div class="modal-header"><h5 class="modal-title"><i class="fas fa-undo me-2"></i>طلب عكس صرف راتب</h5><button type="button" class="btn-close" data-bs-dismiss="modal"></button></div><div class="modal-body"><div class="text-center mb-3"><i class="fas fa-undo text-warning" style="font-size:3rem"></i></div><label class="form-label">سبب العكس</label><textarea name="reason" class="form-control" required></textarea></div><div class="modal-footer"><button type="button" class="btn btn-secondary" data-bs-dismiss="modal"><i class="fas fa-times me-1"></i>إلغاء</button><button type="submit" class="btn btn-primary"><i class="fas fa-paper-plane me-1"></i>إرسال للاعتماد</button></div></form></div></div></div>
<script>document.getElementById('payrollPaymentReverseModal').addEventListener('show.bs.modal',function(e){var d=e.relatedTarget.dataset;document.getElementById('reversePayrollPaymentId').value=d.paymentId;document.getElementById('reversePayrollPaymentStaff').value=d.staffId;});</script>
<?php };
require __DIR__ . '/includes/finance_list_page.php';
require_once '../includes/admin_footer.php';
