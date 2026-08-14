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
    if ($action === 'request_post_item') {
        $factory->approvalWorkflowService()->request('payroll_item_post', ['run_id' => (int) ($post['run_id'] ?? 0), 'staff_id' => (int) ($post['staff_id'] ?? 0), 'calculation_date' => (string) ($post['calculation_date'] ?? '')], $actorId);
        return;
    }
    if ($action === 'request_payment') {
        $amount = trim((string) ($post['amount'] ?? ''));
        if (preg_match('/^\d+(?:\.\d{1,2})?$/', $amount) !== 1) { throw new InvalidArgumentException('قيمة صرف الراتب غير صحيحة.'); }
        $factory->approvalWorkflowService()->request('payroll_payment_post', ['item_id' => (int) ($post['item_id'] ?? 0), 'staff_id' => (int) ($post['staff_id'] ?? 0), 'cashbox_id' => (int) ($post['cashbox_id'] ?? 0), 'amount' => $amount, 'payment_method' => (string) ($post['payment_method'] ?? 'cash')], $actorId);
        return;
    }
    throw new InvalidArgumentException('عملية بند الراتب غير مدعومة.');
};

$financePage = [
    'title' => 'بنود وقسائم الرواتب', 'icon' => 'fa-file-invoice-dollar', 'view' => 'payroll_items', 'filters' => ['staff_id'], 'money_total_field' => 'net', 'create_modal' => 'payrollItemModal', 'create_label' => 'احتساب بند عامل',
    'toolbar_links' => [['href' => 'finance_payroll_runs.php', 'label' => 'دورات الرواتب', 'icon' => 'fa-money-check-alt'], ['href' => 'finance_payroll_payments.php', 'label' => 'مدفوعات الرواتب', 'icon' => 'fa-money-bill-wave']],
    'columns' => [
        ['key' => 'id', 'label' => 'البند'], ['key' => 'payroll_run_id', 'label' => 'الدورة'], ['key' => 'staff_id', 'label' => 'العامل'],
        ['key' => 'gross', 'label' => 'الإجمالي', 'type' => 'money'], ['key' => 'total_deductions', 'label' => 'الاستقطاعات', 'type' => 'money'], ['key' => 'net', 'label' => 'الصافي', 'type' => 'money'],
        ['key' => 'status', 'label' => 'الحالة', 'type' => 'status'], ['key' => 'payslip_ref_number', 'label' => 'مرجع القسيمة'], ['key' => 'payment_status', 'label' => 'السداد', 'type' => 'status'],
    ],
];
$financeRowActions = static function (array $row): string {
    $buttons = '<a class="btn btn-action-pills btn-edit me-1" href="finance_payslip.php?item_id=' . (int) $row['id'] . '" title="عرض وطباعة القسيمة"><i class="fas fa-print"></i></a>';
    if (empty($row['reversal_of']) && (string) ($row['payment_status'] ?? '') !== 'paid') { $buttons .= '<button type="button" class="btn btn-action-pills btn-activate" data-bs-toggle="modal" data-bs-target="#payrollPaymentModal" data-item-id="' . (int) $row['id'] . '" data-staff-id="' . (int) $row['staff_id'] . '" data-net="' . htmlspecialchars((string) $row['net'], ENT_QUOTES, 'UTF-8') . '" title="صرف الراتب"><i class="fas fa-money-bill-wave"></i></button>'; }
    return $buttons;
};
$financeModalRenderer = static function (): void { ?>
<div class="modal fade" id="payrollItemModal" tabindex="-1"><div class="modal-dialog"><div class="modal-content admin-modal admin-modal-premium admin-modal-create"><form method="post" action="finance_payroll_items.php"><input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($_SESSION['csrf_token'] ?? '', ENT_QUOTES, 'UTF-8'); ?>"><input type="hidden" name="action" value="request_post_item"><div class="modal-header"><h5 class="modal-title"><i class="fas fa-calculator me-2"></i>احتساب وترحيل بند عامل</h5><button type="button" class="btn-close" data-bs-dismiss="modal"></button></div><div class="modal-body"><div class="mb-3"><label class="form-label">دورة الرواتب</label><input type="number" min="1" name="run_id" class="form-control" required></div><div class="mb-3"><label class="form-label">العامل</label><input type="number" min="1" name="staff_id" class="form-control" required></div><div class="mb-3"><label class="form-label">تاريخ الاحتساب</label><input type="date" name="calculation_date" value="<?php echo date('Y-m-d'); ?>" class="form-control" required></div><div class="alert alert-info"><i class="fas fa-user-check me-2"></i>يُحتسب الراتب من العقد الفعّال على الخادم ويحتاج اعتماد مستخدم آخر.</div></div><div class="modal-footer"><button type="button" class="btn btn-secondary" data-bs-dismiss="modal"><i class="fas fa-times me-1"></i>إلغاء</button><button type="submit" class="btn btn-primary"><i class="fas fa-paper-plane me-1"></i>إرسال للاعتماد</button></div></form></div></div></div>
<div class="modal fade" id="payrollPaymentModal" tabindex="-1"><div class="modal-dialog"><div class="modal-content admin-modal admin-modal-premium admin-modal-confirm"><form method="post" action="finance_payroll_items.php"><input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($_SESSION['csrf_token'] ?? '', ENT_QUOTES, 'UTF-8'); ?>"><input type="hidden" name="action" value="request_payment"><input type="hidden" name="item_id" id="payItemId"><input type="hidden" name="staff_id" id="payStaffId"><div class="modal-header"><h5 class="modal-title"><i class="fas fa-money-bill-wave me-2"></i>طلب صرف راتب</h5><button type="button" class="btn-close" data-bs-dismiss="modal"></button></div><div class="modal-body"><div class="mb-3"><label class="form-label">الصندوق</label><input type="number" min="1" name="cashbox_id" class="form-control" required></div><div class="mb-3"><label class="form-label">القيمة</label><input type="number" min="0.01" step="0.01" name="amount" id="payAmount" class="form-control" required></div><div class="mb-3"><label class="form-label">طريقة الدفع</label><select name="payment_method" class="form-select"><option value="cash">نقدي</option><option value="bank_transfer">تحويل بنكي</option><option value="check">شيك</option><option value="card">بطاقة</option><option value="other">أخرى</option></select></div></div><div class="modal-footer"><button type="button" class="btn btn-secondary" data-bs-dismiss="modal"><i class="fas fa-times me-1"></i>إلغاء</button><button type="submit" class="btn btn-primary"><i class="fas fa-paper-plane me-1"></i>إرسال للاعتماد</button></div></form></div></div></div>
<script>document.getElementById('payrollPaymentModal').addEventListener('show.bs.modal',function(e){var d=e.relatedTarget.dataset;document.getElementById('payItemId').value=d.itemId;document.getElementById('payStaffId').value=d.staffId;document.getElementById('payAmount').value=d.net;});</script>
<?php };
require __DIR__ . '/includes/finance_list_page.php';
require_once '../includes/admin_footer.php';
