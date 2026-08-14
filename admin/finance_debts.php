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
    if (($post['action'] ?? '') !== 'write_off_debt') { throw new InvalidArgumentException('عملية المديونية غير مدعومة.'); }
    $amountText = (string) ($post['amount'] ?? '');
    if (preg_match('/^\d+(?:\.\d{1,2})?$/', $amountText) !== 1) { throw new InvalidArgumentException('قيمة التسوية غير صحيحة.'); }
    $factory->approvalWorkflowService()->request('debt_write_off', [
        'student_account_id' => (int) ($post['student_account_id'] ?? 0), 'student_id' => (int) ($post['student_id'] ?? 0),
        'academic_year_id' => (int) ($post['academic_year_id'] ?? 0), 'amount' => Money::fromDecimalString($amountText)->toDatabaseString(),
        'reason' => (string) ($post['reason'] ?? ''),
    ], $actorId);
};
$financePage = [
    'title' => 'مديونيات الطلاب', 'icon' => 'fa-exclamation-triangle', 'view' => 'debts',
    'money_total_field' => 'outstanding_due', 'filters' => ['student_id', 'academic_year_id'],
    'columns' => [
        ['key' => 'student_id', 'label' => 'الطالب'], ['key' => 'academic_year_id', 'label' => 'العام الدراسي'],
        ['key' => 'outstanding_due', 'label' => 'المديونية', 'type' => 'money'], ['key' => 'unapplied_credit', 'label' => 'رصيد مقدم', 'type' => 'money'],
        ['key' => 'net_account_position', 'label' => 'صافي المطلوب', 'type' => 'money'],
    ],
];
$financeRowActions = static function (array $row): string {
    return '<button type="button" class="btn btn-action-pills btn-edit" data-bs-toggle="modal" data-bs-target="#writeOffModal" data-account-id="' . (int) $row['student_account_id'] . '" data-student-id="' . (int) $row['student_id'] . '" data-year-id="' . (int) $row['academic_year_id'] . '" data-max-amount="' . htmlspecialchars((string) $row['outstanding_due'], ENT_QUOTES, 'UTF-8') . '" title="تسوية مديونية"><i class="fas fa-file-invoice-dollar"></i></button>';
};
$financeModalRenderer = static function (): void { ?>
<div class="modal fade" id="writeOffModal" tabindex="-1"><div class="modal-dialog"><div class="modal-content admin-modal admin-modal-premium admin-modal-confirm"><form method="post" action="finance_debts.php"><input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($_SESSION['csrf_token'] ?? '', ENT_QUOTES, 'UTF-8'); ?>"><input type="hidden" name="action" value="write_off_debt"><input type="hidden" name="student_account_id" id="debtAccountId"><input type="hidden" name="student_id" id="debtStudentId"><input type="hidden" name="academic_year_id" id="debtYearId"><div class="modal-header"><h5 class="modal-title"><i class="fas fa-file-invoice-dollar me-2"></i>طلب تسوية مديونية</h5><button type="button" class="btn-close" data-bs-dismiss="modal"></button></div><div class="modal-body"><div class="text-center mb-3"><i class="fas fa-balance-scale text-warning" style="font-size:3rem"></i></div><div class="mb-3"><label class="form-label">قيمة التسوية</label><input type="number" min="0.01" step="0.01" name="amount" id="debtAmount" class="form-control" required></div><div class="mb-3"><label class="form-label">سبب التسوية</label><textarea name="reason" class="form-control" required></textarea></div><div class="alert alert-warning"><i class="fas fa-info-circle me-2"></i>سيُرسل الطلب لمستخدم آخر لاعتماده، ولن تُحذف المديونية الأصلية.</div></div><div class="modal-footer"><button type="button" class="btn btn-secondary" data-bs-dismiss="modal"><i class="fas fa-times me-1"></i>إلغاء</button><button type="submit" class="btn btn-primary"><i class="fas fa-paper-plane me-1"></i>إرسال الطلب</button></div></form></div></div></div>
<script>document.getElementById('writeOffModal').addEventListener('show.bs.modal',function(e){var d=e.relatedTarget.dataset;document.getElementById('debtAccountId').value=d.accountId;document.getElementById('debtStudentId').value=d.studentId;document.getElementById('debtYearId').value=d.yearId;document.getElementById('debtAmount').max=d.maxAmount;});</script>
<?php };
require __DIR__ . '/includes/finance_list_page.php';
require_once '../includes/admin_footer.php';
