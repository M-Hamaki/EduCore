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
    if ($action === 'post_receipt') {
        $amountText = (string) ($post['amount'] ?? '');
        if (preg_match('/^\d+(?:\.\d{1,2})?$/', $amountText) !== 1) { throw new InvalidArgumentException('قيمة الإيصال غير صحيحة.'); }
        $amount = Money::fromDecimalString($amountText);
        $studentId = (int) ($post['student_id'] ?? 0);
        $yearId = (int) ($post['academic_year_id'] ?? 0);
        $requestId = bin2hex(random_bytes(16));
        $chargeId = (int) ($post['charge_id'] ?? 0);
        $allocation = $chargeId > 0
            ? $factory->paymentAllocationService()->autoAllocateToOldest($studentId, $yearId, $chargeId, $amount, $requestId, $actorId)
            : ['allocations' => [], 'overpayment' => $amount];
        $factory->receiptService()->postReceipt(
            (int) ($post['student_account_id'] ?? 0), $studentId, (int) ($post['cashbox_id'] ?? 0), $yearId,
            $amount, (string) ($post['payment_method'] ?? 'cash'), $requestId,
            $allocation['allocations'], $allocation['overpayment'], $actorId, date('Y-m-d'), 'auto_oldest'
        );
        return;
    }
    if ($action === 'reverse_receipt') {
        $factory->approvalWorkflowService()->request('receipt_reverse', ['receipt_id' => (int) ($post['receipt_id'] ?? 0), 'student_id' => (int) ($post['student_id'] ?? 0), 'entry_date' => date('Y-m-d')], $actorId);
        return;
    }
    throw new InvalidArgumentException('عملية الإيصال غير مدعومة.');
};
$financePage = [
    'title' => 'التحصيل والإيصالات', 'icon' => 'fa-cash-register', 'view' => 'receipts', 'create_modal' => 'receiptModal', 'create_label' => 'إيصال جديد',
    'money_total_field' => 'gross_amount',
    'columns' => [
        ['key' => 'receipt_number', 'label' => 'رقم الإيصال'], ['key' => 'student_id', 'label' => 'الطالب'],
        ['key' => 'cashbox_code', 'label' => 'الصندوق'], ['key' => 'gross_amount', 'label' => 'المبلغ', 'type' => 'money'],
        ['key' => 'payment_method', 'label' => 'طريقة الدفع'], ['key' => 'posted_at', 'label' => 'التاريخ', 'type' => 'date'],
        ['key' => 'status', 'label' => 'الحالة', 'type' => 'status'], ['key' => 'reversal_of', 'label' => 'عكس إيصال'],
    ],
];
$financeRowActions = static function (array $row): string {
    if (($row['status'] ?? '') !== 'posted' || !empty($row['reversal_of'])) { return ''; }
    return '<button type="button" class="btn btn-action-pills btn-deactivate" data-bs-toggle="modal" data-bs-target="#reverseReceiptModal" data-receipt-id="' . (int) $row['id'] . '" data-student-id="' . (int) $row['student_id'] . '" title="عكس الإيصال"><i class="fas fa-undo"></i></button>';
};
$financeModalRenderer = static function (): void { ?>
<div class="modal fade" id="receiptModal" tabindex="-1"><div class="modal-dialog"><div class="modal-content admin-modal admin-modal-premium admin-modal-create"><form method="post" action="finance_receipts.php"><input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($_SESSION['csrf_token'] ?? '', ENT_QUOTES, 'UTF-8'); ?>"><input type="hidden" name="action" value="post_receipt"><div class="modal-header"><h5 class="modal-title"><i class="fas fa-plus-circle me-2"></i>إيصال جديد</h5><button type="button" class="btn-close" data-bs-dismiss="modal"></button></div><div class="modal-body">
<?php foreach ([['student_account_id','حساب الطالب'],['student_id','الطالب'],['academic_year_id','العام الدراسي'],['cashbox_id','الصندوق']] as $field): ?><div class="mb-3"><label class="form-label"><?php echo $field[1]; ?></label><input type="number" min="1" name="<?php echo $field[0]; ?>" class="form-control" required></div><?php endforeach; ?>
<div class="mb-3"><label class="form-label">الاستحقاق المراد توزيعه (اختياري)</label><input type="number" min="1" name="charge_id" class="form-control"><div class="form-text">عند تركه فارغًا يُسجل المبلغ كرصد مقدم غير مخصص.</div></div><div class="mb-3"><label class="form-label">المبلغ</label><input type="number" step="0.01" min="0.01" name="amount" class="form-control" required></div><div class="mb-3"><label class="form-label">طريقة الدفع</label><select name="payment_method" class="form-select"><option value="cash">نقدي</option><option value="bank_transfer">تحويل بنكي</option><option value="check">شيك</option><option value="card">بطاقة</option><option value="other">أخرى</option></select></div></div><div class="modal-footer"><button type="button" class="btn btn-secondary" data-bs-dismiss="modal"><i class="fas fa-times me-1"></i>إلغاء</button><button type="submit" class="btn btn-success"><i class="fas fa-save me-1"></i>تسجيل الإيصال</button></div></form></div></div></div>
<div class="modal fade" id="reverseReceiptModal" tabindex="-1"><div class="modal-dialog"><div class="modal-content admin-modal admin-modal-premium admin-modal-delete"><form method="post" action="finance_receipts.php"><input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($_SESSION['csrf_token'] ?? '', ENT_QUOTES, 'UTF-8'); ?>"><input type="hidden" name="action" value="reverse_receipt"><input type="hidden" name="receipt_id" id="reverseReceiptId"><input type="hidden" name="student_id" id="reverseStudentId"><div class="modal-header"><h5 class="modal-title"><i class="fas fa-undo me-2"></i>طلب عكس الإيصال</h5><button type="button" class="btn-close" data-bs-dismiss="modal"></button></div><div class="modal-body"><div class="text-center mb-3"><i class="fas fa-undo text-danger" style="font-size:3rem"></i></div><p class="text-center">سيُنشأ طلب اعتماد؛ لا تُنفذ الحركة حتى يعتمدها مستخدم آخر من صفحة طلبات الاعتماد.</p></div><div class="modal-footer"><button type="button" class="btn btn-secondary" data-bs-dismiss="modal"><i class="fas fa-times me-1"></i>إلغاء</button><button type="submit" class="btn btn-danger"><i class="fas fa-paper-plane me-1"></i>إرسال الطلب</button></div></form></div></div></div>
<script>document.getElementById('reverseReceiptModal').addEventListener('show.bs.modal',function(e){document.getElementById('reverseReceiptId').value=e.relatedTarget.dataset.receiptId;document.getElementById('reverseStudentId').value=e.relatedTarget.dataset.studentId;});</script>
<?php };
require __DIR__ . '/includes/finance_list_page.php';
require_once '../includes/admin_footer.php';
