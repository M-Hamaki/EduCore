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
    if ($action === 'create_plan') {
        $factory->feePlanService()->createPlan(
            (int) ($post['charge_type_id'] ?? 0),
            (int) ($post['academic_year_id'] ?? 0),
            (int) ($post['grade_id'] ?? 0) ?: null,
            (string) ($post['name'] ?? ''),
            $actorId
        );
        return;
    }
    if ($action === 'create_version') {
        $installments = [];
        foreach ((array) ($post['installment_name'] ?? []) as $index => $name) {
            if (trim((string) $name) === '') { continue; }
            $amount = (string) (($post['installment_amount'][$index] ?? ''));
            if (preg_match('/^\d+(?:\.\d{1,2})?$/', $amount) !== 1) { throw new InvalidArgumentException('قيمة القسط غير صحيحة.'); }
            $installments[] = ['name' => $name, 'gross_amount' => Money::fromDecimalString($amount), 'due_date' => ($post['due_date'][$index] ?? '') ?: null, 'display_order' => $index + 1];
        }
        $factory->feePlanService()->createVersion((int) ($post['fee_plan_id'] ?? 0), (string) ($post['effective_from'] ?? ''), $installments, $actorId);
        return;
    }
    if ($action === 'activate_version') {
        $factory->feePlanService()->activateVersion((int) ($post['version_id'] ?? 0), $actorId);
        return;
    }
    throw new InvalidArgumentException('عملية خطة الرسوم غير مدعومة.');
};

