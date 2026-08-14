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
    if ($action === 'request_manual_journal') {
        $lines = [];
        foreach ((array) ($post['account_id'] ?? []) as $index => $accountId) {
            if ((int) $accountId <= 0) { continue; }
            $lines[] = ['account_id' => (int) $accountId, 'cost_center_id' => (int) (($post['cost_center_id'][$index] ?? 0)) ?: null, 'debit' => (string) ($post['debit'][$index] ?? '0'), 'credit' => (string) ($post['credit'][$index] ?? '0'), 'description' => (string) ($post['line_description'][$index] ?? '')];
        }
        $factory->approvalWorkflowService()->request('manual_journal_post', ['academic_year_id' => (int) ($post['academic_year_id'] ?? 0), 'finance_period_id' => (int) ($post['finance_period_id'] ?? 0), 'entry_date' => (string) ($post['entry_date'] ?? ''), 'lines' => $lines, 'description' => (string) ($post['description'] ?? ''), 'idempotency_key' => bin2hex(random_bytes(16))], $actorId);
        return;
    }
    if ($action === 'request_manual_reversal') {
        $factory->approvalWorkflowService()->request('manual_journal_reverse', ['original_idempotency_key' => (string) ($post['original_idempotency_key'] ?? ''), 'entry_date' => (string) ($post['entry_date'] ?? ''), 'reason' => (string) ($post['reason'] ?? ''), 'idempotency_key' => bin2hex(random_bytes(16))], $actorId);
        return;
    }
    throw new InvalidArgumentException('عملية دفتر اليومية غير مدعومة.');
};

$financePage = [
    'title' => 'دفتر اليومية والأستاذ العام', 'icon' => 'fa-book', 'view' => 'journal', 'money_total_field' => 'total_debit', 'create_modal' => 'manualJournalModal', 'create_label' => 'قيد يدوي',
    'columns' => [
        ['key' => 'entry_number', 'label' => 'رقم القيد'], ['key' => 'entry_date', 'label' => 'التاريخ', 'type' => 'date'], ['key' => 'source_type', 'label' => 'المصدر'],
        ['key' => 'total_debit', 'label' => 'مدين', 'type' => 'money'], ['key' => 'total_credit', 'label' => 'دائن', 'type' => 'money'], ['key' => 'status', 'label' => 'الحالة', 'type' => 'status'],
        ['key' => 'subledger_transaction_id', 'label' => 'رابط الأستاذ المساعد'], ['key' => 'reversal_of', 'label' => 'عكس القيد'],
    ],
];
$financeRowActions = static function (array $row): string {
    if ((string) ($row['source_type'] ?? '') !== 'manual' || (string) ($row['status'] ?? '') !== 'posted' || !empty($row['reversal_of'])) { return ''; }
    return '<button type="button" class="btn btn-action-pills btn-deactivate" data-bs-toggle="modal" data-bs-target="#manualReversalModal" data-idempotency-key="' . htmlspecialchars((string) $row['source_idempotency_key'], ENT_QUOTES, 'UTF-8') . '" title="عكس القيد"><i class="fas fa-undo"></i></button>';
};
$financeModalRenderer = static function (): void { ?>
<div class="modal fade" id="manualJournalModal" tabindex="-1"><div class="modal-dialog modal-lg"><div class="modal-content admin-modal admin-modal-premium admin-modal-create"><form method="post" action="finance_journal.php"><input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($_SESSION['csrf_token'] ?? '', ENT_QUOTES, 'UTF-8'); ?>"><input type="hidden" name="action" value="request_manual_journal"><div class="modal-header"><h5 class="modal-title"><i class="fas fa-book me-2"></i>طلب قيد يدوي</h5><button type="button" class="btn-close" data-bs-dismiss="modal"></button></div><div class="modal-body"><div class="row g-3 mb-3"><div class="col-md-4"><label class="form-label">العام الدراسي</label><input type="number" min="1" name="academic_year_id" class="form-control" required></div><div class="col-md-4"><label class="form-label">الفترة المالية</label><input type="number" min="1" name="finance_period_id" class="form-control" required></div><div class="col-md-4"><label class="form-label">التاريخ</label><input type="date" name="entry_date" value="<?php echo date('Y-m-d'); ?>" class="form-control" required></div></div><div class="mb-3"><label class="form-label">البيان العام</label><input name="description" class="form-control" required></div><div class="table-responsive"><table class="table table-striped"><thead><tr><th>الحساب</th><th>مركز التكلفة</th><th>مدين</th><th>دائن</th><th>البيان</th></tr></thead><tbody><?php for ($i = 0; $i < 6; $i++): ?><tr><td><input type="number" min="1" name="account_id[]" class="form-control" <?php echo $i < 2 ? 'required' : ''; ?>></td><td><input type="number" min="1" name="cost_center_id[]" class="form-control"></td><td><input type="number" min="0" step="0.01" name="debit[]" value="0.00" class="form-control"></td><td><input type="number" min="0" step="0.01" name="credit[]" value="0.00" class="form-control"></td><td><input name="line_description[]" class="form-control"></td></tr><?php endfor; ?></tbody></table></div><div class="alert alert-warning"><i class="fas fa-user-check me-2"></i>لن يُرحّل القيد إلا بعد اعتماد مستخدم آخر، ويُرفض أي حساب رقابي للطلاب أو العاملين.</div></div><div class="modal-footer"><button type="button" class="btn btn-secondary" data-bs-dismiss="modal"><i class="fas fa-times me-1"></i>إلغاء</button><button type="submit" class="btn btn-primary"><i class="fas fa-paper-plane me-1"></i>إرسال للاعتماد</button></div></form></div></div></div>
<div class="modal fade" id="manualReversalModal" tabindex="-1"><div class="modal-dialog"><div class="modal-content admin-modal admin-modal-premium admin-modal-confirm"><form method="post" action="finance_journal.php"><input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($_SESSION['csrf_token'] ?? '', ENT_QUOTES, 'UTF-8'); ?>"><input type="hidden" name="action" value="request_manual_reversal"><input type="hidden" name="original_idempotency_key" id="manualOriginalKey"><div class="modal-header"><h5 class="modal-title"><i class="fas fa-undo me-2"></i>طلب عكس القيد</h5><button type="button" class="btn-close" data-bs-dismiss="modal"></button></div><div class="modal-body"><div class="text-center mb-3"><i class="fas fa-undo text-warning" style="font-size:3rem"></i></div><div class="mb-3"><label class="form-label">تاريخ العكس</label><input type="date" name="entry_date" value="<?php echo date('Y-m-d'); ?>" class="form-control" required></div><div class="mb-3"><label class="form-label">سبب العكس</label><textarea name="reason" class="form-control" required></textarea></div></div><div class="modal-footer"><button type="button" class="btn btn-secondary" data-bs-dismiss="modal"><i class="fas fa-times me-1"></i>إلغاء</button><button type="submit" class="btn btn-primary"><i class="fas fa-paper-plane me-1"></i>إرسال للاعتماد</button></div></form></div></div></div>
<script>document.getElementById('manualReversalModal').addEventListener('show.bs.modal',function(e){document.getElementById('manualOriginalKey').value=e.relatedTarget.dataset.idempotencyKey;});</script>
<?php };
require __DIR__ . '/includes/finance_list_page.php';
require_once '../includes/admin_footer.php';
