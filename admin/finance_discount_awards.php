<?php

declare(strict_types=1);

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
    if ($action === 'approve_award') { $factory->approvalWorkflowService()->request('discount_award_approve', ['award_id' => (int) ($post['award_id'] ?? 0)], $actorId); return; }
    if ($action === 'apply_award') {
        $amount = (string) ($post['amount'] ?? '');
        if (preg_match('/^\d+(?:\.\d{1,2})?$/', $amount) !== 1) { throw new InvalidArgumentException('قيمة تطبيق الخصم غير صحيحة.'); }
        $factory->discountService()->applyDiscount((int) ($post['award_id'] ?? 0), (int) ($post['charge_id'] ?? 0), (int) ($post['installment_id'] ?? 0) ?: null, Money::fromDecimalString($amount));
        return;
    }
    throw new InvalidArgumentException('عملية طلب الخصم غير مدعومة.');
};
$financePage = [
    'title' => 'طلبات خصومات الطلاب', 'icon' => 'fa-user-tag', 'view' => 'discount_awards', 'money_total_field' => 'awarded_amount',
    'toolbar_links' => [['href' => 'finance_discounts.php', 'label' => 'قواعد الخصم', 'icon' => 'fa-percentage']],
    'columns' => [
        ['key' => 'id', 'label' => 'الطلب'], ['key' => 'student_id', 'label' => 'الطالب'], ['key' => 'rule_name', 'label' => 'قاعدة الخصم'],
        ['key' => 'awarded_amount', 'label' => 'القيمة', 'type' => 'money'], ['key' => 'reason', 'label' => 'السبب'],
        ['key' => 'requested_by', 'label' => 'مقدم الطلب'], ['key' => 'approved_by', 'label' => 'المعتمد'],
        ['key' => 'status', 'label' => 'الحالة', 'type' => 'status'], ['key' => 'created_at', 'label' => 'الوقت', 'type' => 'date'],
    ],
];
$financeRowActions = static function (array $row): string {
    if (($row['status'] ?? '') === 'pending') { return '<button type="button" class="btn btn-action-pills btn-activate" data-bs-toggle="modal" data-bs-target="#approveAwardModal" data-award-id="' . (int) $row['id'] . '" title="اعتماد"><i class="fas fa-check"></i></button>'; }
    if (($row['status'] ?? '') === 'approved') { return '<button type="button" class="btn btn-action-pills btn-edit" data-bs-toggle="modal" data-bs-target="#applyAwardModal" data-award-id="' . (int) $row['id'] . '" data-amount="' . htmlspecialchars((string) $row['awarded_amount'], ENT_QUOTES, 'UTF-8') . '" title="تطبيق على استحقاق"><i class="fas fa-file-invoice-dollar"></i></button>'; }
    return '';
};
$financeModalRenderer = static function (): void { ?>
<div class="modal fade" id="approveAwardModal" tabindex="-1"><div class="modal-dialog"><div class="modal-content admin-modal admin-modal-premium admin-modal-confirm"><form method="post" action="finance_discount_awards.php"><input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($_SESSION['csrf_token'] ?? '', ENT_QUOTES, 'UTF-8'); ?>"><input type="hidden" name="action" value="approve_award"><input type="hidden" name="award_id" class="award-id"><div class="modal-header"><h5 class="modal-title"><i class="fas fa-check me-2"></i>اعتماد الخصم</h5><button type="button" class="btn-close" data-bs-dismiss="modal"></button></div><div class="modal-body"><div class="text-center mb-3"><i class="fas fa-user-check text-success" style="font-size:3rem"></i></div><p class="text-center">يجب أن يكون حسابك مختلفًا عن مقدم الطلب.</p></div><div class="modal-footer"><button type="button" class="btn btn-secondary" data-bs-dismiss="modal"><i class="fas fa-times me-1"></i>إلغاء</button><button type="submit" class="btn btn-primary"><i class="fas fa-check me-1"></i>اعتماد</button></div></form></div></div></div>
<div class="modal fade" id="applyAwardModal" tabindex="-1"><div class="modal-dialog"><div class="modal-content admin-modal admin-modal-premium admin-modal-confirm"><form method="post" action="finance_discount_awards.php"><input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($_SESSION['csrf_token'] ?? '', ENT_QUOTES, 'UTF-8'); ?>"><input type="hidden" name="action" value="apply_award"><input type="hidden" name="award_id" class="award-id"><div class="modal-header"><h5 class="modal-title"><i class="fas fa-file-invoice-dollar me-2"></i>تطبيق الخصم</h5><button type="button" class="btn-close" data-bs-dismiss="modal"></button></div><div class="modal-body"><div class="mb-3"><label class="form-label">الاستحقاق</label><input type="number" min="1" name="charge_id" class="form-control" required></div><div class="mb-3"><label class="form-label">القسط (اختياري)</label><input type="number" min="1" name="installment_id" class="form-control"></div><div class="mb-3"><label class="form-label">القيمة</label><input type="number" min="0.01" step="0.01" name="amount" class="form-control award-amount" required></div></div><div class="modal-footer"><button type="button" class="btn btn-secondary" data-bs-dismiss="modal"><i class="fas fa-times me-1"></i>إلغاء</button><button type="submit" class="btn btn-primary"><i class="fas fa-check me-1"></i>تطبيق</button></div></form></div></div></div>
<script>['approveAwardModal','applyAwardModal'].forEach(function(id){document.getElementById(id).addEventListener('show.bs.modal',function(e){this.querySelector('.award-id').value=e.relatedTarget.dataset.awardId;var a=this.querySelector('.award-amount');if(a){a.value=e.relatedTarget.dataset.amount;}});});</script>
<?php };
require __DIR__ . '/includes/finance_list_page.php';
require_once '../includes/admin_footer.php';