$financePage = [
    'title' => 'خطط الرسوم', 'icon' => 'fa-list', 'view' => 'fee_plans', 'create_modal' => 'planModal', 'create_label' => 'خطة جديدة',
    'money_total_field' => 'latest_total',
    'columns' => [
        ['key' => 'name', 'label' => 'اسم الخطة'], ['key' => 'charge_type_id', 'label' => 'نوع الرسوم'],
        ['key' => 'academic_year_id', 'label' => 'العام الدراسي'], ['key' => 'grade_id', 'label' => 'الصف'],
        ['key' => 'latest_version', 'label' => 'آخر إصدار'], ['key' => 'latest_total', 'label' => 'إجمالي الأقساط', 'type' => 'money'],
        ['key' => 'latest_version_status', 'label' => 'حالة الإصدار', 'type' => 'status'], ['key' => 'status', 'label' => 'حالة الخطة', 'type' => 'status'],
    ],
];
$financeRowActions = static function (array $row, callable $escape): string {
    $planId = (int) $row['id'];
    $versionId = (int) ($row['latest_version_id'] ?? 0);
    return '<button type="button" class="btn btn-action-pills btn-edit me-1" data-bs-toggle="modal" data-bs-target="#versionModal" data-plan-id="' . $planId . '" title="إنشاء إصدار"><i class="fas fa-layer-group"></i></button>'
        . ($versionId > 0 && ($row['latest_version_status'] ?? '') !== 'active' ? '<button type="button" class="btn btn-action-pills btn-activate" data-bs-toggle="modal" data-bs-target="#activateModal" data-version-id="' . $versionId . '" title="تفعيل الإصدار"><i class="fas fa-check"></i></button>' : '');
};
$financeModalRenderer = static function (): void { ?>
<div class="modal fade" id="planModal" tabindex="-1"><div class="modal-dialog"><div class="modal-content admin-modal admin-modal-premium admin-modal-create"><form method="post" action="finance_fee_plans.php">
<input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($_SESSION['csrf_token'] ?? '', ENT_QUOTES, 'UTF-8'); ?>"><input type="hidden" name="action" value="create_plan">
<div class="modal-header"><h5 class="modal-title"><i class="fas fa-plus-circle me-2"></i>خطة رسوم جديدة</h5><button type="button" class="btn-close" data-bs-dismiss="modal"></button></div>
<div class="modal-body"><div class="mb-3"><label class="form-label">نوع الرسوم</label><input type="number" min="1" name="charge_type_id" class="form-control" required></div><div class="mb-3"><label class="form-label">العام الدراسي</label><input type="number" min="1" name="academic_year_id" class="form-control" required></div><div class="mb-3"><label class="form-label">الصف (اختياري)</label><input type="number" min="1" name="grade_id" class="form-control"></div><div class="mb-3"><label class="form-label">اسم الخطة</label><input type="text" name="name" class="form-control" maxlength="150" required></div></div>
<div class="modal-footer"><button type="button" class="btn btn-secondary" data-bs-dismiss="modal"><i class="fas fa-times me-1"></i>إلغاء</button><button type="submit" class="btn btn-success"><i class="fas fa-save me-1"></i>حفظ</button></div></form></div></div></div>
<div class="modal fade" id="versionModal" tabindex="-1"><div class="modal-dialog modal-lg"><div class="modal-content admin-modal admin-modal-premium admin-modal-create"><form method="post" action="finance_fee_plans.php">
<input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($_SESSION['csrf_token'] ?? '', ENT_QUOTES, 'UTF-8'); ?>"><input type="hidden" name="action" value="create_version"><input type="hidden" name="fee_plan_id" id="versionPlanId">
<div class="modal-header"><h5 class="modal-title"><i class="fas fa-layer-group me-2"></i>إصدار أقساط جديد</h5><button type="button" class="btn-close" data-bs-dismiss="modal"></button></div><div class="modal-body"><div class="mb-3"><label class="form-label">ساري من</label><input type="date" name="effective_from" class="form-control" required></div><?php for ($i = 0; $i < 4; $i++): ?><div class="row g-2 mb-2"><div class="col-md-5"><input type="text" name="installment_name[]" class="form-control" placeholder="اسم القسط<?php echo $i === 0 ? ' (مطلوب)' : ''; ?>" <?php echo $i === 0 ? 'required' : ''; ?>></div><div class="col-md-3"><input type="number" step="0.01" min="0.01" name="installment_amount[]" class="form-control" placeholder="القيمة" <?php echo $i === 0 ? 'required' : ''; ?>></div><div class="col-md-4"><input type="date" name="due_date[]" class="form-control"></div></div><?php endfor; ?></div><div class="modal-footer"><button type="button" class="btn btn-secondary" data-bs-dismiss="modal"><i class="fas fa-times me-1"></i>إلغاء</button><button type="submit" class="btn btn-success"><i class="fas fa-save me-1"></i>حفظ الإصدار</button></div></form></div></div></div>
<div class="modal fade" id="activateModal" tabindex="-1"><div class="modal-dialog"><div class="modal-content admin-modal admin-modal-premium admin-modal-confirm"><form method="post" action="finance_fee_plans.php"><input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($_SESSION['csrf_token'] ?? '', ENT_QUOTES, 'UTF-8'); ?>"><input type="hidden" name="action" value="activate_version"><input type="hidden" name="version_id" id="activateVersionId"><div class="modal-header"><h5 class="modal-title"><i class="fas fa-check me-2"></i>تفعيل الإصدار</h5><button type="button" class="btn-close" data-bs-dismiss="modal"></button></div><div class="modal-body"><div class="text-center mb-3"><i class="fas fa-check-circle text-success" style="font-size:3rem"></i></div><p class="text-center">سيصبح هذا الإصدار هو الفعال للاستخدام في احتساب الرسوم.</p></div><div class="modal-footer"><button type="button" class="btn btn-secondary" data-bs-dismiss="modal"><i class="fas fa-times me-1"></i>إلغاء</button><button type="submit" class="btn btn-primary"><i class="fas fa-check me-1"></i>تأكيد</button></div></form></div></div></div>
<script>document.getElementById('versionModal').addEventListener('show.bs.modal',function(e){document.getElementById('versionPlanId').value=e.relatedTarget.dataset.planId;});document.getElementById('activateModal').addEventListener('show.bs.modal',function(e){document.getElementById('activateVersionId').value=e.relatedTarget.dataset.versionId;});</script>
<?php };
require __DIR__ . '/includes/finance_list_page.php';
require_once '../includes/admin_footer.php';
